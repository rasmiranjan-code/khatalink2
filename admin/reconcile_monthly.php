<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['settle_id'])) {
    $pdo->prepare("UPDATE monthly_khata SET is_settled_manually = ? WHERE id = ?")->execute([(int)$_POST['status'], (int)$_POST['settle_id']]);
    header("Location: reconcile_monthly.php?success=Monthly status updated.");
    exit();
}

$query = "SELECT mk.*, s.shop_name, s.bank_acc_no, s.bank_ifsc, c.name as customer_name
          FROM monthly_khata mk
          JOIN shop_owners s ON mk.shop_id = s.id
          JOIN customers c ON mk.customer_id = c.id
          WHERE mk.razorpay_payment_id IS NOT NULL AND mk.status = 'closed'
          AND (s.rzp_account_id IS NULL OR s.rzp_account_id = '')
          ORDER BY mk.created_at DESC";
$payments = $pdo->query($query)->fetchAll();

// Stats Calculation
$total_funds_held = 0;
$total_profit = 0;
$pending_count = 0;
$settled_today = 0;
foreach($payments as $p) {
    $base_amt = (float)$p['paid_amount']; // Original amount before customer fees
    $cust_paid = $base_amt * (1 + (MONTHLY_PLATFORM_COMMISSION_PERCENT / 100)); // Customer pays base + 3%
    $pg_cost = $cust_paid * (PG_FEE_PERCENT / 100); // PG takes this from customer's payment
    $payout = $base_amt * (1 - (SHOP_SERVICE_FEE_PERCENT / 100)); // Shop gets base minus 1%
    $comm = $cust_paid - $pg_cost - $payout; // KhataLink's net profit
    if (!$p['is_settled_manually']) {
        $total_funds_held += $payout;
        $total_profit += $comm;
        $pending_count++;
    }
    if ($p['is_settled_manually'] && date('Y-m-d', strtotime($p['created_at'])) == date('Y-m-d')) {
        $settled_today++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Settlements — KhataLink Admin</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
          * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; }
        .kl-navbar { background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .kl-logo { font-size: 22px; font-weight: 800; color: #0f172a; text-decoration: none; letter-spacing: -0.5px; }
        .layout { display: flex; min-height: calc(100vh - 64px); }
        .sidebar { width: 250px; background: #ffffff; border-right: 1px solid #e2e8f0; padding: 24px 16px; flex-shrink: 0; position: sticky; top: 64px; height: calc(100vh - 64px); overflow-y: auto; }
        .sidebar-label { font-size: 10px; font-weight: 700; color: #94a3b8; letter-spacing: 1.5px; text-transform: uppercase; padding: 0 12px; margin-bottom: 8px; margin-top: 20px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 12px; border-radius: 10px; color: #64748b; font-size: 14px; font-weight: 500; text-decoration: none; transition: all 0.2s; margin-bottom: 2px; }
        .nav-link:hover { background: #eff6ff; color: #2563eb; }
        .nav-link.active { background: #eff6ff; color: #2563eb; font-weight: 600; }
        .main { flex: 1; padding: 32px; overflow-x: hidden; }
        .page-title { font-size: 26px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 4px; }
        .page-subtitle { font-size: 14px; color: #64748b; margin-bottom: 28px; }
        .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px 24px; }
        .stat-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 17px; margin-bottom: 14px; }
        .stat-label { font-size: 13px; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 28px; font-weight: 800; color: #0f172a; letter-spacing: -1px; }
        .kl-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; }
        .card-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid #f1f5f9; }
        .card-title { font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        .kl-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .kl-table thead th { background: #f8fafc; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 20px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
        .kl-table tbody td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; color: #374151; vertical-align: middle; }
        .badge-uid { background: #eff6ff; color: #2563eb; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 6px; }
        .bank-details { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; font-size: 12px; }
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 22px; cursor: pointer; padding: 8px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 998; }
        @media (max-width: 992px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; z-index: 999; transform: translateX(-100%); transition: transform 0.3s ease; box-shadow: 0 0 30px rgba(0,0,0,0.15); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .mobile-menu-btn { display: flex; }
        }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<nav class="kl-navbar">
     <div style="display:flex; align-items:center; gap:16px;">
        <button class="mobile-menu-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
        <a href="dashboard.php" class="kl-logo">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo" style="height: 50px;">
        </a>
    </div>
    <div style="display:flex; align-items:center; gap:16px;">
        <span style="font-size:14px; font-weight:600; color:#374151;"><?= htmlspecialchars($_SESSION['admin_name']) ?></span>
    </div>
</nav>

<div class="layout">
    <?php include '../includes/admin_sidebar.php'; ?>

    <main class="main">
         <div class="page-header">
            <div class="page-title">Monthly Settlements</div>
            <div class="page-subtitle">Reconcile recurring monthly khata funds held by the platform for unlinked merchants.</div>
        </div>

        <div class="stat-grid">
            <div class="stat-card shadow-sm">
                <div class="stat-icon" style="background:#ecfdf5; color:#059669;"><i class="fas fa-hand-holding-dollar"></i></div>
                <div class="stat-label">Net Payout Pending</div>
                <div class="stat-value text-emerald-600">₹<?= number_format($total_funds_held, 2) ?></div>
            </div>
            <div class="stat-card shadow-sm">
                <div class="stat-icon" style="background:#fef2f2; color:#dc2626;"><i class="fas fa-chart-line"></i></div>
                <div class="stat-label">Our Profit (2%)</div>
                <div class="stat-value text-danger">₹<?= number_format($total_profit, 2) ?></div>
            </div>
            <div class="stat-card shadow-sm">
                <div class="stat-icon" style="background:#eff6ff; color:#2563eb;"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Active Claims</div>
                <div class="stat-value"><?= $pending_count ?></div>
            </div>
            <div class="stat-card shadow-sm">
                <div class="stat-icon" style="background:#ecfdf5; color:#059669;"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Settled Today</div>
                <div class="stat-value"><?= $settled_today ?></div>
            </div>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4"><?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>

        <div class="kl-card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-list text-primary"></i> Reconciliation Ledger</div>
            </div>
            <div class="table-responsive">
                <table class="kl-table">
                    <thead>
                        <tr>
                            <th>Source & Date</th>
                            <th>Parties Involved</th>
                            <th class="text-right">Cust. Paid</th>
                            <th class="text-right">Comm.</th>
                            <th class="text-right">Shop Payout</th>
                             <th>Merchant Bank Credentials</th>
                            <th>Gateway Info</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payments as $p): 
                            $payout = (float)$p['paid_amount'];
                            $comm = $payout * (MONTHLY_PLATFORM_COMMISSION_PERCENT / 100);
                        ?>
                        <tr>
                            <td>
                                <span class="badge-uid" style="font-size:9px;">MONTHLY</span>
                                <div class="text-muted mt-1 small"><?= date('d M Y', strtotime($p['created_at'])) ?></div>
                            </td>
                             <td>
                                 <div class="fw-bold text-dark"><?= htmlspecialchars($p['shop_name']) ?></div>
                                <div class="text-muted small">Paid by: <?= htmlspecialchars($p['customer_name']) ?></div>
                            </td>
                            <td class="text-right"><div class="text-xs font-bold text-slate-500">₹<?= number_format($payout + $comm, 2) ?></div></td>
                            <td class="text-right"><div class="text-xs font-bold text-blue-500">₹<?= number_format($comm, 2) ?></div></td>
                            <td class="text-right"><div class="fw-black text-emerald-600">₹<?= number_format($payout, 2) ?></div></td>
                            <td>
                                <div class="bank-details">
                                    <div><strong>Acc:</strong> <?= $p['bank_acc_no'] ?: '<span class="text-danger">Missing</span>' ?></div>
                                    <div><strong>IFSC:</strong> <?= $p['bank_ifsc'] ?: 'N/A' ?></div>
                                </div>
                            </td>
                             <td>
                                <div class="text-muted" style="font-size: 10px;">ID: <span class="text-primary"><?= $p['razorpay_payment_id'] ?></span></div>
                            </td>
                            <td class="text-end">
                                <form method="POST">
                                    <input type="hidden" name="settle_id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $p['is_settled_manually'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-sm <?= $p['is_settled_manually'] ? 'btn-success' : 'btn-outline-danger' ?> fw-bold rounded-3 px-3">
                                        <?= $p['is_settled_manually'] ? '<i class="fas fa-check"></i> Settled' : 'Mark Paid' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($payments)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No pending settlements found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-5 p-4 bg-white border border-slate-200 rounded-4 shadow-sm">
            <h5 class="fw-bold h6 text-primary mb-3"><i class="fas fa-info-circle me-2"></i>Reconciliation Protocol</h5>
            <ol class="small text-muted mb-0">
                <li class="mb-2">Verify the merchant's bank details, process a manual transfer, and mark as <strong>"Settled"</strong>.</li>
                <li>Ensure you record the transaction reference in your internal accounting notes.</li>
            </ol>
        </div>
    </main>
</div>

<script>
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