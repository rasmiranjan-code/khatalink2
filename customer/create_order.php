<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php'; // Required for FIREBASE_API_KEY
require_once '../includes/Groceries_inventory_engine.php';
require_once '../includes/notification_service.php';

/**
 * Haversine formula to calculate distance between two coordinates in KM
 */
function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    // ── ROAD DISTANCE LOGIC (Google Maps API) ──
    $apiKey = FIREBASE_API_KEY;
    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$lat1,$lon1&destinations=$lat2,$lon2&key=$apiKey";
    
    $resp = @file_get_contents($url);
    $data = json_decode($resp, true);
    
    if($data && $data['status'] == 'OK' && $data['rows'][0]['elements'][0]['status'] == 'OK') {
        return round((float)($data['rows'][0]['elements'][0]['distance']['value'] / 1000), 2);
    }

    // Fallback to Haversine
    $earth_radius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($earth_radius * $c, 2);
}

// ===== FLUTTER API & AUTH LAYER =====
$customer_id = 0;
$is_api = false;

if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $customer_id = (int)($parts[0] ?? 0);
} else {
    $customer_id = $_SESSION['customer_id'] ?? 0;
}

if(!$customer_id) {
    if($is_api) exit(json_encode(['success'=>false, 'message'=>'Unauthorized']));
    header("Location: ../auth/login.php?type=customer"); exit();
}

$shop_id = (int)($_GET['shop_id'] ?? 0);

if($shop_id <= 0) {
    header("Location: browse_shops.php");
    exit();
}

// Fetch Shop Details
$stmt_shop = $pdo->prepare("SELECT id, shop_name, shop_category, full_address, pincode, latitude, longitude FROM shop_owners WHERE id = ? AND is_verified = 1");
$stmt_shop->execute([$shop_id]);
$shop = $stmt_shop->fetch();

if(!$shop) {
    if($is_api || isset($_GET['ajax'])) {
        ob_clean();
        exit(json_encode(['success'=>false, 'message'=>'Shop not found or not verified.']));
    }
    die("Shop not found or not verified.");
}

// Fetch Customer Profile for Address Prefill
$stmt_cust = $pdo->prepare("SELECT name, phone, email, full_address, pincode, latitude, longitude FROM customers WHERE id = ?");
$stmt_cust->execute([$customer_id]);
$cust = $stmt_cust->fetch();

