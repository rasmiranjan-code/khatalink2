<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');
header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db.php';

// ===== FLUTTER API HANDLING =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $customer_id = (int)($parts[0] ?? 0);

    if (!$customer_id) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit();
    }

    // Fetch Bonds with Shop and Customer details for the Model
    $stmt = $pdo->prepare("
        SELECT b.*, s.shop_name, c.name as customer_name, c.unique_id, c.phone as customer_phone,
        (SELECT COUNT(*) FROM bond_warnings WHERE bond_id = b.id) as warning_count
        FROM bonds b
        JOIN shop_owners s ON b.shop_id = s.id
        JOIN customers c ON b.customer_id = c.id
        WHERE b.customer_id = ?
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$customer_id]);
    $bonds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Numeric fields ko cast karna zaroori hai Flutter ke liye
    $bonds_list = [];
    foreach($bonds as $b) {
        // Fetch payments for this specific bond to calculate ordinals in App (1st, 2nd, etc.)
        $p_stmt = $pdo->prepare("SELECT amount_paid, payment_date FROM bond_payments WHERE bond_id = ? AND payment_status = 'completed' ORDER BY payment_date ASC");
        $p_stmt->execute([$b['id']]);
        $history = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

        $b['id'] = (int)$b['id'];
        $b['shop_id'] = (int)$b['shop_id'];
        $b['customer_id'] = (int)$b['customer_id'];
        $b['amount'] = (float)$b['amount'];
        $b['initial_paid'] = (float)$b['initial_paid'];
        $b['paid_amount'] = (float)$b['paid_amount'];
        $b['installment_count'] = (int)$b['installment_count'];
        $b['warning_count'] = (int)$b['warning_count'];
        $b['fine_amount'] = (float)$b['fine_amount'];
        $b['history'] = $history; 
        
        $bonds_list[] = $b;
    }

    echo json_encode(['success' => true, 'bonds' => $bonds_list]);
    exit();
}
