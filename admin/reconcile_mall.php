<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

// Handle Manual Settlement Toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['order_id'])) {
    $oid = (int)$_POST['order_id'];
    $status = (int)$_POST['status'];
    $pdo->prepare("UPDATE orders SET is_settled_manually = ? WHERE id = ?")->execute([$status, $oid]);
    header("Location: reconcile_mall.php?success=Order payout status updated.");
    exit();
}

// ── NEW: FILTERS ──
$date_filter = $_GET['date'] ?? date('Y-m-d');
$shop_filter = (int)($_GET['shop_id'] ?? 0);

// Fetch all shops for filter
$all_shops = $pdo->query("SELECT id, shop_name FROM shop_owners ORDER BY shop_name ASC")->fetchAll();

// Fetch Pending Marketplace Settlements
$where_clause = "WHERE o.is_marketplace_order = 1 
                 AND o.order_status = 'delivered' 
                 AND o.payment_mode = 'Online' 
                 AND DATE(o.created_at) = ?";
$params = [$date_filter];

if ($shop_filter > 0) {
    $where_clause .= " AND o.shop_id = ?";
    $params[] = $shop_filter;
}

$query = "SELECT o.*, s.shop_name, s.bank_acc_no, s.bank_ifsc, c.name as customer_name 
          FROM orders o 
          JOIN shop_owners s ON o.shop_id = s.id 
          JOIN customers c ON o.customer_id = c.id 
          $where_clause ORDER BY o.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$totals = ['held' => 0, 'profit' => 0, 'delivery' => 0, 'count' => 0];
