<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];

// Monthly Credit vs Payments (Last 6 months)
$monthly_query = "
    SELECT 
        DATE_FORMAT(dates.date, '%b %Y') as month_label,
        COALESCE(SUM(ue.total_amount), 0) as total_credit,
        (SELECT COALESCE(SUM(ph.amount_paid), 0) 
         FROM payment_history ph 
         WHERE ph.shop_id = :sid1 
         AND DATE_FORMAT(ph.payment_date, '%Y-%m') = DATE_FORMAT(dates.date, '%Y-%m')) as total_paid
    FROM (
        SELECT CURDATE() as date
        UNION ALL SELECT CURDATE() - INTERVAL 1 MONTH
        UNION ALL SELECT CURDATE() - INTERVAL 2 MONTH
        UNION ALL SELECT CURDATE() - INTERVAL 3 MONTH
        UNION ALL SELECT CURDATE() - INTERVAL 4 MONTH
        UNION ALL SELECT CURDATE() - INTERVAL 5 MONTH
    ) dates
    LEFT JOIN udhar_entries ue ON ue.shop_id = :sid2 
    AND DATE_FORMAT(ue.created_at, '%Y-%m') = DATE_FORMAT(dates.date, '%Y-%m')
    GROUP BY month_label, dates.date
    ORDER BY dates.date ASC
";
$stmt_monthly = $pdo->prepare($monthly_query);
$stmt_monthly->execute(['sid1' => $shop_id, 'sid2' => $shop_id]);
$monthly_stats = $stmt_monthly->fetchAll();

$labels = []; $credit_data = []; $paid_data = [];
foreach($monthly_stats as $row) {
    $labels[] = $row['month_label'];
    $credit_data[] = (float)$row['total_credit'];
    $paid_data[] = (float)$row['total_paid'];
}

