<?php
ob_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';

session_start();

// ── AUTHENTICATION LAYER (App & Web) ──
$customer_id = 0;
$is_api = false;

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    $token = get_auth_token();
    $parts = verify_secure_token($token);
    if ($parts) $customer_id = (int)$parts[0];
} else {
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

header('Content-Type: application/json');

// Input handling
$bond_id = isset($_POST['bond_id']) ? (int)$_POST['bond_id'] : null;
$shop_id = isset($_POST['shop_id']) ? (int)$_POST['shop_id'] : null;
$monthly_id = isset($_POST['monthly_id']) ? (int)$_POST['monthly_id'] : null;
$custom_amount = isset($_POST['amount']) ? (float)$_POST['amount'] : null;
$allow_platform_pay = isset($_POST['platform_pay']) && $_POST['platform_pay'] == '1';
$action = $_POST['action'] ?? ''; // New: To identify platform fee payment
$is_marketplace = isset($_POST['is_marketplace']) && $_POST['is_marketplace'] == '1';

$is_rider_paying = ($action === 'pay_platform_fee');

// Ensure clean JSON output
ob_clean();

if (!$customer_id && !$is_rider_paying) exit(json_encode(['success' => false, 'message' => 'Login Expired.']));

if ($is_rider_paying) {
    $rider_id = (int)($_POST['delivery_boy_id'] ?? 0);
    $stmt_r = $pdo->prepare("SELECT name, email, phone FROM delivery_partners WHERE id = ?");
    $stmt_r->execute([$rider_id]);
    $user_data = $stmt_r->fetch();
    $pay_user_id = $rider_id;
} else {
    $cust = $pdo->prepare("SELECT name, email, phone FROM customers WHERE id = ?");
    $cust->execute([$customer_id]);
    $user_data = $cust->fetch();
    $pay_user_id = $customer_id;
}

if (!$user_data) exit(json_encode(['success' => false, 'message' => 'User profile not found']));

$amount_to_pay = 0;
$vendor_amount = 0;
$vendor_id = ''; // Cashfree Vendor ID for Split
$order_note = "";
$shop_name = "Dukandaar";
$meta_data = null;

if ($bond_id) {
    $stmt = $pdo->prepare("SELECT b.amount, b.initial_paid, b.installment_count, s.cf_vendor_id, s.shop_name FROM bonds b JOIN shop_owners s ON b.shop_id = s.id WHERE b.id = ? AND b.customer_id = ?");
    $stmt->execute([$bond_id, $customer_id]);
    $bond = $stmt->fetch();
    if (!$bond) exit(json_encode(['success' => false, 'message' => 'Active Bond not found']));
    $base_kist_amount = ($bond['amount'] - $bond['initial_paid']) / ($bond['installment_count'] ?: 1);
    $amount_to_pay = $base_kist_amount * (1 + (BOND_PLATFORM_COMMISSION_PERCENT / 100)); // Customer pays base + 3%
    $vendor_amount = $base_kist_amount * (1 - (SHOP_SERVICE_FEE_PERCENT / 100)); // Shop gets base - 1%
    $vendor_id = $bond['cf_vendor_id'];
    $order_note = "Bond Kist #$bond_id";
    $shop_name = $bond['shop_name'];
} elseif ($monthly_id) {
    $stmt = $pdo->prepare("SELECT mk.total_amount, s.cf_vendor_id, s.shop_name FROM monthly_khata mk JOIN shop_owners s ON mk.shop_id = s.id WHERE mk.id = ? AND mk.customer_id = ?");
    $stmt->execute([$monthly_id, $customer_id]);
    $mk = $stmt->fetch();
    if (!$mk) exit(json_encode(['success' => false, 'message' => 'Monthly Khata not found']));
    $base_bill_amount = (float)$mk['total_amount'];
    $amount_to_pay = $base_bill_amount * (1 + (MONTHLY_PLATFORM_COMMISSION_PERCENT / 100)); // Customer pays base + 3%
    $vendor_amount = $base_bill_amount * (1 - (SHOP_SERVICE_FEE_PERCENT / 100)); // Shop gets base - 1%
    $vendor_id = $mk['cf_vendor_id'];
    $order_note = "Monthly Bill #$monthly_id";
    $shop_name = $mk['shop_name'];
} elseif ($is_marketplace) {
    $stmt = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
    $stmt->execute([$shop_id]);
    $shop_name = $stmt->fetchColumn() ?: 'Shop';

    $amount_to_pay = (float)($_POST['amount'] ?? 0);
    $vendor_amount = $amount_to_pay / 1.03; // Net to shop (Grand - 3% platform fee)
    $order_note = "Marketplace Order at $shop_name";
    $meta_data = json_encode([
        'cart' => $_POST['cart_json'] ?? '[]',
        'delivery' => $_POST['delivery_meta'] ?? '{}',
        'summary' => $_POST['summary_json'] ?? '{}' // Store summary data
    ]);
} elseif ($action === 'pay_platform_fee') {
    $delivery_boy_id = (int)($_POST['delivery_boy_id'] ?? 0);
    if (!$delivery_boy_id) exit(json_encode(['success' => false, 'message' => 'Delivery Boy ID missing.']));

    $amount_to_pay = (float)($_POST['amount'] ?? 0);
    $order_note = "Platform Fee Payment by Rider #$delivery_boy_id";
    $shop_name = "KhataLink Platform"; // Payment is to platform
    $meta_data = json_encode([
        'type' => 'platform_fee_payment',
        'delivery_boy_id' => $delivery_boy_id,
        'amount' => $amount_to_pay,
        'rider_details' => ['name' => $_POST['rider_name'], 'phone' => $_POST['rider_phone'], 'email' => $_POST['rider_email']]
    ]);
} else {
    $stmt = $pdo->prepare("SELECT cf_vendor_id, shop_name FROM shop_owners WHERE id = ?");
    $stmt->execute([$shop_id]);
    $shop_data = $stmt->fetch();
    $vendor_id = $shop_data['cf_vendor_id'] ?? '';
    $shop_name = $shop_data['shop_name'] ?? 'Shop';

        if ($custom_amount > 0) {
            $base_ledger_amount = (float)$custom_amount;
        } else {
            $stmt_due = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = ? AND customer_id = ? AND status = 'open'");
            $stmt_due->execute([$shop_id, $customer_id]);
            $base_ledger_amount = (float)$stmt_due->fetchColumn();
        }

    $amount_to_pay = $base_ledger_amount * (1 + (LEDGER_PLATFORM_COMMISSION_PERCENT / 100)); // Customer pays base + 3%
    $vendor_amount = $base_ledger_amount * (1 - (SHOP_SERVICE_FEE_PERCENT / 100)); // Shop gets base - 1%
    $order_note = "Ledger Payment for $shop_name";
}

    if ($amount_to_pay <= 0) exit(json_encode(['success' => false, 'message' => 'Invalid payment amount']));

// SAFETY CHECK: Dukandaar setup missing case
/**
 * NOTE: Cashfree EasySplit is rejected. 
 * So we treat every shop as "Offline" (Platform Pay Only) for now.
 */
// Marketplace orders and Rider platform fees always go to platform, so bypass offline check.
if (!$allow_platform_pay && !$is_marketplace && !$is_rider_paying) {
    exit(json_encode([
        'success' => false, 
        'needs_platform_pay' => true,
        'shop_name' => $shop_name,
        'message' => 'Is dukandaar ne online payment setup nahi kiya hai.'
    ]));
}

$order_id = "ORDER_" . time() . "_" . $customer_id . ($allow_platform_pay ? "_PLATFORM" : "");

// Payload for Cashfree v3
$payload = [
    "order_id" => $order_id,
    "order_amount" => round($amount_to_pay, 2),
    "order_currency" => "INR",
    "customer_details" => [
        "customer_id" => ($is_rider_paying ? "RIDER_" : "CUST_") . $pay_user_id,
        "customer_name" => $user_data['name'],
        "customer_email" => $user_data['email'] ?: 'support@khatalink.com',
        "customer_phone" => strlen(preg_replace('/[^0-9]/', '', $user_data['phone'])) >= 10 ? substr(preg_replace('/[^0-9]/', '', $user_data['phone']), -10) : '9999999999'
    ],
    "order_meta" => [
        // Approved ngrok URL used here to satisfy Cashfree's HTTPS and Whitelisting requirements
        "return_url" => "https://igloo-snowless-darling.ngrok-free.dev/khatalink/customer/cashfree_verify.php?order_id={order_id}",
        "notify_url" => "https://igloo-snowless-darling.ngrok-free.dev/khatalink/customer/cashfree_webhook.php"
    ],
    "order_note" => $order_note
];

$ch = curl_init(CF_BASE_URL . "/orders");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "x-client-id: " . CF_APP_ID,
    "x-client-secret: " . CF_SECRET_KEY,
    "x-api-version: " . CF_API_VERSION
]);

