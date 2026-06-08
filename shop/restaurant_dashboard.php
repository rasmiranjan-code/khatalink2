<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// FIX #6: ob_start pehle — ob_clean() baad mein safely call ho sake
ob_start();

session_start();
require_once '../includes/db.php';
require_once '../includes/Groceries_inventory_engine.php';
require_once '../includes/Groceries_assignment_engine.php';
require_once '../includes/notification_service.php';

// ── AUTHENTICATION LAYER ──
$shop_id = 0;
$is_app  = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp'
);

if ($is_app) {
    $token = get_auth_token();
    $parts = verify_secure_token($token);
    $shop_id = (int)($parts[0] ?? 0);
    if (!$shop_id || ($parts[2] ?? '') !== 'shop') {
        ob_end_clean();
        exit(json_encode(['success' => false, 'message' => 'Unauthorized Access']));
    }
} else {
    if (!isset($_SESSION['shop_id'])) {
        header("Location: ../auth/login.php?type=restaurant");
        exit();
    }
    $shop_id = (int)$_SESSION['shop_id'];
}

// FIX #10: Allowed statuses whitelist — koi bhi status inject nahi kar sakta
$ALLOWED_STATUSES = ['accepted', 'packing', 'ready_for_pickup'];

// ── ORDER ACTIONS (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $req_data = $is_app
        ? json_decode(file_get_contents('php://input'), true)
        : $_POST;

    $action   = $req_data['action']   ?? '';
    $order_id = (int)($req_data['order_id'] ?? 0);

    // FIX #5: toggle_kitchen pehle check karo — order actions se alag
    if (!$is_app && isset($_POST['toggle_kitchen'])) {
        $stmt_k = $pdo->prepare("SELECT is_online FROM shop_owners WHERE id = ?");
        $stmt_k->execute([$shop_id]);
        $cur_online = (int)$stmt_k->fetchColumn();
        $new_status = $cur_online ? 0 : 1;
        $pdo->prepare("UPDATE shop_owners SET is_online = ? WHERE id = ?")
            ->execute([$new_status, $shop_id]);
        header("Location: restaurant_dashboard.php");
        exit();
    }

    if ($order_id > 0) {
        // Verify shop owns this order — cust_id bhi yahan lo (Bug #3 fix)
        $stmt_check = $pdo->prepare(
            "SELECT customer_id, order_status FROM orders WHERE id = ? AND shop_id = ?"
        );
        $stmt_check->execute([$order_id, $shop_id]);
        $order_meta = $stmt_check->fetch();

        if ($order_meta) {
            $cust_id = (int)$order_meta['customer_id'];

            // ── ACTION: CANCEL ORDER ──
            if ($action === 'cancel_order') {
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ?")
                        ->execute([$order_id]);

                    // FIX #9: Stock release on cancel — groceries items wapas inventory mein
                    $stmt_it = $pdo->prepare(
                        "SELECT product_id, quantity FROM order_items WHERE order_id = ?"
                    );
                    $stmt_it->execute([$order_id]);
                    foreach ($stmt_it->fetchAll() as $item) {
                        if (!empty($item['product_id'])) {
                            groceries_release_stock(
                                $pdo,
                                (int)$item['product_id'],
                                (float)$item['quantity']
                            );
                        }
                    }

                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("RESTAURANT_CANCEL_ERROR: " . $e->getMessage());
                    if ($is_app) {
                        ob_end_clean();
                        exit(json_encode(['success' => false, 'message' => $e->getMessage()]));
                    }
                    header("Location: restaurant_dashboard.php?err=Cancel+Failed");
                    exit();
                }

                $msg = "Hum maafi chahte hain! Kitchen abhi bahut busy hai aur aapka order taiyar nahi kar payega. Aapka refund process kar diya gaya hai.";
                sendKhataPush($pdo, $cust_id, 'customer', "Order Cancelled ❌", $msg, null, [
                    'type' => 'order_cancelled',
                    'id'   => (string)$order_id
                ]);

                if ($is_app) {
                    ob_end_clean();
                    exit(json_encode(['success' => true, 'message' => 'Order rejected']));
                }
                header("Location: restaurant_dashboard.php?msg=Order+Rejected");
                exit();
            }

            // ── ACTION: UPDATE ORDER STATUS ──
            if ($action === 'update_order_status') {
                $new_status = trim($req_data['status'] ?? '');

                // FIX #10: Whitelist check — unknown status block karo
                if (!in_array($new_status, $ALLOWED_STATUSES, true)) {
                    if ($is_app) {
                        ob_end_clean();
                        exit(json_encode(['success' => false, 'message' => 'Invalid status']));
                    }
                    header("Location: restaurant_dashboard.php?err=Invalid+Status");
                    exit();
                }

                try {
                    // FIX #2: Sab kuch ek transaction mein
                    $pdo->beginTransaction();

                    if ($new_status === 'ready_for_pickup') {
                        $pdo->prepare(
                            "UPDATE orders SET order_status = 'ready_for_pickup' WHERE id = ? AND shop_id = ?"
                        )->execute([$order_id, $shop_id]);

                        // FIX #1: Radius POST se nahi lena — engine apna radius logic use kare
                        // groceries_assign_best_rider() internaly apni search radius use karta hai
                        $assigned = groceries_assign_best_rider($pdo, $order_id);

                        if (!$assigned) {
                            $pdo->prepare(
                                "UPDATE orders SET order_status = 'rider_not_found' WHERE id = ?"
                            )->execute([$order_id]);
                        }

                        // FIX #3: $cust_id upar se aa raha hai — alag query nahi chahiye
                        sendKhataPush(
                            $pdo, $cust_id, 'customer',
                            "Order Ready! 📦",
                            "Aapka order pack ho chuka hai. Rider jald hi pickup karega.",
                            null,
                            ['type' => 'order_update', 'id' => (string)$order_id]
                        );

                    } else {
                        $pdo->prepare(
                            "UPDATE orders SET order_status = ? WHERE id = ? AND shop_id = ?"
                        )->execute([$new_status, $order_id, $shop_id]);

                        $status_config = [
                            'accepted' => [
                                't' => 'Order Accepted! 👨‍🍳',
                                'm' => "Kitchen ne aapka order confirm kar liya hai."
                            ],
                            'packing' => [
                                't' => 'Food is Preparing! 🍜',
                                'm' => "Chef aapka khana taiyar kar rahe hain."
                            ],
                        ];

                        if (isset($status_config[$new_status])) {
                            sendKhataPush(
                                $pdo, $cust_id, 'customer',
                                $status_config[$new_status]['t'],
                                $status_config[$new_status]['m'],
                                null,
                                ['type' => 'order_update', 'id' => (string)$order_id]
                            );
                        }
                    }

                    $pdo->commit();

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("RESTAURANT_STATUS_ERROR: " . $e->getMessage());
                    if ($is_app) {
                        ob_end_clean();
                        exit(json_encode(['success' => false, 'message' => $e->getMessage()]));
                    }
                    header("Location: restaurant_dashboard.php?err=Update+Failed");
                    exit();
                }

                if ($is_app) {
                    ob_end_clean();
                    exit(json_encode([
                        'success' => true,
                        'message' => 'Status updated to ' . $new_status
                    ]));
                }
                header("Location: restaurant_dashboard.php?msg=Status+Updated");
                exit();
            }

        } else {
            if ($is_app) {
                ob_end_clean();
                exit(json_encode(['success' => false, 'message' => 'Unauthorized access to order']));
            }
        }
    }
}

