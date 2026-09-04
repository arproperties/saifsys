<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_unit_history_helper.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$unitId = (int)($_GET['id'] ?? 0);
if (!$unitId) { header('Location: units.php'); exit; }

try {
    ars_assert_unit_usable_for_ars($conn, $unitId, $arsCompanyId);
} catch (Throwable $e) {
    header('Location: units.php');
    exit;
}

$unit = arsGetUnitProfile($conn, $unitId);
if (!$unit) { header('Location: units.php'); exit; }

$success = trim($_GET['success'] ?? '');
$error = '';
$tablesReady = arsHistoryTablesReady($conn);
$currentUserId = arsCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if (!$tablesReady) {
            throw new RuntimeException(arsUnitHistoryMigrationMessage());
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'add_occupancy') {
            arsCreateOccupancy($conn, $arsCompanyId, $unitId, [
                'guest_id' => (int)($_POST['guest_id'] ?? 0),
                'tenant_first_name' => $_POST['tenant_first_name'] ?? '',
                'tenant_last_name' => $_POST['tenant_last_name'] ?? '',
                'tenant_phone' => $_POST['tenant_phone'] ?? '',
                'tenant_email' => $_POST['tenant_email'] ?? '',
                'tenant_id_type' => $_POST['tenant_id_type'] ?? '',
                'tenant_id_number' => $_POST['tenant_id_number'] ?? '',
                'tenant_nationality' => $_POST['tenant_nationality'] ?? '',
                'contract_number' => $_POST['contract_number'] ?? '',
                'contract_start' => $_POST['contract_start'] ?? null,
                'contract_end' => $_POST['contract_end'] ?? null,
                'move_in_date' => $_POST['move_in_date'] ?? date('Y-m-d'),
                'monthly_rent' => $_POST['monthly_rent'] ?? 0,
                'security_deposit' => $_POST['security_deposit'] ?? 0,
                'deposit_status' => $_POST['deposit_status'] ?? 'none',
                'notes' => $_POST['notes'] ?? '',
                'generate_ledger' => !empty($_POST['generate_ledger']),
                'ledger_months' => (int)($_POST['ledger_months'] ?? 12),
            ], $currentUserId);
            header('Location: unit_profile.php?id=' . $unitId . '&success=' . urlencode('Tenant added. Previous active tenant was preserved as old history.'));
            exit;
        }

        if ($action === 'move_out') {
            arsMoveOutOccupancy($conn, $arsCompanyId, (int)($_POST['occupancy_id'] ?? 0), [
                'move_out_date' => $_POST['move_out_date'] ?? date('Y-m-d'),
                'final_settlement_amount' => $_POST['final_settlement_amount'] ?? 0,
                'final_settlement_date' => $_POST['final_settlement_date'] ?? null,
                'final_settlement_notes' => $_POST['final_settlement_notes'] ?? '',
            ]);
            header('Location: unit_profile.php?id=' . $unitId . '&success=' . urlencode('Tenant moved out and kept in old tenant history.'));
            exit;
        }

        if ($action === 'generate_ledger') {
            $occupancyId = (int)($_POST['occupancy_id'] ?? 0);
            $startDate = $_POST['ledger_start'] ?: date('Y-m-d');
            $endDate = $_POST['ledger_end'] ?: null;
            $months = (int)($_POST['ledger_months'] ?? 12);
            $count = arsGenerateMonthlyLedgerRows($conn, $arsCompanyId, $occupancyId, $unitId, $startDate, $endDate, $months);
            header('Location: unit_profile.php?id=' . $unitId . '&success=' . urlencode($count . ' monthly ledger row(s) generated or refreshed.'));
            exit;
        }

        if ($action === 'record_payment') {
            arsRecordOccupancyPayment($conn, $arsCompanyId, (int)($_POST['occupancy_id'] ?? 0), [
                'ledger_id' => (int)($_POST['ledger_id'] ?? 0),
                'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
                'amount' => $_POST['amount'] ?? 0,
                'payment_method' => $_POST['payment_method'] ?? 'cash',
                'reference_number' => $_POST['reference_number'] ?? '',
                'notes' => $_POST['notes'] ?? '',
            ], $currentUserId);
            header('Location: unit_profile.php?id=' . $unitId . '&success=' . urlencode('Tenant payment recorded.'));
            exit;
        }

        if ($action === 'update_ledger') {
            arsUpdateLedgerCharges($conn, $arsCompanyId, (int)($_POST['ledger_id'] ?? 0), [
                'rent_amount' => $_POST['rent_amount'] ?? 0,
                'deposit_amount' => $_POST['deposit_amount'] ?? 0,
                'maintenance_charges' => $_POST['maintenance_charges'] ?? 0,
                'utility_charges' => $_POST['utility_charges'] ?? 0,
                'other_charges' => $_POST['other_charges'] ?? 0,
                'notes' => $_POST['ledger_notes'] ?? '',
            ]);
            header('Location: unit_profile.php?id=' . $unitId . '&success=' . urlencode('Monthly ledger updated.'));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$occupancies = arsGetOccupanciesForUnit($conn, $arsCompanyId, $unitId);
$currentOccupancy = null;
$oldOccupancies = [];
foreach ($occupancies as $occ) {
    if (($occ['status'] ?? '') === 'active' && !$currentOccupancy) $currentOccupancy = $occ;
    else $oldOccupancies[] = $occ;
}

$selectedOccupancyId = (int)($_GET['occupancy_id'] ?? ($currentOccupancy['id'] ?? ($occupancies[0]['id'] ?? 0)));
$selectedOccupancy = null;
foreach ($occupancies as $occ) {
    if ((int)$occ['id'] === $selectedOccupancyId) { $selectedOccupancy = $occ; break; }
}
$ledgerRows = $selectedOccupancy ? arsGetOccupancyLedger($conn, $arsCompanyId, (int)$selectedOccupancy['id']) : [];
$manualPayments = $selectedOccupancy ? arsGetOccupancyPayments($conn, $arsCompanyId, (int)$selectedOccupancy['id']) : [];
$bookings = arsGetUnitBookingHistory($conn, $arsCompanyId, $unitId);
$maintenanceRows = arsGetUnitMaintenanceHistory($conn, $unitId);
$timeline = arsBuildUnitTimeline($occupancies, $bookings);
$guests = arsGetGuestOptions($conn, $arsCompanyId);

$bookingRevenue = array_sum(array_map(fn($b) => (float)($b['total_amount'] ?? 0), $bookings));
$bookingPaid = array_sum(array_map(fn($b) => (float)($b['payment_total'] ?? 0), $bookings));
$ledgerBalance = array_sum(array_map(fn($o) => (float)($o['ledger_balance'] ?? 0), $occupancies));

$pageTitle = 'Flat Profile — ' . ($unit['unit_number'] ?? '');
$shortText = function ($value, int $limit = 120): string {
    $value = (string)$value;
    return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
};
ars_shell_begin([
    'title' => 'Flat ' . ($unit['unit_number'] ?? '') . ' Profile',
    'subtitle' => trim(($unit['building_name'] ?? '') . ' · ' . ($unit['unit_type'] ?? '') . ' · ' . str_replace('_', ' ', $unit['rental_mode'] ?? ''), ' ·'),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Units', 'href' => 'units.php'],
        ['label' => $unit['unit_number'] ?? 'Profile'],
    ],
    'actions_html' => '<div class="d-flex gap-2 flex-wrap">'
        . ars_ui_button('History search', ['href' => 'unit_history_search.php', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'search'])
        . ars_ui_button('Edit unit', ['href' => 'unit_edit.php?id=' . (int)$unitId, 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'pencil'])
        . ars_ui_button('Units', ['href' => 'units.php', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'arrow-left'])
        . '</div>',
    'legacy_bootstrap' => true,
]);
?>


