<?php
ob_start(); // Prevent accidental whitespace output
require_once '../includes/db.php';
require_once '../includes/razorpay_config.php'; // Back to Razorpay Config

if (!extension_loaded('curl')) {
    exit(json_encode(['success' => false, 'message' => 'PHP cURL extension is not enabled in your XAMPP. Please enable it in php.ini file and restart Apache.']));
}

$customer_id = 0;
$auth_source = 'unknown';

// Handle JSON input from Flutter
$json_data = json_decode(file_get_contents('php://input'), true);
if ($json_data) {
    $_POST = array_merge($_POST, $json_data);
}

// Prioritize token-based authentication for Flutter app
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (!empty($token)) {
    $auth_source = 'token';
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $customer_id = (int)($parts[0] ?? 0);
} else { // Fallback to session-based authentication for web
    session_start(); // Start session only if not using token
    $auth_source = 'session';
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if (!$customer_id || (!isset($_POST['bond_id']) && !isset($_POST['shop_id']) && !isset($_POST['monthly_id']))) {
    error_log("DEBUG: razorpay_order.php - Unauthorized access. customer_id: $customer_id, Auth Source: $auth_source");
    exit(json_encode(['success' => false, 'message' => 'Unauthorized or missing parameters.']));
}

$bond_id = isset($_POST['bond_id']) ? (int)$_POST['bond_id'] : null;
$shop_id = isset($_POST['shop_id']) ? (int)$_POST['shop_id'] : null;
$monthly_id = isset($_POST['monthly_id']) ? (int)$_POST['monthly_id'] : null;
$custom_amount = isset($_POST['amount']) ? (float)$_POST['amount'] : null;
$allow_platform_pay = isset($_POST['platform_pay']) && $_POST['platform_pay'] == '1';

$amount_to_pay = 0;
$transfer_amount = 0; // Amount that goes to the shop owner
$receipt = "";

if ($bond_id) {
    // logic for bond installment
    $stmt = $pdo->prepare("SELECT b.amount, b.initial_paid, b.paid_amount, b.installment_count, s.rzp_account_id, s.shop_name, s.id as shop_id 
                           FROM bonds b JOIN shop_owners s ON b.shop_id = s.id 
                           WHERE b.id = ? AND b.customer_id = ? AND b.status != 'closed'");
    $stmt->execute([$bond_id, $customer_id]);
    $bond = $stmt->fetch();
    if (!$bond) exit(json_encode(['success' => false, 'message' => 'Bond not found']));
    $rzp_acc = $bond['rzp_account_id'];
    $shop_name = $bond['shop_name'];
    $shop_id = $bond['shop_id'];
    
    $total_bond_amount = (float)$bond['amount'];
    $initial_paid_amount = (float)$bond['initial_paid'];
    $installment_count = (int)$bond['installment_count'];

    $remaining_after_initial = $total_bond_amount - $initial_paid_amount;
    
    $base_kist_amount = 0;
    if ($installment_count > 0) {
        $base_kist_amount = $remaining_after_initial / $installment_count;
    } else {
        // If no installments, assume one-time payment of remaining amount
        $base_kist_amount = $remaining_after_initial;
    }
    
    // Ensure base_kist_amount is positive
    $base_kist_amount = max(0, $base_kist_amount);

    // Platform Fee for Bonds
    // Customer pays base + 3%
    $amount_to_pay = $base_kist_amount * (1 + (BOND_PLATFORM_COMMISSION_PERCENT / 100));
    // Shop gets base - 1%
    $transfer_amount = $base_kist_amount * (1 - (SHOP_SERVICE_FEE_PERCENT / 100));

    $receipt = 'bond_kist_' . $bond_id;
} elseif ($monthly_id) {
    // Logic for monthly cycle payment
    $stmt = $pdo->prepare("SELECT mk.total_amount, s.rzp_account_id, s.id as shop_id, s.shop_name 
                           FROM monthly_khata mk JOIN shop_owners s ON mk.shop_id = s.id 
                           WHERE mk.id = ? AND mk.customer_id = ? AND mk.status = 'open'");
    $stmt->execute([$monthly_id, $customer_id]);
    $mk = $stmt->fetch();
    if (!$mk) exit(json_encode(['success' => false, 'message' => 'Record not found']));
    
    $base_bill_amount = (float)$mk['total_amount']; // Original bill amount
    // Customer pays base + 3%
    $amount_to_pay = $base_bill_amount * (1 + (MONTHLY_PLATFORM_COMMISSION_PERCENT / 100));
    // Shop gets base - 1%
    $transfer_amount = $base_bill_amount * (1 - (SHOP_SERVICE_FEE_PERCENT / 100));

    $rzp_acc = $mk['rzp_account_id'];
    $shop_id = $mk['shop_id'];
    $shop_name = $mk['shop_name'];
    $receipt = 'monthly_pay_' . $monthly_id;
} else {
    // logic for shop ledger payment
    $stmt_shop = $pdo->prepare("SELECT rzp_account_id, shop_name FROM shop_owners WHERE id = ?");
    $stmt_shop->execute([$shop_id]);
    $s_data = $stmt_shop->fetch();
    $rzp_acc = $s_data['rzp_account_id'] ?? '';
    $shop_name = $s_data['shop_name'] ?? 'Shop';

    if ($custom_amount && $custom_amount > 0) {
        $amount_to_pay = $custom_amount;
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = ? AND customer_id = ? AND status = 'open'");
        $stmt->execute([$shop_id, $customer_id]);
        $amount_to_pay = (float)$stmt->fetchColumn();
    }
    if ($amount_to_pay <= 0) exit(json_encode(['success' => false, 'message' => 'No balance to pay']));
    
    $base_ledger_amount = $amount_to_pay;
    // Customer pays base + 3%
    $amount_to_pay = $base_ledger_amount * (1 + (LEDGER_PLATFORM_COMMISSION_PERCENT / 100));
    // Shop gets base - 1%
    $transfer_amount = $base_ledger_amount * (1 - (SHOP_SERVICE_FEE_PERCENT / 100));
    $receipt = 'ledger_pay_' . $shop_id;
}

// SAFETY CHECK: Agar dukan ne Razorpay link nahi kiya hai aur user ne platform pay allow nahi kiya hai
if ((empty($rzp_acc) || strlen($rzp_acc) !== 18 || substr($rzp_acc, 0, 4) !== 'acc_') && !$allow_platform_pay) {
    exit(json_encode([
        'success' => false, 
        'needs_platform_pay' => true,
        'shop_name' => $shop_name,
        'message' => 'Is dukandaar ne online payment setup poora nahi kiya hai.'
    ]));
}

// Razorpay minimum amount check (Must be at least 100 paise / ₹1)
if ($amount_to_pay < 1) {
    exit(json_encode(['success' => false, 'message' => 'Amount too low. Minimum ₹1 required for online payment.']));
}

$amount_in_paise = round($amount_to_pay * 100);
$transfer_paise = round($transfer_amount * 100);

$order_payload = [
    'amount'   => $amount_in_paise,
    'currency' => RZP_CURRENCY,
    'receipt'  => $receipt . '_' . time() . ($allow_platform_pay ? '_PLATFORM' : '')
];

// Razorpay Route (Linked Accounts)
if (!$allow_platform_pay && !empty($rzp_acc)) {
    $order_payload['transfers'] = [
        [
            'account'  => $rzp_acc,
            'amount'   => (int)$transfer_paise,
            'currency' => RZP_CURRENCY,
            'on_hold'  => 0
        ]
    ];
}

$ch = curl_init("https://api.razorpay.com/v1/orders");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, RZP_KEY_ID . ":" . RZP_KEY_SECRET);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_payload));
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) exit(json_encode(['success' => false, 'message' => 'API Connection Error']));
$order = json_decode($response, true);
ob_clean(); 

