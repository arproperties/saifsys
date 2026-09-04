<?php
/**
 * ONE-TIME repair tool — Fines / Other Deductions ONLY.
 *
 * Links historical payroll_items.other_applied into:
 *   - hr_deduction_settlements
 *   - employee_deductions.remaining_balance
 *
 * Does NOT touch Cash Advances / Loans (adv_applied / hr_loan_settlements).
 *
 * After a successful run on live, delete this file.
 *
 * Access: Owner / Admin only (web). CLI also supported for local ops.
 *
 * Web:  /hr/tools/sync_deduction_payroll_settlements.php
 * CLI:  php hr/tools/sync_deduction_payroll_settlements.php
 *       php hr/tools/sync_deduction_payroll_settlements.php --employee=140
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../includes/hr_loans.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_role(['Owner', 'Admin'], $conn);
}

/**
 * @return array{pending_lines:int,pending_amount:float,schema_ok:bool,lines:list<array>}
 */
function hr_deduction_sync_preview(PDO $conn, ?int $employeeId = null): array
{
    $out = ['pending_lines' => 0, 'pending_amount' => 0.0, 'schema_ok' => false, 'lines' => []];

    $hasRemaining = false;
    try {
        $chk = $conn->query("SHOW COLUMNS FROM employee_deductions LIKE 'remaining_balance'");
        $hasRemaining = (bool)($chk && $chk->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $hasRemaining = false;
    }
    if (!$hasRemaining || !hr_deduction_settlements_table_ready($conn)) {
        return $out;
    }
    $out['schema_ok'] = true;
    $out['lines'] = hr_deduction_sync_unlinked_lines($conn, $employeeId);
    $out['pending_lines'] = count($out['lines']);
    $sum = 0.0;
    foreach ($out['lines'] as $line) {
        $sum += (float)$line['other_applied'];
    }
    $out['pending_amount'] = round($sum, 2);
    return $out;
}

$employeeId = null;
$result = null;
$error = '';
$ran = false;

if ($isCli) {
    global $argv;
    foreach ($argv ?? [] as $arg) {
        if (preg_match('/^--employee=(\d+)$/', (string)$arg, $m)) {
            $employeeId = (int)$m[1];
        }
    }
    $preview = hr_deduction_sync_preview($conn, $employeeId);
    if (!$preview['schema_ok']) {
        fwrite(STDERR, "Schema not ready (employee_deductions.remaining_balance / hr_deduction_settlements).\n");
        exit(1);
    }
    echo "Fines/Deductions payroll sync (NOT loans).\n";
    echo 'Pending unlinked payroll lines: ' . $preview['pending_lines']
        . ' (AED ' . number_format($preview['pending_amount'], 2) . ")\n";
    foreach ($preview['lines'] as $line) {
        echo sprintf(
            "  - emp #%d %s (%s) run #%d other_applied=AED %s open_remaining=AED %s | %s\n",
            (int)$line['employee_id'],
            $line['employee_name'],
            $line['employee_code'],
            (int)$line['payroll_run_id'],
            number_format((float)$line['other_applied'], 2),
            number_format((float)$line['open_remaining'], 2),
            $line['reason']
        );
    }
    try {
        $result = hr_deduction_sync_payroll_settlements($conn, $employeeId);
        $ran = true;
        echo sprintf(
            "Done: items=%d settlements=%d amount=AED %s skipped=%d\n",
            (int)$result['items'],
            (int)$result['settlements'],
            number_format((float)$result['amount'], 2),
            (int)$result['skipped']
        );
        foreach ($result['skipped_details'] ?? [] as $skip) {
            echo sprintf(
                "  SKIP emp #%d %s (%s) run #%d AED %s — %s\n",
                (int)$skip['employee_id'],
                $skip['employee_name'] ?? '',
                $skip['employee_code'] ?? '',
                (int)$skip['payroll_run_id'],
                number_format((float)$skip['other_applied'], 2),
                $skip['reason'] ?? ''
            );
        }
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $employeeId = isset($_POST['employee_id']) && (int)$_POST['employee_id'] > 0
        ? (int)$_POST['employee_id']
        : null;
    try {
        $result = hr_deduction_sync_payroll_settlements($conn, $employeeId);
        $ran = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$preview = hr_deduction_sync_preview($conn, null);
$pageTitle = 'One-time: Sync Deduction Payroll Recoveries';
require_once __DIR__ . '/../includes/hr_layout_header.php';
?>

<div class="container-fluid py-3" style="max-width:1100px">
  <div class="alert alert-warning">
    <strong>One-time repair tool — Fines / Other Deductions only.</strong>
    <div class="mt-1 small">
      This updates <code>employee_deductions.remaining_balance</code> and
      <code>hr_deduction_settlements</code> from historical
      <code>payroll_items.other_applied</code>.
    </div>
    <div class="mt-2 small fw-semibold text-danger">
      Does NOT touch Cash Advances / Loans (<code>adv_applied</code> / <code>hr_loan_settlements</code>).
    </div>
    <div class="mt-2 small">
      Safe to re-run (idempotent). After a successful live run, delete this file:
      <code>hr/tools/sync_deduction_payroll_settlements.php</code>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($ran && is_array($result)): ?>
    <div class="alert alert-success">
      <strong>Sync finished<?= $employeeId ? ' for employee #' . (int)$employeeId : ' for all employees' ?>.</strong>
      <ul class="mb-0 mt-2">
        <li>Payroll lines processed: <?= (int)$result['items'] ?></li>
        <li>Settlement rows created: <?= (int)$result['settlements'] ?></li>
        <li>Amount applied to remaining balances: AED <?= number_format((float)$result['amount'], 2) ?></li>
        <li>Skipped / unmatched: <?= (int)$result['skipped'] ?></li>
      </ul>
    </div>
    <?php if (!empty($result['skipped_details'])): ?>
      <div class="alert alert-danger">
        <strong>Skipped lines (could not fully link)</strong>
        <div class="table-responsive mt-2">
          <table class="table table-sm table-bordered bg-white mb-0">
            <thead class="table-light">
              <tr>
                <th>Employee</th>
                <th>Payroll run</th>
                <th>Period</th>
                <th class="text-end">other_applied</th>
                <th>Why skipped</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($result['skipped_details'] as $skip): ?>
              <tr>
                <td>
                  <a href="../employee_view.php?id=<?= (int)$skip['employee_id'] ?>#tab-deduct" target="_blank">
                    <?= htmlspecialchars(($skip['employee_name'] ?? '') . ' (' . ($skip['employee_code'] ?? '') . ')') ?>
                  </a>
                  <div class="small text-muted">ID #<?= (int)$skip['employee_id'] ?></div>
                </td>
                <td>#<?= (int)$skip['payroll_run_id'] ?></td>
                <td>
                  <?= htmlspecialchars((string)($skip['period_from'] ?? '')) ?>
                  →
                  <?= htmlspecialchars((string)($skip['period_to'] ?? '')) ?>
                </td>
                <td class="text-end">AED <?= number_format((float)$skip['other_applied'], 2) ?></td>
                <td><?= htmlspecialchars((string)($skip['reason'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
      <h5 class="mb-3">Current pending (all employees)</h5>
      <?php if (!$preview['schema_ok']): ?>
        <div class="alert alert-danger mb-0">
          Required schema is missing. Run the HR loans/deductions migration first
          (<code>remaining_balance</code> on <code>employee_deductions</code> and
          <code>hr_deduction_settlements</code>).
        </div>
      <?php else: ?>
        <p class="mb-1">
          Unlinked payroll deduction lines:
          <strong><?= (int)$preview['pending_lines'] ?></strong>
        </p>
        <p class="mb-3 text-muted">
          Total other_applied not yet linked:
          <strong>AED <?= number_format((float)$preview['pending_amount'], 2) ?></strong>
        </p>

        <?php if (!empty($preview['lines'])): ?>
          <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Employee</th>
                  <th>Company</th>
                  <th>Payroll run</th>
                  <th>Period</th>
                  <th class="text-end">Payroll applied</th>
                  <th class="text-end">Open fine remaining</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($preview['lines'] as $line): ?>
                <tr class="<?= (float)$line['open_remaining'] <= 0.005 ? 'table-warning' : '' ?>">
                  <td>
                    <a href="../employee_view.php?id=<?= (int)$line['employee_id'] ?>#tab-deduct" target="_blank">
                      <?= htmlspecialchars($line['employee_name'] . ' (' . $line['employee_code'] . ')') ?>
                    </a>
                    <div class="small text-muted">ID #<?= (int)$line['employee_id'] ?></div>
                  </td>
                  <td><?= htmlspecialchars($line['company_name'] ?: '—') ?></td>
                  <td>
                    <a href="../payroll_run_view.php?id=<?= (int)$line['payroll_run_id'] ?>" target="_blank">
                      #<?= (int)$line['payroll_run_id'] ?>
                    </a>
                  </td>
                  <td>
                    <?= htmlspecialchars((string)($line['period_from'] ?? '')) ?>
                    →
                    <?= htmlspecialchars((string)($line['period_to'] ?? '')) ?>
                  </td>
                  <td class="text-end">AED <?= number_format((float)$line['other_applied'], 2) ?></td>
                  <td class="text-end">AED <?= number_format((float)$line['open_remaining'], 2) ?></td>
                  <td class="small"><?= htmlspecialchars($line['reason']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mb-3">
            Yellow rows usually mean payroll took a fine amount, but there is no open fine balance left to attach it to
            (fine never recorded, already marked settled, or deleted).
          </div>
        <?php endif; ?>

        <form method="post" onsubmit="return confirm('Run fines/deductions payroll sync for ALL employees? This does not touch loans/cash advances.');">
          <?php csrf_field(); ?>
          <input type="hidden" name="employee_id" value="0">
          <button type="submit" class="btn btn-danger" <?= (int)$preview['pending_lines'] === 0 ? 'disabled' : '' ?>>
            Run one-time sync (all employees)
          </button>
          <a class="btn btn-outline-secondary" href="../employees.php">Back to employees</a>
        </form>

        <?php if ((int)$preview['pending_lines'] === 0): ?>
          <div class="small text-success mt-3">Nothing pending — safe to delete this tool file.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm border-0">
    <div class="card-body">
      <h6 class="mb-2">Optional: single employee</h6>
      <form method="post" class="row g-2 align-items-end"
            onsubmit="return confirm('Run fines/deductions payroll sync for this employee only?');">
        <?php csrf_field(); ?>
        <div class="col-auto">
          <label class="form-label small mb-0">Employee ID</label>
          <input type="number" name="employee_id" class="form-control form-control-sm" min="1" required placeholder="e.g. 140">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-outline-primary btn-sm" <?= empty($preview['schema_ok']) ? 'disabled' : '' ?>>
            Sync one employee
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/hr_layout_footer.php'; ?>
