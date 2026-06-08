<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';

header('Content-Type: application/json');

if(!isset($_SESSION['customer_id'])) {
    exit(json_encode(['success' => false, 'message' => 'Unauthorized access.']));
}

$customer_id = $_SESSION['customer_id'];
$data = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($data['order_id'] ?? 0);

if ($order_id <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid Order ID.']));
}

try {
    // Fetch Shop ID before canceling
    $stmt_info = $pdo->prepare("SELECT shop_id FROM orders WHERE id = ? AND customer_id = ?");
    $stmt_info->execute([$order_id, $customer_id]);
    $shop_id = $stmt_info->fetchColumn();

    // Ab order direct cancel nahi hoga, Request jayegi Shopkeeper ko
    $stmt = $pdo->prepare("UPDATE orders SET order_status = 'cancel_requested' WHERE id = ? AND customer_id = ? AND order_status IN ('pending', 'accepted', 'assigned')");
    $stmt->execute([$order_id, $customer_id]);

    if ($stmt->rowCount() > 0) {
        // Notify Shop
        $stmt_s_name = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
        $stmt_s_name->execute([$shop_id]);
        $s_name = $stmt_s_name->fetchColumn() ?: 'Shop';
        $c_name = $_SESSION['customer_name'] ?? 'Customer';
        sendKhataPush($pdo, (int)$shop_id, 'shop', "Cancellation Request ⚠️", "Customer $c_name ne Order #$order_id cancel karne ki request bheji hai. Dashboard par check karein.");
        
        echo json_encode(['success' => true, 'message' => 'Cancellation request sent to shop.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Cannot request cancellation for this order status.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>