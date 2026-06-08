<?php
require_once 'db.php';

// Firebase Configuration (Replace with your actual ID)
define('FIREBASE_PROJECT_ID', 'khatalink-63041');

/**
 * Generates a Google Access Token using the service-account.json
 * This is required for FCM V1 API authentication.
 */
function getGoogleAccessToken() {
    $json_file = __DIR__ . '/service-account.json';
    if (!file_exists($json_file)) {
        error_log("FCM Error: service-account.json not found in includes folder.");
        return false;
    }

    $data = json_decode(file_get_contents($json_file), true);
    $private_key = $data['private_key'];
    $client_email = $data['client_email'];

    // Header for JWT
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));

    // Payload (Claims)
    $now = time();
    $payload = json_encode([
        'iss' => $client_email,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600
    ]);
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

    // Signature
    $signature = '';
    openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $private_key, "SHA256");
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

    // Request Access Token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=' . $jwt);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('FCM Auth Error: ' . curl_error($ch));
        return false;
    }
    curl_close($ch);

    $res_data = json_decode($result, true);
    return $res_data['access_token'] ?? false;
}

/**
 * Sends a Push Notification via Firebase V1 API.
 */
function sendKhataPush(PDO $pdo, int $user_id, string $user_type, string $title, string $body, ?string $image_url = null, array $data = []) {
    $access_token = getGoogleAccessToken();
    if (!$access_token) {
        error_log("FCM Error: Could not generate access token.");
        return false;
    }

    // 1. Get tokens for the user
    $stmt = $pdo->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE user_id = ? AND user_type = ?");
    $stmt->execute([$user_id, $user_type]);
    $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tokens)) return false;

    $url = "https://fcm.googleapis.com/v1/projects/" . FIREBASE_PROJECT_ID . "/messages:send";
    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ];

    foreach($tokens as $token) {
        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body
                ],
                // Convert all data values to strings (Required for FCM V1)
                'data' => array_map('strval', array_merge($data, [
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'image' => (string)$image_url // Added image to data payload
                ])),
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                        'channel_id' => 'khatalink_alerts',
                        'image' => $image_url // Added image to Android notification
                    ]
                ],
                'webpush' => [
                    'headers' => [ 'Urgency' => 'high' ],
                    'notification' => [
                        'icon' => '/khatalink/assets/favicon.png',
                        'requireInteraction' => true,
                        'image' => $image_url // Added image to WebPush notification
                    ]
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local XAMPP

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            error_log("FCM CURL Error: " . curl_error($ch));
        }
        
        curl_close($ch);

        error_log("FCM HTTP $http_code Response for $user_type (ID: $user_id): " . $response);
    }
    return true;
}

/**
 * Sends a Bulk Push Notification (Broadcast).
 * @param string $target_type 'shop', 'customer', 'delivery', or 'all'
 */
function sendKhataBroadcast(PDO $pdo, string $target_type, string $title, string $body, ?string $image_url = null, array $data = []) {
    $access_token = getGoogleAccessToken();
    if (!$access_token) return false;

    $query = "SELECT fcm_token FROM user_fcm_tokens";
    $params = [];
    if ($target_type !== 'all') {
        $query .= " WHERE user_type = ?";
        $params[] = $target_type;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tokens)) return 0;

    $url = "https://fcm.googleapis.com/v1/projects/" . FIREBASE_PROJECT_ID . "/messages:send";
    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ];

    $success_count = 0;
    foreach($tokens as $token) {
        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body
                ],
                'data' => array_map('strval', array_merge($data, [
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'is_broadcast' => 'true',
                    'image' => (string)$image_url // Added image to data payload
                ])),
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                        'image' => $image_url // Added image to Android notification
                    ]
                ],
                'webpush' => [
                    'headers' => [ 'Urgency' => 'high' ],
                    'notification' => [
                        'icon' => '/khatalink/assets/favicon.png',
                        'requireInteraction' => true,
                        'image' => $image_url // Added image to WebPush notification
                    ]
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) $success_count++;
        curl_close($ch);
    }
    return $success_count;
}

/**
 * Inventory Low Alert Helper
 */
function checkInventoryAlert(PDO $pdo, int $shop_id, int $product_id) {
    $stmt = $pdo->prepare("SELECT name, current_stock, low_stock_alert FROM inventory_products WHERE id = ? AND shop_id = ?");
    $stmt->execute([$product_id, $shop_id]);
    $product = $stmt->fetch();

    if ($product && $product['current_stock'] <= $product['low_stock_alert']) {
        $title = "⚠️ Low Stock Alert: " . $product['name'];
        $body = "Aapka stock khatam hone wala hai. Sirf " . (float)$product['current_stock'] . " units bache hain.";
        sendKhataPush($pdo, $shop_id, 'shop', $title, $body, null, ['type' => 'inventory', 'id' => (string)$product_id]);
    }
}

/**
 * Notify Shop Owner about stock deduction
 */
function notifyStockDeduction(PDO $pdo, int $shop_id, int $product_id) {
    $stmt = $pdo->prepare("SELECT name, current_stock, primary_unit FROM inventory_products WHERE id = ? AND shop_id = ?");
    $stmt->execute([$product_id, $shop_id]);
    $product = $stmt->fetch();

    if ($product) {
        $title = "Inventory Update: " . $product['name'];
        $body = "Product stock se kam hua hai. Naya stock balance: " . (float)$product['current_stock'] . " " . $product['primary_unit'];
        sendKhataPush($pdo, $shop_id, 'shop', $title, $body, null, ['type' => 'inventory', 'id' => (string)$product_id]);
    }
}

/**
 * Token Save/Update Logic
 */
function updateFCMToken(PDO $pdo, int $user_id, string $user_type, ?string $token, string $device_type = 'web') {
    if (empty($token)) return;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_fcm_tokens (user_id, user_type, fcm_token, device_type)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                fcm_token = VALUES(fcm_token), 
                last_updated = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$user_id, $user_type, $token, $device_type]);
        return true;
    } catch (Exception $e) {
        error_log("FCM Error: " . $e->getMessage());
        return false;
    }
}
?>