<?php
// ============================================================
// WhatsApp CRM OS — CSV Import Script
// Parses uploaded CSV, sanitizes, normalizes, deduplicates,
// detects website status, language preference, pitch type,
// and imports leads into MySQL
// ============================================================

defined('CRM_APP') or define('CRM_APP', true);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

// ── Can be called via CLI or HTTP (from api/upload_csv.php) ──
$isCli = php_sapi_name() === 'cli';

/**
 * Main import function
 *
 * @param string $filePath    Absolute path to CSV file
 * @param int    $importId    DB csv_imports.id (already created)
 * @param int    $campaignId  Optional campaign to assign leads to
 * @return array Import result summary
 */
function importCsvFile(string $filePath, int $importId, int $campaignId = 1): array
{
    $stats = [
        'total'      => 0,
        'imported'   => 0,
        'skipped'    => 0,
        'duplicates' => 0,
        'errors'     => 0,
        'error_log'  => [],
    ];

    if (!file_exists($filePath) || !is_readable($filePath)) {
        return array_merge($stats, ['fatal' => 'File not found or not readable: ' . $filePath]);
    }

    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return array_merge($stats, ['fatal' => 'Cannot open file']);
    }

    // ── Detect BOM and encoding ───────────────────────────────
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle); // No BOM, rewind
    }

    // ── Read header row ───────────────────────────────────────
    $rawHeaders = fgetcsv($handle, 4096, ',');
    if ($rawHeaders === false) {
        fclose($handle);
        return array_merge($stats, ['fatal' => 'Empty or unreadable CSV file']);
    }

    // Normalize headers: lowercase, trim, replace spaces with _
    $headers = array_map(fn($h) => strtolower(trim(preg_replace('/\s+/', '_', $h))), $rawHeaders);

    // ── Map CSV columns to DB fields ──────────────────────────
    $colMap = _buildColumnMap($headers);

    crmLog('info', 'import', 'CSV import started', [
        'import_id'  => $importId,
        'file'       => basename($filePath),
        'headers'    => $headers,
        'col_map'    => $colMap,
    ]);

    // ── Process rows ──────────────────────────────────────────
    $rowNumber = 1;
    $batchSize = 50;
    $batch     = [];

    while (($row = fgetcsv($handle, 4096, ',')) !== false) {
        $rowNumber++;
        $stats['total']++;

        // Skip completely empty rows
        if (empty(array_filter($row, fn($c) => !empty(trim($c))))) {
            $stats['skipped']++;
            continue;
        }

        $result = _processRow($row, $headers, $colMap, $rowNumber, $campaignId);

        if ($result['status'] === 'error') {
            $stats['errors']++;
            $stats['error_log'][] = ['row' => $rowNumber, 'error' => $result['error']];
            continue;
        }

        if ($result['status'] === 'skip') {
            $stats['skipped']++;
            continue;
        }

        $batch[] = $result['lead'];

        // Flush batch to DB
        if (count($batch) >= $batchSize) {
            $batchResult = _insertBatch($batch, $stats);
            $stats['imported']  += $batchResult['inserted'];
            $stats['duplicates'] += $batchResult['duplicates'];
            $batch = [];
        }
    }

    // Flush remaining rows
    if (!empty($batch)) {
        $batchResult = _insertBatch($batch, $stats);
        $stats['imported']  += $batchResult['inserted'];
        $stats['duplicates'] += $batchResult['duplicates'];
    }

    fclose($handle);

    // ── Update import record in DB ────────────────────────────
    try {
        db()->execute(
            "UPDATE csv_imports SET
                total_rows    = ?,
                imported_rows = ?,
                skipped_rows  = ?,
                duplicate_rows = ?,
                error_rows    = ?,
                error_log     = ?,
                status        = 'completed',
                completed_at  = NOW()
             WHERE id = ?",
            [
                $stats['total'],
                $stats['imported'],
                $stats['skipped'],
                $stats['duplicates'],
                $stats['errors'],
                json_encode($stats['error_log']),
                $importId,
            ]
        );
    } catch (Exception $e) {
        crmLog('error', 'import', 'Failed to update import record', ['error' => $e->getMessage()]);
    }

    crmLog('info', 'import', 'CSV import completed', array_merge($stats, ['import_id' => $importId]));

    return $stats;
}

// ── Build column mapping from detected headers ────────────────
function _buildColumnMap(array $headers): array
{
    // Flexible column name aliases
    $aliases = [
        'business_name' => ['business_name', 'name', 'business', 'company', 'company_name', 'shop_name', 'store_name', 'title'],
        'phone'         => ['phone', 'phone_number', 'mobile', 'contact', 'contact_number', 'whatsapp', 'number', 'mob'],
        'address'       => ['address', 'full_address', 'location', 'addr'],
        'website'       => ['website', 'website_url', 'url', 'web', 'site'],
        'rating'        => ['rating', 'stars', 'score', 'google_rating'],
        'reviews'       => ['reviews', 'review_count', 'total_reviews', 'no_of_reviews', 'ratings_count'],
        'city'          => ['city', 'town', 'district'],
        'state'         => ['state', 'province'],
        'locality'      => ['locality', 'area', 'neighborhood', 'neighbourhood', 'zone'],
        'status'        => ['status', 'lead_status'],
    ];

    $map = [];
    foreach ($aliases as $field => $possibleNames) {
        foreach ($possibleNames as $alias) {
            $idx = array_search($alias, $headers);
            if ($idx !== false) {
                $map[$field] = $idx;
                break;
            }
        }
    }

    return $map;
}