if (isset($order['id'])) {
    try {
        if ($bond_id) {
            $stmt_pay = $pdo->prepare("INSERT INTO bond_payments (bond_id, amount_paid, razorpay_order_id, payment_status) VALUES (?, ?, ?, 'pending')");
            $stmt_pay->execute([$bond_id, $transfer_amount, $order['id']]); 
        } elseif ($monthly_id) { // For monthly khata, store the base amount (transfer_amount) as paid_amount
            $pdo->prepare("UPDATE monthly_khata SET razorpay_order_id = ?, paid_amount = ? WHERE id = ?")->execute([$order['id'], $transfer_amount, $monthly_id]);
        } else {
            $stmt_req = $pdo->prepare("INSERT INTO payment_requests (shop_id, customer_id, amount, payment_mode, razorpay_order_id, status) VALUES (?, ?, ?, 'Razorpay', ?, 'pending')");
            // Store transfer_amount (base) in ledger requests so credit logic stays correct
            $stmt_req->execute([$shop_id, $customer_id, (float)$transfer_amount, $order['id']]); // Store the amount shop receives
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database Sync Error: ' . $e->getMessage()]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'order_id' => $order['id'],
        'amount'   => $amount_in_paise,
        'key_id'   => RZP_KEY_ID
    ]);
} else {
    error_log("Razorpay Order Creation Failed: " . $response);
    echo json_encode(['success' => false, 'message' => $order['error']['description'] ?? 'Order creation failed']);
}
?>