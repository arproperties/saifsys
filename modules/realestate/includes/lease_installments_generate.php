<?php
/**
 * Generate rent installments + optional post-dated cheques for a lease.
 * Used when converting a renewal lease and when creating a draft renewal lease (preview schedule).
 */
require_once __DIR__ . '/lease_vat_calculator.php';
require_once __DIR__ . '/lease_installment_schedule.php';

if (!function_exists('generateLeaseInstallmentsForConversion')) {
    function generateLeaseInstallmentsForConversion(PDO $conn, int $companyId, int $leaseId, ?int $createdBy = null): void {
        $conn->prepare("DELETE FROM re_lease_cheques WHERE lease_id = ? AND company_id = ?")->execute([$leaseId, $companyId]);
        $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND company_id = ?")->execute([$leaseId, $companyId]);
        $conn->prepare("DELETE FROM re_lease_installments WHERE lease_id = ? AND company_id = ?")->execute([$leaseId, $companyId]);

        $stmt = $conn->prepare("
            SELECT *
            FROM re_leases
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$leaseId, $companyId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lease) {
            throw new Exception("Lease not found for installment generation");
        }

        $isRenewalLease = !empty($lease['is_renewal_lease']);

        $splitChillerFees  = !empty($lease['split_chiller_fees']);
        $splitEjariFees    = !empty($lease['split_ejari_fees']);
        $splitAdminFees    = !empty($lease['split_admin_fees']);
        $splitCommFees     = !empty($lease['split_commission_fees']);
        $splitAddParking   = !empty($lease['split_additional_parking']);
        $splitAddStore     = !empty($lease['split_additional_store']);
        $splitAmcFees      = !empty($lease['split_amc_fees']);

        $addFeesToFirst = !empty($lease['add_fees_to_first_installment']);

        $annualRent = $lease['annual_rent'] !== null && $lease['annual_rent'] !== ''
            ? (float)$lease['annual_rent']
            : (float)$lease['monthly_rent'] * 12;

        $chillerFees = (float)($lease['chiller_fees'] ?? 0);
        $ejariFees = (float)($lease['ejari_fees'] ?? 0);
        $adminFees = (float)($lease['admin_fees'] ?? 0);
        $commissionFees = (float)($lease['commission_fees'] ?? 0);

        $hasAdditionalParking = !empty($lease['has_additional_parking']);
        $additionalParkingFee = (float)($lease['additional_parking_fee'] ?? 0);
        $additionalParkingStartDate = $lease['additional_parking_start_date'] ?? null;
        $additionalParkingEndDate = $lease['additional_parking_end_date'] ?? null;

        $hasAdditionalStore = !empty($lease['has_additional_store']);
        $additionalStoreFee = (float)($lease['additional_store_fee'] ?? 0);
        $additionalStoreStartDate = $lease['additional_store_start_date'] ?? null;
        $additionalStoreEndDate = $lease['additional_store_end_date'] ?? null;

        $amcAmount = (float)($lease['amc_amount'] ?? 0);
        $numberOfInstallments = !empty($lease['number_of_installments']) ? (int)$lease['number_of_installments'] : 12;
        if ($numberOfInstallments <= 0) {
            $numberOfInstallments = 12;
        }

        $effectiveAnnualRent = $annualRent;
        if ($splitChillerFees) {
            $effectiveAnnualRent += $chillerFees;
        }
        if ($splitEjariFees) {
            $effectiveAnnualRent += $ejariFees;
        }
        if ($splitAdminFees) {
            $effectiveAnnualRent += $adminFees;
        }
        if ($splitCommFees) {
            $effectiveAnnualRent += $commissionFees;
        }
        if ($splitAddParking && $hasAdditionalParking && $additionalParkingFee > 0) {
            $effectiveAnnualRent += $additionalParkingFee * 12;
        }
        if ($splitAddStore && $hasAdditionalStore && $additionalStoreFee > 0) {
            $effectiveAnnualRent += $additionalStoreFee * 12;
        }
        if ($splitAmcFees && $amcAmount > 0) {
            $effectiveAnnualRent += $amcAmount;
        }

        $totalVat = (float)($lease['total_vat_amount'] ?? 0);
        $vatDist = lease_vat_normalize_distribution($lease['vat_distribution_type'] ?? 'first_installment');
        if ($vatDist === 'split_installments' && $totalVat > 0) {
            $effectiveAnnualRent += $totalVat;
        }

        $monthlyRent = $numberOfInstallments > 0 ? $effectiveAnnualRent / $numberOfInstallments : $effectiveAnnualRent / 12;

        $totalFeesToFirst = 0;
        if (!$isRenewalLease) {
            $totalFeesToFirst += (float)($lease['security_deposit'] ?? 0);
        }
        if (!$splitChillerFees) {
            $totalFeesToFirst += $chillerFees;
        }
        if (!$splitEjariFees) {
            $totalFeesToFirst += $ejariFees;
        }
        if (!$splitAdminFees) {
            $totalFeesToFirst += $adminFees;
        }
        if (!$splitCommFees) {
            $totalFeesToFirst += $commissionFees;
        }

        $leaseStart = new DateTime($lease['start_date']);
        if (!$splitAddParking && $hasAdditionalParking && $additionalParkingFee > 0 && $additionalParkingStartDate) {
            $parkingStart = new DateTime($additionalParkingStartDate);
            $parkingEnd = $additionalParkingEndDate ? new DateTime($additionalParkingEndDate) : null;
            if ($leaseStart >= $parkingStart && (!$parkingEnd || $leaseStart <= $parkingEnd)) {
                $totalFeesToFirst += $additionalParkingFee;
            }
        }
        if (!$splitAddStore && $hasAdditionalStore && $additionalStoreFee > 0 && $additionalStoreStartDate) {
            $storeStart = new DateTime($additionalStoreStartDate);
            $storeEnd = $additionalStoreEndDate ? new DateTime($additionalStoreEndDate) : null;
            if ($leaseStart >= $storeStart && (!$storeEnd || $leaseStart <= $storeEnd)) {
                $totalFeesToFirst += $additionalStoreFee;
            }
        }

        $vatForCombinedFeesRow = 0.0;
        if ($vatDist === 'first_installment' && $totalVat > 0 && !$addFeesToFirst) {
            $totalFeesToFirst += $totalVat;
            $vatForCombinedFeesRow = $totalVat;
        }

        $startDate = (new DateTime($lease['start_date']))->format('Y-m-d');

        if ($vatDist === 'separate_payment' && $totalVat > 0) {
            $conn->prepare("
                INSERT INTO re_lease_installments
                    (company_id, lease_id, installment_date, amount, status, installment_type, vat_amount)
                VALUES (?, ?, ?, ?, 'pending', 'vat', ?)
            ")->execute([$companyId, $leaseId, $startDate, $totalVat, $totalVat]);
        }

        if (!$addFeesToFirst && $totalFeesToFirst > 0) {
            $conn->prepare("
                INSERT INTO re_lease_installments
                    (company_id, lease_id, installment_date, amount, status, vat_amount, installment_type)
                VALUES (?, ?, ?, ?, 'pending', ?, 'combined_fees')
            ")->execute([$companyId, $leaseId, $startDate, $totalFeesToFirst, $vatForCombinedFeesRow]);
        }

        $start = new DateTime($lease['start_date']);
        $end = new DateTime($lease['end_date']);
        $totalDays = $end->diff($start)->days;
        $intervalDays = (int)($totalDays / $numberOfInstallments);
        if ($intervalDays < 1) {
            $intervalDays = 1;
        }

        $vatPer = ($vatDist === 'split_installments' && $totalVat > 0 && $numberOfInstallments > 0)
            ? round($totalVat / $numberOfInstallments, 2)
            : 0.0;
        $vatAcc = 0.0;

        $rentInstallmentAmounts = lease_compute_rent_installment_amounts(
            (float)$effectiveAnnualRent,
            $numberOfInstallments,
            $addFeesToFirst,
            (float)$totalFeesToFirst
        );

        $current = clone $start;
        for ($i = 0; $i < $numberOfInstallments; $i++) {
            $installmentAmount = (float)($rentInstallmentAmounts[$i] ?? $monthlyRent);
            if ($i === 0 && $addFeesToFirst && $vatDist === 'first_installment' && $totalVat > 0) {
                $installmentAmount += $totalVat;
            }

            if ($i === $numberOfInstallments - 1) {
                $installmentDate = $end->format('Y-m-d');
            } else {
                $installmentDate = $current->format('Y-m-d');
            }

            $rowVat = 0.0;
            if ($vatDist === 'split_installments' && $totalVat > 0) {
                $rowVat = ($i === $numberOfInstallments - 1)
                    ? round($totalVat - $vatAcc, 2)
                    : $vatPer;
                $vatAcc += $rowVat;
            } elseif ($vatDist === 'first_installment' && $totalVat > 0 && $addFeesToFirst && $i === 0) {
                $rowVat = $totalVat;
            }

            $conn->prepare("
                INSERT INTO re_lease_installments
                    (company_id, lease_id, installment_date, amount, status, vat_amount)
                VALUES (?, ?, ?, ?, 'pending', ?)
            ")->execute([$companyId, $leaseId, $installmentDate, $installmentAmount, $rowVat]);

            if ($i < $numberOfInstallments - 1) {
                $current->modify("+{$intervalDays} days");
            }
        }

        if (!$splitAmcFees && $amcAmount > 0) {
            $amcInstDate = (new DateTime($lease['start_date']))->format('Y-m-d');
            $conn->prepare("
                INSERT INTO re_lease_installments
                    (company_id, lease_id, installment_date, amount, status, installment_type, vat_amount)
                VALUES (?, ?, ?, ?, 'pending', 'amc', 0)
            ")->execute([$companyId, $leaseId, $amcInstDate, $amcAmount]);
        }

        $paymentMethod = strtolower((string)($lease['payment_method'] ?? ''));
        if ($paymentMethod === 'cheque') {
            $tenantStmt = $conn->prepare("SELECT first_name, last_name FROM re_tenants WHERE id = ? LIMIT 1");
            $tenantStmt->execute([(int)($lease['tenant_id'] ?? 0)]);
            $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $tenantName = trim(($tenant['first_name'] ?? '') . ' ' . ($tenant['last_name'] ?? ''));

            $instStmt = $conn->prepare("
                SELECT id, installment_date, amount
                FROM re_lease_installments
                WHERE lease_id = ? AND company_id = ?
                ORDER BY installment_date ASC, id ASC
            ");
            $instStmt->execute([$leaseId, $companyId]);
            $instRows = $instStmt->fetchAll(PDO::FETCH_ASSOC);

            $idx = 1;
            foreach ($instRows as $instRow) {
                $instId = (int)$instRow['id'];
                $chequeNo = 'CHQ-' . $leaseId . '-' . $idx;
                $chequeDate = $instRow['installment_date'];
                $chequeAmount = (float)$instRow['amount'];

                $conn->prepare("
                    INSERT INTO re_post_dated_cheques
                        (company_id, lease_id, installment_id, cheque_number, cheque_date, cheque_amount,
                         account_holder_name, bank_name, received_date, status, created_by)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, NULL, CURDATE(), 'pending', ?)
                ")->execute([
                    $companyId,
                    $leaseId,
                    $instId,
                    $chequeNo,
                    $chequeDate,
                    $chequeAmount,
                    $tenantName,
                    $createdBy
                ]);

                $conn->prepare("
                    INSERT INTO re_lease_cheques
                        (company_id, lease_id, installment_id, cheque_number, cheque_date, cheque_amount,
                         cheque_holder_name, payment_method, cheque_photo_path, status)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, 'cheque', NULL, 'pending')
                ")->execute([
                    $companyId,
                    $leaseId,
                    $instId,
                    $chequeNo,
                    $chequeDate,
                    $chequeAmount,
                    $tenantName
                ]);

                $idx++;
            }
        }
    }
}
