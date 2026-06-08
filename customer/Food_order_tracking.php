<?php
session_start();
require_once '../includes/db.php';
if (!isset($_SESSION['customer_id'])) { header("Location: ../auth/login.php?type=customer"); exit(); }
$order_id = (int)($_GET['order_id'] ?? 0);

// Fetch Order with Shop, Customer Coords and Rider Details
$customer_id = $_SESSION['customer_id'];
$stmt = $pdo->prepare("SELECT o.*, s.shop_name, o.latitude as cust_lat, o.longitude as cust_lng, dp.name as rider_name, dp.phone as rider_phone, dp.current_lat as rider_lat, dp.current_lng as rider_lng FROM orders o JOIN shop_owners s ON o.shop_id = s.id LEFT JOIN delivery_partners dp ON o.delivery_boy_id = dp.id WHERE o.id = ? AND o.customer_id = ?");
$stmt->execute([$order_id, $_SESSION['customer_id']]);
$order = $stmt->fetch();
if(!$order) { header("Location: Food_orders.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Track Meal #<?= $order_id ?> — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <!-- Leaflet for Map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        #map { height: 300px; border-radius: 2rem; }
        /* Marker Smooth Animation */
        .leaflet-marker-icon { transition: transform 1s linear !important; }
        .step-line { width: 2px; height: 30px; background: #e2e8f0; margin-left: 15px; }
        .step-line.active { background: #ea580c; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 pb-10">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 py-4 px-6 flex items-center justify-between shadow-sm">
    <a href="Food_orders.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
    <h1 class="text-lg font-black uppercase tracking-tight text-orange-600">Track My Meal</h1>
    <span class="text-[10px] font-black bg-orange-100 text-orange-600 px-3 py-1.5 rounded-full uppercase tracking-widest">Live</span>
</nav>

<main class="p-4 max-w-xl mx-auto">

    <!-- 1. Live Tracking Map -->
    <?php $current_status = $order['order_status']; ?>
    <div id="trackingMapContainer" class="mb-6 <?= in_array($current_status, ['assigned', 'picked_up']) ? '' : 'hidden' ?>">
        <div id="map" class="shadow-lg border border-white"></div>
        <div class="bg-white p-4 rounded-b-[2.5rem] border-x border-b border-slate-100 flex items-center justify-between shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-orange-50 text-orange-600 rounded-full flex items-center justify-center animate-bounce">
                    <i class="fas fa-motorcycle"></i>
                </div>
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

    <!-- 2. Status Card -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 p-8 shadow-sm mb-6">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h3 class="text-xl font-black text-slate-900 leading-none">Order Status</h3>
                <p class="text-[10px] font-bold text-slate-400 uppercase mt-2">#ORD-<?= $order_id ?></p>
            </div>
            <?php if($current_status === 'picked_up'): ?>
                <div id="dccContainer" class="text-right <?= empty($order['delivery_code']) ? 'hidden' : '' ?>">
                    <div class="text-[8px] font-black text-blue-600 uppercase tracking-widest">Security OTP</div>
                    <div id="dccCode" class="text-2xl font-black tracking-[0.1em] text-slate-900"><?= $order['delivery_code'] ?: '------' ?></div>
                </div>
            <?php else: ?>
                <div class="text-right">
                    <div class="text-[8px] font-black text-orange-500 uppercase tracking-widest">Est. Arrival</div>
                    <div class="text-sm font-black">20-25 Mins</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="space-y-0">
            <?php 
            $steps = [
                ['id' => 'pending', 'label' => 'Order Placed', 'icon' => 'fa-receipt'],
                ['id' => 'accepted', 'label' => 'Chef Confirmed', 'icon' => 'fa-fire-burner'],
                ['id' => 'packing', 'label' => 'Preparing your Meal', 'icon' => 'fa-bowl-food'],
                ['id' => 'assigned', 'label' => 'Rider Assigned', 'icon' => 'fa-motorcycle'],
                ['id' => 'ready_for_pickup', 'label' => 'Ready for Pickup', 'icon' => 'fa-bag-shopping'],
                ['id' => 'picked_up', 'label' => 'On the way', 'icon' => 'fa-motorcycle'],
                ['id' => 'delivered', 'label' => 'Enjoy your Meal!', 'icon' => 'fa-check-double']
            ];
            $statuses = ['pending', 'accepted', 'packing', 'assigned', 'ready_for_pickup', 'picked_up', 'delivered'];
            
            foreach($steps as $index => $step):
                $is_done = array_search($current_status, $statuses) >= array_search($step['id'], $statuses);
                $is_last = ($index === count($steps) - 1);
            ?>
                <div class="flex items-start gap-4">
                    <div class="flex flex-col items-center">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs <?= $is_done ? 'bg-orange-600 text-white shadow-lg shadow-orange-100' : 'bg-slate-100 text-slate-300' ?>">
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

    <?php if($order['rider_name']): ?>
    <div class="bg-slate-900 rounded-[2rem] p-6 text-white flex items-center justify-between">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-white/10 rounded-xl flex items-center justify-center text-xl text-orange-400"><i class="fas fa-user-ninja"></i></div>
            <div>
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Delivery Partner</p>
                <h4 class="text-sm font-black"><?= htmlspecialchars($order['rider_name']) ?></h4>
            </div>
        </div>
        <a href="tel:<?= $order['rider_phone'] ?>" class="w-10 h-10 bg-orange-600 rounded-full flex items-center justify-center shadow-lg shadow-orange-900/50"><i class="fas fa-phone-alt text-xs"></i></a>
    </div>
    <?php endif; ?>

    <button onclick="window.location.reload()" class="w-full mt-8 bg-white border border-slate-200 text-slate-400 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-50 transition-all">Refresh Live Status</button>
</main>

<script>
// ── Map & Live Data Logic ──────────────────────────────────────────────
let map, riderMarker, userMarker;
const riderLat = <?= (float)$order['rider_lat'] ?>;
const riderLng = <?= (float)$order['rider_lng'] ?>;
const userLat  = <?= (float)$order['cust_lat'] ?>;
const userLng  = <?= (float)$order['cust_lng'] ?>;
const status   = "<?= $current_status ?>";

if(status === 'assigned' || status === 'picked_up') {
    initMap();
}

function initMap() {
    map = L.map('map', { zoomControl: false }).setView([riderLat || userLat, riderLng || userLng], 15);
    
    L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    const scooterIcon = L.divIcon({
        html: '<div class="bg-orange-600 w-8 h-8 rounded-full border-4 border-white shadow-xl flex items-center justify-center text-white text-[10px]"><i class="fas fa-motorcycle"></i></div>',
        className: '', iconSize: [32, 32]
    });

    const homeIcon = L.divIcon({
        html: '<div class="bg-emerald-600 w-8 h-8 rounded-full border-4 border-white shadow-xl flex items-center justify-center text-white text-[10px]"><i class="fas fa-house"></i></div>',
        className: '', iconSize: [32, 32]
    });

    if(riderLat && riderLng) {
        riderMarker = L.marker([riderLat, riderLng], { icon: scooterIcon }).addTo(map);
    }
    if(userLat && userLng) {
        userMarker = L.marker([userLat, userLng], { icon: homeIcon }).addTo(map);
    }
}

function updateEtaDisplay(etaMin, distKm) {
    const el = document.getElementById('etaDisplay');
    if(el) el.innerText = etaMin <= 5 ? 'Arriving in < 5 min' : `Arriving in ${etaMin} min (${parseFloat(distKm).toFixed(1)} km)`;
}

// Polling interval for real-time updates
setInterval(async () => {
    try {
        const res = await fetch(`ajax_get_order_status.php?order_id=<?= $order_id ?>&customer_id=<?= $customer_id ?>`);
        const data = await res.json();
        
        // If status changed, refresh to update UI state
        if(data.status !== status) window.location.reload();
        
        if(data.success) {
            // Update rider position smoothly
            if(data.rider_lat && data.rider_lng) {
                if(riderMarker) {
                    riderMarker.setLatLng([data.rider_lat, data.rider_lng]);
                } else if(map) {
                    const scooterIcon = L.divIcon({
                        html: '<div class="bg-orange-600 w-8 h-8 rounded-full border-4 border-white shadow-xl flex items-center justify-center text-white text-[10px]"><i class="fas fa-motorcycle"></i></div>',
                        className: '', iconSize: [32, 32]
                    });
                    riderMarker = L.marker([data.rider_lat, data.rider_lng], { icon: scooterIcon }).addTo(map);
                }
                
                if(data.eta && data.distance_km) {
                    updateEtaDisplay(data.eta, data.distance_km);
                }
            }

            // Update Security Code if visible
            if(data.dcc && document.getElementById('dccCode')) {
                document.getElementById('dccCode').innerText = data.dcc;
                document.getElementById('dccContainer').classList.remove('hidden');
            }
        }
    } catch(e){}
}, 15000);
</script>
</body>
</html>
