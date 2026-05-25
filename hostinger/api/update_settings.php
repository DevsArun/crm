<?php
// ============================================================
// API: update_settings.php — Save one or multiple settings
// Handles: individual key update, bulk group update,
//          password change, node config test
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_client.php';

requireApiAuth();
requireMethod('POST');

$body   = getJsonBody();
$action = sanitizeString($body['action'] ?? 'update', 30);

// ── Allowed setting keys and their validation rules ───────────
const SETTING_RULES = [
    'groq_api_key'          => ['type' => 'string',  'max' => 200,  'sensitive' => true],
    'groq_model'            => ['type' => 'string',  'max' => 100,  'sensitive' => false],
    'node_api_url'          => ['type' => 'url',     'max' => 300,  'sensitive' => false],
    'node_api_key'          => ['type' => 'string',  'max' => 200,  'sensitive' => true],
    'socket_url'            => ['type' => 'url',     'max' => 300,  'sensitive' => false],
    'webhook_secret'        => ['type' => 'string',  'max' => 200,  'sensitive' => true],
    'campaign_delay_min'    => ['type' => 'integer', 'min' => 30,   'max_val' => 3600],
    'campaign_delay_max'    => ['type' => 'integer', 'min' => 60,   'max_val' => 7200],
    'campaign_daily_limit'  => ['type' => 'integer', 'min' => 1,    'max_val' => 500],
    'campaign_retry_limit'  => ['type' => 'integer', 'min' => 1,    'max_val' => 10],
    'auto_stop_on_reply'    => ['type' => 'boolean'],
    'app_name'              => ['type' => 'string',  'max' => 100,  'sensitive' => false],
    'app_tagline'           => ['type' => 'string',  'max' => 200,  'sensitive' => false],
    'dark_mode'             => ['type' => 'boolean'],
    'notification_sound'    => ['type' => 'boolean'],
    'timezone'              => ['type' => 'string',  'max' => 50,   'sensitive' => false],
    'webhook_ip_whitelist'  => ['type' => 'string',  'max' => 500,  'sensitive' => false],
    'session_timeout'       => ['type' => 'integer', 'min' => 300,  'max_val' => 86400],
    'feature_ai_generation' => ['type' => 'boolean'],
    'feature_validation'    => ['type' => 'boolean'],
    'feature_campaign'      => ['type' => 'boolean'],
    'feature_logs'          => ['type' => 'boolean'],
    'log_retention_days'    => ['type' => 'integer', 'min' => 1,    'max_val' => 365],
    'log_level'             => ['type' => 'string',  'max' => 10,   'sensitive' => false],
    'seller_name'           => ['type' => 'string',  'max' => 100,  'sensitive' => false],
    'seller_services'       => ['type' => 'json'],
    'seller_portfolio_url'  => ['type' => 'url',     'max' => 300,  'sensitive' => false],
    'seller_cta_text'       => ['type' => 'string',  'max' => 300,  'sensitive' => false],
];

