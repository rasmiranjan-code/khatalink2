<?php
header('Access-Control-Allow-Origin: *'); // Allow all origins for development
header('Access-Control-Allow-Methods: POST, GET, OPTIONS'); // Specify allowed methods
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Date filter for both API and Web
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');

session_start();
require_once '../includes/db.php';

// Include config to get platform fee constants (needed for real tax calculation)
require_once '../includes/razorpay_config.php';

// ===== FLUTTER API =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    header('Content-Type: application/json');

    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    error_log("Customer Dashboard API: Received token (after Bearer removal): " . $token);

    $decoded = base64_decode($token);
    error_log("Customer Dashboard API: Decoded token string: " . $decoded);

    $parts = explode(':', $decoded);
    $customer_id = $parts[0] ?? 0;
    error_log("Customer Dashboard API: Extracted customer_id: " . $customer_id);

    $customer = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $customer->execute([$customer_id]);
    $customer = $customer->fetch();

    if(!$customer) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit();
    }

    $total_linked_shops = $pdo->prepare("SELECT COUNT(*) FROM shop_customers WHERE customer_id = ?");
    $total_linked_shops->execute([$customer_id]);
    $total_linked_shops = $total_linked_shops->fetchColumn();

    $total_outstanding_due = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND status = 'open'");
    $total_outstanding_due->execute([$customer_id]);
    $total_outstanding_due = $total_outstanding_due->fetchColumn();

    // Total Payments Made (including platform fees for online transactions)
    $total_payments_made_stmt = $pdo->prepare("SELECT (
        -- Sum of base amounts for all payments (online and manual)
        COALESCE((SELECT SUM(amount_paid) FROM payment_history WHERE customer_id = ?), 0) +
        COALESCE((SELECT SUM(paid_amount) FROM monthly_khata WHERE customer_id = ?), 0) +
        COALESCE((SELECT SUM(bp.amount_paid) FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.customer_id = ?), 0) +

        -- Add platform fees for online payments from payment_history
        COALESCE((SELECT SUM(amount_paid * (" . LEDGER_PLATFORM_COMMISSION_PERCENT . " / 100)) FROM payment_history WHERE customer_id = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
        -- Add platform fees for online payments from monthly_khata
        COALESCE((SELECT SUM(paid_amount * (" . MONTHLY_PLATFORM_COMMISSION_PERCENT . " / 100)) FROM monthly_khata WHERE customer_id = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
        -- Add platform fees for online payments from bond_payments
        COALESCE((SELECT SUM(bp.amount_paid * (" . BOND_PLATFORM_COMMISSION_PERCENT . " / 100)) FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.customer_id = ? AND bp.razorpay_payment_id IS NOT NULL AND bp.razorpay_payment_id != 'Manual'), 0)
    ) as total");
    $total_payments_made_stmt->execute([$customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $customer_id]);
    $total_payments_made = $total_payments_made_stmt->fetchColumn();

    // Today's Online Payments (Real amount with taxes/fees)
    $online_paid_stmt = $pdo->prepare("SELECT (
        COALESCE((SELECT SUM(amount_paid * (1 + " . LEDGER_PLATFORM_COMMISSION_PERCENT . "/100)) FROM payment_history WHERE customer_id = ? AND DATE(payment_date) = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
        COALESCE((SELECT SUM(paid_amount * (1 + " . MONTHLY_PLATFORM_COMMISSION_PERCENT . "/100)) FROM monthly_khata WHERE customer_id = ? AND DATE(paid_at) = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
        COALESCE((SELECT SUM(bp.amount_paid * (1 + " . BOND_PLATFORM_COMMISSION_PERCENT . "/100)) FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.customer_id = ? AND DATE(bp.payment_date) = ? AND bp.razorpay_payment_id IS NOT NULL AND bp.razorpay_payment_id != 'Manual'), 0)
    ) as total");
    $online_paid_stmt->execute([$customer_id, $filter_date, $customer_id, $filter_date, $customer_id, $filter_date]);
    $total_online_paid_api = (float)$online_paid_stmt->fetchColumn();

    $recent_udhar = $pdo->prepare("
        SELECT ue.*, s.shop_name
        FROM udhar_entries ue
        JOIN shop_owners s ON ue.shop_id = s.id
        WHERE ue.customer_id = ?
        ORDER BY ue.entry_date DESC
        LIMIT 5
    ");
    $recent_udhar->execute([$customer_id]);
    $recent_udhar = $recent_udhar->fetchAll();

    // Cast numeric fields for Flutter
    $recent_udhar = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'shop_id' => (int)$row['shop_id'],
            'customer_id' => (int)$row['customer_id'],
            'total_amount' => (float)$row['total_amount'],
            'total_paid' => (float)$row['total_paid'],
            'total_remaining' => (float)$row['total_remaining'],
            'discount_percentage' => (float)$row['discount_percentage'],
            'entry_date' => $row['entry_date'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'shop_name' => $row['shop_name']
        ];
    }, $recent_udhar);

    $recent_payments = $pdo->prepare("
        SELECT ph.*, s.shop_name
        FROM payment_history ph
        JOIN shop_owners s ON ph.shop_id = s.id
        WHERE ph.customer_id = ?
        ORDER BY ph.payment_date DESC
        LIMIT 5
    ");
    $recent_payments->execute([$customer_id]);
    $recent_payments = $recent_payments->fetchAll();

    // Cast numeric fields for Flutter
    $recent_payments = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'entry_id' => (int)$row['entry_id'],
            'shop_id' => (int)$row['shop_id'],
            'customer_id' => (int)$row['customer_id'],
            'amount_paid' => (float)$row['amount_paid'],
            'remaining_after' => (float)$row['remaining_after'],
            'payment_mode' => $row['payment_mode'],
            'payment_date' => $row['payment_date'],
            'created_at' => $row['created_at'],
            'shop_name' => $row['shop_name']
        ];
    }, $recent_payments);

    echo json_encode([
        'success' => true,
        'customer' => [
            'name' => $customer['name'],
            'unique_id' => $customer['unique_id'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
        ],
        'stats' => [
            'total_linked_shops' => (int)$total_linked_shops,
            'total_outstanding_due' => (float)$total_outstanding_due,
            'total_payments_made' => (float)$total_payments_made,
            'today_online_paid' => $total_online_paid_api,
        ],
        'recent_udhar' => $recent_udhar,
        'recent_payments' => $recent_payments,
    ]);
    exit();
}
// ===== END FLUTTER API =====

require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$customer_id = $_SESSION['customer_id'];

// Fetch customer details
$customer = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$customer->execute([$customer_id]);
$customer = $customer->fetch();

if (!$customer) {
    session_destroy();
    header("Location: ../auth/login.php?type=customer&error=Invalid session. Please login again.");
    exit();
}

// Fetch dashboard stats
$total_linked_shops = $pdo->prepare("SELECT COUNT(*) FROM shop_customers WHERE customer_id = ?");
$total_linked_shops->execute([$customer_id]);
$total_linked_shops = $total_linked_shops->fetchColumn();

$total_outstanding_due = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND status = 'open'");
$total_outstanding_due->execute([$customer_id]);
$total_outstanding_due = $total_outstanding_due->fetchColumn();

// Total Payments Made (including platform fees for online transactions)
$total_payments_made_stmt = $pdo->prepare("SELECT (
    -- Sum of base amounts for all payments (online and manual)
    COALESCE((SELECT SUM(amount_paid) FROM payment_history WHERE customer_id = ?), 0) +
    COALESCE((SELECT SUM(paid_amount) FROM monthly_khata WHERE customer_id = ?), 0) +
    COALESCE((SELECT SUM(bp.amount_paid) FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.customer_id = ?), 0) +

    -- Add platform fees for online payments from payment_history
    COALESCE((SELECT SUM(amount_paid * (" . LEDGER_PLATFORM_COMMISSION_PERCENT . " / 100)) FROM payment_history WHERE customer_id = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
    COALESCE((SELECT SUM(paid_amount * (" . MONTHLY_PLATFORM_COMMISSION_PERCENT . " / 100)) FROM monthly_khata WHERE customer_id = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
    COALESCE((SELECT SUM(bp.amount_paid * (" . BOND_PLATFORM_COMMISSION_PERCENT . " / 100)) FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.customer_id = ? AND bp.razorpay_payment_id IS NOT NULL AND bp.razorpay_payment_id != 'Manual'), 0)
) as total");
$total_payments_made_stmt->execute([$customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $customer_id]);
$total_payments_made = $total_payments_made_stmt->fetchColumn();

// Today's Online Payments for Web (Real amount with taxes/fees)
$online_paid_web = $pdo->prepare("SELECT (
    COALESCE((SELECT SUM(amount_paid * (1 + " . LEDGER_PLATFORM_COMMISSION_PERCENT . "/100)) FROM payment_history WHERE customer_id = ? AND DATE(payment_date) = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
    COALESCE((SELECT SUM(paid_amount * (1 + " . MONTHLY_PLATFORM_COMMISSION_PERCENT . "/100)) FROM monthly_khata WHERE customer_id = ? AND DATE(paid_at) = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
    COALESCE((SELECT SUM(bp.amount_paid * (1 + " . BOND_PLATFORM_COMMISSION_PERCENT . "/100)) FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.customer_id = ? AND DATE(bp.payment_date) = ? AND bp.razorpay_payment_id IS NOT NULL AND bp.razorpay_payment_id != 'Manual'), 0)
) as total");
$online_paid_web->execute([$customer_id, $filter_date, $customer_id, $filter_date, $customer_id, $filter_date]);
$total_online_paid_amount = (float)$online_paid_web->fetchColumn();


// Fetch recent udhar entries
$recent_udhar = $pdo->prepare("
    SELECT ue.*, s.shop_name
    FROM udhar_entries ue
    JOIN shop_owners s ON ue.shop_id = s.id
    WHERE ue.customer_id = ?
    ORDER BY ue.entry_date DESC
    LIMIT 5
");
$recent_udhar->execute([$customer_id]);
$recent_udhar = $recent_udhar->fetchAll();

// Fetch recent payments
$recent_payments = $pdo->prepare("
    SELECT ph.*, s.shop_name
    FROM payment_history ph
    JOIN shop_owners s ON ph.shop_id = s.id
    WHERE ph.customer_id = ?
    ORDER BY ph.payment_date DESC
    LIMIT 5
");
$recent_payments->execute([$customer_id]);
$recent_payments = $recent_payments->fetchAll();

// Fetch active marketplace orders for quick tracking
$active_orders_stmt = $pdo->prepare("
    SELECT o.*, s.shop_name, s.shop_category
    FROM orders o
    JOIN shop_owners s ON o.shop_id = s.id
    WHERE o.customer_id = ? AND o.order_status NOT IN ('delivered', 'cancelled')
    ORDER BY o.created_at DESC
");
$active_orders_stmt->execute([$customer_id]);
$active_orders = $active_orders_stmt->fetchAll();
$_SESSION['active_orders_count'] = count($active_orders); // For sidebar badge
$active_orders = $active_orders_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= htmlspecialchars($customer['name']) ?></title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass-banner { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); position: relative; overflow: hidden; }
        .glass-banner::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.03) 0%, transparent 70%); pointer-events: none; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="flex items-center gap-3">
        <div class="hidden sm:flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-xs font-bold px-3 py-1.5 rounded-full uppercase tracking-wider">
            <i class="fas fa-user-tag"></i>
            <?= htmlspecialchars($_SESSION['customer_name']) ?>
        </div>
    </div>
</nav>

<!-- Main Layout (Full Width) -->
<div class="min-h-[calc(100vh-64px)]">

    <!-- Main -->
    <div class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">

        <!-- App Banners Slider -->
        <?php 
        $banners = $pdo->prepare("SELECT image_path FROM banners WHERE target IN ('customer', 'all') ORDER BY created_at DESC LIMIT 5");
        $banners->execute();
        $banner_list = $banners->fetchAll();
        if(!empty($banner_list)): ?>

        <div id="bannerSlider" class="relative w-full mb-8 overflow-hidden rounded-3xl shadow-xl shadow-slate-200" style="background:#f1f5f9;">

            <!-- Slides Track -->
            <div id="bannerTrack"
                 class="flex transition-transform duration-700 ease-in-out"
                 style="width: <?= count($banner_list) * 100 ?>%;">

                <?php foreach($banner_list as $bl): ?>
                <div style="width: <?= 100 / count($banner_list) ?>%; flex-shrink: 0;">
                    <img src="<?= htmlspecialchars($bl['image_path']) ?>"
                         style="width: 100%; height: auto; display: block; max-height: 320px; object-fit: cover;"
                         loading="lazy">
                </div>
                <?php endforeach; ?>

            </div>

            <!-- Dot Indicators -->
            <?php if(count($banner_list) > 1): ?>
            <div style="position:absolute; bottom:14px; left:0; right:0; display:flex; justify-content:center; gap:6px; z-index:10;">
                <?php foreach($banner_list as $i => $bl): ?>
                <div class="b-dot"
                     data-index="<?= $i ?>"
                     style="height:8px; width:<?= $i === 0 ? '22px' : '8px' ?>; border-radius:999px; background:<?= $i === 0 ? 'rgba(255,255,255,0.95)' : 'rgba(255,255,255,0.35)' ?>; transition:all 0.3s ease; cursor:pointer;">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>

        <script>
        (function() {
            let cur = 0;
            const total = <?= count($banner_list) ?>;
            const track = document.getElementById('bannerTrack');
            const dots  = document.querySelectorAll('.b-dot');

            function goTo(n) {
                cur = (n + total) % total;
                track.style.transform = 'translateX(-' + ((cur * 100) / total) + '%)';
                dots.forEach(function(d, i) {
                    d.style.width      = i === cur ? '22px'                    : '8px';
                    d.style.background = i === cur ? 'rgba(255,255,255,0.95)' : 'rgba(255,255,255,0.35)';
                });
            }

            dots.forEach(function(d) {
                d.addEventListener('click', function() { goTo(parseInt(d.dataset.index)); });
            });

            if(total > 1) setInterval(function() { goTo(cur + 1); }, 4000);
        })();
        </script>

        <?php endif; ?>

        <!-- Welcome Banner -->
        <div class="glass-banner rounded-3xl p-8 mb-8 text-white shadow-2xl shadow-slate-200">
            <div class="relative z-10 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-2">Hello, <?= htmlspecialchars($customer['name']) ?>! 👋</h1>
                    <p class="text-slate-400 text-sm md:text-base font-medium">Your Unique ID: <span class="opacity-70"><?= htmlspecialchars($customer['unique_id']) ?></span></p>
                </div>
                <div class="hidden md:block">
                    <div class="w-16 h-16 bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl flex items-center justify-center text-3xl">
                        <i class="fas fa-user-tag"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Date Filter for Online Payment History -->
        <form method="GET" class="bg-white border border-slate-200 rounded-2xl p-4 mb-6 flex items-center gap-4 shadow-sm">
            <div class="flex-1">
                <label for="filter_date" class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Filter Payment Date</label>
                <input type="date" id="filter_date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" 
                       class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all">
            </div>
            <button type="submit" class="bg-slate-900 text-white font-bold px-6 py-2.5 rounded-xl hover:bg-blue-600 transition-all text-[10px] uppercase tracking-widest">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>


        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <!-- Stat Item: Linked Shops -->
            <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all duration-300 group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center text-sm transition-colors group-hover:bg-blue-600 group-hover:text-white">
                        <i class="fas fa-store"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">My Network</span>
                </div>
                <div class="text-slate-500 text-xs font-semibold mb-1">Linked Shops</div>
                <div class="text-2xl font-extrabold text-slate-900 tracking-tight"><?= number_format($total_linked_shops) ?></div>
            </div>
            <!-- Stat Item: Online Payment Report -->
            <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all duration-300 group border-b-4 border-b-indigo-600">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center text-sm transition-colors group-hover:bg-indigo-600 group-hover:text-white">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Paid (<?= date('d M', strtotime($filter_date)) ?>)</span>
                </div>
                <div class="text-slate-500 text-xs font-semibold mb-1">Online to KhataLink</div>
                <div class="text-2xl font-extrabold text-indigo-600 tracking-tight">₹<?= number_format($total_online_paid_amount, 2) ?></div>
            </div>
            <!-- Stat Item: Total Due -->
            <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all duration-300 group border-b-4 border-b-red-600">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 bg-red-50 text-red-600 rounded-xl flex items-center justify-center text-sm transition-colors group-hover:bg-red-600 group-hover:text-white">
                        <i class="fas fa-rupee-sign"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">My Debts</span>
                </div>
                <div class="text-slate-500 text-xs font-semibold mb-1">Total Outstanding Due</div>
                <div class="text-2xl font-extrabold text-red-600 tracking-tight">₹<?= number_format($total_outstanding_due, 0) ?></div>
            </div>
            <!-- Stat Item: Total Payments -->
            <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all duration-300 group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center text-sm transition-colors group-hover:bg-emerald-600 group-hover:text-white">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">My Payments</span>
                </div>
                <div class="text-slate-500 text-xs font-semibold mb-1">Total Paid (Incl. Fees)</div>
                <div class="text-2xl font-extrabold text-emerald-600 tracking-tight">₹<?= number_format($total_payments_made, 2) ?></div>
            </div>
        </div>

        <!-- Quick Actions -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <a href="Food_home.php" class="col-span-2 bg-orange-600 border border-orange-500 rounded-3xl p-6 flex items-center justify-between group hover:shadow-xl hover:shadow-orange-200 transition-all text-white relative overflow-hidden">
        <div class="relative z-10">
            <h4 class="font-black text-lg uppercase tracking-tight">Order Food</h4>
            <p class="text-orange-100 text-xs font-medium">Delicious meals from local kitchens</p>
        </div>
        <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center text-2xl group-hover:scale-110 transition-transform"><i class="fas fa-utensils"></i></div>
        <div class="absolute -right-4 -bottom-4 text-white/5 text-8xl rotate-12"><i class="fas fa-hamburger"></i></div>
    </a>
    <a href="Groceries_home.php" class="col-span-2 bg-emerald-600 border border-emerald-500 rounded-3xl p-6 flex items-center justify-between group hover:shadow-xl hover:shadow-emerald-200 transition-all text-white relative overflow-hidden">
        <div class="relative z-10">
            <h4 class="font-black text-lg uppercase tracking-tight">Groceries Mall</h4>
            <p class="text-emerald-100 text-xs font-medium">Order items from nearest shops</p>
        </div>
        <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center text-2xl group-hover:scale-110 transition-transform"><i class="fas fa-shopping-basket"></i></div>
        <div class="absolute -right-4 -bottom-4 text-white/5 text-8xl rotate-12"><i class="fas fa-leaf"></i></div>
    </a>
    <a href="shops.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-blue-500 hover:bg-blue-50 transition-all group">
        <div class="w-11 h-11 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-store"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-blue-700">My Shops</span>
    </a>
    <a href="ledger.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-emerald-500 hover:bg-emerald-50 transition-all group">
        <div class="w-11 h-11 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-list-check"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-emerald-700">Digital Ledger</span>
    </a>
    <a href="statements.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-amber-500 hover:bg-amber-50 transition-all group">
        <div class="w-11 h-11 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-file-invoice-dollar"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-amber-700">Statements</span>
    </a>
    <a href="profile.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-purple-500 hover:bg-purple-50 transition-all group">
        <div class="w-11 h-11 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-user-circle"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-purple-700">My Profile</span>
    </a>
    <a href="bonds.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-orange-500 hover:bg-orange-50 transition-all group">
        <div class="w-11 h-11 bg-orange-100 text-orange-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-file-contract"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-orange-700">My Bonds</span>
    </a>
    <a href="monthly_khata.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-teal-500 hover:bg-teal-50 transition-all group">
        <div class="w-11 h-11 bg-teal-100 text-teal-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-calendar-alt"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-teal-700">Monthly Dues</span>
    </a>
    <a href="pos_bills.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-rose-500 hover:bg-rose-50 transition-all group">
        <div class="w-11 h-11 bg-rose-100 text-rose-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-receipt"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-rose-700">My POS Bills</span>
    </a>
    <a href="analytics.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-pink-500 hover:bg-pink-50 transition-all group">
        <div class="w-11 h-11 bg-pink-100 text-pink-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-chart-line"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-pink-700">Analytics</span>
    </a>
    <a href="reports.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-red-500 hover:bg-red-50 transition-all group">
        <div class="w-11 h-11 bg-red-100 text-red-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-flag"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-red-700">Disputes</span>
    </a>
    <a href="Groceries_orders.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-red-500 hover:bg-red-50 transition-all group">
        <div class="w-11 h-11 bg-red-100 text-red-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-shopping-bag"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-red-700">My Orders</span>
    </a>
    <a href="queries.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-indigo-500 hover:bg-indigo-50 transition-all group">
        <div class="w-11 h-11 bg-indigo-100 text-indigo-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-headset"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-indigo-700">Help Center</span>
    </a>
    <a href="javascript:void(0)" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 opacity-50 cursor-not-allowed">
        <div class="w-11 h-11 bg-slate-100 text-slate-400 rounded-xl flex items-center justify-center"><i class="fas fa-lock"></i></div>
        <span class="text-sm font-bold text-slate-500 text-center">Order Nearby <span class="text-[8px] bg-amber-500 text-white px-1.5 py-0.5 rounded ml-1">COMING SOON</span></span>
    </a>
    <a href="javascript:void(0)" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 opacity-50 cursor-not-allowed">
        <div class="w-11 h-11 bg-slate-100 text-slate-400 rounded-xl flex items-center justify-center"><i class="fas fa-lock"></i></div>
        <span class="text-sm font-bold text-slate-500 text-center">Order History <span class="text-[8px] bg-amber-500 text-white px-1.5 py-0.5 rounded ml-1">COMING SOON</span></span>
    </a>
    <a href="../auth/logout.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-slate-500 hover:bg-slate-50 transition-all group">
        <div class="w-11 h-11 bg-slate-100 text-slate-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-sign-out-alt"></i></div>
        <span class="text-sm font-bold text-slate-700 group-hover:text-slate-700">Logout</span>
    </a>
</div>

        <!-- Active Marketplace Orders Section -->
        <?php if(!empty($active_orders)): ?>
        <div class="mb-8">
            <h2 class="text-sm font-black uppercase tracking-widest text-slate-400 mb-4 flex items-center gap-2">
                <span class="relative flex h-2 w-2">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
                </span>
                <!-- Active Orders Section -->
        My Active Orders
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach($active_orders as $ao): ?>
                <div class="bg-white border border-slate-200 rounded-[2rem] p-5 hover:border-blue-500 transition-all group flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-xl group-hover:bg-blue-600 group-hover:text-white transition-all">
                            <i class="fas fa-shopping-bag"></i>
                        </div>
                        <div>
                            <h4 class="text-sm font-black text-slate-900"><?= htmlspecialchars($ao['shop_name']) ?></h4>
                            <div class="flex items-center gap-2">
                                <span class="text-[9px] font-black px-2 py-0.5 bg-blue-50 text-blue-600 rounded uppercase tracking-widest">
                                    <?= htmlspecialchars($ao['order_status']) ?>
                                </span>
                                <span class="text-[10px] font-bold text-slate-400">#ORD-<?= $ao['id'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <?php if(!in_array($ao['order_status'], ['picked_up', 'delivered', 'cancelled'])): ?>
                            <button onclick="cancelOrder(<?= $ao['id'] ?>)" class="bg-red-50 text-red-600 font-black px-4 py-2.5 rounded-xl text-[10px] uppercase tracking-widest hover:bg-red-600 hover:text-white transition-all border border-red-100">
                                Cancel
                            </button>
                        <?php endif; ?>
                        <a href="Groceries_order_tracking.php?order_id=<?= $ao['id'] ?>" class="bg-slate-900 text-white font-black px-5 py-2.5 rounded-xl text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg">
                            Track
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Tables -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Recent Udhar Entries -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                        <i class="fas fa-file-invoice text-blue-600"></i> Recent Udhar Entries
                    </h3>
                    <a href="ledger.php" class="text-xs font-bold text-blue-600 hover:underline">View All</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-100">
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Shop</th>
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if(count($recent_udhar) > 0): ?>
                            <?php foreach($recent_udhar as $entry): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-4">
                                    <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($entry['shop_name']) ?></div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="text-[11px] text-slate-500 font-bold uppercase tracking-tight"><?= date('d M Y', strtotime($entry['entry_date'])) ?></div>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <span class="bg-red-50 text-red-700 px-2 py-1 rounded-lg font-black text-xs">₹<?= number_format($entry['total_amount'], 0) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="3" class="px-5 py-10 text-center text-slate-400 text-sm font-medium italic">No recent udhar entries</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Payments -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                        <i class="fas fa-rupee-sign text-emerald-600"></i> Recent Payments
                    </h3>
                    <a href="ledger.php" class="text-xs font-bold text-blue-600 hover:underline">View All</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-100">
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Shop</th>
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if(count($recent_payments) > 0): ?>
                            <?php foreach($recent_payments as $payment): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-4">
                                    <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($payment['shop_name']) ?></div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="text-[11px] text-slate-500 font-bold uppercase tracking-tight"><?= date('d M Y', strtotime($payment['payment_date'])) ?></div>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <span class="bg-emerald-50 text-emerald-700 px-2 py-1 rounded-lg font-black text-xs">₹<?= number_format($payment['amount_paid'], 0) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="3" class="px-5 py-10 text-center text-slate-400 text-sm font-medium italic">No recent payments</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<footer class="bg-white border-t border-slate-200 py-6 text-center">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">
        © <?= date('Y') ?> KhataLink — Premium Digital Ledger
    </div>
</footer>

<script>
async function cancelOrder(orderId) {
    if(!confirm("Are you sure you want to cancel this order?")) return;

    try {
        const response = await fetch('ajax_cancel_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        });
        
        const data = await response.json();
        
        if(data.success) {
            // Refresh page to update the active orders list
            window.location.reload();
        } else {
            alert(data.message);
        }
    } catch (error) {
        console.error("Error cancelling order:", error);
        alert("Something went wrong. Please try again.");
    }
}
</script>

</body>
</html>