// Handle Order Submission
if($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['place_order']) || $is_api)) {

    // ── FIX 3: Set JSON header ONCE at the top — removed duplicate inside try{} ──
    if($is_api || isset($_GET['ajax'])) {
        ob_clean();
        header('Content-Type: application/json');
    }

    if($is_api) {
        $data = json_decode(file_get_contents('php://input'), true);
        $_POST = array_merge($_POST, $data ?? []);
    }

    // cart_data hamesha JSON string mein aayega
    $items = is_array($_POST['cart_data'] ?? null)
        ? $_POST['cart_data']
        : json_decode($_POST['cart_data'] ?? '[]', true);

    // Extract delivery details
    $delivery_name           = trim($_POST['delivery_name']           ?? '');
    $delivery_phone          = trim($_POST['delivery_phone']          ?? '');
    $delivery_email          = trim($_POST['delivery_email']          ?? '');
    $delivery_pincode        = trim($_POST['delivery_pincode']        ?? '');
    $delivery_district       = trim($_POST['delivery_district']       ?? '');
    $delivery_block          = trim($_POST['delivery_block']          ?? '');
    $delivery_landmark       = trim($_POST['delivery_landmark']       ?? '');
    $delivery_village        = trim($_POST['delivery_village']        ?? '');
    $delivery_apartment_house = trim($_POST['delivery_apartment_house'] ?? '');
    $payment_mode            = $_POST['payment_mode'] ?? 'COD';
    $coupon_code             = strtoupper(trim($_POST['coupon_code']  ?? ''));

    // Use high-precision coordinates from request, fallback to profile
    $order_lat = (float)($_POST['latitude']  ?? $cust['latitude']  ?? 0);
    $order_lng = (float)($_POST['longitude'] ?? $cust['longitude'] ?? 0);

    // ── NEW: Resolve coordinates from Village Name + Pincode if provided ──
    // Priority to Registry coordinates for accurate distance calculation
    if(!empty($delivery_village) && !empty($delivery_pincode)) {
        $stmt_reg = $pdo->prepare("SELECT latitude, longitude FROM geo_registry WHERE LOWER(TRIM(village_name)) = LOWER(TRIM(?)) AND pincode = ? LIMIT 1");
        $stmt_reg->execute([$delivery_village, $delivery_pincode]);
        $reg_data = $stmt_reg->fetch();
        if($reg_data) {
            $order_lat = (float)$reg_data['latitude'];
            $order_lng = (float)$reg_data['longitude'];
        }
    }

    // 1. Calculate Items Subtotal
    $subtotal = 0;
    foreach($items as $item) {
        $qty   = (float)($item['qty']   ?? 1);
        $price = (float)($item['price'] ?? $item['sale_price'] ?? 0);
        $subtotal += ($qty * $price);
    }

    // ── VALIDATE AND APPLY COUPON ON SERVER SIDE ──
    $coupon_discount = 0;
    if (!empty($coupon_code)) {
        $stmt_c = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND is_active = 1 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) LIMIT 1");
        $stmt_c->execute([$coupon_code]);
        $cpn = $stmt_c->fetch();
        
        if ($cpn && $subtotal >= (float)$cpn['min_order_value']) {
            if ($cpn['discount_type'] === 'flat') {
                $coupon_discount = (float)$cpn['discount_value'];
            } else {
                $coupon_discount = ($subtotal * (float)$cpn['discount_value']) / 100;
            }
            $coupon_discount = min($coupon_discount, $subtotal); // Cap at subtotal
            // Increment usage
            $pdo->prepare("UPDATE coupons SET usage_count = usage_count + 1 WHERE id = ?")->execute([$cpn['id']]);
        }
    }

    $platform_fee = $subtotal * 0.03; // 3% Platform Fee
    $handling_fee = $subtotal * 0.01; // 1% Handling Fee

    // 2. ── DYNAMIC ROAD DISTANCE CALCULATION ──
    $distance = calculateDistance((float)$shop['latitude'], (float)$shop['longitude'], $order_lat, $order_lng);
    
    $delivery_fee = 20.00;
    if($distance > 2) $delivery_fee += (ceil($distance) - 2) * 10;
    $delivery_fee = round($delivery_fee, 2);

    if(!empty($items)) {
        try {
            $pdo->beginTransaction();

            $final_total = ($subtotal - $coupon_discount) + $delivery_fee + $handling_fee + $platform_fee;
            $net_to_shop = $subtotal - $coupon_discount;

            $stmt_order = $pdo->prepare("INSERT INTO orders
                (customer_id, shop_id, order_status, payment_mode, pincode,
                 delivery_name, delivery_phone, delivery_phone_alt, delivery_email, handling_fee, delivery_landmark,
                 delivery_village, delivery_apartment_house,
                 delivery_district, delivery_block,
                 total_amount, delivery_fee, net_to_shop, latitude, longitude, is_marketplace_order)
                VALUES
                (:customer_id, :shop_id, 'pending', :payment_mode, :pincode,
                 :delivery_name, :delivery_phone, :delivery_alt, :delivery_email, :handling_fee, :delivery_landmark,
                 :delivery_village, :delivery_apartment_house,
                 :delivery_district, :delivery_block,
                 :total_amount, :delivery_fee, :net_to_shop, :lat, :lng, 1)");

            $stmt_order->execute([
                ':customer_id'              => $customer_id,
                ':shop_id'                  => $shop_id,
                ':payment_mode'             => $payment_mode,
                ':pincode'                  => $delivery_pincode,
                ':delivery_name'            => $delivery_name,
                ':delivery_phone'           => $delivery_phone,
                ':delivery_alt'             => $_POST['delivery_phone_alt'] ?? '',
                ':delivery_email'           => $delivery_email,
                ':delivery_district'        => $delivery_district,
                ':delivery_landmark'        => $delivery_landmark,
                ':delivery_block'           => $delivery_block,
                ':delivery_village'         => $delivery_village,
                ':delivery_apartment_house' => $delivery_apartment_house,
                ':total_amount'             => $subtotal + $delivery_fee + $handling_fee + $platform_fee,
                ':delivery_fee'             => $delivery_fee + $handling_fee, // Combined for Rider
                ':handling_fee'             => $handling_fee,
                ':net_to_shop'              => $subtotal,
                ':lat'                      => $order_lat,
                ':lng'                      => $order_lng,
            ]);
            
            $order_id = $pdo->lastInsertId();
            error_log("CREATE_ORDER_DEBUG: Order created ID=$order_id");

            // Insert order items
            $stmt_item = $pdo->prepare("INSERT INTO order_items
                (order_id, product_id, item_name, quantity, unit, price_per_unit, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?)");

            foreach($items as $item) {
                $pid   = (int)($item['product_id'] ?? $item['id'] ?? 0);
                $qty   = (float)($item['qty']   ?? 1);
                $unit  = trim($item['unit']  ?? 'PCS');
                $price = (float)($item['price'] ?? 0);
                $total = $qty * $price;

                if($pid > 0) {
                    groceries_reserve_stock($pdo, $pid, $qty);
                }

                $stmt_item->execute([
                    $order_id,
                    $pid > 0 ? $pid : null,
                    $item['name'],
                    $qty,
                    $unit,
                    $price,
                    $total,
                ]);
                error_log("CREATE_ORDER_DEBUG: Item added: {$item['name']}, Qty=$qty, Unit=$unit, Price=$price");
            }

            // Notify Shop Owner
            $msg_shop = "Naya order #$order_id receive hua hai. Customer "
                . htmlspecialchars($cust['name'])
                . " ne order kiya hai. Kripya items aur rates check karke accept karein.";
            sendKhataPush(
                $pdo, $shop_id, 'shop',
                "Naya Order Mila! 🛒",
                $msg_shop,
                null,
                ['type' => 'order', 'id' => (string)$order_id]
            );

            $pdo->commit();

            if($is_api || isset($_GET['ajax'])) {
                exit(json_encode(['success' => true, 'message' => 'Order placed!', 'order_id' => $order_id]));
            }
            header("Location: Groceries_order_tracking.php?order_id=$order_id&success=Order placed successfully!");
            exit();

        } catch (Exception $e) {
            // ── FIX 2: Guard rollBack() — throws fatal if no active transaction ──
            if($pdo->inTransaction()) $pdo->rollBack();

            error_log("CREATE_ORDER_ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());

            if($is_api || isset($_GET['ajax'])) {
                exit(json_encode(['success' => false, 'message' => $e->getMessage()]));
            }
            $error = "Order failed: " . $e->getMessage();
        }
    } else {
        // Cart empty
        if($is_api || isset($_GET['ajax'])) {
            exit(json_encode(['success' => false, 'message' => 'Cart is empty.']));
        }
        $error = "Cart mein koi item nahi hai.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order from <?= htmlspecialchars($shop['shop_name']) ?> — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-6 shadow-sm">
    <div class="flex items-center gap-4">
        <a href="browse_shops.php" class="text-slate-400 hover:text-blue-600 transition-all"><i class="fas fa-arrow-left"></i></a>
        <div class="flex flex-col">
            <h2 class="text-sm font-black text-slate-900 leading-none"><?= htmlspecialchars($shop['shop_name']) ?></h2>
            <span class="text-[10px] font-bold text-blue-500 uppercase tracking-widest"><?= htmlspecialchars($shop['shop_category']) ?></span>
        </div>
    </div>
    <div class="hidden md:block text-[10px] font-black text-slate-400 uppercase tracking-widest bg-slate-50 border border-slate-100 px-3 py-1.5 rounded-full">
        Secure Marketplace Order
    </div>
</nav>

<main class="p-4 md:p-8 max-w-4xl mx-auto">
    <?php if(isset($error)): ?>
        <div class="bg-red-50 border border-red-100 text-red-600 rounded-[1.5rem] p-5 text-sm font-bold mb-8 flex items-center gap-3 shadow-lg shadow-red-100/50">
            <i class="fas fa-exclamation-circle text-lg"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- Left: Search & Add -->
        <div class="lg:col-span-7">
            <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm mb-6">
                <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-6">Add Items to List</h3>

                <div class="relative mb-6">
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                            <input type="text" id="itemSearch" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl pl-10 pr-4 py-3 text-sm font-bold outline-none focus:bg-white focus:border-blue-500 transition-all" placeholder="Search item in shop...">
                        </div>
                        <button onclick="addCustomItem()" class="bg-slate-900 text-white px-4 rounded-2xl text-xs font-black uppercase tracking-widest hover:bg-blue-600 transition-all"><i class="fas fa-plus"></i></button>
                    </div>
                    <div id="suggestions" class="absolute z-10 w-full bg-white border border-slate-200 rounded-2xl shadow-xl mt-2 hidden overflow-hidden"></div>
                </div>

                <div class="bg-blue-50 border border-blue-100 p-4 rounded-2xl flex gap-3">
                    <i class="fas fa-info-circle text-blue-500 mt-1"></i>
                    <p class="text-[11px] text-blue-700 font-medium leading-relaxed">Agar dukan ki list mein koi saaman nahi mil raha, toh use <b>manually</b> likh kar add kar sakte hain. Shopkeeper accept karte waqt uska price dal dega.</p>
                </div>
            </div>

            <!-- Cart List -->
            <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm">
                <div class="p-6 border-b border-slate-50 bg-slate-50/50">
                    <h3 class="text-xs font-black text-slate-900 uppercase tracking-widest">My Order List</h3>
                </div>
                <div id="cartItems" class="divide-y divide-slate-50 min-h-[100px]">
                    <div class="p-10 text-center text-slate-300 italic text-sm" id="emptyCartMsg">No items added yet.</div>
                </div>
            </div>
        </div>

        <!-- Right: Checkout Details -->
        <div class="lg:col-span-5">
            <form method="POST" class="sticky top-24">
                <input type="hidden" name="cart_data" id="cartDataInput">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm mb-6">
                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-6">Delivery Details</h3>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Receiver Name</label>
                            <input type="text" name="delivery_name" value="<?= htmlspecialchars($cust['name']) ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" required>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Phone Number</label>
                                <input type="text" name="delivery_phone" value="<?= htmlspecialchars($cust['phone']) ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" required>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Email Address</label>
                                <input type="email" name="delivery_email" value="<?= htmlspecialchars($cust['email']) ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" required>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Apartment / House Name / Flat No.</label>
                            <input type="text" name="delivery_apartment_house" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="e.g. Green Valley Apts, House 42" required>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Village / Landmark</label>
                                <input type="text" name="delivery_village" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="e.g. Rampur Village" required>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Pincode</label>
                                <input type="text" name="delivery_pincode" value="<?= htmlspecialchars($cust['pincode']) ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" required maxlength="6">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Block</label>
                                <input type="text" name="delivery_block" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="e.g. Block-A / Sadar" required>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">District</label>
                                <input type="text" name="delivery_district" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="e.g. Lucknow" required>
                            </div>
                        </div>
                    </div>

                    <p class="text-[9px] text-slate-400 mt-6 italic">* Details pre-filled from your profile for convenience.</p>

                    <div class="mb-8 mt-6">
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Mode of Payment</label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center justify-center p-3 bg-slate-50 border-2 border-slate-100 rounded-xl cursor-pointer hover:border-blue-500 transition-all">
                                <input type="radio" name="payment_mode" value="COD" checked class="hidden peer">
                                <span class="text-[10px] font-black uppercase peer-checked:text-blue-600">Cash (COD)</span>
                            </label>
                            <label class="flex items-center justify-center p-3 bg-slate-50 border-2 border-slate-100 rounded-xl opacity-50 cursor-not-allowed" title="Online payment will be enabled after shop accepts the order.">
                                <input type="radio" name="payment_mode" value="Online" disabled class="hidden">
                                <span class="text-[10px] font-black uppercase">Online</span>
                            </label>
                        </div>
                    </div>

                    <div id="priceSummary" class="border-t border-dashed border-slate-200 pt-6 mb-8 hidden">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold text-slate-400 uppercase">Known Total:</span>
                            <span class="text-sm font-black" id="totalDisplay">₹0.00</span>
                        </div>
                        <p class="text-[10px] font-bold text-amber-600 uppercase tracking-tight">Final bill will include prices for custom items.</p>
                    </div>

                    <button type="submit" name="place_order" id="placeOrderBtn" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all uppercase tracking-widest text-xs shadow-xl shadow-slate-200">
                        Place Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<!-- Units Datalist -->
<datalist id="unitList">
    <option value="PCS">Pieces</option>
    <option value="PKT">Packet</option>
    <option value="KG">Kilogram</option>
    <option value="Gram">Gram</option>
    <option value="Ltr">Litre</option>
    <option value="ML">Millilitre</option>
    <option value="Dozen">Dozen</option>
    <option value="Box">Box</option>
    <option value="Bundle">Bundle</option>
    <option value="10gm">10 Gram</option>
    <option value="50gm">50 Gram</option>
    <option value="100gm">100 Gram</option>
    <option value="250gm">250 Gram</option>
    <option value="500gm">500 Gram</option>
    <option value="1kg">1 KG</option>
    <option value="2kg">2 KG</option>
    <option value="5kg">5 KG</option>
    <option value="10kg">10 KG</option>
    <option value="100ml">100 ML</option>
    <option value="250ml">250 ML</option>
    <option value="500ml">500 ML</option>
    <option value="1L">1 Litre</option>
    <option value="2L">2 Litre</option>
    <option value="5L">5 Litre</option>
    <option value="Half Ltr">Half Litre</option>
</datalist>

<script>
let cart = [];
const shopId = <?= $shop_id ?>;
const searchInput   = document.getElementById('itemSearch');
const suggestionsBox = document.getElementById('suggestions');

// ── Guard: disable submit if cart is empty ──
document.querySelector('form').addEventListener('submit', function(e) {
    if(cart.length === 0) {
        e.preventDefault();
        alert('Pehle koi item add karein!');
        return;
    }
    document.getElementById('cartDataInput').value = JSON.stringify(cart);
});

// Search Logic
searchInput.addEventListener('input', async function() {
    const q = this.value.trim();
    if(q.length < 2) { suggestionsBox.classList.add('hidden'); return; }
    try {
        const res  = await fetch(`ajax_search_inventory.php?shop_id=${shopId}&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        if(data.length > 0) {
            suggestionsBox.innerHTML = data.map(p => `
                <div class="px-6 py-3 hover:bg-slate-50 cursor-pointer flex justify-between items-center border-b border-slate-50 last:border-0"
                     onclick='addToCart(${JSON.stringify(p)})'>
                    <div class="text-sm font-black text-slate-700">${p.name}</div>
                    <div class="text-xs font-bold text-emerald-600">₹${p.price}</div>
                </div>
            `).join('');
        } else {
            suggestionsBox.innerHTML = `<div class="px-6 py-4 text-xs font-bold text-slate-400 uppercase italic">Item not found. Click + to add anyway.</div>`;
        }
        suggestionsBox.classList.remove('hidden');
    } catch(e) { console.error(e); }
});

function addCustomItem() {
    const name = searchInput.value.trim();
    if(!name) return;
    addToCart({ id: 0, name: name, price: 0, unit: 'PCS' });
}

function updateUnit(index, value) {
    cart[index].unit = value;
    document.getElementById('cartDataInput').value = JSON.stringify(cart);
}

function addToCart(product) {
    const existing = cart.find(c => c.name === product.name);
    if(existing) { existing.qty++; }
    else          { cart.push({ ...product, qty: 1 }); }
    renderCart();
    searchInput.value = '';
    suggestionsBox.classList.add('hidden');
}

function updateQty(index, delta) {
    cart[index].qty += delta;
    if(cart[index].qty <= 0) cart.splice(index, 1);
    renderCart();
}

function renderCart() {
    const container = document.getElementById('cartItems');
    const summary   = document.getElementById('priceSummary');
    const totalDisp = document.getElementById('totalDisplay');

    if(cart.length === 0) {
        container.innerHTML = `<div class="p-10 text-center text-slate-300 italic text-sm" id="emptyCartMsg">No items added yet.</div>`;
        summary.classList.add('hidden');
        document.getElementById('cartDataInput').value = '[]';
        return;
    }

    summary.classList.remove('hidden');
    let total = 0;
    container.innerHTML = cart.map((item, i) => {
        const itemTotal = item.price * item.qty;
        total += itemTotal;
        return `
            <div class="p-5 flex items-center justify-between">
                <div class="flex-1">
                    <div class="text-sm font-black text-slate-900">${item.name}</div>
                    <div class="mt-1 flex items-center gap-2">
                        <input list="unitList" value="${item.unit}" onchange="updateUnit(${i}, this.value)"
                               class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-[10px] font-bold w-24 outline-none focus:border-blue-500"
                               placeholder="Set Unit...">
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center bg-slate-100 rounded-lg overflow-hidden">
                        <button type="button" onclick="updateQty(${i}, -1)" class="w-8 h-8 flex items-center justify-center hover:bg-slate-200 text-slate-400 transition-all"><i class="fas fa-minus text-[10px]"></i></button>
                        <span class="w-8 text-center text-xs font-black">${item.qty}</span>
                        <button type="button" onclick="updateQty(${i}, 1)"  class="w-8 h-8 flex items-center justify-center hover:bg-slate-200 text-slate-400 transition-all"><i class="fas fa-plus text-[10px]"></i></button>
                    </div>
                    <div class="w-16 text-right text-sm font-black text-slate-900">
                        ${item.price > 0 ? '₹' + itemTotal.toFixed(2) : '—'}
                    </div>
                </div>
            </div>`;
    }).join('');

    totalDisp.innerText = '₹' + total.toFixed(2);
    document.getElementById('cartDataInput').value = JSON.stringify(cart);
}

// Close suggestions on outside click
document.addEventListener('click', (e) => {
    if(!searchInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
        suggestionsBox.classList.add('hidden');
    }
});
</script>
</body>
</html>