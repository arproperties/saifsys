<?php
// hr/leave_calendar.php
// Who is on leave: today, on a month calendar, and as a plain list for the
// month. Approved leave, and requests waiting approval when ticked. Read-only.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_weekly_off.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_role(['Owner','Admin','HR'], $conn);

// ---------- filters ----------
$monthParam = (string)($_GET['month'] ?? '');
$monthStart = preg_match('/^\d{4}-\d{2}$/', $monthParam)
    ? DateTimeImmutable::createFromFormat('!Y-m', $monthParam)
    : null;
$monthStart = ($monthStart ?: new DateTimeImmutable('first day of this month'))->setTime(0, 0);
$monthEnd   = $monthStart->modify('last day of this month');
// Weeks run Monday to Sunday; the grid shows whole weeks.
$gridStart  = $monthStart->modify('-' . ((int)$monthStart->format('N') - 1) . ' days');
$gridEnd    = $monthEnd->modify('+' . (7 - (int)$monthEnd->format('N')) . ' days');

$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);
$dayNames = hr_weekly_off_day_names();

// After a weekly off save, come back to the same view with the Weekly off box open.
$woQs = $_GET;
$woQs['wo'] = 1;
$woBack = 'leave_calendar.php?' . http_build_query($woQs);

// ---------- weekly off by company (one day each; Sunday until set) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_weekly_off'])) {
    csrf_verify();
    $changed = [];
    foreach ($companies as $co) {
        $cid = (int)$co['id'];
        $day = hr_weekly_off_valid_day($_POST['weekly_off'][$cid] ?? '');
        $before = hr_weekly_off_company($conn, $cid);
        if ($day === null || $day === $before) {
            continue;
        }
        hr_weekly_off_save_company($conn, $cid, $day);
        $changed[] = $co['name'] . ': ' . $dayNames[$day];
        $uid = $_SESSION['user']['id'] ?? null;
        audit_bridge_hr_ops('weekly_off_updated', 'settings', $cid,
            'Weekly off for ' . $co['name'] . ' set to ' . $dayNames[$day] . ' (was ' . $dayNames[$before] . ')',
            $cid, ['company_id' => $cid, 'before' => $before, 'after' => $day], 'Weekly off · ' . $co['name'], $uid ? (int)$uid : null);
    }
    $_SESSION['lc_flash'] = ['success', $changed ? 'Weekly off saved, from today. ' . implode(', ', $changed) . '.' : 'No change to weekly off.'];
    header('Location: ' . $woBack);
    exit;
}

