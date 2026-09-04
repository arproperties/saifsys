<?php
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/../modules/ars/includes/ars_availability.php';

$arsCompanyId = getArsCompanyId($conn);
$settings     = getArsSettings($conn, $arsCompanyId);

$buildingFilter = trim($_GET['building'] ?? '');

$sql = "
    SELECT u.id, u.unit_number, u.unit_type, u.area_sqm,
           u.nightly_rate, COALESCE(u.monthly_rate, 0) AS monthly_rate,
           u.listing_title, u.short_description, u.amenities_json,
           u.max_guests, u.rental_mode,
           b.name AS building_name, b.address AS building_address,
           (SELECT file_path FROM ars_unit_photos WHERE unit_id = u.id AND is_primary = 1 LIMIT 1) AS primary_photo
    FROM re_units u
    LEFT JOIN re_buildings b ON b.id = u.building_id
    WHERE u.is_listed = 1
      AND u.rental_mode IN ('short_term','both')
";
$params = [];

if ($buildingFilter) {
    $sql .= " AND b.name = ?";
    $params[] = $buildingFilter;
}

$sql .= " ORDER BY b.name, u.unit_number";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bStmt = $conn->prepare("
    SELECT DISTINCT b.name FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    WHERE u.is_listed = 1 AND u.rental_mode IN ('short_term','both')
    ORDER BY b.name
");
$bStmt->execute();
$buildings = $bStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Browse Rentals';
require_once __DIR__ . '/includes/portal_layout_header.php';

$currency = $settings['currency'] ?? 'AED';
$vatRate  = (float) ($settings['default_vat_rate'] ?? 5);
?>

<div class="container py-4">
    <!-- Hero -->
    <div class="text-center mb-4">
        <h1 class="fw-bold mb-2">Find Your Perfect Stay</h1>
        <p class="text-muted">Short-term furnished apartments in Dubai</p>
    </div>

    <!-- Quick Filters -->
    <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center">
        <a href="?" class="btn <?= !$buildingFilter ? 'btn-portal' : 'btn-portal-outline' ?> btn-sm">All</a>
        <?php foreach ($buildings as $bName): ?>
        <a href="?building=<?= urlencode($bName) ?>" class="btn <?= $buildingFilter === $bName ? 'btn-portal' : 'btn-portal-outline' ?> btn-sm">
            <?= h($bName) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Search CTA -->
    <div class="search-bar mb-4">
        <form action="search.php" method="get" class="row g-2 align-items-end">
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold mb-1">Check-in</label>
                <input type="date" name="check_in" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold mb-1">Check-out</label>
                <input type="date" name="check_out" class="form-control" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label small fw-semibold mb-1">Guests</label>
                <input type="number" name="guests" class="form-control" min="1" value="1">
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label small fw-semibold mb-1">Building</label>
                <select name="building" class="form-select">
                    <option value="">Any</option>
                    <?php foreach ($buildings as $bName): ?>
                    <option value="<?= h($bName) ?>"><?= h($bName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-12">
                <button type="submit" class="btn btn-portal w-100"><i class="bi bi-search me-1"></i>Search</button>
            </div>
        </form>
    </div>

    <!-- Unit Grid -->
    <?php if (empty($units)): ?>
    <div class="text-center py-5">
        <i class="bi bi-house-slash text-muted" style="font-size:3rem"></i>
        <p class="text-muted mt-2">No rentals available at the moment.</p>
    </div>
    <?php else: ?>
    <div class="unit-grid">
        <?php foreach ($units as $u):
            $amenities = json_decode($u['amenities_json'] ?: '[]', true) ?: [];
            $photoPath = $u['primary_photo'] ?: '';
            $photoUrl  = $photoPath ? portal_base_url() . '/../' . $photoPath : '';
            $title     = $u['listing_title'] ?: $u['building_name'] . ' - Unit ' . $u['unit_number'];
            $nIncl     = round((float) $u['nightly_rate'] * (1 + $vatRate / 100), 0);
            $mIncl     = ((float)($u['monthly_rate'] ?? 0)) > 0
                ? round((float) $u['monthly_rate'] * (1 + $vatRate / 100), 0) : 0;
        ?>
        <a href="unit.php?id=<?= $u['id'] ?>" class="text-decoration-none">
            <div class="portal-card">
                <?php if ($photoUrl): ?>
                <img src="<?= h($photoUrl) ?>" alt="<?= h($title) ?>" class="unit-card-img" loading="lazy">
                <?php else: ?>
                <div class="unit-card-img d-flex align-items-center justify-content-center bg-light">
                    <i class="bi bi-image text-muted" style="font-size:3rem"></i>
                </div>
                <?php endif; ?>
                <div class="unit-card-body">
                    <div class="unit-card-title"><?= h($title) ?></div>
                    <div class="unit-card-location"><i class="bi bi-geo-alt me-1"></i><?= h($u['building_name']) ?></div>
                    <div class="unit-card-amenities">
                        <?php if ($u['unit_type']): ?><span><i class="bi bi-door-closed"></i> <?= h(strtoupper($u['unit_type'])) ?></span><?php endif; ?>
                        <?php if ($u['max_guests']): ?><span><i class="bi bi-people"></i> <?= (int)$u['max_guests'] ?> guests</span><?php endif; ?>
                        <?php foreach (array_slice($amenities, 0, 3) as $am): ?>
                        <span><?= h($am) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="unit-card-price">
                        <?= $currency ?> <?= number_format($nIncl, 0) ?> <small>/ night incl. VAT</small>
                        <?php if ($mIncl > 0): ?>
                        <div class="small text-muted fw-normal"><?= $currency ?> <?= number_format($mIncl, 0) ?>/mo incl. VAT</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/portal_layout_footer.php'; ?>
