<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id']) || !isset($_GET['shop_id'])) { die("Access Denied"); }

$shop_id = (int)$_GET['shop_id'];
$stmt_shop = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
$stmt_shop->execute([$shop_id]);
$shop_name = $stmt_shop->fetchColumn();

// Fetch All Combined Transactions (Udhar + Payments)
$query = "
    (SELECT ue.created_at as date, 'Udhar Entry' as type, c.name as customer, ue.total_amount as amount, 0 as received, ue.status 
     FROM udhar_entries ue JOIN customers c ON ue.customer_id = c.id WHERE ue.shop_id = ?)
    UNION ALL
    (SELECT ph.payment_date as date, 'Payment Recv' as type, c.name as customer, 0 as amount, ph.amount_paid as received, ph.payment_mode as status 
     FROM payment_history ph JOIN customers c ON ph.customer_id = c.id WHERE ph.shop_id = ?)
    ORDER BY date ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$shop_id, $shop_id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate CSV
$filename = "report_" . str_replace(' ', '_', $shop_name) . "_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
// UTF-8 BOM for Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, ['Shop Report:', $shop_name]);
fputcsv($output, ['Generated On:', date('Y-m-d H:i:s')]);
fputcsv($output, []); // Empty row
fputcsv($output, ['Date', 'Transaction Type', 'Customer Name', 'Debit (Udhar)', 'Credit (Payment)', 'Mode/Status']);

foreach ($transactions as $t) {
    fputcsv($output, [
        date('d M Y, h:i A', strtotime($t['date'])),
        $t['type'],
        $t['customer'],
        number_format($t['amount'], 2),
        number_format($t['received'], 2),
        $t['status']
    ]);
}
fclose($output);
exit();