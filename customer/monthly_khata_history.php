<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$customer_id = $_SESSION['customer_id'];

// Filters
$from_date = $_GET['from_date'] ?? date('Y-m-01', strtotime('-1 year'));
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$shop_filter = (int)($_GET['shop_id'] ?? 0);

// Fetch all linked shops for filter dropdown
$stmt_shops = $pdo->prepare("SELECT s.id, s.shop_name FROM shop_owners s JOIN shop_customers sc ON s.id = sc.shop_id WHERE sc.customer_id = ? ORDER BY s.shop_name ASC");
$stmt_shops->execute([$customer_id]);
$all_shops = $stmt_shops->fetchAll();

// Build query for monthly khata history
$query = "
    SELECT mk.*, s.shop_name, s.shop_category,
           DATEDIFF(mk.start_date, mk.start_date) as days_passed
    FROM monthly_khata mk
    JOIN shop_owners s ON mk.shop_id = s.id
    WHERE mk.customer_id = ?
    AND mk.start_date BETWEEN ? AND ?
";
$params = [$customer_id, $from_date, $to_date];

if ($shop_filter > 0) {
    $query .= " AND mk.shop_id = ?";
    $params[] = $shop_filter;
}

$query .= " ORDER BY mk.start_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$cycles = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Monthly Khata History — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 font-[Inter]">
<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="monthly_khata.php" class="text-slate-400 hover:text-blue-600"><i class="fas fa-arrow-left"></i></a>
        <span class="text-xs font-black text-blue-700 uppercase tracking-widest">My Monthly Khata History</span>
    </div>
</nav>
<div class="flex">
    <?php include '../includes/customer_sidebar.php'; ?>
    <main class="flex-1 p-4 md:p-8">
        <div class="max-w-5xl mx-auto">
            <div class="mb-10">
                <h1 class="text-3xl font-black text-slate-900">My Monthly Khata History</h1>
                <p class="text-slate-500 text-sm">View all your past and current monthly cycles.</p>
            </div>

            <!-- Filter Form -->
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm mb-8">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">From Date</label>
                        <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">To Date</label>
                        <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">Shop</label>
                        <select name="shop_id" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold">
                            <option value="0">All Shops</option>
                            <?php foreach($all_shops as $shop): ?>
                                <option value="<?= $shop['id'] ?>" <?= $shop_filter == $shop['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($shop['shop_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 text-white font-black px-6 py-2.5 rounded-xl text-[10px] uppercase tracking-widest">Filter</button>
                        <a href="generate_monthly_history_pdf.php?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&shop_id=<?= $shop_filter ?>" target="_blank" class="bg-emerald-600 text-white font-black px-6 py-2.5 rounded-xl text-[10px] uppercase tracking-widest">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                    </div>
                </form>
            </div>

            <!-- History Table -->
            <div class="bg-white border border-slate-200 rounded-[2rem] overflow-x-auto shadow-sm">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Shop</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Cycle Start</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Bill Amount</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Payment Mode</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php if(count($cycles) > 0): ?>
                            <?php foreach($cycles as $mk):
                                $is_paid = ($mk['status'] == 'closed');
                                $payment_mode = ($mk['razorpay_payment_id'] === 'Manual') ? 'Cash' : (empty($mk['razorpay_payment_id']) ? '—' : 'Online');
                            ?>
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-6 py-4 text-sm font-black text-slate-900"><?= htmlspecialchars($mk['shop_name']) ?></td>
                                <td class="px-6 py-4 text-xs font-bold text-slate-500"><?= date('d M Y', strtotime($mk['start_date'])) ?></td>
                                <td class="px-6 py-4 text-sm font-black text-slate-900 text-right">₹<?= number_format($mk['total_amount'], 2) ?></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="text-[9px] font-black px-2 py-1 rounded-full uppercase <?= $is_paid ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600' ?>">
                                        <?= $is_paid ? 'Settled' : 'Open' ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center text-[9px] font-black uppercase text-slate-600">
                                    <?= $payment_mode ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="generate_monthly_statement.php?id=<?= $mk['id'] ?>" target="_blank" class="text-blue-600 hover:underline text-xs font-bold">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="py-20 text-center text-slate-400 italic text-sm">No monthly khata cycles found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.remove('hidden');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.add('hidden');
}
</script>
</body>
</html>