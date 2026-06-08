<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once '../includes/db.php';

// Date Filter handling
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');

// ===== FLUTTER API & AUTHENTICATION =====
$shop_id = 0;
$is_api = false;

if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    header('Content-Type: application/json');
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
    if($is_api) exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
    header("Location: ../auth/login.php?type=shop"); exit();
}

// ── MALL ACCESS CONTROL CHECK ──
$check_access = $pdo->prepare("SELECT is_mall_active FROM shop_owners WHERE id = ?");
$check_access->execute([$shop_id]);
$is_mall_active = (int)$check_access->fetchColumn();

if ($is_mall_active === 0) {
    if ($is_api) {
        ob_clean();
        exit(json_encode(['success' => false, 'deactivated' => true, 'message' => 'Your mall account has been deactivated by Khatalink team. Please contact Administration.']));
    }
    ?>
    <div class="min-h-screen bg-slate-900 flex items-center justify-center p-6 text-center">
        <div class="max-w-md bg-white rounded-[3rem] p-10 shadow-2xl border-t-8 border-red-600">
            <div class="w-20 h-20 bg-red-50 text-red-600 rounded-full flex items-center justify-center text-3xl mx-auto mb-6"><i class="fas fa-user-shield"></i></div>
            <h1 class="text-2xl font-black text-slate-900 mb-4">Mall Access Deactivated</h1>
            <p class="text-slate-500 font-medium mb-8">Your mall account has been deactivated by <span class="text-blue-600 font-bold text-sm uppercase">Khatalink team</span>. Please contact your Administration of KhataLink for more details.</p>
            <a href="dashboard.php" class="inline-block bg-slate-900 text-white px-8 py-3 rounded-2xl font-black uppercase text-[10px] tracking-widest shadow-lg">Back to Main Dashboard</a>
        </div>
    </div>
    <?php exit();
}

