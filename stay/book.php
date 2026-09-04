<?php
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/../modules/ars/includes/ars_availability.php';
require_once __DIR__ . '/../modules/ars/includes/ars_pricing.php';
require_once __DIR__ . '/../modules/ars/includes/ars_guest_notifications.php';

$arsCompanyId = getArsCompanyId($conn);
$settings     = getArsSettings($conn, $arsCompanyId);
$currency     = $settings['currency'] ?? 'AED';
$vatRate      = (float)($settings['default_vat_rate'] ?? 5);
$expiryHours  = (int)($settings['pending_expiry_hours'] ?? 24);

expirePendingBookings($conn, $arsCompanyId);

$unitId   = (int)($_GET['unit_id'] ?? $_POST['unit_id'] ?? 0);
$checkIn  = trim($_GET['check_in'] ?? $_POST['check_in'] ?? '');
$checkOut = trim($_GET['check_out'] ?? $_POST['check_out'] ?? '');
$guests   = max(1, (int)($_GET['guests'] ?? $_POST['guests'] ?? 1));

if (!$unitId || !$checkIn || !$checkOut || $checkIn >= $checkOut || $checkIn < date('Y-m-d')) {
    header('Location: ' . portal_base_url() . '/search.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT u.*, b.name AS building_name, b.address AS building_address
    FROM re_units u
    LEFT JOIN re_buildings b ON b.id = u.building_id
    WHERE u.id = ? AND u.is_listed = 1 AND u.rental_mode IN ('short_term','both')
    LIMIT 1
");
$stmt->execute([$unitId]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$unit) { header('Location: ' . portal_base_url() . '/'); exit; }

$nights = max(1, (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400));
$title  = $unit['listing_title'] ?: $unit['building_name'] . ' - Unit ' . $unit['unit_number'];

$photoStmt = $conn->prepare("SELECT file_path FROM ars_unit_photos WHERE unit_id = ? AND is_primary = 1 LIMIT 1");
$photoStmt->execute([$unitId]);
$photoPath = $photoStmt->fetchColumn();

$pricing = ars_calculate_booking_price_v2(
    $conn, $arsCompanyId, $unitId, (float)$unit['nightly_rate'], $nights,
    $checkIn, $checkOut, null, [], $vatRate
);

$error = $success = '';
$bookingId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $specialReqs = trim($_POST['special_requests'] ?? '');
    $promoCode   = trim($_POST['promo_code'] ?? '');

    $guestId = null;
    $portalUserId = null;

    if (is_portal_guest()) {
        $guestId = current_portal_guest_id();
        $portalUserId = current_portal_user_id();
    } else {
        $regFirst    = trim($_POST['reg_first_name'] ?? '');
        $regLast     = trim($_POST['reg_last_name'] ?? '');
        $regEmail    = strtolower(trim($_POST['reg_email'] ?? ''));
        $regPhone    = trim($_POST['reg_phone'] ?? '');
        $regPassword = $_POST['reg_password'] ?? '';

        $loginEmail    = strtolower(trim($_POST['login_email'] ?? ''));
        $loginPassword = $_POST['login_password'] ?? '';
        $authMode      = $_POST['auth_mode'] ?? 'register';

        if ($authMode === 'login') {
            if (!$loginEmail || !$loginPassword) {
                $error = 'Please enter your email and password.';
            } else {
                $s = $conn->prepare("SELECT pu.*, ag.first_name, ag.last_name FROM portal_users pu LEFT JOIN ars_guests ag ON ag.id = pu.guest_id WHERE pu.email = ? AND pu.user_type = 'guest' AND pu.status = 'active' LIMIT 1");
                $s->execute([$loginEmail]);
                $pu = $s->fetch(PDO::FETCH_ASSOC);
                if (!$pu || !password_verify($loginPassword, $pu['password_hash'])) {
                    $error = 'Invalid email or password.';
                } else {
                    portal_login_set_session($pu, ['first_name' => $pu['first_name'], 'last_name' => $pu['last_name']]);
                    $guestId = (int)$pu['guest_id'];
                    $portalUserId = (int)$pu['id'];
                }
            }
        } else {
            if (!$regFirst || !$regLast || !$regEmail || !$regPassword) {
                $error = 'Please fill in all required fields.';
            } elseif (!filter_var($regEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($regPassword) < 6) {
                $error = 'Password must be at least 6 characters.';
            } else {
                $dup = $conn->prepare("SELECT id FROM portal_users WHERE email = ? LIMIT 1");
                $dup->execute([$regEmail]);
                if ($dup->fetchColumn()) {
                    $error = 'An account with this email already exists. Please login instead.';
                } else {
                    try {
                        $conn->beginTransaction();
                        $conn->prepare("INSERT INTO ars_guests (company_id, first_name, last_name, email, phone, is_active) VALUES (?,?,?,?,?,1)")
                            ->execute([$arsCompanyId, $regFirst, $regLast, $regEmail, $regPhone]);
                        $guestId = (int)$conn->lastInsertId();

                        $hash = password_hash($regPassword, PASSWORD_DEFAULT);
                        $conn->prepare("INSERT INTO portal_users (company_id, user_type, guest_id, email, password_hash, display_name, phone, status, email_verified_at) VALUES (?,'guest',?,?,?,?,?,'active',NOW())")
                            ->execute([$arsCompanyId, $guestId, $regEmail, $hash, $regFirst . ' ' . $regLast, $regPhone]);
                        $portalUserId = (int)$conn->lastInsertId();

                        $conn->prepare("UPDATE ars_guests SET portal_user_id = ? WHERE id = ?")->execute([$portalUserId, $guestId]);
                        $conn->commit();

                        portal_login_set_session(
                            ['id' => $portalUserId, 'guest_id' => $guestId, 'company_id' => $arsCompanyId],
                            ['first_name' => $regFirst, 'last_name' => $regLast]
                        );
                    } catch (PDOException $e) {
                        if ($conn->inTransaction()) $conn->rollBack();
                        $error = 'Registration failed. Please try again.';
                    }
                }
            }
        }
    }

    if (!$error && $guestId) {
        $discount = [];
        $bPromoCodeId = null;
        if ($promoCode) {
            $baseSubtotal = 0.0;
            $nb = ars_get_nightly_breakdown($conn, $arsCompanyId, $unitId, $checkIn, $checkOut, (float)$unit['nightly_rate']);
            foreach ($nb as $n) {
                $baseSubtotal += (float)($n['rate'] ?? 0);
            }
            $baseSubtotal = round($baseSubtotal, 2);
            $pr = ars_validate_promo_code($conn, $arsCompanyId, $promoCode, $nights, $baseSubtotal);
            if ($pr['valid']) {
                $pc = $pr['promo'];
                $discount = [
                    'type'                 => 'promo',
                    'discount_type'        => $pc['discount_type'],
                    'discount_value'       => (float)$pc['discount_value'],
                    'max_discount_amount'  => $pc['max_discount_amount'] !== null && $pc['max_discount_amount'] !== ''
                        ? (float)$pc['max_discount_amount'] : null,
                    'promo_id'             => (int)$pc['id'],
                    'label'                => $pc['code'],
                ];
                $bPromoCodeId = (int)$pc['id'];
            }
        }

        $pricing = ars_calculate_booking_price_v2(
            $conn, $arsCompanyId, $unitId, (float)$unit['nightly_rate'], $nights,
            $checkIn, $checkOut, null, [], $vatRate, $discount
        );

        try {
            $conn->beginTransaction();

            $availResult = ars_check_availability($conn, $unitId, $checkIn, $checkOut);
            if (!$availResult['available']) {
                $conn->rollBack();
                $error = 'Sorry, this unit is no longer available for your selected dates.';
            } else {
                $bookingNumber = generateBookingNumber($conn, $arsCompanyId);
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours"));

                $lengthDiscAmt = $pricing['length_discount_amount'] ?? 0;
                $lengthDiscLabel = $pricing['length_discount_label'] ?? '';
                $rulesJson = !empty($pricing['rules_applied']) ? json_encode($pricing['rules_applied']) : null;
                $bDiscType = !empty($discount) ? 'promo' : 'none';
                $bDiscLabel = !empty($discount) ? ($discount['label'] ?? '') : null;
                $bDiscPct = (!empty($discount) && ($discount['discount_type'] ?? '') === 'percentage')
                    ? (float)($discount['discount_value'] ?? 0) : 0;
                $bDiscAmt = $pricing['discount_amount'] ?? 0;

                $conn->prepare("
                    INSERT INTO ars_bookings
                        (company_id, unit_id, guest_id, booking_number, check_in, check_out, nights, num_guests,
                         status, nightly_rate, rate_override, subtotal, extras_total, vat_rate, vat_amount, net_amount,
                         length_discount_amount, length_discount_label, pricing_rules_applied,
                         pricing_mode, vat_mode, entered_amount,
                         discount_type, discount_label, discount_percent, discount_amount, promo_code_id,
                         total_amount, paid_amount, balance_due, payment_status, expires_at,
                         deposit_amount, deposit_status, is_historical,
                         special_requests, internal_notes, created_by)
                    VALUES (
                        ?,?,?,?,?,?,?,?,
                        'pending',?,?,?,0.00,?,?,?,
                        ?,?,?,
                        'nightly','exclusive',NULL,
                        ?,?,?,?,?,
                        ?,?,?, 'unpaid', ?,
                        0.00,'none',0,
                        ?,?,?
                    )
                ")->execute([
                    $arsCompanyId, $unitId, $guestId, $bookingNumber, $checkIn, $checkOut, $nights, $guests,
                    $pricing['effective_rate'], null, $pricing['subtotal'],
                    $pricing['vat_rate'], $pricing['vat_amount'], $pricing['net_amount'] ?? null,
                    $lengthDiscAmt, $lengthDiscLabel, $rulesJson,
                    $bDiscType, $bDiscLabel, $bDiscPct, $bDiscAmt, $bPromoCodeId,
                    $pricing['total_amount'], 0.00, $pricing['total_amount'], $expiresAt,
                    $specialReqs ?: null, 'Portal booking', null,
                ]);

                if ($bPromoCodeId) {
                    ars_increment_promo_usage($conn, $bPromoCodeId);
                }

                $bookingId = (int)$conn->lastInsertId();

                $conn->prepare("
                    UPDATE ars_guests SET total_bookings = total_bookings + 1 WHERE id = ?
                ")->execute([$guestId]);

                $conn->commit();

                try {
                    $bookingNotify = [
                        'id' => $bookingId,
                        'company_id' => $arsCompanyId,
                        'guest_id' => $guestId,
                        'booking_number' => $bookingNumber,
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                    ];
                    ars_guest_notification_create($conn, [
                        'company_id' => $arsCompanyId,
                        'guest_id' => $guestId,
                        'booking_id' => $bookingId,
                        'event_type' => 'booking_created',
                        'title' => 'Booking request received',
                        'message' => 'Your booking ' . $bookingNumber . ' is pending confirmation.',
                        'cta_route' => '/guest/bookings/' . $bookingId,
                        'meta' => ['booking_number' => $bookingNumber, 'status' => 'pending'],
                    ]);
                    ars_guest_notifications_schedule_booking_reminders($conn, $bookingNotify);
                } catch (Throwable $e) {
                    error_log('Stay portal booking notification failed: ' . $e->getMessage());
                }

                header('Location: ' . portal_base_url() . '/booking.php?id=' . $bookingId . '&created=1');
                exit;
            }
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = 'Booking failed. Please try again.';
        }
    }
}

$isLoggedIn = is_portal_guest();
$pageTitle = 'Book ' . $title;
$_baseUrl = portal_base_url();
require_once __DIR__ . '/includes/portal_layout_header.php';
?>

<div class="container py-4">
    <div class="row g-4">
        <!-- Left: Booking Form -->
        <div class="col-lg-7">
            <h2 class="fw-bold mb-3"><i class="bi bi-calendar-check me-2"></i>Complete Your Booking</h2>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" id="bookingForm">
                <input type="hidden" name="unit_id" value="<?= $unitId ?>">
                <input type="hidden" name="check_in" value="<?= h($checkIn) ?>">
                <input type="hidden" name="check_out" value="<?= h($checkOut) ?>">
                <input type="hidden" name="guests" value="<?= $guests ?>">

                <!-- Trip Details (read-only) -->
                <div class="portal-card p-3 mb-4">
                    <h6 class="fw-bold mb-3">Trip Details</h6>
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <small class="text-muted d-block">Check-in</small>
                            <strong><?= date('M j, Y', strtotime($checkIn)) ?></strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-muted d-block">Check-out</small>
                            <strong><?= date('M j, Y', strtotime($checkOut)) ?></strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-muted d-block">Nights</small>
                            <strong><?= $nights ?></strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-muted d-block">Guests</small>
                            <strong><?= $guests ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Auth Section (register or login) -->
                <?php if (!$isLoggedIn): ?>
                <div class="portal-card p-3 mb-4" id="authSection">
                    <h6 class="fw-bold mb-3">Guest Information</h6>

                    <ul class="nav nav-pills nav-fill mb-3" role="tablist">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#tabRegister" onclick="document.getElementById('authMode').value='register'">New Guest</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tabLogin" onclick="document.getElementById('authMode').value='login'">Returning Guest</a></li>
                    </ul>
                    <input type="hidden" name="auth_mode" id="authMode" value="register">

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tabRegister">
                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">First Name *</label>
                                    <input type="text" name="reg_first_name" class="form-control" value="<?= h($_POST['reg_first_name'] ?? '') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Last Name *</label>
                                    <input type="text" name="reg_last_name" class="form-control" value="<?= h($_POST['reg_last_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Email *</label>
                                    <input type="email" name="reg_email" class="form-control" value="<?= h($_POST['reg_email'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Phone</label>
                                    <input type="tel" name="reg_phone" class="form-control" value="<?= h($_POST['reg_phone'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Password * <small class="text-muted">(min 6 chars)</small></label>
                                    <input type="password" name="reg_password" class="form-control" minlength="6">
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tabLogin">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Email</label>
                                    <input type="email" name="login_email" class="form-control">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Password</label>
                                    <input type="password" name="login_password" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="portal-card p-3 mb-4">
                    <h6 class="fw-bold mb-2">Logged in as</h6>
                    <p class="mb-0"><i class="bi bi-person-check me-1 text-success"></i><?= h(current_portal_display_name()) ?></p>
                </div>
                <?php endif; ?>

                <!-- Promo Code -->
                <div class="portal-card p-3 mb-4">
                    <h6 class="fw-bold mb-3">Promo Code</h6>
                    <div class="input-group">
                        <input type="text" name="promo_code" id="promoInput" class="form-control" placeholder="Enter code (optional)" value="<?= h($_POST['promo_code'] ?? '') ?>">
                        <button type="button" class="btn btn-outline-secondary" id="applyPromo">Apply</button>
                    </div>
                    <div id="promoResult" class="mt-2"></div>
                </div>

                <!-- Special Requests -->
                <div class="portal-card p-3 mb-4">
                    <h6 class="fw-bold mb-3">Special Requests</h6>
                    <textarea name="special_requests" class="form-control" rows="3" placeholder="Any special requests? (optional)"><?= h($_POST['special_requests'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn btn-portal btn-lg w-100">
                    <i class="bi bi-check-circle me-1"></i>Request Booking
                </button>
                <p class="text-muted text-center mt-2 small">Your booking will be pending until confirmed by our team. No payment required now.</p>
            </form>
        </div>

        <!-- Right: Pricing Summary -->
        <div class="col-lg-5">
            <div class="pricing-sticky">
                <div class="pricing-card">
                    <!-- Unit Preview -->
                    <div class="d-flex gap-3 mb-3 align-items-center">
                        <?php if ($photoPath): ?>
                        <img src="<?= h(portal_base_url() . '/../' . $photoPath) ?>" alt="" style="width:80px;height:80px;border-radius:10px;object-fit:cover">
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold"><?= h($title) ?></div>
                            <small class="text-muted"><?= h($unit['building_name']) ?></small>
                        </div>
                    </div>

                    <hr>

                    <!-- Price Breakdown -->
                    <?php
                    $avgInclNight = $nights > 0 ? round((float) $pricing['total_amount'] / $nights, 2) : 0;
                    $umonth       = (float) ($unit['monthly_rate'] ?? 0);
                    $monthlyIncl  = $umonth > 0 ? round($umonth * (1 + $vatRate / 100), 2) : 0;
                    ?>
                    <div id="priceBreakdown">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Avg / night incl. VAT</span>
                            <span class="fw-semibold"><?= $currency ?> <?= number_format($avgInclNight, 2) ?></span>
                        </div>
                        <?php if ($monthlyIncl > 0 && $nights >= 28): ?>
                        <div class="d-flex justify-content-between mb-2 small text-muted">
                            <span>Monthly package (incl. VAT)</span>
                            <span><?= $currency ?> <?= number_format($monthlyIncl, 2) ?>/mo</span>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between mb-2 small text-muted">
                            <span>Room subtotal (ex VAT)</span>
                            <span><?= $currency ?> <?= number_format($pricing['subtotal'], 2) ?></span>
                        </div>
                        <?php if (($pricing['length_discount_amount'] ?? 0) > 0): ?>
                        <div class="d-flex justify-content-between mb-2 text-success">
                            <span>Length discount</span>
                            <span>-<?= $currency ?> <?= number_format($pricing['length_discount_amount'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div id="promoDiscountRow" class="d-none d-flex justify-content-between mb-2 text-success">
                            <span>Promo discount</span>
                            <span id="promoDiscountVal"></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 text-muted small">
                            <span>VAT</span>
                            <span><?= $currency ?> <?= number_format($pricing['vat_amount'], 2) ?></span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between fw-bold fs-5">
                            <span>Total incl. VAT</span>
                            <span id="totalDisplay"><?= $currency ?> <?= number_format($pricing['total_amount'], 2) ?></span>
                        </div>
                    </div>

                    <hr>
                    <div class="text-center">
                        <small class="text-muted"><i class="bi bi-clock me-1"></i>Booking expires in <?= $expiryHours ?>h if not confirmed</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<JS
<script>
document.getElementById('applyPromo')?.addEventListener('click', function(){
    const code = document.getElementById('promoInput').value.trim();
    const res = document.getElementById('promoResult');
    const row = document.getElementById('promoDiscountRow');
    if (!code) { res.innerHTML = ''; row?.classList.add('d-none'); return; }
    fetch('{$_baseUrl}/ajax_check_availability.php?unit_id={$unitId}&check_in={$checkIn}&check_out={$checkOut}&promo_code=' + encodeURIComponent(code))
    .then(r => r.json())
    .then(d => {
        if (d.available && d.promo_valid) {
            res.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Promo applied!</span>';
            if (parseFloat(d.discount_amount) > 0) {
                row?.classList.remove('d-none');
                document.getElementById('promoDiscountVal').textContent = '-{$currency} ' + d.discount_amount;
            }
            document.getElementById('totalDisplay').textContent = '{$currency} ' + d.total;
        } else if (d.available) {
            const msg = (d.promo_error && String(d.promo_error).trim()) ? d.promo_error : 'Invalid or expired code';
            res.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + msg.replace(/</g, '&lt;') + '</span>';
            row?.classList.add('d-none');
        } else {
            res.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + (d.reason || 'Not available') + '</span>';
        }
    }).catch(() => { res.innerHTML = '<span class="text-muted">Could not validate — check connection or try again.</span>'; });
});
</script>
JS;
require_once __DIR__ . '/includes/portal_layout_footer.php';
?>
