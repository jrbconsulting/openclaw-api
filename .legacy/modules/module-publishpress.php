<?php
/**
 * OpenClaw API - PublishPress Statuses Module
 * 
 * Auto-activates when PublishPress Statuses plugin is installed.
 * Adds custom post statuses to REST API.
 */

if (!defined('ABSPATH')) exit;

class OpenClaw_PublishPress_Statuses {

    private static $active = false;

    public static function init() {
        // Check if plugin is active (runs at plugins_loaded)
        if (self::is_plugin_active()) {
            self::$active = true;
            
            // Add filters for custom statuses
            add_filter('rest_post_collection_params', [__CLASS__, 'add_custom_status_params']);
            add_filter('rest_post_query', [__CLASS__, 'filter_by_custom_status'], 10, 2);
            add_filter('rest_pre_insert_post', [__CLASS__, 'allow_custom_status'], 10, 2);
            add_filter('rest_post_schema', [__CLASS__, 'add_status_to_schema']);
            
            // Register endpoint
            add_action('rest_api_init', [__CLASS__, 'register_endpoint']);
            
            // Register capabilities
            add_filter('openclaw_module_capabilities', [__CLASS__, 'register_capabilities']);
        }
    }
    
    private static function is_plugin_active() {
        return function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('publishpress-statuses');
    }
    
    /**
     * Register module capabilities with labels
     */
    public static function register_capabilities($caps) {
        return array_merge($caps, [
            'statuses_read' => ['label' => 'Read Custom Statuses', 'default' => true, 'group' => 'PublishPress'],
        ]);
    }

    public static function get_custom_statuses() {
        global $wp_post_statuses;

        $statuses = [];
        $known_custom = [
            'pitch', 'in-progress', 'pending-review', 'approved', 
            'needs-work', 'rejected', 'assigned', 'query'
        ];

        foreach ($wp_post_statuses as $name => $obj) {
            // Include known custom statuses
            if (in_array($name, $known_custom)) {
                $statuses[$name] = [
                    'name' => $name,
                    'label' => $obj->label ?? ucfirst(str_replace('-', ' ', $name))
                ];
            }
            // Also include any status registered by PublishPress
            elseif (!empty($obj->publishpress_status) || !empty($obj->_builtin_reason)) {
                $statuses[$name] = [
                    'name' => $name,
                    'label' => $obj->label ?? $name
                ];
            }
        }

        return $statuses;
    }

    public static function add_custom_status_params($params) {
        $custom = array_keys(self::get_custom_statuses());
        
        if (isset($params['status'])) {
            $all_statuses = array_merge(
                ['publish', 'draft', 'pending', 'private', 'trash'],
                $custom
            );
            $params['status']['enum'] = $all_statuses;
            
            if (isset($params['status']['items']['enum'])) {
                $params['status']['items']['enum'] = $all_statuses;
            }
        }

        return $params;
    }

    public static function filter_by_custom_status($args, $request) {
        $status = $request->get_param('status');
        $custom_statuses = self::get_custom_statuses();

        if ($status && isset($custom_statuses[$status])) {
            $args['post_status'] = $status;
        }

        return $args;
    }

    public static function allow_custom_status($prepared_post, $request) {
        $status = $request->get_param('status');
        $custom_statuses = self::get_custom_statuses();

        if ($status && isset($custom_statuses[$status])) {
            $prepared_post->post_status = $status;
        }

        return $prepared_post;
    }

    public static function add_status_to_schema($schema) {
        if (!isset($schema['properties']['status'])) {
            return $schema;
        }

        $custom = array_keys(self::get_custom_statuses());
        $all_statuses = array_merge(
            ['publish', 'draft', 'pending', 'private', 'trash'],
            $custom
        );

        $schema['properties']['status']['enum'] = $all_statuses;

        return $schema;
    }

    public static function register_endpoint() {
        register_rest_route('openclaw/v1', '/statuses', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_all_statuses'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('statuses_read'); },
        ]);
    }

    public static function get_all_statuses($request) {
        global $wp_post_statuses;

        $statuses = [];

        // Standard statuses
        foreach (['publish', 'draft', 'pending', 'private', 'trash'] as $name) {
            if (isset($wp_post_statuses[$name])) {
                $statuses[$name] = [
                    'name' => $name,
                    'label' => $wp_post_statuses[$name]->label ?? ucfirst($name),
                    'type' => 'standard'
                ];
            }
        }

        // Custom statuses from PublishPress
        foreach (self::get_custom_statuses() as $name => $data) {
            $statuses[$name] = array_merge($data, ['type' => 'custom']);
        }

        return new WP_REST_Response($statuses, 200);
    }

    public static function is_active() {
        return self::$active;
    }
}

// Initialize module
OpenClaw_PublishPress_Statuses::init();