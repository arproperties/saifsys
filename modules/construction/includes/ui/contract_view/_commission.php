<?php if ($commissionReady): ?>
<?php
$ca = $commissionStatus['amounts'] ?? co_shop_commission_amounts($contract);
$commLocked = !empty($ca['invoice_id']) || !empty($cInv);
?>
<div class="card card-round mb-3" id="commission-section">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong class="shop-section-title">Tenant Commission</strong>
        <span class="badge bg-<?= ($commissionStatus['status'] ?? '') === 'collected' ? 'success' : (($commissionStatus['status'] ?? '') === 'partial' || ($commissionStatus['status'] ?? '') === 'invoiced' ? 'warning text-dark' : 'secondary') ?>">
            <?= h(ucfirst(str_replace('_', ' ', $commissionStatus['status'] ?? 'n/a'))) ?>
        </span>
    </div>
    <div class="card-body">
        <p class="text-muted small">Charged by Madar Al Wadi to the tenant. Default 5% of total net contract rent. Contract-level (not per shop). Posts to income <code>4140</code> and Output VAT <code>2310</code> when invoiced.</p>
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-2"><div class="text-muted small">Net</div><div class="fw-semibold"><?= co_format_money($commissionStatus['net'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">VAT</div><div class="fw-semibold"><?= co_format_money($commissionStatus['vat'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Invoiced</div><div class="fw-semibold"><?= co_format_money($commissionStatus['invoiced'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Collected</div><div class="fw-semibold"><?= co_format_money($commissionStatus['collected'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Outstanding</div><div class="fw-semibold"><?= co_format_money($commissionStatus['outstanding'] ?? 0) ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Invoice</div><div class="fw-semibold"><?php if ($cInv): ?><a target="_blank" href="client_invoice_pdf.php?id=<?= (int)$cInv['id'] ?>"><?= h($cInv['invoice_number']) ?></a><?php else: ?>—<?php endif; ?></div></div>
        </div>
        <form method="post" class="row g-3 align-items-end no-print"><?php csrf_field(); ?><input type="hidden" name="action" value="save_commission">
            <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="commission_enabled" value="1" id="commEnabled" <?= !empty($ca['enabled']) || !isset($contract['commission_enabled']) ? 'checked' : '' ?> <?= $commLocked ? 'disabled' : '' ?>><label class="form-check-label" for="commEnabled">Enabled</label></div></div>
            <div class="col-md-2"><label class="form-label">Basis</label><select name="commission_basis" class="form-select form-select-sm" <?= $commLocked ? 'disabled' : '' ?>><option value="percent" <?= ($ca['basis'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percent</option><option value="fixed" <?= ($ca['basis'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option></select></div>
            <div class="col-md-2"><label class="form-label">Percent %</label><input type="number" step="0.0001" name="commission_percent" class="form-control form-control-sm" value="<?= h($ca['percent'] ?? 5) ?>" <?= $commLocked ? 'readonly' : '' ?>></div>
            <div class="col-md-2"><label class="form-label">Fixed Amount</label><input type="number" step="0.01" name="commission_fixed_amount" class="form-control form-control-sm" value="<?= h($contract['commission_fixed_amount'] ?? 0) ?>" <?= $commLocked ? 'readonly' : '' ?>></div>
            <div class="col-md-2"><label class="form-label">Net Amount</label><input type="number" step="0.01" name="commission_net_amount" class="form-control form-control-sm" value="<?= h($ca['net'] ?? 0) ?>" <?= $commLocked ? 'readonly' : '' ?>></div>
            <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="commission_manual_override" value="1" id="commManual" <?= !empty($ca['manual_override']) ? 'checked' : '' ?> <?= $commLocked ? 'disabled' : '' ?>><label class="form-check-label" for="commManual">Manual override</label></div></div>
            <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="commission_vat_enabled" value="1" id="commVat" <?= !empty($ca['vat_enabled']) ? 'checked' : '' ?> <?= $commLocked ? 'disabled' : '' ?>><label class="form-check-label" for="commVat">VAT on commission</label></div></div>
            <div class="col-md-2"><label class="form-label">VAT %</label><input type="number" step="0.01" name="commission_vat_rate" class="form-control form-control-sm" value="<?= h($ca['vat_rate'] ?? 5) ?>" <?= $commLocked ? 'readonly' : '' ?>></div>
            <div class="col-md-3"><button class="btn btn-outline-primary btn-sm" <?= $commLocked ? 'disabled' : '' ?>>Save Commission</button></div>
        </form>
        <?php if (!$commLocked && !empty($ca['enabled']) && ($ca['net'] ?? 0) > 0): ?>
        <form method="post" class="row g-2 align-items-end mt-3 border-top pt-3 no-print"><?php csrf_field(); ?>
            <input type="hidden" name="action" value="generate_commission_invoice">
            <div class="col-md-3"><label class="form-label">Invoice Date</label><input type="date" name="commission_invoice_date" class="form-control form-control-sm" value="<?= h(date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><button class="btn btn-success btn-sm">Generate Commission Invoice</button><div class="form-text">Creates AR invoice + posts Dr 1310 / Cr 4140 (+ 2310 VAT).</div></div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($phase1Ready): ?>
<div class="alert alert-info no-print">Run <code>migrations/construction_shop_rental_phase16_commission.sql</code> to enable tenant commission.</div>
<?php endif; ?>
