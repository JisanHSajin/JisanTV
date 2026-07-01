<?php
// ============================================================
// HOME PAGE - Channel Listing & Live TV Player
// ============================================================
// Free channels are visible to everyone (no login required)
// Premium channels require login AND active subscription
// ============================================================

session_start();
include "db.php";
include "config.php";
include "session_helper.php";
include "device_helper.php";

// ============================================================
// 1. CHECK LOGIN STATUS
// ============================================================

$is_logged_in = false;
$user_id = 0;
$user_name = '';
$premium_active = false;

$session_manager = new SessionManager($conn);
if ($session_manager->isLoggedIn()) {
    $is_logged_in = true;
    $user_id = $_SESSION['user_id'];
    $user_name = $_SESSION['user_name'] ?? '';
    
    // ============================================================
    // 2. VALIDATE PASSWORD (only for logged-in users)
    // ============================================================
    
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (!isset($_SESSION['password_hash']) || $_SESSION['password_hash'] !== $row['password']) {
            session_destroy();
            $is_logged_in = false;
            header("Location: login.php?msg=password_changed");
            exit;
        }
    } else {
        session_destroy();
        $is_logged_in = false;
        header("Location: login.php");
        exit;
    }
    
    // ============================================================
    // 3. VALIDATE DEVICE (only for logged-in users)
    // ============================================================
    
    $device_manager = new DeviceManager($conn);
    $device_id = $device_manager->getDeviceFingerprint();
    $device_check = $conn->prepare("SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1");
    $device_check->bind_param("is", $user_id, $device_id);
    $device_check->execute();
    
    if ($device_check->get_result()->num_rows == 0) {
        session_destroy();
        $is_logged_in = false;
        header("Location: login.php?msg=device_removed");
        exit;
    }
    
    // ============================================================
    // 4. CHECK SUBSCRIPTION (only for logged-in users)
    // ============================================================
    
    $stmt = $conn->prepare("SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' AND expires_at >= CURDATE()");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $premium_active = ($stmt->get_result()->num_rows > 0);
    $stmt->close();
}

// ============================================================
// 5. FETCH CHANNELS FROM M3U PLAYLISTS
// ============================================================

/**
 * Fetch M3U playlist from URL using cURL
 */
function fetchM3U($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

/**
 * Parse M3U content into channel array grouped by category
 */
function parseM3U($data) {
    $channels = [];
    if (!$data) return $channels;
    
    $lines = preg_split("/\r\n|\n|\r/", $data);
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], "#EXTINF") !== false) {
            preg_match('/tvg-logo="([^"]*)"/', $lines[$i], $logo);
            preg_match('/group-title="([^"]*)"/', $lines[$i], $group);
            preg_match('/,(.*)$/', $lines[$i], $name);
            $url = trim($lines[$i + 1] ?? "");
            $category = $group[1] ?? "Others";
            
            if (!empty($url)) {
                $channels[$category][] = [
                    "name" => trim($name[1] ?? "Channel"),
                    "logo" => $logo[1] ?? "",
                    "url" => $url
                ];
            }
        }
    }
    return $channels;
}

// Load free channels (always available - no login required)
$free_channels = parseM3U(fetchM3U(FREE_M3U_URL));

// Load premium channels (only if user is logged in AND has subscription)
$premium_channels = ($is_logged_in && $premium_active) ? parseM3U(fetchM3U(PREMIUM_M3U_URL)) : [];

