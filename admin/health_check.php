<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

// 1. Database Connection & Basic Info
$db_status = "Online";
try {
    $mysql_version = $pdo->query("SELECT VERSION()")->fetchColumn();
    $db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();
} catch (Exception $e) {
    $db_status = "Error: " . $e->getMessage();
}

// 2. Table Statistics
$tables = [
    'customers' => 'Users',
    'shop_owners' => 'Merchants',
    'delivery_partners' => 'Partners',
    'orders' => 'Marketplace Orders',
    'udhar_entries' => 'Ledger Entries',
    'db_access_logs' => 'Security Hits'
];

$stats = [];
foreach($tables as $tbl => $label) {
    $stats[$label] = $pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
}

// 3. Security Health (Failed hits in last 24h)
$security_alerts = $pdo->query("SELECT COUNT(*) FROM db_access_logs WHERE is_found = 0 AND hit_timestamp > (NOW() - INTERVAL 1 DAY)")->fetchColumn();

// 4. Unverified Partners Check
$pending_partners = $pdo->query("SELECT COUNT(*) FROM delivery_partners WHERE is_verified = 0")->fetchColumn();

// 5. Server Info
$php_version = PHP_VERSION;
$server_os = PHP_OS;
$memory_usage = round(memory_get_usage() / 1024 / 1024, 2) . ' MB';

// ── 6. Scalability Health Check (1 Crore Users Readiness) ──
$readiness_score = 0;
$audit_notes = [];

// Check 1: ID Types (BIGINT check) - Mandatory for 1Cr+ records
$stmt_type = $pdo->prepare("SELECT DATA_TYPE FROM information_schema.columns WHERE table_name = 'customers' AND column_name = 'id' AND table_schema = ?");
$stmt_type->execute([$db_name]);
$cust_id_type = $stmt_type->fetchColumn();
if ($cust_id_type === 'bigint') { $readiness_score += 30; $audit_notes[] = "Primary Keys use BIGINT (Safe for billions)"; }
else { $audit_notes[] = "⚠️ IDs use INT (Limit: 2.1bn, suggest BIGINT for 1Cr+ scaling)"; $readiness_score += 20; }

// Check 2: Indexing on Heavy Filter Columns
$stmt_idx = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_name = 'orders' AND column_name = 'customer_id' AND table_schema = ?");
$stmt_idx->execute([$db_name]);
if ($stmt_idx->fetchColumn() > 0) { $readiness_score += 30; $audit_notes[] = "Relational columns are indexed (Fast joins)"; }
else { $audit_notes[] = "❌ CRITICAL: Missing indexes on 'orders' table"; }

// Check 3: Spatial Engine usage (for Geo-scaling)
$stmt_spatial = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'geo_registry' AND column_name = 'geo_point' AND data_type = 'point'");
$stmt_spatial->execute();
if ($stmt_spatial->fetchColumn() > 0) { $readiness_score += 20; $audit_notes[] = "Spatial Geometry used (Optimized map searches)"; }

// Check 4: Rate Limiting
if (file_exists('../includes/security_shield.php')) { $readiness_score += 20; $audit_notes[] = "DDoS Protection Shield Active"; }

// Determine Look
$score_color = "text-red-500";
$score_bar = "bg-red-500";
if($readiness_score >= 85) { $score_color = "text-emerald-600"; $score_bar = "bg-emerald-500"; }
elseif($readiness_score >= 60) { $score_color = "text-amber-600"; $score_bar = "bg-amber-500"; }

