<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_payroll_accounting.php';
require_once __DIR__ . '/includes/hr_wps_guards.php';
require_role(['Owner','Admin','HR'], $conn);
$overrideSchemaReady = hr_payroll_validation_override_schema_ready($conn);

$canPickCompany = function_exists('has_role') && (has_role('Owner', $conn) || has_role('Admin', $conn));
$currentCompanyId = (int)(current_company_id($conn) ?: 1);

$companies = $conn->query("
    SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$msg = $_GET['msg'] ?? '';
$err = '';

// Delete a non-finalized run
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_run'])) {
    csrf_verify();
    $deleteRunId = (int)($_POST['delete_run'] ?? 0);

    if ($deleteRunId <= 0) {
        $err = 'Invalid payroll run selected.';
    } else {
        try {
            $st = $conn->prepare("SELECT id, company_id, status, accounting_journal_id FROM payroll_runs WHERE id = ? LIMIT 1");
            $st->execute([$deleteRunId]);
            $runToDelete = $st->fetch(PDO::FETCH_ASSOC);

            if (!$runToDelete) {
                $err = 'Payroll run not found.';
            } elseif (!$canPickCompany && (int)$runToDelete['company_id'] !== $currentCompanyId) {
                $err = 'You can only delete payroll runs for your current company.';
            } elseif (in_array((string)$runToDelete['status'], ['finalized', 'paid', 'posted'], true) || !empty($runToDelete['accounting_journal_id'])) {
                $err = 'Finalized or accounting-posted payroll runs cannot be deleted.';
            } else {
                $conn->prepare("DELETE FROM payroll_runs WHERE id = ?")->execute([$deleteRunId]);
                $uid = $_SESSION['user']['id'] ?? null;
                audit_bridge_hr_ops(
                    'payroll_deleted',
                    'payroll_runs',
                    $deleteRunId,
                    'Deleted open payroll run #' . $deleteRunId,
                    isset($runToDelete['company_id']) ? (int)$runToDelete['company_id'] : null,
                    [
                        'status' => $runToDelete['status'] ?? null,
                        'company_id' => isset($runToDelete['company_id']) ? (int)$runToDelete['company_id'] : null,
                    ],
                    'Payroll #' . $deleteRunId,
                    $uid ? (int)$uid : null
                );
                header('Location: payroll_runs.php?msg=' . urlencode("Payroll run #{$deleteRunId} deleted."));
                exit;
            }
        } catch (Throwable $ex) {
            $err = 'Delete failed: ' . $ex->getMessage();
        }
    }
}

// Create a new run
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    csrf_verify();
    $from = $_POST['period_from'] ?? '';
    $to   = $_POST['period_to'] ?? '';
    $companyId = (int)($_POST['company_id'] ?? 0);

    if (!$from || !$to) {
        $err = 'Please select a date range.';
    } elseif ($companyId <= 0) {
        $err = 'Please select a company.';
    } else {
        $chk = $conn->prepare("SELECT 1 FROM companies WHERE id = ? AND is_active = 1");
        $chk->execute([$companyId]);
        if (!$chk->fetchColumn()) {
            $err = 'Invalid company selected.';
        } elseif (!$canPickCompany && $companyId !== $currentCompanyId) {
            $err = 'You can only create payroll for your current company.';
        }
    }

    if (!$err) {
        $conn->beginTransaction();
        try {
            $ins = $conn->prepare("
                INSERT INTO payroll_runs (company_id, payroll_type, period_from, period_to, status, created_at)
                VALUES (?, ?, ?, ?, 'open', NOW())
            ");
            $created = [];
            foreach (array_keys(hr_payroll_payment_types()) as $payrollType) {
                $ins->execute([$companyId, $payrollType, $from, $to]);
                $created[$payrollType] = (int)$conn->lastInsertId();
            }
            $conn->commit();
            $msgText = 'Created WPS payroll #' . $created['wps'] . ' and Cash payroll #' . $created['cash'] . '.';

            $uid = $_SESSION['user']['id'] ?? null;
            $companyName = '';
            try {
                $cn = $conn->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
                $cn->execute([$companyId]);
                $companyName = (string)($cn->fetchColumn() ?: '');
            } catch (Throwable $e) {
                $companyName = '';
            }
            foreach ($created as $payrollType => $runId) {
                if ($runId <= 0) {
                    continue;
                }
                audit_bridge_hr_ops(
                    'payroll_created',
                    'payroll_runs',
                    $runId,
                    'Created ' . strtoupper((string)$payrollType) . ' payroll run #' . $runId
                        . ($companyName !== '' ? (' for ' . $companyName) : '')
                        . ' (' . $from . ' → ' . $to . ')',
                    $companyId,
                    [
                        'payroll_type' => $payrollType,
                        'period_from' => $from,
                        'period_to' => $to,
                        'status' => 'open',
                        'paired_run_ids' => $created,
                    ],
                    strtoupper((string)$payrollType) . ' Payroll #' . $runId,
                    $uid ? (int)$uid : null
                );
            }

            header('Location: payroll_runs.php?company_id=' . $companyId . '&msg=' . urlencode($msgText));
            exit;
        } catch (Throwable $ex) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $err = 'Create failed: ' . $ex->getMessage();
        }
    }
}

