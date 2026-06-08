<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');
if(!isset($_SESSION['shop_id'])) exit(json_encode(['success'=>false, 'message'=>'Unauthorized']));

$data = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($data['order_id'] ?? 0);

if($order_id > 0) {
    $stmt = $pdo->prepare("UPDATE orders SET is_deleted_shop = 1 WHERE id = ? AND shop_id = ?");
    $stmt->execute([$order_id, $_SESSION['shop_id']]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid Order ID']);
}