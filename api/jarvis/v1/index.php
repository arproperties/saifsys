<?php
/**
 * Jarvis API v1 — the door the Jarvis assistant (ainalreemproaiagent.fun) uses to read
 * saifsys.
 *
 * Read-only on purpose: Jarvis asks, saifsys answers, and nothing here changes a row.
 * Every request must carry the shared secret as `X-Jarvis-Key`. The secret lives in
 * includes/config.php as JARVIS_API_KEY and the same value sits in Jarvis's .env as
 * SAIFSYS_API_KEY. Empty or missing = the whole API answers 503, so uploading this
 * file without configuring it exposes nothing.
 *
 * One route per `action`. More things Jarvis needs to know go in as more actions here:
 *
 *   GET ?action=health                   -> { ok: true }
 *   GET ?action=checkouts&date=YYYY-MM-DD -> the stays that check out that day
 *                                           (date defaults to today, Dubai time)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jarvis_api_send(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jarvis_api_error(string $code, string $message, int $status): void
{
    jarvis_api_send(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
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

$secret = defined('JARVIS_API_KEY') ? (string)JARVIS_API_KEY : '';
if ($secret === '') {
    jarvis_api_error('not_configured', 'JARVIS_API_KEY is not set on this server.', 503);
}
$given = (string)($_SERVER['HTTP_X_JARVIS_KEY'] ?? '');
if ($given === '' || !hash_equals($secret, $given)) {
    jarvis_api_error('unauthorized', 'Not authorised.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jarvis_api_error('method_not_allowed', 'Read-only: GET only.', 405);
}

$action = (string)($_GET['action'] ?? '');

if ($action === 'health') {
    jarvis_api_send(['ok' => true, 'version' => 'v1']);
}

if ($action === 'checkouts') {
    // Dubai time, not the server's clock: "today" must be the day the guests are leaving.
    $today = (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
    $date = (string)($_GET['date'] ?? $today);
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        jarvis_api_error('bad_date', 'date must be YYYY-MM-DD.', 400);
    }

    // The same rule as the ARS dashboard's "Departures today" (modules/ars/index.php),
    // across every company, since Jarvis is not signed in to one.
    //
    // The balance is the one the booking page shows — see jarvis_api_balance_due().
    $stmt = $conn->prepare("
        SELECT b.id, b.company_id, b.booking_number, b.status, b.check_in, b.check_out,
               b.num_guests, b.total_amount, b.balance_due, b.payment_status,
               fd.invoice_count, fd.open_balance, fd.net_invoiced,
               g.first_name, g.last_name, g.phone,
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
        WHERE b.check_out = ? AND b.status IN ('checked_in','checked_out','confirmed')
        ORDER BY bd.name, u.unit_number, b.booking_number
        LIMIT 500
    ");
    $stmt->execute([$date]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Every payment row of these bookings, in the page's order.
    $paymentsByBooking = [];
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $pm = $conn->prepare('SELECT booking_id, amount, total_amount FROM ars_booking_payments
            WHERE booking_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY payment_date, id');
        $pm->execute($ids);
        foreach ($pm->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $paymentsByBooking[(int)$p['booking_id']][] = $p;
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'booking_id'     => (int)$r['id'],
            'booking_number' => $r['booking_number'],
            'company_id'     => (int)$r['company_id'],
            'status'         => $r['status'],
            'check_in'       => $r['check_in'],
            'check_out'      => $r['check_out'],
            'unit'           => $r['unit_number'],
            'building'       => $r['building_name'],
            'guest'          => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'guest_phone'    => $r['phone'],
            'guests'         => (int)$r['num_guests'],
            'balance_due'    => jarvis_api_balance_due($r, $paymentsByBooking[(int)$r['id']] ?? []),
            'payment_status' => $r['payment_status'],
        ];
    }
    jarvis_api_send(['ok' => true, 'date' => $date, 'count' => count($out), 'checkouts' => $out]);
}

jarvis_api_error('not_found', 'Unknown action. Try action=checkouts.', 404);
