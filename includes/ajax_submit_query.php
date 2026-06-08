<?php
session_start();
require_once 'db.php';

$user_id = $_SESSION['shop_id'] ?? $_SESSION['customer_id'] ?? 0;
$user_type = isset($_SESSION['shop_id']) ? 'shop' : 'customer';

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if (empty($subject) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Please fill all fields']);
    exit();
}

$stmt = $pdo->prepare("INSERT INTO support_queries (user_id, user_type, subject, message, status) VALUES (?, ?, ?, ?, 'open')");
if ($stmt->execute([$user_id, $user_type, $subject, $message])) {
    echo json_encode(['success' => true, 'message' => 'Your query has been sent to the Admin panel. Status: 24h Resolution.']);
} else {
    echo json_encode(['success' => false, 'message' => 'System error. Try again later.']);
}
?>