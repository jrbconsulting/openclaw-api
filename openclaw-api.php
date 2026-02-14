<?php
/**
 * Plugin Name: OpenClaw API
 * Description: WordPress REST API for OpenClaw remote site management
 * Version: 2.1.0
 * Author: OpenClaw
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function() {
    
    // Health check (no auth)
    register_rest_route('openclaw/v1', '/ping', [
        'methods' => 'GET',
        'callback' => 'openclaw_ping',
        'permission_callback' => '__return_true',
    ]);
    
    // Site info
    register_rest_route('openclaw/v1', '/site', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_site',
        'permission_callback' => function() { return openclaw_verify_token_and_can('site_info'); },
    ]);
    
    // Posts
    register_rest_route('openclaw/v1', '/posts', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_posts',
        'permission_callback' => function() { return openclaw_verify_token_and_can('posts_read'); },
    ]);
    
    register_rest_route('openclaw/v1', '/posts', [
        'methods' => 'POST',
        'callback' => 'openclaw_create_post',
        'permission_callback' => function() { return openclaw_verify_token_and_can('posts_create'); },
    ]);
    
    register_rest_route('openclaw/v1', '/posts/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'openclaw_update_post',
        'permission_callback' => function() { return openclaw_verify_token_and_can('posts_update'); },
    ]);
    
    register_rest_route('openclaw/v1', '/posts/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'openclaw_delete_post',
        'permission_callback' => function() { return openclaw_verify_token_and_can('posts_delete'); },
    ]);
    
    // Categories
    register_rest_route('openclaw/v1', '/categories', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_categories',
        'permission_callback' => function() { return openclaw_verify_token_and_can('categories_read'); },
    ]);
    
    register_rest_route('openclaw/v1', '/categories', [
        'methods' => 'POST',
        'callback' => 'openclaw_create_category',
        'permission_callback' => function() { return openclaw_verify_token_and_can('categories_create'); },
    ]);
    
    // Tags
    register_rest_route('openclaw/v1', '/tags', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_tags',
        'permission_callback' => function() { return openclaw_verify_token_and_can('tags_read'); },
    ]);
    
    register_rest_route('openclaw/v1', '/tags', [
        'methods' => 'POST',
        'callback' => 'openclaw_create_tag',
        'permission_callback' => function() { return openclaw_verify_token_and_can('tags_create'); },
    ]);
    
    // Media
    register_rest_route('openclaw/v1', '/media', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_media',
        'permission_callback' => function() { return openclaw_verify_token_and_can('media_read'); },
    ]);
    
    register_rest_route('openclaw/v1', '/media', [
        'methods' => 'POST',
        'callback' => 'openclaw_upload_media',
        'permission_callback' => function() { return openclaw_verify_token_and_can('media_upload'); },
    ]);
    
    register_rest_route('openclaw/v1', '/media/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'openclaw_delete_media',
        'permission_callback' => function() { return openclaw_verify_token_and_can('media_delete'); },
    ]);
    
    // Pages
    register_rest_route('openclaw/v1', '/pages', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_pages',
        'permission_callback' => function() { return openclaw_verify_token_and_can('pages_read'); },
    ]);
    
    register_rest_route('openclaw/v1', '/pages', [
        'methods' => 'POST',
        'callback' => 'openclaw_create_page',
        'permission_callback' => function() { return openclaw_verify_token_and_can('pages_create'); },
    ]);
    
    // Users
    register_rest_route('openclaw/v1', '/users', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_users',
        'permission_callback' => function() { return openclaw_verify_token_and_can('users_read'); },
    ]);
    
    // Plugins
    register_rest_route('openclaw/v1', '/plugins', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_plugins',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_read'); },
    ]);
    
    register_rest_route('openclaw/v1', '/plugins/install', [
        'methods' => 'POST',
        'callback' => 'openclaw_install_plugin',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_install'); },
    ]);
    
    register_rest_route('openclaw/v1', '/plugins/(?P<slug>[^/]+)/activate', [
        'methods' => 'POST',
        'callback' => 'openclaw_activate_plugin',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_activate'); },
    ]);
    
    register_rest_route('openclaw/v1', '/plugins/(?P<slug>[^/]+)/deactivate', [
        'methods' => 'POST',
        'callback' => 'openclaw_deactivate_plugin',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_deactivate'); },
    ]);
    
    register_rest_route('openclaw/v1', '/plugins/(?P<slug>[^/]+)/update', [
        'methods' => 'POST',
        'callback' => 'openclaw_update_plugin',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_update'); },
    ]);
    
    register_rest_route('openclaw/v1', '/plugins/(?P<slug>[^/]+)', [
        'methods' => 'DELETE',
        'callback' => 'openclaw_delete_plugin',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_delete'); },
    ]);
    
    // Plugin search (WordPress.org)
    register_rest_route('openclaw/v1', '/plugins/search', [
        'methods' => 'GET',
        'callback' => 'openclaw_search_plugins',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_search'); },
    ]);
});

/**
 * Verify API token from X-OpenClaw-Token header
 * Uses timing-safe comparison and hashed token storage
 * Supports legacy plaintext tokens for migration
 */
