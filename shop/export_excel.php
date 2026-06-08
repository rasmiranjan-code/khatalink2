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

// ── AUTH ──────────────────────────────────────────────────────────────────
$token = $_GET['token']?? $_SERVER['HTTP_AUTHORIZATION']?? '';
$token = str_replace(['Bearer ', ' '], ['', '+'], $token);

$shop_id = 0;
if (!empty($token)) {
    @ob_clean();
    $decoded = base64_decode($token);
    if($decoded) {
        $parts = explode(':', $decoded);
        $shop_id = (int)($parts[0]?? 0);
    }
} else {
    $shop_id = (int)($_SESSION['shop_id']?? 0);
}

$customer_id = (int)($_GET['customer_id']?? 0);
$ids = isset($_GET['ids'])? $_GET['ids'] : '';
$format = $_GET['format']?? 'csv'; // csv or html

if (!$shop_id ||!$customer_id) {
    die("Unauthorized access or missing parameters.");
}

// ── FETCH DATA ──────────────────────────────────────────────────────────────
$shop = $pdo->prepare("SELECT shop_name, name as owner_name, gst_number FROM shop_owners WHERE id =?");
$shop->execute([$shop_id]);
$shop_data = $shop->fetch();

$customer = $pdo->prepare("SELECT c.name, c.unique_id, c.phone, c.email FROM customers c 
    JOIN shop_customers sc ON c.id = sc.customer_id 
    WHERE sc.shop_id =? AND c.id =?");
$customer->execute([$shop_id, $customer_id]);
$customer_data = $customer->fetch();

if(!$customer_data) die("Customer not found.");

$id_filter_udhar = '';
$id_filter_payment = '';
$params = [$shop_id, $customer_id, $shop_id, $customer_id];

if(!empty($ids)) {
    $id_array = array_map('intval', explode(',', $ids));
    $placeholders = implode(',', array_fill(0, count($id_array), '?'));
    $id_filter_udhar = " AND id IN ($placeholders)";
    $id_filter_payment = " AND id IN ($placeholders)";
    $params = array_merge($params, $id_array, $id_array);
}

$sql = "
    (SELECT id, 'Udhar' as type, total_amount as amount, total_remaining, discount_percentage, created_at as date, 'open' as mode, pos_bill_id, NULL as rzp_id FROM udhar_entries WHERE shop_id =? AND customer_id =? $id_filter_udhar)
    UNION ALL
    (SELECT MIN(id) as id, 'Payment' as type, SUM(amount_paid) as amount, 0 as total_remaining, 0 as discount_percentage, payment_date as date, payment_mode as mode, NULL as pos_bill_id, razorpay_payment_id as rzp_id FROM payment_history WHERE shop_id =? AND customer_id =? $id_filter_payment GROUP BY payment_date, payment_mode, razorpay_payment_id)
    ORDER BY date ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// ── HTML PREVIEW MODE ───────────────────────────────────────────────────────
if($format == 'html') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Excel Preview - <?= htmlspecialchars($customer_data['name'])?></title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', sans-serif; padding: 20px; background: #f8fafc; }
            .container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 20px; padding: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
            .header { border-bottom: 3px solid #2563eb; padding-bottom: 20px; margin-bottom: 30px; }
            .shop-name { font-size: 24px; font-weight: 900; color: #0f172a; text-transform: uppercase; }
            .customer-name { font-size: 18px; font-weight: 700; color: #2563eb; margin-top: 10px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background: #eff6ff; text-align: left; padding: 12px; border: 1px solid #bfdbfe; font-size: 11px; text-transform: uppercase; color: #1e4ed8; font-weight: 800; }
            td { padding: 12px; border: 1px solid #e2e8f0; font-size: 13px; }
            .text-end { text-align: right; }
            .credit { color: #dc2626; font-weight: 700; }
            .payment { color: #059669; font-weight: 700; }
            .summary { background: #f8fafc; border-left: 5px solid #2563eb; border-radius: 8px; padding: 20px; margin-top: 30px; display: flex; justify-content: space-around; }
            .summary div { text-align: center; }
            .summary-label { font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700; }
            .summary-value { font-size: 20px; font-weight: 900; color: #0f172a; margin-top: 5px; }
            .download-btn { background: #059669; color: white; padding: 12px 24px; border-radius: 12px; text-decoration: none; font-weight: 800; display: inline-block; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="shop-name"><?= htmlspecialchars($shop_data['shop_name'])?></div>
                <div class="customer-name">Customer: <?= htmlspecialchars($customer_data['name'])?> (<?= $customer_data['unique_id']?>)</div>
                <div style="font-size: 12px; color: #64748b; margin-top: 5px;">Generated: <?= date('d M Y h:i A')?></div>
            </div>

            <a href="export_excel.php?customer_id=<?= $customer_id?>&ids=<?= $ids?>&format=csv&token=<?= urlencode($token)?>" class="download-btn">
                <i class="fas fa-file-csv"></i> Download CSV File
            </a>

            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $balance = 0;
                    $total_credit = 0;
                    $total_payment = 0;
                    foreach($transactions as $t):
                        $net_amount = $t['amount'] - ($t['amount'] * ($t['discount_percentage'] / 100));
                        if($t['type'] == 'Udhar') {
                            $balance += $net_amount;
                            $total_credit += $net_amount;
                        } else {
                            $balance -= $t['amount'];
                            $total_payment += $t['amount'];
                        }
                    ?>
                    <tr>
                        <td><?= date('d-m-Y', strtotime($t['date']))?></td>
                        <td><?= $t['type']?></td>
                        <td>
                            <?= $t['type'] == 'Udhar'? 'Purchase' : 'Mode: '.htmlspecialchars($t['mode'])?>
                            <?php if($t['type'] == 'Payment' &&!empty($t['rzp_id'])):?>
                                <br><small style="color: #059669; font-weight: 700;">Verified: <?= htmlspecialchars($t['rzp_id'])?></small>
                            <?php endif;?>
                        </td>
                        <td class="text-end payment"><?= $t['type'] == 'Payment'? '₹'.number_format($t['amount'], 2) : '—'?></td>
                        <td class="text-end credit"><?= $t['type'] == 'Udhar'? '₹'.number_format($net_amount, 2) : '—'?></td>
                        <td class="text-end" style="font-weight: 700;">₹<?= number_format($balance, 2)?></td>
                    </tr>
                    <?php endforeach;?>
                </tbody>
            </table>

            <div class="summary">
                <div>
                    <div class="summary-label">Total Udhar</div>
                    <div class="summary-value" style="color: #dc2626;">₹<?= number_format($total_credit, 2)?></div>
                </div>
                <div>
                    <div class="summary-label">Total Paid</div>
                    <div class="summary-value" style="color: #059669;">₹<?= number_format($total_payment, 2)?></div>
                </div>
                <div>
                    <div class="summary-label">Closing Balance</div>
                    <div class="summary-value" style="color: #2563eb;">₹<?= number_format($balance, 2)?></div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ── CSV GENERATION - No library needed ──────────────────────────────────────
$filename = 'statement_'. $customer_data['unique_id']. '_'. date('Ymd'). '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'. $filename. '"');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header
fputcsv($output, [strtoupper($shop_data['shop_name'])]);
fputcsv($output, ['Customer:', $customer_data['name']. ' ('. $customer_data['unique_id']. ')']);
fputcsv($output, ['Generated:', date('d M Y h:i A')]);
fputcsv($output, []);

// Column Headers
fputcsv($output, ['Date', 'Type', 'Description', 'Debit (In)', 'Credit (Out)', 'Balance']);

// Data
$balance = 0;
$total_credit = 0;
$total_payment = 0;

foreach($transactions as $t) {
    $net_amount = $t['amount'] - ($t['amount'] * ($t['discount_percentage'] / 100));
    
    if($t['type'] == 'Udhar') {
        $balance += $net_amount;
        $total_credit += $net_amount;
    } else {
        $balance -= $t['amount'];
        $total_payment += $t['amount'];
    }

    $desc = $t['type'] == 'Udhar'? 'Purchase' : 'Mode: '. $t['mode'];
    if($t['type'] == 'Payment' &&!empty($t['rzp_id'])) {
        $desc .= ' | Verified: '. $t['rzp_id'];
    }

    fputcsv($output, [
        date('d-m-Y', strtotime($t['date'])),
        $t['type'],
        $desc,
        $t['type'] == 'Payment'? $t['amount'] : '',
        $t['type'] == 'Udhar'? $net_amount : '',
        $balance
    ]);
}

// Summary
fputcsv($output, []);
fputcsv($output, ['', '', 'Total Udhar:', '', $total_credit, '']);
fputcsv($output, ['', '', 'Total Paid:', $total_payment, '', '']);
fputcsv($output, ['', '', 'Closing Balance:', '', '', $balance]);

fclose($output);
exit();
?>