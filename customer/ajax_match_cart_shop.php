<?php
ob_start();
header('Content-Type: application/json');
session_start();

require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';

// ── SECURITY: Authentication Layer (App & Web) ──
$customer_id = 0;
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $token = get_auth_token();
    $parts = verify_secure_token($token);
    $customer_id = (int)($parts[0] ?? 0);
} else {
    $customer_id = (int)($_SESSION['customer_id'] ?? 0);
}

if (!$customer_id) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized Access']);
    ob_end_flush();
    exit;
}

check_cors();

// ── Distance Calculation ──
function calculateRoadDistanceAndDuration(float $origin_lat, float $origin_lng, float $dest_lat, float $dest_lng): array {
    $apiKey = FIREBASE_API_KEY;
    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=" . urlencode("$origin_lat,$origin_lng") . "&destinations=" . urlencode("$dest_lat,$dest_lng") . "&key=$apiKey";

    $km = 0.0;
    $duration_mins = 0;

    $resp = @file_get_contents($url);
    $dist_data = json_decode($resp, true);

    if (
        !empty($dist_data) &&
        isset($dist_data['status']) &&
        $dist_data['status'] == 'OK' &&
        !empty($dist_data['rows'][0]['elements'][0]['status']) &&
        $dist_data['rows'][0]['elements'][0]['status'] == 'OK'
    ) {
        $km = (float)($dist_data['rows'][0]['elements'][0]['distance']['value'] / 1000);
        $duration_mins = max(5, (int)ceil($dist_data['rows'][0]['elements'][0]['duration']['value'] / 60) + 2);
    } else {
        $earth_radius = 6371;
        $dLat = deg2rad($dest_lat - $origin_lat);
        $dLon = deg2rad($dest_lng - $origin_lng);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($origin_lat)) * cos(deg2rad($dest_lat)) *
             sin($dLon / 2) * sin($dLon / 2);
        $km = $earth_radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
        $duration_mins = max(5, (int)ceil($km * 4) + 3);
    }

    return ['distance_km' => round($km, 2), 'duration_mins' => $duration_mins];
}