// ===== GROCERIES STATS ENGINE =====
// 1. Today's Revenue (Only count Online Delivered OR COD Handed Over)
$today_sales = $pdo->prepare("
    SELECT COALESCE(SUM(o.net_to_shop), 0) 
    FROM orders o 
    LEFT JOIN delivery_ledger dl ON o.id = dl.order_id
    WHERE o.shop_id = ? AND o.is_marketplace_order = 1 AND o.order_status = 'delivered' 
    AND DATE(o.created_at) = ?
    AND (o.payment_mode = 'Online' OR dl.is_handed_over = 1)
");
$today_sales->execute([$shop_id, $filter_date]);
$today_revenue = (float)$today_sales->fetchColumn();

// 1.5 Today's Online Revenue (For Expected Payout calculation)
$today_online = $pdo->prepare("SELECT COALESCE(SUM(net_to_shop), 0) FROM orders WHERE shop_id = ? AND is_marketplace_order = 1 AND order_status = 'delivered' AND payment_mode = 'Online' AND DATE(created_at) = ?");
$today_online->execute([$shop_id, $filter_date]);
$online_today_revenue = (float)$today_online->fetchColumn();

// Calculate Expected Settlement Date for the Filtered Date
$ts = strtotime($filter_date);
$dayOfWeek = date('N', $ts);
if ($dayOfWeek == 5) {
    $expected_payout = date('d M Y', strtotime($filter_date . ' + 3 days'));
} else {
    $target = strtotime($filter_date . ' + 2 days');
    if (date('N', $target) == 7) $target = strtotime(date('Y-m-d', $target) . ' + 1 day');
    $expected_payout = date('d M Y', $target);
}

// 2. Total Groceries Revenue (All time: Online Delivered + COD Handed Over)
$total_sales = $pdo->prepare("SELECT COALESCE(SUM(o.net_to_shop), 0) FROM orders o LEFT JOIN delivery_ledger dl ON o.id = dl.order_id WHERE o.shop_id = ? AND o.is_marketplace_order = 1 AND o.order_status = 'delivered' AND (o.payment_mode = 'Online' OR dl.is_handed_over = 1)");
$total_sales->execute([$shop_id]);
$total_revenue = (float)$total_sales->fetchColumn();

// 3. Pending Settlement (Strictly Online Delivered but not yet settled by Admin)
$pending_stmt = $pdo->prepare("SELECT COALESCE(SUM(net_to_shop), 0) FROM orders WHERE shop_id = ? AND is_marketplace_order = 1 AND order_status = 'delivered' AND payment_mode = 'Online' AND is_settled_manually = 0");
$pending_stmt->execute([$shop_id]);
$pending_settlement = (float)$pending_stmt->fetchColumn();

// 4. Fetch Recent Marketplace Orders
$stmt_orders = $pdo->prepare("
    SELECT o.*, c.name as customer_name, s.average_rating, s.total_ratings_count
    FROM orders o 
    JOIN customers c ON o.customer_id = c.id 
    JOIN shop_owners s ON o.shop_id = s.id
    WHERE o.shop_id = ? AND o.is_marketplace_order = 1 
    ORDER BY o.created_at DESC LIMIT 20
");
$stmt_orders->execute([$shop_id]);
$orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);

if($is_api) {
    echo json_encode([
        'success' => true,
        'stats' => [
            'today_revenue' => $today_revenue,
            'total_revenue' => $total_revenue,
            'pending_settlement' => $pending_settlement,
            'next_payout_date' => $expected_payout
        ],
        'orders' => $orders
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Groceries Dashboard — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .bg-grocery { background-color: #f0fdf4; } /* Very light green */
    </style>
</head>
<body class="bg-grocery text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-emerald-100 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <a href="dashboard.php" class="text-slate-400 hover:text-emerald-600"><i class="fas fa-arrow-left"></i></a>
        <h1 class="text-lg font-black text-emerald-700 uppercase tracking-tight">Mall Dashboard</h1>
    </div>
    <div class="bg-emerald-600 text-white text-[10px] font-black px-3 py-1.5 rounded-full uppercase">Groceries Active</div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/shop_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">
        
        <!-- Date Filter Form -->
        <form method="GET" class="bg-white border border-emerald-100 rounded-2xl p-4 mb-8 flex flex-wrap items-end gap-4 shadow-sm">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Check Sale & Payout Date</label>
                <input type="date" name="filter_date" value="<?= $filter_date ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2 text-sm font-bold outline-none focus:border-emerald-500">
            </div>
            <button type="submit" class="bg-emerald-600 text-white px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg hover:bg-emerald-700 transition-all">
                Update View
            </button>
            <?php if($filter_date != date('Y-m-d')): ?>
                <a href="Groceries_dashboard.php" class="bg-slate-100 text-slate-500 px-4 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest">Reset</a>
            <?php endif; ?>
        </form>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <div class="bg-white p-6 rounded-[2rem] border border-emerald-100 shadow-sm">
                <div class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-1">Sales on <?= date('d M', strtotime($filter_date)) ?></div>
                <div class="text-3xl font-black text-slate-900">₹<?= number_format($today_revenue, 2) ?></div>
            </div>
            
            <div class="bg-emerald-600 p-6 rounded-[2rem] text-white shadow-xl shadow-emerald-100">
                <div class="text-[9px] font-black text-emerald-200 uppercase tracking-widest mb-1">Expected Online Payout</div>
                <div class="text-2xl font-black">₹<?= number_format($online_today_revenue, 2) ?></div>
                <div class="mt-1 text-[10px] font-bold text-emerald-100">Date: <?= $expected_payout ?></div>
                <div class="mt-2 pt-2 border-t border-emerald-500/50 text-[10px] font-bold text-white italic">Total Unsettled: ₹<?= number_format($pending_settlement, 2) ?></div>
            </div>

            <div class="bg-white p-6 rounded-[2rem] border border-emerald-100 shadow-sm">
                <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Life-time Revenue</div>
                <div class="text-3xl font-black text-emerald-600">₹<?= number_format($total_revenue, 2) ?></div>
                <div class="mt-2 text-[10px] font-bold text-slate-400">Successfully delivered</div>
            </div>
        </div>

        <!-- Marketplace Orders List -->
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-sm font-black uppercase tracking-widest text-slate-400">Mall Order History</h2>
            <span class="bg-white px-3 py-1 rounded-lg border border-emerald-100 text-[10px] font-black text-emerald-600 uppercase"><?= count($orders) ?> Orders</span>
        </div>

        <div class="space-y-4">
            <?php if(empty($orders)): ?>
                <div class="text-center py-20 bg-white rounded-[2.5rem] border border-dashed border-emerald-200">
                    <p class="text-slate-400 font-bold text-xs uppercase">No marketplace orders yet</p>
                </div>
            <?php endif; ?>

            <?php foreach($orders as $o): ?>
            <a href="Groceries_quick_orders.php?order_id=<?= $o['id'] ?>" class="block bg-white border border-emerald-50 p-6 rounded-[2.5rem] hover:border-emerald-500 transition-all shadow-sm group">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fas fa-shopping-basket"></i>
                        </div>
                        <div>
                            <h3 class="font-black text-slate-900"><?= htmlspecialchars($o['customer_name']) ?></h3>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">Order #<?= $o['id'] ?> • <?= date('d M, h:i A', strtotime($o['created_at'])) ?></p>
                            <?php if($o['average_rating'] > 0): ?>
                            <p class="text-[9px] font-bold text-amber-500 mt-1"><i class="fas fa-star me-1"></i> <?= number_format($o['average_rating'], 1) ?> (<?= $o['total_ratings_count'] ?> ratings)</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-lg font-black text-slate-900">₹<?= number_format($o['net_to_shop'], 2) ?></div>
                        <?php
                        $status_styles = [
                            'pending'   => 'bg-amber-100 text-amber-600',
                            'accepted'  => 'bg-blue-100 text-blue-600',
                            'packing'   => 'bg-indigo-100 text-indigo-600',
                            'ready_for_pickup' => 'bg-emerald-100 text-emerald-600',
                            'delivered' => 'bg-emerald-600 text-white',
                            'cancelled' => 'bg-red-100 text-red-600',
                            'rider_not_found' => 'bg-red-500 text-white'
                        ];
                        $cls = $status_styles[$o['order_status']] ?? 'bg-slate-100 text-slate-600';
                        ?>
                        <span class="inline-block px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest <?= $cls ?>">
                            <?= str_replace('_', ' ', $o['order_status']) ?>
                        </span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </main>
</div>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); }
</script>
</body>
</html>