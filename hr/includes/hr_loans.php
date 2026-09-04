<?php
/**
 * Shared HR loans / salary advances helpers (all companies).
 * Evolves cash_advances into loan-style principal + settlements.
 */

require_once __DIR__ . '/hr_employee_lifecycle.php';

function hr_loans_schema_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM cash_advances LIKE 'remaining_balance'");
        $ready = $stmt && $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $ready = false;
    }
    return (bool)$ready;
}

function hr_loan_settlements_table_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'hr_loan_settlements'");
        $ready = $stmt && $stmt->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return (bool)$ready;
}

function hr_deduction_settlements_table_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'hr_deduction_settlements'");
        $ready = $stmt && $stmt->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return (bool)$ready;
}

/**
 * Initialize installment fields after approve / create open loan.
 */
function hr_loan_initialize_schedule(PDO $conn, int $loanId, float $principal, int $installmentCount = 1, ?string $startDate = null): void
{
    if (!hr_loans_schema_ready($conn) || $loanId <= 0 || $principal <= 0) {
        return;
    }
    $n = max(1, $installmentCount);
    $per = round($principal / $n, 2);
    $stmt = $conn->prepare("
        UPDATE cash_advances
           SET product_type = COALESCE(product_type, 'salary_advance'),
               principal = ?,
               installment_count = ?,
               installment_amount = ?,
               remaining_balance = ?,
               start_date = COALESCE(?, tx_date),
               status = 'open'
         WHERE id = ?
    ");
    $stmt->execute([$principal, $n, $per, $principal, $startDate, $loanId]);
}

function hr_loan_remaining(PDO $conn, int $loanId): float
{
    if (!hr_loans_schema_ready($conn)) {
        $st = $conn->prepare("SELECT amount FROM cash_advances WHERE id = ? AND status = 'open'");
        $st->execute([$loanId]);
        return (float)($st->fetchColumn() ?: 0);
    }
    $st = $conn->prepare("SELECT COALESCE(remaining_balance, amount) FROM cash_advances WHERE id = ?");
    $st->execute([$loanId]);
    return max(0.0, (float)($st->fetchColumn() ?: 0));
}

/**
 * Record a settlement and reduce remaining balance.
 * @return int settlement id
 */
function hr_loan_record_settlement(
    PDO $conn,
    int $loanId,
    float $amount,
    string $method,
    string $settleDate,
    ?int $userId,
    ?int $payrollRunId = null,
    ?int $payrollItemId = null,
    string $notes = ''
): int {
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Settlement amount must be positive.');
    }
    if (!in_array($method, ['payroll', 'cash'], true)) {
        throw new InvalidArgumentException('Invalid settlement method.');
    }

    $st = $conn->prepare("SELECT ca.*, e.company_id FROM cash_advances ca JOIN employees e ON e.id = ca.employee_id WHERE ca.id = ? FOR UPDATE");
    $st->execute([$loanId]);
    $loan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }
    if (($loan['status'] ?? '') === 'void') {
        throw new RuntimeException('Cannot settle a void loan.');
    }

    $remaining = hr_loans_schema_ready($conn)
        ? (float)($loan['remaining_balance'] ?? $loan['amount'])
        : (float)$loan['amount'];
    if ($amount > $remaining + 0.005) {
        throw new RuntimeException('Settlement exceeds remaining loan balance.');
    }

    $settlementId = 0;
    if (hr_loan_settlements_table_ready($conn)) {
        $ins = $conn->prepare("
            INSERT INTO hr_loan_settlements
              (loan_id, employee_id, company_id, settle_date, amount, method, payroll_run_id, payroll_item_id, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $loanId,
            (int)$loan['employee_id'],
            $loan['company_id'] !== null ? (int)$loan['company_id'] : null,
            $settleDate,
            $amount,
            $method,
            $payrollRunId,
            $payrollItemId,
            $notes !== '' ? $notes : null,
            $userId,
        ]);
        $settlementId = (int)$conn->lastInsertId();
    }

    $newRemaining = max(0.0, round($remaining - $amount, 2));
    if (hr_loans_schema_ready($conn)) {
        $upd = $conn->prepare("
            UPDATE cash_advances
               SET remaining_balance = ?,
                   status = IF(? <= 0.005, 'settled', 'open')
             WHERE id = ?
        ");
        $upd->execute([$newRemaining, $newRemaining, $loanId]);
    } elseif ($newRemaining <= 0.005) {
        $conn->prepare("UPDATE cash_advances SET status = 'settled' WHERE id = ?")->execute([$loanId]);
    }

    try {
        require_once __DIR__ . '/../../includes/AuditService.php';
        AuditService::logEvent([
            'action' => 'loan_settled',
            'module' => 'hr',
            'company_id' => $loan['company_id'] !== null ? (int)$loan['company_id'] : null,
            'object_type' => 'cash_advances',
            'object_id' => (string)$loanId,
            'object_ref' => 'Loan #' . $loanId,
            'summary' => 'Settled AED ' . number_format($amount, 2) . ' on loan #' . $loanId . ' via ' . $method,
            'new_data' => ['amount' => $amount, 'method' => $method, 'remaining' => $newRemaining],
            'user_id' => $userId,
            'source' => 'user',
            'success' => true,
        ]);
    } catch (Throwable $ignored) {
        // fail-soft
    }

    return $settlementId;
}

