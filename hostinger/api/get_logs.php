<?php
// ============================================================
// API: get_logs.php — System logs viewer
// Returns: paginated DB logs with filters, log file content
// Also handles: log purge action
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requireApiAuth();

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // ── POST: log actions (purge, etc.) ──────────────────────
    if ($method === 'POST') {
        $body   = getJsonBody();
        $action = sanitizeString($body['action'] ?? '', 30);

        switch ($action) {
            case 'purge': {
                $days   = sanitizeInt($body['days'] ?? 30, 1, 365);
                $level  = sanitizeString($body['level'] ?? '', 20);

                $params = [$days];
                $sql    = "DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";

                if (!empty($level) && in_array($level, ['info', 'warning', 'error', 'debug'])) {
                    $sql     .= " AND level = ?";
                    $params[] = $level;
                }

                $deleted = db()->execute($sql, $params);

                crmLog('info', 'api', "Logs purged: {$deleted} entries older than {$days} days", [
                    'level' => $level ?: 'all',
                ]);

                jsonSuccess([
                    'purged'  => $deleted,
                    'message' => "Deleted {$deleted} log entries older than {$days} days",
                ]);
                break;
            }

            case 'purge_files': {
                $days    = sanitizeInt($body['days'] ?? 7, 1, 30);
                $deleted = 0;
                $cutoff  = time() - ($days * 86400);

                if (is_dir(LOG_DIR)) {
                    foreach (glob(LOG_DIR . '*.log') as $file) {
                        if (filemtime($file) < $cutoff) {
                            @unlink($file);
                            $deleted++;
                        }
                    }
                }

                jsonSuccess([
                    'deleted_files' => $deleted,
                    'message'       => "Deleted {$deleted} log files older than {$days} days",
                ]);
                break;
            }

            default:
                jsonError('Unknown log action', 400);
        }
    }

    // ── GET: fetch logs ───────────────────────────────────────
    requireMethod('GET', 'POST');

    $page    = sanitizeInt($_GET['page']    ?? 1,   1, 1000);
    $perPage = sanitizeInt($_GET['per_page']?? 50,  1, 200);
    $level   = sanitizeString($_GET['level']  ?? '', 20);
    $source  = sanitizeString($_GET['source'] ?? '', 50);
    $search  = sanitizeString($_GET['search'] ?? '', 200);
    $date    = sanitizeString($_GET['date']   ?? '', 12); // YYYY-MM-DD

    // ── Build WHERE ───────────────────────────────────────────
    $where  = [];
    $params = [];

    $validLevels = ['info', 'warning', 'error', 'debug'];
    if (!empty($level) && in_array($level, $validLevels)) {
        $where[]  = 'level = ?';
        $params[] = $level;
    }

    if (!empty($source)) {
        $where[]  = 'source = ?';
        $params[] = $source;
    }

    if (!empty($search)) {
        $where[]  = '(message LIKE ? OR context LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if (!empty($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $where[]  = 'DATE(created_at) = ?';
        $params[] = $date;
    }

    $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Total count ───────────────────────────────────────────
    $total = (int)(db()->fetchOne(
        "SELECT COUNT(*) AS cnt FROM logs {$whereSQL}", $params
    )['cnt'] ?? 0);

    $pagination = paginate($total, $page, $perPage);

    // ── Fetch logs ────────────────────────────────────────────
    $logs = db()->fetchAll(
        "SELECT id, level, source, message, context, ip_address, created_at
         FROM logs {$whereSQL}
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$pagination['per_page'], $pagination['offset']])
    );

    // ── Level summary counts ──────────────────────────────────
    $summary = db()->fetchOne(
        "SELECT
            SUM(level = 'info')    AS info_count,
            SUM(level = 'warning') AS warning_count,
            SUM(level = 'error')   AS error_count,
            SUM(level = 'debug')   AS debug_count
         FROM logs"
    );

    // ── Available sources (for filter dropdown) ───────────────
    $sources = db()->fetchAll(
        "SELECT DISTINCT source FROM logs ORDER BY source ASC LIMIT 50"
    );

    // ── Available log dates (last 30 days) ────────────────────
    $dates = db()->fetchAll(
        "SELECT DATE(created_at) AS log_date, COUNT(*) AS count
         FROM logs
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(created_at)
         ORDER BY log_date DESC"
    );

    // ── Format logs ───────────────────────────────────────────
    $formatted = array_map(function ($log) {
        $context = null;
        if (!empty($log['context'])) {
            $decoded = json_decode($log['context'], true);
            $context = is_array($decoded) ? $decoded : ['raw' => $log['context']];
        }

        return [
            'id'         => (int)$log['id'],
            'level'      => $log['level'],
            'source'     => $log['source'],
            'message'    => $log['message'],
            'context'    => $context,
            'ip_address' => $log['ip_address'],
            'created_at' => $log['created_at'],
            'time_ago'   => timeAgo($log['created_at']),
            'time_display' => formatDateTime($log['created_at']),
        ];
    }, $logs);

    jsonSuccess([
        'logs'       => $formatted,
        'pagination' => $pagination,
        'summary'    => [
            'info'    => (int)($summary['info_count']    ?? 0),
            'warning' => (int)($summary['warning_count'] ?? 0),
            'error'   => (int)($summary['error_count']   ?? 0),
            'debug'   => (int)($summary['debug_count']   ?? 0),
            'total'   => $total,
        ],
        'sources'    => array_column($sources, 'source'),
        'dates'      => $dates,
        'filters'    => compact('level', 'source', 'search', 'date'),
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'get_logs error: ' . $e->getMessage());
    jsonError('Failed to load logs', 500);
}
