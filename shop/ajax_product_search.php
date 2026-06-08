<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once '../includes/db.php';

$shop_id = 0;

// Priority: Token-based Auth (for Flutter)
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (!empty($token)) {
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0] ?? 0);
} elseif (isset($_SESSION['shop_id'])) {
    $shop_id = (int) $_SESSION['shop_id'];
}

if ($shop_id <= 0) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized', 'debug' => 'Session not found']));
}

$query = trim($_GET['q'] ?? '');

if (empty($query)) {
    exit(json_encode([]));
}

try {
    $stmt = $pdo->prepare(
        "SELECT 
            id, 
            name, 
            sale_price, 
            primary_unit as unit,
            gst_percent, 
            barcode, 
            current_stock,
            hsn_code,
            purchase_price
         FROM inventory_products
         WHERE shop_id = ? AND name LIKE ?
         ORDER BY 
            CASE 
                WHEN name LIKE ? THEN 1 
                WHEN name LIKE ? THEN 2 
                ELSE 3 
            END,
            current_stock DESC,
            name ASC
         LIMIT 15"
    );
    
    $exact = $query . '%';
    $contains = '%' . $query . '%';
    $stmt->execute([$shop_id, $contains, $exact, $contains]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Type casting - string se int/float mein convert
    foreach ($products as &$product) {
        $product['id'] = (int)$product['id'];
        $product['sale_price'] = (float)$product['sale_price'];
        $product['gst_percent'] = (float)$product['gst_percent'];
        $product['current_stock'] = (float)$product['current_stock'];
        $product['purchase_price'] = (float)($product['purchase_price'] ?? 0);
        
        // Add rate alias for compatibility
        $product['rate'] = $product['sale_price'];
    }

    echo json_encode($products);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
?>