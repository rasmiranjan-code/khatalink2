<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once '../includes/db.php';

$q      = trim($_GET['q']      ?? '');
$lat    = (float)($_GET['lat'] ?? 0);
$lng    = (float)($_GET['lng'] ?? 0);
$radius = isset($_GET['radius']) && (int)$_GET['radius'] > 0
          ? (int)$_GET['radius'] : 15;
$sort   = trim($_GET['sort']   ?? 'default');

// Min 2 chars
if (strlen($q) < 2) {
    echo json_encode([]);
    exit();
}

try {
    // ══════════════════════════════════════════════════════
    // PROBLEM WAS: SELECT alias params were being bound
    // BEFORE WHERE params — but MySQL PDO binds params
    // left-to-right as they appear in the SQL string.
    //
    // FIX: Use subquery so distance is computed cleanly,
    // and ALL params are bound in the order they appear.
    // ══════════════════════════════════════════════════════

    $params = [];

    // ── Base SELECT ──
    // Include primary_unit which Flutter card needs
    $query  = "SELECT product_id, shop_id, name, sale_price,
                      image_thumb_path, primary_unit,
                      current_stock, average_rating,
                      total_ratings_count";

    // ── Distance column (only if coords given) ──
    if ($lat != 0 && $lng != 0) {
        $query   .= ", (ABS(shop_latitude - ?) + ABS(shop_longitude - ?)) AS dist";
        $params[] = $lat;
        $params[] = $lng;
    }

    // ── FROM + mandatory WHERE ──
    // Removed current_stock > 0 — show all products in search
    // (out-of-stock shown with badge, not hidden)
    $query   .= " FROM Groceries_product_marketplace_cache
                  WHERE (name LIKE ? OR product_category LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";   // also search category name

    // ── Hyperlocal radius filter ──
    if ($lat != 0 && $lng != 0) {
        $lat_offset = $radius / 111.0;
        $lng_offset = $radius / (111.0 * cos(deg2rad($lat)));
        $query   .= " AND shop_latitude  BETWEEN ? AND ?
                      AND shop_longitude BETWEEN ? AND ?";
        $params[] = $lat - $lat_offset;
        $params[] = $lat + $lat_offset;
        $params[] = $lng - $lng_offset;
        $params[] = $lng + $lng_offset;
    }

    // ── ORDER BY ──
    // In-stock products always first, then sort preference
    $stock_first   = "(current_stock > 0) DESC";
    $distance_sort = ($lat != 0 && $lng != 0) ? "dist ASC, " : "";

    if ($sort === 'price_asc') {
        $query .= " ORDER BY $stock_first, {$distance_sort}sale_price ASC";
    } elseif ($sort === 'price_desc') {
        $query .= " ORDER BY $stock_first, {$distance_sort}sale_price DESC";
    } elseif ($sort === 'rating_desc') {
        $query .= " ORDER BY $stock_first, {$distance_sort}average_rating DESC";
    } else {
        // Default: in-stock first → nearest → name
        $query .= " ORDER BY $stock_first, {$distance_sort}name ASC";
    }

    $query .= " LIMIT 10";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Remove internal 'dist' field before sending to client
    foreach ($results as &$row) {
        unset($row['dist']);
    }
    unset($row);

    echo json_encode($results);

} catch (Exception $e) {
    error_log('[ajax_marketplace_search] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([]);   // empty array — Flutter handles gracefully
}