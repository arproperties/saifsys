<?php
/**
 * Rent installment schedule helpers (amounts, typing, reconciliation).
 */
declare(strict_types=1);

if (!function_exists('lease_non_rent_installment_types')) {
    function lease_non_rent_installment_types(): array
    {
        return ['security_deposit', 'chiller', 'ejari', 'admin', 'commission', 'amc', 'parking', 'store', 'vat', 'combined_fees'];
    }
}

if (!function_exists('lease_is_installment_locked')) {
    function lease_is_installment_locked(array $row): bool
    {
        // Status alone can be stale after a financial reset. Lock only when there
        // is real accounting/legal evidence attached to the row.
        if (!empty($row['payment_id'])) {
            return true;
        }
        // Honored only when the caller's query provides these columns (backward compatible).
        if (($row['cheque_status'] ?? '') === 'cleared') {
            return true;
        }
        if (!empty($row['has_allocation'])) {
            return true;
        }
        return false;
    }
}

if (!function_exists('lease_separate_fee_candidates_from_lease')) {
    /**
     * @return list<array{type:string,amount:float,dist:string,active:bool}>
     */
    function lease_separate_fee_candidates_from_lease(array $lease): array
    {
        $startForFees = !empty($lease['start_date']) ? new DateTime($lease['start_date']) : null;
        $candidates = [
            ['type' => 'security_deposit', 'amount' => (float)($lease['security_deposit'] ?? 0), 'dist' => !empty($lease['sep_security_deposit']) ? 'separate' : 'other', 'active' => empty($lease['is_renewal_lease'])],
            ['type' => 'chiller', 'amount' => (float)($lease['chiller_fees'] ?? 0), 'dist' => !empty($lease['sep_chiller_fees']) ? 'separate' : 'other', 'active' => empty($lease['split_chiller_fees'])],
            ['type' => 'ejari', 'amount' => (float)($lease['ejari_fees'] ?? 0), 'dist' => !empty($lease['sep_ejari_fees']) ? 'separate' : 'other', 'active' => empty($lease['split_ejari_fees'])],
            ['type' => 'admin', 'amount' => (float)($lease['admin_fees'] ?? 0), 'dist' => !empty($lease['sep_admin_fees']) ? 'separate' : 'other', 'active' => empty($lease['split_admin_fees'])],
            ['type' => 'commission', 'amount' => (float)($lease['commission_fees'] ?? 0), 'dist' => !empty($lease['sep_commission_fees']) ? 'separate' : 'other', 'active' => empty($lease['split_commission_fees'])],
            ['type' => 'amc', 'amount' => (float)($lease['amc_amount'] ?? 0), 'dist' => !empty($lease['sep_amc_fees']) ? 'separate' : 'other', 'active' => empty($lease['split_amc_fees'])],
        ];

        if (!empty($lease['has_additional_parking']) && (float)($lease['additional_parking_fee'] ?? 0) > 0) {
            $active = true;
            if (!empty($lease['additional_parking_start_date']) && $startForFees) {
                $parkingStart = new DateTime($lease['additional_parking_start_date']);
                $parkingEnd = !empty($lease['additional_parking_end_date']) ? new DateTime($lease['additional_parking_end_date']) : null;
                $active = $startForFees >= $parkingStart && (!$parkingEnd || $startForFees <= $parkingEnd);
            }
            $candidates[] = [
                'type' => 'parking',
                'amount' => (float)$lease['additional_parking_fee'],
                'dist' => !empty($lease['sep_additional_parking']) ? 'separate' : 'other',
                'active' => $active,
            ];
        }

        if (!empty($lease['has_additional_store']) && (float)($lease['additional_store_fee'] ?? 0) > 0) {
            $active = true;
            if (!empty($lease['additional_store_start_date']) && $startForFees) {
                $storeStart = new DateTime($lease['additional_store_start_date']);
                $storeEnd = !empty($lease['additional_store_end_date']) ? new DateTime($lease['additional_store_end_date']) : null;
                $active = $startForFees >= $storeStart && (!$storeEnd || $startForFees <= $storeEnd);
            }
            $candidates[] = [
                'type' => 'store',
                'amount' => (float)$lease['additional_store_fee'],
                'dist' => !empty($lease['sep_additional_store']) ? 'separate' : 'other',
                'active' => $active,
            ];
        }

        if (($lease['vat_distribution_type'] ?? '') === 'separate_payment' && (float)($lease['total_vat_amount'] ?? 0) > 0) {
            $candidates[] = [
                'type' => 'vat',
                'amount' => (float)$lease['total_vat_amount'],
                'dist' => 'separate',
                'active' => true,
            ];
        }

        return $candidates;
    }
}

