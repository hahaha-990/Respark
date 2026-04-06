<?php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['verified'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Session hijacking protection
if ($_SESSION['ip'] !== $_SERVER['REMOTE_ADDR']) {
    session_destroy();
    exit("Session hijacking detected");
}
?>