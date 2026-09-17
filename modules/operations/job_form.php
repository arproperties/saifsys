<?php
/**
 * Operations — create or edit a job. Supervisors only.
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
require_operations_supervisor_department($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$companyId = ops_company_id($conn);
$userId = (int)current_user_id();

$jobId = (int)($_GET['id'] ?? 0);

// CREATING A JOB HERE IS CLOSED — EDITING ONE IS NOT
// --------------------------------------------------
// Jobs are raised on the phone now, by whoever is standing at the site. The
// office used to enter them in advance and hand them out, and that only works
// if you know on Monday where each person will be on Thursday: staff get moved
// between sites all week, so the schedule was wrong by Tuesday and somebody
// had to go and correct it. POST ops/jobs in api/mobile/ops/index.php is where
// a job begins now.
//
// The create path below is left intact rather than deleted. Nothing reaches
// it while this guard stands, and removing these five lines is all it takes to
// have the office able to enter a job again.
if ($jobId <= 0) {
    ops_flash('Staff create their own jobs in the app now. This page edits a job that already exists.', 'info');
    header('Location: ' . $opsBase . '/index.php');
    exit;
}

$job = null;
if ($jobId > 0) {
    $job = ops_load_job($conn, $jobId, $companyId);
    if (!$job) {
        ops_flash('That job could not be found.', 'danger');
        header('Location: ' . $opsBase . '/index.php');
        exit;
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data = [
        'job_type' => (string)($_POST['job_type'] ?? 'cleaning'),
        'title' => trim((string)($_POST['title'] ?? '')),
        'location' => trim((string)($_POST['location'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'assigned_to' => ($_POST['assigned_to'] ?? '') !== '' ? (int)$_POST['assigned_to'] : null,
        'scheduled_date' => (string)($_POST['scheduled_date'] ?? ''),
        'scheduled_time' => trim((string)($_POST['scheduled_time'] ?? '')),
        'priority' => (string)($_POST['priority'] ?? 'normal'),
        'repeat_daily' => !empty($_POST['repeat_daily']) ? 1 : 0,
    ];

    // Who does it is not asked for on the way in any more — it moves around too
    // often to be worth setting here. A new job is always made unassigned and
    // picked up on the edit screen.
    if (!$job) {
        $data['assigned_to'] = null;
    }

    // A generated day is not where the rule lives — the head job is. Editing
    // Tuesday's copy must not quietly start a second series off the same days.
    $isGeneratedCopy = $job && ops_job_in_series($job) && !ops_job_is_repeat_head($job);
    if ($isGeneratedCopy) {
        $data['repeat_daily'] = (int)($job['repeat_daily'] ?? 0);
    }

    if ($data['title'] === '') {
        $errors[] = 'Give the job a short name, e.g. "Deep clean — Office 4".';
    }
    if (!array_key_exists($data['job_type'], ops_job_types())) {
        $errors[] = 'Choose cleaning or maintenance.';
    }
    if (!array_key_exists($data['priority'], ops_priorities())) {
        $data['priority'] = 'normal';
    }
    if ($data['scheduled_date'] === '' || !strtotime($data['scheduled_date'])) {
        $errors[] = 'Choose the date this job is due.';
    }

    if (!$errors) {
        if ($job) {
            // series_id is set on the way in, not left to a second write: a
            // head with repeat_daily = 1 and no series_id would be invisible to
            // the generator, which is the one bug worth designing out here.
            $stmt = $conn->prepare("
                UPDATE ops_jobs
                SET job_type = ?, title = ?, location = ?, description = ?, assigned_to = ?,
                    scheduled_date = ?, scheduled_time = ?, priority = ?,
                    repeat_daily = ?,
                    series_id = CASE WHEN ? = 1 THEN COALESCE(series_id, id) ELSE series_id END
                WHERE id = ? AND company_id = ?
            ");
            try {
                $stmt->execute([
                    $data['job_type'], $data['title'], $data['location'] ?: null, $data['description'] ?: null,
                    $data['assigned_to'], $data['scheduled_date'], $data['scheduled_time'] ?: null,
                    $data['priority'], $data['repeat_daily'],
                    $data['repeat_daily'], $jobId, $companyId,
                ]);
            } catch (PDOException $e) {
                // One day per series is the rule the unique key enforces. Moving
                // a day onto a date the series already covers is the only way an
                // ordinary edit can hit it, so say that instead of a 500.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $errors[] = 'This repeating job already has a day on '
                          . date('D d M Y', strtotime($data['scheduled_date']))
                          . '. Pick another date.';
            }

            if (!$errors) {
                if ($data['repeat_daily']) {
                    ops_generate_daily_jobs($conn, $companyId);
                }
                ops_flash('Job updated.');
                header('Location: ' . $opsBase . '/job_view.php?id=' . $jobId);
                exit;
            }
        }

        // An edit that fell to the duplicate-day check above must not carry on
        // into the insert and quietly make a second job.
        if (!$job) {
            $stmt = $conn->prepare("
                INSERT INTO ops_jobs
                    (company_id, job_type, title, location, description, assigned_to,
                     scheduled_date, scheduled_time, priority, status, created_by,
                     repeat_daily)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)
            ");
            $stmt->execute([
                $companyId, $data['job_type'], $data['title'], $data['location'] ?: null,
                $data['description'] ?: null, $data['assigned_to'], $data['scheduled_date'],
                $data['scheduled_time'] ?: null, $data['priority'], $userId,
                $data['repeat_daily'],
            ]);
            $newId = (int)$conn->lastInsertId();

            // A repeating job is the head of its own series. It can only point at
            // itself after the insert, because that is when it has an id.
            if ($data['repeat_daily']) {
                $conn->prepare("UPDATE ops_jobs SET series_id = id WHERE id = ? AND company_id = ?")
                     ->execute([$newId, $companyId]);
                // Backdated first day? Then the days since are owed already.
                ops_generate_daily_jobs($conn, $companyId);
        }

        ops_flash($data['repeat_daily']
            ? 'Job created. It will be created again every day from now on.'
            : 'Job created.');
        header('Location: ' . $opsBase . '/job_view.php?id=' . $newId);
        exit;
        }
    }

    // Keep what they typed on the form after a validation error.
    $job = array_merge($job ?? [], $data);
}

// Only the edit screen asks who does it, so only the edit screen needs the list.
$people = $jobId ? ops_assignable_users($conn, $companyId) : [];
$pageTitle = $jobId ? 'Edit job' : 'New job';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<a href="<?= h($jobId ? $opsBase . '/job_view.php?id=' . $jobId : $opsBase . '/index.php') ?>" class="text-decoration-none small">
  <i class="bi bi-arrow-left"></i> Back
</a>
<div class="page-header-label mt-2 mb-4"><?= $jobId ? 'Edit job' : 'New job' ?></div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<style>
  /* Off, this has to look like something you can press. Bootstrap paints a
     grey knob on a white track, which reads as disabled rather than off. */
  .ops-repeat-toggle{ cursor:pointer; padding-left:4rem; min-height:0; }
  .ops-repeat-box .form-check-input{
    width:3.25rem; height:1.75rem; margin-left:-4rem; margin-top:.05rem;
    background-color:#ced4da; border:1px solid #adb5bd; cursor:pointer;
    background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23fff'/%3e%3c/svg%3e");
    box-shadow:inset 0 1px 2px rgba(0,0,0,.12);
  }
  .ops-repeat-box .form-check-input:hover{ background-color:#b9c0c8; }
  .ops-repeat-box .form-check-input:checked{
    background-color:var(--primary); border-color:var(--primary); box-shadow:none;
  }
  .ops-repeat-box .form-check-input:focus-visible{
    outline:3px solid rgba(13,110,253,.35); outline-offset:2px;
  }
  /* On, the panel says so without anyone having to read the switch. */
  .ops-repeat-box{ transition:border-color .15s, background-color .15s; }
  .ops-repeat-box:hover{ background-color:#f8f9fa; }
  .ops-repeat-box.is-on{ border-color:var(--primary)!important; background-color:rgba(13,110,253,.04); }
</style>

<div class="card card-round" style="max-width:720px">
  <div class="card-body">
    <form method="post" class="row g-3">
      <?php csrf_field(); ?>

      <div class="col-12">
        <label class="form-label fw-semibold">What is the job?</label>
        <input type="text" name="title" class="form-control form-control-lg"
               value="<?= h($job['title'] ?? '') ?>" placeholder="e.g. AC service — Villa 12" required autofocus>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Type</label>
        <select name="job_type" class="form-select form-select-lg">
          <?php foreach (ops_job_types() as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= ($job['job_type'] ?? 'cleaning') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Priority</label>
        <select name="priority" class="form-select form-select-lg">
          <?php foreach (ops_priorities() as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= ($job['priority'] ?? 'normal') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Where?</label>
        <input type="text" name="location" class="form-control"
               value="<?= h($job['location'] ?? '') ?>" placeholder="Building, unit, site or area">
      </div>

<?php // Who does it is an edit-screen question. Assignments change often enough
      // that fixing one at creation only means coming back to change it. New jobs
      // go out unassigned; the field is still here on edit for whoever picks it up.
      $dateCol = $jobId ? 'col-md-3' : 'col-md-6'; ?>
      <?php if ($jobId): ?>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Who does it?</label>
        <select name="assigned_to" class="form-select">
          <option value="">Decide later</option>
          <?php foreach (ops_people_by_company($people) as $companyName => $group): ?>
            <optgroup label="<?= h($companyName) ?>">
              <?php foreach ($group as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)($job['assigned_to'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= h($p['fullname'] ?: $p['username']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="<?= $dateCol ?>">
        <label class="form-label fw-semibold">Due date</label>
        <input type="date" name="scheduled_date" class="form-control"
               value="<?= h($job['scheduled_date'] ?? date('Y-m-d')) ?>" required>
      </div>

      <div class="<?= $dateCol ?>">
        <label class="form-label fw-semibold">Time <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="time" name="scheduled_time" class="form-control"
               value="<?= h($job['scheduled_time'] ?? '') ?>">
      </div>

<?php
      // Where the whole feature lives on this screen: one tick box. The
      // supervisor sets up today's clean and never opens a second page — every
      // following day is made from this job as it stands.
      $formIsGeneratedCopy = $jobId && ops_job_in_series($job ?? []) && !ops_job_is_repeat_head($job ?? []);
      ?>
      <div class="col-12">
        <?php if ($formIsGeneratedCopy): ?>
          <div class="alert alert-light border mb-0">
            <i class="bi bi-arrow-repeat"></i>
            This is one day of a repeating job. Changing it here only changes this day —
            to change every day from now on, open the
            <a href="<?= h($opsBase) ?>/job_form.php?id=<?= (int)ops_repeat_head_id($job) ?>">job it repeats from</a>.
          </div>
        <?php else: ?>
          <div class="border rounded-3 p-3 ops-repeat-box <?= !empty($job['repeat_daily']) ? 'is-on' : '' ?>"
               id="repeatBox">
            <?php // The whole row is the target, not the 20px switch: this gets
                  // used on a laptop trackpad in an office, in a hurry. ?>
            <label class="form-check form-switch ops-repeat-toggle mb-0" for="repeatDaily">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="repeatDaily" name="repeat_daily" value="1"
                     <?= !empty($job['repeat_daily']) ? 'checked' : '' ?>
                     onchange="document.getElementById('repeatBox').classList.toggle('is-on', this.checked);">
              <span class="fw-semibold d-block">Repeat this job every day</span>
              <span class="text-muted small d-block">
                For work that comes round daily — the morning clean, the bin run.
                The same job is created again each day<?= $jobId ? ', for the same person' : '' ?>.
              </span>
            </label>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Instructions <span class="text-muted fw-normal small">(optional)</span></label>
        <textarea name="description" class="form-control" rows="3"
                  placeholder="Anything the technician or cleaner needs to know"><?= h($job['description'] ?? '') ?></textarea>
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-lg text-white" style="background:var(--primary)">
          <i class="bi bi-check-lg"></i> <?= $jobId ? 'Save changes' : 'Create job' ?>
        </button>
        <a href="<?= h($jobId ? $opsBase . '/job_view.php?id=' . $jobId : $opsBase . '/index.php') ?>" class="btn btn-lg btn-light">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
