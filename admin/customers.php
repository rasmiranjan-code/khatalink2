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

// Handle Customer Deletion
if(isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$del_id]);
    header("Location: customers.php?success=Customer deleted.");
    exit();
}

// Handle Customer Addition
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_customer') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    
    $unique_id = 'CUST-' . date('Y') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

    $check = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
    $check->execute([$email]);
    if($check->fetch()) {
        header("Location: customers.php?error=Email already exists.");
    } else {
        $stmt = $pdo->prepare("INSERT INTO customers (unique_id, name, email, phone, password) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$unique_id, $name, $email, $phone, $password]);
        header("Location: customers.php?success=Customer added! Unique ID: " . $unique_id);
    }
    exit();
}

// Search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$query = "SELECT c.*,
          COUNT(DISTINCT sc.shop_id) as total_shops,
          COUNT(DISTINCT ue.id) as total_entries,
          COALESCE(SUM(ue.total_remaining), 0) as total_due
          FROM customers c
          LEFT JOIN shop_customers sc ON c.id = sc.customer_id
          LEFT JOIN udhar_entries ue ON c.id = ue.customer_id AND ue.status = 'open'
          WHERE 1=1";

$params = [];

if($search) {
    $query .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.unique_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " GROUP BY c.id ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Stats
$total_customers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$total_due = $pdo->query("SELECT COALESCE(SUM(total_remaining),0) FROM udhar_entries WHERE status='open'")->fetchColumn();
$total_closed = $pdo->query("SELECT COUNT(*) FROM udhar_entries WHERE status='closed'")->fetchColumn();
$total_pages = ceil($total_customers / $limit);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Customers — KhataLink Admin</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
        }

        .kl-navbar {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }

        .kl-logo {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        .kl-logo span { color: #2563eb; }

        .btn-logout {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-logout:hover { background: #dc2626; color: #ffffff; }

        .layout { display: flex; min-height: calc(100vh - 64px); }

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

        .nav-link i { width: 18px; font-size: 15px; text-align: center; }
        .nav-link:hover { background: #eff6ff; color: #2563eb; }
        .nav-link.active { background: #eff6ff; color: #2563eb; font-weight: 600; }

        .main { flex: 1; padding: 32px; overflow-x: hidden; }

        .page-title {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin-bottom: 4px;
        }

        .page-subtitle {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 28px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px 24px;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            margin-bottom: 14px;
        }

        .stat-label { font-size: 13px; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 28px; font-weight: 800; color: #0f172a; letter-spacing: -1px; }

        .search-bar {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .search-group { flex: 1; min-width: 200px; }

        .kl-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .kl-input {
            width: 100%;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 11px 16px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.2s;
            outline: none;
        }

        .kl-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
        }

        .btn-search {
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 11px 22px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-search:hover { background: #1d4ed8; }

        .btn-reset {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 11px 18px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-reset:hover { background: #e2e8f0; color: #374151; }

        .kl-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid #f1f5f9;
        }

        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .results-count {
            font-size: 13px;
            color: #64748b;
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 6px;
            font-weight: 600;
        }

        .kl-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .kl-table thead th {
            background: #f8fafc;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 20px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .kl-table tbody td {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            color: #374151;
            vertical-align: middle;
        }

        .kl-table tbody tr:last-child td { border-bottom: none; }
        .kl-table tbody tr:hover td { background: #fafbfc; }

        .customer-avatar {
            width: 38px;
            height: 38px;
            background: #ecfdf5;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #059669;
            font-size: 16px;
            flex-shrink: 0;
        }

        .badge-uid {
            background: #eff6ff;
            color: #2563eb;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }

        .badge-count {
            background: #f1f5f9;
            color: #374151;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
        }

        .due-amount {
            font-weight: 700;
            color: #dc2626;
        }

        .no-due {
            font-weight: 600;
            color: #059669;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
            color: #cbd5e1;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 22px;
            color: #0f172a;
            cursor: pointer;
            padding: 8px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 998;
        }

        @media (max-width: 992px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
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
            .sidebar-overlay.show { display: block; }
            .mobile-menu-btn { display: flex; }
            .main { padding: 20px 16px; }
            .kl-navbar { padding: 0 20px; }
        }

        @media (max-width: 576px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .page-title { font-size: 22px; }
        }
    </style>
</head>
<body>

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
        </span>
        <a href="logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</nav>

<!-- Layout -->
<div class="layout">

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-label">Main</div>
        <a href="dashboard.php" class="nav-link">
            <i class="fas fa-chart-pie"></i> Dashboard
        </a>

        <div class="sidebar-label">Manage</div>
        <a href="shops.php" class="nav-link">
            <i class="fas fa-store"></i> All Shops
        </a>
        <a href="customers.php" class="nav-link active">
            <i class="fas fa-users"></i> All Customers
        </a>

        <div class="sidebar-label">Reports</div>
        <a href="visitors.php" class="nav-link">
            <i class="fas fa-chart-line"></i> Visitor Analytics
        </a>
        <a href="settlements.php" class="nav-link">
            <i class="fas fa-hand-holding-dollar"></i> Daily Settlements
        </a>
        <a href="stuck_payments.php" class="nav-link">
            <i class="fas fa-exclamation-triangle"></i> Stuck Payments
        </a>

        <?php if($current_admin['role'] == 'founder'): ?>
        <div class="sidebar-label">Team</div>
        <a href="team.php" class="nav-link">
            <i class="fas fa-user-shield"></i> Manage Team
        </a>
        <?php endif; ?>

        <div class="sidebar-label">Account</div>
        <a href="logout.php" class="nav-link" style="color:#dc2626;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <!-- Main -->
    <div class="main">

        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <div class="page-title">All Customers</div>
                <div class="page-subtitle">View and manage all registered customers on KhataLink</div>
            </div>
            <button class="btn btn-success fw-bold px-4" data-bs-toggle="modal" data-bs-target="#addCustModal">
                <i class="fas fa-user-plus me-2"></i> Add New Customer
            </button>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4"><?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($_GET['error']) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#ecfdf5; color:#059669;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-label">Total Customers</div>
                <div class="stat-value"><?= $total_customers ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef2f2; color:#dc2626;">
                    <i class="fas fa-rupee-sign"></i>
                </div>
                <div class="stat-label">Total Due Amount</div>
                <div class="stat-value">₹<?= number_format($total_due, 0) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#eff6ff; color:#2563eb;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-label">Closed Entries</div>
                <div class="stat-value"><?= $total_closed ?></div>
            </div>
        </div>

        <!-- Search -->
        <form method="GET" class="search-bar">
            <div class="search-group">
                <label class="kl-label">Search Customer</label>
                <input type="text" name="search" class="kl-input"
                    placeholder="Name, email or unique ID..."
                    value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn-search">
                <i class="fas fa-search"></i> Search
            </button>
            <a href="customers.php" class="btn-reset">
                <i class="fas fa-times"></i> Reset
            </a>
        </form>

        <!-- Table -->
        <div class="kl-card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-users" style="color:#059669;"></i>
                    Customers List
                </div>
                <div class="results-count"><?= count($customers) ?> customers found</div>
            </div>

            <?php if(count($customers) > 0): ?>
            <div style="overflow-x:auto;">
                <table class="kl-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Unique ID</th>
                            <th>Email</th>
                            <th>Shops</th>
                            <th>Entries</th>
                            <th>Total Due</th>
                            <th>Joined</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($customers as $i => $c): ?>
                        <tr>
                            <td style="color:#94a3b8; font-weight:600;"><?= $i+1 ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div class="customer-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div style="font-weight:600; color:#0f172a;">
                                        <?= htmlspecialchars($c['name']) ?>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge-uid"><?= htmlspecialchars($c['unique_id']) ?></span></td>
                            <td style="color:#64748b;"><?= htmlspecialchars($c['email']) ?></td>
                            <td><span class="badge-count"><?= $c['total_shops'] ?> shops</span></td>
                            <td><span class="badge-count"><?= $c['total_entries'] ?> entries</span></td>
                            <td>
                                <?php if($c['total_due'] > 0): ?>
                                <span class="due-amount">₹<?= number_format($c['total_due'], 0) ?></span>
                                <?php else: ?>
                                <span class="no-due">✓ Clear</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:#64748b; white-space:nowrap;">
                                <?= date('d M Y', strtotime($c['created_at'])) ?>
                            </td>
                            <td class="text-end">
                                <a href="customers.php?delete_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this customer? This will also remove their credit history at all shops.')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="p-3 border-top d-flex justify-content-center">
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <p>No customers found</p>
                <span style="font-size:13px;">Try a different search or reset filters</span>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom-0 pt-4 px-4">
                <h5 class="fw-bold mb-0">Add New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="modal-body p-4">
                <input type="hidden" name="action" value="add_customer">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="kl-label">Full Name</label>
                        <input type="text" name="name" class="kl-input" placeholder="e.g. John Doe" required>
                    </div>
                    <div class="col-md-12">
                        <label class="kl-label">Email Address</label>
                        <input type="email" name="email" class="kl-input" placeholder="customer@email.com" required>
                    </div>
                    <div class="col-md-12">
                        <label class="kl-label">WhatsApp Phone Number</label>
                        <input type="text" name="phone" class="kl-input" placeholder="91XXXXXXXXXX" required>
                    </div>
                    <div class="col-md-12">
                        <label class="kl-label">Password</label>
                        <input type="text" name="password" class="kl-input" placeholder="Temporary password" required>
                        <small class="text-muted">Customer can change this later.</small>
                    </div>
                </div>
                <button type="submit" class="btn btn-success w-100 py-3 fw-bold mt-4" style="border-radius: 10px;">Create Customer Account</button>
            </form>
        </div>
    </div>
</div>

<div style="text-align:center; padding:20px; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; background:#ffffff;">
    © 2025 KhataLink — Admin Panel
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