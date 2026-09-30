<?php
/**
 * Jarvis API v1 — the ARS Home Rentals module.
 *
 * Loaded only by ../index.php, after the key has been checked; opened directly it
 * answers nothing. $conn, $action and the jarvis_api_* helpers come from there.
 *
 * View only: every action reads, none writes. Everything is for the ARS company (the
 * one with code 'ARS'), the same company every ARS page works in. Dates are YYYY-MM-DD
 * and "today" is Dubai's today. Balances are always the live one the booking page
 * shows (jarvis_api_balance_due), never the stored ars_bookings.balance_due.
 *
 *   ?module=ars&action=summary                      the Command Center, in numbers
 *   ?module=ars&action=arrivals&date=               who checks in that day
 *   ?module=ars&action=checkouts&date=              who checks out that day
 *   ?module=ars&action=in_house                     everyone checked in right now
 *   ?module=ars&action=booking&q=                   one booking in full, or the matches
 *   ?module=ars&action=bookings&from=&to=&status=&by=   a list (by: stay|check_in|check_out|created)
 *   ?module=ars&action=balances&scope=              who still owes (scope: active|all)
 *   ?module=ars&action=guest&q=                     a guest and their stays
 *   ?module=ars&action=unit&q=                      a unit's status and stays, or every unit
 *   ?module=ars&action=availability&from=&to=       units free for those dates
 *   ?module=ars&action=housekeeping&date=&status=   cleaning jobs
 *   ?module=ars&action=maintenance&status=          repair requests on ARS units (open|all)
 *   ?module=ars&action=payments&from=&to=           money received
 *   ?module=ars&action=deposits&status=             security deposits
 *   ?module=ars&action=activity&q=&limit=           the latest booking activity
 *
 * Left out on purpose: guest ID numbers and documents, attachments, pricing rules.
 */

declare(strict_types=1);

if (!defined('JARVIS_API')) {
    http_response_code(404);
    exit;
}


/**
 * What the guest still owes — the "Amount to collect now" on the booking page.
 * A copy of the rule in modules/ars/booking_view.php; if that changes, change this.
 *
 * 1. Live invoices, when the booking has any: their open balances, credit notes
 *    subtracting. Not ars_bookings.balance_due, which extension / service invoices
 *    never update, so on an extended stay it is wrong.
 * 2. But once staff have typed a Total amount on any payment row, the Payments
 *    table is the office's own statement and wins: each typed row carries what it
 *    leaves unpaid down to the next, and the last row's outstanding is the answer.
 */
function jarvis_api_balance_due(array $b, array $payments): float
{
    $hasDocs = (int)($b['invoice_count'] ?? 0) > 0;
    $stayTotal = $hasDocs ? (float)$b['net_invoiced'] : (float)$b['total_amount'];
    $balance = $hasDocs ? (float)$b['open_balance'] : (float)$b['balance_due'];

    $manual = false;
    $received = 0.0;
    $outstanding = 0.0;
    foreach ($payments as $p) {
        $amount = (float)$p['amount'];
        $received += $amount;
        if ($p['total_amount'] !== null && $p['total_amount'] !== '') {
            $manual = true;
            $outstanding = max(0.0, round($outstanding + ((float)$p['total_amount'] - $amount), 2));
        } else {
            $outstanding = max(0.0, round($stayTotal - $received, 2));
        }
    }
    return round($manual ? $outstanding : $balance, 2);
}


// ---------- shared ----------

const JARVIS_ARS_ACTIVE = "('confirmed','checked_in')";
const JARVIS_ARS_LIMIT = 200;

/** The ARS company, found the way getArsCompanyId() finds it. */
function jarvis_ars_company(PDO $conn): int
{
    $id = (int)($conn->query("SELECT id FROM companies WHERE code = 'ARS' AND is_active = 1 LIMIT 1")->fetchColumn() ?: 0);
    if ($id === 0) {
        jarvis_api_error('no_ars_company', 'No active company with code ARS.', 500);
    }
    return $id;
}

function jarvis_ars_today(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
}

/** A date from the query string: YYYY-MM-DD, or $default when it is missing. */
function jarvis_ars_date(string $key, ?string $default): ?string
{
    $v = trim((string)($_GET[$key] ?? ''));
    if ($v === '') {
        return $default;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$parsed || $parsed->format('Y-m-d') !== $v) {
        jarvis_api_error('bad_date', "$key must be YYYY-MM-DD.", 400);
    }
    return $v;
}

