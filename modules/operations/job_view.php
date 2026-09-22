<?php
/**
 * Operations — one job: time, before/after photos, comments.
 * The office view. Starting, finishing and uploading files are the field
 * staff's own actions and live only in the app.
 *
 * Materials: someone on site asks for what they need in the conversation below
 * and the office hands it over from the card on this page. There is no separate
 * request to approve — the stock movement, tied to this job, is the whole
 * record.
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
require_once __DIR__ . '/includes/ops_checklist.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$companyId = ops_company_id($conn);
$userId = (int)current_user_id();

$jobId = (int)($_GET['id'] ?? 0);
$job = $jobId > 0 ? ops_load_job($conn, $jobId, $companyId) : null;
if (!$job) {
    ops_flash('That job could not be found.', 'danger');
    header('Location: ' . $opsBase . '/index.php');
    exit;
}

$photosStmt = $conn->prepare("
    SELECT p.*, COALESCE(NULLIF(u.fullname, ''), u.username) AS uploader
    FROM ops_job_photos p
    LEFT JOIN user u ON u.id = p.uploaded_by
    WHERE p.job_id = ? ORDER BY p.created_at ASC
");
$photosStmt->execute([$jobId]);
$photos = ['before' => [], 'after' => []];
foreach ($photosStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $photos[$p['photo_type']][] = $p;
}

$comStmt = $conn->prepare("
    SELECT c.*, COALESCE(NULLIF(u.fullname, ''), u.username) AS author
    FROM ops_job_comments c
    LEFT JOIN user u ON u.id = c.user_id
    WHERE c.job_id = ? ORDER BY c.created_at ASC
");
$comStmt->execute([$jobId]);
$comments = $comStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Photos and voice notes sent from the field app. A message can be nothing but
// its attachment — many of the staff using the app cannot write English — so a
// comment with an empty `comment` is normal, not corrupt.
$commentMedia = [];
if ($comments) {
    $mediaStmt = $conn->prepare("
        SELECT id, comment_id, kind, duration_seconds
        FROM ops_job_comment_media
        WHERE job_id = ? AND company_id = ?
        ORDER BY id ASC
    ");
    $mediaStmt->execute([$jobId, (int)$job['company_id']]);
    foreach ($mediaStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $commentMedia[(int)$row['comment_id']][] = $row;
    }
}

// Stock handed out for this job, and what is on the shelf to hand out.
$usedStmt = $conn->prepare("
    SELECT m.id, m.item_id, m.qty_change, m.note, m.created_at,
           i.name AS item_name, i.unit,
           COALESCE(NULLIF(u.fullname, ''), u.username) AS person
    FROM ops_stock_moves m
    JOIN ops_items i ON i.id = m.item_id
    LEFT JOIN user u ON u.id = m.created_by
    WHERE m.job_id = ? AND m.company_id = ?
    ORDER BY m.created_at ASC
");
$usedStmt->execute([$jobId, $companyId]);
$stockUsed = $usedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
// Photos or a clip taken as the material was handed over — the drum, the meter
// reading, the slip that was signed. One query for the whole table.
$stockMedia = ops_stock_move_media($conn, $companyId, array_column($stockUsed, 'id'));
$stockItems = ops_stock_items($conn, $companyId, true);

// Three tabs, because one page carrying assignment, times, photos, materials
// and the whole conversation is a wall. The tab lives in the URL so a reload,
// a bookmark and every redirect after a POST all land back where you were.
$tabs = ['details' => 'Details', 'material' => 'Material', 'chat' => 'Chat'];
$tab = (string)($_GET['tab'] ?? 'details');
if (!isset($tabs[$tab])) {
    $tab = 'details';
}
$jobUrlFor = static function (string $t) use ($opsBase, $jobId): string {
    return $opsBase . '/job_view.php?id=' . $jobId . '&tab=' . $t;
};

$returnTo = $jobUrlFor('details');
$isOpen = in_array($job['status'], ['open', 'in_progress'], true);

$pageTitle = $job['title'];
$jobIsLate = ops_job_is_late($job);

require __DIR__ . '/includes/ops_layout_header.php';
?>

<a href="<?= h($opsBase) ?>/index.php" class="text-decoration-none small">
  <i class="bi bi-arrow-left"></i> Back
</a>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mt-2 mb-4">
  <div>
    <div class="page-header-label"><?= h($job['title']) ?></div>
    <div class="text-muted">
      <span class="badge bg-light text-dark border"><?= h(ops_job_types()[$job['job_type']] ?? $job['job_type']) ?></span>
      <span class="badge bg-<?= h(ops_status_color($job['status'])) ?>"><?= h(ops_status_label($job['status'])) ?></span>
      <?php // Paused, why, and every earlier pause — so "why did this take all
            // morning" is answered on the page rather than by phoning somebody. ?>
      <?php if ($job['status'] === 'in_progress' && !empty($job['paused_at'])): ?>
        <span class="badge bg-warning text-dark ms-1">
          <i class="bi bi-pause-circle"></i> Paused — <?= h(ops_pause_reasons()[$job['pause_reason']] ?? 'Other') ?>
          since <?= h(date('g:i A', strtotime((string)$job['paused_at']))) ?>
        </span>
      <?php endif; ?>
      <?php
        $pauseHistory = [];
        try {
            $ph = $conn->prepare("SELECT reason, paused_at, resumed_at FROM ops_job_pauses WHERE job_id = ? ORDER BY paused_at ASC");
            $ph->execute([(int)$job['id']]);
            $pauseHistory = $ph->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $pauseHistory = [];
        }
      ?>
      <?php if ($pauseHistory): ?>
        <div class="small text-muted mt-1">
          <i class="bi bi-pause-circle"></i>
          <?php foreach ($pauseHistory as $i => $p): ?>
            <?= $i ? ' · ' : '' ?><?= h(ops_pause_reasons()[$p['reason']] ?? 'Other') ?>
            <?= h(date('g:i A', strtotime((string)$p['paused_at']))) ?>–<?= $p['resumed_at'] ? h(date('g:i A', strtotime((string)$p['resumed_at']))) : 'now' ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <span class="badge bg-<?= h(ops_priority_color($job['priority'])) ?>"><?= h(ops_priorities()[$job['priority']]) ?> priority</span>
      <?php if ($jobIsLate): ?>
        <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Late</span>
      <?php endif; ?>
      <?php // Raised when somebody on site asks for something in the
            // conversation. Handing it over from Materials used, or replying,
            // takes it back down. ?>
      <?php if ((int)($job['needs_materials'] ?? 0) === 1): ?>
        <span class="badge bg-warning text-dark"><i class="bi bi-box-seam"></i> Needs materials</span>
      <?php endif; ?>
      <?php // Where the work actually was. Free text is still shown when a job
            // carries it — every job raised before places existed does, and a
            // newer one may add a note on top of the places it named. ?>
      <?php // A tenant's job links back to the request it came from, which is
            // still the office's record and the tenant's page. ?>
      <?php $sourceLink = ops_source_link($appBase, $job); ?>
      <?php if ($sourceLink): ?>
        <a href="<?= h($sourceLink['url']) ?>" class="badge bg-info text-dark text-decoration-none ms-2">
          <i class="bi bi-person-badge"></i> <?= h($sourceLink['label']) ?>
        </a>
      <?php endif; ?>
      <?php $jobPlaces = ops_job_places($conn, [(int)$job['id']])[(int)$job['id']] ?? []; ?>
      <?php foreach ($jobPlaces as $place): ?>
        <span class="badge bg-light text-dark border ms-2">
          <i class="bi <?= $place['place_kind'] === 'unit' ? 'bi-door-closed' : 'bi-building' ?>"></i>
          <?= h($place['label']) ?>
        </span>
      <?php endforeach; ?>
      <?php if (!empty($job['location'])): ?>
        <span class="ms-2"><i class="bi bi-geo-alt"></i> <?= h($job['location']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= h($opsBase) ?>/job_form.php?id=<?= (int)$job['id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Edit</a>
    <form method="post" action="<?= h($opsBase) ?>/job_action.php" onsubmit="return confirm('Delete this job and everything attached to it?');">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="delete_job">
      <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
      <button class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
    </form>
  </div>
</div>

<?php // Money, once the job has finished: the invoice it produced, or why it
      // produced none. Retry only where retrying can change the answer — a
      // building that now has a client, or an error — never on a job that is
      // not billable by rule. ?>
<?php $bill = ops_bill_summary($conn, $appBase, $job); ?>
<?php if ($bill): ?>
  <div class="alert alert-<?= h($bill['tone']) ?> d-flex flex-wrap align-items-center gap-2 py-2">
    <i class="bi <?= $bill['tone'] === 'success' ? 'bi-receipt' : ($bill['tone'] === 'info' ? 'bi-hourglass-split' : 'bi-info-circle') ?>"></i>
    <span><?= h($bill['text']) ?></span>
    <?php if ($bill['url']): ?>
      <a href="<?= h($bill['url']) ?>" class="btn btn-sm btn-outline-dark ms-auto"><?= $bill['tone'] === 'success' ? 'View invoice' : 'Open Work Orders' ?></a>
    <?php elseif (in_array($job['billing_status'], ['no_client', 'failed'], true)): ?>
      <a href="<?= h($opsBase) ?>/billing.php" class="btn btn-sm btn-outline-dark ms-auto">Billing</a>
      <form method="post" action="<?= h($opsBase) ?>/job_action.php" class="m-0">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="retry_billing">
        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
        <button class="btn btn-sm btn-dark">Retry work order</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php // Bootstrap's own tabs: switching is instant, and the URL is rewritten
      // as you go so a refresh or a copied link opens the same tab. ?>
<ul class="nav ops-tabs mb-3" role="tablist">
  <?php foreach ($tabs as $key => $label): ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $tab === $key ? 'active' : '' ?>" type="button" role="tab"
              id="opsTab-<?= h($key) ?>" data-bs-toggle="tab" data-ops-tab="<?= h($key) ?>"
              data-bs-target="#opsPane-<?= h($key) ?>" aria-controls="opsPane-<?= h($key) ?>"
              aria-selected="<?= $tab === $key ? 'true' : 'false' ?>">
        <?php if ($key === 'material'): ?><i class="bi bi-box-seam"></i>
        <?php elseif ($key === 'chat'): ?><i class="bi bi-chat-dots"></i>
        <?php else: ?><i class="bi bi-card-list"></i><?php endif; ?>
        <?= h($label) ?>
        <?php if ($key === 'material' && $stockUsed): ?>
          <span class="badge bg-light text-dark border ms-1"><?= count($stockUsed) ?></span>
        <?php elseif ($key === 'material' && (int)($job['needs_materials'] ?? 0) === 1): ?>
          <span class="badge bg-warning text-dark ms-1">!</span>
        <?php elseif ($key === 'chat' && $comments): ?>
          <span class="badge bg-light text-dark border ms-1"><?= count($comments) ?></span>
        <?php endif; ?>
      </button>
    </li>
  <?php endforeach; ?>
</ul>

<div class="tab-content">

  <!-- ==================== Details ==================== -->
  <div class="tab-pane fade <?= $tab === 'details' ? 'show active' : '' ?>"
       id="opsPane-details" role="tabpanel" aria-labelledby="opsTab-details">

    <!-- Details and time. The clock is read-only; the field app runs it. -->
    <div class="card card-round mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3">Details</h6>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="stat-label">Assigned to</div>
            <div class="fw-semibold"><?= h($job['assignee_name'] ?: 'Nobody yet') ?></div>
          </div>
          <div class="col-md-4">
            <div class="stat-label">Due</div>
            <div class="fw-semibold <?= $jobIsLate ? 'text-danger' : '' ?>">
              <?= h(date('D d M Y', strtotime($job['scheduled_date']))) ?>
              <?php if (!empty($job['scheduled_time'])): ?>
                at <?= h(date('g:i A', strtotime($job['scheduled_time']))) ?>
              <?php endif; ?>
            </div>
            <?php if ($jobIsLate): ?>
              <div class="small text-danger"><?= h(ops_late_note($job)) ?></div>
            <?php endif; ?>
          </div>
          <div class="col-md-4">
            <div class="stat-label">Created by</div>
            <div class="fw-semibold"><?= h($job['creator_name'] ?: 'Unknown') ?></div>
            <div class="small text-muted"><?= h(date('D d M Y, g:i A', strtotime($job['created_at']))) ?></div>
          </div>
        </div>
        <?php if (!empty($job['description'])): ?>
          <div class="mb-2"><div class="stat-label">What to do</div>
            <div><?= nl2br(h($job['description'])) ?></div></div>
        <?php endif; ?>

        <hr>
        <h6 class="fw-bold mb-3"><i class="bi bi-stopwatch"></i> Time</h6>
        <div class="row g-3 mb-3">
          <div class="col-4">
            <div class="stat-label">Started</div>
            <div class="fw-semibold"><?= $job['started_at'] ? h(date('d M, g:i A', strtotime($job['started_at']))) : '—' ?></div>
          </div>
          <div class="col-4">
            <div class="stat-label">Finished</div>
            <div class="fw-semibold"><?= $job['finished_at'] ? h(date('d M, g:i A', strtotime($job['finished_at']))) : '—' ?></div>
          </div>
          <div class="col-4">
            <div class="stat-label">Total time</div>
            <div class="fw-semibold"><?= h(ops_format_duration($job['duration_minutes'] !== null ? (int)$job['duration_minutes'] : null)) ?></div>
          </div>
        </div>

        <?php // The clock belongs to the person doing the work: starting,
              // finishing and moving the status all happen in the field app,
              // not from the office. ?>
        <?php if ($isOpen): ?>
          <p class="text-muted small mb-0">Started and finished by the staff member in the field app.</p>
        <?php endif; ?>

        <?php if (!empty($job['completion_notes'])): ?>
          <div class="alert alert-light border mt-3 mb-0">
            <div class="small text-muted mb-1">Completion notes</div>
            <?= nl2br(h($job['completion_notes'])) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php // Unit cleaning jobs: the checklist the cleaner answered on Finish. ?>
    <?php if (ops_job_needs_checklist($conn, $job)): ?>
      <?php $checklist = ops_checklist_load($conn, (int)$job['id']); ?>
      <div class="card card-round mb-3">
        <div class="card-body">
          <h6 class="fw-bold mb-3"><i class="bi bi-list-check"></i> Cleaning checklist</h6>
          <?php if (!$checklist): ?>
            <p class="text-muted small mb-0">The cleaner ticks this in the field app. It is saved when the job is finished.</p>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach (ops_cleaning_checklist() as $section): ?>
                <div class="col-md-6">
                  <div class="small text-muted fw-semibold mb-1"><?= h($section['title']) ?></div>
                  <?php foreach ($section['items'] as $key => $label): ?>
                    <?php $state = $checklist['items'][$key] ?? null; ?>
                    <div class="small">
                      <?php if ($state === 'done'): ?>
                        <i class="bi bi-check-circle-fill text-success"></i> <?= h($label) ?>
                      <?php elseif ($state === 'na'): ?>
                        <i class="bi bi-dash-circle text-muted"></i> <span class="text-muted"><?= h($label) ?> (N/A)</span>
                      <?php else: ?>
                        <i class="bi bi-circle text-muted"></i> <span class="text-muted"><?= h($label) ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if ($checklist['problems'] || $checklist['problem_note']): ?>
              <div class="alert alert-warning mt-3 mb-0">
                <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle"></i> Problems found</div>
                <?php foreach ($checklist['problems'] as $key): ?>
                  <div><?= h(ops_cleaning_problems()[$key] ?? $key) ?></div>
                <?php endforeach; ?>
                <?php if ($checklist['problem_note']): ?>
                  <div class="mt-1"><?= nl2br(h($checklist['problem_note'])) ?></div>
                <?php endif; ?>
                <?php if ($checklist['maintenance_job_id']): ?>
                  <a class="d-inline-block mt-2" href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$checklist['maintenance_job_id'] ?>">
                    Maintenance job #<?= (int)$checklist['maintenance_job_id'] ?>
                  </a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php // What the tenant photographed when they raised the request. ?>
    <?php $requestPhotos = ops_request_photos($conn, $job); ?>
    <?php if ($requestPhotos): ?>
    <div class="card card-round mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-person-badge"></i> Tenant's photos</h6>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($requestPhotos as $rp): ?>
            <a href="<?= h($opsBase) ?>/request_photo.php?id=<?= (int)$rp['id'] ?>&amp;job=<?= (int)$job['id'] ?>" target="_blank" rel="noopener">
              <img src="<?= h($opsBase) ?>/request_photo.php?id=<?= (int)$rp['id'] ?>&amp;job=<?= (int)$job['id'] ?>" alt="Tenant photo"
                   style="width:104px;height:104px;object-fit:cover;border-radius:10px;border:1px solid #dee2e6;">
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Photos -->
    <div class="card card-round mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-camera"></i> Before &amp; after photos</h6>
        <?php // Read-only from the office: photos and clips come from the field
              // app so what is on the record is what the staff member actually saw. ?>
        <div class="row g-3">
          <?php foreach (['before' => 'Before', 'after' => 'After'] as $type => $label): ?>
            <div class="col-md-6">
              <div class="fw-semibold small text-uppercase text-muted mb-2"><?= h($label) ?></div>
              <?php if (!$photos[$type]): ?>
                <p class="text-muted small">No <?= h(strtolower($label)) ?> photos or videos yet.</p>
              <?php else: ?>
                <div class="d-flex flex-wrap gap-2 mb-2">
                  <?php foreach ($photos[$type] as $p): ?>
                    <div>
                      <?php $isVideo = ($p['media_kind'] ?? 'photo') === 'video'; ?>
                      <?php if ($isVideo): ?>
                        <?php // Plays in place, muted, with controls. preload="metadata" so a
                              // job with six clips does not pull sixty megabytes to draw a grid. ?>
                        <video src="<?= h($opsBase) ?>/photo.php?id=<?= (int)$p['id'] ?>" controls muted
                               preload="metadata" playsinline
                               style="width:104px;height:104px;object-fit:cover;border-radius:10px;border:1px solid #dee2e6;background:#000;">
                        </video>
                      <?php else: ?>
                        <a href="<?= h($opsBase) ?>/photo.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener">
                          <img src="<?= h($opsBase) ?>/photo.php?id=<?= (int)$p['id'] ?>" alt="<?= h($label) ?> photo"
                               style="width:104px;height:104px;object-fit:cover;border-radius:10px;border:1px solid #dee2e6;">
                        </a>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- ==================== Material ==================== -->
  <?php // Side by side: what has already gone is the record you read first, and
        // the form to hand more over sits beside it — so what was taken is in
        // view while you take the next thing. ?>
  <div class="tab-pane fade <?= $tab === 'material' ? 'show active' : '' ?>"
       id="opsPane-material" role="tabpanel" aria-labelledby="opsTab-material">

    <div class="row g-3">

      <!-- What has already gone out -->
      <div class="col-12 ops-col-65">
        <?php // h-100 matches the card beside it. flex-grow-0 on the heading is
              // what keeps the table under it: a .card-body grows to fill a
              // stretched card, which would push its sibling table to the
              // bottom and leave a gap in the middle. ?>
        <div class="card card-round h-100">
          <div class="card-body pb-2 flex-grow-0">
            <h6 class="fw-bold mb-0"><i class="bi bi-box-seam"></i> Materials used</h6>
          </div>

          <?php if (!$stockUsed): ?>
            <div class="card-body pt-0 flex-grow-0">
              <p class="text-muted small mb-0">
                Nothing taken from stock for this job yet — hand something over on the right
                and it is recorded here.
              </p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table ops-table align-middle">
                <thead>
                  <tr><th>Item</th><th>Taken</th><th>Note</th><th>Files</th><th>By</th><th>When</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($stockUsed as $u): ?>
                    <?php $back = (float)$u['qty_change'] > 0; ?>
                    <tr>
                      <td>
                        <a href="<?= h($opsBase) ?>/item_moves.php?id=<?= (int)$u['item_id'] ?>"
                           class="fw-semibold text-decoration-none"><?= h($u['item_name']) ?></a>
                      </td>
                      <td class="text-nowrap">
                        <span class="ops-pill <?= $back ? 'ops-pill-in' : 'ops-pill-out' ?>">
                          <?= $back ? '+' : '−' ?><?= h(ops_qty(abs((float)$u['qty_change']))) ?><?= $u['unit'] ? ' ' . h($u['unit']) : '' ?>
                        </span>
                        <?php if ($back): ?>
                          <span class="small text-muted">returned</span>
                        <?php endif; ?>
                      </td>
                      <td class="small text-muted"><?= $u['note'] !== null && $u['note'] !== '' ? h($u['note']) : '' ?></td>
                      <?php // Evidence of the handover, opened in the same viewer the
                            // thread's photos use rather than in a new tab. ?>
                      <td>
                        <?php $shots = $stockMedia[(int)$u['id']] ?? []; ?>
                        <?php if (!$shots): ?>
                          <span class="small text-muted">—</span>
                        <?php else: ?>
                          <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($shots as $shot): ?>
                              <?php
                                $shotUrl = $opsBase . '/stock_media.php?id=' . (int)$shot['id'];
                                $shotIsVideo = $shot['kind'] === 'video';
                              ?>
                              <button type="button" class="ops-tile ops-tile-mini"
                                      data-ops-view="<?= h($shotUrl) ?>"
                                      data-ops-kind="<?= $shotIsVideo ? 'video' : 'photo' ?>"
                                      title="<?= $shotIsVideo ? 'Play clip' : 'View photo' ?>">
                                <?php if ($shotIsVideo): ?>
                                  <video src="<?= h($shotUrl) ?>" preload="metadata" muted playsinline></video>
                                  <span class="ops-tile-play"><i class="bi bi-play-circle-fill"></i></span>
                                <?php else: ?>
                                  <img src="<?= h($shotUrl) ?>" alt="Photo of this handover" loading="lazy">
                                <?php endif; ?>
                              </button>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td class="small text-muted"><?= h($u['person'] ?: '—') ?></td>
                      <td class="small text-muted text-nowrap num"><?= h(date('d M, g:i A', strtotime($u['created_at']))) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Hand something over -->
      <div class="col-12 ops-col-35">
        <div class="card card-round h-100">
          <div class="card-body">
            <h6 class="fw-bold mb-3"><i class="bi bi-box-arrow-right"></i> Take from stock</h6>

            <?php // The ask arrives in the Chat tab. This hands the thing over
                  // and writes the movement against the job in one press —
                  // there is no request to raise and nothing to approve. ?>
            <?php if (!$stockItems): ?>
              <p class="text-muted small mb-0">
                No stock items yet. <a href="<?= h($opsBase) ?>/item_form.php">Add one</a> first.
              </p>
            <?php else: ?>
              <form method="post" action="<?= h($opsBase) ?>/job_action.php" class="row g-2 align-items-end"
                    enctype="multipart/form-data" id="opsMatForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="use_stock">
                <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                <input type="hidden" name="return_to" value="<?= h($jobUrlFor('material')) ?>">

                <div class="col-12">
                  <label class="form-label small fw-semibold mb-1">Item</label>
                  <?php // Always searchable: the stock list grows, and a search
                        // box that appears once you pass four items is a box
                        // people never learn is there. ?>
                  <select name="item_id" class="form-select" data-search required>
                    <option value="">Choose an item…</option>
                    <?php foreach ($stockItems as $it): ?>
                      <?php $left = (float)$it['current_qty']; ?>
                      <option value="<?= (int)$it['id'] ?>"<?= $left <= 0 ? ' disabled' : '' ?>>
                        <?= h($it['name']) ?> — <?= h(ops_qty($left)) ?><?= $it['unit'] ? ' ' . h($it['unit']) : '' ?> left<?= $left <= 0 ? ' (none)' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-5">
                  <label class="form-label small fw-semibold mb-1">How much</label>
                  <input type="number" step="0.001" min="0" name="qty" class="form-control" placeholder="1" required>
                </div>
                <div class="col-7">
                  <label class="form-label small fw-semibold mb-1">Note <span class="text-muted fw-normal">(optional)</span></label>
                  <input type="text" name="note" class="form-control" placeholder="Given to Francis">
                </div>
                <?php /* Evidence of the handover, taken at the moment it happens.
                         Four inputs on purpose: the picker, a camera and a video
                         camera — each opens the phone's camera straight into the
                         right mode through `capture`, and on a desktop the
                         script below swaps them for the webcam — and the field
                         that is actually posted, rebuilt from the running list
                         so picking twice adds instead of replacing. */ ?>
                <input type="file" id="opsMatPicker" class="d-none" multiple
                       accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,.jpg,.jpeg,.png,.gif,.webp,.mp4,.mov">
                <input type="file" id="opsMatShot" class="d-none" accept="image/*" capture="environment">
                <input type="file" id="opsMatClip" class="d-none" accept="video/*" capture="environment">
                <input type="file" name="media[]" id="opsMatFiles" class="d-none" multiple>

                <div class="col-12">
                  <label class="form-label small fw-semibold mb-1">
                    Photo or video <span class="text-muted fw-normal">(optional)</span>
                  </label>
                  <div class="ops-strip d-none" id="opsMatStrip"></div>
                  <div class="d-flex flex-wrap gap-2" id="opsMatTools">
                    <button type="button" class="btn btn-light border btn-sm d-none" id="opsMatAttach">
                      <i class="bi bi-paperclip"></i> Attach
                    </button>
                    <button type="button" class="btn btn-light border btn-sm d-none" id="opsMatCamera">
                      <i class="bi bi-camera"></i> Photo
                    </button>
                    <button type="button" class="btn btn-light border btn-sm d-none" id="opsMatVideo">
                      <i class="bi bi-camera-video"></i> Video
                    </button>
                  </div>
                  <div class="small text-danger mt-1 d-none" id="opsMatNotice"></div>
                </div>

                <div class="col-12 d-grid">
                  <button class="btn text-white" id="opsMatSend" style="background:var(--primary)">
                    <i class="bi bi-check-lg"></i> Record
                  </button>
                </div>
              </form>
              <div class="form-text mt-2">Comes off the Stock page straight away and shows there as used on this job.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ==================== Chat ==================== -->
  <div class="tab-pane fade <?= $tab === 'chat' ? 'show active' : '' ?>"
       id="opsPane-chat" role="tabpanel" aria-labelledby="opsTab-chat">


    <!-- Conversation -->
    <?php
      /*
       * The office end of the same conversation the field app shows.
       *
       * It is deliberately the app's screen, not a web form that happens to
       * hold messages: photos and videos in a grid, one on its own shown big,
       * voice notes as a player, and a composer whose attach and microphone
       * buttons sit beside the text box at the same size as it. Someone on site
       * can answer with a photo or by speaking; the office must be able to do
       * the same, or half the conversation only works in one direction.
       *
       * Everything a message carries goes up in ONE request here. The app
       * sends one file per request and ties them together with a group id
       * because it is on 3G in a stairwell — see ops_comment_media_multi.sql.
       * A desk on a wired line has the opposite problem, so it does the simple
       * thing and posts the form.
       */
    ?>
    <style>
      /* The card runs the full width; a line of text should not. Past about
         720px a message becomes a hard thing to read back. */
      .ops-msg { max-width: min(85%, 720px); padding: .5rem .75rem; border-radius: .75rem;
                 background: #f1f3f5; color: #212529; }
      .ops-msg .small { color: #6c757d; }
      .ops-msg-mine { background: #212529; color: #fff; }
      .ops-msg-mine .small, .ops-msg-mine .text-muted { color: rgba(255,255,255,.7) !important; }

      /* Photos and videos that arrived together are looked at together, so they
         share one grid. A single attachment is shown big: it is nearly always
         someone showing a problem, and a thumbnail of a leaking pipe shows
         nobody anything. */
      .ops-media { display: flex; flex-wrap: wrap; gap: .35rem; margin: .25rem 0 .4rem; }
      .ops-tile { position: relative; display: block; border-radius: .5rem; overflow: hidden;
                  background: rgba(0,0,0,.06); border: 0; padding: 0; cursor: zoom-in; }
      .ops-tile img, .ops-tile video { width: 100%; height: 100%; object-fit: cover; display: block; }
      .ops-tile-grid { width: 96px; height: 96px; }
      .ops-tile-solo { width: 220px; max-width: 100%; height: 165px; }
      /* Used in the Material tab, not here: a row in a table has room for a
         stamp, not a photo. This block is one <style> for the whole page, so
         the tile and chip rules above serve both tabs. */
      .ops-tile-mini { width: 44px; height: 44px; }
      .ops-tile-play { position: absolute; inset: 0; display: flex; align-items: center;
                       justify-content: center; color: #fff; font-size: 1.6rem;
                       background: rgba(0,0,0,.25); text-shadow: 0 1px 4px rgba(0,0,0,.6); }
      .ops-tile-len { position: absolute; right: .25rem; bottom: .25rem; font-size: .7rem;
                      color: #fff; background: rgba(0,0,0,.6); border-radius: .25rem; padding: 0 .3rem; }

      /* Composer */
      .ops-composer { display: flex; align-items: flex-end; gap: .5rem; }
      .ops-tool { width: 42px; height: 42px; flex: 0 0 42px; display: inline-flex;
                  align-items: center; justify-content: center; border-radius: .6rem; padding: 0; }
      .ops-composer textarea { resize: none; min-height: 42px; max-height: 140px; border-radius: .6rem; }
      .ops-strip { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .5rem; }
      .ops-chip { position: relative; border-radius: .5rem; overflow: hidden; background: #f1f3f5;
                  border: 1px solid rgba(0,0,0,.12); }
      .ops-chip-media { width: 64px; height: 64px; }
      .ops-chip-media img, .ops-chip-media video { width: 100%; height: 100%; object-fit: cover; display: block; }
      .ops-chip-voice { display: flex; align-items: center; gap: .35rem; height: 64px;
                        padding: 0 1.6rem 0 .6rem; font-size: .8rem; white-space: nowrap; }
      .ops-chip-drop { position: absolute; top: 2px; right: 2px; width: 18px; height: 18px;
                       line-height: 16px; text-align: center; border: 0; border-radius: 50%;
                       background: rgba(0,0,0,.65); color: #fff; font-size: .7rem; padding: 0; }
      .ops-rec-dot { width: .6rem; height: .6rem; border-radius: 50%; background: #dc3545;
                     animation: opsPulse 1.2s ease-in-out infinite; }
      @keyframes opsPulse { 0%,100% { opacity: 1; } 50% { opacity: .25; } }
      @media (prefers-reduced-motion: reduce) { .ops-rec-dot { animation: none; } }

      body.dark-mode .ops-msg { background: rgba(148,163,184,.18); color: #e4e4e7; }
      body.dark-mode .ops-msg-mine { background: var(--primary, #0f172a); color: #fff; }
      body.dark-mode .ops-chip { background: rgba(148,163,184,.18); border-color: #475569; }
    </style>
    <div class="card card-round mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-chat-dots"></i> Messages</h6>
        <?php if (!$comments): ?>
          <p class="text-muted small">No messages yet. Use this to ask a question or leave an update.</p>
        <?php else: ?>
          <?php // The thread scrolls inside the card so a long conversation does
                // not push the page down; it opens at the newest message. ?>
          <div class="mb-3 overflow-auto pe-3" id="opsThread" style="max-height:60vh">
            <?php foreach ($comments as $c): ?>
              <?php // Anything written by the person reading the page sits on the
                    // right, the way a chat reads; everyone else stays on the left. ?>
              <?php
                $mine = (int)$c['user_id'] === $userId;
                $media = $commentMedia[(int)$c['id']] ?? [];
                // A voice note is a player, not a picture, so it never joins the grid.
                $voiceItems = array_values(array_filter($media, fn($m) => $m['kind'] === 'voice'));
                $visualItems = array_values(array_filter($media, fn($m) => $m['kind'] !== 'voice'));
                $tileClass = count($visualItems) === 1 ? 'ops-tile-solo' : 'ops-tile-grid';
              ?>
              <div class="d-flex mb-3 <?= $mine ? 'justify-content-end' : '' ?>">
                <div class="ops-msg <?= $mine ? 'ops-msg-mine' : '' ?>">
                  <div class="small mb-1 <?= $mine ? 'text-end' : '' ?>">
                    <span class="fw-semibold"><?= $mine ? 'You' : h($c['author'] ?: 'Unknown') ?></span>
                    · <?= h(date('d M, g:i A', strtotime($c['created_at']))) ?>
                  </div>

                  <?php foreach ($voiceItems as $media): ?>
                    <?php $mediaUrl = $opsBase . '/comment_media.php?id=' . (int)$media['id']; ?>
                    <div class="my-2">
                      <audio controls preload="none" class="w-100" style="max-width:100%" src="<?= h($mediaUrl) ?>"></audio>
                      <?php if ($media['duration_seconds'] !== null): ?>
                        <div class="small text-muted">Voice message · <?= (int)$media['duration_seconds'] ?>s</div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>

                  <?php if ($visualItems): ?>
                    <div class="ops-media">
                      <?php foreach ($visualItems as $media): ?>
                        <?php
                          $mediaUrl = $opsBase . '/comment_media.php?id=' . (int)$media['id'];
                          $isVideo = $media['kind'] === 'video';
                        ?>
                        <?php /* A tile opens the viewer rather than the raw file:
                                a photo in a new browser tab is a photo on a white
                                page with the page it belonged to gone. Videos use
                                preload="metadata" so a thread with several clips
                                shows first frames without pulling the footage —
                                comment_media.php answers byte ranges, so seeking
                                still works once one is opened. */ ?>
                        <button type="button" class="ops-tile <?= $tileClass ?>"
                                data-ops-view="<?= h($mediaUrl) ?>"
                                data-ops-kind="<?= $isVideo ? 'video' : 'photo' ?>"
                                title="<?= $isVideo ? 'Play video' : 'View photo' ?>">
                          <?php if ($isVideo): ?>
                            <video src="<?= h($mediaUrl) ?>" preload="metadata" muted playsinline></video>
                            <span class="ops-tile-play"><i class="bi bi-play-circle-fill"></i></span>
                          <?php else: ?>
                            <img src="<?= h($mediaUrl) ?>" alt="Photo sent in a message" loading="lazy">
                          <?php endif; ?>
                          <?php if ($isVideo && $media['duration_seconds'] !== null): ?>
                            <span class="ops-tile-len"><?= (int)$media['duration_seconds'] ?>s</span>
                          <?php endif; ?>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                  <?php if (trim((string)$c['comment']) !== ''): ?>
                    <div><?= nl2br(h($c['comment'])) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= h($opsBase) ?>/job_action.php"
              enctype="multipart/form-data" id="opsComposerForm">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="add_comment">
          <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
          <input type="hidden" name="return_to" value="<?= h($jobUrlFor('chat')) ?>">

          <?php /* Two file inputs on purpose. The first is the one the button
                   opens; the second is the one that is actually posted, and it
                   is rebuilt from the running list so picking twice adds to the
                   message instead of replacing what was picked first — and so a
                   recorded voice note, which never came from a picker at all,
                   can sit in the same list. */ ?>
          <input type="file" id="opsPicker" class="d-none" multiple
                 accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,audio/mpeg,audio/mp4,audio/aac,audio/wav,.jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.m4a,.mp3,.aac,.wav">
          <input type="file" name="media[]" id="opsFiles" class="d-none" multiple>
          <div id="opsDurations"></div>

          <div class="ops-strip d-none" id="opsStrip"></div>
          <div class="small text-danger mb-2 d-none" id="opsNotice"></div>

          <div class="ops-composer" id="opsIdle">
            <button type="button" class="btn btn-light border ops-tool d-none" id="opsAttach"
                    title="Attach photos, videos or audio">
              <i class="bi bi-paperclip"></i>
            </button>
            <button type="button" class="btn btn-light border ops-tool d-none" id="opsMic"
                    title="Record a voice message">
              <i class="bi bi-mic"></i>
            </button>
            <textarea name="comment" id="opsInput" class="form-control" rows="1"
                      placeholder="Write a message…"></textarea>
            <button class="btn btn-dark ops-tool" id="opsSend" title="Send">
              <i class="bi bi-send"></i>
            </button>
          </div>

          <?php /* While recording, this takes the composer's place rather than
                   opening a panel somewhere else — the same swap the app makes,
                   so the button just pressed does not move. */ ?>
          <div class="ops-composer d-none align-items-center" id="opsRecording">
            <span class="ops-rec-dot ms-1"></span>
            <span class="small text-danger flex-grow-1">
              Recording… <span id="opsTimer">0:00</span>
            </span>
            <button type="button" class="btn btn-light border ops-tool" id="opsCancelRec" title="Discard">
              <i class="bi bi-trash"></i>
            </button>
            <button type="button" class="btn btn-danger ops-tool" id="opsStopRec" title="Stop recording">
              <i class="bi bi-stop-fill"></i>
            </button>
          </div>

          <?php
            // What this server will really take, not what the module allows. A
            // 50 MB clip is refused by post_max_size long before any of the
            // module's own rules are reached, and finding that out after the
            // upload is the wrong time.
            $opsRequestMb = (int)floor(ops_max_request_bytes() / 1048576);
          ?>
          <div class="form-text mt-2">
            Photos, video and voice notes — up to <?= OPS_COMMENT_MAX_ATTACHMENTS ?> in one message<?php
              if ($opsRequestMb > 0): ?>, <?= $opsRequestMb ?> MB altogether<?php endif; ?>.
          </div>
        </form>
      </div>
    </div>

  </div>

</div><?php // /tab-content ?>

<!-- Full-size viewer for anything in the thread. -->
<div class="modal fade" id="opsViewer" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content bg-dark">
      <div class="modal-header border-0 py-2">
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center pt-0" id="opsViewerBody"></div>
    </div>
  </div>
</div>

<?php /* The office camera. A phone opens its own camera app through the
         `capture` attribute on the inputs above; a desktop has no such thing,
         so the webcam is shown here instead — otherwise "Photo" on a PC would
         open a file dialog, which is the Attach button standing next to it. */ ?>
<div class="modal fade" id="opsShot" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-0 py-2">
        <span class="small" id="opsShotTitle">Take a photo</span>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center pt-0">
        <video id="opsShotView" autoplay muted playsinline
               style="max-width:100%; max-height:60vh; border-radius:.5rem; background:#000;"></video>
        <div class="d-flex justify-content-center align-items-center gap-2 mt-2">
          <button type="button" class="btn btn-light btn-sm d-none" id="opsShotSnap">
            <i class="bi bi-camera"></i> Capture
          </button>
          <button type="button" class="btn btn-danger btn-sm d-none" id="opsShotRec">
            <i class="bi bi-record-circle"></i> Start recording
          </button>
        </div>
        <div class="small text-white-50 mt-2" id="opsShotHint"></div>
      </div>
    </div>
  </div>
</div>

<script>
// Everything below runs on DOMContentLoaded, not inline: the Bootstrap bundle
// is loaded by the layout footer further down the page, so `bootstrap` does not
// exist yet at this point in the parse and the viewer would silently never open.
document.addEventListener('DOMContentLoaded', function () {

  // The thread opens at the newest message. A hidden tab has no height to
  // scroll, so it is done again the moment Chat is actually shown.
  function opsScrollThread() {
    var thread = document.getElementById('opsThread');
    if (thread) { thread.scrollTop = thread.scrollHeight; }
  }
  opsScrollThread();

  // Keep the open tab in the URL — without this a refresh, or the redirect
  // after any action, drops you back on Details.
  document.querySelectorAll('[data-ops-tab]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function () {
      var name = btn.getAttribute('data-ops-tab');
      if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        window.history.replaceState({}, '', url);
      }
      if (name === 'chat') { opsScrollThread(); }
    });
  });

  // -------------------------------------------------------------------------
  // Viewer: a tile opens here rather than navigating away from the job.
  // -------------------------------------------------------------------------
  (function () {
    var el = document.getElementById('opsViewer');
    var body = document.getElementById('opsViewerBody');
    if (!el || !body || typeof bootstrap === 'undefined') { return; }
    var modal = new bootstrap.Modal(el);

    document.querySelectorAll('[data-ops-view]').forEach(function (tile) {
      tile.addEventListener('click', function () {
        var url = tile.getAttribute('data-ops-view');
        var kind = tile.getAttribute('data-ops-kind');
        body.innerHTML = '';
        var node;
        if (kind === 'video') {
          node = document.createElement('video');
          node.controls = true;
          node.autoplay = true;
          node.playsInline = true;
        } else {
          node = document.createElement('img');
          node.alt = 'Attachment';
        }
        node.src = url;
        node.style.maxWidth = '100%';
        node.style.maxHeight = '78vh';
        body.appendChild(node);
        modal.show();
      });
    });

    // Stop the sound the moment the box is closed.
    el.addEventListener('hidden.bs.modal', function () { body.innerHTML = ''; });
  })();

  // -------------------------------------------------------------------------
  // Material: photos and video of the handover.
  //
  // The same staging idea as the composer below — a running list rebuilt into
  // the field that is actually posted — but no voice, a smaller ceiling, and a
  // camera that has to work on a desk as well as in a corridor.
  // -------------------------------------------------------------------------
  (function () {
    var form = document.getElementById('opsMatForm');
    if (!form) { return; }

    var picker = document.getElementById('opsMatPicker');
    var shotInput = document.getElementById('opsMatShot');
    var clipInput = document.getElementById('opsMatClip');
    var files = document.getElementById('opsMatFiles');
    var strip = document.getElementById('opsMatStrip');
    var notice = document.getElementById('opsMatNotice');
    var attachBtn = document.getElementById('opsMatAttach');
    var cameraBtn = document.getElementById('opsMatCamera');
    var videoBtn = document.getElementById('opsMatVideo');
    var send = document.getElementById('opsMatSend');

    // The numbers the server enforces (OPS_STOCK_MOVE_MAX_ATTACHMENTS and
    // ops_comment_media_max_bytes), checked here only so nobody waits out the
    // upload of a file that was always going to be refused.
    var MAX_FILES = <?= OPS_STOCK_MOVE_MAX_ATTACHMENTS ?>;
    var MAX_MB = { photo: 10, video: 50 };
    var KIND = {
      jpg: 'photo', jpeg: 'photo', png: 'photo', gif: 'photo', webp: 'photo',
      mp4: 'video', mov: 'video'
    };
    /** A minute of footage is plenty to show a handover. */
    var MAX_RECORD_MS = 60000;

    var pending = [];

    // Building a FileList by hand is the only way to post a snapshot taken in
    // the page alongside a picked file. Without it there is nothing to attach
    // to, so the buttons stay hidden and the card remains what it was.
    var canStage = typeof DataTransfer !== 'undefined' && typeof File !== 'undefined';
    if (!canStage) { return; }
    attachBtn.classList.remove('d-none');

    function say(message) {
      notice.textContent = message || '';
      notice.classList.toggle('d-none', !message);
    }

    function extensionOf(name) {
      var dot = name.lastIndexOf('.');
      return dot < 0 ? '' : name.slice(dot + 1).toLowerCase();
    }

    function stamp() {
      return new Date().toISOString().replace(/[-:T]/g, '').slice(0, 15);
    }

    /** Push the staged list into the field that is actually posted. */
    function sync() {
      var box = new DataTransfer();
      pending.forEach(function (item) { box.items.add(item.file); });
      files.files = box.files;
      draw();
    }

    function draw() {
      strip.innerHTML = '';
      strip.classList.toggle('d-none', pending.length === 0);

      pending.forEach(function (item, index) {
        var chip = document.createElement('div');
        chip.className = 'ops-chip ops-chip-media';

        if (item.kind === 'video') {
          var clip = document.createElement('video');
          clip.src = item.url;
          clip.preload = 'metadata';
          clip.muted = true;
          chip.appendChild(clip);
        } else {
          var thumb = document.createElement('img');
          thumb.src = item.url;
          thumb.alt = item.file.name;
          chip.appendChild(thumb);
        }

        var drop = document.createElement('button');
        drop.type = 'button';
        drop.className = 'ops-chip-drop';
        drop.innerHTML = '&times;';
        drop.title = 'Remove';
        drop.addEventListener('click', function () {
          URL.revokeObjectURL(pending[index].url);
          pending.splice(index, 1);
          say('');
          sync();
        });
        chip.appendChild(drop);

        strip.appendChild(chip);
      });
    }

    /** @return {boolean} whether it was staged. */
    function stage(file) {
      var kind = KIND[extensionOf(file.name)];
      if (!kind) {
        say(file.name + ' cannot be attached here. Photos and MP4 or MOV video only.');
        return false;
      }
      if (pending.length >= MAX_FILES) {
        say('A movement can carry ' + MAX_FILES + ' files at most.');
        return false;
      }
      if (file.size > MAX_MB[kind] * 1048576) {
        say(file.name + ' is larger than ' + MAX_MB[kind] + ' MB.');
        return false;
      }
      pending.push({ file: file, kind: kind, url: URL.createObjectURL(file) });
      return true;
    }

    /** Everything a picker or a camera hands back arrives through here. */
    function take(list) {
      say('');
      Array.prototype.forEach.call(list || [], function (file) { stage(file); });
      sync();
    }

    attachBtn.addEventListener('click', function () { picker.click(); });
    [picker, shotInput, clipInput].forEach(function (input) {
      input.addEventListener('change', function () {
        take(input.files);
        // Cleared so choosing the same file again still counts as a change.
        input.value = '';
      });
    });

    form.addEventListener('submit', function () {
      // A clip takes a moment to leave, and pressing Record twice would take
      // the material off the shelf twice.
      send.disabled = true;
      send.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    });

    // -----------------------------------------------------------------------
    // Camera
    // -----------------------------------------------------------------------

    // A phone opens its own camera app straight into the right mode, which is
    // better than anything this page could show. A desk has no such thing, so
    // the webcam is opened in the modal instead — and only where the browser
    // will record MP4, because MOV and MP4 are the only video the module
    // accepts and Chrome's other option is WebM, which iPhones cannot play.
    var onPhone = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    var shotEl = document.getElementById('opsShot');
    var canWebcam = !onPhone && shotEl && typeof bootstrap !== 'undefined'
      && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;

    var mp4Type = '';
    if (canWebcam && typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported) {
      ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4;codecs=avc1', 'video/mp4']
        .forEach(function (type) {
          if (!mp4Type && MediaRecorder.isTypeSupported(type)) { mp4Type = type; }
        });
    }

    if (onPhone || canWebcam) { cameraBtn.classList.remove('d-none'); }
    if (onPhone || (canWebcam && mp4Type)) { videoBtn.classList.remove('d-none'); }

    if (onPhone) {
      cameraBtn.addEventListener('click', function () { shotInput.click(); });
      videoBtn.addEventListener('click', function () { clipInput.click(); });
      return;
    }
    if (!canWebcam) { return; }

    var modal = new bootstrap.Modal(shotEl);
    var view = document.getElementById('opsShotView');
    var title = document.getElementById('opsShotTitle');
    var hint = document.getElementById('opsShotHint');
    var snapBtn = document.getElementById('opsShotSnap');
    var recBtn = document.getElementById('opsShotRec');

    var stream = null, recorder = null, chunks = [], stopTimer = null, ticking = null;
    // Set while the box is being closed: a recording abandoned by closing the
    // window is a recording nobody wants attached.
    var discarding = false;

    function shutCamera() {
      discarding = true;
      if (recorder && recorder.state === 'recording') { recorder.stop(); }
      if (stopTimer) { clearTimeout(stopTimer); stopTimer = null; }
      if (ticking) { clearInterval(ticking); ticking = null; }
      if (stream) {
        stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
      }
      view.srcObject = null;
    }

    function openCamera(mode) {
      say('');
      title.textContent = mode === 'video' ? 'Record a clip' : 'Take a photo';
      hint.textContent = '';
      snapBtn.classList.toggle('d-none', mode === 'video');
      recBtn.classList.toggle('d-none', mode !== 'video');
      recBtn.innerHTML = '<i class="bi bi-record-circle"></i> Start recording';
      recBtn.classList.remove('btn-light');
      recBtn.classList.add('btn-danger');

      navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment' },
        audio: mode === 'video'
      }).then(function (opened) {
        stream = opened;
        view.srcObject = opened;
        modal.show();
      }).catch(function () {
        // Refused, already in use, or plain HTTP — where getUserMedia does not
        // exist at all. Attaching a file still works, so say that.
        say('The camera could not be opened. Attach a file instead.');
      });
    }

    cameraBtn.addEventListener('click', function () { openCamera('photo'); });
    videoBtn.addEventListener('click', function () { openCamera('video'); });

    snapBtn.addEventListener('click', function () {
      var canvas = document.createElement('canvas');
      canvas.width = view.videoWidth || 1280;
      canvas.height = view.videoHeight || 720;
      canvas.getContext('2d').drawImage(view, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(function (blob) {
        if (blob) {
          take([new File([blob], 'photo_' + stamp() + '.jpg', { type: 'image/jpeg' })]);
        }
        modal.hide();
      }, 'image/jpeg', 0.9);
    });

    recBtn.addEventListener('click', function () {
      if (recorder && recorder.state === 'recording') {
        recorder.stop();
        return;
      }
      chunks = [];
      discarding = false;
      try {
        recorder = new MediaRecorder(stream, { mimeType: mp4Type });
      } catch (e) {
        say('This browser cannot record video. Attach a file instead.');
        modal.hide();
        return;
      }
      recorder.addEventListener('dataavailable', function (event) {
        if (event.data && event.data.size) { chunks.push(event.data); }
      });
      recorder.addEventListener('stop', function () {
        if (stopTimer) { clearTimeout(stopTimer); stopTimer = null; }
        if (ticking) { clearInterval(ticking); ticking = null; }
        if (chunks.length && !discarding) {
          take([new File([new Blob(chunks, { type: 'video/mp4' })],
                         'video_' + stamp() + '.mp4', { type: 'video/mp4' })]);
        }
        chunks = [];
        modal.hide();
      });
      recorder.start();

      var startedAt = Date.now();
      recBtn.innerHTML = '<i class="bi bi-stop-fill"></i> Stop';
      recBtn.classList.remove('btn-danger');
      recBtn.classList.add('btn-light');
      hint.textContent = 'Recording… 0:00';
      ticking = setInterval(function () {
        var seconds = Math.floor((Date.now() - startedAt) / 1000);
        hint.textContent = 'Recording… ' + Math.floor(seconds / 60) + ':' +
          (seconds % 60 < 10 ? '0' : '') + (seconds % 60);
      }, 500);
      // Stopped for them at a minute rather than left running into a file too
      // big for the server to take.
      stopTimer = setTimeout(function () {
        if (recorder && recorder.state === 'recording') { recorder.stop(); }
      }, MAX_RECORD_MS);
    });

    // Closing the box is closing the camera — the light must not stay on.
    shotEl.addEventListener('hidden.bs.modal', shutCamera);
  })();

  // -------------------------------------------------------------------------
  // Composer: attachments and voice.
  // -------------------------------------------------------------------------
  (function () {
    var form = document.getElementById('opsComposerForm');
    if (!form) { return; }

    var picker = document.getElementById('opsPicker');
    var files = document.getElementById('opsFiles');
    var durations = document.getElementById('opsDurations');
    var strip = document.getElementById('opsStrip');
    var notice = document.getElementById('opsNotice');
    var input = document.getElementById('opsInput');
    var send = document.getElementById('opsSend');
    var attachBtn = document.getElementById('opsAttach');
    var micBtn = document.getElementById('opsMic');
    var idle = document.getElementById('opsIdle');
    var recBar = document.getElementById('opsRecording');
    var timer = document.getElementById('opsTimer');

    // Same numbers the server enforces (ops_comment_media_max_bytes and
    // OPS_COMMENT_MAX_ATTACHMENTS). Checked here only so someone is told before
    // waiting out the upload of a file that was always going to be refused.
    var MAX_FILES = 10;
    var MAX_MB = { photo: 10, voice: 10, video: 50 };
    var KIND = {
      jpg: 'photo', jpeg: 'photo', png: 'photo', gif: 'photo', webp: 'photo',
      mp4: 'video', mov: 'video',
      m4a: 'voice', aac: 'voice', mp3: 'voice', wav: 'voice'
    };
    /** Two minutes, as in the app. Past that the office should get a phone call. */
    var MAX_RECORD_MS = 120000;

    /** Everything staged for the next message, in the order it will be sent. */
    var pending = [];

    // Building a FileList by hand is the only way to post a recording and a
    // picked file in one field. Without it there is nothing to attach to, so
    // the buttons stay hidden and the box remains what it was: text only.
    var canStage = typeof DataTransfer !== 'undefined' && typeof File !== 'undefined';
    if (canStage) { attachBtn.classList.remove('d-none'); }

    function say(message) {
      notice.textContent = message || '';
      notice.classList.toggle('d-none', !message);
    }

    function extensionOf(name) {
      var dot = name.lastIndexOf('.');
      return dot < 0 ? '' : name.slice(dot + 1).toLowerCase();
    }

    function clock(seconds) {
      var m = Math.floor(seconds / 60);
      var s = seconds % 60;
      return m + ':' + (s < 10 ? '0' : '') + s;
    }

    /** Push the staged list into the field that is actually posted. */
    function sync() {
      var box = new DataTransfer();
      pending.forEach(function (item) { box.items.add(item.file); });
      files.files = box.files;

      // One hidden field per attachment, in the same order, so the length of a
      // voice note lands against the right file on the server.
      durations.innerHTML = '';
      pending.forEach(function (item) {
        var field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'media_duration[]';
        field.value = item.seconds ? String(item.seconds) : '';
        durations.appendChild(field);
      });

      draw();
    }

    function draw() {
      strip.innerHTML = '';
      strip.classList.toggle('d-none', pending.length === 0);

      pending.forEach(function (item, index) {
        var chip = document.createElement('div');
        chip.className = 'ops-chip ' + (item.kind === 'voice' ? 'ops-chip-voice' : 'ops-chip-media');

        if (item.kind === 'voice') {
          chip.innerHTML = '<i class="bi bi-mic-fill text-danger"></i><span></span>';
          chip.querySelector('span').textContent =
            item.seconds ? 'Voice ' + clock(item.seconds) : 'Voice message';
        } else if (item.kind === 'video') {
          var clip = document.createElement('video');
          clip.src = item.url;
          clip.preload = 'metadata';
          clip.muted = true;
          chip.appendChild(clip);
        } else {
          var thumb = document.createElement('img');
          thumb.src = item.url;
          thumb.alt = item.file.name;
          chip.appendChild(thumb);
        }

        var drop = document.createElement('button');
        drop.type = 'button';
        drop.className = 'ops-chip-drop';
        drop.innerHTML = '&times;';
        drop.title = 'Remove';
        drop.addEventListener('click', function () {
          if (pending[index].url) { URL.revokeObjectURL(pending[index].url); }
          pending.splice(index, 1);
          say('');
          sync();
        });
        chip.appendChild(drop);

        strip.appendChild(chip);
      });

      refreshSend();
    }

    function refreshSend() {
      send.disabled = pending.length === 0 && input.value.trim() === '';
    }

    /** @return {boolean} whether it was staged. */
    function stage(file, seconds) {
      var kind = KIND[extensionOf(file.name)];
      if (!kind) {
        say(file.name + ' cannot be sent here. Photos, MP4/MOV video and MP3/M4A/WAV audio only.');
        return false;
      }
      if (pending.length >= MAX_FILES) {
        say('A message can carry ' + MAX_FILES + ' attachments at most.');
        return false;
      }
      if (file.size > MAX_MB[kind] * 1048576) {
        say(file.name + ' is larger than ' + MAX_MB[kind] + ' MB.');
        return false;
      }
      pending.push({
        file: file,
        kind: kind,
        seconds: seconds || null,
        url: kind === 'voice' ? null : URL.createObjectURL(file)
      });
      return true;
    }

    attachBtn.addEventListener('click', function () { picker.click(); });

    picker.addEventListener('change', function () {
      say('');
      Array.prototype.forEach.call(picker.files || [], function (file) { stage(file); });
      // Cleared so choosing the same file again still counts as a change.
      picker.value = '';
      sync();
    });

    input.addEventListener('input', function () {
      refreshSend();
      // Grows with the message and stops at the height the CSS caps it to.
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 140) + 'px';
    });

    // Enter sends, Shift+Enter starts a new line — what the box looks like it
    // should do, and what every chat does.
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        if (!send.disabled) { form.requestSubmit ? form.requestSubmit(send) : form.submit(); }
      }
    });

    form.addEventListener('submit', function () {
      // A message carrying a video takes a moment to leave. Say so, and make it
      // impossible to send it twice by clicking again.
      send.disabled = true;
      send.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    });

    // -----------------------------------------------------------------------
    // Voice
    // -----------------------------------------------------------------------

    // getUserMedia exists only in a secure context, so on plain HTTP the
    // microphone button is simply never shown rather than shown and broken.
    var canRecord = canStage
      && navigator.mediaDevices && navigator.mediaDevices.getUserMedia
      && typeof MediaRecorder !== 'undefined'
      && (window.AudioContext || window.webkitAudioContext);
    if (canRecord) { micBtn.classList.remove('d-none'); }

    var recorder = null, stream = null, chunks = [], startedAt = 0;
    var ticking = null, stopTimer = null, discarding = false;

    /**
     * Safari records straight to M4A, which every browser and both phones play,
     * so it is kept as it is. Chrome and Firefox only offer WebM/Opus — and a
     * WebM voice note is silent on an iPhone, which is half the field staff. So
     * anything that is not already M4A is decoded and re-written as WAV below.
     */
    function bestMimeType() {
      var wanted = ['audio/mp4', 'audio/mp4;codecs=mp4a.40.2', 'audio/webm;codecs=opus', 'audio/webm'];
      for (var i = 0; i < wanted.length; i++) {
        if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(wanted[i])) {
          return wanted[i];
        }
      }
      return '';
    }

    function showRecording(on) {
      idle.classList.toggle('d-none', on);
      recBar.classList.toggle('d-none', !on);
    }

    function releaseMic() {
      if (stream) {
        stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
      }
      clearInterval(ticking);
      clearTimeout(stopTimer);
      ticking = null;
      stopTimer = null;
    }

    micBtn.addEventListener('click', function () {
      say('');
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (mic) {
        stream = mic;
        chunks = [];
        discarding = false;
        var mime = bestMimeType();
        recorder = mime ? new MediaRecorder(mic, { mimeType: mime }) : new MediaRecorder(mic);
        recorder.ondataavailable = function (event) {
          if (event.data && event.data.size) { chunks.push(event.data); }
        };
        recorder.onstop = finish;
        recorder.start();

        startedAt = Date.now();
        timer.textContent = '0:00';
        showRecording(true);
        ticking = setInterval(function () {
          timer.textContent = clock(Math.floor((Date.now() - startedAt) / 1000));
        }, 250);
        // Stops itself rather than letting someone leave it running.
        stopTimer = setTimeout(function () {
          if (recorder && recorder.state === 'recording') { recorder.stop(); }
        }, MAX_RECORD_MS);
      }).catch(function () {
        say('The microphone is not available. Allow microphone access for this site and try again.');
      });
    });

    document.getElementById('opsStopRec').addEventListener('click', function () {
      if (recorder && recorder.state === 'recording') { recorder.stop(); }
    });

    document.getElementById('opsCancelRec').addEventListener('click', function () {
      discarding = true;
      if (recorder && recorder.state === 'recording') { recorder.stop(); }
      else { showRecording(false); releaseMic(); }
    });

    function finish() {
      var seconds = Math.max(1, Math.round((Date.now() - startedAt) / 1000));
      var type = (recorder && recorder.mimeType) || '';
      var blob = new Blob(chunks, { type: type || 'audio/webm' });
      releaseMic();
      showRecording(false);

      if (discarding || !blob.size) { return; }

      var stamp = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
      if (type.indexOf('audio/mp4') === 0) {
        stageVoice(new File([blob], 'voice_' + stamp + '.m4a', { type: 'audio/mp4' }), seconds);
        return;
      }
      toWav(blob).then(function (wav) {
        stageVoice(new File([wav], 'voice_' + stamp + '.wav', { type: 'audio/wav' }), seconds);
      }).catch(function () {
        say('That recording could not be prepared. Please try again.');
      });
    }

    function stageVoice(file, seconds) {
      if (stage(file, seconds)) { sync(); }
    }

    /** decodeAudioData is a promise in current browsers and a callback in older ones. */
    function decode(context, buffer) {
      return new Promise(function (resolve, reject) {
        var maybe = context.decodeAudioData(buffer, resolve, reject);
        if (maybe && maybe.then) { maybe.then(resolve, reject); }
      });
    }

    /**
     * 16-bit PCM WAV, mono, 22.05 kHz — the same shape the app records at.
     * Speech at roughly 44 KB a second: two minutes is 5 MB, inside the 10 MB
     * the server takes, and playable everywhere without asking anything of the
     * browser, the server or the phone.
     */
    function toWav(blob) {
      var Context = window.AudioContext || window.webkitAudioContext;
      return blob.arrayBuffer().then(function (buffer) {
        var context = new Context();
        return decode(context, buffer).then(function (audio) {
          if (context.close) { context.close(); }
          return encodeWav(audio);
        });
      });
    }

    function encodeWav(audio) {
      var rate = 22050;
      var step = audio.sampleRate / rate;
      var count = Math.max(1, Math.floor(audio.length / step));
      var tracks = [];
      for (var c = 0; c < audio.numberOfChannels; c++) { tracks.push(audio.getChannelData(c)); }

      var view = new DataView(new ArrayBuffer(44 + count * 2));
      var text = function (offset, value) {
        for (var i = 0; i < value.length; i++) { view.setUint8(offset + i, value.charCodeAt(i)); }
      };
      text(0, 'RIFF');
      view.setUint32(4, 36 + count * 2, true);
      text(8, 'WAVE');
      text(12, 'fmt ');
      view.setUint32(16, 16, true);      // PCM header length
      view.setUint16(20, 1, true);       // PCM, uncompressed
      view.setUint16(22, 1, true);       // mono
      view.setUint32(24, rate, true);
      view.setUint32(28, rate * 2, true); // bytes per second
      view.setUint16(32, 2, true);        // bytes per sample frame
      view.setUint16(34, 16, true);       // bits per sample
      text(36, 'data');
      view.setUint32(40, count * 2, true);

      // Channels are averaged rather than one being picked: a laptop with a
      // stereo mic often has almost nothing on one side of it.
      for (var i = 0; i < count; i++) {
        var at = Math.floor(i * step);
        var sum = 0;
        for (var t = 0; t < tracks.length; t++) { sum += tracks[t][at] || 0; }
        var sample = Math.max(-1, Math.min(1, sum / tracks.length));
        view.setInt16(44 + i * 2, sample < 0 ? sample * 0x8000 : sample * 0x7FFF, true);
      }
      return new Blob([view.buffer], { type: 'audio/wav' });
    }

    refreshSend();
  })();

});
</script>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
