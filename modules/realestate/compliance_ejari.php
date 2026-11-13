<?php
/**
 * Real Estate Module - Ejari Tracking Management
 * Track Ejari registration for Dubai leases
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/compliance_ui.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'edit') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $leaseId = (int)$_POST['lease_id'];
        $ejariNumber = $_POST['ejari_number'] ?? '';
        $registrationDate = $_POST['registration_date'] ?? null;
        $expiryDate = $_POST['expiry_date'] ?? null;
        $registrationStatus = $_POST['registration_status'] ?? 'pending';
        $registrationFee = !empty($_POST['registration_fee']) ? (float)$_POST['registration_fee'] : null;
        $notes = $_POST['notes'] ?? '';
        
        if ($leaseId && $ejariNumber) {
            if ($id) {
                // Update
                $stmt = $conn->prepare("
                    UPDATE re_ejari_tracking
                    SET lease_id = ?,
                        ejari_number = ?,
                        registration_date = ?,
                        expiry_date = ?,
                        registration_status = ?,
                        registration_fee = ?,
                        notes = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $leaseId, $ejariNumber, $registrationDate, $expiryDate,
                    $registrationStatus, $registrationFee, $notes, $id, $currentCompanyId
                ]);
            } else {
                // Insert
                $stmt = $conn->prepare("
                    INSERT INTO re_ejari_tracking
                    (company_id, lease_id, ejari_number, registration_date, expiry_date, 
                     registration_status, registration_fee, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $leaseId, $ejariNumber, $registrationDate, $expiryDate,
                    $registrationStatus, $registrationFee, $notes, $currentUserId
                ]);
            }
            $_SESSION['success'] = 'Ejari record saved successfully.';
            header('Location: compliance_ejari.php');
            exit;
        }
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';

// Build query
$where = ["e.company_id = ?"];
$params = [$currentCompanyId];

if ($statusFilter !== 'all') {
    $where[] = "e.registration_status = ?";
    $params[] = $statusFilter;
}

// Get Ejari records
$ejariRecords = $conn->prepare("
    SELECT 
        e.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        DATEDIFF(e.expiry_date, CURDATE()) as days_until_expiry
    FROM re_ejari_tracking e
    JOIN re_leases l ON l.id = e.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY 
        CASE e.registration_status
            WHEN 'expired' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'registered' THEN 3
            ELSE 4
        END,
        e.expiry_date ASC
");
$ejariRecords->execute($params);
$ejariRecords = $ejariRecords->fetchAll(PDO::FETCH_ASSOC);

// Get active leases for dropdown
$leases = $conn->prepare("
    SELECT 
        l.id,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ? AND l.status = 'active'
    ORDER BY b.name, u.unit_number
");
$leases->execute([$currentCompanyId]);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN registration_status = 'registered' THEN 1 END) as registered,
        COUNT(CASE WHEN registration_status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN registration_status = 'expired' THEN 1 END) as expired,
        COUNT(CASE WHEN registration_status = 'registered' AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as expiring_soon
    FROM re_ejari_tracking
    WHERE company_id = ?
");
$stats->execute([$currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

// Ejari certificates uploaded on active leases (Documents tab of each lease)
$leaseEjari = $conn->prepare("
    SELECT l.id AS lease_id, l.lease_number, l.start_date, l.end_date, l.ejari_registration_number,
           u.unit_number, b.name AS building_name,
           t.tenant_type, t.company_name, t.first_name, t.last_name,
           x.doc_id, x.expiry_date, x.file_name,
           DATEDIFF(x.expiry_date, CURDATE()) AS days_left
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN (
        SELECT d.related_id, d.id AS doc_id, d.expiry_date, d.file_name
        FROM re_documents d
        WHERE d.company_id = ? AND d.related_type = 'lease' AND d.document_type = 'ejari'
          AND d.status NOT IN ('renewed','cancelled')
          AND NOT EXISTS (SELECT 1 FROM re_documents n
                          WHERE n.company_id = d.company_id AND n.related_type = 'lease' AND n.related_id = d.related_id
                            AND n.document_type = 'ejari' AND n.status NOT IN ('renewed','cancelled')
                            AND (COALESCE(n.expiry_date,'1000-01-01') > COALESCE(d.expiry_date,'1000-01-01')
                                 OR (COALESCE(n.expiry_date,'1000-01-01') = COALESCE(d.expiry_date,'1000-01-01') AND n.id > d.id)))
    ) x ON x.related_id = l.id
    WHERE l.company_id = ? AND l.status = 'active' AND l.deleted_at IS NULL
    ORDER BY (x.doc_id IS NULL) ASC, x.expiry_date ASC, b.name, u.unit_number
");
$leaseEjari->execute([$currentCompanyId, $currentCompanyId]);
$leaseEjari = $leaseEjari->fetchAll(PDO::FETCH_ASSOC);

$ejBand = static function (array $r): string {
    if (!$r['doc_id']) return 'missing';
    if ($r['expiry_date'] === null) return 'valid';
    $d = (int)$r['days_left'];
    return $d < 0 ? 'expired' : ($d <= 30 ? 'soon' : 'valid');
};
$ejCounts = ['valid' => 0, 'soon' => 0, 'expired' => 0, 'missing' => 0];
foreach ($leaseEjari as &$r) { $r['band'] = $ejBand($r); $ejCounts[$r['band']]++; }
unset($r);
// Show problems first: expired, expiring, missing, then valid
$bandOrder = ['expired' => 0, 'soon' => 1, 'missing' => 2, 'valid' => 3];
usort($leaseEjari, static function ($a, $b) use ($bandOrder) {
    return [$bandOrder[$a['band']], $a['days_left'] ?? PHP_INT_MAX] <=> [$bandOrder[$b['band']], $b['days_left'] ?? PHP_INT_MAX];
});

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
$tenantName = static function (array $r): string {
    if (($r['tenant_type'] ?? '') === 'company' && trim((string)($r['company_name'] ?? '')) !== '') return trim((string)$r['company_name']);
    return trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
};
$regPill = [
    'registered' => ['bg-green', 'Registered'], 'pending' => ['bg-amber', 'Pending'], 'expired' => ['bg-red', 'Expired'],
    'renewed' => ['bg-blue', 'Renewed'], 'cancelled' => ['bg-grey', 'Cancelled'],
];
$statusTabs = ['all' => 'All', 'pending' => 'Pending', 'registered' => 'Registered', 'expired' => 'Expired', 'renewed' => 'Renewed'];

// Set page title and include layout
$pageTitle = 'Ejari Tracking';
require_once __DIR__ . '/includes/re_layout_header.php';

re_cx_header(
    'ejari',
    'Ejari Tracking',
    'Ejari certificate status for every active lease, plus manual Ejari registration records.',
    '<button type="button" class="cx-btn cx-btn-primary" data-bs-toggle="modal" data-bs-target="#ejariModal" onclick="resetForm()"><i class="bi bi-plus-lg"></i> Add Ejari record</button>'
);
re_cx_flash();
?>

<div class="cx-kpis">
    <div class="cx-kpi"><div class="cx-kpi-icon bg-green"><i class="bi bi-patch-check"></i></div>
        <div><div class="cx-kpi-label">Valid</div><div class="cx-kpi-value t-green"><?= $ejCounts['valid'] ?></div>
             <div class="cx-kpi-note">active leases</div></div></div>
    <div class="cx-kpi"><div class="cx-kpi-icon bg-amber"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="cx-kpi-label">Expiring in 30 days</div><div class="cx-kpi-value t-amber"><?= $ejCounts['soon'] ?></div>
             <div class="cx-kpi-note">renew with the lease</div></div></div>
    <div class="cx-kpi"><div class="cx-kpi-icon bg-red"><i class="bi bi-x-octagon"></i></div>
        <div><div class="cx-kpi-label">Expired</div><div class="cx-kpi-value t-red"><?= $ejCounts['expired'] ?></div>
             <div class="cx-kpi-note">lease still active</div></div></div>
    <div class="cx-kpi"><div class="cx-kpi-icon bg-grey"><i class="bi bi-file-earmark-x"></i></div>
        <div><div class="cx-kpi-label">No Ejari on file</div><div class="cx-kpi-value"><?= $ejCounts['missing'] ?></div>
             <div class="cx-kpi-note">of <?= count($leaseEjari) ?> active leases</div></div></div>
</div>

<!-- Ejari certificates on active leases -->
<section class="cx-card" aria-labelledby="cx-ej-title">
    <div class="cx-card-head">
        <div><h2 class="cx-card-title" id="cx-ej-title"><i class="bi bi-file-earmark-text"></i> Ejari on active leases</h2>
             <p class="cx-card-sub">From the Ejari certificate uploaded in each lease's Documents. Problems first.</p></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="cx-chips" role="group" aria-label="Filter">
                <button type="button" class="cx-chip is-active" data-ej-filter="all">All <?= count($leaseEjari) ?></button>
                <button type="button" class="cx-chip" data-ej-filter="expired">Expired <?= $ejCounts['expired'] ?></button>
                <button type="button" class="cx-chip" data-ej-filter="soon">Expiring <?= $ejCounts['soon'] ?></button>
                <button type="button" class="cx-chip" data-ej-filter="missing">No Ejari <?= $ejCounts['missing'] ?></button>
                <button type="button" class="cx-chip" data-ej-filter="valid">Valid <?= $ejCounts['valid'] ?></button>
            </div>
            <input type="search" class="cx-search" id="ejSearch" placeholder="Search unit, tenant, lease…" aria-label="Search">
        </div>
    </div>
    <?php if (!$leaseEjari): ?>
        <div class="cx-empty"><i class="bi bi-inbox" style="color:var(--cx-muted)"></i>No active leases.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="cx-table" id="ejTable">
            <thead><tr>
                <th>Property</th>
                <th>Tenant / lease</th>
                <th class="cx-hide-sm">Lease period</th>
                <th>Ejari expiry</th>
                <th>Status</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($leaseEjari as $r):
                $tn = $tenantName($r);
                $prop = $r['building_name'] . ' – ' . $r['unit_number'];
                $search = strtolower($prop . ' ' . $tn . ' ' . $r['lease_number'] . ' ' . $r['ejari_registration_number']);
            ?>
                <tr data-band="<?= $r['band'] ?>" data-search="<?= h($search) ?>">
                    <td class="cx-strong"><?= h($prop) ?></td>
                    <td><?= h($tn ?: '—') ?><div class="cx-dim"><?= h($r['lease_number']) ?><?= $r['ejari_registration_number'] ? ' · Ejari ' . h($r['ejari_registration_number']) : '' ?></div></td>
                    <td class="cx-hide-sm cx-dim" style="white-space:nowrap;"><?= $r['start_date'] ? h(date('d M Y', strtotime($r['start_date']))) : '—' ?> – <?= $r['end_date'] ? h(date('d M Y', strtotime($r['end_date']))) : '—' ?></td>
                    <td><?= $r['expiry_date'] ? h(date('d M Y', strtotime($r['expiry_date']))) : '<span class="cx-dim">—</span>' ?></td>
                    <td><?php
                        if ($r['band'] === 'missing')      echo '<span class="cx-pill bg-grey">Not uploaded</span>';
                        elseif ($r['band'] === 'valid')    echo '<span class="cx-pill bg-green">Valid</span>';
                        else                               echo re_cx_status_pill((int)$r['days_left']);
                    ?></td>
                    <td class="num"><a class="cx-btn cx-btn-sm" href="lease_view.php?id=<?= (int)$r['lease_id'] ?>"><?= $r['band'] === 'missing' ? '<i class="bi bi-upload"></i> Upload' : 'Open <i class="bi bi-arrow-right"></i>' ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="cx-empty" id="ejNoMatch" hidden>No leases match this filter.</div>
    </div>
    <?php endif; ?>
</section>

<!-- Manual Ejari registration records -->
<section class="cx-card" aria-labelledby="cx-er-title">
    <div class="cx-card-head">
        <div><h2 class="cx-card-title" id="cx-er-title"><i class="bi bi-journal-text"></i> Ejari registration records</h2>
             <p class="cx-card-sub">Registrations entered manually with <strong>Add Ejari record</strong> (number, fee, status).</p></div>
        <div class="cx-chips" role="group" aria-label="Status">
            <?php foreach ($statusTabs as $k => $label):
                $n = $k === 'all' ? (int)$stats['total'] : (int)($stats[$k] ?? 0); ?>
                <a class="cx-chip <?= $statusFilter === $k ? 'is-active' : '' ?>" style="text-decoration:none"
                   href="?status=<?= h($k) ?>"><?= h($label) ?><?= isset($stats[$k]) || $k === 'all' ? ' ' . $n : '' ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if (empty($ejariRecords)): ?>
        <div class="cx-empty">
            <i class="bi bi-journal-plus" style="color:var(--cx-muted)"></i>
            <?= $statusFilter === 'all' ? 'No Ejari registration records yet.' : 'No records with this status.' ?>
            <?php if ($statusFilter === 'all'): ?>
                <div class="mt-3"><button type="button" class="cx-btn" data-bs-toggle="modal" data-bs-target="#ejariModal" onclick="resetForm()"><i class="bi bi-plus-lg"></i> Add the first record</button></div>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="cx-table">
            <thead><tr><th>Ejari number</th><th>Unit / tenant</th><th class="cx-hide-sm">Registered</th><th>Expiry</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ejariRecords as $ejari): [$pc, $pl] = $regPill[$ejari['registration_status']] ?? ['bg-grey', ucfirst((string)$ejari['registration_status'])]; ?>
                <tr>
                    <td class="cx-strong"><?= h($ejari['ejari_number']) ?><div class="cx-dim"><?= h($ejari['lease_number']) ?></div></td>
                    <td><?= h($ejari['building_name']) ?> – <?= h($ejari['unit_number']) ?><div class="cx-dim"><?= h(trim($ejari['first_name'] . ' ' . $ejari['last_name'])) ?></div></td>
                    <td class="cx-hide-sm"><?= $ejari['registration_date'] ? h(date('d M Y', strtotime($ejari['registration_date']))) : '—' ?></td>
                    <td><?= $ejari['expiry_date'] ? h(date('d M Y', strtotime($ejari['expiry_date']))) : '—' ?>
                        <?php if ($ejari['expiry_date'] && $ejari['days_until_expiry'] !== null && (int)$ejari['days_until_expiry'] <= 30): ?>
                            <div><?= re_cx_status_pill((int)$ejari['days_until_expiry']) ?></div><?php endif; ?></td>
                    <td><span class="cx-pill <?= $pc ?>"><?= h($pl) ?></span></td>
                    <td class="num"><a class="cx-btn cx-btn-sm" href="compliance_ejari_view.php?id=<?= (int)$ejari['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php re_cx_footer(); ?>

    <!-- Ejari Modal -->
    <div class="modal fade" id="ejariModal" tabindex="-1" aria-labelledby="ejariModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius:14px;border:0;">
                <div class="modal-header" style="border-bottom:1px solid #eaecf0;">
                    <h5 class="modal-title fw-bold" id="ejariModalTitle">Add Ejari record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="ejariForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create" id="formAction">
                    <input type="hidden" name="id" id="formId">
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="lease_id">Lease <span class="text-danger">*</span></label>
                                <select name="lease_id" id="lease_id" class="form-select" required>
                                    <option value="">Select an active lease…</option>
                                    <?php foreach ($leases as $lease): ?>
                                        <option value="<?= (int)$lease['id'] ?>">
                                            <?= h($lease['building_name'] . ' – ' . $lease['unit_number'] . ' · ' . trim($lease['first_name'] . ' ' . $lease['last_name']) . ' · ' . $lease['lease_number']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="ejari_number">Ejari number <span class="text-danger">*</span></label>
                                <input type="text" name="ejari_number" id="ejari_number" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="registration_status">Status <span class="text-danger">*</span></label>
                                <select name="registration_status" id="registration_status" class="form-select" required>
                                    <option value="pending">Pending</option>
                                    <option value="registered">Registered</option>
                                    <option value="expired">Expired</option>
                                    <option value="renewed">Renewed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="registration_date">Registration date</label>
                                <input type="date" name="registration_date" id="registration_date" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="expiry_date">Expiry date</label>
                                <input type="date" name="expiry_date" id="expiry_date" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="registration_fee">Fee (AED)</label>
                                <input type="number" step="0.01" min="0" name="registration_fee" id="registration_fee" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="notes">Notes</label>
                                <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top:1px solid #eaecf0;">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function resetForm() {
            document.getElementById('ejariForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '';
        }
        (function () {
            var table = document.getElementById('ejTable');
            if (!table) return;
            var rows = Array.prototype.slice.call(table.tBodies[0].rows);
            var chips = document.querySelectorAll('[data-ej-filter]');
            var search = document.getElementById('ejSearch');
            var none = document.getElementById('ejNoMatch');
            var band = 'all';
            function apply() {
                var q = (search.value || '').trim().toLowerCase(), shown = 0;
                rows.forEach(function (tr) {
                    var ok = (band === 'all' || tr.getAttribute('data-band') === band)
                          && (!q || tr.getAttribute('data-search').indexOf(q) !== -1);
                    tr.hidden = !ok; if (ok) shown++;
                });
                none.hidden = shown !== 0;
            }
            chips.forEach(function (c) {
                c.addEventListener('click', function () {
                    chips.forEach(function (x) { x.classList.remove('is-active'); });
                    c.classList.add('is-active'); band = c.getAttribute('data-ej-filter'); apply();
                });
            });
            search.addEventListener('input', apply);
        })();
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
