<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');
if(!isset($_SESSION['admin_id'])) exit(json_encode(['success' => false, 'message' => 'Unauthorized']));

$action = $_POST['action'] ?? '';

if ($action === 'upload') {
    $target = $_POST['target'] ?? 'all';

    // Check limit
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM banners WHERE target = ?");
    $stmt_count->execute([$target]);
    $count = $stmt_count->fetchColumn();
    
    if ($count >= 5) exit(json_encode(['success' => false, 'message' => "Maximum 5 banners allowed for ".ucfirst($target)." segment. Delete one first."]));

    if (isset($_FILES['banner']) && $_FILES['banner']['error'] == 0) {
        $upload_dir = '../assets/img/banners/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $ext = pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('app_') . '.' . $ext;
        
        if (move_uploaded_file($_FILES['banner']['tmp_name'], $upload_dir . $filename)) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
            $image_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/khatalink/assets/img/banners/" . $filename;
            
            $stmt = $pdo->prepare("INSERT INTO banners (image_path, target) VALUES (?, ?)");
            $stmt->execute([$image_url, $target]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Upload failed']);
        }
    }
} elseif ($action === 'delete') {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("SELECT image_path FROM banners WHERE id = ?");
    $stmt->execute([$id]);
    $img_url = $stmt->fetchColumn();
    
    if ($img_url) {
        $filename = basename($img_url);
        $local_path = '../assets/img/banners/' . $filename;
        if (file_exists($local_path)) unlink($local_path);
        
        $pdo->prepare("DELETE FROM banners WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    }
}