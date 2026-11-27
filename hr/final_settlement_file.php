<?php
/**
 * One document attached to a final settlement sheet:
 *   final_settlement_file.php?id=SHEET&n=0   (n = which file of that sheet)
 * Same access as the final settlement page (Owner, Admin, HR).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_final_settlement.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

$p = hr_fs_table_ready($conn) ? hr_fs_load($conn, (int)($_GET['id'] ?? 0)) : null;
$rel = $p['files'][(int)($_GET['n'] ?? 0)] ?? '';
$abs = $rel !== '' ? hr_fs_file_abs($rel) : null;
if (!$abs) {
    http_response_code(404);
    exit('File not found.');
}
$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$type = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? null;
if (!$type) {
    http_response_code(404);
    exit('File not found.');
}
header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="settlement-' . (int)$p['id'] . '-' . ((int)($_GET['n'] ?? 0) + 1) . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($abs);
