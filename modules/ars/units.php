<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_unit_history_helper.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);

// Fetch units available for short-term rental (scoped helper; RE inventory sharing supported)
$historyJoin = '';
$historySelect = "NULL AS current_tenant_name, NULL AS current_contract_number";
if (arsHistoryTablesReady($conn)) {
    $historySelect = "CONCAT(o.tenant_first_name, ' ', COALESCE(o.tenant_last_name, '')) AS current_tenant_name, o.contract_number AS current_contract_number";
    $historyJoin = "LEFT JOIN ars_unit_occupancies o ON o.unit_id = u.id AND o.company_id = {$arsCompanyId} AND o.status = 'active'";
}
[$unitWhere, $unitParams] = ars_short_term_units_where($arsCompanyId, 'u');
$stmt = $conn->prepare("
    SELECT u.*, b.name AS building_name,
        (SELECT file_path FROM ars_unit_photos WHERE unit_id = u.id AND is_primary = 1 LIMIT 1) AS primary_photo,
        {$historySelect}
    FROM re_units u
    LEFT JOIN re_buildings b ON b.id = u.building_id
    {$historyJoin}
    WHERE {$unitWhere}
    ORDER BY b.name, u.unit_number
");
$stmt->execute($unitParams);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Units';
ars_shell_begin([
    'title' => 'Units',
    'subtitle' => count($units) . ' unit' . (count($units) !== 1 ? 's' : ''),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Units'],
    ],
    'actions_html' => ars_ui_button('Tenant history search', ['href' => 'unit_history_search.php', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'search']),
    'legacy_bootstrap' => true,
]);
?>


<?php if (empty($units)): ?>
<div class="ars-card">
    <div class="card-body text-center py-5">
        <i class="bi bi-door-closed text-muted" style="font-size:3rem"></i>
        <p class="mt-3 text-muted">No units configured for short-term rental yet.<br>
        Set a unit's <strong>rental_mode</strong> to "short_term" or "both" in the Real Estate module.</p>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($units as $u): ?>
    <div class="col-6 col-sm-6 col-lg-4 col-xl-3">
        <div class="ars-card h-100">
            <div class="ars-unit-photo-thumb">
                <?php if ($u['primary_photo']): ?>
                <img src="../../<?= h($u['primary_photo']) ?>" alt="<?= h($u['listing_title'] ?: $u['unit_number']) ?>">
                <?php else: ?>
                <div class="ars-unit-photo-placeholder"><i class="bi bi-image"></i></div>
                <?php endif; ?>
                <?php if ($u['is_listed']): ?>
                <span class="ars-badge confirmed" style="position:absolute;top:8px;right:8px"><i class="bi bi-eye-fill"></i> Listed</span>
                <?php endif; ?>
            </div>
            <div class="card-body" style="overflow-wrap:break-word;word-break:break-word">
                <h6 class="fw-bold mb-1"><?= h($u['listing_title'] ?: $u['unit_number']) ?></h6>
                <small class="text-muted d-block mb-2"><?= h($u['building_name']) ?> &middot; <?= h($u['unit_type'] ?? '') ?></small>
                <?php if ($u['nightly_rate']): ?>
                <div class="fw-semibold" style="color:var(--ars-primary)">AED <?= number_format((float)$u['nightly_rate'], 2) ?> <small class="text-muted fw-normal">/ night</small></div>
                <?php else: ?>
                <div class="text-muted small">No rate set</div>
                <?php endif; ?>
                <div class="small text-muted mt-1"><i class="bi bi-people-fill me-1"></i>Max <?= (int)$u['max_guests'] ?> guests</div>
                <div class="small mt-2">
                    <strong>Current Tenant:</strong>
                    <?php if (!empty($u['current_tenant_name'])): ?>
                        <?= h($u['current_tenant_name']) ?>
                        <?php if (!empty($u['current_contract_number'])): ?><br><span class="text-muted"><?= h($u['current_contract_number']) ?></span><?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">No manual active tenant</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0">
                <div class="d-grid gap-2">
                    <?= ars_ui_button('Profile / history', ['href' => 'unit_profile.php?id=' . (int)$u['id'], 'size' => 'sm', 'icon' => 'history', 'class' => 'w-full justify-center']) ?>
                    <?= ars_ui_button('Edit unit', ['href' => 'unit_edit.php?id=' . (int)$u['id'], 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'pencil', 'class' => 'w-full justify-center']) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php ars_shell_end(); ?>
