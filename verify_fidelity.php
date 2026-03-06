<?php
/**
 * 🕵️ FEATURE FIDELITY CHECK - v6.4.0 REFAC
 */
require_once __DIR__ . '/tests/wp-mock.php';
require_once __DIR__ . '/src/Handlers/AdminHandler.php';

use JRB\RemoteApi\Handlers\AdminHandler;

echo "\n--- FEATURE FIDELITY AUDIT START ---\n";

// 1. Check for Token Generation logic in the Admin Handler
$admin_code = file_get_contents(__DIR__ . '/src/Handlers/AdminHandler.php');

if (strpos($admin_code, 'wp_generate_password') !== false) {
    echo "✅ [UI] PASS: Automatic Token Generation logic is present.\n";
} else {
    echo "❌ [UI] FAIL: Token Generation logic MISSING.\n";
}

// 2. Check for Plugin Detection logic 
if (strpos($admin_code, "defined('FLUENTCRM')") !== false && strpos($admin_code, "class_exists('\FluentSupport\App\App')") !== false) {
    echo "✅ [UI] PASS: Dynamic Plugin Detection logic is present.\n";
} else {
    echo "❌ [UI] FAIL: Plugin Detection logic MISSING.\n";
}

// 3. Verify Capability Scoping
if (strpos($admin_code, 'openclaw_api_capabilities') !== false) {
    echo "✅ [UI] PASS: Legacy capability persistence (option name) preserved.\n";
} else {
    echo "❌ [UI] FAIL: Capability option keys changed (Data loss risk).\n";
}

echo "--- FIDELITY AUDIT COMPLETE ---\n";
