<?php
// ============================================================
// Google OAuth Callback Handler
// Processes Google's response, creates/login user
// ============================================================

session_start();
include "db.php";
include "config.php";
include "device_helper.php";
include "session_helper.php";

// Check if there's an error from Google
if (isset($_GET['error'])) {
    $error_message = "Google authentication failed: " . htmlspecialchars($_GET['error']);
    header("Location: login.php?error=" . urlencode($error_message));
    exit;
}

// Check if we have the authorization code
if (!isset($_GET['code'])) {
    header("Location: login.php?error=missing_code");
    exit;
}

$code = $_GET['code'];

// ============================================================
// 1. EXCHANGE CODE FOR ACCESS TOKEN
// ============================================================

$token_url = "https://oauth2.googleapis.com/token";

$post_data = [
    'code' => $code,
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'grant_type' => 'authorization_code'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$token_response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($token_response, true);

if (isset($token_data['error'])) {
    header("Location: login.php?error=" . urlencode($token_data['error']));
    exit;
}

if (!isset($token_data['access_token'])) {
    header("Location: login.php?error=no_access_token");
    exit;
}

$access_token = $token_data['access_token'];

// ============================================================
// 2. GET USER INFO FROM GOOGLE
// ============================================================

$userinfo_url = "https://www.googleapis.com/oauth2/v2/userinfo";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $userinfo_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $access_token
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$userinfo_response = curl_exec($ch);
curl_close($ch);

$userinfo = json_decode($userinfo_response, true);

if (isset($userinfo['error']) || !isset($userinfo['email'])) {
    header("Location: login.php?error=no_email");
    exit;
}

// ============================================================
// 3. PROCESS USER - LOGIN OR REGISTER
// ============================================================

$google_id = $userinfo['id'] ?? '';
$email = $userinfo['email'];
$name = $userinfo['name'] ?? $userinfo['given_name'] ?? 'Google User';
$picture = $userinfo['picture'] ?? '';

// Check if user exists by email
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    // ============================================================
    // USER EXISTS - LOGIN
    // ============================================================
    
    // Update user's google_id if not set
    if (empty($user['google_id'])) {
        $update_stmt = $conn->prepare("UPDATE users SET google_id = ?, avatar = ? WHERE id = ?");
        $update_stmt->bind_param("ssi", $google_id, $picture, $user['id']);
        $update_stmt->execute();
        $update_stmt->close();
    }
    
    // Check if email is verified
    if ($user['is_verified'] == 0) {
        $verify_stmt = $conn->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
        $verify_stmt->bind_param("i", $user['id']);
        $verify_stmt->execute();
        $verify_stmt->close();
    }
    
    // Check device access
    $device_manager = new DeviceManager($conn);
    $device_check = $device_manager->checkDeviceAccess($user['id']);
    
    if (!$device_check['allowed']) {
        header("Location: login.php?error=" . urlencode($device_check['message']));
        exit;
    }
    
    // Login user
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['password_hash'] = $user['password'];
    $_SESSION['google_login'] = true;
    
    // Create persistent session
    $session_manager = new SessionManager($conn);
    $session_manager->createPersistentSession($user['id']);
    
    header("Location: home.php");
    exit;
    
} else {
    // ============================================================
    // USER DOES NOT EXIST - REGISTER
    // ============================================================
    
    // Generate random password for Google users
    $random_password = bin2hex(random_bytes(16));
    $hashed_password = password_hash($random_password, PASSWORD_DEFAULT);
    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO users (name, email, password, google_id, avatar, is_verified, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
    $stmt->bind_param("sssss", $name, $email, $hashed_password, $google_id, $picture);
    
    if ($stmt->execute()) {
        $user_id = mysqli_insert_id($conn);
        
        // Login user
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_name'] = $name;
        $_SESSION['password_hash'] = $hashed_password;
        $_SESSION['google_login'] = true;
        
        // Register device
        $device_manager = new DeviceManager($conn);
        $device_manager->registerDevice($user_id);
        
        // Create persistent session
        $session_manager = new SessionManager($conn);
        $session_manager->createPersistentSession($user_id);
        
        header("Location: home.php");
        exit;
        
    } else {
        header("Location: login.php?error=registration_failed");
        exit;
    }
}
?>