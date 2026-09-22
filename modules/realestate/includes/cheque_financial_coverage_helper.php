<?php
/**
 * Invoice Mode: infer cheque collection from receipt allocations to invoices/obligations.
 *
 * One bank transfer → one receipt → many invoice/obligation allocations can fully cover
 * multiple schedule cheques without a direct payment.cheque_id link.
 */
declare(strict_types=1);

require_once __DIR__ . '/lease_installment_schedule.php';
require_once __DIR__ . '/cheque_lifecycle_helper.php';

if (!function_exists('re_receipt_money')) {
    function re_receipt_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_cheque_take_with_invoice_fils_snap')) {
    /**
     * Allocate up to $need, but if that would leave an invoice/obligation short by
     * at most one fils (0.01) while total exceeds the budget by at most two fils,
     * snap to the full open amount. Matches Invoice Mode monthly rounding
     * (e.g. 666.67+666.67) against schedule splits (e.g. 1333.33).
     */
    function re_cheque_take_with_invoice_fils_snap(float $openAmount, float $need, float $expectedSoFar): float
    {
        $open = re_receipt_money($openAmount);
        $remaining = re_receipt_money(max(0.0, $need - $expectedSoFar));
        $take = re_receipt_money(min($open, $remaining));
        if ($take <= 0.005) {
            return 0.0;
        }
        $leftOnOpen = re_receipt_money($open - $take);
        if ($leftOnOpen > 0.005 && $leftOnOpen <= 0.015) {
            $wouldTotal = re_receipt_money($expectedSoFar + $open);
            if (($wouldTotal - $need) <= 0.025) {
                return $open;
            }
        }
        return $take;
    }
}

if (!function_exists('re_cheque_installment_obligation_types')) {
    /**
     * Map schedule installment_type values to obligation_type enum values.
     *
     * @return list<string>
     */
    function re_cheque_installment_obligation_types(string $installmentType): array
    {
        return match ($installmentType) {
            'security_deposit' => ['security_deposit'],
            'commission' => ['commission'],
            'ejari' => ['other'],
            'admin' => ['admin_fee'],
            'chiller', 'amc', 'service' => ['service'],
            'parking' => ['parking'],
            'store' => ['store'],
            'vat' => ['vat'],
            default => [],
        };
    }
}

if (!function_exists('re_cheque_is_extra_service_target')) {
    /**
     * Extra Service Charge rows (billing_item obligations), not lease chiller/AMC.
     * Rent / Fees Installment coverage must not auto-claim these.
     */
    function re_cheque_is_extra_service_target(array $row): bool
    {
        return (string)($row['source_type'] ?? '') === 'billing_item';
    }
}

if (!function_exists('re_cheque_linked_extra_service_face_by_installment')) {
    /**
     * Face of Extra SC items tied to each installment (re_billing_items.installment_id),
     * i.e. the "Includes service charge +X" part of that cheque's face.
     *
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $obligations
     * @return array<int,float>
     */
    function re_cheque_linked_extra_service_face_by_installment(array $invoices, array $obligations): array
    {
        $faces = [];
        foreach (array_merge($invoices, $obligations) as $row) {
            $installmentId = (int)($row['linked_installment_id'] ?? 0);
            if ($installmentId <= 0 || !re_cheque_is_extra_service_target($row)) {
                continue;
            }
            $faces[$installmentId] = re_receipt_money(($faces[$installmentId] ?? 0) + (float)($row['total_amount'] ?? 0));
        }
        return $faces;
    }
}

