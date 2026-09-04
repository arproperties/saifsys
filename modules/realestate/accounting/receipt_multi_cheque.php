<?php
/**
 * Multi-cheque receipt: one bank/cash/card payment linked to 2+ schedule cheques.
 * Does not replace per-cheque Allocate Payment.
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
require_once __DIR__ . '/../includes/receipt_allocation_engine.php';
require_once __DIR__ . '/../includes/receipt_multi_cheque_helper.php';
require_once __DIR__ . '/../includes/invoice_engine.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mcm_money($n): string { return number_format((float)$n, 2); }

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$leaseId = (int)($_GET['lease_id'] ?? $_POST['lease_id'] ?? 0);
$selectedChequeIds = [];
if (!empty($_GET['cheque_ids']) && is_array($_GET['cheque_ids'])) {
    foreach ($_GET['cheque_ids'] as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) {
            $selectedChequeIds[$cid] = true;
        }
    }
}
$selectedChequeIds = array_keys($selectedChequeIds);

$amountInput = trim((string)($_GET['amount'] ?? ''));
$amount = $amountInput !== '' ? (float)$amountInput : 0.0;
$receiptSource = (string)($_GET['receipt_source'] ?? 'bank_transfer');
$clearedDate = (string)($_GET['cleared_date'] ?? date('Y-m-d'));
$receiptAccount = (string)($_GET['receipt_account'] ?? '');
$referenceNumber = (string)($_GET['reference_number'] ?? '');
$notes = (string)($_GET['notes'] ?? '');
$error = '';
$flash = $_SESSION['re_multi_cheque_flash'] ?? null;
unset($_SESSION['re_multi_cheque_flash']);
$preview = null;

$ready = $leaseId > 0 ? re_invoice_engine_load_invoice_mode_lease($conn, $companyId, $leaseId) : ['success' => false];
if ($leaseId > 0 && empty($ready['success'])) {
    $error = (string)($ready['error'] ?? 'Lease is not available for Invoice Mode receipts.');
}

$eligibleCheques = [];
if ($leaseId > 0 && !empty($ready['success'])) {
    re_receipt_cheque_links_ensure_table($conn);
    $st = $conn->prepare("
        SELECT c.id, c.cheque_number, c.cheque_amount, c.status, c.cheque_date, c.reference_number,
               li.installment_date, COALESCE(li.installment_type, 'rent') AS installment_type
        FROM re_post_dated_cheques c
        LEFT JOIN re_lease_installments li ON li.id = c.installment_id AND li.lease_id = c.lease_id
        WHERE c.company_id = ? AND c.lease_id = ?
        ORDER BY COALESCE(li.installment_date, c.cheque_date) ASC, c.id ASC
    ");
    $st->execute([$companyId, $leaseId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $status = re_cheque_normalize_status((string)($row['status'] ?? ''));
        if (in_array($status, ['replaced', 'cancelled', 'returned'], true)) {
            continue;
        }
        $face = re_receipt_money($row['cheque_amount'] ?? 0);
        $already = re_receipt_cheque_linked_cleared_total($conn, $companyId, $leaseId, (int)$row['id']);
        $remaining = re_receipt_money(max(0.0, $face - $already));
        if ($remaining <= 0.005) {
            continue;
        }
        $row['already_collected'] = $already;
        $row['remaining'] = $remaining;
        $row['status_norm'] = $status;
        $eligibleCheques[] = $row;
    }
}

if ($leaseId > 0 && count($selectedChequeIds) >= 2 && $error === '') {
    if ($amountInput === '') {
        $sum = 0.0;
        foreach ($eligibleCheques as $row) {
            if (in_array((int)$row['id'], $selectedChequeIds, true)) {
                $sum = re_receipt_money($sum + (float)$row['remaining']);
            }
        }
        $amount = $sum;
        $amountInput = number_format($amount, 2, '.', '');
    }
    if ($referenceNumber === '') {
        $refs = [];
        foreach ($eligibleCheques as $row) {
            if (in_array((int)$row['id'], $selectedChequeIds, true)) {
                $refs[] = (string)($row['cheque_number'] ?: ('#' . $row['id']));
            }
        }
        $referenceNumber = implode(' + ', $refs);
    }
    if ($notes === '') {
        $notes = 'Combined receipt covering ' . $referenceNumber;
    }
    $preview = re_receipt_preview_multi_cheque($conn, $companyId, $leaseId, $selectedChequeIds, $amount);
    if (empty($preview['success'])) {
        $error = (string)($preview['error'] ?? 'Could not build multi-cheque preview.');
        $preview = null;
    }
}

$cashAccount = null;
$bankAccounts = [];
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
        WHERE company_id = ? AND account_code = '1110' AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$companyId]);
    $cashAccount = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $cashAccount = null;
}

$pageTitle = 'Multi-Cheque Receipt';
require_once __DIR__ . '/../includes/re_layout_header.php';
$lease = $ready['lease'] ?? null;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-link-45deg"></i> Multi-Cheque Receipt
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="../lease_view.php?id=<?= (int)$leaseId ?>">Back to Lease</a>
        <span class="badge bg-primary align-self-center">One payment → 2+ cheques</span>
    </div>
</div>

<div class="alert alert-info">
    Use this when the tenant pays <strong>two or more schedule cheques with one bank transfer / cash / card payment</strong>.
    Per-cheque <strong>Allocate Payment</strong> on the lease schedule is unchanged.
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= !empty($flash['success']) ? 'success' : 'danger' ?>"><?= h($flash['message'] ?? '') ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!$leaseId || empty($ready['success'])): ?>
    <div class="alert alert-warning">Open this page from an Invoice Mode lease (Multi-Cheque Receipt button).</div>
<?php else: ?>
    <div class="card card-round mb-4">
        <div class="card-body">
            <div class="mb-2"><strong>Lease #<?= (int)$leaseId ?></strong> — <?= h($lease['lease_number'] ?? '') ?></div>
            <form method="get" class="row g-3">
                <input type="hidden" name="lease_id" value="<?= (int)$leaseId ?>">
                <div class="col-12">
                    <label class="form-label">Select cheques to clear together (minimum 2)</label>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:2rem"></th>
                                    <th>Cheque</th>
                                    <th>Type</th>
                                    <th>Expected</th>
                                    <th class="text-end">Face</th>
                                    <th class="text-end">Already linked</th>
                                    <th class="text-end">Remaining</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($eligibleCheques === []): ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">No open cheque balances remain on this lease.</td></tr>
                            <?php else: ?>
                                <?php foreach ($eligibleCheques as $row):
                                    $cid = (int)$row['id'];
                                    $checked = in_array($cid, $selectedChequeIds, true);
                                ?>
                                    <tr>
                                        <td>
                                            <input class="form-check-input cheque-pick" type="checkbox" name="cheque_ids[]" value="<?= $cid ?>" <?= $checked ? 'checked' : '' ?>>
                                        </td>
                                        <td><?= h($row['cheque_number'] ?: ('#' . $cid)) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= h($row['installment_type']) ?></span></td>
                                        <td><?= h($row['installment_date'] ?: $row['cheque_date']) ?></td>
                                        <td class="text-end"><?= mcm_money($row['cheque_amount']) ?></td>
                                        <td class="text-end"><?= mcm_money($row['already_collected']) ?></td>
                                        <td class="text-end fw-semibold"><?= mcm_money($row['remaining']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cleared Amount</label>
                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?= h($amountInput) ?>" placeholder="Sum of remaining">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Source</label>
                    <select name="receipt_source" class="form-select">
                        <?php foreach (re_receipt_source_options() as $value => $label): ?>
                            <?php if ($value === 'cleared_cheque') { continue; } ?>
                            <option value="<?= h($value) ?>" <?= $receiptSource === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cleared Date</label>
                    <input type="date" name="cleared_date" class="form-control" value="<?= h($clearedDate) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference_number" class="form-control" value="<?= h($referenceNumber) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" value="<?= h($notes) ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Load allocation preview</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (is_array($preview)): ?>
        <div class="alert alert-success">
            Selected cheques remaining total <strong>AED <?= mcm_money($preview['expected_cheques_total'] ?? 0) ?></strong>.
            Receipt amount <strong>AED <?= mcm_money($preview['amount'] ?? 0) ?></strong>.
            <?php if (!empty($preview['default_credit']) && (float)$preview['default_credit'] > 0.005): ?>
                Suggested credit parking: AED <?= mcm_money($preview['default_credit']) ?> (invoices not yet issued for some coverage).
            <?php endif; ?>
        </div>

        <form method="post" action="receipt_multi_cheque_confirm.php" id="multiChequeConfirmForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="lease_id" value="<?= (int)$leaseId ?>">
            <input type="hidden" name="amount" value="<?= h(number_format((float)$preview['amount'], 2, '.', '')) ?>">
            <input type="hidden" name="receipt_source" value="<?= h($receiptSource) ?>">
            <input type="hidden" name="cleared_date" value="<?= h($clearedDate) ?>">
            <input type="hidden" name="reference_number" value="<?= h($referenceNumber) ?>">
            <input type="hidden" name="notes" value="<?= h($notes) ?>">
            <?php foreach ($selectedChequeIds as $cid): ?>
                <input type="hidden" name="cheque_ids[]" value="<?= (int)$cid ?>">
            <?php endforeach; ?>

            <div class="card card-round mb-3">
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Receipt Account</label>
                        <select name="receipt_account" class="form-select" required>
                            <option value="">-- Select receipt account --</option>
                            <?php if ($cashAccount): ?>
                                <?php $value = 'cash:' . (int)$cashAccount['id']; ?>
                                <option value="<?= h($value) ?>" <?= $receiptAccount === $value ? 'selected' : '' ?>>
                                    Cash - <?= h($cashAccount['account_code'] . ' ' . $cashAccount['account_name']) ?>
                                </option>
                            <?php endif; ?>
                            <?php foreach ($bankAccounts as $account): ?>
                                <?php $value = 'bank:' . (int)$account['id']; ?>
                                <option value="<?= h($value) ?>" <?= $receiptAccount === $value ? 'selected' : '' ?>>
                                    Bank - <?= h($account['account_name'] . ($account['bank_name'] ? ' (' . $account['bank_name'] . ')' : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cheque shares (from this receipt)</label>
                        <ul class="mb-0 small">
                            <?php foreach (($preview['cheque_rows'] ?? []) as $row): ?>
                                <li>
                                    <?= h($row['cheque_number'] ?: ('#' . $row['id'])) ?>:
                                    AED <?= mcm_money($row['remaining']) ?> of remaining face
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="card card-round mb-3">
                <div class="card-header bg-white"><strong>Allocate to open invoices</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Due</th>
                                    <th>Type</th>
                                    <th class="text-end">Open</th>
                                    <th class="text-end" style="width:8rem">Allocate</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $suggestions = $preview['suggestions'] ?? [];
                            foreach (($preview['open_invoices'] ?? []) as $inv):
                                $key = 'invoice:' . (int)$inv['id'];
                                $sug = isset($suggestions[$key]) ? (float)$suggestions[$key] : 0.0;
                            ?>
                                <tr>
                                    <td><?= h($inv['invoice_number'] ?: ('#' . $inv['id'])) ?></td>
                                    <td><?= h($inv['due_date']) ?></td>
                                    <td><?= h($inv['obligation_type'] ?: '—') ?></td>
                                    <td class="text-end"><?= mcm_money($inv['outstanding_amount']) ?></td>
                                    <td class="text-end">
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end alloc-input"
                                               name="allocation[<?= h($key) ?>]" value="<?= $sug > 0.005 ? h(number_format($sug, 2, '.', '')) : '0' ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($preview['open_invoices'])): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No open invoices. Full amount will park as tenant credit.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <div class="small text-muted" id="allocSummary"></div>
                <button type="submit" class="btn btn-success" id="confirmMultiBtn">Confirm multi-cheque receipt</button>
            </div>
        </form>
        <script>
        (function () {
            const form = document.getElementById('multiChequeConfirmForm');
            if (!form) return;
            const receiptAmt = parseFloat(form.querySelector('input[name="amount"]').value || '0');
            const summary = document.getElementById('allocSummary');
            const btn = document.getElementById('confirmMultiBtn');
            function refresh() {
                let sum = 0;
                form.querySelectorAll('.alloc-input').forEach(function (el) {
                    sum += parseFloat(el.value || '0') || 0;
                });
                const credit = Math.max(0, Math.round((receiptAmt - sum) * 100) / 100);
                if (summary) {
                    summary.textContent = 'Allocated AED ' + sum.toFixed(2) + ' · Tenant credit AED ' + credit.toFixed(2);
                }
                if (btn) {
                    btn.disabled = sum > receiptAmt + 0.005;
                }
            }
            form.querySelectorAll('.alloc-input').forEach(function (el) {
                el.addEventListener('input', refresh);
            });
            refresh();
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
