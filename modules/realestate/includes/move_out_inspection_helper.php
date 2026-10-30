<?php
/**
 * Move-Out inspection checklist (migrations/re_move_out_inspection.sql).
 *
 * Move-outs have their own template (re_move_out_inspection_sections /
 * _template_items, edited on move_out_inspection_template.php), started from a
 * copy of the company's move-in template. Each move-out keeps its own copy of
 * the template's items (re_move_out_inspection_items). Every item is marked OK, Damaged or N/A;
 * a Damaged item points at its damage (re_move_out_damages.inspection_item_id),
 * so the damage assessment and the deposit settlement see it. The final
 * inspection is complete only when no item is left open.
 *
 * Move-outs whose inspection was completed before the checklist existed have
 * no items and keep working as before.
 */

require_once __DIR__ . '/move_in_stages_helper.php';

const RMOI_STATUS_LABELS = ['open' => 'Not checked', 'ok' => 'OK', 'damaged' => 'Damaged', 'na' => 'N/A'];

function rmoi_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW TABLES LIKE 're_move_out_inspection_items'")->fetchColumn()
                && (bool)$conn->query("SHOW TABLES LIKE 're_move_out_inspection_template_items'")->fetchColumn()
                && (bool)$conn->query("SHOW COLUMNS FROM re_move_out_damages LIKE 'inspection_item_id'")->fetch()
                && (bool)$conn->query("SHOW COLUMNS FROM re_move_out_inspection_items LIKE 'is_walkthrough'")->fetch()
                && rms_ready($conn);
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Start the company's move-out template, once: a copy of its move-in template
 * (active sections and items), which itself falls back to the default list.
 */
function rmoi_ensure_template(PDO $conn, int $companyId): void
{
    $st = $conn->prepare("SELECT COUNT(*) FROM re_move_out_inspection_sections WHERE company_id = ?");
    $st->execute([$companyId]);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    rms_ensure_template($conn, $companyId);
    $sec = $conn->prepare("INSERT INTO re_move_out_inspection_sections (company_id, name, display_order, is_walkthrough) VALUES (?, ?, ?, ?)");
    $itm = $conn->prepare("INSERT INTO re_move_out_inspection_template_items (company_id, section_id, item_text, display_order) VALUES (?, ?, ?, ?)");
    $so = 0;
    foreach (rms_template($conn, $companyId) as $s) {
        $sec->execute([$companyId, $s['name'], ++$so, stripos((string)$s['name'], 'walkthrough') !== false ? 1 : 0]);
        $sid = (int)$conn->lastInsertId();
        $io = 0;
        foreach ($s['items'] as $i) {
            if (rms_item_kind((string)$i['item_text']) === 'form') {
                continue; // the signed move-in form
            }
            $itm->execute([$companyId, $sid, $i['item_text'], ++$io]);
        }
    }
}

