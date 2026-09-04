<?php
/**
 * Tenant tenancy contract — offline sign + PDF scan upload (tenant portal).
 */

if (!defined('TENANCY_TENANT_SCAN_MAX_BYTES')) {
    define('TENANCY_TENANT_SCAN_MAX_BYTES', 10 * 1024 * 1024);
}

function tenancy_contract_scan_table_exists(PDO $conn): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $conn->query('SELECT 1 FROM re_tenancy_contract_tenant_uploads LIMIT 1');
        $cache = true;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/**
 * Latest non-superseded tenant scan for this lease (one “current” row).
 */
function tenancy_contract_fetch_active_tenant_scan(PDO $conn, int $leaseId, int $companyId): ?array {
    if (!tenancy_contract_scan_table_exists($conn)) {
        return null;
    }
    $st = $conn->prepare("
        SELECT *
        FROM re_tenancy_contract_tenant_uploads
        WHERE company_id = ? AND lease_id = ? AND superseded_at IS NULL
        ORDER BY uploaded_at DESC, id DESC
        LIMIT 1
    ");
    $st->execute([$companyId, $leaseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Save PDF scan; supersedes any previous active upload for this lease.
 *
 * @return array{ok:bool, message:string, row?:array}
 */
function tenancy_contract_save_tenant_scan_pdf(
    PDO $conn,
    array $lease,
    array $file,
    int $tpuId,
    int $legacyUserId
): array {
    if (!tenancy_contract_scan_table_exists($conn)) {
        return ['ok' => false, 'message' => 'This feature is not available yet. Ask your administrator to run the latest database migration (tenancy_contract_tenant_scan_upload.sql).'];
    }
    $companyId = (int)($lease['company_id'] ?? 0);
    $leaseId = (int)($lease['lease_id'] ?? 0);
    if ($companyId <= 0 || $leaseId <= 0) {
        return ['ok' => false, 'message' => 'Invalid lease.'];
    }
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Please choose a PDF file to upload.'];
    }
    if (($file['size'] ?? 0) > TENANCY_TENANT_SCAN_MAX_BYTES) {
        return ['ok' => false, 'message' => 'File is too large (maximum 10 MB).'];
    }
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return ['ok' => false, 'message' => 'Only PDF files are accepted for the signed contract scan.'];
    }
    $mime = 'application/pdf';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $detected = finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
            if ($detected && !in_array($detected, ['application/pdf', 'application/x-pdf'], true)) {
                return ['ok' => false, 'message' => 'The file does not appear to be a valid PDF.'];
            }
        }
    }

    $base = dirname(__DIR__, 2);
    $dir = $base . '/uploads/tenancy_tenant_scans/' . $companyId . '/' . $leaseId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'message' => 'Could not create upload directory. Please contact support.'];
    }
    $safe = 'signed_scan_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $abs = $dir . '/' . $safe;
    if (!move_uploaded_file($file['tmp_name'], $abs)) {
        return ['ok' => false, 'message' => 'Could not save the uploaded file.'];
    }
    $rel = 'uploads/tenancy_tenant_scans/' . $companyId . '/' . $leaseId . '/' . $safe;
    $size = (int)filesize($abs);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen($ua) > 500) {
        $ua = substr($ua, 0, 500);
    }

    try {
        $conn->beginTransaction();
        $conn->prepare("
            UPDATE re_tenancy_contract_tenant_uploads
            SET superseded_at = NOW()
            WHERE lease_id = ? AND company_id = ? AND superseded_at IS NULL
        ")->execute([$leaseId, $companyId]);

        $conn->prepare("
            INSERT INTO re_tenancy_contract_tenant_uploads
            (company_id, lease_id, stored_path, original_filename, file_size, mime_type,
             tenant_portal_user_id, legacy_user_id, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $companyId,
            $leaseId,
            $rel,
            substr((string)($file['name'] ?? 'signed_contract.pdf'), 0, 240),
            $size,
            $mime,
            $tpuId ?: null,
            $legacyUserId ?: null,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
        ]);
        $newId = (int)$conn->lastInsertId();
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        @unlink($abs);
        error_log('tenancy_contract_save_tenant_scan_pdf: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not record your upload. Please try again later.'];
    }

    $row = tenancy_contract_fetch_active_tenant_scan($conn, $leaseId, $companyId);
    return ['ok' => true, 'message' => 'Thank you. Your signed contract scan was uploaded successfully. Management will process it for landlord signature.', 'row' => $row];
}
