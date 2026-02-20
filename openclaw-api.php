<?php
/**
 * Plugin Name: OpenClaw API
 * Description: WordPress REST API for OpenClaw remote site management
 * Version: 2.6.51
 * Author: OpenClaw
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/openclaw/openclaw-api
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OPENCLAW_API_VERSION', '2.6.51');
define('OPENCLAW_API_GITHUB_REPO', 'openclaw/openclaw-api');

// GitHub Updater Integration
add_filter('update_plugins_github.com', function($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== 'openclaw-api/openclaw-api.php') {
        return $update;
    }
    
    $github_repo = 'openclaw/openclaw-api';
    $response = wp_remote_get("https://api.github.com/repos/{$github_repo}/releases/latest", [
        'headers' => ['User-Agent' => 'WordPress OpenClaw API Plugin'],
        'timeout' => 10,
    ]);
    
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return $update;
    }
    
    $release = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($release['tag_name']) || empty($release['assets'])) {
        return $update;
    }
    
    $new_version = ltrim($release['tag_name'], 'v');
    $current_version = $plugin_data['Version'] ?? '0';
    
    if (version_compare($new_version, $current_version, '<=')) {
        return $update;
    }
    
    // Find the zip asset
    $download_url = null;
    foreach ($release['assets'] as $asset) {
        if (strpos($asset['name'], '.zip') !== false) {
            $download_url = $asset['browser_download_url'];
            break;
        }
    }
    
    if (!$download_url) {
        return $update;
    }
    
    return [
        'slug' => 'openclaw-api',
        'version' => $new_version,
        'url' => $release['html_url'],
        'package' => $download_url,
    ];
}, 10, 3);

// Self-update API endpoint
add_action('rest_api_init', function() {
    register_rest_route('openclaw/v1', '/self-update', [
        'methods' => 'POST',
        'callback' => 'openclaw_self_update',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_update'); },
    ]);
    
    // Direct update from URL (allows external push)
    register_rest_route('openclaw/v1', '/self-update-from-url', [
        'methods' => 'POST',
        'callback' => 'openclaw_self_update_from_url',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_update'); },
    ]);
});

function openclaw_self_update() {
    $plugin_file = 'openclaw-api/openclaw-api.php';
    
    // Check for updates
    wp_update_plugins();
    
    $update_plugins = get_site_transient('update_plugins');
    if (!isset($update_plugins->response[$plugin_file])) {
        return new WP_REST_Response([
            'status' => 'up_to_date',
            'message' => 'OpenClaw API is already up to date',
            'version' => OPENCLAW_API_VERSION ?? '2.4.0',
        ], 200);
    }
    
    // Perform update
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->upgrade($plugin_file);
    
    if (is_wp_error($result)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => $result->get_error_message(),
        ], 500);
    }
    
    return new WP_REST_Response([
        'status' => 'updated',
        'message' => 'OpenClaw API updated successfully',
        'version' => $update_plugins->response[$plugin_file]->new_version ?? 'unknown',
    ], 200);
}

function openclaw_self_update_from_url($request) {
    $data = $request->get_json_params();
    $download_url = esc_url_raw($data['url'] ?? '');
    
    if (empty($download_url)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Missing URL parameter',
        ], 400);
    }
    
    // CRITICAL: Enforce HTTPS
    if (parse_url($download_url, PHP_URL_SCHEME) !== 'https') {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'URL must use HTTPS (security requirement)',
        ], 400);
    }
    
    // Validate URL is from trusted sources
    $trusted_hosts = [
        'github.com',
        'objects.githubusercontent.com',
        'raw.githubusercontent.com',
        'api.github.com',
        'openclaw.ai',
        'clawhub.ai',
    ];
    
    $host = parse_url($download_url, PHP_URL_HOST);
    if (!in_array($host, $trusted_hosts, true)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'URL must be from a trusted host (github.com, objects.githubusercontent.com, openclaw.ai, clawhub.ai)',
        ], 400);
    }
    
    // Download the zip
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    
    $temp_file = download_url($download_url, 300); // 5 minute timeout
    
    if (is_wp_error($temp_file)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Failed to download: ' . $temp_file->get_error_message(),
        ], 500);
    }
    
    // Verify it's a valid zip AND contains OpenClaw API
    $zip = new ZipArchive();
    if ($zip->open($temp_file) !== true) {
        @unlink($temp_file);
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Downloaded file is not a valid ZIP',
        ], 400);
    }
    
    // Validate ZIP contains openclaw-api.php with correct plugin header
    $valid_plugin = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        if (strpos($filename, 'openclaw-api.php') !== false) {
            $content = $zip->getFromIndex($i);
            if (strpos($content, 'Plugin Name: OpenClaw API') !== false) {
                $valid_plugin = true;
            }
            break;
        }
    }
    $zip->close();
    
    if (!$valid_plugin) {
        @unlink($temp_file);
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'ZIP does not contain valid OpenClaw API plugin',
        ], 400);
    }
    
    // Perform update
    $plugin_file = 'openclaw-api/openclaw-api.php';
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->run([
        'package' => $temp_file,
        'destination' => WP_PLUGIN_DIR . '/openclaw-api',
        'clear_destination' => true,
        'clear_working' => true,
        'hook_extra' => ['plugin' => $plugin_file],
    ]);
    
    @unlink($temp_file);
    
    if (is_wp_error($result)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => $result->get_error_message(),
        ], 500);
    }
    
    // Get new version from updated plugin
    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/openclaw-api/openclaw-api.php');
    
    // Clear OpCache if available
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    
    return new WP_REST_Response([
        'status' => 'updated',
        'message' => 'OpenClaw API updated successfully from URL',
        'version' => $plugin_data['Version'] ?? 'unknown',
    ], 200);
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
    
    register_rest_route('openclaw/v1', '/tags/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'openclaw_update_tag',
        'permission_callback' => function() { return openclaw_verify_token_and_can('tags_update'); },
    ]);
    
    register_rest_route('openclaw/v1', '/tags/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'openclaw_delete_tag',
        'permission_callback' => function() { return openclaw_verify_token_and_can('tags_delete'); },
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
    
    register_rest_route('openclaw/v1', '/pages/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'openclaw_update_page',
        'permission_callback' => function() { return openclaw_verify_token_and_can('pages_update'); },
    ]);
    
    register_rest_route('openclaw/v1', '/pages/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'openclaw_delete_page',
        'permission_callback' => function() { return openclaw_verify_token_and_can('pages_delete'); },
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
    
    // Plugin/Theme Upload from ZIP
    register_rest_route('openclaw/v1', '/debug/modules', [
        'methods' => 'GET',
        'callback' => function() {
            $dir = plugin_dir_path(__FILE__) . 'modules/';
            $files = array_diff(scandir($dir), ['.', '..']);
            $details = [];
            foreach ($files as $file) {
                $details[$file] = [
                    'size' => filesize($dir . $file),
                    'mtime' => date('Y-m-d H:i:s', filemtime($dir . $file)),
                    'content_sample' => substr(file_get_contents($dir . $file), 0, 500)
                ];
            }
            return new WP_REST_Response([
                'dir' => $dir,
                'files' => $details,
                'plugin_version' => OPENCLAW_API_VERSION
            ], 200);
        },
        'permission_callback' => 'openclaw_verify_token',
    ]);

    register_rest_route('openclaw/v1', '/plugins/upload', [
        'methods' => 'POST',
        'callback' => 'openclaw_upload_plugin',
        'permission_callback' => function() { return openclaw_verify_token_and_can('plugins_upload'); },
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

// ===== MENU MANAGEMENT API (v2.4.0) =====
add_action('rest_api_init', function() {
    
    // List all menus
    register_rest_route('openclaw/v1', '/menus', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_menus',
        'permission_callback' => function() { return openclaw_verify_token_and_can('menus_read'); },
    ]);
    
    // Get single menu with items
    register_rest_route('openclaw/v1', '/menus/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_menu',
        'permission_callback' => function() { return openclaw_verify_token_and_can('menus_read'); },
    ]);
    
    // Create menu
    register_rest_route('openclaw/v1', '/menus', [
        'methods' => 'POST',
        'callback' => 'openclaw_create_menu',
        'permission_callback' => function() { return openclaw_verify_token_and_can('menus_create'); },
    ]);
    
    // Update menu name
    register_rest_route('openclaw/v1', '/menus/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'openclaw_update_menu',
        'permission_callback' => function() { return openclaw_verify_token_and_can('menus_update'); },
    ]);
    
    // Delete menu
    register_rest_route('openclaw/v1', '/menus/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'openclaw_delete_menu',
        'permission_callback' => function() { return openclaw_verify_token_and_can('menus_delete'); },
    ]);
    
    // Add menu item
    register_rest_route('openclaw/v1', '/menus/(?P<id>\d+)/items', [
        'methods' => 'POST',
        'callback' => 'openclaw_add_menu_item',
        'permission_callback' => function() { return openclaw_verify_token_and_can('menus_update'); },
    ]);
    
    // Remove menu item
    register_rest_route('openclaw/v1', '/menus/(?P<id>\d+)/items/(?P<item_id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'openclaw_delete_menu_item',
        'permission_callback' => function() { return openclaw_verify_token_and_can('menus_update'); },
    ]);
    
    // Reorder menu items
    register_rest_route('openclaw/v1', '/menus/(?P<id>\d+)/items', [
        'methods' => 'PUT',
        'callback' => 'openclaw_reorder_menu_items',
        'permission_callback' => function() { return openclaw_verify_token_and_can('menus_update'); },
    ]);
});

// ===== THEME MANAGEMENT API (v2.4.0) =====
add_action('rest_api_init', function() {
    
    // Get current active theme
    register_rest_route('openclaw/v1', '/theme', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_active_theme',
        'permission_callback' => function() { return openclaw_verify_token_and_can('themes_read'); },
    ]);
    
    // List installed themes
    register_rest_route('openclaw/v1', '/themes', [
        'methods' => 'GET',
        'callback' => 'openclaw_get_themes',
        'permission_callback' => function() { return openclaw_verify_token_and_can('themes_read'); },
    ]);
    
    // Switch active theme
    register_rest_route('openclaw/v1', '/theme', [
        'methods' => 'PUT',
        'callback' => 'openclaw_switch_theme',
        'permission_callback' => function() { return openclaw_verify_token_and_can('themes_switch'); },
    ]);
    
    // Install theme from ZIP URL
    register_rest_route('openclaw/v1', '/theme/install', [
        'methods' => 'POST',
        'callback' => 'openclaw_install_theme_from_url',
        'permission_callback' => function() { return openclaw_verify_token_and_can('themes_install'); },
    ]);
    
    // Delete theme
    register_rest_route('openclaw/v1', '/themes/(?P<stylesheet>[a-zA-Z0-9\-_]+)', [
        'methods' => 'DELETE',
        'callback' => 'openclaw_delete_theme',
        'permission_callback' => function() { return openclaw_verify_token_and_can('themes_delete'); },
    ]);
});

// ===== MENU API FUNCTIONS =====

/**
 * Get all menus
 */
