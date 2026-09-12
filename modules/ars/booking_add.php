<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_availability.php';
require_once __DIR__ . '/includes/ars_pricing.php';
require_once __DIR__ . '/includes/ars_guest_notifications.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_financial_lock.php';
require_once __DIR__ . '/includes/ars_activity.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$settings = getArsSettings($conn, $arsCompanyId);
$userId = current_user_id();

$success = $error = '';
$newBookingId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $unitId       = (int)($_POST['unit_id'] ?? 0);
    $guestId      = (int)($_POST['guest_id'] ?? 0);
    $checkIn      = $_POST['check_in'] ?? '';
    $checkOut     = $_POST['check_out'] ?? '';
    $numGuests    = max(1, (int)($_POST['num_guests'] ?? 1));
    $rateOverride = ($_POST['rate_override'] ?? '') !== '' ? (float)$_POST['rate_override'] : null;
    $depositAmt   = max(0, (float)($_POST['deposit_amount'] ?? 0));
    $promoCode    = strtoupper(trim($_POST['promo_code'] ?? ''));
    $promoCodeId  = (int)($_POST['promo_code_id'] ?? 0);
    $promoDiscType = $_POST['promo_discount_type'] ?? '';
    $promoDiscVal  = (float)($_POST['promo_discount_value'] ?? 0);
    $promoMaxDiscAmt = ($_POST['promo_max_discount_amount'] ?? '') !== '' ? (float)$_POST['promo_max_discount_amount'] : null;
    $manualDiscType = $_POST['manual_discount_type'] ?? '';
    $manualDiscVal  = (float)($_POST['manual_discount_value'] ?? 0);
    $specialReqs  = trim($_POST['special_requests'] ?? '');
    $internalNote = trim($_POST['internal_notes'] ?? '');

    // Display-only rate shown on the booking page; never passed to pricing.
    $displayRateType = $_POST['display_rate_type'] ?? '';
    if (!in_array($displayRateType, ['nightly', 'weekly', 'monthly'], true)) {
        $displayRateType = '';
    }
    $displayRate = ($_POST['display_rate'] ?? '') !== '' ? max(0, (float)$_POST['display_rate']) : null;

    $pricingMode = $_POST['pricing_mode'] ?? 'nightly';
    if (!in_array($pricingMode, ['nightly', 'monthly_package', 'manual_total'], true)) {
        $pricingMode = 'nightly';
    }
    $vatMode = ars_normalize_vat_mode($_POST['vat_mode'] ?? 'exclusive');

    try {
        if ($unitId > 0) {
            ars_assert_unit_usable_for_ars($conn, $unitId, $arsCompanyId);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $manualTotalAmount = max(0, (float)($_POST['manual_total_amount'] ?? 0));
    $isHistorical      = !empty($_POST['is_historical']);
    $historicalStatus  = $_POST['historical_status'] ?? 'confirmed';
    if (!in_array($historicalStatus, ['confirmed', 'checked_in', 'checked_out', 'completed'], true)) {
        $historicalStatus = 'confirmed';
    }

    if (!$unitId || !$guestId || !$checkIn || !$checkOut) {
        $error = 'All required fields must be filled.';
    } elseif ($checkOut <= $checkIn) {
        $error = 'Check-out must be after check-in.';
    } else {
        $nights = (int)((new DateTime($checkOut))->diff(new DateTime($checkIn))->days);
        if ($nights < 1) {
            $error = 'Minimum 1 night.';
        }
    }

    if (!$error && !$isHistorical) {
        try {
            $minStayErr = ars_validate_minimum_stay($conn, $arsCompanyId, $unitId, $checkIn, $checkOut, $nights);
            if ($minStayErr) {
                $error = $minStayErr;
            }
        } catch (Exception $e) {
            $error = 'Pricing rule validation error: ' . $e->getMessage();
        }
    }

    if (!$error) {
        try {
            $conn->beginTransaction();
            $conn->prepare("SELECT id FROM re_units WHERE id = ? FOR UPDATE")->execute([$unitId]);

            if (!$isHistorical) {
                $avail = ars_check_availability($conn, $unitId, $checkIn, $checkOut);
                if (!$avail['available']) {
                    $conn->rollBack();
                    $conflictLabels = array_map(fn($c) => $c['label'], $avail['conflicts']);
                    $error = 'Unit not available: ' . implode(', ', $conflictLabels);
                }
            }

            if (!$error) {
                $stmt = $conn->prepare("SELECT nightly_rate, COALESCE(monthly_rate, 0) AS monthly_rate FROM re_units WHERE id = ?");
                $stmt->execute([$unitId]);
                $ur        = $stmt->fetch(PDO::FETCH_ASSOC);
                $baseRate  = (float)($ur['nightly_rate'] ?? 0);
                $monthlyRt = (float)($ur['monthly_rate'] ?? 0);

                $discountParam = [];
                if ($promoCodeId && $promoCode && $promoDiscVal > 0) {
                    $discountParam = [
                        'type' => 'promo', 'discount_type' => $promoDiscType,
                        'discount_value' => $promoDiscVal, 'max_discount_amount' => $promoMaxDiscAmt,
                        'promo_id' => $promoCodeId, 'label' => $promoCode,
                    ];
                } elseif ($manualDiscType && $manualDiscVal > 0) {
                    $discountParam = [
                        'type' => 'manual', 'discount_type' => $manualDiscType,
                        'discount_value' => $manualDiscVal,
                        'label' => 'Manual ' . ($manualDiscType === 'percentage' ? $manualDiscVal . '%' : 'AED ' . number_format($manualDiscVal, 2)),
                    ];
                }

                $pricing = ars_calculate_booking_price_v3(
                    $conn,
                    $arsCompanyId,
                    $unitId,
                    $baseRate,
                    $monthlyRt,
                    $nights,
                    $checkIn,
                    $checkOut,
                    $pricingMode === 'nightly' ? $rateOverride : null,
                    [],
                    (float) $settings['default_vat_rate'],
                    $discountParam,
                    [
                        'pricing_mode'         => $pricingMode,
                        'vat_mode'             => $vatMode,
                        'entered_amount'       => $manualTotalAmount,
                        'skip_length_discount' => true,
                    ]
                );

                if (!empty($pricing['calc_error'])) {
                    $conn->rollBack();
                    $error = $pricing['calc_error'];
                } else {
                    $bookingNumber = generateBookingNumber($conn, $arsCompanyId);
                    $newStatus     = $isHistorical ? $historicalStatus : 'pending';
                    $expiresAt     = $newStatus === 'pending'
                        ? date('Y-m-d H:i:s', strtotime('+' . (int) $settings['pending_expiry_hours'] . ' hours'))
                        : null;
                    $depStatus = $depositAmt > 0 ? 'pending' : 'none';

                    $lengthDiscAmt    = $pricing['length_discount_amount'] ?? 0;
                    $lengthDiscLabel  = $pricing['length_discount'] ? ($pricing['length_discount']['rule_name'] ?? null) : null;
                    $rulesAppliedJson = !empty($pricing['rules_applied']) ? json_encode($pricing['rules_applied']) : null;

                    $bDiscountType    = $pricing['discount_type'] ?? 'none';
                    $bDiscountLabel   = $pricing['discount_label'] ?? null;
                    $bDiscountPercent = $pricing['discount_percent'] ?? 0;
                    $bDiscountAmount  = $pricing['discount_amount'] ?? 0;
                    $bPromoCodeId     = $pricing['discount_promo_id'] ?? null;

                    $enteredStored = $pricing['entered_amount'] !== null ? $pricing['entered_amount'] : null;

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
                            ?,?,?,?,0.00,?,?,?,
                            ?,?,?,
                            ?,?,?,
                            ?,?,?,?,?,
                            ?,?,?, 'unpaid', ?,
                            ?,?,?,?,?,?
                        )
                    ")->execute([
                        $arsCompanyId, $unitId, $guestId, $bookingNumber, $checkIn, $checkOut, $nights, $numGuests,
                        $newStatus,
                        $pricing['effective_rate'],
                        $pricingMode === 'nightly' ? $rateOverride : null,
                        $pricing['subtotal'],
                        $pricing['vat_rate'],
                        $pricing['vat_amount'],
                        $pricing['net_amount'],
                        $lengthDiscAmt, $lengthDiscLabel, $rulesAppliedJson,
                        $pricingMode, $vatMode, $enteredStored,
                        $bDiscountType, $bDiscountLabel, $bDiscountPercent, $bDiscountAmount, $bPromoCodeId ?: null,
                        $pricing['total_amount'], 0.00, $pricing['total_amount'], $expiresAt,
                        $depositAmt, $depStatus,
                        $isHistorical ? 1 : 0,
                        $specialReqs ?: null, $internalNote ?: null, $userId,
                    ]);

                    if ($bPromoCodeId) {
                        ars_increment_promo_usage($conn, $bPromoCodeId);
                    }
                    $newBookingId = (int) $conn->lastInsertId();
                    if ($displayRateType !== '' || $displayRate !== null) {
                        // Separate statement so the booking is still created if the display columns are missing.
                        try {
                            $conn->prepare("UPDATE ars_bookings SET display_rate_type = ?, display_rate = ? WHERE id = ? AND company_id = ?")
                                ->execute([$displayRateType ?: null, $displayRate, $newBookingId, $arsCompanyId]);
                        } catch (PDOException $e) {
                            error_log('ARS display rate not saved: ' . $e->getMessage());
                        }
                    }
                    try {
                        require_once __DIR__ . '/../../includes/AuditService.php';
                        AuditService::logEvent([
                            'action' => 'booking_created',
                            'module' => 'ars',
                            'company_id' => $arsCompanyId,
                            'object_type' => 'ars_bookings',
                            'object_id' => (string)$newBookingId,
                            'object_ref' => $bookingNumber ?: ('Booking #' . $newBookingId),
                            'summary' => 'Created booking ' . ($bookingNumber ?: ('#' . $newBookingId)),
                            'source' => 'user',
                            'success' => true,
                        ]);
                    } catch (Throwable $ignored) {}

                    if ($isHistorical && $newStatus === 'completed') {
                        $conn->prepare("
                            UPDATE ars_guests SET total_bookings = total_bookings + 1, total_spent = total_spent + ?
                            WHERE id = ? AND company_id = ?
                        ")->execute([(float) $pricing['total_amount'], $guestId, $arsCompanyId]);
                    }

                    $journalOk = true;
                    $journalErr = '';
                    if ($isHistorical && in_array($newStatus, ['confirmed', 'checked_in', 'checked_out', 'completed'], true)) {
                        require_once __DIR__ . '/includes/ars_accounting.php';
                        $bRowStmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? AND company_id = ?");
                        $bRowStmt->execute([$newBookingId, $arsCompanyId]);
                        $bookingRow = $bRowStmt->fetch(PDO::FETCH_ASSOC);
                        if ($bookingRow) {
                            $jr = ars_post_booking_revenue($conn, $bookingRow, $userId);
                            if (empty($jr['success'])) {
                                $journalOk = false;
                                $journalErr = $jr['error'] ?? 'Could not post revenue journal.';
                            } else {
                                $bRowStmt->execute([$newBookingId, $arsCompanyId]);
                                $bookingRow = $bRowStmt->fetch(PDO::FETCH_ASSOC) ?: $bookingRow;
                                ars_booking_engage_financial_lock(
                                    $conn,
                                    $bookingRow,
                                    'Historical booking revenue posted',
                                    $userId,
                                    'invoice_created'
                                );
                            }
                        }
                    }

                    if (!$journalOk) {
                        $conn->rollBack();
                        $error = $journalErr;
                    } else {
                        $conn->commit();
                        ars_booking_activity_log($conn, [
                            'company_id' => $arsCompanyId,
                            'booking_id' => $newBookingId,
                            'booking_number' => $bookingNumber,
                            'event_category' => 'operational',
                            'event_type' => 'booking_created',
                            'title' => 'Booking created',
                            'new_value' => $newStatus,
                            'created_by' => $userId,
                        ]);
                        if (!$isHistorical) {
                            try {
                                $bNotify = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? LIMIT 1');
                                $bNotify->execute([$newBookingId]);
                                $bookingNotify = $bNotify->fetch(PDO::FETCH_ASSOC) ?: [];
                                if ($bookingNotify) {
                                    ars_guest_notification_create($conn, [
                                        'company_id' => $arsCompanyId,
                                        'guest_id' => $guestId,
                                        'booking_id' => $newBookingId,
                                        'event_type' => 'booking_created',
                                        'title' => 'Booking created',
                                        'message' => 'Your booking ' . $bookingNumber . ' has been created.',
                                        'cta_route' => '/guest/bookings/' . $newBookingId,
                                    ]);
                                    ars_guest_notifications_schedule_booking_reminders($conn, $bookingNotify);
                                }
                            } catch (Throwable $e) {
                                error_log('ARS booking created notification failed: ' . $e->getMessage());
                            }
                        }
                        header('Location: booking_view.php?id=' . $newBookingId . '&created=1');
                        exit;
                    }
                }
            }
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Load units & guests for dropdowns
require_once __DIR__ . '/includes/ars_permissions.php';
[$unitWhere, $unitParams] = ars_short_term_units_where($arsCompanyId, 'u');
$unitsStmt = $conn->prepare("
    SELECT u.id, u.unit_number, u.nightly_rate, COALESCE(u.monthly_rate, 0) AS monthly_rate,
           u.max_guests, u.listing_title, b.name AS building_name
    FROM re_units u LEFT JOIN re_buildings b ON b.id = u.building_id
    WHERE {$unitWhere}
    ORDER BY b.name, u.unit_number
");
$unitsStmt->execute($unitParams);
$units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);

$guests = $conn->prepare("SELECT id, first_name, last_name, phone, email FROM ars_guests WHERE company_id = ? AND is_active = 1 ORDER BY first_name, last_name");
$guests->execute([$arsCompanyId]);
$guests = $guests->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'New Reservation';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$prefillIn = $_GET['check_in'] ?? ($_POST['check_in'] ?? '');
$prefillOut = $_GET['check_out'] ?? ($_POST['check_out'] ?? '');
$prefillUnit = (int)($_GET['unit_id'] ?? ($_POST['unit_id'] ?? 0));

$wizardLabels = ['Stay', 'Unit', 'Guest', 'Pricing', 'Deposit', 'Historical', 'Review'];

ars_shell_begin([
    'title' => 'New reservation',
    'subtitle' => 'Guided booking wizard · totals from server',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reservations', 'href' => 'bookings.php'],
        ['label' => 'New'],
    ],
    'actions_html' => ars_ui_button('Cancel', ['href' => 'bookings.php', 'variant' => 'secondary']),
    'legacy_bootstrap' => true,
]);
?>

<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="ars-wizard-shell mb-4" id="arsBookingWizard" data-ars-wizard data-max-step="7" data-step="1">
  <?= ars_ds_wizard_step_rail($wizardLabels) ?>
  <p class="ars-wizard-meta">Step <strong data-ars-wizard-step-num>1</strong> of 7 · <strong data-ars-wizard-step-label>Stay</strong></p>

<form method="POST" id="bookingForm">
<?php csrf_field(); ?>
<div class="row g-4">
  <div class="col-lg-7">
    <!-- Step 1 Stay -->
    <div class="ars-card mb-4 ars-wizard-step is-active" data-ars-wizard-panel="1">
      <div class="card-header">Stay &amp; availability</div>
      <div class="card-body row g-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Check-in *</label>
          <input type="date" name="check_in" id="checkIn" class="form-control" value="<?= h($prefillIn) ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Check-out *</label>
          <input type="date" name="check_out" id="checkOut" class="form-control" value="<?= h($prefillOut) ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Guests</label>
          <input type="number" name="num_guests" id="numGuests" class="form-control" min="1" value="<?= (int)($_POST['num_guests'] ?? 1) ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Rate type</label>
          <?php $drtPost = $_POST['display_rate_type'] ?? ''; ?>
          <select name="display_rate_type" id="displayRateType" class="form-select">
            <option value="">—</option>
            <option value="nightly" <?= $drtPost === 'nightly' ? 'selected' : '' ?>>Nightly</option>
            <option value="weekly" <?= $drtPost === 'weekly' ? 'selected' : '' ?>>Weekly</option>
            <option value="monthly" <?= $drtPost === 'monthly' ? 'selected' : '' ?>>Monthly</option>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Rate (AED)</label>
          <input type="number" step="0.01" min="0" name="display_rate" id="displayRate" class="form-control" value="<?= h($_POST['display_rate'] ?? '') ?>">
          <small class="text-muted">Display only — does not change the booking total.</small>
        </div>
        <div class="col-12 text-muted small">Availability is re-validated on the server when you create the booking.</div>
      </div>
    </div>

    <!-- Step 2 Unit -->
    <div class="ars-card mb-4 ars-wizard-step" data-ars-wizard-panel="2">
      <div class="card-header">Unit selection</div>
      <div class="card-body">
        <label class="form-label fw-semibold">Unit *</label>
        <select name="unit_id" id="unitSelect" class="form-select">
          <option value="">— Select unit —</option>
          <?php foreach ($units as $u): ?>
          <option value="<?= $u['id'] ?>"
            data-rate="<?= $u['nightly_rate'] ?>"
            data-monthly="<?= h($u['monthly_rate'] ?? 0) ?>"
            data-max="<?= $u['max_guests'] ?>"
            <?= ((int)($_POST['unit_id'] ?? $prefillUnit) === (int)$u['id']) ? 'selected' : '' ?>>
            <?= h($u['unit_number']) ?> — <?= h($u['building_name']) ?> — AED <?= number_format((float)$u['nightly_rate'], 2) ?>/night
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Step 3 Guest -->
    <div class="ars-card mb-4 ars-wizard-step" data-ars-wizard-panel="3">
      <div class="card-header">Guest</div>
      <div class="card-body">
        <label class="form-label fw-semibold">Guest *</label>
        <select name="guest_id" id="guestSelect" class="form-select">
          <option value="">— Select guest —</option>
          <?php foreach ($guests as $g): ?>
          <option value="<?= $g['id'] ?>" <?= ((int)($_POST['guest_id'] ?? $_GET['guest_id'] ?? 0) === (int)$g['id']) ? 'selected' : '' ?>>
            <?= h($g['first_name'] . ' ' . $g['last_name']) ?> <?= $g['phone'] ? '(' . h($g['phone']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <p class="small text-muted mt-2 mb-0">Need a new guest? <a href="guests.php">Add in Guests</a>, then return here.</p>
      </div>
    </div>

    <!-- Step 4 Pricing -->
    <div class="ars-card mb-4 ars-wizard-step" data-ars-wizard-panel="4">
      <div class="card-header">Pricing &amp; charges</div>
      <div class="card-body row g-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Pricing mode</label>
          <select name="pricing_mode" id="pricingMode" class="form-select" onchange="recalcPrice()">
            <option value="nightly" <?= ($_POST['pricing_mode'] ?? 'nightly') === 'nightly' ? 'selected' : '' ?>>Nightly</option>
            <option value="monthly_package" <?= ($_POST['pricing_mode'] ?? '') === 'monthly_package' ? 'selected' : '' ?>>Monthly package</option>
            <option value="manual_total" <?= ($_POST['pricing_mode'] ?? '') === 'manual_total' ? 'selected' : '' ?>>Manual total</option>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">VAT</label>
          <select name="vat_mode" id="vatMode" class="form-select" onchange="recalcPrice()">
            <?php $vatPost = ars_normalize_vat_mode($_POST['vat_mode'] ?? 'exclusive'); ?>
            <option value="exclusive" <?= $vatPost === 'exclusive' ? 'selected' : '' ?>>Exclusive</option>
            <option value="inclusive" <?= $vatPost === 'inclusive' ? 'selected' : '' ?>>Inclusive</option>
            <option value="none" <?= $vatPost === 'none' ? 'selected' : '' ?>>No VAT</option>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Rate override (AED/night)</label>
          <input type="number" step="0.01" min="0" name="rate_override" id="rateOverride" class="form-control" value="<?= h($_POST['rate_override'] ?? '') ?>" onchange="recalcPrice()">
        </div>
        <div class="col-sm-6" id="manualTotalWrap">
          <label class="form-label fw-semibold">Manual room total</label>
          <input type="number" step="0.01" min="0" name="manual_total_amount" id="manualTotalAmount" class="form-control" value="<?= h($_POST['manual_total_amount'] ?? '') ?>" onchange="recalcPrice()">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Promo code</label>
          <div class="input-group">
            <input type="text" name="promo_code" id="promoCodeInput" class="form-control text-uppercase" value="<?= h($_POST['promo_code'] ?? '') ?>">
            <button type="button" class="btn btn-ars-outline" onclick="applyPromo()">Apply</button>
          </div>
          <div id="promoFeedback" class="small mt-1"></div>
          <input type="hidden" name="promo_code_id" id="promoCodeId" value="<?= h($_POST['promo_code_id'] ?? '') ?>">
          <input type="hidden" name="promo_discount_type" id="promoDiscountType" value="">
          <input type="hidden" name="promo_discount_value" id="promoDiscountValue" value="">
          <input type="hidden" name="promo_max_discount_amount" id="promoMaxDiscountAmount" value="">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Manual discount type</label>
          <select name="manual_discount_type" id="manualDiscountType" class="form-select" onchange="recalcPrice()">
            <option value="">None</option>
            <option value="percentage" <?= ($_POST['manual_discount_type'] ?? '') === 'percentage' ? 'selected' : '' ?>>Percentage</option>
            <option value="fixed" <?= ($_POST['manual_discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed</option>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Discount value</label>
          <input type="number" step="0.01" min="0" name="manual_discount_value" id="manualDiscountValue" class="form-control" value="<?= h($_POST['manual_discount_value'] ?? '') ?>" onchange="recalcPrice()">
        </div>
      </div>
    </div>

    <!-- Step 5 Services & deposit -->
    <div class="ars-card mb-4 ars-wizard-step" data-ars-wizard-panel="5">
      <div class="card-header">Services &amp; deposit</div>
      <div class="card-body row g-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Security deposit (AED)</label>
          <input type="number" step="0.01" min="0" name="deposit_amount" id="depositAmount" class="form-control" value="<?= h($_POST['deposit_amount'] ?? '0') ?>" onchange="recalcPrice()">
          <small class="text-muted">Not included in total or VAT</small>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Special requests</label>
          <textarea name="special_requests" class="form-control" rows="2"><?= h($_POST['special_requests'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Internal notes</label>
          <textarea name="internal_notes" class="form-control" rows="2"><?= h($_POST['internal_notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Step 6 Payment arrangement -->
    <div class="ars-card mb-4 ars-wizard-step" data-ars-wizard-panel="6">
      <div class="card-header">Payment arrangement</div>
      <div class="card-body">
        <p class="text-muted small">Collect payment after creation from the Booking Workspace. Optional historical backfill:</p>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="is_historical" value="1" id="isHistorical" <?= !empty($_POST['is_historical']) ? 'checked' : '' ?> onchange="document.getElementById('historicalStatusWrap').classList.toggle('d-none', !this.checked)">
          <label class="form-check-label" for="isHistorical">Historical booking (skip availability)</label>
        </div>
        <div class="<?= !empty($_POST['is_historical']) ? '' : 'd-none' ?>" id="historicalStatusWrap">
          <label class="form-label fw-semibold">Historical status</label>
          <select name="historical_status" class="form-select">
            <?php $hs = $_POST['historical_status'] ?? 'confirmed'; ?>
            <option value="confirmed" <?= $hs === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
            <option value="checked_in" <?= $hs === 'checked_in' ? 'selected' : '' ?>>Checked in</option>
            <option value="checked_out" <?= $hs === 'checked_out' ? 'selected' : '' ?>>Checked out</option>
            <option value="completed" <?= $hs === 'completed' ? 'selected' : '' ?>>Completed</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Step 7 Review -->
    <div class="ars-card mb-4 ars-wizard-step" data-ars-wizard-panel="7">
      <div class="card-header">Review &amp; confirm</div>
      <div class="card-body">
        <p class="mb-2">Confirm dates, unit, guest, and the server pricing summary on the right, then create the reservation.</p>
        <p class="small text-muted mb-0">Totals come from the server preview — not from browser-side calculation.</p>
      </div>
    </div>

    <div class="ars-wizard-actions">
      <button type="button" class="btn btn-outline-secondary" id="arsWizardBack" hidden>Back</button>
      <button type="button" class="btn btn-ars" id="arsWizardNext">Next</button>
      <button type="submit" class="btn btn-ars" id="arsWizardSubmit" hidden><i class="bi bi-check-lg me-1"></i>Create booking</button>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="ars-card ars-sticky-card ars-ws-rail" id="pricingSummary">
      <div class="card-header"><i class="bi bi-calculator me-2"></i>Live summary</div>
      <div class="card-body">
        <div id="dispRulesAlert" class="d-none alert alert-info py-1 px-2 mb-2 small"><span id="dispRulesText"></span></div>
        <div id="dispMinStayAlert" class="d-none alert alert-danger py-1 px-2 mb-2 small"><span id="dispMinStayText"></span></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Base Rate</span><span id="dispBaseRate">—</span></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Effective Avg Rate</span><span id="dispRate">—</span></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Nights</span><span id="dispNights">—</span></div>
        <hr>
        <div id="nightlyBreakdownSection" class="d-none mb-2">
          <a class="small text-decoration-none" data-bs-toggle="collapse" href="#nightlyBreakdown">Per-night breakdown</a>
          <div class="collapse mt-1" id="nightlyBreakdown"><div id="nightlyBreakdownBody" class="small" style="max-height:200px;overflow-y:auto"></div></div>
        </div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Subtotal</span><span id="dispSubtotal">—</span></div>
        <div id="dispLengthDiscountRow" class="d-none d-flex justify-content-between mb-2"><span class="text-success"><span id="dispLengthDiscountLabel">Discount</span></span><span class="text-success" id="dispLengthDiscount">—</span></div>
        <div id="dispDiscountRow" class="d-none d-flex justify-content-between mb-2"><span class="text-warning"><span id="dispDiscountLabel">Discount</span></span><span class="text-warning" id="dispDiscountAmount">—</span></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted" id="dispVatLabel">VAT</span><span id="dispVat">—</span></div>
        <hr>
        <div class="d-flex justify-content-between fw-bold fs-5"><span>Stay total</span><span id="dispTotal" style="color:var(--ars-primary)">—</span></div>
        <div id="dispDepositRow" class="d-none mt-2">
          <hr>
          <div class="d-flex justify-content-between"><span class="text-muted">Security deposit</span><span id="dispDeposit">—</span></div>
          <div class="small text-muted mt-1">Held separately — not part of stay total or VAT.</div>
        </div>
        <div id="dispCollectRow" class="d-none mt-3 pt-3 border-top">
          <div class="d-flex justify-content-between align-items-baseline gap-2">
            <div>
              <div class="fw-bold">Amount to collect now</div>
              <div class="small text-muted">Stay total + security deposit</div>
            </div>
            <span id="dispCollectNow" class="fw-bold fs-5" style="color:var(--ars-primary)">—</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</form>
</div>

<?php
$vatRate = (float)($settings['default_vat_rate'] ?? 5);
$wizardLabelsJson = json_encode($wizardLabels, JSON_UNESCAPED_UNICODE);

$pageScripts = <<<JS
<script>
const vatRate = {$vatRate};
let priceTimer = null;
let priceRequestSeq = 0;
let lastStayTotal = 0;

function arsMoney(n) {
  n = parseFloat(n);
  if (!isFinite(n)) return '—';
  return 'AED ' + n.toFixed(2);
}

function arsNightsBetween(ci, co) {
  if (!ci || !co) return 0;
  var a = ci.split('-').map(Number);
  var b = co.split('-').map(Number);
  if (a.length !== 3 || b.length !== 3) return 0;
  var da = Date.UTC(a[0], a[1] - 1, a[2]);
  var db = Date.UTC(b[0], b[1] - 1, b[2]);
  return Math.round((db - da) / 86400000);
}

function arsCsrfToken() {
  if (window.ARS_CSRF) return window.ARS_CSRF;
  var el = document.querySelector('#bookingForm input[name="_csrf"]');
  return el ? el.value : '';
}

function setPricingStatus(msg, isError) {
  var box = document.getElementById('dispMinStayAlert');
  var text = document.getElementById('dispMinStayText');
  if (!box || !text) return;
  if (!msg) {
    box.classList.add('d-none');
    text.textContent = '';
    return;
  }
  box.classList.remove('d-none');
  box.classList.toggle('alert-danger', !!isError);
  box.classList.toggle('alert-info', !isError);
  text.textContent = msg;
}

function currentDepositAmount() {
  var depEl = document.getElementById('depositAmount');
  var dep = depEl ? parseFloat(depEl.value || 0) : 0;
  return isFinite(dep) && dep > 0 ? dep : 0;
}

function setStayTotal(amount) {
  lastStayTotal = (isFinite(amount) && amount > 0) ? amount : 0;
  var totalOut = document.getElementById('dispTotal');
  if (totalOut) totalOut.textContent = lastStayTotal > 0 ? arsMoney(lastStayTotal) : '—';
  updateCollectNow();
}

function updateDeposit() {
  var depRow = document.getElementById('dispDepositRow');
  var depOut = document.getElementById('dispDeposit');
  if (!depRow || !depOut) return;
  var dep = currentDepositAmount();
  if (dep > 0) {
    depRow.classList.remove('d-none');
    depOut.textContent = arsMoney(dep);
  } else {
    depRow.classList.add('d-none');
  }
  updateCollectNow();
}

function updateCollectNow() {
  var row = document.getElementById('dispCollectRow');
  var out = document.getElementById('dispCollectNow');
  if (!row || !out) return;
  var dep = currentDepositAmount();
  var collect = lastStayTotal + dep;
  if (collect > 0.009) {
    row.classList.remove('d-none');
    out.textContent = arsMoney(collect);
  } else {
    row.classList.add('d-none');
    out.textContent = '—';
  }
}

function applyLocalPricingPreview() {
  var sel = document.getElementById('unitSelect');
  var ciEl = document.getElementById('checkIn');
  var coEl = document.getElementById('checkOut');
  var overrideEl = document.getElementById('rateOverride');
  var modeEl = document.getElementById('pricingMode');
  var vatEl = document.getElementById('vatMode');
  var manualEl = document.getElementById('manualTotalAmount');

  var opt = (sel && sel.selectedIndex >= 0) ? sel.options[sel.selectedIndex] : null;
  var baseRate = opt ? parseFloat(opt.getAttribute('data-rate') || (opt.dataset ? opt.dataset.rate : 0) || 0) : 0;
  if (!isFinite(baseRate)) baseRate = 0;
  var unitId = sel ? sel.value : '';
  var ci = ciEl ? ciEl.value : '';
  var co = coEl ? coEl.value : '';
  var overrideVal = overrideEl ? parseFloat(overrideEl.value || 0) : 0;
  if (!isFinite(overrideVal)) overrideVal = 0;
  var pricingMode = modeEl ? modeEl.value : 'nightly';
  var vatMode = vatEl ? vatEl.value : 'exclusive';
  var manualTotal = manualEl ? parseFloat(manualEl.value || 0) : 0;
  if (!isFinite(manualTotal)) manualTotal = 0;
  var nights = arsNightsBetween(ci, co);

  var baseOut = document.getElementById('dispBaseRate');
  var nightsOut = document.getElementById('dispNights');
  var rateOut = document.getElementById('dispRate');
  var subOut = document.getElementById('dispSubtotal');
  var vatOut = document.getElementById('dispVat');
  var vatLbl = document.getElementById('dispVatLabel');

  if (baseOut) baseOut.textContent = unitId ? arsMoney(baseRate) : '—';
  if (nightsOut) nightsOut.textContent = nights > 0 ? String(nights) : '—';

  if (!unitId || nights < 1) {
    if (rateOut) rateOut.textContent = '—';
    if (subOut) subOut.textContent = '—';
    if (vatOut) vatOut.textContent = '—';
    setStayTotal(0);
    updateDeposit();
    return { ready: false, unitId: unitId, ci: ci, co: co, nights: nights, pricingMode: pricingMode, vatMode: vatMode, overrideVal: overrideVal, manualTotal: manualTotal, baseRate: baseRate };
  }

  var effective = baseRate;
  var subtotal = 0;
  if (pricingMode === 'manual_total') {
    subtotal = manualTotal > 0 ? manualTotal : 0;
    effective = nights > 0 && subtotal > 0 ? (subtotal / nights) : 0;
  } else {
    effective = (overrideVal > 0) ? overrideVal : baseRate;
    subtotal = effective * nights;
  }

  var vatAmount = 0;
  var total = subtotal;
  if (vatMode === 'exclusive' && subtotal > 0) {
    vatAmount = subtotal * (vatRate / 100);
    total = subtotal + vatAmount;
  } else if (vatMode === 'inclusive' && subtotal > 0) {
    vatAmount = subtotal - (subtotal / (1 + vatRate / 100));
    total = subtotal;
  } else if (vatMode === 'none') {
    vatAmount = 0;
    total = subtotal;
  }

  if (rateOut) rateOut.textContent = effective > 0 ? arsMoney(effective) : '—';
  if (subOut) subOut.textContent = subtotal > 0 ? arsMoney(subtotal) : '—';
  if (vatLbl) {
    var vatLabel = 'VAT (' + vatRate + '%)';
    if (vatMode === 'inclusive') vatLabel = 'VAT (inclusive)';
    else if (vatMode === 'none') vatLabel = 'VAT';
    vatLbl.textContent = vatLabel;
  }
  if (vatOut) vatOut.textContent = subtotal > 0 ? arsMoney(vatAmount) : '—';
  setStayTotal(subtotal > 0 ? total : 0);
  updateDeposit();

  return { ready: true, unitId: unitId, ci: ci, co: co, nights: nights, pricingMode: pricingMode, vatMode: vatMode, overrideVal: overrideVal, manualTotal: manualTotal, baseRate: baseRate };
}

function recalcPrice() {
  if (priceTimer) clearTimeout(priceTimer);
  applyLocalPricingPreview();
  priceTimer = setTimeout(doRecalcPrice, 250);
}

function doRecalcPrice() {
  var local = applyLocalPricingPreview();
  if (!local.ready) return;

  var fd = new FormData();
  fd.append('action', 'get_price_preview');
  fd.append('_csrf', arsCsrfToken());
  fd.append('unit_id', local.unitId);
  fd.append('check_in', local.ci);
  fd.append('check_out', local.co);
  fd.append('pricing_mode', local.pricingMode);
  fd.append('vat_mode', local.vatMode);
  if (local.manualTotal > 0) fd.append('manual_total_amount', String(local.manualTotal));
  if (local.overrideVal > 0 && local.pricingMode === 'nightly') fd.append('rate_override', String(local.overrideVal));

  var promoIdEl = document.getElementById('promoCodeId');
  var promoCodeEl = document.getElementById('promoCodeInput');
  var appliedPromo = promoIdEl ? promoIdEl.value : '';
  var promoCodeVal = promoCodeEl ? promoCodeEl.value.trim() : '';
  if (appliedPromo && promoCodeVal) {
    fd.append('promo_code', promoCodeVal);
  } else {
    var mdtEl = document.getElementById('manualDiscountType');
    var mdvEl = document.getElementById('manualDiscountValue');
    var mdt = mdtEl ? mdtEl.value : '';
    var mdv = mdvEl ? mdvEl.value : '';
    if (mdt && mdv && parseFloat(mdv) > 0) {
      fd.append('manual_discount_type', mdt);
      fd.append('manual_discount_value', mdv);
    }
  }

  var reqId = ++priceRequestSeq;
  fetch('ajax_pricing_actions.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) {
      return r.text().then(function(text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
        return { ok: r.ok, status: r.status, data: data };
      });
    })
    .then(function(res) {
      if (reqId !== priceRequestSeq) return;
      var d = res.data;
      if (!d || typeof d !== 'object') {
        setPricingStatus('Pricing preview unavailable. Local estimate shown.', true);
        return;
      }
      if (!d.success) {
        setPricingStatus(d.error || 'Pricing error', true);
        return;
      }
      setPricingStatus('', false);
      var p = d.pricing || {};
      var rateOut = document.getElementById('dispRate');
      var nightsOut = document.getElementById('dispNights');
      var subOut = document.getElementById('dispSubtotal');
      var vatOut = document.getElementById('dispVat');
      var vatLbl = document.getElementById('dispVatLabel');
      if (rateOut) rateOut.textContent = arsMoney(p.effective_rate);
      if (nightsOut) nightsOut.textContent = p.nights != null ? String(p.nights) : String(local.nights);
      if (subOut) subOut.textContent = arsMoney(p.subtotal);
      var vatLabel = 'VAT (' + vatRate + '%)';
      if (p.vat_mode === 'inclusive') vatLabel = 'VAT (inclusive room + extras)';
      else if (p.vat_mode === 'none') vatLabel = 'VAT';
      if (vatLbl) vatLbl.textContent = vatLabel;
      if (vatOut) vatOut.textContent = arsMoney(p.vat_amount);
      setStayTotal(parseFloat(p.total_amount) || 0);

      var rulesAlert = document.getElementById('dispRulesAlert');
      var rulesText = document.getElementById('dispRulesText');
      if (rulesAlert && rulesText) {
        if (p.rules_applied && p.rules_applied.length > 0) {
          rulesAlert.classList.remove('d-none');
          rulesText.textContent = p.rules_applied.join(', ');
        } else {
          rulesAlert.classList.add('d-none');
        }
      }

      var ldRow = document.getElementById('dispLengthDiscountRow');
      if (ldRow) {
        if (p.length_discount_amount > 0 && p.length_discount) {
          ldRow.classList.remove('d-none');
          var ldLabel = document.getElementById('dispLengthDiscountLabel');
          var ldAmt = document.getElementById('dispLengthDiscount');
          if (ldLabel) ldLabel.textContent = p.length_discount.rule_name + ' (' + p.length_discount.discount_percent + '%)';
          if (ldAmt) ldAmt.textContent = '- ' + arsMoney(p.length_discount_amount);
        } else {
          ldRow.classList.add('d-none');
        }
      }

      var nbSection = document.getElementById('nightlyBreakdownSection');
      var nbBody = document.getElementById('nightlyBreakdownBody');
      if (nbSection && nbBody) {
        if (p.nightly_breakdown && p.nightly_breakdown.length > 0 && !p.has_manual_override) {
          var hasVariation = p.nightly_breakdown.some(function(n) { return n.rule_name; });
          if (hasVariation) {
            nbSection.classList.remove('d-none');
            var html = '<table class="table table-sm mb-0"><tbody>';
            p.nightly_breakdown.forEach(function(n) {
              var badge = n.rule_name ? '<span class="badge bg-info ms-1">' + n.rule_name + '</span>' : '';
              html += '<tr><td>' + n.day_name + ' ' + n.date + '</td><td class="text-end">' + arsMoney(n.rate) + badge + '</td></tr>';
            });
            html += '</tbody></table>';
            nbBody.innerHTML = html;
          } else {
            nbSection.classList.add('d-none');
          }
        } else {
          nbSection.classList.add('d-none');
        }
      }

      var discRow = document.getElementById('dispDiscountRow');
      if (discRow) {
        if (p.discount_amount > 0) {
          discRow.classList.remove('d-none');
          var discLabel = document.getElementById('dispDiscountLabel');
          var discAmt = document.getElementById('dispDiscountAmount');
          if (discLabel) discLabel.textContent = p.discount_label || 'Discount';
          if (discAmt) discAmt.textContent = '- ' + arsMoney(p.discount_amount);
        } else {
          discRow.classList.add('d-none');
        }
      }
      updateDeposit();
    })
    .catch(function() {
      if (reqId !== priceRequestSeq) return;
      setPricingStatus('Pricing preview request failed. Local estimate shown.', true);
    });
}

function applyPromo() {
  var codeEl = document.getElementById('promoCodeInput');
  var code = codeEl ? codeEl.value.trim() : '';
  if (!code) return;
  var ciEl = document.getElementById('checkIn');
  var coEl = document.getElementById('checkOut');
  var ci = ciEl ? ciEl.value : '';
  var co = coEl ? coEl.value : '';
  var nights = arsNightsBetween(ci, co);

  var fd = new FormData();
  fd.append('action', 'validate_promo');
  fd.append('_csrf', arsCsrfToken());
  fd.append('code', code);
  fd.append('nights', String(nights));
  fd.append('subtotal', '0');

  var fb = document.getElementById('promoFeedback');
  if (fb) fb.innerHTML = '<span class="text-muted">Validating...</span>';

  fetch('ajax_pricing_actions.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        var p = d.promo;
        document.getElementById('promoCodeId').value = p.id;
        document.getElementById('promoDiscountType').value = p.discount_type;
        document.getElementById('promoDiscountValue').value = p.discount_value;
        document.getElementById('promoMaxDiscountAmount').value = p.max_discount_amount || '';
        var label = p.discount_type === 'percentage' ? p.discount_value + '% off' : arsMoney(p.discount_value) + ' off';
        if (p.max_discount_amount) label += ' (max ' + arsMoney(p.max_discount_amount) + ')';
        if (fb) fb.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + p.code + ' applied: ' + label + '</span> <a href="#" onclick="removePromo();return false" class="text-danger ms-2 small">Remove</a>';
        document.getElementById('manualDiscountType').value = '';
        document.getElementById('manualDiscountType').disabled = true;
        document.getElementById('manualDiscountValue').value = '';
        document.getElementById('manualDiscountValue').disabled = true;
        recalcPrice();
      } else {
        document.getElementById('promoCodeId').value = '';
        if (fb) fb.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + (d.error || 'Invalid code') + '</span>';
      }
    })
    .catch(function() { if (fb) fb.innerHTML = '<span class="text-danger">Network error</span>'; });
}

function removePromo() {
  document.getElementById('promoCodeId').value = '';
  document.getElementById('promoCodeInput').value = '';
  document.getElementById('promoDiscountType').value = '';
  document.getElementById('promoDiscountValue').value = '';
  document.getElementById('promoMaxDiscountAmount').value = '';
  document.getElementById('promoFeedback').innerHTML = '';
  document.getElementById('manualDiscountType').disabled = false;
  document.getElementById('manualDiscountValue').disabled = false;
  recalcPrice();
}

(function() {
  var root = document.getElementById('arsBookingWizard');
  if (!root) return;
  var maxStep = parseInt(root.getAttribute('data-max-step') || '7', 10);
  var step = parseInt(root.getAttribute('data-step') || '1', 10);
  var labels = {$wizardLabelsJson};
  var btnBack = document.getElementById('arsWizardBack');
  var btnNext = document.getElementById('arsWizardNext');
  var btnSubmit = document.getElementById('arsWizardSubmit');
  var form = document.getElementById('bookingForm');
  var stepNumEl = root.querySelector('[data-ars-wizard-step-num]');
  var stepLabelEl = root.querySelector('[data-ars-wizard-step-label]');

  function validateStep(n) {
    if (n === 1) {
      var ci = document.getElementById('checkIn');
      var co = document.getElementById('checkOut');
      if (!ci.value || !co.value || co.value <= ci.value) {
        alert('Enter valid check-in and check-out dates.');
        return false;
      }
    }
    if (n === 2) {
      var unit = document.getElementById('unitSelect');
      if (!unit || !unit.value) {
        alert('Select a unit.');
        return false;
      }
    }
    if (n === 3) {
      var guest = document.getElementById('guestSelect');
      if (!guest || !guest.value) {
        alert('Select a guest.');
        return false;
      }
    }
    return true;
  }

  function render() {
    root.setAttribute('data-step', String(step));
    root.querySelectorAll('[data-ars-wizard-panel]').forEach(function(panel) {
      var n = parseInt(panel.getAttribute('data-ars-wizard-panel'), 10);
      var on = n === step;
      panel.classList.toggle('is-active', on);
      panel.setAttribute('aria-hidden', on ? 'false' : 'true');
    });
    root.querySelectorAll('[data-ars-wizard-goto]').forEach(function(btn) {
      var n = parseInt(btn.getAttribute('data-ars-wizard-goto'), 10);
      btn.classList.remove('is-current', 'is-done', 'is-todo');
      if (n === step) btn.classList.add('is-current');
      else if (n < step) btn.classList.add('is-done');
      else btn.classList.add('is-todo');
      btn.setAttribute('aria-current', n === step ? 'step' : 'false');
    });
    if (stepNumEl) stepNumEl.textContent = String(step);
    if (stepLabelEl) stepLabelEl.textContent = labels[step - 1] || '';
    if (btnBack) btnBack.hidden = step <= 1;
    if (btnNext) btnNext.hidden = step >= maxStep;
    if (btnSubmit) btnSubmit.hidden = step < maxStep;
    recalcPrice();
  }

  function go(n) {
    n = Math.max(1, Math.min(maxStep, n));
    if (n === step) return;
    if (n > step) {
      for (var i = step; i < n; i++) {
        if (!validateStep(i)) return;
      }
    }
    step = n;
    render();
    try { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
  }

  if (btnNext) btnNext.addEventListener('click', function() {
    if (!validateStep(step)) return;
    go(step + 1);
  });
  if (btnBack) btnBack.addEventListener('click', function() { go(step - 1); });
  root.querySelectorAll('[data-ars-wizard-goto]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      go(parseInt(btn.getAttribute('data-ars-wizard-goto'), 10));
    });
  });
  if (form) {
    form.addEventListener('submit', function(e) {
      if (step < maxStep) {
        e.preventDefault();
        if (!validateStep(step)) return;
        go(step + 1);
        return;
      }
      for (var i = 1; i <= 3; i++) {
        if (!validateStep(i)) {
          e.preventDefault();
          go(i);
          return;
        }
      }
    });
    form.addEventListener('change', function(e) {
      var t = e.target;
      if (!t || !t.id) return;
      if (['unitSelect','checkIn','checkOut','rateOverride','manualDiscountType','manualDiscountValue','pricingMode','vatMode','manualTotalAmount','depositAmount'].indexOf(t.id) !== -1) {
        recalcPrice();
      }
      if (t.id === 'pricingMode') {
        var wrap = document.getElementById('manualTotalWrap');
        if (wrap) wrap.classList.toggle('d-none', t.value !== 'manual_total');
      }
      if (t.id === 'isHistorical') {
        var hw = document.getElementById('historicalStatusWrap');
        if (hw) hw.classList.toggle('d-none', !t.checked);
      }
    });
    form.addEventListener('input', function(e) {
      var t = e.target;
      if (!t || !t.id) return;
      if (['rateOverride','manualDiscountValue','manualTotalAmount','depositAmount','checkIn','checkOut'].indexOf(t.id) !== -1) {
        recalcPrice();
      }
    });
  }

  render();
})();
</script>
JS;

if (!empty($GLOBALS['ars_shell_state'])) {
    $GLOBALS['ars_shell_state']['pageScripts'] = $pageScripts;
}
ars_shell_end();
