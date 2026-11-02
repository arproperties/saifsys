<?php
/**
 * Finance > Vouchers: one voucher (?id=) with its posted lines and its history
 * (who added, edited and deleted it). A posted voucher can be edited or deleted here.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_voucher_helper.php';
require_once __DIR__ . '/../includes/fin_supplier_payment_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_voucher_tables_ready($conn);
$id = (int)($_GET['id'] ?? 0);
$msg = $_GET['msg'] ?? '';
$err = '';

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if (($_POST['action'] ?? '') !== 'delete') {
            throw new InvalidArgumentException('Unknown action.');
        }
        $gone = fin_voucher_get($conn, $companyId, $id);
        fin_voucher_delete($conn, $companyId, $id, $userId);
        $done = urlencode($gone['voucher_no'] . ' deleted. Its amounts no longer count in any balance.');
        // Deleted from the list: go back to the list.
        header('Location: ' . (($_POST['back'] ?? '') === 'list' ? FIN_VOUCHER_TYPES[$gone['type']]['list'] . '?msg=' . $done : 'fin_voucher_view.php?id=' . $id . '&msg=' . $done));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_voucher_view: ' . $e->getMessage());
        $err = 'The voucher could not be deleted.';
    }
}

$v = ($tableOk && $company && $id > 0) ? fin_voucher_get($conn, $companyId, $id) : null;
$type = $v ? $v['type'] : 'jv';
$vt = FIN_VOUCHER_TYPES[$type];
// A supplier invoice also shows its VAT split and the files attached to it.
$si = ($v && $type === 'si' && fin_si_ready($conn)) ? fin_si_get($conn, $companyId, $id) : null;
$files = $si ? fin_attach_list($conn, $companyId, $id) : [];
// What this voucher is tied to: a supplier payment, an advance journal, the allocations.
$links = $v ? fin_voucher_links($conn, $companyId, $id) : ['supplier_payment' => false, 'transfer' => false, 'allocated' => 0.0];
$sp = ($v && $links['supplier_payment']) ? fin_sp_get($conn, $companyId, $id) : null;
$allocs = ($v && ($si || $sp) && fin_alloc_ready($conn)) ? fin_sp_allocations($conn, $companyId, $id) : [];
$canEdit = $v && $v['status'] === 'posted' && !$links['supplier_payment'] && !$links['transfer'] && !in_array($type, FIN_SCHEDULED_TYPES, true);
// A monthly entry: the schedule it came from.
$autoFrom = null;
if ($v && in_array($type, FIN_SCHEDULED_TYPES, true) && fin_auto_ready($conn)) {
    $stmt = $conn->prepare("SELECT source_type, source_id, IF(source_type = 'asset', (SELECT name FROM fin_assets WHERE id = source_id), (SELECT name FROM fin_alloc_schedules WHERE id = source_id)) AS name FROM fin_auto_entries WHERE voucher_id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    $autoFrom = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
// A supplier invoice can be added to the asset list.
$invoiceAssets = ($si && fin_auto_ready($conn)) ? (function () use ($conn, $companyId, $id) {
    $stmt = $conn->prepare("SELECT id, name FROM fin_assets WHERE company_id = ? AND source_voucher_id = ? ORDER BY id");
    $stmt->execute([$companyId, $id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
})() : [];
$canDelete = $v && $v['status'] === 'posted' && !$links['transfer'];
$history = $v ? array_reverse(fin_voucher_history($conn, $companyId, ['voucher_id' => $id])) : [];
$totalDebit = $totalCredit = 0.0;
if ($v) {
    foreach ($v['lines'] as $l) {
        $totalDebit += (float)$l['debit'];
        $totalCredit += (float)$l['credit'];
    }
}
// Month of the voucher, so an account link opens the ledger on the right period.
$ledgerPeriod = $v ? '&from=' . date('Y-m-01', strtotime($v['voucher_date'])) . '&to=' . date('Y-m-t', strtotime($v['voucher_date'])) : '';

$pageTitle = $v ? $vt['label'] . ' ' . $v['voucher_no'] : 'Voucher';
$finActive = $type;
$finReturnPath = dirname(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) . '/' . $vt['list'];
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .v-meta dt { font-weight: 400; color: #6c757d; }
  .v-meta dd { margin-bottom: .35rem; }
  .v-hist { list-style: none; margin: 0; padding: 0; }
  .v-hist li { padding: .7rem 1rem; border-top: 1px solid #edf0f3; }
  .v-hist li:first-child { border-top: 0; }
  .v-hist .v-when { color: #6c757d; font-size: .85rem; }
  .v-hist table { font-size: .85rem; margin: .4rem 0 0; }
  .v-hist td.v-old { color: #b02a37; }
  .v-hist td.v-new { color: #146c43; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success fin-noprint"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger fin-noprint"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its vouchers.</div>
  <?php elseif (!$v): ?>
    <div class="alert alert-info">This voucher does not exist in <?= h($company['name']) ?>. <a href="<?= h(FIN_VOUCHER_TYPES['jv']['list']) ?>">Go to the voucher list</a>.</div>
  <?php else: ?>

  <div class="d-flex flex-wrap gap-2 mb-3 fin-noprint">
    <a href="<?= h($vt['list']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= h($vt['plural']) ?></a>
    <a href="<?= h($vt['new']) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-plus-lg"></i> New <?= h($vt['label']) ?></a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <?php if ($canEdit): ?>
    <a href="fin_voucher_edit.php?id=<?= (int)$v['id'] ?>" class="btn btn-sm btn-outline-primary ms-auto"><i class="bi bi-pencil"></i> Edit</a>
    <?php endif; ?>
    <?php if ($canDelete): ?>
    <form method="post" class="<?= $canEdit ? '' : 'ms-auto' ?>" onsubmit="return confirm('Delete <?= h($v['voucher_no']) ?>? Its amounts will stop counting in the trial balance.<?= $links['allocated'] > 0 ? ' Its payment links (' . number_format($links['allocated'], 2) . ') will be removed too.' : '' ?> This cannot be undone.');">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="delete">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($v['status'] === 'void'): ?>
    <div class="alert alert-secondary">This voucher was deleted<?= $v['voided_by_name'] ? ' by ' . h($v['voided_by_name']) : '' ?><?= $v['voided_at'] ? ' on ' . h(date('d M Y H:i', strtotime($v['voided_at']))) : '' ?>. Its lines are kept for the record and do not count in any balance.</div>
  <?php endif; ?>
  <div class="card mb-3">
    <div class="card-body">
      <dl class="row mb-0 v-meta">
        <dt class="col-sm-2">Voucher no.</dt><dd class="col-sm-4 fw-bold"><?= h($v['voucher_no']) ?></dd>
        <dt class="col-sm-2">Date</dt><dd class="col-sm-4"><?= h(date('d M Y', strtotime($v['voucher_date']))) ?></dd>
        <?php if ($vt['party'] !== ''): ?>
        <dt class="col-sm-2"><?= h($vt['party']) ?></dt><dd class="col-sm-4"><?= $v['party'] !== null ? h($v['party']) : '—' ?></dd>
        <?php endif; ?>
        <dt class="col-sm-2"><?= $type === 'si' ? 'Supplier invoice no.' : 'Reference' ?></dt><dd class="col-sm-4"><?= $v['reference'] !== null ? h($v['reference']) : '—' ?></dd>
        <dt class="col-sm-2"><?= $type === 'si' ? 'Description' : 'Narration' ?></dt><dd class="col-sm-10"><?= $v['narration'] !== null ? h($v['narration']) : '—' ?></dd>
        <?php if ($si): ?>
        <dt class="col-sm-2">Amount before VAT</dt><dd class="col-sm-4"><?= number_format((float)$si['net_amount'], 2) ?> AED</dd>
        <dt class="col-sm-2">VAT <?= h(rtrim(rtrim((string)$si['vat_rate'], '0'), '.')) ?>%</dt><dd class="col-sm-4"><?= number_format((float)$si['vat_amount'], 2) ?> AED</dd>
        <?php endif; ?>
        <dt class="col-sm-2"><?= $si ? 'Total' : 'Amount' ?></dt><dd class="col-sm-4 fw-bold"><?= number_format((float)$v['amount'], 2) ?> AED</dd>
        <dt class="col-sm-2">Status</dt><dd class="col-sm-4"><?= $v['status'] === 'void' ? '<span class="badge text-bg-secondary">Deleted</span>' : '<span class="badge text-bg-success">Posted</span>' ?></dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Account</th><th>Tenant / supplier</th><th>Description</th><th class="text-end">Debit (AED)</th><th class="text-end">Credit (AED)</th></tr></thead>
        <tbody>
        <?php foreach ($v['lines'] as $l): ?>
          <tr>
            <td><a href="fin_account_ledger.php?account_id=<?= (int)$l['account_id'] ?><?= h($ledgerPeriod) ?>"><?= h($l['code'] . ' ' . $l['name']) ?></a></td>
            <td><?= fin_partner_link($l, false) ?></td>
            <td><?= h($l['description']) ?></td>
            <td class="v-num"><?= (float)$l['debit'] > 0 ? number_format((float)$l['debit'], 2) : '' ?></td>
            <td class="v-num"><?= (float)$l['credit'] > 0 ? number_format((float)$l['credit'], 2) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="fw-bold"><td colspan="3">Total</td><td class="v-num"><?= number_format($totalDebit, 2) ?></td><td class="v-num"><?= number_format($totalCredit, 2) ?></td></tr>
        </tfoot>
      </table>
    </div>
  </div>

  <?php if ($autoFrom): ?>
  <div class="alert alert-info mt-3 fin-noprint">Monthly entry approved from <?= $autoFrom['source_type'] === 'asset' ? 'the depreciation of <a href="fin_asset_view.php?id=' . (int)$autoFrom['source_id'] . '">' . h($autoFrom['name']) . '</a>' : 'the allocation <a href="fin_allocation_view.php?id=' . (int)$autoFrom['source_id'] . '">' . h($autoFrom['name']) . '</a>' ?>. Deleting it sends the month back to Pending Approval.</div>
  <?php endif; ?>

  <?php if ($si && $v['status'] === 'posted' && fin_auto_ready($conn)): ?>
  <div class="card mt-3 fin-noprint">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
      <i class="bi bi-truck"></i>
      <?php if ($invoiceAssets): ?>
        Fixed asset<?= count($invoiceAssets) > 1 ? 's' : '' ?>:
        <?php foreach ($invoiceAssets as $ia): ?><a href="fin_asset_view.php?id=<?= (int)$ia['id'] ?>"><?= h($ia['name']) ?></a><?php endforeach; ?>
      <?php else: ?>
        <span class="text-muted">Not in the asset list.</span>
      <?php endif; ?>
      <a href="fin_asset_form.php?from_si=<?= (int)$v['id'] ?>" class="btn btn-sm btn-outline-primary ms-auto">Add as fixed asset</a>
      <a href="fin_allocation_form.php?from_si=<?= (int)$v['id'] ?>" class="btn btn-sm btn-outline-primary">Spread as prepaid expense</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($links['transfer'] && $v['status'] === 'posted'): ?>
  <div class="alert alert-info mt-3 fin-noprint">This journal moved a supplier advance onto an invoice. It is changed or removed in <a href="fin_supplier_allocations.php">Supplier Allocations</a>.</div>
  <?php endif; ?>

  <?php if ($si || $sp): ?>
  <div class="card mt-3">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
      <?php if ($si): ?>
        Payments
        <span class="ms-auto"><?= fin_sp_status_badge((float)$si['total_amount'], $links['allocated']) ?></span>
        <span class="text-muted small">Outstanding <strong><?= number_format((float)$si['total_amount'] - $links['allocated'], 2) ?></strong> AED</span>
      <?php else: ?>
        Paid as <strong><?= h(FIN_SP_MODES[$sp['mode']]) ?></strong>
        <?php $left = round((float)$sp['amount'] - $links['allocated'], 2); ?>
        <?php if ($sp['mode'] !== 'bills' && $v['status'] === 'posted'): ?>
        <span class="ms-auto text-muted small"><?= number_format($left, 2) ?> AED not linked yet</span>
        <?php if ($left > 0): ?><a class="btn btn-sm btn-outline-primary fin-noprint" href="fin_supplier_allocations.php?vendor=<?= (int)$sp['vendor_id'] ?>&payment=<?= (int)$v['id'] ?>#link">Link to invoices</a><?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th><?= $si ? 'Payment' : 'Invoice' ?></th><th>Date</th><?php if ($si): ?><th>Type</th><?php else: ?><th>Supplier inv. no.</th><?php endif; ?><th class="text-end">Amount (AED)</th><th>Journal</th></tr></thead>
        <tbody>
        <?php foreach ($allocs as $al): ?>
          <tr>
            <?php if ($si): ?>
            <td><a href="fin_voucher_view.php?id=<?= (int)$al['payment_voucher_id'] ?>"><?= h($al['payment_no']) ?></a></td>
            <td><?= h(date('d M Y', strtotime($al['payment_date']))) ?></td>
            <td><?= h(FIN_SP_MODES[$al['mode']]) ?></td>
            <?php else: ?>
            <td><a href="fin_voucher_view.php?id=<?= (int)$al['invoice_voucher_id'] ?>"><?= h($al['invoice_no']) ?></a></td>
            <td><?= h(date('d M Y', strtotime($al['invoice_date']))) ?></td>
            <td><?= h($al['supplier_invoice_no']) ?></td>
            <?php endif; ?>
            <td class="v-num"><?= number_format((float)$al['amount'], 2) ?></td>
            <td><?= $al['transfer_voucher_id'] ? '<a href="fin_voucher_view.php?id=' . (int)$al['transfer_voucher_id'] . '">' . h($al['transfer_no']) . '</a>' : '<span class="text-muted">—</span>' ?></td>
          </tr>
        <?php endforeach; if (!$allocs): ?>
          <tr><td colspan="5" class="text-muted text-center py-3"><?= $si ? 'Nothing paid against this invoice yet.' : 'Not linked to any invoice yet.' ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($si): ?>
  <div class="card mt-3 fin-noprint">
    <div class="card-header">Supporting documents</div>
    <ul class="list-group list-group-flush">
    <?php foreach ($files as $f): ?>
      <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-paperclip"></i>
        <a href="fin_attachment_file.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= h($f['file_name']) ?></a>
        <span class="text-muted small"><?= number_format((int)$f['file_size'] / 1024, 0) ?> KB · <?= h($f['uploaded_by_name'] ?? '') ?> · <?= h(date('d M Y', strtotime($f['uploaded_at']))) ?></span>
        <a href="fin_attachment_file.php?id=<?= (int)$f['id'] ?>&mode=download" class="ms-auto small"><i class="bi bi-download"></i> Download</a>
      </li>
    <?php endforeach; if (!$files): ?>
      <li class="list-group-item text-muted">No file attached.</li>
    <?php endif; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="card mt-3 fin-noprint">
    <div class="card-header">History</div>
    <ul class="v-hist">
    <?php foreach ($history as $hrow): ?>
      <li>
        <?= fin_voucher_action_badge($hrow['action']) ?>
        by <strong><?= h($hrow['user_name'] ?? 'Unknown user') ?></strong>
        <span class="v-when">on <?= h(date('d M Y, h:i A', strtotime($hrow['created_at']))) ?></span>
        <?php if ($hrow['changes']): ?>
        <table class="table table-sm table-borderless w-auto">
          <?php foreach ($hrow['changes'] as [$what, $old, $new]): ?>
          <tr><td class="text-muted pe-3"><?= h($what) ?></td><td class="v-old"><?= $old !== '' ? '<s>' . h($old) . '</s>' : '' ?></td><td class="px-2 text-muted"><?= $old !== '' && $new !== '' ? '→' : '' ?></td><td class="v-new"><?= h($new) ?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </li>
    <?php endforeach; if (!$history): ?>
      <li class="text-muted">No history recorded for this voucher.</li>
    <?php endif; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
