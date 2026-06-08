<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';

// ── Authentication (Web Session + Flutter Token) ──────────────────────────────
$customer_id = 0;
$is_api      = false;

if(
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp'
) {
    $is_api  = true;
    $token   = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $decoded = base64_decode($token);
    $parts   = explode(':', $decoded);
    $customer_id = (int)($parts[0] ?? 0);
} else {
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if(!$customer_id) {
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

// ── Order ID Validate ─────────────────────────────────────────────────────────
$order_id = (int)($_GET['order_id'] ?? 0);
if($order_id <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid order ID']));
}

// ── Haversine Formula ─────────────────────────────────────────────────────────
function getDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R    = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a    = sin($dLat/2) * sin($dLat/2)
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
          * sin($dLon/2) * sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// ── Main Query ────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT
            dp.current_lat   AS lat,
            dp.current_lng   AS lng,
            dp.name          AS db_name,
            dp.phone         AS db_phone,
            o.order_status   AS status,
            o.latitude       AS cust_lat,
            o.longitude      AS cust_lng,
            o.delivery_code,
            o.total_amount,
            o.delivery_fee
        FROM orders o
        JOIN delivery_partners dp ON o.delivery_boy_id = dp.id
        WHERE o.id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$order_id, $customer_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$data) {
        exit(json_encode(['success' => false, 'message' => 'Order not found']));
    }

    // Delivery boy ne abhi GPS share nahi ki
    if(!$data['lat'] || (float)$data['lat'] === 0.0) {
        exit(json_encode([
            'success' => false,
            'message' => 'Delivery partner location not available yet',
            'status'  => $data['status'],
            // Flutter ke liye extra info
            'db_name'  => $data['db_name'],
            'db_phone' => $data['db_phone'],
        ]));
    }

    // Customer location missing
    if(!$data['cust_lat'] || (float)$data['cust_lat'] === 0.0) {
        exit(json_encode(['success' => false, 'message' => 'Customer location missing']));
    }

    $dist = getDistance(
        (float)$data['lat'], (float)$data['lng'],
        (float)$data['cust_lat'], (float)$data['cust_lng']
    );

    // Optimized ETA: distance 0 hone par bhi min 2 mins dikhayega
    $eta = max(5, (int)round(($dist / 18) * 60 + 3));

    echo json_encode([
        'success'       => true,
        'lat'           => (float)$data['lat'],
        'lng'           => (float)$data['lng'],
        'status'        => $data['status'],
        'eta'           => $eta,
        'distance_km'   => round($dist, 2),
        // Flutter ke liye extra fields
        'db_name'       => $data['db_name'],
        'db_phone'      => $data['db_phone'],
        'delivery_code' => $data['delivery_code'],
        'total_amount'  => (float)$data['total_amount'],
        'delivery_fee'  => (float)$data['delivery_fee'],
        'cust_lat'      => (float)$data['cust_lat'],
        'cust_lng'      => (float)$data['cust_lng'],
    ]);

} catch(Exception $e) {
    error_log("Tracking Error Order#$order_id : " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error, try again']);
}