// Log Activity check
$log_file = '../debug_error.log'; // Global log file
$log_size = file_exists($log_file) ? round(filesize($log_file) / 1024, 2) . ' KB' : '0 KB';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Health Check — KhataLink Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
    <a href="dashboard.php" class="flex items-center gap-2">
        <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8">
        <span class="text-[10px] font-black bg-slate-900 text-white px-2 py-1 rounded-md uppercase tracking-widest">System Monitor</span>
    </a>
    <div class="flex items-center gap-4">
        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Status: <span class="text-emerald-500">Stable</span></span>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <!-- Sidebar usage if available or simple layout -->
    <main class="flex-1 p-8 max-w-6xl mx-auto">
        <div class="mb-10">
            <h1 class="text-3xl font-black text-slate-900 tracking-tight">Project Health Diagnostics</h1>
            <p class="text-slate-500 text-sm">Real-time audit of database integrity, security, and infrastructure.</p>
        </div>

        <!-- Connection Status Banner -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 mb-8 flex flex-col md:flex-row items-center justify-between shadow-sm relative overflow-hidden">
            <div class="flex items-center gap-6 relative z-10">
                <div class="w-16 h-16 rounded-3xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-3xl">
                    <i class="fas fa-database"></i>
                </div>
                <div>
                    <h3 class="text-lg font-black text-slate-900 uppercase tracking-tight">Database Engine: <?= $db_status ?></h3>
                    <p class="text-slate-400 text-sm font-medium">Instance: <?= $db_name ?> &nbsp;•&nbsp; Version: <?= $mysql_version ?></p>
                </div>
            </div>
            <div class="mt-6 md:mt-0 relative z-10">
                <span class="bg-emerald-500 text-white px-6 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] shadow-lg shadow-emerald-200">System Live</span>
            </div>
            <i class="fas fa-microchip absolute -right-4 -bottom-4 text-8xl text-slate-50"></i>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Statistics -->
            <div class="lg:col-span-12 mb-8">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm">
                    <div class="flex justify-between items-center mb-6">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-widest">1 Crore Users Scalability Readiness</h4>
                        <div class="text-3xl font-black <?= $score_color ?>"><?= $readiness_score ?>%</div>
                    </div>
                    <div class="w-full h-4 bg-slate-100 rounded-full overflow-hidden mb-8">
                        <div class="<?= $score_bar ?> h-full transition-all duration-1000" style="width: <?= $readiness_score ?>%"></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach($audit_notes as $note): ?>
                            <div class="flex items-center gap-3 text-xs font-bold text-slate-600">
                                <i class="fas <?= str_contains($note, '❌') ? 'fa-times-circle text-red-500' : (str_contains($note, '⚠️') ? 'fa-exclamation-triangle text-amber-500' : 'fa-check-circle text-emerald-500') ?>"></i>
                                <?= $note ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-8 pt-6 border-t border-slate-50 flex justify-between items-center">
                        <p class="text-[10px] text-slate-400 uppercase font-black">Current Log File Weight: <?= $log_size ?></p>
                        <a href="error_monitoring.php" class="text-blue-600 text-[10px] font-black uppercase tracking-widest hover:underline">Open Error Monitor →</a>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-8 space-y-8">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm">
                    <h4 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-6">Database Records Audit</h4>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-6">
                        <?php foreach($stats as $label => $val): ?>
                        <div class="p-5 bg-slate-50 rounded-3xl border border-slate-100">
                            <div class="text-[9px] font-black text-slate-400 uppercase mb-1"><?= $label ?></div>
                            <div class="text-2xl font-black text-slate-900"><?= number_format($val) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm">
                    <h4 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-6">Security & Validation</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="p-6 bg-red-50 rounded-3xl border border-red-100">
                            <div class="flex items-center justify-between mb-4">
                                <i class="fas fa-shield-virus text-red-600"></i>
                                <span class="text-[8px] font-black bg-red-600 text-white px-2 py-0.5 rounded uppercase">24h History</span>
                            </div>
                            <div class="text-2xl font-black text-red-600"><?= $security_alerts ?> Hits Blocked</div>
                            <p class="text-[10px] text-red-400 font-bold uppercase mt-1">Suspicious DB access attempts detected.</p>
                        </div>
                        <div class="p-6 bg-amber-50 rounded-3xl border border-amber-100">
                            <div class="flex items-center justify-between mb-4">
                                <i class="fas fa-motorcycle text-amber-600"></i>
                                <span class="text-[8px] font-black bg-amber-600 text-white px-2 py-0.5 rounded uppercase">Verification</span>
                            </div>
                            <div class="text-2xl font-black text-amber-600"><?= $pending_partners ?> Pending Verification</div>
                            <p class="text-[10px] text-amber-500 font-bold uppercase mt-1">Delivery boys waiting for access.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Environment -->
            <div class="lg:col-span-4">
                <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-2xl sticky top-24">
                    <h4 class="text-[10px] font-black text-blue-400 uppercase tracking-[0.3em] mb-8">Server Environment</h4>
                    
                    <div class="space-y-6">
                        <div>
                            <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">PHP Version</label>
                            <div class="text-sm font-black"><?= $php_version ?></div>
                        </div>
                        <div>
                            <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Operating System</label>
                            <div class="text-sm font-black"><?= $server_os ?></div>
                        </div>
                        <div>
                            <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Memory (PHP usage)</label>
                            <div class="text-sm font-black"><?= $memory_usage ?></div>
                        </div>
                        <div class="pt-6 border-t border-white/10">
                             <div class="bg-white/5 p-4 rounded-2xl">
                                 <p class="text-[10px] text-slate-400 leading-relaxed italic">"Regularly check logs for optimization. Ensure indexes are maintained for high-scale growth."</p>
                             </div>
                        </div>
                        <button onclick="window.location.reload()" class="w-full bg-blue-600 text-white py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-500 transition-all">Refresh Diagnostics</button>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>