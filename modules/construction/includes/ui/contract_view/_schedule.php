<div class="card card-round mb-4" id="rent-schedule"><div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2"><strong class="shop-section-title">Rent Earning Schedule</strong> <span class="text-muted small">— monthly accounting periods (independent of cheque plan)</span>
    <form method="post" class="no-print"><?php csrf_field(); ?><input type="hidden" name="action" value="generate_schedules"><button class="btn btn-sm btn-primary">Generate / Refresh Schedule</button></form>
</div><div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0"><thead class="table-light"><tr><th>Type</th><th>Period</th><th>Due</th><th class="text-end">Net</th><th class="text-end">VAT</th><th class="text-end">Invoice total</th><th>Invoice</th><th></th></tr></thead><tbody>
    <?php foreach ($schedules as $s):
        $sNet = (float)($s['net_amount'] ?? $s['amount']);
        $sVat = (float)($s['vat_amount'] ?? 0);
        $sIsVat = (($s['schedule_type'] ?? 'rent') === 'vat');
        $sTotal = $sIsVat ? $sVat : round($sNet + $sVat, 2);
        $prepaidNote = (!$sIsVat && $sVat > 0.005 && co_shop_normalize_vat_collection($contract['vat_collection_method'] ?? '') === CO_SHOP_VAT_SEPARATE);
    ?>
        <tr>
            <td><span class="badge bg-<?= $sIsVat ? 'secondary' : 'secondary' ?>"><?= h($sIsVat ? 'VAT (legacy)' : 'Rent') ?></span></td>
            <td><?= h($s['period_start']) ?> to <?= h($s['period_end']) ?></td>
            <td><?= h($s['due_date']) ?></td>
            <td class="text-end"><?= co_format_money($sNet) ?></td>
            <td class="text-end"><?= co_format_money($sVat) ?><?php if ($prepaidNote): ?> <span class="text-muted small">(prepaid)</span><?php endif; ?></td>
            <td class="text-end"><?= co_format_money($sTotal) ?></td>
            <td><?= $s['invoice_id'] ? h($s['invoice_number'] . ' (' . $s['invoice_status'] . ')') : '<span class="badge bg-secondary">Pending</span>' ?></td>
            <td class="no-print"><?php if ($sIsVat && !$s['invoice_id']): ?><span class="text-muted small">Retired — use Payment Workspace</span><?php elseif (!$s['invoice_id']): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="invoice_schedule"><input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-outline-success">Generate Invoice</button></form><?php else: ?><a href="client_invoice_pdf.php?id=<?= (int)$s['invoice_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">PDF Tax Invoice</a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$schedules): ?><tr><td colspan="8" class="text-center text-muted py-4">No rent schedule yet. Generate schedule first.</td></tr><?php endif; ?>
    </tbody></table>
</div></div>
<?php if ($commissionStatus ?? null): ?>
<div class="card card-round mb-4" id="commission-on-schedule">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong class="shop-section-title">Tenant Commission</strong>
        <span class="text-muted small">— separate from rent earning schedule (BR-CO-SHOP-008)</span>
    </div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0"><thead class="table-light"><tr><th>Type</th><th>Invoice</th><th>Due</th><th class="text-end">Net</th><th class="text-end">VAT</th><th class="text-end">Total</th><th class="text-end">Balance</th><th></th></tr></thead><tbody>
            <?php if ($cInv ?? null): ?>
            <tr>
                <td><span class="badge bg-info text-dark">Commission</span></td>
                <td><?= h($cInv['invoice_number']) ?> <span class="text-muted small">(<?= h($cInv['status'] ?? '') ?>)</span></td>
                <td><?= h($cInv['due_date'] ?? '') ?></td>
                <td class="text-end"><?= co_format_money($commissionStatus['net'] ?? 0) ?></td>
                <td class="text-end"><?= co_format_money($commissionStatus['vat'] ?? 0) ?></td>
                <td class="text-end"><?= co_format_money($cInv['total_amount'] ?? 0) ?></td>
                <td class="text-end"><?= co_format_money($cInv['balance'] ?? 0) ?></td>
                <td class="text-end no-print">
                    <a href="client_invoice_pdf.php?id=<?= (int)$cInv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">PDF</a>
                    <?php if (($cInv['balance'] ?? 0) > 0.005): ?>
                    <a href="?id=<?= (int)$id ?>&amp;tab=finance#payment-manager" class="btn btn-sm btn-outline-primary">Allocate</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php else: ?>
            <tr><td colspan="8" class="text-muted text-center py-3">
                Commission <?= h(ucfirst(str_replace('_', ' ', $commissionStatus['status'] ?? 'n/a'))) ?>
                · Net <?= strip_tags(co_format_money($commissionStatus['net'] ?? 0)) ?>
                · <a href="?id=<?= (int)$id ?>&amp;tab=commission">Configure / generate invoice</a>
            </td></tr>
            <?php endif; ?>
        </tbody></table>
    </div>
</div>
<?php endif; ?>
