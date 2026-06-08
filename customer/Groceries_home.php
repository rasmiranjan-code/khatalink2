<?php
ob_start(); // Prevent any accidental whitespace/output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kolkata'); // Ensure IST Timezone

// ── 1. API DETECTION ──
$is_api = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp');

if ($is_api) {
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
}

// ── MALL MASTER CONTROL LOGIC ───────────────────────────────────────────
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$mall_open_time       = $settings['mall_open_time'] ?? '07:00:00';
$mall_close_time      = $settings['mall_close_time'] ?? '23:00:00';
$mall_maint_date      = $settings['mall_maintenance_date'] ?? '';
$mall_force_closed    = ($settings['mall_force_closed'] ?? '0') === '1';

$now_time = date('H:i:s');
$today    = date('Y-m-d');

$in_maintenance = ($mall_force_closed || ($mall_maint_date && $mall_maint_date === $today));

// 1. Check for Maintenance Mode (Hard Lock)
if ($in_maintenance) :
    if ($is_api) {
        ob_clean();
        exit(json_encode(['success' => false, 'maintenance' => true, 'message' => 'Mall is under maintenance.']));
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Maintenance — KhataLink Mall</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-slate-900 flex items-center justify-center min-h-screen p-6 text-center">
    <div class="max-w-md">
        <div class="w-24 h-24 bg-blue-500/20 text-blue-500 rounded-full flex items-center justify-center text-4xl mx-auto mb-8 animate-pulse">
            <i class="fas fa-gears"></i>
        </div>
        <h1 class="text-3xl font-black text-white uppercase tracking-tight mb-4 leading-tight">KhataLink Mall Groceries is under maintenance</h1>
        <p class="text-slate-400 text-sm leading-relaxed mb-10">
            We are performing essential upgrades to improve your shopping experience. We'll be back shortly!
        </p>
        <a href="../index.php" class="inline-block bg-white text-slate-900 px-10 py-4 rounded-2xl font-black uppercase text-xs tracking-widest hover:bg-blue-500 hover:text-white transition-all shadow-xl">
            Back to Home
        </a>
    </div>
</body>
</html>
<?php exit(); endif; ?>

<?php
// 2. Check for Ghost Mode (Closed Hours) - Handling Overnight Logic
$now_ts   = strtotime($now_time);
$open_ts  = strtotime($mall_open_time);
$close_ts = strtotime($mall_close_time);

$is_ghost_mode = false; // FORCED 24/7 OPEN

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

// Fetch customer details for footer
$stmt_c = $pdo->prepare("SELECT name, pincode FROM customers WHERE id = ?");
$stmt_c->execute([$customer_id]);
$customer = $stmt_c->fetch();

// FIX: Use profile location as fallback if URL params are missing to prevent "Global" to "Local" jump
$lat      = (float)($_GET['lat']      ?? $customer['latitude'] ?? 0);
$lng      = (float)($_GET['lng']      ?? $customer['longitude'] ?? 0);
$search   = trim($_GET['search']      ?? '');
$category = trim($_GET['category']    ?? '');
$sort     = trim($_GET['sort']        ?? 'default');
$radius   = (isset($_GET['radius']) && (int)$_GET['radius'] > 0) ? (int)$_GET['radius'] : 15; // Increased default to 15km

$now_time = date('H:i:s');
$now_dt   = date('Y-m-d H:i:s');

// FIX: Added is_mall_active filter and improved timing logic to handle overnight shops correctly
$query  = "SELECT c.* FROM Groceries_product_marketplace_cache c
           JOIN shop_owners s ON c.shop_id = s.id
           WHERE s.is_online = 1 AND s.is_verified = 1 AND s.is_mall_active = 1 AND c.current_stock > 0";

$params = [];

if($search) {
    $query   .= " AND c.name LIKE ?";
    $params[] = "%$search%";
}   
if($category && $category !== 'All') {
    $query   .= " AND c.product_category LIKE ?";
    $params[] = "%$category%";
}

if($lat != 0 && $lng != 0) {
    $lat_offset = $radius / 111.0;
    $lng_offset = $radius / (111.0 * cos(deg2rad($lat)));
    
    // FIX: Allow shops with 0,0 coords to show up globally (Location Fallback)
    // Taaki agar dukandaar ne GPS set nahi kiya, tab bhi products na chhupien
    $query .= " AND ( (s.latitude = 0 AND s.longitude = 0) OR (shop_latitude BETWEEN ? AND ? AND shop_longitude BETWEEN ? AND ?) )";
    array_push($params, $lat - $lat_offset, $lat + $lat_offset, $lng - $lng_offset, $lng + $lng_offset);
}

// ── FIX: SQL Injection Prevention in ORDER BY ──
$distance_calc = ($lat != 0 && $lng != 0) ? "(ABS(shop_latitude - ?) + ABS(shop_longitude - ?))" : "NULL";
if($lat != 0 && $lng != 0) { array_push($params, $lat, $lng); }

$order_by_clause = "(current_stock > 0) DESC, " . ($lat != 0 ? "$distance_calc ASC, " : "");

if ($sort === 'price_asc') {
    $query .= " ORDER BY " . $order_by_clause . "sale_price ASC";
} elseif ($sort === 'price_desc') {
    $query .= " ORDER BY " . $order_by_clause . "sale_price DESC";
} elseif ($sort === 'rating_desc') {
    $query .= " ORDER BY " . $order_by_clause . "average_rating DESC";
} else {
    $query .= " ORDER BY " . $order_by_clause . "last_cache_update DESC, sale_price ASC";
}

$query .= " LIMIT 40";
$stmt   = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eta_label = "15-25 mins";
if(!empty($products) && $lat != 0) {
    $first_p = $products[0];
    $deg_dist = abs($first_p['shop_latitude'] - $lat) + abs($first_p['shop_longitude'] - $lng);
    $km_approx = $deg_dist * 111;
    $minutes = ceil(($km_approx * 3) + 7);
    if($km_approx < 0.1)    $eta_label = "7-10 mins";
    elseif($minutes < 12)   $eta_label = "8-12 mins";
    elseif($minutes < 25)   $eta_label = "$minutes-" . ($minutes+5) . " mins";
    else                    $eta_label = "Under 40 mins";
} elseif ($lat == 0) {
    $eta_label = "Fast Delivery";
}

$banners_stmt = $pdo->prepare("SELECT image_path FROM banners WHERE target IN ('groceries','all') ORDER BY created_at DESC LIMIT 5");
$banners_stmt->execute();
$banner_list = $banners_stmt->fetchAll();

// Fetch Active Coupons for Banner Template
$stmt_cpn = $pdo->prepare("SELECT * FROM coupons WHERE is_active = 1 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) ORDER BY created_at DESC LIMIT 1");
$stmt_cpn->execute();
$active_coupon = $stmt_cpn->fetch();

$banner_count = count($banner_list);
$slide_count = $banner_count; // Reverted: Slider only for banners

// Fetch category custom images from Admin
$db_category_imgs = $pdo->query("SELECT category_name, image_path FROM mall_categories")->fetchAll(PDO::FETCH_KEY_PAIR);

// Shared Category Map (Default Icons)
$category_map = [
    'Paan Corner'          => 'https://cdn-icons-png.flaticon.com/512/3141/3141203.png',
    'Dairy, Bread & Eggs'  => 'https://cdn-icons-png.flaticon.com/512/3050/3050111.png',
    'Fruits & Vegetables'  => 'https://cdn-icons-png.flaticon.com/512/2329/2329865.png',
    'Cold Drinks & Juices' => 'https://cdn-icons-png.flaticon.com/512/3126/3126885.png',
    'Snacks & Munchies'    => 'https://cdn-icons-png.flaticon.com/512/2553/2553691.png',
    'Breakfast & Instant'  => 'https://cdn-icons-png.flaticon.com/512/1046/1046774.png',
    'Sweet Tooth'          => 'https://cdn-icons-png.flaticon.com/512/2553/2553642.png',
    'Bakery & Biscuits'    => 'https://cdn-icons-png.flaticon.com/512/3014/3014534.png',
    'Tea, Coffee & Health' => 'https://cdn-icons-png.flaticon.com/512/2135/2135541.png',
    'Atta, Rice & Dal'     => 'https://cdn-icons-png.flaticon.com/512/10502/10502693.png',
    'Masala, Oil & More'   => 'https://cdn-icons-png.flaticon.com/512/2713/2713931.png',
    'Sauces & Spreads'     => 'https://cdn-icons-png.flaticon.com/512/3014/3014389.png',
    'Chicken, Meat & Fish' => 'https://cdn-icons-png.flaticon.com/512/2553/2553648.png',
    'Organic & Healthy'    => 'https://cdn-icons-png.flaticon.com/512/1046/1046757.png',
    'Baby Care'            => 'https://cdn-icons-png.flaticon.com/512/2329/2329841.png',
    'Cleaning Essentials'  => 'https://cdn-icons-png.flaticon.com/512/2553/2553630.png',
    'Home & Office'        => 'https://cdn-icons-png.flaticon.com/512/2329/2329824.png',
    'Personal Care'        => 'https://cdn-icons-png.flaticon.com/512/2966/2966327.png',
    'Pet Care'             => 'https://cdn-icons-png.flaticon.com/512/2553/2553652.png',
    'Frozen Foods'         => 'https://cdn-icons-png.flaticon.com/512/2515/2515183.png',
];

// ── API RESPONSE (Moved to bottom of logic) ──
if($is_api) {
    ob_clean();
    exit(json_encode([
        'success'        => true,
        'maintenance'    => false,
        'is_ghost_mode'  => $is_ghost_mode,
        'products'       => $products,
        'banners'        => $banner_list,
        'active_coupon'  => $active_coupon,
        'category_imgs'  => $db_category_imgs,
        'categories'     => array_keys($category_map)
    ]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Groceries Mall — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        #toast {
            position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%) translateY(20px);
            background: #1e293b; color: #fff;
            padding: .6rem 1.4rem; border-radius: 9999px;
            font-size: .85rem; font-weight: 700;
            opacity: 0; pointer-events: none;
            transition: opacity .3s ease, transform .3s ease;
            white-space: nowrap; z-index: 99999;
        }
        #toast.show {
            opacity: 1; transform: translateX(-50%) translateY(0);
        }
        html, body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; background: #f8fafc; }

        /* ══ STICKY NAV ══ */
        #stickyNav {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 9999;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            box-shadow: 0 1px 8px rgba(0,0,0,0.06);
        }

        #pageContent {
            margin-top: 120px;
        }
        /* GHOST MODE STYLES */
        body.ghost-mode #pageContent, body.ghost-mode #stickyNav, body.ghost-mode footer { filter: grayscale(100%) brightness(0.6) !important; pointer-events: none !important; user-select: none !important; }
        .ghost-overlay { position: fixed; inset: 0; z-index: 999999; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(8px); pointer-events: auto !important; display: none; }
        .ghost-overlay.active { display: flex; }
        .ghost-card { background: white; padding: 3rem; border-radius: 3.5rem; text-align: center; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); border: 4px solid #10b981; max-width: 400px; width: 90%; animation: slideUp 0.5s ease; }

        @media (min-width: 768px) {
            #pageContent { margin-top: 72px; }
        }

        /* ══ NAV ROWS ══ */
        .nav-row1 {
            width: 100%; padding: 0 16px; height: 70px;
            display: flex; align-items: center; gap: 12px;
        }
        .nav-row2 {
            border-top: 1px solid #f8fafc;
            padding: 8px 16px;
            display: flex; align-items: center; gap: 12px; width: 100%;
        }
        @media (min-width: 768px) { .nav-row2 { display: none; } }

        /* ══ LOCATION LABELS ══ */
        .loc-label   { font-size: 9px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: .04em; line-height: 1; }
        .loc-village { font-size: 13px; font-weight: 900; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; line-height: 1.2; }
        .loc-sub     { font-size: 10px; font-weight: 600; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
        .loc-village-m { font-size: 11px; font-weight: 900; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px; }
        .loc-sub-m     { font-size: 9px; font-weight: 600; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px; }

        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
        .loc-loading { animation: pulse 1.2s ease-in-out infinite; color: #cbd5e1 !important; }

        .eta-badge {
            background: #facc15; color: #000;
            font-size: 8px; font-weight: 900;
            padding: 2px 6px; border-radius: 6px;
            text-transform: uppercase; margin-bottom: 2px; display: inline-block;
        }

        /* ══ SEARCH BOX ══ */
        .search-results-box {
            position: absolute; top: calc(100% + 4px); left: 0; right: 0;
            background: #fff; border: 1.5px solid #e2e8f0;
            border-radius: 16px; z-index: 10000; overflow: hidden;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        .suggestion-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; text-decoration: none; color: inherit; transition: background 0.15s;
        }
        .suggestion-item:hover { background: #f8fafc; }

        /* ══ BANNER ══ */
        #mallBannerTrack { transition: transform 0.9s cubic-bezier(0.65,0,0.35,1); }

        /* ══ FLY-TO-CART ══ */
        .fly-item {
            position: fixed;
            width: 42px; height: 42px;
            border-radius: 50%;
            object-fit: cover;
            pointer-events: none;
            z-index: 99999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            border: 2px solid #10b981;
        }

        /* ══ PRODUCT CARD ══ */
        .product-card {
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #f1f5f9;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            transition: box-shadow 0.2s, transform 0.15s;
            cursor: pointer;
        }
        .product-card:hover {
            box-shadow: 0 6px 24px rgba(0,0,0,0.09);
            transform: translateY(-2px);
        }

        /* Card image area */
        .card-img {
            position: relative;
            width: 100%;
            aspect-ratio: 1 / 1;
            background: #f8fafc;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s;
        }
        .product-card:hover .card-img img { transform: scale(1.04); }

        /* ETA chip on image */
        .card-eta {
            position: absolute; bottom: 6px; left: 6px;
            background: rgba(0,0,0,0.6);
            color: #fff;
            font-size: 8px; font-weight: 800;
            padding: 3px 7px; border-radius: 8px;
            display: flex; align-items: center; gap: 3px;
            backdrop-filter: blur(4px);
        }
        .card-oos {
            position: absolute; inset: 0;
            background: rgba(255,255,255,0.55);
            display: flex; align-items: center; justify-content: center;
        }
        .card-oos span {
            font-size: 9px; font-weight: 900; color: #ef4444;
            background: #fef2f2; border: 1px solid #fecaca;
            padding: 4px 10px; border-radius: 20px; text-transform: uppercase;
        }

        /* Card body */
        .card-body {
            padding: 8px;
            display: flex; flex-direction: column; gap: 3px; flex: 1;
        }
        .card-name {
            font-size: 12px; font-weight: 800; color: #0f172a;
            line-height: 1.3;
            display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .card-unit {
            font-size: 10px; font-weight: 600; color: #94a3b8;
        }
        .card-rating {
            font-size: 10px; font-weight: 700; color: #f59e0b;
            display: flex; align-items: center; gap: 3px;
        }
        .card-footer {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: auto; padding-top: 8px;
        }
        .card-price {
            font-size: 14px; font-weight: 900; color: #0f172a;
        }

        /* ADD button */
        .add-btn {
            background: #059669; color: #fff;
            border-radius: 10px; min-width: 56px; padding: 6px 4px 5px;
            cursor: pointer; transition: background 0.15s, transform 0.1s;
            text-decoration: none; border: none; outline: none;
            line-height: 1; display: flex; flex-direction: column; align-items: center;
        }
        .add-btn:hover  { background: #047857; transform: scale(1.04); }
        .add-btn:active { transform: scale(0.97); }
        .add-btn .add-label { font-size: 11px; font-weight: 900; letter-spacing: 0.04em; }
        .add-btn .add-sub   { font-size: 7px;  font-weight: 700; opacity: 0.85; margin-top: 1px; }

        /* ══ CATEGORY GRID ══ */
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }

        /* ══ PRODUCT GRID ══ */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        @media (min-width: 540px)  { .product-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 768px)  { .product-grid { grid-template-columns: repeat(4, 1fr); gap: 10px; } }
        @media (min-width: 1024px) { .product-grid { grid-template-columns: repeat(5, 1fr); } }
        @media (min-width: 1280px) { .product-grid { grid-template-columns: repeat(6, 1fr); } }

        /* ══ FILTER BUTTONS ══ */
        .radius-filter-btn:hover { opacity: 0.8; }
    </style>
</head>
<!-- FIX: Apply ghost-mode class directly from PHP to prevent UI flickering -->
<body class="<?= $is_ghost_mode ? 'ghost-mode' : '' ?>">

<!-- ══════════════════════════════════════
     STICKY NAV
══════════════════════════════════════ -->
<nav id="stickyNav">
    <!-- ROW 1 -->
    <div class="nav-row1">
        <a href="dashboard.php" style="flex-shrink:0; display:flex; align-items:center;">
            <img src="https://i.ibb.co/2Yhj4MhP/Chat-GPT-Image-May-17-2026-02-36-06-AM.png"
                 alt="KhataLink" style="height:48px; width:auto; object-fit:contain;">
        </a>

        <div class="nav-divider" style="width:1px;height:40px;background:#e2e8f0;flex-shrink:0;display:none;" id="desktopDivider"></div>

        <!-- Location block — desktop only -->
        <div id="desktopLocBlock" style="display:none; flex-direction:column; justify-content:center; min-width:0; cursor:pointer; flex-shrink:0;">
            <div><span class="eta-badge" id="dEta"><?= $eta_label ?></span></div>
            <span class="loc-label">Delivering to</span>
            <div style="display:flex; align-items:center; gap:4px; margin-top:2px;">
                <i class="fas fa-location-dot" style="color:#10b981; font-size:11px; flex-shrink:0;"></i>
                <span id="dVillage" class="loc-village loc-loading">Detecting...</span>
                <i class="fas fa-chevron-down" style="font-size:9px; color:#94a3b8; flex-shrink:0;"></i>
            </div>
            <span id="dBlockDist" class="loc-sub" style="padding-left:15px; margin-top:1px; display:none;"></span>
        </div>

        <!-- Clock — desktop only -->
        <div id="desktopClock" style="display:none; align-items:center; gap:6px; background:#f8fafc; border:1px solid #f1f5f9; padding:6px 12px; border-radius:12px; flex-shrink:0;">
            <i class="far fa-clock" style="color:#cbd5e1; font-size:10px;"></i>
            <span id="dClock" style="font-size:11px; font-weight:900; color:#64748b; font-variant-numeric:tabular-nums;">00:00:00</span>
        </div>

        <!-- SEARCH — desktop only -->
        <div style="flex:1; position:relative; display:none;" id="desktopSearchWrap">
            <i class="fas fa-search" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#cbd5e1; font-size:13px; pointer-events:none;"></i>
            <input type="text" id="desktopSearchInput" value="<?= htmlspecialchars($search) ?>"
                   onkeydown="if(event.key==='Enter') window.location.href='Groceries_home.php?search='+encodeURIComponent(this.value)+'&lat='+currentLat+'&lng='+currentLng+'&radius='+currentRadius;"
                   style="width:100%; background:#f8fafc; border:2px solid #f1f5f9; border-radius:14px; padding:10px 14px 10px 40px; font-size:13px; font-weight:600; outline:none; transition:all 0.2s;"
                   placeholder='Search "milk"...'
                   onfocus="this.style.background='#fff';this.style.borderColor='#10b981';"
                   onblur="this.style.background='#f8fafc';this.style.borderColor='#f1f5f9';">
            <div id="desktopSuggestions" class="search-results-box" style="display:none;"></div>
        </div>

        <!-- Right Actions -->
        <div style="display:flex; align-items:center; gap:8px; flex-shrink:0; margin-left:auto;">
            <a href="Groceries_orders.php"
               style="width:40px;height:40px;border-radius:12px;background:#f8fafc;border:1px solid #f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;text-decoration:none;transition:all 0.2s;"
               onmouseover="this.style.color='#10b981';this.style.borderColor='#d1fae5';"
               onmouseout="this.style.color='#94a3b8';this.style.borderColor='#f1f5f9';">
                <i class="fas fa-history" style="font-size:14px;"></i>
            </a>
            <a href="Groceries_cart.php"
               style="position:relative;display:flex;align-items:center;gap:8px;background:#059669;color:#fff;padding:10px 16px;border-radius:12px;box-shadow:0 4px 12px rgba(5,150,105,0.2);text-decoration:none;transition:all 0.2s;"
               onmouseover="this.style.background='#047857';this.style.transform='scale(1.03)';"
               onmouseout="this.style.background='#059669';this.style.transform='scale(1)';">
                <i class="fas fa-shopping-cart" style="font-size:14px;"></i>
                <span style="font-size:11px;font-weight:900;" class="hidden sm:inline">Cart</span>
                <span id="cartBadge" style="position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;font-size:8px;font-weight:900;width:18px;height:18px;border-radius:50%;display:none;align-items:center;justify-content:center;border:2px solid #fff;">0</span>
            </a>
        </div>
    </div>

    <!-- ROW 2 — mobile search + location -->
    <div class="nav-row2">
        <div style="display:flex; flex-direction:column; flex-shrink:0; min-width:0; line-height:1;">
            <span style="font-size:7px;font-weight:900;color:#d97706;text-transform:uppercase;margin-bottom:2px;" id="mEta"><?= $eta_label ?></span>
            <div style="display:flex;align-items:center;gap:3px;">
                <i class="fas fa-location-dot" style="color:#10b981;font-size:9px;flex-shrink:0;"></i>
                <span id="mVillage" class="loc-village-m loc-loading">Detecting...</span>
                <i class="fas fa-chevron-down" style="font-size:8px;color:#cbd5e1;flex-shrink:0;"></i>
            </div>
            <span id="mBlockDist" class="loc-sub-m" style="padding-left:12px;display:none;"></span>
        </div>

        <div style="width:1px;height:32px;background:#e2e8f0;flex-shrink:0;"></div>
        <span id="mClock" style="font-size:10px;font-weight:900;color:#94a3b8;flex-shrink:0;font-variant-numeric:tabular-nums;">00:00:00</span>
        <div style="width:1px;height:32px;background:#e2e8f0;flex-shrink:0;"></div>

        <div style="flex:1;position:relative;">
            <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#cbd5e1;font-size:10px;pointer-events:none;"></i>
            <input type="text" id="mobileSearchInput" value="<?= htmlspecialchars($search) ?>"
                   onkeydown="if(event.key==='Enter') window.location.href='Groceries_home.php?search='+encodeURIComponent(this.value)+'&lat='+currentLat+'&lng='+currentLng+'&radius='+currentRadius;"
                   style="width:100%;background:#f1f5f9;border:none;border-radius:12px;padding:8px 12px 8px 28px;font-size:11px;font-weight:600;outline:none;transition:all 0.2s;"
                   placeholder="Search items..."
                   onfocus="this.style.background='#fff';this.style.boxShadow='0 0 0 2px #10b981';"
                   onblur="this.style.background='#f1f5f9';this.style.boxShadow='none';">
            <div id="mobileSuggestions" class="search-results-box" style="display:none;"></div>
        </div>
    </div>
</nav>

<!-- ══════════════════════════════════════
     PAGE CONTENT
══════════════════════════════════════ -->
<main id="pageContent" style="padding:12px; max-width:1400px; margin-left:auto; margin-right:auto;">

              <!-- GHOST MODE OVERLAY (Managed by JS) -->
    <div id="mallGhostOverlay" class="ghost-overlay <?= $is_ghost_mode ? 'active' : '' ?>">
        <div class="ghost-card">
            <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-4xl mx-auto mb-6">
                <i class="fas fa-moon"></i>
            </div>
            <h2 class="text-3xl font-black text-slate-900 uppercase tracking-tighter mb-2">Mall is Sleeping</h2>
            <p class="text-slate-500 font-bold text-sm mb-8 italic">Aapki suvidha ke liye hum subah jaldi wapas aayenge!</p>
            <div class="grid grid-cols-2 gap-4 py-6 border-y border-slate-100 mb-6">
                <div><div class="text-[10px] font-black text-slate-400 uppercase">Opens At</div><div class="text-lg font-black text-emerald-600" id="overlayOpenTime"><?= date('h:i A', strtotime($mall_open_time)) ?></div></div>
                <div><div class="text-[10px] font-black text-slate-400 uppercase">Closes At</div><div class="text-lg font-black text-slate-900" id="overlayCloseTime"><?= date('h:i A', strtotime($mall_close_time)) ?></div></div>
            </div>
            <a href="../index.php" class="inline-block bg-slate-900 text-white px-10 py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-slate-800 transition-all shadow-lg">Go Back</a>
        </div>
    </div>
    <?php if(!empty($banner_list)): ?>
    <div style="margin-bottom:28px;">
        <div style="position:relative;width:100%;overflow:hidden;border-radius:20px;box-shadow:0 4px 16px rgba(0,0,0,0.08);background:#f1f5f9;aspect-ratio:4/1;">
            <div id="mallBannerTrack" style="display:flex;height:100%;width:<?= count($banner_list)*100 ?>%;">
                <?php foreach($banner_list as $bl): ?>
                <?php 
                    // FIX: Prepends ../ only for Web View context
                    $display_img = (!empty($bl['image_path']) && !filter_var($bl['image_path'], FILTER_VALIDATE_URL)) ? '../' . ltrim($bl['image_path'], '/') : $bl['image_path'];
                ?>
                <div style="height:100%;flex-shrink:0;width:<?= 100/count($banner_list) ?>%;">
                    <img src="<?= htmlspecialchars($display_img) ?>" style="width:100%;height:100%;object-fit:contain;" loading="lazy">
                </div>
                <?php endforeach; ?>
            </div>
            <?php if(count($banner_list)>1): ?>
            <div style="position:absolute;bottom:8px;left:0;right:0;display:flex;justify-content:center;gap:6px;">
                <?php foreach($banner_list as $i=>$bl): ?>
                <div class="mall-dot" style="height:4px;width:20px;border-radius:4px;background:<?= $i===0?'#fff':'rgba(255,255,255,0.4)' ?>;transition:all 0.3s;"></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── DELIVERY RADIUS OPTIONS ── -->
    <form method="GET" action="Groceries_home.php" class="bg-white border border-slate-100 p-4 rounded-3xl shadow-sm mb-8 flex flex-wrap gap-4 items-end">
        <input type="hidden" name="lat" value="<?= $lat ?>">
        <input type="hidden" name="lng" value="<?= $lng ?>">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">

        <!-- Range Dropdown -->
        <div class="flex-1 min-w-[120px]">
            <label class="block text-[9px] font-black uppercase text-slate-400 mb-2 tracking-widest">Range</label>
            <select name="radius" onchange="this.form.submit()" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-3 py-2.5 text-[11px] font-black outline-none focus:border-emerald-500 cursor-pointer">
                <?php foreach([2, 5, 10, 20] as $r_opt): ?>
                    <option value="<?= $r_opt ?>" <?= $radius == $r_opt ? 'selected' : '' ?>><?= $r_opt ?> KM</option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Sort Dropdown -->
        <div class="flex-1 min-w-[140px]">
            <label class="block text-[9px] font-black uppercase text-slate-400 mb-2 tracking-widest">Sort By</label>
            <select name="sort" onchange="this.form.submit()" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-3 py-2.5 text-[11px] font-black outline-none focus:border-emerald-500 cursor-pointer">
                <option value="default" <?= $sort == 'default' ? 'selected' : '' ?>>Recommended</option>
                <option value="price_asc" <?= $sort == 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                <option value="price_desc" <?= $sort == 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                <option value="rating_desc" <?= $sort == 'rating_desc' ? 'selected' : '' ?>>Customer Rating</option>
            </select>
        </div>
        <div class="flex-1">
            <button type="submit" class="w-full bg-slate-900 text-white font-black py-2.5 rounded-2xl hover:bg-slate-800 transition-all shadow-lg uppercase tracking-widest text-[10px]">
                Apply Filters
            </button>
        </div>
    </form>

    <!-- ── CATEGORIES ── -->
    <div style="margin-bottom:32px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding:0 2px;">
            <h2 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:0.2em;color:#94a3b8;margin:0;">Shop by Category</h2>
            <?php if($category): ?>
            <a href="Groceries_home.php<?= $lat ? "?lat=$lat&lng=$lng&radius=$radius" : "?radius=$radius" ?>&sort=<?= $sort ?>" style="font-size:9px;font-weight:900;color:#059669;text-transform:uppercase;text-decoration:none;">Clear ✕</a>
            <?php endif; ?>
        </div>

        <div style="position:relative;margin-bottom:14px;">
            <i class="fas fa-filter" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#cbd5e1;font-size:11px;"></i>
            <input type="text" id="catSearch" oninput="filterCategories(this.value)"
                   style="width:100%;background:#f8fafc;border:1px solid #f1f5f9;border-radius:12px;padding:10px 14px 10px 36px;font-size:12px;font-weight:700;outline:none;"
                   placeholder="Search 100+ categories..."
                   onfocus="this.style.boxShadow='0 0 0 2px rgba(16,185,129,0.2)';"
                   onblur="this.style.boxShadow='none';">
        </div>

        <div id="categoryGrid" style="display:grid;grid-template-columns:repeat(10,1fr);gap:4px 3px;padding:0 1px;">
        <?php foreach($category_map as $c_name => $c_icon):
            $display_img = isset($db_category_imgs[$c_name]) ? '../' . $db_category_imgs[$c_name] : $c_icon;
            $isActive = ($category === $c_name);
        ?>
            <a href="?category=<?= urlencode($c_name) ?><?= $lat ? "&lat=$lat&lng=$lng" : "" ?>&radius=<?= $radius ?>&sort=<?= $sort ?>"
               class="cat-item"
               data-name="<?= strtolower($c_name) ?>"
               style="display:flex;flex-direction:column;align-items:center;gap:2px;text-decoration:none;-webkit-tap-highlight-color:transparent;">
                <div style="width:100%;aspect-ratio:1/1;border-radius:10px;display:flex;align-items:center;justify-content:center;overflow:hidden;transition:all 0.18s;
                            <?= $isActive ? 'background:#d1fae5;box-shadow:0 0 0 1.5px #059669;' : 'background:#f1f5f9;' ?>">
                    <img src="<?= $display_img ?>" style="width:100%;height:100%;padding:6px;object-fit:contain;" alt="<?= $c_name ?>" loading="lazy">
                </div>
                <span style="font-size:6px;font-weight:900;text-transform:uppercase;letter-spacing:0.02em;text-align:center;line-height:1.2;
                             <?= $isActive ? 'color:#047857;' : 'color:#64748b;' ?>">
                    <?= $c_name ?>
                </span>
            </a>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- ── PROMINENT COUPON SECTION (NEW) ── -->
    <?php if($active_coupon): ?>
    <div style="margin-bottom:32px; background:white; border:1px solid #f1f5f9; border-radius:24px; padding:20px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.05);">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
            <h3 style="font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:0.15em; color:#94a3b8; margin:0;">Special Offers For You</h3>
            <span style="font-size:8px; font-weight:900; background:#f0fdf4; color:#059669; padding:3px 8px; border-radius:8px; text-transform:uppercase;">Verified</span>
        </div>
        <div style="display:flex; align-items:center; gap:16px; background:linear-gradient(to right, #f8fafc, #ffffff); border:2px dashed #e2e8f0; padding:16px; border-radius:20px; position:relative; overflow:hidden;">
            <!-- Ticket Notch Effect -->
            <div style="position:absolute; left:-10px; top:50%; transform:translateY(-50%); width:20px; height:20px; background:#f8fafc; border-radius:50%; border:1px solid #f1f5f9;"></div>
            <div style="position:absolute; right:-10px; top:50%; transform:translateY(-50%); width:20px; height:20px; background:#f8fafc; border-radius:50%; border:1px solid #f1f5f9;"></div>
            
            <div style="width:48px; height:48px; background:#d1fae5; color:#059669; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">
                <i class="fas fa-ticket-alt"></i>
            </div>
            <div style="flex:1; min-width:0;">
                <div style="font-size:14px; font-weight:900; color:#0f172a; margin-bottom:2px; letter-spacing:-0.02em;"><?= htmlspecialchars($active_coupon['code']) ?></div>
                <div style="font-size:11px; font-weight:600; color:#64748b; line-height:1.4;"><?= htmlspecialchars($active_coupon['description']) ?></div>
            </div>
            <button onclick="copyCouponCode('<?= $active_coupon['code'] ?>', this)" 
                    class="bg-emerald-600 text-white px-6 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-emerald-100 hover:bg-emerald-700 active:scale-95 transition-all">
                Copy
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── PRODUCT SECTION HEADER ── -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding:0 2px;">
        <h2 style="font-size:14px;font-weight:900;color:#0f172a;margin:0;">
            <?= $category ? htmlspecialchars($category) : ($search ? 'Results: '.htmlspecialchars($search) : 'All Products') ?>
        </h2>
        <span style="font-size:10px;font-weight:700;color:#94a3b8;"><?= count($products) ?> items</span>
    </div>

    <!-- ── PRODUCT GRID ── -->
    <div class="product-grid">
        <?php if(empty($products)): ?>
        <div style="grid-column:1/-1;padding:60px 0;text-align:center;">
            <img src="https://cdn-icons-png.flaticon.com/512/1170/1170577.png" style="width:60px;margin:0 auto 12px;display:block;opacity:0.2;filter:grayscale(1);">
            <p style="color:#94a3b8;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:0.1em;">No products found near you</p>
            <p style="color:#cbd5e1;font-size:10px;margin-top:4px;">Try increasing the delivery range</p>
        </div>
        <?php endif; ?>

        <?php foreach($products as $p): ?>
        <a href="Groceries_product.php?id=<?= (int)$p['product_id'] ?>&shop_id=<?= (int)$p['shop_id'] ?>" class="product-card">

            <div class="card-img">
                <img src="../<?= htmlspecialchars($p['image_thumb_path']) ?>"
                     loading="lazy"
                     alt="<?= htmlspecialchars($p['name']) ?>"
                     style="<?= $p['current_stock'] <= 0 ? 'filter:grayscale(1);opacity:0.45;' : '' ?>"
                     id="pimg-<?= (int)$p['product_id'] ?>-<?= (int)$p['shop_id'] ?>">

                <?php if($p['current_stock'] > 0): ?>
                <div class="card-eta">
                    <i class="fas fa-clock" style="font-size:7px;"></i>
                    <span><?= $eta_label ?></span>
                </div>
                <?php else: ?>
                <div class="card-oos"><span>Out of Stock</span></div>
                <?php endif; ?>
            </div>

            <div class="card-body">
                <div class="card-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="card-unit"><?= htmlspecialchars($p['primary_unit']) ?></div>

                <?php if(!empty($p['average_rating']) && $p['average_rating'] > 0): ?>
                <div class="card-rating">
                    <i class="fas fa-star" style="font-size:9px;"></i>
                    <?= number_format((float)$p['average_rating'], 1) ?>
                    <span style="color:#cbd5e1;font-weight:600;">(<?= (int)$p['total_ratings_count'] ?>)</span>
                </div>
                <?php endif; ?>

                <div class="card-footer">
                    <span class="card-price">₹<?= number_format((float)$p['sale_price'], 0) ?></span>

                    <?php if($p['current_stock'] > 0): ?>
                    <button class="add-btn"
                        onclick="event.preventDefault(); addToCart(
                            <?= (int)$p['product_id'] ?>,
                            <?= (int)$p['shop_id'] ?>,
                            this,
                            '<?= addslashes(htmlspecialchars($p['name'])) ?>',
                            <?= (float)($p['sale_price'] ?? 0) ?>,
                            '<?= addslashes(htmlspecialchars($p['primary_unit'] ?? 'Unit')) ?>',
                            '<?= addslashes($p['image_thumb_path'] ?? '') ?>'
                        );">
                        <span class="add-label">ADD</span>
                        <span class="add-sub">+ more</span>
                    </button>
                    <?php else: ?>
                    <span style="font-size:8px;font-weight:900;color:#ef4444;background:#fef2f2;border:1px solid #fecaca;padding:3px 8px;border-radius:20px;text-transform:uppercase;">Unavailable</span>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

</main>

<!-- ── GROCERIES MALL FOOTER ── -->
<footer class="bg-white border-t border-slate-100 text-slate-900 py-8 md:py-10 mt-8 relative z-[500]">
    <div class="max-w-7xl mx-auto px-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 md:gap-8">
            <!-- Column 1: Branding & Info -->
            <div>
                <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-10 mb-4">
                <p class="text-slate-500 text-sm mb-4 font-medium">Aapki apni local market, ab aapki pocket mein.</p>
                <div class="flex items-center gap-2 text-emerald-400 text-xs font-bold uppercase tracking-widest mb-4">
                    <i class="fas fa-shield-alt"></i> Verified Merchants
                </div>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-tighter">Made with ❤️ in India</p>
            </div>

            <!-- Column 2: Quick Links -->
            <div>
                <h4 class="text-xs font-black uppercase tracking-[0.2em] text-slate-900 mb-4">Mall Access</h4>
                <nav class="space-y-2">
                    <a href="Groceries_home.php" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-bold">Mall Home</a>
                    <a href="Groceries_orders.php" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-bold">My Orders</a>
                    <a href="Groceries_cart.php" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-bold">My Cart</a>
                    <a href="Groceries_orders.php" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-bold">Order History</a>
                </nav>
            </div>

            <!-- Column 3: Support & Legal -->
            <div>
                <h4 class="text-xs font-black uppercase tracking-[0.2em] text-slate-900 mb-4">Support & Legal</h4>
                <nav class="space-y-2">
                    <a href="../guide.php" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-bold">Help Center</a>
                    <a href="reports.php" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-bold">Report Issue</a>
                    <a href="#" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-bold">Terms of Service</a>
                    <a href="#" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-bold">Privacy Policy</a>
                </nav>
            </div>

            <!-- Column 4: Partner With Us & App -->
            <div>
                <h4 class="text-xs font-black uppercase tracking-[0.2em] text-slate-900 mb-4">Partner With Us</h4>
                <nav class="space-y-2">
                    <a href="../partner.php" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-black">Become a Rider</a>
                    <a href="../partner.php" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-black">Register your Shop</a>
                    <a href="#" class="block text-slate-500 hover:text-emerald-600 transition-colors text-sm font-bold">Download App</a>
                </nav>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-slate-100 mt-8 pt-4 text-center text-slate-400 text-[10px] font-bold uppercase tracking-widest">
            © <?= date('Y') ?> KhataLink. All rights reserved.
        </div>
    </div>
</footer>

<!-- ══════════════════════════════════════
     SCRIPTS
══════════════════════════════════════ -->
<script>
// ── Global state ──
let currentLat    = <?= (float)$lat ?>;
let currentLng    = <?= (float)$lng ?>;
let currentRadius = <?= (int)$radius ?>;
let gpsRequested  = false; // prevent reload loop

async function syncCartWithServer(action = 'save') {
    const localCart = JSON.parse(localStorage.getItem('kl_grocery_cart') || '[]');
    try {
        const res = await fetch('ajax_sync_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action, cart: localCart })
        });
        const data = await res.json();
        if (data.success && data.cart) {
            localStorage.setItem('kl_grocery_cart', JSON.stringify(data.cart));
            updateCartBubble();
        }
    } catch (e) { console.warn("Background sync failed", e); }
}

// ══════════════════════════════════════
// FLY-TO-CART animation
// ══════════════════════════════════════
function flyToCart(btnEl, imgSrc) {
    const cartIcon = document.querySelector('a[href="Groceries_cart.php"]');
    if (!cartIcon) return;

    const cartRect = cartIcon.getBoundingClientRect();
    const btnRect  = btnEl.getBoundingClientRect();

    const clone = document.createElement('img');
    clone.src = imgSrc || 'https://cdn-icons-png.flaticon.com/512/3050/3050111.png';
    clone.className = 'fly-item';
    clone.style.left = (btnRect.left + btnRect.width / 2 - 21) + 'px';
    clone.style.top  = (btnRect.top  + btnRect.height / 2 - 21) + 'px';
    document.body.appendChild(clone);

    const tx = cartRect.left + cartRect.width  / 2 - (btnRect.left + btnRect.width  / 2);
    const ty = cartRect.top  + cartRect.height / 2 - (btnRect.top  + btnRect.height / 2);

    clone.animate([
        { transform: 'translate(0,0) scale(1)',                                           opacity: '1' },
        { transform: `translate(${tx*0.4}px,${ty*0.2 - 80}px) scale(0.9)`,               opacity: '1', offset: 0.4 },
        { transform: `translate(${tx}px,${ty}px) scale(0.2)`,                            opacity: '0' }
    ], { duration: 650, easing: 'cubic-bezier(0.25,0.46,0.45,0.94)', fill: 'forwards' })
    .onfinish = () => {
        clone.remove();
        cartIcon.animate([
            { transform: 'scale(1)' },
            { transform: 'scale(1.3)' },
            { transform: 'scale(1)' }
        ], { duration: 300, easing: 'ease-out' });
    };
}

// ══════════════════════════════════════
// ADD TO CART  (single definition, no duplicate)
// ══════════════════════════════════════
function addToCart(productId, shopId, btn, pName, pPrice, pUnit, pImg) {
    let cart = JSON.parse(localStorage.getItem('kl_grocery_cart') || '[]');
    const idx = cart.findIndex(i => i.product_id == productId && i.shop_id == shopId);

    if (idx > -1) {
        cart[idx].qty += 1;
        cart[idx].name             = pName;
        cart[idx].price            = parseFloat(pPrice);
        cart[idx].unit             = pUnit;
        cart[idx].image_thumb_path = pImg;
    } else {
        cart.push({
            product_id: productId, shop_id: shopId,
            qty: 1, name: pName,
            price: parseFloat(pPrice), unit: pUnit,
            image_thumb_path: pImg
        });
    }
    localStorage.setItem('kl_grocery_cart', JSON.stringify(cart));

    // Background Sync with Server
    syncCartWithServer('save');

    // Fly animation
    flyToCart(btn, pImg);

    // Button feedback
    const labelEl = btn.querySelector('.add-label');
    const subEl   = btn.querySelector('.add-sub');
    btn.style.background    = '#047857';
    if (labelEl) labelEl.innerText = '✓';
    if (subEl)   subEl.innerText   = 'Added!';
    setTimeout(() => {
        btn.style.background    = '#059669';
        if (labelEl) labelEl.innerText = 'ADD';
        if (subEl)   subEl.innerText   = '+ more';
    }, 1200);

    updateCartBubble();
}

function updateCartBubble() {
    const cart  = JSON.parse(localStorage.getItem('kl_grocery_cart') || '[]');
    let count   = 0;
    cart.forEach(i => count += i.qty);
    const badge = document.getElementById('cartBadge');
    if (badge) {
        badge.innerText     = count;
        badge.style.display = count > 0 ? 'flex' : 'none';
    }
}

// ══════════════════════════════════════
// RESPONSIVE LAYOUT
// ══════════════════════════════════════
function applyDesktopLayout() {
    const md = window.innerWidth >= 768;
    document.getElementById('desktopDivider').style.display    = md ? 'block' : 'none';
    document.getElementById('desktopLocBlock').style.display   = md ? 'flex'  : 'none';
    document.getElementById('desktopClock').style.display      = md ? 'flex'  : 'none';
    document.getElementById('desktopSearchWrap').style.display = md ? 'block' : 'none';
}
applyDesktopLayout();
window.addEventListener('resize', applyDesktopLayout);

// ── Placeholder rotation ──
const searchWords = ["rice","eggs","dal","kurkure","chocolate","milk","bread","atta","paneer","cold drink"];
let wordIdx = 0;
const dSearch = document.getElementById('desktopSearchInput');
const mSearch = document.getElementById('mobileSearchInput');
function rotatePlaceholder() {
    const text = `Search "${searchWords[wordIdx]}"`;
    if (dSearch && document.activeElement !== dSearch) dSearch.placeholder = text;
    if (mSearch && document.activeElement !== mSearch) mSearch.placeholder = text;
    wordIdx = (wordIdx + 1) % searchWords.length;
}
setInterval(rotatePlaceholder, 3000);

// ── Search suggestions ──
async function handleSearch(e, containerId) {
    const q         = e.target.value.trim();
    const esc = (s) => String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    
    const container = document.getElementById(containerId);
    if (!container) return;
    if (q.length < 2) { container.style.display = 'none'; container.innerHTML = ''; return; }
    try {
        const res  = await fetch(`ajax_marketplace_search.php?q=${encodeURIComponent(q)}&lat=${currentLat}&lng=${currentLng}&radius=${currentRadius}`);
        const data = await res.json();
        if (data.length > 0) {
            container.innerHTML = data.map(p => `
                <a href="Groceries_product.php?id=${p.product_id}&shop_id=${p.shop_id}" class="suggestion-item">
                    <img src="../${p.image_thumb_path}" style="width:40px;height:40px;border-radius:8px;object-fit:cover;background:#f8fafc;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:12px;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(p.name)}</div>
                        <div style="font-size:10px;color:#94a3b8;font-weight:600;">₹${parseFloat(p.sale_price).toFixed(2)}</div>
                    </div>
                    <i class="fas fa-chevron-right" style="font-size:10px;color:#e2e8f0;"></i>
                </a>`).join('');
        } else {
            container.innerHTML = '<div style="padding:14px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;text-align:center;">No products found</div>';
        }
        container.style.display = 'block';
    } catch(err) { console.error(err); }
}

if (dSearch) dSearch.addEventListener('input', e => handleSearch(e, 'desktopSuggestions'));
if (mSearch) mSearch.addEventListener('input', e => handleSearch(e, 'mobileSuggestions'));

document.addEventListener('click', e => {
    if (!e.target.closest('#desktopSearchWrap'))
        document.getElementById('desktopSuggestions').style.display = 'none';
    if (!e.target.closest('#mobileSearchInput') && !e.target.closest('#mobileSuggestions'))
        document.getElementById('mobileSuggestions').style.display = 'none';
});

// ── Clock ──
function tickClock() {
    const t = new Date().toLocaleTimeString('en-US', { hour12:true, hour:'2-digit', minute:'2-digit', second:'2-digit' });
    ['dClock','mClock'].forEach(id => { const el = document.getElementById(id); if(el) el.innerText = t; });
}
setInterval(tickClock, 1000);
tickClock();

// ── Location helpers ──
function setVillage(v) {
    ['dVillage','mVillage'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.innerText = v; el.classList.remove('loc-loading'); }
    });
}
function setBlockDist(bd) {
    ['dBlockDist','mBlockDist'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (bd) { el.innerText = bd; el.style.display = 'block'; }
        else    { el.innerText = ''; el.style.display = 'none'; }
    });
}
function resetLocation() {
    ['dVillage','mVillage'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.innerText = 'Detecting...'; el.classList.add('loc-loading'); }
    });
    setBlockDist('');
}

