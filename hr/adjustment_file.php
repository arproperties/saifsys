<?php
/**
 * One attachment of an employee bonus or deduction:
 *   adjustment_file.php?type=bonus&id=ROW&n=0      or  ?type=deduction&id=ROW&n=0
 *   (n = which attachment of that row, starting at 0)
 * Owner, Admin and HR only.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_adjust_attachment.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

$kind = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$n = (int)($_GET['n'] ?? 0);
$abs = null;
if ($id > 0 && hr_adj_ready($conn, $kind)) {
    $st = $conn->prepare("SELECT attachments_json FROM " . hr_adj_table($kind) . " WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $abs = hr_adj_file_abs(hr_adj_files($st->fetchColumn())[$n] ?? null);
}
$ext = $abs ? strtolower(pathinfo($abs, PATHINFO_EXTENSION)) : '';
$type = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? null;
if (!$abs || !$type) {
    http_response_code(404);
    exit('File not found.');
}
header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . $kind . '-' . $id . '-' . ($n + 1) . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($abs);
