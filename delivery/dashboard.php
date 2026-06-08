<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');
if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
require_once '../includes/notification_service.php';

// ===== AUTHENTICATION =====
$delivery_id = 0;
$is_api = false;
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $parts = explode(':', base64_decode($token));
    $delivery_id = (int)($parts[0] ?? 0);
    if(($parts[2] ?? '') !== 'delivery') $delivery_id = 0;
} else {
    $delivery_id = $_SESSION['delivery_id'] ?? 0;
}
if(!$delivery_id) {
    if($is_api) exit(json_encode(['success'=>false,'message'=>'Unauthorized']));
    header("Location: ../auth/login.php?type=delivery"); exit();
}

$page_error = '';
$page_msg   = $_GET['msg'] ?? '';

// ===== POST ACTIONS =====
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data     = $is_api ? json_decode(file_get_contents('php://input'), true) : $_POST;
    $action   = $data['action'] ?? '';
    $order_id = (int)($data['order_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        if($action === 'toggle_status') {
            $current    = trim($data['current_status'] ?? 'offline');
            $new_status = ($current === 'online_idle') ? 'offline' : 'online_idle';
            $pdo->prepare("UPDATE delivery_partners SET status = ? WHERE id = ?")->execute([$new_status, $delivery_id]);
            $pdo->commit();
            if($is_api) exit(json_encode(['success'=>true,'new_status'=>$new_status]));
            header("Location: dashboard.php"); exit();
        }

        // Action: Request Handover OTP from Shop
        if($action === 'request_handover_code') {
            $h_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE orders SET handover_code = ?, code_updated_at = NOW() WHERE id = ?")->execute([$h_code, $order_id]);
            $pdo->commit();
            if($is_api) exit(json_encode(['success'=>true, 'message'=>'Code requested!']));
            header("Location: dashboard.php?msg=Verification+Requested"); exit();
        }

        // Action: Verify Cash Handover via OTP
        if($action === 'verify_handover') {
            $entered = trim($data['handover_code'] ?? '');
            $stmt = $pdo->prepare("SELECT handover_code FROM orders WHERE id = ? AND delivery_boy_id = ?");
            $stmt->execute([$order_id, $delivery_id]);
            $real = $stmt->fetchColumn();

            if($real === $entered) {
                $pdo->prepare("UPDATE delivery_ledger SET is_handed_over = 1 WHERE order_id = ?")->execute([$order_id]);
                $pdo->prepare("UPDATE orders SET handover_code = 'VERIFIED' WHERE id = ?")->execute([$order_id]);
                $pdo->commit();
                if($is_api) exit(json_encode(['success'=>true, 'message'=>'Settlement Verified!']));
                header("Location: dashboard.php?msg=Cash+Handover+Verified"); exit();
            } else {
                throw new Exception("Invalid Code. Shopkeeper ke screen par dekho.");
            }
        }

        // BUG FIX: verify_pickup handler was completely missing
        if($action === 'verify_pickup') {
            $code = trim($data['code'] ?? '');
            $stmt = $pdo->prepare("SELECT pickup_code FROM orders WHERE id = ? AND delivery_boy_id = ?");
            $stmt->execute([$order_id, $delivery_id]);
            $real = $stmt->fetchColumn();
            if(!$real || $code !== $real) throw new Exception("Invalid Pickup Code. Shopkeeper se code maango.");
            $pdo->prepare("UPDATE orders SET order_status = 'picked_up' WHERE id = ?")->execute([$order_id]);
            $pdo->commit();
            if($is_api) exit(json_encode(['success'=>true,'message'=>'Pickup verified!']));
            header("Location: dashboard.php?msg=Pickup+Verified+%E2%9C%85"); exit();
        }

        // BUG FIX: verify_delivery handler was completely missing
        if($action === 'verify_delivery') {
            $entered = trim($data['code'] ?? '');
            $stmt    = $pdo->prepare("SELECT delivery_code, total_amount, delivery_fee, net_to_shop, customer_id, shop_id FROM orders WHERE id = ? AND delivery_boy_id = ?");
            $stmt->execute([$order_id, $delivery_id]);
            $oi = $stmt->fetch();
            if(!$oi)                          throw new Exception("Order nahi mila.");
            if($oi['delivery_code'] !== $entered) throw new Exception("Invalid DCC Code. Customer se maango.");

            $pdo->prepare("UPDATE orders SET order_status = 'delivered', delivered_at = NOW() WHERE id = ?")->execute([$order_id]);
            $pdo->prepare("INSERT INTO delivery_ledger (delivery_boy_id, order_id, cash_collected, commission_earned, net_payable_to_shop) VALUES (?,?,?,?,?)")
                ->execute([$delivery_id, $order_id, $oi['total_amount'], $oi['delivery_fee'], $oi['net_to_shop']]);
            $pdo->prepare("UPDATE delivery_partners SET status = 'online_idle' WHERE id = ?")->execute([$delivery_id]);

            $c_name = $pdo->prepare("SELECT name      FROM customers   WHERE id = ?"); $c_name->execute([(int)$oi['customer_id']]); $c_name = $c_name->fetchColumn() ?: 'Customer';
            $s_name = $pdo->prepare("SELECT shop_name FROM shop_owners  WHERE id = ?"); $s_name->execute([(int)$oi['shop_id']]);    $s_name = $s_name->fetchColumn() ?: 'Shop';

            sendKhataPush($pdo, (int)$oi['customer_id'], 'customer', "Order Delivered! 🎉", "Aapka order #$order_id $s_name se deliver ho chuka hai.");
            sendKhataPush($pdo, (int)$oi['shop_id'],     'shop',     "Order Delivered ✅",  "Customer $c_name ko Order #$order_id mil gaya hai.");

            $pdo->commit();
            if($is_api) exit(json_encode(['success'=>true,'message'=>'Delivered! Collect ₹'.number_format($oi['total_amount'],2)]));
            header("Location: dashboard.php?msg=Order+Delivered+%F0%9F%8E%89"); exit();
        }

        $pdo->commit();
    } catch(Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        if($is_api) exit(json_encode(['success'=>false,'message'=>$e->getMessage()]));
        $page_error = $e->getMessage();
    }
}

