<?php
/**
 * Finance > Supplier Allocations: link a supplier's On Account payments and
 * Advances to its invoices (?vendor=, and ?payment= for the one being linked),
 * and take a link off again. Linking an advance posts a journal moving it from
 * the advance account to the supplier payable, dated on the later of the two
 * dates; the payment and the invoice keep their own dates.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_supplier_payment_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_sp_ready($conn);
$vendorId = (int)($_GET['vendor'] ?? 0);
$paymentId = (int)($_GET['payment'] ?? 0);
$msg = $_GET['msg'] ?? '';
$err = '';
$typed = [];

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'allocate') {
            $typed = array_map('strval', (array)($_POST['alloc'] ?? []));
            $n = fin_sp_allocate($conn, $companyId, $paymentId, $typed, $userId);
            $done = $n === 1 ? 'Linked to 1 invoice.' : "Linked to {$n} invoices.";
            header('Location: fin_supplier_allocations.php?vendor=' . $vendorId . '&msg=' . urlencode($done));
            exit;
        }
        if ($action === 'remove') {
            fin_sp_unallocate($conn, $companyId, (int)($_POST['allocation_id'] ?? 0), $userId);
            header('Location: fin_supplier_allocations.php?vendor=' . $vendorId . '&msg=' . urlencode('Link removed. The invoice is outstanding again.'));
            exit;
        }
        throw new InvalidArgumentException('Unknown action.');
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_supplier_allocations: ' . $e->getMessage());
        $err = 'The allocation could not be saved.';
    }
}

$ready = $tableOk && $company;
$suppliers = $ready ? fin_si_suppliers($conn, $companyId) : [];
if (!isset($suppliers[$vendorId])) {
    $vendorId = 0;
}
$open = ($ready && $vendorId) ? fin_sp_invoices($conn, $companyId, $vendorId) : [];
$payments = ($ready && $vendorId) ? fin_sp_unallocated($conn, $companyId, $vendorId) : [];
$links = ($ready && $vendorId) ? fin_sp_vendor_allocations($conn, $companyId, $vendorId) : [];
$current = null;
foreach ($payments as $p) {
    if ((int)$p['id'] === $paymentId) {
        $current = $p;
    }
}
// Suppliers with something to link, so the list can say so.
$waiting = [];
foreach ($ready ? fin_sp_unallocated($conn, $companyId) : [] as $p) {
    $waiting[(int)$p['vendor_id']] = true;
}

$pageTitle = 'Supplier Allocations';
$finActive = 'alloc';
$pageStyles = '
  .v-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .v-pay { width: 150px; text-align: right; margin-left: auto; }
  tr.v-current td { background: #eef6ff; }';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_supplier_invoices.sql and fin_supplier_payments.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company.</div>
  <?php else: ?>

  <form method="get" class="card mb-3">
    <div class="card-body row g-2 align-items-end">
      <div class="col-md-6 col-lg-4">
        <label class="form-label small mb-1" for="aVendor">Supplier</label>
        <select class="form-select" id="aVendor" name="vendor" onchange="this.form.submit()">
          <option value="">Choose a supplier</option>
          <?php foreach ($suppliers as $sid => $sname): ?>
            <option value="<?= (int)$sid ?>" <?= (int)$sid === $vendorId ? 'selected' : '' ?>><?= h($sname . (isset($waiting[(int)$sid]) ? '  •  payments to link' : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto text-muted small">On Account payments and Advances not yet linked show "payments to link".</div>
    </div>
  </form>

  <?php if ($vendorId): ?>
  <div class="card mb-3">
    <div class="card-header">Payments not yet linked</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Payment</th><th>Date</th><th>Type</th><th>Reference</th><th class="text-end">Amount</th><th class="text-end">Left to link</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr class="<?= (int)$p['id'] === $paymentId ? 'v-current' : '' ?>">
            <td><a href="fin_voucher_view.php?id=<?= (int)$p['id'] ?>"><?= h($p['voucher_no']) ?></a></td>
            <td><?= h(date('d M Y', strtotime($p['voucher_date']))) ?></td>
            <td><?= h(FIN_SP_MODES[$p['mode']]) ?></td>
            <td><?= h($p['reference']) ?></td>
            <td class="v-num"><?= number_format((float)$p['amount'], 2) ?></td>
            <td class="v-num fw-bold"><?= number_format($p['remaining'], 2) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?vendor=<?= $vendorId ?>&payment=<?= (int)$p['id'] ?>#link">Link to invoices</a></td>
          </tr>
        <?php endforeach; if (!$payments): ?>
          <tr><td colspan="7" class="text-muted text-center py-3">Nothing to link for this supplier.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($current): ?>
  <form method="post" action="?vendor=<?= $vendorId ?>&payment=<?= (int)$current['id'] ?>" class="card mb-3" id="link">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="allocate">
    <div class="card-header">
      Link <strong><?= h($current['voucher_no']) ?></strong> (<?= h(FIN_SP_MODES[$current['mode']]) ?>, <?= number_format($current['remaining'], 2) ?> left) to open invoices
    </div>
    <?php if ($current['mode'] === 'advance'): ?>
    <div class="alert alert-info rounded-0 border-0 mb-0 small">
      The advance moves to the supplier's payable with a journal dated on the invoice date (or the payment date, if later). The payment and the invoice keep their own dates.
    </div>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Invoice</th><th>Supplier inv. no.</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Outstanding</th><th class="text-end">Link (AED)</th></tr></thead>
        <tbody>
        <?php foreach ($open as $inv): ?>
          <tr>
            <td><a href="fin_voucher_view.php?id=<?= (int)$inv['id'] ?>"><?= h($inv['voucher_no']) ?></a></td>
            <td><?= h($inv['invoice_no']) ?></td>
            <td><?= h(date('d M Y', strtotime($inv['voucher_date']))) ?></td>
            <td class="v-num"><?= number_format((float)$inv['total_amount'], 2) ?></td>
            <td class="v-num"><?= number_format($inv['outstanding'], 2) ?></td>
            <td><input class="form-control form-control-sm v-pay" name="alloc[<?= (int)$inv['id'] ?>]" inputmode="decimal" placeholder="0.00" value="<?= h($typed[(int)$inv['id']] ?? '') ?>"></td>
          </tr>
        <?php endforeach; if (!$open): ?>
          <tr><td colspan="6" class="text-muted text-center py-3">This supplier has no open invoices. Book the invoice first.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($open): ?>
    <div class="card-body d-flex gap-2">
      <button class="btn btn-primary">Save links</button>
      <a href="?vendor=<?= $vendorId ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
    <?php endif; ?>
  </form>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">Links made</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Payment</th><th>Invoice</th><th class="text-end">Amount</th><th>Journal</th><th>By</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($links as $l): ?>
          <tr>
            <td><a href="fin_voucher_view.php?id=<?= (int)$l['payment_voucher_id'] ?>"><?= h($l['payment_no']) ?></a> <span class="text-muted small"><?= h(FIN_SP_MODES[$l['mode']]) ?> · <?= h(date('d M Y', strtotime($l['payment_date']))) ?></span></td>
            <td><a href="fin_voucher_view.php?id=<?= (int)$l['invoice_voucher_id'] ?>"><?= h($l['invoice_no']) ?></a> <span class="text-muted small"><?= h($l['supplier_invoice_no']) ?> · <?= h(date('d M Y', strtotime($l['invoice_date']))) ?></span></td>
            <td class="v-num"><?= number_format((float)$l['amount'], 2) ?></td>
            <td><?= $l['transfer_voucher_id'] ? '<a href="fin_voucher_view.php?id=' . (int)$l['transfer_voucher_id'] . '">' . h($l['transfer_no']) . '</a>' : '<span class="text-muted">—</span>' ?></td>
            <td class="small"><?= h($l['created_by_name'] ?? '') ?><div class="text-muted"><?= h(date('d M Y', strtotime($l['created_at']))) ?></div></td>
            <td class="text-end">
              <form method="post" action="?vendor=<?= $vendorId ?>" onsubmit="return confirm('Remove this link? The invoice will be outstanding again<?= $l['transfer_voucher_id'] ? ' and the journal ' . h($l['transfer_no']) . ' will be deleted' : '' ?>.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="allocation_id" value="<?= (int)$l['id'] ?>">
                <button class="btn btn-sm btn-link text-danger p-0">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (!$links): ?>
          <tr><td colspan="6" class="text-muted text-center py-3">No links yet. Payments made Against Bills are linked when they are made and show on the invoice.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
