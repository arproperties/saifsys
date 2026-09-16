<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_pricing.php';
require_once __DIR__ . '/includes/ars_stripe.php';
require_once __DIR__ . '/includes/ars_booking_requests.php';
require_once __DIR__ . '/includes/ars_deposit.php';
require_once __DIR__ . '/includes/ars_financial_lock.php';
require_once __DIR__ . '/includes/ars_activity.php';
require_once __DIR__ . '/includes/ars_early_checkout.php';
ars_deposit_ensure_schema($conn);
ars_early_checkout_ensure_schema($conn);

$arsCompanyId = arsPageAuth($conn);
ars_stripe_ensure_schema($conn);
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_links.php';
require_once __DIR__ . '/../../includes/inventory/inv_material_requests_for_modules.php';
$brand = getBrandSettings($conn);
$settings = getArsSettings($conn, $arsCompanyId);
$bookingId = (int)($_GET['id'] ?? 0);
if (!$bookingId) { header('Location: bookings.php'); exit; }

expirePendingBookings($conn, $arsCompanyId);

$stmt = $conn->prepare("
    SELECT b.*, g.first_name, g.last_name, g.email AS guest_email, g.phone AS guest_phone,
           u.unit_number, u.building_id AS re_building_id, u.listing_title, bl.name AS building_name
    FROM ars_bookings b
    LEFT JOIN ars_guests g ON g.id = b.guest_id
    LEFT JOIN re_units u ON u.id = b.unit_id
    LEFT JOIN re_buildings bl ON bl.id = u.building_id
    WHERE b.id = ? AND b.company_id = ?
");
$stmt->execute([$bookingId, $arsCompanyId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) { header('Location: bookings.php'); exit; }

$matReqForBooking = inv_material_requests_fetch_for_booking($conn, $arsCompanyId, $bookingId);

$charges = $conn->prepare("SELECT * FROM ars_booking_charges WHERE booking_id = ? ORDER BY charge_date, id");
$charges->execute([$bookingId]);
$charges = $charges->fetchAll(PDO::FETCH_ASSOC);

$payments = $conn->prepare("SELECT * FROM ars_booking_payments WHERE booking_id = ? ORDER BY payment_date, id");
$payments->execute([$bookingId]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

$stripeEvents = $conn->prepare("SELECT * FROM ars_stripe_events WHERE booking_id = ? ORDER BY created_at DESC, id DESC LIMIT 20");
$stripeEvents->execute([$bookingId]);
$stripeEvents = $stripeEvents->fetchAll(PDO::FETCH_ASSOC);

$lifecycleRequests = ars_booking_requests_for_booking($conn, $arsCompanyId, $bookingId);

$paymentIds = array_column($payments, 'id');
$journals = ars_get_booking_journals($conn, $bookingId, $paymentIds);

require_once __DIR__ . '/includes/ars_account_roles.php';
ars_ensure_receipt_account_columns($conn);
$arsGlCompanyId = ars_financial_gl_company_id($conn, $arsCompanyId);
$arsReceiptCashOptions = ars_receipt_account_options($conn, $arsGlCompanyId, 'cash');
$arsReceiptBankOptions = ars_receipt_account_options($conn, $arsGlCompanyId, 'bank_transfer');
$arsReceiptOptionsJson = htmlspecialchars(json_encode([
    'cash' => $arsReceiptCashOptions,
    'bank_transfer' => $arsReceiptBankOptions,
    'gl_company_id' => $arsGlCompanyId,
], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

require_once __DIR__ . '/includes/ars_financial_reports.php';
$financialDocs = ars_report_financial_documents($conn, $arsCompanyId, ['booking_id' => $bookingId]);

// Keep booking balance in sync with extras / amendment charges (service invoices insert charge rows).
require_once __DIR__ . '/includes/ars_pricing.php';
try {
    ars_recalc_booking_totals($conn, $bookingId);
    $stmt->execute([$bookingId, $arsCompanyId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC) ?: $booking;
    $charges = $conn->prepare("SELECT * FROM ars_booking_charges WHERE booking_id = ? ORDER BY charge_date, id");
    $charges->execute([$bookingId]);
    $charges = $charges->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
}

require_once __DIR__ . '/includes/ars_booking_unified_docs.php';
$unifiedDocs = ars_booking_unified_documents($conn, $booking, $arsCompanyId, $financialDocs);
$unifiedDocsFlat = ars_booking_unified_documents_flat($unifiedDocs);
$unifiedDocsTotal = ars_udoc_total($unifiedDocs);
$docCategories = ars_booking_doc_categories();

$openDocsBalance = 0.0;
foreach ($financialDocs as $fd) {
    $openDocsBalance += max(0, (float)($fd['balance_due'] ?? 0));
}
$openDocsBalance = round($openDocsBalance, 2);

$revenueJournal = null;
foreach ($journals as $j) {
    if ($j['journal_type'] === 'reversal') {
        continue;
    }
    if ($j['reference_type'] === 'ars_booking'
        || ($j['reference_type'] === 'ars_financial_document' && in_array((string)($j['journal_type'] ?? ''), ['invoice', 'revenue'], true))
        || (($j['type_label'] ?? '') === 'Invoice / Revenue')
    ) {
        $revenueJournal = $j;
        break;
    }
}

$depositAmount = (float)($booking['deposit_amount'] ?? 0);
$depositStatus = $booking['deposit_status'] ?? 'none';

$created = isset($_GET['created']);
$flash = (string)($_GET['flash'] ?? '');
$flashMessages = [
    'payment_recorded' => 'Stay payment recorded. Cash/bank journal posted and AR cleared for this amount.',
    'deposit_received' => 'Security deposit received. Liability journal posted (not stay revenue).',
    'deposit_settled' => 'Security deposit settled. Liability released to cash refund and/or deductions (no VAT on deductions).',
    'booking_confirmed' => 'Booking confirmed. Stay revenue journal posted.',
    'stay_dates_updated' => 'Stay dates updated. Nights and totals recalculated. Confirm when ready.',
    'checked_out' => 'Guest checked out. Cleaning was scheduled for the check-out date.',
    'early_checkout' => 'Early check-out recorded. No stay refund for unused nights. Cleaning scheduled for today (actual departure).',
];
$flashText = $flashMessages[$flash] ?? '';

$finLocked = ars_booking_is_financially_locked($conn, $booking);
$finStatus = (string)($booking['financial_status'] ?? ($finLocked ? 'invoice_created' : 'draft'));

$pageTitle = 'Booking ' . $booking['booking_number'];
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
$guestLabel = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
$shellActionsHtml = '';
if (has_permission('inventory_requests.create', MODULE_INVENTORY, $conn) && inv_user_can_access_material_request_create($conn, 'ars')) {
    $materialRequestUrl = inv_request_material_create_url($conn, [
        'source_module' => 'ars',
        'source_table' => 'ars_bookings',
        'source_id' => $bookingId,
        'context_booking_id' => $bookingId,
        'context_unit_id' => (int)($booking['unit_id'] ?? 0),
        'context_building_id' => (int)($booking['re_building_id'] ?? 0),
        'notes_hint' => 'ARS booking ' . ($booking['booking_number'] ?? ''),
    ]);
    $shellActionsHtml .= '<a class="btn btn-ars-outline btn-sm" href="' . h($materialRequestUrl) . '">Request inventory materials</a>';
}
$shellActionsHtml .= '<a class="btn btn-ars-outline btn-sm" href="bookings.php">Back</a>';
ars_shell_begin([
    'title' => $booking['booking_number'],
    'subtitle' => $guestLabel . ' · Unit ' . ($booking['unit_number'] ?? '—') . ' · ' . ars_ds_format_stay($booking['check_in'] ?? '', $booking['check_out'] ?? ''),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reservations', 'href' => 'bookings.php'],
        ['label' => $booking['booking_number']],
    ],
    'actions_html' => $shellActionsHtml,
    'legacy_bootstrap' => true,
]);

$stayLabel = ars_ds_format_stay($booking['check_in'] ?? '', $booking['check_out'] ?? '');
$unitLabel = trim(($booking['unit_number'] ?? '—') . (!empty($booking['building_name']) ? ' · ' . $booking['building_name'] : ''));
$stayTotal = (float)($booking['total_amount'] ?? 0);
$balanceDue = (float)($booking['balance_due'] ?? 0);
$depositPendingCollect = ($depositAmount > 0 && in_array($depositStatus, ['pending', 'none'], true));
$collectNow = max(0, $balanceDue) + ($depositPendingCollect ? $depositAmount : 0);
$journalViewBase = '../realestate/accounting/journal_entry_view.php?id=';
$journalCompanyQs = '&company_id=' . (int)$arsCompanyId;

$nextStepTitle = '';
$nextStepBody = '';
$nextStepActions = '';
if ($booking['status'] === 'pending') {
    $nextStepTitle = 'Next: Confirm this reservation';
    $nextStepBody = 'Confirming locks the dates and posts the stay revenue journal (AR / Revenue / VAT). Then collect stay payment and security deposit.';
    $nextStepActions = '<button type="button" class="btn btn-ars btn-sm" data-ars-action="confirm"><i class="bi bi-check-circle me-1"></i>Confirm booking</button>'
        . (!$finLocked
            ? '<button type="button" class="btn btn-ars-outline btn-sm" data-bs-toggle="modal" data-bs-target="#editStayDatesModal"><i class="bi bi-calendar-range me-1"></i>Edit stay dates</button>'
            : '')
        . '<button type="button" class="btn btn-ars-outline btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal"><i class="bi bi-cash-coin me-1"></i>Record payment</button>';
} elseif (in_array($booking['status'], ['confirmed', 'checked_in'], true) && $balanceDue > 0.009 && $depositPendingCollect) {
    $nextStepTitle = 'Next: Collect money from guest';
    $nextStepBody = 'Collect stay balance AED ' . number_format($balanceDue, 2) . ' + security deposit AED ' . number_format($depositAmount, 2) . '. Stay payments post a cash/bank journal; deposits are recorded on the Deposit tab.';
    $nextStepActions = '<button type="button" class="btn btn-ars btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal"><i class="bi bi-cash-coin me-1"></i>Record stay payment</button>'
        . '<button type="button" class="btn btn-ars-outline btn-sm" onclick="openWorkspaceTab(\'deposit\', \'security-deposit\')"><i class="bi bi-shield-lock me-1"></i>Collect deposit</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="openWorkspaceTab(\'documents\')"><i class="bi bi-journal-check me-1"></i>Accounting trail</button>';
} elseif (in_array($booking['status'], ['confirmed', 'checked_in'], true) && $balanceDue > 0.009) {
    $nextStepTitle = 'Next: Collect outstanding balance';
    $nextStepBody = 'Open balance AED ' . number_format($balanceDue, 2)
        . ($openDocsBalance > 0.009
            ? ' (includes unpaid amendment invoices such as additional service / damage / extension).'
            : '.')
        . ' Record payment with the RE cash/bank COA picker — the adapter allocates to open invoices.';
    $nextStepActions = '<button type="button" class="btn btn-ars btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal"><i class="bi bi-cash-coin me-1"></i>Record payment</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="openWorkspaceTab(\'documents\')"><i class="bi bi-journal-check me-1"></i>Documents</button>';
} elseif (in_array($booking['status'], ['confirmed', 'checked_in'], true) && $depositPendingCollect) {
    $nextStepTitle = 'Next: Collect security deposit';
    $nextStepBody = 'Stay balance is settled. Collect security deposit AED ' . number_format($depositAmount, 2) . ' (liability hold — not stay revenue).';
    $nextStepActions = '<button type="button" class="btn btn-ars btn-sm" onclick="openWorkspaceTab(\'deposit\', \'security-deposit\')"><i class="bi bi-shield-lock me-1"></i>Collect deposit</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="openWorkspaceTab(\'documents\')"><i class="bi bi-journal-check me-1"></i>Accounting trail</button>';
} elseif ($booking['status'] === 'confirmed' && $balanceDue <= 0.009 && !$depositPendingCollect) {
    $nextStepTitle = 'Next: Check the guest in';
    $nextStepBody = 'Stay balance and deposit are settled. Proceed with check-in when the guest arrives.';
    $nextStepActions = '<button type="button" class="btn btn-ars btn-sm" onclick="bookingAction(\'checkin\')"><i class="bi bi-box-arrow-in-right me-1"></i>Check in</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="openWorkspaceTab(\'documents\')"><i class="bi bi-journal-check me-1"></i>Accounting trail</button>';
}
?>
<div class="ars-ws-identity">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
    <div class="d-flex align-items-start gap-3 min-w-0">
      <?= ars_ds_avatar(ars_ds_guest_initials($booking['first_name'] ?? '', $booking['last_name'] ?? '')) ?>
      <div class="min-w-0">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
          <h2 class="h5 mb-0 fw-bold text-ars-text"><?= h($guestLabel ?: 'Guest') ?></h2>
          <?= ars_ui_status_badge('booking', $booking['status']) ?>
          <?php if ($finLocked): ?><?= ars_ui_lock_indicator() ?><?php endif; ?>
          <?= ars_ui_badge(ucfirst(str_replace('_', ' ', $finStatus)), ['tone' => 'info', 'icon' => 'file-text']) ?>
          <?php if (($booking['booking_source'] ?? 'direct') === 'airbnb'): ?><?= ars_ui_badge('Airbnb ' . ($booking['channel_ref'] ?? ''), ['tone' => 'danger', 'icon' => 'home']) ?><?php endif; ?>
        </div>
        <div class="ars-ws-identity-meta small text-muted">
          <span class="fw-semibold text-ars-ink"><?= h($booking['booking_number']) ?></span>
          <span class="ars-ws-meta-sep" aria-hidden="true">·</span>
          <span><?= h($unitLabel) ?></span>
          <span class="ars-ws-meta-sep" aria-hidden="true">·</span>
          <span><?= h($stayLabel) ?></span>
          <?php if (!empty($booking['guest_phone'])): ?>
            <span class="ars-ws-meta-sep" aria-hidden="true">·</span>
            <span><?= h($booking['guest_phone']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php if (!in_array($booking['status'], ['completed','cancelled','expired'], true)): ?>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <?php if ($booking['status'] === 'pending'): ?>
        <button type="button" class="btn btn-ars btn-sm" data-ars-action="confirm" title="Locks dates and posts stay revenue journal"><i class="bi bi-check-circle me-1"></i>Confirm</button>
        <?php if (!$finLocked): ?>
        <button type="button" class="btn btn-ars-outline btn-sm" data-bs-toggle="modal" data-bs-target="#editStayDatesModal" title="Correct check-in / check-out before confirm"><i class="bi bi-calendar-range me-1"></i>Edit stay dates</button>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($booking['status'] === 'confirmed'): ?>
        <button type="button" class="btn btn-warning btn-sm" onclick="bookingAction('checkin')"><i class="bi bi-box-arrow-in-right me-1"></i>Check In</button>
      <?php endif; ?>
      <?php if ($booking['status'] === 'checked_in'): ?>
        <button type="button" class="btn btn-info btn-sm text-white" data-ars-action="checkout"><i class="bi bi-box-arrow-right me-1"></i>Check Out</button>
      <?php endif; ?>
      <?php if ($booking['status'] === 'checked_out'): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="bookingAction('complete')"><i class="bi bi-check2-all me-1"></i>Complete</button>
      <?php endif; ?>
      <?php if (in_array($booking['status'], ['pending','confirmed'], true)): ?>
        <button type="button" class="btn btn-outline-danger btn-sm" onclick="if(confirm('Cancel this booking? If a revenue journal exists it will be reversed.')) bookingAction('cancel')"><i class="bi bi-x-circle me-1"></i>Cancel</button>
      <?php endif; ?>
      <?php if (!in_array($booking['status'], ['cancelled','expired'], true) && $balanceDue > 0.009): ?>
        <button type="button" class="btn btn-ars-outline btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal"><i class="bi bi-cash-coin me-1"></i>Payment</button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($created): ?>
<div class="alert alert-success alert-dismissible fade show">
  <div class="fw-semibold mb-1"><i class="bi bi-check-circle me-1"></i>Booking created — status Pending</div>
  <div class="small mb-0">Recommended flow: <strong>1)</strong> Confirm (posts revenue) → <strong>2)</strong> Record stay payment → <strong>3)</strong> Collect security deposit → <strong>4)</strong> Documents tab for the contract, receipts and deposit paperwork.</div>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($nextStepTitle !== ''): ?>
<section class="ars-ws-next-step mb-4" aria-label="Next operator step">
  <div class="ars-ws-next-step-body">
    <div class="ars-ws-next-step-eyebrow">Operator guide</div>
    <h3 class="ars-ws-next-step-title"><?= h($nextStepTitle) ?></h3>
    <p class="ars-ws-next-step-text"><?= h($nextStepBody) ?></p>
    <?php if ($collectNow > 0.009 && in_array($booking['status'], ['pending', 'confirmed', 'checked_in'], true)): ?>
      <div class="ars-ws-next-step-collect">Amount to collect now: <strong>AED <?= number_format($collectNow, 2) ?></strong>
        <?php
        $collectHint = 'stay balance';
        if ($balanceDue > 0.009 && $depositPendingCollect) {
            $collectHint = 'stay balance + deposit';
        } elseif ($depositPendingCollect) {
            $collectHint = 'deposit only';
        }
        ?>
        <span class="text-muted">(<?= h($collectHint) ?>)</span>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($nextStepActions !== ''): ?>
  <div class="ars-ws-next-step-actions"><?= $nextStepActions ?></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($flashText !== ''): ?>
<div class="alert alert-success alert-dismissible fade show" id="arsFlashSuccess">
  <div class="fw-semibold mb-0"><i class="bi bi-check-circle me-1"></i><?= h($flashText) ?></div>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<div id="actionAlert"></div>
<div id="ars-booking-view-root" hidden
     data-booking-id="<?= (int)$booking['id'] ?>"
     data-planned-check-out="<?= h((string)($booking['check_out'] ?? '')) ?>"
     data-check-in="<?= h((string)($booking['check_in'] ?? '')) ?>"
     data-check-out="<?= h((string)($booking['check_out'] ?? '')) ?>"
     data-deposit-amount="<?= h(number_format((float)$depositAmount, 2, '.', '')) ?>"
     data-deposit-status="<?= h((string)$depositStatus) ?>"
     data-deposit-editable="<?= in_array($depositStatus, ['none', 'pending'], true) ? '1' : '0' ?>"
     data-booking-status="<?= h((string)($booking['status'] ?? '')) ?>"
     data-fin-locked="<?= $finLocked ? '1' : '0' ?>"
     data-guest-email="<?= h((string)($booking['guest_email'] ?? '')) ?>"
     data-receipt-accounts="<?= $arsReceiptOptionsJson ?>"></div>
<?php
$arsBvJs = __DIR__ . '/assets/js/ars-booking-view.js';
$arsBvJsV = is_file($arsBvJs) ? (string)filemtime($arsBvJs) : '1';
$arsBvJsHref = rtrim(ars_ui_asset_base(), '/') . '/js/ars-booking-view.js?v=' . rawurlencode($arsBvJsV);
?>
<style>
/* Unified Documents table.
   Only the trailing columns get a fixed, nowrap width; Document takes every
   remaining pixel so long names like ARS-INV-2026-00089 stay on one line. */
#ws-docs .ars-udoc-table { min-width: 720px; }
#ws-docs .ars-udoc-col-doc { width: auto; min-width: 15rem; }
#ws-docs .ars-udoc-col-type,
#ws-docs .ars-udoc-col-date,
#ws-docs .ars-udoc-col-amount,
#ws-docs .ars-udoc-actions { width: 1%; white-space: nowrap; }

/* Source + status + note ride under the title instead of costing two columns. */
#ws-docs .ars-udoc-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .3rem;
    margin-top: .2rem;
    font-size: .78rem;
}
#ws-docs .ars-udoc-meta .badge { font-weight: 500; }
#ws-docs .ars-udoc-type { font-size: .8125rem; }

/* The shell pins .btn to min-height:40px and .form-select to 42px, so Bootstrap's
   btn-sm/form-select-sm cannot shrink them. Override inside this table only. */
#ws-docs .ars-udoc-col-type .ars-udoc-refile {
    width: auto;
    min-width: 9.5rem;
    min-height: 0;
    padding: .15rem 1.75rem .15rem .5rem;
    font-size: .78rem;
    background-position: right .4rem center;
}
#ws-docs .ars-udoc-actions-row {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    justify-content: flex-end;
    gap: .35rem;
}
#ws-docs .ars-udoc-actions-row .btn {
    min-height: 0;
    padding: .18rem .55rem;
    font-size: .78rem;
    line-height: 1.4;
    border-width: 1px;
    white-space: nowrap;
}