// ── Process a single CSV row ──────────────────────────────────
function _processRow(array $row, array $headers, array $colMap, int $rowNumber, int $campaignId): array
{
    $get = fn($field, $default = '') => isset($colMap[$field]) && isset($row[$colMap[$field]])
        ? trim((string)$row[$colMap[$field]])
        : $default;

    // ── Required: business name ───────────────────────────────
    $businessName = sanitizeString($get('business_name'), 255);
    if (empty($businessName)) {
        return ['status' => 'error', 'error' => "Row {$rowNumber}: Missing business name"];
    }

    // ── Required: phone number ────────────────────────────────
    $rawPhone = $get('phone');
    if (empty($rawPhone)) {
        return ['status' => 'skip', 'reason' => 'No phone number'];
    }

    $normalizedPhone = normalizePhone($rawPhone);
    if ($normalizedPhone === null) {
        return ['status' => 'error', 'error' => "Row {$rowNumber}: Invalid phone '{$rawPhone}'"];
    }

    // ── Address fields ────────────────────────────────────────
    $rawAddress = sanitizeString($get('address'), 500);
    $locality   = sanitizeString($get('locality'), 150);
    $city       = sanitizeString($get('city'),     100);
    $state      = sanitizeString($get('state'),    100);

    // Parse address if individual fields not provided
    if (!empty($rawAddress) && (empty($locality) || empty($city))) {
        $parsed   = parseAddress($rawAddress);
        $locality = $locality ?: $parsed['locality'];
        $city     = $city     ?: $parsed['city'];
        $state    = $state    ?: $parsed['state'];
    }

    // ── Website ───────────────────────────────────────────────
    $websiteRaw    = $get('website');
    $websiteUrl    = sanitizeUrl($websiteRaw);
    $websiteStatus = detectWebsiteStatus($websiteUrl ?: $websiteRaw);

    // ── Rating / reviews ──────────────────────────────────────
    $ratingRaw  = $get('rating');
    $rating     = is_numeric($ratingRaw) ? min(5.0, max(0.0, (float)$ratingRaw)) : null;
    $reviewsRaw = $get('reviews');
    $reviews    = is_numeric($reviewsRaw) ? max(0, (int)$reviewsRaw) : 0;

    // ── Derived fields ────────────────────────────────────────
    $pitchType  = $websiteStatus === 'has_website' ? 'type_a' : 'type_b';
    $langPref   = detectLanguagePreference($state, $city);

    return [
        'status' => 'ok',
        'lead'   => [
            'business_name'       => $businessName,
            'address'             => $rawAddress,
            'locality'            => $locality,
            'city'                => $city,
            'state'               => $state,
            'phone_number'        => $rawPhone,
            'phone_normalized'    => $normalizedPhone,
            'website_url'         => $websiteUrl ?: null,
            'website_status'      => $websiteStatus,
            'rating'              => $rating,
            'review_count'        => $reviews,
            'pitch_type'          => $pitchType,
            'language_preference' => $langPref,
            'campaign_id'         => $campaignId,
            'whatsapp_status'     => 'pending',
            'outreach_status'     => 'pending',
        ],
    ];
}

// ── Insert a batch of leads (ignore duplicates) ───────────────
function _insertBatch(array $leads, array &$stats): array
{
    $inserted   = 0;
    $duplicates = 0;

    foreach ($leads as $lead) {
        try {
            db()->execute(
                "INSERT INTO leads (
                    business_name, address, locality, city, state,
                    phone_number, phone_normalized, website_url, website_status,
                    rating, review_count, pitch_type, language_preference,
                    campaign_id, whatsapp_status, outreach_status,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    business_name       = IF(business_name = '', VALUES(business_name), business_name),
                    website_url         = IF(website_url IS NULL, VALUES(website_url), website_url),
                    website_status      = IF(website_status = 'no_website', VALUES(website_status), website_status),
                    rating              = IF(rating IS NULL, VALUES(rating), rating),
                    review_count        = IF(review_count = 0, VALUES(review_count), review_count),
                    updated_at          = NOW()",
                [
                    $lead['business_name'],
                    $lead['address'],
                    $lead['locality'],
                    $lead['city'],
                    $lead['state'],
                    $lead['phone_number'],
                    $lead['phone_normalized'],
                    $lead['website_url'],
                    $lead['website_status'],
                    $lead['rating'],
                    $lead['review_count'],
                    $lead['pitch_type'],
                    $lead['language_preference'],
                    $lead['campaign_id'],
                    $lead['whatsapp_status'],
                    $lead['outreach_status'],
                ]
            );

            $inserted++;

        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $duplicates++;
            } else {
                crmLog('error', 'import', 'Row insert error: ' . $e->getMessage(), [
                    'phone' => $lead['phone_normalized'],
                ]);
                $stats['errors']++;
            }
        }
    }

    return ['inserted' => $inserted, 'duplicates' => $duplicates];
}
