<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
require_once '../includes/notification_service.php';
require_once '../includes/Groceries_media_processor.php';
require_once '../includes/Groceries_inventory_engine.php';
track_visitor($pdo);

if (!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id   = $_SESSION['shop_id'];
$error     = '';
$success   = '';
$edit_mode = false;
$view_mode = false;
$product_data = [];

// Handle viewing an existing product
if (isset($_GET['view'])) {
    $view_mode  = true;
    $product_id = (int)$_GET['view'];
    $stmt_product = $pdo->prepare("SELECT * FROM inventory_products WHERE id = ? AND shop_id = ?");
    $stmt_product->execute([$product_id, $shop_id]);
    $product_data = $stmt_product->fetch();
    if (!$product_data) { header("Location: inventory.php"); exit(); }
}

// Handle Delete Product
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    $stmt_p = $pdo->prepare("SELECT photo FROM inventory_products WHERE id = ? AND shop_id = ?");
    $stmt_p->execute([$del_id, $shop_id]);
    $p_to_del = $stmt_p->fetch();
    if ($p_to_del) {
        if ($p_to_del['photo'] && file_exists('../assets/img/products/' . $p_to_del['photo'])) {
            unlink('../assets/img/products/' . $p_to_del['photo']);
        }
        // Mall Cache se product hatao
        $pdo->prepare("DELETE FROM Groceries_product_marketplace_cache WHERE product_id = ?")->execute([$del_id]);
        $pdo->prepare("DELETE FROM inventory_products WHERE id = ? AND shop_id = ?")->execute([$del_id, $shop_id]);
        header("Location: inventory.php?success=Product deleted successfully.");
        exit();
    }
}

// Handle editing an existing product
if (isset($_GET['edit'])) {
    $edit_mode  = true;
    $product_id = (int)$_GET['edit'];
    $stmt_product = $pdo->prepare("SELECT * FROM inventory_products WHERE id = ? AND shop_id = ?");
    $stmt_product->execute([$product_id, $shop_id]);
    $product_data = $stmt_product->fetch();
    if (!$product_data) { header("Location: inventory.php"); exit(); }
}

// ── Units list ─────────────────────────────────────────────────────────────
$units = [
    ['NOS', 'Numbers'], ['PCS', 'Pieces'],  ['KGS', 'Kilograms'], ['BAG', 'Bags'],
    ['BTL', 'Bottles'], ['MLT', 'Mililitre'], ['PAC', 'Packs'],  ['QTL', 'Quintal'],
    ['ROL', 'Rolls'],   ['SET', 'Sets'],    ['TBS', 'Tablets'],  ['TUB', 'Tubes'],
    ['PET', 'Peti'],    ['JAR', 'Jars'],    ['POCH', 'Pouch'],   ['BOR', 'Bora'],
    ['CASE', 'CASE'],   ['CPS', 'Capsules'],['PKT', 'Packets'],  ['QTY', 'Quantity'],
    ['OTH', 'Others'],
];

// ── Categories list (ONE definition, used everywhere) ──────────────────────
$mall_cats = [
    'Chicken, Meat & Fish', 'Exotic Meat', 'Fish & Seafood', 'Chicken', 'Mutton', 'Sausage, Salami & Ham',
    'Pet Care', 'Dog Food & Treats', 'Cat Food & Treats', 'Pet Grooming & Accessories',
    'Baby Care', 'Baby Diapers', 'Baby Feeding Needs', 'Baby Wipes', 'Baby Food', 'Mom Care',
    'Sweet Tooth', 'Flavoured Yogurts', 'Ice Cream & Frozen Dessert', 'Indian Sweets', 'Chocolates',
    'Chocolate Bars', 'Candies & Gum', 'Mouth Fresheners', 'Cakes and Rolls',
    'Tea, Coffee & Health Drinks', 'Tea', 'Coffee', 'Health Drinks', 'Herbal Drinks',
    'Green Tea', 'Roasted & Ground Coffee', 'Instant Coffee',
    'Beauty & Cosmetics', 'Hair Care', 'Shampoo', 'Conditioners', 'Hair Oil', 'Hair Mask',
    'Lipstick', 'Foundation', 'Nail color/paints', 'Face Cream & Gel', 'Facial Kit',
    'Deodorants & Powders', 'Feminine Care', 'Oral Care', 'Personal Care',
    'Dairy, Bread & Eggs', 'Cheese', 'Curd & Yogurt', 'Milk', 'Paneer & Tofu',
    'Butter & More', 'Bread', 'Eggs', 'Peanut Butter', 'Honey',
    'Breakfast & Instant Food', 'Energy Bars', 'Breakfast Cereal', 'Noodles',
    'Soup', 'Instant Mixes', 'Pasta', 'Ready to Eat',
    'Atta, Rice & Dal', 'Rice', 'Besan, Sooji & Maida', 'Rajma, Chhole & Others',
    'Toor', 'Arhar', 'Basmati Rice', 'Atta', 'Flours',
    'Cleaning Essentials', 'Detergent Powder & Bars', 'Liquid Detergents',
    'Toilet Cleaners & More', 'Floor Cleaners & More', 'Dishwashing Gels & Powders',
    'Dishwashing Bars', 'Scrubbers & Cleaning Aids', 'Brooms & Mops',
    'Snacks & Munchies', 'Namkeen Snacks', 'Chips & Crisps', 'Popcorn',
    'Nachos', 'Papad & Fryums', 'Healthy Snacks', 'Bhujia & Mixtures',
    'Sauces & Spreads', 'Jams', 'Ketchup', 'Chutney & Pickle',
    'Peanut Butter', 'Chocolate Spreads', 'Dips & Spreads',
    'Bakery & Biscuits', 'Cookies', 'Cream Biscuits', 'Rusks & Wafers',
    'Gourmet Bakery', 'Specialty Breads', 'Milk & White Breads', 'Buns & Pav',
    'Cold Drinks & Juices', 'Energy Drinks', 'Soft Drinks', 'Coconut Water',
    'Fruit Juices', 'Flavored Milk', 'Lassi', 'Mineral Water', 'Soda',
    'Ice Cream & Frozen Desserts', 'Frozen Veg Snacks', 'Frozen Non-Veg Snacks',
    'Masala, Oil & More', 'Powdered Masala', 'Whole Spices', 'Oil', 'Ghee & Vanaspati',
    'Salt, Sugar & Jaggery', 'Dry Fruits', 'Dates & Seeds',
    'Vegetables & Fruits', 'Fresh Vegetables', 'Fruits', 'Leafies & Herbs',
    'Organic & Gourmet', 'Organic & Hydroponic', 'Exotics & Premium',
    'Pharma & Wellness', 'Vitamins & Daily Nutrition', 'Cough & Cold',
    'Wound Care And Pain Relief', 'Digestive Care', 'Condoms', 'Adult Diapers',
    'Masks & Sanitizers', 'Supplements', 'Pre & Post Workout',
    'Home & Office', 'Stationery Needs', 'Kitchen & Dining Needs',
    'Tissues & Disposables', 'Cleaning Tools', 'Party Essentials', 'Pooja Needs',
    'Toys & Games', 'Soft Toys', 'Learning Toys', 'Board Games', 'Puzzles',
    'Action Figures', 'Art & Craft Kits',
    'Books', 'Children\'s Books', 'Self Help Books', 'Fiction Books', 'Non Fiction Books',
    'Paan Corner', 'Cigarettes & Tobacco', 'Hookah Needs', 'Smoking Needs',
    'Digital Goods', 'Rakhi Gifts', 'Festive & Occasion Needs',
    'Other',
];

