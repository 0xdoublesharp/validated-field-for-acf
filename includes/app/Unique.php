<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;
require_once __DIR__ . '/WorkBudget.php';

/** Database primary keys arbitrate concurrent claims. Values never enter error messages. */
final class Unique {
    private static function empty_component($value): bool { return $value === null || $value === '' || $value === []; }

    private static function normalize($value, array $field) {
        if (($field['type'] ?? '') === 'validated_field') { $field = array_replace($field, ['type' => 'text'], $field['sub_field'] ?? []); }
        if (($field['type'] ?? '') === 'email' && is_string($value)) { return strtolower(trim($value)); }
        if (($field['type'] ?? '') === 'true_false') { return $value ? '1' : '0'; }
        if (in_array($field['type'] ?? '', ['number', 'range', 'user', 'post_object', 'image', 'file'], true) && is_numeric($value)) { return (string) (0 + $value); }
        return is_scalar($value) ? (string) $value : $value;
    }

    private static function keys(string $object, array $values, ?array $definition = null): array {
        $definition = $definition ?? Schema::object($object);
        $keys = []; $definitions = $definition['fields'];
        foreach ($definitions as $name => $field) {
            if (empty($field['unique']) || !array_key_exists($name, $values) || self::empty_component($values[$name]) || ($field['type'] ?? '') === 'validated_field') { continue; }
            $value = self::normalize($values[$name], $field);
            // Preserve existing single-field claim identities.
            $keys[hash('sha256', $object . "\0" . $name . "\0" . wp_json_encode($value))] = $name;
        }
        foreach ($definition['unique_constraints'] ?? [] as $constraint) {
            $tuple = [];
            foreach ($constraint['fields'] as $name) {
                if (!array_key_exists($name, $values) || self::empty_component($values[$name])) { continue 2; }
                $value = self::normalize($values[$name], $definitions[$name]);
                if (self::empty_component($value)) { continue 2; }
                $tuple[] = [$name, $value];
            }
            $key = '@' . $constraint['name'];
            $keys[hash('sha256', $object . "\0constraint\0" . $constraint['name'] . "\0" . wp_json_encode($tuple))] = $key;
        }
        return $keys;
    }

    private static function components(string $object, string $key): array {
        if (strpos($key, '@') !== 0) { return [$key]; }
        foreach (Schema::object($object)['unique_constraints'] ?? [] as $constraint) { if ('@' . $constraint['name'] === $key) { return $constraint['fields']; } }
        return [];
    }

    public static function check(string $object, array $values, int $id = 0) {
        global $wpdb;
        $keys = self::keys($object, $values);
        if (!$keys) { return true; }
        $access = self::authorize($object, $values, $id);
        if (is_wp_error($access)) { return $access; }
        $definition = Schema::object($object);
        $indexed = empty($definition['native_table']) && self::ready($object, $definition);
        if (!$indexed && empty($definition['native_table']) && Storage::in_transaction()) {
            $reconciled = self::reconcile($object, $definition, null, $id, $values);
            if (is_wp_error($reconciled)) { return $reconciled; }
            $indexed = true;
        }
        $ids = $indexed ? [] : $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND ID <> %d ORDER BY ID LIMIT 10001", $object, $id));
        if ($wpdb->last_error) { return self::storage_error(); }
        if (count($ids) > 10000) { return WorkBudget::error(); }
        foreach ($keys as $hash => $name) {
            $owner = $wpdb->get_var($wpdb->prepare('SELECT record_id FROM ' . self::table() . ' WHERE key_hash = %s', $hash));
            if ($wpdb->last_error) { return self::storage_error(); }
            if ($owner !== null && (int) $owner !== $id) { return self::duplicate($object, $name, $id); }
            // Existing native records may predate either single-field or compound constraints.
            foreach ($ids as $other_id) {
                if (!WorkBudget::consume(1 + count(self::components($object, $name)))) { return WorkBudget::error(); }
                Records::flush_cache((int) $other_id);
                $existing = [];
                foreach (self::components($object, $name) as $component) { $existing[$component] = get_field(Schema::fields($object)[$component]['key'], (int) $other_id, false); }
                if (isset(self::keys($object, $existing)[$hash])) { return self::duplicate($object, $name, $id); }
            }
        }
        return true;
    }

