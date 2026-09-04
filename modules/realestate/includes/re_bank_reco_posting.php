<?php
/**
 * Real Estate bank reconciliation — create transactions from statement lines.
 */
declare(strict_types=1);

require_once __DIR__ . '/re_bank_reco_core.php';
require_once __DIR__ . '/../accounting/accounting_engine.php';

/**
 * @return array{success:bool,journal_id:?int,match_id:?int,error:?string}
 */
function re_bank_reco_create_transaction(
    PDO $conn,
    int $companyId,
    array $line,
    string $transactionType,
    int $offsetAccountId,
    string $description,
    ?string $reference,
    ?int $userId,
    ?string $contactType = null,
    ?int $contactId = null,
    string $matchMethod = 'create',
    string $vatTreatment = 'none',
    ?float $vatRate = null
): array {
    $bankGlId = (int) ($line['gl_account_id'] ?? 0);
    $bankAccountId = (int) ($line['bank_account_id'] ?? 0);
    $lineId = (int) ($line['id'] ?? 0);
    $amount = re_bank_rec_money(abs((float) ($line['net_amount'] ?? 0)));
    $isSpent = (float) ($line['net_amount'] ?? 0) < 0;
    $txnDate = (string) ($line['statement_date'] ?? date('Y-m-d'));

    if ($bankGlId <= 0 || $lineId <= 0 || $amount <= 0) {
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => 'Invalid statement line'];
    }
    if (re_bank_rec_period_locked($conn, $companyId, $bankAccountId, $txnDate)) {
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => 'Period is locked'];
    }
    if (re_bank_line_remaining($conn, $line) <= 0.009) {
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => 'Statement line already reconciled'];
    }

    if ($contactType && $contactId) {
        $contactName = '';
        if ($contactType === 'tenant') {
            $st = $conn->prepare("SELECT COALESCE(NULLIF(company_name,''), CONCAT(first_name,' ',last_name)) FROM re_tenants WHERE id = ? AND company_id = ?");
            $st->execute([$contactId, $companyId]);
            $contactName = (string) ($st->fetchColumn() ?: '');
        } elseif ($contactType === 'vendor') {
            $st = $conn->prepare('SELECT vendor_name FROM re_vendors WHERE id = ? AND company_id = ?');
            $st->execute([$contactId, $companyId]);
            $contactName = (string) ($st->fetchColumn() ?: '');
        }
        if ($contactName !== '' && !str_contains($description, $contactName)) {
            $description = trim($contactName . ' — ' . $description);
        }
    }

    if ($offsetAccountId <= 0) {
        if ($transactionType === 'cash_withdrawal') {
            $st = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = '1110' AND is_active = 1 LIMIT 1");
            $st->execute([$companyId]);
            $offsetAccountId = (int) ($st->fetchColumn() ?: 0);
        }
        if ($offsetAccountId <= 0) {
            return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => 'Select an account or configure Cash on Hand (1110)'];
        }
    }

    $ref = $reference ?: ('RE-BR-' . $lineId);
    $vatCfg = re_bank_reco_vat_config($conn, $companyId);
    $vatTreatment = in_array($vatTreatment, ['none', 'standard', 'exempt', 'zero_rated', 'out_of_scope'], true) ? $vatTreatment : 'none';
    $effectiveVatRate = ($vatTreatment === 'standard') ? ($vatRate ?? $vatCfg['default_rate']) : 0.0;
    if ($vatTreatment === 'standard' && $effectiveVatRate <= 0) {
        $effectiveVatRate = (float) $vatCfg['default_rate'];
    }

    try {
        $journalLines = re_bank_reco_build_create_journal_lines(
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
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => $e->getMessage()];
    }

    try {
        $ownsTxn = !$conn->inTransaction();
        if ($ownsTxn) {
            $conn->beginTransaction();
        }

        $result = create_and_post_journal(
            $companyId,
            'manual',
            'bank_reconciliation',
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

        $matchType = $isSpent ? 'payment' : 'receipt';
        if ($transactionType === 'bank_charge') {
            $matchType = 'payment';
        }

        $confirm = re_bank_rec_confirm_match(
            $conn,
            $companyId,
            $bankAccountId,
            $lineId,
            'journal_line',
            're_journal_headers',
            $journalId,
            $bankLineGlId,
            $amount,
            $userId,
            'Created from bank reconciliation',
            100,
            'High',
            $matchMethod,
            $journalId
        );
        if (empty($confirm['success'])) {
            throw new RuntimeException($confirm['error'] ?? 'Could not link match');
        }

        re_bank_rec_audit($conn, $companyId, $bankAccountId, $lineId, (int) ($confirm['match_id'] ?? 0), 'create_transaction', null, json_encode([
            'transaction_type' => $transactionType,
            'journal_id' => $journalId,
            'amount' => $amount,
            'match_method' => $matchMethod,
            'vat_treatment' => $vatTreatment,
            'vat_rate' => $effectiveVatRate,
        ]), $userId, $matchMethod);

        if ($ownsTxn) {
            $conn->commit();
        }
        return ['success' => true, 'journal_id' => $journalId, 'match_id' => (int) ($confirm['match_id'] ?? 0), 'error' => null];
    } catch (Throwable $e) {
        if (isset($ownsTxn) && $ownsTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Small adjustment JE when Find & Match selections do not fully equal the bank line.
 *
 * @param array<string,mixed> $adjustment account_id, amount, description?, type?
 * @return array{success:bool,journal_id:?int,match_id:?int,error:?string}
 */
function re_bank_reco_create_adjustment(
    PDO $conn,
    int $companyId,
    array $line,
    array $adjustment,
    ?int $userId
): array {
    $bankGlId = (int) ($line['gl_account_id'] ?? 0);
    $bankAccountId = (int) ($line['bank_account_id'] ?? 0);
    $lineId = (int) ($line['id'] ?? 0);
    $amount = re_bank_rec_money((float) ($adjustment['amount'] ?? 0));
    $offsetAccountId = (int) ($adjustment['account_id'] ?? 0);
    $description = trim((string) ($adjustment['description'] ?? 'Bank reconciliation adjustment'));
    $txnDate = (string) ($line['statement_date'] ?? $line['txn_date'] ?? date('Y-m-d'));
    $isInflow = (float) ($line['net_amount'] ?? $line['amount'] ?? 0) > 0;

    if ($bankGlId <= 0 || $lineId <= 0 || $amount <= 0 || $offsetAccountId <= 0) {
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => 'Invalid adjustment'];
    }
    if (re_bank_rec_period_locked($conn, $companyId, $bankAccountId, $txnDate)) {
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => 'Period is locked'];
    }

    $ref = 'RE-BR-ADJ-' . $lineId;
    if ($isInflow) {
        $journalLines = [
            ['account_id' => $bankGlId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => $ref],
            ['account_id' => $offsetAccountId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => $ref],
        ];
    } else {
        $journalLines = [
            ['account_id' => $offsetAccountId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => $ref],
            ['account_id' => $bankGlId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => $ref],
        ];
    }

    try {
        $result = create_and_post_journal(
            $companyId,
            'adjustment',
            'bank_reconciliation',
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

        $confirm = re_bank_rec_confirm_match(
            $conn,
            $companyId,
            $bankAccountId,
            $lineId,
            'journal_line',
            're_journal_headers',
            $journalId,
            $bankLineGlId,
            $amount,
            $userId,
            $description,
            100,
            'High',
            'adjustment',
            $journalId
        );
        if (empty($confirm['success'])) {
            throw new RuntimeException($confirm['error'] ?? 'Could not link adjustment match');
        }

        re_bank_rec_audit($conn, $companyId, $bankAccountId, $lineId, (int) ($confirm['match_id'] ?? 0), 'adjustment', null, json_encode([
            'journal_id' => $journalId,
            'amount' => $amount,
            'type' => $adjustment['type'] ?? 'rounding',
        ]), $userId, 'adjustment');

        return ['success' => true, 'journal_id' => $journalId, 'match_id' => (int) ($confirm['match_id'] ?? 0), 'error' => null];
    } catch (Throwable $e) {
        return ['success' => false, 'journal_id' => null, 'match_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Inter-account bank transfer from a statement line.
 *
 * @return array{success:bool,journal_id:?int,match_ids:list<int>,paired_line_id:?int,error:?string}
 */
function re_bank_reco_transfer(
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
    $amount = re_bank_line_remaining($conn, $line);
    $txnDate = (string) ($line['statement_date'] ?? $line['txn_date'] ?? date('Y-m-d'));
    $isInflow = (float) ($line['net_amount'] ?? $line['amount'] ?? 0) > 0;
    $reference = trim($reference);
    $description = trim((string) ($description ?: ($line['description'] ?? 'Bank transfer')));

    if ($fromBankAccountId <= 0 || $toBankAccountId <= 0 || $fromBankAccountId === $toBankAccountId || $amount <= 0) {
        return ['success' => false, 'journal_id' => null, 'match_ids' => [], 'paired_line_id' => null, 'error' => 'Invalid transfer accounts or amount'];
    }
    if (re_bank_rec_period_locked($conn, $companyId, $fromBankAccountId, $txnDate)) {
        return ['success' => false, 'journal_id' => null, 'match_ids' => [], 'paired_line_id' => null, 'error' => 'Period is locked'];
    }

    $destBank = re_bank_verify_account($conn, $toBankAccountId, $companyId);
    if (!$destBank) {
        return ['success' => false, 'journal_id' => null, 'match_ids' => [], 'paired_line_id' => null, 'error' => 'Destination bank account not found'];
    }
    $toGlId = (int) $destBank['gl_account_id'];

    $ref = $reference !== '' ? $reference : ('RE-TRF-' . $lineId);
    if ($isInflow) {
        $journalLines = [
            ['account_id' => $fromGlId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => $ref],
            ['account_id' => $toGlId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => $ref],
        ];
    } else {
        $journalLines = [
            ['account_id' => $toGlId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => $ref],
            ['account_id' => $fromGlId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => $ref],
        ];
    }

    try {
        $conn->beginTransaction();

        $result = create_and_post_journal(
            $companyId,
            'manual',
            'bank_reconciliation',
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

        $matchIds = [];
        $confirm = re_bank_rec_confirm_match(
            $conn,
            $companyId,
            $fromBankAccountId,
            $lineId,
            'journal_line',
            're_journal_headers',
            $journalId,
            $sourceGlLineId,
            $amount,
            $userId,
            'Bank transfer',
            null,
            null,
            'transfer',
            $journalId
        );
        if (empty($confirm['success'])) {
            throw new RuntimeException($confirm['error'] ?? 'Could not match source line');
        }
        $matchIds[] = (int) ($confirm['match_id'] ?? 0);

        $pairedLineId = null;
        $destAmountCol = $isInflow ? 'credit_amount' : 'debit_amount';
        $stDestGl = $conn->prepare("
            SELECT id FROM re_general_ledger
            WHERE journal_id = ? AND company_id = ? AND account_id = ? AND {$destAmountCol} > 0
            ORDER BY id DESC LIMIT 1
        ");
        $stDestGl->execute([$journalId, $companyId, $toGlId]);
        $destGlLineId = (int) ($stDestGl->fetchColumn() ?: 0);

        $stOpp = $conn->prepare("
            SELECT l.* FROM re_bank_statement_lines l
            WHERE l.company_id = ? AND l.bank_account_id = ?
              AND l.statement_date BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY)
              AND ABS(ABS(l.net_amount) - ?) < 0.02
              AND ((? = 1 AND l.net_amount < 0) OR (? = 0 AND l.net_amount > 0))
              AND COALESCE(l.reference, '') = ?
            ORDER BY ABS(DATEDIFF(l.statement_date, ?)) ASC, l.id ASC
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
            if (re_bank_line_remaining($conn, $oppLine) <= 0.009 || $destGlLineId <= 0) {
                continue;
            }
            $oppId = (int) $oppLine['id'];
            $oppConfirm = re_bank_rec_confirm_match(
                $conn,
                $companyId,
                $toBankAccountId,
                $oppId,
                'journal_line',
                're_journal_headers',
                $journalId,
                $destGlLineId,
                $amount,
                $userId,
                'Bank transfer (paired)',
                null,
                null,
                'transfer',
                $journalId
            );
            if (!empty($oppConfirm['success'])) {
                $matchIds[] = (int) ($oppConfirm['match_id'] ?? 0);
                $pairedLineId = $oppId;
                break;
            }
        }

        re_bank_rec_refresh_line_status($conn, $companyId, $lineId);
        re_bank_rec_audit($conn, $companyId, $fromBankAccountId, $lineId, $matchIds[0] ?? null, 'transfer', null, json_encode([
            'journal_id' => $journalId,
            'to_bank_account_id' => $toBankAccountId,
            'paired_line_id' => $pairedLineId,
            'match_ids' => $matchIds,
        ]), $userId, 'transfer');

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

/**
 * Bulk create & reconcile from cash coding grid.
 *
 * @param list<array<string,mixed>> $rows
 * @return array{success:bool,processed:int,failed:list<array<string,mixed>>,error:?string}
 */
function re_bank_reco_cash_coding_bulk(PDO $conn, int $companyId, array $rows, ?int $userId): array
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
            $line = re_bank_get_statement_line($conn, $lineId, $companyId);
            if (!$line || re_bank_line_remaining($conn, $line) <= 0.009) {
                $failed[] = ['index' => $idx, 'line_id' => $lineId, 'error' => 'Line not found or already reconciled'];
                continue;
            }
            if (re_bank_rec_period_locked($conn, $companyId, (int) $line['bank_account_id'], (string) $line['statement_date'])) {
                $failed[] = ['index' => $idx, 'line_id' => $lineId, 'error' => 'Period locked'];
                continue;
            }

            $contactType = null;
            $contactId = null;
            $contactRaw = trim((string) ($row['contact'] ?? ''));
            if ($contactRaw !== '' && str_contains($contactRaw, ':')) {
                [$contactType, $cidRaw] = explode(':', $contactRaw, 2);
                $contactType = in_array($contactType, ['tenant', 'vendor'], true) ? $contactType : null;
                $contactId = $contactType ? (int) $cidRaw : null;
            }

            $result = re_bank_reco_create_transaction(
                $conn,
                $companyId,
                $line,
                (string) ($row['transaction_type'] ?? 'quick_expense'),
                $accountId,
                trim((string) ($row['description'] ?? $line['description'] ?? '')),
                trim((string) ($row['reference'] ?? $line['reference'] ?? '')) ?: null,
                $userId,
                $contactType,
                $contactId,
                'cash_coding'
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
