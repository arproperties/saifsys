<?php
/**
 * Rent Concession (Free Rent) — operational settings.
 * Expects: $concessionReady, $concessionStatus, $concessionLocked, $contract, $id
 */
if (empty($concessionReady)) {
    if (!empty($phase1Ready)) {
        echo '<div class="alert alert-info no-print">Run <code>migrations/construction_shop_rental_phase_rent_concession.sql</code> to enable Rent Concession.</div>';
    }
    return;
}
$cs = $concessionStatus ?? co_shop_concession_status($contract);
$locked = !empty($concessionLocked);
?>
<div class="card card-round mb-3" id="concession-section">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong class="shop-section-title">Rent Concession</strong>
        <span class="badge bg-<?= !empty($cs['enabled']) ? 'warning text-dark' : 'secondary' ?>">
            <?= !empty($cs['enabled']) ? 'Active' : 'Off' ?>
        </span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Occupancy remains the legal lease (<code>start_date</code> → <code>end_date</code>).
            Chargeable months generate rent schedules/invoices; concession months are skipped (no AED&nbsp;0 invoices).
            Deposit, Key Money, commission, cheques, and Payment Workspace are unchanged.
        </p>
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-2"><div class="text-muted small">Occupancy</div><div class="fw-semibold small"><?= h($cs['occupancy_from'] ?? '') ?> → <?= h($cs['occupancy_to'] ?? '') ?></div><div class="small text-muted"><?= (int)($cs['occupancy_months'] ?? 0) ?> mo</div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Chargeable</div><div class="fw-semibold small"><?= h($cs['chargeable_from'] ?? '') ?> → <?= h($cs['chargeable_to'] ?? '') ?></div><div class="small text-muted"><?= (int)($cs['chargeable_months'] ?? 0) ?> mo<?= !empty($cs['chargeable_has_gap']) ? ' · gap' : '' ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Concession</div><div class="fw-semibold small"><?= !empty($cs['enabled']) ? (h($cs['from']) . ' → ' . h($cs['to'])) : '—' ?></div><div class="small text-muted"><?= (int)($cs['concession_months'] ?? 0) ?> free mo</div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Reason</div><div class="fw-semibold"><?= h($cs['reason_label'] ?? '—') ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Concession Value</div><div class="fw-semibold"><?= co_format_money($cs['value_total'] ?? 0) ?></div><div class="small text-muted"><?= !empty($cs['value_stored']) ? 'Stored' : 'Estimate' ?></div></div>
            <div class="col-6 col-md-2"><div class="text-muted small">Monthly Equiv.</div><div class="fw-semibold"><?= co_format_money(co_shop_monthly_equivalent_net($contract)) ?></div><div class="small text-muted">Rent ÷ chargeable</div></div>
        </div>
        <?php if ($locked): ?>
            <div class="alert alert-secondary py-2 mb-0 small">Locked — rent invoices already posted. Cancel/void rent invoices before changing concession.</div>
        <?php else: ?>
        <form method="post" class="row g-3 align-items-end no-print">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save_concession">
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="concession_enabled" value="1" id="cvConcEnabled" <?= !empty($cs['enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cvConcEnabled">Enabled</label>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label">Reason</label>
                <select name="concession_reason" class="form-select form-select-sm">
                    <?php foreach (CO_SHOP_CONCESSION_REASONS as $rk => $rl): ?>
                        <option value="<?= h($rk) ?>" <?= ($cs['reason'] ?? 'fit_out') === $rk ? 'selected' : '' ?>><?= h($rl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Position</label>
                <select name="concession_position" class="form-select form-select-sm">
                    <?php foreach (CO_SHOP_CONCESSION_POSITIONS as $pk => $pl): ?>
                        <option value="<?= h($pk) ?>" <?= ($cs['position'] ?? 'beginning') === $pk ? 'selected' : '' ?>><?= h($pl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Duration</label>
                <input type="number" step="0.01" min="0" name="concession_duration_value" class="form-control form-control-sm" value="<?= h($cs['duration_value'] ?? '1') ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">Unit</label>
                <select name="concession_duration_unit" class="form-select form-select-sm">
                    <option value="months" <?= ($cs['duration_unit'] ?? 'months') === 'months' ? 'selected' : '' ?>>Mo</option>
                    <option value="days" <?= ($cs['duration_unit'] ?? '') === 'days' ? 'selected' : '' ?>>Days</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="concession_from" class="form-control form-control-sm" value="<?= h($cs['from'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="concession_to" class="form-control form-control-sm" value="<?= h($cs['to'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="concession_manual_dates" value="1" id="cvConcManual" <?= (($cs['position'] ?? '') === 'custom') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cvConcManual">Use From/To as entered</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Notes</label>
                <input type="text" name="concession_notes" class="form-control form-control-sm" maxlength="500" value="<?= h($cs['notes'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-primary btn-sm" type="submit">Save Concession</button>
                <div class="form-text">Refreshes pending rent schedules after save.</div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
