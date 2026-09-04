<?php
/**
 * Legal Module - Hearings & Deadlines Calendar
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }
$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int)date('t', $firstDay);
$startDow = (int)date('w', $firstDay);
$monthStart = date('Y-m-01', $firstDay);
$monthEnd = date('Y-m-t', $firstDay);

$eventsByDay = [];

// Hearings
$h = $conn->prepare("
    SELECT h.*, c.case_number FROM re_legal_hearings h
    LEFT JOIN re_legal_cases c ON c.id = h.case_id
    WHERE h.company_id = ? AND h.scheduled_date BETWEEN ? AND ?
    ORDER BY h.scheduled_date, h.scheduled_time
");
$h->execute([$currentCompanyId, $monthStart, $monthEnd]);
foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $d = $row['scheduled_date'];
    $eventsByDay[$d][] = ['type' => 'hearing', 'label' => $row['title'], 'id' => $row['id'], 'case' => $row['case_number']];
}

// Case deadlines
$cd = $conn->prepare("
    SELECT id, case_number, title, deadline_date FROM re_legal_cases
    WHERE company_id = ? AND deleted_at IS NULL AND deadline_date BETWEEN ? AND ?
      AND status NOT IN ('settled','closed','withdrawn')
");
$cd->execute([$currentCompanyId, $monthStart, $monthEnd]);
foreach ($cd->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $d = $row['deadline_date'];
    $eventsByDay[$d][] = ['type' => 'deadline', 'label' => $row['case_number'] . ' deadline', 'id' => $row['id'], 'case' => $row['case_number']];
}

// Notice response deadlines
$nd = $conn->prepare("
    SELECT id, reference_number, subject, response_deadline FROM re_legal_notices
    WHERE company_id = ? AND response_deadline BETWEEN ? AND ?
");
$nd->execute([$currentCompanyId, $monthStart, $monthEnd]);
foreach ($nd->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $d = $row['response_deadline'];
    $eventsByDay[$d][] = ['type' => 'notice', 'label' => $row['reference_number'], 'id' => $row['id'], 'case' => ''];
}

$prevM = $month - 1; $prevY = $year;
$nextM = $month + 1; $nextY = $year;
if ($prevM < 1) { $prevM = 12; $prevY--; }
if ($nextM > 12) { $nextM = 1; $nextY++; }

$pageTitle = 'Hearings Calendar';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-calendar3"></i> Hearings &amp; Deadlines</h1>
            <a href="legal_hearing_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Schedule Event</a>
        </div>

        <div class="card mb-3"><div class="card-body d-flex justify-content-between align-items-center">
            <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
            <h4 class="mb-0"><?= h(date('F Y', $firstDay)) ?></h4>
            <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
        </div></div>

        <div class="card"><div class="card-body">
            <div class="row g-0 text-center fw-bold small border-bottom pb-2 mb-2">
                <div class="col">Sun</div><div class="col">Mon</div><div class="col">Tue</div>
                <div class="col">Wed</div><div class="col">Thu</div><div class="col">Fri</div><div class="col">Sat</div>
            </div>
            <div class="row g-1">
                <?php for ($i = 0; $i < $startDow; $i++): ?><div class="col calendar-day bg-light"></div><?php endfor; ?>
                <?php for ($day = 1; $day <= $daysInMonth; $day++):
                    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $isToday = $dateStr === date('Y-m-d');
                    $evs = $eventsByDay[$dateStr] ?? [];
                ?>
                <div class="col calendar-day <?= $isToday ? 'today' : '' ?>">
                    <strong><?= $day ?></strong>
                    <?php foreach ($evs as $ev): ?>
                        <span class="cal-event <?= h($ev['type']) ?>" title="<?= h($ev['label']) ?>"><?= h(mb_strimwidth($ev['label'], 0, 18, '…')) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endfor; ?>
            </div>
            <div class="mt-3 small text-muted">
                <span class="cal-event hearing d-inline-block">Hearing</span>
                <span class="cal-event deadline d-inline-block">Case deadline</span>
                <span class="cal-event notice d-inline-block">Notice deadline</span>
            </div>
        </div></div>

<?php require_once __DIR__ . '/includes/legal_layout_footer.php'; ?>
