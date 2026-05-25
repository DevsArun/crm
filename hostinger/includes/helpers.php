<?php
// ============================================================
// WhatsApp CRM OS — Helper Functions
// Sanitization, responses, phone cleaning, logging,
// parsing, formatting, pagination, utility
// ============================================================

defined('CRM_APP') or die('Direct access not permitted');

// ============================================================
// HTTP / JSON RESPONSES
// ============================================================

/**
 * Send JSON success response and exit
 */
function jsonSuccess(array $data = [], int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send JSON error response and exit
 */
function jsonError(string $message, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        ['success' => false, 'error' => $message] + $extra,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Only allow specific HTTP methods — return 405 otherwise
 */
function requireMethod(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        jsonError('Method not allowed', 405);
    }
}

/**
 * Get JSON body from POST request
 */
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ============================================================
// SANITIZATION
// ============================================================

/**
 * Sanitize a string for safe use
 */
function sanitizeString(mixed $val, int $maxLen = 500): string
{
    return mb_substr(trim(strip_tags((string)($val ?? ''))), 0, $maxLen);
}

/**
 * Sanitize integer
 */
function sanitizeInt(mixed $val, int $min = 0, int $max = PHP_INT_MAX): int
{
    $int = (int) filter_var($val, FILTER_SANITIZE_NUMBER_INT);
    return max($min, min($max, $int));
}

/**
 * Sanitize email
 */
function sanitizeEmail(mixed $val): string
{
    return (string) filter_var(trim((string)($val ?? '')), FILTER_SANITIZE_EMAIL);
}

/**
 * Sanitize URL
 */
function sanitizeUrl(mixed $val): string
{
    $url = trim((string)($val ?? ''));
    if (empty($url)) return '';
    if (!preg_match('/^https?:\/\//i', $url)) {
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_SANITIZE_URL) ?: '';
}

/**
 * Sanitize and validate phone number → E.164-like (91XXXXXXXXXX)
 * Handles: +91 prefix, 0 prefix, 10-digit, spaces, dashes
 */
function normalizePhone(mixed $phone): ?string
{
    if (empty($phone)) return null;

    // Remove everything except digits and leading +
    $cleaned = preg_replace('/[^\d+]/', '', (string)$phone);
    $digits  = preg_replace('/\D/', '', $cleaned);

    if (empty($digits)) return null;

    // Handle +91 or 91 prefix (India)
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return $digits; // Already correct: 91XXXXXXXXXX
    }

    // Handle 10-digit Indian number
    if (strlen($digits) === 10 && $digits[0] >= '6') {
        return '91' . $digits;
    }

    // Handle 0XXXXXXXXXX (STD format)
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        return '91' . substr($digits, 1);
    }

    // Accept other valid lengths (international)
    if (strlen($digits) >= 10 && strlen($digits) <= 15) {
        return $digits;
    }

    return null; // Invalid
}

/**
 * Format phone for display: +91 XXXXX XXXXX
 */
function formatPhoneDisplay(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);

    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $local = substr($digits, 2);
        return '+91 ' . substr($local, 0, 5) . ' ' . substr($local, 5);
    }

    return '+' . $digits;
}

// ============================================================
// ADDRESS / LOCALITY PARSING
// ============================================================

/**
 * Extract locality, city, state from a raw address string
 * Uses pattern matching common to Google Maps / Justdial exports
 *
 * @return array ['locality' => '', 'city' => '', 'state' => '']
 */
function parseAddress(string $address): array
{
    $result = ['locality' => '', 'city' => '', 'state' => ''];

    if (empty(trim($address))) return $result;

    // Common Indian state names for detection
    $states = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
        'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka',
        'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram',
        'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu',
        'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        'Delhi', 'Jammu and Kashmir', 'Ladakh',
    ];

    // Detect state
    foreach ($states as $state) {
        if (stripos($address, $state) !== false) {
            $result['state'] = $state;
            break;
        }
    }

    // Split by comma to extract parts
    $parts = array_map('trim', explode(',', $address));
    $parts = array_filter($parts, fn($p) => !empty($p));
    $parts = array_values($parts);

    $count = count($parts);

    if ($count >= 3) {
        // Format: Street, Locality, City, State, PIN
        $result['locality'] = $parts[$count - 3] ?? '';
        $result['city']     = $parts[$count - 2] ?? '';
    } elseif ($count === 2) {
        $result['locality'] = $parts[0];
        $result['city']     = $parts[1];
    } elseif ($count === 1) {
        $result['city'] = $parts[0];
    }

    // Remove PIN codes and state from city
    $result['city']     = preg_replace('/\b\d{6}\b/', '', $result['city']);
    $result['locality'] = preg_replace('/\b\d{6}\b/', '', $result['locality']);

    foreach ($states as $state) {
        $result['city']     = str_ireplace($state, '', $result['city']);
        $result['locality'] = str_ireplace($state, '', $result['locality']);
    }

    $result['city']     = trim($result['city'],     ', ');
    $result['locality'] = trim($result['locality'], ', ');

    return $result;
}