function openclaw_get_menus() {
    $menus = wp_get_nav_menus();
    
    return array_map(function($menu) {
        return [
            'id' => $menu->term_id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'count' => $menu->count,
            'locations' => openclaw_get_menu_locations($menu->term_id),
        ];
    }, $menus);
}

/**
 * Get menu locations for a menu
 */
function openclaw_get_menu_locations($menu_id) {
    $locations = get_nav_menu_locations();
    $menu_locations = [];
    
    foreach ($locations as $location => $assigned_menu_id) {
        if ($assigned_menu_id == $menu_id) {
            $menu_locations[] = $location;
        }
    }
    
    return $menu_locations;
}

/**
 * Get single menu with items
 */
function openclaw_get_menu($request) {
    $menu_id = (int) $request['id'];
    
    $menu = wp_get_nav_menu_object($menu_id);
    if (!$menu || is_wp_error($menu)) {
        return new WP_Error('menu_not_found', 'Menu not found', ['status' => 404]);
    }
    
    $items = wp_get_nav_menu_items($menu_id);
    
    $formatted_items = array_map(function($item) {
        return [
            'id' => $item->ID,
            'title' => $item->title,
            'url' => $item->url,
            'type' => $item->type,
            'object' => $item->object,
            'object_id' => $item->object_id,
            'parent' => $item->menu_item_parent,
            'order' => $item->menu_order,
            'target' => $item->target,
            'classes' => $item->classes,
        ];
    }, $items ?: []);
    
    return [
        'id' => $menu->term_id,
        'name' => $menu->name,
        'slug' => $menu->slug,
        'count' => $menu->count,
        'locations' => openclaw_get_menu_locations($menu->term_id),
        'items' => $formatted_items,
    ];
}

/**
 * Create a new menu
 */
function openclaw_create_menu($request) {
    $data = $request->get_json_params();
    $name = sanitize_text_field($data['name'] ?? '');
    
    if (empty($name)) {
        return new WP_Error('missing_name', 'Menu name is required', ['status' => 400]);
    }
    
    // Check if menu with this name already exists
    $existing = wp_get_nav_menu_object($name);
    if ($existing && !is_wp_error($existing)) {
        return new WP_Error('menu_exists', 'Menu with this name already exists', ['status' => 409]);
    }
    
    $result = wp_create_nav_menu($name);
    
    if (is_wp_error($result)) {
        return new WP_Error('create_failed', $result->get_error_message(), ['status' => 500]);
    }
    
    $menu = wp_get_nav_menu_object($result);
    
    // Audit log
    error_log(sprintf('[OpenClaw API] Menu created: ID=%d, Name=%s', $result, $name));
    
    return [
        'success' => true,
        'id' => $menu->term_id,
        'name' => $menu->name,
        'slug' => $menu->slug,
    ];
}

/**
 * Update menu name
 */
function openclaw_update_menu($request) {
    $menu_id = (int) $request['id'];
    $data = $request->get_json_params();
    $name = sanitize_text_field($data['name'] ?? '');
    
    if (empty($name)) {
        return new WP_Error('missing_name', 'Menu name is required', ['status' => 400]);
    }
    
    $menu = wp_get_nav_menu_object($menu_id);
    if (!$menu || is_wp_error($menu)) {
        return new WP_Error('menu_not_found', 'Menu not found', ['status' => 404]);
    }
    
    $result = wp_update_term($menu_id, 'nav_menu', [
        'name' => $name,
        'slug' => sanitize_title($name),
    ]);
    
    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }
    
    $updated_menu = wp_get_nav_menu_object($menu_id);
    
    // Audit log
    error_log(sprintf('[OpenClaw API] Menu updated: ID=%d, OldName=%s, NewName=%s', $menu_id, $menu->name, $name));
    
    return [
        'success' => true,
        'id' => $updated_menu->term_id,
        'name' => $updated_menu->name,
        'slug' => $updated_menu->slug,
    ];
}

/**
 * Delete menu
 */
function openclaw_delete_menu($request) {
    $menu_id = (int) $request['id'];
    
    $menu = wp_get_nav_menu_object($menu_id);
    if (!$menu || is_wp_error($menu)) {
        return new WP_Error('menu_not_found', 'Menu not found', ['status' => 404]);
    }
    
    $result = wp_delete_nav_menu($menu_id);
    
    if (is_wp_error($result)) {
        return new WP_Error('delete_failed', $result->get_error_message(), ['status' => 500]);
    }
    
    // Audit log
    error_log(sprintf('[OpenClaw API] Menu deleted: ID=%d, Name=%s', $menu_id, $menu->name));
    
    return [
        'success' => true,
        'deleted' => true,
        'id' => $menu_id,
    ];
}

/**
 * Add menu item
 */
function openclaw_add_menu_item($request) {
    $menu_id = (int) $request['id'];
    $data = $request->get_json_params();
    
    $menu = wp_get_nav_menu_object($menu_id);
    if (!$menu || is_wp_error($menu)) {
        return new WP_Error('menu_not_found', 'Menu not found', ['status' => 404]);
    }
    
    $type = sanitize_text_field($data['type'] ?? 'custom');
    $title = sanitize_text_field($data['title'] ?? '');
    $url = esc_url_raw($data['url'] ?? '');
    $object_id = (int) ($data['object_id'] ?? 0);
    $parent_id = (int) ($data['parent_id'] ?? 0);
    $position = (int) ($data['position'] ?? 0);
    
    // Build menu item data
    $menu_item_data = [
        'menu-id' => $menu_id,
        'menu-item-type' => $type,
        'menu-item-status' => 'publish',
    ];
    
    // Handle different item types
    switch ($type) {
        case 'post_type':
            if (empty($object_id)) {
                return new WP_Error('missing_object_id', 'object_id is required for post_type items', ['status' => 400]);
            }
            $post = get_post($object_id);
            if (!$post) {
                return new WP_Error('invalid_object_id', 'Post not found', ['status' => 404]);
            }
            $menu_item_data['menu-item-object-id'] = $object_id;
            $menu_item_data['menu-item-object'] = $post->post_type;
            $menu_item_data['menu-item-title'] = $title ?: $post->post_title;
            break;
            
        case 'taxonomy':
            if (empty($object_id)) {
                return new WP_Error('missing_object_id', 'object_id is required for taxonomy items', ['status' => 400]);
            }
            $term = get_term($object_id);
            if (!$term || is_wp_error($term)) {
                return new WP_Error('invalid_object_id', 'Term not found', ['status' => 404]);
            }
            $menu_item_data['menu-item-object-id'] = $object_id;
            $menu_item_data['menu-item-object'] = $term->taxonomy;
            $menu_item_data['menu-item-title'] = $title ?: $term->name;
            break;
            
        case 'custom':
        default:
            if (empty($url)) {
                return new WP_Error('missing_url', 'URL is required for custom menu items', ['status' => 400]);
            }
            $menu_item_data['menu-item-url'] = $url;
            $menu_item_data['menu-item-title'] = $title ?: 'Untitled';
            break;
    }
    
    // Set parent if provided
    if ($parent_id > 0) {
        $menu_item_data['menu-item-parent-id'] = $parent_id;
    }
    
    // Set position if provided
    if ($position > 0) {
        $menu_item_data['menu-item-position'] = $position;
    }
    
    $item_id = wp_update_nav_menu_item($menu_id, 0, $menu_item_data);
    
    if (is_wp_error($item_id)) {
        return new WP_Error('item_create_failed', $item_id->get_error_message(), ['status' => 500]);
    }
    
    // Audit log
    error_log(sprintf('[OpenClaw API] Menu item added: MenuID=%d, ItemID=%d, Type=%s, Title=%s', $menu_id, $item_id, $type, $menu_item_data['menu-item-title']));
    
    $item = get_post($item_id);
    
    return [
        'success' => true,
        'id' => $item_id,
        'title' => $item->post_title,
        'menu_order' => $item->menu_order,
    ];
}

