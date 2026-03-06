<?php
/**
 * JRB Remote Site API - Dynamic Module Loader
 * 
 * Automatically loads all module-*.php files from the modules directory.
 * Includes safety checks to prevent double-loading or unauthorized execution.
 */

if (!defined('ABSPATH')) exit;

class JRB_Remote_Module_Loader {
    public static function init() {
        $module_path = plugin_dir_path(__FILE__);
        $modules = glob($module_path . 'module-*.php');

        if (!empty($modules)) {
            foreach ($modules as $module) {
                // Security check: Verify file exists and is readable
                if (file_exists($module) && is_readable($module)) {
                    include_once $module;
                }
            }
        }
    }
}

// Initialize the loader
JRB_Remote_Module_Loader::init();
