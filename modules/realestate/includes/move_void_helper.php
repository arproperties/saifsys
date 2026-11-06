<?php
/**
 * Cancel / void a move-in or move-out made by mistake. Owner/Admin only, with a reason.
 *
 * The record is kept (status 'cancelled' + cancelled_at / _by / cancel_reason /
 * cancelled_from_status, migrations/re_move_void.sql) and the effects of the
 * completed step are undone. Accounting is never touched here: a posted deposit
 * journal or a paid recovery invoice blocks the void, with what to do first.
 *
 * Move-in (completed): blocked while a move-out exists on the lease or on a renewal
 *   that relies on this move-in. Undoes: lease move_in_completed, unit occupied.
 * Move-out: blocked while the settlement journal is posted (reverse it in Accounting,
 *   which sets the settlement back to draft) or the recovery invoice has payments.
 *   Undoes: unpaid recovery invoice, unposted settlement + deductions, its notice
 *   (made together with the move-out; an open one would block a new move-out), lease move_out_completed, unit vacant (back to occupied when the
 *   lease is active). The lease status is not changed back.
 */

require_once __DIR__ . '/lease_lifecycle_guard.php';
require_once __DIR__ . '/security_deposit_helper.php';
require_once __DIR__ . '/move_in_chain_helper.php';

