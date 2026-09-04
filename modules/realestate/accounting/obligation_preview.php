<?php
/**
 * Lease obligation preview and management.
 *
 * This page intentionally performs no writes. It previews what the future
 * obligation layer would generate for one lease and compares that preview with
 * current installments and billing items.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/obligation_engine.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;
$preview = null;
$existingObligations = [];
$obligationSummary = ['total' => 0, 'cancelled' => 0, 'settled' => 0, 'open' => 0, 'by_status' => []];
$leaseContext = null;
$error = '';
$generationFlash = $_SESSION['re_obligation_generation_flash'] ?? null;
unset($_SESSION['re_obligation_generation_flash']);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function phase2_money($n): string { return number_format((float)$n, 2); }
function phase2_tenant_name(array $row): string
{
    if (($row['tenant_type'] ?? '') === 'company') {
        return (string)($row['company_name'] ?? 'Company Tenant');
    }
    return trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')) ?: 'Tenant';
}

$selectAccountingMode = re_obligation_column_exists($conn, 're_leases', 'accounting_mode')
    ? 'l.accounting_mode'
    : "'legacy' AS accounting_mode";
$leaseOptions = $conn->prepare("
    SELECT l.id, l.lease_number, l.start_date, l.end_date, {$selectAccountingMode},
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           u.unit_number, b.name AS building_name
    FROM re_leases l
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_units u ON u.id = l.unit_id
    LEFT JOIN re_buildings b ON b.id = u.building_id
    WHERE l.company_id = ?
    ORDER BY l.id DESC
    LIMIT 250
");
$leaseOptions->execute([$currentCompanyId]);
$leases = $leaseOptions->fetchAll(PDO::FETCH_ASSOC);

if ($leaseId > 0) {
    $preview = re_obligation_preview_generate($conn, $currentCompanyId, $leaseId);
    if (empty($preview['success'])) {
        $error = (string)($preview['error'] ?? 'Could not generate preview');
        $preview = null;
    }
    $existingObligations = re_obligation_engine_existing_for_lease($conn, $currentCompanyId, $leaseId);
    $obligationSummary = re_obligation_lease_status_summary($conn, $currentCompanyId, $leaseId);
    $ctxStmt = $conn->prepare("
        SELECT id, lease_number, status, termination_date, termination_reason, COALESCE(accounting_mode, 'legacy') AS accounting_mode
        FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1
    ");
    $ctxStmt->execute([$leaseId, $currentCompanyId]);
    $leaseContext = $ctxStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$pageTitle = 'Lease Obligation Preview';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-diagram-3"></i> Lease Obligations
    </div>
    <span class="badge bg-secondary">Preview is read-only</span>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>
<?php if (is_array($generationFlash)): ?>
    <div class="alert alert-<?= !empty($generationFlash['success']) ? 'success' : 'danger' ?>">
        <?= h($generationFlash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-9">
                <label class="form-label">Lease</label>
                <select name="lease_id" class="form-select" required>
                    <option value="">-- Select lease to preview --</option>
                    <?php foreach ($leases as $lease): ?>
                        <option value="<?= (int)$lease['id'] ?>" <?= $leaseId === (int)$lease['id'] ? 'selected' : '' ?>>
                            #<?= (int)$lease['id'] ?> - <?= h($lease['lease_number'] ?? '') ?>
                            | <?= h(phase2_tenant_name($lease)) ?>
                            | <?= h(ucfirst((string)($lease['accounting_mode'] ?? 'legacy'))) ?>
                            | <?= h(trim(($lease['building_name'] ?? '') . ' ' . ($lease['unit_number'] ?? ''))) ?>
                            | <?= h($lease['start_date'] ?? '') ?> to <?= h($lease['end_date'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i> Preview
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($preview): ?>
    <?php
    $lease = $preview['lease'];
    $summary = $preview['summary'];
    $mode = $preview['accounting_mode'];
    $canGenerate = !empty($preview['feature_enabled'])
        && $mode === 'invoice'
        && re_obligation_engine_schema_ready($conn)
        && ($leaseContext['status'] ?? '') !== 'terminated';
    ?>

    <?php if (($leaseContext['status'] ?? '') === 'terminated'): ?>
        <div class="alert alert-info">
            <strong>Terminated lease</strong> — effective <?= h($leaseContext['termination_date'] ?? '') ?>.
            Scroll to <strong>Generated Obligations in re_obligations</strong> below to review cancelled vs settled rows.
            <div class="small mt-2 mb-0">
                Total obligations: <strong><?= (int)$obligationSummary['total'] ?></strong> |
                Settled: <strong><?= (int)$obligationSummary['settled'] ?></strong> |
                Cancelled: <strong><?= (int)$obligationSummary['cancelled'] ?></strong> |
                Open: <strong><?= (int)$obligationSummary['open'] ?></strong>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card card-round border-success h-100"><div class="card-body text-center">
                <div class="small text-muted">Settled</div>
                <div class="h4 mb-0 text-success"><?= (int)$obligationSummary['settled'] ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card card-round border-danger h-100"><div class="card-body text-center">
                <div class="small text-muted">Cancelled (void period)</div>
                <div class="h4 mb-0 text-danger"><?= (int)$obligationSummary['cancelled'] ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card card-round border-primary h-100"><div class="card-body text-center">
                <div class="small text-muted">Open</div>
                <div class="h4 mb-0 text-primary"><?= (int)$obligationSummary['open'] ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card card-round h-100"><div class="card-body text-center">
                <div class="small text-muted">Total in DB</div>
                <div class="h4 mb-0"><?= (int)$obligationSummary['total'] ?></div>
            </div></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-round h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase">Lease</div>
                    <h5 class="mb-1"><?= h($lease['lease_number'] ?? ('#' . $leaseId)) ?></h5>
                    <div><?= h(phase2_tenant_name($lease)) ?></div>
                    <div class="small text-muted"><?= h($lease['start_date'] ?? '') ?> to <?= h($lease['end_date'] ?? '') ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-round h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase">Feature / Mode</div>
                    <div class="mb-1">
                        Feature flag:
                        <span class="badge bg-<?= $preview['feature_enabled'] ? 'success' : 'secondary' ?>">
                            <?= $preview['feature_enabled'] ? 'Enabled' : 'Disabled' ?>
                        </span>
                    </div>
                    <div>
                        Accounting mode:
                        <span class="badge bg-<?= $mode === 'invoice' ? 'primary' : 'secondary' ?>">
                            <?= h(ucfirst($mode)) ?>
                        </span>
                    </div>
                    <small class="text-muted d-block mt-2">Legacy leases are preview-only and cannot be generated by the engine.</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-round h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase">Preview Obligations</div>
                    <h4 class="mb-0"><?= count($preview['preview_obligations']) ?></h4>
                    <small class="text-muted">Generated in memory only</small>
                    <?php if ($canGenerate): ?>
                        <form method="post" action="obligation_generate.php" class="mt-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="lease_id" value="<?= (int)$leaseId ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                    onclick="return confirm('Generate obligations for this Invoice Mode lease? This writes only to re_obligations.');">
                                <i class="bi bi-arrow-repeat"></i> Generate/Refresh Obligations
                            </button>
                        </form>
                    <?php elseif ($mode !== 'invoice'): ?>
                        <small class="text-muted d-block mt-2">Generation disabled: Legacy Mode lease.</small>
                    <?php elseif (($leaseContext['status'] ?? '') === 'terminated'): ?>
                        <small class="text-muted d-block mt-2">Generation disabled: lease is terminated. Review existing obligations below.</small>
                    <?php elseif (empty($preview['feature_enabled'])): ?>
                        <small class="text-muted d-block mt-2">Generation disabled: feature flag is off.</small>
                    <?php else: ?>
                        <small class="text-muted d-block mt-2">Generation disabled: obligation tables are missing.</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-round border-primary h-100">
                <div class="card-body text-center">
                    <h6 class="text-muted text-uppercase small">Preview Total</h6>
                    <div class="h4 text-primary mb-0">AED <?= phase2_money($summary['preview_total']) ?></div>
                    <small class="text-muted">
                        Subtotal AED <?= phase2_money($summary['preview_subtotal']) ?> |
                        VAT AED <?= phase2_money($summary['preview_vat']) ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-round border-info h-100">
                <div class="card-body text-center">
                    <h6 class="text-muted text-uppercase small">Current Installments</h6>
                    <div class="h4 text-info mb-0">AED <?= phase2_money($summary['installment_total']) ?></div>
                    <small class="text-muted">
                        <?= count($preview['current_installments']) ?> rows |
                        VAT AED <?= phase2_money($summary['installment_vat_total']) ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-round border-warning h-100">
                <div class="card-body text-center">
                    <h6 class="text-muted text-uppercase small">Billing Service Sources</h6>
                    <div class="h4 text-warning mb-0">AED <?= phase2_money($summary['billing_item_total']) ?></div>
                    <small class="text-muted">
                        <?= count($preview['current_billing_items']) ?> service source row<?= count($preview['current_billing_items']) === 1 ? '' : 's' ?> |
                        VAT AED <?= phase2_money($summary['billing_item_tax_total']) ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header bg-white">
            <h6 class="mb-0">Preview Obligations (In Memory Only)</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>Class</th>
                            <th>Description</th>
                            <th>Period</th>
                            <th>Due Date</th>
                            <th>Tax</th>
                            <th>Key</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">VAT</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview['preview_obligations'] as $row): ?>
                        <tr>
                            <td><?= h(re_obligation_display_label(
                                $row['obligation_type'] ?? null,
                                $row['source_type'] ?? null,
                                $row['description'] ?? null,
                                null
                            )) ?></td>
                            <td><?= h($row['accounting_class']) ?></td>
                            <td>
                                <?= h($row['description']) ?>
                                <?php if (($row['source_type'] ?? '') === 'billing_item'): ?>
                                    <br><small class="text-muted">Source billing item #<?= h($row['source_id'] ?? '') ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['period_start']) || !empty($row['period_end'])): ?>
                                    <?= h($row['period_start']) ?> to <?= h($row['period_end']) ?>
                                <?php else: ?>
                                    <span class="text-muted">Immediate</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($row['due_date']) ?></td>
                            <td><?= h($row['tax_treatment']) ?> <?= (float)$row['tax_rate'] > 0 ? h($row['tax_rate']) . '%' : '' ?></td>
                            <td><small class="text-muted"><?= h($row['obligation_key'] ?? '') ?></small></td>
                            <td class="text-end"><?= phase2_money($row['subtotal_amount']) ?></td>
                            <td class="text-end"><?= phase2_money($row['vat_amount']) ?></td>
                            <td class="text-end fw-semibold"><?= phase2_money($row['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($preview['preview_obligations'])): ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">No preview obligations generated.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Generated Obligations in re_obligations</h6>
            <span class="badge bg-light text-dark"><?= count($existingObligations) ?> row<?= count($existingObligations) === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Recognition</th>
                            <th>Due Date</th>
                            <th>Key</th>
                            <th>Engine</th>
                            <th class="text-end">Allocated</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($existingObligations as $row): ?>
                        <?php
                        $rowClass = match ((string)($row['status'] ?? '')) {
                            'cancelled' => 'table-danger',
                            'settled' => 'table-success',
                            'open' => '',
                            default => '',
                        };
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td>#<?= (int)$row['id'] ?></td>
                            <td><?= h(re_obligation_display_label(
                                $row['obligation_type'] ?? null,
                                $row['source_type'] ?? null,
                                $row['description'] ?? null,
                                null
                            )) ?></td>
                            <td><span class="badge bg-<?= ($row['status'] ?? '') === 'cancelled' ? 'danger' : (($row['status'] ?? '') === 'settled' ? 'success' : 'secondary') ?>"><?= h($row['status']) ?></span></td>
                            <td><?= h($row['recognition_status'] ?? '') ?></td>
                            <td><?= h($row['due_date']) ?></td>
                            <td><small class="text-muted"><?= h($row['obligation_key'] ?? '') ?></small></td>
                            <td>
                                <?= h($row['engine_version'] ?? '') ?>
                                <?php if (!empty($row['generated_at'])): ?>
                                    <br><small class="text-muted"><?= h($row['generated_at']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= phase2_money($row['allocated_amount'] ?? 0) ?></td>
                            <td class="text-end fw-semibold"><?= phase2_money($row['total_amount'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($existingObligations)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">No generated obligations found for this lease.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card card-round h-100">
                <div class="card-header bg-white">
                    <h6 class="mb-0">Current Installments</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-end">VAT</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($preview['current_installments'] as $row):
                                $instStatus = (string)($row['display_status'] ?? $row['status'] ?? 'pending');
                                $instBadge = match ($instStatus) {
                                    'paid' => 'success',
                                    'partial' => 'info',
                                    'cancelled' => 'secondary',
                                    default => 'warning',
                                };
                            ?>
                                <tr>
                                    <td><?= h($row['installment_date'] ?? '') ?></td>
                                    <td><?= h($row['installment_type'] ?: 'rent') ?></td>
                                    <td><span class="badge bg-<?= $instBadge ?>"><?= h($instStatus) ?></span></td>
                                    <td class="text-end"><?= phase2_money($row['amount'] ?? 0) ?></td>
                                    <td class="text-end"><?= phase2_money($row['vat_amount'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($preview['current_installments'])): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No installments found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card card-round h-100">
                <div class="card-header bg-white">
                    <h6 class="mb-0">Billing Service Sources</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Due Date</th>
                                    <th>Type</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-end">VAT</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($preview['current_billing_items'] as $row):
                                $biStatus = (string)($row['display_status'] ?? (!empty($row['is_paid']) ? 'paid' : ($row['status'] ?? 'pending')));
                                $biBadge = match ($biStatus) {
                                    'paid' => 'success',
                                    'partial' => 'info',
                                    'waived' => 'secondary',
                                    default => 'warning',
                                };
                            ?>
                                <tr>
                                    <td><?= h($row['due_date'] ?? '') ?></td>
                                    <td><?= h($row['display_type'] ?? $row['item_type'] ?? '') ?></td>
                                    <td><?= h($row['display_name'] ?? $row['item_name'] ?? $row['description'] ?? '') ?></td>
                                    <td><span class="badge bg-<?= $biBadge ?>"><?= h($biStatus) ?></span></td>
                                    <td class="text-end"><?= phase2_money($row['total_amount'] ?? $row['amount'] ?? 0) ?></td>
                                    <td class="text-end"><?= phase2_money($row['tax_amount'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($preview['current_billing_items'])): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No service-source billing items found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>

