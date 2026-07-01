<?php
// ============================================================
// Resend Password Reset OTP
// ============================================================

session_start();
include "db.php";
include "config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

$email = $_GET['email'] ?? $_SESSION['reset_email'] ?? '';

if (empty($email)) {
    header("Location: forgot_password.php");
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ?");
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) == 1) {
    $otp = rand(100000, 999999);
    $otp_expires = date("Y-m-d H:i:s", strtotime("+" . OTP_EXPIRY_MINUTES . " minutes"));
    
    $update_stmt = mysqli_prepare($conn, "UPDATE users SET otp = ?, otp_expires_at = ? WHERE email = ?");
    mysqli_stmt_bind_param($update_stmt, "sss", $otp, $otp_expires, $email);
    mysqli_stmt_execute($update_stmt);
    
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email);
        $mail->Subject = 'New Password Reset OTP - Expires in ' . OTP_EXPIRY_MINUTES . ' Minutes';
        $mail->Body = "Your new OTP for password reset is: $otp\n\nThis OTP will expire in " . OTP_EXPIRY_MINUTES . " minutes.\n\nEnter this code to reset your password.";
        $mail->send();
        
        $_SESSION['reset_otp_sent'] = true;
        $_SESSION['reset_email'] = $email;
        $_SESSION['verify_notice'] = "New OTP sent! Valid for " . OTP_EXPIRY_MINUTES . " minutes.";
        header("Location: reset_verify.php?email=" . urlencode($email));
        exit;
    } catch (Exception $e) {
        echo "Failed to send OTP: " . $mail->ErrorInfo;
    }
} else {
    header("Location: forgot_password.php");
    exit;
}