if (!function_exists('re_cheque_pick_linked_extra_service_targets')) {
    /**
     * Extra SC items that are part of this cheque's own installment. Unlike pooled
     * rent money, the cheque face explicitly includes these, so it may pay them.
     * Settled ones stay in invoice_ids so collected display sums their receipt lines.
     *
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $obligations
     * @return array{invoice_ids:list<int>,obligation_ids:list<int>,expected_total:float,preferred_caps:array<string,float>}
     */
    function re_cheque_pick_linked_extra_service_targets(array $invoices, array $obligations, int $installmentId): array
    {
        $picked = ['invoice_ids' => [], 'obligation_ids' => [], 'expected_total' => 0.0, 'preferred_caps' => []];
        if ($installmentId <= 0) {
            return $picked;
        }

        foreach ($invoices as $row) {
            if ((int)($row['linked_installment_id'] ?? 0) !== $installmentId || !re_cheque_is_extra_service_target($row)) {
                continue;
            }
            $invoiceId = (int)($row['id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            $picked['invoice_ids'][] = $invoiceId;
            $picked['expected_total'] = re_receipt_money($picked['expected_total'] + (float)($row['total_amount'] ?? 0));
            $open = re_receipt_money($row['outstanding_amount'] ?? 0);
            if ($open > 0.005) {
                $picked['preferred_caps']['invoice:' . $invoiceId] = $open;
            }
        }

        foreach ($obligations as $row) {
            if ((int)($row['linked_installment_id'] ?? 0) !== $installmentId || !re_cheque_is_extra_service_target($row)) {
                continue;
            }
            $obligationId = (int)($row['id'] ?? 0);
            if ($obligationId <= 0) {
                continue;
            }
            $picked['expected_total'] = re_receipt_money($picked['expected_total'] + (float)($row['total_amount'] ?? 0));
            $open = re_receipt_money(max(0, (float)($row['total_amount'] ?? 0) - (float)($row['allocated_amount'] ?? 0)));
            if ($open > 0.005) {
                $picked['obligation_ids'][] = $obligationId;
                $picked['preferred_caps']['obligation:' . $obligationId] = $open;
            }
        }

        return $picked;
    }
}

if (!function_exists('re_cheque_load_financial_targets')) {
    /**
     * Load invoice and standalone-obligation rows used to resolve cheque coverage.
     *
     * @return array{invoices:list<array<string,mixed>>,obligations:list<array<string,mixed>>}
     */
    function re_cheque_load_financial_targets(PDO $conn, int $companyId, int $leaseId): array
    {
        $invoiceStmt = $conn->prepare("
            SELECT i.id,
                   i.due_date,
                   i.total_amount,
                   i.outstanding_amount,
                   i.status,
                   (
                       SELECT o.obligation_type
                       FROM re_invoice_items ii
                       JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
                       WHERE ii.invoice_id = i.id AND ii.company_id = i.company_id
                       ORDER BY ii.id ASC
                       LIMIT 1
                   ) AS obligation_type,
                   (
                       SELECT o.source_type
                       FROM re_invoice_items ii
                       JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
                       WHERE ii.invoice_id = i.id AND ii.company_id = i.company_id
                       ORDER BY ii.id ASC
                       LIMIT 1
                   ) AS source_type,
                   (
                       SELECT o.source_id
                       FROM re_invoice_items ii
                       JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
                       WHERE ii.invoice_id = i.id AND ii.company_id = i.company_id
                       ORDER BY ii.id ASC
                       LIMIT 1
                   ) AS source_id,
                   (
                       SELECT o.id
                       FROM re_invoice_items ii
                       JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
                       WHERE ii.invoice_id = i.id AND ii.company_id = i.company_id
                       ORDER BY ii.id ASC
                       LIMIT 1
                   ) AS obligation_id,
                   (
                       SELECT bi.installment_id
                       FROM re_invoice_items ii
                       JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
                       JOIN re_billing_items bi ON bi.id = o.source_id AND bi.company_id = o.company_id
                       WHERE ii.invoice_id = i.id AND ii.company_id = i.company_id
                         AND o.source_type = 'billing_item'
                         AND o.obligation_type = 'service'
                         AND bi.item_type = 'service_charge'
                       ORDER BY ii.id ASC
                       LIMIT 1
                   ) AS linked_installment_id,
                   (
                       SELECT COALESCE(SUM(o.vat_amount), 0)
                       FROM re_invoice_items ii
                       JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
                       WHERE ii.invoice_id = i.id AND ii.company_id = i.company_id
                   ) AS vat_amount
            FROM re_invoices i
            WHERE i.company_id = ?
              AND i.lease_id = ?
              AND i.status <> 'cancelled'
            ORDER BY i.due_date ASC, i.id ASC
        ");
        $invoiceStmt->execute([$companyId, $leaseId]);
        $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $obligationStmt = $conn->prepare("
            SELECT o.id,
                   o.due_date,
                   o.total_amount,
                   o.allocated_amount,
                   o.status,
                   o.obligation_type,
                   o.source_type,
                   o.source_id,
                   COALESCE(o.vat_amount, 0) AS vat_amount,
                   bi.installment_id AS linked_installment_id
            FROM re_obligations o
            LEFT JOIN re_billing_items bi
              ON o.source_type = 'billing_item'
             AND o.obligation_type = 'service'
             AND bi.id = o.source_id
             AND bi.company_id = o.company_id
             AND bi.item_type = 'service_charge'
            WHERE o.company_id = ?
              AND o.lease_id = ?
              AND o.status NOT IN ('cancelled', 'waived')
              AND NOT EXISTS (
                    SELECT 1
                    FROM re_invoice_items ii
                    WHERE ii.company_id = o.company_id
                      AND ii.obligation_id = o.id
              )
            ORDER BY o.due_date ASC, o.id ASC
        ");
        $obligationStmt->execute([$companyId, $leaseId]);
        $obligations = $obligationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['invoices' => $invoices, 'obligations' => $obligations];
    }
}

if (!function_exists('re_cheque_invoiced_obligation_ids')) {
    /**
     * @param list<array<string,mixed>> $invoices
     * @return array<int,true>
     */
    function re_cheque_invoiced_obligation_ids(array $invoices): array
    {
        $ids = [];
        foreach ($invoices as $row) {
            $obligationId = (int)($row['obligation_id'] ?? 0);
            if ($obligationId > 0) {
                $ids[$obligationId] = true;
            }
        }
        return $ids;
    }
}

if (!function_exists('re_cheque_pick_non_rent_targets_for_date')) {
    /**
     * Separate "Fees Installment" / combined_fees cheque: allocate to fee invoices
     * up to the cheque face (e.g. Admin 1000 + Ejari/other 300 = 1300).
     *
     * Operational cheque dates often differ from obligation/invoice due dates
     * (lease start vs first collection date), so matching is amount/type based
     * with soft date preference — not a hard same-day filter.
     * Never pull rent or VAT into this basket.
     * When chiller is split/separate, exclude service so Fees Installment cannot
     * steal chiller invoices that belong on rent (+chiller) cheques — especially
     * after Admin/Ejari on this Fees Installment are already paid.
     *
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $obligations
     * @param list<string> $excludedTypes
     * @param bool $includeSettled When true, already-paid fee invoices still count toward
     *                             coverage mapping (face amount) so Fees Installment rows
     *                             retain their Admin/Ejari targets after collection.
     * @return array{invoice_ids:list<int>,obligation_ids:list<int>,expected_total:float,preferred_caps:array<string,float>,amount_mismatch?:bool}
     */
    function re_cheque_pick_non_rent_targets_for_date(
        array $invoices,
        array $obligations,
        string $installmentDate,
        float $chequeAmount,
        array $consumedInvoiceIds = [],
        array $consumedObligationIds = [],
        array $excludedTypes = [],
        bool $includeSettled = false
    ): array {
        $need = re_receipt_money($chequeAmount);
        if ($need <= 0.005) {
            return [
                'invoice_ids' => [],
                'obligation_ids' => [],
                'expected_total' => 0.0,
                'preferred_caps' => [],
            ];
        }

        $excludedLookup = [];
        foreach ($excludedTypes as $excludedType) {
            $excludedType = (string)$excludedType;
            if ($excludedType !== '') {
                $excludedLookup[$excludedType] = true;
            }
        }

        $typeOrder = static function (string $type): int {
            return match ($type) {
                'admin_fee' => 1,
                'commission' => 2,
                'other' => 3, // Ejari
                'parking' => 4,
                'store' => 5,
                'service' => 6, // Chiller / AMC — only when truly on Fees Installment
                default => 9,
            };
        };

        $dateDistance = static function (string $due) use ($installmentDate): int {
            if ($installmentDate === '' || $due === '') {
                return 9999;
            }
            if ($due === $installmentDate) {
                return 0;
            }
            $a = strtotime($installmentDate);
            $b = strtotime($due);
            if ($a === false || $b === false) {
                return 9999;
            }
            return (int)round(abs($a - $b) / 86400);
        };

        $candidates = [];
        $invoicedObligationIds = re_cheque_invoiced_obligation_ids($invoices);

        foreach ($obligations as $row) {
            $type = (string)($row['obligation_type'] ?? '');
            if (in_array($type, ['rent', 'vat', 'security_deposit'], true) || isset($excludedLookup[$type])) {
                continue;
            }
            // Extra SC (billing_item) is never part of rent / Fees Installment baskets.
            if (re_cheque_is_extra_service_target($row)) {
                continue;
            }
            $obligationId = (int)($row['id'] ?? 0);
            if ($obligationId <= 0 || isset($invoicedObligationIds[$obligationId]) || isset($consumedObligationIds[$obligationId])) {
                continue;
            }
            $open = re_receipt_money(max(0, (float)($row['total_amount'] ?? 0) - (float)($row['allocated_amount'] ?? 0)));
            $face = re_receipt_money($row['total_amount'] ?? 0);
            $amount = $open > 0.005 ? $open : ($includeSettled ? $face : 0.0);
            if ($amount <= 0.005) {
                continue;
            }
            $due = (string)($row['due_date'] ?? '');
            $candidates[] = [
                'kind' => 'obligation',
                'id' => $obligationId,
                'type' => $type,
                'amount' => $amount,
                'exact' => abs($amount - $need) <= 0.02 ? 0 : 1,
                'date_distance' => $dateDistance($due),
            ];
        }

        foreach ($invoices as $row) {
            $type = (string)($row['obligation_type'] ?? '');
            if (in_array($type, ['rent', 'vat'], true) || isset($excludedLookup[$type])) {
                continue;
            }
            if (re_cheque_is_extra_service_target($row)) {
                continue;
            }
            $invoiceId = (int)($row['id'] ?? 0);
            if ($invoiceId <= 0 || isset($consumedInvoiceIds[$invoiceId])) {
                continue;
            }
            $open = re_receipt_money($row['outstanding_amount'] ?? 0);
            $face = re_receipt_money($row['total_amount'] ?? 0);
            $amount = $open > 0.005 ? $open : ($includeSettled ? $face : 0.0);
            if ($amount <= 0.005) {
                continue;
            }
            $due = (string)($row['due_date'] ?? '');
            $candidates[] = [
                'kind' => 'invoice',
                'id' => $invoiceId,
                'type' => $type,
                'amount' => $amount,
                'exact' => abs($amount - $need) <= 0.02 ? 0 : 1,
                'date_distance' => $dateDistance($due),
            ];
        }

        // Prefer: exact full-cheque match, then nearer due dates, then Fees Installment types
        // (admin/commission/ejari) before split service/chiller invoices.
        usort($candidates, static function (array $a, array $b) use ($typeOrder): int {
            return ($a['exact'] <=> $b['exact'])
                ?: ((int)$a['date_distance'] <=> (int)$b['date_distance'])
                ?: ($typeOrder((string)$a['type']) <=> $typeOrder((string)$b['type']))
                ?: ($a['id'] <=> $b['id']);
        });

        $invoiceIds = [];
        $obligationIds = [];
        $preferredCaps = [];
        $expectedTotal = 0.0;

        foreach ($candidates as $candidate) {
            if ($expectedTotal >= $need - 0.02) {
                break;
            }
            $take = re_cheque_take_with_invoice_fils_snap((float)$candidate['amount'], $need, $expectedTotal);
            if ($take <= 0.005) {
                continue;
            }
            if ($candidate['kind'] === 'invoice') {
                $key = 'invoice:' . (int)$candidate['id'];
                $invoiceIds[] = (int)$candidate['id'];
            } else {
                $key = 'obligation:' . (int)$candidate['id'];
                $obligationIds[] = (int)$candidate['id'];
            }
            $preferredCaps[$key] = $take;
            $expectedTotal = re_receipt_money($expectedTotal + $take);
        }

        return [
            'invoice_ids' => array_values(array_unique($invoiceIds)),
            'obligation_ids' => array_values(array_unique($obligationIds)),
            'expected_total' => re_receipt_money($expectedTotal),
            'preferred_caps' => $preferredCaps,
            'amount_mismatch' => $need > 0.005 && abs($expectedTotal - $need) > 0.05,
        ];
    }
}

if (!function_exists('re_cheque_pick_typed_targets_for_date')) {
    /**
     * Typed fee cheque (ejari / admin / commission / …): match by obligation type
     * and amount. Soft date preference only — cheque expected date often differs
     * from invoice due date (lease start vs first collection).
     *
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $obligations
     * @return array{invoice_ids:list<int>,obligation_ids:list<int>,expected_total:float,preferred_caps?:array<string,float>}
     */
    function re_cheque_pick_typed_targets_for_date(
        array $invoices,
        array $obligations,
        string $installmentType,
        string $installmentDate,
        float $chequeAmount
    ): array {
        $wantedTypes = re_cheque_installment_obligation_types($installmentType);
        if (!$wantedTypes) {
            return ['invoice_ids' => [], 'obligation_ids' => [], 'expected_total' => 0.0];
        }

        $invoiceIds = [];
        $obligationIds = [];
        $preferredCaps = [];
        $expectedTotal = 0.0;
        $need = re_receipt_money($chequeAmount);
        $invoicedObligationIds = re_cheque_invoiced_obligation_ids($invoices);

        $dateDistance = static function (string $due) use ($installmentDate): int {
            if ($installmentDate === '' || $due === '') {
                return 9999;
            }
            if ($due === $installmentDate) {
                return 0;
            }
            $a = strtotime($installmentDate);
            $b = strtotime($due);
            if ($a === false || $b === false) {
                return 9999;
            }
            return (int)round(abs($a - $b) / 86400);
        };

        $candidates = [];
        foreach ($obligations as $row) {
            if (!in_array((string)($row['obligation_type'] ?? ''), $wantedTypes, true)) {
                continue;
            }
            // Typed chiller/service cheques cover lease fees only — not Extra SC.
            if (re_cheque_is_extra_service_target($row)) {
                continue;
            }
            $obligationId = (int)($row['id'] ?? 0);
            if ($obligationId <= 0 || isset($invoicedObligationIds[$obligationId])) {
                continue;
            }
            $open = re_receipt_money(max(0, (float)($row['total_amount'] ?? 0) - (float)($row['allocated_amount'] ?? 0)));
            if ($open <= 0.005) {
                continue;
            }
            $due = (string)($row['due_date'] ?? '');
            $candidates[] = [
                'kind' => 'obligation',
                'id' => $obligationId,
                'amount' => $open,
                'exact' => ($need > 0.005 && abs($open - $need) <= 0.02) ? 0 : 1,
                'date_distance' => $dateDistance($due),
            ];
        }
        foreach ($invoices as $row) {
            if (!in_array((string)($row['obligation_type'] ?? ''), $wantedTypes, true)) {
                continue;
            }
            if (re_cheque_is_extra_service_target($row)) {
                continue;
            }
            $invoiceId = (int)($row['id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            $open = re_receipt_money($row['outstanding_amount'] ?? $row['total_amount'] ?? 0);
            if ($open <= 0.005) {
                continue;
            }
            $due = (string)($row['due_date'] ?? '');
            $candidates[] = [
                'kind' => 'invoice',
                'id' => $invoiceId,
                'amount' => $open,
                'exact' => ($need > 0.005 && abs($open - $need) <= 0.02) ? 0 : 1,
                'date_distance' => $dateDistance($due),
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            return ($a['exact'] <=> $b['exact'])
                ?: ((int)$a['date_distance'] <=> (int)$b['date_distance'])
                ?: ($a['id'] <=> $b['id']);
        });

        foreach ($candidates as $candidate) {
            if ($need > 0.005 && $expectedTotal >= $need - 0.02) {
                break;
            }
            $take = $need > 0.005
                ? re_cheque_take_with_invoice_fils_snap((float)$candidate['amount'], $need, $expectedTotal)
                : re_receipt_money($candidate['amount']);
            if ($take <= 0.005) {
                continue;
            }
            if ($candidate['kind'] === 'invoice') {
                $key = 'invoice:' . (int)$candidate['id'];
                $invoiceIds[] = (int)$candidate['id'];
            } else {
                $key = 'obligation:' . (int)$candidate['id'];
                $obligationIds[] = (int)$candidate['id'];
            }
            $preferredCaps[$key] = $take;
            $expectedTotal = re_receipt_money($expectedTotal + $take);
        }

        return [
            'invoice_ids' => array_values(array_unique($invoiceIds)),
            'obligation_ids' => array_values(array_unique($obligationIds)),
            'expected_total' => re_receipt_money($expectedTotal > 0 ? $expectedTotal : $chequeAmount),
            'preferred_caps' => $preferredCaps,
        ];
    }
}

if (!function_exists('re_cheque_pick_embedded_vat_targets')) {
    /**
     * Separate-VAT schedule rows when no obligation_type=vat exists: map to fee
     * invoices/obligations that carry embedded VAT (not rent), greedily by due date.
     *
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $obligations
     * @return array{invoice_ids:list<int>,obligation_ids:list<int>,expected_total:float,preferred_caps:array<string,float>}
     */
    function re_cheque_pick_embedded_vat_targets(
        array $invoices,
        array $obligations,
        string $installmentDate,
        float $chequeAmount
    ): array {
        $candidates = [];
        $invoicedObligationIds = re_cheque_invoiced_obligation_ids($invoices);

        foreach ($invoices as $row) {
            if ((string)($row['obligation_type'] ?? '') === 'rent') {
                continue;
            }
            $vat = re_receipt_money($row['vat_amount'] ?? 0);
            if ($vat <= 0.005) {
                continue;
            }
            $invoiceId = (int)($row['id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            $due = (string)($row['due_date'] ?? '');
            $candidates[] = [
                'kind' => 'invoice',
                'id' => $invoiceId,
                'due_date' => $due,
                'vat' => $vat,
                'same_date' => ($installmentDate !== '' && $due === $installmentDate) ? 0 : 1,
            ];
        }

        foreach ($obligations as $row) {
            if ((string)($row['obligation_type'] ?? '') === 'rent') {
                continue;
            }
            $obligationId = (int)($row['id'] ?? 0);
            if ($obligationId <= 0 || isset($invoicedObligationIds[$obligationId])) {
                continue;
            }
            $vat = re_receipt_money($row['vat_amount'] ?? 0);
            if ($vat <= 0.005) {
                continue;
            }
            $due = (string)($row['due_date'] ?? '');
            $candidates[] = [
                'kind' => 'obligation',
                'id' => $obligationId,
                'due_date' => $due,
                'vat' => $vat,
                'same_date' => ($installmentDate !== '' && $due === $installmentDate) ? 0 : 1,
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            return ($a['same_date'] <=> $b['same_date'])
                ?: strcmp((string)$a['due_date'], (string)$b['due_date'])
                ?: ($a['id'] <=> $b['id']);
        });

        $invoiceIds = [];
        $obligationIds = [];
        $preferredCaps = [];
        $expectedTotal = 0.0;
        $need = $chequeAmount > 0.005 ? $chequeAmount : PHP_FLOAT_MAX;

        foreach ($candidates as $candidate) {
            if ($expectedTotal >= $need - 0.005) {
                break;
            }
            $vat = re_receipt_money($candidate['vat']);
            $take = re_receipt_money(min($vat, max(0.0, $need - $expectedTotal)));
            if ($take <= 0.005) {
                continue;
            }
            if ($candidate['kind'] === 'invoice') {
                $invoiceIds[] = (int)$candidate['id'];
                $preferredCaps['invoice:' . (int)$candidate['id']] = $take;
            } else {
                $obligationIds[] = (int)$candidate['id'];
                $preferredCaps['obligation:' . (int)$candidate['id']] = $take;
            }
            $expectedTotal = re_receipt_money($expectedTotal + $take);
        }

        return [
            'invoice_ids' => array_values(array_unique($invoiceIds)),
            'obligation_ids' => array_values(array_unique($obligationIds)),
            'expected_total' => re_receipt_money($expectedTotal > 0 ? $expectedTotal : $chequeAmount),
            'preferred_caps' => $preferredCaps,
        ];
    }
}

if (!function_exists('re_cheque_pick_rent_targets')) {
    /**
     * Walk rent invoices in schedule order for this cheque's rent share.
     * Period selection uses invoice FACE amounts so a partially prepaid month
     * (e.g. 0.98 from tenant credit) does not pull the next cheque into later months.
     * Preferred caps use open amounts on those selected periods only.
     *
     * @param list<array<string,mixed>> $rentInvoices
     * @return array{
     *   invoice_ids:list<int>,
     *   period_invoice_ids:list<int>,
     *   obligation_ids:list<int>,
     *   expected_total:float,
     *   consumed:int,
     *   preferred_caps:array<string,float>,
     *   period_due_dates:list<string>
     * }
     */
    function re_cheque_pick_rent_targets(array $rentInvoices, int $startIndex, float $chequeAmount): array
    {
        $need = re_receipt_money($chequeAmount);
        $selected = [];
        $faceCovered = 0.0;
        $index = $startIndex;

        // Pass 1: lock the period set by schedule face (including already-settled months).
        while ($index < count($rentInvoices) && $faceCovered < $need - 0.02) {
            $row = $rentInvoices[$index];
            $invoiceId = (int)($row['id'] ?? 0);
            $face = re_receipt_money($row['total_amount'] ?? 0);
            if ($invoiceId <= 0 || $face <= 0.005) {
                $index++;
                continue;
            }
            $takeFace = re_cheque_take_with_invoice_fils_snap($face, $need, $faceCovered);
            if ($takeFace <= 0.005) {
                break;
            }
            $selected[] = [
                'id' => $invoiceId,
                'due' => (string)($row['due_date'] ?? ''),
                'face' => $face,
                'take_face' => $takeFace,
                'open' => re_receipt_money($row['outstanding_amount'] ?? 0),
            ];
            $faceCovered = re_receipt_money($faceCovered + $takeFace);
            $index++;
        }

        // Pass 2: suggest open amounts only within the locked periods (no later-month spill).
        $invoiceIds = [];
        $periodInvoiceIds = [];
        $preferredCaps = [];
        $periodDueDates = [];
        $openAllocated = 0.0;
        foreach ($selected as $period) {
            $periodInvoiceIds[] = (int)$period['id'];
            if ($period['due'] !== '') {
                $periodDueDates[] = (string)$period['due'];
            }
            $open = re_receipt_money($period['open']);
            if ($open <= 0.005) {
                continue;
            }
            // Cap suggestion to the face slice claimed for this cheque (not full open on a partial period).
            $cap = re_receipt_money(min($open, (float)($period['take_face'] ?? $open)));
            if ($cap <= 0.005) {
                continue;
            }
            $invoiceIds[] = (int)$period['id'];
            $preferredCaps['invoice:' . (int)$period['id']] = $cap;
            $openAllocated = re_receipt_money($openAllocated + $cap);
        }

        return [
            'invoice_ids' => $invoiceIds,
            'period_invoice_ids' => array_values(array_unique($periodInvoiceIds)),
            'obligation_ids' => [],
            // Face coverage drives fee remainder / later cheque sequencing.
            'expected_total' => re_receipt_money($faceCovered),
            'open_allocated_total' => $openAllocated,
            'consumed' => max($startIndex, $index),
            'preferred_caps' => $preferredCaps,
            'period_due_dates' => array_values(array_unique($periodDueDates)),
        ];
    }
}

if (!function_exists('re_cheque_fee_invoice_ids_for_due_dates')) {
    /**
     * Fee invoices on the given due dates (open or settled).
     * Used so cheque collection status still sees prior allocations after periods settle.
     *
     * @param list<array<string,mixed>> $invoices
     * @param list<string> $dueDates
     * @param list<string>|null $allowedTypes When set, only these obligation_types are included
     *                                        (e.g. ['service'] for rent+chiller rows).
     * @return list<int>
     */
    function re_cheque_fee_invoice_ids_for_due_dates(array $invoices, array $dueDates, ?array $allowedTypes = null): array
    {
        $lookup = [];
        foreach ($dueDates as $dueDate) {
            $dueDate = (string)$dueDate;
            if ($dueDate !== '') {
                $lookup[$dueDate] = true;
            }
        }
        if ($lookup === []) {
            return [];
        }

        $allowedLookup = null;
        if ($allowedTypes !== null) {
            $allowedLookup = [];
            foreach ($allowedTypes as $allowedType) {
                $allowedType = (string)$allowedType;
                if ($allowedType !== '') {
                    $allowedLookup[$allowedType] = true;
                }
            }
        }

        $ids = [];
        foreach ($invoices as $row) {
            $type = (string)($row['obligation_type'] ?? '');
            if (in_array($type, ['rent', 'vat', 'security_deposit'], true)) {
                continue;
            }
            // Extra SC invoices share due dates with rent months but must not join rent coverage.
            if (re_cheque_is_extra_service_target($row)) {
                continue;
            }
            if ($allowedLookup !== null && !isset($allowedLookup[$type])) {
                continue;
            }
            $due = (string)($row['due_date'] ?? '');
            if ($due === '' || !isset($lookup[$due])) {
                continue;
            }
            $invoiceId = (int)($row['id'] ?? 0);
            if ($invoiceId > 0) {
                $ids[] = $invoiceId;
            }
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('re_cheque_pick_fee_remainder_targets')) {
    /**
     * Fill remaining cheque face (after rent share) with non-rent accounting targets.
     * Skips VAT documents so separate VAT cheques stay independent.
     * Tracks consumed ids so later rent cheques take later split fee invoices.
     *
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $obligations
     * @param array<int,true> $consumedInvoiceIds
     * @param array<int,true> $consumedObligationIds
     * @return array{invoice_ids:list<int>,obligation_ids:list<int>,expected_total:float,preferred_caps:array<string,float>}
     */
    function re_cheque_pick_fee_remainder_targets(
        array $invoices,
        array $obligations,
        float $needAmount,
        array &$consumedInvoiceIds,
        array &$consumedObligationIds,
        array $preferredDueDates = []
    ): array {
        if ($needAmount <= 0.02) {
            return ['invoice_ids' => [], 'obligation_ids' => [], 'expected_total' => 0.0, 'preferred_caps' => []];
        }

        $preferredDueLookup = [];
        foreach ($preferredDueDates as $dueDate) {
            $dueDate = (string)$dueDate;
            if ($dueDate !== '') {
                $preferredDueLookup[$dueDate] = true;
            }
        }

        $candidates = [];
        $invoicedObligationIds = re_cheque_invoiced_obligation_ids($invoices);

        foreach ($invoices as $row) {
            $type = (string)($row['obligation_type'] ?? '');
            if ($type === 'rent' || $type === 'vat') {
                continue;
            }
            if (re_cheque_is_extra_service_target($row)) {
                continue;
            }
            $invoiceId = (int)($row['id'] ?? 0);
            if ($invoiceId <= 0 || isset($consumedInvoiceIds[$invoiceId])) {
                continue;
            }
            $open = re_receipt_money($row['outstanding_amount'] ?? $row['total_amount'] ?? 0);
            if ($open <= 0.005) {
                continue;
            }
            $due = (string)($row['due_date'] ?? '');
            $candidates[] = [
                'kind' => 'invoice',
                'id' => $invoiceId,
                'due_date' => $due,
                'amount' => $open,
                'type' => $type,
                'period_match' => isset($preferredDueLookup[$due]) ? 0 : 1,
            ];
        }

        foreach ($obligations as $row) {
            $type = (string)($row['obligation_type'] ?? '');
            if ($type === 'rent' || $type === 'vat' || $type === 'security_deposit') {
                continue;
            }
            if (re_cheque_is_extra_service_target($row)) {
                continue;
            }
            $obligationId = (int)($row['id'] ?? 0);
            if ($obligationId <= 0 || isset($invoicedObligationIds[$obligationId]) || isset($consumedObligationIds[$obligationId])) {
                continue;
            }
            $open = re_receipt_money(max(0, (float)($row['total_amount'] ?? 0) - (float)($row['allocated_amount'] ?? 0)));
            if ($open <= 0.005) {
                continue;
            }
            $due = (string)($row['due_date'] ?? '');
            $candidates[] = [
                'kind' => 'obligation',
                'id' => $obligationId,
                'due_date' => $due,
                'amount' => $open,
                'type' => $type,
                'period_match' => isset($preferredDueLookup[$due]) ? 0 : 1,
            ];
        }

        // Prefer fee invoices whose due dates match the rent periods on this cheque,
        // then earlier dates, then service/chiller before Admin/Ejari leftovers.
        usort($candidates, static function (array $a, array $b): int {
            $typeOrder = static function (string $type): int {
                return match ($type) {
                    'service' => 1,
                    'parking' => 2,
                    'store' => 3,
                    'admin_fee' => 4,
                    'commission' => 5,
                    'other' => 6,
                    default => 9,
                };
            };
            return ((int)$a['period_match'] <=> (int)$b['period_match'])
                ?: strcmp((string)$a['due_date'], (string)$b['due_date'])
                ?: ($typeOrder((string)$a['type']) <=> $typeOrder((string)$b['type']))
                ?: ($a['id'] <=> $b['id']);
        });

        $invoiceIds = [];
        $obligationIds = [];
        $preferredCaps = [];
        $expectedTotal = 0.0;
        $need = $needAmount;
        // When rent periods are known, never spill leftover cheque face (tenant
        // rounding / fils rounding) onto the next period's fee invoice.
        $restrictToMatchedPeriods = $preferredDueLookup !== [];

        foreach ($candidates as $candidate) {
            if ($expectedTotal >= $need - 0.02) {
                break;
            }
            // Do not spill leftover cheque face (tenant rounding) onto later periods.
            if ($restrictToMatchedPeriods && (int)($candidate['period_match'] ?? 1) !== 0) {
                break;
            }
            $take = re_cheque_take_with_invoice_fils_snap((float)$candidate['amount'], $need, $expectedTotal);
            if ($take <= 0.005) {
                continue;
            }
            if ($candidate['kind'] === 'invoice') {
                $key = 'invoice:' . (int)$candidate['id'];
                $invoiceIds[] = (int)$candidate['id'];
                $consumedInvoiceIds[(int)$candidate['id']] = true;
            } else {
                $key = 'obligation:' . (int)$candidate['id'];
                $obligationIds[] = (int)$candidate['id'];
                $consumedObligationIds[(int)$candidate['id']] = true;
            }
            $preferredCaps[$key] = $take;
            $expectedTotal = re_receipt_money($expectedTotal + $take);
        }

        return [
            'invoice_ids' => $invoiceIds,
            'obligation_ids' => $obligationIds,
            'expected_total' => re_receipt_money($expectedTotal),
            'preferred_caps' => $preferredCaps,
        ];
    }
}

if (!function_exists('re_cheque_pick_security_deposit_obligation')) {
    /**
     * Prefer an open security_deposit obligation (liability) when that deposit is
     * included on this cheque's operational face. Deposit is never invoiced as rent.
     *
     * @param list<array<string,mixed>> $obligations
     * @param list<array<string,mixed>> $invoices
     * @param array<int,true> $consumedObligationIds
     * @return array{invoice_ids:list<int>,obligation_ids:list<int>,expected_total:float,preferred_caps:array<string,float>}
     */
    function re_cheque_pick_security_deposit_obligation(
        array $obligations,
        array $invoices,
        float $needAmount,
        array &$consumedObligationIds
    ): array {
        if ($needAmount <= 0.02) {
            return ['invoice_ids' => [], 'obligation_ids' => [], 'expected_total' => 0.0, 'preferred_caps' => []];
        }

        $invoicedObligationIds = re_cheque_invoiced_obligation_ids($invoices);
        foreach ($obligations as $row) {
            if ((string)($row['obligation_type'] ?? '') !== 'security_deposit') {
                continue;
            }
            $obligationId = (int)($row['id'] ?? 0);
            if ($obligationId <= 0 || isset($invoicedObligationIds[$obligationId]) || isset($consumedObligationIds[$obligationId])) {
                continue;
            }
            $open = re_receipt_money(max(0, (float)($row['total_amount'] ?? 0) - (float)($row['allocated_amount'] ?? 0)));
            if ($open <= 0.005) {
                continue;
            }
            $take = re_cheque_take_with_invoice_fils_snap($open, $needAmount, 0.0);
            if ($take <= 0.005) {
                continue;
            }
            $consumedObligationIds[$obligationId] = true;
            $key = 'obligation:' . $obligationId;
            return [
                'invoice_ids' => [],
                'obligation_ids' => [$obligationId],
                'expected_total' => $take,
                'preferred_caps' => [$key => $take],
            ];
        }

        return ['invoice_ids' => [], 'obligation_ids' => [], 'expected_total' => 0.0, 'preferred_caps' => []];
    }
}

if (!function_exists('re_cheque_security_deposit_merged_on_first_rent_face')) {
    /**
     * True when the first rent cheque face is the normal first-installment shape
     * that includes security deposit (rent share + split chiller + one-time fees + deposit).
     * Front-loaded custom faces that only happen to be larger than deposit are excluded.
     */
    function re_cheque_security_deposit_merged_on_first_rent_face(
        float $firstChequeAmount,
        float $rentShare,
        float $chillerShare,
        float $oneTimeFeesWithoutDeposit,
        float $securityDeposit
    ): bool {
        $securityDeposit = re_receipt_money($securityDeposit);
        $firstChequeAmount = re_receipt_money($firstChequeAmount);
        if ($securityDeposit <= 0.005 || $firstChequeAmount <= 0.005) {
            return false;
        }
        $base = re_receipt_money($rentShare + $chillerShare + $oneTimeFeesWithoutDeposit);
        $expectedWithDeposit = re_receipt_money($base + $securityDeposit);
        if (abs($firstChequeAmount - $expectedWithDeposit) <= 50.0) {
            return true;
        }
        $overBase = re_receipt_money($firstChequeAmount - $base);
        return $overBase >= re_receipt_money($securityDeposit - 0.02)
            && $overBase <= re_receipt_money($securityDeposit + 50.0);
    }
}

if (!function_exists('re_cheque_resolve_lease_coverage_map')) {
    /**
     * Resolve which invoices/obligations each schedule cheque is expected to cover.
     *
     * @return array<int,array<string,mixed>>
     */
    function re_cheque_resolve_lease_coverage_map(PDO $conn, int $companyId, int $leaseId): array
    {
        $chequeStmt = $conn->prepare("
            SELECT c.id AS cheque_id,
                   c.cheque_amount,
                   c.cheque_number,
                   li.id AS installment_id,
                   li.installment_date,
                   COALESCE(li.installment_type, 'rent') AS installment_type
            FROM re_post_dated_cheques c
            JOIN re_lease_installments li ON li.id = c.installment_id AND li.lease_id = c.lease_id
            WHERE c.company_id = ?
              AND c.lease_id = ?
            ORDER BY li.installment_date ASC,
                     CASE COALESCE(li.installment_type, 'rent')
                         WHEN 'combined_fees' THEN 0
                         WHEN 'security_deposit' THEN 1
                         WHEN 'vat' THEN 2
                         WHEN 'chiller' THEN 3
                         WHEN 'ejari' THEN 4
                         WHEN 'admin' THEN 5
                         WHEN 'commission' THEN 6
                         ELSE 10
                     END ASC,
                     c.id ASC
        ");
        $chequeStmt->execute([$companyId, $leaseId]);
        $chequeRows = $chequeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$chequeRows) {
            return [];
        }

        $leaseStmt = $conn->prepare("
            SELECT annual_rent, number_of_installments,
                   COALESCE(split_chiller_fees, 0) AS split_chiller_fees,
                   COALESCE(sep_chiller_fees, 0) AS sep_chiller_fees,
                   COALESCE(add_fees_to_first_installment, 1) AS add_fees_to_first_installment,
                   COALESCE(admin_fees, 0) AS admin_fees,
                   COALESCE(ejari_fees, 0) AS ejari_fees,
                   COALESCE(commission_fees, 0) AS commission_fees,
                   COALESCE(chiller_fees, 0) AS chiller_fees,
                   COALESCE(security_deposit, 0) AS security_deposit,
                   COALESCE(total_vat_amount, 0) AS total_vat_amount,
                   COALESCE(vat_distribution_type, 'first_installment') AS vat_distribution_type,
                   COALESCE(is_renewal_lease, 0) AS is_renewal_lease
            FROM re_leases
            WHERE id = ? AND company_id = ?
            LIMIT 1
        ");
        $leaseStmt->execute([$leaseId, $companyId]);
        $leaseRow = $leaseStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $financial = re_cheque_load_financial_targets($conn, $companyId, $leaseId);
        $invoices = $financial['invoices'];
        $obligations = $financial['obligations'];
        // Extra SC items written into a rent installment ("Includes service charge +750")
        // are part of that cheque's face but not rent/chiller. Take them off before the
        // rent share, or every such cheque looks "uneven" and over-claims rent months.
        $linkedExtraFaceByInstallment = re_cheque_linked_extra_service_face_by_installment($invoices, $obligations);
        $rentFaceOf = static function (array $chequeRow) use ($linkedExtraFaceByInstallment): float {
            $face = re_receipt_money($chequeRow['cheque_amount'] ?? 0);
            $linked = (float)($linkedExtraFaceByInstallment[(int)($chequeRow['installment_id'] ?? 0)] ?? 0);
            return re_receipt_money(max(0.0, $face - $linked));
        };
        $installmentCount = max(1, (int)($leaseRow['number_of_installments'] ?? 1));
        $annualRent = re_receipt_money($leaseRow['annual_rent'] ?? 0);
        $rentSharePerCheque = re_receipt_money($annualRent / $installmentCount);
        $chillerAnnual = 0.0;
        if (!empty($leaseRow['split_chiller_fees']) && empty($leaseRow['sep_chiller_fees'])) {
            $chillerAnnual = re_receipt_money($leaseRow['chiller_fees'] ?? 0);
        }
        $chillerSharePerCheque = re_receipt_money($chillerAnnual / $installmentCount);
        $securityDepositAmount = re_receipt_money($leaseRow['security_deposit'] ?? 0);
        $vatDistributionType = (string)($leaseRow['vat_distribution_type'] ?? 'first_installment');
        $totalVatAmount = re_receipt_money($leaseRow['total_vat_amount'] ?? 0);
        // Split VAT belongs on every rent cheque face — must be in typicalFace or the
        // first cheque is falsely treated as "uneven" and steals the next rent month.
        $vatSharePerCheque = ($vatDistributionType === 'split_installments' && $totalVatAmount > 0.005)
            ? re_receipt_money($totalVatAmount / $installmentCount)
            : 0.0;
        // One-time fees on the first rent cheque when fees are merged into that row
        // (not when a dedicated Fees Installment exists). Deposit handled separately.
        $firstRentOneTimeFees = 0.0;
        $hasCombinedFeesInstallment = false;
        $hasSeparateSecurityDepositInstallment = false;
        foreach ($chequeRows as $probeRow) {
            $probeType = (string)($probeRow['installment_type'] ?? '');
            if ($probeType === 'combined_fees') {
                $hasCombinedFeesInstallment = true;
            }
            if ($probeType === 'security_deposit') {
                $hasSeparateSecurityDepositInstallment = true;
            }
        }
        if (
            !$hasCombinedFeesInstallment
            && !empty($leaseRow['add_fees_to_first_installment'])
        ) {
            $firstRentOneTimeFees = re_receipt_money(
                (float)($leaseRow['admin_fees'] ?? 0)
                + (float)($leaseRow['ejari_fees'] ?? 0)
                + (float)($leaseRow['commission_fees'] ?? 0)
            );
            if ($vatDistributionType === 'first_installment') {
                $firstRentOneTimeFees = re_receipt_money(
                    $firstRentOneTimeFees + $totalVatAmount
                );
            }
        }
        // Sum of operational rent cheque faces (excludes Fees Installment / typed fee rows).
        // Used so uneven / front-loaded first cheques claim rent months by face share.
        $rentChequeFacesTotal = 0.0;
        $firstRentChequeId = 0;
        $firstRentChequeAmount = 0.0;
        foreach ($chequeRows as $probeRow) {
            if ((string)($probeRow['installment_type'] ?? 'rent') !== 'rent') {
                continue;
            }
            $probeAmount = $rentFaceOf($probeRow);
            $rentChequeFacesTotal = re_receipt_money($rentChequeFacesTotal + $probeAmount);
            if ($firstRentChequeId <= 0) {
                $firstRentChequeId = (int)($probeRow['cheque_id'] ?? 0);
                $firstRentChequeAmount = $probeAmount;
            }
        }
        $depositMergedOnFirstRent = !$hasSeparateSecurityDepositInstallment
            && empty($leaseRow['is_renewal_lease'])
            && re_cheque_security_deposit_merged_on_first_rent_face(
                $firstRentChequeAmount,
                $rentSharePerCheque,
                $chillerSharePerCheque,
                $firstRentOneTimeFees,
                $securityDepositAmount
            );
        // Keep typical-face / uneven detection aligned with schedule when deposit is on cheque 1.
        if ($depositMergedOnFirstRent) {
            $firstRentOneTimeFees = re_receipt_money($firstRentOneTimeFees + $securityDepositAmount);
        }
        // Face-proportional rent budgets must use rent-only cheque totals. If Admin/Ejari/
        // deposit sit on the 1st rent cheque, leave them out of the denominator — otherwise
        // a later large rent cheque under-claims the last month and parks the fee share
        // as spurious tenant credit (e.g. 21,750 × 1,800/45,300 = 864.24).
        if ($firstRentOneTimeFees > 0.005) {
            $rentChequeFacesTotal = re_receipt_money(max(0.0, $rentChequeFacesTotal - $firstRentOneTimeFees));
        }
        // Split/separate chiller never belongs on Fees Installment; keep service for rent rows.
        $combinedFeesExcludedTypes = [];
        // null = rent rows may claim any period fee type (Admin/Ejari/commission/service)
        // when those fees are merged into the 1st rent cheque (add_fees_to_first).
        $rentPeriodFeeTypes = null;
        if (!empty($leaseRow['split_chiller_fees']) || !empty($leaseRow['sep_chiller_fees'])) {
            $combinedFeesExcludedTypes[] = 'service';
        }
        // Only when a dedicated Fees Installment exists should rent rows be limited to
        // chiller/service — so they cannot re-claim Admin/Ejari after that fees cheque settles.
        // If fees are paid on the 1st rent (+chiller) cheque instead, leave $rentPeriodFeeTypes
        // null so Admin/Ejari/commission stay on that first rent instrument.
        if ($hasCombinedFeesInstallment) {
            $rentPeriodFeeTypes = ['service'];
        }

        $rentInvoices = array_values(array_filter(
            $invoices,
            static fn(array $row): bool => (string)($row['obligation_type'] ?? '') === 'rent'
        ));

        $map = [];
        $rentIndex = 0;
        $consumedFeeInvoiceIds = [];
        $consumedFeeObligationIds = [];

        foreach ($chequeRows as $row) {
            $chequeId = (int)$row['cheque_id'];
            $installmentType = (string)($row['installment_type'] ?? 'rent');
            $installmentDate = (string)($row['installment_date'] ?? '');
            $chequeAmount = re_receipt_money($row['cheque_amount'] ?? 0);

            if ($installmentType === 'rent') {
                $pickedLinkedExtra = re_cheque_pick_linked_extra_service_targets(
                    $invoices,
                    $obligations,
                    (int)($row['installment_id'] ?? 0)
                );
                // Rent/fee split below works on the face without this cheque's own Extra SC.
                $chequeAmount = $rentFaceOf($row);
                // Operational rent cheques often include merged/split fees on the same row.
                // Cover rent share first, then fill the remainder with fee invoices (not VAT).
                // Fee targets already claimed by Fees Installment / typed fee cheques are skipped.
                // Equal schedules stay on annual_rent/N. Uneven / front-loaded faces (e.g. 31,210
                // then 7,000 / 7,000) use face-proportional rent so later months stay on cheque 1.
                $typicalFace = re_receipt_money(
                    $rentSharePerCheque
                    + $chillerSharePerCheque
                    + $vatSharePerCheque
                    + (($chequeId === $firstRentChequeId) ? $firstRentOneTimeFees : 0.0)
                );
                $isUnevenRentFace = $chequeAmount > re_receipt_money($typicalFace + 50.0);
                if (
                    $isUnevenRentFace
                    && $rentChequeFacesTotal > 0.005
                    && $annualRent > 0.005
                ) {
                    $rentBudget = re_receipt_money(min(
                        $chequeAmount,
                        $chequeAmount * ($annualRent / $rentChequeFacesTotal)
                    ));
                } else {
                    $rentBudget = $rentSharePerCheque > 0.005
                        ? min($chequeAmount, $rentSharePerCheque)
                        : $chequeAmount;
                }
                // When Admin/Ejari/deposit (etc.) are merged onto the 1st rent cheque face,
                // do not spend that fee slice as rent-period budget. Otherwise the first
                // cheque over-claims into the next month (e.g. Nov), and the following
                // cheque's preferred caps skip that still-open invoice (FIFO looks "broken").
                if ($chequeId === $firstRentChequeId && $firstRentOneTimeFees > 0.005) {
                    $rentOnlyFace = re_receipt_money(max(0.0, $chequeAmount - $firstRentOneTimeFees));
                    $rentBudget = re_receipt_money(min($rentBudget, $rentOnlyFace));
                }
                $pickedRent = re_cheque_pick_rent_targets($rentInvoices, $rentIndex, $rentBudget);
                $rentIndex = (int)($pickedRent['consumed'] ?? $rentIndex);
                $rentCovered = re_receipt_money($pickedRent['expected_total'] ?? 0);
                // Fee remainder uses planned rent budget when rent snapped a fils over budget,
                // so chiller/fee invoices still receive their full cheque fee share.
                $feeNeed = re_receipt_money(max(0.0, $chequeAmount - min($rentCovered, $rentBudget)));
                // Period dates include already-settled months so fee remainder stays on those
                // periods and does not steal later open chiller invoices.
                $rentDueDates = $pickedRent['period_due_dates'] ?? [];
                // When security deposit is on this first rent cheque face (not a separate
                // deposit installment), prefer the deposit liability before other fee invoices.
                $pickedDeposit = ['invoice_ids' => [], 'obligation_ids' => [], 'expected_total' => 0.0, 'preferred_caps' => []];
                if (
                    $depositMergedOnFirstRent
                    && $chequeId === $firstRentChequeId
                    && $feeNeed > 0.02
                ) {
                    $pickedDeposit = re_cheque_pick_security_deposit_obligation(
                        $obligations,
                        $invoices,
                        $feeNeed,
                        $consumedFeeObligationIds
                    );
                    $feeNeed = re_receipt_money(max(
                        0.0,
                        $feeNeed - (float)($pickedDeposit['expected_total'] ?? 0)
                    ));
                }
                $pickedFees = re_cheque_pick_fee_remainder_targets(
                    $invoices,
                    $obligations,
                    $feeNeed,
                    $consumedFeeInvoiceIds,
                    $consumedFeeObligationIds,
                    $rentDueDates
                );
                // Period fees for collected display — only types this rent row owns
                // (service when Fees Installment / split chiller exists). Never re-claim
                // Admin/Ejari already owned by Fees Installment after those settle.
                $periodFeeInvoiceIds = [];
                foreach (re_cheque_fee_invoice_ids_for_due_dates($invoices, $rentDueDates, $rentPeriodFeeTypes) as $periodFeeInvoiceId) {
                    $periodFeeInvoiceId = (int)$periodFeeInvoiceId;
                    if ($periodFeeInvoiceId <= 0 || !empty($consumedFeeInvoiceIds[$periodFeeInvoiceId])) {
                        continue;
                    }
                    $periodFeeInvoiceIds[] = $periodFeeInvoiceId;
                    $consumedFeeInvoiceIds[$periodFeeInvoiceId] = true;
                }
                $picked = [
                    // Coverage invoice_ids include settled period targets so collected_total
                    // sums all receipt lines for this cheque — not only remaining scraps.
                    'invoice_ids' => array_values(array_unique(array_merge(
                        $pickedRent['period_invoice_ids'] ?? [],
                        $periodFeeInvoiceIds,
                        $pickedRent['invoice_ids'] ?? [],
                        $pickedFees['invoice_ids'] ?? [],
                        $pickedDeposit['invoice_ids'] ?? [],
                        $pickedLinkedExtra['invoice_ids']
                    ))),
                    'obligation_ids' => array_values(array_unique(array_merge(
                        $pickedRent['obligation_ids'] ?? [],
                        $pickedFees['obligation_ids'] ?? [],
                        $pickedDeposit['obligation_ids'] ?? [],
                        $pickedLinkedExtra['obligation_ids']
                    ))),
                    'expected_total' => re_receipt_money(
                        (float)($pickedRent['expected_total'] ?? 0)
                        + (float)($pickedFees['expected_total'] ?? 0)
                        + (float)($pickedDeposit['expected_total'] ?? 0)
                        + (float)$pickedLinkedExtra['expected_total']
                    ),
                    // Prefill caps stay open-only (preferred allocation suggestion).
                    'preferred_caps' => array_merge(
                        $pickedRent['preferred_caps'] ?? [],
                        $pickedDeposit['preferred_caps'] ?? [],
                        $pickedFees['preferred_caps'] ?? [],
                        $pickedLinkedExtra['preferred_caps']
                    ),
                ];
            } elseif ($installmentType === 'combined_fees') {
                // Include settled Admin/Ejari faces so coverage/consumed marks remain after
                // the Fees Installment is paid (open-only pick would otherwise go empty and
                // later rent rows would reclaim those invoices).
                $picked = re_cheque_pick_non_rent_targets_for_date(
                    $invoices,
                    $obligations,
                    $installmentDate,
                    $chequeAmount,
                    $consumedFeeInvoiceIds,
                    $consumedFeeObligationIds,
                    $combinedFeesExcludedTypes,
                    true
                );
                foreach (($picked['invoice_ids'] ?? []) as $claimedInvoiceId) {
                    $consumedFeeInvoiceIds[(int)$claimedInvoiceId] = true;
                }
                foreach (($picked['obligation_ids'] ?? []) as $claimedObligationId) {
                    $consumedFeeObligationIds[(int)$claimedObligationId] = true;
                }
            } elseif (in_array($installmentType, lease_non_rent_installment_types(), true)) {
                $picked = re_cheque_pick_typed_targets_for_date($invoices, $obligations, $installmentType, $installmentDate, $chequeAmount);
                // Separate VAT schedule: prefer obligation_type=vat / its invoice.
                // Embedded fee-VAT fallback remains only for unrepaired historical leases
                // that still embed VAT on fee rows while the schedule has installment_type=vat.
                if ($installmentType === 'vat'
                    && empty($picked['invoice_ids'])
                    && empty($picked['obligation_ids'])
                ) {
                    $picked = re_cheque_pick_embedded_vat_targets($invoices, $obligations, $installmentDate, $chequeAmount);
                }
                // Claim fee-type targets so later rent+chiller rows do not steal Admin/Ejari/etc.
                if (!in_array($installmentType, ['vat', 'security_deposit'], true)) {
                    foreach (($picked['invoice_ids'] ?? []) as $claimedInvoiceId) {
                        $consumedFeeInvoiceIds[(int)$claimedInvoiceId] = true;
                    }
                    foreach (($picked['obligation_ids'] ?? []) as $claimedObligationId) {
                        $consumedFeeObligationIds[(int)$claimedObligationId] = true;
                    }
                }
            } else {
                $picked = ['invoice_ids' => [], 'obligation_ids' => [], 'expected_total' => $chequeAmount, 'preferred_caps' => []];
            }

            $map[$chequeId] = [
                'cheque_id' => $chequeId,
                'cheque_number' => (string)($row['cheque_number'] ?? ''),
                'installment_id' => (int)($row['installment_id'] ?? 0),
                'installment_type' => $installmentType,
                'installment_date' => $installmentDate,
                'cheque_amount' => re_receipt_money($row['cheque_amount'] ?? 0),
                'invoice_ids' => $picked['invoice_ids'] ?? [],
                'obligation_ids' => $picked['obligation_ids'] ?? [],
                'expected_total' => re_receipt_money($picked['expected_total'] ?? $chequeAmount),
                'preferred_caps' => $picked['preferred_caps'] ?? [],
            ];
        }

        return $map;
    }
}

if (!function_exists('re_cheque_resolve_service_plan_coverage')) {
    /**
     * Coverage for Extra Service Charge payment-plan PDCs (installment_id NULL).
     * Prefers open Extra SC invoices for the plan's service_charge_id only — never rent.
     *
     * @return array<string,mixed>|null
     */
    function re_cheque_resolve_service_plan_coverage(PDO $conn, int $companyId, int $leaseId, int $chequeId): ?array
    {
        if ($companyId <= 0 || $leaseId <= 0 || $chequeId <= 0) {
            return null;
        }

        try {
            $col = $conn->query("SHOW COLUMNS FROM re_post_dated_cheques LIKE 'service_payment_plan_line_id'");
            if (!$col || !$col->fetch(PDO::FETCH_ASSOC)) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT c.id AS cheque_id,
                   c.cheque_number,
                   c.cheque_amount,
                   c.service_payment_plan_line_id,
                   p.service_charge_id
            FROM re_post_dated_cheques c
            JOIN re_service_charge_payment_plan_lines l
              ON l.id = c.service_payment_plan_line_id AND l.company_id = c.company_id
            JOIN re_service_charge_payment_plans p
              ON p.id = l.plan_id AND p.company_id = l.company_id
            WHERE c.id = ?
              AND c.company_id = ?
              AND c.lease_id = ?
              AND c.installment_id IS NULL
              AND c.service_payment_plan_line_id IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$chequeId, $companyId, $leaseId]);
        $cheque = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cheque) {
            return null;
        }

        $serviceChargeId = (int)($cheque['service_charge_id'] ?? 0);
        $chequeAmount = re_receipt_money($cheque['cheque_amount'] ?? 0);
        if ($serviceChargeId <= 0 || $chequeAmount <= 0.005) {
            return [
                'cheque_id' => $chequeId,
                'cheque_number' => (string)($cheque['cheque_number'] ?? ''),
                'installment_id' => 0,
                'installment_type' => 'extra_service',
                'installment_date' => '',
                'cheque_amount' => $chequeAmount,
                'invoice_ids' => [],
                'obligation_ids' => [],
                'expected_total' => 0.0,
                // Non-empty caps with no keys block FIFO spill onto rent/chiller.
                'preferred_caps' => ['_extra_service_empty' => 0.0],
                'service_charge_id' => $serviceChargeId,
            ];
        }

        $invStmt = $conn->prepare("
            SELECT i.id,
                   i.due_date,
                   i.outstanding_amount
            FROM re_invoices i
            JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.company_id = i.company_id
            JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = i.company_id
            JOIN re_billing_items bi ON bi.id = o.source_id AND bi.company_id = o.company_id
            WHERE i.company_id = ?
              AND i.lease_id = ?
              AND i.status IN ('sent', 'partial', 'overdue')
              AND i.outstanding_amount > 0.005
              AND o.source_type = 'billing_item'
              AND o.obligation_type = 'service'
              AND bi.service_charge_id = ?
            GROUP BY i.id
            ORDER BY i.due_date ASC, i.id ASC
        ");
        $invStmt->execute([$companyId, $leaseId, $serviceChargeId]);
        $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $invoiceIds = [];
        $preferredCaps = [];
        $expectedTotal = 0.0;
        $need = $chequeAmount;

        foreach ($invoices as $row) {
            if ($expectedTotal >= $need - 0.02) {
                break;
            }
            $invoiceId = (int)($row['id'] ?? 0);
            $open = re_receipt_money($row['outstanding_amount'] ?? 0);
            if ($invoiceId <= 0 || $open <= 0.005) {
                continue;
            }
            $take = re_cheque_take_with_invoice_fils_snap($open, $need, $expectedTotal);
            if ($take <= 0.005) {
                continue;
            }
            $invoiceIds[] = $invoiceId;
            $preferredCaps['invoice:' . $invoiceId] = $take;
            $expectedTotal = re_receipt_money($expectedTotal + $take);
        }

        if ($preferredCaps === []) {
            $preferredCaps = ['_extra_service_empty' => 0.0];
        }

        return [
            'cheque_id' => $chequeId,
            'cheque_number' => (string)($cheque['cheque_number'] ?? ''),
            'installment_id' => 0,
            'installment_type' => 'extra_service',
            'installment_date' => '',
            'cheque_amount' => $chequeAmount,
            'invoice_ids' => $invoiceIds,
            'obligation_ids' => [],
            'expected_total' => $expectedTotal,
            'preferred_caps' => $preferredCaps,
            'service_charge_id' => $serviceChargeId,
        ];
    }
}

if (!function_exists('re_cheque_pick_extra_service_targets')) {
    /**
     * Prefer open Extra SC invoice targets for a service charge (optional billing item first).
     *
     * @return array{preferred_keys:list<string>,preferred_caps:array<string,float>}
     */
    function re_cheque_pick_extra_service_targets(
        PDO $conn,
        int $companyId,
        int $leaseId,
        float $amount,
        int $serviceChargeId = 0,
        int $billingItemId = 0
    ): array {
        $amount = re_receipt_money($amount);
        if ($companyId <= 0 || $leaseId <= 0 || $amount <= 0.005) {
            return ['preferred_keys' => [], 'preferred_caps' => ['_extra_service_empty' => 0.0]];
        }

        $sql = "
            SELECT i.id,
                   i.due_date,
                   i.outstanding_amount,
                   bi.id AS billing_item_id
            FROM re_invoices i
            JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.company_id = i.company_id
            JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = i.company_id
            JOIN re_billing_items bi ON bi.id = o.source_id AND bi.company_id = o.company_id
            WHERE i.company_id = ?
              AND i.lease_id = ?
              AND i.status IN ('sent', 'partial', 'overdue')
              AND i.outstanding_amount > 0.005
              AND o.source_type = 'billing_item'
              AND o.obligation_type = 'service'
        ";
        $args = [$companyId, $leaseId];
        if ($serviceChargeId > 0) {
            $sql .= ' AND bi.service_charge_id = ?';
            $args[] = $serviceChargeId;
        }
        $sql .= ' GROUP BY i.id ORDER BY i.due_date ASC, i.id ASC';

        $stmt = $conn->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($billingItemId > 0) {
            usort($rows, static function (array $a, array $b) use ($billingItemId): int {
                $aMatch = ((int)($a['billing_item_id'] ?? 0) === $billingItemId) ? 0 : 1;
                $bMatch = ((int)($b['billing_item_id'] ?? 0) === $billingItemId) ? 0 : 1;
                return ($aMatch <=> $bMatch)
                    ?: strcmp((string)($a['due_date'] ?? ''), (string)($b['due_date'] ?? ''))
                    ?: ((int)$a['id'] <=> (int)$b['id']);
            });
        }

        $preferredKeys = [];
        $preferredCaps = [];
        $expectedTotal = 0.0;
        foreach ($rows as $row) {
            if ($expectedTotal >= $amount - 0.02) {
                break;
            }
            $invoiceId = (int)($row['id'] ?? 0);
            $open = re_receipt_money($row['outstanding_amount'] ?? 0);
            if ($invoiceId <= 0 || $open <= 0.005) {
                continue;
            }
            $take = re_cheque_take_with_invoice_fils_snap($open, $amount, $expectedTotal);
            if ($take <= 0.005) {
                continue;
            }
            $key = 'invoice:' . $invoiceId;
            $preferredKeys[] = $key;
            $preferredCaps[$key] = $take;
            $expectedTotal = re_receipt_money($expectedTotal + $take);
        }

        if ($preferredCaps === []) {
            $preferredCaps = ['_extra_service_empty' => 0.0];
        }

        return ['preferred_keys' => $preferredKeys, 'preferred_caps' => $preferredCaps];
    }
}

if (!function_exists('re_cheque_invoice_is_settled')) {
    function re_cheque_invoice_is_settled(PDO $conn, int $companyId, int $invoiceId): bool
    {
        $stmt = $conn->prepare("
            SELECT outstanding_amount, status
            FROM re_invoices
            WHERE id = ? AND company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$invoiceId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ((string)($row['status'] ?? '') === 'paid') {
            return true;
        }
        return (float)($row['outstanding_amount'] ?? 0) <= 0.005;
    }
}

if (!function_exists('re_cheque_obligation_is_settled')) {
    function re_cheque_obligation_is_settled(PDO $conn, int $companyId, int $obligationId): bool
    {
        $stmt = $conn->prepare("
            SELECT total_amount, allocated_amount, status
            FROM re_obligations
            WHERE id = ? AND company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$obligationId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ((string)($row['status'] ?? '') === 'settled') {
            return true;
        }
        return (float)($row['allocated_amount'] ?? 0) >= (float)($row['total_amount'] ?? 0) - 0.005;
    }
}

if (!function_exists('re_cheque_coverage_targets_status')) {
    /**
     * @param array<string,mixed>|null $coverage
     * @return array<string,mixed>
     */
    function re_cheque_coverage_targets_status(PDO $conn, int $companyId, ?array $coverage, ?int $forChequeId = null): array
    {
        $empty = [
            'allocated_total' => 0.0,
            'expected_total' => 0.0,
            'is_fully_covered' => false,
            'target_count' => 0,
            'settled_count' => 0,
            'receipts' => [],
            'receipt_ids' => [],
            'receipt_shares' => [],
        ];
        if (!$coverage) {
            return $empty;
        }

        $invoiceIds = $coverage['invoice_ids'] ?? [];
        $obligationIds = $coverage['obligation_ids'] ?? [];
        if (!$invoiceIds && !$obligationIds) {
            return $empty;
        }

        $params = [$companyId];
        $clauses = [];
        if ($invoiceIds) {
            $clauses[] = 'ra.invoice_id IN (' . implode(',', array_fill(0, count($invoiceIds), '?')) . ')';
            $params = array_merge($params, $invoiceIds);
        }
        if ($obligationIds) {
            $clauses[] = 'ra.obligation_id IN (' . implode(',', array_fill(0, count($obligationIds), '?')) . ')';
            $params = array_merge($params, $obligationIds);
        }

        $allocatedTotal = 0.0;
        $receiptIds = [];
        $receiptShares = [];
        if ($clauses) {
            $allocStmt = $conn->prepare("
                SELECT ra.payment_id, SUM(ra.amount_allocated) AS allocated
                FROM re_receipt_allocations ra
                WHERE ra.company_id = ?
                  AND (" . implode(' OR ', $clauses) . ")
                GROUP BY ra.payment_id
            ");
            $allocStmt->execute($params);
            foreach ($allocStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pid = (int)($row['payment_id'] ?? 0);
                $share = re_receipt_money($row['allocated'] ?? 0);
                $allocatedTotal += $share;
                if ($pid > 0) {
                    $receiptIds[] = $pid;
                    $receiptShares[$pid] = $share;
                }
            }
        }
        $allocatedTotal = re_receipt_money($allocatedTotal);
        $receiptIds = array_values(array_unique(array_filter($receiptIds)));

        $receipts = [];
        if ($receiptIds) {
            $placeholders = implode(',', array_fill(0, count($receiptIds), '?'));
            $paymentStmt = $conn->prepare("
                SELECT id, receipt_number, amount, payment_date, cleared_date, allocation_status,
                       payment_method, reference_number, notes, cheque_id, installment_id
                FROM re_payments
                WHERE company_id = ?
                  AND id IN ($placeholders)
                  AND accounting_mode = 'invoice'
                  AND receipt_status = 'cleared'
                ORDER BY COALESCE(cleared_date, payment_date) ASC, id ASC
            ");
            $paymentStmt->execute(array_merge([$companyId], $receiptIds));
            $receipts = $paymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Drop receipts that are explicitly linked to a different cheque instrument.
            if ($forChequeId !== null && $forChequeId > 0) {
                $filtered = [];
                $rebuiltShares = [];
                $rebuiltTotal = 0.0;
                foreach ($receipts as $paymentRow) {
                    $pid = (int)($paymentRow['id'] ?? 0);
                    $linkedChequeId = (int)($paymentRow['cheque_id'] ?? 0);
                    if ($linkedChequeId > 0 && $linkedChequeId !== $forChequeId) {
                        continue;
                    }
                    $share = $receiptShares[$pid] ?? 0.0;
                    $paymentRow['allocated_to_targets'] = $share;
                    $filtered[] = $paymentRow;
                    if ($pid > 0) {
                        $rebuiltShares[$pid] = $share;
                        $rebuiltTotal = re_receipt_money($rebuiltTotal + $share);
                    }
                }
                $receipts = $filtered;
                $receiptShares = $rebuiltShares;
                $allocatedTotal = $rebuiltTotal;
                $receiptIds = array_keys($rebuiltShares);
            } else {
                foreach ($receipts as &$paymentRow) {
                    $pid = (int)($paymentRow['id'] ?? 0);
                    $paymentRow['allocated_to_targets'] = $receiptShares[$pid] ?? 0.0;
                }
                unset($paymentRow);
            }
        }

        $settledCount = 0;
        foreach ($invoiceIds as $invoiceId) {
            if (re_cheque_invoice_is_settled($conn, $companyId, (int)$invoiceId)) {
                $settledCount++;
            }
        }
        foreach ($obligationIds as $obligationId) {
            if (re_cheque_obligation_is_settled($conn, $companyId, (int)$obligationId)) {
                $settledCount++;
            }
        }

        $targetCount = count($invoiceIds) + count($obligationIds);
        $expectedTotal = re_receipt_money($coverage['expected_total'] ?? ($coverage['cheque_amount'] ?? 0));
        $chequeAmount = re_receipt_money($coverage['cheque_amount'] ?? $expectedTotal);
        $allTargetsSettled = $targetCount > 0 && $settledCount === $targetCount;
        $amountCovered = $chequeAmount <= 0
            ? $allTargetsSettled
            : ($allocatedTotal >= $chequeAmount - 0.02 && $allTargetsSettled);

        return [
            'allocated_total' => $allocatedTotal,
            'expected_total' => $expectedTotal,
            'cheque_amount' => $chequeAmount,
            'is_fully_covered' => $amountCovered,
            'target_count' => $targetCount,
            'settled_count' => $settledCount,
            'invoice_ids' => $invoiceIds,
            'obligation_ids' => $obligationIds,
            'receipts' => $receipts,
            'receipt_ids' => $receiptIds,
            'receipt_shares' => $receiptShares,
        ];
    }
}

if (!function_exists('re_cheque_sync_cleared_from_financial_coverage')) {
    /**
     * Mark cheques cleared when their mapped invoices/obligations are fully settled.
     *
     * @return array{synced:list<int>,skipped:list<int>,errors:list<string>}
     */
    function re_cheque_sync_cleared_from_financial_coverage(
        PDO $conn,
        int $companyId,
        int $leaseId,
        ?int $userId = null,
        ?int $triggerPaymentId = null
    ): array {
        $result = ['synced' => [], 'skipped' => [], 'errors' => []];
        if ($leaseId <= 0) {
            return $result;
        }

        $coverageMap = re_cheque_resolve_lease_coverage_map($conn, $companyId, $leaseId);
        if (!$coverageMap) {
            return $result;
        }

        foreach ($coverageMap as $chequeId => $coverage) {
            $chequeId = (int)$chequeId;
            $cheque = re_cheque_load($conn, $companyId, $chequeId);
            if (!$cheque) {
                continue;
            }

            $status = re_cheque_normalize_status((string)($cheque['status'] ?? ''));
            if (in_array($status, ['cleared', 'replaced', 'cancelled', 'returned'], true)) {
                $result['skipped'][] = $chequeId;
                continue;
            }

            $targetStatus = re_cheque_coverage_targets_status($conn, $companyId, $coverage, $chequeId);
            if (empty($targetStatus['is_fully_covered'])) {
                $result['skipped'][] = $chequeId;
                continue;
            }

            $receiptId = $triggerPaymentId;
            if (!$receiptId && !empty($targetStatus['receipt_ids'])) {
                $receiptId = (int)$targetStatus['receipt_ids'][0];
            }

            $chequeNumber = (string)($coverage['cheque_number'] ?? $cheque['cheque_number'] ?? ('#' . $chequeId));
            $clearResult = re_cheque_update_status(
                $conn,
                $companyId,
                $chequeId,
                'cleared',
                $userId,
                'Cleared automatically: underlying invoices/obligations fully paid (Option C coverage sync) for ' . $chequeNumber,
                'cheque_financial_coverage',
                $receiptId,
                null,
                true
            );
            if (empty($clearResult['success'])) {
                $result['errors'][] = 'Cheque #' . $chequeId . ': ' . (string)($clearResult['error'] ?? 'Could not clear.');
                continue;
            }
            $result['synced'][] = $chequeId;
        }

        return $result;
    }
}
