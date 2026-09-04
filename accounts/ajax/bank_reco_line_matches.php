<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';

require_role(['Owner', 'Admin', 'Account'], $conn);

header('Content-Type: application/json; charset=utf-8');

$line_id = (int) ($_GET['line_id'] ?? 0);
if ($line_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'line_id required']);
    exit;
}

$st = $conn->prepare("
    SELECT m.* FROM cleaning_reconciliation_matches m
    WHERE m.bank_statement_line_id = ? AND m.status IN ('proposed','confirmed')
    ORDER BY m.id
");
$st->execute([$line_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode(['success' => true, 'matches' => $rows]);
