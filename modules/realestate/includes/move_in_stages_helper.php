<?php
/**
 * Move-In stages (workflow_version = 2):
 *   1. Contract Signed      re_move_ins.contract_verified (+ _by / _at), or automatically
 *                           when staff uploaded a Tenancy Contract document to the lease
 *                           (re_documents, type TENANCY_CONTRACT; tenant portal scans do
 *                           not count: they are not signed by the landlord yet)
 *   2. Payment Confirmed    automatic: deposit received + first rent installment
 *                           received (from the lease); Owner/Admin override
 *   3. Inspection           every item of every section ticked or N/A
 *                           (re_move_in_inspection_items), then "Complete inspection"
 *                           -> inspection_completed. A completed inspection is locked;
 *                           "Edit inspection" (with a reason) reopens it
 *   4. Key Handover         key rows (re_move_in_keys) + keys_handed_over
 *   5. Inspection Report    generated from stages 3 + 4 (move_in_form.php), sent to
 *                           the tenant, done when the signed copy is uploaded
 *                           (re_move_in_photos.file_type = 'inspection_report').
 *                           Not needed to complete the move-in: the tenant may
 *                           return the signed form later.
 *
 * Key handover timing (key_handover_mode, migrations/re_move_in_key_handover.sql):
 *   move_in    keys confirmed in step 4, then the form (step 5) opens
 *   scheduled  form opens once the keys are listed; confirmed on the date
 *   on_signing form opens once the keys are listed; uploading the signed
 *              form confirms the handover
 *
 * Two Final Walkthrough items are linked (rms_item_kind):
 *   photos -> can only be ticked once a move-in photo is uploaded
 *   form   -> "inspection form completed and signed": step 5 does this, so the
 *             item is no longer copied into new move-ins nor shown; older
 *             move-ins keep the row (ticked by step 5, not counted)
 *
 * A stage can only be worked on when every earlier stage is done, and only
 * undone while no later stage is done.
 *
 * Requires migrations/re_move_in_stages.sql (and re_move_in_form.sql for the
 * "sent to tenant" record).
 */

require_once __DIR__ . '/security_deposit_helper.php';

const RMS_DEFAULT_KEYS = ['Main Door Key', 'MDF Cabinet', 'Access Card', 'Parking Access Card', 'Others'];

const RMS_DEFAULT_TEMPLATE = [
    'General Condition' => [
        'Apartment is clean and in good condition',
        'No major wall damage (holes, stains, etc.)',
        'AC unit is cooling properly',
        'AC thermostat working and functional',
        'No foul odor; space is well-ventilated',
        'Floors clean and undamaged',
    ],
    'Lighting & Electrical' => [
        'All light fixtures/bulbs functional',
        'All power sockets tested and working',
        'Switches and circuit breakers working properly',
        'Water heater working',
    ],
    'Kitchen' => [
        'Countertops, sink, and cabinets clean and undamaged',
        'Exhaust/hood filter cleaned',
        'Sink clean; no leaks; strainer clear',
        'All cabinets clean and functional',
    ],
    'Unit' => [
        'Room clean and free of personal items',
        'Walls clean; no holes, scratches, or stains',
        'Floor clean and undamaged',
        'Built-in wardrobe/cabinets clean and functional',
        'Light fixtures working',
        'AC unit cooling properly',
        'AC thermostat working and functional',
        'Windows and locks working',
        'Drawers open/close properly',
    ],
    'Bathroom' => [
        'Toilets flushed and clean',
        'Bidet and bidet shower working, and fixtures in good condition',
        'Sink, shower, and faucets functioning without leaks',
        'Mirrors, shelves, and fixtures in good condition',
        'No molds or water damage',
        'Drains clear and not clogged',
        'No foul odor; space is well-ventilated',
    ],
    'Additional Areas' => [
        'Balcony/terrace cleaned, no debris',
        'Windows and sliding doors clean and functional',
        "Storage rooms/maid's room empty and clean",
        'Parking slot clean (if applicable)',
    ],
    'Final Walkthrough' => [
        'Walkthrough conducted with tenant and the maintenance service provider M/s Al Mezaya Technical Services',
        'Photos/videos taken of current condition',
    ],
];

function rms_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->query("SELECT 1 FROM re_move_in_inspection_items LIMIT 1");
        $col = $conn->query("SHOW COLUMNS FROM re_move_ins LIKE 'workflow_version'")->fetch();
        $ready = (bool)$col;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

