<?php
// accounts/expense_add.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/gl_posting.php';  // <-- for posting
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/expense_line_calc.php';
require_once __DIR__.'/../includes/cleaning_payment_accounts.php';
require_once __DIR__.'/../includes/sm_expense_service.php';

// Check permission (backward compatible: if no permission but has role, allow)
if (!has_permission('expenses.create', MODULE_FINANCE, $conn)) {
    require_role(['Owner','Admin','Account'], $conn);
}

$vendors = $conn->query("SELECT id, name FROM vendors WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$expAccts = $conn->query("
  SELECT account_no, name FROM chart_of_accounts
  WHERE type='Expense' AND is_header=0
  ORDER BY account_no
")->fetchAll(PDO::FETCH_ASSOC);

$cashBank = cleaning_payment_account_options($conn);
$prepaidAssets = sm_prepaid_asset_options($conn);
$defaultPrepaidAsset = sm_prepaid_asset_account($conn);
$requiresApproval = sm_expense_requires_approval($conn);
$canApprove = sm_user_can_approve_expense($conn);

$msg=$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    csrf_verify();
    $expense_date   = $_POST['expense_date'] ?: date('Y-m-d');
    $vendor_id      = (int)($_POST['vendor_id'] ?? 0);
    $reference_no   = trim($_POST['reference_no'] ?? '');
    $paid_via       = $_POST['paid_via'] ?? 'bank';                    // cash | bank | ap
    $pay_account_no = ($paid_via==='bank'||$paid_via==='cash') ? trim($_POST['pay_account_no'] ?? '') : null;
    if (($paid_via==='bank'||$paid_via==='cash')) {
      if ($pay_account_no === '') {
        throw new RuntimeException('Payment account is required for cash/bank.');
      }
      cleaning_validate_payment_account_no($conn, $pay_account_no);
    }
    $notes          = trim($_POST['notes'] ?? '');
    $vat_mode       = ($_POST['vat_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
    $currency       = 'AED';
    $created_by     = $_SESSION['user_id'] ?? null;
    $expense_type   = in_array($_POST['expense_type'] ?? '', ['prepaid', 'payroll', 'other'], true)
        ? $_POST['expense_type'] : 'operating';
    $prepaid_months = $expense_type === 'prepaid' ? max(1, (int)($_POST['prepaid_months'] ?? 12)) : null;
    $prepaid_expense_account_no = $expense_type === 'prepaid'
        ? trim($_POST['prepaid_expense_account_no'] ?? '') : null;
    $prepaid_asset_account_no = $expense_type === 'prepaid'
        ? trim($_POST['prepaid_asset_account_no'] ?? '') : null;
    if ($expense_type === 'prepaid' && $prepaid_asset_account_no === '') {
        $prepaid_asset_account_no = sm_prepaid_asset_account($conn);
    }
    $save_action    = $_POST['save_action'] ?? 'post';

    // lines
    $acc_nos   = $_POST['line_account_no'] ?? [];
    $descs     = $_POST['line_desc'] ?? [];
    $qtys      = $_POST['line_qty'] ?? [];
    $costs     = $_POST['line_cost'] ?? [];
    $vatrates  = $_POST['line_vat_rate'] ?? [];

    $lines = [];
    $subtotal=0; $vat=0; $total=0;
    for ($i=0; $i<count($acc_nos); $i++) {
      $a = trim($acc_nos[$i] ?? ''); if ($a==='') continue;
      $d = trim($descs[$i] ?? '');
      $q = (float)($qtys[$i] ?? 0);
      $c = (float)($costs[$i] ?? 0);
      $vr= (float)($vatrates[$i] ?? 0);
      if ($q<=0 || $c<0) continue;

      $am = expense_compute_line_amounts($q, $c, $vr, $vat_mode);
      $ls = $am['line_subtotal'];
      $lv = $am['line_vat'];
      $lt = $am['line_total'];

      $lines[] = ['account_no'=>$a,'description'=>$d,'qty'=>$q,'unit_cost'=>$c,'vat_rate'=>$vr,'line_subtotal'=>$ls,'line_vat'=>$lv,'line_total'=>$lt];
      $subtotal += $ls; $vat += $lv; $total += $lt;
    }

    if (!$lines) throw new RuntimeException("Add at least one expense line.");

    $conn->beginTransaction();

    sm_ensure_prepaid_asset_column($conn);

    // header (draft first; post via sm_expense_post_to_gl when allowed)
    $insH = $conn->prepare("
      INSERT INTO expenses
        (expense_date,vendor_id,reference_no,paid_via,pay_account_no,currency,subtotal,vat_amount,total,vat_mode,notes,status,expense_type,prepaid_months,prepaid_expense_account_no,prepaid_asset_account_no,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?, 'draft',?,?,?,?,?)
    ");
    $insH->execute([
        $expense_date, $vendor_id ?: null, $reference_no, $paid_via, $pay_account_no, $currency,
        $subtotal, $vat, $total, $vat_mode, $notes, $expense_type, $prepaid_months,
        $prepaid_expense_account_no ?: null, $prepaid_asset_account_no ?: null, $created_by,
    ]);
    $expId = (int)$conn->lastInsertId();

    // lines
    $insL = $conn->prepare("
      INSERT INTO expense_lines (expense_id,line_no,account_no,description,qty,unit_cost,line_subtotal,vat_rate,line_vat,line_total)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $n=1;
    foreach ($lines as $r) {
      $insL->execute([$expId,$n++,$r['account_no'],$r['description'],$r['qty'],$r['unit_cost'],$r['line_subtotal'],$r['vat_rate'],$r['line_vat'],$r['line_total']]);
    }

    $conn->commit();

    // Move temporary attachments to permanent location
    $tempFileIds = [];
    if (!empty($_POST['temp_file_ids'])) {
        $tempFileIds = json_decode($_POST['temp_file_ids'], true) ?? [];
    }
    
    if (!empty($_SESSION['expense_temp_files']) && is_array($_SESSION['expense_temp_files']) && !empty($tempFileIds)) {
        $uploadDir = __DIR__ . '/../uploads/expenses/' . $expId;
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        $tempDir = __DIR__ . '/../uploads/temp/expenses/';
        
        foreach ($tempFileIds as $fileId) {
            if (isset($_SESSION['expense_temp_files'][$fileId])) {
                $fileInfo = $_SESSION['expense_temp_files'][$fileId];
                $tempPath = $tempDir . $fileInfo['temp_path'];
                if (file_exists($tempPath)) {
                    // Create permanent filename
                    $ext = pathinfo($fileInfo['original_name'], PATHINFO_EXTENSION);
                    $base = pathinfo($fileInfo['original_name'], PATHINFO_FILENAME);
                    $base = preg_replace('/[^a-zA-Z0-9_\-.]+/', '_', $base);
                    $store = $base . '-' . time() . '-' . mt_rand(100, 999) . ($ext ? '.' . $ext : '');
                    $destPath = $uploadDir . '/' . $store;
                    
                    if (rename($tempPath, $destPath)) {
                        // Save to database
                        $relPath = 'uploads/expenses/' . $expId . '/' . $store;
                        $stmt = $conn->prepare("INSERT INTO expense_attachments (expense_id, file_name, file_path, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$expId, $fileInfo['original_name'], $relPath, $fileInfo['type'] ?? null, $fileInfo['size'], $created_by]);
                    }
                }
            }
        }
        // Clear temp files from session
        unset($_SESSION['expense_temp_files']);
    }

    // Audit Log: Track expense creation
    require_once __DIR__ . '/../includes/AuditService.php';
    AuditService::logCreate('expenses', $expId, [
      'expense_date' => $expense_date,
      'vendor_id' => $vendor_id,
      'reference_no' => $reference_no,
      'total' => $total,
      'paid_via' => $paid_via
    ], "Created expense #{$expId} for " . number_format($total, 2) . " AED");

    if ($requiresApproval && $save_action === 'draft') {
        header('Location: expenses.php?ok=draft');
        exit;
    }
    if ($requiresApproval && $save_action === 'submit') {
        sm_expense_submit_for_approval($conn, $expId);
        header('Location: expenses.php?ok=submitted');
        exit;
    }
    if ($requiresApproval && !$canApprove) {
        sm_expense_submit_for_approval($conn, $expId);
        header('Location: expenses.php?ok=submitted');
        exit;
    }

    $postResult = sm_expense_post_to_gl($conn, $expId, $created_by);
    if (!$postResult['success']) {
        throw new RuntimeException($postResult['message']);
    }

    header('Location: expenses.php?ok=1');
    exit;

  } catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Add Expense</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .table tfoot td{font-weight:600}
  .card {border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: box-shadow 0.3s;}
  .card:hover {box-shadow: 0 4px 12px rgba(0,0,0,0.12);}
  .card-header {font-weight: 600;}
  .form-control:focus, .form-select:focus {border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);}
  .form-control-sm:focus, .form-select-sm:focus {border-color: #0d6efd; box-shadow: 0 0 0 0.15rem rgba(13, 110, 253, 0.15);}
  .btn {transition: all 0.2s;}
  .attachment-item {transition: transform 0.2s;}
  .attachment-item:hover {transform: translateY(-2px);}
  .line-item-row {transition: background-color 0.2s;}
  .line-item-row:hover {background-color: #f8f9fa;}
  .table tfoot {background-color: #f8f9fa;}
  .table tfoot th {font-size: 1.1em;}
  input[type="number"]::-webkit-inner-spin-button, input[type="number"]::-webkit-outer-spin-button {opacity: 1;}
  .vr {min-width: 80px !important;}
</style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center mb-4">
    <a href="expenses.php" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <h3 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i>Add Expense</h3>
  </div>

  <?php if($err): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= h($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

  <form method="post" id="expForm">
<?php csrf_field(); ?>
    <div class="card shadow-sm">
      <div class="card-header bg-white border-bottom">
        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Expense Details</h5>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="expense_date" value="<?= h(date('Y-m-d')) ?>" required>
          </div>
          <div class="col-md-5">
            <label class="form-label fw-semibold">Supplier</label>
            <div class="input-group">
              <select class="form-select" name="vendor_id" id="vendor_id">
                <option value="">-- none --</option>
                <?php foreach($vendors as $v): ?>
                  <option value="<?= (int)$v['id'] ?>"><?= h($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#vendorModal">
                <i class="bi bi-plus-circle me-1"></i>Add
              </button>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Reference #</label>
            <input class="form-control" name="reference_no" placeholder="Bill/Receipt number">
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Paid Via <span class="text-danger">*</span></label>
            <select class="form-select" name="paid_via" id="paid_via">
              <option value="bank">Bank</option>
              <option value="cash">Cash</option>
              <option value="ap">Accounts Payable</option>
            </select>
          </div>
          <div class="col-md-4" id="pay_ac_wrap">
            <label class="form-label fw-semibold">Payment Account <span class="text-danger">*</span></label>
            <select class="form-select" name="pay_account_no" id="pay_account_no">
              <option value="">— Select —</option>
              <?php foreach($cashBank as $a): ?>
                <option value="<?= h($a['account_no']) ?>"><?= h($a['account_no'].' – '.$a['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text"><i class="bi bi-info-circle me-1"></i>Used when Paid Via = Bank/Cash</div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Notes</label>
            <input class="form-control" name="notes" placeholder="Additional notes...">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Expense Type</label>
            <select class="form-select" name="expense_type" id="expense_type">
              <option value="operating">Operating (expense now)</option>
              <option value="prepaid">Prepaid (amortize monthly)</option>
              <option value="payroll">Payroll</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-4 prepaid-only d-none">
            <label class="form-label fw-semibold">Amortize over (months)</label>
            <input type="number" class="form-control" name="prepaid_months" id="prepaid_months" min="1" max="60" value="12">
          </div>
          <div class="col-md-4 prepaid-only d-none">
            <label class="form-label fw-semibold">Prepaid asset account</label>
            <select class="form-select" name="prepaid_asset_account_no" id="prepaid_asset_account_no" required>
              <?php foreach ($prepaidAssets as $a): ?>
                <option value="<?= h($a['account_no']) ?>" <?= $a['account_no'] === $defaultPrepaidAsset ? 'selected' : '' ?>>
                  <?= h($a['account_no'].' – '.$a['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Balance sheet account under Prepaid Expenses (rent, insurance, deposits, etc.).</div>
          </div>
          <div class="col-md-4 prepaid-only d-none">
            <label class="form-label fw-semibold">Expense account (amortization)</label>
            <select class="form-select" name="prepaid_expense_account_no" id="prepaid_expense_account_no">
              <option value="">— from first line —</option>
              <?php foreach ($expAccts as $a): ?>
                <option value="<?= h($a['account_no']) ?>"><?= h($a['account_no'].' – '.$a['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Monthly amortization Dr this expense, Cr the prepaid asset above.</div>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">VAT on amounts</label>
            <div class="d-flex flex-wrap gap-3 align-items-center">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="vat_mode" id="vat_mode_exclusive" value="exclusive" checked>
                <label class="form-check-label" for="vat_mode_exclusive">Excluding VAT (exclusive)</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="vat_mode" id="vat_mode_inclusive" value="inclusive">
                <label class="form-check-label" for="vat_mode_inclusive">Including VAT (inclusive)</label>
              </div>
            </div>
            <div class="form-text" id="vatModeHint">Unit cost is <strong>ex VAT</strong>; VAT is added on top.</div>
          </div>
        </div>
      </div>
    </div>

<div class="card mt-3 shadow-sm">
  <div class="card-header bg-white border-bottom">
    <div class="d-flex align-items-center">
      <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i>Attachments</h5>
      <small class="text-muted ms-2">PDF, images, DOC/XLS (max 20MB)</small>
      <div class="ms-auto">
        <input type="file" id="attFile" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx" multiple hidden>
        <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('attFile').click()">
          <i class="bi bi-plus-circle me-1"></i>Upload
        </button>
      </div>
    </div>
  </div>
  <div class="card-body">
    <div id="attList" class="row g-2"></div>
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
            <tbody></tbody>
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
        <button type="button" class="btn btn-outline-primary mt-3" id="addLine">
          <i class="bi bi-plus-circle me-1"></i>Add Line
        </button>
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
      <div class="text-muted small">
        <i class="bi bi-info-circle me-1"></i>Attachments will be saved when you submit the form
        <?php if ($requiresApproval): ?>
          <br><span class="text-warning">Approval workflow is on — submit for review or post directly if you are Admin/Accountant.</span>
        <?php endif; ?>
      </div>
      <div class="d-flex gap-2">
        <input type="hidden" name="save_action" id="save_action" value="post">
        <?php if ($requiresApproval): ?>
          <button type="submit" class="btn btn-outline-secondary" onclick="document.getElementById('save_action').value='draft'">
            Save Draft
          </button>
          <button type="submit" class="btn btn-warning" onclick="document.getElementById('save_action').value='submit'">
            Submit for Approval
          </button>
        <?php endif; ?>
        <?php if (!$requiresApproval || $canApprove): ?>
          <button type="submit" class="btn btn-success px-4" onclick="document.getElementById('save_action').value='post'">
            <i class="bi bi-check-circle me-2"></i>Save &amp; Post
          </button>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="vendorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><form class="modal-content" id="vendorForm">
    <div class="modal-header"><h5 class="modal-title">Add Supplier</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Name *</label><input class="form-control" name="name" required></div>
      <div class="mb-2"><label class="form-label">TRN</label><input class="form-control" name="trn"></div>
      <div class="mb-2"><label class="form-label">Phone</label><input class="form-control" name="phone"></div>
      <div class="mb-2"><label class="form-label">Email</label><input class="form-control" type="email" name="email"></div>
      <div class="mb-2"><label class="form-label">Address</label><input class="form-control" name="address"></div>
      <div id="vendorErr" class="text-danger" style="display:none"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-success">Save</button><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button></div>
  </form></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const ACCOUNTS = [
  <?php foreach($expAccts as $a): ?>
    {no:<?= json_encode($a['account_no']) ?>, name:<?= json_encode($a['name']) ?>},
  <?php endforeach; ?>
];

const body = document.querySelector('#lineTable tbody');
const addBtn = document.getElementById('addLine');

function esc(s){return String(s).replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
function rowTpl(i){
  const opts = ACCOUNTS.map(a=>`<option value="${esc(a.no)}">${esc(a.no)} – ${esc(a.name)}</option>`).join('');
  return `<tr class="line-item-row">
    <td><select class="form-select form-select-sm acc" name="line_account_no[${i}]" required><option value="">-- Select --</option>${opts}</select></td>
    <td><input class="form-control form-control-sm dsc" name="line_desc[${i}]" placeholder="Description"></td>
    <td><input type="number" step="0.001" min="0" class="form-control form-control-sm qty" name="line_qty[${i}]" value="1.000"></td>
    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm cost" name="line_cost[${i}]" value="0.00"></td>
    <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm vr" name="line_vat_rate[${i}]" value="5.00" placeholder="0.00"></td>
    <td class="text-end"><span class="lt fw-semibold">0.00</span></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger del" title="Remove line"><i class="bi bi-trash"></i></button></td>
  </tr>`;
}
function hook(tr){
  tr.querySelectorAll('input,select').forEach(el=>{
    el.addEventListener('input', recalc);
    el.addEventListener('change', recalc);
  });
  tr.querySelector('.del').addEventListener('click', ()=>{ tr.remove(); recalc(); });
}
function addRow(){
  const i = body.children.length;
  body.insertAdjacentHTML('beforeend', rowTpl(i));
  hook(body.lastElementChild); recalc();
}
addBtn.addEventListener('click', addRow);
addRow(); // start with one row

function lineAmounts(q, c, vr, inclusive){
  const g = q * c;
  if (inclusive) {
    if (vr <= 0) return { ls: g, lv: 0, lt: g };
    const lv = Math.round((g * vr / (100 + vr)) * 100) / 100;
    const ls = Math.round((g - lv) * 100) / 100;
    return { ls, lv, lt: g };
  }
  const ls = g;
  const lv = ls * (vr / 100);
  const lt = ls + lv;
  return { ls, lv, lt };
}
function recalc(){
  const inclusive = document.getElementById('vat_mode_inclusive').checked;
  let sub=0, vat=0, tot=0;
  body.querySelectorAll('tr').forEach(tr=>{
    const qtyEl = tr.querySelector('.qty');
    if (!qtyEl) return;
    const q=parseFloat(tr.querySelector('.qty').value)||0;
    const c=parseFloat(tr.querySelector('.cost').value)||0;
    const vr=parseFloat(tr.querySelector('.vr').value)||0;
    const { ls, lv, lt } = lineAmounts(q, c, vr, inclusive);
    sub+=ls; vat+=lv; tot+=lt;
    tr.querySelector('.lt').textContent = lt.toFixed(2);
    tr.querySelector('.lt').classList.add('fw-semibold');
  });
  document.getElementById('tSub').textContent = sub.toFixed(2);
  document.getElementById('tVat').textContent = vat.toFixed(2);
  document.getElementById('tTot').textContent = tot.toFixed(2);
}
function updateVatModeUi(){
  const inclusive = document.getElementById('vat_mode_inclusive').checked;
  document.getElementById('thUnitCost').textContent = inclusive ? 'Unit cost (incl. VAT)' : 'Unit cost (ex VAT)';
  document.getElementById('vatModeHint').innerHTML = inclusive
    ? 'Unit cost is <strong>gross (incl. VAT)</strong>; VAT is calculated automatically.'
    : 'Unit cost is <strong>ex VAT</strong>; VAT is added on top.';
  recalc();
}
document.querySelectorAll('input[name="vat_mode"]').forEach(el=>{
  el.addEventListener('change', updateVatModeUi);
});
updateVatModeUi();

document.getElementById('paid_via').addEventListener('change', (e)=>{
  const ap = e.target.value==='ap';
  document.getElementById('pay_ac_wrap').style.display = ap ? 'none' : '';
  const pa = document.getElementById('pay_account_no');
  if (pa) pa.required = !ap;
});
(function(){
  const pv = document.getElementById('paid_via');
  const pa = document.getElementById('pay_account_no');
  if (pv && pa) pa.required = pv.value !== 'ap';
})();

// Add supplier (AJAX)
document.getElementById('vendorForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  const res = await fetch('ajax_add_vendor.php', {method:'POST', body:fd});
  const json = await res.json().catch(()=>({success:false,error:'Server error'}));
  const box = document.getElementById('vendorErr');
  if(!json.success){ box.textContent=json.error||'Failed'; box.style.display=''; return; }
  // add to select
  const sel=document.getElementById('vendor_id');
  const opt=document.createElement('option'); opt.value=json.vendor.id; opt.textContent=json.vendor.name;
  sel.appendChild(opt); sel.value=json.vendor.id;
  // close modal
  bootstrap.Modal.getInstance(document.getElementById('vendorModal')).hide();
});
</script>
          <script>
          const attFile = document.getElementById('attFile');
          const attErr  = document.getElementById('attErr');
          const attList = document.getElementById('attList');
          const tempFiles = new Map(); // Store temp file IDs

          function formatBytes(bytes) {
            if (bytes <= 0) return '0 B';
            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            while (bytes >= 1024 && i < units.length - 1) {
              bytes /= 1024;
              i++;
            }
            return (i ? bytes.toFixed(1) : bytes) + ' ' + units[i];
          }

          function renderAttachments() {
            if (tempFiles.size === 0) {
              attList.innerHTML = '<div class="col-12"><div class="text-muted small py-3 text-center"><i class="bi bi-inbox"></i> No attachments yet. Click "+ Upload" to add files.</div></div>';
              return;
            }
            
            attList.innerHTML = '';
            tempFiles.forEach((file, id) => {
              const el = document.createElement('div');
              el.className = 'col-md-4 mb-2 attachment-item';
              el.dataset.id = id;
              el.innerHTML = `
                <div class="card border">
                  <div class="card-body p-2 d-flex align-items-center">
                    <i class="bi bi-file-earmark-text text-primary fs-5 me-2"></i>
                    <div class="flex-grow-1 min-w-0">
                      <div class="fw-semibold text-truncate" title="${file.file_name}">${file.file_name}</div>
                      <small class="text-muted">${file.size_fmt}</small>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="delTempAtt('${id}')" title="Remove">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                </div>`;
              attList.appendChild(el);
            });
          }

          if (attFile) {
            attFile.addEventListener('change', async () => {
              const errBox = document.getElementById('attErr');
              if (errBox) errBox.style.display='none';
              
              const files = Array.from(attFile.files);
              if (!files.length) return;
              
              const fd = new FormData();
              files.forEach(f => fd.append('files[]', f));
              
              // Show loading
              const btn = attFile.parentElement.querySelector('button');
              const originalHtml = btn.innerHTML;
              btn.disabled = true;
              btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading...';
              
              try {
                const r = await fetch('ajax/ajax_expense_attachment_temp_upload.php', { method:'POST', body:fd });
                const text = await r.text();
                let j;
                try {
                  j = JSON.parse(text);
                } catch(e) {
                  console.error('Upload JSON error:', e, 'Response:', text);
                  if (errBox) {
                    errBox.textContent = 'Invalid server response';
                    errBox.style.display = '';
                  }
                  return;
                }
                
                if (j.success && j.files) {
                  attFile.value='';
                  if (errBox) errBox.style.display='none';
                  
                  // Add to temp files
                  j.files.forEach(file => {
                    tempFiles.set(file.id, file);
                  });
                  
                  renderAttachments();
                } else {
                  if (errBox) {
                    errBox.textContent = j.error || 'Upload failed';
                    errBox.style.display = '';
                  }
                }
              } catch(e) {
                console.error('Upload error:', e);
                if (errBox) {
                  errBox.textContent = 'Server error: ' + e.message;
                  errBox.style.display = '';
                }
              } finally {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
              }
            });
          }

          function delTempAtt(id) {
            if (!confirm('Remove this file from the expense?')) return;
            tempFiles.delete(id);
            renderAttachments();
          }

          // Store temp files in hidden input before form submit
          document.getElementById('expForm').addEventListener('submit', function() {
            const tempIds = Array.from(tempFiles.keys());
            if (tempIds.length > 0) {
              let input = document.getElementById('temp_file_ids');
              if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.id = 'temp_file_ids';
                input.name = 'temp_file_ids';
                this.appendChild(input);
              }
              input.value = JSON.stringify(tempIds);
            }
          });

          // Initial render
          renderAttachments();

          const expenseType = document.getElementById('expense_type');
          function togglePrepaidFields() {
            const show = expenseType && expenseType.value === 'prepaid';
            document.querySelectorAll('.prepaid-only').forEach(el => el.classList.toggle('d-none', !show));
          }
          if (expenseType) {
            expenseType.addEventListener('change', togglePrepaidFields);
            togglePrepaidFields();
          }
          </script>
</body>
</html>