/**
 * Delete menu item
 */
function openclaw_delete_menu_item($request) {
    $menu_id = (int) $request['id'];
    $item_id = (int) $request['item_id'];
    
    $menu = wp_get_nav_menu_object($menu_id);
    if (!$menu || is_wp_error($menu)) {
        return new WP_Error('menu_not_found', 'Menu not found', ['status' => 404]);
    }
    
    $item = get_post($item_id);
    if (!$item || $item->post_type !== 'nav_menu_item') {
        return new WP_Error('item_not_found', 'Menu item not found', ['status' => 404]);
    }
    
    // Verify item belongs to this menu
    $item_menu_id = get_post_meta($item_id, '_menu_item_menu_item_parent', true);
    $terms = wp_get_object_terms($item_id, 'nav_menu', ['fields' => 'ids']);
    if (!in_array($menu_id, $terms)) {
        return new WP_Error('item_mismatch', 'Menu item does not belong to this menu', ['status' => 400]);
    }
    
    $deleted = wp_delete_post($item_id, true);
    
    if (!$deleted) {
        return new WP_Error('delete_failed', 'Failed to delete menu item', ['status' => 500]);
    }
    
    // Audit log
    error_log(sprintf('[OpenClaw API] Menu item deleted: MenuID=%d, ItemID=%d', $menu_id, $item_id));
    
    return [
        'success' => true,
        'deleted' => true,
        'id' => $item_id,
    ];
}

/**
 * Reorder menu items
 */
function openclaw_reorder_menu_items($request) {
    $menu_id = (int) $request['id'];
    $data = $request->get_json_params();
    
    $menu = wp_get_nav_menu_object($menu_id);
    if (!$menu || is_wp_error($menu)) {
        return new WP_Error('menu_not_found', 'Menu not found', ['status' => 404]);
    }
    
    $item_ids = $data['order'] ?? [];
    
    if (!is_array($item_ids) || empty($item_ids)) {
        return new WP_Error('invalid_order', 'Order must be a non-empty array of item IDs', ['status' => 400]);
    }
    
    // Validate all item IDs belong to this menu
    $menu_items = wp_get_nav_menu_items($menu_id);
    $menu_item_ids = array_map(function($item) { return $item->ID; }, $menu_items);
    
    $invalid_ids = array_diff($item_ids, $menu_item_ids);
    if (!empty($invalid_ids)) {
        return new WP_Error('invalid_items', 'Some item IDs do not belong to this menu: ' . implode(', ', $invalid_ids), ['status' => 400]);
    }
    
    // Update menu order for each item
    $position = 1;
    foreach ($item_ids as $item_id) {
        $item_id = (int) $item_id;
        wp_update_post([
            'ID' => $item_id,
            'menu_order' => $position,
        ]);
        $position++;
    }
    
    // Audit log
    error_log(sprintf('[OpenClaw API] Menu items reordered: MenuID=%d, Items=%s', $menu_id, implode(',', $item_ids)));
    
    return [
        'success' => true,
        'menu_id' => $menu_id,
        'order' => $item_ids,
    ];
}

// ===== THEME API FUNCTIONS =====

/**
 * Get current active theme
 */
function openclaw_get_theme() {
    $theme = wp_get_theme();
    
    return [
        'name' => $theme->get('Name'),
        'stylesheet' => $theme->get_stylesheet(),
        'template' => $theme->get_template(),
        'version' => $theme->get('Version'),
        'author' => $theme->get('Author'),
        'description' => $theme->get('Description'),
        'tags' => $theme->get('Tags'),
        'screenshot' => $theme->get_screenshot(),
        'parent' => $theme->parent() ? $theme->parent()->get('Name') : null,
        'active' => true,
    ];
}

/**
 * List installed themes
 */
function openclaw_get_themes() {
    $themes = wp_get_themes();
    $active_stylesheet = get_stylesheet();
    
    $result = [];
    
    foreach ($themes as $stylesheet => $theme) {
        $result[] = [
            'name' => $theme->get('Name'),
            'stylesheet' => $stylesheet,
            'template' => $theme->get_template(),
            'version' => $theme->get('Version'),
            'author' => $theme->get('Author'),
            'description' => mb_substr($theme->get('Description'), 0, 200),
            'screenshot' => $theme->get_screenshot(),
            'parent' => $theme->parent() ? $theme->parent()->get('Name') : null,
            'active' => ($stylesheet === $active_stylesheet),
        ];
    }
    
    return $result;
}

/**
 * Switch active theme
 */
function openclaw_switch_theme($request) {
    $data = $request->get_json_params();
    $stylesheet = sanitize_key($data['stylesheet'] ?? '');
    
    if (empty($stylesheet)) {
        return new WP_Error('missing_stylesheet', 'Theme stylesheet name is required', ['status' => 400]);
    }
    
    // Check if theme exists
    $theme = wp_get_theme($stylesheet);
    if (!$theme->exists()) {
        return new WP_Error('theme_not_found', 'Theme not found: ' . $stylesheet, ['status' => 404]);
    }
    
    // Can't switch to the same theme
    if ($stylesheet === get_stylesheet()) {
        return new WP_Error('already_active', 'Theme is already active', ['status' => 400]);
    }
    
    $old_theme = wp_get_theme();
    
    try {
        switch_theme($stylesheet);
    } catch (Exception $e) {
        return new WP_Error('switch_failed', 'Failed to switch theme: ' . $e->getMessage(), ['status' => 500]);
    }
    
    // Verify switch
    if (get_stylesheet() !== $stylesheet) {
        return new WP_Error('switch_failed', 'Theme switch failed silently', ['status' => 500]);
    }
    
    // Audit log
    error_log(sprintf('[OpenClaw API] Theme switched: OldTheme=%s, NewTheme=%s', $old_theme->get('Name'), $theme->get('Name')));
    
    return [
        'success' => true,
        'previous_theme' => $old_theme->get('Name'),
        'active_theme' => $theme->get('Name'),
        'stylesheet' => $stylesheet,
    ];
}

/**
 * Install theme from ZIP URL
 */
function openclaw_install_theme_from_url($request) {
    $data = $request->get_json_params();
    $download_url = esc_url_raw($data['url'] ?? '');
    $activate = !empty($data['activate']);
    
    if (empty($download_url)) {
        return new WP_Error('missing_url', 'Theme ZIP URL is required', ['status' => 400]);
    }
    
    // CRITICAL: Enforce HTTPS
    if (parse_url($download_url, PHP_URL_SCHEME) !== 'https') {
        return new WP_Error('https_required', 'URL must use HTTPS (security requirement)', ['status' => 400]);
    }
    
    // Validate URL is from trusted sources
    $trusted_hosts = [
        'github.com',
        'objects.githubusercontent.com',
        'raw.githubusercontent.com',
        'api.github.com',
        'openclaw.ai',
        'clawhub.ai',
        'downloads.wordpress.org',
    ];
    
    $host = parse_url($download_url, PHP_URL_HOST);
    if (!in_array($host, $trusted_hosts, true)) {
        return new WP_Error('untrusted_host', 'URL must be from a trusted host', ['status' => 400]);
    }
    
    // Download the zip
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    
    $temp_file = download_url($download_url, 300);
    
    if (is_wp_error($temp_file)) {
        return new WP_Error('download_failed', 'Failed to download theme: ' . $temp_file->get_error_message(), ['status' => 500]);
    }
    
    // Verify it's a valid zip
    $zip = new ZipArchive();
    if ($zip->open($temp_file) !== true) {
        @unlink($temp_file);
        return new WP_Error('invalid_zip', 'Downloaded file is not a valid ZIP', ['status' => 400]);
    }
    
    // Validate ZIP contains a valid WordPress theme (style.css with Theme Name header)
    $valid_theme = false;
    $theme_slug = null;
    
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        
        // Look for style.css
        if (preg_match('#^([^/]+)/style\.css$#', $filename, $matches)) {
            $content = $zip->getFromIndex($i);
            if (strpos($content, 'Theme Name:') !== false) {
                $valid_theme = true;
                $theme_slug = $matches[1];
                break;
            }
        }
    }
    $zip->close();
    
    if (!$valid_theme) {
        @unlink($temp_file);
        return new WP_Error('invalid_theme', 'ZIP does not contain a valid WordPress theme', ['status' => 400]);
    }
    
    // Check if theme already exists
    $existing_theme = wp_get_theme($theme_slug);
    if ($existing_theme->exists()) {
        @unlink($temp_file);
        return new WP_Error('theme_exists', 'Theme already exists: ' . $theme_slug, ['status' => 409]);
    }
    
    // Install theme
    require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
    
    $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->install($temp_file);
    
    @unlink($temp_file);
    
    if (is_wp_error($result)) {
        return new WP_Error('install_failed', 'Installation failed: ' . $result->get_error_message(), ['status' => 500]);
    }
    
    if (!$result) {
        return new WP_Error('install_failed', 'Theme installation failed', ['status' => 500]);
    }
    
    // Verify theme is now installed
    $installed_theme = wp_get_theme($theme_slug);
    if (!$installed_theme->exists()) {
        return new WP_Error('install_failed', 'Theme installation failed - theme not found after install', ['status' => 500]);
    }
    
    // Activate if requested
    $is_active = false;
    if ($activate) {
        switch_theme($theme_slug);
        $is_active = (get_stylesheet() === $theme_slug);
    }
    
    // Audit log
    error_log(sprintf('[OpenClaw API] Theme installed: Slug=%s, Name=%s, Activated=%s', $theme_slug, $installed_theme->get('Name'), $is_active ? 'yes' : 'no'));
    
    return [
        'success' => true,
        'stylesheet' => $theme_slug,
        'name' => $installed_theme->get('Name'),
        'version' => $installed_theme->get('Version'),
        'active' => $is_active,
    ];
}

