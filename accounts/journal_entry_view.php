<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sm_journal_service.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n) { return number_format((float)$n, 2); }
}

$id = (int)($_GET['id'] ?? 0);
$jv = $id > 0 ? sm_jv_load($conn, $id) : null;
if (!$jv) {
    header('Location: journal_entries.php');
    exit;
}
$lines = sm_jv_load_lines($conn, $id);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_journal'])) {
    csrf_verify();
    $result = sm_jv_post($conn, $id, $_SESSION['user_id'] ?? null);
    if ($result['success']) {
        header('Location: journal_entries.php?msg=' . urlencode($result['message']));
        exit;
    }
    $err = $result['message'];
    $jv = sm_jv_load($conn, $id);
}

$sumDr = array_sum(array_column($lines, 'debit'));
$sumCr = array_sum(array_column($lines, 'credit'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Journal #<?= (int)$id ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex mb-3">
    <a href="journal_entries.php" class="btn btn-outline-secondary me-2">Back</a>
    <h3 class="mb-0">Journal Entry #<?= (int)$id ?></h3>
    <span class="badge bg-<?= $jv['status']==='posted'?'success':'warning' ?> ms-2 align-self-center"><?= h($jv['status']) ?></span>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <div class="card mb-3">
    <div class="card-body row g-2">
      <div class="col-md-3"><strong>Date:</strong> <?= h($jv['journal_date']) ?></div>
      <div class="col-md-9"><strong>Memo:</strong> <?= h($jv['memo'] ?: '—') ?></div>
      <?php if (!empty($jv['gl_journal_id'])): ?>
        <div class="col-12"><strong>GL Journal ID:</strong> <?= (int)$jv['gl_journal_id'] ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th>#</th><th>Account</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $ln): ?>
          <tr>
            <td><?= (int)$ln['line_no'] ?></td>
            <td><code><?= h($ln['account_no']) ?></code></td>
            <td><?= h($ln['description'] ?: '—') ?></td>
            <td class="text-end"><?= money($ln['debit']) ?></td>
            <td class="text-end"><?= money($ln['credit']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="table-light">
            <th colspan="3" class="text-end">Totals</th>
            <th class="text-end"><?= money($sumDr) ?></th>
            <th class="text-end"><?= money($sumCr) ?></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <?php if ($jv['status'] === 'draft' && sm_user_can_finalize($conn)): ?>
    <form method="post" class="mt-3" onsubmit="return confirm('Post this journal to the general ledger?')">
      <?php csrf_field(); ?>
      <button name="post_journal" value="1" class="btn btn-success">Post to GL</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
