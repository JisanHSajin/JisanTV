<?php
// ============================================================
// User Registration Page
// ============================================================

session_start();
include "db.php";
include "config.php";
include "session_helper.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

$message = "";

if (isset($_POST['register'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $pass = trim($_POST['password']);
    $remember_me = isset($_POST['remember_me']);
    
    if (empty($name) || empty($email) || empty($pass)) {
        $message = "All fields are required!";
    } elseif (strlen($pass) < 6) {
        $message = "Password must be at least 6 characters!";
    } else {
        $check = mysqli_query($conn, "SELECT id FROM users WHERE email='$email'");
        if (mysqli_num_rows($check) > 0) {
            $message = "Email already registered!";
        } else {
            $hashed = password_hash($pass, PASSWORD_DEFAULT);
            $otp = rand(100000, 999999);
            $otp_expires = date("Y-m-d H:i:s", strtotime("+" . OTP_EXPIRY_MINUTES . " minutes"));
            
            $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, otp, otp_expires_at, is_verified) VALUES (?, ?, ?, ?, ?, 0)");
            mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $hashed, $otp, $otp_expires);
            
            if (mysqli_stmt_execute($stmt)) {
                $user_id = mysqli_insert_id($conn);
                
                // Auto-login
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_name'] = $name;
                $_SESSION['password_hash'] = $hashed;
                
                if ($remember_me) {
                    $session_manager = new SessionManager($conn);
                    $session_manager->createPersistentSession($user_id);
                }
                
                // Send OTP
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
                    $mail->Subject = 'Verify Your Email - OTP Expires in ' . OTP_EXPIRY_MINUTES . ' Minutes';
                    $mail->Body = "Hello $name,\n\nYour OTP is: $otp\n\nThis OTP will expire in " . OTP_EXPIRY_MINUTES . " minutes.";
                    $mail->send();
                    header("Location: verify.php?email=" . urlencode($email));
                    exit;
                } catch (Exception $e) {
                    mysqli_query($conn, "DELETE FROM users WHERE id = '$user_id'");
                    $message = "Registration failed: " . $mail->ErrorInfo;
                }
            } else {
                $message = "Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register - JisanTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;}
        .register-container{background:#1a1a1a;padding:40px;width:420px;border-radius:12px;border:1px solid #00ffff33;}
        .register-container h2{text-align:center;margin-bottom:20px;color:#00ffff;}
        
        /* Google Button */
        .google-btn{width:100%;padding:12px;background:#4285f4;color:white;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:15px;transition:0.3s;text-decoration:none;}
        .google-btn:hover{background:#357ae8;transform:scale(1.02);}
        .google-btn svg{width:20px;height:20px;}
        
        .divider{display:flex;align-items:center;margin:20px 0;color:#666;font-size:13px;}
        .divider::before,.divider::after{content:'';flex:1;border-bottom:1px solid #333;}
        .divider::before{margin-right:15px;}
        .divider::after{margin-left:15px;}
        
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;font-size:14px;}
        .input-box input:focus{border-color:#00ffff;outline:none;}
        .register-btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:16px;color:black;}
        .register-btn:hover{background:#00cccc;}
        .message{margin-top:15px;text-align:center;color:yellow;font-size:14px;}
        .links{margin-top:20px;text-align:center;}
        .links a{color:#00ffff;text-decoration:none;display:block;margin-top:8px;}
        .links a:hover{text-decoration:underline;}
        .note{font-size:12px;color:#aaa;margin-top:10px;text-align:center;}
        .remember-me{display:flex;align-items:center;gap:10px;margin-bottom:18px;color:#aaa;font-size:14px;}
        .remember-me input[type="checkbox"]{width:18px;height:18px;cursor:pointer;accent-color:#00ffff;}
        .google-note{font-size:12px;color:#666;text-align:center;margin-top:10px;}
        @media(max-width:420px){.register-container{width:95%;padding:25px;}}
    </style>
</head>
<body>
<div class="register-container">
    <h2>Create Account</h2>
    
    <!-- Google Register Button -->
    <a href="google_login.php" class="google-btn">
        <svg viewBox="0 0 48 48">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        Sign up with Google
    </a>
    
    <div class="divider">or continue with email</div>
    
    <form method="POST">
        <div class="input-box">
            <input type="text" name="name" placeholder="Full Name" required>
        </div>
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required>
        </div>
        <div class="input-box">
            <input type="password" name="password" placeholder="Password (min 6 chars)" required minlength="6">
        </div>
        <div class="remember-me">
            <input type="checkbox" name="remember_me" id="remember_me" checked>
            <label for="remember_me">Remember Me (30 days)</label>
        </div>
        <button type="submit" name="register" class="register-btn">Register</button>
        <div class="message"><?php echo $message; ?></div>
        <div class="links">
            <a href="login.php">Already have an account? Login</a>
        </div>
        <div class="note">OTP will expire in <?php echo OTP_EXPIRY_MINUTES; ?> minutes</div>
        <div class="google-note">🔒 Your Google account is safe. We only access your name and email.</div>
    </form>
</div>
</body>
</html>