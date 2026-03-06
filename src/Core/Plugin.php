<?php
namespace JRB\RemoteApi\Core;

if (!defined('ABSPATH')) exit;

/**
 * Core Plugin Class
 *
 * Central configuration and initialization for JRB Remote Site API
 * Optimized v6.5.1 with caching and performance improvements
 *
 * @since 6.5.1
 * @package JRB\RemoteApi\Core
 */
class Plugin {
    /**
     * Plugin version - synchronized with main plugin file
     *
     * @var string
     */
    const VERSION = '6.5.1';

    /**
     * API Namespace - standardized primary namespace
     * All routes registered under this namespace for consistency
     *
     * @var string
     */
    const API_NAMESPACE = 'jrb/v1';

    /**
     * Legacy namespaces supported for backward compatibility
     * These will be removed in version 7.0.0
     *
     * @var array
     */
    const LEGACY_NAMESPACES = ['openclaw/v1', 'jrbremoteapi/v1'];

    /**
     * Text domain for internationalization
     *
     * @var string
     */
    const TEXT_DOMAIN = 'jrb-remote-site-api-for-openclaw';

    /**
     * 🚀 OPTIMIZATION: Static cache for capabilities
     * Avoids repeated get_option() calls
     *
     * @var array|null
     */
    private static $capabilities_cache = null;

    /**
     * 🚀 OPTIMIZATION: Static cache for plugin info
     *
     * @var array|null
     */
    private static $plugin_info_cache = null;

    /**
     * Initialize the plugin
     *
     * @since 6.5.1
     */
    public static function init() {
        // 🚀 OPTIMIZATION: Early exit if already initialized
        if (defined('JRB_CORE_INITIALIZED')) {
            return;
        }
        define('JRB_CORE_INITIALIZED', true);

        // Run auth migration on admin init
        add_action('admin_init', [self::class, 'migrate_legacy_auth']);

        // Register admin menu
        if (is_admin()) {
            \JRB\RemoteApi\Handlers\AdminHandler::init();
        }

        // Register REST routes
        add_action('rest_api_init', [self::class, 'register_routes']);

        // Add API version header to responses
        add_filter('rest_post_dispatch', [self::class, 'add_api_headers'], 10, 3);

        // 🚀 OPTIMIZATION: Hook to clear caches on option update
        add_action('update_option_openclaw_api_capabilities', [self::class, 'clear_capabilities_cache'], 10, 3);
        add_action('deleted_option_openclaw_api_capabilities', [self::class, 'clear_capabilities_cache']);
    }

    /**
     * Migrate legacy authentication data to primary storage
     * Runs once on plugin update or admin access
     *
     * @since 6.5.1
     */
    public static function migrate_legacy_auth() {
        // Only run once per session
        if (get_transient('jrb_auth_migration_done')) {
            return;
        }

        // Delegate to Guard class for migration logic
        if (class_exists('\\JRB\\RemoteApi\\Auth\\Guard')) {
            \JRB\RemoteApi\Auth\Guard::migrate_legacy_auth();
        }

        // Set transient to prevent repeated migration checks
        set_transient('jrb_auth_migration_done', true, HOUR_IN_SECONDS);
    }

    /**
     * Register all REST API routes
     * Delegates to handler classes for modular route registration
     *
     * @since 6.5.1
     */
    public static function register_routes() {
        // Core system handlers
        \JRB\RemoteApi\Handlers\SystemHandler::register_routes();
        \JRB\RemoteApi\Handlers\AdminHandler::register_routes();

        // 🚀 OPTIMIZATION: Conditionally load integration handlers
        if (class_exists('\\JRB\\RemoteApi\\Handlers\\FluentCrmHandler')) {
            \JRB\RemoteApi\Handlers\FluentCrmHandler::register_routes();
        }

        if (class_exists('\\JRB\\RemoteApi\\Handlers\\FluentSupportHandler')) {
            \JRB\RemoteApi\Handlers\FluentSupportHandler::register_routes();
        }
    }

    /**
     * Add API version and namespace info to REST responses
     *
     * @param WP_REST_Response $response Response object
     * @param mixed            $handler  Handler
     * @param WP_REST_Request  $request  Request object
     * @return WP_REST_Response Modified response
     *
     * @since 6.5.1
     */
    public static function add_api_headers($response, $handler, $request) {
        $data = $response->get_data();

        if (is_array($data)) {
            $data['_meta'] = [
                'api_version' => self::VERSION,
                'namespace' => self::API_NAMESPACE,
                'timestamp' => current_time('mysql'),
            ];
            $response->set_data($data);
        }

        // Add header for API clients
        $response->header('X-JRB-API-Version', self::VERSION);
        $response->header('X-JRB-API-Namespace', self::API_NAMESPACE);

        return $response;
    }

    /**
     * 🚀 OPTIMIZATION: Get capabilities with static caching
     * Reduces database queries for capability checks
     *
     * @return array
     * @since 6.5.1
     */
    public static function get_capabilities() {
        // Return cached capabilities if available
        if (self::$capabilities_cache !== null) {
            return self::$capabilities_cache;
        }

        // 🚀 OPTIMIZATION: Try transient cache first (5 minute expiry)
        $cached = get_transient('jrb_capabilities_cache');
        if ($cached !== false) {
            self::$capabilities_cache = $cached;
            return self::$capabilities_cache;
        }

        // Load from database
        $caps = get_option('openclaw_api_capabilities', []);

        // Fallback to legacy option key
        if (empty($caps)) {
            $caps = get_option('jrbremote_api_capabilities', []);
        }

        // Cache in memory and transient
        self::$capabilities_cache = $caps;
        set_transient('jrb_capabilities_cache', $caps, 5 * MINUTE_IN_SECONDS);

        return self::$capabilities_cache;
    }

    /**
     * 🚀 OPTIMIZATION: Clear capabilities cache when options change
     *
     * @since 6.5.1
     */
    public static function clear_capabilities_cache() {
        self::$capabilities_cache = null;
        delete_transient('jrb_capabilities_cache');
    }

    /**
     * 🚀 OPTIMIZATION: Get plugin info with caching
     *
     * @return array
     * @since 6.5.1
     */
    public static function get_plugin_info() {
        if (self::$plugin_info_cache !== null) {
            return self::$plugin_info_cache;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        self::$plugin_info_cache = get_plugin_data(__DIR__ . '/../../jrb-remote-site-api-for-openclaw.php');
        return self::$plugin_info_cache;
    }

    /**
     * Get plugin version
     *
     * @return string
     *
     * @since 6.5.1
     */
    public static function get_version() {
        return self::VERSION;
    }

    /**
     * Get API namespace
     *
     * @return string
     *
     * @since 6.5.1
     */
    public static function get_namespace() {
        return self::API_NAMESPACE;
    }
}
