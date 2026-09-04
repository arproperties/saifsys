<?php
/**
 * Phase 9 reporting helpers for combined Legacy + Invoice Mode reporting.
 *
 * Reports are read-only. These helpers do not recalculate, repost, or correct
 * historical balances.
 */
declare(strict_types=1);

require_once __DIR__ . '/obligation_preview_helper.php';

if (!function_exists('re_report_money')) {
    function re_report_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_report_mode_counts')) {
    function re_report_mode_counts(PDO $conn, int $companyId): array
    {
        $summary = [
            'legacy_total' => 0,
            'invoice_total' => 0,
            'legacy_active' => 0,
            'invoice_active' => 0,
            'legacy_with_payments' => 0,
            'invoice_missing_obligations' => 0,
            'invoice_missing_invoices' => 0,
            'new_legacy' => 0,
            'renewal_legacy' => 0,
        ];
        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(accounting_mode, 'legacy') AS mode,
                       COUNT(*) AS total,
                       SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
                       SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND COALESCE(accounting_mode, 'legacy') = 'legacy' THEN 1 ELSE 0 END) AS new_legacy_count,
                       SUM(CASE WHEN is_renewal_lease = 1 AND COALESCE(accounting_mode, 'legacy') = 'legacy' THEN 1 ELSE 0 END) AS renewal_legacy_count
                FROM re_leases
                WHERE company_id = ?
                GROUP BY COALESCE(accounting_mode, 'legacy')
            ");
            $stmt->execute([$companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if ($row['mode'] === 'invoice') {
                    $summary['invoice_total'] = (int)$row['total'];
                    $summary['invoice_active'] = (int)$row['active_count'];
                } else {
                    $summary['legacy_total'] = (int)$row['total'];
                    $summary['legacy_active'] = (int)$row['active_count'];
                    $summary['new_legacy'] += (int)$row['new_legacy_count'];
                    $summary['renewal_legacy'] += (int)$row['renewal_legacy_count'];
                }
            }
        } catch (Throwable $e) {}
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT l.id)
                FROM re_leases l
                JOIN re_payments p ON p.lease_id = l.id AND p.company_id = l.company_id
                WHERE l.company_id = ? AND COALESCE(l.accounting_mode, 'legacy') = 'legacy'
            ");
            $stmt->execute([$companyId]);
            $summary['legacy_with_payments'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM re_leases l
                WHERE l.company_id = ?
                  AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
                  AND NOT EXISTS (
                        SELECT 1 FROM re_obligations o
                        WHERE o.company_id = l.company_id AND o.lease_id = l.id
                  )
            ");
            $stmt->execute([$companyId]);
            $summary['invoice_missing_obligations'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM re_leases l
                WHERE l.company_id = ?
                  AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
                  AND EXISTS (
                        SELECT 1 FROM re_obligations o
                        WHERE o.company_id = l.company_id AND o.lease_id = l.id
                  )
                  AND NOT EXISTS (
                        SELECT 1 FROM re_invoice_candidates c
                        WHERE c.company_id = l.company_id AND c.lease_id = l.id
                  )
            ");
            $stmt->execute([$companyId]);
            $summary['invoice_missing_invoices'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}
        return $summary;
    }
}

if (!function_exists('re_report_combined_outstanding')) {
    function re_report_combined_outstanding(PDO $conn, int $companyId, string $asOfDate): array
    {
        $legacy = 0.0;
        $invoice = 0.0;
        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(GREATEST(li.amount - COALESCE(paid.total_paid, 0), 0)), 0)
                FROM re_lease_installments li
                JOIN re_leases l ON l.id = li.lease_id AND l.company_id = li.company_id
                LEFT JOIN (
                    SELECT installment_id, COALESCE(SUM(amount_allocated), 0) AS total_paid
                    FROM re_payment_allocations
                    GROUP BY installment_id
                ) paid ON paid.installment_id = li.id
                WHERE li.company_id = ?
                  AND COALESCE(l.accounting_mode, 'legacy') = 'legacy'
                  AND li.installment_date <= ?
                  AND li.status NOT IN ('paid','cancelled','waived','returned')
            ");
            $stmt->execute([$companyId, $asOfDate]);
            $legacy = (float)$stmt->fetchColumn();
        } catch (Throwable $e) {
            try {
                $stmt = $conn->prepare("
                    SELECT COALESCE(SUM(li.amount), 0)
                    FROM re_lease_installments li
                    JOIN re_leases l ON l.id = li.lease_id AND l.company_id = li.company_id
                    WHERE li.company_id = ?
                      AND COALESCE(l.accounting_mode, 'legacy') = 'legacy'
                      AND li.installment_date <= ?
                      AND li.status NOT IN ('paid','cancelled','waived','returned')
                ");
                $stmt->execute([$companyId, $asOfDate]);
                $legacy = (float)$stmt->fetchColumn();
            } catch (Throwable $ignored) {}
        }
        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(i.outstanding_amount), 0)
                FROM re_invoices i
                JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
                WHERE i.company_id = ?
                  AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
                  AND i.invoice_date <= ?
                  AND i.status <> 'cancelled'
            ");
            $stmt->execute([$companyId, $asOfDate]);
            $invoice = (float)$stmt->fetchColumn();
        } catch (Throwable $e) {}
        return [
            'legacy' => re_report_money($legacy),
            'invoice' => re_report_money($invoice),
            'combined' => re_report_money($legacy + $invoice),
        ];
    }
}

if (!function_exists('re_report_combined_collections')) {
    function re_report_combined_collections(PDO $conn, int $companyId, string $dateFrom, string $dateTo): array
    {
        $legacy = 0.0;
        $invoice = 0.0;
        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(p.amount), 0)
                FROM re_payments p
                JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
                WHERE p.company_id = ?
                  AND p.payment_date BETWEEN ? AND ?
                  AND COALESCE(l.accounting_mode, 'legacy') = 'legacy'
            ");
            $stmt->execute([$companyId, $dateFrom, $dateTo]);
            $legacy = (float)$stmt->fetchColumn();
        } catch (Throwable $e) {}
        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(p.amount), 0)
                FROM re_payments p
                JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
                WHERE p.company_id = ?
                  AND p.payment_date BETWEEN ? AND ?
                  AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
                  AND COALESCE(p.receipt_status, 'cleared') = 'cleared'
            ");
            $stmt->execute([$companyId, $dateFrom, $dateTo]);
            $invoice = (float)$stmt->fetchColumn();
        } catch (Throwable $e) {}
        return [
            'legacy' => re_report_money($legacy),
            'invoice' => re_report_money($invoice),
            'combined' => re_report_money($legacy + $invoice),
        ];
    }
}

if (!function_exists('re_report_combined_rent_roll')) {
    function re_report_combined_rent_roll(PDO $conn, int $companyId): array
    {
        $legacy = 0.0;
        $invoice = 0.0;
        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(accounting_mode, 'legacy') AS mode, COALESCE(SUM(annual_rent), 0) AS total
                FROM re_leases
                WHERE company_id = ? AND status = 'active'
                GROUP BY COALESCE(accounting_mode, 'legacy')
            ");
            $stmt->execute([$companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if ($row['mode'] === 'invoice') {
                    $invoice = (float)$row['total'];
                } else {
                    $legacy += (float)$row['total'];
                }
            }
        } catch (Throwable $e) {}
        return [
            'legacy' => re_report_money($legacy),
            'invoice' => re_report_money($invoice),
            'combined' => re_report_money($legacy + $invoice),
        ];
    }
}

