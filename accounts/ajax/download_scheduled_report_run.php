<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/scheduled_reports_service.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$runId = (int)($_GET['run_id'] ?? 0);
if ($runId <= 0) {
    http_response_code(400);
    exit('Invalid run ID');
}

$st = $conn->prepare('SELECT file_path, status FROM scheduled_report_runs WHERE id = ? LIMIT 1');
$st->execute([$runId]);
$run = $st->fetch(PDO::FETCH_ASSOC);

if (!$run || empty($run['file_path']) || ($run['status'] ?? '') !== 'completed') {
    http_response_code(404);
    exit('Report file not found');
}

$path = (string)$run['file_path'];
$storageRoot = realpath(dirname(__DIR__, 2) . '/storage/scheduled_reports');
$fileReal = realpath($path);

if (!$fileReal || !$storageRoot || strpos($fileReal, $storageRoot) !== 0 || !is_file($fileReal)) {
    http_response_code(404);
    exit('Report file not available');
}

$ext = strtolower(pathinfo($fileReal, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'csv' => 'text/csv; charset=utf-8',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    default => 'application/pdf',
};

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($fileReal) . '"');
header('Content-Length: ' . filesize($fileReal));
readfile($fileReal);
exit;
