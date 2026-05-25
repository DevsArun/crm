<?php
// ============================================================
// WhatsApp CRM OS — Application Configuration
// Hostinger PHP Backend
// ============================================================

// ── Prevent direct access ────────────────────────────────────
defined('CRM_APP') or define('CRM_APP', true);

// ── Environment detection ─────────────────────────────────────
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', APP_ENV === 'development');

// ── App identity ──────────────────────────────────────────────
define('APP_NAME',    'OutreachOS');
define('APP_TAGLINE', 'WhatsApp CRM + Cold Outreach System');
define('APP_VERSION', '1.0.0');
define('APP_URL',     rtrim(getenv('APP_URL') ?: 'https://yourdomain.com', '/'));

// ── Timezone ──────────────────────────────────────────────────
define('APP_TIMEZONE', 'Asia/Kolkata');
date_default_timezone_set(APP_TIMEZONE);

// ── Database credentials ──────────────────────────────────────
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('DB_NAME')    ?: 'whatsapp_crm');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('DB_PASS')    ?: '');
define('DB_CHARSET', 'utf8mb4');

// ── Hugging Face Node.js Backend ──────────────────────────────
// Base URL of your HF Space (no trailing slash)
define('NODE_API_URL', rtrim(getenv('NODE_API_URL') ?: 'https://your-space.hf.space', '/'));
// API key that Node.js server expects in X-API-Key header
define('NODE_API_KEY', getenv('NODE_API_KEY') ?: '');
// Timeout for requests to Node backend (seconds)
define('NODE_REQUEST_TIMEOUT', 15);

// ── Socket.io server ──────────────────────────────────────────
// Browser connects here directly
define('SOCKET_URL', getenv('SOCKET_URL') ?: 'https://your-space.hf.space');
// Socket auth token (same as NODE_API_KEY recommended)
define('SOCKET_AUTH_TOKEN', getenv('SOCKET_AUTH_TOKEN') ?: NODE_API_KEY);

// ── Webhook ───────────────────────────────────────────────────
// Secret to verify incoming webhook requests from Node backend
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: '');
// Allow webhook from any IP if empty (lock down in production)
define('WEBHOOK_IP_WHITELIST', getenv('WEBHOOK_IP_WHITELIST') ?: '');

// ── Groq AI ───────────────────────────────────────────────────
define('GROQ_API_KEY',      getenv('GROQ_API_KEY')  ?: '');
define('GROQ_API_URL',      'https://api.groq.com/openai/v1/chat/completions');
define('GROQ_MODEL',        getenv('GROQ_MODEL')    ?: 'llama3-70b-8192');
define('GROQ_MAX_TOKENS',   600);
define('GROQ_TEMPERATURE',  0.85);
define('GROQ_TIMEOUT',      30); // seconds

// ── Campaign defaults ─────────────────────────────────────────
define('CAMPAIGN_DELAY_MIN',  120);   // seconds
define('CAMPAIGN_DELAY_MAX',  300);   // seconds
define('CAMPAIGN_DAILY_LIMIT', 50);   // messages per day
define('CAMPAIGN_RETRY_LIMIT', 3);    // max retry attempts
define('CAMPAIGN_BATCH_SIZE',  10);   // leads per cron run

// ── Session ───────────────────────────────────────────────────
define('SESSION_NAME',    'crm_session');
define('SESSION_TIMEOUT', 3600); // seconds (1 hour)
define('SESSION_SECURE',  APP_ENV === 'production');

// ── File uploads ──────────────────────────────────────────────
define('UPLOAD_DIR',       __DIR__ . '/../uploads/');
define('UPLOAD_MAX_SIZE',  10 * 1024 * 1024); // 10 MB
define('UPLOAD_ALLOWED',   ['text/csv', 'application/vnd.ms-excel',
                             'application/csv', 'text/plain']);

// ── Logging ───────────────────────────────────────────────────
define('LOG_DIR',            __DIR__ . '/../logs/');
define('LOG_LEVEL',          APP_DEBUG ? 'debug' : 'info');
define('LOG_RETENTION_DAYS', 30);

// ── Paths ─────────────────────────────────────────────────────
define('ROOT_PATH',     dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('SCRIPTS_PATH',  ROOT_PATH . '/scripts');
define('API_PATH',      ROOT_PATH . '/api');
define('ASSETS_PATH',   ROOT_PATH . '/assets');

// ── Security ──────────────────────────────────────────────────
define('CSRF_TOKEN_TTL', 3600);    // CSRF token lifetime (seconds)
define('MAX_LOGIN_ATTEMPTS', 10);  // Before temporary lockout
define('LOGIN_LOCKOUT_TIME', 120); // seconds (2 min — not 15!)

// ── Feature flags ─────────────────────────────────────────────
define('FEATURE_AI_GENERATION', true);
define('FEATURE_VALIDATION',    true);
define('FEATURE_CAMPAIGN',      true);
define('FEATURE_LOGS',          true);
define('FEATURE_SOUNDS',        true);

// ── Seller profile (used in AI prompt building) ───────────────
define('SELLER_SERVICES', [
    'Landing Pages',
    'Business Websites',
    'eCommerce Websites',
    'Custom Web Apps',
    'AI Agents',
    'Automation Systems',
    'Android Apps',
    'Chrome Extensions',
    'Digital Marketing',
]);

// ── Error reporting (production safe) ────────────────────────
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', LOG_DIR . 'php_errors.log');
}

// ── Ensure required directories exist ────────────────────────
foreach ([LOG_DIR, UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}
