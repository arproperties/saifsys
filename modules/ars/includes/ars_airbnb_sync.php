<?php
/**
 * ARS ← Airbnb email sync.
 *
 * Reads Airbnb notification mails from the ARS Gmail over IMAP (read-only) and:
 *   - "Reservation confirmed"  → creates a confirmed ARS booking at the Airbnb payout, no VAT,
 *                                posts revenue and engages the financial lock (same as staff confirm)
 *   - "Canceled: Reservation"  → cancels that booking by confirmation code (same as staff cancel)
 *   - "Reservation canceled"   → host cancelled on Airbnb; the mail has dates only, so it cancels
 *                                only when exactly one Airbnb booking has those dates
 *   - "Reservation updated"    → Airbnb doesn't say the new dates or payout, so the booking is
 *                                flagged for staff instead of being changed
 *
 * Anything it can't do safely lands in ars_channel_emails with status needs_review.
 * Credentials: AIRBNB_IMAP_USER / AIRBNB_IMAP_PASS in includes/config.php.
 * Schema: migrations/ars_airbnb_email_sync.sql
 */

declare(strict_types=1);

// Live PHP runs on UTC; Airbnb email dates are read as Asia/Dubai. Without this the go-live
// cutoff and every stamp written here would be 4 hours out on live (same fix as ops/fleet).
date_default_timezone_set('Asia/Dubai');

require_once __DIR__ . '/ars_helpers.php';
require_once __DIR__ . '/ars_pricing.php';
require_once __DIR__ . '/ars_permissions.php';
require_once __DIR__ . '/ars_availability.php';
require_once __DIR__ . '/ars_financial_lock.php';
require_once __DIR__ . '/ars_activity.php';
require_once __DIR__ . '/ars_accounting.php';
require_once dirname(__DIR__, 3) . '/integrations/airbnb/imap_client.php';
require_once dirname(__DIR__, 3) . '/integrations/airbnb/airbnb_email_parser.php';

const ARS_AIRBNB_CHANNEL = 'airbnb';
const ARS_AIRBNB_SENDER = 'automated@airbnb.com';
const ARS_AIRBNB_MAX_PER_RUN = 300;

function ars_airbnb_now(): string {
    return date('Y-m-d H:i:s');
}

/** @return array{user:string, pass:string, host:string, mailbox:string, configured:bool} */
function ars_airbnb_config(): array {
    $user = defined('AIRBNB_IMAP_USER') ? (string)AIRBNB_IMAP_USER : '';
    $pass = defined('AIRBNB_IMAP_PASS') ? str_replace(' ', '', (string)AIRBNB_IMAP_PASS) : '';
    return [
        'user' => $user,
        'pass' => $pass,
        'host' => defined('AIRBNB_IMAP_HOST') ? (string)AIRBNB_IMAP_HOST : 'imap.gmail.com',
        'mailbox' => defined('AIRBNB_IMAP_MAILBOX') ? (string)AIRBNB_IMAP_MAILBOX : '[Gmail]/All Mail',
        'configured' => $user !== '' && $pass !== '',
    ];
}

