<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$current_page = basename($_SERVER['PHP_SELF']);

// --- Data Fetching ---

// 1. Customer Segments Count
$segments = $pdo->prepare("
    SELECT customer_segment, COUNT(*) as count
    FROM shop_customer_analytics
    WHERE shop_id = ?
    GROUP BY customer_segment
");
$segments->execute([$shop_id]);
$segment_counts = $segments->fetchAll(PDO::FETCH_KEY_PAIR);

$segment_data = [
    'champion' => $segment_counts['champion'] ?? 0,
    'loyal' => $segment_counts['loyal'] ?? 0,
    'at_risk' => $segment_counts['at_risk'] ?? 0,
    'new' => $segment_counts['new'] ?? 0,
    'lost' => $segment_counts['lost'] ?? 0,
];

// 2. Top 5 Champion Customers
$champions = $pdo->prepare("
    SELECT c.name, c.unique_id, a.total_spent, a.total_orders
    FROM shop_customer_analytics a
    JOIN customers c ON a.customer_id = c.id
    WHERE a.shop_id = ?
    ORDER BY a.total_spent DESC
    LIMIT 5
");
$champions->execute([$shop_id]);
$top_champions = $champions->fetchAll();

// 3. Top 5 At-Risk Customers
$at_risk_customers = $pdo->prepare("
    SELECT c.name, c.unique_id, a.last_order_date, a.days_since_last_order
    FROM shop_customer_analytics a
    JOIN customers c ON a.customer_id = c.id
    WHERE a.shop_id = ? AND a.customer_segment = 'at_risk'
    ORDER BY a.days_since_last_order DESC
    LIMIT 5
");
$at_risk_customers->execute([$shop_id]);
$top_at_risk = $at_risk_customers->fetchAll();

// 4. Peak Hours Analysis (Last 30 days)
$peak_hours_stmt = $pdo->prepare("
    SELECT HOUR(created_at) as hour, COUNT(*) as order_count
    FROM orders
    WHERE shop_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY hour
    ORDER BY hour ASC
");
$peak_hours_stmt->execute([$shop_id]);
$peak_hours_data = $peak_hours_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$peak_hours_labels = [];
$peak_hours_values = [];
for ($i = 0; $i < 24; $i++) {
    $hour_str = ($i % 12 == 0) ? 12 : $i % 12;
    $ampm = ($i < 12) ? 'AM' : 'PM';
    $peak_hours_labels[] = $hour_str . ' ' . $ampm;
    $peak_hours_values[] = $peak_hours_data[$i] ?? 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Insights - KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
    </style>
</head>
<body>

<div class="flex min-h-screen">
    <?php include '../includes/shop_sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-10">
        <div class="flex justify-between items-start mb-8">
            <div>
                <h1 class="text-3xl font-black text-slate-800">Customer Behavior Insights</h1>
                <p class="text-sm text-slate-500">Understand your customers to grow your business.</p>
            </div>
            <a href="promotions.php" class="bg-blue-600 text-white font-bold py-3 px-6 rounded-xl text-xs uppercase tracking-wider hover:bg-blue-700 transition-all shadow-lg shadow-blue-500/20">
                <i class="fas fa-bullhorn mr-2"></i> Create Promotions
            </a>
        </div>

        <!-- Customer Segments -->
        <div class="mb-8">
            <h2 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4">Customer Segments</h2>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="bg-amber-400 text-white p-5 rounded-2xl shadow-lg shadow-amber-200"><div class="text-3xl font-black"><?= $segment_data['champion'] ?></div><div class="text-xs font-bold opacity-80">Champions</div></div>
                <div class="bg-emerald-500 text-white p-5 rounded-2xl shadow-lg shadow-emerald-200"><div class="text-3xl font-black"><?= $segment_data['loyal'] ?></div><div class="text-xs font-bold opacity-80">Loyal</div></div>
                <div class="bg-red-500 text-white p-5 rounded-2xl shadow-lg shadow-red-200"><div class="text-3xl font-black"><?= $segment_data['at_risk'] ?></div><div class="text-xs font-bold opacity-80">At Risk</div></div>
                <div class="bg-blue-500 text-white p-5 rounded-2xl shadow-lg shadow-blue-200"><div class="text-3xl font-black"><?= $segment_data['new'] ?></div><div class="text-xs font-bold opacity-80">New</div></div>
                <div class="bg-slate-500 text-white p-5 rounded-2xl shadow-lg shadow-slate-200"><div class="text-3xl font-black"><?= $segment_data['lost'] ?></div><div class="text-xs font-bold opacity-80">Lost</div></div>
            </div>
        </div>

        <!-- Top Customers & Peak Hours -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Top Champions -->
            <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2 mb-4"><i class="fas fa-crown text-amber-500"></i> Top 5 Champion Customers</h3>
                <div class="space-y-3">
                    <?php foreach($top_champions as $c): ?>
                    <div class="flex justify-between items-center bg-slate-50 p-3 rounded-xl">
                        <div>
                            <div class="text-sm font-bold text-slate-800"><?= htmlspecialchars($c['name']) ?></div>
                            <div class="text-[10px] font-mono text-slate-400"><?= htmlspecialchars($c['unique_id']) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-black text-emerald-600">₹<?= number_format($c['total_spent']) ?></div>
                            <div class="text-[9px] font-bold text-slate-400"><?= $c['total_orders'] ?> orders</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Top At-Risk -->
            <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2 mb-4"><i class="fas fa-exclamation-triangle text-red-500"></i> Top 5 At-Risk Customers</h3>
                <div class="space-y-3">
                    <?php foreach($top_at_risk as $c): ?>
                    <div class="flex justify-between items-center bg-slate-50 p-3 rounded-xl">
                        <div>
                            <div class="text-sm font-bold text-slate-800"><?= htmlspecialchars($c['name']) ?></div>
                            <div class="text-[10px] font-mono text-slate-400"><?= htmlspecialchars($c['unique_id']) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-black text-red-600"><?= $c['days_since_last_order'] ?> days</div>
                            <div class="text-[9px] font-bold text-slate-400">Last seen: <?= date('d M, Y', strtotime($c['last_order_date'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Peak Hours Chart -->
        <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2 mb-4"><i class="fas fa-chart-bar text-blue-500"></i> Peak Business Hours (Last 30 Days)</h3>
            <div class="h-64">
                <canvas id="peakHoursChart"></canvas>
            </div>
        </div>

    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Peak Hours Chart
    const ctx = document.getElementById('peakHoursChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($peak_hours_labels) ?>,
            datasets: [{
                label: 'Number of Orders',
                data: <?= json_encode($peak_hours_values) ?>,
                backgroundColor: 'rgba(37, 99, 235, 0.6)',
                borderColor: 'rgba(37, 99, 235, 1)',
                borderWidth: 1,
                borderRadius: 6,
                barThickness: 15,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleColor: '#fff',
                    bodyColor: '#94a3b8',
                    padding: 10,
                    cornerRadius: 8
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#f1f5f9'
                    },
                    ticks: {
                        font: { size: 10 },
                        color: '#64748b'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: { size: 9 },
                        color: '#64748b'
                    }
                }
            }
        }
    });
});
</script>

</body>
</html>