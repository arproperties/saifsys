<?php
/**
 * Shared "what is really still owed on this installment" resolver.
 *
 * Overdue reports used to trust `re_lease_installments.status = 'pending'` and print
 * `li.amount`. Both are stale in Invoice Mode: money arrives as a receipt allocated to
 * invoices/obligations, and nothing on that path writes the schedule row back to 'paid'.
 * A fully collected installment therefore stayed "pending" for the full face value.
 *
 * lease_view.php resolves each row properly; this helper applies the SAME precedence so
 * every collections/alert screen agrees with the lease page:
 *   1. Invoice Mode cheque receipt summaries (re_lease_cheque_receipt_summaries)
 *   2. Allocations / direct payments / legacy links (get_installments_total_paid_map)
 */

require_once __DIR__ . '/payment_allocation_helper.php';
require_once __DIR__ . '/obligation_preview_helper.php';
require_once __DIR__ . '/accounting_mode_helper.php';
require_once __DIR__ . '/receipt_allocation_engine.php';

if (!function_exists('re_installment_collected_map')) {
    /**
     * Collected total per installment id.
     *
     * @param array<int,array<string,mixed>> $rows rows carrying at least `id` and `lease_id`
     * @return array<int,float> installment_id => collected amount
     */
    function re_installment_collected_map(PDO $conn, int $companyId, array $rows): array
    {
        $installmentIds = [];
        $leaseIds = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $installmentIds[$id] = true;
            }
            $leaseId = (int)($row['lease_id'] ?? 0);
            if ($leaseId > 0) {
                $leaseIds[$leaseId] = true;
            }
        }
        $installmentIds = array_keys($installmentIds);
        if ($installmentIds === []) {
            return [];
        }

        // ── Source 2 first (one batched query for every row) ─────────────────────
        $collected = [];
        try {
            foreach (get_installments_total_paid_map($conn, $installmentIds) as $id => $paid) {
                $collected[(int)$id] = (float)$paid;
            }
        } catch (Throwable $e) {
            error_log('re_installment_collected_map allocations: ' . $e->getMessage());
        }
        foreach ($installmentIds as $id) {
            if (!isset($collected[$id])) {
                $collected[$id] = 0.0;
            }
        }

        // ── Source 1: Invoice Mode cheque receipt coverage, per lease ────────────
        $invoiceLeaseIds = re_installment_invoice_mode_lease_ids($conn, array_keys($leaseIds));
        if ($invoiceLeaseIds === []) {
            return $collected;
        }

        // installment_id => cheque_id, in one query for the whole page.
        $chequeByInstallment = [];
        try {
            $placeholders = implode(',', array_fill(0, count($installmentIds), '?'));
            $stmt = $conn->prepare("
                SELECT c.id AS cheque_id, c.installment_id, c.lease_id
                FROM re_post_dated_cheques c
                WHERE c.company_id = ? AND c.installment_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$companyId], $installmentIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $chequeRow) {
                $chequeByInstallment[(int)$chequeRow['installment_id']] = [
                    'cheque_id' => (int)$chequeRow['cheque_id'],
                    'lease_id'  => (int)$chequeRow['lease_id'],
                ];
            }
        } catch (Throwable $e) {
            error_log('re_installment_collected_map cheque lookup: ' . $e->getMessage());
            return $collected;
        }
        if ($chequeByInstallment === []) {
            return $collected;
        }

        $summariesByLease = [];
        foreach ($installmentIds as $id) {
            $link = $chequeByInstallment[$id] ?? null;
            if (!$link) {
                continue;
            }
            $leaseId = $link['lease_id'];
            if (!isset($invoiceLeaseIds[$leaseId])) {
                continue;
            }
            if (!array_key_exists($leaseId, $summariesByLease)) {
                try {
                    // Read-only: never persist links from a report page.
                    $summariesByLease[$leaseId] = re_lease_cheque_receipt_summaries($conn, $companyId, $leaseId, false);
                } catch (Throwable $e) {
                    error_log('re_installment_collected_map summaries lease ' . $leaseId . ': ' . $e->getMessage());
                    $summariesByLease[$leaseId] = [];
                }
            }
            $summary = $summariesByLease[$leaseId][$link['cheque_id']] ?? null;
            if (!$summary) {
                continue;
            }
            $chequeCollected = (float)($summary['collected_total'] ?? 0);
            if ($chequeCollected > $collected[$id]) {
                $collected[$id] = $chequeCollected;
            }
        }

        return $collected;
    }
}

if (!function_exists('re_installment_invoice_mode_lease_ids')) {
    /**
     * @param list<int> $leaseIds
     * @return array<int,true> lease ids running in Invoice Mode, as a lookup set
     */
    function re_installment_invoice_mode_lease_ids(PDO $conn, array $leaseIds): array
    {
        $ids = [];
        foreach ($leaseIds as $leaseId) {
            $leaseId = (int)$leaseId;
            if ($leaseId > 0) {
                $ids[$leaseId] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }
        $out = [];
        try {
            if (!re_obligation_column_exists($conn, 're_leases', 'accounting_mode')) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare("SELECT id, accounting_mode FROM re_leases WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if (re_accounting_normalize_mode((string)($row['accounting_mode'] ?? 'legacy')) === 'invoice') {
                    $out[(int)$row['id']] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('re_installment_invoice_mode_lease_ids: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('re_apply_installment_outstanding')) {
    /**
     * Annotate overdue-installment rows with what is actually still owed.
     *
     * Adds `collected_amount` and `outstanding_balance` to every row and, by default,
     * drops rows that are already fully collected — those are not overdue at all.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    function re_apply_installment_outstanding(
        PDO $conn,
        int $companyId,
        array $rows,
        bool $dropSettled = true,
        string $amountKey = 'amount'
    ): array {
        if ($rows === []) {
            return [];
        }
        $collected = re_installment_collected_map($conn, $companyId, $rows);

        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $face = (float)($row[$amountKey] ?? 0);
            $paid = (float)($collected[$id] ?? 0);
            $row['collected_amount'] = $paid;
            $row['outstanding_balance'] = max(0.0, round($face - $paid, 2));
            if ($dropSettled && $row['outstanding_balance'] <= 0.005) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }
}