function openclaw_verify_token() {
    $header = isset($_SERVER['HTTP_X_OPENCLAW_TOKEN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_OPENCLAW_TOKEN'])) : '';
    
    if (empty($header)) {
        return new WP_Error('missing_header', 'Missing X-OpenClaw-Token header', ['status' => 401]);
    }
    
    // Check for hashed token first (preferred)
    $token_hash = get_option('openclaw_api_token_hash');
    if (!empty($token_hash)) {
        $header_hash = wp_hash($header);
        if (hash_equals($token_hash, $header_hash)) {
            return true;
        }
    }
    
    // Fallback to legacy plaintext token (for migration)
    $legacy_token = get_option('openclaw_api_token');
    if (!empty($legacy_token)) {
        if (hash_equals($legacy_token, $header)) {
            // Migrate to hashed storage
            update_option('openclaw_api_token_hash', wp_hash($legacy_token));
            delete_option('openclaw_api_token');
            return true;
        }
    }
    
    if (empty($token_hash) && empty($legacy_token)) {
        return new WP_Error('no_token', 'API token not configured. Go to Settings → OpenClaw API.', ['status' => 500]);
    }
    
    return new WP_Error('invalid_token', 'Invalid API token', ['status' => 401]);
}

// Ping endpoint
function openclaw_ping() {
    return ['status' => 'ok', 'version' => '2.1.0', 'time' => current_time('mysql')];
}

// Site info
function openclaw_get_site() {
    return [
        'name' => get_bloginfo('name'),
        'description' => get_bloginfo('description'),
        'url' => get_bloginfo('url'),
        'version' => get_bloginfo('version'),
        'posts_count' => (int) wp_count_posts()->publish,
        'pages_count' => (int) wp_count_posts('page')->publish,
    ];
}

// Get posts
function openclaw_get_posts($request) {
    $args = [
        'post_type' => 'post',
        'post_status' => 'any',
        'posts_per_page' => min((int) ($request->get_param('per_page') ?: 50), 100),
        'paged' => max((int) ($request->get_param('page') ?: 1), 1),
    ];
    if ($search = $request->get_param('search')) {
        $args['s'] = substr(sanitize_text_field($search), 0, 200);
    }
    $posts = get_posts($args);
    return array_map('openclaw_format_post', $posts);
}

// Create post
function openclaw_create_post($request) {
    $data = $request->get_json_params();
    
    // Validate post status
    $allowed_statuses = ['draft', 'pending', 'private', 'publish'];
    $status = sanitize_text_field($data['status'] ?? 'draft');
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'draft';
    }
    
    // Validate author ID if provided
    $author_id = (int) ($data['author_id'] ?? 1);
    if ($author_id > 1) {
        $user = get_user_by('id', $author_id);
        if (!$user) {
            return new WP_Error('invalid_author', 'Invalid author ID', ['status' => 400]);
        }
    }
    
    $post_data = [
        'post_title' => sanitize_text_field($data['title'] ?? 'Untitled'),
        'post_content' => $data['content'] ?? '',
        'post_status' => $status,
        'post_author' => $author_id,
    ];
    if (!empty($data['categories'])) {
        $post_data['post_category'] = array_map('intval', (array) $data['categories']);
    }
    if (!empty($data['tags'])) {
        $post_data['tags_input'] = array_map('intval', (array) $data['tags']);
    }
    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }
    return openclaw_format_post(get_post($post_id));
}

// Update post
function openclaw_update_post($request) {
    $id = (int) $request['id'];
    
    // Verify post exists and is correct type
    $post = get_post($id);
    if (!$post) {
        return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
    }
    if ($post->post_type !== 'post') {
        return new WP_Error('invalid_post_type', 'This endpoint only works with posts, not pages or other types', ['status' => 400]);
    }
    
    $data = $request->get_json_params();
    $post_data = ['ID' => $id];
    
    // Validate and sanitize fields
    if (isset($data['title'])) {
        $post_data['post_title'] = sanitize_text_field($data['title']);
    }
    if (isset($data['content'])) {
        $post_data['post_content'] = $data['content'];
    }
    if (isset($data['status'])) {
        $allowed_statuses = ['draft', 'pending', 'private', 'publish'];
        $status = sanitize_text_field($data['status']);
        if (!in_array($status, $allowed_statuses, true)) {
            return new WP_Error('invalid_status', 'Invalid post status', ['status' => 400]);
        }
        $post_data['post_status'] = $status;
    }
    if (isset($data['categories'])) {
        $post_data['post_category'] = array_map('intval', (array) $data['categories']);
    }
    if (isset($data['tags'])) {
        $post_data['tags_input'] = array_map('intval', (array) $data['tags']);
    }
    
    $result = wp_update_post($post_data, true);
    if (is_wp_error($result)) return $result;
    return openclaw_format_post(get_post($id));
}

