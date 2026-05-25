<?php
// ============================================================
// WhatsApp CRM OS — Webhook Receiver
// Receives events from HF Node.js backend
// Handles: inbound messages, outbound status, lead updates,
//          WA status changes, queue events
// Security: HMAC-SHA256 signature + IP whitelist
// ============================================================

define('CRM_APP', true);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

// ── Only accept POST requests ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── Read raw body first (needed for signature check) ─────────
$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Empty body']));
}

// ── IP whitelist check ────────────────────────────────────────
if (!verifyWebhookIp()) {
    crmLog('warning', 'webhook', 'Rejected — IP not whitelisted', [
        'ip' => getClientIp(),
    ]);
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

// ── Signature verification ────────────────────────────────────
if (!verifyWebhookSignature($rawBody)) {
    crmLog('warning', 'webhook', 'Rejected — invalid signature', [
        'ip'        => getClientIp(),
        'signature' => $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? 'none',
    ]);
    http_response_code(401);
    exit(json_encode(['error' => 'Invalid signature']));
}

// ── Parse JSON payload ────────────────────────────────────────
$payload = json_decode($rawBody, true);

if (!is_array($payload) || empty($payload['event'])) {
    crmLog('warning', 'webhook', 'Invalid payload structure', ['raw' => substr($rawBody, 0, 200)]);
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid payload']));
}

$event      = $payload['event'];
$data       = $payload['data']      ?? [];
$webhookId  = $payload['webhookId'] ?? null;
$timestamp  = $payload['timestamp'] ?? date('c');

// ── Deduplication via webhookId ───────────────────────────────
if ($webhookId) {
    $exists = db()->fetchOne(
        "SELECT id FROM logs WHERE source = 'webhook' AND context LIKE ? LIMIT 1",
        ['%"webhookId":"' . $webhookId . '"%']
    );
    if ($exists) {
        // Already processed — return 200 to prevent retry
        http_response_code(200);
        exit(json_encode(['success' => true, 'duplicate' => true]));
    }
}

// ── Route to event handler ────────────────────────────────────
$result = ['success' => false, 'event' => $event];

try {
    switch ($event) {
        case 'message.received':
            $result = handleMessageReceived($data, $timestamp);
            break;

        case 'message.sent':
            $result = handleMessageSent($data, $timestamp);
            break;

        case 'message.status':
            $result = handleMessageStatus($data);
            break;

        case 'message.failed':
            $result = handleMessageFailed($data);
            break;

        case 'lead.replied':
            $result = handleLeadReplied($data, $timestamp);
            break;

        case 'lead.validated':
            $result = handleLeadValidated($data);
            break;

        case 'whatsapp.status':
            $result = handleWhatsAppStatus($data, $timestamp);
            break;

        case 'queue.update':
            $result = handleQueueUpdate($data);
            break;

        default:
            crmLog('info', 'webhook', "Unhandled event: {$event}", ['data' => $data]);
            $result = ['success' => true, 'note' => 'Event received but not handled'];
            break;
    }

    // Log successful processing
    crmLog('info', 'webhook', "Event processed: {$event}", [
        'webhookId' => $webhookId,
        'result'    => $result,
    ]);

} catch (Exception $e) {
    crmLog('error', 'webhook', "Handler exception for {$event}: " . $e->getMessage(), [
        'webhookId' => $webhookId,
        'trace'     => $e->getTraceAsString(),
    ]);

    http_response_code(500);
    exit(json_encode(['success' => false, 'error' => 'Internal handler error']));
}

// ── Send success response ─────────────────────────────────────
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(array_merge(['success' => true], $result));
exit;


// ============================================================
// EVENT HANDLERS
// ============================================================

// ── Inbound message received from a lead ─────────────────────
function handleMessageReceived(array $data, string $timestamp): array
{
    $phone      = sanitizeString($data['phone']       ?? '');
    $body       = $data['body']                        ?? '';
    $waId       = sanitizeString($data['waMessageId'] ?? '');
    $msgTime    = $data['timestamp']                   ?? $timestamp;

    if (empty($phone) || empty($body)) {
        return ['error' => 'Missing phone or body in message.received'];
    }

    // Find lead by normalized phone
    $lead = db()->fetchOne(
        "SELECT id, outreach_status, business_name FROM leads WHERE phone_normalized = ? LIMIT 1",
        [$phone]
    );

    $leadId = $lead ? (int)$lead['id'] : null;

    // Insert message record (ignore duplicate waMessageId)
    if (!empty($waId)) {
        $exists = db()->fetchOne(
            "SELECT id FROM messages WHERE wa_message_id = ? LIMIT 1",
            [$waId]
        );
        if ($exists) {
            return ['duplicate' => true, 'waMessageId' => $waId];
        }
    }

    if ($leadId) {
        db()->execute(
            "INSERT INTO messages (lead_id, sender, message_text, wa_message_id, direction, is_read, status, timestamp, created_at)
             VALUES (?, 'lead', ?, ?, 'inbound', 0, 'delivered', ?, NOW())",
            [$leadId, mb_substr($body, 0, 5000), $waId ?: null, $msgTime]
        );

        // Mark lead as replied if it was in outreach
        if (in_array($lead['outreach_status'], ['sent', 'queued', 'pending'])) {
            db()->execute(
                "UPDATE leads SET
                    outreach_status  = 'replied',
                    replied_at       = NOW(),
                    updated_at       = NOW()
                 WHERE id = ?",
                [$leadId]
            );

            // Update campaign replied counter
            $leadRow = db()->fetchOne("SELECT campaign_id FROM leads WHERE id = ?", [$leadId]);
            if (!empty($leadRow['campaign_id'])) {
                db()->execute(
                    "UPDATE campaigns SET replied_count = replied_count + 1 WHERE id = ?",
                    [$leadRow['campaign_id']]
                );
            }

            crmLog('info', 'webhook', "Lead {$leadId} marked as replied", [
                'phone'    => $phone,
                'business' => $lead['business_name'],
            ]);
        }

        return ['lead_id' => $leadId, 'message_stored' => true, 'replied' => true];

    } else {
        // Unknown number — store without lead association (log only)
        crmLog('info', 'webhook', 'Inbound message from unknown number', [
            'phone' => $phone,
            'body'  => substr($body, 0, 100),
        ]);
        return ['lead_id' => null, 'unknown_number' => true];
    }
}

// ── Outbound message sent confirmation ───────────────────────
function handleMessageSent(array $data, string $timestamp): array
{
    $phone      = sanitizeString($data['phone']       ?? '');
    $body       = $data['message']                     ?? '';
    $waId       = sanitizeString($data['waMessageId'] ?? '');
    $leadId     = !empty($data['leadId']) ? (int)$data['leadId'] : null;
    $msgTime    = $data['timestamp'] ?? $timestamp;

    if (empty($phone)) {
        return ['error' => 'Missing phone in message.sent'];
    }

    // Find lead if not provided
    if (!$leadId) {
        $lead   = db()->fetchOne("SELECT id FROM leads WHERE phone_normalized = ? LIMIT 1", [$phone]);
        $leadId = $lead ? (int)$lead['id'] : null;
    }

    if ($leadId) {
        // Prevent duplicate
        if (!empty($waId)) {
            $exists = db()->fetchOne("SELECT id FROM messages WHERE wa_message_id = ? LIMIT 1", [$waId]);
            if ($exists) {
                return ['duplicate' => true];
            }
        }

        db()->execute(
            "INSERT INTO messages (lead_id, sender, message_text, wa_message_id, direction, is_read, status, timestamp, created_at)
             VALUES (?, 'user', ?, ?, 'outbound', 1, 'sent', ?, NOW())",
            [$leadId, mb_substr($body, 0, 5000), $waId ?: null, $msgTime]
        );

        // Update outreach status to sent (if still queued)
        db()->execute(
            "UPDATE leads SET
                outreach_status   = IF(outreach_status = 'queued', 'sent', outreach_status),
                last_contacted_at = NOW(),
                updated_at        = NOW()
             WHERE id = ? AND outreach_status IN ('queued', 'pending')",
            [$leadId]
        );

        return ['lead_id' => $leadId, 'message_stored' => true];
    }

    return ['lead_id' => null, 'note' => 'Lead not found for sent message'];
}

// ── Message delivery/read status update ──────────────────────
function handleMessageStatus(array $data): array
{
    $waId   = sanitizeString($data['waMessageId'] ?? '');
    $status = sanitizeString($data['status']      ?? '');

    if (empty($waId) || empty($status)) {
        return ['error' => 'Missing waMessageId or status'];
    }

    $validStatuses = ['sent', 'delivered', 'read', 'played', 'failed'];
    if (!in_array($status, $validStatuses)) {
        return ['error' => 'Invalid status: ' . $status];
    }

    $affected = db()->execute(
        "UPDATE messages SET status = ? WHERE wa_message_id = ?",
        [$status, $waId]
    );

    return ['updated' => $affected > 0, 'waMessageId' => $waId, 'status' => $status];
}

// ── Message send failure ──────────────────────────────────────
function handleMessageFailed(array $data): array
{
    $leadId = !empty($data['leadId']) ? (int)$data['leadId'] : null;
    $phone  = sanitizeString($data['phone'] ?? '');
    $error  = sanitizeString($data['error'] ?? 'unknown');

    if (!$leadId && !empty($phone)) {
        $lead   = db()->fetchOne("SELECT id FROM leads WHERE phone_normalized = ? LIMIT 1", [$phone]);
        $leadId = $lead ? (int)$lead['id'] : null;
    }

    if ($leadId) {
        db()->execute(
            "UPDATE leads SET outreach_status = 'failed', updated_at = NOW() WHERE id = ?",
            [$leadId]
        );

        // Log failed message
        db()->execute(
            "INSERT INTO messages (lead_id, sender, message_text, direction, status, error_message, timestamp, created_at)
             VALUES (?, 'user', '[FAILED SEND]', 'outbound', 'failed', ?, NOW(), NOW())",
            [$leadId, mb_substr($error, 0, 500)]
        );

        crmLog('error', 'webhook', "Message failed for lead {$leadId}", ['error' => $error]);
    }

    return ['lead_id' => $leadId, 'marked_failed' => $leadId !== null];
}

// ── Lead replied (explicit event from Node) ───────────────────
function handleLeadReplied(array $data, string $timestamp): array
{
    $leadId = !empty($data['leadId']) ? (int)$data['leadId'] : null;
    $phone  = sanitizeString($data['phone'] ?? '');

    if (!$leadId && !empty($phone)) {
        $lead   = db()->fetchOne("SELECT id FROM leads WHERE phone_normalized = ? LIMIT 1", [$phone]);
        $leadId = $lead ? (int)$lead['id'] : null;
    }

    if ($leadId) {
        db()->execute(
            "UPDATE leads SET
                outreach_status = 'replied',
                replied_at      = COALESCE(replied_at, NOW()),
                updated_at      = NOW()
             WHERE id = ?",
            [$leadId]
        );
    }

    return ['lead_id' => $leadId, 'marked_replied' => $leadId !== null];
}

// ── WhatsApp number validation result ────────────────────────
function handleLeadValidated(array $data): array
{
    $phone        = sanitizeString($data['phone']        ?? '');
    $isRegistered = (bool)($data['isRegistered']         ?? false);
    $status       = sanitizeString($data['status']       ?? ($isRegistered ? 'valid' : 'not_on_whatsapp'));

    if (empty($phone)) {
        return ['error' => 'Missing phone'];
    }

    $validStatuses = ['valid', 'invalid', 'not_on_whatsapp', 'failed'];
    if (!in_array($status, $validStatuses)) {
        $status = $isRegistered ? 'valid' : 'not_on_whatsapp';
    }

    $affected = db()->execute(
        "UPDATE leads SET
            whatsapp_status  = ?,
            outreach_status  = IF(? = 'valid', outreach_status, 'skipped'),
            updated_at       = NOW()
         WHERE phone_normalized = ?",
        [$status, $status, $phone]
    );

    return [
        'phone'    => $phone,
        'status'   => $status,
        'updated'  => $affected > 0,
    ];
}

// ── WhatsApp engine status change ─────────────────────────────
function handleWhatsAppStatus(array $data, string $timestamp): array
{
    $status   = sanitizeString($data['status']  ?? 'disconnected');
    $phone    = sanitizeString($data['phone']   ?? '');
    $name     = sanitizeString($data['name']    ?? '');
    $qrBase64 = $data['qrBase64']               ?? null;

    // Update whatsapp_sessions table
    db()->execute(
        "INSERT INTO whatsapp_sessions (session_id, status, phone_number, display_name, qr_code, last_connected, last_ping, updated_at)
         VALUES ('default', ?, ?, ?, ?, IF(? = 'connected', NOW(), NULL), NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            status         = VALUES(status),
            phone_number   = IF(VALUES(phone_number) != '', VALUES(phone_number), phone_number),
            display_name   = IF(VALUES(display_name) != '', VALUES(display_name), display_name),
            qr_code        = VALUES(qr_code),
            last_connected = IF(VALUES(status) = 'connected', NOW(), last_connected),
            last_ping      = NOW(),
            updated_at     = NOW()",
        [$status, $phone, $name, $qrBase64, $status]
    );

    crmLog('info', 'webhook', "WhatsApp status: {$status}", [
        'phone' => $phone,
        'name'  => $name,
    ]);

    return ['status_updated' => true, 'status' => $status];
}

// ── Queue state update ────────────────────────────────────────
function handleQueueUpdate(array $data): array
{
    // Queue updates are informational — just log them
    crmLog('info', 'webhook', 'Queue state update received', $data);
    return ['noted' => true];
}
