<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');
$type = trim($data['type'] ?? 'customer');
$name = trim($data['name'] ?? '');

if(empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Email required.']);
    exit();
}

if($type == 'customer') {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if($user) {
        if($user['is_verified'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Account not verified.']);
        } else {
            echo json_encode([
                'success' => true,
                'role' => 'customer',
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'token' => base64_encode($user['id'] . ':' . $user['email'] . ':customer')
            ]);
        }
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'No customer account found with this Google email. Please register first.'
        ]);
    }
} else {
    $stmt = $pdo->prepare("SELECT * FROM shop_owners WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if($user) {
        if($user['is_verified'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Shop not verified.']);
        } else {
            echo json_encode([
                'success' => true,
                'role' => 'shop',
                'id' => $user['id'],
                'name' => $user['name'],
                'shop_name' => $user['shop_name'],
                'email' => $user['email'],
                'token' => base64_encode($user['id'] . ':' . $user['email'] . ':shop')
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No shop account found with this Google email. Please register first.'
        ]);
    }
}
?>