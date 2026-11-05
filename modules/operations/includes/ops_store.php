<?php
/**
 * Store — the rules, shared by the staff app's API (api/mobile/store) and the
 * office pages (modules/operations/store*.php).
 *
 * Several stores, one item list. The store keeper records every change on the
 * phone, each one an entry ("move"):
 *   in     goods came into the store            store + qty
 *   out    given to a person (for a job/unit)   store − qty
 *   move   sent to another store                store − qty, other store + qty
 *   count  the real number after a count        store set to the counted number
 * Stock (st_stock) is changed in the same transaction as the entry and never
 * goes below 0. Quantities only — no money. Not linked to Purchase, nothing
 * from the old inventory (inv_*). No approval.
 *
 * The store keeper may change or delete his own entry on the day he made it;
 * the office any day. A count cannot be changed, only deleted and done again.
 *
 * "Who" is an array: ['user_id' => int, 'name' => string, 'office' => bool].
 * Every refusal is a StError whose message is written to be shown as it is.
 * Tables: migrations/staff_store.sql.
 */

require_once __DIR__ . '/ops_helper.php'; // Asia/Dubai

const ST_NAME_MAX = 120;
const ST_STORE_MAX = 80;
const ST_UNIT_MAX = 20;
const ST_NOTE_MAX = 160;
const ST_NOTES_MAX = 500;
const ST_LINES_MAX = 50;
const ST_QTY_MAX = 1000000;
const ST_FILE_BYTES = 8 * 1024 * 1024;
const ST_KINDS = ['in' => 'Goods in', 'out' => 'Goods out', 'move' => 'Moved', 'count' => 'Count corrected'];
const ST_REASONS = ['lost' => 'Lost', 'damaged' => 'Damaged', 'counted' => 'Counted'];
const ST_FILE_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

class StError extends RuntimeException
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

function st_tables_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query('SELECT 1 FROM st_move_lines LIMIT 0');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

function st_now(): string
{
    return date('Y-m-d H:i:s');
}

function st_today(): string
{
    return date('Y-m-d');
}

function st_text($v, int $max): string
{
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($v ?? ''))), 0, $max);
}

/** "2" for 2.000, "1.5" for 1.500. */
function st_qty($n): string
{
    $s = rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

function st_valid_date($d): bool
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

// ---------------------------------------------------------------------------
// Stores and items
// ---------------------------------------------------------------------------

function st_stores(PDO $conn, bool $activeOnly = true): array
{
    $rows = $conn->query('SELECT id, name, active FROM st_stores' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'active' => (bool)$r['active']], $rows);
}

function st_store_row(PDO $conn, int $id, bool $mustBeActive = true): array
{
    $stmt = $conn->prepare('SELECT id, name, active FROM st_stores WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s || ($mustBeActive && !(int)$s['active'])) {
        throw new StError('Pick the store again — that one is not on the list any more.');
    }
    return $s;
}

/** Add or rename a store (office). */
function st_save_store(PDO $conn, int $id, string $name, bool $active = true): int
{
    $name = st_text($name, ST_STORE_MAX);
    if ($name === '') {
        throw new StError('Give the store a name.');
    }
    $stmt = $conn->prepare('SELECT id FROM st_stores WHERE name = ? AND id <> ?');
    $stmt->execute([$name, $id]);
    if ($stmt->fetchColumn()) {
        throw new StError('There is already a store called ' . $name . '.');
    }
    if ($id > 0) {
        if (!$active) {
            $q = $conn->prepare('SELECT COUNT(*) FROM st_stock WHERE store_id = ? AND qty <> 0');
            $q->execute([$id]);
            if ((int)$q->fetchColumn() > 0) {
                throw new StError('This store still has stock. Move it out or count it to 0 first.');
            }
        }
        $conn->prepare('UPDATE st_stores SET name = ?, active = ? WHERE id = ?')->execute([$name, $active ? 1 : 0, $id]);
        return $id;
    }
    $conn->prepare('INSERT INTO st_stores (name, active, created_at) VALUES (?, 1, ?)')->execute([$name, st_now()]);
    return (int)$conn->lastInsertId();
}

/**
 * Every active item with how many are in each store.
 * @return array[] each: id, name, unit, min_qty, photo, stock {store_id => qty}, total
 */
