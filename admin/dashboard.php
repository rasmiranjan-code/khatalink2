<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$current_admin = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$current_admin->execute([$_SESSION['admin_id']]);
$current_admin = $current_admin->fetch();
$admin_role = $current_admin['role'] ?? 'team';

// Stats
$total_customers         = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$total_shops             = $pdo->query("SELECT COUNT(*) FROM shop_owners")->fetchColumn();
$total_delivery_partners = $pdo->query("SELECT COUNT(*) FROM delivery_partners")->fetchColumn();
$total_udhar             = $pdo->query("SELECT COUNT(*) FROM udhar_entries")->fetchColumn();
$total_open              = $pdo->query("SELECT COUNT(*) FROM udhar_entries WHERE status='open'")->fetchColumn();

$platform_pay_sum = $pdo->query("
    SELECT SUM(amount) FROM (
        SELECT amount_paid as amount FROM bond_payments WHERE razorpay_payment_id IS NOT NULL AND is_settled_manually = 0
        UNION ALL SELECT paid_amount FROM monthly_khata WHERE razorpay_payment_id IS NOT NULL AND is_settled_manually = 0
        UNION ALL SELECT amount FROM payment_requests WHERE razorpay_payment_id IS NOT NULL AND is_settled_manually = 0
    ) as t
")->fetchColumn() ?: 0;

$visitors_data = $pdo->query("
    SELECT visit_date, COUNT(*) as count 
    FROM visitors 
    WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY visit_date ORDER BY visit_date ASC
")->fetchAll();

$chart_labels = []; $chart_data = [];
for($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-$i days"));
    $label = date('d M',   strtotime("-$i days"));
    $chart_labels[] = $label;
    $found = false;
    foreach($visitors_data as $v) {
        if($v['visit_date'] == $date) { $chart_data[] = (int)$v['count']; $found = true; break; }
    }
    if(!$found) $chart_data[] = 0;
}

$recent_shops     = $pdo->query("SELECT * FROM shop_owners ORDER BY created_at DESC LIMIT 5")->fetchAll();
$recent_customers = $pdo->query("SELECT * FROM customers ORDER BY created_at DESC LIMIT 5")->fetchAll();
$today_visitors   = $pdo->query("SELECT COUNT(*) FROM visitors WHERE visit_date = CURDATE()")->fetchColumn();
$total_visitors   = $pdo->query("SELECT COUNT(*) FROM visitors")->fetchColumn();

$geo_dist   = $pdo->query("SELECT district_name, COUNT(*) as count FROM geo_registry GROUP BY district_name ORDER BY count DESC")->fetchAll();
$geo_labels = array_column($geo_dist, 'district_name');
$geo_counts = array_column($geo_dist, 'count');
$geo_colors = ['#2563eb','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#475569'];

$stmt_pending_dp = $pdo->prepare("SELECT COUNT(*) FROM delivery_partners WHERE is_verified = 0");
$stmt_pending_dp->execute();
$pending_dp_count = $stmt_pending_dp->fetchColumn();

$current_page = basename($_SERVER['PHP_SELF']);
$stuck_payment_pages = ['stuck_payments.php','reconcile_ledger.php','reconcile_bonds.php','reconcile_monthly.php'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — KhataLink Admin</title>
<link rel="icon" type="image/png" href="../assets/favicon.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:#f1f5f9;min-height:100vh;}

/* ── NAVBAR ── */
.kl-navbar{
    background:#fff;border-bottom:1px solid #e2e8f0;
    padding:0 32px;height:64px;display:flex;align-items:center;
    justify-content:space-between;position:sticky;top:0;z-index:100;
    box-shadow:0 1px 3px rgba(0,0,0,.06);
}
.kl-logo{font-size:22px;font-weight:800;color:#0f172a;text-decoration:none;letter-spacing:-.5px;}
.kl-logo span{color:#2563eb;}
.nav-right{display:flex;align-items:center;gap:16px;}
.admin-badge{
    display:flex;align-items:center;gap:10px;
    background:#f8fafc;border:1px solid #e2e8f0;
    border-radius:50px;padding:6px 14px 6px 6px;
}
.admin-avatar{
    width:32px;height:32px;border-radius:50%;
    background:linear-gradient(135deg,#2563eb,#7c3aed);
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:13px;font-weight:700;
}
.admin-name{font-size:13px;font-weight:600;color:#374151;}
.admin-name span{color:#2563eb;}
.btn-logout{
    display:flex;align-items:center;gap:6px;
    background:#fef2f2;border:1px solid #fecaca;color:#dc2626;
    font-size:13px;font-weight:600;padding:8px 16px;border-radius:10px;
    text-decoration:none;transition:all .2s;cursor:pointer;font-family:'Inter',sans-serif;
}
.btn-logout:hover{background:#dc2626;color:#fff;border-color:#dc2626;}
.mobile-menu-btn{
    display:none;background:none;border:none;font-size:22px;
    color:#0f172a;cursor:pointer;padding:8px;border-radius:8px;
}
.mobile-menu-btn:hover{background:#f1f5f9;}

/* ── LAYOUT ── */
.layout{display:flex;min-height:calc(100vh - 64px);}

/* ── SIDEBAR ── */
.sidebar{
    width:260px;background:#fff;border-right:1px solid #e2e8f0;
    padding:20px 12px;flex-shrink:0;position:sticky;
    top:64px;height:calc(100vh - 64px);overflow-y:auto;
    scrollbar-width:thin;scrollbar-color:#e2e8f0 transparent;
}
.sidebar::-webkit-scrollbar{width:4px;}
.sidebar::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:4px;}
.sidebar-section-label{
    font-size:10px;font-weight:700;color:#94a3b8;
    letter-spacing:1.5px;text-transform:uppercase;
    padding:0 10px;margin:20px 0 6px;
}
.sidebar-section-label:first-child{margin-top:0;}
.nav-link{
    display:flex;align-items:center;gap:10px;padding:10px 12px;
    border-radius:10px;color:#64748b;font-size:13.5px;font-weight:500;
    text-decoration:none;transition:all .15s;margin-bottom:1px;position:relative;
}
.nav-link .nav-icon{
    width:32px;height:32px;border-radius:8px;display:flex;
    align-items:center;justify-content:center;font-size:14px;
    flex-shrink:0;transition:all .15s;
    background:#f8fafc;color:#94a3b8;
}
.nav-link:hover{background:#f8fafc;color:#1e40af;}
.nav-link:hover .nav-icon{background:#eff6ff;color:#2563eb;}
.nav-link.active{background:#eff6ff;color:#1d4ed8;font-weight:600;}
.nav-link.active .nav-icon{background:#dbeafe;color:#2563eb;}
.nav-link.danger:hover{background:#fef2f2;color:#dc2626;}
.nav-link.danger:hover .nav-icon{background:#fee2e2;color:#dc2626;}
.nav-badge{
    margin-left:auto;background:#ef4444;color:#fff;
    font-size:10px;font-weight:700;padding:2px 7px;
    border-radius:20px;
}
.nav-divider{height:1px;background:#f1f5f9;margin:8px 0;}

/* ── MAIN ── */
.main{flex:1;padding:28px 32px;overflow-x:hidden;}

/* ── PAGE HEADER ── */
.page-header{margin-bottom:24px;}
.page-title{font-size:26px;font-weight:800;color:#0f172a;letter-spacing:-.5px;margin-bottom:4px;}
.page-subtitle{font-size:14px;color:#64748b;}

/* ── ALERT ── */
.settlement-alert{
    background:#fffbeb;border:1px solid #fde68a;border-radius:16px;
    padding:18px 22px;margin-bottom:24px;
    display:flex;align-items:center;justify-content:space-between;gap:16px;
    flex-wrap:wrap;
}
.settlement-alert-icon{
    width:46px;height:46px;background:#f59e0b;border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:18px;flex-shrink:0;
}
.settlement-alert h5{font-size:15px;font-weight:700;color:#92400e;margin-bottom:3px;}
.settlement-alert p{font-size:13px;color:#b45309;margin:0;}
.alert-btns{display:flex;gap:8px;flex-wrap:wrap;}
.alert-btns a{
    font-size:12px;font-weight:600;padding:8px 16px;border-radius:8px;
    text-decoration:none;transition:all .2s;white-space:nowrap;
}
.btn-alert-primary{background:#2563eb;color:#fff;}
.btn-alert-primary:hover{background:#1d4ed8;color:#fff;}
.btn-alert-dark{background:#0f172a;color:#fff;}
.btn-alert-dark:hover{background:#1e293b;color:#fff;}
.btn-alert-outline{background:#fff;color:#64748b;border:1px solid #e2e8f0;}
.btn-alert-outline:hover{background:#f8fafc;color:#374151;}

/* ── QUICK ACTIONS ── */
.quick-actions{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px;}
.qa-card{
    background:#fff;border:1px solid #e2e8f0;border-radius:14px;
    padding:16px 20px;display:flex;align-items:center;
    justify-content:space-between;gap:12px;transition:all .2s;
}
.qa-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.07);transform:translateY(-1px);}
.qa-info{display:flex;align-items:center;gap:12px;}
.qa-icon-wrap{
    width:40px;height:40px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;
}
.qa-title{font-size:14px;font-weight:600;color:#0f172a;}
.qa-sub{font-size:12px;color:#94a3b8;margin-top:2px;}
.qa-btn{
    display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;
    padding:8px 14px;border-radius:8px;text-decoration:none;
    white-space:nowrap;transition:all .2s;
}

/* ── STAT GRID ── */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.stat-card{
    background:#fff;border:1px solid #e2e8f0;border-radius:16px;
    padding:20px;transition:all .2s;
}
.stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.07);transform:translateY(-2px);}
.stat-card a{text-decoration:none;}
.stat-icon-wrap{
    width:44px;height:44px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:18px;margin-bottom:14px;
}
.stat-label{font-size:12px;font-weight:600;color:#94a3b8;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;}
.stat-value{font-size:30px;font-weight:800;color:#0f172a;letter-spacing:-1px;line-height:1;}

/* ── ACTION CARDS (dashed) ── */
.action-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.action-card{
    background:#fff;border:1.5px dashed #cbd5e1;border-radius:16px;
    padding:20px;text-decoration:none;display:flex;flex-direction:column;
    align-items:center;gap:10px;transition:all .2s;text-align:center;
}
.action-card:hover{border-style:solid;transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.07);}
.action-card .ac-icon{
    width:48px;height:48px;border-radius:14px;
    display:flex;align-items:center;justify-content:center;font-size:20px;
    transition:all .2s;
}
.action-card .ac-label{font-size:13px;font-weight:700;color:#334155;}
.action-card .ac-sub{font-size:11px;color:#94a3b8;margin-top:2px;}
/* Dark geo card */
.action-card.dark{background:#0f172a;border-color:#0f172a;}
.action-card.dark .ac-label{color:#f1f5f9;}
.action-card.dark .ac-sub{color:#64748b;}
.action-card.dark:hover{background:#1e293b;border-color:#1e293b;}

/* ── KL CARD ── */
.kl-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;margin-bottom:20px;}
.card-head{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;
}
.card-title{font-size:15px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;}
.card-link{font-size:12px;font-weight:600;color:#2563eb;text-decoration:none;
    padding:6px 12px;background:#eff6ff;border-radius:8px;transition:all .2s;}
.card-link:hover{background:#dbeafe;color:#1d4ed8;}

/* ── TABLE ── */
.kl-table{width:100%;border-collapse:collapse;font-size:13.5px;}
.kl-table thead th{
    background:#f8fafc;color:#64748b;font-size:11px;font-weight:700;
    text-transform:uppercase;letter-spacing:.5px;padding:11px 16px;
    border-bottom:1px solid #e2e8f0;white-space:nowrap;
}
.kl-table tbody td{
    padding:13px 16px;border-bottom:1px solid #f1f5f9;
    color:#374151;vertical-align:middle;
}
.kl-table tbody tr:last-child td{border-bottom:none;}
.kl-table tbody tr:hover td{background:#fafbfc;}

/* ── BADGES ── */
.badge-blue{background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;}
.badge-green{background:#ecfdf5;color:#047857;font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;}
.badge-gray{background:#f1f5f9;color:#64748b;font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;}

/* ── CHART ── */
.chart-wrap{position:relative;height:260px;}

/* ── RECENT SHOPS ── */
.shop-item{
    display:flex;align-items:center;gap:12px;
    padding:10px 0;border-bottom:1px solid #f1f5f9;
}
.shop-item:last-child{border-bottom:none;}
.shop-avatar{
    width:38px;height:38px;background:#eff6ff;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    color:#2563eb;font-size:15px;flex-shrink:0;
}
.shop-name{font-size:13.5px;font-weight:600;color:#0f172a;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.shop-cat{font-size:12px;color:#64748b;}

/* ── FOOTER ── */
.kl-footer{
    text-align:center;padding:18px;font-size:12px;color:#94a3b8;
    border-top:1px solid #e2e8f0;background:#fff;
}

/* ── RESPONSIVE ── */
@media(max-width:1200px){
    .stat-grid{grid-template-columns:repeat(3,1fr);}
    .action-grid{grid-template-columns:repeat(4,1fr);}
}
@media(max-width:992px){
    .sidebar{
        position:fixed;top:0;left:0;height:100vh;z-index:999;
        transform:translateX(-100%);transition:transform .3s ease;
        box-shadow:0 0 30px rgba(0,0,0,.15);
    }
    .sidebar.open{transform:translateX(0);}
    .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:998;}
    .sidebar-overlay.show{display:block;}
    .mobile-menu-btn{display:flex;}
    .main{padding:20px 16px;}
    .kl-navbar{padding:0 16px;}
    .stat-grid{grid-template-columns:repeat(2,1fr);}
    .action-grid{grid-template-columns:repeat(2,1fr);}
    .quick-actions{grid-template-columns:1fr;}
}
@media(max-width:576px){
    .stat-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
    .action-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
    .stat-value{font-size:24px;}
    .page-title{font-size:22px;}
    .kl-card{padding:16px;}
    .settlement-alert{flex-direction:column;}
}
</style>
</head>
<body>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="kl-navbar">
    <div style="display:flex;align-items:center;gap:16px;">
        <button class="mobile-menu-btn" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="../index.php" class="kl-logo">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" style="height:46px;">
        </a>
    </div>
    <div class="nav-right">
        <div class="admin-badge">
            <div class="admin-avatar"><?= strtoupper(substr($_SESSION['admin_name'], 0, 1)) ?></div>
            <div class="admin-name">Welcome, <span><?= htmlspecialchars($_SESSION['admin_name']) ?></span></div>
        </div>
        <a href="logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</nav>

<!-- Layout -->
<div class="layout">

    <!-- ═══ SIDEBAR ═══ -->
    <aside class="sidebar" id="sidebar">

        <div class="sidebar-section-label">Main</div>
        <a href="dashboard.php" class="nav-link <?= ($current_page=='dashboard.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
            Dashboard
        </a>

        <div class="sidebar-section-label">Manage</div>
        <a href="shops.php" class="nav-link <?= ($current_page=='shops.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-store"></i></span>
            All Shops
        </a>
        <a href="customers.php" class="nav-link <?= ($current_page=='customers.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-users"></i></span>
            All Customers
        </a>
        <a href="delivery_partners.php" class="nav-link <?= ($current_page=='delivery_partners.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-motorcycle"></i></span>
            Delivery Partners
        </a>
        <?php if($pending_dp_count > 0): ?>
        <a href="verify_delivery_partners.php" class="nav-link <?= ($current_page=='verify_delivery_partners.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-user-check"></i></span>
            Pending Verifications
            <span class="nav-badge"><?= $pending_dp_count ?></span>
        </a>
        <?php endif; ?>

        <div class="sidebar-section-label">Finance</div>
        <a href="settlements.php" class="nav-link <?= ($current_page=='settlements.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-hand-holding-dollar"></i></span>
            Daily Settlements
        </a>
        <a href="settlement_management.php" class="nav-link <?= ($current_page=='settlement_management.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
            Settlement Calendar
        </a>
        <a href="settlement_history.php" class="nav-link <?= ($current_page=='settlement_history.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-history"></i></span>
            Settlement History
        </a>
        <a href="reconcile_ledger.php" class="nav-link <?= ($current_page=='reconcile_ledger.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
            Reconcile Ledger
        </a>
        <a href="stuck_payments.php" class="nav-link <?= (in_array($current_page,$stuck_payment_pages))?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span>
            Stuck Payments
        </a>

        <div class="sidebar-section-label">Reports & Analytics</div>
        <a href="visitors.php" class="nav-link <?= ($current_page=='visitors.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
            Visitor Analytics
        </a>
        <a href="queries.php" class="nav-link <?= ($current_page=='queries.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-headset"></i></span>
            Support Queries
        </a>
        <a href="security_audit.php" class="nav-link <?= ($current_page=='security_audit.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-user-shield"></i></span>
            Security Audit
        </a>

        <div class="sidebar-section-label">Platform</div>
        <a href="mall_control.php" class="nav-link <?= ($current_page=='mall_control.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-tower-broadcast"></i></span>
            Mall Control
        </a>
        <a href="manage_banners.php" class="nav-link <?= ($current_page=='manage_banners.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-image"></i></span>
            Manage Banners
        </a>
        <a href="manage_coupons.php" class="nav-link <?= ($current_page=='manage_coupons.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-ticket-alt"></i></span>
            Manage Coupons
        </a>
        <a href="manage_categories.php" class="nav-link <?= ($current_page=='manage_categories.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-th-large"></i></span>
            Mall Categories
        </a>
        <a href="broadcast_notifications.php" class="nav-link <?= ($current_page=='broadcast_notifications.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-bullhorn"></i></span>
            Broadcast Alerts
        </a>
        <a href="geo_registry_manager.php" class="nav-link <?= ($current_page=='geo_registry_manager.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-map-location-dot"></i></span>
            Geo Registry
        </a>

        <?php if($admin_role == 'founder'): ?>
        <div class="sidebar-section-label">Team</div>
        <a href="team.php" class="nav-link <?= ($current_page=='team.php')?'active':'' ?>">
            <span class="nav-icon"><i class="fas fa-user-tie"></i></span>
            Manage Team
        </a>
        <?php endif; ?>

        <div class="nav-divider"></div>
        <a href="logout.php" class="nav-link danger">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            Logout
        </a>
    </aside>

    <!-- ═══ MAIN CONTENT ═══ -->
    <main class="main">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title"> Dashboard</div>
            <div class="page-subtitle">Welcome back — here's what's happening on KhataLink</div>
        </div>

        <!-- Settlement Alert -->
        <?php if($platform_pay_sum > 0): ?>
        <div class="settlement-alert">
            <div style="display:flex;align-items:center;gap:14px;">
                <div class="settlement-alert-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div>
                    <h5>Platform Settlement Pending</h5>
                    <p>You have <strong>₹<?= number_format($platform_pay_sum, 2) ?></strong> collected — needs to be manually settled to shops.</p>
                </div>
            </div>
            <div class="alert-btns">
                <a href="settlement_management.php" class="alert-btns-a btn-alert-primary">
                    <i class="fas fa-calendar-check me-1"></i> Settlement Calendar
                </a>
                <a href="reconcile_ledger.php" class="alert-btns-a btn-alert-dark">
                    <i class="fas fa-balance-scale me-1"></i> Process Now
                </a>
                <a href="settlement_history.php" class="alert-btns-a btn-alert-outline">
                    <i class="fas fa-history me-1"></i> History
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <div class="qa-card">
                <div class="qa-info">
                    <div class="qa-icon-wrap" style="background:#eff6ff;color:#2563eb;">
                        <i class="fas fa-store"></i>
                    </div>
                    <div>
                        <div class="qa-title">Shop Management</div>
                        <div class="qa-sub">Register & manage shops</div>
                    </div>
                </div>
                <a href="shops.php" class="qa-btn" style="background:#2563eb;color:#fff;">
                    <i class="fas fa-plus"></i> Add / Manage
                </a>
            </div>
            <div class="qa-card">
                <div class="qa-info">
                    <div class="qa-icon-wrap" style="background:#ecfdf5;color:#059669;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="qa-title">Customer Management</div>
                        <div class="qa-sub">View & manage customers</div>
                    </div>
                </div>
                <a href="customers.php" class="qa-btn" style="background:#059669;color:#fff;">
                    <i class="fas fa-plus"></i> Add / Manage
                </a>
            </div>
        </div>

        <!-- ── Primary Stats ── -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon-wrap" style="background:#eff6ff;color:#2563eb;">
                    <i class="fas fa-store"></i>
                </div>
                <div class="stat-label">Total Shops</div>
                <div class="stat-value"><?= number_format($total_shops) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap" style="background:#ecfdf5;color:#059669;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-label">Total Customers</div>
                <div class="stat-value"><?= number_format($total_customers) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap" style="background:#fffbeb;color:#d97706;">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="stat-label">Total Udhar</div>
                <div class="stat-value"><?= number_format($total_udhar) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap" style="background:#fef2f2;color:#dc2626;">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-label">Open Entries</div>
                <div class="stat-value"><?= number_format($total_open) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap" style="background:#e0e7ff;color:#4f46e5;">
                    <i class="fas fa-motorcycle"></i>
                </div>
                <div class="stat-label">Delivery Partners</div>
                <div class="stat-value"><?= number_format($total_delivery_partners) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap" style="background:#f5f3ff;color:#7c3aed;">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-label">Today's Visitors</div>
                <div class="stat-value"><?= number_format($today_visitors) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap" style="background:#fdf4ff;color:#a21caf;">
                    <i class="fas fa-globe"></i>
                </div>
                <div class="stat-label">Total Visitors</div>
                <div class="stat-value"><?= number_format($total_visitors) ?></div>
            </div>
            <?php if($platform_pay_sum > 0): ?>
            <div class="stat-card" style="border-color:#fde68a;background:#fffbeb;">
                <div class="stat-icon-wrap" style="background:#fef9c3;color:#d97706;">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stat-label" style="color:#92400e;">Pending Settlement</div>
                <div class="stat-value" style="font-size:22px;color:#b45309;">₹<?= number_format($platform_pay_sum, 0) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Action Shortcuts (all sidebar buttons as quick-launch cards) ── -->
        <div style="font-size:11px;font-weight:700;color:#94a3b8;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:12px;">
            Quick Launch
        </div>
        <div class="action-grid">

            <a href="health_check.php" class="action-card" style="border-color:#10b981;">
                <div class="ac-icon" style="background:#ecfdf5;color:#10b981;">
                    <i class="fas fa-heart-pulse"></i>
                </div>
                <div class="ac-label">Health Check</div>
                <div class="ac-sub">System & DB Status</div>
            </a>

            <a href="error_monitoring.php" class="action-card" style="border-color:#ef4444;">
                <div class="ac-icon" style="background:#fef2f2;color:#ef4444;">
                    <i class="fas fa-bug"></i>
                </div>
                <div class="ac-label">Error Monitor</div>
                <div class="ac-sub">Crash Logs & Spikes</div>
            </a>

            <a href="db_explorer.php" class="action-card light">
                <div class="ac-icon" style="background:rgb(243, 243, 243);color:#38bdf8;">
                    <i class="fas fa-terminal"></i>
                </div>
                <div class="ac-label">DB Master Engine</div>
                <div class="ac-sub">Root Table Access</div>
            </a>

            <a href="broadcast_notifications.php" class="action-card" style="border-color:#bfdbfe;">
                <div class="ac-icon" style="background:#eff6ff;color:#2563eb;">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <div class="ac-label">Broadcast Alerts</div>
                <div class="ac-sub">Send to all users</div>
            </a>

            <a href="manage_banners.php" class="action-card" style="border-color:#c7d2fe;">
                <div class="ac-icon" style="background:#eef2ff;color:#4f46e5;">
                    <i class="fas fa-images"></i>
                </div>
                <div class="ac-label">Manage Banners</div>
                <div class="ac-sub">App home banners</div>
            </a>

            <a href="manage_categories.php" class="action-card" style="border-color:#a7f3d0;">
                <div class="ac-icon" style="background:#ecfdf5;color:#059669;">
                    <i class="fas fa-th-large"></i>
                </div>
                <div class="ac-label">Mall Categories</div>
                <div class="ac-sub">Shop categories</div>
            </a>

            <a href="manage_coupons.php" class="action-card" style="border-color:#fde68a;">
                <div class="ac-icon" style="background:#fffbeb;color:#d97706;">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <div class="ac-label">Manage Coupons</div>
                <div class="ac-sub">Promo & discounts</div>
            </a>

            <a href="settlements.php" class="action-card" style="border-color:#bbf7d0;">
                <div class="ac-icon" style="background:#f0fdf4;color:#16a34a;">
                    <i class="fas fa-hand-holding-dollar"></i>
                </div>
                <div class="ac-label">Daily Settlements</div>
                <div class="ac-sub">Today's payouts</div>
            </a>

            <a href="stuck_payments.php" class="action-card" style="border-color:#fecaca;">
                <div class="ac-icon" style="background:#fef2f2;color:#dc2626;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="ac-label">Stuck Payments</div>
                <div class="ac-sub">Failed transactions</div>
            </a>

            <a href="mall_control.php" class="action-card" style="border-color:#e9d5ff;">
                <div class="ac-icon" style="background:#faf5ff;color:#7c3aed;">
                    <i class="fas fa-tower-broadcast"></i>
                </div>
                <div class="ac-label">Mall Control</div>
                <div class="ac-sub">Platform controls</div>
            </a>

            <a href="security_audit.php" class="action-card" style="border-color:#cbd5e1;">
                <div class="ac-icon" style="background:#f8fafc;color:#475569;">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="ac-label">Security Audit</div>
                <div class="ac-sub">Login & activity</div>
            </a>

            <a href="delivery_partners.php" class="action-card" style="border-color:#c7d2fe;">
                <div class="ac-icon" style="background:#eef2ff;color:#4f46e5;">
                    <i class="fas fa-motorcycle"></i>
                </div>
                <div class="ac-label">Delivery Partners</div>
                <div class="ac-sub">Manage riders</div>
            </a>

            <a href="queries.php" class="action-card" style="border-color:#fde68a;">
                <div class="ac-icon" style="background:#fffbeb;color:#b45309;">
                    <i class="fas fa-headset"></i>
                </div>
                <div class="ac-label">Support Queries</div>
                <div class="ac-sub">User help tickets</div>
            </a>

            <a href="visitors.php" class="action-card" style="border-color:#ddd6fe;">
                <div class="ac-icon" style="background:#f5f3ff;color:#7c3aed;">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="ac-label">Visitor Analytics</div>
                <div class="ac-sub">Traffic insights</div>
            </a>

            <a href="geo_registry_manager.php" class="action-card light">
                <div class="ac-icon" style="background:rgb(255, 255, 255);color:#94a3b8;">
                    <i class="fas fa-map-location-dot"></i>
                </div>
                <div class="ac-label">Geo Registry</div>
                <div class="ac-sub">District coverage</div>
            </a>

            <a href="reconcile_mall.php" class="action-card" style="border-color:#10b981;">
                <div class="ac-icon" style="background:#ecfdf5;color:#10b981;">
                    <i class="fas fa-shopping-basket"></i>
                </div>
                <div class="ac-label">Mall Settlements</div>
                <div class="ac-sub">Marketplace Payouts</div>
            </a>

            <a href="mall_shops.php" class="action-card" style="border-color:#3b82f6;">
                <div class="ac-icon" style="background:#eff6ff;color:#3b82f6;">
                    <i class="fas fa-store-slash"></i>
                </div>
                <div class="ac-label">Mall Shops Manager</div>
                <div class="ac-sub">Control Merchant Access</div>
            </a>

            <a href="hacker_terminal.php" class="action-card" style="border-color:#bc13fe; background: #000;">
                <div class="ac-icon" style="background:rgba(188, 19, 254, 0.1);color:#bc13fe;">
                    <i class="fas fa-user-secret"></i>
                </div>
                <div class="ac-label" style="color: #fff;">Dangerous Access</div>
                <div class="ac-sub">Live Cyber Trace</div>
            </a>

        </div>

        <!-- Pending Verifications Banner (if any) -->
        <?php if($pending_dp_count > 0): ?>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:42px;height:42px;background:#fca5a5;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;">
                    <i class="fas fa-user-check"></i>
                </div>
                <div>
                    <div style="font-size:14px;font-weight:700;color:#991b1b;"><?= $pending_dp_count ?> Delivery Partner<?= $pending_dp_count>1?'s':'' ?> Awaiting Verification</div>
                    <div style="font-size:12px;color:#dc2626;">Review and verify new delivery partner applications</div>
                </div>
            </div>
            <a href="verify_delivery_partners.php" style="background:#dc2626;color:#fff;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;">
                Verify Now <i class="fas fa-arrow-right ms-1"></i>
            </a>
        </div>
        <?php endif; ?>

        <!-- Charts Row -->
        <div class="row g-4">

            <div class="col-lg-8">
                <div class="kl-card">
                    <div class="card-head">
                        <div class="card-title">
                            <i class="fas fa-chart-area" style="color:#2563eb;"></i>
                            Visitor Traffic — Last 7 Days
                        </div>
                        <a href="visitors.php" class="card-link">Full Report</a>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="visitorChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="kl-card" style="height:100%;">
                    <div class="card-head">
                        <div class="card-title">
                            <i class="fas fa-chart-pie" style="color:#10b981;"></i>
                            Geo Distribution
                        </div>
                        <a href="geo_registry_manager.php" class="card-link">Edit</a>
                    </div>
                    <div class="chart-wrap" style="height:220px;">
                        <canvas id="geoPieChart"></canvas>
                    </div>
                    <p style="text-align:center;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;margin-top:12px;">
                        District-wise Coverage
                    </p>
                </div>
            </div>

            <!-- Recent Shops -->
            <div class="col-lg-4">
                <div class="kl-card" style="height:100%;">
                    <div class="card-head">
                        <div class="card-title">
                            <i class="fas fa-store" style="color:#2563eb;"></i>
                            Recent Shops
                        </div>
                        <a href="shops.php" class="card-link">View All</a>
                    </div>
                    <?php if(count($recent_shops) > 0): ?>
                        <?php foreach($recent_shops as $shop): ?>
                        <div class="shop-item">
                            <div class="shop-avatar"><i class="fas fa-store"></i></div>
                            <div style="flex:1;min-width:0;">
                                <div class="shop-name"><?= htmlspecialchars($shop['shop_name']) ?></div>
                                <div class="shop-cat"><?= htmlspecialchars($shop['shop_category']) ?></div>
                            </div>
                            <span class="badge-gray"><?= date('d M', strtotime($shop['created_at'])) ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center;padding:30px;color:#94a3b8;font-size:14px;">
                            <i class="fas fa-store" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>
                            No shops registered yet
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Recent Customers Table -->
        <div class="kl-card" style="margin-top:24px;">
            <div class="card-head">
                <div class="card-title">
                    <i class="fas fa-users" style="color:#059669;"></i>
                    Recent Customers
                </div>
                <a href="customers.php" class="card-link">View All</a>
            </div>
            <div style="overflow-x:auto;">
                <table class="kl-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Unique ID</th>
                            <th>Email</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($recent_customers) > 0): ?>
                            <?php foreach($recent_customers as $i => $c): ?>
                            <tr>
                                <td style="color:#94a3b8;font-weight:600;"><?= $i+1 ?></td>
                                <td style="font-weight:600;color:#0f172a;">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div style="width:32px;height:32px;border-radius:50%;background:#ecfdf5;display:flex;align-items:center;justify-content:center;color:#059669;font-size:12px;font-weight:700;flex-shrink:0;">
                                            <?= strtoupper(substr($c['name'],0,1)) ?>
                                        </div>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </div>
                                </td>
                                <td><span class="badge-blue"><?= htmlspecialchars($c['unique_id']) ?></span></td>
                                <td style="color:#64748b;"><?= htmlspecialchars($c['email']) ?></td>
                                <td><span class="badge-gray"><?= date('d M Y', strtotime($c['created_at'])) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">
                                    No customers registered yet
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<div class="kl-footer">
    © 2025 KhataLink — Admin Panel &nbsp;·&nbsp; Built with ❤️
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar toggle
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.add('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('show');
}

// Visitor Line Chart
const ctx = document.getElementById('visitorChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [{
            label: 'Visitors',
            data: <?= json_encode($chart_data) ?>,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,0.07)',
            borderWidth: 2.5,
            pointBackgroundColor: '#2563eb',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2.5,
            pointRadius: 6,
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#0f172a',
                titleColor: '#fff',
                bodyColor: '#94a3b8',
                padding: 12,
                cornerRadius: 10
            }
        },
        scales: {
            x: { grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', font: { size: 12 } } },
            y: {
                grid: { color: '#f1f5f9' },
                ticks: { color: '#94a3b8', font: { size: 12 }, stepSize: 1 },
                beginAtZero: true
            }
        }
    }
});

// Geo Pie Chart
const pieCtx = document.getElementById('geoPieChart').getContext('2d');
new Chart(pieCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($geo_labels) ?>,
        datasets: [{
            data: <?= json_encode($geo_counts) ?>,
            backgroundColor: <?= json_encode($geo_colors) ?>,
            borderWidth: 2,
            borderColor: '#fff',
            hoverOffset: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    pointStyle: 'circle',
                    font: { size: 10, weight: '600' },
                    padding: 12,
                    color: '#64748b'
                }
            }
        }
    }
});
</script>
</body>
</html>