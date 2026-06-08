<?php
session_start();
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['customer_id'])) { header("Location: ../auth/login.php?type=customer"); exit(); }
$customer_id = $_SESSION['customer_id'];

// Fetch All Food Orders (Restaurant only)
$stmt = $pdo->prepare("
    SELECT o.*, s.shop_name, s.shop_category, s.average_rating, s.total_ratings_count
    FROM orders o 
    JOIN shop_owners s ON o.shop_id = s.id
    WHERE o.customer_id = ? AND s.shop_type = 'restaurant'
    ORDER BY o.created_at DESC
");
$stmt->execute([$customer_id]);
$orders = $stmt->fetchAll();

function getStatusColor(string $s): string {
    $colors = [
        'pending'   => 'bg-amber-50 text-amber-600',
        'delivered' => 'bg-emerald-50 text-emerald-600',
        'cancelled' => 'bg-red-50 text-red-600',
        'packing'   => 'bg-orange-50 text-orange-600'
    ];
    return $colors[$s] ?? 'bg-blue-50 text-blue-600';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Food Orders — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 py-4 px-6 flex items-center gap-4 shadow-sm">
    <a href="Food_home.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
    <h1 class="text-lg font-black uppercase tracking-tight text-orange-600">Food History</h1>
</nav>

<main class="p-4 md:p-8 max-w-3xl mx-auto">
    <?php if(empty($orders)): ?>
        <div class="text-center py-24 bg-white rounded-[2.5rem] border border-dashed border-slate-200">
            <div class="w-20 h-20 bg-orange-50 text-orange-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-4"><i class="fas fa-utensils"></i></div>
            <p class="text-slate-400 font-black text-xs uppercase tracking-widest">Aapne abhi tak koi khana order nahi kiya hai.</p>
            <a href="Food_home.php" class="mt-6 inline-block bg-orange-600 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest">Order Now</a>
        </div>
    <?php endif; ?>

    <div class="space-y-4">
        <?php foreach($orders as $o): ?>
        <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm hover:shadow-lg transition-all">
            <div class="flex justify-between items-start mb-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-orange-50 text-orange-600 rounded-2xl flex items-center justify-center text-lg"><i class="fas fa-hamburger"></i></div>
                    <div>
                        <h3 class="font-black text-slate-900 leading-none"><?= htmlspecialchars($o['shop_name']) ?></h3>
                        <p class="text-[9px] font-bold text-slate-400 uppercase mt-1.5"><?= date('d M, h:i A', strtotime($o['created_at'])) ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-lg font-black text-slate-900">₹<?= number_format($o['total_amount'], 2) ?></div>
                    <span class="inline-block px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest <?= getStatusColor($o['order_status']) ?>">
                        <?= str_replace('_', ' ', $o['order_status']) ?>
                    </span>
                </div>
            </div>
            <div class="pt-4 border-t border-slate-50 flex gap-2">
                <?php if($o['order_status'] !== 'delivered' && $o['order_status'] !== 'cancelled'): ?>
                    <a href="Food_order_tracking.php?order_id=<?= $o['id'] ?>" class="flex-1 bg-orange-600 text-white text-center py-3 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-orange-100">Track Order</a>
                <?php endif; ?>
                <a href="Food_order_details.php?id=<?= $o['id'] ?>" class="flex-1 bg-slate-100 text-slate-600 text-center py-3 rounded-xl text-[10px] font-black uppercase tracking-widest">Bill Details</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>
</body>
</html>
