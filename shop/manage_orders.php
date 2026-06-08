<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
require_once '../includes/Groceries_inventory_engine.php';
require_once '../includes/notification_service.php';

// Helper function to generate 6-digit random code
function generateCode() { return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT); }

// ===== AUTHENTICATION LAYER =====
$shop_id = 0;
$is_api = false;

if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0] ?? 0);
    $role = $parts[2] ?? '';
    if($role !== 'shop') $shop_id = 0;
} else {
    $shop_id = $_SESSION['shop_id'] ?? 0;
}

if(!$shop_id) {
    if($is_api) exit(json_encode(['success'=>false, 'message'=>'Unauthorized']));
    header("Location: ../auth/login.php?type=shop"); exit();
}

// ===== ACTION HANDLING (POST) =====
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $is_api ? json_decode(file_get_contents('php://input'), true) : $_POST;
    $action = $data['action'] ?? '';
    $order_id = (int)($data['order_id'] ?? 0);
    $msg = 'Success'; // Default message

    // Fetch Shop Name once for all notifications in this request
    $s_name_stmt = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
    $s_name_stmt->execute([$shop_id]);
    $s_name = $s_name_stmt->fetchColumn() ?: 'Shop';

    try {
        $pdo->beginTransaction();

        if($action === 'accept_order') {
            // 1. Link customer to shop if not already linked (Fix for "Customers main nahi dikha")
            $stmt_cust_info = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $stmt_cust_info->execute([$order_id]);
            $target_customer_id = (int)$stmt_cust_info->fetchColumn();

            $stmt_check = $pdo->prepare("SELECT id FROM shop_customers WHERE shop_id = ? AND customer_id = ?");
            $stmt_check->execute([$shop_id, $target_customer_id]);
            
            if (!$stmt_check->fetch() && $target_customer_id > 0) {
                $pdo->prepare("INSERT INTO shop_customers (shop_id, customer_id) VALUES (?, ?)")->execute([$shop_id, $target_customer_id]);

                // Send Welcome Notification to Customer
                sendKhataPush($pdo, $target_customer_id, 'customer', "Khata Activated! 🎉", "Namaste! $s_name ne aapka digital khata activate kar diya hai. Ab aap apne udhar aur payments real-time track kar sakte hain.");
            }

            // Update order status to accepted
            $pdo->prepare("UPDATE orders SET order_status = 'accepted' WHERE id = ? AND shop_id = ?")->execute([$order_id, $shop_id]);
            // Fetch customer name for notification
            $stmt_c_name = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
            $stmt_c_name->execute([$target_customer_id]);
            $customer_name = $stmt_c_name->fetchColumn() ?: 'Customer';
            // Notify Customer to track order
            sendKhataPush($pdo, $target_customer_id, 'customer', "Order Accepted! ✅", "$s_name ne aapka order #$order_id accept kar liya hai. Ab aap ise track kar sakte hain.", null, ['type' => 'order', 'id' => (string)$order_id]);

            $msg = "Order accepted. Now assign a delivery boy.";
        }
        elseif($action === 'approve_cancel') {
            $pdo->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ?")->execute([$order_id]);
            
            // Return Stock to Inventory
            $stmt_it = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $stmt_it->execute([$order_id]);
            foreach($stmt_it->fetchAll() as $item) {
                if($item['product_id']) groceries_add_stock_back($pdo, (int)$item['product_id'], (float)$item['quantity']);
            }

            $stmt_cid = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?"); $stmt_cid->execute([$order_id]); $cid = $stmt_cid->fetchColumn();
            sendKhataPush($pdo, (int)$cid, 'customer', "Order Cancelled ✅", "$s_name ne aapka cancellation accept kar liya hai.");
            $msg = "Cancellation approved.";
        }
        elseif($action === 'reject_cancel') {
            $pdo->prepare("UPDATE orders SET order_status = 'accepted' WHERE id = ?")->execute([$order_id]);
            $stmt_cid = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?"); $stmt_cid->execute([$order_id]); $cid = $stmt_cid->fetchColumn();
            sendKhataPush($pdo, (int)$cid, 'customer', "Cancellation Rejected ❌", "Dukan ne cancellation mana kar di hai. Order process ho chuka hai.");
            $msg = "Cancellation rejected. Order is back to Accepted.";
        }
        elseif($action === 'decline_order') {
            // Fetch target customer ID before cancelling
            $stmt_info = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $stmt_info->execute([$order_id]);
            $cid = (int)$stmt_info->fetchColumn();

            // Update order status to cancelled
            $pdo->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ? AND shop_id = ?")->execute([$order_id, $shop_id]);

            // Notify Customer
            sendKhataPush($pdo, (int)$cid, 'customer', "Order Cancelled ❌", "$s_name ne aapka order #$order_id cancel kar diya hai.");

            $msg = "Order declined.";
        }
        elseif($action === 'delete_order_permanently') {
            // Permanently remove from database
            $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$order_id]);
            $pdo->prepare("DELETE FROM delivery_assignments WHERE order_id = ?")->execute([$order_id]);
            $pdo->prepare("DELETE FROM orders WHERE id = ? AND shop_id = ?")->execute([$order_id, $shop_id]);
            $msg = "Order #$order_id has been permanently deleted from database.";
        }
        elseif($action === 'set_item_price') {
            $item_id = (int)($data['item_id'] ?? 0);
            $price = (float)($data['price'] ?? 0);
            $qty = (float)($data['qty'] ?? 1);
            $unit = trim($data['unit'] ?? 'PCS');

            // Update item price and unit
            $pdo->prepare("UPDATE order_items SET price_per_unit = ?, total_price = ?, unit = ? WHERE id = ? AND order_id = ?")->execute([$price, $price * $qty, $unit, $item_id, $order_id]);
            
            // ── FIXED: Fetch delivery fee to maintain correct grand total ──
            $stmt_order_data = $pdo->prepare("SELECT delivery_fee FROM orders WHERE id = ?");
            $stmt_order_data->execute([$order_id]);
            $d_fee = (float)$stmt_order_data->fetchColumn();

            // Recalculate items sum ONLY
            $stmt_items_sum = $pdo->prepare("SELECT SUM(total_price) FROM order_items WHERE order_id = ?");
            $stmt_items_sum->execute([$order_id]);
            $items_sum = (float)$stmt_items_sum->fetchColumn();

            // Update order: total_amount = Items + Fee, net_to_shop = Items only
            $pdo->prepare("UPDATE orders SET total_amount = ?, net_to_shop = ? WHERE id = ?")
                ->execute([$items_sum + $d_fee, $items_sum, $order_id]);

            $msg = "Item price updated.";
        }
        elseif($action === 'assign_delivery_boy') {
            $delivery_boy_id = (int)($data['delivery_boy_id'] ?? 0);
            
            // Check for existing valid code (2 min logic)
            $check_stmt = $pdo->prepare("SELECT pickup_code, code_updated_at FROM orders WHERE id = ?");
            $check_stmt->execute([$order_id]);
            $current = $check_stmt->fetch();
            
            $is_old = (strtotime($current['code_updated_at'] ?? '') < (time() - 120));
            $pickup_code = (!$current['pickup_code'] || $is_old) ? generateCode() : $current['pickup_code'];

            // Create assignment record
            $pdo->prepare("INSERT INTO delivery_assignments (order_id, delivery_boy_id, assignment_status) VALUES (?, ?, 'pending')")->execute([$order_id, $delivery_boy_id]);
            // Update order status and assign pickup code
            $pdo->prepare("UPDATE orders SET order_status = 'assigned', delivery_boy_id = ?, pickup_code = ?, code_updated_at = NOW() WHERE id = ? AND shop_id = ?")->execute([$delivery_boy_id, $pickup_code, $order_id, $shop_id]);
            
            // Fetch Delivery Boy Name
            $stmt_db_name = $pdo->prepare("SELECT name FROM delivery_partners WHERE id = ?");
            $stmt_db_name->execute([$delivery_boy_id]);
            $db_name = $stmt_db_name->fetchColumn() ?: 'Delivery Partner';

            // Notify Delivery Partner
            sendKhataPush($pdo, $delivery_boy_id, 'delivery', "Naya Order Mila! 📦", "$s_name se Order #$order_id pickup ke liye taiyar hai. Pickup Code: $pickup_code. Dukan pahuchein.", null, ['type' => 'order', 'id' => (string)$order_id]);

            // Notify Customer
            $stmt_c = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?"); $stmt_c->execute([$order_id]); $cid = $stmt_c->fetchColumn();
            sendKhataPush($pdo, (int)$cid, 'customer', "Partner Assigned 🛵", "Aapke order ke liye delivery partner nikal chuka hai.");

            $msg = "Delivery boy assigned. Pickup code: $pickup_code";
        }
        elseif($action === 'send_handover_code') {
            $h_code = generateCode();
            $pdo->prepare("UPDATE orders SET handover_code = ?, code_updated_at = NOW() WHERE id = ?")->execute([$h_code, $order_id]);
            
            // Fetch delivery boy ID
            $stmt_db = $pdo->prepare("SELECT delivery_boy_id FROM orders WHERE id = ?");
            $stmt_db->execute([$order_id]);
            $db_id = $stmt_db->fetchColumn();

            // Notify Partner
            sendKhataPush($pdo, (int)$db_id, 'delivery', "Handover Code (SCC): $h_code", "Settle cash at $s_name. Use this code to verify handover.");

            $msg = "Handover code sent to delivery partner.";
        }
        elseif($action === 'verify_handover') {
            $entered_code = $data['code'] ?? '';
            $ledger_id = (int)($data['ledger_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT o.handover_code, l.net_payable_to_shop, l.delivery_boy_id, o.id as order_id FROM delivery_ledger l JOIN orders o ON l.order_id = o.id WHERE l.id = ? AND o.shop_id = ?");
            $stmt->execute([$ledger_id, $shop_id]);
            $handover_info = $stmt->fetch();

            if($handover_info && $handover_info['handover_code'] === $entered_code) {
                // Mark ledger entry as handed over
                $pdo->prepare("UPDATE delivery_ledger SET is_handed_over = 1 WHERE id = ?")->execute([$ledger_id]);

                // Notify Delivery Boy
                
                sendKhataPush($pdo, (int)$handover_info['delivery_boy_id'], 'delivery', "Cash Handover Success ✅", "Order #{$handover_info['order_id']} ka cash $s_name ne receive kar liya hai. Aapka hisab barabar ho gaya.");

                $msg = "Cash handover verified. Amount: ₹{$handover_info['net_payable_to_shop']}";
            } else { throw new Exception("Invalid Handover Code."); }
        }

        $pdo->commit();
        if($is_api) exit(json_encode(['success'=>true, 'message'=>$msg])); // API calls don't need redirect
        header("Location: manage_orders.php?success=" . urlencode($msg));
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        if($is_api) exit(json_encode(['success'=>false, 'message'=>$e->getMessage()])); // API calls don't need redirect
        $error = $e->getMessage();
    }
}

