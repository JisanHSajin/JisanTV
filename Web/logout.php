<?php
// ============================================================
// Logout Handler
// ============================================================

session_start();
include "db.php";
include "config.php";
include "session_helper.php";

$session_manager = new SessionManager($conn);
$session_manager->destroyPersistentSession();

session_destroy();
header("Location: login.php");
exit;
?>