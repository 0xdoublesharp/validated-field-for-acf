<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** One allowance for the entire request, including nested services and GraphQL aliases. */
final class WorkBudget {
    private static $remaining = null;
    private static $deadline = null;
    private static $exhausted = false;
    private static $request = null;

    /**
     * Identity of the current logical request. A persistent worker that serves several HTTP
     * requests from one PHP process presents a new request start time for each of them.
     */
    public static function request_id(): string {
        $server = $_SERVER ?? [];
        return (string) ($server['REQUEST_TIME_FLOAT'] ?? '') . '|' . (string) ($server['UNIQUE_ID'] ?? ($server['HTTP_X_REQUEST_ID'] ?? '')) . '|' . (string) ($server['REMOTE_PORT'] ?? '');
    }

    public static function consume(int $units = 1): bool {
        $request = self::request_id();
        if (self::$request !== $request) { self::$request = $request; self::$remaining = null; self::$exhausted = false; }
        if (self::$remaining === null) {
            $limit = function_exists('apply_filters') ? apply_filters('acf_vf/app/request_work_limit', 20000) : 20000;
            self::$remaining = max(1, min(100000, (int)$limit));
            self::$deadline = hrtime(true) + 10_000_000_000;
        }
        if (self::$exhausted || $units < 0 || $units > self::$remaining || hrtime(true) > self::$deadline) { self::$exhausted = true; return false; }
        self::$remaining -= $units;
        return true;
    }

    public static function exhausted(): bool { return self::$exhausted; }

    /** Units left in this request's allowance, or null before the first charge. */
    public static function remaining(): ?int { return self::$remaining; }

    public static function error(): \WP_Error { return new \WP_Error('request_work_capacity', 'This operation exceeds the request processing limit. Narrow the selection.', ['status'=>413]); }

    /** Only trusted CLI coordinators may mark a new logical request in a long-lived process. */
    public static function reset(): void {
        if (!defined('WP_CLI') || !WP_CLI) { throw new \LogicException('Request budgets cannot be reset by an application request.'); }
        self::$remaining = null; self::$deadline = null; self::$exhausted = false;
    }
}
