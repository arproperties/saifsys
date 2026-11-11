<?php
/**
 * Operations → Body camera: the Looki L1 worn by field staff on chosen tasks.
 * Three tabs:
 *   Daily report  one person's day as a timeline: each recording (when, where,
 *                 what happened, which job) and the events between them, i.e.
 *                 store entries, Store camera changes and voice events. An event
 *                 with no store entry within an hour is an alert.
 *   Recordings    the photos and videos of a day, Linked or Not linked to a job;
 *                 Link to job, Delete.
 *   Setup         each L1: who wears it and its Looki API key; Sync now; how many
 *                 days recordings are kept. Owner, Admin and HR only.
 * Recordings come from cron/body_camera_sync.php. Rules: includes/ops_body_camera.php.
 */
require_once __DIR__ . '/includes/ops_store_page.php';
require_once __DIR__ . '/includes/ops_body_camera.php';

$self = $opsBase . '/body_camera.php';
$fileUrl = $opsBase . '/body_camera_file.php';
$bcReady = bc_tables_ready($conn);
$canManage = bc_can_manage($conn);
$tab = in_array($_GET['tab'] ?? '', ['recordings', 'setup', 'help'], true) ? $_GET['tab'] : 'report';
if ($tab === 'setup' && !$canManage) {
    $tab = 'report';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bcReady) {
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');
    $back = (string)($_POST['back'] ?? '');
    try {
        if ($do === 'add_event') {
            bc_add_event($conn, (int)($_POST['user_id'] ?? 0), $_POST, $stWho['user_id'] ?: null);
            ops_flash('Event added.');
        } elseif ($do === 'edit_event') {
            bc_edit_event($conn, (int)($_POST['event_id'] ?? 0), (int)($_POST['user_id'] ?? 0), $_POST);
            ops_flash('Event saved.');
        } elseif ($do === 'note_row') {
            bc_note_row($conn, (int)($_POST['user_id'] ?? 0), (string)($_POST['ref'] ?? ''), (string)($_POST['note'] ?? ''), $stWho['user_id'] ?: null);
            ops_flash('Note saved.');
        } elseif ($do === 'edit_moment') {
            bc_edit_moment($conn, (int)($_POST['moment_id'] ?? 0), $_POST, $stWho['user_id'] ?: null);
            ops_flash('Saved.');
        } elseif ($do === 'delete_event') {
            if (!$canManage) {
                throw new BcError('Only Owner, Admin and HR can delete events.');
            }
            bc_delete_event($conn, (int)($_POST['user_id'] ?? 0), (string)($_POST['ref'] ?? ''), $stWho['user_id'] ?: null);
            ops_flash('Deleted from the report.');
        } elseif ($do === 'link') {
            // One recording, or a whole hour of them (ids separated by commas).
            $ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['file_id'] ?? ''))))), 0, 500);
            if (!$ids) {
                throw new BcError('That recording is gone.');
            }
            $own = !$conn->inTransaction();
            if ($own) {
                $conn->beginTransaction();
            }
            try {
                foreach ($ids as $fid) {
                    bc_link_file($conn, $fid, (int)($_POST['job_id'] ?? 0), $stWho['user_id'] ?: null);
                }
                if ($own) {
                    $conn->commit();
                }
            } catch (Throwable $e) {
                if ($own) {
                    $conn->rollBack();
                }
                throw $e;
            }
            ops_flash(count($ids) > 1 ? count($ids) . ' recordings linked to the job.' : 'Linked to the job.');
        } elseif ($do === 'delete') {
            if (!$canManage) {
                throw new BcError('Only Owner, Admin and HR can remove recordings.');
            }
            bc_delete_file($conn, (int)($_POST['file_id'] ?? 0));
            ops_flash('Removed from saifsys. It is still in Looki.');
        } elseif ($do === 'device') {
            if (!$canManage) {
                throw new BcError('Only Owner, Admin and HR can set up devices.');
            }
            bc_save_device($conn, (int)($_POST['device_id'] ?? 0), $_POST, $stWho['user_id'] ?: null);
            ops_flash('Device saved. Click Sync now to fetch its recordings.');
        } elseif ($do === 'sync') {
            if (!$canManage) {
                throw new BcError('Only Owner, Admin and HR can sync.');
            }
            $d = bc_device($conn, (int)($_POST['device_id'] ?? 0));
            if (!$d) {
                throw new BcError('That device is gone.');
            }
            // The same lock as the cron, so the two never download the same files at once.
            $lock = fopen(bc_dir() . '/sync.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new BcError('A sync is already running. Try again in a few minutes.');
            }
            set_time_limit(0);
            ignore_user_abort(true);
            session_write_close();
            $note = bc_sync_device($conn, $d);
            flock($lock, LOCK_UN);
            session_start();
            ops_flash('Sync: ' . $note, (bc_device($conn, (int)$d['id'])['last_sync_ok'] ?? 0) ? 'success' : 'danger');
        } elseif ($do === 'keep') {
            if (!$canManage) {
                throw new BcError('Only Owner, Admin and HR can change this.');
            }
            bc_settings_save($_POST);
            ops_flash('Saved. Older report rows are deleted at the next sync.');
        }
    } catch (BcError $e) {
        ops_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $self . ($back !== '' && preg_match('/^[A-Za-z0-9_=&%:.+-]*$/', $back) ? '?' . $back : ''));
    exit;
}