    /** Reject both existing and novel probes before consulting any other owner's values. */
    public static function authorize(string $object, array $values, int $id = 0) {
        if (current_user_can('edit_plugins') || current_user_can('install_plugins')) { return true; }
        $definition = Schema::object($object);
        $type = get_post_type_object($object);
        $all = empty($definition['native_table']) && ($definition['access']['read']['scope'] ?? 'all') === 'all' && $type && current_user_can($type->cap->read_private_posts) && current_user_can($type->cap->edit_private_posts) && current_user_can($type->cap->edit_others_posts) && Permissions::can('read', $object);
        // Primitive capabilities stand in for complete read scope. Deployments whose capability
        // filters deny individual records must turn this shortcut off with a trusted server filter.
        if ($all) { $all = (bool) apply_filters('acf_vf/app/unique_complete_scope', true, $object); }
        foreach (self::keys($object, $values) as $hash=>$name) {
            $components = self::components($object, $name);
            if ($id) {
                $previous = [];
                foreach ($components as $component) { $previous[$component] = get_field(Schema::fields($object)[$component]['key'], $id, false); }
                if (isset(self::keys($object, $previous)[$hash])) { continue; }
            }
            $allowed = $all;
            foreach ($components as $component) { $allowed = $allowed && Permissions::can('read', $object, 0, $component); }
            if (!$allowed && !apply_filters('acf_vf/app/allow_unique_probe', false, $object, $components)) {
                return new \WP_Error('unique_scope', 'Changing cross-record unique identifiers requires access to their complete read scope.', ['status'=>403]);
            }
        }
        return true;
    }

