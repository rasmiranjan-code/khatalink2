<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
require_once '../includes/db.php';

$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');
$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$shop_id = (int)($parts[0]?? 0);

$data = json_decode(file_get_contents('php://input'), true);
$product_id = (int)($data['product_id']?? 0);

if (!$shop_id ||!$product_id) {
    die(json_encode(['success' => false, 'message' => 'Invalid request']));
}

$stmt_p = $pdo->prepare("SELECT photo FROM inventory_products WHERE id =? AND shop_id =?");
$stmt_p->execute([$product_id, $shop_id]);
$p = $stmt_p->fetch();

if ($p) {
    if ($p['photo'] && file_exists('../assets/img/products/'. $p['photo'])) {
        unlink('../assets/img/products/'. $p['photo']);
    }
    // Mall Cache se product hatao
    $pdo->prepare("DELETE FROM Groceries_product_marketplace_cache WHERE product_id = ?")->execute([$product_id]);
    $pdo->prepare("DELETE FROM inventory_products WHERE id =? AND shop_id =?")->execute([$product_id, $shop_id]);
    echo json_encode(['success' => true, 'message' => 'Product deleted']);
} else {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
}
?>