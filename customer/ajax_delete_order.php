<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');
if(!isset($_SESSION['customer_id'])) exit(json_encode(['success'=>false]));

$data = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($data['order_id'] ?? 0);

if($order_id > 0) {
    // Soft delete: History se hide kar rahe hain
    $stmt = $pdo->prepare("UPDATE orders SET is_deleted_customer = 1 WHERE id = ? AND customer_id = ?");
    $stmt->execute([$order_id, $_SESSION['customer_id']]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}