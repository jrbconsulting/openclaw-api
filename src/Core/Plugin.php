<?php
namespace JRB\RemoteApi\Core;

if (!defined('ABSPATH')) exit;

/**
 * Core Plugin Class
 * 
 * Central configuration and initialization for JRB Remote Site API
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
     * Initialize the plugin
     * 
     * @since 6.5.1
     */
    public static function init() {
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
        
        // Integration handlers (conditionally loaded by modules)
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
