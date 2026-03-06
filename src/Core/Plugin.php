<?php
namespace JRB\RemoteApi\Core;

if (!defined('ABSPATH')) exit;

class Plugin {
    const VERSION = '6.4.0';
    const API_NAMESPACE = 'jrbremoteapi/v1';
    const TEXT_DOMAIN = 'jrb-remote-site-api-for-openclaw';

    public static function init() {
        if (is_admin()) {
            \JRB\RemoteApi\Handlers\AdminHandler::init();
        }
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes() {
        // Delegate to Handlers
        \JRB\RemoteApi\Handlers\SystemHandler::register_routes();
        \JRB\RemoteApi\Handlers\FluentCrmHandler::register_routes();
        \JRB\RemoteApi\Handlers\FluentSupportHandler::register_routes();
    }
}
