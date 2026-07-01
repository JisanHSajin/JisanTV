<?php
// ============================================================
// VIDEO PLAYER - Supports HLS, DASH, TS, and Native streams
// ============================================================
// Free channels can be played without login
// Premium channels require login + subscription check
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
$premium_active = false;

$session_manager = new SessionManager($conn);
if ($session_manager->isLoggedIn()) {
    $is_logged_in = true;
    $user_id = $_SESSION['user_id'];
    
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
        }
    } else {
        session_destroy();
        $is_logged_in = false;
    }
    
    // ============================================================
    // 3. VALIDATE DEVICE (only for logged-in users)
    // ============================================================
    
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
    
    // ============================================================
    // 4. CHECK SUBSCRIPTION (only for logged-in users)
    // ============================================================
    
    if ($is_logged_in) {
        $stmt = $conn->prepare("SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' AND expires_at >= CURDATE()");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $premium_active = ($stmt->get_result()->num_rows > 0);
        $stmt->close();
    }
}

// ============================================================
// 5. GET STREAM URL AND CHANNEL NAME
// ============================================================

$url = isset($_GET['url']) ? urldecode($_GET['url']) : '';
$channel_name = isset($_GET['name']) ? urldecode($_GET['name']) : 'Select a channel';

/**
 * Detect stream type from URL
 */
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
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <!-- Load HLS.js and DASH.js from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/dashjs@latest"></script>
    
    <!-- ============================================================
    STYLES - Full Size Player
    ============================================================ -->
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
        
        .loading-overlay{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.85);padding:15px 30px;border-radius:12px;color:#00ffff;display:flex;z-index:100;text-align:center;border:1px solid #00ffff33;flex-direction:column;align-items:center;}
        .loading-overlay .spinner{display:inline-block;width:30px;height:30px;border:3px solid #00ffff33;border-top-color:#00ffff;border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:8px;}
        @keyframes spin{to{transform:rotate(360deg);}}
        .loading-overlay .loading-text{font-size:14px;}
        .loading-overlay .loading-type{font-size:11px;color:#aaa;margin-top:4px;}
        
        .error-overlay{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.9);padding:20px 30px;border-radius:12px;color:#ff4444;display:none;z-index:100;text-align:center;border:1px solid #ff444444;max-width:90%;}
        .error-overlay .error-icon{font-size:40px;display:block;margin-bottom:10px;}
        .retry-btn{background:#00ffff;color:#000;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:bold;margin-top:10px;}
        .retry-btn:hover{background:#00ddff;}
        
        .login-overlay{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.95);padding:30px;border-radius:12px;color:#ff6600;display:none;z-index:100;text-align:center;border:1px solid #ff660044;max-width:90%;}
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
            .loading-overlay{padding:12px 20px;}
            .login-overlay{padding:20px;}
            .login-overlay .login-title{font-size:17px;}
        }
    </style>
</head>
<body>
<div class="player-wrapper">
    <!-- Video Element -->
    <video id="video" controls crossorigin="anonymous" playsinline webkit-playsinline autoplay></video>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <div class="loading-text" id="loadingText">Loading stream...</div>
        <div class="loading-type" id="loadingType">Connecting...</div>
    </div>
    
    <!-- Error Overlay -->
    <div class="error-overlay" id="errorOverlay">
        <span class="error-icon">❌</span>
        <div class="error-title" id="errorTitle">Playback Error</div>
        <div class="error-message" id="errorMessage">Failed to load the stream</div>
        <button class="retry-btn" onclick="retryPlayback()">🔄 Retry</button>
    </div>
    
    <!-- Login Required Overlay -->
    <div class="login-overlay" id="loginOverlay">
        <span class="lock-icon">🔒</span>
        <div class="login-title">Premium Channel</div>
        <div class="login-message">Please login to watch premium content</div>
        <a href="login.php" class="login-btn">Login Now</a>
    </div>
    
    <!-- Player Info Bar -->
    <div class="player-info">
        <div class="channel-name" id="channelName"><?php echo htmlspecialchars($channel_name); ?></div>
        <span class="stream-badge <?php echo $stream_type; ?>" id="streamBadge"><?php echo strtoupper($stream_type); ?></span>
    </div>
</div>

<!-- ============================================================
JAVASCRIPT - FAST LOADING PLAYER
============================================================ -->
<script>
// ============================================================
// 1. CONFIGURATION
// ============================================================

const streamUrl = decodeURIComponent('<?php echo addslashes($url); ?>');
const channelName = decodeURIComponent('<?php echo addslashes($channel_name); ?>');
const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
const premiumActive = <?php echo $premium_active ? 'true' : 'false'; ?>;

// ============================================================
// 2. PLAYER VARIABLES
// ============================================================

let hls = null;
let dashPlayer = null;
let retryCount = 0;
const MAX_RETRIES = 2;

const video = document.getElementById('video');
const loadingOverlay = document.getElementById('loadingOverlay');
const loadingText = document.getElementById('loadingText');
const loadingType = document.getElementById('loadingType');
const channelNameEl = document.getElementById('channelName');
const errorOverlay = document.getElementById('errorOverlay');
const errorTitle = document.getElementById('errorTitle');
const errorMessage = document.getElementById('errorMessage');
const loginOverlay = document.getElementById('loginOverlay');

// ============================================================
// 3. HELPER FUNCTIONS
// ============================================================

function showLoading(show, text = 'Loading stream...', type = 'Connecting...') {
    loadingOverlay.style.display = show ? 'flex' : 'none';
    if (show) {
        loadingText.textContent = text;
        loadingType.textContent = type;
    }
    errorOverlay.style.display = 'none';
    loginOverlay.style.display = 'none';
}

function showError(title, message) {
    errorTitle.textContent = title;
    errorMessage.textContent = message;
    errorOverlay.style.display = 'block';
    loadingOverlay.style.display = 'none';
    loginOverlay.style.display = 'none';
}

function showLoginRequired() {
    loginOverlay.style.display = 'block';
    loadingOverlay.style.display = 'none';
    errorOverlay.style.display = 'none';
    video.style.display = 'none';
}

function retryPlayback() {
    retryCount = 0;
    errorOverlay.style.display = 'none';
    initPlayer(streamUrl);
}

function destroyAllPlayers() {
    if (hls) {
        try { hls.destroy(); } catch(e) {}
        hls = null;
    }
    if (dashPlayer) {
        try { dashPlayer.destroy(); } catch(e) {}
        dashPlayer = null;
    }
    try {
        video.pause();
        video.removeAttribute('src');
        video.load();
        video.style.display = 'block';
    } catch(e) {}
}

function getStreamType(url) {
    const u = url.toLowerCase();
    if (u.includes('.m3u8') || u.includes('m3u8')) return 'hls';
    if (u.includes('.mpd') || u.includes('dash')) return 'dash';
    if (u.includes('.ts')) return 'ts';
    return 'native';
}

// ============================================================
// 4. PLAYER INITIALIZERS
// ============================================================

function initHLSPlayer(url) {
    return new Promise((resolve, reject) => {
        if (!Hls.isSupported()) {
            video.src = url;
            video.load();
            resolve();
            return;
        }
        
        hls = new Hls({
            debug: false,
            enableWorker: true,
            lowLatencyMode: true,
            maxBufferLength: 10,
            maxMaxBufferLength: 30,
            liveDurationInfinity: true,
            liveSyncDuration: 3,
            liveMaxLatencyDuration: 10,
            enableWebVTT: false,
            backbufferLength: 10,
            manifestLoadingTimeOut: 5000,
            manifestLoadingMaxRetry: 2,
            levelLoadingTimeOut: 5000,
            fragLoadingTimeOut: 8000,
        });
        
        hls.loadSource(url);
        hls.attachMedia(video);
        
        let resolved = false;
        
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            if (!resolved) {
                resolved = true;
                resolve();
            }
        });
        
        hls.on(Hls.Events.LEVEL_LOADED, () => {
            if (!resolved) {
                resolved = true;
                resolve();
            }
        });
        
        hls.on(Hls.Events.ERROR, (event, data) => {
            if (data.fatal) {
                if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                    hls.startLoad();
                } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                    try { hls.recoverMediaError(); } catch(e) {}
                } else {
                    if (!resolved) {
                        resolved = true;
                        reject(new Error(data.details || 'HLS error'));
                    }
                }
            }
        });
        
        setTimeout(() => {
            if (!resolved) {
                resolved = true;
                try {
                    if (hls) { hls.destroy(); hls = null; }
                } catch(e) {}
                video.src = url;
                video.load();
                resolve();
            }
        }, 8000);
    });
}

