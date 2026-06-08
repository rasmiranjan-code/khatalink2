<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['shop_id'])) { header("Location: ../auth/login.php"); exit(); }
$shop_id = $_SESSION['shop_id'];
$order_id = (int)($_GET['order_id'] ?? 0);

// Fetch Order Header
$stmt = $pdo->prepare("
    SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.full_address as customer_address
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ? AND o.shop_id = ?
");
$stmt->execute([$order_id, $shop_id]);
$order = $stmt->fetch();

if (!$order) { die("Order not found or access denied."); }

// Fetch Items
$stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt_items->execute([$order_id]);
$items = $stmt_items->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Kitchen KOT #<?= $order_id ?> — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; background: #f8fafc; }</style>
</head>
<body class="p-4 md:p-10">

<div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-between mb-8">
        <a href="restaurant_dashboard.php" class="text-slate-400 hover:text-slate-900 transition-all"><i class="fas fa-arrow-left"></i> Back to Kitchen</a>
        <button onclick="window.print()" class="bg-slate-900 text-white px-6 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest"><i class="fas fa-print me-2"></i> Print KOT</button>
    </div>

    <div class="bg-white rounded-[2.5rem] border border-slate-200 shadow-xl overflow-hidden">
        <!-- Header -->
        <div class="p-8 border-b border-slate-100 bg-slate-50/50">
            <div class="flex justify-between items-start">
                <div>
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight">Order #ORD-<?= $order_id ?></h1>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mt-1"><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></p>
                </div>
                <span class="bg-emerald-600 text-white px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest">
                    <?= htmlspecialchars($order['order_status']) ?>
                </span>
            </div>
        </div>

        <!-- Customer & Delivery -->
        <div class="p-8 grid grid-cols-1 md:grid-cols-2 gap-8 border-b border-slate-100">
            <div>
                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-3">Customer Information</h4>
                <div class="font-black text-slate-900"><?= htmlspecialchars($order['customer_name']) ?></div>
                <div class="text-xs font-bold text-blue-600 mt-1"><i class="fas fa-phone-alt me-1"></i> <?= htmlspecialchars($order['customer_phone']) ?></div>
            </div>
            <div>
                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-3">Delivery Address</h4>
                <p class="text-xs font-medium text-slate-600 leading-relaxed"><?= htmlspecialchars($order['delivery_apartment_house'] . ', ' . $order['delivery_village']) ?></p>
                <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase"><?= htmlspecialchars($order['delivery_block'] . ' · ' . $order['delivery_district']) ?></p>
            </div>
        </div>

        <!-- Item Table -->
        <div class="p-8">
            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-6">Food Items List</h4>
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[9px] font-black text-slate-300 uppercase tracking-widest border-b border-slate-50">
                        <th class="pb-4">Dish Name</th>
                        <th class="pb-4 text-center">Qty</th>
                        <th class="pb-4 text-right">Unit Price</th>
                        <th class="pb-4 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php foreach($items as $it): ?>
                    <tr>
                        <td class="py-5 text-sm font-black text-slate-900"><?= htmlspecialchars($it['item_name']) ?></td>
                        <td class="py-5 text-center text-sm font-black text-slate-500"><?= (float)$it['quantity'] ?></td>
                        <td class="py-5 text-right text-sm font-bold text-slate-400">₹<?= number_format($it['price_per_unit'], 2) ?></td>
                        <td class="py-5 text-right text-sm font-black text-slate-900">₹<?= number_format($it['total_price'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Bill Summary Footer -->
        <div class="bg-slate-900 p-8 text-white">
            <div class="flex justify-between items-center mb-2">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Subtotal (Items)</span>
                <span class="text-sm font-bold">₹<?= number_format($order['net_to_shop'], 2) ?></span>
            </div>
            <div class="flex justify-between items-center mb-6">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Commission/Fees</span>
                <span class="text-sm font-bold">Included in Net</span>
            </div>
            <div class="pt-6 border-t border-white/10 flex justify-between items-center">
                <span class="text-sm font-black uppercase tracking-widest">Total Payout</span>
                <span class="text-3xl font-black text-emerald-400">₹<?= number_format($order['net_to_shop'], 2) ?></span>
            </div>
            <p class="text-[8px] font-bold text-slate-500 uppercase tracking-widest text-center mt-8">KhataLink Kitchen Network — Thank you!</p>
        </div>
    </div>
</div>

</body>
</html>
