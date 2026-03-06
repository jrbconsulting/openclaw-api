<?php
/**
 * OpenClaw API - FluentProject Module
 * 
 * Auto-activates when FluentProject plugin is installed.
 * Provides REST API access to projects, tasks, boards.
 */

if (!defined('ABSPATH')) exit;

class OpenClaw_FluentProject_Module {

    private static $active = false;
    private static $project_type = 'fproject';
    private static $task_type = 'ftask';
    private static $board_type = 'ftask_board';

    public static function init() {
        // Check if plugin is active (runs at plugins_loaded)
        if (self::is_plugin_active()) {
            self::$active = true;
            add_action('rest_api_init', [__CLASS__, 'register_routes']);
            add_filter('openclaw_module_capabilities', [__CLASS__, 'register_capabilities']);
        }
    }
    
    private static function is_plugin_active() {
        return function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentboards');
    }
    
    /**
     * Register module capabilities with labels (granular)
     */
    public static function register_capabilities($caps) {
        return array_merge($caps, [
            // Read operations
            'project_boards_read' => ['label' => 'Read Boards', 'default' => true, 'group' => 'FluentProject'],
            'project_tasks_read' => ['label' => 'Read Tasks', 'default' => true, 'group' => 'FluentProject'],
            'project_comments_read' => ['label' => 'Read Comments', 'default' => true, 'group' => 'FluentProject'],
            // Write operations
            'project_tasks_create' => ['label' => 'Create Tasks', 'default' => false, 'group' => 'FluentProject'],
            'project_tasks_update' => ['label' => 'Update Tasks', 'default' => false, 'group' => 'FluentProject'],
            'project_tasks_delete' => ['label' => 'Delete Tasks', 'default' => false, 'group' => 'FluentProject'],
            'project_comments_create' => ['label' => 'Create Comments', 'default' => false, 'group' => 'FluentProject'],
            // Manage operations
            'project_boards_manage' => ['label' => 'Manage Boards', 'default' => false, 'group' => 'FluentProject'],
            'project_assign' => ['label' => 'Assign Tasks', 'default' => false, 'group' => 'FluentProject'],
        ]);
    }

