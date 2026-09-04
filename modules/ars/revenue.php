<?php
/**
 * ARS Home Rentals — Revenue Reports
 * Comprehensive revenue dashboard with KPIs, breakdowns, and CSV export.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_stripe.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_permissions.php';

$arsCompanyId = arsPageAuth($conn);
ars_stripe_ensure_schema($conn);
$brand = getBrandSettings($conn);
$settings = getArsSettings($conn, $arsCompanyId);
$currency = $settings['currency'] ?? 'AED';
$arsCompany = get_company($conn, $arsCompanyId);

// --- Filters ---
$dateFrom   = $_GET['date_from'] ?? date('Y-m-01');
$dateTo     = $_GET['date_to'] ?? date('Y-m-t');
$dateType   = $_GET['date_type'] ?? 'checkin';
$filterUnit = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
$filterStatus = $_GET['status'] ?? '';

$validStatuses = ['confirmed','checked_in','checked_out','completed','cancelled'];
if ($filterUnit) {
    try {
        ars_assert_unit_usable_for_ars($conn, $filterUnit, $arsCompanyId);
    } catch (Throwable $e) {
        $filterUnit = null;
    }
}

// Build WHERE for bookings
$bWhere  = ['b.company_id = ?'];
$bParams = [$arsCompanyId];

if ($dateType === 'payment') {
    $bWhere[]  = 'b.id IN (SELECT DISTINCT booking_id FROM ars_booking_payments WHERE payment_date BETWEEN ? AND ?)';
} else {
    $bWhere[]  = 'b.check_in <= ? AND b.check_out >= ?';
}
$bParams[] = $dateTo;
$bParams[] = $dateFrom;

if ($filterUnit) {
    $bWhere[]  = 'b.unit_id = ?';
    $bParams[] = $filterUnit;
}
if ($filterStatus && in_array($filterStatus, $validStatuses)) {
    $bWhere[]  = 'b.status = ?';
    $bParams[] = $filterStatus;
} else {
    $bWhere[]  = "b.status NOT IN ('expired')";
}

$whereSQL = implode(' AND ', $bWhere);

// Build WHERE for payments
$pWhere  = ['p.company_id = ?'];
$pParams = [$arsCompanyId];
$pWhere[]  = 'p.payment_date BETWEEN ? AND ?';
$pWhere[]  = "(p.payment_gateway IS NULL OR p.payment_gateway != 'stripe' OR p.gateway_status IN ('succeeded','partially_refunded','refunded'))";
$pParams[] = $dateFrom;
$pParams[] = $dateTo;
if ($filterUnit) {
    $pWhere[]  = 'p.booking_id IN (SELECT id FROM ars_bookings WHERE unit_id = ?)';
    $pParams[] = $filterUnit;
}
$pWhereSQL = implode(' AND ', $pWhere);
$paymentNetExpr = "GREATEST(p.amount - COALESCE(p.amount_refunded, 0), 0)";

// --- KPI Queries ---
$stmt = $conn->prepare("
    SELECT
        COUNT(*)                                            AS booking_count,
        COALESCE(SUM(b.nights), 0)                         AS total_nights,
        COALESCE(SUM(CASE WHEN b.status NOT IN ('cancelled') THEN b.total_amount ELSE 0 END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN b.status NOT IN ('cancelled') THEN b.vat_amount ELSE 0 END), 0)   AS total_vat,
        COALESCE(SUM(CASE WHEN b.status NOT IN ('cancelled') THEN b.paid_amount ELSE 0 END), 0)  AS total_paid,
        COALESCE(SUM(CASE WHEN b.status NOT IN ('cancelled') THEN b.balance_due ELSE 0 END), 0)  AS total_outstanding,
        COALESCE(SUM(b.discount_amount + b.length_discount_amount), 0) AS total_discounts,
        COALESCE(AVG(CASE WHEN b.nights > 0 AND b.status NOT IN ('cancelled') THEN b.subtotal / b.nights END), 0) AS avg_nightly
    FROM ars_bookings b
    WHERE $whereSQL
");
$stmt->execute($bParams);
$kpi = $stmt->fetch(PDO::FETCH_ASSOC);

// Payments received in date range
$stmt = $conn->prepare("SELECT COALESCE(SUM($paymentNetExpr), 0) AS payments_received FROM ars_booking_payments p WHERE $pWhereSQL");
$stmt->execute($pParams);
$paymentsReceived = (float)$stmt->fetchColumn();

// --- Monthly Trend ---
$trendSQL = $dateType === 'payment'
    ? "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS month, SUM($paymentNetExpr) AS revenue
       FROM ars_booking_payments p WHERE $pWhereSQL GROUP BY month ORDER BY month"
    : "SELECT DATE_FORMAT(b.check_in, '%Y-%m') AS month,
              SUM(CASE WHEN b.status NOT IN ('cancelled') THEN b.total_amount ELSE 0 END) AS revenue
       FROM ars_bookings b WHERE $whereSQL GROUP BY month ORDER BY month";
$trendParams = $dateType === 'payment' ? $pParams : $bParams;
$stmt = $conn->prepare($trendSQL);
$stmt->execute($trendParams);
$monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
$maxTrend = max(array_column($monthlyTrend, 'revenue') ?: [1]);

// --- Revenue by Unit ---
$stmt = $conn->prepare("
    SELECT u.unit_number, bl.name AS building,
           COUNT(*) AS bookings, SUM(b.nights) AS nights,
           SUM(CASE WHEN b.status NOT IN ('cancelled') THEN b.total_amount ELSE 0 END) AS revenue,
           AVG(CASE WHEN b.nights > 0 AND b.status NOT IN ('cancelled') THEN b.subtotal / b.nights END) AS avg_rate
    FROM ars_bookings b
    JOIN re_units u ON u.id = b.unit_id
    LEFT JOIN re_buildings bl ON bl.id = u.building_id
    WHERE $whereSQL
    GROUP BY b.unit_id
    ORDER BY revenue DESC
");
$stmt->execute($bParams);
$byUnit = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Revenue by Payment Method ---
$stmt = $conn->prepare("
    SELECT p.payment_method, COUNT(*) AS cnt, SUM($paymentNetExpr) AS total
    FROM ars_booking_payments p
    WHERE $pWhereSQL
    GROUP BY p.payment_method
    ORDER BY total DESC
");
$stmt->execute($pParams);
$byMethod = $stmt->fetchAll(PDO::FETCH_ASSOC);
$methodLabels = [
    'cash' => 'Cash', 'bank_transfer' => 'Bank Transfer',
    'card' => 'Card', 'online' => 'Online', 'other' => 'Other',
];

$stmt = $conn->prepare("
    SELECT p.*, b.booking_number, b.status AS booking_status, g.first_name, g.last_name
    FROM ars_booking_payments p
    JOIN ars_bookings b ON b.id = p.booking_id
    LEFT JOIN ars_guests g ON g.id = b.guest_id
    WHERE p.company_id = ?
      AND p.payment_gateway = 'stripe'
      AND p.payment_date BETWEEN ? AND ?
      AND (
        p.gateway_status IN ('requires_payment_method','failed','canceled','partially_refunded','refunded')
        OR COALESCE(p.amount_refunded, 0) > 0
      )
    ORDER BY p.created_at DESC
    LIMIT 50
");
$stmt->execute([$arsCompanyId, $dateFrom, $dateTo]);
$stripeExceptionPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Occupancy ---
[$unitWhere, $unitParams] = ars_short_term_units_where($arsCompanyId, 'u');
$ucStmt = $conn->prepare("SELECT COUNT(*) FROM re_units u WHERE {$unitWhere}");
$ucStmt->execute($unitParams);
$arsUnitCount = (int)$ucStmt->fetchColumn();
$daysInRange = max(1, (int)((new DateTime($dateTo))->diff(new DateTime($dateFrom))->days) + 1);
$availableNights = $arsUnitCount * $daysInRange;
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(b.nights), 0)
    FROM ars_bookings b
    WHERE b.company_id = ? AND b.status NOT IN ('cancelled','expired','pending')
      AND b.check_in <= ? AND b.check_out >= ?
");
$stmt->execute([$arsCompanyId, $dateTo, $dateFrom]);
$bookedNights = (int)$stmt->fetchColumn();
$occupancyPct = $availableNights > 0 ? round(($bookedNights / $availableNights) * 100, 1) : 0;

// --- Discount Breakdown ---
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN discount_type = 'promo' THEN 1 ELSE 0 END) AS promo_count,
        SUM(CASE WHEN discount_type = 'promo' THEN discount_amount ELSE 0 END) AS promo_total,
        SUM(CASE WHEN discount_type = 'manual' THEN 1 ELSE 0 END) AS manual_count,
        SUM(CASE WHEN discount_type = 'manual' THEN discount_amount ELSE 0 END) AS manual_total,
        SUM(CASE WHEN length_discount_amount > 0 THEN 1 ELSE 0 END) AS length_count,
        SUM(length_discount_amount) AS length_total
    FROM ars_bookings b
    WHERE $whereSQL
");
$stmt->execute($bParams);
$discBreakdown = $stmt->fetch(PDO::FETCH_ASSOC);

// --- Detailed Bookings Table ---
$stmt = $conn->prepare("
    SELECT b.*, g.first_name, g.last_name, u.unit_number, bl.name AS building
    FROM ars_bookings b
    LEFT JOIN ars_guests g ON g.id = b.guest_id
    LEFT JOIN re_units u ON u.id = b.unit_id
    LEFT JOIN re_buildings bl ON bl.id = u.building_id
    WHERE $whereSQL
    ORDER BY b.check_in DESC
");
$stmt->execute($bParams);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Units dropdown for filter ---
$units = ars_fetch_short_term_units($conn, $arsCompanyId);

// --- CSV Export ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ars_revenue_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Booking #','Guest','Unit','Building','Check-in','Check-out','Nights',
                    'Subtotal','Length Discount','Promo/Manual Discount','Extras','VAT','Total','Paid','Balance','Status']);
    foreach ($bookings as $b) {
        fputcsv($out, [
            $b['booking_number'],
            ($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''),
            $b['unit_number'] ?? '',
            $b['building'] ?? '',
            $b['check_in'],
            $b['check_out'],
            $b['nights'],
            number_format((float)$b['subtotal'], 2, '.', ''),
            number_format((float)$b['length_discount_amount'], 2, '.', ''),
            number_format((float)$b['discount_amount'], 2, '.', ''),
            number_format((float)$b['extras_total'], 2, '.', ''),
            number_format((float)$b['vat_amount'], 2, '.', ''),
            number_format((float)$b['total_amount'], 2, '.', ''),
            number_format((float)$b['paid_amount'], 2, '.', ''),
            number_format((float)$b['balance_due'], 2, '.', ''),
            $b['status'],
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Revenue';
ars_shell_begin([
    'title' => 'Revenue',
    'subtitle' => 'Company: ' . ($arsCompany['name'] ?? ('#' . $arsCompanyId))
        . ' · Currency: ' . $currency
        . ' · Booking status is operational; accounting status is shown in Finance',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Reports', 'href' => 'reports.php'],
        ['label' => 'Revenue'],
    ],
    'actions_html' => '<div class="d-flex gap-2 no-print flex-wrap">'
        . '<a href="?' . h(http_build_query(array_merge($_GET, ['export' => 'csv']))) . '" class="btn btn-ars-outline btn-sm"><i class="bi bi-download me-1"></i>CSV</a>'
        . '<button onclick="window.print()" class="btn btn-ars-outline btn-sm"><i class="bi bi-printer me-1"></i>Print</button>'
        . '</div>',
    'legacy_bootstrap' => true,
]);
$fmt = fn($v) => $currency . ' ' . number_format((float)$v, 2);
?>


<!-- Filters -->
<div class="ars-card mb-4 no-print">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-sm-4 col-lg-2">
                <label class="form-label fw-semibold small mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <label class="form-label fw-semibold small mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <label class="form-label fw-semibold small mb-1">Date Type</label>
                <select name="date_type" class="form-select form-select-sm">
                    <option value="checkin" <?= $dateType === 'checkin' ? 'selected' : '' ?>>By Check-in</option>
                    <option value="payment" <?= $dateType === 'payment' ? 'selected' : '' ?>>By Payment Date</option>
                </select>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <label class="form-label fw-semibold small mb-1">Unit</label>
                <select name="unit_id" class="form-select form-select-sm">
                    <option value="">All Units</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filterUnit == $u['id'] ? 'selected' : '' ?>><?= h($u['unit_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <label class="form-label fw-semibold small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($validStatuses as $s): ?>
                        <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <button type="submit" class="btn btn-ars btn-sm w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
            </div>
        </form>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="ars-stat-card success">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="ars-stat-value"><?= $fmt($kpi['total_revenue']) ?></div>
                    <div class="ars-stat-label">Total Revenue</div>
                </div>
                <div class="ars-stat-icon success"><i class="bi bi-currency-dollar"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="ars-stat-card primary">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="ars-stat-value"><?= $fmt($paymentsReceived) ?></div>
                    <div class="ars-stat-label">Payments Received</div>
                </div>
                <div class="ars-stat-icon primary"><i class="bi bi-cash-stack"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="ars-stat-card coral">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="ars-stat-value"><?= $fmt($kpi['total_outstanding']) ?></div>
                    <div class="ars-stat-label">Outstanding</div>
                </div>
                <div class="ars-stat-icon coral"><i class="bi bi-exclamation-triangle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="ars-stat-card info">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="ars-stat-value"><?= $fmt($kpi['total_vat']) ?></div>
                    <div class="ars-stat-label">VAT Collected</div>
                </div>
                <div class="ars-stat-icon info"><i class="bi bi-receipt"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="ars-stat-card accent">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="ars-stat-value"><?= $fmt($kpi['total_discounts']) ?></div>
                    <div class="ars-stat-label">Total Discounts</div>
                </div>
                <div class="ars-stat-icon accent"><i class="bi bi-tags"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="ars-stat-card primary">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="ars-stat-value"><?= $fmt($kpi['avg_nightly']) ?></div>
                    <div class="ars-stat-label">Avg Nightly Rate</div>
                </div>
                <div class="ars-stat-icon primary"><i class="bi bi-moon-stars"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Trend + Occupancy -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="ars-card h-100">
            <div class="card-header"><i class="bi bi-bar-chart-line me-2"></i>Monthly Revenue Trend</div>
            <div class="card-body">
                <?php if (empty($monthlyTrend)): ?>
                    <div class="text-center text-muted py-4"><i class="bi bi-bar-chart fs-3 d-block mb-2"></i>No revenue data in selected range.</div>
                <?php else: ?>
                    <div class="ars-bar-chart">
                        <?php foreach ($monthlyTrend as $m):
                            $pct = $maxTrend > 0 ? round(((float)$m['revenue'] / $maxTrend) * 100) : 0;
                            $label = date('M Y', strtotime($m['month'] . '-01'));
                        ?>
                        <div class="ars-bar-row">
                            <div class="ars-bar-label"><?= h($label) ?></div>
                            <div class="ars-bar-track">
                                <div class="ars-bar-fill" style="width:<?= $pct ?>%"></div>
                            </div>
                            <div class="ars-bar-value"><?= $fmt($m['revenue']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="ars-card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2"></i>Occupancy</div>
            <div class="card-body text-center">
                <div class="ars-occupancy-ring mb-3">
                    <svg viewBox="0 0 36 36" class="ars-circular-chart">
                        <path class="ars-circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                        <path class="ars-circle-fill" stroke-dasharray="<?= $occupancyPct ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                        <text x="18" y="20.35" class="ars-circle-text"><?= $occupancyPct ?>%</text>
                    </svg>
                </div>
                <div class="row text-center g-2">
                    <div class="col-4">
                        <div class="fw-bold" style="color:var(--ars-primary)"><?= $arsUnitCount ?></div>
                        <small class="text-muted">Units</small>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold" style="color:var(--ars-success)"><?= $bookedNights ?></div>
                        <small class="text-muted">Booked</small>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold" style="color:var(--ars-text-muted)"><?= $availableNights ?></div>
                        <small class="text-muted">Available</small>
                    </div>
                </div>
                <small class="text-muted d-block mt-2"><?= $daysInRange ?> days &times; <?= $arsUnitCount ?> units = <?= $availableNights ?> night-slots</small>
            </div>
        </div>
    </div>
</div>

<!-- Revenue by Unit + Payment Method -->
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="ars-card h-100">
            <div class="card-header"><i class="bi bi-door-open me-2"></i>Revenue by Unit</div>
            <?php if (empty($byUnit)): ?>
                <div class="card-body text-center text-muted py-4">No unit data.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table ars-table ars-mobile-cards mb-0">
                        <thead><tr><th>Unit</th><th>Building</th><th>Bookings</th><th>Nights</th><th>Revenue</th><th>Avg Rate</th></tr></thead>
                        <tbody>
                        <?php foreach ($byUnit as $u): ?>
                            <tr>
                                <td data-label="Unit" class="fw-semibold"><?= h($u['unit_number']) ?></td>
                                <td data-label="Building"><?= h($u['building'] ?: '—') ?></td>
                                <td data-label="Bookings"><?= (int)$u['bookings'] ?></td>
                                <td data-label="Nights"><?= (int)$u['nights'] ?></td>
                                <td data-label="Revenue" class="fw-semibold"><?= $fmt($u['revenue']) ?></td>
                                <td data-label="Avg Rate"><?= $fmt($u['avg_rate']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="ars-card h-100">
            <div class="card-header"><i class="bi bi-credit-card me-2"></i>Revenue by Payment Method</div>
            <?php if (empty($byMethod)): ?>
                <div class="card-body text-center text-muted py-4">No payment data.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table ars-table ars-mobile-cards mb-0">
                        <thead><tr><th>Method</th><th>Count</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($byMethod as $pm): ?>
                            <tr>
                                <td data-label="Method"><i class="bi bi-<?= $pm['payment_method'] === 'cash' ? 'cash-coin' : ($pm['payment_method'] === 'card' ? 'credit-card' : ($pm['payment_method'] === 'bank_transfer' ? 'bank' : 'globe')) ?> me-1"></i><?= h($methodLabels[$pm['payment_method']] ?? ucfirst($pm['payment_method'])) ?></td>
                                <td data-label="Count"><?= (int)$pm['cnt'] ?></td>
                                <td data-label="Total" class="fw-semibold"><?= $fmt($pm['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($stripeExceptionPayments)): ?>
<div class="ars-card mb-4">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Stripe Failed / Refunded Payments</div>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead><tr><th>Booking</th><th>Guest</th><th>Amount</th><th>Refunded</th><th>Status</th><th>Payment Intent</th><th>Failure</th></tr></thead>
            <tbody>
            <?php foreach ($stripeExceptionPayments as $p): ?>
                <tr>
                    <td data-label="Booking"><a href="booking_view.php?id=<?= (int)$p['booking_id'] ?>" class="fw-semibold text-decoration-none"><?= h($p['booking_number']) ?></a></td>
                    <td data-label="Guest"><?= h(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: '—') ?></td>
                    <td data-label="Amount"><?= h($p['currency'] ?: $currency) ?> <?= number_format((float)$p['amount'], 2) ?></td>
                    <td data-label="Refunded"><?= h($p['currency'] ?: $currency) ?> <?= number_format((float)($p['amount_refunded'] ?? 0), 2) ?></td>
                    <td data-label="Status"><span class="badge bg-<?= in_array(($p['gateway_status'] ?? ''), ['refunded','partially_refunded']) ? 'warning text-dark' : 'danger' ?>"><?= h($p['gateway_status'] ?: 'unknown') ?></span></td>
                    <td data-label="Payment Intent"><code class="small"><?= h($p['gateway_payment_intent_id'] ?: '—') ?></code></td>
                    <td data-label="Failure"><?= h($p['failure_message'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Discount Breakdown -->
<div class="ars-card mb-4">
    <div class="card-header"><i class="bi bi-tags me-2"></i>Discount Breakdown</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-sm-4">
                <div class="text-center p-3 rounded" style="background:rgba(34,197,94,0.08)">
                    <div class="fs-5 fw-bold" style="color:var(--ars-success)"><?= $fmt($discBreakdown['length_total'] ?? 0) ?></div>
                    <small class="text-muted">Length-of-Stay Discounts</small>
                    <div class="badge bg-success mt-1"><?= (int)($discBreakdown['length_count'] ?? 0) ?> bookings</div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="text-center p-3 rounded" style="background:rgba(249,115,22,0.08)">
                    <div class="fs-5 fw-bold" style="color:var(--ars-accent)"><?= $fmt($discBreakdown['promo_total'] ?? 0) ?></div>
                    <small class="text-muted">Promo Code Discounts</small>
                    <div class="badge bg-warning text-dark mt-1"><?= (int)($discBreakdown['promo_count'] ?? 0) ?> bookings</div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="text-center p-3 rounded" style="background:rgba(13,148,136,0.08)">
                    <div class="fs-5 fw-bold" style="color:var(--ars-primary)"><?= $fmt($discBreakdown['manual_total'] ?? 0) ?></div>
                    <small class="text-muted">Manual Discounts</small>
                    <div class="badge bg-info mt-1"><?= (int)($discBreakdown['manual_count'] ?? 0) ?> bookings</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Bookings Table -->
<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-table me-2"></i>Booking Details <span class="badge bg-secondary ms-1"><?= count($bookings) ?></span></span>
    </div>
    <?php if (empty($bookings)): ?>
        <div class="card-body text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No bookings found for the selected filters.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ars-table ars-mobile-cards mb-0">
                <thead>
                    <tr>
                        <th>Booking #</th><th>Guest</th><th>Unit</th><th>Check-in</th><th>Check-out</th>
                        <th>Nights</th><th>Subtotal</th><th>Discount</th><th>VAT</th><th>Total</th>
                        <th>Paid</th><th>Balance</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td data-label="Booking #">
                            <a href="booking_view.php?id=<?= $b['id'] ?>" class="fw-semibold text-decoration-none" style="color:var(--ars-primary)"><?= h($b['booking_number']) ?></a>
                        </td>
                        <td data-label="Guest"><?= h(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?></td>
                        <td data-label="Unit"><?= h($b['unit_number'] ?? '') ?></td>
                        <td data-label="Check-in"><?= h($b['check_in']) ?></td>
                        <td data-label="Check-out"><?= h($b['check_out']) ?></td>
                        <td data-label="Nights"><?= (int)$b['nights'] ?></td>
                        <td data-label="Subtotal"><?= $fmt($b['subtotal']) ?></td>
                        <td data-label="Discount">
                            <?php
                            $totalDisc = (float)$b['discount_amount'] + (float)$b['length_discount_amount'];
                            if ($totalDisc > 0):
                            ?>
                                <span class="text-danger">-<?= $fmt($totalDisc) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="VAT"><?= $fmt($b['vat_amount']) ?></td>
                        <td data-label="Total" class="fw-bold"><?= $fmt($b['total_amount']) ?></td>
                        <td data-label="Paid"><?= $fmt($b['paid_amount']) ?></td>
                        <td data-label="Balance">
                            <?php if ((float)$b['balance_due'] > 0): ?>
                                <span class="text-danger fw-semibold"><?= $fmt($b['balance_due']) ?></span>
                            <?php else: ?>
                                <span class="text-success"><?= $fmt(0) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status"><?= arsBookingStatusBadge($b['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold" style="background:var(--ars-bg)">
                        <td colspan="5" class="text-end" data-label="">TOTALS</td>
                        <td data-label="Nights"><?= (int)$kpi['total_nights'] ?></td>
                        <td data-label="Subtotal"><?= $fmt(array_sum(array_column($bookings, 'subtotal'))) ?></td>
                        <td data-label="Discount"><span class="text-danger">-<?= $fmt($kpi['total_discounts']) ?></span></td>
                        <td data-label="VAT"><?= $fmt($kpi['total_vat']) ?></td>
                        <td data-label="Total"><?= $fmt($kpi['total_revenue']) ?></td>
                        <td data-label="Paid"><?= $fmt($kpi['total_paid']) ?></td>
                        <td data-label="Balance"><span class="text-danger"><?= $fmt($kpi['total_outstanding']) ?></span></td>
                        <td data-label=""></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php ars_shell_end(); ?>
