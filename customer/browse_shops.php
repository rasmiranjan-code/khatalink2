<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';

// Authentication Layer
$customer_id = 0;
$is_api = false;

if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
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

// ── FEATURE LOCK: Marketplace moved to Mall ───────────────────────────────
if(!$is_api):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Coming Soon — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-6">
    <div class="max-w-md w-full bg-white rounded-[3rem] p-10 text-center shadow-2xl border border-slate-100">
        <div class="w-20 h-20 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center text-3xl mx-auto mb-6">
            <i class="fas fa-lock"></i>
        </div>
        <h1 class="text-2xl font-black text-slate-900 uppercase tracking-tight mb-3">Coming Soon</h1>
        <p class="text-slate-500 text-sm leading-relaxed mb-8">
            The Shop Discovery features have moved to the <span class="font-bold text-slate-900">Mall section</span>. We are currently performing maintenance on this module.
        </p>
        <a href="dashboard.php" class="inline-block bg-slate-900 text-white px-8 py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-slate-800 transition-all shadow-lg">
            Back to Dashboard
        </a>
    </div>
</body>
</html>
<?php exit(); endif; ?>

// User Coordinates
$user_lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$user_lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$max_dist = isset($_GET['range']) ? (float)$_GET['range'] : 50; // Default 50km
$search = trim($_GET['search'] ?? '');

/**
 * Haversine formula to calculate distance between two coordinates in KM
 */
function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earth_radius = 6371; // Kilometers
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($earth_radius * $c, 2);
}

// Fetch all verified shops
$sql = "SELECT id, shop_name, shop_category, profile_image, full_address, pincode, latitude, longitude FROM shop_owners WHERE is_verified = 1";
$params = [];

