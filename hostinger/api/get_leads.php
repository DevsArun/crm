<?php
// ============================================================
// API: get_leads.php — Paginated lead list with filters
// Supports: search, status filters, sorting, pagination
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requireApiAuth();
requireMethod('GET');

try {
    // ── Query parameters ──────────────────────────────────────
    $page           = sanitizeInt($_GET['page']             ?? 1,   1, 1000);
    $perPage        = sanitizeInt($_GET['per_page']         ?? 30,  1, 100);
    $search         = sanitizeString($_GET['search']        ?? '',  200);
    $waStatus       = sanitizeString($_GET['wa_status']     ?? '',  50);
    $outStatus      = sanitizeString($_GET['outreach_status']?? '', 50);
    $websiteStatus  = sanitizeString($_GET['website_status']?? '',  50);
    $pitchType      = sanitizeString($_GET['pitch_type']    ?? '',  20);
    $city           = sanitizeString($_GET['city']          ?? '',  100);
    $state          = sanitizeString($_GET['state']         ?? '',  100);
    $campaignId     = sanitizeInt($_GET['campaign_id']      ?? 0,   0);
    $isPinned       = isset($_GET['pinned']) ? (int)(bool)$_GET['pinned'] : -1;
    $sortBy         = sanitizeString($_GET['sort']          ?? 'created_at', 30);
    $sortDir        = strtoupper(sanitizeString($_GET['dir']?? 'DESC', 4));

    // ── Validate sort params ──────────────────────────────────
    $allowedSort = ['created_at', 'updated_at', 'business_name', 'city', 'rating', 'last_contacted_at', 'replied_at'];
    if (!in_array($sortBy, $allowedSort)) $sortBy = 'created_at';
    if (!in_array($sortDir, ['ASC', 'DESC'])) $sortDir = 'DESC';

    // ── Build WHERE conditions ─────────────────────────────────
    $where  = ['l.is_archived = 0'];
    $params = [];

    if (!empty($search)) {
        $where[]  = '(l.business_name LIKE ? OR l.phone_number LIKE ? OR l.phone_normalized LIKE ? OR l.city LIKE ? OR l.locality LIKE ?)';
        $like     = '%' . $search . '%';
        $params   = array_merge($params, [$like, $like, $like, $like, $like]);
    }

    $waStatuses = ['valid', 'invalid', 'pending', 'not_on_whatsapp', 'failed'];
    if (!empty($waStatus) && in_array($waStatus, $waStatuses)) {
        $where[]  = 'l.whatsapp_status = ?';
        $params[] = $waStatus;
    }

    $outStatuses = ['pending', 'queued', 'sent', 'replied', 'failed', 'skipped'];
    if (!empty($outStatus) && in_array($outStatus, $outStatuses)) {
        $where[]  = 'l.outreach_status = ?';
        $params[] = $outStatus;
    }

    if (!empty($websiteStatus) && in_array($websiteStatus, ['has_website', 'no_website'])) {
        $where[]  = 'l.website_status = ?';
        $params[] = $websiteStatus;
    }

    if (!empty($pitchType) && in_array($pitchType, ['type_a', 'type_b'])) {
        $where[]  = 'l.pitch_type = ?';
        $params[] = $pitchType;
    }

    if (!empty($city)) {
        $where[]  = 'l.city LIKE ?';
        $params[] = '%' . $city . '%';
    }

    if (!empty($state)) {
        $where[]  = 'l.state LIKE ?';
        $params[] = '%' . $state . '%';
    }

    if ($campaignId > 0) {
        $where[]  = 'l.campaign_id = ?';
        $params[] = $campaignId;
    }

    if ($isPinned >= 0) {
        $where[]  = 'l.is_pinned = ?';
        $params[] = $isPinned;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // ── Count total ───────────────────────────────────────────
    $total = (int)(db()->fetchOne(
        "SELECT COUNT(*) AS cnt FROM leads l {$whereSQL}",
        $params
    )['cnt'] ?? 0);

    // ── Paginate ──────────────────────────────────────────────
    $pagination = paginate($total, $page, $perPage);

    // ── Fetch leads ───────────────────────────────────────────
    $leads = db()->fetchAll(
        "SELECT
            l.id, l.business_name, l.locality, l.city, l.state,
            l.phone_number, l.phone_normalized,
            l.website_url, l.website_status,
            l.rating, l.review_count,
            l.whatsapp_status, l.outreach_status,
            l.pitch_type, l.language_preference,
            l.tags, l.notes, l.is_pinned,
            l.last_contacted_at, l.replied_at, l.created_at, l.updated_at,
            l.generated_message,
            (SELECT COUNT(*) FROM messages m WHERE m.lead_id = l.id) AS message_count,
            (SELECT COUNT(*) FROM messages m WHERE m.lead_id = l.id AND m.is_read = 0 AND m.direction = 'inbound') AS unread_count,
            (SELECT m.message_text FROM messages m WHERE m.lead_id = l.id ORDER BY m.timestamp DESC LIMIT 1) AS last_message,
            (SELECT m.timestamp  FROM messages m WHERE m.lead_id = l.id ORDER BY m.timestamp DESC LIMIT 1) AS last_message_time,
            (SELECT m.direction  FROM messages m WHERE m.lead_id = l.id ORDER BY m.timestamp DESC LIMIT 1) AS last_message_direction
         FROM leads l
         {$whereSQL}
         ORDER BY l.is_pinned DESC, l.{$sortBy} {$sortDir}
         LIMIT ? OFFSET ?",
        array_merge($params, [$pagination['per_page'], $pagination['offset']])
    );

    // ── Format leads ──────────────────────────────────────────
    $formatted = array_map(function ($lead) {
        return [
            'id'                    => (int)$lead['id'],
            'business_name'         => $lead['business_name'],
            'locality'              => $lead['locality'],
            'city'                  => $lead['city'],
            'state'                 => $lead['state'],
            'phone_number'          => $lead['phone_number'],
            'phone_normalized'      => $lead['phone_normalized'],
            'phone_display'         => formatPhoneDisplay($lead['phone_normalized']),
            'website_url'           => $lead['website_url'],
            'website_status'        => $lead['website_status'],
            'rating'                => $lead['rating'] ? (float)$lead['rating'] : null,
            'review_count'          => (int)$lead['review_count'],
            'whatsapp_status'       => $lead['whatsapp_status'],
            'outreach_status'       => $lead['outreach_status'],
            'pitch_type'            => $lead['pitch_type'],
            'language_preference'   => $lead['language_preference'],
            'tags'                  => $lead['tags'] ? json_decode($lead['tags'], true) : [],
            'notes'                 => $lead['notes'],
            'is_pinned'             => (bool)$lead['is_pinned'],
            'has_generated_message' => !empty($lead['generated_message']),
            'message_count'         => (int)$lead['message_count'],
            'unread_count'          => (int)$lead['unread_count'],
            'last_message'          => $lead['last_message'] ? truncate($lead['last_message'], 80) : null,
            'last_message_time'     => $lead['last_message_time'],
            'last_message_direction'=> $lead['last_message_direction'],
            'last_message_ago'      => $lead['last_message_time'] ? timeAgo($lead['last_message_time']) : null,
            'last_contacted_at'     => $lead['last_contacted_at'],
            'last_contacted_ago'    => $lead['last_contacted_at'] ? timeAgo($lead['last_contacted_at']) : null,
            'replied_at'            => $lead['replied_at'],
            'created_at'            => $lead['created_at'],
            'created_ago'           => timeAgo($lead['created_at']),
        ];
    }, $leads);

    jsonSuccess([
        'leads'      => $formatted,
        'pagination' => $pagination,
        'filters'    => compact('search', 'waStatus', 'outStatus', 'websiteStatus', 'pitchType', 'city', 'state', 'campaignId'),
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'get_leads error: ' . $e->getMessage());
    jsonError('Failed to load leads', 500);
}
