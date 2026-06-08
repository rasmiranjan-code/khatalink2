<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['shop_id']) || !isset($_POST['customer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$shop_id = $_SESSION['shop_id'];
$customer_id = (int)$_POST['customer_id'];
$field_name = trim($_POST['field_name']);

if(empty($field_name)) {
    echo json_encode(['success' => false, 'message' => 'Empty name']);
    exit();
}

// Check if already exists for this customer
$stmt = $pdo->prepare("SELECT id FROM shop_fields WHERE shop_id = ? AND customer_id = ? AND LOWER(field_name) = LOWER(?)");
$stmt->execute([$shop_id, $customer_id, $field_name]);
$existing = $stmt->fetch();

if($existing) {
    echo json_encode(['success' => true, 'id' => $existing['id'], 'new' => false]);
} else {
    try {
        $stmt = $pdo->prepare("INSERT INTO shop_fields (shop_id, customer_id, field_name) VALUES (?, ?, ?)");
        if($stmt->execute([$shop_id, $customer_id, $field_name])) {
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'new' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'DB Error']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>