if (!function_exists('lease_find_separate_installment_row')) {
    /**
     * @param list<array<string,mixed>> $rows
     */
    function lease_find_separate_installment_row(array $rows, string $type, float $expectedAmount, string $firstDate): ?array
    {
        if ($expectedAmount <= 0) {
            return null;
        }

        $typedMatches = [];
        foreach ($rows as $row) {
            if ((string)($row['installment_type'] ?? '') !== $type) {
                continue;
            }
            $typedMatches[] = $row;
        }

        foreach ($typedMatches as $row) {
            if (abs((float)$row['amount'] - $expectedAmount) < 0.02 && !lease_is_installment_locked($row)) {
                return $row;
            }
        }
        foreach ($typedMatches as $row) {
            if (abs((float)$row['amount'] - $expectedAmount) < 0.02) {
                return $row;
            }
        }
        foreach ($typedMatches as $row) {
            if (!lease_is_installment_locked($row)) {
                return $row;
            }
        }

        $legacyMatches = [];
        foreach ($rows as $row) {
            if ((string)($row['installment_type'] ?? '') !== '') {
                continue;
            }
            if (lease_is_installment_locked($row)) {
                continue;
            }
            if ($row['installment_date'] !== $firstDate) {
                continue;
            }
            if (abs((float)$row['amount'] - $expectedAmount) < 0.02) {
                $legacyMatches[] = $row;
            }
        }
        if ($legacyMatches) {
            usort($legacyMatches, static fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
            return $legacyMatches[0];
        }

        return $typedMatches[0] ?? null;
    }
}

if (!function_exists('lease_is_mistagged_combined_fees_row')) {
    function lease_is_mistagged_combined_fees_row(array $row, float $totalFees): bool
    {
        if (($row['installment_type'] ?? '') !== 'combined_fees') {
            return false;
        }
        $amount = (float)($row['amount'] ?? 0);
        if ($totalFees <= 0) {
            return true;
        }
        if (abs($amount - $totalFees) <= 0.02) {
            return false;
        }
        return $amount > max($totalFees + 100.0, $totalFees * 1.5);
    }
}

if (!function_exists('lease_find_combined_fees_installment_row')) {
    /**
     * @param list<array<string,mixed>> $rows
     */
    function lease_find_combined_fees_installment_row(array $rows, float $totalFees): ?array
    {
        if ($totalFees <= 0) {
            return null;
        }

        $matches = [];
        foreach ($rows as $row) {
            if (lease_is_mistagged_combined_fees_row($row, $totalFees)) {
                continue;
            }
            if (abs((float)($row['amount'] ?? 0) - $totalFees) > 0.02) {
                continue;
            }
            $type = (string)($row['installment_type'] ?? '');
            if ($type !== '' && $type !== 'combined_fees' && in_array($type, lease_non_rent_installment_types(), true)) {
                continue;
            }
            $matches[] = $row;
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn($a, $b) => ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0)));
        return $matches[0];
    }
}

