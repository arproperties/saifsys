<?php
/**
 * Building Inventory — the buildings this person keeps, as cards.
 * The list is Reem's: every building for the master, their own for an administrator.
 * Someone with one building goes straight into it.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/binv_helper.php';

require_login(get_application_web_root() . '/login');
$binv = binv_boot($conn);

$binvBase = get_application_web_root() . '/modules/building_inventory';

try {
    $buildings = binv_reem($binv['code'], 'GET');
} catch (BinvError $e) {
    binv_stop($e);
}
if (!$buildings) {
    binv_stop(new BinvError('You do not keep any building\'s inventory. In Reem, the master names the administrator of each building.', 403));
}

// ?all=1 is the sidebar's "Buildings" link: show the list even when it is one card.
if (count($buildings) === 1 && empty($_GET['all'])) {
    header('Location: ' . $binvBase . '/building.php?id=' . (int)$buildings[0]['id']);
    exit;
}

$pageTitle = 'Buildings';
require __DIR__ . '/includes/binv_layout_header.php';
?>

<div class="page-header-label mb-1">Building Inventory</div>
<p class="text-muted mb-4">The things that stay in a place: AC, fridge, furniture, keys. Pick a building.</p>

<div class="row g-3">
  <?php foreach ($buildings as $b): ?>
    <?php $items = (int)($b['items'] ?? 0); $damaged = (int)($b['damaged'] ?? 0); $missing = (int)($b['missing'] ?? 0); ?>
    <div class="col-12 col-md-6 col-xl-4">
      <a class="binv-building" href="<?= h($binvBase) ?>/building.php?id=<?= (int)$b['id'] ?>">
        <div class="card card-round h-100">
          <div class="card-body d-flex gap-3 align-items-start">
            <div class="fs-2" style="color:var(--primary)"><i class="bi bi-building"></i></div>
            <div style="min-width:0">
              <div class="fw-bold fs-5 text-truncate"><?= h($b['name'] ?? '') ?></div>
              <div>
                <?= $items ?> item<?= $items === 1 ? '' : 's' ?>
                <?php if ($damaged > 0): ?> · <span class="text-warning-emphasis fw-semibold"><?= $damaged ?> damaged</span><?php endif; ?>
                <?php if ($missing > 0): ?> · <span class="text-danger fw-semibold"><?= $missing ?> missing</span><?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/binv_layout_footer.php'; ?>
