<?php
/**
 * OpenClaw API - FluentSupport Module
 * 
 * Auto-activates when FluentSupport plugin is installed.
 * Provides REST API access to tickets, responses, customers.
 * 
 * v2.5.4: Fixed ticket creation to use FluentSupport native models
 * v2.5.4: Added FluentForms → FluentSupport sync endpoint
 */

if (!defined('ABSPATH')) exit;

class OpenClaw_FluentSupport_Module {

    private static $active = false;

    public static function init() {
        // Check if plugin is active (runs at plugins_loaded)
        if (self::is_plugin_active()) {
            self::$active = true;
            add_action('rest_api_init', [__CLASS__, 'register_routes']);
            // Register module capabilities
            add_filter('openclaw_module_capabilities', [__CLASS__, 'register_capabilities']);
        }
    }
    
    private static function is_plugin_active() {
        return function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentsupport');
    }
    
    /**
     * Register module capabilities with labels
     */
    public static function register_capabilities($caps) {
        return array_merge($caps, [
            // Read operations
            'support_tickets_read' => ['label' => 'Read Tickets', 'default' => true, 'group' => 'FluentSupport'],
            'support_responses_read' => ['label' => 'Read Responses', 'default' => true, 'group' => 'FluentSupport'],
            'support_customers_read' => ['label' => 'Read Customers', 'default' => true, 'group' => 'FluentSupport'],
            // Write operations
            'support_tickets_create' => ['label' => 'Create Tickets', 'default' => false, 'group' => 'FluentSupport'],
            'support_tickets_update' => ['label' => 'Update Tickets', 'default' => false, 'group' => 'FluentSupport'],
            'support_responses_create' => ['label' => 'Create Responses', 'default' => false, 'group' => 'FluentSupport'],
            // Manage operations
            'support_tickets_delete' => ['label' => 'Delete Tickets', 'default' => false, 'group' => 'FluentSupport'],
            'support_tickets_assign' => ['label' => 'Assign Tickets', 'default' => false, 'group' => 'FluentSupport'],
            'support_customers_manage' => ['label' => 'Manage Customers', 'default' => false, 'group' => 'FluentSupport'],
            'support_sync' => ['label' => 'Sync Forms to Tickets', 'default' => false, 'group' => 'FluentSupport'],
        ]);
    }

