<?php
/**
 * Real Estate Accounting - General Ledger
 * Shows all transactions for a specific account
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
$hasReFinancial = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);
$hasCoFinancial = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn);
if (!$hasReFinancial && !$hasCoFinancial) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$sessionCompanyId = current_company_id($conn) ?: 1;

/**
 * Resolve the company whose ledger is being viewed.
 *
 * Deep links such as ?company_id=8 (ARS Home Rentals) must keep working across filter
 * changes. The session company cannot be used for this: re_layout_header.php calls
 * ensure_current_company_supports_module() on every render and pushes the session back
 * to a Real Estate company, so a non-Real-Estate ledger would survive exactly one
 * request. Read the company from the query string instead and never touch the session.
 *
 * Fail closed: fall back to the session company unless the user really has access.
 */
function gl_resolve_view_company(PDO $conn, int $sessionCompanyId, ?int $userId): int {
    $requested = !empty($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    if ($requested <= 0 || $requested === $sessionCompanyId) {
        return $sessionCompanyId;
    }
    $uid = (int)($userId ?? 0);
    if ($uid > 0 && user_has_company_access($conn, $uid, $requested)) {
        return $requested;
    }
    return $sessionCompanyId;
}

$userId = current_user_id();
$currentCompanyId = gl_resolve_view_company($conn, $sessionCompanyId, $userId);
// Only carried in links/forms when it differs from the session company.
$viewCompanyParam = $currentCompanyId !== $sessionCompanyId ? $currentCompanyId : 0;
$viewCompany = $viewCompanyParam ? get_company($conn, $currentCompanyId) : null;

// Get filter parameters
$accountId = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : null;
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Get all accounts for dropdown, grouped by type
$accounts = $conn->prepare("
    SELECT id, account_code, account_name, account_type
    FROM re_chart_of_accounts
    WHERE company_id = ? AND is_active = 1 AND is_header = 0
    ORDER BY FIELD(account_type,'Asset','Liability','Equity','Income','Expense'), account_code
");
$accounts->execute([$currentCompanyId]);
$allAccounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

// When no account selected: fetch summary of ALL accounts with activity
$accountsSummary = [];
if (!$accountId) {
    $summaryStmt = $conn->prepare("
        SELECT
            coa.id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance,
            COUNT(gl.id)              AS entry_count,
            COALESCE(SUM(gl.debit_amount), 0)  AS total_debit,
            COALESCE(SUM(gl.credit_amount), 0) AS total_credit,
            MAX(gl.entry_date)        AS last_activity,
            COALESCE(
                CASE WHEN coa.normal_balance = 'debit'
                     THEN SUM(gl.debit_amount) - SUM(gl.credit_amount)
                     ELSE SUM(gl.credit_amount) - SUM(gl.debit_amount)
                END, 0)              AS balance
        FROM re_chart_of_accounts coa
        LEFT JOIN re_general_ledger gl ON gl.account_id = coa.id AND gl.company_id = ?
        WHERE coa.company_id = ? AND coa.is_active = 1 AND coa.is_header = 0
        GROUP BY coa.id
        HAVING entry_count > 0
        ORDER BY FIELD(coa.account_type,'Asset','Liability','Equity','Income','Expense'), coa.account_code
    ");
    $summaryStmt->execute([$currentCompanyId, $currentCompanyId]);
    $accountsSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get ledger entries if account selected
$ledgerEntries = [];
$accountInfo = null;
$openingBalance = 0;
$closingBalance = 0;

if ($accountId) {
    // Get account info
    $stmt = $conn->prepare("
        SELECT * FROM re_chart_of_accounts
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$accountId, $currentCompanyId]);
    $accountInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($accountInfo) {
        // Get opening balance from first principles (SUM of all entries before date_from).
        // Avoids relying on the stored running-balance column which can be stale.
        $normalBalance = $accountInfo['normal_balance'] ?? 'debit';
        if ($normalBalance === 'debit') {
            $openingBalanceSql = "SELECT COALESCE(SUM(debit_amount) - SUM(credit_amount), 0) AS bal
                                  FROM re_general_ledger
                                  WHERE account_id = ? AND company_id = ? AND entry_date < ?";
        } else {
            $openingBalanceSql = "SELECT COALESCE(SUM(credit_amount) - SUM(debit_amount), 0) AS bal
                                  FROM re_general_ledger
                                  WHERE account_id = ? AND company_id = ? AND entry_date < ?";
        }
        $stmt = $conn->prepare($openingBalanceSql);
        $stmt->execute([$accountId, $currentCompanyId, $dateFrom]);
        $openingEntry = $stmt->fetch(PDO::FETCH_ASSOC);
        $openingBalance = $openingEntry ? (float)$openingEntry['bal'] : 0;
        
        // Get ledger entries
        $stmt = $conn->prepare("
            SELECT 
                gl.*,
                jh.journal_number,
                jh.journal_type,
                jh.journal_date,
                jh.description as journal_description
            FROM re_general_ledger gl
            JOIN re_journal_headers jh ON jh.id = gl.journal_id
            WHERE gl.account_id = ? AND gl.company_id = ?
            AND gl.entry_date BETWEEN ? AND ?
            ORDER BY gl.entry_date ASC, gl.id ASC
        ");
        $stmt->execute([$accountId, $currentCompanyId, $dateFrom, $dateTo]);
        $ledgerEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate closing balance from opening balance + period activity.
        $periodDebit = array_sum(array_column($ledgerEntries, 'debit_amount'));
        $periodCredit = array_sum(array_column($ledgerEntries, 'credit_amount'));
        if ($normalBalance === 'debit') {
            $closingBalance = $openingBalance + $periodDebit - $periodCredit;
        } else {
            $closingBalance = $openingBalance + $periodCredit - $periodDebit;
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'General Ledger';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div class="page-header-label">
        <i class="bi bi-journal-text"></i> General Ledger
        <?php if ($accountInfo): ?>
            <small class="text-muted fs-6 fw-normal ms-2">
                — <?= h($accountInfo['account_code']) ?> · <?= h($accountInfo['account_name']) ?>
            </small>
        <?php endif; ?>
        <?php if ($viewCompany): ?>
            <span class="badge bg-warning text-dark fs-6 fw-normal ms-2" title="You are viewing another company's ledger, not your active company.">
                <i class="bi bi-building"></i> <?= h($viewCompany['name']) ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <?php if ($accountId): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportToCSV()">
                <i class="bi bi-download"></i> CSV
            </button>
            <a href="general_ledger.php<?= $viewCompanyParam ? '?company_id=' . $viewCompanyParam : '' ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-grid-3x3-gap"></i> All Accounts
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card card-round mb-4 no-print">
    <div class="card-body">
        <!-- Quick date shortcuts -->
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="text-muted small align-self-center me-1"><i class="bi bi-lightning-fill"></i> Quick:</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_month')">This Month</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('last_month')">Last Month</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_quarter')">This Quarter</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_year')">This Year</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('all_time')">All Time</button>
        </div>
        <form method="GET" class="row g-3" id="glForm">
            <?php if ($viewCompanyParam): ?>
                <input type="hidden" name="company_id" value="<?= (int)$viewCompanyParam ?>">
            <?php endif; ?>
            <div class="col-md-5">
                <label class="form-label fw-semibold"><i class="bi bi-tag"></i> Account</label>
                <select name="account_id" class="form-select" id="accountSelect" onchange="this.form.submit()">
                    <option value="">— Select an account to drill down —</option>
                    <?php
                    $currentType = '';
                    foreach ($allAccounts as $acc):
                        if ($acc['account_type'] !== $currentType):
                            if ($currentType !== '') echo '</optgroup>';
                            $currentType = $acc['account_type'];
                            echo '<optgroup label="' . h($acc['account_type']) . '">';
                        endif;
                    ?>
                        <option value="<?= $acc['id'] ?>" <?= $accountId == $acc['id'] ? 'selected' : '' ?>>
                            <?= h($acc['account_code']) ?> — <?= h($acc['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ($currentType !== '') echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold"><i class="bi bi-calendar-event"></i> From Date</label>
                <input type="date" name="date_from" id="dateFrom" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold"><i class="bi bi-calendar-check"></i> To Date</label>
                <input type="date" name="date_to" id="dateTo" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (!$accountId && !empty($accountsSummary)): ?>
<!-- ── Accounts Overview (no account selected) ─────────────────────────── -->
<?php
    $typeBadge = [
        'Asset'     => 'bg-primary',
        'Liability' => 'bg-danger',
        'Equity'    => 'bg-warning text-dark',
        'Income'    => 'bg-success',
        'Expense'   => 'bg-secondary',
    ];
    $groupedSummary = [];
    foreach ($accountsSummary as $row) {
        $groupedSummary[$row['account_type']][] = $row;
    }
?>
<div class="mb-3">
    <h5 class="fw-bold text-muted"><i class="bi bi-grid-3x3-gap-fill"></i> Accounts with Activity — click any account to view its ledger</h5>
</div>
<?php foreach ($groupedSummary as $typeName => $rows): ?>
<div class="card card-round mb-3">
    <div class="card-header py-2">
        <span class="badge <?= $typeBadge[$typeName] ?? 'bg-secondary' ?> me-2"><?= h($typeName) ?></span>
        <strong><?= count($rows) ?> account<?= count($rows) > 1 ? 's' : '' ?></strong>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:10%">Code</th>
                    <th>Account Name</th>
                    <th class="text-center" style="width:10%">Entries</th>
                    <th class="text-end" style="width:14%">Total Debit</th>
                    <th class="text-end" style="width:14%">Total Credit</th>
                    <th class="text-end" style="width:14%">Balance</th>
                    <th class="text-center" style="width:10%">Last Activity</th>
                    <th class="text-center" style="width:8%">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><code><?= h($row['account_code']) ?></code></td>
                    <td><?= h($row['account_name']) ?></td>
                    <td class="text-center">
                        <span class="badge bg-light text-dark"><?= number_format($row['entry_count']) ?></span>
                    </td>
                    <td class="text-end"><?= $row['total_debit'] > 0 ? number_format($row['total_debit'], 2) : '—' ?></td>
                    <td class="text-end"><?= $row['total_credit'] > 0 ? number_format($row['total_credit'], 2) : '—' ?></td>
                    <td class="text-end">
                        <strong class="text-<?= $row['balance'] >= 0 ? 'primary' : 'danger' ?>">
                            <?= number_format($row['balance'], 2) ?>
                        </strong>
                    </td>
                    <td class="text-center text-muted small">
                        <?= $row['last_activity'] ? date('d M Y', strtotime($row['last_activity'])) : '—' ?>
                    </td>
                    <td class="text-center">
                        <a href="general_ledger.php?account_id=<?= $row['id'] ?>&date_from=<?= date('Y-01-01') ?>&date_to=<?= date('Y-m-d') ?><?= $viewCompanyParam ? '&company_id=' . $viewCompanyParam : '' ?>"
                           class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php elseif (!$accountId): ?>
<!-- Empty state -->
<div class="card card-round">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-journal-text fs-1 d-block mb-3 opacity-50"></i>
        <h5>No Activity Yet</h5>
        <p>No GL entries found for this company. Run the migration to post accounting data.</p>
    </div>
</div>
<?php endif; ?>

<?php if ($accountInfo): ?>
    <!-- Account Summary -->
    <div class="card card-round mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Account Code:</strong><br>
                    <code><?= h($accountInfo['account_code']) ?></code>
                </div>
                <div class="col-md-3">
                    <strong>Account Name:</strong><br>
                    <?= h($accountInfo['account_name']) ?>
                </div>
                <div class="col-md-2">
                    <strong>Type:</strong><br>
                    <span class="badge bg-light text-dark"><?= h($accountInfo['account_type']) ?></span>
                </div>
                <div class="col-md-2">
                    <strong>Opening Balance:</strong><br>
                    <span class="text-<?= $openingBalance >= 0 ? 'primary' : 'danger' ?>">
                        <?= number_format($openingBalance, 2) ?> AED
                    </span>
                </div>
                <div class="col-md-2">
                    <strong>Closing Balance:</strong><br>
                    <span class="text-<?= $closingBalance >= 0 ? 'primary' : 'danger' ?>">
                        <strong><?= number_format($closingBalance, 2) ?> AED</strong>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Period Stats Bar -->
    <?php
    $periodDebitTotal  = array_sum(array_column($ledgerEntries, 'debit_amount'));
    $periodCreditTotal = array_sum(array_column($ledgerEntries, 'credit_amount'));
    ?>
    <div class="row g-3 mb-3">
        <div class="col-sm-3">
            <div class="card border-0 bg-light text-center py-2">
                <div class="small text-muted">Entries in Period</div>
                <div class="fw-bold fs-5"><?= number_format(count($ledgerEntries)) ?></div>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="card border-0 bg-primary bg-opacity-10 text-center py-2">
                <div class="small text-muted">Period Debits</div>
                <div class="fw-bold text-primary"><?= number_format($periodDebitTotal, 2) ?></div>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="card border-0 bg-danger bg-opacity-10 text-center py-2">
                <div class="small text-muted">Period Credits</div>
                <div class="fw-bold text-danger"><?= number_format($periodCreditTotal, 2) ?></div>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="card border-0 bg-success bg-opacity-10 text-center py-2">
                <div class="small text-muted">Closing Balance</div>
                <div class="fw-bold text-<?= $closingBalance >= 0 ? 'success' : 'danger' ?>"><?= number_format($closingBalance, 2) ?></div>
            </div>
        </div>
    </div>

    <!-- Ledger Entries -->
    <div class="card card-round">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="bi bi-list-ul"></i> Ledger Entries
                <small class="text-muted"><?= date('M d, Y', strtotime($dateFrom)) ?> to <?= date('M d, Y', strtotime($dateTo)) ?></small>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="ledgerTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 10%"><i class="bi bi-calendar"></i> Date</th>
                            <th style="width: 12%"><i class="bi bi-hash"></i> Journal #</th>
                            <th style="width: 10%"><i class="bi bi-tag"></i> Type</th>
                            <th style="width: 30%"><i class="bi bi-file-text"></i> Description</th>
                            <th style="width: 12%"><i class="bi bi-hash"></i> Reference</th>
                            <th style="width: 13%" class="text-end"><i class="bi bi-arrow-down-left"></i> Debit</th>
                            <th style="width: 13%" class="text-end"><i class="bi bi-arrow-up-right"></i> Credit</th>
                            <th style="width: 13%" class="text-end"><i class="bi bi-calculator"></i> Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ledgerEntries)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    No transactions found for this period
                                </td>
                            </tr>
                        <?php else: ?>
                            <!-- Opening Balance Row -->
                            <tr class="table-secondary">
                                <td colspan="7" class="text-end fw-bold">Opening Balance:</td>
                                <td class="text-end fw-bold">
                                    <?= number_format($openingBalance, 2) ?>
                                </td>
                            </tr>
                            
                            <?php $runningBalance = $openingBalance; ?>
                            <?php foreach ($ledgerEntries as $entry): ?>
                                <?php
                                    // Recalculate running balance from first principles (don't trust stored column)
                                    if ($normalBalance === 'debit') {
                                        $runningBalance += (float)$entry['debit_amount'] - (float)$entry['credit_amount'];
                                    } else {
                                        $runningBalance += (float)$entry['credit_amount'] - (float)$entry['debit_amount'];
                                    }
                                ?>
                                <tr>
                                    <td><?= date('Y-m-d', strtotime($entry['entry_date'])) ?></td>
                                    <td>
                                        <a href="journal_entry_view.php?id=<?= $entry['journal_id'] ?>&company_id=<?= (int)$currentCompanyId ?>" class="text-decoration-none">
                                            <?= h($entry['journal_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= h($entry['journal_type']) ?></span>
                                    </td>
                                    <td><?= h($entry['description'] ?: $entry['journal_description']) ?></td>
                                    <td><code><?= h($entry['reference'] ?: '-') ?></code></td>
                                    <td class="text-end">
                                        <?php if ($entry['debit_amount'] > 0): ?>
                                            <strong><?= number_format($entry['debit_amount'], 2) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($entry['credit_amount'] > 0): ?>
                                            <strong><?= number_format($entry['credit_amount'], 2) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-<?= $runningBalance >= 0 ? 'primary' : 'danger' ?>">
                                            <?= number_format($runningBalance, 2) ?>
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <!-- Closing Balance Row -->
                            <tr class="table-dark fw-bold">
                                <td colspan="7" class="text-end">Closing Balance:</td>
                                <td class="text-end">
                                    <?= number_format($closingBalance, 2) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    function setDateRange(range) {
        const today = new Date();
        let from, to;
        const pad = n => String(n).padStart(2, '0');
        const fmt = d => d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
        switch (range) {
            case 'this_month':
                from = new Date(today.getFullYear(), today.getMonth(), 1);
                to   = today;
                break;
            case 'last_month':
                from = new Date(today.getFullYear(), today.getMonth()-1, 1);
                to   = new Date(today.getFullYear(), today.getMonth(), 0);
                break;
            case 'this_quarter':
                const q = Math.floor(today.getMonth()/3);
                from = new Date(today.getFullYear(), q*3, 1);
                to   = today;
                break;
            case 'this_year':
                from = new Date(today.getFullYear(), 0, 1);
                to   = today;
                break;
            case 'all_time':
                from = new Date('2000-01-01');
                to   = today;
                break;
        }
        document.getElementById('dateFrom').value = fmt(from);
        document.getElementById('dateTo').value   = fmt(to);
        document.getElementById('glForm').submit();
    }

    function exportToCSV() {
        const table = document.getElementById('ledgerTable');
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
        downloadLink.download = 'general_ledger_<?= h($accountInfo['account_code']) ?>_<?= date('Y-m-d', strtotime($dateFrom)) ?>.csv';
        downloadLink.href = window.URL.createObjectURL(csvFile);
        downloadLink.style.display = 'none';
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
    }
    </script>
<?php endif; ?>

<?php if (!$accountId): ?>
<script>
function setDateRange(range) {
    const today = new Date();
    let from, to;
    const pad = n => String(n).padStart(2, '0');
    const fmt = d => d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
    switch (range) {
        case 'this_month':
            from = new Date(today.getFullYear(), today.getMonth(), 1); to = today; break;
        case 'last_month':
            from = new Date(today.getFullYear(), today.getMonth()-1, 1);
            to   = new Date(today.getFullYear(), today.getMonth(), 0); break;
        case 'this_quarter':
            const q = Math.floor(today.getMonth()/3);
            from = new Date(today.getFullYear(), q*3, 1); to = today; break;
        case 'this_year':
            from = new Date(today.getFullYear(), 0, 1); to = today; break;
        case 'all_time':
            from = new Date('2000-01-01'); to = today; break;
    }
    document.getElementById('dateFrom').value = fmt(from);
    document.getElementById('dateTo').value   = fmt(to);
    document.getElementById('glForm').submit();
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
