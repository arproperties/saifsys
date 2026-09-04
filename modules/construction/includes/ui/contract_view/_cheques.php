<div class="card card-round mb-4" id="cheque-plan">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <strong class="shop-section-title">Cheque Plan</strong>
            <span class="text-muted small ms-1">Cheques never settle invoices. Use Allocate Payment → Payment Workspace after bank clear.</span>
        </div>
        <a class="btn btn-sm btn-primary no-print" href="shop_rental_payment_workspace.php?contract_id=<?= (int)$id ?>">Receive Payment</a>
    </div>
    <div class="card-body">
    <form method="post" action="?id=<?= (int)$id ?>&amp;tab=cheques" class="row g-3 align-items-end mb-3 no-print"><?php csrf_field(); ?><input type="hidden" name="action" value="generate_cheques">
        <div class="col-md-3"><label class="form-label">No. of Rent Cheques</label><input type="number" min="0" name="rent_cheque_count" class="form-control" value="<?= h($contract['rent_cheque_count'] ?? 0) ?>"></div>
        <div class="col-md-3"><label class="form-label">No. of Deposit Cheques</label><input type="number" min="0" name="deposit_cheque_count" class="form-control" value="<?= h($contract['deposit_cheque_count'] ?? 0) ?>"></div>
        <div class="col-md-6">
            <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="combined_first_cheque" value="1" id="regenCombinedFirst" <?= !empty($contract['combined_first_cheque']) ? 'checked' : '' ?> <?= co_db_column_exists($conn, 'co_shop_rental_contracts', 'combined_first_cheque') ? '' : 'disabled' ?>>
                <label class="form-check-label" for="regenCombinedFirst">Combined first cheque (1st rent + sep. VAT + commission)</label>
            </div>
        </div>
        <div class="col-md-3"><button class="btn btn-outline-primary w-100">Generate Cheque Rows</button></div>
    </form>

    <?php
    $allocReady = function_exists('co_shop_cheque_allocatable_statuses');
    $allocStatuses = $allocReady ? co_shop_cheque_allocatable_statuses() : ['received', 'deposited', 'cleared'];
    $phase3Alloc = function_exists('co_shop_cheque_allocated_status_ready') && co_shop_cheque_allocated_status_ready($conn);
    ?>

    <form method="get" action="shop_rental_payment_workspace.php" id="combinedAllocateForm" class="no-print mb-3 border rounded p-2 bg-light">
        <input type="hidden" name="contract_id" value="<?= (int)$id ?>">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <strong class="small mb-0">Combined allocate</strong>
            <span class="small text-muted">Select rent/VAT cheques below, then:</span>
            <button type="submit" class="btn btn-sm btn-success" id="btnAllocateCombined">Allocate Combined Payment</button>
        </div>
        <div class="form-text mb-0">Opens Payment Workspace with funding = selected cheques (same contract only). Confirm posts one payment; cheques become Allocated.</div>
    </form>

    <form method="post" id="saveChequesForm"><?php csrf_field(); ?><input type="hidden" name="action" value="save_cheques"></form>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="no-print" style="width:2rem"></th>
                        <th>Type</th><th>Cheque No.</th><th>Bank</th><th>Date</th>
                        <th class="text-end">Amount</th>
                        <?php if ($hasVatCols): ?><th class="text-end">VAT</th><?php endif; ?>
                        <th>Status</th><th>Notes</th><th></th>
                    </tr>
                </thead>
                <tbody>
            <?php foreach ($cheques as $cheque):
                $isVatSep = (($cheque['notes'] ?? '') === 'VAT_SEPARATE');
                $isCombined = (($cheque['notes'] ?? '') === 'COMBINED_FIRST');
                $typeLabel = $isCombined ? 'Combined first' : ($isVatSep ? 'VAT (separate)' : ucwords(str_replace('_', ' ', $cheque['cheque_type'])));
                $st = (string)($cheque['status'] ?? '');
                $isTerminal = in_array($st, ['allocated', 'cleared'], true) && !empty($cheque['payment_id']);
                $isLockedRow = in_array($st, ['allocated', 'cancelled', 'replaced'], true)
                    || ($st === 'cleared' && !empty($cheque['payment_id']));
                $canAllocate = ($cheque['cheque_type'] ?? '') !== 'security_deposit'
                    && in_array($st, $allocStatuses, true)
                    && empty($cheque['payment_id'])
                    && empty($cheque['allocated_payment_id']);
                $wsUrl = 'shop_rental_payment_workspace.php?contract_id=' . (int)$id . '&cheque_id=' . (int)$cheque['id'];
            ?>
                <tr>
                    <td class="no-print">
                        <?php if ($canAllocate): ?>
                            <input type="checkbox" class="form-check-input cheque-alloc-pick" form="combinedAllocateForm" name="cheque_ids[]" value="<?= (int)$cheque['id'] ?>" data-amount="<?= h(number_format((float)$cheque['amount'], 2, '.', '')) ?>">
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?= $cheque['cheque_type'] === 'rent' ? ($isVatSep ? 'warning text-dark' : ($isCombined ? 'success' : 'primary')) : 'info text-dark' ?>"><?= h($typeLabel) ?></span></td>
                    <td><input form="saveChequesForm" type="text" name="cheques[<?= (int)$cheque['id'] ?>][cheque_number]" class="form-control form-control-sm" value="<?= h($cheque['cheque_number'] ?? '') ?>" <?= $isLockedRow ? 'readonly' : '' ?>></td>
                    <td><input form="saveChequesForm" type="text" name="cheques[<?= (int)$cheque['id'] ?>][bank_name]" class="form-control form-control-sm" value="<?= h($cheque['bank_name'] ?? '') ?>" <?= $isLockedRow ? 'readonly' : '' ?>></td>
                    <td><input form="saveChequesForm" type="date" name="cheques[<?= (int)$cheque['id'] ?>][cheque_date]" class="form-control form-control-sm" value="<?= h($cheque['cheque_date']) ?>" <?= $isLockedRow ? 'readonly' : '' ?>></td>
                    <td><input form="saveChequesForm" type="number" step="0.01" name="cheques[<?= (int)$cheque['id'] ?>][amount]" class="form-control form-control-sm text-end" value="<?= h($cheque['amount']) ?>" <?= $isLockedRow ? 'readonly' : '' ?>></td>
                    <?php if ($hasVatCols): ?>
                    <td>
                        <input form="saveChequesForm" type="number" step="0.01" name="cheques[<?= (int)$cheque['id'] ?>][vat_amount]" class="form-control form-control-sm text-end" value="<?= h($cheque['vat_amount'] ?? 0) ?>" <?= $isLockedRow ? 'readonly' : '' ?>>
                        <input form="saveChequesForm" type="hidden" name="cheques[<?= (int)$cheque['id'] ?>][net_amount]" value="<?= h($cheque['net_amount'] ?? 0) ?>">
                        <input form="saveChequesForm" type="hidden" name="cheques[<?= (int)$cheque['id'] ?>][notes]" value="<?= h($cheque['notes'] ?? '') ?>">
                    </td>
                    <?php endif; ?>
                    <td>
                        <?php if ($st === 'allocated'): ?>
                            <span class="badge bg-success">Allocated</span>
                        <?php elseif ($st === 'cleared' && !empty($cheque['payment_id'])): ?>
                            <span class="badge bg-success">Cleared (legacy receipt)</span>
                        <?php elseif ($st === 'cleared'): ?>
                            <span class="badge text-bg-primary">Cleared (bank)</span>
                            <input form="saveChequesForm" type="hidden" name="cheques[<?= (int)$cheque['id'] ?>][status]" value="cleared">
                        <?php else: ?>
                            <select form="saveChequesForm" name="cheques[<?= (int)$cheque['id'] ?>][status]" class="form-select form-select-sm">
                                <?php foreach (['received','deposited','bounced','returned','replaced','cancelled'] as $status): ?>
                                    <option value="<?= h($status) ?>" <?= $status === $st ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td><?php if (!$hasVatCols): ?><input form="saveChequesForm" type="text" name="cheques[<?= (int)$cheque['id'] ?>][notes]" class="form-control form-control-sm" value="<?= h($cheque['notes'] ?? '') ?>" <?= $isLockedRow ? 'readonly' : '' ?>><?php else: ?><span class="small text-muted"><?= h($cheque['notes'] ?? '') ?></span><?php endif; ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($st === 'allocated' || ($st === 'cleared' && !empty($cheque['payment_id']))): ?>
                            <?php
                            $receiptPayId = 0;
                            if (function_exists('co_receipt_payment_id_for_cheque')) {
                                $receiptPayId = (int)(co_receipt_payment_id_for_cheque($conn, $cid, $cheque) ?: 0);
                            } else {
                                $receiptPayId = (int)($cheque['allocated_payment_id'] ?? 0) ?: (int)($cheque['payment_id'] ?? 0);
                            }
                            ?>
                            <?php if ($receiptPayId > 0): ?>
                                <a href="client_payment_receipt.php?id=<?= $receiptPayId ?>" class="btn btn-sm btn-primary">Receipt</a>
                                <a href="client_receipt_pdf.php?id=<?= $receiptPayId ?>" target="_blank" class="btn btn-sm btn-outline-secondary">PDF</a>
                            <?php else: ?>
                                <a href="shop_cheque_receipt_pdf.php?id=<?= (int)$cheque['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">PDF</a>
                            <?php endif; ?>
                            <?php if ($receiptPayId > 0): ?>
                                <a href="shop_rental_payment_workspace.php?contract_id=<?= (int)$id ?>" class="btn btn-sm btn-outline-warning" title="Reverse from Workspace if bounce needed">Workspace</a>
                            <?php endif; ?>
                        <?php elseif (($cheque['cheque_type'] ?? '') === 'security_deposit'): ?>
                            <span class="small text-muted">Use Record Deposit</span>
                        <?php elseif ($canAllocate): ?>
                            <a class="btn btn-sm btn-success no-print" href="<?= h($wsUrl) ?>">Allocate Payment</a>
                            <button type="button" class="btn btn-sm btn-outline-secondary no-print" data-bs-toggle="collapse" data-bs-target="#bankCheque<?= (int)$cheque['id'] ?>">Bank status</button>
                        <?php elseif ($st !== 'cancelled'): ?>
                            <span class="small text-muted"><?= h(ucfirst($st)) ?></span>
                        <?php endif; ?>
                        <?php if (co_shop_phase2a_schema_ready($conn) && !in_array($st, ['allocated'], true) && empty($cheque['payment_id'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary no-print" data-bs-toggle="collapse" data-bs-target="#lifeCheque<?= (int)$cheque['id'] ?>">Lifecycle</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($canAllocate): ?>
                <tr class="collapse no-print" id="bankCheque<?= (int)$cheque['id'] ?>"><td colspan="<?= $hasVatCols ? 10 : 9 ?>">
                    <form method="post" action="?id=<?= (int)$id ?>&amp;tab=cheques" class="row g-2 align-items-end p-2"><?php csrf_field(); ?>
                        <input type="hidden" name="action" value="cheque_bank_status">
                        <input type="hidden" name="cheque_id" value="<?= (int)$cheque['id'] ?>">
                        <div class="col-md-3">
                            <label class="form-label small mb-0">Bank status (no invoice settlement)</label>
                            <select name="bank_status" class="form-select form-select-sm">
                                <option value="deposited" <?= $st === 'deposited' ? 'selected' : '' ?>>Deposited</option>
                                <option value="cleared" <?= $st === 'cleared' ? 'selected' : '' ?>>Cleared (bank)</option>
                                <option value="received" <?= $st === 'received' ? 'selected' : '' ?>>Received</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-0">Note</label>
                            <input type="text" name="lifecycle_note" class="form-control form-control-sm" placeholder="Optional">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-outline-primary w-100">Save bank status</button>
                        </div>
                        <div class="col-12 small text-muted">Marking deposited/cleared does <strong>not</strong> post GL or settle invoices. Use Allocate Payment after bank clear.</div>
                    </form>
                </td></tr>
                <?php endif; ?>
                <?php if (co_shop_phase2a_schema_ready($conn) && $st !== 'allocated' && empty($cheque['payment_id'])): ?>
                <tr class="collapse no-print" id="lifeCheque<?= (int)$cheque['id'] ?>"><td colspan="<?= $hasVatCols ? 10 : 9 ?>">
                    <form method="post" class="row g-2 align-items-end"><?php csrf_field(); ?>
                        <input type="hidden" name="action" value="cheque_lifecycle">
                        <input type="hidden" name="cheque_id" value="<?= (int)$cheque['id'] ?>">
                        <div class="col-md-2"><label class="form-label">Action</label>
                            <select name="lifecycle_op" class="form-select form-select-sm">
                                <option value="bounce">Bounce</option>
                                <option value="replace">Replace</option>
                                <option value="cancel">Cancel</option>
                                <option value="lost">Lost</option>
                                <option value="redeposit">Re-deposit</option>
                            </select>
                        </div>
                        <div class="col-md-2"><label class="form-label">Note</label><input type="text" name="lifecycle_note" class="form-control form-control-sm"></div>
                        <div class="col-md-2"><label class="form-label">New cheque #</label><input type="text" name="new_cheque_number" class="form-control form-control-sm"></div>
                        <div class="col-md-2"><label class="form-label">New date</label><input type="date" name="new_cheque_date" class="form-control form-control-sm" value="<?= h(date('Y-m-d')) ?>"></div>
                        <div class="col-md-2"><label class="form-label">New amount</label><input type="number" step="0.01" name="new_amount" class="form-control form-control-sm" value="<?= h($cheque['amount']) ?>"></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Apply</button></div>
                        <div class="col-12 small text-muted">If allocated, reverse the Workspace payment first. Bounce alone never settles invoices.</div>
                    </form>
                </td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$cheques): ?><tr><td colspan="<?= $hasVatCols ? 10 : 9 ?>" class="text-center text-muted py-4">No cheque rows yet. Enter cheque counts and generate rows.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <?php if ($cheques): ?><div class="mt-3 no-print"><button form="saveChequesForm" class="btn btn-primary">Save Cheque Details</button></div><?php endif; ?>
        <?php if (!$phase3Alloc): ?>
            <div class="alert alert-warning mt-3 mb-0 small">Run <code>migrations/construction_shop_rental_phase_cheque_allocate.sql</code> so cheques can move to status <strong>allocated</strong> after Workspace confirm.</div>
        <?php endif; ?>
</div></div>
<script>
(function(){
  var form = document.getElementById('combinedAllocateForm');
  var btn = document.getElementById('btnAllocateCombined');
  if (!form || !btn) return;
  form.addEventListener('submit', function(e){
    var n = form.querySelectorAll('.cheque-alloc-pick:checked').length;
    if (n < 1) {
      e.preventDefault();
      alert('Select at least one cheque for combined allocate.');
      return;
    }
  });
})();
</script>
