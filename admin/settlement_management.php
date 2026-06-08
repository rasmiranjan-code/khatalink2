<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

$shop_id_filter = (int)($_GET['shop_id'] ?? 0);
$filter_date = $_GET['filter_date'] ?? date('Y-m-d'); // Default to today
$all_shops = $pdo->query("SELECT id, shop_name FROM shop_owners ORDER BY shop_name ASC")->fetchAll();

// Master Query for Admin Grouped by Date & Shop (T+2 Logic)
$query = "
    SELECT pay_date, shop_id, shop_name, bank_acc_no, bank_ifsc, SUM(base_amount) as amount
    FROM (
        SELECT DATE(pr.created_at) as pay_date, s.id as shop_id, s.shop_name, s.bank_acc_no, s.bank_ifsc, pr.amount as base_amount, pr.is_settled_manually as status 
        FROM payment_requests pr JOIN shop_owners s ON pr.shop_id = s.id WHERE pr.razorpay_payment_id IS NOT NULL AND pr.status = 'approved'
        UNION ALL
        SELECT DATE(mk.paid_at) as pay_date, s.id as shop_id, s.shop_name, s.bank_acc_no, s.bank_ifsc, mk.total_amount as base_amount, mk.is_settled_manually as status 
        FROM monthly_khata mk JOIN shop_owners s ON mk.shop_id = s.id WHERE mk.razorpay_payment_id IS NOT NULL AND mk.status = 'closed'
        UNION ALL
        SELECT DATE(bp.payment_date) as pay_date, s.id as shop_id, s.shop_name, s.bank_acc_no, s.bank_ifsc, bp.amount_paid as base_amount, bp.is_settled_manually as status 
        FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id JOIN shop_owners s ON b.shop_id = s.id WHERE bp.razorpay_payment_id IS NOT NULL AND bp.payment_status = 'completed'
    ) as master_list
    WHERE status = 0 
";

$params = [];
if ($shop_id_filter > 0) {
    $query .= " AND shop_id = ? ";
    $params[] = $shop_id_filter;
}

// Filter by pay_date for pending settlements
$query .= " AND pay_date = ? ";
$params[] = $filter_date;

$query .= "
    GROUP BY pay_date, shop_id
    ORDER BY pay_date ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payouts = $stmt->fetchAll();

// Calculate total pending payout for the filtered date
$total_pending_payout = array_sum(array_column($payouts, 'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Master Payout Management — KhataLink Admin</title>
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
                <h1 class="text-2xl font-black text-slate-900">Settlement Management</h1>
                <p class="text-slate-500 text-sm">Consolidated T+2 payout schedule with Sunday Skip logic.</p>
            </div>
            <div class="flex flex-col md:flex-row gap-3 w-full md:w-auto">
                <form method="GET" class="flex gap-3 w-full">
                    <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" class="flex-1 bg-white border-2 border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold shadow-sm outline-none focus:border-blue-500">
                    <select name="shop_id" class="flex-1 bg-white border-2 border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold shadow-sm outline-none focus:border-blue-500">
                        <option value="0">All Shops</option>
                        <?php foreach($all_shops as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= $shop_id_filter == $sh['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sh['shop_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="bg-slate-900 text-white p-3 rounded-xl hover:bg-slate-800 transition-all"><i class="fas fa-filter"></i></button>
                    <a href="settlement_management.php" class="bg-slate-100 text-slate-600 p-3 rounded-xl hover:bg-slate-200 transition-all"><i class="fas fa-sync"></i></a>
                </form>
                <a href="settlement_history.php" class="bg-blue-600 text-white p-3 rounded-xl hover:bg-blue-700 transition-all text-sm font-bold uppercase tracking-widest flex items-center justify-center gap-2">
                    <i class="fas fa-history"></i> History
                </a>
            </div>
        </div>

        <!-- Stats for Filtered Date -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Pending Payout for <?= date('d M Y', strtotime($filter_date)) ?></div>
                    <div class="text-2xl font-black text-red-600">₹<?= number_format($total_pending_payout, 2) ?></div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="text-[10px] font-black text-red-600 uppercase tracking-widest">
                        <?= count($payouts) ?> Shops Pending
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm shadow-slate-200/50">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Expected Payout Date</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Shop & Bank Details</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Net Amount</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($payouts as $p): 
                            // T+2 Logic with Sunday Skip
                            $ts = strtotime($p['pay_date'] . ' + 2 days');
                            if(date('N', $ts) == 7) { 
                                $ts = strtotime(date('Y-m-d', $ts) . ' + 1 day'); 
                            }
                            $due_on = date('d M Y', $ts);
                            $due_day = date('l', $ts);
                            $is_today = (date('Y-m-d', $ts) <= date('Y-m-d'));
                        ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-5">
                                <div class="text-sm font-black <?= $is_today ? 'text-red-600' : 'text-slate-900' ?>"><?= $due_on ?></div>
                                <div class="text-[9px] font-bold text-slate-400 uppercase tracking-tight"><?= $due_day ?> · Collected: <?= date('d M', strtotime($p['pay_date'])) ?></div>
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
                                <button onclick="triggerSettlement(<?= $p['shop_id'] ?>, '<?= $p['pay_date'] ?>', <?= $p['amount'] ?>)" 
                                        class="bg-slate-900 text-white text-[9px] font-black px-5 py-2.5 rounded-xl uppercase tracking-widest shadow-lg hover:bg-blue-600 transition-all active:scale-95">
                                    Transfer & Settle
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($payouts)): ?>
                            <tr><td colspan="4" class="py-20 text-center text-slate-300 font-bold uppercase tracking-widest text-xs italic">No pending online settlements found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="mt-8 bg-blue-50 border border-blue-100 p-6 rounded-[2rem] flex gap-4 items-center">
            <div class="w-10 h-10 bg-blue-600 text-white rounded-xl flex items-center justify-center text-sm"><i class="fas fa-info-circle"></i></div>
            <p class="text-[11px] text-blue-700 font-medium leading-relaxed">
                <b>Admin Protocol:</b> Jab aap "Transfer & Settle" click karenge, toh teeno modules (Ledger, Bonds, Monthly) ka us date ka data lock ho jayega aur Shop Owner ko unke browser/mobile par real-time notification mil jayega.
            </p>
        </div>
    </div>

    <script>
    async function triggerSettlement(shopId, date, amount) {
        if(!confirm(`Settle ₹${amount} for this shop and send success notification?`)) return;
        
        try {
            const res = await fetch('ajax_trigger_settlement.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `shop_id=${shopId}&pay_date=${date}&amount=${amount}`
            });
            const data = await res.json();
            if(data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        } catch (e) {
            alert("Failed to process. Check internet.");
        }
    }

    function openSidebar() { document.getElementById('sidebar')?.classList.add('open'); document.getElementById('overlay')?.classList.add('show'); }
    function closeSidebar() { document.getElementById('sidebar')?.classList.remove('open'); document.getElementById('overlay')?.classList.remove('show'); }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