/**
 * Delete theme
 */
function openclaw_delete_theme($request) {
    $stylesheet = sanitize_key($request['stylesheet']);
    
    if (empty($stylesheet)) {
        return new WP_Error('missing_stylesheet', 'Theme stylesheet is required', ['status' => 400]);
    }
    
    $theme = wp_get_theme($stylesheet);
    if (!$theme->exists()) {
        return new WP_Error('theme_not_found', 'Theme not found: ' . $stylesheet, ['status' => 404]);
    }
    
    // Can't delete active theme
    if ($stylesheet === get_stylesheet()) {
        return new WP_Error('active_theme', 'Cannot delete active theme. Switch to another theme first.', ['status' => 400]);
    }
    
    // Can't delete active parent theme if using child
    $current_theme = wp_get_theme();
    if ($current_theme->parent() && $current_theme->parent()->get_stylesheet() === $stylesheet) {
        return new WP_Error('parent_theme', 'Cannot delete parent theme of active theme', ['status' => 400]);
    }
    
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/theme.php';
    
    $deleted = delete_theme($stylesheet);
    
    if (is_wp_error($deleted)) {
        return new WP_Error('delete_failed', $deleted->get_error_message(), ['status' => 500]);
    }
    
    if (!$deleted) {
        return new WP_Error('delete_failed', 'Theme deletion failed', ['status' => 500]);
    }
    
    // Audit log
    error_log(sprintf('[OpenClaw API] Theme deleted: Stylesheet=%s, Name=%s', $stylesheet, $theme->get('Name')));
    
    return [
        'success' => true,
        'deleted' => true,
        'stylesheet' => $stylesheet,
    ];
}

add_action('rest_api_init', function() {
    
    // ... existing routes ...
    
});

/**
 * Centralized plugin detection function
 * Eliminates duplicate detection logic across modules
 * 
 * @param string $plugin_slug Plugin identifier (e.g., 'fluentcrm', 'fluentforms')
 * @return bool True if plugin is active
 */
function openclaw_is_plugin_active($plugin_slug) {
    static $cache = [];
    
    if (isset($cache[$plugin_slug])) {
        return $cache[$plugin_slug];
    }
    
    // Ensure is_plugin_active is available
    if (!function_exists('is_plugin_active')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    // Plugin detection configurations
    $configs = [
        'fluentforms' => [
            'classes' => ['FluentForm\App\Models\Form', 'FluentForm\Framework\Helpers\ArrayHelper'],
            'constants' => ['FLUENTFORM', 'FLUENT_FORM_VERSION'],
            'plugin_path' => 'fluentform/fluentform.php',
        ],
        'fluentcommunity' => [
            'classes' => ['FluentCommunity\App\Hooks', 'FluentCommunity\Framework\Foundation\App'],
            'constants' => ['FLUENTCOMMUNITY_VERSION', 'FLUENT_COMMUNITY_VERSION'],
            'plugin_path' => 'fluent-community/fluent-community.php',
        ],
        'fluentcrm' => [
            'classes' => ['FluentCRM\App\Models\Subscriber', 'FluentCrm\App\Models\Subscriber'],
            'constants' => ['FLUENTCRM', 'FLUENT_CRM_VERSION'],
            'plugin_path' => 'fluent-crm/fluent-crm.php',
        ],
        'fluentboards' => [
            'classes' => ['FluentBoards\App\Hooks', 'FluentBoards\App'],
            'constants' => ['FLUENT_BOARDS_VERSION', 'FLUENTBOARDS_VERSION'],
            'plugin_path' => 'fluent-boards/fluent-boards.php',
        ],
        'fluentsupport' => [
            'classes' => ['FluentSupport\App\Hooks', 'FluentSupport\App\Models\Ticket'],
            'constants' => ['FLUENT_SUPPORT_VERSION', 'FLUENTSUPPORT_VERSION'],
            'plugin_path' => 'fluent-support/fluent-support.php',
        ],
        'publishpress-statuses' => [
            'classes' => ['PublishPress_Statuses', 'PublishPress\\Statuses\\Plugin'],
            'constants' => ['PUBLISHPRESS_STATUSES_VERSION', 'PUBLISHPRESS_VERSION'],
            'plugin_path' => 'publishpress-statuses/publishpress-statuses.php',
        ],
    ];
    
    if (!isset($configs[$plugin_slug])) {
        $cache[$plugin_slug] = false;
        return false;
    }
    
    $config = $configs[$plugin_slug];
    
    // Check class existence (most reliable)
    foreach ($config['classes'] as $class) {
        if (class_exists($class)) {
            $cache[$plugin_slug] = true;
            return true;
        }
    }
    
    // Check constants
    foreach ($config['constants'] as $constant) {
        if (defined($constant)) {
            $cache[$plugin_slug] = true;
            return true;
        }
    }
    
    // Check plugin activation status
    if (function_exists('is_plugin_active') && is_plugin_active($config['plugin_path'])) {
        $cache[$plugin_slug] = true;
        return true;
    }
    
    // Fallback to active_plugins option
    $active_plugins = get_option('active_plugins', []);
    if (in_array($config['plugin_path'], $active_plugins)) {
        $cache[$plugin_slug] = true;
        return true;
    }
    
    $cache[$plugin_slug] = false;
    return false;
}

// Load optional modules (each auto-detects if its plugin is installed)
// Hook at priority 1 so modules can use plugins_loaded at higher priorities
add_action('plugins_loaded', function() {
    $modules_loader = __DIR__ . '/modules/openclaw-modules.php';
    if (file_exists($modules_loader)) {
        require_once $modules_loader;
    }
}, 5);

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
    return ['status' => 'ok', 'time' => current_time('mysql')];
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
    // Get all registered statuses including custom ones from PublishPress
    $statuses = get_post_stati([], 'names', 'or');
    
    $args = [
        'post_type' => 'post',
        'post_status' => array_values($statuses), // Include all statuses
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
    
    // Validate post status - allow custom statuses from PublishPress
    $builtin_statuses = ['draft', 'pending', 'private', 'publish'];
    $custom_statuses = array_keys(get_post_stati(['_builtin' => false], 'names'));
    $allowed_statuses = array_merge($builtin_statuses, $custom_statuses);
    $status = sanitize_text_field($data['status'] ?? 'draft');
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'draft';
    }
    
    // Determine author ID (requires posts_set_author capability to override)
    $author_id = 1;  // Default to first user
    if (!empty($data['author_id'])) {
        if (openclaw_can('posts_set_author')) {
            $author_id = (int) $data['author_id'];
            $user = get_user_by('id', $author_id);
            if (!$user) {
                return new WP_Error('invalid_author', 'Invalid author ID', ['status' => 400]);
            }
        } else {
            // Optionally return error if author_id provided but capability missing
            // For now, silently ignore and use default
        }
    }
    
    $post_data = [
        'post_title' => sanitize_text_field($data['title'] ?? 'Untitled'),
        'post_content' => wp_kses_post($data['content'] ?? ''),
        'post_status' => $status,
        'post_author' => $author_id,
    ];
    if (!empty($data['categories'])) {
        $post_data['post_category'] = array_map('intval', (array) $data['categories']);
    }
    if (!empty($data['tags'])) {
        // Accept mixed input: IDs, names, or "slug:value" format
        $post_data['tags_input'] = openclaw_resolve_tags((array) $data['tags']);
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
        $post_data['post_content'] = wp_kses_post($data['content']);
    }
    if (isset($data['status'])) {
        // Allow custom statuses from PublishPress
        $builtin_statuses = ['draft', 'pending', 'private', 'publish'];
        $custom_statuses = array_keys(get_post_stati(['_builtin' => false], 'names'));
        $allowed_statuses = array_merge($builtin_statuses, $custom_statuses);
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
        // Accept mixed input: IDs, names, or "slug:value" format
        $post_data['tags_input'] = openclaw_resolve_tags((array) $data['tags']);
    }

    // Handle featured media using meta_input (more reliable than set_post_thumbnail)
    if (isset($data['featured_media'])) {
        $media_id = (int) $data['featured_media'];
        if ($media_id > 0) {
            // Set thumbnail via meta - bypass potential plugin conflicts
            $post_data['meta_input'] = ['_thumbnail_id' => $media_id];
        } elseif ($media_id === 0) {
            // Remove featured image - do this after update since meta_input can't delete
            add_action('wp_after_insert_post', function($post_id) use ($id) {
                if ($post_id === $id) {
                    delete_post_meta($id, '_thumbnail_id');
                }
            });
        }
    }
    
    $result = wp_update_post($post_data, true);
    if (is_wp_error($result)) return $result;
    
    // For removal, we need to do it after update
    if (isset($data['featured_media']) && (int)$data['featured_media'] === 0) {
        delete_post_meta($id, '_thumbnail_id');
    }
    
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

    // Get tag IDs and names
    $tag_ids = wp_get_post_tags($post->ID, ['fields' => 'ids']);
    $tag_names = [];
    if (!empty($tag_ids)) {
        foreach ($tag_ids as $tag_id) {
            $tag = get_term($tag_id, 'post_tag');
            if ($tag && !is_wp_error($tag)) {
                $tag_names[] = $tag->name;
            }
        }
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
        'tags' => $tag_ids,
        'tag_names' => $tag_names,
        'featured_media' => get_post_thumbnail_id($post->ID),
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

    // WordPress term names limited to 200 chars
    if (mb_strlen($name) > 200) {
        return new WP_Error('name_too_long', 'Tag name must be 200 characters or less', ['status' => 400]);
    }

    $result = wp_insert_term($name, 'post_tag');
    if (is_wp_error($result)) return $result;
    $tag = get_term($result['term_id'], 'post_tag');

    // Audit log
    error_log(sprintf('[OpenClaw API] Tag created: ID=%d, Name=%s', $tag->term_id, $name));

    return ['id' => $tag->term_id, 'name' => $tag->name, 'slug' => $tag->slug];
}

/**
 * Update a tag
 */
function openclaw_update_tag($request) {
    $id = (int) $request['id'];
    $data = $request->get_json_params();
    $name = sanitize_text_field($data['name'] ?? '');

    if (empty($name)) {
        return new WP_Error('missing_name', 'Tag name is required', ['status' => 400]);
    }

    // WordPress term names limited to 200 chars
    if (mb_strlen($name) > 200) {
        return new WP_Error('name_too_long', 'Tag name must be 200 characters or less', ['status' => 400]);
    }

    $tag = get_term($id, 'post_tag');
    if (!$tag || is_wp_error($tag)) {
        return new WP_Error('tag_not_found', 'Tag not found', ['status' => 404]);
    }

    $result = wp_update_term($id, 'post_tag', [
        'name' => $name,
        'slug' => sanitize_title($name),
    ]);

    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }

    $updated_tag = get_term($id, 'post_tag');

    // Audit log
    error_log(sprintf('[OpenClaw API] Tag updated: ID=%d, OldName=%s, NewName=%s', $id, $tag->name, $name));

    return [
        'success' => true,
        'id' => $updated_tag->term_id,
        'name' => $updated_tag->name,
        'slug' => $updated_tag->slug,
    ];
}

