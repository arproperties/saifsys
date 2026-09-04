<?php
/**
 * Phase 2A — Deposit settlement. Accounting only on finalize.
 * Deductions require completed move-out inspection.
 */

function co_shop_deposit_held(PDO $conn, int $companyId, int $contractId): float {
    $stmt = $conn->prepare("SELECT deposit_received_amount FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    return max(0, round((float)$stmt->fetchColumn(), 2));
}

function co_shop_get_finalized_deposit_settlement(PDO $conn, int $companyId, int $contractId): ?array {
    $stmt = $conn->prepare("
        SELECT * FROM co_shop_deposit_settlements
        WHERE company_id = ? AND contract_id = ? AND status = 'finalized'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$companyId, $contractId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Post deposit settlement journal. No outer txn required beyond caller.
 * Dr 2200; Cr bank (refund), Cr 4160 (recoveries), Cr 1310 (apply to AR).
 */
function co_post_shop_deposit_settlement_to_accounting(
    int $companyId,
    int $contractId,
    int $settlementId,
    float $refundAmount,
    float $recoveryAmount,
    float $applyToArAmount,
    ?int $payAccountId,
    string $settlementDate,
    ?int $createdBy
): array {
    global $conn;
    $stmt = $conn->prepare("
        SELECT c.contract_number, cl.client_name
        FROM co_shop_rental_contracts c
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE c.id = ? AND c.company_id = ?
    ");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Contract not found'];
    }

    $depositAccount = find_account_by_code(CO_ACCOUNT_SECURITY_DEPOSITS, $companyId);
    $recoveryAccount = find_account_by_code(CO_ACCOUNT_DEPOSIT_RECOVERY_INCOME, $companyId);
    $arAccount = find_account_by_code(CO_ACCOUNT_AR, $companyId);
    $bankAccount = null;
    if ($payAccountId) {
        $accountStmt = $conn->prepare("
            SELECT id, account_code, account_name, account_type, normal_balance
            FROM re_chart_of_accounts
            WHERE id = ? AND company_id = ? AND is_active = 1 AND is_header = 0 LIMIT 1
        ");
        $accountStmt->execute([(int)$payAccountId, $companyId]);
        $bankAccount = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($refundAmount > 0.005 && !$bankAccount) {
        $bankAccount = find_account_by_code(CO_ACCOUNT_BANK, $companyId) ?: find_account_by_code(CO_ACCOUNT_CASH, $companyId);
    }
    if (!$depositAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Security deposit account 2200 not found.'];
    }
    if ($recoveryAmount > 0.005 && !$recoveryAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Deposit recovery income 4160 not found. Run Setup Accounts.'];
    }
    if ($applyToArAmount > 0.005 && !$arAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'AR account 1310 not found.'];
    }
    if ($refundAmount > 0.005 && !$bankAccount) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Refund bank/cash account required.'];
    }

    $totalDebit = round($refundAmount + $recoveryAmount + $applyToArAmount, 2);
    if ($totalDebit <= 0.005) {
        return ['success' => false, 'journal_id' => null, 'error' => 'Settlement total must be greater than zero.'];
    }

    $desc = 'Shop deposit settlement: ' . $contract['contract_number'] . ' - ' . $contract['client_name'];
    $ref = 'CO-SHOP-DEP-SET-' . $settlementId;
    $lines = [
        ['account_id' => $depositAccount['id'], 'debit' => $totalDebit, 'credit' => 0, 'description' => $desc, 'reference' => $ref],
    ];
    if ($refundAmount > 0.005) {
        $lines[] = ['account_id' => $bankAccount['id'], 'debit' => 0, 'credit' => $refundAmount, 'description' => $desc . ' (refund)', 'reference' => $ref];
    }
    if ($recoveryAmount > 0.005) {
        $lines[] = ['account_id' => $recoveryAccount['id'], 'debit' => 0, 'credit' => $recoveryAmount, 'description' => $desc . ' (recovery)', 'reference' => $ref];
    }
    if ($applyToArAmount > 0.005) {
        $lines[] = ['account_id' => $arAccount['id'], 'debit' => 0, 'credit' => $applyToArAmount, 'description' => $desc . ' (apply to AR)', 'reference' => $ref];
    }

    return create_and_post_journal($companyId, 'adjustment', 'co_shop_deposit_settlement', $settlementId, $lines, $desc, $settlementDate, $createdBy);
}

/**
 * Finalize deposit settlement. Inspection required when any recovery deduction > 0.
 * @param array $input refund/damage/utility/cleaning/forfeit/apply_to_invoices amounts
 */
function co_shop_finalize_deposit_settlement(
    PDO $conn,
    int $companyId,
    int $contractId,
    array $input,
    ?int $userId
): array {
    if (!co_shop_phase2a_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase2a.sql');
    }
    if (co_shop_get_finalized_deposit_settlement($conn, $companyId, $contractId)) {
        throw new RuntimeException('Deposit already settled for this contract. Duplicate settlement is not allowed.');
    }

    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('Contract not found.');
    }

    $held = max(0, round((float)$contract['deposit_received_amount'], 2));
    if ($held <= 0.005) {
        throw new RuntimeException('No security deposit held to settle.');
    }

    $refund = max(0, round((float)($input['refund_amount'] ?? 0), 2));
    $damage = max(0, round((float)($input['damage_amount'] ?? 0), 2));
    $utility = max(0, round((float)($input['utility_amount'] ?? 0), 2));
    $cleaning = max(0, round((float)($input['cleaning_amount'] ?? 0), 2));
    $forfeit = max(0, round((float)($input['forfeit_amount'] ?? 0), 2));
    $applyAr = max(0, round((float)($input['apply_to_invoices_amount'] ?? 0), 2));
    // missing keys treated as damage recovery for GL (4160)
    $missingKeys = max(0, round((float)($input['missing_keys_amount'] ?? 0), 2));
    $damage = round($damage + $missingKeys, 2);

    $total = round($refund + $damage + $utility + $cleaning + $forfeit + $applyAr, 2);
    if ($total <= 0.005) {
        throw new RuntimeException('Enter at least one settlement amount.');
    }
    if ($total > $held + 0.005) {
        throw new RuntimeException('Settlement total (' . number_format($total, 2) . ') exceeds deposit held (' . number_format($held, 2) . ').');
    }

    $recovery = round($damage + $utility + $cleaning + $forfeit, 2);
    $inspectionId = (int)($input['inspection_id'] ?? 0) ?: null;
    if ($recovery > 0.005) {
        if (!$inspectionId) {
            $latest = co_shop_latest_completed_inspection($conn, $companyId, $contractId);
            $inspectionId = $latest ? (int)$latest['id'] : null;
        }
        if (!$inspectionId) {
            throw new RuntimeException('Complete a move-out inspection before applying damage/cleaning/utility deductions.');
        }
        $insp = co_shop_get_inspection($conn, $companyId, $inspectionId);
        if (!$insp || $insp['status'] !== 'completed' || (int)$insp['contract_id'] !== $contractId) {
            throw new RuntimeException('A completed move-out inspection is required for deposit deductions.');
        }
    }

    $date = $input['settlement_date'] ?? date('Y-m-d');
    $payAccountId = (int)($input['pay_account_id'] ?? 0) ?: null;
    if ($refund > 0.005 && !$payAccountId) {
        throw new RuntimeException('Select the bank/cash account for the deposit refund.');
    }
    $notes = trim((string)($input['notes'] ?? ''));

    $conn->prepare("
        INSERT INTO co_shop_deposit_settlements
            (company_id, contract_id, inspection_id, settlement_date, deposit_held,
             refund_amount, damage_amount, utility_amount, cleaning_amount, forfeit_amount,
             apply_to_invoices_amount, pay_account_id, notes, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)
    ")->execute([
        $companyId, $contractId, $inspectionId, $date, $held,
        $refund, $damage, $utility, $cleaning, $forfeit, $applyAr,
        $payAccountId, $notes ?: null, $userId,
    ]);
    $settlementId = (int)$conn->lastInsertId();

    $post = co_post_shop_deposit_settlement_to_accounting(
        $companyId,
        $contractId,
        $settlementId,
        $refund,
        $recovery,
        $applyAr,
        $payAccountId,
        $date,
        $userId
    );
    if (empty($post['success'])) {
        $conn->prepare("DELETE FROM co_shop_deposit_settlements WHERE id = ? AND company_id = ? AND status = 'draft'")
            ->execute([$settlementId, $companyId]);
        throw new RuntimeException($post['error'] ?? 'Deposit settlement posting failed.');
    }

    $conn->prepare("
        UPDATE co_shop_deposit_settlements
        SET status = 'finalized', journal_id = ?, finalized_at = NOW(), finalized_by = ?
        WHERE id = ? AND company_id = ?
    ")->execute([(int)$post['journal_id'], $userId, $settlementId, $companyId]);

    $remaining = max(0, round($held - $total, 2));
    $conn->prepare("UPDATE co_shop_rental_contracts SET deposit_received_amount = ? WHERE id = ? AND company_id = ?")
        ->execute([$remaining, $contractId, $companyId]);

    // Optional: allocate apply-to-AR as credit toward open invoices (FIFO)
    if ($applyAr > 0.005) {
        co_shop_apply_deposit_credit_to_open_invoices($conn, $companyId, $contractId, (int)$contract['client_id'], $applyAr, $userId);
    }

    co_shop_log_event($conn, $companyId, $contractId, 'deposit_settled', [
        'settlement_id' => $settlementId,
        'journal_id' => (int)$post['journal_id'],
        'refund' => $refund,
        'recovery' => $recovery,
        'apply_ar' => $applyAr,
        'remaining_held' => $remaining,
    ], $userId);

    return [
        'settlement_id' => $settlementId,
        'journal_id' => (int)$post['journal_id'],
        'remaining_held' => $remaining,
    ];
}

/**
 * Soft-apply deposit-to-AR portion by allocating client credit then applying to open shop invoices.
 * The GL already credited AR; we record payment allocations for invoice status without double-posting bank.
 */
function co_shop_apply_deposit_credit_to_open_invoices(
    PDO $conn,
    int $companyId,
    int $contractId,
    int $clientId,
    float $amount,
    ?int $userId
): void {
    // Prefer open rent + commission invoices for this contract
    $invStmt = $conn->prepare("
        SELECT i.id, i.total_amount,
               COALESCE((SELECT SUM(a.allocated_amount) FROM co_client_payment_allocations a
                         WHERE a.invoice_id = i.id AND a.company_id = i.company_id), 0) AS paid
        FROM co_client_invoices i
        WHERE i.company_id = ? AND i.client_id = ? AND i.status <> 'cancelled'
          AND (
            (i.source_type = 'shop_rental' AND i.source_id IN (
                SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?
            ))
            OR (i.source_type IN ('shop_commission','shop_termination_penalty') AND i.source_id = ?)
          )
        ORDER BY i.due_date ASC, i.id ASC
    ");
    $invStmt->execute([$companyId, $clientId, $contractId, $companyId, $contractId]);
    $left = $amount;
    foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
        if ($left <= 0.005) {
            break;
        }
        $open = max(0, round((float)$inv['total_amount'] - (float)$inv['paid'], 2));
        if ($open <= 0.005) {
            continue;
        }
        $apply = min($left, $open);
        // Allocation-only payment row (no second bank journal) — reference marks deposit apply
        $payStmt = $conn->prepare("
            INSERT INTO co_client_payments
                (company_id, invoice_id, client_id, payment_date, amount, pay_account_id, reference, created_by)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $payStmt->execute([
            $companyId, (int)$inv['id'], $clientId, date('Y-m-d'), $apply, null,
            'DEPOSIT-APPLY', $userId,
        ]);
        $paymentId = (int)$conn->lastInsertId();
        if (function_exists('co_allocate_client_payment')) {
            co_allocate_client_payment($conn, $companyId, $clientId, $paymentId, (int)$inv['id'], $apply);
        }
        if (function_exists('co_update_client_invoice_status')) {
            co_update_client_invoice_status($conn, $companyId, (int)$inv['id']);
        }
        $left = round($left - $apply, 2);
    }
}
