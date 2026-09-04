<?php
/**
 * Real Estate Module - Lease Termination Workflow
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/lease_termination_helper.php';
require_once __DIR__ . '/includes/accounting_mode_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);
if (!has_role('Owner', $conn) && !has_role('Admin', $conn)) {
    http_response_code(403);
    die('Access denied. Lease termination is available to Owner/Admin only.');
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : (!empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : 0);

if (!$leaseId) {
    header('Location: leases.php');
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return number_format((float)$n, 2); }

$stmt = $conn->prepare("
    SELECT l.*,
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           u.unit_number,
           b.name AS building_name
    FROM re_leases l
    JOIN re_tenants t ON t.id = l.tenant_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE l.id = ? AND l.company_id = ?
");
$stmt->execute([$leaseId, $currentCompanyId]);
$lease = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lease) {
    header('Location: leases.php');
    exit;
}

$terminationDate = $_POST['termination_date'] ?? ($_GET['termination_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $terminationDate)) {
    $terminationDate = date('Y-m-d');
}
$reason = trim($_POST['termination_reason'] ?? '');
$selectedInstallmentIds = array_values(array_unique(array_map('intval', (array)($_POST['return_installment_ids'] ?? []))));
$selectedPdcIds = array_values(array_unique(array_map('intval', (array)($_POST['return_pdc_ids'] ?? []))));
$selectedRecognitionIds = array_values(array_unique(array_map('intval', (array)($_POST['skip_recognition_ids'] ?? []))));
$selectedVoidInvoiceIds = array_values(array_unique(array_map('intval', (array)($_POST['void_invoice_ids'] ?? []))));
$selectedObligationIds = array_values(array_unique(array_map('intval', (array)($_POST['void_obligation_ids'] ?? []))));
$penaltyMode = $_POST['penalty_mode'] ?? 'none';
$penaltyMonths = isset($_POST['penalty_months']) ? (float)$_POST['penalty_months'] : 2.0;
$penaltyAmount = isset($_POST['penalty_amount']) ? (float)$_POST['penalty_amount'] : 0.0;
$penaltyNote = trim($_POST['penalty_note'] ?? '');
$penaltyPdcId = (int)($_POST['penalty_pdc_id'] ?? 0);
$submittedAction = $_POST['action'] ?? 'preview';
$error = '';
$success = '';

try {
    $preview = re_get_lease_termination_preview($conn, $currentCompanyId, $leaseId, $terminationDate);
} catch (Throwable $e) {
    $preview = ['installments' => [], 'pdc_cheques' => [], 'lease_cheques' => [], 'recognition_rows' => [], 'blocked_installments' => [], 'invoice_obligations' => [], 'voidable_invoices' => [], 'pending_candidates' => [], 'accounting_mode' => 'legacy'];
    $error = $e->getMessage();
}
$isInvoiceMode = (($preview['accounting_mode'] ?? re_accounting_normalize_mode((string)($lease['accounting_mode'] ?? 'legacy'))) === 'invoice');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if ($submittedAction === 'terminate') {
        if (empty($_POST['confirm_termination'])) {
            $error = 'Please tick the confirmation checkbox before applying termination.';
        } else {
            try {
                $stats = re_apply_lease_termination($conn, $currentCompanyId, $leaseId, $terminationDate, $reason, $userId, [
                    'installment_ids' => $selectedInstallmentIds,
                    'pdc_ids' => $selectedPdcIds,
                    'recognition_ids' => $selectedRecognitionIds,
                    'void_invoice_ids' => array_values(array_unique(array_map('intval', (array)($_POST['void_invoice_ids'] ?? [])))),
                    'obligation_ids' => array_values(array_unique(array_map('intval', (array)($_POST['void_obligation_ids'] ?? [])))),
                    'penalty_mode' => $penaltyMode,
                    'penalty_months' => $penaltyMonths,
                    'penalty_amount' => $penaltyAmount,
                    'penalty_note' => $penaltyNote,
                    'penalty_pdc_id' => $penaltyPdcId,
                ]);
                $successParts = [
                    "Lease terminated effective {$terminationDate}.",
                    "{$stats['installments_cancelled']} installment(s) cancelled",
                    "{$stats['pdc_returned']} PDC cheque(s) returned",
                ];
                if ($isInvoiceMode) {
                    $successParts[] = "{$stats['invoices_voided']} invoice(s) voided";
                    $successParts[] = "{$stats['obligations_cancelled']} obligation(s) cancelled";
                    if (!empty($stats['penalty_pdc_kept'])) {
                        $successParts[] = 'penalty cheque retained for collection';
                    }
                } else {
                    $successParts[] = "{$stats['recognition_skipped']} recognition row(s) skipped";
                }
                if (!empty($stats['penalty_created'])) {
                    $successParts[] = 'termination penalty created';
                }
                $_SESSION['success'] = implode(', ', $successParts) . '.';
                header('Location: lease_view.php?id=' . $leaseId);
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $submittedAction === 'preview') {
    $selectedInstallmentIds = array_map('intval', array_column(array_filter($preview['installments'], fn($row) => !empty($row['suggested'])), 'id'));
    $selectedPdcIds = array_map('intval', array_column(array_filter($preview['pdc_cheques'], fn($row) => !empty($row['suggested'])), 'id'));
    $selectedRecognitionIds = array_map('intval', array_column(array_filter($preview['recognition_rows'], fn($row) => !empty($row['suggested'])), 'id'));
    $selectedVoidInvoiceIds = array_map('intval', array_column(array_filter($preview['voidable_invoices'] ?? [], fn($row) => !empty($row['suggested'])), 'id'));
    $selectedObligationIds = array_map('intval', array_column(array_filter($preview['invoice_obligations'] ?? [], fn($row) => !empty($row['suggested'])), 'id'));
    if ($penaltyPdcId > 0) {
        $selectedPdcIds = array_values(array_filter($selectedPdcIds, fn($id) => (int)$id !== $penaltyPdcId));
        foreach ($preview['pdc_cheques'] as $row) {
            if ((int)$row['id'] === $penaltyPdcId && !empty($row['installment_id'])) {
                $selectedInstallmentIds = array_values(array_filter($selectedInstallmentIds, fn($id) => (int)$id !== (int)$row['installment_id']));
                break;
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $submittedAction === 'terminate') {
    $selectedInstallmentIds = array_values(array_unique(array_map('intval', (array)($_POST['return_installment_ids'] ?? []))));
    $selectedPdcIds = array_values(array_unique(array_map('intval', (array)($_POST['return_pdc_ids'] ?? []))));
    $selectedRecognitionIds = array_values(array_unique(array_map('intval', (array)($_POST['skip_recognition_ids'] ?? []))));
    $selectedVoidInvoiceIds = array_values(array_unique(array_map('intval', (array)($_POST['void_invoice_ids'] ?? []))));
    $selectedObligationIds = array_values(array_unique(array_map('intval', (array)($_POST['void_obligation_ids'] ?? []))));
    $penaltyPdcId = (int)($_POST['penalty_pdc_id'] ?? 0);
}
$selectedInstallmentLookup = array_fill_keys($selectedInstallmentIds, true);
$selectedPdcLookup = array_fill_keys($selectedPdcIds, true);
$selectedRecognitionLookup = array_fill_keys($selectedRecognitionIds, true);
$selectedVoidInvoiceLookup = array_fill_keys($selectedVoidInvoiceIds, true);
$selectedObligationLookup = array_fill_keys($selectedObligationIds, true);
$monthlyRent = !empty($lease['annual_rent']) ? ((float)$lease['annual_rent'] / 12) : (float)($lease['monthly_rent'] ?? 0);

$tenantName = !empty($lease['company_name'])
    ? $lease['company_name']
    : trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''));

$pageTitle = 'Terminate Lease';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Terminate Lease</div>
    <a href="lease_view.php?id=<?= (int)$leaseId ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Lease
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<div class="alert alert-warning">
    <strong>Important:</strong>
    <?php if ($isInvoiceMode): ?>
        This Invoice Mode lease will be terminated on the <strong>effective date you choose below</strong>.
        Unpaid future invoices will be voided and revenue journals reversed.
        Return only the cheques you select. To collect an early-termination penalty on an existing pending cheque (e.g. keep CHQ-202-8), choose it under <em>Penalty collection cheque</em> — do not mark it returned.
    <?php else: ?>
        This workflow will terminate the lease, return only the cheques you select,
        cancel the selected unpaid installments, and skip the selected pending revenue-recognition rows.
        Paid installments and recognised revenue are not changed.
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card card-round mb-4">
            <div class="card-header"><h5 class="mb-0">Lease Details</h5></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th>Lease</th><td><?= h($lease['lease_number']) ?></td></tr>
                    <tr><th>Tenant</th><td><?= h($tenantName) ?></td></tr>
                    <tr><th>Unit</th><td><?= h($lease['building_name'] . ' - ' . $lease['unit_number']) ?></td></tr>
                    <tr><th>Period</th><td><?= h($lease['start_date']) ?> to <?= h($lease['end_date']) ?></td></tr>
                    <tr><th>Status</th><td><span class="badge bg-secondary"><?= h(ucfirst($lease['status'])) ?></span></td></tr>
                </table>
            </div>
        </div>

        <div class="card card-round">
            <div class="card-header"><h5 class="mb-0">Termination Input</h5></div>
            <div class="card-body">
                <form method="post" id="terminationForm" onsubmit="return handleTerminationSubmit(event);">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="lease_id" value="<?= (int)$leaseId ?>">
                    <input type="hidden" name="action" id="terminationAction" value="preview">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Effective Termination Date *</label>
                        <input type="date" name="termination_date" id="terminationDateInput" class="form-control"
                               value="<?= h($terminationDate) ?>"
                               min="<?= h($lease['start_date']) ?>"
                               max="<?= h($lease['end_date']) ?>"
                               required>
                        <small class="text-muted">This is the contractual termination date — not today's recording date. Rows on or after this date are evaluated.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason / Notes</label>
                        <textarea name="termination_reason" id="terminationReasonInput" rows="3" class="form-control"
                                  placeholder="Example: Tenant terminated contract early; CHQ-202-8 retained as penalty"><?= h($reason) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-primary mb-4" data-action="preview">
                        <i class="bi bi-search"></i> Preview Impact
                    </button>

                    <div class="border rounded p-3 mb-3 bg-light">
                        <div class="fw-semibold mb-2">Termination Penalty</div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="penalty_mode" id="penaltyNone" value="none" <?= $penaltyMode === 'none' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="penaltyNone">No separate penalty billing item</label>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="radio" name="penalty_mode" id="penaltyMonths" value="months" <?= $penaltyMode === 'months' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="penaltyMonths">Penalty by rent months</label>
                        </div>
                        <div class="input-group input-group-sm mt-2">
                            <input type="number" step="0.5" min="0" name="penalty_months" class="form-control" value="<?= h($penaltyMonths) ?>">
                            <span class="input-group-text">month(s), monthly rent <?= money($monthlyRent) ?> AED</span>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="radio" name="penalty_mode" id="penaltyFixed" value="fixed" <?= $penaltyMode === 'fixed' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="penaltyFixed">Fixed owner-decided amount</label>
                        </div>
                        <div class="input-group input-group-sm mt-2">
                            <input type="number" step="0.01" min="0" name="penalty_amount" class="form-control" value="<?= h($penaltyAmount) ?>">
                            <span class="input-group-text">AED</span>
                        </div>
                        <?php if (!empty($preview['pdc_cheques'])): ?>
                        <div class="mt-3">
                            <label class="form-label fw-semibold">Penalty collection cheque (optional)</label>
                            <select name="penalty_pdc_id" class="form-select form-select-sm">
                                <option value="0">— Do not keep a cheque for penalty —</option>
                                <?php foreach ($preview['pdc_cheques'] as $row): ?>
                                    <option value="<?= (int)$row['id'] ?>" <?= $penaltyPdcId === (int)$row['id'] ? 'selected' : '' ?>>
                                        <?= h($row['cheque_number']) ?> — <?= money($row['cheque_amount']) ?> AED on <?= h($row['cheque_date']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">If selected, this cheque stays pending for accountant collection and is excluded from returned cheques. A penalty billing item is created for its amount unless you choose fixed/months above.</small>
                        </div>
                        <?php endif; ?>
                        <textarea name="penalty_note" rows="2" class="form-control form-control-sm mt-2" placeholder="Optional penalty note"><?= h($penaltyNote) ?></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="confirm_termination" value="1" id="confirmTermination">
                        <label class="form-check-label" for="confirmTermination">
                            I confirm the lease should be terminated effective <span id="confirmTerminationDate"><?= h($terminationDate) ?></span>.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-danger w-100" data-action="terminate">
                        <i class="bi bi-x-octagon"></i> Apply Termination
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="row g-3 mb-4">
            <div class="col-md-3"><div class="card card-round text-center"><div class="card-body"><div class="small text-muted">Selected Installments</div><div class="h4 text-danger" id="selectedInstallmentsCount"><?= count($selectedInstallmentIds) ?></div></div></div></div>
            <div class="col-md-3"><div class="card card-round text-center"><div class="card-body"><div class="small text-muted">Selected PDC</div><div class="h4 text-warning" id="selectedPdcCount"><?= count($selectedPdcIds) ?></div></div></div></div>
            <div class="col-md-3"><div class="card card-round text-center"><div class="card-body"><div class="small text-muted">Selected Recognition</div><div class="h4 text-secondary" id="selectedRecognitionCount"><?= count($selectedRecognitionIds) ?></div></div></div></div>
            <div class="col-md-3"><div class="card card-round text-center"><div class="card-body"><div class="small text-muted">Protected</div><div class="h4 text-info"><?= count($preview['blocked_installments']) ?></div></div></div></div>
        </div>

        <div class="card card-round mb-4">
            <div class="card-header">
                <h5 class="mb-0">Unpaid Installments to Cancel</h5>
                <small class="text-muted">Future installments are selected automatically; overdue/pending rows can be selected manually.</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Cancel</th><th>Date</th><th>Amount</th><th>Status</th><th>Default</th></tr></thead>
                        <tbody>
                        <?php if (empty($preview['installments'])): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No safe unpaid installments to cancel.</td></tr>
                        <?php else: foreach ($preview['installments'] as $row): ?>
                            <tr>
                                <td>
                                    <input class="form-check-input" type="checkbox" form="terminationForm"
                                           name="return_installment_ids[]" value="<?= (int)$row['id'] ?>"
                                           <?= isset($selectedInstallmentLookup[(int)$row['id']]) ? 'checked' : '' ?>>
                                </td>
                                <td><?= h($row['installment_date']) ?></td>
                                <td><?= money($row['amount']) ?> AED</td>
                                <td><span class="badge bg-warning text-dark"><?= h($row['status']) ?></span></td>
                                <td><?= !empty($row['suggested']) ? '<span class="badge bg-success">Future</span>' : '<span class="badge bg-light text-dark">Manual</span>' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card card-round mb-4">
            <div class="card-header">
                <h5 class="mb-0">PDC Cheques to Return</h5>
                <small class="text-muted">Select exact cheques to mark as returned. This allows late-registered terminations, including overdue cheques.</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Return</th><th>Cheque #</th><th>Date</th><th>Amount</th><th>Default</th></tr></thead>
                        <tbody>
                        <?php if (empty($preview['pdc_cheques'])): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No pending PDC cheques available to return.</td></tr>
                        <?php else: foreach ($preview['pdc_cheques'] as $row): ?>
                            <tr>
                                <td>
                                    <input class="form-check-input" type="checkbox" form="terminationForm"
                                           name="return_pdc_ids[]" value="<?= (int)$row['id'] ?>"
                                           data-installment-id="<?= (int)($row['installment_id'] ?? 0) ?>"
                                           <?= isset($selectedPdcLookup[(int)$row['id']]) ? 'checked' : '' ?>>
                                </td>
                                <td><?= h($row['cheque_number']) ?></td>
                                <td><?= h($row['cheque_date']) ?></td>
                                <td><?= money($row['cheque_amount']) ?> AED</td>
                                <td><?= !empty($row['suggested']) ? '<span class="badge bg-success">Future</span>' : '<span class="badge bg-light text-dark">Manual</span>' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($isInvoiceMode): ?>
        <div class="card card-round mb-4 border-danger">
            <div class="card-header bg-danger-subtle">
                <h5 class="mb-0">Invoice Mode — Unpaid Invoices to Void</h5>
                <small class="text-muted">Reverses revenue recognition journals for selected unpaid invoices.</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Void</th><th>Invoice #</th><th>Due</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (empty($preview['voidable_invoices'])): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No unpaid issued invoices to void.</td></tr>
                        <?php else: foreach ($preview['voidable_invoices'] as $row): ?>
                            <tr>
                                <td>
                                    <input class="form-check-input" type="checkbox" form="terminationForm"
                                           name="void_invoice_ids[]" value="<?= (int)$row['id'] ?>"
                                           <?= isset($selectedVoidInvoiceLookup[(int)$row['id']]) ? 'checked' : '' ?>>
                                </td>
                                <td><?= h($row['invoice_number']) ?></td>
                                <td><?= h($row['due_date']) ?></td>
                                <td><?= money($row['total_amount']) ?> AED</td>
                                <td><span class="badge bg-warning text-dark"><?= h($row['status']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card card-round mb-4">
            <div class="card-header">
                <h5 class="mb-0">Invoice Mode — Open Obligations to Cancel</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Cancel</th><th>Type</th><th>Due</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (empty($preview['invoice_obligations'])): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No open future obligations.</td></tr>
                        <?php else: foreach ($preview['invoice_obligations'] as $row): ?>
                            <tr>
                                <td>
                                    <input class="form-check-input" type="checkbox" form="terminationForm"
                                           name="void_obligation_ids[]" value="<?= (int)$row['id'] ?>"
                                           <?= isset($selectedObligationLookup[(int)$row['id']]) ? 'checked' : '' ?>>
                                </td>
                                <td><?= h($row['obligation_type']) ?></td>
                                <td><?= h($row['due_date']) ?></td>
                                <td><?= money($row['total_amount']) ?> AED</td>
                                <td><span class="badge bg-secondary"><?= h($row['status']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card card-round">
            <div class="card-header">
                <h5 class="mb-0">Pending Revenue Recognition to Skip</h5>
                <small class="text-muted">Future rows are selected automatically; manual rows are available when linked to selected unpaid installments.</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Skip</th><th>Recognition Date</th><th>Amount</th><th>Status</th><th>Default</th></tr></thead>
                        <tbody>
                        <?php if (empty($preview['recognition_rows'])): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No pending recognition rows to skip.</td></tr>
                        <?php else: foreach ($preview['recognition_rows'] as $row): ?>
                            <tr>
                                <td>
                                    <input class="form-check-input" type="checkbox" form="terminationForm"
                                           name="skip_recognition_ids[]" value="<?= (int)$row['id'] ?>"
                                           data-installment-id="<?= (int)($row['installment_id'] ?? 0) ?>"
                                           <?= isset($selectedRecognitionLookup[(int)$row['id']]) ? 'checked' : '' ?>>
                                </td>
                                <td><?= h($row['recognition_date']) ?></td>
                                <td><?= money($row['amount']) ?> AED</td>
                                <td><span class="badge bg-secondary"><?= h($row['status']) ?></span></td>
                                <td><?= !empty($row['suggested']) ? '<span class="badge bg-success">Future</span>' : '<span class="badge bg-light text-dark">Manual</span>' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$pageScripts = <<<HTML
<script>
(function() {
    function countChecked(selector) {
        return document.querySelectorAll(selector + ':checked').length;
    }

    function updateTerminationSelectionCards() {
        var installmentCount = document.getElementById('selectedInstallmentsCount');
        var pdcCount = document.getElementById('selectedPdcCount');
        var recognitionCount = document.getElementById('selectedRecognitionCount');
        if (installmentCount) {
            installmentCount.textContent = countChecked('input[name="return_installment_ids[]"]');
        }
        if (pdcCount) {
            pdcCount.textContent = countChecked('input[name="return_pdc_ids[]"]');
        }
        if (recognitionCount) {
            recognitionCount.textContent = countChecked('input[name="skip_recognition_ids[]"]');
        }
    }

    function syncPenaltyChequeExclusions() {
        var penaltySelect = document.querySelector('select[name="penalty_pdc_id"]');
        if (!penaltySelect) {
            return;
        }
        var penaltyId = penaltySelect.value;
        document.querySelectorAll('input[name="return_pdc_ids[]"]').forEach(function(input) {
            if (penaltyId && input.value === penaltyId) {
                input.checked = false;
                input.disabled = true;
            } else {
                input.disabled = false;
            }
        });
        if (penaltyId) {
            var penaltyInput = document.querySelector('input[name="return_pdc_ids[]"][value="' + penaltyId + '"]');
            if (penaltyInput) {
                var installmentId = penaltyInput.getAttribute('data-installment-id');
                if (installmentId) {
                    document.querySelectorAll('input[name="return_installment_ids[]"]').forEach(function(instInput) {
                        if (instInput.value === installmentId) {
                            instInput.checked = false;
                            instInput.disabled = true;
                        }
                    });
                }
            }
        } else {
            document.querySelectorAll('input[name="return_installment_ids[]"]').forEach(function(instInput) {
                instInput.disabled = false;
            });
        }
    }

    function syncRecognitionRowsForSelectedItems() {
        var selectedInstallments = {};
        document.querySelectorAll('input[name="return_pdc_ids[]"]:checked').forEach(function(input) {
            var installmentId = input.getAttribute('data-installment-id');
            if (!installmentId || installmentId === '0') {
                return;
            }
            var installmentInput = document.querySelector('input[name="return_installment_ids[]"][value="' + installmentId + '"]');
            if (installmentInput) {
                installmentInput.checked = true;
            }
        });
        document.querySelectorAll('input[name="return_installment_ids[]"]:checked').forEach(function(input) {
            selectedInstallments[input.value] = true;
        });
        document.querySelectorAll('input[name="return_pdc_ids[]"]:checked').forEach(function(input) {
            var installmentId = input.getAttribute('data-installment-id');
            if (installmentId && installmentId !== '0') {
                selectedInstallments[installmentId] = true;
            }
        });
        document.querySelectorAll('input[name="skip_recognition_ids[]"]').forEach(function(input) {
            var installmentId = input.getAttribute('data-installment-id');
            if (installmentId && selectedInstallments[installmentId]) {
                input.checked = true;
            }
        });
    }

    window.handleTerminationSubmit = function(event) {
        var submitter = event.submitter;
        var action = submitter && submitter.getAttribute('data-action') ? submitter.getAttribute('data-action') : 'preview';
        var actionInput = document.getElementById('terminationAction');
        if (actionInput) {
            actionInput.value = action;
        }
        var confirmDate = document.getElementById('confirmTerminationDate');
        var dateInput = document.getElementById('terminationDateInput');
        if (confirmDate && dateInput) {
            confirmDate.textContent = dateInput.value;
        }
        if (action === 'terminate') {
            return confirm('Terminate this lease with the selected cheque returns, invoice voids, and penalty settings? This updates operational and accounting records.');
        }
        return true;
    };

    document.querySelectorAll('input[name="return_installment_ids[]"], input[name="return_pdc_ids[]"]').forEach(function(input) {
        input.addEventListener('change', function() {
            syncRecognitionRowsForSelectedItems();
            updateTerminationSelectionCards();
        });
    });
    document.querySelectorAll('input[name="skip_recognition_ids[]"]').forEach(function(input) {
        input.addEventListener('change', updateTerminationSelectionCards);
    });
    var penaltySelect = document.querySelector('select[name="penalty_pdc_id"]');
    if (penaltySelect) {
        penaltySelect.addEventListener('change', function() {
            syncPenaltyChequeExclusions();
            updateTerminationSelectionCards();
        });
    }
    var dateInput = document.getElementById('terminationDateInput');
    if (dateInput) {
        dateInput.addEventListener('change', function() {
            var confirmDate = document.getElementById('confirmTerminationDate');
            if (confirmDate) {
                confirmDate.textContent = dateInput.value;
            }
        });
    }
    syncPenaltyChequeExclusions();
    syncRecognitionRowsForSelectedItems();
    updateTerminationSelectionCards();
})();
</script>
HTML;
require_once __DIR__ . '/includes/re_layout_footer.php';
?>

