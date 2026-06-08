<?php
header('Access-Control-Allow-Origin: *'); // Allow all origins for development
header('Access-Control-Allow-Methods: POST, GET, OPTIONS'); // Specify allowed methods
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php'; // For platform fee constants

// ===== FLUTTER API =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    ob_clean();
    header('Content-Type: application/json');
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $customer_id_api = $parts[0] ?? 0;

    // 1. Fetch Aggregated Totals
    $stats = $pdo->prepare("
        SELECT 
            (SELECT COALESCE(SUM(total_amount), 0) FROM udhar_entries WHERE customer_id = ?) as total_spent,
            (
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
            ) as total_paid,
            (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND status = 'open') as total_due
    ");
    $stats->execute([$customer_id_api, $customer_id_api, $customer_id_api, $customer_id_api, $customer_id_api, $customer_id_api, $customer_id_api, $customer_id_api]);
    $totals_raw = $stats->fetch();

    $totals = [
        'total_spent' => (float)$totals_raw['total_spent'],
        'total_paid' => (float)$totals_raw['total_paid'],
        'total_due' => (float)$totals_raw['total_due']
    ];

    // 2. Fetch Shop-wise Statement Links
    $stmt_shops = $pdo->prepare("
        SELECT s.id as shop_id, s.shop_name, s.shop_category, s.name as owner_name, s.upi_id,
               (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = s.id AND customer_id = ? AND status = 'open') as current_due,
               (SELECT COUNT(*) FROM udhar_entries WHERE shop_id = s.id AND customer_id = ? AND status = 'open') as open_entries
        FROM shop_owners s
        JOIN shop_customers sc ON s.id = sc.shop_id
        WHERE sc.customer_id = ?
        ORDER BY current_due DESC
    ");
    $stmt_shops->execute([$customer_id_api, $customer_id_api, $customer_id_api]);
    $shops_raw = $stmt_shops->fetchAll();

    $shops = array_map(function($shop) {
        return [
            'shop_id' => (int)$shop['shop_id'],
            'shop_name' => $shop['shop_name'],
            'shop_category' => $shop['shop_category'],
            'owner_name' => $shop['owner_name'],
            'upi_id' => $shop['upi_id'],
            'current_due' => (float)$shop['current_due'],
            'open_entries' => (int)$shop['open_entries']
        ];
    }, $shops_raw);

    echo json_encode([
        'success' => true,
        'totals' => $totals,
        'shops' => $shops,
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

// 1. Fetch Aggregated Totals
$stats = $pdo->prepare("SELECT 
    (SELECT COALESCE(SUM(total_amount), 0) FROM udhar_entries WHERE customer_id = ?) as total_spent,
    (
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
    ) as total_paid,
    (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND status = 'open') as total_due
");
$stats->execute([$customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $customer_id, $customer_id]);
$totals = $stats->fetch();

// 3. Fetch Shop-wise Statement Links
$stmt_shops = $pdo->prepare("
    SELECT s.id as shop_id, s.shop_name, s.shop_category,
           (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = s.id AND customer_id = ? AND status = 'open') as current_due
    FROM shop_owners s
    JOIN shop_customers sc ON s.id = sc.shop_id
    WHERE sc.customer_id = ?
    ORDER BY current_due DESC
");
$stmt_shops->execute([$customer_id, $customer_id]);
$shops = $stmt_shops->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Statements — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass-banner { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="text-[10px] font-black text-amber-600 uppercase tracking-widest bg-amber-50 border border-amber-100 px-3 py-1.5 rounded-full">
        <i class="fas fa-file-invoice-dollar me-1"></i> Statements Center
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/customer_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        <!-- Header -->
        <div class="glass-banner rounded-3xl p-8 mb-8 text-white shadow-xl relative overflow-hidden">
            <div class="relative z-10">
                <h1 class="text-2xl md:text-3xl font-black tracking-tight mb-1">Financial Statements</h1>
                <p class="text-slate-400 text-sm">Analyze your spending patterns and manage your dues.</p>
            </div>
            <i class="fas fa-chart-line absolute -right-4 -bottom-4 text-8xl text-white/5 rotate-12"></i>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Purchases</div>
                <div class="text-2xl font-black text-slate-900">₹<?= number_format($totals['total_spent'], 2) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Payments</div>
                <div class="text-2xl font-black text-emerald-600">₹<?= number_format($totals['total_paid'], 2) ?></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Shop Breakdown -->
            <div class="lg:col-span-12">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm">
                    <h5 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-6">Shop-wise Dues</h5>
                    <div class="space-y-4">
                        <?php foreach($shops as $shop): ?>
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 flex items-center justify-between group hover:border-blue-500 transition-all">
                            <div class="min-w-0">
                                <div class="text-xs font-black text-slate-900 truncate"><?= htmlspecialchars($shop['shop_name']) ?></div>
                                <div class="text-[9px] font-bold text-slate-400 uppercase"><?= htmlspecialchars($shop['shop_category']) ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs font-black <?= $shop['current_due'] > 0 ? 'text-red-600' : 'text-emerald-600' ?>">₹<?= number_format($shop['current_due'], 2) ?></div>
                                <a href="ledger.php?shop=<?= urlencode($shop['shop_name']) ?>" class="text-[9px] font-black text-blue-500 uppercase hover:underline">View History</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Options -->
        <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-4">
             <a href="generate_full_statement.php" target="_blank" class="bg-indigo-50 border border-indigo-100 rounded-3xl p-6 flex items-center justify-between group hover:border-indigo-500 transition-all">
                <div>
                    <h4 class="font-black text-indigo-900 text-sm uppercase tracking-widest">Full History Export</h4>
                    <p class="text-xs text-indigo-600 mt-1">Download your entire transaction history in PDF format.</p>
                </div>
                <div class="w-12 h-12 bg-indigo-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-200 group-hover:scale-110 transition-transform">
                    <i class="fas fa-file-pdf"></i>
                </div>
             </a>
             <a href="export_monthly_summary.php" class="bg-emerald-50 border border-emerald-100 rounded-3xl p-6 flex items-center justify-between group hover:border-emerald-500 transition-all">
                <div>
                    <h4 class="font-black text-emerald-900 text-sm uppercase tracking-widest">Monthly Summary</h4>
                    <p class="text-xs text-emerald-600 mt-1">Export current month's spending as an Excel sheet.</p>
                </div>
                <div class="w-12 h-12 bg-emerald-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-emerald-200 group-hover:scale-110 transition-transform">
                    <i class="fas fa-file-excel"></i>
                </div>
             </a>
        </div>
    </main>
</div>

<footer class="bg-white border-t border-slate-200 py-6 text-center mt-8">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">© <?= date('Y') ?> KhataLink — Premium Digital Ledger</div>
</footer>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }
</script>

</body>
</html>
