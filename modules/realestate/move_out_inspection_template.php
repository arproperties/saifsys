<?php
/**
 * Real Estate Module - Move-Out Inspection Template
 * Sections and checklist items copied into the final inspection of every
 * move-out (separate from the move-in template, started from a copy of it).
 * Owner/Admin can edit; others can view. Changes apply to move-outs whose
 * checklist is created afterwards, never to existing ones.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/move_out_inspection_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 1);
$canEdit = has_role('Owner', $conn) || has_role('Admin', $conn);
$ready = rmoi_ready($conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$canEdit) {
        $_SESSION['error'] = 'Only Owner or Admin can change the inspection template.';
        header('Location: move_out_inspection_template.php');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $sid = (int)($_POST['section_id'] ?? 0);
    $iid = (int)($_POST['item_id'] ?? 0);
    $text = trim((string)($_POST['text'] ?? ''));
    $anchor = '';
    try {
        rmoi_ensure_template($conn, $currentCompanyId);
        switch ($action) {
            case 'add_section':
                if ($text === '') throw new RuntimeException('Enter a section name.');
                $conn->prepare("INSERT INTO re_move_out_inspection_sections (company_id, name, display_order) SELECT ?, ?, COALESCE(MAX(display_order), 0) + 1 FROM re_move_out_inspection_sections WHERE company_id = ?")
                    ->execute([$currentCompanyId, mb_substr($text, 0, 150), $currentCompanyId]);
                $anchor = '#sec' . $conn->lastInsertId();
                $_SESSION['success'] = 'Section added.';
                break;
            case 'rename_section':
                if ($text === '') throw new RuntimeException('Enter a section name.');
                $conn->prepare("UPDATE re_move_out_inspection_sections SET name = ? WHERE id = ? AND company_id = ?")->execute([mb_substr($text, 0, 150), $sid, $currentCompanyId]);
                $anchor = '#sec' . $sid;
                $_SESSION['success'] = 'Section renamed.';
                break;
            case 'toggle_section':
                $wt = $conn->prepare("SELECT is_walkthrough FROM re_move_out_inspection_sections WHERE id = ? AND company_id = ?");
                $wt->execute([$sid, $currentCompanyId]);
                if ((int)$wt->fetchColumn() === 1) throw new RuntimeException('The walkthrough section cannot be removed. You can rename it and change its items.');
                $conn->prepare("UPDATE re_move_out_inspection_sections SET is_active = 1 - is_active WHERE id = ? AND company_id = ?")->execute([$sid, $currentCompanyId]);
                $anchor = '#sec' . $sid;
                $_SESSION['success'] = 'Section updated.';
                break;
            case 'move_section':
            case 'move_item':
                $isSection = $action === 'move_section';
                $table = $isSection ? 're_move_out_inspection_sections' : 're_move_out_inspection_template_items';
                $id = $isSection ? $sid : $iid;
                $scope = $isSection ? 'company_id = ?' : 'section_id = (SELECT section_id FROM (SELECT section_id FROM re_move_out_inspection_template_items WHERE id = ?) x)';
                $scopeArg = $isSection ? $currentCompanyId : $iid;
                $rows = $conn->prepare("SELECT id FROM {$table} WHERE {$scope} AND company_id = ? ORDER BY display_order, id");
                $rows->execute([$scopeArg, $currentCompanyId]);
                $ids = array_map('intval', $rows->fetchAll(PDO::FETCH_COLUMN));
                $pos = array_search($id, $ids, true);
                $to = $pos === false ? false : $pos + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
                if ($pos !== false && $to >= 0 && $to < count($ids)) {
                    [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
                    $upd = $conn->prepare("UPDATE {$table} SET display_order = ? WHERE id = ? AND company_id = ?");
                    foreach ($ids as $o => $rid) $upd->execute([$o + 1, $rid, $currentCompanyId]);
                }
                $anchor = '#sec' . ($isSection ? $sid : (int)($_POST['back_section'] ?? 0));
                break;
            case 'add_item':
                if ($text === '') throw new RuntimeException('Enter the checklist item.');
                $chk = $conn->prepare("SELECT id FROM re_move_out_inspection_sections WHERE id = ? AND company_id = ?");
                $chk->execute([$sid, $currentCompanyId]);
                if (!$chk->fetch()) throw new RuntimeException('Section not found.');
                $conn->prepare("INSERT INTO re_move_out_inspection_template_items (company_id, section_id, item_text, display_order) SELECT ?, ?, ?, COALESCE(MAX(display_order), 0) + 1 FROM re_move_out_inspection_template_items WHERE section_id = ?")
                    ->execute([$currentCompanyId, $sid, mb_substr($text, 0, 255), $sid]);
                $anchor = '#sec' . $sid;
                $_SESSION['success'] = 'Item added.';
                break;
            case 'edit_item':
                if ($text === '') throw new RuntimeException('Enter the checklist item.');
                $conn->prepare("UPDATE re_move_out_inspection_template_items SET item_text = ? WHERE id = ? AND company_id = ?")->execute([mb_substr($text, 0, 255), $iid, $currentCompanyId]);
                $anchor = '#sec' . (int)($_POST['back_section'] ?? 0);
                $_SESSION['success'] = 'Item updated.';
                break;
            case 'toggle_item':
                $conn->prepare("UPDATE re_move_out_inspection_template_items SET is_active = 1 - is_active WHERE id = ? AND company_id = ?")->execute([$iid, $currentCompanyId]);
                $anchor = '#sec' . (int)($_POST['back_section'] ?? 0);
                $_SESSION['success'] = 'Item updated.';
                break;
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header('Location: move_out_inspection_template.php' . $anchor);
    exit;
}

if ($ready) {
    rmoi_ensure_template($conn, $currentCompanyId);
}
$sections = $ready ? rmoi_template($conn, $currentCompanyId, true) : [];

$pageTitle = 'Move-Out Inspection Template';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles = ($pageStyles ?? '') . re_move_ui_css() . '
    .tpl-sec { border: 1px solid var(--rm-border); border-radius: 14px; background: var(--rm-surface); margin-bottom: .85rem; scroll-margin-top: 1rem; }
    .tpl-sec.inactive { opacity: .6; }
    .tpl-sec-h { display: flex; align-items: center; gap: .5rem; padding: .7rem .9rem; border-bottom: 1px solid var(--rm-border); background: var(--rm-surface-2); border-radius: 14px 14px 0 0; flex-wrap: wrap; }
    .tpl-item { display: flex; align-items: center; gap: .5rem; padding: .45rem .9rem; border-top: 1px dashed var(--rm-border); }
    .tpl-item:first-child { border-top: 0; }
    .tpl-item.inactive .tpl-text { text-decoration: line-through; color: var(--rm-muted); }
    .tpl-text { flex: 1; min-width: 0; }
    .tpl-btns { display: flex; gap: .15rem; }
    .tpl-btns .btn { padding: .15rem .4rem; }
    .tpl-inline { display: inline; }
';
require_once __DIR__ . '/includes/re_layout_header.php';

$postBtn = static function (string $action, array $fields, string $icon, string $title, string $cls = 'btn-link text-muted') {
    $html = '<form method="post" class="tpl-inline">' . csrf_field_html() . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $k => $v) $html .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return $html . '<button type="submit" class="btn btn-sm ' . $cls . '" title="' . h($title) . '"><i class="bi ' . h($icon) . '"></i></button></form>';
};
function csrf_field_html(): string { ob_start(); csrf_field(); return ob_get_clean(); }
?>
<div class="rm-page">
    <div class="rm-narrow">
        <a href="move_out.php" class="rm-back"><i class="bi bi-arrow-left"></i> Move-Out Management</a>
        <div class="rm-hero">
            <div>
                <h1><i class="bi bi-ui-checks"></i>Move-Out Inspection Template</h1>
                <div class="rm-sub">The sections and checklist items copied into the final inspection of every move-out, where each item is marked OK, Damaged or N/A. Changes apply to move-outs from now on.</div>
            </div>
        </div>

        <?php foreach (['success' => 'success', 'error' => 'danger'] as $k => $cls): if (!empty($_SESSION[$k])): ?>
            <div class="alert alert-<?= $cls ?> alert-dismissible fade show"><?= h($_SESSION[$k]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION[$k]); endif; endforeach; ?>

        <?php if (!$ready): ?>
            <div class="alert alert-warning">Run <code>migrations/re_move_in_stages.sql</code> and <code>migrations/re_move_out_inspection.sql</code> to use the move-out inspection template.</div>
        <?php else: ?>
            <?php if (!$canEdit): ?>
                <div class="alert alert-info small"><i class="bi bi-eye"></i> View only. Owner or Admin can change the template.</div>
            <?php endif; ?>



            <?php foreach ($sections as $sx => $sec): $secActive = (int)$sec['is_active'] === 1; ?>
                <div class="tpl-sec <?= $secActive ? '' : 'inactive' ?>" id="sec<?= (int)$sec['id'] ?>">
                    <div class="tpl-sec-h">
                        <span class="rm-pill rm-pill-secondary"><?= $sx + 1 ?></span>
                        <?php if ($canEdit): ?>
                            <form method="post" class="d-flex gap-1 flex-grow-1" style="min-width:200px">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="rename_section">
                                <input type="hidden" name="section_id" value="<?= (int)$sec['id'] ?>">
                                <input type="text" name="text" class="form-control form-control-sm fw-semibold" value="<?= h($sec['name']) ?>" maxlength="150" required>
                                <button class="btn btn-sm btn-outline-secondary" type="submit" title="Save name"><i class="bi bi-check2"></i></button>
                            </form>
                            <div class="tpl-btns">
                                <?= $postBtn('move_section', ['section_id' => $sec['id'], 'dir' => 'up'], 'bi-arrow-up', 'Move up') ?>
                                <?= $postBtn('move_section', ['section_id' => $sec['id'], 'dir' => 'down'], 'bi-arrow-down', 'Move down') ?>
                                <?php if (empty($sec['is_walkthrough'])): ?>
                                    <?= $postBtn('toggle_section', ['section_id' => $sec['id']], $secActive ? 'bi-eye-slash' : 'bi-eye', $secActive ? 'Hide section (not used for new move-outs)' : 'Show section again') ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <strong class="flex-grow-1"><?= h($sec['name']) ?></strong>
                        <?php endif; ?>
                        <?php if (!$secActive): ?><span class="rm-pill rm-pill-secondary">Hidden</span><?php endif; ?>
                        <?php if (!empty($sec['is_walkthrough'])): ?><span class="rm-pill rm-pill-info" title="Always used. Its items are marked OK or N/A only (no Damaged).">Walkthrough · OK / N/A only</span><?php endif; ?>
                    </div>
                    <div>
                        <?php foreach ($sec['items'] as $it): $itActive = (int)$it['is_active'] === 1; ?>
                            <div class="tpl-item <?= $itActive ? '' : 'inactive' ?>">
                                <i class="bi bi-check2-square text-muted"></i>
                                <?php if ($canEdit): ?>
                                    <form method="post" class="tpl-text d-flex gap-1">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="edit_item">
                                        <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                        <input type="hidden" name="back_section" value="<?= (int)$sec['id'] ?>">
                                        <input type="text" name="text" class="form-control form-control-sm border-0 bg-transparent" value="<?= h($it['item_text']) ?>" maxlength="255" required onchange="this.form.requestSubmit()">
                                    </form>
                                    <div class="tpl-btns">
                                        <?= $postBtn('move_item', ['item_id' => $it['id'], 'dir' => 'up', 'back_section' => $sec['id']], 'bi-arrow-up', 'Move up') ?>
                                        <?= $postBtn('move_item', ['item_id' => $it['id'], 'dir' => 'down', 'back_section' => $sec['id']], 'bi-arrow-down', 'Move down') ?>
                                        <?= $postBtn('toggle_item', ['item_id' => $it['id'], 'back_section' => $sec['id']], $itActive ? 'bi-eye-slash' : 'bi-eye', $itActive ? 'Hide item (not used for new move-outs)' : 'Show item again') ?>
                                    </div>
                                <?php else: ?>
                                    <span class="tpl-text"><?= h($it['item_text']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($canEdit): ?>
                            <form method="post" class="d-flex gap-1 p-2 border-top">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="add_item">
                                <input type="hidden" name="section_id" value="<?= (int)$sec['id'] ?>">
                                <input type="text" name="text" class="form-control form-control-sm" maxlength="255" placeholder="Add a checklist item to <?= h($sec['name']) ?>" required>
                                <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="bi bi-plus"></i> Add item</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($canEdit): ?>
                <div class="card">
                    <div class="card-body">
                        <form method="post" class="d-flex gap-2 flex-wrap">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="add_section">
                            <input type="text" name="text" class="form-control" style="max-width:420px" maxlength="150" placeholder="New section name, e.g. Furniture &amp; Fixtures" required>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle me-1"></i> Add section</button>
                        </form>
                        <div class="form-text">Hidden sections and items are kept for reference but are not copied into new move-outs.</div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
