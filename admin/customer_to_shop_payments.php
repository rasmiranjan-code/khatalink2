<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Handle Manual Settlement Toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['settle_id'])) {
    $id = (int)$_POST['settle_id'];
    $type = $_POST['settle_type'];
    $status = (int)$_POST['status'];

    try {
        if ($type == 'Bond') {
            $pdo->prepare("UPDATE bond_payments SET is_settled_manually = ? WHERE id = ?")->execute([$status, $id]);
        } elseif ($type == 'Monthly') {
            $pdo->prepare("UPDATE monthly_khata SET is_settled_manually = ? WHERE id = ?")->execute([$status, $id]);
        } elseif ($type == 'Ledger') {
            $pdo->prepare("UPDATE payment_requests SET is_settled_manually = ? WHERE id = ?")->execute([$status, $id]);
        }
        header("Location: customer_to_shop_payments.php?success=Settlement status updated successfully.");
        exit();
    } catch (Exception $e) {
        header("Location: customer_to_shop_payments.php?error=Operation failed. Please try again.");
        exit();
    }
}

// Query: Only fetch payments where Shop has NO Razorpay Account Linked
$query = "
    (SELECT 'Bond' as type, bp.id, bp.amount_paid as amount, bp.payment_date as date, bp.razorpay_payment_id, bp.razorpay_order_id, s.shop_name, s.rzp_account_id, bp.is_settled_manually, c.name as customer_name, s.bank_acc_no, s.bank_ifsc
     FROM bond_payments bp 
     JOIN bonds b ON bp.bond_id = b.id 
     JOIN shop_owners s ON b.shop_id = s.id 
     JOIN customers c ON b.customer_id = c.id
     WHERE bp.razorpay_payment_id IS NOT NULL AND (s.rzp_account_id IS NULL OR s.rzp_account_id = ''))
    UNION ALL
    (SELECT 'Monthly' as type, mk.id, mk.paid_amount as amount, mk.created_at as date, mk.razorpay_payment_id, mk.razorpay_order_id, s.shop_name, s.rzp_account_id, mk.is_settled_manually, c.name as customer_name, s.bank_acc_no, s.bank_ifsc
     FROM monthly_khata mk 
     JOIN shop_owners s ON mk.shop_id = s.id 
     JOIN customers c ON mk.customer_id = c.id
     WHERE mk.razorpay_payment_id IS NOT NULL AND mk.status = 'closed' AND (s.rzp_account_id IS NULL OR s.rzp_account_id = ''))
    UNION ALL
    (SELECT 'Ledger' as type, pr.id, pr.amount as amount, pr.created_at as date, pr.razorpay_payment_id, pr.razorpay_order_id, s.shop_name, s.rzp_account_id, pr.is_settled_manually, c.name as customer_name, s.bank_acc_no, s.bank_ifsc
     FROM payment_requests pr 
     JOIN shop_owners s ON pr.shop_id = s.id 
     JOIN customers c ON pr.customer_id = c.id
     WHERE pr.razorpay_payment_id IS NOT NULL AND pr.status = 'approved' AND (s.rzp_account_id IS NULL OR s.rzp_account_id = ''))
    ORDER BY date DESC
";

$stmt = $pdo->query($query);
$payments = $stmt->fetchAll();

