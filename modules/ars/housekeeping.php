<?php
/**
 * ARS Home Rentals — Housekeeping Dashboard
 * Checkout creates a cleaning work order; this page is the operator board to Start → Complete it.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_early_checkout.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$settings = getArsSettings($conn, $arsCompanyId);
$cleaningCompanyId = $settings['cleaning_company_id'] ?? null;
$currency = $settings['currency'] ?? 'AED';
ars_early_checkout_ensure_schema($conn);

$today = date('Y-m-d');
$filterDate = $_GET['date'] ?? $today;
$filterStatus = $_GET['status'] ?? '';
$highlightId = (int)($_GET['highlight'] ?? 0);
$filterBookingId = (int)($_GET['booking_id'] ?? 0);
$filterUnitId = (int)($_GET['unit_id'] ?? 0);
$validStatuses = ['confirmed', 'scheduled', 'in_progress', 'completed', 'cancelled'];

// Orders for the configured cleaning company that ARS created
$baseWhere = 'mo.company_id = ? AND (mo.client_name = ? OR mo.ars_booking_id IS NOT NULL)';
$baseParams = [$cleaningCompanyId ?: 0, 'ARS Home Rentals'];
$svcDateExpr = 'DATE(COALESCE(mo.service_date, mo.date))';

$todayTasks = [];
$allOrders = [];
$upcomingCheckouts = [];
$pendingToday = $inProgressToday = $completedToday = $totalMonth = 0;

if ($cleaningCompanyId) {
    // Today's board (same dataset drives KPIs — avoids tile/list mismatch)
    $stmt = $conn->prepare("
        SELECT mo.*, b.booking_number, b.guest_id, b.check_out AS planned_check_out,
               b.actual_check_out, b.is_early_checkout,
               g.first_name AS guest_first, g.last_name AS guest_last,
               u.unit_number, bl.name AS building_name
        FROM make_order mo
        LEFT JOIN ars_bookings b ON b.id = mo.ars_booking_id
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bl ON bl.id = u.building_id
        WHERE {$baseWhere}
          AND {$svcDateExpr} = ?
          AND mo.status != 'cancelled'
        ORDER BY
            FIELD(mo.status, 'confirmed', 'scheduled', 'in_progress', 'completed'),
            mo.time ASC, mo.id ASC
    ");
    $stmt->execute(array_merge($baseParams, [$today]));
    $todayTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($todayTasks as $t) {
        $st = (string)($t['status'] ?? '');
        if (in_array($st, ['confirmed', 'scheduled'], true)) {
            $pendingToday++;
        } elseif ($st === 'in_progress') {
            $inProgressToday++;
        } elseif ($st === 'completed') {
            $completedToday++;
        }
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM make_order mo
        WHERE {$baseWhere}
          AND mo.status != 'cancelled'
          AND YEAR({$svcDateExpr}) = YEAR(CURDATE())
          AND MONTH({$svcDateExpr}) = MONTH(CURDATE())
    ");
    $stmt->execute($baseParams);
    $totalMonth = (int)$stmt->fetchColumn();

    // Filterable order list
    $allWhere = $baseWhere . " AND {$svcDateExpr} = ?";
    $allParams = array_merge($baseParams, [$filterDate]);
    if ($filterStatus && in_array($filterStatus, $validStatuses, true)) {
        $allWhere .= ' AND mo.status = ?';
        $allParams[] = $filterStatus;
    }
    $stmt = $conn->prepare("
        SELECT mo.*, b.booking_number, b.guest_id,
               g.first_name AS guest_first, g.last_name AS guest_last,
               u.unit_number, bl.name AS building_name
        FROM make_order mo
        LEFT JOIN ars_bookings b ON b.id = mo.ars_booking_id
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bl ON bl.id = u.building_id
        WHERE {$allWhere}
        ORDER BY mo.created_at DESC
    ");
    $stmt->execute($allParams);
    $allOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Guests still in-house with planned departure in the next 3 days
    $stmt = $conn->prepare("
        SELECT b.id, b.booking_number, b.check_out, b.status AS bk_status, b.unit_id,
               g.first_name, g.last_name,
               u.unit_number, bl.name AS building_name,
               EXISTS(
                   SELECT 1 FROM make_order mo2
                   WHERE mo2.ars_booking_id = b.id AND mo2.status != 'cancelled'
               ) AS has_cleaning_wo
        FROM ars_bookings b
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bl ON bl.id = u.building_id
        WHERE b.company_id = ?
          AND b.status = 'checked_in'
          AND b.check_out BETWEEN ? AND DATE_ADD(?, INTERVAL 3 DAY)
        ORDER BY b.check_out ASC
        LIMIT 10
    ");
    $stmt->execute([$arsCompanyId, $today, $today]);
    $upcomingCheckouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Deep-link from booking view: highlight WO for booking / unit when highlight id not given
if ($highlightId <= 0 && $filterBookingId > 0) {
    foreach (array_merge($todayTasks, $allOrders) as $row) {
        if ((int)($row['ars_booking_id'] ?? 0) === $filterBookingId) {
            $highlightId = (int)$row['id'];
            break;
        }
    }
}

$boardPending = array_values(array_filter($todayTasks, static fn($t) => in_array($t['status'], ['confirmed', 'scheduled'], true)));
$boardProgress = array_values(array_filter($todayTasks, static fn($t) => $t['status'] === 'in_progress'));
$boardDone = array_values(array_filter($todayTasks, static fn($t) => $t['status'] === 'completed'));

$pageTitle = 'Housekeeping';

if ($filterBookingId > 0 || $filterUnitId > 0) {
    $hkDeepLinkNote = 'Deep-linked from booking';
    if ($filterBookingId > 0) {
        $hkDeepLinkNote .= ' #' . $filterBookingId;
    }
    if ($filterUnitId > 0) {
        $hkDeepLinkNote .= ' (unit ' . $filterUnitId . ')';
    }
    $hkDeepLinkNote .= $highlightId > 0
        ? ' — matching cleaning order highlighted.'
        : ' — no cleaning order yet (created after checkout).';
} else {
    $hkDeepLinkNote = '';
}

function hkStatusBadge(string $status): string {
    $map = [
        'confirmed' => '<span class="badge bg-primary"><i class="bi bi-hourglass-split me-1"></i>To start</span>',
        'scheduled' => '<span class="badge bg-info"><i class="bi bi-calendar-check me-1"></i>Scheduled</span>',
        'in_progress' => '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-repeat me-1"></i>In progress</span>',
        'completed' => '<span class="badge bg-success"><i class="bi bi-check2-all me-1"></i>Completed</span>',
        'cancelled' => '<span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Cancelled</span>',
        'invoiced' => '<span class="badge bg-dark"><i class="bi bi-receipt me-1"></i>Invoiced</span>',
    ];
    return $map[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}

/**
 * @param array<string,mixed> $task
 */