// Delete post
function openclaw_delete_post($request) {
    $id = (int) $request['id'];
    
    // Verify post exists and is correct type
    $post = get_post($id);
    if (!$post) {
        return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
    }
    if ($post->post_type !== 'post') {
        return new WP_Error('invalid_post_type', 'This endpoint only works with posts, not pages or other types', ['status' => 400]);
    }
    
    $force = $request->get_param('force') === 'true';
    $result = wp_delete_post($id, $force);
    if (!$result) {
        return new WP_Error('delete_failed', 'Failed to delete post', ['status' => 500]);
    }
    return ['deleted' => true, 'id' => $id];
}

// Format post
function openclaw_format_post($post) {
    if (!$post) {
        return null;
    }
    return [
        'id' => $post->ID,
        'title' => $post->post_title,
        'slug' => $post->post_name,
        'content' => $post->post_content,
        'status' => $post->post_status,
        'date' => $post->post_date,
        'author_id' => $post->post_author,
        'categories' => wp_get_post_categories($post->ID, ['fields' => 'ids']),
        'tags' => wp_get_post_tags($post->ID, ['fields' => 'ids']),
        'link' => get_permalink($post->ID),
    ];
}

// Categories
function openclaw_get_categories() {
    $cats = get_categories(['hide_empty' => false]);
    return array_map(function($c) {
        return ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug];
    }, $cats);
}

function openclaw_create_category($request) {
    $data = $request->get_json_params();
    $name = sanitize_text_field($data['name'] ?? '');
    
    if (empty($name)) {
        return new WP_Error('missing_name', 'Category name is required', ['status' => 400]);
    }
    
    $result = wp_insert_term($name, 'category');
    if (is_wp_error($result)) return $result;
    $cat = get_term($result['term_id'], 'category');
    return ['id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug];
}

// Tags
function openclaw_get_tags() {
    $tags = get_tags(['hide_empty' => false]);
    return array_map(function($t) {
        return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug];
    }, $tags);
}

function openclaw_create_tag($request) {
    $data = $request->get_json_params();
    $name = sanitize_text_field($data['name'] ?? '');
    
    if (empty($name)) {
        return new WP_Error('missing_name', 'Tag name is required', ['status' => 400]);
    }
    
    $result = wp_insert_term($name, 'post_tag');
    if (is_wp_error($result)) return $result;
    $tag = get_term($result['term_id'], 'post_tag');
    return ['id' => $tag->term_id, 'name' => $tag->name, 'slug' => $tag->slug];
}

// Media
function openclaw_get_media($request) {
    $media = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 50]);
    return array_map(function($m) {
        return ['id' => $m->ID, 'title' => $m->post_title, 'url' => wp_get_attachment_url($m->ID)];
    }, $media);
}

// Upload media
function openclaw_upload_media($request) {
    // Check if file was uploaded
    if (empty($_FILES['file'])) {
        return new WP_Error('missing_file', 'No file uploaded. Use multipart/form-data with a "file" field.', ['status' => 400]);
    }
    
    $file = $_FILES['file'];
    
    // Validate file upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];
        $msg = $error_messages[$file['error']] ?? 'Unknown upload error';
        return new WP_Error('upload_error', $msg, ['status' => 400]);
    }
    
    // Validate file type (images only for security)
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types, true)) {
        return new WP_Error('invalid_type', 'Only image files are allowed (JPEG, PNG, GIF, WebP, SVG)', ['status' => 400]);
    }
    
    // Validate file size (max 10MB)
    $max_size = 10 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return new WP_Error('file_too_large', 'File exceeds 10MB limit', ['status' => 400]);
    }
    
    // Sanitize filename
    $filename = sanitize_file_name($file['name']);
    
    // Prepare for WordPress upload
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    
    // Use WordPress file upload handler
    $upload = wp_handle_upload($file, ['test_form' => false]);
    
    if (isset($upload['error'])) {
        return new WP_Error('upload_failed', $upload['error'], ['status' => 500]);
    }
    
    // Create attachment
    $attachment = [
        'post_mime_type' => $upload['type'],
        'post_title' => sanitize_text_field($request->get_param('title') ?: pathinfo($filename, PATHINFO_FILENAME)),
        'post_content' => '',
        'post_status' => 'inherit',
    ];
    
    $attach_id = wp_insert_attachment($attachment, $upload['file']);
    
    if (is_wp_error($attach_id)) {
        return new WP_Error('attachment_failed', $attach_id->get_error_message(), ['status' => 500]);
    }
    
    // Generate metadata for images (thumbnails, etc.)
    if (strpos($upload['type'], 'image/') === 0 && $upload['type'] !== 'image/svg+xml') {
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
    }
    
    // Get the URL
    $url = wp_get_attachment_url($attach_id);
    
    return [
        'success' => true,
        'id' => $attach_id,
        'title' => get_the_title($attach_id),
        'url' => $url,
        'mime_type' => $upload['type'],
        'size' => $file['size'],
    ];
}

