<?php
/**
 * Move-in carried over to renewal leases.
 *
 * A tenant who renews the same unit does not move in again: the move-in done
 * for the first lease covers every later lease of the same tenant and unit,
 * as long as the tenant did not move out in between.
 *
 * Leases are matched on tenant + unit (and start date), not only on
 * re_leases.parent_lease_id, because older renewals were saved without the link.
 * A renewal into a different unit, or a tenant who moved out and came back,
 * needs a new move-in.
 */

/** Setting: leases starting before this date are existing tenancies (Mark as Moved In only). */
require_once __DIR__ . '/lease_lifecycle_guard.php';

const RE_MOVE_IN_FROM_KEY = 're_move_in_process_from';
const RE_MOVE_IN_FROM_DEFAULT = '2026-10-22';

/** Go-live date of the move-in process (Y-m-d), from app_settings. */
function re_move_in_process_from(PDO $conn): string
{
    static $from = null;
    if ($from === null) {
        $from = RE_MOVE_IN_FROM_DEFAULT;
        try {
            $st = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
            $st->execute([RE_MOVE_IN_FROM_KEY]);
            $v = (string)$st->fetchColumn();
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $from = $v;
            }
        } catch (Throwable $e) {
        }
    }
    return $from;
}

function re_move_in_set_process_from(PDO $conn, string $date): void
{
    $up = $conn->prepare("UPDATE app_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
    $up->execute([$date, RE_MOVE_IN_FROM_KEY]);
    if ($up->rowCount() === 0) {
        $chk = $conn->prepare("SELECT COUNT(*) FROM app_settings WHERE setting_key = ?");
        $chk->execute([RE_MOVE_IN_FROM_KEY]);
        if ((int)$chk->fetchColumn() === 0) {
            $conn->prepare("INSERT INTO app_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, NOW())")
                ->execute([RE_MOVE_IN_FROM_KEY, $date, 'Real estate: leases starting before this date use Mark as Moved In instead of the Move-In process']);
        }
    }
}

/** Existing tenancy: the lease started before the move-in process went live. */
function re_lease_is_old(PDO $conn, array $lease): bool
{
    $start = (string)($lease['start_date'] ?? '');
    return $start !== '' && $start < re_move_in_process_from($conn);
}

/**
 * Per lease: the first lease of the same tenant's continuous stay in the unit
 * (same tenant + unit, no move-out in between). Returns [lease_id => [first lease row]]
 * only for leases that are not themselves the first one.
 */
function re_move_in_first_lease_map(PDO $conn, int $companyId): array
{
    static $cache = [];
    if (isset($cache[$companyId])) {
        return $cache[$companyId];
    }
    $st = $conn->prepare("
        SELECT l.id, l.tenant_id, l.unit_id, l.start_date, l.move_in_date, l.lease_number, l.status,
               EXISTS(SELECT 1 FROM re_move_outs mo WHERE mo.lease_id = l.id AND mo.status = 'completed') AS moved_out
        FROM re_leases l
        WHERE l.company_id = ? AND l.status <> 'draft' AND " . re_lease_not_deleted_sql($conn, 'l') . "
        ORDER BY l.tenant_id, l.unit_id, l.start_date, l.id
    ");
    $st->execute([$companyId]);
    $map = [];
    $first = null;
    $group = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
        $key = $l['tenant_id'] . '|' . $l['unit_id'];
        if ($key !== $group) {
            $group = $key;
            $first = null;
        }
        if ($first === null) {
            $first = $l;
        } else {
            $map[(int)$l['id']] = $first;
        }
        if ((int)$l['moved_out'] === 1) {
            $first = null;
        }
    }
    return $cache[$companyId] = $map;
}

/** First lease of the tenancy when $leaseId is a renewal of it, else null. */
function re_move_in_first_lease(PDO $conn, int $companyId, int $leaseId): ?array
{
    return re_move_in_first_lease_map($conn, $companyId)[$leaseId] ?? null;
}

/**
 * Covering move-in for every lease of the company that has none of its own.
 * Returns [lease_id => ['move_in_id', 'move_in_date', 'lease_id', 'lease_number']]
 * where lease_id / lease_number are the earlier lease that holds the move-in.
 */
function re_move_in_carried_over_map(PDO $conn, int $companyId): array
{
    static $cache = [];
    if (isset($cache[$companyId])) {
        return $cache[$companyId];
    }
    $st = $conn->prepare("
        SELECT l.id, l.tenant_id, l.unit_id, l.start_date, l.lease_number,
               (SELECT mi.id FROM re_move_ins mi WHERE mi.lease_id = l.id AND mi.status = 'completed' ORDER BY mi.id DESC LIMIT 1) AS move_in_id,
               (SELECT mi.move_in_date FROM re_move_ins mi WHERE mi.lease_id = l.id AND mi.status = 'completed' ORDER BY mi.id DESC LIMIT 1) AS move_in_date,
               EXISTS(SELECT 1 FROM re_move_outs mo WHERE mo.lease_id = l.id AND mo.status = 'completed') AS moved_out
        FROM re_leases l
        WHERE l.company_id = ? AND l.status <> 'draft' AND " . re_lease_not_deleted_sql($conn, 'l') . "
        ORDER BY l.tenant_id, l.unit_id, l.start_date, l.id
    ");
    $st->execute([$companyId]);

    $map = [];
    $covering = null;      // move-in still in force for this tenant + unit
    $group = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
        $key = $l['tenant_id'] . '|' . $l['unit_id'];
        if ($key !== $group) {
            $group = $key;
            $covering = null;
        }
        if ($l['move_in_id']) {
            $covering = ['move_in_id' => (int)$l['move_in_id'], 'move_in_date' => $l['move_in_date'], 'lease_id' => (int)$l['id'], 'lease_number' => $l['lease_number']];
        } elseif ($covering) {
            $map[(int)$l['id']] = $covering;
        }
        if ((int)$l['moved_out'] === 1) {
            $covering = null; // tenant left: a later lease needs its own move-in
        }
    }
    return $cache[$companyId] = $map;
}

/** Covering move-in from an earlier lease of the same tenant and unit, or null. */
function re_move_in_carried_over(PDO $conn, int $companyId, int $leaseId): ?array
{
    return re_move_in_carried_over_map($conn, $companyId)[$leaseId] ?? null;
}

/** Label for pickers and messages, e.g. "Renewal: moved in under lease L-0001". */
function re_move_in_carried_over_label(array $c): string
{
    return 'Renewal: moved in under lease ' . ($c['lease_number'] ?: ('#' . $c['lease_id']));
}

/**
 * Why a new Move-In cannot be started for this lease (the lease page offers
 * Mark as Moved In instead), or null when it can.
 */
function re_move_in_add_block_reason(PDO $conn, int $companyId, int $leaseId): ?string
{
    if ($c = re_move_in_carried_over($conn, $companyId, $leaseId)) {
        return 'No move-in is needed: this lease renews the same unit for the same tenant, who moved in under lease '
            . ($c['lease_number'] ?: ('#' . $c['lease_id'])) . ' (Move-In #' . $c['move_in_id'] . ').';
    }
    $st = $conn->prepare("SELECT id, lease_number, start_date, status, parent_lease_id, is_renewal_lease FROM re_leases WHERE id = ? AND company_id = ?");
    $st->execute([$leaseId, $companyId]);
    $lease = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lease) {
        return null;
    }
    if ($newer = re_move_out_superseded_by($conn, $companyId, $leaseId)) {
        return 'This lease was renewed by lease ' . ($newer['lease_number'] ?: ('#' . $newer['id']))
            . '. The move-in of this tenancy is recorded from that lease (Mark as Moved In), not here.';
    }
    if (re_lease_is_draft_renewal($lease)) {
        return 'This lease is a renewal that is still a draft. A renewal does not need a new move-in: activate it, then use Mark as Moved In on it if the tenancy has none yet.';
    }
    if ($first = re_move_in_first_lease($conn, $companyId, $leaseId)) {
        return 'This lease renews the tenancy that started with lease ' . ($first['lease_number'] ?: ('#' . $first['id']))
            . '. A renewal does not need its own move-in: use Mark as Moved In on this lease to record the move-in on that first lease.';
    }
    if ($lease['status'] !== 'draft' && re_lease_is_old($conn, $lease)) {
        return 'This lease started before the move-in process went live (' . date('d M Y', strtotime(re_move_in_process_from($conn)))
            . '). Use Mark as Moved In on the lease instead of a new move-in.';
    }
    return null;
}

