<?php
require "../config/db.php";
require "../includes/auth_check.php";

$user_id = $_SESSION['user_id'];
$parking_id = $_POST['parking_id'];
$start = $_POST['start_time'];
$end = $_POST['end_time'];

// Check double booking
$stmt = $pdo->prepare("
SELECT * FROM reservations
WHERE ParkingID=? AND Status='confirmed'
AND (Start_time <= ? AND End_time >= ?)
");
$stmt->execute([$parking_id, $end, $start]);

if ($stmt->rowCount() > 0) {
    die("Slot already booked");
}

// Insert reservation
$stmt = $pdo->prepare("
INSERT INTO reservations (ReservationID, UserID, ParkingID, Start_time, End_time, Status)
VALUES (NULL, ?, ?, ?, ?, 'pending')
");
$stmt->execute([$user_id, $parking_id, $start, $end]);

echo "Reservation created";
?>