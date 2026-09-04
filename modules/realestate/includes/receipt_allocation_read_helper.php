<?php
/**
 * Read-only receipt/obligation allocation helpers for Invoice Mode display.
 *
 * Intentionally does NOT require cheque_lifecycle / accounting_mode_helper / auth.php,
 * so Customer API and CLI financial reads avoid session side effects.
 *
 * Write engines (receipt_allocation_engine.php) require this file and keep the same
 * function names via function_exists guards.
 */
declare(strict_types=1);

if (!function_exists('re_receipt_money')) {
    function re_receipt_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_billing_item_obligation_allocated_map')) {
    /**
     * Invoice Mode display helper: map billing_item_id => SUM(obligation.allocated_amount)
     * for obligations sourced from billing items on a lease.
     *
     * @param list<int> $billingItemIds
     * @return array<int,float>
     */
    function re_billing_item_obligation_allocated_map(PDO $conn, int $companyId, int $leaseId, array $billingItemIds): array
    {
        $ids = [];
        foreach ($billingItemIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($companyId <= 0 || $leaseId <= 0 || $ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("
            SELECT source_id AS billing_item_id,
                   COALESCE(SUM(allocated_amount), 0) AS allocated_total
            FROM re_obligations
            WHERE company_id = ?
              AND lease_id = ?
              AND source_type = 'billing_item'
              AND source_id IN ($placeholders)
            GROUP BY source_id
        ");
        $stmt->execute(array_merge([$companyId, $leaseId], $ids));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['billing_item_id']] = re_receipt_money($row['allocated_total'] ?? 0);
        }
        return $map;
    }
}
