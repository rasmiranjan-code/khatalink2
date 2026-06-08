<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db.php';

// Token Auth
$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');
if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$shop_id = (int)($parts[0]?? 0);

if($shop_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET') {
    // Fetch all tiers
    $stmt = $pdo->prepare("SELECT * FROM customer_tiers WHERE shop_id =? ORDER BY discount_percentage DESC, tier_name ASC");
    $stmt->execute([$shop_id]);
    $tiers = $stmt->fetchAll();

    echo json_encode(['success' => true, 'tiers' => $tiers]);

} elseif($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action']?? '';

    if($action === 'add' || $action === 'edit') {
        $tier_name = trim($data['tier_name']);
        $discount = (float)$data['discount_percentage'];
        $tier_id = (int)($data['tier_id']?? 0);

        if(empty($tier_name)) {
            echo json_encode(['success' => false, 'message' => 'Tier name cannot be empty']);
            exit();
        }
        if($discount < 0 || $discount > 100) {
            echo json_encode(['success' => false, 'message' => 'Discount must be between 0 and 100']);
            exit();
        }

        if($action === 'edit' && $tier_id > 0) {
            $stmt = $pdo->prepare("UPDATE customer_tiers SET tier_name =?, discount_percentage =? WHERE id =? AND shop_id =?");
            $stmt->execute([$tier_name, $discount, $tier_id, $shop_id]);
            echo json_encode(['success' => true, 'message' => 'Tier updated successfully']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO customer_tiers (shop_id, tier_name, discount_percentage) VALUES (?,?,?)");
            $stmt->execute([$shop_id, $tier_name, $discount]);
            echo json_encode(['success' => true, 'message' => 'New tier added successfully']);
        }

    } elseif($action === 'delete') {
        $tier_id = (int)$data['tier_id'];

        // Remove tier from customers first
        $pdo->prepare("UPDATE shop_customers SET tier_id = NULL WHERE tier_id =? AND shop_id =?")->execute([$tier_id, $shop_id]);
        // Delete tier
        $pdo->prepare("DELETE FROM customer_tiers WHERE id =? AND shop_id =?")->execute([$tier_id, $shop_id]);

        echo json_encode(['success' => true, 'message' => 'Tier deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
?>