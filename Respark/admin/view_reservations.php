<?php
require "../config/db.php";
require "../includes/header.php";

$data=$pdo->query("SELECT * FROM reservations")->fetchAll();
?>

<h4>All Reservations</h4>

<table class="table">
<tr><th>ID</th><th>User</th><th>Status</th><th>Start Time</th><th>End Time</th></tr>

<?php foreach($data as $d): ?>
<tr>
<td><?= $d['ReservationID'] ?></td>
<td><?= $d['UserID'] ?></td>
<td><?= $d['Status'] ?></td>
<td><?= $d['Start_time'] ?></td>
<td><?= $d['End_time'] ?></td>
</tr>
<?php endforeach; ?>

</table>

<?php require "../includes/footer.php"; ?>