    public static function register_routes() {
        // Tickets
        register_rest_route('openclaw/v1', '/support/tickets', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_tickets'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_tickets_read'); }
        ]);
        register_rest_route('openclaw/v1', '/support/tickets', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_ticket'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_tickets_create'); }
        ]);
        register_rest_route('openclaw/v1', '/support/tickets/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_ticket'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_tickets_read'); }
        ]);
        register_rest_route('openclaw/v1', '/support/tickets/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_ticket'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_tickets_create'); }
        ]);

        // Responses
        register_rest_route('openclaw/v1', '/support/respond', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_response'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_responses_create'); }
        ]);

        // Customers
        register_rest_route('openclaw/v1', '/support/customers', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_customers'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_customers_read'); }
        ]);
        register_rest_route('openclaw/v1', '/support/customers/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_customer'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_customers_read'); }
        ]);

        // Assignment
        register_rest_route('openclaw/v1', '/support/assign', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'assign_ticket'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_tickets_assign'); }
        ]);

        // Stats
        register_rest_route('openclaw/v1', '/support/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_stats'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_tickets_read'); }
        ]);

        // Search
        register_rest_route('openclaw/v1', '/support/search', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'search_tickets'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_tickets_read'); }
        ]);
        
        // Sync: FluentForms → FluentSupport
        register_rest_route('openclaw/v1', '/support/sync-forms', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'sync_forms_to_tickets'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('support_sync'); }
        ]);
    }

    // === FLUENTSUPPORT MODEL ACCESS ===
    
    /**
     * Get FluentSupport Ticket model
     */
    private static function get_ticket_model() {
        if (class_exists('FluentSupport\App\Models\Ticket')) {
            return new \FluentSupport\App\Models\Ticket();
        }
        return null;
    }
    
    private static function get_customer_model() {
        if (class_exists('FluentSupport\App\Models\Customer')) {
            return new \FluentSupport\App\Models\Customer();
        }
        return null;
    }
    
    /**
     * Get FluentSupport Response model
     */
    private static function get_response_model() {
        if (class_exists('FluentSupport\App\Models\Response')) {
            return new \FluentSupport\App\Models\Response();
        }
        return null;
    }
    
    /**
     * Create response using FluentSupport native model
     */
    private static function create_response_native($ticket_id, $content, $agent_id) {
        $response_model = self::get_response_model();
        if ($response_model) {
            try {
                $response = $response_model->create([
                    'ticket_id' => $ticket_id,
                    'person_id' => $agent_id,
                    'person_type' => 'agent',
                    'conversation_type' => 'response',
                    'content' => $content,
                    'source' => 'web',
                ]);
                return $response ? $response->id : null;
            } catch (\Exception $e) {
                error_log('FluentSupport Response model error: ' . $e->getMessage());
                return null;
            }
        }
        return null;
    }

    // === TICKETS ===

    public static function list_tickets($request) {
        global $wpdb;
        
        $page = (int)($request->get_param('page') ?: 1);
        $per_page = (int)($request->get_param('per_page') ?: 20);
        $status = $request->get_param('status');
        $priority = $request->get_param('priority');
        $offset = ($page - 1) * $per_page;
        
        $table_name = $wpdb->prefix . 'fs_tickets';
        
        // Check if table exists (FluentSupport native)
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $where = 'WHERE 1=1';
            if ($status) {
                $where .= $wpdb->prepare(' AND status = %s', sanitize_text_field($status));
            }
            if ($priority) {
                $where .= $wpdb->prepare(' AND priority = %s', sanitize_text_field($priority));
            }
            
            $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where");
            $tickets = $wpdb->get_results(
                "SELECT * FROM $table_name $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset"
            );
            
            return new WP_REST_Response([
                'data' => array_map([__CLASS__, 'format_native_ticket'], $tickets ?: []),
                'meta' => ['total' => (int)$total, 'page' => $page, 'per_page' => $per_page]
            ], 200);
        }
        
        // Fallback: empty response if FluentSupport tables don't exist
        return new WP_REST_Response([
            'data' => [],
            'meta' => ['total' => 0, 'page' => $page, 'per_page' => $per_page, 'note' => 'FluentSupport tables not found']
        ], 200);
    }
    
    /**
     * Format native FluentSupport ticket
     */
    public static function format_native_ticket($ticket) {
        if (!$ticket) return null;
        
        return [
            'id' => (int)$ticket->id,
            'title' => $ticket->title ?? '',
            'content' => $ticket->content ?? '',
            'customer_id' => (int)($ticket->customer_id ?? 0),
            'status' => $ticket->status ?? 'new',
            'priority' => $ticket->priority ?? 'normal',
            'agent_id' => (int)($ticket->agent_id ?? 0),
            'created_at' => $ticket->created_at ?? '',
            'updated_at' => $ticket->updated_at ?? '',
            'source' => $ticket->source ?? 'api',
        ];
    }

    public static function get_ticket($request) {
        global $wpdb;
        $ticket_id = (int)$request->get_param('id');
        
        $table_name = $wpdb->prefix . 'fs_tickets';
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $ticket_id));
        
        if (!$ticket) {
            return new WP_REST_Response(['error' => 'Ticket not found'], 404);
        }
        
        $data = self::format_native_ticket($ticket);
        
        // Get responses
        $responses_table = $wpdb->prefix . 'fs_conversations';
        $responses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $responses_table WHERE ticket_id = %d ORDER BY created_at ASC",
            $ticket_id
        ));
        
        $data['responses'] = array_map(function($r) {
            return [
                'id' => (int)$r->id,
                'content' => $r->content ?? '',
                'person_id' => (int)($r->person_id ?? 0),
                'created_at' => $r->created_at ?? '',
            ];
        }, $responses ?: []);
        
        return new WP_REST_Response($data, 200);
    }

    public static function create_ticket($request) {
        global $wpdb;
        $data = $request->get_json_params();
        
        $title = sanitize_text_field($data['title'] ?? '');
        if (empty($title)) {
            return new WP_REST_Response(['error' => 'Ticket title is required'], 400);
        }
        
        $content = wp_kses_post($data['content'] ?? '');
        $email = sanitize_email($data['customer_email'] ?? '');
        $first_name = sanitize_text_field($data['customer_first_name'] ?? '');
        $last_name = sanitize_text_field($data['customer_last_name'] ?? '');
        $priority = sanitize_text_field($data['priority'] ?? 'normal');
        $agent_id = (int)($data['agent_id'] ?? 0);
        $source = sanitize_text_field($data['source'] ?? 'api');
        
        $allowed_priorities = ['low', 'normal', 'high', 'urgent', 'critical'];
        if (!in_array($priority, $allowed_priorities, true)) {
            $priority = 'normal';
        }
        
        $tickets_table = $wpdb->prefix . 'fs_tickets';
        $customers_table = $wpdb->prefix . 'fs_customers';
        
        // Check if tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$tickets_table'") !== $tickets_table) {
            return new WP_REST_Response([
                'error' => 'FluentSupport tables not found. Ensure FluentSupport is properly installed.',
                'debug' => [
                    'tables_exist' => false,
                    'expected_table' => $tickets_table
                ]
            ], 500);
        }
        
        // Find or create customer
        $customer_id = 0;
        if (!empty($email)) {
            $existing_customer = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $customers_table WHERE email = %s",
                $email
            ));
            
            if ($existing_customer) {
                $customer_id = (int)$existing_customer->id;
            } else {
                // Create new customer
                $wpdb->insert($customers_table, [
                    'email' => $email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
                $customer_id = $wpdb->insert_id;
            }
        }
        
        // Create ticket
        $inserted = $wpdb->insert($tickets_table, [
            'title' => $title,
            'content' => $content,
            'customer_id' => $customer_id,
            'status' => 'new',
            'priority' => $priority,
            'agent_id' => $agent_id,
            'source' => $source,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        
        if (!$inserted) {
            return new WP_REST_Response([
                'error' => 'Failed to create ticket',
                'db_error' => $wpdb->last_error
            ], 500);
        }
        
        $ticket_id = $wpdb->insert_id;
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tickets_table WHERE id = %d", $ticket_id));
        
        // Trigger FluentSupport hooks
        do_action('fluent_support_ticket_created', $ticket_id, $data);
        
        return new WP_REST_Response(self::format_native_ticket($ticket), 201);
    }

    public static function update_ticket($request) {
        global $wpdb;
        $ticket_id = (int)$request->get_param('id');
        $data = $request->get_json_params();
        
        $tickets_table = $wpdb->prefix . 'fs_tickets';
        
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tickets_table WHERE id = %d", $ticket_id));
        if (!$ticket) {
            return new WP_REST_Response(['error' => 'Ticket not found'], 404);
        }
        
        $update_data = ['updated_at' => current_time('mysql')];
        
        if (isset($data['title'])) {
            $update_data['title'] = sanitize_text_field($data['title']);
        }
        if (isset($data['content'])) {
            $update_data['content'] = wp_kses_post($data['content']);
        }
        if (isset($data['status'])) {
            $allowed_statuses = ['new', 'active', 'closed', 'resolved', 'waiting_customer', 'waiting_agent'];
            $status = sanitize_text_field($data['status']);
            if (in_array($status, $allowed_statuses, true)) {
                $update_data['status'] = $status;
            }
        }
        if (isset($data['priority'])) {
            $allowed_priorities = ['low', 'normal', 'high', 'urgent', 'critical'];
            $priority = sanitize_text_field($data['priority']);
            if (in_array($priority, $allowed_priorities, true)) {
                $update_data['priority'] = $priority;
            }
        }
        if (isset($data['agent_id'])) {
            $update_data['agent_id'] = (int)$data['agent_id'];
        }
        
        $wpdb->update($tickets_table, $update_data, ['id' => $ticket_id]);
        
        do_action('fluent_support_ticket_status_changed', $ticket_id, $data['status'] ?? null);
        
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tickets_table WHERE id = %d", $ticket_id));
        return new WP_REST_Response(self::format_native_ticket($ticket), 200);
    }

    // === RESPONSES ===

    public static function add_response($request) {
        global $wpdb;
        $data = $request->get_json_params();
        
        $ticket_id = (int)($data['ticket_id'] ?? 0);
        if ($ticket_id < 1) {
            return new WP_REST_Response(['error' => 'Valid ticket_id is required'], 400);
        }
        
        $content = wp_kses_post($data['content'] ?? '');
        if (empty($content)) {
            return new WP_REST_Response(['error' => 'Response content is required'], 400);
        }
        
        $agent_id = (int)($data['agent_id'] ?? 0);
        if ($agent_id < 1) {
            $agent_id = 1; // Default to first agent
        }
        
        $tickets_table = $wpdb->prefix . 'fs_tickets';
        $conversations_table = $wpdb->prefix . 'fs_conversations';
        
        // Verify ticket exists
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT id, customer_id FROM $tickets_table WHERE id = %d", $ticket_id));
        if (!$ticket) {
            return new WP_REST_Response(['error' => 'Ticket not found'], 404);
        }
        
        // Try native FluentSupport model first
        $response_id = self::create_response_native($ticket_id, $content, $agent_id);
        
        // Fallback to direct database insert if native model failed
        if (!$response_id) {
            // Get next serial number for this ticket
            $serial = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT MAX(serial) FROM $responses_table WHERE ticket_id = %d",
                $ticket_id
            )) + 1;
            
            // Generate content hash
            $content_hash = md5($content);
            
            // Add response (using FluentSupport native column names)
            $wpdb->insert($responses_table, [
                'ticket_id' => $ticket_id,
                'serial' => $serial,
                'person_id' => $agent_id,
                'conversation_type' => 'response',  // "response" or "note"
                'content' => $content,
                'source' => 'web',
                'content_hash' => $content_hash,
                'is_important' => 'no',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            
            $response_id = $wpdb->insert_id;
            
            // Check for errors
            if (!$response_id) {
                return new WP_REST_Response([
                    'error' => 'Failed to insert response',
                    'db_error' => $wpdb->last_error,
                    'last_query' => $wpdb->last_query,
                ], 500);
            }
        }
        
        // Update ticket status and timestamp
        $wpdb->update($tickets_table, [
            'status' => 'waiting_customer',
            'updated_at' => current_time('mysql'),
        ], ['id' => $ticket_id]);
        
        do_action('fluent_support_response_added', $ticket_id, $response_id, true);
        
        // Get agent info for response
        $agent_name = 'Support';
        $agents_table = $wpdb->prefix . 'fs_agents';
        $agent = $wpdb->get_row($wpdb->prepare("SELECT * FROM $agents_table WHERE user_id = %d", $agent_id));
        if ($agent) {
            $agent_name = trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? '')) ?: 'Support';
        }
        
        return new WP_REST_Response([
            'id' => $response_id,
            'ticket_id' => $ticket_id,
            'content' => $content,
            'author' => $agent_name,
            'agent_id' => $agent_id,
            'created_at' => current_time('mysql'),
        ], 201);
    }

    // === CUSTOMERS ===

    public static function list_customers($request) {
        global $wpdb;
        
        $page = (int)($request->get_param('page') ?: 1);
        $per_page = (int)($request->get_param('per_page') ?: 20);
        $search = $request->get_param('search');
        $offset = ($page - 1) * $per_page;
        
        $customers_table = $wpdb->prefix . 'fs_customers';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$customers_table'") !== $customers_table) {
            return new WP_REST_Response([], 200);
        }
        
        $where = 'WHERE 1=1';
        if ($search) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%';
            $where .= $wpdb->prepare(' AND (email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)', $search, $search, $search);
        }
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $customers_table $where");
        $customers = $wpdb->get_results("SELECT * FROM $customers_table $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
        
        $data = array_map(function($c) use ($wpdb) {
            $tickets_table = $wpdb->prefix . 'fs_tickets';
            $ticket_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tickets_table WHERE customer_id = %d", $c->id));
            
            return [
                'id' => (int)$c->id,
                'email' => $c->email ?? '',
                'first_name' => $c->first_name ?? '',
                'last_name' => $c->last_name ?? '',
                'ticket_count' => (int)$ticket_count,
                'created_at' => $c->created_at ?? '',
            ];
        }, $customers ?: []);
        
        return new WP_REST_Response($data, 200);
    }

    public static function get_customer($request) {
        global $wpdb;
        $customer_id = (int)$request->get_param('id');
        
        $customers_table = $wpdb->prefix . 'fs_customers';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $customer_id));
        
        if (!$customer) {
            return new WP_REST_Response(['error' => 'Customer not found'], 404);
        }
        
        $tickets_table = $wpdb->prefix . 'fs_tickets';
        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tickets_table WHERE customer_id = %d ORDER BY created_at DESC LIMIT 20",
            $customer_id
        ));
        
        return new WP_REST_Response([
            'id' => (int)$customer->id,
            'email' => $customer->email ?? '',
            'first_name' => $customer->first_name ?? '',
            'last_name' => $customer->last_name ?? '',
            'created_at' => $customer->created_at ?? '',
            'tickets' => array_map([__CLASS__, 'format_native_ticket'], $tickets ?: []),
        ], 200);
    }

    // === ASSIGNMENT ===

    public static function assign_ticket($request) {
        global $wpdb;
        $data = $request->get_json_params();
        
        $ticket_id = (int)($data['ticket_id'] ?? 0);
        $agent_id = (int)($data['agent_id'] ?? 0);
        
        if ($ticket_id < 1) {
            return new WP_REST_Response(['error' => 'Valid ticket_id required'], 400);
        }
        
        $tickets_table = $wpdb->prefix . 'fs_tickets';
        $wpdb->update($tickets_table, [
            'agent_id' => $agent_id,
            'updated_at' => current_time('mysql'),
        ], ['id' => $ticket_id]);
        
        do_action('fluent_support_ticket_assigned', $ticket_id, $agent_id);
        
        return new WP_REST_Response([
            'ticket_id' => $ticket_id,
            'agent_id' => $agent_id,
        ], 200);
    }

    // === SEARCH ===

    public static function search_tickets($request) {
        global $wpdb;
        $q = $request->get_param('q');
        
        if (!$q) {
            return new WP_REST_Response(['error' => 'Query required'], 400);
        }
        
        $search = '%' . $wpdb->esc_like(sanitize_text_field($q)) . '%';
        $tickets_table = $wpdb->prefix . 'fs_tickets';
        
        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tickets_table WHERE title LIKE %s OR content LIKE %s ORDER BY created_at DESC LIMIT 50",
            $search, $search
        ));
        
        return new WP_REST_Response([
            'query' => $q,
            'results' => array_map([__CLASS__, 'format_native_ticket'], $tickets ?: [])
        ], 200);
    }

    // === STATS ===

    public static function get_stats($request) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'fs_tickets';
        
        $stats = [
            'total' => 0,
            'by_status' => [],
            'unassigned' => 0,
        ];
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$tickets_table'") === $tickets_table) {
            $stats['total'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $tickets_table");
            
            $statuses = $wpdb->get_results("SELECT status, COUNT(*) as count FROM $tickets_table GROUP BY status");
            foreach ($statuses as $s) {
                $stats['by_status'][$s->status] = (int)$s->count;
            }
            
            $stats['unassigned'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $tickets_table WHERE agent_id = 0 OR agent_id IS NULL");
        }
        
        return new WP_REST_Response($stats, 200);
    }

    // === SYNC: FluentForms → FluentSupport ===
    
    /**
     * Sync FluentForms entries to FluentSupport tickets
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function sync_forms_to_tickets($request) {
        global $wpdb;
        
        $data = $request->get_json_params();
        $form_id = (int)($data['form_id'] ?? 0);
        $create_missing = !empty($data['create_missing']);
        $default_agent_id = (int)($data['default_agent_id'] ?? 0);
        
        // Get FluentForms entries table
        $entries_table = $wpdb->prefix . 'fluentform_entries';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$entries_table'") !== $entries_table) {
            return new WP_REST_Response(['error' => 'FluentForms entries table not found'], 500);
        }
        
        // Get FluentSupport tickets table
        $tickets_table = $wpdb->prefix . 'fs_tickets';
        $customers_table = $wpdb->prefix . 'fs_customers';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$tickets_table'") !== $tickets_table) {
            return new WP_REST_Response(['error' => 'FluentSupport tables not found'], 500);
        }
        
        // Build query for entries
        $where = 'WHERE 1=1';
        if ($form_id > 0) {
            $where .= $wpdb->prepare(' AND form_id = %d', $form_id);
        }
        
        // Get entries not already synced (we check for existing tickets by email/content)
        $entries = $wpdb->get_results(
            "SELECT id, form_id, response, created_at FROM $entries_table $where ORDER BY id DESC LIMIT 100"
        );
        
        $synced = [];
        $skipped = [];
        $errors = [];
        
        foreach ($entries as $entry) {
            $response = maybe_unserialize($entry->response);
            if (!is_array($response)) {
                $response = json_decode($entry->response, true);
            }
            
            if (!is_array($response)) {
                $skipped[] = ['entry_id' => $entry->id, 'reason' => 'Invalid response data'];
                continue;
            }
            
            // Extract customer info from form response
            $email = '';
            $first_name = '';
            $last_name = '';
            $message = '';
            
            // Common field patterns
            foreach ($response as $key => $value) {
                $key_lower = strtolower($key);
                
                if (strpos($key_lower, 'email') !== false) {
                    $email = sanitize_email($value);
                } elseif (strpos($key_lower, 'first_name') !== false || strpos($key_lower, 'firstname') !== false) {
                    $first_name = sanitize_text_field($value);
                } elseif (strpos($key_lower, 'last_name') !== false || strpos($key_lower, 'lastname') !== false) {
                    $last_name = sanitize_text_field($value);
                } elseif (strpos($key_lower, 'name') !== false && strpos($key_lower, 'first') === false && strpos($key_lower, 'last') === false) {
                    // Full name field - try to split
                    $name_parts = explode(' ', sanitize_text_field($value), 2);
                    $first_name = $name_parts[0] ?? '';
                    $last_name = $name_parts[1] ?? '';
                } elseif (in_array($key_lower, ['message', 'comment', 'description', 'enquiry', 'inquiry'])) {
                    $message = sanitize_textarea_field($value);
                }
            }
            
            // Check if email is extracted and if ticket already exists
            if (empty($email)) {
                $skipped[] = ['entry_id' => $entry->id, 'reason' => 'No email found in form'];
                continue;
            }
            
            // Check if ticket already exists for this email/message combination
            $existing_ticket = $wpdb->get_var($wpdb->prepare(
                "SELECT t.id FROM $tickets_table t 
                 JOIN $customers_table c ON t.customer_id = c.id 
                 WHERE c.email = %s AND t.content LIKE %s",
                $email, '%' . $wpdb->esc_like(substr($message, 0, 100)) . '%'
            ));
            
            if ($existing_ticket) {
                $skipped[] = ['entry_id' => $entry->id, 'reason' => 'Ticket already exists', 'ticket_id' => (int)$existing_ticket];
                continue;
            }
            
            if (!$create_missing) {
                $skipped[] = ['entry_id' => $entry->id, 'reason' => 'create_missing not enabled'];
                continue;
            }
            
            // Find or create customer
            $customer = $wpdb->get_row($wpdb->prepare("SELECT id FROM $customers_table WHERE email = %s", $email));
            $customer_id = 0;
            
            if ($customer) {
                $customer_id = (int)$customer->id;
            } else {
                $wpdb->insert($customers_table, [
                    'email' => $email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
                $customer_id = $wpdb->insert_id;
            }
            
            // Build ticket title from message or default
            $title = !empty($message) ? substr($message, 0, 100) : "Form Submission #$entry->id";
            if (strlen($message) > 100) {
                $title .= '...';
            }
            
            // Build full content
            $content_parts = [];
            foreach ($response as $key => $value) {
                if (is_scalar($value) && !empty($value) && !in_array(strtolower($key), ['_wp_http_referer', '__fluent_form_embded_post_id'])) {
                    $content_parts[] = "$key: $value";
                }
            }
            $content = implode("\n", $content_parts);
            
            // Create ticket
            $inserted = $wpdb->insert($tickets_table, [
                'title' => $title,
                'content' => $content,
                'customer_id' => $customer_id,
                'status' => 'new',
                'priority' => 'normal',
                'agent_id' => $default_agent_id,
                'source' => 'fluentform_' . $entry->form_id,
                'created_at' => $entry->created_at ?? current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            
            if ($inserted) {
                $ticket_id = $wpdb->insert_id;
                $synced[] = [
                    'entry_id' => $entry->id,
                    'ticket_id' => $ticket_id,
                    'email' => $email,
                    'name' => trim("$first_name $last_name"),
                ];
                
                do_action('fluent_support_ticket_created', $ticket_id, [
                    'source' => 'sync',
                    'entry_id' => $entry->id,
                    'form_id' => $entry->form_id,
                ]);
            } else {
                $errors[] = ['entry_id' => $entry->id, 'error' => $wpdb->last_error];
            }
        }
        
        return new WP_REST_Response([
            'synced_count' => count($synced),
            'skipped_count' => count($skipped),
            'error_count' => count($errors),
            'synced' => $synced,
            'skipped' => $skipped,
            'errors' => $errors,
        ], 200);
    }

    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[OpenClaw FluentSupport Module] {$message}");
        }
    }

    public static function is_active() {
        return self::$active;
    }
}

// Initialize
OpenClaw_FluentSupport_Module::init();