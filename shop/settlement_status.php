<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
require_once '../includes/notification_service.php';

// ── AUTHENTICATION LAYER (Web + Flutter API) ──────────────────────────
$shop_id = 0;
$is_api = false;

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0] ?? 0);
    $role = $parts[2] ?? '';
    if ($role !== 'shop') $shop_id = 0;
} else {
    $shop_id = (int)($_SESSION['shop_id'] ?? 0);
}

if (!$shop_id) {
    if ($is_api) exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
    header("Location: ../auth/login.php"); exit();
}

// Fetch Grouped Settlements (T+3 Logic)
// Updated Query to fetch status and last update time for settled details
$query = "
    SELECT pay_date, SUM(base_amount) as total_payout, status, MAX(settled_at) as settled_at
    FROM (
        SELECT DATE(created_at) as pay_date, amount as base_amount, is_settled_manually as status, created_at as settled_at 
        FROM payment_requests WHERE shop_id = ? AND razorpay_payment_id IS NOT NULL AND status = 'approved'
        UNION ALL
        SELECT DATE(paid_at) as pay_date, total_amount as base_amount, is_settled_manually as status, paid_at as settled_at 
        FROM monthly_khata WHERE shop_id = ? AND razorpay_payment_id IS NOT NULL AND status = 'closed'
        UNION ALL
        SELECT DATE(payment_date) as pay_date, amount_paid as base_amount, is_settled_manually as status, payment_date as settled_at 
        FROM bond_payments bp JOIN bonds b ON bp.bond_id = b.id WHERE b.shop_id = ? AND bp.razorpay_payment_id IS NOT NULL AND bp.payment_status = 'completed'
    ) as all_pays
    GROUP BY pay_date, status
    ORDER BY pay_date DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$shop_id, $shop_id, $shop_id]);
$raw_settlements = $stmt->fetchAll();

$settlements = [];
foreach ($raw_settlements as $row) {
    $ts = strtotime($row['pay_date']);
    $dayOfWeek = date('N', $ts); // 1 (Mon) to 7 (Sun)
    $settle_ts = $ts; // Initialize

    if ($dayOfWeek == 5) { // Friday -> Monday (T+3)
        $settle_ts = strtotime($row['pay_date'] . ' + 3 days');
    } elseif ($dayOfWeek == 6 || $dayOfWeek == 7 || $dayOfWeek < 5) { 
        // Mon-Thu, Sat, Sun -> T+2
        $settle_ts = strtotime($row['pay_date'] . ' + 2 days');
        // Double check if target is Sunday
        if (date('N', $settle_ts) == 7) $settle_ts = strtotime(date('Y-m-d', $settle_ts) . ' + 1 day');
    }
    
    $settlements[] = [
        'pay_date' => $row['pay_date'],
        'pay_day' => date('l', strtotime($row['pay_date'])),
        'total_payout' => (float)$row['total_payout'],
        'status' => (int)$row['status'],
        'expected_date' => date('Y-m-d', $settle_ts),
        'expected_display' => date('d M Y', $settle_ts),
        'expected_day' => date('l', $settle_ts),
        'settled_at' => $row['settled_at'] ? date('d M Y, h:i A', strtotime($row['settled_at'])) : null,
        'settled_day' => $row['settled_at'] ? date('l', strtotime($row['settled_at'])) : null
    ];
}

// API Response
if ($is_api) {
    ob_clean();
    echo json_encode([
        'success' => true,
        'data' => $settlements
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Settlements — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="../assets/favicon.png">
</head>
<body class="bg-slate-50 font-[Inter]">
    <nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"></a>
        <span class="text-xs font-black text-blue-700 uppercase tracking-widest">Payout Calendar</span>
    </nav>

    <div class="flex">
        <?php include '../includes/shop_sidebar.php'; ?>
        <main class="flex-1 p-4 md:p-8 max-w-4xl mx-auto w-full">
            <div class="mb-10">
                <h1 class="text-3xl font-black text-slate-900">Settlement Wallet</h1>
                <p class="text-slate-500 text-sm">Track your online collections and T+2 payout schedule.</p>
            </div>

            <!-- Important Bank Note -->
            <div class="bg-amber-50 border-2 border-dashed border-amber-200 p-6 rounded-[2rem] mb-8 flex gap-4 items-center">
                <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center shrink-0">
                    <i class="fas fa-university text-xl"></i>
                </div>
                <div>
                    <h4 class="font-black text-amber-900 text-sm uppercase">Zaroori Soochana / Important Note</h4>
                    <p class="text-amber-700 text-xs font-medium leading-relaxed">Paisa receive karne ke liye kripya <b>Profile > Bank Details</b> mein jakar apna Account Number aur IFSC Code sahi se bharein. Galat info hone par settlement delay ho sakti hai.</p>
                </div>
            </div>

            <div class="space-y-4">
                <?php foreach($settlements as $s): 
                    $is_settled = (bool)$s['status'];
                ?>
                <div class="bg-white border border-slate-200 rounded-[2rem] p-6 flex flex-col md:flex-row justify-between items-center gap-4 shadow-sm">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 <?= $is_settled ? 'bg-emerald-100 text-emerald-600' : 'bg-blue-100 text-blue-600' ?> rounded-xl flex items-center justify-center">
                            <i class="fas <?= $is_settled ? 'fa-check-double' : 'fa-clock' ?>"></i>
                        </div>
                        <div>
                            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Collections of <?= date('d M Y', strtotime($s['pay_date'])) ?></div>
                            <div class="text-lg font-black text-slate-900">₹<?= number_format($s['total_payout'], 2) ?></div>
                        </div>
                    </div>

                    <div class="text-center md:text-right">
                        <?php if($is_settled): ?>
                            <span class="text-[9px] font-black bg-emerald-50 text-emerald-600 px-4 py-2 rounded-xl uppercase tracking-widest border border-emerald-100">
                                Settle Ho Gaya ✅
                            </span>
                            <p class="text-[8px] text-slate-500 mt-2 font-bold uppercase tracking-tight">Confirmed on <?= $s['settled_day'] ?></p>
                            <p class="text-[8px] text-slate-400 font-medium"><?= $s['settled_at'] ?></p>
                        <?php else: ?>
                            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Expected Payout Date:</div>
                            <div class="text-sm font-black text-blue-600"><?= $s['expected_display'] ?> (<?= $s['expected_day'] ?>)</div>
                            <p class="text-[9px] text-blue-400 font-bold uppercase mt-1 italic tracking-widest">In KhataLink Wallet 🔒</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if(empty($settlements)): ?>
                <div class="text-center py-20 bg-white border border-dashed border-slate-200 rounded-[2.5rem]">
                    <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No online payments found</p>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>