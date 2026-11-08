<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/**
 * Target authorization for native ACF reference leaves, including nested group, repeater and
 * flexible-content rows. Plugin relationship fields keep their stricter object-bound policy.
 */
final class References {
    private const POST_TYPES = ['relationship', 'post_object', 'page_link', 'image', 'file', 'gallery'];
    private const MAX_TARGETS = 500;

    public static function has(array $field, int $depth = 0): bool {
        $type = $field['type'] ?? '';
        if ($depth > 10 || !empty($field['relationship']) || in_array($type, self::POST_TYPES, true) || in_array($type, ['user', 'taxonomy'], true)) { return true; }
        if ($type === 'validated_field' && self::has($field['sub_field'] ?? [], $depth + 1)) { return true; }
        foreach (($field['sub_fields'] ?? []) as $child) { if (is_array($child) && self::has($child, $depth + 1)) { return true; } }
        foreach (($field['layouts'] ?? []) as $layout) { foreach (($layout['sub_fields'] ?? []) as $child) { if (is_array($child) && self::has($child, $depth + 1)) { return true; } } }
        return false;
    }

    /**
     * Whether the current user may see every target referenced by a public-shaped value.
     * Each target charges the shared request allowance; exhaustion fails closed.
     */
    public static function readable(array $field, $value, int $depth = 0): bool {
        if ($value === null || $value === '' || $value === false || $value === []) { return true; }
        if ($depth > 10) { return false; }
        $type = $field['type'] ?? '';
        if ($type === 'validated_field') { return self::readable(array_replace($field, ['type' => 'text'], $field['sub_field'] ?? []), $value, $depth + 1); }
        if ($type === 'group') { return is_array($value) && self::row($field['sub_fields'] ?? [], $value, $depth); }
        if ($type === 'repeater') {
            if (!is_array($value)) { return false; }
            foreach ($value as $row) { if (!is_array($row) || !self::row($field['sub_fields'] ?? [], $row, $depth)) { return false; } }
            return true;
        }
        if ($type === 'flexible_content') {
            if (!is_array($value)) { return false; }
            foreach ($value as $row) {
                if (!is_array($row)) { return false; }
                $layout = null;
                foreach (($field['layouts'] ?? []) as $candidate) { if (($candidate['name'] ?? null) === ($row['acf_fc_layout'] ?? '')) { $layout = $candidate; break; } }
                if (!$layout || !self::row($layout['sub_fields'] ?? [], $row, $depth)) { return false; }
            }
            return true;
        }
        if (in_array($type, self::POST_TYPES, true)) { return self::posts($type, $value); }
        if ($type === 'taxonomy') { return self::terms($field, $value); }
        if ($type === 'user') { return self::users($value); }
        return true;
    }

    /**
     * Reader projection of a value: list-shaped references keep only targets the current user
     * may see (deleted targets disclose nothing and stay), in their stored order. A single
     * unreadable reference, a malformed value or an exhausted allowance yields null, meaning
     * the whole field is withheld. Nested rows drop only their unreadable leaves.
     *
     * @return array{0:bool,1:mixed} [whether the field is shown, the projected value]
     */
    public static function visible(array $field, $value, int $depth = 0): array {
        if ($value === null || $value === '' || $value === false || $value === []) { return [true, $value]; }
        if ($depth > 10) { return [false, null]; }
        $type = $field['type'] ?? '';
        if ($type === 'validated_field') { return self::visible(array_replace($field, ['type' => 'text'], $field['sub_field'] ?? []), $value, $depth + 1); }
        if ($type === 'group') { return is_array($value) ? self::visible_row($field['sub_fields'] ?? [], $value, $depth) : [false, null]; }
        if ($type === 'repeater' || $type === 'flexible_content') {
            if (!is_array($value)) { return [false, null]; }
            foreach ($value as $index => $row) {
                if (!is_array($row)) { return [false, null]; }
                $fields = $field['sub_fields'] ?? [];
                if ($type === 'flexible_content') {
                    $fields = null;
                    foreach (($field['layouts'] ?? []) as $candidate) { if (($candidate['name'] ?? null) === ($row['acf_fc_layout'] ?? '')) { $fields = $candidate['sub_fields'] ?? []; break; } }
                    if ($fields === null) { return [false, null]; }
                }
                [$shown, $value[$index]] = self::visible_row($fields, $row, $depth);
                if (!$shown) { return [false, null]; }
            }
            return [true, $value];
        }
        $kind = in_array($type, self::POST_TYPES, true) ? 'post' : ($type === 'taxonomy' ? 'term' : ($type === 'user' ? 'user' : null));
        if ($kind === null) { return [true, $value]; }
        $items = is_array($value) ? $value : [$value];
        if (count($items) > self::MAX_TARGETS || !WorkBudget::consume(count($items))) { return [false, null]; }
        $kept = [];
        foreach ($items as $key => $item) {
            $id = self::item_id($item, $type === 'page_link');
            if ($id === null) { return [false, null]; }
            if ($id === 0 || self::target_readable($kind, $field, $id)) { $kept[$key] = $item; }
        }
        if (!is_array($value)) { return $kept ? [true, $value] : [false, null]; }
        return [true, array_keys($value) === range(0, count($value) - 1) ? array_values($kept) : $kept];
    }