// ============================================================
// WEBSITE DETECTION
// ============================================================

/**
 * Determine if a website URL represents a real website
 * (not just a social profile, phone number, or generic page)
 */
function detectWebsiteStatus(mixed $url): string
{
    if (empty($url) || trim((string)$url) === '') {
        return 'no_website';
    }

    $url = strtolower(trim((string)$url));

    // Social media / aggregator profiles are NOT websites
    $notWebsite = [
        'facebook.com', 'fb.com', 'instagram.com', 'twitter.com',
        'linkedin.com', 'youtube.com', 'justdial.com', 'indiamart.com',
        'sulekha.com', 'tradeindia.com', 'gmb.google', 'maps.google',
        'wa.me', 'whatsapp.com',
    ];

    foreach ($notWebsite as $pattern) {
        if (str_contains($url, $pattern)) {
            return 'no_website';
        }
    }

    return 'has_website';
}

// ============================================================
// LANGUAGE DETECTION (for AI prompt)
// ============================================================

/**
 * Detect preferred language tone based on state/city
 */
function detectLanguagePreference(string $state, string $city = ''): string
{
    $state = strtolower(trim($state));
    $city  = strtolower(trim($city));

    $hinglishStates  = ['bihar', 'jharkhand', 'uttar pradesh', 'uttarakhand', 'delhi', 'haryana', 'rajasthan', 'madhya pradesh'];
    $gujaratiStates  = ['gujarat'];
    $marathiStates   = ['maharashtra'];
    $bengaliStates   = ['west bengal'];
    $tamilStates     = ['tamil nadu'];
    $teluguStates    = ['telangana', 'andhra pradesh'];
    $kannadaStates   = ['karnataka'];
    $malayalamStates = ['kerala'];
    $punjabiStates   = ['punjab'];

    foreach ($hinglishStates as $s) {
        if (str_contains($state, $s)) return 'hinglish';
    }
    foreach ($gujaratiStates as $s) {
        if (str_contains($state, $s)) return 'gujarati_english';
    }
    foreach ($marathiStates as $s) {
        if (str_contains($state, $s)) return 'marathi_english';
    }
    foreach ($bengaliStates as $s) {
        if (str_contains($state, $s)) return 'bengali_english';
    }
    foreach ($tamilStates as $s) {
        if (str_contains($state, $s)) return 'tamil_english';
    }
    foreach ($teluguStates as $s) {
        if (str_contains($state, $s)) return 'telugu_english';
    }
    foreach ($kannadaStates as $s) {
        if (str_contains($state, $s)) return 'kannada_english';
    }
    foreach ($malayalamStates as $s) {
        if (str_contains($state, $s)) return 'malayalam_english';
    }
    foreach ($punjabiStates as $s) {
        if (str_contains($state, $s)) return 'punjabi_english';
    }

    return 'english'; // Default
}

// ============================================================
// LOGGING
// ============================================================

/**
 * Write a log entry to DB and optionally to file
 *
 * @param string $level   info|warning|error|debug
 * @param string $source  module name (e.g. 'webhook', 'campaign')
 * @param string $message
 * @param array  $context additional data
 */
