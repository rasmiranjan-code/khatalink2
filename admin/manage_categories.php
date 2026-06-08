<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

$categories = [
    'Paan Corner', 'Dairy, Bread & Eggs', 'Fruits & Vegetables', 'Cold Drinks & Juices', 
    'Snacks & Munchies', 'Breakfast & Instant', 'Sweet Tooth', 'Bakery & Biscuits', 
    'Tea, Coffee & Health', 'Atta, Rice & Dal', 'Masala, Oil & More', 'Sauces & Spreads', 
    'Chicken, Meat & Fish', 'Organic & Healthy', 'Baby Care', 'Cleaning Essentials', 
    'Home & Office', 'Personal Care', 'Pet Care', 'Frozen Foods'
];

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cat_image'])) {
    $cat_name = $_POST['cat_name'];
    $upload_dir = '../assets/img/categories/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    
    // 1. Fetch old image path to delete it from server storage
    $stmt_old = $pdo->prepare("SELECT image_path FROM mall_categories WHERE category_name = ?");
    $stmt_old->execute([$cat_name]);
    $old_path = $stmt_old->fetchColumn();
    if ($old_path && file_exists('../' . $old_path)) {
        @unlink('../' . $old_path);
    }

    $ext = pathinfo($_FILES['cat_image']['name'], PATHINFO_EXTENSION);
    // 2. Add time() stamp to filename for cache-busting in Flutter/Apps
    $filename = str_replace(' ', '_', strtolower($cat_name)) . '_' . time() . '.' . $ext;
    
    if(move_uploaded_file($_FILES['cat_image']['tmp_name'], $upload_dir . $filename)) {
        $path = 'assets/img/categories/' . $filename;
        $stmt = $pdo->prepare("INSERT INTO mall_categories (category_name, image_path) VALUES (?, ?) ON DUPLICATE KEY UPDATE image_path = ?");
        $stmt->execute([$cat_name, $path, $path]);
        $success = "Image updated for $cat_name";
    }
}

$db_cats = $pdo->query("SELECT * FROM mall_categories")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Categories — KhataLink Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 p-4 md:p-8 font-[Inter]">
    <div class="max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-10">
            <h1 class="text-2xl font-black">Mall Category Images</h1>
            <a href="dashboard.php" class="text-xs font-bold uppercase tracking-widest bg-slate-900 text-white px-5 py-2.5 rounded-xl">Back</a>
        </div>

        <?php if(isset($success)): ?>
            <div class="bg-emerald-50 text-emerald-600 p-4 rounded-2xl mb-6 font-bold text-sm border border-emerald-100">
                <i class="fas fa-check-circle me-2"></i> <?= $success ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach($categories as $cat): 
                $img = isset($db_cats[$cat]) ? '../' . $db_cats[$cat]['image_path'] : 'https://cdn-icons-png.flaticon.com/512/1170/1170577.png';
            ?>
            <div class="bg-white p-4 rounded-3xl border border-slate-200 flex items-center gap-4">
                <img src="<?= $img ?>" class="w-16 h-16 rounded-2xl object-cover bg-slate-50">
                <div class="flex-1">
                    <h4 class="font-bold text-sm text-slate-800 mb-2"><?= $cat ?></h4>
                    <form method="POST" enctype="multipart/form-data" class="flex items-center gap-2">
                        <input type="hidden" name="cat_name" value="<?= $cat ?>">
                        <label class="cursor-pointer bg-slate-100 hover:bg-indigo-50 hover:text-indigo-600 transition-all px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest">
                            Upload
                            <input type="file" name="cat_image" class="hidden" onchange="this.form.submit()">
                        </label>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>