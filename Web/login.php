<?php
// ============================================================
// User Login Page
// ============================================================

session_start();
include "db.php";
include "device_helper.php";
include "config.php";
include "session_helper.php";

$message = "";
$device_manager = new DeviceManager($conn);

// Check for redirect messages
if (isset($_GET['msg'])) {
    $messages = [
        'password_changed' => 'Your password was changed. Please login again.',
        'device_removed' => 'Your device was removed. Please login again.',
        'self_device_removed' => 'You removed your own device. Please login again.',
        'all_devices_removed' => 'All devices removed. Please login again.',
        'session_expired' => 'Your session expired. Please login again.'
    ];
    $message = $messages[$_GET['msg']] ?? '';
}

if (isset($_GET['reset']) && $_GET['reset'] == 'success') {
    $message = "Password updated successfully! Please login again.";
}

// Check for Google OAuth errors
if (isset($_GET['error'])) {
    $message = htmlspecialchars($_GET['error']);
}

if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $pass = trim($_POST['password']);
    $remember_me = isset($_POST['remember_me']);
    
    if (empty($email) || empty($pass)) {
        $message = "All fields are required!";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            if (password_verify($pass, $row['password'])) {
                // Check if email is verified
                if ($row['is_verified'] == 0) {
                    $_SESSION['verify_notice'] = "Please verify your email first.";
                    header("Location: verify.php?email=" . urlencode($email));
                    exit;
                }
                
                // Check device access
                $device_check = $device_manager->checkDeviceAccess($row['id']);
                if (!$device_check['allowed']) {
                    $message = $device_check['message'];
                } else {
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['user_name'] = $row['name'];
                    $_SESSION['password_hash'] = $row['password'];
                    
                    if ($remember_me) {
                        $session_manager = new SessionManager($conn);
                        $session_manager->createPersistentSession($row['id']);
                    }
                    
                    header("Location: home.php");
                    exit;
                }
            } else {
                $message = "Wrong password!";
            }
        } else {
            $message = "Email not found!";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - JisanTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;}
        .login-container{background:#1a1a1a;padding:40px;width:420px;border-radius:12px;border:1px solid #00ffff33;}
        .login-container h2{text-align:center;margin-bottom:20px;color:#00ffff;}
        
        /* ============================================================
        LOGIN FORM
        ============================================================ */
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;font-size:14px;}
        .input-box input:focus{border-color:#00ffff;outline:none;}
        .login-btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:16px;color:black;}
        .login-btn:hover{background:#00cccc;}
        .remember-me{display:flex;align-items:center;gap:10px;margin-bottom:18px;color:#aaa;font-size:14px;}
        .remember-me input[type="checkbox"]{width:18px;height:18px;cursor:pointer;accent-color:#00ffff;}
        
        /* ============================================================
        MESSAGES
        ============================================================ */
        .message{margin-top:15px;text-align:center;color:yellow;font-size:14px;}
        .success-message{color:lime;}
        .error-message{color:#ff6666;}
        
        /* ============================================================
        DIVIDER
        ============================================================ */
        .divider{display:flex;align-items:center;margin:20px 0;color:#666;font-size:13px;}
        .divider::before,.divider::after{content:'';flex:1;border-bottom:1px solid #333;}
        .divider::before{margin-right:15px;}
        .divider::after{margin-left:15px;}
        
        /* ============================================================
        GOOGLE BUTTON - No Underline
        ============================================================ */
        .google-btn{width:100%;padding:12px;background:#4285f4;color:white;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;gap:10px;transition:0.3s;text-decoration:none !important;}
        .google-btn:hover{background:#357ae8;transform:scale(1.02);color:white;text-decoration:none !important;}
        .google-btn svg{width:20px;height:20px;}
        
        /* ============================================================
        LINKS
        ============================================================ */
        .links{margin-top:15px;text-align:center;}
        .links a{display:block;color:#00ffff;text-decoration:none;margin-top:8px;}
        .links a:hover{text-decoration:underline;}
        .links .home-link{color:#00ff88;font-weight:bold;}
        .links .home-link:hover{color:#00ffaa;}
        
        /* ============================================================
        NOTE
        ============================================================ */
        .google-note{font-size:12px;color:#666;text-align:center;margin-top:10px;}
        
        /* ============================================================
        RESPONSIVE
        ============================================================ */
        @media(max-width:420px){.login-container{width:95%;padding:25px;}}
    </style>
</head>
<body>
<div class="login-container">
    <h2>Member Login</h2>
    
    <!-- ============================================================
    LOGIN FORM
    ============================================================ -->
    <form method="POST">
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required autofocus>
        </div>
        <div class="input-box">
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <div class="remember-me">
            <input type="checkbox" name="remember_me" id="remember_me">
            <label for="remember_me">Remember Me (30 days)</label>
        </div>
        <button type="submit" name="login" class="login-btn">Login</button>
        
        <?php if($message): ?>
            <div class="message <?php echo strpos($message, 'successfully') !== false ? 'success-message' : 'error-message'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
    </form>
    
    <!-- ============================================================
    DIVIDER
    ============================================================ -->
    <div class="divider">or continue with</div>
    
    <!-- ============================================================
    GOOGLE LOGIN BUTTON (No Underline)
    ============================================================ -->
    <a href="google_login.php" class="google-btn">
        <svg viewBox="0 0 48 48">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        Sign in with Google
    </a>
    
    <!-- ============================================================
    LINKS
    ============================================================ -->
    <div class="links">
        <a href="register.php">Create Account</a>
        <a href="forgot_password.php">Forgot Password?</a>
        <a href="home.php" class="home-link">🏠 Go to Home</a>
    </div>
    
    <div class="google-note">🔒 Your Google account is safe. We only access your name and email.</div>
</div>
</body>
</html>