<?php
ob_start(); // Prevent accidental whitespace output
require_once '../includes/db.php';
require_once '../includes/razorpay_config.php';
require_once '../includes/notification_service.php';

$customer_id = 0;
$auth_source = 'unknown';

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

$order_id   = $_POST['razorpay_order_id'] ?? '';
$payment_id = $_POST['razorpay_payment_id'] ?? '';
$signature  = $_POST['razorpay_signature'] ?? '';

if (empty($order_id)) die(json_encode(['success' => false, 'message' => 'Missing Order ID']));

// Verify Razorpay Signature
$generated_signature = hash_hmac('sha256', $order_id . "|" . $payment_id, RZP_KEY_SECRET);

header('Content-Type: application/json');

// Helper to notify admin
function notifyAdmin(PDO $pdo, string $msg): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_notifications (message, created_at) VALUES (?, NOW())");
        $stmt->execute([$msg]);
    } catch (Exception $e) {
        // Fail silently if admin table missing, don't crash the payment verify
    }
}

// Determine Payment Mode Display Name
$display_mode = (strpos($order_id, '_PLATFORM') !== false) ? 'Platform Pay' : 'Razorpay';

if ($generated_signature == $signature) {
    try {
        $pdo->beginTransaction();
        $payment_processed = false; // Flag to ensure only one type of payment is processed

        // Helper check for Platform Pay
        $is_platform = (strpos($order_id, '_PLATFORM') !== false);

        // 1. Check if it's a Bond Payment
        $stmt_pay = $pdo->prepare("SELECT bond_id, amount_paid FROM bond_payments WHERE razorpay_order_id = ? AND payment_status = 'pending'");
        $stmt_pay->execute([$order_id]);
        $bond_data = $stmt_pay->fetch();

        if ($bond_data) {
            $bond_id = $bond_data['bond_id'];
            $amt = (float)$bond_data['amount_paid'];
            $pdo->prepare("UPDATE bond_payments SET razorpay_payment_id = ?, payment_status = 'completed' WHERE razorpay_order_id = ?")->execute([$payment_id, $order_id]);
            $pdo->prepare("UPDATE bonds SET paid_amount = paid_amount + ? WHERE id = ?")->execute([$amt, $bond_id]);
            
            $stmt_b = $pdo->prepare("SELECT amount, paid_amount FROM bonds WHERE id = ?");
            $stmt_b->execute([$bond_id]);
            $bond = $stmt_b->fetch();
            if ($bond && (float)$bond['paid_amount'] >= (float)$bond['amount']) {
                $pdo->prepare("UPDATE bonds SET status = 'closed' WHERE id = ?")->execute([$bond_id]);
            }
            $payment_processed = true;
            
            // Notify Admin if platform pay
            if ($is_platform) {
                $stmt_info = $pdo->prepare("SELECT s.shop_name, c.name FROM bonds b JOIN shop_owners s ON b.shop_id = s.id JOIN customers c ON b.customer_id = c.id WHERE b.id = ?");
                $stmt_info->execute([$bond_id]); $info = $stmt_info->fetch();
                notifyAdmin($pdo, "Platform Pay: Customer {$info['name']} paid ₹{$amt} for Shop {$info['shop_name']} (Bond #{$bond_id})");
                
                // Notifications
                sendKhataPush($pdo, $customer_id, 'customer', "Bond Payment Successful", "Aapka ₹$amt ka installment jama ho gaya hai.");
                sendKhataPush($pdo, $info['shop_id'], 'shop', "New Bond Payment", "Customer {$info['name']} ne ₹$amt pay kiye hain.");
            }
        } 
        // 2. Check if it's a Monthly Khata Payment
        if (!$payment_processed) { // Only check if not already processed as bond
            $stmt_m = $pdo->prepare("SELECT id, total_amount, shop_id, customer_id FROM monthly_khata WHERE razorpay_order_id = ? AND status = 'open'");
            $stmt_m->execute([$order_id]);
            $monthly_khata_data = $stmt_m->fetch();

            if ($monthly_khata_data) {
                $pdo->prepare("UPDATE monthly_khata SET razorpay_payment_id = ?, status = 'closed' WHERE razorpay_order_id = ?")
                    ->execute([$payment_id, $order_id]);
                $payment_processed = true;

                if ($is_platform) {
                    $stmt_info = $pdo->prepare("SELECT s.shop_name, c.name, s.id as shop_id FROM monthly_khata mk JOIN shop_owners s ON mk.shop_id = s.id JOIN customers c ON mk.customer_id = c.id WHERE mk.id = ?");
                    $stmt_info->execute([$monthly_khata_data['id']]); $info = $stmt_info->fetch();
                    
                    // Notifications for Monthly Khata
                    sendKhataPush($pdo, (int)$customer_id, 'customer', "Monthly Khata Paid! ✅", "Aapka ₹" . number_format($monthly_khata_data['total_amount'], 2) . " ka monthly bill {$info['shop_name']} ko pay ho gaya hai. Dhanyawad!", ['type' => 'monthly_paid', 'monthly_id' => (string)$monthly_khata_data['id']]);
                    sendKhataPush($pdo, (int)$info['shop_id'], 'shop', "Monthly Khata Paid!", "Customer {$info['name']} ne ₹" . number_format($monthly_khata_data['total_amount'], 2) . " ka monthly bill pay kiya hai (via Platform).", ['type' => 'monthly_paid', 'monthly_id' => (string)$monthly_khata_data['id']]);

                    notifyAdmin($pdo, "Platform Pay (Monthly): {$info['name']} paid ₹{$monthly_khata_data['total_amount']} for {$info['shop_name']}");
                }
            }
        }

        // 3. Ledger (Shop) Payment Logic (FIFO) - Only if no other payment type was processed
        if (!$payment_processed) {
            // 2. Ledger (Shop) Payment Logic (FIFO)
            $stmt_req = $pdo->prepare("SELECT id, shop_id, customer_id, amount FROM payment_requests WHERE razorpay_order_id = ? AND status = 'pending'");
            $stmt_req->execute([$order_id]);
            $req_data = $stmt_req->fetch();

            if ($req_data) {
                $shop_id = $req_data['shop_id'];
                $customer_id = $req_data['customer_id'];
                $total_paid = (float)$req_data['amount'];

                // Existing FIFO Ledger clearing logic
                $stmt_entries = $pdo->prepare("SELECT * FROM udhar_entries WHERE customer_id = ? AND shop_id = ? AND status = 'open' ORDER BY created_at ASC");
                $stmt_entries->execute([$customer_id, $shop_id]);
                $open_entries = $stmt_entries->fetchAll();
                $rem = $total_paid;

                foreach($open_entries as $entry) {
                    if($rem <= 0) break;
                    $apply = min($rem, (float)$entry['total_remaining']);
                    $new_rem_entry = (float)$entry['total_remaining'] - $apply;
                    $is_closed = ($new_rem_entry <= 0) ? 'closed' : 'open';
                    
                    $pdo->prepare("UPDATE udhar_entries SET total_remaining = ?, total_paid = total_paid + ?, status = ? WHERE id = ?")
                        ->execute([$new_rem_entry, $apply, $is_closed, $entry['id']]);
                    
                    // Refined: Marking mode as 'Platform Pay' if handled by KhataLink
                    $pdo->prepare("INSERT INTO payment_history (entry_id, shop_id, customer_id, amount_paid, remaining_after, payment_mode, razorpay_payment_id, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                        ->execute([$entry['id'], $shop_id, $customer_id, $apply, $new_rem_entry, $display_mode, $payment_id]);
                    
                    $rem -= $apply;
                }
                // Success: Update request status so it shows in Admin Settlements
                // Success: Update request status so it shows in Admin Settlements and Shop History instantly
                $pdo->prepare("UPDATE payment_requests SET status = 'approved', razorpay_payment_id = ?, is_settled_manually = 0 WHERE id = ?")
                    ->execute([$payment_id, $req_data['id']]);
                $payment_processed = true;

                if ($is_platform) {
                    $stmt_info = $pdo->prepare("SELECT s.shop_name, c.name FROM shop_owners s, customers c WHERE s.id = ? AND c.id = ?");
                    $stmt_info->execute([$shop_id, $customer_id]); $info = $stmt_info->fetch();
                    notifyAdmin($pdo, "Platform Pay (Ledger): {$info['name']} paid ₹{$total_paid} for {$info['shop_name']}");
                    
                    // Notifications
                    sendKhataPush($pdo, $customer_id, 'customer', "Payment Successful! 🎉", "Aapka ₹" . number_format($total_paid, 2) . " ka bhugtan {$info['shop_name']} ko safal raha. Kripya app se receipt download karein.", ['type' => 'payment_success', 'shop_id' => (string)$shop_id]);
                    sendKhataPush($pdo, $shop_id, 'shop', "New Payment (FIFO)", "Customer {$info['name']} ne ₹" . number_format($total_paid, 2) . " pay kiye hain (via Platform).", ['type' => 'payment_received', 'customer_id' => (string)$customer_id]);
                }
            }
        }

        if ($payment_processed) {
            $pdo->commit();
            echo json_encode(['success' => true]);
        } else {
            $pdo->rollBack(); // Rollback if no known payment type was processed
            echo json_encode(['success' => false, 'message' => 'No matching pending payment found for this order.']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Razorpay Verify Error: " . $e->getMessage()); 
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Payment not successful or pending']);
}
?>