// All-time Summary
$stmt_sum = $pdo->prepare("
    SELECT 
        (SELECT COALESCE(SUM(total_remaining),0) FROM udhar_entries WHERE shop_id = ? AND status='open') as total_due,
        (SELECT COALESCE(SUM(amount_paid),0) FROM payment_history WHERE shop_id = ?) as total_coll,
        (SELECT COALESCE(SUM(total_amount),0) FROM udhar_entries WHERE shop_id = ?) as total_credit
");
$stmt_sum->execute([$shop_id, $shop_id, $shop_id]);
$summary = $stmt_sum->fetch();

// Top 5 Customers by Due
$stmt_top = $pdo->prepare("
    SELECT c.name, COALESCE(SUM(ue.total_remaining), 0) as total_due
    FROM customers c
    JOIN shop_customers sc ON c.id = sc.customer_id
    LEFT JOIN udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id = ? AND ue.status = 'open'
    WHERE sc.shop_id = ?
    GROUP BY c.id
    ORDER BY total_due DESC
    LIMIT 5
");
$stmt_top->execute([$shop_id, $shop_id]);
$top_customers = $stmt_top->fetchAll();

$cust_names = []; $cust_dues = [];
foreach($top_customers as $tc) {
    $cust_names[] = $tc['name'];
    $cust_dues[] = (float)$tc['total_due'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Analytics — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
   <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-slate-50 text-slate-900 font-[Inter]">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="hidden sm:flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
        <i class="fas fa-store"></i>
        <?= htmlspecialchars($_SESSION['shop_name']) ?>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">

    <?php include '../includes/shop_sidebar.php'; ?>

    <!-- Main -->
    <div class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">Business Analytics</h1>
            <p class="text-slate-500 text-sm">Visual insights into your shop's performance and cash flow.</p>
        </div>

        <!-- Stat Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                <div class="w-10 h-10 bg-red-50 text-red-600 rounded-xl flex items-center justify-center text-sm mb-4"><i class="fas fa-rupee-sign"></i></div>
                <div class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-1">Total Udhar Given</div>
                <div class="text-2xl font-black text-red-600">₹<?= number_format($summary['total_credit'], 0) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center text-sm mb-4"><i class="fas fa-check-circle"></i></div>
                <div class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-1">Total Collected</div>
                <div class="text-2xl font-black text-emerald-600">₹<?= number_format($summary['total_coll'], 0) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm border-b-4 border-b-blue-600">
                <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center text-sm mb-4"><i class="fas fa-clock"></i></div>
                <div class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-1">Total Due</div>
                <div class="text-2xl font-black text-blue-600">₹<?= number_format($summary['total_due'], 0) ?></div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-8">
            <!-- Cash Flow Chart -->
            <div class="lg:col-span-8 bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2 uppercase tracking-tight mb-6">
                    <i class="fas fa-exchange-alt text-blue-600"></i> Credit vs Collections
                </h3>
                <div class="h-[300px] w-full">
                    <canvas id="cashFlowChart"></canvas>
                </div>
            </div>

            <!-- Top Dues -->
            <div class="lg:col-span-4 bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2 uppercase tracking-tight mb-6">
                    <i class="fas fa-user-clock text-red-600"></i> Top 5 Due Customers
                </h3>
                <div class="h-[300px] w-full">
                    <canvas id="topDuesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Insight Banner -->
        <div class="bg-slate-900 rounded-[2rem] p-8 text-white relative overflow-hidden shadow-2xl shadow-slate-200">
            <div class="relative z-10 flex flex-col md:flex-row justify-between items-center gap-6">
                <div>
                    <div class="text-blue-400 text-[10px] font-black uppercase tracking-[0.3em] mb-2">Smart Insights</div>
                    <h3 class="text-xl font-black mb-2">Automated Business Intelligence</h3>
                    <p class="text-slate-400 text-sm max-w-xl">KhataLink analyzes every transaction in real-time. Use these graphs to identify peak credit seasons and improve your recovery rate.</p>
                </div>
                <div class="hidden md:block">
                    <div class="w-16 h-16 bg-white/10 backdrop-blur-xl rounded-2xl flex items-center justify-center text-3xl">
                        <i class="fas fa-brain"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Cash Flow Chart
const flowCtx = document.getElementById('cashFlowChart').getContext('2d');
new Chart(flowCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            {
                label: 'Udhar Given',
                data: <?= json_encode($credit_data) ?>,
                borderColor: '#dc2626',
                backgroundColor: 'rgba(220,38,38,0.06)',
                fill: true,
                tension: 0.4,
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#dc2626'
            },
            {
                label: 'Collected',
                data: <?= json_encode($paid_data) ?>,
                borderColor: '#059669',
                backgroundColor: 'rgba(5,150,105,0.06)',
                fill: true,
                tension: 0.4,
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#059669'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                labels: { usePointStyle: true, font: { weight: '600', size: 12 } }
            },
            tooltip: {
                backgroundColor: '#0f172a',
                titleColor: '#fff',
                bodyColor: '#94a3b8',
                padding: 12,
                cornerRadius: 8,
                callbacks: {
                    label: ctx => ' ₹' + ctx.raw.toLocaleString('en-IN')
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: {
                    font: { size: 11 },
                    callback: val => '₹' + val.toLocaleString('en-IN')
                }
            },
            x: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
    }
});

// Top Dues Chart
const dueCtx = document.getElementById('topDuesChart').getContext('2d');
new Chart(dueCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($cust_names) ?>,
        datasets: [{
            label: 'Amount Due',
            data: <?= json_encode($cust_dues) ?>,
            backgroundColor: ['#2563eb','#7c3aed','#db2777','#ea580c','#d97706'],
            borderRadius: 8,
            barThickness: 22
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#0f172a',
                callbacks: {
                    label: ctx => ' ₹' + ctx.raw.toLocaleString('en-IN')
                }
            }
        },
        scales: {
            x: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: { font: { size: 11 } }
            },
            y: { grid: { display: false }, ticks: { font: { size: 12 } } }
        }
    }
});

function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.add('show');
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('show');
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>