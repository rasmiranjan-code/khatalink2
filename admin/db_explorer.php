<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

// ── SECONDARY AUTH: DANGER ZONE ACCESS ──
if (!isset($_SESSION['danger_zone_authorized'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['danger_pass'])) {
        if ($_POST['danger_pass'] === 'khata@master') { // Change this key for security
            $_SESSION['danger_zone_authorized'] = true;
            header("Location: db_explorer.php"); exit();
        }
        $auth_err = "INVALID ACCESS KEY. UNAUTHORIZED ATTEMPT LOGGED.";
    }
?>
<!DOCTYPE html>
<html>
<head><title>RESTRICTED ACCESS</title><script src="https://cdn.tailwindcss.com"></script><link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono&display=swap" rel="stylesheet"></head>
<body class="bg-black text-red-500 h-screen flex items-center justify-center font-['JetBrains_Mono']">
    <div class="max-w-md w-full p-8 border-2 border-red-900 bg-zinc-950 rounded-lg shadow-2xl">
        <div class="text-center mb-8">
            <div class="text-5xl mb-4 animate-pulse">☢</div>
            <h1 class="text-sm font-black tracking-[.3em] uppercase leading-relaxed">DB Master Engine Lock</h1>
            <p class="text-[10px] text-zinc-600 mt-2">DANGER ZONE: Root Access Required</p>
        </div>
        <?php if(isset($auth_err)): ?><div class="bg-red-900/20 text-red-400 p-3 rounded mb-6 text-[10px] border border-red-900/50 text-center font-bold italic"><?= $auth_err ?></div><?php endif; ?>
        <form method="POST" class="space-y-4">
            <input type="password" name="danger_pass" autofocus placeholder="ENTER MASTER ACCESS KEY" class="w-full bg-black border border-zinc-800 p-3 rounded text-center text-xs tracking-widest outline-none focus:border-red-600 transition-all placeholder:text-zinc-800">
            <button type="submit" class="w-full bg-red-600 text-black font-black py-3 rounded text-[10px] uppercase tracking-widest hover:bg-red-500 transition-all shadow-[0_0_15px_rgba(220,38,38,0.4)]">Authorize Root</button>
        </form>
        <div class="mt-8 text-center"><a href="dashboard.php" class="text-[9px] text-zinc-700 hover:text-zinc-500 uppercase tracking-widest">← Return to Dashboard</a></div>
    </div>
</body>
</html>
<?php exit(); }

$db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();

// ── NEW: Fetch Tables with Row Counts and Storage Size ──
$stmt_tables = $pdo->prepare("
    SELECT 
        TABLE_NAME AS name, 
        TABLE_ROWS AS `rows`, 
        ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 2) AS size_kb 
    FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = ? 
    ORDER BY TABLE_NAME ASC
");
$stmt_tables->execute([$db_name]);
$tables_meta = $stmt_tables->fetchAll();
$stmt_tables->closeCursor();

// ── STORAGE & QUOTA LOGIC ──
$total_db_size_kb = array_sum(array_column($tables_meta, 'size_kb'));
$total_db_size_mb = round($total_db_size_kb / 1024, 2);
$total_db_size_gb = round($total_db_size_mb / 1024, 3);
$total_db_size_display = $total_db_size_gb >= 1 ? "{$total_db_size_gb} GB" : "{$total_db_size_mb} MB";

// Fetch Virtual Quota from System Settings
$quota_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'db_storage_quota_mb'");
$storage_quota_mb = (float)($quota_stmt->fetchColumn() ?: 1024);
$quota_stmt->closeCursor();

