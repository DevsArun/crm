<?php
// ============================================================
// API: pause_campaign.php — Pause, resume, or stop campaign
// Also handles: campaign creation, campaign status queries
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
$action     = sanitizeString($body['action']      ?? 'pause', 20);
$campaignId = sanitizeInt($body['campaign_id']    ?? 1, 1);

try {
    switch ($action) {

        // ── Pause campaign ────────────────────────────────────
        case 'pause': {
            $campaign = db()->fetchOne("SELECT * FROM campaigns WHERE id = ? LIMIT 1", [$campaignId]);
            if (!$campaign) jsonError('Campaign not found', 404);
            if ($campaign['status'] !== 'running') {
                jsonError("Campaign is not running (current: {$campaign['status']})", 400);
            }

            // Pause Node queue
            $nodeResult = node()->pauseQueue();

            // Update DB
            db()->execute(
                "UPDATE campaigns SET status = 'paused', paused_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$campaignId]
            );

            crmLog('info', 'api', "Campaign {$campaignId} paused", []);

            jsonSuccess([
                'action'        => 'paused',
                'campaign_id'   => $campaignId,
                'queue_paused'  => $nodeResult['success'] ?? false,
                'message'       => 'Campaign paused. Queued messages will not be sent until resumed.',
            ]);
            break;
        }

        // ── Resume campaign ───────────────────────────────────
        case 'resume': {
            $campaign = db()->fetchOne("SELECT * FROM campaigns WHERE id = ? LIMIT 1", [$campaignId]);
            if (!$campaign) jsonError('Campaign not found', 404);
            if ($campaign['status'] !== 'paused') {
                jsonError("Campaign is not paused (current: {$campaign['status']})", 400);
            }

            // Check WA still connected
            $waStatus = node()->waStatus();
            if (!$waStatus['success'] || ($waStatus['data']['status'] ?? '') !== 'connected') {
                jsonError('WhatsApp not connected. Reconnect before resuming.', 503);
            }

            // Resume Node queue
            $nodeResult = node()->resumeQueue();

            // Update DB
            db()->execute(
                "UPDATE campaigns SET status = 'running', paused_at = NULL, updated_at = NOW() WHERE id = ?",
                [$campaignId]
            );

            crmLog('info', 'api', "Campaign {$campaignId} resumed", []);

            jsonSuccess([
                'action'         => 'resumed',
                'campaign_id'    => $campaignId,
                'queue_resumed'  => $nodeResult['success'] ?? false,
                'message'        => 'Campaign resumed. Messages will continue sending.',
            ]);
            break;
        }

        // ── Stop campaign and clear queue ─────────────────────
        case 'stop': {
            $campaign = db()->fetchOne("SELECT * FROM campaigns WHERE id = ? LIMIT 1", [$campaignId]);
            if (!$campaign) jsonError('Campaign not found', 404);

            // Stop Node queue (clears all pending)
            $nodeResult = node()->stopQueue();

            // Reset queued leads back to pending
            $reset = db()->execute(
                "UPDATE leads SET outreach_status = 'pending', updated_at = NOW()
                 WHERE campaign_id = ? AND outreach_status = 'queued'",
                [$campaignId]
            );

            // Update campaign status
            db()->execute(
                "UPDATE campaigns SET status = 'paused', updated_at = NOW() WHERE id = ? AND status = 'running'",
                [$campaignId]
            );

            crmLog('info', 'api', "Campaign {$campaignId} stopped", ['reset_leads' => $reset]);

            jsonSuccess([
                'action'        => 'stopped',
                'campaign_id'   => $campaignId,
                'queue_cleared' => $nodeResult['data']['cleared'] ?? 0,
                'leads_reset'   => $reset,
                'message'       => 'Campaign stopped. Queued leads have been reset to pending.',
            ]);
            break;
        }

        // ── Create new campaign ───────────────────────────────
        case 'create': {
            $name       = sanitizeString($body['name']        ?? 'Campaign #' . date('Y-m-d'), 255);
            $description= sanitizeString($body['description'] ?? '', 500);
            $dailyLimit = sanitizeInt($body['daily_limit']    ?? CAMPAIGN_DAILY_LIMIT, 1, 500);
            $delayMin   = sanitizeInt($body['delay_min']      ?? CAMPAIGN_DELAY_MIN,   30, 3600);
            $delayMax   = sanitizeInt($body['delay_max']      ?? CAMPAIGN_DELAY_MAX,   60, 7200);

            if ($delayMin >= $delayMax) {
                $delayMax = $delayMin + 60;
            }

            $newId = db()->insert(
                "INSERT INTO campaigns (name, description, status, daily_limit, delay_min, delay_max, created_at, updated_at)
                 VALUES (?, ?, 'draft', ?, ?, ?, NOW(), NOW())",
                [$name, $description, $dailyLimit, $delayMin, $delayMax]
            );

            crmLog('info', 'api', "Campaign created: {$name} (ID: {$newId})", []);

            $newCampaign = db()->fetchOne("SELECT * FROM campaigns WHERE id = ? LIMIT 1", [$newId]);

            jsonSuccess([
                'action'   => 'created',
                'campaign' => $newCampaign,
                'message'  => "Campaign '{$name}' created successfully.",
            ]);
            break;
        }

        // ── Get campaign status ───────────────────────────────
        case 'status': {
            $campaign = db()->fetchOne("SELECT * FROM campaigns WHERE id = ? LIMIT 1", [$campaignId]);
            if (!$campaign) jsonError('Campaign not found', 404);

            $stats = db()->fetchOne(
                "SELECT
                    SUM(outreach_status = 'pending')  AS pending,
                    SUM(outreach_status = 'queued')   AS queued,
                    SUM(outreach_status = 'sent')     AS sent,
                    SUM(outreach_status = 'replied')  AS replied,
                    SUM(outreach_status = 'failed')   AS failed,
                    SUM(outreach_status = 'skipped')  AS skipped,
                    SUM(whatsapp_status = 'valid')    AS wa_valid,
                    SUM(whatsapp_status = 'pending')  AS wa_pending,
                    COUNT(*)                          AS total
                 FROM leads WHERE campaign_id = ?",
                [$campaignId]
            );

            $queueState = node()->queueState();

            jsonSuccess([
                'campaign'   => $campaign,
                'lead_stats' => [
                    'total'    => (int)($stats['total']   ?? 0),
                    'pending'  => (int)($stats['pending'] ?? 0),
                    'queued'   => (int)($stats['queued']  ?? 0),
                    'sent'     => (int)($stats['sent']    ?? 0),
                    'replied'  => (int)($stats['replied'] ?? 0),
                    'failed'   => (int)($stats['failed']  ?? 0),
                    'skipped'  => (int)($stats['skipped'] ?? 0),
                    'wa_valid' => (int)($stats['wa_valid']?? 0),
                    'wa_pending'=> (int)($stats['wa_pending']??0),
                ],
                'queue'   => $queueState['data'] ?? [],
            ]);
            break;
        }

        // ── List all campaigns ────────────────────────────────
        case 'list': {
            $campaigns = db()->fetchAll(
                "SELECT c.*,
                    (SELECT COUNT(*) FROM leads WHERE campaign_id = c.id) AS lead_count
                 FROM campaigns c ORDER BY c.created_at DESC LIMIT 50"
            );

            jsonSuccess(['campaigns' => $campaigns]);
            break;
        }

        default:
            jsonError("Unknown action: {$action}. Allowed: pause|resume|stop|create|status|list", 400);
    }

} catch (Exception $e) {
    crmLog('error', 'api', "pause_campaign [{$action}] error: " . $e->getMessage());
    jsonError('Campaign action failed', 500);
}
