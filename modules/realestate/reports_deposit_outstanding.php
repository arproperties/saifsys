<?php
/**
 * Real Estate Module - Security Deposit Outstanding Report
 *
 * Tenancies whose security deposit (per the lease) has not been fully received.
 * A renewal's deposit is held on the first lease of the tenancy, so each
 * tenancy is listed once, under its current lease. Move-ins whose payment step
 * was confirmed manually (keys released before the deposit was recorded) are
 * flagged, as those are the ones to chase first.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';
require_once __DIR__ . '/includes/security_deposit_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$scope = ($_GET['scope'] ?? 'current') === 'all' ? 'all' : 'current';
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$onlyOverride = !empty($_GET['override']);
$exportFormat = $_GET['export'] ?? '';

// Current tenancies: active leases. "All" adds ended ones (expired / terminated)
// that were not renewed and whose deposit settlement is not closed.
$statuses = $scope === 'all' ? ['active', 'has_legal_case', 'expired', 'terminated'] : ['active', 'has_legal_case'];
$in = implode(',', array_fill(0, count($statuses), '?'));
$params = array_merge([$currentCompanyId], $statuses);
$buildingSql = '';
if ($filterBuilding) {
    $buildingSql = ' AND b.id = ?';
    $params[] = $filterBuilding;
}
$st = $conn->prepare("
    SELECT l.id, l.lease_number, l.status, l.start_date, l.end_date, l.tenant_id, l.unit_id,
           u.unit_number, b.id AS building_id, b.name AS building_name,
           t.tenant_type, t.first_name, t.last_name, t.company_name, t.phone, t.email
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ? AND l.status IN ($in) AND " . re_lease_not_deleted_sql($conn, 'l') . $buildingSql . "
    ORDER BY l.start_date DESC, l.id DESC
");
$st->execute($params);
$leases = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Move-ins confirmed manually at the Payment step, by lease
$overrides = [];
try {
    $ov = $conn->prepare("
        SELECT lease_id, id, payment_override_at, payment_override_reason
        FROM re_move_ins
        WHERE company_id = ? AND payment_override = 1 AND status <> 'cancelled'
    ");
    $ov->execute([$currentCompanyId]);
    foreach ($ov->fetchAll(PDO::FETCH_ASSOC) ?: [] as $o) {
        $overrides[(int)$o['lease_id']] = $o;
    }
} catch (Throwable $e) {
}

// Closed settlements (no deposit received, tenant gone): not chased any more
$closed = [];
try {
    $cs = $conn->prepare("SELECT DISTINCT lease_id FROM re_security_deposit_settlements WHERE company_id = ? AND status NOT IN ('draft', 'approved')");
    $cs->execute([$currentCompanyId]);
    $closed = array_flip(array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN) ?: []));
} catch (Throwable $e) {
}

$rows = [];
$seen = [];
// Active leases first, so a tenancy is listed under its current lease
usort($leases, static fn($a, $b) => (int)!in_array($a['status'], ['active', 'has_legal_case'], true) <=> (int)!in_array($b['status'], ['active', 'has_legal_case'], true));
foreach ($leases as $l) {
    $depLeaseId = re_sd_deposit_lease_id($conn, (int)$currentCompanyId, (int)$l['id']);
    if (isset($seen[$depLeaseId])) {
        continue;
    }
    $seen[$depLeaseId] = true;
    $isCurrent = in_array($l['status'], ['active', 'has_legal_case'], true);
    if (!$isCurrent && isset($closed[$depLeaseId])) {
        continue;
    }
    $sum = re_sd_summary($conn, (int)$currentCompanyId, $depLeaseId);
    $expected = (float)$sum['expected'];
    $received = (float)$sum['allocated'];
    $outstanding = round($expected - $received, 2);
    if ($expected <= 0 || $outstanding <= 0.005) {
        continue;
    }
    $override = $overrides[(int)$l['id']] ?? $overrides[$depLeaseId] ?? null;
    if ($onlyOverride && !$override) {
        continue;
    }
    $tenant = ($l['tenant_type'] ?? '') === 'company' && !empty($l['company_name'])
        ? $l['company_name'] : trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
    $rows[] = [
        'lease_id' => (int)$l['id'],
        'lease_number' => $l['lease_number'],
        'lease_status' => $l['status'],
        'deposit_lease_id' => $depLeaseId,
        'deposit_lease_number' => $depLeaseId !== (int)$l['id'] ? ($sum['lease']['lease_number'] ?? ('#' . $depLeaseId)) : null,
        'start_date' => $l['start_date'],
        'building' => $l['building_name'],
        'unit' => $l['unit_number'],
        'tenant' => $tenant !== '' ? $tenant : 'Tenant',
        'phone' => $l['phone'] ?? '',
        'expected' => $expected,
        'received' => $received,
        'outstanding' => $outstanding,
        'in_hand' => (float)$sum['pending_collections'],
        'override' => $override,
        'days' => max(0, (int)floor((time() - strtotime((string)$l['start_date'])) / 86400)),
    ];
}
// Keys released without the deposit first, then the longest outstanding
usort($rows, static fn($a, $b) => [(int)!$a['override'], -$a['days']] <=> [(int)!$b['override'], -$b['days']]);

$totals = ['count' => count($rows), 'outstanding' => 0.0, 'override' => 0, 'in_hand' => 0.0];
foreach ($rows as $r) {
    $totals['outstanding'] += $r['outstanding'];
    $totals['in_hand'] += min($r['in_hand'], $r['outstanding']);
    $totals['override'] += $r['override'] ? 1 : 0;
}

if ($exportFormat === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="security_deposit_outstanding_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Building', 'Unit', 'Tenant', 'Phone', 'Lease', 'Lease status', 'Deposit held on lease', 'Lease start', 'Days', 'Deposit per lease', 'Received', 'Outstanding', 'Cheques in hand', 'Payment confirmed manually at move-in', 'Override reason']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['building'], $r['unit'], $r['tenant'], $r['phone'], $r['lease_number'], $r['lease_status'], $r['deposit_lease_number'] ?? '',
            $r['start_date'], $r['days'], number_format($r['expected'], 2, '.', ''), number_format($r['received'], 2, '.', ''),
            number_format($r['outstanding'], 2, '.', ''), number_format($r['in_hand'], 2, '.', ''),
            $r['override'] ? date('Y-m-d', strtotime((string)$r['override']['payment_override_at'])) : '', $r['override']['payment_override_reason'] ?? '']);
    }
    fclose($out);
    exit;
}

$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
$qs = static fn(array $over = []) => '?' . http_build_query(array_filter(array_merge(
    ['scope' => $scope, 'building_id' => $filterBuilding ?: '', 'override' => $onlyOverride ? 1 : ''], $over
), fn($v) => $v !== '' && $v !== null));

$pageTitle = 'Security Deposit Outstanding';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles = ($pageStyles ?? '') . re_move_ui_css();
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="rm-page">
        <div class="rm-hero">
            <div>
                <h1><i class="bi bi-shield-exclamation"></i>Security Deposit Outstanding</h1>
                <div class="rm-sub">Tenancies whose security deposit has not been fully received. Move-ins confirmed manually at the Payment step are listed first.</div>
            </div>
            <div class="rm-actions">
                <a href="<?= h($qs(['export' => 'csv'])) ?>" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i> CSV</a>
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
            </div>
        </div>

        <div class="rm-stats">
            <a href="<?= h($qs(['override' => ''])) ?>" class="rm-stat rm-tone-primary <?= !$onlyOverride ? 'is-active' : '' ?>">
                <div class="lbl">Tenancies</div>
                <div class="val"><?= (int)$totals['count'] ?></div>
            </a>
            <div class="rm-stat rm-tone-danger">
                <div class="lbl">Outstanding (AED)</div>
                <div class="val"><?= number_format($totals['outstanding'], 2) ?></div>
            </div>
            <a href="<?= h($qs(['override' => 1])) ?>" class="rm-stat rm-tone-warning <?= $onlyOverride ? 'is-active' : '' ?>" title="Payment step confirmed manually at move-in">
                <div class="lbl">Keys released without deposit</div>
                <div class="val"><?= (int)$totals['override'] ?></div>
            </a>
            <div class="rm-stat rm-tone-info" title="Security deposit cheques received but not yet cleared / allocated">
                <div class="lbl">Cheques in hand (AED)</div>
                <div class="val"><?= number_format($totals['in_hand'], 2) ?></div>
            </div>
        </div>

        <form method="GET" class="rm-filter">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-sm-6 col-lg-4">
                    <label class="form-label">Tenancies</label>
                    <select name="scope" class="form-select">
                        <option value="current" <?= $scope === 'current' ? 'selected' : '' ?>>Current (active leases)</option>
                        <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>Current and ended, not settled</option>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-4">
                    <label class="form-label">Building</label>
                    <select name="building_id" class="form-select">
                        <option value="">All buildings</option>
                        <?php foreach ($buildings as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $filterBuilding === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-4 d-flex gap-2">
                    <?php if ($onlyOverride): ?><input type="hidden" name="override" value="1"><?php endif; ?>
                    <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1"></i> Filter</button>
                    <a href="reports_deposit_outstanding.php" class="btn btn-outline-secondary" title="Clear filters"><i class="bi bi-x-lg"></i></a>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-list-ul me-1"></i> Outstanding Deposits <span class="rm-pill rm-pill-secondary ms-1"><?= count($rows) ?></span></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover rm-table-stack">
                        <thead>
                            <tr>
                                <th>Lease / Unit</th>
                                <th>Tenant</th>
                                <th>Since</th>
                                <th class="text-end">Deposit</th>
                                <th class="text-end">Received</th>
                                <th class="text-end">Outstanding</th>
                                <th>Notes</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="8" class="rm-empty"><i class="bi bi-check2-circle"></i>No outstanding security deposits</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td data-label="Lease / Unit">
                                        <div>
                                            <div class="rm-cell-main"><?= h($r['lease_number']) ?></div>
                                            <div class="rm-cell-sub"><?= h($r['building']) ?> · <?= h($r['unit']) ?><?= $r['lease_status'] !== 'active' ? ' · ' . h(ucfirst(str_replace('_', ' ', $r['lease_status']))) : '' ?></div>
                                        </div>
                                    </td>
                                    <td data-label="Tenant">
                                        <div>
                                            <div class="rm-cell-main"><?= h($r['tenant']) ?></div>
                                            <div class="rm-cell-sub"><?= h($r['phone']) ?></div>
                                        </div>
                                    </td>
                                    <td data-label="Since"><span class="rm-cell-main"><?= h(date('M d, Y', strtotime((string)$r['start_date']))) ?></span><div class="rm-cell-sub"><?= (int)$r['days'] ?> days</div></td>
                                    <td data-label="Deposit" class="text-end text-nowrap"><?= number_format($r['expected'], 2) ?></td>
                                    <td data-label="Received" class="text-end text-nowrap"><?= number_format($r['received'], 2) ?></td>
                                    <td data-label="Outstanding" class="text-end text-nowrap"><strong class="text-danger"><?= number_format($r['outstanding'], 2) ?></strong></td>
                                    <td data-label="Notes">
                                        <div class="d-flex flex-column gap-1 align-items-end align-items-md-start">
                                            <?php if ($r['override']): ?>
                                                <span class="rm-pill rm-pill-warning" title="<?= h('Reason: ' . ($r['override']['payment_override_reason'] ?? '')) ?>"><i class="bi bi-key"></i> Keys released without deposit</span>
                                            <?php endif; ?>
                                            <?php if ($r['in_hand'] > 0.005): ?>
                                                <span class="rm-pill rm-pill-info">Cheque in hand <?= number_format($r['in_hand'], 2) ?></span>
                                            <?php endif; ?>
                                            <?php if ($r['deposit_lease_number']): ?>
                                                <span class="rm-cell-sub">Held on first lease <?= h($r['deposit_lease_number']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end rm-td-actions text-nowrap">
                                        <?php if ($r['override']): ?>
                                            <a href="move_in_view.php?id=<?= (int)$r['override']['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Move-in"><i class="bi bi-box-arrow-in-right"></i></a>
                                        <?php endif; ?>
                                        <a href="lease_view.php?id=<?= (int)$r['deposit_lease_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i> Lease</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($rows): ?>
                            <tfoot><tr><th colspan="5" class="text-end">Total outstanding</th><th class="text-end text-nowrap"><?= number_format($totals['outstanding'], 2) ?> AED</th><th colspan="2"></th></tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
