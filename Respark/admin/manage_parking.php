<?php
require "../config/db.php";
require "../includes/header.php";

if($_SERVER['REQUEST_METHOD']==='POST'){
    $pdo->prepare("INSERT INTO parking(Parking_number,Hourly_rate,Status) VALUES(?,?,?)")
        ->execute([$_POST['num'],$_POST['rate'],'available']);
}
?>

<h4>Add Parking</h4>

<form method="POST">
<input class="form-control mb-2" name="num" placeholder="Slot Number">
<input class="form-control mb-2" name="rate" placeholder="Rate">
<button class="btn btn-success">Add</button>
</form>

<?php require "../includes/footer.php"; ?>