// ── Geocoder ──
async function getLocationParts(lat, lng) {
    resetLocation();
    let village = '', block = '', district = '';

    // Step 1: Internal registry
    try {
        const regRes  = await fetch(`ajax_get_registry_location.php?lat=${lat}&lng=${lng}`);
        const regData = await regRes.json();
        if (regData.success) {
            village  = regData.data.village_name;
            block    = regData.data.block_name;
            district = regData.data.district_name;
        }
    } catch(e) {}

    // Step 2: Nominatim fallback
    if (!village) {
        try {
            const r = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&addressdetails=1&zoom=18&accept-language=en`, { headers:{'Accept-Language':'en'} });
            const d = await r.json();
            const a = d.address || {};
            village  = a.village || a.hamlet || a.locality || a.neighbourhood || a.quarter || a.suburb || a.residential || '';
            block    = (a.county || a.city_district || a.town || a.municipality || '').replace(/\s*(block|mandal|taluk)\s*/gi,'').trim();
            district = (a.state_district || a.district || '').replace(/\s*(district|zilla)\s*/gi,'').trim();
        } catch(e) {}
    }

    // Step 3: BigDataCloud fallback
    if (!village) {
        try {
            const r = await fetch(`https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=${lat}&longitude=${lng}&localityLanguage=en`);
            const d = await r.json();
            village = d.locality || d.city || '';
        } catch(e) {}
    }

    if (!village) village = block || 'Your Location';

    const parts = [];
    if (block    && block.toLowerCase()    !== village.toLowerCase()) parts.push(block);
    if (district && district.toLowerCase() !== block.toLowerCase()
                 && district.toLowerCase() !== village.toLowerCase()) parts.push(district);

    setVillage(village);
    setBlockDist(parts.join(' · '));
}

// ── Category filter ──
function filterCategories(q) {
    const items = document.querySelectorAll('.cat-item');
    q = q.toLowerCase().trim();
    items.forEach(item => {
        item.style.display = (!q || item.dataset.name.includes(q)) ? 'flex' : 'none';
    });
}

// ── Banner auto-slide ──
(function() {
    const total = <?= (int)$slide_count ?>;
    const track = document.getElementById('mallBannerTrack');
    const dots  = document.querySelectorAll('.mall-dot');
    if (!track || total <= 1) return;
    let idx = 0;
    setInterval(() => {
        idx = (idx + 1) % total;
        track.style.transform = `translateX(-${(idx * 100) / total}%)`;
        dots.forEach((d, i) => {
            d.style.background = i === idx ? '#fff' : 'rgba(255,255,255,0.4)';
        });
    }, 4000);
})();

// ══════════════════════════════════════
// INIT — GPS + location (no reload loop)
// ══════════════════════════════════════
window.addEventListener('load', function() {
    const params = new URLSearchParams(window.location.search);
    const urlLat = parseFloat(params.get('lat') || '0');
    const urlLng = parseFloat(params.get('lng') || '0');

    // Show location label for existing URL coords
    if (urlLat && urlLng) {
        getLocationParts(urlLat, urlLng);
    }

    // GPS — only trigger reload if coords are missing or significantly off
    if (navigator.geolocation && !gpsRequested) {
        gpsRequested = true;
        navigator.geolocation.getCurrentPosition(
            pos => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                currentLat = lat;
                currentLng = lng;

                // Only fetch labels if not already in URL to avoid double work
                if(!urlLat) getLocationParts(lat, lng);

                // ── FIX RELOAD LOOP ──
                // Only reload if coordinates are missing or moved > 400 meters (increased threshold)
                const hasCoords = params.has('lat') && params.has('lng');
                const diff = Math.sqrt(Math.pow(urlLat - lat, 2) + Math.pow(urlLng - lng, 2));
                if (!hasCoords || diff > 0.01) { 
                    params.set('lat', lat.toFixed(6));
                    params.set('lng', lng.toFixed(6));
                    params.set('radius', currentRadius);
                    window.location.href = `${window.location.pathname}?${params.toString()}`;
                }
            },
            err => {
                if (!urlLat) setVillage('Enable GPS');
            },
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 }
        );
    }

    updateCartBubble();
    syncCartWithServer('get'); // Restore cart from server if logged in on new device
    syncMallStatus(); // FIX: Call immediately to sync UI state
});

// ── LIVE STATUS SYNC ──
async function syncMallStatus() {
    try {
        const res = await fetch('ajax_get_mall_status.php');
        const data = await res.json();
        if(!data.success || !data.server_time) return;

        // 1. Check Maintenance Date or Force Close
        if(data.mall_force_closed || (data.mall_maint_date && data.mall_maint_date === data.server_date)) {
            if (!window.location.search.includes('maintenance')) {
                window.location.reload(); 
            }
            return;
        }

        // 2. Logic for Ghost Mode
        const serverTimeStr = data.server_time; // HH:MM:SS
        const openTime      = data.mall_open_time;
        const closeTime     = data.mall_close_time;

        // ── FIX: Overnight Logic Bug ──
        const toMins = (t) => {
            const [h, m] = t.split(':');
            return parseInt(h) * 60 + parseInt(m);
        };

        const now = toMins(serverTimeStr), open = toMins(openTime), close = toMins(closeTime);

        let isClosed = false;
        if (open < close) { 
            // Standard: 07:00 to 23:00
            isClosed = (now < open || now > close);
        } else {
            // Overnight: 07:00 to 04:00
            isClosed = (now > close && now < open);
        }

        const body = document.body;
        const overlay = document.getElementById('mallGhostOverlay');

        if (isClosed) {
            if (!body.classList.contains('ghost-mode')) {
                body.classList.add('ghost-mode');
                overlay.classList.add('active');
            }
            // Always update display times in case admin changed them while user was away
            if(document.getElementById('overlayOpenTime')) document.getElementById('overlayOpenTime').innerText = formatTime(openTime);
            if(document.getElementById('overlayCloseTime')) document.getElementById('overlayCloseTime').innerText = formatTime(closeTime);
        } else {
            if (body.classList.contains('ghost-mode')) {
                body.classList.remove('ghost-mode');
                overlay.classList.remove('active');
            }
        }
    } catch (e) { console.warn("Status sync failed", e); }
}

// Format HH:MM:SS to 12hr AM/PM
function formatTime(timeStr) {
    if(!timeStr) return '--:--';
    const [h, m] = timeStr.split(':');
    let hour = parseInt(h);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    hour = hour % 12 || 12;
    return `${hour.toString().padStart(2, '0')}:${m} ${ampm}`;
}

// Start Polling every 30 seconds
setInterval(syncMallStatus, 30000); // Check every 30 seconds

// ── TAB FOCUS FIX ──
// Jab user tab par wapas aaye, turant check karo
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        syncMallStatus();
    }
});

function copyCouponCode(code, btn) {
    navigator.clipboard.writeText(code).then(() => {
        const original = btn.innerText;
        const originalBg = btn.className;
        btn.innerText = 'COPIED! ✓';
        btn.style.background = '#059669';
        btn.classList.add('text-white');
        showToast(`Code ${code} copied! Apply at checkout.`);
        setTimeout(() => {
            btn.innerText = original;
            btn.style.background = '';
        }, 2000);
    });
}

function showToast(msg) {
    const t = document.getElementById('toast');
    if(!t) return;
    t.innerText = msg;
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 2500);
}

</script>

<!-- Firebase Push Notifications -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>
<script>
(function() {
    const firebaseConfig = {
        apiKey:            "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
        authDomain:        "khatalink-63041.firebaseapp.com",
        projectId:         "khatalink-63041",
        messagingSenderId: "905429197043",
        appId:             "1:905429197043:web:2a0cbefa0fa176fd2c5786"
    };
    if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
    const messaging = firebase.messaging();
    messaging.onMessage(payload => {
        if (Notification.permission === 'granted') {
            new Notification(payload.notification.title, {
                body: payload.notification.body,
                icon: '../assets/favicon.png'
            });
        }
    });
})();
</script>
</body>
</html>