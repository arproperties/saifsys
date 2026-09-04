<?php
/**
 * Real Estate Accounting - Cash Flow Statement
 * Operating, Investing, Financing (simplified indirect method)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/accounting_engine.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-01-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

$incomeTotal = 0;
$expenseTotal = 0;
$stmt = $conn->prepare("
    SELECT coa.id, coa.account_type, coa.normal_balance,
           COALESCE(SUM(CASE WHEN gl.entry_date BETWEEN ? AND ? THEN gl.debit_amount - gl.credit_amount ELSE 0 END), 0) as period_flow
    FROM re_chart_of_accounts coa
    LEFT JOIN re_general_ledger gl ON gl.account_id = coa.id AND gl.company_id = ?
    WHERE coa.company_id = ? AND coa.is_active = 1 AND coa.is_header = 0
    AND coa.account_type IN ('Income', 'Expense')
    GROUP BY coa.id, coa.account_type, coa.normal_balance
");
$stmt->execute([$dateFrom, $dateTo, $currentCompanyId, $currentCompanyId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $flow = (float)$row['period_flow']; // debit - credit
    if ($row['account_type'] === 'Income') {
        $incomeTotal += -$flow; // income = credit - debit
    } else {
        $expenseTotal += $flow; // expense = debit - credit
    }
}
$netIncome = $incomeTotal - $expenseTotal;

$arStart = 0; $arEnd = 0;
$apStart = 0; $apEnd = 0;
$cashStart = 0; $cashEnd = 0;
$arAccount = find_account_by_code('1310', $currentCompanyId);
$apAccount = find_account_by_code('2130', $currentCompanyId);
$cashAccounts = $conn->prepare("
    SELECT id FROM re_chart_of_accounts
    WHERE company_id = ? AND account_type = 'Asset' AND is_header = 0 AND is_active = 1
    AND (account_code LIKE '11%' OR account_code LIKE '12%')
");
$cashAccounts->execute([$currentCompanyId]);
$cashIds = $cashAccounts->fetchAll(PDO::FETCH_COLUMN);
if ($arAccount) {
    $arStart = get_account_balance($arAccount['id'], $currentCompanyId, date('Y-m-d', strtotime($dateFrom . ' -1 day')));
    $arEnd = get_account_balance($arAccount['id'], $currentCompanyId, $dateTo);
}
if ($apAccount) {
    $apStart = get_account_balance($apAccount['id'], $currentCompanyId, date('Y-m-d', strtotime($dateFrom . ' -1 day')));
    $apEnd = get_account_balance($apAccount['id'], $currentCompanyId, $dateTo);
}
foreach ($cashIds as $cid) {
    $cashStart += get_account_balance($cid, $currentCompanyId, date('Y-m-d', strtotime($dateFrom . ' -1 day')));
    $cashEnd += get_account_balance($cid, $currentCompanyId, $dateTo);
}
$changeAR = $arEnd - $arStart;
$changeAP = $apEnd - $apStart;
$changeCash = $cashEnd - $cashStart;
$operating = $netIncome - $changeAR + $changeAP;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Cash Flow Statement';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-cash-stack"></i> Cash Flow Statement</div>
    <div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <a href="?date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>&export=csv" class="btn btn-outline-primary"><i class="bi bi-download"></i> CSV</a>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">View</button>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <h5 class="mb-3">Cash Flow Statement (Indirect Method)</h5>
        <p class="text-muted"><?= date('M d, Y', strtotime($dateFrom)) ?> to <?= date('M d, Y', strtotime($dateTo)) ?></p>
        <table class="table table-borderless">
            <tr><td><strong>Operating Activities</strong></td><td></td></tr>
            <tr><td class="ps-3">Net Income (P&L)</td><td class="text-end"><?= number_format($netIncome, 2) ?> AED</td></tr>
            <tr><td class="ps-3">Decrease / (Increase) in Accounts Receivable</td><td class="text-end"><?= number_format(-$changeAR, 2) ?> AED</td></tr>
            <tr><td class="ps-3">Increase / (Decrease) in Accounts Payable</td><td class="text-end"><?= number_format($changeAP, 2) ?> AED</td></tr>
            <tr><td class="ps-3"><strong>Net Cash from Operating Activities</strong></td><td class="text-end"><strong><?= number_format($operating, 2) ?> AED</strong></td></tr>
            <tr><td colspan="2">&nbsp;</td></tr>
            <tr><td><strong>Investing Activities</strong></td><td></td></tr>
            <tr><td class="ps-3 text-muted">(No investing activities in this period)</td><td class="text-end">0.00 AED</td></tr>
            <tr><td colspan="2">&nbsp;</td></tr>
            <tr><td><strong>Financing Activities</strong></td><td></td></tr>
            <tr><td class="ps-3 text-muted">(No financing activities in this period)</td><td class="text-end">0.00 AED</td></tr>
            <tr><td colspan="2">&nbsp;</td></tr>
            <tr><td><strong>Net Change in Cash</strong></td><td class="text-end"><strong><?= number_format($changeCash, 2) ?> AED</strong></td></tr>
            <tr><td class="ps-3">Cash at beginning of period</td><td class="text-end"><?= number_format($cashStart, 2) ?> AED</td></tr>
            <tr><td class="ps-3">Cash at end of period</td><td class="text-end"><?= number_format($cashEnd, 2) ?> AED</td></tr>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
