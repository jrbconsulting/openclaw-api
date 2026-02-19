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
        register_rest_route('openclaw/v1', '/crm/campaigns', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_campaign'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_campaigns_create'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/campaigns/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_campaign'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_campaigns_read'); }
        ]);
        register_rest_route('openclaw/v1', '/crm/campaigns/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_campaign'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('crm_campaigns_create'); }
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
        $pivot_table = $wpdb->prefix . 'fc_subscriber_pivot';
        
        $where = 'WHERE 1=1';
        $join = '';
        
        if ($list_id) {
            $join .= " JOIN $pivot_table sl ON s.id = sl.subscriber_id AND sl.object_type = 'list'";
            $where .= " AND sl.object_id = " . (int)$list_id;
        }
        if ($tag_id) {
            $join .= " JOIN $pivot_table st ON s.id = st.subscriber_id AND st.object_type = 'tag'";
            $where .= " AND st.object_id = " . (int)$tag_id;
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
            return new WP_REST_Response(['error' => 'FluentCRM lists table not found', 'table' => $table], 500);
        }
        
        $lists = $wpdb->get_results("SELECT * FROM $table ORDER BY id ASC");
        
        return new WP_REST_Response($lists, 200);
    }

    public static function list_tags($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_tags';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return new WP_REST_Response(['error' => 'FluentCRM tags table not found'], 500);
        }
        
        $tags = $wpdb->get_results("SELECT * FROM $table ORDER BY id ASC");
        return new WP_REST_Response($tags, 200);
    }

    public static function list_campaigns($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_campaigns';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return new WP_REST_Response(['error' => 'FluentCRM campaigns table not found'], 500);
        }
        
        $campaigns = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
        return new WP_REST_Response($campaigns, 200);
    }

    public static function create_campaign($request) {
        global $wpdb;
        $data = $request->get_json_params();
        
        $table = $wpdb->prefix . 'fc_campaigns';
        
        // Required fields
        $title = sanitize_text_field($data['title'] ?? '');
        if (empty($title)) {
            return new WP_REST_Response(['error' => 'Campaign title is required'], 400);
        }
        
        // Build campaign data with correct FluentCRM schema
        $settings = [
            'mailer_settings' => [
                'from_name' => sanitize_text_field($data['from_name'] ?? get_bloginfo('name')),
                'from_email' => sanitize_email($data['from_email'] ?? get_option('admin_email')),
                'reply_to_name' => sanitize_text_field($data['reply_to_name'] ?? ''),
                'reply_to_email' => sanitize_email($data['reply_to_email'] ?? ''),
                'is_custom' => 'yes'
            ],
            'subscribers' => [['list' => 'all', 'tag' => 'all']],
            'excludedSubscribers' => [['list' => '', 'tag' => '']],
            'sending_filter' => 'list_tag',
            'sending_type' => 'instant',
            'is_transactional' => 'no'
        ];
        
        // Target specific lists if provided
        if (!empty($data['list_ids'])) {
            $settings['subscribers'] = [];
            foreach ((array)$data['list_ids'] as $list_id) {
                $settings['subscribers'][] = ['list' => (string)$list_id, 'tag' => 'all'];
            }
        }
        
        $campaign_data = [
            'title' => $title,
            'slug' => sanitize_title($title),
            'status' => 'draft',
            'type' => 'campaign',
            'template_id' => 0,
            'design_template' => 'simple',
            'email_subject' => sanitize_text_field($data['subject'] ?? $title),
            'email_pre_header' => sanitize_text_field($data['preheader'] ?? ''),
            'email_body' => wp_kses_post($data['email_body'] ?? ''),  // Sanitize HTML content
            'recipients_count' => 0,
            'delay' => 0,
            'utm_status' => 0,
            'settings' => serialize($settings),  // FluentCRM uses PHP serialized arrays
            'created_by' => get_current_user_id() ?: 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];
        
        $inserted = $wpdb->insert($table, $campaign_data);
        if (!$inserted) {
            return new WP_REST_Response(['error' => 'Failed to create campaign', 'wpdb_error' => $wpdb->last_error], 500);
        }
        
        $campaign_id = $wpdb->insert_id;
        
        // Create campaign emails for subscribers in target lists
        $list_ids = $data['list_ids'] ?? [];
        $subscriber_count = 0;
        
        if (!empty($list_ids) && class_exists('FluentCRM\App\Models\Subscriber')) {
            $campaign_emails_table = $wpdb->prefix . 'fc_campaign_emails';
            
            // Use FluentCRM's Eloquent model to get subscribers by list
            // This approach works in add_to_list, so it should work here too
            $subscribers = \FluentCRM\App\Models\Subscriber::whereIn('id', function($query) use ($list_ids, $wpdb) {
                $pivot_table = $wpdb->prefix . 'fc_subscriber_pivot';
                $query->select('subscriber_id')
                      ->from($pivot_table)
                      ->where('object_type', 'list')
                      ->whereIn('object_id', $list_ids);
            })->get();
            
            error_log("OpenClaw API campaign Eloquent query found: " . count($subscribers) . " subscribers");
            
            // Create campaign email records
            foreach ($subscribers as $sub) {
                $result = $wpdb->insert($campaign_emails_table, [
                    'campaign_id' => $campaign_id,
                    'subscriber_id' => $sub->id,
                    'email' => $sub->email,
                    'first_name' => $sub->first_name,
                    'last_name' => $sub->last_name,
                    'status' => 'pending',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ]);
                if ($result !== false) {
                    $subscriber_count++;
                }
            }
            
            $wpdb->update($table, ['recipients_count' => $subscriber_count], ['id' => $campaign_id]);
            error_log("OpenClaw API campaign final count: $subscriber_count");
        }
        
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $campaign_id));
        
        return new WP_REST_Response([
            'id' => (int)$campaign->id,
            'title' => $campaign->title,
            'status' => $campaign->status,
            'subject' => $campaign->email_subject,
            'recipients_count' => (int)$campaign->recipients_count,
            'created_at' => $campaign->created_at,
            'message' => 'Campaign created as draft. Use /crm/campaigns/{id}/send to send it.'
        ], 201);
    }

    public static function update_campaign($request) {
        global $wpdb;
        $id = (int)$request->get_param('id');
        $data = $request->get_json_params();
        
        $table = $wpdb->prefix . 'fc_campaigns';
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        
        if (!$campaign) {
            return new WP_REST_Response(['error' => 'Campaign not found'], 404);
        }
        
        if ($campaign->status !== 'draft') {
            return new WP_REST_Response(['error' => 'Only draft campaigns can be updated'], 400);
        }
        
        // Allowed fields to update
        $allowed_fields = ['title', 'email_subject', 'email_preheader', 'email_body', 
                          'email_body_plain', 'from_name', 'from_email', 'reply_to_name', 'reply_to_email'];
        $update_data = array_intersect_key($data, array_flip($allowed_fields));
        
        if (empty($update_data)) {
            return new WP_REST_Response(['error' => 'No valid fields to update'], 400);
        }
        
        // Sanitize
        foreach ($update_data as $key => $value) {
            if (strpos($key, 'email') === 0 && strpos($key, 'body') === false) {
                $update_data[$key] = sanitize_email($value);
            } elseif ($key === 'email_body') {
                // Allow safe HTML for email body, strip dangerous tags
                $update_data[$key] = wp_kses_post($value);
            } elseif ($key === 'email_body_plain') {
                // Plain text version - escape HTML entities
                $update_data[$key] = sanitize_textarea_field($value);
            } else {
                $update_data[$key] = sanitize_text_field($value);
            }
        }
        
        $update_data['updated_at'] = current_time('mysql');
        $wpdb->update($table, $update_data, ['id' => $id]);
        
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        return new WP_REST_Response($campaign, 200);
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
        
        // Check if campaign has emails queued
        $emails_table = $wpdb->prefix . 'fc_campaign_emails';
        $email_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $emails_table WHERE campaign_id = %d", $id
        ));
        
        if ($email_count == 0) {
            return new WP_REST_Response([
                'error' => 'No recipients found for this campaign',
                'hint' => 'Create campaign with list_ids to populate recipients, or add subscribers to the target list first'
            ], 400);
        }
        
        // Update campaign status to processing/scheduled
        $wpdb->update($table, [
            'status' => 'pending',
            'scheduled_at' => current_time('mysql')
        ], ['id' => $id]);
        
        // Trigger FluentCRM's cron to process pending emails
        // This hook tells FluentCRM to send pending campaign emails
        if (class_exists('FluentCRM\App\Models\Campaign')) {
            $campaignModel = \FluentCRM\App\Models\Campaign::find($id);
            if ($campaignModel) {
                do_action('fluentcrm_campaign_status_changed', $campaignModel, 'pending');
            }
        }
        
        // Also trigger general campaign process hook
        do_action('fluentcrm_scheduled_hourly_tasks');
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Campaign queued for sending',
            'campaign_id' => $id,
            'recipients' => (int)$email_count,
            'note' => 'FluentCRM will process emails via its scheduled tasks. May take a few minutes.'
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
            return new WP_REST_Response(['error' => 'Invalid request - requires subscriber_id and list_id'], 400);
        }
        
        global $wpdb;
        // FluentCRM uses unified pivot table for lists AND tags
        $table = $wpdb->prefix . 'fc_subscriber_pivot';
        
        // Check if already in list
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE subscriber_id = %d AND object_id = %d AND object_type = 'list'",
            $subscriber_id, $list_id
        ));
        
        if ($existing) {
            return new WP_REST_Response(['success' => true, 'message' => 'Already in list', 'existing_id' => (int)$existing], 200);
        }
        
        // Insert into pivot table
        $result = $wpdb->insert($table, [
            'subscriber_id' => $subscriber_id,
            'object_id' => $list_id,
            'object_type' => 'list',
            'status' => 'subscribed',
            'created_at' => current_time('mysql')
        ]);
        
        if ($result === false) {
            return new WP_REST_Response([
                'error' => 'Failed to add to list',
                'wpdb_error' => $wpdb->last_error,
                'table' => $table
            ], 500);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'insert_id' => $wpdb->insert_id,
            'message' => 'Added to list successfully'
        ], 200);
    }

    public static function add_tag($request) {
        $subscriber_id = (int)$request->get_param('id');
        $tag_id = (int)($request->get_json_params()['tag_id'] ?? 0);
        
        if (!$subscriber_id || !$tag_id) {
            return new WP_REST_Response(['error' => 'Invalid request - requires subscriber_id and tag_id'], 400);
        }
        
        global $wpdb;
        // Use unified pivot table for consistency with add_to_list
        $table = $wpdb->prefix . 'fc_subscriber_pivot';
        
        // Check if already tagged
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE subscriber_id = %d AND object_id = %d AND object_type = 'FluentCrm\\App\\Models\\Tag'",
            $subscriber_id, $tag_id
        ));
        
        if ($existing) {
            return new WP_REST_Response(['success' => true, 'message' => 'Already tagged', 'existing_id' => (int)$existing], 200);
        }
        
        // Insert into pivot table
        $result = $wpdb->insert($table, [
            'subscriber_id' => $subscriber_id,
            'object_id' => $tag_id,
            'object_type' => 'FluentCrm\\App\\Models\\Tag',
            'status' => 'subscribed',
            'created_at' => current_time('mysql')
        ]);
        
        if ($result === false) {
            return new WP_REST_Response([
                'error' => 'Failed to add tag',
                'wpdb_error' => $wpdb->last_error,
                'table' => $table
            ], 500);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'insert_id' => $wpdb->insert_id,
            'message' => 'Tag added successfully'
        ], 200);
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