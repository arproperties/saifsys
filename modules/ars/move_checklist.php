<?php
/**
 * ARS move-in / move-out checklist settings.
 * Edits the template only; inspections already started keep the items they were opened with.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_move_inspection.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$type = ars_move_inspection_type($_REQUEST['type'] ?? 'in');
$isOut = $type === 'out';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    ars_move_inspection_ensure_schema($conn);
    $action = $_POST['action'] ?? '';
    $itemId = (int)($_POST['item_id'] ?? 0);
    $name = mb_substr(trim((string)($_POST['item_name'] ?? '')), 0, 255);
    $desc = mb_substr(trim((string)($_POST['item_description'] ?? '')), 0, 500);
    $required = isset($_POST['is_required']) ? 1 : 0;
    $order = (int)($_POST['display_order'] ?? 0);

    if ($action === 'add' && $name !== '') {
        if ($order <= 0) {
            $max = $conn->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM ars_move_checklist_templates WHERE company_id = ? AND move_type = ?");
            $max->execute([$arsCompanyId, $type]);
            $order = (int)$max->fetchColumn();
        }
        $conn->prepare("
            INSERT INTO ars_move_checklist_templates (company_id, move_type, item_name, item_description, is_required, display_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$arsCompanyId, $type, $name, $desc !== '' ? $desc : null, $required, $order]);
        $_SESSION['success'] = 'Item added.';
    } elseif ($action === 'save' && $itemId > 0) {
        // The current text is shown as a placeholder, so a box left empty means "keep it".
        $conn->prepare("
            UPDATE ars_move_checklist_templates
            SET item_name = COALESCE(?, item_name), item_description = COALESCE(?, item_description),
                is_required = ?, display_order = ?
            WHERE id = ? AND company_id = ?
        ")->execute([$name !== '' ? $name : null, $desc !== '' ? $desc : null, $required, $order, $itemId, $arsCompanyId]);
        $_SESSION['success'] = 'Item saved.';
    } elseif ($action === 'toggle' && $itemId > 0) {
        $conn->prepare("UPDATE ars_move_checklist_templates SET is_active = 1 - is_active WHERE id = ? AND company_id = ?")
            ->execute([$itemId, $arsCompanyId]);
        $_SESSION['success'] = 'Item updated.';
    } else {
        $_SESSION['error'] = 'Enter the item name.';
    }
    header('Location: move_checklist.php?type=' . $type);
    exit;
}

$items = ars_move_inspection_templates($conn, $arsCompanyId, $type, false);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$tabClass = static fn(bool $on): string => 'no-underline rounded-ars-md px-3 py-1.5 text-ars-sm font-semibold '
    . ($on ? 'bg-ars-ink text-white' : 'text-ars-muted hover:text-ars-text');

ars_shell_begin([
    'title' => 'Inspection Checklist',
    'subtitle' => 'Items staff tick at move-in and move-out',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Move-in / Move-out', 'href' => 'move_report.php'],
        ['label' => 'Checklist'],
    ],
    'actions_html' => ars_move_inspection_nav_html('checklist'),
    'legacy_bootstrap' => true,
]);
?>

<div class="mb-3">
    <div class="inline-flex flex-wrap gap-1 rounded-ars-lg border border-ars-border bg-ars-surface p-1">
        <a class="<?= $tabClass(!$isOut) ?>" href="?type=in">Move-in</a>
        <a class="<?= $tabClass($isOut) ?>" href="?type=out">Move-out</a>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success py-2"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>

<style>
    .ars-chk-table input::placeholder { color: #1c1917; opacity: 1; }
    .ars-chk-table tr.text-muted input::placeholder { color: #8a8580; }
    .ars-chk-table input:focus::placeholder { color: #b5b0aa; }
</style>
<p class="small text-muted">To change an item, just type the new text and press Save; leave a box empty to keep it as it is. Changes apply to inspections opened from now on.</p>

<div class="ars-card mb-3">
<div class="table-responsive">
    <table class="table table-sm ars-table ars-chk-table mb-0 align-middle">
        <thead>
            <tr>
                <th style="width:80px">Order</th>
                <th>Item</th>
                <th>Description</th>
                <th class="text-center">Required</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php $formId = 'chk-' . (int)$item['id']; ?>
            <tr class="<?= $item['is_active'] ? '' : 'text-muted' ?>">
                <td><input form="<?= $formId ?>" class="form-control form-control-sm" type="number" name="display_order" value="<?= (int)$item['display_order'] ?>"></td>
                <td><input form="<?= $formId ?>" class="form-control form-control-sm" type="text" name="item_name" maxlength="255" placeholder="<?= h($item['item_name']) ?>"></td>
                <td><input form="<?= $formId ?>" class="form-control form-control-sm" type="text" name="item_description" maxlength="500" placeholder="<?= h($item['item_description'] ?? '') ?>"></td>
                <td class="text-center"><input form="<?= $formId ?>" class="form-check-input" type="checkbox" name="is_required" <?= $item['is_required'] ? 'checked' : '' ?>></td>
                <td><span class="badge <?= $item['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $item['is_active'] ? 'In use' : 'Off' ?></span></td>
                <td class="text-nowrap text-end">
                    <form id="<?= $formId ?>" method="post" class="d-inline">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="type" value="<?= h($type) ?>">
                        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                        <button class="btn btn-sm btn-ars" name="action" value="save">Save</button>
                        <button class="btn btn-sm btn-outline-secondary" name="action" value="toggle" formnovalidate><?= $item['is_active'] ? 'Turn off' : 'Turn on' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div>

<div class="ars-card">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Add item</div>
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <?php csrf_field(); ?>
            <input type="hidden" name="type" value="<?= h($type) ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Item</label>
                <input class="form-control form-control-sm" type="text" name="item_name" maxlength="255" required>
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-semibold mb-1">Description</label>
                <input class="form-control form-control-sm" type="text" name="item_description" maxlength="500">
            </div>
            <div class="col-md-1">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_required" id="newRequired" checked>
                    <label class="form-check-label small" for="newRequired">Required</label>
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-ars w-100">Add</button>
            </div>
        </form>
    </div>
</div>

<?php ars_shell_end(); ?>
