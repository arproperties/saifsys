<?php
/**
 * Opening balance tool:
 * Posts a balanced manual journal for a selected account and date.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/gl_posting.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $journal_date = trim((string) ($_POST['journal_date'] ?? ''));
    $account_id = (int) ($_POST['account_id'] ?? 0);
    $offset_account_id = (int) ($_POST['offset_account_id'] ?? 0);
    $side = trim((string) ($_POST['side'] ?? 'debit'));
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $memo_in = trim((string) ($_POST['memo'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journal_date)) {
        $err = 'Journal date must be YYYY-MM-DD.';
    } elseif ($account_id <= 0 || $offset_account_id <= 0) {
        $err = 'Select both account and offset account.';
    } elseif ($account_id === $offset_account_id) {
        $err = 'Account and offset account must be different.';
    } elseif (!in_array($side, ['debit', 'credit'], true)) {
        $err = 'Invalid side.';
    } elseif ($amount <= 0) {
        $err = 'Amount must be greater than zero.';
    } else {
        try {
            $st = $conn->prepare("SELECT id, account_no, name FROM chart_of_accounts WHERE id IN (?, ?) AND is_active = 1");
            $st->execute([$account_id, $offset_account_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $map = [];
            foreach ($rows as $r) {
                $map[(int) $r['id']] = $r;
            }

            if (empty($map[$account_id]) || empty($map[$offset_account_id])) {
                throw new RuntimeException('Selected account or offset account is missing/inactive.');
            }

            $descMain = 'Opening balance — ' . $map[$account_id]['account_no'] . ' ' . $map[$account_id]['name'];
            $descOffset = 'Opening balance offset — ' . $map[$offset_account_id]['account_no'] . ' ' . $map[$offset_account_id]['name'];
            $memo = 'Opening balance: ' . $map[$account_id]['account_no'] . ' @ ' . $journal_date;
            if ($memo_in !== '') {
                $memo .= ' — ' . mb_substr($memo_in, 0, 120);
            }

            if ($side === 'debit') {
                $lines = [
                    ['account_id' => $account_id, 'desc' => $descMain, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $offset_account_id, 'desc' => $descOffset, 'debit' => 0, 'credit' => $amount],
                ];
            } else {
                $lines = [
                    ['account_id' => $account_id, 'desc' => $descMain, 'debit' => 0, 'credit' => $amount],
                    ['account_id' => $offset_account_id, 'desc' => $descOffset, 'debit' => $amount, 'credit' => 0],
                ];
            }

            $jid = gl_create_journal($conn, [
                'date' => $journal_date,
                'source' => 'manual',
                'source_id' => null,
                'memo' => $memo,
                'created_by' => current_user_id(),
            ], $lines);

            $msg = 'Opening balance posted in journal #' . $jid . '.';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$accounts = $conn->query("
    SELECT id, account_no, name, type
    FROM chart_of_accounts
    WHERE is_active = 1 AND is_header = 0
    ORDER BY
      FIELD(type, 'Asset','Liability','Equity','Revenue','Expense'),
      account_no
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recent = $conn->query("
    SELECT j.id, j.journal_no, j.journal_date, j.memo, j.created_at
    FROM gl_journals j
    WHERE j.source = 'manual' AND j.memo LIKE 'Opening balance:%'
    ORDER BY j.id DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Opening balances</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f6f7f9}</style>
</head>
<body>
<div class="container my-4">
  <div class="d-flex align-items-center mb-3">
    <div>
      <div class="text-uppercase small text-muted">Accounting</div>
      <h3 class="mb-0">Opening balances</h3>
      <div class="text-muted">Posts balanced manual journals. Use one line per account opening and offset to Opening Balance Equity.</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="tools.php" class="btn btn-outline-secondary">Tools</a>
      <a href="reports.php" class="btn btn-outline-secondary">Reports</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="post" class="row g-3 align-items-end">
        <?php csrf_field(); ?>
        <div class="col-md-2">
          <label class="form-label">Date</label>
          <input type="date" name="journal_date" class="form-control" value="<?= h($_POST['journal_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Account to set</label>
          <select name="account_id" class="form-select" required>
            <option value="">— Select account —</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int) $a['id'] ?>" <?= ((int) ($_POST['account_id'] ?? 0) === (int) $a['id']) ? 'selected' : '' ?>>
                [<?= h($a['type']) ?>] <?= h($a['account_no'] . ' — ' . $a['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Side</label>
          <select name="side" class="form-select" required>
            <option value="debit" <?= (($_POST['side'] ?? 'debit') === 'debit') ? 'selected' : '' ?>>Debit</option>
            <option value="credit" <?= (($_POST['side'] ?? '') === 'credit') ? 'selected' : '' ?>>Credit</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Amount</label>
          <input type="number" name="amount" step="0.01" min="0.01" class="form-control" value="<?= h($_POST['amount'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Offset account</label>
          <select name="offset_account_id" class="form-select" required>
            <option value="">— Select offset —</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int) $a['id'] ?>" <?= ((int) ($_POST['offset_account_id'] ?? 0) === (int) $a['id']) ? 'selected' : '' ?>>
                [<?= h($a['type']) ?>] <?= h($a['account_no'] . ' — ' . $a['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Usually an equity account like Opening Balance Equity.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Memo (optional)</label>
          <input type="text" name="memo" class="form-control" maxlength="120" value="<?= h($_POST['memo'] ?? '') ?>" placeholder="e.g. Legacy balances as of cutover">
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-primary w-100">Post opening</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header"><strong>Recent opening journals</strong></div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Journal</th>
            <th>Date</th>
            <th>Memo</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= h($r['journal_no']) ?> <span class="text-muted">#<?= (int) $r['id'] ?></span></td>
              <td><?= h($r['journal_date']) ?></td>
              <td><?= h($r['memo']) ?></td>
              <td><?= h($r['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recent): ?>
            <tr><td colspan="4" class="text-muted p-4">No opening journals posted yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
