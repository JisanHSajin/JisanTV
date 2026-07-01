<?php
// ============================================================
// Delete Account - Permanently Delete User Account
// ============================================================
// Users must confirm by typing "DELETE" and entering their
// password. All data is permanently removed via CASCADE.
// ============================================================

session_start();
include "db.php";
include "session_helper.php";
include "device_helper.php";
include "config.php";

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

$error = "";

// Get user info
$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'"));

// ============================================================
// 4. PROCESS DELETE
// ============================================================

if (isset($_POST['delete_account'])) {
    $confirm = trim($_POST['confirm_delete']);
    $password = trim($_POST['password']);
    
    if ($confirm !== 'DELETE') {
        $error = "Please type DELETE to confirm account deletion.";
    } elseif (empty($password)) {
        $error = "Please enter your password.";
    } else {
        // Verify password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        
        if (password_verify($password, $user_data['password'])) {
            // Delete user (CASCADE will delete subscriptions, payments, devices, sessions)
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->bind_param("i", $user_id);
            
            if ($delete_stmt->execute()) {
                // Destroy session
                $session_manager->destroyPersistentSession();
                session_destroy();
                
                // Show success and redirect
                echo '<!DOCTYPE html>
                <html>
                <head>
                    <title>Account Deleted - JisanTV</title>
                    <meta http-equiv="refresh" content="2;url=login.php?msg=account_deleted">
                    <style>
                        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
                        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;}
                        .msg{text-align:center;}
                        .msg h2{color:#00ff88;font-size:28px;margin-bottom:10px;}
                        .msg p{color:#888;font-size:16px;}
                        .msg .icon{font-size:60px;display:block;margin-bottom:15px;}
                    </style>
                </head>
                <body>
                    <div class="msg">
                        <span class="icon">✅</span>
                        <h2>Account Deleted Successfully</h2>
                        <p>Redirecting to login page...</p>
                    </div>
                </body>
                </html>';
                exit;
            } else {
                $error = "Failed to delete account. Please try again.";
            }
            $delete_stmt->close();
        } else {
            $error = "Incorrect password!";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Delete Account - JisanTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    
    <!-- ============================================================
    STYLES - Consistent with profile.php
    ============================================================ -->
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;}
        
        .delete-container{background:#1a1a1a;padding:40px;width:500px;border-radius:12px;border:2px solid #ff000066;text-align:center;}
        .delete-container h2{color:#ff4444;margin-bottom:10px;font-size:24px;}
        .delete-container .warning-icon{font-size:60px;display:block;margin-bottom:10px;}
        .delete-container .subtitle{color:#ff6666;font-size:14px;margin-bottom:25px;}
        
        .warning-box{background:#1a0000;padding:20px;border-radius:10px;border:1px solid #ff000044;margin-bottom:25px;text-align:left;}
        .warning-box li{color:#ff8888;font-size:13px;margin:6px 0;list-style:none;}
        .warning-box li::before{content:'⚠️ ';margin-right:5px;}
        
        .input-box{margin-bottom:18px;text-align:left;}
        .input-box label{display:block;color:#ff6666;font-size:13px;margin-bottom:5px;font-weight:bold;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #ff000044;background:#111;color:white;font-size:14px;}
        .input-box input:focus{border-color:#ff0000;outline:none;}
        
        /* Remove autofill background */
        .input-box input:-webkit-autofill,
        .input-box input:-webkit-autofill:hover,
        .input-box input:-webkit-autofill:focus,
        .input-box input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px #111 inset !important;
            -webkit-text-fill-color: white !important;
            background-color: #111 !important;
            background: #111 !important;
            transition: background-color 5000s ease-in-out 0s;
        }
        
        .btn{display:block;width:100%;padding:12px;border-radius:8px;font-weight:bold;text-decoration:none;border:none;cursor:pointer;font-size:16px;margin-top:10px;transition:0.3s;}
        .btn-delete{background:#cc0000;color:white;border:2px solid #ff3333;}
        .btn-delete:hover{background:#ff0000;}
        .btn-cancel{background:#444;color:white;}
        .btn-cancel:hover{background:#555;}
        
        .error-message{margin-bottom:15px;padding:12px;border-radius:8px;text-align:center;background:#660000;color:white;}
        
        .user-info{background:#111;padding:12px;border-radius:8px;margin-bottom:20px;color:#888;font-size:14px;}
        .user-info strong{color:white;}
        
        @media(max-width:500px){.delete-container{width:95%;padding:25px;}
        .warning-box li{font-size:12px;}}
    </style>
</head>
<body>
<div class="delete-container">
    <span class="warning-icon">🗑️</span>
    <h2>Delete Account</h2>
    <div class="subtitle">⚠️ This action is permanent and cannot be undone!</div>
    
    <div class="user-info">
        <strong>Account:</strong> <?php echo htmlspecialchars($user['email']); ?>
    </div>
    
    <!-- ============================================================
    WARNING LIST
    ============================================================ -->
    <div class="warning-box">
        <ul>
            <li>All your personal data will be permanently deleted</li>
            <li>Your subscriptions will be cancelled</li>
            <li>Your payment history will be removed</li>
            <li>All your devices will be logged out</li>
            <li>You will lose access to premium channels</li>
        </ul>
    </div>
    
    <!-- ============================================================
    ERROR MESSAGE
    ============================================================ -->
    <?php if(!empty($error)): ?>
        <div class="error-message"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- ============================================================
    DELETE FORM - No Autofill
    ============================================================ -->
    <form method="POST" onsubmit="return confirmDelete()" autocomplete="off">
        <div class="input-box">
            <label>Type <strong style="color:#ff0000;">DELETE</strong> to confirm:</label>
            <input type="text" name="confirm_delete" placeholder="Type DELETE here" required autofocus autocomplete="off">
        </div>
        <div class="input-box">
            <label>Enter your password to confirm:</label>
            <input type="password" name="password" placeholder="Enter your password" required autocomplete="new-password">
        </div>
        
        <button type="submit" name="delete_account" class="btn btn-delete">🗑️ Permanently Delete My Account</button>
        <a href="profile.php" class="btn btn-cancel">← Cancel and Go Back</a>
    </form>
</div>

<script>
/**
 * Confirm delete with validation
 */
function confirmDelete() {
    var confirmField = document.querySelector('input[name="confirm_delete"]');
    var passwordField = document.querySelector('input[name="password"]');
    
    if (confirmField.value !== 'DELETE') {
        alert('Please type DELETE to confirm account deletion.');
        confirmField.focus();
        return false;
    }
    
    if (passwordField.value === '') {
        alert('Please enter your password.');
        passwordField.focus();
        return false;
    }
    
    return confirm('⚠️ WARNING: This will permanently delete your account and ALL your data! This action cannot be undone. Are you sure?');
}
</script>
</body>
</html>