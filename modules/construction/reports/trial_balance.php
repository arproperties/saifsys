<?php
/**
 * Construction Trial Balance — Shared RE ledger (re_*).
 *
 * Modes (aligned with Zoho Books / Xero practice):
 * - as_of: classic closing Debit/Credit as of one date
 * - range: period Debit/Credit for From–To (what you use for “one week”)
 *   Optional Opening/Closing columns (Zoho: add via customize; we use a checkbox)
 */
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$mode = ($_GET['mode'] ?? '') === 'as_of' ? 'as_of' : 'range';
$showZero = !empty($_GET['show_zero']);
$showOpenClose = !empty($_GET['show_open_close']);

// Presets for common period questions
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
        $dateFrom = $asOf; // unused for query in as_of mode
    } elseif (empty($_GET['date_to']) && !empty($_GET['as_of_date'])) {
        $dateTo = $_GET['as_of_date'];
    }
}
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$rows = [];
$totalDebit = 0.0;
$totalCredit = 0.0;
$totalOpening = 0.0;
$totalClosingDebit = 0.0;
$totalClosingCredit = 0.0;

if ($mode === 'as_of') {
    $stmt = $conn->prepare("
        SELECT coa.id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance,
               COALESCE(CASE WHEN coa.normal_balance = 'debit'
                    THEN SUM(gl.debit_amount) - SUM(gl.credit_amount)
                    ELSE SUM(gl.credit_amount) - SUM(gl.debit_amount)
               END, 0) AS balance
        FROM re_chart_of_accounts coa
        LEFT JOIN re_general_ledger gl
               ON gl.account_id = coa.id
              AND gl.company_id = coa.company_id
              AND gl.entry_date <= ?
        WHERE coa.company_id = ?
          AND coa.is_active = 1
          AND coa.is_header = 0
        GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance
        ORDER BY FIELD(coa.account_type, 'Asset', 'Liability', 'Equity', 'Income', 'Expense'), coa.account_code
    ");
    $stmt->execute([$dateTo, $cid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $balance = (float)$account['balance'];
        if (!$showZero && abs($balance) < 0.005) {
            continue;
        }
        $debit = 0.0;
        $credit = 0.0;
        if ($account['normal_balance'] === 'debit') {
            $debit = $balance >= 0 ? $balance : 0;
            $credit = $balance < 0 ? abs($balance) : 0;
        } else {
            $credit = $balance >= 0 ? $balance : 0;
            $debit = $balance < 0 ? abs($balance) : 0;
        }
        $rows[] = [
            'account_code' => $account['account_code'],
            'account_name' => $account['account_name'],
            'account_type' => $account['account_type'],
            'debit' => $debit,
            'credit' => $credit,
        ];
        $totalDebit += $debit;
        $totalCredit += $credit;
    }
    co_report_export($rows, [
        'account_code' => 'Code',
        'account_name' => 'Account',
        'account_type' => 'Type',
        'debit' => 'Debit',
        'credit' => 'Credit',
    ], 'construction_trial_balance_' . $dateTo, 'Construction Trial Balance');
} else {
    $stmt = $conn->prepare("
        SELECT coa.id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance,
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
          AND coa.is_header = 0
        GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type, coa.normal_balance
        ORDER BY FIELD(coa.account_type, 'Asset', 'Liability', 'Equity', 'Income', 'Expense'), coa.account_code
    ");
    $stmt->execute([
        $dateFrom, $dateFrom,
        $dateFrom, $dateTo,
        $dateFrom, $dateTo,
        $dateTo, $dateTo,
        $dateTo,
        $cid,
    ]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $opening = (float)$account['opening'];
        $periodDebit = (float)$account['period_debit'];
        $periodCredit = (float)$account['period_credit'];
        $closing = (float)$account['closing'];

        // Date-range TB: hide accounts with no movement in the period (unless show zero)
        if (!$showZero && abs($periodDebit) < 0.005 && abs($periodCredit) < 0.005) {
            continue;
        }

        $closeDebit = 0.0;
        $closeCredit = 0.0;
        if ($account['normal_balance'] === 'debit') {
            $closeDebit = $closing >= 0 ? $closing : 0;
            $closeCredit = $closing < 0 ? abs($closing) : 0;
        } else {
            $closeCredit = $closing >= 0 ? $closing : 0;
            $closeDebit = $closing < 0 ? abs($closing) : 0;
        }

        $rows[] = [
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

    $exportCols = [
        'account_code' => 'Code',
        'account_name' => 'Account',
        'account_type' => 'Type',
        'debit' => 'Period Debit',
        'credit' => 'Period Credit',
    ];
    if ($showOpenClose) {
        $exportCols = [
            'account_code' => 'Code',
            'account_name' => 'Account',
            'account_type' => 'Type',
            'opening' => 'Opening',
            'debit' => 'Period Debit',
            'credit' => 'Period Credit',
            'closing_debit' => 'Closing Debit',
            'closing_credit' => 'Closing Credit',
        ];
    }
    co_report_export($rows, $exportCols, 'construction_trial_balance_' . $dateFrom . '_' . $dateTo, 'Construction Trial Balance');
}

$baseQuery = static function (array $extra = []) use ($mode, $dateFrom, $dateTo, $showZero, $showOpenClose): string {
    return http_build_query(array_merge([
        'mode' => $mode,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'as_of_date' => $dateTo,
        'show_zero' => $showZero ? '1' : '',
        'show_open_close' => $showOpenClose ? '1' : '',
    ], $extra));
};

$pageTitle = 'Construction Trial Balance';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Trial Balance</h1>
        <?php if ($mode === 'as_of'): ?>
            <p class="text-muted mb-0">Closing balances as of <?= h($dateTo) ?></p>
        <?php else: ?>
            <p class="text-muted mb-0">
                Period activity <strong><?= h($dateFrom) ?></strong> to <strong><?= h($dateTo) ?></strong>
                — Debit/Credit = movements in this range only
                <?php if ($showOpenClose): ?> · Opening/Closing also shown<?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>

<form method="get" class="card card-round mb-4 no-print">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Report type</label>
                <select name="mode" class="form-select" id="tbMode">
                    <option value="range" <?= $mode === 'range' ? 'selected' : '' ?>>Date range (period activity)</option>
                    <option value="as_of" <?= $mode === 'as_of' ? 'selected' : '' ?>>As of date (closing balances)</option>
                </select>
            </div>
            <div class="col-md-3 tb-asof-only" style="<?= $mode === 'range' ? 'display:none' : '' ?>">
                <label class="form-label">As of Date</label>
                <input type="date" name="as_of_date" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2 tb-range-only" style="<?= $mode === 'as_of' ? 'display:none' : '' ?>">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2 tb-range-only" style="<?= $mode === 'as_of' ? 'display:none' : '' ?>">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Generate</button>
            </div>
        </div>
        <div class="row g-3 mt-1 align-items-center">
            <div class="col-auto tb-range-only" style="<?= $mode === 'as_of' ? 'display:none' : '' ?>">
                <span class="text-muted small me-2">Quick:</span>
                <a class="btn btn-sm btn-outline-secondary" href="?<?= h($baseQuery(['preset' => 'last_7', 'mode' => 'range', 'show_zero' => '', 'show_open_close' => $showOpenClose ? '1' : ''])) ?>">Last 7 days</a>
                <a class="btn btn-sm btn-outline-secondary" href="?<?= h($baseQuery(['preset' => 'this_week', 'mode' => 'range', 'show_zero' => '', 'show_open_close' => $showOpenClose ? '1' : ''])) ?>">This week</a>
                <a class="btn btn-sm btn-outline-secondary" href="?<?= h($baseQuery(['preset' => 'this_month', 'mode' => 'range', 'show_zero' => '', 'show_open_close' => $showOpenClose ? '1' : ''])) ?>">This month</a>
            </div>
            <div class="col-auto">
                <div class="form-check">
                    <input type="checkbox" name="show_zero" value="1" class="form-check-input" id="showZero" <?= $showZero ? 'checked' : '' ?>>
                    <label class="form-check-label" for="showZero"><?= $mode === 'range' ? 'Show accounts with no period activity' : 'Show zero balances' ?></label>
                </div>
            </div>
            <div class="col-auto tb-range-only" style="<?= $mode === 'as_of' ? 'display:none' : '' ?>">
                <div class="form-check">
                    <input type="checkbox" name="show_open_close" value="1" class="form-check-input" id="showOpenClose" <?= $showOpenClose ? 'checked' : '' ?>>
                    <label class="form-check-label" for="showOpenClose">Show opening &amp; closing (Zoho-style)</label>
                </div>
            </div>
        </div>
        <?php if ($mode === 'range'): ?>
        <p class="small text-muted mb-0 mt-3">
            For one week: set From/To (or use <em>Last 7 days</em>). Debit and Credit are only that week’s journal movements — not lifetime closing balances.
            Closing balances still match classic “As of To”; turn on Opening &amp; Closing if you need them.
        </p>
        <?php endif; ?>
    </div>
</form>

<div class="card card-round">
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Code</th>
                    <th>Account</th>
                    <th>Type</th>
                    <?php if ($mode === 'range' && $showOpenClose): ?>
                        <th class="text-end">Opening</th>
                    <?php endif; ?>
                    <th class="text-end"><?= $mode === 'range' ? 'Period Debit' : 'Debit' ?></th>
                    <th class="text-end"><?= $mode === 'range' ? 'Period Credit' : 'Credit' ?></th>
                    <?php if ($mode === 'range' && $showOpenClose): ?>
                        <th class="text-end">Closing Debit</th>
                        <th class="text-end">Closing Credit</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['account_code']) ?></td>
                    <td><?= h($row['account_name']) ?></td>
                    <td><?= h($row['account_type']) ?></td>
                    <?php if ($mode === 'range' && $showOpenClose): ?>
                        <td class="text-end"><?= abs($row['opening']) >= 0.005 ? co_format_money($row['opening']) : '-' ?></td>
                    <?php endif; ?>
                    <td class="text-end"><?= $row['debit'] ? co_format_money($row['debit']) : '-' ?></td>
                    <td class="text-end"><?= $row['credit'] ? co_format_money($row['credit']) : '-' ?></td>
                    <?php if ($mode === 'range' && $showOpenClose): ?>
                        <td class="text-end"><?= $row['closing_debit'] ? co_format_money($row['closing_debit']) : '-' ?></td>
                        <td class="text-end"><?= $row['closing_credit'] ? co_format_money($row['closing_credit']) : '-' ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $mode === 'range' && $showOpenClose ? 8 : 5 ?>" class="text-center text-muted py-4">
                    <?= $mode === 'range' ? 'No journal activity in this date range.' : 'No balances found.' ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <th colspan="3" class="text-end">Totals</th>
                    <?php if ($mode === 'range' && $showOpenClose): ?>
                        <th class="text-end"><?= co_format_money($totalOpening) ?></th>
                    <?php endif; ?>
                    <th class="text-end"><?= co_format_money($totalDebit) ?></th>
                    <th class="text-end"><?= co_format_money($totalCredit) ?></th>
                    <?php if ($mode === 'range' && $showOpenClose): ?>
                        <th class="text-end"><?= co_format_money($totalClosingDebit) ?></th>
                        <th class="text-end"><?= co_format_money($totalClosingCredit) ?></th>
                    <?php endif; ?>
                </tr>
            </tfoot>
        </table>
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
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
