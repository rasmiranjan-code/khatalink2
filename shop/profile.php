<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$success = '';
$error = '';

// Update Shop Details
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $shop_name = trim($_POST['shop_name']);
    $category = trim($_POST['shop_category']);
    $upi_id = trim($_POST['upi_id']);
    $gst_number = trim($_POST['gst_number']);
    $rzp_account_id = trim($_POST['rzp_account_id'] ?? '');
    $bank_acc_no = trim($_POST['bank_acc_no'] ?? '');
    $bank_ifsc = trim($_POST['bank_ifsc'] ?? '');
    $open_time = $_POST['open_time'] ?? '09:00:00';
    $close_time = $_POST['close_time'] ?? '21:00:00';

    if(empty($name) || empty($shop_name)) {
        $error = "Required fields cannot be empty.";
    } elseif (!empty($rzp_account_id) && (strlen($rzp_account_id) !== 18 || substr($rzp_account_id, 0, 4) !== 'acc_')) {
        $error = "Invalid ID! Aapne Bank Account Number dala hai. Yahan Razorpay 'Linked Account ID' chahiye jo 'acc_' se shuru hoti hai (18 characters).";
    } else {
        $stmt = $pdo->prepare("UPDATE shop_owners SET name = ?, shop_name = ?, shop_category = ?, upi_id = ?, gst_number = ?, rzp_account_id = ?, bank_acc_no = ?, bank_ifsc = ?, open_time = ?, close_time = ? WHERE id = ?");
        if($stmt->execute([$name, $shop_name, $category, $upi_id, $gst_number, $rzp_account_id, $bank_acc_no, $bank_ifsc, $open_time, $close_time, $shop_id])) {
            $_SESSION['shop_name'] = $shop_name;
            $success = "Shop settings updated successfully.";
        } else {
            $error = "Failed to update details.";
        }
    }
}

// Update Password
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_password'])) {
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    $stmt = $pdo->prepare("SELECT password FROM shop_owners WHERE id = ?");
    $stmt->execute([$shop_id]);
    $user = $stmt->fetch();

    if(!password_verify($current_pass, $user['password'])) {
        $error = "Current password is incorrect.";
    } elseif($new_pass !== $confirm_pass) {
        $error = "New passwords do not match.";
    } else {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE shop_owners SET password = ? WHERE id = ?")->execute([$hash, $shop_id]);
        $success = "Password changed successfully.";
    }
}

// Fetch Details
$stmt = $pdo->prepare("SELECT * FROM shop_owners WHERE id = ?");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Profile — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="hidden sm:flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
        <i class="fas fa-store"></i>
        <?= htmlspecialchars($_SESSION['shop_name']) ?>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/shop_sidebar.php'; ?>

    <div class="flex-1 p-4 md:p-8 max-w-6xl mx-auto w-full">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">Shop Settings</h1>
            <p class="text-slate-500 text-sm">Configure your business profile and security preferences.</p>
        </div>

        <?php if($success): ?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle"></i> <?= $success ?>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div class="lg:col-span-8">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-10 shadow-sm shadow-slate-200/50">
                    <h5 class="text-sm font-black text-slate-900 uppercase tracking-tight mb-8 flex items-center gap-2">
                        <i class="fas fa-briefcase text-blue-600"></i> Business Information
                    </h5>
                    <form method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Owner Name</label>
                                <input type="text" name="name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" value="<?= htmlspecialchars($shop['name']) ?>" required>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Shop Name</label>
                                <input type="text" name="shop_name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" value="<?= htmlspecialchars($shop['shop_name']) ?>" required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Email Address (Primary)</label>
                            <input type="email" class="w-full bg-slate-100 border-2 border-slate-100 rounded-2xl px-5 py-3.5 text-sm font-bold text-slate-400 cursor-not-allowed outline-none" value="<?= htmlspecialchars($shop['email']) ?>" readonly>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Business Category</label>
                                <select name="shop_category" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all cursor-pointer appearance-none">
                                    <option value="Fashion" <?= $shop['shop_category'] == 'Fashion' ? 'selected' : '' ?>>Fashion & Clothing</option>
                                    <option value="Electronics" <?= $shop['shop_category'] == 'Electronics' ? 'selected' : '' ?>>Electronics</option>
                                    <option value="Grocery" <?= $shop['shop_category'] == 'Grocery' ? 'selected' : '' ?>>Grocery & Kirana</option>
                                    <option value="General" <?= $shop['shop_category'] == 'General' ? 'selected' : '' ?>>General Store</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Opening Time</label>
                                    <input type="time" name="open_time" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" value="<?= $shop['open_time'] ?>">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Closing Time</label>
                                    <input type="time" name="close_time" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" value="<?= $shop['close_time'] ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="pt-6 border-t border-slate-100">
                            <h5 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-6">Payment & Legal</h5>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">UPI ID (for Statement QR)</label>
                                    <input type="text" name="upi_id" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" value="<?= htmlspecialchars($shop['upi_id']) ?>" placeholder="e.g. business@okbank">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">GST Number (Optional)</label>
                                    <input type="text" name="gst_number" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" value="<?= htmlspecialchars($shop['gst_number']) ?>" placeholder="22AAAAA0000A1Z5">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Cashfree Vendor ID (for EasySplit)</label>
                                    <input type="text" name="cf_vendor_id" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" value="<?= htmlspecialchars($shop['cf_vendor_id'] ?? '') ?>" placeholder="VND_XXXXX">
                                    <p class="text-[10px] text-slate-500 mt-1">Cashfree Dashboard mein banayi gayi Vendor ID dalein.</p>
                                </div>
                                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6 p-6 bg-blue-50/30 border border-blue-100 rounded-3xl">
                                    <div class="md:col-span-2 text-[10px] font-black text-blue-600 uppercase tracking-widest flex items-center gap-2"><i class="fas fa-university"></i> Settlememt Bank Details (Manual)</div>
                                    <div>
                                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Bank Account Number</label>
                                        <input type="text" name="bank_acc_no" class="w-full bg-white border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold" value="<?= htmlspecialchars($shop['bank_acc_no'] ?? '') ?>" placeholder="Account Number">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Bank IFSC Code</label>
                                        <input type="text" name="bank_ifsc" class="w-full bg-white border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold" value="<?= htmlspecialchars($shop['bank_ifsc'] ?? '') ?>" placeholder="IFSC Code">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" name="update_profile" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 flex items-center justify-center gap-2 uppercase tracking-widest text-[10px]">
                            <i class="fas fa-save"></i> Commit Profile Updates
                        </button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-4">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm shadow-slate-200/50">
                    <h5 class="text-sm font-black text-slate-900 uppercase tracking-tight mb-8 flex items-center gap-2">
                        <i class="fas fa-shield-alt text-red-600"></i> Account Security
                    </h5>
                    <form method="POST" class="space-y-5">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Current Password</label>
                            <input type="password" name="current_password" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-red-500 outline-none transition-all" required>
                        </div>
                        <div class="pt-4 border-t border-slate-50">
                            <div class="mb-4">
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">New Secure Password</label>
                                <input type="password" name="new_password" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" required>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" required>
                            </div>
                        </div>
                        <button type="submit" name="update_password" class="w-full bg-white border-2 border-slate-200 text-slate-900 font-black py-4 rounded-2xl hover:bg-slate-50 transition-all flex items-center justify-center gap-2 uppercase tracking-widest text-[10px]">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="bg-white border-t border-slate-200 py-6 text-center">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">
        © <?= date('Y') ?> KhataLink — Premium Digital Ledger
    </div>
</footer>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }
</script>

</body>
</html>