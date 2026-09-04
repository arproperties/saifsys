<?php
// accounts/expense_edit.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/expense_line_calc.php';
require_once __DIR__.'/../includes/cleaning_payment_accounts.php';
require_once __DIR__.'/../includes/sm_expense_service.php';

if (!has_permission('expenses.edit', MODULE_FINANCE, $conn)) {
    require_role(['Owner','Admin','Account'], $conn);
}

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { header('Location: expenses.php'); exit; }

sm_ensure_prepaid_asset_column($conn);

$vendors  = $conn->query("SELECT id, name FROM vendors WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$expAccts = $conn->query("SELECT account_no,name FROM chart_of_accounts WHERE type='Expense' AND is_header=0 ORDER BY account_no")->fetchAll(PDO::FETCH_ASSOC);
$cashBank = cleaning_payment_account_options($conn);
$prepaidAssets = sm_prepaid_asset_options($conn);

$H = $conn->prepare("SELECT * FROM expenses WHERE id=?");
$H->execute([$id]);
$h = $H->fetch(PDO::FETCH_ASSOC);
if (!$h) { header('Location: expenses.php'); exit; }

$listedPayNos = array_column($cashBank, 'account_no');
if (!empty($h['pay_account_no']) && !in_array($h['pay_account_no'], $listedPayNos, true)) {
    $one = $conn->prepare("SELECT account_no, name FROM chart_of_accounts WHERE account_no = ? LIMIT 1");
    $one->execute([$h['pay_account_no']]);
    if ($ex = $one->fetch(PDO::FETCH_ASSOC)) {
        $cashBank[] = $ex;
    }
}

$L = $conn->prepare("SELECT * FROM expense_lines WHERE expense_id=? ORDER BY line_no");
$L->execute([$id]);
$lines = $L->fetchAll(PDO::FETCH_ASSOC);

$schedule = sm_expense_prepaid_schedule($conn, $id);
$status = (string)($h['status'] ?? 'draft');
$isVoid = $status === 'void';
$amortPosted = $schedule && (int)($schedule['posted_cnt'] ?? 0) > 0;
$canEditAmounts = !$isVoid && !$amortPosted;
$expenseType = (string)($h['expense_type'] ?? 'operating');
$defaultAsset = trim((string)($h['prepaid_asset_account_no'] ?? '')) ?: (
    $schedule ? (string)$schedule['prepaid_account_no'] : sm_prepaid_asset_account($conn)
);
$defaultAmortExpense = trim((string)($h['prepaid_expense_account_no'] ?? '')) ?: (
    $schedule ? (string)$schedule['expense_account_no'] : ''
);

$msg=$err='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save'])) {
  try {
    csrf_verify();
    if ($isVoid) {
      throw new RuntimeException('This expense is voided and cannot be edited.');
    }
    if ($amortPosted) {
      throw new RuntimeException(
        'Cannot edit monetary details: prepaid amortization already posted. '
        . 'Void/reverse amortization first, or void this expense after reversing amortization journals.'
      );
    }

    $expense_date   = $_POST['expense_date'] ?: $h['expense_date'];
    $vendor_id      = (int)($_POST['vendor_id'] ?? 0);
    $reference_no   = trim($_POST['reference_no'] ?? '');
    $paid_via       = $_POST['paid_via'] ?? $h['paid_via'];
    $pay_account_no = ($paid_via==='ap') ? null : trim($_POST['pay_account_no'] ?? '');
    if ($paid_via === 'bank' || $paid_via === 'cash') {
      if ($pay_account_no === '') {
        throw new RuntimeException('Payment account is required for cash/bank.');
      }
      cleaning_validate_payment_account_no($conn, $pay_account_no);
    }
    $notes          = trim($_POST['notes'] ?? '');
    $vat_mode       = ($_POST['vat_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';

    $expense_type = in_array($_POST['expense_type'] ?? '', ['operating','prepaid','payroll','other'], true)
        ? $_POST['expense_type'] : ($h['expense_type'] ?? 'operating');
    $prepaid_months = $expense_type === 'prepaid' ? max(1, (int)($_POST['prepaid_months'] ?? ($h['prepaid_months'] ?? 12))) : null;
    $prepaid_expense_account_no = $expense_type === 'prepaid'
        ? trim($_POST['prepaid_expense_account_no'] ?? '') : null;
    $prepaid_asset_account_no = $expense_type === 'prepaid'
        ? trim($_POST['prepaid_asset_account_no'] ?? '') : null;
    if ($expense_type === 'prepaid' && $prepaid_asset_account_no === '') {
        $prepaid_asset_account_no = sm_prepaid_asset_account($conn);
    }

    $acc_nos   = $_POST['line_account_no'] ?? [];
    $descs     = $_POST['line_desc'] ?? [];
    $qtys      = $_POST['line_qty'] ?? [];
    $costs     = $_POST['line_cost'] ?? [];
    $vatrates  = $_POST['line_vat_rate'] ?? [];

    $rows=[]; $sub=0; $vat=0; $tot=0;
    for($i=0;$i<count($acc_nos);$i++){
      $a=trim($acc_nos[$i]??''); if($a==='') continue;
      $d=trim($descs[$i]??'');
      $q=(float)($qtys[$i]??0);
      $c=(float)($costs[$i]??0);
      $vr=(float)($vatrates[$i]??0);
      if($q<=0||$c<0) continue;
      $am = expense_compute_line_amounts($q, $c, $vr, $vat_mode);
      $ls = $am['line_subtotal']; $lv = $am['line_vat']; $lt = $am['line_total'];
      $rows[]=['account_no'=>$a,'description'=>$d,'qty'=>$q,'unit_cost'=>$c,'vat_rate'=>$vr,'line_subtotal'=>$ls,'line_vat'=>$lv,'line_total'=>$lt];
      $sub+=$ls; $vat+=$lv; $tot+=$lt;
    }
    if(!$rows) throw new RuntimeException("Add at least one line.");
    if ($expense_type === 'prepaid' && $prepaid_expense_account_no === '') {
      $prepaid_expense_account_no = $rows[0]['account_no'];
    }

    $conn->beginTransaction();
    $upH = $conn->prepare("UPDATE expenses
      SET expense_date=?, vendor_id=?, reference_no=?, paid_via=?, pay_account_no=?,
          subtotal=?, vat_amount=?, total=?, vat_mode=?, notes=?,
          expense_type=?, prepaid_months=?, prepaid_expense_account_no=?, prepaid_asset_account_no=?,
          updated_at=NOW()
      WHERE id=?");
    $upH->execute([
        $expense_date, $vendor_id?:null, $reference_no, $paid_via, $pay_account_no,
        $sub, $vat, $tot, $vat_mode, $notes,
        $expense_type, $prepaid_months, $prepaid_expense_account_no ?: null, $prepaid_asset_account_no ?: null,
        $id
    ]);

    $conn->prepare("DELETE FROM expense_lines WHERE expense_id=?")->execute([$id]);
    $insL = $conn->prepare("INSERT INTO expense_lines
      (expense_id,line_no,account_no,description,qty,unit_cost,line_subtotal,vat_rate,line_vat,line_total)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    $n=1;
    foreach($rows as $r){
      $insL->execute([$id,$n++,$r['account_no'],$r['description'],$r['qty'],$r['unit_cost'],$r['line_subtotal'],$r['vat_rate'],$r['line_vat'],$r['line_total']]);
    }

    // Posted expenses: reverse + repost (prepaid-aware). Drafts stay draft.
    $repostMsg = '';
    if ($status === 'posted' || $status === 'pending_approval') {
      $repost = sm_expense_repost($conn, $id);
      if (!$repost['success']) {
        throw new RuntimeException($repost['message']);
      }
      $repostMsg = ' ' . $repost['message'];
    }

    $conn->commit();

    $msg = 'Saved.' . $repostMsg;
    $H->execute([$id]); $h=$H->fetch(PDO::FETCH_ASSOC);
    $L->execute([$id]); $lines=$L->fetchAll(PDO::FETCH_ASSOC);
    $schedule = sm_expense_prepaid_schedule($conn, $id);
    $status = (string)($h['status'] ?? 'draft');
    $isVoid = $status === 'void';
    $amortPosted = $schedule && (int)($schedule['posted_cnt'] ?? 0) > 0;
    $canEditAmounts = !$isVoid && !$amortPosted;
    $expenseType = (string)($h['expense_type'] ?? 'operating');
    $defaultAsset = trim((string)($h['prepaid_asset_account_no'] ?? '')) ?: (
        $schedule ? (string)$schedule['prepaid_account_no'] : sm_prepaid_asset_account($conn)
    );
    $defaultAmortExpense = trim((string)($h['prepaid_expense_account_no'] ?? '')) ?: (
        $schedule ? (string)$schedule['expense_account_no'] : ''
    );

  } catch(Throwable $e){
    if($conn->inTransaction()) $conn->rollBack();
    $err=$e->getMessage();
  }
}

$statusBadge = match ($status) {
    'posted' => 'success',
    'void' => 'secondary',
    'pending_approval' => 'warning',
    default => 'info',
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Edit Expense #<?= (int)$id ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .table tfoot td{font-weight:600}
  .card {border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08);}
  .form-control:focus, .form-select:focus {border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);}
  .line-item-row:hover {background-color: #f8f9fa;}
  .prepaid-panel { border-left: 4px solid #0d6efd; }
  input[type="number"]::-webkit-inner-spin-button, input[type="number"]::-webkit-outer-spin-button {opacity: 1;}
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
    <a href="expenses.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <h3 class="mb-0 me-auto"><i class="bi bi-receipt-cutoff me-2"></i>Edit Expense #<?= (int)$id ?></h3>
    <span class="badge text-bg-<?= h($statusBadge) ?> fs-6"><?= h($status) ?></span>
    <span class="badge bg-light text-dark border fs-6 text-capitalize"><?= h($expenseType) ?></span>
    <?php if (!empty($h['gl_journal_id'])): ?>
      <a class="btn btn-sm btn-outline-primary" href="journal_entries.php?q=<?= urlencode((string)$h['gl_journal_id']) ?>">
        Journal #<?= (int)$h['gl_journal_id'] ?>
      </a>
    <?php endif; ?>
  </div>

  <?php if($msg): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= h($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

  <?php if ($amortPosted): ?>
    <div class="alert alert-warning">
      <strong>Locked for amount edits.</strong>
      Prepaid amortization has already been posted
      (<?= (int)$schedule['posted_cnt'] ?> period<?= (int)$schedule['posted_cnt'] === 1 ? '' : 's' ?>).
      To correct this booking: reverse those amortization journals (or void them), then return here — or void the original expense after reversals.
      <a href="prepaid_schedules.php" class="alert-link">Open Prepaid Schedules</a>
    </div>
  <?php elseif ($isVoid): ?>
    <div class="alert alert-secondary">This expense is voided. It is view-only.</div>
  <?php elseif ($status === 'posted'): ?>
    <div class="alert alert-info py-2 small mb-3">
      <i class="bi bi-info-circle me-1"></i>
      Saving will reverse the existing GL journal and post a new one (and rebuild the prepaid schedule if type is prepaid).
    </div>
  <?php endif; ?>

  <form method="post">
  <?php csrf_field(); ?>
    <input type="hidden" name="save" value="1">
    <input type="hidden" id="expenseId" value="<?= (int)$id ?>">

    <div class="card shadow-sm">
      <div class="card-header bg-white border-bottom">
        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Expense Details</h5>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="expense_date" value="<?= h($h['expense_date']) ?>" required <?= $canEditAmounts ? '' : 'disabled' ?>>
          </div>
          <div class="col-md-5">
            <label class="form-label fw-semibold">Supplier</label>
            <select class="form-select" name="vendor_id" <?= $canEditAmounts ? '' : 'disabled' ?>>
              <option value="">-- none --</option>
              <?php foreach($vendors as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= (int)$h['vendor_id']===(int)$v['id']?'selected':'' ?>><?= h($v['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Reference #</label>
            <input class="form-control" name="reference_no" value="<?= h($h['reference_no']) ?>" placeholder="Bill/Receipt number" <?= $canEditAmounts ? '' : 'disabled' ?>>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Paid Via <span class="text-danger">*</span></label>
            <select class="form-select" name="paid_via" id="paid_via" <?= $canEditAmounts ? '' : 'disabled' ?>>
              <option value="bank" <?= $h['paid_via']==='bank'?'selected':'' ?>>Bank</option>
              <option value="cash" <?= $h['paid_via']==='cash'?'selected':'' ?>>Cash</option>
              <option value="ap"   <?= $h['paid_via']==='ap'  ?'selected':'' ?>>Accounts Payable</option>
            </select>
          </div>

          <div class="col-md-4" id="pay_ac_wrap">
            <label class="form-label fw-semibold">Payment Account <span class="text-danger">*</span></label>
            <select class="form-select" name="pay_account_no" id="pay_account_no" <?= $canEditAmounts ? '' : 'disabled' ?>>
              <option value="">— Select —</option>
              <?php foreach($cashBank as $a): ?>
                <option value="<?= h($a['account_no']) ?>" <?= ($h['pay_account_no']??'')===$a['account_no']?'selected':'' ?>>
                  <?= h($a['account_no'].' – '.$a['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text"><i class="bi bi-info-circle me-1"></i>Used when Paid Via = Bank/Cash</div>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Notes</label>
            <input class="form-control" name="notes" value="<?= h($h['notes']) ?>" placeholder="Additional notes..." <?= $canEditAmounts ? '' : 'disabled' ?>>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Expense Type</label>
            <select class="form-select" name="expense_type" id="expense_type" <?= $canEditAmounts ? '' : 'disabled' ?>>
              <option value="operating" <?= $expenseType==='operating'?'selected':'' ?>>Operating (expense now)</option>
              <option value="prepaid" <?= $expenseType==='prepaid'?'selected':'' ?>>Prepaid (amortize monthly)</option>
              <option value="payroll" <?= $expenseType==='payroll'?'selected':'' ?>>Payroll</option>
              <option value="other" <?= $expenseType==='other'?'selected':'' ?>>Other</option>
            </select>
          </div>

          <div class="col-md-4 prepaid-only <?= $expenseType === 'prepaid' ? '' : 'd-none' ?>">
            <label class="form-label fw-semibold">Amortize over (months)</label>
            <input type="number" class="form-control" name="prepaid_months" id="prepaid_months" min="1" max="60"
                   value="<?= h((string)($h['prepaid_months'] ?? 12)) ?>" <?= $canEditAmounts ? '' : 'disabled' ?>>
          </div>
          <div class="col-md-4 prepaid-only <?= $expenseType === 'prepaid' ? '' : 'd-none' ?>">
            <label class="form-label fw-semibold">Prepaid asset account</label>
            <select class="form-select" name="prepaid_asset_account_no" id="prepaid_asset_account_no" <?= $canEditAmounts ? '' : 'disabled' ?>>
              <?php foreach ($prepaidAssets as $a): ?>
                <option value="<?= h($a['account_no']) ?>" <?= $a['account_no'] === $defaultAsset ? 'selected' : '' ?>>
                  <?= h($a['account_no'].' – '.$a['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Balance sheet account holding the remaining prepaid balance.</div>
          </div>
          <div class="col-md-4 prepaid-only <?= $expenseType === 'prepaid' ? '' : 'd-none' ?>">
            <label class="form-label fw-semibold">Expense account (amortization)</label>
            <select class="form-select" name="prepaid_expense_account_no" id="prepaid_expense_account_no" <?= $canEditAmounts ? '' : 'disabled' ?>>
              <option value="">— from first line —</option>
              <?php foreach ($expAccts as $a): ?>
                <option value="<?= h($a['account_no']) ?>" <?= $a['account_no'] === $defaultAmortExpense ? 'selected' : '' ?>>
                  <?= h($a['account_no'].' – '.$a['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Monthly Dr expense / Cr prepaid asset.</div>
          </div>

          <?php $vm = ($h['vat_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive'; ?>
          <div class="col-12">
            <label class="form-label fw-semibold">VAT on amounts</label>
            <div class="d-flex flex-wrap gap-3 align-items-center">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="vat_mode" id="vat_mode_exclusive" value="exclusive" <?= $vm === 'exclusive' ? 'checked' : '' ?> <?= $canEditAmounts ? '' : 'disabled' ?>>
                <label class="form-check-label" for="vat_mode_exclusive">Excluding VAT (exclusive)</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="vat_mode" id="vat_mode_inclusive" value="inclusive" <?= $vm === 'inclusive' ? 'checked' : '' ?> <?= $canEditAmounts ? '' : 'disabled' ?>>
                <label class="form-check-label" for="vat_mode_inclusive">Including VAT (inclusive)</label>
              </div>
            </div>
            <div class="form-text" id="vatModeHint">Unit cost is <strong>ex VAT</strong>; VAT is added on top.</div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($expenseType === 'prepaid' || $schedule): ?>
    <div class="card shadow-sm mt-3 prepaid-panel">
      <div class="card-header bg-white">
        <h5 class="mb-0"><i class="bi bi-calendar2-month me-2"></i>Prepaid posting summary</h5>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <div class="text-muted small text-uppercase">Initial posting</div>
            <div>Dr <strong><?= h($defaultAsset) ?></strong> prepaid asset (excl. VAT)</div>
            <div>Dr <strong>1260</strong> VAT Recoverable (if any)</div>
            <div>Cr <strong><?= h(($h['paid_via'] ?? '') === 'ap' ? '2000' : ($h['pay_account_no'] ?? '—')) ?></strong> payment</div>
          </div>
          <div class="col-md-4">
            <div class="text-muted small text-uppercase">Monthly amortization</div>
            <div>Dr <strong><?= h($defaultAmortExpense ?: '—') ?></strong> expense</div>
            <div>Cr <strong><?= h($defaultAsset) ?></strong> prepaid asset</div>
            <div class="small text-muted mt-1"><?= (int)($h['prepaid_months'] ?? ($schedule['months'] ?? 0)) ?> months</div>
          </div>
          <div class="col-md-4">
            <div class="text-muted small text-uppercase">Schedule</div>
            <?php if ($schedule): ?>
              <div>Schedule #<?= (int)$schedule['id'] ?> · <span class="badge text-bg-<?= $schedule['status']==='active'?'primary':($schedule['status']==='completed'?'success':'secondary') ?>"><?= h($schedule['status']) ?></span></div>
              <div class="small">Posted periods: <?= (int)$schedule['posted_cnt'] ?> · Pending: <?= (int)$schedule['pending_cnt'] ?></div>
              <a class="btn btn-sm btn-outline-primary mt-2" href="prepaid_schedules.php">Manage schedules</a>
            <?php else: ?>
              <div class="text-muted">No schedule yet (will be created when posted).</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mt-3 shadow-sm">
      <div class="card-header bg-white border-bottom">
        <div class="d-flex align-items-center">
          <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i>Attachments</h5>
          <small class="text-muted ms-2">PDF, images, DOC/XLS (max 20MB)</small>
          <div class="ms-auto">
            <input type="file" id="attFile" class="d-none" multiple
                   accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,image/*">
            <button type="button" class="btn btn-sm btn-primary" id="btnUpload" <?= $isVoid ? 'disabled' : '' ?>>
              <i class="bi bi-plus-circle me-1"></i>Upload
            </button>
          </div>
        </div>
      </div>
      <div class="card-body">
        <div id="attList" class="mt-2"></div>
        <div class="text-danger small mt-2" id="attErr" style="display:none"></div>
      </div>
    </div>

    <div class="card shadow-sm mt-3">
      <div class="card-header bg-gradient bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Expense Lines</h5>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="lineTable">
            <thead class="table-light">
              <tr>
                <th style="width:25%">Expense Account</th>
                <th style="width:25%">Description</th>
                <th style="width:9%">Qty</th>
                <th style="width:12%"><span id="thUnitCost">Unit cost (ex VAT)</span></th>
                <th style="width:12%">VAT %</th>
                <th class="text-end" style="width:12%">Line Total</th>
                <th style="width:5%"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($lines as $ix=>$r): ?>
                <tr class="line-item-row">
                  <td>
                    <select class="form-select form-select-sm acc" name="line_account_no[<?= $ix ?>]" required <?= $canEditAmounts ? '' : 'disabled' ?>>
                      <option value="">-- Select --</option>
                      <?php foreach($expAccts as $a): ?>
                        <option value="<?= h($a['account_no']) ?>" <?= $a['account_no']===$r['account_no']?'selected':'' ?>>
                          <?= h($a['account_no'].' – '.$a['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input class="form-control form-control-sm dsc" name="line_desc[<?= $ix ?>]" value="<?= h($r['description']) ?>" placeholder="Description" <?= $canEditAmounts ? '' : 'disabled' ?>></td>
                  <td><input type="number" step="0.001" min="0" class="form-control form-control-sm qty" name="line_qty[<?= $ix ?>]" value="<?= h($r['qty']) ?>" <?= $canEditAmounts ? '' : 'disabled' ?>></td>
                  <td><input type="number" step="0.01"  min="0" class="form-control form-control-sm cost" name="line_cost[<?= $ix ?>]" value="<?= h($r['unit_cost']) ?>" <?= $canEditAmounts ? '' : 'disabled' ?>></td>
                  <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm vr" name="line_vat_rate[<?= $ix ?>]" value="<?= h($r['vat_rate']) ?>" placeholder="0.00" <?= $canEditAmounts ? '' : 'disabled' ?>></td>
                  <td class="text-end"><span class="lt fw-semibold">0.00</span></td>
                  <td class="text-center">
                    <?php if ($canEditAmounts): ?>
                      <button type="button" class="btn btn-sm btn-outline-danger del" title="Remove line"><i class="bi bi-trash"></i></button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; if (!$lines): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inbox me-2"></i>No lines. Add some.</td></tr>
              <?php endif; ?>
            </tbody>
            <tfoot class="table-light">
              <tr>
                <td colspan="5" class="text-end fw-semibold">Subtotal:</td>
                <td class="text-end fw-bold"><span id="tSub">0.00</span> AED</td>
                <td></td>
              </tr>
              <tr>
                <td colspan="5" class="text-end fw-semibold">VAT:</td>
                <td class="text-end fw-bold"><span id="tVat">0.00</span> AED</td>
                <td></td>
              </tr>
              <tr class="table-primary">
                <th colspan="5" class="text-end">Total:</th>
                <th class="text-end"><span id="tTot">0.00</span> AED</th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php if ($canEditAmounts): ?>
        <button type="button" class="btn btn-outline-primary mt-3" id="addLine">
          <i class="bi bi-plus-circle me-1"></i>Add Line
        </button>
        <?php endif; ?>
      </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4 pt-3 border-top">
      <div>
        <?php if (!$isVoid && !$amortPosted): ?>
        <form action="expense_delete.php" method="post" class="d-inline" onsubmit="return confirm('Reverse GL and void this expense?')">
          <?php csrf_field(); ?>
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <button type="submit" class="btn btn-outline-danger">
            <i class="bi bi-x-circle me-1"></i>Void expense
          </button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($canEditAmounts): ?>
      <button type="submit" class="btn btn-success px-5">
        <i class="bi bi-check-circle me-2"></i>Save Changes
      </button>
      <?php endif; ?>
    </div>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CAN_EDIT = <?= $canEditAmounts ? 'true' : 'false' ?>;
const ACCOUNTS = [
  <?php foreach($expAccts as $a): ?>
    {no:<?= json_encode($a['account_no']) ?>, name:<?= json_encode($a['name']) ?>},
  <?php endforeach; ?>
];
const body=document.querySelector('#lineTable tbody');
const addBtn=document.getElementById('addLine');

function esc(s){return String(s).replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
function tpl(i){
  const opts = ACCOUNTS.map(a=>`<option value="${esc(a.no)}">${esc(a.no)} – ${esc(a.name)}</option>`).join('');
  return `<tr class="line-item-row">
    <td><select class="form-select form-select-sm acc" name="line_account_no[${i}]" required><option value="">-- Select --</option>${opts}</select></td>
    <td><input class="form-control form-control-sm dsc" name="line_desc[${i}]" placeholder="Description"></td>
    <td><input type="number" step="0.001" min="0" class="form-control form-control-sm qty"  name="line_qty[${i}]" value="1.000"></td>
    <td><input type="number" step="0.01"  min="0" class="form-control form-control-sm cost" name="line_cost[${i}]" value="0.00"></td>
    <td><input type="number" step="0.01"  min="0" max="100" class="form-control form-control-sm vr"   name="line_vat_rate[${i}]" value="5.00" placeholder="0.00"></td>
    <td class="text-end"><span class="lt fw-semibold">0.00</span></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger del" title="Remove line"><i class="bi bi-trash"></i></button></td>
  </tr>`;
}
function hook(tr){
  tr.querySelectorAll('input,select').forEach(el=>{
    el.addEventListener('input', recalc);
    el.addEventListener('change', recalc);
  });
  const del = tr.querySelector('.del');
  if (del) del.addEventListener('click', ()=>{ tr.remove(); recalc(); });
}
function addRow(){ if(!CAN_EDIT) return; const i = body.children.length; body.insertAdjacentHTML('beforeend', tpl(i)); hook(body.lastElementChild); recalc(); }
if (addBtn) addBtn.addEventListener('click', addRow);

function lineAmounts(q, c, vr, inclusive){
  const g = q * c;
  if (inclusive) {
    if (vr <= 0) return { ls: g, lv: 0, lt: g };
    const lv = Math.round((g * vr / (100 + vr)) * 100) / 100;
    const ls = Math.round((g - lv) * 100) / 100;
    return { ls, lv, lt: g };
  }
  const ls = q * c;
  const lv = ls * (vr / 100);
  const lt = ls + lv;
  return { ls, lv, lt };
}
function recalc(){
  const inclusive = document.getElementById('vat_mode_inclusive')?.checked;
  let sub=0, vat=0, tot=0;
  body.querySelectorAll('tr').forEach(tr=>{
    const qtyEl = tr.querySelector('.qty');
    if (!qtyEl) return;
    const q=parseFloat(tr.querySelector('.qty').value)||0;
    const c=parseFloat(tr.querySelector('.cost').value)||0;
    const vr=parseFloat(tr.querySelector('.vr').value)||0;
    const { ls, lv, lt } = lineAmounts(q, c, vr, inclusive);
    sub+=ls; vat+=lv; tot+=lt;
    tr.querySelector('.lt').textContent=lt.toFixed(2);
  });
  document.getElementById('tSub').textContent=sub.toFixed(2);
  document.getElementById('tVat').textContent=vat.toFixed(2);
  document.getElementById('tTot').textContent=tot.toFixed(2);
}
function updateVatModeUi(){
  const inclusive = document.getElementById('vat_mode_inclusive')?.checked;
  const th = document.getElementById('thUnitCost');
  const hint = document.getElementById('vatModeHint');
  if (th) th.textContent = inclusive ? 'Unit cost (incl. VAT)' : 'Unit cost (ex VAT)';
  if (hint) hint.innerHTML = inclusive
    ? 'Unit cost is <strong>gross (incl. VAT)</strong>; VAT is calculated automatically.'
    : 'Unit cost is <strong>ex VAT</strong>; VAT is added on top.';
  recalc();
}
document.querySelectorAll('input[name="vat_mode"]').forEach(el=> el.addEventListener('change', updateVatModeUi));
body.querySelectorAll('tr').forEach(tr => { if (tr.querySelector('.qty')) hook(tr); });
updateVatModeUi();

function togglePay(){
  const paid = document.getElementById('paid_via');
  if (!paid) return;
  const ap = paid.value==='ap';
  document.getElementById('pay_ac_wrap').style.display = ap ? 'none' : '';
  const pa = document.getElementById('pay_account_no');
  if (pa) pa.required = !ap && CAN_EDIT;
}
togglePay();
document.getElementById('paid_via')?.addEventListener('change', togglePay);

function togglePrepaid(){
  const show = document.getElementById('expense_type')?.value === 'prepaid';
  document.querySelectorAll('.prepaid-only').forEach(el => el.classList.toggle('d-none', !show));
}
document.getElementById('expense_type')?.addEventListener('change', togglePrepaid);
togglePrepaid();
</script>

<script>
(function(){
  const expenseId = parseInt(document.getElementById('expenseId').value || '0', 10);
  const btnUpload = document.getElementById('btnUpload');
  const fileInput = document.getElementById('attFile');
  const listBox   = document.getElementById('attList');
  const errBox    = document.getElementById('attErr');

  function fmtSize(n){ const u=['B','KB','MB','GB']; let i=0; while(n>=1024 && i<u.length-1){ n/=1024; i++; } return (i?n.toFixed(1):n)+' '+u[i]; }

  function renderList(items){
    if(!items || !items.length){
      listBox.innerHTML = '<div class="text-muted small py-3 text-center"><i class="bi bi-inbox"></i> No attachments yet. Click "Upload" to add files.</div>';
      return;
    }
    listBox.innerHTML = `
      <div class="row g-2">
        ${items.map(x=>`
          <div class="col-md-4 attachment-item" data-id="${x.id}">
            <div class="card border">
              <div class="card-body p-2 d-flex align-items-center">
                <i class="bi bi-file-earmark-text text-primary fs-5 me-2"></i>
                <div class="flex-grow-1 min-w-0">
                  <div class="fw-semibold text-truncate" title="${x.file_name}">
                    <a href="${x.url}" target="_blank" rel="noopener" class="text-decoration-none">${x.file_name}</a>
                  </div>
                  <small class="text-muted">${x.size_fmt} • ${x.uploaded_at}</small>
                </div>
                <button class="btn btn-sm btn-outline-danger ms-2" data-del="${x.id}" title="Delete">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </div>
          </div>
        `).join('')}
      </div>`;
    listBox.querySelectorAll('[data-del]').forEach(b=>{
      b.addEventListener('click', ()=>{
        const id = b.getAttribute('data-del');
        if(!confirm('Delete this file?')) return;
        fetch('ajax/ajax_expense_attachment_delete.php', {method:'POST', body:new URLSearchParams({id})})
          .then(r=>r.json())
          .then(j=>{ if(j.success) loadList(); else alert(j.error||'Failed'); });
      });
    });
  }

  function loadList(){
    if(!expenseId){ renderList([]); return; }
    fetch('ajax/ajax_expense_attachment_list.php?expense_id='+expenseId)
      .then(r=>r.json())
      .then(j=>{
        if(!j.success){ renderList([]); return; }
        j.items.forEach(x=>{ if(!x.size_fmt && x.file_size){ x.size_fmt = fmtSize(x.file_size); } });
        renderList(j.items);
      })
      .catch(()=> renderList([]));
  }

  btnUpload?.addEventListener('click', ()=>{
    if(!expenseId){ alert('Save the expense first, then upload.'); return; }
    fileInput.click();
  });

  fileInput?.addEventListener('change', ()=>{
    if(!expenseId || !fileInput.files?.length) return;
    const fd = new FormData();
    fd.append('expense_id', String(expenseId));
    Array.from(fileInput.files).forEach(f=>fd.append('files[]', f));
    fetch('ajax/ajax_expense_attachment_upload.php', { method:'POST', body:fd })
      .then(r=>r.json())
      .then(j=>{
        if(j.success){ fileInput.value=''; errBox.style.display='none'; loadList(); }
        else { errBox.textContent=j.error||'Upload failed'; errBox.style.display=''; }
      })
      .catch(e=>{ errBox.textContent=e.message||'Server error'; errBox.style.display=''; });
  });

  loadList();
})();
</script>
</body>
</html>
