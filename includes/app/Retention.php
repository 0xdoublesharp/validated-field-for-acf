<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;
require_once __DIR__ . '/WorkBudget.php';

/** Bounded recent history and outbox retention; pending work is never silently discarded. */
final class Retention {
    /** Per-actor admission keeps one principal from growing shared audit storage across many records. */
    public static function admit_audit(int $actor, int $bytes) {
        global $wpdb; $table = Storage::table('audit');
        $limits = self::audit_limits();
        $owned = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n,COALESCE(SUM(bytes),0) AS b FROM $table WHERE actor_id=%d", $actor), ARRAY_A);
        if ($wpdb->last_error || !$owned) { return self::audit_error(); }
        if ((int) $owned['n'] >= $limits['actor_rows'] || (int) $owned['b'] + $bytes > $limits['actor_bytes']) { return self::audit_error(); }
        // The ID span is an upper bound on retained rows; count exactly only near the ceiling.
        $span = $wpdb->get_row("SELECT COALESCE(MAX(id)-MIN(id)+1,0) AS n FROM $table", ARRAY_A);
        if ((int) ($span['n'] ?? 0) >= $limits['site_rows'] && (int) $wpdb->get_var("SELECT COUNT(*) FROM $table") >= $limits['site_rows']) { return self::audit_error(); }
        return true;
    }

    public static function audit(int $record_id): void {
        global $wpdb; $table = Storage::table('audit');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,bytes FROM $table WHERE record_id=%d ORDER BY id DESC LIMIT 201", $record_id), ARRAY_A);
        if ($wpdb->last_error || !WorkBudget::consume(count($rows))) { throw new \RuntimeException('Audit retention is unavailable.'); }
        $bytes = 0;
        foreach ($rows as $index=>$row) {
            $bytes += (int)$row['bytes'];
            if ($index >= 200 || $bytes > 8388608) {
                // The newest event is always kept, even alone over the byte budget; the per-actor
                // quota bounds it. Everything older than the first event past the budget is removed.
                $operator = $index === 0 ? '<' : '<=';
                if ($wpdb->query($wpdb->prepare("DELETE FROM $table WHERE record_id=%d AND id$operator%d ORDER BY id LIMIT 500", $record_id, $row['id'])) === false) { throw new \RuntimeException('Audit retention limit exceeded.'); }
                break;
            }
        }
    }

    /** Admission reads only indexed actor/size metadata, never retained payload bodies. */
    public static function reserve_job(int $actor, int $payload_bytes) {
        global $wpdb; $table = Storage::table('jobs');
        $site = $wpdb->get_row("SELECT COUNT(*) AS n,COALESCE(SUM(payload_bytes),0) AS b FROM $table", ARRAY_A);
        $owned = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n,COALESCE(SUM(payload_bytes),0) AS b FROM $table WHERE actor_id=%d", $actor), ARRAY_A);
        if ($wpdb->last_error || !$site || !$owned) { return self::error(); }
        if (!WorkBudget::consume(2)) { return WorkBudget::error(); }
        $count = (int) $site['n']; $bytes = (int) $site['b']; $owned_rows = (int) $owned['n']; $owned_bytes = (int) $owned['b'];
        $fits = static function () use (&$count, &$bytes, &$owned_rows, &$owned_bytes, $payload_bytes) { return $owned_rows < 1000 && $owned_bytes + $payload_bytes <= 16777216 && $count < 10000 && $bytes + $payload_bytes <= 134217728; };
        if ($fits()) { return true; }
        // Reclaim only this actor's oldest successful work when its retained quota is full.
        $completed = $wpdb->get_results($wpdb->prepare("SELECT id,payload_bytes FROM $table WHERE actor_id=%d AND status='completed' ORDER BY id LIMIT 1000", $actor), ARRAY_A);
        if ($wpdb->last_error) { return self::error(); }
        if (!WorkBudget::consume(count($completed))) { return WorkBudget::error(); }
        foreach ($completed as $row) {
            if ($wpdb->delete($table, ['id'=>$row['id'],'status'=>'completed']) === false) { return self::error(); }
            --$owned_rows; --$count; $owned_bytes -= (int)$row['payload_bytes']; $bytes -= (int)$row['payload_bytes'];
            if ($fits()) { return true; }
        }
        return self::error();
    }

    /** Cron/CLI maintenance removes a bounded batch of expired history and terminal jobs. */
    public static function cleanup(): void {
        if (get_option('acf_vf_app_storage_version', false) === false) { return; }
        if (!wp_doing_cron() && !(defined('WP_CLI') && WP_CLI) && !current_user_can('manage_options')) { return; }
        Storage::install();
        global $wpdb;
        $audit = Storage::table('audit'); $jobs = Storage::table('jobs');
        $wpdb->query($wpdb->prepare("DELETE FROM $audit WHERE created_at < %s ORDER BY id LIMIT 500", gmdate('Y-m-d H:i:s', time()-90*86400)));
        // Expiry is selected before LIMIT so recent low-ID rows cannot strand expired later rows.
        foreach (['completed'=>7, 'failed'=>30] as $status=>$days) {
            $wpdb->query($wpdb->prepare("DELETE FROM $jobs WHERE status=%s AND completed_at IS NOT NULL AND completed_at < %s ORDER BY completed_at LIMIT 500", $status, gmdate('Y-m-d H:i:s', time()-$days*86400)));
        }
    }

    /** @return array{actor_rows:int,actor_bytes:int,site_rows:int} */
    private static function audit_limits(): array {
        $limits = apply_filters('acf_vf/app/audit_capacity', ['actor_rows'=>50000, 'actor_bytes'=>536870912, 'site_rows'=>1000000]);
        return ['actor_rows'=>max(1, (int) ($limits['actor_rows'] ?? 50000)), 'actor_bytes'=>max(1, (int) ($limits['actor_bytes'] ?? 536870912)), 'site_rows'=>max(1, (int) ($limits['site_rows'] ?? 1000000))];
    }

    private static function audit_error(): \WP_Error { return new \WP_Error('audit_capacity', 'Retained audit capacity is full. Retry after history maintenance runs.', ['status'=>503]); }

    private static function error(): \WP_Error { return new \WP_Error('job_capacity', 'Retained job capacity is full. Process or review pending work before retrying.', ['status'=>503]); }
}
