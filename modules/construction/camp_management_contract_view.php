<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_income_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$userId = (int)(current_user_id() ?: 0);
$id = (int)($_GET['id'] ?? 0);
$msg = '';
$err = '';

function co_camp_contract_load(PDO $conn, int $cid, int $id): ?array {
    $stmt = $conn->prepare("
        SELECT c.*, camp.camp_name, cl.client_name
        FROM co_camp_management_contracts c
        JOIN co_labor_camps camp ON camp.id = c.camp_id
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE c.id = ? AND c.company_id = ?
    ");
    $stmt->execute([$id, $cid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function co_camp_update_settlement_status(PDO $conn, int $cid, int $settlementId): void {
    $stmt = $conn->prepare("
        SELECT s.net_receivable, COALESCE(SUM(r.amount), 0) AS paid_amount
        FROM co_camp_agent_settlements s
        LEFT JOIN co_camp_agent_receipts r ON r.settlement_id = s.id AND r.company_id = s.company_id
        WHERE s.id = ? AND s.company_id = ?
        GROUP BY s.id, s.net_receivable
    ");
    $stmt->execute([$settlementId, $cid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $status = 'posted';
    if ((float)$row['paid_amount'] > 0.005 && (float)$row['paid_amount'] + 0.005 < (float)$row['net_receivable']) {
        $status = 'partial';
    } elseif ((float)$row['paid_amount'] + 0.005 >= (float)$row['net_receivable']) {
        $status = 'paid';
    }
    $conn->prepare("UPDATE co_camp_agent_settlements SET status = ? WHERE id = ? AND company_id = ?")
        ->execute([$status, $settlementId, $cid]);
}

$contract = co_camp_contract_load($conn, $cid, $id);
if (!$contract) { header('Location: camp_management_contracts.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_settlement') {
            $periodStart = $_POST['period_start'] ?? date('Y-m-01');
            $periodEnd = $_POST['period_end'] ?? date('Y-m-t', strtotime($periodStart));
            $settlementDate = $_POST['settlement_date'] ?? date('Y-m-d');
            $grossRent = round((float)($_POST['gross_rent_amount'] ?? 0), 2);
            $outputVatRate = (float)($_POST['output_vat_rate'] ?? $contract['vat_rate']);
            $commissionRate = (float)($_POST['commission_rate'] ?? $contract['commission_rate']);
            $commissionVatRate = (float)($_POST['commission_vat_rate'] ?? $contract['commission_vat_rate']);
            $otherDeductions = round((float)($_POST['other_deductions'] ?? 0), 2);
            $notes = trim($_POST['notes'] ?? '');
            if ($grossRent <= 0) throw new RuntimeException('Gross rent collected is required.');
            $outputVat = round($grossRent * $outputVatRate / 100, 2);
            $commission = round($grossRent * $commissionRate / 100, 2);
            $commissionVat = round($commission * $commissionVatRate / 100, 2);
            $netReceivable = round($grossRent + $outputVat - $commission - $commissionVat - $otherDeductions, 2);
            if ($netReceivable < 0) throw new RuntimeException('Net receivable cannot be negative.');
            $settlementNumber = co_next_document_number($conn, $cid, 'CAMP-SET', 'co_camp_agent_settlements', 'settlement_number');
            $conn->beginTransaction();
            $stmt = $conn->prepare("
                INSERT INTO co_camp_agent_settlements
                    (company_id, contract_id, settlement_number, period_start, period_end, settlement_date, gross_rent_amount, output_vat_rate, output_vat_amount, commission_rate, commission_amount, commission_vat_rate, commission_vat_amount, other_deductions, net_receivable, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?)
            ");
            $stmt->execute([$cid, $id, $settlementNumber, $periodStart, $periodEnd, $settlementDate, $grossRent, $outputVatRate, $outputVat, $commissionRate, $commission, $commissionVatRate, $commissionVat, $otherDeductions, $netReceivable, $notes ?: null, $userId ?: null]);
            $settlementId = (int)$conn->lastInsertId();
            $postResult = co_post_camp_agent_settlement_to_accounting($settlementId, $cid, $userId);
            if (!$postResult['success']) throw new RuntimeException($postResult['error'] ?? 'Settlement posting failed.');
            $conn->prepare("UPDATE co_camp_agent_settlements SET journal_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$postResult['journal_id'], $settlementId, $cid]);
            $conn->commit();
            $msg = 'Camp agent settlement posted. Net receivable: ' . co_format_money($netReceivable);
        } elseif ($action === 'record_receipt') {
            $settlementId = (int)($_POST['settlement_id'] ?? 0);
            $receiptDate = $_POST['receipt_date'] ?? date('Y-m-d');
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $payAccountId = (int)($_POST['pay_account_id'] ?? 0);
            $reference = trim($_POST['reference'] ?? '');
            if ($settlementId <= 0 || $amount <= 0 || $payAccountId <= 0) {
                throw new RuntimeException('Settlement, amount, and received-to account are required.');
            }
            $check = $conn->prepare("
                SELECT s.net_receivable, COALESCE(SUM(r.amount), 0) AS paid_amount
                FROM co_camp_agent_settlements s
                LEFT JOIN co_camp_agent_receipts r ON r.settlement_id = s.id AND r.company_id = s.company_id
                WHERE s.id = ? AND s.company_id = ? AND s.contract_id = ? AND s.status <> 'cancelled'
                GROUP BY s.id, s.net_receivable
            ");
            $check->execute([$settlementId, $cid, $id]);
            $settlement = $check->fetch(PDO::FETCH_ASSOC);
            if (!$settlement) throw new RuntimeException('Settlement not found.');
            $balance = round((float)$settlement['net_receivable'] - (float)$settlement['paid_amount'], 2);
            if ($amount > $balance + 0.005) throw new RuntimeException('Receipt amount cannot exceed settlement balance.');
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO co_camp_agent_receipts (company_id, settlement_id, receipt_date, amount, pay_account_id, reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$cid, $settlementId, $receiptDate, $amount, $payAccountId, $reference ?: null, $userId ?: null]);
            $receiptId = (int)$conn->lastInsertId();
            $postResult = co_post_camp_agent_receipt_to_accounting($receiptId, $cid, $userId);
            if (!$postResult['success']) throw new RuntimeException($postResult['error'] ?? 'Receipt posting failed.');
            $conn->prepare("UPDATE co_camp_agent_receipts SET journal_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$postResult['journal_id'], $receiptId, $cid]);
            co_camp_update_settlement_status($conn, $cid, $settlementId);
            $conn->commit();
            $msg = 'Agent remittance received and posted.';
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $err = $e->getMessage();
    }
    $contract = co_camp_contract_load($conn, $cid, $id);
}

$stmt = $conn->prepare("
    SELECT s.*, COALESCE(p.paid_amount, 0) AS paid_amount,
           GREATEST(s.net_receivable - COALESCE(p.paid_amount, 0), 0) AS balance_due
    FROM co_camp_agent_settlements s
    LEFT JOIN (
        SELECT settlement_id, SUM(amount) AS paid_amount
        FROM co_camp_agent_receipts
        WHERE company_id = ?
        GROUP BY settlement_id
    ) p ON p.settlement_id = s.id
    WHERE s.company_id = ? AND s.contract_id = ?
    ORDER BY s.period_start DESC, s.id DESC
");
$stmt->execute([$cid, $cid, $id]);
$settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);
$paymentAccounts = co_fetch_payment_accounts($conn, $cid);
$pageTitle = 'Camp Agent Agreement';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div><a href="camp_management_contracts.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0"><?= h($contract['contract_number']) ?></h1><p class="text-muted mb-0"><?= h($contract['camp_name']) ?> - Agent: <?= h($contract['client_name']) ?></p></div>
</div>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card card-round"><div class="card-body"><div class="text-muted">Commission</div><h5><?= h(number_format((float)$contract['commission_rate'], 2)) ?>%</h5><small>VAT <?= h(number_format((float)$contract['commission_vat_rate'], 2)) ?>%</small></div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body"><div class="text-muted">Remittance</div><h5><?= h(ucwords(str_replace('_', ' ', $contract['remittance_frequency']))) ?></h5><small><?= h($contract['payment_terms'] ?: 'Per agreement') ?></small></div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body"><div class="text-muted">Rent VAT</div><h5><?= h(number_format((float)$contract['vat_rate'], 2)) ?>%</h5><small>Output VAT on rent</small></div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body"><div class="text-muted">Status</div><h5><span class="badge bg-<?= $contract['status'] === 'active' ? 'success' : 'secondary' ?>"><?= h($contract['status']) ?></span></h5></div></div></div>
</div>
<div class="card card-round mb-4">
    <div class="card-header bg-white"><strong>New Agent Settlement</strong></div>
    <div class="card-body">
        <form method="post" class="row g-3"><input type="hidden" name="action" value="create_settlement">
            <div class="col-md-3"><label class="form-label">Period From</label><input type="date" name="period_start" class="form-control" value="<?= h(date('Y-m-01')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Period To</label><input type="date" name="period_end" class="form-control" value="<?= h(date('Y-m-t')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Settlement Date</label><input type="date" name="settlement_date" class="form-control" value="<?= h(date('Y-m-d')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Gross Rent Collected *</label><input type="number" step="0.01" name="gross_rent_amount" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label">Rent VAT %</label><input type="number" step="0.01" name="output_vat_rate" class="form-control" value="<?= h($contract['vat_rate']) ?>"></div>
            <div class="col-md-2"><label class="form-label">Commission %</label><input type="number" step="0.01" name="commission_rate" class="form-control" value="<?= h($contract['commission_rate']) ?>"></div>
            <div class="col-md-2"><label class="form-label">Commission VAT %</label><input type="number" step="0.01" name="commission_vat_rate" class="form-control" value="<?= h($contract['commission_vat_rate']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Other Deductions</label><input type="number" step="0.01" name="other_deductions" class="form-control" value="0"></div>
            <div class="col-md-3"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control"></div>
            <div class="col-12"><button class="btn btn-primary">Post Settlement</button></div>
        </form>
    </div>
</div>
<div class="card card-round"><div class="card-header bg-white"><strong>Agent Settlements</strong></div><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0"><thead class="table-light"><tr><th>Settlement</th><th>Period</th><th class="text-end">Gross Rent</th><th class="text-end">VAT</th><th class="text-end">Commission</th><th class="text-end">Net Receivable</th><th class="text-end">Paid</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($settlements as $s): ?>
<tr>
    <td><strong><?= h($s['settlement_number']) ?></strong><br><small class="text-muted"><?= h($s['settlement_date']) ?></small></td>
    <td><?= h($s['period_start']) ?> to <?= h($s['period_end']) ?></td>
    <td class="text-end"><?= co_format_money($s['gross_rent_amount']) ?></td>
    <td class="text-end"><?= co_format_money($s['output_vat_amount']) ?></td>
    <td class="text-end"><?= co_format_money((float)$s['commission_amount'] + (float)$s['commission_vat_amount'] + (float)$s['other_deductions']) ?></td>
    <td class="text-end"><?= co_format_money($s['net_receivable']) ?></td>
    <td class="text-end"><?= co_format_money($s['paid_amount']) ?></td>
    <td><span class="badge bg-<?= $s['status'] === 'paid' ? 'success' : ($s['status'] === 'partial' ? 'warning text-dark' : 'primary') ?>"><?= h($s['status']) ?></span></td>
    <td>
        <?php if ((float)$s['balance_due'] > 0.005 && $s['status'] !== 'cancelled'): ?>
            <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="collapse" data-bs-target="#receipt<?= (int)$s['id'] ?>">Receive</button>
        <?php endif; ?>
    </td>
</tr>
<?php if ((float)$s['balance_due'] > 0.005 && $s['status'] !== 'cancelled'): ?>
<tr class="collapse" id="receipt<?= (int)$s['id'] ?>"><td colspan="9" class="bg-light">
    <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="action" value="record_receipt">
        <input type="hidden" name="settlement_id" value="<?= (int)$s['id'] ?>">
        <div class="col-md-2"><label class="form-label">Receipt Date</label><input type="date" name="receipt_date" class="form-control form-control-sm" value="<?= h(date('Y-m-d')) ?>"></div>
        <div class="col-md-2"><label class="form-label">Amount</label><input type="number" step="0.01" name="amount" class="form-control form-control-sm" value="<?= h($s['balance_due']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Received To</label><select name="pay_account_id" class="form-select form-select-sm" required><option value="">Select account</option><?php foreach ($paymentAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['account_code'] . ' - ' . $a['account_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Reference</label><input type="text" name="reference" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-success w-100">Post Receipt</button></div>
    </form>
</td></tr>
<?php endif; ?>
<?php endforeach; ?>
<?php if (!$settlements): ?><tr><td colspan="9" class="text-center text-muted py-4">No agent settlements yet. Post the first settlement when the agent sends the collection statement.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
