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

$success = '';
$error = '';

// Handle Shop Deletion
if(isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    $stmt = $pdo->prepare("DELETE FROM shop_owners WHERE id = ?");
    if($stmt->execute([$del_id])) {
        $success = "Shop and all associated data deleted successfully.";
    }
}

// Handle Shop Addition
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_shop') {
    $name = trim($_POST['name']);
    $shop_name = trim($_POST['shop_name']);
    $email = trim($_POST['email']);
    $category = trim($_POST['category']);
    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    $upi_id = trim($_POST['upi_id']);

    $check = $pdo->prepare("SELECT id FROM shop_owners WHERE email = ?");
    $check->execute([$email]);
    if($check->fetch()) {
        $error = "Email already registered for another shop.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO shop_owners (name, shop_name, email, password, shop_category, upi_id) VALUES (?, ?, ?, ?, ?, ?)");
        if($stmt->execute([$name, $shop_name, $email, $password, $category, $upi_id])) {
            $success = "New shop registered successfully.";
        } else {
            $error = "Failed to add shop.";
        }
    }
}

// Search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';

$query = "SELECT s.*, COUNT(sc.customer_id) as total_customers,
          COUNT(ue.id) as total_entries
          FROM shop_owners s
          LEFT JOIN shop_customers sc ON s.id = sc.shop_id
          LEFT JOIN udhar_entries ue ON s.id = ue.shop_id
          WHERE 1=1";

$params = [];

if($search) {
    $query .= " AND (s.shop_name LIKE ? OR s.name LIKE ? OR s.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if($category) {
    $query .= " AND s.shop_category = ?";
    $params[] = $category;
}

$query .= " GROUP BY s.id ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$shops = $stmt->fetchAll();

