<?php
/**
 * Purchases — the rules, shared by the staff app's API (api/mobile/purchase)
 * and the office pages (modules/operations/purchases.php, purchase_view.php).
 *
 * The buyer records what he bought on the phone, as it happens:
 *   cash   paid at the shop there and then. Photo of the bill. Status paid.
 *   order  ordered from a supplier, the goods come later. Status ordered, then
 *          received when they arrive (photo of the delivery note or invoice).
 * Nobody approves first: the buyer decides and records. A record only — no
 * stock, no invoice, nothing posted to any company's accounts. Its own tables
 * (migrations/staff_purchases.sql), nothing from the old inventory (inv_*).
 *
 * The buyer may change or delete his own purchase on the day he made it.
 * After that only the office can, from Operations → Purchases.
 *
 * "Who" is an array: ['user_id' => int, 'name' => string, 'office' => bool].
 * Every refusal is a PurError whose message is written to be shown as it is.
 */

require_once __DIR__ . '/ops_helper.php'; // Asia/Dubai

const PUR_NAME_MAX = 80;
const PUR_LINE_MAX = 120;
const PUR_UNIT_MAX = 20;
const PUR_NOTES_MAX = 500;
const PUR_LINES_MAX = 50;
const PUR_QTY_MAX = 1000000;
const PUR_PRICE_MAX = 10000000;
const PUR_FILES_MAX = 10;              // on one purchase
const PUR_FILE_BYTES = 8 * 1024 * 1024;
const PUR_KINDS = ['cash' => 'Paid cash', 'order' => 'Order'];
const PUR_STATUSES = ['paid' => 'Paid cash', 'ordered' => 'Waiting to arrive', 'received' => 'Received'];
const PUR_FILE_KINDS = ['bill' => 'Bill', 'delivery' => 'Delivery note'];
const PUR_FILE_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

class PurError extends RuntimeException
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

function pur_tables_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query('SELECT 1 FROM pur_purchase_files LIMIT 0');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

function pur_now(): string
{
    return date('Y-m-d H:i:s');
}

function pur_today(): string
{
    return date('Y-m-d');
}

/** Spaces squeezed, trimmed, cut to $max characters. */
function pur_text($v, int $max): string
{
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($v ?? ''))), 0, $max);
}

function pur_money(float $n): float
{
    return round($n, 2);
}

function pur_valid_date($d): bool
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

// ---------------------------------------------------------------------------
// Suppliers
// ---------------------------------------------------------------------------

