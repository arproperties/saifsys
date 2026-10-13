<?php
/**
 * Finance > Vouchers: history of every voucher of the company selected in the
 * session. One row per add, edit or delete, with the user and what changed.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_voucher_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn);
$err = '';

$type = (string)($_GET['type'] ?? '');
if (!isset(FIN_VOUCHER_TYPES[$type])) {
    $type = '';
}
$actions = ['created' => 'Added', 'edited' => 'Edited', 'deleted' => 'Deleted'];
$action = (string)($_GET['action'] ?? '');
if (!isset($actions[$action])) {
    $action = '';
}
$byUser = (int)($_GET['user'] ?? 0);
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
if (($from !== '' && !fin_voucher_valid_date($from)) || ($to !== '' && !fin_voucher_valid_date($to)) || ($from !== '' && $to !== '' && $from > $to)) {
    $err = 'Choose a valid period: the From date must be on or before the To date.';
    $from = $to = '';
}
$filtered = $type !== '' || $action !== '' || $byUser > 0 || $from !== '' || $to !== '';

$rows = [];
$users = [];
if ($tableOk && $company) {
    $rows = fin_voucher_history($conn, $companyId, ['type' => $type, 'action' => $action, 'user_id' => $byUser, 'from' => $from, 'to' => $to]);
    // Only people who appear in this company's history.
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id, COALESCE(NULLIF(TRIM(u.fullname), ''), u.username) AS name
          FROM fin_voucher_history h
          JOIN user u ON u.id = h.user_id
         WHERE h.company_id = ?
         ORDER BY name");
    $stmt->execute([$companyId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Voucher History';
$finActive = 'history';
$pageSubtitle = $rows ? count($rows) . ' changes' : '';
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .v-chg { font-size: .85rem; margin: 0; }
  .v-chg td { padding: .1rem .5rem .1rem 0; }
  .v-chg td.v-old { color: #b02a37; }
  .v-chg td.v-new { color: #146c43; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its voucher history.</div>
  <?php else: ?>

  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="hType">Voucher</label>
          <select class="form-select form-select-sm" id="hType" name="type">
            <option value="">All kinds</option>
            <?php foreach (FIN_VOUCHER_TYPES as $k => $t): ?>
              <option value="<?= h($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($t['plural']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="hAction">Change</label>
          <select class="form-select form-select-sm" id="hAction" name="action">
            <option value="">All changes</option>
            <?php foreach ($actions as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= $action === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1" for="hUser">By</label>
          <select class="form-select form-select-sm" id="hUser" name="user">
            <option value="">Everyone</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= $byUser === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="hFrom">From</label>
          <input type="date" class="form-control form-control-sm" id="hFrom" name="from" value="<?= h($from) ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1" for="hTo">To</label>
          <input type="date" class="form-control form-control-sm" id="hTo" name="to" value="<?= h($to) ?>">
        </div>
        <div class="col-auto">
          <button class="btn btn-sm btn-primary">Show</button>
          <?php if ($filtered): ?><a href="fin_voucher_history.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>When</th><th>Voucher</th><th>Change</th><th>By</th><th class="text-end">Amount (AED)</th><th>What changed</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="text-nowrap"><?= h(date('d M Y, h:i A', strtotime($r['created_at']))) ?></td>
            <td class="text-nowrap"><a href="fin_voucher_view.php?id=<?= (int)$r['voucher_id'] ?>"><?= h($r['voucher_no']) ?></a><div class="text-muted small"><?= h(FIN_VOUCHER_TYPES[$r['type']]['label']) ?></div></td>
            <td><?= fin_voucher_action_badge($r['action']) ?></td>
            <td><?= h($r['user_name'] ?? 'Unknown user') ?></td>
            <td class="v-num"><?= $r['amount'] !== null ? number_format((float)$r['amount'], 2) : '' ?></td>
            <td>
              <?php if ($r['changes']): ?>
              <table class="v-chg">
                <?php foreach ($r['changes'] as [$what, $old, $new]): ?>
                <tr><td class="text-muted"><?= h($what) ?></td><td class="v-old"><?= $old !== '' ? '<s>' . h($old) . '</s>' : '' ?></td><td class="text-muted"><?= $old !== '' && $new !== '' ? '→' : '' ?></td><td class="v-new"><?= h($new) ?></td></tr>
                <?php endforeach; ?>
              </table>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="6" class="text-muted text-center py-4"><?= $filtered ? 'No changes match.' : 'No vouchers have been recorded yet.' ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