// Stats Calculation
$total_funds_held = 0;
$pending_count = 0;
$settled_today = 0;
foreach($payments as $p) {
    if (!$p['is_settled_manually']) {
        $total_funds_held += (float)$p['amount'];
        $pending_count++;
    }
    if ($p['is_settled_manually'] && date('Y-m-d', strtotime($p['date'])) == date('Y-m-d')) {
        $settled_today++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Settlements — KhataLink Admin</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; }
        .kl-navbar { background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .kl-logo { font-size: 22px; font-weight: 800; color: #0f172a; text-decoration: none; letter-spacing: -0.5px; }
        .kl-logo span { color: #2563eb; }
        .layout { display: flex; min-height: calc(100vh - 64px); }
        .sidebar { width: 250px; background: #ffffff; border-right: 1px solid #e2e8f0; padding: 24px 16px; flex-shrink: 0; position: sticky; top: 64px; height: calc(100vh - 64px); overflow-y: auto; }
        .sidebar-label { font-size: 10px; font-weight: 700; color: #94a3b8; letter-spacing: 1.5px; text-transform: uppercase; padding: 0 12px; margin-bottom: 8px; margin-top: 20px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 12px; border-radius: 10px; color: #64748b; font-size: 14px; font-weight: 500; text-decoration: none; transition: all 0.2s; margin-bottom: 2px; }
        .nav-link i { width: 18px; font-size: 15px; text-align: center; }
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
            <div class="page-title">Platform Settlements</div>
            <div class="page-subtitle">Reconcile payments held by the platform for unlinked merchants.</div>
        </div>

        <!-- Stats Overview -->
        <div class="stat-grid">
            <div class="stat-card shadow-sm">
                <div class="stat-icon" style="background:#fef2f2; color:#dc2626;"><i class="fas fa-wallet"></i></div>
                <div class="stat-label">Pending Funds</div>
                <div class="stat-value text-danger">₹<?= number_format($total_funds_held, 2) ?></div>
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
                            <th>Amount</th>
                            <th>Merchant Bank Credentials</th>
                            <th>Gateway Info</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payments as $p): ?>
                        <tr>
                            <td>
                                <span class="badge-uid" style="font-size:9px;"><?= strtoupper($p['type']) ?></span>
                                <div class="text-muted mt-1 small"><?= date('d M Y', strtotime($p['date'])) ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($p['shop_name']) ?></div>
                                <div class="text-muted small">Paid by: <?= htmlspecialchars($p['customer_name']) ?></div>
                            </td>
                            <?php 
                                $base = (float)$p['amount'];
                                $fee_pct = ($p['type'] == 'Bond') ? BOND_PLATFORM_COMMISSION_PERCENT : (($p['type'] == 'Monthly') ? MONTHLY_PLATFORM_COMMISSION_PERCENT : LEDGER_PLATFORM_COMMISSION_PERCENT);
                                $gross = $base * (1 + ($fee_pct / 100));
                                $pg_fee = $gross * (PG_FEE_PERCENT / 100);
                                $payout = $base; // Full payout to merchant based on 0% shop service fee
                                $profit = $gross - $pg_fee - $payout;
                            ?>
                            <td class="text-right">
                                <div class="fw-black text-dark">₹<?= number_format($gross, 2) ?></div>
                                <div class="text-[9px] text-slate-400 uppercase">Gross Recv.</div>
                            </td>
                            <td class="text-right">
                                <div class="text-red-500 font-bold text-xs">₹<?= number_format($pg_fee, 2) ?></div>
                                <div class="text-[9px] text-slate-400 uppercase">PG Charges (<?= PG_FEE_PERCENT ?>%)</div>
                            </td>
                            <td class="text-right">
                                <div class="fw-black text-emerald-600">₹<?= number_format($payout, 2) ?></div>
                                <div class="text-[9px] text-slate-400 uppercase">Merchant Payout</div>
                            </td>
                            <td class="text-right">
                                <div class="fw-black text-blue-600">₹<?= number_format($profit, 2) ?></div>
                                <div class="text-[9px] text-slate-400 uppercase">KL Profit</div>
                            </td>
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
                                <?php if($p['is_settled_manually']): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="settle_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="settle_type" value="<?= $p['type'] ?>">
                                        <input type="hidden" name="status" value="0">
                                        <button type="submit" class="btn btn-sm btn-success fw-bold rounded-3 px-3">
                                            <i class="fas fa-check"></i> Settled
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="settle_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="settle_type" value="<?= $p['type'] ?>">
                                        <input type="hidden" name="status" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-danger fw-bold rounded-3 px-3">
                                            Mark Paid
                                        </button>
                                    </form>
                                <?php endif; ?>
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

        <!-- Operating Protocol -->
        <div class="mt-5 p-4 bg-white border border-slate-200 rounded-4 shadow-sm">
            <h5 class="fw-bold h6 text-primary mb-3"><i class="fas fa-info-circle me-2"></i>Reconciliation Protocol</h5>
            <ol class="small text-muted mb-0">
                <li class="mb-2">These payments are deposited in the platform account because the shop is not linked to Razorpay.</li>
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