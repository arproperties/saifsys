<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/re_reminder_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$companyId = current_company_id($conn) ?: 1;
$userId = current_user_id() ?: 0;
$isAdmin = re_reminder_admin($conn);
if (
    !$isAdmin
    && !has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_OPERATIONS, $conn)
    && !has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)
) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
$pageTitle = 'Reminders';
$message = '';
$messageType = 'success';
$tablesReady = re_reminder_tables_ready($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesReady) {
    try {
        csrf_verify();
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            re_reminder_create($conn, $companyId, $userId, re_reminder_payload_from_request($_POST));
            $message = 'Reminder created successfully.';
        } elseif ($action === 'update') {
            re_reminder_update($conn, $companyId, (int)($_POST['id'] ?? 0), $userId, $isAdmin, re_reminder_payload_from_request($_POST));
            $message = 'Reminder updated successfully.';
        } elseif ($action === 'delete') {
            re_reminder_delete($conn, $companyId, (int)($_POST['id'] ?? 0), $userId, $isAdmin);
            $message = 'Reminder deleted successfully.';
        } elseif ($action === 'set_status') {
            re_reminder_set_status($conn, $companyId, (int)($_POST['id'] ?? 0), $userId, $isAdmin, $_POST['status'] ?? 'inactive');
            $message = 'Reminder status updated.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$users = $tablesReady ? re_reminder_user_options($conn, $companyId) : [];
$editReminder = null;
if ($tablesReady && !empty($_GET['edit'])) {
    $sql = "SELECT * FROM re_reminders WHERE id = ? AND company_id = ?";
    $args = [(int)$_GET['edit'], $companyId];
    if (!$isAdmin) {
        $sql .= " AND created_by = ?";
        $args[] = $userId;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    $editReminder = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$reminders = [];
$stats = ['active' => 0, 'inactive' => 0, 'completed' => 0, 'due_now' => 0];
$recentLogs = [];
if ($tablesReady) {
    $where = ['r.company_id = ?'];
    $args = [$companyId];
    if (!$isAdmin) {
        $where[] = 'r.created_by = ?';
        $args[] = $userId;
    }
    if ($q !== '') {
        $where[] = '(r.reminder_name LIKE ? OR r.description LIKE ? OR r.module_type LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like);
    }
    if (in_array($statusFilter, ['active', 'inactive', 'completed'], true)) {
        $where[] = 'r.status = ?';
        $args[] = $statusFilter;
    }
    if ($dateFrom !== '') {
        $where[] = 'r.due_date >= ?';
        $args[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'r.due_date <= ?';
        $args[] = $dateTo;
    }
    $stmt = $conn->prepare("
        SELECT r.*, u.fullname AS created_by_name, u.username AS created_by_username
        FROM re_reminders r
        LEFT JOIN user u ON u.id = r.created_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.status = 'active' DESC, r.next_run_at IS NULL, r.next_run_at ASC, r.id DESC
        LIMIT 500
    ");
    $stmt->execute($args);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statWhere = ['company_id = ?'];
    $statArgs = [$companyId];
    if (!$isAdmin) {
        $statWhere[] = 'created_by = ?';
        $statArgs[] = $userId;
    }
    $stmt = $conn->prepare("
        SELECT
            SUM(status = 'active') AS active_count,
            SUM(status = 'inactive') AS inactive_count,
            SUM(status = 'completed') AS completed_count,
            SUM(status = 'active' AND next_run_at IS NOT NULL AND next_run_at <= NOW()) AS due_now_count
        FROM re_reminders
        WHERE " . implode(' AND ', $statWhere)
    );
    $stmt->execute($statArgs);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = [
        'active' => (int)($row['active_count'] ?? 0),
        'inactive' => (int)($row['inactive_count'] ?? 0),
        'completed' => (int)($row['completed_count'] ?? 0),
        'due_now' => (int)($row['due_now_count'] ?? 0),
    ];

    $logWhere = ['l.company_id = ?'];
    $logArgs = [$companyId];
    if (!$isAdmin) {
        $logWhere[] = 'r.created_by = ?';
        $logArgs[] = $userId;
    }
    $stmt = $conn->prepare("
        SELECT l.*, r.reminder_name
        FROM re_reminder_logs l
        JOIN re_reminders r ON r.id = l.reminder_id
        WHERE " . implode(' AND ', $logWhere) . "
        ORDER BY l.created_at DESC
        LIMIT 20
    ");
    $stmt->execute($logArgs);
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function re_reminder_badge_class(string $status): string {
    return match ($status) {
        'active' => 'success',
        'inactive' => 'secondary',
        'completed' => 'primary',
        default => 'secondary',
    };
}

require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="page-header-label mb-1">Reminders</h1>
        <p class="text-muted mb-0">Create scheduled reminders for contracts, payments, invoices, clients, employees, or custom tasks.</p>
    </div>
    <a href="#reminder-form" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Create Reminder</a>
</div>

<?php if (!$tablesReady): ?>
<div class="alert alert-warning">
    Reminder tables are not installed yet. Run <code>migrations/re_reminders.sql</code> on the database first.
</div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; exit; ?>
<?php endif; ?>

<?php if ($message): ?>
<div class="alert alert-<?= h($messageType) ?> alert-dismissible fade show">
    <?= h($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card card-round h-100 border-start border-success border-4"><div class="card-body"><div class="text-muted small text-uppercase">Active</div><div class="h3 mb-0"><?= (int)$stats['active'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100 border-start border-secondary border-4"><div class="card-body"><div class="text-muted small text-uppercase">Inactive</div><div class="h3 mb-0"><?= (int)$stats['inactive'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100 border-start border-primary border-4"><div class="card-body"><div class="text-muted small text-uppercase">Completed / Sent Once</div><div class="h3 mb-0"><?= (int)$stats['completed'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100 border-start border-danger border-4"><div class="card-body"><div class="text-muted small text-uppercase">Due Now</div><div class="h3 mb-0"><?= (int)$stats['due_now'] ?></div></div></div></div>
</div>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Reminder name, notes, module">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'completed' => 'Completed'] as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Due From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Due To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-primary">Filter</button>
            </div>
        </form>
    </div>
</div>

<?php
$formReminder = $editReminder ?: [
    'id' => 0,
    'reminder_name' => '',
    'description' => '',
    'module_type' => 'custom',
    'related_record_id' => '',
    'due_date' => date('Y-m-d'),
    'reminder_days_before' => 1,
    'reminder_time' => '09:00:00',
    'recipients' => '',
    'channel' => 'in_app',
    'repeat_type' => 'none',
    'status' => 'active',
];
$selectedRecipients = re_reminder_decode_recipients($formReminder['recipients'] ?? null);
?>
<div class="card card-round mb-4" id="reminder-form">
    <div class="card-header bg-white">
        <h5 class="mb-0"><?= $editReminder ? 'Edit Reminder' : 'Create Reminder' ?></h5>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="<?= $editReminder ? 'update' : 'create' ?>">
            <?php if ($editReminder): ?><input type="hidden" name="id" value="<?= (int)$editReminder['id'] ?>"><?php endif; ?>

            <div class="col-md-6">
                <label class="form-label">Reminder Name *</label>
                <input type="text" name="reminder_name" class="form-control" required value="<?= h($formReminder['reminder_name']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Related Module/Type</label>
                <select name="module_type" class="form-select">
                    <?php foreach (re_reminder_allowed_module_types() as $type): ?>
                    <option value="<?= h($type) ?>" <?= $formReminder['module_type'] === $type ? 'selected' : '' ?>><?= h(ucfirst($type)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Related Record ID</label>
                <input type="number" name="related_record_id" min="1" class="form-control" value="<?= h($formReminder['related_record_id'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Description / Notes</label>
                <textarea name="description" rows="3" class="form-control"><?= h($formReminder['description'] ?? '') ?></textarea>
            </div>
            <div class="col-md-3">
                <label class="form-label">Due Date *</label>
                <input type="date" name="due_date" class="form-control" required value="<?= h($formReminder['due_date']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Days Before</label>
                <input type="number" name="reminder_days_before" min="0" max="3650" class="form-control" value="<?= h($formReminder['reminder_days_before']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Reminder Time</label>
                <input type="time" name="reminder_time" class="form-control" value="<?= h(substr((string)$formReminder['reminder_time'], 0, 5)) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active" <?= $formReminder['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $formReminder['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <?php if (($formReminder['status'] ?? '') === 'completed'): ?><option value="completed" selected>Completed</option><?php endif; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Recipient User(s)</label>
                <select name="recipient_user_ids[]" class="form-select" multiple size="5">
                    <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $selectedRecipients['user_ids'], true) ? 'selected' : '' ?>>
                        <?= h(($u['fullname'] ?: $u['username']) . (!empty($u['email']) ? ' - ' . $u['email'] : '')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Hold Cmd/Ctrl to select multiple users.</small>
            </div>
            <div class="col-md-4">
                <label class="form-label">Recipient Email(s)</label>
                <textarea name="recipient_emails" rows="5" class="form-control" placeholder="admin@example.com, manager@example.com"><?= h(implode(', ', $selectedRecipients['emails'])) ?></textarea>
                <small class="text-muted">Separate emails with comma, semicolon, space, or new line.</small>
            </div>
            <div class="col-md-2">
                <label class="form-label">Channel</label>
                <select name="channel" class="form-select">
                    <option value="in_app" <?= $formReminder['channel'] === 'in_app' ? 'selected' : '' ?>>In-app</option>
                    <option value="email" <?= $formReminder['channel'] === 'email' ? 'selected' : '' ?>>Email</option>
                    <option value="both" <?= $formReminder['channel'] === 'both' ? 'selected' : '' ?>>Both</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Repeat</label>
                <select name="repeat_type" class="form-select">
                    <?php foreach (re_reminder_allowed_repeats() as $repeat): ?>
                    <option value="<?= h($repeat) ?>" <?= $formReminder['repeat_type'] === $repeat ? 'selected' : '' ?>><?= h(ucfirst($repeat)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $editReminder ? 'Update Reminder' : 'Create Reminder' ?></button>
                <?php if ($editReminder): ?><a href="reminders.php" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Reminder List</h5>
        <span class="text-muted small"><?= count($reminders) ?> shown</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Module</th>
                        <th>Due Date</th>
                        <th>Next Reminder</th>
                        <th>Recipients</th>
                        <th>Status</th>
                        <th>Last Sent</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$reminders): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No reminders found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($reminders as $r): ?>
                    <?php
                    $isDue = $r['status'] === 'active' && !empty($r['next_run_at']) && strtotime($r['next_run_at']) <= time();
                    ?>
                    <tr>
                        <td>
                            <strong><?= h($r['reminder_name']) ?></strong>
                            <?php if (!empty($r['description'])): ?><br><small class="text-muted"><?= h(strlen($r['description']) > 80 ? substr($r['description'], 0, 80) . '...' : $r['description']) ?></small><?php endif; ?>
                            <?php if ($isAdmin && !empty($r['created_by_name'])): ?><br><small class="text-muted">By <?= h($r['created_by_name'] ?: $r['created_by_username']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border"><?= h(ucfirst($r['module_type'])) ?></span>
                            <?php if (!empty($r['related_record_id'])): ?><br><small class="text-muted">Record #<?= (int)$r['related_record_id'] ?></small><?php endif; ?>
                        </td>
                        <td><?= h($r['due_date']) ?><br><small class="text-muted"><?= (int)$r['reminder_days_before'] ?> day(s) before at <?= h(substr((string)$r['reminder_time'], 0, 5)) ?></small></td>
                        <td>
                            <?= $r['next_run_at'] ? h($r['next_run_at']) : '—' ?>
                            <?php if ($isDue): ?><br><span class="badge bg-danger">Overdue / Due Now</span><?php endif; ?>
                        </td>
                        <td><small><?= h(re_reminder_recipient_label($r['recipients'])) ?></small><br><span class="badge bg-info text-dark"><?= h(str_replace('_', '-', $r['channel'])) ?></span></td>
                        <td>
                            <span class="badge bg-<?= h(re_reminder_badge_class($r['status'])) ?>"><?= h(ucfirst($r['status'])) ?></span>
                            <?php if ($r['last_sent_at']): ?><span class="badge bg-primary">Sent</span><?php endif; ?>
                            <?php if ($r['repeat_type'] !== 'none'): ?><br><small class="text-muted">Repeats <?= h($r['repeat_type']) ?></small><?php endif; ?>
                        </td>
                        <td><?= $r['last_sent_at'] ? h($r['last_sent_at']) : '—' ?></td>
                        <td class="text-end">
                            <div class="d-flex justify-content-end gap-1 flex-wrap">
                                <a class="btn btn-sm btn-outline-primary" href="reminders.php?edit=<?= (int)$r['id'] ?>#reminder-form">Edit</a>
                                <form method="post" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $r['status'] === 'active' ? 'inactive' : 'active' ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this reminder?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card card-round">
    <div class="card-header bg-white">
        <h5 class="mb-0">Recent Reminder Delivery Logs</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Date</th><th>Reminder</th><th>Channel</th><th>Recipient</th><th>Status</th><th>Message</th></tr></thead>
                <tbody>
                    <?php if (!$recentLogs): ?><tr><td colspan="6" class="text-center text-muted py-3">No logs yet.</td></tr><?php endif; ?>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td><?= h($log['created_at']) ?></td>
                        <td><?= h($log['reminder_name']) ?></td>
                        <td><?= h($log['channel']) ?></td>
                        <td><?= h($log['recipient'] ?? '—') ?></td>
                        <td><span class="badge bg-<?= $log['status'] === 'sent' ? 'success' : ($log['status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= h($log['status']) ?></span></td>
                        <td><?= h($log['message'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
