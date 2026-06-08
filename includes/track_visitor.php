<?php
function track_visitor($pdo) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $page = $_SERVER['REQUEST_URI'] ?? '/';
    $date = date('Y-m-d');
    $time = date('Y-m-d H:i:s');

    // Optimization: Skip tracking for admin or repetitive internal assets
    if (strpos($page, '/assets/') !== false) return;

    // Same IP same page same day — dobara count nahi
    $stmt = $pdo->prepare("
        SELECT id FROM visitors 
        WHERE ip_address = ? 
        AND page = ? 
        AND visit_date = ?
    ");
    $stmt->execute([$ip, $page, $date]);

    if(!$stmt->fetch()) {
        $insert = $pdo->prepare("
            INSERT INTO visitors (ip_address, page, visit_date, visit_time) 
            VALUES (?, ?, ?, ?)
        ");
        $insert->execute([$ip, $page, $date, $time]);
    }
}
?>