function hk_render_task_card(array $task, int $highlightId, int $filterBookingId = 0): string {
    $id = (int)$task['id'];
    $unitLabel = $task['unit_number'] ?: 'N/A';
    $bookingNum = $task['booking_number'] ?: '—';
    $guestName = trim(($task['guest_first'] ?? '') . ' ' . ($task['guest_last'] ?? '')) ?: '—';
    $building = $task['building_name'] ?: '';
    $status = (string)$task['status'];
    $worker = trim((string)($task['worker_name'] ?? ''));
    $isEarly = !empty($task['is_early_checkout']);
    $borderColor = match ($status) {
        'confirmed', 'scheduled' => 'var(--ars-accent)',
        'in_progress' => 'var(--ars-coral)',
        'completed' => 'var(--ars-success)',
        default => 'var(--ars-border)',
    };
    $nextHint = match ($status) {
        'confirmed', 'scheduled' => 'Next: Start when the cleaner begins',
        'in_progress' => 'Next: Complete when the unit is ready',
        'completed' => 'Unit ready for next guest',
        default => '',
    };

    $hl = ($highlightId === $id || ($filterBookingId > 0 && (int)($task['ars_booking_id'] ?? 0) === $filterBookingId))
        ? ' border border-warning border-2'
        : '';
    $html = '<div class="col-12" id="task-card-' . $id . '">';
    $html .= '<div class="ars-card h-100' . $hl . '" style="border-left:4px solid ' . $borderColor . '">';
    $html .= '<div class="card-body py-3 px-3">';
    $html .= '<div class="d-flex justify-content-between align-items-start mb-2 gap-2">';
    $html .= '<div><h6 class="mb-0 fw-bold"><i class="bi bi-door-open me-1" style="color:var(--ars-primary)"></i>'
        . h($unitLabel) . '</h6>';
    if ($building !== '') {
        $html .= '<small class="text-muted">' . h($building) . '</small>';
    }
    $html .= '</div><div>' . hkStatusBadge($status) . '</div></div>';

    $html .= '<div class="small mb-2">';
    $html .= '<div><i class="bi bi-hash me-1 text-muted"></i>WO #' . $id . '</div>';
    $html .= '<div><i class="bi bi-journal-bookmark me-1 text-muted"></i>';
    if (!empty($task['ars_booking_id'])) {
        $html .= '<a href="booking_view.php?id=' . (int)$task['ars_booking_id'] . '" class="text-decoration-none">' . h($bookingNum) . '</a>';
    } else {
        $html .= h($bookingNum);
    }
    $html .= '</div>';
    $html .= '<div><i class="bi bi-person me-1 text-muted"></i>' . h($guestName) . '</div>';
    $html .= '<div><i class="bi bi-clock me-1 text-muted"></i>' . h($task['time'] ?: '—') . '</div>';
    if ($worker !== '') {
        $html .= '<div><i class="bi bi-person-badge me-1 text-muted"></i>Cleaner: ' . h($worker) . '</div>';
    }
    if ($isEarly) {
        $html .= '<div class="text-warning"><i class="bi bi-lightning me-1"></i>Early checkout — clean today</div>';
    }
    $html .= '</div>';

    if (!empty($task['remark'])) {
        $html .= '<div class="small text-muted mb-2" style="max-height:48px;overflow:hidden">' . h((string)$task['remark']) . '</div>';
    }
    if ($nextHint !== '') {
        $html .= '<div class="small fw-semibold mb-2" style="color:var(--ars-primary)">' . h($nextHint) . '</div>';
    }

    $html .= '<div class="d-flex gap-2 mt-auto flex-wrap">';
    if (in_array($status, ['confirmed', 'scheduled'], true)) {
        $html .= '<button type="button" class="btn btn-sm btn-warning flex-fill" onclick="hkAction(' . $id . ', \'start\')">'
            . '<i class="bi bi-play-fill me-1"></i>Start cleaning</button>';
    }
    if ($status === 'in_progress') {
        $html .= '<button type="button" class="btn btn-sm btn-success flex-fill" onclick="hkAction(' . $id . ', \'complete\')">'
            . '<i class="bi bi-check2-all me-1"></i>Mark complete</button>';
    }
    if ($status === 'completed') {
        $html .= '<span class="btn btn-sm btn-outline-success flex-fill disabled"><i class="bi bi-check-circle me-1"></i>Done</span>';
    }
    if (!in_array($status, ['completed', 'cancelled', 'invoiced'], true)) {
        $html .= '<button type="button" class="btn btn-sm btn-outline-danger" onclick="hkAction(' . $id . ', \'cancel\')" title="Cancel order">'
            . '<i class="bi bi-x-lg"></i></button>';
    }
    $html .= '</div></div></div></div>';
    return $html;
}

