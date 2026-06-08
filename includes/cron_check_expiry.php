<?php
/**
 * KhataLink Automated Expiry Checker
 * Runs daily to notify shop owners about expired products.
 */
require_once 'db.php';
require_once 'notification_service.php';

try {
    // 1. Fetch products expiring today or already expired but not notified
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.shop_id, p.exp_date, s.shop_name 
        FROM inventory_products p
        JOIN shop_owners s ON p.shop_id = s.id
        WHERE p.exp_date IS NOT NULL 
        AND p.exp_date <= CURDATE() 
        AND p.current_stock > 0
    ");
    $stmt->execute();
    $expired_items = $stmt->fetchAll();

    foreach($expired_items as $item) {
        $title = "🚨 Expiry Alert: " . $item['name'];
        $body = "Savdhan! " . $item['name'] . " ki expiry date (" . $item['exp_date'] . ") nikal chuki hai. Kripya ise inventory se hatayein.";
        sendKhataPush($pdo, (int)$item['shop_id'], 'shop', $title, $body, null, ['type' => 'inventory', 'id' => (string)$item['id']]);
    }
} catch (Exception $e) {
    error_log("Expiry Cron Error: " . $e->getMessage());
}
