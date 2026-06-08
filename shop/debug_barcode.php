<?php
// debug_barcode.php (temporarily banao, baad mein delete karna)
session_start();
require_once '../includes/db.php';
$shop_id = (int)$_SESSION['shop_id'];

$stmt = $pdo->prepare("SELECT id, name, barcode, HEX(barcode) as hex_val, LENGTH(barcode) as len FROM inventory_products WHERE shop_id = ?");
$stmt->execute([$shop_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($rows, JSON_PRETTY_PRINT);
?>