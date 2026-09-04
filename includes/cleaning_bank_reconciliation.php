<?php
/**
 * Cleaning module — bank reconciliation helpers (not real-estate accounting).
 */
declare(strict_types=1);

function cleaning_bank_period_from_date(string $date): string {
    return substr($date, 0, 7);
}

function cleaning_bank_is_period_locked(PDO $conn, int $bank_account_id, string $dateYmd): bool {
    $p = cleaning_bank_period_from_date($dateYmd);
    $st = $conn->prepare('SELECT 1 FROM cleaning_reconciliation_period_locks WHERE bank_account_id = ? AND period = ? AND locked = 1 LIMIT 1');
    $st->execute([$bank_account_id, $p]);
    return (bool) $st->fetchColumn();
}

function cleaning_bank_account_no(PDO $conn, int $cleaning_bank_account_id): ?string {
    $st = $conn->prepare('
        SELECT c.account_no FROM cleaning_bank_accounts b
        JOIN chart_of_accounts c ON c.id = b.chart_account_id
        WHERE b.id = ? LIMIT 1
    ');
    $st->execute([$cleaning_bank_account_id]);
    $r = $st->fetchColumn();
    return $r !== false ? (string) $r : null;
}

/** Sum amount_matched for a statement line (proposed + confirmed). */
function cleaning_bank_line_matched_sum(PDO $conn, int $line_id): float {
    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount_matched), 0) FROM cleaning_reconciliation_matches
        WHERE bank_statement_line_id = ? AND status IN ('proposed','confirmed')
    ");
    $st->execute([$line_id]);
    return round((float) $st->fetchColumn(), 2);
}

function cleaning_bank_line_remaining(PDO $conn, array $line): float {
    $amt = (float) ($line['amount'] ?? 0);
    $matched = cleaning_bank_line_matched_sum($conn, (int) $line['id']);
    return round(abs($amt) - $matched, 2);
}

function cleaning_receipt_matched_sum(PDO $conn, int $receipt_id): float {
    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount_matched), 0) FROM cleaning_reconciliation_matches
        WHERE system_type = 'receipt' AND system_id = ? AND status IN ('proposed','confirmed')
    ");
    $st->execute([$receipt_id]);
    return round((float) $st->fetchColumn(), 2);
}

function cleaning_expense_matched_sum(PDO $conn, int $expense_id): float {
    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount_matched), 0) FROM cleaning_reconciliation_matches
        WHERE system_type = 'expense' AND system_id = ? AND status IN ('proposed','confirmed')
    ");
    $st->execute([$expense_id]);
    return round((float) $st->fetchColumn(), 2);
}

/**
 * Soft warning: same receipt/expense already tied to another bank line (non-void).
 *
 * @return list<array{id:int,bank_statement_line_id:int,amount_matched:float,status:string}>
 */
function cleaning_match_duplicate_warnings(PDO $conn, string $system_type, int $system_id, ?int $exclude_match_id = null): array {
    $sql = "
        SELECT m.id, m.bank_statement_line_id, m.amount_matched, m.status
        FROM cleaning_reconciliation_matches m
        WHERE m.system_type = ? AND m.system_id = ? AND m.status IN ('proposed','confirmed')
    ";
    $params = [$system_type, $system_id];
    if ($exclude_match_id) {
        $sql .= ' AND m.id <> ?';
        $params[] = $exclude_match_id;
    }
    $st = $conn->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function cleaning_source_hash(int $bank_account_id, string $date, string $amount, string $desc, string $ref): string {
    $payload = $bank_account_id . '|' . $date . '|' . $amount . '|' . $desc . '|' . $ref;
    return hash('sha256', $payload);
}
