<?php
/**
 * ARS Command Center (Phase 3B Milestone 1 / original Wave 2)
 * Today-first operational home. Read-only KPIs from existing tables.
 * No financial calculation changes.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_unit_history_helper.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
expirePendingBookings($conn, $arsCompanyId);

$today = date('Y-m-d');
$hasCore = function_exists('has_department_access') && has_department_access(MODULE_ARS, DEPT_ARS_CORE, $conn);
$hasOps = function_exists('has_department_access') && has_department_access(MODULE_ARS, DEPT_ARS_OPERATIONS, $conn);

// Arrivals today
$stmt = $conn->prepare("
    SELECT b.id, b.booking_number, b.status, b.check_in, b.check_out, b.total_amount, b.paid_amount, b.balance_due,
           g.first_name, g.last_name, u.unit_number
    FROM ars_bookings b
    LEFT JOIN ars_guests g ON g.id = b.guest_id
    LEFT JOIN re_units u ON u.id = b.unit_id
    WHERE b.company_id = ? AND b.check_in = ? AND b.status IN ('confirmed','checked_in','pending')
    ORDER BY b.check_in, b.booking_number
    LIMIT 40
");
$stmt->execute([$arsCompanyId, $today]);
$arrivals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Departures today
$stmt = $conn->prepare("
    SELECT b.id, b.booking_number, b.status, b.check_in, b.check_out,
           g.first_name, g.last_name, u.unit_number
    FROM ars_bookings b
    LEFT JOIN ars_guests g ON g.id = b.guest_id
    LEFT JOIN re_units u ON u.id = b.unit_id
    WHERE b.company_id = ? AND b.check_out = ? AND b.status IN ('checked_in','checked_out','confirmed')
    ORDER BY b.check_out, b.booking_number
    LIMIT 40
");
$stmt->execute([$arsCompanyId, $today]);
$departures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// In-house
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM ars_bookings WHERE company_id = ? AND status = 'checked_in'
");
$stmt->execute([$arsCompanyId]);
$inHouseCount = (int)$stmt->fetchColumn();

// Unpaid / balance due among active (count + top list share the same definition)
$unpaidWhere = "company_id = ? AND status IN ('confirmed','checked_in') AND COALESCE(balance_due, 0) > 0.009";
$stmt = $conn->prepare("SELECT COUNT(*) FROM ars_bookings WHERE {$unpaidWhere}");
$stmt->execute([$arsCompanyId]);
$unpaidCount = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT b.id, b.booking_number, b.total_amount, b.paid_amount, b.balance_due, g.first_name, g.last_name, u.unit_number
    FROM ars_bookings b
    LEFT JOIN ars_guests g ON g.id = b.guest_id
    LEFT JOIN re_units u ON u.id = b.unit_id
    WHERE b.company_id = ? AND b.status IN ('confirmed','checked_in')
      AND COALESCE(b.balance_due, 0) > 0.009
    ORDER BY b.balance_due DESC
    LIMIT 8
");
$stmt->execute([$arsCompanyId]);
$unpaid = $stmt->fetchAll(PDO::FETCH_ASSOC);

// HK dirty / open orders via cleaning make_order bridge
$hkDirty = [];
$hkDirtyCount = 0;
$settings = getArsSettings($conn, $arsCompanyId);
$cleaningCompanyId = $settings['cleaning_company_id'] ?? null;
if ($cleaningCompanyId) {
    try {
        $stmt = $conn->prepare("
            SELECT mo.id, mo.status, COALESCE(mo.service_date, mo.date) AS scheduled_date,
                   u.unit_number, b.booking_number
            FROM make_order mo
            LEFT JOIN ars_bookings b ON b.id = mo.ars_booking_id
            LEFT JOIN re_units u ON u.id = b.unit_id
            WHERE mo.company_id = ?
              AND (mo.client_name = ? OR mo.ars_booking_id IS NOT NULL)
              AND mo.status IN ('confirmed','scheduled','in_progress')
            ORDER BY COALESCE(mo.service_date, mo.date) IS NULL, COALESCE(mo.service_date, mo.date), mo.id DESC
            LIMIT 20
        ");
        $stmt->execute([(int)$cleaningCompanyId, 'ARS Home Rentals']);
        $hkDirty = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hkDirtyCount = count($hkDirty);
    } catch (Throwable $e) {
    }
}

// Maintenance open (RE maintenance requests on ARS units)
$maintOpen = [];
try {
    [$unitWhere, $unitParams] = ars_short_term_units_where($arsCompanyId, 'u');
    $stmt = $conn->prepare("
        SELECT mr.id, mr.description AS title, mr.status, mr.priority, u.unit_number
        FROM re_maintenance_requests mr
        INNER JOIN re_units u ON u.id = mr.unit_id
        WHERE {$unitWhere} AND mr.status IN ('pending','in_progress','open','assigned')
        ORDER BY mr.request_date DESC
        LIMIT 15
    ");
    $stmt->execute($unitParams);
    $maintOpen = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

// Deposits pending
$depositsPending = [];
try {
    $stmt = $conn->prepare("
        SELECT b.id, b.booking_number, b.deposit_amount, b.deposit_status,
               g.first_name, g.last_name, u.unit_number
        FROM ars_bookings b
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        WHERE b.company_id = ? AND b.deposit_status IN ('pending')
        ORDER BY b.id DESC LIMIT 12
    ");
    $stmt->execute([$arsCompanyId]);
    $depositsPending = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

// Occupancy glance — distinct units with an active stay (not booking count / units)
[$unitWhereOcc, $unitParamsOcc] = ars_short_term_units_where($arsCompanyId, 'u');
$stmt = $conn->prepare("SELECT COUNT(*) FROM re_units u WHERE {$unitWhereOcc}");
$stmt->execute($unitParamsOcc);
$unitCount = (int)$stmt->fetchColumn();
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT b.unit_id)
    FROM ars_bookings b
    WHERE b.company_id = ?
      AND b.status IN ('confirmed','checked_in')
      AND b.unit_id IS NOT NULL
");
$stmt->execute([$arsCompanyId]);
$occupiedUnits = (int)$stmt->fetchColumn();
$stmt = $conn->prepare("SELECT COUNT(*) FROM ars_bookings WHERE company_id = ? AND status IN ('confirmed','checked_in')");
$stmt->execute([$arsCompanyId]);
$activeBookings = (int)$stmt->fetchColumn();
$occPct = $unitCount > 0 ? (int)min(100, round(($occupiedUnits / $unitCount) * 100)) : 0;

$revenueMTD = 0.0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM ars_booking_payments WHERE company_id = ? AND YEAR(payment_date)=YEAR(NOW()) AND MONTH(payment_date)=MONTH(NOW())");
$stmt->execute([$arsCompanyId]);
$revenueMTD = (float)$stmt->fetchColumn();

// Recent activity
$recentActivity = [];
try {
    $stmt = $conn->prepare("
        SELECT a.id, a.event_type, a.title AS summary, a.created_at, a.booking_id, a.booking_number
        FROM ars_booking_activities a
        WHERE a.company_id = ?
        ORDER BY a.id DESC LIMIT 12
    ");
    $stmt->execute([$arsCompanyId]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$new24h = 0;
$stmt = $conn->prepare("SELECT COUNT(*) FROM ars_bookings WHERE company_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute([$arsCompanyId]);
$new24h = (int)$stmt->fetchColumn();

$pageTitle = 'Command Center';
ars_shell_begin([
    'title' => 'Command Center',
    'subtitle' => date('l, F j, Y') . ' · Today-first operations',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Command Center'],
    ],
    'hide_page_header' => true,
    'legacy_bootstrap' => false,
]);

$guestName = static function (array $r): string {
    return trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Guest';
};

$attentionCount = $unpaidCount + $hkDirtyCount + count($maintOpen) + count($depositsPending);
$attentionClear = $attentionCount === 0;
$todayLabel = date('l, F j, Y');
?>

<?php /* Luxury hero */ ?>
<section class="ars-cc-hero mb-5" aria-labelledby="ars-cc-hero-title">
  <div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
      <div class="ars-cc-hero-eyebrow">ARS · Holiday Homes</div>
      <h1 id="ars-cc-hero-title" class="ars-cc-hero-title">Command Center</h1>
      <p class="ars-cc-hero-sub m-0"><?= h($todayLabel) ?> · Today-first operations</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <?= ars_ui_button('New reservation', [
          'href' => 'booking_add.php',
          'icon' => 'plus',
          'class' => 'ars-btn-press',
      ]) ?>
      <a class="inline-flex min-h-ars-touch items-center gap-1.5 rounded-ars-md border border-ars-border bg-white px-3.5 text-ars-sm font-semibold text-ars-text no-underline hover:border-[rgba(184,134,11,0.45)] hover:bg-[rgba(184,134,11,0.04)]" href="calendar.php">
        <?= ars_ui_icon('calendar', ['class' => 'h-4 w-4 text-ars-ink']) ?>
        Calendar
      </a>
    </div>
  </div>
