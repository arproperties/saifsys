<?php
/**
 * Construction bank reconciliation — create transactions from statement lines.
 */
declare(strict_types=1);

require_once __DIR__ . '/construction_bank_reconciliation.php';
require_once __DIR__ . '/construction_accounting_integration.php';
require_once __DIR__ . '/construction_bank_reco_engine.php';

/**
 * @param list<array{account_id:int,amount:float,description?:string,project_id?:int}> $splits
 * @return array{success:bool,journal_id:?int,gl_line_ids:list<int>,error:?string}
 */
function co_bank_reco_create_transaction(
    PDO $conn,
    int $companyId,
    array $line,
    string $transactionType,
    int $offsetAccountId,
    string $description,
    ?string $reference,
    ?int $projectId,
    ?int $userId,
    array $splits = [],
    ?string $contactType = null,
    ?int $contactId = null,
    string $matchMethod = 'create',
    string $vatTreatment = 'none',
    ?float $vatRate = null
): array {
    $bankGlId = (int) ($line['gl_account_id'] ?? 0);
    $bankAccountId = (int) ($line['bank_account_id'] ?? 0);
    $lineId = (int) ($line['id'] ?? 0);
    $amount = co_bank_reco_money(abs((float) ($line['amount'] ?? 0)));
    $isSpent = (float) ($line['amount'] ?? 0) < 0;
    $txnDate = (string) ($line['txn_date'] ?? date('Y-m-d'));

    if ($bankGlId <= 0 || $lineId <= 0 || $amount <= 0) {
        return ['success' => false, 'journal_id' => null, 'gl_line_ids' => [], 'error' => 'Invalid statement line'];
    }
    if (co_bank_is_period_locked($conn, $bankAccountId, $txnDate)) {
        return ['success' => false, 'journal_id' => null, 'gl_line_ids' => [], 'error' => 'Period is locked'];
    }
    if (co_bank_line_remaining($conn, $line) <= 0.009) {
        return ['success' => false, 'journal_id' => null, 'gl_line_ids' => [], 'error' => 'Statement line already reconciled'];
    }

    $contact = co_bank_reco_resolve_contact($conn, $companyId, $contactType, $contactId);
    $description = co_bank_reco_description_with_contact($description, $contact['name'], $transactionType);

    $journalLines = [];
    $splitTotal = 0.0;
    $ref = $reference ?: ('CO-BR-' . $lineId);
    $effectiveVatRate = null;

    if ($splits) {
        foreach ($splits as $split) {
            $splitAmt = co_bank_reco_money((float) ($split['amount'] ?? 0));
            if ($splitAmt <= 0) {
                continue;
            }
            $splitTotal += $splitAmt;
            $acct = (int) ($split['account_id'] ?? 0);
            if ($acct <= 0) {
                return ['success' => false, 'journal_id' => null, 'gl_line_ids' => [], 'error' => 'Split line missing account'];
            }
            if ($isSpent) {
                $journalLines[] = ['account_id' => $acct, 'debit' => $splitAmt, 'credit' => 0, 'description' => $split['description'] ?? $description, 'reference' => $ref];
            } else {
                $journalLines[] = ['account_id' => $acct, 'debit' => 0, 'credit' => $splitAmt, 'description' => $split['description'] ?? $description, 'reference' => $ref];
            }
        }
        if (abs($splitTotal - $amount) > 0.02) {
            return ['success' => false, 'journal_id' => null, 'gl_line_ids' => [], 'error' => 'Split lines must equal the bank line amount'];
        }
        if ($isSpent) {
            $journalLines[] = ['account_id' => $bankGlId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => $ref];
        } else {
            $journalLines[] = ['account_id' => $bankGlId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => $ref];
        }
    } else {
        if ($offsetAccountId <= 0) {
            if ($transactionType === 'cash_withdrawal') {
                $cash = find_account_by_code(CO_ACCOUNT_CASH, $companyId);
                $offsetAccountId = $cash ? (int) $cash['id'] : 0;
            }
            if ($offsetAccountId <= 0) {
                return ['success' => false, 'journal_id' => null, 'gl_line_ids' => [], 'error' => 'Select an account or configure Cash on Hand (1110)'];
            }
        }
        $vatCfg = co_bank_reco_vat_config($conn, $companyId);
        $vatTreatment = in_array($vatTreatment, ['none', 'standard', 'exempt', 'zero_rated', 'out_of_scope'], true) ? $vatTreatment : 'none';
        $effectiveVatRate = ($vatTreatment === 'standard') ? ($vatRate ?? $vatCfg['default_rate']) : 0.0;
        if ($vatTreatment === 'standard' && $effectiveVatRate <= 0) {
            $effectiveVatRate = (float) $vatCfg['default_rate'];
        }
        try {
            $journalLines = co_bank_reco_build_create_journal_lines(
                $offsetAccountId,
                $bankGlId,
                $amount,
                $isSpent,
                $description,
                $ref,
                $vatTreatment,
                (float) $effectiveVatRate,
                $vatCfg['input_vat_account_id'],
                $vatCfg['output_vat_account_id']
            );
        } catch (RuntimeException $e) {
            return ['success' => false, 'journal_id' => null, 'gl_line_ids' => [], 'error' => $e->getMessage()];
        }
    }

    try {
        $ownsTxn = !$conn->inTransaction();
        if ($ownsTxn) {
            $conn->beginTransaction();
        }

        $result = create_and_post_journal(
            $companyId,
            'manual',
            'co_bank_reconciliation',
            $lineId,
            $journalLines,
            $description ?: ('Bank reconciliation create: line #' . $lineId),
            $txnDate,
            $userId
        );
        if (empty($result['success'])) {
            throw new RuntimeException($result['error'] ?? 'Journal posting failed');
        }
        $journalId = (int) ($result['journal_id'] ?? 0);

        if ($transactionType === 'direct_project_expense' && $projectId && co_db_table_exists($conn, 'co_project_costs')) {
            $ins = $conn->prepare('
                INSERT INTO co_project_costs (company_id, project_id, cost_type, description, amount, cost_date, reference, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $ins->execute([
                $companyId,
                $projectId,
                'bank_reconciliation',
                $description,
                $amount,
                $txnDate,
                $ref,
                $userId ?: null,
            ]);
        }

        $glLineIds = [];
        $amountCol = $isSpent ? 'credit_amount' : 'debit_amount';
        $st = $conn->prepare("
            SELECT id FROM re_general_ledger
            WHERE journal_id = ? AND company_id = ? AND account_id = ? AND {$amountCol} > 0
            ORDER BY id DESC LIMIT 1
        ");
        $st->execute([$journalId, $companyId, $bankGlId]);
        $bankLineGlId = (int) ($st->fetchColumn() ?: 0);
        if ($bankLineGlId <= 0) {
            throw new RuntimeException('Could not locate bank GL line for reconciliation link');
        }
        $glLineIds[] = $bankLineGlId;

        $systemType = $isSpent ? 'gl_outflow' : 'gl_inflow';
        $matchCols = '(bank_statement_line_id, system_type, system_id, amount_matched, status, period_lock_key, created_by';
        $matchVals = [$lineId, $systemType, $bankLineGlId, $amount, 'confirmed', null, $userId ?: null];
        if (co_db_column_exists($conn, 'co_reconciliation_matches', 'company_id')) {
            $matchCols .= ', company_id, bank_account_id, match_method, created_transaction_id';
            $method = in_array($matchMethod, ['create', 'cash_coding', 'rule'], true) ? $matchMethod : 'create';
            $matchVals = array_merge($matchVals, [$companyId, $bankAccountId, $method, $journalId]);
        }
        $matchCols .= ') VALUES (' . implode(',', array_fill(0, count($matchVals), '?')) . ')';
        $conn->prepare('INSERT INTO co_reconciliation_matches ' . $matchCols)->execute($matchVals);
        $matchId = (int) $conn->lastInsertId();

        co_bank_sync_gl_reconciled_flag($conn, $bankLineGlId, $companyId, $userId);
        co_bank_refresh_line_status($conn, $lineId, $companyId);
        co_bank_reco_audit($conn, $companyId, $bankAccountId, $lineId, $matchId, 'create_transaction', null, [
            'transaction_type' => $transactionType,
            'journal_id' => $journalId,
            'amount' => $amount,
            'contact_type' => $contact['type'],
            'contact_id' => $contact['id'],
            'contact_name' => $contact['name'],
            'vat_treatment' => $vatTreatment,
            'vat_rate' => $effectiveVatRate ?? null,
        ], $userId, $journalId);

        if ($ownsTxn) {
            $conn->commit();
        }
        return ['success' => true, 'journal_id' => $journalId, 'gl_line_ids' => $glLineIds, 'match_id' => $matchId, 'error' => null];
    } catch (Throwable $e) {
        if (isset($ownsTxn) && $ownsTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'journal_id' => null, 'gl_line_ids' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Bulk create & reconcile from cash coding grid.
 *
 * @param list<array<string,mixed>> $rows
 * @return array{success:bool,processed:int,failed:list<array<string,mixed>>,error:?string}
 */
function co_bank_reco_cash_coding_bulk(PDO $conn, int $companyId, array $rows, ?int $userId): array
{
    $processed = 0;
    $failed = [];

    try {
        $conn->beginTransaction();
        foreach ($rows as $idx => $row) {
            $lineId = (int) ($row['line_id'] ?? 0);
            $accountId = (int) ($row['account_id'] ?? 0);
            if ($lineId <= 0 || $accountId <= 0) {
                $failed[] = ['index' => $idx, 'line_id' => $lineId, 'error' => 'Line and account required'];
                continue;
            }
            $line = co_bank_get_statement_line($conn, $lineId, $companyId);
            if (!$line || co_bank_line_remaining($conn, $line) <= 0.009) {
                $failed[] = ['index' => $idx, 'line_id' => $lineId, 'error' => 'Line not found or already reconciled'];
                continue;
            }
            if (co_bank_is_period_locked($conn, (int) $line['bank_account_id'], $line['txn_date'])) {
                $failed[] = ['index' => $idx, 'line_id' => $lineId, 'error' => 'Period locked'];
                continue;
            }

            $contactType = null;
            $contactId = null;
            $contactRaw = trim((string) ($row['contact'] ?? ''));
            if ($contactRaw !== '' && str_contains($contactRaw, ':')) {
                [$contactType, $cidRaw] = explode(':', $contactRaw, 2);
                $contactType = in_array($contactType, ['client', 'supplier', 'contractor'], true) ? $contactType : null;
                $contactId = $contactType ? (int) $cidRaw : null;
            }

            $result = co_bank_reco_create_transaction(
                $conn,
                $companyId,
                $line,
                (string) ($row['transaction_type'] ?? 'quick_expense'),
                $accountId,
                trim((string) ($row['description'] ?? $line['description'] ?? '')),
                trim((string) ($row['reference'] ?? $line['reference'] ?? '')) ?: null,
                !empty($row['project_id']) ? (int) $row['project_id'] : null,
                $userId,
                [],
                $contactType,
                $contactId,
                'cash_coding',
                (string) ($row['vat_treatment'] ?? 'none'),
                isset($row['vat_rate']) && $row['vat_rate'] !== '' ? (float) $row['vat_rate'] : null
            );
            if (!$result['success']) {
                $failed[] = ['index' => $idx, 'line_id' => $lineId, 'error' => $result['error'] ?? 'Failed'];
                continue;
            }
            $processed++;
        }
        $conn->commit();
        return ['success' => $processed > 0 || !$failed, 'processed' => $processed, 'failed' => $failed, 'error' => null];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'processed' => 0, 'failed' => $failed, 'error' => $e->getMessage()];
    }
}

/**
 * Small adjustment JE when Find & Match selections do not fully equal the bank line.
 *
 * @param array<string,mixed> $adjustment account_id, amount, description?, type?
 * @return array{success:bool,journal_id:?int,match_id:?int,error:?string}
 */
function co_bank_reco_create_adjustment(
    PDO $conn,
    int $companyId,
    array $line,
    array $adjustment,
    ?int $userId
): array {
    $bankGlId = (int) ($line['gl_account_id'] ?? 0);
    $bankAccountId = (int) ($line['bank_account_id'] ?? 0);
    $lineId = (int) ($line['id'] ?? 0);
    $amount = co_bank_reco_money((float) ($adjustment['amount'] ?? 0));
    $offsetAccountId = (int) ($adjustment['account_id'] ?? 0);
    $description = trim((string) ($adjustment['description'] ?? 'Bank reconciliation adjustment'));
    $txnDate = (string) ($line['txn_date'] ?? date('Y-m-d'));
    $isInflow = (float) ($line['amount'] ?? 0) > 0;

    if ($bankGlId <= 0 || $lineId <= 0 || $amount <= 0 || $offsetAccountId <= 0) {
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => 'Invalid adjustment'];
    }
    if (co_bank_is_period_locked($conn, $bankAccountId, $txnDate)) {
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => 'Period is locked'];
    }

    $journalLines = [];
    if ($isInflow) {
        $journalLines[] = ['account_id' => $bankGlId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => 'CO-BR-ADJ-' . $lineId];
        $journalLines[] = ['account_id' => $offsetAccountId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => 'CO-BR-ADJ-' . $lineId];
    } else {
        $journalLines[] = ['account_id' => $offsetAccountId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => 'CO-BR-ADJ-' . $lineId];
        $journalLines[] = ['account_id' => $bankGlId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => 'CO-BR-ADJ-' . $lineId];
    }

    try {
        $result = create_and_post_journal(
            $companyId,
            'manual',
            'co_bank_reconciliation',
            $lineId,
            $journalLines,
            $description,
            $txnDate,
            $userId
        );
        if (empty($result['success'])) {
            throw new RuntimeException($result['error'] ?? 'Adjustment posting failed');
        }
        $journalId = (int) ($result['journal_id'] ?? 0);
        $amountCol = $isInflow ? 'debit_amount' : 'credit_amount';
        $st = $conn->prepare("
            SELECT id FROM re_general_ledger
            WHERE journal_id = ? AND company_id = ? AND account_id = ? AND {$amountCol} > 0
            ORDER BY id DESC LIMIT 1
        ");
        $st->execute([$journalId, $companyId, $bankGlId]);
        $bankLineGlId = (int) ($st->fetchColumn() ?: 0);
        if ($bankLineGlId <= 0) {
            throw new RuntimeException('Could not locate bank GL line for adjustment');
        }

        $systemType = $isInflow ? 'gl_inflow' : 'gl_outflow';
        $matchId = co_bank_reco_insert_and_confirm_match(
            $conn,
            $companyId,
            $bankAccountId,
            $lineId,
            $systemType,
            $bankLineGlId,
            $amount,
            'adjustment',
            $userId,
            $journalId
        );
        co_bank_sync_gl_reconciled_flag($conn, $bankLineGlId, $companyId, $userId);
        co_bank_reco_audit($conn, $companyId, $bankAccountId, $lineId, $matchId, 'adjustment', null, [
            'journal_id' => $journalId,
            'amount' => $amount,
            'type' => $adjustment['type'] ?? 'rounding',
        ], $userId, $journalId);

        return ['success' => true, 'journal_id' => $journalId, 'match_id' => $matchId, 'error' => null];
    } catch (Throwable $e) {
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Inter-account bank transfer from a statement line.
 *
 * @return array{success:bool,journal_id:?int,match_ids:list<int>,paired_line_id:?int,error:?string}
 */
function co_bank_reco_transfer(
    PDO $conn,
    int $companyId,
    array $line,
    int $toBankAccountId,
    string $reference,
    ?string $description,
    ?int $userId
): array {
    $fromBankAccountId = (int) ($line['bank_account_id'] ?? 0);
    $fromGlId = (int) ($line['gl_account_id'] ?? 0);
    $lineId = (int) ($line['id'] ?? 0);
    $amount = co_bank_line_remaining($conn, $line);
    $txnDate = (string) ($line['txn_date'] ?? date('Y-m-d'));
    $isInflow = (float) ($line['amount'] ?? 0) > 0;
    $reference = trim($reference);
    $description = trim((string) ($description ?: ($line['description'] ?? 'Bank transfer')));

    if ($fromBankAccountId <= 0 || $toBankAccountId <= 0 || $fromBankAccountId === $toBankAccountId || $amount <= 0) {
        return ['success' => false, 'journal_id' => null, 'match_ids' => [], 'paired_line_id' => null, 'error' => 'Invalid transfer accounts or amount'];
    }
    if (co_bank_is_period_locked($conn, $fromBankAccountId, $txnDate)) {
        return ['success' => false, 'journal_id' => null, 'match_ids' => [], 'paired_line_id' => null, 'error' => 'Period is locked'];
    }

    $destBank = co_bank_verify_account($conn, $toBankAccountId, $companyId);
    if (!$destBank) {
        return ['success' => false, 'journal_id' => null, 'match_ids' => [], 'paired_line_id' => null, 'error' => 'Destination bank account not found'];
    }
    $toGlId = (int) $destBank['gl_account_id'];

    $dup = $conn->prepare("
        SELECT COUNT(*) FROM co_reconciliation_matches m
        INNER JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
        WHERE l.company_id = ? AND l.bank_account_id = ? AND l.txn_date = ?
          AND m.match_method = 'transfer' AND m.status = 'confirmed'
          AND ABS(l.amount) = ? AND COALESCE(l.reference, '') = ?
    ");
    $dup->execute([$companyId, $fromBankAccountId, $txnDate, $amount, $reference]);
    if ((int) $dup->fetchColumn() > 0) {
        return ['success' => false, 'journal_id' => null, 'match_ids' => [], 'paired_line_id' => null, 'error' => 'A transfer with this date, amount, and reference already exists'];
    }

    $journalLines = [];
    if ($isInflow) {
        $journalLines[] = ['account_id' => $fromGlId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => $reference ?: ('CO-TRF-' . $lineId)];
        $journalLines[] = ['account_id' => $toGlId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => $reference ?: ('CO-TRF-' . $lineId)];
    } else {
        $journalLines[] = ['account_id' => $toGlId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => $reference ?: ('CO-TRF-' . $lineId)];
        $journalLines[] = ['account_id' => $fromGlId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => $reference ?: ('CO-TRF-' . $lineId)];
    }

    try {
        $conn->beginTransaction();

        $result = create_and_post_journal(
            $companyId,
            'manual',
            'co_bank_reconciliation',
            $lineId,
            $journalLines,
            'Bank transfer: ' . $description,
            $txnDate,
            $userId
        );
        if (empty($result['success'])) {
            throw new RuntimeException($result['error'] ?? 'Transfer posting failed');
        }
        $journalId = (int) ($result['journal_id'] ?? 0);

        $sourceAmountCol = $isInflow ? 'debit_amount' : 'credit_amount';
        $st = $conn->prepare("
            SELECT id FROM re_general_ledger
            WHERE journal_id = ? AND company_id = ? AND account_id = ? AND {$sourceAmountCol} > 0
            ORDER BY id DESC LIMIT 1
        ");
        $st->execute([$journalId, $companyId, $fromGlId]);
        $sourceGlLineId = (int) ($st->fetchColumn() ?: 0);
        if ($sourceGlLineId <= 0) {
            throw new RuntimeException('Could not locate source bank GL line');
        }

        $sourceType = $isInflow ? 'gl_inflow' : 'gl_outflow';
        $matchIds = [];
        $matchIds[] = co_bank_reco_insert_and_confirm_match(
            $conn,
            $companyId,
            $fromBankAccountId,
            $lineId,
            $sourceType,
            $sourceGlLineId,
            $amount,
            'transfer',
            $userId,
            $journalId
        );
        co_bank_sync_gl_reconciled_flag($conn, $sourceGlLineId, $companyId, $userId);

        $pairedLineId = null;
        $destAmountCol = $isInflow ? 'credit_amount' : 'debit_amount';
        $destSystemType = $isInflow ? 'gl_outflow' : 'gl_inflow';
        $stDestGl = $conn->prepare("
            SELECT id FROM re_general_ledger
            WHERE journal_id = ? AND company_id = ? AND account_id = ? AND {$destAmountCol} > 0
            ORDER BY id DESC LIMIT 1
        ");
        $stDestGl->execute([$journalId, $companyId, $toGlId]);
        $destGlLineId = (int) ($stDestGl->fetchColumn() ?: 0);

        $stOpp = $conn->prepare("
            SELECT l.* FROM co_bank_statement_lines l
            WHERE l.company_id = ? AND l.bank_account_id = ?
              AND l.txn_date BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY)
              AND ABS(ABS(l.amount) - ?) < 0.02
              AND (
                (? = 1 AND l.amount < 0) OR (? = 0 AND l.amount > 0)
              )
              AND COALESCE(l.reference, '') = ?
            ORDER BY ABS(DATEDIFF(l.txn_date, ?)) ASC, l.id ASC
            LIMIT 5
        ");
        $stOpp->execute([
            $companyId,
            $toBankAccountId,
            $txnDate,
            $txnDate,
            $amount,
            $isInflow ? 1 : 0,
            $isInflow ? 1 : 0,
            $reference,
            $txnDate,
        ]);
        foreach ($stOpp->fetchAll(PDO::FETCH_ASSOC) as $oppLine) {
            if (co_bank_line_remaining($conn, $oppLine) <= 0.009) {
                continue;
            }
            if ($destGlLineId <= 0) {
                break;
            }
            $oppId = (int) $oppLine['id'];
            $matchIds[] = co_bank_reco_insert_and_confirm_match(
                $conn,
                $companyId,
                $toBankAccountId,
                $oppId,
                $destSystemType,
                $destGlLineId,
                $amount,
                'transfer',
                $userId,
                $journalId
            );
            co_bank_sync_gl_reconciled_flag($conn, $destGlLineId, $companyId, $userId);
            co_bank_refresh_line_status($conn, $oppId, $companyId);
            $pairedLineId = $oppId;
            break;
        }

        co_bank_refresh_line_status($conn, $lineId, $companyId);
        co_bank_reco_audit($conn, $companyId, $fromBankAccountId, $lineId, $matchIds[0] ?? null, 'transfer', null, [
            'journal_id' => $journalId,
            'to_bank_account_id' => $toBankAccountId,
            'paired_line_id' => $pairedLineId,
            'match_ids' => $matchIds,
        ], $userId, $journalId);

        $conn->commit();
        return [
            'success' => true,
            'journal_id' => $journalId,
            'match_ids' => $matchIds,
            'paired_line_id' => $pairedLineId,
            'error' => null,
        ];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'journal_id' => null, 'match_ids' => [], 'paired_line_id' => null, 'error' => $e->getMessage()];
    }
}
