<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];

// Fetch All Marketplace Orders for this Shop
$stmt = $pdo->prepare("
    SELECT o.*, c.name as customer_name, dp.name as db_name
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    LEFT JOIN delivery_partners dp ON o.delivery_boy_id = dp.id
    WHERE o.shop_id = ? AND o.is_deleted_shop = 0
    ORDER BY o.created_at DESC
");
$stmt->execute([$shop_id]);
$orders = $stmt->fetchAll();

function getStatusBadge(string $status): string {
    $config = [
        'pending'   => 'bg-slate-100 text-slate-600',
        'accepted'  => 'bg-blue-50 text-blue-600',
        'assigned'  => 'bg-amber-50 text-amber-600',
        'picked_up' => 'bg-purple-50 text-purple-600',
        'delivered' => 'bg-emerald-50 text-emerald-600',
        'cancelled' => 'bg-red-50 text-red-600'
    ];
    $cls = $config[$status] ?? 'bg-slate-100 text-slate-600';
    return '<span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest ' . $cls . '">' . $status . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Market Order History — KhataLink Shop</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-6 shadow-sm">
    <div class="flex items-center gap-4">
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"></a>
        <h2 class="text-sm font-black text-slate-900 uppercase tracking-widest">Market History</h2>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/shop_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">
        <div class="mb-10">
            <h1 class="text-3xl font-black text-slate-900">Order Logs</h1>
            <p class="text-slate-500 text-sm">Full list of marketplace orders handled by your shop.</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm shadow-slate-200/50">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Order ID</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Customer</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Delivery Partner</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php if(empty($orders)): ?>
                            <tr><td colspan="6" class="px-6 py-10 text-center text-slate-400 italic text-xs">No marketplace orders yet.</td></tr>
                        <?php else: foreach($orders as $o): ?>
                            <tr class="hover:bg-slate-50 transition-colors group">
                                <td class="px-6 py-4 font-black text-xs">#<?= $o['id'] ?></td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-bold text-slate-900"><?= htmlspecialchars($o['customer_name']) ?></div>
                                    <div class="text-[9px] text-slate-400 uppercase font-black"><?= $o['pincode'] ?></div>
                                </td>
                                <td class="px-6 py-4 text-[10px] font-bold text-slate-500"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                                <td class="px-6 py-4"><?= getStatusBadge($o['order_status']) ?></td>
                                <td class="px-6 py-4 text-[10px] font-black text-blue-600 uppercase"><?= $o['db_name'] ? htmlspecialchars($o['db_name']) : 'Not Assigned' ?></td>
                                <td class="px-6 py-4 text-right font-black text-sm">₹<?= number_format($o['total_amount'], 2) ?></td>
                                <td class="px-4 text-right">
                                    <button onclick="deleteShopOrder(<?= $o['id'] ?>)" class="text-slate-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all"><i class="fas fa-trash-alt"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }

async function deleteShopOrder(orderId) {
    if(!confirm("Hiding this order will only remove it from your logs. Proceed?")) return;
    try {
        const res = await fetch('ajax_delete_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        });
        const data = await res.json();
        if(data.success) {
            location.reload();
        } else {
            alert("Error: " + data.message);
        }
    } catch (e) { console.error(e); }
}
</script>
</body>
</html>