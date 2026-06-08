<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';

// ── Authentication ────────────────────────────────────────────────────────────
$customer_id = 0;
$is_api      = false;

if(
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp'
) {
    $is_api      = true;
    $token       = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $decoded     = base64_decode($token);
    $parts       = explode(':', $decoded);
    $customer_id = (int)($parts[0] ?? 0);
} else {
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if(!$customer_id) {
    if($is_api) {
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
    }
    header("Location: ../auth/login.php?type=customer"); exit();
}

$order_id = (int)($_GET['order_id'] ?? 0);
if($order_id <= 0) {
    if($is_api) {
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'message' => 'Invalid order ID']));
    }
    die("Invalid Order ID provided.");
}

// ── Fetch Order ───────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        o.*,
        s.shop_name, s.shop_category,
        s.full_address AS shop_address,
        s.latitude     AS shop_lat,
        s.longitude    AS shop_lng,
        dp.name        AS db_name,
        dp.phone       AS db_phone,
        dp.current_lat,
        dp.current_lng
    FROM orders o
    JOIN shop_owners s ON o.shop_id = s.id
    LEFT JOIN delivery_partners dp ON o.delivery_boy_id = dp.id
    WHERE o.id = ? AND o.customer_id = ?
");
$stmt->execute([$order_id, $customer_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$order) {
    if($is_api) {
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'message' => 'Order not found']));
    }
    die("Order not found.");
}

// Flutter → pure JSON response
if($is_api) {
    header('Content-Type: application/json');
    exit(json_encode(['success' => true, 'order' => $order]));
}

// ── Helpers for HTML ──────────────────────────────────────────────────────────
$statuses    = ['pending','accepted','assigned','picked_up','delivered'];
$current_idx = (int)array_search($order['order_status'], $statuses);

$steps = [
    ['label' => 'Order Placed',                 'icon' => 'fa-receipt'],
    ['label' => 'Accepted by Shop',             'icon' => 'fa-check-circle'],
    ['label' => 'Delivery Partner Assigned',    'icon' => 'fa-motorcycle'],
    ['label' => 'Picked Up / On the Way',       'icon' => 'fa-truck-fast'],
    ['label' => 'Delivered',                    'icon' => 'fa-house-circle-check'],
];

