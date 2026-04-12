<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

function getMailerInstance() {
    $mail = new PHPMailer(true);
 
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';               
    $mail->SMTPAuth   = true;
    $mail->Username   = 'yourgmail@gmail.com';          
    $mail->Password   = 'your_app_password';            
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
    $mail->Port       = 587;                           

 
    $mail->setFrom('yourgmail@gmail.com', 'Smart Parking Campus');
    
    return $mail;
}


function sendOTP($email, $otp) {
    try {
        $mail = getMailerInstance();
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Your Security Verification Code';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #eee;'>
                <h2 style='color: #333;'>Verification Code</h2>
                <p>Your One-Time Password (OTP) is:</p>
                <h1 style='color: #4CAF50; letter-spacing: 5px;'>$otp</h1>
                <p>This code will expire in 10 minutes. Please do not share this code with anyone.</p>
            </div>";
        
        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}


function sendBookingNotification($email, $userName, $slotNumber, $bookingTime) {
    try {
        $mail = getMailerInstance();
        $mail->addAddress($email, $userName);
        $mail->isHTML(true);
        $mail->Subject = "Booking Confirmed: Slot $slotNumber";
        
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #eee; border-left: 5px solid #4CAF50;'>
                <h2 style='color: #2e7d32;'>Parking Reservation Confirmed!</h2>
                <p>Hello <strong>$userName</strong>,</p>
                <p>Your parking request was successful. Here are your details:</p>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Slot Number:</strong></td><td>$slotNumber</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Time:</strong></td><td>$bookingTime</td></tr>
                </table>
                <p style='margin-top: 20px;'>Please show this email to the security personnel if requested.</p>
            </div>";

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}


function sendCancellationNotification($email, $userName, $slotNumber) {
    try {
        $mail = getMailerInstance();
        $mail->addAddress($email, $userName);
        $mail->isHTML(true);
        $mail->Subject = "Booking Cancelled: Slot $slotNumber";
        
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #eee; border-left: 5px solid #f44336;'>
                <h2 style='color: #d32f2f;'>Reservation Cancelled</h2>
                <p>Hello <strong>$userName</strong>,</p>
                <p>This email confirms that your booking for <strong>Slot $slotNumber</strong> has been cancelled.</p>
                <p>The slot is now available for other users. If you did not perform this action, please contact the admin.</p>
            </div>";

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}