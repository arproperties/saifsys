<?php
/**
 * Real Estate Accounting — Trial Balance (Shared RE ledger re_*).
 *
 * Modes (same as Construction / Zoho–Xero practice):
 * - as_of: classic closing Debit/Credit as of one date (default; keeps old bookmarks)
 * - range: period Debit/Credit for From–To; optional Opening/Closing columns
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
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
    // Department path skips require_module_access — still align company for this module
    ensure_current_company_supports_module($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required for Trial Balance.');
}

// Default as_of preserves classic RE bookmarks (?as_of_date=…); use mode=range for period TB
if (!isset($_GET['mode'])) {
    if (!empty($_GET['preset']) || !empty($_GET['date_from']) || (!empty($_GET['date_to']) && empty($_GET['as_of_date']))) {
        $mode = 'range';
    } else {
        $mode = 'as_of';
    }
} else {
    $mode = ($_GET['mode'] === 'range') ? 'range' : 'as_of';
}

$showZeroBalances = !empty($_GET['show_zero']);
$showOpenClose = !empty($_GET['show_open_close']);

$preset = $_GET['preset'] ?? '';
if ($preset === 'this_week') {
    $dateFrom = date('Y-m-d', strtotime('monday this week'));
    $dateTo = date('Y-m-d');
    $mode = 'range';
} elseif ($preset === 'last_7') {
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-6 days'));
    $mode = 'range';
} elseif ($preset === 'this_month') {
    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-d');
    $mode = 'range';
} else {
    $dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-6 days'));
    $dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
    if ($mode === 'as_of') {
        $asOf = !empty($_GET['as_of_date']) ? $_GET['as_of_date'] : (!empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d'));
        $dateTo = $asOf;
        $dateFrom = $asOf;
    } elseif (empty($_GET['date_to']) && !empty($_GET['as_of_date'])) {
        $dateTo = $_GET['as_of_date'];
    }
}
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$asOfDate = $dateTo; // used in as_of mode labels / GL year-start links
$trialBalance = [];
$totalDebit = 0.0;
$totalCredit = 0.0;
$totalOpening = 0.0;
$totalClosingDebit = 0.0;
$totalClosingCredit = 0.0;

if ($mode === 'as_of') {
    $accounts = $conn->prepare("
        SELECT
            coa.id,
            coa.account_code,
            coa.account_name,
            coa.account_type,
            coa.normal_balance,
            coa.is_header,
            COALESCE(
                (SELECT
                    CASE
                        WHEN coa.normal_balance = 'debit'
                            THEN SUM(gl.debit_amount) - SUM(gl.credit_amount)
                        ELSE
                            SUM(gl.credit_amount) - SUM(gl.debit_amount)
                    END
                 FROM re_general_ledger gl
                 WHERE gl.account_id = coa.id
                   AND gl.company_id = ?
                   AND gl.entry_date <= ?),
                0
            ) AS balance
        FROM re_chart_of_accounts coa
        WHERE coa.company_id = ? AND coa.is_active = 1
        ORDER BY
            FIELD(coa.account_type, 'Asset', 'Liability', 'Equity', 'Income', 'Expense'),
            coa.account_code
    ");
    $accounts->execute([$currentCompanyId, $asOfDate, $currentCompanyId]);
    foreach ($accounts->fetchAll(PDO::FETCH_ASSOC) as $account) {
        if (!empty($account['is_header'])) {
            continue;
        }
        $balance = (float)$account['balance'];
        if (!$showZeroBalances && abs($balance) < 0.01) {
            continue;
        }
        $debit = 0.0;
        $credit = 0.0;
        if ($account['normal_balance'] === 'debit') {
            $debit = $balance >= 0 ? $balance : 0.0;
            $credit = $balance < 0 ? abs($balance) : 0.0;
        } else {
            $credit = $balance >= 0 ? $balance : 0.0;
            $debit = $balance < 0 ? abs($balance) : 0.0;
        }
        $trialBalance[] = [
            'id' => (int)$account['id'],
            'account_code' => $account['account_code'],
            'account_name' => $account['account_name'],
            'account_type' => $account['account_type'],
            'debit' => $debit,
            'credit' => $credit,
        ];
        $totalDebit += $debit;
        $totalCredit += $credit;
    }
} else {
    $accounts = $conn->prepare("
        SELECT
            coa.id,
            coa.account_code,
            coa.account_name,
            coa.account_type,
            coa.normal_balance,
            coa.is_header,
            COALESCE(CASE WHEN coa.normal_balance = 'debit'
                THEN SUM(CASE WHEN gl.entry_date IS NOT NULL AND gl.entry_date < ? THEN gl.debit_amount - gl.credit_amount ELSE 0 END)
                ELSE SUM(CASE WHEN gl.entry_date IS NOT NULL AND gl.entry_date < ? THEN gl.credit_amount - gl.debit_amount ELSE 0 END)
            END, 0) AS opening,
            COALESCE(SUM(CASE WHEN gl.entry_date BETWEEN ? AND ? THEN gl.debit_amount ELSE 0 END), 0) AS period_debit,
            COALESCE(SUM(CASE WHEN gl.entry_date BETWEEN ? AND ? THEN gl.credit_amount ELSE 0 END), 0) AS period_credit,
            COALESCE(CASE WHEN coa.normal_balance = 'debit'
                THEN SUM(CASE WHEN gl.entry_date IS NOT NULL AND gl.entry_date <= ? THEN gl.debit_amount - gl.credit_amount ELSE 0 END)
                ELSE SUM(CASE WHEN gl.entry_date IS NOT NULL AND gl.entry_date <= ? THEN gl.credit_amount - gl.debit_amount ELSE 0 END)
            END, 0) AS closing
        FROM re_chart_of_accounts coa
        LEFT JOIN re_general_ledger gl
               ON gl.account_id = coa.id
              AND gl.company_id = coa.company_id
              AND gl.entry_date <= ?
        WHERE coa.company_id = ?
          AND coa.is_active = 1
        GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance, coa.is_header
        ORDER BY
            FIELD(coa.account_type, 'Asset', 'Liability', 'Equity', 'Income', 'Expense'),
            coa.account_code
    ");
    $accounts->execute([
        $dateFrom, $dateFrom,
        $dateFrom, $dateTo,
        $dateFrom, $dateTo,
        $dateTo, $dateTo,
        $dateTo,
        $currentCompanyId,
    ]);
    foreach ($accounts->fetchAll(PDO::FETCH_ASSOC) as $account) {
        if (!empty($account['is_header'])) {
            continue;
        }
        $opening = (float)$account['opening'];
        $periodDebit = (float)$account['period_debit'];
        $periodCredit = (float)$account['period_credit'];
        $closing = (float)$account['closing'];

        if (!$showZeroBalances && abs($periodDebit) < 0.01 && abs($periodCredit) < 0.01) {
            continue;
        }

        $closeDebit = 0.0;
        $closeCredit = 0.0;
        if ($account['normal_balance'] === 'debit') {
            $closeDebit = $closing >= 0 ? $closing : 0.0;
            $closeCredit = $closing < 0 ? abs($closing) : 0.0;
        } else {
            $closeCredit = $closing >= 0 ? $closing : 0.0;
            $closeDebit = $closing < 0 ? abs($closing) : 0.0;
        }

        $trialBalance[] = [
            'id' => (int)$account['id'],
            'account_code' => $account['account_code'],
            'account_name' => $account['account_name'],
            'account_type' => $account['account_type'],
            'opening' => $opening,
            'debit' => $periodDebit,
            'credit' => $periodCredit,
            'closing_debit' => $closeDebit,
            'closing_credit' => $closeCredit,
        ];
        $totalOpening += $opening;
        $totalDebit += $periodDebit;
        $totalCredit += $periodCredit;
        $totalClosingDebit += $closeDebit;
        $totalClosingCredit += $closeCredit;
    }
}

$filterQuery = http_build_query(array_filter([
    'mode' => $mode,
    'as_of_date' => $mode === 'as_of' ? $asOfDate : null,
    'date_from' => $mode === 'range' ? $dateFrom : null,
    'date_to' => $mode === 'range' ? $dateTo : null,
    'show_zero' => $showZeroBalances ? '1' : null,
    'show_open_close' => ($mode === 'range' && $showOpenClose) ? '1' : null,
], static function ($v) {
    return $v !== null && $v !== '';
}));

if (!empty($_GET['export'])) {
    require_once __DIR__ . '/export_excel_helper.php';
    if ($mode === 'as_of') {
        $cols = [
            'account_code' => 'Account Code',
            'account_name' => 'Account Name',
            'account_type' => 'Type',
            'debit' => 'Debit (AED)',
            'credit' => 'Credit (AED)',
        ];
        $filename = 'trial_balance_' . $asOfDate;
        $title = 'Trial Balance - As of ' . date('F d, Y', strtotime($asOfDate));
    } else {
        $cols = [
            'account_code' => 'Account Code',
            'account_name' => 'Account Name',
            'account_type' => 'Type',
            'debit' => 'Period Debit (AED)',
            'credit' => 'Period Credit (AED)',
        ];
        if ($showOpenClose) {
            $cols = [
                'account_code' => 'Account Code',
                'account_name' => 'Account Name',
                'account_type' => 'Type',
                'opening' => 'Opening (AED)',
                'debit' => 'Period Debit (AED)',
                'credit' => 'Period Credit (AED)',
                'closing_debit' => 'Closing Debit (AED)',
                'closing_credit' => 'Closing Credit (AED)',
            ];
        }
        $filename = 'trial_balance_' . $dateFrom . '_' . $dateTo;
        $title = 'Trial Balance - Period ' . $dateFrom . ' to ' . $dateTo;
    }
    if ($_GET['export'] === 'excel') {
        accounting_export_excel_or_csv($trialBalance, $cols, $filename, $title);
    } else {
        accounting_export_csv($trialBalance, $cols, $filename);
    }
}

$baseQuery = static function (array $extra = []) use ($mode, $dateFrom, $dateTo, $asOfDate, $showZeroBalances, $showOpenClose): string {
    return http_build_query(array_merge([
        'mode' => $mode,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'as_of_date' => $asOfDate,
        'show_zero' => $showZeroBalances ? '1' : '',
        'show_open_close' => $showOpenClose ? '1' : '',
    ], $extra));
};

$glDateFrom = $mode === 'range' ? $dateFrom : date('Y-01-01', strtotime($asOfDate));
$glDateTo = $mode === 'range' ? $dateTo : $asOfDate;
$colCount = ($mode === 'range' && $showOpenClose) ? 8 : 5;

$pageTitle = 'Trial Balance';
$companyMeta = get_company($conn, $currentCompanyId);
$companyLabel = $companyMeta['name'] ?? ('Company #' . $currentCompanyId);
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print flex-wrap gap-2">
    <div class="page-header-label">
        <i class="bi bi-calculator"></i> Trial Balance
        <small class="text-muted fw-normal ms-2 d-block d-md-inline">Company: <?= h($companyLabel) ?></small>
        <?php if ($mode === 'as_of'): ?>
            <small class="text-muted fw-normal ms-2">As of <?= h(date('F d, Y', strtotime($asOfDate))) ?></small>
        <?php else: ?>
            <small class="text-muted fw-normal ms-2">
                Period <?= h($dateFrom) ?> to <?= h($dateTo) ?>
                — Debit/Credit = movements in this range only
                <?php if ($showOpenClose): ?> · Opening/Closing shown<?php endif; ?>
            </small>
        <?php endif; ?>
    </div>
    <div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
        <a href="?<?= h($filterQuery) ?>&export=csv" class="btn btn-outline-primary"><i class="bi bi-download"></i> CSV</a>
        <a href="?<?= h($filterQuery) ?>&export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    </div>
</div>

<div class="card card-round mb-4 no-print">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-sliders"></i> Report type</label>
                <select name="mode" class="form-select" id="tbMode">
                    <option value="as_of" <?= $mode === 'as_of' ? 'selected' : '' ?>>As of date (closing balances)</option>
                    <option value="range" <?= $mode === 'range' ? 'selected' : '' ?>>Date range (period activity)</option>
                </select>
            </div>
            <div class="col-md-3 tb-asof-only" style="<?= $mode === 'range' ? 'display:none' : '' ?>">
                <label class="form-label"><i class="bi bi-calendar"></i> As Of Date</label>
                <input type="date" name="as_of_date" class="form-control" value="<?= h($asOfDate) ?>">
            </div>
            <div class="col-md-2 tb-range-only" style="<?= $mode === 'as_of' ? 'display:none' : '' ?>">
                <label class="form-label"><i class="bi bi-calendar"></i> From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2 tb-range-only" style="<?= $mode === 'as_of' ? 'display:none' : '' ?>">
                <label class="form-label"><i class="bi bi-calendar"></i> To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel-fill"></i> Generate
                </button>
            </div>
            <div class="col-12">
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <div class="tb-range-only" style="<?= $mode === 'as_of' ? 'display:none' : '' ?>">
                        <span class="text-muted small me-1">Quick:</span>
                        <a class="btn btn-sm btn-outline-secondary" href="?<?= h($baseQuery(['preset' => 'last_7', 'mode' => 'range', 'show_zero' => '', 'show_open_close' => $showOpenClose ? '1' : ''])) ?>">Last 7 days</a>
                        <a class="btn btn-sm btn-outline-secondary" href="?<?= h($baseQuery(['preset' => 'this_week', 'mode' => 'range', 'show_zero' => '', 'show_open_close' => $showOpenClose ? '1' : ''])) ?>">This week</a>
                        <a class="btn btn-sm btn-outline-secondary" href="?<?= h($baseQuery(['preset' => 'this_month', 'mode' => 'range', 'show_zero' => '', 'show_open_close' => $showOpenClose ? '1' : ''])) ?>">This month</a>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="show_zero" id="showZero" value="1" <?= $showZeroBalances ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showZero">
                            <?= $mode === 'range' ? 'Show accounts with no period activity' : 'Show Zero Balances' ?>
                        </label>
                    </div>
                    <div class="form-check mb-0 tb-range-only" style="<?= $mode === 'as_of' ? 'display:none' : '' ?>">
                        <input class="form-check-input" type="checkbox" name="show_open_close" id="showOpenClose" value="1" <?= $showOpenClose ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showOpenClose">Show opening &amp; closing (Zoho-style)</label>
                    </div>
                </div>
                <?php if ($mode === 'range'): ?>
                <p class="small text-muted mb-0 mt-2">
                    For one week: use <em>Last 7 days</em> or set From/To. Period Debit/Credit are only that range’s journal movements.
                    Closing matches classic “As of To” — enable Opening &amp; Closing if needed.
                </p>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-header bg-light">
        <h5 class="mb-0">
            <i class="bi bi-file-text"></i> Trial Balance
            <?php if ($mode === 'as_of'): ?>
                <small class="text-muted">As of <?= h(date('F d, Y', strtotime($asOfDate))) ?></small>
            <?php else: ?>
                <small class="text-muted">Period activity <?= h($dateFrom) ?> to <?= h($dateTo) ?></small>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="trialBalanceTable">
                <thead class="table-light">
                    <tr>
                        <th style="width: 10%"><i class="bi bi-hash"></i> Account Code</th>
                        <th><i class="bi bi-tag"></i> Account Name</th>
                        <th style="width: 12%"><i class="bi bi-tag"></i> Type</th>
                        <?php if ($mode === 'range' && $showOpenClose): ?>
                            <th class="text-end">Opening</th>
                        <?php endif; ?>
                        <th class="text-end"><i class="bi bi-arrow-down-left"></i> <?= $mode === 'range' ? 'Period Debit' : 'Debit' ?> (AED)</th>
                        <th class="text-end"><i class="bi bi-arrow-up-right"></i> <?= $mode === 'range' ? 'Period Credit' : 'Credit' ?> (AED)</th>
                        <?php if ($mode === 'range' && $showOpenClose): ?>
                            <th class="text-end">Closing Debit</th>
                            <th class="text-end">Closing Credit</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trialBalance)): ?>
                        <tr>
                            <td colspan="<?= (int)$colCount ?>" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                <?= $mode === 'range' ? 'No journal activity in this date range.' : 'No accounts found' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php
                        $currentType = '';
                        foreach ($trialBalance as $row):
                            if ($currentType !== $row['account_type']):
                                $currentType = $row['account_type'];
                        ?>
                            <tr class="table-secondary">
                                <td colspan="<?= (int)$colCount ?>" class="fw-bold">
                                    <i class="bi bi-folder"></i> <?= h($currentType) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                            <tr>
                                <td>
                                    <a href="general_ledger.php?account_id=<?= (int)$row['id'] ?>&date_from=<?= h($glDateFrom) ?>&date_to=<?= h($glDateTo) ?>">
                                        <code><?= h($row['account_code']) ?></code>
                                    </a>
                                </td>
                                <td>
                                    <a href="general_ledger.php?account_id=<?= (int)$row['id'] ?>&date_from=<?= h($glDateFrom) ?>&date_to=<?= h($glDateTo) ?>">
                                        <?= h($row['account_name']) ?>
                                    </a>
                                </td>
                                <td><span class="badge bg-light text-dark"><?= h($row['account_type']) ?></span></td>
                                <?php if ($mode === 'range' && $showOpenClose): ?>
                                    <td class="text-end">
                                        <?= abs($row['opening']) >= 0.01 ? '<strong>' . number_format($row['opening'], 2) . '</strong>' : '<span class="text-muted">-</span>' ?>
                                    </td>
                                <?php endif; ?>
                                <td class="text-end">
                                    <?= $row['debit'] > 0 ? '<strong>' . number_format($row['debit'], 2) . '</strong>' : '<span class="text-muted">-</span>' ?>
                                </td>
                                <td class="text-end">
                                    <?= $row['credit'] > 0 ? '<strong>' . number_format($row['credit'], 2) . '</strong>' : '<span class="text-muted">-</span>' ?>
                                </td>
                                <?php if ($mode === 'range' && $showOpenClose): ?>
                                    <td class="text-end">
                                        <?= $row['closing_debit'] > 0 ? '<strong>' . number_format($row['closing_debit'], 2) . '</strong>' : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td class="text-end">
                                        <?= $row['closing_credit'] > 0 ? '<strong>' . number_format($row['closing_credit'], 2) . '</strong>' : '<span class="text-muted">-</span>' ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-dark fw-bold">
                            <td colspan="3" class="text-end">TOTAL:</td>
                            <?php if ($mode === 'range' && $showOpenClose): ?>
                                <td class="text-end"><?= number_format($totalOpening, 2) ?></td>
                            <?php endif; ?>
                            <td class="text-end"><?= number_format($totalDebit, 2) ?></td>
                            <td class="text-end"><?= number_format($totalCredit, 2) ?></td>
                            <?php if ($mode === 'range' && $showOpenClose): ?>
                                <td class="text-end"><?= number_format($totalClosingDebit, 2) ?></td>
                                <td class="text-end"><?= number_format($totalClosingCredit, 2) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php if (abs($totalDebit - $totalCredit) > 0.01): ?>
                            <tr class="table-danger">
                                <td colspan="<?= (int)$colCount - 2 ?>" class="text-end"><strong>DIFFERENCE (Out of Balance):</strong></td>
                                <td colspan="2" class="text-end">
                                    <strong><?= number_format(abs($totalDebit - $totalCredit), 2) ?> AED</strong>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
  var mode = document.getElementById('tbMode');
  if (!mode) return;
  function sync() {
    var range = mode.value === 'range';
    document.querySelectorAll('.tb-range-only').forEach(function (el) { el.style.display = range ? '' : 'none'; });
    document.querySelectorAll('.tb-asof-only').forEach(function (el) { el.style.display = range ? 'none' : ''; });
  }
  mode.addEventListener('change', sync);
})();
</script>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
