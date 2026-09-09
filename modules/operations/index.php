<?php
/**
 * Operations — supervisor home: progress at a glance plus the filtered job list.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$companyId = ops_company_id($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';

// Daily repeating jobs are made here, on the way in, rather than by a cron job
// somebody has to install and nobody would notice had stopped. Whoever opens
// the module first that morning is what puts the day's work on the board — and
// the field app's job list does the same, so a cleaner opening the app before
// the office does still sees their day.
ops_generate_daily_jobs($conn, $companyId);

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$fType = $_GET['type'] ?? '';
$fStatus = $_GET['status'] ?? '';
$fAssignee = isset($_GET['assignee']) && $_GET['assignee'] !== '' ? (int)$_GET['assignee'] : null;
$fFrom = $_GET['from'] ?? '';
$fTo = $_GET['to'] ?? '';
$fSearch = trim((string)($_GET['q'] ?? ''));
// "Waiting for materials" is a state, not a status: the job is still open, but
// somebody on site is stuck without something. It gets its own filter so the
// headline number is a place you can go, not just a number you can read.
$fWaiting = ($_GET['waiting'] ?? '') === '1';

$where = ['j.company_id = ?'];
$params = [$companyId];

if (array_key_exists($fType, ops_job_types())) {
    $where[] = 'j.job_type = ?';
    $params[] = $fType;
}
if ($fStatus === 'active') {
    $where[] = "j.status IN ('open','in_progress')";
} elseif (array_key_exists($fStatus, ops_statuses())) {
    $where[] = 'j.status = ?';
    $params[] = $fStatus;
}
if ($fAssignee !== null) {
    if ($fAssignee === 0) {
        $where[] = 'j.assigned_to IS NULL';
    } else {
        $where[] = 'j.assigned_to = ?';
        $params[] = $fAssignee;
    }
}
if ($fFrom !== '') {
    $where[] = 'j.scheduled_date >= ?';
    $params[] = $fFrom;
}
if ($fTo !== '') {
    $where[] = 'j.scheduled_date <= ?';
    $params[] = $fTo;
}
if ($fSearch !== '') {
    $where[] = '(j.title LIKE ? OR j.location LIKE ?)';
    $params[] = '%' . $fSearch . '%';
    $params[] = '%' . $fSearch . '%';
}
if ($fWaiting) {
    // Same test as the headline tile, so the list and the number agree.
    $where[] = 'j.needs_materials = 1';
    $where[] = "j.status <> 'cancelled'";
}
$whereSql = implode(' AND ', $where);

// ---------------------------------------------------------------------------
// Headline numbers — always for today / overall, not affected by the filters
// ---------------------------------------------------------------------------
$lateSql = ops_late_sql();
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'open') AS open_count,
        SUM(status = 'in_progress') AS in_progress_count,
        SUM(status = 'done') AS done_count,
        SUM({$lateSql}) AS overdue_count,
        SUM(scheduled_date = CURDATE() AND status <> 'cancelled') AS today_count,
        SUM(needs_materials = 1 AND status <> 'cancelled') AS materials_count,
        SUM(assigned_to IS NULL AND scheduled_date = CURDATE() AND status IN ('open','in_progress')) AS unassigned_today
    FROM ops_jobs
    WHERE company_id = ? AND status <> 'cancelled'
");
$statsStmt->execute([$companyId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$total = (int)($stats['total'] ?? 0);
$doneCount = (int)($stats['done_count'] ?? 0);
$donePct = $total > 0 ? (int)round($doneCount / $total * 100) : 0;

// ---------------------------------------------------------------------------
// Per-person progress
// ---------------------------------------------------------------------------
$byPersonStmt = $conn->prepare("
    SELECT
        j.assigned_to,
        COALESCE(NULLIF(u.fullname, ''), u.username, 'Unassigned') AS person,
        COUNT(*) AS total,
        SUM(j.status = 'done') AS done,
        SUM(j.status = 'in_progress') AS in_progress,
        SUM(j.status = 'open') AS open_count
    FROM ops_jobs j
    LEFT JOIN user u ON u.id = j.assigned_to
    WHERE j.company_id = ? AND j.status <> 'cancelled'
      AND j.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY j.assigned_to, person
    ORDER BY total DESC
");
$byPersonStmt->execute([$companyId]);
$byPerson = $byPersonStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ---------------------------------------------------------------------------
// Job list
// ---------------------------------------------------------------------------
$listStmt = $conn->prepare("
    SELECT j.*,
           COALESCE(NULLIF(u.fullname, ''), u.username) AS assignee_name,
           COALESCE(NULLIF(cu.fullname, ''), cu.username) AS creator_name,
           (SELECT COUNT(*) FROM ops_job_photos p WHERE p.job_id = j.id) AS photo_count,
           (SELECT COUNT(*) FROM ops_job_comments c WHERE c.job_id = j.id) AS comment_count
    FROM ops_jobs j
    LEFT JOIN user u  ON u.id  = j.assigned_to
    LEFT JOIN user cu ON cu.id = j.created_by
    WHERE {$whereSql}
    ORDER BY
        (j.status IN ('done','cancelled')),
        CASE
            WHEN j.scheduled_date = CURDATE() THEN 0
            WHEN j.scheduled_date <  CURDATE() THEN 1
            ELSE 2
        END,
        CASE WHEN j.scheduled_date < CURDATE() THEN j.scheduled_date END DESC,
        j.scheduled_date ASC,
        FIELD(j.status, 'in_progress', 'open', 'done', 'cancelled'),
        FIELD(j.priority, 'high', 'normal', 'low')
    LIMIT 300
");
$listStmt->execute($params);
$jobs = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$people = ops_assignable_users($conn, $companyId);

$pageTitle = 'Jobs & progress';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <div class="page-header-label">Jobs &amp; progress</div>
    <div class="text-muted small">Cleaning and maintenance work for <?= h($opsCompanyName ?: 'this company') ?></div>
  </div>
  <a href="<?= h($opsBase) ?>/job_form.php" class="btn btn-lg text-white" style="background:var(--primary)">
    <i class="bi bi-plus-lg"></i> New job
  </a>
</div>

<?php $unassignedToday = (int)($stats['unassigned_today'] ?? 0); ?>
<?php if ($unassignedToday > 0): ?>
  <!-- A job with nobody on it is the one thing a supervisor has to fix each
       morning. Make it one click. -->
  <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-person-exclamation"></i>
    <span>
      <strong><?= $unassignedToday ?></strong> job<?= $unassignedToday > 1 ? 's' : '' ?>
      due today <?= $unassignedToday > 1 ? 'have' : 'has' ?> nobody on <?= $unassignedToday > 1 ? 'them' : 'it' ?> yet.
    </span>
    <a href="<?= h($opsBase) ?>/index.php?assignee=0&amp;from=<?= h(date('Y-m-d')) ?>&amp;to=<?= h(date('Y-m-d')) ?>"
       class="btn btn-sm btn-dark ms-auto">Assign them</a>
  </div>
<?php endif; ?>

<!-- Headline numbers -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-tile">
      <div class="stat-value"><?= (int)($stats['today_count'] ?? 0) ?></div>
      <div class="stat-label">Due today</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-tile">
      <div class="stat-value text-warning"><?= (int)($stats['in_progress_count'] ?? 0) ?></div>
      <div class="stat-label">In progress now</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-tile">
      <div class="stat-value text-danger"><?= (int)($stats['overdue_count'] ?? 0) ?></div>
      <div class="stat-label">Late</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <?php /* The one tile that is a job of work rather than a reading: it links
             to exactly the jobs it counts, so a request that came in from the
             field is one click away from being answered. */ ?>
    <a href="<?= h($opsBase) ?>/index.php?waiting=1"
       class="stat-tile d-block text-decoration-none text-reset">
      <div class="stat-value <?= (int)($stats['materials_count'] ?? 0) > 0 ? 'text-warning' : '' ?>">
        <?= (int)($stats['materials_count'] ?? 0) ?>
      </div>
      <div class="stat-label">Waiting for materials</div>
    </a>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Overall progress -->
  <div class="col-lg-5">
    <div class="card card-round h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-3">Overall progress</h6>
        <div class="d-flex justify-content-between small text-muted mb-1">
          <span><?= $doneCount ?> of <?= $total ?> jobs completed</span>
          <span class="fw-bold"><?= $donePct ?>%</span>
        </div>
        <div class="progress mb-3" style="height:14px;">
          <div class="progress-bar bg-success" style="width:<?= $donePct ?>%"></div>
        </div>
        <div class="d-flex gap-3 small">
          <span><span class="badge bg-secondary">&nbsp;</span> Not started <?= (int)($stats['open_count'] ?? 0) ?></span>
          <span><span class="badge bg-warning">&nbsp;</span> In progress <?= (int)($stats['in_progress_count'] ?? 0) ?></span>
          <span><span class="badge bg-success">&nbsp;</span> Done <?= $doneCount ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Per-person progress -->
  <div class="col-lg-7">
    <div class="card card-round h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-3">By person <span class="text-muted fw-normal small">— last 30 days</span></h6>
        <?php if (!$byPerson): ?>
          <p class="text-muted mb-0">No jobs yet.</p>
        <?php else: ?>
          <?php foreach ($byPerson as $p): ?>
            <?php
              $pTotal = (int)$p['total'];
              $pDone = (int)$p['done'];
              $pPct = $pTotal > 0 ? (int)round($pDone / $pTotal * 100) : 0;
              $pLink = $opsBase . '/index.php?assignee=' . ($p['assigned_to'] === null ? '0' : (int)$p['assigned_to']);
            ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between small mb-1">
                <a href="<?= h($pLink) ?>" class="text-decoration-none fw-semibold"><?= h($p['person']) ?></a>
                <span class="text-muted"><?= $pDone ?>/<?= $pTotal ?> done</span>
              </div>
              <div class="progress" style="height:10px;">
                <div class="progress-bar bg-success" style="width:<?= $pPct ?>%"></div>
                <div class="progress-bar bg-warning" style="width:<?= $pTotal ? (int)round((int)$p['in_progress'] / $pTotal * 100) : 0 ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<?php $opsHasCompanyPicker = count($opsEligibleCompanies) > 1; ?>
