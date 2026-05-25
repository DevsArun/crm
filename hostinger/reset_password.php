<?php
// ============================================================
// EMERGENCY PASSWORD RESET + LOCKOUT CLEAR
// Upload to Hostinger, visit once, then DELETE immediately
// ============================================================

// ── Simple security: only allow from localhost or with secret ─
$SECRET = 'reset123'; // Change this before uploading if you want
if (!isset($_GET['key']) || $_GET['key'] !== $SECRET) {
    die('<h2>Access denied. Add ?key=reset123 to URL</h2>');
}

define('CRM_APP', true);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

$action  = $_GET['action'] ?? 'show';
$newPass = $_GET['pass']   ?? 'admin@123';
$newUser = $_GET['user']   ?? 'admin';

// ── CLEAR LOCKOUT: destroy all PHP sessions ───────────────────
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('crm_session');
    session_start();
}
// Reset lockout counters in current session
foreach ($_SESSION as $key => $val) {
    if (strpos($key, 'login_fail_') !== false) {
        unset($_SESSION[$key]);
    }
}
session_destroy();

// ── UPDATE PASSWORD IN DB ─────────────────────────────────────
$hash     = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 10]);
$updated  = 0;

try {
    // Update username
    db()->execute(
        "UPDATE settings SET setting_value = ? WHERE setting_key = 'admin_username'",
        [$newUser]
    );
    // Update password
    db()->execute(
        "UPDATE settings SET setting_value = ? WHERE setting_key = 'admin_password'",
        [$hash]
    );
    $updated = 1;
} catch (Exception $e) {
    echo '<h2 style="color:red">DB Error: ' . htmlspecialchars($e->getMessage()) . '</h2>';
    exit;
}

// ── Also clear all PHP session files ─────────────────────────
$sessionDir = session_save_path() ?: sys_get_temp_dir();
$cleared    = 0;
if (is_dir($sessionDir)) {
    foreach (glob($sessionDir . '/sess_*') as $file) {
        @unlink($file);
        $cleared++;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Password Reset</title>
<style>
  body { font-family: Arial, sans-serif; max-width: 600px; margin: 60px auto; padding: 20px; background: #0a0a12; color: #f0f0ff; }
  .box { background: #13131f; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 28px; }
  .ok  { color: #34d399; font-size: 18px; font-weight: bold; }
  .info { background: #191926; border-radius: 8px; padding: 14px; margin: 16px 0; font-size: 14px; }
  .warn { color: #fbbf24; font-weight: bold; margin-top: 20px; font-size: 13px; }
  a { color: #818cf8; }
</style>
</head>
<body>
<div class="box">
  <div class="ok">✓ Password Reset Complete!</div>

  <div class="info">
    <b>Username:</b> <?= htmlspecialchars($newUser) ?><br>
    <b>Password:</b> <?= htmlspecialchars($newPass) ?><br>
    <b>Lockout:</b> Cleared (<?= $cleared ?> session files deleted)<br>
    <b>DB Updated:</b> <?= $updated ? 'Yes' : 'No' ?>
  </div>

  <p>
    <a href="/login.php">→ Go to Login Page</a>
  </p>

  <div class="warn">
    ⚠️ DELETE this file from Hostinger immediately after logging in!<br>
    File: <code>reset_password.php</code>
  </div>

  <p style="font-size:12px;color:#5c5c7e;margin-top:16px">
    To set a different password, use:<br>
    <code>?key=reset123&amp;pass=YourNewPassword&amp;user=admin</code>
  </p>
</div>
</body>
</html>
