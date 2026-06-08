<?php
/**
 * KhataLink Groceries - Automatic Rider Assignment Engine
 *
 * BUGS FIXED:
 * Bug #1 — REMOVED echo debug HTML (breaks Flutter JSON API response)
 * Bug #2 — status condition: 'online_idle' OR 'online' OR 'available' (restaurant riders match)
 * Bug #3 — Global pending block replaced: sirf IS ORDER ke rejected/timed_out riders block honge
 * Bug #4 — rider_not_found UPDATE engine se HATA diya — caller handle karega
 * Bug #5 — DEBUG $all query mein bhi bounding box filter laga diya (unnecessary riders fetch nahi)
 * Bug #6 — $sid alag query nahi — $order['shop_id'] directly use kiya
 * Bug #7 — Dead variables $has_riders/$has_eligible/$has_online REMOVE kar diye
 */

function groceries_assign_best_rider(PDO $pdo, int $order_id): bool {

    // ── 1. Fetch Order + Shop ──────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT o.*, s.shop_name, s.latitude AS shop_lat, s.longitude AS shop_lng
        FROM orders o
        JOIN shop_owners s ON o.shop_id = s.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) return false;

    $lat   = (float)$order['shop_lat'];
    $lng   = (float)$order['shop_lng'];
    $range = 3; // STRICT 3KM RADIUS

    if ($lat == 0 || $lng == 0) return false;

    // ── 2. Bounding Box ───────────────────────────────────────────────────
    $lat_offset = $range / 111.0;
    $lng_offset = $range / (111.0 * cos(deg2rad($lat)));
    $min_lat = $lat - $lat_offset; $max_lat = $lat + $lat_offset;
    $min_lng = $lng - $lng_offset; $max_lng = $lng + $lng_offset;

    // ── 3. Final Query ────────────────────────────────────────────────────
    // FIX Bug #2: status IN ('online_idle','online','available') — restaurant riders bhi match karein
    // FIX Bug #3: Global pending block HATA diya — sirf is order ke reject/timed_out block honge
    //             Riders jo abhi kisi aur order pe 'assigned'/'picked_up' hain woh orders table se block
    $query = "
        SELECT id, name,
            (6371 * ACOS(
                COS(RADIANS(:lat)) * COS(RADIANS(COALESCE(NULLIF(current_lat,0), latitude)))
                * COS(RADIANS(COALESCE(NULLIF(current_lng,0), longitude)) - RADIANS(:lng))
                + SIN(RADIANS(:lat)) * SIN(RADIANS(COALESCE(NULLIF(current_lat,0), latitude)))
            )) AS distance
        FROM delivery_partners
        WHERE is_active = 1
          AND is_verified = 1
          AND status IN ('online_idle', 'online', 'available')
          AND COALESCE(NULLIF(current_lat,0), latitude)  IS NOT NULL
          AND COALESCE(NULLIF(current_lng,0), longitude) IS NOT NULL
          AND COALESCE(NULLIF(current_lat,0), latitude)  BETWEEN :min_lat AND :max_lat
          AND COALESCE(NULLIF(current_lng,0), longitude) BETWEEN :min_lng AND :max_lng
          AND id NOT IN (
              SELECT delivery_boy_id FROM delivery_assignments
              WHERE order_id = :order_id
                AND assignment_status IN ('rejected', 'timed_out')
          )
          AND id NOT IN (
              SELECT DISTINCT delivery_boy_id FROM orders
              WHERE order_status IN ('assigned', 'picked_up')
                AND delivery_boy_id IS NOT NULL
          )
        ORDER BY distance ASC
        LIMIT 1
    ";

    $rider_stmt = $pdo->prepare($query);
    $rider_stmt->execute([
        ':lat'      => $lat,
        ':lng'      => $lng,
        ':min_lat'  => $min_lat,
        ':max_lat'  => $max_lat,
        ':min_lng'  => $min_lng,
        ':max_lng'  => $max_lng,
        ':order_id' => $order_id,
    ]);
    $rider = $rider_stmt->fetch();

    // FIX Bug #1: echo debug HTML HATA diya — Flutter ko sirf JSON milega

    if ($rider) {
        // Strictly clean up ALL previous pending attempts for this order
        $pdo->prepare("DELETE FROM delivery_assignments WHERE order_id = ? AND assignment_status = 'pending'")->execute([$order_id]);
        $pdo->prepare("INSERT INTO delivery_assignments (order_id, delivery_boy_id, assignment_status) VALUES (?, ?, 'pending')")->execute([$order_id, $rider['id']]);
        $pdo->prepare("UPDATE orders SET delivery_boy_id = ? WHERE id = ?")->execute([$rider['id'], $order_id]);

        sendKhataPush(
            $pdo,
            (int)$rider['id'],
            'delivery',
            "Naya Quick Order! 🛵",
            "{$order['shop_name']} se pickup karein. Order #$order_id",
            null,
            ['type' => 'grocery_order', 'order_id' => (string)$order_id]
        );
        return true;
    }

    // FIX Bug #4: rider_not_found UPDATE yahaan se HATA diya — caller (dashboard) handle karega
    // FIX Bug #6: $order['shop_id'] directly use kiya — alag SELECT query waste nahi
    sendKhataPush(
        $pdo,
        (int)$order['shop_id'],
        'shop',
        "Rider Nahi Mila ⚠️",
        "Order #$order_id ke liye koi rider nahi mila. Manually assign karein."
    );

    return false;
}
?>