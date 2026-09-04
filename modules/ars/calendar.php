<?php
/**
 * Booking Calendar — Airbnb-style multicalendar.
 *
 * Rows are units, columns are days. Every reservation renders as ONE continuous
 * pill that starts mid-way through the check-in day and ends mid-way through the
 * check-out day, so back-to-back stays interlock the way they do on Airbnb.
 * Free nights show the applicable nightly rate; blocked nights show the hatch.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_availability.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_pricing.php';
require_once __DIR__ . '/includes/ars_early_checkout.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
expirePendingBookings($conn, $arsCompanyId);
ars_early_checkout_ensure_schema($conn);

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1) {
    $month = 12;
    $year--;
}
if ($month > 12) {
    $month = 1;
    $year++;
}

$firstDay = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int)date('t', strtotime($firstDay));
$monthLabel = date('F Y', strtotime($firstDay));
$monthShort = date('M Y', strtotime($firstDay));
$monthEnd = date('Y-m-t', strtotime($firstDay));
$monthEndExclusive = date('Y-m-d', strtotime($monthEnd . ' +1 day'));
$todayStr = date('Y-m-d');

$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$currency = 'AED';
$curStmt = $conn->prepare('SELECT currency FROM ars_company_settings WHERE company_id = ? LIMIT 1');
$curStmt->execute([$arsCompanyId]);
$curVal = $curStmt->fetchColumn();
if ($curVal) {
    $currency = (string)$curVal;
}

$units = ars_fetch_short_term_units($conn, $arsCompanyId);

$unitMaps = [];
$nightlyByUnit = [];
foreach ($units as $u) {
    $uid = (int)$u['id'];
    $unitMaps[$uid] = ars_unit_month_availability($conn, $uid, $year, $month);

    $rates = [];
    $baseRate = (float)($u['nightly_rate'] ?? 0);
    if ($baseRate > 0) {
        foreach (ars_get_nightly_breakdown($conn, $arsCompanyId, $uid, $firstDay, $monthEndExclusive, $baseRate) as $night) {
            $rates[(string)$night['date']] = (float)$night['rate'];
        }
    }
    $nightlyByUnit[$uid] = $rates;
}

$occEndSql = 'COALESCE(b.actual_check_out, b.check_out)';
$bookingsByUnit = [];
$stmt = $conn->prepare("
    SELECT b.id, b.unit_id, b.booking_number, b.check_in, b.check_out, b.actual_check_out, b.is_early_checkout, b.status,
           {$occEndSql} AS occupancy_end,
           g.first_name, g.last_name
    FROM ars_bookings b
    LEFT JOIN ars_guests g ON g.id = b.guest_id
    WHERE b.company_id = ? AND b.status NOT IN ('cancelled','expired')
      AND b.check_in <= ? AND {$occEndSql} >= ?
    ORDER BY b.check_in
");
$stmt->execute([$arsCompanyId, $monthEnd, $firstDay]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bk) {
    $bookingsByUnit[(int)$bk['unit_id']][] = $bk;
}

/** 1-based column index of a date inside the displayed month (may fall outside 1..daysInMonth). */
$columnOf = static function (string $date) use ($firstDay): int {
    $start = new DateTimeImmutable($firstDay);
    $target = new DateTimeImmutable($date);
    return (int)$start->diff($target)->format('%r%a') + 1;
};

/** @return array{class:string,label:string} */
$statusStyle = static function (string $status): array {
    $s = strtolower($status);
    if ($s === 'pending') {
        return ['class' => 'abnb-bar--pending', 'label' => 'Pending'];
    }
    if (in_array($s, ['checked_in', 'checked-in'], true)) {
        return ['class' => 'abnb-bar--inhouse', 'label' => 'Checked in'];
    }
    if (in_array($s, ['checked_out', 'checked-out', 'completed'], true)) {
        return ['class' => 'abnb-bar--done', 'label' => 'Checked out'];
    }
    return ['class' => 'abnb-bar--booked', 'label' => 'Confirmed'];
};

