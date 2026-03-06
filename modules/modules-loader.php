<?php
/**
 * JRB Remote Site API - Dynamic Module Loader v6.5.0
 * 
 * Implements conditional module loading based on dependency detection.
 * - Media + Diagnostics: Always loaded (core features)
 * - FluentCRM: Only if FluentCRM plugin active
 * - FluentSupport: Only if Fluent Support plugin active
 * - FluentForms: Only if FluentForms plugin active
 * - FluentProject: Only if FluentBoards/FluentProject active
 * - FluentCommunity: Only if FluentCommunity plugin active
 * - PublishPress: Only if PublishPress Statuses active
 * - Auth: Always loaded (helper module)
 * 
 * Features:
 * - Defensive module loading with error logging
 * - Module registration verification
 * - Runtime health checks
 * - Auto-recovery mechanism
 * - /diagnostics/modules endpoint
 */

if (!defined('ABSPATH')) exit;

class JRB_Remote_Module_Loader {
    
    /**
     * Modules that are always loaded (core features)
     */
    private static $core_modules = [
        'module-media.php',
        'module-diagnostics.php',
        'module-auth.php',
    ];
    
    /**
     * Conditional modules with their dependency checks
     * Format: filename => detection_callback
     */
    private static $conditional_modules = [
        'module-fluentcrm.php' => 'openclaw_is_plugin_active_fluentcrm',
        'module-fluentsupport.php' => 'openclaw_is_plugin_active_fluentsupport',
        'module-fluentforms.php' => 'openclaw_is_plugin_active_fluentforms',
        'module-fluentproject.php' => 'openclaw_is_plugin_active_fluentproject',
        'module-fluentcommunity.php' => 'openclaw_is_plugin_active_fluentcommunity',
        'module-publishpress.php' => 'openclaw_is_plugin_active_publishpress',
    ];
    
    /**
     * Track loaded modules for diagnostics
     */
    private static $loaded_modules = [];
    
    /**
     * Track failed modules for diagnostics
     */
    private static $failed_modules = [];
    
