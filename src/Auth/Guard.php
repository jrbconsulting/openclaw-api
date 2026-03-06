<?php
namespace JRB\RemoteApi\Auth;

if (!defined('ABSPATH')) exit;

/**
 * 🔐 Guard Component
 * Unified authentication with backward compatibility
 * Optimized v6.5.1 with capability caching and performance improvements
 *
 * Primary: X-JRBRemoteSite-Token header + openclaw_api_token_hash
 * Legacy: X-JRB-Token header + jrbremote_api_token_hash (migrated on first use)
 *
 * @since 6.5.1
 * @deprecated 7.0.0 Legacy token storage will be removed
 */
class Guard {

    /**
     * 🚀 OPTIMIZATION: Static cache for token verification results
     * Prevents repeated database lookups within same request
     *
     * @var bool|null
     */
    private static $token_verified_cache = null;

    /**
     * 🚀 OPTIMIZATION: Static cache for capabilities
     * Avoids repeated get_option() calls
     *
     * @var array|null
     */
    private static $capabilities_cache = null;

    /**
     * 🚀 OPTIMIZATION: Cache expiry time in seconds
     */
    const CACHE_TTL = 300; // 5 minutes

    /**
     * Verify API token with backward compatibility
     *
     * @return bool|\WP_Error True on success, WP_Error on failure
     */
    public static function verify_token() {
        // 🚀 OPTIMIZATION: Return cached result if available (same request)
        if (self::$token_verified_cache !== null) {
            return self::$token_verified_cache;
        }

        // 🚀 OPTIMIZATION: Check transient cache first (cross-request caching)
        $cached_result = get_transient('jrb_token_verified');
        if ($cached_result === 'valid') {
            self::$token_verified_cache = true;
            return true;
        }

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
            self::$token_verified_cache = new \WP_Error('missing_header', 'Missing X-JRBRemoteSite-Token header (or legacy X-JRB-Token)', ['status' => 401]);
            return self::$token_verified_cache;
        }

        // Check PRIMARY token storage (openclaw_api_token_hash)
        $token_hash = get_option('openclaw_api_token_hash');
        if (!empty($token_hash)) {
            if (hash_equals($token_hash, wp_hash($header))) {
                // 🚀 OPTIMIZATION: Cache successful verification for 5 minutes
                set_transient('jrb_token_verified', 'valid', self::CACHE_TTL);
                self::$token_verified_cache = true;
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

                // 🚀 OPTIMIZATION: Cache successful verification
                set_transient('jrb_token_verified', 'valid', self::CACHE_TTL);
                self::$token_verified_cache = true;
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

                // 🚀 OPTIMIZATION: Cache successful verification
                set_transient('jrb_token_verified', 'valid', self::CACHE_TTL);
                self::$token_verified_cache = true;
                return true;
            }
        }

        // Cache failure result
        self::$token_verified_cache = new \WP_Error('invalid_token', 'Invalid API token', ['status' => 401]);
        return self::$token_verified_cache;
    }

    /**
     * 🚀 OPTIMIZATION: Check if capability is enabled with caching
     *
     * @param string $capability Capability name
     * @return bool
     */
    public static function can($capability) {
        // 🚀 OPTIMIZATION: Return cached capabilities if available
        if (self::$capabilities_cache === null) {
            // Try transient cache first
            $cached = get_transient('jrb_capabilities_cache');
            if ($cached !== false) {
                self::$capabilities_cache = $cached;
            } else {
                // Load from database
                $caps = get_option('openclaw_api_capabilities', []);

                // Fallback to legacy option key
                if (empty($caps)) {
                    $caps = get_option('jrbremote_api_capabilities', []);
                }

                // Cache in memory and transient
                self::$capabilities_cache = $caps;
                set_transient('jrb_capabilities_cache', $caps, self::CACHE_TTL);
            }
        }

        return !empty(self::$capabilities_cache[$capability]);
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
     * 🚀 OPTIMIZATION: Clear all auth caches
     * Call this when capabilities or tokens are updated
     */
    public static function clear_auth_cache() {
        self::$token_verified_cache = null;
        self::$capabilities_cache = null;
        delete_transient('jrb_token_verified');
        delete_transient('jrb_capabilities_cache');
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

        // 🚀 OPTIMIZATION: Clear caches after migration
        self::clear_auth_cache();
    }
}
