<?php
ob_start();
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
date_default_timezone_set('Asia/Kolkata');

// AUTH CHECK
if (!isset($_SESSION['customer_id'])) { header("Location: ../auth/login.php?type=customer"); exit(); }
$customer_id = $_SESSION['customer_id'];

// INPUT PARAMS
$shop_id  = (int)($_GET['shop_id']  ?? 0);
$name     = trim($_GET['name']      ?? '');
$phone    = trim($_GET['phone']     ?? '');
$addr     = trim($_GET['addr']      ?? '');
$landmark = trim($_GET['landmark']  ?? '');
$village  = trim($_GET['village']   ?? '');
$pincode  = trim($_GET['pincode']   ?? '');
$block    = trim($_GET['block']     ?? '');
$dist     = trim($_GET['dist']      ?? '');
$u_lat    = (float)($_GET['lat']    ?? 0);
$u_lng    = (float)($_GET['lng']    ?? 0);

// FETCH SHOP FOR DISTANCE
$stmt_s = $pdo->prepare("SELECT shop_name, latitude, longitude FROM shop_owners WHERE id = ?");
$stmt_s->execute([$shop_id]);
$shop = $stmt_s->fetch();

if(!$shop) { header("Location: Food_cart.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — KhataLink Food</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-slate-50 text-slate-900 pb-24">

<nav class="bg-white border-b border-slate-100 py-4 px-6 flex items-center gap-4 shadow-sm">
    <a href="Food_cart.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
    <h1 class="text-lg font-black uppercase tracking-tight text-orange-600">Checkout</h1>
</nav>

<main class="p-4 max-w-xl mx-auto space-y-6">

    <!-- Order Breakdown -->
    <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
        <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-6">Payment Summary</h3>
        <div class="space-y-4" id="priceBreakdown">
            <!-- Injected via JS -->
            <div class="animate-pulse flex flex-col gap-4">
                <div class="h-4 bg-slate-100 rounded w-full"></div>
                <div class="h-4 bg-slate-100 rounded w-3/4"></div>
            </div>
        </div>
    </div>

    <!-- Delivery Details -->
    <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
        <div class="flex items-center gap-4">
            <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-map-pin"></i></div>
            <div>
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Delivering to</p>
                <h4 class="text-xs font-bold text-slate-900 leading-relaxed">
                    <?= htmlspecialchars($name) ?> | <?= htmlspecialchars($phone) ?><br>
                    <?= htmlspecialchars($addr) ?>, Near <?= htmlspecialchars($landmark) ?><br>
                    <?= htmlspecialchars($village) ?>, <?= htmlspecialchars($block) ?>, <?= htmlspecialchars($dist) ?> - <?= $pincode ?>
                </h4>
            </div>
        </div>
    </div>

    <!-- Payment Mode -->
    <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
        <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Payment Method</h3>
        <div class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl">
            <div class="flex items-center gap-3">
                <i class="fas fa-hand-holding-dollar text-slate-400"></i>
                <span class="text-sm font-bold">Cash on Delivery</span>
            </div>
            <i class="fas fa-check-circle text-emerald-600"></i>
        </div>
    </div>

    <button onclick="placeFoodOrder()" id="placeBtn" class="w-full bg-orange-600 text-white py-5 rounded-3xl font-black uppercase text-xs tracking-widest shadow-xl shadow-orange-100 transition-all active:scale-95">
        Place Order <span id="btnTotal"></span>
    </button>

</main>

<script>
let foodCart = JSON.parse(localStorage.getItem('kl_food_cart') || '[]');
let finalData = {};

window.onload = function() {
    calculateFinalBill();
};

function calculateFinalBill() {
    const subtotal = foodCart.reduce((sum, i) => sum + (i.price * i.qty), 0);
    
    // Distance Logic (Mirroring Groceries)
    const shopLat = <?= (float)$shop['latitude'] ?>;
    const shopLng = <?= (float)$shop['longitude'] ?>;
    const userLat = <?= $u_lat ?>;
    const userLng = <?= $u_lng ?>;
    
    let distKm = 0;

    // ── COORDINATE GUARD: Prevent 9000km distance bug ──
    if (userLat !== 0 && userLng !== 0 && shopLat !== 0) {
        const R = 6371;
        const dLat = (userLat - shopLat) * Math.PI / 180;
        const dLon = (userLng - shopLng) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(shopLat * Math.PI / 180) * Math.cos(userLat * Math.PI / 180) * Math.sin(dLon/2) * Math.sin(dLon/2);
        distKm = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    // ── SYNCED LOGIC: Base ₹20 + ₹10 per KM (After 2KM) ──
    let deliveryFee = 20.00; 
    if(distKm > 2) deliveryFee += (Math.ceil(distKm) - 2) * 10;
    
    const platformFee = subtotal * 0.03; // 3%
    const handlingFee = subtotal * 0.01; // 1%
    const grandTotal  = subtotal + deliveryFee + platformFee + handlingFee;

    finalData = { subtotal, deliveryFee, platformFee, handlingFee, grandTotal, distKm };

    document.getElementById('priceBreakdown').innerHTML = `
        <div class="flex justify-between text-sm font-bold text-slate-500"><span>Item Subtotal</span><span>₹${subtotal.toFixed(2)}</span></div>
        <div class="flex justify-between text-sm font-bold text-slate-500"><span>Delivery Fee (${distKm.toFixed(1)} km)</span><span class="text-orange-600">₹${deliveryFee.toFixed(2)}</span></div>
        <div class="flex justify-between text-sm font-bold text-slate-500"><span>Handling Fee (1%)</span><span>₹${handlingFee.toFixed(2)}</span></div>
        <div class="flex justify-between text-sm font-bold text-slate-500"><span>Platform Charges (3%)</span><span>₹${platformFee.toFixed(2)}</span></div>
        <div class="pt-4 border-t border-dashed border-slate-200 flex justify-between items-center"><span class="font-black uppercase text-xs">Total Amount</span><span class="text-2xl font-black text-slate-900">₹${grandTotal.toFixed(0)}</span></div>
    `;
    document.getElementById('btnTotal').innerText = `• ₹${grandTotal.toFixed(0)}`;
}

async function placeFoodOrder() {
    const btn = document.getElementById('placeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

    const payload = {
        shop_id: <?= $shop_id ?>,
        cart_data: JSON.stringify(foodCart),
        delivery_name: "<?= addslashes($name) ?>",
        delivery_phone: "<?= $phone ?>",
        delivery_email: "<?= $_SESSION['customer_email'] ?? 'support@khatalink.com' ?>",
        delivery_pincode: "<?= $pincode ?>",
        delivery_village: "<?= addslashes($village) ?>",
        delivery_apartment_house: "<?= addslashes($addr) ?>",
        delivery_landmark: "<?= addslashes($landmark) ?>",
        delivery_block: "<?= addslashes($block) ?>",
        delivery_district: "<?= addslashes($dist) ?>",
        latitude: <?= $u_lat ?>,
        longitude: <?= $u_lng ?>,
        payment_mode: 'COD',
        place_order: true
    };

    try {
        const res = await fetch('create_order.php?shop_id=<?= $shop_id ?>&ajax=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(payload)
        });
        const result = await res.json();
        
        if(result.success) {
            localStorage.setItem('kl_food_cart', '[]'); // Clear cart
            Swal.fire({ title: 'Order Confirmed! 🍛', text: 'Kitchen ko aapka order bhej diya gaya hai.', icon: 'success', timer: 2000, showConfirmButton: false }).then(() => {
                window.location.href = 'Food_order_tracking.php?order_id=' + result.order_id;
            });
        } else {
            throw new Error(result.message);
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerText = 'Place Order';
        Swal.fire('Error', e.message || 'Order failed. Please try again.', 'error');
    }
}
</script>
</body>
</html>
