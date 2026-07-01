<?php
// ============================================================
// Google OAuth Login Handler
// Redirects user to Google for authentication
// ============================================================

session_start();
include "config.php";

// Build Google OAuth URL
$google_auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'email profile',
    'access_type' => 'online',
    'prompt' => 'select_account'
]);

// Redirect to Google
header("Location: " . $google_auth_url);
exit;
?>