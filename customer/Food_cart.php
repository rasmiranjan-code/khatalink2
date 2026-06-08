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

// Fetch Customer Profile for Delivery
$cust = $pdo->prepare("SELECT id, unique_id, name, phone, email, full_address, pincode FROM customers WHERE id = ?");
$cust->execute([$customer_id]);
$profile = $cust->fetch();

if ($is_api) {
    ob_clean();
    exit(json_encode([
        'success' => true,
        'profile' => $profile
    ]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Order — KhataLink Food</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .payment-radio:checked + label { border-color: #ea580c; background: #fff7ed; color: #ea580c; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 pb-24">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 py-4 px-6 flex items-center gap-4 shadow-sm">
    <a href="Food_home.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
    <h1 class="text-lg font-black uppercase tracking-tight text-orange-600">Food Cart</h1>
</nav>

<main class="p-4 max-w-2xl mx-auto">

    <!-- Cart Items Section -->
    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden mb-6">
        <div class="p-6 border-b border-slate-50 bg-slate-50/50 flex justify-between items-center">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400">Order Summary</h3>
            <button onclick="clearFoodCart()" class="text-[10px] font-black text-red-500 uppercase">Clear Cart</button>
        </div>
        <div id="foodCartList" class="divide-y divide-slate-50">
            <!-- Injected via JS -->
        </div>
    </div>

    <!-- Delivery Address Form -->
    <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm mb-6">
        <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Delivery Location</h3>
        <div class="space-y-4">
            <input type="text" id="delivery_name" value="<?= htmlspecialchars($profile['name']) ?>" placeholder="Full Name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-orange-500">
            <input type="text" id="delivery_phone" value="<?= htmlspecialchars($profile['phone']) ?>" placeholder="Contact Number" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-orange-500">
            <input type="text" id="delivery_addr" value="<?= htmlspecialchars($profile['full_address']) ?>" placeholder="House No, Building Name..." class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-orange-500">
            <input type="text" id="delivery_landmark" placeholder="Nearest Landmark (e.g. Near Shiv Temple)" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-orange-500">

            <div class="grid grid-cols-2 gap-3">
                <select id="delivery_district" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-orange-500"><option value="">District</option></select>
                <select id="delivery_block" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-orange-500"><option value="">Block</option></select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <select id="delivery_village" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-orange-500"><option value="">Village/Area</option></select>
                <input type="text" id="delivery_pincode" value="<?= $profile['pincode'] ?>" placeholder="Pincode" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold text-center" readonly>
            </div>
            <button type="button" onclick="useCurrentLocation()" class="w-full py-2 text-[10px] font-black text-blue-600 uppercase tracking-widest hover:underline">
                <i class="fas fa-location-crosshairs me-1"></i> Use Precise GPS Location
            </button>
        </div>
    </div>

    <!-- Bill Details -->
    <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm mb-12">
        <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Payment Details</h3>
        <div class="space-y-3">
            <div class="flex justify-between text-sm font-bold">
                <span class="text-slate-500">Item Total</span>
                <span id="subtotal">₹0.00</span>
            </div>
        </div>
    </div>

    <!-- Sticky Bottom Checkout -->
    <div class="fixed bottom-0 left-0 right-0 p-4 bg-white border-t border-slate-100 z-[1000] shadow-[0_-10px_20px_rgba(0,0,0,0.02)]">
        <button onclick="goToFoodCheckout()" id="checkoutBtn" class="w-full max-w-xl mx-auto flex items-center justify-center bg-orange-600 text-white py-4 rounded-2xl font-black uppercase tracking-[0.2em] text-xs shadow-xl shadow-orange-100 active:scale-95 transition-all">
            Proceed to Checkout <i class="fas fa-arrow-right ms-2"></i>
        </button>
    </div>

</main>

<script>
let foodCart = JSON.parse(localStorage.getItem('kl_food_cart') || '[]');
let distEl, blockEl, villEl, pinEl;
let currentLat = "<?= $_GET['lat'] ?? 0 ?>";
let currentLng = "<?= $_GET['lng'] ?? 0 ?>";

window.onload = async function() {
    // 1. Load Geo UI
    distEl = document.getElementById('delivery_district');
    blockEl = document.getElementById('delivery_block');
    villEl = document.getElementById('delivery_village');
    pinEl = document.getElementById('delivery_pincode');
    await initGeoHierarchy();

    // 2. Render Cart
    renderFoodCart();
};

function renderFoodCart() {
    const container = document.getElementById('foodCartList');
    const btn = document.getElementById('checkoutBtn');

    if (foodCart.length === 0) {
        container.innerHTML = `<div class="p-20 text-center text-slate-300 italic">Aapka cart khali hai. Kuch tasty mangaiye!</div>`;
        btn.disabled = true;
        btn.style.opacity = '0.5';
        return;
    }

    let sub = 0;
    container.innerHTML = foodCart.map((item, idx) => {
        const itemTotal = item.price * item.qty;
        sub += itemTotal;
        return `
        <div class="p-5 flex items-center justify-between">
            <div class="flex-1">
                <div class="text-sm font-black text-slate-900">${item.name}</div>
                <div class="text-[10px] font-bold text-slate-400">₹${item.price.toFixed(2)} × ${item.qty}</div>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-sm font-black text-slate-900">₹${itemTotal.toFixed(2)}</div>
                <div class="flex items-center bg-slate-100 rounded-xl overflow-hidden h-8">
                    <button onclick="updateFoodQty(${idx}, -1)" class="w-8 flex items-center justify-center text-orange-600 font-black hover:bg-slate-200">−</button>
                    <span class="w-6 text-center text-xs font-black">${item.qty}</span>
                    <button onclick="updateFoodQty(${idx}, 1)" class="w-8 flex items-center justify-center text-orange-600 font-black hover:bg-slate-200">+</button>
                </div>
            </div>
        </div>`;
    }).join('');

    document.getElementById('subtotal').innerText = '₹' + sub.toFixed(2);
}

function updateFoodQty(idx, delta) {
    foodCart[idx].qty += delta;
    if (foodCart[idx].qty <= 0) foodCart.splice(idx, 1);
    localStorage.setItem('kl_food_cart', JSON.stringify(foodCart));
    renderFoodCart();
}

function clearFoodCart() {
    if (confirm("Cart clear karein?")) {
        foodCart = [];
        localStorage.setItem('kl_food_cart', '[]');
        renderFoodCart();
    }
}

// ── Geo Hierarchy Logic ───────────────────────────────────────────────
async function initGeoHierarchy() {
    const res = await fetch('ajax_get_geo_hierarchy.php?type=districts');
    const data = await res.json();
    if(data.success) {
        distEl.innerHTML = '<option value="">Select District</option>' + data.data.map(d => `<option value="${d}">${d}</option>`).join('');
    }

    distEl.onchange = async () => {
        const res = await fetch(`ajax_get_geo_hierarchy.php?type=blocks&district=${distEl.value}`);
        const data = await res.json();
        blockEl.innerHTML = '<option value="">Select Block</option>' + data.data.map(b => `<option value="${b}">${b}</option>`).join('');
        villEl.innerHTML = '<option value="">Select Village</option>';
    };

    blockEl.onchange = async () => {
        const res = await fetch(`ajax_get_geo_hierarchy.php?type=villages&district=${distEl.value}&block=${blockEl.value}`);
        const data = await res.json();
        // Replicated from Groceries: Capture lat/lng in data attributes
        villEl.innerHTML = '<option value="">Select Village</option>' + data.data.map(v => 
            `<option value="${v.village_name}" data-pin="${v.pincode}" data-lat="${v.latitude}" data-lng="${v.longitude}">${v.village_name}</option>`
        ).join('');
    };

    villEl.onchange = () => {
        const opt = villEl.options[villEl.selectedIndex];
        if(opt) {
            pinEl.value = opt.dataset.pin || '';
            // Capture village coordinates for accurate distance from "Home/Target" address
            if(opt.dataset.lat && opt.dataset.lng) {
                currentLat = opt.dataset.lat;
                currentLng = opt.dataset.lng;
            }
        }
    };
}

async function useCurrentLocation() {
    if (!navigator.geolocation) return Swal.fire('Error', 'GPS not supported', 'error');
    Swal.fire({ title: 'Locating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    navigator.geolocation.getCurrentPosition(async (pos) => {
        const res = await fetch(`ajax_get_registry_location.php?lat=${pos.coords.latitude}&lng=${pos.coords.longitude}`);
        const data = await res.json();
        Swal.close();
        if(data.success) {
            distEl.value = data.data.district_name;
            await distEl.dispatchEvent(new Event('change'));
            setTimeout(async () => {
                blockEl.value = data.data.block_name;
                await blockEl.dispatchEvent(new Event('change'));
                setTimeout(() => {
                    villEl.value = data.data.village_name;
                    pinEl.value = data.data.pincode;
                }, 300);
            }, 300);
        }
    }, () => Swal.fire('GPS Error', 'Please enable location access', 'error'));
}

function goToFoodCheckout() {
    const name = document.getElementById('delivery_name').value.trim();
    const phone = document.getElementById('delivery_phone').value.trim();
    const addr = document.getElementById('delivery_addr').value.trim();
    const landmark = document.getElementById('delivery_landmark').value.trim();
    const village = document.getElementById('delivery_village').value;
    const pincode = pinEl.value;

    if (!name || !phone || !addr || !village || !landmark) {
        return Swal.fire('Adhura Address!', 'Kripya Landmark aur pura address bharein taaki rider aapko dhund sake.', 'warning');
    }

    // Prepare Redirect Params
    const params = new URLSearchParams({
        shop_id: foodCart[0].shop_id,
        name, phone, addr, landmark, village, pincode,
        block: blockEl.value,
        dist: distEl.value,
        lat: currentLat, // Pass the village-based or GPS-based latitude
        lng: currentLng  // Pass the village-based or GPS-based longitude
    });

    window.location.href = 'Food_checkout.php?' + params.toString();
}
</script>
</body>
</html>
