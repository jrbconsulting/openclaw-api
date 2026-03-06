<?php
/**
 * 🛠️ PSR-4 Feature Preservation Migration Plan: JRB Remote Site API (v6.4.0)
 * 
 * GOAL: Move legacy logic to PSR-4 Handlers WITHOUT removing any existing functionality.
 * 
 * MANDATORY FEATURES TO PRESERVE:
 * 1. Automatic Token Generation (wp_generate_password logic)
 * 2. Installed Plugin Detection (FluentCRM, Support, Forms, etc.)
 * 3. Dynamic Capability Matrix (The grouping/checkboxes in the UI)
 * 4. GitHub Release Update Integration
 * 5. Legacy Namespace (openclaw/v1) and Header (X-OpenClaw-Token) compatibility.
 */

namespace JRB\RemoteApi\Handlers;

if (!defined('ABSPATH')) exit;

class AdminHandler {
    
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        // Ensure the legacy option names are used so no data is lost
    }

    public static function add_menu() {
        add_options_page(
            'JRB Remote API',
            'JRB Remote API',
            'manage_options',
            'jrb-remote-site-api-for-openclaw',
            [self::class, 'render_settings_page']
        );
    }

    /**
     * RESTORED: This function must be a 1:1 functional clone of the legacy openclaw_api_settings_page()
     */
    public static function render_settings_page() {
        // Logic will be copied IDENTICALLY from the legacy jrb-remote-site-api-openclaw.php
        // Including:
        // - if (isset($_POST['openclaw_generate'])) ...
        // - if (isset($_POST['openclaw_save_caps'])) ...
        // - self::get_grouped_capabilities() which includes detection logic.
    }
}
