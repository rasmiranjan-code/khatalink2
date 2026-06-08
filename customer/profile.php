<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$customer_id = $_SESSION['customer_id'];
$success = '';
$error = '';

// Update Profile Details
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);

    if(empty($name) || empty($phone)) {
        $error = "Required fields cannot be empty.";
    } else {
        $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ? WHERE id = ?");
        if($stmt->execute([$name, $phone, $customer_id])) {
            $_SESSION['customer_name'] = $name;
            $success = "Profile updated successfully.";
        } else {
            $error = "Failed to update profile. Email might be in use.";
        }
    }
}

// Update Password
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_password'])) {
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    $stmt = $pdo->prepare("SELECT password FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $user = $stmt->fetch();

    if(!password_verify($current_pass, $user['password'])) {
        $error = "Current password is incorrect.";
    } elseif($new_pass !== $confirm_pass) {
        $error = "New passwords do not match.";
    } elseif(strlen($new_pass) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE customers SET password = ? WHERE id = ?")->execute([$hash, $customer_id]);
        $success = "Password changed successfully.";
    }
}

// Fetch Current Data
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

// Calculate Trust Score for Profile
$u = $pdo->prepare("SELECT SUM(total_amount) as t, SUM(total_paid) as p FROM udhar_entries WHERE customer_id = ?");
$u->execute([$customer_id]); $ud = $u->fetch();
$b = $pdo->prepare("SELECT SUM(amount) as t, SUM(paid_amount) as p FROM bonds WHERE customer_id = ?");
$b->execute([$customer_id]); $bd = $b->fetch();
$m = $pdo->prepare("SELECT SUM(total_amount) as t, SUM(paid_amount) as p FROM monthly_khata WHERE customer_id = ?");
$m->execute([$customer_id]); $md = $m->fetch();

$tb = (float)$ud['t'] + (float)$bd['t'] + (float)$md['t'];
$tp = (float)$ud['p'] + (float)$bd['p'] + (float)$md['p'];
$trust_score = ($tb > 0) ? round(($tp / $tb) * 100) : 100;

if (!$customer) {
    session_destroy();
    header("Location: ../auth/login.php?type=customer&error=Invalid session. Please login again.");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass-banner { background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%); position: relative; overflow: hidden; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="flex items-center gap-3">
        <div class="bg-indigo-50 border border-indigo-100 text-indigo-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
            <i class="fas fa-id-card me-1"></i> ID: <?= $customer['unique_id'] ?>
        </div>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/customer_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">
        <div class="glass-banner rounded-3xl p-8 mb-8 text-white shadow-xl">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight mb-1">Account Settings</h1>
            <p class="text-indigo-100 text-sm">Manage your personal information and security.</p>
        </div>

        <?php if($success): ?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-bold mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle"></i> <?= $success ?>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-bold mb-6 flex items-center gap-3">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Trust Score Card -->
            <div class="lg:col-span-12">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 flex items-center justify-between shadow-sm border-l-8 <?= $trust_score > 70 ? 'border-emerald-500' : 'border-amber-500' ?>">
                    <div class="flex items-center gap-6">
                        <div class="w-16 h-16 rounded-2xl bg-slate-900 text-white flex items-center justify-center text-2xl font-black">
                            <?= $trust_score ?>
                        </div>
                        <div>
                            <h4 class="text-sm font-black uppercase tracking-widest text-slate-900">Your KhataLink Trust Rating</h4>
                            <p class="text-xs text-slate-500 font-medium">This score is shared with merchants to verify your credit worthiness.</p>
                        </div>
                    </div>
                    <div class="text-right hidden md:block">
                        <span class="text-[10px] font-black px-3 py-1 rounded-full uppercase tracking-widest <?= $trust_score > 70 ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' ?>">
                            <?= $trust_score > 80 ? 'Excellent' : ($trust_score > 50 ? 'Good' : 'Needs Improvement') ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Customer QR Code for Shopkeepers -->
            <div class="lg:col-span-12 text-center bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm">
                <h4 class="text-sm font-black uppercase tracking-widest text-slate-900 mb-4">Your KhataLink ID QR Code</h4>
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($customer['unique_id']) ?>" alt="Customer ID QR Code" class="mx-auto border border-slate-200 rounded-lg p-2">
                <p class="text-xs text-slate-500 mt-2">Shopkeepers can scan this QR to quickly add you or check your Trust Score.</p>
            </div>

            <div class="lg:col-span-7">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm">
                    <h5 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-6">Personal Details</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Full Name</label>
                            <input type="text" name="name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" value="<?= htmlspecialchars($customer['name']) ?>" required>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="mb-3">
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Email Address</label>
                                <input type="email" class="w-full bg-slate-100 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold text-slate-500 outline-none" value="<?= htmlspecialchars($customer['email']) ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">WhatsApp Number</label>
                                <input type="text" name="phone" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" value="<?= htmlspecialchars($customer['phone']) ?>" required>
                            </div>
                        </div>
                        <button type="submit" name="update_profile" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 uppercase tracking-widest text-[10px]">Save Changes</button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-5">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm">
                    <h5 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-6">Security</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Current Password</label>
                            <input type="password" name="current_password" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">New Password</label>
                            <input type="password" name="new_password" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" required>
                        </div>
                        <button type="submit" name="update_password" class="w-full border-2 border-slate-900 text-slate-900 font-black py-4 rounded-2xl hover:bg-slate-900 hover:text-white transition-all uppercase tracking-widest text-[10px]">Change Password</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }
</script>

</body>
</html>