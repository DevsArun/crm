<?php
// ============================================================
// API: send_manual.php — Manual message send (post-reply)
// Sends immediately (bypass queue) — human-driven follow-up
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_client.php';

requireApiAuth();
requireMethod('POST');

$body    = getJsonBody();
$leadId  = sanitizeInt($body['lead_id'] ?? 0, 1);
$message = sanitizeString($body['message'] ?? '', 4000);

if ($leadId === 0) {
    jsonError('lead_id is required', 400);
}
if (empty(trim($message))) {
    jsonError('message cannot be empty', 400);
}

try {
    // ── Fetch lead ────────────────────────────────────────────
    $lead = db()->fetchOne(
        "SELECT id, business_name, phone_normalized, whatsapp_status, outreach_status, is_archived
         FROM leads WHERE id = ? LIMIT 1",
        [$leadId]
    );

    if (!$lead) {
        jsonError('Lead not found', 404);
    }

    if ($lead['is_archived']) {
        jsonError('Lead is archived', 400);
    }

    if ($lead['whatsapp_status'] !== 'valid') {
        jsonError('Lead does not have a valid WhatsApp number', 400);
    }

    // ── Check WA is connected ─────────────────────────────────
    $waStatus = node()->waStatus();
    if (!$waStatus['success'] || ($waStatus['data']['status'] ?? '') !== 'connected') {
        jsonError('WhatsApp is not connected. Please scan QR and reconnect.', 503);
    }

    // ── Send immediately (bypass queue — manual send) ─────────
    $result = node()->sendMessageImmediate(
        $lead['phone_normalized'],
        $message,
        $leadId
    );

    if (!$result['success']) {
        crmLog('error', 'api', 'Manual send failed', [
            'lead_id' => $leadId,
            'error'   => $result['error'] ?? 'unknown',
        ]);
        jsonError('Failed to send message: ' . ($result['error'] ?? 'Unknown error'), 502);
    }

    $waMessageId = $result['data']['waMessageId'] ?? null;

    // ── Store message in DB ───────────────────────────────────
    db()->execute(
        "INSERT INTO messages
            (lead_id, sender, message_text, wa_message_id, direction, is_read, status, timestamp, created_at)
         VALUES
            (?, 'user', ?, ?, 'outbound', 1, 'sent', NOW(), NOW())",
        [$leadId, $message, $waMessageId]
    );

    $messageId = db()->lastInsertId();

    // ── Update lead last_contacted_at ─────────────────────────
    db()->execute(
        "UPDATE leads SET last_contacted_at = NOW(), updated_at = NOW() WHERE id = ?",
        [$leadId]
    );

    crmLog('info', 'api', 'Manual message sent', [
        'lead_id'      => $leadId,
        'business'     => $lead['business_name'],
        'wa_message_id'=> $waMessageId,
        'length'       => strlen($message),
    ]);

    jsonSuccess([
        'message_id'    => $messageId,
        'wa_message_id' => $waMessageId,
        'sent_at'       => date('c'),
        'lead_id'       => $leadId,
        'message' => [
            'id'           => $messageId,
            'sender'       => 'user',
            'message_text' => $message,
            'direction'    => 'outbound',
            'status'       => 'sent',
            'timestamp'    => date('Y-m-d H:i:s'),
            'time_ago'     => 'just now',
        ],
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'send_manual error: ' . $e->getMessage(), ['lead_id' => $leadId]);
    jsonError('Server error while sending message', 500);
}
