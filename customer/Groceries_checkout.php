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

// Restricted Fields
$stmt_cust = $pdo->prepare("SELECT id, name, phone, email, full_address, pincode FROM customers WHERE id = ?");
$stmt_cust->execute([$customer_id]);
$profile = $stmt_cust->fetch();

// For API requests, return profile data
if ($is_api) {
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
    <title>Checkout — KhataLink Groceries</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .payment-radio:checked + label { border-color: #059669; background: #f0fdf4; color: #059669; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 pb-20">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 py-4 px-6 flex items-center gap-4 shadow-sm">
    <a href="Groceries_cart.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
    <h1 class="text-lg font-black uppercase tracking-tight">Final Checkout</h1>
</nav>

<main class="p-4 max-w-xl mx-auto">
    
    <!-- 1. Matching Status (Visible while processing) -->
    <div id="matchingLoader" class="bg-white rounded-[2rem] p-10 text-center shadow-sm border border-slate-100 mb-6">
        <div class="w-16 h-16 border-4 border-slate-100 border-t-emerald-600 rounded-full animate-spin mx-auto mb-4"></div>
        <h3 class="font-black text-slate-900 uppercase tracking-widest text-xs">Finding Best Shop for you...</h3>
        <p class="text-[10px] text-slate-400 font-bold mt-2">Checking real-time stock & nearest delivery partners</p>
    </div>

    <!-- 2. Final Order Summary (Hidden initially) -->
    <div id="checkoutContent" class="hidden space-y-6">
        
        <!-- Shop Info -->
        <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-xl"><i class="fas fa-store"></i></div>
                <div>
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Ordered From</p>
                    <h4 id="displayShopName" class="text-lg font-black text-slate-900 leading-none">Shop Name</h4>
                </div>
            </div>
            <div class="mt-4 pt-4 border-t border-dashed border-slate-200 flex justify-between items-center">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Estimated Delivery</span>
                <div class="flex items-center gap-2">
                    <i class="fas fa-clock text-emerald-500"></i>
                    <span id="displayEta" class="text-sm font-black text-slate-900">Calculating...</span>
                </div>
            </div>
        </div>

        <!-- Delivery Address Confirmation -->
        <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-map-pin"></i></div>
                <div>
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Delivering To</p>
                    <h4 id="displayAddress" class="text-xs font-bold text-slate-900 leading-relaxed">Loading Address...</h4>
                </div>
            </div>
        </div>

        <!-- Coupon Section (Dynamic) -->
        <div id="couponSection" class="hidden bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Have a Coupon?</h3>
            <div class="flex gap-2">
                <input type="text" id="couponInput" class="flex-1 bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-black uppercase outline-none focus:border-emerald-500" placeholder="ENTER CODE">
                <button onclick="applyCoupon()" class="bg-slate-900 text-white px-6 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all">Apply</button>
            </div>
            <div id="couponMessage" class="hidden mt-3 text-[10px] font-bold"></div>
        </div>

        <!-- Items List for this Shop -->
        <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Items in this Order</h3>
            <div id="foundItemsList" class="divide-y divide-slate-50"></div>
            
            <!-- Missing Items Alert -->
            <div id="missingItemsAlert" class="hidden mt-4 pt-4 border-t border-dashed border-slate-200">
                <p class="text-[9px] font-black text-red-400 uppercase mb-2">Unavailable at this shop (Removed from bill):</p>
                <div id="missingItemsList" class="flex flex-wrap gap-2"></div>
            </div>
        </div>

        <!-- Bill Breakdown -->
        <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Payment Breakdown</h3>
            <div class="space-y-3">
                <div class="flex justify-between text-sm font-bold">
                    <span class="text-slate-500">Items Subtotal</span>
                    <span id="displaySubtotal">₹0.00</span>
                </div>
                <div id="displayDiscountRow" class="hidden flex justify-between text-sm font-bold">
                    <span class="text-emerald-600 font-black">Coupon Discount</span>
                    <span id="displayDiscount" class="text-emerald-600">₹0.00</span>
                </div>
                <div class="flex justify-between text-sm font-bold">
                    <span class="text-slate-500">Delivery Fee (Distance)</span>
                    <span id="displayDistFee" class="text-slate-900">₹0.00</span>
                </div>
                <div class="flex justify-between text-sm font-bold">
                    <span class="text-slate-500">Handling Fee (1%)</span>
                    <span id="displayHandlingFee" class="text-slate-900">₹0.00</span>
                </div>
                <div class="flex justify-between text-sm font-bold">
                    <span class="text-slate-500">Platform Charges (3%)</span>
                    <span id="displayPlatformFee">₹0.00</span>
                </div>
                <div class="pt-3 border-t border-dashed border-slate-200 flex justify-between items-center">
                    <span class="font-black uppercase text-xs">Grand Total</span>
                    <span id="displayGrandTotal" class="text-xl font-black text-slate-900">₹0.00</span>
                </div>
            </div>
        </div>

        <!-- Payment Selection -->
        <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Select Payment Method</h3>
            <div class="grid grid-cols-2 gap-3">
                <input type="radio" name="pay_mode" id="pay_cod" value="COD" checked class="hidden payment-radio">
                <label for="pay_cod" class="flex flex-col items-center justify-center p-4 bg-slate-50 border-2 border-transparent rounded-2xl cursor-pointer transition-all">
                    <i class="fas fa-hand-holding-dollar mb-2"></i>
                    <span class="text-[10px] font-black uppercase">Cash on Delivery</span>
                </label>

                <input type="radio" name="pay_mode" id="pay_online" value="Online" class="hidden payment-radio">
                <label for="pay_online" class="flex flex-col items-center justify-center p-4 bg-slate-50 border-2 border-transparent rounded-2xl cursor-pointer transition-all">
                    <i class="fas fa-credit-card mb-2"></i>
                    <span class="text-[10px] font-black uppercase">Online Pay</span>
                </label>
            </div>
        </div>

        <!-- Delivery Note -->
        <div class="bg-amber-50 border border-amber-100 p-4 rounded-2xl flex gap-3">
            <i class="fas fa-info-circle text-amber-500 mt-0.5"></i>
            <p class="text-[10px] text-amber-800 font-bold leading-relaxed uppercase tracking-tight">System ne aapke area ki best dukan ko select kiya hai taaki delivery 15 min mein ho sake.</p>
        </div>

        <!-- Final Place Order Button -->
        <button onclick="placeFinalOrder()" id="finalBtn" class="w-full bg-emerald-600 text-white py-5 rounded-2xl font-black uppercase tracking-[0.2em] text-xs shadow-xl shadow-emerald-100 active:scale-95 transition-all">
            Confirm & Place Order
        </button>
    </div>

</main>

<script>
let cart = JSON.parse(localStorage.getItem('kl_grocery_cart') || '[]');
let matchedData = null;
let finalAddrData = {};
let finalOrderItems = [];
let appliedCouponCode = '';
let couponDiscount = 0;

window.onload = async function() {
    if(cart.length === 0) { window.location.href = 'Groceries_home.php'; return; }
    updateClock();
    setInterval(updateClock, 1000);

    // Get Location from URL or Session
    const urlParams = new URLSearchParams(window.location.search);
    const lat = urlParams.get('lat') || 0;
    const lng = urlParams.get('lng') || 0;

    const selectedRadius = urlParams.get('radius') || '6'; // Get radius from URL
    // IMPORTANT: Priority to URL Pincode (from Cart Form)
    const destPincode = urlParams.get('pincode') || "<?= $profile['pincode'] ?>";
    const destVillage = urlParams.get('village') || "Local";
    const destAddr    = urlParams.get('addr') || "<?= $profile['full_address'] ?>";
    const destName    = urlParams.get('name') || "<?= $profile['name'] ?>";
    const destPhone   = urlParams.get('phone') || "<?= $profile['phone'] ?>";
    const destAlt     = urlParams.get('alt') || "";
    const destBlock   = urlParams.get('block') || "";
    const destDist    = urlParams.get('dist') || "";
    const destLandmark = urlParams.get('landmark') || "";
    
    finalAddrData = { pincode: destPincode, village: destVillage, addr: destAddr, name: destName, phone: destPhone, alt: destAlt, block: destBlock, district: destDist, landmark: destLandmark, lat: lat, lng: lng };

    try {
        const res = await fetch('ajax_match_cart_shop.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cart, lat, lng, pincode: destPincode, village: destVillage, radius: selectedRadius })
        });
        const data = await res.json();

        if(!data.success) {
            Swal.fire('Order Error', data.message, 'warning').then(() => {
                window.location.href = 'Groceries_cart.php';
            });
            return;
        }

        matchedData = data;

        // Show Coupon field if active coupons exist
        if(data.has_active_coupons) {
            document.getElementById('couponSection').classList.remove('hidden');
        }
        finalOrderItems = data.found_items_list;

        document.getElementById('displayShopName').innerText = data.shop_name;

        // Show Formatted Address
        const altStr = finalAddrData.alt ? ` / ${finalAddrData.alt}` : '';
        const landmarkStr = finalAddrData.landmark ? `<div class="text-emerald-600 font-black mt-1"><i class="fas fa-map-marker-alt mr-1"></i> Landmark: ${finalAddrData.landmark}</div>` : '';

        document.getElementById('displayAddress').innerHTML = `
            <div class="text-slate-900 font-black text-sm mb-1">${finalAddrData.name} | ${finalAddrData.phone}${altStr}</div>
            <div class="text-slate-600 font-bold text-xs leading-relaxed">
                ${finalAddrData.addr}
                ${landmarkStr}
                <div class="text-slate-400 text-[10px] uppercase tracking-tight mt-1 font-medium">${finalAddrData.village}, ${finalAddrData.block}, ${finalAddrData.district} - <span class="font-black">${finalAddrData.pincode}</span></div>
            </div>
        `;

        document.getElementById('displayEta').innerText = `${data.eta_mins} min (${data.distance_km} km)`;
        document.getElementById('displaySubtotal').innerText = '₹' + data.summary.subtotal.toFixed(2);
        document.getElementById('displayDistFee').innerText = '₹' + data.summary.dist_fee.toFixed(2);
        document.getElementById('displayHandlingFee').innerText = '₹' + data.summary.handling.toFixed(2);
        document.getElementById('displayPlatformFee').innerText = '₹' + data.summary.platform.toFixed(2);
        document.getElementById('displayGrandTotal').innerText = '₹' + data.summary.grand.toFixed(2);

        // Show Road Distance if resolved
        if(data.resolved_geo && data.resolved_geo.distance_m) {
             const km = (data.resolved_geo.distance_m / 1000).toFixed(1);
             document.getElementById('displayDistFee').innerHTML = `<span class="text-[10px] text-slate-400 mr-2">(${km} km)</span> ₹${data.summary.dist_fee.toFixed(2)}`;
        }

        // Render Found Items
        document.getElementById('foundItemsList').innerHTML = data.found_items_list.map(it => `
            <div class="py-3 flex justify-between items-center">
                <span class="text-sm font-bold text-slate-700">${it.name} <span class="text-[10px] text-slate-400">x${it.qty}</span></span>
                <span class="text-sm font-black text-slate-900">₹${(it.price * it.qty).toFixed(2)}</span>
            </div>
        `).join('');

        // Handle Missing Items UI
        if(data.missing && data.missing.length > 0) {
            document.getElementById('missingItemsAlert').classList.remove('hidden');
            document.getElementById('missingItemsList').innerHTML = data.missing.map(m => `
                <span class="bg-red-50 text-red-500 text-[8px] font-black px-2 py-1 rounded-md border border-red-100">${m}</span>
            `).join('');
        }

        document.getElementById('matchingLoader').classList.add('hidden');
        document.getElementById('checkoutContent').classList.remove('hidden');

    } catch(error) { 
        console.error("Checkout Load Error:", error); 
        Swal.fire('Fetch Error', 'Server returned invalid data or logic crashed. Check Console.', 'error');
        document.getElementById('matchingLoader').innerHTML = '<p class="text-red-500 font-bold">Failed to sync with server.</p>';
    }
};

async function applyCoupon() {
    const code = document.getElementById('couponInput').value.trim();
    const subtotal = matchedData.summary.subtotal;

    if(!code) return;

    try {
        const res = await fetch('ajax_validate_coupon.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code, subtotal })
        });
        const data = await res.json();

        if(data.success) {
            appliedCouponCode = data.code;
            couponDiscount = data.discount;

            // Bazinga Animation Popup
            Swal.fire({
              title: 'Bazinga! 🎊',
              html: `<div class="text-lg font-black text-emerald-600">You Saved ₹${data.discount}!</div>
                     <div class="text-xs text-slate-400 uppercase mt-2">Discount applied on shop base price</div>`,
              icon: 'success',
              showConfirmButton: false,
              timer: 2500,
              customClass: { popup: 'rounded-[3rem] p-10 border-4 border-emerald-500' }
            });

            // Update UI
            document.getElementById('displayDiscountRow').classList.remove('hidden');
            document.getElementById('displayDiscount').innerText = '- ₹' + data.discount.toFixed(2);
            const finalGrand = matchedData.summary.grand - data.discount;
            document.getElementById('displayGrandTotal').innerText = '₹' + finalGrand.toFixed(2);
            document.getElementById('couponInput').disabled = true;
            document.getElementById('couponMessage').innerText = "Coupon Applied Successfully!";
            document.getElementById('couponMessage').className = "mt-3 text-[10px] font-bold text-emerald-600 uppercase";
            document.getElementById('couponMessage').classList.remove('hidden');
        } else {
            Swal.fire('Oops', data.message, 'error');
        }
    } catch(e) { console.error(e); }
}