    public static function register_routes() {
        // Projects
        register_rest_route('openclaw/v1', '/project/projects', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_projects'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_read'); }
        ]);
        register_rest_route('openclaw/v1', '/project/projects', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_project'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_create'); }
        ]);
        register_rest_route('openclaw/v1', '/project/projects/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_project'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_read'); }
        ]);
        register_rest_route('openclaw/v1', '/project/projects/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_project'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_create'); }
        ]);

        // Tasks
        register_rest_route('openclaw/v1', '/project/tasks', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_tasks'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_read'); }
        ]);
        register_rest_route('openclaw/v1', '/project/tasks', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_task'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_create'); }
        ]);
        register_rest_route('openclaw/v1', '/project/tasks/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_task'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_read'); }
        ]);
        register_rest_route('openclaw/v1', '/project/tasks/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_task'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_create'); }
        ]);
        register_rest_route('openclaw/v1', '/project/tasks/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_task'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_delete'); }
        ]);

        // Boards
        register_rest_route('openclaw/v1', '/project/boards', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_boards'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_read'); }
        ]);

        // Comments
        register_rest_route('openclaw/v1', '/project/comments', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_comment'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_create'); }
        ]);

        // Assignments
        register_rest_route('openclaw/v1', '/project/assign', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'assign_task'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_boards_manage'); }
        ]);

        // Stats
        register_rest_route('openclaw/v1', '/project/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_stats'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('project_tasks_read'); }
        ]);
    }

    // === PROJECTS ===

    public static function list_projects($request) {
        $status = $request->get_param('status');

        $args = [
            'post_type' => self::$project_type,
            'posts_per_page' => 50,
            'post_status' => 'publish'
        ];

        if ($status) {
            $args['meta_query'] = [[
                'key' => '_fproject_status',
                'value' => $status
            ]];
        }

        $projects = array_map([__CLASS__, 'format_project'], get_posts($args));

        return new WP_REST_Response($projects, 200);
    }

    public static function format_project($post) {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'description' => $post->post_content,
            'status' => get_post_meta($post->ID, '_fproject_status', true) ?: 'active',
            'progress' => (int)(get_post_meta($post->ID, '_fproject_progress', true) ?: 0),
            'priority' => get_post_meta($post->ID, '_fproject_priority', true) ?: 'normal',
            'due_date' => get_post_meta($post->ID, '_fproject_due', true),
            'start_date' => get_post_meta($post->ID, '_fproject_start', true),
            'members' => get_post_meta($post->ID, '_fproject_members', true) ?: [],
            'created' => $post->post_date,
            'modified' => $post->post_modified
        ];
    }

    public static function get_project($request) {
        $project = get_post($request->get_param('id'));

        if (!$project || $project->post_type !== self::$project_type) {
            return new WP_REST_Response(['error' => 'Project not found'], 404);
        }

        // Get project tasks
        $tasks = get_posts([
            'post_type' => self::$task_type,
            'posts_per_page' => -1,
            'meta_key' => '_ftask_project',
            'meta_value' => $project->ID,
            'post_status' => 'publish'
        ]);

        $data = self::format_project($project);
        $data['tasks'] = array_map([__CLASS__, 'format_task'], $tasks);
        $data['task_count'] = count($tasks);
        $data['completed_tasks'] = count(array_filter($tasks, function($t) {
            return get_post_meta($t->ID, '_ftask_completed', true);
        }));

        return new WP_REST_Response($data, 200);
    }

    public static function create_project($request) {
        $data = $request->get_json_params();

        $title = sanitize_text_field($data['title'] ?? '');
        if (empty($title)) {
            return new WP_REST_Response(['error' => 'Project title is required'], 400);
        }

        $allowed_priorities = ['low', 'normal', 'high', 'urgent'];
        $allowed_statuses = ['active', 'on-hold', 'completed', 'cancelled'];

        $priority = sanitize_text_field($data['priority'] ?? 'normal');
        if (!in_array($priority, $allowed_priorities, true)) {
            $priority = 'normal';
        }

        $status = sanitize_text_field($data['status'] ?? 'active');
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'active';
        }

        $project_id = wp_insert_post([
            'post_type' => self::$project_type,
            'post_title' => $title,
            'post_content' => wp_kses_post($data['description'] ?? ''),
            'post_status' => 'publish'
        ]);

        if (is_wp_error($project_id)) {
            return new WP_REST_Response(['error' => $project_id->get_error_message()], 400);
        }

        update_post_meta($project_id, '_fproject_status', $status);
        update_post_meta($project_id, '_fproject_priority', $priority);
        update_post_meta($project_id, '_fproject_due', sanitize_text_field($data['due_date'] ?? ''));
        update_post_meta($project_id, '_fproject_start', sanitize_text_field($data['start_date'] ?? ''));
        update_post_meta($project_id, '_fproject_members', array_map('intval', (array)($data['members'] ?? [])));

        return new WP_REST_Response(self::format_project(get_post($project_id)), 201);
    }

    public static function update_project($request) {
        $project_id = $request->get_param('id');
        $data = $request->get_json_params();

        // Validate status and priority
        $allowed_statuses = ['pending', 'active', 'completed', 'on-hold', 'cancelled'];
        $allowed_priorities = ['low', 'medium', 'high', 'urgent'];
        
        if (isset($data['status']) && !in_array($data['status'], $allowed_statuses, true)) {
            return new WP_REST_Response(['error' => 'Invalid status. Allowed: ' . implode(', ', $allowed_statuses)], 400);
        }
        if (isset($data['priority']) && !in_array($data['priority'], $allowed_priorities, true)) {
            return new WP_REST_Response(['error' => 'Invalid priority. Allowed: ' . implode(', ', $allowed_priorities)], 400);
        }

        wp_update_post([
            'ID' => $project_id,
            'post_title' => isset($data['title']) ? sanitize_text_field($data['title']) : null,
            'post_content' => isset($data['description']) ? sanitize_textarea_field($data['description']) : null
        ]);

        foreach (['status', 'priority', 'due_date', 'start_date', 'progress'] as $key) {
            if (isset($data[$key])) {
                $value = sanitize_text_field($data[$key]);
                update_post_meta($project_id, "_fproject_{$key}", $value);
            }
        }
        
        // Validate members are valid user IDs
        if (isset($data['members'])) {
            $members = array_map('intval', (array)$data['members']);
            $valid_members = array_filter($members, function($id) { return $id > 0 && get_user_by('id', $id); });
            update_post_meta($project_id, "_fproject_members", $valid_members);
        }

        return new WP_REST_Response(self::format_project(get_post($project_id)), 200);
    }

    // === TASKS ===

    public static function list_tasks($request) {
        $project_id = $request->get_param('project_id');
        $assignee_id = $request->get_param('assignee_id');
        $status = $request->get_param('status');
        $priority = $request->get_param('priority');

        $args = [
            'post_type' => self::$task_type,
            'posts_per_page' => 100,
            'post_status' => 'publish'
        ];

        $meta_query = [];
        if ($project_id) $meta_query[] = ['key' => '_ftask_project', 'value' => $project_id];
        if ($status) $meta_query[] = ['key' => '_ftask_status', 'value' => $status];
        if ($priority) $meta_query[] = ['key' => '_ftask_priority', 'value' => $priority];
        if ($assignee_id) $meta_query[] = ['key' => '_ftask_assignees', 'value' => $assignee_id, 'compare' => 'LIKE'];

        if ($meta_query) $args['meta_query'] = $meta_query;

        $tasks = array_map([__CLASS__, 'format_task'], get_posts($args));

        return new WP_REST_Response($tasks, 200);
    }

    public static function format_task($post) {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'description' => $post->post_content,
            'project_id' => get_post_meta($post->ID, '_ftask_project', true),
            'status' => get_post_meta($post->ID, '_ftask_status', true) ?: 'open',
            'priority' => get_post_meta($post->ID, '_ftask_priority', true) ?: 'normal',
            'board_id' => get_post_meta($post->ID, '_ftask_board', true),
            'assignees' => get_post_meta($post->ID, '_ftask_assignees', true) ?: [],
            'due_date' => get_post_meta($post->ID, '_ftask_due', true),
            'completed' => (bool)get_post_meta($post->ID, '_ftask_completed', true),
            'completed_at' => get_post_meta($post->ID, '_ftask_completed_at', true),
            'created' => $post->post_date,
            'modified' => $post->post_modified
        ];
    }

    public static function get_task($request) {
        $task = get_post($request->get_param('id'));

        if (!$task || $task->post_type !== self::$task_type) {
            return new WP_REST_Response(['error' => 'Task not found'], 404);
        }

        return new WP_REST_Response(self::format_task($task), 200);
    }

    public static function create_task($request) {
        $data = $request->get_json_params();

        $title = sanitize_text_field($data['title'] ?? '');
        if (empty($title)) {
            return new WP_REST_Response(['error' => 'Task title is required'], 400);
        }

        $allowed_priorities = ['low', 'normal', 'high', 'urgent'];
        $allowed_statuses = ['open', 'in-progress', 'blocked', 'completed', 'closed'];

        $priority = sanitize_text_field($data['priority'] ?? 'normal');
        if (!in_array($priority, $allowed_priorities, true)) {
            $priority = 'normal';
        }

        $status = sanitize_text_field($data['status'] ?? 'open');
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'open';
        }

        $task_id = wp_insert_post([
            'post_type' => self::$task_type,
            'post_title' => $title,
            'post_content' => wp_kses_post($data['description'] ?? ''),
            'post_status' => 'publish'
        ]);

        if (is_wp_error($task_id)) {
            return new WP_REST_Response(['error' => $task_id->get_error_message()], 400);
        }

        update_post_meta($task_id, '_ftask_project', (int)($data['project_id'] ?? 0) ?: null);
        update_post_meta($task_id, '_ftask_status', $status);
        update_post_meta($task_id, '_ftask_priority', $priority);
        update_post_meta($task_id, '_ftask_board', (int)($data['board_id'] ?? 0) ?: null);
        update_post_meta($task_id, '_ftask_assignees', array_map('intval', (array)($data['assignees'] ?? [])));
        update_post_meta($task_id, '_ftask_due', sanitize_text_field($data['due_date'] ?? ''));

        return new WP_REST_Response(self::format_task(get_post($task_id)), 201);
    }

    public static function update_task($request) {
        $task_id = $request->get_param('id');
        $data = $request->get_json_params();

        // Validate status and priority
        $allowed_statuses = ['pending', 'in-progress', 'completed', 'on-hold'];
        $allowed_priorities = ['low', 'medium', 'high', 'urgent'];
        
        if (isset($data['status']) && !in_array($data['status'], $allowed_statuses, true)) {
            return new WP_REST_Response(['error' => 'Invalid status. Allowed: ' . implode(', ', $allowed_statuses)], 400);
        }
        if (isset($data['priority']) && !in_array($data['priority'], $allowed_priorities, true)) {
            return new WP_REST_Response(['error' => 'Invalid priority. Allowed: ' . implode(', ', $allowed_priorities)], 400);
        }

        wp_update_post([
            'ID' => $task_id,
            'post_title' => isset($data['title']) ? sanitize_text_field($data['title']) : null,
            'post_content' => isset($data['description']) ? sanitize_textarea_field($data['description']) : null
        ]);

        foreach (['status', 'priority', 'due_date', 'completed'] as $key) {
            if (isset($data[$key])) {
                $meta_key = "_ftask_{$key}";
                update_post_meta($task_id, $meta_key, sanitize_text_field($data[$key]));
            }
        }
        
        // Handle board_id
        if (isset($data['board_id'])) {
            update_post_meta($task_id, '_ftask_board', (int)$data['board_id']);
        }
        
        // Validate assignees are valid user IDs
        if (isset($data['assignees'])) {
            $assignees = array_map('intval', (array)$data['assignees']);
            $valid_assignees = array_filter($assignees, function($id) { return $id > 0 && get_user_by('id', $id); });
            update_post_meta($task_id, '_ftask_assignees', $valid_assignees);
        }

        // Handle completion
        if (!empty($data['completed'])) {
            update_post_meta($task_id, '_ftask_completed_at', current_time('mysql'));
        }

        return new WP_REST_Response(self::format_task(get_post($task_id)), 200);
    }

    public static function delete_task($request) {
        $task_id = $request->get_param('id');
        wp_delete_post($task_id, true);
        return new WP_REST_Response(['deleted' => true], 200);
    }

    // === BOARDS ===

    public static function list_boards($request) {
        $project_id = $request->get_param('project_id');

        $args = [
            'post_type' => self::$board_type,
            'posts_per_page' => 50,
            'post_status' => 'publish'
        ];

        $boards = array_map(function($board) {
            return [
                'id' => $board->ID,
                'title' => $board->post_title,
                'slug' => $board->post_name,
                'order' => (int)get_post_meta($board->ID, '_ftask_board_order', true) ?: 0
            ];
        }, get_posts($args));

        return new WP_REST_Response($boards, 200);
    }

    // === COMMENTS ===

    public static function create_comment($request) {
        $data = $request->get_json_params();
        $user_id = get_current_user_id() ?: 1;
        $user = get_user_by('ID', $user_id);
        
        // Validate task exists
        $task_id = (int)($data['task_id'] ?? 0);
        if (!$task_id || get_post_type($task_id) !== self::$task_type) {
            return new WP_REST_Response(['error' => 'Invalid task ID'], 400);
        }
        
        // Sanitize comment content
        $content = wp_kses_post($data['content'] ?? '');
        if (empty($content)) {
            return new WP_REST_Response(['error' => 'Comment content is required'], 400);
        }

        $comment_id = wp_insert_comment([
            'comment_post_ID' => $task_id,
            'comment_content' => $content,
            'comment_author' => $user->display_name ?? 'API',
            'comment_approved' => 1,
            'user_id' => $user_id,
            'comment_type' => 'ftask_comment'
        ]);

        return new WP_REST_Response([
            'id' => $comment_id,
            'task_id' => $task_id,
            'content' => $content,
            'author' => $user->display_name ?? 'API',
            'date' => current_time('mysql')
        ], 201);
    }

    // === ASSIGNMENTS ===

    public static function assign_task($request) {
        $data = $request->get_json_params();
        $task_id = (int)($data['task_id'] ?? 0);
        
        // Validate task exists
        if (!$task_id) {
            return new WP_REST_Response(['error' => 'Missing task_id'], 400);
        }
        $task = get_post($task_id);
        if (!$task || $task->post_type !== self::$task_type) {
            return new WP_REST_Response(['error' => 'Invalid task ID'], 400);
        }
        
        // Validate and filter user IDs
        $user_ids = array_filter(array_map('intval', (array)($data['user_ids'] ?? [])), function($id) {
            return $id > 0 && get_user_by('id', $id);
        });

        update_post_meta($task_id, '_ftask_assignees', $user_ids);

        return new WP_REST_Response([
            'task_id' => $task_id,
            'assignees' => $user_ids
        ], 200);
    }

    // === STATS ===

    public static function get_stats($request) {
        $projects = wp_count_posts(self::$project_type);
        $tasks = wp_count_posts(self::$task_type);

        $open_tasks = get_posts([
            'post_type' => self::$task_type,
            'meta_key' => '_ftask_completed',
            'meta_value' => false,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        return new WP_REST_Response([
            'total_projects' => $projects->publish,
            'total_tasks' => $tasks->publish,
            'open_tasks' => count($open_tasks),
            'completed_tasks' => $tasks->publish - count($open_tasks)
        ], 200);
    }

    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[OpenClaw FluentProject Module] {$message}");
        }
    }

    public static function is_active() {
        return self::$active;
    }
}

// Initialize
OpenClaw_FluentProject_Module::init();