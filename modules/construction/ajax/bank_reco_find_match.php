<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.view');

$line_id = (int) ($_GET['line_id'] ?? $_POST['line_id'] ?? 0);
if ($line_id <= 0) {
    co_bank_reco_json_error('line_id required');
}

require_once __DIR__ . '/../includes/construction_bank_reco_engine.php';

try {
    $line = co_bank_get_statement_line($conn, $line_id, $cid);
    if (!$line) {
        co_bank_reco_json_error('Statement line not found');
    }

    $filters = [
        'date_from' => trim((string) ($_REQUEST['date_from'] ?? '')),
        'date_to' => trim((string) ($_REQUEST['date_to'] ?? '')),
        'amount' => $_REQUEST['amount'] ?? '',
        'reference' => trim((string) ($_REQUEST['reference'] ?? '')),
        'keyword' => trim((string) ($_REQUEST['keyword'] ?? '')),
        'unreconciled_only' => !empty($_REQUEST['unreconciled_only']),
        'transaction_type' => trim((string) ($_REQUEST['transaction_type'] ?? '')),
    ];

    $results = co_bank_reco_find_transactions($conn, $cid, $line, $filters);

    echo json_encode([
        'success' => true,
        'line' => [
            'id' => (int) $line['id'],
            'remaining' => (float) $line['remaining'],
            'abs_amount' => (float) $line['abs_amount'],
            'is_spent' => (bool) $line['is_spent'],
        ],
        'results' => $results,
        'count' => count($results),
    ]);
} catch (Throwable $e) {
    error_log('co bank_reco_find_match: ' . $e->getMessage());
    co_bank_reco_json_error($e->getMessage());
}
