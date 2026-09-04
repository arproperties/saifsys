<?php
/**
 * AJAX: upload vendor documents (company-scoped).
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

header('Content-Type: application/json');

$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Company context is required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_POST['_csrf']) || !hash_equals((string)($_SESSION['_csrf'] ?? ''), (string)$_POST['_csrf'])) {
    echo json_encode(['success' => false, 'message' => 'CSRF token invalid']);
    exit;
}

$userId = (int)(current_user_id() ?: 0);
$vendorId = !empty($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : 0;

if ($vendorId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Vendor ID required']);
    exit;
}

$stmt = $conn->prepare('SELECT id FROM re_vendors WHERE id = ? AND company_id = ? LIMIT 1');
$stmt->execute([$vendorId, $currentCompanyId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Vendor not found']);
    exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload error (code ' . (int)$file['error'] . ')']);
    exit;
}

$maxSize = 10 * 1024 * 1024; // 10MB
if ((int)$file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File too large (max 10MB)']);
    exit;
}

$originalName = (string)($file['name'] ?? 'document');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];
if (!in_array($ext, $allowedExt, true)) {
    echo json_encode(['success' => false, 'message' => 'File type not allowed. Use JPG, PNG, GIF, PDF, DOC, DOCX, or TXT.']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = (string)$finfo->file($file['tmp_name']);
$allowedMimeByExt = [
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'gif' => ['image/gif'],
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/octet-stream'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
    ],
    'txt' => ['text/plain', 'text/x-plain', 'application/octet-stream'],
];
if (!in_array($detectedMime, $allowedMimeByExt[$ext] ?? [], true)) {
    echo json_encode(['success' => false, 'message' => 'File content does not match the allowed type']);
    exit;
}

$uploadRoot = realpath(__DIR__ . '/../../uploads');
if ($uploadRoot === false) {
    echo json_encode(['success' => false, 'message' => 'Uploads directory is missing']);
    exit;
}

$uploadDir = $uploadRoot . '/realestate/vendors/' . $vendorId;
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        echo json_encode([
            'success' => false,
            'message' => 'Upload folder is not writable. Ask an admin to fix permissions on uploads/realestate/vendors.',
        ]);
        exit;
    }
    @chmod($uploadDir, 0775);
}

if (!is_writable($uploadDir)) {
    echo json_encode([
        'success' => false,
        'message' => 'Upload folder is not writable. Ask an admin to fix permissions on uploads/realestate/vendors.',
    ]);
    exit;
}

$safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
$safeBase = trim((string)$safeBase, '._-');
if ($safeBase === '') {
    $safeBase = 'document';
}
$fileName = time() . '_' . $safeBase . '.' . $ext;
$filePath = $uploadDir . '/' . $fileName;
$relativePath = 'uploads/realestate/vendors/' . $vendorId . '/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    exit;
}

try {
    $documentType = $_POST['document_type'] ?? 'other';
    $allowedTypes = ['license', 'insurance', 'contract', 'invoice', 'certificate', 'other'];
    if (!in_array($documentType, $allowedTypes, true)) {
        $documentType = 'other';
    }
    $documentName = trim((string)($_POST['document_name'] ?? ''));
    if ($documentName === '') {
        $documentName = $originalName;
    }
    $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    if ($expiryDate !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $expiryDate);
        if (!$d || $d->format('Y-m-d') !== $expiryDate) {
            $expiryDate = null;
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO re_vendor_documents
        (company_id, vendor_id, document_type, document_name, file_name, file_path, file_size, mime_type, expiry_date, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $currentCompanyId,
        $vendorId,
        $documentType,
        $documentName,
        $originalName,
        $relativePath,
        (int)$file['size'],
        $detectedMime,
        $expiryDate,
        $userId > 0 ? $userId : null,
    ]);
    $docId = (int)$conn->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Document uploaded successfully',
        'document' => [
            'id' => $docId,
            'document_name' => $documentName,
            'document_type' => $documentType,
            'file_path' => $relativePath,
            'file_size' => (int)$file['size'],
            'mime_type' => $detectedMime,
            'expiry_date' => $expiryDate,
            'view_url' => '../../' . $relativePath,
        ],
    ]);
} catch (Throwable $e) {
    @unlink($filePath);
    error_log('ajax_upload_vendor_document: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save document record']);
}
