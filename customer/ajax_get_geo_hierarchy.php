<?php
// ── Output buffer: prevent ANY accidental HTML/whitespace leaking into JSON ──
ob_start();

// ── CORS headers first, before anything else ──
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

// ── Always respond with JSON — no HTML ever ──
header('Content-Type: application/json; charset=utf-8');

// ── Suppress PHP errors from leaking into JSON output ──
error_reporting(0);
ini_set('display_errors', 0);

// ── Session must start BEFORE require, for web requests ──
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';

// ── AUTHENTICATION LAYER ──
$customer_id = 0;
$is_api      = false;
$parts       = null;

$is_app = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp');
$token = get_auth_token();

if ($is_app || !empty($token)) {
    $is_api = true;
    if (empty($token)) {
        ob_end_clean();
        ob_clean();
        http_response_code(401);
        exit(json_encode(['success' => false, 'message' => 'Authorization token missing']));
    }
    $parts = verify_secure_token($token);
    if ($parts) $customer_id = (int)$parts[0];
} else {
    // Web session fallback
    $customer_id = $_SESSION['customer_id'] ?? 0;
}

// ── Reject unauthenticated requests with JSON ──
if ($customer_id <= 0) {
    ob_clean();
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized Access']));
}

// ── Route by ?type= ──
$type = trim($_GET['type'] ?? '');

try {
    if ($type === 'districts') {

        $stmt = $pdo->query(
            "SELECT DISTINCT TRIM(district_name) AS d
             FROM geo_registry
             WHERE district_name != ''
             ORDER BY d ASC"
        );
        $res = $stmt->fetchAll(PDO::FETCH_COLUMN);

        @ob_end_clean();
        ob_clean();
        exit(json_encode([
            'success' => true,
            'data'    => array_values($res),
            'count'   => count($res),
        ]));

    } elseif ($type === 'blocks') {

        $dist = trim($_GET['district'] ?? '');
        if (empty($dist)) {
            @ob_end_clean();
            ob_clean();
            exit(json_encode(['success' => false, 'message' => 'district parameter missing']));
        }

        $stmt = $pdo->prepare(
            "SELECT DISTINCT TRIM(block_name) AS b
             FROM geo_registry
             WHERE district_name = ? AND block_name != ''
             ORDER BY b ASC"
        );
        $stmt->execute([$dist]);
        $res = $stmt->fetchAll(PDO::FETCH_COLUMN);

        @ob_end_clean();
        ob_clean();
        exit(json_encode([
            'success' => true,
            'data'    => array_values($res),
        ]));

    } elseif ($type === 'villages') {

        $dist  = trim($_GET['district'] ?? '');
        $block = trim($_GET['block']    ?? '');

        if (empty($dist) || empty($block)) {
            @ob_end_clean();
            ob_clean();
            exit(json_encode(['success' => false, 'message' => 'district and block parameters required']));
        }

        $stmt = $pdo->prepare(
            "SELECT TRIM(village_name) AS village_name, pincode, latitude, longitude
             FROM geo_registry
             WHERE district_name = ? AND block_name = ?
             ORDER BY village_name ASC"
        );
        $stmt->execute([$dist, $block]);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);

        @ob_end_clean();
        ob_clean();
        exit(json_encode([
            'success' => true,
            'data'    => $res,
        ]));

    } else {
        ob_clean();
        http_response_code(400);
        exit(json_encode([
            'success' => false,
            'message' => 'Invalid type. Use: districts | blocks | villages',
        ]));
    }

} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    exit(json_encode([
        'success' => false,
        'message' => 'Database error',
        'debug'   => $e->getMessage(),
    ]));
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    exit(json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]));
}