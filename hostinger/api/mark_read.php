<?php
// ============================================================
// API: mark_read.php — Mark messages as read
// Supports: single lead, single message, or all unread
// Also handles: pin/unpin lead, archive/unarchive, update notes/tags
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requireApiAuth();
requireMethod('POST');

$body   = getJsonBody();
$action = sanitizeString($body['action'] ?? 'mark_read', 30);

try {
    switch ($action) {

        // ── Mark all inbound messages of a lead as read ───────
        case 'mark_read': {
            $leadId = sanitizeInt($body['lead_id'] ?? 0, 1);
            if ($leadId === 0) jsonError('lead_id required', 400);

            $affected = db()->execute(
                "UPDATE messages SET is_read = 1
                 WHERE lead_id = ? AND direction = 'inbound' AND is_read = 0",
                [$leadId]
            );

            jsonSuccess(['marked_read' => $affected, 'lead_id' => $leadId]);
            break;
        }

        // ── Mark a specific message as read ───────────────────
        case 'mark_message_read': {
            $messageId = sanitizeInt($body['message_id'] ?? 0, 1);
            if ($messageId === 0) jsonError('message_id required', 400);

            $affected = db()->execute(
                "UPDATE messages SET is_read = 1 WHERE id = ?",
                [$messageId]
            );

            jsonSuccess(['marked_read' => $affected, 'message_id' => $messageId]);
            break;
        }

        // ── Mark ALL unread messages as read ──────────────────
        case 'mark_all_read': {
            $affected = db()->execute(
                "UPDATE messages SET is_read = 1 WHERE direction = 'inbound' AND is_read = 0"
            );

            jsonSuccess(['marked_read' => $affected]);
            break;
        }

        // ── Pin / unpin a lead ────────────────────────────────
        case 'toggle_pin': {
            $leadId = sanitizeInt($body['lead_id'] ?? 0, 1);
            if ($leadId === 0) jsonError('lead_id required', 400);

            $lead = db()->fetchOne("SELECT is_pinned FROM leads WHERE id = ? LIMIT 1", [$leadId]);
            if (!$lead) jsonError('Lead not found', 404);

            $newState = $lead['is_pinned'] ? 0 : 1;
            db()->execute("UPDATE leads SET is_pinned = ?, updated_at = NOW() WHERE id = ?", [$newState, $leadId]);

            jsonSuccess(['pinned' => (bool)$newState, 'lead_id' => $leadId]);
            break;
        }

        // ── Archive / unarchive a lead ────────────────────────
        case 'toggle_archive': {
            $leadId = sanitizeInt($body['lead_id'] ?? 0, 1);
            if ($leadId === 0) jsonError('lead_id required', 400);

            $lead = db()->fetchOne("SELECT is_archived FROM leads WHERE id = ? LIMIT 1", [$leadId]);
            if (!$lead) jsonError('Lead not found', 404);

            $newState = $lead['is_archived'] ? 0 : 1;
            db()->execute("UPDATE leads SET is_archived = ?, updated_at = NOW() WHERE id = ?", [$newState, $leadId]);

            jsonSuccess(['archived' => (bool)$newState, 'lead_id' => $leadId]);
            break;
        }

        // ── Update lead notes ─────────────────────────────────
        case 'update_notes': {
            $leadId = sanitizeInt($body['lead_id'] ?? 0, 1);
            $notes  = sanitizeString($body['notes'] ?? '', 2000);
            if ($leadId === 0) jsonError('lead_id required', 400);

            db()->execute(
                "UPDATE leads SET notes = ?, updated_at = NOW() WHERE id = ?",
                [$notes, $leadId]
            );

            jsonSuccess(['updated' => true, 'lead_id' => $leadId]);
            break;
        }

        // ── Update lead tags ──────────────────────────────────
        case 'update_tags': {
            $leadId = sanitizeInt($body['lead_id'] ?? 0, 1);
            $tags   = $body['tags'] ?? [];
            if ($leadId === 0) jsonError('lead_id required', 400);

            if (!is_array($tags)) {
                jsonError('tags must be an array', 400);
            }

            // Sanitize each tag
            $cleanTags = array_slice(
                array_map(fn($t) => sanitizeString($t, 50), $tags),
                0, 10  // max 10 tags
            );

            db()->execute(
                "UPDATE leads SET tags = ?, updated_at = NOW() WHERE id = ?",
                [json_encode($cleanTags), $leadId]
            );

            jsonSuccess(['updated' => true, 'tags' => $cleanTags, 'lead_id' => $leadId]);
            break;
        }

        // ── Update outreach status manually ───────────────────
        case 'update_outreach_status': {
            $leadId = sanitizeInt($body['lead_id'] ?? 0, 1);
            $status = sanitizeString($body['status'] ?? '', 20);

            if ($leadId === 0) jsonError('lead_id required', 400);

            $valid = ['pending', 'queued', 'sent', 'replied', 'failed', 'skipped'];
            if (!in_array($status, $valid)) {
                jsonError('Invalid status. Allowed: ' . implode(', ', $valid), 400);
            }

            db()->execute(
                "UPDATE leads SET outreach_status = ?, updated_at = NOW() WHERE id = ?",
                [$status, $leadId]
            );

            jsonSuccess(['updated' => true, 'status' => $status, 'lead_id' => $leadId]);
            break;
        }

        // ── Delete a lead (soft: archive only) ────────────────
        case 'delete_lead': {
            $leadId = sanitizeInt($body['lead_id'] ?? 0, 1);
            if ($leadId === 0) jsonError('lead_id required', 400);

            // Soft delete by archiving
            db()->execute(
                "UPDATE leads SET is_archived = 1, updated_at = NOW() WHERE id = ?",
                [$leadId]
            );

            crmLog('info', 'api', "Lead {$leadId} deleted (archived)", []);
            jsonSuccess(['deleted' => true, 'lead_id' => $leadId]);
            break;
        }

        default:
            jsonError("Unknown action: {$action}", 400);
    }

} catch (Exception $e) {
    crmLog('error', 'api', 'mark_read error: ' . $e->getMessage(), ['action' => $action]);
    jsonError('Action failed', 500);
}
