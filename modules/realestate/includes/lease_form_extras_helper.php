<?php
/**
 * Extra services paid upfront with the 1st installment, entered on the Add / Edit Lease form.
 *
 * Each field is saved as an Extra Service charge (re_service_charges, apply_mode
 * merge_first_installment) with one bill (re_billing_items) linked to the cheque that carries
 * the 1st-installment fees: the 1st rent cheque when fees are merged with it, the Fees cheque
 * otherwise. VAT, bills, Invoice Mode obligations and Lease View then work as for a charge
 * added from Billing / Extra Services.
 *
 * The extra rides with the 1st-installment fees: it is part of "to_first" in the schedule
 * engine (first_extras_total), so the fees cheque keeps its amount when the schedule is
 * regenerated. Extras added from Billing / Renewal into that same cheque are counted the
 * same way; merged or split extras on other cheques stay on their own cheque.
 */
declare(strict_types=1);

require_once __DIR__ . '/service_charge_schedule_helper.php';
require_once __DIR__ . '/service_charge_payment_plan_helper.php';
require_once __DIR__ . '/lease_schedule_engine.php';

if (!function_exists('re_lease_form_extra_defs')) {
    /** Form field key => charge name. Add a line here to add a field. */
    function re_lease_form_extra_defs(): array
    {
        return [
            'parking' => 'Additional Parking',
            'storage' => 'Storage',
            'pest_cleaning' => 'Pest Control & Cleaning',
        ];
    }
}

