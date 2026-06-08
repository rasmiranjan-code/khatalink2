<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';

if(!isset($_SESSION['admin_id'])) exit(json_encode(['success' => false, 'message' => 'Unauthorized']));

$shop_id = (int)$_POST['shop_id'];
$pay_date = $_POST['pay_date'];
$amount = (float)$_POST['amount'];

try {
    $pdo->beginTransaction();

    // Update all 3 modules for this shop and date
    $pdo->prepare("UPDATE payment_requests SET is_settled_manually = 1 WHERE shop_id = ? AND DATE(created_at) = ? AND razorpay_payment_id IS NOT NULL")->execute([$shop_id, $pay_date]);
    $pdo->prepare("UPDATE monthly_khata SET is_settled_manually = 1 WHERE shop_id = ? AND DATE(paid_at) = ? AND razorpay_payment_id IS NOT NULL")->execute([$shop_id, $pay_date]);
    $pdo->prepare("UPDATE bond_payments bp JOIN bonds b ON bp.bond_id = b.id SET bp.is_settled_manually = 1 WHERE b.shop_id = ? AND DATE(bp.payment_date) = ? AND bp.razorpay_payment_id IS NOT NULL")->execute([$shop_id, $pay_date]);

    // Fetch Shop Info for Notification
    $stmt = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
    $stmt->execute([$shop_id]);
    $shop_name = $stmt->fetchColumn() ?: 'Merchant';

    // Send Push Notification
    $title = "Settlement Successful ✅";
    $body = "Namaste $shop_name! Aapka ₹" . number_format($amount, 2) . " ka settlement (Collection date: " . date('d M', strtotime($pay_date)) . ") process ho gaya hai. Paisa 24h mein bank mein dikhega.";
    sendKhataPush($pdo, $shop_id, 'shop', $title, $body, ['type' => 'settlement']);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}