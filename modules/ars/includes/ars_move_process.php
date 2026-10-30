<?php
/**
 * ARS Move-In / Move-Out process (staged, like Real Estate move-in / move-out),
 * keyed by booking. Tables are created on first use (migrations/ars_move_process.sql
 * has the same DDL for the live server).
 *
 * Move-in:  1 Payment (deposit + first payment)  2 Inspection (the ARS checklist,
 *           ars_move_inspections type 'in')  3 Key Handover (checks the guest in)
 *           4 Inspection Report (send, sign on screen or upload the signed copy)
 * Move-out: 1 Inspection Schedule  2 Final Inspection (checklist type 'out')
 *           3 Key Return (checks the guest out)  4 Inspection Report (signed copy)
 *           5 Damages & Charges  6 Deposit Settlement (breakdown, statement for
 *           Accounts, marked complete; nothing is posted from here)
 * Completing the move-out marks the booking completed.
 *
 * Signed forms are booking attachments (categories move_in_form / move_out_report).
 */

require_once __DIR__ . '/ars_move_inspection.php';
require_once __DIR__ . '/ars_booking_attachments.php';
require_once __DIR__ . '/ars_deposit.php';
require_once __DIR__ . '/ars_activity.php';

const AMP_DEFAULT_KEYS = [['Main door key', 1], ['Building access card', 1], ['Parking card', 0]];
const AMP_DEFAULT_CHARGES = ['Cleaning'];

