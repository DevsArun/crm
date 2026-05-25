<?php
// ============================================================
// API: get_lead_details.php — Full lead intelligence panel
// Returns: complete lead profile, activity timeline,
//          message history, AI reasoning, outreach analytics
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requireApiAuth();
requireMethod('GET');

$leadId = sanitizeInt($_GET['lead_id'] ?? 0, 1);
if ($leadId === 0) {
    jsonError('lead_id is required', 400);
}

try {
    // ── Full lead row ─────────────────────────────────────────
    $lead = db()->fetchOne(
        "SELECT * FROM leads WHERE id = ? LIMIT 1",
        [$leadId]
    );

    if (!$lead) {
        jsonError('Lead not found', 404);
    }

    // ── Message statistics for this lead ──────────────────────
    $msgStats = db()->fetchOne(
        "SELECT
            COUNT(*)                                AS total_messages,
            SUM(direction = 'inbound')              AS inbound_count,
            SUM(direction = 'outbound')             AS outbound_count,
            SUM(is_read = 0 AND direction='inbound') AS unread_count,
            MIN(timestamp)                          AS first_message_at,
            MAX(timestamp)                          AS last_message_at,
            SUM(status = 'delivered')               AS delivered_count,
            SUM(status = 'read')                    AS read_by_lead_count
         FROM messages WHERE lead_id = ?",
        [$leadId]
    );

    // ── Recent messages (last 20) ─────────────────────────────
    $recentMessages = db()->fetchAll(
        "SELECT id, sender, message_text, direction, is_read,
                status, wa_message_id, timestamp
         FROM messages
         WHERE lead_id = ?
         ORDER BY timestamp DESC
         LIMIT 20",
        [$leadId]
    );

    // ── Activity timeline ─────────────────────────────────────
    $timeline = _buildTimeline($lead, $recentMessages);

    // ── Campaign details ──────────────────────────────────────
    $campaign = null;
    if (!empty($lead['campaign_id'])) {
        $campaign = db()->fetchOne(
            "SELECT id, name, status, sent_count, replied_count, created_at FROM campaigns WHERE id = ? LIMIT 1",
            [(int)$lead['campaign_id']]
        );
    }

    // ── Outreach analytics ────────────────────────────────────
    $analytics = _buildAnalytics($lead, $msgStats);

    // ── Lead intelligence tags ────────────────────────────────
    $intelligence = _buildIntelligence($lead);

    // ── Format response ───────────────────────────────────────
    jsonSuccess([
        'lead' => [
            'id'                  => (int)$lead['id'],
            'business_name'       => $lead['business_name'],
            'address'             => $lead['address'],
            'locality'            => $lead['locality'],
            'city'                => $lead['city'],
            'state'               => $lead['state'],
            'phone_number'        => $lead['phone_number'],
            'phone_normalized'    => $lead['phone_normalized'],
            'phone_display'       => formatPhoneDisplay($lead['phone_normalized']),
            'website_url'         => $lead['website_url'],
            'website_status'      => $lead['website_status'],
            'rating'              => $lead['rating'] ? (float)$lead['rating'] : null,
            'review_count'        => (int)$lead['review_count'],
            'whatsapp_status'     => $lead['whatsapp_status'],
            'outreach_status'     => $lead['outreach_status'],
            'pitch_type'          => $lead['pitch_type'],
            'language_preference' => $lead['language_preference'],
            'generated_message'   => $lead['generated_message'],
            'ai_reasoning'        => $lead['ai_reasoning'],
            'tags'                => $lead['tags'] ? json_decode($lead['tags'], true) : [],
            'notes'               => $lead['notes'],
            'is_pinned'           => (bool)$lead['is_pinned'],
            'is_archived'         => (bool)$lead['is_archived'],
            'campaign_id'         => $lead['campaign_id'] ? (int)$lead['campaign_id'] : null,
            'last_contacted_at'   => $lead['last_contacted_at'],
            'last_contacted_ago'  => $lead['last_contacted_at'] ? timeAgo($lead['last_contacted_at']) : null,
            'replied_at'          => $lead['replied_at'],
            'replied_ago'         => $lead['replied_at'] ? timeAgo($lead['replied_at']) : null,
            'created_at'          => $lead['created_at'],
            'created_ago'         => timeAgo($lead['created_at']),
            'updated_at'          => $lead['updated_at'],
        ],
        'messages' => [
            'total'              => (int)($msgStats['total_messages']    ?? 0),
            'inbound'            => (int)($msgStats['inbound_count']     ?? 0),
            'outbound'           => (int)($msgStats['outbound_count']    ?? 0),
            'unread'             => (int)($msgStats['unread_count']      ?? 0),
            'delivered'          => (int)($msgStats['delivered_count']   ?? 0),
            'read_by_lead'       => (int)($msgStats['read_by_lead_count']?? 0),
            'first_message_at'   => $msgStats['first_message_at']        ?? null,
            'last_message_at'    => $msgStats['last_message_at']         ?? null,
            'recent'             => array_map(fn($m) => [
                'id'           => (int)$m['id'],
                'sender'       => $m['sender'],
                'message_text' => $m['message_text'],
                'direction'    => $m['direction'],
                'is_read'      => (bool)$m['is_read'],
                'status'       => $m['status'],
                'timestamp'    => $m['timestamp'],
                'time_ago'     => timeAgo($m['timestamp']),
            ], array_reverse($recentMessages)),
        ],
        'campaign'     => $campaign,
        'analytics'    => $analytics,
        'intelligence' => $intelligence,
        'timeline'     => $timeline,
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'get_lead_details error: ' . $e->getMessage(), ['lead_id' => $leadId]);
    jsonError('Failed to load lead details', 500);
}

// ── Build activity timeline ───────────────────────────────────
function _buildTimeline(array $lead, array $messages): array
{
    $events = [];

    // Lead created
    $events[] = [
        'type'      => 'created',
        'label'     => 'Lead imported',
        'icon'      => 'plus',
        'color'     => 'indigo',
        'timestamp' => $lead['created_at'],
        'time_ago'  => timeAgo($lead['created_at']),
    ];

    // WA validated
    if ($lead['whatsapp_status'] !== 'pending') {
        $events[] = [
            'type'      => 'validated',
            'label'     => 'WhatsApp: ' . ucfirst(str_replace('_', ' ', $lead['whatsapp_status'])),
            'icon'      => $lead['whatsapp_status'] === 'valid' ? 'check' : 'x',
            'color'     => $lead['whatsapp_status'] === 'valid' ? 'emerald' : 'red',
            'timestamp' => $lead['updated_at'],
            'time_ago'  => timeAgo($lead['updated_at']),
        ];
    }

    // AI message generated
    if (!empty($lead['generated_message'])) {
        $events[] = [
            'type'      => 'ai_generated',
            'label'     => 'AI message generated',
            'icon'      => 'sparkles',
            'color'     => 'violet',
            'timestamp' => $lead['updated_at'],
            'time_ago'  => timeAgo($lead['updated_at']),
        ];
    }

    // Last contacted
    if (!empty($lead['last_contacted_at'])) {
        $events[] = [
            'type'      => 'contacted',
            'label'     => 'First message sent',
            'icon'      => 'send',
            'color'     => 'blue',
            'timestamp' => $lead['last_contacted_at'],
            'time_ago'  => timeAgo($lead['last_contacted_at']),
        ];
    }

    // Replied
    if (!empty($lead['replied_at'])) {
        $events[] = [
            'type'      => 'replied',
            'label'     => 'Lead replied — manual follow-up mode',
            'icon'      => 'message',
            'color'     => 'emerald',
            'timestamp' => $lead['replied_at'],
            'time_ago'  => timeAgo($lead['replied_at']),
        ];
    }

    // Add message events (last 5)
    $msgEvents = array_slice($messages, 0, 5);
    foreach ($msgEvents as $msg) {
        $events[] = [
            'type'      => $msg['direction'] === 'inbound' ? 'message_in' : 'message_out',
            'label'     => $msg['direction'] === 'inbound'
                ? 'Message received: ' . truncate($msg['message_text'], 50)
                : 'Message sent: '     . truncate($msg['message_text'], 50),
            'icon'      => $msg['direction'] === 'inbound' ? 'arrow-down' : 'arrow-up',
            'color'     => $msg['direction'] === 'inbound' ? 'emerald' : 'blue',
            'timestamp' => $msg['timestamp'],
            'time_ago'  => timeAgo($msg['timestamp']),
        ];
    }

    // Sort by timestamp desc
    usort($events, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));

    return $events;
}

