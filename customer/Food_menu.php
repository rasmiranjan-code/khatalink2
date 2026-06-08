<?php
ob_start();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');

// ── 1. API DETECTION & AUTH ──
$is_api = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp');
if ($is_api) {
    error_reporting(0); ini_set('display_errors', 0);
    header('Content-Type: application/json');
}

$customer_id = 0;
if($is_api) {
    $token = get_auth_token();
    $parts = verify_secure_token($token);
    if($parts) $customer_id = (int)$parts[0];
} else {
    $customer_id = $_SESSION['customer_id'] ?? 0;
}

if(!$customer_id) {
    if($is_api) exit(json_encode(['success'=>false, 'message'=>'Unauthorized']));
    header("Location: ../auth/login.php?type=customer"); exit();
}

// ── 2. FETCH SHOP & MENU DATA ──
$shop_id = (int)($_GET['shop_id'] ?? 0);
if($shop_id <= 0) {
    if($is_api) exit(json_encode(['success'=>false, 'message'=>'Invalid Shop ID']));
    header("Location: Food_home.php"); exit();
}

// Fetch Shop Info
$stmt_s = $pdo->prepare("SELECT id, shop_name, profile_image, shop_category, average_rating, total_ratings_count, full_address, open_time, close_time, is_online FROM shop_owners WHERE id = ? AND shop_type = 'restaurant'");
$stmt_s->execute([$shop_id]);
$shop = $stmt_s->fetch();

if(!$shop) {
    if($is_api) exit(json_encode(['success'=>false, 'message'=>'Kitchen not found']));
    header("Location: Food_home.php"); exit();
}

// Fetch Menu Items
$stmt_m = $pdo->prepare("SELECT * FROM restaurant_menu_items WHERE shop_id = ? AND is_available = 1 ORDER BY category ASC, item_name ASC");
$stmt_m->execute([$shop_id]);
$menu_items = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

// Group items by category
$categories = [];
foreach($menu_items as $item) {
    $cat = $item['category'] ?: 'Other';
    $categories[$cat][] = $item;
}

