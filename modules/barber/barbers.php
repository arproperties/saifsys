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
require_permission('barber_backoffice.manage_team', MODULE_BARBER, $conn);
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
            $name = trim($_POST['display_name'] ?? '');
            $uid = (int)($_POST['user_id'] ?? 0);
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') {
                throw new RuntimeException('Display name required');
            }
            if ($uid > 0) {
                $chk = $conn->prepare('SELECT 1 FROM user_companies WHERE user_id = ? AND company_id = ?');
                $chk->execute([$uid, $companyId]);
                if (!$chk->fetch()) {
                    throw new RuntimeException('User is not linked to this company.');
                }
                $conn->prepare('UPDATE barber_staff SET user_id = NULL WHERE company_id = ? AND user_id = ?')->execute([$companyId, $uid]);
            }
            $conn->prepare('INSERT INTO barber_staff (company_id, display_name, user_id, sort_order, is_active) VALUES (?,?,?,?,1)')
                ->execute([$companyId, $name, $uid > 0 ? $uid : null, $sort]);
            $ok = 'Barber added.';
        } elseif ($act === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['display_name'] ?? '');
            $uid = (int)($_POST['user_id'] ?? 0);
            $sort = (int)($_POST['sort_order'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($id <= 0 || $name === '') {
                throw new RuntimeException('Invalid');
            }
            if ($uid > 0) {
                $chk = $conn->prepare('SELECT 1 FROM user_companies WHERE user_id = ? AND company_id = ?');
                $chk->execute([$uid, $companyId]);
                if (!$chk->fetch()) {
                    throw new RuntimeException('User is not linked to this company.');
                }
                $conn->prepare('UPDATE barber_staff SET user_id = NULL WHERE company_id = ? AND user_id = ? AND id != ?')->execute([$companyId, $uid, $id]);
            }
            $conn->prepare('UPDATE barber_staff SET display_name=?, user_id=?, sort_order=?, is_active=? WHERE id=? AND company_id=?')
                ->execute([$name, $uid > 0 ? $uid : null, $sort, $active, $id, $companyId]);
            $ok = 'Updated.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$companyUsers = [];
try {
    $st = $conn->prepare('
        SELECT u.id, u.username, u.fullname
        FROM user u
        INNER JOIN user_companies uc ON uc.user_id = u.id AND uc.company_id = ?
        ORDER BY u.username
    ');
    $st->execute([$companyId]);
    $companyUsers = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $companyUsers = [];
}

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = $conn->prepare('SELECT * FROM barber_staff WHERE id=? AND company_id=?');
    $st->execute([$editId, $companyId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
}

$rows = [];
try {
    $st = $conn->prepare('SELECT b.*, u.username FROM barber_staff b LEFT JOIN user u ON u.id = b.user_id WHERE b.company_id=? ORDER BY b.sort_order, b.display_name');
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $err = 'Run barber migration first.';
}

$pageTitle = 'Team';
require_once __DIR__ . '/includes/barber_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Team (barbers)</div>
    <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
</div>
<p class="text-muted">Link a system <strong>user</strong> to a barber so that tablet defaults to them when they log in. Users must belong to this company.</p>
<?php if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="card mb-4"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Name</th><th>Linked user</th><th>Order</th><th>Active</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= h($r['display_name']) ?></td>
    <td><?= $r['username'] ? h($r['username']) : '—' ?></td>
    <td><?= (int)$r['sort_order'] ?></td>
    <td><?= !empty($r['is_active']) ? 'Yes' : 'No' ?></td>
    <td><a class="btn btn-sm btn-outline-primary" href="barbers.php?edit=<?= (int)$r['id'] ?>">Edit</a></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<div class="card"><div class="card-header"><?= $editRow ? 'Edit barber' : 'Add barber' ?></div><div class="card-body">
<form method="post" class="row g-3">
    <?php csrf_field(); ?>
    <input type="hidden" name="form_action" value="<?= $editRow ? 'update' : 'create' ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
    <div class="col-md-6"><label class="form-label">Display name</label>
        <input type="text" name="display_name" class="form-control" required value="<?= h($editRow['display_name'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Sort order</label>
        <input type="number" name="sort_order" class="form-control" value="<?= (int)($editRow['sort_order'] ?? 0) ?>"></div>
    <div class="col-md-6"><label class="form-label">Linked user (optional)</label>
        <select name="user_id" class="form-select">
            <option value="0">— none —</option>
            <?php foreach ($companyUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($editRow['user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                <?= h($u['username']) ?><?= $u['fullname'] ? ' — ' . h($u['fullname']) : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($editRow): ?>
    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="act" <?= !empty($editRow['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="act">Active</label>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-12">
        <button type="submit" class="btn btn-primary"><?= $editRow ? 'Save' : 'Add' ?></button>
        <?php if ($editRow): ?><a href="barbers.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
    </div>
</form>
</div></div>

<?php require_once __DIR__ . '/includes/barber_layout_footer.php';
