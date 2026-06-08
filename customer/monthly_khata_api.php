<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');
header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db.php';
ob_start();

$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$customer_id = (int)($parts[0] ?? 0);

if (!$customer_id) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Fetch cycles with SHOP NAME and Category
$stmt = $pdo->prepare("
    SELECT mk.*, s.shop_name, s.shop_category, 
           DATEDIFF(CURDATE(), mk.start_date) as days_passed 
    FROM monthly_khata mk 
    JOIN shop_owners s ON mk.shop_id = s.id 
    WHERE mk.customer_id = ? 
    ORDER BY mk.status ASC, mk.start_date DESC
");
$stmt->execute([$customer_id]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_clean();
echo json_encode(['success' => true, 'data' => $data]);
exit();