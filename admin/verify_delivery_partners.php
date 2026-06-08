<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch current admin role for the navbar display
$stmt_role = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
$stmt_role->execute([$_SESSION['admin_id']]);
$admin_role = $stmt_role->fetchColumn() ?: 'team';

$success = '';
$error = '';

// Handle Verification Action
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_partner'])) {
    $partner_id = (int)$_POST['partner_id'];
    try {
        $pdo->prepare("UPDATE delivery_partners SET is_verified = 1 WHERE id = ?")->execute([$partner_id]);
        $success = "Delivery Partner verified successfully!";
    } catch (Exception $e) {
        $error = "Failed to verify partner: " . $e->getMessage();
    }
}

// Fetch all unverified delivery partners
$stmt = $pdo->prepare("SELECT * FROM delivery_partners WHERE is_verified = 0 ORDER BY created_at ASC");
$stmt->execute();
$unverified_partners = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Delivery Partners — KhataLink Admin</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        .kl-navbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .layout { display: flex; min-height: calc(100vh - 64px); }
        .main { flex: 1; padding: 32px; overflow-x: hidden; }
        .page-title { font-size: 26px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 4px; }
        .page-subtitle { font-size: 14px; color: #64748b; margin-bottom: 28px; }
        .kl-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; }
        .partner-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 2rem; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .partner-avatar { width: 60px; height: 60px; border-radius: 1.5rem; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .aadhaar-img { max-width: 100%; height: auto; border-radius: 1rem; margin-top: 15px; border: 1px solid #e2e8f0; }
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 22px; cursor: pointer; padding: 8px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 998; }
        @media (max-width: 992px) { .sidebar-overlay.show { display: block; } .main { padding: 20px 16px; } }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<nav class="kl-navbar">
    <div style="display:flex; align-items:center; gap:16px;">
        <button class="lg:hidden text-slate-600" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
        <a href="dashboard.php">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="Logo" style="height: 40px;">
        </a>
    </div>
    <div class="text-right">
        <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($_SESSION['admin_name']) ?></span>
    </div>
</nav>

<div class="layout">
    <?php include '../includes/admin_sidebar.php'; ?>

    <main class="main">
        <div class="mb-8">
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Pending Delivery Partner Verifications</h1>
            <p class="text-slate-500 text-sm">Review and approve new delivery partners to join the network.</p>
        </div>

        <?php if($success): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if(empty($unverified_partners)): ?>
            <div class="text-center py-20 bg-white border border-dashed border-slate-200 rounded-[2.5rem]">
                <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-4"><i class="fas fa-check-double"></i></div>
                <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No pending verifications</p>
                <p class="text-slate-500 text-sm mt-2">All delivery partners are currently verified.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach($unverified_partners as $partner): ?>
                <div class="partner-card">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="partner-avatar">
                            <i class="fas fa-motorcycle"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-black text-slate-900"><?= htmlspecialchars($partner['name']) ?></h3>
                            <p class="text-xs text-slate-500"><?= htmlspecialchars($partner['email']) ?></p>
                        </div>
                    </div>
                    <div class="text-sm text-slate-600 mb-3">
                        <p><i class="fas fa-phone-alt me-2"></i> <?= htmlspecialchars($partner['phone']) ?></p>
                        <p><i class="fas fa-map-marker-alt me-2"></i> <?= htmlspecialchars($partner['full_address']) ?>, <?= htmlspecialchars($partner['pincode']) ?></p>
                    </div>
                    <?php if($partner['aadhaar_photo']): ?>
                        <a href="../assets/img/aadhaar/<?= $partner['aadhaar_photo'] ?>" target="_blank">
                            <img src="../assets/img/aadhaar/<?= $partner['aadhaar_photo'] ?>" alt="Aadhaar Card" class="aadhaar-img">
                        </a>
                        <p class="text-[9px] text-slate-400 italic mt-2">Click image to view full Aadhaar.</p>
                    <?php else: ?>
                        <p class="text-red-500 text-xs mt-2">Aadhaar photo missing!</p>
                    <?php endif; ?>

                    <div class="mt-6">
                        <form method="POST">
                            <input type="hidden" name="partner_id" value="<?= $partner['id'] ?>">
                            <button type="submit" name="verify_partner" class="w-full bg-emerald-600 text-white font-bold py-3 rounded-xl text-sm uppercase tracking-widest hover:bg-emerald-700 transition-all">
                                <i class="fas fa-check-circle me-2"></i> Verify Partner
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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