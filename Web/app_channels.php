<?php
// ============================================================
// API: Get Channels List for Mobile App
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

$response = ['success' => false, 'channels' => [], 'premium_active' => false, 'message' => ''];

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';

$premium_active = false;

if ($user_id > 0) {
    // 1. Check if device is authorized
    $device_stmt = $conn->prepare("SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1");
    $device_stmt->bind_param("is", $user_id, $device_id);
    $device_stmt->execute();
    $device_result = $device_stmt->get_result();
    
    if ($device_result->num_rows == 0) {
        $response['success'] = false;
        $response['message'] = "Unauthorized device. Please login again.";
        echo json_encode($response);
        exit;
    }
    $device_stmt->close();

    // 2. Check subscription
    $stmt = $conn->prepare("SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' AND expires_at >= CURDATE()");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $premium_active = ($stmt->get_result()->num_rows > 0);
    $stmt->close();
}

// Fetch M3U content
function fetchM3U($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

$channels = [];

// Fetch free channels
$free_m3u = "https://jisanhsajin.neocities.org/LiveNetTV/FreeChannel.m3u";
$free_data = fetchM3U($free_m3u);

if ($free_data) {
    $lines = explode("\n", $free_data);
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], "#EXTINF") !== false) {
            preg_match('/tvg-logo="([^"]*)"/', $lines[$i], $logo);
            preg_match('/group-title="([^"]*)"/', $lines[$i], $group);
            preg_match('/,(.*)$/', $lines[$i], $name);
            $url = isset($lines[$i + 1]) ? trim($lines[$i + 1]) : "";
            $category = isset($group[1]) ? $group[1] : "Others";
            
            if (!empty($url) && strpos($url, 'http') === 0) {
                $channels[] = [
                    "name" => isset($name[1]) ? trim($name[1]) : "Free Channel",
                    "logo" => isset($logo[1]) ? $logo[1] : "",
                    "url" => $url,
                    "category" => $category,
                    "type" => "free"
                ];
            }
        }
    }
}

// Add premium channels if user has subscription
if ($premium_active) {
    $premium_m3u = "https://jisanhsajin.neocities.org/LiveNetTV/Premium_Channel.m3u";
    $premium_data = fetchM3U($premium_m3u);
    
    if ($premium_data) {
        $lines = explode("\n", $premium_data);
        for ($i = 0; $i < count($lines); $i++) {
            if (strpos($lines[$i], "#EXTINF") !== false) {
                preg_match('/tvg-logo="([^"]*)"/', $lines[$i], $logo);
                preg_match('/group-title="([^"]*)"/', $lines[$i], $group);
                preg_match('/,(.*)$/', $lines[$i], $name);
                $url = isset($lines[$i + 1]) ? trim($lines[$i + 1]) : "";
                $category = isset($group[1]) ? $group[1] : "Premium";
                
                if (!empty($url) && strpos($url, 'http') === 0) {
                    $channels[] = [
                        "name" => isset($name[1]) ? trim($name[1]) : "Premium Channel",
                        "logo" => isset($logo[1]) ? $logo[1] : "",
                        "url" => $url,
                        "category" => $category,
                        "type" => "premium"
                    ];
                }
            }
        }
    }
}

// Sort channels by name
usort($channels, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

$response['success'] = true;
$response['channels'] = $channels;
$response['premium_active'] = $premium_active;
$response['message'] = "Channels loaded successfully";

echo json_encode($response);
?>