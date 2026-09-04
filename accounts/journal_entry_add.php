<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sm_journal_service.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$accounts = $conn->query("
    SELECT account_no, name FROM chart_of_accounts
    WHERE is_header = 0 ORDER BY account_no
")->fetchAll(PDO::FETCH_ASSOC);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $lines = [];
    $accNos = $_POST['line_account_no'] ?? [];
    $descs = $_POST['line_desc'] ?? [];
    $debits = $_POST['line_debit'] ?? [];
    $credits = $_POST['line_credit'] ?? [];
    for ($i = 0; $i < count($accNos); $i++) {
        $acc = trim($accNos[$i] ?? '');
        if ($acc === '') continue;
        $lines[] = [
            'account_no' => $acc,
            'description' => trim($descs[$i] ?? ''),
            'debit' => (float)($debits[$i] ?? 0),
            'credit' => (float)($credits[$i] ?? 0),
        ];
    }
    $result = sm_jv_save_draft($conn, [
        'journal_date' => $_POST['journal_date'] ?? date('Y-m-d'),
        'memo' => $_POST['memo'] ?? '',
    ], $lines, null, $_SESSION['user_id'] ?? null);

    if ($result['success']) {
        header('Location: journal_entry_view.php?id=' . (int)$result['id']);
        exit;
    }
    $err = $result['message'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>New Journal Entry</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex mb-3">
    <a href="journal_entries.php" class="btn btn-outline-secondary me-2">Back</a>
    <h3 class="mb-0">New Journal Entry</h3>
  </div>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <form method="post" id="jvForm">
    <?php csrf_field(); ?>
    <div class="card mb-3">
      <div class="card-body row g-3">
        <div class="col-md-3">
          <label class="form-label">Date</label>
          <input type="date" class="form-control" name="journal_date" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
        <div class="col-md-9">
          <label class="form-label">Memo</label>
          <input class="form-control" name="memo" placeholder="Description of this journal entry">
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between">
        <span>Lines</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addLine">+ Add line</button>
      </div>
      <div class="table-responsive">
        <table class="table mb-0" id="linesTable">
          <thead><tr><th>Account</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th></th></tr></thead>
          <tbody></tbody>
          <tfoot>
            <tr class="table-light">
              <th colspan="2" class="text-end">Totals</th>
              <th class="text-end" id="sumDr">0.00</th>
              <th class="text-end" id="sumCr">0.00</th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div class="mt-3 text-end">
      <button class="btn btn-primary">Save Draft</button>
    </div>
  </form>
</div>
<script>
const ACCOUNTS = <?= json_encode($accounts) ?>;
function accountOptions(selected) {
  let html = '<option value="">— select —</option>';
  ACCOUNTS.forEach(a => {
    html += `<option value="${a.account_no}" ${selected===a.account_no?'selected':''}>${a.account_no} – ${a.name}</option>`;
  });
  return html;
}
function addLineRow(data={}) {
  const tb = document.querySelector('#linesTable tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><select class="form-select form-select-sm" name="line_account_no[]">${accountOptions(data.account_no||'')}</select></td>
    <td><input class="form-control form-control-sm" name="line_desc[]" value="${data.description||''}"></td>
    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end line-dr" name="line_debit[]" value="${data.debit||''}"></td>
    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end line-cr" name="line_credit[]" value="${data.credit||''}"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger rm">×</button></td>`;
  tb.appendChild(tr);
  tr.querySelector('.rm').onclick = () => { tr.remove(); recalc(); };
  tr.querySelectorAll('input').forEach(i => i.addEventListener('input', recalc));
  recalc();
}
function recalc() {
  let dr=0, cr=0;
  document.querySelectorAll('.line-dr').forEach(i => dr += parseFloat(i.value||0));
  document.querySelectorAll('.line-cr').forEach(i => cr += parseFloat(i.value||0));
  document.getElementById('sumDr').textContent = dr.toFixed(2);
  document.getElementById('sumCr').textContent = cr.toFixed(2);
}
document.getElementById('addLine').onclick = () => addLineRow();
addLineRow(); addLineRow();
</script>
</body>
</html>