<div class="card card-round mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <?php if ($opsHasCompanyPicker): ?>
      <div class="col-6 col-md-2">
        <label class="form-label small text-muted mb-1">Company</label>
        <!-- Nameless, so it never rides along with the filter query string.
             Switching posts through the hidden form below instead. -->
        <select id="opsCompanySelect" class="form-select">
          <?php foreach ($opsEligibleCompanies as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $opsCtxId ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-6 col-md-2">
        <label class="form-label small text-muted mb-1">Type</label>
        <select name="type" class="form-select">
          <option value="">All</option>
          <?php foreach (ops_job_types() as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= $fType === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small text-muted mb-1">Status</label>
        <select name="status" class="form-select">
          <option value="">All</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Still open</option>
          <?php foreach (ops_statuses() as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 <?= $opsHasCompanyPicker ? 'col-md-2' : 'col-md-3' ?>">
        <label class="form-label small text-muted mb-1">Person</label>
        <select name="assignee" class="form-select">
          <option value="">Everyone</option>
          <option value="0" <?= $fAssignee === 0 ? 'selected' : '' ?>>Unassigned</option>
          <?php foreach ($people as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $fAssignee === (int)$p['id'] ? 'selected' : '' ?>>
              <?= h($p['fullname'] ?: $p['username']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small text-muted mb-1">From</label>
        <input type="date" name="from" value="<?= h($fFrom) ?>" class="form-control">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small text-muted mb-1">To</label>
        <input type="date" name="to" value="<?= h($fTo) ?>" class="form-control">
      </div>
      <?php if ($fWaiting): ?>
        <?php /* Keep the filter on while the other boxes are changed. */ ?>
        <input type="hidden" name="waiting" value="1">
      <?php endif; ?>
      <div class="col-12 col-md-11">
        <input type="text" name="q" value="<?= h($fSearch) ?>" class="form-control" placeholder="Search job title or location…">
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button class="btn btn-dark"><i class="bi bi-funnel"></i></button>
      </div>
    </form>

    <?php if ($opsHasCompanyPicker): ?>
      <!-- Switching company is a POST and forms cannot nest, so the picker above
           hands the chosen id to this one. -->
      <form method="post" action="<?= h($appBase) ?>/switch_company.php" id="opsCompanyForm" class="d-none">
        <?php csrf_field(); ?>
        <input type="hidden" name="return" value="<?= h($opsBase) ?>/index.php">
        <input type="hidden" name="company_id" id="opsCompanyId" value="<?= (int)$opsCtxId ?>">
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($fWaiting): ?>
  <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-box-seam"></i>
    <span>Showing only jobs where someone on site has asked for materials.</span>
    <a href="<?= h($opsBase) ?>/index.php" class="btn btn-sm btn-light ms-auto">Show all jobs</a>
  </div>
<?php endif; ?>

<!-- Job list -->
<div class="card card-round">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Job</th>
          <th>Person</th>
          <th>Created by</th>
          <th>Date</th>
          <th>Status</th>
          <th>Time</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$jobs): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            No jobs match. <a href="<?= h($opsBase) ?>/job_form.php">Create one</a>.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($jobs as $j): ?>
          <?php $isLate = ops_job_is_late($j); ?>
          <tr>
            <td>
              <a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$j['id'] ?>" class="fw-semibold text-decoration-none">
                <?= h($j['title']) ?>
              </a>
              <div class="small text-muted">
                <span class="badge bg-light text-dark border"><?= h(ops_job_types()[$j['job_type']] ?? $j['job_type']) ?></span>
                <?php if ($j['priority'] === 'high'): ?>
                  <span class="badge bg-danger">High</span>
                <?php endif; ?>
                <?php if (!empty($j['location'])): ?>
                  <i class="bi bi-geo-alt"></i> <?= h($j['location']) ?>
                <?php endif; ?>
                <?php if ((int)$j['photo_count'] > 0): ?>
                  <i class="bi bi-camera ms-2"></i> <?= (int)$j['photo_count'] ?>
                <?php endif; ?>
                <?php if ((int)$j['comment_count'] > 0): ?>
                  <i class="bi bi-chat-dots ms-2"></i> <?= (int)$j['comment_count'] ?>
                <?php endif; ?>
                <?php if ((int)$j['needs_materials'] === 1): ?>
                  <span class="badge bg-warning text-dark ms-2"><i class="bi bi-box-seam"></i> Needs materials</span>
                <?php endif; ?>
                <?php if (ops_job_in_series($j)): ?>
                  <span class="ms-2" title="Part of a job that repeats every day"><i class="bi bi-arrow-repeat"></i> Daily</span>
                <?php endif; ?>
              </div>
            </td>
            <td><?= h($j['assignee_name'] ?: '—') ?></td>
            <td class="small">
              <?= h($j['creator_name'] ?: '—') ?>
            </td>
            <td class="<?= $isLate ? 'text-danger fw-semibold' : '' ?>">
              <?= h(date('d M', strtotime($j['scheduled_date']))) ?>
              <?php if (!empty($j['scheduled_time'])): ?>
                <div class="small <?= $isLate ? 'text-danger' : 'text-muted' ?>"><?= h(date('g:i A', strtotime($j['scheduled_time']))) ?></div>
              <?php endif; ?>
              <?php /* Colour is never the only signal — the word says it too. */ ?>
              <?php if ($isLate): ?>
                <span class="badge bg-danger mt-1">Late</span>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-<?= h(ops_status_color($j['status'])) ?>"><?= h(ops_status_label($j['status'])) ?></span></td>
            <td class="small text-muted"><?= h(ops_format_duration($j['duration_minutes'] !== null ? (int)$j['duration_minutes'] : null)) ?></td>
            <td class="text-end">
              <a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$j['id'] ?>" class="btn btn-sm btn-outline-secondary">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