function jarvis_ars_add_days(string $date, int $days): string
{
    return (new DateTime($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/** Some columns are added by ARS pages on first use, so they are checked, not assumed. */
function jarvis_ars_has_column(PDO $conn, string $table, string $column): bool
{
    static $seen = [];
    $k = "$table.$column";
    if (!isset($seen[$k])) {
        try {
            $seen[$k] = (bool)$conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($column))->fetch();
        } catch (Throwable $e) {
            $seen[$k] = false;
        }
    }
    return $seen[$k];
}

/** The units ARS works with — a copy of ars_short_term_units_where(). */
function jarvis_ars_units_where(int $cid, string $a = 'u'): array
{
    $sql = "{$a}.rental_mode IN ('short_term','both')
            AND (
                {$a}.company_id = ?
                OR {$a}.id IN (SELECT DISTINCT unit_id FROM ars_bookings WHERE company_id = ?)
                OR NOT EXISTS (SELECT 1 FROM re_units ux WHERE ux.company_id = ? AND ux.rental_mode IN ('short_term','both') LIMIT 1)
            )";
    return [$sql, [$cid, $cid, $cid]];
}

/** When a stay really ends: the early departure when one was recorded. */
function jarvis_ars_occupancy_end(PDO $conn, string $a = 'b'): string
{
    return jarvis_ars_has_column($conn, 'ars_bookings', 'actual_check_out')
        ? "COALESCE({$a}.actual_check_out, {$a}.check_out)"
        : "{$a}.check_out";
}

/**
 * Bookings with everything jarvis_api_balance_due() needs. $where is ANDed after the
 * company; it may use b (booking), g (guest), u (unit) and bd (building).
 */
function jarvis_ars_fetch(PDO $conn, int $cid, string $where, array $params, string $order, int $limit = JARVIS_ARS_LIMIT): array
{
    $stmt = $conn->prepare("
        SELECT b.*, fd.invoice_count, fd.open_balance, fd.net_invoiced,
               g.first_name, g.last_name, g.phone AS guest_phone, g.email AS guest_email,
               u.unit_number, bd.name AS building_name
        FROM ars_bookings b
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bd ON bd.id = u.building_id
        LEFT JOIN (
            SELECT booking_id, company_id,
                   SUM(document_type <> 'credit_note') AS invoice_count,
                   SUM(CASE WHEN document_type = 'credit_note' THEN -balance_due ELSE balance_due END) AS open_balance,
                   SUM(CASE WHEN document_type = 'credit_note' THEN -total_amount ELSE total_amount END) AS net_invoiced
            FROM ars_financial_documents
            WHERE LOWER(status) NOT IN ('draft','voided','reversed')
            GROUP BY booking_id, company_id
        ) fd ON fd.booking_id = b.id AND fd.company_id = b.company_id
        WHERE b.company_id = ? AND ($where)
        ORDER BY $order
        LIMIT " . max(1, $limit));
    $stmt->execute(array_merge([$cid], $params));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Every payment row of these bookings, in the booking page's order.
    $payments = [];
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $pm = $conn->prepare('SELECT * FROM ars_booking_payments
            WHERE booking_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY payment_date, id');
        $pm->execute($ids);
        foreach ($pm->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (!array_key_exists('total_amount', $p)) {
                $p['total_amount'] = null;
            }
            $payments[(int)$p['booking_id']][] = $p;
        }
    }
    foreach ($rows as &$r) {
        $r['_payments'] = $payments[(int)$r['id']] ?? [];
    }
    unset($r);
    return $rows;
}

/** One booking as Jarvis sees it in a list. */
function jarvis_ars_row(array $r): array
{
    $hasDocs = (int)($r['invoice_count'] ?? 0) > 0;
    $paid = 0.0;
    foreach ($r['_payments'] as $p) {
        $paid += (float)$p['amount'];
    }
    $out = [
        'booking_id'     => (int)$r['id'],
        'booking_number' => $r['booking_number'],
        'status'         => $r['status'],
        'check_in'       => $r['check_in'],
        'check_out'      => $r['check_out'],
        'nights'         => (int)($r['nights'] ?? 0),
        'unit'           => $r['unit_number'],
        'building'       => $r['building_name'],
        'guest'          => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
        'guest_phone'    => $r['guest_phone'],
        'guests'         => (int)($r['num_guests'] ?? 0),
        'total'          => round($hasDocs ? (float)$r['net_invoiced'] : (float)$r['total_amount'], 2),
        'paid'           => round($paid, 2),
        'balance_due'    => jarvis_api_balance_due($r, $r['_payments']),
        'payment_status' => $r['payment_status'],
    ];
    if (!empty($r['actual_check_out']) && (string)$r['actual_check_out'] !== (string)$r['check_out']) {
        $out['left_early_on'] = $r['actual_check_out'];
    }
    if (!empty($r['booking_source'])) {
        $out['source'] = $r['booking_source'];
    }
    return $out;
}

function jarvis_ars_rows(array $rows): array
{
    return array_map('jarvis_ars_row', $rows);
}

function jarvis_ars_like(string $q): string
{
    return '%' . addcslashes($q, '%_\\') . '%';
}

/** The search text, required for the lookups that find one thing. */
function jarvis_ars_q(): string
{
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        jarvis_api_error('missing_q', 'q is required.', 400);
    }
    return $q;
}

/** The booking search the Reservations page uses, plus phone and full name. */
function jarvis_ars_booking_search(string $q): array
{
    $like = jarvis_ars_like($q);
    return [
        "(b.booking_number LIKE ? OR g.first_name LIKE ? OR g.last_name LIKE ?
          OR CONCAT(g.first_name, ' ', g.last_name) LIKE ? OR g.phone LIKE ? OR u.unit_number LIKE ?)",
        [$like, $like, $like, $like, $like, $like],
    ];
}

/** A query that may fail on a server that has never opened the page that makes its table. */
function jarvis_ars_try(callable $fn, $fallback = null)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        return $fallback;
    }
}

