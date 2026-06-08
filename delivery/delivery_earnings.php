<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['delivery_id'])) { header("Location: ../auth/login.php?type=delivery"); exit(); }

$delivery_id = $_SESSION['delivery_id'];

// Fetch Earning History
$stmt = $pdo->prepare("
    SELECT dl.*, o.created_at as order_date, s.shop_name 
    FROM delivery_ledger dl
    JOIN orders o ON dl.order_id = o.id
    JOIN shop_owners s ON o.shop_id = s.id
    WHERE dl.delivery_boy_id = ?
    ORDER BY dl.created_at DESC
");
$stmt->execute([$delivery_id]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Earning History — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center px-6 gap-4 shadow-sm">
    <a href="dashboard.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
    <h1 class="text-sm font-black uppercase tracking-widest">My Earning History</h1>
</nav>

<main class="p-4 max-w-xl mx-auto">
    
    <div class="space-y-4">
        <?php if(empty($history)): ?>
            <div class="text-center py-20 bg-white rounded-[2rem] border border-dashed border-slate-200">
                <p class="text-slate-400 font-bold text-xs uppercase">No earnings recorded yet</p>
            </div>
        <?php endif; ?>

        <?php foreach($history as $h): ?>
        <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <h3 class="font-black text-slate-900 text-sm"><?= htmlspecialchars($h['shop_name']) ?></h3>
                    <p class="text-[10px] font-bold text-slate-400 uppercase"><?= date('d M Y, h:i A', strtotime($h['created_at'])) ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[8px] font-black text-emerald-500 uppercase">My Profit</p>
                    <p class="text-lg font-black text-emerald-600">+₹<?= number_format($h['commission_earned'], 2) ?></p>
                </div>
            </div>
            
            <div class="grid grid-cols-3 gap-2 pt-4 border-t border-slate-50">
                <div class="text-center">
                    <p class="text-[7px] font-black text-slate-400 uppercase">Cash Collected</p>
                    <p class="text-xs font-black text-slate-700">₹<?= number_format($h['cash_collected'], 2) ?></p>
                </div>
                <div class="text-center border-x border-slate-50">
                    <p class="text-[7px] font-black text-slate-400 uppercase">Paid to Shop</p>
                    <p class="text-xs font-black text-blue-600">₹<?= number_format($h['net_payable_to_shop'], 2) ?></p>
                </div>
                <div class="text-center">
                    <p class="text-[7px] font-black text-slate-400 uppercase">Platform Fee</p>
                    <?php $pf = $h['cash_collected'] - $h['commission_earned'] - $h['net_payable_to_shop']; ?>
                    <p class="text-xs font-black text-amber-600">₹<?= number_format($pf, 2) ?></p>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <span class="text-[8px] font-black px-2 py-1 rounded bg-slate-100 text-slate-400 uppercase">Order #<?= $h['order_id'] ?></span>
                <?php if($h['is_handed_over']): ?>
                    <span class="text-[8px] font-black text-emerald-600 uppercase flex items-center gap-1">
                        <i class="fas fa-check-circle"></i> Handed over to Shop
                    </span>
                <?php else: ?>
                    <span class="text-[8px] font-black text-amber-500 uppercase flex items-center gap-1">
                        <i class="fas fa-clock"></i> Cash with me
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</main>

</body>
</html>