// ---------- weekly off for one employee (their own day, or back to the company's) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_employee_weekly_off'])) {
    csrf_verify();
    $eid = (int)($_POST['employee_id'] ?? 0);
    $raw = trim((string)($_POST['weekly_off'] ?? ''));
    $day = $raw === '' ? null : hr_weekly_off_valid_day($raw);
    $st = $conn->prepare("SELECT id, full_name, employee_code, company_id, status FROM employees WHERE id = ?");
    $st->execute([$eid]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e || !hr_employee_is_current_status($e['status'] ?? null)) {
        $_SESSION['lc_flash'] = ['danger', 'Pick a current employee.'];
    } elseif ($raw !== '' && $day === null) {
        $_SESSION['lc_flash'] = ['danger', 'Pick a day for the weekly off.'];
    } else {
        $cid = $e['company_id'] !== null ? (int)$e['company_id'] : null;
        $before = hr_weekly_off_override($conn, $eid);
        hr_weekly_off_save_employee($conn, $eid, $day);
        $now = hr_weekly_off_for_employee($conn, $eid, $cid);
        $label = $e['full_name'] . ' (' . $e['employee_code'] . ')';
        $uid = $_SESSION['user']['id'] ?? null;
        audit_bridge_hr_ops('weekly_off_updated', 'employees', $eid,
            'Weekly off for ' . $label . ': ' . ($day === null ? 'company default (' . $dayNames[$now] . ')' : $dayNames[$day])
                . ' (was ' . ($before === null ? 'company default' : $dayNames[$before]) . ')',
            $cid, ['employee_id' => $eid, 'before' => $before, 'after' => $day], 'Employee #' . $eid, $uid ? (int)$uid : null);
        $_SESSION['lc_flash'] = ['success', 'Weekly off for ' . $label . ': ' . $dayNames[$now] . ($day === null ? ' (company day)' : '') . ', from today.'];
    }
    header('Location: ' . $woBack);
    exit;
}
$companyOff = [];
foreach ($companies as $co) {
    $companyOff[(int)$co['id']] = hr_weekly_off_company($conn, (int)$co['id']);
}
// Employees with their own day (only the selected company's, when one is picked).
$woOwn = [];
foreach (array_keys(hr_weekly_off_rows($conn)) as $k) {
    if (preg_match('/^weekly_off\.e(\d+)$/', (string)$k, $m) && ($own = hr_weekly_off_override($conn, (int)$m[1])) !== null) {
        $woOwn[(int)$m[1]] = $own;
    }
}
$woEmployees = [];
if ($woOwn) {
    $in = implode(',', array_map('intval', array_keys($woOwn)));
    $woEmployees = $conn->query("SELECT e.id, e.full_name, e.employee_code, e.company_id, c.name AS company_name
        FROM employees e LEFT JOIN companies c ON c.id = e.company_id WHERE e.id IN ($in) ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);
    if ($selectedCompanyId > 0) {
        $woEmployees = array_values(array_filter($woEmployees, static fn($r) => (int)$r['company_id'] === $selectedCompanyId));
    }
}
// Current employees to pick from (the selected company's, when one is picked).
$currentStatuses = hr_employee_current_statuses();
$empSql = "SELECT e.id, e.full_name, e.employee_code, e.company_id FROM employees e
           WHERE e.status IN (" . implode(',', array_fill(0, count($currentStatuses), '?')) . ")";
$empParams = $currentStatuses;
if ($selectedCompanyId > 0) {
    $empSql .= " AND e.company_id = ?";
    $empParams[] = $selectedCompanyId;
}
$empStmt = $conn->prepare($empSql . " ORDER BY e.full_name");
$empStmt->execute($empParams);
$woPickList = $empStmt->fetchAll(PDO::FETCH_ASSOC);

