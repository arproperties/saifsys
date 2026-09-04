<?php
/**
 * Real Estate Accounting - Balance Sheet
 * Point-in-time position as of one date (Zoho Books / Xero / Construction).
 * For period movements use Trial Balance → Date range.
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
} else {
    ensure_current_company_supports_module($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required for Balance Sheet.');
}

// As of date only (date_to accepted as alias for bookmarks / Construction parity)
$asOfDate = !empty($_GET['as_of_date']) ? $_GET['as_of_date'] : (!empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d'));

// Calculate balance from first principles: SUM(debit) - SUM(credit) or vice versa.
// This is always correct regardless of the stored running-balance column.

// Get assets (debit-normal: balance = SUM(debit) - SUM(credit))
$assets = $conn->prepare("
    SELECT coa.id, coa.account_code, coa.account_name,
           COALESCE(
               (SELECT SUM(gl.debit_amount) - SUM(gl.credit_amount)
                FROM re_general_ledger gl
                WHERE gl.account_id = coa.id AND gl.company_id = ? AND gl.entry_date <= ?),
               0
           ) AS balance
    FROM re_chart_of_accounts coa
    WHERE coa.company_id = ? AND coa.account_type = 'Asset'
      AND coa.is_active = 1 AND coa.is_header = 0
    HAVING ABS(balance) > 0.01
    ORDER BY coa.account_code
");
$assets->execute([$currentCompanyId, $asOfDate, $currentCompanyId]);
$assetData = $assets->fetchAll(PDO::FETCH_ASSOC);

// Get liabilities (credit-normal: balance = SUM(credit) - SUM(debit))
$liabilities = $conn->prepare("
    SELECT coa.id, coa.account_code, coa.account_name,
           COALESCE(
               (SELECT SUM(gl.credit_amount) - SUM(gl.debit_amount)
                FROM re_general_ledger gl
                WHERE gl.account_id = coa.id AND gl.company_id = ? AND gl.entry_date <= ?),
               0
           ) AS balance
    FROM re_chart_of_accounts coa
    WHERE coa.company_id = ? AND coa.account_type = 'Liability'
      AND coa.is_active = 1 AND coa.is_header = 0
    HAVING ABS(balance) > 0.01
    ORDER BY coa.account_code
");
$liabilities->execute([$currentCompanyId, $asOfDate, $currentCompanyId]);
$liabilityData = $liabilities->fetchAll(PDO::FETCH_ASSOC);

// Get equity (credit-normal: balance = SUM(credit) - SUM(debit))
$equity = $conn->prepare("
    SELECT coa.id, coa.account_code, coa.account_name,
           COALESCE(
               (SELECT SUM(gl.credit_amount) - SUM(gl.debit_amount)
                FROM re_general_ledger gl
                WHERE gl.account_id = coa.id AND gl.company_id = ? AND gl.entry_date <= ?),
               0
           ) AS balance
    FROM re_chart_of_accounts coa
    WHERE coa.company_id = ? AND coa.account_type = 'Equity'
      AND coa.is_active = 1 AND coa.is_header = 0
    HAVING ABS(balance) > 0.01
    ORDER BY coa.account_code
");
$equity->execute([$currentCompanyId, $asOfDate, $currentCompanyId]);
$equityData = $equity->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalAssets = array_sum(array_column($assetData, 'balance'));
$totalLiabilities = array_sum(array_column($liabilityData, 'balance'));
$totalEquity = array_sum(array_column($equityData, 'balance'));

// Current Year Earnings = Net Income (all Income minus all Expense) up to asOfDate.
// Computed directly from GL so it always reflects real P&L, no separate account needed.
$stmt = $conn->prepare("
    SELECT COALESCE(
        SUM(
            CASE
                WHEN coa.account_type = 'Income'  THEN gl.credit_amount - gl.debit_amount
                WHEN coa.account_type = 'Expense' THEN gl.debit_amount  - gl.credit_amount
                ELSE 0
            END
        ), 0) AS net_income
    FROM re_general_ledger gl
    JOIN re_chart_of_accounts coa ON coa.id = gl.account_id AND coa.company_id = gl.company_id
    WHERE gl.company_id = ?
      AND coa.account_type IN ('Income', 'Expense')
      AND gl.entry_date <= ?
");
$stmt->execute([$currentCompanyId, $asOfDate]);
$earningsRow = $stmt->fetch(PDO::FETCH_ASSOC);
$currentYearEarningsAmount = $earningsRow ? (float)$earningsRow['net_income'] : 0;

$totalEquity += $currentYearEarningsAmount;
$totalLiabilitiesAndEquity = $totalLiabilities + $totalEquity;

if (!empty($_GET['export'])) {
    require_once __DIR__ . '/export_excel_helper.php';
    $bsRows = [];
    foreach ($assetData as $r) {
        $bsRows[] = ['section' => 'Asset', 'account_code' => $r['account_code'], 'account_name' => $r['account_name'], 'balance' => $r['balance']];
    }
    $bsRows[] = ['section' => 'Asset', 'account_code' => '', 'account_name' => 'Total Assets', 'balance' => $totalAssets];
    foreach ($liabilityData as $r) {
        $bsRows[] = ['section' => 'Liability', 'account_code' => $r['account_code'], 'account_name' => $r['account_name'], 'balance' => $r['balance']];
    }
    foreach ($equityData as $r) {
        $bsRows[] = ['section' => 'Equity', 'account_code' => $r['account_code'], 'account_name' => $r['account_name'], 'balance' => $r['balance']];
    }
    $bsRows[] = ['section' => 'Equity', 'account_code' => '', 'account_name' => 'Total Liabilities & Equity', 'balance' => $totalLiabilitiesAndEquity];
    $cols = ['section' => 'Section', 'account_code' => 'Code', 'account_name' => 'Account', 'balance' => 'Balance (AED)'];
    $filename = 'balance_sheet_' . $asOfDate;
    $title = 'Balance Sheet - As of ' . date('F d, Y', strtotime($asOfDate));
    if ($_GET['export'] === 'excel') {
        accounting_export_excel_or_csv($bsRows, $cols, $filename, $title);
    } else {
        accounting_export_csv($bsRows, $cols, $filename);
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Balance Sheet';
$companyMeta = get_company($conn, $currentCompanyId);
$companyLabel = $companyMeta['name'] ?? ('Company #' . $currentCompanyId);
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div class="page-header-label">
        <i class="bi bi-file-earmark-spreadsheet"></i> Balance Sheet
        <small class="text-muted fw-normal ms-2 d-block d-md-inline">Company: <?= h($companyLabel) ?></small>
    </div>
    <div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
        <a href="?as_of_date=<?= h($asOfDate) ?>&export=csv" class="btn btn-outline-primary"><i class="bi bi-download"></i> CSV</a>
        <a href="?as_of_date=<?= h($asOfDate) ?>&export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    </div>
</div>

<!-- Filters -->
<div class="card card-round mb-4 no-print">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar"></i> As Of Date</label>
                <input type="date" name="as_of_date" class="form-control" value="<?= h($asOfDate) ?>" required>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel-fill"></i> Generate Report
                </button>
            </div>
            <div class="col-12">
                <p class="small text-muted mb-0">
                    Like Zoho Books / Xero, Balance Sheet is always <strong>as of one date</strong> (position statement).
                    For a week or month of movements, use
                    <a href="trial_balance.php?mode=range">Trial Balance → Date range</a>.
                </p>
            </div>
        </form>
    </div>
</div>

<!-- Balance Sheet -->
<div class="card card-round">
    <div class="card-header bg-light">
        <h5 class="mb-0">
            <i class="bi bi-file-text"></i> Balance Sheet
            <small class="text-muted">As of <?= date('F d, Y', strtotime($asOfDate)) ?></small>
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="balanceSheetTable">
                <thead class="table-light">
                    <tr>
                        <th style="width: 10%"><i class="bi bi-hash"></i> Code</th>
                        <th style="width: 40%"><i class="bi bi-tag"></i> Account</th>
                        <th style="width: 25%" class="text-end">Amount (AED)</th>
                        <th style="width: 10%"><i class="bi bi-hash"></i> Code</th>
                        <th style="width: 40%"><i class="bi bi-tag"></i> Account</th>
                        <th style="width: 25%" class="text-end">Amount (AED)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <!-- Assets Column -->
                        <td colspan="3" class="table-primary fw-bold">
                            <i class="bi bi-arrow-up-circle"></i> ASSETS
                        </td>
                        <!-- Liabilities Column -->
                        <td colspan="3" class="table-danger fw-bold">
                            <i class="bi bi-arrow-down-circle"></i> LIABILITIES
                        </td>
                    </tr>
                    
                    <?php
                    $maxRows = max(count($assetData), count($liabilityData));
                    for ($i = 0; $i < $maxRows; $i++):
                        $asset = $assetData[$i] ?? null;
                        $liab = $liabilityData[$i] ?? null;
                    ?>
                        <tr>
                            <!-- Asset Row -->
                            <td class="<?= $asset ? '' : 'border-0' ?>">
                                <?php if ($asset): ?>
                                    <a href="general_ledger.php?account_id=<?= (int)$asset['id'] ?>&date_from=<?= h(date('Y-01-01', strtotime($asOfDate))) ?>&date_to=<?= h($asOfDate) ?>"><code><?= h($asset['account_code']) ?></code></a>
                                <?php endif; ?>
                            </td>
                            <td class="<?= $asset ? '' : 'border-0' ?>">
                                <?php if ($asset): ?>
                                    <a href="general_ledger.php?account_id=<?= (int)$asset['id'] ?>&date_from=<?= h(date('Y-01-01', strtotime($asOfDate))) ?>&date_to=<?= h($asOfDate) ?>"><?= h($asset['account_name']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="text-end <?= $asset ? '' : 'border-0' ?>">
                                <?php if ($asset): ?>
                                    <strong><?= number_format($asset['balance'], 2) ?></strong>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Liability Row -->
                            <td class="<?= $liab ? '' : 'border-0' ?>">
                                <?php if ($liab): ?>
                                    <a href="general_ledger.php?account_id=<?= (int)$liab['id'] ?>&date_from=<?= h(date('Y-01-01', strtotime($asOfDate))) ?>&date_to=<?= h($asOfDate) ?>"><code><?= h($liab['account_code']) ?></code></a>
                                <?php endif; ?>
                            </td>
                            <td class="<?= $liab ? '' : 'border-0' ?>">
                                <?php if ($liab): ?>
                                    <a href="general_ledger.php?account_id=<?= (int)$liab['id'] ?>&date_from=<?= h(date('Y-01-01', strtotime($asOfDate))) ?>&date_to=<?= h($asOfDate) ?>"><?= h($liab['account_name']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="text-end <?= $liab ? '' : 'border-0' ?>">
                                <?php if ($liab): ?>
                                    <strong><?= number_format($liab['balance'], 2) ?></strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endfor; ?>
                    
                    <!-- Total Assets / Total Liabilities -->
                    <tr class="table-success fw-bold">
                        <td colspan="2" class="text-end">Total Assets:</td>
                        <td class="text-end"><?= number_format($totalAssets, 2) ?></td>
                        <td colspan="2" class="text-end">Total Liabilities:</td>
                        <td class="text-end"><?= number_format($totalLiabilities, 2) ?></td>
                    </tr>
                    
                    <!-- Equity Section -->
                    <tr>
                        <td colspan="3" class="table-info fw-bold">
                            <i class="bi bi-piggy-bank"></i> EQUITY
                        </td>
                        <td colspan="3" class="border-0"></td>
                    </tr>
                    
                    <?php foreach ($equityData as $eq): ?>
                        <tr>
                            <td><a href="general_ledger.php?account_id=<?= (int)$eq['id'] ?>&date_from=<?= h(date('Y-01-01', strtotime($asOfDate))) ?>&date_to=<?= h($asOfDate) ?>"><code><?= h($eq['account_code']) ?></code></a></td>
                            <td><a href="general_ledger.php?account_id=<?= (int)$eq['id'] ?>&date_from=<?= h(date('Y-01-01', strtotime($asOfDate))) ?>&date_to=<?= h($asOfDate) ?>"><?= h($eq['account_name']) ?></a></td>
                            <td class="text-end">
                                <strong><?= number_format($eq['balance'], 2) ?></strong>
                            </td>
                            <td colspan="3" class="border-0"></td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if ($currentYearEarningsAmount != 0): ?>
                        <tr>
                            <td></td>
                            <td>
                                <a href="profit_loss.php" class="text-decoration-none">
                                    <i class="bi bi-graph-up text-success"></i> Net Income (Current Earnings)
                                </a>
                            </td>
                            <td class="text-end">
                                <strong class="text-<?= $currentYearEarningsAmount >= 0 ? 'success' : 'danger' ?>">
                                    <?= number_format($currentYearEarningsAmount, 2) ?>
                                </strong>
                            </td>
                            <td colspan="3" class="border-0"></td>
                        </tr>
                    <?php endif; ?>
                    
                    <!-- Total Equity / Total Liabilities & Equity -->
                    <tr class="table-info fw-bold">
                        <td colspan="2" class="text-end">Total Equity:</td>
                        <td class="text-end"><?= number_format($totalEquity, 2) ?></td>
                        <td colspan="2" class="text-end">Total Liabilities & Equity:</td>
                        <td class="text-end"><?= number_format($totalLiabilitiesAndEquity, 2) ?></td>
                    </tr>
                    
                    <!-- Balance Check -->
                    <?php if (abs($totalAssets - $totalLiabilitiesAndEquity) > 0.01): ?>
                        <tr class="table-danger">
                            <td colspan="3" class="text-end"><strong>Out of Balance:</strong></td>
                            <td colspan="3" class="text-end">
                                <strong><?= number_format(abs($totalAssets - $totalLiabilitiesAndEquity), 2) ?> AED</strong>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr class="table-success">
                            <td colspan="6" class="text-center">
                                <strong>✓ Balance Sheet is Balanced</strong>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function exportToCSV() {
    const table = document.getElementById('balanceSheetTable');
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
    downloadLink.download = 'balance_sheet_<?= date('Y-m-d', strtotime($asOfDate)) ?>.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
