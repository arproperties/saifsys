<?php
/**
 * Move-In Inspection Form (step 5 of the move-in stages).
 *
 * Built on demand from the move-in record, in this order:
 *   1. Move-in details (tenant, lease, unit, move-in date)
 *   2. Keys / access granted (re_move_in_keys, quantity > 0)
 *   3. Inspection checklist (re_move_in_inspection_items) + meter readings + remarks
 *   4. Declaration and signatures
 *   5. Photos (optional, on by default)
 *
 * Used by move_in_form.php (preview / download) and move_in_view.php (email to tenant).
 */

require_once __DIR__ . '/move_in_stages_helper.php';
require_once __DIR__ . '/form_letterhead_helper.php';
require_once __DIR__ . '/re_digital_signature.php';

const RMF_PHOTO_MAX_PX = 1200;

/** Everything the form shows, or null when the move-in is not in this company. */
function rmf_load(PDO $conn, int $companyId, int $moveInId): ?array
{
    $st = $conn->prepare("
        SELECT mi.*, l.lease_number, l.start_date AS lease_start, l.end_date AS lease_end,
               u.unit_number, u.unit_type, b.name AS building_name, b.address AS building_address,
               t.first_name, t.last_name, t.phone, t.email, t.id_number,
               c.name AS company_name, uk.username AS keys_handed_over_by_name
        FROM re_move_ins mi
        JOIN re_leases l ON l.id = mi.lease_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN companies c ON c.id = mi.company_id
        LEFT JOIN user uk ON uk.id = mi.keys_handed_over_by
        WHERE mi.id = ? AND mi.company_id = ?
    ");
    $st->execute([$moveInId, $companyId]);
    $moveIn = $st->fetch(PDO::FETCH_ASSOC);
    if (!$moveIn) {
        return null;
    }

    $meters = $conn->prepare("SELECT * FROM re_meter_readings WHERE lease_id = ? AND reading_type = 'move_in' ORDER BY meter_type, reading_date DESC");
    $meters->execute([(int)$moveIn['lease_id']]);

    $photos = $conn->prepare("SELECT * FROM re_move_in_photos WHERE move_in_id = ? AND file_type = 'photo' ORDER BY uploaded_at, id");
    $photos->execute([$moveInId]);

    return [
        'move_in' => $moveIn,
        'items' => rms_items($conn, $moveInId),
        'keys' => array_values(array_filter(rms_keys($conn, $moveInId), fn($k) => (int)$k['quantity'] > 0)),
        'meters' => $meters->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'photos' => $photos->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
}

function rmf_file_name(array $data): string
{
    $ref = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($data['move_in']['lease_number'] ?: ('MI-' . $data['move_in']['id'])));
    return 'Move_In_Inspection_' . trim($ref, '-') . '.pdf';
}

function rmf_project_root(): string
{
    return dirname(__DIR__, 3);
}

/** Writable folder for mPDF temp files and resized photos. */
function rmf_temp_dir(): string
{
    $dir = rmf_project_root() . '/uploads/move_in_forms_temp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        return $dir;
    }
    return rtrim(sys_get_temp_dir(), '/');
}

/**
 * Smaller JPEG copy of a photo for the PDF (keeps the file small enough to email).
 * Returns [absolute path, is_temp] or null when the photo cannot be read.
 */
function rmf_photo_for_pdf(string $absPath, string $tempDir): ?array
{
    if (!is_file($absPath) || !is_readable($absPath)) {
        return null;
    }
    $info = @getimagesize($absPath);
    if (!$info) {
        return null;
    }
    [$w, $h, $type] = $info;
    $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    $loader = $loaders[$type] ?? null;
    $small = max($w, $h) <= RMF_PHOTO_MAX_PX && $type === IMAGETYPE_JPEG;
    if ($small || !$loader || !function_exists($loader) || !function_exists('imagecreatetruecolor')) {
        // Use the original when it is small already, or when GD cannot open it (mPDF reads JPEG/PNG)
        return in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) ? [$absPath, false] : null;
    }
    $src = @$loader($absPath);
    if (!$src) {
        return null;
    }
    $scale = min(1, RMF_PHOTO_MAX_PX / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $out = $tempDir . '/rmf_' . bin2hex(random_bytes(6)) . '.jpg';
    $ok = imagejpeg($dst, $out, 75);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok ? [$out, true] : null;
}

