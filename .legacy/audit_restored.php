<?php
/**
 * Security Audit for JRB Remote Site API (Legacy Restore)
 */
require_once __DIR__ . '/tests/wp-mock.php';
$_SERVER['HTTP_X_OPENCLAW_TOKEN'] = 'secret';
$GLOBALS['wp_options']['openclaw_api_token_hash'] = wp_hash('secret');
$GLOBALS['wp_options']['openclaw_api_capabilities'] = ['site_info' => true];

require_once __DIR__ . '/jrb-remote-site-api-for-openclaw.php';

echo "--- LEGACY SECURITY AUDIT START ---\n";

// 1. Token Verification
if (openclaw_verify_token() === true) {
    echo "✅ PASS: Token verification works.\n";
} else {
    echo "❌ FAIL: Token verification failed.\n";
}

// 2. Capability Check
if (openclaw_can('site_info') === true && openclaw_can('posts_delete') === false) {
    echo "✅ PASS: Capability check works.\n";
} else {
    echo "❌ FAIL: Capability check failed.\n";
}

// 3. Plugin Detection
if (function_exists('openclaw_is_plugin_active')) {
    echo "✅ PASS: Plugin detection feature exists.\n";
} else {
    echo "❌ FAIL: Plugin detection feature missing.\n";
}

// 4. SQL Check (modules)
$sql_concats = shell_exec("grep -r \"\$wpdb->\" modules/ | grep -v \"prepare\" | grep \"\\$\"");
if (empty($sql_concats)) {
    echo "✅ PASS: No direct variable concatenation in SQL found.\n";
} else {
    echo "⚠️ WARN: Found potential SQL concatenation in modules. Reviewing...\n";
    echo $sql_concats;
}

echo "--- AUDIT COMPLETE ---\n";
