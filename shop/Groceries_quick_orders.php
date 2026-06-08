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
require_once '../includes/Groceries_assignment_engine.php';
require_once '../includes/notification_service.php';

// ===== AUTHENTICATION LAYER =====
$shop_id = 0;
$is_api = false;
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0] ?? 0);
    $role = $parts[2] ?? '';
    if($role !== 'shop') $shop_id = 0;
} else {
    $shop_id = $_SESSION['shop_id'] ?? 0;
}

if(!$shop_id) {
    if($is_api) exit(json_encode(['success'=>false]));
    header("Location: ../auth/login.php?type=shop"); exit();
}

$order_id = (int)($_GET['order_id'] ?? 0);

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data     = $is_api ? json_decode(file_get_contents('php://input'), true) : $_POST;
    $action   = $data['action']          ?? '';
    $order_id = (int)($data['order_id'] ?? 0);
    $db_id    = (int)($data['delivery_boy_id'] ?? 0);
    $assigned = true;

    $s_name_stmt = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
    $s_name_stmt->execute([$shop_id]);
    $s_name = $s_name_stmt->fetchColumn() ?: 'Shop';

    try {
        $pdo->beginTransaction();

        if($action === 'accept_order') {
            $pdo->prepare("UPDATE orders SET order_status = 'accepted' WHERE id = ? AND shop_id = ?")
                ->execute([$order_id, $shop_id]);
            $cid = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $cid->execute([$order_id]);
            $cid = (int)$cid->fetchColumn();
            sendKhataPush($pdo, $cid, 'customer', "Order Accept Ho Gaya! ✅", "Aapka order #$order_id dukan ne accept kar liya hai. Packing shuru ho rahi hai.");

        } elseif($action === 'start_packing') {
            $pdo->prepare("UPDATE orders SET order_status = 'packing' WHERE id = ? AND shop_id = ?")
                ->execute([$order_id, $shop_id]);

        } elseif($action === 'assign_rider') {
            $pdo->prepare("UPDATE orders SET order_status = 'ready_for_pickup' WHERE id = ? AND shop_id = ?")
                ->execute([$order_id, $shop_id]);

            $assigned = groceries_assign_best_rider($pdo, $order_id);

            if(!$assigned) {
                $pdo->prepare("UPDATE orders SET order_status = 'rider_not_found' WHERE id = ?")
                    ->execute([$order_id]);
            }

            $cid = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $cid->execute([$order_id]);
            $cid = (int)$cid->fetchColumn();
            sendKhataPush($pdo, $cid, 'customer', "Order Pack Ho Gaya! 📦", "Aapka order pack ho chuka hai. Delivery partner jald aayega.");

        } elseif($action === 'manual_assign') {
            if($db_id > 0) {
                $pdo->prepare("DELETE FROM delivery_assignments WHERE order_id = ? AND assignment_status = 'pending'")
                    ->execute([$order_id]);
                $pdo->prepare("INSERT INTO delivery_assignments (order_id, delivery_boy_id, assignment_status) VALUES (?, ?, 'pending')")
                    ->execute([$order_id, $db_id]);
                $pdo->prepare("UPDATE orders SET order_status = 'assigned', delivery_boy_id = ? WHERE id = ?")
                    ->execute([$db_id, $order_id]);
                $s_name = $pdo->query("SELECT shop_name FROM shop_owners WHERE id = $shop_id")->fetchColumn();
                sendKhataPush($pdo, $db_id, 'delivery', "Naya Order! 🛵", "$s_name ne aapko order #$order_id assign kiya hai.");
            }

        } elseif($action === 'ready_for_pickup') {
            $pdo->prepare("UPDATE orders SET order_status = 'ready_for_pickup' WHERE id = ? AND shop_id = ?")
                ->execute([$order_id, $shop_id]);
            $assigned = groceries_assign_best_rider($pdo, $order_id);
            if(!$assigned) {
                $pdo->prepare("UPDATE orders SET order_status = 'rider_not_found' WHERE id = ?")
                    ->execute([$order_id]);
            }
            $cid = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $cid->execute([$order_id]);
            $cid = (int)$cid->fetchColumn();
            sendKhataPush($pdo, $cid, 'customer', "Order Ready! 📦", "Aapka order pack ho chuka hai. Rider jald hi pickup karega.");

        } elseif($action === 'approve_cancel') {
            $pdo->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ?")
                ->execute([$order_id]);
            $stmt_it = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $stmt_it->execute([$order_id]);
            foreach($stmt_it->fetchAll() as $item) {
                if($item['product_id']) groceries_release_stock($pdo, (int)$item['product_id'], (float)$item['quantity']);
            }
            $cid = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $cid->execute([$order_id]);
            $cid = (int)$cid->fetchColumn();
            sendKhataPush($pdo, $cid, 'customer', "Order Cancelled ✅", "Dukan ne aapka cancellation accept kar liya hai.");

        } elseif($action === 'reject_cancel') {
            $pdo->prepare("UPDATE orders SET order_status = 'accepted' WHERE id = ?")
                ->execute([$order_id]);
            $cid = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $cid->execute([$order_id]);
            $cid = (int)$cid->fetchColumn();
            sendKhataPush($pdo, $cid, 'customer', "Cancellation Rejected ❌", "Dukan ne cancellation mana kar di hai. Order process ho chuka hai.");

        } elseif($action === 'cancel_order') {
            $pdo->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ? AND shop_id = ?")
                ->execute([$order_id, $shop_id]);
            $stmt_it = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $stmt_it->execute([$order_id]);
            foreach($stmt_it->fetchAll() as $item) {
                if($item['product_id']) groceries_release_stock($pdo, (int)$item['product_id'], (float)$item['quantity']);
            }
            $cid = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $cid->execute([$order_id]);
            $customer_to_notify = (int)$cid->fetchColumn();
            sendKhataPush($pdo, $customer_to_notify, 'customer', "Order Cancelled ❌", "Dukan ne order cancel kar diya hai. Paisa jald hi wapas aayega.");
        }

        $pdo->commit();

        if(in_array($action, ['assign_rider', 'ready_for_pickup']) && !$assigned) {
            echo '<div style="padding:20px; background:#fef2f2; border-bottom:1px solid #fee2e2;">';
            echo '<a href="Groceries_quick_orders.php?order_id='.$order_id.'" style="display:inline-block; padding:10px 20px; background:#0f172a; color:white; border-radius:12px; font-size:12px; font-weight:900; text-decoration:none; text-transform:uppercase;">← Back to Order</a>';
            echo '</div>';
            exit();
        }

        if($is_api) exit(json_encode(['success'=>true]));
        header("Location: Groceries_quick_orders.php?order_id=$order_id&msg=Status+Updated");
        exit();

    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        error_log("QUICK_ORDERS_ERROR: " . $e->getMessage());
        if($is_api) exit(json_encode(['success'=>false, 'message'=>$e->getMessage()]));
        $page_error = $e->getMessage();
    }
}