try {
    switch ($action) {

        // ── Update single setting ─────────────────────────────
        case 'update': {
            $key   = sanitizeString($body['key']   ?? '', 100);
            $value = $body['value'] ?? null;

            if (empty($key)) jsonError('Setting key is required', 400);

            if (!array_key_exists($key, SETTING_RULES)) {
                jsonError("Unknown setting key: {$key}", 400);
            }

            $validated = _validateSettingValue($key, $value, SETTING_RULES[$key]);
            if ($validated['error']) {
                jsonError($validated['error'], 400);
            }

            _saveSetting($key, $validated['value']);

            // Invalidate settings cache
            global $_settingsCache;
            $_settingsCache = null;

            crmLog('info', 'api', "Setting updated: {$key}", [
                'sensitive' => SETTING_RULES[$key]['sensitive'] ?? false,
            ]);

            jsonSuccess([
                'key'     => $key,
                'updated' => true,
                'message' => "Setting '{$key}' saved successfully",
            ]);
            break;
        }

        // ── Bulk update (send entire group) ───────────────────
        case 'bulk_update': {
            $settings = $body['settings'] ?? [];
            if (!is_array($settings) || empty($settings)) {
                jsonError('settings object required for bulk_update', 400);
            }

            $updated = [];
            $errors  = [];

            foreach ($settings as $key => $value) {
                $key = sanitizeString($key, 100);

                if (!array_key_exists($key, SETTING_RULES)) {
                    $errors[$key] = "Unknown key";
                    continue;
                }

                $validated = _validateSettingValue($key, $value, SETTING_RULES[$key]);
                if ($validated['error']) {
                    $errors[$key] = $validated['error'];
                    continue;
                }

                _saveSetting($key, $validated['value']);
                $updated[] = $key;
            }

            // Invalidate cache
            global $_settingsCache;
            $_settingsCache = null;

            crmLog('info', 'api', 'Bulk settings update', [
                'updated' => count($updated),
                'errors'  => count($errors),
            ]);

            jsonSuccess([
                'updated'       => $updated,
                'errors'        => $errors,
                'updated_count' => count($updated),
                'error_count'   => count($errors),
            ]);
            break;
        }

        // ── Change admin password ─────────────────────────────
        case 'change_password': {
            $currentPassword = $body['current_password'] ?? '';
            $newPassword     = $body['new_password']     ?? '';
            $confirmPassword = $body['confirm_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword)) {
                jsonError('Current and new password required', 400);
            }

            if ($newPassword !== $confirmPassword) {
                jsonError('New passwords do not match', 400);
            }

            if (strlen($newPassword) < 8) {
                jsonError('Password must be at least 8 characters', 400);
            }

            // Verify current password
            $storedHash = setting('admin_password', '');
            if (!password_verify($currentPassword, $storedHash)) {
                jsonError('Current password is incorrect', 401);
            }

            // Hash new password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
            _saveSetting('admin_password', $newHash);

            crmLog('info', 'api', 'Admin password changed successfully', []);

            jsonSuccess(['message' => 'Password changed successfully. Please log in again.']);
            break;
        }

        // ── Test Node connection ──────────────────────────────
        case 'test_node': {
            $testUrl = sanitizeUrl($body['url'] ?? NODE_API_URL);
            $testKey = sanitizeString($body['key'] ?? NODE_API_KEY, 200);

            if (empty($testUrl)) {
                jsonError('Node API URL is required for testing', 400);
            }

            // Temporarily override for test
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => rtrim($testUrl, '/') . '/ping',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => [
                    'X-API-Key: ' . $testKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            $online   = !$curlErr && $httpCode === 200;
            $data     = $response ? json_decode($response, true) : null;

            jsonSuccess([
                'online'    => $online,
                'http_code' => $httpCode,
                'pong'      => $data['pong'] ?? false,
                'error'     => $curlErr ?: null,
                'message'   => $online
                    ? 'Node server is reachable and responding'
                    : 'Cannot reach Node server: ' . ($curlErr ?: "HTTP {$httpCode}"),
            ]);
            break;
        }

        // ── Test Groq connection ──────────────────────────────
        case 'test_groq': {
            $testKey = sanitizeString($body['key'] ?? '', 200);
            if (empty($testKey)) {
                $testKey = setting('groq_api_key', GROQ_API_KEY);
            }

            if (empty($testKey)) {
                jsonError('Groq API key required', 400);
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://api.groq.com/openai/v1/models',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $testKey],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $valid = $httpCode === 200;
            $data  = $response ? json_decode($response, true) : null;

            jsonSuccess([
                'valid'     => $valid,
                'http_code' => $httpCode,
                'models'    => $valid ? array_column($data['data'] ?? [], 'id') : [],
                'message'   => $valid
                    ? 'Groq API key is valid'
                    : 'Invalid Groq API key or service unavailable',
            ]);
            break;
        }

        default:
            jsonError("Unknown action: {$action}", 400);
    }

} catch (Exception $e) {
    crmLog('error', 'api', "update_settings [{$action}] error: " . $e->getMessage());
    jsonError('Settings update failed', 500);
}

// ── Validate and cast a setting value ─────────────────────────
function _validateSettingValue(string $key, mixed $value, array $rules): array
{
    $type = $rules['type'] ?? 'string';

    switch ($type) {
        case 'integer': {
            if (!is_numeric($value)) {
                return ['error' => "{$key} must be a number", 'value' => null];
            }
            $int    = (int)$value;
            $minVal = $rules['min']     ?? 0;
            $maxVal = $rules['max_val'] ?? PHP_INT_MAX;
            if ($int < $minVal || $int > $maxVal) {
                return ['error' => "{$key} must be between {$minVal} and {$maxVal}", 'value' => null];
            }
            return ['error' => null, 'value' => (string)$int];
        }

        case 'boolean': {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                return ['error' => "{$key} must be a boolean (true/false/1/0)", 'value' => null];
            }
            return ['error' => null, 'value' => $bool ? '1' : '0'];
        }

        case 'url': {
            $url = sanitizeUrl($value);
            if (!empty($value) && empty($url)) {
                return ['error' => "{$key} must be a valid URL", 'value' => null];
            }
            return ['error' => null, 'value' => $url];
        }

        case 'json': {
            if (is_array($value)) {
                return ['error' => null, 'value' => json_encode($value)];
            }
            if (is_string($value) && isValidJson($value)) {
                return ['error' => null, 'value' => $value];
            }
            return ['error' => "{$key} must be valid JSON or array", 'value' => null];
        }

        case 'string':
        default: {
            $maxLen = $rules['max'] ?? 500;
            $str    = sanitizeString($value, $maxLen);
            return ['error' => null, 'value' => $str];
        }
    }
}

// ── Save a setting to DB ──────────────────────────────────────
function _saveSetting(string $key, string $value): void
{
    db()->execute(
        "UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?",
        [$value, $key]
    );

    // If key doesn't exist, insert it
    $affected = db()->fetchOne(
        "SELECT ROW_COUNT() AS n"
    );

    // Actually check via SELECT
    $exists = db()->fetchOne(
        "SELECT id FROM settings WHERE setting_key = ? LIMIT 1",
        [$key]
    );

    if (!$exists) {
        db()->execute(
            "INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, updated_at)
             VALUES (?, ?, 'string', NOW())",
            [$key, $value]
        );
    }
}
