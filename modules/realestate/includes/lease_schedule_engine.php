<?php
/**
 * Lease Schedule Engine — the single source of truth for lease fee distribution,
 * totals, the intended installment plan, and installment status/locking.
 *
 * Used by lease_add.php (save + form preview), lease_view.php (display + counters),
 * and contract/PDF generation so every screen shows identical numbers.
 *
 * Pure, deterministic functions only (no DB writes here). DB reconciliation that
 * relies on this engine lives in lease_installment_schedule.php / lease_add.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/lease_installment_schedule.php';

if (!function_exists('lease_engine_money')) {
    function lease_engine_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('lease_engine_date_in_window')) {
    /**
     * True when $checkDate falls inside [$startDate, $endDate] (end optional).
     */
    function lease_engine_date_in_window(?string $checkDate, ?string $startDate, ?string $endDate): bool
    {
        if (empty($checkDate) || empty($startDate)) {
            return false;
        }
        try {
            $check = new DateTime($checkDate);
            $start = new DateTime($startDate);
            $end = !empty($endDate) ? new DateTime($endDate) : null;
        } catch (Throwable $e) {
            return false;
        }
        return $check >= $start && (!$end || $check <= $end);
    }
}

if (!function_exists('lease_engine_dist_from_row')) {
    /**
     * Derive the canonical 3-way distribution choices from a re_leases row.
     * Security deposit is first|separate only (separate is inferred from
     * sep_security_deposit which itself is derived from a security_deposit row).
     *
     * @return array<string,string>
     */
    function lease_engine_dist_from_row(array $lease): array
    {
        $threeWay = static function (string $splitKey, string $sepKey, array $row): string {
            if (!empty($row[$splitKey])) {
                return 'split';
            }
            if (!empty($row[$sepKey])) {
                return 'separate';
            }
            return 'first';
        };

        return [
            'security'   => !empty($lease['sep_security_deposit']) ? 'separate' : 'first',
            'chiller'    => $threeWay('split_chiller_fees', 'sep_chiller_fees', $lease),
            'ejari'      => $threeWay('split_ejari_fees', 'sep_ejari_fees', $lease),
            'admin'      => $threeWay('split_admin_fees', 'sep_admin_fees', $lease),
            'commission' => $threeWay('split_commission_fees', 'sep_commission_fees', $lease),
            'amc'        => $threeWay('split_amc_fees', 'sep_amc_fees', $lease),
            'parking'    => $threeWay('split_additional_parking', 'sep_additional_parking', $lease),
            'store'      => $threeWay('split_additional_store', 'sep_additional_store', $lease),
        ];
    }
}

if (!function_exists('lease_normalize_inputs')) {
    /**
     * Normalize a re_leases row (or POST-derived array) into the canonical input
     * shape consumed by lease_compute_totals() / lease_build_plan().
     *
     * Accepts either pre-computed dist values (key 'dist') or a DB row whose
     * split and sep flags will be converted.
     */
    function lease_normalize_inputs(array $src): array
    {
        $dist = isset($src['dist']) && is_array($src['dist'])
            ? $src['dist']
            : lease_engine_dist_from_row($src);

        // VAT total: prefer an explicit computed value, else the stored column.
        $totalVat = array_key_exists('total_vat', $src)
            ? (float)$src['total_vat']
            : (float)($src['total_vat_amount'] ?? 0);

        return [
            'start_date'             => $src['start_date'] ?? null,
            'end_date'               => $src['end_date'] ?? null,
            'annual_rent'            => (float)($src['annual_rent'] ?? 0),
            'number_of_installments' => max(1, (int)($src['number_of_installments'] ?? 12)),
            'security_deposit'       => (float)($src['security_deposit'] ?? 0),
            'chiller_fees'           => (float)($src['chiller_fees'] ?? 0),
            'ejari_fees'             => (float)($src['ejari_fees'] ?? 0),
            'admin_fees'             => (float)($src['admin_fees'] ?? 0),
            'commission_fees'        => (float)($src['commission_fees'] ?? 0),
            'amc_amount'             => (float)($src['amc_amount'] ?? 0),
            'is_renewal_lease'       => !empty($src['is_renewal_lease']),
            'add_fees_to_first'      => array_key_exists('add_fees_to_first_installment', $src)
                ? !empty($src['add_fees_to_first_installment'])
                : true,
            'has_additional_parking' => !empty($src['has_additional_parking']),
            'additional_parking_fee' => (float)($src['additional_parking_fee'] ?? 0),
            'additional_parking_start_date' => $src['additional_parking_start_date'] ?? null,
            'additional_parking_end_date'   => $src['additional_parking_end_date'] ?? null,
            'has_additional_store'   => !empty($src['has_additional_store']),
            'additional_store_fee'   => (float)($src['additional_store_fee'] ?? 0),
            'additional_store_start_date' => $src['additional_store_start_date'] ?? null,
            'additional_store_end_date'   => $src['additional_store_end_date'] ?? null,
            'vat_distribution_type'  => (string)($src['vat_distribution_type'] ?? 'first_installment'),
            'total_vat'              => $totalVat,
            'grace_period_days'      => (int)($src['grace_period_days'] ?? 0),
            'dist'                   => $dist,
        ];
    }
}

