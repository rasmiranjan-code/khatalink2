<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
require_once '../includes/Groceries_inventory_engine.php';
require_once '../includes/notification_service.php';

$order_id = trim($_GET['order_id'] ?? '');
if (empty($order_id)) die("Invalid Request");

// 1. Check payment status from Cashfree API
$ch = curl_init(CF_BASE_URL . "/orders/" . $order_id);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "x-client-id: " . CF_APP_ID,
    "x-client-secret: " . CF_SECRET_KEY,
    "x-api-version: " . CF_API_VERSION
]);
$response = curl_exec($ch);
curl_close($ch);
$order = json_decode($response, true);

if (isset($order['order_status']) && $order['order_status'] === 'PAID') {
    // ── AUTHENTICATION CHECK ──
    $customer_id = 0;
    $is_api = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp');

    if ($is_api) {
        $token = get_auth_token();
        $parts = verify_secure_token($token);
        if ($parts) $customer_id = (int)$parts[0];
    } else {
        $customer_id = (int)($_SESSION['customer_id'] ?? 0);
    }

    try {
        $pdo->beginTransaction();

        // FIX: Initialize all shared variables at the top to avoid undefined variable warnings
        $current_customer_id = null;
        $current_shop_id     = null;
        $shop_id             = null;

        // Determine if it was a Platform Pay or Direct Shop Pay
        $is_platform  = (strpos($order_id, '_PLATFORM') !== false);
        $display_mode = $is_platform ? 'Platform Pay' : 'Cashfree';

        $payment_id = $order['order_id'];
        $cf_ref     = 'CF_' . $payment_id;

        // ── 1. Check Bond Payment ──────────────────────────────────────────────
        $stmt_pay = $pdo->prepare("SELECT bond_id, amount_paid FROM bond_payments WHERE razorpay_order_id = ? AND payment_status = 'pending'");
        $stmt_pay->execute([$payment_id]);
        $bond_data = $stmt_pay->fetch();

        if ($bond_data) {
            $original_base_amount = $bond_data['amount_paid'] / (1 - (SHOP_SERVICE_FEE_PERCENT / 100));

            $pdo->prepare("UPDATE bond_payments SET razorpay_payment_id = ?, payment_status = 'completed' WHERE razorpay_order_id = ?")
                ->execute([$cf_ref, $payment_id]);
            $pdo->prepare("UPDATE bonds SET paid_amount = paid_amount + ? WHERE id = ?")
                ->execute([$original_base_amount, $bond_data['bond_id']]);

            $stmt_b = $pdo->prepare("SELECT amount, paid_amount FROM bonds WHERE id = ?");
            $stmt_b->execute([$bond_data['bond_id']]);
            $bond = $stmt_b->fetch();
            if ($bond && (float)$bond['paid_amount'] >= (float)$bond['amount']) {
                $pdo->prepare("UPDATE bonds SET status = 'closed' WHERE id = ?")
                    ->execute([$bond_data['bond_id']]);
            }

            // FIX: Fetch customer_id from the bond record itself (it wasn't being fetched before)
            $stmt_info = $pdo->prepare("
                SELECT s.shop_name, c.name, s.id AS shop_id, b.customer_id
                FROM bonds b
                JOIN shop_owners s ON b.shop_id = s.id
                JOIN customers c ON b.customer_id = c.id
                WHERE b.id = ?
            ");
            $stmt_info->execute([$bond_data['bond_id']]);
            $info = $stmt_info->fetch();

            if ($info) {
                // FIX: Derive customer_id from query result instead of undefined variable
                $bond_customer_id = (int)$info['customer_id'];

                $shop_msg = $is_platform
                    ? "Customer {$info['name']} ne ₹" . number_format($original_base_amount, 2) . " KhataLink ko pay kar diya hai (Bond). Ab ye rakam aapke next settlement main ayega."
                    : "Customer {$info['name']} ne ₹" . number_format($original_base_amount, 2) . " pay kiye hain (via Cashfree).";

                sendKhataPush($pdo, $bond_customer_id, 'customer', "Bond Payment Confirmed! ✅",
                    "Aapka ₹" . number_format($original_base_amount, 2) . " ka installment {$info['shop_name']} ko pay ho gaya hai. Dhanyawad!",
                    null, ['type' => 'bond_payment', 'bond_id' => (string)$bond_data['bond_id']]);

                sendKhataPush($pdo, (int)$info['shop_id'], 'shop', "New Bond Payment", $shop_msg,
                    null, ['type' => 'bond_payment', 'bond_id' => (string)$bond_data['bond_id']]);
            }
        }

        // ── 2. Check Monthly Khata ─────────────────────────────────────────────
        $stmt_m = $pdo->prepare("SELECT id, total_amount, shop_id, customer_id FROM monthly_khata WHERE razorpay_order_id = ? AND status = 'open'");
        $stmt_m->execute([$payment_id]);
        $mk = $stmt_m->fetch();

        if ($mk) {
            $shop_id             = (int)$mk['shop_id'];
            $customer_id         = (int)$mk['customer_id'];
            // FIX: Also set the $current_ variants so FIFO block can use them if needed
            $current_shop_id     = $shop_id;
            $current_customer_id = $customer_id;

            $original_base_amount = $mk['total_amount'] / (1 - (SHOP_SERVICE_FEE_PERCENT / 100));

            $pdo->prepare("UPDATE monthly_khata SET razorpay_payment_id = ?, status = 'closed', paid_at = NOW() WHERE razorpay_order_id = ?")
                ->execute([$cf_ref, $payment_id]);

            $stmt_info = $pdo->prepare("
                SELECT s.shop_name, c.name, s.id AS shop_id
                FROM monthly_khata mk
                JOIN shop_owners s ON mk.shop_id = s.id
                JOIN customers c ON mk.customer_id = c.id
                WHERE mk.id = ?
            ");
            $stmt_info->execute([$mk['id']]);
            $info = $stmt_info->fetch();

            if ($info) {
                sendKhataPush($pdo, $customer_id, 'customer', "Monthly Khata Paid! ✅",
                    "Aapka ₹" . number_format($original_base_amount, 2) . " ka monthly bill {$info['shop_name']} ko pay ho gaya hai. Dhanyawad!",
                    null, ['type' => 'monthly_paid', 'monthly_id' => (string)$mk['id']]);

                sendKhataPush($pdo, (int)$info['shop_id'], 'shop', "Monthly Khata Paid!",
                    "Customer {$info['name']} ne ₹" . number_format($original_base_amount, 2) . " ka monthly bill pay kiya hai (via Cashfree).",
                    null, ['type' => 'monthly_paid', 'monthly_id' => (string)$mk['id']]);
            }
        }

        // ── 3. Ledger Payment (FIFO) ───────────────────────────────────────────
        $stmt_req = $pdo->prepare("SELECT id, shop_id, customer_id, amount, meta_data FROM payment_requests WHERE razorpay_order_id = ? AND status = 'pending'");
        $stmt_req->execute([$payment_id]);
        $req_data = $stmt_req->fetch();

        if ($req_data) {
            $shop_id     = (int)$req_data['shop_id'];
            $customer_id = (int)$req_data['customer_id'];
            // FIX: Set $current_ variants from payment_request — this is the authoritative source
            $current_shop_id     = $shop_id;
            $current_customer_id = $customer_id;

            $total_paid = (float)$req_data['amount'];
            $meta = !empty($req_data['meta_data']) ? json_decode($req_data['meta_data'], true) : null;

            // ── HANDLE MARKETPLACE ORDER CREATION ──
            if ($meta && (!isset($meta['type']) || $meta['type'] !== 'platform_fee_payment')) {
                $cart    = json_decode($meta['cart'], true);
                $dlv     = json_decode($meta['delivery'], true);
                $summary = json_decode($meta['summary'], true);

                $subtotal_from_summary      = (float)($summary['subtotal'] ?? 0);
                $delivery_fee_from_summary  = (float)($summary['delivery'] ?? 0);
                $platform_fee_from_summary  = (float)($summary['platform'] ?? 0);
                $grand_total_from_summary   = (float)($summary['grand'] ?? 0);
                $net_to_shop                = $subtotal_from_summary;

                $stmt_ord = $pdo->prepare("INSERT INTO orders 
                    (customer_id, shop_id, order_status, payment_mode, pincode, delivery_name, delivery_phone, delivery_email, delivery_apartment_house, delivery_landmark, delivery_village, delivery_block, delivery_district, latitude, longitude, total_amount, delivery_fee, net_to_shop, is_marketplace_order) 
                    VALUES (?, ?, 'pending', 'Online', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

                // FIX: $current_customer_id and $current_shop_id are now properly set above
                $stmt_ord->execute([
                    $current_customer_id,
                    $current_shop_id,
                    $dlv['pincode']  ?? '',
                    $dlv['name']     ?? '',
                    $dlv['phone']    ?? '',
                    $dlv['email']    ?? '',
                    $dlv['address']  ?? '',
                    $meta['delivery_landmark'] ?? '',
                    $meta['delivery_village']  ?? '',
                    $meta['delivery_block']    ?? '',
                    $meta['delivery_district'] ?? '',
                    $meta['latitude']  ?? 0,
                    $meta['longitude'] ?? 0,
                    $grand_total_from_summary,
                    $delivery_fee_from_summary,
                    $net_to_shop
                ]);
                $new_ord_id = $pdo->lastInsertId();

                foreach ($cart as $item) {
                    $pdo->prepare("INSERT INTO order_items (order_id, product_id, item_name, quantity, unit, price_per_unit, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$new_ord_id, $item['product_id'], $item['name'], $item['qty'], $item['unit'], $item['price'], $item['price'] * $item['qty']]);
                    groceries_reserve_stock($pdo, (int)$item['product_id'], (float)$item['qty']);
                }

                sendKhataPush($pdo, $current_shop_id, 'shop', "New Online Order! 💰",
                    "Order #$new_ord_id ke liye ₹" . number_format($total_paid, 2) . " receive ho gaye hain. Packing shuru karein!",
                    null, ['type' => 'order', 'id' => (string)$new_ord_id]);

            } elseif (isset($meta['type']) && $meta['type'] === 'platform_fee_payment') {
                $delivery_boy_id = (int)$meta['delivery_boy_id'];
                $paid_amount     = (float)$meta['amount'];

                $pdo->prepare("UPDATE delivery_ledger SET is_platform_fee_paid = 1 WHERE delivery_boy_id = ? AND is_platform_fee_paid = 0 AND platform_fee_amount > 0")
                    ->execute([$delivery_boy_id]);

                sendKhataPush($pdo, $delivery_boy_id, 'delivery', "Platform Fee Paid! ✅",
                    "Aapne ₹" . number_format($paid_amount, 2) . " KhataLink Platform ko pay kar diya hai. Dhanyawad!",
                    null, ['type' => 'platform_payment']);

                $pdo->commit();
                header("Location: ../delivery/dashboard.php?success=Platform Fee Paid Successfully!");
                exit();

            } else {
                // ── Existing Ledger FIFO logic for normal debt payments ──
                // FIX: $current_customer_id and $current_shop_id are guaranteed set above
                $stmt_entries = $pdo->prepare("SELECT * FROM udhar_entries WHERE customer_id = ? AND shop_id = ? AND status = 'open' ORDER BY created_at ASC");
                $stmt_entries->execute([$current_customer_id, $current_shop_id]);
                $open_entries = $stmt_entries->fetchAll();
                $rem = $total_paid;

                foreach ($open_entries as $entry) {
                    if ($rem <= 0) break;
                    $apply         = min($rem, (float)$entry['total_remaining']);
                    $new_rem_entry = (float)$entry['total_remaining'] - $apply;
                    $is_closed     = ($new_rem_entry <= 0) ? 'closed' : 'open';

                    $pdo->prepare("UPDATE udhar_entries SET total_remaining = ?, total_paid = total_paid + ?, status = ? WHERE id = ?")
                        ->execute([$new_rem_entry, $apply, $is_closed, $entry['id']]);

                    $pdo->prepare("INSERT INTO payment_history (entry_id, shop_id, customer_id, amount_paid, remaining_after, payment_mode, razorpay_payment_id, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                        ->execute([$entry['id'], $current_shop_id, $current_customer_id, $apply, $new_rem_entry, $display_mode, $cf_ref]);

                    $rem -= $apply;
                }

                $pdo->prepare("UPDATE payment_requests SET status = 'approved', razorpay_payment_id = ? WHERE id = ?")
                    ->execute([$cf_ref, $req_data['id']]);

                $stmt_info = $pdo->prepare("SELECT s.shop_name, c.name, s.id AS shop_id FROM shop_owners s, customers c WHERE s.id = ? AND c.id = ?");
                $stmt_info->execute([$current_shop_id, $current_customer_id]);
                $info = $stmt_info->fetch();

                if ($info) {
                    $shop_msg = $is_platform
                        ? "Customer {$info['name']} ne ₹" . number_format($total_paid, 2) . " KhataLink ko pay kar diya hai. Ab ye rakam aapke next settlement main ayega."
                        : "Customer {$info['name']} ne ₹" . number_format($total_paid, 2) . " online pay kiye hain.";

                    sendKhataPush($pdo, $current_customer_id, 'customer', "Payment Successful! 🎉",
                        "Aapka ₹" . number_format($total_paid, 2) . " ka bhugtan {$info['shop_name']} ko safal raha. Kripya app se receipt download karein.",
                        null, ['type' => 'payment_success', 'shop_id' => (string)$current_shop_id]);

                    sendKhataPush($pdo, (int)$info['shop_id'], 'shop', "New Payment Received", $shop_msg,
                        null, ['type' => 'payment_received', 'customer_id' => (string)$current_customer_id]);
                }
            }
        }

        $pdo->commit();
        header("Location: dashboard.php?success=Payment Verified and Records Updated");

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Verification Error: " . $e->getMessage());
    }
} else {
    header("Location: dashboard.php?error=Payment " . ($order['order_status'] ?? 'Unknown'));
}
?>