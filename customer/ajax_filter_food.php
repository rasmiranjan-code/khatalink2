<?php
/**
 * KhataLink Food Marketplace - Real-time Menu Filter
 * Handles Veg/Non-Veg preference and category filtering for the Customer App.
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once '../includes/db.php';

// ── AUTHENTICATION LAYER (App & Web) ──
$customer_id = 0;
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $token = get_auth_token();
    $parts = verify_secure_token($token);
    $customer_id = (int)($parts[0] ?? 0);
} else {
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if (!$customer_id) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized Access']));
}

// ── INPUT HANDLING ──
$shop_id = (int)($_GET['shop_id'] ?? 0);
if ($shop_id <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid Shop ID']));
}

// Filter params: is_veg (1 = Veg only, 0 = Non-Veg only), Null = Show All
$is_veg_filter = isset($_GET['is_veg']) && $_GET['is_veg'] !== '' ? (int)$_GET['is_veg'] : null;
$category      = trim($_GET['category'] ?? '');

// ── DYNAMIC QUERY BUILDING ──
$query = "SELECT * FROM restaurant_menu_items WHERE shop_id = ? AND is_available = 1";
$params = [$shop_id];

if ($is_veg_filter !== null) {
    $query .= " AND is_veg = ?";
    $params[] = $is_veg_filter;
}

if (!empty($category) && $category !== 'All') {
    $query .= " AND category = ?";
    $params[] = $category;
}

$query .= " ORDER BY is_veg DESC, item_name ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare for App UI (Clean number formats and image arrays)
    foreach ($items as &$item) {
        $item['images'] = json_decode($item['image_paths'], true) ?: [];
        unset($item['image_paths']); // Hide raw JSON field
    }

    echo json_encode(['success' => true, 'data' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database Query Error']);
}