ars_shell_begin([
    'title' => 'Housekeeping',
    'subtitle' => date('l, F j, Y'),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Operations', 'href' => 'housekeeping.php'],
        ['label' => 'Housekeeping'],
    ],
    'legacy_bootstrap' => true,
]);
?>

<?php if ($hkDeepLinkNote !== ''): ?>
<div class="alert alert-info py-2">
    <i class="bi bi-link-45deg me-1"></i><?= h($hkDeepLinkNote) ?>
    <a href="housekeeping.php" class="alert-link ms-2">Clear</a>
</div>
<?php endif; ?>

<?php if (!$cleaningCompanyId): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>No cleaning company configured.</strong>
    Set a cleaning company in <a href="settings.php" class="alert-link">ARS Settings</a> so checkout can create work orders.
</div>
<?php else: ?>

<section class="ars-ws-next-step mb-4" aria-label="Housekeeping workflow">
  <div class="ars-ws-next-step-body">
    <div class="ars-ws-next-step-eyebrow">Operator guide</div>
    <h3 class="ars-ws-next-step-title">After guest checkout → clean the unit</h3>
    <p class="ars-ws-next-step-text mb-0">
      Checkout automatically creates a cleaning work order for the <strong>actual departure date</strong>
      (including early checkout). Your job here:
      <strong>1)</strong> Start when the cleaner begins →
      <strong>2)</strong> Mark complete when the unit is ready →
      then the next guest can check in.
    </p>
  </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('To start today', (string)$pendingToday, ['tone' => $pendingToday > 0 ? 'warn' : 'default', 'icon' => 'clock', 'hint' => 'Awaiting Start']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('In progress', (string)$inProgressToday, ['tone' => $inProgressToday > 0 ? 'warn' : 'default', 'icon' => 'refresh-cw', 'hint' => 'Cleaner on site']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Completed today', (string)$completedToday, ['tone' => 'ok', 'icon' => 'check-circle', 'hint' => 'Unit ready']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('This month', (string)$totalMonth, ['tone' => 'default', 'icon' => 'calendar', 'hint' => 'All non-cancelled WOs']) ?></div>
</div>

<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-kanban me-2"></i>Today’s board <span class="badge bg-secondary ms-1"><?= count($todayTasks) ?></span></span>
        <span class="small text-muted">Service date <?= h($today) ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($todayTasks)): ?>
            <div class="text-center text-muted py-4">
                <i class="bi bi-check-circle fs-2 d-block mb-2" style="color:var(--ars-success)"></i>
                No cleaning work orders for today. When a guest checks out, a WO appears here automatically.
            </div>
        <?php else: ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="small text-uppercase text-muted">To start</strong>
                        <span class="badge bg-primary"><?= count($boardPending) ?></span>
                    </div>
                    <div class="row g-2">
                        <?php if (empty($boardPending)): ?>
                            <div class="col-12"><div class="border rounded p-3 small text-muted text-center">Nothing waiting</div></div>
                        <?php else: ?>
                            <?php foreach ($boardPending as $task): ?>
                                <?= hk_render_task_card($task, $highlightId, $filterBookingId) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="small text-uppercase text-muted">In progress</strong>
                        <span class="badge bg-warning text-dark"><?= count($boardProgress) ?></span>
                    </div>
                    <div class="row g-2">
                        <?php if (empty($boardProgress)): ?>
                            <div class="col-12"><div class="border rounded p-3 small text-muted text-center">No active cleans</div></div>
                        <?php else: ?>
                            <?php foreach ($boardProgress as $task): ?>
                                <?= hk_render_task_card($task, $highlightId, $filterBookingId) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="small text-uppercase text-muted">Done today</strong>
                        <span class="badge bg-success"><?= count($boardDone) ?></span>
                    </div>
                    <div class="row g-2">
                        <?php if (empty($boardDone)): ?>
                            <div class="col-12"><div class="border rounded p-3 small text-muted text-center">None completed yet</div></div>
                        <?php else: ?>
                            <?php foreach ($boardDone as $task): ?>
                                <?= hk_render_task_card($task, $highlightId, $filterBookingId) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($upcomingCheckouts)): ?>
