<?php
// ============================================================
// Device Management Helper
// Tracks and limits devices per user (max 3)
// ============================================================

class DeviceManager {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    /**
     * Generate unique device fingerprint (without IP for VPN support)
     */
    public function getDeviceFingerprint() {
        $fingerprint = [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            'platform' => php_uname('s') ?? ''
        ];
        return hash('sha256', json_encode($fingerprint));
    }
    
    /**
     * Detect device type from user agent
     */
    public function detectDeviceType() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (preg_match('/(android|iphone|ipod|mobile)/i', $ua)) {
            return 'mobile';
        }
        if (preg_match('/(ipad|tablet)/i', $ua)) {
            return 'tablet';
        }
        return 'desktop';
    }
    
    /**
     * Get user-friendly device name
     */
    public function getDeviceName() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser';
        
        // OS detection
        $os = 'Unknown OS';
        $os_patterns = [
            'Windows NT 10.0' => 'Windows 10',
            'Windows NT 6.3' => 'Windows 8.1',
            'Windows NT 6.1' => 'Windows 7',
            'Mac OS X' => 'Mac OS X',
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Android' => 'Android',
            'Linux' => 'Linux'
        ];
        foreach ($os_patterns as $pattern => $name) {
            if (stripos($ua, $pattern) !== false) {
                $os = $name;
                break;
            }
        }
        
        // Browser detection
        $browser = 'Unknown Browser';
        $browser_patterns = ['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'];
        foreach ($browser_patterns as $pattern => $name) {
            if (stripos($ua, $pattern) !== false) {
                $browser = $name;
                break;
            }
        }
        
        return "$os - $browser (" . $this->detectDeviceType() . ")";
    }
    
    /**
     * Register or validate device for a user
     */
    public function registerDevice($user_id, $force = false) {
        $device_id = $this->getDeviceFingerprint();
        $device_name = $this->getDeviceName();
        $device_type = $this->detectDeviceType();
        
        // Check if device already exists
        $stmt = $this->conn->prepare("SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1");
        $stmt->bind_param("is", $user_id, $device_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            // Update last login
            $update = $this->conn->prepare("UPDATE device_tokens SET last_login = NOW() WHERE user_id = ? AND device_id = ?");
            $update->bind_param("is", $user_id, $device_id);
            $update->execute();
            return ['success' => true, 'action' => 'existing_device'];
        }
        
        // Check device limit
        $count = $this->getActiveDeviceCount($user_id);
        if ($count >= MAX_DEVICES && !$force) {
            return ['success' => false, 'message' => "Maximum " . MAX_DEVICES . " devices limit reached."];
        }
        
        // Register new device
        $stmt = $this->conn->prepare("INSERT INTO device_tokens (user_id, device_id, device_name, device_type, last_login, is_active) VALUES (?, ?, ?, ?, NOW(), 1)");
        $stmt->bind_param("isss", $user_id, $device_id, $device_name, $device_type);
        
        if ($stmt->execute()) {
            return ['success' => true, 'action' => 'new_device'];
        }
        return ['success' => false, 'message' => 'Failed to register device'];
    }
    
    /**
     * Get active device count for a user
     */
    public function getActiveDeviceCount($user_id) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM device_tokens WHERE user_id = ? AND is_active = 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    }
    
    /**
     * Get all devices for a user
     */
    public function getUserDevices($user_id) {
        $stmt = $this->conn->prepare("SELECT id, device_name, device_type, last_login, device_id FROM device_tokens WHERE user_id = ? AND is_active = 1 ORDER BY last_login DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    
    /**
     * Remove a specific device
     */
    public function removeDevice($user_id, $device_token_id) {
        $stmt = $this->conn->prepare("DELETE FROM device_tokens WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $device_token_id, $user_id);
        return $stmt->execute();
    }
    
    /**
     * Remove all devices for a user (used on password reset)
     */
    public function removeAllDevices($user_id) {
        $stmt = $this->conn->prepare("DELETE FROM device_tokens WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        return $stmt->execute();
    }
    
    /**
     * Check if current device is allowed for login
     */
    public function checkDeviceAccess($user_id) {
        $device_id = $this->getDeviceFingerprint();
        
        $stmt = $this->conn->prepare("SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1");
        $stmt->bind_param("is", $user_id, $device_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $update = $this->conn->prepare("UPDATE device_tokens SET last_login = NOW() WHERE user_id = ? AND device_id = ?");
            $update->bind_param("is", $user_id, $device_id);
            $update->execute();
            return ['allowed' => true];
        }
        
        // Auto-register if under limit
        $result = $this->registerDevice($user_id);
        return [
            'allowed' => $result['success'],
            'message' => $result['message'] ?? 'Device authorized'
        ];
    }
}
?>