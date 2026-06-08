<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/Groceries_media_processor.php';
require_once '../includes/Groceries_inventory_engine.php';

if(!isset($_SESSION['shop_id'])) exit("Unauthorized");
$shop_id = $_SESSION['shop_id'];
$success = ""; $error = "";

if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $name = trim($_POST['name']);
    $price = (float)$_POST['sale_price'];
    $stock = (float)$_POST['opening_stock'];
    $unit = $_POST['primary_unit'];

    if(empty($name) || !isset($_FILES['photo']) || $_FILES['photo']['error'] !== 0) {
        $error = "Product name and Image are MANDATORY for Groceries Mall.";
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO inventory_products (shop_id, name, sale_price, opening_stock, current_stock, primary_unit) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$shop_id, $name, $price, $stock, $stock, $unit]);
            $product_id = $pdo->lastInsertId();

            // Process Mandatory Image
            $images = groceries_process_image($_FILES['photo']['tmp_name'], $product_id, $shop_id);
            
            if(!empty($images)) {
                $pdo->prepare("UPDATE inventory_products SET image_hero_path = ?, image_thumb_path = ?, image_tiny_base64 = ? WHERE id = ?")
                    ->execute([$images['hero'], $images['thumb'], $images['tiny'], $product_id]);
                
                // Sync to Marketplace Cache
                groceries_update_cache($pdo, $product_id);
                
                $pdo->commit();
                $success = "Product published to Groceries Mall successfully!";
            } else { throw new Exception("Image processing failed."); }
        } catch (Exception $e) { $pdo->rollBack(); $error = $e->getMessage(); }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mall Inventory — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 font-[Inter] p-4 md:p-8">
    <div class="max-w-2xl mx-auto">
        <div class="mb-8 flex items-center justify-between">
            <h1 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Mall Inventory</h1>
            <a href="inventory.php" class="text-xs font-bold text-slate-400">Back</a>
        </div>

        <?php if($success): ?><div class="bg-emerald-600 text-white p-4 rounded-2xl mb-6 font-bold text-sm shadow-lg"><?= $success ?></div><?php endif; ?>
        <?php if($error): ?><div class="bg-red-600 text-white p-4 rounded-2xl mb-6 font-bold text-sm shadow-lg"><?= $error ?></div><?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm space-y-6">
            <div class="p-6 bg-emerald-50 rounded-3xl border-2 border-dashed border-emerald-200 text-center">
                <label class="cursor-pointer block">
                    <i class="fas fa-camera text-2xl text-emerald-600 mb-2"></i>
                    <p class="text-[10px] font-black uppercase text-emerald-600 tracking-widest">Upload Product Photo *</p>
                    <input type="file" name="photo" accept="image/*" class="hidden" required>
                    <p class="text-[8px] text-emerald-400 mt-1 italic">WebP high-performance conversion active</p>
                </label>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Product Name</label>
                    <input type="text" name="name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-emerald-500" required>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Sale Price (₹)</label>
                        <input type="number" step="0.01" name="sale_price" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-black text-emerald-600 outline-none" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Opening Stock</label>
                        <input type="number" step="0.01" name="opening_stock" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none" required>
                    </div>
                </div>
                <input type="hidden" name="primary_unit" value="PCS">
            </div>

            <button type="submit" name="add_product" class="w-full bg-emerald-600 text-white font-black py-5 rounded-3xl uppercase tracking-widest text-xs shadow-xl shadow-emerald-100">
                Publish to Groceries Mall
            </button>
        </form>
    </div>
</body>
</html>