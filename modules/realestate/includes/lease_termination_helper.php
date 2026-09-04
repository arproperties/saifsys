<?php
/**
 * Lease termination workflow helper.
 */

require_once __DIR__ . '/accounting_mode_helper.php';
require_once __DIR__ . '/obligation_preview_helper.php';

if (!function_exists('re_table_has_column')) {
    function re_table_has_column(PDO $conn, string $table, string $column): bool {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    }
}

if (!function_exists('re_installment_has_money_links')) {
    function re_installment_has_money_links(PDO $conn, int $installmentId, int $leaseId = 0, int $companyId = 0): bool {
        $stmt = $conn->prepare("SELECT payment_id FROM re_lease_installments WHERE id = ?");
        $stmt->execute([$installmentId]);
        $paymentId = $stmt->fetchColumn();
        if (!empty($paymentId)) {
            return true;
        }

        $stmt = $conn->prepare("SELECT COUNT(*) FROM re_payments WHERE installment_id = ?");
        $stmt->execute([$installmentId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }

        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM re_payment_allocations WHERE installment_id = ?");
            $stmt->execute([$installmentId]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        } catch (Throwable $e) {
            // Allocation table may not exist on older databases.
        }

        if ($leaseId > 0) {
            try {
                $stmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM re_payments p
                    JOIN re_post_dated_cheques c ON c.id = p.cheque_id
                    WHERE c.installment_id = ? AND c.lease_id = ?
                ");
                $stmt->execute([$installmentId, $leaseId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    return true;
                }
            } catch (Throwable $e) {
            }
        }

        return false;
    }
}

if (!function_exists('re_installment_has_collected_schedule')) {
    function re_installment_has_collected_schedule(PDO $conn, int $installmentId, int $leaseId, int $companyId): bool {
        if (re_installment_has_money_links($conn, $installmentId, $leaseId, $companyId)) {
            return true;
        }
        try {
            $stmt = $conn->prepare("
                SELECT status
                FROM re_post_dated_cheques
                WHERE installment_id = ? AND lease_id = ? AND company_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$installmentId, $leaseId, $companyId]);
            $status = (string)$stmt->fetchColumn();
            return in_array($status, ['cleared', 'paid', 'collected', 'deposited'], true);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('re_get_lease_termination_preview')) {
    function re_get_lease_termination_preview(PDO $conn, int $companyId, int $leaseId, string $terminationDate): array {
        $leaseMode = 'legacy';
        try {
            $modeStmt = $conn->prepare("SELECT COALESCE(accounting_mode, 'legacy') FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
            $modeStmt->execute([$leaseId, $companyId]);
            $leaseMode = re_accounting_normalize_mode((string)$modeStmt->fetchColumn());
        } catch (Throwable $e) {
        }

        $preview = [
            'accounting_mode' => $leaseMode,
            'installments' => [],
            'pdc_cheques' => [],
            'lease_cheques' => [],
            'recognition_rows' => [],
            'blocked_installments' => [],
            'invoice_obligations' => [],
            'voidable_invoices' => [],
            'pending_candidates' => [],
        ];

        $stmt = $conn->prepare("
            SELECT id, installment_date, amount, status
            FROM re_lease_installments
            WHERE lease_id = ?
              AND company_id = ?
              AND status IN ('pending', 'overdue')
            ORDER BY installment_date ASC, id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string)$row['status'];
            if (in_array($status, ['paid', 'partial', 'cancelled', 'waived'], true)) {
                $preview['blocked_installments'][] = $row + ['reason' => 'status_' . $status];
                continue;
            }
            if (re_installment_has_collected_schedule($conn, (int)$row['id'], $leaseId, $companyId)) {
                $preview['blocked_installments'][] = $row + ['reason' => 'collected_or_cleared'];
                continue;
            }
            $row['suggested'] = ((string)$row['installment_date'] >= $terminationDate);
            $preview['installments'][] = $row;
        }
        $safeInstallmentLookup = array_fill_keys(array_map('intval', array_column($preview['installments'], 'id')), true);

        $stmt = $conn->prepare("
            SELECT id, installment_id, cheque_number, cheque_date, cheque_amount, status
            FROM re_post_dated_cheques
            WHERE lease_id = ?
              AND company_id = ?
              AND status = 'pending'
            ORDER BY cheque_date ASC, id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['installment_id']) && !isset($safeInstallmentLookup[(int)$row['installment_id']])) {
                continue;
            }
            $row['suggested'] = ((string)$row['cheque_date'] >= $terminationDate);
            $preview['pdc_cheques'][] = $row;
        }

        $stmt = $conn->prepare("
            SELECT id, installment_id, cheque_number, cheque_date, cheque_amount, status
            FROM re_lease_cheques
            WHERE lease_id = ?
              AND company_id = ?
              AND status = 'pending'
            ORDER BY cheque_date ASC, id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['installment_id']) && !isset($safeInstallmentLookup[(int)$row['installment_id']])) {
                continue;
            }
            $row['suggested'] = ((string)$row['cheque_date'] >= $terminationDate);
            $preview['lease_cheques'][] = $row;
        }

        try {
            $stmt = $conn->prepare("
                SELECT id, installment_id, recognition_date, amount, status, deferred_payment_id
                FROM re_rent_recognition_schedule
                WHERE lease_id = ?
                  AND company_id = ?
                  AND status = 'pending'
                ORDER BY recognition_date ASC, id ASC
            ");
            $stmt->execute([$leaseId, $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $isFutureRecognition = ((string)$row['recognition_date'] >= $terminationDate);
                $isSafeManualRecognition = !empty($row['installment_id']) && isset($safeInstallmentLookup[(int)$row['installment_id']]);
                if (!$isFutureRecognition && !$isSafeManualRecognition) {
                    continue;
                }
                $row['suggested'] = $isFutureRecognition;
                $preview['recognition_rows'][] = $row;
            }
        } catch (Throwable $e) {
            $preview['recognition_rows'] = [];
        }

        if ($leaseMode === 'invoice' && re_obligation_table_exists($conn, 're_obligations')) {
            $obStmt = $conn->prepare("
                SELECT o.id, o.obligation_type, o.description, o.due_date, o.total_amount, o.status,
                       o.invoice_id, o.allocated_amount,
                       ii.invoice_id AS linked_invoice_id,
                       i.invoice_number, i.status AS invoice_status, i.outstanding_amount
                FROM re_obligations o
                LEFT JOIN re_invoice_items ii ON ii.obligation_id = o.id AND ii.company_id = o.company_id
                LEFT JOIN re_invoices i ON i.id = COALESCE(o.invoice_id, ii.invoice_id) AND i.company_id = o.company_id
                WHERE o.company_id = ? AND o.lease_id = ?
                  AND o.status IN ('open', 'partially_allocated')
                  AND o.obligation_type NOT IN ('security_deposit')
                  AND o.due_date >= ?
                ORDER BY o.due_date ASC, o.id ASC
            ");
            $obStmt->execute([$companyId, $leaseId, $terminationDate]);
            foreach ($obStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['suggested'] = true;
                $preview['invoice_obligations'][] = $row;
                $linkedInvoiceId = (int)($row['linked_invoice_id'] ?? $row['invoice_id'] ?? 0);
                $invoiceStatus = (string)($row['invoice_status'] ?? '');
                if ($linkedInvoiceId > 0 && !in_array($invoiceStatus, ['paid', 'partial', 'cancelled'], true)) {
                    $preview['voidable_invoices'][] = [
                        'id' => $linkedInvoiceId,
                        'invoice_number' => (string)($row['invoice_number'] ?? ('#' . $linkedInvoiceId)),
                        'due_date' => (string)($row['due_date'] ?? ''),
                        'total_amount' => (float)($row['total_amount'] ?? 0),
                        'status' => $invoiceStatus,
                        'obligation_id' => (int)$row['id'],
                        'suggested' => true,
                    ];
                }
            }

            if (re_obligation_table_exists($conn, 're_invoice_candidates')) {
                $candStmt = $conn->prepare("
                    SELECT c.id, c.eligible_on, c.status, o.obligation_type, o.due_date, o.total_amount, o.description
                    FROM re_invoice_candidates c
                    JOIN re_obligations o ON o.id = c.obligation_id AND o.company_id = c.company_id
                    WHERE c.company_id = ? AND c.lease_id = ?
                      AND c.invoice_id IS NULL
                      AND c.status IN ('prepared', 'approved')
                      AND o.due_date >= ?
                    ORDER BY c.eligible_on ASC, c.id ASC
                ");
                $candStmt->execute([$companyId, $leaseId, $terminationDate]);
                foreach ($candStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $row['suggested'] = true;
                    $preview['pending_candidates'][] = $row;
                }
            }
        }

        return $preview;
    }
}

if (!function_exists('re_apply_lease_termination')) {
    /**
     * @return array<string,int>
     */
    function re_apply_lease_termination(PDO $conn, int $companyId, int $leaseId, string $terminationDate, string $reason, ?int $userId, array $options = []): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $terminationDate)) {
            throw new InvalidArgumentException('Invalid termination date.');
        }

        $stmt = $conn->prepare("SELECT * FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$leaseId, $companyId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lease) {
            throw new RuntimeException('Lease not found.');
        }

        $startDate = (string)($lease['start_date'] ?? '');
        $endDate = (string)($lease['end_date'] ?? '');
        if ($startDate && $terminationDate < $startDate) {
            throw new InvalidArgumentException('Termination date cannot be before lease start date.');
        }
        if ($endDate && $terminationDate > $endDate) {
            throw new InvalidArgumentException('Termination date cannot be after lease end date.');
        }

        $preview = re_get_lease_termination_preview($conn, $companyId, $leaseId, $terminationDate);
        $selectedInstallmentIds = array_values(array_unique(array_map('intval', $options['installment_ids'] ?? [])));
        $selectedPdcIds = array_values(array_unique(array_map('intval', $options['pdc_ids'] ?? [])));
        $selectedRecognitionIds = array_values(array_unique(array_map('intval', $options['recognition_ids'] ?? [])));
        $safePdcLookup = array_fill_keys(array_map('intval', array_column($preview['pdc_cheques'], 'id')), true);
        $selectedPdcIds = array_values(array_filter($selectedPdcIds, fn($id) => isset($safePdcLookup[(int)$id])));
        foreach ($preview['pdc_cheques'] as $row) {
            if (in_array((int)$row['id'], $selectedPdcIds, true) && !empty($row['installment_id'])) {
                $selectedInstallmentIds[] = (int)$row['installment_id'];
            }
        }
        $selectedInstallmentIds = array_values(array_unique($selectedInstallmentIds));
        $selectedInstallmentLookup = array_fill_keys($selectedInstallmentIds, true);
        $safeRecognitionLookup = array_fill_keys(array_map('intval', array_column($preview['recognition_rows'], 'id')), true);
        $selectedRecognitionIds = array_values(array_unique(array_filter($selectedRecognitionIds, fn($id) => isset($safeRecognitionLookup[(int)$id]))));
        $penaltyPdcId = (int)($options['penalty_pdc_id'] ?? 0);
        $penaltyPdcInstallmentId = 0;
        if ($penaltyPdcId > 0) {
            foreach ($preview['pdc_cheques'] as $row) {
                if ((int)$row['id'] === $penaltyPdcId) {
                    $penaltyPdcInstallmentId = (int)($row['installment_id'] ?? 0);
                    break;
                }
            }
            if ($penaltyPdcInstallmentId <= 0) {
                throw new InvalidArgumentException('Penalty collection cheque is not a valid pending PDC on this lease.');
            }
            $selectedPdcIds = array_values(array_filter($selectedPdcIds, fn($id) => (int)$id !== $penaltyPdcId));
            $selectedInstallmentIds = array_values(array_filter($selectedInstallmentIds, fn($id) => (int)$id !== $penaltyPdcInstallmentId));
        }
        $selectedVoidInvoiceIds = array_values(array_unique(array_map('intval', $options['void_invoice_ids'] ?? [])));
        $selectedObligationIds = array_values(array_unique(array_map('intval', $options['obligation_ids'] ?? [])));
        $safeVoidInvoiceLookup = array_fill_keys(array_map('intval', array_column($preview['voidable_invoices'] ?? [], 'id')), true);
        $selectedVoidInvoiceIds = array_values(array_filter($selectedVoidInvoiceIds, fn($id) => isset($safeVoidInvoiceLookup[(int)$id])));
        $safeObligationLookup = array_fill_keys(array_map('intval', array_column($preview['invoice_obligations'] ?? [], 'id')), true);
        $selectedObligationIds = array_values(array_filter($selectedObligationIds, fn($id) => isset($safeObligationLookup[(int)$id])));
        if (($preview['accounting_mode'] ?? 'legacy') === 'invoice' && empty($selectedVoidInvoiceIds) && !empty($preview['voidable_invoices'])) {
            $selectedVoidInvoiceIds = array_map('intval', array_column($preview['voidable_invoices'], 'id'));
        }
        if (($preview['accounting_mode'] ?? 'legacy') === 'invoice' && empty($selectedObligationIds) && !empty($preview['invoice_obligations'])) {
            $selectedObligationIds = array_map('intval', array_column($preview['invoice_obligations'], 'id'));
        }
        $stats = [
            'installments_cancelled' => 0,
            'pdc_returned' => 0,
            'lease_cheques_returned' => 0,
            'recognition_skipped' => 0,
            'units_vacated' => 0,
            'penalty_created' => 0,
            'invoices_voided' => 0,
            'obligations_cancelled' => 0,
            'candidates_cancelled' => 0,
            'penalty_pdc_kept' => 0,
        ];

        $conn->beginTransaction();
        try {
            $leaseSet = [
                "status = 'terminated'",
                "move_out_date = COALESCE(move_out_date, ?)",
                "updated_at = NOW()",
            ];
            $leaseParams = [$terminationDate];

            if (re_table_has_column($conn, 're_leases', 'termination_date')) {
                $leaseSet[] = 'termination_date = ?';
                $leaseParams[] = $terminationDate;
            }
            if (re_table_has_column($conn, 're_leases', 'termination_reason')) {
                $leaseSet[] = 'termination_reason = ?';
                $leaseParams[] = $reason !== '' ? $reason : null;
            }
            if (re_table_has_column($conn, 're_leases', 'terminated_by')) {
                $leaseSet[] = 'terminated_by = ?';
                $leaseParams[] = $userId;
            }
            if (re_table_has_column($conn, 're_leases', 'terminated_at')) {
                $leaseSet[] = 'terminated_at = NOW()';
            }
            $leaseParams[] = $leaseId;
            $leaseParams[] = $companyId;
            $conn->prepare("UPDATE re_leases SET " . implode(', ', $leaseSet) . " WHERE id = ? AND company_id = ?")
                ->execute($leaseParams);

            foreach ($preview['installments'] as $row) {
                if (!isset($selectedInstallmentLookup[(int)$row['id']])) {
                    continue;
                }
                $note = 'Cancelled due to lease termination on ' . $terminationDate;
                if ($reason !== '') {
                    $note .= ': ' . $reason;
                }
                $conn->prepare("
                    UPDATE re_lease_installments
                    SET status = 'cancelled',
                        notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
                    WHERE id = ? AND lease_id = ? AND company_id = ?
                ")->execute([$note, (int)$row['id'], $leaseId, $companyId]);
                $stats['installments_cancelled']++;
            }

            $returnNote = 'Returned due to lease termination on ' . $terminationDate;
            if ($reason !== '') {
                $returnNote .= ': ' . $reason;
            }

            if (!empty($selectedPdcIds)) {
                $ph = implode(',', array_fill(0, count($selectedPdcIds), '?'));
                $stmt = $conn->prepare("
                UPDATE re_post_dated_cheques
                SET status = 'returned',
                    notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?)),
                    updated_at = NOW()
                WHERE lease_id = ?
                  AND company_id = ?
                  AND status = 'pending'
                  AND id IN ($ph)
            ");
                $stmt->execute(array_merge([$returnNote, $leaseId, $companyId], $selectedPdcIds));
                $stats['pdc_returned'] = $stmt->rowCount();
            }

            $selectedLeaseChequeIds = [];
            foreach ($preview['lease_cheques'] as $row) {
                if (!empty($row['installment_id']) && in_array((int)$row['installment_id'], $selectedInstallmentIds, true)) {
                    $selectedLeaseChequeIds[] = (int)$row['id'];
                }
            }
            if (!empty($selectedLeaseChequeIds)) {
                $ph = implode(',', array_fill(0, count($selectedLeaseChequeIds), '?'));
                $stmt = $conn->prepare("
                UPDATE re_lease_cheques
                SET status = 'returned',
                    notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?)),
                    updated_at = NOW()
                WHERE lease_id = ?
                  AND company_id = ?
                  AND status = 'pending'
                  AND id IN ($ph)
            ");
                $stmt->execute(array_merge([$returnNote, $leaseId, $companyId], $selectedLeaseChequeIds));
                $stats['lease_cheques_returned'] = $stmt->rowCount();
            }

            $penaltyMode = (string)($options['penalty_mode'] ?? 'none');
            $penaltyAmount = 0.0;
            $penaltyDescription = '';
            if ($penaltyMode === 'months') {
                $months = max(0, (float)($options['penalty_months'] ?? 0));
                $monthlyRent = !empty($lease['annual_rent'])
                    ? ((float)$lease['annual_rent'] / 12)
                    : (float)($lease['monthly_rent'] ?? 0);
                $penaltyAmount = round($months * $monthlyRent, 2);
                $penaltyDescription = 'Early termination penalty: ' . rtrim(rtrim(number_format($months, 2, '.', ''), '0'), '.') . ' month(s) x ' . number_format($monthlyRent, 2) . ' AED';
            } elseif ($penaltyMode === 'fixed') {
                $penaltyAmount = round(max(0, (float)($options['penalty_amount'] ?? 0)), 2);
                $penaltyDescription = 'Early termination penalty: fixed amount';
            } elseif ($penaltyPdcId > 0) {
                foreach ($preview['pdc_cheques'] as $row) {
                    if ((int)$row['id'] === $penaltyPdcId) {
                        $penaltyAmount = round((float)($row['cheque_amount'] ?? 0), 2);
                        $penaltyDescription = 'Early termination penalty collected via cheque ' . (string)($row['cheque_number'] ?? ('#' . $penaltyPdcId));
                        break;
                    }
                }
            }
            $customPenaltyNote = trim((string)($options['penalty_note'] ?? ''));
            if ($customPenaltyNote !== '') {
                $penaltyDescription .= ($penaltyDescription !== '' ? ' | ' : '') . $customPenaltyNote;
            }
            if ($penaltyAmount > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO re_billing_items
                    (company_id, lease_id, item_type, item_name, item_description, amount,
                     quantity, unit_price, total_amount, billing_date, due_date, is_paid, paid_amount, created_by)
                    VALUES (?, ?, 'penalty', 'Early Termination Penalty', ?, ?, 1, ?, ?, ?, ?, 0, 0, ?)
                ");
                $stmt->execute([
                    $companyId,
                    $leaseId,
                    $penaltyDescription,
                    $penaltyAmount,
                    $penaltyAmount,
                    $penaltyAmount,
                    $terminationDate,
                    $terminationDate,
                    $userId,
                ]);
                $stats['penalty_created'] = 1;
            }

            if ($penaltyPdcId > 0 && $penaltyPdcInstallmentId > 0) {
                $penaltyNote = 'Retained for early termination penalty collection on ' . $terminationDate;
                if ($reason !== '') {
                    $penaltyNote .= ': ' . $reason;
                }
                $conn->prepare("
                    UPDATE re_lease_installments
                    SET installment_type = 'penalty',
                        notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
                    WHERE id = ? AND lease_id = ? AND company_id = ?
                ")->execute([$penaltyNote, $penaltyPdcInstallmentId, $leaseId, $companyId]);
                $conn->prepare("
                    UPDATE re_post_dated_cheques
                    SET notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?)),
                        updated_at = NOW()
                    WHERE id = ? AND lease_id = ? AND company_id = ?
                ")->execute([$penaltyNote, $penaltyPdcId, $leaseId, $companyId]);
                $stats['penalty_pdc_kept'] = 1;
            }

            if (($preview['accounting_mode'] ?? 'legacy') === 'invoice') {
                require_once __DIR__ . '/invoice_engine.php';
                require_once __DIR__ . '/obligation_engine.php';
                $voidReason = 'Lease terminated on ' . $terminationDate . ($reason !== '' ? ': ' . $reason : '');
                foreach ($selectedVoidInvoiceIds as $invoiceId) {
                    $voidRes = re_invoice_engine_void_unpaid_invoice($conn, $companyId, (int)$invoiceId, $voidReason, $userId, $terminationDate);
                    if (empty($voidRes['success']) && empty($voidRes['already_void'])) {
                        throw new RuntimeException($voidRes['error'] ?? 'Could not void invoice #' . $invoiceId);
                    }
                    $stats['invoices_voided']++;
                }
                foreach ($selectedObligationIds as $obligationId) {
                    $obUpd = $conn->prepare("
                        UPDATE re_obligations
                        SET status = 'cancelled',
                            recognition_status = 'skipped',
                            notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
                        WHERE id = ? AND company_id = ? AND lease_id = ?
                          AND status IN ('open', 'partially_allocated')
                    ");
                    $obUpd->execute([$voidReason, (int)$obligationId, $companyId, $leaseId]);
                    $stats['obligations_cancelled'] += $obUpd->rowCount();
                }
                if (!empty($preview['pending_candidates'])) {
                    $candidateIds = array_map('intval', array_column($preview['pending_candidates'], 'id'));
                    if ($candidateIds) {
                        $ph = implode(',', array_fill(0, count($candidateIds), '?'));
                        $stmt = $conn->prepare("
                            UPDATE re_invoice_candidates
                            SET status = 'cancelled',
                                notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
                            WHERE company_id = ? AND lease_id = ? AND invoice_id IS NULL AND id IN ($ph)
                        ");
                        $stmt->execute(array_merge([$voidReason, $companyId, $leaseId], $candidateIds));
                        $stats['candidates_cancelled'] = $stmt->rowCount();
                    }
                }
                if ($stats['penalty_created'] && function_exists('re_obligation_engine_generate_for_lease')) {
                    $obRes = re_obligation_engine_generate_for_lease($conn, $companyId, $leaseId, $userId);
                    if (empty($obRes['success'])) {
                        throw new RuntimeException($obRes['error'] ?? 'Penalty obligation could not be generated.');
                    }
                    if (function_exists('re_invoice_engine_prepare_candidates_for_lease')) {
                        re_invoice_engine_prepare_candidates_for_lease($conn, $companyId, $leaseId, $userId);
                    }
                }
            }

            try {
                if (empty($selectedRecognitionIds)) {
                    throw new RuntimeException('No selected recognition rows to skip.');
                }
                $ph = implode(',', array_fill(0, count($selectedRecognitionIds), '?'));
                $stmt = $conn->prepare("
                    UPDATE re_rent_recognition_schedule
                    SET status = 'skipped',
                        notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
                    WHERE lease_id = ?
                      AND company_id = ?
                      AND status = 'pending'
                      AND id IN ($ph)
                ");
                $stmt->execute(array_merge(['Skipped due to lease termination on ' . $terminationDate, $leaseId, $companyId], $selectedRecognitionIds));
                $stats['recognition_skipped'] = $stmt->rowCount();
            } catch (Throwable $e) {
                $stats['recognition_skipped'] = 0;
            }

            $unitIds = [];
            if (!empty($lease['unit_id'])) {
                $unitIds[] = (int)$lease['unit_id'];
            }
            try {
                $stmt = $conn->prepare("SELECT unit_id FROM re_lease_units WHERE lease_id = ? ORDER BY sort_order ASC, id ASC");
                $stmt->execute([$leaseId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    $unitIds[] = (int)$uid;
                }
            } catch (Throwable $e) {
                // Multi-unit table may not exist on older environments.
            }
            $unitIds = array_values(array_unique(array_filter($unitIds)));

            foreach ($unitIds as $unitId) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM re_leases l
                    LEFT JOIN re_lease_units lu ON lu.lease_id = l.id
                    WHERE l.company_id = ?
                      AND l.id != ?
                      AND l.status = 'active'
                      AND (l.unit_id = ? OR lu.unit_id = ?)
                ");
                $stmt->execute([$companyId, $leaseId, $unitId, $unitId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $conn->prepare("UPDATE re_units SET status = 'vacant', updated_at = NOW() WHERE id = ? AND company_id = ?")
                        ->execute([$unitId, $companyId]);
                    $stats['units_vacated']++;
                }
            }

            $conn->commit();

            try {
                require_once __DIR__ . '/../../../includes/audit_bridge.php';
                $leaseRef = (string)($lease['lease_number'] ?? ('Lease #' . $leaseId));
                audit_bridge_re_ops(
                    $conn,
                    $companyId,
                    'lease_terminated',
                    're_leases',
                    $leaseId,
                    $leaseRef,
                    'Terminated lease ' . $leaseRef,
                    ['status' => $lease['status'] ?? null],
                    [
                        'status' => 'terminated',
                        'units_vacated' => (int)($stats['units_vacated'] ?? 0),
                        'installments_cancelled' => (int)($stats['installments_cancelled'] ?? 0),
                    ],
                    $userId ?? null
                );
            } catch (Throwable $e) {
                error_log('lease_termination audit: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $stats;
    }
}

