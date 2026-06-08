<?php
header('Content-Type: application/json');
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');

$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'success'            => true,
    'mall_open_time'     => '00:00:00', // Forced Always Open
    'mall_close_time'    => '23:59:59', // Forced Always Open
    'mall_maint_date'    => $settings['mall_maintenance_date'] ?? '',
    'mall_force_closed'  => ($settings['mall_force_closed'] ?? '0') === '1',
    'server_time'        => date('H:i:s'),
    'server_date'        => date('Y-m-d')
]);
exit();
