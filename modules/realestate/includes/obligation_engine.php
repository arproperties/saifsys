<?php
/**
 * Phase 2 obligation engine.
 *
 * Write boundary:
 * - writes only to re_obligations
 * - only for leases explicitly marked accounting_mode = invoice
 * - no invoices, receipts, journals, allocations, cheques, or report tables
 */
declare(strict_types=1);

require_once __DIR__ . '/obligation_preview_helper.php';

const RE_OBLIGATION_ENGINE_VERSION = 'phase2.1';

if (!function_exists('re_obligation_engine_schema_ready')) {
    function re_obligation_engine_schema_ready(PDO $conn): bool
    {
        return re_obligation_table_exists($conn, 're_obligations')
            && re_obligation_column_exists($conn, 're_obligations', 'obligation_key')
            && re_obligation_column_exists($conn, 're_obligations', 'engine_version')
            && re_obligation_column_exists($conn, 're_obligations', 'generated_at')
            && re_obligation_column_exists($conn, 're_obligations', 'generated_by');
    }
}

if (!function_exists('re_obligation_engine_is_protected')) {
    function re_obligation_engine_is_protected(array $existing): bool
    {
        $status = (string)($existing['status'] ?? '');
        return (float)($existing['allocated_amount'] ?? 0) > 0
            || !empty($existing['invoice_id'])
            || in_array($status, ['partially_allocated', 'settled', 'cancelled', 'waived'], true);
    }
}

if (!function_exists('re_obligation_engine_prepare_rows')) {
    /**
     * @return array<string,mixed>
     */
    function re_obligation_engine_prepare_rows(PDO $conn, int $companyId, int $leaseId): array
    {
        $preview = re_obligation_preview_generate($conn, $companyId, $leaseId);
        if (empty($preview['success'])) {
            return $preview;
        }

        if (empty($preview['feature_enabled'])) {
            return [
                'success' => false,
                'error' => 'Invoice Mode feature flag is disabled.',
                'preview' => $preview,
            ];
        }

        if (($preview['accounting_mode'] ?? 'legacy') !== 'invoice') {
            return [
                'success' => false,
                'error' => 'This lease is still in Legacy accounting mode. No obligations were generated.',
                'preview' => $preview,
            ];
        }

        if (!re_obligation_engine_schema_ready($conn)) {
            return [
                'success' => false,
                'error' => 'Phase 2 obligation engine migration has not been applied.',
                'preview' => $preview,
            ];
        }

        return [
            'success' => true,
            'preview' => $preview,
            'rows' => $preview['preview_obligations'] ?? [],
        ];
    }
}

if (!function_exists('re_obligation_engine_recognition_status')) {
    function re_obligation_engine_recognition_status(array $row): string
    {
        return in_array((string)($row['accounting_class'] ?? ''), ['liability', 'pass_through'], true)
            ? 'not_applicable'
            : 'pending';
    }
}