/**
 * Outstanding open loan balance for employee (approved / open status).
 */
function hr_loan_employee_outstanding(PDO $conn, int $employeeId): float
{
    if (hr_loans_schema_ready($conn)) {
        $st = $conn->prepare("
            SELECT COALESCE(SUM(COALESCE(remaining_balance, amount)),0)
            FROM cash_advances
            WHERE employee_id = ?
              AND status = 'open'
              AND (request_status IS NULL OR request_status = 'approved')
        ");
        $st->execute([$employeeId]);
        return (float)$st->fetchColumn();
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount),0) FROM cash_advances
        WHERE employee_id = ? AND status = 'open'
          AND (request_status IS NULL OR request_status = 'approved')
    ");
    $st->execute([$employeeId]);
    $given = (float)$st->fetchColumn();
    $st2 = $conn->prepare("
        SELECT COALESCE(SUM(pi.adv_applied),0)
        FROM payroll_items pi
        WHERE pi.employee_id = ? AND pi.adv_applied > 0
    ");
    $st2->execute([$employeeId]);
    return max(0.0, $given - (float)$st2->fetchColumn());
}

/**
 * Sync historical payroll advance deductions into hr_loan_settlements + remaining_balance.
 * Idempotent: skips payroll_items that already have settlement row(s) with the same payroll_item_id.
 *
 * Needed because older payrolls stored recovery only on payroll_items.adv_applied, while the
 * loans UI/outstanding now read remaining_balance / hr_loan_settlements.
 *
 * @return array{items:int,settlements:int,amount:float,skipped:int}
 */
function hr_loan_sync_payroll_settlements(PDO $conn, ?int $employeeId = null): array
{
    $stats = ['items' => 0, 'settlements' => 0, 'amount' => 0.0, 'skipped' => 0];
    if (!hr_loans_schema_ready($conn) || !hr_loan_settlements_table_ready($conn)) {
        return $stats;
    }

    $sql = "
        SELECT pi.id AS payroll_item_id, pi.employee_id, pi.adv_applied, pi.payroll_run_id,
               COALESCE(pr.period_to, pr.period_from, CURDATE()) AS settle_date, pr.status
        FROM payroll_items pi
        JOIN payroll_runs pr ON pr.id = pi.payroll_run_id
        WHERE pi.adv_applied > 0.005
          AND pr.status IN ('open', 'finalized', 'paid')
          AND NOT EXISTS (
              SELECT 1 FROM hr_loan_settlements s WHERE s.payroll_item_id = pi.id
          )
    ";
    $params = [];
    if ($employeeId !== null && $employeeId > 0) {
        $sql .= ' AND pi.employee_id = ?';
        $params[] = $employeeId;
    }
    $sql .= ' ORDER BY pi.employee_id ASC, settle_date ASC, pi.id ASC';

    $st = $conn->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        return $stats;
    }

    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        foreach ($items as $item) {
            $stats['items']++;
            $left = round((float)$item['adv_applied'], 2);
            if ($left <= 0.005) {
                $stats['skipped']++;
                continue;
            }
            $eid = (int)$item['employee_id'];
            $loanStmt = $conn->prepare("
                SELECT id FROM cash_advances
                WHERE employee_id = ?
                  AND status = 'open'
                  AND (request_status IS NULL OR request_status = 'approved')
                  AND COALESCE(remaining_balance, amount) > 0.005
                ORDER BY tx_date ASC, id ASC
                FOR UPDATE
            ");
            $loanStmt->execute([$eid]);
            $loanIds = $loanStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$loanIds) {
                // No open remaining — still create a zero-effect skip count for visibility
                $stats['skipped']++;
                continue;
            }
            foreach ($loanIds as $loanId) {
                if ($left <= 0.005) {
                    break;
                }
                $rem = hr_loan_remaining($conn, (int)$loanId);
                if ($rem <= 0.005) {
                    continue;
                }
                $take = min($left, $rem);
                hr_loan_record_settlement(
                    $conn,
                    (int)$loanId,
                    $take,
                    'payroll',
                    (string)$item['settle_date'],
                    null,
                    (int)$item['payroll_run_id'],
                    (int)$item['payroll_item_id'],
                    'Synced from payroll run #' . (int)$item['payroll_run_id'] . ' (historical adv_applied)'
                );
                $left = round($left - $take, 2);
                $stats['settlements']++;
                $stats['amount'] = round($stats['amount'] + $take, 2);
            }
            if ($left > 0.005) {
                // Payroll applied more than current remaining loan balances — leave remainder unlinked.
                $stats['skipped']++;
            }
        }
        if ($ownTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    return $stats;
}

function hr_loan_history_rows(PDO $conn, int $employeeId): array
{
    $rows = [];
    $loans = $conn->prepare("
        SELECT ca.*, c.name AS company_name
        FROM cash_advances ca
        JOIN employees e ON e.id = ca.employee_id
        LEFT JOIN companies c ON c.id = e.company_id
        WHERE ca.employee_id = ?
        ORDER BY ca.tx_date DESC, ca.id DESC
    ");
    $loans->execute([$employeeId]);
    foreach ($loans->fetchAll(PDO::FETCH_ASSOC) as $loan) {
        $rows[] = [
            'type' => 'loan_issue',
            'date' => $loan['tx_date'],
            'label' => 'Loan / advance issued',
            'amount' => (float)($loan['principal'] ?? $loan['amount']),
            'meta' => $loan,
        ];
    }

    $seenPayrollItems = [];
    if (hr_loan_settlements_table_ready($conn)) {
        $st = $conn->prepare("
            SELECT s.*, ca.description
            FROM hr_loan_settlements s
            JOIN cash_advances ca ON ca.id = s.loan_id
            WHERE s.employee_id = ?
            ORDER BY s.settle_date DESC, s.id DESC
        ");
        $st->execute([$employeeId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if (!empty($s['payroll_item_id'])) {
                $seenPayrollItems[(int)$s['payroll_item_id']] = true;
            }
            $rows[] = [
                'type' => 'loan_settlement',
                'date' => $s['settle_date'],
                'label' => 'Settled via ' . $s['method'],
                'amount' => (float)$s['amount'],
                'meta' => $s,
            ];
        }
    }

    // Also surface legacy payroll_items.adv_applied not yet synced into hr_loan_settlements.
    $st = $conn->prepare("
        SELECT pi.id AS payroll_item_id, pi.adv_applied AS amount, pr.period_from, pr.period_to, pr.id AS payroll_run_id
        FROM payroll_items pi
        JOIN payroll_runs pr ON pr.id = pi.payroll_run_id
        WHERE pi.employee_id = ? AND pi.adv_applied > 0
          AND pr.status IN ('open', 'finalized', 'paid')
        ORDER BY pr.period_from DESC
    ");
    $st->execute([$employeeId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $piId = (int)$s['payroll_item_id'];
        if (isset($seenPayrollItems[$piId])) {
            continue;
        }
        $rows[] = [
            'type' => 'loan_settlement',
            'date' => $s['period_from'],
            'label' => 'Settled via payroll (legacy — run Sync to update outstanding)',
            'amount' => (float)$s['amount'],
            'meta' => $s,
        ];
    }

    usort($rows, static function ($a, $b) {
        return strcmp((string)$b['date'], (string)$a['date']);
    });
    return $rows;
}

/**
 * List payroll other_applied lines not yet linked to hr_deduction_settlements.
 *
 * @return list<array{
 *   payroll_item_id:int,payroll_run_id:int,employee_id:int,employee_code:string,employee_name:string,
 *   company_name:string,period_from:?string,period_to:?string,other_applied:float,
 *   open_remaining:float,reason:string
 * }>
 */
function hr_deduction_sync_unlinked_lines(PDO $conn, ?int $employeeId = null): array
{
    $hasRemaining = false;
    try {
        $chk = $conn->query("SHOW COLUMNS FROM employee_deductions LIKE 'remaining_balance'");
        $hasRemaining = $chk && $chk->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasRemaining = false;
    }
    if (!$hasRemaining || !hr_deduction_settlements_table_ready($conn)) {
        return [];
    }

    $sql = "
        SELECT pi.id AS payroll_item_id,
               pi.employee_id,
               pi.other_applied,
               pi.payroll_run_id,
               pr.period_from,
               pr.period_to,
               pr.status AS run_status,
               e.employee_code,
               e.full_name AS employee_name,
               COALESCE(c.name, '') AS company_name,
               COALESCE((
                   SELECT SUM(COALESCE(ed.remaining_balance, ed.amount))
                   FROM employee_deductions ed
                   WHERE ed.employee_id = pi.employee_id
                     AND COALESCE(ed.settle_status, 'open') = 'open'
                     AND COALESCE(ed.remaining_balance, ed.amount) > 0.005
               ), 0) AS open_remaining
        FROM payroll_items pi
        JOIN payroll_runs pr ON pr.id = pi.payroll_run_id
        JOIN employees e ON e.id = pi.employee_id
        LEFT JOIN companies c ON c.id = e.company_id
        WHERE pi.other_applied > 0.005
          AND pr.status IN ('open', 'posted', 'finalized', 'paid')
          AND NOT EXISTS (
              SELECT 1 FROM hr_deduction_settlements s WHERE s.payroll_item_id = pi.id
          )
    ";
    $params = [];
    if ($employeeId !== null && $employeeId > 0) {
        $sql .= ' AND pi.employee_id = ?';
        $params[] = $employeeId;
    }
    $sql .= ' ORDER BY pi.other_applied DESC, e.full_name ASC, pi.id ASC';

    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $applied = round((float)$r['other_applied'], 2);
        $openRem = round((float)$r['open_remaining'], 2);
        if ($openRem <= 0.005) {
            $reason = 'No open fine/deduction remaining for this employee (none issued, already settled, or deleted).';
        } elseif ($openRem + 0.005 < $applied) {
            $reason = 'Open remaining (AED ' . number_format($openRem, 2)
                . ') is less than payroll other_applied (AED ' . number_format($applied, 2) . ').';
        } else {
            $reason = 'Ready to sync (open remaining covers this payroll amount).';
        }
        $rows[] = [
            'payroll_item_id' => (int)$r['payroll_item_id'],
            'payroll_run_id' => (int)$r['payroll_run_id'],
            'employee_id' => (int)$r['employee_id'],
            'employee_code' => (string)($r['employee_code'] ?? ''),
            'employee_name' => (string)($r['employee_name'] ?? ''),
            'company_name' => (string)($r['company_name'] ?? ''),
            'period_from' => $r['period_from'] ?? null,
            'period_to' => $r['period_to'] ?? null,
            'other_applied' => $applied,
            'open_remaining' => $openRem,
            'reason' => $reason,
        ];
    }
    return $rows;
}

/**
 * Sync historical payroll other-deduction recoveries into hr_deduction_settlements + remaining_balance.
 * Idempotent: skips payroll_items that already have settlement row(s) with the same payroll_item_id.
 *
 * Needed because older payrolls stored recovery only on payroll_items.other_applied, while the
 * deductions UI / Available amount now read remaining_balance / hr_deduction_settlements.
 *
 * @return array{items:int,settlements:int,amount:float,skipped:int,skipped_details:list<array>}
 */
function hr_deduction_sync_payroll_settlements(PDO $conn, ?int $employeeId = null): array
{
    $stats = [
        'items' => 0,
        'settlements' => 0,
        'amount' => 0.0,
        'skipped' => 0,
        'skipped_details' => [],
    ];

    $hasRemaining = false;
    try {
        $chk = $conn->query("SHOW COLUMNS FROM employee_deductions LIKE 'remaining_balance'");
        $hasRemaining = $chk && $chk->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasRemaining = false;
    }
    if (!$hasRemaining || !hr_deduction_settlements_table_ready($conn)) {
        return $stats;
    }

    $sql = "
        SELECT pi.id AS payroll_item_id, pi.employee_id, pi.other_applied, pi.payroll_run_id,
               COALESCE(pr.period_to, pr.period_from, CURDATE()) AS settle_date,
               pr.period_from, pr.period_to, pr.status,
               e.employee_code, e.full_name AS employee_name
        FROM payroll_items pi
        JOIN payroll_runs pr ON pr.id = pi.payroll_run_id
        JOIN employees e ON e.id = pi.employee_id
        WHERE pi.other_applied > 0.005
          AND pr.status IN ('open', 'posted', 'finalized', 'paid')
          AND NOT EXISTS (
              SELECT 1 FROM hr_deduction_settlements s WHERE s.payroll_item_id = pi.id
          )
    ";
    $params = [];
    if ($employeeId !== null && $employeeId > 0) {
        $sql .= ' AND pi.employee_id = ?';
        $params[] = $employeeId;
    }
    $sql .= ' ORDER BY pi.employee_id ASC, settle_date ASC, pi.id ASC';

    $st = $conn->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        return $stats;
    }

    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        foreach ($items as $item) {
            $stats['items']++;
            $left = round((float)$item['other_applied'], 2);
            $detailBase = [
                'payroll_item_id' => (int)$item['payroll_item_id'],
                'payroll_run_id' => (int)$item['payroll_run_id'],
                'employee_id' => (int)$item['employee_id'],
                'employee_code' => (string)($item['employee_code'] ?? ''),
                'employee_name' => (string)($item['employee_name'] ?? ''),
                'period_from' => $item['period_from'] ?? null,
                'period_to' => $item['period_to'] ?? null,
                'other_applied' => $left,
                'applied_now' => 0.0,
            ];
            if ($left <= 0.005) {
                $stats['skipped']++;
                $stats['skipped_details'][] = $detailBase + [
                    'reason' => 'Payroll other_applied is zero.',
                ];
                continue;
            }
            $eid = (int)$item['employee_id'];
            $dedStmt = $conn->prepare("
                SELECT id FROM employee_deductions
                WHERE employee_id = ?
                  AND COALESCE(settle_status, 'open') = 'open'
                  AND COALESCE(remaining_balance, amount) > 0.005
                ORDER BY tx_date ASC, id ASC
                FOR UPDATE
            ");
            $dedStmt->execute([$eid]);
            $dedIds = $dedStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$dedIds) {
                $stats['skipped']++;
                $stats['skipped_details'][] = $detailBase + [
                    'reason' => 'No open fine/deduction remaining for this employee (none issued, already settled, or deleted).',
                ];
                continue;
            }
            $appliedNow = 0.0;
            foreach ($dedIds as $dedId) {
                if ($left <= 0.005) {
                    break;
                }
                $stRem = $conn->prepare("SELECT COALESCE(remaining_balance, amount) FROM employee_deductions WHERE id = ?");
                $stRem->execute([(int)$dedId]);
                $rem = (float)$stRem->fetchColumn();
                if ($rem <= 0.005) {
                    continue;
                }
                $take = min($left, $rem);
                hr_deduction_record_settlement(
                    $conn,
                    (int)$dedId,
                    $take,
                    'payroll',
                    (string)$item['settle_date'],
                    null,
                    (int)$item['payroll_run_id'],
                    (int)$item['payroll_item_id'],
                    'Synced from payroll run #' . (int)$item['payroll_run_id'] . ' (historical other_applied)'
                );
                $left = round($left - $take, 2);
                $appliedNow = round($appliedNow + $take, 2);
                $stats['settlements']++;
                $stats['amount'] = round($stats['amount'] + $take, 2);
            }
            if ($left > 0.005) {
                $stats['skipped']++;
                $stats['skipped_details'][] = $detailBase + [
                    'applied_now' => $appliedNow,
                    'reason' => $appliedNow > 0.005
                        ? ('Partially synced AED ' . number_format($appliedNow, 2)
                            . '; leftover AED ' . number_format($left, 2)
                            . ' has no matching open fine balance.')
                        : ('Open fine remaining was insufficient for payroll other_applied AED '
                            . number_format((float)$item['other_applied'], 2) . '.'),
                ];
            }
        }
        if ($ownTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    return $stats;
}

function hr_deduction_record_settlement(
    PDO $conn,
    int $deductionId,
    float $amount,
    string $method,
    string $settleDate,
    ?int $userId,
    ?int $payrollRunId = null,
    ?int $payrollItemId = null,
    string $notes = ''
): int {
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Settlement amount must be positive.');
    }

    $st = $conn->prepare("
        SELECT ed.*, e.company_id
        FROM employee_deductions ed
        JOIN employees e ON e.id = ed.employee_id
        WHERE ed.id = ?
        FOR UPDATE
    ");
    $st->execute([$deductionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Deduction not found.');
    }

    $hasRemaining = false;
    try {
        $chk = $conn->query("SHOW COLUMNS FROM employee_deductions LIKE 'remaining_balance'");
        $hasRemaining = $chk && $chk->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasRemaining = false;
    }

    $remaining = $hasRemaining
        ? (float)($row['remaining_balance'] ?? $row['amount'])
        : (float)$row['amount'];
    if ($amount > $remaining + 0.005) {
        throw new RuntimeException('Settlement exceeds remaining deduction balance.');
    }

    $settlementId = 0;
    if (hr_deduction_settlements_table_ready($conn)) {
        $ins = $conn->prepare("
            INSERT INTO hr_deduction_settlements
              (deduction_id, employee_id, company_id, settle_date, amount, method, payroll_run_id, payroll_item_id, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $deductionId,
            (int)$row['employee_id'],
            $row['company_id'] !== null ? (int)$row['company_id'] : null,
            $settleDate,
            $amount,
            $method,
            $payrollRunId,
            $payrollItemId,
            $notes !== '' ? $notes : null,
            $userId,
        ]);
        $settlementId = (int)$conn->lastInsertId();
    }

    $newRemaining = max(0.0, round($remaining - $amount, 2));
    if ($hasRemaining) {
        $upd = $conn->prepare("
            UPDATE employee_deductions
               SET remaining_balance = ?,
                   settle_status = IF(? <= 0.005, 'settled', 'open')
             WHERE id = ?
        ");
        $upd->execute([$newRemaining, $newRemaining, $deductionId]);
    }

    try {
        require_once __DIR__ . '/../../includes/AuditService.php';
        AuditService::logEvent([
            'action' => 'deduction_settled',
            'module' => 'hr',
            'company_id' => $row['company_id'] !== null ? (int)$row['company_id'] : null,
            'object_type' => 'employee_deductions',
            'object_id' => (string)$deductionId,
            'object_ref' => 'Deduction #' . $deductionId,
            'summary' => 'Settled AED ' . number_format($amount, 2) . ' on deduction #' . $deductionId
                . ' via ' . $method
                . ' (remaining AED ' . number_format($newRemaining, 2) . ')',
            'new_data' => [
                'amount' => $amount,
                'method' => $method,
                'remaining' => $newRemaining,
                'settlement_id' => $settlementId > 0 ? $settlementId : null,
                'employee_id' => (int)$row['employee_id'],
            ],
            'user_id' => $userId,
            'source' => 'user',
            'success' => true,
        ]);
    } catch (Throwable $ignored) {
    }

    return $settlementId;
}