/**
 * Turn a unit's bookings into positioned bars for this month.
 *
 * @param list<array<string,mixed>> $bookings
 * @return list<array<string,mixed>>
 */
$buildBars = static function (array $bookings) use ($columnOf, $statusStyle, $daysInMonth): array {
    $bars = [];
    foreach ($bookings as $bk) {
        $checkIn = (string)($bk['check_in'] ?? '');
        $occEnd = (string)($bk['occupancy_end'] ?? $bk['check_out'] ?? '');
        if ($checkIn === '' || $occEnd === '') {
            continue;
        }

        // Half-day track maths: day d owns tracks (2d-1, 2d), so its midday is
        // grid line 2d. A stay therefore runs from line 2*checkIn to 2*checkOut.
        $rawStart = $columnOf($checkIn);
        $rawEnd = $columnOf($occEnd);
        $openStart = $rawStart < 1;
        $openEnd = $rawEnd > $daysInMonth;
        $lineStart = $openStart ? 1 : 2 * $rawStart;
        $lineEnd = $openEnd ? (2 * $daysInMonth + 1) : 2 * $rawEnd;
        if ($lineEnd <= $lineStart) {
            continue;
        }

        $nights = (int)(new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($occEnd))->format('%a');
        $guest = trim((string)($bk['first_name'] ?? '') . ' ' . (string)($bk['last_name'] ?? ''));
        if ($guest === '') {
            $guest = 'Guest';
        }
        $style = $statusStyle((string)($bk['status'] ?? 'confirmed'));

        $tooltip = $guest . ' · ' . ($bk['booking_number'] ?? '')
            . ' · ' . date('j M', strtotime($checkIn)) . ' → ' . date('j M', strtotime($occEnd))
            . ' · ' . $nights . ' night' . ($nights === 1 ? '' : 's')
            . ' · ' . $style['label']
            . (!empty($bk['is_early_checkout']) ? ' · Early check-out' : '');

        $bars[] = [
            'id' => (int)$bk['id'],
            'start' => $lineStart,
            'end' => $lineEnd,
            'class' => $style['class'],
            'open_start' => $openStart,
            'open_end' => $openEnd,
            'narrow' => ($lineEnd - $lineStart) <= 2,
            'guest' => $guest,
            'nights' => $nights,
            'tooltip' => $tooltip,
        ];
    }
    return $bars;
};

$pageTitle = 'Calendar';
$calNavHtml = '<div class="ars-calendar-nav inline-flex flex-wrap items-center gap-2">'
    . ars_ui_icon_button('chevron-left', 'Previous month', ['href' => '?year=' . (int)$prevYear . '&month=' . (int)$prevMonth, 'variant' => 'outline', 'size' => 'sm'])
    . '<span class="min-w-[9rem] text-center text-ars-base font-bold tracking-tight text-ars-text">' . h($monthLabel) . '</span>'
    . ars_ui_icon_button('chevron-right', 'Next month', ['href' => '?year=' . (int)$nextYear . '&month=' . (int)$nextMonth, 'variant' => 'outline', 'size' => 'sm'])
    . ars_ui_button('Today', ['href' => '?year=' . date('Y') . '&month=' . date('n'), 'variant' => 'primary', 'size' => 'sm', 'icon' => 'calendar'])
    . ars_ui_button('Blocked', ['href' => 'blocked_dates.php', 'variant' => 'danger-outline', 'size' => 'sm', 'icon' => 'calendar-off'])
    . '</div>';

ars_shell_begin([
    'title' => 'Booking Calendar',
    'subtitle' => $monthLabel,
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Calendar'],
    ],
    'actions_html' => $calNavHtml,
    'legacy_bootstrap' => false,
]);

