<?php
/**
 * Phase 2A — Cheque lifecycle (bounce / replace / cancel / lost / redeposit).
 * Phase 3 — Bank status (deposited/cleared) never settles invoices; Allocated only after Payment Workspace confirm.
 */

function co_shop_cheque_lifecycle_ready(PDO $conn): bool {
    return co_db_column_exists($conn, 'co_shop_rent_cheques', 'replaced_by_cheque_id');
}

function co_shop_cheque_allocated_status_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!co_db_table_exists($conn, 'co_shop_rent_cheques')) {
        $ready = false;
        return false;
    }
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM co_shop_rent_cheques LIKE 'status'");
        $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $type = (string)($col['Type'] ?? '');
        $ready = str_contains($type, 'allocated');
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** Statuses that mean the instrument still needs Workspace allocation (not terminal). */
function co_shop_cheque_allocatable_statuses(): array {
    return ['received', 'deposited', 'cleared'];
}

function co_shop_cheque_has_active_payment(array $cheque): bool {
    return !empty($cheque['payment_id']) || !empty($cheque['allocated_payment_id'])
        || (($cheque['status'] ?? '') === 'allocated');
}

/**
 * Block bounce/replace/cancel when payment already allocated via Workspace (or legacy clear).
 */
function co_shop_cheque_require_no_settlement(array $cheque, string $opLabel = 'change'): void {
    $status = (string)($cheque['status'] ?? '');
    if ($status === 'allocated' || (!empty($cheque['payment_id']) && $status !== 'cleared')) {
        throw new RuntimeException(
            'This cheque is linked to a posted payment. Reverse the payment in Payment Workspace first, then ' . $opLabel . '.'
        );
    }
    if ($status === 'allocated' || !empty($cheque['allocated_payment_id'])) {
        throw new RuntimeException(
            'This cheque is allocated. Reverse the payment in Payment Workspace first, then ' . $opLabel . '.'
        );
    }
    if (!empty($cheque['payment_id']) && in_array($status, ['cleared', 'allocated'], true)) {
        throw new RuntimeException(
            'This cheque has a receipt. Reverse the payment in Payment Workspace first, then ' . $opLabel . '.'
        );
    }
}

