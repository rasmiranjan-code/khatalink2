<?php
// This file assumes session_start() and require_once '../includes/db.php'; have already been called in the parent page.

$current_page = basename($_SERVER['PHP_SELF']);

// Get current admin data for role-based access
// This query should only run once per request, so it's fine here.
$stmt_current_admin_sidebar = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
$stmt_current_admin_sidebar->execute([$_SESSION['admin_id']]);
$current_admin_sidebar = $stmt_current_admin_sidebar->fetch();
$admin_role = $current_admin_sidebar['role'] ?? 'team'; // Default to team if not found

// Fetch count of unverified delivery partners for badge
$stmt_pending_dp = $pdo->prepare("SELECT COUNT(*) FROM delivery_partners WHERE is_verified = 0");
$stmt_pending_dp->execute();
$pending_dp_count = $stmt_pending_dp->fetchColumn();

// Define an array of pages that should activate the 'Stuck Payments' link
$stuck_payments_pages = ['stuck_payments.php', 'reconcile_ledger.php', 'reconcile_bonds.php', 'reconcile_monthly.php'];
?>
<style>
    /* ---- SIDEBAR ---- */
    .sidebar {
        width: 250px;
        background: #ffffff;
        border-right: 1px solid #e2e8f0;
        padding: 24px 16px;
        flex-shrink: 0;
        position: sticky;
        top: 64px;
        height: calc(100vh - 64px);
        overflow-y: auto;
    }

    .sidebar-label {
        font-size: 10px;
        font-weight: 700;
        color: #94a3b8;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        padding: 0 12px;
        margin-bottom: 8px;
        margin-top: 20px;
    }

    .sidebar-label:first-child { margin-top: 0; }

    .nav-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 11px 12px;
        border-radius: 10px;
        color: #64748b;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s;
        margin-bottom: 2px;
    }

    .nav-link i {
        width: 18px;
        font-size: 15px;
        text-align: center;
    }

    .nav-link:hover {
        background: #eff6ff;
        color: #2563eb;
    }

    .nav-link.active {
        background: #eff6ff;
        color: #2563eb;
        font-weight: 600;
    }

    /* Mobile compatibility */
    @media (max-width: 992px) {
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            z-index: 999;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            box-shadow: 0 0 30px rgba(0,0,0,0.15);
        }
        .sidebar.open { transform: translateX(0); }
        .sidebar-overlay.show { display: block !important; }
    }
</style>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-label">Main</div>
    <a href="dashboard.php" class="nav-link <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
        <i class="fas fa-chart-pie"></i> Dashboard
    </a>

    <div class="sidebar-label">Manage</div>
    <a href="shops.php" class="nav-link <?= ($current_page == 'shops.php') ? 'active' : '' ?>">
        <i class="fas fa-store"></i> All Shops
    </a>
    <a href="customers.php" class="nav-link <?= ($current_page == 'customers.php') ? 'active' : '' ?>">
        <i class="fas fa-users"></i> All Customers
    </a>
    <a href="delivery_partners.php" class="nav-link <?= ($current_page == 'delivery_partners.php') ? 'active' : '' ?>">
        <i class="fas fa-motorcycle"></i> Delivery Partners
    </a>

    <div class="sidebar-label">Reports</div>
    <a href="visitors.php" class="nav-link <?= ($current_page == 'visitors.php') ? 'active' : '' ?>">
        <i class="fas fa-chart-line"></i> Visitor Analytics
    </a>
    <a href="settlements.php" class="nav-link <?= ($current_page == 'settlements.php') ? 'active' : '' ?>">
        <i class="fas fa-hand-holding-dollar"></i> Daily Settlements
    </a>
    <a href="stuck_payments.php" class="nav-link <?= (in_array($current_page, $stuck_payments_pages)) ? 'active' : '' ?>">
        <i class="fas fa-exclamation-triangle"></i> Stuck Payments
    </a>
    <a href="queries.php" class="nav-link <?= ($current_page == 'queries.php') ? 'active' : '' ?>">
        <i class="fas fa-headset"></i> Support Queries
    </a>
    <a href="mall_control.php" class="nav-link <?= ($current_page == 'mall_control.php') ? 'active' : '' ?>">
        <i class="fas fa-tower-broadcast"></i> Mall Control
    </a>
    <a href="security_audit.php" class="nav-link <?= ($current_page == 'security_audit.php') ? 'active' : '' ?>">
        <i class="fas fa-user-shield"></i> Security Audit
    </a>
    <a href="manage_banners.php" class="nav-link <?= ($current_page == 'manage_banners.php') ? 'active' : '' ?>">
        <i class="fas fa-image"></i> Manage Banners
    </a>
    <a href="manage_coupons.php" class="nav-link <?= ($current_page == 'manage_coupons.php') ? 'active' : '' ?>">
        <i class="fas fa-ticket-alt"></i> Manage Coupons
    </a>
    <a href="geo_registry_manager.php" class="nav-link <?= ($current_page == 'geo_registry_manager.php') ? 'active' : '' ?>">
        <i class="fas fa-map-location-dot"></i> Geo Registry
    </a>
    <?php if ($pending_dp_count > 0): ?>
    <a href="verify_delivery_partners.php" class="nav-link <?= ($current_page == 'verify_delivery_partners.php') ? 'active' : '' ?>">
        <i class="fas fa-user-check"></i> Pending Verifications
        <span class="badge bg-danger text-white ms-auto"><?= $pending_dp_count ?></span>
    </a>
    <?php endif; ?>


    <?php if($admin_role == 'founder'): ?>
    <div class="sidebar-label">Team</div>
    <a href="team.php" class="nav-link <?= ($current_page == 'team.php') ? 'active' : '' ?>">
        <i class="fas fa-user-shield"></i> Manage Team
    </a>
    <?php endif; ?>

    <div class="sidebar-label">Account</div>
    <a href="logout.php" class="nav-link" style="color:#dc2626;">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>

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