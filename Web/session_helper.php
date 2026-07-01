<?php
// ============================================================
// Session Helper - Database-backed sessions for "Remember Me"
// ============================================================

class SessionManager {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
        
        // Set session cookie parameters
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Create persistent session token for "Remember Me"
     */
    public function createPersistentSession($user_id) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        $stmt = $this->conn->prepare("INSERT INTO session_tokens (user_id, session_token, expires_at) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $user_id, $token, $expires);
        
        if ($stmt->execute()) {
            setcookie(SESSION_COOKIE_NAME, $token, time() + SESSION_LIFETIME, '/', '', false, true);
            return true;
        }
        return false;
    }
    
    /**
     * Validate and restore session from cookie
     */
    public function validatePersistentSession() {
        if (!isset($_COOKIE[SESSION_COOKIE_NAME])) {
            return false;
        }
        
        $token = $_COOKIE[SESSION_COOKIE_NAME];
        $this->cleanExpiredTokens();
        
        $stmt = $this->conn->prepare("SELECT user_id FROM session_tokens WHERE session_token = ? AND expires_at > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $user_id = $row['user_id'];
            
            $user_stmt = $this->conn->prepare("SELECT id, name, password FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            
            if ($user = $user_result->fetch_assoc()) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['password_hash'] = $user['password'];
                $_SESSION['persistent_login'] = true;
                $_SESSION['login_time'] = time();
                
                $this->refreshToken($token);
                return true;
            }
        }
        return false;
    }
    
    private function refreshToken($token) {
        $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        $stmt = $this->conn->prepare("UPDATE session_tokens SET expires_at = ? WHERE session_token = ?");
        $stmt->bind_param("ss", $expires, $token);
        $stmt->execute();
        
        setcookie(SESSION_COOKIE_NAME, $token, time() + SESSION_LIFETIME, '/', '', false, true);
    }
    
    public function cleanExpiredTokens() {
        $stmt = $this->conn->prepare("DELETE FROM session_tokens WHERE expires_at < NOW()");
        $stmt->execute();
    }
    
    public function destroyPersistentSession() {
        if (isset($_COOKIE[SESSION_COOKIE_NAME])) {
            $token = $_COOKIE[SESSION_COOKIE_NAME];
            $stmt = $this->conn->prepare("DELETE FROM session_tokens WHERE session_token = ?");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            setcookie(SESSION_COOKIE_NAME, '', time() - 3600, '/');
        }
    }
    
    /**
     * Check if user is logged in (session or persistent)
     */
    public function isLoggedIn() {
        if (isset($_SESSION['user_id']) && isset($_SESSION['password_hash'])) {
            // Verify password hasn't changed
            $stmt = $this->conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                if ($_SESSION['password_hash'] === $row['password']) {
                    return true;
                }
            }
            session_destroy();
            return false;
        }
        return $this->validatePersistentSession();
    }
}
?>