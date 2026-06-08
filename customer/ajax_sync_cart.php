<?php
ob_start(); // Prevent any accidental output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

error_reporting(0); // Stop warnings from breaking JSON
ini_set('display_errors', 0);

session_start();
require_once '../includes/db.php';
check_cors(); // Safety check

$data = json_decode(file_get_contents('php://input'), true);
$customer_id = 0;

// API logic for Flutter with HMAC Signature verification
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $token = get_auth_token();
    $parts = verify_secure_token($token);
    if($parts) $customer_id = (int)$parts[0];
} else {
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if ($customer_id <= 0) {
    ob_clean();
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$action = $data['action'] ?? 'get';

if ($action === 'save') {
    $raw_cart = $data['cart'] ?? [];
    
    // 1. LIMIT: Max 100 items (DDoS protection)
    if (count($raw_cart) > 100) exit(json_encode(['success' => false, 'message' => 'Cart too large']));

    // 2. SANITIZE: Only trust product_id and qty from client
    $sanitized_cart = [];
    foreach ($raw_cart as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = (float)($item['qty'] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $sanitized_cart[] = ['product_id' => $pid, 'qty' => $qty];
        }
    }

    $cart_json = json_encode($sanitized_cart);

    // FIX: INSERT ... ON DUPLICATE KEY UPDATE avoids multiple rows per customer
    $stmt = $pdo->prepare("INSERT INTO grocery_carts (customer_id, cart_data) VALUES (?, ?) 
                           ON DUPLICATE KEY UPDATE cart_data = VALUES(cart_data), updated_at = NOW()");
    $success = $stmt->execute([$customer_id, $cart_json]);
    
    ob_clean();
    echo json_encode(['success' => $success, 'cart' => hydrate_cart($pdo, $sanitized_cart)]);
} else {
    // 3. HYDRATE: Server-side source of truth for names and prices
    $stmt = $pdo->prepare("SELECT cart_data FROM grocery_carts WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $cart_raw = $stmt->fetchColumn();
    $stored_items = $cart_raw ? json_decode($cart_raw, true) : [];
    
    ob_clean();
    echo json_encode([
        'success' => true, 
        'cart' => hydrate_cart($pdo, $stored_items)
    ]);
}