// ── POST Handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name             = trim($_POST['name'] ?? '');
    $primary_unit     = $_POST['primary_unit'] ?? 'NOS';
    $sale_price       = (float)($_POST['sale_price'] ?? 0);
    $purchase_price   = (float)($_POST['purchase_price'] ?? 0);
    $tax_included     = isset($_POST['tax_included']) ? 1 : 0;
    $opening_stock    = (float)($_POST['opening_stock'] ?? 0);
    $low_stock_alert  = (float)($_POST['low_stock_alert'] ?? 0);
    $hsn_code         = trim($_POST['hsn_code'] ?? '');
    $gst_percent      = (float)($_POST['gst_percent'] ?? 0);
    $product_id_post  = (int)($_POST['product_id'] ?? 0);
    $barcode          = trim($_POST['barcode'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $mfg_date         = !empty($_POST['mfg_date']) ? $_POST['mfg_date'] : null;
    $exp_date         = !empty($_POST['exp_date']) ? $_POST['exp_date'] : null;
    $product_categories = $_POST['product_category'] ?? ['Other'];
    $product_category_str = implode(',', $product_categories);

    if (empty($name)) {
        $error = "Product name is required.";
    } elseif ($product_id_post == 0 && (!isset($_FILES['photos']) || $_FILES['photos']['error'][0] !== 0)) {
        $error = "Product photo is mandatory to publish in Groceries Mall.";
    } else {
        if ($product_id_post > 0) {
            // UPDATE
            $pdo->beginTransaction();
            $stmt_curr = $pdo->prepare("SELECT photo FROM inventory_products WHERE id = ? AND shop_id = ?");
            $stmt_curr->execute([$product_id_post, $shop_id]);
            $curr_prod = $stmt_curr->fetch();

            $pdo->prepare("UPDATE inventory_products SET name=?, primary_unit=?, sale_price=?, purchase_price=?, tax_included=?, opening_stock=?, low_stock_alert=?, current_stock=?, hsn_code=?, gst_percent=?, barcode=?, description=?, mfg_date=?, exp_date=?, product_category=?, last_updated_at=NOW() WHERE id=? AND shop_id=?")
                ->execute([$name, $primary_unit, $sale_price, $purchase_price, $tax_included, $opening_stock, $low_stock_alert, $opening_stock, $hsn_code, $gst_percent, $barcode, $description, $mfg_date, $exp_date, $product_category_str, $product_id_post, $shop_id]);

            if (!empty($_FILES['photos']['name'][0])) {
                $pdo->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$product_id_post]);
                foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['photos']['error'][$key] === 0 && $key < 5) {
                        $proc = groceries_process_image($tmp_name, $product_id_post, $shop_id);
                        if ($key === 0) {
                            $pdo->prepare("UPDATE inventory_products SET photo=?, image_hero_path=?, image_thumb_path=?, image_tiny_base64=? WHERE id=?")
                                ->execute([basename($proc['hero']), $proc['hero'], $proc['thumb'], $proc['tiny'], $product_id_post]);
                        } else {
                            $pdo->prepare("INSERT INTO product_images (product_id, image_hero_path, image_thumb_path) VALUES (?,?,?)")
                                ->execute([$product_id_post, $proc['hero'], $proc['thumb']]);
                        }
                    }
                }
            }

            if (isset($_POST['remove_photo'])) {
                if ($curr_prod['photo'] && file_exists('../assets/img/products/' . $curr_prod['photo'])) {
                    unlink('../assets/img/products/' . $curr_prod['photo']);
                }
                $pdo->prepare("UPDATE inventory_products SET photo=NULL, image_hero_path=NULL, image_thumb_path=NULL, image_tiny_base64=NULL WHERE id=?")->execute([$product_id_post]);
            }

            groceries_update_cache($pdo, $product_id_post);
            $pdo->commit();
            checkInventoryAlert($pdo, $shop_id, $product_id_post);
        } else {
            // INSERT
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO inventory_products (shop_id, name, primary_unit, sale_price, purchase_price, tax_included, opening_stock, low_stock_alert, current_stock, hsn_code, gst_percent, barcode, description, mfg_date, exp_date, product_category) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$shop_id, $name, $primary_unit, $sale_price, $purchase_price, $tax_included, $opening_stock, $low_stock_alert, $opening_stock, $hsn_code, $gst_percent, $barcode, $description, $mfg_date, $exp_date, $product_category_str]);
            $new_product_id = $pdo->lastInsertId();

            if (!empty($_FILES['photos']['name'][0])) {
                foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['photos']['error'][$key] === 0 && $key < 5) {
                        $proc = groceries_process_image($tmp_name, $new_product_id, $shop_id);
                        if ($key === 0) {
                            $pdo->prepare("UPDATE inventory_products SET photo=?, image_hero_path=?, image_thumb_path=?, image_tiny_base64=? WHERE id=?")
                                ->execute([basename($proc['hero']), $proc['hero'], $proc['thumb'], $proc['tiny'], $new_product_id]);
                        } else {
                            $pdo->prepare("INSERT INTO product_images (product_id, image_hero_path, image_thumb_path) VALUES (?,?,?)")
                                ->execute([$new_product_id, $proc['hero'], $proc['thumb']]);
                        }
                    }
                }
            }

            groceries_update_cache($pdo, $new_product_id);
            $pdo->commit();
            checkInventoryAlert($pdo, $shop_id, $new_product_id);
        }

        header("Location: inventory.php" . ($product_id_post > 0 ? "?view=" . $product_id_post : ""));
        exit();
    }
}

