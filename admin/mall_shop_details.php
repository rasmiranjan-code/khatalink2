<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/Groceries_inventory_engine.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }
$id = (int)($_GET['id'] ?? 0);

// Handle Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_mall'])) {
    $status = (int)$_POST['status'];
    $pdo->prepare("UPDATE shop_owners SET is_mall_active = ? WHERE id = ?")->execute([$status, $id]);
    header("Location: mall_shop_details.php?id=$id&msg=Status+Updated");
    exit();
}

// ── NEW: Handle Individual Product Toggle ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_product'])) {
    $pid = (int)$_POST['product_id'];
    $pstatus = (int)$_POST['pstatus'];
    $pdo->prepare("UPDATE inventory_products SET is_marketplace_visible = ? WHERE id = ? AND shop_id = ?")->execute([$pstatus, $pid, $id]);
    
    // Cache refresh
    if ($pstatus === 1) groceries_update_cache($pdo, $pid);
    else $pdo->prepare("DELETE FROM Groceries_product_marketplace_cache WHERE product_id = ? AND shop_id = ?")->execute([$pid, $id]);

    header("Location: mall_shop_details.php?id=$id&msg=Product+Status+Updated");
    exit();
}

// Fetch Shop Data
$stmt = $pdo->prepare("SELECT * FROM shop_owners WHERE id = ?");
$stmt->execute([$id]);
$s = $stmt->fetch();
if(!$s) die("Shop not found");

// ── FIX: Fetch from LIVE Inventory with dynamically calculated ratings ──
$stmt_p = $pdo->prepare("
    SELECT p.*, 
           COALESCE((SELECT AVG(sr.rating) FROM shop_ratings sr JOIN order_items oi ON sr.order_id = oi.order_id WHERE oi.product_id = p.id), 0) as avg_rating,
           (SELECT COUNT(*) FROM shop_ratings sr JOIN order_items oi ON sr.order_id = oi.order_id WHERE oi.product_id = p.id) as total_ratings
    FROM inventory_products p 
    WHERE p.shop_id = ? 
    ORDER BY p.id DESC
");
$stmt_p->execute([$id]);
$products = $stmt_p->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($s['shop_name']) ?> — Mall Kundfali</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900 p-8">
    <div class="max-w-5xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <div class="flex items-center gap-4">
                <a href="mall_shops.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
                <h1 class="text-2xl font-black text-slate-900 tracking-tight"><?= htmlspecialchars($s['shop_name']) ?> — Mall Status</h1>
            </div>
            <form method="POST">
                <input type="hidden" name="status" value="<?= $s['is_mall_active'] ? 0 : 1 ?>">
                <button type="submit" name="toggle_mall" class="px-8 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg transition-all <?= $s['is_mall_active'] ? 'bg-red-600 text-white hover:bg-red-700 shadow-red-100' : 'bg-emerald-600 text-white hover:bg-emerald-700 shadow-emerald-100' ?>">
                    <i class="fas <?= $s['is_mall_active'] ? 'fa-store-slash' : 'fa-check-circle' ?> me-2"></i><?= $s['is_mall_active'] ? 'Deactivate Mall Account' : 'Activate Mall Account' ?>
                </button>
            </form>
        </div>

        <!-- Product Grid listed in Mall -->
        <div class="mb-6">
            <h2 class="text-sm font-black text-slate-400 uppercase tracking-widest mb-6">Inventory Management (<?= count($products) ?> items)</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach($products as $p): ?>
                <div class="bg-white border <?= $p['is_marketplace_visible'] ? 'border-slate-200' : 'border-red-100 bg-red-50/20' ?> rounded-3xl p-4 shadow-sm flex flex-col group">
                    <div class="aspect-square bg-slate-50 rounded-2xl mb-4 overflow-hidden cursor-pointer" onclick="showProductDetails(<?= htmlspecialchars(json_encode($p)) ?>)">
                        <img src="../<?= $p['image_thumb_path'] ?: 'assets/img/products/placeholder.png' ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform">
                    </div>
                    <h4 class="text-xs font-black text-slate-900 truncate mb-1"><?= htmlspecialchars($p['name']) ?></h4>
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-[10px] font-black text-emerald-600">₹<?= number_format($p['sale_price'], 2) ?></span>
                        <div class="flex items-center gap-1 text-[9px] font-black <?= $p['avg_rating'] > 0 ? 'text-amber-500' : 'text-slate-300' ?>">
                            <i class="fas fa-star"></i> <?= number_format($p['avg_rating'], 1) ?>
                        </div>
                    </div>
                    <div class="mt-auto pt-3 border-t border-slate-50 flex justify-between mb-4">
                        <span class="text-[8px] font-bold text-slate-400 uppercase">Stock: <?= $p['current_stock'] ?></span>
                        <span class="text-[8px] font-bold text-blue-400 uppercase">Ratings: <?= $p['total_ratings'] ?></span>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="pstatus" value="<?= $p['is_marketplace_visible'] ? 0 : 1 ?>">
                        <button type="submit" name="toggle_product" class="w-full py-2 rounded-xl text-[8px] font-black uppercase tracking-widest border transition-all <?= $p['is_marketplace_visible'] ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-red-600 border-red-200 hover:bg-red-600 hover:text-white' ?>">
                            <?= $p['is_marketplace_visible'] ? 'Deactivate from Mall' : 'Activate in Mall' ?>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
                <?php if(empty($products)): ?>
                    <div class="col-span-full py-20 text-center bg-white border-2 border-dashed border-slate-200 rounded-3xl">
                        <p class="text-slate-400 font-bold text-xs uppercase italic">No products listed by this shop in the mall</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function showProductDetails(p) {
            Swal.fire({
                title: p.name,
                html: `
                    <div class="text-left space-y-4 py-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                                <div class="text-[8px] font-black text-slate-400 uppercase mb-1">Stock Level</div>
                                <div class="text-sm font-black ${p.current_stock > 0 ? 'text-emerald-600' : 'text-red-600'}">${p.current_stock} ${p.primary_unit}</div>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                                <div class="text-[8px] font-black text-slate-400 uppercase mb-1">Taxation</div>
                                <div class="text-sm font-black text-blue-600">${p.gst_percent}% GST</div>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between text-xs font-bold border-b border-slate-100 pb-2"><span>HSN Code:</span> <span class="text-slate-400">${p.hsn_code || 'N/A'}</span></div>
                            <div class="flex justify-between text-xs font-bold border-b border-slate-100 pb-2"><span>Manufacturing:</span> <span class="text-slate-900">${p.mfg_date || 'N/A'}</span></div>
                            <div class="flex justify-between text-xs font-bold border-b border-slate-100 pb-2"><span>Expiry Date:</span> <span class="text-red-600">${p.exp_date || 'N/A'}</span></div>
                        </div>
                        <div class="p-4 bg-indigo-50/50 rounded-2xl border border-indigo-100">
                             <div class="text-[8px] font-black text-indigo-400 uppercase mb-1">Description</div>
                             <p class="text-[11px] text-indigo-900 font-medium leading-relaxed">${p.description || 'No description provided by merchant.'}</p>
                        </div>
                    </div>
                `,
                confirmButtonText: 'Got it',
                confirmButtonColor: '#0f172a',
                customClass: { popup: 'rounded-[2.5rem] p-8' }
            });
        }
    </script>
</body>
</html>