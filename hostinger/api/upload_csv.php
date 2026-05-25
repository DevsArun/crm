<?php
// ============================================================
// API: upload_csv.php — CSV file upload + import trigger
// Handles: multipart file upload, validation, import execution
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../scripts/import_csv.php';

requireApiAuth();
requireMethod('POST');

try {
    // ── Validate file upload ──────────────────────────────────
    if (empty($_FILES['csv_file'])) {
        jsonError('No file uploaded. Use field name: csv_file', 400);
    }

    $file     = $_FILES['csv_file'];
    $error    = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'File too large (exceeds server limit)',
            UPLOAD_ERR_FORM_SIZE  => 'File too large (exceeds form limit)',
            UPLOAD_ERR_PARTIAL    => 'File upload was interrupted',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write file',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension',
        ];
        jsonError($errors[$error] ?? "Upload error code: {$error}", 400);
    }

    // ── File size check ───────────────────────────────────────
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        jsonError('File too large. Maximum allowed: ' . (UPLOAD_MAX_SIZE / 1024 / 1024) . 'MB', 400);
    }

    if ($file['size'] === 0) {
        jsonError('Uploaded file is empty', 400);
    }

    // ── MIME type validation ──────────────────────────────────
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    $allowedMimes = [
        'text/csv', 'text/plain', 'application/csv',
        'application/vnd.ms-excel', 'application/octet-stream',
    ];

    // Also check file extension as fallback
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($mimeType, $allowedMimes) && !in_array($ext, ['csv', 'txt'])) {
        jsonError("Invalid file type: {$mimeType}. Only CSV files allowed.", 400);
    }

    // ── Generate safe filename ────────────────────────────────
    $safeFilename  = 'import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
    $uploadPath    = UPLOAD_DIR . $safeFilename;

    // ── Ensure upload directory exists ────────────────────────
    if (!is_dir(UPLOAD_DIR)) {
        if (!mkdir(UPLOAD_DIR, 0755, true)) {
            jsonError('Upload directory not accessible', 500);
        }
    }

    // ── Move uploaded file ────────────────────────────────────
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        jsonError('Failed to save uploaded file. Check directory permissions.', 500);
    }

    // ── Get optional parameters ───────────────────────────────
    $campaignId = sanitizeInt($_POST['campaign_id'] ?? 1, 1);

    // ── Create import record in DB ────────────────────────────
    $importId = db()->insert(
        "INSERT INTO csv_imports (filename, original_name, status, imported_by, created_at)
         VALUES (?, ?, 'processing', 'admin', NOW())",
        [$safeFilename, sanitizeString($file['name'], 255)]
    );

    crmLog('info', 'api', 'CSV upload started', [
        'import_id'   => $importId,
        'filename'    => $safeFilename,
        'original'    => $file['name'],
        'size_bytes'  => $file['size'],
        'campaign_id' => $campaignId,
    ]);

    // ── Run import ────────────────────────────────────────────
    // For large files (>500 rows), consider background processing
    // For standard Hostinger hosting, run inline with extended timeout
    set_time_limit(300); // Allow up to 5 minutes for large CSVs
    ini_set('memory_limit', '256M');

    $result = importCsvFile($uploadPath, $importId, $campaignId);

    if (!empty($result['fatal'])) {
        // Update import record as failed
        db()->execute(
            "UPDATE csv_imports SET status = 'failed', error_log = ?, completed_at = NOW() WHERE id = ?",
            [json_encode(['fatal' => $result['fatal']]), $importId]
        );

        jsonError('Import failed: ' . $result['fatal'], 500);
    }

    // ── Fetch final import record ─────────────────────────────
    $importRecord = db()->fetchOne(
        "SELECT * FROM csv_imports WHERE id = ? LIMIT 1",
        [$importId]
    );

    // ── Get total leads in campaign now ───────────────────────
    $campaignLeadCount = (int)(db()->fetchOne(
        "SELECT COUNT(*) AS cnt FROM leads WHERE campaign_id = ? AND is_archived = 0",
        [$campaignId]
    )['cnt'] ?? 0);

    // Update campaign total_leads
    db()->execute(
        "UPDATE campaigns SET total_leads = ?, updated_at = NOW() WHERE id = ?",
        [$campaignLeadCount, $campaignId]
    );

    crmLog('info', 'api', 'CSV import completed', [
        'import_id' => $importId,
        'stats'     => $result,
    ]);

    jsonSuccess([
        'import_id'     => $importId,
        'filename'      => $file['name'],
        'campaign_id'   => $campaignId,
        'stats' => [
            'total_rows'    => (int)($result['total']      ?? 0),
            'imported'      => (int)($result['imported']   ?? 0),
            'duplicates'    => (int)($result['duplicates'] ?? 0),
            'skipped'       => (int)($result['skipped']    ?? 0),
            'errors'        => (int)($result['errors']     ?? 0),
        ],
        'campaign_total_leads' => $campaignLeadCount,
        'import_record'   => $importRecord,
        'message'         => sprintf(
            '%d leads imported. %d duplicates skipped. %d errors.',
            (int)($result['imported']   ?? 0),
            (int)($result['duplicates'] ?? 0),
            (int)($result['errors']     ?? 0)
        ),
        'next_steps' => [
            '1' => 'Run WhatsApp validation to check which numbers are active',
            '2' => 'Generate AI messages for valid leads',
            '3' => 'Start campaign to begin outreach',
        ],
    ]);

} catch (Exception $e) {
    crmLog('error', 'api', 'upload_csv error: ' . $e->getMessage());
    jsonError('CSV upload failed: ' . $e->getMessage(), 500);
}
