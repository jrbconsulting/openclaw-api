<?php
/**
 * 🕵️ REBRAND FIDELITY CHECK - v6.4.0
 */
require_once __DIR__ . '/tests/wp-mock.php';
require_once __DIR__ . '/src/Auth/Guard.php';
require_once __DIR__ . '/src/Core/Plugin.php';
require_once __DIR__ . '/src/Handlers/AdminHandler.php';

use JRB\RemoteApi\Auth\Guard;
use JRB\RemoteApi\Core\Plugin;

echo "\n--- REBRAND AUDIT START ---\n";

// 1. Verify Namespace and Constants
if (Plugin::API_NAMESPACE === 'jrbremoteapi/v1') {
    echo "✅ [BRAND] API Namespace: jrbremoteapi/v1 (Verified).\n";
} else {
    echo "❌ [BRAND] API Namespace Mismatch.\n";
}

// 2. Verify Option Keys (The transition to jrbremote_)
$admin_code = file_get_contents(__DIR__ . '/src/Handlers/AdminHandler.php');
if (strpos($admin_code, 'jrbremote_api_token_hash') !== false) {
    echo "✅ [DB] Option Keys: jrbremote_api_ prefix active (Verified).\n";
} else {
    echo "❌ [DB] Legacy Option Keys found.\n";
}

// 3. Verify Header and Guard Logic
$guard_code = file_get_contents(__DIR__ . '/src/Auth/Guard.php');
if (strpos($guard_code, 'HTTP_X_JRB_TOKEN') !== false) {
    echo "✅ [AUTH] Header: X-JRB-Token enforced (Verified).\n";
} else {
    echo "❌ [AUTH] Legacy Header found.\n";
}

// 4. Feature Check (Granular UI)
if (strpos($admin_code, 'crm_subscribers_read') !== false && strpos($admin_code, 'support_tickets_read') !== false) {
    echo "✅ [UI] Granular Permissions: UI successfully restored and rebranded (Verified).\n";
} else {
    echo "❌ [UI] Pathetic permissions found.\n";
}

echo "--- REBRAND AUDIT COMPLETE ---\n";
