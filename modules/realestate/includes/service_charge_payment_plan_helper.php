<?php
/**
 * Extra Service Charge — VAT resolve, payment plans (collection only), lifecycle helpers.
 * Does NOT replace monthly billing-item revenue recognition.
 */
declare(strict_types=1);

require_once __DIR__ . '/service_charge_schedule_helper.php';

if (!function_exists('re_sc_money')) {
    function re_sc_money($n): float
    {
        return round((float)$n, 2);
    }
}

if (!function_exists('re_sc_setting')) {
    function re_sc_setting(PDO $conn, string $key, string $default = ''): string
    {
        try {
            $st = $conn->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('re_sc_payment_plan_table_ready')) {
    function re_sc_payment_plan_table_ready(PDO $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $conn->query('SELECT 1 FROM re_service_charge_payment_plans LIMIT 1');
            $conn->query('SELECT 1 FROM re_service_charge_payment_plan_lines LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

/**
 * Resolve VAT treatment/rate and snapshot values (never rewrite history later).
 * @return array{treatment:string,rate:float,tax_label:string}
 */
if (!function_exists('re_sc_resolve_vat')) {
    function re_sc_resolve_vat(PDO $conn, string $treatment, ?float $rateHint = null): array
    {
        $treatment = strtolower(trim($treatment));
        if (!in_array($treatment, ['standard', 'zero_rated', 'exempt', 'out_of_scope', 'company_default'], true)) {
            $treatment = 'company_default';
        }
        if ($treatment === 'company_default') {
            $treatment = re_sc_setting($conn, 're_service_charge_company_vat_default_treatment', 'exempt');
            if (!in_array($treatment, ['standard', 'zero_rated', 'exempt', 'out_of_scope'], true)) {
                $treatment = 'exempt';
            }
            $rateHint = (float)re_sc_setting($conn, 're_service_charge_company_vat_default_rate', '0');
        }

        $rate = 0.0;
        if ($treatment === 'standard') {
            $rate = $rateHint !== null && $rateHint > 0
                ? re_sc_money($rateHint)
                : re_sc_money((float)re_sc_setting($conn, 're_service_charge_company_vat_default_rate', '5'));
            if ($rate <= 0) {
                $rate = 5.0; // UAE standard fallback only when company default rate missing
            }
        }

        return [
            'treatment' => $treatment,
            'rate' => $rate,
            'tax_label' => $treatment,
        ];
    }
}

if (!function_exists('re_sc_tax_on_net')) {
    function re_sc_tax_on_net(float $net, float $rate): float
    {
        if ($rate <= 0) {
            return 0.0;
        }
        return re_sc_money($net * ($rate / 100));
    }
}

if (!function_exists('re_sc_normalize_amount_basis')) {
    function re_sc_normalize_amount_basis(string $basis): string
    {
        $basis = strtolower(trim($basis));
        return $basis === 'vat_inclusive' ? 'vat_inclusive' : 'vat_exclusive';
    }
}

/**
 * Derive net agreement total from contracted face value + amount basis + VAT snapshot.
 * Exclusive: contracted is net. Inclusive + standard: contracted is gross → net = gross/(1+r).
 */
if (!function_exists('re_sc_net_from_contract')) {
    function re_sc_net_from_contract(
        float $contracted,
        string $amountBasis,
        string $vatTreatment,
        float $vatRate
    ): float {
        $contracted = re_sc_money($contracted);
        if ($contracted <= 0) {
            return 0.0;
        }
        $basis = re_sc_normalize_amount_basis($amountBasis);
        $treatment = strtolower(trim($vatTreatment));
        $rate = re_sc_money($vatRate);
        if ($basis === 'vat_inclusive' && $treatment === 'standard' && $rate > 0.005) {
            return re_sc_money($contracted / (1 + ($rate / 100)));
        }
        return $contracted;
    }
}

/**
 * Split equal amounts across N periods; last row absorbs fils remainder.
 * @return list<float>
 */
if (!function_exists('re_sc_split_equal_amounts')) {
    function re_sc_split_equal_amounts(float $total, int $count): array
    {
        $total = re_sc_money($total);
        $count = max(1, $count);
        if ($count === 1) {
            return [$total];
        }
        $base = floor(($total / $count) * 100) / 100;
        $amounts = array_fill(0, $count, $base);
        $amounts[$count - 1] = re_sc_money($total - ($base * ($count - 1)));
        return $amounts;
    }
}

/**
 * Build per-period net and tax arrays from contracted face + basis + VAT snapshot.
 * Inclusive standard: sum(net)+sum(tax) === contracted (gross). Exclusive: sum(net)===contracted.
 *
 * @return array{net_total:float,tax_total:float,period_nets:list<float>,period_taxes:list<float>}
 */
if (!function_exists('re_sc_period_amounts_from_contract')) {
    function re_sc_period_amounts_from_contract(
        float $contracted,
        string $amountBasis,
        string $vatTreatment,
        float $vatRate,
        int $periodCount
    ): array {
        $contracted = re_sc_money($contracted);
        $periodCount = max(1, $periodCount);
        $basis = re_sc_normalize_amount_basis($amountBasis);
        $treatment = strtolower(trim($vatTreatment));
        $rate = ($treatment === 'standard') ? re_sc_money($vatRate) : 0.0;

        $netTotal = re_sc_net_from_contract($contracted, $basis, $treatment, $rate);
        if ($basis === 'vat_inclusive' && $treatment === 'standard' && $rate > 0.005) {
            $taxTotal = re_sc_money($contracted - $netTotal);
        } else {
            $taxTotal = re_sc_tax_on_net($netTotal, $rate);
        }

        return [
            'net_total' => $netTotal,
            'tax_total' => $taxTotal,
            'period_nets' => re_sc_split_equal_amounts($netTotal, $periodCount),
            'period_taxes' => re_sc_split_equal_amounts($taxTotal, $periodCount),
        ];
    }
}

/**
 * Collectible payment-plan total must cover invoiced amounts (net + VAT).
 * - Exclusive + standard: contracted net + VAT
 * - Inclusive + standard: contracted is already gross
 * - Non-standard VAT: contracted face (no tax to collect)
 */
if (!function_exists('re_sc_payment_plan_total_from_contract')) {
    function re_sc_payment_plan_total_from_contract(
        float $contractedAmount,
        string $amountBasis = 'vat_exclusive',
        string $vatTreatment = 'exempt',
        float $vatRate = 0.0
    ): float {
        $parts = re_sc_period_amounts_from_contract(
            $contractedAmount,
            $amountBasis,
            $vatTreatment,
            $vatRate,
            1
        );
        return re_sc_money((float)$parts['net_total'] + (float)$parts['tax_total']);
    }
}

/**
 * Build equal collection lines (payment plan only).
 * @return list<array{line_no:int,due_date:string,amount:float}>
 */
if (!function_exists('re_sc_build_equal_payment_plan')) {
    function re_sc_build_equal_payment_plan(
        float $contracted,
        int $count,
        string $firstDue,
        ?string $lastDue = null
    ): array {
        $contracted = re_sc_money($contracted);
        $count = max(1, $count);
        if ($contracted <= 0) {
            return [];
        }
        $base = floor(($contracted / $count) * 100) / 100;
        $amounts = array_fill(0, $count, $base);
        $amounts[$count - 1] = re_sc_money($contracted - ($base * ($count - 1)));

        $start = new DateTime($firstDue ?: date('Y-m-d'));
        $end = $lastDue ? new DateTime($lastDue) : (clone $start)->modify('+' . max(0, $count - 1) . ' months');
        if ($end < $start) {
            $end = clone $start;
        }
        $spanDays = max(0, (int)$start->diff($end)->days);
        $step = $count > 1 ? ($spanDays / ($count - 1)) : 0;

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $due = clone $start;
            if ($i > 0 && $step > 0) {
                $due->modify('+' . (int)round($step * $i) . ' days');
            }
            $out[] = [
                'line_no' => $i + 1,
                'due_date' => $due->format('Y-m-d'),
                'amount' => (float)$amounts[$i],
            ];
        }
        return $out;
    }
}

/**
 * @param list<array{due_date?:string,amount?:float|string,line_no?:int}> $lines
 */
if (!function_exists('re_sc_validate_payment_plan')) {
    function re_sc_validate_payment_plan(float $contracted, array $lines): array
    {
        $contracted = re_sc_money($contracted);
        if ($contracted <= 0.005) {
            return ['success' => false, 'error' => 'Contracted amount must be greater than zero.', 'sum' => 0.0];
        }
        if ($lines === []) {
            return ['success' => false, 'error' => 'Payment plan requires at least one line.', 'sum' => 0.0];
        }
        $sum = 0.0;
        foreach ($lines as $i => $line) {
            $amt = re_sc_money($line['amount'] ?? 0);
            $due = trim((string)($line['due_date'] ?? ''));
            if ($amt <= 0.005) {
                return ['success' => false, 'error' => 'Line ' . ($i + 1) . ' amount must be greater than zero.', 'sum' => $sum];
            }
            if ($due === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                return ['success' => false, 'error' => 'Line ' . ($i + 1) . ' needs a valid due date.', 'sum' => $sum];
            }
            $sum = re_sc_money($sum + $amt);
        }
        if (abs($sum - $contracted) > 0.005) {
            return [
                'success' => false,
                'error' => 'Payment plan total (' . number_format($sum, 2)
                    . ') must equal collectible total including VAT when applicable ('
                    . number_format($contracted, 2) . ').',
                'sum' => $sum,
            ];
        }
        return ['success' => true, 'error' => null, 'sum' => $sum];
    }
}

/**
 * Save payment plan for a service charge. Does not create billing items or PDCs.
 * @param list<array{due_date:string,amount:float|string,notes?:string}> $lines
 */
if (!function_exists('re_sc_save_payment_plan')) {
    function re_sc_save_payment_plan(
        PDO $conn,
        int $companyId,
        int $serviceChargeId,
        int $leaseId,
        float $plannedTotal,
        array $lines,
        ?string $expectedMethod = null,
        ?int $userId = null
    ): array {
        if ($companyId <= 0) {
            return ['success' => false, 'error' => 'Company context is required.', 'plan_id' => null];
        }
        if (!re_sc_payment_plan_table_ready($conn)) {
            return ['success' => false, 'error' => 'Payment plan schema not installed.', 'plan_id' => null];
        }
        $v = re_sc_validate_payment_plan($plannedTotal, $lines);
        if (empty($v['success'])) {
            return ['success' => false, 'error' => $v['error'], 'plan_id' => null];
        }

        $ownTx = !$conn->inTransaction();
        try {
            if ($ownTx) {
                $conn->beginTransaction();
            }
            $chk = $conn->prepare("
                SELECT id, lease_id FROM re_service_charges
                WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE
            ");
            $chk->execute([$serviceChargeId, $companyId]);
            $sc = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$sc || (int)$sc['lease_id'] !== $leaseId) {
                throw new RuntimeException('Service charge not found for this lease.');
            }

            // Cancel prior active plans (keep history rows)
            $conn->prepare("
                UPDATE re_service_charge_payment_plans
                SET status = 'cancelled', updated_at = NOW()
                WHERE company_id = ? AND service_charge_id = ? AND status IN ('draft','active')
            ")->execute([$companyId, $serviceChargeId]);

            $conn->prepare("
                INSERT INTO re_service_charge_payment_plans
                (company_id, service_charge_id, lease_id, status, planned_total, expected_method, created_by)
                VALUES (?,?,?,'active',?,?,?)
            ")->execute([
                $companyId,
                $serviceChargeId,
                $leaseId,
                re_sc_money($plannedTotal),
                $expectedMethod !== null && $expectedMethod !== '' ? $expectedMethod : null,
                $userId,
            ]);
            $planId = (int)$conn->lastInsertId();
            if ($planId <= 0) {
                throw new RuntimeException('Failed to create payment plan.');
            }

            $ins = $conn->prepare("
                INSERT INTO re_service_charge_payment_plan_lines
                (company_id, plan_id, line_no, due_date, amount, status, notes)
                VALUES (?,?,?,?,?,'planned',?)
            ");
            $lineNo = 0;
            foreach ($lines as $line) {
                $lineNo++;
                $ins->execute([
                    $companyId,
                    $planId,
                    $lineNo,
                    (string)$line['due_date'],
                    re_sc_money($line['amount'] ?? 0),
                    isset($line['notes']) ? (string)$line['notes'] : null,
                ]);
            }

            if ($ownTx) {
                $conn->commit();
            }

            if (is_file(__DIR__ . '/../../../includes/AuditService.php')) {
                require_once __DIR__ . '/../../../includes/AuditService.php';
                if (class_exists('AuditService')) {
                    AuditService::logEvent([
                        'action' => 'service_charge_payment_plan_saved',
                        'action_label' => 'Saved service charge payment plan',
                        'module' => 'realestate',
                        'company_id' => $companyId,
                        'object_type' => 're_service_charge_payment_plans',
                        'object_id' => (string)$planId,
                        'object_ref' => 'SC#' . $serviceChargeId . ' plan #' . $planId,
                        'summary' => 'Payment plan ' . $lineNo . ' line(s), total ' . number_format($plannedTotal, 2),
                        'source' => 'user',
                        'success' => true,
                    ]);
                }
            }

            return ['success' => true, 'error' => null, 'plan_id' => $planId, 'line_count' => $lineNo];
        } catch (Throwable $e) {
            if ($ownTx && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage(), 'plan_id' => null];
        }
    }
}

if (!function_exists('re_sc_load_active_payment_plan')) {
    function re_sc_load_active_payment_plan(PDO $conn, int $companyId, int $serviceChargeId): ?array
    {
        if (!re_sc_payment_plan_table_ready($conn)) {
            return null;
        }
        $st = $conn->prepare("
            SELECT * FROM re_service_charge_payment_plans
            WHERE company_id = ? AND service_charge_id = ? AND status = 'active'
            ORDER BY id DESC LIMIT 1
        ");
        $st->execute([$companyId, $serviceChargeId]);
        $plan = $st->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            return null;
        }
        $lines = $conn->prepare("
            SELECT * FROM re_service_charge_payment_plan_lines
            WHERE company_id = ? AND plan_id = ?
            ORDER BY line_no ASC, id ASC
        ");
        $lines->execute([$companyId, (int)$plan['id']]);
        $plan['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $plan;
    }
}

/**
 * Register a service PDC linked to a payment-plan line. Never sets installment_id (rent-safe).
 */
if (!function_exists('re_sc_register_service_cheque')) {
    function re_sc_register_service_cheque(
        PDO $conn,
        int $companyId,
        int $planLineId,
        array $cheque,
        ?int $userId = null
    ): array {
        if ($companyId <= 0) {
            return ['success' => false, 'error' => 'Company context is required.', 'cheque_id' => null];
        }
        if (!re_sc_payment_plan_table_ready($conn)) {
            return ['success' => false, 'error' => 'Payment plan schema not installed.', 'cheque_id' => null];
        }
        $ownTx = !$conn->inTransaction();
        try {
            if ($ownTx) {
                $conn->beginTransaction();
            }
            $st = $conn->prepare("
                SELECT l.*, p.lease_id, p.service_charge_id, p.status AS plan_status
                FROM re_service_charge_payment_plan_lines l
                JOIN re_service_charge_payment_plans p ON p.id = l.plan_id AND p.company_id = l.company_id
                WHERE l.id = ? AND l.company_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $st->execute([$planLineId, $companyId]);
            $line = $st->fetch(PDO::FETCH_ASSOC);
            if (!$line || ($line['plan_status'] ?? '') !== 'active') {
                throw new RuntimeException('Payment plan line not found or plan not active.');
            }
            if (!empty($line['cheque_id'])) {
                throw new RuntimeException('A cheque is already registered on this plan line.');
            }

            $chequeNumber = trim((string)($cheque['cheque_number'] ?? ''));
            $amount = re_sc_money($cheque['cheque_amount'] ?? $line['amount']);
            $chequeDate = (string)($cheque['cheque_date'] ?? $line['due_date']);
            if ($chequeNumber === '' || $amount <= 0.005) {
                throw new RuntimeException('Cheque number and amount are required.');
            }
            if (abs($amount - re_sc_money($line['amount'])) > 0.005) {
                throw new RuntimeException('Cheque amount must match the payment plan line amount.');
            }

            $hasPlanCol = false;
            try {
                $c = $conn->query("SHOW COLUMNS FROM re_post_dated_cheques LIKE 'service_payment_plan_line_id'");
                $hasPlanCol = (bool)$c->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $hasPlanCol = false;
            }

            if ($hasPlanCol) {
                $conn->prepare("
                    INSERT INTO re_post_dated_cheques
                    (company_id, lease_id, cheque_number, bank_name, account_holder_name, payment_method,
                     cheque_amount, cheque_date, status, billing_item_id, installment_id,
                     service_payment_plan_line_id, notes, created_by)
                    VALUES (?,?,?,?,?, 'cheque', ?, ?, 'pending', NULL, NULL, ?, ?, ?)
                ")->execute([
                    $companyId,
                    (int)$line['lease_id'],
                    $chequeNumber,
                    $cheque['bank_name'] ?? null,
                    $cheque['account_holder_name'] ?? null,
                    $amount,
                    $chequeDate,
                    $planLineId,
                    $cheque['notes'] ?? ('Service charge payment plan line #' . $planLineId),
                    $userId,
                ]);
            } else {
                $conn->prepare("
                    INSERT INTO re_post_dated_cheques
                    (company_id, lease_id, cheque_number, bank_name, account_holder_name, payment_method,
                     cheque_amount, cheque_date, status, billing_item_id, installment_id, notes, created_by)
                    VALUES (?,?,?,?,?, 'cheque', ?, ?, 'pending', NULL, NULL, ?, ?)
                ")->execute([
                    $companyId,
                    (int)$line['lease_id'],
                    $chequeNumber,
                    $cheque['bank_name'] ?? null,
                    $cheque['account_holder_name'] ?? null,
                    $amount,
                    $chequeDate,
                    $cheque['notes'] ?? ('Service charge payment plan line #' . $planLineId),
                    $userId,
                ]);
            }
            $chequeId = (int)$conn->lastInsertId();
            $conn->prepare("
                UPDATE re_service_charge_payment_plan_lines
                SET status = 'registered', cheque_id = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$chequeId, $planLineId, $companyId]);

            if ($ownTx) {
                $conn->commit();
            }
            return ['success' => true, 'error' => null, 'cheque_id' => $chequeId];
        } catch (Throwable $e) {
            if ($ownTx && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage(), 'cheque_id' => null];
        }
    }
}

/**
 * Suspend or cancel service charge billing.
 * - Suspend: waive future unpaid schedule rows only (past remain collectible).
 * - Cancel: clear all unpaid related schedule/obligations/invoices/candidates/plans
 *   so the lease can receive a clean re-apply (fails closed if any Extra SC invoice is paid).
 */
if (!function_exists('re_sc_stop_future_billing')) {
    function re_sc_stop_future_billing(
        PDO $conn,
        int $companyId,
        int $serviceChargeId,
        int $leaseId,
        string $lifecycleStatus,
        string $effectiveDate,
        ?int $userId = null,
        string $reason = ''
    ): array {
        if (!in_array($lifecycleStatus, ['suspended', 'cancelled'], true)) {
            return ['success' => false, 'error' => 'Invalid lifecycle status.'];
        }
        if ($effectiveDate === '') {
            $effectiveDate = date('Y-m-d');
        }
        $ownTx = !$conn->inTransaction();
        try {
            if ($ownTx) {
                $conn->beginTransaction();
            }
            $st = $conn->prepare("SELECT * FROM re_service_charges WHERE id = ? AND company_id = ? AND lease_id = ? FOR UPDATE");
            $st->execute([$serviceChargeId, $companyId, $leaseId]);
            $sc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$sc) {
                throw new RuntimeException('Service charge not found.');
            }

            $dateCol = $lifecycleStatus === 'suspended' ? 'suspended_effective_date' : 'cancelled_effective_date';
            // Legacy is_active: active|suspended → 1; cancelled (and peers) → 0
            $isActive = $lifecycleStatus === 'suspended' ? 1 : 0;
            if (re_service_charge_table_has_column($conn, 'lifecycle_status')) {
                $conn->prepare("
                    UPDATE re_service_charges
                    SET lifecycle_status = ?, is_active = ?, {$dateCol} = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ")->execute([$lifecycleStatus, $isActive, $effectiveDate, $serviceChargeId, $companyId]);
            } else {
                $conn->prepare("UPDATE re_service_charges SET is_active = 0 WHERE id = ? AND company_id = ?")
                    ->execute([$serviceChargeId, $companyId]);
            }

            if ($lifecycleStatus === 'cancelled') {
                $clear = re_sc_clear_unpaid_accounting(
                    $conn,
                    $companyId,
                    $serviceChargeId,
                    $leaseId,
                    $effectiveDate,
                    $userId,
                    $reason !== '' ? $reason : ('Cancelled effective ' . $effectiveDate)
                );
                if (empty($clear['success'])) {
                    throw new RuntimeException($clear['error'] ?? 'Could not clear service charge accounting.');
                }
            } else {
                $conn->prepare("
                    UPDATE re_billing_items
                    SET status = 'waived',
                        notes = CONCAT(COALESCE(notes, ''), ' | Service ', ?, ' effective ', ?)
                    WHERE company_id = ?
                      AND lease_id = ?
                      AND service_charge_id = ?
                      AND item_type = 'service_charge'
                      AND is_paid = 0
                      AND status IN ('pending', 'overdue')
                      AND due_date >= ?
                ")->execute([$lifecycleStatus, $effectiveDate, $companyId, $leaseId, $serviceChargeId, $effectiveDate]);
            }

            if (re_sc_payment_plan_table_ready($conn)) {
                $plans = $conn->prepare("
                    SELECT id FROM re_service_charge_payment_plans
                    WHERE company_id = ? AND service_charge_id = ? AND status IN ('draft','active')
                ");
                $plans->execute([$companyId, $serviceChargeId]);
                $planIds = array_map('intval', $plans->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if ($planIds) {
                    $ph = implode(',', array_fill(0, count($planIds), '?'));
                    $conn->prepare("
                        UPDATE re_service_charge_payment_plans
                        SET status = 'cancelled', updated_at = NOW()
                        WHERE company_id = ? AND id IN ($ph)
                    ")->execute(array_merge([$companyId], $planIds));
                    $conn->prepare("
                        UPDATE re_service_charge_payment_plan_lines
                        SET status = 'cancelled', updated_at = NOW()
                        WHERE company_id = ? AND plan_id IN ($ph)
                          AND status IN ('planned','registered')
                    ")->execute(array_merge([$companyId], $planIds));

                    // Cancel only pending service PDCs linked to these plan lines (never rent PDCs).
                    $lineIds = $conn->prepare("
                        SELECT id, cheque_id FROM re_service_charge_payment_plan_lines
                        WHERE company_id = ? AND plan_id IN ($ph)
                    ");
                    $lineIds->execute(array_merge([$companyId], $planIds));
                    foreach ($lineIds->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ln) {
                        $chequeId = (int)($ln['cheque_id'] ?? 0);
                        if ($chequeId <= 0) {
                            continue;
                        }
                        $conn->prepare("
                            UPDATE re_post_dated_cheques
                            SET status = 'cancelled',
                                notes = CONCAT(COALESCE(notes,''), ' | Service charge ', ?)
                            WHERE id = ? AND company_id = ?
                              AND installment_id IS NULL
                              AND status IN ('pending','draft')
                        ")->execute([$lifecycleStatus, $chequeId, $companyId]);
                    }
                }
            }

            re_service_charge_sync_invoice_mode_obligations($conn, $companyId, $leaseId, $userId);

            if ($ownTx) {
                $conn->commit();
            }

            if (is_file(__DIR__ . '/../../../includes/AuditService.php')) {
                require_once __DIR__ . '/../../../includes/AuditService.php';
                if (class_exists('AuditService')) {
                    AuditService::logEvent([
                        'action' => 'service_charge_' . $lifecycleStatus,
                        'action_label' => ucfirst($lifecycleStatus) . ' service charge',
                        'module' => 'realestate',
                        'company_id' => $companyId,
                        'object_type' => 're_service_charges',
                        'object_id' => (string)$serviceChargeId,
                        'summary' => trim($reason !== '' ? $reason : ($lifecycleStatus . ' effective ' . $effectiveDate)),
                        'source' => 'user',
                        'success' => true,
                    ]);
                }
            }

            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            if ($ownTx && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * Cancel path only: waive unpaid schedule rows (all due dates), void unpaid Extra SC invoices,
 * and cancel leftover open obligations/candidates. Never touches rent/lease invoices.
 *
 * @return array{success:bool,error:?string,voided_invoices:int,waived_items:int,cancelled_obligations:int}
 */
if (!function_exists('re_sc_clear_unpaid_accounting')) {
    function re_sc_clear_unpaid_accounting(
        PDO $conn,
        int $companyId,
        int $serviceChargeId,
        int $leaseId,
        string $effectiveDate,
        ?int $userId = null,
        string $reason = ''
    ): array {
        require_once __DIR__ . '/invoice_engine.php';

        $reason = trim($reason) !== '' ? $reason : ('Service charge cancelled effective ' . $effectiveDate);
        $voided = 0;
        $waived = 0;
        $cancelledObligations = 0;

        $itemStmt = $conn->prepare("
            SELECT id
            FROM re_billing_items
            WHERE company_id = ?
              AND lease_id = ?
              AND service_charge_id = ?
              AND item_type = 'service_charge'
        ");
        $itemStmt->execute([$companyId, $leaseId, $serviceChargeId]);
        $billingItemIds = array_values(array_filter(array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        if ($billingItemIds === []) {
            return [
                'success' => true,
                'error' => null,
                'voided_invoices' => 0,
                'waived_items' => 0,
                'cancelled_obligations' => 0,
            ];
        }

        $ph = implode(',', array_fill(0, count($billingItemIds), '?'));

        // Fail closed if any Extra SC invoice already has collections.
        $paidCheck = $conn->prepare("
            SELECT i.invoice_number, i.status, i.paid_amount, i.outstanding_amount
            FROM re_invoices i
            JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.company_id = i.company_id
            JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = i.company_id
            WHERE i.company_id = ?
              AND i.lease_id = ?
              AND i.status <> 'cancelled'
              AND o.source_type = 'billing_item'
              AND o.source_id IN ($ph)
              AND (
                    i.status IN ('paid', 'partial')
                 OR COALESCE(i.paid_amount, 0) > 0.005
                 OR EXISTS (
                        SELECT 1 FROM re_receipt_allocations ra
                        WHERE ra.company_id = i.company_id AND ra.invoice_id = i.id
                          AND ra.amount_allocated > 0.005
                    )
              )
            LIMIT 1
        ");
        $paidCheck->execute(array_merge([$companyId, $leaseId], $billingItemIds));
        $blocked = $paidCheck->fetch(PDO::FETCH_ASSOC);
        if ($blocked) {
            return [
                'success' => false,
                'error' => 'Cannot fully cancel: Extra Service invoice '
                    . (string)($blocked['invoice_number'] ?? '')
                    . ' already has payments/allocations. Reverse those first.',
                'voided_invoices' => 0,
                'waived_items' => 0,
                'cancelled_obligations' => 0,
            ];
        }

        $invStmt = $conn->prepare("
            SELECT DISTINCT i.id
            FROM re_invoices i
            JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.company_id = i.company_id
            JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = i.company_id
            WHERE i.company_id = ?
              AND i.lease_id = ?
              AND i.status <> 'cancelled'
              AND o.source_type = 'billing_item'
              AND o.source_id IN ($ph)
            ORDER BY i.id ASC
        ");
        $invStmt->execute(array_merge([$companyId, $leaseId], $billingItemIds));
        foreach ($invStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $invoiceId) {
            $invoiceId = (int)$invoiceId;
            if ($invoiceId <= 0) {
                continue;
            }
            $void = re_invoice_engine_void_unpaid_invoice(
                $conn,
                $companyId,
                $invoiceId,
                $reason,
                $userId,
                $effectiveDate
            );
            if (empty($void['success'])) {
                return [
                    'success' => false,
                    'error' => $void['error'] ?? ('Could not void invoice #' . $invoiceId),
                    'voided_invoices' => $voided,
                    'waived_items' => $waived,
                    'cancelled_obligations' => $cancelledObligations,
                ];
            }
            if (empty($void['already_void'])) {
                $voided++;
            }
        }

        $waive = $conn->prepare("
            UPDATE re_billing_items
            SET status = 'waived',
                notes = CONCAT(COALESCE(notes, ''), ' | Service cancelled effective ', ?)
            WHERE company_id = ?
              AND lease_id = ?
              AND service_charge_id = ?
              AND item_type = 'service_charge'
              AND is_paid = 0
              AND status IN ('pending', 'overdue', 'partial')
        ");
        $waive->execute([$effectiveDate, $companyId, $leaseId, $serviceChargeId]);
        $waived = (int)$waive->rowCount();

        // Cancel leftover open obligations (including ones never invoiced / already freed by void).
        $obCancel = $conn->prepare("
            UPDATE re_obligations
            SET status = 'cancelled',
                notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
            WHERE company_id = ?
              AND lease_id = ?
              AND source_type = 'billing_item'
              AND source_id IN ($ph)
              AND status IN ('open', 'partially_allocated', 'draft')
        ");
        $obCancel->execute(array_merge([$reason, $companyId, $leaseId], $billingItemIds));
        $cancelledObligations = (int)$obCancel->rowCount();

        try {
            $cand = $conn->prepare("
                UPDATE re_invoice_candidates c
                JOIN re_obligations o ON o.id = c.obligation_id AND o.company_id = c.company_id
                SET c.status = 'cancelled',
                    c.notes = TRIM(CONCAT(COALESCE(c.notes, ''), CASE WHEN COALESCE(c.notes, '') = '' THEN '' ELSE ' | ' END, ?))
                WHERE c.company_id = ?
                  AND c.lease_id = ?
                  AND o.source_type = 'billing_item'
                  AND o.source_id IN ($ph)
                  AND c.status IN ('prepared', 'approved')
            ");
            $cand->execute(array_merge([$reason, $companyId, $leaseId], $billingItemIds));
        } catch (Throwable $e) {
            // Candidates table may be absent on older DBs.
        }

        return [
            'success' => true,
            'error' => null,
            'voided_invoices' => $voided,
            'waived_items' => $waived,
            'cancelled_obligations' => $cancelledObligations,
        ];
    }
}