// ── Search & List ───────────────────────────────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$query  = "SELECT * FROM inventory_products WHERE shop_id = ?";
$params = [$shop_id];
if ($search) {
    $query .= " AND (name LIKE ? OR hsn_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$query .= " ORDER BY created_at DESC";
$stmt_list = $pdo->prepare($query);
$stmt_list->execute($params);
$products = $stmt_list->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $view_mode ? 'Details' : ($edit_mode ? 'Edit' : 'Add') ?> Product — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        #unitModal.flex { display: flex !important; }
        .ocr-loading { display: none; }
        .ocr-loading.flex { display: flex !important; }
        /* Category grid scrollable */
        #catGrid { max-height: 260px; overflow-y: auto; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<!-- AI Scanner Loading -->
<div class="fixed inset-0 z-[2000] bg-white/90 backdrop-blur-sm flex-col items-center justify-center gap-4 ocr-loading" id="ocrLoading">
    <div class="w-12 h-12 border-4 border-slate-200 border-t-blue-600 rounded-full animate-spin"></div>
    <div class="font-black text-slate-900 uppercase tracking-widest text-xs">AI is reading bill items...</div>
    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-[0.2em]" id="ocrStatus">Initializing...</div>
</div>

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

    <div class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">

        <?php if (isset($_GET['success'])): ?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle me-1"></i> <?= htmlspecialchars($_GET['success']) ?>
            </div>
        <?php endif; ?>

<?php if (!isset($_GET['add']) && !$edit_mode && !$view_mode): ?>
    <!-- ═══════════════ LIST VIEW ═══════════════ -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
        <div>
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">My Inventory</h1>
            <p class="text-slate-500 text-sm">Real-time stock tracking across POS, Udhar, Monthly &amp; Bonds.</p>
        </div>
        <div class="bg-blue-50 border border-blue-100 px-4 py-2 rounded-xl hidden md:block">
            <div class="text-[9px] font-black text-blue-600 uppercase tracking-widest">Automation Active</div>
            <div class="text-[11px] font-bold text-slate-600">Stock deducts on every ledger entry.</div>
        </div>
        <div class="flex gap-2">
            <a href="generate_barcodes.php" target="_blank" class="bg-indigo-50 text-indigo-600 font-black px-6 py-3 rounded-2xl hover:bg-indigo-100 transition-all flex items-center gap-2 uppercase tracking-widest text-[10px]">
                <i class="fas fa-barcode"></i> Print Barcodes
            </a>
            <a href="?add=1" class="bg-slate-900 text-white font-black px-6 py-3 rounded-2xl hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 flex items-center gap-2 uppercase tracking-widest text-[10px]">
                <i class="fas fa-plus me-1"></i> Add New Item
            </a>
        </div>
    </div>

    <!-- Search Bar -->
    <form method="GET" class="bg-white border border-slate-200 rounded-3xl p-4 mb-8 flex gap-3 shadow-sm">
        <div class="flex-1">
            <input type="text" name="search" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm focus:bg-white focus:border-blue-500 outline-none transition-all"
                placeholder="Search by product name or HSN code..."
                value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit" class="bg-slate-900 text-white font-bold px-6 py-3 rounded-2xl hover:bg-blue-600 transition-all flex items-center gap-2 uppercase tracking-widest text-[10px]">
            <i class="fas fa-search"></i>
        </button>
        <?php if ($search): ?>
            <a href="inventory.php" class="bg-slate-100 text-slate-600 font-bold px-4 py-3 rounded-2xl flex items-center uppercase tracking-widest text-[10px]">Reset</a>
        <?php endif; ?>
    </form>

    <div class="grid grid-cols-1 gap-3">
        <?php if (count($products) > 0): ?>
            <?php foreach ($products as $p): ?>
                <div class="bg-white border border-slate-200 rounded-2xl p-4 flex items-center gap-4 hover:border-blue-500 hover:shadow-lg transition-all shadow-sm group">
                    <a href="?view=<?= $p['id'] ?>" class="flex items-center flex-1 gap-4 min-w-0">
                        <?php
                        $thumb_img = 'https://ui-avatars.com/api/?name=' . urlencode($p['name']) . '&background=random&color=2563eb&bold=true';
                        if (!empty($p['image_thumb_path'])) {
                            $thumb_img = '../' . $p['image_thumb_path'];
                        } elseif (!empty($p['photo'])) {
                            $thumb_img = '../assets/img/products/' . $p['photo'];
                        }
                        ?>
                        <img src="<?= $thumb_img ?>" class="w-12 h-12 rounded-xl object-cover border border-slate-100 group-hover:scale-105 transition-transform">
                        <div class="min-w-0">
                            <div class="text-sm font-black text-slate-900 truncate"><?= htmlspecialchars($p['name']) ?></div>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?= $p['primary_unit'] ?></span>
                                <span class="w-1 h-1 rounded-full bg-slate-200"></span>
                                <span class="text-[10px] font-black px-2 py-0.5 rounded-md uppercase tracking-widest <?= $p['current_stock'] <= $p['low_stock_alert'] ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600' ?>">
                                    <?= (float)$p['current_stock'] ?> Left
                                </span>
                            </div>
                        </div>
                    </a>
                    <div class="flex items-center gap-4">
                        <div class="text-right hidden sm:block">
                            <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Rate</div>
                            <div class="text-sm font-black text-slate-900 tracking-tight">₹<?= number_format($p['sale_price'], 2) ?></div>
                        </div>
                        <div class="flex items-center gap-1 border-l border-slate-100 pl-4">
                            <a href="export_product.php?id=<?= $p['id'] ?>" target="_blank" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-all" title="Download PDF">
                                <i class="fas fa-file-pdf"></i>
                            </a>
                            <a href="?delete_id=<?= $p['id'] ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-all" title="Delete Product"
                               onclick="return confirm('Are you sure? This will permanently delete the product.')">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php if (!empty($p['barcode'])): ?>
                            <button type="button" onclick="promptBarcodeQuantity(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($p['barcode'], ENT_QUOTES) ?>')"
                                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all" title="Print Barcodes">
                                <i class="fas fa-barcode"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center py-20 bg-white border border-slate-200 rounded-[2.5rem] shadow-sm">
                <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-6">
                    <i class="fas fa-boxes-stacked"></i>
                </div>
                <h3 class="font-black text-slate-900 uppercase tracking-widest text-xs mb-2">No Items Found</h3>
                <p class="text-slate-400 text-xs font-medium"><?= $search ? 'No products match your search query.' : 'Your inventory database is currently empty.' ?></p>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($view_mode): ?>
    <!-- ═══════════════ VIEW MODE ═══════════════ -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
        <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">Product Details</h1>
        <a href="inventory.php" class="bg-slate-900 text-white font-black px-6 py-3 rounded-2xl hover:bg-blue-600 transition-all shadow-lg flex items-center gap-2 uppercase tracking-widest text-[10px]">
            <i class="fas fa-arrow-left me-1"></i> Back to Inventory
        </a>
    </div>

    <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-10 shadow-sm">
        <div class="flex flex-col items-center text-center pb-8 border-b border-slate-100 mb-8">
            <?php
            $hero_img = 'https://ui-avatars.com/api/?name=' . urlencode($product_data['name']) . '&background=random&color=2563eb&bold=true';
            if (!empty($product_data['image_hero_path'])) {
                $hero_img = '../' . $product_data['image_hero_path'];
            } elseif (!empty($product_data['photo'])) {
                $hero_img = '../assets/img/products/' . $product_data['photo'];
            }
            ?>
            <img src="<?= $hero_img ?>" class="w-24 h-24 rounded-3xl shadow-2xl shadow-slate-200 object-cover mb-6">
            <h2 class="text-2xl font-black text-slate-900 tracking-tight mb-2"><?= htmlspecialchars($product_data['name']) ?></h2>
            <div class="text-[10px] font-black px-4 py-1.5 rounded-full uppercase tracking-widest shadow-sm <?= $product_data['current_stock'] <= $product_data['low_stock_alert'] ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600' ?>">
                Current Stock: <?= (float)$product_data['current_stock'] ?> <?= $product_data['primary_unit'] ?>
            </div>
            <?php if (!empty($product_data['barcode'])): ?>
            <div class="mt-4">
                <svg id="barcodeDisplayView"></svg>
            </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
            <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Pricing Specs</div>
                <div class="flex justify-between items-center mb-3">
                    <span class="text-sm text-slate-500 font-medium">Sale Price</span>
                    <span class="text-sm font-black text-emerald-600">₹<?= number_format($product_data['sale_price'], 2) ?></span>
                </div>
                <div class="flex justify-between items-center mb-3">
                    <span class="text-sm text-slate-500 font-medium">Purchase Price</span>
                    <span class="text-sm font-black text-red-600">₹<?= number_format($product_data['purchase_price'], 2) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-slate-500 font-medium">Tax Status</span>
                    <span class="text-[10px] font-black uppercase text-slate-700"><?= $product_data['tax_included'] ? 'Included' : 'Excluded' ?></span>
                </div>
            </div>
            <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Inventory Data</div>
                <div class="flex justify-between items-center mb-3">
                    <span class="text-sm text-slate-500 font-medium">Alert Level</span>
                    <span class="text-sm font-black text-slate-900"><?= (float)$product_data['low_stock_alert'] ?> <?= $product_data['primary_unit'] ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-slate-500 font-medium">HSN Code</span>
                    <span class="text-sm font-black text-slate-900"><?= htmlspecialchars($product_data['hsn_code'] ?: '—') ?></span>
                </div>
            </div>
            <div class="col-span-1 md:col-span-2 bg-blue-50 p-6 rounded-3xl border border-blue-100">
                <div class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-4">Mall Metadata</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] text-slate-400 uppercase">Manufacturing Date</label>
                        <div class="text-sm font-bold"><?= $product_data['mfg_date'] ?: 'N/A' ?></div>
                    </div>
                    <div>
                        <label class="block text-[9px] text-slate-400 uppercase">Expiry Date</label>
                        <div class="text-sm font-bold <?= (!empty($product_data['exp_date']) && strtotime($product_data['exp_date']) < time()) ? 'text-red-600' : '' ?>">
                            <?= $product_data['exp_date'] ?: 'N/A' ?>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <label class="block text-[9px] text-slate-400 uppercase mb-1">Product Description</label>
                    <div class="text-sm text-slate-700 leading-relaxed italic"><?= nl2br(htmlspecialchars($product_data['description'] ?: 'No description provided.')) ?></div>
                </div>
            </div>
        </div>

        <a href="?edit=<?= $product_id ?>" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all shadow-lg text-center flex items-center justify-center gap-2 uppercase tracking-widest text-[10px]">
            <i class="fas fa-edit me-1"></i> EDIT THIS ITEM
        </a>
    </div>

<?php elseif ($edit_mode || isset($_GET['add'])): ?>
    <!-- ═══════════════ ADD / EDIT FORM ═══════════════ -->
    <div class="mb-8">
        <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900"><?= $edit_mode ? 'Edit' : 'Add' ?> Product</h1>
        <p class="text-slate-500 text-sm">Fill in the specifications below to update your inventory.</p>
    </div>

    <form method="POST" enctype="multipart/form-data" class="space-y-6">

        <!-- AI Scanner Button -->
        <label class="w-full bg-indigo-50 border-2 border-dashed border-indigo-200 text-indigo-600 rounded-3xl p-6 flex flex-col items-center justify-center gap-2 cursor-pointer hover:bg-indigo-100 hover:border-indigo-300 transition-all">
            <i class="fas fa-wand-magic-sparkles text-xl"></i>
            <span class="text-xs font-black uppercase tracking-widest">Scan Pack to Auto-Fill Specs</span>
            <input type="file" id="ocrScanner" accept="image/*" capture="environment" class="hidden" onchange="handleInventoryOCR(this)">
        </label>

        <?php if ($edit_mode): ?><input type="hidden" name="product_id" value="<?= $product_id ?>"><?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-medium">
                <i class="fas fa-exclamation-circle me-1"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- General Info Card -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-10 shadow-sm space-y-6">

            <!-- Barcode -->
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Barcode / SKU</label>
                <div class="flex gap-2">
                    <input type="text" name="barcode" id="barcodeInput"
                           class="flex-1 bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all"
                           placeholder="Scan or Generate Barcode"
                           value="<?= htmlspecialchars($product_data['barcode'] ?? '') ?>"
                           oninput="updateBarcodePreview()">
                    <button type="button" onclick="generateRandomBarcode()" class="bg-slate-100 text-slate-600 px-4 rounded-2xl hover:bg-slate-200" title="Auto-Generate"><i class="fas fa-magic"></i></button>
                    <button type="button" onclick="startBarcodeScanner('barcodeInput')" class="bg-blue-600 text-white px-4 rounded-2xl hover:bg-blue-700" title="Scan from Camera"><i class="fas fa-camera"></i></button>
                </div>
                <div id="barcodePreviewContainer" class="mt-4 <?= !empty($product_data['barcode']) ? '' : 'hidden' ?>">
                    <svg id="barcodeDisplay"></svg>
                </div>
            </div>

            <!-- Product Name -->
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Item Identity</label>
                <input type="text" name="name"
                       class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all"
                       placeholder="Enter product name (e.g. Milk 500ml)"
                       value="<?= htmlspecialchars($product_data['name'] ?? $_POST['name'] ?? '') ?>" required>
            </div>

            <!-- Stock Unit -->
            <div class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border border-slate-100">
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Stock Unit</div>
                    <div class="text-sm font-black text-slate-900" id="selectedUnitText"><?= htmlspecialchars($product_data['primary_unit'] ?? 'NOS') ?></div>
                </div>
                <button type="button" class="bg-white border border-slate-200 text-slate-600 text-[10px] font-black px-4 py-2 rounded-xl uppercase tracking-widest hover:bg-slate-100 transition-all" onclick="openUnitModal()">Change</button>
                <input type="hidden" name="primary_unit" id="primaryUnitInput" value="<?= htmlspecialchars($product_data['primary_unit'] ?? 'NOS') ?>">
            </div>

            <!-- ── Categories ── -->
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Product Categories (Multiple)</label>
                <!-- Search filter for categories -->
                <input type="text" id="catSearch"
                       class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-2 text-sm mb-3 focus:border-blue-500 outline-none"
                       placeholder="Filter categories..." oninput="filterMallCategories(this.value)">

                <div id="catGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 bg-slate-50 p-4 rounded-2xl border-2 border-slate-100">
                    <?php
                    $selected_cats = explode(',', $product_data['product_category'] ?? 'Other');
                    foreach ($mall_cats as $cat): ?>
                        <label class="cat-item flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="product_category[]" value="<?= htmlspecialchars($cat) ?>"
                                   <?= in_array($cat, $selected_cats) ? 'checked' : '' ?>
                                   class="rounded text-blue-600 focus:ring-blue-500 border-slate-300">
                            <span class="text-xs font-bold text-slate-600 group-hover:text-blue-600"><?= htmlspecialchars($cat) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="mt-1 text-[8px] text-slate-400 italic">Isi category ke hisab se customer ko mall mein saaman dikhega.</p>
            </div>

            <!-- MFG / EXP Dates -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">MFG Date</label>
                    <input type="date" name="mfg_date" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-blue-500 transition-all" value="<?= $product_data['mfg_date'] ?? '' ?>">
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">EXP Date</label>
                    <input type="date" name="exp_date" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-blue-500 transition-all" value="<?= $product_data['exp_date'] ?? '' ?>">
                </div>
            </div>

            <!-- Description -->
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Detailed Description</label>
                <textarea name="description" rows="3"
                          class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-medium focus:bg-white focus:border-blue-500 outline-none transition-all"
                          placeholder="Tell customers more about this product..."><?= htmlspecialchars($product_data['description'] ?? '') ?></textarea>
            </div>

            <!-- Photos -->
            <div class="flex flex-col gap-4">
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Product Photos (Max 5)</label>
                <label class="relative group cursor-pointer">
                    <div class="w-full h-32 bg-slate-100 border-2 border-dashed border-slate-200 rounded-[2rem] flex flex-col items-center justify-center gap-2 group-hover:bg-slate-200 transition-all">
                        <i class="fas fa-images text-2xl text-slate-400"></i>
                        <span class="text-[9px] font-black uppercase text-slate-400">Select multiple photos</span>
                    </div>
                    <input type="file" name="photos[]" accept="image/*" multiple class="hidden" onchange="previewMultiplePhotos(this)">
                </label>
                <div id="multiPhotoPreview" class="grid grid-cols-5 gap-2"></div>
            </div>
        </div>

        <!-- Pricing & Inventory Card -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-10 shadow-sm grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="space-y-6">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] pb-2 border-b border-slate-50">Pricing Details</div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Sale Price (₹)</label>
                        <input type="number" name="sale_price" step="0.01"
                               class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-black text-emerald-600 focus:bg-white focus:border-emerald-500 outline-none transition-all"
                               value="<?= htmlspecialchars($product_data['sale_price'] ?? $_POST['sale_price'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Purchase (₹)</label>
                        <input type="number" name="purchase_price" step="0.01"
                               class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-black text-slate-600 focus:bg-white focus:border-slate-500 outline-none transition-all"
                               value="<?= htmlspecialchars($product_data['purchase_price'] ?? $_POST['purchase_price'] ?? '') ?>">
                    </div>
                </div>
                <div class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border border-slate-100">
                    <span class="text-[10px] font-black text-slate-900 uppercase tracking-widest">Tax Included in price?</span>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="tax_included" class="sr-only peer" <?= (isset($product_data['tax_included']) && $product_data['tax_included'] == 0) ? '' : 'checked' ?>>
                        <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>
            </div>
            <div class="space-y-6">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] pb-2 border-b border-slate-50">Stock Control</div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Opening Stock</label>
                        <input type="number" name="opening_stock" step="0.01"
                               class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all"
                               value="<?= htmlspecialchars($product_data['opening_stock'] ?? $_POST['opening_stock'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Low Stock Alert</label>
                        <input type="number" name="low_stock_alert" step="0.01"
                               class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold text-red-600 focus:bg-white focus:border-red-500 outline-none transition-all"
                               value="<?= htmlspecialchars($product_data['low_stock_alert'] ?? $_POST['low_stock_alert'] ?? '') ?>">
                    </div>
                </div>
                <div class="flex items-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-2">
                    <i class="fas fa-calendar-alt"></i> Stock as of Today
                </div>
            </div>
        </div>

        <!-- HSN & Tax Card -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-10 shadow-sm">
            <button type="button" class="w-full text-left text-[10px] font-black text-blue-600 uppercase tracking-[0.2em] flex items-center justify-between group" onclick="toggleGST()">
                <span>HSN & Tax Configuration (Optional)</span>
                <i class="fas fa-chevron-down transition-transform duration-300" id="gstChevron"></i>
            </button>
            <div class="hidden pt-8 space-y-6" id="gstSection">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">HSN Code</label>
                        <input type="text" name="hsn_code"
                               class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all"
                               value="<?= htmlspecialchars($product_data['hsn_code'] ?? $_POST['hsn_code'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">GST Percentage</label>
                        <select name="gst_percent" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none cursor-pointer">
                            <?php
                            $current_gst = (float)($product_data['gst_percent'] ?? $_POST['gst_percent'] ?? 0);
                            foreach ([0, 0.25, 1, 1.5, 3, 5, 7.5, 12, 18, 28] as $opt): ?>
                                <option value="<?= $opt ?>" <?= $current_gst == $opt ? 'selected' : '' ?>><?= $opt ?>%</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all shadow-lg flex items-center justify-center gap-2 uppercase tracking-widest text-xs">
            <i class="fas fa-save"></i> <?= $edit_mode ? 'Commit Updates' : 'Publish Product' ?>
        </button>
    </form>
