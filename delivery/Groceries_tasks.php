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

// ===== AUTHENTICATION LAYER (App & Web) =====
$delivery_id = 0;
$is_api = false;
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $delivery_id = (int)($parts[0] ?? 0);
    $role = $parts[2] ?? '';
    if($role !== 'delivery') $delivery_id = 0;
} else {
    $delivery_id = $_SESSION['delivery_id'] ?? 0;
}

if(!$delivery_id) {
    if($is_api) exit(json_encode(['success'=>false, 'message'=>'Unauthorized']));
    header("Location: ../auth/login.php?type=delivery"); exit();
}

// ===== ACTION HANDLING (POST) =====
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $is_api ? json_decode(file_get_contents('php://input'), true) : $_POST;
    $action = $data['action'] ?? '';
    $order_id = (int)($data['order_id'] ?? 0);
    $msg = "";

    try {
        $pdo->beginTransaction();

        if($action === 'accept_task') {
            $pdo->prepare("UPDATE delivery_assignments SET assignment_status = 'accepted', responded_at = NOW() 
                           WHERE order_id = ? AND delivery_boy_id = ? AND assignment_status = 'pending'")
                ->execute([$order_id, $delivery_id]);
            $pdo->prepare("UPDATE orders SET order_status = 'assigned' WHERE id = ?")
                ->execute([$order_id]);
            $pdo->prepare("UPDATE delivery_partners SET status = 'online_busy' WHERE id = ?")
                ->execute([$delivery_id]);
            $msg = "Task accepted! Head to the shop for pickup.";

        } elseif($action === 'send_pickup_code') {
            $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE orders SET pickup_code = ?, code_updated_at = NOW() WHERE id = ?")
                ->execute([$code, $order_id]);
            $stmt_s = $pdo->prepare("SELECT shop_id FROM orders WHERE id = ?");
            $stmt_s->execute([$order_id]);
            $sid = (int)$stmt_s->fetchColumn();
            sendKhataPush($pdo, $sid, 'shop', "Rider is at Shop! 🛵", "Order #$order_id ke liye pickup code: $code. Ise rider ko batayein.");
            $msg = "Pickup code generated and sent to shop dashboard!";

        } elseif($action === 'reject_task') {

            // ── STEP 1: Is rider ki pending assignment reject karo ──
            $stmt_a = $pdo->prepare("
                UPDATE delivery_assignments 
                SET assignment_status = 'rejected', responded_at = NOW() 
                WHERE order_id = ? AND delivery_boy_id = ? AND assignment_status = 'pending'
            ");
            $stmt_a->execute([$order_id, $delivery_id]);

            // ── STEP 2: Order ko 'finding_rider' karo + delivery_boy_id NULL karo ──
            // Condition: sirf tab jab YEH rider assigned tha (race condition guard)
            $stmt_o = $pdo->prepare("
                UPDATE orders 
                SET order_status = 'finding_rider', delivery_boy_id = NULL 
                WHERE id = ? AND delivery_boy_id = ?
            ");
            $stmt_o->execute([$order_id, $delivery_id]);

            // ── STEP 3: Rider ko wapas idle karo ──
            $pdo->prepare("UPDATE delivery_partners SET status = 'online_idle' WHERE id = ?")
                ->execute([$delivery_id]);

            // ── STEP 4: Commit pehle karo — phir engine call karo ──
            // (engine apna khud ka transaction manage karta hai)
            $pdo->commit();

            // ── STEP 5: Naya rider dhundo ──
            // Engine andar se 'ready_for_pickup' ya 'rider_not_found' set karega
            // Hum KUCH NAHI override karenge uske baad
            groceries_assign_best_rider($pdo, $order_id);

            $msg = "Task rejected.";

            // ===== FORCE HALT TO PREVENT REDIRECTION LOOPS & CAPTURE RE-ASSIGNMENT VISUALS =====
            $chkAssignment = $pdo->prepare("SELECT delivery_boy_id, assignment_status FROM delivery_assignments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
            $chkAssignment->execute([$order_id]);
            $last_state = $chkAssignment->fetch(PDO::FETCH_ASSOC);
            
            $chkOrder = $pdo->prepare("SELECT delivery_boy_id, order_status FROM orders WHERE id = ?");
            $chkOrder->execute([$order_id]);
            $last_order = $chkOrder->fetch(PDO::FETCH_ASSOC);

            die("<div style='background:#0f172a; border:4px solid #10b981; color:#f8fafc; padding:30px; margin:20px; font-family:monospace; border-radius:16px; box-shadow:0 25px 50px -12px rgb(0 0 0 / 0.55);'>
                    <h2 style='color:#10b981; margin-top:0;'>✅ ACTION ENGINE EXECUTED SUCCESSFULLY</h2>
                    <p style='color:#94a3b8;'>Redirect block halted by inspector to capture real-time states before page mutation context collapses.</p>
                    <hr style='border:1px solid #334155; margin:15px 0;'>
                    <h3 style='color:#38bdf8; margin-bottom:5px;'>📊 REAL-TIME DB STATE (AFTER RE-ASSIGNMENT ENGINE RUN):</h3>
                    <ul>
                        <li><b>Your Rider ID:</b> #$delivery_id</li>
                        <li><b>Target Order ID:</b> #$order_id</li>
                        <li><b>Current Order Table Status:</b> <span style='color:#fbbf24;'>'" . ($last_order['order_status'] ?? 'NULL') . "'</span></li>
                        <li><b>Current Order Map Assigned Boy ID:</b> <span style='color:#f43f5e;'>" . ($last_order['delivery_boy_id'] ?? 'NULL (Unassigned)') . "</span></li>
                        <li><b>Latest Row In Assignments Table:</b> Rider #<strong>" . ($last_state['delivery_boy_id'] ?? 'NONE') . "</strong> with status <span style='color:#38bdf8;'>'" . ($last_state['assignment_status'] ?? 'NONE') . "'</span></li>
                    </ul>
                    <p style='background:#1e293b; padding:12px; border-radius:6px; color:#e2e8f0; font-size:12px;'>⚠️ <b>LOOP EXPLANATION:</b> Agar upar vaale fields mein wapas se aapka ID (#$delivery_id) aur status 'pending' dikh raha hai, toh iska matlab engine chalte hi wapas aapko match kar raha hai! Agar upar fields NULL hain ya status changed hai, toh mobile card automatically remove ho jayega.</p>
                    <br><a href='Groceries_tasks.php' style='display:inline-block; background:#10b981; color:#fff; padding:10px 20px; text-decoration:none; font-weight:bold; border-radius:8px;'>Press to Continue to Tasks View</a>
                 </div>");
            // =================================================================================

            if($is_api) exit(json_encode(['success'=>true, 'message'=>$msg]));
            header("Location: Groceries_tasks.php?success=" . urlencode($msg)); exit();

        } elseif($action === 'verify_pickup') {
            $code = trim($data['pickup_code'] ?? '');
            $stmt = $pdo->prepare("SELECT pickup_code FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $real_code = $stmt->fetchColumn();

            if($code === $real_code) {
                $pdo->prepare("UPDATE orders SET order_status = 'picked_up', picked_up_at = NOW(), delivery_code = NULL WHERE id = ?")
                    ->execute([$order_id]);
                $stmt_c = $pdo->prepare("SELECT customer_id, shop_id FROM orders WHERE id = ?");
                $stmt_c->execute([$order_id]);
                $inf = $stmt_c->fetch();
                $s_name = $pdo->query("SELECT shop_name FROM shop_owners WHERE id = {$inf['shop_id']}")->fetchColumn();
                sendKhataPush($pdo, (int)$inf['customer_id'], 'customer', "Order Picked Up! 🚚", "Aapka order $s_name se nikal chuka hai. Partner jald hi pahuchega.", null, ['type'=>'order', 'id'=>(string)$order_id]);
                $stmt_items = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmt_items->execute([$order_id]);
                foreach($stmt_items->fetchAll() as $it) {
                    if($it['product_id']) groceries_commit_stock($pdo, (int)$it['product_id'], (float)$it['quantity']);
                }
                $msg = "Pickup successful! Start delivery.";
            } else {
                throw new Exception("Invalid Pickup Code. Ask shopkeeper for the code.");
            }

        } elseif($action === 'send_delivery_code') {
            $dcc = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE orders SET delivery_code = ?, code_updated_at = NOW() WHERE id = ?")
                ->execute([$dcc, $order_id]);
            $stmt_c = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $stmt_c->execute([$order_id]);
            $cid = (int)$stmt_c->fetchColumn();
            sendKhataPush($pdo, $cid, 'customer', "Partner is outside! 🛵", "Aapka verification code: $dcc. Ise rider ko batayein.");
            $msg = "Delivery code (DCC) sent to customer!";

        } elseif($action === 'request_handover_code') {
            $h_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE orders SET handover_code = ?, code_updated_at = NOW() WHERE id = ?")->execute([$h_code, $order_id]);
            $msg = "Handover code generated! Ask shopkeeper to check their dashboard.";

        } elseif($action === 'verify_handover') {
            $entered_code = trim($data['handover_code'] ?? '');
            $stmt = $pdo->prepare("SELECT handover_code FROM orders WHERE id = ? AND delivery_boy_id = ?");
            $stmt->execute([$order_id, $delivery_id]);
            $real_code = $stmt->fetchColumn();

            if($real_code === $entered_code) {
                $pdo->prepare("UPDATE delivery_ledger SET is_handed_over = 1 WHERE order_id = ?")->execute([$order_id]);
                $pdo->prepare("UPDATE orders SET handover_code = 'VERIFIED' WHERE id = ?")->execute([$order_id]);
                $msg = "Cash Handover Verified! Duty complete.";
            } else {
                throw new Exception("Invalid Handover Code. Ask shopkeeper for the code on their screen.");
            }

        } elseif($action === 'verify_delivery') {
            $entered_code = trim($data['delivery_code'] ?? '');
            $stmt = $pdo->prepare("SELECT delivery_code, total_amount, delivery_fee, net_to_shop, customer_id, shop_id FROM orders WHERE id = ? AND delivery_boy_id = ?");
            $stmt->execute([$order_id, $delivery_id]);
            $order_info = $stmt->fetch();

            if(!$order_info) throw new Exception("Order not found.");
            if($order_info['delivery_code'] === $entered_code) {
                $pdo->prepare("UPDATE orders SET order_status = 'delivered', delivered_at = NOW() WHERE id = ?")
                    ->execute([$order_id]);
                $platform_fee = $order_info['total_amount'] - $order_info['delivery_fee'] - $order_info['net_to_shop'];
                $pdo->prepare("INSERT INTO delivery_ledger (delivery_boy_id, order_id, cash_collected, commission_earned, net_payable_to_shop, platform_fee_amount) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$delivery_id, $order_id, $order_info['total_amount'], $order_info['delivery_fee'], $order_info['net_to_shop'], $platform_fee]);
                $pdo->prepare("UPDATE delivery_partners SET status = 'online_idle' WHERE id = ?")
                    ->execute([$delivery_id]);
                $stmt_c = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
                $stmt_c->execute([(int)$order_info['customer_id']]);
                $c_name = $stmt_c->fetchColumn() ?: 'Customer';
                $stmt_s = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
                $stmt_s->execute([(int)$order_info['shop_id']]);
                $s_name = $stmt_s->fetchColumn() ?: 'Shop';
                sendKhataPush($pdo, (int)$order_info['customer_id'], 'customer', "Order Delivered! 🎉", "Aapka order #$order_id $s_name se deliver ho chuka hai.");
                sendKhataPush($pdo, (int)$order_info['shop_id'], 'shop', "Order Delivered ✅", "Customer $c_name ko Order #$order_id mil gaya hai.");
                $msg = "Delivered! Collect Cash: ₹" . number_format($order_info['total_amount'], 2);
            } else {
                throw new Exception("Invalid Security Code (DCC).");
            }
        }

        $pdo->commit();
        if($is_api) exit(json_encode(['success'=>true, 'message'=>$msg]));
        header("Location: Groceries_tasks.php?success=" . urlencode($msg)); exit();

    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        if($is_api) exit(json_encode(['success'=>false, 'message'=>$e->getMessage()]));
        $error = $e->getMessage();
        
        // ===== ADDED FOR DEBUGGING: ROOT CAUSE INSPECTOR =====
        if ($action === 'reject_task') {
            die("<div style='background:#fef2f2; border:2px solid #ef4444; color:#991b1b; padding:25px; margin:20px; font-family:sans-serif; rounded-xl; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1);'>
                    <h2 style='margin:0 0 10px 0; font-size:20px; font-weight:900;'>❌ DECLINE ROOT CAUSE FOUND!</h2>
                    <p style='font-size:14px; font-weight:700; background:#fff; padding:12px; border-left:4px solid #ef4444; margin:10px 0;'><strong>Error Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                    <p style='font-size:12px; color:#4b5563;'><strong>File:</strong> " . $e->getFile() . " on line " . $e->getLine() . "</p>
                    <h3 style='margin:15px 0 5px 0; font-size:13px;'>DEBUG VARIABLES:</h3>
                    <pre style='background:#1e293b; color:#f8fafc; padding:15px; font-size:11px; overflow:auto; border-radius:8px;'>Order ID: $order_id\nRider ID: $delivery_id\nAction: $action\n\nTrace:\n" . $e->getTraceAsString() . "</pre>
                    <br><a href='Groceries_tasks.php' style='background:#1e293b; color:#fff; padding:8px 16px; font-size:11px; text-decoration:none; font-weight:bold; border-radius:6px;'>Go Back</a>
                 </div>");
        }
        // =====================================================
    }
}

// ===== DATA FETCHING =====
$stmt = $pdo->prepare("
    SELECT o.*, s.shop_name, s.full_address as shop_address, 
           s.latitude as shop_lat, s.longitude as shop_lng, 
           s.average_rating, s.total_ratings_count,
           da.assignment_status
    FROM orders o
    JOIN shop_owners s ON o.shop_id = s.id
    JOIN delivery_assignments da ON o.id = da.order_id AND da.delivery_boy_id = ?
    WHERE o.delivery_boy_id = ?
    AND da.assignment_status IN ('pending', 'accepted') 
    AND o.order_status NOT IN ('delivered', 'cancelled', 'pending', 'finding_rider', 'rider_not_found')
    ORDER BY o.created_at DESC
");
$stmt->execute([$delivery_id, $delivery_id]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt_status = $pdo->prepare("SELECT status FROM delivery_partners WHERE id = ?");
$stmt_status->execute([$delivery_id]);
$rider_status = $stmt_status->fetchColumn() ?: 'offline';

if($is_api) exit(json_encode(['success'=>true, 'tasks'=>$tasks]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Grocery Tasks — KhataLink Partner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-emerald-50/30 text-slate-900 p-4 md:p-8">

    <div class="max-w-2xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <a href="dashboard.php" class="w-10 h-10 bg-white border border-emerald-100 rounded-xl flex items-center justify-center text-emerald-600 shadow-sm"><i class="fas fa-chevron-left"></i></a>
            <h1 class="text-xl font-black text-slate-900 uppercase">Grocery Tasks</h1>
            <div class="w-10"></div>
        </div>

        <?php if(isset($error)): ?>
            <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-xs font-bold mb-6"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if(isset($_GET['success'])): ?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-xs font-bold mb-6"><?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>

        <div class="space-y-6">
            <?php if(empty($tasks)): ?>
                <div class="text-center py-20 bg-white rounded-[2.5rem] border border-dashed border-emerald-200">
                    <div class="w-16 h-16 bg-emerald-50 text-emerald-200 rounded-full flex items-center justify-center text-2xl mx-auto mb-4"><i class="fas fa-box"></i></div>
                    <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No active tasks</p>
                </div>
            <?php endif; ?>

            <?php foreach($tasks as $t): 
                $is_pending = ($t['assignment_status'] === 'pending');
                $is_assigned = ($t['order_status'] === 'assigned');
                $is_picked = ($t['order_status'] === 'picked_up');
            ?>
            <div class="bg-white border border-emerald-100 rounded-[2.5rem] p-6 shadow-xl shadow-emerald-900/5 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-2 h-full <?= $is_pending ? 'bg-amber-400' : ($is_picked ? 'bg-blue-600' : 'bg-emerald-500') ?>"></div>

                <div class="mb-6">
                    <div class="flex justify-between items-start">
                        <span class="text-[8px] font-black bg-slate-900 text-white px-2 py-0.5 rounded uppercase tracking-widest mb-2 inline-block">Pickup Location</span>
                        <div class="text-xs font-bold text-slate-400">#ORD-<?= $t['id'] ?></div>
                    </div>
                    <h3 class="font-black text-lg text-slate-900 leading-tight"><?= htmlspecialchars($t['shop_name']) ?></h3>
                    <p class="text-[10px] font-bold text-slate-500 mt-1"><i class="fas fa-store me-1"></i> <?= htmlspecialchars($t['shop_address']) ?></p>
                </div>

                <div class="mb-6 p-4 bg-slate-50 rounded-2xl border border-slate-100">
                    <span class="text-[8px] font-black text-blue-600 uppercase tracking-widest mb-2 inline-block">Drop Location</span>
                    <h4 class="text-sm font-black text-slate-800"><?= htmlspecialchars($t['delivery_name'] ?: ($t['customer_name'] ?? '')) ?> | <?= htmlspecialchars($t['delivery_phone']) ?></h4>
                    <div class="mt-1">
                        <p class="text-[11px] text-slate-600 font-bold leading-tight"><?= htmlspecialchars($t['delivery_apartment_house']) ?></p>
                        <?php if($t['delivery_landmark']): ?>
                            <div class="text-emerald-600 font-black mt-0.5 text-[10px]"><i class="fas fa-map-marker-alt mr-1"></i> Landmark: <?= htmlspecialchars($t['delivery_landmark']) ?></div>
                        <?php endif; ?>
                        <p class="text-[9px] text-slate-400 uppercase font-bold mt-1"><?= htmlspecialchars($t['delivery_village']) ?>, <?= htmlspecialchars($t['delivery_block']) ?>, <?= htmlspecialchars($t['delivery_district'] ?? '') ?> - <?= $t['pincode'] ?></p>
                    </div>
                </div>

                <div class="mb-6 p-5 bg-slate-900 rounded-[2rem] text-white">
                    <div class="text-[8px] font-black text-blue-400 uppercase tracking-[0.2em] mb-4 border-b border-white/10 pb-2">Earning Breakdown</div>
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-[10px] font-bold text-slate-400 uppercase">Your Delivery Fee</span>
                        <span class="text-sm font-black text-emerald-400">₹<?= number_format($t['delivery_fee'], 2) ?></span>
                    </div>
                    <div class="space-y-1.5 mb-4">
                        <div class="flex justify-between text-[9px] font-bold text-slate-500">
                            <span>Pay to Shop (Stock):</span>
                            <span>₹<?= number_format($t['net_to_shop'], 2) ?></span>
                        </div>
                        <?php 
                            $platform_fee = $t['total_amount'] - $t['delivery_fee'] - $t['net_to_shop'];
                            if($platform_fee > 0):
                        ?>
                        <div class="flex justify-between text-[9px] font-bold text-slate-500">
                            <span>Platform Fee (KL):</span>
                            <span>₹<?= number_format($platform_fee, 2) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="pt-3 border-t border-dashed border-white/20 flex justify-between items-center">
                        <div class="text-[10px] font-black uppercase text-blue-400 tracking-wider">Total Cash to Collect</div>
                        <div class="text-xl font-black text-white">₹<?= number_format($t['total_amount'], 2) ?></div>
                    </div>
                </div>

                <?php if($is_pending): ?>
                    <div class="bg-amber-50 border border-amber-100 p-4 rounded-2xl mb-4">
                        <p class="text-[9px] text-amber-700 font-bold leading-tight uppercase">
                            <i class="fas fa-info-circle me-1"></i> Aapko ₹<?= number_format($t['delivery_fee'], 2) ?> milenge is task ko pura karne par.
                        </p>
                    </div>
                    <div class="flex gap-3">
                        <form method="POST" class="flex-1">
                            <input type="hidden" name="action" value="accept_task">
                            <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="w-full bg-emerald-600 text-white py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest shadow-lg hover:bg-emerald-700 transition-all active:scale-95">
                                <i class="fas fa-check me-1"></i> Accept Task
                            </button>
                        </form>
                        <form method="POST" class="flex-1" onsubmit="return confirm('Kya aap sach mein ye task decline karna chahte hain?')">
                            <input type="hidden" name="action" value="reject_task">
                            <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="w-full bg-slate-100 text-slate-500 py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-red-50 hover:text-red-500 transition-all">
                                <i class="fas fa-times me-1"></i> Decline
                            </button>
                        </form>
                    </div>

                <?php elseif($is_assigned): ?>
                    <div class="bg-emerald-50 p-6 rounded-[2rem] border border-emerald-100">
                        <?php if(empty($t['pickup_code'])): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-shop text-emerald-600 text-2xl mb-3"></i>
                                <h4 class="text-sm font-black text-slate-900 mb-4">Reached the Shop?</h4>
                                <form method="POST">
                                    <input type="hidden" name="action" value="send_pickup_code">
                                    <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="w-full bg-emerald-600 text-white py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest shadow-lg shadow-emerald-200">Send Pickup Code to Shop</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <label class="block text-[9px] font-black text-emerald-600 uppercase tracking-widest mb-3">Enter Verification Code from Shop:</label>
                            <form method="POST" class="flex gap-2 items-center mb-4">
                                <input type="hidden" name="action" value="verify_pickup">
                                <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                <input type="text" name="pickup_code" placeholder="000000" maxlength="6" class="flex-1 bg-white border border-emerald-200 rounded-xl px-4 py-3 text-sm font-black tracking-[0.3em] outline-none" required>
                                <button type="submit" class="bg-slate-900 text-white px-5 py-3 rounded-xl text-[10px] font-black uppercase">Verify</button>
                            </form>
                            <?php 
                                $last_sent = !empty($t['code_updated_at']) ? strtotime($t['code_updated_at']) : 0;
                                $diff = time() - $last_sent;
                                $wait = ($diff < 0 || $diff >= 30) ? 0 : (30 - $diff);
                            ?>
                            <div class="pt-4 border-t border-emerald-100">
                                <form method="POST">
                                    <input type="hidden" name="action" value="send_pickup_code">
                                    <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="w-full text-[10px] font-black uppercase tracking-widest transition-all <?= $wait > 0 ? 'text-slate-400 cursor-not-allowed' : 'text-emerald-600 hover:text-emerald-700' ?>" <?= $wait > 0 ? 'disabled' : '' ?>>
                                        <i class="fas fa-sync-alt me-1"></i>
                                        <?= $wait > 0 ? "Resend Code in {$wait}s" : "Resend Pickup Code" ?>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif($is_picked): ?>
                    <div class="bg-blue-600 p-6 rounded-3xl text-white shadow-lg">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-[9px] font-black text-blue-200 uppercase tracking-widest">Drop Location</p>
                                <h4 class="font-black text-sm"><?= htmlspecialchars($t['delivery_name'] ?: 'Customer') ?> | <?= htmlspecialchars($t['delivery_phone']) ?></h4>
                                <div class="mt-2 space-y-1">
                                    <p class="text-[11px] text-white font-black leading-tight"><?= htmlspecialchars($t['delivery_apartment_house']) ?></p>
                                    <?php if($t['delivery_landmark']): ?>
                                        <div class="bg-amber-400 text-slate-900 px-2 py-1 rounded-lg text-[10px] font-black uppercase inline-block shadow-sm">
                                            <i class="fas fa-map-marker-alt mr-1"></i> Landmark: <?= htmlspecialchars($t['delivery_landmark']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <p class="text-[9px] text-blue-100 uppercase font-bold"><?= htmlspecialchars($t['delivery_village']) ?>, <?= htmlspecialchars($t['delivery_block']) ?> - <?= $t['pincode'] ?></p>
                                </div>
                                <?php if($t['delivery_phone_alt']): ?>
                                    <p class="text-[9px] text-blue-200 font-bold mt-1">Alt: <?= htmlspecialchars($t['delivery_phone_alt']) ?></p>
                                </div>
                                <?php endif; ?>
                            <a href="tel:<?= $t['delivery_phone'] ?>" class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-md"><i class="fas fa-phone-alt"></i></a>
                        </div>

                        <div class="mt-6 pt-6 border-t border-white/20">
                            <?php if(empty($t['delivery_code'])): ?>
                                <div class="text-center py-2">
                                    <h4 class="text-xs font-black mb-4">Reached Customer Location?</h4>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="send_delivery_code">
                                        <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="w-full bg-white text-blue-600 py-3 rounded-2xl font-black uppercase text-[10px] tracking-widest shadow-lg">Send Delivery Code (DCC)</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <label class="block text-[9px] font-black text-blue-100 uppercase tracking-widest mb-3">Enter DCC Code from Customer:</label>
                                <form method="POST" class="flex gap-2">
                                    <input type="hidden" name="action" value="verify_delivery">
                                    <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                    <input type="text" name="delivery_code" placeholder="DCC Code" maxlength="6" class="flex-1 bg-white/10 border border-white/30 rounded-xl px-4 py-3 text-sm font-black tracking-[0.3em] text-white placeholder:text-white/40 outline-none" required>
                                    <button type="submit" class="bg-white text-blue-600 px-5 py-3 rounded-xl text-[10px] font-black uppercase">Delivered</button>
                                </form>
                                <?php 
                                    $last_dcc = !empty($t['code_updated_at']) ? strtotime($t['code_updated_at']) : 0;
                                    $dcc_diff = time() - $last_dcc;
                                    $dcc_wait = ($dcc_diff < 0 || $dcc_diff >= 15) ? 0 : (15 - $dcc_diff);
                                ?>
                                <div class="pt-4 text-center">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="send_delivery_code">
                                        <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="text-[9px] font-black uppercase tracking-widest transition-all <?= $dcc_wait > 0 ? 'text-blue-300' : 'text-white underline' ?>" <?= $dcc_wait > 0 ? 'disabled' : '' ?>>
                                            <?= $dcc_wait > 0 ? "Resend DCC in {$dcc_wait}s" : "Resend Delivery Code" ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $t['latitude'] ?>,<?= $t['longitude'] ?>" target="_blank" class="block w-full bg-blue-700 text-white mt-4 py-3 rounded-xl font-black text-center text-[9px] uppercase tracking-widest border border-white/20 hover:bg-blue-800">
                            <i class="fas fa-location-arrow me-1"></i> Open Google Maps Navigation
                        </a>
                    </div>
                <?php endif; ?>

                <div class="mt-6 pt-6 border-t border-slate-50 flex justify-between items-center text-[9px] font-black text-slate-400 uppercase tracking-widest">
                    <span>Cash to Collect: ₹<?= number_format($t['total_amount'], 2) ?></span>
                    <span class="text-blue-600"><?= $t['payment_mode'] ?> Payment</span>
                </div>

                <!-- Cash Settlement (Moved inside loop to fix $t undefined error) -->
                <?php if($t['order_status'] === 'delivered' && $t['payment_mode'] === 'COD'): ?>
                    <div class="bg-slate-900 p-6 rounded-3xl text-white mt-4 border border-white/10">
                        <h4 class="text-xs font-black uppercase text-blue-400 mb-4 tracking-widest text-center">Cash Settlement</h4>
                        <?php if(empty($t['handover_code'])): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="request_handover_code">
                                <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                <button class="w-full bg-blue-600 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg">Generate Handover Code</button>
                            </form>
                        <?php elseif($t['handover_code'] === 'VERIFIED'): ?>
                            <div class="text-emerald-400 font-black text-xs uppercase tracking-widest text-center"><i class="fas fa-check-circle me-1"></i> Settlement Complete</div>
                        <?php else: ?>
                            <p class="text-[9px] text-slate-400 text-center mb-4 uppercase font-bold">Ask shopkeeper for the code shown on their dashboard</p>
                            <form method="POST" class="flex gap-2">
                                <input type="hidden" name="action" value="verify_handover">
                                <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                <input type="text" name="handover_code" placeholder="Enter Code" class="flex-1 bg-white/10 border border-white/20 rounded-xl px-4 py-3 text-sm font-black tracking-[0.3em] outline-none text-white">
                                <button class="bg-white text-slate-900 px-4 py-3 rounded-xl text-[10px] font-black uppercase">Verify</button>
                            </form>
                            <div class="mt-4 text-center">
                                <form method="POST">
                                    <input type="hidden" name="action" value="request_handover_code">
                                    <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                    <button class="text-[9px] font-bold text-slate-500 uppercase underline">Resend Code Request</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-12 text-center">
            <p class="text-[9px] font-black text-slate-300 uppercase tracking-[0.4em]">KhataLink Logistics</p>
        </div>
    </div>

    <script>
    setInterval(() => { location.reload(); }, 45000);

    if ("geolocation" in navigator && "<?= $rider_status ?>" === "online_idle") {
        function updateRiderLocation() {
            navigator.geolocation.getCurrentPosition(async (position) => {
                try {
                    await fetch('ajax_update_location.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'FlutterApp' },
                        body: JSON.stringify({ latitude: position.coords.latitude, longitude: position.coords.longitude })
                    });
                } catch(e) {}
            }, null, { enableHighAccuracy: true });
        }
        updateRiderLocation();
        setInterval(updateRiderLocation, 30000);
    }
    </script>
</body>
</html>