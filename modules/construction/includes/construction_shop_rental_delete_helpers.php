<?php
/**
 * Shop rental contract delete — full financial purge (Madar Al Wadi / Construction).
 *
 * Confirmed admin behaviour (BR-CO-SHOP-010): when deleting a contract, also remove
 * linked invoices, payment receipts, allocations, deposit receipts/settlements,
 * recognitions, and the related posted journals from the Shared RE ledger (re_*),
 * company-scoped only. Does not touch Cleaning gl_* or other companies.
 *
 * WARNING: This permanently removes GL history for those journals from TB/P&L/BS/GL.
 */

require_once dirname(__DIR__, 3) . '/modules/realestate/accounting/accounting_engine.php';

/**
 * @return array{
 *   allowed:bool,
 *   blockers:list<string>,
 *   contract:?array,
 *   contract_number:string,
 *   status:string,
 *   purge:array<string,int>
 * }
 */
function co_shop_contract_delete_eligibility(PDO $conn, int $companyId, int $contractId): array {
    $out = [
        'allowed' => false,
        'blockers' => [],
        'contract' => null,
        'contract_number' => '',
        'status' => '',
        'purge' => [
            'invoices' => 0,
            'payments' => 0,
            'deposit_receipts' => 0,
            'settlements' => 0,
            'recognitions' => 0,
            'journals' => 0,
            'cheques' => 0,
            'schedules' => 0,
        ],
    ];

    $stmt = $conn->prepare('SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?');
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        $out['blockers'][] = 'Contract not found for this company.';
        return $out;
    }
    $out['contract'] = $contract;
    $out['contract_number'] = (string)($contract['contract_number'] ?? '');
    $out['status'] = (string)($contract['status'] ?? '');

    if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'parent_contract_id')) {
        $st = $conn->prepare('SELECT COUNT(*) FROM co_shop_rental_contracts WHERE company_id = ? AND parent_contract_id = ?');
        $st->execute([$companyId, $contractId]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $out['blockers'][] = $n . ' renewal/child contract(s) still reference this contract — delete those first.';
        }
    }

    $ids = co_shop_contract_collect_purge_ids($conn, $companyId, $contractId, $contract);
    $out['purge'] = [
        'invoices' => count($ids['invoice_ids']),
        'payments' => count($ids['payment_ids']),
        'deposit_receipts' => count($ids['deposit_receipt_ids']),
        'settlements' => count($ids['settlement_ids']),
        'recognitions' => count($ids['recognition_ids']),
        'journals' => count($ids['journal_ids']),
        'cheques' => $ids['cheque_count'],
        'schedules' => $ids['schedule_count'],
    ];

    foreach ($ids['journal_ids'] as $jid) {
        $jh = $conn->prepare('SELECT journal_date, journal_number FROM re_journal_headers WHERE id = ? AND company_id = ?');
        $jh->execute([(int)$jid, $companyId]);
        $row = $jh->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }
        if (function_exists('is_period_locked') && is_period_locked($companyId, $row['journal_date'])) {
            $out['blockers'][] = 'Accounting period is locked for journal '
                . ($row['journal_number'] ?: ('#' . $jid))
                . ' (' . $row['journal_date'] . '). Unlock the period before purge-delete.';
            break;
        }
    }

    $out['allowed'] = $out['blockers'] === [];
    return $out;
}

/**
 * @return array<string,mixed>
 */