const RMS_KEY_MODES = [
    'move_in' => 'On move-in',
    'scheduled' => 'On a specific date',
    'on_signing' => 'After the tenant signs the inspection form',
];

/** key_handover_mode columns exist (migrations/re_move_in_key_handover.sql). */
function rms_key_mode_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_ins LIKE 'key_handover_mode'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function rms_key_mode(array $moveIn): string
{
    $m = (string)($moveIn['key_handover_mode'] ?? 'move_in');
    return isset(RMS_KEY_MODES[$m]) ? $m : 'move_in';
}

/** "Not applicable" column exists (migrations/re_move_in_item_na.sql). */
function rms_na_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_in_inspection_items LIKE 'is_na'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Item remark column exists (migrations/re_move_in_item_remarks.sql). */
function rms_remark_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_in_inspection_items LIKE 'remark'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Form "sent to tenant" columns exist (migrations/re_move_in_form.sql). */
function rms_form_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_ins LIKE 'form_sent_at'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Inspection items linked to another part of the page, by their text:
 * 'form' (inspection form completed and signed), 'photos' (photos taken), or null.
 */
function rms_item_kind(string $text): ?string
{
    $t = strtolower($text);
    if (strpos($t, 'inspection form') !== false && strpos($t, 'sign') !== false) {
        return 'form';
    }
    if (strpos($t, 'photo') !== false && (strpos($t, 'taken') !== false || strpos($t, 'condition') !== false)) {
        return 'photos';
    }
    return null;
}

