<?php
session_start();

if($_SERVER['REQUEST_METHOD']==='POST'){
    if($_POST['otp']==$_SESSION['otp'] && time()<$_SESSION['otp_expire']){
        $_SESSION['user_id']=$_SESSION['temp_user'];
        header("Location: ../user/dashboard.php");
        exit();
    } else {
        $_SESSION['error']="Invalid OTP";
    }
}

require "../includes/header.php";
require "../includes/flash.php";
?>

<div class="col-md-4 mx-auto">
<h4>Verify OTP</h4>

<form method="POST">
<input class="form-control mb-2" name="otp" placeholder="Enter OTP">
<button class="btn btn-warning w-100">Verify</button>
</form>
</div>

<?php require "../includes/footer.php"; ?>