</section>

<?php /* 1. Immediate attention */ ?>
<section class="ars-cc-attention mb-5 <?= $attentionClear ? 'is-clear' : ($unpaidCount > 0 ? 'is-urgent' : '') ?>" aria-label="Needs attention">
  <div class="ars-cc-attention-head">
    <span class="ars-cc-dot" aria-hidden="true"></span>
    <div class="min-w-0 flex-1">
      <h2 class="m-0 text-ars-sm font-semibold text-ars-text"><?= $attentionClear ? 'All clear for now' : 'Needs attention now' ?></h2>
      <p class="m-0 text-ars-xs text-ars-muted"><?= $attentionClear ? 'No urgent payment, housekeeping, or maintenance alerts.' : 'Prioritize these before routine work.' ?></p>
    </div>
    <?php if (!$attentionClear): ?>
      <span class="rounded-full border border-[rgba(184,134,11,0.28)] bg-[rgba(184,134,11,0.1)] px-2.5 py-0.5 text-ars-xs font-semibold text-[#8a6808]"><?= (int)$attentionCount ?> items</span>
    <?php endif; ?>
  </div>
  <div class="grid gap-0 sm:grid-cols-2 lg:grid-cols-4">
    <a class="ars-cc-ops-row no-underline text-inherit border-r border-ars-border/50" href="bookings.php?view=outstanding">
      <?= ars_ui_icon('banknote', ['class' => 'h-4 w-4 text-ars-ink shrink-0']) ?>
      <div class="min-w-0 flex-1">
        <div class="text-ars-sm font-semibold">Payment follow-up</div>
        <div class="text-ars-xs text-ars-muted"><?= $unpaidCount ?> balance<?= $unpaidCount === 1 ? '' : 's' ?> due</div>
      </div>
      <span class="ars-tabular text-ars-sm font-semibold text-ars-ink"><?= (int)$unpaidCount ?></span>
    </a>
    <a class="ars-cc-ops-row no-underline text-inherit border-r border-ars-border/50" href="housekeeping.php">
      <?= ars_ui_icon('sparkles', ['class' => 'h-4 w-4 text-ars-ink shrink-0']) ?>
      <div class="min-w-0 flex-1">
        <div class="text-ars-sm font-semibold">Units needing action</div>
        <div class="text-ars-xs text-ars-muted"><?= $hkDirtyCount ? 'Housekeeping backlog' : 'HK clear' ?></div>
      </div>
      <span class="ars-tabular text-ars-sm font-semibold text-ars-ink"><?= (int)$hkDirtyCount ?></span>
    </a>
    <a class="ars-cc-ops-row no-underline text-inherit border-r border-ars-border/50" href="maintenance.php">
      <?= ars_ui_icon('wrench', ['class' => 'h-4 w-4 text-ars-ink shrink-0']) ?>
      <div class="min-w-0 flex-1">
        <div class="text-ars-sm font-semibold">Maintenance</div>
        <div class="text-ars-xs text-ars-muted"><?= count($maintOpen) ? 'Open requests' : 'No open work' ?></div>
      </div>
      <span class="ars-tabular text-ars-sm font-semibold text-ars-ink"><?= count($maintOpen) ?></span>
    </a>
    <a class="ars-cc-ops-row no-underline text-inherit" href="<?= $hasCore ? 'financial_reports.php?tab=deposits' : 'bookings.php' ?>">
      <?= ars_ui_icon('shield', ['class' => 'h-4 w-4 text-ars-ink shrink-0']) ?>
      <div class="min-w-0 flex-1">
        <div class="text-ars-sm font-semibold">Deposits pending</div>
        <div class="text-ars-xs text-ars-muted"><?= count($depositsPending) ? 'Awaiting collection' : 'None pending' ?></div>
      </div>
      <span class="ars-tabular text-ars-sm font-semibold text-ars-ink"><?= count($depositsPending) ?></span>
    </a>
  </div>
