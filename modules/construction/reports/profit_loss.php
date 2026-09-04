<?php
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-01-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

$stmt = $conn->prepare("
    SELECT coa.account_type, coa.account_code, coa.account_name,
           COALESCE(SUM(CASE
             WHEN coa.account_type = 'Income' THEN gl.credit_amount - gl.debit_amount
             WHEN coa.account_type = 'Expense' THEN gl.debit_amount - gl.credit_amount
             ELSE 0 END), 0) AS amount
    FROM re_chart_of_accounts coa
    LEFT JOIN re_general_ledger gl
           ON gl.account_id = coa.id
          AND gl.company_id = coa.company_id
          AND gl.entry_date BETWEEN ? AND ?
    WHERE coa.company_id = ?
      AND coa.account_type IN ('Income', 'Expense')
      AND coa.is_active = 1
      AND coa.is_header = 0
    GROUP BY coa.id, coa.account_type, coa.account_code, coa.account_name
    HAVING ABS(amount) > 0.005
    ORDER BY FIELD(coa.account_type, 'Income', 'Expense'), coa.account_code
");
$stmt->execute([$dateFrom, $dateTo, $cid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalIncome = 0.0;
$totalExpense = 0.0;
foreach ($rows as $row) {
    if ($row['account_type'] === 'Income') $totalIncome += (float)$row['amount'];
    if ($row['account_type'] === 'Expense') $totalExpense += (float)$row['amount'];
}
$netProfit = $totalIncome - $totalExpense;

$exportRows = $rows;
$exportRows[] = ['account_type' => 'Income', 'account_code' => '', 'account_name' => 'Total Income', 'amount' => $totalIncome];
$exportRows[] = ['account_type' => 'Expense', 'account_code' => '', 'account_name' => 'Total Expenses', 'amount' => $totalExpense];
$exportRows[] = ['account_type' => '', 'account_code' => '', 'account_name' => 'Net Profit / Loss', 'amount' => $netProfit];
co_report_export($exportRows, [
    'account_type' => 'Type',
    'account_code' => 'Code',
    'account_name' => 'Account',
    'amount' => 'Amount',
], 'construction_profit_loss_' . $dateFrom . '_' . $dateTo, 'Construction Profit & Loss');

$pageTitle = 'Construction Profit & Loss';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Profit & Loss</h1>
        <p class="text-muted mb-0">Company GL income and expenses from <?= h($dateFrom) ?> to <?= h($dateTo) ?>.</p>
    </div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>

<form method="get" class="card card-round mb-4 no-print">
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
        <div class="col-md-4"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
        <div class="col-md-4"><button class="btn btn-primary">Generate</button></div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card card-round"><div class="card-body"><div class="text-muted">Total Income</div><h4><?= co_format_money($totalIncome) ?></h4></div></div></div>
    <div class="col-md-4"><div class="card card-round"><div class="card-body"><div class="text-muted">Total Expenses</div><h4><?= co_format_money($totalExpense) ?></h4></div></div></div>
    <div class="col-md-4"><div class="card card-round"><div class="card-body"><div class="text-muted">Net Profit / Loss</div><h4 class="<?= $netProfit < 0 ? 'text-danger' : 'text-success' ?>"><?= co_format_money($netProfit) ?></h4></div></div></div>
</div>

<div class="card card-round">
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Type</th><th>Code</th><th>Account</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No P&L activity in this period.</td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr><td><?= h($row['account_type']) ?></td><td><?= h($row['account_code']) ?></td><td><?= h($row['account_name']) ?></td><td class="text-end"><?= co_format_money($row['amount']) ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot class="table-light">
                <tr><th colspan="3" class="text-end">Net Profit / Loss</th><th class="text-end"><?= co_format_money($netProfit) ?></th></tr>
            </tfoot>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