foreach($orders as $o) {
    if(!$o['is_settled_manually']) {
        $totals['held'] += (float)$o['net_to_shop'];
        $totals['delivery'] += (float)$o['delivery_fee'];
        // Profit = Total Paid - (Payout + Delivery)
        $totals['profit'] += ((float)$o['total_amount'] - (float)$o['net_to_shop'] - (float)$o['delivery_fee']);
        $totals['count']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mall Settlements — KhataLink Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="text-slate-400"><i class="fas fa-chevron-left"></i></a>
            <h1 class="text-sm font-black uppercase tracking-widest text-slate-900">Mall Order Settlements</h1>
        </div>
        <div class="flex items-center gap-2">
            <?php if($shop_filter > 0 && !empty($orders)): ?>
                <a href="export_mall_payout_pdf.php?shop_id=<?= $shop_filter ?>&date=<?= $date_filter ?>" target="_blank" class="bg-indigo-600 text-white px-4 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all">
                    <i class="fas fa-file-pdf me-1"></i> Bulk Payout Receipt
                </a>
            <?php endif; ?>
            <div class="text-[10px] font-black bg-emerald-100 text-emerald-600 px-3 py-1 rounded-full uppercase tracking-tighter">Marketplace Engine</div>
        </div>
    </nav>

    <main class="p-8 max-w-7xl mx-auto">
        <div class="flex flex-col md:flex-row justify-between items-end mb-10 gap-6">
            <div>
                <h1 class="text-3xl font-black tracking-tight">Mall Payouts</h1>
                <p class="text-slate-500 text-sm">Reconcile marketplace sales for <span class="font-bold text-blue-600"><?= date('d M Y', strtotime($date_filter)) ?></span></p>
            </div>
            <form method="GET" class="flex gap-2">
                <input type="date" name="date" value="<?= $date_filter ?>" class="bg-white border-2 border-slate-200 rounded-xl px-4 py-2 text-sm font-bold outline-none focus:border-blue-500 transition-all">
                <select name="shop_id" class="bg-white border-2 border-slate-200 rounded-xl px-4 py-2 text-sm font-bold outline-none focus:border-blue-500 transition-all">
                    <option value="0">All Shops Summary</option>
                    <?php foreach($all_shops as $sh): ?>
                        <option value="<?= $sh['id'] ?>" <?= $shop_filter == $sh['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sh['shop_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="bg-slate-900 text-white px-6 py-2 rounded-xl font-bold text-xs uppercase tracking-widest">Filter</button>
            </form>
        </div>

        <!-- Stats Overview -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
            <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                <div class="text-[9px] font-black text-slate-400 uppercase mb-1">Total Payouts Pending</div>
                <div class="text-2xl font-black text-red-600">₹<?= number_format($totals['held'], 2) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                <div class="text-[9px] font-black text-slate-400 uppercase mb-1">Our Net Margin</div>
                <div class="text-2xl font-black text-blue-600">₹<?= number_format($totals['profit'], 2) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                <div class="text-[9px] font-black text-slate-400 uppercase mb-1">Delivery Fees Fund</div>
                <div class="text-2xl font-black text-slate-900">₹<?= number_format($totals['delivery'], 2) ?></div>
            </div>
            <div class="bg-slate-900 p-6 rounded-3xl text-white shadow-xl shadow-slate-200">
                <div class="text-[9px] font-black text-blue-400 uppercase mb-1">Pending Claims</div>
                <div class="text-2xl font-black"><?= $totals['count'] ?> Orders</div>
            </div>
        </div>

        <!-- Detailed Table -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Order Info</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Customer Paid</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Deductions</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Shop Payout</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Bank Details</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($orders as $o): 
                            $margin = (float)$o['total_amount'] - (float)$o['net_to_shop'] - (float)$o['delivery_fee'];
                        ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-5">
                                <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($o['shop_name']) ?></div>
                                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-tight">Order #<?= $o['id'] ?> · <?= date('d M', strtotime($o['created_at'])) ?></div>
                                <div class="text-[9px] text-blue-600 font-bold mt-1">By: <?= htmlspecialchars($o['customer_name']) ?></div>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="text-sm font-black text-slate-900">₹<?= number_format($o['total_amount'], 2) ?></div>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="text-[10px] font-bold text-red-500">Deliv: ₹<?= number_format($o['delivery_fee'], 2) ?></div>
                                <div class="text-[10px] font-bold text-blue-500">KL Margin: ₹<?= number_format($margin, 2) ?></div>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="text-lg font-black text-emerald-600">₹<?= number_format($o['net_to_shop'], 2) ?></div>
                                <div class="text-[8px] font-black text-slate-400 uppercase tracking-widest">To be Transferred</div>
                            </td>
                            <td class="px-6 py-5">
                                <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 min-w-[140px]">
                                    <div class="text-[9px] font-bold text-slate-600 uppercase">A/C: <?= $o['bank_acc_no'] ?: '<span class="text-red-500">MISSING</span>' ?></div>
                                    <div class="text-[9px] font-bold text-slate-400 uppercase"><?= $o['bank_ifsc'] ?: 'N/A' ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-5 text-center">
                                <form method="POST">
                                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $o['is_settled_manually'] ? 0 : 1 ?>">
                                    <?php if($o['is_settled_manually']): ?>
                                        <button type="submit" class="bg-emerald-50 text-emerald-600 px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest border border-emerald-100">
                                            <i class="fas fa-check-double me-1"></i> Settled
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="bg-slate-900 text-white px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest shadow-lg hover:bg-blue-600 transition-all">
                                            Mark Paid
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($orders)): ?>
                            <tr><td colspan="6" class="py-20 text-center text-slate-300 font-bold uppercase tracking-widest text-xs italic">No marketplace online orders found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-8 p-6 bg-amber-50 border-2 border-dashed border-amber-200 rounded-[2rem] flex gap-4 items-center">
            <div class="w-12 h-12 bg-amber-500 text-white rounded-2xl flex items-center justify-center text-xl shadow-lg"><i class="fas fa-shield-alt"></i></div>
            <div>
                <h4 class="font-black text-amber-800 uppercase text-xs tracking-widest">Settlement Integrity Protocol</h4>
                <p class="text-[11px] text-amber-700 font-medium leading-relaxed mt-1">
                    Marketplace orders ka <b>Total Amount</b> aapke admin account mein aata hai. Aapko dukan ko sirf <b>Shop Payout</b> amount transfer karna hai. Delivery Fee ka budget riders ke payouts ke liye reserve rahega.
                </p>
            </div>
        </div>
    </main>
</body>
</html>
