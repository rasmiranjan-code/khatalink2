<?php
session_start();
require_once 'db.php';

if(!isset($_SESSION['shop_id']) && !isset($_SESSION['customer_id'])) {
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$order_id = (int)($_GET['order_id'] ?? 0);

if($order_id <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid ID']));
}

try {
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'items' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>