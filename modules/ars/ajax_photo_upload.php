<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';

header('Content-Type: application/json');
require_once __DIR__ . '/includes/ars_permissions.php';
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();
$action = $_POST['action'] ?? '';
$unitId = (int)($_POST['unit_id'] ?? 0);

if (!$unitId) { echo json_encode(['success' => false, 'error' => 'Missing unit_id']); exit; }

if ($action === 'upload') {
    $uploadDir = __DIR__ . '/../../uploads/ars/units/' . $unitId . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $uploaded = 0;
    $files = $_FILES['photos'] ?? [];
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;

    // Get current max sort_order
    $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM ars_unit_photos WHERE unit_id = ?");
    $stmt->execute([$unitId]);
    $maxSort = (int) $stmt->fetchColumn();

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $mime = $files['type'][$i];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) continue;

        $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION) ?: 'jpg';
        $safeName = 'unit_' . $unitId . '_' . time() . '_' . $i . '.' . strtolower($ext);
        $destPath = $uploadDir . $safeName;
        $relPath  = 'uploads/ars/units/' . $unitId . '/' . $safeName;

        if (!move_uploaded_file($files['tmp_name'][$i], $destPath)) continue;

        // Resize with GD if wider than 1600px
        $info = @getimagesize($destPath);
        if ($info && $info[0] > 1600) {
            $src = null;
            switch ($info['mime']) {
                case 'image/jpeg': $src = @imagecreatefromjpeg($destPath); break;
                case 'image/png':  $src = @imagecreatefrompng($destPath);  break;
                case 'image/webp': $src = @imagecreatefromwebp($destPath); break;
            }
            if ($src) {
                $newW = 1600;
                $newH = (int) round($info[1] * ($newW / $info[0]));
                $dst = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $info[0], $info[1]);
                switch ($info['mime']) {
                    case 'image/jpeg': imagejpeg($dst, $destPath, 85); break;
                    case 'image/png':  imagepng($dst, $destPath, 8);   break;
                    case 'image/webp': imagewebp($dst, $destPath, 85); break;
                }
                imagedestroy($src);
                imagedestroy($dst);
            }
        }

        $maxSort++;
        $isPrimary = ($maxSort === 1) ? 1 : 0;
        $conn->prepare("INSERT INTO ars_unit_photos (unit_id, company_id, file_path, file_name, sort_order, is_primary) VALUES (?,?,?,?,?,?)")
             ->execute([$unitId, $arsCompanyId, $relPath, $safeName, $maxSort, $isPrimary]);
        $uploaded++;
    }
    echo json_encode(['success' => $uploaded > 0, 'uploaded' => $uploaded]);
    exit;
}

if ($action === 'delete') {
    $photoId = (int)($_POST['photo_id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM ars_unit_photos WHERE id = ? AND unit_id = ?");
    $stmt->execute([$photoId, $unitId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($photo) {
        $fullPath = __DIR__ . '/../../' . $photo['file_path'];
        if (file_exists($fullPath)) @unlink($fullPath);
        $conn->prepare("DELETE FROM ars_unit_photos WHERE id = ?")->execute([$photoId]);
        // If deleted was primary, make the next one primary
        if ($photo['is_primary']) {
            $conn->prepare("UPDATE ars_unit_photos SET is_primary = 1 WHERE unit_id = ? ORDER BY sort_order LIMIT 1")->execute([$unitId]);
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'primary') {
    $photoId = (int)($_POST['photo_id'] ?? 0);
    $conn->prepare("UPDATE ars_unit_photos SET is_primary = 0 WHERE unit_id = ?")->execute([$unitId]);
    $conn->prepare("UPDATE ars_unit_photos SET is_primary = 1 WHERE id = ? AND unit_id = ?")->execute([$photoId, $unitId]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