// ── FETCH KITCHEN INFO ──
$stmt = $pdo->prepare(
    "SELECT * FROM shop_owners WHERE id = ? AND shop_type = 'restaurant'"
);
$stmt->execute([$shop_id]);
$kitchen = $stmt->fetch();

if (!$kitchen) {
    header("Location: dashboard.php");
    exit();
}

// ── STATS ──
$orders_today = $pdo->prepare(
    "SELECT COUNT(*) FROM orders WHERE shop_id = ? AND DATE(created_at) = CURDATE()"
);
$orders_today->execute([$shop_id]);
$today_count = (int)$orders_today->fetchColumn();

$live_orders = $pdo->prepare(
    "SELECT COUNT(*) FROM orders
     WHERE shop_id = ? AND order_status NOT IN ('delivered', 'cancelled')"
);
$live_orders->execute([$shop_id]);
$live_count = (int)$live_orders->fetchColumn();

$delivered_today = $pdo->prepare(
    "SELECT COUNT(*) FROM orders
     WHERE shop_id = ? AND order_status = 'delivered' AND DATE(created_at) = CURDATE()"
);
$delivered_today->execute([$shop_id]);
$delivered_count = (int)$delivered_today->fetchColumn();

$revenue_today = $pdo->prepare(
    "SELECT COALESCE(SUM(net_to_shop), 0) FROM orders
     WHERE shop_id = ? AND DATE(created_at) = CURDATE() AND order_status != 'cancelled'"
);
$revenue_today->execute([$shop_id]);
$today_rev = (float)$revenue_today->fetchColumn();

