<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.view');

$line_id = (int) ($_GET['line_id'] ?? $_POST['line_id'] ?? 0);
if ($line_id <= 0) {
    re_bank_reco_json_error('line_id required');
}

require_once __DIR__ . '/../../includes/re_bank_reco_engine.php';

try {
    $line = re_bank_get_statement_line($conn, $line_id, $cid);
    if (!$line) {
        re_bank_reco_json_error('Statement line not found');
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

    $results = re_bank_reco_find_transactions($conn, $cid, $line, $filters);

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
    error_log('re_bank_reco_find_match: ' . $e->getMessage());
    re_bank_reco_json_error($e->getMessage());
}
