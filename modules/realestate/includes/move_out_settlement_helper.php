<?php
/**
 * Move-Out Deposit Settlement, operations side only (migrations/re_move_out_settlement_confirm.sql).
 *
 * The move-out shows the breakdown (deposit received, damages, standard
 * charges, refund due or amount payable) and staff mark the settlement as
 * complete once the Accounts team has settled it. Nothing is posted to
 * accounting from the move-out: refunds, invoices and journals are handled
 * by Accounts manually.
 */

require_once __DIR__ . '/security_deposit_helper.php';
require_once __DIR__ . '/move_out_charges_helper.php';

function rmst_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_outs LIKE 'settlement_completed_at'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Settlement marked complete (or the move-out already completed). */
function rmst_done(array $moveOut): bool
{
    return !empty($moveOut['settlement_completed_at']) || ($moveOut['status'] ?? '') === 'completed';
}

/**
 * The breakdown: ['expected', 'received', 'deposit_lease_id', 'damages' => [[label, amount|null]],
 * 'charges' => [[label, amount|null, note]], 'damage_total', 'charge_total', 'total', 'refund', 'payable', 'pending']
 * pending = damages or charges without an amount yet.
 */
function rmst_figures(PDO $conn, int $companyId, array $moveOut): array
{
    $moveOutId = (int)$moveOut['id'];
    $depLeaseId = re_sd_deposit_lease_id($conn, $companyId, (int)$moveOut['lease_id']);
    $sum = re_sd_summary($conn, $companyId, $depLeaseId);
    $f = [
        'deposit_lease_id' => $depLeaseId,
        'expected' => round((float)($sum['expected'] ?? 0), 2),
        'received' => round((float)($sum['allocated'] ?? 0), 2),
        'damages' => [], 'charges' => [], 'damage_total' => 0.0, 'charge_total' => 0.0, 'pending' => 0,
    ];
    $st = $conn->prepare("SELECT room_area, damage_description, damage_type, repair_cost FROM re_move_out_damages WHERE move_out_id = ? AND company_id = ? ORDER BY id");
    $st->execute([$moveOutId, $companyId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
        $label = trim(($d['room_area'] ? $d['room_area'] . ' - ' : '') . rtrim((string)$d['damage_description'], ' -'));
        $f['damages'][] = [$label !== '' ? $label : 'Damage', $d['repair_cost'] === null ? null : round((float)$d['repair_cost'], 2), ucfirst((string)$d['damage_type'])];
        if ($d['repair_cost'] === null) {
            $f['pending']++;
        } else {
            $f['damage_total'] += (float)$d['repair_cost'];
        }
    }
    foreach (rmoc_charges($conn, $moveOutId) as $c) {
        if ($c['amount'] !== null && (float)$c['amount'] <= 0) {
            continue; // not needed for this unit
        }
        $f['charges'][] = [$c['charge_name'], $c['amount'] === null ? null : round((float)$c['amount'], 2), (string)($c['note'] ?? '')];
        if ($c['amount'] === null) {
            $f['pending']++;
        } else {
            $f['charge_total'] += (float)$c['amount'];
        }
    }
    $f['damage_total'] = round($f['damage_total'], 2);
    $f['charge_total'] = round($f['charge_total'], 2);
    $f['total'] = round($f['damage_total'] + $f['charge_total'], 2);
    $f['refund'] = max(0, round($f['received'] - $f['total'], 2));
    $f['payable'] = max(0, round($f['total'] - $f['received'], 2));
    return $f;
}

/** Mark the settlement complete (Accounts has settled it) or reopen it. */
function rmst_set(PDO $conn, int $companyId, array $moveOut, bool $done, ?int $userId, string $note): array
{
    if (!rmst_ready($conn)) {
        return ['ok' => false, 'error' => 'Run migrations/re_move_out_settlement_confirm.sql first.'];
    }
    if (in_array($moveOut['status'], ['completed', 'cancelled'], true)) {
        return ['ok' => false, 'error' => 'This move-out is ' . $moveOut['status'] . '.'];
    }
    if ($done) {
        if (!empty($moveOut['settlement_completed_at'])) {
            return ['ok' => false, 'error' => 'The settlement is already marked as complete.'];
        }
        if (rmst_figures($conn, $companyId, $moveOut)['pending']) {
            return ['ok' => false, 'error' => 'Enter an amount for every damage and charge first (Damages & Report).'];
        }
        $conn->prepare("UPDATE re_move_outs SET settlement_completed_at = NOW(), settlement_completed_by = ?, settlement_note = ? WHERE id = ? AND company_id = ?")
            ->execute([$userId, $note !== '' ? mb_substr($note, 0, 500) : null, (int)$moveOut['id'], $companyId]);
    } else {
        if (empty($moveOut['settlement_completed_at'])) {
            return ['ok' => false, 'error' => 'The settlement is not marked as complete.'];
        }
        if ($note === '') {
            return ['ok' => false, 'error' => 'Enter the reason for reopening.'];
        }
        $conn->prepare("UPDATE re_move_outs SET settlement_completed_at = NULL, settlement_completed_by = NULL, settlement_note = ? WHERE id = ? AND company_id = ?")
            ->execute([mb_substr('Reopened: ' . $note, 0, 500), (int)$moveOut['id'], $companyId]);
    }
    re_sd_log($conn, $companyId, (int)$moveOut['lease_id'], !empty($moveOut['tenant_id']) ? (int)$moveOut['tenant_id'] : null,
        $done ? 'settlement_confirmed' : 'settlement_reopened', null, null, null, $note, $userId, 'move_out_view', null, null, (int)$moveOut['id'], null, null);
    return ['ok' => true, 'error' => ''];
}
