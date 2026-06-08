<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'active';

// Fetch customers for the current shop using the same logic as the main page
$query = "
    SELECT 
        c.unique_id, 
        c.name, 
        c.email, 
        COALESCE(SUM(ue.total_remaining), 0) AS total_due,
        COUNT(DISTINCT ue.id) AS total_udhar_entries
    FROM 
        customers c
    JOIN 
        shop_customers sc ON c.id = sc.customer_id
    LEFT JOIN 
        udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id = sc.shop_id AND ue.status = 'open'
    WHERE 
        sc.shop_id = ?
";
$params = [$shop_id];

if ($search) {
    $query .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.unique_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " GROUP BY c.id ";

if ($filter === 'active') {
    $query .= " HAVING total_due > 0 ";
}

$query .= " ORDER BY sc.added_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Header settings for Excel-compatible CSV
$filename = "khata_customers_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

fputcsv($output, ['Customer ID', 'Name', 'Email', 'Active Bills', 'Total Due (INR)']);

foreach ($customers as $c) {
    fputcsv($output, [$c['unique_id'], $c['name'], $c['email'], $c['total_udhar_entries'], $c['total_due']]);
}
fclose($output);
exit();