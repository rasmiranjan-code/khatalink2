<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';

require_once '../includes/cashfree_config.php'; // For API key

// ── Distance Calculation (Google Road Distance with Haversine Fallback) ───────
function calculateRoadDistanceAndDuration(float $origin_lat, float $origin_lng, float $dest_lat, float $dest_lng): array {
    $apiKey = FIREBASE_API_KEY; // Using the Firebase API key for Google Maps services
    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$origin_lat,$origin_lng&destinations=$dest_lat,$dest_lng&key=$apiKey";
    
    $km = 0.0;
    $duration_mins = 0;

    $resp = @file_get_contents($url);
    $dist_data = json_decode($resp, true);
    
    if($dist_data && $dist_data['status'] == 'OK' && !empty($dist_data['rows'][0]['elements'][0]['status']) && $dist_data['rows'][0]['elements'][0]['status'] == 'OK') {
        $km = (float)($dist_data['rows'][0]['elements'][0]['distance']['value'] / 1000);
        $duration_mins = max(5, (int)ceil($dist_data['rows'][0]['elements'][0]['duration']['value'] / 60) + 2);
    } else {
        // Fallback to Haversine if API fails or returns error
        $earth_radius = 6371; // km
        $dLat = deg2rad($dest_lat - $origin_lat);
        $dLon = deg2rad($dest_lng - $origin_lng);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($origin_lat)) * cos(deg2rad($dest_lat)) * sin($dLon/2) * sin($dLon/2);
        $km = $earth_radius * 2 * atan2(sqrt($a), sqrt(1-$a));
        // Rough ETA for Haversine: 3-4 mins per KM + 2 mins buffer
        $duration_mins = max(5, (int)ceil($km * 4) + 3);
    }
    
    return ['distance_km' => round($km, 2), 'duration_mins' => $duration_mins];
}

if(!isset($_SESSION['customer_id']) || $_SESSION['customer_id'] != ($_GET['customer_id'] ?? 0)) { // Added customer_id check
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$order_id = (int)($_GET['order_id'] ?? 0);
$customer_id = $_SESSION['customer_id'];

try {
    $stmt = $pdo->prepare("
        SELECT o.order_status as status, o.delivery_code, sr.id IS NOT NULL as has_rated,
               dp.current_lat as rider_lat, dp.current_lng as rider_lng,
               o.latitude as cust_lat, o.longitude as cust_lng
        FROM orders o
        LEFT JOIN delivery_partners dp ON o.delivery_boy_id = dp.id
        WHERE o.id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$order_id, $customer_id]);
    $data = $stmt->fetch();

    $eta = null;
    $distance_km = null;

    if ($data['rider_lat'] && $data['rider_lng'] && $data['cust_lat'] && $data['cust_lng']) {
        $distance_info = calculateRoadDistanceAndDuration((float)$data['rider_lat'], (float)$data['rider_lng'], (float)$data['cust_lat'], (float)$data['cust_lng']);
        $eta = $distance_info['duration_mins'];
        $distance_km = $distance_info['distance_km'];
    }

    if($data) {
        echo json_encode([
            'success'   => true,
            'status'    => $data['status'],
            'rider_lat' => $data['rider_lat'] ? (float)$data['rider_lat'] : null,
            'rider_lng' => $data['rider_lng'] ? (float)$data['rider_lng'] : null,
            'dcc'       => $data['delivery_code'],
            'has_rated' => (bool)$data['has_rated'],
            'eta'       => $eta,
            'distance_km' => $distance_km
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}