<?php
namespace JRB\RemoteApi\Handlers;

if (!defined('ABSPATH')) exit;

use JRB\RemoteApi\Auth\Guard;

/**
 * FluentCRM Handler
 * 
 * Provides REST API endpoints for FluentCRM integration
 * All database queries use proper validation and parameterization
 * 
 * @since 6.5.1
 * @package JRB\RemoteApi\Handlers
 */
class FluentCrmHandler {
    
    /**
     * Allowed FluentCRM tables whitelist
     * Prevents SQL injection via table name manipulation
     * 
     * @var array
     * @since 6.5.1
     */
    private static $allowed_tables = [
        'fc_subscribers',
        'fc_lists',
        'fc_tags',
        'fc_campaigns',
        'fc_contacts',
        'fc_contact_lists',
        'fc_contact_tags',
    ];
    
    /**
     * Register REST API routes
     * 
     * @since 6.5.1
     */
    public static function register_routes() {
        $ns = \JRB\RemoteApi\Core\Plugin::API_NAMESPACE;

        // List subscribers with pagination
        register_rest_route($ns, '/crm/subscribers', [
            'methods' => 'GET',
            'callback' => [self::class, 'list_subscribers'],
            'permission_callback' => function() { 
                return Guard::verify_token_and_can('crm_subscribers_read'); 
            },
            'args' => [
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                    'sanitize_callback' => 'absint',
                ],
                'status' => [
                    'type' => 'string',
                    'default' => 'subscribed',
                    'enum' => ['subscribed', 'unsubscribed', 'bounced', 'pending'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
        
        // Get single subscriber
        register_rest_route($ns, '/crm/subscribers/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_subscriber'],
            'permission_callback' => function() { 
                return Guard::verify_token_and_can('crm_subscribers_read'); 
            },
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Validate table name against whitelist
     * 
     * @param string $table_name Table name to validate
     * @return bool True if valid, false otherwise
     * 
     * @since 6.5.1
     */
    private static function validate_table_name($table_name) {
        global $wpdb;
        
        // Extract base name (remove prefix)
        $base_name = str_replace($wpdb->prefix, '', $table_name);
        
        // Check against whitelist
        return in_array($base_name, self::$allowed_tables, true);
    }

    /**
     * List subscribers with secure pagination
     * 
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error
     * 
     * @since 6.5.1
     */
    public static function list_subscribers($request) {
        global $wpdb;
        
        // Define and validate table name
        $table = $wpdb->prefix . 'fc_subscribers';
        
        if (!self::validate_table_name($table)) {
            error_log('[JRB Remote API] Invalid table name attempted: ' . $table);
            return new \WP_Error(
                'invalid_table', 
                'Invalid table name', 
                ['status' => 400]
            );
        }
        
        // Get and validate pagination parameters
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, (int) ($request->get_param('per_page') ?: 20)));
        $status = sanitize_text_field($request->get_param('status') ?: 'subscribed');
        $offset = ($page - 1) * $per_page;
        
        // SECURE: Use esc_sql() for table name (WordPress < 6.2 compatibility)
        // This is safer than %i which requires WordPress 6.2+
        $table_name = esc_sql($table);
        
        try {
            // SECURE: Parameterized query with validated table name
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE status = %s LIMIT %d OFFSET %d",
                    $status,
                    $per_page,
                    $offset
                )
            );
            
            // Check for database errors
            if ($wpdb->last_error) {
                throw new \Exception($wpdb->last_error);
            }
            
            // Get total count for pagination metadata
            $total = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
                    $status
                )
            );
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $results,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => (int) $total,
                    'total_pages' => ceil($total / $per_page),
                ],
                'meta' => [
                    'api_version' => \JRB\RemoteApi\Core\Plugin::VERSION,
                    'namespace' => \JRB\RemoteApi\Core\Plugin::API_NAMESPACE,
                ],
            ], 200);
            
        } catch (\Exception $e) {
            error_log('[JRB Remote API] Database error in list_subscribers: ' . $e->getMessage());
            
            return new \WP_Error(
                'database_error',
                'Database query failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Get single subscriber by ID
     * 
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error
     * 
     * @since 6.5.1
     */
    public static function get_subscriber($request) {
        global $wpdb;
        
        $id = absint($request->get_param('id'));
        
        if ($id <= 0) {
            return new \WP_Error('invalid_id', 'Invalid subscriber ID', ['status' => 400]);
        }
        
        $table = $wpdb->prefix . 'fc_subscribers';
        
        if (!self::validate_table_name($table)) {
            return new \WP_Error('invalid_table', 'Invalid table name', ['status' => 400]);
        }
        
        $table_name = esc_sql($table);
        
        try {
            $subscriber = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE id = %d",
                    $id
                )
            );
            
            if (!$subscriber) {
                return new \WP_Error('not_found', 'Subscriber not found', ['status' => 404]);
            }
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $subscriber,
                'meta' => [
                    'api_version' => \JRB\RemoteApi\Core\Plugin::VERSION,
                ],
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_Error(
                'database_error',
                'Database query failed',
                ['status' => 500]
            );
        }
    }
}
