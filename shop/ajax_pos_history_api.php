<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
require_once '../includes/db.php';

$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$shop_id = (int)($parts[0] ?? 0);

if (!$shop_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$date = $_GET['date'] ?? date('Y-m-d');
$customer_id_filter = (int)($_GET['customer_id'] ?? 0);

$query = "
    SELECT pb.*, c.name as customer_name, c.unique_id
    FROM pos_bills pb
    LEFT JOIN customers c ON pb.customer_id = c.id
    WHERE pb.shop_id = ? 
    AND DATE(pb.created_at) = ? 
    AND pb.is_deleted_shop = 0 
    AND pb.payment_status NOT IN ('transferred_to_udhar')
";
$params = [$shop_id, $date];

if ($customer_id_filter > 0) {
    $query .= " AND pb.customer_id = ?";
    $params[] = $customer_id_filter;
}
$query .= " ORDER BY pb.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'bills' => $bills]);
?>