/**
 * Delete a tag
 */
function openclaw_delete_tag($request) {
    $id = (int) $request['id'];

    $tag = get_term($id, 'post_tag');
    if (!$tag || is_wp_error($tag)) {
        return new WP_Error('tag_not_found', 'Tag not found', ['status' => 404]);
    }

    $result = wp_delete_term($id, 'post_tag');

    if (is_wp_error($result)) {
        return new WP_Error('delete_failed', $result->get_error_message(), ['status' => 500]);
    }

    if ($result === false) {
        return new WP_Error('delete_failed', 'Failed to delete tag', ['status' => 500]);
    }

    // Audit log
    error_log(sprintf('[OpenClaw API] Tag deleted: ID=%d, Name=%s', $id, $tag->name));

    return [
        'success' => true,
        'deleted' => true,
        'id' => $id,
    ];
}

/**
 * Resolve mixed tag input to an array of tag IDs.
 * Accepts:
 *   - int: Treated as tag ID
 *   - "slug:value": Resolved by slug
 *   - string (other): Resolved by name, auto-created if not exists
 *
 * @param array $tags Mixed array of IDs, names, and/or slugs
 * @return array Array of tag IDs
 */
function openclaw_resolve_tags($tags) {
    if (!is_array($tags) || empty($tags)) {
        return [];
    }

    $tag_ids = [];

    foreach ($tags as $tag) {
        if (is_int($tag) || (is_string($tag) && ctype_digit($tag))) {
            // It's an ID - verify it exists
            $term_id = (int) $tag;
            $term = get_term($term_id, 'post_tag');
            if ($term && !is_wp_error($term)) {
                $tag_ids[] = $term_id;
            }
        } elseif (is_string($tag)) {
            $tag = sanitize_text_field($tag);

            if (strpos($tag, 'slug:') === 0) {
                // Resolve by slug
                $slug = sanitize_title(substr($tag, 5));
                $term = get_term_by('slug', $slug, 'post_tag');
                if ($term) {
                    $tag_ids[] = $term->term_id;
                }
            } else {
                // Resolve by name - auto-create if not exists
                // Skip if name exceeds WordPress term name limit
                if (mb_strlen($tag) > 200) {
                    continue;
                }
                $term = get_term_by('name', $tag, 'post_tag');
                if ($term) {
                    $tag_ids[] = $term->term_id;
                } else {
                    // Auto-create the tag
                    $result = wp_insert_term($tag, 'post_tag');
                    if (!is_wp_error($result) && isset($result['term_id'])) {
                        $tag_ids[] = $result['term_id'];
                        error_log(sprintf('[OpenClaw API] Auto-created tag: Name=%s, ID=%d', $tag, $result['term_id']));
                    }
                }
            }
        }
    }

    return array_unique(array_filter($tag_ids));
}

// Media
// Defer to the module for all media operations
// The module loads after this file and will override the routes

// This is a placeholder - the actual implementation is in openclaw-module-media.php
function openclaw_get_media($request) {
    return new WP_Error('media_module_missing', 'Media management module not installed', ['status' => 500]);
}

function openclaw_upload_media($request) {
    return new WP_Error('media_module_missing', 'Media management module not installed', ['status' => 500]);
}

function openclaw_delete_media($request) {
    return new WP_Error('media_module_missing', 'Media management module not installed', ['status' => 500]);
}

// Pages
function openclaw_get_pages() {
    // Get all registered statuses including custom ones
    $statuses = get_post_stati([], 'names', 'or');
    $pages = get_pages(['post_status' => array_values($statuses)]);
    return array_map(function($p) {
        return ['id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'status' => $p->post_status];
    }, $pages);
}

function openclaw_create_page($request) {
    $data = $request->get_json_params();
    
    // Validate status - allow custom statuses from PublishPress
    $builtin_statuses = ['draft', 'pending', 'private', 'publish'];
    $custom_statuses = array_keys(get_post_stati(['_builtin' => false], 'names'));
    $allowed_statuses = array_merge($builtin_statuses, $custom_statuses);
    $status = sanitize_text_field($data['status'] ?? 'draft');
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'draft';
    }
    
    $page_id = wp_insert_post([
        'post_type' => 'page',
        'post_title' => sanitize_text_field($data['title'] ?? 'Untitled'),
        'post_content' => wp_kses_post($data['content'] ?? ''),
        'post_status' => $status,
    ], true);
    if (is_wp_error($page_id)) return $page_id;
    return ['id' => $page_id, 'title' => $data['title'] ?? 'Untitled', 'link' => get_permalink($page_id)];
}

