<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');
header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../includes/db.php';

// --- Token-based Authentication for Flutter App ---
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$shop_id = (int)($parts[0] ?? 0);

if (!$shop_id) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized: Invalid or missing token.']));
}
// --- End Auth ---

$query = $_GET['query'] ?? ''; // Using 'query' to avoid conflict with 'q' if any

$stmt = $pdo->prepare(
    "SELECT id, name, sale_price, primary_unit, gst_percent, barcode, current_stock, low_stock_alert
     FROM inventory_products
     WHERE shop_id = ? AND name LIKE ?
     ORDER BY name ASC
     LIMIT 10"
);
$stmt->execute([$shop_id, "%{$query}%"]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'products' => $products]);
?>