function rmv_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_ins LIKE 'cancel_reason'")->fetch()
                && (bool)$conn->query("SHOW COLUMNS FROM re_move_outs LIKE 'cancel_reason'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function rmv_can_manage(PDO $conn): bool
{
    return has_role('Owner', $conn) || has_role('Admin', $conn);
}

function rmv_audit(int $companyId, string $type, int $id, string $ref, string $action, string $summary): void
{
    try {
        require_once __DIR__ . '/../../../includes/AuditService.php';
        AuditService::logEvent([
            'action' => $action,
            'module' => 'realestate',
            'company_id' => $companyId,
            'object_type' => $type,
            'object_id' => (string)$id,
            'object_ref' => $ref,
            'summary' => $summary,
            'source' => 'user',
            'success' => true,
        ]);
    } catch (Throwable $e) {
        error_log('Move void audit failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------- Move-in

/**
 * What cancelling this move-in would do, or why it cannot.
 * ['ok' => bool, 'blockers' => [text], 'effects' => [text], 'completed' => bool]
 */
function rmv_move_in_check(PDO $conn, int $companyId, array $mi): array
{
    $res = ['ok' => false, 'blockers' => [], 'effects' => [], 'completed' => $mi['status'] === 'completed'];
    if ($mi['status'] === 'cancelled') {
        $res['blockers'][] = 'This move-in is already cancelled.';
        return $res;
    }
    $leaseId = (int)$mi['lease_id'];

    // Leases that rely on this move-in: the lease itself and its renewals covered by it
    $leaseIds = [$leaseId];
    if ($res['completed']) {
        foreach (re_move_in_carried_over_map($conn, $companyId) as $lid => $cov) {
            if ((int)$cov['move_in_id'] === (int)$mi['id']) {
                $leaseIds[] = (int)$lid;
            }
        }
    }
    $ph = implode(',', array_fill(0, count($leaseIds), '?'));
    $mo = $conn->prepare("
        SELECT mo.id, l.lease_number FROM re_move_outs mo JOIN re_leases l ON l.id = mo.lease_id
        WHERE mo.company_id = ? AND mo.lease_id IN ($ph) AND mo.status <> 'cancelled'
    ");
    $mo->execute(array_merge([$companyId], $leaseIds));
    foreach ($mo->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $res['blockers'][] = 'Move-Out #' . $r['id'] . ' exists for lease ' . $r['lease_number'] . '. Cancel that move-out first.';
    }

    if ($res['completed']) {
        $res['effects'][] = 'The lease is no longer marked as moved in; Move-In (or Mark as Moved In) becomes available again.';
        $res['effects'][] = 'The unit is set back to Vacant, unless another active lease holds it.';
        if (count($leaseIds) > 1) {
            $res['effects'][] = (count($leaseIds) - 1) . ' renewal lease(s) relied on this move-in and will need it again (Mark as Moved In).';
        }
    } else {
        $res['effects'][] = 'The move-in in progress is stopped; a new move-in can be started for the lease.';
    }
    $res['effects'][] = 'The checklist, keys, photos and forms are kept on the cancelled record.';
    $res['ok'] = !$res['blockers'];
    return $res;
}

/** Cancel / void the move-in. Returns ['ok' => bool, 'error' => string]. */
function rmv_void_move_in(PDO $conn, int $companyId, int $moveInId, ?int $userId, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Enter the reason for cancelling.'];
    }
    if (!rmv_ready($conn)) {
        return ['ok' => false, 'error' => 'Run migrations/re_move_void.sql first.'];
    }
    $st = $conn->prepare("SELECT mi.*, l.lease_number FROM re_move_ins mi JOIN re_leases l ON l.id = mi.lease_id WHERE mi.id = ? AND mi.company_id = ?");
    $st->execute([$moveInId, $companyId]);
    $mi = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mi) {
        return ['ok' => false, 'error' => 'Move-in not found.'];
    }
    $chk = rmv_move_in_check($conn, $companyId, $mi);
    if (!$chk['ok']) {
        return ['ok' => false, 'error' => implode(' ', $chk['blockers'])];
    }
    $leaseId = (int)$mi['lease_id'];
    try {
        $conn->beginTransaction();
        $conn->prepare("UPDATE re_move_ins SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?, cancelled_from_status = ? WHERE id = ? AND company_id = ?")
            ->execute([$userId, mb_substr($reason, 0, 500), $mi['status'], $moveInId, $companyId]);
        if ($chk['completed']) {
            $other = $conn->prepare("SELECT COUNT(*) FROM re_move_ins WHERE lease_id = ? AND status = 'completed' AND id <> ?");
            $other->execute([$leaseId, $moveInId]);
            if ((int)$other->fetchColumn() === 0) {
                $conn->prepare("UPDATE re_leases SET move_in_completed = 0 WHERE id = ? AND company_id = ?")->execute([$leaseId, $companyId]);
                // Vacant unless another active lease holds the unit (this lease's own tenant never moved in)
                $held = $conn->prepare("
                    SELECT COUNT(*) FROM re_leases l
                    LEFT JOIN re_lease_units lu ON lu.lease_id = l.id
                    JOIN re_move_ins m ON m.lease_id = l.id AND m.status = 'completed'
                    WHERE l.company_id = ? AND l.id <> ? AND l.status = 'active' AND (l.unit_id = ? OR lu.unit_id = ?)
                ");
                $upd = $conn->prepare("UPDATE re_units SET status = 'vacant' WHERE id = ? AND company_id = ? AND status = 'occupied'");
                foreach (re_lease_unit_ids($conn, $leaseId) as $unitId) {
                    $held->execute([$companyId, $leaseId, $unitId, $unitId]);
                    if ((int)$held->fetchColumn() === 0) {
                        $upd->execute([$unitId, $companyId]);
                    }
                }
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not cancel the move-in: ' . $e->getMessage()];
    }
    rmv_audit($companyId, 're_move_ins', $moveInId, 'Move-In #' . $moveInId . ' (' . $mi['lease_number'] . ')',
        $chk['completed'] ? 'move_in_voided' : 'move_in_cancelled',
        ($chk['completed'] ? 'Completed move-in voided' : 'Move-in cancelled (was ' . $mi['status'] . ')') . '. Reason: ' . $reason);
    return ['ok' => true, 'error' => ''];
}

// ---------------------------------------------------------------- Move-out

/**
 * What cancelling this move-out would do, or why it cannot.
 * ['ok', 'blockers', 'effects', 'completed', 'has_notice']
 */
function rmv_move_out_check(PDO $conn, int $companyId, array $mo): array
{
    $res = ['ok' => false, 'blockers' => [], 'effects' => [], 'completed' => $mo['status'] === 'completed', 'has_notice' => false];
    if ($mo['status'] === 'cancelled') {
        $res['blockers'][] = 'This move-out is already cancelled.';
        return $res;
    }
    $moveOutId = (int)$mo['id'];

    $set = re_sd_settlement_for_move_out($conn, $companyId, $moveOutId);
    if ($set && !empty($set['journal_id'])) {
        $res['blockers'][] = 'The deposit settlement is posted to accounting (journal #' . (int)$set['journal_id'] . '). Reverse that journal first in Accounting: the settlement goes back to draft, then this move-out can be cancelled.';
    } elseif ($set && $set['status'] !== 'cancelled') {
        $res['effects'][] = 'The deposit settlement (not posted) and its deductions are cancelled.';
    }

    if (is_file(__DIR__ . '/move_out_recovery_helper.php')) {
        require_once __DIR__ . '/move_out_recovery_helper.php';
        if (function_exists('rmor_ready') && rmor_ready($conn) && ($rec = rmor_status($conn, $companyId, $mo)) && $rec['status'] !== 'cancelled') {
            if ($rec['paid'] > 0.005) {
                $res['blockers'][] = 'Payments (' . number_format($rec['paid'], 2) . ' AED) are recorded against the final recovery invoice. Reverse those payments first.';
            } else {
                $res['effects'][] = 'The unpaid final recovery invoice (' . number_format($rec['amount'], 2) . ' AED) is cancelled.';
            }
        }
    }

    if (!empty($mo['move_out_notice_id'])) {
        $n = $conn->prepare("SELECT status FROM re_move_out_notices WHERE id = ? AND company_id = ?");
        $n->execute([(int)$mo['move_out_notice_id'], $companyId]);
        $res['has_notice'] = ($ns = $n->fetchColumn()) !== false && $ns !== 'cancelled';
        if ($res['has_notice']) {
            // The notice is made together with the move-out; an open notice would block a new move-out
            $res['effects'][] = 'The move-out notice is cancelled too, so a new notice and move-out can be created if needed.';
        }
    }

    if ($res['completed']) {
        $res['effects'][] = 'The lease is no longer marked as moved out; its unit(s) go back to Occupied if the lease is active.';
        $res['effects'][] = 'The lease status (' . ($mo['lease_status'] ?? '') . ') is not changed back. If the lease was terminated, reverse the termination separately.';
    } else {
        $res['effects'][] = 'The move-out in progress is stopped; a new move-out can be created for the lease.';
    }
    $res['effects'][] = 'The inspection, damages, keys and photos are kept on the cancelled record.';
    $res['ok'] = !$res['blockers'];
    return $res;
}

/** Cancel / void the move-out. Returns ['ok' => bool, 'error' => string]. */
function rmv_void_move_out(PDO $conn, int $companyId, int $moveOutId, ?int $userId, string $reason, bool $cancelNotice): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Enter the reason for cancelling.'];
    }
    if (!rmv_ready($conn)) {
        return ['ok' => false, 'error' => 'Run migrations/re_move_void.sql first.'];
    }
    $st = $conn->prepare("SELECT mo.*, l.lease_number, l.status AS lease_status FROM re_move_outs mo JOIN re_leases l ON l.id = mo.lease_id WHERE mo.id = ? AND mo.company_id = ?");
    $st->execute([$moveOutId, $companyId]);
    $mo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mo) {
        return ['ok' => false, 'error' => 'Move-out not found.'];
    }
    $chk = rmv_move_out_check($conn, $companyId, $mo);
    if (!$chk['ok']) {
        return ['ok' => false, 'error' => implode(' ', $chk['blockers'])];
    }
    $leaseId = (int)$mo['lease_id'];
    try {
        $conn->beginTransaction();

        // Unpaid recovery invoice
        if (function_exists('rmor_status') && ($rec = rmor_status($conn, $companyId, $mo)) && $rec['status'] !== 'cancelled') {
            $rc = rmor_cancel($conn, $companyId, $moveOutId, $userId, 'Move-out #' . $moveOutId . ' cancelled: ' . $reason);
            if (empty($rc['ok'])) {
                throw new RuntimeException($rc['error'] ?? 'The recovery invoice could not be cancelled.');
            }
        }
        // Unposted settlement and its deductions
        $set = re_sd_settlement_for_move_out($conn, $companyId, $moveOutId);
        if ($set && empty($set['journal_id']) && $set['status'] !== 'cancelled') {
            $conn->prepare("UPDATE re_security_deposit_settlements SET status = 'cancelled', notes = TRIM(CONCAT(COALESCE(notes, ''), '\n', ?)), updated_at = NOW() WHERE id = ?")
                ->execute(['[' . date('Y-m-d H:i') . '] Cancelled with Move-Out #' . $moveOutId . ': ' . $reason, (int)$set['id']]);
            $conn->prepare("UPDATE re_security_deposit_deductions SET status = 'cancelled' WHERE company_id = ? AND move_out_id = ? AND journal_id IS NULL AND status IN ('draft', 'approved')")
                ->execute([$companyId, $moveOutId]);
        }
        if ($cancelNotice && !empty($mo['move_out_notice_id'])) {
            $conn->prepare("UPDATE re_move_out_notices SET status = 'cancelled' WHERE id = ? AND company_id = ?")
                ->execute([(int)$mo['move_out_notice_id'], $companyId]);
        }
        $conn->prepare("UPDATE re_move_outs SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?, cancelled_from_status = ? WHERE id = ? AND company_id = ?")
            ->execute([$userId, mb_substr($reason, 0, 500), $mo['status'], $moveOutId, $companyId]);

        if ($chk['completed']) {
            $other = $conn->prepare("SELECT COUNT(*) FROM re_move_outs WHERE lease_id = ? AND status = 'completed' AND id <> ?");
            $other->execute([$leaseId, $moveOutId]);
            if ((int)$other->fetchColumn() === 0) {
                $conn->prepare("UPDATE re_leases SET move_out_completed = 0 WHERE id = ? AND company_id = ?")->execute([$leaseId, $companyId]);
                if ($mo['lease_status'] === 'active') {
                    $occ = $conn->prepare("UPDATE re_units SET status = 'occupied' WHERE id = ? AND company_id = ? AND status = 'vacant'");
                    foreach (re_lease_unit_ids($conn, $leaseId) as $unitId) {
                        $occ->execute([$unitId, $companyId]);
                    }
                }
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not cancel the move-out: ' . $e->getMessage()];
    }
    rmv_audit($companyId, 're_move_outs', $moveOutId, 'Move-Out #' . $moveOutId . ' (' . $mo['lease_number'] . ')',
        $chk['completed'] ? 'move_out_voided' : 'move_out_cancelled',
        ($chk['completed'] ? 'Completed move-out voided' : 'Move-out cancelled (was ' . $mo['status'] . ')')
        . ($cancelNotice && $chk['has_notice'] ? ', notice cancelled' : '') . '. Reason: ' . $reason);
    return ['ok' => true, 'error' => ''];
}
