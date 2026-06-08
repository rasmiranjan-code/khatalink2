<?php
ob_start(); // 👈 Sabse upar add kar
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    exit();
}

require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

// Token Auth
$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');
if (empty($token)) {
    ob_clean(); // 👈 Har echo se pehle
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$shop_id = (int)($parts[0]?? 0);

if($shop_id <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM shop_owners WHERE id =?");
    $stmt->execute([$shop_id]);
    $shop = $stmt->fetch();

    if(!$shop) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Shop not found']);
        exit();
    }

    unset($shop['password']);
    ob_clean(); // 👈 JSON se pehle buffer clear
    echo json_encode(['success' => true, 'shop' => $shop]);

} elseif($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action']?? '';

    if($action === 'update_profile') {
        $name = trim($data['name']);
        $shop_name = trim($data['shop_name']);
        $category = trim($data['shop_category']);
        $upi_id = trim($data['upi_id']);
        $gst_number = trim($data['gst_number']);
        $rzp_account_id = trim($data['rzp_account_id']?? '');
        $bank_acc_no = trim($data['bank_acc_no']?? '');
        $bank_ifsc = trim($data['bank_ifsc']?? '');

        if(empty($name) || empty($shop_name)) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Required fields cannot be empty.']);
            exit();
        } elseif (!empty($rzp_account_id) && (strlen($rzp_account_id)!== 18 || substr($rzp_account_id, 0, 4)!== 'acc_')) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => "Invalid ID! Razorpay 'Linked Account ID' chahiye jo 'acc_' se shuru hoti hai (18 characters)."]);
            exit();
        } else {
            $stmt = $pdo->prepare("UPDATE shop_owners SET name =?, shop_name =?, shop_category =?, upi_id =?, gst_number =?, rzp_account_id =?, bank_acc_no =?, bank_ifsc =? WHERE id =?");
            ob_clean();
            if($stmt->execute([$name, $shop_name, $category, $upi_id, $gst_number, $rzp_account_id, $bank_acc_no, $bank_ifsc, $shop_id])) {
                echo json_encode(['success' => true, 'message' => 'Shop settings updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update details.']);
            }
        }

    } elseif($action === 'update_password') {
        $current_pass = $data['current_password'];
        $new_pass = $data['new_password'];
        $confirm_pass = $data['confirm_password'];

        $stmt = $pdo->prepare("SELECT password FROM shop_owners WHERE id =?");
        $stmt->execute([$shop_id]);
        $user = $stmt->fetch();

        ob_clean();
        if(!password_verify($current_pass, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        } elseif($new_pass!== $confirm_pass) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE shop_owners SET password =? WHERE id =?")->execute([$hash, $shop_id]);
            echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
        }
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
?>