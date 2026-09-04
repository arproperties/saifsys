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
    
    // Format file sizes and normalise expiry fields for frontend
    foreach ($documents as &$doc) {
        $doc['file_size_formatted'] = formatFileSize((int)($doc['file_size'] ?? 0));
        // Backwards-compatible 'expires_at' for JS, sourced from expiry_date
        $expiryDate = $doc['expiry_date'] ?? null;
        $doc['expires_at'] = $expiryDate;
        $doc['is_expiring_soon'] = false;
        if ($expiryDate) {
            $daysUntilExpiry = (strtotime($expiryDate) - time()) / 86400;
            $doc['is_expiring_soon'] = ($daysUntilExpiry > 0 && $daysUntilExpiry <= 30);
            $doc['days_until_expiry'] = (int)ceil($daysUntilExpiry);
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