// Safe JS values — json_encode ensures XSS-safe output
$js_user_lat = json_encode((float)($order['latitude']    ?? 0));
$js_user_lng = json_encode((float)($order['longitude']   ?? 0));
$js_db_lat   = json_encode((float)($order['current_lat'] ?? $order['shop_lat'] ?? 0));
$js_db_lng   = json_encode((float)($order['current_lng'] ?? $order['shop_lng'] ?? 0));
$js_order_id = json_encode($order_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?= $order_id ?> — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <!-- Leaflet Routing Machine CSS (real road routing) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css"/>

    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; }

        /* ── Map container ── */
        #liveMap {
            height: 380px !important;
            width: 100%;
            border-radius: 1.5rem;
            z-index: 10;
            background: #e8f4f8;
        }
        .leaflet-container { border-radius: 1.5rem; }

        /* Hide default routing machine UI panel — we only want the road line */
        .leaflet-routing-container { display: none !important; }

        /* ── Scooter pulse ring ── */
        .scooter-pulse {
            position: relative;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .scooter-pulse::before {
            content: '';
            position: absolute;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.25);
            animation: pulseRing 1.8s ease-out infinite;
        }
        @keyframes pulseRing {
            0%   { transform: scale(0.6); opacity: 1; }
            100% { transform: scale(1.8); opacity: 0; }
        }

        /* ── ETA badge shimmer when calculating ── */
        @keyframes shimmer {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.5; }
        }
        .eta-calculating { animation: shimmer 1.4s ease-in-out infinite; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<!-- ── Navbar ── -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center px-6 gap-4 shadow-sm">
    <a href="dashboard.php" class="w-9 h-9 flex items-center justify-center rounded-xl bg-slate-100 text-slate-500 hover:bg-blue-50 hover:text-blue-600 transition-all">
        <i class="fas fa-arrow-left text-sm"></i>
    </a>
    <div>
        <h2 class="text-sm font-black uppercase tracking-widest leading-none">Order Tracking</h2>
        <p class="text-[10px] text-slate-400 font-bold">Order #<?= $order_id ?></p>
    </div>
</nav>

<main class="p-4 md:p-8 max-w-2xl mx-auto">

    <!-- Success Banner -->
    <?php if(isset($_GET['success'])): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-[1.5rem] p-5 text-sm font-bold mb-6 flex items-center gap-3 shadow-sm">
        <i class="fas fa-check-circle text-lg text-emerald-500"></i>
        <?= htmlspecialchars($_GET['success']) ?>
    </div>
    <?php endif; ?>

    <!-- ── Status Card ── -->
    <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-xl mb-6 overflow-hidden relative">

        <div class="flex justify-between items-start mb-8">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-slate-900">Order #<?= $order_id ?></h1>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-0.5">
                    <?= htmlspecialchars($order['shop_name']) ?>
                </p>
            </div>
            <?php
            $statusColors = [
                'pending'   => 'bg-amber-50 text-amber-600 border-amber-200',
                'accepted'  => 'bg-blue-50 text-blue-600 border-blue-200',
                'assigned'  => 'bg-purple-50 text-purple-600 border-purple-200',
                'picked_up' => 'bg-indigo-50 text-indigo-600 border-indigo-200',
                'delivered' => 'bg-emerald-50 text-emerald-600 border-emerald-200',
            ];
            $sc = $statusColors[$order['order_status']] ?? 'bg-slate-50 text-slate-600 border-slate-200';
            ?>
            <span class="<?= $sc ?> text-[10px] font-black px-4 py-2 rounded-full uppercase tracking-widest border">
                <?= htmlspecialchars(str_replace('_', ' ', $order['order_status'])) ?>
            </span>
        </div>

        <!-- ── LIVE MAP — only when picked_up ── -->
        <?php if($order['order_status'] === 'picked_up'): ?>
        <div class="mb-8 relative">

            <!-- Map -->
            <div id="liveMap" style="height:380px !important;"></div>

            <!-- Live Badge (top-left over map) -->
            <div class="absolute top-3 left-3 z-[500] bg-white/95 backdrop-blur-sm px-3 py-1.5 rounded-full border border-slate-200 shadow-md flex items-center gap-2 pointer-events-none">
                <span class="w-2 h-2 bg-emerald-500 rounded-full animate-ping"></span>
                <span class="text-[9px] font-black uppercase tracking-widest text-slate-800">Live Tracking</span>
            </div>

            <!-- Recenter button (top-right) -->
            <button onclick="recenterMap()" class="absolute top-3 right-3 z-[500] w-9 h-9 bg-white/95 backdrop-blur-sm border border-slate-200 shadow-md rounded-xl flex items-center justify-center text-slate-600 hover:text-blue-600 transition-colors">
                <i class="fas fa-crosshairs text-sm"></i>
            </button>

            <!-- ETA Badge (bottom center, over map) -->
            <div class="absolute bottom-4 left-1/2 -translate-x-1/2 z-[500] bg-slate-900/95 backdrop-blur-sm text-white px-5 py-3 rounded-2xl shadow-2xl flex items-center gap-3 whitespace-nowrap border border-white/10 pointer-events-none">
                <i class="fas fa-clock text-blue-400 text-sm" id="etaIcon"></i>
                <div>
                    <p class="text-[8px] font-black uppercase tracking-widest text-blue-400 leading-none mb-1">Estimated Arrival</p>
                    <p class="text-sm font-black leading-none eta-calculating" id="etaText">Calculating...</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Status Steps ── -->
        <div class="space-y-6 relative">
            <?php foreach($steps as $idx => $step):
                $done    = $idx <= $current_idx;
                $current = $idx === $current_idx;
                $future  = $idx > $current_idx;
            ?>
            <div class="flex items-center gap-5 relative z-10">
                <!-- Icon circle -->
                <div class="relative flex-shrink-0">
                    <div class="w-11 h-11 rounded-2xl flex items-center justify-center text-base transition-all duration-300
                        <?= $done ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'bg-slate-100 text-slate-300' ?>
                        <?= $current ? 'ring-4 ring-blue-100' : '' ?>">
                        <i class="fas <?= $step['icon'] ?>"></i>
                    </div>
                    <?php if($current): ?>
                    <span class="absolute -top-1 -right-1 w-3 h-3 bg-emerald-500 rounded-full border-2 border-white animate-pulse"></span>
                    <?php endif; ?>
                </div>
                <!-- Label -->
                <div class="flex-1">
                    <p class="text-xs font-black uppercase tracking-widest <?= $done ? 'text-slate-900' : 'text-slate-300' ?>">
                        <?= $step['label'] ?>
                    </p>
                    <?php if($current): ?>
                    <p class="text-[10px] text-blue-500 font-bold mt-0.5">Currently in this stage</p>
                    <?php endif; ?>
                </div>
                <!-- Checkmark for done non-current -->
                <?php if($done && !$current): ?>
                <i class="fas fa-check text-blue-400 text-xs flex-shrink-0"></i>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <!-- Vertical progress line -->
            <div class="absolute left-[21px] top-0 bottom-0 w-0.5 bg-slate-100 -z-10"></div>
            <?php if($current_idx > 0): ?>
            <div class="absolute left-[21px] top-0 w-0.5 bg-blue-200 -z-10"
                 style="height: calc(<?= $current_idx ?> * (44px + 24px) + 22px)"></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Delivery Security Code (Show only if generated) ── -->
    <?php if($order['order_status'] === 'picked_up' && !empty($order['delivery_code'])): ?>
    <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-2xl mb-6 relative overflow-hidden">
        <div class="relative z-10 text-center">
            <div class="text-5xl font-black tracking-[0.25em] mb-4 text-white"><?= htmlspecialchars($order['delivery_code']) ?></div>
            <p class="text-xs text-slate-400">Saaman milne par ye code delivery boy ko dein.</p>
        </div>
    </div>
    <?php elseif($order['order_status'] === 'picked_up'): ?>
    <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 text-center mb-6">
        <p class="text-slate-400 text-xs font-bold uppercase italic">DCC generated when partner reaches you...</p>
    </div>
    <?php endif; ?>

    <!-- Cancel Request Button -->
    <?php if(in_array($order['order_status'], ['pending', 'accepted', 'assigned'])): ?>
    <button onclick="requestCancel(<?= $order_id ?>)" class="w-full bg-red-50 text-red-600 font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest border border-red-100 hover:bg-red-600 hover:text-white transition-all mb-8">
        Request Cancellation
    </button>
    <?php endif; ?>

    <!-- ── Delivery Partner Card ── -->
    <?php if(!empty($order['delivery_boy_id'])): ?>
    <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 shadow-sm mb-6">
        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Delivery Partner</h3>
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-gradient-to-br from-blue-50 to-indigo-100 text-blue-600 rounded-2xl flex items-center justify-center text-xl shadow-sm">
                    <i class="fas fa-user-ninja"></i>
                </div>
                <div>
                    <p class="text-sm font-black text-slate-900"><?= htmlspecialchars($order['db_name'] ?? '') ?></p>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>
                        <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">Verified Partner</p>
                    </div>
                </div>
            </div>
            <a href="tel:<?= htmlspecialchars($order['db_phone'] ?? '') ?>"
               class="flex items-center gap-2 bg-slate-900 text-white px-4 py-2.5 rounded-2xl shadow-lg hover:bg-slate-800 transition-colors text-xs font-bold">
                <i class="fas fa-phone-alt text-xs"></i>
                Call
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Order Summary ── -->
    <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm mb-6">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-[10px] font-black text-slate-900 uppercase tracking-widest">Order Summary</h3>
            <span class="text-[9px] font-black bg-slate-100 px-3 py-1 rounded-lg uppercase text-slate-500">
                <?= htmlspecialchars($order['payment_mode']) ?> Payment
            </span>
        </div>
        <div class="p-6">
            <?php if($order['order_status'] !== 'pending'): ?>
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wide">Items Total</span>
                    <span class="text-sm font-black text-slate-900">₹<?= number_format($order['net_to_shop'], 2) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wide">Delivery Fee</span>
                    <span class="text-sm font-black text-blue-600">₹<?= number_format($order['delivery_fee'], 2) ?></span>
                </div>
                <div class="pt-4 border-t border-dashed border-slate-200 flex justify-between items-center">
                    <span class="text-sm font-black uppercase tracking-wide">Amount to Pay</span>
                    <span class="text-xl font-black text-slate-900">₹<?= number_format($order['total_amount'], 2) ?></span>
                </div>
            </div>
            <?php else: ?>
            <div class="text-center py-6">
                <div class="w-12 h-12 bg-amber-50 rounded-2xl flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-clock text-amber-500"></i>
                </div>
                <div class="text-xs font-bold text-amber-600 uppercase tracking-widest mb-1">Pricing in Progress</div>
                <p class="text-[11px] text-slate-400 max-w-xs mx-auto">Dukandaar aapki list check karke final rate set kar raha hai.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-center pb-10">
        <p class="text-[10px] font-bold text-slate-300 uppercase tracking-[0.25em]">KhataLink Hyperlocal Network</p>
    </div>
</main>

<!-- ── Leaflet JS ── -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- Leaflet Routing Machine — real road routing via OSRM (FREE, no API key needed) -->
<script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.min.js"></script>

<!-- ── Tracking Script ── -->
<?php if($order['order_status'] === 'picked_up'): ?>
<script>
// ── PHP → JS (safe via json_encode) ──────────────────────────────────────────
let userLat  = <?= $js_user_lat ?>;
let userLng  = <?= $js_user_lng ?>;
let dbLat    = <?= $js_db_lat ?>;
let dbLng    = <?= $js_db_lng ?>;
const orderId = <?= $js_order_id ?>;

// ── Map center — delivery boy first, else customer, else India center ─────────
const startLat = (dbLat !== 0) ? dbLat : (userLat !== 0 ? userLat : 20.5937);
const startLng = (dbLng !== 0) ? dbLng : (userLng !== 0 ? userLng : 78.9629);

// ── Init Map — zoom 17 for max road/building label detail ─────────────────────
const map = L.map('liveMap', {
    zoomControl:      true,
    attributionControl: true,
    tap: false  // iOS fix
}).setView([startLat, startLng], 17);

// ── DETAILED TILE LAYER ───────────────────────────────────────────────────────
// OpenStreetMap HOT tiles — best free option for India
// Road names, building names, lane labels, area names — all visible at zoom 16+
L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 20,
    minZoom: 10,
    // Crossorigin needed for some browsers
    crossOrigin: true
}).addTo(map);

