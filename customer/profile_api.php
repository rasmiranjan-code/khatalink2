<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db.php';

// Token Auth
$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');
if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$customer_id = (int)($parts[0]?? 0);

if($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit();
}

if($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Fetch Profile Data
    $stmt = $pdo->prepare("SELECT id, unique_id, name, email, phone, created_at FROM customers WHERE id =?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit();
    }

    // Calculate Trust Score - same logic as PHP
    $u = $pdo->prepare("SELECT SUM(total_amount) as t, SUM(total_paid) as p FROM udhar_entries WHERE customer_id =?");
    $u->execute([$customer_id]); $ud = $u->fetch();
    $b = $pdo->prepare("SELECT SUM(amount) as t, SUM(paid_amount) as p FROM bonds WHERE customer_id =?");
    $b->execute([$customer_id]); $bd = $b->fetch();
    $m = $pdo->prepare("SELECT SUM(total_amount) as t, SUM(paid_amount) as p FROM monthly_khata WHERE customer_id =?");
    $m->execute([$customer_id]); $md = $m->fetch();

    $tb = (float)$ud['t'] + (float)$bd['t'] + (float)$md['t'];
    $tp = (float)$ud['p'] + (float)$bd['p'] + (float)$md['p'];
    $trust_score = ($tb > 0)? round(($tp / $tb) * 100) : 100;

    echo json_encode([
        'success' => true,
        'customer' => $customer,
        'trust_score' => $trust_score,
        'total_borrowed' => $tb,
        'total_paid' => $tp
    ]);

} elseif($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if(isset($data['update_profile'])) {
        $name = trim($data['name']);
        $phone = trim($data['phone']);

        if(empty($name) || empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Required fields cannot be empty']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE customers SET name =?, phone =? WHERE id =?");
        if($stmt->execute([$name, $phone, $customer_id])) {
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
        }

    } elseif(isset($data['update_password'])) {
        $current = $data['current_password'];
        $new = $data['new_password'];
        $confirm = $data['confirm_password'];

        $stmt = $pdo->prepare("SELECT password FROM customers WHERE id =?");
        $stmt->execute([$customer_id]);
        $user = $stmt->fetch();

        if(!password_verify($current, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
        } elseif($new!== $confirm) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
        } elseif(strlen($new) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE customers SET password =? WHERE id =?")->execute([$hash, $customer_id]);
            echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
        }
    }
}
?>