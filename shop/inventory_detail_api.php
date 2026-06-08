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
$product_id = (int)($_GET['id']?? 0);

$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');
if (empty($token) && isset($_GET['token'])) {
    $token = $_GET['token'];
}

if (!empty($token)) {
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0]?? 0);
} else {
    session_start();
    $shop_id = (int)($_SESSION['shop_id']?? 0);
}

if (!$shop_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!$product_id) {
    echo json_encode(['success' => false, 'message' => 'Product ID required']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM inventory_products WHERE id = ? AND shop_id = ?");
    $stmt->execute([$product_id, $shop_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        echo json_encode(['success' => true, 'product' => $product]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error']);
}
?>