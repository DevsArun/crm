<?php
// ============================================================
// WhatsApp CRM OS — Authentication & Security
// Handles: session auth, login/logout, webhook verification,
//          CSRF tokens, brute-force lockout
// ============================================================

defined('CRM_APP') or die('Direct access not permitted');

// ── Start session (called once at bootstrap) ─────────────────
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => SESSION_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Regenerate ID periodically to prevent fixation
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - $_SESSION['_created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }
}

// ── Check if user is authenticated ───────────────────────────
function isAuthenticated(): bool
{
    startSecureSession();

    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return false;
    }

    // Session timeout check
    if (!empty($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            destroySession();
            return false;
        }
    }

    $_SESSION['last_activity'] = time();
    return true;
}

// ── Require authentication — redirect to login if not ────────
function requireAuth(): void
{
    if (!isAuthenticated()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard.php');
        header('Location: /login.php?redirect=' . $redirect);
        exit;
    }
}

// ── Require auth for API — return JSON 401 if not ────────────
function requireApiAuth(): void
{
    if (!isAuthenticated()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

// ── Attempt login ─────────────────────────────────────────────
function attemptLogin(string $username, string $password): array
{
    startSecureSession();

    $ip       = getClientIp();
    $lockKey  = 'login_fail_' . md5($ip);

    // Brute-force check
    $attempts = (int)($_SESSION[$lockKey . '_count'] ?? 0);
    $lockTime = (int)($_SESSION[$lockKey . '_time']  ?? 0);

    if ($attempts >= MAX_LOGIN_ATTEMPTS && (time() - $lockTime) < LOGIN_LOCKOUT_TIME) {
        $remaining = LOGIN_LOCKOUT_TIME - (time() - $lockTime);
        return [
            'success' => false,
            'error'   => "Too many failed attempts. Try again in {$remaining} seconds.",
        ];
    }

    // Fetch credentials from settings
    $storedUser = setting('admin_username', 'admin');
    $storedHash = setting('admin_password', '');

    // Validate username
    if (!hash_equals($storedUser, $username)) {
        return _loginFailed($lockKey);
    }

    // Validate password (bcrypt)
    if (!password_verify($password, $storedHash)) {
        return _loginFailed($lockKey);
    }

    // Success — reset lockout, set session
    unset(
        $_SESSION[$lockKey . '_count'],
        $_SESSION[$lockKey . '_time']
    );

    session_regenerate_id(true);

    $_SESSION['authenticated']  = true;
    $_SESSION['username']       = $username;
    $_SESSION['last_activity']  = time();
    $_SESSION['_created']       = time();
    $_SESSION['login_ip']       = $ip;

    crmLog('info', 'auth', "Login successful for user: {$username}", ['ip' => $ip]);

    return ['success' => true];
}

// ── Internal: record failed login attempt ────────────────────
function _loginFailed(string $lockKey): array
{
    $_SESSION[$lockKey . '_count'] = ($_SESSION[$lockKey . '_count'] ?? 0) + 1;
    $_SESSION[$lockKey . '_time']  = time();

    $remaining = MAX_LOGIN_ATTEMPTS - $_SESSION[$lockKey . '_count'];
    $msg = $remaining > 0
        ? "Invalid credentials. {$remaining} attempts remaining."
        : 'Account temporarily locked due to too many failed attempts.';

    return ['success' => false, 'error' => $msg];
}

// ── Destroy session (logout) ──────────────────────────────────
function destroySession(): void
{
    startSecureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// ── Generate CSRF token ───────────────────────────────────────
function generateCsrfToken(): string
{
    startSecureSession();

    if (
        empty($_SESSION['csrf_token']) ||
        empty($_SESSION['csrf_token_time']) ||
        (time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_TTL
    ) {
        $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }

    return $_SESSION['csrf_token'];
}

// ── Validate CSRF token ───────────────────────────────────────
function validateCsrfToken(string $token): bool
{
    startSecureSession();

    if (empty($_SESSION['csrf_token'])) return false;
    if (empty($_SESSION['csrf_token_time'])) return false;
    if ((time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_TTL) return false;

    return hash_equals($_SESSION['csrf_token'], $token);
}

// ── Verify incoming webhook from HF Node ─────────────────────
/**
 * Validates X-Webhook-Signature header using HMAC-SHA256
 * Must be called in webhook.php before processing payload
 */
function verifyWebhookSignature(string $rawBody): bool
{
    $secret = WEBHOOK_SECRET;

    // If no secret configured, skip verification (dev mode)
    if (empty($secret)) {
        error_log('[CRM] Warning: WEBHOOK_SECRET not set — skipping signature check');
        return true;
    }

    $signatureHeader = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

    if (empty($signatureHeader)) {
        crmLog('warning', 'webhook', 'Missing X-Webhook-Signature header');
        return false;
    }

    // Header format: "sha256=<hex>"
    if (!str_starts_with($signatureHeader, 'sha256=')) {
        crmLog('warning', 'webhook', 'Invalid signature format');
        return false;
    }

    $providedHmac  = substr($signatureHeader, 7);
    $expectedHmac  = hash_hmac('sha256', $rawBody, $secret);

    if (!hash_equals($expectedHmac, $providedHmac)) {
        crmLog('warning', 'webhook', 'Signature mismatch', [
            'provided' => $providedHmac,
            'expected' => $expectedHmac,
        ]);
        return false;
    }

    return true;
}

// ── Verify webhook IP whitelist ───────────────────────────────
function verifyWebhookIp(): bool
{
    $whitelist = WEBHOOK_IP_WHITELIST;

    if (empty(trim($whitelist))) return true; // Allow all

    $allowedIps = array_map('trim', explode(',', $whitelist));
    $clientIp   = getClientIp();

    return in_array($clientIp, $allowedIps, true);
}

// ── Get real client IP ────────────────────────────────────────
function getClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',    // Cloudflare
        'HTTP_X_REAL_IP',           // Nginx proxy
        'HTTP_X_FORWARDED_FOR',     // Load balancer
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── Output CSRF hidden input field ────────────────────────────
function csrfField(): string
{
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}
