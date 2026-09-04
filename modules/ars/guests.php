<?php
/**
 * Guests directory — searchable list with ARS design-system chrome.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_guest'])) {
    csrf_verify();
    $fn = trim((string)($_POST['first_name'] ?? ''));
    $ln = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $nationality = trim((string)($_POST['nationality'] ?? ''));
    $idType = $_POST['id_type'] ?? null;
    $idNumber = trim((string)($_POST['id_number'] ?? ''));

    if ($fn === '' || $ln === '') {
        $error = 'First and last name are required.';
    } else {
        try {
            $conn->prepare("
                INSERT INTO ars_guests (company_id, first_name, last_name, email, phone, nationality, id_type, id_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $arsCompanyId,
                $fn,
                $ln,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $nationality !== '' ? $nationality : null,
                $idType ?: null,
                $idNumber !== '' ? $idNumber : null,
            ]);
            $success = 'Guest added successfully.';
        } catch (PDOException $e) {
            $error = 'Failed to add guest: ' . $e->getMessage();
        }
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'with_bookings', 'no_bookings'], true)) {
    $filter = 'all';
}

$sql = "
    SELECT g.*,
           (SELECT COUNT(*) FROM ars_bookings b
             WHERE b.guest_id = g.id AND b.company_id = g.company_id
               AND b.status NOT IN ('cancelled','expired')) AS live_bookings,
           (SELECT MAX(b2.check_in) FROM ars_bookings b2
             WHERE b2.guest_id = g.id AND b2.company_id = g.company_id
               AND b2.status NOT IN ('cancelled','expired')) AS last_check_in
    FROM ars_guests g
    WHERE g.company_id = ?
";
$params = [$arsCompanyId];
if ($search !== '') {
    $sql .= " AND (g.first_name LIKE ? OR g.last_name LIKE ? OR g.email LIKE ? OR g.phone LIKE ? OR CONCAT(g.first_name,' ',g.last_name) LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
$sql .= ' ORDER BY g.last_name, g.first_name LIMIT 500';
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$guests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($filter === 'with_bookings') {
    $guests = array_values(array_filter($guests, static fn($g) => (int)($g['live_bookings'] ?? 0) > 0));
} elseif ($filter === 'no_bookings') {
    $guests = array_values(array_filter($guests, static fn($g) => (int)($g['live_bookings'] ?? 0) === 0));
}

$totalGuests = count($guests);
$withBookings = count(array_filter($guests, static fn($g) => (int)($g['live_bookings'] ?? 0) > 0));
$totalSpentSum = 0.0;
foreach ($guests as $g) {
    $totalSpentSum += (float)($g['total_spent'] ?? 0);
}

$pageTitle = 'Guests';
ars_shell_begin([
    'title' => 'Guests',
    'subtitle' => $totalGuests . ' shown' . ($search !== '' ? ' · filtered' : ''),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Guests'],
    ],
    'actions_html' => ars_ui_button('Add guest', [
        'icon' => 'plus',
        'size' => 'sm',
        'class' => 'ars-btn-press',
        'attrs' => 'data-bs-toggle="modal" data-bs-target="#addGuestModal"',
    ]),
    'toolbar_html' => ars_ds_segment([
        ['id' => 'all', 'label' => 'All', 'icon' => 'users', 'href' => 'guests.php' . ($search !== '' ? '?q=' . rawurlencode($search) : ''), 'active' => $filter === 'all'],
        ['id' => 'with', 'label' => 'With stays', 'icon' => 'calendar-check', 'href' => 'guests.php?filter=with_bookings' . ($search !== '' ? '&q=' . rawurlencode($search) : ''), 'active' => $filter === 'with_bookings'],
        ['id' => 'none', 'label' => 'No stays', 'icon' => 'user', 'href' => 'guests.php?filter=no_bookings' . ($search !== '' ? '&q=' . rawurlencode($search) : ''), 'active' => $filter === 'no_bookings'],
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

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4"><?= ars_ds_stat_tile('Guests', (string)$totalGuests, ['icon' => 'users', 'hint' => 'In this view']) ?></div>
    <div class="col-6 col-lg-4"><?= ars_ds_stat_tile('With stays', (string)$withBookings, ['tone' => 'ok', 'icon' => 'calendar-check', 'hint' => 'Active booking history']) ?></div>
    <div class="col-12 col-lg-4"><?= ars_ds_stat_tile('Recorded spend', formatArsAmount($totalSpentSum), ['icon' => 'wallet', 'hint' => 'From guest totals']) ?></div>
</div>

<?php ob_start(); ?>
<form method="get" class="d-flex flex-wrap align-items-end gap-2" role="search">
    <?php if ($filter !== 'all'): ?>
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <?php endif; ?>
    <div class="flex-grow-1" style="min-width:14rem">
        <label class="form-label small fw-semibold mb-1" for="guestSearch">Search</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input id="guestSearch" type="text" name="q" class="form-control" placeholder="Name, email, or phone…" value="<?= h($search) ?>">
        </div>
    </div>
    <button type="submit" class="btn btn-sm btn-ars-outline"><i class="bi bi-funnel me-1"></i>Search</button>
    <?php if ($search !== '' || $filter !== 'all'): ?>
    <a href="guests.php" class="btn btn-sm btn-outline-secondary">Clear</a>
    <?php endif; ?>
</form>
<?php echo ars_ds_filter_card(ob_get_clean()); ?>

<div class="ars-card mt-3">
    <?php if (empty($guests)): ?>
        <div class="p-4">
            <?= ars_ui_empty_state(
                $search !== '' ? 'No guests match your search' : 'No guests yet',
                $search !== '' ? 'Try another name, email, or phone.' : 'Add a guest to start taking bookings.',
                [
                    'action_html' => '<button type="button" class="btn btn-ars btn-sm" data-bs-toggle="modal" data-bs-target="#addGuestModal"><i class="bi bi-plus-lg me-1"></i>Add guest</button>',
                ]
            ) ?>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0 align-middle">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Contact</th>
                    <th>Nationality</th>
                    <th class="text-center">Stays</th>
                    <th class="text-end">Total spent</th>
                    <th>Last check-in</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($guests as $g):
                $name = trim(($g['first_name'] ?? '') . ' ' . ($g['last_name'] ?? ''));
                $stays = (int)($g['live_bookings'] ?? $g['total_bookings'] ?? 0);
                $lastIn = (string)($g['last_check_in'] ?? '');
            ?>
                <tr class="ars-row-click" style="cursor:pointer" onclick="location.href='guest_view.php?id=<?= (int)$g['id'] ?>'">
                    <td data-label="Guest">
                        <div class="d-flex align-items-center gap-2">
                            <?= ars_ds_avatar(ars_ds_guest_initials($g['first_name'] ?? '', $g['last_name'] ?? '')) ?>
                            <div>
                                <div class="fw-semibold"><?= h($name) ?></div>
                                <?php if (!empty($g['id_number'])): ?>
                                <div class="small text-muted"><?= h(ucwords(str_replace('_', ' ', (string)($g['id_type'] ?? 'ID')))) ?> · <?= h($g['id_number']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td data-label="Contact">
                        <div class="small"><?= h($g['email'] ?: '—') ?></div>
                        <div class="small text-muted"><?= h($g['phone'] ?: '') ?></div>
                    </td>
                    <td data-label="Nationality"><?= h($g['nationality'] ?: '—') ?></td>
                    <td data-label="Stays" class="text-center">
                        <span class="badge <?= $stays > 0 ? 'bg-success' : 'bg-secondary' ?>"><?= $stays ?></span>
                    </td>
                    <td data-label="Total spent" class="text-end ars-tabular fw-semibold"><?= formatArsAmount($g['total_spent'] ?? 0) ?></td>
                    <td data-label="Last check-in" class="text-nowrap small text-muted"><?= $lastIn !== '' ? h($lastIn) : '—' ?></td>
                    <td data-label="" onclick="event.stopPropagation()">
                        <?= ars_ui_button('Open', ['href' => 'guest_view.php?id=' . (int)$g['id'], 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'eye']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="addGuestModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?php csrf_field(); ?>
            <input type="hidden" name="add_guest" value="1">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add guest</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">First name *</label><input type="text" name="first_name" class="form-control" required autofocus></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Last name *</label><input type="text" name="last_name" class="form-control" required></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" class="form-control" placeholder="guest@email.com"></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" class="form-control" placeholder="+971…"></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Nationality</label><input type="text" name="nationality" class="form-control"></div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label fw-semibold">ID type</label>
                        <select name="id_type" class="form-select">
                            <option value="">—</option>
                            <option value="emirates_id">Emirates ID</option>
                            <option value="passport">Passport</option>
                            <option value="visa">Visa</option>
                            <option value="driving_license">Driving License</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label fw-semibold">ID number</label><input type="text" name="id_number" class="form-control"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-ars"><i class="bi bi-check-lg me-1"></i>Add guest</button>
            </div>
        </form>
    </div>
</div>

<?php ars_shell_end(); ?>