// List runs (optional filter by company)
$filterCompany = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;

$sql = "
    SELECT pr.*, c.name AS company_name
    FROM payroll_runs pr
    LEFT JOIN companies c ON c.id = pr.company_id
    WHERE 1=1
";
$args = [];
if (!$canPickCompany) {
    $sql .= " AND pr.company_id = ?";
    $args[] = $currentCompanyId;
} elseif ($filterCompany > 0) {
    $sql .= " AND pr.company_id = ?";
    $args[] = $filterCompany;
}
$sql .= " ORDER BY pr.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($args);
$runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$defaultCreateCompany = ($canPickCompany && $filterCompany > 0) ? $filterCompany : $currentCompanyId;

// Page settings for shared layout
$pageTitle = 'Payroll';
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
echo hr_ui_page_header(
    'Payroll Runs',
    'Create and manage WPS and cash payroll runs by company.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Payroll'],
    ],
    '<a class="btn btn-outline-secondary" href="../operation.php">Back</a>'
);
?>

  <?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="hr-settings-card mb-4">
    <div class="settings-header">New Run</div>
    <div class="card-body">
      <form method="post" class="row g-3 align-items-end">
        <?php csrf_field(); ?>
        <input type="hidden" name="create" value="1">
        <div class="col-md-3">
          <label class="form-label">Company *</label>
          <select name="company_id" class="form-select" required <?= !$canPickCompany ? 'disabled' : '' ?>>
            <?php foreach ($companies as $c): ?>
              <?php
                $cid = (int)$c['id'];
                if (!$canPickCompany && $cid !== $currentCompanyId) {
                    continue;
                }
                $sel = ($cid === $defaultCreateCompany);
              ?>
              <option value="<?= $cid ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$canPickCompany): ?>
            <input type="hidden" name="company_id" value="<?= (int)$currentCompanyId ?>">
            <div class="form-text">Payroll is created for your current company. Switch company in the header if needed.</div>
          <?php endif; ?>
        </div>
        <div class="col-md-2">
          <label class="form-label">From</label>
          <input type="date" name="period_from" class="form-control" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">To</label>
          <input type="date" name="period_to" class="form-control" required>
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary w-100">Create WPS + Cash</button>
        </div>
        <div class="col-12">
          <div class="form-text">The system creates two company-wise runs for the same period: WPS payroll and Cash payroll.</div>
        </div>
      </form>
    </div>
  </div>

  <?php if ($canPickCompany && count($companies) > 1): ?>
  <div class="hr-filter-bar mb-3">
      <form method="get" class="row g-2 align-items-center">
        <div class="col-auto">
          <label class="form-label mb-0 small text-muted">Filter runs by company</label>
        </div>
        <div class="col-md-4">
          <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="0">All companies</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $filterCompany === (int)$c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
  </div>
  <?php endif; ?>

  <div class="hr-settings-card">
    <div class="settings-header">Existing Runs</div>
    <div class="card-body p-0">
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Company</th>
            <th>Payroll Type</th>
            <th>Period</th>
            <th>Status</th>
            <th>Created</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$runs): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-4">No runs yet.</td>
          </tr>
        <?php else: foreach ($runs as $r): ?>
          <?php
            $badge = [
              'open'   => 'primary',
              'draft'  => 'warning',
              'posted' => 'success',
              'finalized' => 'success',
              'paid' => 'success',
              'closed' => 'secondary',
              'cancelled' => 'danger',
            ][$r['status']] ?? 'secondary';
          ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['company_name'] ?? ('#' . (int)($r['company_id'] ?? 0))) ?></td>
            <td><?= htmlspecialchars(hr_payroll_run_type_label($r['payroll_type'] ?? 'wps')) ?></td>
            <td><?= htmlspecialchars($r['period_from']) ?> → <?= htmlspecialchars($r['period_to']) ?></td>
            <td>
              <span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span>
              <?php if ($overrideSchemaReady && !empty($r['validation_override'])): ?>
                <span class="badge text-bg-danger ms-1">Validation Override</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['created_at']) ?></td>
            <td class="text-end">
              <?php if (in_array((string)$r['status'], ['open', 'draft'], true)): ?>
                <a class="btn btn-sm btn-primary" href="payroll_run_build.php?id=<?= (int)$r['id'] ?>">Build / Post</a>
              <?php else: ?>
                <a class="btn btn-sm btn-outline-secondary" href="payroll_run_view.php?id=<?= (int)$r['id'] ?>">View</a>
              <?php endif; ?>
              <?php if (!in_array((string)$r['status'], ['finalized', 'paid', 'posted'], true) && empty($r['accounting_journal_id'])): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete payroll run #<?= (int)$r['id'] ?>? This cannot be undone.')">
                  <?php csrf_field(); ?>
                  <button class="btn btn-sm btn-outline-danger" name="delete_run" value="<?= (int)$r['id'] ?>">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
