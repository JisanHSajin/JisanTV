<?php
// ============================================================
// Database Connection
// ============================================================

// Database credentials
$db_host = "xxxxxxxxxxxxxxxxxxxxxxxxxxx";
$db_user = "xxxxxxxxxxxxxxxxxxxxxxxxxxx";
$db_pass = "xxxxxxxxxxxxxxxxxxxxxxxxxxx";
$db_name = "xxxxxxxxxxxxxxxxxxxxxxxxxxx";

// Establish connection
$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

// Set timezone
date_default_timezone_set('Asia/Dhaka');

// Check connection
if (!$conn) {
    die(json_encode(["error" => "Database Connection Failed"]));
}
?>