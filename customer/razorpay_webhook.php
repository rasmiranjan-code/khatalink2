<?php
/**
 * KhataLink Razorpay Webhook Handler
 * URL to set in Dashboard: http://yourdomain.com/customer/razorpay_webhook.php
 */
require_once '../includes/db.php';
require_once '../includes/razorpay_config.php';

// 1. Get the raw POST body and Razorpay Signature
$json = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (empty($json) || empty($sig)) {
    http_response_code(400);
    exit("Invalid Request");
}

// 2. Verify Webhook Signature
$expected_signature = hash_hmac('sha256', $json, RZP_WEBHOOK_SECRET);

if ($sig !== $expected_signature) {
    error_log("Razorpay Webhook: Invalid Signature Attempt.");
    http_response_code(403);
    exit("Signature Verification Failed");
}

// 3. Parse JSON Data
$data = json_decode($json, true);

// Hum sirf successful payments (captured) ko process karenge
if ($data['event'] === 'payment.captured') {
    $payment_entity = $data['payload']['payment']['entity'];
    $order_id       = $payment_entity['order_id'];
    $payment_id     = $payment_entity['id'];
    $amount_paid    = (float)($payment_entity['amount'] / 100); // Paise to Rupees

    try {
        $pdo->beginTransaction();

        // Check if this order is already processed (Prevent Double Counting)
        $stmt = $pdo->prepare("SELECT bond_id, amount_paid, payment_status FROM bond_payments WHERE razorpay_order_id = ?");
        $stmt->execute([$order_id]);
        $local_payment = $stmt->fetch();

        if ($local_payment && $local_payment['payment_status'] !== 'completed') {
            $bond_id = $local_payment['bond_id']; // This amount_paid is what the shop receives
            
            // Calculate the original base amount before shop's 1% deduction
            $original_base_amount = $local_payment['amount_paid'] / (1 - (SHOP_SERVICE_FEE_PERCENT / 100));
            
            // A. Update Payment Record
            $pdo->prepare("UPDATE bond_payments SET razorpay_payment_id = ?, payment_status = 'completed' WHERE razorpay_order_id = ?")
                ->execute([$payment_id, $order_id]);

            // B. Update Main Bond Balance
            $pdo->prepare("UPDATE bonds SET paid_amount = paid_amount + ? WHERE id = ?") // Update with original base amount
                ->execute([$original_base_amount, $bond_id]);

            // C. Check and Close Bond if fully paid
            $stmt_check = $pdo->prepare("SELECT amount, paid_amount FROM bonds WHERE id = ?");
            $stmt_check->execute([$bond_id]);
            $bond = $stmt_check->fetch();
            
            if ($bond && (float)$bond['paid_amount'] >= (float)$bond['amount']) {
                $pdo->prepare("UPDATE bonds SET status = 'closed' WHERE id = ?")->execute([$bond_id]);
            }

            error_log("Razorpay Webhook: Successfully updated Bond ID $bond_id for Order $order_id");
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Razorpay Webhook Error: " . $e->getMessage());
        http_response_code(500);
        exit();
    }
}

// Razorpay expects a 200 OK response to acknowledge receipt
http_response_code(200);
?>