    /** Reconcile new invariants under the configuration lock, before publishing the schema. */
    public static function reconcile(string $object, array $definition, ?array $previous, int $proposed_id = 0, array $proposed_values = []) {
        if (!Storage::in_transaction()) { return self::storage_error(); }
        $incoming = self::signature($definition);
        if (self::ready($object, $definition)) { return true; }
        if (self::signature($definition) === self::signature(['fields'=>[]])) {
            return $previous && self::signature($previous) !== $incoming ? self::release_object($object) : true;
        }
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID LIMIT 10001", $object));
        if ($wpdb->last_error) { return self::storage_error(); }
        if (count($ids)>10000) { return WorkBudget::error(); }
        $claims = [];
        foreach ($ids as $record_id) {
            if (!WorkBudget::consume(1 + count($definition['fields']))) { return WorkBudget::error(); }
            Records::flush_cache((int)$record_id); $values = [];
            // A proposed/relinked field may not be registered with ACF until apply.
            foreach ($definition['fields'] as $name=>$field) { $values[$name] = acf_get_value((int)$record_id, $field); }
            if ((int)$record_id === $proposed_id) { $values = array_replace($values, $proposed_values); }
            foreach (self::keys($object, $values, $definition) as $hash=>$name) {
                if (isset($claims[$hash])) { return new \WP_Error('schema_unique_duplicates', 'Existing records conflict with the proposed unique constraints. Reconcile them before applying.', ['status'=>409]); }
                $claims[$hash] = ['record_id'=>(int)$record_id, 'field_key'=>$name];
            }
        }
        if ($wpdb->delete(self::table(), ['object_key'=>$object]) === false) { return self::storage_error(); }
        foreach ($claims as $hash=>$claim) {
            if ($wpdb->insert(self::table(), ['key_hash'=>$hash, 'object_key'=>$object, 'record_id'=>$claim['record_id'], 'field_key'=>$claim['field_key'], 'created_at'=>current_time('mysql', true)]) === false) { return self::storage_error(); }
        }
        $name = 'acf_vf_unique_state_'.$object;
        if ($wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", $name, $incoming)) === false) { return self::storage_error(); }
        return true;
    }

    private static function signature(array $schema): string {
        $unique = [];
        foreach ($schema['fields'] ?? [] as $name=>$field) {
            if (!empty($field['unique']) && ($field['type'] ?? '') !== 'validated_field') { $unique[$name] = [$field['type'], $field['unique']]; }
        }
        foreach ($schema['unique_constraints'] ?? [] as $constraint) {
            foreach ($constraint['fields'] as $name) { $unique[$name] = $schema['fields'][$name]; }
        }
        return hash('sha256', wp_json_encode([$unique, $schema['unique_constraints'] ?? []]));
    }

    private static function ready(string $object, array $definition): bool {
        global $wpdb;
        $state = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'acf_vf_unique_state_'.$object));
        return !$wpdb->last_error && $state === self::signature($definition);
    }

    public static function claim(string $object, array $values, int $id = 0) {
        global $wpdb;
        $check = self::check($object, $values, $id);
        if (is_wp_error($check)) { return $check; }
        $keys = self::keys($object, $values);
        // Stable order avoids deadlocks when two requests claim several values.
        ksort($keys);
        foreach ($keys as $hash => $name) {
            $previous = $wpdb->suppress_errors(true);
            $result = $wpdb->query($wpdb->prepare('INSERT INTO ' . self::table() . ' (key_hash,object_key,record_id,field_key,created_at) VALUES (%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE key_hash=key_hash', $hash, $object, $id, $name, current_time('mysql', true)));
            $wpdb->suppress_errors($previous);
            if ($result === false) { return new \WP_Error('unique_storage', 'Unable to reserve unique value.', ['status' => 503]); }
            $owner = $wpdb->get_var($wpdb->prepare('SELECT record_id FROM ' . self::table() . ' WHERE key_hash = %s FOR UPDATE', $hash));
            if ($wpdb->last_error || $owner === null) { return self::storage_error(); }
            // A new record temporarily claims with ID zero inside its uncommitted transaction.
            if ((int) $owner !== $id || (!$id && $result === 0)) { return self::duplicate($object, $name, $id); }
        }
        return array_keys($keys);
    }

    public static function finish(string $object, array $hashes, int $id) {
        global $wpdb;
        foreach ($hashes as $hash) { if ($wpdb->update(self::table(), ['record_id' => $id], ['key_hash' => $hash, 'object_key' => $object]) === false) { return self::storage_error(); } }
        $rows = $wpdb->get_col($wpdb->prepare('SELECT key_hash FROM ' . self::table() . ' WHERE record_id = %d AND object_key = %s', $id, $object));
        if ($wpdb->last_error) { return self::storage_error(); }
        foreach (array_diff($rows, $hashes) as $hash) { if ($wpdb->delete(self::table(), ['key_hash' => $hash, 'record_id' => $id]) === false) { return self::storage_error(); } }
        return true;
    }

    /** Schema unlink owns the transaction; native edits may follow after it commits. */
    public static function release_object(string $object) {
        if (!Storage::in_transaction() || !current_user_can('manage_options')) { return self::storage_error(); }
        global $wpdb;
        if ($wpdb->delete($wpdb->options, ['option_name'=>'acf_vf_unique_state_'.$object]) === false) { return self::storage_error(); }
        return $wpdb->delete(self::table(), ['object_key' => $object]) === false ? self::storage_error() : true;
    }

    public static function release(int $id) { global $wpdb; return $wpdb->delete(self::table(), ['record_id' => $id]) === false ? self::storage_error() : true; }
    private static function storage_error(): \WP_Error { return new \WP_Error('unique_storage', 'Unique constraints could not be persisted.', ['status' => 503]); }
    private static function table(): string { return Storage::table('unique'); }
    private static function duplicate(string $object, string $key, int $id): \WP_Error {
        $components = self::components($object, $key); $field = $components[0] ?? '';
        foreach ($components as $name) { if (!Permissions::can('read', $object, $id, $name)) { $field = ''; break; } }
        return new \WP_Error('unique', 'The record does not satisfy its unique constraints.', ['status' => 422, 'errors' => [['field' => $field, 'code' => 'unique', 'message' => $field === '' ? 'The record does not satisfy its validation rules.' : 'This value or combination is already in use.']]]);
    }
}
