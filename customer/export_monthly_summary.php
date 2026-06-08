<?php
session_start(); // Move session_start to the very top
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

require_once '../includes/db.php';

$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace(['Bearer ', ' '], ['', '+'], $token);

$customer_id = 0;
$auth_source = 'unknown';

if (!empty($token)) {
    $auth_source = 'token';
    @ob_clean();
    $decoded = base64_decode($token);
    if($decoded) {
        $parts = explode(':', $decoded);
        $customer_id = (int)($parts[0] ?? 0);
    }
} else {
    $auth_source = 'session';
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if (!$customer_id) {
    error_log("DEBUG: export_monthly_summary.php - Dying because customer_id is falsey. Auth Source: " . $auth_source);
    die("Unauthorized access.");
}

// Get current month and year
$current_month_start = date('Y-m-01 00:00:00');
$current_month_end = date('Y-m-t 23:59:59');
$month_label = date('M Y');

// Fetch all transactions for the current month
$query = "
    (SELECT ue.id, 'Credit' as type, ue.total_amount as amount, ue.discount_percentage, ue.created_at as date, s.shop_name, s.shop_category
     FROM udhar_entries ue
     JOIN shop_owners s ON ue.shop_id = s.id
     WHERE ue.customer_id = ? AND ue.created_at BETWEEN ? AND ?)
    UNION ALL
    (SELECT ph.id, 'Payment' as type, ph.amount_paid as amount, 0 as discount_percentage, ph.payment_date as date, s.shop_name, s.shop_category
     FROM payment_history ph
     JOIN shop_owners s ON ph.shop_id = s.id
     WHERE ph.customer_id = ? AND ph.payment_date BETWEEN ? AND ?)
    ORDER BY date ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$customer_id, $current_month_start, $current_month_end, $customer_id, $current_month_start, $current_month_end]);
$transactions = $stmt->fetchAll();

// Set CSV headers
$filename = "khata_monthly_summary_" . date('Y-m') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

// CSV Header Row
fputcsv($output, [
    'Date',
    'Time',
    'Type',
    'Shop Name',
    'Shop Category',
    'Amount (INR)',
    'Discount (%)',
    'Net Amount (INR)'
]);

// CSV Data Rows
foreach ($transactions as $t) {
    $net_amount = (float)$t['amount'];
    if ($t['type'] == 'Credit' && $t['discount_percentage'] > 0) {
        $discount_amount = $net_amount * ((float)$t['discount_percentage'] / 100);
        $net_amount -= $discount_amount;
    }

    $row = [
        date('Y-m-d', strtotime($t['date'])),
        date('H:i:s', strtotime($t['date'])),
        $t['type'],
        htmlspecialchars($t['shop_name']),
        htmlspecialchars($t['shop_category']),
        number_format((float)$t['amount'], 2, '.', ''),
        number_format((float)$t['discount_percentage'], 2, '.', ''),
        number_format($net_amount, 2, '.', '')
    ];
    fputcsv($output, $row);
}

fclose($output);
exit();
?>