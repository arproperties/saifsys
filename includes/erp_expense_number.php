<?php
/**
 * Per-company EXP-NNNN sequence (atomic via erp_expense_seq + transaction).
 */
function erp_allocate_expense_number(PDO $db, int $companyId): string {
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT last_num FROM erp_expense_seq WHERE company_id = ? FOR UPDATE');
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $next = (int)$row['last_num'] + 1;
            $db->prepare('UPDATE erp_expense_seq SET last_num = ? WHERE company_id = ?')->execute([$next, $companyId]);
        } else {
            $next = 1;
            $db->prepare('INSERT INTO erp_expense_seq (company_id, last_num) VALUES (?, ?)')->execute([$companyId, $next]);
        }
        $num = 'EXP-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
        if ($owns) {
            $db->commit();
        }
        return $num;
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Sync sequence from existing MAX(expense_number) after manual backfill (optional).
 */
function erp_sync_expense_seq_from_headers(PDO $db, int $companyId): void {
    $st = $db->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(expense_number, '-', -1) AS UNSIGNED)), 0)
        FROM erp_expense_headers
        WHERE company_id = ? AND expense_number LIKE 'EXP-%'
    ");
    $st->execute([$companyId]);
    $max = (int)$st->fetchColumn();
    if ($max <= 0) {
        return;
    }
    $db->prepare('
        INSERT INTO erp_expense_seq (company_id, last_num) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE last_num = GREATEST(last_num, VALUES(last_num))
    ')->execute([$companyId, $max]);
}
