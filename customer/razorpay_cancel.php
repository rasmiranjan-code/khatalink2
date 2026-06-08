<?php
ob_start(); // Prevent accidental whitespace output
require_once '../includes/db.php';
$customer_id = 0;
$auth_source = 'unknown';

// Prioritize token-based authentication for Flutter app
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (!empty($token)) {
    $auth_source = 'token';
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $customer_id = (int)($parts[0] ?? 0);
} else { // Fallback to session-based authentication for web
    session_start(); // Start session only if not using token
    $auth_source = 'session';
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if(!$customer_id || !isset($_POST['order_id'])) {
    error_log("DEBUG: razorpay_cancel.php - Unauthorized access. customer_id: $customer_id, Auth Source: $auth_source");
    exit(json_encode(['success' => false, 'message' => 'Unauthorized or missing parameters.']));
}
$order_id = $_POST['order_id'];
// Update Ledger payment requests to cancelled
$pdo->prepare("UPDATE payment_requests SET status = 'cancelled' WHERE razorpay_order_id = ? AND status = 'pending'")->execute([$order_id]);
// Update Bond payments to cancelled
$pdo->prepare("UPDATE bond_payments SET payment_status = 'cancelled' WHERE razorpay_order_id = ? AND payment_status = 'pending'")->execute([$order_id]);
echo json_encode(['success' => true]);
?>