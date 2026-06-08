<?php
$password = "123456";
$hash = password_hash($password, PASSWORD_DEFAULT);
echo $hash;
?>