// Invalidate size — fixes blank map in overflow/rounded containers
setTimeout(() => map.invalidateSize(), 100);
setTimeout(() => map.invalidateSize(), 500);

// ── Custom Icons ──────────────────────────────────────────────────────────────
const scooterIcon = L.divIcon({
    html: `<div class="scooter-pulse">
               <span style="font-size:26px;line-height:1;position:relative;z-index:1;filter:drop-shadow(0 2px 6px rgba(37,99,235,0.7));">🛵</span>
           </div>`,
    className: '',
    iconSize:   [42, 42],
    iconAnchor: [21, 21],
    popupAnchor:[0, -24]
});

const homeIcon = L.divIcon({
    html: `<div style="
        font-size:28px;line-height:1;
        filter:drop-shadow(0 3px 8px rgba(239,68,68,0.6));
        transform:translateY(-4px);
    ">🏠</div>`,
    className: '',
    iconSize:   [34, 38],
    iconAnchor: [17, 38],
    popupAnchor:[0, -38]
});

// ── Markers ───────────────────────────────────────────────────────────────────
let deliveryMarker = null;
let customerMarker = null;
let routingControl = null;  // Leaflet Routing Machine instance

if(dbLat !== 0 && dbLng !== 0) {
    deliveryMarker = L.marker([dbLat, dbLng], {icon: scooterIcon})
        .addTo(map)
        .bindPopup('<b style="font-family:Inter,sans-serif">🛵 Delivery Partner</b><br><span style="font-size:11px;color:#64748b">Live Location</span>');
}