// What the calendar shows for weekly off on a date: the day shaded (the selected
// company's, or the one all companies share) and the labels. A change applies
// from the day it was made, so each date uses the days in force on that date.
// The main day reads "Weekly off for all", naming anyone who works it; any other
// day names the company or the employee that has it off.
$coNames = array_column($companies, 'name', 'id');
$woShort = static fn(string $full) => preg_split('/\s+/', trim($full))[0] ?: $full;
$scopeCompanyIds = $selectedCompanyId > 0 ? [$selectedCompanyId] : array_map('intval', array_column($companies, 'id'));
$woStateCache = [];
$woStateOn = static function (string $ymd) use ($conn, $scopeCompanyIds, $woPickList, $coNames, $woShort, $selectedCompanyId, &$woStateCache): array {
    $companyDays = [];
    foreach ($scopeCompanyIds as $cid) {
        $companyDays[$cid] = hr_weekly_off_company($conn, $cid, $ymd);
    }
    $own = [];
    foreach ($woPickList as $pe) {
        $o = hr_weekly_off_override($conn, (int)$pe['id'], $ymd);
        if ($o !== null) {
            $own[(int)$pe['id']] = $o;
        }
    }
    $sig = json_encode([$companyDays, $own]);
    if (isset($woStateCache[$sig])) {
        return $woStateCache[$sig];
    }
    $shown = $selectedCompanyId > 0 ? $companyDays[$selectedCompanyId] : (count(array_unique($companyDays)) === 1 ? (int)reset($companyDays) : null);
    $counts = array_count_values($companyDays);
    arsort($counts);
    $main = $shown ?? (int)array_key_first($counts);
    $labels = [];
    for ($wd = 1; $wd <= 7; $wd++) {
        $labels[$wd] = ['all' => $wd === $main, 'except' => [], 'names' => []];
    }
    foreach ($companyDays as $cid => $cday) {
        if ($cday !== $main) {
            $labels[$main]['except'][] = $coNames[$cid] ?? ('Company ' . $cid);
            $labels[$cday]['names'][] = $coNames[$cid] ?? ('Company ' . $cid);
        }
    }
    foreach ($woPickList as $pe) {
        $o = $own[(int)$pe['id']] ?? null;
        $cday = $companyDays[(int)$pe['company_id']] ?? hr_weekly_off_company($conn, $pe['company_id'] !== null ? (int)$pe['company_id'] : null, $ymd);
        if ($o === null || $o === $cday) {
            continue;
        }
        if ($cday === $main) {
            $labels[$main]['except'][] = $woShort($pe['full_name']);
        }
        $labels[$o]['names'][] = $woShort($pe['full_name']);
    }
    return $woStateCache[$sig] = ['shown' => $shown, 'labels' => $labels];
};
// The column header and legend use today's days.
$shownOff = $woStateOn(date('Y-m-d'))['shown'];
$types = $conn->query("SELECT id, name, color_hex FROM leave_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$typeId = (int)($_GET['type_id'] ?? 0);
$withPending = ($_GET['show'] ?? '') === 'pending';

$today = new DateTimeImmutable('today');
$todayYmd = $today->format('Y-m-d');

// ---------- leave: the grid, plus today ----------
$rangeFrom = min($gridStart->format('Y-m-d'), $todayYmd);
$rangeTo   = max($gridEnd->format('Y-m-d'), $todayYmd);
$where = ["lr.date_from <= ?", "lr.date_to >= ?"];
$params = [$rangeTo, $rangeFrom];
$where[] = $withPending ? "lr.status IN ('approved','pending')" : "lr.status = 'approved'";
if ($selectedCompanyId > 0) {
    $where[] = 'e.company_id = ?';
    $params[] = $selectedCompanyId;
}
if ($typeId > 0) {
    $where[] = 'lr.leave_type_id = ?';
    $params[] = $typeId;
}
$stmt = $conn->prepare("
    SELECT lr.id, lr.employee_id, e.company_id, lr.date_from, lr.date_to, lr.days, lr.status,
           e.full_name, e.nickname, e.employee_code, c.name AS company_name,
           lt.name AS type_name, lt.color_hex
    FROM leave_requests lr
    JOIN employees e ON e.id = lr.employee_id
    LEFT JOIN companies c ON c.id = e.company_id
    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY lr.status = 'pending', lr.date_from, e.full_name
");
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- public holidays (yearly ones repeat on the same day) ----------
$holidayRows = $conn->query("SELECT name, holiday_date, repeats_annually FROM holidays WHERE holiday_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$holidayOn = static function (DateTimeImmutable $d) use ($holidayRows): array {
    $names = [];
    foreach ($holidayRows as $h) {
        if ($h['holiday_date'] === $d->format('Y-m-d')
            || ((int)$h['repeats_annually'] === 1 && substr($h['holiday_date'], 5) === $d->format('m-d'))) {
            $names[] = $h['name'];
        }
    }
    return $names;
};
// First working day after a leave ends: skips the person's weekly off and public holidays.
$backOn = static function (array $l) use ($holidayOn, $conn): DateTimeImmutable {
    $cid = $l['company_id'] !== null ? (int)$l['company_id'] : null;
    $isOff = static fn(DateTimeImmutable $d) => (int)$d->format('N') === hr_weekly_off_for_employee($conn, (int)$l['employee_id'], $cid, $d->format('Y-m-d'));
    $d = (new DateTimeImmutable($l['date_to']))->modify('+1 day');
    for ($i = 0; $i < 14 && ($isOff($d) || $holidayOn($d)); $i++) {
        $d = $d->modify('+1 day');
    }
    return $d;
};

$byDay = [];
$todayAway = [];
foreach ($leaves as $l) {
    $from = max($l['date_from'], $gridStart->format('Y-m-d'));
    $to   = min($l['date_to'], $gridEnd->format('Y-m-d'));
    for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
        $byDay[$d->format('Y-m-d')][] = $l;
    }
    if ($l['date_from'] <= $todayYmd && $l['date_to'] >= $todayYmd) {
        $todayAway[] = $l;
    }
}
$holidays = [];
for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')) {
    if ($names = $holidayOn($d)) {
        $holidays[$d->format('Y-m-d')] = $names;
    }
}

