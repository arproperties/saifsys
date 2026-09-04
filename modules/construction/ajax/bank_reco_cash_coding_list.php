<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.cash_coding');

$bankId = (int) ($_GET['bank_account_id'] ?? 0);
$from = trim((string) ($_GET['date_from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['date_to'] ?? date('Y-m-d')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 200;
$offset = ($page - 1) * $limit;

if ($bankId <= 0) {
    co_bank_reco_json_error('bank_account_id required');
}

require_once __DIR__ . '/../includes/construction_bank_reco_rules.php';

try {
    $st = $conn->prepare('
        SELECT l.*, b.gl_account_id
        FROM co_bank_statement_lines l
        JOIN re_bank_accounts b ON b.id = l.bank_account_id AND b.company_id = l.company_id
        WHERE l.bank_account_id = ? AND l.company_id = ? AND l.txn_date BETWEEN ? AND ?
        ORDER BY l.txn_date ASC, l.id ASC
        LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset . '
    ');
    $st->execute([$bankId, $cid, $from, $to]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $line['remaining'] = co_bank_line_remaining($conn, $line);
        if ($line['remaining'] <= 0.009) {
            continue;
        }
        $line['is_spent'] = (float) $line['amount'] < 0;
        $line['abs_amount'] = round(abs((float) $line['amount']), 2);
        $rule = co_bank_rule_match_line($conn, $cid, $line, $bankId);
        $prefill = $rule['prefill'] ?? null;
        if (!$prefill) {
            $guess = co_bank_reco_guess_contact($conn, $cid, (string) ($line['description'] ?? '') . ' ' . (string) ($line['reference'] ?? ''), !$line['is_spent']);
            $prefill = [
                'transaction_type' => $line['is_spent'] ? 'quick_expense' : 'client_receipt',
                'account_id' => null,
                'project_id' => null,
                'contact' => ($guess && !empty($guess['type'])) ? ($guess['type'] . ':' . $guess['id']) : '',
                'description' => (string) ($line['description'] ?? ''),
                'reference' => (string) ($line['reference'] ?? ''),
            ];
        }
        $rows[] = [
            'id' => (int) $line['id'],
            'txn_date' => $line['txn_date'],
            'description' => $line['description'],
            'reference' => $line['reference'],
            'amount' => (float) $line['amount'],
            'abs_amount' => (float) $line['abs_amount'],
            'remaining' => (float) $line['remaining'],
            'is_spent' => (bool) $line['is_spent'],
            'status' => co_bank_line_status($conn, $line),
            'rule_name' => $rule['rule_name'] ?? null,
            'prefill' => $prefill,
        ];
    }
    echo json_encode(['success' => true, 'lines' => $rows, 'page' => $page, 'limit' => $limit]);
} catch (Throwable $e) {
    co_bank_reco_json_error($e->getMessage());
}
