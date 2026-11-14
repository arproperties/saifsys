<?php
/**
 * Bills — the rules, shared by the staff app's API (api/mobile/bills) and the
 * Operations report (modules/operations/staff_bills.php).
 *
 * A bill (or money) passes from hand to hand and every hand-over is recorded:
 *
 *   start    date received, amount, attachment, notes, and who it is given to
 *            on which day. Status waiting, with that person.
 *   accept   the person it waits for takes it. Status with them.
 *   reject   they refuse it, with a reason. It goes back to the one who gave
 *            it (status with the giver), who gives it again or keeps it.
 *   give     the one who has it gives it to the next person. Status waiting.
 *   keep     the one who has it keeps it. Status kept: the chain ends there.
 *
 * Each hand-over is one row in bill_moves; together they are the bill's history.
 * Bills is on for everyone with a staff app PIN unless HR turns it off
 * (staff_app_access, app 'bills_off'); only those people can be given a bill:
 * they need the app to accept it. A record only — nothing is
 * posted to any company's accounts. Tables: migrations/staff_bills.sql.
 *
 * "Who" is an array: ['user_id' => int, 'name' => string, 'office' => bool].
 * The office (Operations → Staff Bills) reads every bill; it accepts or rejects
 * only bills waiting for the person signed in, and may discard any.
 * Every refusal is a BillError whose message is written to be shown as it is.
 */

declare(strict_types=1);

require_once __DIR__ . '/web_push.php';

const STAFF_BILLS_APP = 'bills';
const BILL_NOTES_MAX = 500;
const BILL_REASON_MAX = 255;
const BILL_AMOUNT_MAX = 10000000;
const BILL_FILES_MAX = 6;
const BILL_FILE_BYTES = 8 * 1024 * 1024;
const BILL_FILE_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
const BILL_STATUSES = ['waiting' => 'Waiting to accept', 'with' => 'With holder', 'kept' => 'Kept', 'discarded' => 'Discarded'];
const BILL_MOVE_STATUSES = ['waiting' => 'Waiting', 'accepted' => 'Accepted', 'rejected' => 'Rejected'];

class BillError extends RuntimeException
{
    public int $status;
    public string $errCode;

    public function __construct(string $message, int $status = 400, string $code = 'bad_request')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errCode = $code;
    }
}

function bill_tables_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query('SELECT 1 FROM bill_files LIMIT 0');
            $conn->query('SELECT 1 FROM bill_moves LIMIT 0');
            $conn->query('SELECT 1 FROM bill_edits LIMIT 0');
            $conn->query('SELECT 1 FROM bill_delete_requests LIMIT 0');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Now, to the microsecond: two things done in the same second still show in the right order. */
function bill_now(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
}

function bill_today(): string
{
    return date('Y-m-d');
}

/** Spaces squeezed, trimmed, cut to $max characters. */
function bill_text($v, int $max): string
{
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($v ?? ''))), 0, $max);
}

function bill_valid_date($d): bool
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

/** The name shown for a login: full name, or the username. */
function bill_user_name(PDO $conn, int $userId): ?string
{
    $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(fullname), ''), username) FROM `user` WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $name = $stmt->fetchColumn();
    return $name === false ? null : (string)$name;
}

// ---------------------------------------------------------------------------
// Who can be given a bill
// ---------------------------------------------------------------------------

/**
 * Bills is on for everyone with a staff app PIN, until HR turns it off for
 * someone (HR → employee → Mobile App PIN → Bills → Turn off), which leaves a
 * row app = 'bills_off' in staff_app_access (hr/includes/hr_fleet.php).
 */
