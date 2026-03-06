<?php
namespace JRB\RemoteApi\Handlers;

if (!defined('ABSPATH')) exit;

use JRB\RemoteApi\Auth\Guard;

/**
 * FluentCRM Handler
 *
 * Provides REST API endpoints for FluentCRM integration
 * Optimized v6.5.1 with query caching, table whitelists, and pagination limits
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
     * 🚀 OPTIMIZATION: Static cache for table name validation
     * @var array
     */
    private static $table_validation_cache = [];

    /**
     * 🚀 OPTIMIZATION: Query result cache with object cache integration
     * @var array
     */
    private static $query_cache = [];

    /**
     * 🚀 OPTIMIZATION: Maximum pagination limit
     */
    const MAX_PER_PAGE = 100;

    /**
     * 🚀 OPTIMIZATION: Default pagination limit
     */
    const DEFAULT_PER_PAGE = 20;

    /**
     * 🚀 OPTIMIZATION: Cache TTL for query results (in seconds)
     */
    const QUERY_CACHE_TTL = 300; // 5 minutes

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
                    'default' => self::DEFAULT_PER_PAGE,
                    'minimum' => 1,
                    'maximum' => self::MAX_PER_PAGE,
                    'sanitize_callback' => 'absint',
                ],
                'status' => [
                    'type' => 'string',
                    'default' => 'subscribed',
                    'enum' => ['subscribed', 'unsubscribed', 'bounced', 'pending'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'search' => [
                    'type' => 'string',
                    'default' => '',
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

        // 🚀 OPTIMIZATION: List endpoint for other tables
        register_rest_route($ns, '/crm/(?P<table>[a-z_]+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'list_table_data'],
            'permission_callback' => function() {
                return Guard::verify_token_and_can('crm_read');
            },
            'args' => [
                'table' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'default' => self::DEFAULT_PER_PAGE,
                    'minimum' => 1,
                    'maximum' => self::MAX_PER_PAGE,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * 🚀 OPTIMIZATION: Validate table name against whitelist with caching
     *
     * @param string $table_name Table name to validate
     * @return bool True if valid, false otherwise
     *
     * @since 6.5.1
     */
    private static function validate_table_name($table_name) {
        // Check cache first
        if (isset(self::$table_validation_cache[$table_name])) {
            return self::$table_validation_cache[$table_name];
        }

        global $wpdb;

        // Extract base name (remove prefix)
        $base_name = str_replace($wpdb->prefix, '', $table_name);

        // Check against whitelist
        $is_valid = in_array($base_name, self::$allowed_tables, true);

        // Cache result
        self::$table_validation_cache[$table_name] = $is_valid;

        return $is_valid;
    }

    /**
     * 🚀 OPTIMIZATION: Generate cache key for query results
     *
     * @param string $query_type Type of query
     * @param array $params Query parameters
     * @return string Cache key
     */
    private static function get_cache_key($query_type, $params) {
        return 'jrb_crm_' . $query_type . '_' . md5(serialize($params));
    }

    /**
     * 🚀 OPTIMIZATION: Get cached query result
     *
     * @param string $key Cache key
     * @return mixed Cached data or false
     */
    private static function get_cached_query($key) {
        // Check static cache first (same request)
        if (isset(self::$query_cache[$key])) {
            return self::$query_cache[$key];
        }

        // Check WordPress object cache
        $cached = wp_cache_get($key, 'jrb_crm');
        if ($cached !== false) {
            self::$query_cache[$key] = $cached;
            return $cached;
        }

        return false;
    }

    /**
     * 🚀 OPTIMIZATION: Set cached query result
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     */
    private static function set_cached_query($key, $data) {
        // Set static cache
        self::$query_cache[$key] = $data;

        // Set WordPress object cache
        wp_cache_set($key, $data, 'jrb_crm', self::QUERY_CACHE_TTL);
    }

    /**
     * 🚀 OPTIMIZATION: Invalidate query cache
     *
     * @param string $pattern Cache key pattern to invalidate
     */
    public static function invalidate_query_cache($pattern = '') {
        // Clear static cache
        if (empty($pattern)) {
            self::$query_cache = [];
        } else {
            self::$query_cache = array_filter(
                self::$query_cache,
                function($key) use ($pattern) {
                    return strpos($key, $pattern) !== 0;
                }
            );
        }

        // WordPress object cache invalidation would require cache group flush
        // This is handled automatically by TTL expiry
    }

    /**
     * List subscribers with secure pagination and caching
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error
     *
     * @since 6.5.1
     */
    public static function list_subscribers($request) {
        global $wpdb;

        // Get and validate pagination parameters
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($request->get_param('per_page') ?: self::DEFAULT_PER_PAGE)));
        $status = sanitize_text_field($request->get_param('status') ?: 'subscribed');
        $search = sanitize_text_field($request->get_param('search') ?: '');
        $offset = ($page - 1) * $per_page;

        // 🚀 OPTIMIZATION: Generate cache key and check cache
        $cache_params = ['page' => $page, 'per_page' => $per_page, 'status' => $status, 'search' => $search];
        $cache_key = self::get_cache_key('subscribers_list', $cache_params);

        $cached_result = self::get_cached_query($cache_key);
        if ($cached_result !== false) {
            return new \WP_REST_Response($cached_result, 200);
        }

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

        // SECURE: Use esc_sql() for table name (WordPress < 6.2 compatibility)
        $table_name = esc_sql($table);

        try {
            // Build query with optional search
            if (!empty($search)) {
                $where_clause = $wpdb->prepare(
                    "WHERE status = %s AND (email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)",
                    $status,
                    '%' . $wpdb->esc_like($search) . '%',
                    '%' . $wpdb->esc_like($search) . '%',
                    '%' . $wpdb->esc_like($search) . '%'
                );
            } else {
                $where_clause = $wpdb->prepare("WHERE status = %s", $status);
            }

            // SECURE: Parameterized query with validated table name
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} {$where_clause} LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
                )
            );

            // Check for database errors
            if ($wpdb->last_error) {
                throw new \Exception($wpdb->last_error);
            }

            // Get total count for pagination metadata (cached separately)
            $count_cache_key = self::get_cache_key('subscribers_count', ['status' => $status, 'search' => $search]);
            $total = self::get_cached_query($count_cache_key);

            if ($total === false) {
                $total = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table_name} {$where_clause}",
                        $status
                    )
                );
                self::set_cached_query($count_cache_key, $total);
            }

            $response_data = [
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
                    'cached' => false,
                ],
            ];

            // Cache the response
            self::set_cached_query($cache_key, $response_data);

            return new \WP_REST_Response($response_data, 200);

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
     * Get single subscriber by ID with caching
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

        // 🚀 OPTIMIZATION: Check cache first
        $cache_key = self::get_cache_key('subscriber_single', ['id' => $id]);
        $cached_result = self::get_cached_query($cache_key);
        if ($cached_result !== false) {
            return new \WP_REST_Response($cached_result, 200);
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

            $response_data = [
                'success' => true,
                'data' => $subscriber,
                'meta' => [
                    'api_version' => \JRB\RemoteApi\Core\Plugin::VERSION,
                    'cached' => false,
                ],
            ];

            // Cache the result
            self::set_cached_query($cache_key, $response_data);

            return new \WP_REST_Response($response_data, 200);

        } catch (\Exception $e) {
            return new \WP_Error(
                'database_error',
                'Database query failed',
                ['status' => 500]
            );
        }
    }

    /**
     * 🚀 OPTIMIZATION: Generic list method for any whitelisted table
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error
     */
    public static function list_table_data($request) {
        global $wpdb;

        $table_param = sanitize_text_field($request->get_param('table'));
        $table = $wpdb->prefix . $table_param;

        // Validate table name
        if (!self::validate_table_name($table)) {
            return new \WP_Error('invalid_table', 'Invalid table name', ['status' => 400]);
        }

        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($request->get_param('per_page') ?: self::DEFAULT_PER_PAGE)));
        $offset = ($page - 1) * $per_page;

        // Check cache
        $cache_key = self::get_cache_key('table_list', ['table' => $table_param, 'page' => $page, 'per_page' => $per_page]);
        $cached_result = self::get_cached_query($cache_key);
        if ($cached_result !== false) {
            return new \WP_REST_Response($cached_result, 200);
        }

        $table_name = esc_sql($table);

        try {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
                )
            );

            $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

            $response_data = [
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
            ];

            self::set_cached_query($cache_key, $response_data);

            return new \WP_REST_Response($response_data, 200);

        } catch (\Exception $e) {
            return new \WP_Error(
                'database_error',
                'Database query failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
}
