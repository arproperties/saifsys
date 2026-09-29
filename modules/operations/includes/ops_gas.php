<?php
/**
 * Operations — R410 gas weighed before and after, per unit, on maintenance jobs.
 *
 * The technician photographs the scale and types the kg before using the
 * cylinder, and again after. One reading per unit per use. A job cannot finish
 * while a reading has a Before and no After. See
 * migrations/ops_job_gas_readings.sql.
 */

/** Heavier than any cylinder the team carries; catches a typed 1675 for 16.75. */
const OPS_GAS_MAX_KG = 100.0;

/** Does this job carry the gas section: maintenance only, where the AC work is. */
function ops_job_has_gas(array $job): bool {
    return ($job['job_type'] ?? '') === 'maintenance';
}

/**
 * A typed weight in kg, or null when it is not one. Accepts a comma for the
 * decimal point — half the phones in the team default to it.
 */
function ops_gas_parse_kg($raw): ?float {
    $text = str_replace(',', '.', trim((string)$raw));
    if ($text === '' || !preg_match('/^\d{1,3}(\.\d{1,3})?$/', $text)) {
        return null;
    }
    $kg = (float)$text;
    return $kg > 0 && $kg <= OPS_GAS_MAX_KG ? $kg : null;
}

/** "16.750" → "16.75 kg", "16.000" → "16 kg". */
function ops_gas_kg_label($kg): string {
    return rtrim(rtrim(number_format((float)$kg, 3, '.', ''), '0'), '.') . ' kg';
}

/** Every reading on a job, oldest first. Empty on a database without the migration. */
function ops_gas_readings(PDO $conn, int $jobId): array {
    try {
        $stmt = $conn->prepare("
            SELECT g.*,
                   COALESCE(NULLIF(bu.fullname, ''), bu.username) AS before_by_name,
                   COALESCE(NULLIF(au.fullname, ''), au.username) AS after_by_name
            FROM ops_job_gas_readings g
            LEFT JOIN user bu ON bu.id = g.before_by
            LEFT JOIN user au ON au.id = g.after_by
            WHERE g.job_id = ?
            ORDER BY g.before_at ASC, g.id ASC
        ");
        $stmt->execute([$jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('ops_gas_readings failed: ' . $e->getMessage());
        return [];
    }
}

/** How many readings on a job are still waiting for their After weight. */
function ops_gas_open_count(PDO $conn, int $jobId): int {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM ops_job_gas_readings WHERE job_id = ? AND after_kg IS NULL");
        $stmt->execute([$jobId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('ops_gas_open_count failed: ' . $e->getMessage());
        return 0;
    }
}

/** Before minus After, or null while the After is missing. */
function ops_gas_used_kg(array $reading): ?float {
    if ($reading['after_kg'] === null) {
        return null;
    }
    return round((float)$reading['before_kg'] - (float)$reading['after_kg'], 3);
}

/**
 * Save the photo of the scale. Stills only — the number has to be readable,
 * and a clip adds nothing to that. Checked the same way as the Before/After
 * evidence: extension whitelist, then the bytes must agree.
 *
 * @return array{ok: bool, error?: string, file_path?: string}
 */
function ops_gas_store_photo(int $jobId, array $file, string $stage): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The photo of the scale did not upload. Please try again.'];
    }
    $maxBytes = ops_comment_media_max_bytes('photo');
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => sprintf('The photo must be %d MB or smaller.', (int)round($maxBytes / 1048576))];
    }
    if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    $allowed = ops_photo_media_types('photo');
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, GIF or WEBP photos are allowed.'];
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!in_array($mime, $allowed[$ext], true) || !isset($mimeMap[$mime])) {
        return ['ok' => false, 'error' => 'That file is not a photo.'];
    }

    // Under uploads/operations, so the folder's deny-all .htaccess covers it and
    // the same containment check as every other job file applies when serving.
    $relDir = 'uploads/operations/gas/' . $jobId;
    $absDir = dirname(__DIR__, 3) . '/' . $relDir;
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        return ['ok' => false, 'error' => 'Server could not create the photo folder.'];
    }
    $name = ($stage === 'after' ? 'after' : 'before') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $mimeMap[$mime];
    if (!move_uploaded_file($file['tmp_name'], $absDir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Server could not save the photo.'];
    }
    @chmod($absDir . '/' . $name, 0644);

    return ['ok' => true, 'file_path' => $relDir . '/' . $name];
}

/** Remove a stored scale photo that no row ended up pointing at. */
function ops_gas_discard_photo(string $relPath): void {
    $abs = dirname(__DIR__, 3) . '/' . ltrim($relPath, '/');
    if (is_file($abs)) {
        @unlink($abs);
    }
}

/**
 * Resolve a scale photo's stored path to a servable file and its type, or null.
 * The path must sit inside uploads/operations and be a still.
 *
 * @return array{path: string, type: string}|null
 */
function ops_gas_photo_file(?string $relPath): ?array {
    if ($relPath === null || $relPath === '') {
        return null;
    }
    $root = dirname(__DIR__, 3);
    $baseDir = realpath($root . '/uploads/operations');
    $absPath = realpath($root . '/' . ltrim($relPath, '/'));
    if ($baseDir === false || $absPath === false
        || strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($absPath)) {
        return null;
    }
    $type = ops_comment_media_content_type(strtolower(pathinfo($absPath, PATHINFO_EXTENSION)));
    if ($type === null || strpos($type, 'image/') !== 0) {
        return null;
    }
    return ['path' => $absPath, 'type' => $type];
}

/**
 * The gas section as the app reads it, or null on jobs that have none.
 */
function ops_gas_for_app(PDO $conn, array $job): ?array {
    if (!ops_job_has_gas($job)) {
        return null;
    }
    $readings = array_map(static function (array $r): array {
        $used = ops_gas_used_kg($r);
        return [
            'id' => (int)$r['id'],
            'client_ref' => (string)$r['client_ref'],
            'place_kind' => $r['place_kind'] !== null ? (string)$r['place_kind'] : null,
            'place_id' => $r['place_id'] !== null ? (int)$r['place_id'] : null,
            'unit_label' => (string)$r['unit_label'],
            'before_kg' => (float)$r['before_kg'],
            'before_at' => (string)$r['before_at'],
            'before_photo_path' => 'gas-photos/' . (int)$r['id'] . '/before',
            'after_kg' => $r['after_kg'] !== null ? (float)$r['after_kg'] : null,
            'after_at' => $r['after_at'] !== null ? (string)$r['after_at'] : null,
            'after_photo_path' => $r['after_photo'] !== null ? 'gas-photos/' . (int)$r['id'] . '/after' : null,
            'used_kg' => $used,
        ];
    }, ops_gas_readings($conn, (int)$job['id']));

    return [
        'readings' => $readings,
        // The Finish answer: null not asked yet, false "no gas", true used.
        'used' => isset($job['gas_used']) ? (bool)(int)$job['gas_used'] : null,
    ];
}
