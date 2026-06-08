<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['delivery_id'])) {
    header("Location: ../auth/login.php?type=delivery");
    exit();
}

$delivery_id = $_SESSION['delivery_id'];

// Fetch All Assigned/Delivered Orders for this Partner
$stmt = $pdo->prepare("
    SELECT o.*, s.shop_name, c.name as customer_name
    FROM orders o
    JOIN shop_owners s ON o.shop_id = s.id
    JOIN customers c ON o.customer_id = c.id
    WHERE o.delivery_boy_id = ?
    ORDER BY o.created_at DESC
");
$stmt->execute([$delivery_id]);
$orders = $stmt->fetchAll();

function getStatusBadge(string $status): string {
    $config = [
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
    <title>My Delivery History — KhataLink Partner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-6 shadow-sm">
    <a href="dashboard.php" class="flex items-center gap-2"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"><span class="text-[9px] font-black bg-blue-600 text-white px-2 py-1 rounded-md uppercase">History</span></a>
    <a href="dashboard.php" class="text-slate-400 font-black text-[10px] uppercase tracking-widest"><i class="fas fa-arrow-left"></i> Home</a>
</nav>

<main class="p-4 md:p-8 max-w-4xl mx-auto w-full">
    <div class="mb-10">
        <h1 class="text-3xl font-black text-slate-900">Delivery History</h1>
        <p class="text-slate-500 text-sm">Review your past performance and completed tasks.</p>
    </div>

    <div class="space-y-4">
        <?php if(empty($orders)): ?>
            <div class="text-center py-20 bg-white border border-dashed border-slate-200 rounded-[2.5rem]">
                <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No deliveries to show</p>
            </div>
        <?php else: foreach($orders as $o): ?>
            <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm flex flex-col gap-4">
                <div class="flex justify-between items-start">
                    <div>
                        <h3 class="text-sm font-black text-slate-900"><?= htmlspecialchars($o['shop_name']) ?> <i class="fas fa-arrow-right mx-1 text-slate-300"></i> <?= htmlspecialchars($o['customer_name']) ?></h3>
                        <p class="text-[9px] text-slate-400 font-bold uppercase mt-1">Date: <?= date('d M Y', strtotime($o['created_at'])) ?></p>
                    </div>
                    <?= getStatusBadge($o['order_status']) ?>
                </div>
                
                <div class="bg-slate-50 rounded-2xl p-4 flex justify-between items-center">
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Cash Collected</p>
                        <p class="text-xs font-black text-slate-900">₹<?= number_format($o['total_amount'], 2) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-[8px] font-black text-blue-400 uppercase tracking-widest">Your Earnings</p>
                        <p class="text-xs font-black text-blue-600">₹<?= number_format($o['delivery_fee'], 2) ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Bottom Navigation Space -->
    <div class="h-20 lg:hidden"></div>
</main>

<div class="fixed bottom-0 left-0 w-full bg-white border-t border-slate-200 p-4 flex justify-around items-center lg:hidden">
    <a href="dashboard.php" class="text-slate-400"><i class="fas fa-home text-xl"></i></a>
    <a href="order_history.php" class="text-blue-600"><i class="fas fa-history text-xl"></i></a>
    <a href="earnings.php" class="text-slate-400"><i class="fas fa-wallet text-xl"></i></a>
    <a href="profile.php" class="text-slate-400"><i class="fas fa-user text-xl"></i></a>
</div>

</body>
</html>