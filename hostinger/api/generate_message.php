<?php
// ============================================================
// API: generate_message.php — AI message generation via Groq
// Generates personalized first-outreach WhatsApp message
// Supports: single lead or batch generation
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/groq.php';

requireApiAuth();
requireMethod('POST');

$body     = getJsonBody();
$mode     = sanitizeString($body['mode']     ?? 'single', 20); // single | batch
$leadId   = sanitizeInt($body['lead_id']     ?? 0, 0);
$forceReg = (bool)($body['force_regenerate'] ?? false);

try {
    if (!FEATURE_AI_GENERATION) {
        jsonError('AI generation feature is disabled. Enable it in Settings.', 403);
    }

    $groqKey = setting('groq_api_key', GROQ_API_KEY);
    if (empty($groqKey)) {
        jsonError('Groq API key not configured. Please add it in Settings → API Configuration.', 503);
    }

    // ── Single lead generation ────────────────────────────────
    if ($mode === 'single') {
        if ($leadId === 0) {
            jsonError('lead_id is required for single mode', 400);
        }

        $lead = db()->fetchOne(
            "SELECT * FROM leads WHERE id = ? AND is_archived = 0 LIMIT 1",
            [$leadId]
        );

        if (!$lead) {
            jsonError('Lead not found', 404);
        }

        // Return existing message unless force regenerate
        if (!$forceReg && !empty($lead['generated_message'])) {
            jsonSuccess([
                'lead_id'    => $leadId,
                'message'    => $lead['generated_message'],
                'reasoning'  => $lead['ai_reasoning'],
                'regenerated'=> false,
                'cached'     => true,
            ]);
        }

        // Generate new message
        $result = generateOutreachMessage($lead);

        if (!$result['success'] || empty($result['message'])) {
            jsonError('Failed to generate message: ' . ($result['error'] ?? 'Groq error'), 502);
        }

        // Save to DB
        db()->execute(
            "UPDATE leads SET
                generated_message = ?,
                ai_reasoning      = ?,
                updated_at        = NOW()
             WHERE id = ?",
            [$result['message'], $result['reasoning'], $leadId]
        );

        crmLog('info', 'api', "AI message generated for lead {$leadId}", [
            'business' => $lead['business_name'],
            'length'   => strlen($result['message']),
        ]);

        jsonSuccess([
            'lead_id'    => $leadId,
            'message'    => $result['message'],
            'reasoning'  => $result['reasoning'],
            'regenerated'=> true,
            'cached'     => false,
            'lead_name'  => $lead['business_name'],
            'pitch_type' => $lead['pitch_type'],
            'language'   => $lead['language_preference'],
        ]);
    }

    // ── Batch generation ──────────────────────────────────────
    if ($mode === 'batch') {
        $campaignId = sanitizeInt($body['campaign_id'] ?? 0, 0);
        $limit      = sanitizeInt($body['limit']       ?? 20, 1, 100);
        $onlyEmpty  = (bool)($body['only_empty']       ?? true); // Only generate for leads without message

        $params = [];
        $sql    = "SELECT * FROM leads WHERE is_archived = 0 AND whatsapp_status = 'valid'";

        if ($onlyEmpty && !$forceReg) {
            $sql .= " AND (generated_message IS NULL OR generated_message = '')";
        }

        if ($campaignId > 0) {
            $sql     .= " AND campaign_id = ?";
            $params[] = $campaignId;
        }

        $sql    .= " ORDER BY created_at ASC LIMIT ?";
        $params[] = $limit;

        $leads = db()->fetchAll($sql, $params);

        if (empty($leads)) {
            jsonSuccess([
                'message'   => 'No leads need message generation',
                'total'     => 0,
                'generated' => 0,
                'failed'    => 0,
            ]);
        }

        $generated = 0;
        $failed    = 0;
        $results   = [];

        foreach ($leads as $lead) {
            $lid = (int)$lead['id'];

            $aiResult = generateOutreachMessage($lead);

            if ($aiResult['success'] && !empty($aiResult['message'])) {
                db()->execute(
                    "UPDATE leads SET generated_message = ?, ai_reasoning = ?, updated_at = NOW() WHERE id = ?",
                    [$aiResult['message'], $aiResult['reasoning'], $lid]
                );
                $generated++;
                $results[] = [
                    'lead_id'    => $lid,
                    'name'       => $lead['business_name'],
                    'success'    => true,
                    'preview'    => truncate($aiResult['message'], 100),
                ];
            } else {
                $failed++;
                $results[] = [
                    'lead_id' => $lid,
                    'name'    => $lead['business_name'],
                    'success' => false,
                    'error'   => $aiResult['error'] ?? 'Generation failed',
                ];
            }

            // Rate limit: 500ms between Groq calls
            if (next($leads) !== false) {
                usleep(500000);
            }
        }

        crmLog('info', 'api', 'Batch AI generation complete', [
            'total'     => count($leads),
            'generated' => $generated,
            'failed'    => $failed,
        ]);

        jsonSuccess([
            'total'     => count($leads),
            'generated' => $generated,
            'failed'    => $failed,
            'results'   => $results,
        ]);
    }

    jsonError('Invalid mode. Use: single or batch', 400);

} catch (Exception $e) {
    crmLog('error', 'api', 'generate_message error: ' . $e->getMessage(), ['lead_id' => $leadId]);
    jsonError('Message generation failed', 500);
}
