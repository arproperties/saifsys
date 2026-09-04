<?php
/**
 * Phase 2A — Early termination wizard helpers.
 * Penalty never hardcoded; default months from co_shop_rental_settings.
 */

function co_shop_termination_penalty_preview(PDO $conn, int $companyId, array $contract, ?float $monthsOverride = null, ?float $customAmount = null): array {
    $settings = co_shop_get_settings($conn, $companyId);
    $standardMonths = (float)$settings['default_termination_penalty_months'];
    $monthly = co_shop_monthly_rent_equivalent($contract);
    $standardAmount = round($monthly * $standardMonths, 2);
    $months = $monthsOverride !== null ? max(0, $monthsOverride) : $standardMonths;
    $computed = round($monthly * $months, 2);
    $approved = $customAmount !== null ? max(0, round($customAmount, 2)) : $computed;
    $discount = max(0, round($standardAmount - $approved, 2));
    return [
        'monthly_equivalent' => $monthly,
        'standard_months' => $standardMonths,
        'standard_amount' => $standardAmount,
        'selected_months' => $months,
        'computed_amount' => $computed,
        'approved_amount' => $approved,
        'discount_amount' => $discount,
    ];
}

function co_shop_termination_snapshot(PDO $conn, int $companyId, int $contractId): array {
    $summary = co_shop_contract_financial_summary($conn, $companyId, $contractId);
    $contract = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $contract->execute([$contractId, $companyId]);
    $c = $contract->fetch(PDO::FETCH_ASSOC) ?: [];

    $pendingSched = 0;
    $pst = $conn->prepare("SELECT COUNT(*) FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ? AND status = 'pending'");
    $pst->execute([$companyId, $contractId]);
    $pendingSched = (int)$pst->fetchColumn();

    $futureCheques = 0;
    $cst = $conn->prepare("
        SELECT COUNT(*) FROM co_shop_rent_cheques
        WHERE company_id = ? AND contract_id = ? AND status IN ('received','deposited','bounced','returned')
    ");
    $cst->execute([$companyId, $contractId]);
    $futureCheques = (int)$cst->fetchColumn();

    $pendingRecog = 0;
    if (co_db_table_exists($conn, 'co_shop_rent_schedules')) {
        $rst = $conn->prepare("
            SELECT COUNT(*) FROM co_shop_rent_schedules
            WHERE company_id = ? AND contract_id = ? AND status = 'invoiced'
              AND id NOT IN (SELECT schedule_id FROM co_shop_rent_recognitions WHERE company_id = ? AND schedule_id IS NOT NULL)
        ");
        try {
            $rst->execute([$companyId, $contractId, $companyId]);
            $pendingRecog = (int)$rst->fetchColumn();
        } catch (Throwable $e) {
            $pendingRecog = 0;
        }
    }

    $insp = co_shop_latest_completed_inspection($conn, $companyId, $contractId);
    $settled = co_shop_get_finalized_deposit_settlement($conn, $companyId, $contractId);

    return [
        'contract' => $c,
        'financial' => $summary,
        'pending_schedules' => $pendingSched,
        'open_cheques' => $futureCheques,
        'pending_recognition' => $pendingRecog,
        'deposit_held' => (float)($c['deposit_received_amount'] ?? 0),
        'inspection' => $insp,
        'deposit_settled' => $settled,
        'commission' => $summary['commission'] ?? null,
        'penalty_preview' => co_shop_termination_penalty_preview($conn, $companyId, $c),
    ];
}

/**
 * Finalize early termination. No outer txn — posts penalty invoice separately.
 */
function co_shop_finalize_termination(
    PDO $conn,
    int $companyId,
    int $contractId,
    array $input,
    ?int $userId
): array {
    if (!co_shop_phase2a_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase2a.sql');
    }
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('Contract not found.');
    }
    if ($contract['status'] !== 'active') {
        throw new RuntimeException('Only active contracts can be terminated.');
    }
    $exist = $conn->prepare("
        SELECT id FROM co_shop_contract_terminations
        WHERE company_id = ? AND contract_id = ? AND status = 'finalized' LIMIT 1
    ");
    $exist->execute([$companyId, $contractId]);
    if ($exist->fetchColumn()) {
        throw new RuntimeException('Termination already finalized for this contract.');
    }

    $termDate = $input['termination_date'] ?? date('Y-m-d');
    $reason = trim((string)($input['reason'] ?? ''));
    if ($reason === '') {
        throw new RuntimeException('Termination reason is required.');
    }
    $months = isset($input['penalty_months']) ? (float)$input['penalty_months'] : null;
    $custom = isset($input['approved_penalty_amount']) && $input['approved_penalty_amount'] !== ''
        ? (float)$input['approved_penalty_amount']
        : null;
    $preview = co_shop_termination_penalty_preview($conn, $companyId, $contract, $months, $custom);
    $overrideReason = trim((string)($input['override_reason'] ?? ''));
    if (abs($preview['approved_amount'] - $preview['standard_amount']) > 0.005 && $overrideReason === '') {
        throw new RuntimeException('Override reason is required when approved penalty differs from standard.');
    }
    $method = trim((string)($input['settlement_method'] ?? 'bank_transfer'));

    $conn->prepare("
        INSERT INTO co_shop_contract_terminations
            (company_id, contract_id, termination_date, reason,
             standard_penalty_months, standard_penalty_amount, approved_penalty_amount, discount_amount,
             override_reason, approved_by, settlement_method, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'draft',?)
    ")->execute([
        $companyId, $contractId, $termDate, $reason,
        $preview['standard_months'], $preview['standard_amount'], $preview['approved_amount'], $preview['discount_amount'],
        $overrideReason ?: null, $userId, $method, $userId,
    ]);
    $termId = (int)$conn->lastInsertId();

    // Cancel future pending schedules
    $conn->prepare("
        UPDATE co_shop_rent_schedules SET status = 'cancelled'
        WHERE company_id = ? AND contract_id = ? AND status = 'pending'
    ")->execute([$companyId, $contractId]);

    // Cancel uncleared future cheques
    $conn->prepare("
        UPDATE co_shop_rent_cheques SET status = 'cancelled', lifecycle_note = 'Cancelled on early termination'
        WHERE company_id = ? AND contract_id = ?
          AND status IN ('received','deposited','bounced','returned')
    ")->execute([$companyId, $contractId]);

    $penaltyInvoiceId = null;
    if ($preview['approved_amount'] > 0.005) {
        $income = find_account_by_code(CO_ACCOUNT_SHOP_TERMINATION_PENALTY, $companyId);
        if (!$income) {
            throw new RuntimeException('Penalty income account 4150 not found. Run Setup Accounts.');
        }
        $invNo = co_next_document_number($conn, $companyId, 'SHOP-PEN', 'co_client_invoices', 'invoice_number');
        $penaltyInvoiceId = co_create_income_invoice(
            $conn,
            $companyId,
            (int)$contract['client_id'],
            0,
            'shop_termination_penalty',
            $contractId,
            $invNo,
            $termDate,
            $termDate,
            $preview['approved_amount'],
            0.0,
            'Early termination penalty ' . ($contract['contract_number'] ?? ''),
            (int)$income['id'],
            (int)$userId
        );
        $post = co_post_client_invoice_to_accounting($penaltyInvoiceId, $companyId, $userId);
        if (empty($post['success'])) {
            throw new RuntimeException($post['error'] ?? 'Penalty invoice posting failed.');
        }
        $conn->prepare("UPDATE co_client_invoices SET journal_id = ? WHERE id = ? AND company_id = ?")
            ->execute([(int)$post['journal_id'], $penaltyInvoiceId, $companyId]);
    }

    $conn->prepare("
        UPDATE co_shop_contract_terminations
        SET status = 'finalized', penalty_invoice_id = ?, finalized_at = NOW(), finalized_by = ?
        WHERE id = ? AND company_id = ?
    ")->execute([$penaltyInvoiceId, $userId, $termId, $companyId]);

    $conn->prepare("
        UPDATE co_shop_rental_contracts
        SET termination_reason = ?, terminated_at = NOW()
        WHERE id = ? AND company_id = ?
    ")->execute([$reason, $contractId, $companyId]);

    co_shop_set_contract_status($conn, $companyId, $contractId, 'terminated');
    co_shop_log_event($conn, $companyId, $contractId, 'terminated', [
        'termination_id' => $termId,
        'penalty_invoice_id' => $penaltyInvoiceId,
        'approved_penalty' => $preview['approved_amount'],
    ], $userId);

    return [
        'termination_id' => $termId,
        'penalty_invoice_id' => $penaltyInvoiceId,
        'penalty' => $preview,
    ];
}
