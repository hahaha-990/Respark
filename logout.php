<?php
// logout.php
require_once 'config.php';
startSecureSession();
session_destroy();
header('Location: index.php');
exit;
