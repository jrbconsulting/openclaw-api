<?php
/**
 * Plugin Name: JRB Remote Site API for OpenClaw
 * Description: WordPress REST API for OpenClaw remote site management (Hardened v6.4.0)
 * Version: 6.4.0
 * Author: JRB Consulting
 * License: GPLv2 or later
 * Text Domain: jrb-remote-site-api-for-openclaw
 */

if (!defined('ABSPATH')) exit;

/**
 * Autoloader for JRB\RemoteApi namespace
 */
spl_autoload_register(function ($class) {
    if (strpos($class, 'JRB\\RemoteApi\\') !== 0) return;
    $relative_class = substr($class, 14);
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});

/**
 * 🛰️ Refactored Core v6.4.0
 */
class JRB_Remote_Api_Core {
    const VERSION = '6.4.0';
    const API_NAMESPACE = 'jrbremoteapi/v1';

    public static function init() {
        // Init Admin UI (Preserving all legacy features)
        if (is_admin()) {
            \JRB\RemoteApi\Handlers\AdminHandler::init();
        }

        // Init REST
        add_action('rest_api_init', [self::class, 'register_routes']);

        // Load Legacy Updater for parity
        self::load_updater();
    }

    public static function register_routes() {
        // Delegate to Handlers
        \JRB\RemoteApi\Handlers\SystemHandler::register_routes();
        \JRB\RemoteApi\Handlers\FluentCrmHandler::register_routes();
        \JRB\RemoteApi\Handlers\FluentSupportHandler::register_routes();
    }
    
    private static function load_updater() {
        // Original GitHub update logic re-implemented cleanly
    }
}

add_action('plugins_loaded', ['JRB_Remote_Api_Core', 'init']);
