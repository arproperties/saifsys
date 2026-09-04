<div class="card card-round mb-4" id="deposit-section"><div class="card-header bg-white"><strong class="shop-section-title">Record Security Deposit</strong> <span class="text-muted small">— single posting path for cash or PDC</span></div><div class="card-body">
    <form method="post" class="row g-3 align-items-end no-print"><?php csrf_field(); ?><input type="hidden" name="action" value="record_deposit">
        <div class="col-md-2"><label class="form-label">Amount</label><input type="number" step="0.01" name="deposit_amount" class="form-control" value="<?= h(max(0, (float)$contract['security_deposit'] - (float)$contract['deposit_received_amount'])) ?>" required></div>
        <div class="col-md-2"><label class="form-label">Date</label><input type="date" name="deposit_date" class="form-control" value="<?= h(date('Y-m-d')) ?>"></div>
        <div class="col-md-3"><label class="form-label">Link Deposit Cheque (optional)</label>
            <select name="cheque_id" class="form-select">
                <option value="">Cash / bank transfer (no PDC)</option>
                <?php foreach ($openDepositCheques as $dc): ?>
                    <option value="<?= (int)$dc['id'] ?>"><?= h(($dc['cheque_number'] ?: 'Cheque #' . $dc['id']) . ' · ' . number_format((float)$dc['amount'], 2)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><label class="form-label">Received To</label><select name="pay_account_id" class="form-select" required><option value="">Select account</option><?php foreach ($paymentAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['account_code'] . ' - ' . $a['account_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100" <?= $phase1Ready ? '' : 'disabled' ?>>Post Deposit</button></div>
        <div class="col-12"><input type="text" name="reference" class="form-control" placeholder="Reference"></div>
    </form>
</div></div>