$validDay = static fn($d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$day = $validDay($_GET['day'] ?? null) ? (string)$_GET['day'] : date('Y-m-d');
$people = $bcReady ? bc_people($conn) : [];
$personId = (int)($_GET['user_id'] ?? 0);
if ($tab === 'report' && $personId === 0 && $people) {
    $personId = (int)$people[0]['id'];
}
$linked = in_array($_GET['linked'] ?? '', ['yes', 'no'], true) ? (string)$_GET['linked'] : '';
$qs = static fn(array $p): string => h($self . '?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== 0 && $v !== null)));
$time = static fn(?string $dt): string => $dt ? date('g:i A', strtotime($dt)) : '';
$unlinked = $bcReady ? bc_unlinked_count($conn, $day) : 0;
// Snapshots as proof on the report: each one opens the photo or video from Looki's cloud.
$snapStrip = static function (array $snaps, string $before = '') use ($fileUrl): string {
    if (!$snaps && $before === '') {
        return '';
    }
    $out = '<div class="bc-snaps">' . $before;
    foreach ($snaps as $s) {
        $src = $fileUrl . '?id=' . (int)$s['id'];
        $out .= '<a href="' . h($src) . '" target="_blank" rel="noopener" class="bc-snap-box' . (!empty($s['video']) ? ' bc-snap-video' : '') . '">'
            . '<img src="' . h($src . '&thumb=1') . '" alt="Snapshot" class="bc-snap" loading="lazy"'
            . ' onerror="this.replaceWith(Object.assign(document.createElement(\'span\'),{className:\'bc-snap bc-snap-gone\',textContent:\'Not in Looki\'}))"></a>';
    }
    return $out . '</div>';
};

$pageTitle = 'Body camera';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<style>
  .bc-tl td { vertical-align:top; }
  .bc-tl .bc-time { white-space:nowrap; font-variant-numeric:tabular-nums; color:#475569; }
  .bc-ev > td { background:#f8fafc; }
  .bc-ev-ok > td { background:#f0fdf4; }
  .bc-ev-alert > td { background:#fff7ed; }
  .bc-tag { display:inline-block; padding:.1rem .5rem; border-radius:999px; font-size:.72rem; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
  .bc-tag-store { background:#dcfce7; color:#166534; }
  .bc-tag-alert { background:#ffedd5; color:#9a3412; }
  .bc-tag-voice { background:#e0e7ff; color:#3730a3; }
  .bc-tag-att { background:#e0f2fe; color:#075985; }
  .bc-tag-break { background:#fef9c3; color:#854d0e; }
  .bc-tag-job { background:#ede9fe; color:#5b21b6; }
  .bc-tag-loc { background:#ccfbf1; color:#115e59; }
  .bc-tag-manual { background:#f1f5f9; color:#334155; }
  .bc-thumb { width:132px; height:100px; object-fit:cover; border-radius:10px; border:1px solid #dee2e6; background:#0f172a; display:block; }
  .bc-thumb-box { position:relative; cursor:pointer; }
  .bc-thumb-sm { width:80px; height:60px; border-radius:8px; }
  .bc-thumb-open { border:2px solid #f59e0b; }
  .bc-thumb-box .bc-play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.8rem; text-shadow:0 1px 4px rgba(0,0,0,.6); pointer-events:none; }
  .bc-thumb-box .bc-dur { position:absolute; right:6px; bottom:6px; background:rgba(0,0,0,.65); color:#fff; font-size:.7rem; padding:0 .35rem; border-radius:6px; }
  .bc-story-gone { color:#9a3412; font-weight:600; }
  .bc-story-new { color:#1d4ed8; }
  .bc-story-note { color:#713f12; font-style:italic; }
  body.dark-mode .bc-story-gone { color:#fdba74; }
  body.dark-mode .bc-story-new { color:#93c5fd; }
  .bc-summary { display:flex; flex-wrap:wrap; gap:.4rem 1.4rem; color:#475569; }
  body.dark-mode .bc-summary { color:#cbd5e1; }
  .bc-details summary { cursor:pointer; color:var(--primary); }
  .bc-office-note { margin-top:4px; padding:.25rem .5rem; border-radius:8px; background:#fefce8; color:#713f12; font-size:.85rem; display:inline-block; }
  body.dark-mode .bc-office-note { background:rgba(250,204,21,.12); color:#fde68a; }
  .bc-snaps { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
  .bc-snap { width:72px; height:54px; object-fit:cover; border-radius:8px; border:1px solid #dee2e6; background:#e2e8f0; display:block; }
  .bc-snap-box { position:relative; display:block; }
  .bc-snap-video::after { content:"\25B6"; position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.1rem; text-shadow:0 1px 4px rgba(0,0,0,.7); }
  .bc-snap-gone { display:flex; align-items:center; justify-content:center; font-size:.7rem; color:#64748b; }
  body.dark-mode .bc-ev > td { background:rgba(255,255,255,.03); }
  body.dark-mode .bc-ev-ok > td { background:rgba(34,197,94,.08); }
  body.dark-mode .bc-ev-alert > td { background:rgba(249,115,22,.10); }
  @media print {
    .sidebar, .topbar, .ops-tabs, .bc-noprint, nav, header { display:none !important; }
    .bc-ev > td, .bc-ev-ok > td, .bc-ev-alert > td { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>

<div class="d-flex flex-wrap align-items-start gap-2 mb-3">
  <div>
    <div class="page-header-label">Body camera</div>
    <div class="text-muted small">
      Recordings from the Looki L1 worn on tasks. They come from Looki's cloud every 30 minutes
      after the L1 has charged on Wi-Fi, and are linked to the job the person had started at the time.
    </div>
  </div>
</div>

<ul class="nav ops-tabs mb-3 bc-noprint">
  <li class="nav-item"><a class="nav-link <?= $tab === 'report' ? 'active' : '' ?>" href="<?= $qs(['day' => $day, 'user_id' => $personId]) ?>">Daily report</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'recordings' ? 'active' : '' ?>" href="<?= $qs(['tab' => 'recordings', 'day' => $day]) ?>">Recordings
    <?php if ($unlinked): ?><span class="badge bg-warning-subtle text-warning-emphasis"><?= $unlinked ?> not linked</span><?php endif; ?></a></li>
  <?php if ($canManage): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === 'setup' ? 'active' : '' ?>" href="<?= $qs(['tab' => 'setup']) ?>">Setup</a></li>
  <?php endif; ?>
  <li class="nav-item"><a class="nav-link <?= $tab === 'help' ? 'active' : '' ?>" href="<?= $qs(['tab' => 'help']) ?>"><i class="bi bi-question-circle me-1"></i>How to use</a></li>
</ul>

<?php if (!$bcReady): ?>
  <div class="alert alert-warning">The body camera tables are not set up yet. Run migrations/ops_body_camera.sql.</div>

<?php elseif ($tab === 'report'): ?>
  <?php $prev = date('Y-m-d', strtotime($day . ' -1 day')); $next = date('Y-m-d', strtotime($day . ' +1 day')); ?>
  <form method="get" class="row g-2 align-items-end mb-3 bc-noprint">
    <div class="col-12 col-md-4"><label class="form-label small text-muted mb-1">Employee</label>
      <select name="user_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php if (!$people): ?><option value="">No L1 set up yet</option><?php endif; ?>
        <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $personId ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12 col-md-4"><label class="form-label small text-muted mb-1">Date</label>
      <div class="input-group input-group-sm">
        <a class="btn btn-outline-secondary" href="<?= $qs(['day' => $prev, 'user_id' => $personId]) ?>" aria-label="Day before"><i class="bi bi-chevron-left"></i></a>
        <input type="date" name="day" value="<?= h($day) ?>" class="form-control" onchange="this.form.submit()">
        <a class="btn btn-outline-secondary" href="<?= $qs(['day' => $next, 'user_id' => $personId]) ?>" aria-label="Next day"><i class="bi bi-chevron-right"></i></a>
      </div></div>
    <div class="col-12 col-md-4 text-md-end">
      <?php if ($people): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#bcEventModal"><i class="bi bi-plus-lg me-1"></i>Add event</button>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="bcPdf"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</button>
    </div>
  </form>
  <script>
  // The PDF has every row's details open and every photo loaded; the screen stays as it was.
  (function () {
    let opened = [];
    window.addEventListener('beforeprint', function () {
      opened = Array.from(document.querySelectorAll('details.bc-details:not([open])'));
      opened.forEach(function (d) { d.open = true; });
    });
    window.addEventListener('afterprint', function () {
      opened.forEach(function (d) { d.open = false; });
      opened = [];
    });
    document.getElementById('bcPdf').addEventListener('click', function () {
      const btn = this;
      const imgs = Array.from(document.querySelectorAll('img.bc-snap'));
      imgs.forEach(function (i) { i.loading = 'eager'; });
      btn.disabled = true;
      const loaded = imgs.map(function (i) {
        return i.complete ? Promise.resolve() : new Promise(function (ok) { i.addEventListener('load', ok); i.addEventListener('error', ok); });
      });
      // Photos come from Looki; print after 8 seconds even if some are slow.
      Promise.race([Promise.all(loaded), new Promise(function (ok) { setTimeout(ok, 8000); })]).then(function () {
        btn.disabled = false;
        window.print();
      });
    });
  })();
  </script>

  <?php if (!$people): ?>
    <div class="card card-round"><div class="card-body text-center py-5">
      <i class="bi bi-person-video fs-1 text-muted d-block mb-2"></i>
      <div class="mb-3">No Looki L1 is set up yet.</div>
      <?php if ($canManage): ?><a href="<?= $qs(['tab' => 'setup']) ?>" class="btn btn-primary">Set up an L1</a><?php endif; ?>
    </div></div>
  <?php else: ?>
    <?php
      $rep = bc_report($conn, $personId, $day);
      $personName = '';
      foreach ($people as $p) { if ((int)$p['id'] === $personId) { $personName = (string)$p['name']; } }
    ?>
    <?php
      // Each row kind: tag colour, icon, label. Store camera and voice turn orange without a store entry.
      $kinds = [
          'moment' => ['bc-tag-manual', 'bi-camera-reels', 'Recording'],
          'store_entry' => ['bc-tag-store', 'bi-box-seam', 'Store entry'], 'store_camera' => ['bc-tag-store', 'bi-camera-video', 'Store camera'],
          'voice' => ['bc-tag-voice', 'bi-mic', 'Voice'], 'check_in' => ['bc-tag-att', 'bi-box-arrow-in-right', 'Check in'],
          'check_out' => ['bc-tag-att', 'bi-box-arrow-right', 'Check out'], 'break' => ['bc-tag-break', 'bi-cup-hot', 'Break'],
          'job_start' => ['bc-tag-job', 'bi-play-circle', 'Job started'], 'job_finish' => ['bc-tag-job', 'bi-check2-circle', 'Job finished'],
          'pause' => ['bc-tag-break', 'bi-pause-circle', 'Paused'], 'trip' => ['bc-tag-loc', 'bi-geo-alt', 'Trip'],
          'manual' => ['bc-tag-manual', 'bi-pencil', 'Added'], 'command' => ['bc-tag-job', 'bi-mic', 'Voice'],
          'change' => ['bc-tag-alert', 'bi-question-circle', 'Not seen'],
      ];
      // A voice command's tag says what it was.
      $commandTags = ['Start work' => ['bc-tag-job', 'bi-play-circle'], 'Finish work' => ['bc-tag-job', 'bi-check2-circle'],
          'Break' => ['bc-tag-break', 'bi-cup-hot'], 'Back' => ['bc-tag-break', 'bi-cup-hot'], 'Problem' => ['bc-tag-alert', 'bi-exclamation-octagon']];
      $back = 'day=' . $day . '&user_id=' . $personId;
      $mapLink = static fn(string $ll, string $label): string => '<a class="bc-noprint" href="' . h('https://www.google.com/maps?q=' . rawurlencode($ll)) . '" target="_blank" rel="noopener"><i class="bi bi-geo-alt"></i> ' . h($label) . '</a>';
      $deleteForm = static function (array $r) use ($canManage, $personId, $back): string {
          if (!$canManage) {
              return '';
          }
          ob_start();
          csrf_field();
          $csrf = ob_get_clean();
          $ask = $r['type'] === 'manual' ? 'Delete this event?' : 'Delete this from the report? Nothing changes in HR, Operations, Store or Looki.';
          return '<form method="post" class="m-0 d-inline bc-noprint" onsubmit="return confirm(' . h(json_encode($ask)) . ')">' . $csrf
              . '<input type="hidden" name="do" value="delete_event"><input type="hidden" name="ref" value="' . h($r['ref']) . '">'
              . '<input type="hidden" name="user_id" value="' . (int)$personId . '"><input type="hidden" name="back" value="' . h($back) . '">'
              . '<button class="btn btn-sm btn-link text-danger p-0" title="Delete" aria-label="Delete"><i class="bi bi-trash"></i></button></form>';
      };
      $storeCount = count(array_filter($rep['rows'], static fn(array $r): bool => $r['type'] === 'store_entry'));
      $onCamera = $rep['first'] ? bc_minutes(strtotime($rep['last']) - strtotime($rep['first'])) : '';
    ?>

    <?php // Layout B: one summary line, then the day as one compact table. ?>
    <div class="card card-round mb-3">
      <div class="card-body py-3">
        <div class="d-flex flex-wrap align-items-baseline gap-2">
          <div class="fw-semibold fs-5 me-2"><?= h($personName) ?> · <?= h(date('l j M Y', strtotime($day))) ?></div>
          <?php if (!$rep['rows']): ?>
            <span class="text-muted">Nothing recorded this day.</span>
          <?php endif; ?>
        </div>
        <?php if ($rep['rows']): ?>
          <div class="bc-summary mt-1">
            <?php if ($rep['first']): ?>
              <span><i class="bi bi-clock me-1"></i>On camera <b><?= h($time($rep['first'])) ?> – <?= h($time($rep['last'])) ?></b> (<?= h($onCamera) ?>)</span>
            <?php endif; ?>
            <span><i class="bi bi-tools me-1"></i><b><?= (int)$rep['jobs'] ?></b> job<?= $rep['jobs'] === 1 ? '' : 's' ?></span>
            <span><i class="bi bi-box-seam me-1"></i><b><?= $storeCount ?></b> store entr<?= $storeCount === 1 ? 'y' : 'ies' ?></span>
            <?php if ($rep['alerts']): ?>
              <span class="text-warning-emphasis fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= (int)$rep['alerts'] ?> to check</span>
            <?php else: ?>
              <span class="text-success-emphasis"><i class="bi bi-check-circle-fill me-1"></i>Nothing to check</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($rep['rows']): ?>
    <div class="card card-round mb-3">
      <div class="table-responsive">
        <table class="table ops-table bc-tl">
          <thead><tr><th style="width:8.5rem">Time</th><th>What</th><th style="width:12rem">Where</th><th style="width:7rem">Job</th><th class="bc-noprint" style="width:4rem"></th></tr></thead>
          <tbody>
          <?php foreach ($rep['rows'] as $r): ?>
            <?php
              $storeCheck = in_array($r['type'], ['store_camera', 'voice'], true);
              $alert = ($storeCheck && empty($r['ok'])) || ($r['type'] === 'change' && $r['flag'] === 'gone');
              $tag = $kinds[$r['type']];
              if ($r['type'] === 'change' && $r['flag'] === 'new') { $tag = ['bc-tag-loc', 'bi-plus-circle', 'New']; }
              if ($storeCheck) { $tag[0] = $alert ? 'bc-tag-alert' : 'bc-tag-store'; }
              if ($r['type'] === 'manual' && $r['label'] !== '') { $tag[2] = $r['label']; }
              if ($r['type'] === 'command') { $tag = array_merge($commandTags[$r['label']] ?? $tag, [$r['label']]); }
              // A store line Looki saw in the video, heard, or the wearer said to the assistant.
              $seen = $r['type'] === 'voice' && $r['label'] === 'Seen';
              if ($r['type'] === 'voice') { $tag = [$tag[0], $seen ? 'bi-camera' : 'bi-mic', $seen ? 'Camera saw' : ($r['label'] === 'Heard' ? 'Heard' : 'Said')]; }
              $isMoment = $r['type'] === 'moment';
            ?>
            <tr class="<?= $alert ? 'bc-ev-alert' : ($r['type'] === 'store_entry' ? 'bc-ev-ok' : '') ?>">
              <td class="bc-time"><?= h($time($r['at'])) ?><?= !empty($r['end']) && $time($r['end']) !== $time($r['at']) ? '–' . h($time($r['end'])) : '' ?></td>
              <td>
                <div>
                  <span class="bc-tag <?= $tag[0] ?> me-1" title="<?= h($tag[2]) ?>"><i class="bi <?= $tag[1] ?>"></i></span>
                  <span class="<?= $isMoment ? 'fw-semibold' : '' ?>"><?= $r['type'] === 'voice' && !$seen ? '“' . h($r['text']) . '”' : h($r['text']) ?></span>
                  <?php if (!$isMoment && $r['type'] !== 'change'): ?><span class="small text-muted ms-1"><?= h($tag[2]) ?></span><?php endif; ?>
                </div>
                <div class="small">
                  <?php if ($r['type'] === 'store_entry'): ?>
                    <a href="<?= h($opsBase) ?>/store_history.php" class="text-success-emphasis">Store entry #<?= (int)$r['move_id'] ?> <i class="bi bi-check-circle-fill"></i></a>
                  <?php elseif ($storeCheck && $alert): ?>
                    <span class="text-warning-emphasis fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> No store entry within an hour</span>
                  <?php elseif ($storeCheck): ?>
                    <span class="text-success-emphasis"><i class="bi bi-check-circle-fill"></i> Store entry found</span>
                  <?php elseif ($r['type'] === 'trip'): ?>
                    <?= !empty($r['map_from']) ? $mapLink($r['map_from'], 'Start') : '' ?><?= !empty($r['map_to']) ? ' · ' . $mapLink($r['map_to'], 'End') : '' ?>
                  <?php elseif ($r['type'] === 'manual' && !empty($r['added_by'])): ?>
                    <span class="text-muted">Added by <?= h($r['added_by']) ?></span>
                  <?php elseif ($r['type'] === 'command'): ?>
                    <span class="text-muted"><i class="bi bi-mic"></i> “<?= h($r['said']) ?>”</span>
                  <?php elseif ($r['type'] === 'change'): ?>
                    <span class="text-muted">Looki did not see it in this recording. Check the photos.</span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($r['office_note'])): ?><div class="bc-office-note"><i class="bi bi-chat-left-text me-1"></i><?= h($r['office_note']) ?></div><?php endif; ?>
                <?php $camSnap = !empty($r['cam_change_id']) ? '<a href="' . h($opsBase . '/store_camera_file.php?change=' . (int)$r['cam_change_id']) . '" target="_blank" rel="noopener" title="Store camera">'
                    . '<img src="' . h($opsBase . '/store_camera_file.php?change=' . (int)$r['cam_change_id']) . '" alt="Store camera picture" class="bc-snap" loading="lazy"></a>' : ''; ?>
                <?= $snapStrip($r['snaps'] ?? [], $camSnap) ?>
                <?php if ($isMoment && ($r['description'] || $r['keywords'])): ?>
                  <details class="bc-details small mt-1">
                    <summary class="bc-noprint">Details<?= $r['files'] > count($r['snaps']) ? ' · ' . (int)$r['files'] . ' photos and videos' : '' ?></summary>
                    <?php if ($r['description']): ?><div class="text-muted mt-1"><?= h($r['description']) ?></div><?php endif; ?>
                    <?php if ($r['keywords']): ?><div class="mt-1"><span class="text-muted">Looki saw:</span> <?= h(implode(', ', $r['keywords'])) ?></div><?php endif; ?>
                    <?php if ($r['files'] > count($r['snaps'])): ?>
                      <div class="d-none d-print-block text-muted mt-1"><?= (int)$r['files'] ?> photos and videos in this recording.</div>
                      <a class="bc-noprint" href="<?= $qs(['tab' => 'recordings', 'day' => $day, 'user_id' => $personId]) ?>">Open all <?= (int)$r['files'] ?> photos and videos ›</a>
                    <?php endif; ?>
                  </details>
                <?php endif; ?>
              </td>
              <td class="small"><?= h(!empty($r['place']) ? explode(',', (string)$r['place'])[0] : '') ?></td>
              <td>
                <?php if ($isMoment && $r['jobs']): ?>
                  <?php foreach (array_keys($r['jobs']) as $jid): ?><div><a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$jid ?>" title="<?= h($r['jobs'][$jid]) ?>">#<?= (int)$jid ?></a></div><?php endforeach; ?>
                <?php elseif ($isMoment && $r['job_chosen']): ?><span class="text-muted small">No job</span>
                <?php elseif (!empty($r['job_id'])): ?><a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$r['job_id'] ?>">#<?= (int)$r['job_id'] ?></a>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td class="bc-noprint text-nowrap text-end">
                <?php if ($isMoment): ?>
                  <button type="button" class="btn btn-sm btn-link p-0 me-2 bc-edit-moment" title="Note and job" aria-label="Note and job"
                          data-id="<?= (int)$r['moment_id'] ?>" data-note="<?= h((string)$r['office_note']) ?>"
                          data-job="<?= $r['job_chosen'] ? ($r['office_job_id'] ?? 'none') : '' ?>" data-what="<?= h($time($r['at']) . ' · ' . $r['text']) ?>"><i class="bi bi-pencil"></i></button>
                <?php elseif ($r['type'] === 'manual'): ?>
                  <button type="button" class="btn btn-sm btn-link p-0 me-2 bc-edit-event" title="Edit" aria-label="Edit"
                          data-id="<?= (int)substr($r['ref'], 1) ?>" data-time="<?= h(substr($r['at'], 11, 5)) ?>" data-end="<?= h($r['end'] ? substr($r['end'], 11, 5) : '') ?>"
                          data-label="<?= h($r['label']) ?>" data-text="<?= h($r['text']) ?>"><i class="bi bi-pencil"></i></button>
                <?php else: ?>
                  <button type="button" class="btn btn-sm btn-link p-0 me-2 bc-edit-note" title="Add a note" aria-label="Add a note"
                          data-ref="<?= h($r['ref']) ?>" data-note="<?= h((string)($r['office_note'] ?? '')) ?>"
                          data-what="<?= h($time($r['at']) . ' · ' . $tag[2] . ' · ' . $r['text']) ?>"><i class="bi bi-pencil"></i></button>
                <?php endif; ?>
                <?= $deleteForm($r) ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if ($rep['no_job']): ?>
      <div class="small text-muted mb-3"><i class="bi bi-info-circle me-1"></i>Recorded but not on any job: <?= h(implode(', ', $rep['no_job'])) ?>. Use ✏️ to set the job.</div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="modal fade" id="bcEventModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog"><div class="modal-content">
        <form method="post">
          <?php csrf_field(); ?><input type="hidden" name="do" value="add_event"><input type="hidden" name="user_id" value="<?= (int)$personId ?>">
          <input type="hidden" name="event_id" value="0">
          <input type="hidden" name="day" value="<?= h($day) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <div class="modal-header"><h5 class="modal-title" id="bcEventTitle">Add event</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">
            <div class="small text-muted mb-3"><?= h($personName) ?> · <?= h(date('l j M Y', strtotime($day))) ?></div>
            <div class="row g-2 mb-3">
              <div class="col-6"><label class="form-label">From</label><input type="time" name="time" class="form-control" required></div>
              <div class="col-6"><label class="form-label">To <span class="text-muted small">(if it lasted)</span></label><input type="time" name="end_time" class="form-control"></div>
            </div>
            <div class="mb-3"><label class="form-label">Kind</label>
              <select name="label" class="form-select" required>
                <?php foreach (BC_EVENT_LABELS as $l): ?><option value="<?= h($l) ?>"><?= h($l) ?></option><?php endforeach; ?>
              </select></div>
            <div class="mb-1"><label class="form-label">What happened</label>
              <textarea name="text" class="form-control" rows="3" maxlength="500" required placeholder="e.g. Went to Al Barsha 12 to collect keys"></textarea></div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="bcEventSave">Add</button></div>
        </form>
      </div></div>
    </div>

    <?php $dayJobs = bc_jobs_for($conn, $personId, $day); ?>
    <div class="modal fade" id="bcMomentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog"><div class="modal-content">
        <form method="post">
          <?php csrf_field(); ?><input type="hidden" name="do" value="edit_moment"><input type="hidden" name="moment_id" value="0">
          <input type="hidden" name="back" value="<?= h($back) ?>">
          <div class="modal-header"><h5 class="modal-title">Note and job</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">
            <div class="small text-muted mb-3" id="bcMomentWhat"></div>
            <div class="mb-3"><label class="form-label">Job</label>
              <select name="job" class="form-select">
                <option value="">By time (from the job's Start and Finish)</option>
                <option value="none">No job</option>
                <?php foreach ($dayJobs as $j): ?>
                  <option value="<?= (int)$j['id'] ?>">#<?= (int)$j['id'] ?> <?= h($j['title'] . ($j['location'] ? ' · ' . $j['location'] : '')) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$dayJobs): ?><div class="form-text">This person has no jobs on this day.</div><?php endif; ?></div>
            <div class="mb-1"><label class="form-label">Note</label>
              <textarea name="note" class="form-control" rows="3" maxlength="500" placeholder="e.g. Checked: picked up keys for unit 204"></textarea></div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
        </form>
      </div></div>
    </div>
    <div class="modal fade" id="bcNoteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog"><div class="modal-content">
        <form method="post">
          <?php csrf_field(); ?><input type="hidden" name="do" value="note_row"><input type="hidden" name="ref" value="">
          <input type="hidden" name="user_id" value="<?= (int)$personId ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <div class="modal-header"><h5 class="modal-title">Office note</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">
            <div class="small text-muted mb-3" id="bcNoteWhat"></div>
            <textarea name="note" class="form-control" rows="3" maxlength="500" placeholder="e.g. Confirmed with the store keeper"></textarea>
            <div class="form-text">The row itself stays as HR, Operations or Store recorded it. Empty the note to remove it.</div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
        </form>
      </div></div>
    </div>
    <script>
    (function () {
      const nm = document.getElementById('bcNoteModal');
      document.querySelectorAll('.bc-edit-note').forEach(function (b) {
        b.addEventListener('click', function () {
          nm.querySelector('[name=ref]').value = b.dataset.ref;
          nm.querySelector('[name=note]').value = b.dataset.note;
          document.getElementById('bcNoteWhat').textContent = b.dataset.what;
          bootstrap.Modal.getOrCreateInstance(nm).show();
        });
      });
      const ev = document.getElementById('bcEventModal');
      const evForm = ev.querySelector('form');
      // Add event opens it empty; the pencil on an added event opens it filled in.
      ev.addEventListener('show.bs.modal', function (e) {
        const b = e.relatedTarget && e.relatedTarget.classList.contains('bc-edit-event') ? e.relatedTarget : null;
        evForm.querySelector('[name=do]').value = b ? 'edit_event' : 'add_event';
        evForm.querySelector('[name=event_id]').value = b ? b.dataset.id : 0;
        evForm.querySelector('[name=time]').value = b ? b.dataset.time : '';
        evForm.querySelector('[name=end_time]').value = b ? b.dataset.end : '';
        evForm.querySelector('[name=label]').value = b ? b.dataset.label : evForm.querySelector('[name=label] option').value;
        evForm.querySelector('[name=text]').value = b ? b.dataset.text : '';
        document.getElementById('bcEventTitle').textContent = b ? 'Edit event' : 'Add event';
        document.getElementById('bcEventSave').textContent = b ? 'Save' : 'Add';
      });
      document.querySelectorAll('.bc-edit-event').forEach(function (b) {
        b.addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(ev).show(b); });
      });

      const mo = document.getElementById('bcMomentModal');
      document.querySelectorAll('.bc-edit-moment').forEach(function (b) {
        b.addEventListener('click', function () {
          mo.querySelector('[name=moment_id]').value = b.dataset.id;
          mo.querySelector('[name=note]').value = b.dataset.note;
          const sel = mo.querySelector('[name=job]');
          sel.value = b.dataset.job;
          if (sel.value !== b.dataset.job) { sel.value = ''; }
          document.getElementById('bcMomentWhat').textContent = b.dataset.what;
          bootstrap.Modal.getOrCreateInstance(mo).show();
        });
      });
    })();
    </script>
  <?php endif; ?>

<?php elseif ($tab === 'recordings'): ?>
  <form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="tab" value="recordings">
    <div class="col-12 col-md-4">
      <div class="btn-group btn-group-sm w-100" role="group">
        <?php foreach (['' => 'All', 'yes' => 'Linked', 'no' => 'Not linked'] as $v => $label): ?>
          <a class="btn <?= $linked === $v ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= $qs(['tab' => 'recordings', 'day' => $day, 'user_id' => $personId, 'linked' => $v]) ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div></div>
    <div class="col-6 col-md-4"><label class="form-label small text-muted mb-1">Employee</label>
      <select name="user_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All</option>
        <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $personId ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-4"><label class="form-label small text-muted mb-1">Date</label>
      <input type="date" name="day" value="<?= h($day) ?>" class="form-control form-control-sm" onchange="this.form.submit()"></div>
    <?php if ($linked !== ''): ?><input type="hidden" name="linked" value="<?= h($linked) ?>"><?php endif; ?>
  </form>

  <?php
    $files = bc_files($conn, ['day' => $day, 'user_id' => $personId, 'linked' => $linked]);
    $byPerson = [];
    foreach ($files as $f) { $byPerson[(int)$f['user_id']][] = $f; }
    $back = 'tab=recordings&day=' . $day . ($personId ? '&user_id=' . $personId : '') . ($linked !== '' ? '&linked=' . $linked : '');
  ?>
  <?php if (!$files): ?>
    <div class="card card-round"><div class="card-body text-center text-muted py-5">No recordings for this day.</div></div>
  <?php endif; ?>
  <?php foreach ($byPerson as $uid => $list): ?>
    <?php
      $jobs = bc_jobs_for($conn, $uid, $day);
      $jobsJson = json_encode(array_map(static fn(array $j): array => ['id' => (int)$j['id'], 'text' => '#' . $j['id'] . ' ' . $j['title'] . ($j['location'] ? ' · ' . $j['location'] : '')
          . ($j['started_at'] ? '  ' . date('g:i A', strtotime($j['started_at'])) . ($j['finished_at'] ? '–' . date('g:i A', strtotime($j['finished_at'])) : '') : '')], $jobs));
      // One table row per hour of the day.
      $byHour = [];
      foreach ($list as $f) { $byHour[(int)date('G', strtotime($f['recorded_at']))][] = $f; }
      ksort($byHour);
    ?>
    <div class="card card-round mb-3">
      <div class="card-body">
        <div class="fw-semibold mb-3"><?= h(date('j M Y', strtotime($day))) ?> · <?= h($list[0]['person'] ?? '') ?> · <span class="text-muted fw-normal"><?= count($list) ?> recordings</span></div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th style="width:130px">Hour</th><th>Photos and videos</th><th style="width:220px">Job</th></tr></thead>
            <tbody>
            <?php foreach ($byHour as $hour => $hList): ?>
              <?php
                $videos = count(array_filter($hList, static fn(array $f): bool => $f['media_type'] === 'video'));
                $photos = count($hList) - $videos;
                $hourJobs = [];
                foreach ($hList as $f) { if ($f['job_id']) { $hourJobs[(int)$f['job_id']] = $f['job_title'] ?? ''; } }
                $notLinked = count(array_filter($hList, static fn(array $f): bool => !$f['job_id']));
                $hourLabel = date('g A', mktime($hour, 0)) . ' – ' . date('g A', mktime($hour + 1, 0));
              ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?= h($hourLabel) ?></div>
                  <div class="small text-muted"><?= $photos ? $photos . ' photo' . ($photos > 1 ? 's' : '') : '' ?><?= $photos && $videos ? ' · ' : '' ?><?= $videos ? $videos . ' video' . ($videos > 1 ? 's' : '') : '' ?></div>
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-2">
                  <?php foreach ($hList as $f): ?>
                    <?php
                      $isVideo = $f['media_type'] === 'video';
                      $src = $fileUrl . '?id=' . (int)$f['id'];
                    ?>
                    <div class="text-center" style="width:80px">
                      <div class="bc-thumb-box bc-open" data-src="<?= h($src) ?>" data-video="<?= $isVideo ? 1 : 0 ?>"
                           data-title="<?= h($time($f['recorded_at']) . ' · ' . ($f['person'] ?? '')) ?>">
                        <img src="<?= h($isVideo ? $src . '&thumb=1' : $src) ?>" alt="" class="bc-thumb bc-thumb-sm<?= $f['job_id'] ? '' : ' bc-thumb-open' ?>" loading="lazy">
                        <?php if ($isVideo): ?><span class="bc-play"><i class="bi bi-play-circle-fill"></i></span><?php endif; ?>
                        <?php if ($f['duration_ms']): ?><span class="bc-dur"><?= h(bc_duration((int)$f['duration_ms'])) ?></span><?php endif; ?>
                      </div>
                      <div class="small d-flex justify-content-center align-items-center gap-1">
                        <span><?= h($time($f['recorded_at'])) ?></span>
                        <?php if ($canManage): ?>
                          <form method="post" class="m-0" onsubmit="return confirm('Remove this recording from saifsys? It stays in Looki.')">
                            <?php csrf_field(); ?><input type="hidden" name="do" value="delete"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
                            <button class="btn btn-link btn-sm text-danger p-0" aria-label="Delete"><i class="bi bi-trash"></i></button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  </div>
                </td>
                <td>
                  <?php foreach ($hourJobs as $jid => $jtitle): ?>
                    <div class="small text-truncate"><a href="<?= h($opsBase) ?>/job_view.php?id=<?= $jid ?>">#<?= $jid ?></a> <span class="text-muted"><?= h($jtitle) ?></span></div>
                  <?php endforeach; ?>
                  <?php if ($notLinked): ?><div class="small text-warning-emphasis"><?= $notLinked ?> not linked</div><?php endif; ?>
                  <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 mt-1 bc-link"
                          data-file="<?= h(implode(',', array_map(static fn(array $f): int => (int)$f['id'], $hList))) ?>"
                          data-what="<?= h($hourLabel . ', ' . date('j M', strtotime($day)) . ' · ' . ($list[0]['person'] ?? '') . ' · ' . count($hList) . ' recordings') ?>"
                          data-jobs="<?= h($jobsJson) ?>"
                          data-current="<?= count($hourJobs) === 1 && !$notLinked ? (int)array_key_first($hourJobs) : 0 ?>"><?= $notLinked ? 'Link to job' : 'Change job' ?></button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="modal fade" id="bcLinkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
      <form method="post">
        <?php csrf_field(); ?><input type="hidden" name="do" value="link"><input type="hidden" name="file_id" value=""><input type="hidden" name="back" value="<?= h($back) ?>">
        <div class="modal-header"><h5 class="modal-title">Link this recording to a job</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="small text-muted mb-2" id="bcLinkWhat"></div>
          <div id="bcLinkJobs"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="bcLinkSave">Link</button></div>
      </form>
    </div></div>
  </div>

  <div class="modal fade" id="bcViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="bcViewTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body text-center bg-black" id="bcViewBody"></div>
    </div></div>
  </div>

  <script>
  (function () {
    const linkModal = document.getElementById('bcLinkModal');
    document.querySelectorAll('.bc-link').forEach(function (b) {
      b.addEventListener('click', function () {
        const jobs = JSON.parse(b.dataset.jobs || '[]');
        linkModal.querySelector('[name=file_id]').value = b.dataset.file;
        document.getElementById('bcLinkWhat').textContent = 'Recording: ' + b.dataset.what;
        const box = document.getElementById('bcLinkJobs');
        box.textContent = '';
        if (!jobs.length) {
          box.innerHTML = '<p class="text-muted mb-0">This person has no jobs on this day.</p>';
        }
        jobs.forEach(function (j, i) {
          const id = 'bcJob' + j.id;
          const div = document.createElement('div');
          div.className = 'form-check mb-1';
          div.innerHTML = '<input class="form-check-input" type="radio" name="job_id" required><label class="form-check-label"></label>';
          const input = div.querySelector('input'); input.id = id; input.value = j.id; input.checked = String(j.id) === b.dataset.current;
          const label = div.querySelector('label'); label.htmlFor = id; label.textContent = j.text;
          box.appendChild(div);
        });
        document.getElementById('bcLinkSave').disabled = !jobs.length;
        bootstrap.Modal.getOrCreateInstance(linkModal).show();
      });
    });

    const viewModal = document.getElementById('bcViewModal');
    const viewBody = document.getElementById('bcViewBody');
    document.querySelectorAll('.bc-open').forEach(function (t) {
      t.addEventListener('click', function () {
        document.getElementById('bcViewTitle').textContent = t.dataset.title;
        viewBody.textContent = '';
        const el = document.createElement(t.dataset.video === '1' ? 'video' : 'img');
        el.src = t.dataset.src;
        el.style.maxWidth = '100%'; el.style.maxHeight = '75vh';
        if (el.tagName === 'VIDEO') { el.controls = true; el.autoplay = true; el.playsInline = true; }
        viewBody.appendChild(el);
        bootstrap.Modal.getOrCreateInstance(viewModal).show();
      });
    });
    viewModal.addEventListener('hidden.bs.modal', function () { viewBody.textContent = ''; });
  })();
  </script>

<?php elseif ($tab === 'help'): ?>
  <?php // Plain steps for the person wearing the L1 and for the office. Prints on one page for staff. ?>
  <div class="d-flex mb-3 bc-noprint">
    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print for staff</button>
  </div>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card card-round h-100"><div class="card-body">
        <div class="fw-semibold fs-5 mb-3"><i class="bi bi-person-video me-1"></i>For staff wearing the L1</div>
        <ol class="mb-3">
          <li class="mb-2"><b>Switch on:</b> hold <b>B</b> until the corner lights come on. Keep your phone's Bluetooth on.</li>
          <li class="mb-2"><b>Clip it on</b> at chest height, camera facing forward.</li>
          <li class="mb-2"><b>To talk to it:</b> hold the <b>touchpad</b>, say the word, let go.</li>
        </ol>
        <table class="table table-sm mb-3">
          <thead><tr><th>When</th><th>Hold the touchpad and say</th></tr></thead>
          <tbody>
            <tr><td>You start a job</td><td><b>“Start work, JVC 204”</b></td></tr>
            <tr><td>You stop for a break</td><td><b>“Break”</b>, then <b>“Start work”</b> when you are back</td></tr>
            <tr><td>The job is done</td><td><b>“Finish work”</b></td></tr>
          </tbody>
        </table>
        <ul class="mb-3">
          <li class="mb-2">Press <b>B once</b> for a photo at the start and at the end of the job.</li>
          <li class="mb-2">Taking from the store? Hold the touchpad and say <b>“Taking two floor cleaners”</b>.</li>
          <li class="mb-2">Something broken? Hold the touchpad and say <b>“Problem, water leak in the bathroom”</b>.</li>
          <li class="mb-2"><b>End of day:</b> put the L1 <b>on charge</b> at the office, near the office phone with the Looki app.
            It uploads by itself over the office Wi-Fi (saved on the L1: Looki app → L1 → Wi-Fi; Auto Upload While Charging ON).</li>
        </ul>
        <div class="alert alert-info small mb-3">
          <b>No phone with you?</b> The L1 still records. Use the buttons: <b>B</b> photo, <b>A</b> video.
          Voice commands and location need the phone, so they won't show. Give the L1 back the same day so it uploads on charge.
        </div>
        <div class="alert alert-warning small mb-0">
          Tell people before you record. Do not record inside an occupied home without the tenant's OK.
          Keep the camera off in the office and on breaks.
        </div>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card card-round mb-3"><div class="card-body">
        <div class="fw-semibold fs-5 mb-3"><i class="bi bi-hand-index me-1"></i>Buttons and lights</div>
        <table class="table table-sm mb-0">
          <tbody>
            <tr><td><b>B</b> once</td><td>Photo</td></tr>
            <tr><td><b>A</b> once</td><td>Start / stop video <span class="text-muted">(top light solid green)</span></td></tr>
            <tr><td><b>A</b> hold</td><td>Start / stop audio <span class="text-muted">(top light solid red)</span></td></tr>
            <tr><td><b>B</b> hold</td><td>Switch on, or start / stop Story Mode <span class="text-muted">(top light breathing green)</span></td></tr>
            <tr><td><b>A + B</b> hold</td><td>Switch off <span class="text-muted">(corner lights flash yellow)</span></td></tr>
            <tr><td><b>Touchpad</b> hold</td><td>Speak a command, then let go</td></tr>
            <tr><td>Double-tap the pad</td><td>Battery: green high, orange medium, red low</td></tr>
          </tbody>
        </table>
      </div></div>
      <div class="card card-round bc-noprint"><div class="card-body">
        <div class="fw-semibold fs-5 mb-3"><i class="bi bi-building me-1"></i>For the office</div>
        <ol class="mb-0">
          <li class="mb-2"><b>Daily report:</b> choose the employee and the date. Each row shows what happened, when and where, with photos as proof.
            What staff said (Start work, Break, Finish work) shows with the time and how long it took.</li>
          <li class="mb-2"><b>Orange rows</b> (⚠️) need a look: something seems taken from the store with no store entry.</li>
          <li class="mb-2"><b>Add event</b> to note something yourself. <b>🗑</b> removes a row from the report only; nothing changes in HR, Operations or Store.</li>
          <li class="mb-2"><b>Recordings:</b> all photos and videos of the day. <b>Link to job</b> for any marked “Not linked”.</li>
          <li class="mb-2">New recordings arrive after the L1 charges on Wi-Fi near the phone. If they don't, open the Looki app → <b>Device → Upload &amp; Process</b>, then <b>Sync now</b>. Upload within 2 days; saifsys looks back 2 days. They stay in Looki's cloud.</li>
        </ol>
      </div></div>
    </div>
  </div>

<?php elseif ($tab === 'setup'): ?>
  <?php $devices = bc_devices($conn); $users = ops_assignable_users($conn); $keep = bc_settings()['keep_days']; ?>
  <div class="card card-round mb-3">
    <div class="card-body">
      <div class="d-flex align-items-center mb-3">
        <div class="fw-semibold fs-5">Devices</div>
        <button type="button" class="btn btn-sm btn-primary ms-auto bc-dev" data-dev="<?= h(json_encode(['id' => 0, 'user_id' => 0, 'label' => '', 'active' => 1])) ?>"><i class="bi bi-plus-lg me-1"></i>Add L1</button>
      </div>
      <?php if (!$devices): ?>
        <p class="text-muted mb-0">No L1 yet. Click Add L1, choose who wears it and paste the API key from Looki (web.looki.ai → API keys).</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table ops-table">
            <thead><tr><th>Device</th><th>Worn by</th><th>Key</th><th>Last sync</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($devices as $d): ?>
              <tr class="<?= $d['active'] ? '' : 'text-muted' ?>">
                <td class="fw-semibold"><?= h($d['label']) ?><?= $d['active'] ? '' : ' <span class="badge bg-secondary-subtle text-secondary-emphasis">Off</span>' ?></td>
                <td><?= h($d['person'] ?? '') ?></td>
                <td><code><?= h(bc_key_hint((string)$d['api_key'])) ?></code></td>
                <td class="small">
                  <?php if ($d['last_sync_at']): ?>
                    <?= h(date('j M g:i A', strtotime($d['last_sync_at']))) ?>
                    <i class="bi <?= $d['last_sync_ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
                    <div class="text-muted"><?= h($d['last_sync_note'] ?? '') ?></div>
                  <?php else: ?><span class="text-muted">Not yet</span><?php endif; ?>
                </td>
                <td class="text-nowrap">
                  <form method="post" class="d-inline m-0" onsubmit="this.querySelector('button').disabled = true; this.querySelector('span').textContent = 'Syncing…';">
                    <?php csrf_field(); ?><input type="hidden" name="do" value="sync"><input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="back" value="tab=setup">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i><span>Sync now</span></button>
                  </form>
                  <button type="button" class="btn btn-sm btn-outline-secondary bc-dev" data-dev="<?= h(json_encode(['id' => (int)$d['id'], 'user_id' => (int)$d['user_id'], 'label' => $d['label'], 'active' => (int)$d['active']])) ?>">Edit</button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card card-round mb-3">
    <div class="card-body">
      <div class="fw-semibold fs-5 mb-2">Keep the report</div>
      <form method="post" class="d-flex flex-wrap align-items-center gap-2">
        <?php csrf_field(); ?><input type="hidden" name="do" value="keep"><input type="hidden" name="back" value="tab=setup">
        <span>Keep the daily report for</span>
        <input type="number" name="keep_days" value="<?= (int)$keep ?>" min="7" max="3650" class="form-control form-control-sm" style="width:90px">
        <span>days, then delete it from saifsys. Looki's own photos and videos are not touched.</span>
        <button class="btn btn-sm btn-primary">Save</button>
      </form>
    </div>
  </div>

  <div class="card card-round mb-3">
    <div class="card-body small text-muted">
      <div class="fw-semibold text-body mb-1">How recordings arrive</div>
      The L1 uploads to Looki's cloud while it charges on Wi-Fi (Looki app → Data Control: Upload to Cloud ON).
      The photos and videos stay in Looki's cloud: saifsys keeps only the daily report and opens each one from Looki with the API key.
      Every 30 minutes this server reads the new recordings. On the live server that is a cron job:
      <code>*/30 * * * * /usr/bin/php /home/&lt;user&gt;/public_html/cron/body_camera_sync.php</code>.
      Looki's audio summary is not kept, only a sentence that names a store item or the store.
    </div>
  </div>

  <div class="modal fade" id="bcDevModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
      <form method="post" autocomplete="off">
        <?php csrf_field(); ?><input type="hidden" name="do" value="device"><input type="hidden" name="device_id" value="0"><input type="hidden" name="back" value="tab=setup">
        <div class="modal-header"><h5 class="modal-title" id="bcDevTitle">Add L1</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Name</label>
            <input type="text" name="label" maxlength="80" class="form-control" placeholder="Looki L1 (51289)" required></div>
          <div class="mb-3"><label class="form-label">Worn by</label>
            <select name="user_id" class="form-select" required><option value="">Choose…</option>
              <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h(($u['fullname'] ?: $u['username']) . ($u['company_name'] ? ' · ' . $u['company_name'] : '')) ?></option><?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label">Looki API key</label>
            <input type="password" name="api_key" class="form-control" autocomplete="new-password" spellcheck="false">
            <div class="form-text" id="bcKeyHelp">From web.looki.ai → API keys. It is never shown again here.</div></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" id="bcDevActive"><label class="form-check-label" for="bcDevActive">On (fetch its recordings)</label></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
      </form>
    </div></div>
  </div>
  <script>
  (function () {
    const m = document.getElementById('bcDevModal');
    document.querySelectorAll('.bc-dev').forEach(function (b) {
      b.addEventListener('click', function () {
        const d = JSON.parse(b.dataset.dev);
        m.querySelector('[name=device_id]').value = d.id;
        m.querySelector('[name=label]').value = d.label;
        m.querySelector('[name=user_id]').value = d.user_id || '';
        m.querySelector('[name=api_key]').value = '';
        m.querySelector('[name=api_key]').required = !d.id;
        m.querySelector('[name=active]').checked = !!d.active;
        document.getElementById('bcDevTitle').textContent = d.id ? 'Edit L1' : 'Add L1';
        document.getElementById('bcKeyHelp').textContent = d.id ? 'Leave empty to keep the current key.' : 'From web.looki.ai → API keys. It is never shown again here.';
        bootstrap.Modal.getOrCreateInstance(m).show();
      });
    });
  })();
  </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
