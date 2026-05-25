<?php
// ============================================================
// API: validate_number.php — Single WhatsApp number validation
// Checks if a lead's phone is registered on WhatsApp
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
$leadId = sanitizeInt($body['lead_id'] ?? 0, 1);

if ($leadId === 0) {
    jsonError('lead_id is required', 400);
}

try {
    // ── Fetch lead ────────────────────────────────────────────
    $lead = db()->fetchOne(
        "SELECT id, business_name, phone_normalized, whatsapp_status FROM leads WHERE id = ? LIMIT 1",
        [$leadId]
    );

    if (!$lead) {
        jsonError('Lead not found', 404);
    }

    if (empty($lead['phone_normalized'])) {
        // Mark as invalid immediately
        db()->execute(
            "UPDATE leads SET whatsapp_status = 'invalid', updated_at = NOW() WHERE id = ?",
            [$leadId]
        );
        jsonSuccess([
            'lead_id'      => $leadId,
            'phone'        => null,
            'status'       => 'invalid',
            'is_registered'=> false,
            'message'      => 'No valid phone number on record',
        ]);
    }

    // ── Check WA connection ───────────────────────────────────
    $waStatus = node()->waStatus();
    if (!$waStatus['success'] || ($waStatus['data']['status'] ?? '') !== 'connected') {
        jsonError('WhatsApp engine not connected. Please scan QR first.', 503);
    }

    // ── Call Node check-number ────────────────────────────────
    $result = node()->checkNumber($lead['phone_normalized']);

    if (!$result['success']) {
        // Mark as failed
        db()->execute(
            "UPDATE leads SET whatsapp_status = 'failed', updated_at = NOW() WHERE id = ?",
            [$leadId]
        );

        jsonError('Validation failed: ' . ($result['error'] ?? 'Node error'), 502);
    }

    $data         = $result['data'] ?? [];
    $isRegistered = (bool)($data['isRegistered'] ?? false);
    $newStatus    = $isRegistered ? 'valid' : 'not_on_whatsapp';

    // ── Update DB ─────────────────────────────────────────────
    db()->execute(
        "UPDATE leads SET
            whatsapp_status = ?,
            outreach_status = IF(? != 'valid' AND outreach_status = 'pending', 'skipped', outreach_status),
            updated_at      = NOW()
         WHERE id = ?",
        [$newStatus, $newStatus, $leadId]
    );

    crmLog('info', 'api', "Number validated: {$lead['phone_normalized']} → {$newStatus}", [
        'lead_id'  => $leadId,
        'business' => $lead['business_name'],
    ]);

    jsonSuccess([
        'lead_id'       => $leadId,
        'business_name' => $lead['business_name'],
        'phone'         => $lead['phone_normalized'],
        'phone_display' => formatPhoneDisplay($lead['phone_normalized']),
        'status'        => $newStatus,
        'is_registered' => $isRegistered,
        'previous_status' => $lead['whatsapp_status'],
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'validate_number error: ' . $e->getMessage(), ['lead_id' => $leadId]);
    jsonError('Validation error', 500);
}