function co_shop_contract_collect_purge_ids(PDO $conn, int $companyId, int $contractId, ?array $contract = null): array {
    if ($contract === null) {
        $stmt = $conn->prepare('SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?');
        $stmt->execute([$contractId, $companyId]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $invoiceIds = [];
    $paymentIds = [];
    $depositReceiptIds = [];
    $settlementIds = [];
    $recognitionIds = [];
    $journalIds = [];
    $scheduleIds = [];

    $addJ = static function ($id) use (&$journalIds): void {
        $id = (int)$id;
        if ($id > 0) {
            $journalIds[$id] = $id;
        }
    };
    $addI = static function ($id) use (&$invoiceIds): void {
        $id = (int)$id;
        if ($id > 0) {
            $invoiceIds[$id] = $id;
        }
    };
    $addP = static function ($id) use (&$paymentIds): void {
        $id = (int)$id;
        if ($id > 0) {
            $paymentIds[$id] = $id;
        }
    };

    if (!empty($contract['deposit_journal_id'])) {
        $addJ($contract['deposit_journal_id']);
    }
    if (!empty($contract['commission_invoice_id'])) {
        $addI($contract['commission_invoice_id']);
    }

    $scheduleCount = 0;
    if (co_db_table_exists($conn, 'co_shop_rent_schedules')) {
        $st = $conn->prepare('SELECT id FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ?');
        $st->execute([$companyId, $contractId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $scheduleIds[(int)$sid] = (int)$sid;
        }
        $scheduleCount = count($scheduleIds);
    }

    if ($scheduleIds && co_db_table_exists($conn, 'co_client_invoices')) {
        $in = implode(',', array_map('intval', array_values($scheduleIds)));
        $st = $conn->query("
            SELECT id, journal_id FROM co_client_invoices
            WHERE company_id = " . (int)$companyId . "
              AND source_type = 'shop_rental' AND source_id IN ($in)
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addI($row['id']);
            $addJ($row['journal_id'] ?? 0);
        }
    }

    if (co_db_table_exists($conn, 'co_client_invoices')) {
        $st = $conn->prepare("
            SELECT id, journal_id FROM co_client_invoices
            WHERE company_id = ? AND source_type IN ('shop_commission','shop_termination_penalty') AND source_id = ?
        ");
        $st->execute([$companyId, $contractId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addI($row['id']);
            $addJ($row['journal_id'] ?? 0);
        }
    }

    if (co_db_table_exists($conn, 'co_shop_deposit_receipts')) {
        $st = $conn->prepare('SELECT id, journal_id FROM co_shop_deposit_receipts WHERE company_id = ? AND contract_id = ?');
        $st->execute([$companyId, $contractId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $depositReceiptIds[(int)$row['id']] = (int)$row['id'];
            $addJ($row['journal_id'] ?? 0);
        }
    }

    if (co_db_table_exists($conn, 'co_shop_deposit_settlements')) {
        $st = $conn->prepare('SELECT id, journal_id FROM co_shop_deposit_settlements WHERE company_id = ? AND contract_id = ?');
        $st->execute([$companyId, $contractId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settlementIds[(int)$row['id']] = (int)$row['id'];
            $addJ($row['journal_id'] ?? 0);
        }
    }

    if (co_db_table_exists($conn, 'co_shop_rent_recognitions') && $scheduleIds) {
        $in = implode(',', array_map('intval', array_values($scheduleIds)));
        $st = $conn->query("
            SELECT id, journal_id FROM co_shop_rent_recognitions
            WHERE company_id = " . (int)$companyId . " AND schedule_id IN ($in)
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recognitionIds[(int)$row['id']] = (int)$row['id'];
            $addJ($row['journal_id'] ?? 0);
        }
    }

    $chequeCount = 0;
    if (co_db_table_exists($conn, 'co_shop_rent_cheques')) {
        $st = $conn->prepare('SELECT id, journal_id, payment_id, invoice_id FROM co_shop_rent_cheques WHERE company_id = ? AND contract_id = ?');
        $st->execute([$companyId, $contractId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $chequeCount = count($rows);
        foreach ($rows as $row) {
            $addJ($row['journal_id'] ?? 0);
            $addP($row['payment_id'] ?? 0);
            $addI($row['invoice_id'] ?? 0);
        }
    }

    if ($invoiceIds && co_db_table_exists($conn, 'co_client_payments')) {
        $in = implode(',', array_map('intval', array_values($invoiceIds)));
        $st = $conn->query("
            SELECT id, journal_id FROM co_client_payments
            WHERE company_id = " . (int)$companyId . " AND invoice_id IN ($in)
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addP($row['id']);
            $addJ($row['journal_id'] ?? 0);
        }
        if (co_db_table_exists($conn, 'co_client_payment_allocations')) {
            $st = $conn->query("
                SELECT DISTINCT payment_id FROM co_client_payment_allocations
                WHERE company_id = " . (int)$companyId . " AND invoice_id IN ($in)
            ");
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $addP($pid);
            }
        }
    }

    if ($paymentIds && co_db_table_exists($conn, 'co_client_payments')) {
        $in = implode(',', array_map('intval', array_values($paymentIds)));
        $st = $conn->query("
            SELECT id, journal_id FROM co_client_payments
            WHERE company_id = " . (int)$companyId . " AND id IN ($in)
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addJ($row['journal_id'] ?? 0);
        }
    }

    $refPairs = [];
    foreach ($invoiceIds as $iid) {
        $refPairs[] = ['co_client_invoice', (int)$iid];
    }
    foreach ($paymentIds as $pid) {
        $refPairs[] = ['co_client_payment', (int)$pid];
        $refPairs[] = ['co_client_credit_apply', (int)$pid];
    }
    foreach ($depositReceiptIds as $rid) {
        $refPairs[] = ['co_shop_deposit_receipt', (int)$rid];
    }
    $refPairs[] = ['co_shop_deposit', $contractId];
    foreach ($settlementIds as $sid) {
        $refPairs[] = ['co_shop_deposit_settlement', (int)$sid];
    }

    if ($refPairs) {
        $ors = [];
        foreach ($refPairs as [$t, $r]) {
            $ors[] = '(reference_type = ' . $conn->quote($t) . ' AND reference_id = ' . (int)$r . ')';
        }
        $sql = 'SELECT id, reversal_journal_id FROM re_journal_headers WHERE company_id = ' . (int)$companyId
            . ' AND (' . implode(' OR ', $ors) . ')';
        $st = $conn->query($sql);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addJ($row['id']);
            $addJ($row['reversal_journal_id'] ?? 0);
        }
    }

    if ($journalIds) {
        $in = implode(',', array_map('intval', array_values($journalIds)));
        $st = $conn->query("
            SELECT id, reversal_journal_id FROM re_journal_headers
            WHERE company_id = " . (int)$companyId . "
              AND (id IN ($in) OR reversal_journal_id IN ($in))
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addJ($row['id']);
            $addJ($row['reversal_journal_id'] ?? 0);
        }
    }

    return [
        'invoice_ids' => array_values($invoiceIds),
        'payment_ids' => array_values($paymentIds),
        'deposit_receipt_ids' => array_values($depositReceiptIds),
        'settlement_ids' => array_values($settlementIds),
        'recognition_ids' => array_values($recognitionIds),
        'journal_ids' => array_values($journalIds),
        'cheque_count' => $chequeCount,
        'schedule_count' => $scheduleCount,
        'schedule_ids' => array_values($scheduleIds),
    ];
}

/**
 * @param list<int> $journalIds
 */
function co_shop_hard_delete_journals(PDO $conn, int $companyId, array $journalIds): int {
    $journalIds = array_values(array_unique(array_filter(array_map('intval', $journalIds), static fn($v) => $v > 0)));
    if (!$journalIds) {
        return 0;
    }

    $in = implode(',', $journalIds);
    $st = $conn->query("
        SELECT id, reversal_journal_id FROM re_journal_headers
        WHERE company_id = " . (int)$companyId . "
          AND (id IN ($in) OR reversal_journal_id IN ($in))
    ");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $journalIds[] = (int)$row['id'];
        if (!empty($row['reversal_journal_id'])) {
            $journalIds[] = (int)$row['reversal_journal_id'];
        }
    }
    $journalIds = array_values(array_unique(array_filter($journalIds)));
    if (!$journalIds) {
        return 0;
    }
    $in = implode(',', $journalIds);

    $st = $conn->query('SELECT id, company_id FROM re_journal_headers WHERE id IN (' . $in . ')');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int)$row['company_id'] !== $companyId) {
            throw new RuntimeException('Refusing to delete journal #' . (int)$row['id'] . ' — company mismatch.');
        }
    }

    if (co_db_table_exists($conn, 're_bank_reconciliation_matches')) {
        $conn->exec("
            DELETE m FROM re_bank_reconciliation_matches m
            INNER JOIN re_general_ledger gl ON gl.id = m.gl_line_id
            WHERE m.company_id = " . (int)$companyId . "
              AND gl.journal_id IN ($in)
        ");
        $conn->exec("
            DELETE FROM re_bank_reconciliation_matches
            WHERE company_id = " . (int)$companyId . "
              AND source_table IN ('re_journal_headers','re_general_ledger')
              AND source_id IN ($in)
        ");
    }

    if (co_db_table_exists($conn, 're_general_ledger')) {
        $conn->exec('DELETE FROM re_general_ledger WHERE company_id = ' . (int)$companyId . ' AND journal_id IN (' . $in . ')');
    }
    if (co_db_table_exists($conn, 're_account_ledger_entries')) {
        $conn->exec("
            DELETE ale FROM re_account_ledger_entries ale
            INNER JOIN re_journal_lines jl ON jl.id = ale.journal_line_id
            WHERE jl.journal_id IN ($in) AND jl.company_id = " . (int)$companyId . "
        ");
    }
    if (co_db_table_exists($conn, 're_accounting_postings')) {
        $conn->exec('DELETE FROM re_accounting_postings WHERE journal_id IN (' . $in . ')');
    }

    $conn->exec("
        UPDATE re_journal_headers
        SET is_reversed = 0, reversal_journal_id = NULL
        WHERE company_id = " . (int)$companyId . "
          AND reversal_journal_id IN ($in)
    ");

    $conn->exec('DELETE FROM re_journal_lines WHERE company_id = ' . (int)$companyId . ' AND journal_id IN (' . $in . ')');
    $conn->exec('DELETE FROM re_journal_headers WHERE company_id = ' . (int)$companyId . ' AND id IN (' . $in . ')');

    return count($journalIds);
}

/**
 * Permanently delete a shop rental contract and all linked financial documents + journals.
 *
 * @throws RuntimeException when blocked
 */
function co_shop_delete_contract(PDO $conn, int $companyId, int $contractId, ?int $userId = null): array {
    $elig = co_shop_contract_delete_eligibility($conn, $companyId, $contractId);
    if (!$elig['allowed']) {
        throw new RuntimeException(
            'Cannot delete contract ' . ($elig['contract_number'] ?: '#' . $contractId) . ': '
            . implode(' ', $elig['blockers'])
        );
    }

    $contract = $elig['contract'];
    $clientId = (int)($contract['client_id'] ?? 0);
    $ids = co_shop_contract_collect_purge_ids($conn, $companyId, $contractId, $contract);

    $ownTxn = !$conn->inTransaction();
    if ($ownTxn) {
        $conn->beginTransaction();
    }

    try {
        if (function_exists('co_shop_release_contract_shops')) {
            co_shop_release_contract_shops($conn, $companyId, $contractId);
        }

        if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'renewed_to_contract_id')) {
            $conn->prepare('
                UPDATE co_shop_rental_contracts
                SET renewed_to_contract_id = NULL
                WHERE company_id = ? AND renewed_to_contract_id = ?
            ')->execute([$companyId, $contractId]);
        }

        if ($ids['invoice_ids'] && co_db_table_exists($conn, 'co_client_invoices')) {
            $in = implode(',', array_map('intval', $ids['invoice_ids']));
            $conn->exec('UPDATE co_client_invoices SET journal_id = NULL WHERE company_id = ' . (int)$companyId . ' AND id IN (' . $in . ')');
        }
        if ($ids['payment_ids'] && co_db_table_exists($conn, 'co_client_payments')) {
            $in = implode(',', array_map('intval', $ids['payment_ids']));
            $conn->exec('UPDATE co_client_payments SET journal_id = NULL WHERE company_id = ' . (int)$companyId . ' AND id IN (' . $in . ')');
        }
        if (co_db_table_exists($conn, 'co_shop_deposit_receipts')) {
            $conn->prepare('UPDATE co_shop_deposit_receipts SET journal_id = NULL WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }
        if (co_db_table_exists($conn, 'co_shop_deposit_settlements')) {
            $conn->prepare('UPDATE co_shop_deposit_settlements SET journal_id = NULL WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }
        if ($ids['recognition_ids'] && co_db_table_exists($conn, 'co_shop_rent_recognitions')) {
            $in = implode(',', array_map('intval', $ids['recognition_ids']));
            $conn->exec('UPDATE co_shop_rent_recognitions SET journal_id = NULL WHERE company_id = ' . (int)$companyId . ' AND id IN (' . $in . ')');
        }
        if (co_db_table_exists($conn, 'co_shop_rent_cheques')) {
            $setParts = ['journal_id = NULL'];
            if (co_db_column_exists($conn, 'co_shop_rent_cheques', 'payment_id')) {
                $setParts[] = 'payment_id = NULL';
            }
            if (co_db_column_exists($conn, 'co_shop_rent_cheques', 'invoice_id')) {
                $setParts[] = 'invoice_id = NULL';
            }
            if (co_db_column_exists($conn, 'co_shop_rent_cheques', 'deposit_receipt_id')) {
                $setParts[] = 'deposit_receipt_id = NULL';
            }
            $conn->prepare('UPDATE co_shop_rent_cheques SET ' . implode(', ', $setParts) . ' WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }
        $setContract = ['deposit_journal_id = NULL'];
        if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'commission_invoice_id')) {
            $setContract[] = 'commission_invoice_id = NULL';
        }
        $conn->prepare('UPDATE co_shop_rental_contracts SET ' . implode(', ', $setContract) . ' WHERE id = ? AND company_id = ?')
            ->execute([$contractId, $companyId]);

        $journalsDeleted = co_shop_hard_delete_journals($conn, $companyId, $ids['journal_ids']);

        if (co_db_table_exists($conn, 'co_client_credit_transactions')) {
            $conds = [];
            if ($ids['payment_ids']) {
                $conds[] = 'payment_id IN (' . implode(',', array_map('intval', $ids['payment_ids'])) . ')';
            }
            if ($ids['invoice_ids']) {
                $conds[] = 'invoice_id IN (' . implode(',', array_map('intval', $ids['invoice_ids'])) . ')';
            }
            if ($conds) {
                $conn->exec('
                    DELETE FROM co_client_credit_transactions
                    WHERE company_id = ' . (int)$companyId . ' AND (' . implode(' OR ', $conds) . ')
                ');
            }
        }

        if ($ids['payment_ids'] && co_db_table_exists($conn, 'co_client_payment_allocations')) {
            $in = implode(',', array_map('intval', $ids['payment_ids']));
            $conn->exec('DELETE FROM co_client_payment_allocations WHERE company_id = ' . (int)$companyId . ' AND payment_id IN (' . $in . ')');
        }
        if ($ids['invoice_ids'] && co_db_table_exists($conn, 'co_client_payment_allocations')) {
            $in = implode(',', array_map('intval', $ids['invoice_ids']));
            $conn->exec('DELETE FROM co_client_payment_allocations WHERE company_id = ' . (int)$companyId . ' AND invoice_id IN (' . $in . ')');
        }
        if ($ids['payment_ids'] && co_db_table_exists($conn, 'co_client_payments')) {
            $in = implode(',', array_map('intval', $ids['payment_ids']));
            $conn->exec('DELETE FROM co_client_payments WHERE company_id = ' . (int)$companyId . ' AND id IN (' . $in . ')');
        }

        if ($ids['invoice_ids']) {
            $in = implode(',', array_map('intval', $ids['invoice_ids']));
            if (co_db_table_exists($conn, 'co_client_invoice_lines')) {
                $conn->exec('DELETE FROM co_client_invoice_lines WHERE company_id = ' . (int)$companyId . ' AND invoice_id IN (' . $in . ')');
            }
            if (co_db_table_exists($conn, 'co_client_invoice_documents')) {
                $conn->exec('DELETE FROM co_client_invoice_documents WHERE company_id = ' . (int)$companyId . ' AND client_invoice_id IN (' . $in . ')');
            }
            if (co_db_table_exists($conn, 'co_client_invoices')) {
                $conn->exec('DELETE FROM co_client_invoices WHERE company_id = ' . (int)$companyId . ' AND id IN (' . $in . ')');
            }
        }

        if (co_db_table_exists($conn, 'co_shop_rent_schedules')) {
            $conn->prepare('UPDATE co_shop_rent_schedules SET invoice_id = NULL WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }
        if (co_db_table_exists($conn, 'co_shop_rent_recognitions') && $ids['schedule_ids']) {
            $in = implode(',', array_map('intval', $ids['schedule_ids']));
            $conn->exec('DELETE FROM co_shop_rent_recognitions WHERE company_id = ' . (int)$companyId . ' AND schedule_id IN (' . $in . ')');
        }
        if (co_db_table_exists($conn, 'co_shop_rent_cheques')) {
            $conn->prepare('DELETE FROM co_shop_rent_cheques WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }
        if (co_db_table_exists($conn, 'co_shop_rent_schedules')) {
            $conn->prepare('DELETE FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }

        if (co_db_table_exists($conn, 'co_shop_deposit_receipts')) {
            $conn->prepare('DELETE FROM co_shop_deposit_receipts WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }
        if (co_db_table_exists($conn, 'co_shop_deposit_settlements')) {
            $conn->prepare('DELETE FROM co_shop_deposit_settlements WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }
        if (co_db_table_exists($conn, 'co_shop_contract_terminations')) {
            $conn->prepare('DELETE FROM co_shop_contract_terminations WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }
        if (co_db_table_exists($conn, 'co_shop_move_out_inspections')) {
            if (co_db_table_exists($conn, 'co_shop_move_out_inspection_files')) {
                $conn->prepare('
                    DELETE f FROM co_shop_move_out_inspection_files f
                    INNER JOIN co_shop_move_out_inspections i ON i.id = f.inspection_id
                    WHERE i.company_id = ? AND i.contract_id = ?
                ')->execute([$companyId, $contractId]);
            }
            $conn->prepare('DELETE FROM co_shop_move_out_inspections WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }
        foreach (['co_shop_contract_events', 'co_shop_contract_amendments'] as $table) {
            if (co_db_table_exists($conn, $table)) {
                $conn->prepare("DELETE FROM {$table} WHERE company_id = ? AND contract_id = ?")
                    ->execute([$companyId, $contractId]);
            }
        }
        if (co_db_table_exists($conn, 'co_shop_rental_contract_shops')) {
            $conn->prepare('DELETE FROM co_shop_rental_contract_shops WHERE company_id = ? AND contract_id = ?')
                ->execute([$companyId, $contractId]);
        }

        if ($clientId > 0 && co_db_table_exists($conn, 'co_client_credit_balances') && co_db_table_exists($conn, 'co_client_credit_transactions')) {
            $st = $conn->prepare("
                SELECT COALESCE(SUM(CASE
                    WHEN txn_type = 'overpayment' THEN amount
                    WHEN txn_type = 'apply' THEN -amount
                    WHEN txn_type = 'adjustment' THEN amount
                    ELSE 0 END), 0)
                FROM co_client_credit_transactions
                WHERE company_id = ? AND client_id = ?
            ");
            $st->execute([$companyId, $clientId]);
            $bal = round((float)$st->fetchColumn(), 2);
            $conn->prepare('
                INSERT INTO co_client_credit_balances (client_id, company_id, balance_aed)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE balance_aed = VALUES(balance_aed)
            ')->execute([$clientId, $companyId, $bal]);
        }

        $del = $conn->prepare('DELETE FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?');
        $del->execute([$contractId, $companyId]);
        if ($del->rowCount() < 1) {
            throw new RuntimeException('Contract delete failed — row not removed.');
        }

        if ($ownTxn) {
            $conn->commit();
        }

        return [
            'contract_id' => $contractId,
            'contract_number' => (string)($contract['contract_number'] ?? ''),
            'deleted_by' => $userId,
            'purged' => [
                'invoices' => count($ids['invoice_ids']),
                'payments' => count($ids['payment_ids']),
                'journals' => $journalsDeleted,
                'deposit_receipts' => count($ids['deposit_receipt_ids']),
                'schedules' => count($ids['schedule_ids']),
                'cheques' => $ids['cheque_count'],
            ],
        ];
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
