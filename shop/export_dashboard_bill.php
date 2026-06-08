<?php
session_start();
require_once '../includes/db.php';

// This file is specifically for printing the last bill from the dashboard.
// It relies on bill data stored in the session by export_pos_bill.php.

$bill_data = [];
$customer_name = 'Guest Customer';
$shop_id = (int)($_SESSION['shop_id'] ?? 0);

if (isset($_SESSION['last_bill_data']) && isset($_SESSION['last_bill_cust'])) {
    $bill_data_raw = json_decode($_SESSION['last_bill_data'], true);
    $customer_name = $_SESSION['last_bill_cust'];

    // Reformat bill_data to match the structure expected by the template
    foreach ($bill_data_raw as $item) {
        $bill_data[] = [
            'name' => $item['name'] ?? $item['n'],
            'unit' => $item['unit'] ?? $item['u'] ?? 'NOS',
            'qty' => $item['qty'] ?? $item['q'],
            'rate' => $item['rate'] ?? $item['r'],
        ];
    }
} else {
    die("No last bill data found in session. Please generate a bill first.");
}

// Fetch Shop Details
$stmt = $pdo->prepare("SELECT * FROM shop_owners WHERE id = ?");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch();

if (!$shop) die("Shop details not found.");

// Calculate Totals
$grand_total = 0;
foreach ($bill_data as $item) {
    $grand_total += (float)$item['qty'] * (float)$item['rate'];
}

// Generate UPI QR Code URL
$qr_url = "";
if (!empty($shop['upi_id']) && $grand_total > 0) {
    $upi_payload = "upi://pay?pa=" . htmlspecialchars($shop['upi_id']) . "&pn=" . urlencode($shop['shop_name']) . "&am=" . number_format($grand_total, 2, '.', '') . "&cu=INR&tn=POS_Bill";
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($upi_payload);
}
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
        body {
            font-family: 'Inter', sans-serif;
            color: #1e293b;
            padding: 40px;
            line-height: 1.5;
            background: #fff;
            position: relative;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 25px;
            margin-bottom: 30px;
        }

        .logo-section img {
            height: 45px;
            margin-bottom: 10px;
        }

        .shop-info h1 {
            margin: 0;
            color: #0f172a;
            font-size: 22px;
            font-weight: 800;
        }

        .shop-info p {
            margin: 3px 0 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .bill-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            background: #f8fafc;
            padding: 15px 20px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .meta-item label {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            color: #94a3b8;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .meta-item span {
            font-weight: 700;
            color: #0f172a;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 15px;
            border-bottom: 2px solid #e2e8f0;
            font-size: 11px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 800;
        }

        td {
            padding: 14px 15px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }

        .text-end {
            text-align: right;
        }

        .summary-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 40px;
        }

        .qr-box {
            text-align: center;
            border: 1px solid #e2e8f0;
            padding: 10px;
            border-radius: 12px;
            background: #fff;
            width: 130px;
        }

        .qr-box img {
            width: 110px;
            height: 110px;
            display: block;
        }

        .qr-box small {
            display: block;
            font-size: 9px;
            font-weight: 700;
            color: #94a3b8;
            margin-top: 5px;
        }

        .total-box {
            text-align: right;
        }

        .total-row {
            margin-bottom: 5px;
        }

        .total-row label {
            font-size: 13px;
            color: #64748b;
        }

        .total-row span {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            margin-left: 15px;
        }

        .grand-total {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #e2e8f0;
        }

        .grand-total label {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
        }

        .grand-total span {
            font-size: 24px;
            font-weight: 900;
            color: #2563eb;
            margin-left: 15px;
        }

        .footer {
            margin-top: 80px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
            padding-top: 25px;
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            opacity: 0.03;
            z-index: -100;
            width: 60%;
            pointer-events: none;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                padding: 0;
            }
        }

        .btn-print {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-print:hover {
            background: #1d4ed8;
        }
    </style>
</head>

<body>
    <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="watermark" alt="">

    <div class="no-print" style="margin-bottom: 30px; text-align: right;">
        <button onclick="window.print()" class="btn-print">
            <i class="fas fa-print me-2"></i> Print Bill / Save PDF
        </button>
    </div>

    <div class="header">
        <div class="logo-section">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo">
            <div class="shop-info">
                <h1><?= htmlspecialchars($shop['shop_name']) ?></h1>
                <p><?= htmlspecialchars($shop['shop_category']) ?> Store<br>
                GSTIN: <?= htmlspecialchars($shop['gst_number'] ?: 'N/A') ?></p>
            </div>
        </div>
        <div style="text-align: right;">
            <div style="background: #2563eb; color: #fff; padding: 6px 15px; border-radius: 8px; font-weight: 800; font-size: 12px; display: inline-block; margin-bottom: 10px;">CASH MEMO</div>
            <p style="margin: 0; font-size: 13px; color: #64748b;">
                Date: <strong><?= date('d M Y, h:i A') ?></strong><br>
                Inv No: <strong>POS-<?= time() ?></strong>
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
            <span><?= htmlspecialchars($shop['name']) ?></span>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Item Description</th>
                <th class="text-end">Unit</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Rate</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($bill_data as $i => $item): ?>
            <tr>
                <td style="color: #94a3b8; font-weight: 600;"><?= $i + 1 ?></td>
                <td style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($item['name']) ?></td>
                <td class="text-end"><?= htmlspecialchars($item['unit'] ?? 'NOS') ?></td>
                <td class="text-end"><?= (float)$item['qty'] ?></td>
                <td class="text-end">₹<?= number_format($item['rate'], 2) ?></td>
                <td class="text-end" style="font-weight: 700;">₹<?= number_format((float)$item['qty'] * (float)$item['rate'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="summary-section">
        <div class="qr-section">
            <?php if($qr_url): ?>
            <div class="qr-box">
                <img src="<?= $qr_url ?>" alt="Payment QR">
                <small>Scan to Pay ₹<?= number_format($grand_total, 2) ?></small>
            </div>
            <?php endif; ?>
        </div>
        <div class="total-box">
            <div class="total-row"><label>Subtotal</label> <span>₹<?= number_format($grand_total, 2) ?></span></div>
            <div class="total-row"><label>Tax / GST (Included)</label> <span>₹0.00</span></div>
            <div class="grand-total"><label>Grand Total</label> <span>₹<?= number_format($grand_total, 2) ?></span></div>
        </div>
    </div>

    <div class="footer">
        Thank you for your visit! Please come again.<br>
        Generated via <strong>KhataLink AI Voice POS</strong>.
    </div>
</body>

</html>