<?php endif; ?>
    </div>
</div>

<!-- Unit Selection Modal -->
<div class="fixed inset-0 z-[2000] hidden items-end md:items-center justify-center p-0 md:p-4 bg-slate-900/60 backdrop-blur-sm" id="unitModal">
    <div class="bg-white w-full max-w-lg rounded-t-[2.5rem] md:rounded-[2.5rem] flex flex-col max-h-[85vh] shadow-2xl animate-[slideUp_0.3s_ease]">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-lg font-black text-slate-900 uppercase tracking-tight">Standard Metric Units</h3>
            <button class="w-8 h-8 flex items-center justify-center bg-slate-100 rounded-full text-slate-500" onclick="closeUnitModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-4 bg-slate-50">
            <input type="text" id="unitSearch" class="w-full bg-white border-2 border-slate-200 rounded-2xl px-5 py-3 text-sm focus:border-blue-500 outline-none" placeholder="Filter units (e.g. KG, PCS)..." oninput="filterUnits(this.value)">
        </div>
        <div class="flex-1 overflow-y-auto divide-y divide-slate-50" id="unitList">
            <?php foreach ($units as $u): ?>
            <div class="px-6 py-4 flex items-center justify-between cursor-pointer hover:bg-blue-50/50 transition-colors group" data-code="<?= $u[0] ?>" data-name="<?= htmlspecialchars($u[1]) ?>" onclick="selectUnit('<?= $u[0] ?>', '<?= addslashes($u[1]) ?>')">
                <span class="text-sm font-bold text-slate-700 group-hover:text-blue-600"><?= htmlspecialchars($u[1]) ?></span>
                <div class="w-5 h-5 rounded-full border-2 border-slate-200 flex items-center justify-center group-[.selected]:bg-blue-600 group-[.selected]:border-blue-600 transition-all"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="p-6 border-t border-slate-100">
            <button type="button" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl uppercase tracking-widest" onclick="confirmUnit()">Confirm Selection</button>
        </div>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div id="scannerModal" class="fixed inset-0 z-[3000] hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-md">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] overflow-hidden shadow-2xl">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-black uppercase tracking-widest text-xs">Scan Barcode</h3>
            <button onclick="stopBarcodeScanner()" class="text-slate-400"><i class="fas fa-times"></i></button>
        </div>
        <div id="reader" class="w-full h-64 bg-black"></div>
        <div class="p-6 text-center">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Place barcode within the frame</p>
        </div>
    </div>
