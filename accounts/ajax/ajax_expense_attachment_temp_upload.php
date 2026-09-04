<?php
// Temporary upload handler for expenses being created
ob_start();
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

ob_clean();
header('Content-Type: application/json');

try {
    if (empty($_FILES) || !isset($_FILES['files'])) {
        echo json_encode(['success'=>false, 'error'=>'No files uploaded']);
        ob_end_flush();
        exit;
    }

    // Initialize session temp storage
    if (!isset($_SESSION['expense_temp_files'])) {
        $_SESSION['expense_temp_files'] = [];
    }

    $maxBytes = 20 * 1024 * 1024; // 20MB
    $tempDir = __DIR__ . '/../../uploads/temp/expenses/';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }

    $userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
    $okAny = false;
    $errors = [];
    $uploadedFiles = [];

    // Handle files
    $files = [];
    if (is_array($_FILES['files']['error'])) {
        foreach ($_FILES['files']['error'] as $i => $err) {
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            $files[] = [
                'error' => $err,
                'name' => $_FILES['files']['name'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'size' => $_FILES['files']['size'][$i],
                'type' => $_FILES['files']['type'][$i]
            ];
        }
    } else {
        if ($_FILES['files']['error'] !== UPLOAD_ERR_NO_FILE) {
            $files[] = [
                'error' => $_FILES['files']['error'],
                'name' => $_FILES['files']['name'],
                'tmp_name' => $_FILES['files']['tmp_name'],
                'size' => $_FILES['files']['size'],
                'type' => $_FILES['files']['type']
            ];
        }
    }

    foreach ($files as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload error code ' . $file['error'];
            continue;
        }

        $name = $file['name'];
        $tmp = $file['tmp_name'];
        $size = (int)$file['size'];
        $type = $file['type'] ?? '';

        if ($size <= 0 || $size > $maxBytes) {
            $errors[] = $name . ' too large';
            continue;
        }

        // Sanitize filename
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^a-zA-Z0-9_\-.]+/', '_', $base);
        $store = $base . '-' . time() . '-' . mt_rand(1000, 9999) . ($ext ? '.' . $ext : '');

        $destPath = $tempDir . $store;
        if (!move_uploaded_file($tmp, $destPath)) {
            $errors[] = 'Failed to move ' . $name;
            continue;
        }

        // Store in session
        $fileId = uniqid('temp_', true);
        $_SESSION['expense_temp_files'][$fileId] = [
            'original_name' => $name,
            'temp_path' => $store,
            'size' => $size,
            'type' => $type,
            'uploaded_at' => date('Y-m-d H:i:s')
        ];

        $uploadedFiles[] = [
            'id' => $fileId,
            'file_name' => $name,
            'size' => $size,
            'size_fmt' => formatBytes($size)
        ];

        $okAny = true;
    }

    echo json_encode([
        'success' => $okAny,
        'files' => $uploadedFiles,
        'error' => $okAny ? null : ($errors[0] ?? 'Upload failed')
    ]);

} catch (Exception $e) {
    error_log("Temp upload error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

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

ob_end_flush();