if (!function_exists('lease_compute_totals')) {
    /**
     * The ONE fee/total formula. Returns effective annual rent, fees routed to the
     * first installment / combined fees cheque, fees on their own cheque, and the
     * per-installment rent amount. Mirrors the canonical lease_add.php save math.
     */
    function lease_compute_totals(array $src): array
    {
        $in = lease_normalize_inputs($src);
        $dist = $in['dist'];
        $n = $in['number_of_installments'];

        $parkingMonthly = ($in['has_additional_parking'] && $in['additional_parking_fee'] > 0)
            ? $in['additional_parking_fee'] : 0.0;
        $storeMonthly = ($in['has_additional_store'] && $in['additional_store_fee'] > 0)
            ? $in['additional_store_fee'] : 0.0;

        $parkingFirstEligible = $parkingMonthly > 0 && lease_engine_date_in_window(
            $in['start_date'], $in['additional_parking_start_date'], $in['additional_parking_end_date']
        );
        $storeFirstEligible = $storeMonthly > 0 && lease_engine_date_in_window(
            $in['start_date'], $in['additional_store_start_date'], $in['additional_store_end_date']
        );

        // Effective annual rent = base + all 'split' fees.
        $effectiveAnnual = $in['annual_rent'];
        if ($dist['chiller'] === 'split')    $effectiveAnnual += $in['chiller_fees'];
        if ($dist['ejari'] === 'split')      $effectiveAnnual += $in['ejari_fees'];
        if ($dist['admin'] === 'split')      $effectiveAnnual += $in['admin_fees'];
        if ($dist['commission'] === 'split') $effectiveAnnual += $in['commission_fees'];
        if ($dist['amc'] === 'split' && $in['amc_amount'] > 0) $effectiveAnnual += $in['amc_amount'];
        if ($dist['parking'] === 'split' && $parkingMonthly > 0) $effectiveAnnual += $parkingMonthly * 12;
        if ($dist['store'] === 'split' && $storeMonthly > 0)     $effectiveAnnual += $storeMonthly * 12;
        if ($in['vat_distribution_type'] === 'split_installments' && $in['total_vat'] > 0) {
            $effectiveAnnual += $in['total_vat'];
        }

        // Fees routed to the 1st installment (or combined fees cheque).
        $toFirst = 0.0;
        if (!$in['is_renewal_lease'] && $dist['security'] !== 'separate') {
            $toFirst += $in['security_deposit'];
        }
        if ($dist['chiller'] === 'first')    $toFirst += $in['chiller_fees'];
        if ($dist['ejari'] === 'first')      $toFirst += $in['ejari_fees'];
        if ($dist['admin'] === 'first')      $toFirst += $in['admin_fees'];
        if ($dist['commission'] === 'first') $toFirst += $in['commission_fees'];
        if ($dist['amc'] === 'first')        $toFirst += $in['amc_amount'];
        if ($dist['parking'] === 'first' && $parkingFirstEligible) $toFirst += $parkingMonthly;
        if ($dist['store'] === 'first' && $storeFirstEligible)     $toFirst += $storeMonthly;
        if ($in['vat_distribution_type'] === 'first_installment' && $in['total_vat'] > 0) {
            $toFirst += $in['total_vat'];
        }

        // Fees getting their own separate cheque.
        $toSeparate = 0.0;
        if (!$in['is_renewal_lease'] && $dist['security'] === 'separate') {
            $toSeparate += $in['security_deposit'];
        }
        if ($dist['chiller'] === 'separate')    $toSeparate += $in['chiller_fees'];
        if ($dist['ejari'] === 'separate')      $toSeparate += $in['ejari_fees'];
        if ($dist['admin'] === 'separate')      $toSeparate += $in['admin_fees'];
        if ($dist['commission'] === 'separate') $toSeparate += $in['commission_fees'];
        if ($dist['amc'] === 'separate' && $in['amc_amount'] > 0) $toSeparate += $in['amc_amount'];
        if ($dist['parking'] === 'separate' && $parkingMonthly > 0) $toSeparate += $parkingMonthly;
        if ($dist['store'] === 'separate' && $storeMonthly > 0)     $toSeparate += $storeMonthly;
        if ($in['vat_distribution_type'] === 'separate_payment' && $in['total_vat'] > 0) {
            $toSeparate += $in['total_vat'];
        }

        return [
            'effective_annual'   => lease_engine_money($effectiveAnnual),
            'monthly_rent'       => lease_engine_money($effectiveAnnual / $n),
            'to_first'           => lease_engine_money($toFirst),
            'to_separate'        => lease_engine_money($toSeparate),
            'total_vat'          => lease_engine_money($in['total_vat']),
            'number_of_installments' => $n,
            'add_fees_to_first'  => $in['add_fees_to_first'],
            'parking_monthly'    => lease_engine_money($parkingMonthly),
            'store_monthly'      => lease_engine_money($storeMonthly),
        ];
    }
}