// Update page
function openclaw_update_page($request) {
    $id = (int) $request['id'];
    
    // Verify page exists and is correct type
    $page = get_post($id);
    if (!$page) {
        return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
    }
    if ($page->post_type !== 'page') {
        return new WP_Error('invalid_post_type', 'This endpoint only works with pages, not posts or other types', ['status' => 400]);
    }
    
    $data = $request->get_json_params();
    $post_data = ['ID' => $id];
    
    // Validate and sanitize fields
    if (isset($data['title'])) {
        $post_data['post_title'] = sanitize_text_field($data['title']);
    }
    if (isset($data['content'])) {
        $post_data['post_content'] = wp_kses_post($data['content']);
    }
    if (isset($data['status'])) {
        // Allow custom statuses from PublishPress
        $builtin_statuses = ['draft', 'pending', 'private', 'publish'];
        $custom_statuses = array_keys(get_post_stati(['_builtin' => false], 'names'));
        $allowed_statuses = array_merge($builtin_statuses, $custom_statuses);
        $status = sanitize_text_field($data['status']);
        if (!in_array($status, $allowed_statuses, true)) {
            return new WP_Error('invalid_status', 'Invalid page status', ['status' => 400]);
        }
        $post_data['post_status'] = $status;
    }
    if (isset($data['slug'])) {
        $post_data['post_name'] = sanitize_title($data['slug']);
    }
    if (isset($data['parent'])) {
        $post_data['post_parent'] = (int) $data['parent'];
    }
    if (isset($data['menu_order'])) {
        $post_data['post_order'] = (int) $data['menu_order'];
    }
    
    $result = wp_update_post($post_data, true);
    if (is_wp_error($result)) return $result;
    return openclaw_format_page(get_post($id));
}

// Delete page
function openclaw_delete_page($request) {
    $id = (int) $request['id'];
    
    // Verify page exists and is correct type
    $page = get_post($id);
    if (!$page) {
        return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
    }
    if ($page->post_type !== 'page') {
        return new WP_Error('invalid_post_type', 'This endpoint only works with pages, not posts or other types', ['status' => 400]);
    }
    
    $force = $request->get_param('force') === 'true';
    $result = wp_delete_post($id, $force);
    if (!$result) {
        return new WP_Error('delete_failed', 'Failed to delete page', ['status' => 500]);
    }
    return ['deleted' => true, 'id' => $id];
}

// Format page for response
function openclaw_format_page($page) {
    if (!$page) {
        return null;
    }
    return [
        'id' => $page->ID,
        'title' => $page->post_title,
        'slug' => $page->post_name,
        'content' => $page->post_content,
        'status' => $page->post_status,
        'parent' => $page->post_parent,
        'menu_order' => $page->menu_order,
        'link' => get_permalink($page->ID),
    ];
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

// Upload plugin/theme from ZIP file
function openclaw_upload_plugin($request) {
    // Check file upload
    if (empty($_FILES['file'])) {
        return new WP_Error('missing_file', 'No file uploaded. Use multipart/form-data with a "file" field.', ['status' => 400]);
    }
    
    $file = $_FILES['file'];
    
    // Validate upload error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server maximum size',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form maximum size',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Server error: cannot write file',
            UPLOAD_ERR_EXTENSION => 'File upload blocked by server',
        ];
        return new WP_Error('upload_error', $errors[$file['error']] ?? 'Unknown upload error', ['status' => 400]);
    }
    
    // Validate actual file size (not user-provided)
    $max_size = 10 * 1024 * 1024; // 10MB
    $actual_size = filesize($file['tmp_name']);
    if ($actual_size === false || $actual_size > $max_size) {
        return new WP_Error('file_too_large', 'File exceeds 10MB limit', ['status' => 400]);
    }
    
    // Validate file extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        return new WP_Error('invalid_type', 'Only ZIP files are allowed', ['status' => 400]);
    }
    
    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowed_mimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
    if (!in_array($mime_type, $allowed_mimes, true)) {
        return new WP_Error('invalid_mime', 'Invalid file type. Expected ZIP.', ['status' => 400]);
    }
    
    // Extract and validate
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    
    WP_Filesystem();
    global $wp_filesystem;
    
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/openclaw-temp-' . uniqid();
    
    if (!$wp_filesystem->mkdir($temp_dir)) {
        return new WP_Error('mkdir_failed', 'Could not create temp directory', ['status' => 500]);
    }
    
    // Unzip
    $unzip_result = unzip_file($file['tmp_name'], $temp_dir);
    if (is_wp_error($unzip_result)) {
        $wp_filesystem->delete($temp_dir, true);
        return new WP_Error('unzip_failed', 'Could not extract ZIP: ' . $unzip_result->get_error_message(), ['status' => 400]);
    }
    
    // Find plugin/theme directory
    $items = $wp_filesystem->dirlist($temp_dir);
    if (empty($items)) {
        $wp_filesystem->delete($temp_dir, true);
        return new WP_Error('empty_zip', 'ZIP file is empty', ['status' => 400]);
    }
    
    // Get the first directory (plugin/theme root)
    $extracted_dir = null;
    $is_theme = false;
    $plugin_data = null;
    
    foreach ($items as $name => $item) {
        if ($item['type'] === 'd') {
            $extracted_dir = $temp_dir . '/' . $name;
            break;
        }
    }
    
    if (!$extracted_dir) {
        // Maybe files are at root (not in subfolder)
        $extracted_dir = $temp_dir;
    }
    
    // Security scan: check for suspicious files
    $suspicious_patterns = [
        '/\.php\.suspected$/i',
        '/\.phtml$/i',
        '/\.php\d+$/i',
        '/eval\s*\(/i',
        '/base64_decode\s*\(/i',
        '/gzinflate\s*\(/i',
        '/str_rot13\s*\(/i',
        '/shell_exec\s*\(/i',
        '/passthru\s*\(/i',
        '/system\s*\(/i',
    ];
    
    // Recursively scan files
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extracted_dir, RecursiveDirectoryIterator::SKIP_DOTS));
    $php_files = [];
    
    foreach ($iterator as $file_obj) {
        if ($file_obj->isFile()) {
            $filename = $file_obj->getFilename();
            $filepath = $file_obj->getPathname();
            
            // Check for suspicious filenames
            foreach ($suspicious_patterns as $pattern) {
                if (preg_match($pattern, $filename)) {
                    $wp_filesystem->delete($temp_dir, true);
                    return new WP_Error('suspicious_file', 'Suspicious file detected: ' . $filename, ['status' => 400]);
                }
            }
            
            // Collect PHP files for header scanning
            if (strtolower($file_obj->getExtension()) === 'php') {
                $php_files[] = $filepath;
            }
        }
    }
    
    // Check for valid plugin or theme
    $main_plugin_file = null;
    $style_css = $extracted_dir . '/style.css';
    
    // Check for theme
    if ($wp_filesystem->exists($style_css)) {
        $theme_data = get_file_data($style_css, [
            'Name' => 'Theme Name',
            'Version' => 'Version',
        ]);
        
        if (!empty($theme_data['Name'])) {
            $is_theme = true;
        }
    }
    
    // Check for plugin if not theme
    if (!$is_theme) {
        foreach ($php_files as $php_file) {
            $data = get_file_data($php_file, [
                'Name' => 'Plugin Name',
                'Version' => 'Version',
            ]);
            
            if (!empty($data['Name'])) {
                $main_plugin_file = $php_file;
                $plugin_data = $data;
                break;
            }
        }
        
        if (!$main_plugin_file) {
            $wp_filesystem->delete($temp_dir, true);
            return new WP_Error('invalid_package', 'No valid plugin or theme found in ZIP', ['status' => 400]);
        }
    }
    
    // Determine destination
    $slug = basename($extracted_dir);
    
    if ($is_theme) {
        $dest = get_theme_root() . '/' . $slug;
        $type = 'theme';
    } else {
        $dest = WP_PLUGIN_DIR . '/' . $slug;
        $type = 'plugin';
    }
    
    // Check if already exists
    if ($wp_filesystem->exists($dest)) {
        $wp_filesystem->delete($temp_dir, true);
        return new WP_Error('already_exists', ucfirst($type) . " '{$slug}' already exists. Delete it first or use update.", ['status' => 409]);
    }
    
    // Move to final location
    if (!$wp_filesystem->move($extracted_dir, $dest)) {
        $wp_filesystem->delete($temp_dir, true);
        return new WP_Error('move_failed', "Could not install {$type}", ['status' => 500]);
    }
    
    // Cleanup temp
    $wp_filesystem->delete($temp_dir, true);
    
    // Get final info
    $response = [
        'success' => true,
        'type' => $type,
        'slug' => $slug,
    ];
    
    if ($is_theme) {
        $theme_data = get_file_data($dest . '/style.css', ['Name' => 'Theme Name', 'Version' => 'Version']);
        $response['name'] = $theme_data['Name'];
        $response['version'] = $theme_data['Version'];
    } else {
        $response['name'] = $plugin_data['Name'];
        $response['version'] = $plugin_data['Version'];
        $response['file'] = basename($main_plugin_file);
    }
    
    // Activate if requested
    $activate = !empty($request->get_param('activate'));
    if ($activate && $type === 'plugin' && $main_plugin_file) {
        $plugin_path = $slug . '/' . basename($main_plugin_file);
        $activate_result = activate_plugin($plugin_path);
        if (!is_wp_error($activate_result)) {
            $response['active'] = true;
            $response['status'] = 'installed_and_activated';
        } else {
            $response['active'] = false;
            $response['activation_error'] = $activate_result->get_error_message();
        }
    } else {
        $response['active'] = false;
        $response['status'] = 'installed';
    }
    
    return new WP_REST_Response($response, 201);
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

