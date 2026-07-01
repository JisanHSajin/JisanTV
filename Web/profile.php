<?php
// ============================================================
// User Profile - Manage Devices, Subscription & Account
// ============================================================
// Users can view and manage:
// - Personal information (name, email, avatar)
// - Subscription status
// - Connected devices (view, remove, remove all)
// - Account actions (edit profile, logout, delete account)
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

$message = "";
$error = "";

// ============================================================
// 4. REMOVE SINGLE DEVICE
// ============================================================

if (isset($_GET['remove_device'])) {
    $device_token_id = (int)$_GET['remove_device'];
    
    $current_device_check = $conn->prepare("SELECT device_id FROM device_tokens WHERE id = ? AND user_id = ?");
    $current_device_check->bind_param("ii", $device_token_id, $user_id);
    $current_device_check->execute();
    $current_device_result = $current_device_check->get_result();
    
    if ($current_device_row = $current_device_result->fetch_assoc()) {
        $is_current_device = ($current_device_row['device_id'] == $device_id);
        
        if ($device_manager->removeDevice($user_id, $device_token_id)) {
            if ($is_current_device) {
                session_destroy();
                header("Location: login.php?msg=self_device_removed");
                exit;
            }
            $message = "Device removed successfully!";
        } else {
            $error = "Failed to remove device!";
        }
    }
}

// ============================================================
// 5. REMOVE ALL DEVICES
// ============================================================

if (isset($_GET['remove_all_devices'])) {
    $device_manager->removeAllDevices($user_id);
    session_destroy();
    header("Location: login.php?msg=all_devices_removed");
    exit;
}

// ============================================================
// 6. GET USER DATA
// ============================================================

$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'"));

// Get subscription
$sub = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM subscriptions WHERE user_id='$user_id' ORDER BY id DESC LIMIT 1"));
$sub_active = false;
$sub_message = "No Active Subscription";
if ($sub && $sub['status'] == 'active' && $sub['expires_at'] >= date("Y-m-d")) {
    $sub_active = true;
    $sub_message = "✅ Active - Expires on " . $sub['expires_at'];
} elseif ($sub) {
    $sub_message = "❌ Expired on " . $sub['expires_at'];
}

$devices = $device_manager->getUserDevices($user_id);
$device_count = $device_manager->getActiveDeviceCount($user_id);
$is_google_user = !empty($user['google_id']);
$has_password = !empty($user['password']);
?>