function ars_airbnb_tables_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $conn->query('SELECT 1 FROM ars_channel_emails LIMIT 1');
        $conn->query('SELECT 1 FROM ars_channel_listings LIMIT 1');
        $conn->query('SELECT 1 FROM ars_channel_sync_state LIMIT 1');
        $conn->query('SELECT booking_source, channel_ref FROM ars_bookings LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function ars_airbnb_state(PDO $conn, int $companyId, string $mailbox): array {
    $st = $conn->prepare('SELECT * FROM ars_channel_sync_state WHERE company_id = ? AND channel = ? AND mailbox = ? LIMIT 1');
    $st->execute([$companyId, ARS_AIRBNB_CHANNEL, $mailbox]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    // First run fixes the go-live moment. Only bookings made after it are imported — existing
    // bookings stay with staff. There is deliberately no way to move it back.
    $conn->prepare('INSERT INTO ars_channel_sync_state (company_id, channel, mailbox, start_at) VALUES (?, ?, ?, ?)')
         ->execute([$companyId, ARS_AIRBNB_CHANNEL, $mailbox, ars_airbnb_now()]);
    $st->execute([$companyId, ARS_AIRBNB_CHANNEL, $mailbox]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Pull new Airbnb mails and process everything pending.
 * @return array{ok:bool, error:?string, fetched:int, created:int, cancelled:int, review:int, skipped_locked?:bool}
 */
function ars_airbnb_run_sync(PDO $conn, int $companyId): array {
    $summary = ['ok' => false, 'error' => null, 'fetched' => 0, 'created' => 0, 'cancelled' => 0, 'review' => 0];
    if (!ars_airbnb_tables_ready($conn)) {
        $summary['error'] = 'Airbnb sync tables are missing — run migrations/ars_airbnb_email_sync.sql';
        return $summary;
    }
    $cfg = ars_airbnb_config();
    if (!$cfg['configured']) {
        $summary['error'] = 'Gmail login is not set — add AIRBNB_IMAP_USER and AIRBNB_IMAP_PASS to includes/config.php';
        return $summary;
    }

    // One run at a time, whether it came from cron or the "Sync now" button.
    $lockName = 'ars_airbnb_sync_' . $companyId;
    if ((int)$conn->query('SELECT GET_LOCK(' . $conn->quote($lockName) . ', 0)')->fetchColumn() !== 1) {
        $summary['ok'] = true;
        $summary['skipped_locked'] = true;
        return $summary;
    }

    $state = ars_airbnb_state($conn, $companyId, $cfg['mailbox']);
    $conn->prepare('UPDATE ars_channel_sync_state SET last_run_at = ? WHERE id = ?')->execute([ars_airbnb_now(), $state['id']]);

    try {
        $summary['fetched'] = ars_airbnb_fetch_new($conn, $companyId, $cfg, $state);

        $pending = $conn->prepare("SELECT * FROM ars_channel_emails WHERE company_id = ? AND status = 'new' ORDER BY sent_at, id");
        $pending->execute([$companyId]);
        foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $res = ars_airbnb_process_email($conn, $companyId, $row, null);
            if ($res['status'] === 'processed' && ($res['action'] ?? '') === 'created') $summary['created']++;
            if ($res['status'] === 'processed' && ($res['action'] ?? '') === 'cancelled') $summary['cancelled']++;
            if ($res['status'] === 'needs_review') $summary['review']++;
        }

        $conn->prepare('UPDATE ars_channel_sync_state SET last_success_at = ?, last_error = NULL WHERE id = ?')
             ->execute([ars_airbnb_now(), $state['id']]);
        $summary['ok'] = true;
    } catch (Throwable $e) {
        $summary['error'] = $e->getMessage();
        $conn->prepare('UPDATE ars_channel_sync_state SET last_error = ? WHERE id = ?')
             ->execute([mb_substr($e->getMessage(), 0, 1000), $state['id']]);
        error_log('[ARS Airbnb sync] ' . $e->getMessage());
    } finally {
        $conn->query('SELECT RELEASE_LOCK(' . $conn->quote($lockName) . ')');
    }
    return $summary;
}

/** Download booking-related mails newer than the cursor into ars_channel_emails. */
function ars_airbnb_fetch_new(PDO $conn, int $companyId, array $cfg, array $state): int {
    $imap = new AirbnbImapClient();
    $imap->connect($cfg['host']);
    $stored = 0;
    try {
        $imap->login($cfg['user'], $cfg['pass']);
        $uidValidity = $imap->examine($cfg['mailbox']);
        $lastUid = (int)$state['last_uid'];
        if ((int)$state['uidvalidity'] !== $uidValidity) {
            // Mailbox was rebuilt; UIDs restart. Message-ID dedupe below prevents re-imports.
            $lastUid = 0;
            $conn->prepare('UPDATE ars_channel_sync_state SET uidvalidity = ?, last_uid = 0 WHERE id = ?')
                 ->execute([$uidValidity, $state['id']]);
        }

        $startAt = (string)($state['start_at'] ?: ars_airbnb_now());
        // IMAP SINCE is date-only (and in the server's zone), so ask from the day before and filter exactly below.
        $since = date('j-M-Y', strtotime($startAt) - 86400);
        $criteria = 'UID ' . ($lastUid + 1) . ':* SINCE ' . $since . ' FROM ' . $imap->quote(ARS_AIRBNB_SENDER);
        $uids = array_values(array_filter($imap->uidSearch($criteria), static fn($u) => $u > $lastUid));
        $uids = array_slice($uids, 0, ARS_AIRBNB_MAX_PER_RUN);
        if (!$uids) return 0;

        $headers = $imap->uidFetchHeaders($uids);
        $advance = $conn->prepare('UPDATE ars_channel_sync_state SET last_uid = ? WHERE id = ? AND last_uid < ?');
        $dupe = $conn->prepare('SELECT id FROM ars_channel_emails WHERE company_id = ? AND message_id = ? LIMIT 1');

        foreach ($uids as $uid) {
            $type = airbnb_subject_type(airbnb_header_subject($headers[$uid] ?? ''));
            if ($type !== 'ignored') {
                $raw = $imap->uidFetchRaw($uid);
                if ($raw === null) {
                    throw new RuntimeException("Could not download message UID {$uid}");
                }
                $p = airbnb_parse_email($raw);
                $tooOld = (string)$p['sent_at'] < $startAt;
                $dupe->execute([$companyId, $p['message_id']]);
                if (!$tooOld && !($p['message_id'] !== '' && $dupe->fetchColumn())) {
                    ars_airbnb_store_email($conn, $companyId, $cfg['mailbox'], $uidValidity, $uid, $p, $raw);
                    $stored++;
                }
            }
            $advance->execute([$uid, $state['id'], $uid]);
        }
        if ($stored > 0) {
            $conn->prepare('UPDATE ars_channel_sync_state SET last_booking_email_at = ? WHERE id = ?')
                 ->execute([ars_airbnb_now(), $state['id']]);
        }
    } finally {
        $imap->logout();
    }
    return $stored;
}

function ars_airbnb_store_email(PDO $conn, int $companyId, string $mailbox, int $uidValidity, int $uid, array $p, string $raw): void {
    $status = $p['type'] === 'change_request' ? 'info' : 'new';
    $conn->prepare("
        INSERT IGNORE INTO ars_channel_emails
            (company_id, channel, mailbox, uidvalidity, imap_uid, message_id, sent_at, subject, email_type,
             confirmation_code, listing_id, listing_title, guest_name, check_in, check_out,
             requested_check_in, requested_check_out, num_guests, payout_amount, parsed_json, raw_eml,
             status, result_note, created_at)
        VALUES (?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?)
    ")->execute([
        $companyId, ARS_AIRBNB_CHANNEL, $mailbox, $uidValidity, $uid, $p['message_id'] ?: null, $p['sent_at'],
        mb_substr((string)$p['subject'], 0, 500), $p['type'],
        $p['confirmation_code'], $p['listing_id'], $p['listing_title'] ? mb_substr($p['listing_title'], 0, 255) : null,
        $p['guest_name'] ? mb_substr($p['guest_name'], 0, 200) : null, $p['check_in'], $p['check_out'],
        $p['requested_check_in'], $p['requested_check_out'], $p['num_guests'], $p['payout_amount'],
        json_encode($p, JSON_UNESCAPED_UNICODE), $raw,
        $status, $status === 'info' ? 'Change request — kept so staff can see the requested dates if Airbnb later confirms the change.' : null,
        ars_airbnb_now(),
    ]);
}

/** Record the outcome on the email row. */
function ars_airbnb_mark(PDO $conn, int $emailId, string $status, string $note, ?int $bookingId = null, ?int $userId = null): array {
    $resolved = in_array($status, ['processed', 'resolved', 'info'], true) && $userId !== null;
    $conn->prepare('
        UPDATE ars_channel_emails
           SET status = ?, result_note = ?, booking_id = COALESCE(?, booking_id), attempts = attempts + 1, processed_at = ?,
               resolved_by = ' . ($resolved ? '?' : 'resolved_by') . ', resolved_at = ' . ($resolved ? '?' : 'resolved_at') . '
         WHERE id = ?
    ')->execute(array_merge(
        [$status, $note, $bookingId, ars_airbnb_now()],
        $resolved ? [$userId, ars_airbnb_now()] : [],
        [$emailId]
    ));
    return ['status' => $status, 'note' => $note, 'booking_id' => $bookingId];
}

