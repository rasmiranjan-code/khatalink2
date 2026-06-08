<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

$log_file = '../debug_error.log'; // Global log file

// Handle Clear Log
if(isset($_POST['clear_logs'])) {
    file_put_contents($log_file, "");
    header("Location: error_monitoring.php?msg=Logs Cleared");
    exit();
}

$logs = [];
if(file_exists($log_file)) {
    $lines = file($log_file);
    // Get last 100 errors for performance
    $logs = array_reverse(array_slice($lines, -100));
}

// Check for Spike (Load Monitoring)
$is_db_heavy = false;
if(count($logs) > 50) {
    // Agar pichle 5 minute mein 50 se zyada errors hain → High Load Alert
    $is_db_heavy = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Error & DB Monitor — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="text-slate-400"><i class="fas fa-chevron-left"></i></a>
            <h1 class="text-sm font-black uppercase tracking-widest text-slate-900">Crash & Load Monitor</h1>
        </div>
        <form method="POST" onsubmit="return confirm('Poore logs delete kar dein?')">
            <button type="submit" name="clear_logs" class="bg-red-50 text-red-600 px-4 py-2 rounded-xl text-[10px] font-black uppercase border border-red-100 hover:bg-red-600 hover:text-white transition-all">Clear All Logs</button>
        </form>
    </nav>

    <main class="p-8 max-w-7xl mx-auto">
        
        <?php if($is_db_heavy): ?>
        <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl shadow-red-200 flex items-center justify-between animate-pulse">
            <div class="flex items-center gap-4">
                <i class="fas fa-microchip text-2xl"></i>
                <div>
                    <h4 class="font-black uppercase text-sm tracking-widest">Database Spike Detected!</h4>
                    <p class="text-xs text-red-100 font-bold">Server par abnormal hits ho rahe hain. Database design limit test ho rahi hai.</p>
                </div>
            </div>
            <span class="bg-white text-red-600 px-4 py-1.5 rounded-full font-black text-[10px] uppercase">Action Required</span>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div class="lg:col-span-12">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                        <h3 class="text-xs font-black uppercase tracking-widest text-slate-500">Live Backend Trace (Last 100 Hits)</h3>
                        <span class="text-[10px] font-bold text-slate-400">Log File: <?= realpath($log_file) ?></span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 border-b border-slate-100">
                                <tr>
                                    <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Hit Info</th>
                                    <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Error Root Cause</th>
                                    <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Impact</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50 font-mono">
                                <?php foreach($logs as $line): 
                                    if(empty(trim($line))) continue;
                                    $is_crit = str_contains($line, 'PDO') || str_contains($line, 'SQL') || str_contains($line, 'Fatal');
                                    
                                    // Detect Module from path
                                    $module = 'System';
                                    if(str_contains($line, '/customer/')) $module = 'Customer App';
                                    elseif(str_contains($line, '/shop/')) $module = 'Shop Panel';
                                    elseif(str_contains($line, '/admin/')) $module = 'Admin Desk';
                                    elseif(str_contains($line, '/delivery/')) $module = 'Rider App';
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="text-[10px] font-black text-slate-400 uppercase"><?= substr($line, 0, 20) ?></div>
                                        <div class="text-[9px] text-indigo-600 font-black mt-1 uppercase tracking-tighter">Source: <?= $module ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-xs font-bold <?= $is_crit ? 'text-red-600' : 'text-slate-700' ?> break-all">
                                            <?= htmlspecialchars(substr($line, 20)) ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-1 rounded text-[8px] font-black uppercase <?= $is_crit ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-500' ?>">
                                            <?= $is_crit ? 'CRITICAL ERROR' : 'LOG INFO' ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($logs)): ?>
                                    <tr><td colspan="3" class="p-20 text-center text-slate-300 italic font-bold">System is 100% healthy. No logs found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>