// Delete media
function openclaw_delete_media($request) {
    $id = (int) $request['id'];
    
    $attachment = get_post($id);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return new WP_Error('not_found', 'Media not found', ['status' => 404]);
    }
    
    $deleted = wp_delete_attachment($id, true); // true = force delete (skip trash)
    
    if (!$deleted) {
        return new WP_Error('delete_failed', 'Failed to delete media', ['status' => 500]);
    }
    
    return ['success' => true, 'id' => $id];
}

// Pages
function openclaw_get_pages() {
    $pages = get_pages();
    return array_map(function($p) {
        return ['id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'status' => $p->post_status];
    }, $pages);
}

function openclaw_create_page($request) {
    $data = $request->get_json_params();
    $page_id = wp_insert_post([
        'post_type' => 'page',
        'post_title' => sanitize_text_field($data['title'] ?? 'Untitled'),
        'post_content' => $data['content'] ?? '',
        'post_status' => sanitize_text_field($data['status'] ?? 'draft'),
    ], true);
    if (is_wp_error($page_id)) return $page_id;
    return ['id' => $page_id, 'title' => $data['title'] ?? 'Untitled', 'link' => get_permalink($page_id)];
}

// Users
function openclaw_get_users() {
    $users = get_users();
    return array_map(function($u) {
        // Email excluded for privacy - only return necessary info
        return [
            'id' => $u->ID,
            'username' => $u->user_login,
            'display_name' => $u->display_name,
            'roles' => $u->roles,
        ];
    }, $users);
}

// ===== PLUGIN MANAGEMENT =====

// Validate plugin slug (lowercase alphanumeric with hyphens)
function openclaw_validate_plugin_slug($slug) {
    $slug = sanitize_key($slug);
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        return false;
    }
    return $slug;
}

// List installed plugins
function openclaw_get_plugins() {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $plugins = get_plugins();
    $active = get_option('active_plugins', []);
    $result = [];
    
    foreach ($plugins as $path => $plugin) {
        $slug = dirname($path);
        $result[] = [
            'name' => $plugin['Name'],
            'slug' => $slug,
            'path' => $path,
            'version' => $plugin['Version'],
            'active' => in_array($path, $active),
            'description' => $plugin['Description'] ?? '',
            'author' => $plugin['AuthorName'] ?? $plugin['Author'] ?? '',
        ];
    }
    
    return $result;
}

// Search WordPress.org for plugins
function openclaw_search_plugins($request) {
    if (!function_exists('plugins_api')) {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    }
    
    $search = sanitize_text_field($request->get_param('q') ?: $request->get_param('search'));
    $page = max((int) ($request->get_param('page') ?: 1), 1);
    $per_page = min((int) ($request->get_param('per_page') ?: 20), 100);
    
    // Limit search query length
    $search = substr($search, 0, 200);
    
    $args = [
        'search' => $search,
        'page' => $page,
        'per_page' => $per_page,
        'fields' => [
            'short_description' => true,
            'downloads' => true,
            'active_installs' => true,
            'rating' => true,
            'sections' => false,
        ],
    ];
    
    $api = plugins_api('query_plugins', $args);
    
    if (is_wp_error($api)) {
        return new WP_Error('search_failed', $api->get_error_message(), ['status' => 500]);
    }
    
    return [
        'total' => $api->info['results'] ?? 0,
        'page' => $page,
        'plugins' => array_map(function($p) {
            return [
                'name' => $p->name,
                'slug' => $p->slug,
                'version' => $p->version,
                'rating' => $p->rating ?? 0,
                'active_installs' => $p->active_installs ?? 0,
                'downloaded' => $p->downloaded ?? 0,
                'short_description' => $p->short_description ?? '',
                'author' => $p->author ?? '',
            ];
        }, $api->plugins),
    ];
}