function st_items(PDO $conn, string $q = '', bool $activeOnly = true): array
{
    $q = st_text($q, ST_NAME_MAX);
    $stmt = $conn->prepare('SELECT id, name, unit, min_qty, photo_path, active FROM st_items
        WHERE (? = 0 OR active = 1) AND (? = \'\' OR name LIKE CONCAT(\'%\', ?, \'%\'))
        ORDER BY name LIMIT 2000');
    $stmt->execute([$activeOnly ? 1 : 0, $q, $q]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stock = [];
    foreach ($conn->query('SELECT store_id, item_id, qty FROM st_stock WHERE qty <> 0')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
        $stock[(int)$s['item_id']][(int)$s['store_id']] = (float)$s['qty'];
    }
    return array_map(static function (array $i) use ($stock): array {
        $per = $stock[(int)$i['id']] ?? [];
        return [
            'id' => (int)$i['id'],
            'name' => (string)$i['name'],
            'unit' => $i['unit'] !== null ? (string)$i['unit'] : null,
            'min_qty' => $i['min_qty'] !== null ? (float)$i['min_qty'] : null,
            'photo' => $i['photo_path'] !== null,
            'active' => (bool)$i['active'],
            'stock' => (object)$per,
            'total' => array_sum($per),
        ];
    }, $items);
}

/**
 * Add an item (store keeper or office) or change one (office). Returns its id.
 * A name already on the list (any case) gives that item back, not a second one.
 */
function st_save_item(PDO $conn, array $who, int $id, array $input): int
{
    $name = st_text($input['name'] ?? '', ST_NAME_MAX);
    if ($name === '') {
        throw new StError('Write the name of the item.');
    }
    $unit = st_text($input['unit'] ?? '', ST_UNIT_MAX);
    $min = ($input['min_qty'] ?? '') === '' || ($input['min_qty'] ?? null) === null ? null : (is_numeric($input['min_qty']) ? max(0, (float)$input['min_qty']) : null);
    if ($id > 0) {
        if (empty($who['office'])) {
            throw new StError('Ask the office to change an item.', 403, 'forbidden');
        }
        $stmt = $conn->prepare('SELECT id FROM st_items WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $id]);
        if ($stmt->fetchColumn()) {
            throw new StError('There is already an item called ' . $name . '.');
        }
        $active = !array_key_exists('active', $input) || !empty($input['active']);
        $conn->prepare('UPDATE st_items SET name = ?, unit = ?, min_qty = ?, active = ? WHERE id = ?')
            ->execute([$name, $unit !== '' ? $unit : null, $min, $active ? 1 : 0, $id]);
        return $id;
    }
    $stmt = $conn->prepare('SELECT id, active FROM st_items WHERE name = ?');
    $stmt->execute([$name]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        if (!(int)$found['active']) {
            $conn->prepare('UPDATE st_items SET active = 1 WHERE id = ?')->execute([(int)$found['id']]);
        }
        return (int)$found['id'];
    }
    $conn->prepare('INSERT INTO st_items (name, unit, min_qty, active, created_at, created_by) VALUES (?, ?, ?, 1, ?, ?)')
        ->execute([$name, $unit !== '' ? $unit : null, $min, st_now(), (int)$who['user_id'] ?: null]);
    return (int)$conn->lastInsertId();
}

/** Current staff, for "who took it". */
function st_people(PDO $conn): array
{
    $stmt = $conn->prepare("SELECT id, full_name, employee_code FROM employees
        WHERE status IN ('active', 'on_leave', 'notice_period') AND full_name <> '' ORDER BY full_name");
    $stmt->execute();
    return array_map(static fn(array $r): array => [
        'id' => (int)$r['id'], 'name' => trim((string)$r['full_name']), 'code' => (string)($r['employee_code'] ?? ''),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

// ---------------------------------------------------------------------------
// Stock
// ---------------------------------------------------------------------------

/** Change one item's stock in one store; refuses to go below 0. Call inside a transaction. */
function st_apply(PDO $conn, int $storeId, int $itemId, float $delta): float
{
    $stmt = $conn->prepare('SELECT qty FROM st_stock WHERE store_id = ? AND item_id = ? FOR UPDATE');
    $stmt->execute([$storeId, $itemId]);
    $have = $stmt->fetchColumn();
    $now = round(($have === false ? 0.0 : (float)$have) + $delta, 3);
    if ($now < 0) {
        $q = $conn->prepare('SELECT i.name, i.unit, s.name AS store FROM st_items i, st_stores s WHERE i.id = ? AND s.id = ?');
        $q->execute([$itemId, $storeId]);
        $n = $q->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'this item', 'unit' => '', 'store' => 'this store'];
        throw new StError('Only ' . st_qty($have === false ? 0 : $have) . ($n['unit'] ? ' ' . $n['unit'] : '') . ' ' . $n['name'] . ' in ' . $n['store'] . '.', 409, 'not_enough');
    }
    $conn->prepare('INSERT INTO st_stock (store_id, item_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)')
        ->execute([$storeId, $itemId, $now]);
    return $now;
}

/** What an entry did to stock, as [store_id, item_id, delta] triples. */
function st_effects(array $move, array $lines): array
{
    $out = [];
    foreach ($lines as $l) {
        $item = (int)$l['item_id'];
        $q = (float)$l['qty'];
        switch ($move['kind']) {
            case 'in':
            case 'count':
                $out[] = [(int)$move['store_id'], $item, $q];
                break;
            case 'out':
                $out[] = [(int)$move['store_id'], $item, -$q];
                break;
            case 'move':
                $out[] = [(int)$move['store_id'], $item, -$q];
                $out[] = [(int)$move['to_store_id'], $item, $q];
                break;
        }
    }
    return $out;
}

/** Undo what a saved entry did. Refuses when the goods are already gone (stock would drop below 0). */
function st_reverse(PDO $conn, array $move): void
{
    $stmt = $conn->prepare('SELECT item_id, qty FROM st_move_lines WHERE move_id = ?');
    $stmt->execute([(int)$move['id']]);
    $effects = st_effects($move, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    // Take back what was added first, so a refusal names the right item.
    usort($effects, static fn(array $a, array $b): int => $b[2] <=> $a[2]);
    foreach ($effects as [$store, $item, $delta]) {
        try {
            st_apply($conn, $store, $item, -$delta);
        } catch (StError $e) {
            throw new StError($e->getMessage() . ' Some of it has already gone out — correct the count instead.', 409, 'not_enough');
        }
    }
}

// ---------------------------------------------------------------------------
// Entries
// ---------------------------------------------------------------------------

const ST_SELECT = 'SELECT m.*, s.name AS store_name, t.name AS to_store_name
    FROM st_moves m JOIN st_stores s ON s.id = m.store_id LEFT JOIN st_stores t ON t.id = m.to_store_id';

function st_shape_many(PDO $conn, array $rows, ?string $today = null): array
{
    if (!$rows) {
        return [];
    }
    $today = $today ?? st_today();
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT l.move_id, l.item_id, l.qty, l.counted, i.name, i.unit
        FROM st_move_lines l JOIN st_items i ON i.id = l.item_id WHERE l.move_id IN ($in) ORDER BY l.id");
    $stmt->execute($ids);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
        $lines[(int)$l['move_id']][] = [
            'item_id' => (int)$l['item_id'], 'name' => (string)$l['name'], 'unit' => $l['unit'] !== null ? (string)$l['unit'] : null,
            'qty' => (float)$l['qty'], 'counted' => $l['counted'] !== null ? (float)$l['counted'] : null,
        ];
    }
    return array_map(static fn(array $m): array => [
        'id' => (int)$m['id'],
        'kind' => (string)$m['kind'],
        'store' => ['id' => (int)$m['store_id'], 'name' => (string)$m['store_name']],
        'to_store' => $m['to_store_id'] !== null ? ['id' => (int)$m['to_store_id'], 'name' => (string)$m['to_store_name']] : null,
        'given_to' => $m['given_to_name'] !== null ? ['id' => $m['given_to_employee_id'] !== null ? (int)$m['given_to_employee_id'] : null, 'name' => (string)$m['given_to_name']] : null,
        'for_note' => $m['for_note'] !== null ? (string)$m['for_note'] : null,
        'source' => $m['source'] !== null ? (string)$m['source'] : null,
        'reason' => $m['reason'] !== null ? (string)$m['reason'] : null,
        'notes' => $m['notes'] !== null ? (string)$m['notes'] : null,
        'photo' => $m['photo_path'] !== null,
        'by' => ['user_id' => (int)$m['user_id'], 'name' => (string)$m['person_name']],
        'lines' => $lines[(int)$m['id']] ?? [],
        'can_change' => (string)$m['created_day'] === $today,
        'created_at' => (string)$m['created_at'],
        'updated_at' => (string)$m['updated_at'],
        'updated_by' => $m['updated_by_name'] !== null ? (string)$m['updated_by_name'] : null,
    ], $rows);
}

function st_row_for(PDO $conn, array $who, int $id, bool $lock = false): ?array
{
    $stmt = $conn->prepare(ST_SELECT . ' WHERE m.id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m || (empty($who['office']) && (int)$m['user_id'] !== (int)$who['user_id'])) {
        return null;
    }
    return $m;
}

function st_get(PDO $conn, array $who, int $id, ?string $today = null): ?array
{
    $m = st_row_for($conn, $who, $id);
    return $m ? st_shape_many($conn, [$m], $today)[0] : null;
}

/** The store keeper's own entries, newest first, thirty at a time. */
function st_list_mine(PDO $conn, array $who, int $before = 0, ?string $today = null): array
{
    $stmt = $conn->prepare(ST_SELECT . ' WHERE m.user_id = ? AND (? = 0 OR m.id < ?) ORDER BY m.id DESC LIMIT 30');
    $stmt->execute([(int)$who['user_id'], $before, $before]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return ['moves' => st_shape_many($conn, $rows, $today), 'more' => count($rows) === 30];
}

/** The office history. $f: from, to, store, item, person (user id), kind. */
function st_list_all(PDO $conn, array $f, ?string $today = null): array
{
    $today = $today ?? st_today();
    $from = st_valid_date($f['from'] ?? null) ? $f['from'] : date('Y-m-d', strtotime($today . ' -30 days'));
    $to = st_valid_date($f['to'] ?? null) ? $f['to'] : $today;
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $where = ['m.created_at >= ?', 'm.created_at < ?'];
    $params = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
    if ((int)($f['store'] ?? 0) > 0) {
        $where[] = '(m.store_id = ? OR m.to_store_id = ?)';
        array_push($params, (int)$f['store'], (int)$f['store']);
    }
    if ((int)($f['item'] ?? 0) > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM st_move_lines l WHERE l.move_id = m.id AND l.item_id = ?)';
        $params[] = (int)$f['item'];
    }
    if ((int)($f['person'] ?? 0) > 0) {
        $where[] = 'm.user_id = ?';
        $params[] = (int)$f['person'];
    }
    if (isset(ST_KINDS[$f['kind'] ?? ''])) {
        $where[] = 'm.kind = ?';
        $params[] = $f['kind'];
    }
    $stmt = $conn->prepare(ST_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT 1000');
    $stmt->execute($params);
    $people = $conn->query('SELECT user_id AS id, MAX(person_name) AS name FROM st_moves GROUP BY user_id ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return ['from' => $from, 'to' => $to, 'moves' => st_shape_many($conn, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $today), 'people' => $people];
}

function st_may_change(array $who, array $m, string $today): void
{
    if (!empty($who['office'])) {
        return;
    }
    if ((int)$m['user_id'] !== (int)$who['user_id']) {
        throw new StError('That entry is not there any more.', 404, 'not_found');
    }
    if ((string)$m['created_day'] !== $today) {
        throw new StError('This entry is from an earlier day. Ask the office to change it.', 409, 'locked');
    }
}

/**
 * The lines as sent: [{item_id, qty}] (count: qty = the real number found).
 * Items must exist; the same item twice is added together.
 */
function st_read_lines(PDO $conn, string $kind, $raw): array
{
    if (!is_array($raw) || $raw === []) {
        throw new StError('Add at least one item.');
    }
    if (count($raw) > ST_LINES_MAX) {
        throw new StError('At most ' . ST_LINES_MAX . ' items at a time.');
    }
    $byItem = [];
    foreach (array_values($raw) as $i => $l) {
        $l = is_array($l) ? $l : [];
        $itemId = (int)($l['item_id'] ?? 0);
        $stmt = $conn->prepare('SELECT id, name FROM st_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new StError('Item ' . ($i + 1) . ' is not on the list any more. Pick it again.');
        }
        $qty = is_numeric($l['qty'] ?? null) ? round((float)$l['qty'], 3) : null;
        if ($kind === 'count') {
            if ($qty === null || $qty < 0 || $qty > ST_QTY_MAX) {
                throw new StError($item['name'] . ': write the number you counted.');
            }
            if (isset($byItem[$itemId])) {
                throw new StError($item['name'] . ' is in the list twice.');
            }
        } elseif ($qty === null || $qty <= 0 || $qty > ST_QTY_MAX) {
            throw new StError($item['name'] . ': write how many.');
        }
        $byItem[$itemId] = ($byItem[$itemId] ?? 0) + $qty;
    }
    $out = [];
    foreach ($byItem as $itemId => $qty) {
        $out[] = ['item_id' => $itemId, 'qty' => $qty];
    }
    return $out;
}

/**
 * Make an entry ($id 0), or change one. $input: kind, store_id, to_store_id
 * (move), given_to_employee_id (out), for_note (out), source (in), reason
 * (count), notes, client_ref, lines. $photo: one optional file (in).
 *
 * @param array|null $photo ['tmp' => path, 'uploaded' => bool]
 * @return array|null the entry, or null when it is not the asker's to see
 */
function st_save(PDO $conn, array $who, int $id, array $input, ?array $photo = null, ?string $today = null): ?array
{
    $today = $today ?? st_today();
    $office = !empty($who['office']);
    $kind = (string)($input['kind'] ?? '');
    if (!isset(ST_KINDS[$kind])) {
        throw new StError('Say if it is goods in, goods out, a move or a count.');
    }
    $clientRef = st_text($input['client_ref'] ?? '', 64);
    if ($id === 0 && $clientRef !== '' && !$office) {
        $stmt = $conn->prepare('SELECT id FROM st_moves WHERE user_id = ? AND client_ref = ?');
        $stmt->execute([(int)$who['user_id'], $clientRef]);
        $seen = (int)$stmt->fetchColumn();
        if ($seen > 0) {
            return st_get($conn, $who, $seen, $today);
        }
    }
    if ($id === 0 && $office) {
        throw new StError('The office does not record entries here; the store keeper does it on the phone.', 403, 'forbidden');
    }

    $storeId = (int)($input['store_id'] ?? 0);
    $toStoreId = $kind === 'move' ? (int)($input['to_store_id'] ?? 0) : 0;
    $lines = st_read_lines($conn, $kind, $input['lines'] ?? null);
    $notes = st_text($input['notes'] ?? '', ST_NOTES_MAX);
    $source = $kind === 'in' ? st_text($input['source'] ?? '', 120) : '';
    $forNote = $kind === 'out' ? st_text($input['for_note'] ?? '', ST_NOTE_MAX) : '';
    $reason = $kind === 'count' ? (string)($input['reason'] ?? '') : '';
    if ($kind === 'count' && !isset(ST_REASONS[$reason])) {
        throw new StError('Say why the number is different: lost, damaged, or just counted.');
    }
    $givenId = null;
    $givenName = null;
    if ($kind === 'out') {
        $givenId = (int)($input['given_to_employee_id'] ?? 0);
        $stmt = $conn->prepare('SELECT full_name FROM employees WHERE id = ?');
        $stmt->execute([$givenId]);
        $givenName = trim((string)$stmt->fetchColumn());
        if ($givenId <= 0 || $givenName === '') {
            throw new StError('Pick who took the items.');
        }
    }
    $file = null;
    if ($photo !== null) {
        $file = st_check_file($photo);
    }

    $conn->beginTransaction();
    try {
        $old = $id > 0 ? st_row_for($conn, $who, $id, true) : null;
        if ($id > 0 && !$old) {
            $conn->rollBack();
            return null;
        }
        if ($old) {
            st_may_change($who, $old, $today);
            if ($old['kind'] === 'count' || $kind === 'count') {
                throw new StError('A count cannot be changed. Delete it and count again.', 409, 'locked');
            }
            if ($kind !== $old['kind']) {
                throw new StError('An entry keeps its kind. Delete it and make a new one.', 409, 'locked');
            }
        }
        st_store_row($conn, $storeId, !$old || (int)$old['store_id'] !== $storeId);
        if ($kind === 'move') {
            if ($toStoreId === $storeId) {
                throw new StError('Pick a different store to move to.');
            }
            st_store_row($conn, $toStoreId, !$old || (int)$old['to_store_id'] !== $toStoreId);
        }
        if ($old) {
            st_reverse($conn, $old);
        }
        // A count saves the difference from what the store had, and the number found.
        $saved = [];
        foreach ($lines as $l) {
            if ($kind === 'count') {
                $stmt = $conn->prepare('SELECT qty FROM st_stock WHERE store_id = ? AND item_id = ? FOR UPDATE');
                $stmt->execute([$storeId, $l['item_id']]);
                $have = (float)($stmt->fetchColumn() ?: 0);
                $saved[] = ['item_id' => $l['item_id'], 'qty' => round($l['qty'] - $have, 3), 'counted' => $l['qty']];
            } else {
                $saved[] = ['item_id' => $l['item_id'], 'qty' => $l['qty'], 'counted' => null];
            }
        }
        $now = st_now();
        $values = [
            $storeId, $toStoreId ?: null, $givenId, $givenName, $forNote !== '' ? $forNote : null, $source !== '' ? $source : null,
            $reason !== '' ? $reason : null, $notes !== '' ? $notes : null,
        ];
        if ($old) {
            $conn->prepare('UPDATE st_moves SET store_id = ?, to_store_id = ?, given_to_employee_id = ?, given_to_name = ?, for_note = ?,
                    source = ?, reason = ?, notes = ?, updated_at = ?, updated_by_name = ? WHERE id = ?')
                ->execute(array_merge($values, [$now, (string)$who['name'], (int)$old['id']]));
            $conn->prepare('DELETE FROM st_move_lines WHERE move_id = ?')->execute([(int)$old['id']]);
            $mid = (int)$old['id'];
        } else {
            $conn->prepare('INSERT INTO st_moves (store_id, to_store_id, given_to_employee_id, given_to_name, for_note, source, reason, notes,
                    kind, user_id, person_name, created_day, client_ref, created_at, updated_at, updated_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($values, [$kind, (int)$who['user_id'], (string)$who['name'], $today, $clientRef !== '' ? $clientRef : null, $now, $now, (string)$who['name']]));
            $mid = (int)$conn->lastInsertId();
        }
        $ins = $conn->prepare('INSERT INTO st_move_lines (move_id, item_id, qty, counted) VALUES (?, ?, ?, ?)');
        foreach ($saved as $l) {
            $ins->execute([$mid, $l['item_id'], $l['qty'], $l['counted']]);
        }
        $move = ['id' => $mid, 'kind' => $kind, 'store_id' => $storeId, 'to_store_id' => $toStoreId ?: null];
        // Take away first, so "only 3 in the store" is the message when it is short.
        $effects = st_effects($move, $saved);
        usort($effects, static fn(array $a, array $b): int => $a[2] <=> $b[2]);
        foreach ($effects as [$store, $item, $delta]) {
            if ($delta != 0) {
                st_apply($conn, $store, $item, $delta);
            }
        }
        if ($file !== null) {
            $rel = st_store_file($file, 'moves', 'move' . $mid);
            $q = $conn->prepare('SELECT photo_path FROM st_moves WHERE id = ?');
            $q->execute([$mid]);
            $oldPhoto = $q->fetchColumn();
            $conn->prepare('UPDATE st_moves SET photo_path = ? WHERE id = ?')->execute([$rel, $mid]);
            if ($oldPhoto) {
                st_drop_file((string)$oldPhoto);
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    return st_get($conn, $who, $mid, $today);
}

function st_delete(PDO $conn, array $who, int $id, ?string $today = null): ?array
{
    $today = $today ?? st_today();
    $conn->beginTransaction();
    try {
        $m = st_row_for($conn, $who, $id, true);
        if (!$m) {
            $conn->rollBack();
            return null;
        }
        st_may_change($who, $m, $today);
        st_reverse($conn, $m);
        $conn->prepare('DELETE FROM st_moves WHERE id = ?')->execute([(int)$m['id']]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    if ($m['photo_path']) {
        st_drop_file((string)$m['photo_path']);
    }
    return ['deleted' => (int)$m['id']];
}

// ---------------------------------------------------------------------------
// Photos (one per entry, one per item)
// ---------------------------------------------------------------------------

function st_root(): string
{
    return dirname(__DIR__, 3);
}

function st_sniff(string $path): ?string
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
    return null;
}

/** $_FILES[$field] as ['tmp' => path, 'uploaded' => true], or null when none was sent. */
function st_uploaded_file(string $field): ?array
{
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || !isset($f['tmp_name']) || is_array($f['tmp_name'])) {
        return null;
    }
    $code = (int)$f['error'];
    if ($code === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        throw new StError('That photo is too big to send.', 413, 'too_big');
    }
    if ($code !== UPLOAD_ERR_OK) {
        throw new StError('The photo did not come through. Try again.');
    }
    return ['tmp' => (string)$f['tmp_name'], 'uploaded' => true];
}

function st_check_file(array $f): array
{
    $size = @filesize((string)$f['tmp']);
    if (!$size) {
        throw new StError('The photo did not come through. Try again.');
    }
    if ($size > ST_FILE_BYTES) {
        throw new StError('That photo is too big to send.', 413, 'too_big');
    }
    $ext = st_sniff((string)$f['tmp']);
    if ($ext === null) {
        throw new StError('A photo must be a JPG, PNG or WEBP picture.');
    }
    return $f + ['ext' => $ext];
}

function st_store_file(array $f, string $folder, string $prefix): string
{
    $root = st_root();
    $base = $root . '/uploads/store';
    if (!is_dir($base . '/' . $folder) && !@mkdir($base . '/' . $folder, 0755, true)) {
        throw new StError('The photo could not be kept. Try again.', 500, 'server');
    }
    // Not web-readable: the app and the office read them through PHP.
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $rel = 'uploads/store/' . $folder . '/' . $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $f['ext'];
    $ok = !empty($f['uploaded']) ? move_uploaded_file($f['tmp'], $root . '/' . $rel) : copy($f['tmp'], $root . '/' . $rel);
    if (!$ok) {
        throw new StError('The photo could not be kept. Try again.', 500, 'server');
    }
    return $rel;
}

function st_drop_file(string $rel): void
{
    if (strpos($rel, 'uploads/store/') === 0 && strpos($rel, '..') === false) {
        @unlink(st_root() . '/' . $rel);
    }
}

/** Give an item its photo (store keeper or office); the old one goes. */
function st_set_item_photo(PDO $conn, int $itemId, array $photo): void
{
    $f = st_check_file($photo);
    $stmt = $conn->prepare('SELECT photo_path FROM st_items WHERE id = ?');
    $stmt->execute([$itemId]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) {
        throw new StError('That item is not on the list any more.', 404, 'not_found');
    }
    $rel = st_store_file($f, 'items', 'item' . $itemId);
    $conn->prepare('UPDATE st_items SET photo_path = ? WHERE id = ?')->execute([$rel, $itemId]);
    if ($old['photo_path']) {
        st_drop_file((string)$old['photo_path']);
    }
}

/** The photo file of an item, or of an entry the asker may see; or null. */
function st_photo(PDO $conn, array $who, string $what, int $id): ?array
{
    if ($what === 'item') {
        $stmt = $conn->prepare('SELECT photo_path FROM st_items WHERE id = ?');
        $stmt->execute([$id]);
        $rel = $stmt->fetchColumn();
    } else {
        $m = st_row_for($conn, $who, $id);
        $rel = $m ? $m['photo_path'] : null;
    }
    if (!$rel) {
        return null;
    }
    $base = realpath(st_root() . '/uploads/store');
    $abs = realpath(st_root() . '/' . ltrim((string)$rel, '/'));
    if ($base === false || $abs === false || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
        return null;
    }
    $type = ST_FILE_TYPES[strtolower(pathinfo($abs, PATHINFO_EXTENSION))] ?? null;
    return $type === null ? null : ['path' => $abs, 'mime' => $type];
}

function st_send_file(array $f): void
{
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($f['path']));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    header('Cache-Control: private, max-age=3600');
    readfile($f['path']);
    exit;
}
