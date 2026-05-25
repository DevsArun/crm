<?php
// ============================================================
// WhatsApp CRM OS — Retry Failed Outreach
// Re-queues leads with outreach_status = 'failed'
// Can be triggered manually or via cron
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
 * Retry all failed leads (up to $limit)
 *
 * @param int $limit      Max leads to retry in one run
 * @param int $campaignId Optional campaign filter
 * @return array Result summary
 */
function retryFailedLeads(int $limit = 20, int $campaignId = 0): array
{
    $maxRetries  = (int) setting('campaign_retry_limit', CAMPAIGN_RETRY_LIMIT);
    $delayMin    = (int) setting('campaign_delay_min',   CAMPAIGN_DELAY_MIN);
    $delayMax    = (int) setting('campaign_delay_max',   CAMPAIGN_DELAY_MAX);

    $stats = [
        'total_failed'  => 0,
        'queued'        => 0,
        'skipped'       => 0,
        'errors'        => 0,
        'limit'         => $limit,
    ];

    // ── Check WA connection ───────────────────────────────────
    $waStatus = node()->waStatus();
    if (!$waStatus['success'] || ($waStatus['data']['status'] ?? '') !== 'connected') {
        crmLog('warning', 'retry', 'WhatsApp not connected — retry aborted');
        return array_merge($stats, ['aborted' => 'WhatsApp not connected']);
    }

    // ── Fetch failed leads ────────────────────────────────────
    $sql = "SELECT l.*
            FROM leads l
            WHERE l.outreach_status = 'failed'
              AND l.whatsapp_status = 'valid'
              AND (l.campaign_id = ? OR ? = 0)
            ORDER BY l.updated_at ASC
            LIMIT ?";

    try {
        $leads = db()->fetchAll($sql, [$campaignId, $campaignId, $limit]);
    } catch (Exception $e) {
        crmLog('error', 'retry', 'DB fetch error: ' . $e->getMessage());
        return array_merge($stats, ['fatal' => $e->getMessage()]);
    }

    $stats['total_failed'] = count($leads);

    if (empty($leads)) {
        crmLog('info', 'retry', 'No failed leads to retry');
        return $stats;
    }

    crmLog('info', 'retry', "Retrying {$stats['total_failed']} failed leads");

    foreach ($leads as $lead) {
        $leadId = (int)$lead['id'];

        // ── Regenerate message if missing ────────────────────
        $message = $lead['generated_message'] ?? '';

        if (empty($message)) {
            $aiResult = generateOutreachMessage($lead);
            if ($aiResult['success'] && !empty($aiResult['message'])) {
                $message = $aiResult['message'];
                db()->execute(
                    "UPDATE leads SET generated_message = ?, ai_reasoning = ? WHERE id = ?",
                    [$message, $aiResult['reasoning'], $leadId]
                );
            } else {
                crmLog('warning', 'retry', "Could not generate message for lead {$leadId}");
                $stats['skipped']++;
                continue;
            }
        }

        // ── Queue for send ────────────────────────────────────
        $result = node()->sendMessage(
            $lead['phone_normalized'],
            $message,
            $leadId,
            $lead['business_name'],
            $delayMin,
            $delayMax
        );

        if ($result['success']) {
            // Mark as queued
            db()->execute(
                "UPDATE leads SET outreach_status = 'queued', updated_at = NOW() WHERE id = ?",
                [$leadId]
            );
            $stats['queued']++;

            crmLog('info', 'retry', "Lead {$leadId} re-queued", [
                'phone'    => $lead['phone_normalized'],
                'business' => $lead['business_name'],
            ]);
        } else {
            $stats['errors']++;
            crmLog('error', 'retry', "Failed to queue lead {$leadId}", [
                'error' => $result['error'] ?? 'unknown',
            ]);
        }

        // Small delay between queue submissions
        usleep(200000); // 0.2s
    }

    crmLog('info', 'retry', 'Retry run complete', $stats);
    return $stats;
}

// ── CLI / direct execution ────────────────────────────────────
if ($isCli) {
    $limit      = (int)($argv[1] ?? 20);
    $campaignId = (int)($argv[2] ?? 0);

    echo "=== WhatsApp CRM: Retry Failed Leads ===\n";
    echo "Limit: {$limit} | Campaign: " . ($campaignId ?: 'all') . "\n\n";

    $result = retryFailedLeads($limit, $campaignId);

    echo "Total failed found : {$result['total_failed']}\n";
    echo "Re-queued          : {$result['queued']}\n";
    echo "Skipped            : {$result['skipped']}\n";
    echo "Errors             : {$result['errors']}\n";

    if (!empty($result['aborted'])) {
        echo "ABORTED: {$result['aborted']}\n";
    }

    exit(0);
}
