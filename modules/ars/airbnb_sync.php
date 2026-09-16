<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_airbnb_sync.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);

$ready = ars_airbnb_tables_ready($conn);
$cfg = ars_airbnb_config();
$state = [];
$warnings = [];
$review = $listings = $recent = [];

if ($ready) {
    $state = ars_airbnb_state($conn, $arsCompanyId, $cfg['mailbox']);
    $warnings = ars_airbnb_health_warnings($conn, $arsCompanyId);

    $st = $conn->prepare("
        SELECT e.id, e.sent_at, e.email_type, e.status, e.confirmation_code, e.listing_id, e.listing_title, e.guest_name,
               e.check_in, e.check_out, e.payout_amount, e.result_note, e.booking_id, b.booking_number,
               l.unit_id AS listing_unit_id
          FROM ars_channel_emails e
          LEFT JOIN ars_bookings b ON b.id = e.booking_id
          LEFT JOIN ars_channel_listings l ON l.company_id = e.company_id AND l.channel = e.channel AND l.listing_id = e.listing_id
         WHERE e.company_id = ? AND e.status = 'needs_review'
         ORDER BY e.sent_at, e.id
    ");
    $st->execute([$arsCompanyId]);
    $review = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $conn->prepare("
        SELECT l.*, u.unit_number, bld.name AS building_name,
               (SELECT COUNT(*) FROM ars_channel_emails e WHERE e.company_id = l.company_id AND e.listing_id = l.listing_id AND e.email_type = 'confirmed') AS bookings_seen
          FROM ars_channel_listings l
          LEFT JOIN re_units u ON u.id = l.unit_id
          LEFT JOIN re_buildings bld ON bld.id = u.building_id
         WHERE l.company_id = ? AND l.channel = ?
         ORDER BY l.unit_id IS NOT NULL, l.last_seen_at DESC
    ");
    $st->execute([$arsCompanyId, ARS_AIRBNB_CHANNEL]);
    $listings = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $conn->prepare("
        SELECT e.id, e.sent_at, e.email_type, e.status, e.confirmation_code, e.guest_name, e.listing_title, e.check_in, e.check_out,
               e.payout_amount, e.result_note, e.booking_id, b.booking_number
          FROM ars_channel_emails e
          LEFT JOIN ars_bookings b ON b.id = e.booking_id
         WHERE e.company_id = ? AND e.status <> 'needs_review'
         ORDER BY e.sent_at DESC, e.id DESC
         LIMIT 50
    ");
    $st->execute([$arsCompanyId]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
}

$units = ars_fetch_short_term_units($conn, $arsCompanyId);
// Same rule as ars_require_booking_action('confirm'): core staff, or admins without department flags.
$canAct = ars_user_has_core($conn) || !ars_user_has_ops($conn);

$typeLabels = [
    'confirmed' => ['New booking', 'success'],
    'guest_cancel' => ['Guest cancelled', 'danger'],
    'host_cancel' => ['You cancelled', 'danger'],
    'updated' => ['Reservation changed', 'warning'],
    'change_request' => ['Change request', 'secondary'],
];
$statusLabels = [
    'processed' => ['Done', 'success'],
    'resolved' => ['Handled by staff', 'secondary'],
    'info' => ['No action', 'light'],
    'new' => ['Waiting', 'info'],
    'needs_review' => ['Needs attention', 'warning'],
];
$badge = static function (array $map, string $key): string {
    [$label, $tone] = $map[$key] ?? [$key, 'secondary'];
    return '<span class="badge bg-' . $tone . ($tone === 'light' || $tone === 'warning' ? ' text-dark' : '') . '">' . h($label) . '</span>';
};
$dates = static function (?string $in, ?string $out): string {
    if (!$in) return '—';
    return h(date('d M Y', strtotime($in))) . ($out ? ' → ' . h(date('d M Y', strtotime($out))) : '');
};
$unitOptions = static function (?int $selected) use ($units): string {
    $html = '<option value="">Select unit…</option>';
    foreach ($units as $u) {
        $html .= '<option value="' . (int)$u['id'] . '"' . ((int)$selected === (int)$u['id'] ? ' selected' : '') . '>'
            . h($u['unit_number']) . ' — ' . h((string)$u['building_name']) . '</option>';
    }
    return $html;
};

ars_shell_begin([
    'title' => 'Airbnb Sync',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Bookings', 'href' => 'bookings.php'],
        ['label' => 'Airbnb Sync'],
    ],
    'actions_html' => ($ready && $cfg['configured'] && $canAct)
        ? ars_ui_button('Sync now', ['icon' => 'refresh-cw', 'size' => 'sm', 'attrs' => 'id="syncNowBtn" onclick="syncNow()"'])
        : '',
    'legacy_bootstrap' => true,
]);
?>

<div id="syncAlert"></div>

<?php if (!$ready): ?>
<div class="alert alert-warning">
    <i class="bi bi-database-exclamation me-2"></i>Airbnb sync is not installed yet. Run <code>migrations/ars_airbnb_email_sync.sql</code> on this database.
</div>
<?php else: ?>

<!-- Status -->
<div class="ars-card mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <div class="small text-muted">Gmail account</div>
                <div class="fw-semibold"><?= $cfg['configured'] ? h($cfg['user']) : '<span class="text-danger">Not set up</span>' ?></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="small text-muted">Last checked</div>
                <div class="fw-semibold"><?= !empty($state['last_run_at']) ? h(date('d M, H:i', strtotime($state['last_run_at']))) : '—' ?></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="small text-muted">Last successful</div>
                <div class="fw-semibold"><?= !empty($state['last_success_at']) ? h(date('d M, H:i', strtotime($state['last_success_at']))) : '—' ?></div>
            </div>
            <div class="col-6 col-md-2">
                <div class="small text-muted">Needs attention</div>
                <div class="fw-semibold <?= $review ? 'text-warning' : '' ?>"><?= count($review) ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Importing bookings made after</div>
                <div class="fw-semibold"><?= !empty($state['start_at']) ? h(date('d M Y, H:i', strtotime($state['start_at']))) : '—' ?></div>
            </div>
        </div>
        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-danger mt-3 mb-0 py-2"><i class="bi bi-exclamation-triangle me-2"></i><?= h($w) ?></div>
        <?php endforeach; ?>
        <p class="small text-muted mt-3 mb-0">
            Only bookings made on Airbnb after the time above are imported; existing bookings are left as they are.
            New Airbnb bookings are added as <strong>confirmed</strong> at the Airbnb payout with no VAT. Guest cancellations cancel the booking.
            Airbnb's change emails don't include the new dates or payout, so changed reservations are listed below for staff to amend.
        </p>
    </div>
</div>

<!-- Needs attention -->
<h5 class="mb-2">Needs attention</h5>
<div class="ars-card mb-4">
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0 align-middle">
            <thead>
                <tr><th>Received</th><th>Email</th><th>Guest / listing</th><th>Stay</th><th>Payout</th><th style="min-width:280px">What to do</th></tr>
            </thead>
            <tbody>
            <?php if (!$review): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-check2-circle fs-3 d-block mb-2"></i>Nothing needs attention.</td></tr>
            <?php endif; ?>
            <?php foreach ($review as $r): ?>
                <tr>
                    <td data-label="Received" class="text-nowrap"><?= h(date('d M, H:i', strtotime($r['sent_at']))) ?></td>
                    <td data-label="Email"><?= $badge($typeLabels, $r['email_type']) ?><?php if ($r['confirmation_code']): ?><br><small class="text-muted"><?= h($r['confirmation_code']) ?></small><?php endif; ?></td>
                    <td data-label="Guest / listing"><strong><?= h((string)$r['guest_name']) ?></strong><br><small><?= h((string)$r['listing_title']) ?></small></td>
                    <td data-label="Stay" class="text-nowrap"><?= $dates($r['check_in'], $r['check_out']) ?></td>
                    <td data-label="Payout" class="text-nowrap"><?= $r['payout_amount'] !== null && $r['email_type'] === 'confirmed' ? 'AED ' . number_format((float)$r['payout_amount'], 2) : '—' ?></td>
                    <td data-label="What to do">
                        <div class="small mb-2"><?= h((string)$r['result_note']) ?></div>
                        <?php if ($r['booking_id']): ?>
                            <a href="booking_view.php?id=<?= (int)$r['booking_id'] ?>" class="btn btn-sm btn-outline-primary mb-1"><i class="bi bi-box-arrow-up-right me-1"></i><?= h((string)$r['booking_number']) ?></a>
                        <?php endif; ?>
                        <?php if ($canAct): ?>
                            <?php if ($r['email_type'] === 'confirmed' && $r['check_in'] && $r['payout_amount'] !== null): ?>
                                <div class="input-group input-group-sm mb-1">
                                    <select class="form-select" id="unit_<?= (int)$r['id'] ?>"><?= $unitOptions($r['listing_unit_id'] !== null ? (int)$r['listing_unit_id'] : null) ?></select>
                                    <button class="btn btn-ars" onclick="createOnUnit(<?= (int)$r['id'] ?>)">Create booking</button>
                                </div>
                                <?php if ($r['listing_id']): ?>
                                <div class="form-check small mb-2">
                                    <input class="form-check-input" type="checkbox" id="remember_<?= (int)$r['id'] ?>" <?= $r['listing_unit_id'] === null ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="remember_<?= (int)$r['id'] ?>">Use this unit for future bookings of this listing</label>
                                </div>
                                <?php endif; ?>
                                <div class="input-group input-group-sm mb-1">
                                    <input type="text" class="form-control" id="link_<?= (int)$r['id'] ?>" placeholder="Already entered? e.g. ARS-26-00150">
                                    <button class="btn btn-outline-secondary" onclick="linkBooking(<?= (int)$r['id'] ?>)">Link</button>
                                </div>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary" onclick="retryEmail(<?= (int)$r['id'] ?>)"><i class="bi bi-arrow-repeat me-1"></i>Retry</button>
                            <button class="btn btn-sm btn-outline-success" onclick="dismissEmail(<?= (int)$r['id'] ?>)"><i class="bi bi-check2 me-1"></i>Done</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Listings -->
<h5 class="mb-2">Airbnb listings</h5>
<p class="small text-muted mb-2">Each Airbnb listing books into its linked unit. Listings appear here the first time a booking for them arrives.</p>
<div class="ars-card mb-4">
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0 align-middle">
            <thead><tr><th>Airbnb listing</th><th>Listing ID</th><th>Bookings seen</th><th style="min-width:260px">ARS unit</th></tr></thead>
            <tbody>
            <?php if (!$listings): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No Airbnb listings seen yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($listings as $l): ?>
                <tr>
                    <td data-label="Listing"><strong><?= h((string)($l['listing_title'] ?: 'Untitled')) ?></strong><?php if (!$l['unit_id']): ?> <span class="badge bg-warning text-dark">Not linked</span><?php endif; ?></td>
                    <td data-label="Listing ID"><a href="https://www.airbnb.com/rooms/<?= h($l['listing_id']) ?>" target="_blank" rel="noopener" class="small"><?= h($l['listing_id']) ?></a></td>
                    <td data-label="Bookings seen"><?= (int)$l['bookings_seen'] ?></td>
                    <td data-label="ARS unit">
                        <?php if ($canAct): ?>
                        <div class="input-group input-group-sm">
                            <select class="form-select" id="listing_<?= h($l['listing_id']) ?>"><?= $unitOptions($l['unit_id'] !== null ? (int)$l['unit_id'] : null) ?></select>
                            <button class="btn btn-outline-primary" onclick="saveListing('<?= h($l['listing_id']) ?>')">Save</button>
                        </div>
                        <?php else: ?>
                            <?= $l['unit_id'] ? h($l['unit_number'] . ' — ' . $l['building_name']) : '—' ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent -->
<h5 class="mb-2">Recent Airbnb emails</h5>
<div class="ars-card">
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0 align-middle">
            <thead><tr><th>Received</th><th>Email</th><th>Guest / listing</th><th>Stay</th><th>Result</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No Airbnb booking emails processed yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td data-label="Received" class="text-nowrap"><?= h(date('d M, H:i', strtotime($r['sent_at']))) ?></td>
                    <td data-label="Email"><?= $badge($typeLabels, $r['email_type']) ?><?php if ($r['confirmation_code']): ?><br><small class="text-muted"><?= h($r['confirmation_code']) ?></small><?php endif; ?></td>
                    <td data-label="Guest / listing"><?= h((string)$r['guest_name']) ?><br><small class="text-muted"><?= h((string)$r['listing_title']) ?></small></td>
                    <td data-label="Stay" class="text-nowrap"><?= $dates($r['check_in'], $r['check_out']) ?></td>
                    <td data-label="Result">
                        <?= $badge($statusLabels, $r['status']) ?>
                        <?php if ($r['booking_id']): ?> <a href="booking_view.php?id=<?= (int)$r['booking_id'] ?>"><?= h((string)$r['booking_number']) ?></a><?php endif; ?>
                        <div class="small text-muted"><?= h((string)$r['result_note']) ?></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
$pageScripts = <<<'JS'
<script>
function showSyncAlert(msg, type) {
    const el = document.getElementById('syncAlert');
    el.innerHTML = '';
    const div = document.createElement('div');
    div.className = 'alert alert-' + type + ' alert-dismissible fade show';
    div.textContent = msg;
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn-close'; btn.setAttribute('data-bs-dismiss', 'alert');
    div.appendChild(btn);
    el.appendChild(div);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function airbnbAction(data) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    fd.append('_csrf', window.ARS_CSRF || '');
    return fetch('ajax_airbnb_sync_actions.php', { method: 'POST', body: fd }).then(r => r.json());
}
function done(d, reload) {
    if (d.success) {
        if (d.message) showSyncAlert(d.message, 'success');
        if (reload) setTimeout(() => location.reload(), d.message ? 1500 : 0);
    } else {
        showSyncAlert(d.error || 'Failed', 'danger');
    }
}
function syncNow() {
    const btn = document.getElementById('syncNowBtn');
    if (btn) btn.disabled = true;
    showSyncAlert('Checking Gmail for Airbnb emails…', 'info');
    airbnbAction({ action: 'sync_now' }).then(d => done(d, true))
        .catch(() => showSyncAlert('Network error', 'danger'))
        .finally(() => { if (btn) btn.disabled = false; });
}
function createOnUnit(id) {
    const unit = document.getElementById('unit_' + id).value;
    if (!unit) { showSyncAlert('Pick a unit first.', 'danger'); return; }
    const remember = document.getElementById('remember_' + id);
    airbnbAction({ action: 'create_on_unit', email_id: id, unit_id: unit, remember: remember && remember.checked ? 1 : '' })
        .then(d => done(d, d.success)).catch(() => showSyncAlert('Network error', 'danger'));
}
function linkBooking(id) {
    const bn = document.getElementById('link_' + id).value.trim();
    if (!bn) { showSyncAlert('Type the ARS booking number to link.', 'danger'); return; }
    if (!confirm('Link Airbnb reservation to ' + bn + '? The booking amount and dates are not changed.')) return;
    airbnbAction({ action: 'link_booking', email_id: id, booking_number: bn })
        .then(d => done(d, d.success)).catch(() => showSyncAlert('Network error', 'danger'));
}
function retryEmail(id) {
    airbnbAction({ action: 'retry', email_id: id }).then(d => done(d, true)).catch(() => showSyncAlert('Network error', 'danger'));
}
function dismissEmail(id) {
    const note = prompt('Mark as handled. Optional note (what you did):', '');
    if (note === null) return;
    airbnbAction({ action: 'dismiss', email_id: id, note: note }).then(d => done(d, true)).catch(() => showSyncAlert('Network error', 'danger'));
}
function saveListing(listingId) {
    const unit = document.getElementById('listing_' + listingId).value;
    airbnbAction({ action: 'save_listing', listing_id: listingId, unit_id: unit })
        .then(d => done(Object.assign({ message: 'Listing saved.' }, d), d.success)).catch(() => showSyncAlert('Network error', 'danger'));
}
</script>
JS;
if (!empty($GLOBALS['ars_shell_state'])) {
    $GLOBALS['ars_shell_state']['pageScripts'] = $pageScripts;
}
ars_shell_end();
