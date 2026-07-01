<?php
// ============================================================
// Reset Password - Verify OTP
// ============================================================
// Users enter the OTP sent to their email. If valid and not expired,
// they are redirected to set a new password.
// ============================================================

session_start();
include "db.php";
include "config.php";

$email = $_GET['email'] ?? $_SESSION['reset_email'] ?? '';

if (empty($email)) {
    header("Location: forgot_password.php");
    exit;
}

$_SESSION['reset_email'] = $email;

$message = "";
$otp_expired = false;

// ============================================================
// 1. PROCESS OTP VERIFICATION
// ============================================================

if (isset($_POST['verify'])) {
    $otp = trim($_POST['otp']);
    
    // Check if OTP matches and not expired
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ? AND otp = ?");
    mysqli_stmt_bind_param($stmt, "ss", $email, $otp);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($check = mysqli_fetch_assoc($result)) {
        $otp_expires_at = strtotime($check['otp_expires_at']);
        $current_time = time();
        
        if ($otp_expires_at > $current_time) {
            // OTP is valid - clear it and redirect to set new password
            $update_stmt = mysqli_prepare($conn, "UPDATE users SET otp = NULL, otp_expires_at = NULL WHERE email = ?");
            mysqli_stmt_bind_param($update_stmt, "s", $email);
            mysqli_stmt_execute($update_stmt);
            
            $_SESSION['reset_email'] = $email;
            header("Location: new_password.php");
            exit;
        } else {
            $otp_expired = true;
            $message = "OTP has expired! Please request a new OTP.";
        }
    } else {
        $message = "Invalid OTP! Please try again.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify OTP - JisanTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;padding:20px;}
        .container{background:#1a1a1a;padding:40px;width:400px;border-radius:12px;border:1px solid #00ffff33;text-align:center;}
        h2{color:#00ffff;margin-bottom:25px;}
        .info-text{background:#111;padding:10px;border-radius:8px;margin-bottom:20px;color:#aaa;font-size:14px;word-break:break-all;}
        .expiry-warning{background:#331100;padding:8px;border-radius:6px;margin-bottom:15px;color:#ffaa00;font-size:12px;}
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;text-align:center;font-size:20px;letter-spacing:8px;}
        .input-box input:focus{border-color:#00ffff;outline:none;}
        .btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:16px;color:black;}
        .btn:hover{background:#00cccc;}
        .message{margin-top:15px;color:yellow;font-size:14px;}
        .error-message{color:#ff6666;}
        .success-message{color:lime;}
        .links{margin-top:20px;}
        .links a{color:#00ffff;text-decoration:none;display:inline-block;margin:0 10px;}
        .links a:hover{text-decoration:underline;}
        @media(max-width:420px){.container{width:95%;padding:25px;}
        .input-box input{letter-spacing:5px;font-size:18px;}}
    </style>
</head>
<body>
<div class="container">
    <h2>🔑 Verify OTP</h2>
    <div class="info-text">
        OTP sent to: <strong><?php echo htmlspecialchars($email); ?></strong>
    </div>
    <div class="expiry-warning">
        ⏰ This OTP will expire in <strong><?php echo OTP_EXPIRY_MINUTES; ?> minutes</strong>
    </div>
    
    <form method="POST">
        <div class="input-box">
            <input type="text" name="otp" placeholder="Enter 6-digit OTP" maxlength="6" required autofocus>
        </div>
        <button type="submit" name="verify" class="btn">Verify & Continue</button>
        <?php if($message): ?>
            <div class="message <?php echo $otp_expired ? 'error-message' : ''; ?>"><?php echo $message; ?></div>
        <?php endif; ?>
    </form>
    
    <div class="links">
        <a href="resend_reset_otp.php?email=<?php echo urlencode($email); ?>">Resend OTP</a>
        <a href="forgot_password.php">Try Different Email</a>
    </div>
</div>
</body>
</html>