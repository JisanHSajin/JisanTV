<?php
// ============================================================
// Resend Verification OTP
// ============================================================

session_start();
include "db.php";
include "config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

$email = $_GET['email'] ?? '';
if (empty($email)) {
    header("Location: login.php");
    exit;
}

$result = mysqli_query($conn, "SELECT * FROM users WHERE email='$email'");
if ($row = mysqli_fetch_assoc($result)) {
    if ($row['is_verified'] == 1) {
        $_SESSION['verify_notice'] = "Account already verified!";
        header("Location: login.php");
        exit;
    }
    
    $otp = rand(100000, 999999);
    $otp_expires = date("Y-m-d H:i:s", strtotime("+" . OTP_EXPIRY_MINUTES . " minutes"));
    
    $stmt = mysqli_prepare($conn, "UPDATE users SET otp = ?, otp_expires_at = ? WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "sss", $otp, $otp_expires, $email);
    mysqli_stmt_execute($stmt);
    
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
        $mail->Subject = 'New Verification OTP - Expires in ' . OTP_EXPIRY_MINUTES . ' Minutes';
        $mail->Body = "Your new verification OTP is: $otp\n\nValid for " . OTP_EXPIRY_MINUTES . " minutes.";
        $mail->send();
        $_SESSION['verify_notice'] = "New OTP sent. Valid for " . OTP_EXPIRY_MINUTES . " minutes.";
        header("Location: verify.php?email=" . urlencode($email));
        exit;
    } catch (Exception $e) {
        echo "Failed to send email: " . $mail->ErrorInfo;
    }
} else {
    echo "Email not found!";
}