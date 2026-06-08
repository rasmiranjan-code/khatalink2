<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$code = strtoupper(trim($data['code'] ?? ''));
$subtotal = (float)($data['subtotal'] ?? 0);

if (!$code) exit(json_encode(['success' => false, 'message' => 'Enter a coupon code']));

$stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND is_active = 1 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) LIMIT 1");
$stmt->execute([$code]);
$coupon = $stmt->fetch();

if (!$coupon) {
    exit(json_encode(['success' => false, 'message' => 'Invalid or expired coupon code']));
}

if ($subtotal < (float)$coupon['min_order_value']) {
    exit(json_encode(['success' => false, 'message' => 'Minimum order value of ₹' . $coupon['min_order_value'] . ' required']));
}

$discount = 0;
if ($coupon['discount_type'] === 'flat') {
    $discount = (float)$coupon['discount_value'];
} else {
    $discount = ($subtotal * (float)$coupon['discount_value']) / 100;
}

// Discount can't be more than subtotal
$discount = min($discount, $subtotal);

exit(json_encode([
    'success' => true,
    'message' => 'Coupon applied!',
    'discount' => round($discount, 2),
    'code' => $coupon['code']
]));