<?php
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$accountId = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

if ($accountId && empty($_GET['date_from'])) {
    $rangeStmt = $conn->prepare("
        SELECT MIN(entry_date) AS first_entry, MAX(entry_date) AS last_entry
        FROM re_general_ledger
        WHERE account_id = ? AND company_id = ?
    ");
    $rangeStmt->execute([$accountId, $cid]);
    $range = $rangeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($range['first_entry'])) {
        $dateFrom = $range['first_entry'];
    }
    if (empty($_GET['date_to']) && !empty($range['last_entry']) && $range['last_entry'] > $dateTo) {
        $dateTo = $range['last_entry'];
    }
}

$accountsStmt = $conn->prepare("
    SELECT id, account_code, account_name, account_type, normal_balance
    FROM re_chart_of_accounts
    WHERE company_id = ? AND is_active = 1 AND is_header = 0
    ORDER BY FIELD(account_type, 'Asset', 'Liability', 'Equity', 'Income', 'Expense'), account_code
");
$accountsStmt->execute([$cid]);
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

$account = null;
$entries = [];
$opening = 0.0;
$closing = 0.0;
if ($accountId) {
    foreach ($accounts as $candidate) {
        if ((int)$candidate['id'] === $accountId) {
            $account = $candidate;
            break;
        }
    }
    if ($account) {
        $normal = $account['normal_balance'] ?? 'debit';
        $openSql = $normal === 'debit'
            ? "SELECT COALESCE(SUM(debit_amount) - SUM(credit_amount), 0) FROM re_general_ledger WHERE account_id = ? AND company_id = ? AND entry_date < ?"
            : "SELECT COALESCE(SUM(credit_amount) - SUM(debit_amount), 0) FROM re_general_ledger WHERE account_id = ? AND company_id = ? AND entry_date < ?";
        $stmt = $conn->prepare($openSql);
        $stmt->execute([$accountId, $cid, $dateFrom]);
        $opening = (float)$stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT gl.*, jh.journal_number, jh.journal_type, jh.reference_type, jh.reference_id, jh.description AS journal_description
            FROM re_general_ledger gl
            JOIN re_journal_headers jh ON jh.id = gl.journal_id
            WHERE gl.account_id = ? AND gl.company_id = ? AND gl.entry_date BETWEEN ? AND ?
            ORDER BY gl.entry_date ASC, gl.id ASC
        ");
        $stmt->execute([$accountId, $cid, $dateFrom, $dateTo]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $periodDebit = array_sum(array_map(fn($r) => (float)$r['debit_amount'], $entries));
        $periodCredit = array_sum(array_map(fn($r) => (float)$r['credit_amount'], $entries));
        $closing = $normal === 'debit' ? $opening + $periodDebit - $periodCredit : $opening + $periodCredit - $periodDebit;
    }
}

co_report_export($entries, [
    'entry_date' => 'Date',
    'journal_number' => 'Journal',
    'reference_type' => 'Reference Type',
    'description' => 'Description',
    'debit_amount' => 'Debit',
    'credit_amount' => 'Credit',
    'balance' => 'Stored Balance',
], 'construction_general_ledger_' . $dateFrom . '_' . $dateTo, 'Construction General Ledger');

$pageTitle = 'Construction General Ledger';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">General Ledger</h1><p class="text-muted mb-0">Account activity from <?= h($dateFrom) ?> to <?= h($dateTo) ?></p></div>
    <div class="d-flex gap-2"><?= $account ? co_report_export_buttons() : '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print</button>' ?></div>
</div>
<form method="get" class="card card-round mb-4 no-print">
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label">Account</label><select name="account_id" class="form-select"><option value="0">Select account</option><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $accountId === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['account_code'] . ' — ' . $a['account_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
        <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
    </div>
</form>
<?php if (!$account): ?>
<div class="card card-round"><div class="card-body text-center text-muted py-4">Choose an account to view ledger activity.</div></div>
<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-md-6"><div class="card card-round"><div class="card-body"><div class="text-muted">Opening Balance</div><h4><?= co_format_money($opening) ?></h4></div></div></div>
    <div class="col-md-6"><div class="card card-round"><div class="card-body"><div class="text-muted">Closing Balance</div><h4><?= co_format_money($closing) ?></h4></div></div></div>
</div>
<div class="card card-round">
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Journal</th><th>Reference</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
            <tbody>
            <?php foreach ($entries as $row): ?>
                <tr><td><?= h($row['entry_date']) ?></td><td><?php if (!empty($row['journal_id'])): ?><a href="../journal_entry_view.php?id=<?= (int)$row['journal_id'] ?>"><?= h($row['journal_number']) ?></a><?php else: ?><?= h($row['journal_number']) ?><?php endif; ?></td><td><?= h($row['reference_type'] . ' #' . $row['reference_id']) ?></td><td><?= h($row['description'] ?: $row['journal_description']) ?></td><td class="text-end"><?= (float)$row['debit_amount'] ? co_format_money($row['debit_amount']) : '-' ?></td><td class="text-end"><?= (float)$row['credit_amount'] ? co_format_money($row['credit_amount']) : '-' ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$entries): ?><tr><td colspan="6" class="text-center text-muted py-4">No ledger activity in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
