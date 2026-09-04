<?php
ob_start();
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

ob_clean();
header('Content-Type: application/json');

function formatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}

try {
    $expense_id = isset($_GET['expense_id']) ? (int)$_GET['expense_id'] : 0;
    if ($expense_id <= 0) {
        echo json_encode(['success'=>false, 'items'=>[]]);
        ob_end_flush();
        exit;
    }

    $stmt = $conn->prepare("SELECT id, file_name, file_path, file_size, uploaded_at FROM expense_attachments WHERE expense_id = ? ORDER BY id DESC");
    $stmt->execute([$expense_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'file_name' => $row['file_name'],
            'url' => '../' . $row['file_path'],
            'size_fmt' => formatBytes($row['file_size'] ?? 0),
            'uploaded_at' => $row['uploaded_at'] ?? ''
        ];
    }

    echo json_encode(['success'=>true, 'items'=>$items]);
} catch (Exception $e) {
    error_log("Attachment list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false, 'items'=>[], 'error'=>'Server error']);
}

ob_end_flush();
