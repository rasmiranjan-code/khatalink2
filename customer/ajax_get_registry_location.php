<?php
ob_start();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once '../includes/db.php';

// ===== AUTHENTICATION LAYER =====
$customer_id = 0;
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $token = get_auth_token();
    $parts = verify_secure_token($token);
    if($parts) $customer_id = (int)$parts[0];
} else {
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if ($customer_id <= 0) {
    ob_clean();
    exit(json_encode(['success' => false, 'message' => 'Unauthorized Access']));
}

check_cors();

$lat = (float)($_GET['lat'] ?? 0);
$lng = (float)($_GET['lng'] ?? 0);

if ($lat == 0 || $lng == 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
    exit();
}

try {
    // ── OPTIMIZED SPATIAL SEARCH ──
    // Pehle 10 meter (Exact) check karega, phir 1km (Radius)
    // Point function handles (Longitude, Latitude)
    $stmt_radius = $pdo->prepare("
        SELECT village_name, block_name, district_name, pincode,
               ST_Distance_Sphere(geo_point, ST_GeomFromText(?, 4326)) AS distance_m
        FROM geo_registry 
        HAVING distance_m <= 1000
        ORDER BY distance_m ASC 
        LIMIT 1
    ");
    $stmt_radius->execute(["POINT($lng $lat)"]);
    $radius_match = $stmt_radius->fetch(PDO::FETCH_ASSOC);

    if ($radius_match) {
        // Identify source for debugging
        $source = ($radius_match['distance_m'] <= 10) ? 'registry_exact' : 'registry_radius';
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'source' => $source,
            'data' => $radius_match
        ]);
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Not found in registry']);
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
