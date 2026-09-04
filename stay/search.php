<?php
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/../modules/ars/includes/ars_availability.php';
require_once __DIR__ . '/../modules/ars/includes/ars_pricing.php';

$arsCompanyId = getArsCompanyId($conn);
$settings     = getArsSettings($conn, $arsCompanyId);
$currency     = $settings['currency'] ?? 'AED';
$vatRate      = (float)($settings['default_vat_rate'] ?? 5);

$checkIn  = trim($_GET['check_in'] ?? '');
$checkOut = trim($_GET['check_out'] ?? '');
$guests   = max(1, (int)($_GET['guests'] ?? 1));
$building = trim($_GET['building'] ?? '');
$hasSearch = ($checkIn && $checkOut && $checkIn < $checkOut);

$bStmt = $conn->prepare("
    SELECT DISTINCT b.name FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    WHERE u.is_listed = 1 AND u.rental_mode IN ('short_term','both')
    ORDER BY b.name
");
$bStmt->execute();
$buildings = $bStmt->fetchAll(PDO::FETCH_COLUMN);

$results = [];
if ($hasSearch) {
    $sql = "
        SELECT u.id, u.unit_number, u.unit_type, u.area_sqm,
               u.nightly_rate, COALESCE(u.monthly_rate, 0) AS monthly_rate,
               u.listing_title, u.short_description, u.amenities_json,
               u.max_guests, b.name AS building_name,
               (SELECT file_path FROM ars_unit_photos WHERE unit_id = u.id AND is_primary = 1 LIMIT 1) AS primary_photo
        FROM re_units u
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE u.is_listed = 1 AND u.rental_mode IN ('short_term','both')
    ";
    $params = [];

    if ($guests > 0) {
        $sql .= " AND (u.max_guests >= ? OR u.max_guests IS NULL OR u.max_guests = 0)";
        $params[] = $guests;
    }
    if ($building) {
        $sql .= " AND b.name = ?";
        $params[] = $building;
    }

    $sql .= " ORDER BY u.nightly_rate ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nights = max(1, (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400));

    foreach ($candidates as $u) {
        $avail = ars_check_availability($conn, $u['id'], $checkIn, $checkOut);
        if (!$avail['available']) {
            continue;
        }

        $minStayOk = ars_validate_minimum_stay($conn, $arsCompanyId, (int)$u['id'], $checkIn, $checkOut, $nights);
        if ($minStayOk !== null) continue;

        $price = ars_calculate_booking_price_v2(
            $conn, $arsCompanyId, (int)$u['id'],
            (float)$u['nightly_rate'], $nights, $checkIn, $checkOut,
            null, [], $vatRate
        );
        $u['price'] = $price;
        $results[] = $u;
    }
}

$pageTitle = 'Search Availability';
require_once __DIR__ . '/includes/portal_layout_header.php';
?>

<div class="container py-4">
    <h2 class="fw-bold mb-3">Search Available Rentals</h2>

    <!-- Search Form -->
    <div class="search-bar mb-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold mb-1">Check-in *</label>
                <input type="date" name="check_in" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= h($checkIn) ?>" required>
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold mb-1">Check-out *</label>
                <input type="date" name="check_out" class="form-control" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= h($checkOut) ?>" required>
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label small fw-semibold mb-1">Guests</label>
                <input type="number" name="guests" class="form-control" min="1" value="<?= $guests ?>">
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label small fw-semibold mb-1">Building</label>
                <select name="building" class="form-select">
                    <option value="">Any</option>
                    <?php foreach ($buildings as $bName): ?>
                    <option value="<?= h($bName) ?>" <?= $building === $bName ? 'selected' : '' ?>><?= h($bName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-12">
                <button type="submit" class="btn btn-portal w-100"><i class="bi bi-search me-1"></i>Search</button>
            </div>
        </form>
    </div>

    <!-- Results -->
    <?php if ($hasSearch): ?>
    <p class="text-muted mb-3">
        <strong><?= count($results) ?></strong> unit<?= count($results) !== 1 ? 's' : '' ?> available
        for <?= h($checkIn) ?> — <?= h($checkOut) ?> (<?= $nights ?> night<?= $nights > 1 ? 's' : '' ?>)
    </p>

    <?php if (empty($results)): ?>
    <div class="text-center py-5">
        <i class="bi bi-calendar-x text-muted" style="font-size:3rem"></i>
        <p class="text-muted mt-2">No units available for these dates. Try different dates or fewer guests.</p>
    </div>
    <?php else: ?>
    <div class="unit-grid">
        <?php foreach ($results as $u):
            $amenities = json_decode($u['amenities_json'] ?: '[]', true) ?: [];
            $photoUrl  = $u['primary_photo'] ? portal_base_url() . '/../' . $u['primary_photo'] : '';
            $t = $u['listing_title'] ?: $u['building_name'] . ' - Unit ' . $u['unit_number'];
            $p = $u['price'];
        ?>
        <a href="book.php?unit_id=<?= $u['id'] ?>&check_in=<?= urlencode($checkIn) ?>&check_out=<?= urlencode($checkOut) ?>&guests=<?= $guests ?>" class="text-decoration-none">
            <div class="portal-card">
                <?php if ($photoUrl): ?>
                <img src="<?= h($photoUrl) ?>" alt="<?= h($t) ?>" class="unit-card-img" loading="lazy">
                <?php else: ?>
                <div class="unit-card-img d-flex align-items-center justify-content-center bg-light">
                    <i class="bi bi-image text-muted" style="font-size:3rem"></i>
                </div>
                <?php endif; ?>
                <div class="unit-card-body">
                    <div class="unit-card-title"><?= h($t) ?></div>
                    <div class="unit-card-location"><i class="bi bi-geo-alt me-1"></i><?= h($u['building_name']) ?></div>
                    <div class="unit-card-amenities">
                        <?php if ($u['unit_type']): ?><span><i class="bi bi-door-closed"></i> <?= h(strtoupper($u['unit_type'])) ?></span><?php endif; ?>
                        <?php if ($u['max_guests']): ?><span><i class="bi bi-people"></i> <?= (int)$u['max_guests'] ?> guests</span><?php endif; ?>
                    </div>
                    <?php
                    $stayTotal = (float)($p['total_amount'] ?? 0);
                    $avgIncl   = $nights > 0 ? round($stayTotal / $nights, 0) : 0;
                    $mrate     = (float)($u['monthly_rate'] ?? 0);
                    $moIncl    = $mrate > 0 ? round($mrate * (1 + $vatRate / 100), 0) : 0;
                    ?>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="unit-card-price">
                            <?= $currency ?> <?= number_format($stayTotal, 0) ?> <small>total incl. VAT</small>
                        </div>
                        <small class="text-muted text-end"><?= $currency ?> <?= number_format($avgIncl, 0) ?>/night avg incl. VAT<?php if ($moIncl > 0 && $nights >= 28): ?><br><span class="small">~<?= $currency ?> <?= number_format($moIncl, 0) ?>/mo incl. VAT</span><?php endif; ?></small>
                    </div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php elseif ($_SERVER['QUERY_STRING']): ?>
    <div class="text-center py-5">
        <p class="text-muted">Please select both check-in and check-out dates to search.</p>
    </div>
    <?php else: ?>
    <div class="text-center py-5">
        <i class="bi bi-search text-muted" style="font-size:3rem"></i>
        <p class="text-muted mt-2">Enter your dates above to find available rentals.</p>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/portal_layout_footer.php'; ?>