if (!function_exists('lease_filter_rent_like_installment_rows')) {
    /**
     * Rent cheque rows only — excludes combined-fees and typed separate-fee installments.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    function lease_filter_rent_like_installment_rows(
        array $rows,
        int $numberOfInstallments,
        float $totalFees,
        bool $addFeesToFirstInstallment,
        array $excludeInstallmentIds = [],
        bool $limitToInstallmentCount = true
    ): array {
        $exclude = array_fill_keys(array_map('intval', $excludeInstallmentIds), true);
        $combinedFeesRow = null;
        if (!$addFeesToFirstInstallment && $totalFees > 0) {
            $combinedFeesRow = lease_find_combined_fees_installment_row($rows, $totalFees);
            if ($combinedFeesRow) {
                $exclude[(int)$combinedFeesRow['id']] = true;
            }
        }

        $knownSepTypes = lease_non_rent_installment_types();
        $rentLike = [];
        foreach ($rows as $row) {
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId > 0 && isset($exclude[$rowId])) {
                continue;
            }
            $type = (string)($row['installment_type'] ?? '');
            if ($type === 'combined_fees') {
                continue;
            }
            if ($type !== '' && in_array($type, $knownSepTypes, true)) {
                continue;
            }
            $rentLike[] = $row;
        }

        if ($limitToInstallmentCount && $numberOfInstallments > 0 && count($rentLike) > $numberOfInstallments) {
            $rentLike = array_slice($rentLike, 0, $numberOfInstallments);
        }

        return $rentLike;
    }
}

if (!function_exists('lease_repair_invoice_mode_operational_schedule')) {
    /**
     * Safe operational schedule repair for Invoice Mode leases.
     * Retags the fees row, removes unpaid duplicate rent rows, and cancels
     * paid duplicate rent rows without touching invoices/receipts/allocations.
     *
     * @return array<string,mixed>
     */
    function lease_repair_invoice_mode_operational_schedule(PDO $conn, int $leaseId, int $companyId, array $lease): array
    {
        require_once __DIR__ . '/lease_schedule_engine.php';

        $totals = lease_compute_totals($lease);
        $totalFees = (float)($totals['to_first'] ?? 0);
        $rentCount = max(1, (int)($lease['number_of_installments'] ?? 12));
        $addFeesToFirst = !empty($lease['add_fees_to_first_installment']);
        $lockedIds = lease_locked_installment_ids($conn, $leaseId, $companyId);

        $conn->prepare("
            UPDATE re_lease_installments
            SET company_id = ?
            WHERE lease_id = ? AND (company_id = 0 OR company_id IS NULL)
        ")->execute([$companyId, $leaseId]);

        $stmt = $conn->prepare("
            SELECT id, installment_date, amount, installment_type, status, payment_id
            FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ?
            ORDER BY installment_date ASC, id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $report = [
            'success' => true,
            'fees_retagged' => 0,
            'unpaid_deleted' => 0,
            'paid_cancelled' => 0,
            'fees_amount_fixed' => 0,
        ];

        if (!$addFeesToFirst && $totalFees > 0) {
            lease_fix_mistagged_combined_fees_rows($conn, $leaseId, $companyId, $totalFees, $lockedIds);

            // Remove duplicate combined_fees rows created after a legacy rent-typed fees row.
            $legacyFees = lease_find_combined_fees_installment_row($rows, $totalFees);
            if ($legacyFees) {
                $legacyId = (int)$legacyFees['id'];
                foreach ($rows as $row) {
                    $rowId = (int)($row['id'] ?? 0);
                    if ($rowId <= 0 || $rowId === $legacyId) {
                        continue;
                    }
                    if ((string)($row['installment_type'] ?? '') !== 'combined_fees') {
                        continue;
                    }
                    if (abs((float)($row['amount'] ?? 0) - $totalFees) > 0.02) {
                        continue;
                    }
                    if (isset($lockedIds[$rowId]) || lease_is_installment_locked($row)) {
                        continue;
                    }
                    $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id = ? AND company_id = ?")
                        ->execute([$leaseId, $rowId, $companyId]);
                    $conn->prepare("DELETE FROM re_lease_installments WHERE id = ? AND lease_id = ? AND company_id = ?")
                        ->execute([$rowId, $leaseId, $companyId]);
                    $report['unpaid_deleted']++;
                }
            }

            $cfRow = lease_ensure_combined_fees_installment($conn, $leaseId, $companyId, [
                'start_date' => $lease['start_date'] ?? null,
                'add_fees_to_first_installment' => 0,
                'is_renewal_lease' => !empty($lease['is_renewal_lease']) ? 1 : 0,
                'security_deposit' => $lease['security_deposit'] ?? 0,
                'sep_security_deposit' => $lease['sep_security_deposit'] ?? 0,
                'chiller_fees' => $lease['chiller_fees'] ?? 0,
                'ejari_fees' => $lease['ejari_fees'] ?? 0,
                'admin_fees' => $lease['admin_fees'] ?? 0,
                'commission_fees' => $lease['commission_fees'] ?? 0,
                'split_chiller_fees' => $lease['split_chiller_fees'] ?? 0,
                'split_ejari_fees' => $lease['split_ejari_fees'] ?? 0,
                'split_admin_fees' => $lease['split_admin_fees'] ?? 0,
                'split_commission_fees' => $lease['split_commission_fees'] ?? 0,
                'sep_chiller_fees' => $lease['sep_chiller_fees'] ?? 0,
                'sep_ejari_fees' => $lease['sep_ejari_fees'] ?? 0,
                'sep_admin_fees' => $lease['sep_admin_fees'] ?? 0,
                'sep_commission_fees' => $lease['sep_commission_fees'] ?? 0,
                'vat_distribution_type' => $lease['vat_distribution_type'] ?? 'first_installment',
                'total_vat_amount' => $lease['total_vat_amount'] ?? 0,
            ], $lockedIds);

            if ($cfRow && (string)($cfRow['installment_type'] ?? '') === 'combined_fees') {
                $report['fees_retagged']++;
            }

            $cfId = (int)($cfRow['id'] ?? 0);
            if ($cfId > 0 && !isset($lockedIds[$cfId]) && abs((float)($cfRow['amount'] ?? 0) - $totalFees) > 0.02) {
                $conn->prepare("UPDATE re_lease_installments SET amount = ? WHERE id = ? AND company_id = ?")
                    ->execute([$totalFees, $cfId, $companyId]);
                $conn->prepare("UPDATE re_post_dated_cheques SET cheque_amount = ? WHERE lease_id = ? AND installment_id = ? AND company_id = ?")
                    ->execute([$totalFees, $leaseId, $cfId, $companyId]);
                $report['fees_amount_fixed']++;
            }

            $stmt->execute([$leaseId, $companyId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $combinedFeesRow = (!$addFeesToFirst && $totalFees > 0)
            ? lease_find_combined_fees_installment_row($rows, $totalFees)
            : null;
        $combinedFeesId = $combinedFeesRow ? (int)$combinedFeesRow['id'] : 0;

        $rentLikeAll = lease_filter_rent_like_installment_rows(
            $rows,
            $rentCount,
            $totalFees,
            $addFeesToFirst,
            [],
            false
        );
        $canonicalRentIds = array_fill_keys(
            array_map('intval', array_column(array_slice($rentLikeAll, 0, $rentCount), 'id')),
            true
        );

        foreach ($rentLikeAll as $index => $row) {
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId <= 0 || isset($canonicalRentIds[$rowId])) {
                continue;
            }

            $isLocked = isset($lockedIds[$rowId]) || lease_is_installment_locked($row);
            if (!$isLocked) {
                $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id = ? AND company_id = ?")
                    ->execute([$leaseId, $rowId, $companyId]);
                $conn->prepare("DELETE FROM re_lease_cheques WHERE lease_id = ? AND installment_id = ?")
                    ->execute([$leaseId, $rowId]);
                $conn->prepare("DELETE FROM re_lease_installments WHERE id = ? AND lease_id = ? AND company_id = ?")
                    ->execute([$rowId, $leaseId, $companyId]);
                $report['unpaid_deleted']++;
                continue;
            }

            $conn->prepare("
                UPDATE re_lease_installments
                SET status = 'cancelled',
                    notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
                WHERE id = ? AND lease_id = ? AND company_id = ?
            ")->execute([
                'Operational schedule repair: duplicate rent row removed from schedule (payment history preserved).',
                $rowId,
                $leaseId,
                $companyId,
            ]);
            $report['paid_cancelled']++;
        }

        if ($combinedFeesId > 0) {
            foreach ($rows as $row) {
                $rowId = (int)($row['id'] ?? 0);
                if ($rowId <= 0 || $rowId === $combinedFeesId) {
                    continue;
                }
                if (abs((float)($row['amount'] ?? 0) - $totalFees) > 0.02) {
                    continue;
                }
                $type = (string)($row['installment_type'] ?? '');
                if ($type !== '' && $type !== 'rent' && $type !== 'combined_fees') {
                    continue;
                }
                if (isset($canonicalRentIds[$rowId])) {
                    continue;
                }
                if (!isset($lockedIds[$rowId]) && !lease_is_installment_locked($row)) {
                    $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id = ? AND company_id = ?")
                        ->execute([$leaseId, $rowId, $companyId]);
                    $conn->prepare("DELETE FROM re_lease_installments WHERE id = ? AND lease_id = ? AND company_id = ?")
                        ->execute([$rowId, $leaseId, $companyId]);
                    $report['unpaid_deleted']++;
                }
            }
        }

        lease_sync_cheque_amounts($conn, $leaseId, $companyId);

        return $report;
    }
}

if (!function_exists('lease_fix_mistagged_combined_fees_rows')) {
    /**
     * Reclassify rent rows that were incorrectly tagged as combined_fees.
     *
     * @param array<int,bool> $lockedIds
     */
    function lease_fix_mistagged_combined_fees_rows(PDO $conn, int $leaseId, int $companyId, float $totalFees, array $lockedIds = []): void
    {
        $stmt = $conn->prepare("
            SELECT id, amount, installment_type
            FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ? AND installment_type = 'combined_fees'
        ");
        $stmt->execute([$leaseId, $companyId]);
        $clear = $conn->prepare("UPDATE re_lease_installments SET installment_type = 'rent' WHERE id = ? AND company_id = ?");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId <= 0 || isset($lockedIds[$rowId])) {
                continue;
            }
            if (lease_is_mistagged_combined_fees_row($row, $totalFees)) {
                $clear->execute([$rowId, $companyId]);
            }
        }
    }
}

if (!function_exists('lease_ensure_combined_fees_installment')) {
    /**
     * Ensure a standalone combined-fees installment exists when fees are not merged into rent.
     *
     * @return array<string,mixed>|null
     */
    function lease_ensure_combined_fees_installment(PDO $conn, int $leaseId, int $companyId, array $lease, array $lockedIds = []): ?array
    {
        if (!empty($lease['add_fees_to_first_installment'])) {
            return null;
        }
        $totals = lease_compute_totals($lease);
        $totalFees = (float)($totals['to_first'] ?? 0);
        if ($totalFees <= 0.005) {
            return null;
        }

        lease_fix_mistagged_combined_fees_rows($conn, $leaseId, $companyId, $totalFees, $lockedIds);

        // Legacy rows may have company_id = 0 — align before we search or insert schedule rows.
        $conn->prepare("
            UPDATE re_lease_installments
            SET company_id = ?
            WHERE lease_id = ? AND (company_id = 0 OR company_id IS NULL)
        ")->execute([$companyId, $leaseId]);

        $stmt = $conn->prepare("
            SELECT id, installment_date, amount, installment_type, status, payment_id
            FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ?
            ORDER BY installment_date ASC, id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return null;
        }

        $existing = lease_find_combined_fees_installment_row($rows, $totalFees);
        if ($existing) {
            if ((string)($existing['installment_type'] ?? '') !== 'combined_fees') {
                $conn->prepare("UPDATE re_lease_installments SET installment_type = 'combined_fees' WHERE id = ? AND company_id = ?")
                    ->execute([(int)$existing['id'], $companyId]);
                $existing['installment_type'] = 'combined_fees';
            }
            return $existing;
        }

        $firstDate = (string)($lease['start_date'] ?? $rows[0]['installment_date']);
        foreach ($rows as $row) {
            if ($row['installment_date'] === $firstDate) {
                $firstDate = (string)$row['installment_date'];
                break;
            }
        }

        $insert = $conn->prepare("
            INSERT INTO re_lease_installments
                (company_id, lease_id, installment_date, amount, status, installment_type)
            VALUES (?, ?, ?, ?, 'pending', 'combined_fees')
        ");
        $insert->execute([$companyId, $leaseId, $firstDate, $totalFees]);
        return [
            'id' => (int)$conn->lastInsertId(),
            'installment_date' => $firstDate,
            'amount' => $totalFees,
            'installment_type' => 'combined_fees',
            'status' => 'pending',
            'payment_id' => null,
        ];
    }
}

if (!function_exists('lease_reset_stale_installment_statuses')) {
    /**
     * Clear stale paid/partial flags left after financial resets when no payment evidence exists.
     */
    function lease_reset_stale_installment_statuses(PDO $conn, int $leaseId, int $companyId): void
    {
        $hasAlloc = false;
        try {
            $chk = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_payment_allocations'");
            $hasAlloc = ((int)$chk->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $hasAlloc = false;
        }

        $allocSql = $hasAlloc ? "
            AND NOT EXISTS (
                SELECT 1 FROM re_payment_allocations pa WHERE pa.installment_id = li.id LIMIT 1
            )
        " : '';

        $conn->prepare("
            UPDATE re_lease_installments li
            SET status = 'pending', paid_at = NULL
            WHERE li.lease_id = ?
              AND li.company_id = ?
              AND li.status IN ('paid', 'partial')
              AND li.payment_id IS NULL
              AND li.invoice_id IS NULL
              $allocSql
              AND NOT EXISTS (
                  SELECT 1 FROM re_payments p
                  WHERE p.installment_id = li.id AND p.lease_id = li.lease_id
                  LIMIT 1
              )
              AND NOT EXISTS (
                  SELECT 1 FROM re_post_dated_cheques c
                  WHERE c.installment_id = li.id AND c.lease_id = li.lease_id AND c.status = 'cleared'
                  LIMIT 1
              )
        ")->execute([$leaseId, $companyId]);
    }
}

if (!function_exists('lease_fix_mistagged_separate_installments')) {
    /**
     * Untag rent rows that were incorrectly marked as separate fee installments.
     */
    function lease_fix_mistagged_separate_installments(PDO $conn, int $leaseId, int $companyId, array $lease): void
    {
        $stmt = $conn->prepare("
            SELECT id, installment_date, amount, installment_type, status, payment_id
            FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ?
            ORDER BY installment_date ASC, id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }

        $expectedByType = [];
        foreach (lease_separate_fee_candidates_from_lease($lease) as $candidate) {
            if ($candidate['dist'] === 'separate' && $candidate['active'] && $candidate['amount'] > 0) {
                $expectedByType[$candidate['type']] = (float)$candidate['amount'];
            }
        }

        $clear = $conn->prepare("UPDATE re_lease_installments SET installment_type = 'rent' WHERE id = ? AND company_id = ?");
        foreach ($rows as $row) {
            $type = (string)($row['installment_type'] ?? '');
            if ($type === '' || !isset($expectedByType[$type])) {
                continue;
            }
            $expected = $expectedByType[$type];
            $amount = (float)$row['amount'];
            if ($amount <= ($expected * 1.5)) {
                continue;
            }
            $clear->execute([(int)$row['id'], $companyId]);
        }
    }
}

if (!function_exists('lease_ensure_separate_fee_installments')) {
    function lease_ensure_separate_fee_installments(PDO $conn, int $leaseId, int $companyId, array $lease): void
    {
        lease_fix_mistagged_separate_installments($conn, $leaseId, $companyId, $lease);

        $stmt = $conn->prepare("
            SELECT id, installment_date, amount, installment_type, status, payment_id
            FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ?
            ORDER BY installment_date ASC, id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }

        $firstDate = (string)$rows[0]['installment_date'];
        $insert = $conn->prepare("
            INSERT INTO re_lease_installments
                (company_id, lease_id, installment_date, amount, status, installment_type)
            VALUES (?, ?, ?, ?, 'pending', ?)
        ");
        $tag = $conn->prepare('UPDATE re_lease_installments SET installment_type = ? WHERE id = ? AND company_id = ?');

        foreach (lease_separate_fee_candidates_from_lease($lease) as $candidate) {
            if ($candidate['dist'] !== 'separate' || !$candidate['active'] || $candidate['amount'] <= 0) {
                continue;
            }
            $match = lease_find_separate_installment_row($rows, $candidate['type'], $candidate['amount'], $firstDate);
            if ($match) {
                if ((string)($match['installment_type'] ?? '') !== $candidate['type']) {
                    $tag->execute([$candidate['type'], (int)$match['id'], $companyId]);
                    $match['installment_type'] = $candidate['type'];
                }
                continue;
            }
            $insert->execute([$companyId, $leaseId, $firstDate, $candidate['amount'], $candidate['type']]);
            $rows[] = [
                'id' => (int)$conn->lastInsertId(),
                'installment_date' => $firstDate,
                'amount' => $candidate['amount'],
                'installment_type' => $candidate['type'],
                'status' => 'pending',
                'payment_id' => null,
            ];
        }
    }
}

if (!function_exists('lease_reconcile_separate_fee_installments')) {
    function lease_reconcile_separate_fee_installments(PDO $conn, int $leaseId, int $companyId, array $lease): void
    {
        lease_ensure_separate_fee_installments($conn, $leaseId, $companyId, $lease);

        $stmt = $conn->prepare("
            SELECT li.id, li.installment_date, li.amount, li.installment_type, li.status, li.payment_id,
                   (SELECT c.status FROM re_post_dated_cheques c
                     WHERE c.installment_id = li.id AND c.lease_id = li.lease_id
                     ORDER BY c.id DESC LIMIT 1) AS cheque_status
            FROM re_lease_installments li
            WHERE li.lease_id = ? AND li.company_id = ?
            ORDER BY li.installment_date ASC, li.id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }

        $firstDate = (string)$rows[0]['installment_date'];
        $updInst = $conn->prepare('UPDATE re_lease_installments SET amount = ? WHERE id = ? AND company_id = ?');
        $updPdc = $conn->prepare('UPDATE re_post_dated_cheques SET cheque_amount = ? WHERE lease_id = ? AND installment_id = ?');
        $updLc = $conn->prepare('UPDATE re_lease_cheques SET cheque_amount = ? WHERE lease_id = ? AND installment_id = ?');

        foreach (lease_separate_fee_candidates_from_lease($lease) as $candidate) {
            if ($candidate['dist'] !== 'separate' || !$candidate['active'] || $candidate['amount'] <= 0) {
                continue;
            }
            $row = lease_find_separate_installment_row($rows, $candidate['type'], $candidate['amount'], $firstDate);
            if (!$row || lease_is_installment_locked($row)) {
                continue;
            }
            $expected = round($candidate['amount'], 2);
            if (abs((float)$row['amount'] - $expected) < 0.009) {
                continue;
            }
            $instId = (int)$row['id'];
            $updInst->execute([$expected, $instId, $companyId]);
            $updPdc->execute([$expected, $leaseId, $instId]);
            $updLc->execute([$expected, $leaseId, $instId]);
        }
    }
}

/**
 * Per-installment rent amounts; last row absorbs rounding remainder.
 *
 * @return list<float>
 */
if (!function_exists('lease_compute_rent_installment_amounts')) {
    function lease_compute_rent_installment_amounts(
        float $effectiveAnnualRent,
        int $numberOfInstallments,
        bool $addFeesToFirst,
        float $totalFeesToFirst
    ): array {
        if ($numberOfInstallments < 1) {
            return [];
        }

        $basePer = round($effectiveAnnualRent / $numberOfInstallments, 2);
        $lastBase = round($effectiveAnnualRent - ($basePer * ($numberOfInstallments - 1)), 2);
        $amounts = [];

        for ($i = 0; $i < $numberOfInstallments; $i++) {
            $amt = ($i === $numberOfInstallments - 1) ? $lastBase : $basePer;
            if ($i === 0 && $addFeesToFirst && $totalFeesToFirst > 0) {
                $amt = round($amt + $totalFeesToFirst, 2);
            }
            $amounts[] = $amt;
        }

        return $amounts;
    }
}

/**
 * Tag legacy separate-fee rows that were saved without installment_type.
 */
if (!function_exists('lease_tag_untyped_separate_installments')) {
    function lease_tag_untyped_separate_installments(PDO $conn, int $leaseId, int $companyId, array $lease): void
    {
        $stmt = $conn->prepare("
            SELECT id, installment_date, amount, installment_type, status, payment_id
            FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ?
            ORDER BY installment_date ASC, id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }

        $firstDate = $rows[0]['installment_date'];
        $candidates = [
            'parking' => (!empty($lease['sep_additional_parking']) && (float)($lease['additional_parking_fee'] ?? 0) > 0)
                ? (float)$lease['additional_parking_fee'] : 0.0,
            'store' => (!empty($lease['sep_additional_store']) && (float)($lease['additional_store_fee'] ?? 0) > 0)
                ? (float)$lease['additional_store_fee'] : 0.0,
            'chiller' => (!empty($lease['sep_chiller_fees'])) ? (float)($lease['chiller_fees'] ?? 0) : 0.0,
            'ejari' => (!empty($lease['sep_ejari_fees'])) ? (float)($lease['ejari_fees'] ?? 0) : 0.0,
            'admin' => (!empty($lease['sep_admin_fees'])) ? (float)($lease['admin_fees'] ?? 0) : 0.0,
            'commission' => (!empty($lease['sep_commission_fees'])) ? (float)($lease['commission_fees'] ?? 0) : 0.0,
            'amc' => (!empty($lease['sep_amc_fees'])) ? (float)($lease['amc_amount'] ?? 0) : 0.0,
        ];

        $usedTypes = [];
        foreach ($rows as $row) {
            $type = (string)($row['installment_type'] ?? '');
            if ($type !== '') {
                $usedTypes[$type] = true;
            }
        }

        $upd = $conn->prepare('UPDATE re_lease_installments SET installment_type = ? WHERE id = ? AND company_id = ?');
        foreach ($rows as $row) {
            if ((string)($row['installment_type'] ?? '') !== '') {
                continue;
            }
            if (lease_is_installment_locked($row)) {
                continue;
            }
            if ($row['installment_date'] !== $firstDate) {
                continue;
            }
            $rowAmount = (float)$row['amount'];
            $bestType = null;
            $bestExpected = 0.0;
            foreach ($candidates as $type => $expected) {
                if ($expected <= 0 || isset($usedTypes[$type])) {
                    continue;
                }
                if (abs($rowAmount - $expected) < 0.02) {
                    if ($bestType === null || $expected > $bestExpected) {
                        $bestType = $type;
                        $bestExpected = $expected;
                    }
                }
            }
            if ($bestType !== null) {
                $upd->execute([$bestType, (int)$row['id'], $companyId]);
                $usedTypes[$bestType] = true;
            }
        }
    }
}

/**
 * Ordered rent-only installment rows (excludes separate/combined fee rows).
 *
 * @return list<array<string,mixed>>
 */
if (!function_exists('lease_fetch_rent_installment_rows')) {
    function lease_fetch_rent_installment_rows(PDO $conn, int $leaseId, int $companyId, array $lease): array
    {
        lease_tag_untyped_separate_installments($conn, $leaseId, $companyId, $lease);

        $stmt = $conn->prepare("
            SELECT li.id, li.installment_date, li.amount, li.status, li.payment_id, li.installment_type,
                   (SELECT c.status FROM re_post_dated_cheques c
                     WHERE c.installment_id = li.id AND c.lease_id = li.lease_id
                     ORDER BY c.id DESC LIMIT 1) AS cheque_status
            FROM re_lease_installments li
            WHERE li.lease_id = ? AND li.company_id = ?
            ORDER BY li.installment_date ASC, li.id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $nonRent = lease_non_rent_installment_types();
        $rentRows = [];
        foreach ($rows as $row) {
            $type = (string)($row['installment_type'] ?? '');
            if ($type !== '' && in_array($type, $nonRent, true)) {
                continue;
            }
            $rentRows[] = $row;
        }

        return $rentRows;
    }
}

/**
 * Keep both cheque mirror tables (re_post_dated_cheques + re_lease_cheques) equal
 * to the installment amount for every UNLOCKED row. Locked rows (paid/partial/
 * allocated/cleared) are left frozen. This enforces the invariant:
 *   cheque_amount == installment.amount   (for unpaid rows)
 */
if (!function_exists('lease_sync_cheque_amounts')) {
    function lease_sync_cheque_amounts(PDO $conn, int $leaseId, int $companyId): void
    {
        $locked = function_exists('lease_locked_installment_ids')
            ? lease_locked_installment_ids($conn, $leaseId, $companyId)
            : [];

        $stmt = $conn->prepare("SELECT id, amount FROM re_lease_installments WHERE lease_id = ? AND company_id = ?");
        $stmt->execute([$leaseId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }

        // Never change a cleared cheque's amount.
        $updPdc = $conn->prepare("UPDATE re_post_dated_cheques SET cheque_amount = ? WHERE lease_id = ? AND installment_id = ? AND (status IS NULL OR status <> 'cleared')");
        $updLc  = $conn->prepare("UPDATE re_lease_cheques SET cheque_amount = ? WHERE lease_id = ? AND installment_id = ?");

        foreach ($rows as $row) {
            $instId = (int)$row['id'];
            if (isset($locked[$instId])) {
                continue;
            }
            $amt = round((float)$row['amount'], 2);
            $updPdc->execute([$amt, $leaseId, $instId]);
            try {
                $updLc->execute([$amt, $leaseId, $instId]);
            } catch (Throwable $e) {
                // re_lease_cheques is optional/secondary — ignore if absent.
            }
        }
    }
}

/**
 * Safely realign the deferred-revenue recognition schedule after a schedule edit.
 *
 * ONLY pending + unfunded rows (deferred_payment_id IS NULL) tied to an UNLOCKED
 * installment are updated to match the installment's current amount/date. Recognised,
 * skipped, or funded rows — and rows tied to locked installments — are never touched.
 * No-op when the table is absent or the lease has no schedule rows.
 */
if (!function_exists('lease_sync_recognition_schedule')) {
    function lease_sync_recognition_schedule(PDO $conn, int $leaseId, int $companyId): void
    {
        try {
            $chk = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_rent_recognition_schedule'");
            if (((int)$chk->fetchColumn()) === 0) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }

        $locked = function_exists('lease_locked_installment_ids')
            ? lease_locked_installment_ids($conn, $leaseId, $companyId)
            : [];

        $stmt = $conn->prepare("
            SELECT rrs.id AS rrs_id, rrs.installment_id, li.amount, li.installment_date
            FROM re_rent_recognition_schedule rrs
            JOIN re_lease_installments li ON li.id = rrs.installment_id AND li.company_id = rrs.company_id
            WHERE rrs.company_id = ?
              AND rrs.lease_id = ?
              AND rrs.status = 'pending'
              AND rrs.deferred_payment_id IS NULL
              AND rrs.installment_id IS NOT NULL
        ");
        $stmt->execute([$companyId, $leaseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }

        $upd = $conn->prepare("
            UPDATE re_rent_recognition_schedule
            SET amount = ?, recognition_date = ?
            WHERE id = ? AND company_id = ? AND status = 'pending' AND deferred_payment_id IS NULL
        ");
        foreach ($rows as $row) {
            if (isset($locked[(int)$row['installment_id']])) {
                continue;
            }
            $upd->execute([
                round((float)$row['amount'], 2),
                (string)$row['installment_date'],
                (int)$row['rrs_id'],
                $companyId,
            ]);
        }
    }
}

/**
 * Align unpaid rent installment (and linked cheque) amounts with lease terms.
 */
if (!function_exists('lease_reconcile_unpaid_rent_installments')) {
    function lease_reconcile_unpaid_rent_installments(
        PDO $conn,
        int $leaseId,
        int $companyId,
        float $effectiveAnnualRent,
        int $numberOfInstallments,
        bool $addFeesToFirst,
        float $totalFeesToFirst
    ): void {
        if ($numberOfInstallments < 1) {
            return;
        }

        $leaseStmt = $conn->prepare('SELECT * FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1');
        $leaseStmt->execute([$leaseId, $companyId]);
        $lease = $leaseStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lease) {
            return;
        }

        $rentRows = lease_fetch_rent_installment_rows($conn, $leaseId, $companyId, $lease);
        if (count($rentRows) !== $numberOfInstallments) {
            return;
        }

        $targetAmounts = lease_compute_rent_installment_amounts(
            $effectiveAnnualRent,
            $numberOfInstallments,
            $addFeesToFirst,
            $totalFeesToFirst
        );

        $updInst = $conn->prepare('UPDATE re_lease_installments SET amount = ? WHERE id = ? AND company_id = ?');
        $updPdc = $conn->prepare('UPDATE re_post_dated_cheques SET cheque_amount = ? WHERE lease_id = ? AND installment_id = ?');
        $updLc = $conn->prepare('UPDATE re_lease_cheques SET cheque_amount = ? WHERE lease_id = ? AND installment_id = ?');

        foreach ($rentRows as $idx => $row) {
            if (lease_is_installment_locked($row)) {
                continue;
            }
            $newAmt = $targetAmounts[$idx] ?? (float)$row['amount'];
            if (abs((float)$row['amount'] - $newAmt) < 0.009) {
                continue;
            }
            $instId = (int)$row['id'];
            $updInst->execute([$newAmt, $instId, $companyId]);
            $updPdc->execute([$newAmt, $leaseId, $instId]);
            $updLc->execute([$newAmt, $leaseId, $instId]);
        }
    }
}
