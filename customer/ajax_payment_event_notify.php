<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';

header('Content-Type: application/json');

$customer_id = $_SESSION['customer_id'] ?? 0;
$customer_name = $_SESSION['customer_name'] ?? 'Customer';

$data = json_decode(file_get_contents('php://input'), true);
$shop_id = (int)($data['shop_id'] ?? 0);
$amount = (float)($data['amount'] ?? 0);
$event = $data['event'] ?? 'intent'; // 'intent', 'cancel', 'exit'

if ($shop_id <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

$title = "";
$body = "";

if ($event === 'intent') {
    $title = "Payment Attempt Started 💳";
    $body = "Customer $customer_name ne ₹$amount ka online bhugtan shuru kiya hai.";
} elseif ($event === 'cancel') {
    $title = "Payment Cancelled ⚠️";
    $body = "Customer $customer_name ne ₹$amount ka bhugtan cancel kar diya.";
} elseif ($event === 'exit') {
    $title = "Payment Window Closed";
    $body = "Customer $customer_name ne payment screen se exit kiya.";
}

if ($title) {
    sendKhataPush($pdo, $shop_id, 'shop', $title, $body);
}
echo json_encode(['success' => true]);