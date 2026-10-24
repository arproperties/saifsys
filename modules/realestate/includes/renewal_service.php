<?php
/**
 * Lease renewal: the parts both the renewal pages and Jarvis (Reem) run.
 *
 *   re_renewal_create()  - a renewal workflow and its draft lease, from an active lease.
 *                          Was the "initiate" block of lease_renewal_workflow.php; the page
 *                          still does exactly this, through here. It does not begin or
 *                          commit a transaction: the caller does, so a quote can roll it back.
 *   re_renewal_quote()   - what a renewal at a given rent would be, cheque by cheque, as
 *                          saifsys itself would make it. Everything is rolled back.
 *   re_renewal_convert() - Approve & Convert. Was the block in lease_renewal_workflow_view.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/lease_vat_calculator.php';
require_once __DIR__ . '/lease_number_sequence.php';
require_once __DIR__ . '/lease_installments_generate.php';
require_once __DIR__ . '/accounting_mode_helper.php';
require_once __DIR__ . '/obligation_engine.php';
require_once __DIR__ . '/invoice_engine.php';
require_once __DIR__ . '/../../../includes/renewal_workflow_transitions.php';

if (!function_exists('re_renewal_default_terms')) {
    function re_renewal_default_terms(): string
    {
        return implode("\n", [
            'If the tenant wishes to terminate the contract on expiry and the tenant has not served 60 days prior written notice to the Landlord/Agent; the tenants are liable to pay outstanding rental dues and three months rental penalty from the date of key handover.',
            'If the tenant fails to renew the tenancy contract on or before its expiration, the contract will be automatically terminated, and the tenant will be deemed to have vacated the premises. Upon the expiration of the tenancy contract, the tenant is granted a grace period of 3 days to vacate the premises. During this period, the tenant must ensure that the unit is returned in good condition, as specified in the contract.',
            'In any case where the tenant does not vacate the premises or fails to renew the contract on time, a late renewal fee of 1,000 AED will be applicable. This fee will be due immediately and must be paid in full to proceed with the renewal or allow the tenant to remain in the property.',
            'In the event of non-renewal or failure to vacate, the landlord reserves the right to initiate further actions, including but not limited to, filing with the Notary Public or initiating an RDC case.',
            'Please note that the additional parking slot is not included in the rent amount provided.'
        ]);
    }
}

if (!function_exists('re_renewal_default_documents')) {
    function re_renewal_default_documents(): string
    {
        return implode("\n", [
            'Valid Occupant passport copy & visa page (Residential)',
            'Valid cheque signatory Passport copy (Residential/Commercial)',
            'Valid Trade license copy (Applicable Tenancy in the name of company or Commercial)',
            'Valid Owner/signatory passport copy (Commercial)',
            'Valid sponsor passport copy (Commercial)',
            'L.L.C agreement (If applicable)',
            'Power of attorney (If applicable)'
        ]);
    }
}

if (!function_exists('re_renewal_lines')) {
    /** Text with one item per line: blank lines and surrounding spaces taken out. */
    function re_renewal_lines(?string $text): string
    {
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }
        $clean = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }
        return implode("\n", $clean);
    }
}

