<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

// 1. Fetch Top Suspicious IPs (Most failed lookups - potential bots/hackers)
$suspicious_ips = $pdo->query("
    SELECT ip_address, COUNT(*) as failed_hits, MAX(hit_timestamp) as last_seen
    FROM db_access_logs 
    WHERE is_found = 0 
    GROUP BY ip_address 
    ORDER BY failed_hits DESC 
    LIMIT 10
")->fetchAll();

// 2. Recent Access Logs for activity feed
$recent_logs = $pdo->query("
    SELECT * FROM db_access_logs 
    ORDER BY hit_timestamp DESC 
    LIMIT 50
")->fetchAll();

// 3. Aggregated Stats
$total_failed = $pdo->query("SELECT COUNT(*) FROM db_access_logs WHERE is_found = 0")->fetchColumn();
$unique_ips = $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM db_access_logs")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Security Audit — KhataLink Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        .kl-navbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .layout { display: flex; min-height: calc(100vh - 64px); }
        .main { flex: 1; padding: 32px; overflow-x: hidden; }
    </style>
</head>
<body>

<nav class="kl-navbar">
    <div style="display:flex; align-items:center; gap:16px;">
        <button class="lg:hidden text-slate-600" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
        <a href="dashboard.php">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="Logo" style="height: 40px;">
        </a>
    </div>
    <div class="text-right">
        <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($_SESSION['admin_name']) ?></span>
    </div>
</nav>

<div class="layout">
    <?php include '../includes/admin_sidebar.php'; ?>

    <main class="main">
        <div class="mb-8">
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Security Audit Center</h1>
            <p class="text-slate-500 text-sm">Monitor suspicious database access patterns and potential DDoS activity.</p>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Total Blocked Hits</div>
                <div class="text-3xl font-black text-red-600"><?= number_format($total_failed) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Unique IP Addresses</div>
                <div class="text-3xl font-black text-slate-900"><?= number_format($unique_ips) ?></div>
            </div>
            <div class="bg-slate-900 rounded-[2rem] p-6 text-white shadow-xl">
                <div class="text-[10px] font-black text-blue-400 uppercase tracking-widest mb-2">Shield Status</div>
                <div class="text-xl font-black flex items-center gap-2"><i class="fas fa-shield-virus text-emerald-400"></i> PROTECTION ON</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div class="lg:col-span-5">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm">
                    <div class="p-6 border-b border-slate-100 bg-slate-50/50"><h3 class="text-xs font-black uppercase tracking-widest">Top Suspicious IPs</h3></div>
                    <div class="divide-y divide-slate-50">
                        <?php if($suspicious_ips): foreach($suspicious_ips as $ip): ?>
                        <div class="p-5 flex justify-between items-center hover:bg-slate-50 transition-colors">
                            <div>
                                <div class="text-sm font-black text-slate-900"><?= $ip['ip_address'] ?></div>
                                <div class="text-[9px] font-bold text-slate-400 uppercase">Last Seen: <?= date('d M, h:i A', strtotime($ip['last_seen'])) ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-black text-red-600"><?= $ip['failed_hits'] ?> hits</div>
                                <div class="text-[8px] font-black bg-red-50 text-red-600 px-2 py-0.5 rounded-md uppercase">High Alert</div>
                            </div>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="p-10 text-center text-slate-400 italic text-sm">No suspicious activity logs found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-7">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm">
                    <div class="p-6 border-b border-slate-100 bg-slate-50/50"><h3 class="text-xs font-black uppercase tracking-widest">Recent Shield Activity</h3></div>
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b border-slate-100">
                            <tr>
                                <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">IP Address</th>
                                <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Action / ID</th>
                                <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach($recent_logs as $log): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="text-xs font-bold text-slate-900"><?= $log['ip_address'] ?></div>
                                    <div class="text-[9px] text-slate-400"><?= date('d M, h:i:s A', strtotime($log['hit_timestamp'])) ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-[10px] font-black text-slate-600 uppercase"><?= $log['request_type'] ?></div>
                                    <div class="text-[9px] text-slate-400">ID: <?= htmlspecialchars($log['requested_id']) ?></div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="text-[8px] font-black px-2 py-1 rounded-md uppercase <?= $log['is_found'] ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600' ?>">
                                        <?= $log['is_found'] ? 'VALID' : 'BLOCKED' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