$response = curl_exec($ch);
curl_close($ch);
$order = json_decode($response, true);

if (!$order) {
    ob_clean();
    exit(json_encode(['success' => false, 'message' => 'Cashfree API unreachable']));
}

if (isset($order['payment_session_id'])) {
    // Save pending transaction details to DB
    try {
    if ($bond_id) {
        $pdo->prepare("INSERT INTO bond_payments (bond_id, amount_paid, razorpay_order_id, payment_status) VALUES (?, ?, ?, 'pending')")
            ->execute([$bond_id, $vendor_amount, $order_id]);
    } elseif ($monthly_id) {
        $pdo->prepare("UPDATE monthly_khata SET razorpay_order_id = ?, paid_amount = ? WHERE id = ?")
            ->execute([$order_id, $vendor_amount, $monthly_id]);
    } elseif ($is_marketplace || $is_rider_paying) {
        $pdo->prepare("INSERT INTO payment_requests 
            (shop_id, customer_id, amount, payment_mode, razorpay_order_id, status, meta_data) 
            VALUES (?, ?, ?, 'Online', ?, 'pending', ?)")
            ->execute([$shop_id, $customer_id, $vendor_amount, $order_id, $meta_data]);
    } else {
        $pdo->prepare("INSERT INTO payment_requests (shop_id, customer_id, amount, payment_mode, razorpay_order_id, status) VALUES (?, ?, ?, 'Cashfree', ?, 'pending')")
            ->execute([$shop_id, $customer_id, $vendor_amount, $order_id]);
    }
    } catch (PDOException $e) {
        ob_clean();
        exit(json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]));
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'payment_session_id' => $order['payment_session_id'],
        'order_id' => $order_id,
        'shop_id' => $shop_id,
        'amount' => round($amount_to_pay, 2)
    ]);
} else {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $order['message'] ?? 'Order creation failed']);
}
?>