<?php
/**
 * 🛰️ LIVE API INTEGRATION TEST - v6.4.0
 * Testing the jrbremoteapi/v1 namespace with the new X-JRB-Token.
 */

$api_url = 'http://localhost:18797/wp-json/jrbremoteapi/v1/site';
$token = 'ELT9BuSt9qtfpNNJel5N0y4TGMFLNiphQ5TrRmkqHR5VcxQrO7nz7nOdWVNW9qNT';

echo "📡 Pinging live API at $api_url...\n";

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-JRB-Token: $token",
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "📥 HTTP Status: $http_code\n";
echo "📄 Response Body: $response\n\n";

if ($http_code === 200) {
    echo "✅ SUCCESS: The new API Token is active and the v6.4.0 infrastructure is responding correctly.\n";
} else {
    echo "❌ FAILURE: API responded with status $http_code. Check token settings or capability matrix.\n";
}
