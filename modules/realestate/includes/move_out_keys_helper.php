<?php
/**
 * Move-Out Key Return (migrations/re_move_out_keys.sql).
 *
 * The keys and access cards handed over at move-in (re_move_in_keys) are
 * copied into the move-out once (re_move_out_keys), so staff can tick off
 * what the tenant gives back. The move-in is the lease's own, or for a
 * renewal the first lease's (re_move_in_carried_over()). Without key rows at
 * move-in the usual list is offered with nothing issued.
 *
 * Confirming the key return needs a returned quantity on every row and sets
 * re_move_outs.keys_returned (+ _by / _at).
 */

require_once __DIR__ . '/move_in_stages_helper.php';
require_once __DIR__ . '/move_in_chain_helper.php';

function rmok_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW TABLES LIKE 're_move_out_keys'")->fetchColumn();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * The move-in whose keys the tenant holds: ['id', 'move_in_date', 'lease_number', 'carried' => bool] or null.
 */
function rmok_source_move_in(PDO $conn, int $companyId, int $leaseId): ?array
{
    $st = $conn->prepare("
        SELECT mi.id, mi.move_in_date, l.lease_number
        FROM re_move_ins mi JOIN re_leases l ON l.id = mi.lease_id
        WHERE mi.lease_id = ? AND mi.company_id = ? AND mi.status <> 'cancelled'
        ORDER BY (mi.status = 'completed') DESC, mi.id DESC LIMIT 1
    ");
    $st->execute([$leaseId, $companyId]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        return $row + ['carried' => false];
    }
    if ($c = re_move_in_carried_over($conn, $companyId, $leaseId)) {
        return ['id' => (int)$c['move_in_id'], 'move_in_date' => $c['move_in_date'], 'lease_number' => $c['lease_number'], 'carried' => true];
    }
    return null;
}

/** Keys handed over at that move-in (quantity > 0). */
function rmok_move_in_keys(PDO $conn, int $moveInId): array
{
    if (!rms_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("SELECT key_label, quantity, card_numbers FROM re_move_in_keys WHERE move_in_id = ? AND quantity > 0 ORDER BY display_order, id");
    $st->execute([$moveInId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Copy the move-in keys into the move-out, once. */
function rmok_create_for_move_out(PDO $conn, int $companyId, int $moveOutId, int $leaseId, ?int $userId): void
{
    $has = $conn->prepare("SELECT COUNT(*) FROM re_move_out_keys WHERE move_out_id = ?");
    $has->execute([$moveOutId]);
    if ((int)$has->fetchColumn() > 0) {
        return;
    }
    $src = rmok_source_move_in($conn, $companyId, $leaseId);
    $keys = $src ? rmok_move_in_keys($conn, (int)$src['id']) : [];
    $ins = $conn->prepare("INSERT INTO re_move_out_keys (company_id, move_out_id, key_label, issued_qty, card_numbers, display_order, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($keys) {
        foreach ($keys as $o => $k) {
            $ins->execute([$companyId, $moveOutId, $k['key_label'], (int)$k['quantity'], $k['card_numbers'] ?: null, $o + 1, $userId]);
        }
    } else {
        foreach (RMS_DEFAULT_KEYS as $o => $label) {
            $ins->execute([$companyId, $moveOutId, $label, null, null, $o + 1, $userId]);
        }
    }
}

function rmok_keys(PDO $conn, int $moveOutId): array
{
    $st = $conn->prepare("SELECT * FROM re_move_out_keys WHERE move_out_id = ? ORDER BY display_order, id");
    $st->execute([$moveOutId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * ['rows', 'issued', 'returned', 'pending' (rows without a returned qty),
 *  'missing' => [ ['label', 'qty'], ... ] where fewer came back than were issued]
 */
function rmok_summary(array $keys): array
{
    $s = ['rows' => count($keys), 'issued' => 0, 'returned' => 0, 'pending' => 0, 'missing' => []];
    foreach ($keys as $k) {
        $s['issued'] += (int)$k['issued_qty'];
        if ($k['returned_qty'] === null) {
            $s['pending']++;
            continue;
        }
        $s['returned'] += (int)$k['returned_qty'];
        if ($k['issued_qty'] !== null && (int)$k['returned_qty'] < (int)$k['issued_qty']) {
            $s['missing'][] = ['label' => $k['key_label'], 'qty' => (int)$k['issued_qty'] - (int)$k['returned_qty']];
        }
    }
    return $s;
}

/** "1 x Access Card, 2 x Main Door Key" */
function rmok_missing_text(array $missing): string
{
    return implode(', ', array_map(static fn($m) => $m['qty'] . ' × ' . $m['label'], $missing));
}
