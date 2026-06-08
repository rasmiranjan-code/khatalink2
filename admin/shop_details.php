<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/razorpay_config.php';
if(!isset($_SESSION['admin_id']) || !isset($_GET['id'])) { header("Location: login.php"); exit(); }
$shop_id = (int)$_GET['id'];

// Fetch current admin role for the navbar display
$stmt_role = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
$stmt_role->execute([$_SESSION['admin_id']]);
$admin_role = $stmt_role->fetchColumn() ?: 'team';

// 1. Fetch Core Shop Info
$stmt = $pdo->prepare("SELECT * FROM shop_owners WHERE id = ?");
$stmt->execute([$shop_id]);
$s = $stmt->fetch();
if(!$s) die("Shop not found.");

// 2. Aggregated Stats
$stats = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM shop_customers WHERE shop_id = ?) as total_customers,
        (SELECT COUNT(*) FROM bonds WHERE shop_id = ? AND status != 'closed') as active_bonds,
        (SELECT COUNT(*) FROM monthly_khata WHERE shop_id = ? AND status = 'open') as active_monthly,
        (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = ? AND status = 'open') as total_receivables,
        (SELECT COALESCE(SUM(amount_paid), 0) FROM payment_history WHERE shop_id = ?) as total_collected
");
$stats->execute([$shop_id, $shop_id, $shop_id, $shop_id, $shop_id]);
$sm = $stats->fetch();

