<?php
/**
 * ARS move-in / move-out inspection for one booking.
 * Staff tick the checklist, add remarks and photos, then complete it; completing
 * also checks the guest in (or out) through the normal booking action.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_move_inspection.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$userId = current_user_id();

$bookingId = (int)($_GET['booking_id'] ?? 0);
$type = ars_move_inspection_type($_GET['type'] ?? 'in');
$isOut = $type === 'out';
$typeLabel = ars_move_inspection_type_label($type);

$stmt = $conn->prepare("
    SELECT b.id, b.booking_number, b.status, b.check_in, b.check_out, b.actual_check_out, b.num_guests,
           g.first_name, g.last_name, g.phone AS guest_phone,
           u.unit_number, bl.name AS building_name
    FROM ars_bookings b
    LEFT JOIN ars_guests g ON g.id = b.guest_id
    LEFT JOIN re_units u ON u.id = b.unit_id
    LEFT JOIN re_buildings bl ON bl.id = u.building_id
    WHERE b.id = ? AND b.company_id = ?
");
$stmt->execute([$bookingId, $arsCompanyId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    http_response_code(404);
    exit('Booking not found');
}

$status = (string)$booking['status'];
$allowed = in_array($status, ars_move_inspection_allowed_statuses($type), true);
$inspection = $allowed
    ? ars_move_inspection_get_or_create($conn, $arsCompanyId, $bookingId, $type, $userId)
    : ars_move_inspection_find($conn, $arsCompanyId, $bookingId, $type);

$items = $inspection ? ars_move_inspection_items($conn, (int)$inspection['id']) : [];
$photos = $inspection ? ars_move_inspection_photos($conn, (int)$inspection['id']) : [];
$progress = $inspection ? ars_move_inspection_progress($conn, (int)$inspection['id']) : ['total' => 0, 'done' => 0, 'required_left' => 0];
$isDone = $inspection && $inspection['status'] === 'completed';

// The booking step this inspection leads to, while it is still open to take.
$canMove = $status === ($isOut ? 'checked_in' : 'confirmed');
$moveVerb = $isOut ? 'check out' : 'check in';
$today = (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');

$statusLabel = ucwords(str_replace('_', ' ', $status));
$guestName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')) ?: 'Guest';
$fmtDateTime = static fn($d): string => $d ? date('d M Y, H:i', strtotime((string)$d)) : '';
$tabLink = static fn(string $t): string => 'move_inspection.php?' . http_build_query(['booking_id' => $bookingId, 'type' => $t]);
$tabClass = static fn(bool $on): string => 'no-underline rounded-ars-md px-3 py-1.5 text-ars-sm font-semibold '
    . ($on ? 'bg-ars-ink text-white' : 'text-ars-muted hover:text-ars-text');

ars_shell_begin([
    'title' => $typeLabel . ' Inspection',
    'subtitle' => $booking['booking_number'] . ' · ' . $guestName,
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Move-in / Move-out', 'href' => 'move_report.php'],
        ['label' => $typeLabel . ' inspection'],
    ],
    'actions_html' => '<a class="no-underline inline-flex items-center gap-1 rounded-ars-md border border-ars-border px-3 py-2 text-ars-sm font-semibold text-ars-text hover:border-ars-ink/40" href="booking_view.php?id=' . $bookingId . '">'
        . ars_ui_icon('external-link', ['class' => 'h-4 w-4']) . ' Open booking</a>',
    'legacy_bootstrap' => true,
]);
?>
<style>
    .ars-insp-item { border-left: 4px solid #dee2e6; }
    .ars-insp-item.is-required { border-left-color: #b07d2b; }
    .ars-insp-item.is-done { border-left-color: #198754; background: #f3faf6; }
    .ars-insp-item .form-check-input { width: 1.35rem; height: 1.35rem; margin-top: .1rem; }
    .ars-insp-photo img { width: 100%; height: 120px; object-fit: cover; border-radius: 6px; border: 1px solid #dee2e6; }
    .ars-insp-photo .ars-insp-pdf { height: 120px; border-radius: 6px; border: 1px solid #dee2e6; display: flex; align-items: center; justify-content: center; font-size: 2rem; }
</style>

<div class="mb-3">
    <div class="inline-flex flex-wrap gap-1 rounded-ars-lg border border-ars-border bg-ars-surface p-1">
        <a class="<?= $tabClass(!$isOut) ?>" href="<?= h($tabLink('in')) ?>">Move-in</a>
        <a class="<?= $tabClass($isOut) ?>" href="<?= h($tabLink('out')) ?>">Move-out</a>
    </div>
</div>

<div id="inspAlert" class="d-none"></div>

<div class="ars-card mb-3">
    <div class="card-body py-3">
        <div class="row g-3 small">
            <div class="col-6 col-lg-3"><div class="text-muted">Guest</div><div class="fw-semibold"><?= h($guestName) ?></div><div><?= h($booking['guest_phone'] ?: '') ?></div></div>
            <div class="col-6 col-lg-3"><div class="text-muted">Unit</div><div class="fw-semibold"><?= h($booking['unit_number'] ?: '—') ?></div><div><?= h($booking['building_name'] ?: '') ?></div></div>
            <div class="col-6 col-lg-3"><div class="text-muted">Stay</div><div class="fw-semibold"><?= h(ars_ds_format_stay((string)$booking['check_in'], (string)($booking['actual_check_out'] ?: $booking['check_out']))) ?></div><div><?= (int)$booking['num_guests'] ?> guest(s)</div></div>
            <div class="col-6 col-lg-3"><div class="text-muted">Booking status</div><div class="fw-semibold"><?= h($statusLabel) ?></div></div>
        </div>
    </div>
</div>

<?php if (!$inspection): ?>
    <div class="alert alert-warning">
        <?php if ($isOut): ?>
            The move-out inspection opens once the guest is checked in. This booking is <strong><?= h($statusLabel) ?></strong>.
        <?php else: ?>
            This booking is <strong><?= h($statusLabel) ?></strong>, so there is no move-in inspection for it.
        <?php endif; ?>
    </div>
<?php else: ?>

<?php if ($isDone): ?>
    <div class="alert alert-success d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <i class="bi bi-check-circle-fill me-1"></i>
            <strong><?= h($typeLabel) ?> inspection completed</strong>
            <?= h($fmtDateTime($inspection['completed_at'])) ?>
            <?php if (!empty($inspection['completed_by_name'])): ?>by <?= h($inspection['completed_by_name']) ?><?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="ars-card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-list-check me-2"></i><?= h($typeLabel) ?> checklist</span>
                <span class="badge bg-secondary" id="inspProgress"><?= $progress['done'] ?> of <?= $progress['total'] ?></span>
            </div>
            <div class="card-body">
                <?php if (!$items): ?>
                    <p class="text-muted mb-0">No checklist items. Add them in <a href="move_checklist.php?type=<?= h($type) ?>">Checklist settings</a>.</p>
                <?php endif; ?>
                <?php foreach ($items as $item): ?>
                    <div class="ars-insp-item rounded p-3 mb-2 <?= $item['is_required'] ? 'is-required' : '' ?> <?= $item['is_completed'] ? 'is-done' : '' ?>" data-item-id="<?= (int)$item['id'] ?>">
                        <div class="form-check d-flex gap-2 align-items-start mb-0">
                            <input class="form-check-input flex-shrink-0 insp-check" type="checkbox" id="insp-item-<?= (int)$item['id'] ?>"
                                   <?= $item['is_completed'] ? 'checked' : '' ?> <?= $isDone ? 'disabled' : '' ?>>
                            <label class="form-check-label flex-grow-1" for="insp-item-<?= (int)$item['id'] ?>">
                                <span class="fw-semibold"><?= h($item['item_name']) ?></span>
                                <?php if ($item['is_required']): ?><span class="badge bg-warning text-dark ms-1">Required</span><?php endif; ?>
                                <?php if (!empty($item['item_description'])): ?><span class="d-block small text-muted"><?= h($item['item_description']) ?></span><?php endif; ?>
                            </label>
                        </div>
                        <input type="text" class="form-control form-control-sm mt-2 insp-remarks" maxlength="1000" placeholder="Remarks (optional)"
                               value="<?= h($item['remarks'] ?? '') ?>" <?= $isDone ? 'disabled' : '' ?>>
                        <?php if ($item['is_completed'] && !empty($item['completed_at'])): ?>
                            <div class="small text-muted mt-1">Ticked <?= h($fmtDateTime($item['completed_at'])) ?><?= !empty($item['completed_by_name']) ? ' by ' . h($item['completed_by_name']) : '' ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ars-card mb-3">
            <div class="card-header"><i class="bi bi-camera me-2"></i>Photos</div>
            <div class="card-body">
                <form id="inspPhotoForm" class="row g-2 align-items-end mb-3">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold mb-1">Photos</label>
                        <input class="form-control form-control-sm" type="file" name="photos[]" accept="image/*,application/pdf" multiple required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold mb-1">Room / note</label>
                        <input class="form-control form-control-sm" type="text" name="caption" maxlength="255" placeholder="e.g. Bedroom wall">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-sm btn-ars w-100" type="submit"><i class="bi bi-upload me-1"></i>Upload</button>
                    </div>
                </form>
                <?php if (!$photos): ?>
                    <p class="text-muted small mb-0">No photos yet.</p>
                <?php else: ?>
                <div class="row g-2">
                    <?php foreach ($photos as $p): ?>
                        <?php $isPdf = strtolower(pathinfo((string)$p['original_name'], PATHINFO_EXTENSION)) === 'pdf'; ?>
                        <div class="col-6 col-md-3 ars-insp-photo">
                            <a href="move_inspection_photo.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" class="text-decoration-none">
                                <?php if ($isPdf): ?>
                                    <div class="ars-insp-pdf text-danger"><i class="bi bi-file-earmark-pdf"></i></div>
                                <?php else: ?>
                                    <img src="move_inspection_photo.php?id=<?= (int)$p['id'] ?>" alt="<?= h($p['caption'] ?: $p['original_name']) ?>" loading="lazy">
                                <?php endif; ?>
                            </a>
                            <div class="d-flex justify-content-between align-items-start gap-1 mt-1">
                                <span class="small text-muted text-truncate"><?= h($p['caption'] ?: $p['original_name']) ?></span>
                                <?php if (!$isDone): ?>
                                    <button type="button" class="btn btn-link btn-sm text-danger p-0 insp-photo-del" data-photo-id="<?= (int)$p['id'] ?>" title="Remove"><i class="bi bi-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="ars-card mb-3">
            <div class="card-header"><i class="bi bi-pencil-square me-2"></i><?= $isOut ? 'Damages / notes' : 'Notes' ?></div>
            <div class="card-body">
                <textarea class="form-control form-control-sm" id="inspNotes" rows="5" maxlength="5000" <?= $isDone ? 'disabled' : '' ?>
                          placeholder="<?= $isOut ? 'Anything broken, missing or dirty' : 'Anything to note at arrival' ?>"><?= h($inspection['notes'] ?? '') ?></textarea>
                <?php if (!$isDone): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="inspNotesSave">Save notes</button>
                    <span class="small text-success ms-2 d-none" id="inspNotesSaved">Saved</span>
                <?php endif; ?>
                <?php if ($isOut): ?>
                    <p class="small text-muted mt-2 mb-0">Notes only. Any deduction from the security deposit is done on the booking's Deposit tab.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="ars-card mb-3">
            <div class="card-header"><i class="bi bi-check2-all me-2"></i>Finish</div>
            <div class="card-body">
                <?php if ($canMove && $isOut): ?>
                    <label class="form-label small fw-semibold mb-1" for="inspOutDate">Check-out date</label>
                    <input type="date" class="form-control form-control-sm mb-2" id="inspOutDate"
                           value="<?= h($today) ?>" max="<?= h($today) ?>" min="<?= h((string)$booking['check_in']) ?>">
                <?php endif; ?>

                <?php if (!$isDone): ?>
                    <button type="button" class="btn btn-ars w-100" id="inspCompleteBtn">
                        <i class="bi bi-check-circle me-1"></i>Complete <?= h(strtolower($typeLabel)) ?><?= $canMove ? ' & ' . h($moveVerb) : '' ?>
                    </button>
                    <p class="small text-muted mt-2 mb-0">
                        <?php if ($canMove): ?>
                            Closes the checklist and marks the guest <?= $isOut ? 'checked out' : 'checked in' ?>.
                        <?php else: ?>
                            Closes the checklist. The booking stays <strong><?= h($statusLabel) ?></strong><?= (!$isOut && $status === 'pending') ? '; confirm it on the booking page before check-in' : '' ?>.
                        <?php endif; ?>
                        All required items must be ticked.
                    </p>
                <?php elseif ($canMove): ?>
                    <p class="small mb-2">The inspection is done but the guest is not <?= $isOut ? 'checked out' : 'checked in' ?> yet.</p>
                    <button type="button" class="btn btn-ars w-100" id="inspMoveBtn">
                        <i class="bi bi-box-arrow-<?= $isOut ? 'right' : 'in-right' ?> me-1"></i><?= h(ucfirst($moveVerb)) ?> guest now
                    </button>
                <?php else: ?>
                    <p class="small text-muted mb-0">Nothing left to do here. Booking is <strong><?= h($statusLabel) ?></strong>.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var csrf = <?= json_encode(csrf_token()) ?>;
    var bookingId = <?= (int)$bookingId ?>;
    var type = <?= json_encode($type) ?>;
    var canMove = <?= $canMove ? 'true' : 'false' ?>;
    var alertEl = document.getElementById('inspAlert');

    function showAlert(msg, tone) {
        alertEl.className = 'alert alert-' + (tone || 'danger');
        alertEl.textContent = msg;
        alertEl.scrollIntoView({ block: 'center' });
    }

    function post(url, data) {
        var fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        }
        fd.append('_csrf', csrf);
        fd.append('booking_id', bookingId);
        fd.append('type', type);
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
            return r.json().catch(function () { throw new Error('Server error (' + r.status + ')'); });
        });
    }

    function saveItem(row) {
        var check = row.querySelector('.insp-check');
        return post('ajax_move_inspection.php', {
            action: 'save_item',
            item_id: row.getAttribute('data-item-id'),
            is_completed: check.checked ? '1' : '0',
            remarks: row.querySelector('.insp-remarks').value
        }).then(function (d) {
            if (!d.success) throw new Error(d.error || 'Could not save');
            row.classList.toggle('is-done', check.checked);
            if (d.progress) {
                document.getElementById('inspProgress').textContent = d.progress.done + ' of ' + d.progress.total;
            }
        });
    }

    document.querySelectorAll('.ars-insp-item').forEach(function (row) {
        var check = row.querySelector('.insp-check');
        var remarks = row.querySelector('.insp-remarks');
        check.addEventListener('change', function () {
            saveItem(row).catch(function (e) {
                check.checked = !check.checked;
                showAlert(e.message);
            });
        });
        remarks.addEventListener('change', function () {
            saveItem(row).catch(function (e) { showAlert(e.message); });
        });
    });

    function saveNotes() {
        return post('ajax_move_inspection.php', { action: 'save_notes', notes: document.getElementById('inspNotes').value })
            .then(function (d) { if (!d.success) throw new Error(d.error || 'Could not save notes'); });
    }
    var notesBtn = document.getElementById('inspNotesSave');
    if (notesBtn) {
        notesBtn.addEventListener('click', function () {
            saveNotes().then(function () {
                document.getElementById('inspNotesSaved').classList.remove('d-none');
            }).catch(function (e) { showAlert(e.message); });
        });
    }

    var photoForm = document.getElementById('inspPhotoForm');
    photoForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = photoForm.querySelector('button[type="submit"]');
        var fd = new FormData(photoForm);
        fd.append('action', 'upload_photo');
        btn.disabled = true;
        post('ajax_move_inspection.php', fd).then(function (d) {
            if (!d.success) throw new Error(d.error || 'Upload failed');
            if (d.error) window.alert('Some files were not saved: ' + d.error);
            location.reload();
        }).catch(function (err) {
            btn.disabled = false;
            showAlert(err.message);
        });
    });

    document.querySelectorAll('.insp-photo-del').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.confirm('Remove this photo?')) return;
            post('ajax_move_inspection.php', { action: 'delete_photo', photo_id: btn.getAttribute('data-photo-id') })
                .then(function (d) {
                    if (!d.success) throw new Error(d.error || 'Could not remove');
                    location.reload();
                }).catch(function (e) { showAlert(e.message); });
        });
    });

    // The check-in / check-out itself is the normal booking action, so the
    // cleaning order, early check-out and timeline all behave as they do there.
    function moveGuest() {
        var data = { action: type === 'out' ? 'checkout' : 'checkin' };
        if (type === 'out') {
            var dateEl = document.getElementById('inspOutDate');
            data.actual_check_out = dateEl ? dateEl.value : '';
        }
        return post('ajax_booking_actions.php', data).then(function (d) {
            if (!d.success) throw new Error(d.error || 'Action failed');
        });
    }

    var completeBtn = document.getElementById('inspCompleteBtn');
    if (completeBtn) {
        completeBtn.addEventListener('click', function () {
            var ask = canMove
                ? 'Complete the inspection and ' + (type === 'out' ? 'check the guest out' : 'check the guest in') + '?'
                : 'Complete the inspection? The checklist cannot be changed after this.';
            if (!window.confirm(ask)) return;
            completeBtn.disabled = true;
            saveNotes().then(function () {
                return post('ajax_move_inspection.php', { action: 'complete' });
            }).then(function (d) {
                if (!d.success) throw new Error(d.error || 'Could not complete');
                if (!canMove) return location.reload();
                return moveGuest().then(function () { location.reload(); }, function (e) {
                    window.alert('Inspection completed, but the guest was not ' + (type === 'out' ? 'checked out' : 'checked in') + ': ' + e.message);
                    location.reload();
                });
            }).catch(function (e) {
                completeBtn.disabled = false;
                showAlert(e.message);
            });
        });
    }

    var moveBtn = document.getElementById('inspMoveBtn');
    if (moveBtn) {
        moveBtn.addEventListener('click', function () {
            moveBtn.disabled = true;
            moveGuest().then(function () { location.reload(); }).catch(function (e) {
                moveBtn.disabled = false;
                showAlert(e.message);
            });
        });
    }
})();
</script>
<?php endif; ?>

<?php ars_shell_end(); ?>