/** Latest Tenancy Contract uploaded to the lease (staff upload), or null. */
function rms_contract_document(PDO $conn, int $companyId, int $leaseId): ?array
{
    try {
        $st = $conn->prepare("
            SELECT d.id, d.file_name, d.file_path, d.created_at, u.username AS uploaded_by_name
            FROM re_documents d
            LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
            LEFT JOIN user u ON u.id = d.uploaded_by
            WHERE d.related_type = 'lease' AND d.related_id = ? AND d.company_id = ? AND d.status = 'active'
              AND (dt.document_type_code = 'TENANCY_CONTRACT' OR d.document_type = 'lease_agreement')
            ORDER BY d.created_at DESC, d.id DESC LIMIT 1
        ");
        $st->execute([$leaseId, $companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null; // documents module not installed
    }
}

/** Uploaded move-in files: ['photo' => n, 'inspection_report' => n] */
function rms_file_counts(PDO $conn, int $moveInId): array
{
    $st = $conn->prepare("SELECT file_type, COUNT(*) FROM re_move_in_photos WHERE move_in_id = ? GROUP BY file_type");
    $st->execute([$moveInId]);
    $out = ['photo' => 0, 'inspection_report' => 0];
    foreach ($st->fetchAll(PDO::FETCH_NUM) ?: [] as [$type, $n]) {
        $out[$type] = (int)$n;
    }
    return $out;
}

/** Tick / untick the "form completed and signed" item(s) to match the uploaded signed form. */
function rms_sync_form_item(PDO $conn, int $moveInId, ?int $userId): void
{
    $signed = rms_file_counts($conn, $moveInId)['inspection_report'] > 0 ? 1 : 0;
    $st = $conn->prepare("SELECT id, item_text FROM re_move_in_inspection_items WHERE move_in_id = ? AND is_checked <> ?");
    $st->execute([$moveInId, $signed]);
    $upd = $conn->prepare("UPDATE re_move_in_inspection_items SET is_checked = ?, checked_by = ?, checked_at = ?, note = ? WHERE id = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        if (rms_item_kind((string)$r['item_text']) === 'form') {
            $upd->execute([$signed, $signed ? $userId : null, $signed ? date('Y-m-d H:i:s') : null, $signed ? 'Signed form uploaded' : null, (int)$r['id']]);
        }
    }
}

/** Seed the default template for a company that has none (call inside a POST). */
function rms_ensure_template(PDO $conn, int $companyId): void
{
    $st = $conn->prepare("SELECT COUNT(*) FROM re_move_in_inspection_sections WHERE company_id = ?");
    $st->execute([$companyId]);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    $sec = $conn->prepare("INSERT INTO re_move_in_inspection_sections (company_id, name, display_order) VALUES (?, ?, ?)");
    $itm = $conn->prepare("INSERT INTO re_move_in_inspection_template_items (company_id, section_id, item_text, display_order) VALUES (?, ?, ?, ?)");
    $so = 0;
    foreach (RMS_DEFAULT_TEMPLATE as $name => $items) {
        $sec->execute([$companyId, $name, ++$so]);
        $sid = (int)$conn->lastInsertId();
        foreach ($items as $io => $text) {
            $itm->execute([$companyId, $sid, $text, $io + 1]);
        }
    }
}

/** Active template: [ ['id','name','display_order','items'=>[...]], ... ] */
function rms_template(PDO $conn, int $companyId, bool $includeInactive = false): array
{
    $st = $conn->prepare("SELECT * FROM re_move_in_inspection_sections WHERE company_id = ?" . ($includeInactive ? '' : ' AND is_active = 1') . " ORDER BY display_order, id");
    $st->execute([$companyId]);
    $sections = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $it = $conn->prepare("SELECT * FROM re_move_in_inspection_template_items WHERE section_id = ?" . ($includeInactive ? '' : ' AND is_active = 1') . " ORDER BY display_order, id");
    foreach ($sections as &$s) {
        $it->execute([(int)$s['id']]);
        $s['items'] = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($s);
    return $sections;
}

/** Give a new move-in its own copy of the template and the key rows; mark it version 2. */
function rms_create_for_move_in(PDO $conn, int $companyId, int $moveInId, ?int $userId): void
{
    rms_ensure_template($conn, $companyId);
    $ins = $conn->prepare("
        INSERT INTO re_move_in_inspection_items
            (company_id, move_in_id, section_name, section_order, item_text, item_order, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach (rms_template($conn, $companyId) as $s) {
        foreach ($s['items'] as $i) {
            if (rms_item_kind((string)$i['item_text']) === 'form') {
                continue; // the signed form is step 5, not a checklist item
            }
            $ins->execute([$companyId, $moveInId, $s['name'], (int)$s['display_order'], $i['item_text'], (int)$i['display_order'], $userId]);
        }
    }
    $key = $conn->prepare("INSERT INTO re_move_in_keys (company_id, move_in_id, key_label, display_order) VALUES (?, ?, ?, ?)");
    foreach (RMS_DEFAULT_KEYS as $o => $label) {
        $key->execute([$companyId, $moveInId, $label, $o + 1]);
    }
    $conn->prepare("UPDATE re_move_ins SET workflow_version = 2 WHERE id = ?")->execute([$moveInId]);
}

/** Inspection items grouped by section: [ section_name => ['order'=>n, 'items'=>[...]] ] */
function rms_items(PDO $conn, int $moveInId): array
{
    $st = $conn->prepare("
        SELECT ii.*, u.username AS checked_by_name
        FROM re_move_in_inspection_items ii
        LEFT JOIN user u ON u.id = ii.checked_by
        WHERE ii.move_in_id = ?
        ORDER BY ii.section_order, ii.section_name, ii.item_order, ii.id
    ");
    $st->execute([$moveInId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $out[$r['section_name']]['order'] = (int)$r['section_order'];
        $out[$r['section_name']]['items'][] = $r;
    }
    return $out;
}

function rms_keys(PDO $conn, int $moveInId): array
{
    $st = $conn->prepare("SELECT * FROM re_move_in_keys WHERE move_in_id = ? ORDER BY display_order, id");
    $st->execute([$moveInId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Payment status read from the lease.
 * deposit: from the security-deposit tracking (re_sd_summary); not required when none expected.
 * first:   first row of the payment schedule (as on the lease page); without a schedule,
 *          the earliest rent obligation (invoice mode).
 */
function rms_payment_status(PDO $conn, int $companyId, array $lease): array
{
    $leaseId = (int)$lease['id'];
    $sd = re_sd_summary($conn, $companyId, $leaseId);
    $depExpected = (float)($sd['expected'] ?? 0);
    $depReceived = (float)($sd['allocated'] ?? 0);
    $deposit = [
        'expected' => $depExpected,
        'received' => $depReceived,
        'required' => $depExpected > 0,
        'done' => $depExpected <= 0 || $depReceived + 0.005 >= $depExpected,
    ];

    $first = ['found' => false, 'due' => null, 'amount' => 0.0, 'received' => 0.0, 'done' => false, 'source' => null];
    $mode = (string)($lease['accounting_mode'] ?? '');

    // 1. The tenant's first installment is the first row of the payment schedule
    //    (re_lease_installments), the row shown first on the lease page. It may carry
    //    the deposit and fees too. Collected = its cheque's linked receipts (invoice
    //    mode) or a cleared cheque / paid row; same reading as the lease page.
    $st = $conn->prepare("
        SELECT li.id, li.installment_date, li.amount, li.status,
               c.id AS cheque_id, c.cheque_amount, c.status AS cheque_status
        FROM re_lease_installments li
        LEFT JOIN re_post_dated_cheques c ON c.id = (
            SELECT c2.id FROM re_post_dated_cheques c2
            WHERE c2.installment_id = li.id AND c2.lease_id = li.lease_id
              AND c2.status NOT IN ('cancelled', 'replaced')
            ORDER BY c2.id DESC LIMIT 1
        )
        WHERE li.company_id = ? AND li.lease_id = ? AND li.installment_type = 'rent' AND li.status <> 'cancelled'
        ORDER BY li.installment_date, li.id LIMIT 1
    ");
    $st->execute([$companyId, $leaseId]);
    if ($i = $st->fetch(PDO::FETCH_ASSOC)) {
        $amount = (float)($i['cheque_amount'] ?: $i['amount']);
        $received = 0.0;
        if (in_array($i['status'], ['paid', 'waived'], true) || ($i['cheque_status'] ?? '') === 'cleared') {
            $received = $amount;
        } elseif (!empty($i['cheque_id']) && $mode === 'invoice') {
            require_once __DIR__ . '/receipt_allocation_engine.php';
            if (function_exists('re_lease_cheque_receipt_summaries')) {
                // Read only: no link repair on a page view
                $sum = re_lease_cheque_receipt_summaries($conn, $companyId, $leaseId, false)[(int)$i['cheque_id']] ?? null;
                $received = min($amount, (float)($sum['collected_total'] ?? 0));
            }
        }
        $first = [
            'found' => true, 'due' => $i['installment_date'], 'amount' => $amount, 'received' => $received,
            'done' => $amount > 0 && $received + 0.005 >= $amount, 'source' => 'schedule',
        ];
    }

    // 2. No payment schedule: first rent obligation (invoice mode)
    if (!$first['found'] && $mode !== 'legacy') {
        $st = $conn->prepare("
            SELECT due_date, total_amount, allocated_amount, status
            FROM re_obligations
            WHERE company_id = ? AND lease_id = ? AND obligation_type = 'rent' AND status NOT IN ('cancelled', 'draft')
            ORDER BY due_date, id LIMIT 1
        ");
        $st->execute([$companyId, $leaseId]);
        if ($o = $st->fetch(PDO::FETCH_ASSOC)) {
            $first = [
                'found' => true, 'due' => $o['due_date'], 'amount' => (float)$o['total_amount'],
                'received' => (float)$o['allocated_amount'],
                'done' => in_array($o['status'], ['settled', 'waived'], true) || (float)$o['allocated_amount'] + 0.005 >= (float)$o['total_amount'],
                'source' => 'obligation',
            ];
        }
    }
    return ['deposit' => $deposit, 'first' => $first, 'auto_done' => $deposit['done'] && $first['done']];
}

/**
 * Stage flags for a version-2 move-in.
 * Returns ['done' => [1..5 => bool], 'current' => 1..6 (6 = all done),
 *          'ready_to_complete' => stages 1-4 done, 'payment' => rms_payment_status(), 'files' => rms_file_counts()]
 * The linked "form" item is left out of the inspection count (step 5 ticks it).
 */
function rms_stage_state(PDO $conn, int $companyId, array $moveIn, array $items): array
{
    $payment = rms_payment_status($conn, $companyId, ['id' => $moveIn['lease_id'], 'accounting_mode' => $moveIn['accounting_mode'] ?? '']);
    $files = rms_file_counts($conn, (int)$moveIn['id']);
    $kq = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM re_move_in_keys WHERE move_in_id = ?");
    $kq->execute([(int)$moveIn['id']]);
    $keysQty = (int)$kq->fetchColumn();
    $keyMode = rms_key_mode($moveIn);
    $contractDoc = rms_contract_document($conn, $companyId, (int)$moveIn['lease_id']);
    $total = 0; $checked = 0;
    foreach ($items as $sec) {
        foreach ($sec['items'] as $i) {
            if (rms_item_kind((string)$i['item_text']) === 'form') {
                continue;
            }
            $total++;
            $checked += (int)$i['is_checked'];
        }
    }
    $done = [
        1 => !empty($moveIn['contract_verified']) || $contractDoc !== null,
        2 => $payment['auto_done'] || !empty($moveIn['payment_override']),
        3 => $total > 0 && $checked === $total && !empty($moveIn['inspection_completed']),
        4 => !empty($moveIn['keys_handed_over']),
        5 => false,
    ];
    // Step 4 is "ready" once the keys are handed over, or, for a later handover, once the
    // keys (and the date) are saved: the flow moves on to the form, and the handover itself
    // is confirmed at the end (on the date) or by the signed form. done[4] stays "handed over".
    $keysReady = $done[4] || ($keyMode !== 'move_in' && $keysQty > 0
        && ($keyMode !== 'scheduled' || !empty($moveIn['key_handover_scheduled_at'])));
    $formOpen = $done[3] && $keysReady;
    $done[5] = $formOpen && $files['inspection_report'] > 0;
    $current = 6;
    foreach ([1 => $done[1], 2 => $done[2], 3 => $done[3], 4 => $keysReady, 5 => $done[5]] as $n => $ok) {
        if (!$ok) { $current = $n; break; }
    }
    return [
        'done' => $done, 'current' => $current, 'payment' => $payment, 'files' => $files,
        'key_mode' => $keyMode, 'keys_qty' => $keysQty, 'form_open' => $formOpen, 'keys_ready' => $keysReady,
        'contract_doc' => $contractDoc,
        'ready_to_complete' => $done[1] && $done[2] && $done[3] && $done[4],
        'items_total' => $total, 'items_checked' => $checked,
        'insp_all_checked' => $total > 0 && $checked === $total,
    ];
}

/** Error text when stage $stage cannot be changed now, or null. $undo = going back. */
function rms_stage_guard(array $state, int $stage, bool $undo = false): ?string
{
    $names = [1 => 'Contract Signed', 2 => 'Payment Confirmed', 3 => 'Inspection', 4 => 'Key Handover', 5 => 'Inspection Report'];
    if (!$undo) {
        for ($n = 1; $n < $stage; $n++) {
            if (!$state['done'][$n]) {
                return 'Complete "' . $names[$n] . '" first. Stages must be done in order.';
            }
        }
        return null;
    }
    for ($n = 5; $n > $stage; $n--) {
        if ($n === 5 && $state['done'][5]) {
            return 'Remove the signed inspection form first (step 5). It records the current inspection and keys.';
        }
        if ($state['done'][$n] || ($n === 3 && $state['items_checked'] > 0)) {
            return 'Undo "' . $names[$n] . '" first. Stages must be undone in reverse order.';
        }
    }
    return null;
}

/** Keep the stored flags in line with the items (inspection) and the lease (payment). */
function rms_sync_flags(PDO $conn, int $moveInId, int $companyId, ?int $userId): void
{
    $mi = $conn->prepare("SELECT mi.*, l.accounting_mode FROM re_move_ins mi JOIN re_leases l ON l.id = mi.lease_id WHERE mi.id = ? AND mi.company_id = ?");
    $mi->execute([$moveInId, $companyId]);
    $moveIn = $mi->fetch(PDO::FETCH_ASSOC);
    if (!$moveIn) {
        return;
    }
    $state = rms_stage_state($conn, $companyId, $moveIn, rms_items($conn, $moveInId));
    // The inspection is completed by the user ("Complete inspection"); it only
    // falls back to open when an item is no longer checked
    if (!$state['insp_all_checked']) {
        $conn->prepare("UPDATE re_move_ins SET inspection_completed = 0, inspection_completed_by = NULL, inspection_completed_at = NULL WHERE id = ? AND inspection_completed <> 0")
            ->execute([$moveInId]);
    }
    $pay = $state['done'][2] ? 1 : 0;
    $conn->prepare("
        UPDATE re_move_ins
        SET payment_confirmed = ?, payment_confirmed_by = ?, payment_confirmed_at = ?
        WHERE id = ? AND payment_confirmed <> ?
    ")->execute([$pay, $pay ? $userId : null, $pay ? date('Y-m-d H:i:s') : null, $moveInId, $pay]);
}
