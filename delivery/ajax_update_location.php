<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

session_start();
require_once '../includes/db.php';

$delivery_boy_id = 0;

// 1. Try Token Auth (App)
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if(!empty($auth)) {
    $token = str_replace('Bearer ', '', $auth);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $delivery_boy_id = (int)($parts[0] ?? 0);
} 
// 2. Try Session Auth (Web Dashboard)
elseif(isset($_SESSION['delivery_id'])) {
    $delivery_boy_id = (int)$_SESSION['delivery_id'];
}

if(!$delivery_boy_id) {
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$data = json_decode(file_get_contents('php://input'), true);
$lat = (float)($data['latitude'] ?? 0);
$lng = (float)($data['longitude'] ?? 0);

if($lat && $lng) {
    $stmt = $pdo->prepare("UPDATE delivery_partners SET current_lat = ?, current_lng = ?, last_location_update = NOW() WHERE id = ?");
    $stmt->execute([$lat, $lng, $delivery_boy_id]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid Coords']);
}