function addCustomerMarker(lat, lng, label) {
    customerMarker = L.marker([lat, lng], {icon: homeIcon})
        .addTo(map)
        .bindPopup('<b style="font-family:Inter,sans-serif">🏠 ' + label + '</b>');
}

if(userLat !== 0 && userLng !== 0) {
    addCustomerMarker(userLat, userLng, 'Your Location');
    fitMarkers();
    if(dbLat !== 0) drawRoadRoute(dbLat, dbLng, userLat, userLng);
} else {
    // Browser GPS fallback
    if(navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(pos) {
            userLat = pos.coords.latitude;
            userLng = pos.coords.longitude;
            addCustomerMarker(userLat, userLng, 'Your Current Location');
            fitMarkers();
            if(dbLat !== 0) drawRoadRoute(dbLat, dbLng, userLat, userLng);
        }, function() {
            setEta('⚠ Location unavailable', false);
        });
    }
}

// ── Fit both markers in viewport ──────────────────────────────────────────────
function fitMarkers() {
    const markers = [deliveryMarker, customerMarker].filter(Boolean);
    if(markers.length === 2) {
        const group = L.featureGroup(markers);
        const bounds = group.getBounds();
        // Only fit if markers are meaningfully far apart
        if(bounds.getNorthEast().distanceTo(bounds.getSouthWest()) > 100) {
            map.fitBounds(bounds.pad(0.35));
        } else {
            // Same location or very close → just center with good zoom
            map.setView([dbLat || userLat, dbLng || userLng], 17);
        }
    } else if(markers.length === 1) {
        map.setView(markers[0].getLatLng(), 17);
    }
}

