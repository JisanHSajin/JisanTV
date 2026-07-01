<?php
// ============================================================
// Buy Premium Subscription Page
// ============================================================

session_start();
include "db.php";
include "session_helper.php";
include "config.php";
include "device_helper.php";

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

// Validate password
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
}

// Validate device
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

if (isset($_POST['submit_payment'])) {
    $plan = $_POST['plan'];
    $trxid = trim($_POST['trxid']);
    
    $amount = match($plan) {
        '1month' => PRICE_1_MONTH,
        '3month' => PRICE_3_MONTH,
        '6month' => PRICE_6_MONTH,
        default => 0
    };
    
    if (empty($trxid)) {
        $message = "Transaction ID is required!";
    } elseif ($amount > 0) {
        $stmt = $conn->prepare("INSERT INTO payments (user_id, trxid, amount, status) VALUES (?, ?, ?, 'pending')");
        $stmt->bind_param("isi", $_SESSION['user_id'], $trxid, $amount);
        $stmt->execute();
        $message = "Payment submitted successfully! Wait for admin approval.";
    } else {
        $message = "Invalid plan selected!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Buy Premium</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;}
        .buy-container{background:#1a1a1a;padding:40px;width:420px;border-radius:12px;border:1px solid #00ffff33;text-align:center;}
        .buy-container h2{margin-bottom:20px;color:#00ffff;}
        .info{margin-bottom:15px;color:lime;font-weight:bold;}
        .input-box{margin-bottom:15px;}
        .input-box select,.input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;}
        .buy-btn{width:100%;padding:12px;background:orange;border:none;border-radius:8px;font-weight:bold;cursor:pointer;}
        .buy-btn:hover{background:#ff9900;}
        .message{margin-top:15px;color:yellow;}
        .links{margin-top:20px;}
        .links a{color:#00ffff;text-decoration:none;}
        @media(max-width:420px){.buy-container{width:95%;padding:25px;}}
    </style>
</head>
<body>
<div class="buy-container">
    <h2>Buy Premium Subscription</h2>
    <p class="info">Pay via bKash to: <strong><?php echo BKASH_NUMBER; ?></strong></p>
    <p class="info">Enter transaction id of your bkash payment</p>
    <form method="POST">
        <div class="input-box">
            <select name="plan" required>
                <option value="1month">1 Month - <?php echo PRICE_1_MONTH; ?>৳</option>
                <option value="3month">3 Months - <?php echo PRICE_3_MONTH; ?>৳</option>
                <option value="6month">6 Months - <?php echo PRICE_6_MONTH; ?>৳</option>
            </select>
        </div>
        <div class="input-box">
            <input type="text" name="trxid" placeholder="Enter bKash Transaction ID" required>
        </div>
        <button type="submit" name="submit_payment" class="buy-btn">Submit Payment</button>
        <div class="message"><?php echo $message; ?></div>
    </form>
    <div class="links"><a href="home.php">Go to Home</a></div>
</div>
</body>
</html>