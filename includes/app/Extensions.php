<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Installed providers only. Payment and network state never control runtime registration. */
final class Extensions {
    public const API = 1;
    /**
     * Semantic security contract a provider must implement: secret-free configuration responses,
     * mutation request-intent guards, contributor provenance, transactional owner-quota storage and
     * non-executing metadata listings. Older API-1 builds are declined rather than trusted.
     */
    public const CONTRACT = 2;
    private static $providers = [];
    private static $declined = [];
    private static $features = [];
    private static $closed = false;
    /**
     * Register during acf_vf/app/register_extensions, before the registry closes.
     *
     * @param string $id Unique provider ID, such as validated-field-pro.
     * @param array{api:int,contract:int,features:string[],register:callable} $definition API, security contract and hook registrar.
     * @throws \InvalidArgumentException For late, duplicate or incompatible registration.
     */
    public static function register(string $id, array $definition): void {
        if (self::$closed || isset(self::$providers[$id]) || !preg_match('/^[a-z][a-z0-9_.-]*$/D', $id) || ($definition['api'] ?? null) !== self::API || !is_array($definition['features'] ?? null) || !is_callable($definition['register'] ?? null)) {
            throw new \InvalidArgumentException('Invalid, duplicate, incompatible, or late extension registration.');
        }
        foreach ($definition['features'] as $feature) {
            if (!is_string($feature) || !preg_match('/^[a-z][a-z0-9_.]*$/D', $feature) || isset(self::$features[$feature])) { throw new \InvalidArgumentException('Invalid or duplicate feature.'); }
        }
        // A provider predating the current security contract is not booted or advertised.
        $contract = $definition['contract'] ?? 0;
        if (!is_int($contract) || $contract < self::CONTRACT) { self::$declined[$id] = is_int($contract) ? $contract : 0; return; }
        // Hook registration must succeed before the provider is advertised as usable.
        call_user_func($definition['register']);
        self::$providers[$id] = $definition['features'];
        foreach ($definition['features'] as $feature) { self::$features[$feature] = $id; }
    }
    public static function finish(): void { self::$closed = true; }
    public static function has(string $feature): bool { return isset(self::$features[$feature]); }
    /**
     * @return true|\WP_Error Availability of the installed provider, independent of licensing.
     */
    public static function require_feature(string $feature) {
        return self::has($feature) ? true : new \WP_Error('vf_provider_missing', 'This operation requires the compatible Validated Field Pro add-on.', ['status'=>409,'features'=>[$feature]]);
    }
    public static function metadata(): array { return ['api'=>self::API,'features'=>array_keys(self::$features)]; }
    /** @return array<string,int> Providers declined for an older security contract. */
    public static function declined(): array { return self::$declined; }
    public static function notice(): void {
        if (!self::$declined || !current_user_can('activate_plugins')) { return; }
        echo '<div class="notice notice-error"><p>'.esc_html__('An installed Validated Field add-on predates this release\'s security requirements and has been disabled. Update the add-on to restore its features.','validated-field-for-acf').'</p></div>';
    }
}
