<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// ── 1. FILTERS ──
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-7 days'));
$to_date   = $_GET['to_date']   ?? date('Y-m-d');

// ── 2. AGGREGATED STATS ──
$today_hits = $pdo->query("SELECT COUNT(*) FROM visitors WHERE visit_date = CURDATE()")->fetchColumn();
$unique_ips = $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visitors")->fetchColumn();
$total_hits = $pdo->query("SELECT COUNT(*) FROM visitors")->fetchColumn();

// ── 3. CHART DATA (Last 7/30 Days) ──
$chart_query = $pdo->prepare("
    SELECT visit_date, COUNT(*) as count 
    FROM visitors 
    WHERE visit_date BETWEEN ? AND ?
    GROUP BY visit_date 
    ORDER BY visit_date ASC
");
$chart_query->execute([$from_date, $to_date]);
$raw_chart = $chart_query->fetchAll();

$labels = []; $counts = [];
foreach($raw_chart as $row) {
    $labels[] = date('d M', strtotime($row['visit_date']));
    $counts[] = (int)$row['count'];
}

// ── 4. DETAILED LOGS ──
$stmt_logs = $pdo->prepare("
    SELECT * FROM visitors 
    WHERE visit_date BETWEEN ? AND ? 
    ORDER BY visit_time DESC 
    LIMIT 200
");
$stmt_logs->execute([$from_date, $to_date]);
$logs = $stmt_logs->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Analytics — KhataLink Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <a href="dashboard.php" class="text-slate-400 hover:text-slate-900"><i class="fas fa-chevron-left"></i></a>
        <h1 class="text-sm font-black uppercase tracking-widest text-slate-900">Traffic Monitor</h1>
    </div>
    <div class="text-[10px] font-black bg-blue-50 text-blue-600 px-3 py-1 rounded-full uppercase tracking-tighter border border-blue-100">Live Analytics Engine</div>
</nav>

<div class="flex">
    <?php include '../includes/admin_sidebar.php'; ?>

    <main class="flex-1 p-8">
        <div class="max-w-6xl mx-auto">
            
            <div class="flex flex-col md:flex-row justify-between items-end mb-10 gap-6">
                <div>
                    <h2 class="text-3xl font-black tracking-tight">Visitor Analytics</h2>
                    <p class="text-slate-500 text-sm">Monitoring engagement and reach across the network.</p>
                </div>
                <form method="GET" class="flex gap-2">
                    <div class="bg-white border-2 border-slate-200 rounded-xl px-3 py-1">
                        <label class="block text-[8px] font-black text-slate-400 uppercase">From</label>
                        <input type="date" name="from_date" value="<?= $from_date ?>" class="text-xs font-bold outline-none">
                    </div>
                    <div class="bg-white border-2 border-slate-200 rounded-xl px-3 py-1">
                        <label class="block text-[8px] font-black text-slate-400 uppercase">To</label>
                        <input type="date" name="to_date" value="<?= $to_date ?>" class="text-xs font-bold outline-none">
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-6 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-blue-600 transition-all">Filter</button>
                </form>
            </div>

            <!-- Stat Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                    <div class="text-[9px] font-black text-slate-400 uppercase mb-1">Hits Today</div>
                    <div class="text-3xl font-black text-blue-600"><?= number_format($today_hits) ?></div>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                    <div class="text-[9px] font-black text-slate-400 uppercase mb-1">Unique IP Base</div>
                    <div class="text-3xl font-black text-slate-900"><?= number_format($unique_ips) ?></div>
                </div>
                <div class="bg-slate-900 p-6 rounded-[2rem] text-white shadow-xl shadow-slate-200">
                    <div class="text-[9px] font-black text-blue-400 uppercase mb-1">Lifetime Traffic</div>
                    <div class="text-3xl font-black"><?= number_format($total_hits) ?> <span class="text-xs text-slate-500 font-bold">Views</span></div>
                </div>
            </div>

            <!-- Chart Section -->
            <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm mb-10">
                <div class="flex items-center justify-between mb-8">
                    <h3 class="text-xs font-black uppercase tracking-[0.2em] text-slate-400">Visitor Trend Line</h3>
                    <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-3 py-1 rounded-lg">Real-time Data Sync</span>
                </div>
                <div class="h-72 w-full">
                    <canvas id="visitorTrendChart"></canvas>
                </div>
            </div>

            <!-- Table Section -->
            <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm">
                <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-500">Live Traffic Stream (Top 200)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b border-slate-100">
                            <tr>
                                <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Time & Date</th>
                                <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">IP Address</th>
                                <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Target Page</th>
                                <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Device Info</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach($logs as $log): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="text-xs font-black text-slate-900"><?= date('h:i:s A', strtotime($log['visit_time'])) ?></div>
                                    <div class="text-[9px] font-bold text-slate-400 uppercase"><?= date('d M Y', strtotime($log['visit_date'])) ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-[10px] font-mono font-bold bg-slate-100 px-2 py-1 rounded text-slate-600"><?= $log['ip_address'] ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-[10px] font-black text-blue-600 truncate max-w-[150px]" title="<?= htmlspecialchars($log['page_url']) ?>">
                                        <?= basename($log['page_url']) ?: 'Home' ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-[9px] text-slate-400 font-medium truncate max-w-[200px]" title="<?= htmlspecialchars($log['user_agent']) ?>">
                                        <?= htmlspecialchars($log['user_agent']) ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($logs)): ?>
                                <tr><td colspan="4" class="p-20 text-center text-slate-300 font-bold uppercase tracking-widest text-xs italic">No traffic recorded for this range</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.add('show'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.remove('show'); }

// ── Chart Initialization ──
const ctx = document.getElementById('visitorTrendChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
            label: 'Visits',
            data: <?= json_encode($counts) ?>,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37, 99, 235, 0.1)',
            borderWidth: 3,
            pointBackgroundColor: '#2563eb',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { 
                beginAtZero: true, 
                grid: { color: '#f1f5f9' },
                ticks: { font: { weight: 'bold' }, color: '#94a3b8' }
            },
            x: { 
                grid: { display: false },
                ticks: { font: { weight: 'bold' }, color: '#94a3b8' }
            }
        }
    }
});
</script>

</body>
</html>
