<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');

// ===== AUTHENTICATION LAYER (App & Web) =====
$customer_id = 0;
$is_api = false;
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    header('Content-Type: application/json');
    $token      = get_auth_token();
    $parts      = verify_secure_token($token);
    $customer_id = (int)($parts[0] ?? 0);
} else {
    $customer_id = $_SESSION['customer_id'] ?? 0;
}

if (!$customer_id) {
    if ($is_api) exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
    header("Location: ../auth/login.php?type=customer"); exit();
}

// ===== FETCH PRODUCT DETAILS =====
$product_id = (int)($_GET['id']      ?? 0);
$shop_id    = (int)($_GET['shop_id'] ?? 0);

if ($product_id === 0 || $shop_id === 0) {
    if ($is_api) exit(json_encode(['success' => false, 'message' => 'Product or Shop ID missing.']));
    header("Location: Groceries_home.php"); exit();
}

// Fetch product from cache
$stmt_product = $pdo->prepare("SELECT * FROM Groceries_product_marketplace_cache WHERE product_id = ? AND shop_id = ?");
$stmt_product->execute([$product_id, $shop_id]);
$product = $stmt_product->fetch(PDO::FETCH_ASSOC);

// Fetch Extra Images for Slider
$stmt_imgs = $pdo->prepare("SELECT image_hero_path FROM product_images WHERE product_id = ?");
$stmt_imgs->execute([$product_id]);
$extra_images = $stmt_imgs->fetchAll(PDO::FETCH_COLUMN);
$all_images = array_merge([$product['image_hero_path'] ?: $product['image_thumb_path']], $extra_images);

if (!$product) {
    if ($is_api) exit(json_encode(['success' => false, 'message' => 'Product not found in marketplace.']));
    header("Location: Groceries_home.php?error=Product+not+found."); exit();
}

// Fetch shop details
$stmt_shop = $pdo->prepare("SELECT shop_name, full_address, pincode, average_rating, total_ratings_count, is_online, open_time, close_time, override_until FROM shop_owners WHERE id = ?");
$stmt_shop->execute([$shop_id]);
$shop = $stmt_shop->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
    if ($is_api) exit(json_encode(['success' => false, 'message' => 'Shop not found.']));
    header("Location: Groceries_home.php?error=Shop+not+found."); exit();
}

// ── Logic: Is Shop Currently Open? ────────────────────────────────────
$is_shop_open = ($shop['is_online'] == 1 && ((date('H:i:s') >= $shop['open_time'] && date('H:i:s') <= $shop['close_time']) || ($shop['override_until'] && strtotime($shop['override_until']) > time())));

// ── Extract Primary Category & Fetch Similar Products ──────────────────
$cat_list    = explode(',', $product['product_category'] ?? '');
$primary_cat = trim($cat_list[0] ?? '');