if (!function_exists('re_renewal_create')) {
    /**
     * A renewal workflow (status initiated) and its draft lease. $in holds what the
     * "Initiate renewal" form holds; anything left out takes the form's default.
     *
     *   increase_type 'percentage'|'fixed', increase_value, proposed_rent (wins when set),
     *   admin_fees, chiller_charges, number_of_cheques, proposed_start_date, proposed_end_date,
     *   target_renewal_date, assigned_to, additional_parking_enabled/_fee, small_store_enabled/_fee,
     *   big_store_enabled/_fee, terms_text, required_documents_text, stamp_image_path,
     *   carry_ejari (default true: the draft keeps the old lease's Ejari number, as it always has).
     *
     * @return array{workflow_id:int,new_lease_id:int,current_rent:float,proposed_rent:float,grand_total:float,rera:float,start_date:string,end_date:string,number_of_cheques:int,terms_text:string,required_documents_text:string}
     */
    function re_renewal_create(PDO $conn, int $companyId, int $userId, int $leaseId, array $in): array
    {
        $num = static fn(string $k): ?float => (isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null) ? (float)$in[$k] : null;

        $increaseType = in_array($in['increase_type'] ?? 'percentage', ['percentage', 'fixed'], true) ? $in['increase_type'] : 'percentage';
        $increaseValue = (float)($num('increase_value') ?? 0.0);
        $overrideProposedRent = !empty($in['proposed_rent']) ? (float)$in['proposed_rent'] : null;
        $adminFees = !empty($in['admin_fees']) ? (float)$in['admin_fees'] : null;
        $chillerFeeInput = $num('chiller_charges');
        $numberOfChequesInput = !empty($in['number_of_cheques']) ? (int)$in['number_of_cheques'] : 0;
        $additionalParkingEnabled = !empty($in['additional_parking_enabled']) ? 1 : 0;
        $additionalParkingFee = !empty($in['additional_parking_fee']) ? (float)$in['additional_parking_fee'] : 0.0;
        $smallStoreEnabled = !empty($in['small_store_enabled']) ? 1 : 0;
        $smallStoreFee = !empty($in['small_store_fee']) ? (float)$in['small_store_fee'] : 0.0;
        $bigStoreEnabled = !empty($in['big_store_enabled']) ? 1 : 0;
        $bigStoreFee = !empty($in['big_store_fee']) ? (float)$in['big_store_fee'] : 0.0;
        $termsTextInput = re_renewal_lines($in['terms_text'] ?? '');
        $requiredDocsInput = re_renewal_lines($in['required_documents_text'] ?? '');
        $proposedStartDate = !empty($in['proposed_start_date']) ? (string)$in['proposed_start_date'] : null;
        $proposedEndDate = !empty($in['proposed_end_date']) ? (string)$in['proposed_end_date'] : null;
        $targetRenewalDate = !empty($in['target_renewal_date']) ? (string)$in['target_renewal_date'] : null;
        $assignedTo = !empty($in['assigned_to']) ? (int)$in['assigned_to'] : null;
        $stampRelativePath = !empty($in['stamp_image_path']) ? (string)$in['stamp_image_path'] : null;
        $carryEjari = !array_key_exists('carry_ejari', $in) || !empty($in['carry_ejari']);

        // Load old lease + property context
        $stmtOld = $conn->prepare("
            SELECT l.*,
                   u.unit_number, u.unit_type, u.premises_number,
                   u.building_id,
                   b.name AS building_name,
                   bs.template_code,
                   COALESCE(bs.show_chiller_row, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS bs_show_chiller_row,
                   COALESCE(bs.show_chiller_term, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS bs_show_chiller_term,
                   COALESCE(bs.admin_fee_label, 'Lease Renewal Charges') AS bs_admin_fee_label,
                   COALESCE(bs.default_parking_fee, 0) AS bs_default_parking_fee,
                   COALESCE(bs.default_small_store_fee, 0) AS bs_default_small_store_fee,
                   COALESCE(bs.default_big_store_fee, 0) AS bs_default_big_store_fee,
                   COALESCE(bs.vat_enabled, 1) AS bs_vat_enabled,
                   COALESCE(bs.vat_rate, 5.00) AS bs_vat_rate,
                   COALESCE(bs.show_grand_total_row, 0) AS bs_show_grand_total_row,
                   bs.terms_text AS bs_terms_text,
                   bs.required_documents_text AS bs_required_documents_text
            FROM re_leases l
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            LEFT JOIN re_renewal_notice_building_settings bs ON bs.company_id = l.company_id AND bs.building_id = u.building_id
            WHERE l.id = ? AND l.company_id = ?
        ");
        $stmtOld->execute([$leaseId, $companyId]);
        $oldLease = $stmtOld->fetch(PDO::FETCH_ASSOC);
        if (!$oldLease) {
            throw new Exception("Lease not found");
        }

        $tenantId = (int)$oldLease['tenant_id'];
        $unitId   = (int)$oldLease['unit_id'];
        $buildingId = (int)($oldLease['building_id'] ?? 0);

        // current_rent is annual base rent (fallback: monthly_rent * 12)
        $currentRent = $oldLease['annual_rent'] !== null && $oldLease['annual_rent'] !== ''
            ? (float)$oldLease['annual_rent']
            : (float)$oldLease['monthly_rent'] * 12;

        // Calculate new rent
        if ($increaseType === 'percentage') {
            $calculatedNewRent = $currentRent + ($currentRent * ($increaseValue / 100.0));
        } else {
            $calculatedNewRent = $currentRent + $increaseValue;
        }
        $calculatedNewRent = round($calculatedNewRent, 2);

        // Manual override (if admin filled proposed_rent)
        $proposedRent = $overrideProposedRent !== null ? round($overrideProposedRent, 2) : $calculatedNewRent;

        // Proposed dates defaults: same duration, starting 1 day after old end
        if (!$proposedStartDate) {
            $dt = new DateTime($oldLease['end_date']);
            $dt->modify('+1 day');
            $proposedStartDate = $dt->format('Y-m-d');
        }
        if (!$proposedEndDate) {
            $oldStart = new DateTime($oldLease['start_date']);
            $oldEnd   = new DateTime($oldLease['end_date']);
            $durationDays = $oldEnd->diff($oldStart)->days;
            $dtEnd = new DateTime($proposedStartDate);
            $dtEnd->modify('+' . $durationDays . ' days');
            $proposedEndDate = $dtEnd->format('Y-m-d');
        }

        // Building-driven settings (SQL uses Park Place fallback when no bs row — same as expiring-lease list)
        $showChillerRow = (int)($oldLease['bs_show_chiller_row'] ?? 0);
        $showChillerTerm = (int)($oldLease['bs_show_chiller_term'] ?? 0);
        $adminFeeLabel = trim((string)($oldLease['bs_admin_fee_label'] ?? 'Lease Renewal Charges'));
        if ($adminFeeLabel === '') {
            $adminFeeLabel = 'Lease Renewal Charges';
        }
        $vatEnabled = (int)($oldLease['bs_vat_enabled'] ?? 1);
        $vatRate = (float)($oldLease['bs_vat_rate'] ?? 5.0);
        if ($vatRate <= 0) {
            $vatRate = 5.0;
        }
        $templateCode = trim((string)($oldLease['template_code'] ?? 'renewal_notice_unified_v1'));
        if ($templateCode === '') {
            $templateCode = 'renewal_notice_unified_v1';
        }
        $oldChiller = !empty($oldLease['chiller_fees']) ? (float)$oldLease['chiller_fees'] : 0.0;
        $chillerCharges = $showChillerRow ? ($chillerFeeInput !== null ? $chillerFeeInput : $oldChiller) : 0.0;

        // Admin fees: admin input wins, otherwise keep existing
        $oldAdmin = !empty($oldLease['admin_fees']) ? (float)$oldLease['admin_fees'] : 0.0;
        $adminFeesFinal = $adminFees !== null ? (float)$adminFees : $oldAdmin;

        if (!$additionalParkingEnabled) {
            $additionalParkingFee = 0.0;
        }
        if (!$smallStoreEnabled) {
            $smallStoreFee = 0.0;
        }
        if (!$bigStoreEnabled) {
            $bigStoreFee = 0.0;
        }

        // VAT base (extras, excluding rent & Ejari) + optional VAT on rent (commercial / shop).
        $vatBase = $adminFeesFinal + $chillerCharges + $additionalParkingFee + $smallStoreFee + $bigStoreFee;
        $rera = 300.0;
        if ($buildingId > 0) {
            $rsRera = $conn->prepare("SELECT COALESCE(rera_charges, 300) AS rera FROM re_renewal_notice_building_settings WHERE company_id = ? AND building_id = ? LIMIT 1");
            $rsRera->execute([$companyId, $buildingId]);
            $rRera = $rsRera->fetch(PDO::FETCH_ASSOC);
            if ($rRera && isset($rRera['rera'])) {
                $rera = (float)$rRera['rera'];
            }
        }
        $vatApplicableOnRent = !empty($oldLease['vat_applicable_on_rent'])
            ? 1
            : (lease_vat_unit_is_commercial((string)($oldLease['unit_type'] ?? '')) ? 1 : 0);
        $vatDist = lease_vat_normalize_distribution($oldLease['vat_distribution_type'] ?? 'first_installment');
        // Reem's renewals (reem_cheques): one cheque per installment, the chiller split over
        // them all, and every fee, the VAT and the Ejari fee in the first.
        $reemCheques = !empty($in['reem_cheques']);
        if ($reemCheques) {
            $vatDist = 'first_installment';
        }
        $vatCalc = lease_vat_compute_amounts([
            'annual_rent' => $proposedRent,
            'vat_applicable_on_rent' => $vatApplicableOnRent,
            'vat_applicable_on_extra_charges' => $vatEnabled,
            'lease_vat_rate' => $vatRate,
            'extra_charges_base' => $vatBase,
        ]);
        $vatExtra = $vatCalc['extra_vat'];
        $rentVat = $vatCalc['rent_vat'];

        $numberOfCheques = $numberOfChequesInput > 0 ? $numberOfChequesInput : (int)($oldLease['number_of_installments'] ?? 4);
        if ($numberOfCheques <= 0) {
            $numberOfCheques = 4;
        }

        $termsTextFinal = $termsTextInput !== '' ? $termsTextInput : re_renewal_lines((string)($oldLease['bs_terms_text'] ?? re_renewal_default_terms()));
        $requiredDocsFinal = $requiredDocsInput !== '' ? $requiredDocsInput : re_renewal_lines((string)($oldLease['bs_required_documents_text'] ?? re_renewal_default_documents()));
        $showGrandTotalRow = (int)($oldLease['bs_show_grand_total_row'] ?? 0);
        $grandTotal = round($proposedRent + $vatBase + $rentVat + $vatExtra + $rera, 2);

        // If target date not provided, use proposed start date.
        if (!$targetRenewalDate) {
            $targetRenewalDate = $proposedStartDate;
        }

        // 1) Insert renewal workflow
        $stmt = $conn->prepare("
            INSERT INTO re_lease_renewal_workflows
            (
                lease_id,
                tenant_id,
                unit_id,
                status,
                workflow_step,
                initiated_date,
                target_renewal_date,
                current_rent,
                increase_type,
                increase_value,
                calculated_new_rent,
                proposed_rent,
                chiller_charges,
                admin_fees,
                vat_extra_charges,
                template_code,
                building_id,
                show_chiller_row,
                show_chiller_term,
                admin_fee_label,
                vat_enabled,
                vat_rate,
                additional_parking_enabled,
                additional_parking_fee,
                small_store_enabled,
                small_store_fee,
                big_store_enabled,
                big_store_fee,
                number_of_cheques,
                terms_text,
                required_documents_text,
                stamp_image_path,
                show_grand_total_row,
                grand_total_amount,
                proposed_start_date,
                proposed_end_date,
                assigned_to,
                created_by,
                rent_vat_amount,
                vat_applicable_on_rent,
                vat_distribution_type,
                vat_notes
            )
            VALUES
            (?, ?, ?, 'initiated', 'initiated', CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $leaseId,
            $tenantId,
            $unitId,
            $targetRenewalDate,
            $currentRent,
            $increaseType,
            $increaseValue,
            $calculatedNewRent,
            $proposedRent,
            $chillerCharges,
            $adminFeesFinal,
            $vatExtra,
            $templateCode,
            $buildingId,
            $showChillerRow,
            $showChillerTerm,
            $adminFeeLabel,
            $vatEnabled,
            $vatRate,
            $additionalParkingEnabled,
            $additionalParkingFee,
            $smallStoreEnabled,
            $smallStoreFee,
            $bigStoreEnabled,
            $bigStoreFee,
            $numberOfCheques,
            $termsTextFinal,
            $requiredDocsFinal,
            $stampRelativePath,
            $showGrandTotalRow,
            $grandTotal,
            $proposedStartDate,
            $proposedEndDate,
            $assignedTo,
            $userId,
            $rentVat,
            $vatApplicableOnRent ? 1 : 0,
            $vatDist,
            null,
        ]);
        $workflowNewId = (int)$conn->lastInsertId();

        // Rent-change audit history
        $h = $conn->prepare("
            INSERT INTO re_lease_renewal_rent_history
                (company_id, workflow_id, lease_id, old_rent, increase_type, increase_value, calculated_new_rent, override_new_rent, changed_by, notes)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $h->execute([
            $companyId,
            $workflowNewId,
            $leaseId,
            (float)$currentRent,
            $increaseType,
            (float)$increaseValue,
            (float)$calculatedNewRent,
            (float)$proposedRent,
            $userId,
            'Initial renewal initiation'
        ]);

        // 2) Create draft lease and link to parent lease (next lease # = max suffix + 1 for this unit/prefix)
        $newLeaseNumber = lease_generate_number_for_unit($conn, $companyId, $unitId);
        if ($newLeaseNumber === null || $newLeaseNumber === '') {
            throw new Exception('Could not generate a draft lease number for this unit.');
        }

        // monthly_rent per installment (lease_add uses annual_rent / number_of_installments)
        $numberOfInstallments = $numberOfCheques > 0 ? $numberOfCheques : (!empty($oldLease['number_of_installments']) ? (int)$oldLease['number_of_installments'] : 12);
        $monthlyRentForInstallments = $numberOfInstallments > 0 ? round($proposedRent / $numberOfInstallments, 2) : round($proposedRent / 12.0, 2);

        $stmtInsLease = $conn->prepare("
            INSERT INTO re_leases
            (
                company_id, unit_id, tenant_id, lease_number,
                start_date, end_date,
                annual_rent, monthly_rent, number_of_installments,
                security_deposit,
                chiller_fees, ejari_fees, admin_fees, commission_fees,
                amc_amount, split_amc_fees,
                add_fees_to_first_installment,
                deferred_revenue_mode,
                is_renewal_lease,
                split_chiller_fees, split_ejari_fees, split_admin_fees, split_commission_fees,
                split_additional_parking, split_additional_store,
                has_additional_parking, additional_parking_fee, additional_parking_start_date, additional_parking_end_date,
                has_additional_store, additional_store_fee, additional_store_start_date, additional_store_end_date,
                payment_day, payment_method, grace_period_days,
                renewal_terms, template_id,
                ejari_registration_number, ejari_issue_date, ejari_property_code,
                landlord_signature_path, tenant_signature_path, company_stamp_path,
                status, notes, created_by,
                parent_lease_id,
                vat_applicable_on_rent,
                vat_applicable_on_extra_charges,
                lease_vat_rate,
                rent_vat_amount,
                extra_services_vat_amount,
                total_vat_amount,
                vat_distribution_type,
                vat_notes,
                is_multi_unit
            )
            VALUES
            (?, ?, ?, ?,
             ?, ?,
             ?, ?, ?,
             ?,
             ?, ?, ?, ?,
             ?, ?,
             ?,
             ?,
             1,
             ?, ?, ?, ?,
             ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?,
             ?, ?,
             ?, ?, ?,
             NULL, NULL, NULL,
             'draft', ?, ?,
             ?,
             ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmtInsLease->execute([
            $companyId,
            $unitId,
            $tenantId,
            $newLeaseNumber,
            $proposedStartDate,
            $proposedEndDate,
            $proposedRent,
            $monthlyRentForInstallments,
            $numberOfInstallments,
            (float)($oldLease['security_deposit'] ?? 0),
            $chillerCharges,
            $reemCheques ? (float)$rera : (float)($oldLease['ejari_fees'] ?? 0),
            $adminFeesFinal,
            0.00,
            (float)($oldLease['amc_amount'] ?? 0),
            (int)($oldLease['split_amc_fees'] ?? 0),
            $reemCheques ? 1 : (int)($oldLease['add_fees_to_first_installment'] ?? 1),
            (int)($oldLease['deferred_revenue_mode'] ?? 1),
            $reemCheques ? 1 : (int)($oldLease['split_chiller_fees'] ?? 0),
            $reemCheques ? 0 : (int)($oldLease['split_ejari_fees'] ?? 0),
            $reemCheques ? 0 : (int)($oldLease['split_admin_fees'] ?? 0),
            0,
            $reemCheques ? 0 : (int)($oldLease['split_additional_parking'] ?? 0),
            $reemCheques ? 0 : (int)($oldLease['split_additional_store'] ?? 0),
            $additionalParkingEnabled,
            $additionalParkingEnabled ? $additionalParkingFee : 0.0,
            $additionalParkingEnabled ? $proposedStartDate : null,
            $additionalParkingEnabled ? $proposedEndDate : null,
            ($smallStoreEnabled || $bigStoreEnabled) ? 1 : 0,
            (float)(($smallStoreEnabled ? $smallStoreFee : 0.0) + ($bigStoreEnabled ? $bigStoreFee : 0.0)),
            ($smallStoreEnabled || $bigStoreEnabled) ? $proposedStartDate : null,
            ($smallStoreEnabled || $bigStoreEnabled) ? $proposedEndDate : null,
            (int)($oldLease['payment_day'] ?? 1),
            $oldLease['payment_method'] ?? 'cheque',
            (int)($oldLease['grace_period_days'] ?? 0),
            $oldLease['renewal_terms'] ?? null,
            $oldLease['template_id'] ?? null,
            $carryEjari ? ($oldLease['ejari_registration_number'] ?? null) : null,
            $carryEjari ? ($oldLease['ejari_issue_date'] ?? null) : null,
            $oldLease['ejari_property_code'] ?? null,
            $oldLease['notes'] ?? null,
            $userId,
            $leaseId,
            $vatApplicableOnRent ? 1 : 0,
            $vatEnabled ? 1 : 0,
            $vatRate,
            $rentVat,
            $vatExtra,
            $vatCalc['total_vat'],
            $vatDist,
            null,
            (int)($oldLease['is_multi_unit'] ?? 0)
        ]);

        $newLeaseId = (int)$conn->lastInsertId();

        // Link workflow -> new lease
        $conn->prepare("UPDATE re_lease_renewal_workflows SET new_lease_id = ? WHERE id = ?")
            ->execute([$newLeaseId, $workflowNewId]);

        // Installments are generated only on "Approve & Convert" so negotiated amounts can change first.

        return [
            'workflow_id' => $workflowNewId,
            'new_lease_id' => $newLeaseId,
            'current_rent' => (float)$currentRent,
            'proposed_rent' => (float)$proposedRent,
            'grand_total' => (float)$grandTotal,
            'rera' => (float)$rera,
            'start_date' => (string)$proposedStartDate,
            'end_date' => (string)$proposedEndDate,
            'number_of_cheques' => (int)$numberOfInstallments,
            'terms_text' => (string)$termsTextFinal,
            'required_documents_text' => (string)$requiredDocsFinal,
        ];
    }
}

if (!function_exists('re_renewal_schedule')) {
    /**
     * The payments of a lease as saifsys makes them on convert: one row per installment,
     * which is one cheque each when the lease is paid by cheque.
     *
     * @return list<array{date:string,amount:float,type:string}>
     */
    function re_renewal_schedule(PDO $conn, int $companyId, int $leaseId, int $userId, bool $reemCheques = false): array
    {
        generateLeaseInstallmentsForConversion($conn, $companyId, $leaseId, $userId);
        if ($reemCheques) {
            re_renewal_reem_cheques($conn, $companyId, $leaseId);
        }
        $st = $conn->prepare("SELECT installment_date, amount, installment_type FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ? ORDER BY installment_date ASC, id ASC");
        $st->execute([$leaseId, $companyId]);
        return array_map(static fn($r) => [
            'date' => (string)$r['installment_date'],
            'amount' => round((float)$r['amount'], 2),
            'type' => (string)($r['installment_type'] ?? '') ?: 'rent',
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
}

if (!function_exists('re_renewal_reem_cheques')) {
    /**
     * The cheques of a renewal Reem runs, set right after saifsys has made them (with the
     * fees in the first): the rent and the chiller each split in whole dirhams, what is left
     * over going in the first cheque with the fees, the VAT and the Ejari fee; and the dates
     * the same day of the month, evenly apart (every 2 months for 6 cheques), never the
     * lease's last day. The cheque rows follow their installments. Totals do not change.
     */
    function re_renewal_reem_cheques(PDO $conn, int $companyId, int $leaseId): void
    {
        $st = $conn->prepare("SELECT start_date, end_date, annual_rent, chiller_fees, split_chiller_fees, add_fees_to_first_installment
            FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
        $st->execute([$leaseId, $companyId]);
        $lease = $st->fetch(PDO::FETCH_ASSOC);
        if (!$lease || empty($lease['add_fees_to_first_installment'])) {
            return;
        }
        $st = $conn->prepare("SELECT id, amount FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ? AND (installment_type IS NULL OR installment_type = '' OR installment_type = 'rent')
            ORDER BY installment_date ASC, id ASC");
        $st->execute([$leaseId, $companyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $n = count($rows);
        if ($n < 1) {
            return;
        }

        $rent = round((float)$lease['annual_rent'], 2);
        $chiller = !empty($lease['split_chiller_fees']) ? round((float)$lease['chiller_fees'], 2) : 0.0;
        $all = round(array_sum(array_map(static fn($r) => (float)$r['amount'], $rows)), 2);
        $fees = round($all - $rent - $chiller, 2); // the fees, VAT and Ejari saifsys put in the first
        $per = floor($rent / $n) + floor($chiller / $n);
        $amounts = array_fill(0, $n, (float)$per);
        $amounts[0] = round($rent + $chiller - $per * ($n - 1) + $fees, 2);

        // Evenly apart by whole months when the term divides into them; otherwise saifsys's dates.
        $start = new DateTime((string)$lease['start_date']);
        $end = (new DateTime((string)$lease['end_date']))->modify('+1 day');
        $months = ((int)$end->format('Y') - (int)$start->format('Y')) * 12 + (int)$end->format('n') - (int)$start->format('n');
        $step = ($months > 0 && $months % $n === 0) ? intdiv($months, $n) : 0;
        $day = (int)$start->format('j');

        $setInst = $conn->prepare("UPDATE re_lease_installments SET amount = ?" . ($step ? ", installment_date = ?" : '') . " WHERE id = ? AND company_id = ?");
        $setPdc = $conn->prepare("UPDATE re_post_dated_cheques SET cheque_amount = ?" . ($step ? ", cheque_date = ?" : '') . " WHERE installment_id = ? AND company_id = ?");
        $setChq = $conn->prepare("UPDATE re_lease_cheques SET cheque_amount = ?" . ($step ? ", cheque_date = ?" : '') . " WHERE installment_id = ? AND company_id = ?");
        foreach ($rows as $i => $row) {
            $args = [$amounts[$i]];
            if ($step) {
                $m = (int)$start->format('n') - 1 + $i * $step;
                $y = (int)$start->format('Y') + intdiv($m, 12);
                $m = $m % 12 + 1;
                $last = (int)(new DateTime(sprintf('%04d-%02d-01', $y, $m)))->format('t');
                $args[] = sprintf('%04d-%02d-%02d', $y, $m, min($day, $last));
            }
            $args[] = (int)$row['id'];
            $args[] = $companyId;
            $setInst->execute($args);
            $setPdc->execute($args);
            $setChq->execute($args);
        }
    }
}

if (!function_exists('re_renewal_quote')) {
    /**
     * What re_renewal_create() and then Approve & Convert would make, with nothing kept:
     * it is all done inside a transaction that is rolled back.
     */
    function re_renewal_quote(PDO $conn, int $companyId, int $userId, int $leaseId, array $in): array
    {
        if ($conn->inTransaction()) {
            throw new Exception('A quote cannot be made inside another transaction.');
        }
        $conn->beginTransaction();
        try {
            $made = re_renewal_create($conn, $companyId, $userId, $leaseId, $in);
            $cheques = re_renewal_schedule($conn, $companyId, $made['new_lease_id'], $userId, !empty($in['reem_cheques']));
        } finally {
            $conn->rollBack();
        }
        unset($made['workflow_id'], $made['new_lease_id']);
        $made['cheques'] = $cheques;
        $made['cheques_total'] = round(array_sum(array_column($cheques, 'amount')), 2);
        return $made;
    }
}

if (!function_exists('re_renewal_convert')) {
    /**
     * Approve & Convert: the renewal lease's schedule, obligations and invoice candidates are
     * made now; it becomes active at once if its start date has come, else it waits for it.
     * $mayOverride: the person may convert from any status (Owner/Admin).
     *
     * @return array{activate_now:bool,old_lease_id:int,new_lease_id:int,admin_override:bool,start_date:string,message:string}
     */
    function re_renewal_convert(PDO $conn, int $companyId, int $userId, int $workflowId, bool $mayOverride = false, bool $reemCheques = false): array
    {
        $st = $conn->prepare("SELECT status, lease_id, new_lease_id FROM re_lease_renewal_workflows WHERE id = ? LIMIT 1");
        $st->execute([$workflowId]);
        $wf = $st->fetch(PDO::FETCH_ASSOC);
        if (!$wf) {
            throw new Exception('Renewal workflow not found.');
        }
        $apSt = (string)$wf['status'];
        $isAdminOverride = !renewal_wf_can_approve_convert($apSt) && $mayOverride;
        if (!renewal_wf_can_approve_convert($apSt) && !$isAdminOverride) {
            throw new Exception('Approve & Convert is only allowed after tenant acceptance/signature, unless the current user is Owner/Admin.');
        }
        if (empty($wf['new_lease_id'])) {
            throw new Exception("New lease is not linked. Cannot convert.");
        }
        $oldLeaseId = (int)$wf['lease_id'];
        $newLeaseId = (int)$wf['new_lease_id'];

        $leaseCheck = $conn->prepare("SELECT id, status, start_date, accounting_mode FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
        $leaseCheck->execute([$newLeaseId, $companyId]);
        $newLeaseRow = $leaseCheck->fetch(PDO::FETCH_ASSOC);
        if (!$newLeaseRow) {
            throw new Exception('Linked renewal lease was not found.');
        }
        if (!in_array((string)$newLeaseRow['status'], ['draft', 'active'], true)) {
            throw new Exception('Linked renewal lease is not in a convertible state.');
        }

        $newStartDate = !empty($newLeaseRow['start_date']) ? date('Y-m-d', strtotime((string)$newLeaseRow['start_date'])) : date('Y-m-d');
        $activateNow = $newStartDate <= date('Y-m-d');

        $conn->beginTransaction();
        try {
            // Renewals: no commission (admin/renewal fees only); reset if draft was edited manually.
            if (function_exists('re_obligation_column_exists') && re_obligation_column_exists($conn, 're_leases', 'accounting_mode')) {
                $conn->prepare("UPDATE re_leases SET commission_fees = 0, split_commission_fees = 0, accounting_mode = 'invoice' WHERE id = ? AND company_id = ?")
                    ->execute([$newLeaseId, $companyId]);
            } else {
                $conn->prepare("UPDATE re_leases SET commission_fees = 0, split_commission_fees = 0 WHERE id = ? AND company_id = ?")
                    ->execute([$newLeaseId, $companyId]);
            }

            // Operational schedule/cheques can be prepared in advance, but the lease remains draft until start date.
            generateLeaseInstallmentsForConversion($conn, $companyId, $newLeaseId, $userId);
            if ($reemCheques) {
                re_renewal_reem_cheques($conn, $companyId, $newLeaseId);
            }

            // Invoice Mode: prepare obligations and approved invoice candidates from the staged lease.
            if (function_exists('re_obligation_engine_generate_for_lease')) {
                $obRes = re_obligation_engine_generate_for_lease($conn, $companyId, $newLeaseId, $userId);
                if (empty($obRes['success'])) {
                    throw new Exception($obRes['error'] ?? 'Could not prepare renewal lease obligations.');
                }
            }
            if (function_exists('re_invoice_engine_prepare_candidates_for_lease')) {
                $candRes = re_invoice_engine_prepare_candidates_for_lease($conn, $companyId, $newLeaseId, $userId);
                if (empty($candRes['success'])) {
                    throw new Exception($candRes['error'] ?? 'Could not prepare renewal invoice candidates.');
                }
            }

            if ($activateNow) {
                $conn->prepare("UPDATE re_leases SET status = 'active', updated_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute([$newLeaseId, $companyId]);
                $conn->prepare("UPDATE re_leases SET status = 'expired', move_out_date = COALESCE(move_out_date, end_date), updated_at = NOW() WHERE id = ? AND company_id = ? AND status = 'active'")
                    ->execute([$oldLeaseId, $companyId]);
            } else {
                $conn->prepare("UPDATE re_leases SET status = 'draft', updated_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute([$newLeaseId, $companyId]);
            }

            $u = $conn->prepare("
                UPDATE re_lease_renewal_workflows
                SET status = 'converted',
                    approved_by = ?,
                    converted_by = ?,
                    workflow_step = 'completed'
                WHERE id = ? AND status = ?
            ");
            $u->execute([$userId, $userId, $workflowId, $apSt]);
            if ($u->rowCount() === 0) {
                throw new Exception('Could not convert workflow (status may have changed).');
            }

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        if (!$activateNow && function_exists('re_accounting_log_mode_change')) {
            re_accounting_log_mode_change($conn, $companyId, $newLeaseId, (string)($newLeaseRow['accounting_mode'] ?? 'legacy'), 'invoice', $userId, 'Renewal staged in Invoice Mode until start date.', 'renewal_workflow_convert');
        }
        renewal_wf_log_admin_event(
            $conn,
            $workflowId,
            $companyId,
            $userId,
            $isAdminOverride ? 'owner_admin_override_convert' : 'admin_approve_convert',
            $isAdminOverride
                ? ($activateNow ? 'Owner/Admin override: renewal activated immediately.' : 'Owner/Admin override: renewal staged until start date.')
                : ($activateNow ? 'Renewal approved; start date reached, new lease activated and old lease expired.' : 'Renewal approved; new lease staged until start date and old lease remains active.')
        );

        // Tenant in-app notification: renewal completed/converted
        if (is_file(__DIR__ . '/../../../includes/tenant_notifications.php')) require_once __DIR__ . '/../../../includes/tenant_notifications.php';
        if (function_exists('tenant_notification_create')) tenant_notification_create($conn, [
            'company_id' => $companyId,
            'lease_id' => $newLeaseId > 0 ? $newLeaseId : $oldLeaseId,
            'type' => 'renewal_status',
            'entity_type' => 'renewal',
            'entity_id' => (int)$workflowId,
            'title' => $activateNow ? 'Lease renewed' : 'Lease renewal approved',
            'body' => $activateNow ? 'Your lease renewal is complete. Your new lease is now active.' : 'Your lease renewal is approved. The new lease will become active on its start date.',
        ]);

        $message = $isAdminOverride
            ? ($activateNow ? "Renewal converted by Owner/Admin override (new ACTIVE, old EXPIRED)." : "Renewal approved by Owner/Admin override and staged. The old lease remains active until {$newStartDate}.")
            : ($activateNow ? "Renewal approved and leases converted (new ACTIVE, old EXPIRED)." : "Renewal approved and staged. The old lease remains active until the new lease start date ({$newStartDate}).");

        return [
            'activate_now' => (bool)$activateNow,
            'old_lease_id' => $oldLeaseId,
            'new_lease_id' => $newLeaseId,
            'admin_override' => (bool)$isAdminOverride,
            'start_date' => $newStartDate,
            'message' => $message,
        ];
    }
}

if (!function_exists('re_renewal_set_rent')) {
    /**
     * A new rent (and number of cheques) on a renewal not yet converted, as the renewal page's
     * "Save renewal amounts" does: the workflow's totals and VAT worked out again from its own
     * charges, the rent history noted, and the draft lease synced. Nothing else changes.
     *
     * @return array{proposed_rent:float,grand_total:float,number_of_cheques:int}
     */
    function re_renewal_set_rent(PDO $conn, int $companyId, int $userId, int $workflowId, float $rent, int $cheques, string $note = ''): array
    {
        $st = $conn->prepare("SELECT * FROM re_lease_renewal_workflows WHERE id = ? LIMIT 1");
        $st->execute([$workflowId]);
        $wf = $st->fetch(PDO::FETCH_ASSOC);
        if (!$wf) {
            throw new Exception('Renewal workflow not found.');
        }
        if (!renewal_wf_can_save_renewal_amounts((string)$wf['status'])) {
            throw new Exception("The renewal is {$wf['status']}: its rent can no longer change.");
        }
        $rent = round($rent, 2);
        if ($rent <= 0 || $cheques < 1 || $cheques > 12) {
            throw new Exception('The rent must be above 0 and the cheques 1 to 12.');
        }

        $vatBase = (float)$wf['admin_fees'] + (float)$wf['chiller_charges'] + (float)$wf['additional_parking_fee']
            + (float)$wf['small_store_fee'] + (float)$wf['big_store_fee'];
        $vatRate = (float)$wf['vat_rate'] > 0 ? (float)$wf['vat_rate'] : 5.0;
        $vatCalc = lease_vat_compute_amounts([
            'annual_rent' => $rent,
            'vat_applicable_on_rent' => (int)$wf['vat_applicable_on_rent'] === 1,
            'vat_applicable_on_extra_charges' => (int)$wf['vat_enabled'] === 1,
            'lease_vat_rate' => $vatRate,
            'extra_charges_base' => $vatBase,
        ]);
        $rera = 300.0;
        if ((int)$wf['building_id'] > 0) {
            $rs = $conn->prepare("SELECT COALESCE(rera_charges, 300) AS rera FROM re_renewal_notice_building_settings WHERE company_id = ? AND building_id = ? LIMIT 1");
            $rs->execute([$companyId, (int)$wf['building_id']]);
            $r = $rs->fetch(PDO::FETCH_ASSOC);
            if ($r && isset($r['rera'])) {
                $rera = (float)$r['rera'];
            }
        }
        $grandTotal = round($rent + $vatBase + $vatCalc['rent_vat'] + $vatCalc['extra_vat'] + $rera, 2);
        $current = (float)$wf['current_rent'];

        $own = !$conn->inTransaction();
        if ($own) {
            $conn->beginTransaction();
        }
        try {
            $conn->prepare("UPDATE re_lease_renewal_workflows
                SET increase_type = 'fixed', increase_value = ?, proposed_rent = ?, rent_vat_amount = ?, vat_extra_charges = ?,
                    number_of_cheques = ?, grand_total_amount = ?
                WHERE id = ?")
                ->execute([round($rent - $current, 2), $rent, $vatCalc['rent_vat'], $vatCalc['extra_vat'], $cheques, $grandTotal, $workflowId]);
            $conn->prepare("INSERT INTO re_lease_renewal_rent_history
                    (company_id, workflow_id, lease_id, old_rent, increase_type, increase_value, calculated_new_rent, override_new_rent, changed_by, notes)
                VALUES (?, ?, ?, ?, 'fixed', ?, ?, ?, ?, ?)")
                ->execute([$companyId, $workflowId, (int)$wf['lease_id'], $current, round($rent - $current, 2), $rent, $rent, $userId,
                    $note !== '' ? mb_substr($note, 0, 500) : 'Rent changed']);
            if ((int)$wf['new_lease_id'] > 0) {
                $conn->prepare("DELETE FROM re_lease_installments WHERE lease_id = ? AND company_id = ?")->execute([(int)$wf['new_lease_id'], $companyId]);
                $conn->prepare("UPDATE re_leases
                    SET annual_rent = ?, monthly_rent = ?, number_of_installments = ?,
                        rent_vat_amount = ?, extra_services_vat_amount = ?, total_vat_amount = ?
                    WHERE id = ? AND company_id = ? AND status = 'draft'")
                    ->execute([$rent, round($rent / $cheques, 2), $cheques, $vatCalc['rent_vat'], $vatCalc['extra_vat'], $vatCalc['total_vat'],
                        (int)$wf['new_lease_id'], $companyId]);
            }
            if ($own) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
        return ['proposed_rent' => $rent, 'grand_total' => $grandTotal, 'number_of_cheques' => $cheques];
    }
}
