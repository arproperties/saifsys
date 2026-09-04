<?php
/**
 * Contract Charges tab — Payment Maturity Phase 1 + Key Money.
 * Expects: $chargesReady, $contractCharges, $id, $contract, $cid, $keyMoneyStatus (optional)
 */
if (!function_exists('co_format_money')) {
    function co_format_money($n) { return 'AED ' . number_format((float)$n, 2); }
}
$hasTimingCol = $chargesReady && co_db_column_exists($conn, 'co_shop_contract_charges', 'invoice_timing');
?>
<div class="card card-round mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Contract Charges</strong>
        <?php if (!$chargesReady): ?>
            <span class="badge text-bg-warning">Run migrations/construction_shop_rental_phase_charges.sql</span>
        <?php else: ?>
            <span class="small text-muted">System charges sync with rent / deposit / commission. Key Money is one-time AR revenue (income <code>4170</code>).</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!$chargesReady): ?>
            <p class="text-muted mb-0">Charge catalogue is not available until the migration is applied.</p>
        <?php elseif (!$contractCharges): ?>
            <p class="text-muted mb-0">No charges synced yet. Reload this page after migration.</p>
        <?php else: ?>
            <form method="post" id="coChargesSaveForm" class="mb-3">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save_charges">
            </form>
            <p class="small text-muted mb-2">
                Saving updates charge lines and syncs rent / deposit / commission onto the contract.
                <strong>Posted invoices are never rewritten</strong> — change amounts only before invoicing, or cancel/void then re-invoice (same as Commission tab).
                VAT: Exclusive / Inclusive / None (None = Exempt, Zero Rated, or Out of Scope for calculation).
            </p>
            <div class="table-responsive mb-2">
                <table class="table table-sm align-middle mb-2">
                    <thead class="table-light">
                        <tr>
                            <th>Charge</th>
                            <th>Nature</th>
                            <th class="text-end">Net amount</th>
                            <th>VAT</th>
                            <th class="text-end">VAT amt</th>
                            <th class="text-end">Gross</th>
                            <th>GL</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contractCharges as $ch): ?>
                        <?php
                        $code = (string)($ch['charge_code'] ?? '');
                        $isKey = $code === 'key_money';
                        $isDeposit = $code === 'security_deposit';
                        $isInvoiced = ($ch['status'] ?? '') === 'invoiced';
                        $locked = $isInvoiced;
                        $timing = (string)($ch['invoice_timing'] ?? 'immediate');
                        $canInvoice = !empty($ch['invoicing_enabled'])
                            && ($ch['nature'] ?? '') === 'income'
                            && $code !== 'rent'
                            && ($ch['status'] ?? '') === 'active'
                            && (float)$ch['amount'] > 0;
                        if ($isKey && $timing === 'on_start') {
                            $start = (string)($contract['start_date'] ?? '');
                            if ($start !== '' && $start > date('Y-m-d')) {
                                $canInvoice = false;
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <input form="coChargesSaveForm" type="hidden" name="charge_id[]" value="<?= (int)$ch['id'] ?>">
                                <div class="fw-semibold"><?= h($ch['charge_name']) ?></div>
                                <div class="small text-muted"><code><?= h($code) ?></code><?= !empty($ch['is_system']) ? ' · system' : ' · custom' ?></div>
                                <?php if ($isKey && $hasTimingCol && !$isInvoiced): ?>
                                    <label class="small text-muted mb-0 mt-1 d-block">Invoice timing</label>
                                    <select form="coChargesSaveForm" name="charge_invoice_timing[]" class="form-select form-select-sm">
                                        <option value="immediate" <?= $timing === 'immediate' ? 'selected' : '' ?>>Generate Immediately</option>
                                        <option value="on_start" <?= $timing === 'on_start' ? 'selected' : '' ?>>On Contract Start Date</option>
                                    </select>
                                <?php elseif ($isKey): ?>
                                    <input form="coChargesSaveForm" type="hidden" name="charge_invoice_timing[]" value="<?= h($timing) ?>">
                                    <div class="small text-muted">Timing: <?= h($timing === 'on_start' ? 'On Contract Start' : 'Immediate') ?></div>
                                <?php else: ?>
                                    <input form="coChargesSaveForm" type="hidden" name="charge_invoice_timing[]" value="immediate">
                                <?php endif; ?>
                                <?php if ($isInvoiced): ?>
                                    <div class="small text-warning">
                                        Locked — already invoiced. Void or reverse the related invoice first, then you can modify this charge and re-invoice.
                                    </div>
                                <?php elseif ($isKey && $timing === 'on_start' && !empty($contract['start_date']) && $contract['start_date'] > date('Y-m-d')): ?>
                                    <div class="small text-info">Invoice available on start date <?= h($contract['start_date']) ?>.</div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= h($ch['nature']) ?></span></td>
                            <td class="text-end" style="min-width:7rem">
                                <input form="coChargesSaveForm" type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="charge_amount[]" value="<?= h(number_format((float)$ch['amount'], 2, '.', '')) ?>" <?= $locked ? 'readonly' : '' ?>>
                            </td>
                            <td style="min-width:8rem">
                                <?php if ($isDeposit): ?>
                                    <input form="coChargesSaveForm" type="hidden" name="charge_vat_mode[]" value="none">
                                    <input form="coChargesSaveForm" type="hidden" name="charge_vat_rate[]" value="0">
                                    <span class="small text-muted">n/a</span>
                                <?php elseif ($locked): ?>
                                    <input form="coChargesSaveForm" type="hidden" name="charge_vat_mode[]" value="<?= h($ch['vat_mode'] ?? 'exclusive') ?>">
                                    <input form="coChargesSaveForm" type="hidden" name="charge_vat_rate[]" value="<?= h(number_format((float)$ch['vat_rate'], 2, '.', '')) ?>">
                                    <span class="small"><?= h($ch['vat_mode'] ?? '') ?> <?= h(number_format((float)$ch['vat_rate'], 2)) ?>%</span>
                                <?php else: ?>
                                    <select form="coChargesSaveForm" name="charge_vat_mode[]" class="form-select form-select-sm mb-1">
                                        <?php foreach (['exclusive' => 'Exclusive (e.g. VAT 5%)', 'inclusive' => 'Inclusive', 'none' => 'None (Exempt / Zero / OOS)'] as $vm => $vl): ?>
                                            <option value="<?= h($vm) ?>" <?= ($ch['vat_mode'] ?? '') === $vm ? 'selected' : '' ?>><?= h($vl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input form="coChargesSaveForm" type="number" step="0.01" min="0" class="form-control form-control-sm" name="charge_vat_rate[]" value="<?= h(number_format((float)$ch['vat_rate'], 2, '.', '')) ?>" title="VAT %">
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= co_format_money($ch['vat_amount']) ?></td>
                            <td class="text-end"><?= co_format_money($ch['gross_amount']) ?></td>
                            <td style="min-width:6rem">
                                <input form="coChargesSaveForm" type="text" class="form-control form-control-sm" name="charge_gl[]" value="<?= h($ch['gl_account_override'] ?: ($ch['default_coa_code'] ?? ($isKey ? '4170' : ''))) ?>" placeholder="COA" <?= $isInvoiced ? 'readonly' : '' ?>>
                                <input form="coChargesSaveForm" type="hidden" name="charge_notes[]" value="<?= h((string)($ch['notes'] ?? '')) ?>">
                            </td>
                            <td>
                                <?php if ($isInvoiced): ?>
                                    <input form="coChargesSaveForm" type="hidden" name="charge_status[]" value="invoiced">
                                    <span class="badge text-bg-success">invoiced</span>
                                <?php else: ?>
                                    <select form="coChargesSaveForm" name="charge_status[]" class="form-select form-select-sm">
                                        <?php foreach (['active', 'disabled', 'cancelled'] as $st): ?>
                                            <option value="<?= h($st) ?>" <?= ($ch['status'] ?? '') === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($canInvoice): ?>
                                    <form method="post" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="generate_charge_invoice">
                                        <input type="hidden" name="charge_id" value="<?= (int)$ch['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Invoice</button>
                                    </form>
                                <?php elseif ($isInvoiced): ?>
                                    <span class="small text-success">Invoiced</span>
                                <?php elseif ($code === 'rent'): ?>
                                    <span class="small text-muted">Via schedules</span>
                                <?php elseif ($isDeposit): ?>
                                    <span class="small text-muted">Deposit path</span>
                                <?php elseif ($isKey && $timing === 'on_start'): ?>
                                    <span class="small text-muted">After start</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" form="coChargesSaveForm" class="btn btn-primary btn-sm">Save charges</button>

            <hr class="my-4">
            <h6 class="mb-2">Add custom charge</h6>
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add_custom_charge">
                <div class="col-md-3">
                    <label class="form-label small mb-0">Name</label>
                    <input type="text" name="custom_name" class="form-control form-control-sm" required maxlength="120">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">Amount (net)</label>
                    <input type="number" step="0.01" min="0.01" name="custom_amount" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">GL code</label>
                    <input type="text" name="custom_coa" class="form-control form-control-sm" required placeholder="e.g. 4120">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">VAT mode</label>
                    <select name="custom_vat_mode" class="form-select form-select-sm">
                        <option value="exclusive">Exclusive</option>
                        <option value="inclusive">Inclusive</option>
                        <option value="none">None</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-0">VAT %</label>
                    <input type="number" step="0.01" name="custom_vat_rate" class="form-control form-control-sm" value="5">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Add</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
