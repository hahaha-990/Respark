<?php
require "../config/db.php";
require "../includes/header.php";

$slots=$pdo->query("SELECT * FROM parking")->fetchAll();
?>

<h4>Book Slot</h4>

<form method="POST" action="pay.php">
<select name="parking_id" class="form-control mb-2">
<?php foreach($slots as $s): ?>
<option value="<?= $s['ParkingID'] ?>">
<?= $s['Parking_number'] ?> (RM<?= $s['Hourly_rate'] ?>)
</option>
<?php endforeach; ?>
</select>

<input class="form-control mb-2" type="datetime-local" name="start">
<input class="form-control mb-2" type="datetime-local" name="end">

<button class="btn btn-success">Book</button>
</form>

<?php require "../includes/footer.php"; ?>