function amp_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ars_move_inspection_ensure_schema($conn);
    $tables = [
        "CREATE TABLE IF NOT EXISTS ars_move_ins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            booking_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
            contract_verified TINYINT(1) NOT NULL DEFAULT 0,
            contract_verified_by INT NULL,
            contract_verified_at DATETIME NULL,
            payment_override TINYINT(1) NOT NULL DEFAULT 0,
            payment_override_by INT NULL,
            payment_override_at DATETIME NULL,
            payment_override_reason VARCHAR(500) NULL,
            key_handover_date DATE NULL,
            keys_handed_over TINYINT(1) NOT NULL DEFAULT 0,
            keys_handed_over_at DATETIME NULL,
            keys_handed_over_by INT NULL,
            form_sent_at DATETIME NULL,
            form_sent_to VARCHAR(255) NULL,
            form_sent_by INT NULL,
            completed_at DATETIME NULL,
            completed_by INT NULL,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancel_reason VARCHAR(500) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_amp_in_booking (company_id, booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ars_move_outs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            booking_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
            move_out_date DATE NULL,
            inspection_date DATE NULL,
            inspection_time TIME NULL,
            inspection_note VARCHAR(255) NULL,
            keys_returned TINYINT(1) NOT NULL DEFAULT 0,
            keys_returned_at DATETIME NULL,
            keys_returned_by INT NULL,
            keys_partial_ack TINYINT(1) NOT NULL DEFAULT 0,
            report_sent_at DATETIME NULL,
            report_sent_to VARCHAR(255) NULL,
            report_sent_by INT NULL,
            settlement_completed_at DATETIME NULL,
            settlement_completed_by INT NULL,
            settlement_note VARCHAR(500) NULL,
            completed_at DATETIME NULL,
            completed_by INT NULL,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancel_reason VARCHAR(500) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_amp_out_booking (company_id, booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ars_move_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            booking_id INT NOT NULL,
            key_label VARCHAR(100) NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            card_numbers VARCHAR(255) NULL,
            returned_qty INT NULL,
            return_note VARCHAR(255) NULL,
            display_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_amp_keys_booking (company_id, booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ars_move_out_damages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            move_out_id INT NOT NULL,
            area VARCHAR(100) NULL,
            description VARCHAR(500) NOT NULL,
            cost DECIMAL(12,2) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_amp_dmg (move_out_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ars_move_out_charge_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            KEY idx_amp_ct (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ars_move_settings (
            company_id INT NOT NULL PRIMARY KEY,
            min_nights INT NOT NULL DEFAULT 30,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ars_move_unit_process (
            company_id INT NOT NULL,
            unit_id INT NOT NULL,
            always_process TINYINT(1) NOT NULL DEFAULT 1,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (company_id, unit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ars_move_out_charges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            move_out_id INT NOT NULL,
            charge_name VARCHAR(100) NOT NULL,
            amount DECIMAL(12,2) NULL,
            note VARCHAR(255) NULL,
            is_removed TINYINT(1) NOT NULL DEFAULT 0,
            display_order INT NOT NULL DEFAULT 0,
            KEY idx_amp_ch (move_out_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $sql) {
        try {
            $conn->exec($sql);
        } catch (Throwable $e) {
            error_log('ARS move process schema: ' . $e->getMessage());
        }
    }
    // Guest digital signature on the inspection form (added to older installs)
    foreach (['ars_move_ins', 'ars_move_outs'] as $t) {
        try {
            if (!$conn->query("SHOW COLUMNS FROM $t LIKE 'guest_signature_path'")->fetch()) {
                $conn->exec("ALTER TABLE $t ADD COLUMN guest_signature_path VARCHAR(500) NULL, ADD COLUMN guest_signed_name VARCHAR(150) NULL, ADD COLUMN guest_signed_at DATETIME NULL");
            }
            if ($t === 'ars_move_outs' && !$conn->query("SHOW COLUMNS FROM ars_move_keys LIKE 'damage_id'")->fetch()) {
                // A key / card not returned, charged as a damage (ars_move_out_damages)
                $conn->exec("ALTER TABLE ars_move_keys ADD COLUMN damage_id INT NULL");
            }
            if (!$conn->query("SHOW COLUMNS FROM $t LIKE 'signed_superseded_at'")->fetch()) {
                $conn->exec("ALTER TABLE $t ADD COLUMN signed_superseded_at DATETIME NULL");
            }
        } catch (Throwable $e) {
            error_log('ARS move signature columns (' . $t . '): ' . $e->getMessage());
        }
    }
}

function amp_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        amp_ensure_schema($conn);
        try {
            $conn->query("SELECT 1 FROM ars_move_ins LIMIT 1");
            $conn->query("SELECT 1 FROM ars_move_outs LIMIT 1");
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function amp_now(): string
{
    return ars_move_inspection_now();
}

function amp_today(): string
{
    return substr(amp_now(), 0, 10);
}

/** Owner / Admin or ARS core staff: overrides, void, reopen. */
function amp_can_manage(PDO $conn): bool
{
    require_once __DIR__ . '/ars_permissions.php';
    return (function_exists('has_role') && (has_role('Owner', $conn) || has_role('Admin', $conn))) || ars_user_has_core($conn);
}

/** Booking with guest, unit and building. */
function amp_booking(PDO $conn, int $companyId, int $bookingId): ?array
{
    $st = $conn->prepare("
        SELECT b.*, g.first_name, g.last_name, g.email AS guest_email, g.phone AS guest_phone, g.nationality,
               g.id_type, g.id_number, u.unit_number, u.unit_type, bl.name AS building_name
        FROM ars_bookings b
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bl ON bl.id = u.building_id
        WHERE b.id = ? AND b.company_id = ?
    ");
    $st->execute([$bookingId, $companyId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function amp_guest_name(array $b): string
{
    return trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'Guest';
}

/** The booking's open (or completed) move-in / move-out; cancelled ones are history. */
function amp_record(PDO $conn, int $companyId, int $bookingId, string $type, bool $withCancelled = false): ?array
{
    amp_ensure_schema($conn);
    $t = $type === 'out' ? 'ars_move_outs' : 'ars_move_ins';
    $st = $conn->prepare("SELECT * FROM $t WHERE company_id = ? AND booking_id = ?" . ($withCancelled ? '' : " AND status <> 'cancelled'") . " ORDER BY id DESC LIMIT 1");
    $st->execute([$companyId, $bookingId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Why a move-in / move-out cannot be started for this booking, or null. */
function amp_start_block(PDO $conn, int $companyId, array $booking, string $type): ?string
{
    $s = (string)$booking['status'];
    if ($type === 'in') {
        if (!in_array($s, ['pending', 'confirmed'], true)) {
            return 'The booking is ' . str_replace('_', ' ', $s) . ': a move-in can only be started before check-in.';
        }
    } elseif ($s !== 'checked_in') {
        return $s === 'confirmed' || $s === 'pending'
            ? 'The guest is not checked in yet. Complete the move-in first.'
            : 'The booking is ' . str_replace('_', ' ', $s) . ': a move-out can only be started while the guest is checked in.';
    }
    return amp_record($conn, $companyId, (int)$booking['id'], $type) ? 'A ' . ($type === 'out' ? 'move-out' : 'move-in') . ' is already open for this booking.' : null;
}

function amp_start(PDO $conn, int $companyId, array $booking, string $type, ?int $userId): array
{
    if ($err = amp_start_block($conn, $companyId, $booking, $type)) {
        return ['ok' => false, 'error' => $err];
    }
    $bid = (int)$booking['id'];
    $conn->beginTransaction();
    try {
        if ($type === 'in') {
            $conn->prepare("INSERT INTO ars_move_ins (company_id, booking_id, key_handover_date, created_by) VALUES (?, ?, ?, ?)")
                ->execute([$companyId, $bid, $booking['check_in'], $userId]);
            // Keys and access cards, once per booking (the move-out checks them back)
            $has = $conn->prepare("SELECT COUNT(*) FROM ars_move_keys WHERE company_id = ? AND booking_id = ?");
            $has->execute([$companyId, $bid]);
            if (!(int)$has->fetchColumn()) {
                $ins = $conn->prepare("INSERT INTO ars_move_keys (company_id, booking_id, key_label, quantity, display_order) VALUES (?, ?, ?, ?, ?)");
                foreach (AMP_DEFAULT_KEYS as $i => [$label, $qty]) {
                    $ins->execute([$companyId, $bid, $label, $qty, $i + 1]);
                }
            }
        } else {
            $conn->prepare("INSERT INTO ars_move_outs (company_id, booking_id, move_out_date, inspection_date, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$companyId, $bid, max((string)$booking['check_in'], min((string)$booking['check_out'], amp_today())), $booking['check_out'], $userId]);
            $moId = (int)$conn->lastInsertId();
            amp_charges_copy($conn, $companyId, $moId);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    ars_move_inspection_get_or_create($conn, $companyId, $bid, $type, $userId);
    amp_log($conn, $companyId, $booking, $type === 'out' ? 'move_out_started' : 'move_in_started', ($type === 'out' ? 'Move-out' : 'Move-in') . ' process initiated', '', $userId);
    return ['ok' => true, 'error' => ''];
}

function amp_log(PDO $conn, int $companyId, array $booking, string $event, string $title, string $desc, ?int $userId): void
{
    try {
        ars_booking_activity_log($conn, [
            'company_id' => $companyId,
            'booking_id' => (int)$booking['id'],
            'booking_number' => $booking['booking_number'] ?? null,
            'event_category' => 'operational',
            'event_type' => $event,
            'title' => $title,
            'description' => $desc !== '' ? $desc : null,
            'created_by' => $userId,
        ]);
    } catch (Throwable $e) {
        error_log('ARS move process log: ' . $e->getMessage());
    }
}

/**
 * The move-in date (key handover) may differ from the booking check-in: the guest
 * arrives later, or earlier by arrangement. Allowed from 30 days before check-in
 * to check-out. Returns an error message, or null when the date is fine.
 */
function amp_move_in_date_error(array $booking, string $date): ?string
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return 'Choose a valid move-in date.';
    }
    $earliest = date('Y-m-d', strtotime((string)$booking['check_in'] . ' -30 days'));
    if ($date < $earliest) {
        return 'The move-in date cannot be more than 30 days before check-in (' . date('d M Y', strtotime((string)$booking['check_in'])) . ').';
    }
    if ($date > (string)$booking['check_out']) {
        return 'The move-in date cannot be after check-out (' . date('d M Y', strtotime((string)$booking['check_out'])) . ').';
    }
    return null;
}

/** How the move-in date compares with the booking check-in, e.g. "2 days after check-in" ('' when the same). */
function amp_move_in_date_note(array $booking, string $date): string
{
    $diff = (int)round((strtotime($date) - strtotime((string)$booking['check_in'])) / 86400);
    if ($diff === 0) {
        return '';
    }
    return abs($diff) . ' day' . (abs($diff) === 1 ? '' : 's') . ($diff > 0 ? ' after' : ' before') . ' the booking check-in (' . date('d M Y', strtotime((string)$booking['check_in'])) . ')';
}

/* ---------- which bookings use the process ---------- */

/** Stays of this many nights or more go through the Move-In / Move-Out process (default 30). */
function amp_min_nights(PDO $conn, int $companyId): int
{
    amp_ensure_schema($conn);
    $st = $conn->prepare("SELECT min_nights FROM ars_move_settings WHERE company_id = ?");
    $st->execute([$companyId]);
    $v = $st->fetchColumn();
    return $v === false ? 30 : max(1, (int)$v);
}

function amp_set_min_nights(PDO $conn, int $companyId, int $nights, ?int $userId): void
{
    amp_ensure_schema($conn);
    $conn->prepare("INSERT INTO ars_move_settings (company_id, min_nights, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE min_nights = VALUES(min_nights), updated_by = VALUES(updated_by)")
        ->execute([$companyId, max(1, min(366, $nights)), $userId]);
}

/** Units switched to always use the process, whatever the stay length: [unit_id => true]. */
function amp_forced_units(PDO $conn, int $companyId): array
{
    amp_ensure_schema($conn);
    $st = $conn->prepare("SELECT unit_id FROM ars_move_unit_process WHERE company_id = ? AND always_process = 1");
    $st->execute([$companyId]);
    return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
}

function amp_set_unit_forced(PDO $conn, int $companyId, int $unitId, bool $on, ?int $userId): void
{
    amp_ensure_schema($conn);
    $conn->prepare("INSERT INTO ars_move_unit_process (company_id, unit_id, always_process, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE always_process = VALUES(always_process), updated_by = VALUES(updated_by)")
        ->execute([$companyId, $unitId, $on ? 1 : 0, $userId]);
}

function amp_booking_nights(array $b): int
{
    $n = (int)($b['nights'] ?? 0);
    if ($n <= 0 && !empty($b['check_in']) && !empty($b['check_out'])) {
        $n = (int)round((strtotime((string)$b['check_out']) - strtotime((string)$b['check_in'])) / 86400);
    }
    return max(0, $n);
}

/**
 * Does this booking go through the Move-In / Move-Out process? Yes for a stay of the
 * minimum nights or more, or a unit switched to always use it; shorter stays use the
 * quick Check In / Check Out on the booking. Returns ['on', 'nights', 'min', 'forced'].
 */
function amp_rule(PDO $conn, int $companyId, array $booking): array
{
    $min = amp_min_nights($conn, $companyId);
    $nights = amp_booking_nights($booking);
    $forced = isset(amp_forced_units($conn, $companyId)[(int)($booking['unit_id'] ?? 0)]);
    return ['on' => $forced || $nights >= $min, 'nights' => $nights, 'min' => $min, 'forced' => $forced];
}

/** The process applies to this side of the booking: by the rule, or because it was started anyway. */
function amp_process_on(PDO $conn, int $companyId, array $booking, string $type): bool
{
    // Short stays always use Check In / Check Out; a process opened on one (e.g. before the rule) stays viewable but does not block
    return amp_rule($conn, $companyId, $booking)['on'];
}

/* ---------- keys ---------- */

function amp_keys(PDO $conn, int $companyId, int $bookingId): array
{
    amp_ensure_schema($conn);
    $st = $conn->prepare("SELECT * FROM ars_move_keys WHERE company_id = ? AND booking_id = ? ORDER BY display_order, id");
    $st->execute([$companyId, $bookingId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Keys handed over (quantity > 0) and how many are still to come back. */
function amp_keys_summary(array $keys): array
{
    $s = ['issued' => 0, 'rows' => 0, 'pending' => 0, 'missing' => []];
    foreach ($keys as $k) {
        $q = (int)$k['quantity'];
        if ($q <= 0) {
            continue;
        }
        $s['rows']++;
        $s['issued'] += $q;
        if ($k['returned_qty'] === null) {
            $s['pending']++;
        } elseif ((int)$k['returned_qty'] < $q) {
            $s['missing'][] = ($q - (int)$k['returned_qty']) . ' × ' . $k['key_label'];
        }
    }
    return $s;
}

/* ---------- payment ---------- */

function amp_payment(array $b): array
{
    $depAmount = round((float)($b['deposit_amount'] ?? 0), 2);
    $depStatus = (string)($b['deposit_status'] ?? 'none');
    $depDone = $depAmount <= 0 || in_array($depStatus, ['received', 'partially_refunded', 'refunded', 'forfeited'], true);
    $total = round((float)($b['total_amount'] ?? 0), 2);
    $paid = round((float)($b['paid_amount'] ?? 0), 2);
    $firstDone = $total <= 0 || $paid > 0.005;
    return [
        'deposit' => ['required' => $depAmount > 0, 'amount' => $depAmount, 'status' => $depStatus, 'done' => $depDone],
        'first' => ['total' => $total, 'paid' => $paid, 'balance' => round((float)($b['balance_due'] ?? max(0, $total - $paid)), 2), 'done' => $firstDone],
        'auto_done' => $depDone && $firstDone,
    ];
}

/* ---------- signed forms (booking attachments) ---------- */

/**
 * Signed copies of the inspection report, newest first. With $rec (the move-in /
 * move-out): only the ones that still count; a copy signed before the inspection was
 * changed (signed_superseded_at) is outdated, see amp_superseded_forms().
 */
function amp_signed_forms(PDO $conn, int $companyId, int $bookingId, string $type, ?array $rec = null): array
{
    ars_booking_attachments_ensure_schema($conn);
    $since = (string)($rec['signed_superseded_at'] ?? '');
    $st = $conn->prepare("SELECT * FROM ars_booking_attachments WHERE company_id = ? AND booking_id = ? AND doc_category = ?" . ($since !== '' ? ' AND created_at > ?' : '') . " ORDER BY id DESC");
    $st->execute(array_merge([$companyId, $bookingId, $type === 'out' ? 'move_out_report' : 'move_in_form'], $since !== '' ? [$since] : []));
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Signed copies made before the inspection was last changed (kept, but outdated). */
function amp_superseded_forms(PDO $conn, int $companyId, int $bookingId, string $type, array $rec): array
{
    $since = (string)($rec['signed_superseded_at'] ?? '');
    if ($since === '') {
        return [];
    }
    $st = $conn->prepare("SELECT * FROM ars_booking_attachments WHERE company_id = ? AND booking_id = ? AND doc_category = ? AND created_at <= ? ORDER BY id DESC");
    $st->execute([$companyId, $bookingId, $type === 'out' ? 'move_out_report' : 'move_in_form', $since]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ---------- move-in stages ---------- */

function amp_in_state(PDO $conn, int $companyId, array $booking, array $mi): array
{
    $bid = (int)$booking['id'];
    $pay = amp_payment($booking);
    $insp = ars_move_inspection_find($conn, $companyId, $bid, 'in');
    $signed = amp_signed_forms($conn, $companyId, $bid, 'in', $mi);
    $done = [
        1 => $pay['auto_done'] || !empty($mi['payment_override']),
        2 => $insp && $insp['status'] === 'completed',
        3 => !empty($mi['keys_handed_over']),
        4 => (bool)$signed,
    ];
    $current = 5;
    foreach ($done as $n => $ok) {
        if (!$ok) {
            $current = $n;
            break;
        }
    }
    return ['done' => $done, 'current' => $current, 'payment' => $pay, 'inspection' => $insp,
        'signed' => $signed, 'ready_to_complete' => !in_array(false, $done, true)];
}

/* ---------- move-out: damages and charges ---------- */

function amp_charge_types(PDO $conn, int $companyId, bool $all = false): array
{
    amp_ensure_schema($conn);
    $cnt = $conn->prepare("SELECT COUNT(*) FROM ars_move_out_charge_types WHERE company_id = ?");
    $cnt->execute([$companyId]);
    if (!(int)$cnt->fetchColumn()) {
        $ins = $conn->prepare("INSERT INTO ars_move_out_charge_types (company_id, name, display_order) VALUES (?, ?, ?)");
        foreach (AMP_DEFAULT_CHARGES as $i => $n) {
            $ins->execute([$companyId, $n, $i + 1]);
        }
    }
    $st = $conn->prepare("SELECT * FROM ars_move_out_charge_types WHERE company_id = ?" . ($all ? '' : ' AND is_active = 1') . " ORDER BY display_order, id");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function amp_charges_copy(PDO $conn, int $companyId, int $moveOutId): void
{
    $ins = $conn->prepare("INSERT INTO ars_move_out_charges (company_id, move_out_id, charge_name, display_order) VALUES (?, ?, ?, ?)");
    foreach (amp_charge_types($conn, $companyId) as $i => $t) {
        $ins->execute([$companyId, $moveOutId, $t['name'], $i + 1]);
    }
}

function amp_charges(PDO $conn, int $moveOutId, bool $removed = false): array
{
    $st = $conn->prepare("SELECT * FROM ars_move_out_charges WHERE move_out_id = ? AND is_removed = ? ORDER BY display_order, id");
    $st->execute([$moveOutId, $removed ? 1 : 0]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function amp_damages(PDO $conn, int $moveOutId): array
{
    $st = $conn->prepare("SELECT * FROM ars_move_out_damages WHERE move_out_id = ? ORDER BY id");
    $st->execute([$moveOutId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Deposit settlement breakdown: deposit held, damages, charges, refund due or payable.
 * Read only: Accounts settles the deposit on the booking's Deposit tab.
 */
function amp_figures(PDO $conn, array $booking, array $mo): array
{
    $depStatus = (string)($booking['deposit_status'] ?? 'none');
    $held = in_array($depStatus, ['received', 'partially_refunded'], true) ? ars_deposit_held_remaining($booking) : 0.0;
    $f = ['deposit' => round((float)($booking['deposit_amount'] ?? 0), 2), 'deposit_status' => $depStatus, 'held' => $held,
        'damages' => [], 'charges' => [], 'damage_total' => 0.0, 'charge_total' => 0.0, 'pending' => 0];
    foreach (amp_damages($conn, (int)$mo['id']) as $d) {
        $label = trim(($d['area'] ? $d['area'] . ' - ' : '') . $d['description']);
        $f['damages'][] = [$label, $d['cost'] === null ? null : round((float)$d['cost'], 2)];
        if ($d['cost'] === null) {
            $f['pending']++;
        } else {
            $f['damage_total'] += (float)$d['cost'];
        }
    }
    foreach (amp_charges($conn, (int)$mo['id']) as $c) {
        if ($c['amount'] !== null && (float)$c['amount'] <= 0) {
            continue;
        }
        $f['charges'][] = [$c['charge_name'], $c['amount'] === null ? null : round((float)$c['amount'], 2), (string)($c['note'] ?? '')];
        if ($c['amount'] === null) {
            $f['pending']++;
        } else {
            $f['charge_total'] += (float)$c['amount'];
        }
    }
    $f['damage_total'] = round($f['damage_total'], 2);
    $f['charge_total'] = round($f['charge_total'], 2);
    $f['total'] = round($f['damage_total'] + $f['charge_total'], 2);
    $f['refund'] = max(0, round($held - $f['total'], 2));
    $f['payable'] = max(0, round($f['total'] - $held, 2));
    return $f;
}

/** Damages without a cost and charges without an amount (0 = no charge). */
function amp_pending_amounts(PDO $conn, int $moveOutId): int
{
    $a = $conn->prepare("SELECT COUNT(*) FROM ars_move_out_damages WHERE move_out_id = ? AND cost IS NULL");
    $a->execute([$moveOutId]);
    $b = $conn->prepare("SELECT COUNT(*) FROM ars_move_out_charges WHERE move_out_id = ? AND is_removed = 0 AND amount IS NULL");
    $b->execute([$moveOutId]);
    return (int)$a->fetchColumn() + (int)$b->fetchColumn();
}

/* ---------- move-out stages ---------- */

function amp_out_state(PDO $conn, int $companyId, array $booking, array $mo): array
{
    $bid = (int)$booking['id'];
    $insp = ars_move_inspection_find($conn, $companyId, $bid, 'out');
    $signed = amp_signed_forms($conn, $companyId, $bid, 'out', $mo);
    $inspected = $insp && $insp['status'] === 'completed';
    $pending = amp_pending_amounts($conn, (int)$mo['id']);
    $done = [
        1 => !empty($mo['inspection_date']) || $inspected,
        2 => $inspected,
        3 => !empty($mo['keys_returned']),
        4 => (bool)$signed,
        5 => $inspected && $pending === 0,
        6 => !empty($mo['settlement_completed_at']),
    ];
    $current = 7;
    foreach ($done as $n => $ok) {
        if (!$ok) {
            $current = $n;
            break;
        }
    }
    return ['done' => $done, 'current' => $current, 'inspection' => $insp, 'signed' => $signed, 'pending' => $pending,
        'ready_to_complete' => !in_array(false, $done, true)];
}

/* ---------- void ---------- */

/** Cancel a move-in / move-out started by mistake. Not after the guest was checked in / out by it. */
function amp_void(PDO $conn, int $companyId, array $booking, array $rec, string $type, ?int $userId, string $reason): array
{
    if (!amp_can_manage($conn)) {
        return ['ok' => false, 'error' => 'Only Owner, Admin or ARS core staff can cancel it.'];
    }
    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'Enter the reason.'];
    }
    if ($rec['status'] !== 'in_progress') {
        return ['ok' => false, 'error' => 'Only an open process can be cancelled.'];
    }
    if ($type === 'in' && !empty($rec['keys_handed_over'])) {
        return ['ok' => false, 'error' => 'The keys are handed over and the guest is checked in, so the move-in cannot be cancelled.'];
    }
    if ($type === 'out' && !empty($rec['keys_returned'])) {
        return ['ok' => false, 'error' => 'The keys are returned and the guest is checked out, so the move-out cannot be cancelled.'];
    }
    $t = $type === 'out' ? 'ars_move_outs' : 'ars_move_ins';
    $conn->prepare("UPDATE $t SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ? AND company_id = ?")
        ->execute([$userId, mb_substr(trim($reason), 0, 500), (int)$rec['id'], $companyId]);
    amp_log($conn, $companyId, $booking, $type === 'out' ? 'move_out_cancelled' : 'move_in_cancelled', ($type === 'out' ? 'Move-out' : 'Move-in') . ' process cancelled', $reason, $userId);
    return ['ok' => true, 'error' => ''];
}

/* ---------- forms (PDF) ---------- */

function amp_e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function amp_date(?string $d, string $fmt = 'd M Y'): string
{
    return $d ? date($fmt, strtotime($d)) : '';
}

function amp_temp_dir(): string
{
    $dir = dirname(__DIR__, 3) . '/uploads/ars_move_forms_temp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return is_dir($dir) && is_writable($dir) ? $dir : rtrim(sys_get_temp_dir(), '/');
}

/** mPDF with the ARS letterhead: an uploaded header / footer (Form Header & Footer), else the ARS logo and details. */
function amp_mpdf(PDO $conn, int $companyId, string $title): \Mpdf\Mpdf
{
    require_once dirname(__DIR__, 2) . '/realestate/includes/form_letterhead_helper.php';
    $tmp = amp_temp_dir();
    $lh = rfl_get($conn, $companyId);
    if ($lh['header']['custom'] || $lh['footer']['custom']) {
        return rfl_mpdf($conn, $companyId, $tmp, $title);
    }
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    require_once __DIR__ . '/ars_booking_documents.php';
    $co = ars_booking_document_company($conn, $companyId);
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmp, 'default_font' => 'dejavusans',
        'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 30, 'margin_bottom' => 16, 'margin_header' => 6, 'margin_footer' => 5,
        'shrink_tables_to_fit' => 0]);
    $mpdf->SetTitle($title);
    $logo = '';
    $abs = $co['logo_path'] !== '' ? dirname(__DIR__, 3) . '/' . ltrim($co['logo_path'], '/') : '';
    if ($abs !== '' && is_readable($abs)) {
        $logo = '<img src="' . amp_e($abs) . '" style="max-height:16mm;max-width:50mm" />';
    }
    $mpdf->SetHTMLHeader('<table width="100%" style="border-bottom:.4mm solid ' . amp_e($co['primary_color']) . ';font-family:dejavusans"><tr>'
        . '<td style="border:none;width:55mm;vertical-align:middle">' . $logo . '</td>'
        . '<td style="border:none;text-align:right;vertical-align:middle;font-size:8pt;color:#374151"><b style="font-size:11pt;color:' . amp_e($co['primary_color']) . '">' . amp_e($co['name']) . '</b><br>'
        . amp_e($co['address']) . ($co['phone'] !== '' ? '<br>' . amp_e($co['phone']) : '') . ($co['email'] !== '' ? ' &middot; ' . amp_e($co['email']) : '')
        . ($co['trn'] !== '' ? '<br>TRN ' . amp_e($co['trn']) : '') . '</td></tr></table>');
    $mpdf->SetHTMLFooter('<div style="text-align:right;font-size:7pt;color:#6b7280">' . amp_e($co['name']) . ' &middot; Page {PAGENO} of {nbpg}</div>');
    return $mpdf;
}

function amp_company_name(PDO $conn, int $companyId): string
{
    require_once __DIR__ . '/ars_booking_documents.php';
    return (string)(ars_booking_document_company($conn, $companyId)['name'] ?? 'ARS Home Rentals');
}

function amp_form_css(): string
{
    return '<style>
        body { font-family: dejavusans; font-size: 9pt; color: #111; }
        h1 { font-size: 14pt; text-align: center; margin: 0 0 1mm; letter-spacing: .5pt; }
        .ref { text-align: center; font-size: 8pt; color: #555; margin-bottom: 4mm; }
        h2 { font-size: 10pt; background: #1f2937; color: #fff; padding: 1.5mm 2.5mm; margin: 4mm 0 0; }
        h3 { font-size: 9pt; margin: 3mm 0 1mm; color: #1f2937; border-bottom: .3mm solid #9ca3af; padding-bottom: .8mm; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border: .25mm solid #9ca3af; padding: 1.4mm 2mm; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-size: 8.5pt; }
        td.l { width: 22%; background: #f9fafb; font-weight: bold; }
        td.r, th.r { text-align: right; }
        td.c, th.c { text-align: center; }
        td.ok { width: 14mm; text-align: center; font-size: 11pt; color: #15803d; }
        td.no { width: 14mm; text-align: center; color: #9ca3af; }
        .muted { color: #6b7280; }
        td.note { height: 14mm; }
        table.sig th { width: 50%; }
        table.sig td { height: 9mm; vertical-align: middle; }
        table.sig td.sign { height: 18mm; vertical-align: top; }
        table.keep { page-break-inside: avoid; margin-top: 4mm; }
        td.keepcell { border: none; padding: 0; }
        td.secth { border: none; background: #1f2937; color: #fff; font-weight: bold; font-size: 10pt; padding: 1.5mm 2.5mm; }
        td.declcell { border: none; padding: 3mm 0; line-height: 1.45; }
        table.photos td { border: none; width: 50%; padding: 1.5mm; text-align: center; vertical-align: top; }
        .cap { font-size: 7.5pt; color: #4b5563; margin-top: 1mm; }
    </style>';
}

/** Inspection photos as PDF-ready files (smaller JPEG copies), plus the temp copies to delete. */
function amp_pdf_photos(PDO $conn, ?array $insp): array
{
    if (!$insp) {
        return [[], []];
    }
    require_once dirname(__DIR__, 2) . '/realestate/includes/move_in_form_helper.php';
    $files = [];
    $temp = [];
    $ps = $conn->prepare("SELECT relative_path, caption, original_name FROM ars_move_inspection_photos WHERE inspection_id = ? ORDER BY id");
    $ps->execute([(int)$insp['id']]);
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
        $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$p['relative_path'], '/');
        $r = rmf_photo_for_pdf($abs, amp_temp_dir());
        if ($r) {
            $files[] = ['path' => $r[0], 'caption' => (string)($p['caption'] ?: $p['original_name'])];
            if ($r[1]) {
                $temp[] = $r[0];
            }
        }
    }
    return [$files, $temp];
}

function amp_pdf_details(array $b, string $dateLabel, string $date): string
{
    $guest = amp_guest_name($b);
    $unit = trim(($b['building_name'] ?? '') . ' · ' . ($b['unit_number'] ?? ''), ' ·');
    $h = '<table>';
    $h .= '<tr><td class="l">Guest</td><td>' . amp_e($guest) . '</td><td class="l">' . amp_e($dateLabel) . '</td><td>' . amp_e(amp_date($date)) . '</td></tr>';
    $h .= '<tr><td class="l">Unit</td><td>' . amp_e($unit) . '</td><td class="l">Booking No.</td><td>' . amp_e($b['booking_number']) . '</td></tr>';
    $h .= '<tr><td class="l">Stay</td><td>' . amp_e(amp_date($b['check_in']) . ' to ' . amp_date($b['actual_check_out'] ?: $b['check_out'])) . '</td><td class="l">Guests</td><td>' . (int)$b['num_guests'] . '</td></tr>';
    $h .= '<tr><td class="l">Phone</td><td>' . amp_e($b['guest_phone']) . '</td><td class="l">Email</td><td>' . amp_e($b['guest_email']) . '</td></tr>';
    $h .= '<tr><td class="l">ID</td><td>' . amp_e(trim(($b['id_type'] ? ucwords(str_replace('_', ' ', $b['id_type'])) . ' ' : '') . ($b['id_number'] ?? ''))) . '</td><td class="l">Nationality</td><td>' . amp_e($b['nationality']) . '</td></tr>';
    return $h . '</table>';
}

function amp_pdf_checklist(PDO $conn, ?array $insp, bool $remarks): string
{
    if (!$insp) {
        return '<p class="muted">The inspection has not been started.</p>';
    }
    $items = ars_move_inspection_items($conn, (int)$insp['id']);
    if (!$items) {
        return '<p class="muted">No checklist items.</p>';
    }
    // One table per section (1. General Condition, 2. Furniture & Fixtures, ...); the last section is the remarks box
    $sections = [];
    foreach ($items as $i) {
        $sections[(string)($i['section_name'] ?? '')][] = $i;
    }
    $h = '';
    $sn = 0;
    foreach ($sections as $secName => $rows) {
        if ($secName !== '' || count($sections) > 1) {
            $h .= '<h3>' . (++$sn) . '. ' . amp_e($secName !== '' ? $secName : 'Other') . '</h3>';
        }
        $h .= '<table><tr><th>Item</th><th style="width:14mm;text-align:center">OK</th>' . ($remarks ? '<th style="width:60mm">Remarks</th>' : '') . '</tr>';
        foreach ($rows as $i) {
            $h .= '<tr><td>' . amp_e($i['item_name']) . '</td>' . (!empty($i['is_damaged']) ? '<td style="width:14mm;text-align:center;color:#b91c1c;font-size:7.5pt;font-weight:bold">DAMAGED</td>' : ((int)$i['is_completed'] === 1 ? '<td class="ok">&#10004;</td>' : '<td class="no">&#9744;</td>'))
                . ($remarks ? '<td>' . amp_e((string)($i['remarks'] ?? '')) . '</td>' : '') . '</tr>';
        }
        $h .= '</table>';
    }
    $h .= '<h3>' . ($sn ? ($sn + 1) . '. ' : '') . 'Inspection Remarks</h3><table><tr><td class="note">' . nl2br(amp_e((string)($insp['notes'] ?? ''))) . '</td></tr></table>';
    return $h;
}

/**
 * Guest / company signature boxes. $signed: the move-in / move-out record when the
 * signed copy is made (with_signature): the guest's digital signature goes in the box.
 * Other previews show an empty box, so a signature is never shown on content the guest did not see.
 */
function amp_pdf_signatures(string $guest, string $company, ?array $signed = null): string
{
    $abs = $signed && !empty($signed['guest_signature_path']) ? dirname(__DIR__, 3) . '/' . ltrim((string)$signed['guest_signature_path'], '/') : '';
    $has = $abs !== '' && is_file($abs);
    $cell = '<span class="muted">Signature:</span>' . ($has
        ? '<br><img src="' . amp_e($abs) . '" style="height:15mm;max-width:75mm" /><div class="muted" style="font-size:6.5pt">Signed digitally on ' . amp_e(date('d M Y H:i', strtotime((string)$signed['guest_signed_at']))) . '</div>'
        : '');
    return '<table class="sig"><tr><th>Guest</th><th>For ' . amp_e($company) . '</th></tr>'
        . '<tr><td><span class="muted">Name:</span> ' . amp_e($has && !empty($signed['guest_signed_name']) ? $signed['guest_signed_name'] : $guest) . '</td><td><span class="muted">Name:</span></td></tr>'
        . '<tr><td class="sign">' . $cell . '</td><td class="sign"><span class="muted">Signature:</span></td></tr>'
        . '<tr><td><span class="muted">Date:</span> ' . ($has ? amp_e(date('d M Y', strtotime((string)$signed['guest_signed_at']))) : '') . '</td><td><span class="muted">Date:</span></td></tr></table>';
}

function amp_pdf_photos_html(array $files, int $sec): string
{
    if (!$files) {
        return '';
    }
    $h = '<pagebreak /><h2>' . $sec . '. Photos of Unit Condition (' . count($files) . ')</h2><table class="photos">';
    foreach (array_chunk($files, 2) as $pair) {
        $h .= '<tr>';
        foreach ($pair as $p) {
            $h .= '<td><img src="' . amp_e($p['path']) . '" style="max-width:85mm;max-height:62mm" /><div class="cap">' . amp_e($p['caption']) . '</div></td>';
        }
        $h .= (count($pair) === 1 ? '<td></td>' : '') . '</tr>';
    }
    return $h . '</table>';
}

function amp_pdf_keys(array $keys, bool $returned): string
{
    $rows = array_filter($keys, static fn($k) => (int)$k['quantity'] > 0);
    if (!$rows) {
        return '<p class="muted">No keys or access cards recorded.</p>';
    }
    $h = '<table><tr><th>Description</th><th style="width:22mm" class="c">' . ($returned ? 'Issued' : 'Quantity') . '</th>'
        . ($returned ? '<th style="width:22mm" class="c">Returned</th>' : '') . '<th>Card / Key No.</th></tr>';
    foreach ($rows as $k) {
        $h .= '<tr><td>' . amp_e($k['key_label']) . '</td><td class="c">' . (int)$k['quantity'] . '</td>'
            . ($returned ? '<td class="c">' . ($k['returned_qty'] === null ? '-' : (int)$k['returned_qty']) . '</td>' : '')
            . '<td>' . amp_e($k['card_numbers'] ?: '-') . ($returned && $k['return_note'] ? '<div class="muted">' . amp_e($k['return_note']) . '</div>' : '') . '</td></tr>';
    }
    return $h . '</table>';
}

/**
 * The PDF for a booking: 'in' (move-in inspection form), 'out' (move-out inspection
 * report) or 'settlement' (deposit settlement statement). Returns [bytes, filename].
 * $opts: photos (bool), remarks (bool: item remarks).
 */
function amp_pdf(PDO $conn, int $companyId, array $booking, string $kind, array $opts = []): array
{
    $company = amp_company_name($conn, $companyId);
    $guest = amp_guest_name($booking);
    $bid = (int)$booking['id'];
    $ref = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$booking['booking_number']);
    $temp = [];
    $h = amp_form_css();
    if ($kind === 'in') {
        $mi = amp_record($conn, $companyId, $bid, 'in');
        $insp = ars_move_inspection_find($conn, $companyId, $bid, 'in');
        [$photos, $temp] = !empty($opts['photos']) ? amp_pdf_photos($conn, $insp) : [[], []];
        $handed = !empty($mi['keys_handed_over']);
        $h .= '<h1>MOVE-IN INSPECTION REPORT</h1><div class="ref">Booking ' . amp_e($booking['booking_number']) . ' &middot; Generated ' . date('d M Y H:i') . '</div>';
        $h .= '<h2>1. Move-In Details</h2>' . amp_pdf_details($booking, 'Move-In Date', (string)($mi['key_handover_date'] ?? $booking['check_in']));
        $h .= '<h2>2. Keys &amp; Access ' . ($handed ? 'Granted' : 'To Be Handed Over') . '</h2>' . amp_pdf_keys(amp_keys($conn, $companyId, $bid), false);
        $h .= '<h2>3. Inspection Checklist</h2>' . amp_pdf_checklist($conn, $insp, !empty($opts['remarks']));
        $h .= '<table style="margin-top:2mm"><tr><td class="l">Guest comments</td><td class="note"></td></tr></table>';
        $h .= '<table class="keep"><tr><td class="keepcell">'
            . '<table><tr><td class="secth">4. Move-In Acknowledgement &amp; Undertaking</td></tr></table>'
            . '<table><tr><td class="declcell"><b>Acknowledgement by Guest</b><br>I, the undersigned <b>' . amp_e($guest) . '</b>'
            . ($booking['id_number'] ? ', holding ID number <b>' . amp_e($booking['id_number']) . '</b>' : '')
            . ', hereby acknowledge that I have received possession of the above-mentioned unit from ' . amp_e($company)
            . ', along with the keys, access cards and any other items listed in section 2.<br><br>'
            . 'I confirm that I have inspected and accepted the unit in its current condition' . ($photos ? ', as recorded in this report and the attached photos,' : ', as recorded in this report,')
            . ' and undertake to look after the unit and its contents during my stay and to follow the house rules. I agree to return the unit, with all keys, access cards and items listed in section 2, at the end of the stay; any damage or missing item may be deducted from the security deposit.'
            . '</td></tr></table>' . amp_pdf_signatures($guest, $company, !empty($opts['with_signature']) ? ($kind === 'out' ? $mo : $mi) : null) . '</td></tr></table>';
        $h .= amp_pdf_photos_html($photos, 5);
        $name = 'Move_In_Inspection_' . $ref . '.pdf';
        $title = 'Move-In Inspection Report - ' . $booking['booking_number'];
    } elseif ($kind === 'out') {
        $mo = amp_record($conn, $companyId, $bid, 'out');
        $insp = ars_move_inspection_find($conn, $companyId, $bid, 'out');
        [$photos, $temp] = !empty($opts['photos']) ? amp_pdf_photos($conn, $insp) : [[], []];
        $h .= '<h1>MOVE-OUT INSPECTION REPORT</h1><div class="ref">Booking ' . amp_e($booking['booking_number']) . ' &middot; Generated ' . date('d M Y H:i') . '</div>';
        $h .= '<h2>1. Move-Out Details</h2>' . amp_pdf_details($booking, 'Move-Out Date', (string)($mo['move_out_date'] ?? $booking['check_out']));
        $h .= '<h2>2. Keys &amp; Access Returned</h2>' . amp_pdf_keys(amp_keys($conn, $companyId, $bid), true);
        $h .= '<h2>3. Final Inspection Checklist</h2>' . amp_pdf_checklist($conn, $insp, !empty($opts['remarks']));
        $sec = 3;
        if ($mo && ($dmg = amp_damages($conn, (int)$mo['id']))) {
            $h .= '<h2>' . (++$sec) . '. Damages Found</h2><table><tr><th>Area</th><th>Description</th></tr>';
            foreach ($dmg as $d) {
                $h .= '<tr><td style="width:40mm">' . amp_e($d['area'] ?: '-') . '</td><td>' . amp_e($d['description']) . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '<table class="keep"><tr><td class="keepcell">'
            . '<table><tr><td class="secth">' . (++$sec) . '. Move-Out Undertaking</td></tr></table>'
            . '<table><tr><td class="declcell">I, the undersigned <b>' . amp_e($guest) . '</b>'
            . ($booking['id_number'] ? ', holding ID number <b>' . amp_e($booking['id_number']) . '</b>' : '')
            . ', hereby confirm that I have handed over the above-mentioned unit, along with the keys and access cards listed in section 2, to ' . amp_e($company) . '.<br><br>'
            . 'I understand that I no longer have access to the unit or to any belongings left inside, and I accept the final inspection recorded in this report'
            . ($photos ? ', including the attached photos' : '') . '. Any damage, missing item or charge listed may be deducted from the security deposit.'
            . '</td></tr></table>' . amp_pdf_signatures($guest, $company, !empty($opts['with_signature']) ? ($kind === 'out' ? $mo : $mi) : null) . '</td></tr></table>';
        $h .= amp_pdf_photos_html($photos, $sec + 1);
        $name = 'Move_Out_Inspection_' . $ref . '.pdf';
        $title = 'Move-Out Inspection Report - ' . $booking['booking_number'];
    } else {
        $mo = amp_record($conn, $companyId, $bid, 'out');
        if (!$mo) {
            throw new RuntimeException('No move-out for this booking.');
        }
        $f = amp_figures($conn, $booking, $mo);
        $draft = empty($mo['settlement_completed_at']) && $f['pending'] > 0;
        $money = static fn($v) => number_format((float)$v, 2) . ' AED';
        $h .= '<h1>SECURITY DEPOSIT SETTLEMENT STATEMENT</h1><div class="ref">Booking ' . amp_e($booking['booking_number']) . ' &middot; Generated ' . date('d M Y H:i')
            . ($draft ? ' &middot; <b style="color:#b91c1c">DRAFT - some amounts not entered yet</b>' : '') . '</div>';
        $h .= '<h2>1. Stay Details</h2>' . amp_pdf_details($booking, 'Move-Out Date', (string)($mo['move_out_date'] ?? $booking['check_out']));
        $h .= '<h2>2. Security Deposit</h2><table><tr><td class="l">Deposit</td><td class="r">' . $money($f['deposit']) . '</td><td class="l">Held now</td><td class="r">' . $money($f['held']) . '</td></tr>'
            . '<tr><td class="l">Deposit status</td><td colspan="3">' . amp_e(ucwords(str_replace('_', ' ', $f['deposit_status']))) . '</td></tr></table>';
        $h .= '<h2>3. Damages &amp; Charges</h2><table><tr><th style="width:8mm" class="c">#</th><th>Item</th><th style="width:30mm">Type</th><th class="r" style="width:30mm">Amount</th></tr>';
        $n = 0;
        foreach ($f['damages'] as [$label, $amt]) {
            $h .= '<tr><td class="c">' . (++$n) . '</td><td>' . amp_e($label) . '</td><td>Damage</td><td class="r">' . ($amt === null ? '<span style="color:#b45309">Not costed yet</span>' : $money($amt)) . '</td></tr>';
        }
        foreach ($f['charges'] as [$label, $amt, $note]) {
            $h .= '<tr><td class="c">' . (++$n) . '</td><td>' . amp_e($label) . ($note !== '' ? '<div class="muted">' . amp_e($note) . '</div>' : '') . '</td><td>Charge</td><td class="r">'
                . ($amt === null ? '<span style="color:#b45309">Not entered yet</span>' : $money($amt)) . '</td></tr>';
        }
        if (!$n) {
            $h .= '<tr><td colspan="4" class="muted">No damages or charges.</td></tr>';
        }
        $h .= '<tr><th colspan="3" class="r">Total</th><th class="r">' . $money($f['total']) . '</th></tr></table>';
        $h .= '<h2>4. Settlement</h2><table>'
            . '<tr><td class="l" style="width:60%">Security deposit held</td><td class="r">' . $money($f['held']) . '</td></tr>'
            . '<tr><td class="l">Damages and charges</td><td class="r">- ' . $money($f['total']) . '</td></tr>'
            . '<tr><th>Refund due to the guest</th><th class="r">' . $money($f['refund']) . '</th></tr>'
            . ($f['payable'] > 0 ? '<tr><td class="l" style="color:#b91c1c">Not covered by the deposit, payable by the guest</td><td class="r" style="color:#b91c1c;font-weight:bold">' . $money($f['payable']) . '</td></tr>' : '')
            . '<tr><td class="l">Settlement status</td><td>' . amp_e(!empty($mo['settlement_completed_at']) ? 'Complete, ' . amp_date($mo['settlement_completed_at']) : 'Pending - with Accounts for booking') . '</td></tr>'
            . (!empty($mo['settlement_note']) ? '<tr><td class="l">Note</td><td>' . amp_e($mo['settlement_note']) . '</td></tr>' : '')
            . '</table>';
        $name = 'Deposit_Settlement_' . $ref . '.pdf';
        $title = 'Deposit Settlement Statement - ' . $booking['booking_number'];
    }
    try {
        $mpdf = amp_mpdf($conn, $companyId, $title);
        if ($kind === 'settlement' && !empty($draft)) {
            $mpdf->SetWatermarkText('DRAFT');
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($h);
        $bytes = $mpdf->Output('', 'S');
    } finally {
        foreach ($temp as $t) {
            @unlink($t);
        }
    }
    return [$bytes, $name];
}

/**
 * The guest signs on screen: the signature is stored on the move-in / move-out, the
 * form is made with it, and that PDF is filed in the booking documents as the signed
 * copy (same category as an uploaded signed copy). Returns ['ok', 'error', 'name'].
 */
function amp_sign_form(PDO $conn, int $companyId, array $booking, array $rec, string $kind, string $dataUrl, string $signer, array $opts, ?int $userId): array
{
    require_once dirname(__DIR__, 2) . '/realestate/includes/re_digital_signature.php';
    $bid = (int)$booking['id'];
    $table = $kind === 'out' ? 'ars_move_outs' : 'ars_move_ins';
    $rel = 'ars_booking_attachments/' . $companyId . '/' . $bid;
    try {
        $sigPath = re_sig_save_png($dataUrl, $rel);
        $name = re_sig_name($signer, amp_guest_name($booking));
        $conn->prepare("UPDATE $table SET guest_signature_path = ?, guest_signed_name = ?, guest_signed_at = ? WHERE id = ? AND company_id = ?")
            ->execute([$sigPath, $name, amp_now(), (int)$rec['id'], $companyId]);
        [$pdf, $fname] = amp_pdf($conn, $companyId, $booking, $kind, $opts + ['with_signature' => true]);
        $stored = bin2hex(random_bytes(16)) . '.pdf';
        $abs = dirname(__DIR__, 3) . '/uploads/' . $rel . '/' . $stored;
        if (file_put_contents($abs, $pdf) === false) {
            throw new RuntimeException('The signed form could not be saved.');
        }
        @chmod($abs, 0644);
        $orig = preg_replace('/\.pdf$/', '', $fname) . '_signed.pdf';
        $conn->prepare("
            INSERT INTO ars_booking_attachments (company_id, booking_id, original_name, stored_name, relative_path, mime_type, file_size, doc_category, uploaded_by)
            VALUES (?, ?, ?, ?, ?, 'application/pdf', ?, ?, ?)
        ")->execute([$companyId, $bid, $orig, $stored, 'uploads/' . $rel . '/' . $stored, strlen($pdf), $kind === 'out' ? 'move_out_report' : 'move_in_form', $userId]);
        amp_log($conn, $companyId, $booking, 'move_' . $kind . '_signed', ($kind === 'out' ? 'Move-out report' : 'Move-in report') . ' signed digitally by ' . $name, '', $userId);
        return ['ok' => true, 'error' => '', 'name' => $name];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'name' => ''];
    }
}

/** Email a form to the guest. Returns ['ok', 'error', 'to']. */
function amp_email_form(PDO $conn, int $companyId, array $booking, string $kind, array $opts, string $to): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.', 'to' => $to];
    }
    // A signed copy is sent when there is one, unless the blank form is asked for
    $signed = $kind !== 'settlement' && empty($opts['unsigned']) ? (amp_signed_forms($conn, $companyId, (int)$booking['id'], $kind, amp_record($conn, $companyId, (int)$booking['id'], $kind))[0] ?? null) : null;
    $tmp = amp_temp_dir() . '/amp_' . bin2hex(random_bytes(6)) . '.pdf';
    if ($signed) {
        $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$signed['relative_path'], '/');
        if (!is_readable($abs) || !@copy($abs, $tmp)) {
            return ['ok' => false, 'error' => 'The signed copy could not be read.', 'to' => $to];
        }
        $fname = $signed['original_name'];
    } else {
        [$bytes, $fname] = amp_pdf($conn, $companyId, $booking, $kind, $opts);
        file_put_contents($tmp, $bytes);
    }
    $label = ['in' => 'Move-in inspection report', 'out' => 'Move-out inspection report', 'settlement' => 'Deposit settlement statement'][$kind];
    $company = amp_company_name($conn, $companyId);
    $body = '<p>Dear ' . amp_e(amp_guest_name($booking)) . ',</p>'
        . '<p>Please find attached the <strong>' . amp_e($label) . '</strong>' . ($signed ? ' (signed copy)' : '') . ' for booking <strong>' . amp_e($booking['booking_number']) . '</strong>'
        . ($booking['unit_number'] ? ', unit ' . amp_e(trim(($booking['building_name'] ?? '') . ' ' . $booking['unit_number'])) : '') . '.</p>'
        . (!$signed && $kind !== 'settlement' ? '<p>Please review it, sign it and return a copy to us.</p>' : '')
        . '<p>Thank you,<br>' . amp_e($company) . '</p>';
    try {
        require_once dirname(__DIR__, 3) . '/includes/email_service.php';
        $mailer = new EmailService($conn);
        $send = $mailer->sendCustomEmail($to, $label . ' - ' . $booking['booking_number'], $body, strip_tags($body),
            [['path' => $tmp, 'name' => $fname, 'type' => 'application/pdf']]);
    } catch (Throwable $e) {
        $send = ['success' => false, 'error' => $e->getMessage()];
    }
    @unlink($tmp);
    if (empty($send['success'])) {
        return ['ok' => false, 'error' => (string)($send['error'] ?? 'Email could not be sent. Check Email Settings (SMTP).'), 'to' => $to];
    }
    return ['ok' => true, 'error' => '', 'to' => $to, 'signed' => (bool)$signed];
}