function jarvis_ars_cleaning_company(PDO $conn, int $cid): int
{
    return (int)jarvis_ars_try(function () use ($conn, $cid) {
        $s = $conn->prepare('SELECT cleaning_company_id FROM ars_company_settings WHERE company_id = ? LIMIT 1');
        $s->execute([$cid]);
        return $s->fetchColumn() ?: 0;
    }, 0);
}

$cid = jarvis_ars_company($conn);
$today = jarvis_ars_today();

// ---------- today ----------

if ($action === 'summary') {
    $count = function (string $sql, array $params) use ($conn): int {
        $s = $conn->prepare($sql);
        $s->execute($params);
        return (int)$s->fetchColumn();
    };

    // The Command Center's own definitions (modules/ars/index.php).
    $arrivals = $count("SELECT COUNT(*) FROM ars_bookings WHERE company_id = ? AND check_in = ? AND status IN ('confirmed','checked_in','pending')", [$cid, $today]);
    $departures = $count("SELECT COUNT(*) FROM ars_bookings WHERE company_id = ? AND check_out = ? AND status IN ('checked_in','checked_out','confirmed')", [$cid, $today]);
    $inHouse = $count("SELECT COUNT(*) FROM ars_bookings WHERE company_id = ? AND status = 'checked_in'", [$cid]);
    [$uw, $up] = jarvis_ars_units_where($cid);
    $units = $count("SELECT COUNT(*) FROM re_units u WHERE $uw", $up);
    $occupied = $count("SELECT COUNT(DISTINCT unit_id) FROM ars_bookings WHERE company_id = ? AND status IN " . JARVIS_ARS_ACTIVE . " AND unit_id IS NOT NULL", [$cid]);
    $active = $count("SELECT COUNT(*) FROM ars_bookings WHERE company_id = ? AND status IN " . JARVIS_ARS_ACTIVE, [$cid]);
    $new24h = $count("SELECT COUNT(*) FROM ars_bookings WHERE company_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", [$cid]);
    $monthStart = substr($today, 0, 8) . '01';
    $s = $conn->prepare('SELECT COALESCE(SUM(amount), 0) FROM ars_booking_payments WHERE company_id = ? AND payment_date BETWEEN ? AND ?');
    $s->execute([$cid, $monthStart, $today]);
    $received = round((float)$s->fetchColumn(), 2);

    // Balances with the live rule, so this can say less than the Command Center tile.
    $owing = array_filter(jarvis_ars_rows(jarvis_ars_fetch($conn, $cid, 'b.status IN ' . JARVIS_ARS_ACTIVE, [], 'b.id', 2000)), fn($b) => $b['balance_due'] > 0.009);

    $hk = null;
    if ($clean = jarvis_ars_cleaning_company($conn, $cid)) {
        $hk = jarvis_ars_try(fn() => $count("SELECT COUNT(*) FROM make_order mo WHERE mo.company_id = ? AND (mo.client_name = ? OR mo.ars_booking_id IS NOT NULL)
            AND mo.status IN ('confirmed','scheduled','in_progress')", [$clean, 'ARS Home Rentals']));
    }
    $maint = jarvis_ars_try(fn() => $count("SELECT COUNT(*) FROM re_maintenance_requests mr INNER JOIN re_units u ON u.id = mr.unit_id
        WHERE $uw AND mr.status IN ('pending','in_progress','open','assigned')", $up));
    $deposits = jarvis_ars_has_column($conn, 'ars_bookings', 'deposit_status')
        ? $count("SELECT COUNT(*) FROM ars_bookings WHERE company_id = ? AND deposit_status = 'pending'", [$cid])
        : null;

    jarvis_api_send(['ok' => true, 'date' => $today, 'summary' => [
        'arrivals_today'        => $arrivals,
        'departures_today'      => $departures,
        'in_house'              => $inHouse,
        'units'                 => $units,
        'units_occupied'        => $occupied,
        'occupancy_percent'     => $units > 0 ? (int)min(100, round($occupied / $units * 100)) : 0,
        'active_stays'          => $active,
        'balances_due_count'    => count($owing),
        'balances_due_total'    => round(array_sum(array_column($owing, 'balance_due')), 2),
        'housekeeping_open'     => $hk,
        'maintenance_open'      => $maint,
        'deposits_pending'      => $deposits,
        'new_bookings_24h'      => $new24h,
        'received_this_month'   => $received,
    ]]);
}

if ($action === 'arrivals') {
    $date = jarvis_ars_date('date', $today);
    $rows = jarvis_ars_fetch($conn, $cid, "b.check_in = ? AND b.status IN ('confirmed','checked_in','pending')", [$date], 'bd.name, u.unit_number, b.booking_number', 500);
    jarvis_api_send(['ok' => true, 'date' => $date, 'count' => count($rows), 'arrivals' => jarvis_ars_rows($rows)]);
}

if ($action === 'checkouts') {
    // The same rule as the Command Center's "Departures today".
    $date = jarvis_ars_date('date', $today);
    $rows = jarvis_ars_fetch($conn, $cid, "b.check_out = ? AND b.status IN ('checked_in','checked_out','confirmed')", [$date], 'bd.name, u.unit_number, b.booking_number', 500);
    jarvis_api_send(['ok' => true, 'date' => $date, 'count' => count($rows), 'checkouts' => jarvis_ars_rows($rows)]);
}

if ($action === 'in_house') {
    $rows = jarvis_ars_fetch($conn, $cid, "b.status = 'checked_in'", [], 'b.check_out, bd.name, u.unit_number', 500);
    jarvis_api_send(['ok' => true, 'date' => $today, 'count' => count($rows), 'in_house' => jarvis_ars_rows($rows)]);
}

// ---------- bookings and guests ----------

if ($action === 'booking') {
    $q = jarvis_ars_q();
    $rows = jarvis_ars_fetch($conn, $cid, 'b.booking_number = ?', [$q], 'b.id');
    if (!$rows) {
        [$w, $p] = jarvis_ars_booking_search($q);
        $rows = jarvis_ars_fetch($conn, $cid, $w, $p, 'b.check_in DESC', 20);
    }
    if (count($rows) !== 1) {
        jarvis_api_send(['ok' => true, 'q' => $q, 'count' => count($rows), 'matches' => jarvis_ars_rows($rows)]);
    }

    $r = $rows[0];
    $id = (int)$r['id'];
    $booking = jarvis_ars_row($r) + [
        'guest_email'         => $r['guest_email'],
        'nightly_rate'        => (float)($r['rate_override'] ?? 0) > 0 ? (float)$r['rate_override'] : (float)($r['nightly_rate'] ?? 0),
        'vat_amount'          => (float)($r['vat_amount'] ?? 0),
        'special_requests'    => $r['special_requests'] ?? null,
        'internal_notes'      => $r['internal_notes'] ?? null,
        'created_at'          => $r['created_at'] ?? null,
    ];
    if (($r['status'] ?? '') === 'cancelled') {
        $booking['cancelled_at'] = $r['cancelled_at'] ?? null;
        $booking['cancellation_reason'] = $r['cancellation_reason'] ?? null;
    }
    if (isset($r['deposit_status'])) {
        $booking['deposit'] = [
            'amount'    => (float)($r['deposit_amount'] ?? 0),
            'status'    => $r['deposit_status'],
            'forfeited' => (float)($r['deposit_forfeited_amount'] ?? 0),
        ];
    }
    $booking['payments'] = array_map(fn($p) => [
        'date'      => $p['payment_date'],
        'amount'    => (float)$p['amount'],
        'method'    => $p['payment_method'] ?? null,
        'type'      => $p['payment_type'] ?? null,
        'reference' => $p['reference_number'] ?? null,
        'notes'     => $p['notes'] ?? null,
    ], $r['_payments']);
    $booking['invoices'] = jarvis_ars_try(function () use ($conn, $cid, $id) {
        $s = $conn->prepare('SELECT document_type, document_number, document_date, status, total_amount, balance_due
            FROM ars_financial_documents WHERE booking_id = ? AND company_id = ? ORDER BY document_date, id');
        $s->execute([$id, $cid]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }, []);
    $booking['extensions'] = jarvis_ars_try(function () use ($conn, $cid, $id) {
        $s = $conn->prepare('SELECT * FROM ars_booking_extension_log WHERE booking_id = ? AND company_id = ? ORDER BY extended_from, id');
        $s->execute([$id, $cid]);
        return array_map(fn($e) => [
            'from'   => $e['extended_from'],
            'to'     => $e['extended_to'],
            'nights' => (int)$e['nights'],
            'rate'   => isset($e['rate_per_night']) ? (float)$e['rate_per_night'] : null,
            'amount' => isset($e['amount']) ? (float)$e['amount'] : null,
            'note'   => $e['note'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }, []);
    $credit = jarvis_ars_try(function () use ($conn, $cid, $id) {
        $s = $conn->prepare("SELECT COALESCE(SUM(balance), 0) FROM ars_guest_credits WHERE booking_id = ? AND company_id = ? AND status = 'open'");
        $s->execute([$id, $cid]);
        return round((float)$s->fetchColumn(), 2);
    }, 0.0);
    if ($credit > 0) {
        $booking['guest_credit'] = $credit;
    }
    jarvis_api_send(['ok' => true, 'booking' => $booking]);
}

if ($action === 'bookings') {
    $from = jarvis_ars_date('from', $today);
    $to = jarvis_ars_date('to', jarvis_ars_add_days($from, 30));
    $by = (string)($_GET['by'] ?? 'stay');
    $cols = ['check_in' => 'b.check_in', 'check_out' => 'b.check_out', 'created' => 'DATE(b.created_at)'];
    if ($by === 'stay') {
        $where = 'b.check_in <= ? AND b.check_out >= ?'; // any stay touching the range
        $params = [$to, $from];
    } elseif (isset($cols[$by])) {
        $where = "{$cols[$by]} BETWEEN ? AND ?";
        $params = [$from, $to];
    } else {
        jarvis_api_error('bad_by', 'by must be stay, check_in, check_out or created.', 400);
    }
    $status = (string)($_GET['status'] ?? '');
    if ($status !== '') {
        $where .= ' AND b.status = ?';
        $params[] = $status;
    }
    $rows = jarvis_ars_fetch($conn, $cid, $where, $params, 'b.check_in, bd.name, u.unit_number');
    jarvis_api_send(['ok' => true, 'from' => $from, 'to' => $to, 'by' => $by, 'status' => $status ?: null,
        'count' => count($rows), 'capped_at' => JARVIS_ARS_LIMIT, 'bookings' => jarvis_ars_rows($rows)]);
}

if ($action === 'balances') {
    // Every candidate, then the live rule — filtering on the stored balance_due would
    // miss extended stays, which is exactly where money gets forgotten.
    $scope = (string)($_GET['scope'] ?? 'active');
    if ($scope === 'all') {
        $where = "b.status IN ('confirmed','checked_in','checked_out','completed') AND b.check_out >= ?";
        $params = [jarvis_ars_add_days($today, -365)];
    } else {
        $scope = 'active';
        $where = 'b.status IN ' . JARVIS_ARS_ACTIVE;
        $params = [];
    }
    $owing = array_values(array_filter(jarvis_ars_rows(jarvis_ars_fetch($conn, $cid, $where, $params, 'b.id', 5000)), fn($b) => $b['balance_due'] > 0.009));
    usort($owing, fn($a, $b) => $b['balance_due'] <=> $a['balance_due']);
    jarvis_api_send(['ok' => true, 'scope' => $scope, 'count' => count($owing),
        'total' => round(array_sum(array_column($owing, 'balance_due')), 2), 'balances' => $owing]);
}

if ($action === 'guest') {
    $q = jarvis_ars_q();
    $like = jarvis_ars_like($q);
    $s = $conn->prepare("SELECT id, first_name, last_name, email, phone, phone_alt, nationality, notes, created_at
        FROM ars_guests
        WHERE company_id = ? AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?
              OR phone LIKE ? OR phone_alt LIKE ? OR email LIKE ?)
        ORDER BY first_name, last_name LIMIT 20");
    $s->execute([$cid, $like, $like, $like, $like, $like, $like]);
    $guests = $s->fetchAll(PDO::FETCH_ASSOC);
    if (count($guests) !== 1) {
        jarvis_api_send(['ok' => true, 'q' => $q, 'count' => count($guests), 'matches' => array_map(fn($g) => [
            'guest_id' => (int)$g['id'], 'name' => trim($g['first_name'] . ' ' . $g['last_name']), 'phone' => $g['phone'], 'email' => $g['email'],
        ], $guests)]);
    }
    $g = $guests[0];
    $stays = jarvis_ars_rows(jarvis_ars_fetch($conn, $cid, 'b.guest_id = ?', [(int)$g['id']], 'b.check_in DESC', 100));
    jarvis_api_send(['ok' => true, 'guest' => [
        'guest_id'    => (int)$g['id'],
        'name'        => trim($g['first_name'] . ' ' . $g['last_name']),
        'phone'       => $g['phone'],
        'phone_alt'   => $g['phone_alt'],
        'email'       => $g['email'],
        'nationality' => $g['nationality'],
        'notes'       => $g['notes'],
        'guest_since' => $g['created_at'],
        'stays_count' => count($stays),
        'total_paid'  => round(array_sum(array_column($stays, 'paid')), 2),
        'total_owed'  => round(array_sum(array_column($stays, 'balance_due')), 2),
        'stays'       => $stays,
    ]]);
}

// ---------- units ----------

if ($action === 'unit') {
    [$uw, $up] = jarvis_ars_units_where($cid);
    $q = trim((string)($_GET['q'] ?? ''));
    $where = $uw;
    $params = $up;
    if ($q !== '') {
        $like = jarvis_ars_like($q);
        $where .= " AND (u.unit_number LIKE ? OR bd.name LIKE ? OR CONCAT(bd.name, ' ', u.unit_number) LIKE ?)";
        array_push($params, $like, $like, $like);
    }
    $s = $conn->prepare("SELECT u.*, bd.name AS building_name FROM re_units u LEFT JOIN re_buildings bd ON bd.id = u.building_id
        WHERE $where ORDER BY bd.name, u.unit_number LIMIT 300");
    $s->execute($params);
    $units = $s->fetchAll(PDO::FETCH_ASSOC);

    $occEnd = jarvis_ars_occupancy_end($conn);
    $now = function (int $unitId) use ($conn, $cid, $today, $occEnd) {
        $s = $conn->prepare("SELECT b.booking_number, b.status, b.check_in, b.check_out, TRIM(CONCAT(g.first_name, ' ', g.last_name)) AS guest
            FROM ars_bookings b LEFT JOIN ars_guests g ON g.id = b.guest_id
            WHERE b.company_id = ? AND b.unit_id = ? AND b.status NOT IN ('cancelled','expired')
              AND (b.status = 'checked_in' OR (b.check_in <= ? AND $occEnd > ?))
            ORDER BY b.status = 'checked_in' DESC LIMIT 1");
        $s->execute([$cid, $unitId, $today, $today]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $blockedToday = function (int $unitId) use ($conn, $today) {
        $s = $conn->prepare('SELECT start_date, end_date, reason FROM ars_blocked_dates WHERE unit_id = ? AND start_date <= ? AND end_date > ? LIMIT 1');
        $s->execute([$unitId, $today, $today]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $describe = function (array $u) {
        return [
            'unit_id'    => (int)$u['id'],
            'unit'       => $u['unit_number'],
            'building'   => $u['building_name'],
            'title'      => $u['listing_title'] ?? null,
            'nightly_rate' => isset($u['nightly_rate']) ? (float)$u['nightly_rate'] : null,
            'max_guests' => isset($u['max_guests']) ? (int)$u['max_guests'] : null,
            'unit_type'  => $u['unit_type'] ?? null,
        ];
    };

    if (count($units) !== 1) {
        $list = array_map(function ($u) use ($describe, $now, $blockedToday) {
            $stay = $now((int)$u['id']);
            $block = $stay ? null : $blockedToday((int)$u['id']);
            return $describe($u) + [
                'today' => $stay ? 'occupied' : ($block ? 'blocked' : 'free'),
                'guest' => $stay['guest'] ?? null,
                'until' => $stay['check_out'] ?? ($block['end_date'] ?? null),
            ];
        }, $units);
        jarvis_api_send(['ok' => true, 'q' => $q ?: null, 'date' => $today, 'count' => count($list), 'units' => $list]);
    }

    $u = $units[0];
    $unitId = (int)$u['id'];
    $next = jarvis_ars_fetch($conn, $cid, "b.unit_id = ? AND b.check_in > ? AND b.status IN ('pending','confirmed')", [$unitId, $today], 'b.check_in', 5);
    $past = jarvis_ars_fetch($conn, $cid, "b.unit_id = ? AND b.check_out <= ? AND b.status IN ('checked_out','completed')", [$unitId, $today], 'b.check_out DESC', 10);
    $s = $conn->prepare('SELECT start_date, end_date, reason FROM ars_blocked_dates WHERE unit_id = ? AND end_date > ? ORDER BY start_date LIMIT 20');
    $s->execute([$unitId, $today]);
    $current = $now($unitId);
    $currentRow = $current ? jarvis_ars_fetch($conn, $cid, 'b.booking_number = ?', [$current['booking_number']], 'b.id', 1) : [];
    jarvis_api_send(['ok' => true, 'date' => $today, 'unit' => $describe($u) + [
        'current_stay'  => $currentRow ? jarvis_ars_row($currentRow[0]) : null,
        'next_bookings' => jarvis_ars_rows($next),
        'blocked_dates' => $s->fetchAll(PDO::FETCH_ASSOC),
        'recent_stays'  => jarvis_ars_rows($past),
    ]]);
}

if ($action === 'availability') {
    // ars_check_availability()'s rule, for every ARS unit at once: a stay or a block
    // overlapping [from, to) makes a unit busy. to is the checkout day.
    $from = jarvis_ars_date('from', null);
    $to = jarvis_ars_date('to', null);
    if (!$from || !$to || $to <= $from) {
        jarvis_api_error('bad_range', 'from and to are required, and to (the checkout day) must be after from.', 400);
    }
    [$uw, $up] = jarvis_ars_units_where($cid);
    $occEnd = jarvis_ars_occupancy_end($conn);
    $s = $conn->prepare("SELECT u.*, bd.name AS building_name,
            EXISTS(SELECT 1 FROM ars_bookings b WHERE b.unit_id = u.id AND b.status NOT IN ('cancelled','expired')
                   AND b.check_in < ? AND $occEnd > ?) AS booked,
            EXISTS(SELECT 1 FROM ars_blocked_dates x WHERE x.unit_id = u.id AND x.start_date < ? AND x.end_date > ?) AS blocked
        FROM re_units u LEFT JOIN re_buildings bd ON bd.id = u.building_id
        WHERE $uw ORDER BY bd.name, u.unit_number");
    $s->execute(array_merge([$to, $from, $to, $from], $up));
    $free = [];
    $busy = 0;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if ($u['booked'] || $u['blocked']) {
            $busy++;
            continue;
        }
        $free[] = [
            'unit'         => $u['unit_number'],
            'building'     => $u['building_name'],
            'title'        => $u['listing_title'] ?? null,
            'nightly_rate' => isset($u['nightly_rate']) ? (float)$u['nightly_rate'] : null,
            'max_guests'   => isset($u['max_guests']) ? (int)$u['max_guests'] : null,
        ];
    }
    jarvis_api_send(['ok' => true, 'from' => $from, 'to' => $to, 'free_count' => count($free), 'busy_count' => $busy, 'free' => $free]);
}

// ---------- operations and money ----------

if ($action === 'housekeeping') {
    $date = jarvis_ars_date('date', $today);
    $clean = jarvis_ars_cleaning_company($conn, $cid);
    if (!$clean) {
        jarvis_api_send(['ok' => true, 'date' => $date, 'count' => 0, 'jobs' => [], 'note' => 'No cleaning company is set in ARS settings.']);
    }
    // The Housekeeping board's own query (modules/ars/housekeeping.php).
    $where = "mo.company_id = ? AND (mo.client_name = ? OR mo.ars_booking_id IS NOT NULL)
              AND DATE(COALESCE(mo.service_date, mo.date)) = ? AND mo.status != 'cancelled'";
    $params = [$clean, 'ARS Home Rentals', $date];
    $status = (string)($_GET['status'] ?? '');
    if ($status !== '') {
        $where .= ' AND mo.status = ?';
        $params[] = $status;
    }
    $s = $conn->prepare("SELECT mo.*, b.booking_number, g.first_name, g.last_name, u.unit_number, bd.name AS building_name
        FROM make_order mo
        LEFT JOIN ars_bookings b ON b.id = mo.ars_booking_id
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bd ON bd.id = u.building_id
        WHERE $where
        ORDER BY FIELD(mo.status, 'confirmed', 'scheduled', 'in_progress', 'completed'), mo.time, mo.id
        LIMIT 300");
    $s->execute($params);
    $jobs = array_map(fn($j) => [
        'job_id'   => (int)$j['id'],
        'status'   => $j['status'],
        'date'     => substr((string)($j['service_date'] ?? $j['date'] ?? ''), 0, 10),
        'time'     => $j['time'] ?? null,
        'unit'     => $j['unit_number'],
        'building' => $j['building_name'],
        'booking_number' => $j['booking_number'],
        'guest'    => trim(($j['first_name'] ?? '') . ' ' . ($j['last_name'] ?? '')) ?: null,
    ], $s->fetchAll(PDO::FETCH_ASSOC));
    jarvis_api_send(['ok' => true, 'date' => $date, 'count' => count($jobs), 'jobs' => $jobs]);
}

if ($action === 'maintenance') {
    [$uw, $up] = jarvis_ars_units_where($cid);
    $all = ($_GET['status'] ?? 'open') === 'all';
    $where = $uw . ($all ? '' : " AND mr.status IN ('pending','in_progress','open','assigned')");
    $s = $conn->prepare("SELECT mr.*, u.unit_number, bd.name AS building_name
        FROM re_maintenance_requests mr
        INNER JOIN re_units u ON u.id = mr.unit_id
        LEFT JOIN re_buildings bd ON bd.id = u.building_id
        WHERE $where ORDER BY mr.request_date DESC, mr.id DESC LIMIT 200");
    $s->execute($up);
    $requests = array_map(fn($m) => [
        'request_id'   => (int)$m['id'],
        'status'       => $m['status'],
        'priority'     => $m['priority'] ?? null,
        'category'     => $m['category'] ?? null,
        'description'  => $m['description'] ?? null,
        'unit'         => $m['unit_number'],
        'building'     => $m['building_name'],
        'requested_on' => $m['request_date'] ?? null,
        'completed_at' => $m['completed_at'] ?? null,
        'notes'        => $m['notes'] ?? null,
    ], $s->fetchAll(PDO::FETCH_ASSOC));
    jarvis_api_send(['ok' => true, 'status' => $all ? 'all' : 'open', 'count' => count($requests), 'requests' => $requests]);
}

if ($action === 'payments') {
    $from = jarvis_ars_date('from', substr($today, 0, 8) . '01');
    $to = jarvis_ars_date('to', $today);
    $s = $conn->prepare("SELECT p.*, b.booking_number, g.first_name, g.last_name, u.unit_number, bd.name AS building_name
        FROM ars_booking_payments p
        LEFT JOIN ars_bookings b ON b.id = p.booking_id
        LEFT JOIN ars_guests g ON g.id = b.guest_id
        LEFT JOIN re_units u ON u.id = b.unit_id
        LEFT JOIN re_buildings bd ON bd.id = u.building_id
        WHERE p.company_id = ? AND p.payment_date BETWEEN ? AND ?
        ORDER BY p.payment_date, p.id LIMIT 1000");
    $s->execute([$cid, $from, $to]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $byMethod = [];
    foreach ($rows as $p) {
        $m = (string)($p['payment_method'] ?? 'other');
        $byMethod[$m] = round(($byMethod[$m] ?? 0) + (float)$p['amount'], 2);
    }
    jarvis_api_send(['ok' => true, 'from' => $from, 'to' => $to, 'count' => count($rows),
        'total' => round(array_sum(array_map(fn($p) => (float)$p['amount'], $rows)), 2), 'by_method' => $byMethod,
        'payments' => array_map(fn($p) => [
            'date'      => $p['payment_date'],
            'amount'    => (float)$p['amount'],
            'method'    => $p['payment_method'] ?? null,
            'type'      => $p['payment_type'] ?? null,
            'reference' => $p['reference_number'] ?? null,
            'booking_number' => $p['booking_number'],
            'guest'     => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
            'unit'      => $p['unit_number'],
            'building'  => $p['building_name'],
        ], $rows)]);
}

if ($action === 'deposits') {
    if (!jarvis_ars_has_column($conn, 'ars_bookings', 'deposit_status')) {
        jarvis_api_send(['ok' => true, 'count' => 0, 'deposits' => [], 'note' => 'Security deposits are not in use in ARS yet.']);
    }
    $status = (string)($_GET['status'] ?? '');
    $where = $status !== '' ? 'b.deposit_status = ?' : "b.deposit_status NOT IN ('none','')";
    $params = $status !== '' ? [$status] : [];
    $rows = jarvis_ars_fetch($conn, $cid, $where, $params, 'b.check_in DESC');
    $totals = [];
    $deposits = array_map(function ($r) use (&$totals) {
        $amount = round((float)($r['deposit_amount'] ?? 0), 2);
        $totals[$r['deposit_status']] = round(($totals[$r['deposit_status']] ?? 0) + $amount, 2);
        return [
            'booking_number' => $r['booking_number'],
            'status'         => $r['deposit_status'],
            'amount'         => $amount,
            'forfeited'      => round((float)($r['deposit_forfeited_amount'] ?? 0), 2),
            'method'         => $r['deposit_payment_method'] ?? null,
            'booking_status' => $r['status'],
            'check_in'       => $r['check_in'],
            'check_out'      => $r['check_out'],
            'guest'          => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'unit'           => $r['unit_number'],
            'building'       => $r['building_name'],
        ];
    }, $rows);
    jarvis_api_send(['ok' => true, 'status' => $status ?: null, 'count' => count($deposits), 'capped_at' => JARVIS_ARS_LIMIT,
        'totals_by_status' => $totals, 'deposits' => $deposits]);
}

if ($action === 'activity') {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
    $q = trim((string)($_GET['q'] ?? ''));
    $where = 'a.company_id = ?';
    $params = [$cid];
    if ($q !== '') {
        $where .= ' AND a.booking_number = ?';
        $params[] = $q;
    }
    $rows = jarvis_ars_try(function () use ($conn, $where, $params, $limit) {
        $s = $conn->prepare("SELECT a.created_at, a.booking_number, a.event_category, a.event_type, a.title, a.description
            FROM ars_booking_activities a WHERE $where ORDER BY a.id DESC LIMIT $limit");
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }, []);
    jarvis_api_send(['ok' => true, 'booking_number' => $q ?: null, 'count' => count($rows), 'activity' => $rows]);
}

jarvis_api_error('not_found', 'Unknown ARS action.', 404);
