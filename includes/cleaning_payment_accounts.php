<?php
/**
 * Cleaning module: shared Chart of Accounts list + validation for
 * - customer receipt deposit (money in)
 * - expense payment (money out)
 *
 * Same rules everywhere: active Asset leaf accounts; excludes 1110 (trade receivables).
 */

declare(strict_types=1);

/**
 * @return list<array{account_no: string, name: string}>
 */
function cleaning_payment_account_options(PDO $conn): array {
    $st = $conn->query("
        SELECT account_no, name
        FROM chart_of_accounts
        WHERE type = 'Asset'
          AND is_header = 0
          AND is_active = 1
          AND account_no <> '1110'
        ORDER BY account_no
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @throws RuntimeException if account is not allowed
 */
function cleaning_validate_payment_account_no(PDO $conn, string $account_no): void {
    $account_no = trim($account_no);
    if ($account_no === '') {
        throw new RuntimeException('Payment / deposit account is required.');
    }
    $st = $conn->prepare("
        SELECT 1 FROM chart_of_accounts
        WHERE account_no = ?
          AND type = 'Asset'
          AND is_header = 0
          AND is_active = 1
          AND account_no <> '1110'
        LIMIT 1
    ");
    $st->execute([$account_no]);
    if (!$st->fetch()) {
        throw new RuntimeException('Invalid or inactive payment account: ' . $account_no);
    }
}
