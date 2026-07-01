<?php
// ============================================================
// Email Verification Page
// ============================================================
// Users enter the OTP sent to their email after registration.
// If valid and not expired, the account is verified and activated.
// ============================================================

session_start();
include "db.php";
include "config.php";

$email = $_GET['email'] ?? '';

if (empty($email)) {
    header("Location: login.php");
    exit;
}

$message = "";
$success = false;
$otp_expired = false;

// ============================================================
// 1. PROCESS OTP VERIFICATION
// ============================================================

if (isset($_POST['verify'])) {
    $entered_otp = $_POST['otp'];
    
    // Check if OTP matches and account is not verified yet
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ? AND otp = ? AND is_verified = 0");
    mysqli_stmt_bind_param($stmt, "ss", $email, $entered_otp);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($check = mysqli_fetch_assoc($result)) {
        $otp_expires_at = strtotime($check['otp_expires_at']);
        $current_time = time();
        
        if ($otp_expires_at > $current_time) {
            // OTP is valid - verify the account
            $update_stmt = mysqli_prepare($conn, "UPDATE users SET is_verified = 1, otp = NULL, otp_expires_at = NULL WHERE email = ?");
            mysqli_stmt_bind_param($update_stmt, "s", $email);
            mysqli_stmt_execute($update_stmt);
            $message = "Account Verified Successfully!";
            $success = true;
        } else {
            $otp_expired = true;
            $message = "OTP has expired! Please request a new OTP.";
        }
    } else {
        $message = "Wrong OTP! Please try again.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify Email - JisanTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;padding:20px;}
        .verify-container{background:#1a1a1a;padding:40px;width:400px;border-radius:12px;border:1px solid #00ffff33;text-align:center;}
        .verify-container h2{margin-bottom:25px;color:#00ffff;}
        .info-text{background:#111;padding:10px;border-radius:8px;margin-bottom:20px;color:#aaa;font-size:14px;word-break:break-all;}
        .expiry-warning{background:#331100;padding:8px;border-radius:6px;margin-bottom:15px;color:#ffaa00;font-size:12px;}
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;text-align:center;letter-spacing:8px;font-size:20px;}
        .input-box input:focus{border-color:#00ffff;outline:none;}
        .verify-btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:16px;color:black;}
        .verify-btn:hover{background:#00cccc;}
        .message{margin-top:15px;color:yellow;font-size:14px;}
        .success{color:lime;font-size:16px;padding:10px;}
        .error{color:#ff6666;}
        .links{margin-top:20px;}
        .links a{color:#00ffff;text-decoration:none;display:inline-block;margin:0 10px;}
        .links a:hover{text-decoration:underline;}
        .resend-note{font-size:12px;color:#666;margin-top:15px;}
        @media(max-width:420px){.verify-container{width:95%;padding:25px;}
        .input-box input{letter-spacing:5px;font-size:18px;}}
    </style>
</head>
<body>
<div class="verify-container">
    <h2>📧 Email Verification</h2>
    
    <div class="info-text">
        Verifying: <strong><?php echo htmlspecialchars($email); ?></strong>
    </div>
    
    <div class="expiry-warning">
        ⏰ OTP expires in <strong><?php echo OTP_EXPIRY_MINUTES; ?> minutes</strong>
    </div>
    
    <?php if(isset($_SESSION['verify_notice'])): ?>
        <div class="message"><?php echo $_SESSION['verify_notice']; unset($_SESSION['verify_notice']); ?></div>
    <?php endif; ?>
    
    <?php if(!$success): ?>
    <form method="POST">
        <div class="input-box">
            <input type="text" name="otp" placeholder="Enter 6-digit OTP" maxlength="6" required autofocus>
        </div>
        <button name="verify" class="verify-btn">Verify Account</button>
        <div class="message <?php echo $otp_expired ? 'error' : ''; ?>"><?php echo $message; ?></div>
    </form>
    
    <div class="links">
        <a href="resend_otp.php?email=<?php echo urlencode($email); ?>">Resend OTP</a>
        <a href="login.php">Back to Login</a>
    </div>
    
    <?php else: ?>
        <div class="success">✅ <?php echo $message; ?></div>
        <div style="margin:20px 0; color:#aaa;">Your account is now active! You can login to access all features.</div>
        <div class="links">
            <a href="login.php" style="font-size:18px; color:lime;">Login Now →</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>