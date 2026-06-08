<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['shop_id'])) exit(json_encode(['success' => false]));

$order_id = (int)($_GET['order_id'] ?? 0);

if($order_id > 0) {
    $stmt = $pdo->prepare("SELECT pickup_code, handover_code, order_status FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    echo json_encode(['success' => true, 'code' => $order['pickup_code'], 'handover' => $order['handover_code'], 'status' => $order['order_status']]);
} else {
    echo json_encode(['success' => false]);
}