// ---------- helpers ----------
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$colorOf = static fn($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c) ? $c : '#6c757d';
$shortType = static fn($t) => trim(preg_replace('/\s+leave$/i', '', (string)($t ?: 'Leave')));
$nameOf = static fn(array $l) => trim((string)($l['nickname'] ?: $l['full_name']));
$daysText = static function ($days): string {
    $n = (float)$days;
    $s = rtrim(rtrim(number_format($n, 1), '0'), '.');
    return $s . ' day' . ($n == 1.0 ? '' : 's');
};
$fmtRange = static function (string $from, string $to): string {
    $a = new DateTimeImmutable($from);
    $b = new DateTimeImmutable($to);
    if ($from === $to) return $a->format('D j M');
    return $a->format('M Y') === $b->format('M Y')
        ? $a->format('j') . ' – ' . $b->format('j M')
        : $a->format('j M') . ' – ' . $b->format('j M');
};
$link = static function (array $set): string {
    $qs = array_merge($_GET, $set);
    foreach ($qs as $k => $v) {
        if ($v === '' || $v === null || $v === 0) unset($qs[$k]);
    }
    return '?' . http_build_query($qs);
};
$maxPerDay = 4;
$monthName = $monthStart->format('F Y');
$isThisMonth = $monthStart->format('Y-m') === $today->format('Y-m');

