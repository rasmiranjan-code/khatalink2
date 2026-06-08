<?php
ob_start();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');

// ── 1. API DETECTION ──
$is_api = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp');

if ($is_api) {
    error_reporting(0);
    ini_set('display_errors', 0);
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

// Fetch customer details
$stmt_c = $pdo->prepare("SELECT name, pincode FROM customers WHERE id = ?");
$stmt_c->execute([$customer_id]);
$customer = $stmt_c->fetch();

$lat      = (float)($_GET['lat']      ?? 0);
$lng      = (float)($_GET['lng']      ?? 0);
$search   = trim($_GET['search']      ?? '');
$category = trim($_GET['category']    ?? '');
$radius   = (isset($_GET['radius']) && (int)$_GET['radius'] > 0) ? (int)$_GET['radius'] : 6;

$now_time = date('H:i:s');
$now_dt   = date('Y-m-d H:i:s');

// ── 2. FETCH FOOD ITEMS (Zomato Style) ──
// Logic: 6km radius, items from online & verified restaurants
$query = "SELECT m.*, s.shop_name, s.latitude AS shop_lat, s.longitude AS shop_lng, s.average_rating AS shop_rating
          FROM restaurant_menu_items m
          JOIN shop_owners s ON m.shop_id = s.id
          WHERE s.shop_type = 'restaurant' AND s.is_verified = 1 AND s.is_online = 1 AND m.is_available = 1";
$params = []; // Time restrictions removed, starting with fresh params

if($search) {
    $query .= " AND (m.item_name LIKE ? OR s.shop_name LIKE ? OR m.category LIKE ?)";
    $search_p = "%$search%"; array_push($params, $search_p, $search_p, $search_p);
}

if($category && $category !== 'All') {
    $query .= " AND m.category LIKE ?";
    $params[] = "%$category%";
}

if($lat != 0 && $lng != 0) {
    $lat_offset = $radius / 111.0;
    $lng_offset = $radius / (111.0 * cos(deg2rad($lat)));
    $query .= " AND s.latitude BETWEEN ? AND ? AND s.longitude BETWEEN ? AND ?";
    array_push($params, $lat - $lat_offset, $lat + $lat_offset, $lng - $lng_offset, $lng + $lng_offset);
}

$query .= " ORDER BY m.created_at DESC LIMIT 50";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$food_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── ETA Calculation (for Navbar) ──
$eta_label = "15-25 mins"; // Default
if(!empty($food_items) && $lat != 0) {
    $first_item = $food_items[0];
    $deg_dist = abs($first_item['shop_lat'] - $lat) + abs($first_item['shop_lng'] - $lng);
    $km_approx = $deg_dist * 111;
    $minutes = ceil(($km_approx * 3) + 7); // Rough estimate
    if($km_approx < 0.1)    $eta_label = "7-10 mins";
    elseif($minutes < 12)   $eta_label = "8-12 mins";
    elseif($minutes < 25)   $eta_label = "$minutes-" . ($minutes+5) . " mins";
    else                    $eta_label = "Under 40 mins";
} elseif ($lat == 0) {
    $eta_label = "Fast Delivery"; // If location not found
}


// Fetch Banners specifically for Food
$banners_stmt = $pdo->prepare("SELECT image_path FROM banners WHERE target IN ('food','all') ORDER BY created_at DESC LIMIT 5");
$banners_stmt->execute();
$banner_list = $banners_stmt->fetchAll();

$cuisine_map = [
    'Biryani' => 'https://cdn-icons-png.flaticon.com/512/706/706164.png',
    'Pizza' => 'https://cdn-icons-png.flaticon.com/512/3595/3595455.png',
    'Burger' => 'https://cdn-icons-png.flaticon.com/512/3075/3075977.png',
    'Chinese' => 'https://cdn-icons-png.flaticon.com/512/2718/2718224.png',
    'Thali' => 'https://cdn-icons-png.flaticon.com/512/3449/3449332.png',
    'Bakery' => 'https://cdn-icons-png.flaticon.com/512/3014/3014534.png',
    'Fast Food' => 'https://cdn-icons-png.flaticon.com/512/2737/2737081.png',
    'South Indian' => 'https://cdn-icons-png.flaticon.com/512/5029/5029191.png'
];

if($is_api) {
    ob_clean();
    exit(json_encode(['success'=>true, 'food_items'=>$food_items, 'banners'=>$banner_list]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Food Delivery — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; overflow-x: hidden; }
        #stickyNav { position: fixed; top: 0; left: 0; right: 0; z-index: 9999; background: #fff; border-bottom: 1px solid #f1f5f9; box-shadow: 0 1px 8px rgba(0,0,0,0.06); }
        #pageContent { margin-top: 135px; }
        @media (min-width: 768px) { #pageContent { margin-top: 85px; } }
        .loc-loading { animation: pulse 1.2s ease-in-out infinite; color: #cbd5e1 !important; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
        .food-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .food-card:hover { transform: translateY(-6px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .eta-badge { background: #facc15; color: #000; font-size: 8px; font-weight: 900; padding: 2px 6px; border-radius: 6px; text-transform: uppercase; }
    </style>
</head>
<body>

<nav id="stickyNav">
    <div class="max-w-7xl mx-auto px-4 h-[70px] flex items-center gap-4">
        <a href="dashboard.php" style="flex-shrink:0; display:flex; align-items:center;">
            <img src="https://i.ibb.co/2Yhj4MhP/Chat-GPT-Image-May-17-2026-02-36-06-AM.png" alt="KhataLink" style="height:48px;">
        </a>
        <div class="hidden md:block w-px h-10 bg-slate-100 mx-2"></div>
        <div class="hidden md:flex flex-col min-w-0 cursor-pointer flex-shrink-0" onclick="getLocation()">
            <div><span class="eta-badge" id="dEta"><?= $eta_label ?></span></div>
            <span class="text-[9px] font-black uppercase text-orange-600 tracking-widest">Delivering to</span>
            <div class="flex items-center gap-1">
                <i class="fas fa-location-dot text-orange-500 text-xs"></i>
                <span id="dVillage" class="text-sm font-black truncate max-w-[150px] loc-loading">Detecting...</span>
                <i class="fas fa-chevron-down text-[8px] text-slate-400"></i>
            </div>
            <span id="dBlockDist" class="text-[10px] font-bold text-slate-400 truncate max-w-[150px] mt-1 hidden"></span>
        </div>
        <div class="hidden md:flex items-center gap-2 bg-slate-50 border border-slate-100 px-3 py-2 rounded-xl flex-shrink-0">
            <i class="far fa-clock text-slate-400 text-[10px]"></i>
            <span id="dClock" class="text-[11px] font-black text-slate-600 tabular-nums">00:00:00</span>
        </div>
        <div class="flex-1 relative">
            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-sm"></i>
            <input type="text" id="searchInput" value="<?= htmlspecialchars($search) ?>"
                onkeydown="if(event.key==='Enter') window.location.href='Food_home.php?search='+encodeURIComponent(this.value)+'&lat='+currentLat+'&lng='+currentLng+'&radius='+currentRadius;"
                class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl py-2.5 pl-10 pr-4 text-sm font-bold outline-none focus:border-orange-500 focus:bg-white transition-all shadow-sm" 
                placeholder="Search food or restaurant...">
        </div>
        <div class="flex items-center gap-2">
            <a href="Food_orders.php" class="w-11 h-11 bg-slate-50 text-slate-400 rounded-2xl flex items-center justify-center border border-slate-100 hover:text-orange-500 transition-all">
                <i class="fas fa-history text-sm"></i>
            </a>
            <a href="Food_cart.php" class="relative w-11 h-11 bg-orange-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-orange-100 transition-all active:scale-90">
                <i class="fas fa-shopping-bag text-sm"></i>
                <span id="cartBadge" class="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[8px] font-black w-5 h-5 rounded-full flex items-center justify-center border-2 border-white hidden">0</span>
            </a>
        </div>
    </div>
    <div class="md:hidden px-4 pb-3 flex items-center gap-3 border-t border-slate-100 pt-2">
         <div class="flex flex-col flex-shrink-0 min-w-0" line-height="1">
            <span class="text-[7px] font-black uppercase text-orange-600 tracking-widest leading-none mb-1" id="mEta"><?= $eta_label ?></span>
            <div class="flex items-center gap-1" onclick="getLocation()">
                <i class="fas fa-location-dot text-orange-500 text-[10px]"></i>
                <span id="mVillage" class="text-xs font-black truncate max-w-[100px] loc-loading">Detecting...</span>
                <i class="fas fa-chevron-down text-[8px] text-slate-300"></i>
            </div>
            <span id="mBlockDist" class="text-[8px] font-bold text-slate-400 truncate max-w-[100px] hidden"></span>
        </div>
        <div class="w-px h-8 bg-slate-100"></div>
        <span id="mClock" class="text-[10px] font-black text-slate-400 tabular-nums">00:00:00</span>
        <div class="w-px h-8 bg-slate-100"></div>
        <div class="flex-1 text-[9px] font-bold text-slate-400 uppercase truncate">Free delivery on 1st order!</div>
    </div>
</nav>

<main id="pageContent" class="max-w-7xl mx-auto p-4 md:p-8">

    <!-- 1. Banners -->
    <?php if(!empty($banner_list)): ?>
    <div class="relative w-full overflow-hidden rounded-[2.5rem] shadow-xl mb-10 aspect-[4/1] bg-slate-200">
        <div id="bannerTrack" class="flex h-full transition-transform duration-700 ease-in-out" style="width: <?= count($banner_list)*100 ?>%;">
            <?php foreach($banner_list as $bl): ?>
            <div class="h-full flex-shrink-0 w-full">
                <img src="../<?= htmlspecialchars($bl['image_path']) ?>" class="w-full h-full object-cover">
            </div>
            <?php endforeach; ?>
        </div>
        <div class="absolute bottom-4 left-0 right-0 flex justify-center gap-2">
            <?php foreach($banner_list as $i=>$bl): ?>
                <div class="banner-dot w-2 h-2 rounded-full bg-white/40 transition-all <?= $i===0?'!bg-white !w-6':'' ?>"></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2. Cuisines -->
    <div class="mb-12">
        <h2 class="text-xs font-black uppercase tracking-[0.2em] text-slate-400 mb-6">In the Mood for?</h2>
        <div class="flex gap-4 overflow-x-auto pb-4 no-scrollbar">
            <?php foreach($cuisine_map as $c_name => $c_icon): ?>
            <a href="?category=<?= urlencode($c_name) ?>&lat=<?= $lat ?>&lng=<?= $lng ?>" class="flex flex-col items-center gap-3 shrink-0 group">
                <div class="w-20 h-20 bg-white border border-slate-100 rounded-full flex items-center justify-center p-4 shadow-sm group-hover:border-orange-500 transition-all">
                    <img src="<?= $c_icon ?>" class="w-full h-full object-contain grayscale group-hover:grayscale-0 transition-all">
                </div>
                <span class="text-[10px] font-black uppercase tracking-tight text-slate-500 group-hover:text-orange-600"><?= $c_name ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 3. Food Items List (Zomato Style) -->
    <div class="flex items-center justify-between mb-8">
        <h2 class="text-xl font-black text-slate-900 tracking-tight uppercase">Top Dishes for you</h2>
        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?= count($food_items) ?> Found</span>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-8">
        <?php if(empty($food_items)): ?>
            <div class="col-span-full py-20 text-center bg-white border-2 border-dashed border-slate-200 rounded-[3rem]">
                <div class="w-20 h-20 bg-orange-50 text-orange-300 rounded-full flex items-center justify-center text-4xl mx-auto mb-4"><i class="fas fa-utensils"></i></div>
                <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No dishes available within 6km. Try a different category!</p>
            </div>
        <?php endif; ?>

        <?php foreach($food_items as $f): 
            $imgs = json_decode($f['image_paths'] ?? '[]', true) ?: [];
            $display_img = !empty($imgs) ? '../' . $imgs[0] : 'https://placehold.co/400x400?text=Delicious+Food';
        ?>
        <a href="Food_menu.php?shop_id=<?= $f['shop_id'] ?>&item_id=<?= $f['id'] ?>" class="food-card bg-white rounded-[2rem] border border-slate-100 overflow-hidden flex flex-col">
            <div class="relative aspect-square overflow-hidden bg-slate-50">
                <img src="<?= $display_img ?>" class="w-full h-full object-cover" loading="lazy">
                <div class="absolute top-3 left-3">
                    <span class="bg-white/90 backdrop-blur-sm p-1 rounded border border-slate-200 shadow-sm flex items-center justify-center">
                         <div class="w-2 h-2 rounded-full <?= $f['is_veg'] ? 'bg-emerald-600' : 'bg-red-600' ?>"></div>
                    </span>
                </div>
                <div class="absolute top-4 right-4 bg-white/95 backdrop-blur px-2.5 py-1 rounded-xl shadow-lg flex items-center gap-1.5">
                    <span class="text-[10px] font-black"><?= number_format($f['shop_rating'] ?? 0, 1) ?: 'New' ?></span>
                    <i class="fas fa-star text-[10px] text-amber-500"></i>
                </div>
            </div>
            <div class="p-4 md:p-6 flex-1 flex flex-col">
                <div class="flex justify-between items-start mb-2">
                    <h3 class="text-sm md:text-base font-black text-slate-900 leading-tight line-clamp-1"><?= htmlspecialchars($f['item_name']) ?></h3>
                </div>
                <div class="flex items-center justify-between mt-auto">
                    <span class="text-base font-black text-slate-900">₹<?= number_format($f['price'], 0) ?></span>
                    <span class="text-[8px] font-black bg-slate-100 text-slate-400 px-2 py-0.5 rounded uppercase"><?= htmlspecialchars($f['weight_packet']) ?></span>
                </div>
                <div class="flex items-center gap-1.5 pt-3 mt-3 border-t border-slate-50">
                    <i class="fas fa-utensils text-slate-300 text-[8px]"></i>
                    <p class="text-[9px] text-slate-400 font-bold uppercase truncate"><?= htmlspecialchars($f['shop_name']) ?></p>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

</main>

<footer class="bg-white border-t border-slate-100 py-10 mt-12">
    <div class="max-w-7xl mx-auto px-6 text-center">
        <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-10 mx-auto mb-4 opacity-50">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">KhataLink Food Network v1.0</p>
    </div>
</footer>

<script>
let currentLat = <?= $lat ?>, currentLng = <?= $lng ?>;
let currentRadius = <?= $radius ?>;

function tick() {
    const t = new Date().toLocaleTimeString('en-IN', { hour12:true, hour:'2-digit', minute:'2-digit', second:'2-digit' });
    if(document.getElementById('mClock')) document.getElementById('mClock').innerText = t;
    if(document.getElementById('dClock')) document.getElementById('dClock').innerText = t;
}
setInterval(tick, 1000); tick();

// ── Geolocation ────────────────────────────────────────────────────────
function getLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
            const lat = pos.coords.latitude, lng = pos.coords.longitude;
            currentLat = lat; currentLng = lng;
            const params = new URLSearchParams(window.location.search);
            if(!params.has('lat')) {
                window.location.href = `Food_home.php?lat=${lat}&lng=${lng}&radius=${currentRadius}`;
            }
            getLocationParts(lat, lng);
        });
    }
}

async function getLocationParts(lat, lng) {
    try {
        const res = await fetch(`ajax_get_registry_location.php?lat=${lat}&lng=${lng}`);
        const data = await res.json();
        if (data.success) {
            const v = data.data.village_name;
            const b = data.data.block_name;
            const d = data.data.district_name;
            
            document.getElementById('dVillage').innerText = v;
            document.getElementById('dVillage').classList.remove('loc-loading');
            document.getElementById('mVillage').innerText = v;
            document.getElementById('mVillage').classList.remove('loc-loading');

            const parts = [];
            if (b && b.toLowerCase() !== v.toLowerCase()) parts.push(b);
            if (d && d.toLowerCase() !== b.toLowerCase() && d.toLowerCase() !== v.toLowerCase()) parts.push(d);
            const subText = parts.join(' · ');

            ['dBlockDist', 'mBlockDist'].forEach(id => {
                const el = document.getElementById(id);
                if(el) { el.innerText = subText; el.classList.toggle('hidden', !subText); }
            });
        }
    } catch(e) {}
}

// ── Banners ────────────────────────────────────────────────────────────
(function() {
    const total = <?= count($banner_list) ?>;
    const track = document.getElementById('bannerTrack');
    const dots  = document.querySelectorAll('.banner-dot');
    if(!track || total <= 1) return;
    let idx = 0;
    setInterval(() => {
        idx = (idx + 1) % total;
        track.style.transform = `translateX(-${(idx * 100) / total}%)`;
        dots.forEach((d, i) => {
            if(i === idx) { d.classList.add('!w-6', '!bg-white'); }
            else { d.classList.remove('!w-6', '!bg-white'); }
        });
    }, 5000);
})();

window.onload = () => {
    if(currentLat !== 0) getLocationParts(currentLat, currentLng);
    else getLocation();
};

// Search listener
document.getElementById('searchInput').addEventListener('keydown', e => {
    if(e.key === 'Enter') {
        window.location.href = `Food_home.php?search=${encodeURIComponent(e.target.value)}&lat=${currentLat}&lng=${currentLng}&radius=${currentRadius}`;
    }
});
</script>
</body>
</html>
