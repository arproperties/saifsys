<?php
require_once __DIR__ . '/includes/portal_auth.php';
require_once __DIR__ . '/../modules/ars/includes/ars_availability.php';
require_once __DIR__ . '/../modules/ars/includes/ars_pricing.php';

$unitId = (int)($_GET['id'] ?? 0);
if (!$unitId) { header('Location: ' . portal_base_url() . '/'); exit; }

$arsCompanyId = getArsCompanyId($conn);
$settings     = getArsSettings($conn, $arsCompanyId);
$currency     = $settings['currency'] ?? 'AED';
$vatRate      = (float)($settings['default_vat_rate'] ?? 5);

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

$photos = $conn->prepare("SELECT * FROM ars_unit_photos WHERE unit_id = ? ORDER BY is_primary DESC, sort_order, id");
$photos->execute([$unitId]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);

$amenities = json_decode($unit['amenities_json'] ?: '[]', true) ?: [];
$title = $unit['listing_title'] ?: $unit['building_name'] . ' - Unit ' . $unit['unit_number'];

$year  = (int)($_GET['cal_year']  ?? date('Y'));
$month = (int)($_GET['cal_month'] ?? date('n'));
$calMap = ars_unit_month_availability($conn, $unitId, $year, $month);

$pageTitle = $title;
$_baseUrl  = portal_base_url();
require_once __DIR__ . '/includes/portal_layout_header.php';
?>

<div class="container py-4">
    <div class="row g-4">
        <!-- Left: Photos + Details -->
        <div class="col-lg-8">
            <!-- Photo Gallery -->
            <?php if (count($photos) > 0): ?>
            <div class="unit-gallery mb-4" id="unitCarousel">
                <div id="photoCarousel" class="carousel slide" data-bs-ride="false">
                    <div class="carousel-inner">
                        <?php foreach ($photos as $i => $ph): ?>
                        <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
                            <img src="<?= h(portal_base_url() . '/../' . $ph['file_path']) ?>" alt="Photo <?= $i+1 ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($photos) > 1): ?>
                    <button class="carousel-control-prev" type="button" data-bs-target="#photoCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon"></span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#photoCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon"></span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="unit-gallery mb-4 bg-light d-flex align-items-center justify-content-center" style="height:300px;border-radius:var(--portal-card-radius)">
                <i class="bi bi-camera text-muted" style="font-size:4rem"></i>
            </div>
            <?php endif; ?>

            <!-- Title & Location -->
            <h1 class="fw-bold mb-1"><?= h($title) ?></h1>
            <p class="text-muted mb-3"><i class="bi bi-geo-alt me-1"></i><?= h($unit['building_name']) ?><?= $unit['building_address'] ? ' — ' . h($unit['building_address']) : '' ?></p>

            <!-- Quick Stats -->
            <div class="d-flex flex-wrap gap-3 mb-4">
                <?php if ($unit['unit_type']): ?><div class="amenity-tag"><i class="bi bi-door-closed"></i><?= h(strtoupper($unit['unit_type'])) ?></div><?php endif; ?>
                <?php if ($unit['area_sqm']): ?><div class="amenity-tag"><i class="bi bi-aspect-ratio"></i><?= number_format((float)$unit['area_sqm']) ?> sqm</div><?php endif; ?>
                <?php if ($unit['max_guests']): ?><div class="amenity-tag"><i class="bi bi-people"></i>Up to <?= (int)$unit['max_guests'] ?> guests</div><?php endif; ?>
            </div>

            <!-- Description -->
            <?php if ($unit['short_description']): ?>
            <div class="mb-4">
                <h5 class="fw-bold">About this place</h5>
                <p class="text-muted"><?= nl2br(h($unit['short_description'])) ?></p>
            </div>
            <?php endif; ?>

            <!-- Amenities -->
            <?php if ($amenities): ?>
            <div class="mb-4">
                <h5 class="fw-bold">Amenities</h5>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($amenities as $am): ?>
                    <div class="amenity-tag"><i class="bi bi-check-circle"></i><?= h($am) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Mini Calendar -->
            <div class="mb-4">
                <h5 class="fw-bold mb-3">Availability</h5>
                <?php
                    $prevMonth = $month - 1; $prevYear = $year;
                    if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
                    $nextMonth = $month + 1; $nextYear = $year;
                    if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
                    $firstDow = (int)date('w', mktime(0, 0, 0, $month, 1, $year));
                    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
                    $today = date('Y-m-d');
                ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <a href="?id=<?= $unitId ?>&cal_year=<?= $prevYear ?>&cal_month=<?= $prevMonth ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
                    <strong><?= date('F Y', mktime(0, 0, 0, $month, 1, $year)) ?></strong>
                    <a href="?id=<?= $unitId ?>&cal_year=<?= $nextYear ?>&cal_month=<?= $nextMonth ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
                </div>
                <table class="table table-sm mini-cal text-center mb-0">
                    <thead><tr><th>Su</th><th>Mo</th><th>Tu</th><th>We</th><th>Th</th><th>Fr</th><th>Sa</th></tr></thead>
                    <tbody>
                    <?php
                    $cell = 0;
                    echo '<tr>';
                    for ($i = 0; $i < $firstDow; $i++) { echo '<td></td>'; $cell++; }
                    for ($d = 1; $d <= $daysInMonth; $d++) {
                        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $status = $calMap[$dateStr] ?? 'available';
                        $cls = 'cal-' . ($status === 'available' ? 'available' : ($status === 'blocked' || $status === 'maintenance' ? 'blocked' : 'booked'));
                        if ($dateStr === $today) $cls .= ' cal-today';
                        echo '<td class="' . $cls . '">' . $d . '</td>';
                        $cell++;
                        if ($cell % 7 === 0 && $d < $daysInMonth) echo '</tr><tr>';
                    }
                    while ($cell % 7 !== 0) { echo '<td></td>'; $cell++; }
                    echo '</tr>';
                    ?>
                    </tbody>
                </table>
                <div class="d-flex gap-3 mt-2" style="font-size:.75rem">
                    <span><span class="d-inline-block rounded" style="width:12px;height:12px;background:#E8F5E9"></span> Available</span>
                    <span><span class="d-inline-block rounded" style="width:12px;height:12px;background:#FFEBEE"></span> Booked</span>
                    <span><span class="d-inline-block rounded" style="width:12px;height:12px;background:#ECEFF1"></span> Unavailable</span>
                </div>
            </div>
        </div>

        <!-- Right: Pricing Card (sticky on desktop) -->
        <div class="col-lg-4">
            <div class="pricing-sticky">
                <div class="pricing-card">
                    <?php
                    $nightlyEx  = (float) $unit['nightly_rate'];
                    $nightlyIncl = round($nightlyEx * (1 + $vatRate / 100), 0);
                    $monthlyEx   = (float) ($unit['monthly_rate'] ?? 0);
                    $monthlyIncl = $monthlyEx > 0 ? round($monthlyEx * (1 + $vatRate / 100), 0) : 0;
                    ?>
                    <div class="price-big mb-3"><?= $currency ?> <?= number_format($nightlyIncl, 0) ?> <small>/ night incl. VAT</small></div>
                    <?php if ($monthlyIncl > 0): ?>
                    <p class="small text-muted mb-2">Long stays: from <?= $currency ?> <?= number_format($monthlyIncl, 0) ?>/month incl. VAT (package pricing)</p>
                    <?php endif; ?>

                    <form id="quickBookForm" action="book.php" method="get">
                        <input type="hidden" name="unit_id" value="<?= $unitId ?>">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Check-in</label>
                            <input type="date" name="check_in" id="qbCheckIn" class="form-control" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Check-out</label>
                            <input type="date" name="check_out" id="qbCheckOut" class="form-control" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Guests</label>
                            <select name="guests" class="form-select">
                                <?php for ($g = 1; $g <= max(1, (int)$unit['max_guests']); $g++): ?>
                                <option value="<?= $g ?>"><?= $g ?> guest<?= $g > 1 ? 's' : '' ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div id="pricingPreview" class="d-none mb-3 p-3 rounded" style="background:#f9f9f9"></div>

                        <button type="submit" class="btn btn-portal w-100 btn-lg" id="bookBtn">
                            <i class="bi bi-calendar-check me-1"></i>Book Now
                        </button>
                    </form>

                    <p class="text-muted text-center mt-2 mb-0" style="font-size:.8rem">You won't be charged yet</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<JS