$stmt_perf = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN order_status NOT IN ('pending','cancelled') THEN 1 ELSE 0 END) AS accepted
    FROM orders WHERE shop_id = ?
");
$stmt_perf->execute([$shop_id]);
$perf = $stmt_perf->fetch();
$acceptance_rate = ($perf['total'] > 0)
    ? round(($perf['accepted'] / $perf['total']) * 100)
    : 0;

// ── FLUTTER API RESPONSE ──
if ($is_app) {
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'kitchen' => $kitchen,
        'stats'   => [
            'live_orders'     => $live_count,
            'today_count'     => $today_count,
            'today_rev'       => $today_rev,
            'acceptance_rate' => $acceptance_rate,
        ],
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Dashboard — <?= htmlspecialchars($kitchen['shop_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .stat-card { border-radius: 1.5rem; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-5px); }
        .order-card { border-radius: 1.25rem; border-left: 6px solid #10b981; }
        .order-card.pending { border-left-color: #f59e0b; }
    </style>
</head>
<body class="text-slate-900">

<!-- Sticky Nav -->
<nav class="sticky top-0 z-50 bg-white border-b border-slate-200 px-6 py-4 flex items-center shadow-sm">
    <div class="flex items-center gap-3">
        <?php if (!empty($kitchen['profile_image'])): ?>
            <img src="../assets/img/profiles/<?= htmlspecialchars($kitchen['profile_image']) ?>"
                 class="w-10 h-10 rounded-xl object-cover shadow-md border border-slate-100">
        <?php else: ?>
            <div class="w-10 h-10 bg-emerald-600 text-white rounded-xl flex items-center justify-center text-lg shadow-lg">
                <i class="fas fa-utensils"></i>
            </div>
        <?php endif; ?>
        <div>
            <h1 class="text-sm font-black uppercase tracking-tight leading-none">
                <?= htmlspecialchars($kitchen['shop_name']) ?>
            </h1>
            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">Cloud Kitchen Panel</span>
        </div>
    </div>

    <div class="absolute left-1/2 -translate-x-1/2">
        <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png"
             alt="KhataLink" class="h-8 md:h-10">
    </div>

    <!-- FIX #5: toggle_kitchen ka apna dedicated form -->
    <form method="POST" class="flex items-center gap-2 ml-auto">
        <span class="text-[10px] font-black uppercase <?= $kitchen['is_online'] ? 'text-emerald-600' : 'text-slate-400' ?>">
            <span class="hidden sm:inline">
                <?= $kitchen['is_online'] ? 'Taking Orders' : 'Kitchen Closed' ?>
            </span>
        </span>
        <button type="submit" name="toggle_kitchen" value="1"
                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors
                       <?= $kitchen['is_online'] ? 'bg-emerald-600' : 'bg-slate-300' ?>">
            <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform
                         <?= $kitchen['is_online'] ? 'translate-x-6' : 'translate-x-1' ?>"></span>
        </button>
    </form>
</nav>

<?php if (isset($_GET['msg'])): ?>
<div class="max-w-6xl mx-auto px-4 pt-4">
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-3 text-sm font-bold flex items-center gap-2">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['msg']) ?>
    </div>
</div>
<?php endif; ?>
<?php if (isset($_GET['err'])): ?>
<div class="max-w-6xl mx-auto px-4 pt-4">
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-3 text-sm font-bold flex items-center gap-2">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_GET['err']) ?>
    </div>
</div>
<?php endif; ?>

<main class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

    <!-- Stats Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white p-6 stat-card shadow-sm border border-slate-100">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Live Orders</div>
            <div class="text-3xl font-black text-orange-500"><?= $live_count ?></div>
            <div class="mt-2 flex items-center text-[10px] font-bold text-emerald-600 bg-emerald-50 w-fit px-2 py-0.5 rounded">
                <i class="fas fa-arrow-up mr-1"></i> Active Now
            </div>
        </div>
        <div class="bg-white p-6 stat-card shadow-sm border border-slate-100">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Today's Sales</div>
            <div class="text-3xl font-black text-slate-900">₹<?= number_format($today_rev, 0) ?></div>
            <div class="text-[10px] font-bold text-slate-400 mt-2 italic">Net Payout to you</div>
        </div>
        <div class="bg-white p-6 stat-card shadow-sm border border-slate-100">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Completed</div>
            <div class="text-3xl font-black text-slate-900"><?= $today_count ?></div>
            <div class="text-[10px] font-bold text-slate-400 mt-2 uppercase">Total orders today</div>
        </div>
        <div class="bg-slate-900 p-6 stat-card shadow-xl text-white">
            <div class="text-[10px] font-black text-emerald-400 uppercase tracking-widest mb-1">Rating</div>
            <div class="text-3xl font-black">
                <?= number_format($kitchen['average_rating'], 1) ?>
                <i class="fas fa-star text-sm text-amber-400"></i>
            </div>
            <div class="text-[10px] font-bold text-slate-500 mt-2 uppercase">
                <?= $kitchen['total_ratings_count'] ?> reviews
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Live Orders List -->
        <div class="lg:col-span-2 space-y-4">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em]">Incoming Orders</h2>
                <button onclick="location.reload()"
                        class="text-blue-600 text-xs font-bold hover:underline">
                    <i class="fas fa-sync-alt mr-1"></i> Refresh
                </button>
            </div>

            <?php
            $stmt_orders = $pdo->prepare("
                SELECT o.*, c.name AS customer_name, c.phone AS customer_phone
                FROM orders o
                JOIN customers c ON o.customer_id = c.id
                WHERE o.shop_id = ? AND o.order_status != 'cancelled'
                ORDER BY (o.order_status = 'delivered') ASC, o.created_at DESC
            ");
            $stmt_orders->execute([$shop_id]);
            $orders = $stmt_orders->fetchAll();

            if (empty($orders)): ?>
                <div class="bg-white rounded-[2rem] p-16 text-center border border-dashed border-slate-300">
                    <div class="w-20 h-20 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center text-4xl mx-auto mb-4">
                        <i class="fas fa-concierge-bell"></i>
                    </div>
                    <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">
                        No active orders right now
                    </p>
                </div>
            <?php endif; ?>

            <?php foreach ($orders as $o):
                $is_pending   = ($o['order_status'] === 'pending');
                $is_delivered = ($o['order_status'] === 'delivered');
            ?>
            <div class="bg-white order-card shadow-sm p-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4
                        <?= $is_pending   ? 'pending'                            : '' ?>
                        <?= $is_delivered ? 'opacity-60 border-l-slate-300'      : '' ?>">

                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-black text-slate-900">#ORD-<?= $o['id'] ?></span>
                        <span class="text-[9px] font-black px-2 py-0.5 rounded uppercase
                            <?= $is_pending   ? 'bg-amber-50 text-amber-600'  :
                               ($is_delivered ? 'bg-slate-100 text-slate-500' :
                                                'bg-emerald-50 text-emerald-600') ?>">
                            <?= htmlspecialchars($o['order_status']) ?>
                        </span>
                    </div>
                    <p class="text-xs font-bold text-slate-500 uppercase">
                        <?= htmlspecialchars($o['customer_name']) ?> · <?= date('h:i A', strtotime($o['created_at'])) ?>
                    </p>

                    <!-- Items -->
                    <div class="pt-3 flex flex-wrap gap-2">
                        <?php
                        $stmt_it = $pdo->prepare(
                            "SELECT item_name, quantity FROM order_items WHERE order_id = ?"
                        );
                        $stmt_it->execute([$o['id']]);
                        while ($it = $stmt_it->fetch()): ?>
                            <span class="bg-slate-50 border border-slate-100 px-3 py-1 rounded-lg text-[10px] font-black text-slate-600">
                                <?= (float)$it['quantity'] ?> × <?= htmlspecialchars($it['item_name']) ?>
                            </span>
                        <?php endwhile; ?>
                    </div>

                    <!-- FIX #4: OTP boxes — clean single if/endif blocks, no duplicates -->
                    <div class="flex gap-4 mt-4" id="otp-container-<?= $o['id'] ?>">

                        <?php if (
                            !empty($o['pickup_code']) &&
                            in_array($o['order_status'], ['ready_for_pickup', 'assigned', 'finding_rider'], true)
                        ): ?>
                            <div class="bg-blue-50 border border-blue-100 rounded-xl px-3 py-2"
                                 id="pickup-box-<?= $o['id'] ?>">
                                <p class="text-[7px] font-black text-blue-400 uppercase tracking-widest">Pickup Code</p>
                                <p class="text-xs font-black text-blue-700 tracking-widest"><?= $o['pickup_code'] ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (
                            !empty($o['handover_code']) &&
                            $o['handover_code'] !== 'VERIFIED' &&
                            $o['order_status'] === 'delivered'
                        ): ?>
                            <div class="bg-emerald-50 border border-emerald-100 rounded-xl px-3 py-2"
                                 id="handover-box-<?= $o['id'] ?>">
                                <p class="text-[7px] font-black text-emerald-400 uppercase tracking-widest">
                                    Cash Settlement Code
                                </p>
                                <p class="text-xs font-black text-emerald-700 tracking-widest">
                                    <?= $o['handover_code'] ?>
                                </p>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-2 w-full md:w-auto">
                    <a href="restaurant_order_details.php?order_id=<?= $o['id'] ?>"
                       class="flex-1 text-center bg-slate-100 text-slate-600 py-3 px-6 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-slate-200 transition-all">
                        View List
                    </a>

                    <?php if (!$is_delivered): ?>
                    <form method="POST" class="flex-1 flex gap-2" id="form-<?= $o['id'] ?>">
                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">

                        <?php
                        // FIX #7: $radius_val dead variable hata diya — sirf $next_st/$btn_txt/$btn_clr
                        $next_st  = '';
                        $btn_txt  = '';
                        $btn_clr  = '';

                        if ($o['order_status'] === 'pending') {
                            $next_st = 'accepted';
                            $btn_txt = 'Accept';
                            $btn_clr = 'bg-blue-600';
                        } elseif ($o['order_status'] === 'accepted') {
                            $next_st = 'packing';
                            $btn_txt = 'Prepare';
                            $btn_clr = 'bg-orange-500';
                        } elseif (
                            $o['order_status'] === 'packing' ||
                            $o['order_status'] === 'rider_not_found'
                        ) {
                            $next_st = 'ready_for_pickup';
                            $btn_txt = ($o['order_status'] === 'rider_not_found') ? 'Retry Search' : 'Ready';
                            $btn_clr = ($o['order_status'] === 'rider_not_found') ? 'bg-red-600'   : 'bg-emerald-600';
                        }
                        ?>

                        <?php if ($next_st): ?>
                            <input type="hidden" name="action" value="update_order_status">
                            <input type="hidden" name="status" value="<?= $next_st ?>">
                            <button type="submit"
                                    class="w-full <?= $btn_clr ?> text-white py-3 px-6 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg hover:opacity-90 transition-all">
                                <?= $btn_txt ?>
                            </button>
                            <button type="submit" name="action" value="cancel_order"
                                    class="bg-red-50 text-red-500 px-4 py-3 rounded-xl hover:bg-red-500 hover:text-white transition-all"
                                    title="Reject Order">
                                <i class="fas fa-times"></i>
                            </button>
                        <?php else: ?>
                            <?php if ($o['order_status'] === 'ready_for_pickup' && empty($o['delivery_boy_id'])): ?>
                                <button disabled
                                        class="w-full bg-amber-50 text-amber-600 py-3 px-8 rounded-xl text-[10px] font-black uppercase tracking-widest cursor-not-allowed">
                                    <i class="fas fa-search me-1"></i> Finding Rider...
                                </button>
                            <?php elseif ($o['order_status'] === 'finding_rider'): ?>
                                <button disabled
                                        class="w-full bg-amber-100 text-amber-800 py-3 px-8 rounded-xl text-[10px] font-black uppercase tracking-widest cursor-not-allowed animate-pulse">
                                    <i class="fas fa-robot me-1"></i> Auto Matching...
                                </button>
                            <?php elseif ($o['order_status'] === 'ready_for_pickup' && !empty($o['delivery_boy_id'])): ?>
                                <button disabled
                                        class="w-full bg-blue-50 text-blue-600 py-3 px-8 rounded-xl text-[10px] font-black uppercase tracking-widest cursor-not-allowed">
                                    <i class="fas fa-clock me-1"></i> Waiting for Rider
                                </button>
                            <?php else: ?>
                                <button disabled
                                        class="w-full bg-slate-100 text-slate-400 py-3 px-8 rounded-xl text-[10px] font-black uppercase tracking-widest cursor-not-allowed">
                                    In Transit
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Right: Controls -->
        <div class="space-y-6">
            <div class="bg-white rounded-[2rem] p-8 shadow-sm border border-slate-100">
                <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-6">Kitchen Control</h3>
                <div class="grid grid-cols-1 gap-3">
                    <a href="restaurant_menu.php"
                       class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border-2 border-transparent hover:border-emerald-500 transition-all group">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center group-hover:bg-emerald-600 group-hover:text-white transition-all">
                                <i class="fas fa-book-open"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black text-slate-900 leading-none">Menu Book</p>
                                <p class="text-[9px] font-bold text-slate-400 uppercase mt-1">Manage Dishes & Prices</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 text-xs"></i>
                    </a>
                    <a href="restaurant_payouts.php"
                       class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border-2 border-transparent hover:border-blue-500 transition-all group">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center group-hover:bg-blue-600 group-hover:text-white transition-all">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black text-slate-900 leading-none">Earnings</p>
                                <p class="text-[9px] font-bold text-slate-400 uppercase mt-1">Settlements & Reports</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 text-xs"></i>
                    </a>
                    <a href="profile.php"
                       class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border-2 border-transparent hover:border-purple-500 transition-all group">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center group-hover:bg-purple-600 group-hover:text-white transition-all">
                                <i class="fas fa-cog"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black text-slate-900 leading-none">Settings</p>
                                <p class="text-[9px] font-bold text-slate-400 uppercase mt-1">Kitchen Open/Close Hours</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 text-xs"></i>
                    </a>
                </div>
            </div>

            <!-- Performance -->
            <div class="bg-indigo-600 rounded-[2rem] p-8 text-white shadow-xl shadow-indigo-100 relative overflow-hidden">
                <h3 class="text-[10px] font-black text-indigo-200 uppercase tracking-widest mb-4">Kitchen Performance</h3>
                <div class="space-y-4 relative z-10">
                    <div>
                        <div class="flex justify-between text-xs font-bold mb-2">
                            <span>Order Acceptance</span>
                            <span><?= $acceptance_rate ?>%</span>
                        </div>
                        <div class="w-full h-1.5 bg-white/20 rounded-full overflow-hidden">
                            <div class="h-full bg-white" style="width: <?= $acceptance_rate ?>%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs font-bold mb-2">
                            <span>Avg. Prep Time</span>
                            <span>--</span>
                        </div>
                        <div class="w-full h-1.5 bg-white/20 rounded-full overflow-hidden">
                            <div class="h-full bg-white w-[85%]"></div>
                        </div>
                    </div>
                </div>
                <i class="fas fa-bolt absolute -right-4 -bottom-4 text-8xl text-white/5 rotate-12"></i>
            </div>

            <a href="../auth/logout.php"
               class="flex items-center justify-center gap-2 w-full bg-white border border-red-100 text-red-600 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-red-600 hover:text-white transition-all shadow-sm">
                <i class="fas fa-power-off"></i> Exit Dashboard
            </a>
        </div>
    </div>
</main>

<footer class="text-center py-10 opacity-30">
    <p class="text-[10px] font-black uppercase tracking-[0.3em]">KhataLink Kitchen v1.0.0</p>
</footer>

<script>
// FIX #8: Reliable real-time polling — data-status attribute se compare karo, innerText nahi
setInterval(async () => {
    if (document.hidden) return;

    const forms = document.querySelectorAll('[id^="form-"]');
    const orderIds = Array.from(forms).map(f => f.id.split('-')[1]);

    for (const id of orderIds) {
        try {
            const res  = await fetch(`ajax_check_pickup_code.php?order_id=${id}`);
            const data = await res.json();
            if (!data.success) continue;

            // Status badge se current status nikalo
            const badge = document.querySelector(`#form-${id}`)
                          ?.closest('.order-card')
                          ?.querySelector('.text-\\[9px\\]');
            const currentStatus = badge?.textContent?.trim()?.toLowerCase() ?? '';

            // Agar status badal gaya — page reload (UI buttons update ke liye)
            if (data.status && data.status !== currentStatus) {
                location.reload();
                return;
            }

            // Pickup code live inject
            if (data.code && !document.getElementById(`pickup-box-${id}`)) {
                const container = document.getElementById(`otp-container-${id}`);
                if (container) {
                    container.insertAdjacentHTML('afterbegin', `
                        <div class="bg-blue-50 border border-blue-100 rounded-xl px-3 py-2" id="pickup-box-${id}">
                            <p class="text-[7px] font-black text-blue-400 uppercase tracking-widest">Pickup Code</p>
                            <p class="text-xs font-black text-blue-700 tracking-widest">${data.code}</p>
                        </div>`);
                }
            }

            // Handover code live inject
            if (data.handover && !document.getElementById(`handover-box-${id}`)) {
                const container = document.getElementById(`otp-container-${id}`);
                if (container) {
                    container.insertAdjacentHTML('beforeend', `
                        <div class="bg-emerald-50 border border-emerald-100 rounded-xl px-3 py-2" id="handover-box-${id}">
                            <p class="text-[7px] font-black text-emerald-400 uppercase tracking-widest">Cash Settlement Code</p>
                            <p class="text-xs font-black text-emerald-700 tracking-widest">${data.handover}</p>
                        </div>`);
                }
            }
        } catch (e) {
            // Network error — silently ignore
        }
    }
}, 5000);
</script>
</body>
</html>