// 3. Customer List (with their specific due at this shop)
$customers_stmt = $pdo->prepare("
    SELECT c.*, sc.added_at,
    (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = ? AND customer_id = c.id AND status = 'open') as shop_due
    FROM customers c
    JOIN shop_customers sc ON c.id = sc.customer_id
    WHERE sc.shop_id = ?
    ORDER BY shop_due DESC
");
$customers_stmt->execute([$shop_id, $shop_id]);
$customer_list = $customers_stmt->fetchAll();

// 4. Active Bonds for this Shop
$bonds_stmt = $pdo->prepare("
    SELECT b.*, c.name as customer_name, c.unique_id
    FROM bonds b
    JOIN customers c ON b.customer_id = c.id
    WHERE b.shop_id = ? AND b.status != 'closed'
");
$bonds_stmt->execute([$shop_id]);
$bond_list = $bonds_stmt->fetchAll();

// 5. Monthly Khata List
$monthly_stmt = $pdo->prepare("
    SELECT mk.*, c.name as customer_name, c.unique_id
    FROM monthly_khata mk
    JOIN customers c ON mk.customer_id = c.id
    WHERE mk.shop_id = ? AND mk.status = 'open'
");
$monthly_stmt->execute([$shop_id]);
$monthly_list = $monthly_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($s['shop_name']) ?> — Merchant Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .kundfali-card { border-radius: 2rem; border: 1px solid #e2e8f0; background: #fff; overflow: hidden; }
        .nav-tabs { border: none; gap: 10px; }
        .nav-tabs .nav-link { border: none; border-radius: 12px; font-weight: 800; font-size: 11px; text-transform: uppercase; color: #64748b; padding: 10px 20px; }
        .nav-tabs .nav-link.active { background: #0f172a; color: #fff; }
    </style>
</head>
<body class="p-4 md:p-8">
<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="kl-navbar">
    <div style="display:flex; align-items:center; gap:16px;">
        <button class="mobile-menu-btn" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="../index.php" class="kl-logo">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo" style="height: 50px;">
        </a>
    </div>
    <div style="display:flex; align-items:center; gap:16px;">
        <span style="font-size:14px; font-weight:600; color:#374151;">
            <?= htmlspecialchars($_SESSION['admin_name']) ?>
            <span style="color:#d97706; font-size:11px; margin-left:4px;">
                (<?= ucfirst($admin_role) ?>)
            </span>
        </span>
        <a href="logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</nav>
<div class="max-w-7xl mx-auto">
    
    <!-- Header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <a href="shops.php" class="w-10 h-10 bg-white border border-slate-200 rounded-xl flex items-center justify-center text-slate-400 hover:text-blue-600 transition-all"><i class="fas fa-arrow-left"></i></a>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= htmlspecialchars($s['shop_name']) ?></h1>
            </div>
            <p class="text-slate-500 text-sm font-medium ml-12">Merchant Master Profile & Kundfali</p>
        </div>
        <div class="flex gap-2 ml-12 md:ml-0">
            <a href="export_shop_report.php?shop_id=<?= $shop_id ?>" class="bg-emerald-600 text-white px-6 py-3 rounded-2xl font-bold text-xs uppercase tracking-widest flex items-center gap-2 shadow-lg shadow-emerald-100 hover:bg-emerald-700">
                <i class="fas fa-file-csv"></i> Download Statement
            </a>
            <a href="shop_pos_recovery.php?shop_id=<?= $shop_id ?>" class="bg-red-600 text-white px-6 py-3 rounded-2xl font-bold text-xs uppercase tracking-widest flex items-center gap-2 shadow-lg shadow-red-100 hover:bg-red-700">
                <i class="fas fa-trash-restore"></i> Recover Deleted POS Bills
            </a>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white border border-slate-200 rounded-3xl p-5">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Receivables</div>
            <div class="text-2xl font-black text-red-600">₹<?= number_format($sm['total_receivables'], 2) ?></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-3xl p-5">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Collections</div>
            <div class="text-2xl font-black text-emerald-600">₹<?= number_format($sm['total_collected'], 2) ?></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-3xl p-5">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Bond Holders</div>
            <div class="text-2xl font-black text-slate-900"><?= $sm['active_bonds'] ?></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-3xl p-5">
            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Monthly Subs</div>
            <div class="text-2xl font-black text-slate-900"><?= $sm['active_monthly'] ?></div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left: Merchant Identity -->
        <div class="col-lg-4">
            <div class="kundfali-card p-6 md:p-8 sticky top-8">
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-6 border-b border-slate-50 pb-4">Merchant Identity</h3>
                
                <div class="space-y-6">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Owner Name</label>
                        <div class="text-sm font-bold text-slate-800"><?= htmlspecialchars($s['name']) ?></div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Category</label>
                        <span class="bg-blue-50 text-blue-600 text-[10px] font-black px-3 py-1 rounded-lg uppercase"><?= htmlspecialchars($s['shop_category']) ?></span>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Payment Gateway ID</label>
                        <code class="text-blue-600 font-bold"><?= $s['rzp_account_id'] ?: 'Not Linked' ?></code>
                    </div>
                    <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                        <div class="text-[10px] font-black text-slate-400 uppercase mb-3 flex items-center gap-2"><i class="fas fa-university"></i> Bank Credentials</div>
                        <div class="text-xs font-bold text-slate-700 mb-1">Acc: <?= $s['bank_acc_no'] ?: '—' ?></div>
                        <div class="text-xs font-bold text-slate-700">IFSC: <?= $s['bank_ifsc'] ?: '—' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Kundfali Lists -->
        <div class="col-lg-8">
            <div class="kundfali-card">
                <div class="p-4 border-b border-slate-100 bg-slate-50/50">
                    <ul class="nav nav-tabs" id="kundfaliTab" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-customers">Customers (<?= count($customer_list) ?>)</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bonds">Bonds (<?= count($bond_list) ?>)</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-monthly">Monthly (<?= count($monthly_list) ?>)</button></li>
                    </ul>
                </div>

                <div class="tab-content p-6">
                    <!-- TAB: CUSTOMERS -->
                    <div class="tab-pane fade show active" id="tab-customers">
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                        <th>Customer</th>
                                        <th>Unique ID</th>
                                        <th class="text-end">Balance Due</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php foreach($customer_list as $c): ?>
                                    <tr>
                                        <td>
                                            <div class="font-bold text-slate-900"><?= htmlspecialchars($c['name']) ?></div>
                                            <div class="text-[10px] text-slate-400">Added: <?= date('d M Y', strtotime($c['added_at'])) ?></div>
                                        </td>
                                        <td><span class="text-xs font-mono font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded"><?= $c['unique_id'] ?></span></td>
                                        <td class="text-end font-black text-red-600">₹<?= number_format($c['shop_due'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB: BONDS -->
                    <div class="tab-pane fade" id="tab-bonds">
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                        <th>Customer</th>
                                        <th>Bond Value</th>
                                        <th>Remaining</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php foreach($bond_list as $b): ?>
                                    <tr>
                                        <td>
                                            <div class="font-bold text-slate-900"><?= htmlspecialchars($b['customer_name']) ?></div>
                                            <div class="text-[10px] text-slate-400">Due: <?= date('d M Y', strtotime($b['due_date'])) ?></div>
                                        </td>
                                        <td class="text-sm font-bold">₹<?= number_format($b['amount'], 0) ?></td>
                                        <td class="text-sm font-black text-red-600">₹<?= number_format($b['amount'] - $b['paid_amount'], 0) ?></td>
                                        <td><span class="text-[9px] font-black px-3 py-1 rounded-full uppercase <?= $b['status'] == 'overdue' ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-blue-600' ?>"><?= $b['status'] ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($bond_list)): ?><tr><td colspan="4" class="text-center py-5 text-slate-400 italic text-sm">No active bonds found.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB: MONTHLY -->
                    <div class="tab-pane fade" id="tab-monthly">
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                        <th>Customer</th>
                                        <th>Cycle Start</th>
                                        <th class="text-end">Current Bill</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php foreach($monthly_list as $m): ?>
                                    <tr>
                                        <td>
                                            <div class="font-bold text-slate-900"><?= htmlspecialchars($m['customer_name']) ?></div>
                                            <div class="text-[10px] text-slate-400">UID: <?= $m['unique_id'] ?></div>
                                        </td>
                                        <td class="text-xs font-bold text-slate-600"><?= date('d M Y', strtotime($m['start_date'])) ?></td>
                                        <td class="text-end font-black text-blue-600">₹<?= number_format($m['total_amount'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($monthly_list)): ?><tr><td colspan="3" class="text-center py-5 text-slate-400 italic text-sm">No active monthly cycles.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Sidebar toggle functions for mobile
    function openSidebar() {
        document.getElementById('sidebar').classList.add('open');
        document.getElementById('overlay').classList.add('show');
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('overlay').classList.remove('show');
    }
</script>
</body>
</html>