</div>

<script>
// ── Init on load ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const barcodeInput = document.getElementById('barcodeInput');
    if (barcodeInput?.value) {
        JsBarcode("#barcodeDisplay", barcodeInput.value, { format: "CODE128", height: 40, fontSize: 12 });
    }
    const viewBarcode = "<?= addslashes($product_data['barcode'] ?? '') ?>";
    if (document.getElementById('barcodeDisplayView') && viewBarcode) {
        JsBarcode("#barcodeDisplayView", viewBarcode, { height: 40, fontSize: 12 });
    }
});

// ── Barcode ───────────────────────────────────────────────────────────────
function generateRandomBarcode() {
    const code = 'KL' + Math.floor(Math.random() * 9000000000 + 1000000000);
    document.getElementById('barcodeInput').value = code;
    document.getElementById('barcodePreviewContainer').classList.remove('hidden');
    JsBarcode("#barcodeDisplay", code, { format: "CODE128", height: 40, fontSize: 12 });
}

function updateBarcodePreview() {
    const val = document.getElementById('barcodeInput').value;
    const container = document.getElementById('barcodePreviewContainer');
    if (val) {
        container.classList.remove('hidden');
        JsBarcode("#barcodeDisplay", val, { format: "CODE128", height: 40, fontSize: 12 });
    } else {
        container.classList.add('hidden');
    }
}

