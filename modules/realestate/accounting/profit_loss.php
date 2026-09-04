<?php
/**
 * Real Estate Accounting - Profit & Loss Statement
 * Shows income and expenses for a period
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get filter parameters
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-01-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$comparePeriod = !empty($_GET['compare']) ? true : false;
$compareFrom = !empty($_GET['compare_from']) ? $_GET['compare_from'] : date('Y-01-01', strtotime('-1 year'));
$compareTo = !empty($_GET['compare_to']) ? $_GET['compare_to'] : date('Y-m-d', strtotime('-1 year'));

// Get income accounts with balances
$incomeAccounts = $conn->prepare("
    SELECT 
        coa.id,
        coa.account_code,
        coa.account_name,
        coa.parent_id,
        COALESCE(SUM(CASE WHEN gl.entry_date BETWEEN ? AND ? THEN gl.credit_amount - gl.debit_amount ELSE 0 END), 0) as period_amount,
        COALESCE(SUM(CASE WHEN gl.entry_date BETWEEN ? AND ? THEN gl.credit_amount - gl.debit_amount ELSE 0 END), 0) as compare_amount
    FROM re_chart_of_accounts coa
    LEFT JOIN re_general_ledger gl ON gl.account_id = coa.id AND gl.company_id = ?
    WHERE coa.company_id = ? AND coa.account_type = 'Income' AND coa.is_active = 1 AND coa.is_header = 0
    GROUP BY coa.id, coa.account_code, coa.account_name, coa.parent_id
    HAVING period_amount != 0 OR compare_amount != 0
    ORDER BY coa.account_code
");
$incomeAccounts->execute([
    $dateFrom, $dateTo, $compareFrom, $compareTo,
    $currentCompanyId, $currentCompanyId
]);
$incomeData = $incomeAccounts->fetchAll(PDO::FETCH_ASSOC);

// Get expense accounts with balances
$expenseAccounts = $conn->prepare("
    SELECT 
        coa.id,
        coa.account_code,
        coa.account_name,
        coa.parent_id,
        COALESCE(SUM(CASE WHEN gl.entry_date BETWEEN ? AND ? THEN gl.debit_amount - gl.credit_amount ELSE 0 END), 0) as period_amount,
        COALESCE(SUM(CASE WHEN gl.entry_date BETWEEN ? AND ? THEN gl.debit_amount - gl.credit_amount ELSE 0 END), 0) as compare_amount
    FROM re_chart_of_accounts coa
    LEFT JOIN re_general_ledger gl ON gl.account_id = coa.id AND gl.company_id = ?
    WHERE coa.company_id = ? AND coa.account_type = 'Expense' AND coa.is_active = 1 AND coa.is_header = 0
    GROUP BY coa.id, coa.account_code, coa.account_name, coa.parent_id
    HAVING period_amount != 0 OR compare_amount != 0
    ORDER BY coa.account_code
");
$expenseAccounts->execute([
    $dateFrom, $dateTo, $compareFrom, $compareTo,
    $currentCompanyId, $currentCompanyId
]);
$expenseData = $expenseAccounts->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalIncome = array_sum(array_column($incomeData, 'period_amount'));
$totalExpenses = array_sum(array_column($expenseData, 'period_amount'));
$netProfit = $totalIncome - $totalExpenses;

$totalIncomeCompare = array_sum(array_column($incomeData, 'compare_amount'));
$totalExpensesCompare = array_sum(array_column($expenseData, 'compare_amount'));
$netProfitCompare = $totalIncomeCompare - $totalExpensesCompare;

if (!empty($_GET['export'])) {
    require_once __DIR__ . '/export_excel_helper.php';
    $pnlRows = [];
    foreach ($incomeData as $r) {
        $pnlRows[] = ['section' => 'Income', 'account_code' => $r['account_code'], 'account_name' => $r['account_name'], 'period_amount' => $r['period_amount'], 'compare_amount' => $r['compare_amount'] ?? 0];
    }
    $pnlRows[] = ['section' => 'Income', 'account_code' => '', 'account_name' => 'Total Income', 'period_amount' => $totalIncome, 'compare_amount' => $totalIncomeCompare];
    foreach ($expenseData as $r) {
        $pnlRows[] = ['section' => 'Expense', 'account_code' => $r['account_code'], 'account_name' => $r['account_name'], 'period_amount' => -$r['period_amount'], 'compare_amount' => -($r['compare_amount'] ?? 0)];
    }
    $pnlRows[] = ['section' => 'Expense', 'account_code' => '', 'account_name' => 'Total Expense', 'period_amount' => -$totalExpenses, 'compare_amount' => -$totalExpensesCompare];
    $pnlRows[] = ['section' => '', 'account_code' => '', 'account_name' => 'Net Profit', 'period_amount' => $netProfit, 'compare_amount' => $netProfitCompare];
    $cols = ['section' => 'Section', 'account_code' => 'Code', 'account_name' => 'Account', 'period_amount' => 'Period (AED)', 'compare_amount' => 'Compare (AED)'];
    $filename = 'profit_loss_' . $dateFrom . '_' . $dateTo;
    $title = 'Profit & Loss - ' . $dateFrom . ' to ' . $dateTo;
    if ($_GET['export'] === 'excel') {
        accounting_export_excel_or_csv($pnlRows, $cols, $filename, $title);
    } else {
        accounting_export_csv($pnlRows, $cols, $filename);
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Profit & Loss Statement';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div class="page-header-label">
        <i class="bi bi-graph-up"></i> Profit & Loss Statement
    </div>
    <div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
        <a href="?date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>&export=csv" class="btn btn-outline-primary"><i class="bi bi-download"></i> CSV</a>
        <a href="?date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>&export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    </div>
</div>

<!-- Filters -->
<div class="card card-round mb-4 no-print">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>" required>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="compare" id="compare" value="1" <?= $comparePeriod ? 'checked' : '' ?> onchange="toggleCompare()">
                    <label class="form-check-label" for="compare">
                        Compare Period
                    </label>
                </div>
            </div>
            <div class="col-md-2 compare-period" style="display: <?= $comparePeriod ? 'block' : 'none' ?>;">
                <label class="form-label"><i class="bi bi-calendar"></i> Compare From</label>
                <input type="date" name="compare_from" class="form-control" value="<?= h($compareFrom) ?>">
            </div>
            <div class="col-md-2 compare-period" style="display: <?= $comparePeriod ? 'block' : 'none' ?>;">
                <label class="form-label"><i class="bi bi-calendar"></i> Compare To</label>
                <input type="date" name="compare_to" class="form-control" value="<?= h($compareTo) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel-fill"></i> Generate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- P&L Report -->
<div class="card card-round">
    <div class="card-header bg-light">
        <h5 class="mb-0">
            <i class="bi bi-file-text"></i> Profit & Loss Statement
            <small class="text-muted">
                <?= date('M d, Y', strtotime($dateFrom)) ?> to <?= date('M d, Y', strtotime($dateTo)) ?>
            </small>
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="pnlTable">
                <thead class="table-light">
                    <tr>
                        <th style="width: 10%"><i class="bi bi-hash"></i> Code</th>
                        <th style="width: 40%"><i class="bi bi-tag"></i> Account</th>
                        <?php if ($comparePeriod): ?>
                            <th style="width: 17.5%" class="text-end">Previous Period</th>
                            <th style="width: 17.5%" class="text-end">Variance</th>
                        <?php endif; ?>
                        <th style="width: <?= $comparePeriod ? '15%' : '25%' ?>%" class="text-end">Current Period</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Income Section -->
                    <tr class="table-primary">
                        <td colspan="<?= $comparePeriod ? '5' : '3' ?>" class="fw-bold">
                            <i class="bi bi-arrow-up-circle"></i> INCOME
                        </td>
                    </tr>
                    <?php if (empty($incomeData)): ?>
                        <tr>
                            <td colspan="<?= $comparePeriod ? '5' : '3' ?>" class="text-muted ps-4">No income recorded</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($incomeData as $income): ?>
                            <tr>
                                <td class="ps-4"><a href="general_ledger.php?account_id=<?= (int)$income['id'] ?>&date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>"><code><?= h($income['account_code']) ?></code></a></td>
                                <td><a href="general_ledger.php?account_id=<?= (int)$income['id'] ?>&date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>"><?= h($income['account_name']) ?></a></td>
                                <?php if ($comparePeriod): ?>
                                    <td class="text-end"><?= number_format($income['compare_amount'], 2) ?></td>
                                    <td class="text-end">
                                        <?php 
                                        $variance = $income['period_amount'] - $income['compare_amount'];
                                        $variancePct = $income['compare_amount'] != 0 ? ($variance / abs($income['compare_amount'])) * 100 : 0;
                                        ?>
                                        <span class="text-<?= $variance >= 0 ? 'success' : 'danger' ?>">
                                            <?= $variance >= 0 ? '+' : '' ?><?= number_format($variance, 2) ?>
                                            (<?= $variance >= 0 ? '+' : '' ?><?= number_format($variancePct, 1) ?>%)
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td class="text-end">
                                    <strong><?= number_format($income['period_amount'], 2) ?> AED</strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="table-success fw-bold">
                        <td colspan="<?= $comparePeriod ? '2' : '2' ?>" class="text-end">Total Income:</td>
                        <?php if ($comparePeriod): ?>
                            <td class="text-end"><?= number_format($totalIncomeCompare, 2) ?></td>
                            <td class="text-end">
                                <?php 
                                $variance = $totalIncome - $totalIncomeCompare;
                                $variancePct = $totalIncomeCompare != 0 ? ($variance / abs($totalIncomeCompare)) * 100 : 0;
                                ?>
                                <span class="text-<?= $variance >= 0 ? 'success' : 'danger' ?>">
                                    <?= $variance >= 0 ? '+' : '' ?><?= number_format($variance, 2) ?>
                                    (<?= $variance >= 0 ? '+' : '' ?><?= number_format($variancePct, 1) ?>%)
                                </span>
                            </td>
                        <?php endif; ?>
                        <td class="text-end"><?= number_format($totalIncome, 2) ?> AED</td>
                    </tr>
                    
                    <!-- Expense Section -->
                    <tr class="table-danger">
                        <td colspan="<?= $comparePeriod ? '5' : '3' ?>" class="fw-bold">
                            <i class="bi bi-arrow-down-circle"></i> EXPENSES
                        </td>
                    </tr>
                    <?php if (empty($expenseData)): ?>
                        <tr>
                            <td colspan="<?= $comparePeriod ? '5' : '3' ?>" class="text-muted ps-4">No expenses recorded</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenseData as $expense): ?>
                            <tr>
                                <td class="ps-4"><a href="general_ledger.php?account_id=<?= (int)$expense['id'] ?>&date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>"><code><?= h($expense['account_code']) ?></code></a></td>
                                <td><a href="general_ledger.php?account_id=<?= (int)$expense['id'] ?>&date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>"><?= h($expense['account_name']) ?></a></td>
                                <?php if ($comparePeriod): ?>
                                    <td class="text-end"><?= number_format($expense['compare_amount'], 2) ?></td>
                                    <td class="text-end">
                                        <?php 
                                        $variance = $expense['period_amount'] - $expense['compare_amount'];
                                        $variancePct = $expense['compare_amount'] != 0 ? ($variance / abs($expense['compare_amount'])) * 100 : 0;
                                        ?>
                                        <span class="text-<?= $variance <= 0 ? 'success' : 'danger' ?>">
                                            <?= $variance >= 0 ? '+' : '' ?><?= number_format($variance, 2) ?>
                                            (<?= $variance >= 0 ? '+' : '' ?><?= number_format($variancePct, 1) ?>%)
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td class="text-end">
                                    <strong><?= number_format($expense['period_amount'], 2) ?> AED</strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="table-danger fw-bold">
                        <td colspan="<?= $comparePeriod ? '2' : '2' ?>" class="text-end">Total Expenses:</td>
                        <?php if ($comparePeriod): ?>
                            <td class="text-end"><?= number_format($totalExpensesCompare, 2) ?></td>
                            <td class="text-end">
                                <?php 
                                $variance = $totalExpenses - $totalExpensesCompare;
                                $variancePct = $totalExpensesCompare != 0 ? ($variance / abs($totalExpensesCompare)) * 100 : 0;
                                ?>
                                <span class="text-<?= $variance <= 0 ? 'success' : 'danger' ?>">
                                    <?= $variance >= 0 ? '+' : '' ?><?= number_format($variance, 2) ?>
                                    (<?= $variance >= 0 ? '+' : '' ?><?= number_format($variancePct, 1) ?>%)
                                </span>
                            </td>
                        <?php endif; ?>
                        <td class="text-end"><?= number_format($totalExpenses, 2) ?> AED</td>
                    </tr>
                    
                    <!-- Net Profit/Loss -->
                    <tr class="table-dark fw-bold">
                        <td colspan="<?= $comparePeriod ? '2' : '2' ?>" class="text-end">NET <?= $netProfit >= 0 ? 'PROFIT' : 'LOSS' ?>:</td>
                        <?php if ($comparePeriod): ?>
                            <td class="text-end"><?= number_format($netProfitCompare, 2) ?></td>
                            <td class="text-end">
                                <?php 
                                $variance = $netProfit - $netProfitCompare;
                                $variancePct = $netProfitCompare != 0 ? ($variance / abs($netProfitCompare)) * 100 : 0;
                                ?>
                                <span class="text-<?= $variance >= 0 ? 'success' : 'danger' ?>">
                                    <?= $variance >= 0 ? '+' : '' ?><?= number_format($variance, 2) ?>
                                    (<?= $variance >= 0 ? '+' : '' ?><?= number_format($variancePct, 1) ?>%)
                                </span>
                            </td>
                        <?php endif; ?>
                        <td class="text-end">
                            <strong class="text-<?= $netProfit >= 0 ? 'success' : 'danger' ?>">
                                <?= number_format($netProfit, 2) ?> AED
                            </strong>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function toggleCompare() {
    const checkboxes = document.querySelectorAll('.compare-period');
    checkboxes.forEach(cb => {
        cb.style.display = document.getElementById('compare').checked ? 'block' : 'none';
    });
}

function exportToCSV() {
    const table = document.getElementById('pnlTable');
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        csv.push(row.join(','));
    }
    
    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = 'profit_loss_<?= date('Y-m-d', strtotime($dateFrom)) ?>_<?= date('Y-m-d', strtotime($dateTo)) ?>.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