<!DOCTYPE html>
<html>
<head>
    <title>My Profile - JisanTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    
    <!-- ============================================================
    STYLES - Consistent with home.php
    ============================================================ -->
    <style>
        /* Reset & Base */
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;}
        
        /* Profile Container */
        .profile-container{background:#1a1a1a;padding:40px;width:600px;border-radius:12px;border:1px solid #00ffff33;}
        .profile-container h2{text-align:center;margin-bottom:25px;color:#00ffff;font-size:24px;}
        .profile-container h2 span{color:#ff8800;}
        
        /* Info Boxes */
        .info-box{background:#111;padding:18px;margin-bottom:20px;border-radius:10px;border:1px solid #00ffff22;}
        .info-box strong{color:lime;}
        .info-box .avatar{width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid #00ffff;margin-bottom:10px;}
        
        /* Badges */
        .badge{display:inline-block;padding:2px 12px;border-radius:12px;font-size:10px;font-weight:bold;}
        .badge-google{background:#4285f4;color:white;}
        .badge-password{background:#00ff88;color:black;}
        .badge-no-password{background:#ff6600;color:white;}
        
        /* Device Box */
        .device-box{background:#111;padding:18px;margin-bottom:20px;border-radius:10px;border:1px solid #00ffff22;}
        .device-box h3{color:lime;margin-bottom:15px;font-size:16px;}
        .device-item{display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid #222;}
        .device-item:last-child{border-bottom:none;}
        .device-name{font-weight:bold;}
        .current-device{color:#00ffff;font-size:11px;margin-left:8px;}
        .device-detail{font-size:12px;color:#888;margin-top:3px;}
        .remove-device{color:red;text-decoration:none;padding:5px 12px;border-radius:5px;background:#330000;font-size:12px;transition:0.3s;}
        .remove-device:hover{background:#660000;}
        .device-count{color:lime;font-weight:bold;}
        
        /* Buttons */
        .btn{display:block;width:100%;text-align:center;padding:12px;border-radius:8px;font-weight:bold;text-decoration:none;margin-top:10px;border:none;cursor:pointer;font-size:14px;transition:0.3s;}
        .btn-dashboard{background:#00ffff;color:black;}
        .btn-dashboard:hover{background:#00dddd;}
        .btn-buy{background:orange;color:black;}
        .btn-buy:hover{background:#ff9900;}
        .btn-logout{background:#cc0000;color:white;}
        .btn-logout:hover{background:#ff0000;}
        .btn-remove-all{background:#ff6600;color:white;}
        .btn-remove-all:hover{background:#ff5500;}
        .btn-edit{background:#4a90d9;color:white;}
        .btn-edit:hover{background:#3a7bc8;}
        .btn-delete{background:#cc0000;color:white;border:2px solid #ff3333;}
        .btn-delete:hover{background:#ff0000;}
        
        .action-buttons{display:flex;gap:10px;margin-top:10px;}
        .action-buttons .btn{flex:1;margin-top:0;}
        
        /* Messages */
        .message{margin-bottom:15px;padding:12px;border-radius:8px;text-align:center;background:green;color:white;}
        .error-message{margin-bottom:15px;padding:12px;border-radius:8px;text-align:center;background:#660000;color:white;}
        .warning-note{background:#331100;padding:10px;border-radius:8px;margin-top:15px;color:#ffaa00;font-size:12px;text-align:center;}
        
        /* Delete Section */
        .delete-section{margin-top:20px;text-align:center;border-top:1px solid #ff000044;padding-top:20px;}
        .delete-section .warning{color:#ff4444;font-size:12px;margin-top:10px;}
        
        /* Responsive */
        @media(max-width:600px){
            .profile-container{width:95%;padding:20px;}
            .device-item{flex-direction:column;text-align:center;gap:8px;}
            .remove-device{margin-top:5px;}
            .action-buttons{flex-direction:column;}
            .action-buttons .btn{margin-top:5px;}
            .profile-container h2{font-size:20px;}
        }
    </style>
</head>
<body>
<div class="profile-container">
    <h2>👤 My <span>Profile</span></h2>
    
    <!-- ============================================================
    MESSAGES
    ============================================================ -->
    <?php if(!empty($message)): ?>
        <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if(!empty($error)): ?>
        <div class="error-message"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- ============================================================
    USER INFO
    ============================================================ -->
    <div class="info-box">
        <?php if(!empty($user['avatar'])): ?>
            <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="Avatar" class="avatar">
        <?php endif; ?>
        <p><strong>Name:</strong> <?php echo htmlspecialchars($user['name'] ?? 'Not set'); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
        <p>
            <strong>Account Type:</strong>
            <?php if($is_google_user): ?>
                <span class="badge badge-google">Google</span>
            <?php else: ?>
                <span class="badge badge-password">Email</span>
            <?php endif; ?>
            
            <?php if($has_password): ?>
                <span class="badge badge-password">Has Password</span>
            <?php else: ?>
                <span class="badge badge-no-password">No Password</span>
            <?php endif; ?>
        </p>
        <p><strong>Member Since:</strong> <?php echo date("d M Y", strtotime($user['created_at'])); ?></p>
        
        <a href="edit_profile.php" class="btn btn-edit" style="margin-top:12px;">✏️ Edit Profile</a>
    </div>
    
    <!-- ============================================================
    SUBSCRIPTION
    ============================================================ -->
    <div class="info-box">
        <p><strong>Subscription:</strong> <?php echo $sub_message; ?></p>
        <?php if(!$sub_active): ?>
            <a href="buy.php" class="btn btn-buy">Buy Subscription</a>
        <?php endif; ?>
    </div>
    
    <!-- ============================================================
    DEVICES
    ============================================================ -->
    <div class="device-box">
        <h3>📱 Connected Devices (<span class="device-count"><?php echo $device_count; ?>/<?php echo MAX_DEVICES; ?></span>)</h3>
        
        <?php if($device_count == 0): ?>
            <p style="color:#888; text-align:center; padding:10px;">No devices connected.</p>
        <?php else: ?>
            <?php while($device = mysqli_fetch_assoc($devices)): ?>
            <?php 
                $is_current = ($device['device_id'] ?? '') == $device_id;
            ?>
            <div class="device-item">
                <div class="device-info">
                    <div class="device-name">
                        <?php echo htmlspecialchars($device['device_name']); ?>
                        <?php if($is_current): ?>
                            <span class="current-device">(Current)</span>
                        <?php endif; ?>
                    </div>
                    <div class="device-detail">Last active: <?php echo date("d M Y H:i", strtotime($device['last_login'])); ?></div>
                </div>
                <a href="?remove_device=<?php echo $device['id']; ?>" class="remove-device" onclick="return confirm('Remove this device? You will be logged out from it immediately.')">❌ Remove</a>
            </div>
            <?php endwhile; ?>
            
            <?php if($device_count > 0): ?>
                <a href="?remove_all_devices=1" class="btn btn-remove-all" onclick="return confirm('⚠️ WARNING: This will log you out from ALL devices including this one! Continue?')">🔒 Remove All Devices</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <div class="warning-note">
        ⚠️ Removing a device will immediately log out that device.
    </div>
    
    <!-- ============================================================
    ACTION BUTTONS
    ============================================================ -->
    <div class="action-buttons">
        <a href="home.php" class="btn btn-dashboard">🏠 Home</a>
        <a href="logout.php" class="btn btn-logout">🚪 Logout</a>
    </div>
    
    <!-- ============================================================
    DELETE ACCOUNT
    ============================================================ -->
    <div class="delete-section">
        <a href="delete_account.php" class="btn btn-delete" onclick="return confirm('⚠️ WARNING: This will permanently delete your account and ALL your data! This action cannot be undone. Are you sure you want to continue?')">
            🗑️ Delete My Account
        </a>
        <p class="warning">⚠️ This will permanently delete all your data including subscriptions, devices, and payment history.</p>
    </div>
</div>
</body>
</html>