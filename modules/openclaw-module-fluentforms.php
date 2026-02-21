<?php
/**
 * OpenClaw API - FluentForms Module
 * 
 * Auto-activates when FluentForms plugin is installed.
 * Provides REST API access to forms, entries, and submissions.
 */

if (!defined('ABSPATH')) exit;

class OpenClaw_FluentForms_Module {

    private static $active = false;

    public static function init() {
        // Check if plugin is active (runs at plugins_loaded)
        if (self::is_plugin_active()) {
            self::$active = true;
            add_action('rest_api_init', [__CLASS__, 'register_routes']);
            add_filter('openclaw_module_capabilities', [__CLASS__, 'register_capabilities']);
        }
    }
    
    private static function is_plugin_active() {
        return function_exists('openclaw_is_plugin_active') && openclaw_is_plugin_active('fluentforms');
    }
    
    /**
     * Register module capabilities with labels
     */
    public static function register_capabilities($caps) {
        return array_merge($caps, [
            // Read operations
            'forms_read' => ['label' => 'Read Forms', 'default' => true, 'group' => 'FluentForms'],
            'forms_entries_read' => ['label' => 'Read Entries', 'default' => true, 'group' => 'FluentForms'],
            'forms_submissions_read' => ['label' => 'Read Submissions', 'default' => true, 'group' => 'FluentForms'],
            // Write operations
            'forms_create' => ['label' => 'Create Forms', 'default' => false, 'group' => 'FluentForms'],
            'forms_update' => ['label' => 'Update Forms', 'default' => false, 'group' => 'FluentForms'],
            'forms_submit' => ['label' => 'Submit Form Entries', 'default' => true, 'group' => 'FluentForms'],
            'forms_entries_export' => ['label' => 'Export Entries', 'default' => false, 'group' => 'FluentForms'],
            // Manage operations
            'forms_delete' => ['label' => 'Delete Forms', 'default' => false, 'group' => 'FluentForms'],
            'forms_entries_delete' => ['label' => 'Delete Entries', 'default' => false, 'group' => 'FluentForms'],
        ]);
    }

