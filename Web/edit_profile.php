<?php
// ============================================================
// Edit Profile - Update Name, Email & Password
// ============================================================
// Features:
// - Profile Information section with pen icons
// - Click pen to edit: Name (verify by password)
// - Click pen to edit: Email (verify by OTP) - LOCKED for Google users
// - Click pen to edit: Password (with forget password link)
// - Google users: Email is locked with a lock icon
// ============================================================

session_start();
include "db.php";
include "session_helper.php";
include "device_helper.php";
include "config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

// ============================================================
// 1. SESSION VALIDATION
// ============================================================

// Auto-restore session from cookie
$session_manager = new SessionManager($conn);
if (!$session_manager->isLoggedIn()) {
    header("Location: login.php");
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// ============================================================
// 2. PASSWORD VALIDATION
// ============================================================

$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    if (!isset($_SESSION['password_hash']) || $_SESSION['password_hash'] !== $user['password']) {
        session_destroy();
        header("Location: login.php?msg=password_changed");
        exit;
    }
} else {
    session_destroy();
    header("Location: login.php");
    exit;
}

// ============================================================
// 3. DEVICE VALIDATION
// ============================================================

$device_manager = new DeviceManager($conn);
$device_id = $device_manager->getDeviceFingerprint();

$device_check = $conn->prepare("SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1");
$device_check->bind_param("is", $user_id, $device_id);
$device_check->execute();
$device_result = $device_check->get_result();

if ($device_result->num_rows == 0) {
    session_destroy();
    header("Location: login.php?msg=device_removed");
    exit;
}

$message = "";
$error = "";
$edit_mode = ""; // 'name', 'email', 'password'

// Get user info
$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'"));
$is_google_user = !empty($user['google_id']);
$has_password = !empty($user['password']);

// ============================================================
// 4. NAME EDIT - Verify by Password
// ============================================================

if (isset($_POST['edit_name'])) {
    $new_name = trim($_POST['new_name']);
    $password = trim($_POST['password_verify']);
    
    if (empty($new_name)) {
        $error = "Name cannot be empty!";
    } elseif (empty($password)) {
        $error = "Please enter your password to verify.";
    } else {
        // Verify password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        
        if (password_verify($password, $user_data['password'])) {
            $update_stmt = $conn->prepare("UPDATE users SET name = ? WHERE id = ?");
            $update_stmt->bind_param("si", $new_name, $user_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['user_name'] = $new_name;
                $message = "Name updated successfully!";
                $user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'"));
                $edit_mode = "";
            } else {
                $error = "Failed to update name.";
            }
            $update_stmt->close();
        } else {
            $error = "Incorrect password!";
        }
    }
    if (!empty($error)) {
        $edit_mode = "name";
    }
}

// ============================================================
// 5. EMAIL EDIT - Verify by OTP (LOCKED FOR GOOGLE USERS)
// ============================================================

if (isset($_POST['send_email_otp']) && !$is_google_user) {
    $new_email = trim($_POST['new_email']);
    
    if (empty($new_email)) {
        $error = "Email address is required!";
        $edit_mode = "email";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address!";
        $edit_mode = "email";
    } elseif ($new_email === $user['email']) {
        $error = "New email is same as current email.";
        $edit_mode = "email";
    } else {
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_stmt->bind_param("si", $new_email, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = "Email is already used by another account!";
            $edit_mode = "email";
        } else {
            $otp = rand(100000, 999999);
            $otp_expires = date("Y-m-d H:i:s", strtotime("+" . OTP_EXPIRY_MINUTES . " minutes"));
            
            $_SESSION['pending_email'] = $new_email;
            $_SESSION['email_otp'] = $otp;
            $_SESSION['email_otp_expires'] = $otp_expires;
            
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
                $mail->addAddress($new_email);
                $mail->Subject = 'Email Change Verification - JisanTV';
                $mail->Body = "Your OTP to change your email is: $otp\n\nThis OTP will expire in " . OTP_EXPIRY_MINUTES . " minutes.\n\nEnter this code to verify your new email address.";
                $mail->send();
                $message = "OTP sent to your new email.";
                $edit_mode = "email_otp";
            } catch (Exception $e) {
                unset($_SESSION['pending_email']);
                unset($_SESSION['email_otp']);
                unset($_SESSION['email_otp_expires']);
                $error = "Failed to send OTP: " . $mail->ErrorInfo;
                $edit_mode = "email";
            }
        }
        $check_stmt->close();
    }
}

