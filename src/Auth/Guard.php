<?php
namespace JRB\RemoteApi\Auth;

if (!defined('ABSPATH')) exit;

/**
 * 🔐 Guard Component
 * Unified authentication with backward compatibility
 * 
 * Primary: X-JRBRemoteSite-Token header + openclaw_api_token_hash
 * Legacy: X-JRB-Token header + jrbremote_api_token_hash (migrated on first use)
 * 
 * @since 6.5.1
 * @deprecated 7.0.0 Legacy token storage will be removed
 */
class Guard {
    
    /**
     * Verify API token with backward compatibility
     * 
     * @return bool|\WP_Error True on success, WP_Error on failure
     */
    public static function verify_token() {
        // Try primary header first
        $header = isset($_SERVER['HTTP_X_JRBREMOTESITE_TOKEN']) 
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_JRBREMOTESITE_TOKEN']))
            : '';
        
        // Fallback to legacy header
        if (empty($header)) {
            $header = isset($_SERVER['HTTP_X_JRB_TOKEN']) 
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_JRB_TOKEN']))
                : '';
        }
        
        if (empty($header)) {
            return new \WP_Error('missing_header', 'Missing X-JRBRemoteSite-Token header (or legacy X-JRB-Token)', ['status' => 401]);
        }
        
        // Check PRIMARY token storage (openclaw_api_token_hash)
        $token_hash = get_option('openclaw_api_token_hash');
        if (!empty($token_hash)) {
            if (hash_equals($token_hash, wp_hash($header))) {
                return true;
            }
        }
        
        // MIGRATION: Check legacy token storage and migrate if valid
        $legacy_hash = get_option('jrbremote_api_token_hash');
        if (!empty($legacy_hash)) {
            if (hash_equals($legacy_hash, wp_hash($header))) {
                // Migrate to primary storage
                update_option('openclaw_api_token_hash', $legacy_hash);
                delete_option('jrbremote_api_token_hash');
                delete_option('jrbremote_api_token'); // Remove any legacy plaintext
                
                // Log migration event
                error_log('[JRB Remote API] Auth token migrated from jrbremote_api_token_hash to openclaw_api_token_hash');
                
                return true;
            }
        }
        
        // Legacy plaintext token check (for very old installations - will be removed in 7.0.0)
        $legacy_token = get_option('openclaw_api_token');
        if (!empty($legacy_token)) {
            if (hash_equals($legacy_token, $header)) {
                // Migrate plaintext to hashed
                update_option('openclaw_api_token_hash', wp_hash($legacy_token));
                delete_option('openclaw_api_token');
                
                error_log('[JRB Remote API] Plaintext token migrated to hashed storage');
                
                return true;
            }
        }
        
        return new \WP_Error('invalid_token', 'Invalid API token', ['status' => 401]);
    }

    /**
     * Check if capability is enabled
     * 
     * @param string $capability Capability name
     * @return bool
     */
    public static function can($capability) {
        $caps = get_option('openclaw_api_capabilities', []);
        
        // Fallback to legacy option key
        if (empty($caps)) {
            $caps = get_option('jrbremote_api_capabilities', []);
        }
        
        return !empty($caps[$capability]);
    }

    /**
     * Verify token AND check capability
     * 
     * @param string $capability Required capability
     * @return bool|\WP_Error
     */
    public static function verify_token_and_can($capability) {
        $verified = self::verify_token();
        if (is_wp_error($verified)) {
            return $verified;
        }
        
        if (!self::can($capability)) {
            return new \WP_Error('forbidden', "Access denied for capability: $capability", ['status' => 403]);
        }
        
        return true;
    }
    
    /**
     * Migration helper - run on plugin update
     * Migrates all legacy auth data to primary storage
     * 
     * @since 6.5.1
     */
    public static function migrate_legacy_auth() {
        $old_hash = get_option('jrbremote_api_token_hash');
        $new_hash = get_option('openclaw_api_token_hash');
        
        // Migrate token hash if new doesn't exist
        if ($old_hash && !$new_hash) {
            update_option('openclaw_api_token_hash', $old_hash);
            delete_option('jrbremote_api_token_hash');
            error_log('[JRB Remote API] Token hash migrated during update');
        }
        
        // Migrate capabilities
        $old_caps = get_option('jrbremote_api_capabilities');
        $new_caps = get_option('openclaw_api_capabilities');
        
        if ($old_caps && !$new_caps) {
            update_option('openclaw_api_capabilities', $old_caps);
            // Keep legacy caps for backward compat during transition
            error_log('[JRB Remote API] Capabilities migrated during update');
        }
        
        // Remove legacy plaintext tokens (security cleanup)
        delete_option('jrbremote_api_token');
        delete_option('openclaw_api_token');
    }
}