// Core capabilities only (no module filter)
function openclaw_get_core_capabilities() {
    return [
        'posts_read' => true,
        'posts_create' => true,
        'posts_update' => true,
        'posts_delete' => false,
        'posts_set_author' => false,
        'pages_read' => true,
        'pages_create' => true,
        'pages_update' => true,
        'pages_delete' => false,
        'categories_read' => true,
        'categories_create' => true,
        'tags_read' => true,
        'tags_create' => true,
        'tags_update' => true,
        'tags_delete' => false,
        'media_read' => true,
        'media_upload' => false,
        'media_delete' => false,
        'users_read' => true,
        'plugins_read' => true,
        'plugins_search' => true,
        'plugins_install' => false,
        'plugins_activate' => false,
        'plugins_deactivate' => false,
        'plugins_update' => false,
        'plugins_delete' => false,
        'plugins_upload' => false,
        'site_info' => true,
        // Menus (v2.4.0)
        'menus_read' => true,
        'menus_create' => false,
        'menus_update' => false,
        'menus_delete' => false,
        // Themes (v2.4.0)
        'themes_read' => true,
        'themes_switch' => false,
        'themes_install' => false,
        'themes_delete' => false,
    ];
}

// Default capabilities (core + module via filter)
function openclaw_get_default_capabilities() {
    $defaults = openclaw_get_core_capabilities();
    
    // Allow modules to add their capabilities
    return apply_filters('openclaw_default_capabilities', $defaults);
}

// Get capabilities with defaults
function openclaw_get_capabilities() {
    $defaults = openclaw_get_default_capabilities();
    $saved = get_option('openclaw_api_capabilities', []);
    return wp_parse_args($saved, $defaults);
}

// Check if capability is allowed
function openclaw_can($capability) {
    // Validate capability name is a non-empty string
    if (!is_string($capability) || empty(trim($capability))) {
        return false;
    }
    
    $caps = openclaw_get_capabilities();
    return !empty($caps[$capability]);
}

// Encrypt GitHub token using WordPress encryption functions
function openclaw_encrypt_github_token($token) {
    if (function_exists('openssl_encrypt') && function_exists('openssl_decrypt')) {
        // Use WordPress secret key for encryption
        $secret_key = defined('AUTH_KEY') ? AUTH_KEY : substr(hash('sha256', AUTH_SALT . SECURE_AUTH_SALT), 0, 32);
        $iv = substr(hash('sha256', NONCE_SALT), 0, 16);
        
        $encrypted = openssl_encrypt($token, 'AES-256-CBC', $secret_key, 0, $iv);
        return base64_encode($encrypted);
    }
    
    // Fallback to simple encoding if OpenSSL is not available
    return base64_encode($token);
}