// ===== DATA FETCHING =====

$stmt_rider = $pdo->prepare("SELECT name, phone, email, status FROM delivery_partners WHERE id = ?");
$stmt_rider->execute([$delivery_id]);
$rider        = $stmt_rider->fetch(PDO::FETCH_ASSOC);
$rider_status = $rider['status'] ?? 'offline';

// BUG FIX: removed non-existent 'cash_in_hand' from SQL; using correct keys only
$stats = $pdo->prepare("
    SELECT
        COALESCE(SUM(commission_earned), 0) AS total_earned,
        COALESCE(SUM(CASE WHEN is_handed_over = 0 THEN net_payable_to_shop ELSE 0 END), 0) AS cash_pending_shop,
        COALESCE(SUM(CASE WHEN is_handed_over = 1 THEN net_payable_to_shop ELSE 0 END), 0) AS cash_completed_shop,
        -- Strictly check platform fee status, NOT handover status
        COALESCE(SUM(CASE WHEN is_platform_fee_paid = 0 THEN platform_fee_amount ELSE 0 END), 0) as platform_due,
        (SELECT COUNT(*) FROM orders WHERE delivery_boy_id = ? AND order_status IN ('assigned','picked_up')) AS active_tasks
    FROM delivery_ledger WHERE delivery_boy_id = ?
");
$stats->execute([$delivery_id, $delivery_id]);
$st = $stats->fetch(PDO::FETCH_ASSOC);

$stmt_active = $pdo->prepare("
    SELECT o.*, s.shop_name, s.full_address AS shop_address,
           c.name AS cust_name, c.full_address AS cust_address, c.phone AS cust_phone
    FROM orders o
    JOIN shop_owners s ON o.shop_id     = s.id
    JOIN customers   c ON o.customer_id = c.id
    WHERE o.delivery_boy_id = ? AND o.order_status IN ('assigned','picked_up')
    ORDER BY o.created_at DESC
");
$stmt_active->execute([$delivery_id]);
$active_deliveries = $stmt_active->fetchAll(PDO::FETCH_ASSOC);

$stmt_ledger = $pdo->prepare("
    SELECT l.*, s.shop_name, o.handover_code
    FROM delivery_ledger l
    JOIN orders      o ON l.order_id = o.id
    JOIN shop_owners s ON o.shop_id  = s.id
    WHERE l.delivery_boy_id = ? AND l.is_handed_over = 0
");
$stmt_ledger->execute([$delivery_id]);
$ledger_items = $stmt_ledger->fetchAll(PDO::FETCH_ASSOC);

// BUG FIX: $new_requests was undefined — tasks moved to Groceries_tasks.php
$new_requests = [];

if($is_api) exit(json_encode([
    'success'           => true,
    'stats'             => $st,
    'new_requests'      => $new_requests,
    'active_deliveries' => $active_deliveries,
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Dashboard — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,700;0,9..40,800&display=swap" rel="stylesheet">
    <style>* { font-family: 'DM Sans', sans-serif; }</style>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen pb-24">

<!-- NAV -->
<nav class="sticky top-0 z-50 bg-white border-b border-slate-200 h-14 flex items-center justify-between px-4 md:px-8">
    <div class="flex items-center gap-3">
        <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-7">
        <span class="text-[9px] font-black bg-slate-900 text-white px-2 py-0.5 rounded-md tracking-widest uppercase">#<?= $delivery_id ?></span>
    </div>
    <div class="flex items-center gap-3">
        <form method="POST">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="current_status" value="<?= $rider_status ?>">
            <button type="submit" class="flex items-center gap-2 px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-wider border transition-all
                <?= $rider_status==='online_idle' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200' ?>">
                <span class="w-1.5 h-1.5 rounded-full <?= $rider_status==='online_idle' ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400' ?>"></span>
                <?= $rider_status==='online_idle' ? 'Online' : 'Offline' ?>
            </button>
        </form>
        <a href="../auth/logout.php" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-red-500 transition-colors">
            <i class="fas fa-sign-out-alt text-sm"></i>
        </a>
    </div>
</nav>

<main class="max-w-2xl mx-auto p-4 md:p-6 space-y-5">

    <!-- Flash messages -->
    <?php if($page_error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm font-bold flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($page_error) ?>
    </div>
    <?php endif; ?>
    <?php if($page_msg): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl p-4 text-sm font-bold flex items-center gap-2">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($page_msg) ?>
    </div>
    <?php endif; ?>

    <!-- ══ STATS GRID — BUG FIX: opening <div class="grid"> was completely missing ══ -->
    <div class="grid grid-cols-2 gap-3">

        <!-- Total Earnings (Dist Fee + Handling Fee) -->
        <div class="col-span-2 bg-emerald-600 rounded-3xl p-5 text-white">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-emerald-200 mb-1">Total My Earnings</p>
                    <p class="text-3xl font-black">₹<?= number_format($st['total_earned'] ?? 0, 2) ?></p>
                    <p class="text-[10px] text-emerald-300 font-bold mt-1">
                        <?= $st['active_tasks'] ?? 0 ?> active task<?= ($st['active_tasks']??0)!=1?'s':'' ?>
                    </p>
                </div>
                <div class="flex flex-col gap-2">
                    <!-- My Earnings Button — was missing entirely -->
                    <a href="earnings.php" class="bg-white text-emerald-700 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wide hover:bg-emerald-50 transition-all flex items-center gap-2">
                        <i class="fas fa-wallet"></i> My Earnings
                    </a>
                    <a href="Groceries_tasks.php" class="bg-emerald-700 border border-emerald-500 text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wide hover:bg-emerald-800 transition-all flex items-center gap-2">
                        <i class="fas fa-box"></i> View Tasks
                    </a>
                </div>
            </div>
        </div>

        <!-- Cash Pending to Shop -->
        <div class="bg-white border border-slate-200 rounded-3xl p-5">
            <p class="text-[9px] font-black uppercase text-slate-400 tracking-wider mb-1">Cash to Shop</p>
            <p class="text-xl font-black text-slate-900">₹<?= number_format($st['cash_pending_shop'] ?? 0, 2) ?></p>
            <p class="text-[9px] text-slate-400 mt-0.5">Pending handover</p>
        </div>

        <!-- Cash Handed Over -->
        <div class="bg-white border border-slate-200 rounded-3xl p-5">
            <p class="text-[9px] font-black uppercase text-slate-400 tracking-wider mb-1">Handed Over</p>
            <p class="text-xl font-black text-slate-400">₹<?= number_format($st['cash_completed_shop'] ?? 0, 2) ?></p>
            <p class="text-[9px] text-slate-300 mt-0.5">Completed</p>
        </div>

        <!-- Pay to Platform — BUG FIX: UPI pay button added -->
        <div class="col-span-2 bg-slate-900 rounded-3xl p-5">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-[9px] font-black uppercase text-amber-400 tracking-wider mb-1">Pay to Platform (UPI)</p>
                    <p class="text-2xl font-black text-white">₹<?= number_format($st['platform_due'] ?? 0, 2) ?></p>
                    <p class="text-[9px] text-slate-500 mt-0.5">Outstanding amount</p>
                </div>
                <?php if(($st['platform_due'] ?? 0) > 0): ?>
                <button onclick="startPlatformPayment(this)"
                   class="flex-shrink-0 bg-amber-400 text-slate-900 px-6 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-amber-400/20 active:scale-95 transition-all flex items-center gap-2">
                    <i class="fas fa-credit-card"></i> Pay Now
                </button>
                <?php else: ?>
                <div class="flex-shrink-0 bg-emerald-900 text-emerald-400 px-5 py-3 rounded-2xl text-[10px] font-black uppercase tracking-wide flex items-center gap-2">
                    <i class="fas fa-check"></i> Clear
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /stats grid -->

    <!-- ══ ACTIVE DELIVERIES ══ -->
    <div>
        <h2 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-3">On-Going Deliveries</h2>

        <?php if(empty($active_deliveries)): ?>
        <div class="bg-white border border-dashed border-slate-200 rounded-3xl p-10 text-center">
            <i class="fas fa-motorcycle text-slate-200 text-3xl mb-3"></i>
            <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No active deliveries</p>
        </div>
        <?php endif; ?>

        <div class="space-y-4">
        <?php foreach($active_deliveries as $ad):
            $is_assigned = ($ad['order_status'] === 'assigned');
            $is_picked   = ($ad['order_status'] === 'picked_up');
        ?>
        <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-sm">

            <div class="flex items-center justify-between px-5 pt-4 pb-3 border-b border-slate-50">
                <span class="text-[9px] font-black uppercase tracking-widest px-3 py-1 rounded-full border
                    <?= $is_assigned ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-blue-50 text-blue-700 border-blue-200' ?>">
                    <?= $is_assigned ? '📦 Ready to Pickup' : '🛵 Out for Delivery' ?>
                </span>
                <span class="text-[10px] font-black text-slate-400">#ORD-<?= $ad['id'] ?></span>
            </div>

            <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- Step 1: Pickup -->
                <div class="<?= $is_picked ? 'opacity-30' : '' ?> bg-slate-50 rounded-2xl p-4">
                    <p class="text-[9px] font-black text-amber-600 uppercase tracking-widest mb-2">
                        <i class="fas fa-store me-1"></i> Step 1 — Pickup
                    </p>
                    <p class="font-black text-slate-900 text-sm"><?= htmlspecialchars($ad['shop_name']) ?></p>
                    <p class="text-[11px] text-slate-500 mb-3 leading-tight"><?= htmlspecialchars($ad['shop_address']) ?></p>

                    <?php if($is_assigned): ?>
                    <form method="POST" class="flex gap-2">
                        <input type="hidden" name="action"   value="verify_pickup">
                        <input type="hidden" name="order_id" value="<?= $ad['id'] ?>">
                        <input type="text"   name="code" placeholder="Pickup Code" maxlength="6" required
                               class="flex-1 min-w-0 bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-black tracking-widest outline-none focus:border-amber-400">
                        <button class="bg-slate-900 text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase hover:bg-slate-700 transition-all">Verify</button>
                    </form>
                    <p class="text-[9px] text-slate-400 mt-2 italic">Shopkeeper ke dashboard se code lo.</p>
                    <?php else: ?>
                    <p class="text-xs font-black text-emerald-600"><i class="fas fa-check-circle me-1"></i>Picked up</p>
                    <?php endif; ?>
                </div>

                <!-- Step 2: Deliver -->
                <div class="<?= $is_assigned ? 'opacity-25 pointer-events-none select-none' : '' ?> bg-slate-50 rounded-2xl p-4">
                    <p class="text-[9px] font-black text-blue-600 uppercase tracking-widest mb-2">
                        <i class="fas fa-house me-1"></i> Step 2 — Deliver
                    </p>
                    <div class="flex items-start justify-between mb-2">
                        <div>
                            <p class="font-black text-slate-900 text-sm"><?= htmlspecialchars($ad['cust_name']) ?></p>
                            <p class="text-[11px] text-slate-500 leading-tight"><?= htmlspecialchars($ad['cust_address']) ?></p>
                        </div>
                        <a href="tel:<?= $ad['cust_phone'] ?>"
                           class="w-8 h-8 flex-shrink-0 bg-blue-600 text-white rounded-full flex items-center justify-center text-xs ml-2">
                            <i class="fas fa-phone-alt"></i>
                        </a>
                    </div>

                    <?php if($is_picked): ?>
                    <form method="POST" class="flex gap-2 mt-3">
                        <input type="hidden" name="action"   value="verify_delivery">
                        <input type="hidden" name="order_id" value="<?= $ad['id'] ?>">
                        <input type="text"   name="code" placeholder="DCC Code" maxlength="6" required
                               class="flex-1 min-w-0 bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-black tracking-widest outline-none focus:border-blue-400">
                        <button class="bg-blue-600 text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase hover:bg-blue-700 transition-all">Done</button>
                    </form>
                    <p class="text-[9px] text-slate-400 mt-2 italic">Customer se DCC code maango.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bottom bar -->
            <div class="border-t border-slate-50 bg-slate-50/60 px-5 py-3 flex items-center justify-between">
                <div class="flex gap-5">
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase">Collect</p>
                        <p class="text-sm font-black">₹<?= number_format($ad['total_amount'], 2) ?></p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase">Your Share</p>
                        <p class="text-sm font-black text-emerald-600">₹<?= number_format($ad['delivery_fee'], 2) ?></p>
                    </div>
                </div>
                <?php if($is_picked): ?>
                <a href="https://www.google.com/maps/dir/?api=1&destination=<?= urlencode($ad['cust_address']) ?>"
                   target="_blank"
                   class="bg-blue-600 text-white text-[9px] font-black uppercase px-3 py-2 rounded-xl flex items-center gap-1.5 hover:bg-blue-700 transition-all">
                    <i class="fas fa-location-arrow"></i> Navigate
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ PENDING CASH HANDOVERS ══ -->
    <?php if(!empty($ledger_items)): ?>
    <div>
        <h2 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-3">Pending Cash Handovers</h2>
        <div class="space-y-3">
        <?php foreach($ledger_items as $li): ?>
        <div class="bg-white border border-emerald-100 rounded-3xl p-5 flex items-center justify-between gap-4">
            <div>
                <p class="font-black text-slate-900 text-sm"><?= htmlspecialchars($li['shop_name']) ?></p>
                <p class="text-xs text-emerald-700 font-bold mt-0.5">Hand over: ₹<?= number_format($li['net_payable_to_shop'], 2) ?></p>
                <p class="text-[9px] text-slate-400 mt-0.5">Your commission ₹<?= number_format($li['commission_earned'], 2) ?> deducted</p>
            </div>
            <div class="text-center flex-shrink-0">
                <?php if(empty($li['handover_code'])): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="request_handover_code">
                    <input type="hidden" name="order_id" value="<?= $li['order_id'] ?>">
                    <button class="bg-blue-600 text-white px-3 py-2.5 rounded-xl text-[9px] font-black uppercase tracking-widest shadow-lg shadow-blue-100">Send Verification</button>
                </form>
                <?php else: ?>
                <form method="POST" class="flex flex-col gap-1.5">
                    <input type="hidden" name="action" value="verify_handover">
                    <input type="hidden" name="order_id" value="<?= $li['order_id'] ?>">
                    <input type="text" name="handover_code" placeholder="Enter OTP" maxlength="6" required 
                           class="w-20 bg-slate-50 border border-slate-200 rounded-lg py-1.5 text-xs font-black text-center outline-none focus:border-emerald-500">
                    <button class="bg-emerald-600 text-white px-2 py-1.5 rounded-lg text-[8px] font-black uppercase">Verify</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

<!-- BOTTOM NAV -->
<div class="fixed bottom-0 left-0 w-full bg-white border-t border-slate-200 px-4 py-2 flex justify-around items-center lg:hidden z-50">
    <a href="dashboard.php"      class="flex flex-col items-center gap-0.5 text-blue-600">
        <i class="fas fa-home text-lg"></i><span class="text-[8px] font-black uppercase tracking-wide">Home</span>
    </a>
    <a href="Groceries_tasks.php" class="flex flex-col items-center gap-0.5 text-slate-400 relative">
        <i class="fas fa-box text-lg"></i><span class="text-[8px] font-black uppercase tracking-wide">Tasks</span>
    </a>
    <a href="earnings.php"        class="flex flex-col items-center gap-0.5 text-slate-400">
        <i class="fas fa-wallet text-lg"></i><span class="text-[8px] font-black uppercase tracking-wide">Earnings</span>
    </a>
    <a href="order_history.php"   class="flex flex-col items-center gap-0.5 text-slate-400">
        <i class="fas fa-history text-lg"></i><span class="text-[8px] font-black uppercase tracking-wide">History</span>
    </a>
    <a href="profile.php"         class="flex flex-col items-center gap-0.5 text-slate-400">
        <i class="fas fa-user text-lg"></i><span class="text-[8px] font-black uppercase tracking-wide">Profile</span>
    </a>
</div>

<script>
if("geolocation" in navigator && "<?= $rider_status ?>" === "online_idle") {
    function syncGPS() {
        navigator.geolocation.getCurrentPosition(pos => {
            fetch('ajax_update_location.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-Requested-With':'FlutterApp'},
                body: JSON.stringify({latitude: pos.coords.latitude, longitude: pos.coords.longitude})
            });
        }, null, {enableHighAccuracy: true});
    }
    syncGPS();
    setInterval(syncGPS, 30000);
}

// ── REAL-TIME SETTLEMENT POLLING ──
setInterval(async () => {
    const forms = document.querySelectorAll('.text-center.flex-shrink-0 form');
    for(let form of forms) {
        const orderId = form.querySelector('[name="order_id"]')?.value;
        const action = form.querySelector('[name="action"]')?.value;
        if(!orderId) continue;

        try {
            const res = await fetch(`../shop/ajax_check_pickup_code.php?order_id=${orderId}`);
            const data = await res.json();
            // Agar rider 'Request' button pe hai aur shopkeeper ne code generate kar diya hai
            if(data.success && action === 'request_handover_code' && data.handover && data.handover !== 'VERIFIED') {
                location.reload();
            }
            // Agar settlement complete ho gayi hai
            if(data.success && data.handover === 'VERIFIED') {
                location.reload();
            }
        } catch(e){}
    }
}, 5000);

</script>

<!-- Cashfree SDK v3 & Payment Logic -->
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const cashfree = Cashfree({ mode: "<?= (CF_MODE === 'PROD') ? 'production' : 'sandbox' ?>" });

async function startPlatformPayment(btn) {
    const amountToPay = <?= (float)($st['platform_due'] ?? 0) ?>;
    if (amountToPay <= 0) return;

    if (!btn.hasAttribute('data-original')) {
        btn.setAttribute('data-original', btn.innerHTML);
    }
    const originalHtml = btn.getAttribute('data-original');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';

    const formData = new URLSearchParams();
    formData.append('action', 'pay_platform_fee');
    formData.append('amount', amountToPay);
    formData.append('delivery_boy_id', <?= $delivery_id ?>);
    formData.append('rider_name', <?= json_encode($rider['name'] ?? 'Rider') ?>);
    formData.append('rider_phone', <?= json_encode($rider['phone'] ?? '9999999999') ?>);
    formData.append('rider_email', <?= json_encode($rider['email'] ?? 'support@khatalink.com') ?>);

    try {
        const res = await fetch('../customer/cashfree_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            cashfree.checkout({ paymentSessionId: data.payment_session_id, redirectTarget: "_self" });
        } else {
            Swal.fire('Payment Failed', data.message || 'Error creating session.', 'error');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (e) {
        console.error(e);
        Swal.fire('Error', 'Connection failed. Please retry.', 'error');
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

/* Auto-refresh dashboard for new tasks */
setInterval(() => {
    if(!document.hidden) location.reload();
}, 45000);
</script>
</body>
</html>