function bill_has_access(PDO $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("
            SELECT 1 FROM ops_staff_pins p
             WHERE p.user_id = ?
               AND NOT EXISTS (SELECT 1 FROM staff_app_access a WHERE a.user_id = p.user_id AND a.app = ?)
             LIMIT 1
        ");
        $stmt->execute([$userId, STAFF_BILLS_APP . '_off']);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Everyone a bill can be given to: a PIN, Bills not turned off by HR, login
 * active. Not $except (the giver).
 *
 * @return list<array{id:int,name:string}>
 */
function bill_people(PDO $conn, int $except = 0): array
{
    $stmt = $conn->prepare("
        SELECT u.id, COALESCE(NULLIF(TRIM(u.fullname), ''), u.username) AS name
          FROM ops_staff_pins p
          JOIN `user` u ON u.id = p.user_id
         WHERE u.id <> ? AND COALESCE(u.is_active, 1) = 1
           AND NOT EXISTS (SELECT 1 FROM staff_app_access a WHERE a.user_id = u.id AND a.app = ?)
         ORDER BY name
    ");
    $stmt->execute([$except, STAFF_BILLS_APP . '_off']);
    require_once __DIR__ . '/account_status.php';
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        // Left the company or login closed: they could never accept it.
        if (account_login_block_reason($conn, (int)$r['id']) === null) {
            $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
        }
    }
    return $out;
}

/** The person a bill is being given to, checked: not the giver, Bills on, can sign in. */
function bill_recipient(PDO $conn, array $who, $toUserId): array
{
    $to = (int)$toUserId;
    if ($to <= 0) {
        throw new BillError('Choose who you are giving it to.');
    }
    if ($to === (int)$who['user_id']) {
        throw new BillError('You cannot give a bill to yourself.');
    }
    $name = bill_user_name($conn, $to);
    if ($name === null || !bill_has_access($conn, $to)) {
        throw new BillError('That person cannot take bills. They need a staff app PIN, and Bills must not be turned off for them in HR.');
    }
    require_once __DIR__ . '/account_status.php';
    if (account_login_block_reason($conn, $to) !== null) {
        throw new BillError('That person cannot sign in to the app now. Choose someone else.');
    }
    return ['user_id' => $to, 'name' => $name];
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/** The bill row, or null. */
function bill_row(PDO $conn, int $id, bool $lock = false): ?array
{
    $stmt = $conn->prepare('SELECT * FROM bill_bills WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    return $b ?: null;
}

/** Started it, holds it, or was in any hand-over. */
function bill_involved(PDO $conn, int $userId, array $b): bool
{
    if ((int)$b['created_by'] === $userId || (int)$b['holder_user_id'] === $userId) {
        return true;
    }
    $stmt = $conn->prepare('SELECT 1 FROM bill_moves WHERE bill_id = ? AND (from_user_id = ? OR to_user_id = ?) LIMIT 1');
    $stmt->execute([(int)$b['id'], $userId, $userId]);
    return (bool)$stmt->fetchColumn();
}

/** The bill as the asker may see it: staff only bills they are part of, the office any. */
function bill_row_for(PDO $conn, array $who, int $id, bool $lock = false): ?array
{
    $b = bill_row($conn, $id, $lock);
    if (!$b) {
        return null;
    }
    if (empty($who['office']) && ((string)$b['status'] === 'discarded' || !bill_involved($conn, (int)$who['user_id'], $b))) {
        return null;
    }
    return $b;
}

/**
 * Bills as the phone and the report want them: with their files and their
 * history, and (for staff) what the asker may do with each.
 */
function bill_shape_many(PDO $conn, array $rows, ?array $who = null): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $files = $moves = [];
    $stmt = $conn->prepare("SELECT id, bill_id, file_path FROM bill_files WHERE bill_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
        $ext = strtolower(pathinfo((string)$f['file_path'], PATHINFO_EXTENSION));
        $files[(int)$f['bill_id']][] = ['id' => (int)$f['id'], 'mime' => BILL_FILE_TYPES[$ext] ?? 'application/octet-stream'];
    }
    $stmt = $conn->prepare("SELECT * FROM bill_moves WHERE bill_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
        $moves[(int)$m['bill_id']][] = [
            'id' => (int)$m['id'],
            'from' => ['user_id' => (int)$m['from_user_id'], 'name' => (string)$m['from_name']],
            'to' => ['user_id' => (int)$m['to_user_id'], 'name' => (string)$m['to_name']],
            'given_on' => (string)$m['given_on'],
            'status' => (string)$m['status'],
            'reason' => $m['reason'] !== null ? (string)$m['reason'] : null,
            'acted_at' => $m['acted_at'] !== null ? (string)$m['acted_at'] : null,
            'created_at' => (string)$m['created_at'],
        ];
    }
    $edits = [];
    $stmt = $conn->prepare("SELECT * FROM bill_edits WHERE bill_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
        $edits[(int)$e['bill_id']][] = [
            'kind' => (string)($e['kind'] ?? 'edit'),
            'by' => ['user_id' => (int)$e['user_id'], 'name' => (string)$e['user_name']],
            'changes' => json_decode((string)$e['changes'], true) ?: [],
            'reason' => (string)$e['reason'],
            'created_at' => (string)$e['created_at'],
        ];
    }
    $requests = [];
    $stmt = $conn->prepare("SELECT * FROM bill_delete_requests WHERE bill_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $q) {
        $requests[(int)$q['bill_id']][] = [
            'id' => (int)$q['id'],
            'by' => ['user_id' => (int)$q['user_id'], 'name' => (string)$q['user_name']],
            'reason' => (string)$q['reason'],
            'status' => (string)$q['status'],
            'at' => (string)$q['created_at'],
            'decided_by' => $q['decided_by_name'] !== null ? (string)$q['decided_by_name'] : null,
            'decided_at' => $q['decided_at'] !== null ? (string)$q['decided_at'] : null,
            'decline_reason' => $q['decline_reason'] !== null ? (string)$q['decline_reason'] : null,
        ];
    }
    $me = $who && empty($who['office']) ? (int)$who['user_id'] : 0;
    return array_map(static function (array $b) use ($files, $moves, $edits, $requests, $me): array {
        $mine = $me > 0 && (int)$b['holder_user_id'] === $me;
        $billMoves = $moves[(int)$b['id']] ?? [];
        $billEdits = $edits[(int)$b['id']] ?? [];
        $last = $billMoves ? $billMoves[count($billMoves) - 1] : null;
        return [
            'edits' => $billEdits,
            'events' => bill_events($b, $billMoves, $billEdits, $requests[(int)$b['id']] ?? []),
            'delete_request' => bill_open_request($requests[(int)$b['id']] ?? []),
            // Only the one who started it may ask the office to delete it.
            'can_request_delete' => $me > 0 && (int)$b['created_by'] === $me && (string)$b['status'] !== 'discarded'
                && bill_open_request($requests[(int)$b['id']] ?? []) === null,
            'can_edit' => $me > 0 && bill_may_edit($b, $me, in_array('accepted', array_column($billMoves, 'status'), true)),
            'can_note' => bill_may_note($b, $me),
            'amount_locked' => in_array('accepted', array_column($billMoves, 'status'), true),
            'id' => (int)$b['id'],
            'received_on' => (string)$b['received_on'],
            'amount' => (float)$b['amount'],
            'notes' => $b['notes'] !== null ? (string)$b['notes'] : null,
            'status' => (string)$b['status'],
            'by' => ['user_id' => (int)$b['created_by'], 'name' => (string)$b['created_by_name']],
            'holder' => ['user_id' => (int)$b['holder_user_id'], 'name' => (string)$b['holder_name']],
            'kept_at' => $b['kept_at'] !== null ? (string)$b['kept_at'] : null,
            'files' => $files[(int)$b['id']] ?? [],
            'moves' => $billMoves,
            // Came back to its holder: the last hand-over was refused.
            'returned' => (string)$b['status'] === 'with' && $last !== null && $last['status'] === 'rejected',
            'can_answer' => $mine && (string)$b['status'] === 'waiting',
            // On hold while the office decides on a delete request: no Keep / Give (Reject still allowed).
            'can_pass' => $mine && (string)$b['status'] === 'with' && bill_open_request($requests[(int)$b['id']] ?? []) === null,
            'on_hold' => bill_open_request($requests[(int)$b['id']] ?? []) !== null && (string)$b['status'] !== 'discarded',
            'created_at' => (string)$b['created_at'],
            'updated_at' => (string)$b['updated_at'],
        ];
    }, $rows);
}

/**
 * The bill's history in time order, one entry per thing that happened:
 * started, given, accepted, rejected (with reason), edited (what changed and
 * why), kept, discarded by the office (with reason) — and, last, "waiting"
 * while it waits to be accepted.
 * Each: {kind, at, by, to, given_on, reason, changes}.
 */
/** The open delete request in a bill's list of requests, or null. */
function bill_open_request(array $requests): ?array
{
    foreach ($requests as $q) {
        if ($q['status'] === 'open') {
            return $q;
        }
    }
    return null;
}

function bill_events(array $b, array $moves, array $edits, array $requests = []): array
{
    $ev = [];
    $add = static function (string $kind, ?string $at, string $by, array $extra = []) use (&$ev): void {
        $ev[] = array_merge(['kind' => $kind, 'at' => $at, 'by' => $by, 'to' => null, 'given_on' => null, 'reason' => null, 'changes' => null], $extra,
            ['n' => count($ev)]);
    };
    $add('started', (string)$b['created_at'], (string)$b['created_by_name']);
    foreach ($moves as $m) {
        $add('given', $m['created_at'], $m['from']['name'], ['to' => $m['to']['name'], 'given_on' => $m['given_on']]);
        if ($m['status'] === 'accepted' || $m['status'] === 'rejected') {
            $add($m['status'], $m['acted_at'], $m['to']['name'], ['reason' => $m['reason']]);
        }
    }
    foreach ($edits as $e) {
        if ($e['kind'] === 'note') {
            $add('note', $e['created_at'], $e['by']['name'], ['reason' => $e['reason']]);
        } else {
            $add('edited', $e['created_at'], $e['by']['name'], ['reason' => $e['reason'], 'changes' => $e['changes']]);
        }
    }
    if ((string)$b['status'] === 'kept') {
        $add('kept', $b['kept_at'] !== null ? (string)$b['kept_at'] : null, (string)$b['holder_name']);
    }
    foreach ($requests as $q) {
        $add('delete_requested', $q['at'], $q['by']['name'], ['reason' => $q['reason']]);
        if ($q['status'] === 'declined') {
            $add('delete_declined', $q['decided_at'], (string)$q['decided_by'], ['reason' => $q['decline_reason']]);
        }
    }
    if ((string)$b['status'] === 'discarded') {
        $add('discarded', $b['discarded_at'] !== null ? (string)$b['discarded_at'] : null, (string)$b['discarded_by_name'], ['reason' => $b['discard_reason']]);
    }
    usort($ev, static fn(array $x, array $y): int => [(string)$x['at'], $x['n']] <=> [(string)$y['at'], $y['n']]);
    if ((string)$b['status'] === 'waiting') {
        $ev[] = ['kind' => 'waiting', 'at' => null, 'by' => (string)$b['holder_name'], 'to' => null, 'given_on' => null, 'reason' => null, 'changes' => null];
    }
    return array_map(static function (array $e): array {
        unset($e['n']);
        return $e;
    }, $ev);
}

/**
 * Who may change a bill: only the one who started it, and only at the first
 * stage — before anyone has accepted it. After that nobody edits; the starter
 * and whoever holds it may add notes (bill_add_note). Everyone else only
 * rejects, gives or keeps.
 */
function bill_may_edit(array $b, int $userId, bool $accepted): bool
{
    if ($userId <= 0 || $accepted || in_array((string)$b['status'], ['kept', 'discarded'], true)) {
        return false;
    }
    // Only the one who started it, and only before anyone has accepted it.
    return (int)$b['created_by'] === $userId;
}

/** Add a note: the one who started it, or whoever holds it now; not on a deleted bill. */
function bill_may_note(array $b, int $userId): bool
{
    return $userId > 0 && (string)$b['status'] !== 'discarded'
        && ((int)$b['created_by'] === $userId || (int)$b['holder_user_id'] === $userId);
}

/**
 * The amount is fixed once anyone has accepted the bill (the first level is
 * done). Before that — still waiting with the first person, or rejected back —
 * the one who started it may correct it.
 */
function bill_amount_locked(PDO $conn, int $billId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM bill_moves WHERE bill_id = ? AND status = 'accepted' LIMIT 1");
    $stmt->execute([$billId]);
    return (bool)$stmt->fetchColumn();
}

function bill_get(PDO $conn, array $who, int $id): ?array
{
    $b = bill_row_for($conn, $who, $id);
    return $b ? bill_shape_many($conn, [$b], $who)[0] : null;
}

/**
 * The phone's list. open: every bill waiting for the asker or with them (to act
 * on). recent: every bill they are part of, newest first, thirty at a time
 * ($before = the last id they have).
 */
function bill_list_mine(PDO $conn, array $who, int $before = 0): array
{
    $me = (int)$who['user_id'];
    $open = [];
    if ($before === 0) {
        $stmt = $conn->prepare("SELECT * FROM bill_bills WHERE holder_user_id = ? AND status IN ('waiting', 'with') ORDER BY id DESC");
        $stmt->execute([$me]);
        $open = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $stmt = $conn->prepare("
        SELECT b.* FROM bill_bills b
         WHERE (b.created_by = ? OR b.holder_user_id = ?
                OR EXISTS (SELECT 1 FROM bill_moves m WHERE m.bill_id = b.id AND (m.from_user_id = ? OR m.to_user_id = ?)))
           AND b.status <> 'discarded'
           AND (? = 0 OR b.id < ?)
         ORDER BY b.id DESC LIMIT 30
    ");
    $stmt->execute([$me, $me, $me, $me, $before, $before]);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return [
        'open' => bill_shape_many($conn, $open, $who),
        'recent' => bill_shape_many($conn, $recent, $who),
        'more' => count($recent) === 30,
    ];
}

/**
 * The Operations report: bills received between two days.
 * $f: from, to, person (user id: started, held or handed over), status, q (notes or a name),
 * company (id: the company of the employee who started the bill; 0 = all companies).
 */
function bill_list_all(PDO $conn, array $f): array
{
    $today = bill_today();
    $from = bill_valid_date($f['from'] ?? null) ? $f['from'] : substr($today, 0, 7) . '-01';
    $to = bill_valid_date($f['to'] ?? null) ? $f['to'] : $today;
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $where = ['b.received_on BETWEEN ? AND ?'];
    $params = [$from, $to];
    $person = (int)($f['person'] ?? 0);
    if ($person > 0) {
        $where[] = '(b.created_by = ? OR b.holder_user_id = ? OR EXISTS (SELECT 1 FROM bill_moves m WHERE m.bill_id = b.id AND (m.from_user_id = ? OR m.to_user_id = ?)))';
        array_push($params, $person, $person, $person, $person);
    }
    $company = (int)($f['company'] ?? 0);
    if ($company > 0) {
        // Through either link HR uses: employees.user_id, or user.employee_id.
        $where[] = '(EXISTS (SELECT 1 FROM employees e WHERE e.user_id = b.created_by AND e.company_id = ?)
            OR EXISTS (SELECT 1 FROM `user` u JOIN employees e ON e.id = u.employee_id WHERE u.id = b.created_by AND e.company_id = ?))';
        array_push($params, $company, $company);
    }
    if (($f['status'] ?? '') === 'delete_requested') {
        $where[] = "b.status <> 'discarded' AND EXISTS (SELECT 1 FROM bill_delete_requests q WHERE q.bill_id = b.id AND q.status = 'open')";
    } elseif (isset(BILL_STATUSES[$f['status'] ?? ''])) {
        $where[] = 'b.status = ?';
        $params[] = $f['status'];
    } else {
        $where[] = "b.status <> 'discarded'"; // only under Status: Discarded
    }
    $q = bill_text($f['q'] ?? '', 80);
    if ($q !== '') {
        $where[] = "(b.notes LIKE CONCAT('%', ?, '%') OR b.created_by_name LIKE CONCAT('%', ?, '%') OR b.holder_name LIKE CONCAT('%', ?, '%') OR b.id = ?)";
        array_push($params, $q, $q, $q, ctype_digit($q) ? (int)$q : 0);
    }
    $stmt = $conn->prepare('SELECT b.* FROM bill_bills b WHERE ' . implode(' AND ', $where) . ' ORDER BY b.received_on DESC, b.id DESC LIMIT 1000');
    $stmt->execute($params);
    $bills = bill_shape_many($conn, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], ['office' => true]);

    $people = $conn->query("
        SELECT id, MAX(name) AS name FROM (
            SELECT created_by AS id, created_by_name AS name FROM bill_bills
            UNION ALL SELECT to_user_id, to_name FROM bill_moves
        ) x GROUP BY id ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $companies = $conn->query('SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return [
        'from' => $from,
        'to' => $to,
        'companies' => $companies,
        'bills' => $bills,
        'total' => round(array_sum(array_column($bills, 'amount')), 2),
        'people' => $people,
    ];
}

// ---------------------------------------------------------------------------
// Writing. Each returns [bill, notifications]: the API sends the
// notifications once the answer has gone back to the phone.
// ---------------------------------------------------------------------------

/** A day typed on the phone: real, not in the future, not before $notBefore. */
function bill_read_day($v, string $what, ?string $notBefore = null): string
{
    $today = bill_today();
    $d = (string)($v ?? '');
    if ($d === '') {
        return $today;
    }
    if (!bill_valid_date($d)) {
        throw new BillError('The ' . $what . ' is not a real day.');
    }
    if ($d > $today) {
        throw new BillError('The ' . $what . ' cannot be in the future.');
    }
    if ($notBefore !== null && $d < $notBefore) {
        throw new BillError('The ' . $what . ' cannot be before the day the bill was received.');
    }
    return $d;
}

/** "AED 1,250.00" */
function bill_money(float $n): string
{
    return 'AED ' . number_format($n, 2);
}

/** What the person given a bill is told. */
function bill_message_given(array $from, float $amount, int $billId): array
{
    return [
        'user_id' => 0, // set by the caller
        'title' => 'You got a bill',
        'body' => $from['name'] . ' gave you a bill of ' . bill_money($amount) . '. Tap to accept or reject it.',
        'tag' => 'bill-' . $billId,
        'url' => './#bills',
    ];
}

/**
 * Start a bill. $input: received_on, amount, notes, client_ref, and optionally
 * to (user id) + given_on — without them the bill stays with whoever started it
 * (status with). $files: the attachments sent with it.
 *
 * @param array[] $files each ['tmp' => path, 'uploaded' => bool]
 * @return array{0:array,1:list<array>}
 */
function bill_create(PDO $conn, array $who, array $input, array $files = []): array
{
    if (!empty($who['office'])) {
        throw new BillError('Bills are started on the staff app.', 403, 'forbidden');
    }
    $clientRef = bill_text($input['client_ref'] ?? '', 64);
    if ($clientRef !== '') {
        $stmt = $conn->prepare('SELECT id FROM bill_bills WHERE created_by = ? AND client_ref = ?');
        $stmt->execute([(int)$who['user_id'], $clientRef]);
        $seen = (int)$stmt->fetchColumn();
        if ($seen > 0) {
            return [bill_get($conn, $who, $seen), []];
        }
    }
    $receivedOn = bill_read_day($input['received_on'] ?? '', 'date received');
    $amount = is_numeric($input['amount'] ?? null) ? round((float)$input['amount'], 2) : 0.0;
    if ($amount <= 0 || $amount > BILL_AMOUNT_MAX) {
        throw new BillError('Write the amount.');
    }
    $notes = bill_text($input['notes'] ?? '', BILL_NOTES_MAX);
    // "to" is optional: without it the bill stays with whoever started it, to
    // be given later from the list (one or many at a time).
    $to = (int)($input['to'] ?? 0) > 0 ? bill_recipient($conn, $who, $input['to']) : null;
    $givenOn = $to ? bill_read_day($input['given_on'] ?? '', 'date given', $receivedOn) : null;
    $photos = bill_check_files($files);

    $conn->beginTransaction();
    try {
        $now = bill_now();
        $holder = $to ?? $who;
        $conn->prepare("INSERT INTO bill_bills (received_on, amount, notes, created_by, created_by_name, status, holder_user_id, holder_name,
                client_ref, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$receivedOn, $amount, $notes !== '' ? $notes : null, (int)$who['user_id'], (string)$who['name'],
                $to ? 'waiting' : 'with', (int)$holder['user_id'], (string)$holder['name'], $clientRef !== '' ? $clientRef : null, $now, $now]);
        $id = (int)$conn->lastInsertId();
        if ($to) {
            bill_insert_move($conn, $id, $who, $to, $givenOn, $now);
        }
        bill_store_files($conn, $id, $photos);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    if (!$to) {
        return [bill_get($conn, $who, $id), []];
    }
    $msg = bill_message_given($who, $amount, $id);
    $msg['user_id'] = $to['user_id'];
    return [bill_get($conn, $who, $id), [$msg]];
}

function bill_insert_move(PDO $conn, int $billId, array $from, array $to, string $givenOn, string $now): void
{
    $conn->prepare("INSERT INTO bill_moves (bill_id, from_user_id, from_name, to_user_id, to_name, given_on, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'waiting', ?)")
        ->execute([$billId, (int)$from['user_id'], (string)$from['name'], (int)$to['user_id'], (string)$to['name'], $givenOn, $now]);
}

/** The hand-over waiting on this bill (locked), or null. */
function bill_waiting_move(PDO $conn, int $billId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM bill_moves WHERE bill_id = ? AND status = 'waiting' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$billId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    return $m ?: null;
}

/**
 * Run $change on a bill the asker holds, inside a transaction, after checking
 * it is in $status. Returns null when the bill is not the asker's to see.
 */
function bill_change(PDO $conn, array $who, int $id, string $status, callable $change): ?array
{
    if (!empty($who['office'])) {
        throw new BillError('The office does not change bills here.', 403, 'forbidden');
    }
    $notify = [];
    $conn->beginTransaction();
    try {
        $b = bill_row_for($conn, $who, $id, true);
        if (!$b) {
            $conn->rollBack();
            return null;
        }
        if ((int)$b['holder_user_id'] !== (int)$who['user_id'] || (string)$b['status'] !== $status) {
            throw new BillError(bill_state_message($b, $who), 409, 'conflict');
        }
        $notify = $change($b, bill_now());
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    return [bill_get($conn, $who, $id), $notify ?: []];
}

/** A delete request is open: the bill waits for the office (no accept, give or keep). */
function bill_on_hold(PDO $conn, int $billId): bool
{
    $q = $conn->prepare("SELECT 1 FROM bill_delete_requests WHERE bill_id = ? AND status = 'open' LIMIT 1");
    $q->execute([$billId]);
    return (bool)$q->fetchColumn();
}

/** Refuse when on hold. */
function bill_not_on_hold(PDO $conn, int $billId): void
{
    if (bill_on_hold($conn, $billId)) {
        throw new BillError('Delete requested: this bill waits for the office. It cannot be accepted, given or kept now.', 409, 'on_hold');
    }
}

/** Why the asker cannot do that now, in words for the phone. */
function bill_state_message(array $b, array $who): string
{
    if ((string)$b['status'] === 'kept') {
        return 'This bill is finished: ' . $b['holder_name'] . ' kept it.';
    }
    if ((int)$b['holder_user_id'] !== (int)$who['user_id']) {
        return (string)$b['status'] === 'waiting'
            ? 'This bill is now waiting for ' . $b['holder_name'] . ' to accept it.'
            : 'This bill is now with ' . $b['holder_name'] . '.';
    }
    return (string)$b['status'] === 'waiting' ? 'Accept or reject this bill first.' : 'You have already accepted this bill.';
}

function bill_accept(PDO $conn, array $who, int $id): ?array
{
    return bill_change($conn, $who, $id, 'waiting', static function (array $b, string $now) use ($conn): array {
        bill_not_on_hold($conn, (int)$b['id']);
        $m = bill_waiting_move($conn, (int)$b['id']);
        if ($m) {
            $conn->prepare("UPDATE bill_moves SET status = 'accepted', acted_at = ? WHERE id = ?")->execute([$now, (int)$m['id']]);
        }
        $conn->prepare("UPDATE bill_bills SET status = 'with', updated_at = ? WHERE id = ?")->execute([$now, (int)$b['id']]);
        return [];
    });
}

/** Refuse it: back to whoever gave it, who is told why. */
function bill_reject(PDO $conn, array $who, int $id, $reason): ?array
{
    $reason = bill_text($reason, BILL_REASON_MAX);
    if ($reason === '') {
        throw new BillError('Write why you are rejecting it.');
    }
    return bill_change($conn, $who, $id, 'waiting', static function (array $b, string $now) use ($conn, $who, $reason): array {
        $m = bill_waiting_move($conn, (int)$b['id']);
        if (!$m) {
            throw new BillError('This bill cannot be sent back. Ask the office.', 409, 'conflict');
        }
        $conn->prepare("UPDATE bill_moves SET status = 'rejected', reason = ?, acted_at = ? WHERE id = ?")->execute([$reason, $now, (int)$m['id']]);
        $conn->prepare("UPDATE bill_bills SET status = 'with', holder_user_id = ?, holder_name = ?, updated_at = ? WHERE id = ?")
            ->execute([(int)$m['from_user_id'], (string)$m['from_name'], $now, (int)$b['id']]);
        return [[
            'user_id' => (int)$m['from_user_id'],
            'title' => 'Bill rejected',
            'body' => $who['name'] . ' rejected the bill of ' . bill_money((float)$b['amount']) . ': ' . $reason,
            'tag' => 'bill-' . (int)$b['id'],
            'url' => './#bills',
        ]];
    });
}

/** Give it to the next person. $input: to (user id), given_on. */
function bill_give(PDO $conn, array $who, int $id, array $input): ?array
{
    $to = bill_recipient($conn, $who, $input['to'] ?? 0);
    return bill_change($conn, $who, $id, 'with', static function (array $b, string $now) use ($conn, $who, $to, $input): array {
        bill_not_on_hold($conn, (int)$b['id']);
        $givenOn = bill_read_day($input['given_on'] ?? '', 'date given', (string)$b['received_on']);
        bill_insert_move($conn, (int)$b['id'], $who, $to, $givenOn, $now);
        $conn->prepare("UPDATE bill_bills SET status = 'waiting', holder_user_id = ?, holder_name = ?, updated_at = ? WHERE id = ?")
            ->execute([$to['user_id'], $to['name'], $now, (int)$b['id']]);
        $msg = bill_message_given($who, (float)$b['amount'], (int)$b['id']);
        $msg['user_id'] = $to['user_id'];
        return [$msg];
    });
}

/** Keep it: the chain ends with the asker. */
function bill_keep(PDO $conn, array $who, int $id): ?array
{
    return bill_change($conn, $who, $id, 'with', static function (array $b, string $now) use ($conn): array {
        bill_not_on_hold($conn, (int)$b['id']);
        $conn->prepare("UPDATE bill_bills SET status = 'kept', kept_at = ?, updated_at = ? WHERE id = ?")->execute([$now, $now, (int)$b['id']]);
        return [];
    });
}

/**
 * Change a bill, with a reason. $input: reason (needed), and any of
 * received_on, amount (not once anyone has accepted it), notes. $files: more attachments. Only what really
 * changed is recorded (bill_edits), as [what, before, after]. When someone else
 * has the bill, they are told.
 */
function bill_edit(PDO $conn, array $who, int $id, array $input, array $files = []): ?array
{
    if (!empty($who['office'])) {
        throw new BillError('The office does not change bills here.', 403, 'forbidden');
    }
    $reason = bill_text($input['reason'] ?? '', BILL_REASON_MAX);
    if ($reason === '') {
        throw new BillError('Write why you are changing it.');
    }
    $photos = bill_check_files($files);
    $notify = [];
    $conn->beginTransaction();
    try {
        $b = bill_row_for($conn, $who, $id, true);
        if (!$b) {
            $conn->rollBack();
            return null;
        }
        if (!bill_may_edit($b, (int)$who['user_id'], bill_amount_locked($conn, (int)$b['id']))) {
            throw new BillError((string)$b['status'] === 'kept'
                ? 'This bill is finished: ' . $b['holder_name'] . ' kept it. It cannot be changed.'
                : ((int)$b['created_by'] === (int)$who['user_id']
                    ? 'This bill has already been accepted, so it cannot be changed. You can add a note.'
                    : 'Only the person who started this bill can change it. You can add a note.'), 409, 'conflict');
        }
        $changes = [];
        $set = [];
        if (array_key_exists('received_on', $input)) {
            $stmt = $conn->prepare('SELECT MIN(given_on) FROM bill_moves WHERE bill_id = ?');
            $stmt->execute([(int)$b['id']]);
            $firstGiven = $stmt->fetchColumn();
            $d = bill_read_day($input['received_on'], 'date received');
            if ($firstGiven && $d > $firstGiven) {
                throw new BillError('The date received cannot be after the day it was first given (' . date('d M Y', strtotime((string)$firstGiven)) . ').');
            }
            if ($d !== (string)$b['received_on']) {
                $changes[] = ['Date received', date('d M Y', strtotime((string)$b['received_on'])), date('d M Y', strtotime($d))];
                $set['received_on'] = $d;
            }
        }
        if (array_key_exists('amount', $input)) {
            $amount = is_numeric($input['amount']) ? round((float)$input['amount'], 2) : 0.0;
            if ($amount <= 0 || $amount > BILL_AMOUNT_MAX) {
                throw new BillError('Write the amount.');
            }
            if (abs($amount - (float)$b['amount']) >= 0.005 && bill_amount_locked($conn, (int)$b['id'])) {
                throw new BillError('The amount cannot be changed: the bill has already been accepted.', 409, 'locked');
            }
            if (abs($amount - (float)$b['amount']) >= 0.005) {
                $changes[] = ['Amount', bill_money((float)$b['amount']), bill_money($amount)];
                $set['amount'] = $amount;
            }
        }
        if (array_key_exists('notes', $input)) {
            $notes = bill_text($input['notes'], BILL_NOTES_MAX);
            if ($notes !== (string)($b['notes'] ?? '')) {
                $changes[] = ['Notes', (string)($b['notes'] ?? ''), $notes];
                $set['notes'] = $notes !== '' ? $notes : null;
            }
        }
        if ($photos) {
            $changes[] = ['Attachment', '', count($photos) === 1 ? '1 added' : count($photos) . ' added'];
        }
        if (!$changes) {
            throw new BillError('Nothing was changed.');
        }
        $now = bill_now();
        $set['updated_at'] = $now;
        $conn->prepare('UPDATE bill_bills SET ' . implode(', ', array_map(static fn(string $c): string => "`$c` = ?", array_keys($set))) . ' WHERE id = ?')
            ->execute(array_merge(array_values($set), [(int)$b['id']]));
        $conn->prepare('INSERT INTO bill_edits (bill_id, user_id, user_name, changes, reason, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int)$b['id'], (int)$who['user_id'], (string)$who['name'], json_encode($changes, JSON_UNESCAPED_UNICODE), $reason, $now]);
        bill_store_files($conn, (int)$b['id'], $photos);
        $conn->commit();
        if ((int)$b['holder_user_id'] !== (int)$who['user_id']) {
            $notify[] = [
                'user_id' => (int)$b['holder_user_id'],
                'title' => 'Bill changed',
                'body' => $who['name'] . ' changed bill #' . (int)$b['id'] . ': ' . $reason,
                'tag' => 'bill-' . (int)$b['id'],
                'url' => './#bills',
            ];
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    return [bill_get($conn, $who, $id), $notify];
}

/**
 * The office throws a bill away (Operations → Staff Bills, Owner or Admin), with a
 * reason. Nothing is deleted: the bill leaves every phone and the totals, and
 * stays in Operations → Staff Bills under Status: Discarded with who did it and why. Whoever had
 * it is told. $office: ['user_id', 'name'] of the person signed in.
 *
 * @return array{0:array,1:list<array>}|null
 */
function bill_discard(PDO $conn, array $office, int $id, $reason): ?array
{
    $reason = bill_text($reason, BILL_REASON_MAX);
    if ($reason === '') {
        throw new BillError('Write why the bill is being discarded.');
    }
    $conn->beginTransaction();
    try {
        $b = bill_row($conn, $id, true);
        if (!$b) {
            $conn->rollBack();
            return null;
        }
        if ((string)$b['status'] === 'discarded') {
            throw new BillError('This bill is already discarded.', 409, 'conflict');
        }
        $q = $conn->prepare("SELECT * FROM bill_delete_requests WHERE bill_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $q->execute([$id]);
        $request = $q->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new BillError('Only a bill whose starter asked for it to be deleted can be deleted.', 409, 'no_request');
        }
        $now = bill_now();
        $conn->prepare("UPDATE bill_bills SET status = 'discarded', discarded_at = ?, discarded_by = ?, discarded_by_name = ?, discard_reason = ?, updated_at = ? WHERE id = ?")
            ->execute([$now, (int)$office['user_id'], (string)$office['name'], $reason, $now, $id]);
        $conn->prepare("UPDATE bill_delete_requests SET status = 'deleted', decided_by = ?, decided_by_name = ?, decided_at = ? WHERE id = ?")
            ->execute([(int)$office['user_id'], (string)$office['name'], $now, (int)$request['id']]);
        // A hand-over still waiting is closed with it.
        $conn->prepare("UPDATE bill_moves SET status = 'rejected', reason = ?, acted_at = ? WHERE bill_id = ? AND status = 'waiting'")
            ->execute(['Discarded by the office', $now, $id]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    $notify = [];
    if ((int)$request['user_id'] !== (int)$b['holder_user_id'] && (int)$request['user_id'] !== (int)$office['user_id']) {
        $notify[] = [
            'user_id' => (int)$request['user_id'],
            'title' => 'Bill deleted',
            'body' => 'The office deleted bill #' . $id . ' (' . bill_money((float)$b['amount']) . ') as you asked.',
            'tag' => 'bill-' . $id,
            'url' => './#bills',
        ];
    }
    if ((int)$b['holder_user_id'] !== (int)$office['user_id']) {
        $notify[] = [
            'user_id' => (int)$b['holder_user_id'],
            'title' => 'Bill discarded',
            'body' => 'The office discarded bill #' . $id . ' (' . bill_money((float)$b['amount']) . '): ' . $reason,
            'tag' => 'bill-' . $id,
            'url' => './#bills',
        ];
    }
    return [bill_get($conn, ['office' => true], $id), $notify];
}

/**
 * The same action on several bills (accept, reject, give, keep). Each bill is done on
 * its own, so one that cannot be done (moved on meanwhile) does not stop the
 * others; it comes back in "failed" with the reason. Giving several to one
 * person sends one notification ("gave you 3 bills"), not one per bill.
 *
 * @return array{0:array{bills:list<array>,failed:list<array{id:int,message:string}>},1:list<array>}
 */
function bill_many(PDO $conn, array $who, string $action, $ids, array $input = []): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn(int $i): bool => $i > 0)));
    if (!$ids) {
        throw new BillError('Tick at least one bill.');
    }
    if (count($ids) > 100) {
        throw new BillError('At most 100 bills at a time.');
    }
    if ($action === 'reject' && bill_text($input['reason'] ?? '', BILL_REASON_MAX) === '') {
        throw new BillError('Write why you are rejecting them.');
    }
    $to = $action === 'give' ? bill_recipient($conn, $who, $input['to'] ?? 0) : null;
    $done = $failed = $notify = [];
    foreach ($ids as $id) {
        try {
            $r = match ($action) {
                'accept' => bill_accept($conn, $who, $id),
                'reject' => bill_reject($conn, $who, $id, $input['reason'] ?? ''),
                'give' => bill_give($conn, $who, $id, ['to' => $to['user_id'], 'given_on' => $input['given_on'] ?? '']),
                'keep' => bill_keep($conn, $who, $id),
                default => throw new BillError('Unknown action.'),
            };
            if ($r === null || $r[0] === null) {
                $failed[] = ['id' => $id, 'message' => 'That bill is not there any more.'];
                continue;
            }
            $done[] = $r[0];
            array_push($notify, ...$r[1]);
        } catch (BillError $e) {
            $failed[] = ['id' => $id, 'message' => $e->getMessage()];
        }
    }
    if ($action === 'give' && count($done) > 1) {
        $total = array_sum(array_column($done, 'amount'));
        $notify = [[
            'user_id' => $to['user_id'],
            'title' => 'You got ' . count($done) . ' bills',
            'body' => $who['name'] . ' gave you ' . count($done) . ' bills, ' . bill_money((float)$total) . ' in all. Tap to accept or reject them.',
            'tag' => 'bills-from-' . (int)$who['user_id'],
            'url' => './#bills',
        ]];
    }
    return [['bills' => $done, 'failed' => $failed], $notify];
}

/**
 * The one who started a bill asks the office to delete it, with a reason. Only
 * the office deletes (bill_discard) or declines (bill_decline_delete).
 */
function bill_request_delete(PDO $conn, array $who, int $id, $reason): ?array
{
    if (!empty($who['office'])) {
        throw new BillError('The office deletes from Operations → Staff Bills.', 403, 'forbidden');
    }
    $reason = bill_text($reason, BILL_REASON_MAX);
    if ($reason === '') {
        throw new BillError('Write why it should be deleted.');
    }
    $conn->beginTransaction();
    try {
        $b = bill_row_for($conn, $who, $id, true);
        if (!$b) {
            $conn->rollBack();
            return null;
        }
        if ((int)$b['created_by'] !== (int)$who['user_id']) {
            throw new BillError('Only the person who started this bill can ask for it to be deleted.', 403, 'forbidden');
        }
        $q = $conn->prepare("SELECT 1 FROM bill_delete_requests WHERE bill_id = ? AND status = 'open' LIMIT 1");
        $q->execute([$id]);
        if ($q->fetchColumn()) {
            throw new BillError('You have already asked for this bill to be deleted. The office will decide.', 409, 'conflict');
        }
        $conn->prepare("INSERT INTO bill_delete_requests (bill_id, user_id, user_name, reason, status, created_at) VALUES (?, ?, ?, ?, 'open', ?)")
            ->execute([$id, (int)$who['user_id'], (string)$who['name'], $reason, bill_now()]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    return [bill_get($conn, $who, $id), []];
}

/** The office says no to a delete request (reason optional). The one who asked is told. */
function bill_decline_delete(PDO $conn, array $office, int $id, $reason = ''): ?array
{
    $reason = bill_text($reason, BILL_REASON_MAX);
    $q = $conn->prepare("SELECT * FROM bill_delete_requests WHERE bill_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
    $q->execute([$id]);
    $request = $q->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        throw new BillError('There is no delete request on this bill.', 409, 'no_request');
    }
    $conn->prepare("UPDATE bill_delete_requests SET status = 'declined', decided_by = ?, decided_by_name = ?, decided_at = ?, decline_reason = ? WHERE id = ? AND status = 'open'")
        ->execute([(int)$office['user_id'], (string)$office['name'], bill_now(), $reason !== '' ? $reason : null, (int)$request['id']]);
    $notify = [];
    if ((int)$request['user_id'] !== (int)$office['user_id']) {
        $notify[] = [
            'user_id' => (int)$request['user_id'],
            'title' => 'Delete request declined',
            'body' => 'The office did not delete bill #' . $id . ($reason !== '' ? ': ' . $reason : '.'),
            'tag' => 'bill-' . $id,
            'url' => './#bills',
        ];
    }
    return [bill_get($conn, ['office' => true], $id), $notify];
}

/**
 * Add a note to a bill (no change to it): the starter or whoever holds it.
 * Shows in the history. Whoever holds it is told, when someone else wrote it.
 */
function bill_add_note(PDO $conn, array $who, int $id, $note): ?array
{
    if (!empty($who['office'])) {
        throw new BillError('Notes are added on the staff app.', 403, 'forbidden');
    }
    $note = bill_text($note, BILL_REASON_MAX);
    if ($note === '') {
        throw new BillError('Write the note.');
    }
    $b = bill_row_for($conn, $who, $id);
    if (!$b) {
        return null;
    }
    if (!bill_may_note($b, (int)$who['user_id'])) {
        throw new BillError('Only the person who started this bill, or the person who has it now, can add a note.', 403, 'forbidden');
    }
    $conn->prepare("INSERT INTO bill_edits (bill_id, user_id, user_name, kind, changes, reason, created_at) VALUES (?, ?, ?, 'note', '[]', ?, ?)")
        ->execute([$id, (int)$who['user_id'], (string)$who['name'], $note, bill_now()]);
    $notify = [];
    if ((int)$b['holder_user_id'] !== (int)$who['user_id']) {
        $notify[] = [
            'user_id' => (int)$b['holder_user_id'],
            'title' => 'Note on a bill',
            'body' => $who['name'] . ' added a note to bill #' . $id . ': ' . $note,
            'tag' => 'bill-' . $id,
            'url' => './#bills',
        ];
    }
    return [bill_get($conn, $who, $id), $notify];
}

// ---------------------------------------------------------------------------
// Notifications (web push, the same phones as the attendance reminder)
// ---------------------------------------------------------------------------

function bill_push_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query('SELECT 1 FROM staff_push_subscriptions LIMIT 0');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Save (or refresh) one phone's subscription for a user. The same table and
 * the same checks as the attendance reminder's (ops_push_subscribe), so one
 * phone signed up in either place gets both kinds of notification.
 */
function bill_push_subscribe(PDO $conn, int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): bool
{
    if (!preg_match('#^https://#i', $endpoint) && !preg_match('#^http://localhost(:\d+)?/#i', $endpoint)) {
        return false;
    }
    if (strlen(web_push_b64u_decode($p256dh)) !== 65 || strlen(web_push_b64u_decode($auth)) !== 16) {
        return false;
    }
    $conn->prepare("
        INSERT INTO staff_push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, user_agent, last_seen_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth),
            user_agent = VALUES(user_agent), last_seen_at = NOW()
    ")->execute([$userId, hash('sha256', $endpoint), $endpoint, $p256dh, $auth, mb_substr($userAgent, 0, 255)]);
    return true;
}

function bill_push_unsubscribe(PDO $conn, int $userId, string $endpoint): void
{
    $conn->prepare('DELETE FROM staff_push_subscriptions WHERE endpoint_hash = ? AND user_id = ?')
        ->execute([hash('sha256', $endpoint), $userId]);
}

/**
 * Send each message to every phone its person has notifications on for.
 * A phone the browser has dropped is forgotten. Never throws.
 */
function bill_notify(PDO $conn, array $messages): void
{
    if (!$messages || !bill_push_ready($conn)) {
        return;
    }
    try {
        $subs = $conn->prepare('SELECT id, endpoint, p256dh, auth FROM staff_push_subscriptions WHERE user_id = ?');
        foreach ($messages as $msg) {
            $subs->execute([(int)$msg['user_id']]);
            $targets = $subs->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$targets) {
                continue;
            }
            $payload = ['title' => $msg['title'], 'body' => $msg['body'], 'tag' => $msg['tag'], 'url' => $msg['url'], 'type' => 'open-bills'];
            foreach (web_push_send_many($conn, $targets, $payload, 86400) as $r) {
                if ($r['gone']) {
                    $conn->prepare('DELETE FROM staff_push_subscriptions WHERE id = ?')->execute([(int)$r['target']['id']]);
                } elseif ($r['error'] !== '') {
                    error_log('bills push to user ' . (int)$msg['user_id'] . ': ' . $r['status'] . ' ' . $r['error']);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('bills push: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Attachments
// ---------------------------------------------------------------------------

function bill_root(): string
{
    return dirname(__DIR__);
}

/** What a file really is, by its first bytes — never by the name or type the phone says. */
function bill_sniff(string $path): ?string
{
    $h = @fopen($path, 'rb');
    if (!$h) {
        return null;
    }
    $b = (string)fread($h, 12);
    fclose($h);
    if (strlen($b) < 12) {
        return null;
    }
    if (substr($b, 0, 3) === "\xFF\xD8\xFF") {
        return 'jpg';
    }
    if (substr($b, 0, 8) === "\x89PNG\r\n\x1A\n") {
        return 'png';
    }
    if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') {
        return 'webp';
    }
    if (substr($b, 0, 5) === '%PDF-') {
        return 'pdf';
    }
    return null;
}

/** $_FILES[$field] (one or many) as a list of ['tmp' => path, 'uploaded' => true]. */
function bill_uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || !isset($f['tmp_name'])) {
        return [];
    }
    $tmp = (array)$f['tmp_name'];
    $err = (array)$f['error'];
    $out = [];
    foreach ($tmp as $i => $path) {
        $code = (int)($err[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            throw new BillError('That file is too big to send.', 413, 'too_big');
        }
        if ($code !== UPLOAD_ERR_OK) {
            throw new BillError('The attachment did not come through. Try again.');
        }
        $out[] = ['tmp' => (string)$path, 'uploaded' => true];
    }
    return $out;
}

/** @return array[] each ['tmp', 'uploaded', 'ext'] — checked, nothing kept yet */
function bill_check_files(array $files): array
{
    if (count($files) > BILL_FILES_MAX) {
        throw new BillError('At most ' . BILL_FILES_MAX . ' attachments on one bill.');
    }
    $out = [];
    foreach ($files as $f) {
        $size = @filesize((string)$f['tmp']);
        if ($size === false || $size === 0) {
            continue;
        }
        if ($size > BILL_FILE_BYTES) {
            throw new BillError('That file is too big to send.', 413, 'too_big');
        }
        $ext = bill_sniff((string)$f['tmp']);
        if ($ext === null) {
            throw new BillError('An attachment must be a JPG, PNG or WEBP photo, or a PDF.');
        }
        $out[] = $f + ['ext' => $ext];
    }
    return $out;
}

function bill_store_files(PDO $conn, int $billId, array $photos): void
{
    if (!$photos) {
        return;
    }
    $stmt = $conn->prepare('SELECT COUNT(*) FROM bill_files WHERE bill_id = ?');
    $stmt->execute([$billId]);
    if ((int)$stmt->fetchColumn() + count($photos) > BILL_FILES_MAX) {
        throw new BillError('At most ' . BILL_FILES_MAX . ' attachments on one bill.');
    }
    $root = bill_root();
    $base = $root . '/uploads/bills';
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }
    // Not web-readable: the app and the report read them through PHP.
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $dirRel = 'uploads/bills/' . $billId;
    if (!is_dir($root . '/' . $dirRel) && !@mkdir($root . '/' . $dirRel, 0755, true)) {
        throw new BillError('The attachment could not be kept. Try again.', 500, 'server');
    }
    $ins = $conn->prepare('INSERT INTO bill_files (bill_id, file_path, created_at) VALUES (?, ?, ?)');
    foreach ($photos as $ph) {
        $rel = $dirRel . '/bill_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ph['ext'];
        $ok = !empty($ph['uploaded']) ? move_uploaded_file($ph['tmp'], $root . '/' . $rel) : copy($ph['tmp'], $root . '/' . $rel);
        if (!$ok) {
            throw new BillError('The attachment could not be kept. Try again.', 500, 'server');
        }
        $ins->execute([$billId, $rel, bill_now()]);
    }
}

/** The file on disk and its type, for one attachment of a bill the asker may see; or null. */
function bill_file(PDO $conn, array $who, int $id, int $fileId): ?array
{
    $b = bill_row_for($conn, $who, $id);
    if (!$b) {
        return null;
    }
    $stmt = $conn->prepare('SELECT file_path FROM bill_files WHERE id = ? AND bill_id = ?');
    $stmt->execute([$fileId, (int)$b['id']]);
    $rel = $stmt->fetchColumn();
    if ($rel === false) {
        return null;
    }
    $base = realpath(bill_root() . '/uploads/bills');
    $abs = realpath(bill_root() . '/' . ltrim((string)$rel, '/'));
    if ($base === false || $abs === false || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
        return null;
    }
    // Content-Type from the extension (whitelist), never from the file's bytes.
    $type = BILL_FILE_TYPES[strtolower(pathinfo($abs, PATHINFO_EXTENSION))] ?? null;
    return $type === null ? null : ['path' => $abs, 'mime' => $type];
}

/** Send one file and stop. */
function bill_send_file(array $f): void
{
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($f['path']));
    header('X-Content-Type-Options: nosniff');
    if ($f['mime'] !== 'application/pdf') {
        header("Content-Security-Policy: default-src 'none'");
    }
    header('Cache-Control: private, max-age=3600');
    readfile($f['path']);
    exit;
}