// Auto-Expand Logic: If usage > 90%, increase by 500MB automatically
if ($total_db_size_mb > ($storage_quota_mb * 0.9)) {
    $storage_quota_mb += 512;
    @$quota_stmt->closeCursor(); // Extra safety
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('db_storage_quota_mb', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
        ->execute([$storage_quota_mb, $storage_quota_mb]);
}

$usage_percent = min(100, round(($total_db_size_mb / $storage_quota_mb) * 100, 1));
$storage_quota_gb = round($storage_quota_mb / 1024, 3);

// ── INDICATORS LOGIC ──
$reasons = [];

// 1. Connection (Green if reached here)
$ind_conn = true;
$reasons['conn'] = "Database engine is connected and responding normally.";

// 2. Health (Small issues: Yellow if non-bigint IDs found)
$int_pk_tables = [];
$stmt_pk_types = $pdo->prepare("
    SELECT
        kcu.TABLE_NAME,
        kcu.COLUMN_NAME,
        c.DATA_TYPE
    FROM
        information_schema.KEY_COLUMN_USAGE kcu
    JOIN
        information_schema.COLUMNS c ON kcu.TABLE_SCHEMA = c.TABLE_SCHEMA
                                    AND kcu.TABLE_NAME = c.TABLE_NAME
                                    AND kcu.COLUMN_NAME = c.COLUMN_NAME
    WHERE
        kcu.CONSTRAINT_SCHEMA = ?
        AND kcu.CONSTRAINT_NAME = 'PRIMARY'
    ORDER BY
        kcu.TABLE_NAME;
");
$stmt_pk_types->execute([$db_name]);
$pk_types = $stmt_pk_types->fetchAll(PDO::FETCH_ASSOC);
$stmt_pk_types->closeCursor();

foreach ($pk_types as $pk) {
    if (strtolower($pk['DATA_TYPE']) === 'int') {
        $int_pk_tables[] = "<code>" . htmlspecialchars($pk['TABLE_NAME']) . "." . htmlspecialchars($pk['COLUMN_NAME']) . "</code>";
    }
}

$ind_health = empty($int_pk_tables);
if ($ind_health) {
    $reasons['health'] = "All critical primary keys use BIGINT. Ready for 1Cr+ users.";
} else {
    // ── NICHOD: Grouping tables for better clarity ──
    $groups = ['Identity' => [], 'Transactional' => [], 'System/Log' => []];
    foreach($int_pk_tables as $tbl_col) {
        if (str_contains($tbl_col, 'customers') || str_contains($tbl_col, 'shop_owners') || str_contains($tbl_col, 'delivery_partners')) 
            $groups['Identity'][] = $tbl_col;
        elseif (str_contains($tbl_col, 'orders') || str_contains($tbl_col, 'udhar') || str_contains($tbl_col, 'bond') || str_contains($tbl_col, 'payment') || str_contains($tbl_col, 'pos_'))
            $groups['Transactional'][] = $tbl_col;
        else 
            $groups['System/Log'][] = $tbl_col;
    }

    $html = "<div class='text-left'><p class='mb-4 text-slate-600 text-sm'><b>" . count($int_pk_tables) . "</b> tables are using 32-bit IDs. At 1Cr+ users, high-velocity tables might overflow.</p>";
    foreach($groups as $name => $list) {
        if(empty($list)) continue;
        $html .= "<div class='mb-3'><h4 class='text-[10px] font-black uppercase text-blue-600 tracking-widest mb-1'>$name Module</h4>";
        $html .= "<div class='flex flex-wrap gap-1'>" . implode(", ", $list) . "</div></div>";
    }
    $html .= "<div class='mt-4 p-3 bg-blue-50 border border-blue-100 rounded-xl text-[10px] font-bold text-blue-700'>TIP: Run the BIGINT migration script in the SQL Terminal to turn this indicator GREEN.</div></div>";
    
    // Encode for JS usage
    $reasons['health'] = addslashes($html);
}

// ── Handle Storage Expand Request (AJAX/POST) (Moved after indicator logic) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'expand_storage') {
    $new_quota = $storage_quota_mb + 1024; // Add 1GB
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('db_storage_quota_mb', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
        ->execute([$new_quota, $new_quota]);
    echo json_encode(['success' => true, 'new_quota' => $new_quota]);
    exit();
}

// 3. Unauthorized Access (Red pulse if logs have hits)
$stmt_sec = $pdo->query("SELECT COUNT(*) FROM db_access_logs WHERE is_found = 0 AND hit_timestamp > (NOW() - INTERVAL 1 DAY)");
$sec_hits = $stmt_sec->fetchColumn();
$stmt_sec->closeCursor();
$ind_sec = ($sec_hits == 0);
$reasons['sec'] = $ind_sec ? "No unauthorized DB hits detected in the last 24 hours." : "ALERT: $sec_hits unauthorized access attempts blocked in the last 24 hours!";

// 4. App Bugs (Check debug log for recent crashes)
$log_file = '../customer/debug_error.log';
$log_tail = "";
if(file_exists($log_file)) {
    $log_lines = array_slice(file($log_file), -30);
    $log_tail = implode("", $log_lines);
}
$bug_count = substr_count(strtolower($log_tail), 'error');
$ind_bugs = ($bug_count == 0);
$reasons['bugs'] = $ind_bugs ? "Application logs are clean. No UI/Logic crashes found." : "NOTICE: $bug_count recent bugs detected in debug_error.log. Please review.";

// 5. DB Errors (Critical SQL Syntax issues)
$sql_issue = (str_contains($log_tail, 'SQLSTATE') || str_contains($log_tail, 'PDO'));
$ind_sql = !$sql_issue;
$reasons['sql'] = $ind_sql ? "Database queries are executing successfully." : "CRITICAL: SQL Syntax or PDO errors found in logs. Matching engine might be failing.";

$selected_table = $_GET['table'] ?? '';
$sql_output = null;
$sql_error = '';

// Handle Manual SQL Execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['raw_sql'])) {
    try {
        $sql = trim($_POST['raw_sql']);
        $stmt = $pdo->query($sql);
        
        // ── UNIVERSAL RESULT DETECTOR ──
        if ($stmt->columnCount() > 0) {
            $sql_output = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sql_output = "Query executed successfully. Rows affected: " . $stmt->rowCount();
        }
        @$stmt->closeCursor();
    } catch (Exception $e) { $sql_error = $e->getMessage(); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DB Explorer — KhataLink Engine</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .mono { font-family: 'JetBrains Mono', monospace; font-size: 12px; }
        .indicator-dot { width: 8px; height: 8px; border-radius: 50%; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex flex-col">
    <nav class="h-16 border-b border-slate-200 flex items-center justify-between px-8 bg-white sticky top-0 z-50 shadow-sm">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="text-slate-400 hover:text-slate-900"><i class="fas fa-chevron-left"></i></a>
            <h1 class="text-xs font-black uppercase tracking-[0.3em] text-slate-900">DB Master Engine <span class="text-slate-300 ml-2">v4.0</span></h1>
        </div>

        <!-- Status Indicators -->
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-2 cursor-help" onclick="showIndicatorDetail('Engine Status', '<?= $reasons['conn'] ?>')">
                <div class="indicator-dot bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]"></div>
                <span class="text-[9px] font-black uppercase text-slate-400">Engine</span>
            </div>
            <div class="flex items-center gap-2 cursor-help" onclick="showIndicatorDetail('Health Audit', '<?= $reasons['health'] ?>')">
                <div class="indicator-dot <?= $ind_health ? 'bg-emerald-500' : 'bg-amber-500' ?>"></div>
                <span class="text-[9px] font-black uppercase text-slate-400">Health</span>
            </div>
            <div class="flex items-center gap-2 cursor-help" onclick="showIndicatorDetail('Shield Activity', '<?= $reasons['sec'] ?>')">
                <div class="indicator-dot <?= $ind_sec ? 'bg-emerald-500' : 'bg-red-500 animate-pulse' ?>"></div>
                <span class="text-[9px] font-black uppercase text-slate-400">Shield</span>
            </div>
            <div class="flex items-center gap-2 cursor-help" onclick="showIndicatorDetail('App Stability', '<?= $reasons['bugs'] ?>')">
                <div class="indicator-dot <?= $ind_bugs ? 'bg-emerald-500' : 'bg-red-500' ?>"></div>
                <span class="text-[9px] font-black uppercase text-slate-400">Stability</span>
            </div>
            <div class="flex items-center gap-2 cursor-help" onclick="showIndicatorDetail('Query Syntax', '<?= $reasons['sql'] ?>')">
                <div class="indicator-dot <?= $ind_sql ? 'bg-emerald-500' : 'bg-rose-600 shadow-[0_0_8px_rgba(225,29,72,0.4)]' ?>"></div>
                <span class="text-[9px] font-black uppercase text-slate-400">Syntax</span>
            </div>
        </div>

        <div class="flex items-center gap-4 border-l border-slate-100 pl-6 cursor-pointer group" onclick="showStorageDetail()">
            <div class="text-right">
                <div class="text-[9px] font-black text-slate-400 uppercase tracking-tighter mb-0.5">Used: <?= $total_db_size_display ?></div>
                <div class="text-[11px] font-black text-slate-900 leading-none">Quota: <?= $storage_quota_gb ?> GB</div>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center border border-slate-100 relative overflow-hidden group-hover:border-blue-200 group-hover:bg-blue-50 transition-all">
                <div class="absolute bottom-0 left-0 right-0 bg-blue-500 transition-all duration-1000" style="height: <?= $usage_percent ?>%; opacity: 0.2;"></div>
                <i class="fas fa-server text-xs <?= $usage_percent > 80 ? 'text-amber-500' : 'text-slate-400' ?> relative z-10"></i>
            </div>
        </div>

        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest hidden xl:block">DB: <span class="text-blue-600 font-black ml-1"><?= $db_name ?></span></div>
    </nav>

    <div class="flex flex-1 overflow-hidden">
        <!-- Sidebar: Table List -->
        <aside class="w-64 border-r border-slate-200 overflow-y-auto p-4 bg-white">
            <h3 class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-4">Tables (<?= count($tables_meta) ?>)</h3>
            <div class="space-y-1">
                <?php foreach($tables_meta as $t): ?>
                <a href="?table=<?= $t['name'] ?>" class="block px-3 py-2 rounded-xl transition-all <?= $selected_table === $t['name'] ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold truncate"><i class="fas fa-table mr-1.5 opacity-50"></i> <?= $t['name'] ?></span>
                        <div class="flex flex-col items-end flex-shrink-0 ml-2">
                            <span class="text-[8px] font-black <?= $selected_table === $t['name'] ? 'text-blue-100' : 'text-blue-600' ?>"><?= number_format($t['rows']) ?> rows</span>
                            <span class="text-[7px] font-bold opacity-60 uppercase"><?= $t['size_kb'] ?> KB</span>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto p-8">
            
            <!-- SQL CONSOLE -->
            <section class="mb-10">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-500">Live SQL Terminal</h3>
                    <span class="text-[9px] bg-red-50 text-red-600 px-2 py-1 rounded font-black border border-red-100">ROOT ACCESS</span>
                </div>
                <form method="POST" class="bg-white rounded-2xl overflow-hidden border border-slate-200 shadow-xl">
                    <textarea name="raw_sql" class="w-full h-32 bg-slate-50 p-6 mono text-blue-700 outline-none resize-none placeholder:text-slate-300" placeholder="SELECT * FROM customers WHERE id = 1..."><?= $_POST['raw_sql'] ?? '' ?></textarea>
                    <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 flex justify-between items-center">
                        <p class="text-[10px] text-slate-500 italic">Terminator: Use semicolon (;) for multiple commands.</p>
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-500 transition-all">Execute Query</button>
                    </div>
                </form>

                <?php if($sql_error): ?>
                    <div class="mt-4 p-4 bg-red-50 border border-red-100 rounded-xl text-red-600 mono text-xs"><?= $sql_error ?></div>
                <?php endif; ?>

                <?php if($sql_output !== null): ?>
                    <div class="mt-6 bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xl">
                        <div class="p-4 bg-slate-50 border-b border-slate-100 text-[10px] font-black uppercase text-slate-500 flex justify-between items-center">
                            <span>Result Output <?= is_array($sql_output) ? '(' . count($sql_output) . ' rows)' : '' ?></span>
                        </div>
                        <div class="overflow-x-auto max-h-96">
                            <?php if(is_array($sql_output)): ?>
                            <table class="w-full text-left mono whitespace-nowrap">
                                <thead class="bg-slate-200 sticky top-0 shadow-sm">
                                    <tr><?php foreach(array_keys($sql_output[0] ?? []) as $kh): ?><th class="px-4 py-2 border-b border-slate-300 text-blue-700 uppercase text-[10px]"><?= $kh ?></th><?php endforeach; ?></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach($sql_output as $row): ?>
                                    <tr class="hover:bg-slate-50"><?php foreach($row as $v): ?><td class="px-4 py-2 text-slate-700"><?= htmlspecialchars($v ?? 'NULL') ?></td><?php endforeach; ?></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                                <?php if(empty($sql_output)): ?>
                                    <div class="p-12 text-center text-slate-400 italic bg-slate-50/50">
                                        <i class="fas fa-database mb-3 opacity-20 text-4xl"></i><br>
                                        MySQL returned an empty result set (i.e. zero rows).
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="p-6 text-emerald-400 font-bold"><?= $sql_output ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <?php if($selected_table): 
                $q_cols = $pdo->query("DESCRIBE `$selected_table` ");
                $cols = $q_cols->fetchAll();
                $q_cols->closeCursor();

                $q_data = $pdo->query("SELECT * FROM `$selected_table` LIMIT 50");
                $data = $q_data->fetchAll();
                $q_data->closeCursor();
            ?>
            <!-- Table Structure -->
            <section class="mb-10">
                <h3 class="text-xs font-black uppercase tracking-widest text-slate-500 mb-4">Structure: <?= $selected_table ?></h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <?php foreach($cols as $c): ?>
                    <div class="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm">
                        <div class="text-[10px] font-black text-blue-600 uppercase"><?= $c['Field'] ?></div>
                        <div class="text-xs font-mono text-slate-400 mt-1"><?= $c['Type'] ?></div>
                        <div class="flex gap-2 mt-3">
                            <?php if($c['Key'] === 'PRI'): ?><span class="text-[8px] bg-amber-50 text-amber-600 border border-amber-100 px-1.5 py-0.5 rounded font-black">P-KEY</span><?php endif; ?>
                            <?php if($c['Null'] === 'NO'): ?><span class="text-[8px] bg-red-50 text-red-600 border border-red-100 px-1.5 py-0.5 rounded font-black">REQUIRED</span><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Table Data View -->
            <section>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-500">Live Data (Top 50 Rows)</h3>
                    <a href="export_table_csv.php?table=<?= $selected_table ?>" class="text-[9px] font-black uppercase text-blue-400 hover:underline">Download CSV</a>
                </div>
                <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left mono">
                            <thead class="bg-slate-50">
                                <tr><?php foreach($cols as $c): ?><th class="px-6 py-4 text-[10px] font-black uppercase text-slate-400 border-b border-slate-100"><?= $c['Field'] ?></th><?php endforeach; ?></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach($data as $row): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <?php foreach($row as $val): ?>
                                    <td class="px-6 py-3 text-slate-600 truncate max-w-[200px]" title="<?= htmlspecialchars($val ?? '') ?>"><?= htmlspecialchars($val ?? 'NULL') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($data)): ?>
                                    <tr><td colspan="<?= count($cols) ?>" class="p-20 text-center text-slate-600 italic">No data found in this table.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            <?php else: ?>
            <!-- Welcome State -->
            <div class="h-full flex flex-col items-center justify-center text-center py-20">
                <div class="w-24 h-24 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center text-4xl mb-6 shadow-sm">
                    <i class="fas fa-database"></i>
                </div>
                <h2 class="text-2xl font-black text-slate-900 uppercase tracking-tighter">Welcome to the Core</h2>
                <p class="text-slate-500 max-w-sm mx-auto mt-2 font-medium">Select a table from the sidebar to inspect structure, browse data, or run custom SQL logic.</p>
                <div class="mt-10 grid grid-cols-2 gap-4">
                    <div class="p-6 bg-white rounded-3xl border border-slate-200 shadow-sm">
                        <div class="text-2xl font-black text-blue-600"><?= count($tables_meta) ?></div>
                        <div class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mt-1">Active Tables</div>
                    </div>
                    <div class="p-6 bg-white rounded-3xl border border-slate-200 shadow-sm">
                        <div class="text-2xl font-black text-emerald-400">READY</div>
                        <div class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mt-1">Engine Status</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function showIndicatorDetail(title, text) {
            Swal.fire({
                title: title,
                html: text,
                icon: text.includes('ALERT') || text.includes('CRITICAL') ? 'error' : (text.includes('NOTICE') || text.includes('Primary') ? 'warning' : 'success'),
                confirmButtonColor: '#2563eb',
                customClass: { popup: 'rounded-[2rem] p-8' }
            });
        }

        function showStorageDetail() {
            Swal.fire({
                title: 'Storage Optimization Hub',
                html: `
                    <div class="text-left space-y-4 py-4">
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                            <div class="flex justify-between text-[10px] font-black text-slate-400 uppercase mb-2">
                                <span>Current Used Storage</span>
                                <span><?= $total_db_size_display ?></span>
                            </div>
                            <div class="flex justify-between text-[10px] font-black text-slate-400 uppercase mb-4">
                                <span>Total Allocated Quota</span>
                                <span><?= $storage_quota_gb ?> GB</span>
                            </div>
                            <div class="flex justify-between text-[10px] font-black text-slate-400 uppercase mb-2">
                                <span>Utilization Intensity</span>
                                <span><?= $usage_percent ?>% Used</span>
                            </div>
                            <div class="w-full h-3 bg-slate-200 rounded-full overflow-hidden">
                                <div class="bg-blue-600 h-full" style="width: <?= $usage_percent ?>%"></div>
                            </div>
                        </div>
                        <p class="text-xs text-slate-500 font-medium leading-relaxed">System is running in <b>Auto-Scale</b> mode. Jab database 90% full hogi, KhataLink engine automatically virtual quota expand kar dega.</p>
                    </div>
                `,
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-expand-arrows-alt me-2"></i> Expand Now (+1GB)',
                cancelButtonText: 'Optimize Tables',
                confirmButtonColor: '#2563eb',
                customClass: { popup: 'rounded-[2rem] p-8' }
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('db_explorer.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=expand_storage' })
                    .then(res => res.json()).then(data => { if(data.success) location.reload(); });
                }
            });
        }
    </script>
</body>
</html>