<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch promotions with performance stats
$promotions = $pdo->prepare("
    SELECT 
        p.*,
        (SELECT COUNT(*) FROM customer_promotions WHERE promotion_id = p.id) as total_sent,
        (SELECT COUNT(*) FROM customer_promotions WHERE promotion_id = p.id AND status = 'used') as total_used,
        (SELECT SUM(o.total_amount) FROM orders o JOIN customer_promotions cp ON o.id = cp.used_in_order_id WHERE cp.promotion_id = p.id) as revenue_generated
    FROM promotions p
    WHERE p.shop_id = ?
    ORDER BY p.created_at DESC
");
$promotions->execute([$shop_id]);
$promotions_data = $promotions->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promotions - KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
    </style>
</head>
<body>

<div class="flex min-h-screen">
    <?php include '../includes/shop_sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-10">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <div>
                <h1 class="text-3xl font-black text-slate-800">Promotions Engine</h1>
                <p class="text-sm text-slate-500">Create and manage targeted offers for your customers.</p>
            </div>
            <a href="create_promotion.php" class="bg-blue-600 text-white font-bold py-3 px-6 rounded-xl text-xs uppercase tracking-wider hover:bg-blue-700 transition-all shadow-lg shadow-blue-500/20">
                <i class="fas fa-plus mr-2"></i> Create New Offer
            </a>
        </div>

        <?php if (empty($promotions_data)): ?>
            <div class="text-center py-20 bg-white border border-slate-200 rounded-3xl shadow-sm">
                <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-6">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <h3 class="font-black text-slate-900 uppercase tracking-widest text-xs mb-2">No Promotions Found</h3>
                <p class="text-slate-400 text-xs font-medium">Click 'Create New Offer' to get started.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                <?php foreach ($promotions_data as $promo): 
                    $conversion_rate = $promo['total_sent'] > 0 ? round(($promo['total_used'] / $promo['total_sent']) * 100, 1) : 0;
                ?>
                <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm hover:shadow-lg hover:border-blue-300 transition-all">
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="text-lg font-black text-slate-800"><?= htmlspecialchars($promo['name']) ?></h3>
                        <?php
                            $status_class = 'bg-slate-100 text-slate-500';
                            if ($promo['status'] === 'active' && strtotime($promo['end_date']) >= time()) {
                                $status_class = 'bg-emerald-50 text-emerald-600';
                            } elseif ($promo['status'] === 'inactive' || strtotime($promo['end_date']) < time()) {
                                $status_class = 'bg-red-50 text-red-500';
                            }
                        ?>
                        <span class="text-[9px] font-black px-3 py-1 rounded-full uppercase <?= $status_class ?>">
                            <?= (strtotime($promo['end_date']) < time()) ? 'Expired' : $promo['status'] ?>
                        </span>
                    </div>

                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 text-center mb-5">
                        <p class="text-xs font-bold text-blue-800">"<?= htmlspecialchars($promo['message']) ?>"</p>
                    </div>

                    <div class="grid grid-cols-3 gap-4 text-center mb-5">
                        <div>
                            <div class="text-2xl font-black text-blue-600"><?= $promo['total_sent'] ?></div>
                            <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Sent</div>
                        </div>
                        <div>
                            <div class="text-2xl font-black text-emerald-600"><?= $promo['total_used'] ?></div>
                            <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Used</div>
                        </div>
                        <div>
                            <div class="text-2xl font-black text-slate-800"><?= $conversion_rate ?>%</div>
                            <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Converted</div>
                        </div>
                    </div>

                    <div class="bg-slate-50 rounded-xl p-4 space-y-2 text-xs font-medium">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Offer:</span>
                            <span class="font-bold text-slate-800">
                                <?= $promo['offer_type'] == 'flat' ? '₹' . $promo['offer_value'] : $promo['offer_value'] . '%' ?> OFF
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Target:</span>
                            <span class="font-bold text-slate-800 uppercase"><?= htmlspecialchars($promo['target_segment']) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Validity:</span>
                            <span class="font-bold text-slate-800"><?= date('d M', strtotime($promo['start_date'])) ?> to <?= date('d M, Y', strtotime($promo['end_date'])) ?></span>
                        </div>
                        <div class="flex justify-between pt-2 border-t border-slate-200">
                            <span class="text-slate-500">Revenue Generated:</span>
                            <span class="font-black text-emerald-700">₹<?= number_format($promo['revenue_generated'] ?? 0, 2) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

</body>
</html>

