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
$err = ''; $msg = '';
$held = (float)$contract['deposit_received_amount'];
$existing = co_shop_get_finalized_deposit_settlement($conn, $cid, $id);
$insp = co_shop_latest_completed_inspection($conn, $cid, $id);
$suggested = $insp ? (json_decode($insp['suggested_deductions_json'] ?? '{}', true) ?: []) : [];

$paymentAccounts = $conn->prepare("
    SELECT id, account_code, account_name FROM re_chart_of_accounts
    WHERE company_id = ? AND is_active = 1 AND is_header = 0
      AND (account_code LIKE '11%' OR account_code LIKE '12%')
    ORDER BY account_code
");
$paymentAccounts->execute([$cid]);
$paymentAccounts = $paymentAccounts->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing) {
    csrf_verify();
    try {
        $result = co_shop_finalize_deposit_settlement($conn, $cid, $id, [
            'settlement_date' => $_POST['settlement_date'] ?? date('Y-m-d'),
            'refund_amount' => (float)($_POST['refund_amount'] ?? 0),
            'damage_amount' => (float)($_POST['damage_amount'] ?? 0),
            'utility_amount' => (float)($_POST['utility_amount'] ?? 0),
            'cleaning_amount' => (float)($_POST['cleaning_amount'] ?? 0),
            'forfeit_amount' => (float)($_POST['forfeit_amount'] ?? 0),
            'missing_keys_amount' => (float)($_POST['missing_keys_amount'] ?? 0),
            'apply_to_invoices_amount' => (float)($_POST['apply_to_invoices_amount'] ?? 0),
            'pay_account_id' => (int)($_POST['pay_account_id'] ?? 0),
            'inspection_id' => (int)($_POST['inspection_id'] ?? ($insp['id'] ?? 0)),
            'notes' => $_POST['notes'] ?? '',
        ], $userId);
        header('Location: shop_rental_contract_view.php?id=' . $id . '&msg=' . urlencode('Deposit settled. Journal #' . $result['journal_id']));
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$dmg = (float)($_POST['damage_amount'] ?? ($suggested['damage'] ?? 0));
$cln = (float)($_POST['cleaning_amount'] ?? ($suggested['cleaning'] ?? 0));
$utl = (float)($_POST['utility_amount'] ?? ($suggested['utility'] ?? 0));
$keys = (float)($_POST['missing_keys_amount'] ?? ($suggested['missing_keys'] ?? 0));
$forf = (float)($_POST['forfeit_amount'] ?? 0);
$refundDefault = (float)($_POST['refund_amount'] ?? max(0, $held - (float)($suggested['total'] ?? 0)));
$applyInv = (float)($_POST['apply_to_invoices_amount'] ?? 0);

$coUiV2 = true;
$pageTitle = 'Deposit Settlement';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="co-page-header">
    <div>
        <div class="co-crumb">
            <a href="shop_rental_contracts.php">Contracts</a><span>/</span>
            <a href="shop_rental_contract_view.php?id=<?= $id ?>"><?= h($contract['contract_number']) ?></a><span>/</span>
            <span>Deposit Settlement</span>
        </div>
        <h1>Deposit Settlement</h1>
        <p class="co-page-sub"><?= h($contract['contract_number']) ?> · Held <?= co_format_money($held) ?> · Recoveries post to <code>4160</code></p>
    </div>
    <div class="co-page-actions">
        <a href="shop_rental_contract_view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</a>
        <?php if (!$insp && !$existing): ?>
        <a href="shop_rental_move_out_inspection.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">Move-Out Inspection</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if ($existing): ?>
<div class="alert alert-success">Already settled on <?= h($existing['settlement_date']) ?> · Journal #<?= (int)$existing['journal_id'] ?> · Duplicate settlement blocked.</div>
<?php else: ?>

<form method="post" class="co-settle-form" x-data="{
  step: 1,
  held: <?= json_encode($held) ?>,
  damage: <?= json_encode($dmg) ?>,
  cleaning: <?= json_encode($cln) ?>,
  utility: <?= json_encode($utl) ?>,
  keys: <?= json_encode($keys) ?>,
  forfeit: <?= json_encode($forf) ?>,
  refund: <?= json_encode($refundDefault) ?>,
  applyInv: <?= json_encode($applyInv) ?>,
  get deductions() { return (+this.damage||0)+(+this.cleaning||0)+(+this.utility||0)+(+this.keys||0)+(+this.forfeit||0); },
  get remaining() { return Math.round((this.held - this.deductions - (+this.refund||0) - (+this.applyInv||0)) * 100) / 100; }
}" x-init="$el.classList.add('co-stepper-ready')">
<?php csrf_field(); ?>
<input type="hidden" name="inspection_id" value="<?= (int)($insp['id'] ?? 0) ?>">

<div class="co-stepper no-print">
    <button type="button" class="co-step" :class="{ active: step===1, done: step>1 }" @click="step=1"><span class="num">1</span><span class="label">Inspection</span></button>
    <button type="button" class="co-step" :class="{ active: step===2, done: step>2 }" @click="step=2"><span class="num">2</span><span class="label">Deductions</span></button>
    <button type="button" class="co-step" :class="{ active: step===3, done: step>3 }" @click="step=3"><span class="num">3</span><span class="label">Settlement</span></button>
    <button type="button" class="co-step" :class="{ active: step===4 }" @click="step=4"><span class="num">4</span><span class="label">Review</span></button>
</div>

<div class="card card-round mb-3">
<div class="card-body">
    <div class="co-step-panel" :class="{ active: step===1 }">
        <h2 class="h6 mb-3" style="color:var(--co-gold-soft)">Step 1 — Inspection</h2>
        <?php if (!$insp): ?>
        <div class="alert alert-warning">No completed move-out inspection. Full refund is allowed; damage/cleaning/utility deductions require <a href="shop_rental_move_out_inspection.php?id=<?= $id ?>">inspection</a>.</div>
        <?php else: ?>
        <div class="alert alert-info">Using inspection #<?= (int)$insp['id'] ?> (<?= h($insp['inspection_date']) ?>). Suggested amounts pre-filled — edit freely before finalize.</div>
        <?php endif; ?>
        <div class="co-kpi mb-3" style="max-width:280px"><div class="lbl">Deposit held</div><div class="val gold"><?= co_format_money($held) ?></div></div>
        <div class="d-flex justify-content-end mt-3 no-print"><button type="button" class="btn btn-primary" @click="step=2">Next</button></div>
    </div>

    <div class="co-step-panel" :class="{ active: step===2 }">
        <h2 class="h6 mb-3" style="color:var(--co-gold-soft)">Step 2 — Deductions (suggested vs approved)</h2>
        <div class="table-responsive co-table-shell mb-3">
            <table class="table mb-0">
                <thead class="table-light"><tr><th>Item</th><th class="text-end">Suggested</th><th class="text-end">Approved</th></tr></thead>
                <tbody>
                    <tr>
                        <td>Damage (4160)</td>
                        <td class="text-end text-muted"><?= co_format_money($suggested['damage'] ?? 0) ?></td>
                        <td class="text-end" style="max-width:9rem"><input type="number" step="0.01" name="damage_amount" class="form-control form-control-sm text-end" x-model.number="damage" value="<?= h((string)$dmg) ?>"></td>
                    </tr>
                    <tr>
                        <td>Cleaning (4160)</td>
                        <td class="text-end text-muted"><?= co_format_money($suggested['cleaning'] ?? 0) ?></td>
                        <td class="text-end" style="max-width:9rem"><input type="number" step="0.01" name="cleaning_amount" class="form-control form-control-sm text-end" x-model.number="cleaning" value="<?= h((string)$cln) ?>"></td>
                    </tr>
                    <tr>
                        <td>Utilities (4160)</td>
                        <td class="text-end text-muted"><?= co_format_money($suggested['utility'] ?? 0) ?></td>
                        <td class="text-end" style="max-width:9rem"><input type="number" step="0.01" name="utility_amount" class="form-control form-control-sm text-end" x-model.number="utility" value="<?= h((string)$utl) ?>"></td>
                    </tr>
                    <tr>
                        <td>Missing keys (4160)</td>
                        <td class="text-end text-muted"><?= co_format_money($suggested['missing_keys'] ?? 0) ?></td>
                        <td class="text-end" style="max-width:9rem"><input type="number" step="0.01" name="missing_keys_amount" class="form-control form-control-sm text-end" x-model.number="keys" value="<?= h((string)$keys) ?>"></td>
                    </tr>
                    <tr>
                        <td>Forfeiture (4160)</td>
                        <td class="text-end text-muted">—</td>
                        <td class="text-end" style="max-width:9rem"><input type="number" step="0.01" name="forfeit_amount" class="form-control form-control-sm text-end" x-model.number="forfeit" value="<?= h((string)$forf) ?>"></td>
                    </tr>
                </tbody>
                <tfoot><tr><th>Total deductions</th><th></th><th class="text-end" x-text="deductions.toFixed(2)"><?= number_format($dmg+$cln+$utl+$keys+$forf, 2) ?></th></tr></tfoot>
            </table>
        </div>
        <div class="d-flex justify-content-between no-print">
            <button type="button" class="btn btn-outline-secondary" @click="step=1">Back</button>
            <button type="button" class="btn btn-primary" @click="step=3">Next</button>
        </div>
    </div>

    <div class="co-step-panel" :class="{ active: step===3 }">
        <h2 class="h6 mb-3" style="color:var(--co-gold-soft)">Step 3 — Settlement amounts</h2>
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Settlement Date</label><input type="date" name="settlement_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Refund to tenant</label><input type="number" step="0.01" name="refund_amount" class="form-control" x-model.number="refund" value="<?= h((string)$refundDefault) ?>"></div>
            <div class="col-md-3"><label class="form-label">Apply to open invoices</label><input type="number" step="0.01" name="apply_to_invoices_amount" class="form-control" x-model.number="applyInv" value="<?= h((string)$applyInv) ?>"></div>
            <div class="col-md-6"><label class="form-label">Refund Bank/Cash</label><select name="pay_account_id" class="form-select"><option value="">Select if refunding</option><?php foreach ($paymentAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['account_code'].' - '.$a['account_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control" value="<?= h($_POST['notes'] ?? '') ?>"></div>
        </div>
        <div class="row g-3 mt-2">
            <div class="col-md-4"><div class="co-kpi"><div class="lbl">Refund</div><div class="val teal" style="font-size:1.1rem" x-text="(+refund||0).toFixed(2)"><?= number_format($refundDefault, 2) ?></div></div></div>
            <div class="col-md-4"><div class="co-kpi"><div class="lbl">Apply to AR</div><div class="val" style="font-size:1.1rem" x-text="(+applyInv||0).toFixed(2)"><?= number_format($applyInv, 2) ?></div></div></div>
            <div class="col-md-4"><div class="co-kpi"><div class="lbl">Remaining</div><div class="val" style="font-size:1.1rem" :class="{ danger: remaining < -0.005 }" x-text="remaining.toFixed(2)"><?= number_format($held - ($dmg+$cln+$utl+$keys+$forf) - $refundDefault - $applyInv, 2) ?></div></div></div>
        </div>
        <div class="d-flex justify-content-between mt-4 no-print">
            <button type="button" class="btn btn-outline-secondary" @click="step=2">Back</button>
            <button type="button" class="btn btn-primary" @click="step=4">Next</button>
        </div>
    </div>

    <div class="co-step-panel" :class="{ active: step===4 }">
        <h2 class="h6 mb-3" style="color:var(--co-gold-soft)">Step 4 — Review &amp; finalize</h2>
        <div class="alert alert-warning">Finalize posts deposit settlement journals (refund / recovery <code>4160</code> / AR application). This cannot be duplicated.</div>
        <div class="d-flex justify-content-between mt-4">
            <button type="button" class="btn btn-outline-secondary no-print" @click="step=3">Back</button>
            <button class="btn btn-danger" onclick="return confirm('Finalize deposit settlement and post journals?')">Finalize Settlement</button>
        </div>
    </div>
</div>
</div>
</form>
<style>
.co-settle-form.co-stepper-ready .co-step-panel { display: none; }
.co-settle-form.co-stepper-ready .co-step-panel.active { display: block; }
</style>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