let html5QrCode = null;
async function startBarcodeScanner(targetId) {
    if (html5QrCode) await stopBarcodeScanner();
    document.getElementById('scannerModal').classList.replace('hidden', 'flex');
    html5QrCode = new Html5Qrcode("reader");
    const config = { fps: 10, qrbox: { width: 250, height: 150 } };
    const onScan = (decodedText) => {
        document.getElementById(targetId).value = decodedText;
        if (targetId === 'barcodeInput') {
            document.getElementById('barcodePreviewContainer').classList.remove('hidden');
            JsBarcode("#barcodeDisplay", decodedText, { format: "CODE128", height: 40, fontSize: 12 });
        }
        stopBarcodeScanner();
    };
    html5QrCode.start({ facingMode: "environment" }, config, onScan)
        .catch(() => html5QrCode.start({ facingMode: "user" }, config, onScan));
}

function stopBarcodeScanner() {
    if (html5QrCode) {
        html5QrCode.stop().then(() => {
            document.getElementById('scannerModal').classList.replace('flex', 'hidden');
        });
    } else {
        document.getElementById('scannerModal').classList.replace('flex', 'hidden');
    }
}

// ── Photo Preview ─────────────────────────────────────────────────────────
function previewMultiplePhotos(input) {
    const container = document.getElementById('multiPhotoPreview');
    container.innerHTML = '';
    Array.from(input.files).slice(0, 5).forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'w-full aspect-square object-cover rounded-xl border border-slate-200';
            container.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
}

