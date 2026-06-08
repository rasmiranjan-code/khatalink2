<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once '../includes/db.php';

// 1. User Inputs
$user_lat = (float)($_GET['lat'] ?? 0);
$user_lng = (float)($_GET['lng'] ?? 0);
$search   = trim($_GET['search'] ?? '');
$range    = (float)($_GET['range'] ?? 15); // Sync default with home page (15km)

$now_time = date('H:i:s');
$now_dt   = date('Y-m-d H:i:s');

try {
    // 3. Unified Query: Products + Shop + Rider Count
    // Order by: Price ASC, then Distance ASC, then Riders DESC
    $query = "
        SELECT 
            p.id, p.name, p.sale_price, p.photo, p.primary_unit, p.shop_id,
            s.shop_name, s.latitude as shop_lat, s.longitude as shop_lng, s.pincode,
            (CASE WHEN :ulat != 0 THEN (6371 * acos(cos(deg2rad(:ulat)) * cos(deg2rad(s.latitude)) * cos(deg2rad(s.longitude) - deg2rad(:ulng)) + sin(deg2rad(:ulat)) * sin(deg2rad(s.latitude)))) ELSE 0 END) AS distance,
            (SELECT COUNT(*) FROM delivery_partners dp WHERE dp.pincode = s.pincode AND dp.is_active = 1 AND dp.is_verified = 1) as active_riders
        FROM Groceries_product_marketplace_cache p
        JOIN shop_owners s ON p.shop_id = s.id
        WHERE s.is_verified = 1 AND s.is_online = 1 AND s.is_mall_active = 1
          AND p.current_stock > 0
    ";
    
    $params = [
        'ulat' => $user_lat,
        'ulng' => $user_lng
    ];

    // FIX: Only apply spatial filter if coordinates are valid to prevent empty results on first app load
    if($user_lat != 0 && $user_lng != 0) {
        $lat_offset = $range / 111; 
        $lng_offset = $range / (111 * cos(deg2rad($user_lat)));
        
        // FIX: Global Fallback for shops with no GPS coordinates
        $query .= " AND ( (s.latitude = 0 AND s.longitude = 0) OR (s.latitude BETWEEN :min_lat AND :max_lat
                    AND s.longitude BETWEEN :min_lng AND :max_lng) )";
        
        $params['min_lat'] = $user_lat - $lat_offset;
        $params['max_lat'] = $user_lat + $lat_offset;
        $params['min_lng'] = $user_lng - $lng_offset;
        $params['max_lng'] = $user_lng + $lng_offset;
    }

    if($search) {
        $query .= " AND (p.name LIKE :search OR s.shop_category LIKE :search)";
        $params['search'] = "%$search%";
    }

    // ── THE BRAIN: The Ranking Logic ──
    $query .= " ORDER BY p.sale_price ASC, distance ASC, active_riders DESC LIMIT 50";

    $stmt = $pdo->prepare($query);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Formatting Response
    $feed = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'price' => (float)$row['sale_price'],
            'unit' => $row['primary_unit'],
            'image' => $row['photo'] ? '../assets/img/products/' . $row['photo'] : null,
            'shop' => [
                'id' => (int)$row['shop_id'],
                'name' => $row['shop_name'],
                'distance' => round($row['distance'], 2),
                'riders' => (int)$row['active_riders']
            ],
            'delivery_tag' => ($row['active_riders'] > 0) ? 'Fast Delivery' : 'Standard'
        ];
    }, $products);

    echo json_encode(['success' => true, 'feed' => $feed]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}