<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) die("Unauthorized");

$shop_id = (int)($_GET['shop_id'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');

if(!$shop_id) die("Please select a shop to generate receipt.");

// Fetch Shop Info
$stmt_s = $pdo->prepare("SELECT * FROM shop_owners WHERE id = ?");
$stmt_s->execute([$shop_id]);
$shop = $stmt_s->fetch();

// Fetch Orders
$stmt_o = $pdo->prepare("
    SELECT o.*, c.name as customer_name 
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.shop_id = ? AND DATE(o.created_at) = ? 
    AND o.is_marketplace_order = 1 AND o.order_status = 'delivered' AND o.payment_mode = 'Online'
");
$stmt_o->execute([$shop_id, $date]);
$orders = $stmt_o->fetchAll();

if(empty($orders)) die("No orders found for this shop on the selected date.");

$totals = ['gross' => 0, 'delivery' => 0, 'margin' => 0, 'payout' => 0];
$all_settled = true;
foreach($orders as $o) {
    $totals['gross'] += (float)$o['total_amount'];
    $totals['payout'] += (float)$o['net_to_shop'];
    $totals['delivery'] += (float)$o['delivery_fee'];
    $totals['margin'] += ((float)$o['total_amount'] - (float)$o['net_to_shop'] - (float)$o['delivery_fee']);
    if(!$o['is_settled_manually']) $all_settled = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payout Receipt - <?= htmlspecialchars($shop['shop_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #1e293b; line-height: 1.5; }
        .container { max-width: 850px; margin: 0 auto; border: 1px solid #e2e8f0; padding: 40px; position: relative; border-radius: 20px; }
        .header { border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #f8fafc; text-align: left; padding: 12px; border-bottom: 2px solid #e2e8f0; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 800; }
        td { padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
        .total-row { background: #f8fafc; font-weight: 900; }
        .payout-box { background: #0f172a; color: #fff; padding: 25px; border-radius: 15px; margin-top: 30px; text-align: right; }
        .stamp { position: absolute; top: 150px; right: 50px; width: 150px; transform: rotate(-15deg); opacity: 0.15; z-index: -1; }
        .settled-badge { display: inline-block; background: #d1fae5; color: #065f46; padding: 5px 15px; border-radius: 50px; font-size: 10px; font-weight: 900; text-transform: uppercase; margin-top: 10px; border: 1px solid #6ee7b7; }
        @media print { .no-print { display: none; } .container { border: none; padding: 0; } }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #0f172a; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: bold;">Download as PDF</button>
    </div>

    <div class="container">
        <?php if($all_settled): ?>
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="stamp">
        <?php endif; ?>

        <div class="header">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" style="height: 50px;">
            <div style="text-align: right;">
                <h2 style="margin: 0; font-weight: 900;">Mall Payout Receipt</h2>
                <p style="margin: 5px 0; font-size: 12px; color: #64748b;">Statement Date: <?= date('d M Y', strtotime($date)) ?></p>
                <?php if($all_settled): ?>
                    <div class="settled-badge">✓ PAYMENT SETTLED</div>
                <?php endif; ?>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; margin-bottom: 40px;">
            <div>
                <label style="font-size: 10px; font-weight: 900; color: #94a3b8; text-transform: uppercase;">Merchant Details</label>
                <div style="font-weight: 900; font-size: 18px;"><?= htmlspecialchars($shop['shop_name']) ?></div>
                <div style="font-size: 12px; color: #475569;"><?= htmlspecialchars($shop['bank_acc_no'] ? "A/C: ".$shop['bank_acc_no'] : "Bank Info Not Provided") ?></div>
                <div style="font-size: 12px; color: #64748b;">IFSC: <?= htmlspecialchars($shop['bank_ifsc'] ?: "N/A") ?></div>
            </div>
            <div style="text-align: right;">
                <label style="font-size: 10px; font-weight: 900; color: #94a3b8; text-transform: uppercase;">Platform Info</label>
                <div style="font-weight: 700; font-size: 14px;">KhataLink Marketplace</div>
                <div style="font-size: 12px; color: #64748b;">Settlement Cycle: Daily T+1</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th style="text-align: right;">Gross (₹)</th>
                    <th style="text-align: right;">Deliv (₹)</th>
                    <th style="text-align: right;">Margin (₹)</th>
                    <th style="text-align: right;">Payout (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($orders as $o): 
                    $m = (float)$o['total_amount'] - (float)$o['net_to_shop'] - (float)$o['delivery_fee'];
                ?>
                <tr>
                    <td style="font-weight: 700;">#<?= $o['id'] ?></td>
                    <td><?= htmlspecialchars($o['customer_name']) ?></td>
                    <td style="text-align: right;">₹<?= number_format($o['total_amount'], 2) ?></td>
                    <td style="text-align: right;">₹<?= number_format($o['delivery_fee'], 2) ?></td>
                    <td style="text-align: right;">₹<?= number_format($m, 2) ?></td>
                    <td style="text-align: right; font-weight: 700;">₹<?= number_format($o['net_to_shop'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2">TOTAL RECONCILIATION</td>
                    <td style="text-align: right;">₹<?= number_format($totals['gross'], 2) ?></td>
                    <td style="text-align: right;">₹<?= number_format($totals['delivery'], 2) ?></td>
                    <td style="text-align: right;">₹<?= number_format($totals['margin'], 2) ?></td>
                    <td style="text-align: right; color: #059669;">₹<?= number_format($totals['payout'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="payout-box">
            <div style="font-size: 11px; font-weight: 900; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.1em; margin-bottom: 5px;">Net Transferrable Payout</div>
            <div style="font-size: 32px; font-weight: 900;">₹<?= number_format($totals['payout'], 2) ?></div>
            <div style="font-size: 10px; color: #64748b; margin-top: 10px;">Transfer generated via KhataLink Settlement Engine</div>
        </div>

        <div style="margin-top: 50px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 10px; color: #94a3b8; text-align: center;">
            This is an automated payout report. Please contact KhataLink Support for any discrepancies.<br>
            © <?= date('Y') ?> KhataLink Marketplace.
        </div>
    </div>
</body>
</html>