if($is_api) {
    ob_clean();
    exit(json_encode(['success'=>true, 'shop'=>$shop, 'menu'=>$categories]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($shop['shop_name']) ?> Menu — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #fff; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .veg-icon { width: 12px; height: 12px; border: 1px solid #1ba94c; padding: 2px; display: flex; align-items: center; justify-content: center; }
        .veg-icon div { width: 6px; height: 6px; background: #1ba94c; border-radius: 50%; }
        .nonveg-icon { width: 12px; height: 12px; border: 1px solid #e23744; padding: 2px; display: flex; align-items: center; justify-content: center; }
        .nonveg-icon div { width: 0; height: 0; border-left: 3px solid transparent; border-right: 3px solid transparent; border-bottom: 6px solid #e23744; }
        .category-tab.active { color: #ea580c; border-bottom: 3px solid #ea580c; }
        .cart-bar { transition: transform 0.3s ease; }
    </style>
</head>
<body class="pb-32">

<!-- Top Navigation -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 px-4 h-16 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <a href="Food_home.php" class="text-slate-900"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h1 class="text-sm font-black uppercase tracking-tight leading-none"><?= htmlspecialchars($shop['shop_name']) ?></h1>
            <p class="text-[9px] font-bold text-slate-400 uppercase mt-1">Ready in 20-25 mins</p>
        </div>
    </div>
    <div class="flex gap-4">
        <button class="text-slate-400"><i class="fas fa-search"></i></button>
        <button class="text-slate-400"><i class="fas fa-share-alt"></i></button>
    </div>
</nav>

<main class="max-w-3xl mx-auto px-4 pt-6">
    
    <!-- Shop Info Header -->
    <div class="mb-8 border-b border-slate-100 pb-6">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h2 class="text-2xl font-black text-slate-900"><?= htmlspecialchars($shop['shop_name']) ?></h2>
                <p class="text-xs font-medium text-slate-500 mt-1"><?= htmlspecialchars($shop['shop_category']) ?></p>
                <p class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($shop['full_address']) ?></p>
            </div>
            <div class="bg-emerald-600 text-white p-2 rounded-xl text-center shadow-lg shadow-emerald-100">
                <div class="text-sm font-black"><?= number_format($shop['average_rating'], 1) ?: '4.0' ?> <i class="fas fa-star text-[10px]"></i></div>
                <div class="text-[8px] font-bold uppercase opacity-80 border-t border-white/20 mt-1 pt-1"><?= $shop['total_ratings_count'] ?> ratings</div>
            </div>
        </div>
        <div class="flex items-center gap-4 text-xs font-bold text-slate-600">
            <div class="flex items-center gap-1.5 bg-slate-50 px-3 py-1.5 rounded-lg"><i class="far fa-clock text-orange-500"></i> 20-30 mins</div>
            <div class="flex items-center gap-1.5 bg-slate-50 px-3 py-1.5 rounded-lg"><i class="fas fa-bicycle text-orange-500"></i> Free Delivery</div>
        </div>
    </div>

    <!-- Veg Only Filter -->
    <div class="flex items-center gap-2 mb-8">
        <span class="text-[10px] font-black uppercase text-slate-400">Veg Only</span>
        <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" id="vegToggle" class="sr-only peer" onchange="filterVegOnly(this.checked)">
            <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600"></div>
        </label>
    </div>

    <!-- Menu Sections -->
    <div class="space-y-12" id="menuSections">
        <?php foreach($categories as $cat_name => $items): ?>
        <div class="category-block">
            <h3 class="text-lg font-black text-slate-900 mb-6 flex items-center gap-2">
                <?= htmlspecialchars($cat_name) ?>
                <span class="text-[10px] bg-slate-100 text-slate-400 px-2 py-0.5 rounded-full"><?= count($items) ?></span>
            </h3>
            
            <div class="space-y-8">
                <?php foreach($items as $dish): 
                    $imgs = json_decode($dish['image_paths'] ?? '[]', true) ?: [];
                    $is_veg = (bool)$dish['is_veg'];
                ?>
                <div class="dish-item flex gap-6 <?= $is_veg ? 'is-veg' : 'is-nonveg' ?>" data-item-id="<?= $dish['id'] ?>">
                    <div class="flex-1">
                        <div class="<?= $is_veg ? 'veg-icon' : 'nonveg-icon' ?> mb-2"><div></div></div>
                        <h4 class="font-black text-slate-800 text-base mb-1"><?= htmlspecialchars($dish['item_name']) ?></h4>
                        <div class="text-sm font-black text-slate-900 mb-3">₹<?= number_format($dish['price'], 0) ?></div>
                        <p class="text-xs text-slate-400 leading-relaxed line-clamp-2"><?= htmlspecialchars($dish['description']) ?></p>
                        
                        <?php if($dish['ingredients']): ?>
                            <p class="text-[10px] text-slate-300 mt-2 font-medium italic">Contains: <?= htmlspecialchars($dish['ingredients']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="relative w-32 h-32 flex-shrink-0">
                        <?php if(!empty($imgs)): ?>
                            <img src="../<?= $imgs[0] ?>" class="w-full h-full object-cover rounded-2xl bg-slate-50 shadow-sm">
                        <?php else: ?>
                            <div class="w-full h-full bg-slate-50 rounded-2xl flex items-center justify-center"><i class="fas fa-utensils text-slate-200 text-2xl"></i></div>
                        <?php endif; ?>
                        
                        <div class="absolute -bottom-3 left-1/2 -translate-x-1/2 w-[80%]">
                            <div class="add-control bg-white border border-slate-200 rounded-xl shadow-lg flex items-center justify-between h-9 overflow-hidden">
                                <button onclick="handleDishAdd(<?= htmlspecialchars(json_encode($dish)) ?>, -1)" class="w-1/3 flex items-center justify-center text-orange-600 font-black hover:bg-slate-50">−</button>
                                <span id="qty-<?= $dish['id'] ?>" class="text-xs font-black text-slate-900 qty-display">0</span>
                                <button onclick="handleDishAdd(<?= htmlspecialchars(json_encode($dish)) ?>, 1)" class="w-1/3 flex items-center justify-center text-orange-600 font-black hover:bg-slate-50">+</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="h-px bg-slate-50 w-full mt-10"></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Bottom Cart Bar -->
    <div id="cartBar" class="cart-bar fixed bottom-6 left-4 right-4 bg-orange-600 rounded-2xl p-4 shadow-2xl z-[2000] flex items-center justify-between translate-y-32">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center text-white"><i class="fas fa-shopping-bag"></i></div>
            <div>
                <div class="text-[10px] font-black text-orange-100 uppercase tracking-widest" id="cartCount">0 Items</div>
                <div class="text-sm font-black text-white" id="cartTotal">₹0.00</div>
            </div>
        </div>
        <a href="Food_cart.php" class="bg-white text-orange-600 px-6 py-2.5 rounded-xl font-black text-[11px] uppercase tracking-widest">View Cart <i class="fas fa-arrow-right ms-1"></i></a>
    </div>

</main>

<!-- CUSTOMIZATION MODAL -->
<div id="customModal" class="fixed inset-0 z-[3000] hidden items-end justify-center bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white w-full max-w-xl rounded-t-[2.5rem] p-8 shadow-2xl animate-[slideUp_0.3s_ease]">
        <div class="flex justify-between items-start mb-6">
            <div>
                <h2 id="custDishName" class="text-xl font-black text-slate-900">Customize Dish</h2>
                <p id="custDishPrice" class="text-sm font-bold text-slate-400">Base Price: ₹0</p>
            </div>
            <button onclick="closeCustomModal()" class="w-10 h-10 bg-slate-100 rounded-full flex items-center justify-center"><i class="fas fa-times"></i></button>
        </div>
        <div id="custOptionsBody" class="space-y-8 max-h-[50vh] overflow-y-auto no-scrollbar">
            <!-- Options injected by JS -->
        </div>
        <button id="addCustomBtn" class="w-full bg-orange-600 text-white py-5 rounded-2xl font-black uppercase mt-8 shadow-xl">Add to Cart • <span id="finalCustPrice">₹0</span></button>
    </div>
</div>

<script>
let currentShopId = <?= $shop_id ?>;
let foodCart = [];

let currentCustomizingDish = null;

function handleDishAdd(dish, delta) {
    if(delta > 0 && dish.customizable_options) {
        openCustomization(dish);
    } else {
        updateLocalCart(dish.id, delta, dish.item_name, dish.price);
    }
}

function openCustomization(dish) {
    currentCustomizingDish = dish;
    document.getElementById('custDishName').innerText = dish.item_name;
    document.getElementById('custDishPrice').innerText = `Base Price: ₹${dish.price}`;
    
    const groups = JSON.parse(dish.customizable_options);
    let html = '';
    groups.forEach((g, gIdx) => {
        html += `<div>
            <h4 class="text-xs font-black uppercase text-slate-400 mb-4 tracking-widest">${g.name}</h4>
            <div class="space-y-3">
                ${g.values.map((v, vIdx) => `
                    <label class="flex justify-between items-center p-4 border border-slate-100 rounded-2xl cursor-pointer hover:bg-slate-50">
                        <div class="flex items-center gap-3">
                            <input type="${g.type}" name="group-${gIdx}" class="w-4 h-4 accent-orange-600" 
                                   data-label="${v.label}" data-price="${v.price}" onchange="calcCustomTotal()">
                            <span class="text-sm font-bold text-slate-700">${v.label}</span>
                        </div>
                        <span class="text-xs font-black text-slate-400">+₹${v.price}</span>
                    </label>
                `).join('')}
            </div>
        </div>`;
    });
    
    document.getElementById('custOptionsBody').innerHTML = html;
    calcCustomTotal();
    document.getElementById('customModal').classList.remove('hidden');
    document.getElementById('customModal').classList.add('flex');
}

function calcCustomTotal() {
    let total = parseFloat(currentCustomizingDish.price);
    document.querySelectorAll('#custOptionsBody input:checked').forEach(input => {
        total += parseFloat(input.dataset.price);
    });
    document.getElementById('finalCustPrice').innerText = `₹${total}`;
}

document.getElementById('addCustomBtn').onclick = () => {
    let selections = [];
    let totalPrice = parseFloat(currentCustomizingDish.price);
    document.querySelectorAll('#custOptionsBody input:checked').forEach(input => {
        selections.push(input.dataset.label);
        totalPrice += parseFloat(input.dataset.price);
    });
    
    const nameWithOpts = currentCustomizingDish.item_name + (selections.length ? ` (${selections.join(', ')})` : '');
    updateLocalCart(currentCustomizingDish.id, 1, nameWithOpts, totalPrice);
    closeCustomModal();
};

function closeCustomModal() { document.getElementById('customModal').classList.add('hidden'); }

window.onload = function() {
    // Load Cart from LocalStorage
    const savedCart = localStorage.getItem('kl_food_cart');
    if(savedCart) {
        foodCart = JSON.parse(savedCart);
        // If existing items are from another shop, ask to clear (Zomato Logic)
        if(foodCart.length > 0 && foodCart[0].shop_id !== currentShopId) {
            // Optionally clear or handle multi-shop later
        }
    }
    refreshUI();
};

/**
 * Updates the local cart in memory and localStorage
 */
function updateLocalCart(itemId, delta, name, price) {
    // Check if item from another shop exists
    if (foodCart.length > 0 && foodCart[0].shop_id !== currentShopId) {
        Swal.fire({
            title: 'Replace Cart?',
            text: 'Aapka cart pehle se kisi aur kitchen ke items se bhara hai. Ise clear karke naya order shuru karein?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ea580c',
            confirmButtonText: 'Haan, Replace Karo'
        }).then((result) => {
            if (result.isConfirmed) {
                foodCart = [];
                processUpdate();
            }
        });
        return;
    }

    function processUpdate() {
        let idx = foodCart.findIndex(i => i.item_id === itemId);
        
        if(idx > -1) {
            foodCart[idx].qty += delta;
            if(foodCart[idx].qty <= 0) foodCart.splice(idx, 1);
        } else if(delta > 0) {
            foodCart.push({
                item_id: itemId,
                shop_id: currentShopId,
                name: name,
                price: price,
                qty: 1
            });
        }
        
        localStorage.setItem('kl_food_cart', JSON.stringify(foodCart));
        refreshUI();
        
        // Beep/Haptic effect
        if(window.navigator.vibrate) window.navigator.vibrate(10);
    }
    
    processUpdate();
}

/**
 * Refreshes all UI elements based on current cart state
 */
function refreshUI() {
    let totalItems = 0;
    let totalPrice = 0;

    // Reset all qty labels to 0
    document.querySelectorAll('[id^="qty-"]').forEach(el => el.innerText = "0");

    foodCart.forEach(item => {
        if(item.shop_id === currentShopId) {
            const el = document.getElementById(`qty-${item.item_id}`);
            if(el) el.innerText = item.qty;
            totalItems += item.qty;
            totalPrice += (item.price * item.qty);
        }
    });

    // Update Cart Bar
    const bar = document.getElementById('cartBar');
    if(totalItems > 0) {
        bar.classList.remove('translate-y-32');
        bar.classList.add('translate-y-0');
        document.getElementById('cartCount').innerText = `${totalItems} ${totalItems > 1 ? 'Items' : 'Item'}`;
        document.getElementById('cartTotal').innerText = `₹${totalPrice.toFixed(0)}`;
    } else {
        bar.classList.add('translate-y-32');
        bar.classList.remove('translate-y-0');
    }
}

/**
 * Filters menu to show only Veg items
 */
function filterVegOnly(isVegOnly) {
    const items = document.querySelectorAll('.dish-item');
    const blocks = document.querySelectorAll('.category-block');

    items.forEach(item => {
        if(isVegOnly && !item.classList.contains('is-veg')) {
            item.style.display = 'none';
        } else {
            item.style.display = 'flex';
        }
    });

    // Hide category titles if all items inside are hidden
    blocks.forEach(block => {
        const visibleItems = block.querySelectorAll('.dish-item[style="display: flex;"]');
        const allItems = block.querySelectorAll('.dish-item');
        
        // Case when style is not explicitly set (initial state)
        let actuallyVisible = 0;
        allItems.forEach(i => { if(window.getComputedStyle(i).display !== 'none') actuallyVisible++; });

        block.style.display = actuallyVisible === 0 ? 'none' : 'block';
    });
}

// ── Scroll Animation for Cart Bar ──
let lastScrollTop = 0;
window.addEventListener("scroll", function() {
    let st = window.pageYOffset || document.documentElement.scrollTop;
    const bar = document.getElementById('cartBar');
    if(foodCart.length === 0) return;

    if (st > lastScrollTop) {
        // Scrolling down - slightly hide but keep accessible
        bar.style.opacity = "0.9";
    } else {
        // Scrolling up - show clearly
        bar.style.opacity = "1";
    }
    lastScrollTop = st <= 0 ? 0 : st;
}, false);

</script>
</body>
</html>
