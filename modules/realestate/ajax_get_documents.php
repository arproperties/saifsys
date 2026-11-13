<?php
/**
 * Real Estate Document List Handler (AJAX)
 */

// Prevent any output before JSON
ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/document_watch_helper.php';

// Clear any output
ob_clean();

require_login();
require_module_access($conn, MODULE_REALESTATE);

header('Content-Type: application/json');

$currentCompanyId = current_company_id($conn) ?: 1;

try {
    $relatedType = $_GET['related_type'] ?? '';
    $relatedId = (int)($_GET['related_id'] ?? 0);
    
    if (empty($relatedType) || $relatedId <= 0) {
        throw new Exception('Related entity is required');
    }
    
    // Get documents
    $stmt = $conn->prepare("
        SELECT d.*, u.username as uploaded_by_name, dt.document_type_name
        FROM re_documents d
        LEFT JOIN user u ON u.id = d.uploaded_by
        LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
        WHERE d.company_id = ? AND d.related_type = ? AND d.related_id = ?
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$currentCompanyId, $relatedType, $relatedId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tenant papers (EID, visa, passport, ...) uploaded on this tenant's OTHER leases
    // (typically the lease before a renewal) that are still the current copy and
    // still watched. Shown read-only so the active lease shows what is being tracked.
    if ($relatedType === 'lease') {
        $personTypes = re_doc_sql_list(RE_DOC_PERSON_TYPES);
        $carry = $conn->prepare("
            SELECT d.*, u.username AS uploaded_by_name, dt.document_type_name,
                   ol.id AS from_lease_id, ol.lease_number AS from_lease_number
            FROM re_documents d
            JOIN re_leases ol ON ol.id = d.related_id AND d.related_type = 'lease'
            JOIN re_leases cur ON cur.id = ? AND cur.company_id = d.company_id
                 AND cur.status IN (" . re_doc_sql_list(RE_DOC_WATCH_LEASE_STATUSES) . ")
            LEFT JOIN user u ON u.id = d.uploaded_by
            LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
            WHERE d.company_id = ?
              AND ol.tenant_id = cur.tenant_id
              AND ol.id <> cur.id
              AND ol.deleted_at IS NULL
              AND d.document_type IN ({$personTypes})
              AND " . re_doc_watched_sql('d') . "
        ");
        $carry->execute([$relatedId, $currentCompanyId]);
        foreach ($carry->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['is_carried'] = true;
            $documents[] = $row;
        }
    }

    // Tenant portal uploads: signed tenancy contract scans (lease only)
    if ($relatedType === 'lease') {
        try {
            $tu = $conn->prepare("
                SELECT id, stored_path AS file_path, original_filename AS file_name, file_size, uploaded_at AS created_at
                FROM re_tenancy_contract_tenant_uploads
                WHERE company_id = ? AND lease_id = ? AND superseded_at IS NULL
                ORDER BY uploaded_at DESC
            ");
            $tu->execute([$currentCompanyId, $relatedId]);
            while ($row = $tu->fetch(PDO::FETCH_ASSOC)) {
                $documents[] = [
                    'id' => 'tscan_' . (int)$row['id'],
                    'is_tenant_portal_scan' => true,
                    'document_type' => 'tenant_signed_tenancy_scan',
                    'document_name' => '',
                    'file_name' => $row['file_name'],
                    'file_path' => $row['file_path'],
                    'file_size' => (int)$row['file_size'],
                    'created_at' => $row['created_at'],
                    'uploaded_by_name' => 'Tenant (portal)',
                    'expiry_date' => null,
                    'expires_at' => null,
                    'is_expired' => 0,
                    'is_expiring_soon' => false,
                ];
            }
        } catch (Throwable $e) {
            // table may not exist yet
        }
    }
    
    // Is each document still watched (active lease, not replaced by a newer copy)?
    $watchReasons = re_doc_watch_reasons($conn, $currentCompanyId, array_map(static function ($d) {
        return is_numeric($d['id'] ?? null) ? (int)$d['id'] : 0;
    }, $documents));

    // Format file sizes and normalise expiry fields for frontend
    foreach ($documents as &$doc) {
        $docId = is_numeric($doc['id'] ?? null) ? (int)$doc['id'] : 0;
        $doc['watch_reason'] = $docId ? ($watchReasons[$docId] ?? null) : 'no_expiry';
        $doc['is_watched'] = ($doc['watch_reason'] === null);
        $doc['is_carried'] = !empty($doc['is_carried']);
        $doc['file_size_formatted'] = formatFileSize((int)($doc['file_size'] ?? 0));
        // Backwards-compatible 'expires_at' for JS, sourced from expiry_date
        $expiryDate = $doc['expiry_date'] ?? null;
        $doc['expires_at'] = $expiryDate;
        $doc['is_expiring_soon'] = false;
        // Work out expiry from the date on every load. The stored is_expired
        // flag is only set at upload time, so it goes stale once the date passes.
        $doc['is_expired'] = false;
        if ($expiryDate) {
            $daysUntilExpiry = (int)round((strtotime(substr((string)$expiryDate, 0, 10)) - strtotime('today')) / 86400);
            $doc['is_expired'] = ($daysUntilExpiry < 0);
            $doc['is_expiring_soon'] = ($daysUntilExpiry >= 0 && $daysUntilExpiry <= 30);
            $doc['days_until_expiry'] = $daysUntilExpiry;
        }
    }
    unset($doc);

    usort($documents, static function ($a, $b) {
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });
    
    ob_clean(); // Clear any output
    echo json_encode([
        'success' => true,
        'documents' => $documents
    ]);
    ob_end_flush();
    exit;
    
} catch (Exception $e) {
    ob_clean(); // Clear any output
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'documents' => []
    ]);
    ob_end_flush();
    exit;
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