<?php if (!$tablesReady): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= h(arsUnitHistoryMigrationMessage()) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Current tenant', $currentOccupancy ? '1' : '0', ['icon' => 'user-check']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Old tenants', (string)count($oldOccupancies), ['icon' => 'users']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Booking stays', (string)count($bookings), ['icon' => 'calendar-check']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Manual ledger balance', formatArsAmount($ledgerBalance), ['tone' => 'ok', 'icon' => 'wallet']) ?></div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="ars-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-person-check-fill me-2"></i>Current Tenant</span>
                <?php if ($tablesReady): ?><button class="btn btn-ars btn-sm" data-bs-toggle="modal" data-bs-target="#addTenantModal"><i class="bi bi-person-plus me-1"></i>Add / Switch Tenant</button><?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$currentOccupancy): ?>
                    <p class="text-muted mb-0">No active manual tenant. Existing bookings still appear in the history below.</p>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <h5 class="mb-1"><?= h(trim($currentOccupancy['tenant_first_name'] . ' ' . ($currentOccupancy['tenant_last_name'] ?? ''))) ?></h5>
                            <span class="badge bg-success">Active Current Record</span>
                            <div class="small text-muted mt-2"><?= h($currentOccupancy['tenant_phone'] ?: 'No phone') ?><?= $currentOccupancy['tenant_id_number'] ? ' · ID ' . h($currentOccupancy['tenant_id_number']) : '' ?></div>
                        </div>
                        <div class="col-md-7">
                            <div class="row g-2 small">
                                <div class="col-6"><strong class="text-muted d-block">Contract</strong><?= h($currentOccupancy['contract_number'] ?: '—') ?></div>
                                <div class="col-6"><strong class="text-muted d-block">Move-in</strong><?= h($currentOccupancy['move_in_date']) ?></div>
                                <div class="col-6"><strong class="text-muted d-block">Monthly Rent</strong><?= formatArsAmount($currentOccupancy['monthly_rent']) ?></div>
                                <div class="col-6"><strong class="text-muted d-block">Deposit</strong><?= formatArsAmount($currentOccupancy['security_deposit']) ?></div>
                                <div class="col-6"><strong class="text-muted d-block">Ledger Paid</strong><?= formatArsAmount($currentOccupancy['ledger_paid']) ?></div>
                                <div class="col-6"><strong class="text-muted d-block">Outstanding</strong><?= formatArsAmount($currentOccupancy['ledger_balance']) ?></div>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <form method="POST" class="row g-2 align-items-end">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="move_out">
                        <input type="hidden" name="occupancy_id" value="<?= (int)$currentOccupancy['id'] ?>">
                        <div class="col-md-3"><label class="form-label small fw-semibold">Move-out Date</label><input type="date" name="move_out_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-md-3"><label class="form-label small fw-semibold">Settlement Amount</label><input type="number" step="0.01" min="0" name="final_settlement_amount" class="form-control form-control-sm" value="0"></div>
                        <div class="col-md-4"><label class="form-label small fw-semibold">Settlement Notes</label><input type="text" name="final_settlement_notes" class="form-control form-control-sm" placeholder="Final settlement / handover note"></div>
                        <div class="col-md-2"><button class="btn btn-outline-danger btn-sm w-100" onclick="return confirm('Move out this tenant and keep the record as old history?')">Move Out</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="ars-card mb-4">
            <div class="card-header"><i class="bi bi-archive-fill me-2"></i>Old Tenants</div>
            <div class="table-responsive">
                <table class="table ars-table ars-mobile-cards mb-0">
                    <thead><tr><th>Tenant</th><th>Contract</th><th>Move-in</th><th>Move-out</th><th>Final Settlement</th><th>Balance</th><th></th></tr></thead>
                    <tbody>
                    <?php if (empty($oldOccupancies)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">No old manual tenants yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($oldOccupancies as $occ): ?>
                        <tr>
                            <td data-label="Tenant"><?= h(trim($occ['tenant_first_name'] . ' ' . ($occ['tenant_last_name'] ?? ''))) ?><br><span class="badge bg-secondary">Inactive / Old Tenant</span></td>
                            <td data-label="Contract"><?= h($occ['contract_number'] ?: '—') ?></td>
                            <td data-label="Move-in"><?= h($occ['move_in_date']) ?></td>
                            <td data-label="Move-out"><?= h($occ['move_out_date'] ?: '—') ?></td>
                            <td data-label="Final Settlement"><?= formatArsAmount($occ['final_settlement_amount']) ?></td>
                            <td data-label="Balance"><?= formatArsAmount($occ['ledger_balance']) ?></td>
                            <td data-label=""><a href="unit_profile.php?id=<?= $unitId ?>&occupancy_id=<?= (int)$occ['id'] ?>" class="btn btn-ars-outline btn-sm">Ledger</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ars-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-calendar2-week me-2"></i>Monthly Tenant Ledger<?= $selectedOccupancy ? ' — ' . h(trim($selectedOccupancy['tenant_first_name'] . ' ' . ($selectedOccupancy['tenant_last_name'] ?? ''))) : '' ?></span>
                <?php if ($selectedOccupancy): ?><button class="btn btn-ars-outline btn-sm" data-bs-toggle="modal" data-bs-target="#generateLedgerModal"><i class="bi bi-calendar-plus me-1"></i>Generate Months</button><?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table ars-table ars-mobile-cards mb-0">
                    <thead><tr><th>Month</th><th>Rent</th><th>Deposit</th><th>Maintenance</th><th>Utilities</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$selectedOccupancy || empty($ledgerRows)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">No monthly ledger rows yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($ledgerRows as $row): ?>
                        <tr>
                            <td data-label="Month"><?= h(date('M Y', strtotime($row['ledger_month']))) ?></td>
                            <td data-label="Rent"><?= formatArsAmount($row['rent_amount']) ?></td>
                            <td data-label="Deposit"><?= formatArsAmount($row['deposit_amount']) ?></td>
                            <td data-label="Maintenance"><?= formatArsAmount($row['maintenance_charges']) ?></td>
                            <td data-label="Utilities"><?= formatArsAmount($row['utility_charges']) ?></td>
                            <td data-label="Total"><?= formatArsAmount($row['total_charges']) ?></td>
                            <td data-label="Paid"><?= formatArsAmount($row['paid_amount']) ?></td>
                            <td data-label="Balance" class="fw-semibold"><?= formatArsAmount($row['pending_balance']) ?></td>
                            <td data-label="Status"><span class="badge bg-<?= $row['status'] === 'paid' ? 'success' : ($row['status'] === 'partial' ? 'warning text-dark' : 'secondary') ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                            <td data-label=""><button class="btn btn-ars-outline btn-sm" data-bs-toggle="modal" data-bs-target="#ledgerModal<?= (int)$row['id'] ?>">Update</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="ars-card mb-4">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>Tenant Timeline View</div>
            <div class="card-body">
                <?php if (empty($timeline)): ?>
                    <p class="text-muted mb-0">No occupancy or booking history yet.</p>
                <?php endif; ?>
                <?php foreach ($timeline as $item): ?>
                    <div class="border-start ps-3 pb-3 position-relative">
                        <span class="badge <?= $item['type'] === 'occupancy' ? 'bg-primary' : 'bg-info text-dark' ?> mb-1"><?= h(ucfirst($item['type'])) ?></span>
                        <div class="fw-semibold"><?= h($item['title'] ?: 'Unknown') ?></div>
                        <div class="small text-muted"><?= h($item['date'] ?: '—') ?><?= $item['end_date'] ? ' → ' . h($item['end_date']) : '' ?> · <?= h($item['subtitle']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ars-card mb-4">
            <div class="card-header"><i class="bi bi-cash-coin me-2"></i>Manual Payments</div>
            <div class="card-body">
                <?php if ($selectedOccupancy): ?>
                <form method="POST" class="row g-2 mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="occupancy_id" value="<?= (int)$selectedOccupancy['id'] ?>">
                    <div class="col-12">
                        <select name="ledger_id" class="form-select form-select-sm">
                            <option value="">Payment not linked to a month</option>
                            <?php foreach ($ledgerRows as $row): ?>
                            <option value="<?= (int)$row['id'] ?>"><?= h(date('M Y', strtotime($row['ledger_month']))) ?> — Balance <?= formatArsAmount($row['pending_balance']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-6"><input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required></div>
                    <div class="col-6">
                        <select name="payment_method" class="form-select form-select-sm">
                            <?php foreach (['cash','bank_transfer','card','online','cheque','other'] as $method): ?>
                            <option value="<?= $method ?>"><?= h(ucwords(str_replace('_', ' ', $method))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><input type="text" name="reference_number" class="form-control form-control-sm" placeholder="Reference"></div>
                    <div class="col-12"><input type="text" name="notes" class="form-control form-control-sm" placeholder="Payment notes"></div>
                    <div class="col-12"><button class="btn btn-ars btn-sm w-100">Record Payment</button></div>
                </form>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Date</th><th>Month</th><th>Amount</th><th>Method</th></tr></thead>
                        <tbody>
                        <?php if (empty($manualPayments)): ?><tr><td colspan="4" class="text-muted text-center">No manual payments.</td></tr><?php endif; ?>
                        <?php foreach ($manualPayments as $pay): ?>
                        <tr><td><?= h($pay['payment_date']) ?></td><td><?= $pay['ledger_month'] ? h(date('M Y', strtotime($pay['ledger_month']))) : '—' ?></td><td><?= formatArsAmount($pay['amount']) ?></td><td><?= h(str_replace('_', ' ', $pay['payment_method'])) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="ars-card mb-4">
            <div class="card-header"><i class="bi bi-tools me-2"></i>Maintenance / Utility Context</div>
            <div class="card-body">
                <?php if (empty($maintenanceRows)): ?><p class="text-muted mb-0">No maintenance requests for this flat.</p><?php endif; ?>
                <?php foreach ($maintenanceRows as $m): ?>
                    <div class="border-bottom pb-2 mb-2">
                        <div class="d-flex justify-content-between"><strong><?= h($m['category'] ?: 'Maintenance') ?></strong><span class="badge bg-secondary"><?= h($m['status']) ?></span></div>
                        <div class="small text-muted"><?= h($m['request_date']) ?> · <?= formatArsAmount($m['cost']) ?></div>
                        <div class="small"><?= h($shortText($m['description'] ?? '', 120)) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-bookmark-fill me-2"></i>Existing Booking / Guest History</span>
        <span class="small text-muted">Revenue <?= formatArsAmount($bookingRevenue) ?> · Paid <?= formatArsAmount($bookingPaid) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead><tr><th>Booking</th><th>Guest</th><th>Dates</th><th>Charges</th><th>Paid</th><th>Balance</th><th>Deposit</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (empty($bookings)): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No bookings for this flat yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($bookings as $b): ?>
                <tr>
                    <td data-label="Booking"><a href="booking_view.php?id=<?= (int)$b['id'] ?>" class="fw-semibold text-decoration-none"><?= h($b['booking_number']) ?></a></td>
                    <td data-label="Guest"><?= h(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?><br><small class="text-muted"><?= h($b['guest_phone'] ?? '') ?></small></td>
                    <td data-label="Dates"><?= h($b['check_in']) ?> → <?= h($b['check_out']) ?><br><small class="text-muted"><?= (int)$b['nights'] ?> night(s)</small></td>
                    <td data-label="Charges"><?= formatArsAmount($b['charge_total'] ?: $b['total_amount']) ?><br><small class="text-muted"><?= (int)$b['charge_count'] ?> charge row(s)</small></td>
                    <td data-label="Paid"><?= formatArsAmount($b['payment_total']) ?><br><small class="text-muted"><?= (int)$b['payment_count'] ?> payment(s)</small></td>
                    <td data-label="Balance"><?= formatArsAmount($b['balance_due']) ?></td>
                    <td data-label="Deposit"><?= formatArsAmount($b['deposit_amount'] ?? 0) ?><br><small class="text-muted"><?= h($b['deposit_status'] ?? 'none') ?></small></td>
                    <td data-label="Status"><?= arsBookingStatusBadge($b['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($tablesReady): ?>
<div class="modal fade" id="addTenantModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" class="modal-content">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add_occupancy">
            <div class="modal-header"><h5 class="modal-title">Add / Switch Current Tenant</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-info small">Adding a new active tenant automatically moves the existing active tenant to inactive history. Old ledger and payments remain saved.</div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Use Existing Guest (optional)</label>
                        <select name="guest_id" class="form-select">
                            <option value="">Create / enter tenant manually</option>
                            <?php foreach ($guests as $guest): ?>
                            <option value="<?= (int)$guest['id'] ?>"><?= h(trim($guest['first_name'] . ' ' . $guest['last_name'])) ?><?= $guest['phone'] ? ' — ' . h($guest['phone']) : '' ?><?= $guest['id_number'] ? ' — ID ' . h($guest['id_number']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">First Name *</label><input type="text" name="tenant_first_name" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">Last Name</label><input type="text" name="tenant_last_name" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="tenant_phone" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="tenant_email" class="form-control"></div>
                    <div class="col-md-4">
                        <label class="form-label">ID Type</label>
                        <select name="tenant_id_type" class="form-select">
                            <option value="">—</option>
                            <?php foreach (['emirates_id'=>'Emirates ID','passport'=>'Passport','visa'=>'Visa','driving_license'=>'Driving License','other'=>'Other'] as $v=>$l): ?>
                            <option value="<?= $v ?>"><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">ID / Passport Number</label><input type="text" name="tenant_id_number" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Nationality</label><input type="text" name="tenant_nationality" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Contract Number</label><input type="text" name="contract_number" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Contract Start</label><input type="date" name="contract_start" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Contract End</label><input type="date" name="contract_end" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Move-in Date *</label><input type="date" name="move_in_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Monthly Rent</label><input type="number" step="0.01" min="0" name="monthly_rent" class="form-control" value="0"></div>
                    <div class="col-md-4"><label class="form-label">Security Deposit</label><input type="number" step="0.01" min="0" name="security_deposit" class="form-control" value="0"></div>
                    <div class="col-md-4">
                        <label class="form-label">Deposit Status</label>
                        <select name="deposit_status" class="form-select">
                            <?php foreach (['none','pending','received','partially_refunded','refunded','forfeited'] as $status): ?>
                            <option value="<?= $status ?>"><?= h(ucwords(str_replace('_', ' ', $status))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Ledger Months</label><input type="number" min="1" max="120" name="ledger_months" class="form-control" value="12"></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="generate_ledger" id="generateLedger" checked><label class="form-check-label" for="generateLedger">Generate monthly ledger</label></div></div>
                    <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-ars">Save Tenant</button></div>
        </form>
    </div>
</div>

<?php if ($selectedOccupancy): ?>
<div class="modal fade" id="generateLedgerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="generate_ledger">
            <input type="hidden" name="occupancy_id" value="<?= (int)$selectedOccupancy['id'] ?>">
            <div class="modal-header"><h5 class="modal-title">Generate Monthly Ledger</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-6"><label class="form-label">Start</label><input type="date" name="ledger_start" class="form-control" value="<?= h($selectedOccupancy['contract_start'] ?: $selectedOccupancy['move_in_date']) ?>"></div>
                <div class="col-md-6"><label class="form-label">End (optional)</label><input type="date" name="ledger_end" class="form-control" value="<?= h($selectedOccupancy['contract_end'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Months if no end date</label><input type="number" min="1" max="120" name="ledger_months" class="form-control" value="12"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-ars">Generate</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php foreach ($ledgerRows as $row): ?>
<div class="modal fade" id="ledgerModal<?= (int)$row['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_ledger">
            <input type="hidden" name="ledger_id" value="<?= (int)$row['id'] ?>">
            <div class="modal-header"><h5 class="modal-title">Update <?= h(date('M Y', strtotime($row['ledger_month']))) ?> Ledger</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-6"><label class="form-label">Rent</label><input type="number" step="0.01" min="0" name="rent_amount" class="form-control" value="<?= h($row['rent_amount']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Deposit</label><input type="number" step="0.01" min="0" name="deposit_amount" class="form-control" value="<?= h($row['deposit_amount']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Maintenance Charges</label><input type="number" step="0.01" min="0" name="maintenance_charges" class="form-control" value="<?= h($row['maintenance_charges']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Utility Bills</label><input type="number" step="0.01" min="0" name="utility_charges" class="form-control" value="<?= h($row['utility_charges']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Other Charges</label><input type="number" step="0.01" min="0" name="other_charges" class="form-control" value="<?= h($row['other_charges']) ?>"></div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="ledger_notes" class="form-control" rows="2"><?= h($row['notes'] ?? '') ?></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-ars">Update Ledger</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php ars_shell_end(); ?>
