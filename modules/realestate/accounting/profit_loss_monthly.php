<?php
/**
 * Real Estate Accounting - Profit & Loss, month by month
 * One column per calendar month, read from the general ledger.
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

$validMonth = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}$/', $v);
$monthFrom = $validMonth($_GET['month_from'] ?? null) ? $_GET['month_from'] : date('Y-01');
$monthTo = $validMonth($_GET['month_to'] ?? null) ? $_GET['month_to'] : date('Y-m');
if ($monthTo < $monthFrom) {
    [$monthFrom, $monthTo] = [$monthTo, $monthFrom];
}
// At most 24 columns.
$months = [];
$cursor = new DateTimeImmutable($monthFrom . '-01');
$end = new DateTimeImmutable($monthTo . '-01');
while ($cursor <= $end && count($months) < 24) {
    $months[] = $cursor->format('Y-m');
    $cursor = $cursor->modify('+1 month');
}
$monthTo = end($months);
$dateFrom = $monthFrom . '-01';
$dateTo = (new DateTimeImmutable($monthTo . '-01'))->format('Y-m-t');

$stmt = $conn->prepare("
    SELECT coa.account_type, coa.account_code, coa.account_name,
           DATE_FORMAT(gl.entry_date, '%Y-%m') AS ym,
           SUM(gl.credit_amount - gl.debit_amount) AS net_credit
    FROM re_general_ledger gl
    JOIN re_chart_of_accounts coa ON coa.id = gl.account_id AND coa.company_id = gl.company_id
    WHERE gl.company_id = ? AND gl.entry_date BETWEEN ? AND ?
      AND coa.account_type IN ('Income', 'Expense')
    GROUP BY coa.account_type, coa.account_code, coa.account_name, ym
    ORDER BY coa.account_code
");
$stmt->execute([$currentCompanyId, $dateFrom, $dateTo]);

// sections: Income shown as credit - debit, Expense as debit - credit
$sections = ['Income' => [], 'Expense' => []];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $type = $r['account_type'];
    $key = $r['account_code'];
    $sections[$type][$key] ??= ['code' => $r['account_code'], 'name' => $r['account_name'], 'months' => []];
    $amount = (float)$r['net_credit'];
    $sections[$type][$key]['months'][$r['ym']] = $type === 'Income' ? $amount : -$amount;
}
foreach ($sections as $type => $rows) {
    $sections[$type] = array_filter($rows, static fn($row) => abs(array_sum($row['months'])) > 0.004 || count(array_filter($row['months'], static fn($v) => abs($v) > 0.004)));
}
$totals = [];
foreach (['Income', 'Expense'] as $type) {
    foreach ($months as $m) {
        $totals[$type][$m] = 0.0;
        foreach ($sections[$type] as $row) {
            $totals[$type][$m] += $row['months'][$m] ?? 0;
        }
    }
}
$net = [];
foreach ($months as $m) {
    $net[$m] = $totals['Income'][$m] - $totals['Expense'][$m];
}
$monthLabel = static fn($m) => date('M Y', strtotime($m . '-01'));

if (!empty($_GET['export'])) {
    require_once __DIR__ . '/export_excel_helper.php';
    $cols = ['section' => 'Section', 'code' => 'Code', 'name' => 'Account'];
    foreach ($months as $m) {
        $cols[$m] = $monthLabel($m);
    }
    $cols['total'] = 'Total';
    $out = [];
    foreach (['Income', 'Expense'] as $type) {
        foreach ($sections[$type] as $row) {
            $line = ['section' => $type, 'code' => $row['code'], 'name' => $row['name']];
            foreach ($months as $m) {
                $line[$m] = round($row['months'][$m] ?? 0, 2);
            }
            $line['total'] = round(array_sum(array_intersect_key($row['months'], array_flip($months))), 2);
            $out[] = $line;
        }
        $line = ['section' => $type, 'code' => '', 'name' => 'Total ' . $type];
        foreach ($months as $m) {
            $line[$m] = round($totals[$type][$m], 2);
        }
        $line['total'] = round(array_sum($totals[$type]), 2);
        $out[] = $line;
    }
    $line = ['section' => '', 'code' => '', 'name' => 'Net Profit'];
    foreach ($months as $m) {
        $line[$m] = round($net[$m], 2);
    }
    $line['total'] = round(array_sum($net), 2);
    $out[] = $line;
    $filename = 'profit_loss_monthly_' . $monthFrom . '_' . $monthTo;
    if ($_GET['export'] === 'excel') {
        accounting_export_excel_or_csv($out, $cols, $filename, 'Profit & Loss by Month');
    } else {
        accounting_export_csv($out, $cols, $filename);
    }
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$fmt = static fn($v) => abs($v) < 0.005 ? '-' : number_format($v, 2);
$query = 'month_from=' . urlencode($monthFrom) . '&month_to=' . urlencode($monthTo);

$pageTitle = 'Profit & Loss by Month';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div class="page-header-label">
        <i class="bi bi-calendar3"></i> Profit &amp; Loss by Month
    </div>
    <div>
        <a href="profit_loss.php" class="btn btn-outline-secondary"><i class="bi bi-graph-up"></i> Period view</a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= h($query) ?>&export=csv" class="btn btn-outline-primary"><i class="bi bi-download"></i> CSV</a>
        <a href="?<?= h($query) ?>&export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    </div>
</div>

<div class="card card-round mb-4 no-print">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">From month</label>
                <input type="month" name="month_from" class="form-control" value="<?= h($monthFrom) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">To month</label>
                <input type="month" name="month_to" class="form-control" value="<?= h($monthTo) ?>" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Show</button>
            </div>
            <div class="col-md-4 text-muted small">
                Rent is booked by calendar day: a lease starting on the 23rd gives that month only its own days. Up to 24 months.
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="white-space: nowrap;">
                <thead class="table-light">
                    <tr>
                        <th>Account</th>
                        <?php foreach ($months as $m): ?><th class="text-end"><?= h($monthLabel($m)) ?></th><?php endforeach; ?>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (['Income' => 'Income', 'Expense' => 'Expenses'] as $type => $label): ?>
                    <tr class="table-secondary"><td colspan="<?= count($months) + 2 ?>" class="fw-bold"><?= h($label) ?></td></tr>
                    <?php if (!$sections[$type]): ?>
                        <tr><td colspan="<?= count($months) + 2 ?>" class="text-muted ps-4">Nothing recorded</td></tr>
                    <?php endif; ?>
                    <?php foreach ($sections[$type] as $row): ?>
                        <tr>
                            <td class="ps-4"><?= h($row['code'] . ' - ' . $row['name']) ?></td>
                            <?php foreach ($months as $m): ?><td class="text-end"><?= $fmt($row['months'][$m] ?? 0) ?></td><?php endforeach; ?>
                            <td class="text-end fw-semibold"><?= $fmt(array_sum(array_intersect_key($row['months'], array_flip($months)))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="fw-bold">
                        <td class="text-end">Total <?= h($label) ?></td>
                        <?php foreach ($months as $m): ?><td class="text-end"><?= $fmt($totals[$type][$m]) ?></td><?php endforeach; ?>
                        <td class="text-end"><?= $fmt(array_sum($totals[$type])) ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr class="table-primary fw-bold">
                        <td class="text-end">Net Profit</td>
                        <?php foreach ($months as $m): ?>
                            <td class="text-end text-<?= $net[$m] >= 0 ? 'success' : 'danger' ?>"><?= $fmt($net[$m]) ?></td>
                        <?php endforeach; ?>
                        <td class="text-end text-<?= array_sum($net) >= 0 ? 'success' : 'danger' ?>"><?= $fmt(array_sum($net)) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
