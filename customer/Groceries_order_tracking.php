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

$order_id = (int)($_GET['order_id'] ?? 0);

// Fetch Order with Shop and Delivery Boy details
$stmt = $pdo->prepare("SELECT o.*, s.shop_name, s.latitude as shop_lat, s.longitude as shop_lng, s.full_address as shop_address, s.average_rating, s.total_ratings_count, o.latitude as cust_lat, o.longitude as cust_lng,
           dp.name as rider_name, dp.phone as rider_phone, dp.current_lat as rider_lat, dp.current_lng as rider_lng
    FROM orders o
    JOIN shop_owners s ON o.shop_id = s.id
    LEFT JOIN delivery_partners dp ON o.delivery_boy_id = dp.id
    WHERE o.id = ? AND o.customer_id = ?
");
$stmt->execute([$order_id, $customer_id]);
$order = $stmt->fetch();

if(!$order) { header("Location: Groceries_home.php"); exit(); }

// For API requests, return JSON order info
if ($is_api) {
    exit(json_encode([
        'success' => true,
        'order'   => $order
    ]));
}

$statuses = ['pending', 'accepted', 'packing', 'ready_for_pickup', 'assigned', 'picked_up', 'delivered', 'cancelled'];
$current_status = $order['order_status'];
$can_cancel = !in_array($current_status, ['picked_up', 'delivered', 'cancelled']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Order #<?= $order_id ?> — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Leaflet for Map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        #map { height: 300px; border-radius: 2rem; }
        .step-active { color: #059669; }
        /* Marker Smooth Animation */
        .leaflet-marker-icon {
            transition: transform 1s linear !important;
        }
        .step-line { width: 2px; height: 30px; background: #e2e8f0; margin-left: 15px; }
        .step-line.active { background: #059669; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 pb-10">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 py-4 px-6 flex items-center justify-between shadow-sm">
    <div class="flex items-center gap-4">
        <a href="Groceries_orders.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
        <h1 class="text-lg font-black uppercase tracking-tight">Track Order</h1>
    </div>
    <div class="flex items-center gap-2">
        <?php if($can_cancel): ?>
            <button onclick="requestCancel(<?= $order_id ?>)" class="text-[9px] font-black bg-red-50 text-red-600 px-3 py-1.5 rounded-full uppercase tracking-widest border border-red-100 hover:bg-red-600 hover:text-white transition-all">Cancel</button>
        <?php endif; ?>
        <span class="text-[10px] font-black bg-emerald-100 text-emerald-600 px-3 py-1.5 rounded-full uppercase tracking-widest">Live</span>
    </div>
</nav>

<main class="p-4 max-w-xl mx-auto">

    <!-- 1. Tracking Map (Visible when Rider is Assigned or Picked Up) -->
    <div id="trackingMapContainer" class="mb-6 <?= in_array($current_status, ['assigned', 'picked_up']) ? '' : 'hidden' ?>">
        <div id="map" class="shadow-lg border border-white"></div>
        <div class="bg-white p-4 rounded-b-[2rem] border-x border-b border-slate-100 flex items-center justify-between shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-full flex items-center justify-center animate-bounce"><i class="fas fa-motorcycle"></i></div>
                <div>
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">Estimated Arrival</p>
                    <p class="text-sm font-black" id="etaDisplay">Calculating ETA...</p>
                </div>
            </div>
            <?php if($order['rider_phone']): ?>
                <a href="tel:<?= $order['rider_phone'] ?>" class="bg-slate-900 text-white px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest">Call Partner</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 2. Status Stepper -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 p-8 shadow-sm mb-6">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h3 class="text-xl font-black text-slate-900 leading-none">Order Status</h3>
                <p class="text-[10px] font-bold text-slate-400 uppercase mt-2 tracking-widest">#ORD-<?= $order_id ?></p>
            </div>

            <!-- DCC Code Section (Visible when Rider reaches Customer) -->
            <?php if($current_status === 'picked_up'): ?>
                <div id="dccContainer" class="<?= empty($order['delivery_code']) ? 'hidden' : '' ?> text-right">
                    <div class="text-[8px] font-black text-blue-600 uppercase tracking-widest">Security DCC OTP</div>
                    <div id="dccCode" class="text-2xl font-black tracking-[0.1em] text-slate-900"><?= $order['delivery_code'] ?: '------' ?></div>
                    <p class="text-[7px] text-slate-400 font-bold uppercase">Share this with Rider</p>
                </div>
            <?php endif; ?>

            <?php if($current_status !== 'picked_up' && $current_status !== 'delivered' && $current_status !== 'cancelled'): ?>
                <div class="text-right">
                    <div class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Est. Preparation</div>
                    <div class="text-sm font-black text-slate-900">10-15 Mins</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="space-y-0">
            <?php 
            $steps = [
                ['id' => 'pending', 'label' => 'Order Placed', 'icon' => 'fa-receipt'],
                ['id' => 'accepted', 'label' => 'Confirmed by Shop', 'icon' => 'fa-check-circle'],
                ['id' => 'packing', 'label' => 'Packing your items', 'icon' => 'fa-box-open'],
                ['id' => 'assigned', 'label' => 'Rider is arriving', 'icon' => 'fa-motorcycle'],
                ['id' => 'picked_up', 'label' => 'Out for delivery', 'icon' => 'fa-truck-fast'],
                ['id' => 'delivered', 'label' => 'Delivered', 'icon' => 'fa-house-circle-check']
            ];

            $found_current = false;
            foreach($steps as $index => $step):
                $is_done = array_search($current_status, $statuses) >= array_search($step['id'], $statuses);
                $is_last = ($index === count($steps) - 1);
            ?>
                <div class="flex items-start gap-4">
                    <div class="flex flex-col items-center">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs <?= $is_done ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-100' : 'bg-slate-100 text-slate-300' ?>">
                            <i class="fas <?= $step['icon'] ?>"></i>
                        </div>
                        <?php if(!$is_last): ?>
                            <div class="step-line <?= $is_done ? 'active' : '' ?>"></div>
                        <?php endif; ?>
                    </div>
                    <div class="pt-1">
                        <p class="text-xs font-black uppercase tracking-widest <?= $is_done ? 'text-slate-900' : 'text-slate-300' ?>"><?= $step['label'] ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 2.5 Delivery Address Detail (New Section) -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 p-6 shadow-sm mb-6">
        <div class="flex items-center gap-4 mb-4">
            <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center"><i class="fas fa-map-pin"></i></div>
            <div>
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Delivering To</p>
                <div class="mt-1">
                    <div class="text-slate-900 font-black text-sm mb-1"><?= htmlspecialchars($order['delivery_name'] ?: $order['customer_name']) ?> | <?= htmlspecialchars($order['delivery_phone']) ?><?= $order['delivery_phone_alt'] ? ' / '.htmlspecialchars($order['delivery_phone_alt']) : '' ?></div>
                    <div class="text-slate-600 font-bold text-xs leading-relaxed">
                        <?= htmlspecialchars($order['delivery_apartment_house']) ?>
                        <?php if($order['delivery_landmark']): ?>
                            <div class="text-emerald-600 font-black mt-1"><i class="fas fa-map-marker-alt mr-1"></i> Landmark: <?= htmlspecialchars($order['delivery_landmark']) ?></div>
                        <?php endif; ?>
                        <div class="text-slate-400 text-[10px] uppercase tracking-tight mt-1 font-medium"><?= htmlspecialchars($order['delivery_village']) ?>, <?= htmlspecialchars($order['delivery_block']) ?>, <?= htmlspecialchars($order['delivery_district']) ?> - <span class="font-black"><?= $order['pincode'] ?></span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Shop & Summary -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 p-6 shadow-sm">
        <div class="flex items-center gap-4 mb-6">
            <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-xl"><i class="fas fa-store"></i></div>
            <div>
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Store Partner</p>
                <h4 class="text-lg font-black text-slate-900 leading-none"><?= htmlspecialchars($order['shop_name']) ?></h4>
                <?php if($order['average_rating'] > 0): ?>
                <p class="text-[9px] font-bold text-amber-500 mt-1"><i class="fas fa-star me-1"></i> <?= number_format($order['average_rating'], 1) ?> (<?= $order['total_ratings_count'] ?> ratings)</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="pt-4 border-t border-dashed border-slate-100 flex justify-between items-center">
            <p class="text-[10px] font-black text-slate-400 uppercase">Bill Amount</p>
            <p class="text-lg font-black text-slate-900">₹<?= number_format($order['total_amount'], 2) ?></p>
        </div>
    </div>

    <button onclick="window.location.reload()" class="w-full mt-8 bg-white border border-slate-200 text-slate-400 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-50 transition-all">Refresh Status</button>

</main>

<script>
// ── Live Tracking Logic ──────────────────────────────────────────────────
let map, riderMarker, userMarker;
const riderLat = <?= (float)$order['rider_lat'] ?>;
const riderLng = <?= (float)$order['rider_lng'] ?>;
const userLat  = <?= (float)$order['latitude'] ?>;
const userLng  = <?= (float)$order['longitude'] ?>;
const status   = "<?= $current_status ?>";

if(status === 'assigned' || status === 'picked_up') {
    initMap();
}

// ── Helper: Haversine distance for immediate feedback ──
function getDistJS(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2-lat1) * Math.PI / 180;
    const dLon = (lon2-lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(lat1 * Math.PI / 180 ) * Math.cos(lat2 * Math.PI / 180 ) * Math.sin(dLon/2) * Math.sin(dLon/2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

// ── ETA Display Logic ─────────────────────────────────────────────────────────
function updateEtaDisplay(etaMin, distKm) {
    const el = document.getElementById('etaDisplay');
    if(!el) return;
    
    if (etaMin <= 5) {
        el.innerText = 'Arriving in < 5 min';
    } else {
        el.innerText = `Arriving in ${etaMin} min (${parseFloat(distKm).toFixed(1)} km)`;
    }
}

function initMap() {
    map = L.map('map', { zoomControl: false }).setView([riderLat || userLat, riderLng || userLng], 15);
    
    L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    // Define Icons
    const scooterIcon = L.divIcon({
        html: '<div class="bg-blue-600 w-8 h-8 rounded-full border-4 border-white shadow-xl flex items-center justify-center text-white text-[10px]"><i class="fas fa-motorcycle"></i></div>',
        className: '', iconSize: [32, 32]
    });

    const homeIcon = L.divIcon({
        html: '<div class="bg-emerald-600 w-8 h-8 rounded-full border-4 border-white shadow-xl flex items-center justify-center text-white text-[10px]"><i class="fas fa-house"></i></div>',
        className: '', iconSize: [32, 32]
    });

    // Initial Markers
    if(riderLat && riderLng) {
        riderMarker = L.marker([riderLat, riderLng], { icon: scooterIcon }).addTo(map);
    }
    if(userLat && userLng) {
        userMarker = L.marker([userLat, userLng], { icon: homeIcon }).addTo(map);
    }

    // Initial ETA display via JS calculation while server polls
    if(riderLat && userLat) {
        const d = getDistJS(riderLat, riderLng, userLat, userLng);
        const m = Math.max(5, Math.ceil(d * 4) + 2);
        updateEtaDisplay(m, d);
    }
}

// Auto-refresh status from server every 15s
setInterval(async () => {
    try {
        const res = await fetch(`ajax_get_order_status.php?order_id=<?= $order_id ?>&customer_id=<?= $customer_id ?>`); // Pass customer_id for auth
        const data = await res.json();
        if(data.status !== status) window.location.reload();
        
        if(data.success) {
            // Update or Create rider pos on map
            if(data.rider_lat && data.rider_lng) {
                if(riderMarker) {
                    riderMarker.setLatLng([data.rider_lat, data.rider_lng]);
                } else {
                    const scooterIcon = L.divIcon({
                        html: '<div class="bg-blue-600 w-8 h-8 rounded-full border-4 border-white shadow-xl flex items-center justify-center text-white text-[10px]"><i class="fas fa-motorcycle"></i></div>',
                        className: '', iconSize: [32, 32]
                    });
                    riderMarker = L.marker([data.rider_lat, data.rider_lng], { icon: scooterIcon }).addTo(map);
                }

                // Update ETA with new road distance data from server
                if(data.eta && data.distance_km) {
                    updateEtaDisplay(data.eta, data.distance_km);
                }
            }
        }

        // Update DCC if visible
        if(data.dcc && document.getElementById('dccContainer')) {
            document.getElementById('dccCode').innerText = data.dcc;
            document.getElementById('dccContainer').classList.remove('hidden');
        }
    } catch(e){}
}, 15000);

async function requestCancel(orderId) {
    const result = await Swal.fire({
        title: 'Cancel Order?',
        text: "Dukandaar ko cancellation request bheji jayegi.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Request Cancel',
        confirmButtonColor: '#dc2626',
        customClass: { popup: 'rounded-[2.5rem]' }
    });

    if(result.isConfirmed) {
        try {
            const res = await fetch('ajax_cancel_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: orderId })
            });
            const data = await res.json();
            if(data.success) {
                Swal.fire('Requested', data.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        } catch(e) { Swal.fire('Error', 'Connection failed', 'error'); }
    }
}
</script>

</body>
</html>