function updateClock() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const dClock = document.getElementById('deviceClock');
    if(dClock) dClock.innerText = timeStr;
}

async function placeFinalOrder() {
    const btn = document.getElementById('finalBtn');
    const payMode = document.querySelector('input[name="pay_mode"]:checked').value;

    if(payMode === 'Online') {
        startOnlinePayment(btn);
        return;
    }

    processCOD(btn);
}

async function startOnlinePayment(btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Initializing Secure Pay...';

    const cashfree = Cashfree({ mode: "production" }); // Change to sandbox for testing

    const formData = new URLSearchParams();
    formData.append('shop_id', matchedData.shop_id);
    formData.append('amount', (matchedData.summary.grand - couponDiscount).toFixed(2));
    formData.append('is_marketplace', '1');
    formData.append('coupon_code', appliedCouponCode);
    
    // Add high-precision coordinates for online order creation
    formData.append('latitude', finalAddrData.lat);
    formData.append('longitude', finalAddrData.lng);
    formData.append('delivery_landmark', finalAddrData.landmark);

    formData.append('cart_json', JSON.stringify(cart));
    formData.append('delivery_meta', JSON.stringify({
        name: "<?= $profile['name'] ?>",
        phone: "<?= $profile['phone'] ?>",
        email: "<?= $profile['email'] ?>",
        pincode: "<?= $profile['pincode'] ?>",
        address: document.getElementById('delivery_addr')?.value || "<?= $profile['full_address'] ?>"
    }));

    try {
        const res = await fetch('cashfree_order.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.success) {
            cashfree.checkout({ paymentSessionId: data.payment_session_id, redirectTarget: "_self" });
        } else {
            throw new Error(data.message);
        }
    } catch(e) {
        Swal.fire('Online Pay Failed', e.message, 'error');
        btn.disabled = false; btn.innerText = 'Confirm & Place Order';
    }
}

