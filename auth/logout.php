<?php
session_start();
$uid = $_SESSION['customer_id'] ?? $_SESSION['shop_id'] ?? $_SESSION['delivery_id'] ?? 'Unknown';
$type = isset($_SESSION['customer_id']) ? 'customer' : (isset($_SESSION['shop_id']) ? 'shop' : (isset($_SESSION['delivery_id']) ? 'delivery' : 'user'));

if ($uid !== 'Unknown') {
    error_log("LOGOUT_SUCCESS: User ID: $uid, Type: $type, IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
}

session_destroy();
header("Location: ../index.php");
exit();
?>