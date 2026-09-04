<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$currentCompanyId = current_company_id($conn) ?: 1;
$caseId = !empty($_GET['case_id']) ? (int)$_GET['case_id'] : 0;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $caseId = !empty($_POST['case_id']) ? (int)$_POST['case_id'] : null;
    $eventType = $_POST['event_type'] ?? 'hearing';
    $title = trim($_POST['title'] ?? '');
    $scheduledDate = $_POST['scheduled_date'] ?? '';
    if (!array_key_exists($eventType, legal_hearing_types())) $eventType = 'hearing';
    if ($title === '' || $scheduledDate === '') {
        $error = 'Title and date are required.';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO re_legal_hearings
            (company_id, case_id, event_type, title, description, scheduled_date, scheduled_time, location, court_room,
             counsel_id, assigned_to, status, reminder_days_before, created_by, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?, 'scheduled', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $currentCompanyId, $caseId, $eventType, $title, trim($_POST['description'] ?? '') ?: null,
            $scheduledDate, $_POST['scheduled_time'] ?: null, trim($_POST['location'] ?? '') ?: null,
            trim($_POST['court_room'] ?? '') ?: null,
            !empty($_POST['counsel_id']) ? (int)$_POST['counsel_id'] : null,
            !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null,
            max(0, (int)($_POST['reminder_days_before'] ?? 3)), current_user_id(),
        ]);
        if ($caseId) legal_log_event($conn, $caseId, 'deadline', 'Scheduled: ' . $title . ' on ' . $scheduledDate);
        $_SESSION['success'] = 'Event scheduled.';
        header('Location: legal_calendar.php'); exit;
    }
}

$cases = $conn->prepare("SELECT id, case_number, title FROM re_legal_cases WHERE company_id = ? AND deleted_at IS NULL ORDER BY created_at DESC");
$cases->execute([$currentCompanyId]);
$cases = $cases->fetchAll(PDO::FETCH_ASSOC);
$counselList = legal_fetch_counsel($conn, $currentCompanyId);
$users = legal_fetch_assignable_users($conn, $currentCompanyId);

$pageTitle = 'Schedule Event';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between mb-4">
            <h1><i class="bi bi-calendar-plus"></i> Schedule Hearing / Event</h1>
            <a href="legal_calendar.php" class="btn btn-secondary">Back</a>
        </div>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <form method="POST" class="card"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required></div>
                <div class="col-md-3"><label class="form-label">Type</label>
                    <select name="event_type" class="form-select"><?php foreach (legal_hearing_types() as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-3"><label class="form-label">Linked Case</label>
                    <select name="case_id" class="form-select"><option value="">— None —</option>
                    <?php foreach ($cases as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $caseId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['case_number']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label">Date *</label><input type="date" name="scheduled_date" class="form-control" required value="<?= h(date('Y-m-d')) ?>"></div>
                <div class="col-md-3"><label class="form-label">Time</label><input type="time" name="scheduled_time" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">Location / Court</label><input type="text" name="location" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">Court Room</label><input type="text" name="court_room" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">External Counsel</label>
                    <select name="counsel_id" class="form-select"><option value="">— None —</option>
                    <?php foreach ($counselList as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-4"><label class="form-label">Assigned To</label>
                    <select name="assigned_to" class="form-select"><option value="">— Unassigned —</option>
                    <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-4"><label class="form-label">Remind (days before)</label><input type="number" name="reminder_days_before" class="form-control" value="3" min="0"></div>
                <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
            </div>
            <button type="submit" class="btn btn-primary mt-3">Save Event</button>
        </div></form>
<?php require_once __DIR__ . '/includes/legal_layout_footer.php'; ?>