async function processCOD(btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Order...';

    const orderPayload = {
        shop_id: matchedData.shop_id,
        cart_data: JSON.stringify(finalOrderItems), // Only send what the shop actually has
        delivery_name: finalAddrData.name,
        delivery_phone: finalAddrData.phone,
        delivery_phone_alt: finalAddrData.alt, // Ensure this column is used/handled
        delivery_email: "<?= $profile['email'] ?>",
        delivery_pincode: finalAddrData.pincode,
        delivery_village: finalAddrData.village,
        delivery_apartment_house: finalAddrData.addr,
        delivery_block: finalAddrData.block,
        delivery_landmark: finalAddrData.landmark,
        latitude: finalAddrData.lat,
        longitude: finalAddrData.lng,
        delivery_district: finalAddrData.district,
        payment_mode: document.querySelector('input[name="pay_mode"]:checked').value,
        coupon_code: appliedCouponCode,
        place_order: true
    };

    try {
        const res = await fetch('create_order.php?shop_id=' + matchedData.shop_id + '&ajax=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(orderPayload)
        });
        const result = await res.json();

        // Smart Cart Cleaning: Only remove items that were actually ordered
        let localCart = JSON.parse(localStorage.getItem('kl_grocery_cart') || '[]');
        const orderedIds = finalOrderItems.map(i => i.product_id);
        localCart = localCart.filter(i => !orderedIds.includes(i.product_id));
        localStorage.setItem('kl_grocery_cart', JSON.stringify(localCart));
        
        Swal.fire({
            title: 'Order Placed! 🎉',
            html: `<div class="text-sm">Order created with <b>${finalOrderItems.length}</b> items.<br>${localCart.length > 0 ? `<span class="text-amber-600 font-bold">${localCart.length} items left in cart for another shop.</span>` : ''}</div>`,
            icon: 'success',
            timer: 1500,
            showConfirmButton: false
        }).then(() => {
            window.location.href = 'Groceries_order_tracking.php?order_id=' + result.order_id;
        });

    } catch(e) { 
        btn.disabled = false;
        btn.innerText = 'Confirm & Place Order';
        Swal.fire('Oops', 'Could not place order. Check your internet.', 'error');
    }
}
</script>
</body>
</html>