// ── Category Filter ───────────────────────────────────────────────────────
function filterMallCategories(val) {
    const q = val.toLowerCase();
    document.querySelectorAll('.cat-item').forEach(item => {
        item.style.display = item.innerText.toLowerCase().includes(q) ? 'flex' : 'none';
    });
}

// ── Unit Modal ────────────────────────────────────────────────────────────
let selectedUnit = { code: 'NOS', name: 'Numbers' };
function openUnitModal()  { document.getElementById('unitModal').classList.replace('hidden', 'flex'); document.getElementById('unitSearch').value = ''; filterUnits(''); }
function closeUnitModal() { document.getElementById('unitModal').classList.replace('flex', 'hidden'); }
function selectUnit(code, name) {
    selectedUnit = { code, name };
    document.querySelectorAll('#unitList div').forEach(el => el.classList.remove('selected'));
    document.querySelector(`[data-code="${code}"]`)?.classList.add('selected');
}
function confirmUnit() {
    document.getElementById('selectedUnitText').innerText = selectedUnit.code;
    document.getElementById('primaryUnitInput').value = selectedUnit.code;
    closeUnitModal();
}
function filterUnits(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('#unitList div[data-name]').forEach(item => {
        item.classList.toggle('hidden', !item.dataset.name.toLowerCase().includes(q));
    });
}
document.getElementById('unitModal').addEventListener('click', function(e) {
    if (e.target === this) closeUnitModal();
});

