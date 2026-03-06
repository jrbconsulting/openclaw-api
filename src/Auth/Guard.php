<?php
namespace JRB\RemoteApi\Auth;

if (!defined('ABSPATH')) exit;

/**
 * 🔐 Guard Component
 * Handles exact legacy auth logic using existing option keys.
 */
class Guard {
    
    public static function verify_token() {
        // EXACT CLONE OF LEGACY AUTH LOGIC
        $header = isset($_SERVER['HTTP_X_JRB_TOKEN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_JRB_TOKEN'])) : '';
        
        if (empty($header)) {
            return new \WP_Error('missing_header', 'Missing X-JRB-Token header', ['status' => 401]);
        }
        
        // Use existing legacy keys
        $token_hash = get_option('jrbremote_api_token_hash');
        if (!empty($token_hash)) {
            if (hash_equals($token_hash, wp_hash($header))) return true;
        }
        
        $legacy_token = get_option('jrbremote_api_token');
        if (!empty($legacy_token)) {
            if (hash_equals($legacy_token, $header)) {
                update_option('jrbremote_api_token_hash', wp_hash($legacy_token));
                delete_option('jrbremote_api_token');
                return true;
            }
        }
        
        return new \WP_Error('invalid_token', 'Invalid API token', ['status' => 401]);
    }

    public static function can($capability) {
        $caps = get_option('jrbremote_api_capabilities', []);
        return !empty($caps[$capability]);
    }

    public static function verify_token_and_can($capability) {
        $verified = self::verify_token();
        if (is_wp_error($verified)) return $verified;
        
        if (!self::can($capability)) {
            return new \WP_Error('forbidden', "Access denied for capability: $capability", ['status' => 403]);
        }
        return true;
    }
}