// ===== DATA FETCHING =====
$stmt = $pdo->prepare("
    SELECT o.*,
           c.name  AS customer_name,
           c.phone AS customer_phone,
           c.full_address AS customer_address,
           s.average_rating, s.total_ratings_count,
           dp.name  AS rider_name,
           dp.phone AS rider_phone
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    JOIN shop_owners s ON o.shop_id = s.id
    LEFT JOIN delivery_partners dp ON o.delivery_boy_id = dp.id
    WHERE o.id = ? AND o.shop_id = ?
");
$stmt->execute([$order_id, $shop_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$order) die("Order not found");

$stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt_items->execute([$order_id]);
$items = $stmt_items->fetchAll();

$stmt_riders = $pdo->prepare("SELECT id, name, phone FROM delivery_partners WHERE pincode = ? AND is_active = 1 AND is_verified = 1");
$stmt_riders->execute([$order['pincode']]);
$available_riders = $stmt_riders->fetchAll();

$curr_status = $order['order_status'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fulfillment Center — Order #<?= $order_id ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        /* ✅ Finding Rider spinner */
        @keyframes spin { to { transform: rotate(360deg); } }
        .finding-spinner {
            width: 48px; height: 48px;
            border: 4px solid #fde68a;
            border-top-color: #d97706;
            border-radius: 50%;
            animation: spin 0.9s linear infinite;
            margin: 0 auto;
        }
        @keyframes pulse-text {
            0%, 100% { opacity: 1; } 50% { opacity: 0.4; }
        }
        .pulse-text { animation: pulse-text 1.5s ease-in-out infinite; }
    </style>
</head>
<body class="bg-emerald-50/30 text-slate-900 p-4 md:p-8">

<div class="max-w-2xl mx-auto">

    <!-- Header -->
    <div class="flex items-center justify-between mb-8">
        <a href="Groceries_dashboard.php" class="w-10 h-10 bg-white border border-emerald-100 rounded-xl flex items-center justify-center text-emerald-600 shadow-sm">
            <i class="fas fa-chevron-left"></i>
        </a>
        <div class="text-center">
            <h1 class="text-xl font-black text-slate-900 uppercase">Quick Fulfillment</h1>
            <p class="text-[10px] font-bold text-slate-400 tracking-[0.2em] uppercase">Order #<?= $order_id ?></p>
        </div>
        <div class="w-10"></div>
    </div>

    <?php if(isset($page_error)): ?>
    <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-bold mb-6 flex items-center gap-3">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($page_error) ?>
    </div>
    <?php endif; ?>

    <?php if(isset($_GET['msg'])): ?>
    <div class="bg-emerald-50 border border-emerald-100 text-emerald-700 rounded-2xl p-4 text-sm font-bold mb-6 flex items-center gap-3">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- ✅ Status Progress Bar — finding_rider bhi step 3 pe map kiya -->
    <?php
    $steps = [
        'pending'          => 0,
        'accepted'         => 1,
        'packing'          => 2,
        'ready_for_pickup' => 3,
        'finding_rider'    => 3, // ✅ naya
        'assigned'         => 3,
        'rider_not_found'  => 3,
        'picked_up'        => 4,
        'delivered'        => 5,
    ];
    $step   = $steps[$curr_status] ?? 0;
    $labels = ['Pending', 'Accepted', 'Packing', 'Rider', 'En Route', 'Delivered'];
    ?>
    <div class="bg-white border border-emerald-100 rounded-[2rem] p-5 shadow-sm mb-6">
        <div class="flex justify-between items-center relative">
            <div class="absolute top-3 left-0 right-0 h-0.5 bg-slate-100 z-0"></div>
            <div class="absolute top-3 left-0 h-0.5 bg-emerald-500 z-0 transition-all" style="width:<?= min(100, $step * 20) ?>%"></div>
            <?php foreach($labels as $i => $label): ?>
            <div class="flex flex-col items-center z-10">
                <div class="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-black <?= $i <= $step ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-400' ?>">
                    <?= $i < $step ? '✓' : ($i + 1) ?>
                </div>
                <span class="text-[8px] font-bold mt-1 <?= $i <= $step ? 'text-emerald-600' : 'text-slate-300' ?>"><?= $label ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main Card -->
    <div class="bg-white border border-emerald-100 rounded-[2.5rem] p-6 md:p-10 shadow-xl shadow-emerald-900/5 mb-8">

        <!-- Customer Box -->
        <div class="flex items-center justify-between mb-8 bg-emerald-50/50 p-4 rounded-3xl border border-emerald-50">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-white rounded-2xl flex items-center justify-center shadow-sm text-emerald-600">
                    <i class="fas fa-user"></i>
                </div>
                <div>
                    <p class="text-[8px] font-black text-emerald-600 uppercase tracking-widest">Receiver Details</p>
                    <h4 class="font-black text-slate-900 leading-tight"><?= htmlspecialchars($order['delivery_name'] ?: $order['customer_name']) ?></h4>
                    <div class="flex gap-2 text-[10px] font-bold text-slate-500 uppercase mt-1">
                        <?php if($order['average_rating'] > 0): ?>
                            <span class="text-amber-500"><i class="fas fa-star me-1"></i> <?= number_format($order['average_rating'], 1) ?></span>
                            <span class="w-1 h-1 rounded-full bg-slate-200 self-center"></span>
                        <?php endif; ?>
                        <span><i class="fas fa-phone-alt"></i> <?= htmlspecialchars($order['delivery_phone']) ?></span>
                        <?php if(!empty($order['delivery_phone_alt'])): ?>
                            <span><i class="fas fa-phone"></i> <?= htmlspecialchars($order['delivery_phone_alt']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-slate-600 font-bold text-[11px] leading-tight mt-2">
                        <?= htmlspecialchars($order['delivery_apartment_house']) ?>
                        <?php if($order['delivery_landmark']): ?>
                            <div class="text-emerald-600 font-black mt-0.5"><i class="fas fa-map-marker-alt mr-1 text-[9px]"></i> Landmark: <?= htmlspecialchars($order['delivery_landmark']) ?></div>
                        <?php endif; ?>
                        <div class="text-slate-400 text-[9px] uppercase tracking-tight mt-1 font-medium"><?= htmlspecialchars($order['delivery_village']) ?>, <?= htmlspecialchars($order['delivery_block']) ?>, <?= htmlspecialchars($order['delivery_district']) ?> - <span class="font-black"><?= $order['pincode'] ?></span></div>
                    </div>
                </div>
            </div>
            <a href="tel:<?= $order['customer_phone'] ?>" class="w-10 h-10 bg-emerald-600 text-white rounded-full flex items-center justify-center shadow-lg flex-shrink-0">
                <i class="fas fa-phone-alt text-xs"></i>
            </a>
        </div>

        <!-- Items List -->
        <div class="mb-10">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">
                Pack Items Below (<?= count($items) ?> items):
            </div>
            <div class="space-y-1">
                <?php foreach($items as $it): ?>
                <div class="flex justify-between items-center py-3 border-b border-slate-50 last:border-0">
                    <div class="flex items-center gap-3">
                        <div class="w-2 h-2 bg-emerald-400 rounded-full flex-shrink-0"></div>
                        <span class="text-sm font-bold text-slate-800"><?= htmlspecialchars($it['item_name']) ?></span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-black bg-slate-100 px-3 py-1 rounded-lg uppercase">
                            <?= (float)$it['quantity'] ?> <?= htmlspecialchars($it['unit']) ?>
                        </span>
                        <?php if($it['price_per_unit'] > 0): ?>
                        <span class="text-xs font-black text-emerald-600 w-16 text-right">₹<?= number_format($it['total_price'], 2) ?></span>
                        <?php else: ?>
                        <span class="text-[9px] font-bold text-amber-500 uppercase w-16 text-right">Price TBD</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 pt-4 border-t border-dashed border-slate-100 flex justify-between">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Net Items Value</span>
                <span class="text-base font-black text-slate-900">₹<?= number_format($order['net_to_shop'], 2) ?></span>
            </div>
        </div>

        <!-- ═══ ACTION AREA ═══ -->
        <div class="space-y-4">

            <?php if($curr_status === 'pending'): ?>
            <div class="text-center mb-2">
                <span class="inline-block bg-amber-50 text-amber-600 text-[9px] font-black uppercase tracking-widest px-4 py-1.5 rounded-full border border-amber-100">
                    New Order — Waiting for Acceptance
                </span>
            </div>
            <form method="POST">
                <input type="hidden" name="action"   value="accept_order">
                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                <button class="w-full bg-emerald-600 text-white py-5 rounded-3xl font-black uppercase text-xs tracking-widest shadow-xl shadow-emerald-200 hover:bg-emerald-700 active:scale-95 transition-all">
                    <i class="fas fa-check me-2"></i> Accept Order
                </button>
            </form>
            <form method="POST">
                <input type="hidden" name="action"   value="cancel_order">
                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                <button class="w-full bg-slate-100 text-slate-500 py-3 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-red-50 hover:text-red-500 transition-all">
                    Decline Order
                </button>
            </form>

            <?php elseif($curr_status === 'accepted'): ?>
            <div class="text-center mb-2">
                <span class="inline-block bg-indigo-50 text-indigo-600 text-[9px] font-black uppercase tracking-widest px-4 py-1.5 rounded-full border border-indigo-100">
                    Order Accepted — Start Packing
                </span>
            </div>
            <form method="POST">
                <input type="hidden" name="action"   value="start_packing">
                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                <button class="w-full bg-indigo-600 text-white py-5 rounded-3xl font-black uppercase text-xs tracking-widest shadow-xl hover:bg-indigo-700 active:scale-95 transition-all">
                    <i class="fas fa-box me-2"></i> Start Packing Items
                </button>
            </form>

            <?php elseif($curr_status === 'packing'): ?>
            <div class="text-center mb-2">
                <span class="inline-block bg-purple-50 text-purple-600 text-[9px] font-black uppercase tracking-widest px-4 py-1.5 rounded-full border border-purple-100">
                    Packing in Progress — Assign a Rider
                </span>
            </div>
            <div class="bg-white border-2 border-slate-100 rounded-[2rem] p-6 shadow-sm">
                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4 text-center">Delivery Assignment</h4>
                <form method="POST" class="mb-4">
                    <input type="hidden" name="action"   value="assign_rider">
                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                    <button class="w-full bg-emerald-700 text-white py-5 rounded-3xl font-black uppercase text-xs tracking-widest shadow-xl hover:bg-emerald-800 active:scale-95 transition-all">
                        <i class="fas fa-robot me-2"></i> Assign Nearest Rider Automatically
                    </button>
                </form>
                <?php if(!empty($available_riders)): ?>
                <div class="pt-4 border-t border-slate-50">
                    <p class="text-[9px] font-black text-slate-400 uppercase text-center mb-3">
                        Or choose manually from <?= count($available_riders) ?> local partner(s)
                    </p>
                    <form method="POST" class="flex gap-2">
                        <input type="hidden" name="action"   value="manual_assign">
                        <input type="hidden" name="order_id" value="<?= $order_id ?>">
                        <select name="delivery_boy_id" class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-bold outline-none focus:border-blue-500" required>
                            <option value="">Select Rider...</option>
                            <?php foreach($available_riders as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?> — <?= $r['phone'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="bg-slate-900 text-white px-4 py-2.5 rounded-xl text-[10px] font-black uppercase hover:bg-blue-600 transition-all">Assign</button>
                    </form>
                </div>
                <?php else: ?>
                <p class="text-[10px] text-center text-slate-400 font-bold pt-4 border-t border-slate-50">
                    No manual riders in pincode <?= htmlspecialchars($order['pincode']) ?>. Use auto-assign above.
                </p>
                <?php endif; ?>
            </div>

            <?php elseif($curr_status === 'finding_rider'): ?>
            <!-- ✅ NAYA BLOCK: Rider reject karne ke baad auto-search chal rahi hai -->
            <div class="text-center p-8 bg-amber-50 rounded-[2rem] border-2 border-dashed border-amber-200">
                <div class="finding-spinner mb-5"></div>
                <h4 class="font-black text-sm uppercase text-amber-800 pulse-text">Finding Next Rider...</h4>
                <p class="text-[10px] text-amber-600 font-bold mt-2">
                    Previous rider ne decline kiya. System automatically<br>
                    nearest available rider dhund raha hai.
                </p>
                <p class="text-[9px] text-amber-400 font-bold mt-4 uppercase tracking-widest">
                    Page auto-refresh ho raha hai...
                </p>
            </div>
            <!-- Manual override option bhi available -->
            <?php if(!empty($available_riders)): ?>
            <div class="bg-slate-50 border border-slate-100 rounded-2xl p-4 mt-2">
                <p class="text-[9px] font-black text-slate-400 uppercase text-center mb-3">
                    Auto search wait nahi karna? Manually assign karo
                </p>
                <form method="POST" class="flex gap-2">
                    <input type="hidden" name="action"   value="manual_assign">
                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                    <select name="delivery_boy_id" class="flex-1 bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-bold outline-none focus:border-blue-500" required>
                        <option value="">Select Rider...</option>
                        <?php foreach($available_riders as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?> — <?= $r['phone'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="bg-slate-900 text-white px-4 py-2.5 rounded-xl text-[10px] font-black uppercase">Force</button>
                </form>
            </div>
            <?php endif; ?>

            <?php elseif($curr_status === 'ready_for_pickup'): ?>
            <div class="text-center p-8 bg-blue-50 rounded-[2rem] border-2 border-dashed border-blue-200">
                <div class="w-14 h-14 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4 text-xl">
                    <i class="fas fa-clock"></i>
                </div>
                <?php if(!empty($order['rider_name'])): ?>
                    <h4 class="font-black text-sm uppercase text-blue-800">Rider Notified</h4>
                    <p class="text-[10px] text-blue-600 font-bold mt-1">Waiting for rider to accept the assignment...</p>
                <?php else: ?>
                    <h4 class="font-black text-sm uppercase text-blue-800">Searching for Rider</h4>
                    <p class="text-[10px] text-blue-600 font-bold mt-1">System is trying to find a nearby partner...</p>
                <?php endif; ?>
                
                <?php if(!empty($order['rider_name'])): ?>
                <div class="mt-4 pt-4 border-t border-blue-200 flex justify-between items-center">
                    <div class="text-left">
                        <p class="text-[8px] font-black uppercase text-blue-400">Assigned To</p>
                        <p class="text-sm font-black text-slate-900"><?= htmlspecialchars($order['rider_name']) ?></p>
                        <p class="text-[10px] text-slate-500"><?= htmlspecialchars($order['rider_phone']) ?></p>
                    </div>
                    <a href="tel:<?= $order['rider_phone'] ?>" class="w-10 h-10 bg-blue-600 text-white rounded-full flex items-center justify-center">
                        <i class="fas fa-phone-alt text-xs"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="action"   value="cancel_order">
                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                <button type="submit" class="w-full bg-red-50 text-red-600 py-3 rounded-2xl font-black uppercase text-[10px] tracking-widest border border-red-100 hover:bg-red-600 hover:text-white transition-all mb-4" onclick="return confirm('Rider respond nahi kar raha, kya order cancel karna hai?')">
                    Cancel Order
                </button>
            </form>

            <div class="bg-slate-50 border border-slate-100 rounded-2xl p-4">
                <p class="text-[9px] font-black text-slate-400 uppercase text-center mb-3">Rider not responding? Override manually</p>
                <?php if(!empty($available_riders)): ?>
                <form method="POST" class="flex gap-2">
                    <input type="hidden" name="action"   value="manual_assign">
                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                    <select name="delivery_boy_id" class="flex-1 bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-bold outline-none focus:border-blue-500" required>
                        <option value="">Select Rider...</option>
                        <?php foreach($available_riders as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?> — <?= $r['phone'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="bg-slate-900 text-white px-4 py-2.5 rounded-xl text-[10px] font-black uppercase">Force</button>
                </form>
                <?php else: ?>
                <p class="text-[10px] text-center text-slate-400 font-bold">No local partners available for this pincode.</p>
                <?php endif; ?>
            </div>

            <?php elseif($curr_status === 'rider_not_found'): ?>
            <div class="bg-amber-50 border-2 border-amber-200 rounded-[2rem] p-6 text-center mb-4">
                <i class="fas fa-exclamation-triangle text-amber-500 text-2xl mb-3"></i>
                <h4 class="font-black text-slate-900 uppercase text-sm">Delivery Boy Not Found</h4>
                <p class="text-[10px] text-amber-700 font-bold mt-1">Aapke area mein koi rider available nahi hai.</p>
            </div>

            <form method="POST" class="mb-4">
                <input type="hidden" name="action"   value="cancel_order">
                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                <button type="submit" class="w-full bg-red-600 text-white py-4 rounded-3xl font-black uppercase text-xs tracking-widest shadow-lg active:scale-95 transition-all" onclick="return confirm('Rider na hone ki wajah se order cancel karein?')">
                    <i class="fas fa-times-circle me-2"></i> Cancel Order (No Rider)
                </button>
            </form>

            <form method="POST" class="mb-2">
                <input type="hidden" name="action"   value="assign_rider">
                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                <button class="w-full bg-emerald-600 text-white py-4 rounded-3xl font-black uppercase text-xs tracking-widest hover:bg-emerald-700 active:scale-95 transition-all">
                    <i class="fas fa-redo me-2"></i> Retry Auto Assign
                </button>
            </form>
            <?php if(!empty($available_riders)): ?>
            <div class="bg-white border-2 border-slate-100 rounded-[2rem] p-6 shadow-sm">
                <p class="text-[9px] font-black text-slate-400 uppercase text-center mb-3">Assign Manually</p>
                <form method="POST" class="flex gap-2">
                    <input type="hidden" name="action"   value="manual_assign">
                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                    <select name="delivery_boy_id" class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-bold outline-none focus:border-blue-500" required>
                        <option value="">Select Rider...</option>
                        <?php foreach($available_riders as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?> — <?= $r['phone'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="bg-slate-900 text-white px-4 py-2.5 rounded-xl text-[10px] font-black uppercase hover:bg-blue-600 transition-all">Assign</button>
                </form>
            </div>
            <?php else: ?>
            <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-[10px] font-bold text-center">
                No delivery partners registered for pincode <?= htmlspecialchars($order['pincode']) ?>.<br>
                Please add a delivery partner for this area first.
            </div>
            <?php endif; ?>

            <!-- Option to Cancel if no rider can be found -->
            <form method="POST" class="mt-4 pt-4 border-t border-dashed border-slate-100">
                <input type="hidden" name="action"   value="cancel_order">
                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                <button type="submit" class="w-full bg-red-50 text-red-500 py-3 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-red-600 hover:text-white transition-all" onclick="return confirm('No rider found. Cancel this order?')">
                    Cancel Order Permanently
                </button>
            </form>

            <?php elseif($curr_status === 'assigned'): ?>
            <div class="text-center p-8 bg-blue-50 rounded-[2rem] border-2 border-dashed border-blue-200">
                <div class="w-14 h-14 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4 text-xl">
                    <i class="fas fa-user-check"></i>
                </div>
                <h4 class="font-black text-sm uppercase text-blue-800">Rider On The Way</h4>
                <p class="text-[10px] text-blue-600 font-bold mt-1">Partner is coming to your shop for pickup.</p>
                <?php if(!empty($order['rider_name'])): ?>
                <div class="mt-6 pt-6 border-t border-blue-200 flex items-center justify-between">
                    <div class="text-left">
                        <p class="text-[8px] font-black uppercase text-blue-400">Assigned Partner</p>
                        <p class="text-sm font-black text-slate-900"><?= htmlspecialchars($order['rider_name']) ?></p>
                        <p class="text-[10px] text-slate-500"><?= htmlspecialchars($order['rider_phone']) ?></p>
                    </div>
                    <a href="tel:<?= $order['rider_phone'] ?>" class="w-10 h-10 bg-blue-600 text-white rounded-full flex items-center justify-center">
                        <i class="fas fa-phone-alt text-xs"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif($curr_status === 'cancel_requested'): ?>
            <div class="bg-red-50 border-2 border-red-100 p-8 rounded-[2.5rem] text-center">
                <i class="fas fa-exclamation-triangle text-red-600 text-3xl mb-4"></i>
                <h4 class="font-black text-slate-900 uppercase mb-2">Cancel Request</h4>
                <p class="text-[10px] text-slate-500 font-bold mb-6">Customer ne order cancel karne ki request ki hai.</p>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                    <button name="action" value="approve_cancel" class="w-full bg-red-600 text-white py-4 rounded-2xl font-black uppercase text-[10px] hover:bg-red-700 transition-all">
                        <i class="fas fa-check me-1"></i> Approve — Stop Order & Refund
                    </button>
                    <button name="action" value="reject_cancel" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-black uppercase text-[10px] hover:bg-slate-700 transition-all">
                        <i class="fas fa-times me-1"></i> Reject — Continue Processing
                    </button>
                </form>
            </div>

            <?php elseif($curr_status === 'picked_up'): ?>
            <div class="bg-indigo-600 text-white p-8 rounded-3xl text-center shadow-xl">
                <i class="fas fa-truck-fast text-3xl mb-3"></i>
                <h4 class="font-black uppercase text-sm tracking-widest">Out for Delivery</h4>
                <p class="text-[10px] text-indigo-200 font-medium mt-1">Partner has collected the package.</p>
                <?php if(!empty($order['rider_name'])): ?>
                <div class="mt-6 pt-6 border-t border-indigo-500 flex items-center justify-between">
                    <div class="text-left">
                        <p class="text-[8px] font-black uppercase text-indigo-300">En Route Partner</p>
                        <p class="text-sm font-black"><?= htmlspecialchars($order['rider_name']) ?></p>
                    </div>
                    <a href="tel:<?= $order['rider_phone'] ?>" class="w-10 h-10 bg-white/20 text-white rounded-full flex items-center justify-center">
                        <i class="fas fa-phone-alt text-xs"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif($curr_status === 'delivered'): ?>
            <div class="bg-slate-900 text-white p-6 rounded-3xl shadow-xl">
                <div class="text-center mb-6">
                    <i class="fas fa-check-circle text-emerald-400 text-3xl mb-3"></i>
                    <h4 class="font-black uppercase text-sm tracking-widest">Delivered</h4>
                    <p class="text-[10px] text-slate-400 font-medium mt-1">Payment will be settled soon.</p>
                </div>
                <?php if($order['payment_mode'] === 'COD'): ?>
                    <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
                        <?php
                        $stmt_ledger = $pdo->prepare("SELECT is_handed_over FROM delivery_ledger WHERE order_id = ?");
                        $stmt_ledger->execute([$order_id]);
                        $is_handed = $stmt_ledger->fetchColumn();
                        if($is_handed): ?>
                            <div class="text-center text-emerald-400 font-black text-[10px] uppercase tracking-widest">
                                <i class="fas fa-hand-holding-dollar me-1"></i> Cash Handed Over
                            </div>
                        <?php elseif(empty($order['handover_code'])): ?>
                            <p class="text-[8px] font-black text-slate-400 uppercase mb-3 text-center">Collect ₹<?= number_format($order['net_to_shop'], 2) ?> from Rider</p>
                            <form method="POST">
                                <input type="hidden" name="action"   value="send_handover_code">
                                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                <button class="w-full bg-emerald-600 text-white py-3 rounded-xl font-black uppercase text-[10px] tracking-widest">Send Handover Code</button>
                            </form>
                        <?php else: ?>
                            <p class="text-[8px] font-black text-amber-400 uppercase mb-3 text-center">Enter Code from Rider's Phone</p>
                            <form method="POST" class="flex gap-2">
                                <input type="hidden" name="action"   value="verify_handover">
                                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                <input type="text" name="code" placeholder="Enter Code" class="flex-1 bg-white/10 border border-white/20 rounded-xl px-4 py-2 text-sm font-black tracking-widest outline-none text-white" required>
                                <button class="bg-white text-slate-900 px-4 py-2 rounded-xl text-[10px] font-black uppercase">Verify</button>
                            </form>
                            <div class="mt-4 text-center">
                                <p class="text-[10px] text-slate-400">Code Sent: <b class="text-white"><?= $order['handover_code'] ?></b></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="mt-4 bg-white/10 rounded-xl p-3">
                    <p class="text-[9px] font-black text-slate-300 uppercase">Your Sale Value</p>
                    <p class="text-xl font-black text-white">₹<?= number_format($order['net_to_shop'], 2) ?></p>
                </div>
            </div>

            <?php elseif($curr_status === 'cancelled'): ?>
            <div class="bg-red-50 border border-red-100 text-red-700 p-6 rounded-3xl text-center">
                <i class="fas fa-times-circle text-2xl mb-2"></i>
                <h4 class="font-black uppercase text-sm">Order Cancelled</h4>
                <p class="text-[10px] font-bold mt-1">Stock has been released back to inventory.</p>
            </div>

            <?php endif; ?>

        </div><!-- /action area -->
    </div><!-- /main card -->

    <div class="text-center">
        <p class="text-[10px] font-black text-slate-300 uppercase tracking-[0.3em]">KhataLink Groceries</p>
    </div>
</div>

<script>
// ✅ finding_rider bhi polling mein add kiya — page auto-reload hoga jab rider mile
<?php if(in_array($curr_status, ['ready_for_pickup', 'finding_rider', 'assigned', 'rider_not_found'])): ?>
    const pollInterval = setInterval(async () => {
        try {
            const res = await fetch('ajax_check_pickup_code.php?order_id=<?= $order_id ?>');
            const data = await res.json();

            // Status change ho gaya to reload
            if(data.status && data.status !== '<?= $curr_status ?>') {
                location.reload();
                return;
            }

            if(data.code !== null && data.code !== "") {
                const box = document.getElementById('pickupCodeBox');
                const el  = document.getElementById('displayCode');
                if(el)  el.innerText = data.code;
                if(box) box.classList.remove('hidden');
                clearInterval(pollInterval);
            }
        } catch(e) {}
    }, 4000);
<?php endif; ?>
</script>
</body>
</html>