<?php
// ============================================================
// Cleanup Expired Sessions
// Run this via cron job or manually
// ============================================================

include "db.php";

// Clean expired session tokens
$stmt = $conn->prepare("DELETE FROM session_tokens WHERE expires_at < NOW()");
$stmt->execute();

echo "Cleaned " . $stmt->affected_rows . " expired sessions.\n";

// Also clean expired OTPs (optional)
$stmt2 = $conn->prepare("UPDATE users SET otp = NULL, otp_expires_at = NULL WHERE otp_expires_at < NOW()");
$stmt2->execute();

echo "Cleaned " . $stmt2->affected_rows . " expired OTPs.\n";
?>