<?php
/**
 * Construction Suppliers / AP Diagnostics (Phase 0) — read-only.
 */
require_once __DIR__ . '/construction_report_helpers.php';
require_once __DIR__ . '/../includes/construction_supplier_ap_helpers.php';

$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$auditReady = co_supplier_ap_audit_schema_ready($conn);
$sections = co_supplier_ap_diagnostics($conn, $cid);

$pageTitle = 'Supplier / AP Diagnostics';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h1 class="h4 mb-0">Supplier / AP Diagnostics</h1>
        <p class="text-muted mb-0">Read-only checks for Construction Suppliers payables. Does not post, reverse, or migrate data.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="../supplier_invoices.php">Supplier Invoices</a>
        <a class="btn btn-outline-secondary btn-sm" href="supplier_aging.php">Supplier Aging</a>
    </div>
</div>

<?php if (!$auditReady): ?>
<div class="alert alert-warning">
    Run <code>migrations/construction_supplier_ap_phase0.sql</code> to enable the Suppliers AP audit trail.
</div>
<?php else: ?>
<div class="alert alert-success py-2">AP audit table ready (<code>co_supplier_ap_audit</code>).</div>
<?php endif; ?>

<div class="alert alert-info py-2 small">
    Phase 0 hardening: posted invoices only on aging; fail-closed company context on supplier pages;
    duplicate GL post blocked when <code>journal_id</code> already set.
</div>

<?php foreach ($sections as $section): ?>
<div class="card card-round mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><?= h($section['title'] ?? 'Check') ?></strong>
        <span class="badge bg-secondary"><?= count($section['rows'] ?? []) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($section['rows'])): ?>
            <div class="text-center text-muted py-3">None found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <?php foreach (array_keys($section['rows'][0]) as $col): ?>
                                <th><?= h($col) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($section['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($row as $val): ?>
                                <td><?= h(is_scalar($val) || $val === null ? (string)$val : json_encode($val)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
