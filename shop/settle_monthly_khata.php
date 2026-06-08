<?php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

// ── Token se shop_id nikalo (Flutter app token bhejta hai) ────────────────
$token = $_POST['token'] ?? '';

if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Token missing']);
    exit;
}

// Token verify karo — apni shops table mein token column hai?
// Agar session based hai toh neeche dekho alternate version
$stmt = $pdo->prepare("SELECT id FROM shops WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$shop = $stmt->fetch();

if (!$shop) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

$shop_id = (int) $shop['id'];

// ── Input ─────────────────────────────────────────────────────────────────
$khata_id = (int)   ($_POST['khata_id'] ?? 0);
$amount   = (float) ($_POST['amount']   ?? 0);
$mode     = trim(    $_POST['mode']     ?? 'Cash');

if ($khata_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid khata_id']);
    exit;
}

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount']);
    exit;
}

// ── Verify: khata is shop ka hai aur open hai ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT id, status, total_amount, is_settled_manually 
    FROM monthly_khata 
    WHERE id = ? AND shop_id = ?
    LIMIT 1
");
$stmt->execute([$khata_id, $shop_id]);
$khata = $stmt->fetch();

if (!$khata) {
    echo json_encode([
        'success' => false,
        'message' => 'Khata not found',
        'debug'   => ['khata_id' => $khata_id, 'shop_id' => $shop_id]
    ]);
    exit;
}

if ($khata['status'] === 'closed' || $khata['is_settled_manually'] == 1) {
    echo json_encode([
        'success' => false,
        'message' => 'Already settled'
    ]);
    exit;
}

// ── Settle karo — bilkul monthly_khata.php ke settle_manual jaisa ─────────
$stmt = $pdo->prepare("
    UPDATE monthly_khata 
    SET 
        status               = 'closed',
        is_settled_manually  = 1,
        paid_amount          = ?,
        payment_mode         = ?,
        razorpay_payment_id  = 'Manual',
        paid_at              = NOW()
    WHERE id = ? AND shop_id = ?
");
$stmt->execute([$amount, $mode, $khata_id, $shop_id]);

if ($stmt->rowCount() > 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Payment collected successfully',
        'data'    => [
            'khata_id'     => $khata_id,
            'paid_amount'  => $amount,
            'payment_mode' => $mode,
            'paid_at'      => date('Y-m-d H:i:s')
        ]
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Update failed',
        'debug'   => [
            'khata_id' => $khata_id,
            'shop_id'  => $shop_id,
            'amount'   => $amount
        ]
    ]);
}
?>