<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

require_once '../includes/db.php';

$shop_id = 0;
$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');

if (!empty($token)) {
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0]?? 0);
} else {
    session_start();
    $shop_id = (int)($_SESSION['shop_id']?? 0);
}

if (!$shop_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login']);
    exit();
}

$search = trim($_GET['search']?? '');

$query = "SELECT * FROM inventory_products WHERE shop_id = ?";
$params = [$shop_id];

if ($search) {
    $query.= " AND (name LIKE ? OR hsn_code LIKE ? OR barcode LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query.= " ORDER BY created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'products' => $products]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>