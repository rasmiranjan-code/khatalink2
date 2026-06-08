<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$customer_id = $_SESSION['customer_id'];

// 1. Fetch Aggregated Totals
$stats = $pdo->prepare("
    SELECT 
        (SELECT COALESCE(SUM(total_amount), 0) FROM udhar_entries WHERE customer_id = ?) as total_spent,
        (SELECT COALESCE(SUM(amount_paid), 0) FROM payment_history WHERE customer_id = ?) as total_paid,
        (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND status = 'open') as total_due
");
$stats->execute([$customer_id, $customer_id, $customer_id]);
$totals = $stats->fetch();

// 2. Fetch Monthly Insights for Graph (Last 6 Months)
$graph_query = $pdo->prepare("
    SELECT month, 
           COALESCE(SUM(credit), 0) as credit, 
           COALESCE(SUM(payment), 0) as payment 
    FROM (
        SELECT DATE_FORMAT(entry_date, '%b %Y') as month, 
               total_amount as credit, 0 as payment,
               entry_date as sort_date
        FROM udhar_entries WHERE customer_id = ? 
        UNION ALL 
        SELECT DATE_FORMAT(payment_date, '%b %Y') as month, 
               0 as credit, amount_paid as payment,
               payment_date as sort_date
        FROM payment_history WHERE customer_id = ?
    ) as combined 
    GROUP BY month 
    ORDER BY MIN(sort_date) DESC 
    LIMIT 6
");
$graph_query->execute([$customer_id, $customer_id]);
$graph_data = array_reverse($graph_query->fetchAll(PDO::FETCH_ASSOC));

$months = json_encode(array_column($graph_data, 'month'));
$credits = json_encode(array_map('floatval', array_column($graph_data, 'credit')));
$payments = json_encode(array_map('floatval', array_column($graph_data, 'payment')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Analytics — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass-banner { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="text-[10px] font-black text-blue-600 uppercase tracking-widest bg-blue-50 border border-blue-100 px-3 py-1.5 rounded-full">
        <i class="fas fa-chart-line me-1"></i> Data Analytics
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/customer_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        <div class="glass-banner rounded-3xl p-8 mb-8 text-white shadow-xl relative overflow-hidden">
            <div class="relative z-10">
                <h1 class="text-2xl md:text-3xl font-black tracking-tight mb-1">Spending Insights</h1>
                <p class="text-slate-400 text-sm">Visualize your monthly credit and payment trends.</p>
            </div>
            <i class="fas fa-chart-pie absolute -right-4 -bottom-4 text-8xl text-white/5 rotate-12"></i>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Purchases</div>
                <div class="text-2xl font-black text-slate-900">₹<?= number_format($totals['total_spent'], 2) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Payments</div>
                <div class="text-2xl font-black text-emerald-600">₹<?= number_format($totals['total_paid'], 2) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Due</div>
                <div class="text-2xl font-black text-red-600">₹<?= number_format($totals['total_due'], 2) ?></div>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm">
            <h5 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-6">Spending vs Payments Trend</h5>
            <div class="h-[400px]">
                <canvas id="statementChart"></canvas>
            </div>
        </div>
    </main>
</div>

<footer class="bg-white border-t border-slate-200 py-6 text-center mt-8">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">© <?= date('Y') ?> KhataLink — Premium Digital Ledger</div>
</footer>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }

// Chart Setup
const ctx = document.getElementById('statementChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= $months ?>,
        datasets: [
            { label: 'Credit (Udhar)', data: <?= $credits ?>, backgroundColor: 'rgba(239, 68, 68, 0.8)', borderRadius: 8 },
            { label: 'Payments', data: <?= $payments ?>, backgroundColor: 'rgba(16, 185, 129, 0.8)', borderRadius: 8 }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { weight: 'bold', size: 10 } } } }, scales: { y: { beginAtZero: true, grid: { display: false }, ticks: { font: { weight: 'bold', size: 10 } } }, x: { grid: { display: false }, ticks: { font: { weight: 'bold', size: 10 } } } } }
});
</script>
</body>
</html>