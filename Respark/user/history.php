<?php
require "../config/db.php";
require "../includes/header.php";

$data=$pdo->query("SELECT * FROM reservations")->fetchAll();
?>

<h4>Booking History</h4>

<table class="table table-bordered">
<tr><th>ID</th><th>Status</th></tr>

<?php foreach($data as $d): ?>
<tr>
<td><?= $d['ReservationID'] ?></td>
<td><?= $d['Status'] ?></td>
</tr>
<?php endforeach; ?>

</table>

<?php require "../includes/footer.php"; ?>