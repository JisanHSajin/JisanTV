<?php
// ============================================================
// Forgot Password - Send OTP to Email
// ============================================================
// Users enter their email address to receive a password reset OTP.
// The OTP is sent via email using PHPMailer and expires after
// the configured time (OTP_EXPIRY_MINUTES).
// ============================================================

session_start();
include "db.php";
include "config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

$message = "";
$email = "";

// ============================================================
// 1. PROCESS OTP REQUEST
// ============================================================

if (isset($_POST['send_otp'])) {
    $email = trim($_POST['email']);
    
    // Check if email exists in database
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);
        
        // Generate 6-digit OTP
        $otp = rand(100000, 999999);
        $otp_expires = date("Y-m-d H:i:s", strtotime("+" . OTP_EXPIRY_MINUTES . " minutes"));
        
        // Save OTP to database
        $update_stmt = mysqli_prepare($conn, "UPDATE users SET otp = ?, otp_expires_at = ? WHERE email = ?");
        mysqli_stmt_bind_param($update_stmt, "sss", $otp, $otp_expires, $email);
        mysqli_stmt_execute($update_stmt);
        
        // Send OTP via email
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
            $mail->Subject = 'Password Reset OTP - Expires in ' . OTP_EXPIRY_MINUTES . ' Minutes';
            $mail->Body = "Your OTP to reset password is: $otp\n\nThis OTP will expire in " . OTP_EXPIRY_MINUTES . " minutes.\n\nEnter this code on the verification page to reset your password.\n\nIf you didn't request this, please ignore this email.";
            $mail->send();
            
            // Store email in session for verification
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_otp_sent'] = true;
            
            // Redirect to OTP verification page
            header("Location: reset_verify.php?email=" . urlencode($email));
            exit;
            
        } catch (Exception $e) {
            $message = "Failed to send OTP: " . $mail->ErrorInfo;
        }
    } else {
        $message = "Email not found! Please check and try again.";
    }
}

// Restore email from session if available
if (isset($_SESSION['reset_email']) && empty($email)) {
    $email = $_SESSION['reset_email'];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password - JisanTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;padding:20px;}
        .container{background:#1a1a1a;padding:40px;width:400px;border-radius:12px;border:1px solid #00ffff33;text-align:center;}
        h2{color:#00ffff;margin-bottom:25px;}
        .note{font-size:12px;color:#aaa;margin-bottom:15px;}
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;font-size:14px;}
        .input-box input:focus{border-color:#00ffff;outline:none;}
        .btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:16px;color:black;}
        .btn:hover{background:#00cccc;}
        .message{margin-top:15px;color:yellow;font-size:14px;}
        .links{margin-top:20px;}
        .links a{color:#00ffff;text-decoration:none;display:block;margin-top:8px;}
        .links a:hover{text-decoration:underline;}
        .security-note{background:#001122;padding:10px;border-radius:8px;margin-top:15px;color:#6688aa;font-size:12px;}
        @media(max-width:420px){.container{width:95%;padding:25px;}}
    </style>
</head>
<body>
<div class="container">
    <h2>🔐 Reset Password</h2>
    <div class="note">OTP will expire in <strong><?php echo OTP_EXPIRY_MINUTES; ?> minutes</strong></div>
    
    <form method="POST">
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" value="<?php echo htmlspecialchars($email); ?>" required autofocus>
        </div>
        <button type="submit" name="send_otp" class="btn">Send OTP</button>
        <?php if($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>
    </form>
    
    <div class="links">
        <a href="login.php">← Back to Login</a>
    </div>
    <div class="security-note">🔒 A 6-digit OTP will be sent to your registered email address.</div>
</div>
</body>
</html>