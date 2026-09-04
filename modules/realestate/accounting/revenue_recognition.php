<?php
/**
 * Revenue Recognition — Monthly Deferred Rent → Income
 *
 * Shows all pending recognition schedule rows and lets the user run
 * the recognition for all or selected leases up to a chosen date.
 *
 * Accounting entry per row when "Run Recognition" is clicked:
 *   Dr. Deferred Rent Revenue [2410]   amount
 *     Cr. Rental Income [4110]         amount
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/accounting_integration.php';
require_once __DIR__ . '/../includes/accounting_mode_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand            = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId           = $_SESSION['user_id'] ?? null;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return number_format((float)$n, 2); }

$success = $error = null;
$invoiceLeaseFilter = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;

// ── Handle POST: run recognition ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    if ($_POST['action'] === 'run_recognition') {
        $upToDate  = !empty($_POST['up_to_date']) ? $_POST['up_to_date'] : date('Y-m-d');
        $leaseIds  = !empty($_POST['lease_ids']) ? array_map('intval', (array)$_POST['lease_ids']) : [];

        $result = process_revenue_recognition($currentCompanyId, $userId, $upToDate, $leaseIds);

        if ($result['recognised'] > 0 && empty($result['errors'])) {
            $success = "Successfully recognised revenue for {$result['recognised']} installment(s). Journal entries have been posted.";
        } elseif ($result['recognised'] > 0) {
            $success = "Recognised {$result['recognised']} installment(s) with some errors: " . implode('; ', $result['errors']);
        } elseif (!empty($result['errors'])) {
            $error = "Recognition failed: " . implode('; ', $result['errors']);
        } else {
            $success = "No pending recognition rows found for the selected date range. Nothing to process.";
        }
    }

    if ($_POST['action'] === 'skip_row') {
        $rowId = (int)($_POST['row_id'] ?? 0);
        if ($rowId) {
            $conn->prepare("
                UPDATE re_rent_recognition_schedule
                SET status = 'skipped', notes = CONCAT(COALESCE(notes,''), ' [Skipped manually]')
                WHERE id = ? AND company_id = ? AND status = 'pending'
            ")->execute([$rowId, $currentCompanyId]);
            $success = "Row #{$rowId} skipped.";
        }
    }
}

// ── Load pending rows ──────────────────────────────────────────────────────
$filterStatus  = $_GET['status'] ?? 'pending';
$filterLease   = trim($_GET['lease_search'] ?? '');
$statusOptions = ['pending' => 'Pending', 'recognised' => 'Recognised', 'skipped' => 'Skipped', '' => 'All'];

$where  = 'rrs.company_id = ?';
$params = [$currentCompanyId];

if ($filterStatus !== '') {
    $where  .= ' AND rrs.status = ?';
    $params[] = $filterStatus;
}
if ($filterLease !== '') {
    $where  .= ' AND (l.lease_number LIKE ? OR CONCAT(t.first_name," ",t.last_name) LIKE ? OR t.company_name LIKE ?)';
    $like    = '%' . $filterLease . '%';
    $params  = array_merge($params, [$like, $like, $like]);
}

$stmt = $conn->prepare("
    SELECT rrs.*,
           l.lease_number, l.annual_rent,
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           b.name AS building_name, u.unit_number,
           jh.journal_number AS recognition_journal_number
    FROM re_rent_recognition_schedule rrs
    JOIN re_leases l  ON l.id  = rrs.lease_id
    JOIN re_tenants t ON t.id  = l.tenant_id
    JOIN re_units   u ON u.id  = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_journal_headers jh ON jh.id = rrs.recognition_journal_id
    WHERE $where
    ORDER BY rrs.recognition_date ASC, rrs.id ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary counts ─────────────────────────────────────────────────────────
$summaryStmt = $conn->prepare("
    SELECT status,
           COUNT(*) AS cnt,
           SUM(amount) AS total
    FROM re_rent_recognition_schedule
    WHERE company_id = ?
    GROUP BY status
");
$summaryStmt->execute([$currentCompanyId]);
$summaryRaw = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);
$summary = [];
foreach ($summaryRaw as $s) {
    $summary[$s['status']] = $s;
}

// Count how many pending rows are actually ready (have a payment) vs waiting
$readyStmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN deferred_payment_id IS NOT NULL THEN 1 ELSE 0 END) AS ready_cnt,
        SUM(CASE WHEN deferred_payment_id IS NOT NULL THEN amount ELSE 0 END) AS ready_total,
        SUM(CASE WHEN deferred_payment_id IS NULL THEN 1 ELSE 0 END) AS waiting_cnt,
        SUM(CASE WHEN deferred_payment_id IS NULL THEN amount ELSE 0 END) AS waiting_total
    FROM re_rent_recognition_schedule
    WHERE company_id = ? AND status = 'pending'
");
$readyStmt->execute([$currentCompanyId]);
$readySummary = $readyStmt->fetch(PDO::FETCH_ASSOC);

// ── Leases with deferred mode (for the Run Recognition filter) ─────────────
$leaseStmt = $conn->prepare("
    SELECT l.id, l.lease_number,
           CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
           b.name AS building_name, u.unit_number
    FROM re_leases l
    JOIN re_tenants t ON t.id = l.tenant_id
    JOIN re_units u   ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE l.company_id = ? AND l.deferred_revenue_mode = 1
    ORDER BY l.lease_number
");
$leaseStmt->execute([$currentCompanyId]);
$deferredLeases = $leaseStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Invoice Mode recognition diagnostics ────────────────────────────────────
$invoiceModeDiagnosticsReady = re_obligation_table_exists($conn, 're_obligations')
    && re_obligation_table_exists($conn, 're_invoice_candidates')
    && re_obligation_table_exists($conn, 're_invoice_items');
$invoiceModeRows = [];
$invoiceModeSummary = [
    'recognised' => 0,
    'pending' => 0,
    'issued_without_journal' => 0,
    'obligations_without_invoice' => 0,
];
if ($invoiceModeDiagnosticsReady) {
    $invoiceWhere = "o.company_id = ? AND l.accounting_mode = 'invoice' AND o.accounting_class <> 'liability' AND o.obligation_type <> 'security_deposit'";
    $invoiceParams = [$currentCompanyId];
    if ($invoiceLeaseFilter > 0) {
        $invoiceWhere .= ' AND o.lease_id = ?';
        $invoiceParams[] = $invoiceLeaseFilter;
    }

    $stmt = $conn->prepare("
        SELECT o.id, o.lease_id, o.obligation_type, o.description, o.period_start, o.period_end, o.due_date,
               o.subtotal_amount, o.vat_amount, o.total_amount, o.status, o.recognition_status,
               o.recognition_journal_id,
               l.lease_number,
               COALESCE(NULLIF(t.company_name, ''), CONCAT(t.first_name, ' ', t.last_name)) AS tenant_name,
               c.id AS candidate_id, c.status AS candidate_status, c.eligible_on, c.invoice_id AS candidate_invoice_id,
               ii.invoice_id AS line_invoice_id,
               inv.invoice_number, inv.invoice_date,
               jh.journal_number
        FROM re_obligations o
        JOIN re_leases l ON l.id = o.lease_id AND l.company_id = o.company_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN re_invoice_candidates c ON c.obligation_id = o.id AND c.company_id = o.company_id
        LEFT JOIN re_invoice_items ii ON ii.obligation_id = o.id AND ii.company_id = o.company_id
        LEFT JOIN re_invoices inv ON inv.id = COALESCE(c.invoice_id, ii.invoice_id) AND inv.company_id = o.company_id
        LEFT JOIN re_journal_headers jh ON jh.id = o.recognition_journal_id
        WHERE $invoiceWhere
        ORDER BY o.due_date ASC, o.id ASC
        LIMIT 200
    ");
    $stmt->execute($invoiceParams);
    $invoiceModeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($invoiceModeRows as $row) {
        if (($row['recognition_status'] ?? '') === 'recognised') {
            $invoiceModeSummary['recognised']++;
        } else {
            $invoiceModeSummary['pending']++;
        }
        if (!empty($row['line_invoice_id']) && empty($row['recognition_journal_id'])) {
            $invoiceModeSummary['issued_without_journal']++;
        }
        if (empty($row['line_invoice_id'])) {
            $invoiceModeSummary['obligations_without_invoice']++;
        }
    }
}

$csrfToken = csrf_token();
$pageTitle = 'Revenue Recognition';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="page-header-label">
        <i class="bi bi-arrow-repeat text-success"></i> Revenue Recognition
    </div>
    <div class="text-muted small">
        Legacy: Deferred Revenue recognition. Invoice Mode: recognition happens when an invoice candidate is issued.
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle"></i> <?= h($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Summary Cards ────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="card card-round border-success">
            <div class="card-body text-center py-3">
                <div class="text-muted small"><i class="bi bi-play-circle text-success"></i> Ready to Recognise</div>
                <div class="fw-bold fs-5 text-success"><?= (int)($readySummary['ready_cnt'] ?? 0) ?> rows</div>
                <div class="text-muted small"><?= money($readySummary['ready_total'] ?? 0) ?> AED</div>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card card-round border-warning">
            <div class="card-body text-center py-3">
                <div class="text-muted small"><i class="bi bi-clock text-warning"></i> Waiting for Payment</div>
                <div class="fw-bold fs-5 text-warning"><?= (int)($readySummary['waiting_cnt'] ?? 0) ?> rows</div>
                <div class="text-muted small"><?= money($readySummary['waiting_total'] ?? 0) ?> AED</div>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card card-round border-success">
            <div class="card-body text-center py-3">
                <div class="text-muted small"><i class="bi bi-check-circle text-success"></i> Recognised</div>
                <div class="fw-bold fs-5 text-success"><?= $summary['recognised']['cnt'] ?? 0 ?> rows</div>
                <div class="text-muted small"><?= money($summary['recognised']['total'] ?? 0) ?> AED</div>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card card-round border-secondary">
            <div class="card-body text-center py-3">
                <div class="text-muted small"><i class="bi bi-skip-forward text-secondary"></i> Skipped</div>
                <div class="fw-bold fs-5 text-secondary"><?= $summary['skipped']['cnt'] ?? 0 ?> rows</div>
                <div class="text-muted small"><?= money($summary['skipped']['total'] ?? 0) ?> AED</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Invoice Mode Diagnostics ────────────────────────────────────────────── -->
<div class="card card-round mb-4 border-primary">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-clipboard2-pulse"></i> Invoice Mode Recognition Diagnostics</h5>
        <span class="badge bg-primary">Option A: Invoice issuance posts revenue</span>
    </div>
    <div class="card-body">
        <?php if (!$invoiceModeDiagnosticsReady): ?>
            <div class="alert alert-warning mb-0">Invoice Mode diagnostic tables are not available yet. Run the Phase 1-3 migrations first.</div>
        <?php else: ?>
            <form method="GET" class="row g-2 align-items-end mb-3">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Lease ID filter</label>
                    <input type="number" name="lease_id" class="form-control form-control-sm" value="<?= $invoiceLeaseFilter ?: '' ?>" placeholder="Optional">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-outline-primary w-100">Load Diagnostics</button>
                </div>
                <?php if ($invoiceLeaseFilter): ?>
                    <div class="col-md-2">
                        <a href="revenue_recognition.php" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
                    </div>
                <?php endif; ?>
            </form>
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="border rounded p-3"><div class="small text-muted">Recognised Obligations</div><div class="h5 mb-0 text-success"><?= (int)$invoiceModeSummary['recognised'] ?></div></div></div>
                <div class="col-md-3"><div class="border rounded p-3"><div class="small text-muted">Pending / Not Issued</div><div class="h5 mb-0 text-warning"><?= (int)$invoiceModeSummary['pending'] ?></div></div></div>
                <div class="col-md-3"><div class="border rounded p-3"><div class="small text-muted">Issued Without Journal</div><div class="h5 mb-0 text-danger"><?= (int)$invoiceModeSummary['issued_without_journal'] ?></div></div></div>
                <div class="col-md-3"><div class="border rounded p-3"><div class="small text-muted">Without Issued Invoice</div><div class="h5 mb-0 text-secondary"><?= (int)$invoiceModeSummary['obligations_without_invoice'] ?></div></div></div>
            </div>
            <?php if (empty($invoiceModeRows)): ?>
                <div class="text-center text-muted py-3">No Invoice Mode obligations found for this filter.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Lease</th>
                                <th>Tenant</th>
                                <th>Obligation</th>
                                <th>Period / Due</th>
                                <th class="text-end">Amount</th>
                                <th>Invoice</th>
                                <th>Recognition</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoiceModeRows as $row): ?>
                                <tr>
                                    <td><a href="../lease_view.php?id=<?= (int)$row['lease_id'] ?>"><?= h($row['lease_number']) ?></a></td>
                                    <td><?= h($row['tenant_name']) ?></td>
                                    <td>
                                        <strong><?= h(ucwords(str_replace('_', ' ', (string)$row['obligation_type']))) ?></strong><br>
                                        <small class="text-muted"><?= h($row['description']) ?></small>
                                    </td>
                                    <td class="small">
                                        <?= h($row['period_start'] ?: '-') ?> to <?= h($row['period_end'] ?: '-') ?><br>
                                        Due: <?= h($row['due_date']) ?>
                                    </td>
                                    <td class="text-end"><?= money($row['total_amount']) ?> AED</td>
                                    <td>
                                        <?php if (!empty($row['invoice_number'])): ?>
                                            <span class="badge bg-success"><?= h($row['invoice_number']) ?></span><br>
                                            <small class="text-muted"><?= h($row['invoice_date']) ?></small>
                                        <?php elseif (!empty($row['candidate_id'])): ?>
                                            <span class="badge bg-warning text-dark"><?= h($row['candidate_status']) ?></span><br>
                                            <small class="text-muted">Eligible: <?= h($row['eligible_on']) ?></small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No candidate</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (($row['recognition_status'] ?? '') === 'recognised'): ?>
                                            <span class="badge bg-success">Recognised</span>
                                            <?php if (!empty($row['recognition_journal_id'])): ?>
                                                <br><a class="small" href="journal_entry_view.php?id=<?= (int)$row['recognition_journal_id'] ?>"><?= h($row['journal_number'] ?: ('Journal #' . $row['recognition_journal_id'])) ?></a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><?= h($row['recognition_status'] ?: 'pending') ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Run Recognition Panel ────────────────────────────────────────────────── -->
<div class="card card-round mb-4" style="border: 2px solid #198754;">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="bi bi-play-circle"></i> Run Legacy Deferred Revenue Recognition</h5>
    </div>
    <div class="card-body">
        <?php if (empty($deferredLeases)): ?>
        <div class="alert alert-info mb-0">
            No leases are currently set to <strong>Accrual / Deferred Revenue Mode</strong>.
            To enable this, edit a lease and turn on the <em>Accrual Mode</em> toggle in the
            Fee Distribution &amp; Accounting Settings section.
        </div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="run_recognition">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Recognise Up To Date</label>
                    <input type="date" name="up_to_date" class="form-control"
                           value="<?= h(date('Y-m-d')) ?>" required>
                    <small class="text-muted">All pending rows with recognition_date ≤ this date will be processed.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Filter by Lease (optional — leave blank for all)</label>
                    <select name="lease_ids[]" class="form-select" multiple size="4">
                        <?php foreach ($deferredLeases as $dl): ?>
                        <option value="<?= $dl['id'] ?>">
                            <?= h($dl['lease_number']) ?> — <?= h($dl['building_name'] . ' ' . $dl['unit_number']) ?> — <?= h($dl['tenant_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Hold Ctrl/Cmd to select multiple. Leave unselected to run for <em>all</em> deferred leases.</small>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success btn-lg w-100"
                            onclick="return confirm('This will post journal entries for all pending recognition rows up to the selected date. Continue?')">
                        <i class="bi bi-play-circle"></i> Run Recognition
                    </button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ── Filter Bar ────────────────────────────────────────────────────────────── -->
<div class="card card-round mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach ($statusOptions as $val => $lbl): ?>
                    <option value="<?= h($val) ?>" <?= $filterStatus === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1">Search Lease / Tenant</label>
                <input type="text" name="lease_search" class="form-control form-control-sm"
                       value="<?= h($filterLease) ?>" placeholder="Lease number, tenant name…">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100">Filter</button>
            </div>
            <div class="col-md-2">
                <a href="revenue_recognition.php" class="btn btn-outline-secondary btn-sm w-100">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Schedule Table ────────────────────────────────────────────────────────── -->
<div class="card card-round">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-table"></i> Recognition Schedule</h6>
        <span class="badge bg-secondary"><?= count($rows) ?> rows</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
        <div class="text-center text-muted py-4">No records found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Recognition Date</th>
                        <th>Lease</th>
                        <th>Unit</th>
                        <th>Tenant</th>
                        <th class="text-end">Amount (AED)</th>
                        <th>Status</th>
                        <th>Journal</th>
                        <th>Recognised At</th>
                        <th class="no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row):
                        $tenantName = $row['tenant_type'] === 'company'
                            ? $row['company_name']
                            : ($row['first_name'] . ' ' . $row['last_name']);
                        $badgeClass = match($row['status']) {
                            'recognised' => 'success',
                            'skipped'    => 'secondary',
                            default      => 'warning text-dark',
                        };
                    ?>
                    <tr>
                        <td><?= h($row['recognition_date']) ?></td>
                        <td><a href="../lease_view.php?id=<?= $row['lease_id'] ?>"><?= h($row['lease_number']) ?></a></td>
                        <td><?= h($row['building_name'] . ' - ' . $row['unit_number']) ?></td>
                        <td><?= h($tenantName) ?></td>
                        <td class="text-end fw-bold"><?= money($row['amount']) ?></td>
                        <td>
                            <span class="badge bg-<?= $badgeClass ?>"><?= ucfirst($row['status']) ?></span>
                            <?php if ($row['status'] === 'pending' && empty($row['deferred_payment_id'])): ?>
                                <br><small class="text-warning" title="No payment has been recorded for this period yet. Record a payment first, then run recognition.">
                                    <i class="bi bi-exclamation-triangle-fill"></i> No payment yet
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['recognition_journal_number']): ?>
                                <a href="journal_entry_view.php?id=<?= $row['recognition_journal_id'] ?>" class="small">
                                    <?= h($row['recognition_journal_number']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= $row['recognised_at'] ? date('d M Y', strtotime($row['recognised_at'])) : '—' ?>
                        </td>
                        <td class="no-print">
                            <?php if ($row['status'] === 'pending'): ?>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Skip this recognition row? It will not be processed automatically.')">
                                <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="skip_row">
                                <input type="hidden" name="row_id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-skip-forward"></i> Skip
                                </button>
                            </form>
                            <?php elseif ($row['status'] === 'recognised'): ?>
                            <a href="journal_entry_view.php?id=<?= $row['recognition_journal_id'] ?>"
                               class="btn btn-sm btn-outline-success">
                                <i class="bi bi-eye"></i> View Journal
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">Total</td>
                        <td class="text-end"><?= money(array_sum(array_column($rows, 'amount'))) ?> AED</td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