/** The most used first; with $q, the ones whose name has it. */
function pur_list_suppliers(PDO $conn, string $q = ''): array
{
    $q = pur_text($q, PUR_NAME_MAX);
    $stmt = $conn->prepare("
        SELECT s.id, s.name, COUNT(p.id) AS used
        FROM pur_suppliers s
        LEFT JOIN pur_purchases p ON p.supplier_id = s.id
        WHERE ? = '' OR s.name LIKE CONCAT('%', ?, '%')
        GROUP BY s.id, s.name
        ORDER BY used DESC, s.name
        LIMIT 30
    ");
    $stmt->execute([$q, $q]);
    return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'used' => (int)$r['used']], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/** The supplier by id, or by name — a new name becomes a new supplier. */
function pur_supplier_for(PDO $conn, array $input): int
{
    $id = (int)($input['supplier_id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare('SELECT id FROM pur_suppliers WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            throw new PurError('Pick the shop or supplier again — that one is not on the list any more.');
        }
        return $id;
    }
    $name = pur_text($input['supplier'] ?? '', PUR_NAME_MAX);
    if ($name === '') {
        throw new PurError('Write the name of the shop or supplier.');
    }
    // The name is unique without regard to case, so "ace hardware" finds "Ace Hardware".
    $stmt = $conn->prepare('INSERT INTO pur_suppliers (name, created_at) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $stmt->execute([$name, pur_now()]);
    return (int)$conn->lastInsertId();
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

const PUR_SELECT = 'SELECT p.*, s.name AS supplier_name FROM pur_purchases p JOIN pur_suppliers s ON s.id = p.supplier_id';

/** @param array[] $rows purchase rows; @return array[] in the shape the app and pages use */
function pur_shape_many(PDO $conn, array $rows, ?string $today = null): array
{
    if (!$rows) {
        return [];
    }
    $today = $today ?? pur_today();
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $lines = $files = [];
    $stmt = $conn->prepare("SELECT purchase_id, name, qty, unit, price, amount FROM pur_purchase_lines WHERE purchase_id IN ($in) ORDER BY position, id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
        $lines[(int)$l['purchase_id']][] = [
            'name' => (string)$l['name'], 'qty' => (float)$l['qty'], 'unit' => $l['unit'] !== null ? (string)$l['unit'] : null,
            'price' => (float)$l['price'], 'amount' => (float)$l['amount'],
        ];
    }
    $stmt = $conn->prepare("SELECT id, purchase_id, kind, file_path FROM pur_purchase_files WHERE purchase_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
        $ext = strtolower(pathinfo((string)$f['file_path'], PATHINFO_EXTENSION));
        $files[(int)$f['purchase_id']][] = ['id' => (int)$f['id'], 'kind' => (string)$f['kind'], 'mime' => PUR_FILE_TYPES[$ext] ?? 'application/octet-stream'];
    }
    return array_map(static fn(array $p): array => [
        'id' => (int)$p['id'],
        'kind' => (string)$p['kind'],
        'status' => (string)$p['status'],
        'supplier' => ['id' => (int)$p['supplier_id'], 'name' => (string)$p['supplier_name']],
        'bought_on' => (string)$p['bought_on'],
        'received_on' => $p['received_on'] !== null ? (string)$p['received_on'] : null,
        'total' => (float)$p['total'],
        'notes' => $p['notes'] !== null ? (string)$p['notes'] : null,
        'by' => ['user_id' => (int)$p['user_id'], 'name' => (string)$p['person_name']],
        'lines' => $lines[(int)$p['id']] ?? [],
        'files' => $files[(int)$p['id']] ?? [],
        'can_change' => (string)$p['created_day'] === $today,
        'created_at' => (string)$p['created_at'],
        'updated_at' => (string)$p['updated_at'],
        'updated_by' => $p['updated_by_name'] !== null ? (string)$p['updated_by_name'] : null,
    ], $rows);
}

/** One purchase row as the asker may see it: the buyer only his own, the office any. */
function pur_row_for(PDO $conn, array $who, int $id, bool $lock = false): ?array
{
    $stmt = $conn->prepare(PUR_SELECT . ' WHERE p.id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p || (empty($who['office']) && (int)$p['user_id'] !== (int)$who['user_id'])) {
        return null;
    }
    return $p;
}

function pur_get(PDO $conn, array $who, int $id, ?string $today = null): ?array
{
    $p = pur_row_for($conn, $who, $id);
    return $p ? pur_shape_many($conn, [$p], $today)[0] : null;
}

/** The buyer's own, newest first, thirty at a time ($before = the last id he has). */
function pur_list_mine(PDO $conn, array $who, int $before = 0, ?string $today = null): array
{
    $stmt = $conn->prepare(PUR_SELECT . ' WHERE p.user_id = ? AND (? = 0 OR p.id < ?) ORDER BY p.id DESC LIMIT 30');
    $stmt->execute([(int)$who['user_id'], $before, $before]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return ['purchases' => pur_shape_many($conn, $rows, $today), 'more' => count($rows) === 30];
}

/**
 * The office list: purchases bought between two days, with their total.
 * $f: from, to, supplier (id), person (user id), kind, status, q.
 */
function pur_list_all(PDO $conn, array $f, ?string $today = null): array
{
    $today = $today ?? pur_today();
    $from = pur_valid_date($f['from'] ?? null) ? $f['from'] : substr($today, 0, 7) . '-01';
    $to = pur_valid_date($f['to'] ?? null) ? $f['to'] : $today;
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $where = ['p.bought_on BETWEEN ? AND ?'];
    $params = [$from, $to];
    if ((int)($f['supplier'] ?? 0) > 0) {
        $where[] = 'p.supplier_id = ?';
        $params[] = (int)$f['supplier'];
    }
    if ((int)($f['person'] ?? 0) > 0) {
        $where[] = 'p.user_id = ?';
        $params[] = (int)$f['person'];
    }
    if (isset(PUR_KINDS[$f['kind'] ?? ''])) {
        $where[] = 'p.kind = ?';
        $params[] = $f['kind'];
    }
    if (isset(PUR_STATUSES[$f['status'] ?? ''])) {
        $where[] = 'p.status = ?';
        $params[] = $f['status'];
    }
    $q = pur_text($f['q'] ?? '', PUR_NAME_MAX);
    if ($q !== '') {
        $where[] = "(s.name LIKE CONCAT('%', ?, '%') OR p.person_name LIKE CONCAT('%', ?, '%')
            OR EXISTS (SELECT 1 FROM pur_purchase_lines l WHERE l.purchase_id = p.id AND l.name LIKE CONCAT('%', ?, '%')))";
        array_push($params, $q, $q, $q);
    }
    $stmt = $conn->prepare(PUR_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.bought_on DESC, p.id DESC LIMIT 1000');
    $stmt->execute($params);
    $purchases = pur_shape_many($conn, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $today);

    $people = $conn->query('SELECT user_id AS id, MAX(person_name) AS name FROM pur_purchases GROUP BY user_id ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $suppliers = $conn->query('SELECT id, name FROM pur_suppliers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return [
        'from' => $from,
        'to' => $to,
        'purchases' => $purchases,
        'total' => pur_money(array_sum(array_column($purchases, 'total'))),
        'people' => $people,
        'suppliers' => $suppliers,
    ];
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

/** The lines as typed: name, quantity, price for one. Checked; the amounts worked out here. */
function pur_read_lines($raw): array
{
    if (!is_array($raw) || $raw === []) {
        throw new PurError('Add at least one item.');
    }
    if (count($raw) > PUR_LINES_MAX) {
        throw new PurError('At most ' . PUR_LINES_MAX . ' items in one purchase.');
    }
    $out = [];
    foreach (array_values($raw) as $i => $l) {
        $l = is_array($l) ? $l : [];
        $name = pur_text($l['name'] ?? '', PUR_LINE_MAX);
        if ($name === '') {
            throw new PurError('Item ' . ($i + 1) . ': write what was bought.');
        }
        $qty = is_numeric($l['qty'] ?? null) ? (float)$l['qty'] : 0.0;
        if ($qty <= 0 || $qty > PUR_QTY_MAX) {
            throw new PurError($name . ': the quantity must be more than 0.');
        }
        $price = is_numeric($l['price'] ?? null) ? (float)$l['price'] : -1.0;
        if ($price < 0 || $price > PUR_PRICE_MAX) {
            throw new PurError($name . ': write the price for one.');
        }
        $unit = pur_text($l['unit'] ?? '', PUR_UNIT_MAX);
        $out[] = [
            'name' => $name,
            'qty' => round($qty, 3),
            'unit' => $unit !== '' ? $unit : null,
            'price' => pur_money($price),
            'amount' => pur_money($qty * $price),
        ];
    }
    return $out;
}

/** The office always; the buyer his own, on the day he made it. */
function pur_may_change(array $who, array $p, string $today): void
{
    if (!empty($who['office'])) {
        return;
    }
    if ((int)$p['user_id'] !== (int)$who['user_id']) {
        throw new PurError('That purchase is not there any more.', 404, 'not_found');
    }
    if ((string)$p['created_day'] !== $today) {
        throw new PurError('This purchase is from an earlier day. Ask the office to change it.', 409, 'locked');
    }
}

/**
 * Make a purchase ($id 0), or change one. $input: kind, supplier | supplier_id,
 * lines, notes, client_ref, bought_on (office only). $files: the bill photos sent
 * with a new one — a cash purchase from the phone needs at least one.
 * client_ref is the phone's own id for this save, so a double tap or a resend
 * makes one purchase.
 *
 * @param array[] $files each ['tmp' => path, 'uploaded' => bool]
 * @return array|null the purchase, or null when it is not the asker's to see
 */
function pur_save(PDO $conn, array $who, int $id, array $input, array $files = [], ?string $today = null): ?array
{
    $today = $today ?? pur_today();
    $office = !empty($who['office']);
    $kind = (string)($input['kind'] ?? '');
    if (!isset(PUR_KINDS[$kind])) {
        throw new PurError('Say if it was paid in cash at the shop, or ordered from a supplier.');
    }
    $lines = pur_read_lines($input['lines'] ?? null);
    $notes = pur_text($input['notes'] ?? '', PUR_NOTES_MAX);
    $total = pur_money(array_sum(array_column($lines, 'amount')));
    $clientRef = pur_text($input['client_ref'] ?? '', 64);
    $boughtOn = null;
    if ($office && ($input['bought_on'] ?? '') !== '') {
        if (!pur_valid_date($input['bought_on']) || $input['bought_on'] > $today) {
            throw new PurError('The date bought is not a real day, or it is in the future.');
        }
        $boughtOn = (string)$input['bought_on'];
    }
    $photos = pur_check_files($files);

    if ($id === 0 && $clientRef !== '' && !$office) {
        $stmt = $conn->prepare('SELECT id FROM pur_purchases WHERE user_id = ? AND client_ref = ?');
        $stmt->execute([(int)$who['user_id'], $clientRef]);
        $seen = (int)$stmt->fetchColumn();
        if ($seen > 0) {
            return pur_get($conn, $who, $seen, $today);
        }
    }
    if ($id === 0 && $office) {
        throw new PurError('The office does not make purchases here; the buyer records them on the phone.', 403, 'forbidden');
    }
    if ($id === 0 && $kind === 'cash' && !$photos) {
        throw new PurError('Take a photo of the bill.');
    }

    $conn->beginTransaction();
    try {
        $p = $id > 0 ? pur_row_for($conn, $who, $id, true) : null;
        if ($id > 0 && !$p) {
            $conn->rollBack();
            return null;
        }
        if ($p) {
            pur_may_change($who, $p, $today);
        }
        $supplierId = pur_supplier_for($conn, $input);
        $now = pur_now();
        if ($p) {
            // Changing cash to an order (or back) starts its status over; otherwise it stays.
            $status = $kind === $p['kind'] ? (string)$p['status'] : ($kind === 'cash' ? 'paid' : 'ordered');
            $conn->prepare("UPDATE pur_purchases SET kind = ?, status = ?, supplier_id = ?, notes = ?, total = ?,
                    bought_on = ?, received_on = IF(? = 'received', received_on, NULL),
                    updated_at = ?, updated_by = ?, updated_by_name = ?
                WHERE id = ?")
                ->execute([$kind, $status, $supplierId, $notes !== '' ? $notes : null, $total,
                    $boughtOn ?? $p['bought_on'], $status, $now, (int)$who['user_id'] ?: null, (string)$who['name'], (int)$p['id']]);
            $conn->prepare('DELETE FROM pur_purchase_lines WHERE purchase_id = ?')->execute([(int)$p['id']]);
            $pid = (int)$p['id'];
        } else {
            $conn->prepare("INSERT INTO pur_purchases (user_id, person_name, kind, status, supplier_id, bought_on, created_day,
                    total, notes, client_ref, created_at, updated_at, updated_by, updated_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([(int)$who['user_id'], (string)$who['name'], $kind, $kind === 'cash' ? 'paid' : 'ordered', $supplierId,
                    $today, $today, $total, $notes !== '' ? $notes : null, $clientRef !== '' ? $clientRef : null,
                    $now, $now, (int)$who['user_id'], (string)$who['name']]);
            $pid = (int)$conn->lastInsertId();
        }
        $ins = $conn->prepare('INSERT INTO pur_purchase_lines (purchase_id, position, name, qty, unit, price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($lines as $pos => $l) {
            $ins->execute([$pid, $pos, $l['name'], $l['qty'], $l['unit'], $l['price'], $l['amount']]);
        }
        pur_store_files($conn, $pid, 'bill', $photos);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    return pur_get($conn, $who, $pid, $today);
}

/** The goods of an order came: received today, with the photo of the delivery note. */
function pur_mark_received(PDO $conn, array $who, int $id, array $files = [], ?string $today = null): ?array
{
    $today = $today ?? pur_today();
    $p = pur_row_for($conn, $who, $id);
    if (!$p) {
        return null;
    }
    if ($p['kind'] !== 'order') {
        throw new PurError('Only an order is received. A cash purchase is done when it is paid.');
    }
    if ($p['status'] === 'received') {
        throw new PurError('This order is already marked received.', 409, 'conflict');
    }
    $photos = pur_check_files($files);
    if (empty($who['office']) && !$photos) {
        throw new PurError('Take a photo of the delivery note or the invoice.');
    }
    $conn->beginTransaction();
    try {
        $conn->prepare("UPDATE pur_purchases SET status = 'received', received_on = ?, updated_at = ?, updated_by = ?, updated_by_name = ?
            WHERE id = ? AND status = 'ordered'")
            ->execute([$today, pur_now(), (int)$who['user_id'] ?: null, (string)$who['name'], (int)$p['id']]);
        pur_store_files($conn, (int)$p['id'], 'delivery', $photos);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    return pur_get($conn, $who, (int)$p['id'], $today);
}

/** Back to waiting — the office only, for a "received" tapped by mistake. */
function pur_mark_not_received(PDO $conn, array $who, int $id, ?string $today = null): ?array
{
    if (empty($who['office'])) {
        throw new PurError('Ask the office to change it.', 403, 'forbidden');
    }
    $p = pur_row_for($conn, $who, $id);
    if (!$p) {
        return null;
    }
    if ($p['status'] !== 'received') {
        throw new PurError('This order is not marked received.', 409, 'conflict');
    }
    $conn->prepare("UPDATE pur_purchases SET status = 'ordered', received_on = NULL, updated_at = ?, updated_by = ?, updated_by_name = ? WHERE id = ?")
        ->execute([pur_now(), (int)$who['user_id'] ?: null, (string)$who['name'], (int)$p['id']]);
    return pur_get($conn, $who, (int)$p['id'], $today);
}

function pur_delete(PDO $conn, array $who, int $id, ?string $today = null): ?array
{
    $today = $today ?? pur_today();
    $p = pur_row_for($conn, $who, $id);
    if (!$p) {
        return null;
    }
    pur_may_change($who, $p, $today);
    $stmt = $conn->prepare('SELECT file_path FROM pur_purchase_files WHERE purchase_id = ?');
    $stmt->execute([(int)$p['id']]);
    $paths = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $conn->prepare('DELETE FROM pur_purchases WHERE id = ?')->execute([(int)$p['id']]);
    foreach ($paths as $rel) {
        pur_drop_file((string)$rel);
    }
    @rmdir(pur_root() . '/uploads/purchases/' . (int)$p['id']);
    return ['deleted' => (int)$p['id']];
}

// ---------------------------------------------------------------------------
// Photos
// ---------------------------------------------------------------------------

function pur_root(): string
{
    return dirname(__DIR__, 3);
}

/** What a file really is, by its first bytes — never by the name or type the phone says. */
function pur_sniff(string $path): ?string
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

/**
 * $_FILES[$field] (one or many) as a list of ['tmp' => path, 'uploaded' => true].
 * A file the server refused (too big for PHP) is a PurError, not silently dropped.
 */
function pur_uploaded_files(string $field): array
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
            throw new PurError('That photo is too big to send.', 413, 'too_big');
        }
        if ($code !== UPLOAD_ERR_OK) {
            throw new PurError('The photo did not come through. Try again.');
        }
        $out[] = ['tmp' => (string)$path, 'uploaded' => true];
    }
    return $out;
}

/** @return array[] each ['tmp', 'uploaded', 'ext'] — checked, nothing kept yet */
function pur_check_files(array $files): array
{
    if (count($files) > 6) {
        throw new PurError('At most 6 photos at a time.');
    }
    $out = [];
    foreach ($files as $f) {
        $size = @filesize((string)$f['tmp']);
        if ($size === false || $size === 0) {
            continue;
        }
        if ($size > PUR_FILE_BYTES) {
            throw new PurError('That photo is too big to send.', 413, 'too_big');
        }
        $ext = pur_sniff((string)$f['tmp']);
        if ($ext === null) {
            throw new PurError('A photo must be a JPG, PNG or WEBP picture (or a PDF from the office).');
        }
        $out[] = $f + ['ext' => $ext];
    }
    return $out;
}

function pur_store_files(PDO $conn, int $purchaseId, string $kind, array $photos): void
{
    if (!$photos) {
        return;
    }
    $stmt = $conn->prepare('SELECT COUNT(*) FROM pur_purchase_files WHERE purchase_id = ?');
    $stmt->execute([$purchaseId]);
    if ((int)$stmt->fetchColumn() + count($photos) > PUR_FILES_MAX) {
        throw new PurError('At most ' . PUR_FILES_MAX . ' photos on one purchase.');
    }
    $root = pur_root();
    $base = $root . '/uploads/purchases';
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }
    // Not web-readable: the app and the office read them through PHP.
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $dirRel = 'uploads/purchases/' . $purchaseId;
    if (!is_dir($root . '/' . $dirRel) && !@mkdir($root . '/' . $dirRel, 0755, true)) {
        throw new PurError('The photo could not be kept. Try again.', 500, 'server');
    }
    $ins = $conn->prepare('INSERT INTO pur_purchase_files (purchase_id, kind, file_path, created_at) VALUES (?, ?, ?, ?)');
    foreach ($photos as $ph) {
        $rel = $dirRel . '/' . $kind . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ph['ext'];
        $ok = !empty($ph['uploaded']) ? move_uploaded_file($ph['tmp'], $root . '/' . $rel) : copy($ph['tmp'], $root . '/' . $rel);
        if (!$ok) {
            throw new PurError('The photo could not be kept. Try again.', 500, 'server');
        }
        $ins->execute([$purchaseId, $kind, $rel, pur_now()]);
    }
}

function pur_drop_file(string $rel): void
{
    if (strpos($rel, 'uploads/purchases/') === 0 && strpos($rel, '..') === false) {
        @unlink(pur_root() . '/' . $rel);
    }
}

/** On the day it arrived the buyer may still add or swap the delivery photo of an older order. */
function pur_delivery_day(array $who, array $p, string $kind, string $today): bool
{
    return $kind === 'delivery' && empty($who['office']) && (int)$p['user_id'] === (int)$who['user_id']
        && (string)$p['received_on'] === $today;
}

function pur_add_files(PDO $conn, array $who, int $id, string $kind, array $files, ?string $today = null): ?array
{
    $today = $today ?? pur_today();
    if (!isset(PUR_FILE_KINDS[$kind])) {
        throw new PurError('Say if the photo is the bill or the delivery note.');
    }
    $p = pur_row_for($conn, $who, $id);
    if (!$p) {
        return null;
    }
    if (!pur_delivery_day($who, $p, $kind, $today)) {
        pur_may_change($who, $p, $today);
    }
    $photos = pur_check_files($files);
    if (!$photos) {
        throw new PurError('No photo came through.');
    }
    pur_store_files($conn, (int)$p['id'], $kind, $photos);
    return pur_get($conn, $who, (int)$p['id'], $today);
}

function pur_remove_file(PDO $conn, array $who, int $id, int $fileId, ?string $today = null): ?array
{
    $today = $today ?? pur_today();
    $p = pur_row_for($conn, $who, $id);
    if (!$p) {
        return null;
    }
    $stmt = $conn->prepare('SELECT id, kind, file_path FROM pur_purchase_files WHERE id = ? AND purchase_id = ?');
    $stmt->execute([$fileId, (int)$p['id']]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$f) {
        return null;
    }
    if (!pur_delivery_day($who, $p, (string)$f['kind'], $today)) {
        pur_may_change($who, $p, $today);
    }
    if (empty($who['office'])) {
        // The buyer cannot leave a cash purchase without its bill, or a received order without its note.
        $stmt = $conn->prepare('SELECT COUNT(*) FROM pur_purchase_files WHERE purchase_id = ? AND kind = ? AND id <> ?');
        $stmt->execute([(int)$p['id'], $f['kind'], (int)$f['id']]);
        $left = (int)$stmt->fetchColumn();
        if ($left === 0 && (($f['kind'] === 'bill' && $p['kind'] === 'cash') || ($f['kind'] === 'delivery' && $p['status'] === 'received'))) {
            throw new PurError('Take the new photo first, then delete this one.');
        }
    }
    $conn->prepare('DELETE FROM pur_purchase_files WHERE id = ?')->execute([(int)$f['id']]);
    pur_drop_file((string)$f['file_path']);
    return pur_get($conn, $who, (int)$p['id'], $today);
}

/** The file on disk and its type, for one photo of a purchase the asker may see; or null. */
function pur_file(PDO $conn, array $who, int $id, int $fileId): ?array
{
    $p = pur_row_for($conn, $who, $id);
    if (!$p) {
        return null;
    }
    $stmt = $conn->prepare('SELECT file_path FROM pur_purchase_files WHERE id = ? AND purchase_id = ?');
    $stmt->execute([$fileId, (int)$p['id']]);
    $rel = $stmt->fetchColumn();
    if ($rel === false) {
        return null;
    }
    $base = realpath(pur_root() . '/uploads/purchases');
    $abs = realpath(pur_root() . '/' . ltrim((string)$rel, '/'));
    if ($base === false || $abs === false || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
        return null;
    }
    // Content-Type from the extension (whitelist), never from the file's bytes.
    $type = PUR_FILE_TYPES[strtolower(pathinfo($abs, PATHINFO_EXTENSION))] ?? null;
    return $type === null ? null : ['path' => $abs, 'mime' => $type];
}

/** Send one file and stop. */
function pur_send_file(array $f): void
{
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($f['path']));
    header('X-Content-Type-Options: nosniff');
    if ($f['mime'] !== 'application/pdf') {
        // A PDF needs the browser's own viewer, which this header would stop.
        header("Content-Security-Policy: default-src 'none'");
    }
    header('Cache-Control: private, max-age=3600');
    readfile($f['path']);
    exit;
}

/** "2" for 2.000, "1.5" for 1.500. */
function pur_qty($n): string
{
    return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.') ?: '0';
}