<script>
(function(){
    const checkIn = document.getElementById('qbCheckIn');
    const checkOut = document.getElementById('qbCheckOut');
    const preview = document.getElementById('pricingPreview');
    const baseUrl = '{$_baseUrl}';
    let debounce;

    function updatePreview() {
        const ci = checkIn.value, co = checkOut.value;
        if (!ci || !co || ci >= co) { preview.classList.add('d-none'); return; }
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            fetch(baseUrl + '/ajax_check_availability.php?unit_id={$unitId}&check_in=' + ci + '&check_out=' + co)
            .then(r => r.json())
            .then(d => {
                if (!d.available) {
                    preview.innerHTML = '<div class="text-danger"><i class="bi bi-x-circle me-1"></i>Not available for these dates</div>';
                } else {
                    let html = '<div class="d-flex justify-content-between mb-1"><span>Avg / night incl. VAT</span><span>{$currency} ' + (d.avg_nightly_incl || d.effective_nightly) + '</span></div>';
                    html += '<div class="d-flex justify-content-between mb-1"><span>Room subtotal (ex VAT)</span><span>{$currency} ' + d.subtotal + '</span></div>';
                    if (d.vat > 0) html += '<div class="d-flex justify-content-between mb-1 text-muted small"><span>VAT</span><span>{$currency} ' + d.vat + '</span></div>';
                    html += '<hr class="my-1"><div class="d-flex justify-content-between fw-bold"><span>Total incl. VAT</span><span>{$currency} ' + d.total + '</span></div>';
                    preview.innerHTML = html;
                }
                preview.classList.remove('d-none');
            }).catch(() => {});
        }, 400);
    }

    checkIn.addEventListener('change', function() {
        if (this.value) {
            const next = new Date(this.value);
            next.setDate(next.getDate() + 1);
            checkOut.min = next.toISOString().split('T')[0];
            if (checkOut.value && checkOut.value <= this.value) checkOut.value = '';
        }
        updatePreview();
    });
    checkOut.addEventListener('change', updatePreview);
})();
</script>
JS;
require_once __DIR__ . '/includes/portal_layout_footer.php';
?>
