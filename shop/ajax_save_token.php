<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';

header('Content-Type: application/json');

if(!isset($_SESSION['shop_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$token = $data['fcm_token'] ?? null;

if($token) {
    // Notification service function ko call karke token save karein aur status check karein
    if (updateFCMToken($pdo, (int)$_SESSION['shop_id'], 'shop', $token, 'web')) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update token in DB.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No token provided']);
}
?>