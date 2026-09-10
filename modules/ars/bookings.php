<?php
/**
 * Reservations list — Flagship Visual Redesign
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
expirePendingBookings($conn, $arsCompanyId);

$statusFilter = $_GET['status'] ?? '';
$view = $_GET['view'] ?? '';
$search = trim($_GET['q'] ?? '');
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';
$today = date('Y-m-d');
$allowedViews = ['', 'arrivals', 'departures', 'inhouse', 'outstanding'];
if (!in_array($view, $allowedViews, true)) {
    $view = '';
}

if ($view === 'arrivals') {
    $dateFrom = $dateFrom ?: $today;
    $dateTo = $dateTo ?: $today;
}
// Outstanding view owns its own status set — ignore conflicting status filter.
if ($view === 'outstanding') {
    $statusFilter = '';
}

$sql = "
    SELECT b.*, g.first_name, g.last_name, g.phone AS guest_phone, g.email AS guest_email,
           u.unit_number, bl.name AS building_name
    FROM ars_bookings b
    LEFT JOIN ars_guests g ON g.id = b.guest_id
    LEFT JOIN re_units u ON u.id = b.unit_id
    LEFT JOIN re_buildings bl ON bl.id = u.building_id
    WHERE b.company_id = ?
";
$params = [$arsCompanyId];

if ($statusFilter) {
    $sql .= " AND b.status = ?";
    $params[] = $statusFilter;
}
if ($search) {
    $sql .= " AND (b.booking_number LIKE ? OR g.first_name LIKE ? OR g.last_name LIKE ? OR u.unit_number LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($dateFrom && $view !== 'outstanding') {
    $sql .= " AND b.check_in >= ?";
    $params[] = $dateFrom;
}
if ($dateTo && $view !== 'outstanding') {
    $sql .= " AND b.check_out <= ?";
    $params[] = $dateTo;
}
if ($view === 'arrivals') {
    $sql .= " AND b.check_in = ? AND b.status IN ('confirmed','checked_in','pending')";
    $params[] = $today;
} elseif ($view === 'departures') {
    $sql .= " AND b.check_out = ? AND b.status IN ('checked_in','checked_out','confirmed')";
    $params[] = $today;
} elseif ($view === 'inhouse') {
    $sql .= " AND b.status = 'checked_in'";
} elseif ($view === 'outstanding') {
    // Same definition as Command Center payment follow-up.
    $sql .= " AND b.status IN ('confirmed','checked_in') AND COALESCE(b.balance_due, 0) > 0.009";
}

$sql .= $view === 'outstanding'
    ? " ORDER BY b.balance_due DESC, b.check_in ASC LIMIT 200"
    : " ORDER BY b.created_at DESC LIMIT 200";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$subtitle = count($bookings) . ' shown · max 200';
if ($view === 'outstanding') {
    $subtitle = count($bookings) . ' with balance due · confirmed & in-house';
}

$pageTitle = 'Reservations';
ars_shell_begin([
    'title' => 'Reservations',
    'subtitle' => $subtitle,
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reservations'],
    ],
    'actions_html' => ars_ui_button('New reservation', ['href' => 'booking_add.php', 'icon' => 'plus', 'class' => 'ars-btn-press']),
    'toolbar_html' => ars_ds_segment([
        ['id' => 'all', 'label' => 'All', 'icon' => 'list', 'href' => 'bookings.php', 'active' => $view === '' && $statusFilter === ''],
        ['id' => 'arrivals', 'label' => 'Arrivals', 'icon' => 'log-in', 'href' => 'bookings.php?view=arrivals', 'active' => $view === 'arrivals'],
        ['id' => 'departures', 'label' => 'Departures', 'icon' => 'log-out', 'href' => 'bookings.php?view=departures', 'active' => $view === 'departures'],
        ['id' => 'inhouse', 'label' => 'In-house', 'icon' => 'home', 'href' => 'bookings.php?view=inhouse', 'active' => $view === 'inhouse'],
        ['id' => 'outstanding', 'label' => 'Balances due', 'icon' => 'banknote', 'href' => 'bookings.php?view=outstanding', 'active' => $view === 'outstanding'],
        ['id' => 'pending', 'label' => 'Pending', 'icon' => 'clock', 'href' => 'bookings.php?status=pending', 'active' => $statusFilter === 'pending' && $view === ''],
    ]),
]);
?>

<?php ob_start(); ?>
<form method="get" class="flex flex-wrap items-end gap-3" role="search">
  <div class="min-w-[14rem] flex-1">
    <label class="mb-1.5 block text-ars-xs font-medium text-ars-muted" for="q">Search</label>
    <div class="relative">
      <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ars-muted"><?= ars_ui_icon('search', ['class' => 'h-4 w-4']) ?></span>
      <input id="q" name="q" value="<?= h($search) ?>" class="w-full min-h-ars-touch rounded-ars-md border border-ars-border bg-ars-bg pl-9 pr-3 text-ars-sm focus:border-ars-ink" placeholder="Booking, guest, unit…">
    </div>
  </div>
  <div>
    <label class="mb-1.5 block text-ars-xs font-medium text-ars-muted" for="status">Status</label>
    <select id="status" name="status" class="min-h-ars-touch rounded-ars-md border border-ars-border bg-ars-bg px-3 text-ars-sm">
      <option value="">All</option>
      <?php foreach (['pending','confirmed','checked_in','checked_out','completed','cancelled','expired'] as $s): ?>
        <option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $s))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="mb-1.5 block text-ars-xs font-medium text-ars-muted" for="from">From</label>
    <input type="date" id="from" name="from" value="<?= h($dateFrom) ?>" class="min-h-ars-touch rounded-ars-md border border-ars-border bg-ars-bg px-3 text-ars-sm">
  </div>
  <div>
    <label class="mb-1.5 block text-ars-xs font-medium text-ars-muted" for="to">To</label>
    <input type="date" id="to" name="to" value="<?= h($dateTo) ?>" class="min-h-ars-touch rounded-ars-md border border-ars-border bg-ars-bg px-3 text-ars-sm">
  </div>
  <?php if ($view): ?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif; ?>
  <?= ars_ui_button('Filter', ['type' => 'submit', 'variant' => 'secondary', 'icon' => 'search', 'class' => 'ars-btn-press']) ?>
  <a class="no-underline text-ars-sm font-medium text-ars-ink min-h-ars-touch inline-flex items-center gap-1" href="bookings.php"><?= ars_ui_icon('x', ['class' => 'h-3.5 w-3.5']) ?> Clear</a>
</form>
<?php echo ars_ds_filter_card(ob_get_clean()); ?>

<?php if (!$bookings): ?>
  <?= ars_ui_empty_state('No reservations found', 'Try clearing filters or create a new reservation.', [
      'action_html' => ars_ui_button('New reservation', ['href' => 'booking_add.php', 'icon' => 'plus']),
  ]) ?>
<?php else: ?>
  <div class="hidden overflow-hidden rounded-ars-lg border border-ars-border bg-ars-surface shadow-ars-sm md:block">
    <div class="overflow-x-auto">
    <table class="ars-data-table min-w-full text-left">
      <caption class="sr-only">Reservations</caption>
      <thead>
        <tr>
          <th>Guest / booking</th>
          <th>Unit</th>
          <th>Stay</th>
          <th class="text-right">Days left</th>
          <th>Rate type</th>
          <th class="text-right">Rate</th>
          <th class="text-right">Total</th>
          <?php if ($view === 'outstanding'): ?><th class="text-right">Balance</th><?php endif; ?>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bookings as $b):
            $gName = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'Guest';
            $phone = trim((string)($b['guest_phone'] ?? ''));
            $href = 'booking_view.php?id=' . (int)$b['id'];
            $bal = max(0, (float)($b['balance_due'] ?? 0));
        ?>
          <tr class="ars-row-clickable" onclick="location.href='<?= h($href) ?>'">
            <td>
              <div class="flex items-center gap-3">
                <?= ars_ds_avatar(ars_ds_guest_initials($b['first_name'] ?? '', $b['last_name'] ?? '')) ?>
                <div class="min-w-0">
                  <div class="truncate font-semibold text-ars-text"><?= h($gName) ?></div>
                  <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-ars-xs text-ars-muted">
                    <span class="font-medium text-ars-ink"><?= h($b['booking_number']) ?></span>
                    <?php if ($phone !== ''): ?><span><?= h($phone) ?></span><?php endif; ?>
                  </div>
                </div>
              </div>
            </td>
            <td>
              <div class="font-medium text-ars-text"><?= h($b['unit_number'] ?? '—') ?></div>
              <?php if (!empty($b['building_name'])): ?>
                <div class="mt-0.5 text-ars-xs text-ars-muted"><?= h($b['building_name']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-ars-muted whitespace-nowrap"><?= h(ars_ds_format_stay($b['check_in'] ?? '', $b['check_out'] ?? '')) ?></td>
            <td class="text-right ars-tabular whitespace-nowrap">
              <?php
              // Days until planned check-out; stays that have ended show a dash.
              $daysLeft = null;
              if (!empty($b['check_out']) && !in_array($b['status'], ['checked_out', 'completed', 'cancelled', 'expired'], true)) {
                  $daysLeft = (int)(new DateTime(date('Y-m-d')))->diff(new DateTime(substr((string)$b['check_out'], 0, 10)))->format('%r%a');
              }
              if ($daysLeft === null): ?>—<?php
              elseif ($daysLeft < 0): ?><span class="text-ars-danger font-semibold">Overdue <?= abs($daysLeft) ?>d</span><?php
              elseif ($daysLeft === 0): ?><span class="font-semibold">Today</span><?php
              else: ?><?= $daysLeft ?> <?= $daysLeft === 1 ? 'day' : 'days' ?><?php
              endif; ?>
            </td>
            <?php // Display-only rate from the booking wizard; not part of the total. ?>
            <td class="text-ars-muted whitespace-nowrap"><?= !empty($b['display_rate_type']) ? h(ucfirst($b['display_rate_type'])) : '—' ?></td>
            <td class="text-right ars-tabular whitespace-nowrap"><?= isset($b['display_rate']) ? formatArsAmount($b['display_rate']) : '—' ?></td>
            <td class="text-right ars-tabular font-semibold whitespace-nowrap"><?= formatArsAmount($b['total_amount']) ?></td>
            <?php if ($view === 'outstanding'): ?>
            <td class="text-right ars-tabular font-semibold whitespace-nowrap text-ars-danger"><?= formatArsAmount($bal) ?></td>
            <?php endif; ?>
            <td><?= ars_ui_status_badge('booking', $b['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="space-y-2 md:hidden">
    <?php foreach ($bookings as $b):
        $gName = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'Guest';
    ?>
      <a href="booking_view.php?id=<?= (int)$b['id'] ?>" class="ars-interactive block rounded-ars-lg border border-ars-border bg-ars-surface p-4 no-underline text-inherit shadow-ars-sm">
        <div class="flex items-start gap-3">
          <?= ars_ds_avatar(ars_ds_guest_initials($b['first_name'] ?? '', $b['last_name'] ?? '')) ?>
          <div class="min-w-0 flex-1">
            <div class="flex justify-between gap-2">
              <span class="font-semibold text-ars-text"><?= h($gName) ?></span>
              <?= ars_ui_status_badge('booking', $b['status']) ?>
            </div>
            <div class="mt-1 text-ars-xs font-medium text-ars-ink"><?= h($b['booking_number']) ?></div>
            <div class="mt-1.5 text-ars-sm text-ars-muted"><?= h($b['unit_number'] ?? '') ?><?= !empty($b['building_name']) ? ' · ' . h($b['building_name']) : '' ?></div>
            <div class="mt-0.5 flex flex-wrap justify-between gap-2 text-ars-xs text-ars-muted">
              <span><?= h(ars_ds_format_stay($b['check_in'] ?? '', $b['check_out'] ?? '')) ?></span>
              <span class="ars-tabular font-semibold text-ars-text"><?= formatArsAmount($b['total_amount']) ?></span>
            </div>
            <?php if ($view === 'outstanding' && (float)($b['balance_due'] ?? 0) > 0.009): ?>
            <div class="mt-1 text-ars-xs font-semibold text-ars-danger">Balance <?= formatArsAmount($b['balance_due']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php ars_shell_end(); ?>