if ($primary_cat && $primary_cat !== 'Other') {
    $stmt_sim = $pdo->prepare("SELECT * FROM Groceries_product_marketplace_cache WHERE (product_category LIKE ?) AND product_id != ? AND current_stock > 0 LIMIT 10");
    $stmt_sim->execute(['%' . $primary_cat . '%', $product_id]);
} else {
    // Fallback: Agar category nahi hai, toh same shop ke doosre items dikhao
    $stmt_sim = $pdo->prepare("SELECT * FROM Groceries_product_marketplace_cache WHERE shop_id = ? AND product_id != ? AND current_stock > 0 LIMIT 10");
    $stmt_sim->execute([$shop_id, $product_id]);
}
$similar_products = $stmt_sim->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch Product Specific Reviews ────────────────────────────────────
$stmt_rev = $pdo->prepare("
    SELECT sr.rating, sr.comment, sr.created_at, c.name as customer_name
    FROM shop_ratings sr
    JOIN customers c ON sr.customer_id = c.id
    WHERE sr.order_id IN (SELECT order_id FROM order_items WHERE product_id = ?)
    ORDER BY sr.created_at DESC
");
$stmt_rev->execute([$product_id]);
$reviews = $stmt_rev->fetchAll(PDO::FETCH_ASSOC);

// For API requests, return JSON (with similar products included)
if ($is_api) {
    exit(json_encode([
        'success'  => true,
        'product'  => $product,
        'shop'     => $shop,
        'similar'  => $similar_products,
        'reviews'  => $reviews
    ]));
}

// ── Helper: build a safe root-relative URL for images ──────────────────
// DB stores paths like "uploads/products/abc.jpg" (no leading slash).
// Adjust APP_ROOT_URL to match your actual XAMPP vhost / subfolder.
define('APP_ROOT_URL', '/khatalink/');   // ← change if needed

function asset_url(string $path): string {
    $path = ltrim($path, '/');
    return APP_ROOT_URL . $path;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?> — KhataLink Groceries</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <!-- Swiper.js CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
    <style>
        body { font-family: 'Inter', sans-serif; }
        #toast {
            position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%) translateY(20px);
            background: #1e293b; color: #fff;
            padding: .6rem 1.4rem; border-radius: 9999px;
            font-size: .85rem; font-weight: 700;
            opacity: 0; pointer-events: none;
            transition: opacity .3s ease, transform .3s ease;
            white-space: nowrap; z-index: 2000;
        }
        #toast.show {
            opacity: 1; transform: translateX(-50%) translateY(0);
        }
        /* Custom Swiper Styles */
        .reviews-swiper { padding-bottom: 40px !important; }
        .swiper-pagination-bullet-active { background: #059669 !important; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<!-- Toast -->
<div id="toast"></div>

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 py-3 px-4 shadow-sm">
    <div class="max-w-5xl mx-auto flex items-center justify-between gap-4">
        <a href="Groceries_home.php" class="text-slate-400 hover:text-emerald-600 transition-all">
            <i class="fas fa-chevron-left text-lg"></i>
        </a>
        <h1 class="text-lg font-black text-slate-900 truncate flex-1 text-center">
            <?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <a href="Groceries_cart.php" class="relative w-10 h-10 bg-emerald-600 text-white rounded-xl flex items-center justify-center shadow-lg shadow-emerald-100 transition-all hover:scale-105">
            <i class="fas fa-shopping-cart text-sm"></i>
            <span id="cartBadge" class="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[8px] font-black w-5 h-5 rounded-full flex items-center justify-center border-2 border-white hidden">0</span>
        </a>
    </div>
</nav>

<main class="p-4 md:p-8 max-w-6xl mx-auto">

    <!-- PRODUCT HERO -->
    <div class="flex flex-col md:flex-row gap-8 mb-12">

        <!-- LEFT: Image & Toggle -->
        <div class="md:w-1/2">
            <div class="bg-white border border-slate-100 rounded-[2.5rem] p-4 mb-4 shadow-sm">
                <div class="aspect-square bg-slate-50 rounded-[2rem] overflow-hidden relative p-4">
                    <?php 
                        $display_hero = !empty($product['image_hero_path']) ? $product['image_hero_path'] : $product['image_thumb_path'];
                    ?>
                    <img src="<?= asset_url($display_hero ?? 'assets/img/products/placeholder.png') ?>"
                         class="w-full h-full object-contain hover:scale-105 transition-transform duration-700"
                         alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>">

                    <?php if ((int)$product['current_stock'] <= 0): ?>
                        <div class="absolute inset-0 flex items-center justify-center bg-slate-900/40">
                            <span class="bg-white text-red-600 text-sm font-black px-4 py-2 rounded-full uppercase">Out of Stock</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Show Details Toggle Button -->
            <button onclick="toggleDetails()" class="w-full bg-white border border-slate-200 text-slate-500 py-3 rounded-2xl font-black text-[10px] uppercase tracking-[0.2em] hover:bg-slate-50 transition-all flex items-center justify-center gap-2">
                <i id="detailIcon" class="fas fa-plus"></i>
                <span id="detailBtnText">Show Product Details</span>
            </button>
        </div>

        <!-- RIGHT: Core Info & Purchase -->
        <div class="md:w-1/2 flex flex-col justify-center">
            <div class="mb-2">
                <span class="text-[10px] font-black text-blue-500 bg-blue-50 px-3 py-1 rounded-full uppercase tracking-widest">
                    <?= htmlspecialchars($primary_cat) ?>
                </span>
            </div>

            <h2 class="text-3xl md:text-4xl font-black text-slate-900 leading-tight mb-2">
                <?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>
            </h2>

            <div class="flex flex-col mb-6">
                <p class="text-lg font-bold text-slate-400 uppercase tracking-tight">
                    <?= htmlspecialchars($product['primary_unit'], ENT_QUOTES, 'UTF-8') ?> Pack
                </p>
                <p class="text-xs font-bold text-slate-500 mt-1">
                    Sold by: <span class="text-blue-600"><?= htmlspecialchars($shop['shop_name']) ?></span>
                    <?php if($shop['average_rating'] > 0): ?>
                        <span class="text-amber-500 ml-2"><i class="fas fa-star me-1 text-[10px]"></i><?= number_format($shop['average_rating'], 1) ?></span>
                        <span class="text-[9px] text-slate-400">(<?= $shop['total_ratings_count'] ?>)</span>
                    <?php endif; ?>
                </p>
            </div>

            <?php if(count($reviews) > 0): ?>
                <?php 
                    $prod_avg = array_sum(array_column($reviews, 'rating')) / count($reviews);
                ?>
                <div class="flex items-center gap-2 mb-6 bg-amber-50 w-fit px-3 py-1 rounded-lg border border-amber-100">
                    <span class="text-amber-600 font-black text-xs"><?= number_format($prod_avg, 1) ?></span>
                    <div class="flex text-[8px] text-amber-400"><?= str_repeat('<i class="fas fa-star"></i>', round($prod_avg)) ?></div>
                    <span class="text-[9px] font-bold text-slate-400 uppercase tracking-tighter"><?= count($reviews) ?> Product Reviews</span>
                </div>
            <?php endif; ?>

            <div class="flex items-center gap-6 mb-8">
                <div class="flex flex-col">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Best Price</span>
                    <span class="text-4xl font-black text-emerald-600">₹<?= number_format((float)$product['sale_price'], 2) ?></span>
                </div>
                <div class="h-12 w-px bg-slate-100"></div>
                <div class="flex items-center bg-slate-100 rounded-2xl p-1.5">
                    <button onclick="changeQty(-1)" class="w-10 h-10 bg-white text-slate-900 rounded-xl flex items-center justify-center shadow-sm active:scale-90 transition-transform">
                        <i class="fas fa-minus text-xs"></i>
                    </button>
                    <span id="productQty" class="w-12 text-center font-black text-lg">1</span>
                    <button onclick="changeQty(1)" class="w-10 h-10 bg-white text-slate-900 rounded-xl flex items-center justify-center shadow-sm active:scale-90 transition-transform">
                        <i class="fas fa-plus text-xs"></i>
                    </button>
                </div>
            </div>

            <button id="addToCartBtn"
                    onclick="<?= $is_shop_open ? 'handleAddToCart()' : 'Swal.fire(\'Shop Closed\', \'Ye dukan abhi band hai. Kripya dukan khulne ka intezar karein.\', \'info\')' ?>"
                    class="w-full md:w-64 bg-emerald-600 text-white py-4 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl shadow-emerald-100 hover:bg-emerald-700 active:scale-95 transition-all disabled:opacity-50 disabled:grayscale"
                    <?= ((int)$product['current_stock'] <= 0 || !$is_shop_open) ? 'disabled' : '' ?>>
                <?php 
                    if ((int)$product['current_stock'] <= 0) echo 'Out of Stock';
                    elseif (!$is_shop_open) echo 'Shop Closed';
                    else echo 'Add to Cart';
                ?>
            </button>
            <?php if(!$is_shop_open): ?>
                <p class="mt-4 text-[10px] font-black text-red-500 uppercase tracking-widest bg-red-50 w-fit px-4 py-2 rounded-xl border border-red-100"><i class="fas fa-moon me-2"></i> Dukandaar abhi offline hai</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- WHY SHOP FROM KHATALINK? -->
    <div class="bg-white border border-slate-100 rounded-[2.5rem] p-8 shadow-sm mb-12">
        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6 text-center">Why Shop from KhataLink Groceries?</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="flex flex-col items-center text-center p-4 bg-emerald-50/50 rounded-2xl border border-emerald-100">
                <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-xl mb-3">
                    <i class="fas fa-truck-fast"></i>
                </div>
                <h4 class="text-sm font-black text-slate-900 mb-1">Express Delivery</h4>
                <p class="text-[10px] text-slate-500 leading-relaxed">Get your essentials delivered to your doorstep in minutes from nearby shops.</p>
            </div>
            <div class="flex flex-col items-center text-center p-4 bg-blue-50/50 rounded-2xl border border-blue-100">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center text-xl mb-3">
                    <i class="fas fa-tags"></i>
                </div>
                <h4 class="text-sm font-black text-slate-900 mb-1">Best Prices & Offers</h4>
                <p class="text-[10px] text-slate-500 leading-relaxed">Enjoy competitive prices and exclusive deals directly from local manufacturers and shops.</p>
            </div>
            <div class="flex flex-col items-center text-center p-4 bg-purple-50/50 rounded-2xl border border-purple-100">
                <div class="w-12 h-12 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center text-xl mb-3">
                    <i class="fas fa-boxes-stacked"></i>
                </div>
                <h4 class="text-sm font-black text-slate-900 mb-1">Wide Assortment</h4>
                <p class="text-[10px] text-slate-500 leading-relaxed">Choose from thousands of products across various categories, all verified for quality.</p>
            </div>
        </div>
    </div>

    <!-- COLLAPSIBLE DETAILS SECTION -->
    <div id="detailsPane" class="hidden animate-[slideUp_0.4s_ease] mb-12">
        <div class="bg-white border border-slate-100 rounded-[2.5rem] p-8 shadow-sm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

                <!-- Product Specifications -->
                <div>
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Product Specifications</h3>
                    <?php if (!empty($product['description'])): ?>
                        <p class="text-sm text-slate-600 leading-relaxed mb-6"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
                    <?php endif; ?>
                    <div class="grid grid-cols-2 gap-4">
                        <?php if ($product['mfg_date']): ?>
                            <div class="bg-slate-50 p-4 rounded-2xl">
                                <label class="block text-[8px] font-black text-slate-400 uppercase mb-1">MFG Date</label>
                                <div class="text-xs font-black text-slate-800"><?= date('d M Y', strtotime($product['mfg_date'])) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($product['exp_date']): ?>
                            <div class="bg-slate-50 p-4 rounded-2xl">
                                <label class="block text-[8px] font-black text-slate-400 uppercase mb-1">EXP Date</label>
                                <div class="text-xs font-black text-slate-800"><?= date('d M Y', strtotime($product['exp_date'])) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Merchant Information -->
                <div>
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Store Location</h3>
                    <p class="text-sm font-black text-slate-800"><?= htmlspecialchars($shop['shop_name']) ?></p>
                    <p class="text-xs text-slate-500 mt-1 leading-relaxed"><?= htmlspecialchars($shop['full_address']) ?></p>
                </div>

            </div>
        </div>
    </div>

    <!-- CUSTOMER REVIEWS SECTION -->
    <div class="mb-12">
        <div class="flex items-center justify-between mb-6 px-1">
            <h2 class="text-xs font-black text-slate-900 uppercase tracking-[0.2em]">Customer Reviews</h2>
            <span class="text-[10px] font-bold text-slate-400"><?= count($reviews) ?> Comments</span>
        </div>

        <?php if(!empty($reviews)): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach($reviews as $rev): ?>
                    <div class="bg-white border border-slate-100 p-6 rounded-[2rem] shadow-sm hover:border-amber-200 transition-all">
                        <div class="flex justify-between items-start mb-3">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 bg-slate-100 rounded-full flex items-center justify-center text-[10px] font-black text-slate-400 uppercase">
                                    <?= substr($rev['customer_name'], 0, 1) ?>
                                </div>
                                <div>
                                    <div class="text-xs font-black text-slate-900 leading-none mb-1"><?= htmlspecialchars($rev['customer_name']) ?></div>
                                    <div class="text-[8px] font-bold text-slate-400 uppercase tracking-widest"><?= date('d M Y', strtotime($rev['created_at'])) ?></div>
                                </div>
                            </div>
                            <div class="flex text-[9px] text-amber-400">
                                <?= str_repeat('<i class="fas fa-star"></i>', $rev['rating']) ?>
                                <?= str_repeat('<i class="far fa-star text-slate-200"></i>', 5 - $rev['rating']) ?>
                            </div>
                        </div>
                        <?php if($rev['comment']): ?>
                            <p class="text-sm text-slate-600 font-medium leading-relaxed italic">"<?= htmlspecialchars($rev['comment']) ?>"</p>
                        <?php else: ?>
                            <p class="text-xs text-slate-400 italic">No written comment provided.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="bg-white border border-dashed border-slate-200 rounded-[2.5rem] py-16 text-center">
                <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center text-2xl mx-auto mb-4"><i class="fas fa-comment-dots"></i></div>
                <p class="text-slate-400 font-bold text-[10px] uppercase tracking-widest">No reviews for this product yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- SIMILAR PRODUCTS SECTION -->
    <div class="mb-12">
        <div class="flex items-center justify-between mb-6 px-1">
            <h2 class="text-xs font-black text-slate-900 uppercase tracking-[0.2em]">Similar Items You May Need</h2>
        </div>
        <?php if (!empty($similar_products)): ?>
        <div class="flex gap-4 overflow-x-auto pb-6 no-scrollbar">
            <?php foreach ($similar_products as $sp): ?>
            <a href="Groceries_product.php?id=<?= $sp['product_id'] ?>&shop_id=<?= $sp['shop_id'] ?>"
               class="min-w-[160px] max-w-[160px] bg-white border border-slate-100 rounded-3xl p-3 shadow-sm hover:shadow-md transition-all group shrink-0">
                <div class="aspect-square bg-slate-50 rounded-2xl mb-3 overflow-hidden">
                    <?php 
                        $sim_thumb = !empty($sp['image_thumb_path']) ? $sp['image_thumb_path'] : 'assets/img/products/placeholder.png';
                    ?>
                    <img src="<?= asset_url($sim_thumb) ?>" 
                         class="w-full h-full object-cover group-hover:scale-110 transition-transform"
                         onerror="this.src='<?= asset_url('assets/img/products/placeholder.png') ?>'">
                </div>
                <h4 class="text-[10px] font-black text-slate-800 truncate mb-1"><?= htmlspecialchars($sp['name']) ?></h4>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-black text-emerald-600">₹<?= number_format($sp['sale_price'], 2) ?></span>
                    <div class="w-6 h-6 bg-slate-900 text-white rounded-lg flex items-center justify-center text-[10px]">
                        <i class="fas fa-plus"></i>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="bg-white border border-dashed border-slate-200 rounded-[2rem] py-12 text-center">
                <i class="fas fa-box-open text-slate-200 text-3xl mb-3"></i>
                <p class="text-slate-400 font-bold text-[10px] uppercase tracking-widest">Similar products not found in this shop</p>
            </div>
        <?php endif; ?>
    </div>

</main>

<script>
    const productId    = <?= (int)$product['product_id'] ?>;
    const shopId       = <?= (int)$product['shop_id'] ?>;
    const productName  = <?= json_encode($product['name']) ?>;
    const productPrice = <?= (float)$product['sale_price'] ?>;
    const productUnit  = <?= json_encode($product['primary_unit']) ?>;
    const productThumb = <?= json_encode($product['image_thumb_path'] ?? '') ?>;
    const productStock = <?= (int)$product['current_stock'] ?>;

    let currentQty = 1;
    const qtyLabel = document.getElementById('productQty');

    function changeQty(delta) {
        if (productStock <= 0) return;
        const next = currentQty + delta;
        if (next >= 1 && next <= productStock) {
            currentQty = next;
            qtyLabel.innerText = currentQty;
        }
    }

    function toggleDetails() {
        const pane   = document.getElementById('detailsPane');
        const icon   = document.getElementById('detailIcon');
        const text   = document.getElementById('detailBtnText');
        const isHidden = pane.classList.toggle('hidden');
        icon.className = isHidden ? 'fas fa-plus' : 'fas fa-minus';
        text.innerText = isHidden ? 'Show Product Details' : 'Hide Product Details';
        if (!isHidden) pane.scrollIntoView({ behavior: 'smooth' });
    }

    function handleAddToCart() {
        let cart = JSON.parse(localStorage.getItem('kl_grocery_cart') || '[]');
        const idx = cart.findIndex(i => i.product_id === productId && i.shop_id === shopId);
        if (idx > -1) {
            cart[idx].qty = Math.min(cart[idx].qty + currentQty, productStock);
        } else {
            cart.push({
                product_id:       productId,
                shop_id:          shopId,
                name:             productName,
                price:            productPrice,
                unit:             productUnit,
                qty:              currentQty,
                image_thumb_path: productThumb,
            });
        }
        localStorage.setItem('kl_grocery_cart', JSON.stringify(cart));
        updateCartBadge();
        showToast(`${currentQty} × ${productName} added to cart ✓`);
    }

    function updateCartBadge() {
        const cart  = JSON.parse(localStorage.getItem('kl_grocery_cart') || '[]');
        const total = cart.reduce((sum, i) => sum + i.qty, 0);
        const badge = document.getElementById('cartBadge');
        if (badge) {
            badge.innerText = total;
            badge.classList.toggle('hidden', total === 0);
        }
    }
    updateCartBadge();

    // ── Swiper Initialization ──
    document.addEventListener('DOMContentLoaded', function() {
        const swiper = new Swiper('.reviews-swiper', {
            slidesPerView: 1.15,
            spaceBetween: 16,
            grabCursor: true,
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
            },
            breakpoints: {
                768: { slidesPerView: 2.2, spaceBetween: 24 }
            }
        });
    });

    function showToast(msg) {
        const t = document.getElementById('toast');
        t.innerText = msg;
        t.classList.add('show');
        clearTimeout(t._timer);
        t._timer = setTimeout(() => t.classList.remove('show'), 2500);
    }
</script>

<!-- Swiper.js JS -->
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
<!-- Firebase Cloud Messaging -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>
<script>
    const firebaseConfig = {
        apiKey:            "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
        authDomain:        "khatalink-63041.firebaseapp.com",
        projectId:         "khatalink-63041",
        messagingSenderId: "905429197043",
        appId:             "1:905429197043:web:2a0cbefa0fa176fd2c5786"
    };
    if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
    const messaging = firebase.messaging();
    messaging.onMessage((payload) => {
        if (Notification.permission === "granted") {
            new Notification(payload.notification.title, {
                body: payload.notification.body,
                icon: '/khatalink/assets/favicon.png'
            });
        }
    });
</script>
</body>
</html>