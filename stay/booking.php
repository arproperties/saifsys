<?php
require_once __DIR__ . '/includes/portal_auth.php';
require_portal_login();

$bookingId    = (int)($_GET['id'] ?? 0);
$guestId      = current_portal_guest_id();
$arsCompanyId = getArsCompanyId($conn);
$settings     = getArsSettings($conn, $arsCompanyId);
$currency     = $settings['currency'] ?? 'AED';

if (!$bookingId) { header('Location: dashboard.php'); exit; }

$stmt = $conn->prepare("
    SELECT bk.*, u.unit_number, u.listing_title, b.name AS building_name, b.address AS building_address,
           g.first_name AS guest_first, g.last_name AS guest_last,
           (SELECT file_path FROM ars_unit_photos WHERE unit_id = bk.unit_id AND is_primary = 1 LIMIT 1) AS photo
    FROM ars_bookings bk
    LEFT JOIN re_units u ON u.id = bk.unit_id
    LEFT JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN ars_guests g ON g.id = bk.guest_id
    WHERE bk.id = ? AND bk.guest_id = ?
    LIMIT 1
");
$stmt->execute([$bookingId, $guestId]);
$bk = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$bk) { header('Location: dashboard.php'); exit; }

$title = $bk['listing_title'] ?: $bk['building_name'] . ' - Unit ' . $bk['unit_number'];
$photo = $bk['photo'] ? portal_base_url() . '/../' . $bk['photo'] : '';

