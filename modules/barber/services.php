<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';

require_login();
require_module_access($conn, MODULE_BARBER);
require_barber_backoffice_department($conn);
require_permission('barber_backoffice.manage_services', MODULE_BARBER, $conn);
ensure_current_company_supports_module($conn, MODULE_BARBER);

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $act = $_POST['form_action'] ?? '';
        if ($act === 'create') {
            $name = trim($_POST['name'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $sort = (int)($_POST['sort_order'] ?? 0);
            $fav = !empty($_POST['is_favorite']) ? 1 : 0;
            if ($name === '') {
                throw new RuntimeException('Name required');
            }
            $conn->prepare('INSERT INTO barber_services (company_id, name, price, sort_order, is_favorite, is_active) VALUES (?,?,?,?,?,1)')
                ->execute([$companyId, $name, $price, $sort, $fav]);
            $ok = 'Service added.';
        } elseif ($act === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $sort = (int)($_POST['sort_order'] ?? 0);
            $fav = !empty($_POST['is_favorite']) ? 1 : 0;
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($id <= 0 || $name === '') {
                throw new RuntimeException('Invalid');
            }
            $conn->prepare('UPDATE barber_services SET name=?, price=?, sort_order=?, is_favorite=?, is_active=? WHERE id=? AND company_id=?')
                ->execute([$name, $price, $sort, $fav, $active, $id, $companyId]);
            $ok = 'Service updated.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = $conn->prepare('SELECT * FROM barber_services WHERE id=? AND company_id=?');
    $st->execute([$editId, $companyId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
}

$rows = [];
try {
    $st = $conn->prepare('SELECT * FROM barber_services WHERE company_id=? ORDER BY sort_order ASC, name ASC');
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $err = 'Run barber migration first.';
}

$pageTitle = 'Services';
require_once __DIR__ . '/includes/barber_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Services</div>
    <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
</div>
<?php if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="card mb-4"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Order</th><th>Name</th><th class="text-end">Price</th><th>Fav</th><th>Active</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= (int)$r['sort_order'] ?></td>
    <td><?= h($r['name']) ?></td>
    <td class="text-end"><?= number_format((float)$r['price'], 2) ?></td>
    <td><?= !empty($r['is_favorite']) ? '★' : '—' ?></td>
    <td><?= !empty($r['is_active']) ? 'Yes' : 'No' ?></td>
    <td><a class="btn btn-sm btn-outline-primary" href="services.php?edit=<?= (int)$r['id'] ?>">Edit</a></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<div class="card"><div class="card-header"><?= $editRow ? 'Edit service' : 'Add service' ?></div><div class="card-body">
<form method="post" class="row g-3">
    <?php csrf_field(); ?>
    <input type="hidden" name="form_action" value="<?= $editRow ? 'update' : 'create' ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
    <div class="col-md-6"><label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="<?= h($editRow['name'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Price (AED)</label>
        <input type="number" step="0.01" min="0" name="price" class="form-control" required value="<?= h((string)($editRow['price'] ?? '0')) ?>"></div>
    <div class="col-md-3"><label class="form-label">Sort order</label>
        <input type="number" name="sort_order" class="form-control" value="<?= (int)($editRow['sort_order'] ?? 0) ?>"></div>
    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_favorite" value="1" id="fav" <?= !empty($editRow['is_favorite']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="fav">Favorite (for future quick POS)</label>
        </div>
        <?php if ($editRow): ?>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="act" <?= !empty($editRow['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="act">Active</label>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-12">
        <button type="submit" class="btn btn-primary"><?= $editRow ? 'Save' : 'Add' ?></button>
        <?php if ($editRow): ?><a href="services.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
    </div>
</form>
</div></div>

<?php require_once __DIR__ . '/includes/barber_layout_footer.php';
