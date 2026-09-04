<?php
/**
 * AJAX endpoint to upload task attachments
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/includes/re_task_access.php';

require_login();
if (defined('TASKS_SHARED_MODE') && TASKS_SHARED_MODE) {
    if (!re_tasks_user_can_view_shared($conn, (int)current_user_id())) {
        http_response_code(403);
        exit('Forbidden');
    }
}

header('Content-Type: application/json');

$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : 0;

if (!$taskId) {
    echo json_encode(['success' => false, 'message' => 'Task ID required']);
    exit;
}

// Verify task belongs to company and user can access it
if (!re_tasks_can_view_task($conn, $taskId, $currentCompanyId, $userId)) {
    echo json_encode(['success' => false, 'message' => 'Task not found']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload error']);
    exit;
}

$file = $_FILES['file'];
$maxSize = 10 * 1024 * 1024; // 10MB

if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File too large (max 10MB)']);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'];
if (!in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'File type not allowed']);
    exit;
}

$uploadDir = __DIR__ . '/../../uploads/realestate/tasks/' . $taskId . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$fileName = time() . '_' . basename($file['name']);
$filePath = $uploadDir . $fileName;
$relativePath = 'uploads/realestate/tasks/' . $taskId . '/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    exit;
}

try {
    $stmt = $conn->prepare("
        INSERT INTO re_task_attachments (company_id, task_id, file_name, file_path, file_size, mime_type, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $currentCompanyId, $taskId, $file['name'], $relativePath, 
        $file['size'], $file['type'], $userId
    ]);
    
    echo json_encode(['success' => true, 'message' => 'File uploaded successfully']);
} catch (Exception $e) {
    unlink($filePath);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