// ============================================================
// 6. VERIFY EMAIL OTP
// ============================================================

if (isset($_POST['verify_email_otp'])) {
    $entered_otp = trim($_POST['otp']);
    $pending_email = $_SESSION['pending_email'] ?? '';
    
    if (empty($pending_email)) {
        $error = "No pending email change request.";
        $edit_mode = "";
    } elseif (empty($entered_otp)) {
        $error = "Please enter the OTP.";
        $edit_mode = "email_otp";
    } else {
        $saved_otp = $_SESSION['email_otp'] ?? '';
        $otp_expires = $_SESSION['email_otp_expires'] ?? '';
        
        if (empty($saved_otp)) {
            $error = "OTP expired. Please request a new OTP.";
            $edit_mode = "email";
            unset($_SESSION['pending_email']);
            unset($_SESSION['email_otp']);
            unset($_SESSION['email_otp_expires']);
        } elseif ($entered_otp == $saved_otp && strtotime($otp_expires) > time()) {
            $update_stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
            $update_stmt->bind_param("si", $pending_email, $user_id);
            
            if ($update_stmt->execute()) {
                unset($_SESSION['pending_email']);
                unset($_SESSION['email_otp']);
                unset($_SESSION['email_otp_expires']);
                $message = "Email updated successfully!";
                $edit_mode = "";
                $user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'"));
            } else {
                $error = "Failed to update email.";
                $edit_mode = "email_otp";
            }
            $update_stmt->close();
        } elseif ($entered_otp == $saved_otp) {
            $error = "OTP has expired! Please request a new OTP.";
            $edit_mode = "email_otp";
        } else {
            $error = "Invalid OTP! Please try again.";
            $edit_mode = "email_otp";
        }
    }
}

// ============================================================
// 7. RESEND EMAIL OTP
// ============================================================

if (isset($_GET['resend_email_otp'])) {
    $pending_email = $_SESSION['pending_email'] ?? '';
    
    if (!empty($pending_email)) {
        $otp = rand(100000, 999999);
        $otp_expires = date("Y-m-d H:i:s", strtotime("+" . OTP_EXPIRY_MINUTES . " minutes"));
        $_SESSION['email_otp'] = $otp;
        $_SESSION['email_otp_expires'] = $otp_expires;
        $edit_mode = "email_otp";
        
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
            $mail->addAddress($pending_email);
            $mail->Subject = 'New Email Change OTP - JisanTV';
            $mail->Body = "Your new OTP to change your email is: $otp\n\nThis OTP will expire in " . OTP_EXPIRY_MINUTES . " minutes.";
            $mail->send();
            $message = "New OTP sent.";
        } catch (Exception $e) {
            $error = "Failed to send OTP: " . $mail->ErrorInfo;
            $edit_mode = "email";
        }
    }
}

// ============================================================
// 8. PASSWORD EDIT - With Current Password & Forget Password Link
// ============================================================

if (isset($_POST['edit_password'])) {
    $current_password = trim($_POST['current_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    if (empty($current_password) && $has_password) {
        $error = "Please enter your current password.";
        $edit_mode = "password";
    } elseif (empty($new_password) || empty($confirm_password)) {
        $error = "Please enter and confirm your new password.";
        $edit_mode = "password";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters!";
        $edit_mode = "password";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match!";
        $edit_mode = "password";
    } else {
        // Verify current password if user has one
        $password_valid = false;
        if (!$has_password) {
            // Google user setting password for first time
            $password_valid = true;
        } else {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            
            if (password_verify($current_password, $user_data['password'])) {
                $password_valid = true;
            } else {
                $error = "Current password is incorrect!";
                $edit_mode = "password";
            }
        }
        
        if ($password_valid) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $user_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['password_hash'] = $hashed_password;
                $has_password = true;
                $message = "Password updated successfully!";
                $edit_mode = "";
                $user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'"));
            } else {
                $error = "Failed to update password.";
                $edit_mode = "password";
            }
            $update_stmt->close();
        }
    }
}

