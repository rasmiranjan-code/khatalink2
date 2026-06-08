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
$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace(['Bearer ', ' '], ['', '+'], $token);

$customer_id = 0;
if (!empty($token)) {
    @ob_clean();
    $decoded = base64_decode($token);
    if($decoded) {
        $parts = explode(':', $decoded);
        $customer_id = (int)($parts[0] ?? 0);
    }
} else {
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

$shop_id = (int)($_GET['shop_id'] ?? 0);

if (!$customer_id || !$shop_id) {
    die("Unauthorized access or missing parameters.");
}
// Fetch Customer and Shop Details
$stmt = $pdo->prepare("
    SELECT c.name as customer_name, c.unique_id, 
           s.shop_name, s.shop_category, s.name as owner_name, s.upi_id, s.gst_number, sc.show_gst 
    FROM customers c 
    JOIN shop_customers sc ON c.id = sc.customer_id 
    JOIN shop_owners s ON s.id = sc.shop_id
    WHERE sc.customer_id = ? AND sc.shop_id = ?
");
$stmt->execute([$customer_id, $shop_id]);
$data = $stmt->fetch();

if(!$data) die("Customer not linked to this shop or shop not found.");

// Calculate Net Balance Due (Sum of all open udhar_entries for this customer at this shop)
$net_balance_due_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND shop_id = ? AND status = 'open'");
$net_balance_due_stmt->execute([$customer_id, $shop_id]);
$net_balance_due = $net_balance_due_stmt->fetchColumn();

// Fetch Transactions for this specific customer and shop
$history_query = "
    (SELECT id, 'Credit' as type, total_amount as amount, total_remaining, discount_percentage, created_at as date, 'open' as mode, pos_bill_id, NULL as rzp_id FROM udhar_entries WHERE customer_id = ? AND shop_id = ?)
    UNION ALL
    (SELECT MIN(id) as id, 'Payment' as type, SUM(amount_paid) as amount, 0 as total_remaining, 0 as discount_percentage, payment_date as date, payment_mode as mode, NULL as pos_bill_id, razorpay_payment_id as rzp_id FROM payment_history WHERE customer_id = ? AND shop_id = ? GROUP BY payment_date, payment_mode, razorpay_payment_id)
    ORDER BY date ASC
";
$stmt_hist = $pdo->prepare($history_query);
$stmt_hist->execute([$customer_id, $shop_id, $customer_id, $shop_id]);
$transactions = $stmt_hist->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement - <?= htmlspecialchars($data['customer_name']) ?> (<?= htmlspecialchars($data['shop_name']) ?>)</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; color: #1e293b; padding: 40px; line-height: 1.5; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f1f5f9; padding-bottom: 30px; margin-bottom: 30px; }
        .logo-section img { height: 50px; margin-bottom: 15px; }
        .shop-info h1 { margin: 0; color: #0f172a; font-size: 24px; font-weight: 800; }
        .shop-info p { margin: 5px 0 0 0; color: #64748b; font-size: 14px; }
        .customer-info h3 { margin: 0 0 5px 0; }
        .customer-info p { margin: 0; color: #64748b; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #eff6ff; text-align: left; padding: 14px 15px; border-bottom: 2px solid #2563eb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #1e4ed8; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: top; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .credit { color: #dc2626; font-weight: bold; }
        .payment { color: #059669; font-weight: bold; }
        .item-details { font-size: 12px; color: #64748b; margin-top: 5px; list-style: none; padding: 0; }
        .item-details li { display: inline-block; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; margin-right: 5px; margin-bottom: 2px; }
        .footer { margin-top: 60px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 20px; }
        .summary-box { background: #f8fafc; border: 1px solid #e2e8f0; border-left: 5px solid #2563eb; border-radius: 12px; padding: 20px; margin-top: 30px; display: flex; justify-content: flex-end; }
        .summary-item { text-align: right; margin-left: 40px; }
        .summary-label { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 5px; }
        .summary-value { font-size: 18px; font-weight: 800; color: #0f172a; }
        .row-qr { width: 65px; height: 65px; border: 1px solid #f1f5f9; border-radius: 6px; padding: 2px; background: #fff; }
        .footer-wrap { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 30px; }
        .qr-section { text-align: center; background: #fff; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; width: 140px; }
        .qr-section img { width: 120px; height: 120px; display: block; }
        .qr-label { display: block; font-size: 9px; font-weight: 800; color: #64748b; margin-top: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        
        /* Premium Paid Stamp */
        .paid-stamp {
            position: absolute;
            top: 160px;
            right: 100px;
            width: 180px;
            transform: rotate(-20deg);
            opacity: 0.8;
            z-index: 100;
            pointer-events: none;
        }
        .stamp-brand { display: block; font-size: 10px; margin-top: -2px; font-weight: 700; letter-spacing: 1px; }
        .stamp-verify { display: block; font-size: 9px; color: #059669; font-weight: 600; margin-top: 2px; }

        /* Signature Section */
        .signature-section {
            display: flex;
            justify-content: flex-end;
            margin-top: 50px;
            padding-right: 20px;
        }
        .signature-box { 
            text-align: center; 
            width: 220px; 
            position: relative; 
        }
        .signature-box::after {
            content: 'OFFICIAL SEAL';
            position: absolute;
            top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-10deg);
            font-size: 32px; font-weight: 900; color: rgba(37, 99, 235, 0.05);
            letter-spacing: 2px; z-index: -1; pointer-events: none;
        }
        .signature-name { font-family: 'Great Vibes', cursive; font-size: 32px; color: #000; margin-bottom: -10px; }
        .signature-line { border-top: 1.5px solid #0f172a; margin-bottom: 8px; }
        .signature-text { font-size: 11px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 1px; }
        .signature-owner { font-size: 11px; color: #64748b; margin-top: 2px; }
        .moto { text-align: center; margin-top: 40px; font-size: 16px; font-weight: 700; color: #94a3b8; letter-spacing: 2px; text-transform: uppercase; }

        @media (max-width: 600px) {
            body { padding: 15px; }
            .header { flex-direction: column; align-items: center; text-align: center !important; }
            .customer-info { text-align: center !important; margin-top: 15px; width: 100%; }
            .footer-wrap { flex-direction: column; align-items: center; }
            .summary-box { margin-left: 0; width: 100%; margin-top: 20px; }
            table { font-size: 10px; }
            th, td { padding: 8px 5px !important; }
            .paid-stamp { top: 120px; right: 50%; transform: translateX(50%) rotate(-20deg); width: 140px; }
            .row-qr { width: 45px; height: 45px; }
            .signature-section { justify-content: center; padding-right: 0; }
        }

        /* Page Watermark */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            opacity: 0.04;
            z-index: -100;
            width: 70%;
            pointer-events: none;
        }

        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <!-- Branding Watermark -->
    <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="watermark" alt="KhataLink Watermark">

    <?php if((float)$net_balance_due <= 0.01): ?>
        <img src="../assets/official stamp.png" class="paid-stamp" alt="Paid Stamp">
    <?php endif; ?>

    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 5px; cursor: pointer;">
            Print Statement / Save as PDF
        </button>
    </div>

    <div class="header">
        <div class="logo-section">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo">
            <div class="shop-info">
                <h1><?= htmlspecialchars($data['shop_name']) ?></h1>
                <p>Digital Ledger Statement<br>Owner: <?= htmlspecialchars($data['owner_name']) ?></p>
                <?php if($data['show_gst'] && !empty($data['gst_number'])): ?>
                    <p style="color: #0f172a; font-weight: 700; font-size: 12px; margin-top: 5px;">GSTIN: <?= htmlspecialchars($data['gst_number']) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="customer-info" style="text-align: right;">
            <h3>Customer: <?= htmlspecialchars($data['customer_name']) ?></h3>
            <p>ID: <?= $data['unique_id'] ?><br>Date: <?= date('d M Y') ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Transaction Details</th>
                <th class="text-end">Debit (In)</th>
                <th class="text-end">Credit (Out)</th>
                <th class="text-end">Running Balance</th>
                <th class="text-end">Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $balance = 0;
            $total_credit = 0;
            $total_payment = 0;
            $running_balance_display = 0; // To show running balance before current transaction
            foreach($transactions as $t): 
                $entry_discount = ($t['type'] == 'Credit') ? ($t['amount'] * ($t['discount_percentage'] / 100)) : 0;
                $net_amount = $t['amount'] - $entry_discount;

                if($t['type'] == 'Credit') {
                    $running_balance_display = $balance; // Balance before this credit
                    $balance += $net_amount;
                    $total_credit += $net_amount;
                } else {
                    $running_balance_display = $balance; // Balance before this payment
                    $balance -= $t['amount'];
                    
                    // Calculate actual amount paid by customer for the summary
                    $is_online_payment = !empty($t['rzp_id']) && $t['rzp_id'] !== 'Manual';
                    $customer_spent = $is_online_payment ? ($t['amount'] * (1 + (LEDGER_PLATFORM_COMMISSION_PERCENT / 100))) : $t['amount'];
                    $total_payment += $customer_spent;
                }
            ?>
            <tr>
                <td style="white-space: nowrap;"><?= date('d M Y', strtotime($t['date'])) ?></td>
                <td>
                    <div style="font-weight: 600;"><?= $t['type'] == 'Credit' ? 'Purchase / Udhar' : 'Payment Received' ?></div>
                    <div class="small text-muted" style="font-size: 11px; margin-top: 2px;">
                        <?= $t['type'] == 'Credit' ? 'Itemized Entry' : 'Mode: ' . htmlspecialchars($t['mode']) ?>
                    </div>
                    <?php if($t['type'] == 'Payment' && !empty($t['rzp_id'])): ?>
                        <div style="font-size: 9px; color: #059669; font-weight: 800; margin-top: 4px; text-transform: uppercase;">
                            <i class="fas fa-check-circle"></i> Verified Online: <?= htmlspecialchars($t['rzp_id']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if($t['type'] == 'Credit' && $t['discount_percentage'] > 0): ?>
                        <div style="font-size: 10px; color: #059669; font-weight: 600; margin-top: 5px;">
                            <i class="fas fa-tag me-1"></i> <?= number_format($t['discount_percentage'], 0) ?>% Discount Applied (-₹<?= number_format($entry_discount, 2) ?>)
                        </div>
                    <?php endif; ?>
                    <?php if($t['type'] == 'Credit'): ?>
                        <?php
                        $item_stmt = $pdo->prepare("SELECT field_name, quantity, rate, amount FROM udhar_items WHERE entry_id = ?");
                        $item_stmt->execute([$t['id']]);
                        $items = $item_stmt->fetchAll();
                        if($items): ?>
                            <ul class="item-details">
                                <?php foreach($items as $item): ?>
                                    <li><?= htmlspecialchars($item['field_name']) ?>: <?= (float)$item['quantity'] ?> x ₹<?= number_format($item['rate'], 2) ?> = ₹<?= number_format($item['amount'], 2) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if($t['pos_bill_id']): ?>
                                <div style="font-size: 9px; color: #0f172a; font-weight: 800; margin-top: 5px; text-transform: uppercase;">
                                    <i class="fas fa-file-invoice"></i> POS Bill #<?= $t['pos_bill_id'] ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="text-end <?= ($t['type'] == 'Payment') ? 'payment' : '' ?>">
                    <?php if($t['type'] == 'Payment'): 
                        $is_online = !empty($t['rzp_id']) && $t['rzp_id'] !== 'Manual';
                        // Show amount with platform fees for online payments
                        $display_p = $is_online ? ($t['amount'] * (1 + (LEDGER_PLATFORM_COMMISSION_PERCENT / 100))) : $t['amount'];
                        echo '₹' . number_format($display_p, 2);
                    else: echo '—'; 
                    endif; ?>
                </td>
                <td class="text-end <?= ($t['type'] == 'Credit') ? 'credit' : '' ?>">
                    <?php if($t['type'] == 'Credit'): ?>
                        <div style="font-size: 13px; font-weight: 800;">₹<?= number_format($net_amount, 2) ?></div>
                        <?php if($t['discount_percentage'] > 0): ?>
                            <div style="font-size: 9px; text-decoration: line-through; color: #94a3b8;">₹<?= number_format($t['amount'], 2) ?></div>
                        <?php endif; ?>
                    <?php else: echo '—'; endif; ?>
                </td>
                <td class="text-end" style="font-weight: 600;">
                    ₹<?= number_format($running_balance_display, 2) ?>
                </td>
                <td class="text-end" style="font-weight: 600;">₹<?= number_format($balance, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer-wrap">
        <div class="qr-section">
            <?php if(!empty($data['upi_id']) && (float)$net_balance_due > 0.01): 
                $clean_net_amt = number_format(round((float)$net_balance_due, 2), 2, '.', '');
                $upi_payload = "upi://pay?pa=" . htmlspecialchars($data['upi_id']) . "&pn=" . urlencode($data['shop_name']) . "&am=" . $clean_net_amt . "&cu=INR";
                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($upi_payload);
            ?>
                <img src="<?= $qr_url ?>" alt="UPI QR Code">
                <span class="qr-label">Scan to Pay via UPI</span>
            <?php else: ?>
                <div style="width: 120px; height: 120px; display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 10px; border: 1px dashed #e2e8f0; border-radius: 8px; text-align: center; padding: 10px;">
                    <?= $balance > 0 ? 'No UPI ID provided' : 'No Balance Due' ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="summary-box" style="margin-top: 0; flex: 1; margin-left: 20px;">
            <div class="summary-item">
                <div class="summary-label">Total Udhar</div>
                <div class="summary-value" style="color: #dc2626;">₹<?= number_format($total_credit, 2) ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Total Paid (Incl. Fees)</div>
                <div class="summary-value" style="color: #059669;">₹<?= number_format($total_payment, 2) ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Net Balance Due</div>
                <div class="summary-value" style="color: #2563eb; font-size: 24px;">₹<?= number_format($balance, 2) ?></div>
                <?php if($balance > 0.01 && !empty($data['upi_id'])): 
                    $full_pay_link = "upi://pay?pa=" . htmlspecialchars($data['upi_id']) . "&pn=" . urlencode($data['shop_name']) . "&am=" . number_format($balance, 2, '.', '') . "&cu=INR";
                ?>
                    <a href="<?= $full_pay_link ?>" class="no-print" style="display:inline-block; margin-top:10px; padding:6px 12px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px; font-size:11px; font-weight:800; text-transform:uppercase;">Pay Full Balance</a>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top: 30px; text-align: center; font-size: 10px; color: #94a3b8;">
            * Online payments include a 3% platform convenience fee.
        </div>
    </div>

    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-name"><?= htmlspecialchars($data['owner_name']) ?></div>
            <div class="signature-line"></div>
            <div class="signature-text">Authorized Signature</div>
            <div class="signature-owner">For <?= htmlspecialchars($data['shop_name']) ?></div>
        </div>
    </div>

    <div class="footer">
        This is a computer-generated document. Generated via <strong>KhataLink</strong> Digital Ledger.
    </div>
</body>
</html>