<?php
session_start();
require_once '../includes/db.php';

// Support both POST (from dashboard) and GET (from QR scan)
// This file now expects a `bill_id` to fetch data from the database.
// It can come from POST (after saving bill) or GET (from QR scan).

$bill_id = (int)($_POST['bill_id'] ?? $_GET['bill_id'] ?? 0);

if (!$bill_id) {
    die("Bill ID missing.");
}

// Fetch Bill Details
$stmt_bill = $pdo->prepare("
    SELECT pb.*, c.name as customer_name, c.unique_id as customer_unique_id,
           s.shop_name, s.name as owner_name, s.gst_number, s.upi_id, s.shop_category
    FROM pos_bills pb
    JOIN shop_owners s ON pb.shop_id = s.id
    LEFT JOIN customers c ON pb.customer_id = c.id
    WHERE pb.id = ?
");
$stmt_bill->execute([$bill_id]);
$bill = $stmt_bill->fetch();

if (!$bill) {
    die("Bill not found.");
}

// Fetch Bill Items
$stmt_items = $pdo->prepare("SELECT * FROM pos_bill_items WHERE pos_bill_id = ?");
$stmt_items->execute([$bill_id]);
$bill_items = $stmt_items->fetchAll();

// Shop and Customer details
$shop = [
    'shop_name' => $bill['shop_name'] ?? '',
    'shop_category' => $bill['shop_category'] ?? '',
    'gst_number' => $bill['gst_number'] ?? '',
    'upi_id' => $bill['upi_id'] ?? '',
    'name' => $bill['owner_name'] ?? '',
];
$customer_name = $bill['customer_name'] ?? 'Guest Customer';

// Totals are already in pos_bills table
$total_gross_amount = (float)$bill['total_gross_amount'];
$total_discount_amount = (float)$bill['total_discount_amount'];
$final_net_amount = (float)$bill['final_net_amount'];

$payment_status = $bill['payment_status'];
$is_udhar = ($payment_status === 'transferred_to_udhar');

// Generate UPI QR Code URL for the final net amount
$qr_url = "";
if (!empty($shop['upi_id']) && $final_net_amount > 0 && !$is_udhar) {
    $upi_payload = "upi://pay?pa=" . htmlspecialchars($shop['upi_id']) . "&pn=" . urlencode($shop['shop_name']) . "&am=" . number_format($final_net_amount, 2, '.', '') . "&cu=INR&tn=POS_Bill_" . $bill['bill_number'];
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($upi_payload);
}

// For QR code to view bill online
$base_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/khatalink";
$view_bill_url = $base_url . "/shop/export_pos_bill.php?bill_id=" . $bill_id;
$view_bill_qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($view_bill_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>POS Bill - <?= htmlspecialchars($customer_name) ?></title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; color: #1e293b; padding: 40px; line-height: 1.5; background: #fff; position: relative; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f1f5f9; padding-bottom: 25px; margin-bottom: 30px; }
        .logo-section img { height: 45px; margin-bottom: 10px; }
        .shop-info h1 { margin: 0; color: #0f172a; font-size: 22px; font-weight: 800; }
        .shop-info p { margin: 3px 0 0 0; color: #64748b; font-size: 13px; }
        .bill-meta { display: flex; justify-content: space-between; margin-bottom: 30px; background: #f8fafc; padding: 15px 20px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .meta-item label { display: block; font-size: 10px; text-transform: uppercase; color: #94a3b8; font-weight: 800; letter-spacing: 0.5px; }
        .meta-item span { font-weight: 700; color: #0f172a; font-size: 14px; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 15px; border-bottom: 2px solid #e2e8f0; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 800; }
        td { padding: 14px 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .text-end { text-align: right; }
        
        .summary-section { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 40px; }
        .qr-box { text-align: center; border: 1px solid #e2e8f0; padding: 10px; border-radius: 12px; background: #fff; width: 130px; }
        .qr-box img { width: 110px; height: 110px; display: block; }
        .qr-box small { display: block; font-size: 9px; font-weight: 700; color: #94a3b8; margin-top: 5px; }

        .total-box { text-align: right; }
        .total-row { margin-bottom: 5px; }
        .total-row label { font-size: 13px; color: #64748b; }
        .total-row span { font-size: 14px; font-weight: 600; color: #0f172a; margin-left: 15px; }
        .grand-total { margin-top: 10px; padding-top: 10px; border-top: 2px solid #e2e8f0; }
        .grand-total label { font-size: 15px; font-weight: 800; color: #0f172a; }
        .grand-total span { font-size: 24px; font-weight: 900; color: #2563eb; margin-left: 15px; }

        .footer { margin-top: 80px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 25px; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); opacity: 0.03; z-index: -100; width: 60%; pointer-events: none; }
        
        .udhar-watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-20deg); font-size: 120px; font-weight: 900; color: rgba(220,38,38,0.08); pointer-events: none; }
        
        /* Premium PAID Stamp */
        .paid-stamp-box { position: absolute; top: 120px; right: 60px; border: 5px solid #059669; color: #059669; padding: 10px 20px; border-radius: 12px; font-size: 24px; font-weight: 900; text-transform: uppercase; transform: rotate(-15deg); opacity: 0.8; z-index: 50; }
        .paid-stamp-box span { display: block; font-size: 10px; text-align: center; margin-top: -4px; opacity: 0.7; }

        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
        .btn-print { background: #2563eb; color: #fff; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-print:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="watermark" alt="">

    <?php if($is_udhar): ?>
        <div class="udhar-watermark">CREDIT BILL</div>
    <?php else: ?>
        <div class="paid-stamp-box">
            PAID
            <span><?= date('d M Y', strtotime($bill['created_at'])) ?></span>
        </div>
        <div style="position: absolute; top: 100px; right: 220px; z-index: 100;">
            <img src="../assets/official stamp.png" style="width: 130px; opacity: 0.7; transform: rotate(-15deg);" alt="Official Stamp">
        </div>
    <?php endif; ?>

    <div class="no-print" style="margin-bottom: 30px; text-align: right;">
        <button onclick="window.print()" class="btn-print">
            <i class="fas fa-print me-2"></i> Print Bill / Save PDF
        </button>
    </div>

    <div class="header">
        <div class="logo-section">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo">
            <div class="shop-info">
                <h1><?= htmlspecialchars($shop['shop_name'] ?? '') ?></h1> 
                <p><?= htmlspecialchars($shop['shop_category'] ?? '') ?> Store<br>
                GSTIN: <?= htmlspecialchars($shop['gst_number'] ?? 'N/A') ?></p>
            </div>
        </div>
        <div style="text-align: right;">
            <?php if(!$is_udhar): ?>
                <div style="background: #2563eb; color: #fff; padding: 6px 15px; border-radius: 8px; font-weight: 800; font-size: 12px; display: inline-block; margin-bottom: 10px;">CASH MEMO</div>
            <?php endif; ?>
            <p style="margin: 0; font-size: 13px; color: #64748b;"> 
                Date: <strong><?= date('d M Y, h:i A') ?></strong><br>
                Inv No: <strong><?= htmlspecialchars($bill['bill_number']) ?></strong>
            </p>
        </div>
    </div>

    <div class="bill-meta">
        <div class="meta-item">
            <label>Customer Name</label>
            <span><?= htmlspecialchars($customer_name) ?></span> 
        </div>
        <div class="meta-item text-end">
            <label>Billed By</label>
            <span><?= htmlspecialchars($bill['owner_name'] ?? '') ?></span>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Item Description</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Rate</th>
                <th class="text-end">Disc</th>
                <th class="text-end">GST (%)</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($bill_items as $i => $item): ?>
            <tr>
                <td style="color: #94a3b8; font-weight: 600;"><?= $i + 1 ?></td>
                <td style="font-weight: 700; color: #0f172a;">
                    <!-- Using item_name as it is the standard column name for items -->
                    <?= htmlspecialchars($item['item_name'] ?? $item['name'] ?? 'Unknown Item') ?>
                </td>
                <td class="text-end"><?= (float)$item['quantity'] ?> <?= htmlspecialchars($item['unit'] ?? '') ?></td>
                <td class="text-end">₹<?= number_format($item['rate'], 2) ?></td>
                <td class="text-end">₹<?= number_format($item['item_discount_amount'], 2) ?></td>
                <td class="text-end"><?= number_format($item['gst_percent'], 2) ?>%</td>
                <td class="text-end" style="font-weight: 700;">₹<?= number_format($item['total_amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="summary-section">
        <div class="qr-section" style="display: flex; flex-direction: column; align-items: center;">
            <?php if($qr_url && !$is_udhar): ?>
            <div class="qr-box" style="margin-bottom: 10px;">
                <img src="<?= $qr_url ?>" alt="UPI Payment QR">
                <small>Scan to Pay ₹<?= number_format($final_net_amount, 2) ?></small>
            </div>
            <?php endif; ?>
            <div class="qr-box">
                <img src="<?= $view_bill_qr_url ?>" alt="View Bill QR">
                <small>Scan to View Bill Online</small>
            </div>
        </div>
        <div class="total-box">
            <div class="total-row"><label>Gross Total</label> <span>₹<?= number_format($total_gross_amount, 2) ?></span></div>
            <div class="total-row"><label>Total Discount</label> <span>₹<?= number_format($total_discount_amount, 2) ?></span></div>
            <div class="total-row"><label>Tax / GST (Included)</label> <span>₹<?= number_format($final_net_amount - ($total_gross_amount - $total_discount_amount), 2) ?></span></div>
            <div class="grand-total"><label>Net Payable</label> <span>₹<?= number_format($final_net_amount, 2) ?></span></div>
        </div>
    </div>

    <div class="footer">
        Thank you for your visit! Please come again.<br>
        Generated via <strong>KhataLink AI Voice POS</strong>.
    </div>
</body>
</html>