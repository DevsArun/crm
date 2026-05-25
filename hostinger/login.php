<?php
// ============================================================
// login.php — Premium login page
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

// Already logged in?
if (isAuthenticated()) {
    header('Location: /dashboard.php');
    exit;
}

$error      = '';
$loggedOut  = isset($_GET['logged_out']);
$redirect   = sanitizeString($_GET['redirect'] ?? '/dashboard.php', 200);

// ── Handle POST login ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeString($_POST['username'] ?? '', 100);
    $password = $_POST['password'] ?? '';
    $csrf     = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($csrf)) {
        $error = 'Invalid request. Please try again.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } else {
        $result = attemptLogin($username, $password);
        if ($result['success']) {
            // Validate redirect URL (must be local)
            if (!preg_match('/^\/[a-zA-Z0-9_\-\.\/]*$/', $redirect)) {
                $redirect = '/dashboard.php';
            }
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$csrfToken = generateCsrfToken();
$appName   = setting('app_name', APP_NAME);
$tagline   = setting('app_tagline', APP_TAGLINE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — <?= htmlspecialchars($appName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <style>
    body { overflow: auto; height: auto; min-height: 100vh; }
  </style>
</head>
<body>

<div class="login-page">
  <div class="login-card">

    <!-- Logo -->
    <div class="login-logo">
      <div class="login-logo-icon">⚡</div>
      <div class="login-title"><?= htmlspecialchars($appName) ?></div>
      <div class="login-sub"><?= htmlspecialchars($tagline) ?></div>
    </div>

    <!-- Logged out message -->
    <?php if ($loggedOut): ?>
    <div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#34d399;text-align:center">
      You have been signed out successfully.
    </div>
    <?php endif; ?>

    <!-- Error message -->
    <?php if ($error): ?>
    <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#f87171;text-align:center">
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Login form -->
    <form method="POST" action="/login.php<?= $redirect !== '/dashboard.php' ? '?redirect=' . urlencode($redirect) : '' ?>" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <input
          class="form-input"
          type="text"
          id="username"
          name="username"
          placeholder="admin"
          autocomplete="username"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
          required
          autofocus
        >
      </div>

      <div class="form-group" style="margin-bottom:20px">
        <label class="form-label" for="password">Password</label>
        <div style="position:relative">
          <input
            class="form-input"
            type="password"
            id="password"
            name="password"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
            style="padding-right:42px"
          >
          <button type="button" onclick="togglePassword()" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:14px" title="Show/hide password">
            <span id="pwd-eye">👁</span>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;padding:11px;font-size:14px;font-weight:700;letter-spacing:0.01em">
        Sign In to Dashboard
      </button>
    </form>

    <!-- Footer -->
    <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border);text-align:center">
      <p style="font-size:11px;color:var(--text-disabled)">
        WhatsApp CRM + Cold Outreach OS &nbsp;·&nbsp; v<?= APP_VERSION ?>
      </p>
    </div>

  </div>
</div>

<div id="toast-container"></div>

<script>
function togglePassword() {
  const input = document.getElementById('password');
  const eye   = document.getElementById('pwd-eye');
  if (input.type === 'password') {
    input.type = 'text';
    eye.textContent = '🙈';
  } else {
    input.type = 'password';
    eye.textContent = '👁';
  }
}

// Autofocus first empty field
document.addEventListener('DOMContentLoaded', () => {
  const username = document.getElementById('username');
  const password = document.getElementById('password');
  if (!username.value) username.focus();
  else password.focus();
});
</script>
</body>
</html>
