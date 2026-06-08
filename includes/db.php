<?php
if (!defined('KHATALINK_START_TIME')) define('KHATALINK_START_TIME', microtime(true)); // Start execution timer
$host     = "localhost";
$dbname   = "khatalink";
$username = "root";
$password = "";

// ── GLOBAL ERROR LOGGING ──
ini_set('log_errors', 1); // Enable error logging
ini_set('error_log', dirname(__DIR__) . '/debug_error.log'); // Set global error log file
error_reporting(E_ALL);

// ── PERFORMANCE TRACKING ──
// Jab bhi koi script khatam hogi, wo apni speed log karegi
register_shutdown_function(function() {
    $duration = round((microtime(true) - KHATALINK_START_TIME) * 1000);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
    
    // Sirf APIs aur important files log karein (noise kam karne ke liye)
    if (str_contains($uri, '.php') && !str_contains($uri, 'ajax_hacker_stats')) {
        error_log("[API_HIT] $uri | Speed: {$duration}ms | IP: $ip");
    }
});

// ── SECURITY CONFIG ──
define('SERVER_SECRET', 'khata_link_premium_9988_secret'); // Kabhi kisi ko mat batana

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Initialize Security Shield
    require_once 'security_shield.php';
    if (isset($pdo)) {
        checkRateLimit($pdo);
    }

    // ── SECURE TOKEN HELPER ──
    function generate_secure_token(int $id, string $email, string $role): string {
        return base64_encode("$id:$email:$role");
    }

    function get_auth_token(): string {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (empty($auth_header) && function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            $auth_header = $headers['authorization'] ?? '';
        }
        return trim(str_ireplace('Bearer ', '', $auth_header));
    }

    function verify_secure_token(string $token) {
        $token = str_replace(' ', '+', $token); // Fix space-to-plus corruption
        $decoded = base64_decode($token);

        if ($decoded && str_contains($decoded, ':')) {
            $parts = explode(':', $decoded);

            // Case: Handle Flutter Wrap if present: "id:base64_payload"
            if (count($parts) == 2) {
                $inner_decoded = base64_decode(str_replace(' ', '+', $parts[1]));
                if ($inner_decoded && str_contains($inner_decoded, ':')) {
                    return explode(':', $inner_decoded);
                }
            }
            return $parts; // Return direct "id:email:role" parts
        }
        return false;
    }

    // ── SECURE CORS CHECK ──
    function check_cors() {
        // Allow requests from the Flutter App
        $is_app = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp');
        if ($is_app) return;

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin) {
            $current_host = $_SERVER['HTTP_HOST'] ?? '';
            // SMART CHECK: Agar origin mein aapka host (localhost/IP/domain) maujood hai, toh allow karein
            if (strpos($origin, $current_host) !== false) return;

            // Fallback for hardcoded localhost (dev environment)
            if (strpos($origin, "http://localhost") !== 0 && strpos($origin, "http://127.0.0.1") !== 0) {
                http_response_code(403);
                exit(json_encode(['success' => false, 'message' => 'CORS Policy Violation']));
            }
        }
    }

    // ── SHARED CART HYDRATION (API Consistency) ──
    function hydrate_cart(PDO $pdo, array $raw_cart): array {
        if (empty($raw_cart)) return [];
        $sanitized = [];
        foreach ($raw_cart as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = min(10, max(0, (float)($item['qty'] ?? 0))); // FORCE MAX 10 LIMIT
            if ($pid > 0 && $qty > 0) $sanitized[$pid] = $qty;
        }
        if (empty($sanitized)) return [];

        $ids = array_keys($sanitized);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT product_id, name, sale_price as price, primary_unit as unit, image_thumb_path FROM Groceries_product_marketplace_cache WHERE product_id IN ($placeholders)");
        $stmt->execute($ids);
        $db_products = $stmt->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

        $final = [];
        foreach ($sanitized as $pid => $qty) {
            if (isset($db_products[$pid])) {
                $final[] = array_merge($db_products[$pid], [
                    'product_id' => $pid, // 👈 Explicitly add product_id back to the item
                    'qty' => $qty
                ]);
            }
        }
        return $final;
    }

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>