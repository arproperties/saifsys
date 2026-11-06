<?php
/**
 * ARS Move-In / Move-Out Inspection Template (GET type = in | out).
 * Sections and checklist items copied into every new inspection of that direction
 * (ars_move_checklist_templates; a section is the section_name of its items).
 * Inspections already started keep their own items.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_move_process.php';

$arsCompanyId = arsPageAuth($conn);
$type = ars_move_inspection_type($_REQUEST['type'] ?? 'in');
$isOut = $type === 'out';
$label = $isOut ? 'Move-Out' : 'Move-In';
$canEdit = amp_can_manage($conn);
ars_move_inspection_ensure_schema($conn);
ars_move_inspection_templates($conn, $arsCompanyId, $type, false); // seeds the standard list the first time

/** Renumber the items: sections in their order, items in theirs. $sectionOrder: list of section names. */
$renumber = static function (array $sectionOrder) use ($conn, $arsCompanyId, $type): void {
    $st = $conn->prepare("SELECT id, COALESCE(section_name, '') AS sec FROM ars_move_checklist_templates WHERE company_id = ? AND move_type = ? ORDER BY display_order, id");
    $st->execute([$arsCompanyId, $type]);
    $bySec = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $bySec[$r['sec']][] = (int)$r['id'];
    }
    $order = array_values(array_unique(array_merge(array_values(array_filter($sectionOrder, static fn($x) => isset($bySec[$x]))), array_keys($bySec))));
    $upd = $conn->prepare("UPDATE ars_move_checklist_templates SET display_order = ? WHERE id = ? AND company_id = ?");
    $o = 0;
    foreach ($order as $sec) {
        foreach ($bySec[$sec] as $id) {
            $upd->execute([++$o, $id, $arsCompanyId]);
        }
    }
};
/** Section names in their current order. */
$sectionNames = static function () use ($conn, $arsCompanyId, $type): array {
    $st = $conn->prepare("SELECT COALESCE(section_name, '') AS sec, MIN(display_order) AS o FROM ars_move_checklist_templates WHERE company_id = ? AND move_type = ? GROUP BY COALESCE(section_name, '') ORDER BY MAX(is_active) DESC, o"); // hidden sections last
    $st->execute([$arsCompanyId, $type]);
    return array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'sec');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $sec = mb_substr(trim((string)($_POST['section'] ?? '')), 0, 100);
    $iid = (int)($_POST['item_id'] ?? 0);
    $text = mb_substr(trim((string)($_POST['text'] ?? '')), 0, 255);
    $anchor = '';
    try {
        if (!$canEdit) {
            throw new RuntimeException('Only Owner, Admin or ARS core staff can change the template.');
        }
        $secs = $sectionNames();
        switch ($action) {
            case 'standard':
                $cnt = ars_move_inspection_apply_standard($conn, $arsCompanyId, $type);
                $_SESSION['success'] = 'Standard ' . strtolower($label) . ' checklist loaded (' . $cnt . ' items). The previous items are kept as hidden.';
                break;
            case 'add_section':
                $new = mb_substr(trim((string)($_POST['new_section'] ?? '')), 0, 100);
                if ($new === '' || $text === '') throw new RuntimeException('Enter the section name and its first item.');
                if (in_array($new, $secs, true)) throw new RuntimeException('There is already a section called ' . $new . '.');
                $conn->prepare("INSERT INTO ars_move_checklist_templates (company_id, move_type, section_name, item_name, is_required, display_order)
                    SELECT ?, ?, ?, ?, ?, COALESCE(MAX(display_order), 0) + 1 FROM ars_move_checklist_templates WHERE company_id = ? AND move_type = ?")
                    ->execute([$arsCompanyId, $type, $new, $text, isset($_POST['is_required']) ? 1 : 0, $arsCompanyId, $type]);
                $anchor = '#sec' . (count($secs) + 1);
                $_SESSION['success'] = 'Section added.';
                break;
            case 'rename_section':
                $new = mb_substr(trim((string)($_POST['new_section'] ?? '')), 0, 100);
                if ($new === '') throw new RuntimeException('Enter a section name.');
                if ($new !== $sec && in_array($new, $secs, true)) throw new RuntimeException('There is already a section called ' . $new . '.');
                $conn->prepare("UPDATE ars_move_checklist_templates SET section_name = ? WHERE company_id = ? AND move_type = ? AND COALESCE(section_name, '') = ?")
                    ->execute([$new, $arsCompanyId, $type, $sec]);
                $anchor = '#sec' . (array_search($sec, $secs, true) + 1);
                $_SESSION['success'] = 'Section renamed.';
                break;
            case 'toggle_section':
                // Hidden when every item is off; Show turns them all on again
                $st = $conn->prepare("SELECT MAX(is_active) FROM ars_move_checklist_templates WHERE company_id = ? AND move_type = ? AND COALESCE(section_name, '') = ?");
                $st->execute([$arsCompanyId, $type, $sec]);
                $conn->prepare("UPDATE ars_move_checklist_templates SET is_active = ? WHERE company_id = ? AND move_type = ? AND COALESCE(section_name, '') = ?")
                    ->execute([(int)$st->fetchColumn() ? 0 : 1, $arsCompanyId, $type, $sec]);
                $anchor = '#sec' . (array_search($sec, $secs, true) + 1);
                $_SESSION['success'] = 'Section updated.';
                break;
            case 'move_section':
                $pos = array_search($sec, $secs, true);
                $to = $pos === false ? false : $pos + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
                if ($pos !== false && $to >= 0 && $to < count($secs)) {
                    [$secs[$pos], $secs[$to]] = [$secs[$to], $secs[$pos]];
                    $renumber($secs);
                    $anchor = '#sec' . ($to + 1);
                }
                break;
            case 'add_item':
                if ($text === '') throw new RuntimeException('Enter the checklist item.');
                if (!in_array($sec, $secs, true)) throw new RuntimeException('Section not found.');
                // Last in its section: placed after the section's last item, then renumbered
                $mx = $conn->prepare("SELECT COALESCE(MAX(display_order), 0) FROM ars_move_checklist_templates WHERE company_id = ? AND move_type = ? AND COALESCE(section_name, '') = ?");
                $mx->execute([$arsCompanyId, $type, $sec]);
                $max = (int)$mx->fetchColumn();
                $conn->prepare("UPDATE ars_move_checklist_templates SET display_order = display_order + 1 WHERE company_id = ? AND move_type = ? AND display_order > ?")
                    ->execute([$arsCompanyId, $type, $max]);
                $conn->prepare("INSERT INTO ars_move_checklist_templates (company_id, move_type, section_name, item_name, is_required, display_order) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$arsCompanyId, $type, $sec !== '' ? $sec : null, $text, isset($_POST['is_required']) ? 1 : 0, $max + 1]);
                $renumber($secs);
                $anchor = '#sec' . (array_search($sec, $secs, true) + 1);
                $_SESSION['success'] = 'Item added.';
                break;
            case 'edit_item':
                if ($text === '') throw new RuntimeException('Enter the checklist item.');
                $conn->prepare("UPDATE ars_move_checklist_templates SET item_name = ? WHERE id = ? AND company_id = ?")->execute([$text, $iid, $arsCompanyId]);
                $anchor = '#sec' . (array_search($sec, $secs, true) + 1);
                $_SESSION['success'] = 'Item updated.';
                break;
            case 'toggle_item':
            case 'toggle_required':
                $col = $action === 'toggle_item' ? 'is_active' : 'is_required';
                $conn->prepare("UPDATE ars_move_checklist_templates SET {$col} = 1 - {$col} WHERE id = ? AND company_id = ?")->execute([$iid, $arsCompanyId]);
                $anchor = '#sec' . (array_search($sec, $secs, true) + 1);
                $_SESSION['success'] = 'Item updated.';
                break;
            case 'move_item':
                $st = $conn->prepare("SELECT id FROM ars_move_checklist_templates WHERE company_id = ? AND move_type = ? AND COALESCE(section_name, '') = ? ORDER BY display_order, id");
                $st->execute([$arsCompanyId, $type, $sec]);
                $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $pos = array_search($iid, $ids, true);
                $to = $pos === false ? false : $pos + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
                if ($pos !== false && $to >= 0 && $to < count($ids)) {
                    $o = $conn->prepare("SELECT id, display_order FROM ars_move_checklist_templates WHERE id IN (?, ?)");
                    $o->execute([$ids[$pos], $ids[$to]]);
                    $ord = array_column($o->fetchAll(PDO::FETCH_ASSOC), 'display_order', 'id');
                    $u = $conn->prepare("UPDATE ars_move_checklist_templates SET display_order = ? WHERE id = ? AND company_id = ?");
                    $u->execute([$ord[$ids[$to]], $ids[$pos], $arsCompanyId]);
                    $u->execute([$ord[$ids[$pos]], $ids[$to], $arsCompanyId]);
                    $renumber($secs);
                }
                $anchor = '#sec' . (array_search($sec, $secs, true) + 1);
                break;
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header('Location: move_inspection_template.php?type=' . $type . $anchor);
    exit;
}

// Sections with their items, in order
$st = $conn->prepare("SELECT * FROM ars_move_checklist_templates WHERE company_id = ? AND move_type = ? ORDER BY display_order, id");
$st->execute([$arsCompanyId, $type]);
$sections = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $it) {
    $sections[(string)($it['section_name'] ?? '')][] = $it;
}
// Sections in use first (in their order), hidden ones after
uksort($sections, static function ($a, $b) use ($sections): int {
    $on = static fn(array $items): int => array_filter($items, static fn($x) => (int)$x['is_active'] === 1) ? 0 : 1;
    return [$on($sections[$a]), (int)$sections[$a][0]['display_order']] <=> [$on($sections[$b]), (int)$sections[$b][0]['display_order']];
});
$activeCount = 0;
foreach ($sections as $items) {
    foreach ($items as $it) {
        $activeCount += (int)$it['is_active'];
    }
}
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$tabClass = static fn(bool $on): string => 'no-underline rounded-ars-md px-3 py-1.5 text-ars-sm font-semibold ' . ($on ? 'bg-ars-ink text-white' : 'text-ars-muted hover:text-ars-text');
ars_shell_begin([
    'title' => 'Inspection Template',
    'subtitle' => 'Sections and checklist items copied into every new ' . strtolower($label) . ' inspection',
    'breadcrumbs' => [['label' => 'ARS', 'href' => 'index.php'], ['label' => 'Move-In / Move-Out', 'href' => 'move_report.php'], ['label' => 'Inspection Template']],
    'actions_html' => ars_move_inspection_nav_html('checklist'),
    'legacy_bootstrap' => true,
]);
$csrf = static function (): string { ob_start(); csrf_field(); return (string)ob_get_clean(); };
$btn = static function (string $action, array $fields, string $icon, string $title) use ($csrf, $type): string {
    $h = '<form method="post" class="d-inline">' . $csrf() . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
    return $h . '<button type="submit" class="btn btn-sm btn-link text-muted px-1" title="' . h($title) . '"><i class="bi ' . h($icon) . '"></i></button></form>';
};
?>
<style>
    .tpl-sec { border: 1px solid #e8e4dc; border-radius: 14px; background: #fff; margin-bottom: .85rem; scroll-margin-top: 1rem; }
    .tpl-sec.inactive { opacity: .6; }
    .tpl-sec-h { display: flex; align-items: center; gap: .5rem; padding: .65rem .9rem; border-bottom: 1px solid #e8e4dc; background: #faf8f3; border-radius: 14px 14px 0 0; flex-wrap: wrap; }
    .tpl-n { display: inline-flex; align-items: center; justify-content: center; width: 1.6rem; height: 1.6rem; border-radius: 50%; font-size: .8rem; font-weight: 700; background: rgba(184,134,11,.14); color: #8a6808; }
    .tpl-item { display: flex; align-items: center; gap: .5rem; padding: .35rem .9rem; border-top: 1px dashed #e8e4dc; }
    .tpl-item:first-child { border-top: 0; }
    .tpl-item.inactive .tpl-text input { text-decoration: line-through; color: #8a8580; }
    .tpl-text { flex: 1; min-width: 0; }
    .tpl-req { font-size: .72rem; border-radius: 999px; padding: .1rem .55rem; border: 1px solid #e8e4dc; background: #fff; color: #6b7280; }
    .tpl-req.on { background: rgba(184,134,11,.12); color: #8a6808; border-color: rgba(184,134,11,.35); }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="inline-flex flex-wrap gap-1 rounded-ars-lg border border-ars-border bg-ars-surface p-1">
        <a class="<?= $tabClass(!$isOut) ?>" href="?type=in">Move-In</a>
        <a class="<?= $tabClass($isOut) ?>" href="?type=out">Move-Out</a>
    </div>
    <?php if ($canEdit): ?>
        <form method="post" onsubmit="return confirm('Replace the <?= strtolower($label) ?> template with the standard checklist (10 sections)? The current items are kept as hidden. Inspections already started keep their items.');">
            <?php csrf_field(); ?><input type="hidden" name="type" value="<?= h($type) ?>">
            <button class="btn btn-sm btn-outline-secondary" name="action" value="standard"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset to the standard checklist</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success py-2"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
<p class="small text-muted">The <?= strtolower($label) ?> checklist: <?= (int)$activeCount ?> items in use. Changes apply to inspections started from now on; Inspection Remarks is the notes box at the end of every inspection.
    <?php if (!$canEdit): ?><br><i class="bi bi-eye"></i> View only. Owner, Admin or ARS core staff can change the template.<?php endif; ?></p>

<?php $sx = 0; $num = 0; foreach ($sections as $secName => $items): $sx++;
    $secActive = (bool)array_filter($items, static fn($x) => (int)$x['is_active'] === 1); if ($secActive) $num++; ?>
    <div class="tpl-sec <?= $secActive ? '' : 'inactive' ?>" id="sec<?= $sx ?>">
        <div class="tpl-sec-h">
            <span class="tpl-n"><?= $secActive ? $num : '–' ?></span>
            <?php if ($canEdit): ?>
                <form method="post" class="d-flex gap-1 flex-grow-1" style="min-width:220px">
                    <?php csrf_field(); ?><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="action" value="rename_section"><input type="hidden" name="section" value="<?= h($secName) ?>">
                    <input type="text" name="new_section" class="form-control form-control-sm fw-semibold" value="<?= h($secName !== '' ? $secName : 'Other') ?>" maxlength="100" required>
                    <button class="btn btn-sm btn-outline-secondary" type="submit" title="Save name"><i class="bi bi-check2"></i></button>
                </form>
                <div>
                    <?= $btn('move_section', ['section' => $secName, 'dir' => 'up'], 'bi-arrow-up', 'Move section up') ?>
                    <?= $btn('move_section', ['section' => $secName, 'dir' => 'down'], 'bi-arrow-down', 'Move section down') ?>
                    <?= $btn('toggle_section', ['section' => $secName], $secActive ? 'bi-eye-slash' : 'bi-eye', $secActive ? 'Hide section (not used for new inspections)' : 'Show section again') ?>
                </div>
            <?php else: ?>
                <strong class="flex-grow-1"><?= h($secName !== '' ? $secName : 'Other') ?></strong>
            <?php endif; ?>
            <?php if (!$secActive): ?><span class="badge bg-secondary">Hidden</span><?php endif; ?>
        </div>
        <div>
            <?php foreach ($items as $it): $on = (int)$it['is_active'] === 1; ?>
                <div class="tpl-item <?= $on ? '' : 'inactive' ?>">
                    <i class="bi bi-check2-square text-muted"></i>
                    <?php if ($canEdit): ?>
                        <form method="post" class="tpl-text">
                            <?php csrf_field(); ?><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="action" value="edit_item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="section" value="<?= h($secName) ?>">
                            <input type="text" name="text" class="form-control form-control-sm border-0 bg-transparent" value="<?= h($it['item_name']) ?>" maxlength="255" required onchange="this.form.requestSubmit()">
                        </form>
                        <form method="post" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="action" value="toggle_required"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="section" value="<?= h($secName) ?>">
                            <button type="submit" class="tpl-req <?= $it['is_required'] ? 'on' : '' ?>" title="Required items must be ticked to complete the inspection. Click to change."><?= $it['is_required'] ? 'Required' : 'Optional' ?></button></form>
                        <span class="text-nowrap">
                            <?= $btn('move_item', ['item_id' => $it['id'], 'section' => $secName, 'dir' => 'up'], 'bi-arrow-up', 'Move up') ?>
                            <?= $btn('move_item', ['item_id' => $it['id'], 'section' => $secName, 'dir' => 'down'], 'bi-arrow-down', 'Move down') ?>
                            <?= $btn('toggle_item', ['item_id' => $it['id'], 'section' => $secName], $on ? 'bi-eye-slash' : 'bi-eye', $on ? 'Hide item (not used for new inspections)' : 'Show item again') ?>
                        </span>
                    <?php else: ?>
                        <span class="tpl-text small"><?= h($it['item_name']) ?></span>
                        <span class="tpl-req <?= $it['is_required'] ? 'on' : '' ?>"><?= $it['is_required'] ? 'Required' : 'Optional' ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($canEdit): ?>
                <form method="post" class="d-flex flex-wrap gap-2 align-items-center p-2 border-top">
                    <?php csrf_field(); ?><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="action" value="add_item"><input type="hidden" name="section" value="<?= h($secName) ?>">
                    <input type="text" name="text" class="form-control form-control-sm flex-grow-1" style="min-width:220px;width:auto" maxlength="255" placeholder="Add a checklist item to <?= h($secName !== '' ? $secName : 'this section') ?>" required>
                    <label class="small text-nowrap"><input type="checkbox" class="form-check-input me-1" name="is_required" checked>Required</label>
                    <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="bi bi-plus"></i> Add item</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($canEdit): ?>
    <div class="ars-card">
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="action" value="add_section">
                <div class="col-md-4"><label class="form-label small fw-semibold mb-1">New section</label><input type="text" name="new_section" class="form-control form-control-sm" maxlength="100" placeholder="e.g. Laundry Area" required></div>
                <div class="col-md-5"><label class="form-label small fw-semibold mb-1">First item</label><input type="text" name="text" class="form-control form-control-sm" maxlength="255" placeholder="e.g. Dryer clean and working (if any)" required></div>
                <div class="col-md-1"><label class="small"><input type="checkbox" class="form-check-input me-1" name="is_required" checked>Required</label></div>
                <div class="col-md-2"><button class="btn btn-sm btn-ars w-100" type="submit"><i class="bi bi-plus-circle me-1"></i>Add section</button></div>
            </form>
            <div class="form-text">Hidden sections and items are kept but not used for new inspections. An item with "photo" in its name can only be ticked after a photo is uploaded.</div>
        </div>
    </div>
<?php endif; ?>
<?php ars_shell_end(); ?>
