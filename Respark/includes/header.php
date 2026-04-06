<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Smart Parking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">🚗 Smart Parking</span>
        <?php if(isset($_SESSION['user_id'])): ?>
            <a href="../auth/logout.php" class="btn btn-danger">Logout</a>
        <?php endif; ?>
    </div>
</nav>

<div class="container mt-4"></div>