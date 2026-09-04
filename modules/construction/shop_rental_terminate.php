<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
$cid = co_shop_require_company_id($conn);
$userId = current_user_id();
$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT c.*, cl.client_name FROM co_shop_rental_contracts c JOIN co_clients cl ON cl.id = c.client_id WHERE c.id = ? AND c.company_id = ?");
$stmt->execute([$id, $cid]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) { http_response_code(404); exit('Contract not found'); }
$err = '';
$snap = co_shop_termination_snapshot($conn, $cid, $id);
$preview = $snap['penalty_preview'];
$months = isset($_POST['penalty_months']) ? (float)$_POST['penalty_months'] : $preview['standard_months'];
$custom = isset($_POST['approved_penalty_amount']) ? (float)$_POST['approved_penalty_amount'] : $preview['standard_amount'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $months = (float)($_POST['penalty_months'] ?? $preview['standard_months']);
    $custom = (float)($_POST['approved_penalty_amount'] ?? 0);
    $preview = co_shop_termination_penalty_preview($conn, $cid, $contract, $months, $custom);
    if (!empty($_POST['finalize'])) {
        try {
            $result = co_shop_finalize_termination($conn, $cid, $id, [
                'termination_date' => $_POST['termination_date'] ?? date('Y-m-d'),
                'reason' => $_POST['reason'] ?? '',
                'penalty_months' => $months,
                'approved_penalty_amount' => $custom,
                'override_reason' => $_POST['override_reason'] ?? '',
                'settlement_method' => $_POST['settlement_method'] ?? 'bank_transfer',
            ], $userId);
            $q = 'Termination finalized.';
            if (!empty($result['penalty_invoice_id'])) {
                $q .= ' Penalty invoice #' . $result['penalty_invoice_id'];
            }
            header('Location: shop_rental_contract_view.php?id=' . $id . '&msg=' . urlencode($q));
            exit;
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}
$coUiV2 = true;
$pageTitle = 'Early Termination';
require_once __DIR__ . '/includes/construction_layout_header.php';
$f = $snap['financial'] ?: [];
$selMethod = (string)($_POST['settlement_method'] ?? 'bank_transfer');
$methods = [
    'bank_transfer' => ['Bank Transfer', 'landmark'],
    'cash' => ['Cash', 'banknote'],
    'cheque' => ['Cheque', 'scroll-text'],
    'deposit' => ['Deposit Deduction', 'lock'],
    'deposit_and_payment' => ['Deposit + Payment', 'split'],
    'installments' => ['Installments (AR)', 'calendar-days'],
];
?>
<div class="co-page-header">
    <div>
        <div class="co-crumb">
            <a href="shop_rental_contracts.php">Contracts</a><span>/</span>
            <a href="shop_rental_contract_view.php?id=<?= $id ?>"><?= h($contract['contract_number']) ?></a><span>/</span>
            <span>Early Termination</span>
        </div>
        <h1>Early Termination</h1>
        <p class="co-page-sub"><?= h($contract['contract_number']) ?> · <?= h($contract['client_name']) ?> · Penalty income <code>4150</code></p>
    </div>
    <div class="co-page-actions">
        <a href="shop_rental_contract_view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</a>
    </div>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if ($contract['status'] !== 'active'): ?>
<div class="alert alert-warning">Contract status is <?= h($contract['status']) ?>. Only active contracts can be terminated here.</div>
<?php else: ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="co-kpi"><div class="lbl">Outstanding rent</div><div class="val" style="font-size:1.1rem"><?= co_format_money($f['outstanding'] ?? 0) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="co-kpi"><div class="lbl">Tenant credit</div><div class="val teal" style="font-size:1.1rem"><?= co_format_money($f['client_credit'] ?? 0) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="co-kpi"><div class="lbl">Deposit held</div><div class="val" style="font-size:1.1rem"><?= co_format_money($snap['deposit_held']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="co-kpi"><div class="lbl">Pending sched / cheques</div><div class="val" style="font-size:1.1rem"><?= (int)$snap['pending_schedules'] ?> / <?= (int)$snap['open_cheques'] ?></div></div></div>
</div>

<div class="alert alert-secondary mb-3">
    Commission: <?= h(($snap['commission']['status'] ?? 'n/a')) ?>
    · Inspection: <?= $snap['inspection'] ? ('#'.(int)$snap['inspection']['id'].' completed') : 'not completed' ?>
    · Deposit settled: <?= $snap['deposit_settled'] ? 'yes' : 'no' ?>
    <?php if ($snap['deposit_held'] > 0 && !$snap['deposit_settled']): ?>
     — <a href="shop_rental_move_out_inspection.php?id=<?= $id ?>">Inspection</a> /
        <a href="shop_rental_deposit_settle.php?id=<?= $id ?>">Settle deposit</a> recommended before or after terminate.
    <?php endif; ?>
</div>

<form method="post" class="co-term-form" x-data="{ step: 1 }" x-init="$el.classList.add('co-stepper-ready')">
<?php csrf_field(); ?>
<div class="co-stepper no-print" role="tablist">
    <button type="button" class="co-step" :class="{ active: step===1, done: step>1 }" @click="step=1"><span class="num">1</span><span class="label">Details</span></button>
    <button type="button" class="co-step" :class="{ active: step===2, done: step>2 }" @click="step=2"><span class="num">2</span><span class="label">Penalty</span></button>
    <button type="button" class="co-step" :class="{ active: step===3, done: step>3 }" @click="step=3"><span class="num">3</span><span class="label">Settlement</span></button>
    <button type="button" class="co-step" :class="{ active: step===4 }" @click="step=4"><span class="num">4</span><span class="label">Review</span></button>
</div>

<div class="card card-round mb-3">
    <div class="card-body">
        <div class="co-step-panel" :class="{ active: step===1 }">
            <h2 class="h6 mb-3" style="color:var(--co-gold-soft)">Step 1 — Termination details</h2>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Termination Date</label><input type="date" name="termination_date" class="form-control" value="<?= h($_POST['termination_date'] ?? date('Y-m-d')) ?>" required></div>
                <div class="col-md-9"><label class="form-label">Reason</label><input type="text" name="reason" class="form-control" value="<?= h($_POST['reason'] ?? '') ?>" required></div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4 no-print">
                <button type="button" class="btn btn-primary" @click="step=2">Next</button>
            </div>
        </div>

        <div class="co-step-panel" :class="{ active: step===2 }">
            <h2 class="h6 mb-3" style="color:var(--co-gold-soft)">Step 2 — Penalty calculation</h2>
            <p class="text-muted small">Standard <?= h((string)$preview['standard_months']) ?> months × monthly <?= co_format_money($preview['monthly_equivalent']) ?> = <?= co_format_money($preview['standard_amount']) ?></p>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Months</label>
                    <select name="penalty_months" class="form-select" onchange="this.form.submit()">
                        <?php foreach ([0, 0.5, 1, 1.5, 2, 3] as $m): ?>
                        <option value="<?= $m ?>" <?= abs($months - $m) < 0.001 ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Approved Penalty Amount</label><input type="number" step="0.01" name="approved_penalty_amount" class="form-control" value="<?= h((string)$custom) ?>"></div>
                <div class="col-md-3"><label class="form-label">Discount vs standard</label><input type="text" class="form-control" readonly value="<?= h(number_format(max(0, $preview['standard_amount'] - $custom), 2)) ?>"></div>
                <div class="col-md-3"><label class="form-label">Override reason</label><input type="text" name="override_reason" class="form-control" value="<?= h($_POST['override_reason'] ?? '') ?>" placeholder="If amount ≠ standard"></div>
            </div>
            <div class="d-flex justify-content-between gap-2 mt-4 no-print">
                <button type="button" class="btn btn-outline-secondary" @click="step=1">Back</button>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary" name="finalize" value="0">Recalculate</button>
                    <button type="button" class="btn btn-primary" @click="step=3">Next</button>
                </div>
            </div>
        </div>

        <div class="co-step-panel" :class="{ active: step===3 }">
            <h2 class="h6 mb-3" style="color:var(--co-gold-soft)">Step 3 — Settlement method</h2>
            <div class="co-method-grid mb-3">
                <?php foreach ($methods as $k => $meta): ?>
                <label class="co-method <?= $selMethod === $k ? 'is-selected' : '' ?>">
                    <input type="radio" name="settlement_method" value="<?= h($k) ?>" <?= $selMethod === $k ? 'checked' : '' ?>>
                    <span class="ico"><i data-lucide="<?= h($meta[1]) ?>" style="width:22px;height:22px"></i></span>
                    <span class="txt"><?= h($meta[0]) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between gap-2 mt-4 no-print">
                <button type="button" class="btn btn-outline-secondary" @click="step=2">Back</button>
                <button type="button" class="btn btn-primary" @click="step=4">Next</button>
            </div>
        </div>

        <div class="co-step-panel" :class="{ active: step===4 }">
            <h2 class="h6 mb-3" style="color:var(--co-gold-soft)">Step 4 — Review &amp; finalize</h2>
            <div class="alert alert-warning">
                Finalizing cancels pending schedules/cheques, posts penalty invoice if any (income <code>4150</code>), and sets status to <strong>Terminated</strong>.
            </div>
            <ul class="mb-3 text-muted">
                <li>Approved penalty: <strong style="color:var(--co-text)"><?= co_format_money($custom) ?></strong></li>
                <li>Standard: <?= co_format_money($preview['standard_amount']) ?></li>
            </ul>
            <div class="d-flex justify-content-between gap-2 mt-4">
                <button type="button" class="btn btn-outline-secondary no-print" @click="step=3">Back</button>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary" name="finalize" value="0">Recalculate</button>
                    <button class="btn btn-danger" name="finalize" value="1" onclick="return confirm('Finalize termination? This cancels pending schedules/cheques, posts penalty invoice if any, and sets status Terminated.')">Finalize Termination</button>
                </div>
            </div>
        </div>
    </div>
</div>
</form>
<style>
.co-term-form.co-stepper-ready .co-step-panel { display: none; }
.co-term-form.co-stepper-ready .co-step-panel.active { display: block; }
</style>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
