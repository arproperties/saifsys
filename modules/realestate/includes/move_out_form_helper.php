<?php
/**
 * Move-Out Inspection Form, built on demand from the move-out record:
 *   1. Move-out details   2. Keys returned (against the move-in handover)
 *   3. Inspection checklist (OK / Damaged / N/A) and notes   4. Damages found (no costs)
 *   5. Meter readings   6. Declaration and signatures   7. Photos (optional)
 * The deposit is in the separate Deposit Settlement Statement (rmos_*), the
 * form signed before the move-out is completed.
 * Same letterhead as the move-in form (form_letterhead.php).
 */

require_once __DIR__ . '/move_in_form_helper.php';
require_once __DIR__ . '/security_deposit_helper.php';
require_once __DIR__ . '/move_out_inspection_helper.php';
require_once __DIR__ . '/move_out_keys_helper.php';
require_once __DIR__ . '/re_digital_signature.php';
require_once __DIR__ . '/move_out_charges_helper.php';
require_once __DIR__ . '/move_out_settlement_helper.php';

function rmof_load(PDO $conn, int $companyId, int $moveOutId): ?array
{
    $st = $conn->prepare("
        SELECT mo.*, mon.notice_date, mon.intended_move_out_date,
               l.lease_number, l.start_date AS lease_start, l.end_date AS lease_end,
               u.unit_number, u.unit_type, b.name AS building_name,
               t.first_name, t.last_name, t.phone, t.email, t.id_number,
               c.name AS company_name, uk.username AS keys_returned_by_name
        FROM re_move_outs mo
        JOIN re_leases l ON l.id = mo.lease_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN re_move_out_notices mon ON mon.id = mo.move_out_notice_id
        LEFT JOIN companies c ON c.id = mo.company_id
        LEFT JOIN user uk ON uk.id = mo.keys_returned_by
        WHERE mo.id = ? AND mo.company_id = ?
    ");
    $st->execute([$moveOutId, $companyId]);
    $moveOut = $st->fetch(PDO::FETCH_ASSOC);
    if (!$moveOut) {
        return null;
    }
    $leaseId = (int)$moveOut['lease_id'];

    $damages = $conn->prepare("SELECT * FROM re_move_out_damages WHERE move_out_id = ? ORDER BY assessed_at, id");
    $damages->execute([$moveOutId]);
    $meters = $conn->prepare("SELECT * FROM re_meter_readings WHERE lease_id = ? AND reading_type = 'move_out' ORDER BY meter_type, reading_date DESC");
    $meters->execute([$leaseId]);
    $photos = $conn->prepare("SELECT * FROM re_move_out_photos WHERE move_out_id = ?" . rmo_photo_only_sql($conn) . " ORDER BY uploaded_at, id");
    $photos->execute([$moveOutId]);

    // Keys handed over at move-in (stage workflow), to check them back in
    $keys = [];
    if (rms_ready($conn)) {
        $k = $conn->prepare("
            SELECT k.key_label, k.quantity, k.card_numbers
            FROM re_move_in_keys k
            JOIN re_move_ins mi ON mi.id = k.move_in_id
            WHERE mi.lease_id = ? AND mi.company_id = ? AND mi.status <> 'cancelled' AND k.quantity > 0
            ORDER BY mi.id DESC, k.display_order, k.id
        ");
        $k->execute([$leaseId, $companyId]);
        $keys = $k->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $deposit = null;
    try {
        $deposit = [
            'summary' => re_sd_summary($conn, $companyId, re_sd_deposit_lease_id($conn, $companyId, $leaseId)),
            'settlement' => re_sd_settlement_for_move_out($conn, $companyId, $moveOutId) ?: null,
        ];
    } catch (Throwable $e) {
        error_log('Move-out form deposit summary failed: ' . $e->getMessage());
    }

    $damageRows = $damages->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $photoRows = $photos->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Damage photos first, captioned with their damage; then the unit's condition
    $damageById = array_column($damageRows, null, 'id');
    $damagePhotos = [];
    $conditionPhotos = [];
    foreach ($photoRows as $p) {
        $d = !empty($p['damage_id']) ? ($damageById[(int)$p['damage_id']] ?? null) : null;
        if ($d) {
            $p['room_area'] = trim(($p['room_area'] ?: ($d['room_area'] ?: '')) . ' - Damage: ' . $d['damage_description'], ' -');
            $damagePhotos[] = $p;
        } else {
            $conditionPhotos[] = $p;
        }
    }
    $photoRows = array_merge($damagePhotos, $conditionPhotos);
    // Damage photos not already in the photo list
    $known = array_column($photoRows, 'photo_path');
    foreach ($damageRows as $d) {
        if (!empty($d['photo_path']) && !in_array($d['photo_path'], $known, true)) {
            $photoRows[] = ['photo_path' => $d['photo_path'], 'room_area' => trim(($d['room_area'] ?: 'Damage') . ' - damage'), 'uploaded_at' => $d['assessed_at'] ?: $d['created_at']];
        }
    }

    $checklist = [];
    if (rmoi_ready($conn)) {
        $checklist = rmoi_items($conn, $moveOutId);
    }

    // Key return rows (Key Return step); older move-outs fall back to the move-in keys
    $returnKeys = rmok_ready($conn) ? rmok_keys($conn, $moveOutId) : [];

    return [
        'move_out' => $moveOut,
        'checklist' => $checklist,
        'return_keys' => $returnKeys,
        'damages' => $damageRows,
        'meters' => $meters->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'photos' => $photoRows,
        'keys' => $keys,
        'deposit' => $deposit,
    ];
}

function rmof_file_name(array $data): string
{
    $ref = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($data['move_out']['lease_number'] ?: ('MO-' . $data['move_out']['id'])));
    return 'Move_Out_Inspection_' . trim($ref, '-') . '.pdf';
}

/** Styles shared by the inspection report and the settlement statement. */
function rmof_css(): string
{
    return '<style>
        body { font-family: dejavusans; font-size: 9pt; color: #111; }
        h1 { font-size: 14pt; text-align: center; margin: 0 0 1mm; letter-spacing: .5pt; }
        .ref { text-align: center; font-size: 8pt; color: #555; margin-bottom: 4mm; }
        h2 { font-size: 10pt; background: #1f2937; color: #fff; padding: 1.5mm 2.5mm; margin: 4mm 0 0; }
        h3 { font-size: 9pt; margin: 3mm 0 1mm; color: #1f2937; border-bottom: .3mm solid #9ca3af; padding-bottom: .8mm; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border: .25mm solid #9ca3af; padding: 1.4mm 2mm; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-size: 8.5pt; }
        td.l { width: 22%; background: #f9fafb; font-weight: bold; }
        td.r, th.r { text-align: right; }
        td.c, th.c { text-align: center; }
        td.note { height: 14mm; }
        .muted { color: #6b7280; }
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
}

function rmof_html(array $data, array $photoFiles): string
{
    $mo = $data['move_out'];
    $tenant = trim($mo['first_name'] . ' ' . $mo['last_name']);
    $unit = $mo['unit_number'] . ($mo['unit_type'] ? ' (' . $mo['unit_type'] . ')' : '');
    $company = $mo['company_name'] ?: 'Landlord / Property Management';
    $inspected = rmf_date($mo['inspection_completed_at'] ?? null) ?: rmf_date($mo['inspection_scheduled_date'] ?? null);

    $h = rmof_css();

    $sec = 0; // section numbers, in order (some sections are left out when empty)
    $h .= '<h1>MOVE-OUT INSPECTION REPORT</h1>';
    $h .= '<div class="ref">Move-Out #' . (int)$mo['id'] . ' &middot; Lease ' . rmf_e($mo['lease_number']) . ' &middot; Generated ' . date('d M Y H:i') . '</div>';

    $h .= '<h2>' . (++$sec) . '. Move-Out Details</h2><table>';
    $h .= '<tr><td class="l">Tenant</td><td>' . rmf_e($tenant) . '</td><td class="l">Move-Out Date</td><td>' . rmf_e(rmf_date($mo['actual_move_out_date'])) . '</td></tr>';
    $h .= '<tr><td class="l">Building</td><td>' . rmf_e($mo['building_name']) . '</td><td class="l">Unit</td><td>' . rmf_e($unit) . '</td></tr>';
    $h .= '<tr><td class="l">Lease No.</td><td>' . rmf_e($mo['lease_number']) . '</td><td class="l">Lease Period</td><td>' . rmf_e(rmf_date($mo['lease_start']) . ' to ' . rmf_date($mo['lease_end'])) . '</td></tr>';
    $h .= '<tr><td class="l">Notice Date</td><td>' . rmf_e(rmf_date($mo['notice_date'] ?? null) ?: '-') . '</td><td class="l">Inspection Date</td><td>' . rmf_e($inspected ?: '-') . '</td></tr>';
    $h .= '<tr><td class="l">Phone</td><td>' . rmf_e($mo['phone']) . '</td><td class="l">Email</td><td>' . rmf_e($mo['email']) . '</td></tr>';
    $h .= '</table>';

    // 2. Keys returned
    $h .= '<h2>' . (++$sec) . '. Keys &amp; Access Returned</h2>';
    if (!empty($data['return_keys'])) {
        $sum = rmok_summary($data['return_keys']);
        $h .= '<table><tr><th>Description</th><th class="c" style="width:26mm">Issued at move-in</th><th class="c" style="width:20mm">Returned</th><th>Card / Key No.</th><th>Note</th></tr>';
        foreach ($data['return_keys'] as $k) {
            $short = $k['issued_qty'] !== null && $k['returned_qty'] !== null && (int)$k['returned_qty'] < (int)$k['issued_qty'];
            $h .= '<tr><td>' . rmf_e($k['key_label']) . '</td><td class="c">' . ($k['issued_qty'] === null ? '-' : (int)$k['issued_qty']) . '</td>'
                . '<td class="c"' . ($short ? ' style="color:#b91c1c;font-weight:bold"' : '') . '>' . ($k['returned_qty'] === null ? '' : (int)$k['returned_qty']) . '</td>'
                . '<td>' . rmf_e($k['card_numbers'] ?: '-') . '</td><td>' . rmf_e($k['note'] ?? '') . '</td></tr>';
        }
        $h .= '</table>';
        if ($sum['missing']) {
            $h .= '<div style="margin-top:1mm;color:#b91c1c"><b>Not returned:</b> ' . rmf_e(rmok_missing_text($sum['missing'])) . '</div>';
        }
    } elseif ($data['keys']) {
        $h .= '<table><tr><th>Description</th><th class="c" style="width:28mm">Handed over at move-in</th><th class="c" style="width:24mm">Returned</th><th>Card / Key No.</th></tr>';
        foreach ($data['keys'] as $k) {
            $h .= '<tr><td>' . rmf_e($k['key_label']) . '</td><td class="c">' . (int)$k['quantity'] . '</td><td class="c">' . (!empty($mo['keys_returned']) ? (int)$k['quantity'] : '') . '</td><td>' . rmf_e($k['card_numbers'] ?: '-') . '</td></tr>';
        }
        $h .= '</table>';
    } else {
        $h .= '<table><tr><th>Description</th><th class="c" style="width:24mm">Quantity</th><th>Card / Key No.</th></tr>';
        for ($i = 0; $i < 3; $i++) {
            $h .= '<tr><td style="height:7mm"></td><td></td><td></td></tr>';
        }
        $h .= '</table>';
    }
    $h .= '<div class="muted" style="margin-top:1mm">' . (!empty($mo['keys_returned'])
        ? 'Key return confirmed on ' . rmf_e(rmf_date($mo['keys_returned_at'], 'd M Y H:i')) . (!empty($mo['keys_returned_by_name']) ? ', received by ' . rmf_e($mo['keys_returned_by_name']) : '')
        : 'Keys not yet recorded as returned.') . '</div>';

    // 3. Inspection checklist (each item OK / Damaged / N/A)
    $h .= '<h2>' . (++$sec) . '. Final Inspection</h2>';
    if (!empty($data['checklist'])) {
        $cnt = rmoi_counts($data['checklist']);
        $h .= '<div class="muted" style="margin:1.5mm 0">' . ($cnt['total'] - $cnt['open']) . ' of ' . $cnt['total'] . ' items checked: '
            . $cnt['ok'] . ' OK, ' . $cnt['damaged'] . ' damaged, ' . $cnt['na'] . ' not applicable.</div>';
        $h .= '<table><tr><th>Item</th><th class="c" style="width:22mm">Condition</th><th style="width:55mm">Remark</th></tr>';
        foreach ($data['checklist'] as $secName => $secRow) {
            $h .= '<tr><td colspan="3" style="background:#e5e7eb;font-weight:bold">' . rmf_e($secName) . '</td></tr>';
            foreach ($secRow['items'] as $it) {
                $st = (string)$it['status'];
                $color = ['ok' => '#166534', 'damaged' => '#b91c1c', 'na' => '#6b7280', 'open' => '#92400e'][$st] ?? '#111';
                $remark = (string)($it['remark'] ?? '');
                if ($st === 'damaged' && $it['damage_id']) {
                    $remark = trim('See damages found. ' . $remark);
                }
                $h .= '<tr><td>' . rmf_e($it['item_text']) . '</td><td class="c" style="color:' . $color . ';font-weight:bold">'
                    . rmf_e(RMOI_STATUS_LABELS[$st] ?? $st) . '</td><td>' . rmf_e($remark) . '</td></tr>';
            }
        }
        $h .= '</table>';
    }
    $h .= '<h3>Inspection Notes</h3><table><tr><td class="note">' . nl2br(rmf_e($mo['final_inspection_notes'] ?? '')) . '</td></tr></table>';

    // 4. Damages found (no costs: those are in the Deposit Settlement Statement)
    $h .= '<h2>' . (++$sec) . '. Damages Found</h2>';
    if ($data['damages']) {
        $h .= '<table><tr><th style="width:8mm" class="c">#</th><th style="width:32mm">Area</th><th>Description</th><th style="width:22mm">Severity</th></tr>';
        foreach (array_values($data['damages']) as $n => $d) {
            $h .= '<tr><td class="c">' . ($n + 1) . '</td><td>' . rmf_e($d['room_area'] ?: '-') . '</td><td>' . rmf_e(rtrim((string)$d['damage_description'], ' -')) . ($d['notes'] ? '<div class="muted">' . rmf_e($d['notes']) . '</div>' : '') . '</td><td>' . rmf_e(ucfirst($d['damage_type'])) . '</td></tr>';
        }
        $h .= '</table>';
        $h .= '<div class="muted" style="margin-top:1mm">Repair costs and how they are set against the security deposit are shown in the Deposit Settlement Statement.</div>';
    } else {
        $h .= '<p class="muted">No damages recorded.</p>';
    }

    if ($data['meters']) {
        $h .= '<h2>' . (++$sec) . '. Final Meter Readings</h2><table><tr><th>Meter</th><th>Reading</th><th>Meter No.</th><th>Date</th></tr>';
        foreach ($data['meters'] as $m) {
            $h .= '<tr><td>' . rmf_e(ucfirst($m['meter_type'])) . '</td><td>' . rmf_e(number_format((float)$m['reading_value'], 2)) . '</td><td>' . rmf_e($m['meter_number'] ?: '-') . '</td><td>' . rmf_e(rmf_date($m['reading_date'])) . '</td></tr>';
        }
        $h .= '</table>';
    }

    // 6. Declaration and signatures
    // Kept on one page: a split left the last row alone on a new page
    $h .= '<table class="keep"><tr><td class="keepcell">';
    // Tenant name and Emirates ID filled in; a line to write on when missing
    $fill = static fn(string $v, int $len) => trim($v) !== '' ? '<b>' . rmf_e($v) . '</b>' : str_repeat('_', $len);
    $h .= '<table><tr><td class="secth" style="border:none;background:#1f2937;color:#ffffff;font-weight:bold;font-size:10pt;padding:1.5mm 2.5mm">' . (++$sec) . '. Move-Out Undertaking</td></tr></table>';
    $h .= '<table><tr><td class="declcell">'
        . 'I, the undersigned ' . $fill($tenant, 30) . ', holding Emirates ID Number ' . $fill((string)$mo['id_number'], 22)
        . ', a tenant of the aforementioned apartment, hereby confirm my undertaking to hand over the apartment, along with the keys and access cards listed in section 2, to the owner&#39;s representative ' . rmf_e($company) . '.<br><br>'
        . 'I understand that I shall no longer have access to the above premises or to any belongings left inside. Furthermore, I recognize and respect their right to conduct a final inspection and quotation for general move-out maintenance, as stipulated in the contract. '
        . 'I also confirm that I have no objection to any reports prepared in relation to the apartment&#39;s condition' . ($photoFiles ? ', including the attached photos,' : '') . ' along with the inventory list stated as previously handed over (if applicable).'
        . '</td></tr></table>';
    $h .= '<table class="sig">'
        . '<tr><th>Tenant</th><th>For ' . rmf_e($company) . '</th></tr>'
        // Signature only in the signed copy made when signing (see move_in_form_helper)
        . '<tr><td><span class="muted">Name:</span> ' . rmf_e((!empty($data['with_signature']) ? ($mo['tenant_signed_name'] ?? '') : '') ?: $tenant) . '</td><td><span class="muted">Name:</span></td></tr>'
        . '<tr><td class="sign">' . (!empty($data['with_signature']) ? re_sig_cell_html($mo) : '<span class="muted">Signature:</span>') . '</td><td class="sign"><span class="muted">Signature:</span></td></tr>'
        . '<tr><td><span class="muted">Date:</span> ' . (!empty($data['with_signature']) ? rmf_e(re_sig_date($mo)) : '') . '</td><td><span class="muted">Date:</span></td></tr>'
        . '</table>';
    $h .= '</td></tr></table>';

    if ($photoFiles) {
        $h .= '<pagebreak /><h2>' . (++$sec) . '. Photos of Unit Condition (' . count($photoFiles) . ')</h2><table class="photos">';
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

/** $opts: 'meters' (final meter readings), off by default. */
function rmof_pdf(PDO $conn, array $data, bool $includePhotos, array $opts = []): string
{
    if (empty($opts['meters'])) {
        $data['meters'] = [];
    }
    $data['with_signature'] = !empty($opts['with_signature']);
    $tmp = rmf_temp_dir();
    [$photoFiles, $tempFiles] = $includePhotos ? rmf_prepare_photos($data['photos'], $tmp) : [[], []];
    try {
        $mpdf = rfl_mpdf($conn, (int)$data['move_out']['company_id'], $tmp, 'Move-Out Inspection Report - ' . ($data['move_out']['lease_number'] ?? ''));
        $mpdf->WriteHTML(rmof_html($data, $photoFiles));
        return $mpdf->Output('', 'S');
    } finally {
        foreach ($tempFiles as $f) {
            @unlink($f);
        }
    }
}

/* ------------------------------------------------------------------------
 * Deposit Settlement Statement: the second move-out form, prepared before the
 * move-out is completed (no acknowledgement or signatures). What the tenant paid as security deposit, each
 * deduction (damages itemised), the refund and how it was paid, and any
 * damage the deposit did not cover.
 * --------------------------------------------------------------------- */

const RMOS_DEDUCTION_LABELS = [
    'damage' => 'Damages', 'cleaning' => 'Cleaning', 'repair' => 'Repairs', 'unpaid_invoice_offset' => 'Unpaid invoice offset',
    'admin_fee' => 'Administration fee', 'forfeiture' => 'Forfeiture', 'other' => 'Other',
];

/** rmof_load() plus the settlement breakdown and who marked it complete. */
function rmos_load(PDO $conn, int $companyId, int $moveOutId): ?array
{
    $data = rmof_load($conn, $companyId, $moveOutId);
    if (!$data) {
        return null;
    }
    $data['figures'] = rmst_figures($conn, $companyId, $data['move_out']);
    $data['completed_by_name'] = null;
    if (!empty($data['move_out']['settlement_completed_by'])) {
        $u = $conn->prepare("SELECT username FROM user WHERE id = ?");
        $u->execute([(int)$data['move_out']['settlement_completed_by']]);
        $data['completed_by_name'] = $u->fetchColumn() ?: null;
    }
    return $data;
}

/** Draft while a damage or charge has no amount yet (the statement goes to Accounts before completion). */
function rmos_is_draft(array $data): bool
{
    return !rmst_done($data['move_out']) && $data['figures']['pending'] > 0;
}
function rmos_file_name(array $data): string
{
    $ref = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($data['move_out']['lease_number'] ?: ('MO-' . $data['move_out']['id'])));
    return 'Deposit_Settlement_' . trim($ref, '-') . '.pdf';
}

function rmos_html(array $data): string
{
    $mo = $data['move_out'];
    $tenant = trim($mo['first_name'] . ' ' . $mo['last_name']);
    $unit = $mo['unit_number'] . ($mo['unit_type'] ? ' (' . $mo['unit_type'] . ')' : '');
    $company = $mo['company_name'] ?: 'Landlord / Property Management';
    $money = static fn($v) => number_format((float)$v, 2) . ' AED';
    $fig = $data['figures'];
    $sec = 0;

    $h = rmof_css();
    $h .= '<h1>SECURITY DEPOSIT SETTLEMENT STATEMENT</h1>';
    $h .= '<div class="ref">Move-Out #' . (int)$mo['id'] . ' &middot; Lease ' . rmf_e($mo['lease_number']) . ' &middot; Generated ' . date('d M Y H:i')
        . (rmos_is_draft($data) ? ' &middot; <b style="color:#b91c1c">DRAFT - some amounts not entered yet</b>' : '') . '</div>';

    // 1. Details
    $h .= '<h2>' . (++$sec) . '. Tenancy Details</h2><table>';
    $h .= '<tr><td class="l">Tenant</td><td>' . rmf_e($tenant) . '</td><td class="l">Move-Out Date</td><td>' . rmf_e(rmf_date($mo['actual_move_out_date'])) . '</td></tr>';
    $h .= '<tr><td class="l">Building</td><td>' . rmf_e($mo['building_name']) . '</td><td class="l">Unit</td><td>' . rmf_e($unit) . '</td></tr>';
    $h .= '<tr><td class="l">Lease No.</td><td>' . rmf_e($mo['lease_number']) . '</td><td class="l">Lease Period</td><td>' . rmf_e(rmf_date($mo['lease_start']) . ' to ' . rmf_date($mo['lease_end'])) . '</td></tr>';
    $h .= '<tr><td class="l">Inspection</td><td>' . rmf_e(rmf_date($mo['inspection_completed_at'] ?? null) ?: '-') . '</td><td class="l">Keys Returned</td><td>' . rmf_e(!empty($mo['keys_returned']) ? (rmf_date($mo['keys_returned_at'] ?? null) ?: 'Yes') : 'No') . '</td></tr>';
    $h .= '</table>';

    // 2. Deposit held
    $h .= '<h2>' . (++$sec) . '. Security Deposit</h2><table>';
    $h .= '<tr><td class="l">Deposit per lease</td><td class="r">' . $money($fig['expected']) . '</td><td class="l">Deposit received</td><td class="r">' . $money($fig['received']) . '</td></tr>';
    $h .= '</table>';

    // 3. Damages itemised, then the standard move-out charges
    $h .= '<h2>' . (++$sec) . '. Damages &amp; Charges</h2>';
    $h .= '<table><tr><th style="width:8mm" class="c">#</th><th>Item</th><th style="width:30mm">Type</th><th class="r" style="width:30mm">Amount</th></tr>';
    $n = 0;
    foreach ($fig['damages'] as [$label, $amt, $type]) {
        $h .= '<tr><td class="c">' . (++$n) . '</td><td>' . rmf_e($label) . '</td><td>Damage (' . rmf_e($type) . ')</td><td class="r">'
            . ($amt === null ? '<span style="color:#b45309">Not costed yet</span>' : $money($amt)) . '</td></tr>';
    }
    foreach ($fig['charges'] as [$label, $amt, $note]) {
        $h .= '<tr><td class="c">' . (++$n) . '</td><td>' . rmf_e($label) . ($note !== '' ? '<div class="muted">' . rmf_e($note) . '</div>' : '')
            . '</td><td>Standard charge</td><td class="r">' . ($amt === null ? '<span style="color:#b45309">Not entered yet</span>' : $money($amt)) . '</td></tr>';
    }
    if ($n === 0) {
        $h .= '<tr><td colspan="4" class="muted">No damages or charges.</td></tr>';
    }
    $h .= '<tr><th colspan="3" class="r">Total</th><th class="r">' . $money($fig['total']) . '</th></tr>';
    $h .= '</table>';

    // 4. Settlement
    $h .= '<h2>' . (++$sec) . '. Settlement</h2><table>';
    $h .= '<tr><td class="l" style="width:60%">Security deposit received</td><td class="r">' . $money($fig['received']) . '</td></tr>';
    $h .= '<tr><td class="l">Damages and charges</td><td class="r">- ' . $money($fig['total']) . '</td></tr>';
    $h .= '<tr><th>Refund due to the tenant</th><th class="r">' . $money($fig['refund']) . '</th></tr>';
    if ($fig['payable'] > 0) {
        $h .= '<tr><td class="l" style="color:#b91c1c">Not covered by the deposit, payable by the tenant</td><td class="r" style="color:#b91c1c;font-weight:bold">' . $money($fig['payable']) . '</td></tr>';
    }
    $done = !empty($mo['settlement_completed_at']);
    $h .= '<tr><td class="l">Settlement status</td><td>' . rmf_e($done
        ? 'Complete, ' . rmf_date($mo['settlement_completed_at'], 'd M Y') . ($data['completed_by_name'] ? ' by ' . $data['completed_by_name'] : '')
        : (rmst_done($mo) ? 'Complete' : 'Pending - with Accounts for booking')) . '</td></tr>';
    if (!empty($mo['settlement_note'])) {
        $h .= '<tr><td class="l">Note</td><td>' . rmf_e($mo['settlement_note']) . '</td></tr>';
    }
    $h .= '</table>';
    return $h;
}

function rmos_pdf(PDO $conn, array $data): string
{
    $tmp = rmf_temp_dir();
    $mpdf = rfl_mpdf($conn, (int)$data['move_out']['company_id'], $tmp, 'Deposit Settlement Statement - ' . ($data['move_out']['lease_number'] ?? ''));
    if (rmos_is_draft($data)) {
        $mpdf->SetWatermarkText('DRAFT');
        $mpdf->showWatermarkText = true;
    }
    $mpdf->WriteHTML(rmos_html($data));
    return $mpdf->Output('', 'S');
}