/** Move-out template: [ ['id','name','display_order','is_active','items'=>[...]], ... ] */
function rmoi_template(PDO $conn, int $companyId, bool $includeInactive = false): array
{
    $st = $conn->prepare("SELECT * FROM re_move_out_inspection_sections WHERE company_id = ?" . ($includeInactive ? '' : ' AND is_active = 1') . " ORDER BY display_order, id");
    $st->execute([$companyId]);
    $sections = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $it = $conn->prepare("SELECT * FROM re_move_out_inspection_template_items WHERE section_id = ?" . ($includeInactive ? '' : ' AND is_active = 1') . " ORDER BY display_order, id");
    foreach ($sections as &$s) {
        $it->execute([(int)$s['id']]);
        $s['items'] = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($s);
    return $sections;
}

/** Give a move-out its own copy of the move-out template, once. */
function rmoi_create_for_move_out(PDO $conn, int $companyId, int $moveOutId, ?int $userId): void
{
    $has = $conn->prepare("SELECT COUNT(*) FROM re_move_out_inspection_items WHERE move_out_id = ?");
    $has->execute([$moveOutId]);
    if ((int)$has->fetchColumn() > 0) {
        return;
    }
    rmoi_ensure_template($conn, $companyId);
    $ins = $conn->prepare("
        INSERT INTO re_move_out_inspection_items
            (company_id, move_out_id, section_name, section_order, item_text, item_order, is_walkthrough, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach (rmoi_template($conn, $companyId) as $s) {
        foreach ($s['items'] as $i) {
            $ins->execute([$companyId, $moveOutId, $s['name'], (int)$s['display_order'], $i['item_text'], (int)$i['display_order'], (int)$s['is_walkthrough'], $userId]);
        }
    }
}

/** Items grouped by section: [ section_name => ['order' => n, 'items' => [...]] ] */
function rmoi_items(PDO $conn, int $moveOutId): array
{
    $st = $conn->prepare("
        SELECT ii.*, u.username AS checked_by_name,
               d.repair_cost AS damage_cost, d.damage_type AS damage_severity, d.damage_description
        FROM re_move_out_inspection_items ii
        LEFT JOIN user u ON u.id = ii.checked_by
        LEFT JOIN re_move_out_damages d ON d.id = ii.damage_id
        WHERE ii.move_out_id = ?
        ORDER BY ii.section_order, ii.section_name, ii.item_order, ii.id
    ");
    $st->execute([$moveOutId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $out[$r['section_name']]['order'] = (int)$r['section_order'];
        $out[$r['section_name']]['items'][] = $r;
    }
    return $out;
}

/** ['total', 'open', 'ok', 'damaged', 'na'] for a grouped item list. */
function rmoi_counts(array $grouped): array
{
    $c = ['total' => 0, 'open' => 0, 'ok' => 0, 'damaged' => 0, 'na' => 0];
    foreach ($grouped as $sec) {
        foreach ($sec['items'] as $it) {
            $c['total']++;
            $c[$it['status']] = ($c[$it['status']] ?? 0) + 1;
        }
    }
    return $c;
}

/** re_move_out_photos.file_type exists (migrations/re_move_out_signed_forms.sql). */
function rmo_signed_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_out_photos LIKE 'file_type'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** SQL condition for photos only (not signed forms), for re_move_out_photos aliased or not. */
function rmo_photo_only_sql(PDO $conn, string $alias = ''): string
{
    return rmo_signed_ready($conn) ? " AND {$alias}file_type = 'photo'" : '';
}

/** Signed Inspection Report (Form 1) uploads, newest first. */
function rmo_signed_reports(PDO $conn, int $moveOutId): array
{
    if (!rmo_signed_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("
        SELECT p.*, u.username AS uploaded_by_name FROM re_move_out_photos p
        LEFT JOIN user u ON u.id = p.uploaded_by
        WHERE p.move_out_id = ? AND p.file_type = 'inspection_report'
        ORDER BY p.uploaded_at DESC, p.id DESC
    ");
    $st->execute([$moveOutId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Signed reports replaced when the inspection was changed afterwards (kept as history). */
function rmo_superseded_reports(PDO $conn, int $moveOutId): array
{
    if (!rmo_signed_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("SELECT * FROM re_move_out_photos WHERE move_out_id = ? AND file_type = 'inspection_report_superseded' ORDER BY uploaded_at DESC, id DESC");
    $st->execute([$moveOutId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * The "Photos taken" checklist item can only be OK while a photo exists: when
 * the last one is removed it goes back to not checked. Returns how many items
 * were set back.
 */
function rmoi_sync_photo_items(PDO $conn, int $companyId, int $moveOutId): int
{
    if (!rmoi_ready($conn) || rmoi_photo_count($conn, $moveOutId) > 0) {
        return 0;
    }
    $st = $conn->prepare("SELECT id, item_text FROM re_move_out_inspection_items WHERE move_out_id = ? AND company_id = ? AND status = 'ok'");
    $st->execute([$moveOutId, $companyId]);
    $upd = $conn->prepare("UPDATE re_move_out_inspection_items SET status = 'open', checked_by = NULL, checked_at = NULL WHERE id = ?");
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $it) {
        if (rms_item_kind((string)$it['item_text']) === 'photos') {
            $upd->execute([(int)$it['id']]);
            $n++;
        }
    }
    return $n;
}

function rmoi_photo_count(PDO $conn, int $moveOutId): int
{
    $st = $conn->prepare("SELECT COUNT(*) FROM re_move_out_photos WHERE move_out_id = ?" . rmo_photo_only_sql($conn));
    $st->execute([$moveOutId]);
    return (int)$st->fetchColumn();
}

/**
 * Whether an item can be marked Damaged: not the walkthrough section's items
 * (the walkthrough itself, photos taken, ...) nor the photos item.
 */
function rmoi_item_can_damage(array $item): bool
{
    return empty($item['is_walkthrough']) && rms_item_kind((string)($item['item_text'] ?? '')) !== 'photos';
}

/** Keep re_move_outs.damage_assessment_total in step with the damages. */
function rmoi_recalc_damage_total(PDO $conn, int $moveOutId): float
{
    $st = $conn->prepare("SELECT COALESCE(SUM(repair_cost), 0) FROM re_move_out_damages WHERE move_out_id = ?");
    $st->execute([$moveOutId]);
    $total = (float)$st->fetchColumn();
    $conn->prepare("UPDATE re_move_outs SET damage_assessment_total = ? WHERE id = ?")->execute([$total, $moveOutId]);
    return $total;
}

/**
 * Set an item to open / ok / na. Leaving "damaged" removes the damage that
 * was recorded for it. Damaged is set by recording the damage (add_damage).
 */
function rmoi_set_status(PDO $conn, int $companyId, int $moveOutId, int $itemId, string $status, ?int $userId): ?string
{
    if (!in_array($status, ['open', 'ok', 'na'], true)) {
        return 'Unknown status.';
    }
    $st = $conn->prepare("SELECT id, status, damage_id, item_text FROM re_move_out_inspection_items WHERE id = ? AND move_out_id = ? AND company_id = ?");
    $st->execute([$itemId, $moveOutId, $companyId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        return 'Inspection item not found.';
    }
    if ($status === 'ok' && rms_item_kind((string)$item['item_text']) === 'photos' && rmoi_photo_count($conn, $moveOutId) === 0) {
        return 'Upload at least one photo in Move-Out Photos first.';
    }
    $conn->beginTransaction();
    try {
        if ($item['status'] === 'damaged' && $item['damage_id']) {
            $conn->prepare("DELETE FROM re_move_out_damages WHERE id = ? AND move_out_id = ?")->execute([(int)$item['damage_id'], $moveOutId]);
            // Its photos stay as condition photos
            $conn->prepare("UPDATE re_move_out_photos SET is_damage = 0, damage_id = NULL WHERE damage_id = ? AND move_out_id = ?")->execute([(int)$item['damage_id'], $moveOutId]);
            rmoi_recalc_damage_total($conn, $moveOutId);
        }
        $done = $status !== 'open';
        $conn->prepare("UPDATE re_move_out_inspection_items SET status = ?, damage_id = NULL, checked_by = ?, checked_at = ? WHERE id = ?")
            ->execute([$status, $done ? $userId : null, $done ? date('Y-m-d H:i:s') : null, $itemId]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        return 'Could not save: ' . $e->getMessage();
    }
    return null;
}
