<?php
/**
 * Airbnb email sync tests.
 *
 *   php tests/airbnb_email_sync_test.php parser
 *       Parser only, against the real sample mails in docs/airbnb_samples/ (no database).
 *
 *   php tests/airbnb_email_sync_test.php flow <database>
 *       Full booking flow against a THROWAWAY copy of the database (name must start with test_).
 *       Creates bookings, journals and cancellations there.
 */

declare(strict_types=1);

$mode = $argv[1] ?? 'parser';
$samples = __DIR__ . '/../docs/airbnb_samples';
$failed = 0;
$passed = 0;

function check(bool $cond, string $label): void
{
    global $failed, $passed;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    $cond ? $passed++ : $failed++;
}

function sample(string $name): string
{
    global $samples;
    $f = $samples . '/' . $name;
    if (!is_file($f)) {
        fwrite(STDERR, "Missing sample {$f}\n");
        exit(2);
    }
    return file_get_contents($f);
}

if ($mode === 'parser') {
    require_once __DIR__ . '/../integrations/airbnb/airbnb_email_parser.php';

    $r = airbnb_parse_email(sample('confirmed_2176.eml'));
    check($r['type'] === 'confirmed', 'confirmation recognised');
    check($r['confirmation_code'] === 'HMD5XX4EFA', 'confirmation code');
    check($r['listing_id'] === '1131173518416298103', 'listing id');
    check($r['check_in'] === '2026-09-13' && $r['check_out'] === '2026-09-19', 'stay dates with inferred year');
    check($r['payout_amount'] === 904.15, 'payout = YOU EARN (904.15), not guest paid');
    check($r['guest_paid_amount'] === 1070.0, 'guest paid read separately');
    check($r['num_guests'] === 2, 'pets not counted as guests');
    check($r['guest_first_name'] === 'Ronald John' && $r['guest_last_name'] === 'Montero', 'guest name split');
    check($r['errors'] === [], 'no parse errors');

    $r = airbnb_parse_email(sample('confirmed_180.eml'));
    check($r['check_in'] === '2025-05-01' && $r['payout_amount'] === 339.5, '2025 template (old fee layout)');

    $r = airbnb_parse_email(sample('confirmed_1106.eml'));
    check($r['num_guests'] === 2, 'adult + child counted');

    $r = airbnb_parse_email(sample('cancel_host_404.eml'));
    check($r['type'] === 'guest_cancel' && $r['confirmation_code'] === 'HM5J3PKEHT', 'guest cancel code');
    check($r['check_in'] === '2025-12-22' && $r['check_out'] === '2026-01-03', 'cancel range across new year');

    $r = airbnb_parse_email(sample('cancel_guest_2021.eml'));
    check($r['type'] === 'host_cancel' && $r['check_in'] === '2026-08-30' && $r['check_out'] === '2026-09-29', 'host cancel dates');
    check($r['confirmation_code'] === null, 'host cancel has no code (so matching is by dates)');

    $r = airbnb_parse_email(sample('updated_1700.eml'));
    check($r['type'] === 'updated' && $r['confirmation_code'] === 'HMTYBBXNQK', 'reservation updated code');

    $r = airbnb_parse_email(sample('change_request_1698.eml'));
    check($r['type'] === 'change_request' && $r['requested_check_out'] === '2026-08-24', 'change request dates');
    check($r['listing_title'] === 'New Studio Apartment in JVC', 'change request listing title without nickname');

    foreach (['payout_1495.eml', 'pending_request_2171.eml', 'dismissed_2159.eml', 'change_declined_1443.eml'] as $f) {
        check(airbnb_parse_email(sample($f))['type'] === 'ignored', "ignored: {$f}");
    }

    // Subject classifier must agree with the full parser on every sample.
    foreach (glob($samples . '/*.eml') as $f) {
        $raw = file_get_contents($f);
        $full = airbnb_parse_email($raw);
        check(airbnb_subject_type($full['subject']) === $full['type'], 'subject classifier agrees: ' . basename($f));
    }

    $broken = str_replace('YOU EARN', 'YOU MAKE', sample('confirmed_2176.eml'));
    check(airbnb_parse_email($broken)['errors'] !== [], 'layout change is reported, not guessed');
} elseif ($mode === 'flow') {
    $db = $argv[2] ?? '';
    if (strpos($db, 'test_') !== 0) {
        fwrite(STDERR, "Refusing to run: flow tests need a throwaway database named test_*\n");
        exit(2);
    }
    define('DB_NAME', $db);
    @require_once __DIR__ . '/../includes/config.php'; // its own DB_NAME define is ignored
    require_once __DIR__ . '/../includes/db_connect.php';
    require_once __DIR__ . '/../modules/ars/includes/ars_airbnb_sync.php';
    check($conn->query('SELECT DATABASE()')->fetchColumn() === $db, 'connected to throwaway database');

    $companyId = getArsCompanyId($conn);
    $conn->exec("DELETE FROM ars_channel_emails; DELETE FROM ars_channel_listings;");

    $uid = 900000;
    $store = static function (string $raw) use ($conn, $companyId, &$uid): array {
        $p = airbnb_parse_email($raw);
        $uid++;
        ars_airbnb_store_email($conn, $companyId, 'test', 1, $uid, $p, $raw);
        $st = $conn->prepare('SELECT * FROM ars_channel_emails WHERE company_id = ? AND imap_uid = ?');
        $st->execute([$companyId, $uid]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $reload = static function (int $id) use ($conn): array {
        $st = $conn->prepare('SELECT * FROM ars_channel_emails WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $booking = static function (string $code) use ($conn): ?array {
        $st = $conn->prepare("SELECT * FROM ars_bookings WHERE booking_source = 'airbnb' AND channel_ref = ?");
        $st->execute([$code]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    // Pick a short-term unit that is free for the test stay.
    $unitId = (int)$conn->query("
        SELECT u.id FROM re_units u
         WHERE u.rental_mode IN ('short_term','both')
           AND NOT EXISTS (SELECT 1 FROM ars_bookings b WHERE b.unit_id = u.id AND b.status NOT IN ('cancelled','expired') AND b.check_in < '2026-09-19' AND b.check_out > '2026-09-13')
           AND NOT EXISTS (SELECT 1 FROM ars_blocked_dates d WHERE d.unit_id = u.id AND d.start_date < '2026-09-19' AND d.end_date > '2026-09-13')
         ORDER BY u.id LIMIT 1")->fetchColumn();
    check($unitId > 0, 'found a free unit for the test stay');

    // 1. New listing, not linked → review
    $e = $store(sample('confirmed_2176.eml'));
    $res = ars_airbnb_process_email($conn, $companyId, $e, null);
    check($res['status'] === 'needs_review' && strpos($res['note'], 'not linked') !== false, 'unlinked listing goes to review');
    check($booking('HMD5XX4EFA') === null, 'no booking created without a unit');

    // 2. Link listing, retry → confirmed booking at payout, no VAT, revenue posted, locked
    $conn->prepare('UPDATE ars_channel_listings SET unit_id = ? WHERE listing_id = ?')->execute([$unitId, '1131173518416298103']);
    $res = ars_airbnb_process_email($conn, $companyId, $reload((int)$e['id']), null);
    $b = $booking('HMD5XX4EFA');
    check($res['status'] === 'processed' && $b !== null, 'booking created after linking the listing');
    check($b && $b['status'] === 'confirmed', 'status confirmed');
    check($b && (float)$b['total_amount'] === 904.15 && (float)$b['balance_due'] === 904.15, 'total = Airbnb payout 904.15');
    check($b && (float)$b['vat_amount'] === 0.0 && $b['vat_mode'] === 'none', 'no VAT');
    check($b && $b['check_in'] === '2026-09-13' && $b['check_out'] === '2026-09-19' && (int)$b['nights'] === 6, 'dates and nights');
    check($b && (int)$b['unit_id'] === $unitId, 'booked on the linked unit');
    check($b && !empty($b['journal_id']), 'revenue journal posted');
    check($b && (int)$b['is_financially_locked'] === 1, 'financial lock engaged like a staff confirm');
    if ($b) {
        $j = $conn->prepare('SELECT SUM(debit_amount) d, SUM(credit_amount) c FROM re_journal_lines WHERE journal_id = ?');
        $j->execute([(int)$b['journal_id']]);
        $sum = $j->fetch(PDO::FETCH_ASSOC);
        check(abs((float)$sum['d'] - 904.15) < 0.001 && abs((float)$sum['c'] - 904.15) < 0.001, 'journal balances at 904.15');
        $g = $conn->prepare('SELECT first_name, last_name FROM ars_guests WHERE id = ?');
        $g->execute([(int)$b['guest_id']]);
        $guest = $g->fetch(PDO::FETCH_ASSOC);
        check($guest['first_name'] === 'Ronald John' && $guest['last_name'] === 'Montero', 'guest created with Airbnb name');
    }

    // 3. Same email again → no duplicate
    $before = (int)$conn->query("SELECT COUNT(*) FROM ars_bookings WHERE channel_ref = 'HMD5XX4EFA'")->fetchColumn();
    $res = ars_airbnb_process_email($conn, $companyId, $reload((int)$e['id']), null);
    $after = (int)$conn->query("SELECT COUNT(*) FROM ars_bookings WHERE channel_ref = 'HMD5XX4EFA'")->fetchColumn();
    check($before === 1 && $after === 1 && strpos($res['note'], 'Already in ARS') !== false, 'reprocessing does not duplicate');

    // 4. Another Airbnb booking for overlapping dates on the same unit → review, not double-booked
    $overlap = str_replace(['HMD5XX4EFA', 'Ronald John Montero'], ['HMTESTOVR1', 'Test Overlap'], sample('confirmed_2176.eml'));
    $overlap = preg_replace('/^Message-ID:.*$/mi', 'Message-ID: <overlap-test@airbnb.com>', $overlap);
    $e2 = $store($overlap);
    $res = ars_airbnb_process_email($conn, $companyId, $e2, null);
    check($res['status'] === 'needs_review' && strpos($res['note'], 'not free') !== false, 'overlap goes to review');
    check($booking('HMTESTOVR1') === null, 'overlap not booked');

    // 5. Guest cancels on Airbnb → booking cancelled, journal reversed
    $cancel = str_replace(['HMPCH4Q99B', 'Sep 18 – 26', 'Sep 18 =E2=80=93 26', 'September 18 – 26'], ['HMD5XX4EFA', 'Sep 13 – 19', 'Sep 13 =E2=80=93 19', 'September 13 – 19'], sample('cancel_host_2090.eml'));
    $cancel = preg_replace('/^Message-ID:.*$/mi', 'Message-ID: <cancel-test@airbnb.com>', $cancel);
    $e3 = $store($cancel);
    check($e3['email_type'] === 'guest_cancel' && $e3['confirmation_code'] === 'HMD5XX4EFA', 'test cancel email parsed');
    $res = ars_airbnb_process_email($conn, $companyId, $e3, null);
    $b = $booking('HMD5XX4EFA');
    check($res['status'] === 'processed' && $b && $b['status'] === 'cancelled', 'guest cancel cancels the booking');
    if ($b) {
        $rv = $conn->prepare('SELECT is_reversed FROM re_journal_headers WHERE id = ?');
        $rv->execute([(int)$b['journal_id']]);
        check((int)$rv->fetchColumn() === 1, 'revenue journal reversed');
    }

    // 6. Overlap email retried after the cancel → now books
    $res = ars_airbnb_process_email($conn, $companyId, $reload((int)$e2['id']), null);
    check($res['status'] === 'processed' && $booking('HMTESTOVR1') !== null, 'retry books once dates are free');

    // 7. Host cancel matched by dates (exactly one Airbnb booking) → cancelled
    $hostCancel = str_replace(['Aug 30 – Sep 29', 'Aug =?UTF-8?B?MzDigInigJPigIlTZXA=?= 29, 2026'], ['Sep 13 – 19', 'Sep 13 - 19, 2026'], sample('cancel_guest_2021.eml'));
    $hostCancel = preg_replace('/^Message-ID:.*$/mi', 'Message-ID: <host-cancel-test@airbnb.com>', $hostCancel);
    $e4 = $store($hostCancel);
    check($e4['email_type'] === 'host_cancel' && $e4['check_in'] === '2026-09-13', 'test host-cancel parsed');
    $res = ars_airbnb_process_email($conn, $companyId, $e4, null);
    check($res['status'] === 'processed' && $booking('HMTESTOVR1')['status'] === 'cancelled', 'host cancel matched by dates');

    // 8. Staff already entered it by hand → conflict → link existing, money untouched
    $hand = $conn->query("SELECT * FROM ars_bookings WHERE booking_number = 'ARS-26-00135'")->fetch(PDO::FETCH_ASSOC);
    if ($hand) {
        $conn->prepare("INSERT INTO ars_channel_listings (company_id, channel, listing_id, unit_id) VALUES (?, 'airbnb', '1246874074957418365', ?)")
             ->execute([$companyId, (int)$hand['unit_id']]);
        $e5 = $store(sample('confirmed_1372.eml'));
        $res = ars_airbnb_process_email($conn, $companyId, $e5, null);
        check($res['status'] === 'needs_review' && strpos($res['note'], 'ARS-26-00135') !== false, 'hand-entered booking detected as conflict');
        ars_airbnb_link_existing($conn, $companyId, $reload((int)$e5['id']), 'ARS-26-00135', 1);
        $linked = $conn->query("SELECT * FROM ars_bookings WHERE booking_number = 'ARS-26-00135'")->fetch(PDO::FETCH_ASSOC);
        check($linked['channel_ref'] === 'HMWJKKSH9J' && $linked['total_amount'] === $hand['total_amount'], 'linked without changing money');
        check($reload((int)$e5['id'])['status'] === 'resolved', 'review item resolved');

        // 9. Airbnb says the reservation changed → flagged on that booking, nothing altered
        $e6 = $store(sample('change_accepted_1461.eml'));
        $res = ars_airbnb_process_email($conn, $companyId, $e6, null);
        $still = $conn->query("SELECT check_in, check_out, total_amount FROM ars_bookings WHERE booking_number = 'ARS-26-00135'")->fetch(PDO::FETCH_ASSOC);
        check($res['status'] === 'needs_review' && (int)$res['booking_id'] === (int)$hand['id'], 'change flagged against the booking');
        check($still['check_out'] === $hand['check_out'] && $still['total_amount'] === $hand['total_amount'], 'change did not alter the booking');
    } else {
        echo "SKIP: ARS-26-00135 not in this database\n";
    }

    // 10. Airbnb changes their layout → review, no booking
    $broken = str_replace(['YOU EARN', 'HMD5XX4EFA'], ['YOU MAKE', 'HMTESTBRK1'], sample('confirmed_2176.eml'));
    $broken = preg_replace('/^Message-ID:.*$/mi', 'Message-ID: <broken-test@airbnb.com>', $broken);
    $e7 = $store($broken);
    $res = ars_airbnb_process_email($conn, $companyId, $e7, null);
    check($res['status'] === 'needs_review' && $booking('HMTESTBRK1') === null, 'unreadable email is not guessed');
} else {
    fwrite(STDERR, "Usage: php tests/airbnb_email_sync_test.php parser | flow test_<db>\n");
    exit(2);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