// ── Recenter button ───────────────────────────────────────────────────────────
function recenterMap() {
    fitMarkers();
}

// ── REAL ROAD ROUTING via OSRM (FREE, no API key) ────────────────────────────
// This draws the actual road path — not a straight line
function drawRoadRoute(dLat, dLng, uLat, uLng) {
    if(!dLat || !uLat || dLat === 0 || uLat === 0) return;

    // Remove old routing if exists
    if(routingControl) {
        map.removeControl(routingControl);
        routingControl = null;
    }

    // Check if same/very close coordinates
    const dist = Math.sqrt(Math.pow(dLat - uLat, 2) + Math.pow(dLng - uLng, 2));
    if(dist < 0.0002) {
        // Too close — no route needed
        return;
    }

    routingControl = L.Routing.control({
        waypoints: [
            L.latLng(dLat, dLng),   // Delivery boy position
            L.latLng(uLat, uLng)    // Customer position
        ],
        router: L.Routing.osrmv1({
            serviceUrl: 'https://router.project-osrm.org/route/v1',
            profile:    'driving'   // 'driving' | 'walking' | 'cycling'
        }),
        lineOptions: {
            styles: [
                // Outer glow/shadow
                { color: '#1d4ed8', opacity: 0.2, weight: 10 },
                // Main route line — blue, solid
                { color: '#2563eb', opacity: 0.85, weight: 5 },
                // Inner highlight
                { color: '#60a5fa', opacity: 0.5, weight: 2 }
            ],
            addWaypoints: false,   // Don't allow drag to add stops
            missingRouteTolerance: 10
        },
        addWaypoints:  false,
        draggableWaypoints: false,
        fitSelectedRoutes: false,   // We handle fitting manually
        showAlternatives: false,
        createMarker: function() { return null; } // Hide default A/B markers
    }).addTo(map);

    // Optional: listen for route found to update ETA from actual road distance
    routingControl.on('routesfound', function(e) {
        const route = e.routes[0];
        const roadDistKm  = route.summary.totalDistance / 1000;   // metres → km
        const roadTimeSec = route.summary.totalTime;               // seconds
        const roadTimeMin = Math.max(5, Math.ceil(roadTimeSec / 60));

        // Update ETA with actual road-based time
        updateEtaDisplay(roadDistKm, roadTimeMin);
    });

    routingControl.on('routingerror', function() {
        // OSRM failed (network issue?) — fallback to straight-line ETA
        console.warn('OSRM routing failed, using straight-line ETA');
    });
}

// ── ETA Display Logic ─────────────────────────────────────────────────────────
function updateEtaDisplay(distKm, etaMin) {
    const el   = document.getElementById('etaText');
    const icon = document.getElementById('etaIcon');

    // Remove shimmer once we have data
    el.classList.remove('eta-calculating');

    if(distKm < 0.05) {
        // Coordinates same or <50m apart
        el.innerText  = 'Reached your area 📍';
        icon.className = 'fas fa-map-pin text-emerald-400 text-sm';
    } else if(distKm < 0.15) {
        el.innerText  = 'Arriving now 🛵';
        icon.className = 'fas fa-motorcycle text-blue-400 text-sm';
    } else if(etaMin <= 5) {
        el.innerText  = 'Less than 5 min';
        icon.className = 'fas fa-clock text-blue-400 text-sm';
    } else {
        el.innerText  = etaMin + ' min away · ' + distKm.toFixed(1) + ' km';
        icon.className = 'fas fa-clock text-blue-400 text-sm';
    }
}

