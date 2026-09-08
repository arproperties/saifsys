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
    ];

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
            $stmt = $conn->prepare("
                UPDATE ops_jobs
                SET job_type = ?, title = ?, location = ?, description = ?, assigned_to = ?,
                    scheduled_date = ?, scheduled_time = ?, priority = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $data['job_type'], $data['title'], $data['location'] ?: null, $data['description'] ?: null,
                $data['assigned_to'], $data['scheduled_date'], $data['scheduled_time'] ?: null,
                $data['priority'], $jobId, $companyId,
            ]);
            ops_flash('Job updated.');
            header('Location: ' . $opsBase . '/job_view.php?id=' . $jobId);
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO ops_jobs
                (company_id, job_type, title, location, description, assigned_to,
                 scheduled_date, scheduled_time, priority, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)
        ");
        $stmt->execute([
            $companyId, $data['job_type'], $data['title'], $data['location'] ?: null,
            $data['description'] ?: null, $data['assigned_to'], $data['scheduled_date'],
            $data['scheduled_time'] ?: null, $data['priority'], $userId,
        ]);
        ops_flash('Job created.');
        header('Location: ' . $opsBase . '/job_view.php?id=' . (int)$conn->lastInsertId());
        exit;
    }

    // Keep what they typed on the form after a validation error.
    $job = array_merge($job ?? [], $data);
}

$people = ops_assignable_users($conn, $companyId);
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

      <div class="col-md-6">
        <label class="form-label fw-semibold">Who does it?</label>
        <select name="assigned_to" class="form-select">
          <option value="">Decide later</option>
          <?php foreach ($people as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)($job['assigned_to'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
              <?= h($p['fullname'] ?: $p['username']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label fw-semibold">Due date</label>
        <input type="date" name="scheduled_date" class="form-control"
               value="<?= h($job['scheduled_date'] ?? date('Y-m-d')) ?>" required>
      </div>

      <div class="col-md-3">
        <label class="form-label fw-semibold">Time <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="time" name="scheduled_time" class="form-control"
               value="<?= h($job['scheduled_time'] ?? '') ?>">
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
