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
require_once __DIR__ . '/includes/ops_sources.php';
require_once __DIR__ . '/includes/ops_billing.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$companyId = ops_company_id($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';

// Tenant requests and checkouts into the pool, on the way in — see includes/ops_sources.php.
ops_sync_tenant_requests($conn, [$companyId]);

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

$where = ['(j.company_id = ? OR ' . ops_job_open_to_all_sql('j') . ')'];
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
// PHP's date, not CURDATE(): the live MySQL runs on UTC, so between midnight
// and 4 AM its "today" is still yesterday.
$todayDate = date('Y-m-d');
$lateSql = ops_late_sql();
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'open') AS open_count,
        SUM(status = 'in_progress') AS in_progress_count,
        SUM(status = 'done') AS done_count,
        SUM({$lateSql}) AS overdue_count,
        SUM(scheduled_date = '{$todayDate}' AND status <> 'cancelled') AS today_count,
        SUM(needs_materials = 1 AND status <> 'cancelled') AS materials_count,
        SUM(assigned_to IS NULL AND source_type = 'staff' AND scheduled_date = '{$todayDate}' AND status IN ('open','in_progress')) AS unassigned_today,
        SUM(assigned_to IS NULL AND source_type <> 'staff' AND status = 'open') AS pool_count
    FROM ops_jobs
    WHERE (company_id = ? OR " . ops_job_open_to_all_sql('') . ") AND status <> 'cancelled'
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
    WHERE (j.company_id = ? OR " . ops_job_open_to_all_sql('j') . ") AND j.status <> 'cancelled'
      AND j.scheduled_date >= DATE_SUB('{$todayDate}', INTERVAL 30 DAY)
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
           (SELECT COUNT(*) FROM ops_job_comments c WHERE c.job_id = j.id) AS comment_count,
           (SELECT mo.invoice_id FROM make_order mo WHERE mo.id = j.order_id) AS order_invoice_id
    FROM ops_jobs j
    LEFT JOIN user u  ON u.id  = j.assigned_to
    LEFT JOIN user cu ON cu.id = j.created_by
    WHERE {$whereSql}
    ORDER BY
        (j.status IN ('done','cancelled')),
        CASE
            WHEN j.scheduled_date = '{$todayDate}' THEN 0
            WHEN j.scheduled_date <  '{$todayDate}' THEN 1
            ELSE 2
        END,
        CASE WHEN j.scheduled_date < '{$todayDate}' THEN j.scheduled_date END DESC,
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
  <?php // Jobs are raised on the phone now, by whoever is standing at the site.
        // Scheduling them here in advance is what this replaced: staff move
        // between sites all week, so a roster entered on Monday was wrong by
        // Tuesday and somebody had to come back and fix it. Nothing creates a
        // job on this screen any more — the form is still here for editing one
        // that exists. ?>
  <div class="text-muted small text-md-end">
    <i class="bi bi-phone"></i> Staff create their own jobs in the app.
  </div>
</div>

<?php $poolCount = (int)($stats['pool_count'] ?? 0); ?>
<?php if ($poolCount > 0): ?>
  <!-- Tenant requests waiting in the pool. Not a job for the office to hand
       out — any field phone can claim them — so this says how many, and lets
       someone see which, but offers no Assign button. -->
  <div class="alert alert-info d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-inbox"></i>
    <span>
      <strong><?= $poolCount ?></strong> job<?= $poolCount > 1 ? 's' : '' ?>
      waiting for someone to claim <?= $poolCount > 1 ? 'them' : 'it' ?> in the app.
    </span>
    <a href="<?= h($opsBase) ?>/index.php?assignee=0&amp;status=open"
       class="btn btn-sm btn-outline-dark ms-auto">See them</a>
  </div>
<?php endif; ?>

<?php // Finished jobs are invoiced by the office, in the old Work Orders list —
      // see includes/ops_billing.php. This keeps the pile visible. ?>
<?php $awaitingFinalize = ops_bill_awaiting_finalize_count($conn, [$companyId]); ?>
<?php if ($awaitingFinalize > 0): ?>
  <div class="alert alert-info d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-hourglass-split"></i>
    <span>
      <strong><?= $awaitingFinalize ?></strong> finished job<?= $awaitingFinalize > 1 ? 's are' : ' is' ?>
      waiting for Finalize before <?= $awaitingFinalize > 1 ? 'they are' : 'it is' ?> invoiced.
    </span>
    <a href="<?= h(ops_bill_finalize_list_url($appBase)) ?>" class="btn btn-sm btn-dark ms-auto">Open Work Orders</a>
  </div>
<?php endif; ?>

