<?php if ($summary):
    $listInvoices = $summary['recent_invoices'] ?? [];
    $openRentVat = $summary['open_invoices'] ?? [];
    $unclearedCheques = array_values(array_filter($cheques ?? [], static function ($c) {
        return ($c['cheque_type'] ?? '') !== 'security_deposit'
            && !in_array($c['status'] ?? '', ['allocated', 'cancelled', 'replaced'], true)
            && empty($c['payment_id']);
    }));
    $openPayInvoices = [];
    foreach ($openRentVat as $inv) {
        $openPayInvoices[] = $inv;
    }
    if ($cInv ?? null) {
        $cBal = (float)($cInv['balance'] ?? 0);
        if ($cBal > 0.005) {
            $cInv['kind'] = 'commission';
            $cInv['kind_label'] = 'Commission';
            $cInv['balance'] = $cBal;
            $openPayInvoices[] = $cInv;
        }
    }
?>
<div class="card card-round mb-4" id="payment-manager">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <strong class="shop-section-title">Payment Manager</strong>
            <span class="text-muted small">— rent, VAT, commission · receipts · cheque allocation</span>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="client_invoices.php?client_id=<?= (int)$contract['client_id'] ?>&amp;source_type=shop_rental">All shop invoices →</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-6">
                <h6 class="text-muted">Open Invoices (allocate these)</h6>
                <div class="table-responsive" style="max-height:22rem;overflow:auto">
                    <table class="table table-sm mb-0"><thead><tr><th>Type</th><th>Invoice</th><th>Due</th><th class="text-end">Balance</th><th></th></tr></thead><tbody>
                    <?php foreach ($openPayInvoices as $inv): ?>
                        <tr>
                            <td><span class="badge bg-<?= ($inv['kind'] ?? '') === 'vat' ? 'warning text-dark' : (($inv['kind'] ?? '') === 'commission' ? 'info text-dark' : (($inv['kind'] ?? '') === 'key_money' ? 'primary' : 'secondary')) ?>"><?= h($inv['kind_label'] ?? 'Rent') ?></span></td>
                            <td><?= h($inv['invoice_number']) ?></td>
                            <td><?= h($inv['due_date'] ?? '') ?></td>
                            <td class="text-end"><?= co_format_money($inv['balance']) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" target="_blank" href="client_invoice_pdf.php?id=<?= (int)$inv['id'] ?>">PDF</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$openPayInvoices): ?><tr><td colspan="5" class="text-muted text-center">No open balances.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
                <h6 class="text-muted mt-3">Recent invoices (incl. paid)</h6>
                <div class="table-responsive" style="max-height:12rem;overflow:auto">
                    <table class="table table-sm mb-0"><thead><tr><th>Type</th><th>Invoice</th><th class="text-end">Total</th><th class="text-end">Balance</th></tr></thead><tbody>
                    <?php foreach ($listInvoices as $inv): ?>
                        <tr>
                            <td><span class="badge bg-<?= ($inv['kind'] ?? '') === 'vat' ? 'warning text-dark' : 'secondary' ?>"><?= h($inv['kind_label'] ?? 'Rent') ?></span></td>
                            <td><?= h($inv['invoice_number']) ?></td>
                            <td class="text-end"><?= co_format_money($inv['total_amount']) ?></td>
                            <td class="text-end"><?= co_format_money($inv['balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$listInvoices): ?><tr><td colspan="4" class="text-muted text-center">No rent/VAT invoices yet.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
                <?php if ($cInv ?? null): ?>
                <h6 class="text-muted mt-3">Commission Invoice</h6>
                <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Invoice</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Balance</th><th></th></tr></thead><tbody>
                    <tr>
                        <td><?= h($cInv['invoice_number']) ?></td>
                        <td><?= h($cInv['due_date']) ?></td>
                        <td class="text-end"><?= co_format_money($cInv['total_amount']) ?></td>
                        <td class="text-end"><?= co_format_money($cInv['balance'] ?? 0) ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" target="_blank" href="client_invoice_pdf.php?id=<?= (int)$cInv['id'] ?>">PDF</a></td>
                    </tr>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <div class="col-lg-6">
                <h6 class="text-muted">Recent Receipts</h6>
                <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Invoice</th><th class="text-end">Amount</th><th>Ref</th><th></th></tr></thead><tbody>
                <?php foreach ($summary['recent_receipts'] as $rcp): ?>
                    <tr>
                        <td><?= h($rcp['payment_date']) ?></td>
                        <td><?= h($rcp['invoice_number'] ?: 'Multi / Workspace') ?></td>
                        <td class="text-end"><?= co_format_money($rcp['amount']) ?></td>
                        <td><?= h($rcp['reference'] ?? '') ?></td>
                        <td class="text-end">
                            <?php if (!empty($rcp['id'])): ?>
                                <a class="btn btn-sm btn-outline-primary" href="client_payment_receipt.php?id=<?= (int)$rcp['id'] ?>">Receipt</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$summary['recent_receipts']): ?><tr><td colspan="5" class="text-muted text-center">No receipts yet.</td></tr><?php endif; ?>
                </tbody></table></div>

                <?php if ($unclearedCheques && $openPayInvoices): ?>
                <div class="mt-3 border-top pt-3 no-print">
                    <strong class="small" style="color:var(--co-gold-soft)">Allocate from cheques (Payment Workspace)</strong>
                    <p class="form-text mb-2">
                        Cheques do not settle invoices. Mark bank deposited/cleared on the Cheques tab, then allocate via Workspace.
                        Confirm posts one receipt; selected cheque(s) become <strong>Allocated</strong>.
                    </p>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <a class="btn btn-sm btn-success" href="shop_rental_payment_workspace.php?contract_id=<?= (int)$id ?>">Receive Payment</a>
                        <a class="btn btn-sm btn-outline-primary" href="?id=<?= (int)$id ?>&amp;tab=cheques">Cheques → Allocate Payment</a>
                    </div>
                    <div class="table-responsive" style="max-height:12rem;overflow:auto">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Cheque</th><th>Date</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($unclearedCheques as $ch):
                                if (in_array($ch['status'] ?? '', ['allocated', 'cancelled', 'replaced'], true)) {
                                    continue;
                                }
                                $chNum = trim((string)($ch['cheque_number'] ?? ''));
                                $allocHref = 'shop_rental_payment_workspace.php?contract_id=' . (int)$id . '&cheque_id=' . (int)$ch['id'];
                            ?>
                                <tr>
                                    <td><?= $chNum !== '' ? h($chNum) : ('#' . (int)$ch['id']) ?></td>
                                    <td><?= h($ch['cheque_date'] ?? '') ?></td>
                                    <td class="text-end"><?= co_format_money($ch['amount']) ?></td>
                                    <td><?= h($ch['status'] ?? '') ?></td>
                                    <td class="text-end"><a class="btn btn-sm btn-outline-success" href="<?= h($allocHref) ?>">Allocate</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($openPayInvoices): ?>
                <form method="post" action="?id=<?= (int)$id ?>&amp;tab=finance#payment-manager" class="row g-2 align-items-end mt-3 border-top pt-3 no-print" id="pmReceiptForm"><?php csrf_field(); ?>
                    <input type="hidden" name="action" value="record_receipt">
                    <div class="col-12"><strong class="small">Cash / transfer receipt (single invoice)</strong></div>
                    <div class="col-md-5"><label class="form-label">Open Invoice</label>
                        <select name="invoice_id" id="pmInvoiceSelect" class="form-select form-select-sm" required>
                            <?php foreach ($openPayInvoices as $inv): ?>
                                <option value="<?= (int)$inv['id'] ?>" data-balance="<?= h(number_format((float)$inv['balance'], 2, '.', '')) ?>">
                                    [<?= h($inv['kind_label'] ?? 'Rent') ?>] <?= h($inv['invoice_number']) ?> (<?= co_format_money($inv['balance']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="form-label">Amount</label><input type="number" step="0.01" name="amount" id="pmAmount" class="form-control form-control-sm" required></div>
                    <div class="col-md-2"><label class="form-label">Date</label><input type="date" name="payment_date" class="form-control form-control-sm" value="<?= h(date('Y-m-d')) ?>"></div>
                    <div class="col-md-3"><label class="form-label">Received To</label><select name="pay_account_id" class="form-select form-select-sm" required><option value="">Select</option><?php foreach ($paymentAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['account_code'] . ' - ' . $a['account_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><input type="text" name="reference" class="form-control form-control-sm" placeholder="Reference (optional)"></div>
                    <div class="col-12"><button class="btn btn-sm btn-primary">Record Receipt</button></div>
                </form>
                <?php endif; ?>
                <?php if (($summary['client_credit'] ?? 0) > 0.005 && $openPayInvoices): ?>
                <form method="post" action="?id=<?= (int)$id ?>&amp;tab=finance#payment-manager" class="row g-2 align-items-end mt-2 no-print"><?php csrf_field(); ?>
                    <input type="hidden" name="action" value="apply_credit">
                    <div class="col-md-5"><label class="form-label">Apply Credit To</label>
                        <select name="invoice_id" class="form-select form-select-sm" required>
                            <?php foreach ($openPayInvoices as $inv): ?>
                                <option value="<?= (int)$inv['id'] ?>"><?= h($inv['invoice_number']) ?> (<?= co_format_money($inv['balance']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Amount (max <?= co_format_money($summary['client_credit']) ?>)</label><input type="number" step="0.01" name="amount" class="form-control form-control-sm" value="<?= h(number_format((float)$summary['client_credit'], 2, '.', '')) ?>" required></div>
                    <div class="col-md-3"><button class="btn btn-sm btn-outline-success w-100">Apply Credit</button></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
  function fillFromSelect(sel, input, attr){
    if(!sel||!input) return;
    function sync(){
      var opt=sel.options[sel.selectedIndex];
      if(!opt) return;
      var v=opt.getAttribute(attr);
      if(v) input.value=v;
    }
    sel.addEventListener('change', sync);
    sync();
  }
  fillFromSelect(document.getElementById('pmInvoiceSelect'), document.getElementById('pmAmount'), 'data-balance');
})();
</script>
<?php endif; ?>