try {

    // ── Read Raw Input ──
    $raw_input = file_get_contents('php://input');

    // ═══════════════════════════════════════════
    // DEBUG LOG
    // D:\xampp\htdocs\khatalink\customer\debug_error.log mein dekho
    // ═══════════════════════════════════════════
    error_log("══════════ MATCH_CART DEBUG ══════════");
    error_log("CUSTOMER_ID: $customer_id");
    error_log("RAW_INPUT: " . $raw_input);
    error_log("ALL_HEADERS: " . json_encode(getallheaders()));

    $data = json_decode($raw_input, true);

    error_log("DECODED_DATA keys: " . json_encode(array_keys($data ?? [])));
    error_log("CART_RECEIVED: " . json_encode($data['cart'] ?? 'KEY_MISSING'));
    error_log("LAT: " . ($data['lat'] ?? 'MISSING'));
    error_log("LNG: " . ($data['lng'] ?? 'MISSING'));
    error_log("PINCODE: " . ($data['pincode'] ?? 'MISSING'));
    error_log("VILLAGE: " . ($data['village'] ?? 'MISSING'));

    // ── Cart Sanitization ──
    $raw_cart = $data['cart'] ?? [];

    if (empty($raw_cart)) {
        error_log("DEBUG: raw_cart is EMPTY after decode");
        ob_clean();
        echo json_encode([
            'success'         => false,
            'message'         => 'Cart is empty',
            'debug_raw_input' => $raw_input,
        ]);
        ob_end_flush();
        exit;
    }

    if (count($raw_cart) > 100) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Cart size limit exceeded']);
        ob_end_flush();
        exit;
    }

    error_log("RAW_CART COUNT: " . count($raw_cart));
    error_log("FIRST_ITEM: " . json_encode($raw_cart[0] ?? 'none'));

    $sanitized_cart = [];
    foreach ($raw_cart as $index => $item) {
        error_log("ITEM[$index] KEYS: " . json_encode(array_keys($item)));
        error_log("ITEM[$index] DATA: " . json_encode($item));

        // Robust ID check: looks for product_id OR id
        $pid = (int)($item['product_id'] ?? $item['id'] ?? 0);
        $qty = min(10, max(0, (float)($item['qty'] ?? 0)));

        error_log("ITEM[$index] → pid=$pid, qty=$qty");

        if ($pid > 0 && $qty > 0) {
            $sanitized_cart[] = [
                'product_id' => $pid,
                'qty'        => $qty,
                'name'       => trim($item['name'] ?? 'Item'),
            ];
        } else {
            error_log("ITEM[$index] SKIPPED — pid=$pid qty=$qty invalid");
        }
    }

    error_log("SANITIZED_CART COUNT: " . count($sanitized_cart));
    error_log("SANITIZED_CART: " . json_encode($sanitized_cart));

    $cart             = $sanitized_cart;
    $lat              = (float)($data['lat']     ?? 0);
    $lng              = (float)($data['lng']     ?? 0);
    $pincode          = trim($data['pincode']    ?? '');
    $village_provided = trim($data['village']    ?? '');

    if (empty($cart)) {
        error_log("DEBUG: sanitized cart EMPTY — all items invalid");
        ob_clean();
        echo json_encode([
            'success'        => false,
            'message'        => 'No valid products in cart. Check product_id and qty.',
            'debug_raw_cart' => $raw_cart,
        ]);
        ob_end_flush();
        exit;
    }

    // ── Resolve Village Coordinates ──
    if (!empty($village_provided) && !empty($pincode)) {
        $stmt_reg = $pdo->prepare("SELECT latitude, longitude FROM geo_registry WHERE LOWER(TRIM(village_name)) = LOWER(TRIM(?)) AND pincode = ? LIMIT 1");
        $stmt_reg->execute([$village_provided, $pincode]);
        $reg_data = $stmt_reg->fetch();
        if ($reg_data) {
            $lat = (float)$reg_data['latitude'];
            $lng = (float)$reg_data['longitude'];
            error_log("MATCH_ENGINE: Village coords overwritten → lat=$lat, lng=$lng");
        }
    }

    // ── Smart Radius Search ──
    $p_ids = array_column($cart, 'product_id');
    error_log("P_IDS: " . json_encode($p_ids));
    
    // CRITICAL FIX: Prevent SQL IN () error if p_ids is somehow empty
    if (empty($p_ids)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'No valid products found.']);
        exit;
    }

    $placeholders  = implode(',', array_fill(0, count($p_ids), '?'));
    $search_radius = 6;
    $max_radius    = 8;
    $stock_data    = [];

    while ($search_radius <= $max_radius) {
        $query  = "SELECT shop_id, product_id, current_stock, sale_price 
                   FROM Groceries_product_marketplace_cache 
                   WHERE product_id IN ($placeholders) AND current_stock > 0";
        $params = $p_ids;

        if ($lat != 0 && $lng != 0) {
            $lat_offset = $search_radius / 111.0;
            $lng_offset = $search_radius / (111.0 * cos(deg2rad($lat)));
            $query .= " AND (shop_latitude BETWEEN ? AND ?) AND (shop_longitude BETWEEN ? AND ?)";
            array_push($params, $lat - $lat_offset, $lat + $lat_offset, $lng - $lng_offset, $lng + $lng_offset);
        }

        error_log("SQL (radius=$search_radius): $query");
        error_log("SQL PARAMS: " . json_encode($params));

        $stmt       = $pdo->prepare($query);
        $stmt->execute($params);
        $stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("STOCK_DATA COUNT at radius=$search_radius: " . count($stock_data));

        if (!empty($stock_data)) break;
        $search_radius += 2;
    }

    // ── Group by Shop ──
    $shop_scores = [];
    foreach ($stock_data as $row) {
        $sid = $row['shop_id'];
        if (!isset($shop_scores[$sid])) {
            $shop_scores[$sid] = ['count' => 0, 'items' => [], 'total_val' => 0];
        }
        $shop_scores[$sid]['count']++;
        $shop_scores[$sid]['items'][]    = $row['product_id'];
        $shop_scores[$sid]['total_val'] += $row['sale_price'];
    }

    error_log("SHOP_SCORES: " . json_encode($shop_scores));

    if (empty($shop_scores)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'No shops currently have these items in stock.']);
        ob_end_flush();
        exit;
    }

    // ── MAX Items Shop ──
    $max_items = 0;
    foreach ($shop_scores as $s) {
        if ($s['count'] > $max_items) $max_items = $s['count'];
    }

    // ── Nearest Shop Tie-Breaker ──
    $eligible_shops = [];
    foreach ($shop_scores as $id => $s) {
        if ($s['count'] === $max_items) $eligible_shops[] = $id;
    }

    $placeholders_eligible = implode(',', array_fill(0, count($eligible_shops), '?'));
    $stmt_nearest = $pdo->prepare("
        SELECT id FROM shop_owners 
        WHERE id IN ($placeholders_eligible)
        ORDER BY (ABS(latitude - ?) + ABS(longitude - ?)) ASC 
        LIMIT 1
    ");
    $stmt_nearest->execute(array_merge($eligible_shops, [$lat, $lng]));
    $best_shop_id_res = $stmt_nearest->fetchColumn();
    $best_shop_id     = $best_shop_id_res ?: $eligible_shops[0];

    error_log("BEST_SHOP_ID: $best_shop_id");

    // ── Found Items & Subtotal ──
    $available_pids      = $shop_scores[$best_shop_id]['items'];
    $missing_items       = [];
    $found_items_details = [];
    $found_subtotal      = 0;
    $db_prices           = array_column($stock_data, 'sale_price', 'product_id');

    foreach ($cart as $item) {
        if (!in_array($item['product_id'], $available_pids)) {
            $missing_items[] = $item['name'];
        } else {
            $actual_price    = (float)($db_prices[$item['product_id']] ?? 0);
            $found_subtotal += ($actual_price * $item['qty']);
            $item['price']   = $actual_price;
            $found_items_details[] = $item;
        }
    }

    error_log("FOUND_SUBTOTAL: $found_subtotal");

    if ($found_subtotal < 100) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => "Minimum ₹100 order not met. Found: ₹$found_subtotal",
        ]);
        ob_end_flush();
        exit;
    }

    // ── Shop Meta ──
    $shop_info = $pdo->prepare("SELECT shop_name, latitude, longitude FROM shop_owners WHERE id = ?");
    $shop_info->execute([$best_shop_id]);
    $s_meta = $shop_info->fetch();

    // ── Nearest Village ──
    $resolved_village = null;
    if ($lat != 0 && $lng != 0) {
        try {
            $stmt_v = $pdo->prepare("
                SELECT village_name, block_name, district_name, pincode,
                       ST_Distance_Sphere(geo_point, ST_GeomFromText(?, 4326)) AS distance_m
                FROM geo_registry 
                ORDER BY distance_m ASC 
                LIMIT 1
            ");
            $stmt_v->execute(["POINT($lng $lat)"]);
            $resolved_village = $stmt_v->fetch();
        } catch (Exception $e) {
            error_log("Spatial Resolve Error: " . $e->getMessage());
        }
    }

    // ── Delivery Fee ──
    $delivery_fee = 20.00;
    $distance_km  = 0.0;
    $eta_mins     = 0;

    if ($lat != 0 && $lng != 0 && !empty($s_meta['latitude'])) {
        $distance_info = calculateRoadDistanceAndDuration($lat, $lng, (float)$s_meta['latitude'], (float)$s_meta['longitude']);
        $distance_km   = $distance_info['distance_km'];
        $eta_mins      = $distance_info['duration_mins'];
        if ($distance_km > 2) $delivery_fee += (ceil($distance_km) - 2) * 10;
    }

    // ── Fees ──
    $platform_fee = $found_subtotal * 0.03;
    $handling_fee = $found_subtotal * 0.01;
    $grand_total  = $found_subtotal + $delivery_fee + $handling_fee + $platform_fee;

    $has_coupons = (bool)$pdo->query("SELECT id FROM coupons WHERE is_active = 1 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) LIMIT 1")->fetch();

    error_log("SUCCESS — Shop: " . ($s_meta['shop_name'] ?? 'unknown') . " | Grand: $grand_total");
    error_log("══════════ END DEBUG ══════════");

    ob_clean();
    echo json_encode([
        'success'            => true,
        'shop_id'            => (int)$best_shop_id,
        'shop_name'          => $s_meta['shop_name'],
        'missing'            => $missing_items,
        'distance_km'        => round($distance_km, 2),
        'eta_mins'           => $eta_mins,
        'resolved_geo'       => $resolved_village,
        'found_items_list'   => $found_items_details,
        'delivery_fee'       => round($delivery_fee, 2),
        'has_active_coupons' => $has_coupons,
        'handling_fee'       => round($handling_fee, 2),
        'platform_fee'       => round($platform_fee, 2),
        'total_items'        => count($cart),
        'found_items'        => $max_items,
        'is_perfect'         => ($max_items === count($cart)),
        'summary'            => [
            'subtotal' => round($found_subtotal, 2),
            'delivery' => round($delivery_fee + $handling_fee, 2),
            'dist_fee' => round($delivery_fee, 2),
            'handling' => round($handling_fee, 2),
            'platform' => round($platform_fee, 2),
            'grand'    => round($grand_total, 2),
        ],
    ]);

} catch (PDOException $e) {
    error_log("PDO ERROR: " . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("GENERAL ERROR: " . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Engine Error: ' . $e->getMessage()]);
}

ob_end_flush();