    /**
     * Initialize the module loader
     */
    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'load_modules'], 5);
        add_action('admin_init', [__CLASS__, 'health_check'], 100);
        add_action('rest_api_init', [__CLASS__, 'register_diagnostics_endpoint']);
        
        error_log('[JRB Module Loader v6.5.0] Initialized');
    }
    
    /**
     * Load all modules with dependency checking
     */
    public static function load_modules() {
        $module_path = plugin_dir_path(__FILE__);
        
        error_log('[JRB Module Loader] Starting module scan...');
        
        // === Load Core Modules (unconditional) ===
        foreach (self::$core_modules as $module_file) {
            $module = $module_path . $module_file;
            self::load_module($module, $module_file, true);
        }
        
        // === Load Conditional Modules (dependency check) ===
        foreach (self::$conditional_modules as $module_file => $check_callback) {
            $module = $module_path . $module_file;
            
            if (!file_exists($module)) {
                error_log("[JRB Module Loader] SKIP: {$module_file} (file not found)");
                continue;
            }
            
            // Check dependency
            $dependency_active = false;
            if (function_exists($check_callback)) {
                $dependency_active = call_user_func($check_callback);
            } elseif (function_exists('openclaw_is_plugin_active')) {
                // Map to plugin slug
                $slug_map = [
                    'module-fluentcrm.php' => 'fluentcrm',
                    'module-fluentsupport.php' => 'fluentsupport',
                    'module-fluentforms.php' => 'fluentforms',
                    'module-fluentproject.php' => 'fluentboards',
                    'module-fluentcommunity.php' => 'fluentcommunity',
                    'module-publishpress.php' => 'publishpress-statuses',
                ];
                if (isset($slug_map[$module_file])) {
                    $dependency_active = openclaw_is_plugin_active($slug_map[$module_file]);
                }
            }
            
            if ($dependency_active) {
                self::load_module($module, $module_file, false, $slug_map[$module_file] ?? 'unknown');
            } else {
                error_log("[JRB Module Loader] SKIP: {$module_file} (dependency not active)");
            }
        }
        
        error_log('[JRB Module Loader] Module scan complete. Loaded: ' . count(self::$loaded_modules) . ', Failed: ' . count(self::$failed_modules));
        
        // Store loaded modules info for diagnostics
        set_transient('jrb_loaded_modules', self::$loaded_modules, HOUR_IN_SECONDS);
        set_transient('jrb_failed_modules', self::$failed_modules, HOUR_IN_SECONDS);
    }
    
    /**
     * Load a single module with defensive checks
     */
    private static function load_module($path, $filename, $is_core = false, $dependency = null) {
        if (!file_exists($path)) {
            $error = "File not found";
            error_log("[JRB Module Loader] FAILED: {$filename} - {$error}");
            self::$failed_modules[$filename] = [
                'error' => $error,
                'is_core' => $is_core,
                'dependency' => $dependency,
            ];
            return false;
        }
        
        if (!is_readable($path)) {
            $error = "File not readable (check permissions)";
            error_log("[JRB Module Loader] FAILED: {$filename} - {$error}");
            self::$failed_modules[$filename] = [
                'error' => $error,
                'is_core' => $is_core,
                'dependency' => $dependency,
            ];
            return false;
        }
        
        // Get file info for diagnostics
        $file_size = filesize($path);
        $file_mtime = filemtime($path);
        
        error_log("[JRB Module Loader] Loading: {$filename} (core: " . ($is_core ? 'yes' : 'no') . ", size: " . round($file_size / 1024, 2) . "KB)");
        
        // Defensive loading with try-catch for parse errors
        $load_start = microtime(true);
        $load_error = null;
        
        try {
            include_once $path;
        } catch (Throwable $e) {
            $load_error = $e->getMessage();
            error_log("[JRB Module Loader] EXCEPTION loading {$filename}: " . $e->getMessage());
        }
        
        $load_time = round((microtime(true) - $load_start) * 1000, 2);
        
        if ($load_error) {
            self::$failed_modules[$filename] = [
                'error' => $load_error,
                'is_core' => $is_core,
                'dependency' => $dependency,
                'load_time_ms' => $load_time,
            ];
            return false;
        }
        
        // Verify module registered successfully
        self::$loaded_modules[$filename] = [
            'loaded' => true,
            'is_core' => $is_core,
            'dependency' => $dependency,
            'size_bytes' => $file_size,
            'modified' => date('Y-m-d H:i:s', $file_mtime),
            'load_time_ms' => $load_time,
            'loaded_at' => date('Y-m-d H:i:s'),
        ];
        
        error_log("[JRB Module Loader] ✓ Loaded: {$filename} in {$load_time}ms");
        return true;
    }
    
    /**
     * Runtime health check - verifies critical modules are registered
     */
    public static function health_check() {
        // Only run once per day
        $last_check = get_option('jrb_module_health_check', 0);
        if (time() - $last_check < DAY_IN_SECONDS) {
            return;
        }
        
        update_option('jrb_module_health_check', time());
        
        error_log('[JRB Module Loader] Running health check...');
        
        $health_status = [
            'check_time' => current_time('mysql'),
            'issues' => [],
            'recovery_actions' => [],
        ];
        
        // Check if media routes are registered
        if (function_exists('rest_get_server')) {
            $routes = rest_get_server()->get_routes();
            $has_media_route = false;
            
            foreach ($routes as $route => $handlers) {
                if (strpos($route, '/openclaw/v1/media') !== false) {
                    $has_media_route = true;
                    break;
                }
            }
            
            if (!$has_media_route) {
                $health_status['issues'][] = 'Media routes not registered';
                error_log('[JRB Module Loader] WARNING: Media routes not registered!');
                
                // Attempt recovery
                $recovery_result = self::recover_media_module();
                if ($recovery_result) {
                    $health_status['recovery_actions'][] = 'Media module reloaded successfully';
                    error_log('[JRB Module Loader] Media module recovered');
                } else {
                    $health_status['recovery_actions'][] = 'Media module recovery failed - manual intervention may be required';
                }
            }
        }
        
        // Store health status
        update_option('jrb_module_health_status', $health_status);
        
        // Show admin notice if issues found
        if (!empty($health_status['issues'])) {
            add_action('admin_notices', function() use ($health_status) {
                echo '<div class="notice notice-warning">';
                echo '<p><strong>JRB Remote API Module Health Check:</strong></p>';
                echo '<ul>';
                foreach ($health_status['issues'] as $issue) {
                    echo '<li>' . esc_html($issue) . '</li>';
                }
                foreach ($health_status['recovery_actions'] as $action) {
                    echo '<li>Recovery: ' . esc_html($action) . '</li>';
                }
                echo '</ul>';
                echo '</div>';
            });
        }
    }
    
    /**
     * Attempt to recover the media module
     */
    private static function recover_media_module() {
        $module_path = plugin_dir_path(__FILE__) . 'module-media.php';
        
        if (!file_exists($module_path)) {
            error_log('[JRB Module Loader] Recovery failed: module-media.php not found');
            return false;
        }
        
        // Re-include the module
        try {
            include_once $module_path;
            error_log('[JRB Module Loader] Media module reloaded during recovery');
            return true;
        } catch (Throwable $e) {
            error_log('[JRB Module Loader] Recovery exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Register diagnostics endpoint for module status
     */
    public static function register_diagnostics_endpoint() {
        register_rest_route('openclaw/v1', '/diagnostics/modules', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_modules_status'],
            'permission_callback' => function() {
                return jrb_verify_token_and_can('site_info');
            },
        ]);
    }
    
    /**
     * Get module status for diagnostics endpoint
     */
    public static function get_modules_status() {
        // Get fresh data from transients or regenerate
        $loaded = get_transient('jrb_loaded_modules');
        $failed = get_transient('jrb_failed_modules');
        
        if ($loaded === false) {
            $loaded = self::$loaded_modules;
        }
        if ($failed === false) {
            $failed = self::$failed_modules;
        }
        
        // Get registered routes
        $routes = [];
        if (function_exists('rest_get_server')) {
            $all_routes = rest_get_server()->get_routes();
            foreach ($all_routes as $route => $handlers) {
                if (strpos($route, '/openclaw/v1/') !== false) {
                    $routes[] = $route;
                }
            }
        }
        
        // Get health status
        $health_status = get_option('jrb_module_health_status', []);
        
        return [
            'loaded_modules' => $loaded,
            'failed_modules' => $failed,
            'registered_routes' => $routes,
            'route_count' => count($routes),
            'health_status' => $health_status,
            'rest_api_init_fired' => did_action('rest_api_init') > 0,
            'plugins_loaded_fired' => did_action('plugins_loaded') > 0,
            'timestamp' => current_time('mysql'),
        ];
    }
    
    /**
     * Get list of loaded modules
     */
    public static function get_loaded_modules() {
        return array_keys(self::$loaded_modules);
    }
}

/**
 * Dependency detection callbacks
 */

function jrb_is_plugin_active_fluentcrm() {
    if (function_exists('openclaw_is_plugin_active')) {
        return openclaw_is_plugin_active('fluentcrm');
    }
    return class_exists('FluentCRM\App\Models\Subscriber') || class_exists('FluentCrm\App\Models\Subscriber');
}

function jrb_is_plugin_active_fluentsupport() {
    if (function_exists('openclaw_is_plugin_active')) {
        return openclaw_is_plugin_active('fluentsupport');
    }
    return class_exists('FluentSupport\App\Models\Ticket');
}

function jrb_is_plugin_active_fluentforms() {
    if (function_exists('openclaw_is_plugin_active')) {
        return openclaw_is_plugin_active('fluentforms');
    }
    return class_exists('FluentForm\App\Models\Form');
}

function jrb_is_plugin_active_fluentproject() {
    if (function_exists('openclaw_is_plugin_active')) {
        return openclaw_is_plugin_active('fluentboards');
    }
    return class_exists('FluentBoards\App\Hooks') || get_post_type_object('fproject') !== null;
}

function jrb_is_plugin_active_fluentcommunity() {
    if (function_exists('openclaw_is_plugin_active')) {
        return openclaw_is_plugin_active('fluentcommunity');
    }
    return get_post_type_object('fcom_post') !== null;
}

function jrb_is_plugin_active_publishpress() {
    if (function_exists('openclaw_is_plugin_active')) {
        return openclaw_is_plugin_active('publishpress-statuses');
    }
    return class_exists('PublishPress_Statuses');
}

// Initialize the loader
JRB_Remote_Module_Loader::init();
