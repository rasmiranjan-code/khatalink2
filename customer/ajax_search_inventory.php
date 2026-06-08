<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

session_start();
require_once '../includes/db.php';

// Customer authentication check
if(!isset($_SESSION['customer_id'])) {
    exit(json_encode(['error' => 'Unauthorized']));
}

$shop_id = (int)($_GET['shop_id'] ?? 0);
$query = trim($_GET['q'] ?? '');

if ($shop_id <= 0 || empty($query)) {
    exit(json_encode([]));
}

try {
    $stmt = $pdo->prepare("
        SELECT id, name, sale_price, primary_unit, gst_percent 
        FROM inventory_products 
        WHERE shop_id = ? AND name LIKE ? 
        LIMIT 10
    ");
    $stmt->execute([$shop_id, "%$query%"]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatting for frontend
    $results = array_map(function($p) {
        return [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'price' => (float)$p['sale_price'],
            'unit' => $p['primary_unit'],
            'gst' => (float)$p['gst_percent']
        ];
    }, $products);

    echo json_encode($results);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
