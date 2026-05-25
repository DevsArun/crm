<?php
// ============================================================
// WhatsApp CRM OS — Campaign Runner Script
// Picks validated leads, generates AI messages, queues sends
// Designed for cron execution OR manual API trigger
// Anti-ban: only queues CAMPAIGN_BATCH_SIZE leads per run
// ============================================================

defined('CRM_APP') or define('CRM_APP', true);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_client.php';
require_once __DIR__ . '/../includes/groq.php';

$isCli = php_sapi_name() === 'cli';

/**
 * Run campaign — queue next batch of leads for outreach
 *
 * @param int  $campaignId  Campaign to run (default: 1)
 * @param int  $batchSize   How many leads to process per run
 * @param bool $forceRegen  Force regenerate AI messages even if existing
 * @return array Result summary
 */
function runCampaign(int $campaignId = 1, int $batchSize = 0, bool $forceRegen = false): array
{
    if ($batchSize <= 0) {
        $batchSize = CAMPAIGN_BATCH_SIZE;
    }

    $delayMin  = (int) setting('campaign_delay_min',   CAMPAIGN_DELAY_MIN);
    $delayMax  = (int) setting('campaign_delay_max',   CAMPAIGN_DELAY_MAX);
    $dailyLimit = (int) setting('campaign_daily_limit', CAMPAIGN_DAILY_LIMIT);

    $stats = [
        'campaign_id'  => $campaignId,
        'batch_size'   => $batchSize,
        'total_picked' => 0,
        'queued'       => 0,
        'skipped'      => 0,
        'ai_generated' => 0,
        'ai_failed'    => 0,
        'errors'       => 0,
        'aborted'      => null,
    ];

    // ── Feature flag check ────────────────────────────────────
    if (!FEATURE_CAMPAIGN) {
        return array_merge($stats, ['aborted' => 'Campaign feature disabled']);
    }

    // ── Verify campaign exists and is active ──────────────────
    $campaign = db()->fetchOne(
        "SELECT * FROM campaigns WHERE id = ? AND status IN ('running', 'draft')",
        [$campaignId]
    );

    if (!$campaign) {
        crmLog('warning', 'campaign', "Campaign {$campaignId} not found or not active");
        return array_merge($stats, ['aborted' => "Campaign {$campaignId} not found or not running"]);
    }

    // ── Check WhatsApp connection ─────────────────────────────
    $waStatus = node()->waStatus();
    if (!$waStatus['success'] || ($waStatus['data']['status'] ?? '') !== 'connected') {
        crmLog('warning', 'campaign', 'WhatsApp not connected — campaign aborted');
        return array_merge($stats, ['aborted' => 'WhatsApp not connected']);
    }

    // ── Check daily send count from queue state ───────────────
    $queueState = node()->queueState();
    $todaySent  = 0;
    if ($queueState['success'] && isset($queueState['data']['sendCount'])) {
        $todaySent = (int)$queueState['data']['sendCount'];
    }

    if ($todaySent >= $dailyLimit) {
        crmLog('info', 'campaign', "Daily limit reached ({$todaySent}/{$dailyLimit}) — skipping run");
        return array_merge($stats, ['aborted' => "Daily limit reached ({$todaySent}/{$dailyLimit})"]);
    }

    $remaining = $dailyLimit - $todaySent;
    $batchSize = min($batchSize, $remaining);

    // ── Mark campaign as running ──────────────────────────────
    db()->execute(
        "UPDATE campaigns SET status = 'running', started_at = COALESCE(started_at, NOW()), updated_at = NOW() WHERE id = ?",
        [$campaignId]
    );

    // ── Pick next batch of leads ──────────────────────────────
    // Criteria: valid WA, pending outreach, not replied, not skipped
    $leads = db()->fetchAll(
        "SELECT * FROM leads
         WHERE campaign_id      = ?
           AND whatsapp_status  = 'valid'
           AND outreach_status  = 'pending'
           AND is_archived      = 0
         ORDER BY created_at ASC
         LIMIT ?",
        [$campaignId, $batchSize]
    );

    $stats['total_picked'] = count($leads);

    if (empty($leads)) {
        crmLog('info', 'campaign', "No pending leads for campaign {$campaignId}");

        // Check if campaign is complete
        $remaining = db()->fetchOne(
            "SELECT COUNT(*) as cnt FROM leads
             WHERE campaign_id = ? AND outreach_status = 'pending'",
            [$campaignId]
        );

        if ((int)($remaining['cnt'] ?? 0) === 0) {
            db()->execute(
                "UPDATE campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?",
                [$campaignId]
            );
            crmLog('info', 'campaign', "Campaign {$campaignId} marked as completed");
        }

        return $stats;
    }

    crmLog('info', 'campaign', "Processing {$stats['total_picked']} leads for campaign {$campaignId}", [
        'delay_range' => "{$delayMin}s - {$delayMax}s",
        'daily_limit' => $dailyLimit,
        'today_sent'  => $todaySent,
    ]);

    // ── Process each lead ─────────────────────────────────────
    foreach ($leads as $lead) {
        $leadId = (int)$lead['id'];

        // ── Get or generate AI message ────────────────────────
        $message = (!$forceRegen && !empty($lead['generated_message']))
            ? $lead['generated_message']
            : null;

        if (empty($message)) {
            $aiResult = generateOutreachMessage($lead);

            if (!$aiResult['success'] || empty($aiResult['message'])) {
                $stats['ai_failed']++;
                crmLog('error', 'campaign', "AI generation failed for lead {$leadId}", [
                    'error' => $aiResult['error'] ?? 'unknown',
                ]);
                // Don't skip — use fallback from generateOutreachMessage (it always returns something)
                $message = $aiResult['message'] ?? '';
                if (empty($message)) {
                    $stats['skipped']++;
                    continue;
                }
            } else {
                $stats['ai_generated']++;
            }

            $message = $aiResult['message'];

            // Persist generated message
            db()->execute(
                "UPDATE leads SET
                    generated_message = ?,
                    ai_reasoning      = ?,
                    updated_at        = NOW()
                 WHERE id = ?",
                [$message, $aiResult['reasoning'] ?? null, $leadId]
            );
        }

        // ── Send to Node.js queue ─────────────────────────────
        $sendResult = node()->sendMessage(
            $lead['phone_normalized'],
            $message,
            $leadId,
            $lead['business_name'],
            $delayMin,
            $delayMax
        );

        if ($sendResult['success']) {
            // Mark as queued in DB
            db()->execute(
                "UPDATE leads SET
                    outreach_status  = 'queued',
                    last_contacted_at = NOW(),
                    updated_at       = NOW()
                 WHERE id = ?",
                [$leadId]
            );

            // Update campaign counter
            db()->execute(
                "UPDATE campaigns SET sent_count = sent_count + 1, updated_at = NOW() WHERE id = ?",
                [$campaignId]
            );

            $stats['queued']++;

            crmLog('info', 'campaign', "Lead {$leadId} queued for outreach", [
                'phone'    => $lead['phone_normalized'],
                'business' => $lead['business_name'],
            ]);

        } else {
            $errorMsg = $sendResult['error'] ?? 'unknown error';

            // Mark as failed
            db()->execute(
                "UPDATE leads SET outreach_status = 'failed', updated_at = NOW() WHERE id = ?",
                [$leadId]
            );

            // Update fail counter
            db()->execute(
                "UPDATE campaigns SET failed_count = failed_count + 1 WHERE id = ?",
                [$campaignId]
            );

            $stats['errors']++;

            crmLog('error', 'campaign', "Failed to queue lead {$leadId}", [
                'error'   => $errorMsg,
                'phone'   => $lead['phone_normalized'],
            ]);
        }

        // Brief pause between queue submissions (not WA delay — just DB/API breathing room)
        usleep(100000); // 0.1s
    }

    // ── Final campaign stats update ───────────────────────────
    $totals = db()->fetchOne(
        "SELECT
            SUM(outreach_status = 'pending')  as pending,
            SUM(outreach_status = 'queued')   as queued,
            SUM(outreach_status = 'sent')     as sent,
            SUM(outreach_status = 'replied')  as replied,
            SUM(outreach_status = 'failed')   as failed
         FROM leads WHERE campaign_id = ?",
        [$campaignId]
    );

    if ($totals) {
        db()->execute(
            "UPDATE campaigns SET
                replied_count = ?,
                failed_count  = ?,
                updated_at    = NOW()
             WHERE id = ?",
            [(int)$totals['replied'], (int)$totals['failed'], $campaignId]
        );

        // Auto-complete if no more pending
        if ((int)($totals['pending'] ?? 0) === 0 && (int)($totals['queued'] ?? 0) === 0) {
            db()->execute(
                "UPDATE campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?",
                [$campaignId]
            );
            crmLog('info', 'campaign', "Campaign {$campaignId} auto-completed");
        }
    }

    crmLog('info', 'campaign', "Campaign run complete", array_merge(
        $stats,
        ['campaign_id' => $campaignId]
    ));

    return $stats;
}

// ── CLI execution ─────────────────────────────────────────────
if ($isCli) {
    $campaignId  = (int)($argv[1] ?? 1);
    $batchSize   = (int)($argv[2] ?? CAMPAIGN_BATCH_SIZE);
    $forceRegen  = isset($argv[3]) && $argv[3] === '--force-regen';

    echo "=== WhatsApp CRM: Campaign Runner ===\n";
    echo "Campaign ID : {$campaignId}\n";
    echo "Batch Size  : {$batchSize}\n";
    echo "Force Regen : " . ($forceRegen ? 'yes' : 'no') . "\n\n";

    $result = runCampaign($campaignId, $batchSize, $forceRegen);

    echo "Total picked  : {$result['total_picked']}\n";
    echo "Queued        : {$result['queued']}\n";
    echo "AI Generated  : {$result['ai_generated']}\n";
    echo "AI Failed     : {$result['ai_failed']}\n";
    echo "Skipped       : {$result['skipped']}\n";
    echo "Errors        : {$result['errors']}\n";

    if (!empty($result['aborted'])) {
        echo "ABORTED: {$result['aborted']}\n";
        exit(1);
    }

    exit(0);
}
