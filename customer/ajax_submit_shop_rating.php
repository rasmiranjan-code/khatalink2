<?php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if(!isset($_SESSION['customer_id'])) {
    exit(json_encode(['success' => false, 'message' => 'Unauthorized access.']));
}

$customer_id = $_SESSION['customer_id'];
$data = json_decode(file_get_contents('php://input'), true);

$shop_id = (int)($data['shop_id'] ?? 0);
$order_id = (int)($data['order_id'] ?? 0);
$rating = (int)($data['rating'] ?? 0);
$comment = trim($data['comment'] ?? '');

if ($shop_id <= 0 || $order_id <= 0 || $rating < 1 || $rating > 5) {
    exit(json_encode(['success' => false, 'message' => 'Invalid rating data.']));
}

try {
    $pdo->beginTransaction();

    // 1. Check if order is delivered and not already rated
    $stmt_order = $pdo->prepare("SELECT order_status FROM orders WHERE id = ? AND customer_id = ? AND order_status = 'delivered'");
    $stmt_order->execute([$order_id, $customer_id]);
    if (!$stmt_order->fetch()) {
        throw new Exception("Order not delivered or already rated.");
    }

    // 2. Insert new rating
    $stmt_insert = $pdo->prepare("INSERT INTO shop_ratings (customer_id, shop_id, order_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
    $stmt_insert->execute([$customer_id, $shop_id, $order_id, $rating, $comment]);

    // 3. Update shop_owners average rating and count
    $stmt_update_shop = $pdo->prepare("
        UPDATE shop_owners SET
            total_ratings_count = total_ratings_count + 1,
            average_rating = ( (average_rating * (total_ratings_count - 1)) + ? ) / total_ratings_count
        WHERE id = ?
    ");
    $stmt_update_shop->execute([$rating, $shop_id]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Rating submitted successfully!']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>