<?php
require "../includes/auth_check.php";
require "../config/db.php";
require "../includes/csrf.php";

verifyToken($_POST['csrf']);

$user_id = $_SESSION['user_id'];
$parking_id = $_POST['parking_id'];
$start = $_POST['start'];
$end = $_POST['end'];

// Insert reservation
$stmt = $pdo->prepare("
INSERT INTO reservations (UserID, ParkingID, Start_time, End_time, Status)
VALUES (?, ?, ?, ?, 'confirmed')
");
$stmt->execute([$user_id, $parking_id, $start, $end]);

$reservation_id = $pdo->lastInsertId();

// Insert payment
$stmt = $pdo->prepare("
INSERT INTO payment (ReservationID, Transaction, Amount, Payment_status)
VALUES (?, ?, ?, 'paid')
");
$stmt->execute([$reservation_id, uniqid("TXN"), 10.00]);

require "../includes/header.php";
?>

<div class="alert alert-success">
    ✅ Booking Confirmed! <br>
    Reservation ID: <?= $reservation_id ?>
</div>

<a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>

<?php require "../includes/footer.php"; ?>