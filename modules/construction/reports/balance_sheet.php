<?php
/**
 * Construction Balance Sheet — Shared RE ledger (re_*).
 * Point-in-time only (same as Zoho Books / Xero): As of Date.
 * For period movements use Trial Balance → Date range.
 */
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$asOfDate = !empty($_GET['as_of_date']) ? $_GET['as_of_date'] : (!empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d'));

$stmt = $conn->prepare("
    SELECT coa.account_type, coa.account_code, coa.account_name,
           COALESCE(CASE
             WHEN coa.account_type = 'Asset' THEN SUM(gl.debit_amount) - SUM(gl.credit_amount)
             WHEN coa.account_type IN ('Liability', 'Equity') THEN SUM(gl.credit_amount) - SUM(gl.debit_amount)
             ELSE 0 END, 0) AS balance
    FROM re_chart_of_accounts coa
    LEFT JOIN re_general_ledger gl
           ON gl.account_id = coa.id
          AND gl.company_id = coa.company_id
          AND gl.entry_date <= ?
    WHERE coa.company_id = ?
      AND coa.account_type IN ('Asset', 'Liability', 'Equity')
      AND coa.is_active = 1
      AND coa.is_header = 0
    GROUP BY coa.id, coa.account_type, coa.account_code, coa.account_name
    HAVING ABS(balance) > 0.005
    ORDER BY FIELD(coa.account_type, 'Asset', 'Liability', 'Equity'), coa.account_code
");
$stmt->execute([$asOfDate, $cid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$earningsStmt = $conn->prepare("
    SELECT COALESCE(SUM(CASE
        WHEN coa.account_type = 'Income' THEN gl.credit_amount - gl.debit_amount
        WHEN coa.account_type = 'Expense' THEN gl.debit_amount - gl.credit_amount
        ELSE 0 END), 0) AS net_income
    FROM re_general_ledger gl
    JOIN re_chart_of_accounts coa ON coa.id = gl.account_id AND coa.company_id = gl.company_id
    WHERE gl.company_id = ?
      AND gl.entry_date <= ?
      AND coa.account_type IN ('Income', 'Expense')
");
$earningsStmt->execute([$cid, $asOfDate]);
$currentEarnings = (float)$earningsStmt->fetchColumn();

$totalAssets = 0.0;
$totalLiabilities = 0.0;
$totalEquity = $currentEarnings;
foreach ($rows as $row) {
    if ($row['account_type'] === 'Asset') $totalAssets += (float)$row['balance'];
    if ($row['account_type'] === 'Liability') $totalLiabilities += (float)$row['balance'];
    if ($row['account_type'] === 'Equity') $totalEquity += (float)$row['balance'];
}

$exportRows = $rows;
$exportRows[] = ['account_type' => 'Equity', 'account_code' => '', 'account_name' => 'Current Year Earnings', 'balance' => $currentEarnings];
co_report_export($exportRows, [
    'account_type' => 'Section',
    'account_code' => 'Code',
    'account_name' => 'Account',
    'balance' => 'Balance',
], 'construction_balance_sheet_' . $asOfDate, 'Construction Balance Sheet');

$pageTitle = 'Construction Balance Sheet';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Balance Sheet</h1>
        <p class="text-muted mb-0">As of <?= h($asOfDate) ?> · position statement (not a date-range report)</p>
    </div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<form method="get" class="card card-round mb-4 no-print">
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">As of Date</label>
            <input type="date" name="as_of_date" class="form-control" value="<?= h($asOfDate) ?>">
        </div>
        <div class="col-md-4">
            <button class="btn btn-primary">Generate</button>
        </div>
        <div class="col-md-12">
            <p class="small text-muted mb-0">
                Like Zoho Books / Xero, Balance Sheet is always <strong>as of one date</strong>.
                For a week or month of movements, use <a href="trial_balance.php?mode=range">Trial Balance → Date range</a>.
            </p>
        </div>
    </div>
</form>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card card-round"><div class="card-body"><div class="text-muted">Assets</div><h4><?= co_format_money($totalAssets) ?></h4></div></div></div>
    <div class="col-md-4"><div class="card card-round"><div class="card-body"><div class="text-muted">Liabilities</div><h4><?= co_format_money($totalLiabilities) ?></h4></div></div></div>
    <div class="col-md-4"><div class="card card-round"><div class="card-body"><div class="text-muted">Equity incl. earnings</div><h4><?= co_format_money($totalEquity) ?></h4></div></div></div>
</div>
<div class="card card-round">
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Section</th><th>Code</th><th>Account</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr><td><?= h($row['account_type']) ?></td><td><?= h($row['account_code']) ?></td><td><?= h($row['account_name']) ?></td><td class="text-end"><?= co_format_money($row['balance']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="table-light"><td>Equity</td><td></td><td>Current Year Earnings</td><td class="text-end"><?= co_format_money($currentEarnings) ?></td></tr>
            </tbody>
            <tfoot class="table-light"><tr><th colspan="3" class="text-end">Liabilities + Equity</th><th class="text-end"><?= co_format_money($totalLiabilities + $totalEquity) ?></th></tr></tfoot>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
