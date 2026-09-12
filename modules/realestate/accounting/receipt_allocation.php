<?php
/**
 * Receipt allocation for one Invoice Mode lease.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/receipt_allocation_engine.php';
require_once __DIR__ . '/../includes/invoice_engine.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function p4_money($n): string { return number_format((float)$n, 2); }
function p4_tenant_name(array $row): string
{
    if (($row['tenant_type'] ?? '') === 'company') {
        return (string)($row['company_name'] ?? 'Company Tenant');
    }
    return trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')) ?: 'Tenant';
}

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$leaseId = (int)($_GET['lease_id'] ?? 0);
$chequeId = (int)($_GET['cheque_id'] ?? 0);
$amountInput = trim((string)($_GET['amount'] ?? ''));
$amount = $amountInput !== '' ? (float)$amountInput : 0.0;
$receiptSourceProvided = array_key_exists('receipt_source', $_GET);
$receiptSource = (string)($_GET['receipt_source'] ?? 'bank_transfer');
$clearedDate = (string)($_GET['cleared_date'] ?? date('Y-m-d'));
$receiptAccount = (string)($_GET['receipt_account'] ?? '');
$referenceNumber = (string)($_GET['reference_number'] ?? '');
$notes = (string)($_GET['notes'] ?? '');
$preferExtraService = !empty($_GET['prefer_extra_service']);
$serviceChargeIdPref = (int)($_GET['service_charge_id'] ?? 0);
$billingItemIdPref = (int)($_GET['billing_item_id'] ?? 0);
$selectedCheque = null;
$chequeSummary = null;
$preview = null;
$error = '';
$flash = $_SESSION['re_receipt_allocation_flash'] ?? null;
unset($_SESSION['re_receipt_allocation_flash']);

if ($chequeId > 0) {
    try {
        $selectedCheque = re_cheque_load($conn, $companyId, $chequeId);
        if ($selectedCheque) {
            $leaseId = (int)$selectedCheque['lease_id'];
            $chequeSummary = re_cheque_receipt_summary($conn, $companyId, $chequeId);
            if ($amountInput === '') {
                $amount = $chequeSummary['remaining'] > 0.005
                    ? $chequeSummary['remaining']
                    : (float)($selectedCheque['cheque_amount'] ?? 0);
                $amountInput = number_format($amount, 2, '.', '');
            } elseif ($chequeSummary['remaining'] > 0.005
                && abs($amount - (float)($selectedCheque['cheque_amount'] ?? 0)) < 0.01) {
                $amount = $chequeSummary['remaining'];
                $amountInput = number_format($amount, 2, '.', '');
            }
            if (!$receiptSourceProvided) {
                $receiptSource = 'cleared_cheque';
            }
            if ($referenceNumber === '') {
                $referenceNumber = (string)($selectedCheque['reference_number'] ?? $selectedCheque['cheque_number'] ?? '');
            }
            if ($notes === '') {
                $notes = 'Receipt from cleared cheque ' . (string)($selectedCheque['cheque_number'] ?? ('#' . $chequeId));
            }
        }
    } catch (Throwable $e) {
        $selectedCheque = null;
    }
}

$leases = [];
if (re_obligation_column_exists($conn, 're_leases', 'accounting_mode')) {
    $leasesStmt = $conn->prepare("
        SELECT l.id, l.lease_number, l.start_date, l.end_date, l.accounting_mode,
               t.first_name, t.last_name, t.company_name, t.tenant_type,
               u.unit_number, b.name AS building_name
        FROM re_leases l
        JOIN re_tenants t ON t.id = l.tenant_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE l.company_id = ? AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
        ORDER BY l.id DESC
        LIMIT 250
    ");
    $leasesStmt->execute([$companyId]);
    $leases = $leasesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Always include the active/context lease even when it is older than the latest 250 invoice-mode leases.
    if ($leaseId > 0) {
        $leaseInList = false;
        foreach ($leases as $leaseRow) {
            if ((int)$leaseRow['id'] === $leaseId) {
                $leaseInList = true;
                break;
            }
        }
        if (!$leaseInList) {
            $contextLeaseStmt = $conn->prepare("
                SELECT l.id, l.lease_number, l.start_date, l.end_date, l.accounting_mode,
                       t.first_name, t.last_name, t.company_name, t.tenant_type,
                       u.unit_number, b.name AS building_name
                FROM re_leases l
                JOIN re_tenants t ON t.id = l.tenant_id
                JOIN re_units u ON u.id = l.unit_id
                JOIN re_buildings b ON b.id = u.building_id
                WHERE l.company_id = ? AND l.id = ?
                LIMIT 1
            ");
            $contextLeaseStmt->execute([$companyId, $leaseId]);
            $contextLease = $contextLeaseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($contextLease && re_accounting_normalize_mode((string)($contextLease['accounting_mode'] ?? '')) === 'invoice') {
                array_unshift($leases, $contextLease);
            }
        }
    }
} else {
    $error = 'Phase 1 accounting_mode migration has not been applied yet.';
}

$lockLeaseSelection = $chequeId > 0;

$bankAccounts = [];
$cashAccounts = [];
try {
    $stmt = $conn->prepare("
        SELECT ba.id, ba.account_name, ba.bank_name, ba.account_number
        FROM re_bank_accounts ba
        WHERE ba.company_id = ? AND ba.is_active = 1
        ORDER BY ba.account_name
    ");
    $stmt->execute([$companyId]);
    $bankAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $bankAccounts = [];
}
try {
    $stmt = $conn->prepare("
        SELECT id, account_code, account_name
        FROM re_chart_of_accounts
        WHERE company_id = ? AND account_code IN ('1110', '1141') AND is_active = 1
        ORDER BY account_code
    ");
    $stmt->execute([$companyId]);
    $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $cashAccounts = [];
}

$receiptAccountType = null;
$receiptAccountId = null;
if (preg_match('/^(bank|cash|card_clearing):(\d+)$/', $receiptAccount, $accountMatch)) {
    $receiptAccountType = $accountMatch[1];
    $receiptAccountId = (int)$accountMatch[2];
}

if ($leaseId > 0) {
    $autoIssue = re_invoice_engine_issue_eligible_for_lease($conn, $companyId, $leaseId, current_user_id());
    if (empty($autoIssue['success']) && !empty($autoIssue['error'])) {
        error_log('Auto issue eligible invoices before receipt allocation failed: ' . $autoIssue['error']);
    }
    $preview = re_receipt_preview($conn, $companyId, $leaseId, $amount, $chequeId, [
        'prefer_extra_service' => $preferExtraService,
        'service_charge_id' => $serviceChargeIdPref,
        'billing_item_id' => $billingItemIdPref,
    ]);
    if (empty($preview['success'])) {
        $error = (string)($preview['error'] ?? 'Could not build receipt allocation preview.');
        $preview = null;
    } elseif ($chequeId > 0 && is_array($preview)) {
        // Keep Cleared Amount in sync when one-fils snap bumps receipt to match invoices.
        $previewAmount = re_receipt_money($preview['amount'] ?? 0);
        if ($previewAmount > $amount + 0.005 && ($previewAmount - $amount) <= 0.025) {
            $amount = $previewAmount;
            $amountInput = number_format($amount, 2, '.', '');
        }
    }
}

$pageTitle = 'Receipt Allocation';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-cash-coin"></i> Receipt Allocation
    </div>
    <span class="badge bg-secondary">Invoice Mode only</span>
</div>

<?php if ($selectedCheque): ?>
    <div class="alert alert-success">
        <?php if ($chequeSummary): ?>
            <div class="d-flex flex-wrap gap-3 align-items-center mb-1">
                <span><strong>Cheque <?= h($selectedCheque['cheque_number'] ?? ('#' . $chequeId)) ?></strong></span>
                <span>Total <strong><?= p4_money($chequeSummary['cheque_amount']) ?> AED</strong></span>
                <span>Collected <strong><?= p4_money($chequeSummary['collected_total']) ?> AED</strong></span>
                <span>Remaining <strong class="<?= $chequeSummary['remaining'] > 0.005 ? 'text-danger' : '' ?>"><?= p4_money($chequeSummary['remaining']) ?> AED</strong></span>
            </div>
            <?php if ($chequeSummary['receipt_count'] > 0): ?>
                <div class="small mt-2">
                    Linked receipts:
                    <?php foreach ($chequeSummary['receipts'] as $idx => $rcpt): ?>
                        <?php if ($idx > 0): ?>, <?php endif; ?>
                        <a href="../payment_view.php?id=<?= (int)$rcpt['id'] ?>" class="alert-link">
                            <?= h($rcpt['receipt_number'] ?: ('#' . $rcpt['id'])) ?>
                        </a> (<?= p4_money($rcpt['amount'] ?? 0) ?> AED)
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="small mt-2 mb-0 text-muted">
                Multiple bank-transfer/cash/card receipts can be linked to the same cheque. The cheque is marked cleared only when linked cleared receipts reach the cheque amount.
            </div>
            <?php if (is_array($preview) && !empty($preview['coverage_preferred'])): ?>
                <div class="small mt-2 mb-0">
                    Suggested fills prefer invoices/obligations linked to this schedule row. All open invoices remain editable.
                </div>
                <?php if (!empty($preview['default_credit']) && (float)$preview['default_credit'] > 0.005): ?>
                    <div class="small mt-2 mb-0 text-warning">
                        AED <?= p4_money($preview['default_credit']) ?> cannot be matched to issued invoices for this cheque
                        (for example remaining fee VAT not yet invoiced). Confirming will park that amount as tenant credit
                        until you allocate it to later invoices — it is not lost, and it is not applied to rent.
                    </div>
                <?php endif; ?>
            <?php elseif (is_array($preview) && !empty($preview['coverage_empty'])): ?>
                <div class="small mt-2 mb-0 text-warning">
                    No matching invoices were found for this cheque type; showing FIFO suggestion across open invoices. Adjust allocation before confirming.
                </div>
            <?php endif; ?>
        <?php else: ?>
            Recording receipt linked to cheque <strong><?= h($selectedCheque['cheque_number'] ?? ('#' . $chequeId)) ?></strong>
            for AED <?= p4_money($selectedCheque['cheque_amount'] ?? 0) ?>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if (is_array($flash)): ?>
    <div class="alert alert-<?= !empty($flash['success']) ? 'success' : 'danger' ?>"><?= h($flash['message'] ?? '') ?></div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end" id="receiptLoadForm">
            <?php if ($chequeId > 0): ?>
                <input type="hidden" name="cheque_id" value="<?= (int)$chequeId ?>">
            <?php endif; ?>
            <div class="col-md-5">
                <label class="form-label">Invoice Mode Lease</label>
                <?php if ($lockLeaseSelection && $leaseId > 0): ?>
                    <?php
                    $lockedLeaseLabel = '';
                    foreach ($leases as $leaseRow) {
                        if ((int)$leaseRow['id'] === $leaseId) {
                            $lockedLeaseLabel = '#' . (int)$leaseRow['id'] . ' - ' . ($leaseRow['lease_number'] ?? '')
                                . ' | ' . p4_tenant_name($leaseRow)
                                . ' | ' . trim(($leaseRow['building_name'] ?? '') . ' ' . ($leaseRow['unit_number'] ?? ''));
                            break;
                        }
                    }
                    if ($lockedLeaseLabel === '' && $selectedCheque) {
                        $lockedLeaseLabel = 'Lease #' . (int)$leaseId
                            . ' | Cheque ' . (string)($selectedCheque['cheque_number'] ?? ('#' . $chequeId));
                    }
                    ?>
                    <input type="text" class="form-control" value="<?= h($lockedLeaseLabel ?: ('Lease #' . $leaseId)) ?>" readonly>
                    <input type="hidden" name="lease_id" value="<?= (int)$leaseId ?>">
                    <div class="form-text">Lease is fixed for this cheque-linked receipt.</div>
                <?php else: ?>
                <select name="lease_id" class="form-select" required>
                    <option value="">-- Select lease --</option>
                    <?php foreach ($leases as $lease): ?>
                        <option value="<?= (int)$lease['id'] ?>" <?= $leaseId === (int)$lease['id'] ? 'selected' : '' ?>>
                            #<?= (int)$lease['id'] ?> - <?= h($lease['lease_number']) ?>
                            | <?= h(p4_tenant_name($lease)) ?>
                            | <?= h(($lease['building_name'] ?? '') . ' ' . ($lease['unit_number'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label">Cleared Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?= h($amountInput) ?>" placeholder="Optional for preview">
            </div>
            <div class="col-md-2">
                <label class="form-label">Source</label>
                <select name="receipt_source" class="form-select">
                    <?php foreach (re_receipt_source_options() as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $receiptSource === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Cleared Date</label>
                <input type="date" name="cleared_date" class="form-control" value="<?= h($clearedDate) ?>" required>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100">Load</button>
            </div>
            <div class="col-md-4">
                <label class="form-label">Receipt Account</label>
                <select name="receipt_account" class="form-select" required>
                    <option value="">-- Select receipt account --</option>
                    <?php foreach ($cashAccounts as $cashAccount): ?>
                        <?php $value = 'cash:' . (int)$cashAccount['id']; ?>
                        <option value="<?= h($value) ?>" <?= $receiptAccount === $value ? 'selected' : '' ?>>
                            Cash - <?= h($cashAccount['account_code'] . ' ' . $cashAccount['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php foreach ($bankAccounts as $account): ?>
                        <?php $value = 'bank:' . (int)$account['id']; ?>
                        <option value="<?= h($value) ?>" <?= $receiptAccount === $value ? 'selected' : '' ?>>
                            Bank - <?= h($account['account_name'] . ' ' . ($account['bank_name'] ? '(' . $account['bank_name'] . ')' : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Reference</label>
                <input type="text" name="reference_number" class="form-control" value="<?= h($referenceNumber) ?>" placeholder="Transfer ref / cheque no / card ref">
            </div>
            <div class="col-md-4">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" value="<?= h($notes) ?>">
            </div>
        </form>
    </div>
</div>

<?php if ($preview): ?>
    <?php
        $lease = $preview['lease'];
        $defaults = $preview['default_allocations'];
        $openTotal = array_sum(array_map(static fn($target) => (float)$target['open_amount'], $preview['targets']));
    ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-round h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Lease</div>
                <h5 class="mb-1"><?= h($lease['lease_number'] ?? ('#' . $leaseId)) ?></h5>
                <div><?= h(p4_tenant_name($lease)) ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card card-round h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Open Receivables</div>
                <h4 class="mb-0">AED <?= p4_money($openTotal) ?></h4>
                <small class="text-muted"><?= count($preview['targets']) ?> invoice/obligation target<?= count($preview['targets']) === 1 ? '' : 's' ?></small>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card card-round h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Cleared Receipt Amount</div>
                <h4 class="mb-0"><?= $amount > 0 ? 'AED ' . p4_money($preview['amount']) : 'Not entered yet' ?></h4>
                <small class="text-muted">
                    <?php if ($amount > 0): ?>
                        Suggested allocation AED <?= p4_money($preview['default_allocated']) ?>; credit AED <?= p4_money($preview['default_credit']) ?>.
                    <?php else: ?>
                        Enter amount above and click Load to auto-suggest allocation.
                    <?php endif; ?>
                </small>
            </div></div>
        </div>
    </div>

    <?php
        $tenantCreditBalance = 0.0;
        if (!empty($lease['tenant_id']) && function_exists('get_tenant_credit_balance')) {
            $tenantCreditBalance = (float)get_tenant_credit_balance($conn, (int)$lease['tenant_id'], $companyId);
        }
    ?>
    <?php if ($tenantCreditBalance > 0.005): ?>
        <div class="alert alert-info py-2 mb-3">
            Tenant credit available: <strong>AED <?= p4_money($tenantCreditBalance) ?></strong>.
            After confirm, credit is auto-applied only when it fully clears an open invoice
            (never chips leftover fils into a rent/fee month). Any remainder stays as tenant credit.
            Receipt amount, cheque status, and bank reconciliation stay unchanged.
        </div>
    <?php endif; ?>

    <form method="post" action="receipt_allocation_confirm.php">
        <?php csrf_field(); ?>
        <input type="hidden" name="lease_id" value="<?= (int)$leaseId ?>">
        <input type="hidden" name="amount" value="<?= h($amount) ?>">
        <input type="hidden" name="receipt_source" value="<?= h($receiptSource) ?>">
        <input type="hidden" name="cleared_date" value="<?= h($clearedDate) ?>">
        <input type="hidden" name="receipt_account" id="confirm_receipt_account" value="<?= h($receiptAccount) ?>">
        <input type="hidden" name="receipt_account_type" id="confirm_receipt_account_type" value="<?= h($receiptAccountType ?? '') ?>">
        <input type="hidden" name="receipt_account_id" id="confirm_receipt_account_id" value="<?= h($receiptAccountId ?? '') ?>">
        <input type="hidden" name="reference_number" value="<?= h($referenceNumber) ?>">
        <input type="hidden" name="notes" value="<?= h($notes) ?>">
        <input type="hidden" name="cheque_id" value="<?= (int)$chequeId ?>">

        <div class="card card-round mb-4">
            <div class="card-header bg-white">
                <h6 class="mb-0">Open Invoices & Direct Targets - Adjust Allocation Before Confirming</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Target</th>
                                <th>Due Date</th>
                                <th>Source</th>
                                <th class="text-end">Open</th>
                                <th class="text-end">Allocate</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($preview['targets'] as $target): ?>
                            <?php $key = $target['target_type'] . ':' . (int)$target['target_id']; ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?= $target['target_type'] === 'invoice' ? 'primary' : 'warning' ?>"><?= h($target['target_type']) ?></span>
                                    <?= h($target['label']) ?>
                                </td>
                                <td><?= h($target['due_date']) ?></td>
                                <td><?php
                                    $srcLabel = (string)($target['source_types'] ?? '');
                                    $srcLabel = str_replace(
                                        ['extra_service', 'admin_fee'],
                                        ['extra service', 'admin fee'],
                                        $srcLabel
                                    );
                                    echo h($srcLabel);
                                ?></td>
                                <td class="text-end"><?= p4_money($target['open_amount']) ?></td>
                                <td class="text-end" style="width:160px">
                                    <input type="number" step="0.01" min="0" max="<?= h($target['open_amount']) ?>"
                                           name="allocation[<?= h($key) ?>]"
                                           class="form-control form-control-sm text-end"
                                           value="<?= h($defaults[$key] ?? 0) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($preview['targets'])): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No open invoices or direct obligation targets found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <div class="row g-3 align-items-end">
                    <div class="col-md-10">
                        <small class="text-muted">
                            <?= $amount > 0
                                ? 'Default allocation is oldest issued invoice first. The Receipt Account selected above is required, saved on the receipt, and used for bank/GL posting. Any unallocated balance becomes tenant credit.'
                                : 'This is a receivable list only. Enter the cleared amount above before confirming a receipt.' ?>
                        </small>
                    </div>
                    <div class="col-md-2 text-end">
                        <button type="submit" class="btn btn-success w-100" id="confirmReceiptBtn" <?= ($amount > 0 && $receiptAccountType && $receiptAccountId) ? '' : 'disabled' ?> onclick="return confirm('Confirm cleared receipt and allocation? This creates a receipt and allocation rows only.');">
                            <i class="bi bi-check2-circle"></i> Confirm
                        </button>
                        <div class="small text-danger mt-1 receipt-account-help" id="receiptAccountHelp" <?= ($amount > 0 && $receiptAccountType && $receiptAccountId) ? 'style="display:none;"' : '' ?>>
                            Select Receipt Account above<?= $amount <= 0 ? ' and enter cleared amount' : '' ?> before confirming.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var accountSelect = document.querySelector('#receiptLoadForm select[name="receipt_account"]');
    var confirmForm = document.querySelector('form[action="receipt_allocation_confirm.php"]');
    if (!accountSelect || !confirmForm) {
        return;
    }

    var rawInput = document.getElementById('confirm_receipt_account');
    var typeInput = document.getElementById('confirm_receipt_account_type');
    var idInput = document.getElementById('confirm_receipt_account_id');
    var confirmBtn = document.getElementById('confirmReceiptBtn');
    var helpText = document.getElementById('receiptAccountHelp');
    var amountInput = confirmForm.querySelector('input[name="amount"]');

    function syncReceiptAccount() {
        var value = accountSelect.value || '';
        var match = value.match(/^(bank|cash|card_clearing):(\d+)$/);
        if (rawInput) {
            rawInput.value = value;
        }
        if (match) {
            if (typeInput) typeInput.value = match[1];
            if (idInput) idInput.value = match[2];
        } else {
            if (typeInput) typeInput.value = '';
            if (idInput) idInput.value = '';
        }

        var amount = amountInput ? parseFloat(amountInput.value || '0') : 0;
        var ready = amount > 0 && !!match;
        if (confirmBtn) {
            confirmBtn.disabled = !ready;
        }
        if (helpText) {
            helpText.style.display = ready ? 'none' : '';
        }
    }

    accountSelect.addEventListener('change', syncReceiptAccount);
    syncReceiptAccount();
});
</script>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>

