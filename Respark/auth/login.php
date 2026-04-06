<?php
session_start();
require_once "..\config\db.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $stmt = $pdo->prepare("SELECT UserId, Password FROM users WHERE Email = ?");
    $stmt->execute([$_POST['email']]);
    $result = $stmt->fetch();
    
    if ($result) {
            $_SESSION["loggedin"] = true;
            $_SESSION["id"] = $result['UserId'];
            header("location: verify.php");
    } else echo "Invalid email.";
}

require "../includes/header.php";
require "../includes/flash.php";
?>

<div class="row justify-content-center">
<div class="col-md-4">

<h4>Login</h4>

<form method="POST">
<input class="form-control mb-2" name="email" placeholder="yourname@gmail.com" required>
<div class="input-group mb-2">
    <input class="form-control" type="password" name="password" id="password" placeholder="Password" required>
    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility()">
        <i class="bi bi-eye"></i>
    </button>
</div>
<script>
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const button = event.target.closest('button');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        button.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
        passwordInput.type = 'password';
        button.innerHTML = '<i class="bi bi-eye"></i>';
    }
}
</script>
<button class="btn btn-primary w-100">Login</button>
</form>

<a href="register.php">Register</a>

</div>
</div>

<?php require "../includes/footer.php"; ?>