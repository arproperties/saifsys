<?php
/**
 * Guest profile — identity, contact, and booking stay history.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_guest_delete.php';
require_once __DIR__ . '/includes/ars_guest_attachments.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$guestId = (int)($_GET['id'] ?? 0);
if (!$guestId) {
    header('Location: guests.php');
    exit;
}

$stmt = $conn->prepare('SELECT * FROM ars_guests WHERE id = ? AND company_id = ?');
$stmt->execute([$guestId, $arsCompanyId]);
$guest = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$guest) {
    header('Location: guests.php');
    exit;
}

$success = $error = '';
$canDeleteGuests = ars_user_can_delete_guest($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_guest'])) {
    csrf_verify();
    if (!$canDeleteGuests) {
        $error = 'You do not have permission to delete guests.';
    } else {
        $err = ars_guest_delete($conn, $arsCompanyId, $guestId, function_exists('current_user_id') ? current_user_id() : null);
        if ($err === null) {
            $deletedName = trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''));
            header('Location: guests.php?deleted=' . rawurlencode($deletedName !== '' ? $deletedName : ('#' . $guestId)));
            exit;
        }
        $error = $err;
    }
}

$deleteBlockers = $canDeleteGuests ? ars_guest_delete_blockers($conn, $arsCompanyId, $guestId) : [];
$section = (string)($_GET['section'] ?? 'overview');
if ($section === 'flats') {
    $section = 'overview'; // legacy URL; flat tenancy is not shown on guest profile
}
if (!in_array($section, ['overview', 'profile', 'stays', 'documents'], true)) {
    $section = 'overview';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_guest'])) {
    csrf_verify();
    try {
        $conn->prepare("
            UPDATE ars_guests SET first_name=?, last_name=?, email=?, phone=?, phone_alt=?,
                nationality=?, id_type=?, id_number=?, id_expiry=?, address=?, notes=?
            WHERE id=? AND company_id=?
        ")->execute([
            trim((string)$_POST['first_name']),
            trim((string)$_POST['last_name']),
            trim((string)$_POST['email']) ?: null,
            trim((string)$_POST['phone']) ?: null,
            trim((string)$_POST['phone_alt']) ?: null,
            trim((string)$_POST['nationality']) ?: null,
            $_POST['id_type'] ?: null,
            trim((string)$_POST['id_number']) ?: null,
            $_POST['id_expiry'] ?: null,
            trim((string)$_POST['address']) ?: null,
            trim((string)$_POST['notes']) ?: null,
            $guestId,
            $arsCompanyId,
        ]);
        $success = 'Guest updated.';
        require_once __DIR__ . '/includes/ars_activity.php';
        ars_activity_log_guest_change(
            $conn,
            $arsCompanyId,
            $guestId,
            'Guest profile fields were updated in ARS.',
            function_exists('current_user_id') ? current_user_id() : null
        );
        $stmt = $conn->prepare('SELECT * FROM ars_guests WHERE id = ? AND company_id = ?');
        $stmt->execute([$guestId, $arsCompanyId]);
        $guest = $stmt->fetch(PDO::FETCH_ASSOC);
        $section = 'profile';
    } catch (PDOException $e) {
        $error = $e->getMessage();
        $section = 'profile';
    }
}

$bookings = [];
try {
    $stmt = $conn->prepare("
        SELECT b.*, u.unit_number, bl.name AS building_name
        FROM ars_bookings b
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bl ON bl.id = u.building_id
        WHERE b.guest_id = ? AND b.company_id = ?
        ORDER BY b.check_in DESC
    ");
    $stmt->execute([$guestId, $arsCompanyId]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
}

$docCategories = ars_guest_doc_categories();
$guestDocs = [];
$guestDocsError = '';
try {
    $guestDocs = ars_guest_attachments_list($conn, $arsCompanyId, $guestId);
} catch (Throwable $e) {
    $guestDocsError = $e->getMessage();
}
$guestDocCount = count($guestDocs);
// Documents whose expiry has passed, or lands inside the next 30 days, get flagged
// on the profile so staff renew ID paperwork before the next check-in.
$docExpiryToday = date('Y-m-d');
$docExpirySoon = date('Y-m-d', strtotime('+30 days'));

$guestName = trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''));
$liveStays = 0;
$nightsTotal = 0;
$openBalance = 0.0;
foreach ($bookings as $bk) {
    $st = (string)($bk['status'] ?? '');
    if (!in_array($st, ['cancelled', 'expired'], true)) {
        $liveStays++;
        $nightsTotal += (int)($bk['nights'] ?? 0);
        $openBalance += max(0, (float)($bk['balance_due'] ?? 0));
    }
}

$actions = '<div class="d-flex flex-wrap gap-2">'
    . ars_ui_button('New booking', [
        'href' => 'booking_add.php?guest_id=' . $guestId,
        'variant' => 'primary',
        'size' => 'sm',
        'icon' => 'calendar-plus',
    ])
    . ars_ui_button('All guests', [
        'href' => 'guests.php',
        'variant' => 'secondary',
        'size' => 'sm',
        'icon' => 'arrow-left',
    ])
    . ($canDeleteGuests
        ? ars_ui_button('Delete', [
            'variant' => 'danger-outline',
            'size' => 'sm',
            'icon' => 'trash-2',
            'disabled' => !empty($deleteBlockers),
            'attrs' => empty($deleteBlockers)
                ? 'data-bs-toggle="modal" data-bs-target="#deleteGuestModal"'
                : 'title="' . h(implode('; ', $deleteBlockers)) . '"',
        ])
        : '')
    . '</div>';

$pageTitle = 'Guest — ' . $guestName;
ars_shell_begin([
    'title' => $guestName !== '' ? $guestName : 'Guest',
    'subtitle' => 'Guest profile · #' . $guestId,
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Guests', 'href' => 'guests.php'],
        ['label' => $guestName !== '' ? $guestName : ('#' . $guestId)],
    ],
    'actions_html' => $actions,
    'toolbar_html' => ars_ds_segment([
        ['id' => 'overview', 'label' => 'Overview', 'icon' => 'layout-dashboard', 'href' => 'guest_view.php?id=' . $guestId . '&section=overview', 'active' => $section === 'overview'],
        ['id' => 'profile', 'label' => 'Edit profile', 'icon' => 'user', 'href' => 'guest_view.php?id=' . $guestId . '&section=profile', 'active' => $section === 'profile'],
        ['id' => 'stays', 'label' => 'Stays', 'icon' => 'calendar', 'href' => 'guest_view.php?id=' . $guestId . '&section=stays', 'active' => $section === 'stays'],
        ['id' => 'documents', 'label' => 'Documents' . ($guestDocCount > 0 ? ' (' . $guestDocCount . ')' : ''), 'icon' => 'file-text', 'href' => 'guest_view.php?id=' . $guestId . '&section=documents', 'active' => $section === 'documents'],
    ]),
    'legacy_bootstrap' => true,
]);
?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="ars-card mb-4">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div class="d-flex align-items-start gap-3 min-w-0">
                <?= ars_ds_avatar(ars_ds_guest_initials($guest['first_name'] ?? '', $guest['last_name'] ?? '')) ?>
                <div class="min-w-0">
                    <h2 class="h5 mb-1 fw-bold"><?= h($guestName) ?></h2>
                    <div class="d-flex flex-wrap gap-2 small text-muted">
                        <?php if (!empty($guest['email'])): ?>
                        <a class="text-decoration-none" href="mailto:<?= h($guest['email']) ?>"><i class="bi bi-envelope me-1"></i><?= h($guest['email']) ?></a>
                        <?php endif; ?>
                        <?php if (!empty($guest['phone'])): ?>
                        <a class="text-decoration-none" href="tel:<?= h($guest['phone']) ?>"><i class="bi bi-telephone me-1"></i><?= h($guest['phone']) ?></a>
                        <?php endif; ?>
                        <?php if (!empty($guest['nationality'])): ?>
                        <span><i class="bi bi-flag me-1"></i><?= h($guest['nationality']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($guest['id_number'])): ?>
                        <span><i class="bi bi-card-text me-1"></i><?= h(ucwords(str_replace('_', ' ', (string)($guest['id_type'] ?? 'ID')))) ?> <?= h($guest['id_number']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($guest['notes'])): ?>
                    <p class="small text-muted mb-0 mt-2"><i class="bi bi-sticky me-1"></i><?= h($guest['notes']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($openBalance > 0.009): ?>
            <div class="text-end">
                <div class="small text-muted">Open stay balance</div>
                <div class="fs-5 fw-bold text-danger ars-tabular"><?= formatArsAmount($openBalance) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($section === 'overview' || $section === 'stays'): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Stays', (string)$liveStays, ['icon' => 'calendar-check', 'hint' => 'Excl. cancelled/expired']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Nights', (string)$nightsTotal, ['icon' => 'moon', 'hint' => 'Across active stays']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Total spent', formatArsAmount($guest['total_spent'] ?? 0), ['tone' => 'ok', 'icon' => 'wallet']) ?></div>
    <div class="col-6 col-lg-3"><?= ars_ds_stat_tile('Open balance', formatArsAmount($openBalance), ['tone' => $openBalance > 0 ? 'warn' : 'ok', 'icon' => 'alert-circle']) ?></div>
</div>
<?php endif; ?>

<?php if ($section === 'overview'): ?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="ars-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person-vcard me-2"></i>Profile snapshot</span>
                <a class="btn btn-sm btn-ars-outline text-decoration-none" href="guest_view.php?id=<?= $guestId ?>&amp;section=profile">Edit</a>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Email</dt><dd class="col-7"><?= h($guest['email'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= h($guest['phone'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Alt phone</dt><dd class="col-7"><?= h($guest['phone_alt'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Nationality</dt><dd class="col-7"><?= h($guest['nationality'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">ID</dt>
                    <dd class="col-7">
                        <?php if (!empty($guest['id_number'])): ?>
                            <?= h(ucwords(str_replace('_', ' ', (string)($guest['id_type'] ?? '')))) ?>
                            <?= h($guest['id_number']) ?>
                            <?php if (!empty($guest['id_expiry'])): ?><br><span class="text-muted">Expires <?= h($guest['id_expiry']) ?></span><?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted">Address</dt><dd class="col-7"><?= h($guest['address'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Documents</dt>
                    <dd class="col-7">
                        <a class="text-decoration-none" href="guest_view.php?id=<?= $guestId ?>&amp;section=documents">
                            <?= $guestDocCount > 0 ? (int)$guestDocCount . ' on file' : 'Add ID / passport' ?>
                        </a>
                    </dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="ars-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-journal-bookmark me-2"></i>Recent stays</span>
                <a class="btn btn-sm btn-ars-outline text-decoration-none" href="guest_view.php?id=<?= $guestId ?>&amp;section=stays">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table ars-table ars-mobile-cards mb-0 align-middle">
                    <thead><tr><th>Booking</th><th>Unit</th><th>Dates</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php
                    $recent = array_slice($bookings, 0, 5);
                    if (empty($recent)):
                    ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No bookings yet. <a href="booking_add.php?guest_id=<?= $guestId ?>">Create one</a>.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent as $bk): ?>
                        <tr>
                            <td data-label="Booking"><a href="booking_view.php?id=<?= (int)$bk['id'] ?>" class="fw-semibold text-decoration-none"><?= h($bk['booking_number']) ?></a></td>
                            <td data-label="Unit"><?= h($bk['unit_number'] ?? '—') ?><br><small class="text-muted"><?= h($bk['building_name'] ?? '') ?></small></td>
                            <td data-label="Dates" class="small"><?= h(ars_ds_format_stay($bk['check_in'] ?? '', $bk['check_out'] ?? '')) ?><br><span class="text-muted"><?= (int)$bk['nights'] ?> night<?= (int)$bk['nights'] !== 1 ? 's' : '' ?></span></td>
                            <td data-label="Total" class="text-end ars-tabular"><?= formatArsAmount($bk['total_amount'] ?? 0) ?></td>
                            <td data-label="Status"><?= ars_ui_status_badge('booking', (string)$bk['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php elseif ($section === 'profile'): ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="edit_guest" value="1">
        <div class="ars-card">
            <div class="card-header"><i class="bi bi-pencil-square me-2"></i>Edit profile</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">First name *</label><input type="text" name="first_name" class="form-control" value="<?= h($guest['first_name']) ?>" required></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Last name *</label><input type="text" name="last_name" class="form-control" value="<?= h($guest['last_name']) ?>" required></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" class="form-control" value="<?= h($guest['email'] ?? '') ?>"></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" class="form-control" value="<?= h($guest['phone'] ?? '') ?>"></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Alt phone</label><input type="text" name="phone_alt" class="form-control" value="<?= h($guest['phone_alt'] ?? '') ?>"></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Nationality</label><input type="text" name="nationality" class="form-control" value="<?= h($guest['nationality'] ?? '') ?>"></div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label fw-semibold">ID type</label>
                        <select name="id_type" class="form-select">
                            <option value="">—</option>
                            <?php foreach (['emirates_id' => 'Emirates ID', 'passport' => 'Passport', 'visa' => 'Visa', 'driving_license' => 'Driving License', 'other' => 'Other'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= ($guest['id_type'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-4"><label class="form-label fw-semibold">ID number</label><input type="text" name="id_number" class="form-control" value="<?= h($guest['id_number'] ?? '') ?>"></div>
                    <div class="col-12 col-sm-4"><label class="form-label fw-semibold">ID expiry</label><input type="date" name="id_expiry" class="form-control" value="<?= h($guest['id_expiry'] ?? '') ?>"></div>
                    <div class="col-12"><label class="form-label fw-semibold">Address</label><textarea name="address" class="form-control" rows="2"><?= h($guest['address'] ?? '') ?></textarea></div>
                    <div class="col-12"><label class="form-label fw-semibold">Internal notes</label><textarea name="notes" class="form-control" rows="3" placeholder="Staff-only notes"><?= h($guest['notes'] ?? '') ?></textarea></div>
                </div>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2">
                <?= ars_ui_button('Save changes', ['type' => 'submit', 'size' => 'sm', 'icon' => 'check']) ?>
                <a href="guest_view.php?id=<?= $guestId ?>&amp;section=overview" class="btn btn-sm btn-outline-secondary">Cancel</a>
            </div>
        </div>
        </form>
    </div>
</div>

<?php elseif ($section === 'stays'): ?>
<div class="ars-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-journal-bookmark me-2"></i>Booking history <span class="badge bg-secondary ms-1"><?= count($bookings) ?></span></span>
        <a class="btn btn-sm btn-ars text-decoration-none" href="booking_add.php?guest_id=<?= $guestId ?>"><i class="bi bi-plus-lg me-1"></i>New booking</a>
    </div>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0 align-middle">
            <thead><tr><th>Booking</th><th>Unit</th><th>Dates</th><th class="text-end">Total</th><th class="text-end">Balance</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($bookings)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No bookings yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($bookings as $bk):
                $bal = (float)($bk['balance_due'] ?? 0);
            ?>
                <tr class="<?= $bal > 0.009 ? 'table-warning' : '' ?>">
                    <td data-label="Booking"><a href="booking_view.php?id=<?= (int)$bk['id'] ?>" class="fw-semibold text-decoration-none"><?= h($bk['booking_number']) ?></a></td>
                    <td data-label="Unit"><?= h($bk['unit_number'] ?? '—') ?><br><small class="text-muted"><?= h($bk['building_name'] ?? '') ?></small></td>
                    <td data-label="Dates"><?= h(ars_ds_format_stay($bk['check_in'] ?? '', $bk['check_out'] ?? '')) ?><br><small class="text-muted"><?= (int)$bk['nights'] ?> night<?= (int)$bk['nights'] !== 1 ? 's' : '' ?></small></td>
                    <td data-label="Total" class="text-end ars-tabular"><?= formatArsAmount($bk['total_amount'] ?? 0) ?></td>
                    <td data-label="Balance" class="text-end ars-tabular <?= $bal > 0.009 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= formatArsAmount($bal) ?></td>
                    <td data-label="Status"><?= ars_ui_status_badge('booking', (string)$bk['status']) ?></td>
                    <td data-label=""><a class="btn btn-sm btn-ars-outline text-decoration-none" href="booking_view.php?id=<?= (int)$bk['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($section === 'documents'): ?>
<?php
$docsByCategory = [];
foreach ($docCategories as $catKey => $catMeta) {
    $docsByCategory[$catKey] = 0;
}
$expiringDocs = [];
foreach ($guestDocs as $gd) {
    $catKey = ars_guest_doc_category_normalize($gd['doc_category'] ?? 'other');
    $docsByCategory[$catKey]++;
    $exp = (string)($gd['expiry_date'] ?? '');
    if ($exp !== '' && $exp <= $docExpirySoon) {
        $expiringDocs[] = $gd + ['_category' => $catKey];
    }
}
?>
<div class="row g-3 mb-4">
    <?php foreach ($docCategories as $catKey => $catMeta): ?>
    <div class="col-6 col-lg">
        <div class="ars-card h-100">
            <div class="card-body py-3">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <span class="small text-muted"><i class="bi <?= h($catMeta['icon']) ?> me-1"></i><?= h($catMeta['label']) ?></span>
                    <span class="badge <?= $docsByCategory[$catKey] > 0 ? 'bg-success' : 'bg-light text-muted border' ?>"><?= (int)$docsByCategory[$catKey] ?></span>
                </div>
                <button type="button" class="btn btn-sm btn-ars-outline w-100 mt-2"
                        data-bs-toggle="modal" data-bs-target="#guestDocModal"
                        data-ars-doc-category="<?= h($catKey) ?>">
                    <i class="bi bi-upload me-1"></i>Upload
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($expiringDocs)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong><?= count($expiringDocs) ?></strong> document<?= count($expiringDocs) !== 1 ? 's' : '' ?> expired or expiring within 30 days:
    <?php
    $expLabels = [];
    foreach ($expiringDocs as $ed) {
        $expLabels[] = h(ars_guest_doc_category_label($ed['_category'])) . ' (' . h((string)$ed['expiry_date']) . ')';
    }
    echo implode(', ', $expLabels);
    ?>
</div>
<?php endif; ?>

<div class="ars-card" id="guest-docs">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-folder2-open me-2"></i>Guest documents
            <span class="badge bg-secondary ms-1"><?= (int)$guestDocCount ?></span>
        </span>
        <button type="button" class="btn btn-ars btn-sm" data-bs-toggle="modal" data-bs-target="#guestDocModal">
            <i class="bi bi-upload me-1"></i>Upload document
        </button>
    </div>
    <div class="card-body pb-3">
        <?php if ($guestDocsError !== ''): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Could not load documents: <?= h($guestDocsError) ?></div>
        <?php endif; ?>
        <?php if (empty($guestDocs)): ?>
        <div class="border rounded text-center text-muted py-4 px-2 bg-light">
            <i class="bi bi-folder2-open fs-4 d-block mb-2"></i>
            No documents on this guest yet.<br>
            <small>Upload the Emirates ID, passport or visa copy — they stay on the profile across every stay.</small>
        </div>
        <?php else: ?>
        <div class="table-responsive border rounded">
            <table class="table ars-table ars-mobile-cards mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th style="min-width:170px">Type</th>
                        <th>Number</th>
                        <th>Expiry</th>
                        <th>Uploaded</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($guestDocs as $gd):
                    $gdId = (int)$gd['id'];
                    $gdCat = ars_guest_doc_category_normalize($gd['doc_category'] ?? 'other');
                    $gdExp = (string)($gd['expiry_date'] ?? '');
                    $gdSize = (int)($gd['file_size'] ?? 0);
                    $viewUrl = 'guest_document_view.php?guest_id=' . $guestId . '&attachment_id=' . $gdId;
                ?>
                    <tr>
                        <td data-label="Document">
                            <div class="fw-semibold text-break"><?= h($gd['original_name'] ?? 'file') ?></div>
                            <div class="small text-muted"><?= $gdSize > 0 ? h(number_format($gdSize / 1024, 0)) . ' KB' : '' ?></div>
                        </td>
                        <td data-label="Type">
                            <select class="form-select form-select-sm ars-guest-doc-refile"
                                    data-ars-attachment-id="<?= $gdId ?>"
                                    aria-label="Change document type">
                                <?php foreach ($docCategories as $optKey => $optMeta): ?>
                                <option value="<?= h($optKey) ?>" <?= $optKey === $gdCat ? 'selected' : '' ?>><?= h($optMeta['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td data-label="Number"><?= $gd['doc_number'] ? h((string)$gd['doc_number']) : '—' ?></td>
                        <td data-label="Expiry">
                            <?php if ($gdExp === ''): ?>—
                            <?php elseif ($gdExp < $docExpiryToday): ?>
                                <span class="badge bg-danger">Expired <?= h($gdExp) ?></span>
                            <?php elseif ($gdExp <= $docExpirySoon): ?>
                                <span class="badge bg-warning text-dark">Expires <?= h($gdExp) ?></span>
                            <?php else: ?>
                                <?= h($gdExp) ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Uploaded" class="small text-muted">
                            <?= h(substr((string)($gd['created_at'] ?? ''), 0, 16)) ?>
                            <?php if (!empty($gd['uploaded_by_name'])): ?><br><?= h((string)$gd['uploaded_by_name']) ?><?php endif; ?>
                        </td>
                        <td data-label="Actions" class="text-end">
                            <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                                <a class="btn btn-sm btn-ars-outline" href="<?= h($viewUrl) ?>" target="_blank" rel="noopener">View</a>
                                <a class="btn btn-sm btn-ars-outline" href="<?= h($viewUrl) ?>&amp;disposition=attachment">Download</a>
                                <button type="button" class="btn btn-sm btn-outline-danger ars-guest-doc-delete"
                                        data-ars-attachment-id="<?= $gdId ?>"
                                        data-ars-doc-name="<?= h($gd['original_name'] ?? 'file') ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <p class="small text-muted mt-3 mb-0">
            <i class="bi bi-info-circle me-1"></i>Max 10 MB per file. PDF, images, Office or text. Files are stored company-scoped and served only to signed-in staff.
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($canDeleteGuests && empty($deleteBlockers)): ?>
<div class="modal fade" id="deleteGuestModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <?php csrf_field(); ?>
            <input type="hidden" name="delete_guest" value="1">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>Delete guest</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Permanently delete <strong><?= h($guestName !== '' ? $guestName : ('Guest #' . $guestId)) ?></strong>?</p>
                <p class="small text-muted mb-0">This cannot be undone. The delete is re-checked on submit and refused if the guest has any booking, financial document, credit, occupancy record, or portal account.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete guest</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


<?php if ($section === 'documents'): ?>
<!-- Upload a guest document -->
<div class="modal fade" id="guestDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload guest document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="guestDocAlert" class="d-none"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="guestDocCategory">Document type</label>
                    <select id="guestDocCategory" class="form-select">
                        <?php foreach ($docCategories as $optKey => $optMeta): ?>
                        <option value="<?= h($optKey) ?>"<?= $optKey === 'emirates_id' ? ' selected' : '' ?>><?= h($optMeta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-7">
                        <label class="form-label fw-semibold" for="guestDocNumber">Document number <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" id="guestDocNumber" class="form-control" placeholder="784-0000-0000000-0">
                    </div>
                    <div class="col-12 col-sm-5">
                        <label class="form-label fw-semibold" for="guestDocExpiry">Expiry <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="date" id="guestDocExpiry" class="form-control">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" for="guestDocFile">File</label>
                    <input type="file" id="guestDocFile" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt">
                    <div class="form-text">Max 10 MB. PDF, images, Office or text.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" id="guestDocUploadBtn"><i class="bi bi-upload me-1"></i>Upload</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
  var GUEST_ID = <?= (int)$guestId ?>;
  var CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;

  function alertBox(msg, kind, targetId) {
    var el = document.getElementById(targetId || 'guestDocAlert');
    if (!el) { window.alert(msg); return; }
    el.className = 'alert alert-' + (kind || 'info');
    el.textContent = msg;
    el.classList.remove('d-none');
  }

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('guest_id', String(GUEST_ID));
    fd.append('_csrf', CSRF);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('ajax_guest_documents.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF }
    }).then(function (r) { return r.json(); });
  }

  var modal = document.getElementById('guestDocModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', function (ev) {
      var alertEl = document.getElementById('guestDocAlert');
      if (alertEl) { alertEl.classList.add('d-none'); alertEl.textContent = ''; }
      // The per-type "Upload" tiles preselect the type they sit under.
      var trigger = ev.relatedTarget;
      var preset = trigger && trigger.getAttribute('data-ars-doc-category');
      var catEl = document.getElementById('guestDocCategory');
      if (catEl && preset) {
        var opt = catEl.querySelector('option[value="' + preset + '"]');
        if (opt) catEl.value = preset;
      }
    });
  }

  var uploadBtn = document.getElementById('guestDocUploadBtn');
  if (uploadBtn) {
    uploadBtn.addEventListener('click', function () {
      var input = document.getElementById('guestDocFile');
      if (!input || !input.files || !input.files[0]) {
        alertBox('Choose a file first.', 'danger');
        return;
      }
      var fd = new FormData();
      fd.append('action', 'upload_guest_document');
      fd.append('guest_id', String(GUEST_ID));
      fd.append('_csrf', CSRF);
      fd.append('doc_category', (document.getElementById('guestDocCategory') || {}).value || 'other');
      fd.append('doc_number', (document.getElementById('guestDocNumber') || {}).value || '');
      fd.append('expiry_date', (document.getElementById('guestDocExpiry') || {}).value || '');
      fd.append('file', input.files[0]);
      uploadBtn.disabled = true;
      fetch('ajax_guest_documents.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF }
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          uploadBtn.disabled = false;
          if (d && d.success) {
            alertBox('Uploaded. Refreshing…', 'success');
            window.setTimeout(function () { location.reload(); }, 500);
          } else {
            alertBox((d && d.error) || 'Upload failed.', 'danger');
          }
        })
        .catch(function () {
          uploadBtn.disabled = false;
          alertBox('Network error.', 'danger');
        });
    });
  }

  document.querySelectorAll('.ars-guest-doc-refile').forEach(function (sel) {
    sel._prev = sel.value;
    sel.addEventListener('change', function () {
      sel.disabled = true;
      post('set_guest_document_category', {
        attachment_id: sel.getAttribute('data-ars-attachment-id'),
        doc_category: sel.value
      })
        .then(function (d) {
          if (d && d.success) {
            location.reload();
          } else {
            sel.disabled = false;
            sel.value = sel._prev;
            window.alert((d && d.error) || 'Could not move the document.');
          }
        })
        .catch(function () {
          sel.disabled = false;
          sel.value = sel._prev;
          window.alert('Network error moving the document.');
        });
    });
  });

  document.querySelectorAll('.ars-guest-doc-delete').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var name = btn.getAttribute('data-ars-doc-name') || 'this document';
      if (!window.confirm('Delete ' + name + '? This removes the file permanently.')) return;
      btn.disabled = true;
      post('delete_guest_document', { attachment_id: btn.getAttribute('data-ars-attachment-id') })
        .then(function (d) {
          if (d && d.success) {
            location.reload();
          } else {
            btn.disabled = false;
            window.alert((d && d.error) || 'Could not delete the document.');
          }
        })
        .catch(function () {
          btn.disabled = false;
          window.alert('Network error deleting the document.');
        });
    });
  });
})();
</script>
<?php endif; ?>

<?php ars_shell_end(); ?>
