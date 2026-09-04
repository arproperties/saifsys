<?php
/** Phase 8 Bank Reconciliation Diagnostics - read only. */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/bank_reconciliation_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) require_module_access($conn, MODULE_REALESTATE);
$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$bankAccountId = !empty($_GET['bank_account_id']) ? (int)$_GET['bank_account_id'] : 0;
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function m($n){ return number_format((float)$n, 2); }
function diag_amount(array $row): string {
    foreach (['net_amount','amount','amount_allocated','debit_amount','credit_amount','matched_amount','total_amount'] as $key) {
        if (isset($row[$key]) && is_numeric($row[$key]) && abs((float)$row[$key]) > 0.005) return m($row[$key]);
    }
    return '-';
}
function diag_date(array $row): string {
    foreach (['statement_date','payment_date','cleared_date','entry_date','cheque_date','imported_at'] as $key) {
        if (!empty($row[$key])) return (string)$row[$key];
    }
    return '-';
}
function diag_title(array $row): string {
    foreach (['receipt_number','journal_number','cheque_number','reference','invoice_number','description','source_hash'] as $key) {
        if (!empty($row[$key])) return (string)$row[$key];
    }
    if (!empty($row['id'])) return '#' . (int)$row['id'];
    return 'Record';
}
function diag_summary(string $section, array $row): string {
    if (!empty($row['error'])) return 'Could not run diagnostic: ' . $row['error'];
    return match ($section) {
        'Unmatched Bank Statement Lines' => 'Bank statement line is imported but not matched yet.',
        'Unmatched ERP Receipts / Payments' => 'Receipt/payment exists in ERP but has no confirmed reconciliation match.',
        'Unreconciled GL Bank Lines' => 'Posted book bank entry is waiting for a bank statement match.',
        'Matched Amount Mismatches' => 'Matched amount does not equal bank statement amount.',
        'Duplicate Statement Hashes' => 'Possible duplicate imported bank statement line.',
        'Reconciled GL Without Match Record' => 'GL line is marked reconciled but no confirmed match record exists.',
        'Match Record Without Valid Source' => 'A confirmed match points to a missing statement/source row.',
        'Security Deposit Receipts Unmatched' => 'Security deposit receipt has not been reconciled.',
        'Cheque Cleared But No Receipt Match' => 'Cheque is cleared but has no reconciled receipt match.',
        default => 'Review this item.',
    };
}

