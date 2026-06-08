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
require_once '../includes/cashfree_config.php'; // For LEDGER_PLATFORM_COMMISSION_PERCENT

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

if (!$customer_id) {
    die("Unauthorized access.");
}

// Date Filtering
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$is_filtered = ($from_date && $to_date);

// Fetch Customer Details
$stmt = $pdo->prepare("SELECT name as customer_name, unique_id FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer_data = $stmt->fetch();

if(!$customer_data) die("Customer not found.");

// Calculate Net Balance Due (Sum of all open udhar_entries for this customer across all shops)
$net_balance_due_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND status = 'open'");
$net_balance_due_stmt->execute([$customer_id]);
$net_balance_due = $net_balance_due_stmt->fetchColumn();

// Fetch All Transactions for this specific customer across all shops
$date_filter_ue = "";
$date_filter_ph = "";
$sql_params = [$customer_id];

if ($is_filtered) {
    $date_filter_ue = " AND DATE(ue.created_at) BETWEEN ? AND ?";
    $date_filter_ph = " AND DATE(ph.payment_date) BETWEEN ? AND ?";
    $sql_params = [$customer_id, $from_date, $to_date, $customer_id, $from_date, $to_date];
} else {
    $sql_params = [$customer_id, $customer_id];
}

$history_query = "
    (SELECT ue.id, 'Credit' as type, ue.total_amount as amount, ue.total_remaining, ue.discount_percentage, ue.created_at as date, 'open' as mode, s.shop_name, s.shop_category, s.upi_id, s.gst_number, NULL as rzp_id, ue.pos_bill_id
     FROM udhar_entries ue
     JOIN shop_owners s ON ue.shop_id = s.id
     WHERE ue.customer_id = ?" . $date_filter_ue . ")
    UNION ALL
    (SELECT ph.id, 'Payment' as type, ph.amount_paid as amount, 0 as total_remaining, 0 as discount_percentage, ph.payment_date as date, ph.payment_mode as mode, s.shop_name, s.shop_category, s.upi_id, s.gst_number, ph.razorpay_payment_id as rzp_id, NULL as pos_bill_id
     FROM payment_history ph
     JOIN shop_owners s ON ph.shop_id = s.id
     WHERE ph.customer_id = ?" . $date_filter_ph . ")
    ORDER BY date ASC
";
$stmt_hist = $pdo->prepare($history_query);
$stmt_hist->execute($sql_params);
$transactions = $stmt_hist->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Statement - <?= htmlspecialchars($customer_data['customer_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; color: #1e293b; padding: 40px; line-height: 1.5; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f1f5f9; padding-bottom: 30px; margin-bottom: 30px; }
        .logo-section img { height: 50px; margin-bottom: 15px; }
        .main-title { margin: 0; color: #0f172a; font-size: 28px; font-weight: 800; }
        .subtitle { margin: 5px 0 0 0; color: #64748b; font-size: 14px; }
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
        .footer-wrap { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 30px; }
        .qr-section { text-align: center; background: #fff; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; width: 140px; }
        .qr-section img { width: 120px; height: 120px; display: block; }
        .qr-label { display: block; font-size: 9px; font-weight: 800; color: #64748b; margin-top: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .paid-stamp { position: absolute; top: 160px; right: 100px; width: 180px; transform: rotate(-20deg); opacity: 0.8; z-index: 100; pointer-events: none; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); opacity: 0.04; z-index: -100; width: 70%; pointer-events: none; }

        /* Filter Form Styles */
        .filter-form { display: flex; gap: 15px; align-items: flex-end; background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 30px; }
        .filter-form div { display: flex; flex-direction: column; gap: 5px; }
        .filter-form label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .filter-form input { padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-weight: 600; color: #1e293b; outline: none; }
        .btn-filter { background: #2563eb; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-print { background: #0f172a; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }

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

    <div class="no-print" style="margin-bottom: 20px;">
        <form method="GET" class="filter-form">
            <div>
                <label>From Date</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div>
                <label>To Date</label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply Filter</button>
            <button type="button" onclick="window.print()" class="btn-print" style="margin-left: auto;">
                Print Statement / Save as PDF
            </button>
        </form>
    </div>

    <div class="header">
        <div class="logo-section">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo">
            <div class="shop-info">
                <h1 class="main-title">KhataLink Consolidated Statement</h1>
                <p class="subtitle">
                    <?php if($is_filtered): ?>
                        Transactions from <?= date('d M Y', strtotime($from_date)) ?> to <?= date('d M Y', strtotime($to_date)) ?>
                    <?php else: ?>
                        All Transactions Across Your Linked Shops
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="customer-info" style="text-align: right;">
            <h3>Customer: <?= htmlspecialchars($customer_data['customer_name']) ?></h3>
            <p>ID: <?= $customer_data['unique_id'] ?><br>Date: <?= date('d M Y') ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Shop</th>
                <th>Transaction Details</th>
                <th class="text-end">Debit (In)</th>
                <th class="text-end">Credit (Out)</th>
                <th class="text-end">Running Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $balance = 0;
            $total_credit = 0;
            $total_payment = 0;
            foreach($transactions as $t):
                $entry_discount = ($t['type'] == 'Credit') ? ($t['amount'] * ($t['discount_percentage'] / 100)) : 0;
                $net_amount = $t['amount'] - $entry_discount;

                $running_balance_display = $balance; // Balance before this transaction

                if($t['type'] == 'Credit') {
                    $balance += $net_amount;
                    $total_credit += $net_amount;
                } else {
                    // Calculate actual amount paid by customer for the summary, including fees for online payments
                    $is_online_payment = !empty($t['rzp_id']) && $t['rzp_id'] !== 'Manual';
                    $customer_spent = $is_online_payment ? ((float)$t['amount'] * (1 + (LEDGER_PLATFORM_COMMISSION_PERCENT / 100))) : (float)$t['amount'];
                    $balance -= $t['amount']; // Balance should be reduced by the base amount (what the shop receives)
                    $total_payment += $customer_spent; // Total paid should include fees
                }
            ?>
            <tr>
                <td style="white-space: nowrap;"><?= date('d M Y', strtotime($t['date'])) ?></td>
                <td>
                    <div style="font-weight: 600;"><?= htmlspecialchars($t['shop_name']) ?></div>
                    <div style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($t['shop_category']) ?></div>
                </td>
                <td>
                    <div style="font-weight: 600;"><?= $t['type'] == 'Credit' ? 'Purchase / Udhar' : 'Payment' ?></div>
                    <div style="font-size: 11px; margin-top: 2px; color: #64748b;">
                        <?= $t['type'] == 'Credit' ? 'Itemized Entry' : 'Mode: ' . htmlspecialchars($t['mode']) ?>
                    </div>
                    <?php if($t['type'] == 'Payment' && !empty($t['rzp_id'])): ?>
                        <div style="font-size: 9px; color: #059669; font-weight: 800; margin-top: 4px; text-transform: uppercase;">
                            <i class="fas fa-check-circle"></i> Verified Receipt: <?= htmlspecialchars($t['rzp_id']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if($t['type'] == 'Credit' && $t['discount_percentage'] > 0): ?>
                        <div style="font-size: 10px; color: #059669; font-weight: 600; margin-top: 5px;">
                            <i class="fas fa-tag me-1"></i> <?= number_format($t['discount_percentage'], 0) ?>% Discount (-₹<?= number_format($entry_discount, 2) ?>)
                        </div>
                    <?php endif; ?>
                    <?php if($t['type'] == 'Credit'): ?>
                        <?php
                        // Fetch items for this specific udhar entry
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
                        $display_p = $is_online ? ((float)$t['amount'] * (1 + (LEDGER_PLATFORM_COMMISSION_PERCENT / 100))) : (float)$t['amount'];
                        echo '₹' . number_format($display_p, 2);
                    else: echo '—'; endif; ?>
                </td>
                <td class="text-end <?= ($t['type'] == 'Credit') ? 'credit' : '' ?>">
                    <?php if($t['type'] == 'Credit'): ?>
                        <div style="font-size: 13px; font-weight: 800;">₹<?= number_format($net_amount, 2) ?></div>
                        <?php if($t['discount_percentage'] > 0): ?>
                            <div style="font-size: 9px; text-decoration: line-through; color: #94a3b8;">₹<?= number_format($t['amount'], 2) ?></div>
                        <?php endif; ?>
                    <?php else: echo '—'; endif; ?>
                </td>
                <td class="text-end" style="font-weight: 600;">₹<?= number_format($balance, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer-wrap">
        <div class="qr-section">
            <?php 
            $generic_upi_id = "your_customer_upi@bank"; // Replace with a generic customer UPI or a payment gateway link
            if((float)$net_balance_due > 0.01 && !empty($generic_upi_id)):
                // Consolidated UPI QR for total due (if any)
                // Note: This QR will point to a generic UPI ID if no specific shop is chosen.
                // For a consolidated statement, it's better to provide a generic payment link or list shop-wise UPIs.
                // For simplicity, we'll just show a placeholder or a generic link if available.
                // A more robust solution would involve a payment gateway or a list of shop UPIs.
                $clean_net_amt = number_format(round((float)$net_balance_due, 2), 2, '.', '');
                $upi_payload = "upi://pay?pa=" . htmlspecialchars($generic_upi_id) . "&pn=" . urlencode($customer_data['customer_name']) . "&am=" . $clean_net_amt . "&cu=INR&tn=KhataLink_Full_Payment";
                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($upi_payload);
            ?>
                <img src="<?= $qr_url ?>" alt="UPI QR Code">
                <span class="qr-label">Scan to Pay Total Due</span>
            <?php else: ?>
                <div style="width: 120px; height: 120px; display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 10px; border: 1px dashed #e2e8f0; border-radius: 8px; text-align: center; padding: 10px;">
                    <?= $net_balance_due > 0 ? 'No UPI ID for consolidated payment' : 'No Balance Due' ?>
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
                <div class="summary-value" style="color: #2563eb; font-size: 24px;">₹<?= number_format($net_balance_due, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="footer">
        <div style="margin-bottom: 8px; font-weight: 600;">* Online payments include a 3% platform convenience fee.</div>
        This is a computer-generated document. Generated via <strong>KhataLink</strong> Digital Ledger.
    </div>
</body>
</html>