// Install plugin from WordPress.org
function openclaw_install_plugin($request) {
    $data = $request->get_json_params();
    $slug = openclaw_validate_plugin_slug($data['slug'] ?? '');
    $activate = !empty($data['activate']);
    
    if (empty($slug)) {
        return new WP_Error('invalid_slug', 'Invalid plugin slug. Must be lowercase alphanumeric with hyphens.', ['status' => 400]);
    }
    
    // Include required files
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    
    // Get plugin info
    $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
    if (is_wp_error($api)) {
        return new WP_Error('plugin_not_found', 'Plugin not found on WordPress.org: ' . $slug, ['status' => 404]);
    }
    
    // Check if already installed
    $installed = get_plugins();
    foreach ($installed as $path => $plugin) {
        if (dirname($path) === $slug) {
            // Already installed
            $active = in_array($path, get_option('active_plugins', []));
            return [
                'success' => true,
                'status' => 'already_installed',
                'name' => $plugin['Name'],
                'slug' => $slug,
                'version' => $plugin['Version'],
                'active' => $active,
            ];
        }
    }
    
    // Install
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->install($api->download_link);
    
    if (is_wp_error($result)) {
        return new WP_Error('install_failed', $result->get_error_message(), ['status' => 500]);
    }
    
    if (!$result) {
        return new WP_Error('install_failed', 'Plugin installation failed', ['status' => 500]);
    }
    
    // Find the installed plugin path
    $installed = get_plugins();
    $plugin_path = null;
    foreach ($installed as $path => $plugin) {
        if (dirname($path) === $slug) {
            $plugin_path = $path;
            break;
        }
    }
    
    if (!$plugin_path) {
        return new WP_Error('path_not_found', 'Plugin installed but path not found', ['status' => 500]);
    }
    
    $response = [
        'success' => true,
        'status' => 'installed',
        'name' => $installed[$plugin_path]['Name'],
        'slug' => $slug,
        'version' => $installed[$plugin_path]['Version'],
        'active' => false,
    ];
    
    // Activate if requested
    if ($activate) {
        $activate_result = activate_plugin($plugin_path);
        if (!is_wp_error($activate_result)) {
            $response['active'] = true;
            $response['status'] = 'installed_and_activated';
        }
    }
    
    return $response;
}

// Activate plugin
function openclaw_activate_plugin($request) {
    $slug = openclaw_validate_plugin_slug($request['slug']);
    
    if (empty($slug)) {
        return new WP_Error('invalid_slug', 'Invalid plugin slug', ['status' => 400]);
    }
    
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    // Find plugin by slug
    $plugins = get_plugins();
    $plugin_path = null;
    foreach ($plugins as $path => $plugin) {
        if (dirname($path) === $slug) {
            $plugin_path = $path;
            break;
        }
    }
    
    if (!$plugin_path) {
        return new WP_Error('plugin_not_found', 'Plugin not installed: ' . $slug, ['status' => 404]);
    }
    
    $result = activate_plugin($plugin_path);
    
    if (is_wp_error($result)) {
        return new WP_Error('activation_failed', $result->get_error_message(), ['status' => 500]);
    }
    
    return [
        'success' => true,
        'status' => 'activated',
        'slug' => $slug,
        'name' => $plugins[$plugin_path]['Name'],
    ];
}

// Deactivate plugin
function openclaw_deactivate_plugin($request) {
    $slug = openclaw_validate_plugin_slug($request['slug']);
    
    if (empty($slug)) {
        return new WP_Error('invalid_slug', 'Invalid plugin slug', ['status' => 400]);
    }
    
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $plugins = get_plugins();
    $plugin_path = null;
    foreach ($plugins as $path => $plugin) {
        if (dirname($path) === $slug) {
            $plugin_path = $path;
            break;
        }
    }
    
    if (!$plugin_path) {
        return new WP_Error('plugin_not_found', 'Plugin not installed: ' . $slug, ['status' => 404]);
    }
    
    deactivate_plugins($plugin_path);
    
    return [
        'success' => true,
        'status' => 'deactivated',
        'slug' => $slug,
        'name' => $plugins[$plugin_path]['Name'],
    ];
}

// Update plugin
function openclaw_update_plugin($request) {
    $slug = openclaw_validate_plugin_slug($request['slug']);
    
    if (empty($slug)) {
        return new WP_Error('invalid_slug', 'Invalid plugin slug', ['status' => 400]);
    }
    
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    
    $plugins = get_plugins();
    $plugin_path = null;
    foreach ($plugins as $path => $plugin) {
        if (dirname($path) === $slug) {
            $plugin_path = $path;
            break;
        }
    }
    
    if (!$plugin_path) {
        return new WP_Error('plugin_not_found', 'Plugin not installed: ' . $slug, ['status' => 404]);
    }
    
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->upgrade($plugin_path);
    
    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }
    
    // Get updated plugin info
    $plugins = get_plugins();
    
    return [
        'success' => true,
        'status' => 'updated',
        'slug' => $slug,
        'name' => $plugins[$plugin_path]['Name'],
        'version' => $plugins[$plugin_path]['Version'],
    ];
}

