<?php
session_start();
require_once '../includes/db.php';
if (!isset($_SESSION['admin_id'])) { header("Location: ../auth/admin_login.php"); exit(); }

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST['settings'] as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$key, $value, $value]);
    }
    $success = "Mall control settings updated successfully!";
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mall Control — Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 font-[Inter]">
    <div class="flex">
        <?php include '../includes/admin_sidebar.php'; ?>
        <main class="flex-1 p-8 max-w-4xl mx-auto">
            <div class="mb-10">
                <h1 class="text-3xl font-black text-slate-900 tracking-tight">Mall Master Control</h1>
                <p class="text-slate-500">Configure global opening hours and maintenance mode.</p>
            </div>

            <?php if($success): ?>
                <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 p-4 rounded-2xl mb-8 font-bold text-sm">
                    <i class="fas fa-check-circle me-2"></i> <?= $success ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <!-- Timing Section -->
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-6 flex items-center gap-2">
                        <i class="fas fa-clock text-blue-600"></i> Standard Service Hours
                    </h3>
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2">Opening Time</label>
                            <input type="time" name="settings[mall_open_time]" value="<?= $settings['mall_open_time'] ?? '07:00' ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 font-black">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2">Closing Time</label>
                            <input type="time" name="settings[mall_close_time]" value="<?= $settings['mall_close_time'] ?? '23:00' ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 font-black">
                        </div>
                    </div>
                    <p class="mt-4 text-[10px] text-slate-400 italic">Mall automatically enters 'Ghost Mode' (B&W) outside these hours.</p>
                </div>

                <!-- Maintenance Section -->
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-6 flex items-center gap-2">
                        <i class="fas fa-hammer text-amber-500"></i> Maintenance & Emergency
                    </h3>
                    <div class="space-y-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2">Scheduled Maintenance Date</label>
                            <input type="date" name="settings[mall_maintenance_date]" value="<?= $settings['mall_maintenance_date'] ?? '' ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 font-black">
                        </div>
                        
                        <div class="p-6 bg-red-50 border border-red-100 rounded-3xl flex items-center justify-between">
                            <div>
                                <h4 class="text-sm font-black text-red-600 uppercase tracking-tight">Instant Force Close</h4>
                                <p class="text-[10px] text-red-400 font-bold uppercase">Lock the mall immediately for everyone</p>
                            </div>
                            <select name="settings[mall_force_closed]" class="bg-white border-2 border-red-100 rounded-xl px-4 py-2 text-xs font-black text-red-600 outline-none">
                                <option value="0" <?= ($settings['mall_force_closed'] ?? '0') == '0' ? 'selected' : '' ?>>OFF (Live)</option>
                                <option value="1" <?= ($settings['mall_force_closed'] ?? '0') == '1' ? 'selected' : '' ?>>ON (Locked)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-[2rem] shadow-xl hover:bg-blue-600 transition-all uppercase tracking-[0.2em] text-xs">
                    Update Mall Status
                </button>
            </form>
        </main>
    </div>
</body>
</html>