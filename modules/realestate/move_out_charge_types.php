<?php
/**
 * Real Estate Module - Standard Move-Out Charges
 * The charges every move-out usually carries (Cleaning, Painting, Pest
 * Control, ...). Each new move-out gets this list in Damages & Report, where
 * staff enter the amount for that unit; the amounts are deducted from the
 * security deposit. Owner/Admin can edit; others can view.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/move_out_charges_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 1);
$canEdit = has_role('Owner', $conn) || has_role('Admin', $conn);
$ready = rmoc_ready($conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$canEdit) {
        $_SESSION['error'] = 'Only Owner or Admin can change the standard move-out charges.';
        header('Location: move_out_charge_types.php');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    try {
        rmoc_ensure_types($conn, $currentCompanyId);
        switch ($action) {
            case 'add':
                if ($name === '') throw new RuntimeException('Enter the charge name.');
                $conn->prepare("INSERT INTO re_move_out_charge_types (company_id, name, display_order) SELECT ?, ?, COALESCE(MAX(display_order), 0) + 1 FROM re_move_out_charge_types WHERE company_id = ?")
                    ->execute([$currentCompanyId, mb_substr($name, 0, 100), $currentCompanyId]);
                $_SESSION['success'] = 'Charge added. New move-outs will include it.';
                break;
            case 'rename':
                if ($name === '') throw new RuntimeException('Enter the charge name.');
                $conn->prepare("UPDATE re_move_out_charge_types SET name = ? WHERE id = ? AND company_id = ?")->execute([mb_substr($name, 0, 100), $id, $currentCompanyId]);
                $_SESSION['success'] = 'Charge renamed.';
                break;
            case 'toggle':
                $conn->prepare("UPDATE re_move_out_charge_types SET is_active = 1 - is_active WHERE id = ? AND company_id = ?")->execute([$id, $currentCompanyId]);
                $_SESSION['success'] = 'Charge updated.';
                break;
            case 'move':
                $ids = array_map('intval', array_column(rmoc_types($conn, $currentCompanyId, true), 'id'));
                $pos = array_search($id, $ids, true);
                $to = $pos === false ? false : $pos + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
                if ($pos !== false && $to >= 0 && $to < count($ids)) {
                    [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
                    $upd = $conn->prepare("UPDATE re_move_out_charge_types SET display_order = ? WHERE id = ? AND company_id = ?");
                    foreach ($ids as $o => $rid) $upd->execute([$o + 1, $rid, $currentCompanyId]);
                }
                break;
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header('Location: move_out_charge_types.php');
    exit;
}

if ($ready) {
    rmoc_ensure_types($conn, $currentCompanyId);
}
$types = $ready ? rmoc_types($conn, $currentCompanyId, true) : [];

$pageTitle = 'Standard Move-Out Charges';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles = ($pageStyles ?? '') . re_move_ui_css() . '
    .chg-row { display: flex; align-items: center; gap: .5rem; padding: .55rem .9rem; border-top: 1px dashed var(--rm-border); }
    .chg-row:first-child { border-top: 0; }
    .chg-row.inactive input[type=text] { text-decoration: line-through; color: var(--rm-muted); }
    .chg-inline { display: inline; }
';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="rm-page">
    <div class="rm-narrow">
        <a href="move_out.php" class="rm-back"><i class="bi bi-arrow-left"></i> Move-Out Management</a>
        <div class="rm-hero">
            <div>
                <h1><i class="bi bi-brush"></i>Standard Move-Out Charges</h1>
                <div class="rm-sub">Charges every move-out usually needs, such as cleaning, painting and pest control. Each new move-out lists them in Damages &amp; Report, where the amount for that unit is entered; the amounts are deducted from the security deposit.</div>
            </div>
        </div>

        <?php foreach (['success' => 'success', 'error' => 'danger'] as $k => $cls): if (!empty($_SESSION[$k])): ?>
            <div class="alert alert-<?= $cls ?> alert-dismissible fade show"><?= h($_SESSION[$k]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION[$k]); endif; endforeach; ?>

        <?php if (!$ready): ?>
            <div class="alert alert-warning">Run <code>migrations/re_move_out_charges.sql</code> to use standard move-out charges.</div>
        <?php else: ?>
            <?php if (!$canEdit): ?>
                <div class="alert alert-info small"><i class="bi bi-eye"></i> View only. Owner or Admin can change the list.</div>
            <?php endif; ?>
            <div class="card mb-3">
                <div class="card-body p-0">
                    <?php foreach ($types as $t): $active = (int)$t['is_active'] === 1; ?>
                        <div class="chg-row <?= $active ? '' : 'inactive' ?>">
                            <i class="bi bi-brush text-muted"></i>
                            <?php if ($canEdit): ?>
                                <form method="post" class="d-flex gap-1 flex-grow-1">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <input type="text" name="name" class="form-control form-control-sm" value="<?= h($t['name']) ?>" maxlength="100" required>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" title="Save name"><i class="bi bi-check2"></i></button>
                                </form>
                                <?php foreach ([['move', 'up', 'bi-arrow-up', 'Move up'], ['move', 'down', 'bi-arrow-down', 'Move down'], ['toggle', '', $active ? 'bi-eye-slash' : 'bi-eye', $active ? 'Hide (not added to new move-outs)' : 'Show again']] as [$act, $dir, $icon, $title]): ?>
                                    <form method="post" class="chg-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="<?= $act ?>">
                                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                        <?php if ($dir): ?><input type="hidden" name="dir" value="<?= $dir ?>"><?php endif; ?>
                                        <button type="submit" class="btn btn-sm btn-link text-muted px-1" title="<?= h($title) ?>"><i class="bi <?= $icon ?>"></i></button>
                                    </form>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="flex-grow-1"><?= h($t['name']) ?></span>
                            <?php endif; ?>
                            <?php if (!$active): ?><span class="rm-pill rm-pill-secondary">Hidden</span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($canEdit): ?>
                <div class="card">
                    <div class="card-body">
                        <form method="post" class="d-flex gap-2 flex-wrap">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="add">
                            <input type="text" name="name" class="form-control" style="max-width:420px" maxlength="100" placeholder="New standard charge, e.g. AC servicing" required>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle me-1"></i> Add charge</button>
                        </form>
                        <div class="form-text">Changes apply to move-outs whose charges are created afterwards. Hidden charges are not added to new move-outs.</div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
