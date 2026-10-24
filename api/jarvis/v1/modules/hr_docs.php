<?php
/**
 * Jarvis API, module "hr_docs": employee documents (passports, visas, Emirates IDs,
 * labour cards, contracts) for Reem's Document Renewals, which copies them onto its
 * Shelf and checks again every night. Read-only; the logic is in includes/jarvis_hr_docs.php.
 *
 *   ?module=hr_docs&action=documents&after_id=0&limit=500
 *       -> { documents: [{ id, employee_code, employee_name, employee_left, type, number,
 *            issued_at, expires_at, notes, has_file, file_name, updated_at }], next_after_id }
 *   ?module=hr_docs&action=document_file&id=<document id>
 *       -> the file itself; 404 when there is none (or its path leaves uploads/docs)
 */

declare(strict_types=1);

if (!defined('JARVIS_API')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../../includes/jarvis_hr_docs.php';

$appRoot = dirname(__DIR__, 4);

if ($action === 'documents') {
    $page = jarvis_hr_docs_list($conn, $appRoot, max(0, (int)($_GET['after_id'] ?? 0)), (int)($_GET['limit'] ?? JARVIS_HR_DOCS_MAX));
    jarvis_api_send(['ok' => true] + $page);
}

if ($action === 'document_file') {
    $file = jarvis_hr_docs_file($conn, $appRoot, (int)($_GET['id'] ?? 0));
    if ($file === null) {
        jarvis_api_error('not_found', 'That document has no file.', 404);
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . rawurlencode(basename($file)) . '"');
    readfile($file);
    exit;
}

jarvis_api_error('not_found', 'Unknown action. Try action=documents.', 404);
