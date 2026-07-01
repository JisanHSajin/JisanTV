<?php
// ============================================================
// VIDEO PLAYER - Fast Loading with Instant Playback
// ============================================================
// Optimized for speed - no black screen
// ============================================================

session_start();
include "db.php";
include "config.php";
include "session_helper.php";
include "device_helper.php";

// ============================================================
// 1. CHECK LOGIN STATUS (Fast)
// ============================================================

$is_logged_in = false;
$user_id = 0;
$premium_active = false;

$session_manager = new SessionManager($conn);
if ($session_manager->isLoggedIn()) {
    $is_logged_in = true;
    $user_id = $_SESSION['user_id'];
    
    // Quick password check
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (!isset($_SESSION['password_hash']) || $_SESSION['password_hash'] !== $row['password']) {
            session_destroy();
            $is_logged_in = false;
        }
    } else {
        session_destroy();
        $is_logged_in = false;
    }
    
    // Device check (quick)
    if ($is_logged_in) {
        $device_manager = new DeviceManager($conn);
        $device_id = $device_manager->getDeviceFingerprint();
        $device_check = $conn->prepare("SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1");
        $device_check->bind_param("is", $user_id, $device_id);
        $device_check->execute();
        if ($device_check->get_result()->num_rows == 0) {
            session_destroy();
            $is_logged_in = false;
        }
    }
    
    // Subscription check
    if ($is_logged_in) {
        $stmt = $conn->prepare("SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' AND expires_at >= CURDATE()");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $premium_active = ($stmt->get_result()->num_rows > 0);
        $stmt->close();
    }
}

// ============================================================
// 2. GET STREAM URL
// ============================================================

$url = isset($_GET['url']) ? urldecode($_GET['url']) : '';
$channel_name = isset($_GET['name']) ? urldecode($_GET['name']) : 'Select a channel';

// Detect stream type
function getStreamType($url) {
    $url_lower = strtolower($url);
    if (strpos($url_lower, '.m3u8') !== false || strpos($url_lower, 'm3u8') !== false) return 'hls';
    if (strpos($url_lower, '.mpd') !== false || strpos($url_lower, 'dash') !== false) return 'dash';
    if (strpos($url_lower, '.ts') !== false) return 'ts';
    return 'native';
}

