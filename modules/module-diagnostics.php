<?php
/**
 * JRB Remote Site API - Diagnostics Module
 * 
 * Provides site health and server insights via the v6.3.0+ dynamic bridge.
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('openclaw/v1', '/diagnostics/health', [
        'methods'             => 'GET',
        'callback'            => 'jrb_remote_get_site_health',
        'permission_callback' => 'openclaw_api_verify_token',
    ]);

    register_rest_route('openclaw/v1', '/diagnostics/server', [
        'methods'             => 'GET',
        'callback'            => 'jrb_remote_get_server_info',
        'permission_callback' => 'openclaw_api_verify_token',
    ]);
});

/**
 * Get WordPress Site Health simplified report
 */
function jrb_remote_get_site_health() {
    try {
        if ( ! class_exists( 'WP_Site_Health' ) ) {
            // Include the file manually if it's not already loaded
            $site_health_file = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
            if ( file_exists($site_health_file) ) {
                require_once $site_health_file;
            }
        }

        $health_data = [
            'status' => 'operational',
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
            'active_theme' => get_stylesheet(),
        ];

        // Only try to get health instance if class was found
        if ( class_exists( 'WP_Site_Health' ) ) {
            $health = WP_Site_Health::get_instance();
            // We just confirm it's available for now
            $health_data['health_engine'] = 'WP_Site_Health';
        }

        return new WP_REST_Response($health_data, 200);

    } catch (Throwable $e) {
        return new WP_REST_Response(['error' => $e->getMessage()], 500);
    }
}

/**
 * Get Server-Level Environment Info
 */
function jrb_remote_get_server_info() {
    return new WP_REST_Response([
        'os' => PHP_OS,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'memory_limit' => ini_get('memory_limit'),
        'post_max_size' => ini_get('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'max_execution_time' => ini_get('max_execution_time'),
        'disk_free_space' => function_exists('disk_free_space') ? round(@disk_free_space(ABSPATH) / 1024 / 1024 / 1024, 2) . ' GB' : 'N/A',
    ], 200);
}