if (!function_exists('re_lease_form_extras_ready')) {
    /** True when re_service_charges can hold lease form extras (adds the column if allowed). */
    function re_lease_form_extras_ready(PDO $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            re_service_charge_ensure_apply_mode_column($conn);
            $has = (bool)$conn->query("SHOW COLUMNS FROM re_service_charges LIKE 'lease_form_key'")->fetch();
            if (!$has) {
                $conn->exec("
                    ALTER TABLE re_service_charges
                    ADD COLUMN lease_form_key VARCHAR(40) DEFAULT NULL AFTER apply_mode,
                    ADD INDEX idx_sc_lease_form_key (lease_id, lease_form_key)
                ");
                $has = (bool)$conn->query("SHOW COLUMNS FROM re_service_charges LIKE 'lease_form_key'")->fetch();
            }
            $ready = $has;
        } catch (Throwable $e) {
            error_log('re_lease_form_extras_ready: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('re_lease_form_extras_vat_rate')) {
    /** Rate used when "+ VAT" is ticked: the company's standard rate for extra services. */
    function re_lease_form_extras_vat_rate(PDO $conn): float
    {
        $vat = re_sc_resolve_vat($conn, 'standard');
        return (float)($vat['rate'] ?? 5.0) > 0 ? (float)$vat['rate'] : 5.0;
    }
}

if (!function_exists('re_lease_first_fees_installment')) {
    /**
     * The cheque that carries the 1st-installment fees: the Fees cheque (combined_fees) when
     * fees are not merged with rent, else the 1st rent cheque.
     *
     * @return array<string,mixed>|null
     */
    function re_lease_first_fees_installment(PDO $conn, int $leaseId, bool $addFeesToFirst): ?array
    {
        $stmt = $conn->prepare("
            SELECT id, installment_date, amount, installment_type, status, payment_id
            FROM re_lease_installments
            WHERE lease_id = ? AND status <> 'cancelled'
            ORDER BY installment_date ASC, id ASC
        ");
        $stmt->execute([$leaseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$addFeesToFirst) {
            foreach ($rows as $row) {
                if ((string)($row['installment_type'] ?? '') === 'combined_fees') {
                    return $row;
                }
            }
        }
        foreach ($rows as $row) {
            if (in_array((string)($row['installment_type'] ?? ''), ['', 'rent'], true)) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('re_lease_merged_extras')) {
    /**
     * Extra service bills merged or split into this lease's cheques, split into:
     *  - first: merged-into-1st bills on the 1st-fees cheque (plus, with $includeOrphans, bills
     *    whose cheque row was deleted by an earlier rebuild; the next save puts them back on it)
     *  - by_installment: every other merged/split bill, by the cheque it is on
     *
     * @return array{first_total:float,first_items:list<array<string,mixed>>,by_installment:array<int,float>,other_total:float}
     */
    function re_lease_merged_extras(
        PDO $conn,
        int $companyId,
        int $leaseId,
        bool $addFeesToFirst,
        bool $includeFormExtras,
        bool $includeOrphans
    ): array {
        $out = ['first_total' => 0.0, 'first_items' => [], 'by_installment' => [], 'other_total' => 0.0];
        if ($leaseId <= 0 || !re_service_charge_table_has_column($conn, 'apply_mode')) {
            return $out;
        }
        $hasFormKey = re_service_charge_table_has_column($conn, 'lease_form_key');
        $formKeySql = $hasFormKey ? 'sc.lease_form_key' : 'NULL';
        $formFilter = (!$includeFormExtras && $hasFormKey) ? 'AND sc.lease_form_key IS NULL' : '';

        $stmt = $conn->prepare("
            SELECT bi.id AS bill_id, bi.total_amount, sc.charge_name, sc.apply_mode,
                   $formKeySql AS lease_form_key, li.id AS li_id, li.status AS li_status
            FROM re_billing_items bi
            JOIN re_service_charges sc ON sc.id = bi.service_charge_id AND sc.company_id = bi.company_id
            LEFT JOIN re_lease_installments li ON li.id = bi.installment_id AND li.lease_id = bi.lease_id
            WHERE bi.company_id = ? AND bi.lease_id = ?
              AND bi.installment_id IS NOT NULL
              AND sc.is_active = 1
              AND sc.apply_mode IN ('merge_first_installment', 'split_all_installments')
              $formFilter
            ORDER BY bi.id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$bills) {
            return $out;
        }

        $target = re_lease_first_fees_installment($conn, $leaseId, $addFeesToFirst);
        $targetId = $target ? (int)$target['id'] : 0;

        foreach ($bills as $bill) {
            $gross = round((float)$bill['total_amount'], 2);
            $liId = (int)($bill['li_id'] ?? 0);
            if ($liId > 0 && (string)$bill['li_status'] === 'cancelled') {
                continue;
            }
            $orphan = $liId === 0;
            if ($orphan && !$includeOrphans) {
                continue;
            }
            if ($orphan || ($bill['apply_mode'] === 'merge_first_installment' && $liId === $targetId)) {
                $out['first_total'] += $gross;
                $out['first_items'][] = [
                    'bill_id' => (int)$bill['bill_id'],
                    'name' => (string)$bill['charge_name'],
                    'gross' => $gross,
                    'orphan' => $orphan,
                    'lease_form_key' => $bill['lease_form_key'],
                ];
                continue;
            }
            $out['by_installment'][$liId] = round(($out['by_installment'][$liId] ?? 0.0) + $gross, 2);
            $out['other_total'] += $gross;
        }
        $out['first_total'] = round($out['first_total'], 2);
        $out['other_total'] = round($out['other_total'], 2);
        return $out;
    }
}

if (!function_exists('re_lease_first_extras_total')) {
    /** Extras on the 1st-fees cheque, as stored: the first_extras_total input of lease_compute_totals(). */
    function re_lease_first_extras_total(PDO $conn, int $companyId, int $leaseId, bool $addFeesToFirst): float
    {
        try {
            return re_lease_merged_extras($conn, $companyId, $leaseId, $addFeesToFirst, true, false)['first_total'];
        } catch (Throwable $e) {
            error_log('re_lease_first_extras_total: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('re_lease_form_extra_bill_locked')) {
    /** A bill that is paid, on a collected cheque, or invoiced / allocated in Invoice Mode is never changed here. */
    function re_lease_form_extra_bill_locked(PDO $conn, int $companyId, int $leaseId, array $bill): bool
    {
        if (empty($bill['bill_id'])) {
            return false;
        }
        if ((string)($bill['bill_status'] ?? '') === 'paid'
            || !empty($bill['payment_id'])
            || !empty($bill['is_paid'])
            || (float)($bill['paid_amount'] ?? 0) > 0.005) {
            return true;
        }
        if (!empty($bill['installment_id'])) {
            $locked = lease_locked_installment_ids($conn, $leaseId, $companyId);
            if (isset($locked[(int)$bill['installment_id']])) {
                return true;
            }
        }
        try {
            require_once __DIR__ . '/obligation_engine.php';
            $ob = $conn->prepare("
                SELECT * FROM re_obligations
                WHERE company_id = ? AND lease_id = ? AND source_type = 'billing_item' AND source_id = ?
            ");
            $ob->execute([$companyId, $leaseId, (int)$bill['bill_id']]);
            foreach ($ob->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if (function_exists('re_obligation_engine_is_protected') && re_obligation_engine_is_protected($row, $conn)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            // No obligations table (Legacy-only database): nothing to protect there.
        }
        return false;
    }
}

if (!function_exists('re_lease_form_extras_load')) {
    /**
     * Stored lease form extras, by field key.
     *
     * @return array<string,array<string,mixed>>
     */
    function re_lease_form_extras_load(PDO $conn, int $companyId, int $leaseId): array
    {
        if ($leaseId <= 0 || !re_lease_form_extras_ready($conn)) {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT sc.id AS charge_id, sc.lease_form_key, sc.amount, sc.vat_treatment, sc.vat_rate,
                   bi.id AS bill_id, bi.installment_id, bi.tax_amount, bi.total_amount,
                   bi.status AS bill_status, bi.payment_id, bi.is_paid, bi.paid_amount
            FROM re_service_charges sc
            LEFT JOIN re_billing_items bi ON bi.service_charge_id = sc.id AND bi.company_id = sc.company_id
            WHERE sc.company_id = ? AND sc.lease_id = ? AND sc.is_active = 1 AND sc.lease_form_key IS NOT NULL
            ORDER BY sc.id DESC, bi.id DESC
        ");
        $stmt->execute([$companyId, $leaseId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (string)$row['lease_form_key'];
            if (isset($out[$key])) {
                continue;
            }
            $net = round((float)$row['amount'], 2);
            $vatOn = (string)$row['vat_treatment'] === 'standard' && (float)$row['vat_rate'] > 0;
            $vat = $row['bill_id'] ? round((float)$row['tax_amount'], 2) : ($vatOn ? round($net * (float)$row['vat_rate'] / 100, 2) : 0.0);
            $out[$key] = [
                'charge_id' => (int)$row['charge_id'],
                'bill_id' => $row['bill_id'] ? (int)$row['bill_id'] : null,
                'installment_id' => $row['installment_id'] ? (int)$row['installment_id'] : null,
                'net' => $net,
                'vat_on' => $vatOn,
                'vat_rate' => $vatOn ? (float)$row['vat_rate'] : 0.0,
                'vat' => $vat,
                'gross' => round($net + $vat, 2),
                'locked' => re_lease_form_extra_bill_locked($conn, $companyId, $leaseId, $row),
            ];
        }
        return $out;
    }
}

if (!function_exists('re_lease_form_extras_from_post')) {
    /**
     * Posted extras, normalised. A locked extra keeps its stored values whatever was posted.
     *
     * @return array{items:array<string,array<string,mixed>>,total:float}
     */
    function re_lease_form_extras_from_post(array $post, array $stored, float $vatRate): array
    {
        $posted = is_array($post['lease_extra'] ?? null) ? $post['lease_extra'] : [];
        $items = [];
        $total = 0.0;
        foreach (re_lease_form_extra_defs() as $key => $name) {
            if (!empty($stored[$key]['locked'])) {
                $items[$key] = $stored[$key];
            } else {
                $net = round(max(0.0, (float)($posted[$key]['amount'] ?? 0)), 2);
                $vatOn = $net > 0 && !empty($posted[$key]['vat']);
                $vat = $vatOn ? round($net * $vatRate / 100, 2) : 0.0;
                $items[$key] = [
                    'net' => $net,
                    'vat_on' => $vatOn,
                    'vat_rate' => $vatOn ? $vatRate : 0.0,
                    'vat' => $vat,
                    'gross' => round($net + $vat, 2),
                    'locked' => false,
                ];
            }
            $items[$key]['name'] = $name;
            $total += (float)$items[$key]['gross'];
        }
        return ['items' => $items, 'total' => round($total, 2)];
    }
}

if (!function_exists('re_lease_form_extras_sync')) {
    /**
     * After the schedule is saved: make the charges and bills match the form, link them to the
     * 1st-fees cheque, and move the other extras that ride with the 1st-installment fees (and
     * bills whose cheque row is gone) onto it too. Cheque amounts are not changed here: the
     * posted schedule already carries the extras.
     *
     * @param array<string,array<string,mixed>> $items from re_lease_form_extras_from_post()
     * @param list<int> $relinkBillIds first_items bill ids taken before the save
     */
    function re_lease_form_extras_sync(
        PDO $conn,
        int $companyId,
        int $leaseId,
        array $items,
        array $relinkBillIds,
        bool $addFeesToFirst,
        array $leaseInfo
    ): void {
        if (!re_lease_form_extras_ready($conn)) {
            return;
        }
        $target = re_lease_first_fees_installment($conn, $leaseId, $addFeesToFirst);
        $stored = re_lease_form_extras_load($conn, $companyId, $leaseId);

        $needsTarget = $relinkBillIds !== [];
        foreach ($items as $key => $item) {
            if (empty($item['locked']) && (float)$item['gross'] > 0) {
                $needsTarget = true;
            }
        }
        if (!$target) {
            if ($needsTarget) {
                throw new RuntimeException('Extra services could not be added: this lease has no 1st installment to add them to.');
            }
            return;
        }
        $targetId = (int)$target['id'];
        $targetDate = (string)$target['installment_date'];

        $optionalCols = [];
        foreach (['contracted_amount', 'lifecycle_status', 'vat_treatment', 'vat_rate', 'amount_basis', 'expected_payment_method', 'cancelled_effective_date'] as $col) {
            $optionalCols[$col] = re_service_charge_table_has_column($conn, $col);
        }

        foreach (re_lease_form_extra_defs() as $key => $name) {
            $item = $items[$key] ?? ['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0, 'vat_on' => false, 'vat_rate' => 0.0];
            $st = $stored[$key] ?? null;
            if ($st && !empty($st['locked'])) {
                continue;
            }

            if ((float)$item['gross'] <= 0) {
                if ($st) {
                    $conn->prepare("
                        DELETE FROM re_billing_items
                        WHERE company_id = ? AND lease_id = ? AND service_charge_id = ?
                          AND payment_id IS NULL AND status IN ('pending', 'overdue')
                    ")->execute([$companyId, $leaseId, (int)$st['charge_id']]);
                    $set = 'is_active = 0, updated_at = NOW()';
                    if ($optionalCols['lifecycle_status']) {
                        $set .= ", lifecycle_status = 'cancelled'";
                    }
                    if ($optionalCols['cancelled_effective_date']) {
                        $set .= ', cancelled_effective_date = CURDATE()';
                    }
                    $conn->prepare("UPDATE re_service_charges SET $set WHERE id = ? AND company_id = ?")
                        ->execute([(int)$st['charge_id'], $companyId]);
                }
                continue;
            }

            $net = (float)$item['net'];
            $vatAmount = (float)$item['vat'];
            $gross = (float)$item['gross'];
            $vatRate = !empty($item['vat_on']) ? (float)$item['vat_rate'] : 0.0;
            $charge = [
                'charge_name' => $name,
                'charge_description' => 'Lease form: paid with the 1st installment',
                'amount' => $net,
                'charge_type' => 'fixed',
                'is_recurring' => 0,
                'recurrence_type' => 'one_time',
                'apply_mode' => 'merge_first_installment',
                'start_date' => (string)$leaseInfo['start_date'],
                'end_date' => $leaseInfo['end_date'] ?: null,
                'contracted_amount' => $net,
                'lifecycle_status' => 'active',
                'vat_treatment' => !empty($item['vat_on']) ? 'standard' : 'exempt',
                'vat_rate' => $vatRate,
                'amount_basis' => 'vat_exclusive',
                'expected_payment_method' => (string)($leaseInfo['payment_method'] ?? 'cheque'),
            ];
            $cols = ['charge_name', 'charge_description', 'amount', 'charge_type', 'is_recurring', 'recurrence_type', 'apply_mode', 'start_date', 'end_date'];
            foreach (['contracted_amount', 'lifecycle_status', 'vat_treatment', 'vat_rate', 'amount_basis', 'expected_payment_method'] as $col) {
                if ($optionalCols[$col]) {
                    $cols[] = $col;
                }
            }

            if ($st) {
                $chargeId = (int)$st['charge_id'];
                $set = implode(', ', array_map(static fn($c) => "$c = ?", $cols));
                $conn->prepare("UPDATE re_service_charges SET $set, updated_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute(array_merge(array_map(static fn($c) => $charge[$c], $cols), [$chargeId, $companyId]));
            } else {
                $insCols = array_merge(['company_id', 'lease_id', 'lease_form_key', 'is_active'], $cols);
                $values = array_merge([$companyId, $leaseId, $key, 1], array_map(static fn($c) => $charge[$c], $cols));
                $conn->prepare('INSERT INTO re_service_charges (' . implode(', ', $insCols) . ') VALUES (' . implode(', ', array_fill(0, count($insCols), '?')) . ')')
                    ->execute($values);
                $chargeId = (int)$conn->lastInsertId();
            }

            $billId = $st['bill_id'] ?? null;
            if ($billId) {
                $conn->prepare("
                    UPDATE re_billing_items
                    SET item_name = ?, amount = ?, unit_price = ?, tax_rate = ?, tax_amount = ?, total_amount = ?,
                        billing_period_start = ?, billing_period_end = ?, billing_date = ?, due_date = ?,
                        installment_id = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ? AND lease_id = ?
                ")->execute([
                    $name, $net, $net, $vatRate, $vatAmount, $gross,
                    $targetDate, $targetDate, $targetDate, $targetDate,
                    $targetId, (int)$billId, $companyId, $leaseId,
                ]);
            } else {
                $conn->prepare("
                    INSERT INTO re_billing_items
                        (company_id, lease_id, item_type, item_name, item_description, amount,
                         quantity, unit_price, tax_rate, tax_amount, total_amount, billing_period_start, billing_period_end,
                         billing_date, due_date, service_charge_id, installment_id, status, notes, created_by)
                    VALUES (?, ?, 'service_charge', ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
                ")->execute([
                    $companyId, $leaseId, $name, 'Paid with the 1st installment', $net,
                    $net, $vatRate, $vatAmount, $gross,
                    $targetDate, $targetDate, $targetDate, $targetDate,
                    $chargeId, $targetId, 'Added on the lease form', $leaseInfo['user_id'] ?? null,
                ]);
            }
        }

        // Extras from Billing / Renewal that ride with the 1st-installment fees, and any merged or
        // split bill whose cheque row is gone: the schedule posted from the form carries them on
        // the 1st-fees cheque, so the bill follows.
        $relinkBillIds = array_values(array_filter(array_map('intval', $relinkBillIds)));
        $orphans = $conn->prepare("
            SELECT bi.id
            FROM re_billing_items bi
            JOIN re_service_charges sc ON sc.id = bi.service_charge_id AND sc.company_id = bi.company_id
            LEFT JOIN re_lease_installments li ON li.id = bi.installment_id AND li.lease_id = bi.lease_id
            WHERE bi.company_id = ? AND bi.lease_id = ? AND bi.installment_id IS NOT NULL AND li.id IS NULL
              AND sc.is_active = 1 AND sc.apply_mode IN ('merge_first_installment', 'split_all_installments')
        ");
        $orphans->execute([$companyId, $leaseId]);
        $relinkBillIds = array_values(array_unique(array_merge($relinkBillIds, array_map('intval', $orphans->fetchAll(PDO::FETCH_COLUMN) ?: []))));
        if ($relinkBillIds) {
            $ph = implode(',', array_fill(0, count($relinkBillIds), '?'));
            $conn->prepare("
                UPDATE re_billing_items
                SET installment_id = ?, updated_at = NOW()
                WHERE company_id = ? AND lease_id = ? AND id IN ($ph)
                  AND payment_id IS NULL AND status IN ('pending', 'overdue')
            ")->execute(array_merge([$targetId, $companyId, $leaseId], $relinkBillIds));
        }
    }
}
