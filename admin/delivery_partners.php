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

// ── HANDLE NEW PARTNER ADDITION ──
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_partner') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $pincode = trim($_POST['pincode']);
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    try {
        $check = $pdo->prepare("SELECT id FROM delivery_partners WHERE email = ? OR phone = ?");
        $check->execute([$email, $phone]);
        if($check->fetch()) {
            $error = "Email or Phone already exists in the system.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO delivery_partners (name, email, phone, pincode, password, is_verified, is_active) VALUES (?, ?, ?, ?, ?, 1, 1)");
            if($stmt->execute([$name, $email, $phone, $pincode, $pass])) {
                $success = "Rider $name added and activated successfully!";
            } else {
                $error = "Failed to add partner.";
            }
        }
    } catch (Exception $e) {
        $error = "Database Error: " . $e->getMessage();
    }
}

// Handle Actions (Activate/Deactivate/Delete)
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $partner_id = (int)$_POST['partner_id'];
    try {
        if($_POST['action'] == 'toggle_active') {
            $current_status = (int)$_POST['current_status'];
            $new_status = $current_status == 1 ? 0 : 1;
            $pdo->prepare("UPDATE delivery_partners SET is_active = ? WHERE id = ?")->execute([$new_status, $partner_id]);
            $success = "Partner status updated successfully!";
        } elseif($_POST['action'] == 'delete') {
            // Delete associated images first
            $stmt_img = $pdo->prepare("SELECT profile_image, aadhaar_photo FROM delivery_partners WHERE id = ?");
            $stmt_img->execute([$partner_id]);
            $partner_imgs = $stmt_img->fetch();
            
            if($partner_imgs) {
                $profile_dir = '../assets/img/profiles/';
                $aadhaar_dir = '../assets/img/aadhaar/';
                if($partner_imgs['profile_image'] && file_exists($profile_dir . $partner_imgs['profile_image'])) unlink($profile_dir . $partner_imgs['profile_image']);
                if($partner_imgs['aadhaar_photo'] && file_exists($aadhaar_dir . $partner_imgs['aadhaar_photo'])) unlink($aadhaar_dir . $partner_imgs['aadhaar_photo']);
            }
            $pdo->prepare("DELETE FROM delivery_partners WHERE id = ?")->execute([$partner_id]);
            $success = "Delivery Partner deleted successfully!";
        }
    } catch (Exception $e) {
        $error = "Operation failed: " . $e->getMessage();
    }
}

// Fetch all delivery partners with their stats
$stmt = $pdo->prepare("
    SELECT 
        dp.*,
        COALESCE(SUM(CASE WHEN o.order_status = 'delivered' THEN 1 ELSE 0 END), 0) as total_delivered_orders,
        COALESCE(SUM(dl.commission_earned), 0) as total_commission_earned
    FROM delivery_partners dp
    LEFT JOIN orders o ON dp.id = o.delivery_boy_id
    LEFT JOIN delivery_ledger dl ON o.id = dl.order_id AND dl.delivery_boy_id = dp.id
    GROUP BY dp.id
    ORDER BY dp.created_at DESC
");
$stmt->execute();
$delivery_partners = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Delivery Partners — KhataLink Admin</title>
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
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="Logo" style="height: 40px;">
        </a>
        <button onclick="document.getElementById('addRiderModal').classList.remove('hidden')" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg ml-4">
            <i class="fas fa-plus mr-1"></i> Add Partner
        </button>
    </div>
    <div class="text-right">
        <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($_SESSION['admin_name']) ?></span>
    </div>
</nav>

<div class="layout">
    <?php include '../includes/admin_sidebar.php'; ?>

    <main class="main">
        <div class="mb-8">
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Manage Delivery Partners</h1>
            <p class="text-slate-500 text-sm">Monitor, activate, and deactivate delivery personnel.</p>
        </div>

        <?php if($success): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if(empty($delivery_partners)): ?>
            <div class="text-center py-20 bg-white border border-dashed border-slate-200 rounded-[2.5rem]">
                <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-4"><i class="fas fa-motorcycle"></i></div>
                <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No delivery partners registered yet</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach($delivery_partners as $partner): ?>
                <div class="partner-card">
                    <div class="flex items-center gap-4 mb-4">
                        <?php if($partner['profile_image']): ?>
                            <img src="../assets/img/profiles/<?= $partner['profile_image'] ?>" class="w-14 h-14 rounded-full object-cover border-2 border-blue-200">
                        <?php else: ?>
                            <div class="partner-avatar">
                                <i class="fas fa-motorcycle"></i>
                            </div>
                        <?php endif; ?>
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
                        <a href="../assets/img/aadhaar/<?= $partner['aadhaar_photo'] ?>" target="_blank" class="block text-blue-600 text-xs font-bold mb-3">
                            <i class="fas fa-id-card me-1"></i> View Aadhaar Card
                        </a>
                    <?php else: ?>
                        <p class="text-red-500 text-xs mb-3">Aadhaar photo missing!</p>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 gap-2 text-xs font-bold text-slate-600 mb-4">
                        <div class="bg-slate-50 p-2 rounded-lg text-center">Orders: <span class="font-black text-blue-600"><?= $partner['total_delivered_orders'] ?></span></div>
                        <div class="bg-slate-50 p-2 rounded-lg text-center">Income: <span class="font-black text-emerald-600">₹<?= number_format($partner['total_commission_earned'], 2) ?></span></div>
                    </div>

                    <div class="mt-4 flex gap-2">
                        <form method="POST" class="flex-1">
                            <input type="hidden" name="partner_id" value="<?= $partner['id'] ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="current_status" value="<?= $partner['is_active'] ?>">
                            <button type="submit" class="w-full py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all <?= $partner['is_active'] ? 'bg-amber-500 text-white hover:bg-amber-600' : 'bg-emerald-600 text-white hover:bg-emerald-700' ?>">
                                <i class="fas <?= $partner['is_active'] ? 'fa-pause-circle' : 'fa-play-circle' ?> me-1"></i> <?= $partner['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                        <form method="POST" class="flex-1">
                            <input type="hidden" name="partner_id" value="<?= $partner['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" onclick="return confirm('Are you sure you want to delete this partner? All associated data will be removed.')" class="w-full bg-red-600 text-white py-2 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-red-700 transition-all">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Add Rider Modal -->
<div id="addRiderModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white w-full max-w-lg rounded-[2.5rem] p-8 shadow-2xl">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-black text-slate-900 uppercase">Register New Rider</h2>
            <button onclick="document.getElementById('addRiderModal').classList.add('hidden')" class="text-slate-400"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_partner">
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Rider Full Name</label>
                <input type="text" name="name" required class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold focus:border-blue-500 outline-none">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Email Address</label>
                    <input type="email" name="email" required class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Phone Number</label>
                    <input type="text" name="phone" required class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Assigned Pincode</label>
                    <input type="text" name="pincode" required class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Set Password</label>
                    <input type="password" name="password" required class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none">
                </div>
            </div>
            <div class="pt-4">
                <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-black uppercase text-xs tracking-widest shadow-xl shadow-slate-200">
                    Onboard Rider Instantly
                </button>
            </div>
        </form>
    </div>
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