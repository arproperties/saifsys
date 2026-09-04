<?php
require_once __DIR__ . '/includes/portal_auth.php';
require_portal_login();

$guestId      = current_portal_guest_id();
$arsCompanyId = getArsCompanyId($conn);
$settings     = getArsSettings($conn, $arsCompanyId);
$currency     = $settings['currency'] ?? 'AED';

expirePendingBookings($conn, $arsCompanyId);

$stmt = $conn->prepare("
    SELECT bk.*, u.unit_number, u.listing_title, b.name AS building_name,
           (SELECT file_path FROM ars_unit_photos WHERE unit_id = bk.unit_id AND is_primary = 1 LIMIT 1) AS photo
    FROM ars_bookings bk
    LEFT JOIN re_units u ON u.id = bk.unit_id
    LEFT JOIN re_buildings b ON b.id = u.building_id
    WHERE bk.guest_id = ?
    ORDER BY bk.check_in DESC
");
$stmt->execute([$guestId]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$upcoming = array_filter($bookings, fn($bk) => in_array($bk['status'], ['pending','confirmed','checked_in']) && $bk['check_out'] >= date('Y-m-d'));
$past     = array_filter($bookings, fn($bk) => !in_array($bk['status'], ['pending','confirmed','checked_in']) || $bk['check_out'] < date('Y-m-d'));

$pageTitle = 'My Bookings';
$_baseUrl = portal_base_url();
require_once __DIR__ . '/includes/portal_layout_header.php';

function portalStatusBadge(string $status): string {
    $map = [
        'pending'     => ['pending',   'clock'],
        'confirmed'   => ['confirmed', 'check-circle'],
        'checked_in'  => ['check-in',  'box-arrow-in-right'],
        'checked_out' => ['check-out', 'box-arrow-right'],
        'completed'   => ['completed', 'check-all'],
        'cancelled'   => ['cancelled', 'x-circle'],
        'expired'     => ['expired',   'clock-history'],
    ];
    $m = $map[$status] ?? ['pending', 'question-circle'];
    return '<span class="portal-badge ' . $m[0] . '"><i class="bi bi-' . $m[1] . '"></i> ' . ucfirst($status) . '</span>';
}
?>

<div class="container py-4">
    <h2 class="fw-bold mb-4"><i class="bi bi-grid-1x2 me-2"></i>My Bookings</h2>

    <?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success py-2">
        <i class="bi bi-check-circle me-1"></i>Booking submitted successfully! Our team will review it shortly.
    </div>
    <?php endif; ?>

    <!-- Upcoming -->
    <h5 class="fw-semibold mb-3">Upcoming & Active</h5>
    <?php if (empty($upcoming)): ?>
    <div class="portal-card p-4 text-center mb-4">
        <i class="bi bi-calendar-plus text-muted" style="font-size:2rem"></i>
        <p class="text-muted mt-2 mb-2">No upcoming bookings</p>
        <a href="<?= $_baseUrl ?>/" class="btn btn-portal btn-sm">Browse Rentals</a>
    </div>
    <?php else: ?>
    <div class="d-flex flex-column gap-3 mb-4">
        <?php foreach ($upcoming as $bk):
            $t = $bk['listing_title'] ?: $bk['building_name'] . ' - Unit ' . $bk['unit_number'];
            $photo = $bk['photo'] ? portal_base_url() . '/../' . $bk['photo'] : '';
        ?>
        <a href="booking.php?id=<?= $bk['id'] ?>" class="text-decoration-none">
            <div class="booking-card">
                <?php if ($photo): ?>
                <img src="<?= h($photo) ?>" alt="" class="booking-card-img">
                <?php else: ?>
                <div class="booking-card-img bg-light d-flex align-items-center justify-content-center"><i class="bi bi-house text-muted"></i></div>
                <?php endif; ?>
                <div class="booking-card-info">
                    <div class="booking-card-title"><?= h($t) ?></div>
                    <div class="booking-card-dates">
                        <?= date('M j', strtotime($bk['check_in'])) ?> — <?= date('M j, Y', strtotime($bk['check_out'])) ?>
                        &middot; <?= (int)$bk['nights'] ?> night<?= $bk['nights'] > 1 ? 's' : '' ?>
                    </div>
                    <div class="mt-1"><?= portalStatusBadge($bk['status']) ?></div>
                </div>
                <div class="text-end">
                    <div class="fw-bold" style="color:var(--portal-primary)"><?= $currency ?> <?= number_format((float)$bk['total_amount'], 2) ?></div>
                    <small class="text-muted">#<?= h($bk['booking_number']) ?></small>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Past -->
    <?php if (!empty($past)): ?>
    <h5 class="fw-semibold mb-3">Past Bookings</h5>
    <div class="d-flex flex-column gap-3 mb-4">
        <?php foreach ($past as $bk):
            $t = $bk['listing_title'] ?: $bk['building_name'] . ' - Unit ' . $bk['unit_number'];
            $photo = $bk['photo'] ? portal_base_url() . '/../' . $bk['photo'] : '';
        ?>
        <a href="booking.php?id=<?= $bk['id'] ?>" class="text-decoration-none">
            <div class="booking-card" style="opacity:.85">
                <?php if ($photo): ?>
                <img src="<?= h($photo) ?>" alt="" class="booking-card-img">
                <?php else: ?>
                <div class="booking-card-img bg-light d-flex align-items-center justify-content-center"><i class="bi bi-house text-muted"></i></div>
                <?php endif; ?>
                <div class="booking-card-info">
                    <div class="booking-card-title"><?= h($t) ?></div>
                    <div class="booking-card-dates">
                        <?= date('M j', strtotime($bk['check_in'])) ?> — <?= date('M j, Y', strtotime($bk['check_out'])) ?>
                    </div>
                    <div class="mt-1"><?= portalStatusBadge($bk['status']) ?></div>
                </div>
                <div class="text-end">
                    <div class="fw-bold"><?= $currency ?> <?= number_format((float)$bk['total_amount'], 2) ?></div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/portal_layout_footer.php'; ?>
