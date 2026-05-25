<?php
// ============================================================
// API: get_messages.php — Conversation thread for a lead
// Returns: full message history, lead summary, unread count
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requireApiAuth();
requireMethod('GET');

// ── Required: lead_id ─────────────────────────────────────────
$leadId = sanitizeInt($_GET['lead_id'] ?? 0, 1);
if ($leadId === 0) {
    jsonError('lead_id is required', 400);
}

$page    = sanitizeInt($_GET['page']     ?? 1,   1, 500);
$perPage = sanitizeInt($_GET['per_page'] ?? 50,  1, 100);
$before  = sanitizeString($_GET['before'] ?? '', 30); // ISO timestamp for pagination

try {
    // ── Verify lead exists ────────────────────────────────────
    $lead = db()->fetchOne(
        "SELECT id, business_name, phone_number, phone_normalized,
                city, state, whatsapp_status, outreach_status,
                generated_message, last_contacted_at, replied_at
         FROM leads WHERE id = ? AND is_archived = 0 LIMIT 1",
        [$leadId]
    );

    if (!$lead) {
        jsonError('Lead not found', 404);
    }

    // ── Build message query ───────────────────────────────────
    $where  = ['lead_id = ?'];
    $params = [$leadId];

    if (!empty($before)) {
        $where[]  = 'timestamp < ?';
        $params[] = $before;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // ── Count total messages ──────────────────────────────────
    $total = (int)(db()->fetchOne(
        "SELECT COUNT(*) AS cnt FROM messages {$whereSQL}",
        $params
    )['cnt'] ?? 0);

    $pagination = paginate($total, $page, $perPage);

    // ── Fetch messages ────────────────────────────────────────
    $messages = db()->fetchAll(
        "SELECT id, sender, message_text, wa_message_id,
                direction, is_read, status, error_message, timestamp, created_at
         FROM messages
         {$whereSQL}
         ORDER BY timestamp ASC
         LIMIT ? OFFSET ?",
        array_merge($params, [$pagination['per_page'], $pagination['offset']])
    );

    // ── Mark inbound messages as read ─────────────────────────
    $unreadIds = array_filter(
        array_column(
            array_filter($messages, fn($m) => $m['direction'] === 'inbound' && !$m['is_read']),
            'id'
        )
    );

    if (!empty($unreadIds)) {
        $placeholders = implode(',', array_fill(0, count($unreadIds), '?'));
        db()->execute(
            "UPDATE messages SET is_read = 1 WHERE id IN ({$placeholders})",
            array_values($unreadIds)
        );
    }

    // ── Format messages ───────────────────────────────────────
    $formatted = array_map(function ($msg) {
        return [
            'id'           => (int)$msg['id'],
            'sender'       => $msg['sender'],
            'message_text' => $msg['message_text'],
            'wa_message_id'=> $msg['wa_message_id'],
            'direction'    => $msg['direction'],
            'is_read'      => (bool)$msg['is_read'],
            'status'       => $msg['status'],
            'error_message'=> $msg['error_message'],
            'timestamp'    => $msg['timestamp'],
            'time_display' => formatDateTime($msg['timestamp']),
            'time_ago'     => timeAgo($msg['timestamp']),
        ];
    }, $messages);

    // ── Unread count (after marking read) ────────────────────
    $remainingUnread = (int)(db()->fetchOne(
        "SELECT COUNT(*) AS cnt FROM messages WHERE lead_id = ? AND is_read = 0 AND direction = 'inbound'",
        [$leadId]
    )['cnt'] ?? 0);

    jsonSuccess([
        'lead' => [
            'id'               => (int)$lead['id'],
            'business_name'    => $lead['business_name'],
            'phone_number'     => $lead['phone_number'],
            'phone_display'    => formatPhoneDisplay($lead['phone_normalized']),
            'city'             => $lead['city'],
            'state'            => $lead['state'],
            'whatsapp_status'  => $lead['whatsapp_status'],
            'outreach_status'  => $lead['outreach_status'],
            'last_contacted_at'=> $lead['last_contacted_at'],
            'replied_at'       => $lead['replied_at'],
            'has_ai_message'   => !empty($lead['generated_message']),
        ],
        'messages'      => $formatted,
        'pagination'    => $pagination,
        'unread_count'  => $remainingUnread,
        'marked_read'   => count($unreadIds),
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'get_messages error: ' . $e->getMessage(), ['lead_id' => $leadId]);
    jsonError('Failed to load messages', 500);
}