// Decrypt GitHub token
function openclaw_decrypt_github_token($encrypted_token) {
    if (empty($encrypted_token)) {
        return '';
    }
    
    if (function_exists('openssl_encrypt') && function_exists('openssl_decrypt')) {
        // Use the same keys for decryption
        $secret_key = defined('AUTH_KEY') ? AUTH_KEY : substr(hash('sha256', AUTH_SALT . SECURE_AUTH_SALT), 0, 32);
        $iv = substr(hash('sha256', NONCE_SALT), 0, 16);
        
        $decoded = base64_decode($encrypted_token);
        $decrypted = openssl_decrypt($decoded, 'AES-256-CBC', $secret_key, 0, $iv);
        return $decrypted;
    }
    
    // Fallback to simple decoding
    return base64_decode($encrypted_token);
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
        
        // Pass token directly to display - NO transient storage
        $new_token = $token;
        
        echo '<div class="notice notice-success"><p>Token generated! <strong>Copy it now - it will not be shown again.</strong></p></div>';
    }
    
    // Handle token deletion
    if (isset($_POST['openclaw_delete']) && check_admin_referer('openclaw_settings')) {
        delete_option('openclaw_api_token_hash');
        delete_option('openclaw_api_token'); // Remove any legacy plaintext token
        echo '<div class="notice notice-success"><p>Token deleted!</p></div>';
    }
    
    // Handle core capabilities save (only updates core, preserves module)
    if (isset($_POST['openclaw_save_core_caps']) && check_admin_referer('openclaw_capabilities')) {
        $existing_caps = get_option('openclaw_api_capabilities', []);
        $core_defaults = openclaw_get_core_capabilities();
        
        // Update only core capabilities
        foreach ($core_defaults as $key => $default) {
            $existing_caps[$key] = isset($_POST['openclaw_cap_' . $key]);
        }
        
        update_option('openclaw_api_capabilities', $existing_caps);
        echo '<div class="notice notice-success"><p>Core capabilities saved!</p></div>';
    }
    
    // Handle module capabilities save (only updates module, preserves core)
    if (isset($_POST['openclaw_save_module_caps']) && check_admin_referer('openclaw_capabilities')) {
        $existing_caps = get_option('openclaw_api_capabilities', []);
        $module_caps = openclaw_get_module_capabilities();
        
        // Update only module capabilities
        foreach ($module_caps as $key => $info) {
            $existing_caps[$key] = isset($_POST['openclaw_cap_' . $key]);
        }
        
        update_option('openclaw_api_capabilities', $existing_caps);
        echo '<div class="notice notice-success"><p>Module capabilities saved!</p></div>';
    }
    
    // Handle self-update
    if (isset($_POST['openclaw_self_update']) && check_admin_referer('openclaw_self_update')) {
        $plugin_file = 'openclaw-api/openclaw-api.php';
        
        // Get latest release
        $response = wp_remote_get('https://api.github.com/repos/openclaw/openclaw-api/releases/latest', [
            'headers' => ['User-Agent' => 'OpenClaw API Plugin'],
            'timeout' => 10,
        ]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $release = json_decode(wp_remote_retrieve_body($response), true);
            $download_url = null;
            
            if (!empty($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (strpos($asset['name'], '.zip') !== false) {
                        $download_url = $asset['browser_download_url'];
                        break;
                    }
                }
            }
            
            if ($download_url) {
                // Download to temp
                $temp_file = download_url($download_url);
                
                if (!is_wp_error($temp_file)) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                    
                    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
                    $result = $upgrader->run([
                        'package' => $temp_file,
                        'destination' => WP_PLUGIN_DIR . '/openclaw-api',
                        'clear_destination' => true,
                        'clear_working' => true,
                        'hook_extra' => ['plugin' => $plugin_file],
                    ]);
                    
                    @unlink($temp_file);
                    
                    if (!is_wp_error($result) && $result) {
                        echo '<div class="notice notice-success"><p>OpenClaw API updated successfully! <a href="' . esc_url(admin_url('plugins.php')) . '">View plugins</a></p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Update failed. Please update manually.</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Could not download update.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>No download URL found in release.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Could not check for updates from GitHub.</p></div>';
        }
    }
    
    // Check for newly generated token to display
    // Token only lives in this request's memory - never stored in database
    if (!$new_token) {
        // No token to display (normal page load)
    }
    
    $has_token = get_option('openclaw_api_token_hash') || get_option('openclaw_api_token');
    $caps = openclaw_get_capabilities();
    $defaults = openclaw_get_default_capabilities();
    
    // Group capabilities
    $groups = [
        'Posts' => ['posts_read', 'posts_create', 'posts_update', 'posts_delete', 'posts_set_author'],
        'Pages' => ['pages_read', 'pages_create', 'pages_update', 'pages_delete'],
        'Taxonomies' => ['categories_read', 'categories_create', 'tags_read', 'tags_create', 'tags_update', 'tags_delete'],
        'Media' => ['media_read', 'media_upload', 'media_delete'],
        'Users' => ['users_read'],
        'Plugins' => ['plugins_read', 'plugins_search', 'plugins_install', 'plugins_upload', 'plugins_activate', 'plugins_deactivate', 'plugins_update', 'plugins_delete'],
        'Menus' => ['menus_read', 'menus_create', 'menus_update', 'menus_delete'],
        'Themes' => ['themes_read', 'themes_switch', 'themes_install', 'themes_delete'],
        'Site' => ['site_info'],
    ];
    
    $cap_labels = [
        'posts_read' => 'Read Posts',
        'posts_create' => 'Create Posts',
        'posts_update' => 'Update Posts',
        'posts_delete' => 'Delete Posts',
        'posts_set_author' => 'Set Post Author',
        'pages_read' => 'Read Pages',
        'pages_create' => 'Create Pages',
        'pages_update' => 'Update Pages',
        'pages_delete' => 'Delete Pages',
        'categories_read' => 'Read Categories',
        'categories_create' => 'Create Categories',
        'tags_read' => 'Read Tags',
        'tags_create' => 'Create Tags',
        'tags_update' => 'Update Tags',
        'tags_delete' => 'Delete Tags',
        'media_read' => 'Read Media',
        'media_upload' => 'Upload Media',
        'media_delete' => 'Delete Media',
        'users_read' => 'Read Users',
        'plugins_read' => 'List Plugins',
        'plugins_search' => 'Search Plugins (WordPress.org)',
        'plugins_install' => 'Install Plugins',
        'plugins_upload' => 'Upload Plugins/Themes (ZIP)',
        'plugins_activate' => 'Activate Plugins',
        'plugins_deactivate' => 'Deactivate Plugins',
        'plugins_update' => 'Update Plugins',
        'plugins_delete' => 'Delete Plugins',
        'menus_read' => 'Read Menus',
        'menus_create' => 'Create Menus',
        'menus_update' => 'Update Menus',
        'menus_delete' => 'Delete Menus',
        'themes_read' => 'List Themes',
        'themes_switch' => 'Switch Theme',
        'themes_install' => 'Install Themes',
        'themes_delete' => 'Delete Themes',
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
            // Token shown once only - not stored anywhere
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
            <input type="hidden" name="openclaw_save_core_caps" value="1">
            
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
        
        <?php
        // Get detected module capabilities
        $module_caps = apply_filters('openclaw_module_capabilities', []);
        if (!empty($module_caps)): 
            // Group by module
            $module_groups = [];
            foreach ($module_caps as $cap => $info) {
                $group = $info['group'] ?? 'Modules';
                if (!isset($module_groups[$group])) {
                    $module_groups[$group] = [];
                }
                $module_groups[$group][$cap] = $info;
            }
        ?>
        <h2>Detected Integrations</h2>
        <p style="color:#666;">These capabilities are provided by detected plugins. Enable or disable as needed.</p>
        
        <form method="post">
            <?php wp_nonce_field('openclaw_capabilities'); ?>
            <input type="hidden" name="openclaw_save_module_caps" value="1">
            
            <table class="form-table">
                <?php foreach ($module_groups as $group_name => $group_caps): ?>
                <tr>
                    <th scope="row" style="padding-top:20px;font-weight:600;"><?php echo esc_html($group_name); ?></th>
                    <td style="padding-top:20px;">
                        <?php foreach ($group_caps as $cap => $info): ?>
                        <label style="display:inline-block;width:200px;margin-bottom:8px;">
                            <input type="checkbox" name="openclaw_cap_<?php echo esc_attr($cap); ?>" <?php checked(!empty($caps[$cap])); ?>>
                            <?php echo esc_html($info['label'] ?? $cap); ?>
                            <?php if (($info['default'] ?? false) === false): ?>
                                <span style="color:#d63638;font-size:11px;">(off by default)</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            
            <p class="submit">
                <input type="submit" value="Save Module Capabilities" class="button button-primary">
            </p>
        </form>
        <?php endif; ?>
        
        <h2>Plugin Updates</h2>
        <?php
        // Check for updates from GitHub
        $current_version = OPENCLAW_API_VERSION ?? '2.4.0';
        $update_available = false;
        $latest_version = $current_version;
        
        $response = wp_remote_get('https://api.github.com/repos/openclaw/openclaw-api/releases/latest', [
            'headers' => ['User-Agent' => 'OpenClaw API Plugin'],
            'timeout' => 5,
        ]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $release = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($release['tag_name'])) {
                $latest_version = ltrim($release['tag_name'], 'v');
                if (version_compare($latest_version, $current_version, '>')) {
                    $update_available = true;
                }
            }
        }
        ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">Installed Version</th>
                <td><code><?php echo esc_html($current_version); ?></code></td>
            </tr>
            <tr>
                <th scope="row">Latest Available</th>
                <td>
                    <code><?php echo esc_html($latest_version); ?></code>
                    <?php if ($update_available): ?>
                        <span style="color:#d63638;margin-left:10px;">⚡ Update available!</span>
                    <?php else: ?>
                        <span style="color:#00a32a;margin-left:10px;">✓ Up to date</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        
        <?php if ($update_available): ?>
        <form method="post">
            <?php wp_nonce_field('openclaw_self_update'); ?>
            <input type="hidden" name="openclaw_self_update" value="1">
            <p class="submit">
                <input type="submit" value="Update to <?php echo esc_attr($latest_version); ?>" class="button button-primary" onclick="return confirm('Update OpenClaw API to version <?php echo esc_attr($latest_version); ?>? This will overwrite the existing files.');">
            </p>
        </form>
        <?php endif; ?>
        
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
                <tr><td>PUT</td><td><code>/pages/{id}</code></td><td>pages_update</td><td>Update page</td></tr>
                <tr><td>DELETE</td><td><code>/pages/{id}</code></td><td>pages_delete</td><td>Delete page</td></tr>
                <tr><td>GET</td><td><code>/categories</code></td><td>categories_read</td><td>List categories</td></tr>
                <tr><td>POST</td><td><code>/categories</code></td><td>categories_create</td><td>Create category</td></tr>
                <tr><td>GET</td><td><code>/tags</code></td><td>tags_read</td><td>List tags</td></tr>
                <tr><td>POST</td><td><code>/tags</code></td><td>tags_create</td><td>Create tag</td></tr>
                <tr><td>PUT</td><td><code>/tags/{id}</code></td><td>tags_update</td><td>Update tag name</td></tr>
                <tr><td>DELETE</td><td><code>/tags/{id}</code></td><td>tags_delete</td><td>Delete tag</td></tr>
                <tr><td>GET</td><td><code>/media</code></td><td>media_read</td><td>List media</td></tr>
                <tr><td>POST</td><td><code>/media</code></td><td>media_upload</td><td>Upload image (multipart/form-data)</td></tr>
                <tr><td>DELETE</td><td><code>/media/{id}</code></td><td>media_delete</td><td>Delete media</td></tr>
                <tr><td>GET</td><td><code>/users</code></td><td>users_read</td><td>List users</td></tr>
                <tr><td>GET</td><td><code>/plugins</code></td><td>plugins_read</td><td>List installed plugins</td></tr>
                <tr><td>GET</td><td><code>/plugins/search</code></td><td>plugins_search</td><td>Search WordPress.org</td></tr>
                <tr><td>POST</td><td><code>/plugins/install</code></td><td>plugins_install</td><td>Install plugin</td></tr>
                <tr><td>POST</td><td><code>/plugins/upload</code></td><td>plugins_upload</td><td>Upload plugin/theme (ZIP)</td></tr>
                <tr><td>POST</td><td><code>/plugins/{slug}/activate</code></td><td>plugins_activate</td><td>Activate plugin</td></tr>
                <tr><td>POST</td><td><code>/plugins/{slug}/deactivate</code></td><td>plugins_deactivate</td><td>Deactivate plugin</td></tr>
                <tr><td>POST</td><td><code>/plugins/{slug}/update</code></td><td>plugins_update</td><td>Update plugin</td></tr>
                <tr><td>DELETE</td><td><code>/plugins/{slug}</code></td><td>plugins_delete</td><td>Delete plugin</td></tr>
                <tr><td colspan="4" style="background:#f0f0f1;font-weight:bold;">Menus (v2.4.0)</td></tr>
                <tr><td>GET</td><td><code>/menus</code></td><td>menus_read</td><td>List all menus</td></tr>
                <tr><td>GET</td><td><code>/menus/{id}</code></td><td>menus_read</td><td>Get menu with items</td></tr>
                <tr><td>POST</td><td><code>/menus</code></td><td>menus_create</td><td>Create menu</td></tr>
                <tr><td>PUT</td><td><code>/menus/{id}</code></td><td>menus_update</td><td>Update menu name</td></tr>
                <tr><td>DELETE</td><td><code>/menus/{id}</code></td><td>menus_delete</td><td>Delete menu</td></tr>
                <tr><td>POST</td><td><code>/menus/{id}/items</code></td><td>menus_update</td><td>Add menu item</td></tr>
                <tr><td>DELETE</td><td><code>/menus/{id}/items/{item_id}</code></td><td>menus_update</td><td>Delete menu item</td></tr>
                <tr><td>PUT</td><td><code>/menus/{id}/items</code></td><td>menus_update</td><td>Reorder menu items</td></tr>
                <tr><td colspan="4" style="background:#f0f0f1;font-weight:bold;">Themes (v2.4.0)</td></tr>
                <tr><td>GET</td><td><code>/theme</code></td><td>themes_read</td><td>Get active theme</td></tr>
                <tr><td>GET</td><td><code>/themes</code></td><td>themes_read</td><td>List installed themes</td></tr>
                <tr><td>PUT</td><td><code>/theme</code></td><td>themes_switch</td><td>Switch active theme</td></tr>
                <tr><td>POST</td><td><code>/theme/install</code></td><td>themes_install</td><td>Install theme from ZIP URL</td></tr>
                <tr><td>DELETE</td><td><code>/themes/{stylesheet}</code></td><td>themes_delete</td><td>Delete theme</td></tr>
                <tr><td colspan="4" style="background:#f0f0f1;font-weight:bold;">Self-Update</td></tr>
                <tr><td>POST</td><td><code>/self-update</code></td><td>plugins_update</td><td>Self-update OpenClaw API</td></tr>
                <tr><td>POST</td><td><code>/self-update-from-url</code></td><td>plugins_update</td><td>Self-update from trusted URL</td></tr>
            </tbody>
        </table>
    </div>
    <?php
}