// ── Build outreach analytics ──────────────────────────────────
function _buildAnalytics(array $lead, array $msgStats): array
{
    $conversationDepth = (int)($msgStats['total_messages'] ?? 0);
    $hasReplied        = $lead['outreach_status'] === 'replied';
    $wasSent           = in_array($lead['outreach_status'], ['sent', 'replied']);

    return [
        'conversation_depth'  => $conversationDepth,
        'has_replied'         => $hasReplied,
        'was_sent'            => $wasSent,
        'engagement_score'    => _calcEngagementScore($lead, $msgStats),
        'days_since_contact'  => !empty($lead['last_contacted_at'])
            ? (int)((time() - strtotime($lead['last_contacted_at'])) / 86400)
            : null,
        'time_to_reply'       => (!empty($lead['last_contacted_at']) && !empty($lead['replied_at']))
            ? _humanDuration(strtotime($lead['replied_at']) - strtotime($lead['last_contacted_at']))
            : null,
        'outreach_channel'    => 'whatsapp',
        'pitch_type_label'    => $lead['pitch_type'] === 'type_a'
            ? 'Digital Growth (Has Website)'
            : 'Digital Presence (No Website)',
    ];
}

function _calcEngagementScore(array $lead, array $msgStats): string
{
    $score = 0;
    if ($lead['whatsapp_status'] === 'valid')       $score += 20;
    if (!empty($lead['generated_message']))          $score += 20;
    if (in_array($lead['outreach_status'], ['sent', 'replied'])) $score += 30;
    if ($lead['outreach_status'] === 'replied')      $score += 30;

    if ($score >= 90) return 'hot';
    if ($score >= 60) return 'warm';
    if ($score >= 30) return 'cool';
    return 'cold';
}