/**
 * @param int|null $userId staff member when retried/forced from the review screen; null from cron
 * @param array{unit_id?:int} $opts
 * @return array{status:string, note:string, booking_id:?int, action?:string}
 */
function ars_airbnb_process_email(PDO $conn, int $companyId, array $email, ?int $userId, array $opts = []): array {
    $id = (int)$email['id'];
    try {
        switch ($email['email_type']) {
            case 'confirmed':
                return ars_airbnb_handle_confirmed($conn, $companyId, $email, $userId, $opts);
            case 'guest_cancel':
                return ars_airbnb_handle_guest_cancel($conn, $companyId, $email, $userId);
            case 'host_cancel':
                return ars_airbnb_handle_host_cancel($conn, $companyId, $email, $userId);
            case 'updated':
                return ars_airbnb_handle_updated($conn, $companyId, $email);
            default:
                return ars_airbnb_mark($conn, $id, 'info', 'No action needed for this email.');
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('[ARS Airbnb sync] email ' . $id . ': ' . $e->getMessage());
        return ars_airbnb_mark($conn, $id, 'needs_review', 'Error: ' . $e->getMessage());
    }
}

function ars_airbnb_find_booking_by_code(PDO $conn, int $companyId, ?string $code): ?array {
    if (!$code) return null;
    $st = $conn->prepare('SELECT * FROM ars_bookings WHERE company_id = ? AND booking_source = ? AND channel_ref = ? LIMIT 1');
    $st->execute([$companyId, ARS_AIRBNB_CHANNEL, $code]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ars_airbnb_touch_listing(PDO $conn, int $companyId, ?string $listingId, ?string $title): ?array {
    if (!$listingId) return null;
    $now = ars_airbnb_now();
    $conn->prepare('
        INSERT INTO ars_channel_listings (company_id, channel, listing_id, listing_title, first_seen_at, last_seen_at)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE listing_title = COALESCE(VALUES(listing_title), listing_title), last_seen_at = VALUES(last_seen_at)
    ')->execute([$companyId, ARS_AIRBNB_CHANNEL, $listingId, $title, $now, $now]);
    $st = $conn->prepare('SELECT * FROM ars_channel_listings WHERE company_id = ? AND channel = ? AND listing_id = ? LIMIT 1');
    $st->execute([$companyId, ARS_AIRBNB_CHANNEL, $listingId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ars_airbnb_unit_label(PDO $conn, int $unitId): string {
    $st = $conn->prepare('SELECT u.unit_number, b.name FROM re_units u LEFT JOIN re_buildings b ON b.id = u.building_id WHERE u.id = ?');
    $st->execute([$unitId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ? trim('Unit ' . $u['unit_number'] . ($u['name'] ? ' (' . $u['name'] . ')' : '')) : ('Unit #' . $unitId);
}

function ars_airbnb_handle_confirmed(PDO $conn, int $companyId, array $email, ?int $userId, array $opts): array {
    $id = (int)$email['id'];
    $p = json_decode((string)$email['parsed_json'], true) ?: [];

    if (!empty($p['errors'])) {
        return ars_airbnb_mark($conn, $id, 'needs_review', 'Could not read this email fully (' . implode('; ', $p['errors']) . '). Airbnb may have changed their email layout — enter this booking by hand and dismiss.');
    }

    $code = (string)$email['confirmation_code'];
    if ($existing = ars_airbnb_find_booking_by_code($conn, $companyId, $code)) {
        return ars_airbnb_mark($conn, $id, 'processed', 'Already in ARS as ' . $existing['booking_number'] . '.', (int)$existing['id'], $userId);
    }

    $listing = ars_airbnb_touch_listing($conn, $companyId, $email['listing_id'], $email['listing_title']);
    $unitId = (int)($opts['unit_id'] ?? 0) ?: (int)($listing['unit_id'] ?? 0);
    if ($unitId <= 0) {
        return ars_airbnb_mark($conn, $id, 'needs_review', 'Airbnb listing “' . ($email['listing_title'] ?: $email['listing_id']) . '” is not linked to an ARS unit yet. Pick a unit to create the booking.');
    }
    ars_assert_unit_usable_for_ars($conn, $unitId, $companyId);

    $checkIn = (string)$email['check_in'];
    $checkOut = (string)$email['check_out'];
    $nights = (int)(new DateTime($checkOut))->diff(new DateTime($checkIn))->days;
    $payout = round((float)$email['payout_amount'], 2);
    $settings = getArsSettings($conn, $companyId);

    $conn->beginTransaction();
    $conn->prepare('SELECT id FROM re_units WHERE id = ? FOR UPDATE')->execute([$unitId]);

    $avail = ars_check_availability($conn, $unitId, $checkIn, $checkOut);
    if (empty($avail['available'])) {
        $conn->rollBack();
        $labels = array_map(static fn($c) => $c['label'] ?? 'conflict', $avail['conflicts'] ?? []);
        return ars_airbnb_mark($conn, $id, 'needs_review',
            ars_airbnb_unit_label($conn, $unitId) . ' is not free for ' . $checkIn . ' → ' . $checkOut . ': ' . implode(', ', $labels)
            . '. If staff already entered this Airbnb booking by hand, link it. Otherwise pick another unit.');
    }

    $ur = $conn->prepare('SELECT nightly_rate, COALESCE(monthly_rate, 0) AS monthly_rate FROM re_units WHERE id = ?');
    $ur->execute([$unitId]);
    $rates = $ur->fetch(PDO::FETCH_ASSOC) ?: ['nightly_rate' => 0, 'monthly_rate' => 0];

    $pricing = ars_calculate_booking_price_v3(
        $conn, $companyId, $unitId,
        (float)$rates['nightly_rate'], (float)$rates['monthly_rate'],
        $nights, $checkIn, $checkOut, null, [], (float)$settings['default_vat_rate'], [],
        ['pricing_mode' => 'manual_total', 'vat_mode' => 'none', 'entered_amount' => $payout, 'skip_length_discount' => true]
    );
    if (!empty($pricing['calc_error'])) {
        $conn->rollBack();
        return ars_airbnb_mark($conn, $id, 'needs_review', 'Pricing failed: ' . $pricing['calc_error']);
    }

    $first = trim((string)($p['guest_first_name'] ?? '')) ?: 'Airbnb';
    $last = trim((string)($p['guest_last_name'] ?? '')) ?: 'Guest';
    $conn->prepare('INSERT INTO ars_guests (company_id, first_name, last_name, notes) VALUES (?, ?, ?, ?)')
         ->execute([$companyId, mb_substr($first, 0, 100), mb_substr($last, 0, 100), 'Airbnb guest — reservation ' . $code]);
    $guestId = (int)$conn->lastInsertId();

    $bookingNumber = generateBookingNumber($conn, $companyId);
    $notes = 'Imported from Airbnb email. Confirmation code ' . $code
        . '. Airbnb payout AED ' . number_format($payout, 2)
        . (!empty($p['guest_paid_amount']) ? ' (guest paid AED ' . number_format((float)$p['guest_paid_amount'], 2) . ')' : '')
        . '. Guests: ' . ($p['guests_text'] ?? '—') . '.';

    $conn->prepare("
        INSERT INTO ars_bookings
            (company_id, unit_id, guest_id, booking_number, booking_source, channel_ref, check_in, check_out, nights, num_guests,
             status, nightly_rate, rate_override, subtotal, extras_total, vat_rate, vat_amount, net_amount,
             length_discount_amount, length_discount_label, pricing_rules_applied,
             pricing_mode, vat_mode, entered_amount,
             discount_type, discount_label, discount_percent, discount_amount, promo_code_id,
             total_amount, paid_amount, balance_due, payment_status, expires_at,
             deposit_amount, deposit_status, is_historical,
             special_requests, internal_notes, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?, 'confirmed',?,NULL,?,0.00,?,?,?, 0,NULL,NULL, 'manual_total','none',?, 'none',NULL,0,0,NULL, ?,0.00,?,'unpaid',NULL, 0,'none',0, NULL,?,?)
    ")->execute([
        $companyId, $unitId, $guestId, $bookingNumber, ARS_AIRBNB_CHANNEL, $code, $checkIn, $checkOut, $nights, max(1, (int)$email['num_guests']),
        $pricing['effective_rate'], $pricing['subtotal'], $pricing['vat_rate'], $pricing['vat_amount'], $pricing['net_amount'],
        $pricing['entered_amount'],
        $pricing['total_amount'], $pricing['total_amount'],
        $notes, $userId,
    ]);
    $bookingId = (int)$conn->lastInsertId();

    $bst = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? AND company_id = ?');
    $bst->execute([$bookingId, $companyId]);
    $booking = $bst->fetch(PDO::FETCH_ASSOC);

    $journal = ars_post_booking_revenue($conn, $booking, $userId);
    if (empty($journal['success'])) {
        $conn->rollBack();
        return ars_airbnb_mark($conn, $id, 'needs_review', 'Revenue posting failed, booking not created: ' . ($journal['error'] ?? 'unknown error'));
    }
    $bst->execute([$bookingId, $companyId]);
    $booking = $bst->fetch(PDO::FETCH_ASSOC) ?: $booking;
    ars_booking_engage_financial_lock($conn, $booking, 'Revenue journal posted on Airbnb import', $userId, 'invoice_created');
    $conn->commit();

    ars_booking_activity_log($conn, [
        'company_id' => $companyId,
        'booking_id' => $bookingId,
        'booking_number' => $bookingNumber,
        'event_category' => 'operational',
        'event_type' => 'booking_created',
        'title' => 'Booking created from Airbnb',
        'description' => 'Airbnb confirmation ' . $code . ' — payout AED ' . number_format($payout, 2),
        'new_value' => 'confirmed',
        'related_journal_id' => $journal['journal_id'] ?? null,
        'source' => 'import',
        'created_by' => $userId,
        'dedupe_key' => 'airbnb_created:' . $code,
    ]);
    ars_airbnb_audit($companyId, $bookingId, $bookingNumber, 'booking_created', 'Created booking ' . $bookingNumber . ' from Airbnb ' . $code, $userId);

    $r = ars_airbnb_mark($conn, $id, 'processed', 'Created ' . $bookingNumber . ' on ' . ars_airbnb_unit_label($conn, $unitId) . '.', $bookingId, $userId);
    $r['action'] = 'created';
    return $r;
}

/** Same steps as the staff Cancel button in ajax_booking_actions.php. */
function ars_airbnb_cancel_booking(PDO $conn, int $companyId, array $booking, string $reason, ?int $userId): void {
    $conn->beginTransaction();
    try {
        $lock = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? AND company_id = ? FOR UPDATE');
        $lock->execute([(int)$booking['id'], $companyId]);
        $booking = $lock->fetch(PDO::FETCH_ASSOC) ?: $booking;
        if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
            throw new RuntimeException('Booking ' . $booking['booking_number'] . ' is ' . $booking['status'] . ' and cannot be cancelled automatically.');
        }
        if ($booking['journal_id']) {
            $rev = ars_reverse_booking_journal((int)$booking['journal_id'], $userId);
            if (empty($rev['success'])) {
                throw new RuntimeException('Journal reversal failed: ' . ($rev['error'] ?? 'Unknown error'));
            }
        }
        $now = ars_airbnb_now();
        $conn->prepare("UPDATE ars_bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancellation_reason = ?, updated_at = ? WHERE id = ? AND company_id = ?")
             ->execute([$now, $userId, $reason, $now, (int)$booking['id'], $companyId]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    ars_booking_activity_log($conn, [
        'company_id' => $companyId,
        'booking_id' => (int)$booking['id'],
        'booking_number' => $booking['booking_number'],
        'event_category' => 'operational',
        'event_type' => 'booking_cancelled',
        'title' => 'Booking cancelled from Airbnb',
        'description' => $reason,
        'previous_value' => $booking['status'],
        'new_value' => 'cancelled',
        'source' => 'import',
        'created_by' => $userId,
    ]);
    ars_airbnb_audit($companyId, (int)$booking['id'], (string)$booking['booking_number'], 'booking_cancelled', 'Cancelled booking ' . $booking['booking_number'] . ' — ' . $reason, $userId);
}

function ars_airbnb_handle_guest_cancel(PDO $conn, int $companyId, array $email, ?int $userId): array {
    $id = (int)$email['id'];
    $code = (string)$email['confirmation_code'];
    $booking = ars_airbnb_find_booking_by_code($conn, $companyId, $code);

    if (!$booking) {
        // A reservation from before the sync went live — those stay with staff.
        return ars_airbnb_mark($conn, $id, 'info', 'Guest cancelled Airbnb ' . $code . ' — not a synced booking, nothing changed.');
    }
    if ($booking['status'] === 'cancelled') {
        return ars_airbnb_mark($conn, $id, 'processed', $booking['booking_number'] . ' was already cancelled.', (int)$booking['id'], $userId);
    }
    if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
        return ars_airbnb_mark($conn, $id, 'needs_review', 'Guest cancelled on Airbnb, but ' . $booking['booking_number'] . ' is already ' . $booking['status'] . '. Handle it by hand.', (int)$booking['id']);
    }
    ars_airbnb_cancel_booking($conn, $companyId, $booking, 'Guest cancelled on Airbnb (' . $code . ')', $userId);
    $r = ars_airbnb_mark($conn, $id, 'processed', 'Cancelled ' . $booking['booking_number'] . ' — guest cancelled on Airbnb.', (int)$booking['id'], $userId);
    $r['action'] = 'cancelled';
    return $r;
}

function ars_airbnb_handle_host_cancel(PDO $conn, int $companyId, array $email, ?int $userId): array {
    $id = (int)$email['id'];
    if (!$email['check_in'] || !$email['check_out']) {
        return ars_airbnb_mark($conn, $id, 'needs_review', 'You cancelled a reservation on Airbnb, but the dates could not be read. Cancel the ARS booking by hand.');
    }
    $st = $conn->prepare("SELECT * FROM ars_bookings WHERE company_id = ? AND booking_source = ? AND check_in = ? AND check_out = ? AND status NOT IN ('cancelled','expired')");
    $st->execute([$companyId, ARS_AIRBNB_CHANNEL, $email['check_in'], $email['check_out']]);
    $matches = $st->fetchAll(PDO::FETCH_ASSOC);
    $range = $email['check_in'] . ' → ' . $email['check_out'];

    if (count($matches) === 0) {
        return ars_airbnb_mark($conn, $id, 'info', 'You cancelled an Airbnb reservation for ' . $range . '. No Airbnb booking in ARS has those dates — if staff entered it by hand, cancel it there.');
    }
    if (count($matches) > 1) {
        return ars_airbnb_mark($conn, $id, 'needs_review', 'You cancelled an Airbnb reservation for ' . $range . ', but ' . count($matches) . ' ARS bookings have those dates ('
            . implode(', ', array_column($matches, 'booking_number')) . '). Airbnb\'s email doesn\'t say which — cancel the right one by hand.');
    }
    $booking = $matches[0];
    if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
        return ars_airbnb_mark($conn, $id, 'needs_review', 'You cancelled on Airbnb, but ' . $booking['booking_number'] . ' is already ' . $booking['status'] . '. Handle it by hand.', (int)$booking['id']);
    }
    ars_airbnb_cancel_booking($conn, $companyId, $booking, 'Cancelled by host on Airbnb (' . $booking['channel_ref'] . ')', $userId);
    $r = ars_airbnb_mark($conn, $id, 'processed', 'Cancelled ' . $booking['booking_number'] . ' — you cancelled it on Airbnb.', (int)$booking['id'], $userId);
    $r['action'] = 'cancelled';
    return $r;
}

function ars_airbnb_handle_updated(PDO $conn, int $companyId, array $email): array {
    $id = (int)$email['id'];
    $code = (string)$email['confirmation_code'];
    $booking = ars_airbnb_find_booking_by_code($conn, $companyId, $code);
    if (!$booking) {
        return ars_airbnb_mark($conn, $id, 'info', 'Airbnb reservation ' . $code . ' changed — it isn\'t linked to an ARS booking, nothing to update.');
    }
    if ($booking['status'] === 'cancelled') {
        return ars_airbnb_mark($conn, $id, 'info', 'Airbnb reservation ' . $code . ' changed, but ' . $booking['booking_number'] . ' is cancelled.', (int)$booking['id']);
    }
    $hint = '';
    if ($req = ars_airbnb_matching_change_request($conn, $companyId, $email)) {
        $hint = ' Last change request asked for ' . $req['requested_check_in'] . ' → ' . $req['requested_check_out'] . '.';
    }
    return ars_airbnb_mark($conn, $id, 'needs_review',
        'Airbnb changed reservation ' . $code . ' (' . $booking['booking_number'] . ', currently ' . $booking['check_in'] . ' → ' . $booking['check_out']
        . ', AED ' . number_format((float)$booking['total_amount'], 2) . '). Airbnb\'s email does not include the new dates or payout — check the Airbnb app and amend the booking.' . $hint,
        (int)$booking['id']);
}

/** The change request that most likely led to this "Reservation updated" mail (same guest first name + listing). */
function ars_airbnb_matching_change_request(PDO $conn, int $companyId, array $email): ?array {
    $guest = mb_strtolower(trim(explode(' ', (string)$email['guest_name'])[0] ?? ''));
    if ($guest === '') return null;
    $st = $conn->prepare("
        SELECT * FROM ars_channel_emails
         WHERE company_id = ? AND email_type = 'change_request' AND sent_at <= ?
           AND LOWER(listing_title) = LOWER(?) AND requested_check_in IS NOT NULL
         ORDER BY sent_at DESC LIMIT 5
    ");
    $st->execute([$companyId, $email['sent_at'], (string)$email['listing_title']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (mb_strtolower(trim(explode(' ', (string)$row['guest_name'])[0] ?? '')) === $guest) return $row;
    }
    return null;
}

/** Staff says an existing (hand-entered) booking is this Airbnb reservation. No money is changed. */
function ars_airbnb_link_existing(PDO $conn, int $companyId, array $email, string $bookingNumber, int $userId): array {
    $code = (string)$email['confirmation_code'];
    if ($code === '') throw new RuntimeException('This email has no confirmation code to link.');
    if ($other = ars_airbnb_find_booking_by_code($conn, $companyId, $code)) {
        throw new RuntimeException('Airbnb ' . $code . ' is already linked to ' . $other['booking_number'] . '.');
    }
    $st = $conn->prepare('SELECT * FROM ars_bookings WHERE company_id = ? AND booking_number = ? LIMIT 1');
    $st->execute([$companyId, trim($bookingNumber)]);
    $booking = $st->fetch(PDO::FETCH_ASSOC);
    if (!$booking) throw new RuntimeException('Booking ' . $bookingNumber . ' not found.');
    if (!empty($booking['channel_ref'])) {
        throw new RuntimeException($booking['booking_number'] . ' is already linked to Airbnb ' . $booking['channel_ref'] . '.');
    }
    $conn->prepare('UPDATE ars_bookings SET booking_source = ?, channel_ref = ? WHERE id = ? AND company_id = ?')
         ->execute([ARS_AIRBNB_CHANNEL, $code, (int)$booking['id'], $companyId]);
    ars_booking_activity_log($conn, [
        'company_id' => $companyId,
        'booking_id' => (int)$booking['id'],
        'booking_number' => $booking['booking_number'],
        'event_category' => 'operational',
        'event_type' => 'channel_linked',
        'title' => 'Linked to Airbnb ' . $code,
        'source' => 'user',
        'created_by' => $userId,
    ]);
    $diff = '';
    if ($email['payout_amount'] !== null && abs((float)$email['payout_amount'] - (float)$booking['total_amount']) >= 0.01) {
        $diff = ' Note: ARS total AED ' . number_format((float)$booking['total_amount'], 2) . ' vs Airbnb payout AED ' . number_format((float)$email['payout_amount'], 2) . ' — not changed.';
    }
    return ars_airbnb_mark($conn, (int)$email['id'], 'resolved', 'Linked to existing ' . $booking['booking_number'] . ' by staff.' . $diff, (int)$booking['id'], $userId);
}

function ars_airbnb_audit(int $companyId, int $bookingId, string $ref, string $action, string $summary, ?int $userId): void {
    try {
        require_once dirname(__DIR__, 3) . '/includes/AuditService.php';
        AuditService::logEvent([
            'action' => $action,
            'module' => 'ars',
            'company_id' => $companyId,
            'object_type' => 'ars_bookings',
            'object_id' => (string)$bookingId,
            'object_ref' => $ref,
            'summary' => $summary,
            'user_id' => $userId,
            'source' => $userId ? 'user' : 'system',
            'success' => true,
        ]);
    } catch (Throwable $ignored) {}
}

/**
 * Problems worth shouting about on the page and in the alert email.
 * @return list<string>
 */
function ars_airbnb_health_warnings(PDO $conn, int $companyId): array {
    $warn = [];
    $cfg = ars_airbnb_config();
    if (!$cfg['configured']) {
        return ['Gmail login is not set up on this server (AIRBNB_IMAP_USER / AIRBNB_IMAP_PASS in includes/config.php).'];
    }
    $state = ars_airbnb_state($conn, $companyId, $cfg['mailbox']);
    if (!empty($state['last_error'])) {
        $warn[] = 'Last sync failed: ' . $state['last_error'];
    }
    if (empty($state['last_success_at'])) {
        $warn[] = 'The sync has never completed successfully.';
    } elseif (strtotime((string)$state['last_success_at']) < time() - 3 * 3600) {
        $warn[] = 'No successful sync since ' . $state['last_success_at'] . ' — check the cron job.';
    }
    // Airbnb bookings arrive every few days; a week of silence usually means Airbnb changed a subject line.
    $lastMail = $state['last_booking_email_at'] ?: null;
    if ($lastMail === null && !empty($state['start_at']) && strtotime((string)$state['start_at']) < time() - 7 * 86400) {
        $warn[] = 'No Airbnb booking email recognised since the sync started on ' . $state['start_at'] . '. Airbnb may have changed their email format.';
    } elseif ($lastMail !== null && strtotime((string)$lastMail) < time() - 7 * 86400) {
        $warn[] = 'No Airbnb booking email recognised since ' . $lastMail . '. If there have been bookings since then, Airbnb may have changed their email format.';
    }
    return $warn;
}
