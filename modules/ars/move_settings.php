<?php
/**
 * ARS move-out settings: the standard move-out charges every move-out starts
 * with (amounts are entered per move-out), and the header / footer image for
 * the move-in / move-out forms (without one, the ARS logo and details are used).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_move_process.php';
require_once __DIR__ . '/../realestate/includes/form_letterhead_helper.php';

$arsCompanyId = arsPageAuth($conn);
$userId = current_user_id();
$canEdit = amp_can_manage($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if (!$canEdit) {
            throw new RuntimeException('Only Owner, Admin or ARS core staff can change these settings.');
        }
        amp_charge_types($conn, $arsCompanyId, true);
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        switch ($action) {
            case 'process_rule':
                $min = (int)($_POST['min_nights'] ?? 0);
                if ($min < 1 || $min > 365) throw new RuntimeException('Enter the minimum nights (1 to 365).');
                amp_set_min_nights($conn, $arsCompanyId, $min, $userId);
                $on = array_fill_keys(array_map('intval', (array)($_POST['units'] ?? [])), true);
                $forced = amp_forced_units($conn, $arsCompanyId);
                foreach (ars_fetch_short_term_units($conn, $arsCompanyId) as $u) {
                    $uid = (int)$u['id'];
                    if (isset($on[$uid]) !== isset($forced[$uid])) {
                        amp_set_unit_forced($conn, $arsCompanyId, $uid, isset($on[$uid]), $userId);
                    }
                }
                $_SESSION['success'] = 'Saved. Stays of ' . $min . ' nights or more' . ($on ? ', and every stay in the ' . count($on) . ' selected unit' . (count($on) === 1 ? '' : 's') . ',' : '') . ' go through the Move-In / Move-Out process. Shorter stays use Check In / Check Out.';
                break;
            case 'add':
                if ($name === '') throw new RuntimeException('Enter the charge name.');
                $conn->prepare("INSERT INTO ars_move_out_charge_types (company_id, name, display_order) SELECT ?, ?, COALESCE(MAX(display_order), 0) + 1 FROM ars_move_out_charge_types WHERE company_id = ?")
                    ->execute([$arsCompanyId, $name, $arsCompanyId]);
                $_SESSION['success'] = 'Charge added. New move-outs include it.';
                break;
            case 'rename':
                if ($name === '') throw new RuntimeException('Enter the charge name.');
                $conn->prepare("UPDATE ars_move_out_charge_types SET name = ? WHERE id = ? AND company_id = ?")->execute([$name, $id, $arsCompanyId]);
                $_SESSION['success'] = 'Charge renamed.';
                break;
            case 'toggle':
                $conn->prepare("UPDATE ars_move_out_charge_types SET is_active = 1 - is_active WHERE id = ? AND company_id = ?")->execute([$id, $arsCompanyId]);
                $_SESSION['success'] = 'Charge updated.';
                break;
            case 'letterhead':
                if (!rfl_ready($conn)) throw new RuntimeException('Run migrations/re_form_letterheads.sql first.');
                $part = ($_POST['part'] ?? '') === 'footer' ? 'footer' : 'header';
                if (!empty($_POST['reset'])) {
                    rfl_reset($conn, $arsCompanyId, $part, $userId);
                    $_SESSION['success'] = ucfirst($part) . ' removed.';
                } else {
                    rfl_save_upload($conn, $arsCompanyId, $part, $_FILES['image'] ?? [], $userId);
                    $_SESSION['success'] = ucfirst($part) . ' saved. The move-in / move-out forms use it now.';
                }
                break;
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header('Location: move_settings.php');
    exit;
}

$types = amp_charge_types($conn, $arsCompanyId, true);
$minNights = amp_min_nights($conn, $arsCompanyId);
$forcedUnits = amp_forced_units($conn, $arsCompanyId);
$ruleUnits = ars_fetch_short_term_units($conn, $arsCompanyId);
$lh = rfl_ready($conn) ? rfl_get($conn, $arsCompanyId) : null;
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

ars_shell_begin([
    'title' => 'Move-In / Move-Out Settings',
    'subtitle' => 'Which stays use the process, standard move-out charges and the form header / footer',
    'breadcrumbs' => [['label' => 'ARS', 'href' => 'index.php'], ['label' => 'Move-In / Move-Out', 'href' => 'move_report.php'], ['label' => 'Settings']],
    'actions_html' => ars_move_inspection_nav_html('settings'),
    'legacy_bootstrap' => true,
]);
?>
<?php if ($success): ?><div class="alert alert-success py-2"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
<?php if (!$canEdit): ?><div class="alert alert-info small py-2">View only. Owner, Admin or ARS core staff can change these settings.</div><?php endif; ?>

<style>
.amp-rule-units{max-height:320px;overflow:auto;border:1px solid var(--bs-border-color);border-radius:.5rem}
.amp-rule-units label{display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-bottom:1px solid var(--bs-border-color-translucent);margin:0;cursor:pointer}
.amp-rule-units label:last-child{border-bottom:0}
.amp-rule-units label:hover{background:rgba(184,134,11,.06)}
.amp-rule-units .form-check-input{margin:0;flex-shrink:0}
.amp-rule-units .form-check-input:checked{background-color:#b8860b;border-color:#b8860b}
</style>
<div class="ars-card mb-3">
    <div class="card-header"><i class="bi bi-signpost-split me-2"></i>When to use the Move-In / Move-Out process</div>
    <div class="card-body">
        <p class="small text-muted mb-3">Stays of at least the minimum nights go through the Move-In / Move-Out process (inspection, keys, signed report, deposit settlement). Shorter stays use the quick <strong>Check In</strong> / <strong>Check Out</strong> on the booking. Switch on a unit below to use the process for every stay in that unit, whatever its length. A process already started on a booking is always kept.</p>
        <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="process_rule">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold small" for="minNights">Minimum nights for the process</label>
                    <div class="input-group" style="max-width:220px">
                        <input type="number" id="minNights" name="min_nights" class="form-control" min="1" max="365" value="<?= (int)$minNights ?>" required <?= $canEdit ? '' : 'disabled' ?>>
                        <span class="input-group-text">nights</span>
                    </div>
                    <div class="form-text">Default 30. Bookings of this many nights or more use the process.</div>
                </div>
                <div class="col-md-8">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                        <span class="form-label fw-semibold small mb-0">Always use the process for these units <span class="text-muted fw-normal">(<span id="ruleCount"><?= count(array_intersect_key($forcedUnits, array_flip(array_map('intval', array_column($ruleUnits, 'id'))))) ?></span> on)</span></span>
                        <input type="search" id="ruleFilter" class="form-control form-control-sm" style="max-width:200px" placeholder="Find unit or building">
                    </div>
                    <div class="amp-rule-units">
                        <?php foreach ($ruleUnits as $u): $uid = (int)$u['id']; ?>
                        <label data-find="<?= h(mb_strtolower($u['unit_number'] . ' ' . ($u['building_name'] ?? '') . ' ' . ($u['listing_title'] ?? ''))) ?>">
                            <span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" name="units[]" value="<?= $uid ?>" <?= isset($forcedUnits[$uid]) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>></span>
                            <span class="fw-semibold"><?= h($u['unit_number']) ?></span>
                            <span class="small text-muted text-truncate"><?= h($u['building_name'] ?? '') ?><?= !empty($u['listing_title']) ? ' · ' . h($u['listing_title']) : '' ?></span>
                        </label>
                        <?php endforeach; ?>
                        <?php if (!$ruleUnits): ?><div class="small text-muted p-3">No short-term units.</div><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($canEdit): ?><button class="btn btn-sm btn-ars mt-3" type="submit"><i class="bi bi-check2-circle me-1"></i>Save</button><?php endif; ?>
        </form>
    </div>
</div>
<script>
(function () {
    var f = document.getElementById('ruleFilter'), box = document.querySelector('.amp-rule-units'), cnt = document.getElementById('ruleCount');
    if (f) f.addEventListener('input', function () {
        var q = f.value.trim().toLowerCase();
        box.querySelectorAll('label[data-find]').forEach(function (l) { l.style.display = !q || l.dataset.find.indexOf(q) !== -1 ? '' : 'none'; });
    });
    if (box) box.addEventListener('change', function () { cnt.textContent = box.querySelectorAll('input:checked').length; });
})();
</script>

<div class="row g-3">
<div class="col-lg-6">
<div class="ars-card mb-3">
    <div class="card-header"><i class="bi bi-brush me-2"></i>Standard move-out charges</div>
    <div class="card-body">
        <p class="small text-muted">Every new move-out lists these charges in Damages &amp; Charges, where the amount for that stay is entered (0 if not needed). Hidden charges are not added to new move-outs.</p>
        <?php foreach ($types as $t): $active = (int)$t['is_active'] === 1; ?>
            <div class="d-flex align-items-center gap-2 py-2 border-bottom <?= $active ? '' : 'text-muted' ?>">
                <?php if ($canEdit): ?>
                    <form method="post" class="d-flex gap-1 flex-grow-1"><?php csrf_field(); ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <input type="text" name="name" class="form-control form-control-sm" value="<?= h($t['name']) ?>" maxlength="100" required>
                        <button class="btn btn-sm btn-outline-secondary" type="submit" title="Save name"><i class="bi bi-check2"></i></button></form>
                    <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm btn-link text-muted px-1" type="submit" title="<?= $active ? 'Hide' : 'Show again' ?>"><i class="bi <?= $active ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button></form>
                <?php else: ?>
                    <span class="flex-grow-1"><?= h($t['name']) ?></span>
                <?php endif; ?>
                <?php if (!$active): ?><span class="badge bg-secondary">Hidden</span><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($canEdit): ?>
            <form method="post" class="d-flex gap-2 mt-3"><?php csrf_field(); ?><input type="hidden" name="action" value="add">
                <input type="text" name="name" class="form-control form-control-sm" maxlength="100" placeholder="New standard charge, e.g. Linen replacement" required>
                <button class="btn btn-sm btn-ars text-nowrap" type="submit"><i class="bi bi-plus-circle me-1"></i>Add</button></form>
        <?php endif; ?>
    </div>
</div>
</div>
<div class="col-lg-6">
<div class="ars-card mb-3">
    <div class="card-header"><i class="bi bi-layout-text-window me-2"></i>Form header &amp; footer</div>
    <div class="card-body">
        <p class="small text-muted">Images printed at the top and bottom of the move-in inspection form, the move-out inspection report and the deposit settlement statement (full page width, PNG or JPG). Without a header, the ARS logo and details from Document Branding are used.</p>
        <?php if (!$lh): ?>
            <div class="alert alert-warning small py-2 mb-0">Run <code>migrations/re_form_letterheads.sql</code> to upload a header and footer.</div>
        <?php else: foreach (['header' => 'Header', 'footer' => 'Footer'] as $part => $label): $cur = $lh[$part]; ?>
            <div class="mb-3">
                <div class="fw-semibold small mb-1"><?= $label ?></div>
                <?php if ($cur['custom']): ?>
                    <img src="../../<?= h($cur['path']) ?>" alt="<?= $label ?>" class="img-fluid border rounded mb-2">
                <?php else: ?>
                    <div class="small text-muted mb-2"><?= $part === 'header' ? 'Not set: the ARS logo and details are used.' : 'Not set: page numbers only.' ?></div>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                    <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2"><?php csrf_field(); ?><input type="hidden" name="action" value="letterhead"><input type="hidden" name="part" value="<?= $part ?>">
                        <input type="file" name="image" class="form-control form-control-sm" style="max-width:280px" accept="image/png,image/jpeg">
                        <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-upload me-1"></i>Upload</button>
                        <?php if ($cur['custom']): ?><button class="btn btn-sm btn-outline-danger" type="submit" name="reset" value="1" onclick="return confirm('Remove the <?= $part ?>?')">Remove</button><?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
        <a class="small" href="document_branding.php"><i class="bi bi-palette me-1"></i>Document Branding (logo and details)</a>
    </div>
</div>
</div>
</div>
<?php ars_shell_end(); ?>