if (!function_exists('lease_build_plan')) {
    /**
     * Build the intended schedule (ordered) from lease inputs. Amounts and types
     * only; callers attach/keep real dates. Order matches the lease form exactly:
     *   1) separate-fee lines (security, chiller, ejari, admin, commission, amc, parking, store, vat)
     *   2) combined_fees line (when fees are NOT merged into the 1st rent installment)
     *   3) rent installments 1..N
     *
     * @return array{lines:list<array<string,mixed>>, totals:array<string,mixed>}
     */
    function lease_build_plan(array $src): array
    {
        $in = lease_normalize_inputs($src);
        $totals = lease_compute_totals($src);
        $dist = $in['dist'];

        $sepLabels = [
            'security_deposit' => 'Security Deposit',
            'chiller'    => 'Chiller Fees',
            'ejari'      => 'Ejari Fees',
            'admin'      => 'Admin Fees',
            'commission' => 'Commission Fees',
            'amc'        => 'AMC Fee',
            'parking'    => 'Parking Fee',
            'store'      => 'Store Fee',
            'vat'        => 'VAT (total)',
        ];

        // Candidate separate fees in canonical order with their amounts.
        $sepCandidates = [];
        if (!$in['is_renewal_lease'] && $dist['security'] === 'separate' && $in['security_deposit'] > 0) {
            $sepCandidates[] = ['type' => 'security_deposit', 'amount' => $in['security_deposit']];
        }
        if ($dist['chiller'] === 'separate' && $in['chiller_fees'] > 0) {
            $sepCandidates[] = ['type' => 'chiller', 'amount' => $in['chiller_fees']];
        }
        if ($dist['ejari'] === 'separate' && $in['ejari_fees'] > 0) {
            $sepCandidates[] = ['type' => 'ejari', 'amount' => $in['ejari_fees']];
        }
        if ($dist['admin'] === 'separate' && $in['admin_fees'] > 0) {
            $sepCandidates[] = ['type' => 'admin', 'amount' => $in['admin_fees']];
        }
        if ($dist['commission'] === 'separate' && $in['commission_fees'] > 0) {
            $sepCandidates[] = ['type' => 'commission', 'amount' => $in['commission_fees']];
        }
        if ($dist['amc'] === 'separate' && $in['amc_amount'] > 0) {
            $sepCandidates[] = ['type' => 'amc', 'amount' => $in['amc_amount']];
        }
        if ($dist['parking'] === 'separate' && $totals['parking_monthly'] > 0) {
            $sepCandidates[] = ['type' => 'parking', 'amount' => $totals['parking_monthly']];
        }
        if ($dist['store'] === 'separate' && $totals['store_monthly'] > 0) {
            $sepCandidates[] = ['type' => 'store', 'amount' => $totals['store_monthly']];
        }
        if ($in['vat_distribution_type'] === 'separate_payment' && $in['total_vat'] > 0) {
            $sepCandidates[] = ['type' => 'vat', 'amount' => $in['total_vat']];
        }

        $lines = [];
        foreach ($sepCandidates as $sf) {
            $lines[] = [
                'kind'             => 'separate',
                'installment_type' => $sf['type'],
                'label'            => $sepLabels[$sf['type']] ?? ucfirst($sf['type']),
                'amount'           => lease_engine_money($sf['amount']),
                'ordinal'          => null,
                'is_rent'          => false,
            ];
        }

        // Combined fees cheque (only when fees are NOT merged into 1st rent installment).
        if (!$in['add_fees_to_first'] && $totals['to_first'] > 0) {
            $lines[] = [
                'kind'             => 'combined_fees',
                'installment_type' => 'combined_fees',
                'label'            => 'Fees Installment',
                'amount'           => lease_engine_money($totals['to_first']),
                'ordinal'          => null,
                'is_rent'          => false,
            ];
        }

        // Rent installments.
        $rentAmounts = lease_compute_rent_installment_amounts(
            $totals['effective_annual'],
            $in['number_of_installments'],
            $in['add_fees_to_first'],
            $totals['to_first']
        );
        for ($i = 0; $i < $in['number_of_installments']; $i++) {
            $lines[] = [
                'kind'             => 'rent',
                'installment_type' => 'rent',
                'label'            => 'Installment ' . ($i + 1),
                'amount'           => lease_engine_money($rentAmounts[$i] ?? $totals['monthly_rent']),
                'ordinal'          => $i,
                'is_rent'          => true,
            ];
        }

        return ['lines' => $lines, 'totals' => $totals];
    }
}