</section>

<?php /* Compact today metrics — secondary to attention */ ?>
<div class="ars-cc-kpi-row">
  <?= ars_ds_stat_tile('Arrivals today', (string)count($arrivals), ['icon' => 'log-in', 'href' => 'bookings.php?view=arrivals', 'hint' => 'Check-ins scheduled']) ?>
  <?= ars_ds_stat_tile('Departures today', (string)count($departures), ['icon' => 'log-out', 'href' => 'bookings.php?view=departures', 'hint' => 'Check-outs scheduled']) ?>
  <?= ars_ds_stat_tile('In-house', (string)$inHouseCount, ['icon' => 'home', 'href' => 'bookings.php?view=inhouse', 'hint' => 'Currently staying']) ?>
  <?= ars_ds_stat_tile('Occupancy', $occPct . '%', ['icon' => 'building-2', 'hint' => $occupiedUnits . ' of ' . $unitCount . ' units occupied · ' . $activeBookings . ' active stays']) ?>
</div>

<?php /* 2. Arrivals + Departures — primary ops columns */ ?>
<div class="ars-cc-section grid gap-4 lg:grid-cols-2">
  <?php
  $arrBody = '';
  if (!$arrivals) {
      $arrBody = '<div class="ars-cc-empty">'
          . '<div class="ars-cc-empty-icon">' . ars_ui_icon('log-in', ['class' => 'h-5 w-5']) . '</div>'
          . '<div class="text-ars-sm font-semibold text-ars-text">No arrivals today</div>'
          . '<p class="mt-1 mb-3 text-ars-xs text-ars-muted">Enjoy the calm — or create a reservation.</p>'
          . ars_ui_button('New reservation', ['href' => 'booking_add.php', 'icon' => 'plus', 'size' => 'sm'])
          . '</div>';
  } else {
      foreach ($arrivals as $r) {
          $due = max(0, (float)($r['balance_due'] ?? ((float)$r['total_amount'] - (float)($r['paid_amount'] ?? 0))));
          $trail = ars_ui_status_badge('booking', $r['status']);
          if ($due > 0.009) {
              $trail .= ' ' . ars_ui_badge('Due ' . formatArsAmount($due), ['tone' => 'warning', 'icon' => 'alert-circle']);
          }
          $arrBody .= ars_ds_list_row(
              $guestName($r),
              $r['booking_number'] . ' · Unit ' . ($r['unit_number'] ?? '—'),
              'booking_view.php?id=' . (int)$r['id'],
              $trail,
              ['avatar' => ars_ds_avatar(ars_ds_guest_initials($r['first_name'] ?? '', $r['last_name'] ?? ''))]
          );
      }
  }
  echo ars_ds_panel('Arrivals today', $arrBody, [
      'icon' => 'log-in',
      'action_html' => '<a class="inline-flex items-center gap-1 text-ars-xs font-semibold text-ars-ink no-underline" href="bookings.php?view=arrivals">' . ars_ui_icon('arrow-right', ['class' => 'h-3.5 w-3.5']) . ' View all</a>',
  ]);

  $depBody = '';
  if (!$departures) {
      $depBody = '<div class="ars-cc-empty">'
          . '<div class="ars-cc-empty-icon">' . ars_ui_icon('log-out', ['class' => 'h-5 w-5']) . '</div>'
          . '<div class="text-ars-sm font-semibold text-ars-text">No departures today</div>'
          . '<p class="mt-1 mb-0 text-ars-xs text-ars-muted">No check-outs scheduled for today.</p>'
          . '</div>';
  } else {
      foreach ($departures as $r) {
          $depBody .= ars_ds_list_row(
              $guestName($r),
              $r['booking_number'] . ' · Unit ' . ($r['unit_number'] ?? '—'),
              'booking_view.php?id=' . (int)$r['id'],
              ars_ui_status_badge('booking', $r['status']),
              ['avatar' => ars_ds_avatar(ars_ds_guest_initials($r['first_name'] ?? '', $r['last_name'] ?? ''))]
          );
      }
  }
  echo ars_ds_panel('Departures today', $depBody, [
      'icon' => 'log-out',
      'action_html' => '<a class="inline-flex items-center gap-1 text-ars-xs font-semibold text-ars-ink no-underline" href="bookings.php?view=departures">' . ars_ui_icon('arrow-right', ['class' => 'h-3.5 w-3.5']) . ' View all</a>',
  ]);
  ?>
