<?php
/**
 * Shared parts of move_in_view.php and move_out_view.php: page styles, the
 * booking strip, the inspection checklist section, the form card and the
 * page script. Milestones and the date picker come from the Real Estate
 * move-in / move-out UI (re_move_ui.php), so both modules look the same.
 */

require_once dirname(__DIR__, 2) . '/realestate/includes/re_move_ui.php';

function amp_page_css(): string
{
    return '<style>' . re_move_ui_css() . re_milestones_css() . re_form_card_css() . '
.rm-page { --primary: #b8860b; --primary-dark: #8a6808; --shadow-sm: 0 1px 2px rgba(26,26,26,.04); --shadow: 0 4px 20px rgba(26,26,26,.07); --rm-border: #e8e4dc; }
/* ARS palette (gold #b8860b, deep #8a6808, bright #d4af37, success #059669) over the Real Estate step path */
.rm-page .rmm-track::after { background: linear-gradient(90deg, #059669, #b8860b); }
.rm-page .is-done .rmm-dot { background: #059669; border-color: #059669; }
.rm-page .rmm-btn[aria-pressed="true"] .rmm-dot { box-shadow: 0 0 0 4px rgba(184,134,11,.2); }
.rm-page .rmm-btn:focus-visible, .rm-page .rmm-nb:focus-visible { outline-color: rgba(184,134,11,.5); }
.rm-page .rmm-head { background: linear-gradient(120deg, rgba(184,134,11,.10), rgba(212,175,55,.05) 60%, var(--rm-surface)); }
.rm-page .rmm-head.is-done { background: linear-gradient(120deg, rgba(5,150,105,.10), var(--rm-surface) 70%); }
.rm-page .rmm-head.is-done .rmm-art, .rm-page .rmm-finish-row.ok > .bi:first-child { color: #059669; }
.rm-page .rmm-nb-prev:hover { border-color: rgba(184,134,11,.45); }
.rm-page .rmm-nb-prev:hover .rmm-nb-ico { background: rgba(184,134,11,.12); color: #8a6808; }
.rm-page .rmm-nb-next:not(.is-locked) { background: linear-gradient(135deg, #d4af37 0%, #b8860b 55%, #8a6808 100%); background-size: 160% 160%; box-shadow: 0 10px 24px -10px rgba(138,104,8,.75), inset 0 1px 0 rgba(255,255,255,.25); }
.rm-page .rmm-nb-next:not(.is-locked):hover { box-shadow: 0 16px 30px -12px rgba(138,104,8,.85), inset 0 1px 0 rgba(255,255,255,.25); }
.rm-page .rmm-finish-art { background: linear-gradient(135deg, #059669, #b8860b); box-shadow: 0 10px 24px rgba(184,134,11,.3); }
.rm-page .rm-pill-primary { background: rgba(184,134,11,.12); color: #8a6808; }
.rm-page .rm-pill-success { background: rgba(5,150,105,.12); color: #047857; }
.rm-page .rmf-chip:has(input:checked) { background: rgba(184,134,11,.08); }
.rm-page .amp-n { background: rgba(184,134,11,.12); color: #8a6808; }
.rm-page .amp-item.is-required { border-left-color: #d4af37; }
.rm-page .amp-item.is-done, .rm-page .amp-row.ok .ic { border-left-color: #059669; }
.rm-page .amp-row.ok .ic { background: rgba(5,150,105,.12); color: #059669; }
/* Action buttons in the ARS colours: main = gold, confirm (handover, complete) = ARS green */
.rm-page .btn-primary { background: #b8860b; border-color: #b8860b; color: #fff; }
.rm-page .btn-primary:hover, .rm-page .btn-primary:focus { background: #9a7209; border-color: #9a7209; color: #fff; }
.rm-page .btn-outline-primary { color: #8a6808; border-color: #b8860b; }
.rm-page .btn-outline-primary:hover { background: #b8860b; border-color: #b8860b; color: #fff; }
.rm-page .btn-success { background: #059669; border-color: #059669; }
.rm-page .btn-success:hover, .rm-page .btn-success:focus { background: #047857; border-color: #047857; }
.rm-page .btn-outline-success { color: #047857; border-color: #059669; }
.rm-page .btn-outline-success:hover { background: #059669; border-color: #059669; color: #fff; }
.rm-page .amp-card a:not(.btn):not(.rmm-finish-row) { color: #8a6808; }
.rm-page .btn-link { color: #8a6808; }
.rm-page .btn-link:hover { color: #6b5106; }
.rm-page .alert-info { background: rgba(184,134,11,.08); border-color: rgba(184,134,11,.28); color: #5c4a12; }
.amp-add { display: flex; gap: .5rem; margin-top: .6rem; }
/* Report card: include switches, then clearly separate action buttons */
.amp-incl { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.5rem; padding: .7rem .9rem; margin: .5rem 0 .85rem; background: #faf8f3; border: 1px solid #e8e4dc; border-radius: 10px; }
.amp-incl-l { font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--rm-muted); margin-right: .25rem; }
.amp-sw { margin: 0; min-height: 0; display: flex; align-items: center; gap: .45rem; }
.amp-sw .form-check-input { margin: 0; width: 2.1em; height: 1.15em; cursor: pointer; }
.amp-sw .form-check-input:checked { background-color: #b8860b; border-color: #b8860b; }
.amp-sw .form-check-input:focus { box-shadow: 0 0 0 .2rem rgba(184,134,11,.2); border-color: #d4af37; }
.amp-sw .form-check-label { cursor: pointer; font-size: .92rem; font-weight: 500; }
.amp-sw.is-off { opacity: .5; }
.amp-sw-n { display: inline-flex; align-items: center; justify-content: center; min-width: 1.35rem; height: 1.35rem; padding: 0 .35rem; border-radius: 999px; font-size: .72rem; font-weight: 700; background: rgba(184,134,11,.14); color: #8a6808; }
.amp-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; }
.rm-page .amp-actions .btn, .rm-page .rmf-step .btn:not(.btn-link), .rm-page .rmf-send .btn, .rm-page .rmf-more .btn { display: inline-flex; align-items: center; gap: .45rem; padding: .45rem 1rem; font-size: .9rem; font-weight: 600; border-radius: 8px; line-height: 1.3; }
.rm-page .amp-actions .btn-outline-primary { background: #fff; }
.amp-hf { font-size: .85rem; text-decoration: none; color: var(--rm-muted) !important; }
.amp-hf:hover { color: #8a6808 !important; }
.rm-page .rmf-step { padding: 1.05rem 0; gap: .9rem; }
.rm-page .rmf-step-t { margin-bottom: .55rem; font-size: 1rem; }
.rm-page .rmf-step-n { background: rgba(184,134,11,.14); color: #8a6808; }
.rm-page .rmf-step-n.is-or { background: #f3f1ec; color: var(--rm-muted); }
@media (max-width: 575.98px) { .amp-hf { margin-left: 0 !important; width: 100%; } }
@media (max-width: 575.98px) { .amp-add { flex-direction: column; } }
.amp-card { background: var(--rm-surface); border: 1px solid var(--rm-border); border-radius: 14px; margin-bottom: 1rem; }
.amp-card > .amp-h { display: flex; align-items: center; justify-content: space-between; gap: .5rem; flex-wrap: wrap; padding: .8rem 1rem; border-bottom: 1px solid var(--rm-border); font-weight: 700; }
.amp-card > .amp-b { padding: 1rem; }
.amp-item { border-left: 4px solid #dee2e6; border-radius: 8px; padding: .6rem .8rem; margin-bottom: .5rem; background: var(--rm-surface); box-shadow: inset 0 0 0 1px var(--rm-border); }
.amp-item.is-required { border-left-color: #b07d2b; }
.amp-sec { margin-bottom: .9rem; }
.amp-sec-h { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .45rem .1rem; margin-bottom: .35rem; border-bottom: 1px solid var(--rm-border); font-weight: 700; }
.amp-sec-n { display: inline-flex; align-items: center; justify-content: center; width: 1.45rem; height: 1.45rem; margin-right: .45rem; border-radius: 50%; font-size: .75rem; background: rgba(184,134,11,.12); color: #8a6808; }
.amp-sec .amp-item { padding: .45rem .7rem; margin-bottom: .3rem; }
.amp-sec .amp-item .form-check-label { font-size: .93rem; }
.amp-sec-h { cursor: pointer; user-select: none; border-radius: 8px; transition: background .2s ease; }
.amp-sec-h:hover { background: rgba(184,134,11,.05); }
.amp-sec-chev { color: var(--rm-muted); transition: transform .25s ease; }
.amp-sec.is-collapsed .amp-sec-chev { transform: rotate(-90deg); }
.amp-sec.is-collapsed .amp-item { display: none; }
.amp-sec.is-collapsed .amp-sec-h { margin-bottom: 0; }
.amp-sec-tick, .amp-sec-ok { display: none; }
.amp-sec.is-full .amp-sec-h { background: rgba(5,150,105,.07); border-bottom-color: transparent; padding-left: .55rem; padding-right: .55rem; }
.amp-sec.is-full .amp-sec-n { background: #059669; color: #fff; }
.amp-sec.is-full .amp-sec-num, .amp-sec.is-full .amp-sec-all { display: none; }
.amp-sec.is-full .amp-sec-tick { display: inline; }
.amp-sec.is-full .amp-sec-ok { display: inline-flex; align-items: center; color: #047857; font-size: .8rem; font-weight: 600; }
.amp-sec.is-full .amp-sec-count { display: none; }
.amp-sec .amp-item { animation: amp-in .2s ease; }
@keyframes amp-in { from { opacity: 0; transform: translateY(-3px); } to { opacity: 1; transform: none; } }
.amp-item.is-done { border-left-color: #198754; background: rgba(25,135,84,.05); }
.rm-page .amp-item.is-damaged { border-left-color: #dc3545; background: rgba(220,53,69,.06); }
.rm-page .amp-dmg-btn { padding: .15rem .5rem; font-size: .78rem; white-space: nowrap; border-color: transparent; color: #b4535b; background: transparent; opacity: .75; }
.rm-page .amp-dmg-btn:hover, .rm-page .amp-dmg-btn:focus-visible { opacity: 1; color: #fff; background: #dc3545; border-color: #dc3545; }
.rm-page .amp-item:hover .amp-dmg-btn { opacity: 1; }
.amp-item .form-check-input { width: 1.3rem; height: 1.3rem; margin-top: .1rem; }
.amp-photo img { width: 100%; height: 110px; object-fit: cover; border-radius: 8px; border: 1px solid var(--rm-border); }
.amp-facts { display: flex; flex-wrap: wrap; gap: .4rem 1.6rem; font-size: .9rem; }
.amp-facts .k { color: var(--rm-muted); font-size: .78rem; display: block; }
.amp-row { display: flex; gap: .75rem; align-items: flex-start; padding: .8rem 0; border-top: 1px dashed var(--rm-border); }
.amp-row:first-child { border-top: 0; padding-top: 0; }
.amp-row .ic { flex: 0 0 auto; width: 2rem; height: 2rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: rgba(15,23,42,.06); }
.amp-row.ok .ic { background: rgba(25,135,84,.12); color: #198754; }
.amp-n { flex: 0 0 auto; width: 1.7rem; height: 1.7rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: .85rem; background: #eef2ff; color: var(--primary); }
.amp-stmt { width: 100%; max-width: 640px; border-collapse: collapse; font-size: .95rem; }
.amp-stmt td { padding: .42rem .25rem; border-bottom: 1px dashed var(--rm-border); vertical-align: top; }
.amp-stmt td:last-child { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; width: 1%; padding-left: 1rem; }
.amp-stmt tr.less td:last-child { color: #b02a37; }
.amp-stmt tr.total td { border-top: 2px solid var(--rm-text); border-bottom: 0; font-weight: 700; font-size: 1.05rem; padding-top: .6rem; }
.amp-stmt tr.total td:last-child { color: #198754; }
.amp-stmt tr.sub td { color: var(--rm-muted); font-size: .88rem; }
.amp-stmt tr.excess td { background: rgba(220,53,69,.06); color: #b02a37; font-weight: 700; }
</style>';
}

/** Booking strip under the title. */
function amp_booking_strip(array $b): string
{
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    return '<div class="amp-card"><div class="amp-b"><div class="amp-facts">'
        . '<span><span class="k">Guest</span><strong>' . $e(amp_guest_name($b)) . '</strong> ' . $e($b['guest_phone'] ?? '') . '</span>'
        . '<span><span class="k">Unit</span><strong>' . $e(trim(($b['building_name'] ?? '') . ' · ' . ($b['unit_number'] ?? ''), ' ·')) . '</strong></span>'
        . '<span><span class="k">Stay</span><strong>' . $e(date('d M Y', strtotime($b['check_in'])) . ' → ' . date('d M Y', strtotime($b['actual_check_out'] ?: $b['check_out']))) . '</strong> · ' . (int)$b['nights'] . ' night(s)</span>'
        . '<span><span class="k">Booking</span><a href="booking_view.php?id=' . (int)$b['id'] . '">' . $e($b['booking_number']) . '</a> · ' . $e(ucwords(str_replace('_', ' ', $b['status']))) . '</span>'
        . '</div></div></div>';
}

/**
 * The checklist, notes and photos of the move-in / move-out inspection, with
 * Complete / Edit. $canEdit: the inspection may be reopened (Edit inspection).
 */
function amp_inspection_section(PDO $conn, int $companyId, int $bookingId, string $type, int $ms, bool $canEdit, string $lockNote = ''): string
{
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $insp = ars_move_inspection_find($conn, $companyId, $bookingId, $type);
    if (!$insp) {
        return '';
    }
    $items = ars_move_inspection_items($conn, (int)$insp['id']);
    $photos = ars_move_inspection_photos($conn, (int)$insp['id']);
    $damageCosts = [];
    if ($type === 'out') {
        $dc = $conn->prepare("SELECT d.id, d.cost FROM ars_move_out_damages d JOIN ars_move_inspection_items i ON i.damage_id = d.id WHERE i.inspection_id = ?");
        $dc->execute([(int)$insp['id']]);
        foreach ($dc->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $damageCosts[(int)$r['id']] = $r['cost'];
        }
    }
    $prog = ars_move_inspection_progress($conn, (int)$insp['id']);
    $done = $insp['status'] === 'completed';
    $label = $type === 'out' ? 'Final inspection' : 'Move-in inspection';
    $h = '<div class="amp-card" data-ms="' . $ms . '" id="ampInspection"><div class="amp-h"><span><i class="bi bi-clipboard-check me-1"></i>' . $label . ' checklist</span>'
        . re_move_ui_pill($done ? 'Completed' : $prog['done'] . ' of ' . $prog['total'], $done ? 'success' : 'primary') . '</div><div class="amp-b">';
    if (!$items) {
        $h .= '<p class="text-muted">No checklist items. Add them in the <a href="move_inspection_template.php?type=' . $type . '">Inspection Template</a>.</p>';
    }
    // Items under their section (General Condition, Kitchen, ...), in checklist order
    $sections = [];
    foreach ($items as $it) {
        $sections[(string)($it['section_name'] ?? '')][] = $it;
    }
    $sn = 0;
    foreach ($sections as $secName => $secItems) {
        $secDone = count(array_filter($secItems, static fn($x) => (int)$x['is_completed'] === 1));
        // A fully ticked section starts folded; its heading opens it again
        $full = $secItems && $secDone === count($secItems);
        $h .= '<div class="amp-sec' . ($full ? ' is-full is-collapsed' : '') . '" data-sec>';
        if ($secName !== '' || count($sections) > 1) {
            $h .= '<div class="amp-sec-h" role="button" tabindex="0" title="Show / hide the items"><span><span class="amp-sec-n"><span class="amp-sec-num">' . (++$sn) . '</span><i class="bi bi-check-lg amp-sec-tick"></i></span>' . $e($secName !== '' ? $secName : 'Other') . '</span>'
                . '<span class="d-flex align-items-center gap-2"><span class="amp-sec-ok"><i class="bi bi-check-circle-fill me-1"></i>All checked</span>'
                . '<span class="small text-muted amp-sec-count">' . $secDone . '/' . count($secItems) . '</span>'
                . (!$done ? '<button type="button" class="btn btn-sm btn-outline-primary py-0 amp-sec-all">Select all</button>' : '')
                . '<i class="bi bi-chevron-down amp-sec-chev"></i></span></div>';
        }
        foreach ($secItems as $it) {
            $rem = (string)($it['remarks'] ?? '');
            // Move-out: an item can be marked Damaged (recorded in Damages & Charges)
            $dmg = $type === 'out' && !empty($it['is_damaged']);
            $dmgCost = $dmg ? ($damageCosts[(int)$it['damage_id']] ?? false) : false;
            // "Photos taken": only once a photo is uploaded
            $needPhoto = !$done && ars_move_inspection_is_photo_item((string)$it['item_name']) && !$photos && !(int)$it['is_completed'];
            $h .= '<div class="amp-item ' . ($it['is_completed'] ? 'is-done ' : '') . ($dmg ? 'is-damaged' : '') . '" data-item-id="' . (int)$it['id'] . '"' . (ars_move_inspection_is_photo_item((string)$it['item_name']) ? ' data-photo="1"' : '') . '>'
                . '<div class="form-check d-flex gap-2 align-items-start mb-0"><input class="form-check-input flex-shrink-0 amp-check" type="checkbox" id="ai' . (int)$it['id'] . '" '
                . ($it['is_completed'] ? 'checked ' : '') . ($done || $needPhoto || $dmg ? 'disabled' : '') . '>'
                . '<label class="form-check-label flex-grow-1" for="ai' . (int)$it['id'] . '">' . $e($it['item_name'])
                . ($needPhoto ? ' <a href="#ampPhotos" class="badge text-bg-warning text-decoration-none fw-normal"><i class="bi bi-camera"></i> Upload photos first</a>' : '')
                . (!$it['is_required'] ? ' <span class="badge text-bg-light border fw-normal">Optional</span>' : '')
                . (!empty($it['item_description']) ? '<span class="d-block small text-muted">' . $e($it['item_description']) . '</span>' : '') . '</label>'
                . ($dmg ? ' <span class="badge text-bg-danger fw-normal"><i class="bi bi-exclamation-triangle"></i> Damaged · ' . ($dmgCost === false || $dmgCost === null ? 'not costed' : number_format((float)$dmgCost, 2) . ' AED') . '</span>' : '')
                . ($type === 'out' && !$done && !$dmg ? '<button type="button" class="btn btn-sm btn-outline-danger amp-dmg-btn" title="Found damaged: record it in Damages & Charges" onclick="ampItemDamage(' . (int)$it['id'] . ', ' . $e(json_encode((string)($it['section_name'] ?? ''))) . ', ' . $e(json_encode((string)$it['item_name'])) . ')"><i class="bi bi-exclamation-triangle"></i> Damaged</button>' : '')
                . ($dmg && !$done ? '<button type="button" class="btn btn-link btn-sm p-0 small text-danger" title="Undo: remove the damage" onclick="if (confirm(\'Undo? The damage recorded for this item is removed from Damages & Charges.\')) ampPostReload(\'item_damage_undo\', {type: \'out\', item_id: ' . (int)$it['id'] . '});"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>' : '')
                . (!$done && $rem === '' ? '<button type="button" class="btn btn-link btn-sm p-0 small amp-rem-open" title="Add a remark"><i class="bi bi-chat-left-text"></i></button>' : '') . '</div>'
                . '<input type="text" class="form-control form-control-sm mt-2 amp-remarks" maxlength="1000" placeholder="Remark, e.g. small scratch on the door" value="' . $e($rem) . '" '
                . ($done ? 'disabled ' : '') . ($rem === '' ? 'hidden' : '') . '></div>';
        }
        $h .= '</div>';
    }
    $h .= '<label class="form-label small fw-semibold mt-3 mb-1" for="ampNotes">Inspection Remarks</label>'
        . '<textarea class="form-control form-control-sm" id="ampNotes" rows="3" maxlength="5000" ' . ($done ? 'disabled' : '') . ' placeholder="' . ($type === 'out' ? 'Anything broken, missing or dirty' : 'Anything to note at arrival') . '">' . $e($insp['notes'] ?? '') . '</textarea>';

    // Photos
    $h .= '<div class="mt-3 fw-semibold small" id="ampPhotos"><i class="bi bi-camera me-1"></i>Photos (' . count($photos) . ')</div><div class="row g-2 mt-1">';
    foreach ($photos as $p) {
        $isPdf = strtolower(pathinfo((string)$p['original_name'], PATHINFO_EXTENSION)) === 'pdf';
        $h .= '<div class="col-6 col-md-3 amp-photo"><a href="move_inspection_photo.php?id=' . (int)$p['id'] . '" target="_blank" rel="noopener">'
            . ($isPdf ? '<div class="border rounded text-center py-4 text-danger"><i class="bi bi-file-earmark-pdf fs-2"></i></div>' : '<img src="move_inspection_photo.php?id=' . (int)$p['id'] . '" alt="" loading="lazy">')
            . '</a><div class="d-flex justify-content-between gap-1 mt-1"><span class="small text-muted text-truncate">' . $e($p['caption'] ?: $p['original_name']) . '</span>'
            . (!$done ? '<button type="button" class="btn btn-link btn-sm text-danger p-0 amp-photo-del" data-photo-id="' . (int)$p['id'] . '" title="Remove"><i class="bi bi-trash"></i></button>' : '') . '</div></div>';
    }
    $h .= '</div>';
    if (!$done) {
        $h .= '<form id="ampPhotoForm" class="row g-2 align-items-end mt-1"><div class="col-md-5"><input class="form-control form-control-sm" type="file" name="photos[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple required><div class="form-text">JPG, JPEG, PNG or WEBP · max 10 MB each</div></div>'
            . '<div class="col-md-5"><input class="form-control form-control-sm" type="text" name="caption" maxlength="255" placeholder="Room / note, e.g. Bedroom wall"></div>'
            . '<div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit"><i class="bi bi-upload me-1"></i>Upload</button></div></form>';
    }

    // Finish
    $h .= '<div class="mt-3 pt-3 border-top">';
    if (!$done) {
        $h .= '<button type="button" class="btn btn-success" id="ampInspComplete"><i class="bi bi-check2-circle me-1"></i>Complete inspection</button>'
            . '<span class="small text-muted ms-2">' . ($prog['required_left'] ? $prog['required_left'] . ' required item(s) left.' : 'All required items are ticked.') . '</span>';
    } else {
        $h .= '<span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Inspection completed ' . $e(date('d M Y H:i', strtotime($insp['completed_at']))) . (!empty($insp['completed_by_name']) ? ' by ' . $e($insp['completed_by_name']) : '') . '</span>';
        if ($canEdit) {
            $h .= ' <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="ampInspReopen()"><i class="bi bi-pencil-square me-1"></i>Edit inspection</button>';
        } elseif ($lockNote !== '') {
            $h .= ' <span class="small text-muted ms-2"><i class="bi bi-lock"></i> ' . $e($lockNote) . '</span>';
        }
    }
    return $h . '</div></div></div>';
}

/**
 * Form card, laid out like the Real Estate move-in Inspection Form:
 *  - not signed: 1 Prepare the form (include chips, Preview, Download), 2 Guest signs
 *    (Digital Sign, or upload the paper scan), or Email it to the guest for signing
 *  - signed: the signed copy, email it, and "Change the form or sign again" folded away
 * $kind 'in' (move-in inspection form) or 'out' (move-out inspection report).
 */
function amp_form_card(array $b, string $kind, int $ms, bool $inspDone, array $signed, ?string $sentAt, ?string $sentTo, int $photoCount, array $old = [], bool $locked = false): string
{
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $title = $kind === 'out' ? 'Move-Out Inspection Report' : 'Move-In Inspection Report';
    $doc = 'report';
    $url = 'move_form.php?booking_id=' . (int)$b['id'] . '&kind=' . $kind;
    $dl = static fn(array $a): string => 'document_download.php?booking_id=' . (int)$b['id'] . '&attachment_id=' . (int)$a['id'];
    $latest = $signed[0] ?? null;
    $pill = $latest ? re_move_ui_pill('Signed', 'success') : re_move_ui_pill($sentAt ? 'Awaiting signature' : 'To do', $sentAt ? 'warning' : 'primary');
    $h = '<div class="amp-card" data-ms="' . $ms . '"><div class="amp-h"><span><i class="bi bi-file-earmark-text me-1"></i>' . $title . '</span>' . $pill . '</div><div class="amp-b">';
    if (!$inspDone) {
        return $h . '<p class="small text-muted mb-0"><i class="bi bi-lock"></i> Complete the inspection first; the ' . $doc . ' is made from it.</p></div></div>';
    }
    $sentLine = $sentAt ? '<div class="small text-muted mt-1"><i class="bi bi-check2"></i> Last sent to ' . $e($sentTo) . ' · ' . $e(date('M d, Y H:i', strtotime($sentAt))) . '</div>' : '';
    // What goes in the report (switches), then the actions (buttons) on their own row
    $sw = static function (string $opt, string $label, bool $on, bool $available, string $count = '') use ($e, $kind): string {
        $id = 'ampOpt' . ucfirst($kind) . ucfirst($opt);
        return '<div class="form-check form-switch amp-sw' . ($available ? '' : ' is-off') . '" title="' . ($available ? '' : 'Nothing to include yet') . '">'
            . '<input class="form-check-input amp-opt" type="checkbox" role="switch" id="' . $id . '" data-opt="' . $opt . '" ' . ($on ? 'checked ' : '') . ($available ? '' : 'disabled') . '>'
            . '<label class="form-check-label" for="' . $id . '">' . $e($label) . ($count !== '' ? ' <span class="amp-sw-n">' . $e($count) . '</span>' : '') . '</label></div>';
    };
    $prepare = '<div class="amp-incl" aria-label="Include in the ' . $doc . '"><span class="amp-incl-l">Include</span>'
        . $sw('photos', 'Photos', $photoCount > 0, $photoCount > 0, $photoCount ? (string)$photoCount : '')
        . $sw('remarks', 'Item remarks & notes', false, true) . '</div>'
        . '<div class="amp-actions">'
        . '<a class="btn btn-outline-primary amp-form-link" data-base="' . $e($url) . '" href="' . $e($url) . '" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview</a>'
        . '<a class="btn btn-primary amp-form-link" data-base="' . $e($url . '&download=1') . '" href="' . $e($url . '&download=1') . '"><i class="bi bi-file-earmark-arrow-down"></i> Download PDF</a>'
        . '<a href="move_settings.php" class="amp-hf ms-auto"><i class="bi bi-layout-text-window"></i> Header &amp; footer</a></div>';
    $upload = '<form class="collapse" id="ampSignedForm" onsubmit="event.preventDefault(); ampUploadSigned(this);">'
        . '<div class="amp-add mt-2"><input type="file" name="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp" required>'
        . '<button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="bi bi-upload"></i> Upload scan</button></div>'
        . '<div class="form-text">PDF or photo of the paper-signed ' . $doc . ', up to 10MB.</div></form>';
    $email = static fn(string $btnCls, string $what) => '<form class="amp-add mt-0" onsubmit="event.preventDefault(); ampSend(this, \'' . $kind . '\');">'
        . '<input type="hidden" name="send_what" value="' . $what . '">'
        . '<input type="email" name="email" class="form-control" required value="' . $e($b['guest_email'] ?? '') . '" placeholder="Guest email">'
        . '<button class="btn ' . $btnCls . ' text-nowrap" type="submit"><i class="bi bi-send"></i> Send</button></form>';

    // Completed: the signed report is final. View / download only; no sending, signing or uploading
    if ($locked) {
        if ($latest) {
            $h .= '<div class="rmf-signed"><div class="rmf-signed-ic"><i class="bi bi-patch-check-fill"></i></div>'
                . '<div class="rmf-signed-txt"><div class="rmf-signed-t">' . $e(stripos((string)$latest['original_name'], '_signed.pdf') !== false ? 'Signed digitally' : 'Signed copy') . '</div>'
                . '<div class="rm-cell-sub">' . $e($latest['original_name']) . ' · ' . $e(date('M d, Y · H:i', strtotime($latest['created_at']))) . '</div></div>'
                . '<div class="rmf-row ms-auto"><a class="btn btn-sm btn-success" href="' . $e($dl($latest) . '&inline=1') . '" target="_blank" rel="noopener"><i class="bi bi-eye"></i> View</a>'
                . '<a class="btn btn-sm btn-outline-success" href="' . $e($dl($latest)) . '" title="Download the signed copy" aria-label="Download the signed copy"><i class="bi bi-download"></i></a></div></div>';
        }
        $h .= '<p class="small text-muted mt-3 mb-0"><i class="bi bi-lock"></i> The ' . ($kind === 'out' ? 'move-out' : 'move-in') . ' is completed, so the signed report is final: it can be viewed and downloaded, but no longer sent, signed again or replaced.'
            . ($sentAt ? ' Last sent to ' . $e($sentTo) . ' · ' . $e(date('M d, Y H:i', strtotime($sentAt))) . '.' : '') . '</p>';
        if (count($signed) > 1 || $old) {
            $h .= '<details class="rmf-more"><summary><i class="bi bi-files"></i> All signed copies <i class="bi bi-chevron-down ms-auto"></i></summary><div class="rmf-more-b">';
            foreach (array_merge($signed, $old) as $i => $sc) {
                $h .= '<div class="rmf-file"><i class="bi ' . ($i < count($signed) ? 'bi-file-earmark-check' : 'bi-file-earmark-x') . '"></i><a href="' . $e($dl($sc) . '&inline=1') . '" target="_blank" rel="noopener">' . $e($sc['original_name']) . '</a>'
                    . '<span class="rm-cell-sub">' . $e(date('M d, Y H:i', strtotime($sc['created_at']))) . ($i < count($signed) ? '' : ' · outdated') . '</span></div>';
            }
            $h .= '</div></details>';
        }
        return $h . '</div></div>';
    }
    if ($latest) {
        $h .= '<div class="rmf-signed"><div class="rmf-signed-ic"><i class="bi bi-patch-check-fill"></i></div>'
            . '<div class="rmf-signed-txt"><div class="rmf-signed-t">' . $e(stripos((string)$latest['original_name'], '_signed.pdf') !== false ? 'Signed digitally' : 'Signed copy') . '</div>'
            . '<div class="rm-cell-sub">' . $e($latest['original_name']) . ' · ' . $e(date('M d, Y · H:i', strtotime($latest['created_at']))) . '</div></div>'
            . '<div class="rmf-row ms-auto"><a class="btn btn-sm btn-success" href="' . $e($dl($latest) . '&inline=1') . '" target="_blank" rel="noopener"><i class="bi bi-eye"></i> View</a>'
            . '<a class="btn btn-sm btn-outline-success" href="' . $e($dl($latest)) . '" title="Download the signed copy" aria-label="Download the signed copy"><i class="bi bi-download"></i></a></div></div>';
        $h .= '<div class="rmf-send"><div class="rmf-send-t">Email the signed copy to the guest</div>' . $email('btn-primary', 'signed') . $sentLine . '</div>';
        $h .= '<details class="rmf-more"><summary><i class="bi bi-sliders"></i> Change the ' . $doc . ' or sign again <i class="bi bi-chevron-down ms-auto"></i></summary><div class="rmf-more-b">'
            . '<div class="rmf-sub">' . ucfirst($doc) . ' contents</div>' . $prepare
            . '<div class="rmf-sub">Signature</div><div class="rmf-row">'
            . '<button type="button" class="btn btn-sm btn-outline-success" onclick="ampSign(\'' . $kind . '\')"><i class="bi bi-pen"></i> Sign again</button>'
            . '<a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#ampSignedForm" role="button"><i class="bi bi-upload"></i> Upload paper scan</a></div>'
            . $upload
            . '<div class="rmf-sub">Email the unsigned ' . $doc . '</div>' . $email('btn-outline-secondary', 'unsigned')
            . '<div class="rmf-sub">Signed copies</div>';
        foreach ($signed as $sc) {
            $h .= '<div class="rmf-file"><i class="bi bi-file-earmark-check"></i><a href="' . $e($dl($sc) . '&inline=1') . '" target="_blank" rel="noopener">' . $e($sc['original_name']) . '</a>'
                . '<span class="rm-cell-sub">' . $e(date('M d, Y H:i', strtotime($sc['created_at']))) . '</span></div>';
        }
        return $h . '</div></details></div></div>';
    }
    if ($old) {
        // Signed before the inspection was changed: kept for the record, signing again is needed
        $h .= '<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle"></i> The inspection was changed after the guest signed on ' . $e(date('M d, Y H:i', strtotime($old[0]['created_at'])))
            . '. That signed copy no longer matches the report: have the guest sign the updated report.'
            . '<details class="mt-1"><summary>Earlier signed copies (' . count($old) . ')</summary>';
        foreach ($old as $oc) {
            $h .= '<div class="rmf-file"><i class="bi bi-file-earmark-x"></i><a href="' . $e($dl($oc) . '&inline=1') . '" target="_blank" rel="noopener">' . $e($oc['original_name']) . '</a>'
                . '<span class="rm-cell-sub">' . $e(date('M d, Y H:i', strtotime($oc['created_at']))) . ' · outdated</span></div>';
        }
        $h .= '</details></div>';
    }
    $h .= '<div class="rmf-step"><div class="rmf-step-n">1</div><div class="rmf-step-b"><div class="rmf-step-t">Prepare the ' . $doc . '</div>' . $prepare . '</div></div>';
    $h .= '<div class="rmf-step"><div class="rmf-step-n">2</div><div class="rmf-step-b"><div class="rmf-step-t">Guest signs</div><div class="rmf-row">'
        . '<button type="button" class="btn btn-success" onclick="ampSign(\'' . $kind . '\')"><i class="bi bi-pen"></i> Digital Sign</button>'
        . '<a class="rmf-link" data-bs-toggle="collapse" href="#ampSignedForm" role="button"><i class="bi bi-upload"></i> Signed on paper? Upload the scan</a></div>'
        . $upload . '</div></div>';
    $h .= '<div class="rmf-step"><div class="rmf-step-n is-or">or</div><div class="rmf-step-b"><div class="rmf-step-t">Email it to the guest for signing</div>'
        . $email('btn-outline-primary', 'unsigned') . $sentLine . '</div></div>';
    return $h . '</div></div>';
}

/** Move-out: pop-up to record a checklist item as damaged. */
function amp_damage_modal_html(): string
{
    return <<<'HTML'
<div class="modal fade" id="ampDmgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="ampDmgForm">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger"></i> Record damage</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="small text-muted mb-2" id="ampDmgItem"></div>
        <input type="hidden" name="item_id">
        <div class="row g-2">
          <div class="col-12"><label class="form-label small fw-semibold mb-1" for="ampDmgArea">Area</label><input type="text" name="area" id="ampDmgArea" class="form-control" maxlength="100" placeholder="e.g. Living room"></div>
          <div class="col-12"><label class="form-label small fw-semibold mb-1" for="ampDmgDesc">What is damaged *</label><textarea name="description" id="ampDmgDesc" class="form-control" rows="3" maxlength="500" required placeholder="Describe the damage, e.g. Sofa fabric torn on the left arm; 2 dining chairs missing"></textarea></div>
        </div>
        <div class="form-text">The damage is added to Damages &amp; Charges and the item is marked Damaged. Enter the repair cost there, in the Damages &amp; Charges step. Add photos of it in Photos below the checklist.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger"><i class="bi bi-exclamation-triangle"></i> Record damage</button></div>
    </form>
  </div>
</div>
<script>
function ampItemDamage(id, section, name) {
    const f = document.getElementById("ampDmgForm");
    f.reset(); f.item_id.value = id; f.area.value = section || "";
    document.getElementById("ampDmgItem").textContent = (section ? section + " · " : "") + name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById("ampDmgModal")).show();
    setTimeout(() => f.description.focus(), 300);
}
document.getElementById("ampDmgForm").addEventListener("submit", e => {
    e.preventDefault();
    const f = e.target, btn = f.querySelector("[type=submit]");
    if (!f.description.value.trim()) { f.description.focus(); return; }
    ampPostReload("item_damage", { type: "out", item_id: f.item_id.value, area: f.area.value, description: f.description.value }, btn);
});
</script>
HTML;
}

/** The signing pad (Real Estate pop-up, worded for the guest). */
function amp_sig_modal_html(): string
{
    require_once dirname(__DIR__, 2) . '/realestate/includes/re_digital_signature.php';
    return str_replace(['Tenant signature', 'Tenant name', 'Enter the tenant name.', 'placed in the Tenant signature box'],
        ['Guest signature', 'Guest name', 'Enter the guest name.', 'placed in the Guest signature box'], re_sig_modal_html());
}

/** Cancel (void) modal + button markup. */
function amp_void_html(string $type, bool $can, ?string $block): string
{
    if (!$can) {
        return '';
    }
    $label = $type === 'out' ? 'Cancel Move-Out' : 'Cancel Move-In';
    if ($block) {
        return '<button type="button" class="btn btn-outline-danger btn-sm" disabled title="' . htmlspecialchars($block, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-x-circle"></i> ' . $label . '</button>';
    }
    return '<button type="button" class="btn btn-outline-danger btn-sm" onclick="ampVoid()"><i class="bi bi-x-circle"></i> ' . $label . '</button>';
}

/** Page script shared by both pages. */
function amp_page_js(int $bookingId, string $type, string $guest = ''): string
{
    $csrf = json_encode(csrf_token());
    return '<script>
window.ampCtx = { booking: ' . $bookingId . ', type: ' . json_encode($type) . ', csrf: ' . $csrf . ', guest: ' . json_encode($guest) . ' };
function ampFetch(url, data) {
    const fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
    fd.append("_csrf", ampCtx.csrf); fd.append("booking_id", ampCtx.booking); if (!fd.has("type")) fd.append("type", ampCtx.type);
    return fetch(url, { method: "POST", body: fd, credentials: "same-origin" })
        .then(r => r.text().then(t => { try { return JSON.parse(t); } catch (e) { throw new Error(t.trim().slice(0, 200) || ("HTTP " + r.status)); } }));
}
function ampReload() { try { sessionStorage.setItem("ampScroll", String(window.scrollY)); } catch (e) {} location.reload(); }
function ampPost(action, data, btn) {
    if (btn) btn.disabled = true;
    return ampFetch("ajax_move_process.php", Object.assign({ action: action }, data || {})).then(res => {
        if (!res.success) { if (btn) btn.disabled = false; alert(res.message || res.error || "Could not save."); return res; }
        return res;
    }).catch(err => { if (btn) btn.disabled = false; alert("Could not save: " + err.message); return { success: false }; });
}
function ampPostReload(action, data, btn) { return ampPost(action, data, btn).then(r => { if (r.success) ampReload(); return r; }); }
// Booking status change through the normal booking action (cleaning order, timeline, ...)
function ampBooking(action, data) {
    return ampFetch("ajax_booking_actions.php", Object.assign({ action: action }, data || {})).then(r => {
        if (!r.success) throw new Error(r.error || r.message || "Booking action failed");
        return r;
    });
}
function ampInspReopen() {
    const reason = prompt("Edit the inspection? It opens again for changes.\\n\\nReason:");
    if (reason === null) return;
    ampPostReload("insp_reopen", { reason: reason });
}
function ampVoid() {
    const reason = prompt("Cancel this process? It is kept as cancelled and a new one can be started.\\n\\nReason:");
    if (reason === null) return;
    ampPost("void", { reason: reason }).then(r => { if (r.success) location.href = "booking_view.php?id=" + ampCtx.booking; });
}
function ampOpts() {
    const o = {}; document.querySelectorAll(".amp-opt").forEach(c => { o[c.dataset.opt] = c.checked ? "1" : "0"; }); return o;
}
function ampSyncLinks() {
    const q = new URLSearchParams(ampOpts()).toString();
    document.querySelectorAll(".amp-form-link").forEach(a => { a.href = a.dataset.base + "&" + q; });
}
document.querySelectorAll(".amp-opt").forEach(c => c.addEventListener("change", ampSyncLinks)); ampSyncLinks();
function ampSend(form, kind) {
    const btn = form.querySelector("button");
    const d = Object.assign({ email: form.email.value, kind: kind }, ampOpts());
    if (form.send_what) d.send_what = form.send_what.value;
    ampPost("form_send", d, btn).then(r => { if (r.success) { alert(r.message); ampReload(); } });
}
function ampSign(kind) {
    if (!window.reSigOpen) { alert("The signing pad could not load. Reload the page."); return; }
    window.reSigOpen({
        title: kind === "out" ? "Guest signature · move-out report" : "Guest signature · move-in form",
        name: window.ampCtx.guest || "",
        onSave: (dataUrl, name, btn) => {
            btn.disabled = true; btn.innerHTML = "Saving…";
            ampFetch("ajax_move_process.php", Object.assign({ action: "form_sign", signature: dataUrl, signer_name: name }, ampOpts()))
                .then(r => { if (!r.success) throw new Error(r.message || "Could not save the signature"); ampReload(); })
                .catch(e => { alert(e.message); btn.disabled = false; btn.innerHTML = "<i class=\"bi bi-check-circle\"></i> Sign &amp; save"; });
        }
    });
}
function ampUploadSigned(form) {
    const fd = new FormData(form); fd.append("action", "signed_upload");
    const btn = form.querySelector("button"); btn.disabled = true;
    ampFetch("ajax_move_process.php", fd).then(r => { if (!r.success) { btn.disabled = false; alert(r.message || "Upload failed"); } else ampReload(); })
        .catch(e => { btn.disabled = false; alert(e.message); });
}
// Inspection checklist: saved through ajax_move_inspection.php
document.querySelectorAll(".amp-item").forEach(row => {
    const check = row.querySelector(".amp-check"), rem = row.querySelector(".amp-remarks");
    const save = () => ampFetch("ajax_move_inspection.php", { action: "save_item", item_id: row.dataset.itemId, is_completed: check.checked ? "1" : "0", remarks: rem.value })
        .then(d => { if (!d.success) throw new Error(d.error || "Could not save"); row.classList.toggle("is-done", check.checked); });
    const recount = () => {
        const sec = row.closest("[data-sec]"); if (!sec) return;
        const done = sec.querySelectorAll(".amp-check:checked").length, all = sec.querySelectorAll(".amp-check").length;
        const c = sec.querySelector(".amp-sec-count"); if (c) c.textContent = done + "/" + all;
        const wasFull = sec.classList.contains("is-full"), full = all > 0 && done === all;
        sec.classList.toggle("is-full", full);
        // Fold once everything is ticked (a short pause so the last tick is seen); open again when one is unticked
        if (full && !wasFull) setTimeout(() => { if (sec.classList.contains("is-full")) sec.classList.add("is-collapsed"); }, 450);
        if (!full) sec.classList.remove("is-collapsed");
    };
    // "Photos taken" needs a photo on this inspection (also checked by the server)
    const noPhoto = () => row.dataset.photo === "1" && check.checked && !document.querySelector(".amp-photo");
    const refuse = msg => { check.checked = false; row.classList.remove("is-done"); recount(); if (msg) alert(msg); };
    const photoMsg = "Upload at least one photo first (Photos, below the checklist); then tick this item.";
    row.ampSave = () => noPhoto() ? (refuse(), Promise.reject(new Error(photoMsg))) : save().then(recount, e => { refuse(); throw e; });
    const ro = row.querySelector(".amp-rem-open"); if (ro) ro.addEventListener("click", () => { rem.hidden = false; ro.remove(); rem.focus(); });
    check.addEventListener("change", () => {
        if (noPhoto()) { refuse(photoMsg); return; }
        const was = !check.checked;
        save().then(recount).catch(e => { check.checked = was; row.classList.toggle("is-done", was); recount(); alert(e.message); });
    });
    rem.addEventListener("change", () => save().catch(e => alert(e.message)));
});
// Section heading: show / hide its items (buttons inside it keep their own action)
document.querySelectorAll(".amp-sec-h").forEach(h => {
    const toggle = e => { if (e.target.closest("button")) return; h.closest("[data-sec]").classList.toggle("is-collapsed"); };
    h.addEventListener("click", toggle);
    h.addEventListener("keydown", e => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggle(e); } });
});
// Select all in a section: ticks every unticked item, one save each
document.querySelectorAll(".amp-sec-all").forEach(btn => btn.addEventListener("click", () => {
    const hasPhoto = !!document.querySelector(".amp-photo");
    const all = [...btn.closest("[data-sec]").querySelectorAll(".amp-item")].filter(r => { const c = r.querySelector(".amp-check"); return !c.checked && !c.disabled; });
    const rows = all.filter(r => hasPhoto || r.dataset.photo !== "1");
    const skipped = all.length - rows.length;
    if (!rows.length) { if (skipped) alert("Upload at least one photo first (Photos, below the checklist); then tick the photos item."); return; }
    btn.disabled = true;
    const errors = [];
    rows.reduce((p, r) => p.then(() => { r.querySelector(".amp-check").checked = true; return r.ampSave().catch(e => { errors.push(e.message); }); }), Promise.resolve())
        .then(() => {
            if (skipped) errors.push("The photos item is not ticked: upload at least one photo first (Photos, below the checklist).");
            if (errors.length) alert([...new Set(errors)].join("\n"));
        })
        .finally(() => { btn.disabled = false; });
}));
const ampNotes = document.getElementById("ampNotes");
const ampSaveNotes = () => ampNotes && !ampNotes.disabled ? ampFetch("ajax_move_inspection.php", { action: "save_notes", notes: ampNotes.value }) : Promise.resolve({ success: true });
if (ampNotes) ampNotes.addEventListener("change", () => ampSaveNotes().then(d => { if (!d.success) alert(d.error || "Could not save notes"); }));
const ampPhotoForm = document.getElementById("ampPhotoForm");
if (ampPhotoForm) ampPhotoForm.addEventListener("submit", e => {
    e.preventDefault();
    const bad = [...ampPhotoForm.querySelector("input[type=file]").files].filter(f => !/\.(jpe?g|png|webp)$/i.test(f.name) || f.size > 10 * 1024 * 1024);
    if (bad.length) { alert("Only photos (JPG, JPEG, PNG or WEBP, max 10 MB each) can be uploaded:\n" + bad.map(f => "• " + f.name).join("\n")); return; }
    const fd = new FormData(ampPhotoForm); fd.append("action", "upload_photo");
    const btn = ampPhotoForm.querySelector("button"); btn.disabled = true;
    ampFetch("ajax_move_inspection.php", fd).then(d => { if (!d.success) throw new Error(d.error || "Upload failed"); if (d.error) alert("Some files were not saved: " + d.error); ampReload(); })
        .catch(err => { btn.disabled = false; alert(err.message); });
});
document.querySelectorAll(".amp-photo-del").forEach(b => b.addEventListener("click", () => {
    if (!confirm("Remove this photo?")) return;
    ampFetch("ajax_move_inspection.php", { action: "delete_photo", photo_id: b.dataset.photoId }).then(d => { if (!d.success) throw new Error(d.error || "Could not remove"); ampReload(); }).catch(e => alert(e.message));
}));
const ampInspBtn = document.getElementById("ampInspComplete");
if (ampInspBtn) ampInspBtn.addEventListener("click", () => {
    if (!confirm("Complete the inspection? The checklist is locked after this (it can be edited again with a reason).")) return;
    ampInspBtn.disabled = true;
    ampSaveNotes().then(() => ampFetch("ajax_move_inspection.php", { action: "complete" })).then(d => { if (!d.success) throw new Error(d.error || "Could not complete"); ampReload(); })
        .catch(e => { ampInspBtn.disabled = false; alert(e.message); });
});
try { const y = sessionStorage.getItem("ampScroll"); if (y !== null) { sessionStorage.removeItem("ampScroll"); window.scrollTo(0, +y); } } catch (e) {}
</script>';
}
