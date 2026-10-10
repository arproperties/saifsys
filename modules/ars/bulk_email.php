<?php
/**
 * Bulk Email — pick guests and write the message. Nothing is sent from here;
 * the next page (bulk_email_preview.php) shows the final list and asks to confirm.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_bulk_email.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
ars_bulk_email_ensure_schema($conn);

$audiences = ars_bulk_email_audiences();
$audience = (string)($_POST['audience'] ?? $_GET['audience'] ?? 'in_house');
if (!isset($audiences[$audience])) {
    $audience = 'in_house';
}
$filters = [
    'audience' => $audience,
    'building_id' => (int)($_POST['building_id'] ?? $_GET['building_id'] ?? 0),
    'q' => trim((string)($_POST['q'] ?? $_GET['q'] ?? '')),
];
$error = '';
$subject = '';
$bodyText = '';
$ticked = null; // null = first visit, everyone ticked

// Coming back from the preview with "Edit": refill the form.
$draft = $_SESSION['ars_bulk_email_preview'] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['edit'])
    && is_array($draft) && (int)($draft['company_id'] ?? 0) === $arsCompanyId) {
    $filters = $draft['filters'];
    $audience = $filters['audience'];
    $subject = (string)$draft['subject'];
    $bodyText = (string)$draft['body_text'];
    $ticked = array_flip(array_map('intval', $draft['guest_ids']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $subject = trim((string)($_POST['subject'] ?? ''));
    $bodyText = trim((string)($_POST['body_text'] ?? ''));
    $guestIds = array_map('intval', (array)($_POST['guest_ids'] ?? []));
    $ticked = array_flip($guestIds);

    $validated = ars_bulk_email_validate_selection($conn, $arsCompanyId, $guestIds, $filters);
    if ($subject === '') {
        $error = 'Subject is required.';
    } elseif ($bodyText === '') {
        $error = 'Message is required.';
    } elseif (empty($validated['ok'])) {
        $error = $validated['error'] ?? 'Select at least one guest.';
    } else {
        $files = ars_bulk_email_store_attachments($arsCompanyId, $_FILES['attachments'] ?? []);
        if (empty($files['ok'])) {
            $error = $files['error'] ?? 'Attachment error.';
        } else {
            // A previous draft's files are replaced by this one's.
            if (is_array($draft) && (int)($draft['company_id'] ?? 0) === $arsCompanyId) {
                ars_bulk_email_delete_files($arsCompanyId, $draft['attachments'] ?? []);
            }
            $_SESSION['ars_bulk_email_preview'] = [
                'company_id' => $arsCompanyId,
                'guest_ids' => array_keys($ticked),
                'filters' => $filters,
                'subject' => $subject,
                'body_text' => $bodyText,
                'attachments' => $files['files'],
            ];
            header('Location: bulk_email_preview.php');
            exit;
        }
    }
}

$list = ars_bulk_email_list_candidates($conn, $arsCompanyId, $filters);
$eligible = $list['eligible'];
$excluded = $list['excluded'];
$buildings = ars_bulk_email_buildings($conn, $arsCompanyId);

$tabHref = static function (string $id) use ($filters): string {
    $q = ['audience' => $id];
    if ($filters['building_id'] > 0) {
        $q['building_id'] = $filters['building_id'];
    }
    return 'bulk_email.php?' . http_build_query($q);
};
$tabs = [];
foreach ($audiences as $id => $label) {
    $tabs[] = ['id' => $id, 'label' => $label, 'href' => $tabHref($id), 'active' => $audience === $id];
}

$pageTitle = 'Bulk Email';
ars_shell_begin([
    'title' => 'Bulk Email',
    'subtitle' => 'One private email per guest. Guests never see each other.',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Bulk Email'],
    ],
    'actions_html' => ars_ui_button('Sent emails', [
        'href' => 'bulk_email_view.php',
        'variant' => 'secondary',
        'size' => 'sm',
        'icon' => 'history',
    ]),
    'toolbar_html' => ars_ds_segment($tabs),
    'legacy_bootstrap' => true,
]);
?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?></div>
<?php endif; ?>

<?php ob_start(); ?>
<form method="get" class="d-flex flex-wrap align-items-end gap-2">
    <input type="hidden" name="audience" value="<?= h($audience) ?>">
    <div style="min-width:12rem">
        <label class="form-label small fw-semibold mb-1" for="beBuilding">Building</label>
        <select id="beBuilding" name="building_id" class="form-select form-select-sm">
            <option value="0">All buildings</option>
            <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $filters['building_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="flex-grow-1" style="min-width:14rem">
        <label class="form-label small fw-semibold mb-1" for="beSearch">Search</label>
        <input id="beSearch" type="text" name="q" class="form-control form-control-sm" placeholder="Name, email, phone, unit…" value="<?= h($filters['q']) ?>">
    </div>
    <button type="submit" class="btn btn-sm btn-ars-outline"><i class="bi bi-funnel me-1"></i>Filter</button>
    <?php if ($filters['q'] !== '' || $filters['building_id'] > 0): ?>
    <a href="bulk_email.php?audience=<?= h($audience) ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
    <?php endif; ?>
</form>
<?php echo ars_ds_filter_card(ob_get_clean()); ?>

<form method="post" enctype="multipart/form-data" id="beForm">
    <?php csrf_field(); ?>
    <input type="hidden" name="audience" value="<?= h($audience) ?>">
    <input type="hidden" name="building_id" value="<?= (int)$filters['building_id'] ?>">
    <input type="hidden" name="q" value="<?= h($filters['q']) ?>">

    <div class="row g-3 align-items-start">
        <div class="col-lg-7">
            <div class="ars-card">
                <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                    <div class="fw-semibold">Guests <span class="text-muted fw-normal">· <span id="beCount">0</span> of <?= count($eligible) ?> selected</span></div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="beAll">
                        <label class="form-check-label small" for="beAll">Select all</label>
                    </div>
                </div>
                <?php if (!$eligible): ?>
                <div class="p-4">
                    <?= ars_ui_empty_state('No guests with an email here', 'Try another tab or clear the filter.') ?>
                </div>
                <?php else: ?>
                <div class="table-responsive" style="max-height:60vh;overflow:auto">
                    <table class="table ars-table mb-0 align-middle">
                        <thead>
                            <tr><th style="width:40px"></th><th>Guest</th><th>Unit</th><th>Stay</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($eligible as $r): ?>
                            <tr>
                                <td><input type="checkbox" class="form-check-input be-check" name="guest_ids[]" value="<?= (int)$r['guest_id'] ?>" <?= ($ticked === null || isset($ticked[$r['guest_id']])) ? 'checked' : '' ?>></td>
                                <td>
                                    <div class="fw-semibold"><?= h($r['guest_name']) ?></div>
                                    <div class="small text-muted"><?= h($r['email']) ?></div>
                                </td>
                                <td>
                                    <?= h($r['unit_number'] !== '' ? $r['unit_number'] : '—') ?>
                                    <div class="small text-muted"><?= h($r['building_name']) ?></div>
                                </td>
                                <td class="small text-nowrap">
                                    <?php if ($r['check_in'] !== ''): ?>
                                    <?= h(ars_bulk_email_format_date($r['check_in'])) ?> – <?= h(ars_bulk_email_format_date($r['check_out'])) ?>
                                    <div class="text-muted"><?= h($r['booking_number']) ?></div>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($excluded): ?>
            <div class="ars-card mt-3">
                <div class="p-3 border-bottom fw-semibold">Cannot be emailed <span class="text-muted fw-normal">· <?= count($excluded) ?></span></div>
                <div class="table-responsive" style="max-height:220px;overflow:auto">
                    <table class="table ars-table mb-0 align-middle">
                        <tbody>
                        <?php foreach ($excluded as $r): ?>
                            <tr>
                                <td><a href="guest_view.php?id=<?= (int)$r['guest_id'] ?>"><?= h($r['guest_name']) ?></a></td>
                                <td><?= h($r['unit_number'] !== '' ? $r['unit_number'] : '—') ?></td>
                                <td class="small text-muted"><?= h($r['exclude_reason']) ?><?= $r['exclude_reason'] === 'Invalid email' ? ': ' . h($r['email']) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-5">
            <div class="ars-card p-3">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="beSubject">Subject *</label>
                    <input type="text" name="subject" id="beSubject" class="form-control" maxlength="255" required value="<?= h($subject) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="beBody">Message *</label>
                    <textarea name="body_text" id="beBody" class="form-control" rows="11" required placeholder="Dear {{guest_name}},"><?= h($bodyText) ?></textarea>
                    <div class="form-text">
                        Tap to insert:
                        <?php foreach (array_keys(ars_bulk_email_placeholder_values([])) as $ph): ?>
                        <a href="#" class="be-ph badge text-bg-light border text-decoration-none"><?= h($ph) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="beFiles">Attachments (optional)</label>
                    <input type="file" name="attachments[]" id="beFiles" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xlsx">
                    <div class="form-text">Up to <?= ARS_BULK_EMAIL_MAX_FILES ?> files, 5MB each. PDF, JPG, PNG, DOC, DOCX, XLSX.<?= !empty($_GET['edit']) && !empty($draft['attachments']) ? ' Choose the files again.' : '' ?></div>
                </div>
                <button type="submit" class="btn btn-ars w-100"><i class="bi bi-eye me-1"></i>Preview</button>
                <div class="form-text text-center">Nothing is sent until you confirm on the next page.</div>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    var checks = Array.prototype.slice.call(document.querySelectorAll('.be-check'));
    var all = document.getElementById('beAll');
    function sync() {
        var n = checks.filter(function (c) { return c.checked; }).length;
        document.getElementById('beCount').textContent = n;
        all.checked = checks.length > 0 && n === checks.length;
    }
    all.addEventListener('change', function () {
        checks.forEach(function (c) { c.checked = all.checked; });
        sync();
    });
    checks.forEach(function (c) { c.addEventListener('change', sync); });
    sync();

    var body = document.getElementById('beBody');
    document.querySelectorAll('.be-ph').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var s = body.selectionStart, t = a.textContent;
            body.value = body.value.slice(0, s) + t + body.value.slice(body.selectionEnd);
            body.focus();
            body.selectionStart = body.selectionEnd = s + t.length;
        });
    });

    document.getElementById('beForm').addEventListener('submit', function (e) {
        if (!checks.some(function (c) { return c.checked; })) {
            e.preventDefault();
            alert('Select at least one guest.');
        }
    });
})();
</script>

<?php ars_shell_end(); ?>