function setEta(text, shimmer) {
    const el = document.getElementById('etaText');
    el.innerText = text;
    if(shimmer) el.classList.add('eta-calculating');
    else         el.classList.remove('eta-calculating');
}

// ── Live Polling ──────────────────────────────────────────────────────────────
let pollTimer      = null;
let lastDbLat      = dbLat;
let lastDbLng      = dbLng;
let consecutiveFails = 0;

async function updateTracking() {
    try {
        const res  = await fetch('ajax_get_delivery_location.php?order_id=' + orderId);
        const data = await res.json();

        if(data.success && data.lat && data.lng) {
            consecutiveFails = 0;
            const newPos = L.latLng(data.lat, data.lng);

            // Create or move delivery marker
            if(!deliveryMarker) {
                deliveryMarker = L.marker(newPos, {icon: scooterIcon}).addTo(map);
            } else {
                deliveryMarker.setLatLng(newPos);
            }

            // Redraw road route if delivery boy moved significantly (>10m)
            const moved = Math.sqrt(
                Math.pow(data.lat - lastDbLat, 2) +
                Math.pow(data.lng - lastDbLng, 2)
            );
            if(moved > 0.0001 && userLat !== 0) {
                drawRoadRoute(data.lat, data.lng, userLat, userLng);
                lastDbLat = data.lat;
                lastDbLng = data.lng;
            }

            // ETA from AJAX (straight-line fallback if OSRM not responded)
            if(data.distance_km !== undefined) {
                updateEtaDisplay(data.distance_km, data.eta);
            }

            // Auto-pan if scooter left the viewport
            if(!map.getBounds().contains(newPos)) {
                map.panTo(newPos, { animate: true, duration: 1.2 });
            }

            // Delivered → stop polling and reload
            if(data.status === 'delivered') {
                clearInterval(pollTimer);
                setEta('Delivered ✅', false);
                setTimeout(() => location.reload(), 2500);
            }

        } else {
            consecutiveFails++;
            if(consecutiveFails >= 3) {
                setEta('Locating partner...', true);
            }
        }
    } catch(e) {
        console.error('Tracking Error:', e);
        consecutiveFails++;
    }
}

// Fire immediately, then every 15 seconds
updateTracking();
pollTimer = setInterval(updateTracking, 15000);
</script>

<?php else: ?>
<script>
    // Auto-refresh every 30s for non-tracking statuses
    setTimeout(() => location.reload(), 30000);
</script>
<?php endif; ?>

<!-- ── Firebase Push Notifications ── -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>
<script>
if(!firebase.apps.length) {
    firebase.initializeApp({
        apiKey:            "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
        authDomain:        "khatalink-63041.firebaseapp.com",
        projectId:         "khatalink-63041",
        messagingSenderId: "905429197043",
        appId:             "1:905429197043:web:2a0cbefa0fa176fd2c5786"
    });
}

const messaging = firebase.messaging();

async function syncFCMToken() {
    try {
        const reg = await navigator.serviceWorker.register('../firebase-messaging-sw.js');
        await navigator.serviceWorker.ready;
        const token = await messaging.getToken({
            vapidKey: 'BGixP4kke2vi5l1mpqb_P-GI5xh2OM4KcPQ_8lzQmJqvdJXHG4xFpkYvexfpD_lX7LvBQ1ORR3asE1LQkFeWFHo',
            serviceWorkerRegistration: reg
        });
        // Token milga — backend pe save karna ho toh yahan AJAX se bhejo
    } catch(e) { console.error('FCM Sync Error:', e); }
}
syncFCMToken();

messaging.onMessage(function(payload) {
    if(Notification.permission !== 'granted') return;
    var title = (payload.notification && payload.notification.title) || 'Order Update';
    var opts  = {
        body:  (payload.notification && payload.notification.body) || '',
        icon:  '../assets/favicon.png',
    };
    if(payload.notification && payload.notification.image) {
        opts.image = payload.notification.image;
    }
    var n = new Notification(title, opts);
    n.onclick = function() { window.focus(); n.close(); };
});
</script>

</body>
</html>