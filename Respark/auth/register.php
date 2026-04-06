<?php
session_start();
require "../config/db.php";

if($_SERVER['REQUEST_METHOD']==='POST'){
    $name=$_POST['name'];
    $email=$_POST['email'];
    $pass=password_hash($_POST['password'], PASSWORD_BCRYPT);

    $pdo->prepare("INSERT INTO users(Name,Email,Password) VALUES(?,?,?)")
        ->execute([$name,$email,$pass]);

    $_SESSION['success']="Account created!";
}

require "../includes/header.php";
require "../includes/flash.php";
?>

<div class="col-md-4 mx-auto">
<h4>Register</h4>

<form method="POST">
<input class="form-control mb-2" name="name" placeholder="Username" required>
<input class="form-control mb-2" name="email" placeholder="Email" required>
<input class="form-control mb-2" type="password" name="password" placeholder="Password" required>
<button class="btn btn-success w-100">Register</button>
</form>
</div>

<?php require "../includes/footer.php"; ?>