/* Phones use the existing stacked-card layout; drop the desktop width rules. */
@media (max-width: 767.98px) {
    #ws-docs .ars-udoc-table { min-width: 0; }
    #ws-docs .ars-udoc-col-doc,
    #ws-docs .ars-udoc-col-type,
    #ws-docs .ars-udoc-col-date,
    #ws-docs .ars-udoc-col-amount,
    #ws-docs .ars-udoc-actions { width: auto; min-width: 0; white-space: normal; }
    #ws-docs .ars-udoc-actions-row { flex-wrap: wrap; justify-content: flex-start; }
}
</style>
<script>window.ARS_BOOKING_ID = <?= (int)$booking['id'] ?>;</script>
<script src="<?= h($arsBvJsHref) ?>"></script>

<div>
<?= ars_ds_workspace_tabs([
    ['id' => 'overview', 'label' => 'Overview', 'icon' => 'layout-dashboard', 'active' => true],
    ['id' => 'money', 'label' => 'Money', 'icon' => 'wallet'],
    ['id' => 'deposit', 'label' => 'Deposit', 'icon' => 'shield'],
    ['id' => 'timeline', 'label' => 'Timeline', 'icon' => 'activity'],
    ['id' => 'documents', 'label' => 'Documents', 'icon' => 'file-text'],
]) ?>

