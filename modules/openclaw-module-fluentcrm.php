<?php
/**
 * OpenClaw API - FluentCRM Module
 * 
 * Auto-activates when FluentCRM plugin is installed.
 * Provides REST API access to contacts, lists, campaigns, sequences.
 */

if (!defined('ABSPATH')) exit;

class OpenClaw_FluentCRM_Module {

    private static $active = false;

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'check_and_activate'], 15);
    }

    public static function check_and_activate() {
        if (!self::is_fluentcrm_active()) {
            return;
        }

        self::$active = true;
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('openclaw_module_capabilities', [__CLASS__, 'register_capabilities']);
        self::log('Module activated - FluentCRM integration enabled');
    }

    private static function is_fluentcrm_active() {
        // Try centralized detection first
        if (function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentcrm')) {
            return true;
        }
        // Fallback: check for FluentCRM classes directly
        return class_exists('FluentCRM\App\Models\Subscriber') || class_exists('FluentCrm\App\Models\Subscriber');
    }
    
    /**
     * Register module capabilities with labels (granular)
     */
    public static function register_capabilities($caps) {
        return array_merge($caps, [
            // Read operations
            'crm_subscribers_read' => ['label' => 'Read Subscribers', 'default' => true, 'group' => 'FluentCRM'],
            'crm_lists_read' => ['label' => 'Read Lists', 'default' => true, 'group' => 'FluentCRM'],
            'crm_campaigns_read' => ['label' => 'Read Campaigns', 'default' => true, 'group' => 'FluentCRM'],
            'crm_tags_read' => ['label' => 'Read Tags', 'default' => true, 'group' => 'FluentCRM'],
            'crm_reports_read' => ['label' => 'Read Reports', 'default' => true, 'group' => 'FluentCRM'],
            // Write operations
            'crm_subscribers_create' => ['label' => 'Create Subscribers', 'default' => false, 'group' => 'FluentCRM'],
            'crm_subscribers_update' => ['label' => 'Update Subscribers', 'default' => false, 'group' => 'FluentCRM'],
            'crm_subscribers_delete' => ['label' => 'Delete Subscribers', 'default' => false, 'group' => 'FluentCRM'],
            'crm_lists_manage' => ['label' => 'Manage Lists', 'default' => false, 'group' => 'FluentCRM'],
            'crm_tags_manage' => ['label' => 'Manage Tags', 'default' => false, 'group' => 'FluentCRM'],
            'crm_campaigns_create' => ['label' => 'Create Campaigns', 'default' => false, 'group' => 'FluentCRM'],
            'crm_campaigns_send' => ['label' => 'Send Campaigns', 'default' => false, 'group' => 'FluentCRM'],
        ]);
    }

    public static function register_routes() {
        // Subscribers CRUD
        register_rest_route('openclaw/v1', '/crm/subscribers', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_subscribers'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_subscribers_read'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/subscribers', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_subscriber'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_subscribers_create'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/subscribers/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_subscriber'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_subscribers_read'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/subscribers/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_subscriber'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_subscribers_update'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/subscribers/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_subscriber'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_subscribers_delete'); }
        ]);

        // Lists & Tags
        register_rest_route('openclaw/v1', '/crm/lists', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_lists'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_lists_read'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/tags', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_tags'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_tags_read'); }
        ]);

        // Campaigns
        register_rest_route('openclaw/v1', '/crm/campaigns', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_campaigns'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_campaigns_read'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/campaigns/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_campaign'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_campaigns_read'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/campaigns/(?P<id>\d+)/send', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'send_campaign'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_campaigns_send'); }
        ]);

        // Sequences
        register_rest_route('openclaw/v1', '/crm/sequences', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_sequences'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_campaigns_read'); }
        ]);

        // Add to list/tag
        register_rest_route('openclaw/v1', '/crm/subscribers/(?P<id>\d+)/add-list', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_to_list'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_lists_manage'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/subscribers/(?P<id>\d+)/add-tag', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_tag'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_tags_manage'); }
        ]);

        // Stats
        register_rest_route('openclaw/v1', '/crm/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_stats'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_reports_read'); }
        ]);
    }

    // === IMPLEMENTATIONS ===

    public static function list_subscribers($request) {
        global $wpdb;
        
        $page = max(1, (int)($request->get_param('page') ?: 1));
        $per_page = min((int)($request->get_param('per_page') ?: 20), 100);
        $list_id = $request->get_param('list_id') ? (int)$request->get_param('list_id') : null;
        $tag_id = $request->get_param('tag_id') ? (int)$request->get_param('tag_id') : null;
        $search = $request->get_param('search') ? sanitize_text_field($request->get_param('search')) : null;
        $status = $request->get_param('status') ? sanitize_text_field($request->get_param('status')) : null;
        
        $table = $wpdb->prefix . 'fc_subscribers';
        $lists_pivot = $wpdb->prefix . 'fc_subscriber_lists';
        $tags_pivot = $wpdb->prefix . 'fc_subscriber_tags';
        
        $where = 'WHERE 1=1';
        $join = '';
        
        if ($list_id) {
            $join .= " JOIN $lists_pivot sl ON s.id = sl.subscriber_id";
            $where .= $wpdb->prepare(' AND sl.list_id = %d', $list_id);
        }
        if ($tag_id) {
            $join .= " JOIN $tags_pivot st ON s.id = st.subscriber_id";
            $where .= $wpdb->prepare(' AND st.tag_id = %d', $tag_id);
        }
        if ($status) {
            $where .= $wpdb->prepare(' AND s.status = %s', $status);
        }
        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(
                ' AND (s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s)',
                $search_like, $search_like, $search_like
            );
        }
        
        $total = $wpdb->get_var("SELECT COUNT(DISTINCT s.id) FROM $table s $join $where");
        $offset = ($page - 1) * $per_page;
        
        $subscribers = $wpdb->get_results(
            "SELECT DISTINCT s.id, s.email, s.first_name, s.last_name, s.status, s.created_at 
             FROM $table s $join $where 
             ORDER BY s.created_at DESC 
             LIMIT $per_page OFFSET $offset"
        );
        
        return new WP_REST_Response([
            'data' => array_map(function($s) {
                return [
                    'id' => (int)$s->id,
                    'email' => $s->email,
                    'first_name' => $s->first_name,
                    'last_name' => $s->last_name,
                    'status' => $s->status,
                    'created_at' => $s->created_at
                ];
            }, $subscribers ?: []),
            'meta' => [
                'total' => (int)$total,
                'page' => $page,
                'per_page' => $per_page,
                'pages' => $total ? ceil($total / $per_page) : 0
            ]
        ], 200);
    }

    public static function format_subscriber($s) {
        return [
            'id' => $s->id,
            'email' => $s->email,
            'first_name' => $s->first_name,
            'last_name' => $s->last_name,
            'full_name' => trim($s->first_name . ' ' . $s->last_name),
            'status' => $s->status,
            'lists' => $s->lists->map(function($l) { return ['id' => $l->id, 'title' => $l->title]; }),
            'tags' => $s->tags->map(function($t) { return ['id' => $t->id, 'title' => $t->title]; }),
            'created_at' => $s->created_at,
            'custom_values' => $s->custom_values ?? []
        ];
    }

    public static function get_subscriber($request) {
        $subscriber = \FluentCRM\App\Models\Subscriber::with(['lists', 'tags'])
            ->find($request->get_param('id'));

        if (!$subscriber) {
            return new WP_REST_Response(['error' => 'Subscriber not found'], 404);
        }

        return new WP_REST_Response(self::format_subscriber($subscriber), 200);
    }

    public static function create_subscriber($request) {
        $data = $request->get_json_params();

        $email = sanitize_email($data['email'] ?? '');
        if (empty($email) || !is_email($email)) {
            return new WP_REST_Response(['error' => 'Valid email is required'], 400);
        }

        // Check if exists
        $existing = \FluentCRM\App\Models\Subscriber::where('email', $email)->first();
        if ($existing) {
            return new WP_REST_Response([
                'error' => 'Subscriber already exists',
                'id' => $existing->id
            ], 409);
        }

        $allowed_statuses = ['subscribed', 'unsubscribed', 'pending', 'bounced'];
        $status = sanitize_text_field($data['status'] ?? 'subscribed');
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'subscribed';
        }

        $subscriber = \FluentCRM\App\Models\Subscriber::create([
            'email' => $email,
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'status' => $status,
            'custom_values' => $data['custom_values'] ?? []
        ]);

        if (!empty($data['lists'])) {
            $subscriber->lists()->sync($data['lists']);
        }
        if (!empty($data['tags'])) {
            $subscriber->tags()->sync($data['tags']);
        }

        // Trigger automation
        do_action('fluentcrm_contact_added', $subscriber);

        return new WP_REST_Response(self::format_subscriber($subscriber->fresh(['lists', 'tags'])), 201);
    }

    public static function update_subscriber($request) {
        $subscriber = \FluentCRM\App\Models\Subscriber::find($request->get_param('id'));

        if (!$subscriber) {
            return new WP_REST_Response(['error' => 'Subscriber not found'], 404);
        }

        // Whitelist allowed fields to prevent mass assignment
        $allowed_fields = ['first_name', 'last_name', 'email', 'status', 'phone', 'address_line_1', 
                          'address_line_2', 'city', 'state', 'country', 'zip', 'date_of_birth', 'source'];
        $data = array_intersect_key($request->get_json_params(), array_flip($allowed_fields));
        
        if (empty($data)) {
            return new WP_REST_Response(['error' => 'No valid fields to update'], 400);
        }
        
        $subscriber->fill($data)->save();

        return new WP_REST_Response(self::format_subscriber($subscriber->fresh(['lists', 'tags'])), 200);
    }

    public static function delete_subscriber($request) {
        $subscriber = \FluentCRM\App\Models\Subscriber::find($request->get_param('id'));

        if (!$subscriber) {
            return new WP_REST_Response(['error' => 'Subscriber not found'], 404);
        }

        $subscriber->delete();

        return new WP_REST_Response(['deleted' => true, 'id' => $request->get_param('id')], 200);
    }

    public static function list_lists($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_lists';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return new WP_REST_Response(['error' => 'FluentCRM lists table not found'], 500);
        }
        
        $lists = $wpdb->get_results(
            "SELECT id, title, slug, description, total_contacts, created_at, updated_at 
             FROM $table 
             ORDER BY title ASC"
        );
        
        return new WP_REST_Response($lists, 200);
    }

    public static function list_tags($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_tags';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return new WP_REST_Response(['error' => 'FluentCRM tags table not found'], 500);
        }
        
        $tags = $wpdb->get_results(
            "SELECT id, title, slug, description, total_contacts, created_at, updated_at 
             FROM $table 
             ORDER BY title ASC"
        );
        
        return new WP_REST_Response($tags, 200);
    }

    public static function list_campaigns($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_campaigns';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return new WP_REST_Response(['error' => 'FluentCRM campaigns table not found'], 500);
        }
        
        $campaigns = $wpdb->get_results(
            "SELECT id, title, slug, status, email_count, created_at, updated_at, scheduled_at, delivered_at 
             FROM $table 
             ORDER BY created_at DESC"
        );
        
        return new WP_REST_Response($campaigns, 200);
    }

    public static function get_campaign($request) {
        global $wpdb;
        $id = (int)$request->get_param('id');
        $table = $wpdb->prefix . 'fc_campaigns';
        
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d", $id
        ));
        
        if (!$campaign) {
            return new WP_REST_Response(['error' => 'Campaign not found'], 404);
        }
        
        return new WP_REST_Response($campaign, 200);
    }

    public static function send_campaign($request) {
        global $wpdb;
        $id = (int)$request->get_param('id');
        $table = $wpdb->prefix . 'fc_campaigns';
        
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d", $id
        ));
        
        if (!$campaign) {
            return new WP_REST_Response(['error' => 'Campaign not found'], 404);
        }
        
        // Trigger FluentCRM's campaign send
        if (function_exists('fluentcrm_scheduled_campaign')) {
            fluentcrm_scheduled_campaign($campaign);
        } else {
            do_action('fluentcrm_campaign_scheduled', $campaign);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Campaign send initiated',
            'campaign_id' => $campaign->id
        ], 200);
    }

    public static function list_sequences($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_sequences';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return new WP_REST_Response([], 200);
        }
        
        $sequences = $wpdb->get_results("SELECT * FROM $table ORDER BY title ASC");
        return new WP_REST_Response($sequences, 200);
    }

    public static function add_to_list($request) {
        $subscriber_id = (int)$request->get_param('id');
        $list_id = (int)($request->get_json_params()['list_id'] ?? 0);
        
        if (!$subscriber_id || !$list_id) {
            return new WP_REST_Response(['error' => 'Invalid request'], 400);
        }
        
        // Use FluentCRM helper if available
        if (function_exists('fluentcrm_add_contact_to_list')) {
            fluentcrm_add_contact_to_list($subscriber_id, $list_id);
        } else {
            global $wpdb;
            $table = $wpdb->prefix . 'fc_subscriber_lists';
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table (subscriber_id, list_id, status, created_at) VALUES (%d, %d, 'subscribed', NOW())",
                $subscriber_id, $list_id
            ));
        }
        
        return new WP_REST_Response(['success' => true], 200);
    }

    public static function add_tag($request) {
        $subscriber_id = (int)$request->get_param('id');
        $tag_id = (int)($request->get_json_params()['tag_id'] ?? 0);
        
        if (!$subscriber_id || !$tag_id) {
            return new WP_REST_Response(['error' => 'Invalid request'], 400);
        }
        
        // Use FluentCRM helper if available
        if (function_exists('fluentcrm_add_tag_to_subscriber')) {
            fluentcrm_add_tag_to_subscriber($subscriber_id, $tag_id);
        } else {
            global $wpdb;
            $table = $wpdb->prefix . 'fc_subscriber_tags';
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table (subscriber_id, tag_id, created_at) VALUES (%d, %d, NOW())",
                $subscriber_id, $tag_id
            ));
        }
        
        return new WP_REST_Response(['success' => true], 200);
    }

    public static function get_stats($request) {
        global $wpdb;
        
        $subscribers_table = $wpdb->prefix . 'fc_subscribers';
        $lists_table = $wpdb->prefix . 'fc_lists';
        $tags_table = $wpdb->prefix . 'fc_tags';
        $campaigns_table = $wpdb->prefix . 'fc_campaigns';
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $subscribers_table");
        $active = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $subscribers_table WHERE status = %s", 'subscribed'));
        $unsubscribed = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $subscribers_table WHERE status = %s", 'unsubscribed'));
        $pending = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $subscribers_table WHERE status = %s", 'pending'));
        
        return new WP_REST_Response([
            'total_subscribers' => (int)$total,
            'active' => (int)$active,
            'unsubscribed' => (int)$unsubscribed,
            'pending' => (int)$pending,
            'lists' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $lists_table"),
            'tags' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $tags_table"),
            'campaigns' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $campaigns_table")
        ], 200);
    }

    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[OpenClaw FluentCRM Module] {$message}");
        }
    }

    public static function is_active() {
        return self::$active;
    }
}

// Initialize module
OpenClaw_FluentCRM_Module::init();