// Delete plugin
function openclaw_delete_plugin($request) {
    $slug = openclaw_validate_plugin_slug($request['slug']);
    
    if (empty($slug)) {
        return new WP_Error('invalid_slug', 'Invalid plugin slug', ['status' => 400]);
    }
    
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    
    $plugins = get_plugins();
    $plugin_path = null;
    foreach ($plugins as $path => $plugin) {
        if (dirname($path) === $slug) {
            $plugin_path = $path;
            break;
        }
    }
    
    if (!$plugin_path) {
        return new WP_Error('plugin_not_found', 'Plugin not installed: ' . $slug, ['status' => 404]);
    }
    
    // Deactivate first if active
    if (is_plugin_active($plugin_path)) {
        deactivate_plugins($plugin_path);
    }
    
    // Delete
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $deleted = delete_plugins([$plugin_path]);
    
    if (is_wp_error($deleted)) {
        return new WP_Error('delete_failed', $deleted->get_error_message(), ['status' => 500]);
    }
    
    if (!$deleted) {
        return new WP_Error('delete_failed', 'Failed to delete plugin', ['status' => 500]);
    }
    
    return [
        'success' => true,
        'status' => 'deleted',
        'slug' => $slug,
    ];
}

// Admin settings page
add_action('admin_menu', function() {
    add_options_page('OpenClaw API', 'OpenClaw API', 'manage_options', 'openclaw-api', 'openclaw_api_admin_page');
});

// Default capabilities
function openclaw_get_default_capabilities() {
    return [
        'posts_read' => true,
        'posts_create' => true,
        'posts_update' => true,
        'posts_delete' => false,
        'pages_read' => true,
        'pages_create' => true,
        'categories_read' => true,
        'categories_create' => true,
        'tags_read' => true,
        'tags_create' => true,
        'media_read' => true,
        'media_upload' => false,  // Off by default - enable in settings
        'media_delete' => false,  // Off by default - enable in settings
        'users_read' => true,
        'plugins_read' => true,
        'plugins_search' => true,
        'plugins_install' => false,
        'plugins_activate' => false,
        'plugins_deactivate' => false,
        'plugins_update' => false,
        'plugins_delete' => false,
        'site_info' => true,
    ];
}

// Get capabilities with defaults
function openclaw_get_capabilities() {
    $defaults = openclaw_get_default_capabilities();
    $saved = get_option('openclaw_api_capabilities', []);
    return wp_parse_args($saved, $defaults);
}

// Check if capability is allowed
function openclaw_can($capability) {
    $caps = openclaw_get_capabilities();
    return !empty($caps[$capability]);
}

// Permission callback that checks both token AND capability
function openclaw_verify_token_and_can($capability) {
    $token_check = openclaw_verify_token();
    if (is_wp_error($token_check)) {
        return $token_check;
    }
    if (!openclaw_can($capability)) {
        return new WP_Error('capability_denied', "API capability '$capability' is disabled", ['status' => 403]);
    }
    return true;
}