// Total stats
$total_shops = $pdo->query("SELECT COUNT(*) FROM shop_owners")->fetchColumn();
$categories = $pdo->query("SELECT DISTINCT shop_category FROM shop_owners ORDER BY shop_category")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Shops — KhataLink Admin</title>
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

        /* Stats */
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

        /* Search Bar */
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

        /* Table Card */
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

        .shop-avatar {
            width: 40px;
            height: 40px;
            background: #eff6ff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
            font-size: 16px;
            flex-shrink: 0;
        }

        .badge-category {
            background: #f0fdf4;
            color: #059669;
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
        }

        .badge-count {
            background: #eff6ff;
            color: #2563eb;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
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

        .empty-state p {
            font-size: 15px;
            font-weight: 500;
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
            .search-bar { padding: 16px; }
        }
    </style>
</head>
<body>
<!-- Navbar -->
<nav class="kl-navbar">
    <div style="display:flex; align-items:center; gap:16px;">
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
<div class="min-h-[calc(100vh-64px)]">
    <!-- Main -->
    <div class="main">

        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <div class="page-title">All Shops</div>
                <div class="page-subtitle">View and manage all registered shops on KhataLink</div>
            </div>
            <button class="btn btn-primary fw-bold px-4" data-bs-toggle="modal" data-bs-target="#addShopModal">
                <i class="fas fa-plus me-2"></i> Register New Shop
            </button>
        </div>

        <?php if($success): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4"><?= $success ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4"><?= $error ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#eff6ff; color:#2563eb;">
                    <i class="fas fa-store"></i>
                </div>
                <div class="stat-label">Total Shops</div>
                <div class="stat-value"><?= $total_shops ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#ecfdf5; color:#059669;">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="stat-label">Categories</div>
                <div class="stat-value"><?= count($categories) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fffbeb; color:#d97706;">
                    <i class="fas fa-search"></i>
                </div>
                <div class="stat-label">Search Results</div>
                <div class="stat-value"><?= count($shops) ?></div>
            </div>
        </div>

        <!-- Search -->
        <form method="GET" class="search-bar">
            <div class="search-group">
                <label class="kl-label">Search Shop</label>
                <input type="text" name="search" class="kl-input"
                    placeholder="Shop name, owner name or email..."
                    value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="search-group" style="max-width: 200px;">
                <label class="kl-label">Category</label>
                <select name="category" class="kl-input">
                    <option value="">All Categories</option>
                    <?php foreach($categories as $cat): ?>
                    <option value="<?= $cat['shop_category'] ?>"
                        <?= $category == $cat['shop_category'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['shop_category']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-search">
                <i class="fas fa-search"></i> Search
            </button>
            <a href="shops.php" class="btn-reset">
                <i class="fas fa-times"></i> Reset
            </a>
        </form>

        <!-- Table -->
        <div class="kl-card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-store" style="color:#2563eb;"></i>
                    Shops List
                </div>
                <div class="results-count"><?= count($shops) ?> shops found</div>
            </div>

            <?php if(count($shops) > 0): ?>
            <div style="overflow-x:auto;">
                <table class="kl-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Shop</th>
                            <th>Owner</th>
                            <th>Email</th>
                            <th>Category</th>
                            <th>Customers</th>
                            <th>Entries</th>
                            <th>Joined</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($shops as $i => $shop): ?>
                        <tr>
                            <td style="color:#94a3b8; font-weight:600;"><?= $i+1 ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div class="shop-avatar">
                                        <i class="fas fa-store"></i>
                                    </div>
                                    <a href="shop_details.php?id=<?= $shop['id'] ?>" style="font-weight:600; color:#0f172a; text-decoration:none;" class="hover-primary">
                                        <?= htmlspecialchars($shop['shop_name']) ?>
                                    </a>
                                </div>
                            </td>
                            <td style="color:#374151;"><?= htmlspecialchars($shop['name']) ?></td>
                            <td style="color:#64748b;"><?= htmlspecialchars($shop['email']) ?></td>
                            <td><span class="badge-category"><?= htmlspecialchars($shop['shop_category']) ?></span></td>
                            <td><span class="badge-count"><?= $shop['total_customers'] ?></span></td>
                            <td><span class="badge-count"><?= $shop['total_entries'] ?></span></td>
                            <td style="color:#64748b; white-space:nowrap;">
                                <?= date('d M Y', strtotime($shop['created_at'])) ?>
                            </td>
                            <td class="text-end whitespace-nowrap">
                                <a href="shop_details.php?id=<?= $shop['id'] ?>" class="btn btn-sm btn-primary fw-bold px-3 rounded-pill" style="font-size: 11px;">
                                    <i class="fas fa-eye me-1"></i> Kundfali
                                </a>
                                <a href="shops.php?delete_id=<?= $shop['id'] ?>" class="btn btn-sm btn-outline-danger rounded-circle ms-2" onclick="return confirm('Delete shop permanently?')" style="width: 30px; height: 30px; padding: 0; line-height: 30px;">
                                    <i class="fas fa-trash" style="font-size: 10px;"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-store"></i>
                <p>No shops found</p>
                <span style="font-size:13px;">Try a different search or reset filters</span>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Add Shop Modal -->
<div class="modal fade" id="addShopModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom-0 pt-4 px-4">
                <h5 class="fw-bold mb-0">Register New Shop</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="modal-body p-4">
                <input type="hidden" name="action" value="add_shop">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="kl-label">Shop Name</label>
                        <input type="text" name="shop_name" class="kl-input" placeholder="e.g. Gupta General Store" required>
                    </div>
                    <div class="col-md-6">
                        <label class="kl-label">Owner Name</label>
                        <input type="text" name="name" class="kl-input" placeholder="Full Name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="kl-label">Category</label>
                        <select name="category" class="kl-input" required>
                            <option value="General">General Store</option>
                            <option value="Grocery">Grocery</option>
                            <option value="Fashion">Fashion</option>
                            <option value="Electronics">Electronics</option>
                            <option value="Medical">Medical</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="kl-label">Email Address</label>
                        <input type="email" name="email" class="kl-input" placeholder="shop@email.com" required>
                    </div>
                    <div class="col-md-12">
                        <label class="kl-label">Temporary Password</label>
                        <input type="text" name="password" class="kl-input" placeholder="Min 6 characters" required>
                    </div>
                    <div class="col-md-12">
                        <label class="kl-label">UPI ID (Optional)</label>
                        <input type="text" name="upi_id" class="kl-input" placeholder="upi@bank">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-3 fw-bold mt-4" style="border-radius: 10px;">Register Shop Account</button>
            </form>
        </div>
    </div>
</div>

<div style="text-align:center; padding:20px; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; background:#ffffff;">
    © 2025 KhataLink — Admin Panel
</div>

<script>
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>