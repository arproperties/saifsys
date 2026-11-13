<?php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/../includes/hr_attendance_attachments.php';
require_once __DIR__ . '/includes/hr_attendance_cameras.php';
require_once __DIR__ . '/includes/hr_attendance_lock.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: attendance.php'); exit; }

$me_id = $_SESSION['user']['id'] ?? null;

$flash_err = $flash_ok = '';
$flash_warn = [];

/* Load row */
$stmt = $conn->prepare("
SELECT a.*, e.full_name, e.employee_code
  FROM attendance a
  JOIN employees e ON e.id=a.employee_id
 WHERE a.id=?
");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { header('Location: attendance.php'); exit; }

// Offer only what this database can store, but never drop the status the row
// already holds — that would silently rewrite it on save.
$statuses = hr_attendance_statuses();
foreach (array_keys($statuses) as $key) {
    if ($key !== ($row['status'] ?? '') && !hr_attendance_status_supported($conn, $key)) {
        unset($statuses[$key]);
    }
}
$attAccept = '.' . implode(',.', hr_attendance_attach_allowed_extensions());

/* A POST over post_max_size arrives empty — check before CSRF so the message fits. */
if (hr_attendance_post_too_large()) {
    $flash_err = 'The upload was larger than the server allows (' . htmlspecialchars(hr_attendance_post_max_label(), ENT_QUOTES, 'UTF-8') . '). Nothing was saved.';
} elseif ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();

    /* Remove one attachment */
    if (($_POST['action'] ?? '') === 'delete_attachment') {
        $attachmentId = (int)($_POST['attachment_id'] ?? 0);
        $owned = false;
        if ($attachmentId > 0) {
            $chk = $conn->prepare("SELECT 1 FROM attendance_attachments WHERE id = ? AND attendance_id = ?");
            $chk->execute([$attachmentId, $id]);
            $owned = (bool)$chk->fetchColumn();
        }
        if ($owned && hr_attendance_attachment_delete($conn, $attachmentId)) {
            $flash_ok = 'Attachment removed.';
        } else {
            $flash_err = 'That attachment could not be removed.';
        }
    } else {
        $work_date = $_POST['work_date'] ?? $row['work_date'];
        $check_in  = ($_POST['check_in']  ?? '') !== '' ? $_POST['check_in']  : null;
        $check_out = ($_POST['check_out'] ?? '') !== '' ? $_POST['check_out'] : null;
        $status    = $_POST['status'] ?? $row['status'];
        $notes     = trim($_POST['notes'] ?? '');
        $files     = $_FILES['attachments'] ?? null;
        // Break Start / End: only where the break columns exist (migrations/hr_attendance_break.sql).
        $hasBreak   = array_key_exists('break_start', $row);
        $breakStart = $hasBreak && ($_POST['break_start'] ?? '') !== '' ? substr((string)$_POST['break_start'], 0, 5) : null;
        $breakEnd   = $hasBreak && ($_POST['break_end'] ?? '') !== '' ? substr((string)$_POST['break_end'], 0, 5) : null;

        $statusChanged = $status !== ($row['status'] ?? '');
        // On a locked day the reason must be new text, not the note already on the row.
        $lockReason = $notes !== trim((string)($row['notes'] ?? '')) ? $notes : '';
        $lockMsg = hr_attendance_write_block($conn, (int)$row['employee_id'], (string)$row['work_date'], 'change', $lockReason);
        if (!$lockMsg && $work_date !== $row['work_date']) {
            $lockMsg = hr_attendance_write_block($conn, (int)$row['employee_id'], (string)$work_date, 'change', $lockReason);
        }

        if ($lockMsg) {
            $flash_err = $lockMsg;
        } elseif (!array_key_exists($status, $statuses)) {
            $flash_err = 'Invalid status.';
        } elseif ($breakEnd !== null && $breakStart === null) {
            $flash_err = 'Break End needs a Break Start.';
        } elseif ($breakStart !== null && $breakEnd !== null && $breakEnd < $breakStart) {
            $flash_err = 'Break End (' . $breakEnd . ') cannot be before Break Start (' . $breakStart . ').';
        } elseif ($statusChanged && $notes === '') {
            // Same rule as the quick actions on the list page: no silent changes.
            $flash_err = 'Changing the status needs a reason in Notes.';
        } elseif (hr_attendance_same_time($check_in, $check_out, $status)) {
            $flash_err = 'Check-out cannot be the same time as check-in (' . substr($check_in, 0, 5) . ').';
        } else {
            // recalc hours (an Out before the In is the next morning)
            $hours = hr_attendance_no_hours_status($status) ? null : hr_attendance_hours($check_in, $check_out);

            try {
                $notes = mb_substr($notes, 0, HR_ATTENDANCE_NOTE_MAX);
                $u = $conn->prepare("
                    UPDATE attendance
                       SET work_date=?, check_in=?, check_out=?, hours=?, status=?, notes=?, updated_by=?, updated_at=NOW()
                     WHERE id=?
                ");
                $u->execute([$work_date,$check_in,$check_out,$hours,$status,$notes,$me_id,$id]);
                // The break is recorded only, never taken off the hours above.
                $breakMins = null;
                if ($hasBreak) {
                    $breakMins = ($breakStart !== null && $breakEnd !== null)
                        ? (int)round((strtotime('2000-01-01 ' . $breakEnd) - strtotime('2000-01-01 ' . $breakStart)) / 60)
                        : null;
                    $conn->prepare("UPDATE attendance SET break_start = ?, break_end = ?, break_minutes = ? WHERE id = ?")
                         ->execute([$breakStart, $breakEnd, $breakMins, $id]);
                }
                $flash_ok = 'Saved.';

                $changeId = 0;
                if ($statusChanged) {
                    $changeId = hr_attendance_log_status_change(
                        $conn, $id, $row['status'] ?? null, $status, $notes, $row['notes'] ?? null,
                        $me_id ? (int)$me_id : null
                    );
                }

                if (hr_attendance_files_chosen($files)) {
                    $uploadErrors = [];
                    $saved = hr_attendance_attachments_save(
                        $conn, $id, $changeId, $me_id ? (int)$me_id : null, (array)$files, $uploadErrors
                    );
                    if ($saved > 0) {
                        $flash_ok = 'Saved with ' . $saved . ' attachment' . ($saved === 1 ? '' : 's') . '.';
                    } else {
                        $flash_warn[] = 'The change saved but no file could be stored — attach the document again.';
                    }
                    foreach ($uploadErrors as $ue) { $flash_warn[] = $ue; }
                }

                $empLabel = trim(($row['full_name'] ?? '') . ' (' . ($row['employee_code'] ?? '') . ')');
                $cidSt = $conn->prepare("SELECT company_id FROM employees WHERE id = ? LIMIT 1");
                $cidSt->execute([(int)$row['employee_id']]);
                $empCompanyId = (int)($cidSt->fetchColumn() ?: 0);
                audit_bridge_hr_ops(
                    'attendance_updated',
                    'attendance',
                    $id,
                    'Updated attendance for ' . $empLabel . ' on ' . $work_date . ' to ' . $status
                        . ($statusChanged && $notes !== '' ? ' — ' . $notes : ''),
                    $empCompanyId > 0 ? $empCompanyId : null,
                    [
                        'employee_id' => (int)$row['employee_id'],
                        'work_date' => $work_date,
                        'status' => $status,
                        'check_in' => $check_in,
                        'check_out' => $check_out,
                        'hours' => $hours,
                        'break_start' => $breakStart,
                        'break_end' => $breakEnd,
                        'break_minutes' => $breakMins,
                        'from_status' => $row['status'] ?? null,
                        'note' => $statusChanged ? $notes : null,
                    ],
                    $empLabel . ' @ ' . $work_date,
                    $me_id ? (int)$me_id : null
                );
            } catch (PDOException $e) {
                $flash_err = 'DB error: '.$e->getMessage();
            }
        }
    }

    // reload fresh
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { header('Location: attendance.php'); exit; }
}

$attachments = hr_attendance_attachments_for($conn, $id);
$history     = hr_attendance_status_history($conn, $id);

$pageTitle = 'Edit Attendance';
$pageStyles = hr_attendance_status_css();
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Edit Attendance',
    h($row['full_name']) . ' (' . h($row['employee_code']) . ')',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Attendance', 'href' => 'attendance.php'],
        ['label' => 'Edit #' . (int)$row['id']],
    ],
    '<a class="btn btn-outline-secondary" href="attendance.php"><i class="bi bi-arrow-left me-1"></i>Back</a>'
);
?>

  <?php if ($flash_err): ?><div class="alert alert-danger"><?= h($flash_err) ?></div><?php endif; ?>
  <?php if ($flash_ok):  ?><div class="alert alert-success"><?= h($flash_ok)  ?></div><?php endif; ?>
  <?php if ($flash_warn): ?>
    <div class="alert alert-warning"><ul class="mb-0 small"><?php foreach ($flash_warn as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php
    $exitDay = hr_attendance_exit_day($conn, (int)$row['employee_id']);
    $afterExit = $exitDay !== null && $row['work_date'] > $exitDay;
    $dayLocked = hr_attendance_date_locked((string)$row['work_date']);
  ?>
  <?php if ($afterExit): ?>
    <div class="alert alert-danger"><strong>Locked:</strong> this day is after the employee's last working day (<?= h(date('j M Y', strtotime($exitDay))) ?>). It cannot be changed. An Owner can delete it from the Attendance list.</div>
  <?php elseif ($dayLocked && !hr_attendance_lock_admin($conn)): ?>
    <div class="alert alert-warning"><strong>Locked:</strong> attendance older than <?= HR_ATTENDANCE_LOCK_DAYS ?> days can only be changed by an Owner.</div>
  <?php elseif ($dayLocked): ?>
    <div class="alert alert-warning"><strong>Older than <?= HR_ATTENDANCE_LOCK_DAYS ?> days:</strong> you can change it as an Owner, but write the reason in Notes.</div>
  <?php endif; ?>

  <div class="hr-settings-card">
    <div class="card-body">
      <form method="post" class="row g-3" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="hidden" name="current_status" value="<?= h($row['status']) ?>">
        <div class="col-md-3">
          <label class="form-label">Date</label>
          <input type="date" name="work_date" class="form-control" value="<?= h($row['work_date']) ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Check-in</label>
          <input type="time" name="check_in" class="form-control" value="<?= h($row['check_in']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Check-out</label>
          <input type="time" name="check_out" class="form-control" value="<?= h($row['check_out']) ?>">
          <div class="form-text">Earlier than check-in = next day (night shift).</div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" id="attEditStatus" class="form-select" data-current="<?= h($row['status']) ?>">
            <?php foreach ($statuses as $k=>$v): ?>
              <option value="<?= h($k) ?>" <?= $row['status']===$k?'selected':''; ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Current: <?= hr_attendance_status_badge_html($row['status']) ?></div>
        </div>
        <?php if (array_key_exists('break_start', $row)): ?>
        <div class="col-md-3">
          <label class="form-label">Break Start</label>
          <input type="time" name="break_start" class="form-control" value="<?= h(substr((string)$row['break_start'], 0, 5)) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Break End</label>
          <input type="time" name="break_end" class="form-control" value="<?= h(substr((string)$row['break_end'], 0, 5)) ?>">
        </div>
        <div class="col-md-6 d-flex align-items-end">
          <div class="form-text mb-2">
            <?= $row['break_minutes'] !== null ? 'Break was ' . (int)$row['break_minutes'] . ' min. ' : '' ?>Empty both to remove the break. The break is recorded only; it is not taken off the hours.
          </div>
        </div>
        <?php endif; ?>
        <div class="col-md-12">
          <label class="form-label">Notes <span class="text-danger d-none" id="attEditNotesReq">*</span></label>
          <input type="text" name="notes" id="attEditNotes" class="form-control"
                 maxlength="<?= (int)HR_ATTENDANCE_NOTE_MAX ?>" value="<?= h($row['notes']) ?>">
          <div class="form-text">Changing the status requires a reason here. Documents below are optional.</div>
        </div>
        <div class="col-md-12">
          <label class="form-label">Attach documents</label>
          <input type="file" name="attachments[]" id="attEditFiles" class="form-control" multiple accept="<?= h($attAccept) ?>">
          <div class="form-text">
            PDF, image, Word or Excel — max <?= (int)(HR_ATTENDANCE_ATTACH_MAX_BYTES / 1024 / 1024) ?> MB each,
            <?= (int)HR_ATTENDANCE_ATTACH_MAX_FILES ?> files per save.
          </div>
        </div>
        <div class="col-12 d-flex justify-content-end">
          <button class="btn btn-primary px-4">Save</button>
        </div>
      </form>
    </div>
  </div>

  <?php
    // Camera arrival / departure photos: the HR role only.
    $camPhotos = has_role('HR', $conn) ? hr_camera_photos_for((int)$row['id']) : ['in' => false, 'out' => false];
  ?>
  <?php if ($camPhotos['in'] || $camPhotos['out']): ?>
  <div class="hr-settings-card mt-3">
    <div class="settings-header">Camera photos</div>
    <div class="card-body">
      <div class="row g-3">
        <?php foreach (['in' => 'Check-in', 'out' => 'Check-out'] as $k => $label): ?>
          <div class="col-sm-6 text-center">
            <div class="fw-semibold mb-2"><?= $label ?></div>
            <?php if ($camPhotos[$k]): ?>
              <img src="attendance_camera_photo.php?id=<?= (int)$row['id'] ?>&amp;which=<?= $k ?>&amp;t=<?= time() ?>"
                   class="img-fluid rounded border" style="max-height:320px" alt="<?= $label ?> photo">
            <?php else: ?>
              <div class="text-muted small py-5 border rounded">No photo</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="hr-settings-card mt-3">
    <div class="settings-header">Attachments</div>
    <div class="card-body">
      <?php if (!$attachments): ?>
        <p class="text-muted mb-0">No documents on this record yet.</p>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($attachments as $a): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
              <span>
                <i class="bi <?= h(hr_attendance_attach_icon((string)$a['file_name'])) ?> me-2"></i>
                <a href="<?= h(hr_attendance_attach_href((int)$a['id'])) ?>" target="_blank" rel="noopener"><?= h($a['file_name']) ?></a>
                <span class="text-muted small ms-2"><?= h(hr_attendance_attach_size((int)$a['file_size'])) ?> — <?= h($a['uploaded_at']) ?></span>
              </span>
              <span>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h(hr_attendance_attach_href((int)$a['id'], 'download')) ?>">Download</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Remove this attachment?')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_attachment">
                  <input type="hidden" name="attachment_id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Remove</button>
                </form>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="hr-settings-card mt-3">
    <div class="settings-header">Status history</div>
    <div class="card-body p-0">
      <?php if (!$history): ?>
        <p class="text-muted mb-0 p-3">No recorded status changes.</p>
      <?php else: ?>
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th>When</th><th>From</th><th>To</th><th>Reason</th><th>By</th></tr>
          </thead>
          <tbody>
            <?php foreach ($history as $c): ?>
              <tr>
                <td class="text-nowrap"><?= h($c['changed_at']) ?></td>
                <td><?= hr_attendance_status_badge_html($c['from_status']) ?></td>
                <td><?= hr_attendance_status_badge_html($c['to_status']) ?></td>
                <td><?= h($c['note']) ?></td>
                <td><?= h($c['changed_by_name'] ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  const sel = document.getElementById('attEditStatus');
  if (!sel) return;
  const notes = document.getElementById('attEditNotes');
  const notesReq = document.getElementById('attEditNotesReq');

  function sync() {
    const changed = sel.value !== sel.dataset.current;
    notes.required = changed;
    notesReq.classList.toggle('d-none', !changed);
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
JS;
?>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
