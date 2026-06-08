<?php
session_start();
require_once '../includes/db.php';

// Admin authentication check
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/admin_login.php"); // Assuming admin login page
    exit();
}

$message = '';
$error = '';

// Handle banner upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_banner'])) {
    $target = trim($_POST['target']);
    $file = $_FILES['banner_image'];

    if (empty($target)) {
        $error = "Banner target is required.";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload error: " . $file['error'];
    } else {
        $upload_dir = '../uploads/banners/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true); // Create directory if it doesn't exist
        }

        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $new_file_name = uniqid('banner_') . '.' . $file_extension;
        $target_file = $upload_dir . $new_file_name;

        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            $image_path = 'uploads/banners/' . $new_file_name; // Path to store in DB
            $stmt = $pdo->prepare("INSERT INTO banners (image_path, target) VALUES (?, ?)");
            if ($stmt->execute([$image_path, $target])) {
                $message = "Banner uploaded successfully!";
            } else {
                $error = "Failed to save banner to database.";
                unlink($target_file); // Delete uploaded file if DB insert fails
            }
        } else {
            $error = "Failed to move uploaded file.";
        }
    }
}

// Handle banner deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_banner'])) {
    $banner_id = (int)$_POST['banner_id'];
    $stmt = $pdo->prepare("SELECT image_path FROM banners WHERE id = ?");
    $stmt->execute([$banner_id]);
    $banner = $stmt->fetch();

    if ($banner) {
        $file_to_delete = '../' . $banner['image_path'];
        if (file_exists($file_to_delete)) {
            unlink($file_to_delete);
        }
        $stmt = $pdo->prepare("DELETE FROM banners WHERE id = ?");
        if ($stmt->execute([$banner_id])) {
            $message = "Banner deleted successfully!";
        } else {
            $error = "Failed to delete banner from database.";
        }
    } else {
        $error = "Banner not found.";
    }
}

// Fetch all banners for display
$stmt = $pdo->query("SELECT * FROM banners ORDER BY created_at DESC");
$banners = $stmt->fetchAll();

// Fetch current admin data for sidebar
$stmt_current_admin = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
$stmt_current_admin->execute([$_SESSION['admin_id']]);
$current_admin = $stmt_current_admin->fetch();
$admin_role = $current_admin['role'] ?? 'team';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Banners — Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
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
    <div class="text-[10px] font-black text-blue-700 uppercase tracking-widest bg-blue-50 border border-blue-100 px-3 py-1.5 rounded-full">
        <i class="fas fa-image me-1"></i> Banner Management
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/admin_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        <div class="mb-8">
            <h1 class="text-3xl font-black text-slate-900 tracking-tight">Manage Banners</h1>
            <p class="text-slate-500 text-sm">Upload and manage banner images for different sections of the platform.</p>
        </div>

        <?php if ($message): ?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-bold mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-bold mb-6 flex items-center gap-3">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm mb-8">
            <h2 class="text-xl font-black text-slate-900 mb-6">Upload New Banner</h2>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Banner Image</label>
                    <input type="file" name="banner_image" accept="image/*" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all">
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Target Section</label>
                    <select name="target" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all">
                        <option value="">Select Target</option>
                        <option value="all">All Pages (Default)</option>
                        <option value="customer">Customer Dashboard</option>
                        <option value="groceries">Groceries Home</option>
                        <option value="partner">Partner Page Header</option>
                        <option value="partner_opportunities">Partner Page Opportunities</option>
                        <option value="delivery">Delivery Partner Page Header</option>
                        <option value="partner_card_store">Partner Card: Store</option>
                        <option value="partner_card_rent">Partner Card: Rent</option>
                        <option value="partner_card_seller">Partner Card: Seller</option>
                        <option value="partner_card_deliver">Partner Card: Deliver</option>
                    </select>
                </div>
                <button type="submit" name="upload_banner" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all shadow-lg uppercase tracking-widest text-xs">
                    <i class="fas fa-upload me-2"></i> Upload Banner
                </button>
            </form>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm">
            <h2 class="text-xl font-black text-slate-900 mb-6">Existing Banners</h2>
            <?php if (empty($banners)): ?>
                <div class="text-center py-10 text-slate-400">No banners uploaded yet.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($banners as $banner): ?>
                        <div class="bg-slate-50 border border-slate-100 rounded-2xl overflow-hidden shadow-sm relative">
                            <img src="../<?= htmlspecialchars($banner['image_path']) ?>" alt="Banner" class="w-full h-32 object-cover">
                            <div class="p-4">
                                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Target:</p>
                                <p class="text-sm font-bold text-slate-900"><?= htmlspecialchars($banner['target']) ?></p>
                                <p class="text-[9px] text-slate-500 mt-1">Uploaded: <?= date('d M Y', strtotime($banner['created_at'])) ?></p>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this banner?');" class="mt-4">
                                    <input type="hidden" name="banner_id" value="<?= $banner['id'] ?>">
                                    <button type="submit" name="delete_banner" class="w-full bg-red-50 text-red-600 text-[10px] font-black uppercase py-2 rounded-xl hover:bg-red-100 transition-all">
                                        <i class="fas fa-trash me-1"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

</body>
</html>