$calCssHref = rtrim(ars_ui_asset_base(), '/') . '/ars_calendar_cells.css?v=' . rawurlencode((string)@filemtime(__DIR__ . '/assets/ars_calendar_cells.css'));
$colsStyle = 'grid-template-columns: repeat(' . $daysInMonth . ', minmax(0, 1fr));';
$halfColsStyle = 'grid-template-columns: repeat(' . (2 * $daysInMonth) . ', minmax(0, 1fr));';
?>
<link rel="stylesheet" href="<?= h($calCssHref) ?>">

<div class="abnb-cal mb-4">
    <div class="abnb-legend">
        <span class="abnb-legend-item"><span class="abnb-swatch abnb-swatch--available"></span> Available</span>
        <span class="abnb-legend-item"><span class="abnb-swatch abnb-swatch--booked"></span> Booked</span>
        <span class="abnb-legend-item"><span class="abnb-swatch abnb-swatch--inhouse"></span> Checked in</span>
        <span class="abnb-legend-item"><span class="abnb-swatch abnb-swatch--pending"></span> Pending</span>
        <span class="abnb-legend-item"><span class="abnb-swatch abnb-swatch--done"></span> Checked out</span>
        <span class="abnb-legend-item"><span class="abnb-swatch abnb-swatch--blocked"></span> Blocked</span>
        <span class="abnb-legend-hint">Bars run from check-in midday to check-out midday · prices in <?= h($currency) ?></span>
    </div>

    <?php if (!$units): ?>
        <div class="abnb-empty"><?= ars_ui_empty_state('No units to show', 'Add short-term units to see occupancy.') ?></div>
    <?php else: ?>
    <div class="abnb-grid">
            <div class="abnb-head">
                <div class="abnb-head-unit"><?= h($monthShort) ?></div>
                <div class="abnb-head-days" style="<?= h($colsStyle) ?>">
                    <?php for ($d = 1; $d <= $daysInMonth; $d++):
                        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $dow = date('D', strtotime($dateStr));
                        $cls = 'abnb-head-day';
                        if (in_array($dow, ['Sat', 'Sun'], true)) {
                            $cls .= ' is-weekend';
                        }
                        if ($dateStr === $todayStr) {
                            $cls .= ' is-today';
                        } elseif ($dateStr < $todayStr) {
                            $cls .= ' is-past';
                        }
                    ?>
                    <div class="<?= $cls ?>">
                        <span class="abnb-dow"><?= substr($dow, 0, 3) ?></span>
                        <span class="abnb-dom"><?= $d ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <?php foreach ($units as $u):
                $uid = (int)$u['id'];
                $bars = $buildBars($bookingsByUnit[$uid] ?? []);
                $rates = $nightlyByUnit[$uid] ?? [];
                $unitNo = trim((string)($u['unit_number'] ?? ''));
                $unitTag = mb_substr(str_replace(' ', '', $unitNo) ?: '—', 0, 4);
                $unitName = trim((string)($u['listing_title'] ?? '')) !== ''
                    ? (string)$u['listing_title']
                    : ($unitNo !== '' ? 'Unit ' . $unitNo : 'Unit');
            ?>
            <div class="abnb-row">
                <div class="abnb-unit">
                    <a href="unit_edit.php?id=<?= $uid ?>" title="<?= h(trim(($u['unit_number'] ?? '') . ' · ' . ($u['building_name'] ?? ''))) ?>">
                        <span class="abnb-unit-thumb" aria-hidden="true"><?= h($unitTag) ?></span>
                        <span class="abnb-unit-text">
                            <span class="abnb-unit-name"><?= h($unitName) ?></span>
                            <span class="abnb-unit-sub"><?= h($u['building_name'] ?? '') ?></span>
                        </span>
                    </a>
                </div>
                <div class="abnb-days" style="<?= h($colsStyle) ?>">
                    <?php if ($bars): ?>
                    <div class="abnb-bars" style="<?= h($halfColsStyle) ?>">
                        <?php foreach ($bars as $bar):
                            $barCls = 'abnb-bar ' . $bar['class']
                                . ($bar['open_start'] ? ' is-open-start' : '')
                                . ($bar['open_end'] ? ' is-open-end' : '')
                                . ($bar['narrow'] ? ' abnb-bar--narrow' : '');
                        ?>
                        <a class="<?= h($barCls) ?>"
                           style="grid-column: <?= (int)$bar['start'] ?> / <?= (int)$bar['end'] ?>;"
                           href="booking_view.php?id=<?= (int)$bar['id'] ?>"
                           title="<?= h($bar['tooltip']) ?>">
                            <span class="abnb-bar-name"><?= h($bar['guest']) ?></span>
                            <span class="abnb-bar-meta"><?= (int)$bar['nights'] ?>n</span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php for ($d = 1; $d <= $daysInMonth; $d++):
                        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $dow = date('D', strtotime($dateStr));
                        $dayAvail = $unitMaps[$uid][$dateStr] ?? 'available';
                        $isBlocked = in_array($dayAvail, ['maintenance', 'blocked'], true);

                        $cls = 'abnb-cell';
                        if (in_array($dow, ['Sat', 'Sun'], true)) {
                            $cls .= ' is-weekend';
                        }
                        if ($dateStr === $todayStr) {
                            $cls .= ' is-today';
                        } elseif ($dateStr < $todayStr) {
                            $cls .= ' is-past';
                        }
                        if ($isBlocked) {
                            $cls .= ' is-blocked';
                        }

                        // Airbnb shows a rate only on nights that are actually sellable.
                        $rate = $rates[$dateStr] ?? null;
                        $priceLabel = ($dayAvail === 'available' && $rate !== null && $rate > 0)
                            ? number_format($rate, 0)
                            : '';
                        $cellTitle = $isBlocked
                            ? 'Blocked · ' . date('j M Y', strtotime($dateStr))
                            : date('j M Y', strtotime($dateStr)) . ($priceLabel !== '' ? ' · ' . $currency . ' ' . $priceLabel : '');
                    ?>
                    <div class="<?= $cls ?>"
                         title="<?= h($cellTitle) ?>"
                         <?php if ($isBlocked): ?>
                             onclick="location.href='blocked_dates.php'"
                         <?php else: ?>
                             onclick="calCellClick(event, <?= $uid ?>, '<?= h($dateStr) ?>')"
                         <?php endif; ?>
                    ><?= h($priceLabel) ?></div>
                    <?php endfor; ?>

                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div id="calCtxMenu" class="ars-cal-ctx" role="menu" aria-hidden="true">
    <a id="ctxBook" href="#" role="menuitem"><?= ars_ui_icon('calendar-plus', ['class' => 'h-4 w-4 text-ars-ink']) ?> New booking</a>
    <a id="ctxBlock" href="#" role="menuitem" class="is-danger"><?= ars_ui_icon('calendar-off', ['class' => 'h-4 w-4']) ?> Block date</a>
</div>

<?php
$pageScripts = <<<'JS'
<script>
const ctxMenu = document.getElementById('calCtxMenu');
let ctxVisible = false;

function calCellClick(e, unitId, dateStr) {
    e.stopPropagation();
    document.getElementById('ctxBook').href = 'booking_add.php?unit_id=' + unitId + '&check_in=' + dateStr;
    document.getElementById('ctxBlock').href = 'blocked_dates.php?prefill_unit=' + unitId + '&prefill_start=' + dateStr;
    ctxMenu.style.left = Math.min(e.clientX, window.innerWidth - 200) + 'px';
    ctxMenu.style.top = Math.min(e.clientY, window.innerHeight - 120) + 'px';
    ctxMenu.classList.add('show');
    ctxMenu.setAttribute('aria-hidden', 'false');
    ctxVisible = true;
}

document.addEventListener('click', function() {
    if (ctxVisible) {
        ctxMenu.classList.remove('show');
        ctxMenu.setAttribute('aria-hidden', 'true');
        ctxVisible = false;
    }
});
</script>
JS;
$GLOBALS['ars_shell_state']['pageScripts'] = $pageScripts;
ars_shell_end();