// ── GST Toggle ────────────────────────────────────────────────────────────
function toggleGST() {
    const sec     = document.getElementById('gstSection');
    const chevron = document.getElementById('gstChevron');
    sec.classList.toggle('hidden');
    chevron.style.transform = sec.classList.contains('hidden') ? 'rotate(0deg)' : 'rotate(180deg)';
}

// ── OCR ───────────────────────────────────────────────────────────────────
async function handleInventoryOCR(input) {
    const file = input.files[0];
    if (!file) return;
    const loading = document.getElementById('ocrLoading');
    const status  = document.getElementById('ocrStatus');
    loading.style.display = 'flex';
    try {
        const { data: { text } } = await Tesseract.recognize(file, 'eng', {
            logger: m => { if (m.status === 'recognizing text') status.innerText = `Reading: ${Math.round(m.progress * 100)}%`; }
        });
        loading.style.display = 'none';
        processScannedText(text);
    } catch (err) {
        console.error("OCR Error:", err);
        loading.style.display = 'none';
    }
}

function processScannedText(text) {
    const junkWords = ['total', 'bill', 'tax', 'gst', 'cash', 'date', 'invoice', 'amount', 'summary', 'subtotal'];
    for (let line of text.split('\n').map(l => l.trim()).filter(l => l.length > 3)) {
        const cleanLine  = line.replace(/[₹Rs:]/gi, '').trim();
        const numbers    = cleanLine.match(/\d+(\.\d+)?/g);
        const nameMatch  = cleanLine.match(/[\p{L}\s]{3,}/u);
        if (nameMatch && numbers) {
            const itemName = nameMatch[0].trim();
            if (junkWords.some(j => itemName.toLowerCase().includes(j))) continue;
            document.querySelector('input[name="name"]').value       = itemName;
            document.querySelector('input[name="sale_price"]').value = numbers[numbers.length - 1];
            document.querySelector('input[name="name"]').focus();
            break;
        }
    }
}

// ── Barcode print prompt ──────────────────────────────────────────────────
function promptBarcodeQuantity(productId, productName, barcode) {
    Swal.fire({
        title: `Print Barcodes for ${productName}`,
        input: 'number',
        inputValue: 1,
        inputLabel: 'How many stickers to print?',
        inputPlaceholder: 'Enter quantity',
        showCancelButton: true,
        confirmButtonText: 'Generate PDF',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => { if (!value || parseInt(value) <= 0) return 'Please enter a valid quantity!'; }
    }).then(result => {
        if (result.isConfirmed) window.open(`generate_barcodes.php?product_id=${productId}&quantity=${result.value}`, '_blank');
    });
}

// ── Sidebar ───────────────────────────────────────────────────────────────
function openSidebar()  { document.getElementById('sidebar').classList.add('open');    document.getElementById('overlay').classList.add('show'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.remove('show'); }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>