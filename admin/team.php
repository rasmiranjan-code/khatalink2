<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Sirf founder add kar sakta hai
$current_admin = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$current_admin->execute([$_SESSION['admin_id']]);
$current_admin = $current_admin->fetch();

$error = '';
$success = '';

// Add Team Member
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    if($current_admin['role'] != 'founder') {
        $error = "Only founder can add team members.";
    } else {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);

        if(empty($name) || empty($email) || empty($password)) {
            $error = "All fields are required.";
        } elseif(strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
            $stmt->execute([$email]);
            if($stmt->fetch()) {
                $error = "This email is already registered.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO admins (name, email, password, role, added_by) VALUES (?, ?, ?, 'team', ?)");
                $stmt->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $_SESSION['admin_id']
                ]);
                $success = "Team member added successfully!";
            }
        }
    }
}

// Delete Team Member
if(isset($_GET['delete']) && $current_admin['role'] == 'founder') {
    $del_id = (int)$_GET['delete'];
    // Founder khud ko delete nahi kar sakta
    $stmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
    $stmt->execute([$del_id]);
    $del_admin = $stmt->fetch();
    if($del_admin && $del_admin['role'] != 'founder') {
        $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$del_id]);
        $success = "Team member removed successfully!";
    } else {
        $error = "Cannot delete founder account.";
    }
}

// Get all team members
$team = $pdo->query("SELECT * FROM admins ORDER BY role ASC, created_at ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team — KhataLink Admin</title>
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

        /* ---- NAVBAR ---- */
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

        .btn-logout:hover {
            background: #dc2626;
            color: #ffffff;
        }

        /* ---- LAYOUT ---- */
        .layout {
            display: flex;
            min-height: calc(100vh - 64px);
        }

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

        .nav-link i { width: 18px; font-size: 15px; text-align: center; }
        .nav-link:hover { background: #eff6ff; color: #2563eb; }
        .nav-link.active { background: #eff6ff; color: #2563eb; font-weight: 600; }

        /* ---- MAIN ---- */
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

        /* ---- CARDS ---- */
        .kl-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ---- FORM ---- */
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
            padding: 12px 16px;
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

        .kl-input::placeholder { color: #94a3b8; }

        .kl-mb { margin-bottom: 18px; }

        .btn-primary {
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.3);
        }

        /* ---- ALERTS ---- */
        .kl-alert {
            border-radius: 10px;
            padding: 13px 16px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .kl-alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
        .kl-alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #059669; }

        /* ---- TABLE ---- */
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
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .kl-table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #374151;
            vertical-align: middle;
        }

        .kl-table tbody tr:last-child td { border-bottom: none; }
        .kl-table tbody tr:hover td { background: #fafbfc; }

        /* ---- BADGES ---- */
        .badge-founder {
            background: #fef3c7;
            color: #d97706;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-team {
            background: #eff6ff;
            color: #2563eb;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-delete {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-delete:hover {
            background: #dc2626;
            color: #ffffff;
            border-color: #dc2626;
        }

        /* ---- FOUNDER ONLY NOTE ---- */
        .founder-note {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 13px;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
        }

        /* ---- MOBILE ---- */
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
            .page-title { font-size: 22px; }
            .kl-card { padding: 18px; }
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
            <span style="color:#d97706; font-size:11px; margin-left:4px;">
                (<?= ucfirst($current_admin['role']) ?>)
            </span>
        </span>
        <a href="logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</nav>

<!-- Layout -->
<div class="layout">

    <?php include '../includes/admin_sidebar.php'; ?>

    <!-- Main -->
    <div class="main">

        <div class="page-title">Manage Team</div>
        <div class="page-subtitle">Add or remove team members who can access the admin panel</div>

        <?php if($error): ?>
        <div class="kl-alert kl-alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if($success): ?>
        <div class="kl-alert kl-alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if($current_admin['role'] == 'founder'): ?>

        <!-- Add Team Member Form -->
        <div class="kl-card">
            <div class="card-title">
                <i class="fas fa-user-plus" style="color:#2563eb;"></i>
                Add New Team Member
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="kl-mb">
                            <label class="kl-label">Full Name</label>
                            <input type="text" name="name" class="kl-input"
                                placeholder="Team member name" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="kl-mb">
                            <label class="kl-label">Email Address</label>
                            <input type="email" name="email" class="kl-input"
                                placeholder="Team member email" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="kl-mb">
                            <label class="kl-label">Password</label>
                            <input type="password" name="password" class="kl-input"
                                placeholder="Min 6 characters" required>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-user-plus"></i> Add Team Member
                </button>
            </form>
        </div>

        <?php else: ?>

        <div class="founder-note">
            <i class="fas fa-lock"></i>
            Only the founder can add or remove team members.
        </div>

        <?php endif; ?>

        <!-- Team List -->
        <div class="kl-card">
            <div class="card-title">
                <i class="fas fa-users" style="color:#059669;"></i>
                All Team Members (<?= count($team) ?>)
            </div>

            <div style="overflow-x:auto;">
                <table class="kl-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Added On</th>
                            <?php if($current_admin['role'] == 'founder'): ?>
                            <th>Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($team as $i => $member): ?>
                        <tr>
                            <td style="color:#94a3b8; font-weight:600;"><?= $i+1 ?></td>
                            <td>
                                <div style="font-weight:600; color:#0f172a;">
                                    <?= htmlspecialchars($member['name']) ?>
                                    <?php if($member['id'] == $_SESSION['admin_id']): ?>
                                    <span style="font-size:11px; color:#94a3b8; font-weight:400;">(You)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="color:#64748b;"><?= htmlspecialchars($member['email']) ?></td>
                            <td>
                                <?php if($member['role'] == 'founder'): ?>
                                <span class="badge-founder">
                                    <i class="fas fa-crown"></i> Founder
                                </span>
                                <?php else: ?>
                                <span class="badge-team">
                                    <i class="fas fa-user"></i> Team
                                </span>
                                <?php endif; ?>
                            </td>
                            <td style="color:#64748b;"><?= date('d M Y', strtotime($member['created_at'])) ?></td>
                            <?php if($current_admin['role'] == 'founder'): ?>
                            <td>
                                <?php if($member['role'] != 'founder'): ?>
                                <a href="team.php?delete=<?= $member['id'] ?>"
                                    class="btn-delete"
                                    onclick="return confirm('Remove this team member?')">
                                    <i class="fas fa-trash"></i> Remove
                                </a>
                                <?php else: ?>
                                <span style="font-size:12px; color:#94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<div style="text-align:center; padding:20px; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; background:#ffffff;">
    © 2025 KhataLink — Admin Panel
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>