<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

// Fetch all shops with Marketplace specific stats
$query = "
    SELECT s.id, s.shop_name, s.shop_category, s.is_mall_active, s.is_online,
           COUNT(o.id) as total_orders,
           SUM(CASE WHEN o.order_status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
           SUM(CASE WHEN o.order_status = 'delivered' THEN o.total_amount ELSE 0 END) as gross_sales,
           SUM(CASE WHEN o.order_status = 'delivered' THEN (o.total_amount - o.net_to_shop - o.delivery_fee) ELSE 0 END) as platform_profit
    FROM shop_owners s
    LEFT JOIN orders o ON s.id = o.shop_id AND o.is_marketplace_order = 1
    GROUP BY s.id
    ORDER BY gross_sales DESC
";
$shops = $pdo->query($query)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mall Shop Manager — KhataLink Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="text-slate-400"><i class="fas fa-chevron-left"></i></a>
            <h1 class="text-sm font-black uppercase tracking-widest text-slate-900">Mall Shop Manager</h1>
        </div>
    </nav>

    <main class="p-8 max-w-7xl mx-auto">
        <div class="mb-10">
            <h1 class="text-3xl font-black text-slate-900 tracking-tight">Marketplace Merchant Network</h1>
            <p class="text-slate-500 text-sm">Control shop visibility and monitor order performance across the Mall.</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Shop & Category</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Visibility</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Orders (Delivered)</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Gross Volume</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Our Profit</th>
                            <th class="px-6 py-4 text-center text-[9px] font-black text-slate-400 uppercase tracking-widest">Control</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($shops as $sh): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-5">
                                <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($sh['shop_name']) ?></div>
                                <div class="text-[10px] font-bold text-blue-500 uppercase tracking-widest"><?= htmlspecialchars($sh['shop_category']) ?></div>
                            </td>
                            <td class="px-6 py-5">
                                <?php if($sh['is_mall_active']): ?>
                                    <span class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-600 text-[8px] font-black uppercase border border-emerald-100">Listed</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 rounded-full bg-red-50 text-red-600 text-[8px] font-black uppercase border border-red-100">Banned</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-5 text-center">
                                <div class="text-xs font-black text-slate-900"><?= $sh['total_orders'] ?></div>
                                <div class="text-[9px] font-bold text-emerald-600 uppercase">Dlv: <?= $sh['delivered_count'] ?></div>
                            </td>
                            <td class="px-6 py-5 text-right font-black text-slate-900">₹<?= number_format($sh['gross_sales'], 2) ?></td>
                            <td class="px-6 py-5 text-right font-black text-blue-600">₹<?= number_format($sh['platform_profit'], 2) ?></td>
                            <td class="px-6 py-5 text-center">
                                <a href="mall_shop_details.php?id=<?= $sh['id'] ?>" class="bg-slate-900 text-white px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all">Inspect shops details</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>