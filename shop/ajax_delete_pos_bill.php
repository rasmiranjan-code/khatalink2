<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

if ($shop_id <= 0) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}
$bill_id = (int)($_POST['bill_id'] ?? 0);

try {
    $stmt = $pdo->prepare("UPDATE pos_bills SET is_deleted_shop = 1 WHERE id = ? AND shop_id = ?");
    echo json_encode(['success' => $stmt->execute([$bill_id, $shop_id])]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>