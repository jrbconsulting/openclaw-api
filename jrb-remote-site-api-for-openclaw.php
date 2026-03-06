<?php
/**
 * Plugin Name: JRB Remote Site API for OpenClaw
 * Description: WordPress REST API for OpenClaw remote site management (Optimized v6.5.1)
 * Version: 6.5.1
 * Author: JRB Consulting
 * License: GPLv2 or later
 * Text Domain: jrb-remote-site-api-for-openclaw
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

/**
 * 🚀 OPTIMIZATION v6.5.1: Centralized plugin detection with static caching
 * Prevents repeated file_exists() and class_exists() checks
 */
class JRB_Remote_Api_Core {

    /**
     * Static cache for plugin detection results
     * @var array
     */
    private static $plugin_cache = [];

    /**
     * Static cache for module availability
     * @var array
     */
    private static $module_cache = [];

    const VERSION = '6.5.1';
    const API_NAMESPACE = 'jrb/v1';

    /**
     * 🚀 OPTIMIZATION: Cached plugin file check
     * @param string $file Relative file path
     * @return bool
     */
    public static function has_file($file) {
        if (isset(self::$plugin_cache[$file])) {
            return self::$plugin_cache[$file];
        }

        $full_path = __DIR__ . '/' . $file;
        self::$plugin_cache[$file] = file_exists($full_path);
        return self::$plugin_cache[$file];
    }

    /**
     * 🚀 OPTIMIZATION: Cached class existence check
     * @param string $class Fully qualified class name
     * @return bool
     */
    public static function has_class($class) {
        if (isset(self::$plugin_cache['class_' . $class])) {
            return self::$plugin_cache['class_' . $class];
        }

        self::$plugin_cache['class_' . $class] = class_exists($class);
        return self::$plugin_cache['class_' . $class];
    }

    public static function init() {
        // 🚀 OPTIMIZATION: Early exit if already initialized
        if (defined('JRB_REMOTE_API_INITIALIZED')) {
            return;
        }
        define('JRB_REMOTE_API_INITIALIZED', true);

        // Init Admin UI
        if (is_admin()) {
            \JRB\RemoteApi\Handlers\AdminHandler::init();
        }

        // Init REST
        add_action('rest_api_init', [self::class, 'register_routes']);

        // 🚀 OPTIMIZATION: Load modules with transient caching
        add_action('plugins_loaded', [self::class, 'load_modules'], 11);
    }

    public static function register_routes() {
        // Core system handlers - always available
        \JRB\RemoteApi\Handlers\SystemHandler::register_routes();
        \JRB\RemoteApi\Handlers\AdminHandler::register_routes();

        // 🚀 OPTIMIZATION: Conditionally load integration handlers based on cached module detection
        if (self::is_module_active('fluent_crm')) {
            \JRB\RemoteApi\Handlers\FluentCrmHandler::register_routes();
        }

        if (self::is_module_active('fluent_support')) {
            \JRB\RemoteApi\Handlers\FluentSupportHandler::register_routes();
        }
    }

    /**
     * 🚀 OPTIMIZATION: Module loading with transient caching
     * Checks module availability with 1-hour transient cache
     */
    public static function load_modules() {
        // 🚀 OPTIMIZATION: Check transient first for module status
        $module_status = get_transient('jrb_module_status');

        if ($module_status === false) {
            $module_status = [];

            // Detect FluentCRM
            if (class_exists('FluentCRM\App\Models\Subscriber')) {
                $module_status['fluent_crm'] = true;
                error_log('[JRB Remote API] FluentCRM module detected and activated');
            } else {
                $module_status['fluent_crm'] = false;
            }

            // Detect FluentSupport
            if (class_exists('FluentSupport\App\Models\Ticket')) {
                $module_status['fluent_support'] = true;
                error_log('[JRB Remote API] FluentSupport module detected and activated');
            } else {
                $module_status['fluent_support'] = false;
            }

            // 🚀 OPTIMIZATION: Cache module status for 1 hour
            set_transient('jrb_module_status', $module_status, HOUR_IN_SECONDS);
        }

        self::$module_cache = $module_status;
    }

    /**
     * 🚀 OPTIMIZATION: Check if module is active using cached results
     * @param string $module Module name
     * @return bool
     */
    public static function is_module_active($module) {
        // 🚀 OPTIMIZATION: Return cached result immediately
        if (isset(self::$module_cache[$module])) {
            return self::$module_cache[$module];
        }

        // Fallback if cache not populated
        return false;
    }

    /**
     * 🚀 OPTIMIZATION: Clear module cache on plugin update
     */
    public static function clear_module_cache() {
        delete_transient('jrb_module_status');
        self::$module_cache = [];
        self::$plugin_cache = [];
    }
}

// Register activation hook to clear caches
register_activation_hook(__FILE__, ['JRB_Remote_Api_Core', 'clear_module_cache']);

// Register deactivation hook
register_deactivation_hook(__FILE__, function() {
    delete_transient('jrb_module_status');
    delete_transient('jrb_auth_migration_done');
    delete_transient('jrb_capabilities_cache');
});

add_action('plugins_loaded', ['JRB_Remote_Api_Core', 'init']);
