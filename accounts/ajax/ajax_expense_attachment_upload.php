<?php
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

    $expense_id = isset($_POST['expense_id']) ? (int)$_POST['expense_id'] : 0;
    if ($expense_id <= 0) {
        echo json_encode(['success'=>false, 'error'=>'Missing expense id']);
        ob_end_flush();
        exit;
    }

    // Verify expense exists
    $stmt = $conn->prepare("SELECT id FROM expenses WHERE id = ?");
    $stmt->execute([$expense_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success'=>false, 'error'=>'Expense not found']);
        ob_end_flush();
        exit;
    }

    // Create upload directory
    $uploadDir = __DIR__ . '/../../uploads/expenses/' . $expense_id;
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0775, true)) {
            echo json_encode(['success'=>false, 'error'=>'Cannot create upload directory']);
            ob_end_flush();
            exit;
        }
    }

    $maxBytes = 20 * 1024 * 1024; // 20MB
    $okAny = false;
    $errors = [];

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

    $userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);

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
        $store = $base . '-' . time() . '-' . mt_rand(100, 999) . ($ext ? '.' . $ext : '');

        $destPath = $uploadDir . '/' . $store;
        if (!move_uploaded_file($tmp, $destPath)) {
            $errors[] = 'Failed to move ' . $name;
            continue;
        }

        // Relative path
        $relPath = 'uploads/expenses/' . $expense_id . '/' . $store;

        // Save to database
        try {
            $stmt = $conn->prepare("INSERT INTO expense_attachments (expense_id, file_name, file_path, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$expense_id, $name, $relPath, $type ?: null, $size, $userId]);
        } catch (PDOException $e) {
            @unlink($destPath);
            $errors[] = 'Database error: ' . $e->getMessage();
            error_log("Upload DB error: " . $e->getMessage());
            continue;
        }

        // Audit log (non-blocking)
        try {
            if (file_exists(__DIR__ . '/../../../includes/AuditService.php')) {
                require_once __DIR__ . '/../../../includes/AuditService.php';
                if (class_exists('AuditService')) {
                    AuditService::logUpload($name, $relPath, 'expenses', $expense_id, "Uploaded attachment '{$name}' to expense #{$expense_id}");
                }
            }
        } catch (Exception $e) {
            error_log("Audit log error (non-fatal): " . $e->getMessage());
        }

        $okAny = true;
    }

    echo json_encode([
        'success' => $okAny,
        'error' => $okAny ? null : ($errors[0] ?? 'Upload failed')
    ]);

} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

ob_end_flush();