// ===== DATA FETCHING =====
// 1. New Orders (Pending acceptance)
$stmt_new_orders = $pdo->prepare("
    SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.full_address as customer_address
    FROM orders o JOIN customers c ON o.customer_id = c.id
    WHERE o.shop_id = ? AND o.order_status = 'pending'
    ORDER BY o.created_at ASC
    LIMIT 100 -- Add a limit for performance
");
$stmt_new_orders->execute([$shop_id]);
$new_orders = $stmt_new_orders->fetchAll();

// 1.5. Cancellation Requests
$stmt_cancel_reqs = $pdo->prepare("SELECT o.*, c.name as customer_name FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.shop_id = ? AND o.order_status = 'cancel_requested'");
$stmt_cancel_reqs->execute([$shop_id]);
$cancel_reqs = $stmt_cancel_reqs->fetchAll();

// 2. Orders Ready for Assignment (Accepted but not yet assigned)
$stmt_ready_orders = $pdo->prepare("
    SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.full_address as customer_address
    FROM orders o JOIN customers c ON o.customer_id = c.id
    WHERE o.shop_id = ? AND o.order_status = 'accepted' AND o.delivery_boy_id IS NULL
    ORDER BY o.created_at ASC
    LIMIT 100 -- Add a limit for performance
");
$stmt_ready_orders->execute([$shop_id]);
$ready_orders = $stmt_ready_orders->fetchAll();

// 3. Assigned Orders (Waiting for pickup or delivery)
$stmt_assigned_orders = $pdo->prepare("
    SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.full_address as customer_address,
           dp.name as delivery_boy_name, dp.phone as delivery_boy_phone
    FROM orders o JOIN customers c ON o.customer_id = c.id
    LEFT JOIN delivery_partners dp ON o.delivery_boy_id = dp.id
    WHERE o.shop_id = ? AND o.order_status IN ('assigned', 'picked_up')
    ORDER BY o.created_at ASC
    LIMIT 100 -- Add a limit for performance
");
$stmt_assigned_orders->execute([$shop_id]);
$assigned_orders = $stmt_assigned_orders->fetchAll();

// 4. Pending Cash Handovers
$stmt_handovers = $pdo->prepare("
    SELECT dl.*, o.id as order_id, o.handover_code, dp.name as delivery_boy_name, dp.phone as delivery_boy_phone
    FROM delivery_ledger dl JOIN orders o ON dl.order_id = o.id JOIN delivery_partners dp ON dl.delivery_boy_id = dp.id
    WHERE o.shop_id = ? AND dl.is_handed_over = 0
    ORDER BY dl.created_at ASC
    LIMIT 100 -- Add a limit for performance
");
$stmt_handovers->execute([$shop_id]);
$pending_handovers = $stmt_handovers->fetchAll();

// 5. Available Delivery Boys (in the same pincode as the shop)
$stmt_shop_pincode = $pdo->prepare("SELECT pincode FROM shop_owners WHERE id = ?");
$stmt_shop_pincode->execute([$shop_id]);
$shop_pincode = trim($stmt_shop_pincode->fetchColumn() ?? '');

$available_delivery_boys = [];
if($shop_pincode) {
    $stmt_db = $pdo->prepare("SELECT id, name, phone FROM delivery_partners WHERE pincode = ? AND is_active = 1 AND is_verified = 1");
    $stmt_db->execute([$shop_pincode]);
    $available_delivery_boys = $stmt_db->fetchAll();
    error_log("SHOP_MANAGE_ORDERS_DEBUG: Available Delivery Boys for Pincode $shop_pincode: " . count($available_delivery_boys));
}

// Fetch order items for each order
function fetchOrderItems(PDO $pdo, int $order_id): array {
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    return $stmt->fetchAll();
}

error_log("SHOP_MANAGE_ORDERS_DEBUG: Shop ID: $shop_id. New Orders: " . count($new_orders) . ", Ready Orders: " . count($ready_orders) . ", Assigned Orders: " . count($assigned_orders) . ", Pending Handovers: " . count($pending_handovers));
if($is_api) {
    $response_data = [
        'success' => true,
        'new_orders' => [],
        'ready_orders' => [],
        'assigned_orders' => [],
        'pending_handovers' => [],
        'available_delivery_boys' => $available_delivery_boys
    ];

    foreach($new_orders as $order) {
        $order['items'] = fetchOrderItems($pdo, $order['id']);
        $response_data['new_orders'][] = $order;
    }
    foreach($ready_orders as $order) {
        $order['items'] = fetchOrderItems($pdo, $order['id']);
        $response_data['ready_orders'][] = $order;
    }
    foreach($assigned_orders as $order) {
        $order['items'] = fetchOrderItems($pdo, $order['id']);
        $response_data['assigned_orders'][] = $order;
    }
    foreach($pending_handovers as $handover) {
        $response_data['pending_handovers'][] = $handover;
    }

    exit(json_encode($response_data));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders — KhataLink Shop</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="text-[10px] font-black text-indigo-600 uppercase tracking-widest bg-indigo-50 border border-indigo-100 px-3 py-1.5 rounded-full">
        <i class="fas fa-shopping-bag me-1"></i> Marketplace Orders
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/shop_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        <div class="mb-8">
            <h1 class="text-3xl font-black text-slate-900 tracking-tight">Manage Orders</h1>
            <p class="text-slate-500 text-sm">Accept, process, and track marketplace orders.</p>
        </div>

        <?php if(isset($error)): ?>
            <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-bold mb-6 flex items-center gap-3">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if(isset($msg) && $msg !== 'Success'): ?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-bold mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <!-- New Orders Section -->
        <div class="mb-10">
            <h2 class="text-sm font-black uppercase tracking-widest text-slate-400 mb-4 flex items-center gap-2">
                <span class="w-2 h-2 bg-red-500 rounded-full animate-ping"></span> New Orders (<?= count($new_orders) ?>)
            </h2>
            <div class="space-y-4">
                <?php if(empty($new_orders)): ?>
                    <div class="text-center py-10 bg-white border border-dashed border-slate-200 rounded-[2rem]">
                        <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No new orders</p>
                    </div>
                <?php endif; ?>
                <?php foreach($new_orders as $order): ?>
                    <div class="bg-white border-2 border-red-100 rounded-[2.5rem] p-6 shadow-lg shadow-red-50">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="font-black text-lg"><?= htmlspecialchars($order['customer_name']) ?></h3>
                                <p class="text-xs text-slate-500"><i class="fas fa-map-marker-alt mr-1"></i> <?= htmlspecialchars($order['delivery_apartment_house'] . ', ' . $order['delivery_village'] . ', ' . $order['delivery_block'] . ', ' . $order['delivery_district'] . ' - ' . $order['pincode']) ?></p>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] font-black text-red-600 uppercase">Order #<?= $order['id'] ?></div>
                                <div class="text-lg font-black">₹<?= number_format($order['total_amount'], 2) ?></div>
                            </div>
                        </div>
                        <div class="mb-4">
                            <h4 class="text-xs font-black text-slate-400 uppercase mb-2">Items:</h4>
                            <ul class="space-y-1">
                                <?php foreach(fetchOrderItems($pdo, $order['id']) as $item): ?>
                                    <li class="flex justify-between items-center bg-slate-50 p-2 rounded-lg">
                                        <span class="text-sm font-bold"><?= htmlspecialchars($item['item_name']) ?> (<?= $item['quantity'] ?> <?= $item['unit'] ?>)</span>
                                        <?php if($item['price_per_unit'] > 0): ?>
                                            <span class="text-sm font-black">₹<?= number_format($item['total_price'], 2) ?></span>
                                        <?php else: ?>
                                            <form method="POST" class="flex gap-2 items-center">
                                                <input type="hidden" name="action" value="set_item_price">
                                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                <input type="hidden" name="qty" value="<?= $item['quantity'] ?>">
                                                <input type="hidden" name="unit" value="<?= $item['unit'] ?>">
                                                <input type="number" name="price" placeholder="Set Price" class="w-24 bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-bold outline-none" step="0.01" required>
                                                <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded-lg text-[9px] font-black">Set</button>
                                            </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="action" value="accept_order">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <button type="submit" class="w-full bg-blue-600 text-white font-black py-3 rounded-2xl uppercase text-[10px] tracking-widest shadow-lg shadow-blue-100">Accept</button>
                            </form>
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="action" value="decline_order">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <button type="submit" class="w-full bg-slate-100 text-slate-600 font-black py-3 rounded-2xl uppercase text-[10px] tracking-widest">Decline</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Cancellation Requests Section -->
        <?php if($cancel_reqs): ?>
        <div class="mb-10">
            <h2 class="text-sm font-black uppercase tracking-widest text-red-400 mb-4">Cancellation Requests (<?= count($cancel_reqs) ?>)</h2>
            <div class="space-y-4">
                <?php foreach($cancel_reqs as $cr): ?>
                    <div class="bg-red-50 border-2 border-red-100 rounded-[2.5rem] p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-black text-slate-900"><?= htmlspecialchars($cr['customer_name']) ?> wants to cancel</h3>
                            <span class="text-xs font-bold text-red-600">Order #<?= $cr['id'] ?></span>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="action" value="approve_cancel">
                                <input type="hidden" name="order_id" value="<?= $cr['id'] ?>">
                                <button type="submit" class="w-full bg-red-600 text-white font-black py-3 rounded-2xl uppercase text-[10px] tracking-widest">Approve Cancel</button>
                            </form>
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="action" value="reject_cancel">
                                <input type="hidden" name="order_id" value="<?= $cr['id'] ?>">
                                <button type="submit" class="w-full bg-slate-900 text-white font-black py-3 rounded-2xl uppercase text-[10px] tracking-widest">Reject (Already Out)</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ready for Assignment Section -->
        <div class="mb-10">
            <h2 class="text-sm font-black uppercase tracking-widest text-slate-400 mb-4">Ready for Assignment (<?= count($ready_orders) ?>)</h2>
            <div class="space-y-4">
                <?php if(empty($ready_orders)): ?>
                    <div class="text-center py-10 bg-white border border-dashed border-slate-200 rounded-[2rem]">
                        <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No orders ready for assignment</p>
                    </div>
                <?php endif; ?>
                <?php foreach($ready_orders as $order): ?>
                    <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="font-black text-lg"><?= htmlspecialchars($order['customer_name']) ?></h3>
                                <p class="text-xs text-slate-500"><i class="fas fa-map-marker-alt mr-1"></i> <?= htmlspecialchars($order['delivery_apartment_house'] . ', ' . $order['delivery_village'] . ', ' . $order['delivery_block'] . ', ' . $order['delivery_district'] . ' - ' . $order['pincode']) ?></p>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] font-black text-blue-600 uppercase">Order #<?= $order['id'] ?></div>
                                <div class="text-lg font-black">₹<?= number_format($order['total_amount'], 2) ?></div>
                                <!-- Delete Option -->
                                <form method="POST" class="mt-2" onsubmit="return confirm('Are you sure? This will PERMANENTLY delete the order from database.');">
                                    <input type="hidden" name="action" value="delete_order_permanently">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="text-slate-300 hover:text-red-500 transition-all">
                                        <i class="fas fa-trash-alt text-xs"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="mb-4">
                            <h4 class="text-xs font-black text-slate-400 uppercase mb-2">Items:</h4>
                            <ul class="space-y-1">
                                <?php foreach(fetchOrderItems($pdo, $order['id']) as $item): ?>
                                    <li class="flex justify-between items-center bg-slate-50 p-2 rounded-lg">
                                        <span class="text-sm font-bold"><?= htmlspecialchars($item['item_name']) ?> (<?= $item['quantity'] ?> <?= $item['unit'] ?>)</span>
                                        <span class="text-sm font-black">₹<?= number_format($item['total_price'], 2) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <form method="POST" class="flex gap-2">
                            <input type="hidden" name="action" value="assign_delivery_boy">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <select name="delivery_boy_id" class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-xs font-bold outline-none focus:border-blue-500" required>
                                <option value="">Select Delivery Boy</option>
                                <?php foreach($available_delivery_boys as $db): ?>
                                    <option value="<?= $db['id'] ?>"><?= htmlspecialchars($db['name']) ?> (<?= htmlspecialchars($db['phone']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase">Assign</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Assigned Orders Section -->
        <div class="mb-10">
            <h2 class="text-sm font-black uppercase tracking-widest text-slate-400 mb-4">Assigned Orders (<?= count($assigned_orders) ?>)</h2>
            <div class="space-y-4">
                <?php if(empty($assigned_orders)): ?>
                    <div class="text-center py-10 bg-white border border-dashed border-slate-200 rounded-[2rem]">
                        <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No assigned orders</p>
                    </div>
                <?php endif; ?>
                <?php foreach($assigned_orders as $order): ?>
                    <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="font-black text-lg"><?= htmlspecialchars($order['customer_name']) ?></h3>
                                <p class="text-xs text-slate-500"><i class="fas fa-map-marker-alt mr-1"></i> <?= htmlspecialchars($order['delivery_apartment_house'] . ', ' . $order['delivery_village'] . ', ' . $order['delivery_block'] . ', ' . $order['delivery_district'] . ' - ' . $order['pincode']) ?></p>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] font-black text-blue-600 uppercase">Order #<?= $order['id'] ?></div>
                                <div class="text-lg font-black">₹<?= number_format($order['total_amount'], 2) ?></div>
                            </div>
                        </div>
                        <div class="mb-4">
                            <h4 class="text-xs font-black text-slate-400 uppercase mb-2">Items:</h4>
                            <ul class="space-y-1">
                                <?php foreach(fetchOrderItems($pdo, $order['id']) as $item): ?>
                                    <li class="flex justify-between items-center bg-slate-50 p-2 rounded-lg">
                                        <span class="text-sm font-bold"><?= htmlspecialchars($item['item_name']) ?> (<?= $item['quantity'] ?> <?= $item['unit'] ?>)</span>
                                        <span class="text-sm font-black">₹<?= number_format($item['total_price'], 2) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="flex justify-between items-center bg-blue-50 p-3 rounded-xl border border-blue-100">
                            <div class="text-xs font-bold text-blue-700">Assigned to: <?= htmlspecialchars($order['delivery_boy_name']) ?></div>
                            <div class="text-xs font-bold text-blue-700">Pickup Code: <span class="font-black"><?= $order['pickup_code'] ?></span></div>
                        </div>
                        <?php if($order['order_status'] === 'picked_up'): ?>
                            <div class="mt-2 text-xs font-bold text-emerald-600"><i class="fas fa-check-circle mr-1"></i> Order Picked Up by Delivery Boy.</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pending Cash Handovers Section -->
        <div class="mb-10">
            <h2 class="text-sm font-black uppercase tracking-widest text-slate-400 mb-4">Pending Cash Handovers (<?= count($pending_handovers) ?>)</h2>
            <div class="space-y-4">
                <?php if(empty($pending_handovers)): ?>
                    <div class="text-center py-10 bg-white border border-dashed border-slate-200 rounded-[2rem]">
                        <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No pending cash handovers</p>
                    </div>
                <?php endif; ?>
                <?php foreach($pending_handovers as $handover): ?>
                    <div class="bg-white border-2 border-emerald-100 rounded-[2.5rem] p-6 shadow-lg shadow-emerald-50">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="font-black text-lg">From <?= htmlspecialchars($handover['delivery_boy_name']) ?></h3>
                                <p class="text-xs text-slate-500"><i class="fas fa-phone mr-1"></i> <?= htmlspecialchars($handover['delivery_boy_phone']) ?></p>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] font-black text-emerald-600 uppercase">Order #<?= $handover['order_id'] ?></div>
                                <div class="text-lg font-black">₹<?= number_format($handover['net_payable_to_shop'], 2) ?></div>
                            </div>
                        </div>
                        <div class="flex gap-2 items-center">
                        <?php if(!$handover['handover_code']): ?>
                            <form method="POST" class="w-full">
                                <input type="hidden" name="action" value="send_handover_code">
                                <input type="hidden" name="order_id" value="<?= $handover['order_id'] ?>">
                                <button type="submit" class="w-full bg-slate-900 text-white font-black py-3 rounded-2xl uppercase text-[10px] tracking-widest shadow-lg">Send Handover Code</button>
                            </form>
                        <?php else: ?>
                        <form method="POST" class="flex gap-2">
                            <input type="hidden" name="action" value="verify_handover">
                            <input type="hidden" name="ledger_id" value="<?= $handover['id'] ?>">
                            <input type="text" name="code" placeholder="Enter Handover Code" class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-xs font-bold outline-none focus:border-blue-500" required>
                            <button type="submit" class="bg-emerald-600 text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase">Verify</button>
                        </form>
                        <?php endif; ?>
                        </div>
                        <p class="text-[8px] text-slate-400 mt-2 italic">* Ask delivery boy for the Handover Code (SCC) shown on his dashboard.</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </main>
</div>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }
</script>

<!-- Firebase Professional Notifications -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>
<script>
    const firebaseConfig = {
        apiKey: "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
        authDomain: "khatalink-63041.firebaseapp.com",
        projectId: "khatalink-63041",
        messagingSenderId: "905429197043",
        appId: "1:905429197043:web:2a0cbefa0fa176fd2c5786"
    };
    if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
    const messaging = firebase.messaging();
    async function syncToken() {
        try {
            const registration = await navigator.serviceWorker.register('../firebase-messaging-sw.js');
            await navigator.serviceWorker.ready;
            await messaging.getToken({ 
                vapidKey: 'BGixP4kke2vi5l1mpqb_P-GI5xh2OM4KcPQ_8lzQmJqvdJXHG4xFpkYvexfpD_lX7LvBQ1ORR3asE1LQkFeWFHo',
                serviceWorkerRegistration: registration
            });
        } catch (e) { console.error("FCM Sync Error:", e); }
    }
    syncToken();
    messaging.onMessage((payload) => {
        const title = payload.notification?.title || 'Marketplace Order';
        const body = payload.notification?.body || 'New status update';
        const image = payload.notification?.image;
        if (Notification.permission === "granted") {
            const options = {
                body: body,
                icon: '../assets/favicon.png'
            };
            if (image) {
                options.image = image;
            }
            const n = new Notification(title, options);
            n.onclick = function() { window.focus(); this.close(); };
        }
    });
</script>

</body>
</html>