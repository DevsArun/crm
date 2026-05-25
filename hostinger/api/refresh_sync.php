<?php
// ============================================================
// API: refresh_sync.php — Force sync state from HF Node
// Returns: WA status, queue state, socket stats, system health
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_client.php';

requireApiAuth();
requireMethod('GET', 'POST');

try {
    $action = sanitizeString($_GET['action'] ?? $_POST['action'] ?? 'full', 30);

    switch ($action) {

        // ── Full sync: WA + queue + socket ────────────────────
        case 'full':
        default: {
            // Parallel fetch from Node
            $waStatusResult  = node()->waStatus();
            $queueResult     = node()->queueState();
            $socketResult    = node()->socketStats();
            $healthResult    = node()->health();

            $waData     = $waStatusResult['success']  ? ($waStatusResult['data']  ?? []) : [];
            $queueData  = $queueResult['success']     ? ($queueResult['data']     ?? []) : [];
            $socketData = $socketResult['success']    ? ($socketResult['data']    ?? []) : [];
            $healthData = $healthResult['success']    ? ($healthResult['data']    ?? []) : [];

            // ── Sync WA session to DB ────────────────────────
            if (!empty($waData)) {
                db()->execute(
                    "UPDATE whatsapp_sessions SET
                        status       = ?,
                        phone_number = COALESCE(NULLIF(?, ''), phone_number),
                        display_name = COALESCE(NULLIF(?, ''), display_name),
                        last_ping    = NOW(),
                        updated_at   = NOW()
                     WHERE session_id = 'default'",
                    [
                        $waData['status']  ?? 'disconnected',
                        $waData['phone']   ?? '',
                        $waData['name']    ?? '',
                    ]
                );
            }

            // ── DB-side stats ─────────────────────────────────
            $dbStats = db()->fetchOne(
                "SELECT
                    (SELECT COUNT(*) FROM leads WHERE is_archived = 0)                     AS total_leads,
                    (SELECT COUNT(*) FROM leads WHERE whatsapp_status = 'valid')           AS valid_leads,
                    (SELECT COUNT(*) FROM leads WHERE outreach_status = 'replied')         AS replied_leads,
                    (SELECT COUNT(*) FROM messages WHERE direction = 'inbound' AND is_read = 0) AS unread_messages,
                    (SELECT COUNT(*) FROM campaigns WHERE status = 'running')              AS active_campaigns"
            );

            // ── WA session from DB ────────────────────────────
            $waSession = db()->fetchOne(
                "SELECT status, phone_number, display_name, last_connected, last_ping
                 FROM whatsapp_sessions WHERE session_id = 'default' LIMIT 1"
            );

            jsonSuccess([
                'node_online'  => $waStatusResult['success'] || $queueResult['success'],
                'whatsapp'     => [
                    'status'         => $waData['status']      ?? $waSession['status']       ?? 'disconnected',
                    'phone'          => $waData['phone']       ?? $waSession['phone_number'] ?? null,
                    'name'           => $waData['name']        ?? $waSession['display_name'] ?? null,
                    'last_ping'      => $waSession['last_ping']      ?? null,
                    'last_connected' => $waSession['last_connected'] ?? null,
                    'node_stats'     => $waData['stats']       ?? [],
                ],
                'queue'        => [
                    'length'      => (int)($queueData['queueLength']   ?? 0),
                    'is_running'  => (bool)($queueData['isRunning']    ?? false),
                    'is_paused'   => (bool)($queueData['isPaused']     ?? false),
                    'send_count'  => (int)($queueData['sendCount']     ?? 0),
                    'daily_limit' => (int)($queueData['dailyLimit']    ?? 50),
                    'current_job' => $queueData['currentJobId']        ?? null,
                ],
                'sockets'      => [
                    'connected_clients' => (int)($socketData['connectedClients'] ?? 0),
                ],
                'health'       => [
                    'uptime'   => $healthData['uptime'] ?? null,
                    'memory'   => $healthData['memory'] ?? null,
                    'status'   => $healthData['status'] ?? ($healthResult['success'] ? 'ok' : 'offline'),
                ],
                'db_stats'     => [
                    'total_leads'      => (int)($dbStats['total_leads']      ?? 0),
                    'valid_leads'      => (int)($dbStats['valid_leads']      ?? 0),
                    'replied_leads'    => (int)($dbStats['replied_leads']    ?? 0),
                    'unread_messages'  => (int)($dbStats['unread_messages']  ?? 0),
                    'active_campaigns' => (int)($dbStats['active_campaigns'] ?? 0),
                ],
                'synced_at'    => date('c'),
            ]);
            break;
        }

        // ── WA only ───────────────────────────────────────────
        case 'whatsapp': {
            $result = node()->waStatus();
            $data   = $result['success'] ? ($result['data'] ?? []) : [];

            if (!empty($data)) {
                db()->execute(
                    "UPDATE whatsapp_sessions SET status = ?, last_ping = NOW(), updated_at = NOW()
                     WHERE session_id = 'default'",
                    [$data['status'] ?? 'disconnected']
                );
            }

            jsonSuccess([
                'online'    => $result['success'],
                'whatsapp'  => $data,
                'synced_at' => date('c'),
            ]);
            break;
        }

        // ── Queue only ────────────────────────────────────────
        case 'queue': {
            $result = node()->queueState();
            jsonSuccess([
                'online'    => $result['success'],
                'queue'     => $result['data'] ?? [],
                'synced_at' => date('c'),
            ]);
            break;
        }

        // ── Restart WA engine ─────────────────────────────────
        case 'restart_wa': {
            requireMethod('POST');
            $result = node()->restartWA();
            crmLog('info', 'api', 'WA restart triggered via dashboard', []);
            jsonSuccess([
                'restarted' => $result['success'],
                'message'   => $result['success']
                    ? 'WhatsApp engine restarting. New QR will appear shortly.'
                    : 'Restart failed: ' . ($result['error'] ?? 'unknown'),
            ]);
            break;
        }

        // ── Get current QR ────────────────────────────────────
        case 'get_qr': {
            $result = node()->getQR();
            if (!$result['success']) {
                jsonError($result['error'] ?? 'QR not available', 404);
            }
            jsonSuccess(['qr' => $result['data']['qr'] ?? null]);
            break;
        }
    }

} catch (Exception $e) {
    crmLog('error', 'api', 'refresh_sync error: ' . $e->getMessage());
    jsonError('Sync failed', 500);
}
