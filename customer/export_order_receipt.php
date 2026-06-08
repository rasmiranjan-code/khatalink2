<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['customer_id'])) {
    die("Access Denied: Please login as a customer.");
}

$order_id = (int)($_GET['order_id'] ?? 0);
$customer_id = $_SESSION['customer_id'];

if ($order_id <= 0) {
    die("Invalid Order ID.");
}

// Fetch Order, Shop and Customer Details
$stmt = $pdo->prepare("
    SELECT o.*, s.shop_name, s.shop_category, s.full_address as shop_address, 
           c.name as customer_name, c.unique_id
    FROM orders o
    JOIN shop_owners s ON o.shop_id = s.id
    JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ? AND o.customer_id = ? AND o.order_status = 'delivered'
");
$stmt->execute([$order_id, $customer_id]);
$order = $stmt->fetch();

if(!$order) {
    die("Order not found or it has not been delivered yet. Receipts are only available for delivered orders.");
}

// Fetch Itemized List
$stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt_items->execute([$order_id]);
$items = $stmt_items->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt_ORD_<?= $order_id ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #1e293b; background: #f8fafc; line-height: 1.5; }
        .receipt-box { max-width: 800px; margin: 0 auto; border: 1px solid #e2e8f0; padding: 50px; border-radius: 24px; background: #fff; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); position: relative; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f172a; padding-bottom: 30px; margin-bottom: 30px; }
        .logo img { height: 50px; margin-bottom: 10px; }
        .invoice-title { font-size: 24px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; }
        .meta-info { font-size: 13px; color: #64748b; }
        .address-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }
        .address-label { font-size: 10px; font-weight: 900; text-transform: uppercase; color: #94a3b8; letter-spacing: 1px; margin-bottom: 8px; }
        .address-val { font-size: 14px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin: 30px 0; }
        th { text-align: left; padding: 12px 15px; background: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 11px; text-transform: uppercase; font-weight: 800; color: #64748b; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .total-section { margin-left: auto; width: 300px; }
        .total-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; }
        .grand-total { border-top: 2px solid #0f172a; margin-top: 10px; padding-top: 15px; font-size: 18px; font-weight: 900; }
        .footer { text-align: center; font-size: 11px; color: #94a3b8; margin-top: 60px; border-top: 1px solid #f1f5f9; padding-top: 20px; }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 70px; font-weight: 900; color: rgba(0,0,0,0.02); pointer-events: none; white-space: nowrap; }
        @media print { 
            .no-print { display: none; } 
            body { padding: 0; background: #fff; } 
            .receipt-box { border: none; padding: 20px; width: 100%; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; margin-bottom: 30px;">
        <button onclick="window.print()" style="padding: 12px 30px; background: #2563eb; color: #fff; border: none; border-radius: 12px; font-weight: 800; cursor: pointer; text-transform: uppercase; font-size: 12px; letter-spacing: 1px; box-shadow: 0 4px 12px rgba(37,99,235,0.3);">Print Receipt / Save as PDF</button>
    </div>

    <div class="receipt-box">
        <div class="watermark">KHATALINK VERIFIED</div>
        <div class="header">
            <div class="logo">
                <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo">
                <div class="address-val"><?= htmlspecialchars($order['shop_name']) ?></div>
                <div class="meta-info"><?= htmlspecialchars($order['shop_category']) ?></div>
            </div>
            <div style="text-align: right;">
                <div class="invoice-title">Order Receipt</div>
                <div class="meta-info">#ORD-<?= $order_id ?></div>
                <div class="meta-info">Date: <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></div>
            </div>
        </div>

        <div class="address-grid">
            <div>
                <div class="address-label">Billed To</div>
                <div class="address-val"><?= htmlspecialchars($order['customer_name']) ?></div>
                <div class="meta-info">ID: <?= $order['unique_id'] ?></div>
            </div>
            <div style="text-align: right;">
                <div class="address-label">Delivery Destination</div>
                <div class="address-val"><?= htmlspecialchars($order['delivery_name']) ?></div>
                <div class="meta-info">
                    <?= htmlspecialchars($order['delivery_apartment_house']) ?>, <?= htmlspecialchars($order['delivery_village']) ?><br>
                    <?= htmlspecialchars($order['delivery_block']) ?>, <?= htmlspecialchars($order['delivery_district']) ?><br>
                    Pincode: <?= htmlspecialchars($order['pincode']) ?>
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align: center;">Qty</th>
                    <th style="text-align: right;">Unit Price</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($items as $it): ?>
                <tr>
                    <td style="font-weight: 700;"><?= htmlspecialchars($it['item_name']) ?></td>
                    <td style="text-align: center;"><?= (float)$it['quantity'] ?> <?= $it['unit'] ?></td>
                    <td style="text-align: right;">₹<?= number_format($it['price_per_unit'], 2) ?></td>
                    <td style="text-align: right; font-weight: 700;">₹<?= number_format($it['total_price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-section">
            <div class="total-row">
                <span class="meta-info">Items Subtotal:</span>
                <span style="font-weight: 700;">₹<?= number_format($order['total_amount'], 2) ?></span>
            </div>
            <div class="total-row">
                <span class="meta-info">Delivery Charges:</span>
                <span style="font-weight: 700;">₹<?= number_format($order['delivery_fee'], 2) ?></span>
            </div>
            <div class="total-row grand-total">
                <span>Grand Total:</span>
                <span>₹<?= number_format($order['total_amount'], 2) ?></span>
            </div>
            <div class="total-row" style="margin-top: 10px;">
                <span class="address-label">Payment Mode:</span>
                <span class="address-val" style="color: #2563eb;"><?= $order['payment_mode'] ?></span>
            </div>
        </div>

        <div class="footer">
            <p>Thank you for shopping at <strong><?= htmlspecialchars($order['shop_name']) ?></strong>!</p>
            <p style="margin-top: 5px;">This is a computer-generated receipt. Verified by KhataLink Hyperlocal Network.</p>
        </div>
    </div>
</body>
</html>
