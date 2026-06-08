<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$filter_date = $_GET['filter_date'] ?? date('Y-m-d');

session_start();
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');

// ===== FLUTTER API =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    header('Content-Type: application/json');

    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    error_log("Shop Dashboard API: Received token (after Bearer removal): " . $token);

    $decoded = base64_decode($token);
    error_log("Shop Dashboard API: Decoded token string: " . $decoded);

    $parts = explode(':', $decoded);
    $shop_id = $parts[0] ?? 0;
    error_log("Shop Dashboard API: Extracted shop_id: " . $shop_id);

    $shop = $pdo->prepare("SELECT * FROM shop_owners WHERE id = ?");
    $shop->execute([$shop_id]);
    $shop = $shop->fetch();

    if(!$shop) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit();
    }

    $total_customers = $pdo->prepare("SELECT COUNT(*) FROM shop_customers WHERE shop_id = ?");
    $total_customers->execute([$shop_id]);
    $total_customers = $total_customers->fetchColumn();

    $total_due = $pdo->prepare("SELECT COALESCE(SUM(total_remaining),0) FROM udhar_entries WHERE shop_id = ? AND status = 'open'");
    $total_due->execute([$shop_id]);
    $total_due = $total_due->fetchColumn();

    $total_collected = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payment_history WHERE shop_id = ?");
    $total_collected->execute([$shop_id]);
    $total_collected = $total_collected->fetchColumn();

    $active_khata_count = $pdo->prepare("SELECT COUNT(*) FROM udhar_entries WHERE shop_id = ? AND status = 'open' AND total_remaining > 0");
    $active_khata_count->execute([$shop_id]);
    $active_khata_count = $active_khata_count->fetchColumn();

    $overdue_count = $pdo->prepare("SELECT COUNT(*) FROM bonds WHERE shop_id = ? AND status = 'overdue'");
    $overdue_count->execute([$shop_id]);
    $overdue_count = $overdue_count->fetchColumn();

    $unread_reports = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE shop_id = ? AND is_read = 0");
    $unread_reports->execute([$shop_id]);
    $unread_reports = $unread_reports->fetchColumn();

    $today_online_stmt = $pdo->prepare("SELECT (
        COALESCE((SELECT SUM(amount_paid) FROM payment_history WHERE shop_id = ? AND DATE(payment_date) = CURDATE() AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
        COALESCE((SELECT SUM(paid_amount) FROM monthly_khata WHERE shop_id = ? AND DATE(paid_at) = CURDATE() AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
        COALESCE((SELECT SUM(bp.amount_paid) FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.shop_id = ? AND DATE(bp.payment_date) = CURDATE() AND bp.razorpay_payment_id IS NOT NULL AND bp.razorpay_payment_id != 'Manual'), 0)
    ) as total");
    $today_online_stmt->execute([$shop_id, $shop_id, $shop_id]);
    $today_online_val = (float)$today_online_stmt->fetchColumn();

    $recent_customers = $pdo->prepare("
        SELECT c.id, c.name, c.unique_id, sc.added_at,
        COALESCE(SUM(ue.total_remaining),0) as total_due
        FROM customers c
        JOIN shop_customers sc ON c.id = sc.customer_id
        JOIN udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id = ? AND ue.status = 'open'
        WHERE sc.shop_id = ?
        GROUP BY c.id
        HAVING total_due > 0
        ORDER BY sc.added_at DESC
        LIMIT 5
    ");
    $recent_customers->execute([$shop_id, $shop_id]);
    $recent_customers = $recent_customers->fetchAll();

    $recent_payments = $pdo->prepare("
        SELECT ph.*, c.name as customer_name
        FROM payment_history ph
        JOIN customers c ON ph.customer_id = c.id
        WHERE ph.shop_id = ?
        ORDER BY ph.payment_date DESC
        LIMIT 5
    ");
    $recent_payments->execute([$shop_id]);
    $recent_payments = $recent_payments->fetchAll();

    echo json_encode([
        'success' => true,
        'shop' => [
            'name' => $shop['name'],
            'shop_name' => $shop['shop_name'],
            'shop_category' => $shop['shop_category'],
        ],
        'stats' => [
            'total_customers' => (int)$total_customers,
            'total_due' => (float)$total_due,
            'total_collected' => (float)$total_collected,
            'active_khata_count' => (int)$active_khata_count,
            'overdue_count' => (int)$overdue_count,
            'unread_reports' => (int)$unread_reports,
            'today_online_payout' => $today_online_val,
        ],
        'recent_customers' => $recent_customers,
        'recent_payments' => $recent_payments,
    ]);
    exit();
}
// ===== END FLUTTER API =====

require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];

// ── SHOP STATUS HANDLER ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_online') {
        $status = (int)($_POST['status'] ?? 1);
        if ($status === 1) {
            $stmt_t = $pdo->prepare("SELECT open_time, close_time FROM shop_owners WHERE id = ?");
            $stmt_t->execute([$shop_id]);
            $t = $stmt_t->fetch();
            $now = date('H:i:s');
            if ($now > $t['close_time'] || $now < $t['open_time']) {
                $new_until = date('Y-m-d H:i:s', strtotime('+4 hours'));
                $pdo->prepare("UPDATE shop_owners SET is_online = 1, override_until = ? WHERE id = ?")->execute([$new_until, $shop_id]);
            } else {
                $pdo->prepare("UPDATE shop_owners SET is_online = 1, override_until = NULL WHERE id = ?")->execute([$shop_id]);
            }
        } else {
            $pdo->prepare("UPDATE shop_owners SET is_online = 0, override_until = NULL WHERE id = ?")->execute([$shop_id]);
        }
    } elseif ($action === 'extend_time') {
        $new_until = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $pdo->prepare("UPDATE shop_owners SET override_until = ?, is_online = 1 WHERE id = ?")->execute([$new_until, $shop_id]);
    }
}

// Fetch Current Status
$stmt_s = $pdo->prepare("SELECT is_online, open_time, close_time, override_until FROM shop_owners WHERE id = ?");
$stmt_s->execute([$shop_id]);
$s_status = $stmt_s->fetch();
$is_open_now = ($s_status['is_online'] == 1 && ((date('H:i:s') >= $s_status['open_time'] && date('H:i:s') <= $s_status['close_time']) || ($s_status['override_until'] && strtotime($s_status['override_until']) > time())));

// Run overdue fine check
include_once '../includes/cron_apply_fines.php';

$shop = $pdo->prepare("SELECT * FROM shop_owners WHERE id = ?");
$shop->execute([$shop_id]);
$shop = $shop->fetch();

$total_customers = $pdo->prepare("SELECT COUNT(*) FROM shop_customers WHERE shop_id = ?");
$total_customers->execute([$shop_id]);
$total_customers = $total_customers->fetchColumn();

$total_entries = $pdo->prepare("SELECT COUNT(*) FROM udhar_entries WHERE shop_id = ?");
$total_entries->execute([$shop_id]);
$total_entries = $total_entries->fetchColumn();

$active_khata_count = $pdo->prepare("SELECT COUNT(*) FROM udhar_entries WHERE shop_id = ? AND status = 'open' AND total_remaining > 0");
$active_khata_count->execute([$shop_id]);
$active_khata_count = $active_khata_count->fetchColumn();

$total_due = $pdo->prepare("SELECT COALESCE(SUM(total_remaining),0) FROM udhar_entries WHERE shop_id = ? AND status = 'open'");
$total_due->execute([$shop_id]);
$total_due = $total_due->fetchColumn();

$total_collected = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payment_history WHERE shop_id = ?");
$total_collected->execute([$shop_id]);
$total_collected = $total_collected->fetchColumn();

$unread_reports = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE shop_id = ? AND is_read = 0");
$unread_reports->execute([$shop_id]);
$unread_reports = $unread_reports->fetchColumn();

$overdue_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM bonds WHERE shop_id = ? AND status = 'overdue'");
$overdue_count_stmt->execute([$shop_id]);
$overdue_count = (int)$overdue_count_stmt->fetchColumn();

// Sidebar badge: unread reports
$stmt_sidebar_unread = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE shop_id = ? AND is_read = 0");
$stmt_sidebar_unread->execute([$shop_id]);
$sidebar_unread_count = $stmt_sidebar_unread->fetchColumn();

// Sidebar badge: stuck payments
$stmt_sidebar_stuck = $pdo->prepare("SELECT (
    (SELECT COUNT(*) FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.shop_id = ? AND bp.razorpay_payment_id IS NOT NULL AND bp.is_settled_manually = 0) +
    (SELECT COUNT(*) FROM monthly_khata WHERE shop_id = ? AND razorpay_payment_id IS NOT NULL AND is_settled_manually = 0) +
    (SELECT COUNT(*) FROM payment_requests WHERE shop_id = ? AND razorpay_payment_id IS NOT NULL AND status = 'approved' AND is_settled_manually = 0)
) as total");
$stmt_sidebar_stuck->execute([$shop_id, $shop_id, $shop_id]);
$sidebar_stuck_count = $stmt_sidebar_stuck->fetchColumn();

// Today's Online Collection for Web
$today_online_web = $pdo->prepare("SELECT (
    COALESCE((SELECT SUM(amount_paid) FROM payment_history WHERE shop_id = ? AND DATE(payment_date) = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
    COALESCE((SELECT SUM(paid_amount) FROM monthly_khata WHERE shop_id = ? AND DATE(paid_at) = ? AND razorpay_payment_id IS NOT NULL AND razorpay_payment_id != 'Manual'), 0) +
    COALESCE((SELECT SUM(bp.amount_paid) FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.shop_id = ? AND DATE(bp.payment_date) = ? AND bp.razorpay_payment_id IS NOT NULL AND bp.razorpay_payment_id != 'Manual'), 0)
) as total");
$today_online_web->execute([$shop_id, $filter_date, $shop_id, $filter_date, $shop_id, $filter_date]);
$today_online_amount = (float)$today_online_web->fetchColumn();

$recent_customers = $pdo->prepare("
    SELECT c.*, sc.added_at,
    COALESCE(SUM(ue.total_remaining),0) as total_due
    FROM customers c
    JOIN shop_customers sc ON c.id = sc.customer_id
    JOIN udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id = ? AND ue.status = 'open'
    WHERE sc.shop_id = ?
    GROUP BY c.id
    HAVING total_due > 0
    ORDER BY sc.added_at DESC
    LIMIT 5
");
$recent_customers->execute([$shop_id, $shop_id]);
$recent_customers = $recent_customers->fetchAll();

$recent_payments = $pdo->prepare("
    SELECT ph.*, c.name as customer_name
    FROM payment_history ph
    JOIN customers c ON ph.customer_id = c.id
    WHERE ph.shop_id = ?
    ORDER BY ph.payment_date DESC
    LIMIT 5
");
$recent_payments->execute([$shop_id]);
$recent_payments = $recent_payments->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= htmlspecialchars($shop['shop_name']) ?></title>
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
            <i class="fas fa-store"></i>
            <?= htmlspecialchars($_SESSION['shop_name']) ?>
        </div>
    </div>
</nav>

<!-- Main Layout -->
<div class="min-h-[calc(100vh-64px)]">
    <div class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">

        <!-- App Banners Slider -->
        <?php
        $banners = $pdo->prepare("SELECT image_path FROM banners WHERE target IN ('shop', 'all') ORDER BY created_at DESC LIMIT 5");
        $banners->execute();
        $banner_list = $banners->fetchAll();
        if(!empty($banner_list)): ?>
        <div id="bannerSlider" class="relative w-full mb-8 overflow-hidden rounded-3xl shadow-xl shadow-slate-200" style="background:#f1f5f9;">
            <div id="bannerTrack" class="flex transition-transform duration-700 ease-in-out" style="width: <?= count($banner_list) * 100 ?>%;">
                <?php foreach($banner_list as $bl): ?>
                <div style="width: <?= 100 / count($banner_list) ?>%; flex-shrink: 0;">
                    <img src="<?= htmlspecialchars($bl['image_path']) ?>" style="width: 100%; height: auto; display: block; max-height: 320px; object-fit: cover;" loading="lazy">
                </div>
                <?php endforeach; ?>
            </div>
            <?php if(count($banner_list) > 1): ?>
            <div style="position:absolute; bottom:14px; left:0; right:0; display:flex; justify-content:center; gap:6px; z-index:10;">
                <?php foreach($banner_list as $i => $bl): ?>
                <div class="b-dot" data-index="<?= $i ?>" style="height:8px; width:<?= $i === 0 ? '22px' : '8px' ?>; border-radius:999px; background:<?= $i === 0 ? 'rgba(255,255,255,0.95)' : 'rgba(255,255,255,0.35)' ?>; transition:all 0.3s ease; cursor:pointer;"></div>
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
                    d.style.width      = i === cur ? '22px' : '8px';
                    d.style.background = i === cur ? 'rgba(255,255,255,0.95)' : 'rgba(255,255,255,0.35)';
                });
            }
            dots.forEach(function(d) { d.addEventListener('click', function() { goTo(parseInt(d.dataset.index)); }); });
            if(total > 1) setInterval(function() { goTo(cur + 1); }, 4000);
        })();
        </script>
        <?php endif; ?>

        <!-- Overdue Alert -->
        <?php if($overdue_count > 0): ?>
        <div class="bg-red-600 text-white p-4 md:p-6 rounded-[2rem] mb-8 shadow-xl shadow-red-200 flex items-center justify-between animate-pulse">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-white/20 rounded-2xl flex items-center justify-center text-xl"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <h4 class="font-black text-sm uppercase tracking-widest">Urgent Action Required</h4>
                    <p class="text-xs text-red-100 font-medium">Aapke <?= $overdue_count ?> customers ne kist miss ki hai. Kripya recovery check karein.</p>
                </div>
            </div>
            <a href="bonds.php" class="bg-white text-red-600 text-[10px] font-black px-6 py-2.5 rounded-xl uppercase tracking-widest shadow-lg">Check Now</a>
        </div>
        <?php endif; ?>

        <!-- Welcome Banner -->
        <div class="glass-banner rounded-3xl p-8 mb-8 text-white shadow-2xl shadow-slate-200">
            <div class="relative z-10 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-2">Welcome back, <?= htmlspecialchars($shop['name']) ?>! 👋</h1>
                    <p class="text-slate-400 text-sm md:text-base font-medium"><?= htmlspecialchars($shop['shop_name']) ?> • <span class="opacity-70"><?= htmlspecialchars($shop['shop_category']) ?></span></p>
                </div>
                <div class="hidden md:block">
                    <div class="w-16 h-16 bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl flex items-center justify-center text-3xl">
                        <i class="fas fa-store"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shop Status Control Panel -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm mb-8 flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full flex items-center justify-center text-xl <?= $is_open_now ? 'bg-emerald-100 text-emerald-600 animate-pulse' : 'bg-slate-100 text-slate-400' ?>">
                    <i class="fas fa-store"></i>
                </div>
                <div class="text-left">
                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Mall Visibility</div>
                    <div class="text-lg font-black <?= $is_open_now ? 'text-emerald-600' : 'text-slate-500' ?>"><?= $is_open_now ? 'ONLINE & SELLING' : 'OFFLINE / CLOSED' ?></div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase">Hours: <?= date('h:i A', strtotime($s_status['open_time'])) ?> - <?= date('h:i A', strtotime($s_status['close_time'])) ?></p>
                </div>
            </div>
            <div class="flex gap-2 w-full md:w-auto">
                <form method="POST" class="flex-1">
                    <input type="hidden" name="action" value="toggle_online">
                    <input type="hidden" name="status" value="<?= $s_status['is_online'] ? '0' : '1' ?>">
                    <button type="submit" class="w-full px-6 py-3 rounded-2xl font-black uppercase text-[10px] tracking-widest transition-all <?= $s_status['is_online'] ? 'bg-red-50 text-red-600 border border-red-100' : 'bg-emerald-600 text-white shadow-lg' ?>">
                        <?= $s_status['is_online'] ? 'Go Offline' : 'Go Online' ?>
                    </button>
                </form>
                <?php if(!$is_open_now || (date('H:i:s') > $s_status['close_time'])): ?>
                <form method="POST" class="flex-1">
                    <input type="hidden" name="action" value="extend_time">
                    <button type="submit" class="w-full bg-blue-600 text-white px-6 py-3 rounded-2xl font-black uppercase text-[10px] tracking-widest shadow-lg shadow-blue-100">
                        Open for 1 Hr
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Date Filter -->
        <form method="GET" class="bg-white border border-slate-200 rounded-2xl p-4 mb-6 flex items-center gap-4 shadow-sm">
            <div class="flex-1">
                <label for="filter_date" class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Filter Payout Date</label>
                <input type="date" id="filter_date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>"
                       class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all">
            </div>
            <button type="submit" class="bg-slate-900 text-white font-bold px-6 py-2.5 rounded-xl hover:bg-blue-600 transition-all text-[10px] uppercase tracking-widest">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all duration-300 group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center text-sm transition-colors group-hover:bg-blue-600 group-hover:text-white"><i class="fas fa-users"></i></div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Customer Base</span>
                </div>
                <div class="text-slate-500 text-xs font-semibold mb-1">Total Customers</div>
                <div class="text-2xl font-extrabold text-slate-900 tracking-tight"><?= number_format($total_customers) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all duration-300 group border-b-4 border-b-indigo-600">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center text-sm transition-colors group-hover:bg-indigo-600 group-hover:text-white"><i class="fas fa-bolt"></i></div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Payout (<?= date('d M', strtotime($filter_date)) ?>)</span>
                </div>
                <div class="text-slate-500 text-xs font-semibold mb-1">From KhataLink</div>
                <div class="text-2xl font-extrabold text-indigo-600 tracking-tight">₹<?= number_format($today_online_amount, 0) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all duration-300 group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 bg-red-50 text-red-600 rounded-xl flex items-center justify-center text-sm transition-colors group-hover:bg-red-600 group-hover:text-white"><i class="fas fa-rupee-sign"></i></div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Receivables</span>
                </div>
                <div class="text-slate-500 text-xs font-semibold mb-1">Total Due</div>
                <div class="text-2xl font-extrabold text-red-600 tracking-tight">₹<?= number_format($total_due, 0) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all duration-300 group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center text-sm transition-colors group-hover:bg-emerald-600 group-hover:text-white"><i class="fas fa-check-circle"></i></div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Revenue</span>
                </div>
                <div class="text-slate-500 text-xs font-semibold mb-1">Total Collected</div>
                <div class="text-2xl font-extrabold text-emerald-600 tracking-tight">₹<?= number_format($total_collected, 0) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all duration-300 group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 bg-amber-50 text-amber-600 rounded-xl flex items-center justify-center text-sm transition-colors group-hover:bg-amber-600 group-hover:text-white"><i class="fas fa-clock"></i></div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Active Khata</span>
                </div>
                <div class="text-slate-500 text-xs font-semibold mb-1">Open Entries</div>
                <div class="text-2xl font-extrabold text-slate-900 tracking-tight"><?= number_format($active_khata_count) ?></div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">

            <!-- Hero: Mall Dashboard -->
            <a href="Groceries_dashboard.php" class="col-span-2 bg-emerald-600 border border-emerald-500 rounded-3xl p-6 flex items-center justify-between group hover:shadow-xl hover:shadow-emerald-200 transition-all text-white relative overflow-hidden">
                <div class="relative z-10">
                    <h4 class="font-black text-lg uppercase tracking-tight">Mall Dashboard</h4>
                    <p class="text-emerald-100 text-xs font-medium">Manage marketplace orders & online sales</p>
                </div>
                <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center text-2xl group-hover:scale-110 transition-transform"><i class="fas fa-shopping-basket"></i></div>
                <div class="absolute -right-4 -bottom-4 text-white/5 text-8xl rotate-12"><i class="fas fa-leaf"></i></div>
            </a>

            <!-- Overview -->
            <a href="dashboard.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-blue-500 hover:bg-blue-50 transition-all group">
                <div class="w-11 h-11 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-chart-pie"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-blue-700">Overview</span>
            </a>

            <!-- Analytics -->
            <a href="analytics.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-pink-500 hover:bg-pink-50 transition-all group">
                <div class="w-11 h-11 bg-pink-100 text-pink-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-chart-line"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-pink-700">Analytics</span>
            </a>

            <!-- User Guide -->
            <a href="../guide.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-amber-500 hover:bg-amber-50 transition-all group">
                <div class="w-11 h-11 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-book"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-amber-700">User Guide</span>
            </a>

            <!-- My Customers -->
            <a href="customers.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-purple-500 hover:bg-purple-50 transition-all group">
                <div class="w-11 h-11 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-user-group"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-purple-700">My Customers</span>
            </a>

            <!-- Add Customer -->
            <a href="add_customer.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-blue-500 hover:bg-blue-50 transition-all group">
                <div class="w-11 h-11 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-user-plus"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-blue-700">Add Customer</span>
            </a>

            <!-- Voice POS -->
            <a href="voice_billing.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-rose-500 hover:bg-rose-50 transition-all group">
                <div class="w-11 h-11 bg-rose-100 text-rose-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-microphone"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-rose-700">Voice POS</span>
            </a>

            <!-- Inventory -->
            <a href="inventory.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-indigo-500 hover:bg-indigo-50 transition-all group">
                <div class="w-11 h-11 bg-indigo-100 text-indigo-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-boxes-stacked"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-indigo-700">Inventory</span>
            </a>

            <!-- POS History -->
            <a href="pos_history.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-orange-500 hover:bg-orange-50 transition-all group">
                <div class="w-11 h-11 bg-orange-100 text-orange-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-receipt"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-orange-700">POS History</span>
            </a>

            <!-- Trust Score CIBIL -->
            <a href="customer_cibil.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-teal-500 hover:bg-teal-50 transition-all group">
                <div class="w-11 h-11 bg-teal-100 text-teal-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-shield-check"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-teal-700">Trust Score (CIBIL)</span>
            </a>

            <!-- Market Orders COMING SOON -->
            <a href="javascript:void(0)" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 opacity-50 cursor-not-allowed">
                <div class="w-11 h-11 bg-slate-100 text-slate-400 rounded-xl flex items-center justify-center"><i class="fas fa-lock"></i></div>
                <span class="text-sm font-bold text-slate-500 text-center">Market Orders <span class="text-[8px] bg-amber-500 text-white px-1.5 py-0.5 rounded">COMING SOON</span></span>
            </a>

            <!-- Add Udhar -->
            <a href="udhar.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-emerald-500 hover:bg-emerald-50 transition-all group">
                <div class="w-11 h-11 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-file-invoice"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-emerald-700">Add Udhar</span>
            </a>

            <!-- Add Payment -->
            <a href="payment.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-amber-500 hover:bg-amber-50 transition-all group">
                <div class="w-11 h-11 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-rupee-sign"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-amber-700">Add Payment</span>
            </a>

            <!-- Bond System -->
            <a href="bonds.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-red-500 hover:bg-red-50 transition-all group">
                <div class="w-11 h-11 bg-red-100 text-red-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-file-contract"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-red-700">Bond System</span>
            </a>

            <!-- Monthly Khata -->
            <a href="monthly_khata.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-teal-500 hover:bg-teal-50 transition-all group">
                <div class="w-11 h-11 bg-teal-100 text-teal-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-calendar-check"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-teal-700">Monthly Khata</span>
            </a>

            <!-- Market History COMING SOON -->
            <a href="javascript:void(0)" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 opacity-50 cursor-not-allowed">
                <div class="w-11 h-11 bg-slate-100 text-slate-400 rounded-xl flex items-center justify-center"><i class="fas fa-lock"></i></div>
                <span class="text-sm font-bold text-slate-500 text-center">Market History <span class="text-[8px] bg-amber-500 text-white px-1.5 py-0.5 rounded">COMING SOON</span></span>
            </a>

            <!-- Tiers -->
            <a href="customer_tiers.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-yellow-500 hover:bg-yellow-50 transition-all group">
                <div class="w-11 h-11 bg-yellow-100 text-yellow-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-medal"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-yellow-700">Tiers</span>
            </a>

            <!-- Payment Setup with badge -->
            <a href="razorpay_guide.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-blue-500 hover:bg-blue-50 transition-all group">
                <div class="w-11 h-11 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-shield-check"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-blue-700 text-center">
                    Payment Setup
                    <?php if($sidebar_stuck_count > 0): ?>
                    <span class="inline-block text-[8px] bg-amber-500 text-white px-1.5 py-0.5 rounded ml-1"><?= $sidebar_stuck_count ?></span>
                    <?php endif; ?>
                </span>
            </a>

            <!-- Reports with badge -->
            <a href="reports.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-gray-500 hover:bg-gray-50 transition-all group">
                <div class="w-11 h-11 bg-gray-100 text-gray-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-flag"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-gray-700 text-center">
                    Reports
                    <?php if($sidebar_unread_count > 0): ?>
                    <span class="inline-block text-[8px] bg-red-500 text-white px-1.5 py-0.5 rounded ml-1"><?= $sidebar_unread_count ?></span>
                    <?php endif; ?>
                </span>
            </a>

            <!-- Custom Fields -->
            <a href="fields.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-purple-500 hover:bg-purple-50 transition-all group">
                <div class="w-11 h-11 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-sliders-h"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-purple-700">Custom Fields</span>
            </a>

            <!-- Today's Report -->
            <a href="export_daily_report.php" target="_blank" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-indigo-500 hover:bg-indigo-50 transition-all group">
                <div class="w-11 h-11 bg-indigo-100 text-indigo-700 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-print"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-indigo-700">Today's Report</span>
            </a>

            <!-- Settlements -->
            <a href="settlement_status.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-blue-500 hover:bg-blue-50 transition-all group border-b-4 border-b-blue-600">
                <div class="w-11 h-11 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-wallet"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-blue-700">Settlements</span>
            </a>

            <!-- Help & Support -->
            <a href="queries.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-indigo-500 hover:bg-indigo-50 transition-all group">
                <div class="w-11 h-11 bg-indigo-100 text-indigo-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-headset"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-indigo-700">Help & Support</span>
            </a>

            <!-- Settings / Profile -->
            <a href="profile.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-yellow-500 hover:bg-yellow-50 transition-all group">
                <div class="w-11 h-11 bg-yellow-100 text-yellow-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-cog"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-yellow-700">Settings</span>
            </a>

            <!-- Logout -->
            <a href="../auth/logout.php" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center gap-3 hover:border-slate-500 hover:bg-slate-50 transition-all group">
                <div class="w-11 h-11 bg-slate-100 text-slate-600 rounded-xl flex items-center justify-center transition-transform group-hover:scale-110"><i class="fas fa-sign-out-alt"></i></div>
                <span class="text-sm font-bold text-slate-700 group-hover:text-slate-700">Logout</span>
            </a>

        </div>

        <!-- Recent Tables -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Recent Customers -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                        <i class="fas fa-users text-blue-600"></i> Recent Customers
                    </h3>
                    <a href="customers.php" class="text-xs font-bold text-blue-600 hover:underline">Manage All</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-100">
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Customer</th>
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Outstanding Due</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if(count($recent_customers) > 0): ?>
                            <?php foreach($recent_customers as $c): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-4">
                                    <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($c['name']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-medium tracking-tight"><?= htmlspecialchars($c['unique_id']) ?></div>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <?php if($c['total_due'] > 0): ?>
                                    <span class="text-red-600 font-extrabold text-sm tracking-tight">₹<?= number_format($c['total_due'], 0) ?></span>
                                    <?php else: ?>
                                    <span class="text-emerald-600 font-bold text-xs">✓ Clear</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="2" class="px-5 py-10 text-center text-slate-400 text-sm font-medium italic">No customers yet</td></tr>
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
                    <a href="payment_history.php" class="text-xs font-bold text-blue-600 hover:underline">View All</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-100">
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Customer</th>
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if(count($recent_payments) > 0): ?>
                            <?php foreach($recent_payments as $p): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-4">
                                    <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($p['customer_name']) ?></div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="text-[11px] text-slate-500 font-bold uppercase tracking-tight"><?= date('d M Y', strtotime($p['payment_date'])) ?></div>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <span class="bg-emerald-50 text-emerald-700 px-2 py-1 rounded-lg font-black text-xs">₹<?= number_format($p['amount_paid'], 0) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="3" class="px-5 py-10 text-center text-slate-400 text-sm font-medium italic">No payments yet</td></tr>
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

<!-- Firebase SDKs -->
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

    firebase.initializeApp(firebaseConfig);
    const messaging = firebase.messaging();

    async function registerToken() {
        try {
            let registration = await navigator.serviceWorker.register('../firebase-messaging-sw.js');
            await navigator.serviceWorker.ready;
            const currentToken = await messaging.getToken({
                vapidKey: 'BGixP4kke2vi5l1mpqb_P-GI5xh2OM4KcPQ_8lzQmJqvdJXHG4xFpkYvexfpD_lX7LvBQ1ORR3asE1LQkFeWFHo',
                serviceWorkerRegistration: registration
            });
            if (currentToken) {
                console.log("FCM Token generated:", currentToken);
                const response = await fetch('ajax_save_token.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ fcm_token: currentToken })
                });
                const data = await response.json();
                if (data.success) {
                    console.log("FCM Token saved to DB successfully.");
                } else {
                    console.error("Failed to save FCM Token to DB:", data.message);
                }
            } else {
                console.warn("No FCM Token received.");
            }
        } catch (err) {
            console.error('An error occurred while retrieving or saving token: ', err);
        }
    }

    if (Notification.permission !== 'granted') {
        Notification.requestPermission().then((permission) => {
            if (permission === 'granted') { registerToken(); }
            else { console.warn("Notification permission denied by user."); }
        });
    } else {
        registerToken();
    }

    messaging.onMessage((payload) => {
        console.log('Foreground message received: ', payload);
        const title = payload.notification?.title || 'KhataLink Notification';
        const body = payload.notification?.body || '';
        const image = payload.notification?.image;
        if (Notification.permission === "granted") {
            const options = { body: body, icon: '../assets/favicon.png' };
            if (image) { options.image = image; }
            new Notification(title, options);
        }
    });
</script>
</body>
</html>