<?php // Staff jobs only: a pool job with nobody on it is waiting by design. ?>
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
          <?php /* The list is as long as the payroll, so it scrolls inside the
                   card instead of pushing the whole dashboard down. */ ?>
          <div class="ops-byperson">
            <?php foreach ($byPerson as $p): ?>
              <?php
                $pTotal = (int)$p['total'];
                $pDone = (int)$p['done'];
                $pPct = $pTotal > 0 ? (int)round($pDone / $pTotal * 100) : 0;
                $pProg = $pTotal ? (int)round((int)$p['in_progress'] / $pTotal * 100) : 0;
                $pLink = $opsBase . '/index.php?assignee=' . ($p['assigned_to'] === null ? '0' : (int)$p['assigned_to']);
              ?>
              <div class="ops-byperson-row">
                <a href="<?= h($pLink) ?>" class="ops-byperson-name text-decoration-none" title="<?= h($p['person']) ?>"><?= h($p['person']) ?></a>
                <div class="progress ops-byperson-bar">
                  <div class="progress-bar bg-success" style="width:<?= $pPct ?>%"></div>
                  <div class="progress-bar bg-warning" style="width:<?= $pProg ?>%"></div>
                </div>
                <span class="ops-byperson-count text-muted small"><?= $pDone ?>/<?= $pTotal ?></span>
              </div>
            <?php endforeach; ?>
          </div>
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
          <?php foreach (ops_people_by_company($people) as $companyName => $group): ?>
            <optgroup label="<?= h($companyName) ?>">
              <?php foreach ($group as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $fAssignee === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= h($p['fullname'] ?: $p['username']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
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
            No jobs match. Jobs appear here as staff create them in the app.
          </td></tr>
        <?php endif; ?>
        <?php // Every place on this page in one query — see ops_job_places(). ?>
        <?php $placesByJob = ops_job_places($conn, array_column($jobs, 'id')); ?>
        <?php foreach ($jobs as $j): ?>
          <?php $isLate = ops_job_is_late($j); ?>
          <tr>
            <td>
              <a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$j['id'] ?>" class="fw-semibold text-decoration-none">
                <?= h($j['title']) ?>
              </a>
              <div class="small text-muted">
                <span class="badge bg-light text-dark border"><?= h(ops_job_types()[$j['job_type']] ?? $j['job_type']) ?></span>
                <?php if (($j['billing_status'] ?? '') === 'billed' || (int)($j['order_invoice_id'] ?? 0) > 0): ?>
                  <span class="badge bg-success-subtle text-success-emphasis border"><i class="bi bi-receipt"></i> Invoiced</span>
                <?php elseif (($j['billing_status'] ?? '') === 'awaiting_finalize'): ?>
                  <span class="badge bg-info-subtle text-info-emphasis border" title="Work order #<?= (int)$j['order_id'] ?> — Finalize it in Work Orders"><i class="bi bi-hourglass-split"></i> Waiting for Finalize</span>
                <?php elseif (in_array($j['billing_status'] ?? '', ['no_client', 'failed'], true)): ?>
                  <span class="badge bg-warning text-dark" title="<?= h($j['billing_note'] ?? '') ?>"><i class="bi bi-exclamation-triangle"></i> Not invoiced</span>
                <?php endif; ?>
                <?php if (($j['source_type'] ?? 'staff') !== 'staff'): ?>
                  <?php if ($j['source_type'] === 'cleaner_report'): ?>
                    <a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$j['source_id'] ?>"
                       class="badge bg-info text-dark text-decoration-none"
                       title="Open the cleaning job this was found on">
                      <i class="bi bi-brush"></i> Found by cleaner on job #<?= (int)$j['source_id'] ?>
                    </a>
                  <?php elseif ($j['source_type'] === 'ars_checkout'): ?>
                    <span class="badge bg-info text-dark"><i class="bi bi-box-arrow-right"></i> Guest checkout</span>
                  <?php elseif ($j['source_type'] === 'customer_booking'): ?>
                    <span class="badge bg-info text-dark"><i class="bi bi-phone"></i> Customer booking</span>
                  <?php elseif ($j['source_type'] === 'tenant_move_out'): ?>
                    <span class="badge bg-info text-dark"><i class="bi bi-box-arrow-right"></i> Move-out</span>
                  <?php else: ?>
                    <span class="badge bg-info text-dark"><i class="bi bi-person-badge"></i> Request</span>
                  <?php endif; ?>
                  <?php if ($j['assigned_to'] === null && $j['status'] === 'open'): ?>
                    <span class="badge bg-warning text-dark">Waiting to be claimed</span>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($j['priority'] === 'high'): ?>
                  <span class="badge bg-danger">High</span>
                <?php endif; ?>
                <?php foreach ($placesByJob[(int)$j['id']] ?? [] as $place): ?>
                  <span class="badge bg-light text-dark border fw-normal">
                    <i class="bi <?= $place['place_kind'] === 'unit' ? 'bi-door-closed' : 'bi-building' ?>"></i>
                    <?= h($place['label']) ?>
                  </span>
                <?php endforeach; ?>
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
              </div>
              <?php // What the cleaner reported, so the office sees it without opening
                    // the job. The first line only repeats the badge. ?>
              <?php if (($j['source_type'] ?? '') === 'cleaner_report' && trim((string)$j['description']) !== ''): ?>
                <?php $found = array_slice(array_filter(array_map('trim', explode("\n", (string)$j['description']))), 1); ?>
                <?php if ($found): ?>
                  <div class="small mt-1 text-body-secondary">
                    <i class="bi bi-exclamation-circle"></i>
                    <?= h(mb_strimwidth(implode(' · ', array_map(static fn($l) => ltrim($l, '- '), $found)), 0, 160, '…')) ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
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
            <td>
              <span class="badge bg-<?= h(ops_status_color($j['status'])) ?>"><?= h(ops_status_label($j['status'])) ?></span>
              <?php if ($j['status'] === 'in_progress' && !empty($j['paused_at'])): ?>
                <div class="small text-warning-emphasis mt-1">
                  <i class="bi bi-pause-circle"></i> Paused — <?= h(ops_pause_reasons()[$j['pause_reason']] ?? 'Other') ?>
                  <span class="text-muted">since <?= h(date('g:i A', strtotime((string)$j['paused_at']))) ?></span>
                </div>
              <?php endif; ?>
            </td>
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
