<?php
// hr/cash_advances.php — Loans / salary advances list (issue, cash repay, void)

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/AuditService.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_loans.php';
require_role(['Owner', 'Admin', 'HR'], $conn);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function flash($k)
{
    if (!empty($_SESSION[$k])) {
        $m = $_SESSION[$k];
        unset($_SESSION[$k]);
        return $m;
    }
    return '';
}

function ca_employee_company_id(PDO $conn, int $employeeId): ?int
{
    $st = $conn->prepare('SELECT company_id FROM employees WHERE id = ?');
    $st->execute([$employeeId]);
    $cid = $st->fetchColumn();
    return $cid !== false && $cid !== null ? (int)$cid : null;
}

function ca_load_loan(PDO $conn, int $id): ?array
{
    $st = $conn->prepare("
        SELECT ca.*, e.company_id, e.full_name, e.employee_code
        FROM cash_advances ca
        JOIN employees e ON e.id = ca.employee_id
        WHERE ca.id = ?
    ");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$loansSchemaReady = hr_loans_schema_ready($conn);
$settlementsReady = hr_loan_settlements_table_ready($conn);

// --- create / void / delete / sync ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if (isset($_POST['sync_payroll_settlements'])) {
        try {
            $stats = hr_loan_sync_payroll_settlements($conn);
            $_SESSION['ok'] = sprintf(
                'Synced payroll loan recoveries: %d payroll line(s), %d settlement row(s), AED %s applied to outstanding. Skipped/unmatched: %d.',
                (int)$stats['items'],
                (int)$stats['settlements'],
                number_format((float)$stats['amount'], 2),
                (int)$stats['skipped']
            );
            try {
                require_once __DIR__ . '/../includes/audit_bridge.php';
                audit_bridge_hr_ops(
                    'loan_payroll_synced',
                    'cash_advances',
                    (int)($stats['settlements'] ?? 0),
                    'Synced payroll loan recoveries: '
                        . (int)$stats['items'] . ' payroll line(s), '
                        . (int)$stats['settlements'] . ' settlement(s), AED '
                        . number_format((float)$stats['amount'], 2)
                        . ' (skipped ' . (int)$stats['skipped'] . ')',
                    function_exists('current_company_id') ? (current_company_id($conn) ?: null) : null,
                    [
                        'items' => (int)$stats['items'],
                        'settlements' => (int)$stats['settlements'],
                        'amount' => (float)$stats['amount'],
                        'skipped' => (int)$stats['skipped'],
                    ],
                    'Loan payroll sync',
                    $uid
                );
            } catch (Throwable $ignored) {
            }
        } catch (Throwable $e) {
            $_SESSION['err'] = 'Could not sync payroll recoveries: ' . $e->getMessage();
        }
        header('Location: cash_advances.php');
        exit;
    }

    if (isset($_POST['create'])) {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $tx_date = $_POST['tx_date'] ?: date('Y-m-d');
        $amount = (float)($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        if ($emp_id && $amount > 0) {
            $installments = max(1, (int)($_POST['installment_count'] ?? 1));
            $stmt = $conn->prepare("INSERT INTO cash_advances (employee_id, tx_date, amount, description, status, created_by, request_status)
                                    VALUES (?,?,?,?, 'open', ?, 'approved')");
            $stmt->execute([$emp_id, $tx_date, $amount, $desc, $uid]);
            $newId = (int)$conn->lastInsertId();
            hr_loan_initialize_schedule($conn, $newId, $amount, $installments, $tx_date);
            $companyId = ca_employee_company_id($conn, $emp_id);
            try {
                AuditService::logEvent([
                    'action' => 'loan_issued',
                    'module' => 'hr',
                    'company_id' => $companyId,
                    'object_type' => 'cash_advances',
                    'object_id' => (string)$newId,
                    'object_ref' => 'Loan #' . $newId,
                    'summary' => 'Issued loan/advance #' . $newId . ' for AED ' . number_format($amount, 2)
                        . ' in ' . $installments . ' planned installment(s)',
                    'new_data' => [
                        'employee_id' => $emp_id,
                        'amount' => $amount,
                        'installment_count' => $installments,
                        'tx_date' => $tx_date,
                        'description' => $desc,
                    ],
                    'user_id' => $uid,
                    'source' => 'user',
                    'success' => true,
                ]);
            } catch (Throwable $ignored) {
            }
            $_SESSION['ok'] = 'Loan / salary advance added. Outstanding balance is tracked until recovered by payroll or cash repayment.';
        } else {
            $_SESSION['err'] = 'Employee and a positive amount are required.';
        }
        header('Location: cash_advances.php');
        exit;
    }

    if (isset($_POST['void_loan'])) {
        $id = (int)($_POST['id'] ?? 0);
        $loan = $id > 0 ? ca_load_loan($conn, $id) : null;
        if ($loan && ($loan['status'] ?? '') !== 'void') {
            $conn->prepare("UPDATE cash_advances SET status = 'void' WHERE id = ?")->execute([$id]);
            try {
                AuditService::logEvent([
                    'action' => 'loan_voided',
                    'module' => 'hr',
                    'company_id' => $loan['company_id'] !== null ? (int)$loan['company_id'] : null,
                    'object_type' => 'cash_advances',
                    'object_id' => (string)$id,
                    'object_ref' => 'Loan #' . $id,
                    'summary' => 'Voided loan/advance #' . $id,
                    'old_data' => ['status' => $loan['status'], 'amount' => $loan['amount']],
                    'new_data' => ['status' => 'void'],
                    'user_id' => $uid,
                    'source' => 'user',
                    'success' => true,
                ]);
            } catch (Throwable $ignored) {
            }
            $_SESSION['ok'] = "Advance #{$id} voided. It will no longer be recovered via payroll or cash.";
        } else {
            $_SESSION['err'] = 'Loan not found or already void.';
        }
        header('Location: cash_advances.php');
        exit;
    }

    if (isset($_POST['delete']) && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $loan = $id > 0 ? ca_load_loan($conn, $id) : null;
        if ($loan) {
            try {
                AuditService::logEvent([
                    'action' => 'loan_deleted',
                    'module' => 'hr',
                    'company_id' => $loan['company_id'] !== null ? (int)$loan['company_id'] : null,
                    'object_type' => 'cash_advances',
                    'object_id' => (string)$id,
                    'object_ref' => 'Loan #' . $id,
                    'summary' => 'Deleted loan/advance #' . $id,
                    'old_data' => [
                        'employee_id' => (int)$loan['employee_id'],
                        'amount' => $loan['amount'],
                        'status' => $loan['status'],
                        'tx_date' => $loan['tx_date'],
                    ],
                    'user_id' => $uid,
                    'source' => 'user',
                    'success' => true,
                ]);
            } catch (Throwable $ignored) {
            }
            $conn->prepare('DELETE FROM cash_advances WHERE id = ?')->execute([$id]);
            $_SESSION['ok'] = "Advance #{$id} deleted.";
        } else {
            $_SESSION['err'] = 'Loan not found.';
        }
        header('Location: cash_advances.php');
        exit;
    }
}

// --- filters ---
$emp_filter = (int)($_GET['employee_id'] ?? 0);
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$status = $_GET['status'] ?? '';
$request_status = $_GET['request_status'] ?? '';
$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);

$where = [];
$p = [];
if ($emp_filter) {
    $where[] = 'ca.employee_id=?';
    $p[] = $emp_filter;
}
hr_add_company_where($where, $p, $selectedCompanyId, 'e.company_id');
if ($from) {
    $where[] = 'ca.tx_date>=?';
    $p[] = $from;
}
if ($to) {
    $where[] = 'ca.tx_date<=?';
    $p[] = $to;
}
if ($status !== '') {
    $where[] = 'ca.status=?';
    $p[] = $status;
}
if ($request_status !== '') {
    $where[] = 'ca.request_status=?';
    $p[] = $request_status;
}
$SQLWHERE = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$pendingSql = "SELECT COUNT(*) FROM cash_advances ca JOIN employees e ON e.id = ca.employee_id WHERE ca.request_status='pending'";
$pendingParams = [];
if ($selectedCompanyId > 0) {
    $pendingSql .= ' AND e.company_id = ?';
    $pendingParams[] = $selectedCompanyId;
}
$pendingStmt = $conn->prepare($pendingSql);
$pendingStmt->execute($pendingParams);
$pendingCount = (int)$pendingStmt->fetchColumn();
$pendingHref = '?request_status=pending' . ($selectedCompanyId > 0 ? '&company_id=' . (int)$selectedCompanyId : '');

$employeeSql = "
  SELECT e.id, CONCAT(e.full_name,' (',e.employee_code,')') label, c.name AS company_name
  FROM employees e
  LEFT JOIN companies c ON c.id = e.company_id
";
$employeeWhere = ['e.status IN (' . hr_employee_status_in_sql(hr_employee_current_statuses()) . ')'];
$employeeParams = hr_employee_current_statuses();
if ($selectedCompanyId > 0) {
    $employeeWhere[] = 'e.company_id = ?';
    $employeeParams[] = $selectedCompanyId;
}
$employeeSql .= ' WHERE ' . implode(' AND ', $employeeWhere);
$employeeSql .= ' ORDER BY e.full_name';
$employeeStmt = $conn->prepare($employeeSql);
$employeeStmt->execute($employeeParams);
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
  SELECT ca.*, e.full_name, e.employee_code, c.name AS company_name
  FROM cash_advances ca
  JOIN employees e ON e.id=ca.employee_id
  LEFT JOIN companies c ON c.id = e.company_id
  $SQLWHERE
  ORDER BY
    CASE WHEN ca.request_status='pending' THEN 0 ELSE 1 END,
    ca.tx_date DESC,
    ca.id DESC
");
$stmt->execute($p);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sumIssued = 0.0;
$sumOutstanding = 0.0;
foreach ($rows as $r) {
    $sumIssued += (float)($r['principal'] ?? $r['amount'] ?? 0);
    $rem = (float)($r['remaining_balance'] ?? $r['principal'] ?? $r['amount'] ?? 0);
    if (($r['status'] ?? '') === 'settled' || ($r['status'] ?? '') === 'void') {
        $rem = 0.0;
    }
    if (($r['request_status'] ?? '') === 'pending') {
        $rem = 0.0;
    }
    $sumOutstanding += max(0.0, $rem);
}

// Settlements keyed by loan_id (for light history)
$settlementsByLoan = [];
if ($settlementsReady && $rows) {
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $ids = array_values(array_filter($ids));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stS = $conn->prepare("
            SELECT loan_id, settle_date, amount, method, notes
            FROM hr_loan_settlements
            WHERE loan_id IN ($in)
            ORDER BY settle_date DESC, id DESC
        ");
        $stS->execute($ids);
        foreach ($stS->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $lid = (int)$s['loan_id'];
            if (!isset($settlementsByLoan[$lid])) {
                $settlementsByLoan[$lid] = [];
            }
            if (count($settlementsByLoan[$lid]) < 8) {
                $settlementsByLoan[$lid][] = $s;
            }
        }
    }
}

$pageTitle = 'Loans / Advances';
$hrScopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
$pageStyles = '.num{text-align:right;font-variant-numeric:tabular-nums}';
require_once __DIR__ . '/includes/hr_layout_header.php';

$returnQs = http_build_query(array_filter([
    'employee_id' => $emp_filter ?: null,
    'company_id' => $selectedCompanyId ?: null,
    'from' => $from ?: null,
    'to' => $to ?: null,
    'status' => $status !== '' ? $status : null,
    'request_status' => $request_status !== '' ? $request_status : null,
]));
$cashReturnUrl = 'cash_advances.php' . ($returnQs !== '' ? '?' . $returnQs : '');

// Sync button kept as a quiet optional repair (idempotent). Not needed after the one-time historical sync.
$actionsHtml = '';
if ($loansSchemaReady && $settlementsReady) {
    $actionsHtml .= '<form method="post" class="d-inline" title="Optional repair: import any payroll advance recoveries not yet linked to loans. Safe to re-run." onsubmit="return confirm(\'Re-sync payroll loan recoveries? Only unlinked payroll lines are applied.\');">'
        . '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'
        . '<input type="hidden" name="sync_payroll_settlements" value="1">'
        . '<button type="submit" class="btn btn-outline-secondary btn-sm">Re-sync payroll (optional)</button>'
        . '</form> ';
}
$actionsHtml .= '<a href="' . h($hrBase) . '/payroll_runs" class="btn btn-outline-secondary btn-sm">Payroll runs</a>';

echo hr_ui_page_header(
    'Loans / Salary Advances',
    'Issue advances, track outstanding balances, record cash repayments, or recover via payroll.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Loans / Advances'],
    ],
    $actionsHtml
);
?>

  <?php if ($m = flash('err')): ?><div class="alert alert-danger"><?= h($m) ?></div><?php endif; ?>
  <?php if ($m = flash('ok')): ?><div class="alert alert-success"><?= h($m) ?></div><?php endif; ?>

  <div class="hr-settings-card mb-3">
    <div class="settings-header">How loans &amp; advances work</div>
    <div class="card-body">
      <div class="row g-3 small">
        <div class="col-md-3">
          <div class="fw-semibold mb-1"><i data-lucide="file-plus" style="width:16px;height:16px" class="me-1"></i>1. Issue</div>
          <p class="text-muted mb-0">HR records a loan/advance. The system tracks an <strong>outstanding balance</strong> equal to the amount issued.</p>
        </div>
        <div class="col-md-3">
          <div class="fw-semibold mb-1"><i data-lucide="calendar-range" style="width:16px;height:16px" class="me-1"></i>2. Planned installments</div>
          <p class="text-muted mb-0">Installments are a <strong>recovery plan</strong> (e.g. 4 × AED 250). They do <strong>not</strong> auto-post to payroll each month.</p>
        </div>
        <div class="col-md-3">
          <div class="fw-semibold mb-1"><i data-lucide="banknote" style="width:16px;height:16px" class="me-1"></i>3a. Payroll recovery</div>
          <p class="text-muted mb-0">On <strong>Build Payroll</strong>, enter <em>Advance apply</em> for the employee. That amount reduces the outstanding balance and appears on the payslip.</p>
        </div>
        <div class="col-md-3">
          <div class="fw-semibold mb-1"><i data-lucide="wallet" style="width:16px;height:16px" class="me-1"></i>3b. Cash repayment</div>
          <p class="text-muted mb-0">When the employee pays <strong>cash or bank transfer</strong>, use <strong>Record cash repayment</strong>. This is <strong>not</strong> a payslip deduction.</p>
        </div>
      </div>
      <p class="small text-muted mb-0 mt-3">
        Status becomes <strong>Settled</strong> automatically when outstanding reaches zero (via payroll and/or cash).
        Use <strong>Void</strong> only to cancel a loan that should no longer be collected — do not use void as a repayment.
      </p>
    </div>
  </div>

  <?php if ($pendingCount > 0 && $request_status !== 'pending'): ?>
  <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <strong><i class="bi bi-exclamation-triangle-fill me-2"></i><?= (int)$pendingCount ?> cash advance request<?= $pendingCount > 1 ? 's' : '' ?> pending approval</strong>
      <div class="small mt-1">Review on the employee profile (approve / reject).</div>
    </div>
    <a href="<?= h($pendingHref) ?>" class="btn btn-warning btn-sm">View pending</a>
  </div>
  <?php endif; ?>

  <div class="hr-settings-card mb-4">
    <div class="settings-header">Issue new loan / salary advance</div>
    <div class="card-body">
      <form method="post" class="row g-3" id="loanCreateForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="create" value="1">
        <div class="col-md-4">
          <label class="form-label">Employee *</label>
          <select name="employee_id" class="form-select" required>
            <option value="">-- Select --</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= (int)$e['id'] ?>">
                <?= h($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . h($e['company_name']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Issue date</label>
          <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Amount (AED) *</label>
          <input type="number" step="0.01" min="0.01" name="amount" id="loanAmount" class="form-control" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Planned installments</label>
          <input type="number" min="1" max="60" step="1" name="installment_count" id="loanInstallments" value="1" class="form-control">
          <div class="form-text" id="loanInstallmentPreview">Plan: 1 × amount (guidance only)</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Description</label>
          <input name="description" class="form-control" placeholder="Optional note">
        </div>
        <div class="col-12">
          <button class="btn btn-primary">Issue loan / advance</button>
        </div>
      </form>
    </div>
  </div>

  <div class="hr-filter-bar mb-3">
    <form class="row g-3" method="get">
      <div class="col-md-3">
        <label class="form-label">Employee</label>
        <select name="employee_id" class="form-select">
          <option value="0">All</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= $emp_filter == $e['id'] ? 'selected' : '' ?>>
              <?= h($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . h($e['company_name']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Company</label>
        <select name="company_id" class="form-select">
          <option value="0">All companies</option>
          <?php foreach ($companies as $company): ?>
            <option value="<?= (int)$company['id'] ?>" <?= $selectedCompanyId === (int)$company['id'] ? 'selected' : '' ?>>
              <?= h($company['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">Recovery status</label>
        <select name="status" class="form-select">
          <option value="">All</option>
          <?php foreach (['open', 'settled', 'void'] as $st): ?>
            <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Request status</label>
        <select name="request_status" class="form-select">
          <option value="">All</option>
          <option value="pending" <?= $request_status === 'pending' ? 'selected' : '' ?>>Pending</option>
          <option value="approved" <?= $request_status === 'approved' ? 'selected' : '' ?>>Approved</option>
          <option value="rejected" <?= $request_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Apply</button>
      </div>
    </form>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex flex-wrap align-items-center gap-2">
      <span>Entries</span>
      <div class="ms-auto small d-flex flex-wrap gap-3">
        <span>Issued: <strong><?= number_format($sumIssued, 2) ?></strong></span>
        <span>Outstanding: <strong><?= number_format($sumOutstanding, 2) ?></strong></span>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Employee</th>
                <th>Company</th>
                <th class="num">Issued</th>
                <?php if ($loansSchemaReady): ?>
                  <th class="num">Outstanding</th>
                  <th>Plan</th>
                <?php endif; ?>
                <th>Description</th>
                <th>Request</th>
                <th>Status</th>
                <th class="text-end" style="min-width:220px">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="<?= $loansSchemaReady ? 11 : 9 ?>" class="text-center text-muted py-4">No records.</td></tr>
            <?php else: foreach ($rows as $r):
                $reqStatus = $r['request_status'] ?? null;
                $isPending = $reqStatus === 'pending';
                $issued = (float)($r['principal'] ?? $r['amount'] ?? 0);
                $remaining = (float)($r['remaining_balance'] ?? $r['principal'] ?? $r['amount'] ?? 0);
                if (($r['status'] ?? '') === 'settled') {
                    $remaining = 0.0;
                }
                if (($r['status'] ?? '') === 'void') {
                    $remaining = 0.0;
                }
                $instN = max(1, (int)($r['installment_count'] ?? 1));
                $instAmt = isset($r['installment_amount']) ? (float)$r['installment_amount'] : ($instN > 0 ? round($issued / $instN, 2) : $issued);
                $canCashSettle = !$isPending
                    && ($r['status'] ?? '') === 'open'
                    && $remaining > 0.005
                    && ($reqStatus === null || $reqStatus === 'approved' || $reqStatus === '');
                $loanSettlements = $settlementsByLoan[(int)$r['id']] ?? [];
                $rowClass = $isPending ? 'table-warning' : '';
            ?>
              <tr class="<?= $rowClass ?>">
                <td><?= (int)$r['id'] ?></td>
                <td class="text-nowrap"><?= h($r['tx_date']) ?></td>
                <td>
                  <?= h($r['full_name']) ?>
                  <div class="small text-muted"><?= h($r['employee_code']) ?></div>
                </td>
                <td><?= h($r['company_name'] ?: '—') ?></td>
                <td class="num text-nowrap">
                  <?php if ($isPending && !empty($r['requested_amount'])): ?>
                    <span class="text-muted small">Req <?= number_format((float)$r['requested_amount'], 2) ?></span>
                  <?php elseif ($reqStatus === 'approved' && !empty($r['approved_amount']) && (float)$r['approved_amount'] != (float)($r['requested_amount'] ?? $r['amount'])): ?>
                    <div class="text-decoration-line-through text-muted small"><?= number_format((float)($r['requested_amount'] ?? $r['amount']), 2) ?></div>
                    <div><?= number_format((float)$r['approved_amount'], 2) ?></div>
                  <?php else: ?>
                    <?= number_format($issued, 2) ?>
                  <?php endif; ?>
                </td>
                <?php if ($loansSchemaReady): ?>
                  <td class="num text-nowrap">
                    <?php if ($isPending): ?>
                      <span class="text-muted">—</span>
                    <?php else: ?>
                      <strong class="<?= $remaining > 0.005 ? 'text-warning-emphasis' : 'text-success' ?>"><?= number_format($remaining, 2) ?></strong>
                    <?php endif; ?>
                  </td>
                  <td class="small text-nowrap">
                    <?php if ($isPending): ?>
                      <span class="text-muted">—</span>
                    <?php else: ?>
                      <?= (int)$instN ?> × <?= number_format($instAmt, 2) ?>
                      <div class="text-muted">guidance</div>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <td><?php
                  $desc = trim((string)($r['description'] ?? ''));
                  echo $desc !== '' ? h($desc) : '<span class="text-muted">—</span>';
                ?></td>
                <td>
                  <?php if ($isPending): ?>
                    <?= hr_ui_status_pill('pending', 'Pending') ?>
                  <?php elseif ($reqStatus === 'approved'): ?>
                    <?= hr_ui_status_pill('approved', 'Approved') ?>
                  <?php elseif ($reqStatus === 'rejected'): ?>
                    <?= hr_ui_status_pill('rejected', 'Rejected') ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                    $st = (string)($r['status'] ?? 'open');
                    echo hr_ui_status_pill($st, ucfirst($st));
                  ?>
                </td>
                <td class="text-end">
                  <div class="d-flex flex-wrap justify-content-end gap-1">
                    <?php if ($isPending): ?>
                      <a href="employee_view.php?id=<?= (int)$r['employee_id'] ?>#tab-cashadv" class="btn btn-sm btn-warning" title="Approve or reject on employee profile">
                        Review
                      </a>
                    <?php endif; ?>
                    <?php if ($canCashSettle): ?>
                      <button type="button"
                              class="btn btn-sm btn-success btn-loan-cash-settle"
                              data-bs-toggle="modal"
                              data-bs-target="#loanCashSettleModal"
                              data-loan-id="<?= (int)$r['id'] ?>"
                              data-employee-id="<?= (int)$r['employee_id'] ?>"
                              data-remaining="<?= number_format($remaining, 2, '.', '') ?>"
                              data-loan-date="<?= h($r['tx_date']) ?>"
                              data-loan-desc="<?= h((string)($r['description'] ?? '')) ?>"
                              data-employee-label="<?= h($r['full_name'] . ' (' . $r['employee_code'] . ')') ?>"
                              title="Employee paid cash / transfer — record repayment">
                        Cash repayment
                      </button>
                    <?php endif; ?>
                    <a href="employee_view.php?id=<?= (int)$r['employee_id'] ?>#tab-cashadv" class="btn btn-sm btn-outline-secondary">Employee</a>
                    <?php if (($r['status'] ?? '') === 'open' && !$isPending): ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('Void loan #<?= (int)$r['id'] ?>? It will no longer be collected via payroll or cash.');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="void_loan" value="1">
                        <button class="btn btn-sm btn-outline-secondary">Void</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this record permanently? Prefer Void if the loan should stay for history.');">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="delete" value="1">
                      <button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                  </div>
                  <?php if ($loanSettlements): ?>
                    <details class="mt-1 text-start">
                      <summary class="small text-muted" style="cursor:pointer">Repayment history (<?= count($loanSettlements) ?>)</summary>
                      <ul class="small mb-0 ps-3 mt-1">
                        <?php foreach ($loanSettlements as $s): ?>
                          <li>
                            <?= h($s['settle_date']) ?> —
                            AED <?= number_format((float)$s['amount'], 2) ?>
                            via <?= h($s['method']) ?>
                            <?php if (!empty($s['notes'])): ?>
                              <span class="text-muted">(<?= h($s['notes']) ?>)</span>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </details>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Cash repayment modal -->
  <div class="modal fade" id="loanCashSettleModal" tabindex="-1" aria-labelledby="loanCashSettleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" action="loan_settle_cash.php" class="modal-content">
        <?php csrf_field(); ?>
        <input type="hidden" name="type" value="loan">
        <input type="hidden" name="id" id="loanCashSettleId" value="">
        <input type="hidden" name="employee_id" id="loanCashSettleEmployeeId" value="">
        <input type="hidden" name="return" value="<?= h($cashReturnUrl) ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="loanCashSettleModalLabel">
            <i class="bi bi-cash-coin me-2 text-success"></i>Record cash repayment
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light border small mb-3">
            Use this only when the employee <strong>paid cash or bank transfer</strong> to the company.
            This is <strong>not</strong> a payroll deduction and will not appear on the payslip.
          </div>
          <div class="mb-2 small text-muted" id="loanCashSettleMeta"></div>
          <div class="mb-3">
            <label class="form-label">Outstanding balance</label>
            <div class="form-control-plaintext fw-semibold" id="loanCashSettleRemainingLabel">—</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="loanCashSettleAmount">Amount received (AED)</label>
            <input type="number" step="0.01" min="0.01" class="form-control" name="amount" id="loanCashSettleAmount" required>
            <div class="form-text">Partial repayments are allowed. Cannot exceed outstanding.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="loanCashSettleDate">Payment date</label>
            <input type="date" class="form-control" name="settle_date" id="loanCashSettleDate" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="mb-0">
            <label class="form-label" for="loanCashSettleNotes">Notes (optional)</label>
            <input type="text" class="form-control" name="notes" id="loanCashSettleNotes" placeholder="e.g. Cash received / bank transfer ref">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Save cash repayment</button>
        </div>
      </form>
    </div>
  </div>

<?php
$pageScripts = <<<'HR_LOANS_JS'
<script>
(function () {
  function updateInstallmentPreview() {
    var amtEl = document.getElementById('loanAmount');
    var nEl = document.getElementById('loanInstallments');
    var out = document.getElementById('loanInstallmentPreview');
    if (!amtEl || !nEl || !out) return;
    var amt = parseFloat(amtEl.value || '0');
    var n = Math.max(1, parseInt(nEl.value || '1', 10) || 1);
    if (!(amt > 0)) {
      out.textContent = 'Plan: enter amount — installment = amount ÷ N (guidance only, not auto-payroll)';
      return;
    }
    var per = Math.round((amt / n) * 100) / 100;
    out.textContent = 'Plan: ' + n + ' × AED ' + per.toFixed(2) + ' (guidance only — recover via payroll apply or cash repayment)';
  }
  document.getElementById('loanAmount')?.addEventListener('input', updateInstallmentPreview);
  document.getElementById('loanInstallments')?.addEventListener('input', updateInstallmentPreview);
  updateInstallmentPreview();

  var modal = document.getElementById('loanCashSettleModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    if (!btn) return;
    var loanId = btn.getAttribute('data-loan-id') || '';
    var empId = btn.getAttribute('data-employee-id') || '';
    var remaining = btn.getAttribute('data-remaining') || '0';
    var date = btn.getAttribute('data-loan-date') || '';
    var desc = btn.getAttribute('data-loan-desc') || '';
    var label = btn.getAttribute('data-employee-label') || '';
    document.getElementById('loanCashSettleId').value = loanId;
    document.getElementById('loanCashSettleEmployeeId').value = empId;
    document.getElementById('loanCashSettleRemainingLabel').textContent = 'AED ' + remaining;
    document.getElementById('loanCashSettleAmount').value = remaining;
    document.getElementById('loanCashSettleAmount').max = remaining;
    var meta = 'Loan #' + loanId + (label ? ' · ' + label : '') + (date ? ' · issued ' + date : '');
    if (desc) meta += ' · ' + desc;
    document.getElementById('loanCashSettleMeta').textContent = meta;
  });
})();
</script>
HR_LOANS_JS;
require_once __DIR__ . '/includes/hr_layout_footer.php';
