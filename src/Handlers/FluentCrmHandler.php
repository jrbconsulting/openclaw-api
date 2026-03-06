<?php
namespace JRB\RemoteApi\Handlers;

if (!defined('ABSPATH')) exit;

use JRB\RemoteApi\Auth\Guard;

class FluentCrmHandler {
    
    public static function register_routes() {
        $ns = \JRB\RemoteApi\Core\Plugin::API_NAMESPACE;

        register_rest_route($ns, '/crm/subscribers', [
            'methods' => 'GET',
            'callback' => [self::class, 'list_subscribers'],
            'permission_callback' => function() { return Guard::verify_token_and_can('crm_subscribers_read'); }
        ]);
    }

    public static function list_subscribers($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_subscribers';
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i LIMIT 20", $table));
        return new \WP_REST_Response($results, 200);
    }
}