// Check if user is on mobile app
$is_mobile_app = false;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (stripos($user_agent, 'JisanTV') !== false || 
    stripos($user_agent, 'JisanRealTV') !== false ||
    stripos($user_agent, 'wv') !== false ||
    (stripos($user_agent, 'Android') !== false && stripos($user_agent, 'wv') !== false)) {
    $is_mobile_app = true;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>JisanTV - Watch Live Channels</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <!-- Preconnect for faster loading -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://jisanhsajin.neocities.org">

    <!-- ============================================================
    STYLES
    ============================================================ -->
    <style>
        /* Reset & Base */
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;}
        
        /* Header */
        .site-header{display:flex;justify-content:space-between;align-items:center;padding:15px 30px;background:#1a1a1a;border-bottom:1px solid #00ffff33;flex-wrap:wrap;gap:10px;}
        .site-branding a{display:flex;align-items:center;text-decoration:none;color:white;}
        .site-branding img{width:45px;height:45px;margin-right:10px;border-radius:10px;}
        .site-branding span{font-size:18px;font-weight:bold;color:#00ffff;}
        .site-nav{display:flex;gap:20px;flex-wrap:wrap;}
        .site-nav a,.header-right a{color:#00ffff;text-decoration:none;font-weight:bold;}
        .site-nav a:hover,.header-right a:hover{color:#00cccc;}
        .header-right{display:flex;gap:15px;align-items:center;flex-wrap:wrap;}
        
        /* ============================================================
        MAIN LAYOUT - Player on Left (55%), Channels on Right (45%)
        ============================================================ */
        .main-layout{display:flex;gap:20px;padding:15px 20px;height:calc(100vh - 80px);min-height:500px;}
        
        /* Left Side - Video Player (Bigger) */
        .left-side{flex:0 0 55%;display:flex;justify-content:center;align-items:center;padding:5px;}
        .left-side .player-container{width:100%;height:100%;max-height:85vh;background:#000;border-radius:15px;overflow:hidden;border:1px solid #00ffff33;position:relative;aspect-ratio:16/9;}
        .player-container iframe{width:100%;height:100%;border:none;background:#000;display:block;}
        
        /* Right Side - Channel List */
        .right-side{flex:1;max-height:calc(100vh - 80px);overflow-y:auto;padding-right:5px;}
        .right-side::-webkit-scrollbar{width:6px;}
        .right-side::-webkit-scrollbar-track{background:#1a1a1a;border-radius:10px;}
        .right-side::-webkit-scrollbar-thumb{background:#00ffff44;border-radius:10px;}
        .right-side::-webkit-scrollbar-thumb:hover{background:#00ffff88;}
        
        /* Search */
        .search-box{padding:15px 0;position:sticky;top:0;background:#0f0f0f;z-index:500;}
        #searchInput{width:100%;padding:12px 16px;border-radius:10px;border:1px solid #333;background:#1a1a1a;color:white;font-size:14px;}
        #searchInput:focus{border-color:#00ffff;outline:none;}
        
        /* Category Filter */
        .category-filter{padding:8px 0 12px 0;display:flex;flex-wrap:wrap;gap:8px;border-bottom:1px solid #00ffff33;}
        .cat-btn{padding:6px 12px;background:#1a1a1a;border:1px solid #00ffff33;border-radius:8px;cursor:pointer;color:white;font-size:12px;transition:0.3s;}
        .cat-btn:hover,.cat-btn.active{background:#00ffff;color:black;}
        
        /* Channel Grid */
        h2{margin:20px 0 10px 0;color:#00ffff;font-size:18px;}
        .category-block{margin-bottom:15px;}
        .category-title{margin:12px 0 10px 0;font-size:16px;color:#00ffff;text-align:center;padding:8px;background:#1a1a1a;border:1px solid #00ffff33;border-radius:10px;}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;padding:0 0 15px 0;}
        .card{background:#1a1a1a;padding:12px 8px;border-radius:10px;text-align:center;cursor:pointer;border:1px solid #00ffff22;transition:0.3s;}
        .card:hover{transform:translateY(-3px);box-shadow:0 0 15px rgba(0,255,255,0.3);}
        .card img{max-height:40px;max-width:100%;margin-bottom:8px;display:block;margin-left:auto;margin-right:auto;}
        .card div{font-size:12px;word-wrap:break-word;line-height:1.2;}
        
        /* Premium Lock Box */
        .lockbox{padding:20px;text-align:center;background:#1a1a1a;margin:0 0 20px 0;border-radius:12px;border:1px solid red;}
        .lockbox h3{color:red;margin-bottom:8px;font-size:16px;}
        .lockbox p{color:#ccc;margin-bottom:12px;font-size:13px;}
        .lockbox a{color:lime;font-size:16px;font-weight:bold;text-decoration:none;}
        .lockbox a:hover{text-decoration:underline;}
        .login-prompt{padding:20px;text-align:center;background:#1a1a2e;margin:0 0 20px 0;border-radius:12px;border:1px solid #00ffff44;}
        .login-prompt a{color:#00ffff;font-size:16px;font-weight:bold;text-decoration:none;}
        .login-prompt a:hover{text-decoration:underline;}
        
        /* Badges */
        .premium-badge{display:inline-block;background:#ffd700;color:#000;font-size:9px;padding:2px 8px;border-radius:10px;font-weight:bold;margin-left:5px;}
        .free-badge{display:inline-block;background:#00ff88;color:#000;font-size:9px;padding:2px 8px;border-radius:10px;font-weight:bold;margin-left:5px;}
        
        /* App Ad Banner Styles */
        .app-ad-banner {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border: 1px solid #00ffff44;
            border-radius: 16px;
            margin: 20px 30px;
            padding: 20px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,255,255,0.1);
        }
        .app-ad-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(0,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }
        @keyframes pulse {
            0% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.05); }
            100% { opacity: 0.3; transform: scale(1); }
        }
        .app-ad-content {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            z-index: 2;
            flex: 2;
        }
        .app-ad-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #00ffff, #0066ff);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            box-shadow: 0 0 15px rgba(0,255,255,0.5);
        }
        .app-ad-text h3 {
            color: #00ffff;
            font-size: 20px;
            margin-bottom: 5px;
        }
        .app-ad-text p {
            color: #ccc;
            font-size: 14px;
        }
        .app-ad-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            z-index: 2;
        }
        .app-download-btn {
            background: linear-gradient(135deg, #00ffff, #0066ff);
            color: #000;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 40px;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: 0.3s;
            box-shadow: 0 3px 10px rgba(0,255,255,0.3);
        }
        .app-download-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,255,255,0.5);
            background: linear-gradient(135deg, #00ddff, #0055dd);
        }
        .app-download-btn.secondary {
            background: linear-gradient(135deg, #ff6600, #ff0066);
            box-shadow: 0 3px 10px rgba(255,102,0,0.3);
        }
        .app-download-btn.secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255,102,0,0.5);
        }
        .close-ad {
            position: absolute;
            top: 10px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            cursor: pointer;
            font-size: 18px;
            padding: 0 8px;
            border-radius: 50%;
            z-index: 3;
            transition: 0.3s;
        }
        .close-ad:hover {
            background: rgba(255,255,255,0.4);
            color: #ff4444;
        }
        .mobile-app-hidden {
            display: none !important;
        }
        
        /* Footer */
        .site-footer{background:#1a1a1a;padding:15px 30px;text-align:center;border-top:1px solid #00ffff22;}
        .footer-social{display:flex;justify-content:center;gap:15px;margin-bottom:8px;}
        .footer-social svg{width:20px;height:20px;fill:white;}
        .footer-social a:hover svg{fill:#00ffff;}
        .site-footer p{font-size:12px;color:#666;}
        
        /* Prevent right-click */
        body{-webkit-touch-callout:none;-webkit-user-select:none;-khtml-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;}
        
        /* ============================================================
        RESPONSIVE DESIGN
        ============================================================ */
        @media(max-width:1024px){.left-side{flex:0 0 50%;}}
        @media(max-width:768px){
            .main-layout{flex-direction:column;padding:10px 12px;height:auto;min-height:auto;}
            .left-side{flex:0 0 auto;width:100%;padding:0;}
            .left-side .player-container{height:auto;max-height:50vh;aspect-ratio:16/9;}
            .right-side{max-height:none;padding-right:0;}
            .site-header{flex-direction:column;text-align:center;padding:12px 15px;}
            .site-branding span{font-size:16px;}
            .grid{grid-template-columns:repeat(2,1fr);gap:8px;}
            h2{margin:15px 0 8px 0;font-size:16px;}
            .category-title{margin:10px 0 8px 0;font-size:14px;padding:6px;}
            .search-box{padding:10px 0;}
            #searchInput{padding:10px 14px;font-size:13px;}
            .card{padding:10px 6px;}
            .card img{max-height:35px;}
            .card div{font-size:11px;}
            .cat-btn{font-size:11px;padding:5px 8px;}
            .lockbox{padding:15px;margin:0 0 15px 0;}
            .lockbox a{font-size:14px;}
            .login-prompt{padding:15px;margin:0 0 15px 0;}
            .login-prompt a{font-size:14px;}
            .app-ad-banner { margin: 15px 12px; flex-direction: column; text-align: center; }
            .app-ad-content { justify-content: center; }
        }
        @media(max-width:480px){
            .main-layout{padding:8px 8px;}
            .left-side .player-container{max-height:40vh;}
            .grid{grid-template-columns:repeat(2,1fr);gap:6px;padding:0 0 10px 0;}
            h2{margin:12px 0 6px 0;font-size:14px;}
            .category-title{margin:8px 0 6px 0;font-size:12px;padding:5px;}
            .card{padding:8px 4px;}
            .card img{max-height:30px;}
            .card div{font-size:10px;}
            .search-box{padding:6px 0;}
            #searchInput{padding:8px 12px;font-size:12px;}
            .category-filter{padding:4px 0 8px 0;gap:4px;}
            .cat-btn{font-size:10px;padding:4px 8px;}
            .lockbox{padding:12px;}
            .lockbox h3{font-size:14px;}
            .lockbox p{font-size:12px;}
            .lockbox a{font-size:13px;}
            .login-prompt{padding:12px;}
            .login-prompt a{font-size:13px;}
            .site-header{padding:8px 10px;}
            .site-branding img{width:35px;height:35px;}
            .site-branding span{font-size:14px;}
            .header-right{gap:10px;font-size:13px;}
            .site-footer{padding:10px 15px;}
            .footer-social{gap:10px;}
            .footer-social svg{width:18px;height:18px;}
            .app-ad-banner { margin: 10px 8px; padding: 15px; }
            .app-ad-icon { width: 45px; height: 45px; font-size: 24px; }
            .app-ad-text h3 { font-size: 16px; }
            .app-ad-text p { font-size: 12px; }
            .app-download-btn { padding: 8px 16px; font-size: 12px; }
        }
    </style>
</head>

<body>
    <!-- ============================================================
    HEADER
    ============================================================ -->
    <header class="site-header">
        <div class="site-branding">
            <a href="home.php">
                <img src="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png" alt="Logo">
                <span>JisanTV</span>
            </a>
        </div>
        <nav class="site-nav">
            <a href="https://apkpure.com/developer?id=41147142" target="_blank">App</a>
            <a href="#channels">Channels</a>
            <a href="#footer">Connect</a>
        </nav>
        <div class="header-right">
            <?php if($is_logged_in): ?>
                <span><b><?php echo htmlspecialchars($user_name); ?></b></span>
                <a href="profile.php">Profile</a>
                <a href="logout.php">Logout</a>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- ============================================================
    APP AD BANNER - Always visible by default
    ============================================================ -->
    <div class="app-ad-banner <?php echo $is_mobile_app ? 'mobile-app-hidden' : ''; ?>" id="mainAdBanner">
        <button class="close-ad" id="closeAdBtn" title="Close this ad">×</button>
        <div class="app-ad-content">
            <div class="app-ad-icon">📺</div>
            <div class="app-ad-text">
                <h3>🔥 Get JisanTV Mobile App!</h3>
                <p>Watch 1000+ live channels on your phone - Better experience, less buffering!</p>
            </div>
        </div>
        <div class="app-ad-buttons">
            <a href="https://apkpure.com/p/com.jisanhsajin.jisantv" target="_blank" class="app-download-btn">
                📱 JisanTV
            </a>
            <a href="https://apkpure.com/p/com.jisanhsajin.jisanrealtv" target="_blank" class="app-download-btn secondary">
                🎬 JisanRealTV
            </a>
        </div>
    </div>

    <!-- ============================================================
    MAIN LAYOUT: Player (Left 55%) + Channel List (Right 45%)
    ============================================================ -->
    <div class="main-layout">
        <!-- Left: Video Player (Bigger) -->
        <div class="left-side">
            <div class="player-container" id="playerContainer">
                <iframe id="playerFrame" src="player.php" allow="autoplay; fullscreen" allowfullscreen></iframe>
            </div>
        </div>
        
        <!-- Right: Channel List -->
        <div class="right-side">
            <!-- Search Bar -->
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search channels..." onkeyup="searchChannels()">
            </div>
            
            <!-- Category Filter -->
            <div class="category-filter" id="categoryFilter"></div>
            
            <!-- ============================================================
            FREE CHANNELS - Visible to Everyone (No Login Required)
            ============================================================ -->
            <h2 id="channels">📺 Free Channels <span class="free-badge">FREE</span></h2>
            
            <?php if(empty($free_channels)): ?>
                <p style="text-align:center;color:#aaa;padding:20px;">No free channels available at the moment.</p>
            <?php else: ?>
                <?php foreach($free_channels as $cat => $channels): ?>
                <div class="category-block" data-category="<?php echo strtolower($cat); ?>">
                    <div class="category-title"><?php echo htmlspecialchars($cat); ?></div>
                    <div class="grid">
                        <?php foreach($channels as $ch): ?>
                        <div class="card" data-name="<?php echo strtolower($ch['name']); ?>" onclick="playStream('<?php echo urlencode($ch['url']); ?>', '<?php echo addslashes($ch['name']); ?>')">
                            <img src="<?php echo htmlspecialchars($ch['logo']); ?>" alt="<?php echo htmlspecialchars($ch['name']); ?>" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22%2300ffff%22%3E%3Cpath d=%22M4 6h16v12H4z%22/%3E%3C/svg%3E'">
                            <div><?php echo htmlspecialchars($ch['name']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- ============================================================
            PREMIUM CHANNELS - Requires Login + Subscription
            ============================================================ -->
            <h2>⭐ Premium Channels <span class="premium-badge">PREMIUM</span></h2>
            
            <?php if(!$is_logged_in): ?>
                <!-- User is NOT logged in - Show Login Prompt -->
                <div class="lockbox">
                    <h3>🔒 Login Required</h3>
                    <p>Please login or register to access premium channels.</p>
                    <div class="login-prompt" style="margin:0;border-color:red;">
                        <p><a href="login.php">Login</a> or <a href="register.php">Register</a> to buy premium subscription</p>
                    </div>
                </div>
            <?php elseif(!$premium_active): ?>
                <!-- User IS logged in but NO active subscription -->
                <div class="lockbox">
                    <h3>🔒 Premium Locked</h3>
                    <p>You need an active subscription to unlock premium channels.</p>
                    <a href="buy.php">Buy Premium Now →</a>
                </div>
            <?php else: ?>
                <!-- User IS logged in AND has active subscription -->
                <?php if(empty($premium_channels)): ?>
                    <p style="text-align:center;color:#aaa;padding:20px;">No premium channels available at the moment.</p>
                <?php else: ?>
                    <?php foreach($premium_channels as $cat => $channels): ?>
                    <div class="category-block" data-category="<?php echo strtolower($cat); ?>">
                        <div class="category-title"><?php echo htmlspecialchars($cat); ?></div>
                        <div class="grid">
                            <?php foreach($channels as $ch): ?>
                            <div class="card" data-name="<?php echo strtolower($ch['name']); ?>" onclick="playStream('<?php echo urlencode($ch['url']); ?>', '<?php echo addslashes($ch['name']); ?>')">
                                <img src="<?php echo htmlspecialchars($ch['logo']); ?>" alt="<?php echo htmlspecialchars($ch['name']); ?>" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22%23ffd700%22%3E%3Cpath d=%22M12 2l2.4 7.2h7.6l-6 4.8 2.4 7.2-6-4.8-6 4.8 2.4-7.2-6-4.8h7.6z%22/%3E%3C/svg%3E'">
                                <div><?php echo htmlspecialchars($ch['name']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <footer class="site-footer" id="footer">
        <div class="footer-social">
            <a href="https://www.facebook.com/jisanhsajin/" target="_blank">
                <svg viewBox="0 0 24 24"><path d="M22.675 0H1.325C.593 0 0 .593 0 1.325v21.351C0 23.407.593 24 1.325 24H12.82v-9.294H9.692v-3.622h3.128V8.413c0-3.1 1.894-4.788 4.659-4.788 1.325 0 2.464.099 2.796.143v3.24l-1.918.001c-1.504 0-1.795.715-1.795 1.763v2.312h3.587l-.467 3.622h-3.12V24h6.116c.73 0 1.324-.593 1.324-1.324V1.325C24 .593 23.407 0 22.675 0z"/></svg>
            </a>
            <a href="https://www.instagram.com/jisanhsajin/" target="_blank">
                <svg viewBox="0 0 24 24"><path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm5 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm6.5-.9a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2z"/></svg>
            </a>
            <a href="https://www.linkedin.com/in/jisanhsajin/" target="_blank">
                <svg viewBox="0 0 24 24"><path d="M20.447 20.452H17.24v-5.569c0-1.327-.026-3.037-1.85-3.037-1.852 0-2.136 1.447-2.136 2.942v5.664H9.06V9h3.033v1.561h.043c.423-.8 1.455-1.644 2.996-1.644 3.202 0 3.794 2.107 3.794 4.847v6.688zM5.337 7.433a1.755 1.755 0 1 1 0-3.509 1.755 1.755 0 0 1 0 3.509zM6.814 20.452H3.861V9h2.953v11.452z"/></svg>
            </a>
            <a href="https://www.youtube.com/@JisanHSajin" target="_blank">
                <svg viewBox="0 0 24 24"><path d="M23.498 6.186a2.946 2.946 0 0 0-2.074-2.075C19.708 3.5 12 3.5 12 3.5s-7.708 0-9.424.611A2.946 2.946 0 0 0 .502 6.186C0 7.895 0 12 0 12s0 4.105.502 5.814a2.946 2.946 0 0 0 2.074 2.075C4.292 20.5 12 20.5 12 20.5s7.708 0 9.424-.611a2.946 2.946 0 0 0 2.074-2.075C24 16.105 24 12 24 12s0-4.105-.502-5.814zM9.545 15.568V8.432L15.818 12z"/></svg>
            </a>
            <a href="https://github.com/jisanhsajin" target="_blank">
                <svg viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.387.6.113.82-.258.82-.577v-2.234c-3.338.724-4.042-1.61-4.042-1.61-.546-1.387-1.333-1.756-1.333-1.756-1.089-.744.084-.729.084-.729 1.205.084 1.838 1.237 1.838 1.237 1.07 1.835 2.809 1.304 3.495.997.108-.776.418-1.304.762-1.604-2.665-.3-5.467-1.332-5.467-5.93 0-1.31.468-2.38 1.236-3.22-.124-.303-.536-1.523.116-3.176 0 0 1.008-.322 3.3 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.29-1.552 3.296-1.23 3.296-1.23.653 1.653.242 2.873.118 3.176.77.84 1.236 1.91 1.236 3.22 0 4.61-2.807 5.625-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .321.216.694.825.576C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12z"/></svg>
            </a>
        </div>
        <p>© 2026 JisanTV | All Rights Reserved</p>
    </footer>

    <!-- ============================================================
    JAVASCRIPT
    ============================================================ -->
    <script>
        /**
         * Play a stream in the video player
         */
        function playStream(url, channelName) {
            var frame = document.getElementById('playerFrame');
            frame.src = 'player.php?url=' + encodeURIComponent(url) + '&name=' + encodeURIComponent(channelName);
            document.querySelector(".player-container").scrollIntoView({ behavior: "smooth" });
        }

        /**
         * Filter channels by search input
         */
        function searchChannels() {
            let input = document.getElementById("searchInput").value.toLowerCase();
            document.querySelectorAll(".category-block").forEach(block => {
                let catName = block.querySelector(".category-title").innerText.toLowerCase();
                let cards = block.querySelectorAll(".card");
                let hasMatch = false;
                cards.forEach(card => {
                    let chName = card.getAttribute("data-name");
                    if(chName.includes(input) || catName.includes(input)) {
                        card.style.display = "block";
                        hasMatch = true;
                    } else {
                        card.style.display = "none";
                    }
                });
                block.style.display = hasMatch ? "block" : "none";
            });
        }

        /**
         * Build category filter buttons on page load
         */
        window.onload = function() {
            let categories = new Set();
            document.querySelectorAll(".category-block").forEach(block => {
                categories.add(block.querySelector(".category-title").innerText);
            });
            let filterDiv = document.getElementById("categoryFilter");
            filterDiv.innerHTML = '<div class="cat-btn active" onclick="filterCategory(\'all\', event)">All</div>';
            categories.forEach(cat => {
                filterDiv.innerHTML += '<div class="cat-btn" onclick="filterCategory(\'' + cat.toLowerCase() + '\', event)">' + cat + '</div>';
            });
        };

        /**
         * Filter channels by category
         */
        function filterCategory(category, event) {
            document.querySelectorAll(".cat-btn").forEach(btn => btn.classList.remove("active"));
            event.target.classList.add("active");
            document.querySelectorAll(".category-block").forEach(block => {
                let cat = block.querySelector(".category-title").innerText.toLowerCase();
                block.style.display = (category === "all" || cat === category) ? "block" : "none";
            });
        }

        // Disable right-click context menu
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });

        // ========== APP AD BANNER - Shows every time ==========
        document.addEventListener('DOMContentLoaded', function() {
            <?php if(!$is_mobile_app): ?>
            const banner = document.getElementById('mainAdBanner');
            const closeBtn = document.getElementById('closeAdBtn');
            
            // Always show banner on every page load
            if (banner) {
                banner.style.display = 'flex';
            }
            
            // Only hide for current session when clicked
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    if (banner) {
                        banner.style.display = 'none';
                        // Do NOT store in localStorage - will show again on reload
                    }
                });
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>