<?php
/**
 * Bounce / late fees on Invoice Mode leases become a normal invoice as soon as they are added,
 * so Receipt Allocation can see them. Before this only the cheque-lifecycle bounce path made the
 * fee an obligation; the cheque page and the daily late-fee run only saved the billing item.
 *
 * Journal is the usual invoice one (Dr AR 1310 / Cr penalty income), posted by the invoice engine.
 */

require_once __DIR__ . '/obligation_engine.php';
require_once __DIR__ . '/invoice_engine.php';

if (!function_exists('re_penalty_make_payable')) {
    /**
     * @return array{success:bool,invoice_id?:int,invoice_number?:string,pending?:bool,skipped?:bool,error?:string}
     */
    function re_penalty_make_payable(PDO $conn, int $companyId, int $leaseId, int $billingItemId, ?int $userId = null): array
    {
        try {
            $ready = re_invoice_engine_load_invoice_mode_lease($conn, $companyId, $leaseId);
            if (empty($ready['success'])) {
                return ['success' => true, 'skipped' => true];
            }

            $st = $conn->prepare("
                SELECT id FROM re_billing_items
                WHERE id = ? AND company_id = ? AND lease_id = ? AND item_type = 'penalty'
                  AND status <> 'waived' AND COALESCE(is_waived, 0) = 0
                LIMIT 1
            ");
            $st->execute([$billingItemId, $companyId, $leaseId]);
            if (!$st->fetchColumn()) {
                return ['success' => false, 'error' => 'Fee not found, or it was waived.'];
            }

            $existing = re_penalty_invoice_for_item($conn, $companyId, $billingItemId);
            if ($existing) {
                return ['success' => true, 'invoice_id' => (int)$existing['id'], 'invoice_number' => (string)$existing['invoice_number']];
            }

            $gen = re_obligation_engine_generate_for_lease($conn, $companyId, $leaseId, $userId);
            if (empty($gen['success'])) {
                return ['success' => false, 'error' => (string)($gen['error'] ?? 'Could not refresh the lease charges.')];
            }
            $prep = re_invoice_engine_prepare_candidates_for_lease($conn, $companyId, $leaseId, $userId);
            if (empty($prep['success'])) {
                return ['success' => false, 'error' => (string)($prep['error'] ?? 'Could not prepare the invoice.')];
            }

            $st = $conn->prepare("
                SELECT c.id, c.eligible_on
                FROM re_obligations o
                JOIN re_invoice_candidates c ON c.obligation_id = o.id AND c.company_id = o.company_id
                WHERE o.company_id = ? AND o.lease_id = ? AND o.source_type = 'billing_item' AND o.source_id = ?
                  AND c.status = 'approved' AND c.invoice_id IS NULL
                ORDER BY c.id DESC LIMIT 1
            ");
            $st->execute([$companyId, $leaseId, $billingItemId]);
            $candidate = $st->fetch(PDO::FETCH_ASSOC);
            if (!$candidate) {
                return ['success' => false, 'error' => 'No invoice could be prepared for this fee.'];
            }
            if ((string)$candidate['eligible_on'] > date('Y-m-d')) {
                // Not due yet: Receipt Allocation / Invoice Preview issue it on its date.
                return ['success' => true, 'pending' => true];
            }

            $issue = re_invoice_engine_issue_candidate_for_lease($conn, $companyId, $leaseId, (int)$candidate['id'], $userId);
            if (empty($issue['success'])) {
                return ['success' => false, 'error' => (string)($issue['error'] ?? 'Could not issue the invoice.')];
            }
            $invoice = re_penalty_invoice_for_item($conn, $companyId, $billingItemId);
            return [
                'success' => true,
                'invoice_id' => (int)($invoice['id'] ?? 0),
                'invoice_number' => (string)($invoice['invoice_number'] ?? ''),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('re_penalty_invoice_for_item')) {
    function re_penalty_invoice_for_item(PDO $conn, int $companyId, int $billingItemId): ?array
    {
        $st = $conn->prepare("
            SELECT i.id, i.invoice_number
            FROM re_obligations o
            JOIN re_invoice_items ii ON ii.obligation_id = o.id AND ii.company_id = o.company_id
            JOIN re_invoices i ON i.id = ii.invoice_id AND i.company_id = ii.company_id
            WHERE o.company_id = ? AND o.source_type = 'billing_item' AND o.source_id = ?
              AND i.status NOT IN ('cancelled', 'void')
            ORDER BY i.id DESC LIMIT 1
        ");
        $st->execute([$companyId, $billingItemId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('re_penalty_unbilled_for_lease')) {
    /**
     * Fees on an Invoice Mode lease that have no live invoice yet (made before this change).
     *
     * @return list<array<string,mixed>>
     */
    function re_penalty_unbilled_for_lease(PDO $conn, int $companyId, int $leaseId): array
    {
        try {
            $st = $conn->prepare("
                SELECT bi.id, bi.item_name, bi.total_amount, bi.due_date
                FROM re_billing_items bi
                JOIN re_leases l ON l.id = bi.lease_id AND l.company_id = bi.company_id
                WHERE bi.company_id = ? AND bi.lease_id = ? AND bi.item_type = 'penalty'
                  AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
                  AND bi.status <> 'waived' AND COALESCE(bi.is_waived, 0) = 0
                  AND COALESCE(bi.is_paid, 0) = 0
                  AND NOT EXISTS (
                        SELECT 1
                        FROM re_obligations o
                        JOIN re_invoice_items ii ON ii.obligation_id = o.id AND ii.company_id = o.company_id
                        JOIN re_invoices i ON i.id = ii.invoice_id AND i.company_id = ii.company_id
                        WHERE o.company_id = bi.company_id AND o.source_type = 'billing_item' AND o.source_id = bi.id
                          AND i.status NOT IN ('cancelled', 'void')
                  )
                ORDER BY bi.due_date ASC, bi.id ASC
            ");
            $st->execute([$companyId, $leaseId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('re_penalty_unbilled_for_lease: ' . $e->getMessage());
            return [];
        }
    }
}