if($search) {
    $sql .= " AND (shop_name LIKE ? OR shop_category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$all_shops = $stmt->fetchAll();

$nearby_shops = [];
foreach($all_shops as $shop) {
    $dist = null;
    if($user_lat && $user_lng && $shop['latitude'] && $shop['longitude']) {
        $dist = calculateDistance($user_lat, $user_lng, (float)$shop['latitude'], (float)$shop['longitude']);
    }
    
    // Range filter if location is available
    if($user_lat && $dist !== null && $dist > $max_dist) continue;

    $shop['distance'] = $dist;
    $nearby_shops[] = $shop;
}

// Sort by distance if location available
if($user_lat) {
    usort($nearby_shops, function($a, $b) {
        return ($a['distance'] ?? 999) <=> ($b['distance'] ?? 999);
    });
}

if($is_api) {
    exit(json_encode(['success'=>true, 'shops'=>$nearby_shops]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Shops — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="text-[10px] font-black text-emerald-600 uppercase tracking-widest bg-emerald-50 border border-emerald-100 px-3 py-1.5 rounded-full">
        <i class="fas fa-location-dot me-1"></i> Shop Discovery
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/customer_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        
        <div class="mb-8">
            <h1 class="text-3xl font-black text-slate-900 tracking-tight">Browse Local Shops</h1>
            <p class="text-slate-500 text-sm">Discover trusted local merchants delivering in your area.</p>
        </div>

        <!-- Location Status & Filters -->
        <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm mb-8">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                <div id="locationStatus" class="flex items-center gap-4 p-4 bg-slate-50 rounded-2xl border border-slate-100">
                    <div class="w-10 h-10 <?= $user_lat ? 'bg-emerald-100 text-emerald-600' : 'bg-amber-100 text-amber-600' ?> rounded-xl flex items-center justify-center">
                        <i class="fas fa-location-arrow <?= !$user_lat ? 'animate-pulse' : '' ?>"></i>
                    </div>
                    <div>
                        <div class="text-[10px] font-black uppercase text-slate-400">Location Services</div>
                        <div class="text-sm font-bold"><?= $user_lat ? 'Precision Tracking Active' : 'Location Not Found' ?></div>
                        <?php if(!$user_lat): ?>
                            <button onclick="getLocation()" class="text-[9px] font-black text-blue-600 uppercase underline mt-1">Enable Location</button>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="GET" id="filterForm" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="lat" id="formLat" value="<?= $user_lat ?>">
                    <input type="hidden" name="lng" id="formLng" value="<?= $user_lng ?>">
                    
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">Search Store/Category</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold outline-none focus:border-blue-500" placeholder="e.g. Kirana, Pharmacy...">
                    </div>

                    <div class="w-32">
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">Distance Range</label>
                        <select name="range" onchange="this.form.submit()" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold outline-none cursor-pointer">
                            <option value="2" <?= $max_dist == 2 ? 'selected' : '' ?>>2 KM</option>
                            <option value="5" <?= $max_dist == 5 ? 'selected' : '' ?>>5 KM</option>
                            <option value="10" <?= $max_dist == 10 ? 'selected' : '' ?>>10 KM</option>
                            <option value="50" <?= $max_dist == 50 ? 'selected' : '' ?>>50 KM</option>
                        </select>
                    </div>

                    <button type="submit" class="bg-slate-900 text-white font-black px-6 py-3 rounded-xl text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all">Search</button>
                </form>
            </div>
        </div>

        <!-- Shops Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if(empty($nearby_shops)): ?>
                <div class="col-span-full text-center py-20 bg-white border border-dashed border-slate-200 rounded-[3rem]">
                    <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-2xl mx-auto mb-4">
                        <i class="fas fa-store-slash"></i>
                    </div>
                    <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No shops found in this area</p>
                </div>
            <?php endif; ?>

            <?php foreach($nearby_shops as $shop): ?>
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 hover:shadow-xl hover:border-blue-500 transition-all flex flex-col group overflow-hidden">
                    <div class="flex justify-between items-start mb-6">
                        <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-xl font-black group-hover:bg-blue-600 group-hover:text-white transition-all">
                            <i class="fas fa-store"></i>
                        </div>
                        <?php if($shop['distance'] !== null): ?>
                            <span class="bg-emerald-50 text-emerald-700 text-[9px] font-black px-3 py-1.5 rounded-lg uppercase tracking-tight">
                                <i class="fas fa-location-arrow mr-1"></i> <?= $shop['distance'] ?> KM away
                            </span>
                        <?php endif; ?>
                    </div>

                    <h3 class="text-lg font-black text-slate-900"><?= htmlspecialchars($shop['shop_name']) ?></h3>
                    <p class="text-[10px] font-black text-blue-500 uppercase tracking-widest mb-4"><?= htmlspecialchars($shop['shop_category']) ?></p>
                    
                    <div class="mt-auto pt-6 border-t border-slate-50">
                        <div class="flex items-center gap-2 mb-6">
                            <i class="fas fa-map-pin text-slate-300 text-xs"></i>
                            <p class="text-[11px] text-slate-500 font-medium truncate"><?= htmlspecialchars($shop['full_address']) ?></p>
                        </div>
                        
                        <a href="create_order.php?shop_id=<?= $shop['id'] ?>" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all flex items-center justify-center gap-2 text-[10px] uppercase tracking-widest shadow-lg shadow-slate-100">
                            <i class="fas fa-shopping-basket"></i> Create Order
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>

<script>
function getLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(position => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            const url = new URL(window.location.href);
            if (url.searchParams.get('lat') != lat) {
                url.searchParams.set('lat', lat);
                url.searchParams.set('lng', lng);
                window.location.href = url.toString();
            }
        }, (error) => {
            console.error("Geolocation error:", error);
        });
    }
}

// Auto-fetch location if missing on web
window.onload = function() {
    const params = new URLSearchParams(window.location.search);
    if (!params.has('lat')) {
        getLocation();
    }
}

function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }
</script>
</body>
</html>