function co_shop_get_cheque(PDO $conn, int $companyId, int $chequeId): ?array {
    $stmt = $conn->prepare("SELECT * FROM co_shop_rent_cheques WHERE id = ? AND company_id = ?");
    $stmt->execute([$chequeId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Load & validate cheques for Workspace allocate (same company + contract).
 * @param list<int> $chequeIds
 * @return list<array<string,mixed>>
 */
function co_shop_cheques_for_allocate(PDO $conn, int $companyId, int $contractId, array $chequeIds): array {
    $chequeIds = array_values(array_unique(array_filter(array_map('intval', $chequeIds))));
    if (!$chequeIds) {
        throw new RuntimeException('Select at least one cheque.');
    }
    $placeholders = implode(',', array_fill(0, count($chequeIds), '?'));
    $params = array_merge([$companyId, $contractId], $chequeIds);
    $stmt = $conn->prepare("
        SELECT * FROM co_shop_rent_cheques
        WHERE company_id = ? AND contract_id = ? AND id IN ($placeholders)
        ORDER BY cheque_date ASC, id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) !== count($chequeIds)) {
        throw new RuntimeException('One or more cheques were not found on this contract.');
    }
    $allowed = co_shop_cheque_allocatable_statuses();
    foreach ($rows as $ch) {
        if (($ch['cheque_type'] ?? '') === 'security_deposit') {
            throw new RuntimeException('Security deposit cheques use Record Deposit, not Allocate Payment.');
        }
        if (!in_array($ch['status'] ?? '', $allowed, true)) {
            throw new RuntimeException(
                'Cheque #' . (int)$ch['id'] . ' status "' . ($ch['status'] ?? '') . '" cannot be allocated. '
                . 'Use received / deposited / cleared instruments only.'
            );
        }
        if (co_shop_cheque_has_active_payment($ch)) {
            throw new RuntimeException('Cheque #' . (int)$ch['id'] . ' is already linked to a payment.');
        }
    }
    return $rows;
}

/**
 * Bank operations only — does not create payments or settle invoices.
 */
function co_shop_cheque_mark_bank_status(
    PDO $conn,
    int $companyId,
    int $chequeId,
    string $newStatus,
    ?string $note,
    ?int $userId
): void {
    if (!in_array($newStatus, ['received', 'deposited', 'cleared'], true)) {
        throw new RuntimeException('Invalid bank status.');
    }
    $ch = co_shop_get_cheque($conn, $companyId, $chequeId);
    if (!$ch) {
        throw new RuntimeException('Cheque not found.');
    }
    if (($ch['cheque_type'] ?? '') === 'security_deposit' && $newStatus === 'cleared') {
        throw new RuntimeException('Use Record Deposit for security deposit cheques.');
    }
    if (in_array($ch['status'] ?? '', ['allocated', 'cancelled', 'replaced'], true)) {
        throw new RuntimeException('Cannot change bank status for ' . $ch['status'] . ' cheques.');
    }
    if (!empty($ch['payment_id']) || !empty($ch['allocated_payment_id'])) {
        throw new RuntimeException('Cheque is linked to a payment. Reverse payment before changing bank status.');
    }
    $conn->prepare("
        UPDATE co_shop_rent_cheques
        SET status = ?, lifecycle_note = COALESCE(?, lifecycle_note)
        WHERE id = ? AND company_id = ?
    ")->execute([$newStatus, $note ?: null, $chequeId, $companyId]);
    if (function_exists('co_shop_log_event')) {
        co_shop_log_event($conn, $companyId, (int)$ch['contract_id'], 'cheque_bank_status', [
            'cheque_id' => $chequeId,
            'status' => $newStatus,
            'note' => $note,
        ], $userId);
    }
}

/**
 * After Workspace confirm — mark instruments Allocated.
 * @param list<int> $chequeIds
 * @param array<int,float> $amountByCheque
 */
function co_shop_cheques_mark_allocated(
    PDO $conn,
    int $companyId,
    int $paymentId,
    array $chequeIds,
    array $amountByCheque = [],
    ?int $userId = null
): void {
    if ($paymentId <= 0 || !$chequeIds) {
        return;
    }
    if (!co_shop_cheque_allocated_status_ready($conn)) {
        foreach ($chequeIds as $chId) {
            $chId = (int)$chId;
            if ($chId <= 0) {
                continue;
            }
            $conn->prepare("
                UPDATE co_shop_rent_cheques
                SET payment_id = COALESCE(NULLIF(payment_id, 0), ?)
                WHERE id = ? AND company_id = ?
            ")->execute([$paymentId, $chId, $companyId]);
        }
        return;
    }
    $hasAllocAt = co_db_column_exists($conn, 'co_shop_rent_cheques', 'allocated_at');
    $hasAllocPay = co_db_column_exists($conn, 'co_shop_rent_cheques', 'allocated_payment_id');
    foreach ($chequeIds as $chId) {
        $chId = (int)$chId;
        if ($chId <= 0) {
            continue;
        }
        $ch = co_shop_get_cheque($conn, $companyId, $chId);
        if (!$ch) {
            continue;
        }
        $sets = ["status = 'allocated'", 'payment_id = ?'];
        $params = [$paymentId];
        if ($hasAllocPay) {
            $sets[] = 'allocated_payment_id = ?';
            $params[] = $paymentId;
        }
        if ($hasAllocAt) {
            $sets[] = 'allocated_at = NOW()';
        }
        $params[] = $chId;
        $params[] = $companyId;
        $conn->prepare('UPDATE co_shop_rent_cheques SET ' . implode(', ', $sets) . ' WHERE id = ? AND company_id = ?')
            ->execute($params);
        if (function_exists('co_shop_log_event')) {
            co_shop_log_event($conn, $companyId, (int)$ch['contract_id'], 'cheque_allocated', [
                'cheque_id' => $chId,
                'payment_id' => $paymentId,
                'amount' => $amountByCheque[$chId] ?? (float)$ch['amount'],
            ], $userId);
        }
    }
}

/**
 * After payment reverse — reopen instruments to cleared (bank still cleared) by default.
 */
function co_shop_cheques_unallocate_for_payment(
    PDO $conn,
    int $companyId,
    int $paymentId,
    string $restoreStatus = 'cleared',
    ?int $userId = null
): void {
    if ($paymentId <= 0) {
        return;
    }
    if (!in_array($restoreStatus, ['received', 'deposited', 'cleared'], true)) {
        $restoreStatus = 'cleared';
    }
    $ids = [];
    if (co_db_table_exists($conn, 'co_shop_receipt_cheque_links')) {
        $stmt = $conn->prepare("SELECT cheque_id FROM co_shop_receipt_cheque_links WHERE company_id = ? AND payment_id = ?");
        $stmt->execute([$companyId, $paymentId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    $stmt = $conn->prepare("SELECT id FROM co_shop_rent_cheques WHERE company_id = ? AND payment_id = ?");
    $stmt->execute([$companyId, $paymentId]);
    $ids = array_values(array_unique(array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []))));
    if (co_db_column_exists($conn, 'co_shop_rent_cheques', 'allocated_payment_id')) {
        $stmt = $conn->prepare("SELECT id FROM co_shop_rent_cheques WHERE company_id = ? AND allocated_payment_id = ?");
        $stmt->execute([$companyId, $paymentId]);
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []))));
    }
    foreach ($ids as $chId) {
        $ch = co_shop_get_cheque($conn, $companyId, $chId);
        if (!$ch) {
            continue;
        }
        $sets = ['status = ?', 'payment_id = NULL'];
        $params = [$restoreStatus];
        if (co_db_column_exists($conn, 'co_shop_rent_cheques', 'allocated_payment_id')) {
            $sets[] = 'allocated_payment_id = NULL';
        }
        if (co_db_column_exists($conn, 'co_shop_rent_cheques', 'allocated_at')) {
            $sets[] = 'allocated_at = NULL';
        }
        $params[] = $chId;
        $params[] = $companyId;
        $conn->prepare('UPDATE co_shop_rent_cheques SET ' . implode(', ', $sets) . ' WHERE id = ? AND company_id = ?')
            ->execute($params);
        if (function_exists('co_shop_log_event')) {
            co_shop_log_event($conn, $companyId, (int)$ch['contract_id'], 'cheque_unallocated', [
                'cheque_id' => $chId,
                'payment_id' => $paymentId,
                'status' => $restoreStatus,
            ], $userId);
        }
    }
}

function co_shop_cheque_bounce(PDO $conn, int $companyId, int $chequeId, string $note, ?int $userId): void {
    $ch = co_shop_get_cheque($conn, $companyId, $chequeId);
    if (!$ch) {
        throw new RuntimeException('Cheque not found.');
    }
    if (!empty($ch['payment_id']) || !empty($ch['allocated_payment_id']) || ($ch['status'] ?? '') === 'allocated') {
        throw new RuntimeException(
            'This cheque is linked to a posted payment. Reverse the payment in Payment Workspace first, then bounce.'
        );
    }
    if (in_array($ch['status'], ['cancelled', 'replaced', 'allocated'], true)) {
        throw new RuntimeException('Cheque status does not allow bounce.');
    }
    $conn->prepare("
        UPDATE co_shop_rent_cheques
        SET status = 'bounced', bounced_at = NOW(), lifecycle_note = ?, notes = COALESCE(notes, ?)
        WHERE id = ? AND company_id = ?
    ")->execute([$note ?: null, $note ?: 'Bounced', $chequeId, $companyId]);
    co_shop_log_event($conn, $companyId, (int)$ch['contract_id'], 'cheque_bounced', [
        'cheque_id' => $chequeId,
        'note' => $note,
    ], $userId);
}

function co_shop_cheque_cancel(PDO $conn, int $companyId, int $chequeId, string $note, ?int $userId): void {
    $ch = co_shop_get_cheque($conn, $companyId, $chequeId);
    if (!$ch) {
        throw new RuntimeException('Cheque not found.');
    }
    if (!empty($ch['payment_id']) || !empty($ch['allocated_payment_id']) || ($ch['status'] ?? '') === 'allocated') {
        throw new RuntimeException(
            'This cheque is linked to a posted payment. Reverse the payment in Payment Workspace first, then cancel.'
        );
    }
    $conn->prepare("
        UPDATE co_shop_rent_cheques SET status = 'cancelled', lifecycle_note = ?
        WHERE id = ? AND company_id = ?
    ")->execute([$note ?: null, $chequeId, $companyId]);
    co_shop_log_event($conn, $companyId, (int)$ch['contract_id'], 'cheque_cancelled', [
        'cheque_id' => $chequeId,
        'note' => $note,
    ], $userId);
}

function co_shop_cheque_mark_lost(PDO $conn, int $companyId, int $chequeId, string $note, ?int $userId): void {
    $ch = co_shop_get_cheque($conn, $companyId, $chequeId);
    if (!$ch) {
        throw new RuntimeException('Cheque not found.');
    }
    if (!empty($ch['payment_id']) || !empty($ch['allocated_payment_id']) || ($ch['status'] ?? '') === 'allocated') {
        throw new RuntimeException(
            'This cheque is linked to a posted payment. Reverse the payment in Payment Workspace first, then mark lost.'
        );
    }
    $conn->prepare("
        UPDATE co_shop_rent_cheques
        SET status = 'cancelled', lost_at = NOW(), lifecycle_note = ?
        WHERE id = ? AND company_id = ?
    ")->execute([$note ?: 'Lost cheque', $chequeId, $companyId]);
    co_shop_log_event($conn, $companyId, (int)$ch['contract_id'], 'cheque_lost', [
        'cheque_id' => $chequeId,
        'note' => $note,
    ], $userId);
}

function co_shop_cheque_redeposit(PDO $conn, int $companyId, int $chequeId, string $note, ?int $userId): void {
    $ch = co_shop_get_cheque($conn, $companyId, $chequeId);
    if (!$ch) {
        throw new RuntimeException('Cheque not found.');
    }
    if (!in_array($ch['status'], ['bounced', 'returned'], true)) {
        throw new RuntimeException('Only bounced or returned cheques can be re-deposited.');
    }
    $conn->prepare("
        UPDATE co_shop_rent_cheques
        SET status = 'deposited', bounced_at = NULL, lifecycle_note = ?
        WHERE id = ? AND company_id = ?
    ")->execute([$note ?: 'Re-deposited', $chequeId, $companyId]);
    co_shop_log_event($conn, $companyId, (int)$ch['contract_id'], 'cheque_redeposited', [
        'cheque_id' => $chequeId,
    ], $userId);
}

/**
 * Create replacement cheque and link both ways.
 * @return int new cheque id
 */
function co_shop_cheque_replace(
    PDO $conn,
    int $companyId,
    int $oldChequeId,
    array $newData,
    ?int $userId
): int {
    $old = co_shop_get_cheque($conn, $companyId, $oldChequeId);
    if (!$old) {
        throw new RuntimeException('Cheque not found.');
    }
    if (!empty($old['payment_id']) || !empty($old['allocated_payment_id']) || ($old['status'] ?? '') === 'allocated') {
        throw new RuntimeException(
            'This cheque is linked to a posted payment. Reverse the payment in Payment Workspace first, then replace.'
        );
    }
    if (($old['status'] ?? '') === 'replaced') {
        throw new RuntimeException('Cheque already replaced.');
    }
    $amount = isset($newData['amount']) ? (float)$newData['amount'] : (float)$old['amount'];
    $date = $newData['cheque_date'] ?? $old['cheque_date'];
    $number = $newData['cheque_number'] ?? null;
    $bank = $newData['bank_name'] ?? $old['bank_name'];
    $vat = isset($newData['vat_amount']) ? (float)$newData['vat_amount'] : (float)($old['vat_amount'] ?? 0);
    $net = isset($newData['net_amount']) ? (float)$newData['net_amount'] : (float)($old['net_amount'] ?? $amount);

    $cols = 'company_id, contract_id, schedule_id, cheque_type, cheque_number, bank_name, cheque_date, amount, status, invoice_id, notes, created_by';
    $vals = '?,?,?,?,?,?,?,?,?,?,?,?';
    $params = [
        $companyId,
        (int)$old['contract_id'],
        $old['schedule_id'] ? (int)$old['schedule_id'] : null,
        $old['cheque_type'],
        $number,
        $bank,
        $date,
        $amount,
        'received',
        $old['invoice_id'] ? (int)$old['invoice_id'] : null,
        'Replacement for cheque #' . $oldChequeId,
        $userId,
    ];
    if (co_db_column_exists($conn, 'co_shop_rent_cheques', 'vat_amount')) {
        $cols .= ', vat_amount, net_amount';
        $vals .= ',?,?';
        $params[] = $vat;
        $params[] = $net;
    }
    if (co_shop_cheque_lifecycle_ready($conn)) {
        $cols .= ', replaces_cheque_id, lifecycle_note';
        $vals .= ',?,?';
        $params[] = $oldChequeId;
        $params[] = trim((string)($newData['lifecycle_note'] ?? 'Replacement cheque'));
    }
    $conn->prepare("INSERT INTO co_shop_rent_cheques ($cols) VALUES ($vals)")->execute($params);
    $newId = (int)$conn->lastInsertId();

    if (co_shop_cheque_lifecycle_ready($conn)) {
        $conn->prepare("
            UPDATE co_shop_rent_cheques
            SET status = 'replaced', replaced_by_cheque_id = ?, lifecycle_note = ?
            WHERE id = ? AND company_id = ?
        ")->execute([$newId, 'Replaced by cheque #' . $newId, $oldChequeId, $companyId]);
    } else {
        $conn->prepare("UPDATE co_shop_rent_cheques SET status = 'replaced' WHERE id = ? AND company_id = ?")
            ->execute([$oldChequeId, $companyId]);
    }

    co_shop_log_event($conn, $companyId, (int)$old['contract_id'], 'cheque_replaced', [
        'old_cheque_id' => $oldChequeId,
        'new_cheque_id' => $newId,
    ], $userId);
    return $newId;
}
