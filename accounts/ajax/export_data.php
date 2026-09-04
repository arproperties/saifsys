<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/export_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$dataType = $_GET['type'] ?? $_POST['type'] ?? '';
$format = $_GET['format'] ?? $_POST['format'] ?? 'csv';
$filters = json_decode($_GET['filters'] ?? $_POST['filters'] ?? '{}', true);
$selectedIds = json_decode($_GET['selected_ids'] ?? $_POST['selected_ids'] ?? '[]', true);

if (empty($dataType)) {
  header('HTTP/1.1 400 Bad Request');
  exit('Missing data type');
}

if (!in_array($format, ['csv', 'excel', 'pdf'])) {
  header('HTTP/1.1 400 Bad Request');
  exit('Invalid format');
}

try {
  handleExportRequest($conn, $dataType, $format, $filters, $selectedIds);
} catch (Throwable $e) {
  header('HTTP/1.1 500 Internal Server Error');
  exit('Export failed: ' . $e->getMessage());
}
