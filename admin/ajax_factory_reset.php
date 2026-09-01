<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

// Double-check authentication and role
if (!isset($_SESSION['admin_id'])) {
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$stmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();

if (!$admin || $admin['role'] !== 'founder') {
    exit(json_encode(['success' => false, 'message' => 'Only the founder can perform a factory reset.']));
}

// List of tables to truncate
$tables_to_truncate = [
    'customers',
    'shop_owners',
    'delivery_partners',
    'shop_customers',
    'udhar_entries',
    'udhar_items',
    'payment_history',
    'payment_requests',
    'bonds',
    'bond_payments',
    'bond_warnings',
    'monthly_khata',
    'monthly_khata_items',
    'orders',
    'order_items',
    'pos_bills',
    'pos_bill_items',
    'inventory_products',
    'product_images',
    'grocery_carts',
    'shop_ratings',
    'support_queries',
    'visitors',
    'db_access_logs',
    'delivery_ledger'
];

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0;');
    foreach ($tables_to_truncate as $table) {
        $pdo->exec("TRUNCATE TABLE `$table`;");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
    echo json_encode(['success' => true, 'message' => 'System has been reset to factory settings.']);
} catch (Exception $e) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?>