$pageTitle = 'Leave Calendar';
$pageHead = '<style>
  .lc-today-strip { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .lc-person { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 6px; background: #f8f9fa; border: 1px solid #e5e7eb; font-size: .85rem; color: #1f2937; text-decoration: none; }
  .lc-person:hover { background: #f1f3f5; color: #111827; }
  .lc-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex: 0 0 auto; }
  .lc-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border-top: 1px solid #e5e7eb; border-left: 1px solid #e5e7eb; }
  .lc-head { padding: 8px; font-size: .8rem; font-weight: 600; color: #4b5563; background: #f9fafb; border-right: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
  .lc-day { min-height: 112px; padding: 6px; border-right: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; background: #fff; }
  .lc-day { cursor: pointer; user-select: none; }
  .lc-day:hover { box-shadow: inset 0 0 0 1px #b8860b; }
  .lc-day.lc-weekend { background: #f1f2f4; }
  .lc-head .lc-off { font-weight: 500; color: #6b7280; font-size: .72rem; }
  .lc-wo-row { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f1f2f4; }
  .lc-wo-row:last-child { border-bottom: 0; }
  .lc-wo-row .lc-wo-name { flex: 1 1 auto; min-width: 0; }
  .lc-day.lc-holiday { background: #fff7e6; }
  .lc-day.lc-out { background: #fafafa; }
  .lc-day.lc-out > * { opacity: .45; }
  .lc-day.lc-today { box-shadow: inset 0 0 0 2px #b8860b; }
  .lc-top { display: flex; justify-content: space-between; align-items: baseline; gap: 4px; margin-bottom: 4px; }
  .lc-num { font-weight: 600; font-size: .9rem; color: #374151; }
  .lc-count { font-size: .72rem; color: #6b7280; }
  .lc-count.lc-busy { color: #c2410c; font-weight: 700; }
  .lc-hol { display: block; font-size: .72rem; color: #9a6700; font-weight: 600; margin-bottom: 3px; line-height: 1.2; }
  .lc-wo { display: block; font-size: .72rem; color: #4b5563; font-weight: 600; margin-bottom: 3px; line-height: 1.25; }
  .lc-wo .lc-wo-ex { font-weight: 400; color: #6b7280; }
  .lc-chip { display: block; font-size: .76rem; line-height: 1.3; padding: 2px 6px; margin-bottom: 3px; border-radius: 4px; border-left: 4px solid var(--c); background: color-mix(in srgb, var(--c) 12%, #fff); color: #1f2937; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .lc-chip:hover { background: color-mix(in srgb, var(--c) 22%, #fff); color: #111827; }
  .lc-chip .lc-type { color: #6b7280; }
  /* Waiting for approval: amber and dashed, so it is never mistaken for approved leave. */
  .lc-chip.lc-pending { background: #fff4d6; border: 1px dashed #d9a400; border-left: 4px solid #d9a400; color: #7a4f00; white-space: normal; }
  .lc-chip.lc-pending .lc-type { color: #9a6700; font-weight: 600; }
  .lc-waiting { display: inline-flex; align-items: center; gap: 4px; padding: 1px 8px; border-radius: 4px; background: #fff4d6; border: 1px dashed #d9a400; color: #7a4f00; font-weight: 600; font-size: .8rem; }
  .lc-more summary { font-size: .74rem; color: #2563eb; cursor: pointer; list-style: none; }
  .lc-more summary::-webkit-details-marker { display: none; }
  .lc-legend { display: inline-flex; align-items: center; gap: 6px; font-size: .8rem; margin-right: 14px; color: #4b5563; }
  .lc-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
</style>';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Leave Calendar',
    'See who is on leave and when they are back.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Leave Calendar'],
    ],
    '<a class="btn btn-outline-secondary" href="leave_requests.php"><i class="bi bi-arrow-left me-1"></i>Back</a>'
    . ' <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#weeklyOffModal"><i class="bi bi-calendar-x me-1"></i>Weekly off</button>'
    . ' <a class="btn btn-primary" href="leave_requests.php?from=calendar#new-request"><i class="bi bi-plus-lg me-1"></i>Request leave</a>'
);

// Result of a request raised from here (set by leave_requests.php).
$lcFlash = $_SESSION['lc_flash'] ?? null;
unset($_SESSION['lc_flash']);
?>
  <?php if (!isset($_GET['wo']) && is_array($lcFlash) && in_array($lcFlash[0] ?? '', ['success', 'danger'], true)): ?>
    <div class="alert alert-<?= $lcFlash[0] ?>"><?= $h($lcFlash[1] ?? '') ?></div>
  <?php endif; ?>

  <!-- Who is away today -->
  <div class="hr-settings-card mb-3">
    <div class="card-body py-3 px-3">
      <div class="lc-today-strip">
        <strong class="me-1"><i class="bi bi-calendar-check me-1"></i>On leave today, <?= $h($today->format('D j M')) ?>:</strong>
        <?php if (!$todayAway): ?>
          <span class="text-muted">No one is on leave today.</span>
        <?php else: foreach ($todayAway as $l): ?>
          <a class="lc-person" href="employee_view.php?id=<?= (int)$l['employee_id'] ?>#tab-leave"
             title="<?= $h($l['full_name'] . ' (' . $l['employee_code'] . ')' . ($l['company_name'] ? ' · ' . $l['company_name'] : '')) ?>">
            <span class="lc-dot" style="background: <?= $h($colorOf($l['color_hex'])) ?>"></span>
            <span><strong><?= $h($nameOf($l)) ?></strong> (<?= $h($l['type_name'] ?: 'Leave') ?>)</span>
            <?php if ($l['status'] === 'pending'): ?>
              <span class="lc-waiting"><i class="bi bi-hourglass-split"></i>Waiting for approval</span>
            <?php else: ?>
              <span class="text-muted">· back on <?= $h($backOn($l)->format('D j M')) ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="hr-filter-bar mb-3">
    <form class="row g-2 align-items-center" method="get">
      <input type="hidden" name="month" value="<?= $h($monthStart->format('Y-m')) ?>">
      <div class="col-md-auto d-flex align-items-center gap-1">
        <a class="btn btn-outline-secondary" href="<?= $h($link(['month' => $monthStart->modify('-1 month')->format('Y-m')])) ?>" title="Previous month"><i class="bi bi-chevron-left"></i></a>
        <span class="fw-semibold fs-6 px-2" style="min-width:140px;text-align:center"><?= $h($monthName) ?></span>
        <a class="btn btn-outline-secondary" href="<?= $h($link(['month' => $monthStart->modify('+1 month')->format('Y-m')])) ?>" title="Next month"><i class="bi bi-chevron-right"></i></a>
        <?php if (!$isThisMonth): ?>
          <a class="btn btn-outline-secondary ms-1" href="<?= $h($link(['month' => $today->format('Y-m')])) ?>">This month</a>
        <?php endif; ?>
      </div>
      <?php if (count($companies) > 1): ?>
      <div class="col-md-3">
        <select class="form-select" name="company_id" onchange="this.form.submit()" aria-label="Company">
          <option value="0">All companies</option>
          <?php foreach ($companies as $co): ?>
            <option value="<?= (int)$co['id'] ?>" <?= $selectedCompanyId === (int)$co['id'] ? 'selected' : '' ?>><?= $h($co['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-2">
        <select class="form-select" name="type_id" onchange="this.form.submit()" aria-label="Leave type">
          <option value="0">All leave types</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $typeId === (int)$t['id'] ? 'selected' : '' ?>><?= $h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-auto">
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" name="show" value="pending" id="lcPending" <?= $withPending ? 'checked' : '' ?> onchange="this.form.submit()">
          <label class="form-check-label" for="lcPending">Include requests waiting for approval</label>
        </div>
      </div>
    </form>
  </div>

  <!-- Month calendar -->
  <div class="hr-settings-card mb-3">
    <div class="card-body p-0">
      <div class="lc-grid">
        <?php foreach ($dayNames as $dnum => $dn): ?>
          <div class="lc-head"><?= $dn ?><?php if ($dnum === $shownOff): ?> <span class="lc-off">· weekly off</span><?php endif; ?></div>
        <?php endforeach; ?>

        <?php for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')):
          $ymd = $d->format('Y-m-d');
          $dayLeaves = $byDay[$ymd] ?? [];
          $classes = ['lc-day'];
          if ($d < $monthStart || $d > $monthEnd) $classes[] = 'lc-out';
          $woDay = $woStateOn($ymd);
          if ((int)$d->format('N') === $woDay['shown']) $classes[] = 'lc-weekend';
          if (!empty($holidays[$ymd])) $classes[] = 'lc-holiday';
          if ($ymd === $todayYmd) $classes[] = 'lc-today';
        ?>
          <div class="<?= implode(' ', $classes) ?>" data-date="<?= $h($ymd) ?>" title="Double-click to request leave on <?= $h($d->format('D j M')) ?>">
            <div class="lc-top">
              <span class="lc-num"><?= (int)$d->format('j') ?></span>
              <?php if ($dayLeaves): ?>
                <span class="lc-count <?= count($dayLeaves) >= 3 ? 'lc-busy' : '' ?>"><?= count($dayLeaves) ?> on leave</span>
              <?php endif; ?>
            </div>
            <?php foreach ($holidays[$ymd] ?? [] as $hn): ?>
              <span class="lc-hol"><i class="bi bi-flag-fill me-1"></i><?= $h($hn) ?></span>
            <?php endforeach; ?>
            <?php $wl = $woDay['labels'][(int)$d->format('N')]; ?>
            <?php if ($wl['all']): ?>
              <span class="lc-wo" title="<?= $h($wl['except'] ? 'Working this day: ' . implode(', ', $wl['except']) : 'Weekly off for everyone') ?>">Weekly off for all<?= $wl['except'] ? ' <span class="lc-wo-ex">except ' . $h(implode(', ', $wl['except'])) . '</span>' : '' ?></span>
            <?php endif; ?>
            <?php foreach ($wl['names'] as $wn): ?>
              <span class="lc-wo"><?= $h($wn) ?>: weekly off</span>
            <?php endforeach; ?>
            <?php foreach ($dayLeaves as $i => $l):
              if ($i === $maxPerDay): ?>
                <details class="lc-more"><summary>+<?= count($dayLeaves) - $maxPerDay ?> more</summary>
              <?php endif;
              $title = $l['full_name'] . ' (' . $l['employee_code'] . ')'
                  . ($l['company_name'] ? ' · ' . $l['company_name'] : '')
                  . "\n" . ($l['type_name'] ?: 'Leave') . ' · ' . $fmtRange($l['date_from'], $l['date_to']) . ' · ' . $daysText($l['days'])
                  . "\n" . ($l['status'] === 'pending' ? 'Waiting for approval' : 'Back on ' . $backOn($l)->format('D j M'));
            ?>
              <a class="lc-chip <?= $l['status'] === 'pending' ? 'lc-pending' : '' ?>" style="--c: <?= $h($colorOf($l['color_hex'])) ?>"
                 href="employee_view.php?id=<?= (int)$l['employee_id'] ?>#tab-leave" title="<?= $h($title) ?>">
                <?php if ($l['status'] === 'pending'): ?>
                  <i class="bi bi-hourglass-split"></i> <?= $h($nameOf($l)) ?> <span class="lc-type">· waiting for approval</span>
                <?php else: ?>
                  <?= $h($nameOf($l)) ?> <span class="lc-type">· <?= $h($shortType($l['type_name'])) ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
            <?php if (count($dayLeaves) > $maxPerDay): ?></details><?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>
    <div class="px-3 py-2 border-top">
      <?php foreach ($types as $t): ?>
        <span class="lc-legend"><span class="lc-swatch" style="background: <?= $h($colorOf($t['color_hex'])) ?>"></span><?= $h($t['name']) ?></span>
      <?php endforeach; ?>
      <span class="lc-legend"><span class="lc-swatch" style="background:#fff4d6;border:1px dashed #d9a400"></span>Waiting for approval</span>
      <span class="lc-legend"><span class="lc-swatch" style="background:#fff7e6;border:1px solid #f0d9a8"></span>Public holiday</span>
      <span class="lc-legend"><span class="lc-swatch" style="background:#f1f2f4;border:1px solid #e5e7eb"></span>Weekly off<?= $shownOff === null ? ' (differs by company: pick a company)' : '' ?></span>
      <span class="lc-legend float-md-end text-muted"><i class="bi bi-hand-index"></i>Double-click a day to request leave</span>
    </div>
  </div>

  <!-- Weekly off: by company, and per employee (opened from the Weekly off button) -->
  <div class="modal fade" id="weeklyOffModal" tabindex="-1" aria-labelledby="weeklyOffTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="weeklyOffTitle"><i class="bi bi-calendar-x me-1"></i>Weekly off</h5>
          <div class="small text-muted">One day a week. A change applies from today; earlier dates keep the day they had. Attendance and payroll are not changed.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
      <?php if (isset($_GET['wo']) && is_array($lcFlash) && in_array($lcFlash[0] ?? '', ['success', 'danger'], true)): ?>
        <div class="alert alert-<?= $lcFlash[0] ?> m-3 mb-0 py-2"><?= $h($lcFlash[1] ?? '') ?></div>
      <?php endif; ?>
      <form method="post" class="card-body py-2 px-3">
        <div class="fw-semibold small text-uppercase text-muted mb-1">By company</div>
        <?php csrf_field(); ?>
        <input type="hidden" name="save_weekly_off" value="1">
        <?php foreach ($companies as $co): $cid = (int)$co['id']; ?>
          <div class="lc-wo-row">
            <span class="lc-wo-name fw-semibold"><?= $h($co['name']) ?></span>
            <select class="form-select form-select-sm w-auto" name="weekly_off[<?= $cid ?>]" aria-label="Weekly off for <?= $h($co['name']) ?>">
              <?php foreach ($dayNames as $dnum => $dn): ?>
                <option value="<?= $dnum ?>" <?= $companyOff[$cid] === $dnum ? 'selected' : '' ?>><?= $dn ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
        <div class="d-flex align-items-center gap-2 mt-2">
          <button type="submit" class="btn btn-primary btn-sm">Save companies</button>
        </div>
      </form>

      <div class="card-body py-2 px-3 border-top">
        <div class="fw-semibold small text-uppercase text-muted mb-1">By employee <span class="text-lowercase fw-normal">(only when different from their company)</span></div>
        <form method="post" class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <?php csrf_field(); ?>
          <input type="hidden" name="save_employee_weekly_off" value="1">
          <select class="form-select form-select-sm w-auto" name="employee_id" required aria-label="Employee" style="max-width:340px">
            <option value="">Choose employee…</option>
            <?php foreach ($woPickList as $pe): ?>
              <option value="<?= (int)$pe['id'] ?>"><?= $h($pe['full_name'] . ' (' . $pe['employee_code'] . ')') ?> · <?= $h($dayNames[$woOwn[(int)$pe['id']] ?? hr_weekly_off_company($conn, $pe['company_id'] !== null ? (int)$pe['company_id'] : null)]) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="form-select form-select-sm w-auto" name="weekly_off" aria-label="Weekly off day">
            <option value="">Company day</option>
            <?php foreach ($dayNames as $dnum => $dn): ?>
              <option value="<?= $dnum ?>"><?= $dn ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary btn-sm">Save employee</button>
        </form>
        <?php if (!$woEmployees): ?>
          <div class="small text-muted">No employee has their own day<?= $selectedCompanyId > 0 ? ' in this company' : '' ?>; everyone follows their company.</div>
        <?php else: foreach ($woEmployees as $we): $weCo = $we['company_id'] !== null ? (int)$we['company_id'] : null; ?>
          <div class="lc-wo-row">
            <span class="lc-wo-name"><strong><?= $h($we['full_name']) ?></strong> <span class="text-muted small">(<?= $h($we['employee_code']) ?>) · <?= $h($we['company_name'] ?: '—') ?></span></span>
            <span class="fw-semibold"><?= $h($dayNames[$woOwn[(int)$we['id']]]) ?></span>
            <span class="small text-muted">company: <?= $h($dayNames[hr_weekly_off_company($conn, $weCo)]) ?></span>
            <form method="post" class="m-0">
              <?php csrf_field(); ?>
              <input type="hidden" name="save_employee_weekly_off" value="1">
              <input type="hidden" name="employee_id" value="<?= (int)$we['id'] ?>">
              <input type="hidden" name="weekly_off" value="">
              <button type="submit" class="btn btn-outline-secondary btn-sm">Use company day</button>
            </form>
          </div>
        <?php endforeach; endif; ?>
      </div>
      </div>
      </div>
    </div>
  </div>

<?php
// Double-click a day: open the leave request form with that day filled in.
$pageScripts = '<script>
if (new URLSearchParams(location.search).has("wo")) {
  document.addEventListener("DOMContentLoaded", function () {
    bootstrap.Modal.getOrCreateInstance(document.getElementById("weeklyOffModal")).show();
    var url = new URL(location.href);
    url.searchParams.delete("wo");
    history.replaceState(null, "", url.pathname + url.search);
  });
}
document.addEventListener("dblclick", function (e) {
  var day = e.target.closest(".lc-day[data-date]");
  if (!day || e.target.closest("a, summary")) return;
  window.location.href = "leave_requests.php?from=calendar&date=" + encodeURIComponent(day.dataset.date) + "#new-request";
});
</script>';
require_once __DIR__ . '/includes/hr_layout_footer.php';
?>
