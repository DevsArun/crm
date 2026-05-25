<?php
// ============================================================
// API: get_stats.php — Dashboard KPI Statistics
// Returns: lead counts, outreach stats, WA status, queue state
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/node_client.php';

requireApiAuth();
requireMethod('GET');

try {
    // ── Lead statistics ───────────────────────────────────────
    $leadStats = db()->fetchOne(
        "SELECT
            COUNT(*)                                          AS total_leads,
            SUM(whatsapp_status = 'valid')                    AS wa_valid,
            SUM(whatsapp_status = 'invalid')                  AS wa_invalid,
            SUM(whatsapp_status = 'not_on_whatsapp')          AS wa_not_on_wa,
            SUM(whatsapp_status = 'pending')                  AS wa_pending,
            SUM(outreach_status = 'pending')                  AS outreach_pending,
            SUM(outreach_status = 'queued')                   AS outreach_queued,
            SUM(outreach_status = 'sent')                     AS outreach_sent,
            SUM(outreach_status = 'replied')                  AS outreach_replied,
            SUM(outreach_status = 'failed')                   AS outreach_failed,
            SUM(outreach_status = 'skipped')                  AS outreach_skipped,
            SUM(website_status  = 'has_website')              AS has_website,
            SUM(website_status  = 'no_website')               AS no_website,
            SUM(pitch_type      = 'type_a')                   AS type_a,
            SUM(pitch_type      = 'type_b')                   AS type_b
         FROM leads
         WHERE is_archived = 0"
    );

    // ── Message statistics ────────────────────────────────────
    $msgStats = db()->fetchOne(
        "SELECT
            COUNT(*)                          AS total_messages,
            SUM(direction = 'inbound')        AS inbound,
            SUM(direction = 'outbound')       AS outbound,
            SUM(is_read = 0 AND direction = 'inbound') AS unread
         FROM messages"
    );

    // ── Today's activity ──────────────────────────────────────
    $todayStats = db()->fetchOne(
        "SELECT
            SUM(outreach_status = 'sent'    AND DATE(last_contacted_at) = CURDATE()) AS sent_today,
            SUM(outreach_status = 'replied' AND DATE(replied_at)        = CURDATE()) AS replied_today,
            COUNT(DATE(created_at) = CURDATE() OR NULL)                              AS leads_added_today
         FROM leads
         WHERE is_archived = 0"
    );

    // ── Campaign stats ────────────────────────────────────────
    $campaignStats = db()->fetchOne(
        "SELECT
            COUNT(*)                          AS total_campaigns,
            SUM(status = 'running')           AS running,
            SUM(status = 'paused')            AS paused,
            SUM(status = 'completed')         AS completed,
            SUM(sent_count)                   AS total_sent,
            SUM(replied_count)                AS total_replied,
            SUM(failed_count)                 AS total_failed
         FROM campaigns"
    );

    // ── Recent leads (last 5 added) ───────────────────────────
    $recentLeads = db()->fetchAll(
        "SELECT id, business_name, city, whatsapp_status, outreach_status, created_at
         FROM leads
         WHERE is_archived = 0
         ORDER BY created_at DESC
         LIMIT 5"
    );

    // ── WhatsApp + queue status from Node ────────────────────
    $waStatusRaw = node()->waStatus();
    $waData      = $waStatusRaw['success'] ? ($waStatusRaw['data'] ?? []) : [];

    $queueRaw    = node()->queueState();
    $queueData   = $queueRaw['success'] ? ($queueRaw['data'] ?? []) : [];

    // ── WhatsApp session from DB (fallback if Node down) ──────
    $waSession = db()->fetchOne(
        "SELECT status, phone_number, display_name, last_connected, last_ping FROM whatsapp_sessions WHERE session_id = 'default' LIMIT 1"
    );

    // ── Reply rate calculation ─────────────────────────────────
    $sent    = (int)($leadStats['outreach_sent']    ?? 0);
    $replied = (int)($leadStats['outreach_replied'] ?? 0);
    $replyRate = $sent > 0 ? round(($replied / $sent) * 100, 1) : 0;

    // ── WA validation rate ────────────────────────────────────
    $total    = (int)($leadStats['total_leads']  ?? 0);
    $valid    = (int)($leadStats['wa_valid']      ?? 0);
    $valRate  = $total > 0 ? round(($valid / $total) * 100, 1) : 0;

    jsonSuccess([
        'leads' => [
            'total'            => (int)($leadStats['total_leads']       ?? 0),
            'wa_valid'         => (int)($leadStats['wa_valid']           ?? 0),
            'wa_invalid'       => (int)($leadStats['wa_invalid']         ?? 0),
            'wa_not_on_wa'     => (int)($leadStats['wa_not_on_wa']       ?? 0),
            'wa_pending'       => (int)($leadStats['wa_pending']         ?? 0),
            'outreach_pending' => (int)($leadStats['outreach_pending']   ?? 0),
            'outreach_queued'  => (int)($leadStats['outreach_queued']    ?? 0),
            'outreach_sent'    => (int)($leadStats['outreach_sent']      ?? 0),
            'outreach_replied' => (int)($leadStats['outreach_replied']   ?? 0),
            'outreach_failed'  => (int)($leadStats['outreach_failed']    ?? 0),
            'outreach_skipped' => (int)($leadStats['outreach_skipped']   ?? 0),
            'has_website'      => (int)($leadStats['has_website']        ?? 0),
            'no_website'       => (int)($leadStats['no_website']         ?? 0),
            'type_a'           => (int)($leadStats['type_a']             ?? 0),
            'type_b'           => (int)($leadStats['type_b']             ?? 0),
            'reply_rate'       => $replyRate,
            'validation_rate'  => $valRate,
        ],
        'messages' => [
            'total'    => (int)($msgStats['total_messages'] ?? 0),
            'inbound'  => (int)($msgStats['inbound']        ?? 0),
            'outbound' => (int)($msgStats['outbound']       ?? 0),
            'unread'   => (int)($msgStats['unread']         ?? 0),
        ],
        'today' => [
            'sent_today'       => (int)($todayStats['sent_today']       ?? 0),
            'replied_today'    => (int)($todayStats['replied_today']    ?? 0),
            'leads_added_today'=> (int)($todayStats['leads_added_today']?? 0),
        ],
        'campaigns' => [
            'total'         => (int)($campaignStats['total_campaigns'] ?? 0),
            'running'       => (int)($campaignStats['running']         ?? 0),
            'paused'        => (int)($campaignStats['paused']          ?? 0),
            'completed'     => (int)($campaignStats['completed']       ?? 0),
            'total_sent'    => (int)($campaignStats['total_sent']      ?? 0),
            'total_replied' => (int)($campaignStats['total_replied']   ?? 0),
            'total_failed'  => (int)($campaignStats['total_failed']    ?? 0),
        ],
        'whatsapp' => [
            'status'         => $waData['status']      ?? $waSession['status']       ?? 'disconnected',
            'phone'          => $waData['phone']       ?? $waSession['phone_number'] ?? null,
            'name'           => $waData['name']        ?? $waSession['display_name'] ?? null,
            'last_connected' => $waSession['last_connected'] ?? null,
            'last_ping'      => $waSession['last_ping']      ?? null,
            'node_online'    => $waStatusRaw['success'],
        ],
        'queue' => [
            'length'     => (int)($queueData['queueLength'] ?? 0),
            'is_running' => (bool)($queueData['isRunning']  ?? false),
            'is_paused'  => (bool)($queueData['isPaused']   ?? false),
            'send_count' => (int)($queueData['sendCount']   ?? 0),
            'daily_limit'=> (int)($queueData['dailyLimit']  ?? 50),
        ],
        'recent_leads' => array_map(fn($l) => [
            'id'              => (int)$l['id'],
            'business_name'   => $l['business_name'],
            'city'            => $l['city'],
            'whatsapp_status' => $l['whatsapp_status'],
            'outreach_status' => $l['outreach_status'],
            'created_at'      => $l['created_at'],
            'time_ago'        => timeAgo($l['created_at']),
        ], $recentLeads),
        'generated_at' => date('c'),
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'get_stats error: ' . $e->getMessage());
    jsonError('Failed to load statistics', 500);
}
