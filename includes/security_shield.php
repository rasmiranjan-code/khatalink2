<?php
/**
 * KhataLink Security Shield - Rate Limiter
 * Blocks suspicious IPs based on failed DB lookups.
 */

function checkRateLimit(PDO $pdo) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $limit_window = 60; // Seconds
    $max_failed_hits = 50; // Threshold for suspicious activity

    // Check how many failed attempts (is_found = 0) in the last minute
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM db_access_logs 
        WHERE ip_address = ? 
        AND is_found = 0 
        AND hit_timestamp > (NOW() - INTERVAL ? SECOND)
    ");
    $stmt->execute([$ip, $limit_window]);
    $failed_count = (int)$stmt->fetchColumn();

    if ($failed_count >= $max_failed_hits) {
        // Log the block event for admin
        error_log("SECURITY ALERT: IP $ip blocked due to rate limiting.");
        
        header('Content-Type: application/json');
        http_response_code(429); // Too Many Requests
        exit(json_encode([
            'success' => false,
            'message' => 'Access Denied: Suspicious activity detected. Your IP is temporarily throttled.'
        ]));
    }
}

/**
 * Call this function whenever a search/lookup fails
 */
function logDbHit(PDO $pdo, string $type, ?string $req_id, bool $found) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $stmt = $pdo->prepare("
            INSERT INTO db_access_logs (ip_address, request_type, requested_id, is_found)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$ip, $type, $req_id, $found ? 1 : 0]);
    } catch (Exception $e) {
        // Fail silently to not disrupt the user flow
        error_log("Logging error: " . $e->getMessage());
    }
}
?>