function initDASHPlayer(url) {
    return new Promise((resolve, reject) => {
        if (typeof dashjs === 'undefined') {
            video.src = url;
            video.load();
            resolve();
            return;
        }
        
        dashPlayer = dashjs.MediaPlayer().create();
        dashPlayer.initialize(video, url, true);
        
        let resolved = false;
        
        dashPlayer.on('playbackStarted', () => {
            if (!resolved) {
                resolved = true;
                resolve();
            }
        });
        
        dashPlayer.on('playbackReady', () => {
            if (!resolved) {
                resolved = true;
                resolve();
            }
        });
        
        dashPlayer.on('error', (e) => {
            if (!resolved) {
                resolved = true;
                reject(new Error(e.error || 'DASH playback error'));
            }
        });
        
        setTimeout(() => {
            if (!resolved) {
                resolved = true;
                reject(new Error('DASH stream timeout'));
            }
        }, 10000);
    });
}

function initNativePlayer(url) {
    return new Promise((resolve) => {
        video.src = url;
        video.load();
        resolve();
    });
}

// ============================================================
// 5. MAIN PLAYER INITIALIZATION
// ============================================================

function initPlayer(url) {
    if (!url || url === '') {
        showLoading(false);
        channelNameEl.textContent = 'No stream selected';
        video.style.display = 'none';
        return;
    }
    
    channelNameEl.textContent = channelName;
    
    const urlLower = url.toLowerCase();
    const isPremiumStream = urlLower.includes('premium') || urlLower.includes('premium_channel');
    
    if (isPremiumStream && !isLoggedIn) {
        showLoginRequired();
        return;
    }
    
    if (isPremiumStream && !premiumActive) {
        showLoginRequired();
        return;
    }
    
    destroyAllPlayers();
    
    const streamType = getStreamType(url);
    const typeNames = {
        'hls': 'HLS',
        'dash': 'DASH',
        'ts': 'TS',
        'native': 'Native'
    };
    showLoading(true, 'Loading ' + typeNames[streamType] + ' stream...', 'Connecting to server...');
    
    document.getElementById('streamBadge').textContent = streamType.toUpperCase();
    document.getElementById('streamBadge').className = 'stream-badge ' + streamType;
    
    let playerPromise;
    switch(streamType) {
        case 'hls':
            playerPromise = initHLSPlayer(url);
            break;
        case 'dash':
            playerPromise = initDASHPlayer(url);
            break;
        default:
            playerPromise = initNativePlayer(url);
    }
    
    playerPromise
        .then(() => {
            showLoading(false);
            video.play().catch(() => {});
            retryCount = 0;
        })
        .catch((error) => {
            console.warn('Player error:', error);
            if (retryCount < MAX_RETRIES) {
                retryCount++;
                setTimeout(() => {
                    showLoading(true, 'Retrying... (' + retryCount + '/' + MAX_RETRIES + ')', 'Attempting to recover...');
                    initPlayer(url);
                }, 1500);
            } else {
                showError('Playback Failed', error.message || 'Unable to play this stream.');
            }
        });
}

