<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['shop_id'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json');

$shop_id = (int) $_SESSION['shop_id'];
$query = $_GET['q'] ?? '';

if (empty($query)) {
    exit(json_encode([]));
}

$stmt = $pdo->prepare(
    "SELECT c.id, c.name, c.unique_id, c.phone, c.email
     FROM customers c
     JOIN shop_customers sc ON c.id = sc.customer_id
     WHERE sc.shop_id = ?
       AND (c.name LIKE ? OR c.unique_id LIKE ? OR c.phone LIKE ?)
     ORDER BY c.name ASC
     LIMIT 10"
);
$stmt->execute([$shop_id, "%{$query}%", "%{$query}%", "%{$query}%"]);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($customers);
?>