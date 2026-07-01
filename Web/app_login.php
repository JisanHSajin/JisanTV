<?php
// ============================================================
// API: Mobile App Login
// ============================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "db.php";
require_once "device_helper.php";
require_once "config.php";

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $email = isset($input['email']) ? trim($input['email']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';
    $device_id = isset($input['device_id']) ? trim($input['device_id']) : '';
    $device_name = isset($input['device_name']) ? trim($input['device_name']) : 'Unknown Android Device';
    $device_type = isset($input['device_type']) ? trim($input['device_type']) : 'Android App';
    
    if (empty($email) || empty($password)) {
        $response['message'] = "Email and password are required";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                if ($user['is_verified'] == 1) {
                    $uid = $user['id'];
                    
                    // Check if device exists
                    $check_stmt = $conn->prepare("SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1");
                    $check_stmt->bind_param("is", $uid, $device_id);
                    $check_stmt->execute();
                    $device_exists = ($check_stmt->get_result()->num_rows > 0);
                    $check_stmt->close();
                    
                    if ($device_exists) {
                        // Update last login
                        $upd_stmt = $conn->prepare("UPDATE device_tokens SET last_login = NOW() WHERE user_id = ? AND device_id = ?");
                        $upd_stmt->bind_param("is", $uid, $device_id);
                        $upd_stmt->execute();
                        $upd_stmt->close();
                        $device_allowed = true;
                    } else {
                        // Check device limit
                        $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM device_tokens WHERE user_id = ? AND is_active = 1");
                        $count_stmt->bind_param("i", $uid);
                        $count_stmt->execute();
                        $current_devices = $count_stmt->get_result()->fetch_assoc()['count'];
                        $count_stmt->close();
                        
                        $max_limit = defined('MAX_DEVICES') ? MAX_DEVICES : 3;
                        
                        if ($current_devices < $max_limit) {
                            // Register new device
                            $ins_stmt = $conn->prepare("INSERT INTO device_tokens (user_id, device_id, device_name, device_type, last_login, is_active) VALUES (?, ?, ?, ?, NOW(), 1)");
                            $ins_stmt->bind_param("isss", $uid, $device_id, $device_name, $device_type);
                            $ins_stmt->execute();
                            $ins_stmt->close();
                            $device_allowed = true;
                        } else {
                            $device_allowed = false;
                            $response['message'] = "Login failed: Device limit reached ($max_limit devices). Please remove a device from your web profile.";
                        }
                    }
                    
                    if ($device_allowed) {
                        // Check subscription
                        $sub_stmt = $conn->prepare("SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' AND expires_at >= CURDATE()");
                        $sub_stmt->bind_param("i", $uid);
                        $sub_stmt->execute();
                        $subscription_active = ($sub_stmt->get_result()->num_rows > 0);
                        $sub_stmt->close();
                        
                        $response['success'] = true;
                        $response['user_id'] = $user['id'];
                        $response['user_name'] = $user['name'];
                        $response['subscription_active'] = $subscription_active;
                        $response['message'] = "Login successful";
                    }
                } else {
                    $response['message'] = "Please verify your email first";
                }
            } else {
                $response['message'] = "Wrong password";
            }
        } else {
            $response['message'] = "Email not found";
        }
        $stmt->close();
    }
} else {
    $response['message'] = "Please use POST method";
}

echo json_encode($response);
?>