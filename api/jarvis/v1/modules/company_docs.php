<?php
/**
 * Jarvis API, module "company_docs": the company documents of HR -> Company Documents
 * (trade licences, MOA, Ejari, establishment cards, POA, VAT and corporate tax certificates),
 * for Reem, which copies them onto its Shelf so its chat answers from the current issue.
 * Read-only; the logic is in includes/jarvis_company_docs.php.
 *
 *   ?module=company_docs&action=documents&after_id=0&limit=500
 *       -> { documents: [{ id, company_id, company_name, company_code, type, title, number, authority,
 *            issued_at, expires_at, notes, updated_at, has_file, file_name, file_key,
 *            versions: [{ version_no, number, authority, issued_at, expires_at, notes, archived_at,
 *                         has_file, file_name, file_key }] }], next_after_id }
 *   ?module=company_docs&action=document_file&id=<document id>[&version=<version_no>]
 *       -> the file itself; 404 when there is none (or its path leaves uploads/company_docs)
 */

declare(strict_types=1);

if (!defined('JARVIS_API')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../../includes/jarvis_company_docs.php';

$appRoot = dirname(__DIR__, 4);

if ($action === 'documents') {
    $page = jarvis_company_docs_list($conn, $appRoot, max(0, (int)($_GET['after_id'] ?? 0)), (int)($_GET['limit'] ?? JARVIS_COMPANY_DOCS_MAX));
    jarvis_api_send(['ok' => true] + $page);
}

if ($action === 'document_file') {
    $version = isset($_GET['version']) && $_GET['version'] !== '' ? (int)$_GET['version'] : null;
    $file = jarvis_company_docs_file($conn, $appRoot, (int)($_GET['id'] ?? 0), $version);
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
