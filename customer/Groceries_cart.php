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

// SAFE SELECT: No password hashes or sensitive tokens
$cust = $pdo->prepare("SELECT id, unique_id, name, phone, email, full_address, pincode FROM customers WHERE id = ?");
$cust->execute([$customer_id]);
$profile = $cust->fetch();

// For API requests, return profile data + saved cart data
if ($is_api) {
    // Get saved cart securely
    $stmt_c_data = $pdo->prepare("SELECT cart_data FROM grocery_carts WHERE customer_id = ?");
    $stmt_c_data->execute([$customer_id]);
    $saved_cart_raw = $stmt_c_data->fetchColumn();
    $cart_array = $saved_cart_raw ? json_decode($saved_cart_raw, true) : [];

    ob_clean(); // Clear any previous output or warnings
    exit(json_encode([
        'success' => true,
        'profile' => $profile,
        'cart'    => hydrate_cart($pdo, $cart_array)
    ]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart — KhataLink Groceries</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .payment-radio:checked + label { border-color: #059669; background: #f0fdf4; color: #059669; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 pb-20">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 py-4 px-6 flex items-center gap-4 shadow-sm">
    <a href="Groceries_home.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
    <h1 class="text-lg font-black uppercase tracking-tight">Review Cart</h1>
</nav>

<main class="p-4 max-w-2xl mx-auto">

    <!-- Cart Items -->
    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden mb-6">
        <div class="p-6 border-b border-slate-50 bg-slate-50/50 flex justify-between items-center">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400">Shopping List</h3>
            <button onclick="clearCart()" class="text-[10px] font-black text-red-500 uppercase">Clear All</button>
        </div>
        <div id="cartList" class="divide-y divide-slate-50">
            <!-- Injected via JS -->
        </div>
    </div>

    <!-- Address Card -->
    <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm mb-6">
        <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Delivery Address</h3>
        <div class="space-y-4">
            <input type="text" id="delivery_name" value="<?= htmlspecialchars($profile['name']) ?>" placeholder="Receiver Full Name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-emerald-500">
            <div class="grid grid-cols-2 gap-3">
                <input type="text" id="delivery_phone" value="<?= htmlspecialchars($profile['phone']) ?>" placeholder="Primary Phone" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold">
                <input type="text" id="delivery_phone_alt" placeholder="Alt Phone (Optional)" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold">
            </div>
            <input type="text" id="delivery_addr" value="<?= htmlspecialchars($profile['full_address']) ?>" placeholder="House No, Building Name..." class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-emerald-500">
            <input type="text" id="delivery_landmark" placeholder="Nearest Landmark (e.g. Near Shiv Temple)" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-emerald-500">
            <div class="grid grid-cols-2 gap-3">
                <select id="delivery_district" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-emerald-500"><option value="">District</option></select>
                <select id="delivery_block" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-emerald-500"><option value="">Block</option></select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <select id="delivery_village" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-emerald-500"><option value="">Village/Area</option></select>
                <input type="text" id="delivery_pincode" value="<?= $profile['pincode'] ?>" placeholder="Pincode" class="bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold" readonly>
            </div>
            <div class="flex justify-end">
                <button type="button" onclick="useCurrentLocation()" class="text-[10px] font-black text-blue-600 uppercase tracking-widest hover:underline">
                    <i class="fas fa-location-crosshairs me-1"></i> Use My Current Location
                </button>
            </div>
        </div>
    </div>

    <!-- Bill Summary -->
    <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm mb-20">
        <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Bill Details</h3>
        <div class="space-y-3">
            <div class="flex justify-between text-sm font-bold">
                <span class="text-slate-500">Item Total</span>
                <span id="subtotal">₹0.00</span>
            </div>
            <div class="flex justify-between text-sm font-bold">
                <span class="text-slate-500">Delivery Fee</span>
                <span id="delivery_fee" class="text-emerald-600">Calculated at checkout</span>
            </div>
            <div class="pt-3 border-t border-dashed border-slate-200 flex justify-between items-center">
                <span class="font-black uppercase text-xs">Grand Total</span>
                <span id="grand_total" class="text-xl font-black text-slate-900">₹0.00</span>
            </div>
        </div>
    </div>

    <!-- Checkout Action -->
    <div class="fixed bottom-0 left-0 right-0 p-4 bg-white border-t border-slate-100 z-[1000]">
        <button onclick="goToCheckout()" id="checkoutBtn" class="w-full max-w-xl mx-auto flex items-center justify-center bg-emerald-600 text-white py-4 rounded-2xl font-black uppercase tracking-[0.2em] text-xs shadow-xl shadow-emerald-100 active:scale-95 transition-all">
            Review & Checkout
        </button>
    </div>

</main>

<script>
// ── PLACEHOLDER: inline SVG data URI — no external file needed ──
const PLACEHOLDER_IMG = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='48' height='48' viewBox='0 0 48 48'%3E%3Crect width='48' height='48' fill='%23f1f5f9' rx='8'/%3E%3Ctext x='50%25' y='54%25' dominant-baseline='middle' text-anchor='middle' font-size='22' fill='%23cbd5e1'%3E%F0%9F%9B%92%3C/text%3E%3C/svg%3E";

let distEl, blockEl, villEl, pinEl;
let cart = [];
let isSyncing = false;

async function syncCartWithServer(action = 'save') {
    if(isSyncing) return;
    isSyncing = true;
    try {
        const res = await fetch('ajax_sync_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, cart })
        });
        const data = await res.json();
        if (action === 'get' && data.success) {
            // ── SERVER AS ULTIMATE SOURCE OF TRUTH ──
            cart = data.cart; // This cart contains REAL prices from DB
            localStorage.setItem('kl_grocery_cart', JSON.stringify(cart));
            renderCart();
        }
    } catch (e) { console.error("Sync failed", e); } finally { isSyncing = false; }
}

function renderCart() {
    const container = document.getElementById('cartList');
    const btn = document.getElementById('checkoutBtn');

    if (cart.length === 0) {
        container.innerHTML = `<div class="p-20 text-center text-slate-300 italic">Cart is empty</div>`;
        btn.disabled = true;
        btn.style.opacity = '0.5';
        document.getElementById('subtotal').innerText = '₹0.00';
        document.getElementById('grand_total').innerText = '₹0.00';
        return;
    }

    btn.disabled = false;
    btn.style.opacity = '1';

    let sub = 0;
    container.innerHTML = cart.map((item, idx) => {
        const itemPrice = parseFloat(item.price) || 0;
        const itemName  = item.name || 'Unknown Item';
        
        // Fix path logic: Ensure we only prepend ../ if it's a relative path without it
        let imgSrc = item.image_thumb_path || '';
        if (imgSrc && !imgSrc.startsWith('http') && !imgSrc.startsWith('/') && !imgSrc.startsWith('../')) {
            imgSrc = '../' + imgSrc;
        }
        if (!imgSrc) imgSrc = PLACEHOLDER_IMG;

        sub += (itemPrice * item.qty);
        return `
        <div class="p-5 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <img 
                    src="${imgSrc}" 
                    class="w-12 h-12 rounded-xl object-cover bg-slate-50" 
                    onerror="this.onerror=null;this.src='${PLACEHOLDER_IMG}'"
                >
                <div>
                    <div class="text-sm font-black text-slate-900">${itemName}</div>
                    <div class="text-[10px] font-bold text-slate-400">₹${itemPrice.toFixed(2)} per ${item.unit || 'NOS'}</div>
                </div>
            </div>
            <div class="flex items-center bg-slate-100 rounded-xl overflow-hidden">
                <button onclick="updateQty(${idx}, -1)" class="w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-200"><i class="fas fa-minus text-[10px]"></i></button>
                <span class="w-8 text-center text-xs font-black">${item.qty}</span>
                <button onclick="updateQty(${idx}, 1)" class="w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-200"><i class="fas fa-plus text-[10px]"></i></button>
            </div>
        </div>`;
    }).join('');

    document.getElementById('subtotal').innerText = '₹' + sub.toFixed(2);
    document.getElementById('grand_total').innerText = '₹' + sub.toFixed(2);
}

// ── Geo Hierarchy Logic ──
async function loadGeo(type, params = '') {
    const url = `ajax_get_geo_hierarchy.php?type=${type}${params}`;
    console.log(`[GEO_DEBUG] Loading ${type} from: ${url}`);
    const res = await fetch(url);
    if(!res.ok) {
        console.error(`[GEO_ERROR] HTTP Status ${res.status} for ${type}`);
        return [];
    }
    const data = await res.json();
    console.log(`[GEO_DEBUG] Received Data for ${type}:`, data);
    if(!data.success) console.error(`[GEO_ERROR] Server logic failed: ${data.message}`);
    return data.success ? data.data : [];
}

// ── HELPER: Safely select value in dropdown even if cases differ ──
function forceSelect(el, val) {
    if(!el || !val) return false;
    const target = val.toString().trim().toLowerCase();
    for(let i=0; i<el.options.length; i++) {
        if(el.options[i].value.trim().toLowerCase() === target) {
            el.selectedIndex = i;
            console.log(`[GEO_UI] Selected "${el.options[i].value}" in ${el.id}`);
            return true;
        }
    }
    console.warn(`[GEO_UI] Could not find "${val}" in ${el.id}.`);
    return false;
}

async function initCartPage() {
    // 1. Initial Local Render
    const localCart = JSON.parse(localStorage.getItem('kl_grocery_cart') || '[]');
    if(localCart.length > 0) { cart = localCart; renderCart(); }

    // ── SMART SYNC ──
    if (localCart.length > 0) {
        // Agar phone mein items hain, toh pehle unhe server par 'save' karo (taaki prices hydrate ho jayein)
        await syncCartWithServer('save');
    } else {
        // Agar phone khali hai, tab server se purana cart 'get' karo
        await syncCartWithServer('get');
    }

    // Get DOM references
    distEl = document.getElementById('delivery_district');
    blockEl = document.getElementById('delivery_block');
    villEl = document.getElementById('delivery_village');
    pinEl = document.getElementById('delivery_pincode');

    // Load initial districts
    const districts = await loadGeo('districts');
    distEl.innerHTML = '<option value="">Select District</option>' + districts.map(d => `<option value="${d}">${d}</option>`).join('');
    
    // Pre-fill from profile if available
    const profileDistrict = <?= json_encode($profile['district'] ?? '') ?>;
    const profileBlock    = <?= json_encode($profile['block'] ?? '') ?>;
    const profileVillage  = <?= json_encode($profile['village'] ?? '') ?>;

    try {
        if (profileDistrict && districts.map(d => d.toLowerCase()).includes(profileDistrict.toLowerCase())) {
            distEl.value = profileDistrict;
            const blocks = await loadGeo('blocks', `&district=${distEl.value}`);
            blockEl.innerHTML = '<option value="">Select Block</option>' + blocks.map(b => `<option value="${b}">${b}</option>`).join('');

            if (profileBlock && blocks.includes(profileBlock)) {
                blockEl.value = profileBlock;
                const villages = await loadGeo('villages', `&district=${distEl.value}&block=${blockEl.value}`);
                villEl.innerHTML = '<option value="">Select Village</option>' + villages.map(v => `<option value="${v.village_name}" data-pin="${v.pincode}" data-lat="${v.latitude}" data-lng="${v.longitude}">${v.village_name}</option>`).join('');

                if (profileVillage) {
                    villEl.value = profileVillage;
                    const opt = villEl.options[villEl.selectedIndex];
                    if(opt) {
                        pinEl.value = opt.dataset.pin || '';
                        if(opt.dataset.lat) {
                            const url = new URL(window.location.href);
                            url.searchParams.set('lat', parseFloat(opt.dataset.lat).toFixed(15));
                            url.searchParams.set('lng', parseFloat(opt.dataset.lng).toFixed(15));
                            window.history.replaceState({}, '', url);
                        }
                    }
                }
            }
        }
    } catch(e) { console.error("Prefill failed", e); }

    // Event listeners for cascading
    distEl.onchange = async (event) => {
        const blocks = await loadGeo('blocks', `&district=${distEl.value}`);
        blockEl.innerHTML = '<option value="">Select Block</option>' + blocks.map(b => `<option value="${b}">${b}</option>`).join('');
        villEl.innerHTML = '<option value="">Select Village</option>';
        pinEl.value = '';
    };

    blockEl.onchange = async (event) => {
        const villages = await loadGeo('villages', `&district=${distEl.value}&block=${blockEl.value}`);
        villEl.innerHTML = '<option value="">Select Village</option>' + villages.map(v => `<option value="${v.village_name}" data-pin="${v.pincode}" data-lat="${v.latitude}" data-lng="${v.longitude}">${v.village_name}</option>`).join('');
        pinEl.value = '';
    };

    villEl.onchange = (event) => {
        const opt = villEl.options[villEl.selectedIndex];
        pinEl.value = opt.dataset.pin || '';
        if(opt.dataset.lat && opt.dataset.lng) {
            const url = new URL(window.location.href);
            url.searchParams.set('lat', parseFloat(opt.dataset.lat).toFixed(15));
            url.searchParams.set('lng', parseFloat(opt.dataset.lng).toFixed(15));
            window.history.replaceState({}, '', url);
        }
    };
}

window.addEventListener('DOMContentLoaded', initCartPage);

function updateQty(idx, delta) {
    cart[idx].qty = Math.min(10, cart[idx].qty + delta); // JS UI LIMIT
    if (cart[idx].qty <= 0) cart.splice(idx, 1);
    localStorage.setItem('kl_grocery_cart', JSON.stringify(cart));
    syncCartWithServer('save');
    renderCart();
}

function clearCart() {
    if (confirm("Empty your cart?")) {
        cart = [];
        localStorage.setItem('kl_grocery_cart', '[]');
        syncCartWithServer('save');
        renderCart();
    }
}

function goToCheckout() {
    const urlParams = new URLSearchParams(window.location.search);

    const name    = document.getElementById('delivery_name').value.trim();
    const phone   = document.getElementById('delivery_phone').value.trim();
    const altPhone = document.getElementById('delivery_phone_alt').value.trim();
    const landmark = document.getElementById('delivery_landmark').value.trim();
    const addr    = document.getElementById('delivery_addr').value.trim();
    const village = document.getElementById('delivery_village').value.trim();
    const pin     = document.getElementById('delivery_pincode').value.trim();
    const block   = document.getElementById('delivery_block').value.trim();
    const dist    = document.getElementById('delivery_district').value.trim();

    if (!name || !phone || !village || !pin || !block || !dist) {
        Swal.fire('Wait!', 'Please select your District, Block and Village.', 'warning');
        return;
    }

    if (!addr || !landmark) {
        Swal.fire('Address Missing', 'Please enter your House/Flat No. and a Nearest Landmark to help our rider find you faster.', 'warning');
        return;
    }

    const params = new URLSearchParams(urlParams);
    params.set('pincode', pin);
    params.set('name', name);
    params.set('phone', phone);
    params.set('alt', altPhone);
    params.set('village', village);
    params.set('landmark', landmark);
    params.set('addr', addr);
    params.set('block', block);
    params.set('dist', dist);

    window.location.href = 'Groceries_checkout.php?' + params.toString();
}

async function useCurrentLocation() {
    if (!navigator.geolocation) {
        Swal.fire('Error', 'Geolocation is not supported by your browser.', 'error');
        return;
    }

    Swal.fire({
        title: 'Fetching Location...',
        text: 'Determining your precise coordinates...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    navigator.geolocation.getCurrentPosition(async (position) => {
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;

        const url = new URL(window.location.href);
        url.searchParams.set('lat', lat.toFixed(15));
        url.searchParams.set('lng', lng.toFixed(15));
        window.history.replaceState({}, '', url);

        try {
            console.log(`[GEO_GPS] Looking up coordinates: ${lat}, ${lng}`);
            const regRes = await fetch(`ajax_get_registry_location.php?lat=${lat.toFixed(15)}&lng=${lng.toFixed(15)}`);
            const regData = await regRes.json();
            console.log("[GEO_GPS] Registry Match Result:", regData);

            if (regData.success) {
                forceSelect(distEl, regData.data.district_name);
                await distEl.dispatchEvent(new Event('change'));
                // Wait for blocks to load then select
                setTimeout(async () => {
                    forceSelect(blockEl, regData.data.block_name);
                    await blockEl.dispatchEvent(new Event('change'));
                    setTimeout(() => {
                        forceSelect(villEl, regData.data.village_name);
                        villEl.dispatchEvent(new Event('change'));
                    }, 500);
                }, 500);

                Swal.fire({
                    title: 'Precision Lock! 🎯',
                    text: `Identified: ${regData.data.village_name}. Please fill your specific House/Flat No.`,
                    icon: 'success',
                    timer: 2500
                });
            } else {
                // Fallback to Nominatim if not found in registry
                const res  = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&addressdetails=1&zoom=18&accept-language=en`);
                const data = await res.json();

                if (data && data.address) {
                    const a = data.address;
                    document.getElementById('delivery_addr').value    = a.house_number ? `${a.house_number}, ${a.road || a.building || ''}` : (a.building || a.road || a.neighbourhood || '');
                    
                    // Try to match Nominatim data to dropdowns
                    const nominatimDistrict = a.state_district || a.district || '';
                    const nominatimBlock = a.county || a.city_district || a.town || a.municipality || '';
                    const nominatimVillage = a.village || a.hamlet || a.locality || a.neighbourhood || a.quarter || a.suburb || a.residential || '';

                    // Fallback to text inputs if dropdown selection fails
                    console.log("[GEO_FALLBACK] Nominatim Data:", a);
                    document.getElementById('delivery_pincode').value  = a.postcode || '';
                    document.getElementById('delivery_addr').value = a.house_number ? `${a.house_number}, ${a.road}` : (a.road || a.suburb || '');

                    Swal.fire({ 
                        title: 'Location Found!',
                        text: 'Details pre-filled via global mapping services.',
                        icon: 'success',
                        timer: 2000
                    });
                } else {
                    Swal.fire('Manual Entry', 'Coordinates found but details missing. Please type your address.', 'info');
                }
            }
        } catch (e) {
            console.error("[GEO_CRITICAL] Location Resolution Crash:", e);
            Swal.fire('Error', 'Connection timeout. Please fill the address manually.', 'error');
        } finally {
            Swal.close();
        }
    }, (error) => {
        const msgs = {
            [error.PERMISSION_DENIED]: 'Location access denied. Please enable it in your browser settings.',
            [error.POSITION_UNAVAILABLE]: 'Location information is unavailable.',
            [error.TIMEOUT]: 'The request to get user location timed out.'
        };
        Swal.fire('Location Error', msgs[error.code] || 'Location access denied.', 'error');
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
}

renderCart();
</script>
</body>
</html>