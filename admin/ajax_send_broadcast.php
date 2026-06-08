<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';

header('Content-Type: application/json');

if(!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$type = $_POST['type'] ?? '';
$title = trim($_POST['title'] ?? '');
$body = trim($_POST['body'] ?? '');

if(empty($title) || empty($body)) {
    echo json_encode(['success' => false, 'message' => 'Title and Body required']);
    exit();
}

$image_url = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
    $upload_dir = '../assets/img/notifications/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    
    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $filename = uniqid('banner_') . '.' . $ext;
    
    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/khatalink";
        $image_url = $base_url . "/assets/img/notifications/" . $filename;
    }
}

$count = sendKhataBroadcast($pdo, $type, $title, $body, $image_url);
echo json_encode(['success' => true, 'count' => $count]);