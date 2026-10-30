<?php
/**
 * "Mark as Moved In" — record a completed move-in for a tenant who moved in
 * before the Move-In module was used. Skips checklist, inspection and meter
 * readings; the record is flagged re_move_ins.is_historical = 1.
 *
 * Requires migrations/add_re_move_ins_is_historical.sql.
 */

require_once __DIR__ . '/../../../includes/AuditService.php';

function re_move_in_historical_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $col = $conn->query("SHOW COLUMNS FROM re_move_ins LIKE 'is_historical'");
        $ready = (bool)$col->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Latest move-in for the lease that is not cancelled, or null.
 */
function re_move_in_open_record(PDO $conn, int $leaseId): ?array
{
    $stmt = $conn->prepare("
        SELECT id, status FROM re_move_ins
        WHERE lease_id = ? AND status != 'cancelled'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$leaseId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Default date offered in the dialog: lease move-in date, else start date.
 */
function re_move_in_historical_default_date(array $lease): string
{
    return (string)($lease['move_in_date'] ?: ($lease['start_date'] ?: date('Y-m-d')));
}

/**
 * @return array{ok:bool, error?:string, move_in_id?:int}
 */
function re_move_in_mark_historical(PDO $conn, int $companyId, int $userId, int $leaseId, string $moveInDate, string $reason): array
{
    if (!re_move_in_historical_ready($conn)) {
        return ['ok' => false, 'error' => 'Run migration migrations/add_re_move_ins_is_historical.sql first.'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Please enter a reason.'];
    }
    $d = DateTime::createFromFormat('Y-m-d', $moveInDate);
    if (!$d || $d->format('Y-m-d') !== $moveInDate) {
        return ['ok' => false, 'error' => 'Please enter a valid move-in date.'];
    }
    if ($moveInDate > date('Y-m-d')) {
        return ['ok' => false, 'error' => 'Move-in date cannot be in the future.'];
    }

    require_once __DIR__ . '/lease_lifecycle_guard.php';
    $stmt = $conn->prepare("SELECT id, company_id, lease_number, unit_id, status, move_in_date, move_in_completed FROM re_leases l WHERE l.id = ? AND l.company_id = ? AND " . re_lease_not_deleted_sql($conn, 'l'));
    $stmt->execute([$leaseId, $companyId]);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lease) {
        return ['ok' => false, 'error' => 'Lease not found.'];
    }
    if ($lease['status'] === 'draft') {
        return ['ok' => false, 'error' => 'A draft lease cannot be marked as moved in. Activate the lease or use the normal Move-In.'];
    }

    try {
        $conn->beginTransaction();

        // Lock the lease row so two clicks cannot create two records
        $conn->prepare("SELECT id FROM re_leases WHERE id = ? FOR UPDATE")->execute([$leaseId]);
        if (re_move_in_open_record($conn, $leaseId)) {
            $conn->rollBack();
            return ['ok' => false, 'error' => 'This lease already has a move-in record. Complete or cancel it from the Move-In page.'];
        }

        $note = 'Marked as moved in (historical entry, no checklist): ' . $reason;
        $conn->prepare("
            INSERT INTO re_move_ins
                (company_id, lease_id, move_in_date, status, is_historical, approved_by, approved_at, notes, created_by)
            VALUES (?, ?, ?, 'completed', 1, ?, NOW(), ?, ?)
        ")->execute([$companyId, $leaseId, $moveInDate, $userId ?: null, $note, $userId ?: null]);
        $moveInId = (int)$conn->lastInsertId();

        $conn->prepare("
            UPDATE re_leases
            SET move_in_completed = 1,
                move_in_date = COALESCE(move_in_date, ?)
            WHERE id = ?
        ")->execute([$moveInDate, $leaseId]);

        // Only a running lease occupies the unit now; an expired/terminated
        // lease's unit may since be vacant or let to someone else.
        $unitUpdated = false;
        if ($lease['status'] === 'active') {
            re_lease_units_set_occupied($conn, $companyId, $leaseId);
            $unitUpdated = true;
        }

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not mark as moved in: ' . $e->getMessage()];
    }

    $ref = (string)($lease['lease_number'] ?: ('Lease #' . $leaseId));
    AuditService::logEvent([
        'action' => 'move_in_marked_historical',
        'action_label' => 'Marked as moved in',
        'module' => 'realestate',
        'company_id' => $companyId,
        'object_type' => 're_move_ins',
        'object_id' => (string)$moveInId,
        'object_ref' => $ref,
        'summary' => 'Marked lease ' . $ref . ' as moved in on ' . $moveInDate . ' (historical entry). Reason: ' . $reason,
        'old_data' => ['move_in_completed' => (int)$lease['move_in_completed'], 'move_in_date' => $lease['move_in_date']],
        'new_data' => [
            'move_in_id' => $moveInId,
            'move_in_completed' => 1,
            'move_in_date' => $lease['move_in_date'] ?: $moveInDate,
            'unit_set_occupied' => $unitUpdated,
        ],
        'source' => 'user',
        'success' => true,
    ]);

    return ['ok' => true, 'move_in_id' => $moveInId];
}
