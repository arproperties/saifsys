<?php
/**
 * Phase 1 obligation preview helper.
 *
 * Read-only by design:
 * - no INSERT/UPDATE/DELETE
 * - no invoice generation
 * - no payment/allocation changes
 * - no cheque changes
 * - no journal posting
 */
declare(strict_types=1);

require_once __DIR__ . '/lease_vat_calculator.php';

if (!function_exists('re_obligation_table_exists')) {
    function re_obligation_table_exists(PDO $conn, string $table): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('re_obligation_column_exists')) {
    function re_obligation_column_exists(PDO $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('re_accounting_phase1_feature_enabled')) {
    function re_accounting_phase1_feature_enabled(PDO $conn): bool
    {
        try {
            $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = 're_accounting_invoice_mode_enabled' LIMIT 1");
            $stmt->execute();
            return (string)$stmt->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('re_accounting_mode_for_lease')) {
    function re_accounting_mode_for_lease(PDO $conn, array $lease): string
    {
        if (array_key_exists('accounting_mode', $lease)) {
            return (string)($lease['accounting_mode'] ?: 'legacy');
        }
        return 'legacy';
    }
}

if (!function_exists('re_obligation_money')) {
    function re_obligation_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_obligation_add_month_safe')) {
    function re_obligation_add_month_safe(DateTimeImmutable $date): DateTimeImmutable
    {
        $day = (int)$date->format('d');
        $firstOfNext = $date->modify('first day of next month');
        $lastDay = (int)$firstOfNext->format('t');
        return $firstOfNext->setDate(
            (int)$firstOfNext->format('Y'),
            (int)$firstOfNext->format('m'),
            min($day, $lastDay)
        );
    }
}

if (!function_exists('re_obligation_monthly_periods')) {
    /**
     * @return list<array{start:string,end:string,due:string}>
     */
    function re_obligation_monthly_periods(string $startDate, string $endDate): array
    {
        try {
            $cursor = new DateTimeImmutable($startDate);
            $leaseEnd = new DateTimeImmutable($endDate);
        } catch (Throwable $e) {
            return [];
        }

        if ($cursor > $leaseEnd) {
            return [];
        }

        $periods = [];
        $guard = 0;
        while ($cursor <= $leaseEnd && $guard < 240) {
            $next = re_obligation_add_month_safe($cursor);
            $periodEnd = $next->modify('-1 day');
            if ($periodEnd > $leaseEnd) {
                $periodEnd = $leaseEnd;
            }
            $periods[] = [
                'start' => $cursor->format('Y-m-d'),
                'end' => $periodEnd->format('Y-m-d'),
                'due' => $cursor->format('Y-m-d'),
            ];
            $cursor = $next;
            $guard++;
        }

        return $periods;
    }
}

if (!function_exists('re_obligation_tax_for_rent')) {
    /**
     * @return array{treatment:string,rate:float}
     */
    function re_obligation_tax_for_rent(array $lease): array
    {
        $rate = (float)($lease['lease_vat_rate'] ?? 5.0);
        $taxable = !empty($lease['vat_applicable_on_rent']) && $rate > 0;
        return [
            'treatment' => $taxable ? 'standard' : 'exempt',
            'rate' => $taxable ? $rate : 0.0,
        ];
    }
}

if (!function_exists('re_obligation_tax_for_extra')) {
    /**
     * @return array{treatment:string,rate:float}
     */
    function re_obligation_tax_for_extra(array $lease): array
    {
        $rate = (float)($lease['lease_vat_rate'] ?? 5.0);
        $taxable = !array_key_exists('vat_applicable_on_extra_charges', $lease)
            || !empty($lease['vat_applicable_on_extra_charges']);
        return [
            'treatment' => ($taxable && $rate > 0) ? 'standard' : 'exempt',
            'rate' => ($taxable && $rate > 0) ? $rate : 0.0,
        ];
    }
}

if (!function_exists('re_obligation_preview_row')) {
    /**
     * @return array<string,mixed>
     */
    function re_obligation_preview_row(
        string $type,
        string $class,
        string $description,
        string $dueDate,
        float $subtotal,
        string $taxTreatment = 'exempt',
        float $taxRate = 0.0,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        string $sourceType = 'lease',
        $sourceId = null
    ): array {
        $vat = $taxTreatment === 'standard' ? re_obligation_money($subtotal * ($taxRate / 100)) : 0.0;
        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'obligation_type' => $type,
            'accounting_class' => $class,
            'description' => $description,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_date' => $dueDate,
            'tax_treatment' => $taxTreatment,
            'tax_rate' => re_obligation_money($taxRate),
            'subtotal_amount' => re_obligation_money($subtotal),
            'vat_amount' => $vat,
            'total_amount' => re_obligation_money($subtotal + $vat),
        ];
    }
}

if (!function_exists('re_obligation_fee_distribution')) {
    function re_obligation_fee_distribution(array $lease, string $splitKey, string $separateKey): string
    {
        if (!empty($lease[$splitKey])) return 'split';
        if (!empty($lease[$separateKey])) return 'separate';
        return 'first';
    }
}

if (!function_exists('re_obligation_add_fee_rows')) {
    /**
     * Add fee obligations. If the fee is split in the lease schedule, create one
     * obligation per rent period so invoice candidates align with cheque rows.
     *
     * @param list<array{start:string,end:string,due:string}> $periods
     * @param list<array<string,mixed>> $obligations
     */
    function re_obligation_add_fee_rows(array &$obligations, array $lease, array $periods, string $type, string $class, string $label, float $amount, string $distribution, array $tax, string $startDate, int $leaseId, string $taxTreatmentOverride = '', ?float $taxRateOverride = null): void
    {
        if ($amount <= 0) return;
        $taxTreatment = $taxTreatmentOverride !== '' ? $taxTreatmentOverride : (string)$tax['treatment'];
        $taxRate = $taxRateOverride !== null ? $taxRateOverride : (float)$tax['rate'];

        if ($distribution === 'split' && $periods) {
            $per = re_obligation_money($amount / count($periods));
            $allocated = 0.0;
            foreach ($periods as $idx => $period) {
                $subtotal = ($idx === count($periods) - 1)
                    ? re_obligation_money($amount - $allocated)
                    : $per;
                $allocated += $subtotal;
                $obligations[] = re_obligation_preview_row(
                    $type,
                    $class,
                    $label . ' split period ' . ($idx + 1),
                    $period['due'],
                    $subtotal,
                    $taxTreatment,
                    $taxRate,
                    $period['start'],
                    $period['end'],
                    'lease',
                    $leaseId
                );
            }
            return;
        }

        $obligations[] = re_obligation_preview_row(
            $type,
            $class,
            $label,
            $startDate ?: date('Y-m-d'),
            $amount,
            $taxTreatment,
            $taxRate,
            null,
            null,
            'lease',
            $leaseId
        );
    }
}

if (!function_exists('re_obligation_stable_key')) {
    function re_obligation_stable_key(int $leaseId, array $row): string
    {
        $parts = [
            'lease',
            $leaseId,
            $row['source_type'] ?? 'lease',
            $row['source_id'] ?? '0',
            $row['obligation_type'] ?? 'other',
            $row['period_start'] ?? '',
            $row['period_end'] ?? '',
            $row['due_date'] ?? '',
            substr(sha1((string)($row['description'] ?? '')), 0, 10),
        ];
        return implode(':', array_map('strval', $parts));
    }
}

if (!function_exists('re_obligation_preview_load_lease')) {
    /**
     * @return array<string,mixed>|null
     */
    function re_obligation_preview_load_lease(PDO $conn, int $companyId, int $leaseId): ?array
    {
        $selectAccountingMode = re_obligation_column_exists($conn, 're_leases', 'accounting_mode')
            ? 'l.accounting_mode'
            : "'legacy' AS accounting_mode";

        $stmt = $conn->prepare("
            SELECT l.*, {$selectAccountingMode},
                   t.first_name, t.last_name, t.company_name, t.tenant_type,
                   u.unit_number, u.unit_type, b.name AS building_name
            FROM re_leases l
            LEFT JOIN re_tenants t ON t.id = l.tenant_id
            LEFT JOIN re_units u ON u.id = l.unit_id
            LEFT JOIN re_buildings b ON b.id = u.building_id
            WHERE l.id = ? AND l.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$leaseId, $companyId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        return $lease ?: null;
    }
}

if (!function_exists('re_obligation_display_label')) {
    /**
     * Human-readable obligation/invoice type label.
     * Distinguishes lease chiller/AMC (service) from Extra Service Charge (billing_item).
     */
    function re_obligation_display_label(
        ?string $obligationType,
        ?string $sourceType = null,
        ?string $description = null,
        ?string $serviceChargeName = null
    ): string {
        $type = strtolower(trim((string)$obligationType));
        $src = strtolower(trim((string)$sourceType));
        $desc = trim((string)$description);
        if ($desc !== '' && strcasecmp($desc, $type) === 0) {
            $desc = '';
        }
        $charge = trim((string)$serviceChargeName);

        if ($type === 'service' && $src === 'billing_item') {
            $name = $charge !== '' ? $charge : ($desc !== '' ? $desc : 'Extra service');
            return 'extra service: ' . $name;
        }
        if ($type === 'service') {
            if ($desc !== '') {
                return 'lease service: ' . $desc;
            }
            return 'lease service (chiller / AMC)';
        }
        if ($type === 'admin_fee') {
            return $desc !== '' ? $desc : 'admin fee';
        }
        if ($type === 'other') {
            return $desc !== '' ? $desc : 'other fee';
        }
        if ($desc !== '') {
            return $desc;
        }
        return $type !== '' ? str_replace('_', ' ', $type) : '—';
    }
}

if (!function_exists('re_obligation_preview_installments')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_obligation_preview_installments(PDO $conn, int $companyId, int $leaseId): array
    {
        $dueDateSelect = re_obligation_column_exists($conn, 're_lease_installments', 'due_date')
            ? 'li.due_date'
            : 'li.installment_date AS due_date';
        $vatSelect = re_obligation_column_exists($conn, 're_lease_installments', 'vat_amount')
            ? 'li.vat_amount'
            : '0.00 AS vat_amount';
        $typeSelect = re_obligation_column_exists($conn, 're_lease_installments', 'installment_type')
            ? 'li.installment_type'
            : "'rent' AS installment_type";
        $paymentSelect = re_obligation_column_exists($conn, 're_lease_installments', 'payment_id')
            ? 'li.payment_id'
            : 'NULL AS payment_id';
        $invoiceSelect = re_obligation_column_exists($conn, 're_lease_installments', 'invoice_id')
            ? 'li.invoice_id'
            : 'NULL AS invoice_id';

        $stmt = $conn->prepare("
            SELECT li.id, li.installment_date, {$dueDateSelect}, li.amount, {$vatSelect}, li.status, {$typeSelect}, {$paymentSelect}, {$invoiceSelect},
                   COALESCE(pa.allocated_amount, 0) AS allocated_amount,
                   COALESCE(rp.receipt_amount, 0) AS receipt_amount,
                   COALESCE(p.amount, 0) AS direct_payment_amount
            FROM re_lease_installments li
            LEFT JOIN (
                SELECT installment_id, SUM(amount_allocated) AS allocated_amount
                FROM re_payment_allocations
                GROUP BY installment_id
            ) pa ON pa.installment_id = li.id
            LEFT JOIN re_payments p ON p.id = li.payment_id
            LEFT JOIN (
                SELECT installment_id, SUM(amount) AS receipt_amount
                FROM re_payments
                WHERE company_id = ? AND lease_id = ? AND installment_id IS NOT NULL
                GROUP BY installment_id
            ) rp ON rp.installment_id = li.id
            WHERE li.company_id = ? AND li.lease_id = ?
            ORDER BY li.installment_date ASC, li.id ASC
        ");
        $stmt->execute([$companyId, $leaseId, $companyId, $leaseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $accountingMode = 'legacy';
        try {
            $modeStmt = $conn->prepare("SELECT COALESCE(accounting_mode, 'legacy') FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
            $modeStmt->execute([$leaseId, $companyId]);
            $accountingMode = strtolower((string)($modeStmt->fetchColumn() ?: 'legacy'));
        } catch (Throwable $e) {
            $accountingMode = 'legacy';
        }

        // Invoice Mode: installment.payment_id / Legacy allocations are often empty.
        // Prefer operational cheque collection summaries linked by installment_id.
        $imByInstallment = [];
        if ($accountingMode === 'invoice') {
            try {
                require_once __DIR__ . '/receipt_allocation_engine.php';
                $summaries = re_lease_cheque_receipt_summaries($conn, $companyId, $leaseId, false);
                $chequeRows = $conn->prepare("
                    SELECT id, installment_id
                    FROM re_post_dated_cheques
                    WHERE company_id = ? AND lease_id = ? AND installment_id IS NOT NULL
                ");
                $chequeRows->execute([$companyId, $leaseId]);
                foreach ($chequeRows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ch) {
                    $instId = (int)($ch['installment_id'] ?? 0);
                    $chequeId = (int)($ch['id'] ?? 0);
                    if ($instId <= 0 || $chequeId <= 0 || !isset($summaries[$chequeId])) {
                        continue;
                    }
                    $imByInstallment[$instId] = $summaries[$chequeId];
                }
            } catch (Throwable $e) {
                $imByInstallment = [];
            }
        }

        foreach ($rows as &$row) {
            $instId = (int)($row['id'] ?? 0);
            if ($accountingMode === 'invoice' && isset($imByInstallment[$instId])) {
                $summary = $imByInstallment[$instId];
                $collected = (float)($summary['collected_total'] ?? 0);
                $face = (float)($summary['cheque_amount'] ?? $row['amount'] ?? 0);
                if (!empty($summary['is_fully_collected']) || ($face > 0.005 && $collected >= $face - 0.02)) {
                    $row['display_status'] = 'paid';
                } elseif ($collected > 0.005) {
                    $row['display_status'] = 'partial';
                } else {
                    $row['display_status'] = 'pending';
                }
                continue;
            }

            $paid = max((float)($row['allocated_amount'] ?? 0), (float)($row['receipt_amount'] ?? 0), (float)($row['direct_payment_amount'] ?? 0));
            $due = (float)($row['amount'] ?? 0);
            if ($paid >= $due - 0.009 && $due > 0) {
                $row['display_status'] = 'paid';
            } elseif ($paid > 0.005) {
                $row['display_status'] = 'partial';
            } else {
                $row['display_status'] = 'pending';
            }
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('re_obligation_preview_billing_items')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_obligation_preview_billing_items(PDO $conn, int $companyId, int $leaseId): array
    {
        if (!re_obligation_table_exists($conn, 're_billing_items')) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT bi.*,
                   sc.charge_name AS service_charge_name,
                   sc.vat_treatment AS sc_vat_treatment,
                   sc.vat_rate AS sc_vat_rate,
                   sc.amount_basis AS sc_amount_basis
            FROM re_billing_items bi
            LEFT JOIN re_service_charges sc
              ON sc.id = bi.service_charge_id AND sc.company_id = bi.company_id
            WHERE bi.company_id = ? AND bi.lease_id = ?
              AND bi.item_type IN ('service_charge', 'parking_fee', 'penalty', 'other')
              AND bi.status <> 'waived'
              AND COALESCE(bi.is_waived, 0) = 0
              AND (
                    bi.item_type <> 'service_charge'
                 OR bi.service_charge_id IS NULL
                 OR COALESCE(sc.lifecycle_status, 'active') NOT IN ('draft', 'cancelled')
              )
            ORDER BY bi.due_date ASC, bi.id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Invoice Mode: is_paid is Legacy; use obligation allocated_amount for Extra SC collections.
        $allocatedMap = [];
        try {
            require_once __DIR__ . '/receipt_allocation_engine.php';
            $ids = array_map(static fn(array $r): int => (int)($r['id'] ?? 0), $rows);
            $allocatedMap = re_billing_item_obligation_allocated_map($conn, $companyId, $leaseId, $ids);
        } catch (Throwable $e) {
            $allocatedMap = [];
        }

        foreach ($rows as &$row) {
            $total = (float)($row['total_amount'] ?? $row['amount'] ?? 0);
            $paid = (float)($allocatedMap[(int)($row['id'] ?? 0)] ?? 0);
            if ($paid <= 0.005 && !empty($row['is_paid'])) {
                $paid = $total;
            }
            $row['collected_amount'] = $paid;
            if (($row['status'] ?? '') === 'waived' || !empty($row['is_waived'])) {
                $row['display_status'] = 'waived';
            } elseif ($total > 0.005 && $paid >= $total - 0.02) {
                $row['display_status'] = 'paid';
            } elseif ($paid > 0.005) {
                $row['display_status'] = 'partial';
            } else {
                $row['display_status'] = 'pending';
            }
            $row['display_type'] = ((string)($row['item_type'] ?? '') === 'service_charge')
                ? 'extra service'
                : (string)($row['item_type'] ?? '');
            $row['display_name'] = trim((string)($row['service_charge_name'] ?? '')) !== ''
                ? (string)$row['service_charge_name']
                : (string)($row['item_name'] ?? $row['description'] ?? '');
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('re_obligation_preview_from_billing_item')) {
    /**
     * @return array<string,mixed>
     */
    function re_obligation_preview_from_billing_item(array $item): array
    {
        $itemType = (string)($item['item_type'] ?? 'other');
        $typeMap = [
            'penalty' => 'penalty',
            'parking_fee' => 'parking',
            'service_charge' => 'service',
            'rent' => 'rent',
            'other' => 'other',
        ];
        $obligationType = $typeMap[$itemType] ?? 'other';
        $class = $obligationType === 'penalty' ? 'penalty' : ($obligationType === 'rent' ? 'revenue' : 'service');
        $name = (string)($item['item_name'] ?? $item['description'] ?? ucwords(str_replace('_', ' ', $obligationType)));
        $amount = (float)($item['amount'] ?? $item['total_amount'] ?? 0);
        $taxAmount = (float)($item['tax_amount'] ?? 0);
        $total = (float)($item['total_amount'] ?? 0);
        $subtotal = $amount > 0 ? $amount : max(0, $total - $taxAmount);
        $taxRate = (float)($item['tax_rate'] ?? 0);
        $dueDate = (string)($item['due_date'] ?? $item['billing_date'] ?? date('Y-m-d'));

        // Preserve UAE VAT classification from the service-charge snapshot when present.
        $allowedTreatments = ['standard', 'zero_rated', 'exempt', 'out_of_scope'];
        $taxTreatment = strtolower(trim((string)($item['sc_vat_treatment'] ?? $item['tax_treatment'] ?? '')));
        if (!in_array($taxTreatment, $allowedTreatments, true)) {
            if ($taxAmount > 0.005 || $taxRate > 0.005) {
                $taxTreatment = 'standard';
            } else {
                $taxTreatment = 'exempt';
            }
        }
        if ($taxTreatment !== 'standard') {
            $taxRate = 0.0;
        }

        return re_obligation_preview_row(
            $obligationType,
            $class,
            $name,
            $dueDate,
            $subtotal,
            $taxTreatment,
            $taxRate,
            $item['billing_period_start'] ?? null,
            $item['billing_period_end'] ?? null,
            'billing_item',
            $item['id'] ?? null
        );
    }
}

if (!function_exists('re_obligation_preview_generate')) {
    /**
     * @return array<string,mixed>
     */
    function re_obligation_preview_generate(PDO $conn, int $companyId, int $leaseId): array
    {
        $lease = re_obligation_preview_load_lease($conn, $companyId, $leaseId);
        if (!$lease) {
            return ['success' => false, 'error' => 'Lease not found', 'lease' => null];
        }

        $installments = re_obligation_preview_installments($conn, $companyId, $leaseId);
        $billingItems = re_obligation_preview_billing_items($conn, $companyId, $leaseId);
        $featureEnabled = re_accounting_phase1_feature_enabled($conn);
        $accountingMode = re_accounting_mode_for_lease($conn, $lease);

        $obligations = [];
        $startDate = (string)($lease['start_date'] ?? '');
        $endDate = (string)($lease['end_date'] ?? '');
        $annualRent = (float)($lease['annual_rent'] ?? 0);
        $periods = ($startDate && $endDate) ? re_obligation_monthly_periods($startDate, $endDate) : [];
        $rentTax = re_obligation_tax_for_rent($lease);

        // Confirmed: Separate VAT cheque → one vat obligation; rent/fee docs stay net.
        $vatDist = function_exists('lease_vat_normalize_distribution')
            ? lease_vat_normalize_distribution($lease['vat_distribution_type'] ?? 'first_installment')
            : (string)($lease['vat_distribution_type'] ?? 'first_installment');
        $separateVatPayment = ($vatDist === 'separate_payment');
        $separateVatTotal = $separateVatPayment
            ? re_obligation_money((float)($lease['total_vat_amount'] ?? 0))
            : 0.0;

        if ($annualRent > 0 && !empty($periods)) {
            $monthly = re_obligation_money($annualRent / count($periods));
            $rentVatTotal = ($separateVatPayment || $rentTax['treatment'] !== 'standard')
                ? 0.0
                : re_obligation_money((float)($lease['rent_vat_amount'] ?? ($annualRent * ((float)$rentTax['rate'] / 100))));
            $monthlyVat = count($periods) > 0 ? re_obligation_money($rentVatTotal / count($periods)) : 0.0;
            $allocated = 0.0;
            $allocatedVat = 0.0;
            foreach ($periods as $idx => $period) {
                $subtotal = ($idx === count($periods) - 1)
                    ? re_obligation_money($annualRent - $allocated)
                    : $monthly;
                $allocated += $subtotal;
                $vatAmount = ($idx === count($periods) - 1)
                    ? re_obligation_money($rentVatTotal - $allocatedVat)
                    : $monthlyVat;
                $allocatedVat += $vatAmount;
                $rentRow = re_obligation_preview_row(
                    'rent',
                    'revenue',
                    'Monthly rent recognition period ' . ($idx + 1),
                    $period['due'],
                    $subtotal,
                    $separateVatPayment ? 'exempt' : $rentTax['treatment'],
                    $separateVatPayment ? 0.0 : $rentTax['rate'],
                    $period['start'],
                    $period['end'],
                    'lease',
                    $leaseId
                );
                $rentRow['vat_amount'] = $vatAmount;
                $rentRow['total_amount'] = re_obligation_money($subtotal + $vatAmount);
                $obligations[] = $rentRow;
            }
        }

        $extraTax = re_obligation_tax_for_extra($lease);
        if ($separateVatPayment) {
            // Fee principal may still split across cheques; VAT is not embedded on those rows.
            $extraTax = ['treatment' => 'out_of_scope', 'rate' => 0.0];
        }
        re_obligation_add_fee_rows($obligations, $lease, $periods, 'admin_fee', 'revenue', 'Admin Fees', (float)($lease['admin_fees'] ?? 0), re_obligation_fee_distribution($lease, 'split_admin_fees', 'sep_admin_fees'), $extraTax, $startDate, $leaseId);
        re_obligation_add_fee_rows($obligations, $lease, $periods, 'commission', 'revenue', 'Commission Fees', (float)($lease['commission_fees'] ?? 0), re_obligation_fee_distribution($lease, 'split_commission_fees', 'sep_commission_fees'), $extraTax, $startDate, $leaseId);
        re_obligation_add_fee_rows($obligations, $lease, $periods, 'service', 'service', 'Chiller Fees', (float)($lease['chiller_fees'] ?? 0), re_obligation_fee_distribution($lease, 'split_chiller_fees', 'sep_chiller_fees'), $extraTax, $startDate, $leaseId);
        re_obligation_add_fee_rows($obligations, $lease, $periods, 'other', 'pass_through', 'Ejari Fees', (float)($lease['ejari_fees'] ?? 0), re_obligation_fee_distribution($lease, 'split_ejari_fees', 'sep_ejari_fees'), ['treatment' => 'out_of_scope', 'rate' => 0.0], $startDate, $leaseId, 'out_of_scope', 0.0);

        if ($separateVatTotal > 0.005) {
            $extraTaxForRate = re_obligation_tax_for_extra($lease);
            $vatRate = (float)($lease['vat_rate'] ?? 0);
            if ($vatRate <= 0) {
                $vatRate = (float)($extraTaxForRate['rate'] ?? 5.0);
            }
            if ($vatRate <= 0) {
                $vatRate = 5.0;
            }
            $vatRow = re_obligation_preview_row(
                'vat',
                'revenue',
                'Separate VAT payment',
                $startDate ?: date('Y-m-d'),
                0.0,
                'standard',
                $vatRate,
                null,
                null,
                'lease',
                $leaseId
            );
            // VAT-only document: tax fills the face amount (matches operational VAT cheque).
            $vatRow['vat_amount'] = $separateVatTotal;
            $vatRow['total_amount'] = $separateVatTotal;
            $vatRow['subtotal_amount'] = 0.0;
            $obligations[] = $vatRow;
        }

        $securityDeposit = (float)($lease['security_deposit'] ?? 0);
        if ($securityDeposit > 0 && empty($lease['is_renewal_lease'])) {
            $obligations[] = re_obligation_preview_row(
                'security_deposit',
                'liability',
                'Security Deposit Liability',
                $startDate ?: date('Y-m-d'),
                $securityDeposit,
                'out_of_scope',
                0.0,
                null,
                null,
                'lease',
                $leaseId
            );
        }

        foreach ($billingItems as $item) {
            $obligations[] = re_obligation_preview_from_billing_item($item);
        }

        foreach ($obligations as $idx => $row) {
            $obligations[$idx]['obligation_key'] = re_obligation_stable_key($leaseId, $row);
        }

        $summary = [
            'preview_subtotal' => 0.0,
            'preview_vat' => 0.0,
            'preview_total' => 0.0,
            'installment_total' => 0.0,
            'installment_vat_total' => 0.0,
            'billing_item_total' => 0.0,
            'billing_item_tax_total' => 0.0,
        ];
        foreach ($obligations as $row) {
            $summary['preview_subtotal'] += (float)$row['subtotal_amount'];
            $summary['preview_vat'] += (float)$row['vat_amount'];
            $summary['preview_total'] += (float)$row['total_amount'];
        }
        foreach ($installments as $row) {
            $summary['installment_total'] += (float)($row['amount'] ?? 0);
            $summary['installment_vat_total'] += (float)($row['vat_amount'] ?? 0);
        }
        foreach ($billingItems as $row) {
            $summary['billing_item_total'] += (float)($row['total_amount'] ?? $row['amount'] ?? 0);
            $summary['billing_item_tax_total'] += (float)($row['tax_amount'] ?? 0);
        }
        foreach ($summary as $key => $value) {
            $summary[$key] = re_obligation_money($value);
        }

        return [
            'success' => true,
            'feature_enabled' => $featureEnabled,
            'accounting_mode' => $accountingMode,
            'lease' => $lease,
            'preview_obligations' => $obligations,
            'current_installments' => $installments,
            'current_billing_items' => $billingItems,
            'summary' => $summary,
        ];
    }
}