if (!function_exists('lease_engine_collected')) {
    /**
     * Collected amount for an installment row already enriched by the caller.
     * Prefers an explicit total_paid_display (allocation-aware), then total_paid.
     */
    function lease_engine_collected(array $inst): float
    {
        if (array_key_exists('total_paid_display', $inst) && $inst['total_paid_display'] !== null) {
            return (float)$inst['total_paid_display'];
        }
        return (float)($inst['total_paid'] ?? 0);
    }
}

if (!function_exists('lease_engine_due')) {
    /**
     * Due amount: the installment amount is the source of truth. (A linked cheque
     * mirrors it for unpaid rows; for locked rows the installment row already
     * holds the agreed amount.)
     */
    function lease_engine_due(array $inst): float
    {
        return (float)($inst['amount'] ?? 0);
    }
}

if (!function_exists('lease_installment_status')) {
    /**
     * The ONE status function. Used for BOTH the Payment Summary counters and the
     * per-row badges so they can never disagree.
     *
     * Expects $inst to optionally contain: status, cheque_status, amount,
     * total_paid_display|total_paid, installment_date.
     */
    function lease_installment_status(array $inst, int $graceDays = 0): string
    {
        $dbStatus = (string)($inst['status'] ?? '');
        if ($dbStatus === 'cancelled') {
            return 'cancelled';
        }
        if (($inst['cheque_status'] ?? '') === 'returned') {
            return 'returned';
        }
        if ($dbStatus === 'waived') {
            return 'waived';
        }

        $collected = lease_engine_collected($inst);
        $due = lease_engine_due($inst);

        if ($due > 0 && $collected >= $due - 0.009) {
            return 'paid';
        }
        if ($collected > 0) {
            return 'partial';
        }
        $chequeStatus = (string)($inst['cheque_status'] ?? '');
        if ($chequeStatus !== '' && $chequeStatus !== 'pending') {
            return $chequeStatus; // bounced / deposited / cleared
        }

        $dueDate = (string)($inst['installment_date'] ?? '');
        if ($dueDate !== '') {
            $deadline = strtotime($dueDate);
            if ($graceDays > 0 && $deadline !== false) {
                $deadline = strtotime('+' . $graceDays . ' days', $deadline);
            }
            if ($deadline !== false && $deadline < strtotime(date('Y-m-d'))) {
                return 'overdue';
            }
        }
        return 'pending';
    }
}