function crmLog(string $level, string $source, string $message, array $context = []): void
{
    // File log always
    $logLine = sprintf(
        "[%s] [%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $source,
        $message,
        !empty($context) ? json_encode($context) : ''
    );

    $logFile = LOG_DIR . date('Y-m-d') . '-app.log';
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

    // DB log (non-blocking)
    try {
        db()->execute(
            "INSERT INTO logs (level, source, message, context, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                $level,
                $source,
                mb_substr($message, 0, 1000),
                !empty($context) ? json_encode($context) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (Exception $e) {
        // Log to file if DB fails — don't throw
        @file_put_contents(LOG_DIR . 'db_log_errors.log', date('Y-m-d H:i:s') . " DB LOG FAIL: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// ============================================================
// PAGINATION
// ============================================================

/**
 * Build pagination metadata
 */
function paginate(int $total, int $page, int $perPage): array
{
    $page     = max(1, $page);
    $perPage  = max(1, min(100, $perPage));
    $pages    = (int) ceil($total / $perPage);
    $offset   = ($page - 1) * $perPage;

    return [
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'pages'     => $pages,
        'offset'    => $offset,
        'has_prev'  => $page > 1,
        'has_next'  => $page < $pages,
    ];
}

// ============================================================
// FORMATTING
// ============================================================

/**
 * Format datetime for display (IST)
 */
function formatDateTime(mixed $dt): string
{
    if (empty($dt)) return '—';
    try {
        $d = new DateTime((string)$dt, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone(APP_TIMEZONE));
        return $d->format('d M Y, h:i A');
    } catch (Exception $e) {
        return (string)$dt;
    }
}

/**
 * Format datetime as relative time (e.g. "2 hours ago")
 */
function timeAgo(mixed $dt): string
{
    if (empty($dt)) return '—';

    try {
        $time = (new DateTime((string)$dt))->getTimestamp();
    } catch (Exception $e) {
        return (string)$dt;
    }

    $diff = time() - $time;

    if ($diff < 60)      return 'just now';
    if ($diff < 3600)    return floor($diff / 60)    . 'm ago';
    if ($diff < 86400)   return floor($diff / 3600)  . 'h ago';
    if ($diff < 604800)  return floor($diff / 86400) . 'd ago';

    return date('d M Y', $time);
}

/**
 * Truncate text with ellipsis
 */
function truncate(string $text, int $length = 60): string
{
    return mb_strlen($text) > $length
        ? mb_substr($text, 0, $length) . '...'
        : $text;
}

/**
 * Format number with Indian comma style (e.g. 1,23,456)
 */
function formatIndianNumber(int $num): string
{
    return preg_replace('/(\d+?)(?=(\d\d)+(\d)(?!\d))/', '$1,', (string)$num);
}

/**
 * Generate a random alphanumeric token
 */
function randomToken(int $length = 32): string
{
    return bin2hex(random_bytes(intdiv($length, 2)));
}

/**
 * Mask a phone number for display: 91XXXXX5678
 */
function maskPhone(string $phone): string
{
    $len = strlen($phone);
    if ($len <= 4) return $phone;
    return str_repeat('X', $len - 4) . substr($phone, -4);
}

// ============================================================
// WHATSAPP STATUS HELPERS
// ============================================================

/**
 * Get badge HTML for WhatsApp validation status
 */
function waBadge(string $status): string
{
    $map = [
        'valid'            => ['bg' => 'bg-emerald-500/20 text-emerald-400',  'label' => 'Valid WA'],
        'invalid'          => ['bg' => 'bg-red-500/20 text-red-400',          'label' => 'Invalid'],
        'not_on_whatsapp'  => ['bg' => 'bg-slate-500/20 text-slate-400',      'label' => 'No WA'],
        'pending'          => ['bg' => 'bg-amber-500/20 text-amber-400',      'label' => 'Pending'],
        'failed'           => ['bg' => 'bg-orange-500/20 text-orange-400',    'label' => 'Failed'],
    ];
    $b = $map[$status] ?? ['bg' => 'bg-slate-500/20 text-slate-400', 'label' => ucfirst($status)];
    return '<span class="px-2 py-0.5 rounded-full text-xs font-medium ' . $b['bg'] . '">' . $b['label'] . '</span>';
}

/**
 * Get badge HTML for outreach status
 */
function outreachBadge(string $status): string
{
    $map = [
        'pending'  => ['bg' => 'bg-slate-500/20 text-slate-400',     'label' => 'Pending'],
        'queued'   => ['bg' => 'bg-blue-500/20 text-blue-400',       'label' => 'Queued'],
        'sent'     => ['bg' => 'bg-indigo-500/20 text-indigo-400',   'label' => 'Sent'],
        'replied'  => ['bg' => 'bg-emerald-500/20 text-emerald-400', 'label' => 'Replied'],
        'failed'   => ['bg' => 'bg-red-500/20 text-red-400',         'label' => 'Failed'],
        'skipped'  => ['bg' => 'bg-slate-500/20 text-slate-400',     'label' => 'Skipped'],
    ];
    $b = $map[$status] ?? ['bg' => 'bg-slate-500/20 text-slate-400', 'label' => ucfirst($status)];
    return '<span class="px-2 py-0.5 rounded-full text-xs font-medium ' . $b['bg'] . '">' . $b['label'] . '</span>';
}

// ============================================================
// ARRAY / DATA HELPERS
// ============================================================

/**
 * Safe array get with default
 */
function arrayGet(array $arr, string $key, mixed $default = null): mixed
{
    return $arr[$key] ?? $default;
}

/**
 * Flatten a multidimensional array (1 level deep)
 */
function arrayFlatten(array $arr): array
{
    return array_merge(...array_values($arr));
}

/**
 * Generate CSV content from array of rows
 */
function generateCsv(array $headers, array $rows): string
{
    ob_start();
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    return ob_get_clean();
}

/**
 * Check if a string is valid JSON
 */
function isValidJson(string $str): bool
{
    json_decode($str);
    return json_last_error() === JSON_ERROR_NONE;
}
