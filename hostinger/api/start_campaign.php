<?php
// ============================================================
// API: start_campaign.php — Start or resume a campaign
// Triggers campaign.php script to queue batch of leads
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_client.php';
require_once __DIR__ . '/../scripts/campaign.php';

requireApiAuth();
requireMethod('POST');

$body       = getJsonBody();
$campaignId = sanitizeInt($body['campaign_id']  ?? 1, 1);
$batchSize  = sanitizeInt($body['batch_size']   ?? CAMPAIGN_BATCH_SIZE, 1, 100);
$forceRegen = (bool)($body['force_regenerate'] ?? false);

try {
    if (!FEATURE_CAMPAIGN) {
        jsonError('Campaign feature is disabled. Enable it in Settings.', 403);
    }

    // ── Verify campaign exists ────────────────────────────────
    $campaign = db()->fetchOne(
        "SELECT * FROM campaigns WHERE id = ? LIMIT 1",
        [$campaignId]
    );

    if (!$campaign) {
        jsonError("Campaign #{$campaignId} not found", 404);
    }

    if ($campaign['status'] === 'completed') {
        jsonError('Campaign is already completed. Create a new campaign to continue.', 400);
    }

    // ── Check WA connection ───────────────────────────────────
    $waStatus = node()->waStatus();
    if (!$waStatus['success'] || ($waStatus['data']['status'] ?? '') !== 'connected') {
        jsonError('WhatsApp is not connected. Please scan QR code first.', 503);
    }

    // ── Check pending leads exist ─────────────────────────────
    $pendingCount = db()->fetchOne(
        "SELECT COUNT(*) AS cnt FROM leads
         WHERE campaign_id = ? AND whatsapp_status = 'valid' AND outreach_status = 'pending' AND is_archived = 0",
        [$campaignId]
    );

    $pending = (int)($pendingCount['cnt'] ?? 0);

    if ($pending === 0 && $campaign['status'] !== 'running') {
        // Check if there are any queued (resuming)
        $queuedCount = db()->fetchOne(
            "SELECT COUNT(*) AS cnt FROM leads WHERE campaign_id = ? AND outreach_status = 'queued'",
            [$campaignId]
        );
        if ((int)($queuedCount['cnt'] ?? 0) === 0) {
            jsonError('No valid pending leads available. Validate WhatsApp numbers first.', 400);
        }
    }

    // ── Also resume queue on Node if paused ──────────────────
    $queueState = node()->queueState();
    if ($queueState['success'] && ($queueState['data']['isPaused'] ?? false)) {
        node()->resumeQueue();
    }

    // ── Run campaign batch ────────────────────────────────────
    $result = runCampaign($campaignId, $batchSize, $forceRegen);

    if (!empty($result['aborted'])) {
        jsonError('Campaign aborted: ' . $result['aborted'], 400);
    }

    // ── Fetch updated campaign state ──────────────────────────
    $updatedCampaign = db()->fetchOne(
        "SELECT id, name, status, total_leads, sent_count, replied_count, failed_count, started_at, updated_at
         FROM campaigns WHERE id = ? LIMIT 1",
        [$campaignId]
    );

    // ── Pending leads remaining ───────────────────────────────
    $remaining = (int)(db()->fetchOne(
        "SELECT COUNT(*) AS cnt FROM leads
         WHERE campaign_id = ? AND whatsapp_status = 'valid' AND outreach_status = 'pending'",
        [$campaignId]
    )['cnt'] ?? 0);

    crmLog('info', 'api', "Campaign {$campaignId} started", [
        'queued'    => $result['queued'],
        'remaining' => $remaining,
    ]);

    jsonSuccess([
        'campaign'   => $updatedCampaign,
        'batch_result' => [
            'total_picked'  => $result['total_picked'],
            'queued'        => $result['queued'],
            'ai_generated'  => $result['ai_generated'],
            'ai_failed'     => $result['ai_failed'],
            'skipped'       => $result['skipped'],
            'errors'        => $result['errors'],
        ],
        'pending_remaining' => $remaining,
        'message' => $result['queued'] > 0
            ? "{$result['queued']} leads queued for outreach. Messages will be sent with randomized delays."
            : 'No new leads queued in this batch.',
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'start_campaign error: ' . $e->getMessage(), ['campaign_id' => $campaignId]);
    jsonError('Failed to start campaign', 500);
}