<div class="ars-card mb-4">
    <div class="card-header">
        <i class="bi bi-box-arrow-right me-2"></i>Upcoming checkouts (next 3 days)
        <span class="badge bg-info ms-1"><?= count($upcomingCheckouts) ?></span>
    </div>
    <div class="card-body py-2 border-bottom">
        <p class="small text-muted mb-0">Guests still in-house. A cleaning WO is created <strong>when you check them out</strong> (not before).</p>
    </div>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead><tr><th>Booking</th><th>Guest</th><th>Unit</th><th>Building</th><th>Planned checkout</th><th>Cleaning WO</th></tr></thead>
            <tbody>
            <?php foreach ($upcomingCheckouts as $co): ?>
                <tr class="<?= ($filterBookingId > 0 && (int)$co['id'] === $filterBookingId) || ($filterUnitId > 0 && (int)($co['unit_id'] ?? 0) === $filterUnitId) ? 'table-warning' : '' ?>"
                    <?= ($filterBookingId > 0 && (int)$co['id'] === $filterBookingId) ? 'id="hk-booking-row"' : '' ?>>
                    <td data-label="Booking">
                        <a href="booking_view.php?id=<?= (int)$co['id'] ?>" class="fw-semibold text-decoration-none" style="color:var(--ars-primary)"><?= h($co['booking_number']) ?></a>
                    </td>
                    <td data-label="Guest"><?= h(($co['first_name'] ?? '') . ' ' . ($co['last_name'] ?? '')) ?></td>
                    <td data-label="Unit"><?= h($co['unit_number'] ?? '') ?></td>
                    <td data-label="Building"><?= h($co['building_name'] ?? '—') ?></td>
                    <td data-label="Checkout">
                        <?php
                        $coDate = $co['check_out'];
                        $isToday = ($coDate === $today);
                        $isTomorrow = ($coDate === date('Y-m-d', strtotime('+1 day')));
                        ?>
                        <?php if ($isToday): ?>
                            <span class="badge bg-danger">Today</span>
                        <?php elseif ($isTomorrow): ?>
                            <span class="badge bg-warning text-dark">Tomorrow</span>
                        <?php else: ?>
                            <?= h($coDate) ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="WO">
                        <?php if (!empty($co['has_cleaning_wo'])): ?>
                            <span class="badge bg-success">Created</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">After checkout</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-list-task me-2"></i>Orders by date</span>
        <span class="small text-muted">Browse or filter any service day</span>
    </div>
    <div class="card-body py-2 no-print">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-sm-4 col-lg-3">
                <label class="form-label fw-semibold small mb-1">Service date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= h($filterDate) ?>">
            </div>
            <div class="col-6 col-sm-4 col-lg-3">
                <label class="form-label fw-semibold small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($validStatuses as $s): ?>
                        <option value="<?= h($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-4 col-lg-2">
                <?= ars_ui_button('Filter', ['type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'filter', 'class' => 'w-full justify-center']) ?>
            </div>
            <?php if ($filterDate !== $today || $filterStatus !== ''): ?>
            <div class="col-auto">
                <a href="housekeeping.php" class="btn btn-sm btn-outline-secondary">Today</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
    <?php if (empty($allOrders)): ?>
        <div class="card-body text-center text-muted py-4">
            <i class="bi bi-inbox fs-3 d-block mb-2"></i>No cleaning orders for <?= h($filterDate) ?>.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ars-table ars-mobile-cards mb-0">
                <thead>
                    <tr>
                        <th>Order #</th><th>Unit</th><th>Building</th><th>Booking</th>
                        <th>Service date</th><th>Time</th><th>Cleaner</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allOrders as $o):
                    $unitLabel = $o['unit_number'] ?: '—';
                    $bookingNum = $o['booking_number'] ?: '—';
                ?>
                    <tr id="order-row-<?= (int)$o['id'] ?>" class="<?= ($highlightId === (int)$o['id'] || ($filterBookingId > 0 && (int)($o['ars_booking_id'] ?? 0) === $filterBookingId)) ? 'table-warning' : '' ?>">
                        <td data-label="Order #" class="fw-semibold">#<?= (int)$o['id'] ?></td>
                        <td data-label="Unit"><?= h($unitLabel) ?></td>
                        <td data-label="Building"><?= h($o['building_name'] ?: '—') ?></td>
                        <td data-label="Booking">
                            <?php if ($o['ars_booking_id']): ?>
                                <a href="booking_view.php?id=<?= (int)$o['ars_booking_id'] ?>" class="text-decoration-none" style="color:var(--ars-primary)"><?= h($bookingNum) ?></a>
                            <?php else: ?>
                                <?= h($bookingNum) ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Service date"><?= h($o['service_date'] ?: $o['date'] ?: '—') ?></td>
                        <td data-label="Time"><?= h($o['time'] ?: '—') ?></td>
                        <td data-label="Cleaner"><?= h($o['worker_name'] ?: '—') ?></td>
                        <td data-label="Status" id="status-<?= (int)$o['id'] ?>"><?= hkStatusBadge((string)$o['status']) ?></td>
                        <td data-label="Actions">
                            <div class="d-flex gap-1 flex-wrap" id="actions-<?= (int)$o['id'] ?>">
                                <?php if (in_array($o['status'], ['confirmed', 'scheduled'], true)): ?>
                                    <button type="button" class="btn btn-sm btn-warning" onclick="hkAction(<?= (int)$o['id'] ?>, 'start')" title="Start"><i class="bi bi-play-fill"></i></button>
                                <?php endif; ?>
                                <?php if ($o['status'] === 'in_progress'): ?>
                                    <button type="button" class="btn btn-sm btn-success" onclick="hkAction(<?= (int)$o['id'] ?>, 'complete')" title="Complete"><i class="bi bi-check2-all"></i></button>
                                <?php endif; ?>
                                <?php if (!in_array($o['status'], ['completed', 'cancelled', 'invoiced'], true)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="hkAction(<?= (int)$o['id'] ?>, 'cancel')" title="Cancel"><i class="bi bi-x-lg"></i></button>
                                <?php endif; ?>
                                <?php if ($o['status'] === 'completed'): ?>
                                    <span class="text-success small"><i class="bi bi-check-circle-fill"></i></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php
$highlightJs = ($highlightId > 0 || $filterBookingId > 0)
    ? "\ndocument.addEventListener('DOMContentLoaded',function(){var id=" . (int)$highlightId . ";var el=(id?document.getElementById('task-card-'+id):null)||(id?document.getElementById('order-row-'+id):null)||document.getElementById('hk-booking-row');if(el){el.scrollIntoView({behavior:'smooth',block:'center'});}});\n"
    : '';
$pageScripts = <<<JS
<script>
function hkAction(orderId, action) {
    if (action === 'cancel' && !confirm('Cancel this cleaning work order?')) return;
    var labels = { start: 'Starting…', complete: 'Completing…', cancel: 'Cancelling…' };

    var fd = new FormData();
    fd.append('action', action);
    fd.append('order_id', orderId);
    fd.append('_csrf', window.ARS_CSRF || '');

    fetch('ajax_housekeeping_actions.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                location.reload();
            } else {
                alert(d.error || 'Action failed');
            }
        })
        .catch(function () { alert('Network error'); });
}
{$highlightJs}
</script>
JS;
if (!empty($pageScripts) && !empty($GLOBALS['ars_shell_state'])) {
    $GLOBALS['ars_shell_state']['pageScripts'] = $pageScripts;
}
ars_shell_end();
?>
