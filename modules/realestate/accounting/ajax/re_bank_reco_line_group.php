<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.view');

// A bank charge ticked together with its VAT line: their total and the ERP entry that equals it.
$lineIds = array_filter(array_map('intval', explode(',', (string) ($_GET['line_ids'] ?? ''))));

require_once __DIR__ . '/../../includes/re_bank_reco_engine.php';

try {
    $group = re_bank_reco_load_line_group($conn, $cid, $lineIds);
    if (empty($group['success'])) {
        re_bank_reco_json_error((string) $group['error']);
    }
    $lines = [];
    foreach ($group['lines'] as $line) {
        $lines[] = [
            'id' => (int) $line['id'],
            'txn_date' => (string) $line['statement_date'],
            'description' => (string) $line['description'],
            'remaining' => (float) $line['remaining'],
            'is_spent' => (bool) $line['is_spent'],
        ];
    }
    echo json_encode([
        'success' => true,
        'lines' => $lines,
        'total' => (float) $group['total'],
        'is_spent' => (bool) $group['lines'][0]['is_spent'],
        'candidates' => re_bank_reco_line_group_candidates($conn, $cid, $group['lines'], (float) $group['total']),
    ]);
} catch (Throwable $e) {
    error_log('re_bank_reco_line_group: ' . $e->getMessage());
    re_bank_reco_json_error($e->getMessage());
}