if (!function_exists('lease_is_locked')) {
    /**
     * A row is LOCKED (never auto-modified/deleted) when real money/legal state
     * is attached: payment_id, allocation row, or a cleared/returned cheque.
     * Status alone can be stale after financial resets and must not lock a row.
     */
    function lease_is_locked(array $inst, ?PDO $conn = null): bool
    {
        if (!empty($inst['payment_id'])) {
            return true;
        }
        if (($inst['cheque_status'] ?? '') === 'cleared') {
            return true;
        }
        if ($conn !== null && !empty($inst['id'])) {
            try {
                static $hasAlloc = null;
                if ($hasAlloc === null) {
                    $chk = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_payment_allocations'");
                    $hasAlloc = ((int)$chk->fetchColumn()) > 0;
                }
                if ($hasAlloc) {
                    $a = $conn->prepare("SELECT 1 FROM re_payment_allocations WHERE installment_id = ? LIMIT 1");
                    $a->execute([(int)$inst['id']]);
                    if ($a->fetchColumn()) {
                        return true;
                    }
                }
            } catch (Throwable $e) {
                // If the check fails, fall through to the non-allocation signals already evaluated.
            }
        }
        return false;
    }
}

if (!function_exists('lease_locked_installment_ids')) {
    /**
     * Return the set of LOCKED installment IDs for a lease (as id => true).
     * A row is locked when real money/legal state is attached: a legacy
     * payment_id, a direct payment, a payment allocation, or a cleared post-dated cheque.
     *
     * This is the authoritative guard the deterministic save uses to ensure
     * paid/allocated data is never modified or deleted.
     *
     * @return array<int,bool>
     */
    function lease_locked_installment_ids(PDO $conn, int $leaseId, int $companyId): array
    {
        $locked = [];

        $stmt = $conn->prepare("
            SELECT id FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ?
              AND payment_id IS NOT NULL
        ");
        $stmt->execute([$leaseId, $companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $locked[(int)$id] = true;
        }

        try {
            $stmt = $conn->prepare("
                SELECT DISTINCT installment_id
                FROM re_payments
                WHERE lease_id = ? AND company_id = ? AND installment_id IS NOT NULL
            ");
            $stmt->execute([$leaseId, $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if ($id !== null) {
                    $locked[(int)$id] = true;
                }
            }
        } catch (Throwable $e) {
            // payments table/columns optional — ignore.
        }

        try {
            $stmt = $conn->prepare("
                SELECT installment_id FROM re_post_dated_cheques
                WHERE lease_id = ? AND status = 'cleared' AND installment_id IS NOT NULL
            ");
            $stmt->execute([$leaseId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if ($id !== null) {
                    $locked[(int)$id] = true;
                }
            }
        } catch (Throwable $e) {
            // cheque table/columns optional — ignore.
        }

        try {
            $chk = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_payment_allocations'");
            if (((int)$chk->fetchColumn()) > 0) {
                $stmt = $conn->prepare("
                    SELECT DISTINCT pa.installment_id
                    FROM re_payment_allocations pa
                    JOIN re_lease_installments li ON li.id = pa.installment_id
                    WHERE li.lease_id = ? AND li.company_id = ?
                ");
                $stmt->execute([$leaseId, $companyId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    if ($id !== null) {
                        $locked[(int)$id] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            // allocations optional — ignore.
        }

        return $locked;
    }
}

if (!function_exists('lease_status_badge_class')) {
    /**
     * Bootstrap contextual class for a computed installment status.
     */
    function lease_status_badge_class(string $status): string
    {
        $map = [
            'pending'   => 'warning',
            'paid'      => 'success',
            'overdue'   => 'danger',
            'waived'    => 'secondary',
            'partial'   => 'info',
            'bounced'   => 'danger',
            'deposited' => 'info',
            'cleared'   => 'success',
            'cancelled' => 'secondary',
            'returned'  => 'dark',
            'hold'      => 'dark',
            'held_by_finance' => 'dark',
            'collected' => 'primary',
            'draft' => 'secondary',
            'replaced' => 'secondary',
            'legal_escalated' => 'primary',
        ];
        return $map[$status] ?? 'secondary';
    }
}