/**
 * When the keys are (or were) handed over, for the form and the email:
 * ['handed' => bool, 'mode' => move_in|scheduled|on_signing, 'when' => 'd M Y H:i' or '',
 *  'line' => one sentence for the keys section]
 */
function rmf_key_handover(array $mi): array
{
    $mode = rms_key_mode($mi);
    if (!empty($mi['keys_handed_over'])) {
        $when = rmf_date($mi['keys_handed_over_at'] ?? null, 'd M Y H:i');
        return ['handed' => true, 'mode' => $mode, 'when' => $when,
            'line' => 'Handed over on ' . $when . (!empty($mi['keys_handed_over_by_name']) ? ' by ' . $mi['keys_handed_over_by_name'] : '') . '.'];
    }
    if ($mode === 'scheduled') {
        $at = (string)($mi['key_handover_scheduled_at'] ?? '');
        // A handover date (no time of day) is printed as a date only
        $when = rmf_date($at ?: null, $at !== '' && date('H:i', strtotime($at)) === '00:00' ? 'd M Y' : 'd M Y H:i');
        return ['handed' => false, 'mode' => $mode, 'when' => $when,
            'line' => $when !== '' ? 'To be handed over on ' . $when . '.' : 'To be handed over on a date to be confirmed.'];
    }
    if ($mode === 'on_signing') {
        return ['handed' => false, 'mode' => $mode, 'when' => '', 'line' => 'To be handed over when the tenant signs this report.'];
    }
    return ['handed' => false, 'mode' => $mode, 'when' => '', 'line' => ''];
}

/** Sentence for the email to the tenant about the key handover. */
function rmf_key_email_line(array $kh): string
{
    if ($kh['handed']) {
        return 'The keys and access cards were handed over to you on ' . $kh['when'] . '.';
    }
    if ($kh['mode'] === 'scheduled') {
        return 'The keys and access cards will be handed over to you on ' . ($kh['when'] !== '' ? $kh['when'] : 'the agreed date') . '.';
    }
    if ($kh['mode'] === 'on_signing') {
        return 'The keys and access cards will be handed over to you when you sign this report.';
    }
    return 'The keys and access cards are handed over to you at move-in.';
}

function rmf_e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function rmf_date(?string $d, string $fmt = 'd M Y'): string
{
    return $d ? date($fmt, strtotime($d)) : '';
}

/**
 * Form body HTML (no letterhead: mPDF adds it on every page).
 * $photoFiles: [ ['path' => abs path, 'caption' => text], ... ] — empty to leave the photos out.
 */
