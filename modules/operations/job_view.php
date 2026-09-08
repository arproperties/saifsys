<?php
/**
 * Operations — one job: time, before/after photos, comments.
 * The office view. Starting, finishing and uploading files are the field
 * staff's own actions and live only in the app.
 *
 * Materials are not tracked per job. Someone on site asks for what they need in
 * the conversation below; the office hands it over and takes it off the Stock
 * page. That stock movement is the whole record.
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
    $mediaStmt->execute([$jobId, $companyId]);
    foreach ($mediaStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $commentMedia[(int)$row['comment_id']][] = $row;
    }
}

$returnTo = $opsBase . '/job_view.php?id=' . $jobId;
$isOpen = in_array($job['status'], ['open', 'in_progress'], true);

$pageTitle = $job['title'];
$jobIsLate = ops_job_is_late($job);

// Repeating work: the rule lives on the head job, so a generated day shows the
// same badge but sends "stop" to the job it came from.
$jobRepeats = ops_job_in_series($job);
$jobIsRepeatHead = ops_job_is_repeat_head($job);
$jobHeadId = ops_repeat_head_id($job);
$jobRepeatOn = !empty($job['repeat_daily']);
$jobHeadTitle = (string)$job['title'];
if ($jobRepeats && !$jobIsRepeatHead) {
    // A generated day carries no rule of its own — read it off the head so the
    // page can say "still repeating" or "stopped" without lying.
    $headStmt = $conn->prepare("
        SELECT title, repeat_daily
        FROM ops_jobs WHERE id = ? AND company_id = ? LIMIT 1
    ");
    $headStmt->execute([$jobHeadId, $companyId]);
    $headRow = $headStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $jobRepeatOn = !empty($headRow['repeat_daily']);
    $jobHeadTitle = (string)($headRow['title'] ?? $job['title']);
}

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
      <span class="badge bg-<?= h(ops_priority_color($job['priority'])) ?>"><?= h(ops_priorities()[$job['priority']]) ?> priority</span>
      <?php if ($jobIsLate): ?>
        <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Late</span>
      <?php endif; ?>
      <?php if ($jobRepeats): ?>
        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
          <i class="bi bi-arrow-repeat"></i> Daily
        </span>
      <?php endif; ?>
      <?php if (!empty($job['location'])): ?>
        <span class="ms-2"><i class="bi bi-geo-alt"></i> <?= h($job['location']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= h($opsBase) ?>/job_form.php?id=<?= (int)$job['id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Edit</a>
    <form method="post" action="<?= h($opsBase) ?>/job_action.php" onsubmit="return confirm(<?= $jobIsRepeatHead && $jobRepeatOn
        ? "'Delete this job and everything attached to it? It is the job the daily copies are made from, so the repeat stops too.'"
        : "'Delete this job and everything attached to it?'" ?>);">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="delete_job">
      <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
      <button class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
    </form>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">

    <!-- Assignment -->
    <div class="card card-round mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-person-check"></i> Who does it</h6>
        <?php $jobPeople = ops_assignable_users($conn, $companyId); ?>
        <form method="post" action="<?= h($opsBase) ?>/job_action.php">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
          <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
          <div class="d-flex gap-2 align-items-center">
            <select name="assigned_to" class="form-select" style="flex:0 1 320px; width:auto; min-width:0">
              <option value="">Nobody yet</option>
              <?php foreach ($jobPeople as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$job['assigned_to'] === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= h($p['fullname'] ?: $p['username']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-secondary flex-shrink-0">Save</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Repeating work. Only shown when there is a series to say something about. -->
    <?php if ($jobRepeats): ?>
    <div class="card card-round mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-arrow-repeat"></i> Repeats</h6>
        <?php if ($jobIsRepeatHead): ?>
          <p class="mb-3">
            <?php if ($jobRepeatOn): ?>
              This job is created again <strong>every day</strong>, until somebody stops it
              with the button below. Each day copies this job as it stands, so edit it here
              to change tomorrow onwards — days already created keep what they were given.
            <?php else: ?>
              This job used to repeat daily. It has been stopped, so no new days are being created.
            <?php endif; ?>
          </p>
          <form method="post" action="<?= h($opsBase) ?>/job_action.php"
                <?= $jobRepeatOn ? 'onsubmit="return confirm(\'Stop creating this job every day? Days already created stay where they are.\');"' : '' ?>>
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="set_repeat">
            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
            <input type="hidden" name="repeat_daily" value="<?= $jobRepeatOn ? '0' : '1' ?>">
            <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
            <button class="btn btn-sm <?= $jobRepeatOn ? 'btn-outline-danger' : 'btn-outline-secondary' ?>">
              <i class="bi bi-<?= $jobRepeatOn ? 'stop-circle' : 'play-circle' ?>"></i>
              <?= $jobRepeatOn ? 'Stop repeating' : 'Start repeating again' ?>
            </button>
          </form>
        <?php else: ?>
          <p class="mb-2">
            One day of a daily job. It was created automatically from
            <a href="<?= h($opsBase) ?>/job_view.php?id=<?= (int)$jobHeadId ?>"><?= h($jobHeadTitle) ?></a>.
          </p>
          <p class="text-muted small mb-0">
            <?= $jobRepeatOn
                ? 'Still repeating — anything changed here applies to this day only.'
                : 'The repeat has been stopped; no further days will be created.' ?>
          </p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

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

  <!-- Right column -->
  <div class="col-lg-4">

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
      .ops-msg { max-width: 85%; padding: .5rem .75rem; border-radius: .75rem;
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
          <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">

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
</div>

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

<script>
// Everything below runs on DOMContentLoaded, not inline: the Bootstrap bundle
// is loaded by the layout footer further down the page, so `bootstrap` does not
// exist yet at this point in the parse and the viewer would silently never open.
document.addEventListener('DOMContentLoaded', function () {

  (function () {
    var thread = document.getElementById('opsThread');
    if (thread) { thread.scrollTop = thread.scrollHeight; }
  })();

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