$stream_type = getStreamType($url);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Player - JisanTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <!-- Load HLS.js and DASH.js from CDN with async -->
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest" async></script>
    <script src="https://cdn.jsdelivr.net/npm/dashjs@latest" async></script>
    
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#000;color:white;height:100vh;overflow:hidden;display:flex;flex-direction:column;}
        .player-wrapper{flex:1;display:flex;flex-direction:column;background:#000;position:relative;min-height:0;}
        video{width:100%;flex:1;min-height:0;object-fit:contain;background:#000;display:block;}
        
        .player-info{background:#1a1a1a;padding:8px 12px;text-align:center;border-top:1px solid #00ffff33;flex-shrink:0;min-height:40px;display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:8px;}
        .player-info .channel-name{color:#00ffff;font-weight:bold;font-size:14px;max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .player-info .stream-badge{background:#ff6600;color:#fff;font-size:10px;padding:2px 10px;border-radius:12px;font-weight:bold;text-transform:uppercase;}
        .player-info .stream-badge.hls{background:#ff4444;}
        .player-info .stream-badge.dash{background:#4444ff;}
        .player-info .stream-badge.ts{background:#ff8800;}
        .player-info .stream-badge.native{background:#44bb44;}
        
        /* Minimal loading indicator - non-blocking */
        .loading-indicator{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.7);padding:10px 20px;border-radius:8px;color:#00ffff;display:none;z-index:10;font-size:13px;pointer-events:none;}
        .loading-indicator .spinner{display:inline-block;width:20px;height:20px;border:2px solid #00ffff33;border-top-color:#00ffff;border-radius:50%;animation:spin 0.6s linear infinite;margin-right:10px;vertical-align:middle;}
        @keyframes spin{to{transform:rotate(360deg);}}
        
        .login-overlay{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.92);padding:30px;border-radius:12px;color:#ff6600;display:none;z-index:20;text-align:center;border:1px solid #ff660044;max-width:90%;}
        .login-overlay .lock-icon{font-size:50px;display:block;margin-bottom:10px;}
        .login-overlay .login-title{font-size:20px;font-weight:bold;margin-bottom:5px;}
        .login-overlay .login-message{font-size:14px;color:#ccc;margin-bottom:15px;}
        .login-overlay .login-btn{background:#00ffff;color:#000;border:none;padding:10px 30px;border-radius:6px;cursor:pointer;font-weight:bold;text-decoration:none;display:inline-block;font-size:16px;}
        .login-overlay .login-btn:hover{background:#00ddff;}
        
        @media(max-width:768px){
            .player-info .channel-name{font-size:12px;max-width:60%;}
            .player-info{padding:6px 10px;min-height:34px;}
        }
        @media(max-width:480px){
            .player-info .channel-name{font-size:11px;max-width:50%;}
            .player-info{padding:4px 8px;min-height:30px;}
            .login-overlay{padding:20px;}
            .login-overlay .login-title{font-size:17px;}
        }
    </style>
</head>
<body>
<div class="player-wrapper">
    <!-- Video Element - Starts playing immediately -->
    <video id="video" controls crossorigin="anonymous" playsinline webkit-playsinline autoplay></video>
    
    <!-- Minimal Loading Indicator -->
    <div class="loading-indicator" id="loadingIndicator">
        <span class="spinner"></span>
        <span id="loadingText">Loading...</span>
    </div>
    
    <!-- Login Required -->
    <div class="login-overlay" id="loginOverlay">
        <span class="lock-icon">🔒</span>
        <div class="login-title">Premium Channel</div>
        <div class="login-message">Please login to watch premium content</div>
        <a href="login.php" class="login-btn">Login Now</a>
    </div>
    
    <!-- Player Info -->
    <div class="player-info">
        <div class="channel-name" id="channelName"><?php echo htmlspecialchars($channel_name); ?></div>
        <span class="stream-badge <?php echo $stream_type; ?>" id="streamBadge"><?php echo strtoupper($stream_type); ?></span>
    </div>
</div>

<!-- ============================================================
JAVASCRIPT - OPTIMIZED FOR SPEED
============================================================ -->
<script>
// ============================================================
// 1. CONFIGURATION
// ============================================================

const streamUrl = decodeURIComponent('<?php echo addslashes($url); ?>');
const channelName = decodeURIComponent('<?php echo addslashes($channel_name); ?>');
const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
const premiumActive = <?php echo $premium_active ? 'true' : 'false'; ?>;
const streamType = '<?php echo $stream_type; ?>';

// ============================================================
// 2. DOM REFS
// ============================================================

const video = document.getElementById('video');
const channelNameEl = document.getElementById('channelName');
const loadingIndicator = document.getElementById('loadingIndicator');
const loadingText = document.getElementById('loadingText');
const loginOverlay = document.getElementById('loginOverlay');

let hls = null;
let dashPlayer = null;
let started = false;

// ============================================================
// 3. HELPER FUNCTIONS
// ============================================================

function showLoading(show, text = 'Loading...') {
    if (show) {
        loadingIndicator.style.display = 'block';
        loadingText.textContent = text;
    } else {
        loadingIndicator.style.display = 'none';
    }
}

function showLoginRequired() {
    loginOverlay.style.display = 'block';
    video.style.display = 'none';
}

// ============================================================
// 4. PLAYER INITIALIZATION - IMMEDIATE
// ============================================================

function initPlayer(url) {
    // Check if premium
    const isPremium = url.toLowerCase().includes('premium') || url.toLowerCase().includes('premium_channel');
    
    if (isPremium && !isLoggedIn) {
        showLoginRequired();
        return;
    }
    
    if (isPremium && !premiumActive) {
        showLoginRequired();
        return;
    }
    
    // Show loading briefly
    showLoading(true, 'Starting...');
    
    // Destroy old players
    if (hls) {
        try { hls.destroy(); } catch(e) {}
        hls = null;
    }
    if (dashPlayer) {
        try { dashPlayer.destroy(); } catch(e) {}
        dashPlayer = null;
    }
    
    // Clear video
    try {
        video.pause();
        video.removeAttribute('src');
        video.load();
        video.style.display = 'block';
    } catch(e) {}
    
    // Start playback immediately based on type
    switch(streamType) {
        case 'hls':
            initHLS(url);
            break;
        case 'dash':
            initDASH(url);
            break;
        default:
            initNative(url);
    }
}

// ============================================================
// 5. HLS PLAYER - FAST
// ============================================================

function initHLS(url) {
    if (typeof Hls !== 'undefined' && Hls.isSupported()) {
        hls = new Hls({
            debug: false,
            enableWorker: true,
            lowLatencyMode: true,
            maxBufferLength: 5,
            liveSyncDuration: 2,
            manifestLoadingTimeOut: 3000,
            fragLoadingTimeOut: 5000,
        });
        
        hls.loadSource(url);
        hls.attachMedia(video);
        
        hls.on(Hls.Events.MANIFEST_PARSED, function() {
            showLoading(false);
            video.play().catch(function() {});
        });
        
        hls.on(Hls.Events.LEVEL_LOADED, function() {
            showLoading(false);
        });
        
        // Fallback after timeout
        setTimeout(function() {
            if (!started) {
                showLoading(false);
                video.src = url;
                video.load();
                video.play().catch(function() {});
            }
        }, 5000);
        
    } else {
        // Native fallback
        video.src = url;
        video.load();
        video.play().catch(function() {});
        showLoading(false);
    }
}

// ============================================================
// 6. DASH PLAYER - FAST
// ============================================================

function initDASH(url) {
    if (typeof dashjs !== 'undefined') {
        dashPlayer = dashjs.MediaPlayer().create();
        dashPlayer.initialize(video, url, true);
        
        dashPlayer.on('playbackStarted', function() {
            showLoading(false);
            started = true;
        });
        
        dashPlayer.on('playbackReady', function() {
            showLoading(false);
            started = true;
        });
        
        setTimeout(function() {
            if (!started) {
                showLoading(false);
                video.src = url;
                video.load();
                video.play().catch(function() {});
            }
        }, 5000);
        
    } else {
        video.src = url;
        video.load();
        video.play().catch(function() {});
        showLoading(false);
    }
}

// ============================================================
// 7. NATIVE PLAYER - INSTANT
// ============================================================

function initNative(url) {
    video.src = url;
    video.load();
    video.play().catch(function() {});
    showLoading(false);
    
    // Hide loading after a moment
    setTimeout(function() {
        showLoading(false);
    }, 500);
}

// ============================================================
// 8. START PLAYER - IMMEDIATE
// ============================================================

// Set channel name immediately
channelNameEl.textContent = channelName;

// Start if URL exists
if (streamUrl && streamUrl !== '') {
    // Small delay to let DOM render
    setTimeout(function() {
        initPlayer(streamUrl);
    }, 50);
} else {
    showLoading(false);
    video.style.display = 'none';
    channelNameEl.textContent = 'No stream selected';
}

// ============================================================
// 9. EVENT LISTENERS
// ============================================================

// Hide loading when video starts playing
video.addEventListener('playing', function() {
    showLoading(false);
    started = true;
});

video.addEventListener('canplay', function() {
    showLoading(false);
    started = true;
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'f' || e.key === 'F') {
        e.preventDefault();
        if (video.requestFullscreen) {
            video.requestFullscreen().catch(function() {});
        }
    }
    if (e.key === ' ' || e.key === 'Space') {
        e.preventDefault();
        if (video.paused) {
            video.play().catch(function() {});
        } else {
            video.pause();
        }
    }
});

// ============================================================
// 10. SECURITY (Minimal)
// ============================================================

document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    return false;
});

console.log('JisanTV Player Ready');
</script>
</body>
</html>
