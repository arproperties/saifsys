<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';

$line_id = (int) ($_GET['line_id'] ?? 0);
if ($line_id <= 0) {
    co_bank_reco_json_error('line_id required');
}

co_bank_reco_require_tables($conn);

$st = $conn->prepare("
    SELECT m.* FROM co_reconciliation_matches m
    INNER JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
    WHERE m.bank_statement_line_id = ? AND l.company_id = ? AND m.status IN ('proposed','confirmed')
    ORDER BY m.id
");
$st->execute([$line_id, $cid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode(['success' => true, 'matches' => $rows]);
