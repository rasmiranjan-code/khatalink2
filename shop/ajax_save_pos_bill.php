<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';

$shop_id = 0;
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (!empty($token)) {
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0] ?? 0);
} elseif (isset($_SESSION['shop_id'])) {
    $shop_id = (int)$_SESSION['shop_id'];
}

if ($shop_id <= 0) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized access']));
}
$data = json_decode(file_get_contents('php://input'), true);

$customer_id = (int)($data['customer_id'] ?? 0); // 0 if Guest Customer
$customer_name = trim($data['customer_name'] ?? 'Guest Customer');
$items = $data['items'] ?? [];
$payment_status = $data['payment_status'] ?? 'paid_cash'; // 'paid_cash', 'paid_online', 'transferred_to_udhar'
$final_net_amount = (float)($data['final_net_amount'] ?? 0);

if (empty($items)) {
    exit(json_encode(['success' => false, 'message' => 'No items in bill.']));
}

// --- DEBUG LOGGING START ---
error_log("POS_BILL_SAVE_DEBUG: Shop ID: " . $shop_id);
error_log("POS_BILL_SAVE_DEBUG: Raw customer_id from frontend: " . ($data['customer_id'] ?? 'not set'));
// --- DEBUG LOGGING END ---
try {
    $pdo->beginTransaction();

    // Calculate totals from items for server-side validation
    $total_gross_amount = 0;
    $total_discount_amount = 0;
    $total_net_with_gst = 0;
    foreach ($items as $item) {
        $qty = (float)$item['qty'];
        $rate = (float)$item['rate'];
        $discount = (float)$item['discount'];
        $gst_p = (float)($item['gst_percent'] ?? 0);

        $total_gross_amount += ($qty * $rate);
        $total_discount_amount += ($qty * $discount);
        
        $sub = ($qty * $rate) - ($qty * $discount);
        $total_net_with_gst += $sub * (1 + ($gst_p / 100));
    }
    $calculated_net_amount = $total_net_with_gst;
    // --- DEBUG LOGGING START ---
    error_log("POS_BILL_SAVE_DEBUG: Calculated Net Amount: " . $calculated_net_amount);
    // --- DEBUG LOGGING END ---

    // Create a unique bill number
    $bill_number = 'POS-' . date('YmdHis') . '-' . uniqid();

    // 1. Insert into pos_bills
    $stmt_bill = $pdo->prepare("
        INSERT INTO pos_bills (shop_id, customer_id, bill_number, total_gross_amount, total_discount_amount, final_net_amount, payment_status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_bill->execute([
        $shop_id,
        $customer_id > 0 ? $customer_id : null, // Store null if guest to respect foreign key
        $bill_number,
        $total_gross_amount,
        $total_discount_amount,
        $calculated_net_amount,
        $payment_status
    ]);
    // --- DEBUG LOGGING START ---
    error_log("POS_BILL_SAVE_DEBUG: Inserted into pos_bills with customer_id: " . ($customer_id > 0 ? $customer_id : 'NULL') . " and bill_number: " . $bill_number);
    error_log("POS_BILL_SAVE_DEBUG: New pos_bill_id: " . $pdo->lastInsertId());
    // --- DEBUG LOGGING END ---
    $pos_bill_id = $pdo->lastInsertId();

    // 2. Insert into pos_bill_items and deduct stock
    $stmt_item = $pdo->prepare(" 
        INSERT INTO pos_bill_items (pos_bill_id, product_id, item_name, quantity, unit, rate, item_discount_amount, gst_percent, total_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_stock_deduct = $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock - ? WHERE id = ? AND shop_id = ?");

    foreach ($items as $item) {
        $product_id = (int)($item['product_id'] ?? 0); // From frontend, if product was found in inventory
        $qty = (float)$item['qty'];
        $rate = (float)$item['rate'];
        $discount = (float)$item['discount'];
        $unit = $item['unit'] ?? 'NOS';
        $gst_percent = (float)$item['gst_percent']; // New: Get GST percent
        $subtotal = ($qty * $rate) - ($qty * $discount);
        $item_net_total = $subtotal * (1 + ($gst_percent / 100)); // Apply GST

        $stmt_item->execute([
            $pos_bill_id,
            $product_id > 0 ? $product_id : null,
            $item['name'],
            $qty,
            $unit,
            $rate,
            $qty * $discount, // Total discount for this item row
            $gst_percent, // New: Store GST percent
            $item_net_total
        ]);

        // Deduct stock if product_id is valid
        if ($product_id > 0) {
            $stmt_stock_deduct->execute([$qty, $product_id, $shop_id]);
            notifyStockDeduction($pdo, (int)$shop_id, $product_id);
            checkInventoryAlert($pdo, (int)$shop_id, $product_id);
        }
    }
    // --- DEBUG LOGGING START ---
    error_log("POS_BILL_SAVE_DEBUG: All items inserted for pos_bill_id: " . $pos_bill_id);
    // --- DEBUG LOGGING END ---

    // 3. If payment_status is 'transferred_to_udhar', create udhar_entry
    if ($payment_status === 'transferred_to_udhar' && $customer_id > 0) {
        $stmt_udhar = $pdo->prepare("INSERT INTO udhar_entries (shop_id, customer_id, total_amount, total_remaining, discount_percentage, status, pos_bill_id) VALUES (?, ?, ?, ?, ?, 'open', ?)");
        // For udhar, we might need to fetch customer's tier discount if applicable, but for POS, we assume final_net_amount already has it.
        $stmt_udhar->execute([$shop_id, $customer_id, $calculated_net_amount, $calculated_net_amount, 0, $pos_bill_id]);
        // --- DEBUG LOGGING START ---
        error_log("POS_BILL_SAVE_DEBUG: Udhar entry created for customer_id: " . $customer_id . " with pos_bill_id: " . $pos_bill_id);
        // --- DEBUG LOGGING END ---
    } else {
        error_log("POS_BILL_SAVE_DEBUG: No udhar entry created. Payment status: " . $payment_status . ", Customer ID: " . $customer_id);
    }

    // --- Customer Notification Logic ---
    if ($customer_id > 0) {
        $stmt_s = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
        $stmt_s->execute([$shop_id]);
        $s_name = $stmt_s->fetchColumn() ?: 'Shop';

        if ($payment_status === 'transferred_to_udhar') {
            $title = "Naya Udhar: " . $s_name;
            $body = "₹" . number_format($calculated_net_amount, 2) . " ka bill aapke udhar khata mein add ho gaya hai. Details app mein dekhein.";
            sendKhataPush($pdo, $customer_id, 'customer', $title, $body, ['type' => 'ledger', 'shop_id' => (string)$shop_id]);
        } else {
            $title = "Payment Successful: " . $s_name;
            $body = "Aapka ₹" . number_format($calculated_net_amount, 2) . " ka bhugtan safal raha. Kripya apna bill PDF download karein.";
            sendKhataPush($pdo, $customer_id, 'customer', $title, $body, ['type' => 'pos_bill', 'bill_id' => (string)$pos_bill_id]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Bill saved successfully!', 'bill_id' => $pos_bill_id]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("POS Bill Save Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save bill: ' . $e->getMessage()]);
}
?>