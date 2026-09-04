<?php
/**
 * Key Money financial status — commission-parity visibility.
 * Expects: $chargesReady, $keyMoneyStatus, $kmInv, $contract, $id
 */
if (!$chargesReady) {
    return;
}
$kms = $keyMoneyStatus ?? null;
if (!$kms) {
    return;
}
$kmInv = $kms['invoice'] ?? ($kmInv ?? null);
$kmCharge = $kms['charge'] ?? null;
$kmLocked = !empty($kmInv) || (($kmCharge['status'] ?? '') === 'invoiced');
$timing = (string)($kms['timing'] ?? 'immediate');
?>
<div class="card card-round mb-3" id="key-money-section">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong class="shop-section-title">Key Money</strong>
        <span class="badge bg-<?= ($kms['status'] ?? '') === 'collected' ? 'success' : (($kms['status'] ?? '') === 'partial' || ($kms['status'] ?? '') === 'invoiced' ? 'warning text-dark' : 'secondary') ?>">
            <?= h(ucfirst(str_replace('_', ' ', $kms['status'] ?? 'n/a'))) ?>
        </span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            One-time tenant revenue (not deposit / deferred). Posts to income <code>4170</code>
            (+ Output VAT <code>2310</code> when VAT applies). Paid via Payment Workspace.
            Timing: <strong><?= $timing === 'on_start' ? 'On Contract Start Date' : 'Generate Immediately' ?></strong>.
        </p>
        <div class="row g-3 mb-2">
            <div class="col-6 col-md-2"><div class="text-muted small">Net</div><div class="fw-semibold"><?= co_format_money($kms['net'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">VAT</div><div class="fw-semibold"><?= co_format_money($kms['vat'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Invoiced</div><div class="fw-semibold"><?= co_format_money($kms['invoiced'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Paid / Collected</div><div class="fw-semibold"><?= co_format_money($kms['collected'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Outstanding</div><div class="fw-semibold"><?= co_format_money($kms['outstanding'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2">
                <div class="text-muted small">Invoice Status</div>
                <div class="fw-semibold"><?= h(ucfirst(str_replace('_', ' ', $kms['status'] ?? 'n/a'))) ?></div>
                <div class="small">
                    <?php if ($kmInv): ?>
                        <a target="_blank" href="client_invoice_pdf.php?id=<?= (int)$kmInv['id'] ?>"><?= h($kmInv['invoice_number']) ?></a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (!$kmLocked && !empty($kms['enabled']) && empty($kmInv) && !empty($kmCharge)): ?>
            <form method="post" class="row g-2 align-items-end mt-2 border-top pt-3 no-print">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="generate_charge_invoice">
                <input type="hidden" name="charge_id" value="<?= (int)$kmCharge['id'] ?>">
                <div class="col-md-3">
                    <label class="form-label">Invoice Date</label>
                    <input type="date" name="key_money_invoice_date" class="form-control form-control-sm" value="<?= h($timing === 'on_start' && !empty($contract['start_date']) ? $contract['start_date'] : date('Y-m-d')) ?>">
                </div>
                <div class="col-md-5">
                    <button class="btn btn-success btn-sm" type="submit">Generate Key Money Invoice</button>
                    <div class="form-text">Creates AR invoice + posts Dr 1310 / Cr 4170 (+ 2310 VAT when applicable).</div>
                </div>
            </form>
        <?php elseif (!$kmLocked && empty($kms['enabled'])): ?>
            <p class="small text-muted mb-0">Set a Key Money amount on the Charges tab (or at contract create) to enable invoicing.</p>
        <?php endif; ?>
    </div>
</div>
