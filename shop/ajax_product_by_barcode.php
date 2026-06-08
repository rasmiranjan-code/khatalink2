<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once '../includes/db.php';

$shop_id = 0;
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (!empty($token)) {
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0] ?? 0);
} elseif (isset($_SESSION['shop_id'])) {
    $shop_id = (int)$_SESSION['shop_id'];
}

if ($shop_id <= 0) { exit(json_encode(['error' => 'Unauthorized'])); }

$barcode = trim($_GET['barcode'] ?? '');

if(empty($barcode)) { exit(json_encode([])); }

$stmt = $pdo->prepare("SELECT * FROM inventory_products WHERE shop_id = ? AND TRIM(barcode) = ? LIMIT 1");
$stmt->execute([$shop_id, $barcode]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode($product ?: ['error' => 'Not found']);
?>