    private static function visible_row(array $fields, array $row, int $depth): array {
        foreach ($fields as $key => $child) {
            if (!is_array($child)) { continue; }
            $name = $child['name'] ?? (is_string($key) ? $key : '');
            if ($name === '' || !array_key_exists($name, $row) || !self::has($child)) { continue; }
            [$shown, $projected] = self::visible($child, $row[$name], $depth + 1);
            if ($shown) { $row[$name] = $projected; } else { unset($row[$name]); }
        }
        return [true, $row];
    }

    /** @return int|null Target ID, 0 for a page_link URL that names no record, or null when malformed. */
    private static function item_id($item, bool $allow_urls) {
        if (is_array($item)) { $item = $item['ID'] ?? ($item['id'] ?? null); }
        elseif (is_object($item)) { $item = $item->ID ?? ($item->term_id ?? null); }
        if (is_int($item) || (is_string($item) && ctype_digit($item))) { return (int) $item; }
        if ($allow_urls && is_string($item)) { return 0; }
        return null;
    }

    private static function target_readable(string $kind, array $field, int $id): bool {
        if ($kind === 'post') {
            $post_type = get_post_type($id);
            if (!$post_type) { return true; }
            if (get_post_status($id) === 'trash' && !current_user_can('read_post', $id)) { return false; }
            return Schema::managed_object($post_type) ? Permissions::can('read', $post_type, $id) : current_user_can('read_post', $id);
        }
        if ($kind === 'term') {
            $term = get_term($id);
            if (!$term instanceof \WP_Term) { return false; }
            $taxonomy = get_taxonomy($term->taxonomy);
            return $taxonomy && ($taxonomy->public || current_user_can($taxonomy->cap->assign_terms));
        }
        return (bool) get_userdata($id);
    }

    private static function row(array $fields, array $row, int $depth): bool {
        foreach ($fields as $key => $child) {
            if (!is_array($child)) { continue; }
            $name = $child['name'] ?? (is_string($key) ? $key : '');
            if ($name === '' || !array_key_exists($name, $row) || !self::has($child)) { continue; }
            if (!self::readable($child, $row[$name], $depth + 1)) { return false; }
        }
        return true;
    }

    /** @return int[]|null Numeric IDs, or null for a malformed or oversized value. */
    private static function ids($value, bool $allow_urls = false): ?array {
        $items = is_array($value) ? array_values($value) : [$value];
        if (count($items) > self::MAX_TARGETS) { return null; }
        $ids = [];
        foreach ($items as $item) {
            // page_link may hold archive URLs (ID 0), which reference no record.
            $id = self::item_id($item, $allow_urls);
            if ($id === null) { return null; }
            $ids[] = $id;
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function posts(string $type, $value): bool {
        $ids = self::ids($value, $type === 'page_link');
        if ($ids === null || !WorkBudget::consume(count($ids))) { return false; }
        // A deleted target discloses nothing and stays visible so quality checks can report it.
        foreach ($ids as $id) { if (!self::target_readable('post', [], $id)) { return false; } }
        return true;
    }

    private static function terms(array $field, $value): bool {
        $ids = self::ids($value);
        if ($ids === null || !WorkBudget::consume(count($ids))) { return false; }
        foreach ($ids as $id) { if (!self::target_readable('term', $field, $id)) { return false; } }
        return true;
    }

    /** WordPress exposes user IDs through author archives, so only existence is required. */
    private static function users($value): bool {
        $ids = self::ids($value);
        if ($ids === null || !WorkBudget::consume(count($ids))) { return false; }
        foreach ($ids as $id) { if (!self::target_readable('user', [], $id)) { return false; } }
        return true;
    }
}