// ============================================================
// 9. CANCEL EDIT
// ============================================================

if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_email']);
    unset($_SESSION['email_otp']);
    unset($_SESSION['email_otp_expires']);
    $edit_mode = "";
    header("Location: edit_profile.php");
    exit;
}

// If there's a pending email OTP, show OTP mode
if (isset($_SESSION['pending_email']) && !empty($_SESSION['pending_email']) && empty($edit_mode)) {
    $edit_mode = "email_otp";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Profile - JisanTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    
    <!-- ============================================================
    STYLES - Consistent with home.php & profile.php
    ============================================================ -->
    <style>
        /* Reset & Base */
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;}
        
        /* Container */
        .edit-container{background:#1a1a1a;padding:40px;width:600px;border-radius:12px;border:1px solid #00ffff33;}
        .edit-container h2{text-align:center;margin-bottom:25px;color:#00ffff;font-size:24px;}
        .edit-container h2 span{color:#ff8800;}
        
        /* Profile Section */
        .profile-section{background:#111;padding:20px;border-radius:10px;border:1px solid #00ffff22;margin-bottom:20px;}
        .profile-section .section-title{color:#00ffff;font-size:15px;margin-bottom:15px;border-bottom:1px solid #222;padding-bottom:10px;}
        
        .info-item{
            display:flex;
            align-items:center;
            padding:12px 8px;
            border-bottom:1px solid #1a1a1a;
            gap:8px;
            flex-wrap:wrap;
        }
        .info-item:last-child{border-bottom:none;}
        .info-label{
            color:#666;
            width:110px;
            flex-shrink:0;
            font-size:13px;
        }
        .info-value{
            color:#e0e0e0;
            flex:1;
            font-size:14px;
            min-width:80px;
            word-break:break-all;
            overflow-wrap:break-word;
        }
        
        .info-edit-btn{
            background:transparent;
            border:1px solid #00ffff33;
            color:#00ffff;
            cursor:pointer;
            padding:5px 12px;
            border-radius:6px;
            font-size:12px;
            transition:0.3s;
            white-space:nowrap;
            flex-shrink:0;
        }
        .info-edit-btn:hover{background:#00ffff22;border-color:#00ffff;}
        .info-edit-btn.active{background:#ff880022;border-color:#ff8800;color:#ff8800;}
        .info-edit-btn.locked{opacity:0.5;cursor:not-allowed;border-color:#444;color:#666;}
        .info-edit-btn.locked:hover{background:transparent;border-color:#444;}
        
        .lock-icon{color:#ff8800;font-size:12px;margin-left:5px;}
        .locked-text{color:#ff6600;font-size:11px;margin-left:5px;}
        
        /* Badges */
        .badge{display:inline-block;padding:2px 12px;border-radius:12px;font-size:10px;font-weight:bold;}
        .badge-google{background:#4285f4;color:white;}
        .badge-password{background:#00ff88;color:black;}
        .badge-no-password{background:#ff6600;color:white;}
        
        /* Edit Forms */
        .edit-form{background:#0a0a0a;padding:15px;border-radius:10px;margin-top:10px;border-left:3px solid #00ffff;display:none;}
        .edit-form.active{display:block;}
        .edit-form .input-box{margin-bottom:12px;}
        .edit-form .input-box label{display:block;color:#888;font-size:12px;margin-bottom:4px;}
        .edit-form .input-box input{width:100%;padding:10px 14px;border-radius:6px;border:1px solid #333;background:#111;color:white;font-size:14px;}
        .edit-form .input-box input:focus{border-color:#00ffff;outline:none;}
        .edit-form .input-box .field-note{display:block;color:#555;font-size:11px;margin-top:3px;}
        
        .btn{display:inline-block;padding:10px 20px;border-radius:6px;font-weight:bold;text-decoration:none;border:none;cursor:pointer;font-size:14px;transition:0.3s;}
        .btn-primary{background:#00ffff;color:black;}
        .btn-primary:hover{background:#00dddd;}
        .btn-success{background:#00ff88;color:black;}
        .btn-success:hover{background:#00dd77;}
        .btn-danger{background:#cc4444;color:white;}
        .btn-danger:hover{background:#dd5555;}
        .btn-secondary{background:#444;color:white;}
        .btn-secondary:hover{background:#555;}
        .btn-warning{background:#ff8800;color:white;}
        .btn-warning:hover{background:#ff7700;}
        .btn-block{width:100%;text-align:center;display:block;}
        
        .action-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:5px;}
        .action-row .btn{flex:1;min-width:80px;text-align:center;}
        
        .otp-box{background:#0a1a0a;padding:15px;border-radius:10px;border:1px solid #00ff8844;margin-top:10px;}
        .otp-box h4{color:#00ff88;margin-bottom:8px;font-size:14px;}
        .otp-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;}
        .otp-actions .btn{flex:1;min-width:80px;text-align:center;}
        
        .message{padding:12px;border-radius:8px;text-align:center;margin-bottom:15px;}
        .message-success{background:green;color:white;}
        .message-error{background:#660000;color:white;}
        
        .forgot-link{color:#00ffff;text-decoration:none;font-size:13px;display:inline-block;margin-top:5px;}
        .forgot-link:hover{text-decoration:underline;}
        
        .back-btn{display:block;padding:12px;border-radius:8px;background:#222;color:#888;text-decoration:none;text-align:center;font-size:14px;margin-top:10px;transition:0.3s;}
        .back-btn:hover{background:#333;color:white;}
        
        .google-lock-box{text-align:center;padding:15px;color:#666;background:#1a1a1a;border-radius:8px;border:1px solid #ff880033;}
        .google-lock-box .icon{font-size:30px;display:block;margin-bottom:5px;}
        .google-lock-box p{color:#ff8800;font-weight:500;}
        .google-lock-box small{color:#555;font-size:12px;}
        
        /* ============================================================
        RESPONSIVE FIXES - Mobile text overflow
        ============================================================ */
        @media(max-width:550px){
            .edit-container{width:95%;padding:20px;}
            .edit-container h2{font-size:20px;}
            
            .profile-section{padding:12px;}
            
            .info-item{
                padding:10px 4px;
                gap:6px;
            }
            .info-label{
                width:auto;
                min-width:60px;
                font-size:12px;
                flex-shrink:0;
            }
            .info-value{
                font-size:13px;
                min-width:50px;
                word-break:break-word;
                overflow-wrap:break-word;
            }
            .info-edit-btn{
                font-size:11px;
                padding:4px 10px;
                margin-left:auto;
                flex-shrink:0;
            }
            
            /* Make sure the container doesn't overflow */
            .edit-container{
                overflow:hidden;
            }
            
            .section-title{
                font-size:13px;
            }
            
            .action-row{flex-direction:column;}
            .action-row .btn{width:100%;}
            .otp-actions{flex-direction:column;}
            .otp-actions .btn{width:100%;}
            
            .badge{font-size:9px;padding:1px 8px;}
        }
        
        @media(max-width:400px){
            .edit-container{padding:15px;}
            .info-item{
                flex-wrap:wrap;
                padding:8px 2px;
            }
            .info-label{
                width:100%;
                font-size:11px;
                color:#555;
            }
            .info-value{
                font-size:12px;
                width:calc(100% - 70px);
                flex:1;
            }
            .info-edit-btn{
                font-size:10px;
                padding:3px 8px;
                margin-left:0;
            }
            .edit-container h2{font-size:18px;}
            .profile-section{padding:10px;}
            .edit-form{padding:12px;}
        }
    </style>
</head>
<body>
<div class="edit-container">
    <h2>✏️ Edit <span>Profile</span></h2>
    
    <!-- ============================================================
    MESSAGES
    ============================================================ -->
    <?php if(!empty($message)): ?>
        <div class="message message-success">✅ <?php echo $message; ?></div>
    <?php endif; ?>
    <?php if(!empty($error)): ?>
        <div class="message message-error">❌ <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- ============================================================
    PROFILE INFORMATION SECTION
    ============================================================ -->
    <div class="profile-section">
        <div class="section-title">
            📋 Profile Information
            <?php if($is_google_user): ?>
                <span class="badge badge-google">Google</span>
            <?php endif; ?>
            <?php if($has_password): ?>
                <span class="badge badge-password">Has Password</span>
            <?php else: ?>
                <span class="badge badge-no-password">No Password</span>
            <?php endif; ?>
        </div>
        
        <!-- ============================================================
        NAME
        ============================================================ -->
        <div class="info-item">
            <span class="info-label">Name</span>
            <span class="info-value"><?php echo htmlspecialchars($user['name'] ?? 'Not set'); ?></span>
            <button class="info-edit-btn <?php echo $edit_mode == 'name' ? 'active' : ''; ?>" onclick="toggleEdit('name')">✏️ Edit</button>
        </div>
        <div class="edit-form <?php echo $edit_mode == 'name' ? 'active' : ''; ?>" id="nameForm">
            <form method="POST">
                <div class="input-box">
                    <label>New Name</label>
                    <input type="text" name="new_name" placeholder="Enter new name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                </div>
                <div class="input-box">
                    <label>Verify with Password</label>
                    <input type="password" name="password_verify" placeholder="Enter your current password" required>
                    <span class="field-note">🔒 Enter your password to verify identity</span>
                </div>
                <div class="action-row">
                    <button type="submit" name="edit_name" class="btn btn-primary">💾 Update Name</button>
                    <a href="?cancel=1" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
        
        <!-- ============================================================
        EMAIL - LOCKED FOR GOOGLE USERS
        ============================================================ -->
        <div class="info-item">
            <span class="info-label">Email</span>
            <span class="info-value">
                <?php echo htmlspecialchars($user['email']); ?>
                <?php if($is_google_user): ?>
                    <span class="lock-icon">🔒</span>
                    <span class="locked-text">(Locked)</span>
                <?php endif; ?>
            </span>
            <button class="info-edit-btn <?php echo $is_google_user ? 'locked' : ''; ?> <?php echo ($edit_mode == 'email' || $edit_mode == 'email_otp') ? 'active' : ''; ?>" 
                    onclick="<?php echo $is_google_user ? 'alert(\'Email is locked for Google accounts.\')' : "toggleEdit('email')"; ?>">
                <?php if($is_google_user): ?>
                    🔒 Locked
                <?php else: ?>
                    ✏️ Edit
                <?php endif; ?>
            </button>
        </div>
        <div class="edit-form <?php echo ($edit_mode == 'email' || $edit_mode == 'email_otp') ? 'active' : ''; ?>" id="emailForm">
            <?php if($is_google_user): ?>
                <div class="google-lock-box">
                    <span class="icon">🔒</span>
                    <p>Email is locked for Google accounts</p>
                    <small>You can change your email from your Google account settings.</small>
                </div>
            <?php elseif($edit_mode == 'email_otp'): ?>
                <!-- OTP Verification -->
                <div class="otp-box">
                    <h4>📧 Verify New Email</h4>
                    <p style="color:#aaa;font-size:13px;margin-bottom:5px;">
                        OTP sent to: <strong style="color:#00ff88;"><?php echo htmlspecialchars($_SESSION['pending_email'] ?? ''); ?></strong>
                    </p>
                    <p style="color:#666;font-size:12px;margin-bottom:10px;">
                        ⏰ OTP expires in <?php echo OTP_EXPIRY_MINUTES; ?> minutes
                    </p>
                    <form method="POST">
                        <div class="input-box">
                            <label>Enter OTP</label>
                            <input type="text" name="otp" placeholder="Enter 6-digit OTP" maxlength="6" required autofocus>
                        </div>
                        <div class="otp-actions">
                            <button type="submit" name="verify_email_otp" class="btn btn-success">✅ Verify Email</button>
                            <a href="?resend_email_otp=1" class="btn btn-secondary">🔄 Resend</a>
                            <a href="?cancel=1" class="btn btn-danger">✖ Cancel</a>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="input-box">
                        <label>New Email Address</label>
                        <input type="email" name="new_email" placeholder="Enter new email address" required>
                        <span class="field-note">📧 An OTP will be sent to verify your new email</span>
                    </div>
                    <div class="action-row">
                        <button type="submit" name="send_email_otp" class="btn btn-primary">📨 Send OTP</button>
                        <a href="?cancel=1" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        
        <!-- ============================================================
        PASSWORD
        ============================================================ -->
        <div class="info-item">
            <span class="info-label">Password</span>
            <span class="info-value">
                <?php if($has_password): ?>
                    ••••••••••••••
                <?php else: ?>
                    <span style="color:#ff6600;">Not set</span>
                <?php endif; ?>
            </span>
            <button class="info-edit-btn <?php echo $edit_mode == 'password' ? 'active' : ''; ?>" onclick="toggleEdit('password')">✏️ Edit</button>
        </div>
        <div class="edit-form <?php echo $edit_mode == 'password' ? 'active' : ''; ?>" id="passwordForm">
            <form method="POST">
                <?php if($has_password): ?>
                <div class="input-box">
                    <label>Current Password</label>
                    <input type="password" name="current_password" placeholder="Enter your current password" required>
                </div>
                <?php else: ?>
                <div class="input-box">
                    <label style="color:#ff8800;">🔑 Set Your Password</label>
                    <input type="password" name="current_password" placeholder="Leave blank (Google account)" value="">
                    <span class="field-note">ℹ️ You're setting a password for your Google account</span>
                </div>
                <?php endif; ?>
                <div class="input-box">
                    <label>New Password</label>
                    <input type="password" name="new_password" placeholder="Enter new password (min 6 chars)" required>
                </div>
                <div class="input-box">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="Confirm your new password" required>
                </div>
                <div class="action-row">
                    <button type="submit" name="edit_password" class="btn btn-primary">
                        <?php echo $has_password ? '🔄 Update Password' : '🔑 Set Password'; ?>
                    </button>
                    <a href="?cancel=1" class="btn btn-secondary">Cancel</a>
                </div>
                <div style="margin-top:10px;">
                    <a href="forgot_password.php" class="forgot-link">🔒 Forgot Password?</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- ============================================================
    BACK TO PROFILE
    ============================================================ -->
    <a href="profile.php" class="back-btn">← Back to Profile</a>
</div>

<script>
/**
 * Toggle edit form visibility
 */
function toggleEdit(field) {
    var urlParams = new URLSearchParams(window.location.search);
    var currentMode = urlParams.get('mode');
    
    if (currentMode === field) {
        window.location.href = 'edit_profile.php?cancel=1';
        return;
    }
    
    <?php if(isset($_SESSION['pending_email']) && !empty($_SESSION['pending_email'])): ?>
    if (field !== 'email_otp') {
        alert('Please complete or cancel the email verification first.');
        return;
    }
    <?php endif; ?>
    
    window.location.href = 'edit_profile.php?mode=' + field;
}

// Check URL parameter on load
document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var mode = urlParams.get('mode');
    
    if (mode) {
        document.querySelectorAll('.edit-form').forEach(function(form) {
            form.classList.remove('active');
        });
        
        var formId = '';
        if (mode === 'name') formId = 'nameForm';
        else if (mode === 'email') formId = 'emailForm';
        else if (mode === 'email_otp') formId = 'emailForm';
        else if (mode === 'password') formId = 'passwordForm';
        
        if (formId) {
            document.getElementById(formId).classList.add('active');
        }
        
        document.querySelectorAll('.info-edit-btn').forEach(function(btn) {
            btn.classList.remove('active');
        });
        var targetBtn = document.querySelector('.info-edit-btn[onclick*="' + mode + '"]');
        if (targetBtn) {
            targetBtn.classList.add('active');
        }
    }
    
    // Auto-hide messages after 5 seconds
    var messages = document.querySelectorAll('.message');
    messages.forEach(function(msg) {
        setTimeout(function() {
            msg.style.transition = 'opacity 0.5s';
            msg.style.opacity = '0';
            setTimeout(function() {
                msg.style.display = 'none';
            }, 500);
        }, 5000);
    });
});
</script>
</body>
</html>