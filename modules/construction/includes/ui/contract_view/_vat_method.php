<?php
/** VAT collection method amendment — regenerates cheque plan + pending schedules when allowed. */
$vatOpts = co_shop_vat_collection_options();
$vatCurrent = co_shop_normalize_vat_collection($contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);
$vatElig = $vatMethodChange ?? ['allowed' => false, 'blockers' => [], 'current' => $vatCurrent];
?>
<div class="card card-round mb-3 no-print" id="vat-collection-method">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong class="shop-section-title">VAT Collection Method</strong>
        <span class="badge bg-light text-dark border"><?= h($vatOpts[$vatCurrent] ?? $vatCurrent) ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Changes how VAT is split across the <strong>cheque plan</strong> and <strong>earning schedule</strong>
            (BR-CO-SHOP-002). Allowed only while Draft/Active with no rent invoices, no cleared/linked rent cheques,
            and no posted revenue recognition. Deposit and commission settings are not changed.
        </p>
        <?php if (!empty($vatElig['allowed'])): ?>
        <form method="post" action="?id=<?= (int)$id ?>&amp;tab=finance" class="row g-3"
              onsubmit="return confirm('Change VAT collection method and rebuild cheque plan + pending rent schedule?');">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_vat_collection">
            <div class="col-md-6">
                <label class="form-label">New method</label>
                <select name="vat_collection_method" class="form-select" required>
                    <?php foreach ($vatOpts as $k => $label): ?>
                        <option value="<?= h($k) ?>" <?= $vatCurrent === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Amendment reason</label>
                <input type="text" name="amendment_reason" class="form-control" required
                       placeholder="e.g. Tenant will pay VAT on a separate cheque">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm">Apply VAT Method &amp; Rebuild Plans</button>
            </div>
        </form>
        <?php else: ?>
        <div class="alert alert-warning py-2 mb-0">
            <strong>Change locked.</strong>
            <?php if (!empty($vatElig['blockers'])): ?>
                <ul class="mb-0 small mt-1">
                    <?php foreach ($vatElig['blockers'] as $b): ?>
                        <li><?= h($b) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <span class="small">VAT method cannot be changed for this contract right now.</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