    public static function register_routes() {
        // Forms
        register_rest_route('openclaw/v1', '/forms', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_forms'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_read'); },
        ]);
        register_rest_route('openclaw/v1', '/forms', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_form'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_create'); },
        ]);
        register_rest_route('openclaw/v1', '/forms/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_form'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_read'); },
        ]);
        register_rest_route('openclaw/v1', '/forms/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_form'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_update'); },
        ]);

        // Entries
        register_rest_route('openclaw/v1', '/forms/(?P<id>\d+)/entries', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_entries'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_read'); },
        ]);
        register_rest_route('openclaw/v1', '/forms/(?P<id>\d+)/entries', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'submit_entry'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_submit'); },
        ]);
        register_rest_route('openclaw/v1', '/entries/(?P<entry_id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_entry'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_read'); },
        ]);
        register_rest_route('openclaw/v1', '/entries/(?P<entry_id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_entry'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_entries_delete'); },
        ]);

        // Export
        register_rest_route('openclaw/v1', '/forms/(?P<id>\d+)/export', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'export_entries'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_read'); },
        ]);

        // Stats
        register_rest_route('openclaw/v1', '/forms/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_stats'],
            'permission_callback' => function() { return openclaw_verify_token_and_can('forms_read'); },
        ]);
    }

    // === FORMS ===

    public static function list_forms($request) {
        $forms = \FluentForm\App\Models\Form::orderBy('id', 'desc')->get();

        $data = $forms->map(function($form) {
            return [
                'id' => $form->id,
                'title' => $form->title,
                'status' => $form->status,
                'type' => $form->form_type ?? 'form',
                'entries_count' => \FluentForm\App\Models\Submission::where('form_id', $form->id)->count(),
                'created_at' => $form->created_at,
                'updated_at' => $form->updated_at
            ];
        });

        return new WP_REST_Response($data, 200);
    }

    public static function get_form($request) {
        $form = \FluentForm\App\Models\Form::find($request->get_param('id'));

        if (!$form) {
            return new WP_REST_Response(['error' => 'Form not found'], 404);
        }

        $fields = json_decode($form->form_fields, true);

        return new WP_REST_Response([
            'id' => $form->id,
            'title' => $form->title,
            'status' => $form->status,
            'type' => $form->form_type ?? 'form',
            'fields' => $fields,
            'settings' => json_decode($form->settings ?? '{}', true),
            'entries_count' => \FluentForm\App\Models\Submission::where('form_id', $form->id)->count(),
            'created_at' => $form->created_at,
            'updated_at' => $form->updated_at
        ], 200);
    }

    public static function create_form($request) {
        $title = $request->get_param('title');
        $fields = $request->get_param('fields');
        $status = $request->get_param('status') ?: 'published';

        if (empty($title)) {
            return new WP_REST_Response(['error' => 'Title is required'], 400);
        }

        $form_fields = is_array($fields) ? json_encode($fields) : $fields;

        $form = \FluentForm\App\Models\Form::create([
            'title' => $title,
            'form_fields' => $form_fields,
            'status' => $status,
            'created_by' => get_current_user_id() ?: 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        if (!$form) {
            return new WP_REST_Response(['error' => 'Failed to create form'], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'id' => $form->id,
            'title' => $form->title,
            'shortcode' => '[fluentform id="' . $form->id . '"]'
        ], 201);
    }

    public static function update_form($request) {
        $form = \FluentForm\App\Models\Form::find($request->get_param('id'));

        if (!$form) {
            return new WP_REST_Response(['error' => 'Form not found'], 404);
        }

        $title = $request->get_param('title');
        $fields = $request->get_param('fields');
        $status = $request->get_param('status');

        if ($title) $form->title = $title;
        if ($fields) $form->form_fields = is_array($fields) ? json_encode($fields) : $fields;
        if ($status) $form->status = $status;

        $form->updated_at = current_time('mysql');
        $form->save();

        return new WP_REST_Response(['success' => true, 'id' => $form->id], 200);
    }

    // === ENTRIES ===

    public static function list_entries($request) {
        $form_id = $request->get_param('id');
        $page = (int)($request->get_param('page') ?: 1);
        $per_page = (int)($request->get_param('per_page') ?: 20);
        $status = $request->get_param('status');

        $query = \FluentForm\App\Models\Submission::where('form_id', $form_id)
            ->orderBy('id', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $entries = $query->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get();

        $data = $entries->map(function($entry) {
            return self::format_entry($entry);
        });

        return new WP_REST_Response([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'pages' => ceil($total / $per_page)
            ]
        ], 200);
    }

    public static function format_entry($entry) {
        $response = json_decode($entry->response, true) ?: [];

        return [
            'id' => $entry->id,
            'form_id' => $entry->form_id,
            'user_id' => $entry->user_id,
            'status' => $entry->status,
            'source_url' => $entry->source_url,
            'ip' => $entry->ip,
            'browser' => $entry->browser,
            'response' => $response,
            'created_at' => $entry->created_at,
            'updated_at' => $entry->updated_at
        ];
    }

    public static function get_entry($request) {
        $entry = \FluentForm\App\Models\Submission::find($request->get_param('entry_id'));

        if (!$entry) {
            return new WP_REST_Response(['error' => 'Entry not found'], 404);
        }

        return new WP_REST_Response(self::format_entry($entry), 200);
    }

    public static function submit_entry($request) {
        $form_id = $request->get_param('id');
        $data = $request->get_json_params();

        $form = \FluentForm\App\Models\Form::find($form_id);
        if (!$form) {
            return new WP_REST_Response(['error' => 'Form not found'], 404);
        }

        // Use FluentForms submission handler
        $submission = \FluentForm\App\Models\Submission::create([
            'form_id' => $form_id,
            'user_id' => get_current_user_id() ?: 0,
            'response' => json_encode($data),
            'status' => 'unread',
            'source_url' => $request->get_header('referer') ?: '',
            'ip' => self::get_client_ip(),
            'browser' => $request->get_header('user_agent') ?: '',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        // Trigger FluentForms hooks
        do_action('fluentform/submission_inserted', $submission, $form, $data);

        return new WP_REST_Response([
            'success' => true,
            'entry_id' => $submission->id,
            'message' => 'Form submitted successfully'
        ], 201);
    }

    public static function delete_entry($request) {
        $entry = \FluentForm\App\Models\Submission::find($request->get_param('entry_id'));

        if (!$entry) {
            return new WP_REST_Response(['error' => 'Entry not found'], 404);
        }

        $entry->delete();

        return new WP_REST_Response(['deleted' => true], 200);
    }

    // === EXPORT ===

    public static function export_entries($request) {
        $form_id = $request->get_param('id');
        $format = $request->get_param('format') ?: 'json';

        $entries = \FluentForm\App\Models\Submission::where('form_id', $form_id)->get();
        $data = $entries->map(function($entry) {
            return self::format_entry($entry);
        });

        if ($format === 'csv') {
            $csv = self::to_csv($data->toArray());
            return new WP_REST_Response([
                'format' => 'csv',
                'data' => $csv
            ], 200);
        }

        return new WP_REST_Response([
            'format' => 'json',
            'data' => $data
        ], 200);
    }

    private static function to_csv($data) {
        if (empty($data)) return '';

        $headers = array_keys($data[0]);
        $lines = [implode(',', $headers)];

        foreach ($data as $row) {
            $values = array_map(function($v) {
                if (is_array($v)) {
                    $v = json_encode($v);
                }
                // Escape CSV injection characters
                $v = (string)$v;
                if (preg_match('/^[=+\-@\t]/', $v)) {
                    $v = "'" . $v;  // Prefix with single quote to neutralize formulas
                }
                return '"' . str_replace('"', '""', $v) . '"';
            }, $row);
            $lines[] = implode(',', $values);
        }

        return implode("\n", $lines);
    }

    // === STATS ===

    public static function get_stats($request) {
        $forms_count = \FluentForm\App\Models\Form::count();
        $entries_count = \FluentForm\App\Models\Submission::count();
        $unread_count = \FluentForm\App\Models\Submission::where('status', 'unread')->count();
        $read_count = \FluentForm\App\Models\Submission::where('status', 'read')->count();

        // Get top forms by entries
        $top_forms = \FluentForm\App\Models\Form::withCount('submissions')
            ->orderBy('submissions_count', 'desc')
            ->take(5)
            ->get(['id', 'title']);

        return new WP_REST_Response([
            'total_forms' => $forms_count,
            'total_entries' => $entries_count,
            'unread_entries' => $unread_count,
            'read_entries' => $read_count,
            'top_forms' => $top_forms
        ], 200);
    }

    private static function get_client_ip() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }

    public static function is_active() {
        return self::$active;
    }
}

// Initialize module
OpenClaw_FluentForms_Module::init();

// Register FluentForms capabilities with defaults
add_filter('openclaw_default_capabilities', function($caps) {
    return array_merge($caps, [
        'forms_read' => true,
        'forms_submit' => true,
        'forms_delete' => false,
    ]);
});