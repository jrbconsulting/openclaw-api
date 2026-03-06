<?php
/**
 * OpenClaw API - FluentCommunity Module
 * 
 * Auto-activates when FluentCommunity plugin is installed.
 * Provides REST API access to community posts, groups, and members.
 */

if (!defined('ABSPATH')) exit;

class OpenClaw_FluentCommunity_Module {

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
        return function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentcommunity');
    }
    
    /**
     * Register module capabilities with labels (granular)
     */
    public static function register_capabilities($caps) {
        return array_merge($caps, [
            // Read operations
            'community_posts_read' => ['label' => 'Read Posts', 'default' => true, 'group' => 'FluentCommunity'],
            'community_groups_read' => ['label' => 'Read Groups', 'default' => true, 'group' => 'FluentCommunity'],
            'community_members_read' => ['label' => 'Read Members', 'default' => true, 'group' => 'FluentCommunity'],
            'community_comments_read' => ['label' => 'Read Comments', 'default' => true, 'group' => 'FluentCommunity'],
            // Write operations
            'community_posts_create' => ['label' => 'Create Posts', 'default' => false, 'group' => 'FluentCommunity'],
            'community_posts_update' => ['label' => 'Update Posts', 'default' => false, 'group' => 'FluentCommunity'],
            'community_posts_delete' => ['label' => 'Delete Posts', 'default' => false, 'group' => 'FluentCommunity'],
            'community_comments_create' => ['label' => 'Create Comments', 'default' => false, 'group' => 'FluentCommunity'],
            // Manage operations
            'community_groups_manage' => ['label' => 'Manage Groups', 'default' => false, 'group' => 'FluentCommunity'],
            'community_members_manage' => ['label' => 'Manage Members', 'default' => false, 'group' => 'FluentCommunity'],
        ]);
    }

    public static function register_routes() {
        // Posts
        register_rest_route('openclaw/v1', '/community/posts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_posts'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('community_posts_read'); },
        ]);
        register_rest_route('openclaw/v1', '/community/posts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_post'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('community_posts_read'); },
        ]);
        register_rest_route('openclaw/v1', '/community/posts', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_post'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('community_posts_create'); },
        ]);
        register_rest_route('openclaw/v1', '/community/posts/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_post'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('community_posts_update'); },
        ]);
        register_rest_route('openclaw/v1', '/community/posts/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_post'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('community_posts_delete'); },
        ]);

        // Groups
        register_rest_route('openclaw/v1', '/community/groups', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_groups'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('community_groups_read'); },
        ]);
        register_rest_route('openclaw/v1', '/community/groups/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_group'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('community_groups_read'); },
        ]);

        // Members
        register_rest_route('openclaw/v1', '/community/members', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_members'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('community_members_read'); },
        ]);
    }

    public static function list_posts($request) {
        $page = (int)($request->get_param('page') ?: 1);
        $per_page = min((int)($request->get_param('per_page') ?: 20), 100);
        $group_id = $request->get_param('group_id');

        $args = [
            'post_type' => 'fcom_post',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_status' => 'publish',
        ];

        if ($group_id) {
            $args['meta_query'] = [['key' => 'group_id', 'value' => (int)$group_id]];
        }

        $query = new WP_Query($args);
        $posts = array_map([__CLASS__, 'format_post'], $query->posts);

        return new WP_REST_Response([
            'data' => $posts,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ], 200);
    }

    public static function format_post($post) {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'author_id' => $post->post_author,
            'date' => $post->post_date,
            'group_id' => get_post_meta($post->ID, 'group_id', true),
        ];
    }

    public static function get_post($request) {
        $post = get_post($request->get_param('id'));
        if (!$post || $post->post_type !== 'fcom_post') {
            return new WP_REST_Response(['error' => 'Post not found'], 404);
        }
        return new WP_REST_Response(self::format_post($post), 200);
    }

    public static function create_post($request) {
        $data = $request->get_json_params();

        $post_id = wp_insert_post([
            'post_type' => 'fcom_post',
            'post_title' => sanitize_text_field($data['title'] ?? ''),
            'post_content' => wp_kses_post($data['content'] ?? ''),
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ?: 1,
        ]);

        if (is_wp_error($post_id)) {
            return new WP_REST_Response(['error' => $post_id->get_error_message()], 400);
        }

        if (!empty($data['group_id'])) {
            update_post_meta($post_id, 'group_id', (int)$data['group_id']);
        }

        return new WP_REST_Response(self::format_post(get_post($post_id)), 201);
    }

    public static function list_groups($request) {
        $groups = get_posts([
            'post_type' => 'fcom_group',
            'posts_per_page' => 50,
            'post_status' => 'publish',
        ]);

        $data = array_map(function($group) {
            return [
                'id' => $group->ID,
                'title' => $group->post_title,
                'description' => $group->post_content,
                'member_count' => get_post_meta($group->ID, 'member_count', true) ?: 0,
            ];
        }, $groups);

        return new WP_REST_Response($data, 200);
    }

    public static function get_group($request) {
        $group = get_post($request->get_param('id'));
        if (!$group || $group->post_type !== 'fcom_group') {
            return new WP_REST_Response(['error' => 'Group not found'], 404);
        }

        return new WP_REST_Response([
            'id' => $group->ID,
            'title' => $group->post_title,
            'description' => $group->post_content,
            'member_count' => get_post_meta($group->ID, 'member_count', true) ?: 0,
        ], 200);
    }

    public static function list_members($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fcom_members';

        // Check if table exists safely
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return new WP_REST_Response(['error' => 'Members table not found'], 404);
        }

        $page = max(1, (int)($request->get_param('page') ?: 1));
        $per_page = min((int)($request->get_param('per_page') ?: 20), 100);
        $offset = ($page - 1) * $per_page;

        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, name, created_at FROM {$table} LIMIT %d OFFSET %d",
            $per_page, $offset
        ));

        $data = array_map(function($member) {
            return [
                'id' => (int)$member->id,
                'user_id' => $member->user_id ? (int)$member->user_id : null,
                'name' => sanitize_text_field($member->name ?? ''),
                'joined_at' => $member->created_at ?? '',
            ];
        }, $members);

        return new WP_REST_Response($data, 200);
    }

    public static function is_active() {
        return self::$active;
    }
}

OpenClaw_FluentCommunity_Module::init();

// Register capabilities
add_filter('openclaw_default_capabilities', function($caps) {
    return array_merge($caps, [
        'community_read' => true,
        'community_write' => false,
    ]);
});