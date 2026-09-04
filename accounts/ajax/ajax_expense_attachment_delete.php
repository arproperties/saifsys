<?php
ob_start();
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

ob_clean();
header('Content-Type: application/json');

try {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        echo json_encode(['success'=>false, 'error'=>'Missing id']);
        ob_end_flush();
        exit;
    }

    // Get file path
    $stmt = $conn->prepare("SELECT file_path FROM expense_attachments WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        echo json_encode(['success'=>false, 'error'=>'Not found']);
        ob_end_flush();
        exit;
    }

    // Delete from database
    $stmt = $conn->prepare("DELETE FROM expense_attachments WHERE id = ?");
    $stmt->execute([$id]);

    // Try to delete file
    $filePath = __DIR__ . '/../../' . $row['file_path'];
    $realPath = realpath($filePath);
    $uploadBase = realpath(__DIR__ . '/../../uploads/expenses/');
    if ($realPath && $uploadBase && strpos($realPath, $uploadBase) === 0) {
        @unlink($realPath);
    }

    echo json_encode(['success'=>true]);

} catch (Exception $e) {
    error_log("Delete error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'Server error']);
}

ob_end_flush();
