<?php
ob_start();
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) exit(json_encode(['success'=>false]));
header('Content-Type: application/json');

$db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();

// ══ 1. BASIC STATS ══
$active_users = $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visitors WHERE visit_time > (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
$orders_today = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// ══ 2. REVENUE TICKER ══
$revenue_today      = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE(created_at) = CURDATE() AND order_status != 'cancelled'")->fetchColumn();
$revenue_month      = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND order_status != 'cancelled'")->fetchColumn();
$revenue_prev_month = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE MONTH(created_at) = MONTH(NOW() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(NOW() - INTERVAL 1 MONTH) AND order_status != 'cancelled'")->fetchColumn();
$revenue_growth     = $revenue_prev_month > 0 ? round((($revenue_month - $revenue_prev_month) / $revenue_prev_month) * 100, 1) : 0;

// ══ NEW: NETWORK RISK MONITOR ══
$bad_debt_sum = $pdo->query("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE status = 'open' AND DATEDIFF(NOW(), created_at) > 30")->fetchColumn();
$risk_level = ($bad_debt_sum > 50000) ? 'CRITICAL' : (($bad_debt_sum > 10000) ? 'ELEVATED' : 'STABLE');

// ══ 3. LIVE ORDER HEATMAP ══
// Uses your actual orders table columns: order_status, delivery_village, latitude, longitude
$order_heatmap = [];
try {
    $recent_orders = $pdo->query("
        SELECT o.id, o.total_amount, o.created_at, o.order_status AS status,
               c.name AS customer_name,
               o.delivery_village AS city, o.latitude, o.longitude
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.created_at > (NOW() - INTERVAL 24 HOUR)
        ORDER BY o.created_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    $city_coords = [
        'mumbai'      => [19.0760, 72.8777], 'delhi'       => [28.6139, 77.2090],
        'bangalore'   => [12.9716, 77.5946], 'hyderabad'   => [17.3850, 78.4867],
        'chennai'     => [13.0827, 80.2707], 'kolkata'     => [22.5726, 88.3639],
        'pune'        => [18.5204, 73.8567], 'ahmedabad'   => [23.0225, 72.5714],
        'jaipur'      => [26.9124, 75.7873], 'bhubaneswar' => [20.2961, 85.8245],
        'surat'       => [21.1702, 72.8311], 'lucknow'     => [26.8467, 80.9462],
        'patna'       => [25.5941, 85.1376], 'ranchi'      => [23.3441, 85.3096],
        'raipur'      => [21.2514, 81.6296], 'bhopal'      => [23.2599, 77.4126],
        'nagpur'      => [21.1458, 79.0882], 'visakhapatnam'=> [17.6868, 83.2185],
    ];

    foreach ($recent_orders as $order) {
        // ── FIX: Better numeric check instead of empty() which fails on some coordinate formats ──
        $lat = (isset($order['latitude']) && is_numeric($order['latitude']) && (float)$order['latitude'] != 0) 
               ? (float)$order['latitude'] : null;
        $lng = (isset($order['longitude']) && is_numeric($order['longitude']) && (float)$order['longitude'] != 0) 
               ? (float)$order['longitude'] : null;

        // Fallback to city-based coordinates
        if (!$lat && !empty($order['city'])) {
            $city_key = strtolower(trim($order['city']));
            foreach ($city_coords as $city => $coords) {
                if (str_contains($city_key, $city)) {
                    $lat = $coords[0] + (rand(-50, 50) / 1000);
                    $lng = $coords[1] + (rand(-50, 50) / 1000);
                    break;
                }
            }
        }

        if ($lat && $lng) {
            $order_heatmap[] = [
                'id'       => $order['id'],
                'lat'      => $lat,
                'lng'      => $lng,
                'amount'   => (float)$order['total_amount'],
                'customer' => $order['customer_name'] ?? 'Unknown',
                'city'     => $order['city'] ?? 'Unknown',
                'status'   => $order['status'],
                'time'     => date('H:i:s', strtotime($order['created_at']))
            ];
        }
    }
} catch (Exception $e) {
    $order_heatmap = [];
}

// ══ 4. SQL QUERY MONITOR ══
$sql_queries = [];
try {
    $running = $pdo->query("
        SELECT id, user, host, db, command, time, state, LEFT(info, 120) AS query_text
        FROM information_schema.PROCESSLIST
        WHERE command != 'Sleep' AND db = '$db_name'
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($running as $proc) {
        if (!empty($proc['query_text'])) {
            $qt = strtoupper(trim($proc['query_text']));
            $type = str_starts_with($qt,'SELECT') ? 'SELECT' :
                   (str_starts_with($qt,'INSERT') ? 'INSERT' :
                   (str_starts_with($qt,'UPDATE') ? 'UPDATE' :
                   (str_starts_with($qt,'DELETE') ? 'DELETE' : 'QUERY')));
            $sql_queries[] = [
                'id'    => $proc['id'],
                'query' => $proc['query_text'],
                'time'  => (int)$proc['time'],
                'state' => $proc['state'] ?: 'executing',
                'user'  => $proc['user'],
                'type'  => $type,
            ];
        }
    }
} catch (Exception $e) {
    $sql_queries = [];
}

// ══ 5. VISITOR FINGERPRINTING ══
    function parse_ua($ua) {
        $os = 'Unknown'; $browser = 'Unknown'; $device = 'Desktop';
        if (empty($ua)) return compact('os','browser','device');
        if (str_contains($ua,'Windows NT 10'))      $os = 'Win10';
        elseif (str_contains($ua,'Windows NT 6.1')) $os = 'Win7';
        elseif (str_contains($ua,'Mac OS X'))       $os = 'macOS';
        elseif (str_contains($ua,'Ubuntu')||str_contains($ua,'Linux')) $os = 'Linux';
        elseif (str_contains($ua,'Android'))        { $os = 'Android'; $device = 'Mobile'; }
        elseif (str_contains($ua,'iPhone'))         { $os = 'iOS'; $device = 'Mobile'; }
        elseif (str_contains($ua,'iPad'))           { $os = 'iPadOS'; $device = 'Tablet'; }
        if      (str_contains($ua,'Chrome')&&!str_contains($ua,'Edg')) $browser = 'Chrome';
        elseif  (str_contains($ua,'Firefox'))  $browser = 'Firefox';
        elseif  (str_contains($ua,'Edg'))      $browser = 'Edge';
        elseif  (str_contains($ua,'Safari')&&!str_contains($ua,'Chrome')) $browser = 'Safari';
        elseif  (str_contains($ua,'OPR')||str_contains($ua,'Opera')) $browser = 'Opera';
        return compact('os','browser','device');
    }

$visitor_fingerprints = [];
try {
    $vf_data = $pdo->query("
        SELECT ip_address, user_agent, page, visit_time, COUNT(*) AS hit_count
        FROM visitors
        WHERE visit_time > (NOW() - INTERVAL 10 MINUTE)
        GROUP BY ip_address, user_agent
        ORDER BY visit_time DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $visitor_fingerprints = array_map(function($v) {
        $fp = parse_ua($v['user_agent'] ?? '');
        return [
            'ip'        => $v['ip_address'],
            'os'        => $fp['os'],
            'browser'   => $fp['browser'],
            'device'    => $fp['device'],
            'hits'      => (int)$v['hit_count'],
            'last_page' => basename($v['page'] ?: 'index.php'),
            'time'      => date('H:i:s', strtotime($v['visit_time']))
        ];
    }, $vf_data);
} catch (Exception $e) { $visitor_fingerprints = []; } // SILENT FAIL: Stops spamming the terminal trace

// ══ 6. DB SIZE ══
$db_size_mb = 0; $db_percent = 0;
try {
    $db_size_kb      = $pdo->query("SELECT SUM(data_length+index_length)/1024 FROM information_schema.TABLES WHERE table_schema='$db_name'")->fetchColumn();
    $db_size_mb      = round($db_size_kb / 1024, 2);
    $quota_stmt      = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='db_storage_quota_mb'");
    $quota           = (float)($quota_stmt->fetchColumn() ?: 1024);
    $db_percent      = round(($db_size_mb / $quota) * 100, 1);
} catch (Exception $e) {}

// ══ 7. API HITS + GEO ══
$api_hits = (int)$pdo->query("SELECT COUNT(*) FROM visitors WHERE visit_time > (NOW() - INTERVAL 1 MINUTE)")->fetchColumn();

$ip_geo_cache = [];
function geolocate_ip($ip) {
    global $ip_geo_cache;
    
    // ── LOCAL TESTING OVERRIDE: Show pins on map even for localhost ──
    if ($ip === '::1' || $ip === '127.0.0.1' || str_starts_with($ip,'192.168.')) {
        return ['lat'=>20.5937, 'lng'=>78.9629, 'city'=>'Local', 'country'=>'Node'];
    }

    if (isset($ip_geo_cache[$ip])) return $ip_geo_cache[$ip];
    $ch = curl_init("http://ip-api.com/json/$ip?fields=lat,lon,city,country");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $r  = curl_exec($ch); curl_close($ch);
    $g  = json_decode($r, true);
    if ($g && isset($g['lat'])) {
        return $ip_geo_cache[$ip] = ['lat'=>$g['lat'],'lng'=>$g['lon'],'city'=>$g['city'],'country'=>$g['country']];
    }
    return false;
}

// ══ 8. LOGS ══
$log_file = '../debug_error.log';
$api_metrics = []; $new_logs = []; $recent_errors = 0; $login_events = []; $logout_events = [];
if (file_exists($log_file)) {
    // ── INCREASE BUFFER: Read last 100 lines to ensure logins aren't missed during traffic spikes ──
    foreach (array_slice(file($log_file), -100) as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        preg_match('/^\[(.*?)\] (.*)$/', $line, $m);
        $time_only = explode(' ', $m[1] ?? '')[1] ?? date('H:i:s');
        $message   = $m[2] ?? $line;

        if (str_contains($message, '[API_HIT]')) {
            preg_match('/\[API_HIT\] (.*) \| Speed: (.*)ms \| IP: (.*)/', $message, $hm);
            if ($hm) {
                $ip  = trim($hm[3]);
                $geo = geolocate_ip($ip);
                $api_metrics[] = [
                    'uri'      => basename($hm[1]),
                    'ms'       => $hm[2],
                    'ip'       => $ip,
                    'lat'      => $geo ? $geo['lat'] : null,
                    'lng'      => $geo ? $geo['lng'] : null,
                    'location' => $geo ? ($geo['city'].', '.$geo['country']) : 'Unknown',
                ];
                continue;
            }
        }

        $level  = 'INFO';
        if (stripos($message,'PHP Warning')  !== false)                                                $level = 'WARNING';
        if (stripos($message,'PHP Fatal')    !== false)                                                $level = 'FATAL';
        if (stripos($message,'SQLSTATE')     !== false || stripos($message,'PDOException') !== false)  $level = 'DB_ERROR';
        if (stripos($message,'ERROR')        !== false)                                                $level = 'ERROR';
        if (stripos($message,'DEBUG')        !== false)                                                $level = 'DEBUG';

        $module = 'System';
        if      (stripos($message,'/customer/')  !== false) $module = 'Customer';
        elseif  (stripos($message,'/shop/')      !== false) $module = 'Shop';
        elseif  (stripos($message,'/admin/')     !== false) $module = 'Admin';
        elseif  (stripos($message,'/delivery/')  !== false) $module = 'Rider';

        if (in_array($level,['ERROR','FATAL','DB_ERROR'])) $recent_errors++;

        if (stripos($message,'LOGIN_SUCCESS') !== false) {
            $ev = sync_user_location($pdo, $message, 'LOGIN_SUCCESS');
            if ($ev) $login_events[] = $ev;
        }
        elseif (stripos($message,'LOGOUT_SUCCESS') !== false) {
            $ev = sync_user_location($pdo, $message, 'LOGOUT_SUCCESS');
            if ($ev) $logout_events[] = $ev;
        }

        $new_logs[] = ['timestamp'=>$time_only,'level'=>$level,'module'=>$module,'message'=>$message];
    }
}

/**
 * Extract User details from log and sync with DB Coordinates
 */
function sync_user_location($pdo, $message, $prefix) {
    preg_match('/User ID: (\d+)/', $message, $idm);
    preg_match('/Type: (\w+)/', $message, $tm);
    preg_match('/IP: ([\d\.]+)/', $message, $im);

    $uid  = $idm[1] ?? 0;
    $type = strtolower($tm[1] ?? '');
    $ip   = $im[1] ?? '0.0.0.0';

    $lat = null; $lng = null; $loc = 'Searching...';

    // REAL-TIME SYNC: Fetch actual coords from DB
    if ($uid > 0 && !empty($type)) {
        $table = ($type === 'shop') ? 'shop_owners' : (($type === 'delivery' || $type === 'rider') ? 'delivery_partners' : 'customers');
        try {
            $u_stmt = $pdo->prepare("SELECT latitude, longitude FROM $table WHERE id = ? LIMIT 1");
            $u_stmt->execute([$uid]);
            $row = $u_stmt->fetch();
            if ($row && $row['latitude']) {
                $lat = (float)$row['latitude'];
                $lng = (float)$row['longitude'];
                $loc = "DB Sync: " . ucfirst($type);
            }
        } catch(Exception $e) {}
    }

    // IP Fallback if DB coordinates missing
    if (!$lat) {
        $geo = geolocate_ip($ip);
        if ($geo) { $lat = $geo['lat']; $lng = $geo['lng']; $loc = $geo['city'] . ', ' . $geo['country']; }
        else { $loc = "Internal Network"; }
    }

    if ($lat === null) return null; // Don't return invalid pins

    return [
        'event_id' => md5($message . $ip), // Unique ID per log entry
        'message'  => $message,
        'ip'       => $ip,
        'lat'      => $lat,
        'lng'      => $lng,
        'location' => $loc . ($uid > 0 ? " (UID: $uid)" : ""),
        'role'     => $type // Send role for pin coloring
    ];
}

// ══ 9. RECENT API CALLS ══
$recent_api_calls = [];
try {
    $pages = $pdo->query("SELECT page FROM visitors ORDER BY visit_time DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
    $recent_api_calls = array_values(array_filter(array_unique(array_map(fn($p)=>basename($p)?:'index.php', $pages))));
} catch (Exception $e) {}

// ══ 10. SECURITY EVENTS ══
$security_events = [];
try {
    $security_events = $pdo->query("SELECT ip_address, request_type FROM db_access_logs WHERE is_found = 0 ORDER BY hit_timestamp DESC LIMIT 5")->fetchAll();
} catch (Exception $e) {}

// ══ 11. SIMULATED HARDWARE ══
$cpu      = $api_hits > 50 ? rand(60,90) : rand(15,45);
$ram      = round(memory_get_usage()/1024/1024, 2) + rand(100,200);
$is_attack = ($api_hits > 120);

ob_clean();
echo json_encode([
    'cpu'                 => $cpu,
    'ram'                 => $ram,
    'active_users'        => (int)$active_users,
    'orders_today'        => (int)$orders_today,
    'db_size'             => $db_size_mb,
    'db_percent'          => $db_percent,
    'api_hits'            => $api_hits,
    'is_attack'           => $is_attack,
    'live_entities'       => fetch_live_entities($pdo), // NEW: Direct DB Check for online users
    'bad_debt'            => (float)$bad_debt_sum,
    'network_risk'        => $risk_level,
    'logs'                => $new_logs,
    'api_metrics'         => $api_metrics,
    'recent_api_calls'    => $recent_api_calls,
    'recent_errors'       => $recent_errors,
    'security_events'     => $security_events,
    'login_events'        => $login_events,
    'logout_events'       => $logout_events,
    'revenue_today'       => (float)$revenue_today,
    'revenue_month'       => (float)$revenue_month,
    'revenue_growth'      => $revenue_growth,
    'order_heatmap'       => $order_heatmap,
    'sql_queries'         => $sql_queries,
    'visitor_fingerprints'=> $visitor_fingerprints,
]);

/**
 * NEW: Fetch currently online/active users directly from DB tables
 */
function fetch_live_entities($pdo) {
    $entities = [];
    
    // 1. Shops (is_online = 1)
    $stmt = $pdo->query("SELECT id, shop_name as name, 'shop' as role, latitude as lat, longitude as lng FROM shop_owners WHERE is_online = 1 AND latitude != 0");
    while($r = $stmt->fetch()) { 
        $r['id'] = 'shop_' . $r['id'];
        $entities[] = $r; 
    }

    // 2. Delivery Partners (status online)
    $stmt = $pdo->query("SELECT id, name, 'delivery' as role, COALESCE(NULLIF(current_lat,0), latitude) as lat, COALESCE(NULLIF(current_lng,0), longitude) as lng FROM delivery_partners WHERE status LIKE 'online%' AND (current_lat != 0 OR latitude != 0)");
    while($r = $stmt->fetch()) { 
        $r['id'] = 'delivery_' . $r['id'];
        $entities[] = $r; 
    }

    // 3. New Customers (Account created in last 24h OR active with coordinates)
    $stmt = $pdo->query("
        SELECT id, name, 'customer' as role, latitude as lat, longitude as lng, created_at 
        FROM customers 
        WHERE (created_at > (NOW() - INTERVAL 24 HOUR) OR latitude != 0)
        AND latitude IS NOT NULL AND latitude != 0
        LIMIT 30
    ");
    while($r = $stmt->fetch()) { 
        $r['id'] = 'customer_' . $r['id'];
        $r['is_new'] = (strtotime($r['created_at']) > strtotime('-1 hour'));
        $entities[] = $r; 
    }

    return $entities;
}