$bankAccounts = $conn->prepare("SELECT ba.*, coa.account_code, coa.account_name FROM re_bank_accounts ba JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id WHERE ba.company_id = ? AND ba.is_active = 1 ORDER BY coa.account_code");
$bankAccounts->execute([$companyId]);
$allBankAccounts = $bankAccounts->fetchAll(PDO::FETCH_ASSOC);
$bank = $bankAccountId ? re_bank_rec_bank_account($conn, $companyId, $bankAccountId) : null;
$sections = [];
if ($bank) {
    $queries = [
        'Unmatched Bank Statement Lines' => ["SELECT id, statement_date, description, reference, net_amount, status FROM re_bank_statement_lines WHERE company_id = ? AND bank_account_id = ? AND status IN ('unmatched','partially_matched','investigating') ORDER BY statement_date DESC LIMIT 100", [$companyId,$bankAccountId]],
        'Unmatched ERP Receipts / Payments' => ["SELECT p.id, p.payment_date, p.receipt_number, p.reference_number, p.amount, p.accounting_mode, p.receipt_status FROM re_payments p WHERE p.company_id = ? AND NOT EXISTS (SELECT 1 FROM re_bank_reconciliation_matches m WHERE m.company_id = p.company_id AND m.source_table = 're_payments' AND m.source_id = p.id AND m.status = 'confirmed') ORDER BY p.payment_date DESC LIMIT 100", [$companyId]],
        'Unreconciled GL Bank Lines' => ["SELECT gl.id, gl.entry_date, gl.debit_amount, gl.credit_amount, gl.reference, jh.journal_number FROM re_general_ledger gl JOIN re_journal_headers jh ON jh.id = gl.journal_id WHERE gl.company_id = ? AND gl.account_id = ? AND gl.is_reconciled = 0 ORDER BY gl.entry_date DESC LIMIT 100", [$companyId,(int)$bank['gl_account_id']]],
        'Matched Amount Mismatches' => ["SELECT l.id, l.statement_date, l.description, l.net_amount, COALESCE(SUM(m.matched_amount),0) matched_amount FROM re_bank_statement_lines l JOIN re_bank_reconciliation_matches m ON m.statement_line_id = l.id AND m.company_id = l.company_id AND m.status = 'confirmed' WHERE l.company_id = ? AND l.bank_account_id = ? GROUP BY l.id HAVING ABS(ABS(l.net_amount) - matched_amount) > 0.02 LIMIT 100", [$companyId,$bankAccountId]],
        'Duplicate Statement Hashes' => ["SELECT source_hash, COUNT(*) cnt, MIN(statement_date) first_date, SUM(net_amount) total_amount FROM re_bank_statement_lines WHERE company_id = ? AND bank_account_id = ? GROUP BY source_hash HAVING COUNT(*) > 1", [$companyId,$bankAccountId]],
        'Reconciled GL Without Match Record' => ["SELECT gl.id, gl.entry_date, gl.debit_amount, gl.credit_amount, gl.reference FROM re_general_ledger gl WHERE gl.company_id = ? AND gl.account_id = ? AND gl.is_reconciled = 1 AND NOT EXISTS (SELECT 1 FROM re_bank_reconciliation_matches m WHERE m.company_id = gl.company_id AND m.gl_line_id = gl.id AND m.status = 'confirmed') ORDER BY gl.entry_date DESC LIMIT 100", [$companyId,(int)$bank['gl_account_id']]],
        'Match Record Without Valid Source' => ["SELECT m.* FROM re_bank_reconciliation_matches m LEFT JOIN re_bank_statement_lines l ON l.id = m.statement_line_id AND l.company_id = m.company_id WHERE m.company_id = ? AND m.bank_account_id = ? AND m.status = 'confirmed' AND l.id IS NULL LIMIT 100", [$companyId,$bankAccountId]],
        'Security Deposit Receipts Unmatched' => ["SELECT p.id, p.receipt_number, p.payment_date, ra.amount_allocated FROM re_receipt_allocations ra JOIN re_obligations o ON o.id = ra.obligation_id AND o.company_id = ra.company_id JOIN re_payments p ON p.id = ra.payment_id AND p.company_id = ra.company_id WHERE ra.company_id = ? AND o.obligation_type = 'security_deposit' AND NOT EXISTS (SELECT 1 FROM re_bank_reconciliation_matches m WHERE m.company_id = p.company_id AND m.source_table = 're_payments' AND m.source_id = p.id AND m.status = 'confirmed') ORDER BY p.payment_date DESC LIMIT 100", [$companyId]],
        'Cheque Cleared But No Receipt Match' => ["SELECT c.id, c.cheque_number, c.cheque_amount, c.cleared_date, c.payment_id FROM re_post_dated_cheques c WHERE c.company_id = ? AND c.status = 'cleared' AND (c.payment_id IS NULL OR NOT EXISTS (SELECT 1 FROM re_bank_reconciliation_matches m WHERE m.company_id = c.company_id AND m.source_table = 're_payments' AND m.source_id = c.payment_id AND m.status = 'confirmed')) ORDER BY c.cleared_date DESC LIMIT 100", [$companyId]],
    ];
    foreach ($queries as $title => [$sql,$params]) {
        try { $st=$conn->prepare($sql); $st->execute($params); $sections[$title]=$st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch(Throwable $e){ $sections[$title]=[['error'=>$e->getMessage()]]; }
    }
}
$pageTitle = 'Bank Reconciliation Diagnostics';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div class="page-header-label"><i class="bi bi-clipboard-data"></i> Bank Reconciliation Diagnostics</div><a href="bank_reconciliation.php<?= $bankAccountId ? '?bank_account_id='.(int)$bankAccountId : '' ?>" class="btn btn-outline-secondary">Workbench</a></div>
<div class="card card-round mb-4"><div class="card-body"><form method="get" class="row g-2"><div class="col-md-8"><select name="bank_account_id" class="form-select" required><option value="">-- Select Bank --</option><?php foreach($allBankAccounts as $acc): ?><option value="<?= (int)$acc['id'] ?>" <?= $bankAccountId===(int)$acc['id']?'selected':'' ?>><?= h($acc['account_code'].' - '.$acc['account_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><button class="btn btn-primary w-100">Load Diagnostics</button></div></form></div></div>
<?php if ($bank): ?>
<?php $totalIssues = array_sum(array_map('count', $sections)); ?>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small text-uppercase">Items Needing Attention</div><div class="h3 mb-0 <?= $totalIssues ? 'text-warning' : 'text-success' ?>"><?= (int)$totalIssues ?></div><small class="text-muted"><?= $totalIssues ? 'Review sections below.' : 'No issues found.' ?></small></div></div></div>
    <div class="col-md-8"><div class="card card-round h-100"><div class="card-body"><div class="fw-semibold mb-1">What this page shows</div><div class="text-muted">Readable checks for bank statement lines, ERP receipts, posted bank GL entries, and confirmed reconciliation matches.</div></div></div></div>
</div>
<?php foreach($sections as $title=>$rows): ?>
<div class="card card-round mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-0"><?= h($title) ?></h6>
            <small class="text-muted"><?= h(diag_summary($title, [])) ?></small>
        </div>
        <span class="badge bg-<?= count($rows) ? 'warning text-dark' : 'success' ?>"><?= count($rows) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if(!$rows): ?>
            <div class="text-center text-muted py-3">No items found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Record</th><th>What it means</th><th class="text-end">Amount</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach($rows as $row): ?>
                        <tr>
                            <td><?= h(diag_date($row)) ?></td>
                            <td><?= h(diag_title($row)) ?></td>
                            <td><?= h(diag_summary($title, $row)) ?></td>
                            <td class="text-end"><?= h(diag_amount($row)) ?></td>
                            <td>
                                <?php if (!empty($row['id']) && in_array($title, ['Unmatched ERP Receipts / Payments','Security Deposit Receipts Unmatched'], true)): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="../payment_view.php?id=<?= (int)$row['id'] ?>">Open Receipt</a>
                                <?php elseif (!empty($row['id']) && $title === 'Unmatched Bank Statement Lines'): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="bank_reconciliation.php?bank_account_id=<?= (int)$bankAccountId ?>&line_id=<?= (int)$row['id'] ?>&status=all">Open in Workbench</a>
                                <?php elseif (!empty($row['id']) && $title === 'Unreconciled GL Bank Lines'): ?>
                                    <span class="text-muted small">Import/select bank statement line to match</span>
                                <?php else: ?>
                                    <span class="text-muted small">Review</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
