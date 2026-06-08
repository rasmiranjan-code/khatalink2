<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

$shop_id_filter = (int)($_GET['shop_id'] ?? 0);
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$all_shops = $pdo->query("SELECT id, shop_name FROM shop_owners ORDER BY shop_name ASC")->fetchAll();

// Master Query for Admin Grouped by Date & Shop (Settled Payments)
$query = "
    SELECT pay_date, shop_id, shop_name, bank_acc_no, bank_ifsc, SUM(base_amount) as amount, MAX(settled_at) as settled_at
    FROM (
        SELECT DATE(pr.created_at) as pay_date, s.id as shop_id, s.shop_name, s.bank_acc_no, s.bank_ifsc, pr.amount as base_amount, pr.created_at as settled_at
        FROM payment_requests pr JOIN shop_owners s ON pr.shop_id = s.id WHERE pr.razorpay_payment_id IS NOT NULL AND pr.status = 'approved' AND pr.is_settled_manually = 1
        UNION ALL
        SELECT DATE(mk.paid_at) as pay_date, s.id as shop_id, s.shop_name, s.bank_acc_no, s.bank_ifsc, mk.total_amount as base_amount, mk.paid_at as settled_at
        FROM monthly_khata mk JOIN shop_owners s ON mk.shop_id = s.id WHERE mk.razorpay_payment_id IS NOT NULL AND mk.status = 'closed' AND mk.is_settled_manually = 1
        UNION ALL
        SELECT DATE(bp.payment_date) as pay_date, s.id as shop_id, s.shop_name, s.bank_acc_no, s.bank_ifsc, bp.amount_paid as base_amount, bp.payment_date as settled_at
        FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id JOIN shop_owners s ON b.shop_id = s.id WHERE bp.razorpay_payment_id IS NOT NULL AND bp.payment_status = 'completed' AND bp.is_settled_manually = 1
    ) as master_list
    WHERE pay_date BETWEEN ? AND ?
";

$params = [$from_date, $to_date];
if ($shop_id_filter > 0) {
    $query .= " AND shop_id = ? ";
    $params[] = $shop_id_filter;
}

$query .= "
    GROUP BY pay_date, shop_id
    ORDER BY pay_date DESC, shop_name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$settled_payouts = $stmt->fetchAll();

// Total settled amount for stats
$total_settled_amount = array_sum(array_column($settled_payouts, 'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settlement History — KhataLink Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 p-4 md:p-8">
    <div class="max-w-6xl mx-auto">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-2xl font-black text-slate-900">Settlement History</h1>
                <p class="text-slate-500 text-sm">Review all past payouts to shops.</p>
            </div>
            <div class="flex gap-3 w-full md:w-auto">
                <a href="settlement_management.php" class="bg-slate-900 text-white p-3 rounded-xl hover:bg-slate-800 transition-all text-sm font-bold uppercase tracking-widest flex items-center justify-center gap-2">
                    <i class="fas fa-calendar-check"></i> Pending
                </a>
                <a href="dashboard.php" class="bg-slate-100 text-slate-600 p-3 rounded-xl hover:bg-slate-200 transition-all"><i class="fas fa-home"></i></a>
            </div>
        </div>

        <!-- Stats for History -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Settled Amount (<?= date('d M Y', strtotime($from_date)) ?> - <?= date('d M Y', strtotime($to_date)) ?>)</div>
                    <div class="text-2xl font-black text-emerald-600">₹<?= number_format($total_settled_amount, 2) ?></div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div class="text-[10px] font-black text-emerald-600 uppercase tracking-widest">
                        <?= count($settled_payouts) ?> Payouts
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm mb-8">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2">From Date</label>
                    <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" class="w-full bg-slate-50 border-2 border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold shadow-sm outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2">To Date</label>
                    <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" class="w-full bg-slate-50 border-2 border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold shadow-sm outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2">Shop</label>
                    <select name="shop_id" class="w-full bg-white border-2 border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold shadow-sm outline-none focus:border-blue-500">
                        <option value="0">All Shops</option>
                        <?php foreach($all_shops as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= $shop_id_filter == $sh['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sh['shop_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-slate-900 text-white p-3 rounded-xl hover:bg-slate-800 transition-all text-sm font-bold uppercase tracking-widest flex items-center justify-center gap-2">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="settlement_history.php" class="bg-slate-100 text-slate-600 p-3 rounded-xl hover:bg-slate-200 transition-all"><i class="fas fa-sync"></i></a>
                </div>
            </form>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm shadow-slate-200/50">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Collection Date</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Shop & Bank Details</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Net Payout</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Settled On</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($settled_payouts as $p): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-5">
                                <div class="text-sm font-black text-slate-900"><?= date('d M Y', strtotime($p['pay_date'])) ?></div>
                                <div class="text-[9px] font-bold text-slate-400 uppercase tracking-tight"><?= date('l', strtotime($p['pay_date'])) ?></div>
                            </td>
                            <td class="px-6 py-5">
                                <div class="text-sm font-bold text-slate-900"><?= htmlspecialchars($p['shop_name']) ?></div>
                                <div class="flex items-center gap-2 mt-1">
                                    <div class="text-[9px] text-slate-500 font-mono bg-slate-100 px-2 py-0.5 rounded italic">A/C: <?= $p['bank_acc_no'] ?: 'NOT SET' ?></div>
                                    <div class="text-[9px] text-slate-500 font-mono bg-slate-100 px-2 py-0.5 rounded uppercase"><?= $p['bank_ifsc'] ?: 'N/A' ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="text-lg font-black text-emerald-600">₹<?= number_format($p['amount'], 2) ?></div>
                                <div class="text-[8px] font-bold text-slate-400 uppercase tracking-widest">100% Base Payout</div>
                            </td>
                            <td class="px-6 py-5 text-center">
                                <div class="text-sm font-black text-emerald-600"><?= date('d M Y', strtotime($p['settled_at'])) ?></div>
                                <div class="text-[9px] font-bold text-slate-400 uppercase tracking-tight"><?= date('l, h:i A', strtotime($p['settled_at'])) ?></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($settled_payouts)): ?>
                            <tr><td colspan="4" class="py-20 text-center text-slate-300 font-bold uppercase tracking-widest text-xs italic">No settled payouts found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    function openSidebar() { document.getElementById('sidebar')?.classList.add('open'); document.getElementById('overlay')?.classList.add('show'); }
    function closeSidebar() { document.getElementById('sidebar')?.classList.remove('open'); document.getElementById('overlay')?.classList.remove('show'); }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>