function rmf_html(array $data, array $photoFiles): string
{
    $mi = $data['move_in'];
    $tenant = trim($mi['first_name'] . ' ' . $mi['last_name']);
    $unit = $mi['unit_number'] . ($mi['unit_type'] ? ' (' . $mi['unit_type'] . ')' : '');
    $inspectedOn = rmf_date($mi['inspection_completed_at'] ?? null) ?: rmf_date(date('Y-m-d'));
    $company = $mi['company_name'] ?: 'Landlord / Property Management';

    $h = '<style>
        body { font-family: dejavusans; font-size: 9pt; color: #111; }
        h1 { font-size: 14pt; text-align: center; margin: 0 0 1mm; letter-spacing: .5pt; }
        .ref { text-align: center; font-size: 8pt; color: #555; margin-bottom: 4mm; }
        h2 { font-size: 10pt; background: #1f2937; color: #fff; padding: 1.5mm 2.5mm; margin: 4mm 0 0; }
        h3 { font-size: 9pt; margin: 3mm 0 1mm; color: #1f2937; border-bottom: .3mm solid #9ca3af; padding-bottom: .8mm; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border: .25mm solid #9ca3af; padding: 1.4mm 2mm; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-size: 8.5pt; }
        td.l { width: 22%; background: #f9fafb; font-weight: bold; }
        td.ok { width: 14mm; text-align: center; font-size: 11pt; color: #15803d; }
        td.no { width: 14mm; text-align: center; color: #9ca3af; }
        td.na { width: 14mm; text-align: center; color: #6b7280; font-size: 7.5pt; font-weight: bold; }
        .muted { color: #6b7280; }
        td.note { height: 14mm; }
        .decl { margin: 3mm 0; line-height: 1.45; }
        table.sig th { width: 50%; }
        table.sig td { height: 9mm; vertical-align: middle; }
        table.sig td.sign { height: 18mm; vertical-align: top; }
        table.keep { page-break-inside: avoid; margin-top: 4mm; }
        td.keepcell { border: none; padding: 0; }
        td.secth { border: none; background: #1f2937; color: #fff; font-weight: bold; font-size: 10pt; padding: 1.5mm 2.5mm; }
        td.declcell { border: none; padding: 3mm 0; line-height: 1.45; }
        table.keep h2 { margin-top: 0; }
        table.sig .muted { font-size: 7.5pt; }
        table.photos td { border: none; width: 50%; padding: 1.5mm; text-align: center; vertical-align: top; }
        .cap { font-size: 7.5pt; color: #4b5563; margin-top: 1mm; }
    </style>';

    $h .= '<h1>MOVE-IN INSPECTION REPORT</h1>';
    $h .= '<div class="ref">Move-In #' . (int)$mi['id'] . ' &middot; Lease ' . rmf_e($mi['lease_number']) . ' &middot; Generated ' . date('d M Y H:i') . '</div>';

    // 1. Move-in details
    $h .= '<h2>1. Move-In Details</h2><table>';
    $h .= '<tr><td class="l">Tenant</td><td>' . rmf_e($tenant) . '</td><td class="l">Move-In Date</td><td>' . rmf_e(rmf_date($mi['move_in_date'])) . '</td></tr>';
    $h .= '<tr><td class="l">Building</td><td>' . rmf_e($mi['building_name']) . '</td><td class="l">Unit</td><td>' . rmf_e($unit) . '</td></tr>';
    $h .= '<tr><td class="l">Lease No.</td><td>' . rmf_e($mi['lease_number']) . '</td><td class="l">Lease Period</td><td>' . rmf_e(rmf_date($mi['lease_start']) . ' to ' . rmf_date($mi['lease_end'])) . '</td></tr>';
    $h .= '<tr><td class="l">Phone</td><td>' . rmf_e($mi['phone']) . '</td><td class="l">Email</td><td>' . rmf_e($mi['email']) . '</td></tr>';
    $h .= '<tr><td class="l">Inspection Date</td><td>' . rmf_e($inspectedOn) . '</td><td class="l">Emirates ID / Passport</td><td>' . rmf_e($mi['id_number']) . '</td></tr>';
    $h .= '</table>';

    // 2. Keys / access granted
    $kh = rmf_key_handover($mi);
    $h .= '<h2>2. Keys &amp; Access ' . ($kh['handed'] || $kh['mode'] === 'move_in' ? 'Granted' : 'To Be Handed Over') . '</h2>';
    if ($data['keys']) {
        $h .= '<table><tr><th>Description</th><th style="width:22mm">Quantity</th><th>Card / Key No.</th></tr>';
        foreach ($data['keys'] as $k) {
            $h .= '<tr><td>' . rmf_e($k['key_label']) . '</td><td style="text-align:center">' . (int)$k['quantity'] . '</td><td>' . rmf_e($k['card_numbers'] ?: '-') . '</td></tr>';
        }
        $h .= '</table>';
        if ($kh['line'] !== '') {
            $h .= '<div class="muted" style="margin-top:1mm">' . rmf_e($kh['line']) . '</div>';
        }
    } else {
        $h .= '<p class="muted">No keys or access cards recorded.</p>';
    }

    // 3. Inspection checklist
    $h .= '<h2>3. Inspection Checklist</h2>';
    foreach ($data['items'] as $secName => $sec) {
        $rows = array_filter($sec['items'], fn($i) => rms_item_kind((string)$i['item_text']) !== 'form');
        if (!$rows) {
            continue;
        }
        // Remarks column only when the item remarks are included
        $withRemarks = !empty($data['include_item_remarks']);
        $h .= '<h3>' . rmf_e($secName) . '</h3><table><tr><th>Item</th><th style="width:14mm;text-align:center">OK</th>'
            . ($withRemarks ? '<th style="width:60mm">Remarks</th>' : '') . '</tr>';
        foreach ($rows as $i) {
            $on = (int)$i['is_checked'] === 1;
            $h .= '<tr><td>' . rmf_e($i['item_text']) . '</td>'
                . ($on && !empty($i['is_na']) ? '<td class="na">N/A</td>' : ($on ? '<td class="ok">&#10004;</td>' : '<td class="no">&#9744;</td>'))
                . ($withRemarks ? '<td>' . rmf_e((string)($i['remark'] ?? '')) . '</td>' : '') . '</tr>';
        }
        $h .= '</table>';
    }

    if ($data['meters']) {
        $h .= '<h3>Meter Readings</h3><table><tr><th>Meter</th><th>Reading</th><th>Meter No.</th><th>Date</th></tr>';
        foreach ($data['meters'] as $m) {
            $h .= '<tr><td>' . rmf_e(ucfirst($m['meter_type'])) . '</td><td>' . rmf_e(number_format((float)$m['reading_value'], 2)) . '</td><td>' . rmf_e($m['meter_number'] ?: '-') . '</td><td>' . rmf_e(rmf_date($m['reading_date'])) . '</td></tr>';
        }
        $h .= '</table>';
    }

    if (!empty($data['include_insp_remarks'])) {
        $h .= '<h3>Inspection Remarks</h3><table><tr><td class="note">' . nl2br(rmf_e($mi['inspection_notes'] ?? '')) . '</td></tr></table>';
    }
    $h .= '<h3>Tenant Comments</h3><table><tr><td class="note">' . nl2br(rmf_e($mi['tenant_comments'] ?? '')) . '</td></tr></table>';
    if (!empty($data['include_services'])) {
        $h .= '<h3>Additional Services &amp; Details</h3><table><tr><td class="note">' . nl2br(rmf_e($mi['additional_services'] ?? '')) . '</td></tr></table>';
    }

    // 4. Declaration and signatures
    // Kept on one page: a split left the last row alone on a new page
    $h .= '<table class="keep"><tr><td class="keepcell">';
    // Tenant name and Emirates ID filled in; a line to write on when missing
    $fill = static fn(string $v, int $len) => trim($v) !== '' ? '<b>' . rmf_e($v) . '</b>' : str_repeat('_', $len);
    $h .= '<table><tr><td class="secth" style="border:none;background:#1f2937;color:#ffffff;font-weight:bold;font-size:10pt;padding:1.5mm 2.5mm">4. Move-In Acknowledgement &amp; Undertaking</td></tr></table>';
    $h .= '<table><tr><td class="declcell">'
        . '<b>Acknowledgement by Tenant</b><br>'
        . 'I, the undersigned ' . $fill($tenant, 30) . ', holding Emirates ID Number ' . $fill((string)$mi['id_number'], 22)
        . ($kh['handed'] || $kh['mode'] === 'move_in'
            ? ', hereby acknowledge that I have received possession of the above-mentioned apartment from ' . rmf_e($company)
                . ', along with the keys, access cards and any other applicable items listed in section 2.<br><br>'
            : ($kh['mode'] === 'on_signing'
                ? ', hereby acknowledge that, upon signing this report, I receive possession of the above-mentioned apartment from ' . rmf_e($company)
                    . ', along with the keys, access cards and any other applicable items listed in section 2.<br><br>'
                : ', hereby acknowledge that I have received possession of the above-mentioned apartment from ' . rmf_e($company)
                    . ', and that the keys, access cards and any other applicable items listed in section 2 will be handed over to me'
                    . ($kh['when'] !== '' ? ' on ' . rmf_e($kh['when']) : ' on the agreed date') . '.<br><br>'))
        . 'I confirm that I have inspected and accepted the apartment in its current condition' . ($photoFiles ? ', as recorded in this report and the attached photos,' : ', as recorded in this report,')
        . ' and undertake to maintain the property in accordance with the tenancy agreement. I also agree to return the apartment, along with all keys, access cards and handed-over items listed in section 2, upon vacating the property, subject to the terms of the tenancy contract.'
        . '</td></tr></table>';
    $h .= '<table class="sig">'
        . '<tr><th>Tenant</th><th>For ' . rmf_e($company) . '</th></tr>'
        // The tenant's signature only goes into the signed copy made when signing
        // ('with_signature'); later previews show an empty box, so it is never
        // shown on content the tenant did not see
        . '<tr><td><span class="muted">Name:</span> ' . rmf_e((!empty($data['with_signature']) ? ($mi['tenant_signed_name'] ?? '') : '') ?: $tenant) . '</td><td><span class="muted">Name:</span></td></tr>'
        . '<tr><td class="sign">' . (!empty($data['with_signature']) ? re_sig_cell_html($mi) : '<span class="muted">Signature:</span>') . '</td><td class="sign"><span class="muted">Signature:</span></td></tr>'
        . '<tr><td><span class="muted">Date:</span> ' . (!empty($data['with_signature']) ? rmf_e(re_sig_date($mi)) : '') . '</td><td><span class="muted">Date:</span></td></tr>'
        . '</table>';
    $h .= '</td></tr></table>';

    // 5. Photos
    if ($photoFiles) {
        $h .= '<pagebreak /><h2>5. Photos of Unit Condition (' . count($photoFiles) . ')</h2><table class="photos">';
        foreach (array_chunk($photoFiles, 2) as $pair) {
            $h .= '<tr>';
            foreach ($pair as $p) {
                $h .= '<td><img src="' . rmf_e($p['path']) . '" style="max-width:85mm;max-height:62mm" /><div class="cap">' . rmf_e($p['caption']) . '</div></td>';
            }
            if (count($pair) === 1) {
                $h .= '<td></td>';
            }
            $h .= '</tr>';
        }
        $h .= '</table>';
    }
    return $h;
}

/**
 * Photos as PDF-ready files: [ ['path' => abs path, 'caption' => text], ... ] plus the
 * temp copies to delete afterwards. $rows: photo rows with photo_path, room_area, uploaded_at.
 */
function rmf_prepare_photos(array $rows, string $tmp): array
{
    $root = rmf_project_root();
    $files = [];
    $temp = [];
    foreach ($rows as $p) {
        $copy = rmf_photo_for_pdf($root . '/' . ltrim((string)$p['photo_path'], '/'), $tmp);
        if (!$copy) {
            continue;
        }
        if ($copy[1]) {
            $temp[] = $copy[0];
        }
        $files[] = ['path' => $copy[0], 'caption' => trim(($p['room_area'] ?: 'Photo') . ' · ' . rmf_date($p['uploaded_at'] ?? null))];
    }
    return [$files, $temp];
}

/**
 * PDF bytes of the form, on the company letterhead (form_letterhead.php).
 * $opts, all off by default: 'meters' (meter readings), 'services' (additional services & details),
 * 'item_remarks' (remark per checklist item), 'insp_remarks' (inspection remarks).
 */
function rmf_pdf(PDO $conn, array $data, bool $includePhotos, array $opts = []): string
{
    if (empty($opts['meters'])) {
        $data['meters'] = [];
    }
    $data['include_services'] = !empty($opts['services']);
    $data['include_item_remarks'] = !empty($opts['item_remarks']);
    $data['include_insp_remarks'] = !empty($opts['insp_remarks']);
    $data['with_signature'] = !empty($opts['with_signature']);
    $tmp = rmf_temp_dir();
    [$photoFiles, $tempFiles] = $includePhotos ? rmf_prepare_photos($data['photos'], $tmp) : [[], []];
    try {
        $mpdf = rfl_mpdf($conn, (int)$data['move_in']['company_id'], $tmp, 'Move-In Inspection Report - ' . ($data['move_in']['lease_number'] ?? ''));
        $mpdf->WriteHTML(rmf_html($data, $photoFiles));
        return $mpdf->Output('', 'S');
    } finally {
        foreach ($tempFiles as $f) {
            @unlink($f);
        }
    }
}