<div class="row g-4 mt-1">
    <div class="col-lg-8">
        <section id="ars-ws-panel-overview" data-ars-ws-panel="overview" class="ars-ws-panel is-active">
<div id="ws-overview">
        <!-- Booking Details -->
        <div class="ars-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-person-badge me-2"></i>Stay &amp; guest</span>
                <div class="d-flex align-items-center gap-1 flex-wrap">
                    <?php if (!empty($booking['is_historical'])): ?>
                    <span class="badge bg-secondary">Historical</span>
                    <?php endif; ?>
                    <?php
                    $pm = $booking['pricing_mode'] ?? 'nightly';
                    if ($pm && $pm !== 'nightly'):
                    ?>
                    <span class="badge bg-info text-dark"><?= h(str_replace('_', ' ', $pm)) ?></span>
                    <?php endif; ?>
                    <?php
                    $vatVm = ars_normalize_vat_mode((string)($booking['vat_mode'] ?? 'exclusive'));
                    if ($vatVm === 'inclusive'):
                    ?>
                    <span class="badge bg-light text-dark border">VAT inclusive</span>
                    <?php elseif ($vatVm === 'none'): ?>
                    <span class="badge bg-light text-dark border">No VAT</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-4"><strong class="text-muted d-block small">Guest</strong><a href="guest_view.php?id=<?= $booking['guest_id'] ?>" class="text-decoration-none fw-semibold"><?= h($booking['first_name'] . ' ' . $booking['last_name']) ?></a></div>
                    <div class="col-6 col-md-4"><strong class="text-muted d-block small">Phone</strong><?= h($booking['guest_phone'] ?: '—') ?></div>
                    <div class="col-6 col-md-4"><strong class="text-muted d-block small">Email</strong><?= h($booking['guest_email'] ?: '—') ?></div>
                    <div class="col-6 col-md-4"><strong class="text-muted d-block small">Unit</strong><a href="unit_profile.php?id=<?= (int)$booking['unit_id'] ?>" class="text-decoration-none fw-semibold"><?= h($booking['unit_number']) ?></a> — <?= h($booking['building_name']) ?></div>
                    <div class="col-6 col-md-4"><strong class="text-muted d-block small">Check-in</strong><?= h($booking['check_in']) ?><?php if ($booking['status'] === 'pending' && !$finLocked): ?> <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#editStayDatesModal">Edit</button><?php endif; ?></div>
                    <div class="col-6 col-md-4">
                      <strong class="text-muted d-block small">Check-out (planned)</strong>
                      <?= h($booking['check_out']) ?>
                      <?php if ($booking['status'] === 'pending' && !$finLocked): ?>
                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#editStayDatesModal">Edit</button>
                      <?php endif; ?>
                      <?php if (ars_booking_is_early_checkout($booking)): ?>
                        <div class="mt-1">
                          <span class="badge bg-warning text-dark">Earlier check-out</span>
                          <span class="small fw-semibold ms-1"><?= h(ars_booking_effective_check_out($booking)) ?></span>
                        </div>
                        <div class="small text-muted mt-1">Stay nights were not refunded.</div>
                      <?php elseif (!empty($booking['actual_check_out']) && (string)$booking['actual_check_out'] !== (string)$booking['check_out']): ?>
                        <div class="mt-1 small text-muted">Actual: <?= h($booking['actual_check_out']) ?></div>
                      <?php endif; ?>
                    </div>
                    <div class="col-6 col-md-3"><strong class="text-muted d-block small">Nights</strong><?= (int)$booking['nights'] ?></div>
                    <div class="col-6 col-md-3"><strong class="text-muted d-block small">Guests</strong><?= (int)$booking['num_guests'] ?></div>
                    <?php
                    // Display-only rate entered on the booking wizard; not part of the booking total.
                    $dispRateType = (string)($booking['display_rate_type'] ?? '');
                    $dispRate = $booking['display_rate'] ?? null;
                    if ($dispRateType !== '' || $dispRate !== null):
                        $dispRateParts = array_filter([
                            ucfirst($dispRateType),
                            $dispRate !== null ? 'AED ' . number_format((float)$dispRate, 2) : '',
                        ]);
                    ?>
                    <div class="col-6 col-md-3"><strong class="text-muted d-block small">Rate</strong><?= h(implode(' · ', $dispRateParts)) ?></div>
                    <?php endif; ?>
                    <div class="col-6 col-md-3"><strong class="text-muted d-block small">Created</strong><?= h(date('M j, Y', strtotime($booking['created_at']))) ?></div>
                    <?php if ($revenueJournal): ?>
                    <div class="col-6 col-md-3"><strong class="text-muted d-block small">Revenue journal</strong>
                      <a class="badge text-decoration-none bg-<?= $revenueJournal['is_reversed'] ? 'danger' : ($revenueJournal['is_posted'] ? 'success' : 'warning') ?>" href="<?= h($journalViewBase . (int)$revenueJournal['id'] . $journalCompanyQs) ?>" target="_blank" rel="noopener"><?= h($revenueJournal['journal_number']) ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($booking['expires_at'] && $booking['status'] === 'pending'): ?>
                    <div class="col-6 col-md-3"><strong class="text-muted d-block small">Expires</strong><span class="text-danger"><?= h(date('M j, H:i', strtotime($booking['expires_at']))) ?></span></div>
                    <?php endif; ?>
                    <?php if ($booking['special_requests']): ?>
                    <div class="col-12"><strong class="text-muted d-block small">Special Requests</strong><?= nl2br(h($booking['special_requests'])) ?></div>
                    <?php endif; ?>
                    <?php if ($booking['internal_notes']): ?>
                    <div class="col-12"><strong class="text-muted d-block small">Internal Notes</strong><?= nl2br(h($booking['internal_notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Status Actions (duplicate kept for overview scroll context; primary actions live in sticky identity) -->
        <?php if (!in_array($booking['status'], ['completed','cancelled','expired'])): ?>
        <div class="ars-card mb-4 d-lg-none" id="status-actions">
            <div class="card-header"><i class="bi bi-lightning me-2"></i>Lifecycle</div>
            <div class="card-body d-flex flex-wrap gap-2">
                <?php if ($booking['status'] === 'pending'): ?>
                <button type="button" class="btn btn-ars btn-sm" data-ars-action="confirm"><i class="bi bi-check-circle me-1"></i>Confirm</button>
                <?php if (!$finLocked): ?>
                <button type="button" class="btn btn-ars-outline btn-sm" data-bs-toggle="modal" data-bs-target="#editStayDatesModal"><i class="bi bi-calendar-range me-1"></i>Edit stay dates</button>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($booking['status'] === 'confirmed'): ?>
                <button class="btn btn-warning btn-sm" onclick="bookingAction('checkin')"><i class="bi bi-box-arrow-in-right me-1"></i>Check In</button>
                <?php endif; ?>
                <?php if ($booking['status'] === 'checked_in'): ?>
                <button class="btn btn-info btn-sm text-white" data-ars-action="checkout"><i class="bi bi-box-arrow-right me-1"></i>Check Out</button>
                <?php endif; ?>
                <?php if ($booking['status'] === 'checked_out'): ?>
                <button class="btn btn-primary btn-sm" onclick="bookingAction('complete')"><i class="bi bi-check2-all me-1"></i>Mark Complete</button>
                <?php endif; ?>
                <?php if (in_array($booking['status'], ['pending','confirmed'])): ?>
                <button class="btn btn-outline-danger btn-sm" onclick="if(confirm('Cancel this booking?')) bookingAction('cancel')"><i class="bi bi-x-circle me-1"></i>Cancel</button>
                <?php endif; ?>
                <a href="maintenance.php" class="btn btn-outline-secondary btn-sm" onclick="sessionStorage.setItem('prefillMaintUnit','<?= $booking['unit_id'] ?>'); sessionStorage.setItem('prefillMaintBooking','<?= (int)$bookingId ?>')"><i class="bi bi-wrench me-1"></i>Report Issue</a>
            </div>
        </div>
        <?php endif; ?>
<?php
// Capture lifecycle requests card into overview (keeps one Overview panel).
ob_start();
?>
        <div class="ars-card mb-4" id="lifecycle-requests">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-chat-square-text me-2"></i>Guest Lifecycle Requests</span>
                <span class="badge bg-secondary"><?= count($lifecycleRequests) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table ars-table ars-mobile-cards mb-0">
                    <thead><tr><th>Requested</th><th>Type</th><th>Status</th><th>Guest Details</th><th>Admin Note</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (empty($lifecycleRequests)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No lifecycle requests from guest yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($lifecycleRequests as $req): ?>
                        <tr>
                            <td data-label="Requested"><?= h($req['created_at']) ?></td>
                            <td data-label="Type">
                                <span class="badge bg-info text-dark"><?= h(ucfirst(str_replace('_', ' ', (string)$req['request_type']))) ?></span>
                                <?php if (!empty($req['contact_subject'])): ?>
                                    <div class="small text-muted mt-1"><?= h($req['contact_subject']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <?php
                                $st = (string)$req['status'];
                                $stClass = $st === 'approved' ? 'success' : ($st === 'rejected' ? 'danger' : ($st === 'cancelled' ? 'secondary' : 'warning text-dark'));
                                ?>
                                <span class="badge bg-<?= $stClass ?>"><?= h($st) ?></span>
                            </td>
                            <td data-label="Guest Details">
                                <?php if (!empty($req['guest_note'])): ?><div><?= nl2br(h($req['guest_note'])) ?></div><?php endif; ?>
                                <?php if (!empty($req['requested_check_out'])): ?><div class="small text-muted">Requested check-out: <?= h($req['requested_check_out']) ?></div><?php endif; ?>
                                <?php if (!empty($req['requested_check_in'])): ?><div class="small text-muted">Requested check-in: <?= h($req['requested_check_in']) ?></div><?php endif; ?>
                                <?php if (!empty($req['requested_time'])): ?><div class="small text-muted">Requested time: <?= h($req['requested_time']) ?></div><?php endif; ?>
                            </td>
                            <td data-label="Admin Note">
                                <?= $req['admin_note'] ? nl2br(h($req['admin_note'])) : '—' ?>
                                <?php if (!empty($req['reviewed_by_name'])): ?><div class="small text-muted mt-1">By: <?= h($req['reviewed_by_name']) ?></div><?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <?php if ($req['status'] === 'pending'): ?>
                                    <button class="btn btn-success btn-sm mb-1" onclick="reviewLifecycleRequest(<?= (int)$req['id'] ?>, 'approve_lifecycle_request')">Approve</button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="reviewLifecycleRequest(<?= (int)$req['id'] ?>, 'reject_lifecycle_request')">Reject</button>
                                <?php else: ?>
                                    <span class="text-muted small d-block mb-1">Reviewed</span>
                                    <?php
                                    $canReapply = $req['status'] === 'approved'
                                        && in_array((string)$req['request_type'], ['extension', 'cancellation', 'early_checkin', 'late_checkout', 'support'], true)
                                        && (
                                            ((string)$req['request_type'] === 'extension'
                                                && !empty($req['requested_check_out'])
                                                && (string)$req['requested_check_out'] !== (string)$booking['check_out'])
                                            || ((string)$req['request_type'] === 'cancellation'
                                                && (string)$booking['status'] !== 'cancelled')
                                        );
                                    ?>
                                    <?php if ($canReapply): ?>
                                        <button class="btn btn-outline-primary btn-sm" onclick="reviewLifecycleRequest(<?= (int)$req['id'] ?>, 'reapply_lifecycle_request')">Apply to booking</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
<?php
$arsWsLifecycleHtml = ob_get_clean();
echo $arsWsLifecycleHtml;
?>
<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-box-seam me-1"></i> Material requests for this booking</span>
        <a class="btn btn-sm btn-ars-outline" href="my_material_requests.php">My material requests</a>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-2">Stock requests linked to this booking (any user). Check before submitting another request.</p>
        <?php
        $companyId = $arsCompanyId;
        $rows = $matReqForBooking;
        $detailPage = 'material_request_view.php';
        $showRequestedBy = true;
        require __DIR__ . '/../../includes/inventory/partials/material_requests_list_table.php';
        ?>
    </div>
</div>
        </div>
        </section>

        <section id="ars-ws-panel-timeline" data-ars-ws-panel="timeline" class="ars-ws-panel">
        <div id="ws-activity">
        <!-- Booking Activity Center -->
        <div class="ars-card mb-4" id="activity-center">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-activity me-2"></i>Booking Activity Center</span>
                <span class="badge bg-light text-dark border" id="activityTotalBadge">—</span>
            </div>
            <div class="card-body pb-2">
                <div class="ars-activity-filters d-flex flex-wrap gap-1 mb-3" role="tablist" aria-label="Activity filters">
                    <?php
                    $activityFilters = [
                        'all' => 'All',
                        'operational' => 'Operational',
                        'financial' => 'Financial',
                        'payment' => 'Payments',
                        'accounting' => 'Accounting',
                        'housekeeping' => 'Housekeeping',
                        'maintenance' => 'Maintenance',
                        'notes' => 'Notes',
                        'documents' => 'Documents',
                        'system' => 'System',
                    ];
                    foreach ($activityFilters as $fkey => $flabel):
                    ?>
                    <button type="button" class="btn btn-sm ars-activity-filter <?= $fkey === 'all' ? 'active' : '' ?>" data-filter="<?= h($fkey) ?>"><?= h($flabel) ?></button>
                    <?php endforeach; ?>
                </div>
                <div id="activityFeed" class="ars-activity-feed" aria-live="polite">
                    <div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>Loading activity…</div>
                </div>
                <div class="text-center mt-2 mb-1">
                    <button type="button" class="btn btn-ars-outline btn-sm d-none" id="activityLoadMore">Load more</button>
                </div>
            </div>
        </div>
        </div>
        </section>

        <section id="ars-ws-panel-money" data-ars-ws-panel="money" class="ars-ws-panel">
        <div id="ws-money">
        <!-- Charges -->
        <div class="ars-card mb-4" id="charges">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-receipt me-2"></i>Charges</span>
                <?php if (!in_array($booking['status'], ['completed','cancelled','expired'])): ?>
                <button class="btn btn-ars btn-sm" data-bs-toggle="modal" data-bs-target="#addChargeModal"><i class="bi bi-plus-lg"></i></button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table ars-table ars-mobile-cards mb-0">
                    <thead><tr><th>Type</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php if (empty($charges)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No extra charges.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($charges as $ch): ?>
                        <tr>
                            <td data-label="Type"><span class="badge bg-secondary"><?= h(str_replace('_',' ',$ch['charge_type'])) ?></span></td>
                            <td data-label="Description"><?= h($ch['description']) ?></td>
                            <td data-label="Qty"><?= $ch['quantity'] ?></td>
                            <td data-label="Unit Price">AED <?= number_format((float)$ch['unit_price'],2) ?></td>
                            <td data-label="Total" class="fw-semibold">AED <?= number_format((float)$ch['total'],2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payments -->
        <div class="ars-card mb-4" id="payments">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cash-coin me-2"></i>Payments</span>
                <?php if (!in_array($booking['status'], ['cancelled','expired'])): ?>
                <button class="btn btn-ars btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal"><i class="bi bi-plus-lg"></i></button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table ars-table ars-mobile-cards mb-0">
                    <thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Status</th><th>Reference</th><th>Stripe</th><th>Journal</th><th>Link</th></tr></thead>
                    <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No payments recorded.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $pm): ?>
                        <tr>
                            <td data-label="Date"><?= h($pm['payment_date']) ?></td>
                            <td data-label="Method">
                                <?= h(ucfirst(str_replace('_',' ',$pm['payment_method']))) ?>
                                <?php if (($pm['payment_gateway'] ?? '') === 'stripe'): ?>
                                    <span class="badge bg-dark ms-1">Stripe</span>
                                    <?php if (!empty($pm['payment_type'])): ?><span class="badge bg-info text-dark ms-1"><?= h($pm['payment_type']) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="Amount" class="fw-semibold text-success">
                                <?= h($pm['currency'] ?: 'AED') ?> <?= number_format((float)$pm['amount'],2) ?>
                                <?php if ((float)($pm['amount_refunded'] ?? 0) > 0): ?>
                                    <br><small class="text-warning">Refunded <?= h($pm['currency'] ?: 'AED') ?> <?= number_format((float)$pm['amount_refunded'],2) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <?php if (($pm['payment_gateway'] ?? '') === 'stripe'): ?>
                                    <span class="badge bg-<?= ($pm['gateway_status'] ?? '') === 'succeeded' ? 'success' : (in_array(($pm['gateway_status'] ?? ''), ['requires_payment_method','failed','canceled']) ? 'danger' : 'warning text-dark') ?>">
                                        <?= h($pm['gateway_status'] ?: 'pending') ?>
                                    </span>
                                    <?php if (!empty($pm['failure_message'])): ?><div class="small text-danger mt-1"><?= h($pm['failure_message']) ?></div><?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-success">recorded</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Reference"><?= h($pm['reference_number'] ?: '—') ?></td>
                            <td data-label="Stripe">
                                <?php if (!empty($pm['gateway_payment_intent_id'])): ?>
                                    <code class="small"><?= h($pm['gateway_payment_intent_id']) ?></code>
                                    <?php if (!empty($pm['gateway_charge_id'])): ?><br><code class="small"><?= h($pm['gateway_charge_id']) ?></code><?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td data-label="Journal">
                                <?php if ($pm['journal_id']):
                                    $pmJournal = null;
                                    foreach ($journals as $j) { if ($j['id'] === (int)$pm['journal_id']) { $pmJournal = $j; break; } }
                                    if ($pmJournal): ?>
                                    <span class="badge bg-success" title="Posted"><?= h($pmJournal['journal_number']) ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">JV#<?= $pm['journal_id'] ?></span>
                                    <?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td data-label="Link">
                                <?php if ($pm['payment_link_url']): ?>
                                <a href="<?= h($pm['payment_link_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-link-45deg"></i></a>
                                <?php if ($pm['payment_link_status'] === 'pending'): ?>
                                <span class="badge bg-warning text-dark" style="cursor:pointer" onclick="markLinkPaid(<?= (int)$pm['id'] ?>)" title="Click to mark as paid">pending</span>
                                <?php elseif ($pm['payment_link_status']): ?>
                                <span class="badge bg-success"><?= h($pm['payment_link_status']) ?></span>
                                <?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($stripeEvents)): ?>
        <div class="ars-card mb-4">
            <div class="card-header"><i class="bi bi-activity me-2"></i>Stripe Event Audit</div>
            <div class="table-responsive">
                <table class="table ars-table ars-mobile-cards mb-0">
                    <thead><tr><th>Received</th><th>Event</th><th>Status</th><th>Payment Intent</th><th>Error</th></tr></thead>
                    <tbody>
                    <?php foreach ($stripeEvents as $ev): ?>
                        <tr>
                            <td data-label="Received"><?= h($ev['created_at']) ?></td>
                            <td data-label="Event"><?= h($ev['event_type']) ?></td>
                            <td data-label="Status"><span class="badge bg-<?= $ev['status'] === 'processed' ? 'success' : ($ev['status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= h($ev['status']) ?></span></td>
                            <td data-label="Payment Intent"><?= $ev['payment_intent_id'] ? '<code class="small">' . h($ev['payment_intent_id']) . '</code>' : '—' ?></td>
                            <td data-label="Error"><?= h($ev['error_message'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        </div>
        </section>

        <section id="ars-ws-panel-deposit" data-ars-ws-panel="deposit" class="ars-ws-panel">
        <div id="ws-deposit">
        <!-- Security Deposit -->
        <div class="ars-card mb-4" id="security-deposit">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-shield-lock me-2"></i>Security Deposit</span>
                <?= arsDepositStatusBadge($depositStatus) ?>
            </div>
            <div class="card-body">
                <?php $canEditDeposit = in_array($depositStatus, ['none', 'pending'], true); ?>
                <?php if ($canEditDeposit): ?>
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold small mb-1">Deposit amount (AED)</label>
                        <input type="number" step="0.01" min="0" id="depositAmountInput" class="form-control"
                               value="<?= $depositAmount > 0 ? number_format($depositAmount, 2, '.', '') : '' ?>"
                               placeholder="0.00">
                        <small class="text-muted">Refundable hold — separate from stay total. Guest can pay online or cash in the app.</small>
                    </div>
                    <div class="col-sm-auto">
                        <button type="button" class="btn btn-ars btn-sm" onclick="saveSecurityDeposit()">
                            <i class="bi bi-save me-1"></i>Save amount
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Deposit Amount</strong><span class="fw-bold">AED <?= number_format($depositAmount,2) ?></span></div>
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Payment method</strong><?= h($booking['deposit_payment_method'] ?? '—') ?></div>
                    <?php if (!empty($booking['deposit_cash_requested'])): ?>
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Guest choice</strong><span class="badge bg-warning text-dark">Cash selected in app</span></div>
                    <?php endif; ?>
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Received</strong><?= $booking['deposit_received_date'] ? h($booking['deposit_received_date']) : '—' ?></div>
                    <?php $refunded = (float)($booking['deposit_refunded_amount'] ?? 0); ?>
                    <?php $forfeited = (float)($booking['deposit_forfeited_amount'] ?? 0); ?>
                    <?php $heldRemaining = ars_deposit_held_remaining($booking); ?>
                    <?php if ($refunded > 0): ?>
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Refunded</strong>AED <?= number_format($refunded,2) ?> (<?= h($booking['deposit_refunded_date'] ?? '') ?>)</div>
                    <?php endif; ?>
                    <?php if ($forfeited > 0): ?>
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Deductions / forfeited</strong>AED <?= number_format($forfeited,2) ?></div>
                    <?php endif; ?>
                    <?php if (in_array($depositStatus, ['received', 'partially_refunded'], true)): ?>
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Still held</strong>AED <?= number_format($heldRemaining,2) ?></div>
                    <?php endif; ?>
                    <?php if ($booking['deposit_journal_id'] ?? null): ?>
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Receive Journal</strong><span class="badge bg-success">JV#<?= (int)$booking['deposit_journal_id'] ?></span></div>
                    <?php endif; ?>
                    <?php if ($booking['deposit_settlement_journal_id'] ?? null): ?>
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Settlement Journal</strong><span class="badge bg-warning text-dark">JV#<?= (int)$booking['deposit_settlement_journal_id'] ?></span></div>
                    <?php elseif ($booking['deposit_refund_journal_id'] ?? null): ?>
                    <div class="col-6 col-sm-4"><strong class="text-muted d-block small">Refund Journal</strong><span class="badge bg-info">JV#<?= (int)$booking['deposit_refund_journal_id'] ?></span></div>
                    <?php endif; ?>
                </div>
                <?php if (!in_array($booking['status'], ['cancelled','expired'])): ?>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($depositStatus === 'pending'): ?>
                    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#depositReceiveModal"><i class="bi bi-check-circle me-1"></i>Record Deposit Received</button>
                    <?php endif; ?>
                    <?php if (in_array($depositStatus, ['received','partially_refunded'], true)): ?>
                    <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#depositRefundModal"><i class="bi bi-arrow-counterclockwise me-1"></i>Settle deposit</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        </div>
        </section>

        <section id="ars-ws-panel-documents" data-ars-ws-panel="documents" class="ars-ws-panel">
        <div id="documents">
        <!-- Unified Documents — one flat list; the Type column says where each doc is filed -->
        <div class="ars-card mb-4" id="ws-docs">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-folder2-open me-2"></i>Documents
                    <span class="badge bg-secondary ms-1"><?= (int)$unifiedDocsTotal ?></span>
                </span>
                <div class="d-flex gap-1 flex-wrap">
                    <button type="button" class="btn btn-ars-outline btn-sm" data-bs-toggle="modal" data-bs-target="#documentsActionModal" data-ars-docs-mode="send"><i class="bi bi-envelope-check me-1"></i>Send to guest</button>
                    <button type="button" class="btn btn-ars btn-sm" data-bs-toggle="modal" data-bs-target="#attachmentModal"><i class="bi bi-upload me-1"></i>Upload</button>
                </div>
            </div>
            <div class="card-body pb-3">
                <?php if (empty($unifiedDocsFlat)): ?>
                <div class="border rounded text-center text-muted py-4 px-2 bg-light">
                    <i class="bi bi-folder2-open fs-4 d-block mb-2"></i>
                    No documents on this booking yet.
                </div>
                <?php else: ?>
                <div class="table-responsive border rounded">
                    <table class="table ars-table ars-mobile-cards ars-udoc-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ars-udoc-col-doc">Document</th>
                                <th class="ars-udoc-col-type">Type</th>
                                <th class="ars-udoc-col-date">Date</th>
                                <th class="text-end ars-udoc-col-amount">Amount</th>
                                <th class="text-end ars-udoc-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($unifiedDocsFlat as $item): ?>
                            <tr>
                                <td data-label="Document" class="ars-udoc-col-doc">
                                    <div class="fw-semibold"><?= h($item['title']) ?></div>
                                    <div class="ars-udoc-meta">
                                        <span class="badge <?= h($item['badge_class']) ?>"><?= h($item['badge']) ?></span>
                                        <?php if ($item['status'] !== ''): ?>
                                        <span class="badge <?= h($item['status_class']) ?>"<?= $item['unavailable_reason'] !== '' ? ' title="' . h($item['unavailable_reason']) . '"' : '' ?>><?= h(str_replace('_', ' ', $item['status'])) ?></span>
                                        <?php endif; ?>
                                        <?php if ($item['subtitle'] !== ''): ?>
                                        <span class="text-muted"><?= h($item['subtitle']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Type" class="ars-udoc-col-type">
                                    <?php if ($item['attachment_id'] !== null): ?>
                                    <select class="form-select form-select-sm ars-udoc-refile"
                                            data-ars-attachment-id="<?= (int)$item['attachment_id'] ?>"
                                            aria-label="Change document type">
                                        <?php foreach ($docCategories as $optKey => $optMeta): ?>
                                        <option value="<?= h($optKey) ?>" <?= $optKey === $item['category'] ? 'selected' : '' ?>><?= h($optMeta['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php else: ?>
                                    <span class="ars-udoc-type"><?= h($item['category_label']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Date" class="ars-udoc-col-date"><?= $item['date'] !== '' ? h($item['date']) : '—' ?></td>
                                <td data-label="Amount" class="text-end ars-tabular ars-udoc-col-amount">
                                    <?= $item['amount'] !== null ? h($item['currency']) . ' ' . number_format((float)$item['amount'], 2) : '—' ?>
                                </td>
                                <td data-label="Actions" class="text-end ars-udoc-actions">
                                    <div class="ars-udoc-actions-row">
                                        <?php if ($item['view_url'] !== ''): ?>
                                        <a class="btn btn-sm btn-ars-outline" href="<?= h($item['view_url']) ?>">View</a>
                                        <?php endif; ?>
                                        <?php if ($item['download_url'] !== '' && $item['available']): ?>
                                        <a class="btn btn-sm btn-ars-outline" href="<?= h($item['download_url']) ?>" target="_blank" rel="noopener"><?= $item['kind'] === 'upload' ? 'Download' : 'PDF' ?></a>
                                        <?php endif; ?>
                                        <?php if ($item['attachment_id'] !== null): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-ars-attachment-delete="<?= (int)$item['attachment_id'] ?>"
                                                data-ars-attachment-name="<?= h($item['title']) ?>">Delete</button>
                                        <?php endif; ?>
                                        <?php if ($item['can_send']): ?>
                                        <button type="button" class="btn btn-sm btn-ars-outline"
                                                data-ars-doc-send="<?= h($item['doc_type']) ?>"
                                                data-ars-doc-payment="<?= h((string)($item['payment_id'] ?? '')) ?>">Email</button>
                                        <?php endif; ?>
                                        <?php if ($item['kind'] === 'deposit'): ?>
                                        <button type="button" class="btn btn-sm btn-ars-outline" onclick="openWorkspaceTab('deposit', 'security-deposit')">Open</button>
                                        <?php endif; ?>
                                        <?php // show a dash only when no button above actually rendered ?>
                                        <?php if ($item['view_url'] === '' && !($item['download_url'] !== '' && $item['available']) && !$item['can_send'] && $item['kind'] !== 'deposit'): ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Accounting Trail (journals) — audit detail, collapsed so the tab stays readable -->
        <div class="ars-card mb-4" id="ws-accounting-trail">
            <div class="card-header">
                <button type="button"
                        class="btn btn-link p-0 w-100 text-start fw-semibold d-flex justify-content-between align-items-center"
                        style="color:inherit"
                        data-bs-toggle="collapse" data-bs-target="#accountingTrailBody"
                        aria-expanded="false" aria-controls="accountingTrailBody">
                    <span><i class="bi bi-journal-check me-2"></i>Accounting trail (audit)
                        <span class="badge bg-secondary ms-1"><?= count($journals) ?></span>
                    </span>
                    <i class="bi bi-chevron-down small" aria-hidden="true"></i>
                </button>
            </div>
            <div class="collapse" id="accountingTrailBody">
            <?php if (empty($journals)): ?>
            <div class="card-body text-center text-muted py-4"><i class="bi bi-journal-x fs-3 d-block mb-2"></i>No journal entries yet.<br><small>Journals are created when bookings are confirmed, payments recorded, or deposits processed.</small></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table ars-table ars-mobile-cards mb-0">
                    <thead><tr><th>#</th><th>Type</th><th>Journal #</th><th>Date</th><th>Status</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($journals as $idx => $j): ?>
                        <?php $jlId = 'jl' . (int)$j['id']; ?>
                        <tr>
                            <td data-label="#">
                              <button type="button"
                                      class="btn btn-link btn-sm p-0 text-decoration-none ars-journal-lines-toggle"
                                      data-bs-toggle="collapse"
                                      data-bs-target="#<?= h($jlId) ?>"
                                      aria-expanded="false"
                                      aria-controls="<?= h($jlId) ?>"
                                      title="Show journal lines">
                                <?= $idx + 1 ?> <i class="bi bi-chevron-down small" aria-hidden="true"></i>
                              </button>
                            </td>
                            <td data-label="Type"><span class="badge bg-<?= $j['reference_type'] === 'ars_booking' || $j['reference_type'] === 'ars_financial_document' ? 'primary' : ($j['journal_type'] === 'reversal' ? 'danger' : ($j['reference_type'] === 'ars_deposit' ? 'info' : ($j['reference_type'] === 'ars_deposit_refund' || $j['reference_type'] === 'ars_deposit_settlement' ? 'warning' : 'success'))) ?>"><?= h($j['type_label']) ?></span></td>
                            <td data-label="Journal #" class="fw-semibold">
                              <a class="ars-journal-link text-decoration-none" href="<?= h($journalViewBase . (int)$j['id'] . $journalCompanyQs) ?>" target="_blank" rel="noopener noreferrer"><?= h($j['journal_number']) ?></a>
                            </td>
                            <td data-label="Date"><?= h($j['date']) ?></td>
                            <td data-label="Status">
                                <?php if ($j['is_reversed']): ?><span class="badge bg-danger">Reversed</span>
                                <?php elseif ($j['is_posted']): ?><span class="badge bg-success">Posted</span>
                                <?php else: ?><span class="badge bg-warning text-dark">Draft</span><?php endif; ?>
                            </td>
                            <td data-label="Total" class="fw-semibold">AED <?= number_format($j['total_debit'],2) ?></td>
                        </tr>
                        <tr class="ars-journal-lines-row">
                            <td colspan="6" class="p-0 border-0">
                                <div class="collapse" id="<?= h($jlId) ?>">
                                    <table class="table table-sm mb-0 ars-journal-lines-table">
                                        <thead><tr class="small"><th>Account Code</th><th>Account Name</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($j['lines'] as $line): ?>
                                        <tr class="small">
                                            <td><code><?= h($line['account_code'] ?? '') ?></code></td>
                                            <td><?= h($line['account_name'] ?? '') ?></td>
                                            <td class="text-end"><?= (float)($line['debit_amount'] ?? 0) > 0 ? 'AED ' . number_format((float)$line['debit_amount'],2) : '' ?></td>
                                            <td class="text-end"><?= (float)($line['credit_amount'] ?? 0) > 0 ? 'AED ' . number_format((float)$line['credit_amount'],2) : '' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            </div>
        </div>
        </div>
        </section>
    </div>

    <!-- Right: Quick Actions + Pricing Summary -->
    <div class="col-lg-4">
        <div class="ars-ws-rail">
        <div class="ars-card mb-4" id="quick-actions">
            <div class="card-header"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</div>
            <div class="card-body">
                <div class="d-grid gap-2 ars-quick-actions">
                    <?php if (!in_array($booking['status'], ['cancelled','expired'], true) && $balanceDue > 0.009): ?>
                    <button type="button" class="btn btn-ars btn-sm w-100 text-start" data-bs-toggle="modal" data-bs-target="#addPaymentModal"><i class="bi bi-cash-coin me-2"></i>Collect payment (AED <?= number_format($balanceDue, 2) ?>)</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ars-outline btn-sm w-100 text-start" data-bs-toggle="modal" data-bs-target="#amendmentModal" data-ars-amend-tab="service"><i class="bi bi-plus-circle me-2"></i>Additional Service</button>
                    <button type="button" class="btn btn-ars-outline btn-sm w-100 text-start" data-bs-toggle="modal" data-bs-target="#amendmentModal" data-ars-amend-tab="damage"><i class="bi bi-exclamation-diamond me-2"></i>Damage Charge</button>
                    <div class="row g-2 mt-1">
                    <div class="col-6"><button type="button" class="btn btn-ars-outline btn-sm w-100" onclick="openWorkspaceTab('deposit', 'security-deposit')"><i class="bi bi-shield-lock me-1"></i>Deposit</button></div>
                    <div class="col-6"><a class="btn btn-ars-outline btn-sm w-100" href="housekeeping.php?booking_id=<?= (int)$bookingId ?>&amp;unit_id=<?= (int)$booking['unit_id'] ?>"><i class="bi bi-broom me-1"></i>HK</a></div>
                    <div class="col-6"><a class="btn btn-ars-outline btn-sm w-100" href="maintenance.php" onclick="sessionStorage.setItem('prefillMaintUnit','<?= (int)$booking['unit_id'] ?>'); sessionStorage.setItem('prefillMaintBooking','<?= (int)$bookingId ?>')"><i class="bi bi-wrench me-1"></i>Maint</a></div>
                    <div class="col-6"><button type="button" class="btn btn-ars-outline btn-sm w-100" data-bs-toggle="modal" data-bs-target="#internalNoteModal"><i class="bi bi-sticky me-1"></i>Note</button></div>
                    <div class="col-6"><button type="button" class="btn btn-ars-outline btn-sm w-100" data-bs-toggle="modal" data-bs-target="#documentsActionModal" data-ars-docs-mode="print"><i class="bi bi-printer me-1"></i>Print / PDF</button></div>
                    <div class="col-6"><button type="button" class="btn btn-ars-outline btn-sm w-100" data-bs-toggle="modal" data-bs-target="#documentsActionModal" data-ars-docs-mode="send"><i class="bi bi-envelope-check me-1"></i>Send docs</button></div>
                    <div class="col-12"><button type="button" class="btn btn-ars-outline btn-sm w-100" data-bs-toggle="modal" data-bs-target="#amendmentModal" data-ars-amend-tab="extension"><i class="bi bi-calendar-range me-1"></i>Extend stay</button></div>
                    <div class="col-12"><button type="button" class="btn btn-ars-outline btn-sm w-100" data-bs-toggle="modal" data-bs-target="#amendmentModal" data-ars-amend-tab="adjustment"><i class="bi bi-sliders me-1"></i>Rate adjustment</button></div>
                    <div class="col-12"><button type="button" class="btn btn-ars-outline btn-sm w-100" data-bs-toggle="modal" data-bs-target="#amendmentModal" data-ars-amend-tab="credit"><i class="bi bi-arrow-counterclockwise me-1"></i>Credit note / stay refund</button></div>
                    </div>
                </div>
                <?php if ($finLocked): ?>
                <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Financial lock is on. Extra charges, extensions, and credit notes create <strong>new</strong> financial documents (they do not rewrite the original invoice).</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="ars-card ars-sticky-card ars-ws-pricing">
            <div class="card-header"><i class="bi bi-calculator me-2"></i>Pricing Summary</div>
            <div class="card-body">
                <?php
                $rulesApplied = [];
                if (!empty($booking['pricing_rules_applied'])) {
                    $decoded = json_decode($booking['pricing_rules_applied'], true);
                    if (is_array($decoded)) $rulesApplied = $decoded;
                }
                if (!empty($rulesApplied)):
                ?>
                <div class="alert alert-info py-1 px-2 mb-2 small"><i class="bi bi-tags me-1"></i><?= h(implode(', ', $rulesApplied)) ?></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Nightly Rate</span><span class="ars-tabular">AED <?= number_format((float)$booking['nightly_rate'],2) ?></span></div>
                <?php if ($booking['rate_override'] > 0): ?>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Override Rate</span><span class="text-warning ars-tabular">AED <?= number_format((float)$booking['rate_override'],2) ?></span></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Nights</span><span class="ars-tabular"><?= (int)$booking['nights'] ?></span></div>
                <hr>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Room Subtotal</span><span class="ars-tabular">AED <?= number_format((float)$booking['subtotal'],2) ?></span></div>
                <?php
                $lengthDiscAmt = (float)($booking['length_discount_amount'] ?? 0);
                $lengthDiscLabel = $booking['length_discount_label'] ?? null;
                if ($lengthDiscAmt > 0):
                ?>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-success"><i class="bi bi-percent me-1"></i><?= h($lengthDiscLabel ?: 'Length Discount') ?></span>
                    <span class="text-success ars-tabular">- AED <?= number_format($lengthDiscAmt, 2) ?></span>
                </div>
                <?php endif; ?>
                <?php
                $bookingDiscAmt = (float)($booking['discount_amount'] ?? 0);
                $bookingDiscLabel = $booking['discount_label'] ?? null;
                $bookingDiscType = $booking['discount_type'] ?? 'none';
                if ($bookingDiscAmt > 0):
                ?>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-warning"><i class="bi bi-ticket-perforated me-1"></i><?= h($bookingDiscLabel ?: 'Discount') ?>
                    <?php if ($bookingDiscType === 'promo'): ?><span class="badge bg-dark ms-1 font-monospace small"><?= h($bookingDiscLabel) ?></span><?php endif; ?>
                    </span>
                    <span class="text-warning ars-tabular">- AED <?= number_format($bookingDiscAmt, 2) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($booking['extras_total'] > 0): ?>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Extra Charges (net)</span><span class="ars-tabular">AED <?= number_format((float)$booking['extras_total'],2) ?></span></div>
                <?php endif; ?>
                <?php if (isset($booking['net_amount']) && $booking['net_amount'] !== null && $booking['net_amount'] !== ''): ?>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Taxable net (revenue base)</span><span class="ars-tabular">AED <?= number_format((float)$booking['net_amount'],2) ?></span></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">VAT</span><span class="ars-tabular">AED <?= number_format((float)$booking['vat_amount'],2) ?></span></div>
                <hr>
                <div class="d-flex justify-content-between align-items-baseline fw-bold mb-3"><span>Stay total</span><span class="ars-ws-price-total">AED <?= number_format($stayTotal,2) ?></span></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Paid</span><span class="text-success ars-tabular">AED <?= number_format((float)$booking['paid_amount'],2) ?></span></div>
                <div class="d-flex justify-content-between fw-bold"><span>Balance due</span><span class="ars-tabular <?= $balanceDue > 0 ? 'text-danger' : 'text-success' ?>">AED <?= number_format($balanceDue,2) ?></span></div>
                <?php if ($openDocsBalance > 0.009): ?>
                <div class="d-flex justify-content-between mt-1"><span class="text-muted small">Open invoices</span><span class="text-danger small ars-tabular">AED <?= number_format($openDocsBalance, 2) ?></span></div>
                <?php endif; ?>
                <div class="mt-2"><?= arsPaymentStatusBadge($booking['payment_status']) ?></div>
                <?php if ($balanceDue > 0.009): ?>
                <button type="button" class="btn btn-ars btn-sm w-100 mt-3" data-bs-toggle="modal" data-bs-target="#addPaymentModal"><i class="bi bi-cash-coin me-1"></i>Collect AED <?= number_format($balanceDue, 2) ?></button>
                <?php endif; ?>
                <?php if ($depositAmount > 0): ?>
                <?php
                  $depRefundedAmt = (float)($booking['deposit_refunded_amount'] ?? 0);
                  $depForfeitedAmt = (float)($booking['deposit_forfeited_amount'] ?? 0);
                  $depHeldAmt = ars_deposit_held_remaining($booking);
                ?>
                <hr>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted"><i class="bi bi-shield-lock me-1"></i>Security deposit</span><span class="ars-tabular">AED <?= number_format($depositAmount,2) ?></span></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Deposit status</span><?= arsDepositStatusBadge($depositStatus) ?></div>
                <?php if ($depRefundedAmt > 0.009): ?>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Refunded to guest</span><span class="text-success ars-tabular">AED <?= number_format($depRefundedAmt, 2) ?></span></div>
                <?php endif; ?>
                <?php if ($depForfeitedAmt > 0.009): ?>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Deductions kept</span><span class="text-warning ars-tabular">AED <?= number_format($depForfeitedAmt, 2) ?></span></div>
                <?php endif; ?>
                <?php if (in_array($depositStatus, ['received', 'partially_refunded'], true) && $depHeldAmt > 0.009): ?>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Still held</span><span class="ars-tabular">AED <?= number_format($depHeldAmt, 2) ?></span></div>
                <?php endif; ?>
                <div class="small text-muted mt-1">Held separately — not part of stay total or VAT. Deductions have no VAT.</div>
                <?php endif; ?>
                <?php if ($collectNow > 0.009): ?>
                <div class="ars-ws-collect-now mt-3 pt-3 border-top">
                  <div class="d-flex justify-content-between align-items-baseline gap-2">
                    <div>
                      <div class="fw-bold">Amount to collect now</div>
                      <div class="small text-muted">Stay balance<?= $depositPendingCollect ? ' + pending deposit' : '' ?></div>
                    </div>
                    <span class="fw-bold fs-5 ars-tabular" style="color:var(--ars-primary)">AED <?= number_format($collectNow, 2) ?></span>
                  </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>
</div>

<!-- Edit Stay Dates Modal (pending + unlocked only) -->
<?php if ($booking['status'] === 'pending' && !$finLocked): ?>
<div class="modal fade" id="editStayDatesModal" tabindex="-1" aria-labelledby="editStayDatesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title" id="editStayDatesModalLabel"><i class="bi bi-calendar-range me-2"></i>Edit stay dates</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">Correct check-in / check-out on this <strong>pending</strong> booking. Availability is rechecked and nights / stay total are recalculated. No revenue journal is posted until you Confirm.</p>
                <div id="editStayDatesAlert" class="d-none"></div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="editStayCheckIn">Check-in</label>
                        <input type="date" class="form-control" id="editStayCheckIn" value="<?= h((string)$booking['check_in']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="editStayCheckOut">Check-out</label>
                        <input type="date" class="form-control" id="editStayCheckOut" value="<?= h((string)$booking['check_out']) ?>">
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="editStayPreviewBtn"><i class="bi bi-calculator me-1"></i>Preview price</button>
                </div>
                <div id="editStayPreviewBox" class="ars-card mb-3 d-none">
                    <div class="card-body py-3 small">
                        <div class="row g-2">
                            <div class="col-6"><span class="text-muted">Nights</span><div><span id="editStayOldNights">—</span> → <strong id="editStayNewNights">—</strong></div></div>
                            <div class="col-6"><span class="text-muted">Stay total</span><div>AED <span id="editStayOldTotal">—</span> → <strong>AED <span id="editStayNewTotal">—</span></strong></div></div>
                            <div class="col-6"><span class="text-muted">Subtotal</span><div id="editStayNewSubtotal">—</div></div>
                            <div class="col-6"><span class="text-muted">VAT</span><div id="editStayNewVat">—</div></div>
                        </div>
                    </div>
                </div>
                <div class="border rounded p-3">
                    <div class="fw-semibold mb-2">Security deposit</div>
                    <p class="small text-muted mb-2 mb-md-3">No automatic commercial rule — choose Keep or set a new amount (same as create).</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="editStayDepositMode" id="editStayDepKeep" value="keep" checked>
                        <label class="form-check-label" for="editStayDepKeep">Keep current deposit (AED <?= number_format($depositAmount, 2) ?>)</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="editStayDepositMode" id="editStayDepSet" value="set" <?= in_array($depositStatus, ['none', 'pending'], true) ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="editStayDepSet">Set new deposit amount</label>
                    </div>
                    <div class="mt-2" id="editStayDepAmountWrap" style="display:none">
                        <label class="form-label small fw-semibold" for="editStayDepAmount">New deposit (AED)</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="editStayDepAmount" value="<?= number_format($depositAmount, 2, '.', '') ?>" <?= in_array($depositStatus, ['none', 'pending'], true) ? '' : 'disabled' ?>>
                        <?php if (!in_array($depositStatus, ['none', 'pending'], true)): ?>
                        <small class="text-danger">Deposit can no longer be changed (status: <?= h($depositStatus) ?>).</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" id="editStayApplyBtn" disabled><i class="bi bi-check-lg me-1"></i>Apply changes</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Internal Note Modal -->
<div class="modal fade" id="internalNoteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-sticky me-2"></i>Add Internal Note</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Note</label>
                <textarea id="internalNoteText" class="form-control" rows="4" maxlength="4000" placeholder="Staff-only note (not shown to guest)"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" onclick="saveInternalNote()"><i class="bi bi-check-lg me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Charge Modal -->
<div class="modal fade" id="addChargeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Add Charge</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Type</label>
                    <select id="chargeType" class="form-select">
                        <option value="cleaning_fee">Cleaning Fee</option>
                        <option value="late_checkout">Late Checkout</option>
                        <option value="extra_guest">Extra Guest</option>
                        <option value="damage">Damage</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label fw-semibold">Description</label><input type="text" id="chargeDesc" class="form-control"></div>
                <div class="row g-3">
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Qty</label><input type="number" id="chargeQty" class="form-control" value="1" min="1"></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Unit Price (AED)</label><input type="number" step="0.01" id="chargePrice" class="form-control" value="0"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" onclick="addCharge()"><i class="bi bi-check-lg me-1"></i>Add</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Payment Modal -->
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="payModalAlert" class="d-none"></div>
                <?php if ($openDocsBalance > 0.009): ?>
                <div class="alert alert-info py-2 small mb-3">
                    Open financial documents: <strong>AED <?= number_format($openDocsBalance, 2) ?></strong>.
                    Payment allocates automatically to unpaid invoices (original / service / extension) via the Financial Adapter.
                </div>
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Amount (AED) *</label><input type="number" step="0.01" id="payAmount" class="form-control" value="<?= number_format(max($balanceDue, $openDocsBalance),2,'.','') ?>"></div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label fw-semibold">Method</label>
                        <select id="payMethod" class="form-select" data-ars-receipt-method>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="card">Card</option>
                            <option value="online">Online</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Post to GL account (RE) *</label>
                        <select id="payReceiptAccount" class="form-select" data-ars-receipt-account required>
                            <option value="">Select account…</option>
                        </select>
                        <div class="form-text">Posts under Real Estate company COA — active cash accounts under <strong>1100</strong> (except Stripe clearing 1140) or bank accounts under <strong>1200</strong>.</div>
                    </div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Date *</label><input type="date" id="payDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-12 col-sm-6"><label class="form-label fw-semibold">Reference #</label><input type="text" id="payRef" class="form-control"></div>
                    <div class="col-8"><label class="form-label fw-semibold">Payment Link URL</label><input type="url" id="payLink" class="form-control" placeholder="https://..." onchange="toggleLinkStatus()"></div>
                    <div class="col-4"><label class="form-label fw-semibold">Link Status</label>
                        <select id="payLinkStatus" class="form-select" disabled>
                            <option value="paid">Paid</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea id="payNotes" class="form-control" rows="2"></textarea></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" id="payRecordBtn" data-ars-action="record-payment" disabled title="Confirm amount, then click Record">
                    <i class="bi bi-check-lg me-1"></i>Record
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Deposit Receive Modal -->
<div class="modal fade" id="depositReceiveModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-shield-lock me-2"></i>Record Deposit Received</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="depRecModalAlert" class="d-none"></div>
                <div class="mb-3"><label class="form-label fw-semibold">Amount (AED)</label><input type="number" step="0.01" id="depRecAmount" class="form-control" value="<?= number_format($depositAmount,2,'.','') ?>" readonly></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Method</label>
                    <select id="depRecMethod" class="form-select" data-ars-receipt-method>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="card">Card</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Post to GL account (RE) *</label>
                    <select id="depRecReceiptAccount" class="form-select" data-ars-receipt-account required>
                        <option value="">Select account…</option>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label fw-semibold">Date</label><input type="date" id="depRecDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" data-ars-action="receive-deposit"><i class="bi bi-check-lg me-1"></i>Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Deposit Settle Modal (refund + optional deductions, no VAT) -->
<div class="modal fade" id="depositRefundModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise me-2"></i>Settle security deposit</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="depSettleModalAlert"></div>
                <?php $maxRefund = ars_deposit_held_remaining($booking); ?>
                <p class="small text-muted mb-3">
                    Held remaining: <strong>AED <?= number_format($maxRefund, 2) ?></strong>.
                    Deductions release liability to HH income with <strong>no VAT</strong> (damage → 4140, lost/other → 4410).
                    Refund cash/bank posts on Real Estate COA — select the account below.
                </p>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-semibold mb-0">Deductions</label>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="depAddDeductionBtn"><i class="bi bi-plus-lg me-1"></i>Add line</button>
                    </div>
                    <div id="depDeductionRows" class="d-flex flex-column gap-2"></div>
                    <small class="text-muted">Leave empty for a cash-only refund of the amount below.</small>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Refund to guest (AED)</label>
                        <input type="number" step="0.01" min="0" id="depRefAmount" class="form-control"
                               value="<?= number_format($maxRefund, 2, '.', '') ?>"
                               max="<?= number_format($maxRefund, 2, '.', '') ?>"
                               data-held-remaining="<?= number_format($maxRefund, 2, '.', '') ?>">
                        <small class="form-text text-muted">Defaults to remaining after deductions.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Method</label>
                        <select id="depRefMethod" class="form-select" data-ars-receipt-method>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="card">Card</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Date</label>
                        <input type="date" id="depRefDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Post refund to GL account (RE) *</label>
                        <select id="depRefReceiptAccount" class="form-select" data-ars-receipt-account required>
                            <option value="">Select account…</option>
                        </select>
                        <div class="form-text">Required when refund amount &gt; 0. Deduction-only settle may leave this blank.</div>
                    </div>
                </div>
                <div class="ars-card mt-3 mb-0">
                    <div class="card-body py-2 small">
                        <div class="d-flex justify-content-between"><span>Deductions total</span><strong id="depDeductTotalLbl">AED 0.00</strong></div>
                        <div class="d-flex justify-content-between"><span>Refund to guest</span><strong id="depRefundTotalLbl">AED <?= number_format($maxRefund, 2) ?></strong></div>
                        <div class="d-flex justify-content-between border-top pt-1 mt-1"><span>Still held after settle</span><strong id="depStillHeldLbl">AED 0.00</strong></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" data-ars-action="settle-deposit" onclick="settleDeposit()">
                    <i class="bi bi-check-lg me-1"></i>Settle deposit
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Print / Send documents -->
<div class="modal fade" id="documentsActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title" id="documentsActionTitle"><i class="bi bi-file-earmark-pdf me-2"></i>Documents</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="docsActionAlert" class="d-none"></div>
                <p class="small text-muted mb-2" id="docsActionHint">Choose a document. PDF uses the existing booking document helpers (no new journals).</p>
                <div id="docsActionList" class="list-group list-group-flush">
                    <div class="text-muted small py-3 text-center">Loading…</div>
                </div>
                <hr class="my-3">
                <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="docsPrintPageBtn">
                    <i class="bi bi-printer me-1"></i>Print this page
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Staff attachments -->
<div class="modal fade" id="attachmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="attachModalAlert" class="d-none"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="attachCategory">Document type</label>
                    <select id="attachCategory" class="form-select">
                        <?php foreach ($docCategories as $optKey => $optMeta): ?>
                        <option value="<?= h($optKey) ?>"<?= $optKey === 'other' ? ' selected' : '' ?>><?= h($optMeta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Files the system did not generate go here. Any type can be added later, including for older bookings.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Upload file</label>
                    <input type="file" id="attachFileInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt">
                    <div class="form-text">Max 10 MB. PDF, images, Office, or text. Stored company-scoped; PHP execution blocked in uploads.</div>
                </div>
                <button type="button" class="btn btn-ars btn-sm mb-3" id="attachUploadBtn"><i class="bi bi-upload me-1"></i>Upload</button>
                <div id="attachList" class="list-group list-group-flush small">
                    <div class="text-muted py-2">Loading…</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Amendment / money documents (Phase 2D adapter — new docs only) -->
<div class="modal fade" id="amendmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title"><i class="bi bi-file-earmark-diff me-2"></i>Booking amendment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="amendModalAlert" class="d-none"></div>
                <p class="small text-muted">Creates a <strong>new</strong> financial document via the Financial Adapter (GL company per BR-ARS-FIN-001). Does not rewrite posted invoice history.</p>
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-ars-amend-pane="service" id="amendTabService">Service</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-ars-amend-pane="damage" id="amendTabDamage">Damage</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-ars-amend-pane="extension" id="amendTabExtension">Extension</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-ars-amend-pane="adjustment" id="amendTabAdjustment">Rate adjustment</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-ars-amend-pane="credit" id="amendTabCredit">Credit note</button></li>
                </ul>
                <div data-ars-amend-panel="service" class="ars-amend-panel">
                    <div class="mb-3"><label class="form-label fw-semibold">Description *</label><input type="text" id="amendSvcDesc" class="form-control" placeholder="e.g. Extra cleaning / late checkout fee"></div>
                    <div class="row g-3">
                        <div class="col-sm-6"><label class="form-label fw-semibold">Amount ex-VAT (AED) *</label><input type="number" step="0.01" min="0.01" id="amendSvcNet" class="form-control" value="0"></div>
                        <div class="col-sm-6"><label class="form-label fw-semibold">Qty</label><input type="number" step="1" min="1" id="amendSvcQty" class="form-control" value="1"></div>
                    </div>
                    <p class="small text-muted mt-2 mb-0">Posts <code>service_invoice</code> → revenue role ADDITIONAL_SERVICE (4140 HH). VAT follows booking mode.</p>
                </div>
                <div data-ars-amend-panel="damage" class="ars-amend-panel d-none">
                    <div class="mb-3"><label class="form-label fw-semibold">Description *</label><input type="text" id="amendDmgDesc" class="form-control" placeholder="e.g. Broken glass / furniture damage"></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Amount ex-VAT (AED) *</label><input type="number" step="0.01" min="0.01" id="amendDmgNet" class="form-control" value="0"></div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="amendDmgApplyDep">
                        <label class="form-check-label" for="amendDmgApplyDep">Apply held deposit toward this damage (optional)</label>
                    </div>
                    <div class="mb-0 d-none" id="amendDmgDepWrap">
                        <label class="form-label fw-semibold">Deposit apply amount (AED)</label>
                        <input type="number" step="0.01" min="0" id="amendDmgDepAmt" class="form-control" value="0">
                    </div>
                    <p class="small text-muted mt-2 mb-0">Posts <code>service_invoice</code> line_type damage → DAMAGE_REVENUE (4140 HH). Prefer deposit settle deductions for simple forfeit cases.</p>
                </div>
                <div data-ars-amend-panel="extension" class="ars-amend-panel d-none">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New check-out date *</label>
                        <input type="date" id="amendExtOut" class="form-control"
                               min="<?= h(date('Y-m-d', strtotime((string)$booking['check_out'] . ' +1 day'))) ?>"
                               value="<?= h(date('Y-m-d', strtotime((string)$booking['check_out'] . ' +1 day'))) ?>">
                        <div class="form-text">Current check-out: <?= h((string)$booking['check_out']) ?>. Added nights billed at nightly rate via extension invoice.</div>
                    </div>
                </div>
                <div data-ars-amend-panel="adjustment" class="ars-amend-panel d-none">
                    <div class="mb-3"><label class="form-label fw-semibold">Description *</label><input type="text" id="amendAdjDesc" class="form-control" value="Rate correction per contract"></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount (AED) *</label>
                        <input type="number" step="0.01" min="0.01" id="amendAdjNet" class="form-control" value="0">
                        <div class="form-text">
                            Booking VAT mode: <strong><?= h(strtolower((string)($booking['vat_mode'] ?? 'exclusive'))) ?></strong> —
                            <?php if (strtolower((string)($booking['vat_mode'] ?? 'exclusive')) === 'inclusive'): ?>
                                enter the <strong>gross</strong> difference; VAT is extracted from it.
                            <?php else: ?>
                                enter the <strong>net (ex-VAT)</strong> difference; VAT is added on top.
                            <?php endif; ?>
                            Current stay total: AED <?= number_format((float)$booking['total_amount'], 2) ?>.
                        </div>
                    </div>
                    <p class="small text-muted mt-2 mb-0">Posts <code>adjustment_invoice</code> (ARS-ADJ) → revenue role ROOM_REVENUE. Use this for rate/total corrections on a locked booking — not Service, which books to ADDITIONAL_SERVICE_REVENUE.</p>
                </div>
                <div data-ars-amend-panel="credit" class="ars-amend-panel d-none">
                    <div class="mb-3"><label class="form-label fw-semibold">Description</label><input type="text" id="amendCnDesc" class="form-control" value="Stay credit note"></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Amount ex-VAT (AED) *</label><input type="number" step="0.01" min="0.01" id="amendCnNet" class="form-control" value="0"></div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="amendCnGuestCredit" checked>
                        <label class="form-check-label" for="amendCnGuestCredit">Create guest credit liability (default). Untick for AR reduction only.</label>
                    </div>
                    <p class="small text-muted mt-2 mb-0">Stay refund / CN path — separate from security deposit refund (already live on Deposit tab).</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" id="amendSubmitBtn"><i class="bi bi-check-lg me-1"></i>Post document</button>
            </div>
        </div>
    </div>
</div>

<?php
ars_shell_end();