function _humanDuration(int $seconds): string
{
    if ($seconds < 60)   return "{$seconds}s";
    if ($seconds < 3600) return floor($seconds/60) . 'm';
    if ($seconds < 86400) return floor($seconds/3600) . 'h';
    return floor($seconds/86400) . 'd';
}

// ── Build lead intelligence object ───────────────────────────
function _buildIntelligence(array $lead): array
{
    $signals = [];

    if ($lead['website_status'] === 'has_website') {
        $signals[] = ['label' => 'Has Website', 'type' => 'positive', 'icon' => 'globe'];
    } else {
        $signals[] = ['label' => 'No Website', 'type' => 'opportunity', 'icon' => 'globe-off'];
    }

    $rating = (float)($lead['rating'] ?? 0);
    if ($rating >= 4.5) {
        $signals[] = ['label' => "Rated {$rating}★ — Excellent", 'type' => 'positive', 'icon' => 'star'];
    } elseif ($rating >= 4.0) {
        $signals[] = ['label' => "Rated {$rating}★ — Well rated", 'type' => 'positive', 'icon' => 'star'];
    } elseif ($rating > 0) {
        $signals[] = ['label' => "Rated {$rating}★", 'type' => 'neutral', 'icon' => 'star'];
    }

    $reviews = (int)($lead['review_count'] ?? 0);
    if ($reviews >= 100) {
        $signals[] = ['label' => "{$reviews} reviews — High visibility", 'type' => 'positive', 'icon' => 'users'];
    } elseif ($reviews >= 20) {
        $signals[] = ['label' => "{$reviews} reviews — Active business", 'type' => 'positive', 'icon' => 'users'];
    }

    $lang = $lead['language_preference'] ?? 'english';
    $signals[] = ['label' => 'Language: ' . ucfirst(str_replace('_', ' + ', $lang)), 'type' => 'info', 'icon' => 'language'];

    $pitch = $lead['pitch_type'] === 'type_a'
        ? 'Type A — Conversion & Growth'
        : 'Type B — Digital Presence';
    $signals[] = ['label' => $pitch, 'type' => 'info', 'icon' => 'target'];

    return [
        'signals'           => $signals,
        'recommended_angle' => $lead['pitch_type'] === 'type_a'
            ? 'Focus on AI automation, conversion optimization, and digital growth tools'
            : 'Focus on building digital presence, website credibility, and online discoverability',
        'language_tone'     => $lang,
    ];
}
