<?php
use PHPMailer\PHPMailer\PHPMailer;

require '../vendor/autoload.php';

function sendOTP($email, $otp) {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'yourgmail@gmail.com'; // your gmail
    $mail->Password = 'your_app_password';   // Gmail App Password
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    $mail->setFrom('yourgmail@gmail.com', 'Smart Parking');
    $mail->addAddress($email);

    $mail->Subject = 'Your OTP Code';
    $mail->Body    = "Your OTP is: $otp";

    return $mail->send();
}
?>