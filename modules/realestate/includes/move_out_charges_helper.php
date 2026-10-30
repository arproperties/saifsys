<?php
/**
 * Standard Move-Out Charges (migrations/re_move_out_charges.sql).
 *
 * The company keeps a list of charges every move-out usually carries
 * (Cleaning, Painting, Pest Control, ...); each move-out gets its own copy,
 * staff enter the amount for that unit and may add one-off charges. When the
 * deposit settlement is prepared, every charge with an amount becomes its own
 * security deposit deduction (reason "Standard move-out charge: <name>"),
 * next to the damage deduction, so approval, refund and accounting are
 * unchanged.
 */

const RMOC_DEFAULT_TYPES = ['Cleaning', 'Painting', 'Pest Control'];
const RMOC_REASON_PREFIX = 'Standard move-out charge: ';

function rmoc_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$conn->query("SHOW COLUMNS FROM re_move_out_charges LIKE 'is_removed'")->fetch()
                && (bool)$conn->query("SHOW TABLES LIKE 're_move_out_charge_types'")->fetchColumn();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The company's list, started with Cleaning, Painting and Pest Control. */
function rmoc_ensure_types(PDO $conn, int $companyId): void
{
    $st = $conn->prepare("SELECT COUNT(*) FROM re_move_out_charge_types WHERE company_id = ?");
    $st->execute([$companyId]);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    $ins = $conn->prepare("INSERT INTO re_move_out_charge_types (company_id, name, display_order) VALUES (?, ?, ?)");
    foreach (RMOC_DEFAULT_TYPES as $o => $name) {
        $ins->execute([$companyId, $name, $o + 1]);
    }
}

function rmoc_types(PDO $conn, int $companyId, bool $includeInactive = false): array
{
    $st = $conn->prepare("SELECT * FROM re_move_out_charge_types WHERE company_id = ?" . ($includeInactive ? '' : ' AND is_active = 1') . " ORDER BY display_order, id");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Copy the company's charges into the move-out, once. */
function rmoc_create_for_move_out(PDO $conn, int $companyId, int $moveOutId, ?int $userId): void
{
    $has = $conn->prepare("SELECT COUNT(*) FROM re_move_out_charges WHERE move_out_id = ?");
    $has->execute([$moveOutId]);
    if ((int)$has->fetchColumn() > 0) {
        return;
    }
    rmoc_ensure_types($conn, $companyId);
    $ins = $conn->prepare("INSERT INTO re_move_out_charges (company_id, move_out_id, charge_name, display_order, updated_by) VALUES (?, ?, ?, ?, ?)");
    foreach (rmoc_types($conn, $companyId) as $o => $t) {
        $ins->execute([$companyId, $moveOutId, $t['name'], $o + 1, $userId]);
    }
}

/** The move-out's charges; $removed = true for the ones removed from it instead. */
function rmoc_charges(PDO $conn, int $moveOutId, bool $removed = false): array
{
    if (!rmoc_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("SELECT * FROM re_move_out_charges WHERE move_out_id = ? AND is_removed = " . ($removed ? 1 : 0) . " ORDER BY display_order, id");
    $st->execute([$moveOutId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** ['total' => sum of amounts, 'pending' => lines without an amount] */
function rmoc_summary(array $charges): array
{
    $s = ['total' => 0.0, 'pending' => 0];
    foreach ($charges as $c) {
        if ($c['amount'] === null) {
            $s['pending']++;
        } else {
            $s['total'] += (float)$c['amount'];
        }
    }
    $s['total'] = round($s['total'], 2);
    return $s;
}

/** Deduction type for a charge: cleaning when the name says so, else other. */
function rmoc_deduction_type(string $name): string
{
    return stripos($name, 'clean') !== false ? 'cleaning' : 'other';
}

/**
 * Called when the deposit settlement is prepared: the charge deductions are
 * rebuilt from the move-out's charges (drafts, approved with the rest).
 */
function rmoc_sync_deductions(PDO $conn, int $companyId, int $moveOutId, int $leaseId, ?int $tenantId, ?int $userId): void
{
    if (!rmoc_ready($conn)) {
        return;
    }
    $conn->prepare("
        UPDATE re_security_deposit_deductions SET status = 'cancelled'
        WHERE company_id = ? AND move_out_id = ? AND status IN ('draft', 'approved') AND reason LIKE ?
    ")->execute([$companyId, $moveOutId, RMOC_REASON_PREFIX . '%']);
    $ins = $conn->prepare("
        INSERT INTO re_security_deposit_deductions
            (company_id, lease_id, tenant_id, move_out_id, deduction_type, amount, reason, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)
    ");
    foreach (rmoc_charges($conn, $moveOutId) as $c) {
        if ($c['amount'] === null || (float)$c['amount'] <= 0) {
            continue;
        }
        $ins->execute([$companyId, $leaseId, $tenantId, $moveOutId, rmoc_deduction_type($c['charge_name']), round((float)$c['amount'], 2),
            mb_substr(RMOC_REASON_PREFIX . $c['charge_name'] . ($c['note'] ? ' (' . $c['note'] . ')' : ''), 0, 255), $userId]);
    }
}

/** A deduction row created from a standard charge (shown with the charges, not again). */
function rmoc_is_charge_deduction(array $deduction): bool
{
    return strpos((string)($deduction['reason'] ?? ''), RMOC_REASON_PREFIX) === 0;
}
