<?php
// ============================================================
// API: validate_batch.php — Batch WhatsApp number validation
// Validates all pending leads or a selected subset
// Results delivered async via webhook + socket
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_client.php';

requireApiAuth();
requireMethod('POST');

$body       = getJsonBody();
$mode       = sanitizeString($body['mode']        ?? 'pending', 20); // pending | selected | all_unvalidated
$leadIds    = $body['lead_ids'] ?? [];                                // for 'selected' mode
$campaignId = sanitizeInt($body['campaign_id']    ?? 0, 0);
$limit      = sanitizeInt($body['limit']          ?? 50, 1, 200);

try {
    // ── WA connection check ───────────────────────────────────
    $waStatus = node()->waStatus();
    if (!$waStatus['success'] || ($waStatus['data']['status'] ?? '') !== 'connected') {
        jsonError('WhatsApp engine not connected. Please scan QR first.', 503);
    }

    // ── Build lead query based on mode ────────────────────────
    switch ($mode) {
        case 'selected':
            if (empty($leadIds) || !is_array($leadIds)) {
                jsonError('lead_ids array required for selected mode', 400);
            }
            // Sanitize IDs
            $cleanIds = array_map('intval', array_filter($leadIds, fn($id) => is_numeric($id) && $id > 0));
            $cleanIds = array_slice($cleanIds, 0, 200); // max 200

            if (empty($cleanIds)) {
                jsonError('No valid lead IDs provided', 400);
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $leads = db()->fetchAll(
                "SELECT id, phone_normalized, business_name FROM leads
                 WHERE id IN ({$placeholders}) AND is_archived = 0",
                $cleanIds
            );
            break;

        case 'all_unvalidated':
            $params = [];
            $sql    = "SELECT id, phone_normalized, business_name FROM leads
                       WHERE whatsapp_status IN ('pending', 'failed') AND is_archived = 0";
            if ($campaignId > 0) {
                $sql     .= " AND campaign_id = ?";
                $params[] = $campaignId;
            }
            $sql    .= " ORDER BY created_at ASC LIMIT ?";
            $params[] = $limit;
            $leads  = db()->fetchAll($sql, $params);
            break;

        case 'pending':
        default:
            $params = [];
            $sql    = "SELECT id, phone_normalized, business_name FROM leads
                       WHERE whatsapp_status = 'pending' AND is_archived = 0";
            if ($campaignId > 0) {
                $sql     .= " AND campaign_id = ?";
                $params[] = $campaignId;
            }
            $sql    .= " ORDER BY created_at ASC LIMIT ?";
            $params[] = $limit;
            $leads  = db()->fetchAll($sql, $params);
            break;
    }

    if (empty($leads)) {
        jsonSuccess([
            'message'  => 'No leads found for validation',
            'total'    => 0,
            'queued'   => 0,
            'mode'     => $mode,
        ]);
    }

    // ── Extract normalized phones ─────────────────────────────
    $phones = array_filter(
        array_column($leads, 'phone_normalized'),
        fn($p) => !empty($p)
    );
    $phones = array_values($phones);

    if (empty($phones)) {
        jsonError('No valid phone numbers found in selected leads', 400);
    }

    // ── Mark all as 'pending' (reset failed) ─────────────────
    $leadIdList   = array_column($leads, 'id');
    $placeholders = implode(',', array_fill(0, count($leadIdList), '?'));
    db()->execute(
        "UPDATE leads SET whatsapp_status = 'pending', updated_at = NOW() WHERE id IN ({$placeholders})",
        $leadIdList
    );

    // ── Submit batch to Node (async — results via webhook) ────
    // Node will process and call back to webhook.php per result
    $result = node()->checkNumberBatch($phones);

    if (!$result['success']) {
        crmLog('error', 'api', 'Batch validation Node call failed', [
            'error' => $result['error'] ?? 'unknown',
            'count' => count($phones),
        ]);
        jsonError('Failed to start batch validation: ' . ($result['error'] ?? 'Node error'), 502);
    }

    crmLog('info', 'api', 'Batch validation started', [
        'mode'   => $mode,
        'total'  => count($phones),
        'campaign' => $campaignId ?: 'all',
    ]);

    jsonSuccess([
        'message'    => 'Batch validation started. Results will update in real-time.',
        'total'      => count($phones),
        'mode'       => $mode,
        'campaign_id'=> $campaignId ?: null,
        'note'       => 'Lead statuses will update live via socket events',
        'leads_info' => array_map(fn($l) => [
            'id'    => (int)$l['id'],
            'name'  => $l['business_name'],
            'phone' => $l['phone_normalized'],
        ], $leads),
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'validate_batch error: ' . $e->getMessage());
    jsonError('Batch validation failed', 500);
}
