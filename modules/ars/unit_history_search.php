<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_unit_history_helper.php';
require_once __DIR__ . '/includes/ars_shell.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$query = trim($_GET['q'] ?? '');
$results = $query !== '' ? arsSearchUnitHistory($conn, $arsCompanyId, $query) : [];
$tablesReady = arsHistoryTablesReady($conn);

$pageTitle = 'Tenant History Search';
ars_shell_begin([
    'title' => 'Flat / Tenant History Search',
    'subtitle' => 'Company scope: ARS #' . $arsCompanyId . ' · Search occupancy and booking history',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Properties & Units', 'href' => 'units.php'],
        ['label' => 'History Search'],
    ],
    'actions_html' => '<a href="units.php" class="btn btn-ars-outline btn-sm"><i class="bi bi-arrow-left me-1"></i>Units</a>',
    'legacy_bootstrap' => true,
]);
?>

<?php if (!$tablesReady): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= h(arsUnitHistoryMigrationMessage()) ?> Existing booking history search is still available.</div>
<?php endif; ?>

<div class="ars-card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-9">
                <label class="form-label fw-semibold">Search by flat number, tenant name, phone, Emirates ID/passport, contract number, or booking number</label>
                <input type="text" name="q" class="form-control" value="<?= h($query) ?>" placeholder="Example: 101, Mr. XYZ, 050..., EID/passport, contract no.">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-ars flex-fill"><i class="bi bi-search me-1"></i>Search</button>
                <a href="unit_history_search.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="ars-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2"></i>Results</span>
        <span class="text-muted small"><?= count($results) ?> match<?= count($results) === 1 ? '' : 'es' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead><tr><th>Flat</th><th>Matched Tenant / Guest</th><th>Phone</th><th>ID / Passport</th><th>Reference</th><th>Source</th><th></th></tr></thead>
            <tbody>
            <?php if ($query === ''): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Enter a search term to find flat tenant history.</td></tr>
            <?php elseif (empty($results)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No matching tenant history found.</td></tr>
            <?php endif; ?>
            <?php foreach ($results as $row): ?>
                <tr>
                    <td data-label="Flat"><strong><?= h($row['unit_number'] ?? '') ?></strong><br><small class="text-muted"><?= h($row['building_name'] ?? '') ?></small></td>
                    <td data-label="Matched Tenant / Guest"><?= h(trim($row['match_name'] ?? '') ?: '—') ?></td>
                    <td data-label="Phone"><?= h($row['match_phone'] ?: '—') ?></td>
                    <td data-label="ID / Passport"><?= h($row['match_id_number'] ?: '—') ?></td>
                    <td data-label="Reference"><?= h($row['match_reference'] ?: '—') ?></td>
                    <td data-label="Source"><span class="badge <?= ($row['source_type'] ?? '') === 'occupancy' ? 'bg-primary' : 'bg-info text-dark' ?>"><?= h(ucfirst($row['source_type'] ?? '')) ?></span></td>
                    <td data-label=""><a href="unit_profile.php?id=<?= (int)$row['unit_id'] ?>" class="btn btn-ars-outline btn-sm text-decoration-none">Open Profile</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php ars_shell_end(); ?>
