<?php
/**
 * Vendor Payment Made — multi-bill allocation UX (Phase 1).
 * Posting path unchanged: re_ap_post_vendor_payment().
 * UI: Bootstrap 5 + RE layout + Lucide + SweetAlert2 (vanilla JS allocation).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}
$userId = current_user_id();
$success = '';
$error = '';
$createdAdvancePaymentId = 0;
$createdAdvanceVendorId = 0;
$createdAdvanceAmount = 0.0;

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

$vendorId = (int)($_GET['vendor_id'] ?? $_POST['vendor_id'] ?? 0);
$preBill = (int)($_GET['bill_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $vendorId = (int)$_POST['vendor_id'];
        $date = $_POST['payment_date'] ?? date('Y-m-d');
        $method = $_POST['payment_method'] ?? 'bank_transfer';
        // The picker returns a chart-of-accounts id; keep bank_account_id in sync
        // when that GL account is a registered bank account.
        $payAccountId = !empty($_POST['pay_account_id']) ? (int)$_POST['pay_account_id'] : 0;
        $glAccountId = null;
        $bankId = null;
        if ($payAccountId > 0) {
            $accStmt = $conn->prepare("
                SELECT coa.id,
                       (SELECT ba.id FROM re_bank_accounts ba
                        WHERE ba.gl_account_id = coa.id AND ba.company_id = coa.company_id AND ba.is_active = 1
                        ORDER BY ba.id LIMIT 1) AS bank_account_id
                FROM re_chart_of_accounts coa
                WHERE coa.id = ? AND coa.company_id = ? AND coa.is_active = 1 AND COALESCE(coa.is_header, 0) = 0
                LIMIT 1
            ");
            $accStmt->execute([$payAccountId, $companyId]);
            $acc = $accStmt->fetch(PDO::FETCH_ASSOC);
            if (!$acc) {
                throw new RuntimeException('Select a valid bank or cash account.');
            }
            $glAccountId = (int)$acc['id'];
            $bankId = !empty($acc['bank_account_id']) ? (int)$acc['bank_account_id'] : null;
        }
        $ref = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $paymentAmount = round((float)($_POST['payment_amount'] ?? 0), 2);
        $allocs = $_POST['alloc'] ?? [];
        $allocated = 0.0;
        $valid = [];
        foreach ($allocs as $billId => $amt) {
            $amt = (float)$amt;
            if ($amt <= 0) {
                continue;
            }
            $bill = re_ap_load_bill($conn, $companyId, (int)$billId);
            if (!$bill || ((int)$bill['vendor_id'] !== $vendorId)) {
                throw new RuntimeException('Invalid bill selected.');
            }
            re_ap_refresh_bill_status($conn, $companyId, (int)$billId);
            $bill = re_ap_load_bill($conn, $companyId, (int)$billId);
            $bal = (float)($bill['balance_due'] ?? 0);
            if ($amt > $bal + 0.005) {
                throw new RuntimeException('Payment cannot exceed bill balance for ' . $bill['invoice_number']);
            }
            $valid[(int)$billId] = round($amt, 2);
            $allocated += $amt;
        }
        $allocated = round($allocated, 2);
        if ($paymentAmount <= 0) {
            // Backward compatible: if payment_amount omitted, use sum of allocations
            $paymentAmount = $allocated;
        }
        $advanceAmount = round($paymentAmount - $allocated, 2);
        if ($advanceAmount < -0.005) {
            throw new RuntimeException('Allocated amount exceeds payment amount.');
        }
        if ($advanceAmount < 0) {
            $advanceAmount = 0.0;
        }
        if (!$vendorId || $paymentAmount <= 0) {
            throw new RuntimeException('Select vendor and enter a payment amount greater than zero.');
        }
        if ($allocated <= 0 && $advanceAmount <= 0) {
            throw new RuntimeException('Allocate to bills and/or leave a remainder as vendor advance.');
        }
        // gl_account_id lands with migrations/re_vendor_payment_gl_account.sql;
        // stay insertable on a database that has not had it applied yet.
        $glReady = re_ap_payment_gl_column_ready($conn);
        if (!$glReady && $glAccountId !== null && $bankId === null) {
            throw new RuntimeException('Paying from a cash account requires migrations/re_vendor_payment_gl_account.sql to be applied first.');
        }
        $conn->beginTransaction();
        if ($glReady) {
            $stmt = $conn->prepare("
                INSERT INTO re_vendor_payments
                (company_id, vendor_id, payment_date, amount, advance_amount, payment_method, bank_account_id, gl_account_id, reference_number, notes, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
            ");
            $stmt->execute([
                $companyId, $vendorId, $date, $paymentAmount, $advanceAmount, $method,
                $bankId, $glAccountId, $ref ?: null, $notes ?: null, $userId,
            ]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO re_vendor_payments
                (company_id, vendor_id, payment_date, amount, advance_amount, payment_method, bank_account_id, reference_number, notes, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
            ");
            $stmt->execute([
                $companyId, $vendorId, $date, $paymentAmount, $advanceAmount, $method,
                $bankId, $ref ?: null, $notes ?: null, $userId,
            ]);
        }
        $paymentId = (int)$conn->lastInsertId();
        if ($valid) {
            $ins = $conn->prepare("
                INSERT INTO re_vendor_payment_allocations (company_id, vendor_payment_id, vendor_invoice_id, amount_allocated)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($valid as $billId => $amt) {
                $ins->execute([$companyId, $paymentId, $billId, $amt]);
            }
        }
        $post = re_ap_post_vendor_payment($conn, $companyId, $paymentId, $userId);
        if (empty($post['success'])) {
            throw new RuntimeException($post['error'] ?? 'Payment posting failed');
        }
        $conn->commit();
        $msg = 'Vendor payment posted. Journal #' . $post['journal_id'];
        if ($advanceAmount > 0.005) {
            $msg .= '. Advance created: ' . number_format($advanceAmount, 2) . ' AED';
            $createdAdvancePaymentId = $paymentId;
            $createdAdvanceVendorId = $vendorId;
            $createdAdvanceAmount = $advanceAmount;
        }
        $success = $msg;
        $preBill = 0;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = $e->getMessage();
    }
}

$vendors = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? AND status = 'active' ORDER BY vendor_name");
$vendors->execute([$companyId]);
$vendors = $vendors->fetchAll(PDO::FETCH_ASSOC) ?: [];

$bills = [];
if ($vendorId > 0) {
    $stmt = $conn->prepare("
        SELECT id, invoice_number, invoice_date, due_date, total_amount, paid_amount, balance_due, status
        FROM re_vendor_invoices
        WHERE company_id = ? AND vendor_id = ?
          AND posting_status = 'posted'
          AND status NOT IN ('paid', 'void', 'cancelled', 'draft')
          AND COALESCE(balance_due, total_amount - paid_amount) > 0.005
        ORDER BY due_date ASC, id ASC
    ");
    $stmt->execute([$companyId, $vendorId]);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$selectedPayAccountId = ($success === '') ? (int)($_POST['pay_account_id'] ?? 0) : 0;

// Every active cash/bank GL account is payable from, not just the ones
// registered in re_bank_accounts. Registered ones keep their bank name.
$payAccounts = $conn->prepare("
    SELECT coa.id, coa.account_code, coa.account_name,
           (SELECT ba.id FROM re_bank_accounts ba
            WHERE ba.gl_account_id = coa.id AND ba.company_id = coa.company_id AND ba.is_active = 1
            ORDER BY ba.id LIMIT 1) AS bank_account_id,
           (SELECT ba2.account_name FROM re_bank_accounts ba2
            WHERE ba2.gl_account_id = coa.id AND ba2.company_id = coa.company_id AND ba2.is_active = 1
            ORDER BY ba2.id LIMIT 1) AS bank_account_name
    FROM re_chart_of_accounts coa
    WHERE coa.company_id = ?
      AND coa.is_active = 1
      AND COALESCE(coa.is_header, 0) = 0
      AND (coa.account_code LIKE '11%' OR coa.account_code LIKE '12%')
    ORDER BY coa.account_code
");
$payAccounts->execute([$companyId]);
$payAccounts = $payAccounts->fetchAll(PDO::FETCH_ASSOC) ?: [];
$bankOptions = [];
$cashOptions = [];
foreach ($payAccounts as $acc) {
    if (!empty($acc['bank_account_id'])) {
        $bankOptions[] = $acc;
    } else {
        $cashOptions[] = $acc;
    }
}

$billPayload = [];
$prefillTotal = 0.0;
$openBalanceTotal = 0.0;
foreach ($bills as $bill) {
    $bal = (float)($bill['balance_due'] ?? max(0, (float)$bill['total_amount'] - (float)$bill['paid_amount']));
    $openBalanceTotal += $bal;
    $pre = ($preBill === (int)$bill['id']) ? $bal : 0.0;
    if ($pre > 0) {
        $prefillTotal += $pre;
    }
    $billPayload[] = [
        'id' => (int)$bill['id'],
        'invoice_number' => (string)$bill['invoice_number'],
        'invoice_date' => (string)($bill['invoice_date'] ?? ''),
        'due_date' => (string)$bill['due_date'],
        'total' => (float)$bill['total_amount'],
        'balance' => $bal,
        'selected' => $pre > 0,
        'amount' => $pre,
    ];
}

$vendorAdvanceBal = ($vendorId > 0 && function_exists('re_ap_vendor_advance_balance'))
    ? re_ap_vendor_advance_balance($conn, $companyId, $vendorId)
    : 0.0;

$reApUiEnhanced = true;
$pageTitle = 'Payment Made';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i data-lucide="check-circle" class="me-1"></i><?= h($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php if ($createdAdvancePaymentId > 0 && function_exists('re_ap_advance_vat_table_ready') && re_ap_advance_vat_table_ready($conn)): ?>
        <div class="alert alert-info">
            Optional: if you have a supplier VAT document for this advance, record it separately.
            <a class="btn btn-sm btn-outline-primary ms-2"
               href="vendor_advance_vat_document_edit.php?vendor_id=<?= (int)$createdAdvanceVendorId ?>&payment_id=<?= (int)$createdAdvancePaymentId ?>">
                Add Advance VAT Document
            </a>
            <div class="form-text mt-1">VAT is never inferred from the payment. Payment journal stays Dr 1410 / Cr Bank.</div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i data-lucide="alert-circle" class="me-1"></i><?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="page-header-label mb-0">
        <i data-lucide="banknote" class="me-1"></i>
        Payment Made
    </div>
    <div class="d-flex gap-2 re-ap-print-hide">
        <?php if ($vendorId): ?>
            <a href="vendor_statement.php?vendor_id=<?= (int)$vendorId ?>" class="btn btn-outline-primary btn-sm">
                <i data-lucide="file-text" class="me-1"></i> Vendor SOA
            </a>
        <?php endif; ?>
        <a href="vendor_bills.php<?= $vendorId ? ('?vendor_id=' . (int)$vendorId) : '' ?>" class="btn btn-outline-secondary btn-sm">
            <i data-lucide="receipt" class="me-1"></i> Vendor Bills
        </a>
        <a href="vendor_payments.php" class="btn btn-outline-secondary btn-sm">
            <i data-lucide="list" class="me-1"></i> Payments List
        </a>
    </div>
</div>

<form method="post" id="vendorPaymentForm">
    <?php csrf_field(); ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card card-round mb-3">
                <div class="card-header bg-white py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i data-lucide="wallet"></i>
                        <strong>Payment Details</strong>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Vendor <span class="text-danger">*</span></label>
                            <select name="vendor_id" class="form-select" required
                                    onchange="location.href='vendor_payment_add.php?vendor_id='+encodeURIComponent(this.value)">
                                <option value="">-- Select vendor --</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>>
                                        <?= h($v['vendor_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Bank / Cash Account</label>
                            <select name="pay_account_id" class="form-select">
                                <option value="">Default for payment method</option>
                                <?php if ($bankOptions): ?>
                                    <optgroup label="Bank accounts">
                                        <?php foreach ($bankOptions as $b): ?>
                                            <option value="<?= (int)$b['id'] ?>"<?= ((int)$b['id'] === $selectedPayAccountId) ? ' selected' : '' ?>><?= h($b['account_code'] . ' — ' . ($b['bank_account_name'] ?: $b['account_name'])) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <?php if ($cashOptions): ?>
                                    <optgroup label="Cash &amp; other accounts">
                                        <?php foreach ($cashOptions as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>"<?= ((int)$c['id'] === $selectedPayAccountId) ? ' selected' : '' ?>><?= h($c['account_code'] . ' — ' . $c['account_name']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Reference</label>
                            <input name="reference_number" class="form-control" placeholder="Cheque / transfer ref">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Notes</label>
                            <input name="notes" class="form-control" placeholder="Optional">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-round mb-3">
                <div class="card-header bg-white py-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <i data-lucide="sparkles"></i>
                            <strong>Allocate Payment</strong>
                        </div>
                        <span class="badge text-bg-light border"><?= count($billPayload) ?> open bill(s)</span>
                    </div>
                </div>
                <div class="card-body border-bottom">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Payment Amount (AED)</label>
                            <input type="number" step="0.01" min="0" name="payment_amount" id="payAmount"
                                   class="form-control form-control-lg"
                                   value="<?= $prefillTotal > 0 ? h(number_format($prefillTotal, 2, '.', '')) : '' ?>"
                                   placeholder="0.00">
                            <div class="form-text">
                                Open bill balance: <strong><?= m($openBalanceTotal) ?></strong> AED
                                <?php if ($vendorId): ?>
                                    · Existing advance: <strong><?= m($vendorAdvanceBal) ?></strong> AED
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="re-ap-toolbar d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-primary" id="btnAutoFifo" <?= $billPayload ? '' : 'disabled' ?>>
                                    <i data-lucide="wand-2" class="me-1"></i> Auto Allocate (FIFO)
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="btnClearAlloc" <?= $billPayload ? '' : 'disabled' ?>>
                                    <i data-lucide="eraser" class="me-1"></i> Clear
                                </button>
                                <button type="button" class="btn btn-outline-dark" id="btnApplyRemaining" <?= $billPayload ? '' : 'disabled' ?>>
                                    <i data-lucide="between-horizontal-start" class="me-1"></i> Apply Remaining to Selected
                                </button>
                                <span class="badge align-self-center bg-light text-dark border" id="allocModeBadge">Mode: Manual</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover re-ap-table mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:2.75rem;" class="text-center">
                                    <input type="checkbox" class="form-check-input" id="chkAllBills" <?= $billPayload ? '' : 'disabled' ?>>
                                </th>
                                <th>Bill #</th>
                                <th>Bill Date</th>
                                <th>Due Date</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Balance</th>
                                <th class="text-end" style="min-width:8.5rem;">Pay Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($billPayload): ?>
                                <?php foreach ($billPayload as $bill): ?>
                                    <tr data-bill-row data-balance="<?= h((string)$bill['balance']) ?>">
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input bill-select"
                                                   <?= !empty($bill['selected']) ? 'checked' : '' ?>>
                                        </td>
                                        <td>
                                            <a href="vendor_bill_view.php?id=<?= (int)$bill['id'] ?>" class="fw-semibold text-decoration-none">
                                                <?= h($bill['invoice_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= h($bill['invoice_date']) ?></td>
                                        <td><?= h($bill['due_date']) ?></td>
                                        <td class="text-end"><?= m($bill['total']) ?></td>
                                        <td class="text-end text-danger fw-semibold"><?= m($bill['balance']) ?></td>
                                        <td>
                                            <input type="number" step="0.01" min="0"
                                                   class="form-control form-control-sm text-end bill-amount"
                                                   name="alloc[<?= (int)$bill['id'] ?>]"
                                                   max="<?= h((string)$bill['balance']) ?>"
                                                   value="<?= ((float)$bill['amount'] > 0) ? h(number_format((float)$bill['amount'], 2, '.', '')) : '' ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-5">
                                        <?php if ($vendorId <= 0): ?>
                                            Select a vendor to load open posted bills.
                                        <?php else: ?>
                                            No open posted bills for this vendor.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-round re-ap-alloc-summary" id="allocSummaryCard">
                <div class="card-header bg-white py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i data-lucide="calculator"></i>
                        <strong>Allocation Summary</strong>
                    </div>
                </div>
                <div class="card-body">
                    <div class="re-ap-summary-line d-flex justify-content-between mb-2">
                        <span class="text-muted">Payment Amount</span>
                        <strong id="sumPayment">0.00 AED</strong>
                    </div>
                    <div class="re-ap-summary-line d-flex justify-content-between mb-2">
                        <span class="text-muted">Allocated to Bills</span>
                        <strong id="sumAllocated">0.00 AED</strong>
                    </div>
                    <div class="re-ap-summary-line d-flex justify-content-between mb-3">
                        <span class="text-muted">To Vendor Advance</span>
                        <strong id="sumAdvance">0.00 AED</strong>
                    </div>

                    <div class="progress mb-3" style="height:.55rem;">
                        <div class="progress-bar bg-success" id="barBills" role="progressbar" style="width:0%"></div>
                        <div class="progress-bar bg-primary" id="barAdvance" role="progressbar" style="width:0%"></div>
                    </div>

                    <p class="small text-muted mb-3">
                        Fully, partially, or unallocated payments are allowed.
                        Any remainder posts to <strong>Vendor Advances (1410)</strong> for later application to bills.
                    </p>

                    <div class="alert alert-warning py-2 small mb-2 d-none" id="warnOver">
                        Allocated exceeds payment amount. Reduce bill amounts before posting.
                    </div>
                    <div class="alert alert-info py-2 small mb-3 d-none" id="infoAdvance">
                        <span id="infoAdvanceAmt">0.00</span> AED will be retained as vendor advance.
                    </div>

                    <button type="submit" class="btn btn-success w-100" id="btnPostPayment" <?= $vendorId ? '' : 'disabled' ?> disabled>
                        <i data-lucide="check" class="me-1"></i> Post Payment
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
  function round2(n) { return Math.round((Number(n) || 0) * 100) / 100; }
  function fmt(n) {
    return round2(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function notify(title, text) {
    if (window.Swal) {
      Swal.fire({ icon: 'info', title: title, text: text });
    } else {
      alert(title + (text ? '\n' + text : ''));
    }
  }

  var form = document.getElementById('vendorPaymentForm');
  var payInput = document.getElementById('payAmount');
  var modeBadge = document.getElementById('allocModeBadge');
  var summaryCard = document.getElementById('allocSummaryCard');
  var sumPayment = document.getElementById('sumPayment');
  var sumAllocated = document.getElementById('sumAllocated');
  var sumAdvance = document.getElementById('sumAdvance');
  var barBills = document.getElementById('barBills');
  var barAdvance = document.getElementById('barAdvance');
  var warnOver = document.getElementById('warnOver');
  var infoAdvance = document.getElementById('infoAdvance');
  var infoAdvanceAmt = document.getElementById('infoAdvanceAmt');
  var btnPost = document.getElementById('btnPostPayment');
  var chkAll = document.getElementById('chkAllBills');
  var mode = 'manual';

  if (!form || !payInput) return;

  function rows() {
    return Array.prototype.slice.call(form.querySelectorAll('[data-bill-row]'));
  }

  function recalc() {
    var pay = round2(payInput.value);
    var allocated = 0;
    rows().forEach(function (row) {
      var amtInput = row.querySelector('.bill-amount');
      allocated += round2(amtInput && amtInput.value);
    });
    allocated = round2(allocated);
    var remaining = round2(pay - allocated);
    var toAdvance = remaining > 0.005 ? remaining : 0;
    var over = allocated > 0.005 && allocated > pay + 0.005;
    var fully = allocated > 0.005 && !over && Math.abs(remaining) < 0.005;
    var canPost = pay > 0.005 && !over && (allocated > 0.005 || toAdvance > 0.005);
    var billPct = pay <= 0 ? 0 : Math.min(100, Math.round((allocated / pay) * 100));
    var advPct = pay <= 0 ? 0 : Math.min(100 - billPct, Math.round((toAdvance / pay) * 100));

    sumPayment.textContent = fmt(pay) + ' AED';
    sumAllocated.textContent = fmt(allocated) + ' AED';
    sumAdvance.textContent = fmt(toAdvance) + ' AED';
    sumAdvance.classList.toggle('text-primary', toAdvance > 0.005);
    barBills.style.width = billPct + '%';
    barAdvance.style.width = advPct + '%';
    warnOver.classList.toggle('d-none', !over);
    infoAdvance.classList.toggle('d-none', !(toAdvance > 0.005 && !over));
    infoAdvanceAmt.textContent = fmt(toAdvance);
    summaryCard.classList.toggle('warn', over);
    summaryCard.classList.toggle('ok', fully);
    if (btnPost && <?= $vendorId > 0 ? 'true' : 'false' ?>) {
      btnPost.disabled = !canPost;
    }
    modeBadge.textContent = 'Mode: ' + (mode === 'fifo' ? 'Auto FIFO' : 'Manual');
    if (chkAll) {
      var list = rows();
      chkAll.checked = list.length > 0 && list.every(function (r) {
        var c = r.querySelector('.bill-select');
        return c && c.checked;
      });
    }
  }

  function autoFifo() {
    var left = round2(payInput.value);
    if (left <= 0) {
      notify('Enter payment amount first', 'Type the bank payment total, then click Auto Allocate.');
      return;
    }
    mode = 'fifo';
    rows().forEach(function (row) {
      var bal = round2(row.getAttribute('data-balance'));
      var amtInput = row.querySelector('.bill-amount');
      var sel = row.querySelector('.bill-select');
      if (left <= 0.005) {
        amtInput.value = '';
        if (sel) sel.checked = false;
        return;
      }
      var take = round2(Math.min(bal, left));
      amtInput.value = take > 0 ? take.toFixed(2) : '';
      if (sel) sel.checked = take > 0;
      left = round2(left - take);
    });
    recalc();
  }

  function clearAlloc() {
    mode = 'manual';
    rows().forEach(function (row) {
      var amtInput = row.querySelector('.bill-amount');
      var sel = row.querySelector('.bill-select');
      if (amtInput) amtInput.value = '';
      if (sel) sel.checked = false;
    });
    recalc();
  }

  function applyRemainingToSelected() {
    var pay = round2(payInput.value);
    var allocated = 0;
    rows().forEach(function (row) {
      allocated += round2(row.querySelector('.bill-amount').value);
    });
    var left = round2(pay - allocated);
    if (left <= 0.005) {
      notify('Nothing to apply', 'Enter a payment amount first (and leave some unallocated).');
      return;
    }
    var any = rows().some(function (row) {
      var c = row.querySelector('.bill-select');
      return c && c.checked;
    });
    if (!any) {
      notify('Select bills first', 'Tick one or more bills, then apply the remaining amount.');
      return;
    }
    mode = 'manual';
    rows().forEach(function (row) {
      var sel = row.querySelector('.bill-select');
      if (!sel || !sel.checked || left <= 0.005) return;
      var bal = round2(row.getAttribute('data-balance'));
      var amtInput = row.querySelector('.bill-amount');
      var cur = round2(amtInput.value);
      var room = round2(bal - cur);
      if (room <= 0.005) return;
      var take = round2(Math.min(room, left));
      amtInput.value = round2(cur + take).toFixed(2);
      left = round2(left - take);
    });
    recalc();
  }

  payInput.addEventListener('input', function () {
    mode = 'manual';
    recalc();
  });

  rows().forEach(function (row) {
    var amtInput = row.querySelector('.bill-amount');
    var sel = row.querySelector('.bill-select');
    if (amtInput) {
      amtInput.addEventListener('input', function () {
        mode = 'manual';
        var bal = round2(row.getAttribute('data-balance'));
        var v = round2(amtInput.value);
        if (v > bal) {
          amtInput.value = bal.toFixed(2);
          v = bal;
        }
        if (v < 0) {
          amtInput.value = '';
          v = 0;
        }
        if (sel && v > 0) sel.checked = true;
        recalc();
      });
    }
    if (sel) {
      sel.addEventListener('change', function () {
        mode = 'manual';
        recalc();
      });
    }
  });

  if (chkAll) {
    chkAll.addEventListener('change', function () {
      mode = 'manual';
      var on = !!chkAll.checked;
      rows().forEach(function (row) {
        var sel = row.querySelector('.bill-select');
        if (sel) sel.checked = on;
      });
      recalc();
    });
  }

  var btnFifo = document.getElementById('btnAutoFifo');
  var btnClear = document.getElementById('btnClearAlloc');
  var btnApply = document.getElementById('btnApplyRemaining');
  if (btnFifo) btnFifo.addEventListener('click', autoFifo);
  if (btnClear) btnClear.addEventListener('click', clearAlloc);
  if (btnApply) btnApply.addEventListener('click', applyRemainingToSelected);

  form.addEventListener('submit', function (e) {
    recalc();
    if (btnPost && btnPost.disabled) {
      e.preventDefault();
      notify('Cannot post yet', 'Enter a payment amount and ensure allocations do not exceed it.');
      return;
    }
    if (!window.Swal) return;
    e.preventDefault();
    var pay = round2(payInput.value);
    var allocated = 0;
    rows().forEach(function (row) {
      allocated += round2(row.querySelector('.bill-amount').value);
    });
    allocated = round2(allocated);
    var toAdvance = round2(Math.max(0, pay - allocated));
    Swal.fire({
      title: 'Post vendor payment?',
      html: '<div class="text-start small">Payment <strong>' + fmt(pay) +
        ' AED</strong><br>Allocated to bills: <strong>' + fmt(allocated) +
        ' AED</strong><br>To vendor advance: <strong>' + fmt(toAdvance) + ' AED</strong></div>',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Post Payment',
      confirmButtonColor: '#198754'
    }).then(function (r) {
      if (r.isConfirmed) {
        HTMLFormElement.prototype.submit.call(form);
      }
    });
  });

  recalc();
})();
</script>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