$payments = $conn->prepare("SELECT * FROM ars_booking_payments WHERE booking_id = ? ORDER BY payment_date DESC");
$payments->execute([$bookingId]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

function portalBadge(string $status): string {
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
    return '<span class="portal-badge ' . $m[0] . '"><i class="bi bi-' . $m[1] . '"></i> ' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

$pageTitle = 'Booking #' . $bk['booking_number'];
$_baseUrl = portal_base_url();
require_once __DIR__ . '/includes/portal_layout_header.php';
?>

<div class="container py-4">
    <?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success py-2 mb-4">
        <i class="bi bi-check-circle me-1"></i><strong>Booking submitted!</strong> Our team will review and confirm your booking shortly. You will receive a confirmation notification.
    </div>
    <?php endif; ?>

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        <h2 class="fw-bold mb-0">Booking #<?= h($bk['booking_number']) ?></h2>
        <?= portalBadge($bk['status']) ?>
    </div>

    <div class="row g-4">
        <!-- Left Column -->
        <div class="col-lg-8">
            <!-- Unit Card -->
            <div class="portal-card p-0 mb-4">
                <div class="d-flex flex-column flex-md-row">
                    <?php if ($photo): ?>
                    <img src="<?= h($photo) ?>" alt="" style="width:200px;height:160px;object-fit:cover;border-radius:var(--portal-card-radius) 0 0 var(--portal-card-radius)" class="d-none d-md-block">
                    <img src="<?= h($photo) ?>" alt="" style="width:100%;height:180px;object-fit:cover;border-radius:var(--portal-card-radius) var(--portal-card-radius) 0 0" class="d-md-none">
                    <?php endif; ?>
                    <div class="p-3 flex-grow-1">
                        <h5 class="fw-bold mb-1"><?= h($title) ?></h5>
                        <p class="text-muted mb-2"><i class="bi bi-geo-alt me-1"></i><?= h($bk['building_name']) ?></p>
                        <div class="d-flex flex-wrap gap-3">
                            <div><small class="text-muted d-block">Check-in</small><strong><?= date('M j, Y', strtotime($bk['check_in'])) ?></strong></div>
                            <div><small class="text-muted d-block">Check-out</small><strong><?= date('M j, Y', strtotime($bk['check_out'])) ?></strong></div>
                            <div><small class="text-muted d-block">Nights</small><strong><?= (int)$bk['nights'] ?></strong></div>
                            <div><small class="text-muted d-block">Guests</small><strong><?= (int)$bk['num_guests'] ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Info -->
            <?php if ($bk['status'] === 'pending'): ?>
            <div class="alert alert-warning py-3 mb-4">
                <i class="bi bi-hourglass-split me-2"></i>
                <strong>Booking Pending</strong> — Our team is reviewing your request.
                <?php if ($bk['expires_at']): ?>
                <br><small class="text-muted">Expires: <?= date('M j, Y g:i A', strtotime($bk['expires_at'])) ?></small>
                <?php endif; ?>
            </div>
            <?php elseif ($bk['status'] === 'confirmed'): ?>
            <div class="alert alert-success py-3 mb-4">
                <i class="bi bi-check-circle me-2"></i>
                <strong>Booking Confirmed!</strong> — See payment instructions below.
            </div>
            <?php elseif ($bk['status'] === 'cancelled'): ?>
            <div class="alert alert-secondary py-3 mb-4">
                <i class="bi bi-x-circle me-2"></i>
                <strong>Booking Cancelled</strong>
            </div>
            <?php elseif ($bk['status'] === 'expired'): ?>
            <div class="alert alert-secondary py-3 mb-4">
                <i class="bi bi-clock-history me-2"></i>
                <strong>Booking Expired</strong> — Please create a new booking if you'd like to try again.
            </div>
            <?php endif; ?>

            <!-- Special Requests -->
            <?php if ($bk['special_requests']): ?>
            <div class="portal-card p-3 mb-4">
                <h6 class="fw-bold mb-2"><i class="bi bi-chat-text me-2"></i>Special Requests</h6>
                <p class="mb-0 text-muted"><?= nl2br(h($bk['special_requests'])) ?></p>
            </div>
            <?php endif; ?>

            <!-- Payment Instructions (for confirmed bookings) -->
            <?php if (in_array($bk['status'], ['confirmed', 'checked_in'])): ?>
            <div class="portal-card p-3 mb-4">
                <h6 class="fw-bold mb-2"><i class="bi bi-credit-card me-2"></i>Payment Instructions</h6>
                <p class="text-muted mb-2">Please complete payment using the method provided by our team:</p>
                <ul class="text-muted mb-0">
                    <li>A payment link will be sent to your email</li>
                    <li>You can also pay via bank transfer — details will be shared by our team</li>
                    <li>Once payment is confirmed, your booking status will be updated</li>
                </ul>
                <?php
                $paidPayments = array_filter($payments, fn($p) => ($p['payment_link_status'] ?? '') === 'paid');
                if (!empty($paidPayments)): ?>
                <div class="mt-3 p-2 bg-light rounded">
                    <small class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>Payment received — thank you!</small>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Payments History -->
            <?php if (!empty($payments)): ?>
            <div class="portal-card p-3 mb-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-receipt me-2"></i>Payments</h6>
                <?php foreach ($payments as $pay): ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <small class="text-muted"><?= date('M j, Y', strtotime($pay['payment_date'])) ?></small>
                        <span class="ms-2"><?= h(ucfirst($pay['payment_method'] ?? 'N/A')) ?></span>
                        <?php if ($pay['reference_number']): ?>
                        <small class="text-muted ms-1">Ref: <?= h($pay['reference_number']) ?></small>
                        <?php endif; ?>
                    </div>
                    <strong><?= $currency ?> <?= number_format((float)$pay['amount'], 2) ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right: Price Summary -->
        <div class="col-lg-4">
            <div class="pricing-sticky">
                <div class="pricing-card">
                    <h6 class="fw-bold mb-3">Price Summary</h6>

                    <div class="d-flex justify-content-between mb-2">
                        <span><?= $currency ?> <?= number_format((float)$bk['nightly_rate'], 2) ?> x <?= (int)$bk['nights'] ?> nights</span>
                        <span><?= $currency ?> <?= number_format((float)$bk['subtotal'], 2) ?></span>
                    </div>

                    <?php if ((float)$bk['length_discount_amount'] > 0): ?>
                    <div class="d-flex justify-content-between mb-2 text-success small">
                        <span><?= h($bk['length_discount_label'] ?: 'Length discount') ?></span>
                        <span>-<?= $currency ?> <?= number_format((float)$bk['length_discount_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ((float)$bk['discount_amount'] > 0): ?>
                    <div class="d-flex justify-content-between mb-2 text-success small">
                        <span><?= h($bk['discount_label'] ?: 'Discount') ?></span>
                        <span>-<?= $currency ?> <?= number_format((float)$bk['discount_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ((float)$bk['extras_total'] > 0): ?>
                    <div class="d-flex justify-content-between mb-2 small">
                        <span>Extra charges</span>
                        <span><?= $currency ?> <?= number_format((float)$bk['extras_total'], 2) ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mb-2 text-muted small">
                        <span>VAT (<?= number_format((float)$bk['vat_rate'], 0) ?>%)</span>
                        <span><?= $currency ?> <?= number_format((float)$bk['vat_amount'], 2) ?></span>
                    </div>

                    <hr>
                    <div class="d-flex justify-content-between fw-bold fs-5">
                        <span>Total</span>
                        <span style="color:var(--portal-primary)"><?= $currency ?> <?= number_format((float)$bk['total_amount'], 2) ?></span>
                    </div>

                    <?php if ((float)$bk['paid_amount'] > 0): ?>
                    <div class="d-flex justify-content-between mt-2 small">
                        <span class="text-success">Paid</span>
                        <span class="text-success"><?= $currency ?> <?= number_format((float)$bk['paid_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ((float)$bk['balance_due'] > 0 && !in_array($bk['status'], ['cancelled','expired'])): ?>
                    <div class="d-flex justify-content-between small">
                        <span class="text-danger">Balance Due</span>
                        <span class="text-danger"><?= $currency ?> <?= number_format((float)$bk['balance_due'], 2) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ((float)$bk['deposit_amount'] > 0): ?>
                    <hr>
                    <div class="d-flex justify-content-between small">
                        <span>Security Deposit</span>
                        <span><?= $currency ?> <?= number_format((float)$bk['deposit_amount'], 2) ?></span>
                    </div>
                    <div class="small text-muted">Status: <?= ucfirst($bk['deposit_status'] ?? 'none') ?></div>
                    <?php endif; ?>
                </div>

                <!-- Book Again -->
                <?php if (in_array($bk['status'], ['completed','cancelled','expired'])): ?>
                <a href="unit.php?id=<?= $bk['unit_id'] ?>" class="btn btn-portal-outline w-100 mt-3">
                    <i class="bi bi-arrow-repeat me-1"></i>Book Again
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/portal_layout_footer.php'; ?>