function openclaw_api_admin_page() {
    $new_token = null;
    
    // Handle token generation
    if (isset($_POST['openclaw_generate']) && check_admin_referer('openclaw_settings')) {
        // Generate a new token
        $token = wp_generate_password(64, false);
        $token_hash = wp_hash($token);
        
        // Store the hash, not the token
        update_option('openclaw_api_token_hash', $token_hash);
        delete_option('openclaw_api_token'); // Remove any legacy plaintext token
        
        // Store token temporarily to show to user (cleared after display)
        $new_token = $token;
        set_transient('openclaw_new_token', $token, 60);
        
        echo '<div class="notice notice-success"><p>Token generated! <strong>Copy it now - it will not be shown again.</strong></p></div>';
    }
    
    // Handle token deletion
    if (isset($_POST['openclaw_delete']) && check_admin_referer('openclaw_settings')) {
        delete_option('openclaw_api_token_hash');
        delete_option('openclaw_api_token'); // Remove any legacy plaintext token
        delete_transient('openclaw_new_token');
        echo '<div class="notice notice-success"><p>Token deleted!</p></div>';
    }
    
    // Handle capabilities save
    if (isset($_POST['openclaw_save_caps']) && check_admin_referer('openclaw_capabilities')) {
        $caps = [];
        foreach (openclaw_get_default_capabilities() as $key => $default) {
            $caps[$key] = isset($_POST['openclaw_cap_' . $key]);
        }
        update_option('openclaw_api_capabilities', $caps);
        echo '<div class="notice notice-success"><p>Capabilities saved!</p></div>';
    }
    
    // Check for newly generated token to display
    if (!$new_token) {
        $new_token = get_transient('openclaw_new_token');
    }
    
    $has_token = get_option('openclaw_api_token_hash') || get_option('openclaw_api_token');
    $caps = openclaw_get_capabilities();
    $defaults = openclaw_get_default_capabilities();
    
    // Group capabilities
    $groups = [
        'Posts' => ['posts_read', 'posts_create', 'posts_update', 'posts_delete'],
        'Pages' => ['pages_read', 'pages_create'],
        'Taxonomies' => ['categories_read', 'categories_create', 'tags_read', 'tags_create'],
        'Media' => ['media_read', 'media_upload', 'media_delete'],
        'Users' => ['users_read'],
        'Plugins' => ['plugins_read', 'plugins_search', 'plugins_install', 'plugins_activate', 'plugins_deactivate', 'plugins_update', 'plugins_delete'],
        'Site' => ['site_info'],
    ];
    
    $cap_labels = [
        'posts_read' => 'Read Posts',
        'posts_create' => 'Create Posts',
        'posts_update' => 'Update Posts',
        'posts_delete' => 'Delete Posts',
        'pages_read' => 'Read Pages',
        'pages_create' => 'Create Pages',
        'categories_read' => 'Read Categories',
        'categories_create' => 'Create Categories',
        'tags_read' => 'Read Tags',
        'tags_create' => 'Create Tags',
        'media_read' => 'Read Media',
        'media_upload' => 'Upload Media',
        'media_delete' => 'Delete Media',
        'users_read' => 'Read Users',
        'plugins_read' => 'List Plugins',
        'plugins_search' => 'Search Plugins (WordPress.org)',
        'plugins_install' => 'Install Plugins',
        'plugins_activate' => 'Activate Plugins',
        'plugins_deactivate' => 'Deactivate Plugins',
        'plugins_update' => 'Update Plugins',
        'plugins_delete' => 'Delete Plugins',
        'site_info' => 'Read Site Info',
    ];
    ?>
    <div class="wrap">
        <h1>OpenClaw API</h1>
        <p>REST API for OpenClaw remote site management. Use <code>X-OpenClaw-Token</code> header for authentication.</p>
        
        <h2>API Token</h2>
        <?php if ($new_token): ?>
            <div class="notice notice-warning" style="background:#fff3cd;border-left-color:#ffb900;">
                <p><strong>⚠️ Copy this token now! It will not be shown again.</strong></p>
                <p><code style="background:#f0f0f1;padding:10px;display:block;word-break:break-all;font-size:14px;font-weight:bold;"><?php echo esc_html($new_token); ?></code></p>
                <p style="color:#666;font-size:12px;">Store this securely. Tokens are stored hashed and cannot be recovered.</p>
            </div>
            <pre style="background:#f0f0f1;padding:15px;font-size:12px;margin-top:15px;">curl -H "X-OpenClaw-Token: <?php echo esc_html($new_token); ?>" \
    https://yoursite.com/wp-json/openclaw/v1/site</pre>
            <?php
            // Clear the transient so it's not shown again
            delete_transient('openclaw_new_token');
            ?>
        <?php elseif ($has_token): ?>
            <p style="color:#666;">✓ API token is configured. Tokens are stored hashed and cannot be displayed.</p>
            <form method="post" style="margin-top:10px;">
                <?php wp_nonce_field('openclaw_settings'); ?>
                <input type="hidden" name="openclaw_delete" value="1">
                <input type="submit" value="Delete Token" class="button" onclick="return confirm('Are you sure? This will break all API integrations.');">
                <button type="submit" name="openclaw_generate" value="1" class="button" style="margin-left:5px;" onclick="return confirm('Generate a new token? The old token will stop working immediately.');">Regenerate Token</button>
                <?php wp_nonce_field('openclaw_settings'); ?>
            </form>
        <?php else: ?>
            <p style="color:#666;">No API token configured. Generate one to enable API access.</p>
            <form method="post">
                <?php wp_nonce_field('openclaw_settings'); ?>
                <input type="hidden" name="openclaw_generate" value="1">
                <input type="submit" value="Generate Token" class="button button-primary">
            </form>
        <?php endif; ?>
        
        <h2>API Capabilities</h2>
        <p style="color:#666;">Enable or disable specific API capabilities. Disabled endpoints will return 403 Forbidden.</p>
        
        <form method="post">
            <?php wp_nonce_field('openclaw_capabilities'); ?>
            <input type="hidden" name="openclaw_save_caps" value="1">
            
            <table class="form-table">
                <?php foreach ($groups as $group_name => $group_caps): ?>
                <tr>
                    <th scope="row" style="padding-top:20px;font-weight:600;"><?php echo esc_html($group_name); ?></th>
                    <td style="padding-top:20px;">
                        <?php foreach ($group_caps as $cap): ?>
                        <label style="display:inline-block;width:200px;margin-bottom:8px;">
                            <input type="checkbox" name="openclaw_cap_<?php echo esc_attr($cap); ?>" <?php checked(!empty($caps[$cap])); ?>>
                            <?php echo esc_html($cap_labels[$cap] ?? $cap); ?>
                            <?php if ($defaults[$cap] === false): ?>
                                <span style="color:#d63638;font-size:11px;">(off by default)</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            
            <p class="submit">
                <input type="submit" value="Save Capabilities" class="button button-primary">
                <button type="button" class="button" onclick="document.querySelectorAll('[name^=\'openclaw_cap_\']').forEach(cb => cb.checked = false);" style="margin-left:5px;">Disable All</button>
                <button type="button" class="button" id="reset-defaults-btn" style="margin-left:5px;">Reset to Defaults</button>
                <script>
                document.getElementById('reset-defaults-btn').addEventListener('click', function() {
                    <?php foreach ($defaults as $k => $v): ?>
                    var el = document.querySelector('[name="openclaw_cap_<?php echo esc_js($k); ?>"]');
                    if (el) el.checked = <?php echo $v ? 'true' : 'false'; ?>;
                    <?php endforeach; ?>
                });
                </script>
            </p>
        </form>
        
        <h2>API Endpoints</h2>
        <table class="widefat" style="max-width:800px;">
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Endpoint</th>
                    <th>Capability</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>GET</td><td><code>/ping</code></td><td>-</td><td>Health check (no auth)</td></tr>
                <tr><td>GET</td><td><code>/site</code></td><td>site_info</td><td>Site information</td></tr>
                <tr><td>GET</td><td><code>/posts</code></td><td>posts_read</td><td>List posts</td></tr>
                <tr><td>POST</td><td><code>/posts</code></td><td>posts_create</td><td>Create post</td></tr>
                <tr><td>PUT</td><td><code>/posts/{id}</code></td><td>posts_update</td><td>Update post</td></tr>
                <tr><td>DELETE</td><td><code>/posts/{id}</code></td><td>posts_delete</td><td>Delete post</td></tr>
                <tr><td>GET</td><td><code>/pages</code></td><td>pages_read</td><td>List pages</td></tr>
                <tr><td>POST</td><td><code>/pages</code></td><td>pages_create</td><td>Create page</td></tr>
                <tr><td>GET</td><td><code>/categories</code></td><td>categories_read</td><td>List categories</td></tr>
                <tr><td>POST</td><td><code>/categories</code></td><td>categories_create</td><td>Create category</td></tr>
                <tr><td>GET</td><td><code>/tags</code></td><td>tags_read</td><td>List tags</td></tr>
                <tr><td>POST</td><td><code>/tags</code></td><td>tags_create</td><td>Create tag</td></tr>
                <tr><td>GET</td><td><code>/media</code></td><td>media_read</td><td>List media</td></tr>
                <tr><td>POST</td><td><code>/media</code></td><td>media_upload</td><td>Upload image (multipart/form-data)</td></tr>
                <tr><td>DELETE</td><td><code>/media/{id}</code></td><td>media_delete</td><td>Delete media</td></tr>
                <tr><td>GET</td><td><code>/users</code></td><td>users_read</td><td>List users</td></tr>
                <tr><td>GET</td><td><code>/plugins</code></td><td>plugins_read</td><td>List installed plugins</td></tr>
                <tr><td>GET</td><td><code>/plugins/search</code></td><td>plugins_search</td><td>Search WordPress.org</td></tr>
                <tr><td>POST</td><td><code>/plugins/install</code></td><td>plugins_install</td><td>Install plugin</td></tr>
                <tr><td>POST</td><td><code>/plugins/{slug}/activate</code></td><td>plugins_activate</td><td>Activate plugin</td></tr>
                <tr><td>POST</td><td><code>/plugins/{slug}/deactivate</code></td><td>plugins_deactivate</td><td>Deactivate plugin</td></tr>
                <tr><td>POST</td><td><code>/plugins/{slug}/update</code></td><td>plugins_update</td><td>Update plugin</td></tr>
                <tr><td>DELETE</td><td><code>/plugins/{slug}</code></td><td>plugins_delete</td><td>Delete plugin</td></tr>
            </tbody>
        </table>
    </div>
    <?php
}