// ============================================================
// 6. START PLAYER
// ============================================================

showLoading(true, 'Starting player...', 'Initializing...');

if (streamUrl && streamUrl !== '') {
    setTimeout(function() {
        initPlayer(streamUrl);
    }, 100);
} else {
    showLoading(false);
    channelNameEl.textContent = 'No stream selected';
    video.style.display = 'none';
}

// ============================================================
// 7. EVENT LISTENERS
// ============================================================

document.addEventListener('keydown', function(e) {
    if (e.key === 'f' || e.key === 'F') {
        e.preventDefault();
        if (video.requestFullscreen) {
            video.requestFullscreen().catch(() => {});
        }
    }
    if (e.key === ' ' || e.key === 'Space') {
        e.preventDefault();
        if (video.paused) video.play(); else video.pause();
    }
});

video.addEventListener('canplay', function() {
    showLoading(false);
});

video.addEventListener('playing', function() {
    showLoading(false);
});

video.addEventListener('error', function(e) {
    if (errorOverlay.style.display === 'none' && loadingOverlay.style.display === 'none') {
        if (retryCount < MAX_RETRIES) {
            retryCount++;
            setTimeout(() => {
                showLoading(true, 'Recovering... (' + retryCount + '/' + MAX_RETRIES + ')', 'Attempting to recover...');
                initPlayer(streamUrl);
            }, 1500);
        } else {
            showError('Video Error', 'The stream encountered an error.');
        }
    }
});

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        if (!video.paused) {
            video.pause();
        }
    }
});

// ============================================================
// 8. SECURITY
// ============================================================

(function() {
    var isMobile = /android|iphone|ipad|ipod|mobile/i.test(navigator.userAgent);
    
    if (!isMobile) {
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F12') { e.preventDefault(); return false; }
            if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'J' || e.key === 'C')) {
                e.preventDefault();
                return false;
            }
            if (e.ctrlKey && (e.key === 'u' || e.key === 'U')) {
                e.preventDefault();
                return false;
            }
        });
    }
})();

console.log('JisanTV Player initialized successfully');
</script>
</body>
</html>