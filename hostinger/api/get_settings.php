<?php
// ============================================================
// API: get_settings.php — Fetch all settings grouped by category
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_client.php';

requireApiAuth();
requireMethod('GET');

try {
    // ── Fetch all settings from DB ────────────────────────────
    $rows = db()->fetchAll(
        "SELECT setting_key, setting_value, setting_type, label, group_name
         FROM settings ORDER BY group_name, id ASC"
    );

    // ── Group settings and cast types ─────────────────────────
    $grouped = [];
    $flat    = [];

    foreach ($rows as $row) {
        $key   = $row['setting_key'];
        $val   = $row['setting_value'];
        $group = $row['group_name'] ?? 'general';

        // Cast value by type
        switch ($row['setting_type']) {
            case 'integer': $val = (int)$val;             break;
            case 'boolean': $val = (bool)(int)$val;        break;
            case 'json':    $val = json_decode($val, true); break;
        }

        // Mask sensitive keys (don't expose API keys in frontend)
        $sensitive = ['groq_api_key', 'node_api_key', 'webhook_secret', 'admin_password'];
        $display   = in_array($key, $sensitive)
            ? (empty($row['setting_value']) ? '' : '••••••••••••••••')
            : $val;

        $entry = [
            'key'    => $key,
            'value'  => $display,
            'type'   => $row['setting_type'],
            'label'  => $row['label'],
            'is_set' => !empty($row['setting_value']),
        ];

        $grouped[$group][] = $entry;
        $flat[$key]        = $val;
    }

    // ── System status checks ──────────────────────────────────
    $systemStatus = [];

    // Groq configured?
    $systemStatus['groq_configured']      = !empty($flat['groq_api_key'] ?? '');
    // Node configured?
    $systemStatus['node_configured']      = !empty($flat['node_api_url'] ?? '')
        && ($flat['node_api_url'] !== 'https://your-space.hf.space');
    // Node API key set?
    $systemStatus['node_key_set']         = !empty($flat['node_api_key'] ?? '');
    // Webhook secret set?
    $systemStatus['webhook_secret_set']   = !empty($flat['webhook_secret'] ?? '');
    // Admin password changed from default?
    $systemStatus['password_changed']     = !str_starts_with(
        $flat['admin_password'] ?? '',
        '$2y$10$defaultHash'
    );

    // ── Node connectivity test ────────────────────────────────
    $nodePing = false;
    if ($systemStatus['node_configured'] && $systemStatus['node_key_set']) {
        $nodePing = node()->ping();
    }
    $systemStatus['node_online'] = $nodePing;

    // ── WA session status ─────────────────────────────────────
    $waSession = db()->fetchOne(
        "SELECT status, phone_number, last_ping FROM whatsapp_sessions WHERE session_id = 'default' LIMIT 1"
    );

    // ── Import stats ──────────────────────────────────────────
    $importStats = db()->fetchOne(
        "SELECT COUNT(*) AS total_imports,
                SUM(imported_rows) AS total_imported,
                MAX(created_at) AS last_import
         FROM csv_imports WHERE status = 'completed'"
    );

    jsonSuccess([
        'settings'       => $grouped,
        'system_status'  => $systemStatus,
        'whatsapp'       => [
            'status'       => $waSession['status']       ?? 'disconnected',
            'phone'        => $waSession['phone_number'] ?? null,
            'last_ping'    => $waSession['last_ping']    ?? null,
        ],
        'import_stats'   => [
            'total_imports'   => (int)($importStats['total_imports']   ?? 0),
            'total_imported'  => (int)($importStats['total_imported']  ?? 0),
            'last_import'     => $importStats['last_import']           ?? null,
        ],
        'app_constants'  => [
            'app_name'    => APP_NAME,
            'app_version' => APP_VERSION,
            'timezone'    => APP_TIMEZONE,
            'socket_url'  => SOCKET_URL,
            'node_api_url'=> NODE_API_URL,
        ],
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'get_settings error: ' . $e->getMessage());
    jsonError('Failed to load settings', 500);
}