</div>

<?php /* 3–4. Payment risk + unit attention */ ?>
<div class="ars-cc-section grid gap-4 lg:grid-cols-2">
  <?php
  $payBody = '';
  if (!$unpaid) {
      $payBody = '<div class="ars-cc-empty">'
          . '<div class="ars-cc-empty-icon">' . ars_ui_icon('banknote', ['class' => 'h-5 w-5']) . '</div>'
          . '<div class="text-ars-sm font-semibold text-ars-text">No payment risk</div>'
          . '<p class="mt-1 mb-0 text-ars-xs text-ars-muted">No outstanding balances on active stays.</p>'
          . '</div>';
  } else {
      foreach (array_slice($unpaid, 0, 8) as $r) {
          $bal = max(0, (float)($r['balance_due'] ?? ((float)$r['total_amount'] - (float)($r['paid_amount'] ?? 0))));
          $payBody .= ars_ds_list_row(
              $guestName($r),
              $r['booking_number'] . ' · Unit ' . ($r['unit_number'] ?? '—'),
              'booking_view.php?id=' . (int)$r['id'],
              '<span class="ars-tabular text-ars-sm font-semibold text-ars-danger">' . h(formatArsAmount($bal)) . '</span>',
              ['avatar' => ars_ds_avatar(ars_ds_guest_initials($r['first_name'] ?? '', $r['last_name'] ?? ''))]
          );
      }
  }
  echo ars_ds_panel('Payment risk', $payBody, [
      'icon' => 'banknote',
      'action_html' => $unpaidCount > 0
          ? '<a class="inline-flex items-center gap-1 text-ars-xs font-semibold text-ars-ink no-underline" href="bookings.php?view=outstanding">' . ars_ui_icon('arrow-right', ['class' => 'h-3.5 w-3.5']) . ' View all ' . (int)$unpaidCount . '</a>'
          : '',
  ]);

  $unitBody = '';
  if (!$hkDirty && !$maintOpen) {
      $unitBody = '<div class="ars-cc-empty">'
          . '<div class="ars-cc-empty-icon">' . ars_ui_icon('building-2', ['class' => 'h-5 w-5']) . '</div>'
          . '<div class="text-ars-sm font-semibold text-ars-text">Units look settled</div>'
          . '<p class="mt-1 mb-0 text-ars-xs text-ars-muted">No units flagged for housekeeping or maintenance.</p>'
          . '</div>';
  } else {
      foreach (array_slice($hkDirty, 0, 6) as $h) {
          $unitBody .= ars_ds_list_row(
              'Unit ' . ($h['unit_number'] ?? '—'),
              (!empty($h['booking_number']) ? $h['booking_number'] . ' · ' : '') . 'Housekeeping',
              'housekeeping.php',
              ars_ui_badge(ucfirst(str_replace('_', ' ', $h['status'] ?? '')), ['tone' => 'warning', 'icon' => 'sparkles'])
          );
      }
      foreach (array_slice($maintOpen, 0, 4) as $m) {
          $unitBody .= ars_ds_list_row(
              'Unit ' . ($m['unit_number'] ?? '—'),
              trim(($m['title'] ?? 'Maintenance') ?: 'Maintenance'),
              'maintenance.php',
              ars_ui_badge(ucfirst(str_replace('_', ' ', $m['status'] ?? '')), ['tone' => 'info', 'icon' => 'wrench'])
          );
      }
  }
  echo ars_ds_panel('Unit attention', $unitBody, [
      'icon' => 'building-2',
      'action_html' => ($hasOps || $hasCore)
          ? '<a class="text-ars-xs font-semibold text-ars-ink no-underline" href="housekeeping.php">HK board</a>'
          : '',
  ]);
  ?>
