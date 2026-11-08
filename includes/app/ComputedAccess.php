<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;
require_once __DIR__ . '/WorkBudget.php';

/** Confidentiality of retained computed values is enforced even without their calculator. */
final class ComputedAccess {
    public const META = '_vf_app_computed_provenance';
    private static $checking=[];
    public const MAX_SOURCES = 10000;
    private const UNBOUND_KEYS = ['read_roles', 'write_roles', 'label', 'instructions'];

    /** Retained contributor IDs per record; a trusted server filter may only lower the ceiling. */
    public static function max_sources(): int { return max(1, min(self::MAX_SOURCES, (int) (function_exists('apply_filters') ? apply_filters('acf_vf/app/computed_source_limit', self::MAX_SOURCES) : self::MAX_SOURCES))); }
    /** Bind each retained result to its producing graph, value and contributing records. */
    public static function evidence(string $object, string $name, $value, array $sources = []): array {
        return ['schema'=>self::fingerprint($object, $name), 'value'=>hash('sha256', wp_json_encode($value)), 'sources'=>$sources];
    }

    /**
     * Each distinct dependency is expanded once and charged to the request allowance, so shared
     * subgraphs cannot multiply work. A chain longer than 40 fields or a cycle has no fingerprint.
     */
    private static function fingerprint(string $object, string $name): ?string {
        $memo = [];
        $node = self::node($object, $name, [], $memo);
        return $node === null || $node[1] > 40 ? null : $node[0];
    }

    /** @return array{0:string,1:int}|null Fingerprint and longest dependency chain, in fields. */
    private static function node(string $object, string $name, array $path, array &$memo): ?array {
        $key = $object.':'.$name;
        if (isset($path[$key]) || count($path) >= 40) { return null; }
        if (array_key_exists($key, $memo)) { return $memo[$key]; }
        if (!WorkBudget::consume()) { return $memo[$key] = null; }
        $path[$key] = true;
        $field = Schema::fields($object)[$name] ?? [];
        // Access lists are checked live on every read and labels do not change results, so
        // neither invalidates retained values.
        $graph = [$object, $name, array_diff_key($field, array_flip(self::UNBOUND_KEYS))]; $height = 1;
        $dependencies = [];
        if (!empty($field['formula'])) {
            $formula = ExpressionSyntax::dependencies($field['formula']);
            if (is_wp_error($formula)) { return $memo[$key] = null; }
            foreach ($formula as $dependency) { $dependencies[] = [$object, $dependency]; }
        }
        if (!empty($field['rollup'])) {
            $config = $field['rollup'];
            $dependencies[] = [$config['object'] ?? '', $config['relationship_field'] ?? ''];
            if (($config['operation'] ?? '') !== 'count') { $dependencies[] = [$config['object'] ?? '', $config['field'] ?? '']; }
        }
        foreach ($dependencies as [$dependency_object, $dependency]) {
            $child = self::node((string) $dependency_object, (string) $dependency, $path, $memo);
            if ($child === null) { return $memo[$key] = null; }
            $graph[] = $child[0]; $height = max($height, $child[1] + 1);
        }
        return $memo[$key] = [hash('sha256', wp_json_encode($graph)), $height];
    }

    public static function matches(string $object, int $id, string $name, $value): bool {
        $field = Schema::fields($object)[$name] ?? [];
        if (empty($field['formula']) && empty($field['rollup'])) { return true; }
        $stored = get_post_meta($id, self::META, true);
        return is_array($stored) && isset($stored[$name]['value']) && hash_equals($stored[$name]['value'], hash('sha256', wp_json_encode($value)));
    }
    public static function can_read(string $object,int $id,string $name,int $user_id): bool {
        $key=$user_id.':'.$object.':'.$id.':'.$name;
        if (isset(self::$checking[$key]) || count(self::$checking)>=40) { return false; }
        self::$checking[$key]=true;
        try {
            $field=Schema::fields($object)[$name] ?? [];
            $evidence = null;
            if ($id && (!empty($field['formula']) || !empty($field['rollup']))) {
                $stored = get_post_meta($id, self::META, true);
                $evidence = is_array($stored) ? ($stored[$name] ?? null) : null;
                if (!is_array($evidence)) {
                    // Nothing retained yet discloses nothing; definition-level checks below decide.
                    // A retained value without provenance stays hidden.
                    $retained = get_post_meta($id, $name, true);
                    if ($retained !== '' && $retained !== null && $retained !== false) { return false; }
                } else {
                    $fingerprint = self::fingerprint($object, $name);
                    if ($fingerprint === null || ($evidence['schema'] ?? null) !== $fingerprint) { return false; }
                }
            }
            if (!empty($field['formula'])) {
                if (!is_string($field['formula'])) { return false; }
                $dependencies=ExpressionSyntax::dependencies($field['formula']);
                if (is_wp_error($dependencies)) { return false; }
                foreach ($dependencies as $dependency) { if (!Permissions::can('read',$object,$id,$dependency,$user_id)) { return false; } }
            }
            $config=$field['rollup'] ?? [];
            if (!$config) { return true; }
            if (!is_array($config) || !is_string($config['object'] ?? null) || !is_string($config['relationship_field'] ?? null) || !in_array($config['operation'] ?? '',['count','sum','min','max'],true)) { return false; }
            if (!Permissions::can('read',$config['object'],0,$config['relationship_field'],$user_id)) { return false; }
            if ($config['operation'] !== 'count' && (!is_string($config['field'] ?? null) || !Permissions::can('read',$config['object'],0,$config['field'],$user_id))) { return false; }
            // List columns, generated schemas and never-computed values have no contributors yet.
            if (!$id || $evidence === null) { return true; }
            if (!is_array($evidence['sources'] ?? null) || count($evidence['sources']) > self::max_sources()) { return false; }
            foreach ($evidence['sources'] as $source_id) {
                if (!WorkBudget::consume()) { return false; }
                if (!is_int($source_id) || $source_id < 1 || !Permissions::can('read',$config['object'],$source_id,null,$user_id) || !Permissions::can('read',$config['object'],$source_id,$config['relationship_field'],$user_id) || ($config['operation'] !== 'count' && !Permissions::can('read',$config['object'],$source_id,$config['field'],$user_id))) { return false; }
            }
            return true;
        } finally { unset(self::$checking[$key]); }
    }
}
