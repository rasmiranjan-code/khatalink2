<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';

if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

$date_filter = $_GET['date'] ?? date('Y-m-d');
$shop_filter = (int)($_GET['shop_id'] ?? 0);

$where_shop = $shop_filter > 0 ? " AND s.id = $shop_filter" : "";

// Fetch all shops for filter dropdown
$all_shops = $pdo->query("SELECT id, shop_name FROM shop_owners ORDER BY shop_name ASC")->fetchAll();

// 1. FETCH LEDGER PAYMENTS (payment_requests table)
$stmt_ledger = $pdo->prepare("
    SELECT pr.amount as customer_paid, s.shop_name, c.name as customer_name, pr.created_at, pr.razorpay_payment_id
    FROM payment_requests pr
    JOIN shop_owners s ON pr.shop_id = s.id
    JOIN customers c ON pr.customer_id = c.id
    WHERE pr.status = 'approved' AND DATE(pr.created_at) = ? AND pr.razorpay_payment_id IS NOT NULL
    $where_shop
");
$stmt_ledger->execute([$date_filter]);
$ledger_list = $stmt_ledger->fetchAll();

// 2. FETCH BOND PAYMENTS (bond_payments table)
// Note: bond_payments.amount_paid stores the SHOP'S amount. We calculate total and commission.
$stmt_bond = $pdo->prepare("
    SELECT bp.amount_paid as shop_payout, s.shop_name, c.name as customer_name, bp.payment_date, bp.razorpay_payment_id
    FROM bond_payments bp
    JOIN bonds b ON bp.bond_id = b.id
    JOIN shop_owners s ON b.shop_id = s.id
    JOIN customers c ON b.customer_id = c.id
    WHERE bp.payment_status = 'completed' AND DATE(bp.payment_date) = ?
    $where_shop
");
$stmt_bond->execute([$date_filter]);
$bond_list = $stmt_bond->fetchAll();

// 3. FETCH MONTHLY KHATA PAYMENTS
$stmt_monthly = $pdo->prepare("
    SELECT mk.total_amount as shop_payout, s.shop_name, c.name as customer_name, mk.created_at, mk.razorpay_payment_id
    FROM monthly_khata mk
    JOIN shop_owners s ON mk.shop_id = s.id
    JOIN customers c ON mk.customer_id = c.id
    WHERE mk.status = 'closed' AND DATE(mk.created_at) = ? AND mk.razorpay_payment_id IS NOT NULL
    $where_shop
");
$stmt_monthly->execute([$date_filter]);
$monthly_list = $stmt_monthly->fetchAll();

// CALCULATE TOTALS
$totals = ['customer' => 0, 'profit' => 0, 'payout' => 0, 'pg_fees' => 0];

// Process Ledger
foreach($ledger_list as &$l) {
    $base_amt = (float)$l['customer_paid']; // Data in DB is Base (100%)
    $cust_total = $base_amt * (1 + (LEDGER_PLATFORM_COMMISSION_PERCENT / 100));
    $pg_cost = $base_amt * (PG_FEE_PERCENT / 100);
    
    $l['payout'] = $base_amt; 
    $l['profit'] = $cust_total - $pg_cost - $base_amt;
    
    $totals['customer'] += $cust_total;
    $totals['pg_fees'] += $pg_cost;
    $totals['profit'] += $l['profit'];
    $totals['payout'] += $l['payout'];
}

// Process Bonds
foreach($bond_list as &$b) {
    $base_amt = (float)$b['shop_payout']; // Data in DB is Base
    $cust_total = $base_amt * (1 + (BOND_PLATFORM_COMMISSION_PERCENT / 100));
    $pg_cost = $base_amt * (PG_FEE_PERCENT / 100);
    
    $b['payout'] = $base_amt;
    $b['profit'] = $cust_total - $pg_cost - $base_amt;
    
    $totals['customer'] += $cust_total;
    $totals['pg_fees'] += $pg_cost;
    $totals['profit'] += $b['profit'];
    $totals['payout'] += $b['payout'];
}

// Process Monthly
foreach($monthly_list as &$m) {
    $base_amt = (float)$m['shop_payout']; // Data in DB is Base
    $cust_total = $base_amt * (1 + (MONTHLY_PLATFORM_COMMISSION_PERCENT / 100));
    $pg_cost = $base_amt * (PG_FEE_PERCENT / 100);

    $m['payout'] = $base_amt;
    $m['profit'] = $cust_total - $pg_cost - $base_amt;

    $totals['customer'] += $cust_total;
    $totals['pg_fees'] += $pg_cost;
    $totals['profit'] += $m['profit'];
    $totals['payout'] += $m['payout'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settlements — KhataLink Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 font-[Inter]">
    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <div class="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight">Daily Settlements</h1>
                <p class="text-slate-500 text-sm">Payout breakdown for date: <span class="font-bold text-blue-600"><?= date('d M Y', strtotime($date_filter)) ?></span></p>
            </div>
            <form method="GET" class="flex gap-2">
                <input type="date" name="date" value="<?= $date_filter ?>" class="bg-white border-2 border-slate-200 rounded-xl px-4 py-2 text-sm font-bold outline-none focus:border-blue-500">
                <select name="shop_id" class="bg-white border-2 border-slate-200 rounded-xl px-4 py-2 text-sm font-bold outline-none focus:border-blue-500">
                    <option value="0">All Shops</option>
                    <?php foreach($all_shops as $sh): ?>
                        <option value="<?= $sh['id'] ?>" <?= $shop_filter == $sh['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sh['shop_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="bg-slate-900 text-white px-6 py-2 rounded-xl font-bold text-xs uppercase tracking-widest">Filter</button>
            </form>
        </div>

        <!-- DAILY TOTALS SUMMARY -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Total Recv. from Customers</div>
                <div class="text-3xl font-black text-slate-900">₹<?= number_format($totals['customer'], 2) ?></div>
            </div>
            <div class="bg-blue-600 rounded-[2rem] p-6 shadow-xl shadow-blue-100">
                <div class="text-[10px] font-black text-blue-200 uppercase tracking-widest mb-2">Net KhataLink Profit (After PG Fees)</div>
                <div class="text-3xl font-black text-white">₹<?= number_format($totals['profit'], 2) ?></div>
            </div>
            <div class="bg-emerald-600 rounded-[2rem] p-6 shadow-xl shadow-emerald-100">
                <div class="text-[10px] font-black text-emerald-100 uppercase tracking-widest mb-2">Total Net Payout to Shops</div>
                <div class="text-3xl font-black text-white">₹<?= number_format($totals['payout'], 2) ?></div>
            </div>
        </div>

        <!-- FINANCIAL BREAKDOWN BOX -->
        <div class="bg-slate-900 rounded-[2.5rem] p-8 mb-10 text-white shadow-2xl relative overflow-hidden">
            <div class="relative z-10">
                <div class="text-[10px] font-black text-blue-400 uppercase tracking-[0.3em] mb-6 flex items-center gap-2">
                    <i class="fas fa-vault"></i> Platform Reconciliation Audit
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                    <div class="border-l-2 border-white/10 pl-6">
                        <div class="text-slate-400 text-[10px] font-bold uppercase mb-1">Gross Collected</div>
                        <div class="text-xl font-black">₹<?= number_format($totals['customer'], 2) ?></div>
                    </div>
                    <div class="border-l-2 border-red-500 pl-6">
                        <div class="text-red-400 text-[10px] font-bold uppercase mb-1">PG Charges (<?= PG_FEE_PERCENT ?>%)</div>
                        <div class="text-xl font-black text-red-400">- ₹<?= number_format($totals['pg_fees'], 2) ?></div>
                    </div>
                    <div class="border-l-2 border-emerald-500 pl-6">
                        <div class="text-emerald-400 text-[10px] font-bold uppercase mb-1">Merchant Payouts</div>
                        <div class="text-xl font-black text-emerald-400">₹<?= number_format($totals['payout'], 2) ?></div>
                    </div>
                    <div class="border-l-2 border-blue-500 pl-6">
                        <div class="text-blue-400 text-[10px] font-bold uppercase mb-1">Net KhataLink Profit</div>
                        <div class="text-2xl font-black text-blue-400">₹<?= number_format($totals['profit'], 2) ?></div>
                    </div>
                </div>
            </div>
            <i class="fas fa-chart-pie absolute -right-10 -bottom-10 text-[150px] opacity-5"></i>
        </div>
        <!-- ACTION CARDS -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <a href="reconcile_ledger.php" class="bg-white border border-slate-200 rounded-[2rem] p-8 hover:border-blue-500 hover:shadow-xl transition-all group">
                <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-6 group-hover:bg-blue-600 group-hover:text-white transition-all"><i class="fas fa-list-check"></i></div>
                <h3 class="font-black text-lg text-slate-900">Process Ledger Payouts</h3>
                <p class="text-slate-400 text-xs mt-1">Manual transfers for shop udhar collections.</p>
                <div class="mt-6 flex items-center gap-2 text-blue-600 font-bold text-xs uppercase tracking-widest">Open Center <i class="fas fa-arrow-right"></i></div>
            </a>
            <a href="reconcile_bonds.php" class="bg-white border border-slate-200 rounded-[2rem] p-8 hover:border-indigo-500 hover:shadow-xl transition-all group">
                <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center mb-6 group-hover:bg-indigo-600 group-hover:text-white transition-all"><i class="fas fa-file-contract"></i></div>
                <h3 class="font-black text-lg text-slate-900">Process Bond Payouts</h3>
                <p class="text-slate-400 text-xs mt-1">Manual transfers for security bond installments.</p>
                <div class="mt-6 flex items-center gap-2 text-indigo-600 font-bold text-xs uppercase tracking-widest">Open Center <i class="fas fa-arrow-right"></i></div>
            </a>
            <a href="reconcile_monthly.php" class="bg-white border border-slate-200 rounded-[2rem] p-8 hover:border-emerald-500 hover:shadow-xl transition-all group">
                <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center mb-6 group-hover:bg-emerald-600 group-hover:text-white transition-all"><i class="fas fa-calendar-check"></i></div>
                <h3 class="font-black text-lg text-slate-900">Process Monthly Payouts</h3>
                <p class="text-slate-400 text-xs mt-1">Manual transfers for recurring monthly khata.</p>
                <div class="mt-6 flex items-center gap-2 text-emerald-600 font-bold text-xs uppercase tracking-widest">Open Center <i class="fas fa-arrow-right"></i></div>
            </a>
            <a href="reconcile_mall.php" class="bg-white border border-slate-200 rounded-[2rem] p-8 hover:border-orange-500 hover:shadow-xl transition-all group">
                <div class="w-12 h-12 bg-orange-50 text-orange-600 rounded-2xl flex items-center justify-center mb-6 group-hover:bg-orange-600 group-hover:text-white transition-all"><i class="fas fa-store"></i></div>
                <h3 class="font-black text-lg text-slate-900">Mall Order Payouts</h3>
                <p class="text-slate-400 text-xs mt-1">Reconcile Groceries Mall marketplace sales.</p>
                <div class="mt-6 flex items-center gap-2 text-orange-600 font-bold text-xs uppercase tracking-widest">Open Center <i class="fas fa-arrow-right"></i></div>
            </a>
        </div>
        
        <div class="mt-12 p-8 bg-blue-50 rounded-[2rem] border-2 border-dashed border-blue-200 text-center">
            <p class="text-sm font-black text-blue-700 uppercase tracking-widest">Payout Instructions</p>
            <p class="text-xs text-blue-500 font-medium mt-1">Upar diye gaye <span class="font-bold">Shop Payout</span> amounts ko dukandaaro ke bank accounts mein transfer karein. Hamara commission hamare Razorpay dashboard mein pehle hi collect ho chuka hai.</p>
        </div>
    </div>
</body>
</html>