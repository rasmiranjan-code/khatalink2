<?php
session_start();
if (isset($_SESSION['admin_id'])) {
    error_log("LOGOUT_SUCCESS: User ID: " . $_SESSION['admin_id'] . ", Type: admin, IP: " . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
}

session_destroy();
header("Location: login.php");
exit();
?>