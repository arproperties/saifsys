<?php
/**
 * Owner-only tool: cleanup orphan extra rent installments.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/installment_cleanup_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

if (!has_role('Owner', $conn)) {
    http_response_code(403);
    die('Access denied. This tool is available to Owner only.');
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$report = null;
$runError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $mode = ($_POST['mode'] ?? 'dry-run') === 'apply' ? 'apply' : 'dry-run';
    $scope = $_POST['scope'] ?? 'all_company';
    $leaseId = isset($_POST['lease_id']) ? (int)$_POST['lease_id'] : 0;
    $runAllCompanies = !empty($_POST['all_companies']);

    $opts = [
        'apply' => ($mode === 'apply'),
    ];

    if (!$runAllCompanies) {
        $opts['company_id'] = $currentCompanyId;
    }
    if ($scope === 'single_lease') {
        if ($leaseId <= 0) {
            $runError = 'Please enter a valid Lease ID.';
        } else {
            $opts['lease_id'] = $leaseId;
        }
    }

    if ($runError === '') {
        try {
            $report = re_cleanup_orphan_installments($conn, $opts);
        } catch (Throwable $e) {
            $runError = $e->getMessage();
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Installments Cleanup Tool';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Installments Cleanup Tool</div>
    <span class="badge bg-danger">Owner Only</span>
</div>

<div class="alert alert-warning">
    <strong>Purpose:</strong> fix leases with extra orphan rent installments (e.g. 5 rows when contract has 4 cheques).
    <br><strong>Safety:</strong> only deletes rows that are unpaid and have no linked payments/allocations/cheques.
</div>

<?php if ($runError): ?>
    <div class="alert alert-danger"><?= h($runError) ?></div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-header"><h5 class="mb-0">Run Cleanup</h5></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?php csrf_field(); ?>

            <div class="col-md-4">
                <label class="form-label">Mode</label>
                <select name="mode" class="form-select" required>
                    <option value="dry-run" selected>Dry Run (preview only)</option>
                    <option value="apply">Apply (execute cleanup)</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Scope</label>
                <select name="scope" id="scopeSelect" class="form-select" required>
                    <option value="all_company" selected>All leases (current company)</option>
                    <option value="single_lease">Specific lease</option>
                </select>
            </div>

            <div class="col-md-4" id="leaseIdWrap" style="display:none;">
                <label class="form-label">Lease ID</label>
                <input type="number" min="1" class="form-control" name="lease_id" placeholder="e.g. 294">
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="allCompanies" name="all_companies">
                    <label class="form-check-label" for="allCompanies">
                        Include all companies (not only current company)
                    </label>
                </div>
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-play-fill"></i> Run
                </button>
                <a href="installments_cleanup_admin.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if (is_array($report)): ?>
    <div class="card card-round mb-4">
        <div class="card-header">
            <h5 class="mb-0">Result Summary (<?= h(strtoupper((string)$report['mode'])) ?>)</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-md-2"><div class="border rounded p-2"><small class="text-muted">Scanned</small><div class="h5 mb-0"><?= (int)$report['scanned'] ?></div></div></div>
                <div class="col-md-3"><div class="border rounded p-2"><small class="text-muted">With Extra</small><div class="h5 mb-0"><?= (int)$report['with_extra'] ?></div></div></div>
                <div class="col-md-2"><div class="border rounded p-2"><small class="text-muted">Fixed</small><div class="h5 mb-0 text-success"><?= (int)$report['fixed'] ?></div></div></div>
                <div class="col-md-3"><div class="border rounded p-2"><small class="text-muted">Rows Deleted</small><div class="h5 mb-0 text-danger"><?= (int)$report['rows_deleted'] ?></div></div></div>
                <div class="col-md-2"><div class="border rounded p-2"><small class="text-muted">Manual Review</small><div class="h5 mb-0 text-warning"><?= count($report['manual_review'] ?? []) ?></div></div></div>
            </div>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header"><h5 class="mb-0">Lease-Level Results</h5></div>
        <div class="card-body">
            <?php if (empty($report['lines'])): ?>
                <div class="text-muted">No leases matched cleanup conditions.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Lease ID</th>
                                <th>Lease Number</th>
                                <th>Expected</th>
                                <th>Actual</th>
                                <th>Extra</th>
                                <th>Safe Candidates</th>
                                <th>Delete IDs</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['lines'] as $line): ?>
                                <tr>
                                    <td><?= (int)$line['lease_id'] ?></td>
                                    <td><?= h($line['lease_number']) ?></td>
                                    <td><?= (int)$line['expected'] ?></td>
                                    <td><?= (int)$line['actual'] ?></td>
                                    <td><?= (int)$line['extra'] ?></td>
                                    <td><?= (int)$line['safe_candidates'] ?></td>
                                    <td><code><?= h(implode(',', $line['delete_ids'] ?? [])) ?></code></td>
                                    <td>
                                        <?php
                                        $st = (string)($line['status'] ?? '');
                                        $cls = 'secondary';
                                        if ($st === 'applied') $cls = 'success';
                                        elseif ($st === 'dry_run_ok' || $st === 'pending_apply') $cls = 'info';
                                        elseif ($st === 'manual_review') $cls = 'warning';
                                        elseif ($st === 'error') $cls = 'danger';
                                        ?>
                                        <span class="badge bg-<?= $cls ?>"><?= h($st) ?></span>
                                        <?php if (!empty($line['error'])): ?>
                                            <div class="small text-danger mt-1"><?= h($line['error']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
    (function () {
        const scope = document.getElementById('scopeSelect');
        const leaseWrap = document.getElementById('leaseIdWrap');
        function syncScope() {
            leaseWrap.style.display = scope.value === 'single_lease' ? '' : 'none';
        }
        scope.addEventListener('change', syncScope);
        syncScope();
    })();
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