if (!function_exists('re_obligation_engine_current_by_key')) {
    /**
     * @return array<string,mixed>|null
     */
    function re_obligation_engine_current_by_key(PDO $conn, int $companyId, string $key): ?array
    {
        $stmt = $conn->prepare("
            SELECT id, status, allocated_amount, invoice_id
            FROM re_obligations
            WHERE company_id = ? AND obligation_key = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$companyId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('re_obligation_engine_insert_row')) {
    function re_obligation_engine_insert_row(PDO $conn, int $companyId, array $lease, array $row, ?int $userId): void
    {
        $stmt = $conn->prepare("
            INSERT INTO re_obligations (
                company_id, lease_id, tenant_id, source_type, source_id, obligation_key,
                obligation_type, accounting_class, description, period_start, period_end, due_date,
                tax_treatment, tax_rate, subtotal_amount, vat_amount, total_amount,
                allocated_amount, status, invoice_id, recognition_status, recognition_journal_id,
                notes, engine_version, generated_at, generated_by, created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                0.00, 'open', NULL, ?, NULL,
                NULL, ?, NOW(), ?, ?
            )
        ");
        $stmt->execute([
            $companyId,
            (int)$lease['id'],
            !empty($lease['tenant_id']) ? (int)$lease['tenant_id'] : null,
            (string)($row['source_type'] ?? 'lease'),
            isset($row['source_id']) ? (int)$row['source_id'] : null,
            (string)$row['obligation_key'],
            (string)($row['obligation_type'] ?? 'other'),
            (string)($row['accounting_class'] ?? 'revenue'),
            (string)($row['description'] ?? ''),
            $row['period_start'] ?: null,
            $row['period_end'] ?: null,
            (string)$row['due_date'],
            (string)($row['tax_treatment'] ?? 'exempt'),
            (float)($row['tax_rate'] ?? 0),
            (float)($row['subtotal_amount'] ?? 0),
            (float)($row['vat_amount'] ?? 0),
            (float)($row['total_amount'] ?? 0),
            re_obligation_engine_recognition_status($row),
            RE_OBLIGATION_ENGINE_VERSION,
            $userId,
            $userId,
        ]);
    }
}

if (!function_exists('re_obligation_engine_update_row')) {
    function re_obligation_engine_update_row(PDO $conn, int $companyId, int $id, array $lease, array $row, ?int $userId): bool
    {
        $stmt = $conn->prepare("
            UPDATE re_obligations
            SET lease_id = ?,
                tenant_id = ?,
                source_type = ?,
                source_id = ?,
                obligation_type = ?,
                accounting_class = ?,
                description = ?,
                period_start = ?,
                period_end = ?,
                due_date = ?,
                tax_treatment = ?,
                tax_rate = ?,
                subtotal_amount = ?,
                vat_amount = ?,
                total_amount = ?,
                recognition_status = ?,
                engine_version = ?,
                generated_at = NOW(),
                generated_by = ?
            WHERE company_id = ?
              AND id = ?
              AND allocated_amount = 0.00
              AND invoice_id IS NULL
              AND status IN ('draft', 'open')
        ");
        $stmt->execute([
            (int)$lease['id'],
            !empty($lease['tenant_id']) ? (int)$lease['tenant_id'] : null,
            (string)($row['source_type'] ?? 'lease'),
            isset($row['source_id']) ? (int)$row['source_id'] : null,
            (string)($row['obligation_type'] ?? 'other'),
            (string)($row['accounting_class'] ?? 'revenue'),
            (string)($row['description'] ?? ''),
            $row['period_start'] ?: null,
            $row['period_end'] ?: null,
            (string)$row['due_date'],
            (string)($row['tax_treatment'] ?? 'exempt'),
            (float)($row['tax_rate'] ?? 0),
            (float)($row['subtotal_amount'] ?? 0),
            (float)($row['vat_amount'] ?? 0),
            (float)($row['total_amount'] ?? 0),
            re_obligation_engine_recognition_status($row),
            RE_OBLIGATION_ENGINE_VERSION,
            $userId,
            $companyId,
            $id,
        ]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('re_obligation_engine_cleanup_obsolete_unallocated')) {
    /**
     * Remove old engine-generated obligations that are no longer produced by the
     * current preview, plus their unpaid/unallocated candidate invoices.
     *
     * This is intentionally conservative: if money, allocations, multi-line
     * invoices, or protected statuses exist, the old row is left untouched.
     *
     * @return array<string,int>
     */
    function re_obligation_engine_cleanup_obsolete_unallocated(PDO $conn, int $companyId, int $leaseId, array $currentKeys): array
    {
        $stats = ['obsolete_found' => 0, 'deleted_obligations' => 0, 'deleted_invoices' => 0, 'deleted_journals' => 0, 'protected' => 0];
        if (!re_obligation_engine_schema_ready($conn)) {
            return $stats;
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM re_obligations
            WHERE company_id = ?
              AND lease_id = ?
              AND engine_version IS NOT NULL
              AND obligation_key IS NOT NULL
        ");
        $stmt->execute([$companyId, $leaseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $currentKeySet = array_fill_keys($currentKeys, true);

        foreach ($rows as $row) {
            $key = (string)($row['obligation_key'] ?? '');
            if ($key === '' || isset($currentKeySet[$key])) {
                continue;
            }
            $stats['obsolete_found']++;
            if (re_obligation_engine_is_protected($row)) {
                $stats['protected']++;
                continue;
            }

            $obligationId = (int)$row['id'];
            $invoiceIds = [];
            $invStmt = $conn->prepare("
                SELECT DISTINCT invoice_id
                FROM re_invoice_items
                WHERE company_id = ? AND obligation_id = ? AND invoice_id IS NOT NULL
            ");
            $invStmt->execute([$companyId, $obligationId]);
            $invoiceIds = array_merge($invoiceIds, array_map('intval', $invStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
            $candStmt = $conn->prepare("SELECT invoice_id FROM re_invoice_candidates WHERE company_id = ? AND obligation_id = ? AND invoice_id IS NOT NULL");
            $candStmt->execute([$companyId, $obligationId]);
            $invoiceIds = array_merge($invoiceIds, array_map('intval', $candStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
            if (!empty($row['invoice_id'])) {
                $invoiceIds[] = (int)$row['invoice_id'];
            }
            $invoiceIds = array_values(array_unique(array_filter($invoiceIds)));

            $safeInvoiceIds = [];
            foreach ($invoiceIds as $invoiceId) {
                $safe = $conn->prepare("
                    SELECT i.id,
                           COALESCE(i.paid_amount, 0) AS paid_amount,
                           (SELECT COUNT(*) FROM re_receipt_allocations ra WHERE ra.company_id = i.company_id AND ra.invoice_id = i.id) AS allocation_count,
                           (SELECT COUNT(*) FROM re_invoice_items ii WHERE ii.company_id = i.company_id AND ii.invoice_id = i.id) AS item_count,
                           (SELECT COUNT(*) FROM re_invoice_items ii WHERE ii.company_id = i.company_id AND ii.invoice_id = i.id AND ii.obligation_id = ?) AS target_item_count
                    FROM re_invoices i
                    WHERE i.company_id = ? AND i.id = ? AND i.lease_id = ?
                    LIMIT 1
                ");
                $safe->execute([$obligationId, $companyId, $invoiceId, $leaseId]);
                $inv = $safe->fetch(PDO::FETCH_ASSOC);
                if (!$inv || (float)$inv['paid_amount'] > 0.005 || (int)$inv['allocation_count'] > 0 || (int)$inv['item_count'] !== (int)$inv['target_item_count']) {
                    $stats['protected']++;
                    continue 2;
                }
                $safeInvoiceIds[] = $invoiceId;
            }

            $journalIds = [];
            if (!empty($row['recognition_journal_id'])) {
                $journalIds[] = (int)$row['recognition_journal_id'];
            }
            if ($safeInvoiceIds) {
                $ph = implode(',', array_fill(0, count($safeInvoiceIds), '?'));
                $jh = $conn->prepare("SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type = 'invoice' AND reference_id IN ($ph)");
                $jh->execute(array_merge([$companyId], $safeInvoiceIds));
                $journalIds = array_merge($journalIds, array_map('intval', $jh->fetchAll(PDO::FETCH_COLUMN) ?: []));
            }
            $journalIds = array_values(array_unique(array_filter($journalIds)));

            if ($journalIds) {
                $ph = implode(',', array_fill(0, count($journalIds), '?'));
                $conn->prepare("DELETE FROM re_general_ledger WHERE journal_id IN ($ph)")->execute($journalIds);
                $conn->prepare("DELETE FROM re_account_ledger_entries WHERE journal_id IN ($ph)")->execute($journalIds);
                $conn->prepare("DELETE FROM re_accounting_postings WHERE journal_id IN ($ph)")->execute($journalIds);
                $conn->prepare("DELETE FROM re_journal_lines WHERE journal_id IN ($ph)")->execute($journalIds);
                $conn->prepare("DELETE FROM re_journal_headers WHERE id IN ($ph)")->execute($journalIds);
                $stats['deleted_journals'] += count($journalIds);
            }

            $conn->prepare("DELETE FROM re_invoice_candidates WHERE company_id = ? AND obligation_id = ?")->execute([$companyId, $obligationId]);
            $conn->prepare("DELETE FROM re_invoice_items WHERE company_id = ? AND obligation_id = ?")->execute([$companyId, $obligationId]);
            if ($safeInvoiceIds) {
                $ph = implode(',', array_fill(0, count($safeInvoiceIds), '?'));
                $conn->prepare("DELETE FROM re_invoices WHERE company_id = ? AND id IN ($ph)")->execute(array_merge([$companyId], $safeInvoiceIds));
                $stats['deleted_invoices'] += count($safeInvoiceIds);
            }
            $conn->prepare("DELETE FROM re_obligations WHERE company_id = ? AND id = ?")->execute([$companyId, $obligationId]);
            $stats['deleted_obligations']++;
        }

        return $stats;
    }
}

if (!function_exists('re_obligation_engine_generate_for_lease')) {
    /**
     * @return array<string,mixed>
     */
    function re_obligation_engine_generate_for_lease(PDO $conn, int $companyId, int $leaseId, ?int $userId = null): array
    {
        $prepared = re_obligation_engine_prepare_rows($conn, $companyId, $leaseId);
        if (empty($prepared['success'])) {
            return $prepared;
        }

        $preview = $prepared['preview'];
        $lease = $preview['lease'];
        $rows = $prepared['rows'];
        $stats = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'protected' => 0,
            'total_rows' => count($rows),
        ];

        $startedHere = !$conn->inTransaction();
        if ($startedHere) {
            $conn->beginTransaction();
        }

        try {
            $currentKeys = array_values(array_filter(array_map(static fn($row) => (string)($row['obligation_key'] ?? ''), $rows)));
            $cleanup = re_obligation_engine_cleanup_obsolete_unallocated($conn, $companyId, $leaseId, $currentKeys);
            $stats['obsolete_deleted'] = (int)($cleanup['deleted_obligations'] ?? 0);
            $stats['obsolete_invoices_deleted'] = (int)($cleanup['deleted_invoices'] ?? 0);
            $stats['obsolete_protected'] = (int)($cleanup['protected'] ?? 0);

            foreach ($rows as $row) {
                $key = (string)($row['obligation_key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $existing = re_obligation_engine_current_by_key($conn, $companyId, $key);
                if (!$existing) {
                    re_obligation_engine_insert_row($conn, $companyId, $lease, $row, $userId);
                    $stats['created']++;
                    continue;
                }

                if (re_obligation_engine_is_protected($existing)) {
                    $stats['protected']++;
                    continue;
                }

                if (re_obligation_engine_update_row($conn, $companyId, (int)$existing['id'], $lease, $row, $userId)) {
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }

            if ($startedHere) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($startedHere && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Could not generate obligations: ' . $e->getMessage(),
                'preview' => $preview,
                'stats' => $stats,
            ];
        }

        return [
            'success' => true,
            'preview' => $preview,
            'stats' => $stats,
        ];
    }
}

if (!function_exists('re_obligation_engine_existing_for_lease')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_obligation_engine_existing_for_lease(PDO $conn, int $companyId, int $leaseId): array
    {
        if (!re_obligation_table_exists($conn, 're_obligations')) {
            return [];
        }

        $keySelect = re_obligation_column_exists($conn, 're_obligations', 'obligation_key')
            ? 'obligation_key'
            : "NULL AS obligation_key";
        $engineSelect = re_obligation_column_exists($conn, 're_obligations', 'engine_version')
            ? 'engine_version'
            : "NULL AS engine_version";
        $generatedAtSelect = re_obligation_column_exists($conn, 're_obligations', 'generated_at')
            ? 'generated_at'
            : "NULL AS generated_at";

        $stmt = $conn->prepare("
            SELECT id, {$keySelect}, source_type, source_id, obligation_type, accounting_class,
                   description, period_start, period_end, due_date, tax_treatment, tax_rate,
                   subtotal_amount, vat_amount, total_amount, allocated_amount, status,
                   invoice_id, recognition_status, {$engineSelect}, {$generatedAtSelect}
            FROM re_obligations
            WHERE company_id = ? AND lease_id = ?
            ORDER BY due_date ASC, id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('re_obligation_lease_status_summary')) {
    /**
     * @return array<string,mixed>
     */
    function re_obligation_lease_status_summary(PDO $conn, int $companyId, int $leaseId): array
    {
        if (!re_obligation_table_exists($conn, 're_obligations')) {
            return ['total' => 0, 'by_status' => [], 'cancelled' => 0, 'settled' => 0, 'open' => 0];
        }
        $stmt = $conn->prepare("
            SELECT status, COUNT(*) cnt, COALESCE(SUM(total_amount), 0) total
            FROM re_obligations
            WHERE company_id = ? AND lease_id = ?
            GROUP BY status
        ");
        $stmt->execute([$companyId, $leaseId]);
        $byStatus = [];
        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byStatus[(string)$row['status']] = [
                'count' => (int)$row['cnt'],
                'total' => (float)$row['total'],
            ];
            $total += (int)$row['cnt'];
        }
        return [
            'total' => $total,
            'by_status' => $byStatus,
            'cancelled' => (int)($byStatus['cancelled']['count'] ?? 0),
            'settled' => (int)($byStatus['settled']['count'] ?? 0),
            'open' => (int)($byStatus['open']['count'] ?? 0),
        ];
    }
}