/**
 * Draft lease that renews another lease: linked by parent_lease_id, or flagged
 * is_renewal_lease. It is part of the tenancy it renews (the tenant does not
 * move in again), although drafts are left out of the tenancy maps above.
 */
function re_lease_is_draft_renewal(array $lease): bool
{
    return ($lease['status'] ?? '') === 'draft' && (!empty($lease['parent_lease_id']) || !empty($lease['is_renewal_lease']));
}

/**
 * A later lease of the same tenancy (same tenant and unit, no move-out in
 * between) that has replaced this one, e.g. its renewal, also while that
 * renewal is still a draft; null when this is the current lease. Move-in and
 * move-out belong on that newest lease: the older ones show no move-in or
 * move-out actions.
 */
function re_move_out_superseded_by(PDO $conn, int $companyId, int $leaseId): ?array
{
    $map = re_move_in_first_lease_map($conn, $companyId);
    $headOf = static fn(int $id) => isset($map[$id]) ? (int)$map[$id]['id'] : $id;
    $head = $headOf($leaseId);
    $st = $conn->prepare("
        SELECT l.id, l.lease_number, l.start_date, l.status, l.tenant_id, l.unit_id, l.parent_lease_id, l.is_renewal_lease
        FROM re_leases l
        JOIN re_leases me ON me.id = ? AND me.company_id = l.company_id
        WHERE l.company_id = ? AND l.id <> me.id AND " . re_lease_not_deleted_sql($conn, 'l') . "
          AND l.tenant_id = me.tenant_id AND l.unit_id = me.unit_id
          AND (l.start_date > me.start_date OR (l.start_date = me.start_date AND l.id > me.id))
          AND (l.status <> 'draft' OR l.parent_lease_id IS NOT NULL OR l.is_renewal_lease = 1)
        ORDER BY l.start_date DESC, l.id DESC
    ");
    $st->execute([$leaseId, $companyId]);
    $parentOf = $conn->prepare("SELECT parent_lease_id, status FROM re_leases WHERE id = ? AND company_id = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $later) {
        if ($later['status'] !== 'draft') {
            if ($headOf((int)$later['id']) === $head) {
                return $later; // the newest lease of this tenancy
            }
            continue;
        }
        // Draft renewal: follow its renewal link back into this tenancy
        $p = (int)($later['parent_lease_id'] ?? 0);
        for ($i = 0; $p > 0 && $i < 20; $i++) {
            if ($p === $leaseId || $headOf($p) === $head) {
                return $later;
            }
            $parentOf->execute([$p, $companyId]);
            $row = $parentOf->fetch(PDO::FETCH_ASSOC);
            $p = $row ? (int)$row['parent_lease_id'] : 0;
        }
        if (empty($later['parent_lease_id']) && !empty($later['is_renewal_lease'])) {
            return $later; // flagged renewal of the same tenant and unit, saved without the link
        }
    }
    return null;
}
