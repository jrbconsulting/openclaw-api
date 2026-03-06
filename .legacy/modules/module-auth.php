<?php
/**
 * OpenClaw API - Module Auth Helper
 * 
 * Provides permission callbacks for modules that use the OpenClaw_Fluent_Auth pattern.
 * Also handles dynamic capability registration for the Fluent Suite.
 * 
 * @deprecated 2.3.6 Use openclaw_verify_token_and_can() directly for granular permissions
 */

if (!defined('ABSPATH')) exit;

/**
 * Auth helper class for Fluent Suite modules
 * 
 * @deprecated 2.3.6 Direct capability checks are now used in routes.
 *             This class is maintained for backward compatibility only.
 *             Use openclaw_verify_token_and_can('capability_name') directly.
 */
class OpenClaw_Fluent_Auth {
    
    /**
     * Check read permission (legacy)
     * @deprecated 2.3.6 Use openclaw_verify_token_and_can('specific_capability') instead
     */
    public static function check_read() {
        _deprecated_function(__METHOD__, '2.3.6', 'openclaw_verify_token_and_can()');
        return openclaw_verify_token_and_can('fluent_read');
    }
    
    /**
     * Check write permission (legacy)
     * @deprecated 2.3.6 Use openclaw_verify_token_and_can('specific_capability') instead
     */
    public static function check_write() {
        _deprecated_function(__METHOD__, '2.3.6', 'openclaw_verify_token_and_can()');
        return openclaw_verify_token_and_can('fluent_write');
    }
    
    /**
     * Check manage permission (legacy)
     * @deprecated 2.3.6 Use openclaw_verify_token_and_can('specific_capability') instead
     */
    public static function check_manage() {
        _deprecated_function(__METHOD__, '2.3.6', 'openclaw_verify_token_and_can()');
        return openclaw_verify_token_and_can('fluent_manage');
    }
    
    /**
     * Check admin permission (legacy)
     * @deprecated 2.3.6 Use openclaw_verify_token_and_can('specific_capability') instead
     */
    public static function check_admin() {
        _deprecated_function(__METHOD__, '2.3.6', 'openclaw_verify_token_and_can()');
        return openclaw_verify_token_and_can('fluent_admin');
    }
    
    /**
     * Check specific capability (for granular permissions)
     * This method is NOT deprecated as it provides useful abstraction.
     * 
     * @param string $capability The capability to check
     * @return bool
     */
    public static function check_capability($capability) {
        return openclaw_verify_token_and_can($capability);
    }
}

/**
 * Get module capability definitions with labels and groups.
 * 
 * Each module should use the 'openclaw_module_capabilities' filter to register.
 * 
 * @return array Array of [capability => ['label' => string, 'default' => bool, 'group' => string]]
 */
function openclaw_get_module_capabilities() {
    return apply_filters('openclaw_module_capabilities', []);
}

// Register Fluent Suite capabilities (legacy support)
add_filter('openclaw_default_capabilities', function($caps) {
    $module_caps = openclaw_get_module_capabilities();
    foreach ($module_caps as $cap => $info) {
        $caps[$cap] = $info['default'] ?? false;
    }
    return $caps;
});

// Register Fluent Suite module capabilities (generic fallback)
add_filter('openclaw_module_capabilities', function($caps) {
    return array_merge($caps, [
        'fluent_read' => ['label' => 'Read Fluent Data (All)', 'default' => true, 'group' => 'Fluent Suite'],
        'fluent_write' => ['label' => 'Write Fluent Data (All)', 'default' => false, 'group' => 'Fluent Suite'],
        'fluent_manage' => ['label' => 'Manage Fluent Data (All)', 'default' => false, 'group' => 'Fluent Suite'],
        'fluent_admin' => ['label' => 'Full Fluent Admin Access', 'default' => false, 'group' => 'Fluent Suite'],
    ]);
});