<?php
session_start();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); 
    exit();
}

require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';

// Token handling
$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace(['Bearer ', ' '], ['', '+'], $token);

$customer_id = 0;
if (!empty($token)) {
    $decoded = base64_decode($token);
    if ($decoded) {
        $parts = explode(':', $decoded);
        $customer_id = (int)($parts[0] ?? 0);
    }
}

// Fallback to session if token isn't providing the ID
if (!$customer_id) {
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if (!$customer_id) {
    die("Unauthorized access.");
}

$id = (int)($_GET['id'] ?? 0);

// Fetch Monthly Khata Details
$stmt = $pdo->prepare("
    SELECT mk.*, c.name as customer_name, c.unique_id, s.shop_name, s.name as owner_name, s.upi_id
    FROM monthly_khata mk
    JOIN customers c ON mk.customer_id = c.id
    JOIN shop_owners s ON mk.shop_id = s.id
    WHERE mk.id = ? AND mk.customer_id = ?
");
$stmt->execute([$id, $customer_id]);
$mk = $stmt->fetch();

if(!$mk) {
    die("Statement not found.");
}

// Fetch Items for this Khata
$items_stmt = $pdo->prepare("SELECT * FROM monthly_khata_items WHERE khata_id = ? ORDER BY item_date ASC");
$items_stmt->execute([$id]);
$list = $items_stmt->fetchAll();

$is_paid = ($mk['status'] == 'closed');
$is_manual = ($mk['razorpay_payment_id'] === 'Manual');

$base_amt = (float)$mk['total_amount'];
$pg_fee_amt = $is_manual ? 0 : ($base_amt * (PG_FEE_PERCENT / 100));
$kl_fee_amt = $is_manual ? 0 : ($base_amt * ((MONTHLY_PLATFORM_COMMISSION_PERCENT - PG_FEE_PERCENT) / 100));
$fee_amt = $pg_fee_amt + $kl_fee_amt;
$grand_total = $base_amt + $fee_amt;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Statement - <?= htmlspecialchars($mk['customer_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #1e293b; background: #fff; }
        .container { max-width: 800px; margin: 0 auto; border: 1px solid #f1f5f9; padding: 40px; position: relative; }
        .header { border-bottom: 3px solid #2563eb; padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { margin: 0; font-weight: 900; text-transform: uppercase; font-size: 22px; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #eff6ff; text-align: left; padding: 12px; border: 1px solid #bfdbfe; font-size: 11px; text-transform: uppercase; color: #1e4ed8; font-weight: 800; }
        td { padding: 12px; border: 1px solid #e2e8f0; font-size: 13px; }
        .subtotal-row { background: #f8fafc; font-weight: 700; color: #64748b; }
        .fee-row { background: #fff; font-size: 11px; color: #94a3b8; }
        .total-row { background: #0f172a; color: #fff; font-weight: 900; font-size: 16px; }
        .stamp { position: absolute; top: 150px; right: 50px; width: 180px; transform: rotate(-15deg); opacity: 0.8; z-index: 100; pointer-events: none; }
        .cash-watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-20deg); font-size: 120px; font-weight: 900; color: rgba(5, 150, 105, 0.08); pointer-events: none; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-30deg); opacity: 0.03; font-size: 80px; font-weight: 900; pointer-events: none; }
        @media print { .no-print { display: none !important; } .container { border: none; padding: 0; } body { padding: 20px; } .stamp { opacity: 1; } }
    </style>
</head>
<body>
    <div class="watermark">KHATALINK MONTHLY</div>
    <?php if($is_manual): ?>
        <div class="cash-watermark">CASH PAYMENT</div>
    <?php endif; ?>
    
    <?php if($is_paid): ?>
        <img src="../assets/official stamp.png" class="stamp" alt="PAID STAMP">
    <?php endif; ?>

    <div class="container">
        <div class="header">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" style="height: 55px;" alt="KhataLink Logo">
            <div>
                <h1><?= htmlspecialchars($mk['shop_name']) ?></h1>
                <p style="margin:5px 0; font-size: 12px; color: #64748b;">Monthly Credit Statement</p>
            </div>
            <div style="text-align: right;">
                <div style="font-weight: 900; font-size: 14px;">Invoice #MK-<?= $mk['id'] ?></div>
                <div style="font-size: 12px; color: #64748b;">Cycle Start: <?= date('d M Y', strtotime($mk['start_date'])) ?></div>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; margin-bottom: 40px;">
            <div>
                <label style="font-size: 10px; font-weight: 900; color: #94a3b8; text-transform: uppercase;">Customer Details</label>
                <div style="font-weight: 700;"><?= htmlspecialchars($mk['customer_name']) ?></div>
                <div style="font-size: 12px; color: #64748b;">ID: <?= $mk['unique_id'] ?></div>
            </div>
            <div style="text-align: right;">
                <label style="font-size: 10px; font-weight: 900; color: #94a3b8; text-transform: uppercase;">Payment Status</label>
                <div style="font-weight: 900; color: <?= $is_paid ? '#059669' : '#dc2626' ?>;"><?= $is_paid ? 'DUE CLEARED' : 'PENDING' ?></div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th style="text-align: center;">Qty</th>
                    <th style="text-align: right;">Rate</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($list as $item): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($item['item_date'])) ?></td>
                    <td style="font-weight: 700;"><?= htmlspecialchars($item['item_name']) ?></td>
                    <td style="text-align: center;"><?= (float)$item['quantity'] ?></td>
                    <td style="text-align: right;">₹<?= number_format($item['rate'], 2) ?></td>
                    <td style="text-align: right; font-weight: 700;">₹<?= number_format($item['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="subtotal-row">
                    <td colspan="4" style="text-align: right;">SHOP BILL SUBTOTAL</td>
                    <td style="text-align: right;">₹<?= number_format($base_amt, 2) ?></td>
                </tr>
                <tr class="fee-row">
                    <td colspan="4" style="text-align: right;">
                        <?= $is_manual ? 'OFFLINE MANUAL PAYMENT (CASH)' : 'ONLINE PG PROCESSING FEES ('.PG_FEE_PERCENT.'%)' ?>
                    </td>
                    <td style="text-align: right;">₹<?= number_format($pg_fee_amt, 2) ?></td>
                </tr>
                <?php if(!$is_manual): ?>
                <tr class="fee-row">
                    <td colspan="4" style="text-align: right;">KHATALINK SERVICE CONVENIENCE FEE (<?= (MONTHLY_PLATFORM_COMMISSION_PERCENT - PG_FEE_PERCENT) ?>%)</td>
                    <td style="text-align: right;">₹<?= number_format($kl_fee_amt, 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td colspan="4" style="text-align: right;">
                        <?= $is_manual ? 'TOTAL CASH RECEIVED' : 'TOTAL AMOUNT PAYABLE' ?>
                    </td>
                    <td style="text-align: right;">₹<?= number_format($grand_total, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 50px; border-top: 1px solid #f1f5f9; padding-top: 20px; font-size: 11px; color: #94a3b8; text-align: center;">
            This is a computer generated document by KhataLink. <br>
            <?php if($is_paid && !empty($mk['razorpay_payment_id'])): ?>
                Transaction Verified: <?= htmlspecialchars($mk['razorpay_payment_id']) ?>
            <?php endif; ?>
        </div>
    </div>

    <div style="margin-top: 20px; text-align: center;" class="no-print">
        <button onclick="window.print()" style="padding: 12px 30px; background: #0f172a; color: #fff; border-radius: 12px; font-weight: 900; cursor: pointer;">Download Statement (PDF)</button>
    </div>
</body>
</html>