</div>

<?php /* 5. Supporting glance + activity */ ?>
<div class="ars-cc-section grid gap-4 lg:grid-cols-3">
  <?php
  $glanceBits = '<div class="grid grid-cols-2 gap-3 p-4">'
      . ($hasCore ? ars_ds_stat_tile('Revenue MTD', formatArsAmount($revenueMTD), ['icon' => 'wallet', 'hint' => 'Payments received']) : ars_ds_stat_tile('Active stays', (string)$activeBookings, ['icon' => 'calendar-check']))
      . ars_ds_stat_tile('New 24h', (string)$new24h, ['icon' => 'sparkles', 'hint' => 'Reservations created'])
      . '</div>';
  echo ars_ds_panel('At a glance', $glanceBits, ['icon' => 'layout-dashboard', 'class' => 'lg:col-span-1']);

  $actBody = '';
  if (!$recentActivity) {
      $actBody = '<div class="ars-cc-empty"><div class="text-ars-sm text-ars-muted">No recent activity.</div></div>';
  } else {
      foreach ($recentActivity as $a) {
          $href = !empty($a['booking_id']) ? 'booking_view.php?id=' . (int)$a['booking_id'] : '';
          $actBody .= ars_ds_list_row(
              $a['summary'] ?: ($a['event_type'] ?? 'Event'),
              ($a['booking_number'] ?? '') . ' · ' . ($a['created_at'] ?? ''),
              $href
          );
      }
  }
  echo '<div class="lg:col-span-2">' . ars_ds_panel('Recent activity', $actBody, [
      'icon' => 'activity',
      'action_html' => '<a class="inline-flex items-center gap-1 text-ars-xs font-semibold text-ars-ink no-underline" href="activity_center.php">' . ars_ui_icon('arrow-right', ['class' => 'h-3.5 w-3.5']) . ' Activity Center</a>',
  ]) . '</div>';
  ?>
</div>

<?php if ($hasCore && $depositsPending): ?>
  <?php
  $depP = '';
  foreach ($depositsPending as $d) {
      $depP .= ars_ds_list_row(
          $guestName($d),
          $d['booking_number'] . ' · Deposit ' . formatArsAmount($d['deposit_amount'] ?? 0),
          'booking_view.php?id=' . (int)$d['id'],
          ars_ui_badge(ucfirst($d['deposit_status'] ?? 'pending'), ['tone' => 'warning', 'icon' => 'shield']),
          ['avatar' => ars_ds_avatar(ars_ds_guest_initials($d['first_name'] ?? '', $d['last_name'] ?? ''))]
      );
  }
  echo '<div class="mb-4">' . ars_ds_panel('Deposits pending', $depP, [
      'icon' => 'shield',
      'action_html' => '<a class="text-ars-xs font-semibold text-ars-ink no-underline" href="financial_reports.php?tab=deposits">Finance</a>',
  ]) . '</div>';
  ?>
<?php endif; ?>

<?php ars_shell_end(); ?>
