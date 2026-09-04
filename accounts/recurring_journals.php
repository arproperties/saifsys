<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/cleaning_accounting_context.php';
require_once __DIR__ . '/../includes/work_order_financial_guard.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$accounts = $conn->query("SELECT account_no, name FROM chart_of_accounts WHERE is_header=0 ORDER BY account_no")->fetchAll(PDO::FETCH_ASSOC);
$msg = $_GET['msg'] ?? '';
$err = '';

$tableOk = false;
$chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sm_recurring_journals'");
$chk->execute();
$tableOk = (int)$chk->fetchColumn() > 0;

if ($tableOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $memo = trim($_POST['memo'] ?? '');
    $frequency = in_array($_POST['frequency'] ?? '', ['monthly', 'quarterly', 'yearly'], true) ? $_POST['frequency'] : 'monthly';
    $nextRun = $_POST['next_run_date'] ?? date('Y-m-d');

    $lines = [];
    $accNos = $_POST['line_account_no'] ?? [];
    $descs = $_POST['line_desc'] ?? [];
    $debits = $_POST['line_debit'] ?? [];
    $credits = $_POST['line_credit'] ?? [];
    for ($i = 0; $i < count($accNos); $i++) {
        $acc = trim($accNos[$i] ?? '');
        if ($acc === '') continue;
        $lines[] = [$acc, trim($descs[$i] ?? ''), (float)($debits[$i] ?? 0), (float)($credits[$i] ?? 0)];
    }

    if ($name === '' || count($lines) < 2) {
        $err = 'Name and at least two balanced lines are required.';
    } else {
        $companyId = cleaning_accounting_company_id($conn);
        $conn->beginTransaction();
        try {
            $conn->prepare("INSERT INTO sm_recurring_journals (company_id, name, memo, frequency, next_run_date, created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$companyId, $name, $memo ?: null, $frequency, $nextRun, $_SESSION['user_id'] ?? null]);
            $rid = (int)$conn->lastInsertId();
            $ins = $conn->prepare("INSERT INTO sm_recurring_journal_lines (recurring_journal_id, line_no, account_no, description, debit, credit) VALUES (?,?,?,?,?,?)");
            $n = 1;
            foreach ($lines as $ln) {
                $ins->execute([$rid, $n++, $ln[0], $ln[1] ?: null, $ln[2], $ln[3]]);
            }
            $conn->commit();
            header('Location: recurring_journals.php?msg=' . urlencode('Recurring journal created.'));
            exit;
        } catch (Throwable $e) {
            $conn->rollBack();
            $err = $e->getMessage();
        }
    }
}

$rows = [];
if ($tableOk) {
    $rows = $conn->query("SELECT * FROM sm_recurring_journals ORDER BY next_run_date ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Recurring Journals</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex mb-3">
    <h3 class="mb-0 me-auto">Recurring Journals</h3>
    <a href="../account.php" class="btn btn-outline-secondary btn-sm">Back</a>
  </div>
  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run Phase 7 migration first.</div>
  <?php else: ?>

  <div class="card mb-4">
    <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th>Name</th><th>Frequency</th><th>Next run</th><th>Last run</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['name']) ?></td>
            <td><?= h($r['frequency']) ?></td>
            <td><?= h($r['next_run_date']) ?></td>
            <td><?= h($r['last_run_date'] ?: '—') ?></td>
            <td><?= h($r['status']) ?></td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="5" class="text-muted text-center py-3">No recurring journals yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Add Recurring Journal</div>
    <div class="card-body">
      <form method="post" id="rjForm">
        <?php csrf_field(); ?>
        <div class="row g-2 mb-3">
          <div class="col-md-4"><input class="form-control" name="name" placeholder="Template name" required></div>
          <div class="col-md-4"><input class="form-control" name="memo" placeholder="Memo"></div>
          <div class="col-md-2">
            <select class="form-select" name="frequency">
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
              <option value="yearly">Yearly</option>
            </select>
          </div>
          <div class="col-md-2"><input type="date" class="form-control" name="next_run_date" value="<?= h(date('Y-m-d')) ?>"></div>
        </div>
        <table class="table table-sm" id="linesTable">
          <thead><tr><th>Account</th><th>Description</th><th>Debit</th><th>Credit</th></tr></thead>
          <tbody></tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary mb-2" id="addLine">+ Line</button>
        <div><button class="btn btn-primary">Save</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
const ACCOUNTS = <?= json_encode($accounts) ?>;
function addLine() {
  const tr = document.createElement('tr');
  let opts = '<option value="">—</option>';
  ACCOUNTS.forEach(a => opts += `<option value="${a.account_no}">${a.account_no} – ${a.name}</option>`);
  tr.innerHTML = `<td><select class="form-select form-select-sm" name="line_account_no[]">${opts}</select></td>
    <td><input class="form-control form-control-sm" name="line_desc[]"></td>
    <td><input type="number" step="0.01" class="form-control form-control-sm" name="line_debit[]"></td>
    <td><input type="number" step="0.01" class="form-control form-control-sm" name="line_credit[]"></td>`;
  document.querySelector('#linesTable tbody').appendChild(tr);
}
document.getElementById('addLine').onclick = addLine;
addLine(); addLine();
</script>
</body>
</html>
