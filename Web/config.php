<?php
// ============================================================
// JisanTV - Configuration File
// ============================================================

// ---------- ADMIN SETTINGS ----------
define('ADMIN_PASSWORD', 'xxxxxxxx');

// ---------- CHANNEL SOURCES ----------
// Free channels M3U URL
define('FREE_M3U_URL', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
// Premium channels M3U URL
define('PREMIUM_M3U_URL', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

// ---------- PRICING (in BDT) ----------
define('PRICE_1_MONTH', 150);
define('PRICE_3_MONTH', 300);
define('PRICE_6_MONTH', 500);

// ---------- DEVICE LIMITS ----------
// Maximum number of devices per user
define('MAX_DEVICES', 3);

// ---------- EMAIL CONFIGURATION ----------
// SMTP settings for sending OTP emails
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'jisanhossain0077@gmail.com');
define('SMTP_PASS', 'oria myqr nipa fwui');
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('SMTP_FROM_NAME', 'JisanTV');

// ---------- PAYMENT ----------
// bKash merchant number
define('BKASH_NUMBER', '01XXXXXXXXX');

// ---------- SECURITY ----------
// OTP expiry time in minutes
define('OTP_EXPIRY_MINUTES', 5);

// Session cookie name (for Remember Me)
define('SESSION_COOKIE_NAME', 'jisantv_session');
define('SESSION_LIFETIME', 2592000); // 30 days

// ---------- GOOGLE OAUTH ----------
// Google OAuth credentials
define('GOOGLE_CLIENT_ID', 'xxxxxxxxxxxxxxxxxxxxxxxx');
define('GOOGLE_CLIENT_SECRET', 'xxxxxxxxxxxxxxxxxxxx');
define('GOOGLE_REDIRECT_URI', 'https://jisanhsajin.gt.tc/google_callback.php');
?>