<?php
/**
 * Real Estate Module - Add/Edit Lease
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/lease_vat_calculator.php';
require_once __DIR__ . '/includes/lease_number_sequence.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';
require_once __DIR__ . '/includes/lease_installment_schedule.php';
require_once __DIR__ . '/includes/lease_schedule_engine.php';
require_once __DIR__ . '/includes/accounting_mode_helper.php';
require_once __DIR__ . '/includes/lease_payment_schedule_helper.php';
require_once __DIR__ . '/includes/invoice_engine.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$leaseId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$unitId = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
$tenantId = !empty($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;
$lease = null;
$success = '';
$error = '';
$formData = null; // Store POST data on error to preserve form values
$accountingModeSettings = re_accounting_mode_settings($conn);
$canOverrideAccountingMode = re_accounting_current_user_can_override_mode($conn);

// Get units and tenants
$units = $conn->prepare("
    SELECT u.id, u.unit_number, u.unit_type, u.status, b.name as building_name
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id
    WHERE u.company_id = ?
    ORDER BY b.name, u.unit_number
");
$units->execute([$currentCompanyId]);
$units = $units->fetchAll(PDO::FETCH_ASSOC);

// Include company_name and tenant_type so commercial tenants show company name
$tenants = $conn->prepare("
    SELECT id, tenant_type, company_name, first_name, last_name, phone, email 
    FROM re_tenants 
    WHERE company_id = ? AND is_active = 1 
    ORDER BY COALESCE(NULLIF(company_name,''), last_name), first_name
");
$tenants->execute([$currentCompanyId]);
$tenants = $tenants->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    // -----------------------------------------------------------------------
    // Multi-Unit Lease support
    // When is_multi_unit is set, we receive arrays of unit_ids + rents.
    // The first unit becomes the primary unit_id on re_leases;
    // annual_rent is the sum of all units' rents.
    // -----------------------------------------------------------------------
    $isMultiUnit = !empty($_POST['is_multi_unit']);
    $multiUnits  = []; // [['unit_id'=>X,'annual_rent'=>Y], ...]

    if ($isMultiUnit
        && !empty($_POST['multi_unit_ids'])
        && is_array($_POST['multi_unit_ids']))
    {
        $muIds   = $_POST['multi_unit_ids'];
        $muRents = $_POST['multi_unit_rents'] ?? [];
        foreach ($muIds as $k => $muid) {
            $muid   = (int)$muid;
            $mrent  = (float)($muRents[$k] ?? 0);
            if ($muid > 0 && $mrent >= 0) {
                $multiUnits[] = ['unit_id' => $muid, 'annual_rent' => $mrent];
            }
        }
    }

    if ($isMultiUnit && !empty($multiUnits)) {
        $unitId     = (int)$multiUnits[0]['unit_id'];
        // annual_rent derived from sum of all units — override POST value
        $_POST['annual_rent'] = array_sum(array_column($multiUnits, 'annual_rent'));
    } else {
        $unitId = (int)$_POST['unit_id'];
    }

    $tenantId = (int)$_POST['tenant_id'];
    $leaseNumber = trim($_POST['lease_number'] ?? '');

    // New lease: suggest next BuildingShort-Unit-NNNN (max suffix + 1, any status) so terminated
    // leases and uq_lease_number cannot collide with a stale client-side sequence.
    if (!$leaseId && $unitId) {
        $generated = lease_generate_number_for_unit($conn, $currentCompanyId, $unitId);
        $dupChk = $conn->prepare("SELECT 1 FROM re_leases WHERE company_id = ? AND lease_number = ? LIMIT 1");
        $dupChk->execute([$currentCompanyId, $leaseNumber]);
        $isDup = (bool)$dupChk->fetchColumn();
        if ($generated !== null && ($leaseNumber === '' || $isDup)) {
            $leaseNumber = $generated;
        }
    }
    
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $annualRent = !empty($_POST['annual_rent']) ? (float)$_POST['annual_rent'] : 0;
    $numberOfInstallments = !empty($_POST['number_of_installments']) ? (int)$_POST['number_of_installments'] : 12;
    $securityDeposit = !empty($_POST['security_deposit']) ? (float)$_POST['security_deposit'] : 0;
    $chillerFees = !empty($_POST['chiller_fees']) ? (float)$_POST['chiller_fees'] : 0;
    $ejariFees = !empty($_POST['ejari_fees']) ? (float)$_POST['ejari_fees'] : 0;
    $adminFees = !empty($_POST['admin_fees']) ? (float)$_POST['admin_fees'] : 0;
    $commissionFees = !empty($_POST['commission_fees']) ? (float)$_POST['commission_fees'] : 0;
    $amcAmount      = !empty($_POST['amc_amount'])      ? (float)$_POST['amc_amount']      : 0;
    $addFeesToFirstInstallment = !empty($_POST['add_fees_to_first_installment']);
    // Accrual / deferred revenue mode
    $deferredRevenueMode  = !empty($_POST['deferred_revenue_mode']);
    $isRenewalLease       = !empty($_POST['is_renewal_lease']);
    $accountingModeContext = $leaseId ? 'edit' : ($isRenewalLease ? 'renewal' : 'new');
    $existingAccountingMode = null;
    if ($leaseId) {
        try {
            $modeStmt = $conn->prepare("SELECT accounting_mode FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
            $modeStmt->execute([$leaseId, $currentCompanyId]);
            $existingAccountingMode = re_accounting_normalize_mode((string)$modeStmt->fetchColumn());
        } catch (Throwable $e) {
            $existingAccountingMode = 'legacy';
        }
    }
    $defaultAccountingMode = $accountingModeContext === 'edit'
        ? ($existingAccountingMode ?: 'legacy')
        : re_accounting_default_mode_for_context($conn, $accountingModeContext, date('Y-m-d'));
    $requestedAccountingMode = $canOverrideAccountingMode
        ? (string)($_POST['accounting_mode'] ?? $defaultAccountingMode)
        : $defaultAccountingMode;
    $accountingModeOverrideReason = trim((string)($_POST['accounting_mode_reason'] ?? ''));
    $modeValidation = re_accounting_validate_requested_mode(
        $conn,
        $currentCompanyId,
        $leaseId ? (int)$leaseId : null,
        $existingAccountingMode,
        $requestedAccountingMode,
        $accountingModeContext,
        date('Y-m-d'),
        $accountingModeOverrideReason
    );
    if (!$modeValidation['success']) {
        $error = $modeValidation['error'];
    }
    $accountingMode = $modeValidation['mode'];
    if ($accountingMode === 'invoice') {
        $deferredRevenueMode = false;
    }

    // 3-way fee distribution: 'split' | 'first' | 'separate'
    $validDist = ['split', 'first', 'separate'];
    $distChiller  = in_array($_POST['dist_chiller']     ?? '', $validDist) ? $_POST['dist_chiller']     : 'first';
    $distEjari    = in_array($_POST['dist_ejari']       ?? '', $validDist) ? $_POST['dist_ejari']       : 'first';
    $distAdmin    = in_array($_POST['dist_admin']       ?? '', $validDist) ? $_POST['dist_admin']       : 'first';
    $distComm     = in_array($_POST['dist_commission']  ?? '', $validDist) ? $_POST['dist_commission']  : 'first';
    $distAmc      = in_array($_POST['dist_amc']         ?? '', $validDist) ? $_POST['dist_amc']         : 'separate';
    $distParking  = in_array($_POST['dist_parking']     ?? '', $validDist) ? $_POST['dist_parking']     : 'first';
    $distStore    = in_array($_POST['dist_store']       ?? '', $validDist) ? $_POST['dist_store']       : 'first';
    // Security Deposit supports only 'first' (merge) or 'separate' (own cheque)
    $distSecurity = in_array($_POST['dist_security_deposit'] ?? '', ['first', 'separate'], true) ? $_POST['dist_security_deposit'] : 'first';
    $sepSecurityDeposit = ($distSecurity === 'separate');

    // Map to split_* booleans (DB columns) + sep_* booleans
    $splitChillerFees       = ($distChiller  === 'split');
    $splitEjariFees         = ($distEjari    === 'split');
    $splitAdminFees         = ($distAdmin    === 'split');
    $splitCommissionFees    = ($distComm     === 'split');
    $splitAmcFees           = ($distAmc      === 'split');
    $splitAdditionalParking = ($distParking  === 'split');
    $splitAdditionalStore   = ($distStore    === 'split');
    $sepChillerFees         = ($distChiller  === 'separate');
    $sepEjariFees           = ($distEjari    === 'separate');
    $sepAdminFees           = ($distAdmin    === 'separate');
    $sepCommissionFees      = ($distComm     === 'separate');
    $sepAmcFees             = ($distAmc      === 'separate');
    $sepAdditionalParking   = ($distParking  === 'separate');
    $sepAdditionalStore     = ($distStore    === 'separate');
    // Payment day is no longer used in UI; keep a default for backward compatibility
    $paymentDay = 1;
    $paymentMethod = $_POST['payment_method'] ?? 'cheque';
    $gracePeriodDays = !empty($_POST['grace_period_days']) ? (int)$_POST['grace_period_days'] : 0;
    $renewalTerms = trim($_POST['renewal_terms'] ?? '');
    $templateId = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
    $status = $_POST['status'] ?? 'draft';
    $existingLeaseForStatus = null;
    $validLeaseStatuses = function_exists('re_lease_valid_statuses')
        ? re_lease_valid_statuses()
        : ['draft', 'active', 'expired', 'terminated', 'renewed', 'has_legal_case'];
    if (!in_array($status, $validLeaseStatuses, true)) {
        $error = 'Invalid lease status.';
    }

    if (!$error && !$leaseId && !in_array($status, ['draft', 'active'], true)) {
        $error = 'New leases can only be created as Draft or Active.';
    }

    if (!$error && $leaseId) {
        $existingLeaseForStatus = re_lease_load_for_lifecycle($conn, $currentCompanyId, $leaseId);
        if (!$existingLeaseForStatus) {
            $error = 'Lease not found.';
        } elseif (($existingLeaseForStatus['status'] ?? '') !== $status) {
            if ($status === 'terminated') {
                $error = 'Use the Terminate Lease workflow so termination date, cheque returns, and revenue recognition are handled correctly.';
            } elseif ($status === 'renewed') {
                $error = 'Use the Renewal Workflow to mark a lease renewed/converted.';
            } elseif (!in_array($status, re_lease_allowed_status_transitions($existingLeaseForStatus), true)) {
                $error = 'Status change from "' . ($existingLeaseForStatus['status'] ?? '') . '" to "' . $status . '" is not allowed.';
            }
        }
    }
    $moveInDate = !empty($_POST['move_in_date']) ? $_POST['move_in_date'] : null;
    $notes = trim($_POST['notes'] ?? '');
    
    // Additional parking
    $hasAdditionalParking = !empty($_POST['has_additional_parking']);
    $additionalParkingFee = !empty($_POST['additional_parking_fee']) ? (float)$_POST['additional_parking_fee'] : 0;
    $additionalParkingStartDate = !empty($_POST['additional_parking_start_date']) ? $_POST['additional_parking_start_date'] : null;
    $additionalParkingEndDate = !empty($_POST['additional_parking_end_date']) ? $_POST['additional_parking_end_date'] : null;
    
    // Additional store
    $hasAdditionalStore = !empty($_POST['has_additional_store']);
    $additionalStoreFee = !empty($_POST['additional_store_fee']) ? (float)$_POST['additional_store_fee'] : 0;
    $additionalStoreStartDate = !empty($_POST['additional_store_start_date']) ? $_POST['additional_store_start_date'] : null;
    $additionalStoreEndDate = !empty($_POST['additional_store_end_date']) ? $_POST['additional_store_end_date'] : null;

    // Phase 3.5: AMC / parking / store are legacy extra-service fields.
    // New leases and renewals must use Billing / Extra Services instead.
    if (!$leaseId || $isRenewalLease) {
        $amcAmount = 0.0;
        $distAmc = 'first';
        $splitAmcFees = false;
        $sepAmcFees = false;
        $hasAdditionalParking = false;
        $additionalParkingFee = 0.0;
        $additionalParkingStartDate = null;
        $additionalParkingEndDate = null;
        $distParking = 'first';
        $splitAdditionalParking = false;
        $sepAdditionalParking = false;
        $hasAdditionalStore = false;
        $additionalStoreFee = 0.0;
        $additionalStoreStartDate = null;
        $additionalStoreEndDate = null;
        $distStore = 'first';
        $splitAdditionalStore = false;
        $sepAdditionalStore = false;
    } elseif (!isset($_POST['amc_amount'], $_POST['has_additional_parking'], $_POST['has_additional_store'])) {
        // Existing legacy lease edit with hidden extra-service controls: preserve stored historical values.
        try {
            $legacyExtrasStmt = $conn->prepare("
                SELECT amc_amount, split_amc_fees, sep_amc_fees,
                       has_additional_parking, additional_parking_fee, additional_parking_start_date, additional_parking_end_date,
                       split_additional_parking, sep_additional_parking,
                       has_additional_store, additional_store_fee, additional_store_start_date, additional_store_end_date,
                       split_additional_store, sep_additional_store
                FROM re_leases
                WHERE id = ? AND company_id = ?
                LIMIT 1
            ");
            $legacyExtrasStmt->execute([$leaseId, $currentCompanyId]);
            $legacyExtras = $legacyExtrasStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($legacyExtras) {
                $amcAmount = (float)($legacyExtras['amc_amount'] ?? 0);
                $splitAmcFees = !empty($legacyExtras['split_amc_fees']);
                $sepAmcFees = !empty($legacyExtras['sep_amc_fees']);
                $hasAdditionalParking = !empty($legacyExtras['has_additional_parking']);
                $additionalParkingFee = (float)($legacyExtras['additional_parking_fee'] ?? 0);
                $additionalParkingStartDate = $legacyExtras['additional_parking_start_date'] ?? null;
                $additionalParkingEndDate = $legacyExtras['additional_parking_end_date'] ?? null;
                $splitAdditionalParking = !empty($legacyExtras['split_additional_parking']);
                $sepAdditionalParking = !empty($legacyExtras['sep_additional_parking']);
                $hasAdditionalStore = !empty($legacyExtras['has_additional_store']);
                $additionalStoreFee = (float)($legacyExtras['additional_store_fee'] ?? 0);
                $additionalStoreStartDate = $legacyExtras['additional_store_start_date'] ?? null;
                $additionalStoreEndDate = $legacyExtras['additional_store_end_date'] ?? null;
                $splitAdditionalStore = !empty($legacyExtras['split_additional_store']);
                $sepAdditionalStore = !empty($legacyExtras['sep_additional_store']);
            }
        } catch (Throwable $e) {
            // If legacy columns are unavailable, keep the already-normalized zero/default values.
        }
    }

    $unitTypeForVat = '';
    if ($unitId) {
        try {
            $ux = $conn->prepare("SELECT unit_type FROM re_units WHERE id = ? AND company_id = ? LIMIT 1");
            $ux->execute([$unitId, $currentCompanyId]);
            $unitTypeForVat = (string)($ux->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $unitTypeForVat = '';
        }
    }
    $commercialUnit = lease_vat_unit_is_commercial($unitTypeForVat);
    $vatApplicableOnRent = $commercialUnit && !empty($_POST['vat_applicable_on_rent']);
    // Unchecked checkboxes are absent from POST; absence must mean "no VAT on extras".
    $vatApplicableOnExtraCharges = !empty($_POST['vat_applicable_on_extra_charges']);
    $leaseVatRate = isset($_POST['lease_vat_rate']) ? (float)$_POST['lease_vat_rate'] : 5.0;
    if ($leaseVatRate <= 0) {
        $leaseVatRate = 5.0;
    }
    $vatDistributionType = lease_vat_normalize_distribution($_POST['vat_distribution_type'] ?? 'first_installment');
    $vatNotesLease = trim((string)($_POST['vat_notes'] ?? ''));
    // Ejari is excluded from VAT extras by business rule.
    $extraBaseForVat = $chillerFees + $adminFees + $commissionFees;
    $leaseVatCalc = lease_vat_compute_amounts([
        'annual_rent' => $annualRent,
        'vat_applicable_on_rent' => $vatApplicableOnRent,
        'vat_applicable_on_extra_charges' => $vatApplicableOnExtraCharges,
        'lease_vat_rate' => $leaseVatRate,
        'extra_charges_base' => $extraBaseForVat,
    ]);
    $rentVatStored = $leaseVatCalc['rent_vat'];
    $extraVatStored = $leaseVatCalc['extra_vat'];
    $totalVatStored = $leaseVatCalc['total_vat'];

    // Ejari fields
    $ejariRegistrationNumber = trim($_POST['ejari_registration_number'] ?? '');
    $ejariIssueDate = !empty($_POST['ejari_issue_date']) ? $_POST['ejari_issue_date'] : null;
    $ejariPropertyCode = trim($_POST['ejari_property_code'] ?? '');

    // -----------------------------------------------------------------------
    // Flexible 3-way fee distribution:
    //   'split'    → add annual value to effectiveAnnualRent (÷ all installments)
    //   'first'    → goes to 1st installment or combined fees installment
    //   'separate' → gets its own dedicated installment (cheque)
    // Security Deposit: renewal lease → not collected at all.
    // Additional Parking/Store: monthly × 12 = annual when splitting.
    // -----------------------------------------------------------------------
    $effectiveAnnualRent = $annualRent;
    if ($splitChillerFees)    $effectiveAnnualRent += $chillerFees;
    if ($splitEjariFees)      $effectiveAnnualRent += $ejariFees;
    if ($splitAdminFees)      $effectiveAnnualRent += $adminFees;
    if ($splitCommissionFees) $effectiveAnnualRent += $commissionFees;
    if ($vatDistributionType === 'split_installments' && $totalVatStored > 0) {
        $effectiveAnnualRent += $totalVatStored;
    }
    $monthlyRent = $effectiveAnnualRent / $numberOfInstallments;

    // Fees going to the 1st installment (or combined fees installment) — 'first' mode only
    $totalFeesToFirst = 0;
    if (!$isRenewalLease && $distSecurity !== 'separate') $totalFeesToFirst += $securityDeposit;
    if ($distChiller === 'first')   $totalFeesToFirst += $chillerFees;
    if ($distEjari   === 'first')   $totalFeesToFirst += $ejariFees;
    if ($distAdmin   === 'first')   $totalFeesToFirst += $adminFees;
    if ($distComm    === 'first')   $totalFeesToFirst += $commissionFees;
    if ($vatDistributionType === 'first_installment' && $totalVatStored > 0) {
        $totalFeesToFirst += $totalVatStored;
    }
    // separate_payment: VAT row is injected into $separateFeeList below (new leases only)
    
    // Helper function to handle file uploads
    function handleFileUpload($fileKey, $uploadDir, $allowedTypes = ['png', 'jpg', 'jpeg', 'pdf']) {
        if (empty($_FILES[$fileKey]['tmp_name']) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $file = $_FILES[$fileKey];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowedTypes)) {
            return null;
        }
        
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Return relative path from project root
            return str_replace(__DIR__ . '/../../', '', $filepath);
        }
        
        return null;
    }
    
    // Handle file uploads
    $ejariDocumentPath = null;
    $landlordSignaturePath = null;
    $tenantSignaturePath = null;
    $companyStampPath = null;
    
    if ($leaseId) {
        // Load existing paths
        $stmt = $conn->prepare("SELECT ejari_document_path, landlord_signature_path, tenant_signature_path, company_stamp_path FROM re_leases WHERE id = ? AND company_id = ?");
        $stmt->execute([$leaseId, $currentCompanyId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $ejariDocumentPath = $existing['ejari_document_path'] ?? null;
        $landlordSignaturePath = $existing['landlord_signature_path'] ?? null;
        $tenantSignaturePath = $existing['tenant_signature_path'] ?? null;
        $companyStampPath = $existing['company_stamp_path'] ?? null;
    }
    
    // Upload new files if provided
    $uploadBase = __DIR__ . '/../../uploads/leases';
    if (!empty($_FILES['ejari_document']['tmp_name'])) {
        $newPath = handleFileUpload('ejari_document', $uploadBase . '/ejari', ['pdf']);
        if ($newPath) $ejariDocumentPath = $newPath;
    }
    if (!empty($_FILES['landlord_signature']['tmp_name'])) {
        $newPath = handleFileUpload('landlord_signature', $uploadBase . '/signatures', ['png', 'jpg', 'jpeg']);
        if ($newPath) $landlordSignaturePath = $newPath;
    }
    if (!empty($_FILES['tenant_signature']['tmp_name'])) {
        $newPath = handleFileUpload('tenant_signature', $uploadBase . '/signatures', ['png', 'jpg', 'jpeg']);
        if ($newPath) $tenantSignaturePath = $newPath;
    }
    if (!empty($_FILES['company_stamp']['tmp_name'])) {
        $newPath = handleFileUpload('company_stamp', $uploadBase . '/stamps', ['png', 'jpg', 'jpeg']);
        if ($newPath) $companyStampPath = $newPath;
    }
    
    // Check if any of the selected units already has an active lease (only for new leases)
    if (!$leaseId) {
        $unitsToCheck = !empty($multiUnits)
            ? array_column($multiUnits, 'unit_id')
            : ($unitId ? [$unitId] : []);

        foreach ($unitsToCheck as $checkUid) {
            $stmt = $conn->prepare("
                SELECT l.id, l.lease_number, l.end_date, u.unit_number
                FROM re_leases l
                JOIN re_units u ON u.id = l.unit_id
                WHERE l.unit_id = ? AND l.company_id = ? AND l.status = 'active'
                  AND " . re_lease_not_deleted_sql($conn, 'l') . "
                LIMIT 1
            ");
            $stmt->execute([$checkUid, $currentCompanyId]);
            $existingLease = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existingLease) {
                $error = "Unit #{$existingLease['unit_number']} already has an ACTIVE lease (#{$existingLease['lease_number']}, ends: {$existingLease['end_date']}). Terminate it, expire it, or mark it Has Legal Case before creating a new lease.";
                break;
            }
        }
    }

    $expectedScheduleBreakdown = re_payment_schedule_expected_breakdown([
        'annual_rent' => $annualRent,
        'security_deposit' => $securityDeposit,
        'is_renewal_lease' => $isRenewalLease ? 1 : 0,
        'chiller_fees' => $chillerFees,
        'ejari_fees' => $ejariFees,
        'admin_fees' => $adminFees,
        'commission_fees' => $commissionFees,
        'vat_applicable_on_rent' => $vatApplicableOnRent ? 1 : 0,
        'vat_applicable_on_extra_charges' => $vatApplicableOnExtraCharges ? 1 : 0,
        'lease_vat_rate' => $leaseVatRate,
    ]);
    $postedScheduleRows = (!empty($_POST['cheques']) && is_array($_POST['cheques'])) ? $_POST['cheques'] : [];
    $postedScheduleTotal = $postedScheduleRows
        ? re_payment_schedule_sum_posted_rows($postedScheduleRows)
        : (float)$expectedScheduleBreakdown['total_expected'];
    $scheduleMismatchReason = trim((string)($_POST['schedule_mismatch_reason'] ?? ''));
    $scheduleMismatchApproval = !empty($_POST['schedule_mismatch_approved']);
    $scheduleValidation = re_payment_schedule_validate_totals(
        $conn,
        (float)$expectedScheduleBreakdown['total_expected'],
        $postedScheduleTotal,
        $scheduleMismatchApproval,
        $scheduleMismatchReason
    );
    if (!$scheduleValidation['valid']) {
        $error = $scheduleValidation['error'];
    }
    
    if ($unitId && $tenantId && $startDate && $endDate && $annualRent > 0 && !$error) {
        try {
            $conn->beginTransaction();
            
            // Track if this is a new lease (before INSERT sets $leaseId)
            $wasNewLease = !$leaseId;
            
            if ($leaseId) {
                // Update
                $stmt = $conn->prepare("
                    UPDATE re_leases 
                    SET unit_id = ?, tenant_id = ?, lease_number = ?, start_date = ?, end_date = ?,
                        annual_rent = ?, monthly_rent = ?, number_of_installments = ?, security_deposit = ?, 
                        chiller_fees = ?, ejari_fees = ?, admin_fees = ?, commission_fees = ?,
                        add_fees_to_first_installment = ?,
                        deferred_revenue_mode = ?,
                        is_renewal_lease = ?,
                        split_chiller_fees = ?, sep_chiller_fees = ?,
                        split_ejari_fees = ?, sep_ejari_fees = ?,
                        split_admin_fees = ?, sep_admin_fees = ?,
                        split_commission_fees = ?, sep_commission_fees = ?,
                        split_additional_parking = ?, sep_additional_parking = ?,
                        split_additional_store = ?, sep_additional_store = ?,
                        amc_amount = ?, split_amc_fees = ?, sep_amc_fees = ?,
                        payment_day = ?, payment_method = ?, grace_period_days = ?, renewal_terms = ?, template_id = ?,
                        has_additional_parking = ?, additional_parking_fee = ?, additional_parking_start_date = ?, additional_parking_end_date = ?,
                        has_additional_store = ?, additional_store_fee = ?, additional_store_start_date = ?, additional_store_end_date = ?,
                        ejari_registration_number = ?, ejari_issue_date = ?, ejari_property_code = ?, ejari_document_path = ?,
                        landlord_signature_path = ?, tenant_signature_path = ?, company_stamp_path = ?,
                        status = ?, move_in_date = ?, notes = ?,
                        is_multi_unit = ?,
                        vat_applicable_on_rent = ?, vat_applicable_on_extra_charges = ?, lease_vat_rate = ?,
                        rent_vat_amount = ?, extra_services_vat_amount = ?, total_vat_amount = ?,
                        vat_distribution_type = ?, vat_notes = ?,
                        accounting_mode = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $unitId, $tenantId, $leaseNumber, $startDate, $endDate,
                    $annualRent, $monthlyRent, $numberOfInstallments, $securityDeposit,
                    $chillerFees, $ejariFees, $adminFees, $commissionFees,
                    $addFeesToFirstInstallment ? 1 : 0,
                    $deferredRevenueMode ? 1 : 0,
                    $isRenewalLease ? 1 : 0,
                    $splitChillerFees ? 1 : 0, $sepChillerFees ? 1 : 0,
                    $splitEjariFees ? 1 : 0, $sepEjariFees ? 1 : 0,
                    $splitAdminFees ? 1 : 0, $sepAdminFees ? 1 : 0,
                    $splitCommissionFees ? 1 : 0, $sepCommissionFees ? 1 : 0,
                    $splitAdditionalParking ? 1 : 0, $sepAdditionalParking ? 1 : 0,
                    $splitAdditionalStore ? 1 : 0, $sepAdditionalStore ? 1 : 0,
                    $amcAmount, $splitAmcFees ? 1 : 0, $sepAmcFees ? 1 : 0,
                    $paymentDay, $paymentMethod,
                    $gracePeriodDays, $renewalTerms ?: null, $templateId,
                    $hasAdditionalParking ? 1 : 0, $additionalParkingFee, $additionalParkingStartDate, $additionalParkingEndDate,
                    $hasAdditionalStore ? 1 : 0, $additionalStoreFee, $additionalStoreStartDate, $additionalStoreEndDate,
                    $ejariRegistrationNumber ?: null, $ejariIssueDate, $ejariPropertyCode ?: null, $ejariDocumentPath ?: null,
                    $landlordSignaturePath ?: null, $tenantSignaturePath ?: null, $companyStampPath ?: null,
                    $status, $moveInDate, $notes ?: null,
                    $isMultiUnit ? 1 : 0,
                    $vatApplicableOnRent ? 1 : 0,
                    $vatApplicableOnExtraCharges ? 1 : 0,
                    $leaseVatRate,
                    $rentVatStored,
                    $extraVatStored,
                    $totalVatStored,
                    $vatDistributionType,
                    $vatNotesLease !== '' ? $vatNotesLease : null,
                    $accountingMode,
                    $leaseId, $currentCompanyId
                ]);

                if ($existingAccountingMode !== null && $existingAccountingMode !== $accountingMode) {
                    re_accounting_log_mode_change(
                        $conn,
                        $currentCompanyId,
                        (int)$leaseId,
                        $existingAccountingMode,
                        $accountingMode,
                        $userId,
                        $accountingModeOverrideReason,
                        'lease_edit'
                    );
                }

                if ($existingLeaseForStatus && ($existingLeaseForStatus['status'] ?? '') !== $status) {
                    re_lease_lifecycle_audit(
                        'status_change',
                        (int)$leaseId,
                        ['status' => $existingLeaseForStatus['status'] ?? null],
                        ['status' => $status],
                        'Lease status changed from ' . ($existingLeaseForStatus['status'] ?? '') . ' to ' . $status . ' while editing lease.'
                    );
                }

                // Sync re_lease_units for this lease
                if (!empty($multiUnits)) {
                    // Delete existing unit rows (re-insert fresh)
                    $conn->prepare("DELETE FROM re_lease_units WHERE lease_id = ?")->execute([$leaseId]);
                    $luStmt = $conn->prepare("
                        INSERT INTO re_lease_units (company_id, lease_id, unit_id, annual_rent, sort_order)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($multiUnits as $idx => $mu) {
                        $luStmt->execute([$currentCompanyId, $leaseId, $mu['unit_id'], $mu['annual_rent'], $idx]);
                        // Mark each additional unit as occupied
                        if ($idx > 0) {
                            $conn->prepare("UPDATE re_units SET status='occupied' WHERE id=? AND company_id=?")
                                 ->execute([$mu['unit_id'], $currentCompanyId]);
                        }
                    }
                } else {
                    // Single-unit lease: ensure re_lease_units has at least one row
                    $conn->prepare("
                        INSERT IGNORE INTO re_lease_units (company_id, lease_id, unit_id, annual_rent, sort_order)
                        VALUES (?, ?, ?, ?, 0)
                    ")->execute([$currentCompanyId, $leaseId, $unitId, $annualRent]);
                }

                // Handle Fees Installment for existing leases (use same fees-to-first as computed above)
                $totalFees = $totalFeesToFirst;
                $start = new DateTime($startDate);
                
                // Get existing installments ordered by date
                $stmt = $conn->prepare("
                    SELECT id, installment_date, amount, status, payment_id
                    FROM re_lease_installments
                    WHERE lease_id = ?
                    ORDER BY installment_date ASC, id ASC
                ");
                $stmt->execute([$leaseId]);
                $existingInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // If number_of_installments was reduced, safely remove extra unpaid installments at the end.
                // IMPORTANT: exclude the fees installment from this count — it is not a "regular" installment.
                if (!empty($existingInstallments)) {
                    // Identify the fees installment by matching amount ≈ $totalFeesToFirst on the first date
                    $shrinkFeesInstId = null;
                    if ($totalFeesToFirst > 0) {
                        $shrinkFirstDate = $existingInstallments[0]['installment_date'];
                        foreach ($existingInstallments as $sinst) {
                            if ($sinst['installment_date'] === $shrinkFirstDate
                                && abs((float)$sinst['amount'] - (float)$totalFeesToFirst) < 0.01) {
                                $shrinkFeesInstId = $sinst['id'];
                                break;
                            }
                        }
                    }

                    // Build array of regular installments only (excludes fees installment)
                    $regularInstallments = array_values(array_filter(
                        $existingInstallments,
                        fn($i) => $i['id'] !== $shrinkFeesInstId
                    ));
                    $existingCount = count($regularInstallments);

                    if ($numberOfInstallments > 0 && $numberOfInstallments < $existingCount) {
                        $canShrink = true;
                        for ($i = $numberOfInstallments; $i < $existingCount; $i++) {
                            $inst = $regularInstallments[$i];
                            if ($inst['status'] === 'paid' || !empty($inst['payment_id'])) {
                                $canShrink = false;
                                break;
                            }
                        }
                        if ($canShrink) {
                            for ($i = $numberOfInstallments; $i < $existingCount; $i++) {
                                $instId = (int)$regularInstallments[$i]['id'];
                                if ($instId <= 0) continue;
                                $stmtDel = $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id = ?");
                                $stmtDel->execute([$leaseId, $instId]);
                                $stmtDel = $conn->prepare("DELETE FROM re_lease_cheques WHERE lease_id = ? AND installment_id = ?");
                                $stmtDel->execute([$leaseId, $instId]);
                                $stmtDel = $conn->prepare("DELETE FROM re_lease_installments WHERE id = ?");
                                $stmtDel->execute([$instId]);
                            }
                            // Reload installments after shrink
                            $stmt = $conn->prepare("
                                SELECT id, installment_date, amount, status, payment_id
                                FROM re_lease_installments
                                WHERE lease_id = ?
                                ORDER BY installment_date ASC, id ASC
                            ");
                            $stmt->execute([$leaseId]);
                            $existingInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                }
                
                if (!empty($existingInstallments)) {
                    $firstInstallmentDate = $existingInstallments[0]['installment_date'];
                    $firstInstallmentId = $existingInstallments[0]['id'];
                    $firstInstallmentAmount = $existingInstallments[0]['amount'];
                    $firstInstallmentPaid = ($existingInstallments[0]['status'] === 'paid' || !empty($existingInstallments[0]['payment_id']));
                    
                    // Check if a Fees Installment exists (installment with amount matching total fees on first date)
                    $feesInstallmentId = null;
                    $feesInstallmentExists = false;
                    foreach ($existingInstallments as $inst) {
                        if ($inst['installment_date'] === $firstInstallmentDate) {
                            // Check if amount matches total fees (within 0.01 tolerance)
                            if (abs($inst['amount'] - $totalFees) < 0.01 && $totalFees > 0) {
                                $feesInstallmentId = $inst['id'];
                                $feesInstallmentExists = true;
                                break;
                            }
                        } else {
                            break; // Only check installments on the first date
                        }
                    }
                    
                    // If checkbox is unchecked and fees exist, ensure Fees Installment exists
                    if (!$addFeesToFirstInstallment && $totalFees > 0 && !$feesInstallmentExists) {
                        // Create Fees Installment only if no payments have been made (safe to modify)
                        $hasPayments = false;
                        foreach ($existingInstallments as $inst) {
                            if ($inst['status'] === 'paid' || !empty($inst['payment_id'])) {
                                $hasPayments = true;
                                break;
                            }
                        }
                        
                        // Only create if no payments made yet (draft status or no payments)
                        if (!$hasPayments || $status === 'draft') {
                            $feesInstallmentDate = $firstInstallmentDate;
                            $stmt = $conn->prepare("
                                INSERT INTO re_lease_installments (company_id, lease_id, installment_date, amount, status, installment_type)
                                VALUES (?, ?, ?, ?, 'pending', 'combined_fees')
                            ");
                            $stmt->execute([$currentCompanyId, $leaseId, $feesInstallmentDate, $totalFees]);
                            $feesInstallmentId = $conn->lastInsertId();
                            
                            // If first installment had fees added to it, subtract them now
                            if ($firstInstallmentAmount > $monthlyRent) {
                                $newFirstAmount = $firstInstallmentAmount - $totalFees;
                                if ($newFirstAmount < $monthlyRent) {
                                    $newFirstAmount = $monthlyRent; // Ensure it's at least monthly rent
                                }
                                $stmt = $conn->prepare("
                                    UPDATE re_lease_installments 
                                    SET amount = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$newFirstAmount, $firstInstallmentId]);
                            }
                        }
                    }
                    
                    // If checkbox is checked and Fees Installment exists, remove it and add fees to first installment
                    if ($addFeesToFirstInstallment && $feesInstallmentExists && $feesInstallmentId) {
                        // Check if Fees Installment is paid
                        $feesInstPaid = false;
                        foreach ($existingInstallments as $inst) {
                            if ($inst['id'] == $feesInstallmentId) {
                                $feesInstPaid = ($inst['status'] === 'paid' || !empty($inst['payment_id']));
                                break;
                            }
                        }
                        
                        // Only remove if unpaid (safe to modify)
                        if (!$feesInstPaid) {
                            // Delete the Fees Installment
                            $stmt = $conn->prepare("DELETE FROM re_lease_installments WHERE id = ?");
                            $stmt->execute([$feesInstallmentId]);
                            
                            // Add fees to first installment
                            $newFirstAmount = $firstInstallmentAmount + $totalFees;
                            $stmt = $conn->prepare("
                                UPDATE re_lease_installments 
                                SET amount = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$newFirstAmount, $firstInstallmentId]);
                        }
                    }
                }
                
                // ------------------------------------------------------------------
                // Legacy AMC installments are preserved read-only from Phase 3.5.
                // Do not create, update, or delete AMC rows from Lease Add.
                // ------------------------------------------------------------------
                /*
                // Find any existing AMC installment for this lease
                $existingAmcInst = $conn->prepare("
                    SELECT id, status, payment_id
                    FROM re_lease_installments
                    WHERE lease_id = ? AND installment_type = 'amc'
                    LIMIT 1
                ");
                $existingAmcInst->execute([$leaseId]);
                $amcInst = $existingAmcInst->fetch(PDO::FETCH_ASSOC);
                $amcInstPaid = $amcInst && ($amcInst['status'] === 'paid' || !empty($amcInst['payment_id']));

                if ($sepAmcFees && $amcAmount > 0) {
                    // Need a separate AMC installment
                    if ($amcInst && !$amcInstPaid) {
                        // Update existing unpaid AMC installment amount
                        $conn->prepare("UPDATE re_lease_installments SET amount = ? WHERE id = ?")
                             ->execute([$amcAmount, $amcInst['id']]);
                    } elseif (!$amcInst) {
                        // Create new AMC installment on start date
                        $conn->prepare("
                            INSERT INTO re_lease_installments
                                (company_id, lease_id, installment_date, amount, status, installment_type)
                            VALUES (?, ?, ?, ?, 'pending', 'amc')
                        ")->execute([$currentCompanyId, $leaseId, $startDate, $amcAmount]);
                    }
                } else {
                    // AMC is split/first OR amount is 0: remove unpaid separate AMC installment if it exists
                    if ($amcInst && !$amcInstPaid) {
                        $conn->prepare("DELETE FROM re_lease_installments WHERE id = ?")
                             ->execute([$amcInst['id']]);
                    }
                }
                */

                // Handle cheques when editing: sync amounts and cheque details from Payment Details section
                // Run whenever cheques data is submitted (so lease_view shows updated values)
                if (!empty($_POST['cheques']) && is_array($_POST['cheques'])) {
                    // Get tenant name for cheque holder
                    $tenantStmt = $conn->prepare("SELECT first_name, last_name FROM re_tenants WHERE id = ?");
                    $tenantStmt->execute([$tenantId]);
                    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
                    $tenantName = ($tenant ? $tenant['first_name'] . ' ' . $tenant['last_name'] : '');
                    
                    // Get all installments ordered by date (including any separate fee rows)
                    lease_ensure_separate_fee_installments($conn, $leaseId, $currentCompanyId, [
                        'start_date' => $startDate,
                        'is_renewal_lease' => $isRenewalLease ? 1 : 0,
                        'security_deposit' => $securityDeposit,
                        'sep_security_deposit' => $sepSecurityDeposit ? 1 : 0,
                        'chiller_fees' => $chillerFees,
                        'ejari_fees' => $ejariFees,
                        'admin_fees' => $adminFees,
                        'commission_fees' => $commissionFees,
                        'amc_amount' => 0,
                        'split_chiller_fees' => $splitChillerFees ? 1 : 0,
                        'split_ejari_fees' => $splitEjariFees ? 1 : 0,
                        'split_admin_fees' => $splitAdminFees ? 1 : 0,
                        'split_commission_fees' => $splitCommissionFees ? 1 : 0,
                        'split_amc_fees' => 0,
                        'sep_chiller_fees' => $sepChillerFees ? 1 : 0,
                        'sep_ejari_fees' => $sepEjariFees ? 1 : 0,
                        'sep_admin_fees' => $sepAdminFees ? 1 : 0,
                        'sep_commission_fees' => $sepCommissionFees ? 1 : 0,
                        'sep_amc_fees' => 0,
                        'sep_additional_parking' => 0,
                        'sep_additional_store' => 0,
                        'has_additional_parking' => 0,
                        'additional_parking_fee' => 0,
                        'additional_parking_start_date' => null,
                        'additional_parking_end_date' => null,
                        'has_additional_store' => 0,
                        'additional_store_fee' => 0,
                        'additional_store_start_date' => null,
                        'additional_store_end_date' => null,
                        'vat_distribution_type' => $vatDistributionType,
                        'total_vat_amount' => $totalVatStored,
                    ]);

                    $stmt = $conn->prepare("
                        SELECT id, installment_date, amount, installment_type, status, payment_id
                        FROM re_lease_installments
                        WHERE lease_id = ?
                        ORDER BY installment_date ASC, id ASC
                    ");
                    $stmt->execute([$leaseId]);
                    $allInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // LOCKED rows (paid/partial/payment/allocation/cleared-cheque) are never
                    // mutated or deleted by the save — this is the core data-safety guard.
                    $lockedInstIds = lease_locked_installment_ids($conn, (int)$leaseId, (int)$currentCompanyId);
                    lease_reset_stale_installment_statuses($conn, (int)$leaseId, (int)$currentCompanyId);

                    $leaseRowForSchedule = [
                        'start_date' => $startDate,
                        'add_fees_to_first_installment' => $addFeesToFirstInstallment ? 1 : 0,
                        'is_renewal_lease' => $isRenewalLease ? 1 : 0,
                        'security_deposit' => $securityDeposit,
                        'sep_security_deposit' => $sepSecurityDeposit ? 1 : 0,
                        'chiller_fees' => $chillerFees,
                        'ejari_fees' => $ejariFees,
                        'admin_fees' => $adminFees,
                        'commission_fees' => $commissionFees,
                        'split_chiller_fees' => $splitChillerFees ? 1 : 0,
                        'split_ejari_fees' => $splitEjariFees ? 1 : 0,
                        'split_admin_fees' => $splitAdminFees ? 1 : 0,
                        'split_commission_fees' => $splitCommissionFees ? 1 : 0,
                        'sep_chiller_fees' => $sepChillerFees ? 1 : 0,
                        'sep_ejari_fees' => $sepEjariFees ? 1 : 0,
                        'sep_admin_fees' => $sepAdminFees ? 1 : 0,
                        'sep_commission_fees' => $sepCommissionFees ? 1 : 0,
                        'vat_distribution_type' => $vatDistributionType,
                        'total_vat_amount' => $totalVatStored,
                    ];
                    lease_fix_mistagged_combined_fees_rows($conn, (int)$leaseId, (int)$currentCompanyId, (float)$totalFees, $lockedInstIds);
                    lease_ensure_combined_fees_installment($conn, (int)$leaseId, (int)$currentCompanyId, $leaseRowForSchedule, $lockedInstIds);

                    $stmt = $conn->prepare("
                        SELECT id, installment_date, amount, installment_type, status, payment_id
                        FROM re_lease_installments
                        WHERE lease_id = ?
                        ORDER BY installment_date ASC, id ASC
                    ");
                    $stmt->execute([$leaseId]);
                    $allInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $instById = [];
                    foreach ($allInstallments as $row) {
                        $instById[(int)$row['id']] = $row;
                    }

                    // Repair stale/mistagged combined-fee rows before ID mapping.
                    foreach ($allInstallments as &$row) {
                        if (!lease_is_mistagged_combined_fees_row($row, (float)$totalFees)) {
                            continue;
                        }
                        $rowId = (int)($row['id'] ?? 0);
                        if ($rowId > 0 && !isset($lockedInstIds[$rowId])) {
                            $conn->prepare("UPDATE re_lease_installments SET installment_type = 'rent' WHERE id = ? AND company_id = ?")
                                 ->execute([$rowId, $currentCompanyId]);
                            $row['installment_type'] = 'rent';
                            $instById[$rowId]['installment_type'] = 'rent';
                        }
                    }
                    unset($row);

                    // Build ordered installment IDs matching generateChequesRows() (never use raw POST index vs allInstallments[]).
                    $knownSepTypes = ['security_deposit', 'chiller', 'ejari', 'admin', 'commission', 'amc', 'parking', 'store', 'vat'];
                    $byTypeFirst = [];
                    foreach ($allInstallments as $row) {
                        $t = (string)($row['installment_type'] ?? '');
                        if ($t !== '' && in_array($t, $knownSepTypes, true) && !isset($byTypeFirst[$t])) {
                            $byTypeFirst[$t] = $row;
                        }
                    }
                    $sepFeeOrder = [];
                    if ($sepSecurityDeposit && !$isRenewalLease && $securityDeposit > 0) {
                        $sepFeeOrder[] = ['dist' => 'separate', 'amount' => $securityDeposit, 'type' => 'security_deposit'];
                    }
                    $sepFeeOrder = array_merge($sepFeeOrder, [
                        ['dist' => $distChiller, 'amount' => $chillerFees, 'type' => 'chiller'],
                        ['dist' => $distEjari, 'amount' => $ejariFees, 'type' => 'ejari'],
                        ['dist' => $distAdmin, 'amount' => $adminFees, 'type' => 'admin'],
                        ['dist' => $distComm, 'amount' => $commissionFees, 'type' => 'commission'],
                    ]);
                    if ($vatDistributionType === 'separate_payment' && $totalVatStored > 0) {
                        $sepFeeOrder[] = ['dist' => 'separate', 'amount' => $totalVatStored, 'type' => 'vat'];
                    }
                    $legacySepIds = [];
                    $firstInstDateForLegacyFees = $allInstallments[0]['installment_date'] ?? $startDate;
                    foreach ($sepFeeOrder as $sf) {
                        if ($sf['dist'] !== 'separate' || $sf['amount'] <= 0) {
                            continue;
                        }
                        if (!isset($byTypeFirst[$sf['type']])) {
                            $match = lease_find_separate_installment_row(
                                $allInstallments,
                                $sf['type'],
                                (float)$sf['amount'],
                                $firstInstDateForLegacyFees
                            );
                            if ($match) {
                                $byTypeFirst[$sf['type']] = $match;
                            }
                        }
                        if (isset($byTypeFirst[$sf['type']])) {
                            $legacySepIds[(int)$byTypeFirst[$sf['type']]['id']] = true;
                        }
                    }
                    $chequeTargetIds = [];
                    foreach ($sepFeeOrder as $sf) {
                        if ($sf['dist'] === 'separate' && $sf['amount'] > 0 && isset($byTypeFirst[$sf['type']])) {
                            $chequeTargetIds[] = (int)$byTypeFirst[$sf['type']]['id'];
                        }
                    }
                    if (!$addFeesToFirstInstallment && $totalFees > 0) {
                        $cfRow = lease_find_combined_fees_installment_row($allInstallments, (float)$totalFees);
                        if ($cfRow) {
                            $chequeTargetIds[] = (int)$cfRow['id'];
                        }
                    }
                    $legacySepIdList = array_keys($legacySepIds);
                    $rentLike = lease_filter_rent_like_installment_rows(
                        $allInstallments,
                        (int)$numberOfInstallments,
                        (float)$totalFees,
                        (bool)$addFeesToFirstInstallment,
                        $legacySepIdList
                    );
                    foreach ($rentLike as $r) {
                        $chequeTargetIds[] = (int)$r['id'];
                    }

                    $chequeMapSeq = 0;
                    $submittedInstallmentIds = [];

                    // Process each cheque/payment row from the form. In edit mode, the posted schedule is the
                    // source of truth, so missing installment IDs are created instead of silently skipped.
                    foreach ($_POST['cheques'] as $index => $chequeData) {
                        if (empty($chequeData['amount']) || $chequeData['amount'] <= 0) continue;

                        $chequeAmount = (float)$chequeData['amount'];
                        $postedVatAmount = isset($chequeData['vat_amount']) ? (float)$chequeData['vat_amount'] : 0.0;
                        $postedInstallmentType = trim((string)($chequeData['installment_type'] ?? ''));
                        $allowedPostedTypes = ['combined_fees', 'security_deposit', 'chiller', 'ejari', 'admin', 'commission', 'amc', 'parking', 'store', 'vat'];
                        $chequeNumber = trim($chequeData['cheque_number'] ?? '');
                        $chequeDateRaw = !empty($chequeData['cheque_date']) ? $chequeData['cheque_date'] : $startDate;
                        // Ensure date is in YYYY-MM-DD format (convert from MM/DD/YYYY if needed)
                        $chequeDate = $chequeDateRaw;
                        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $chequeDateRaw, $matches)) {
                            // Convert MM/DD/YYYY to YYYY-MM-DD
                            $chequeDate = $matches[3] . '-' . $matches[1] . '-' . $matches[2];
                        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $chequeDateRaw)) {
                            // Already in YYYY-MM-DD format
                            $chequeDate = $chequeDateRaw;
                        } else {
                            // Try to parse with strtotime as fallback
                            $parsed = strtotime($chequeDateRaw);
                            if ($parsed !== false) {
                                $chequeDate = date('Y-m-d', $parsed);
                            } else {
                                $chequeDate = $startDate; // Fallback to lease start date
                            }
                        }

                        $postedInstId = (int)($chequeData['installment_id'] ?? 0);
                        $installmentInfo = null;
                        if ($postedInstallmentType === 'combined_fees') {
                            $installmentInfo = lease_find_combined_fees_installment_row($allInstallments, (float)$totalFees);
                            if (!$installmentInfo && $postedInstId > 0 && isset($instById[$postedInstId])) {
                                $candidate = $instById[$postedInstId];
                                if (!lease_is_mistagged_combined_fees_row($candidate, (float)$totalFees)) {
                                    $installmentInfo = $candidate;
                                }
                            }
                        } elseif ($postedInstallmentType !== '' && in_array($postedInstallmentType, $allowedPostedTypes, true)) {
                            $expectedSeparateAmount = 0.0;
                            foreach ($sepFeeOrder as $sf) {
                                if ($sf['type'] === $postedInstallmentType) {
                                    $expectedSeparateAmount = (float)$sf['amount'];
                                    break;
                                }
                            }
                            $typeRow = lease_find_separate_installment_row(
                                $allInstallments,
                                $postedInstallmentType,
                                $expectedSeparateAmount,
                                $firstInstDateForLegacyFees
                            );
                            if ($typeRow) {
                                $installmentInfo = $typeRow;
                            } elseif ($postedInstId > 0 && isset($instById[$postedInstId])) {
                                $installmentInfo = $instById[$postedInstId];
                            } elseif (isset($chequeTargetIds[$chequeMapSeq])) {
                                $tid = $chequeTargetIds[$chequeMapSeq];
                                $installmentInfo = $instById[$tid] ?? null;
                            } else {
                                $installmentInfo = null;
                            }
                        } elseif ($postedInstId > 0 && isset($instById[$postedInstId])) {
                            $installmentInfo = $instById[$postedInstId];
                        } elseif (isset($chequeTargetIds[$chequeMapSeq])) {
                            $tid = $chequeTargetIds[$chequeMapSeq];
                            $installmentInfo = $instById[$tid] ?? null;
                        } else {
                            $installmentInfo = null;
                        }
                        $chequeMapSeq++;
                        if (!$installmentInfo) {
                            $newType = ($postedInstallmentType !== '' && in_array($postedInstallmentType, $allowedPostedTypes, true))
                                ? $postedInstallmentType
                                : 'rent';
                            $createInst = $conn->prepare("
                                INSERT INTO re_lease_installments
                                    (company_id, lease_id, installment_date, amount, status, installment_type)
                                VALUES (?, ?, ?, ?, 'pending', ?)
                            ");
                            $createInst->execute([$currentCompanyId, $leaseId, $chequeDate, $chequeAmount, $newType]);
                            $newInstId = (int)$conn->lastInsertId();
                            $installmentInfo = [
                                'id' => $newInstId,
                                'installment_date' => $chequeDate,
                                'amount' => $chequeAmount,
                                'installment_type' => $newType,
                            ];
                            $instById[$newInstId] = $installmentInfo;
                            $allInstallments[] = $installmentInfo;
                        }
                        if (!$installmentInfo) {
                            continue;
                        }

                        // DATA SAFETY: never modify a locked (paid/partial/allocated/cleared) row.
                        // Keep it in the "submitted" set so the stale-cleanup below won't delete it.
                        if (isset($lockedInstIds[(int)$installmentInfo['id']])) {
                            $submittedInstallmentIds[] = (int)$installmentInfo['id'];
                            continue;
                        }

                        $chequeHolderName = trim($chequeData['cheque_holder_name'] ?? $tenantName);
                        $paymentMethodCheque = re_payment_schedule_normalize_method($chequeData['payment_method'] ?? 'cheque');
                        $bankName = trim($chequeData['bank_name'] ?? '');
                        $referenceNumber = trim((string)($chequeData['reference_number'] ?? ''));
                        // A stale system placeholder (CHQ-<lease>-<n>) must not be carried into the reference
                        // field, where it would shadow the real cheque number on the lease view.
                        if ($referenceNumber === '' || re_is_auto_cheque_number($referenceNumber)) {
                            $referenceNumber = $chequeNumber;
                        }
                        $scheduleNotes = trim((string)($chequeData['notes'] ?? ''));
                        
                        // Update installment amount and date to match cheque amount and date
                        re_payment_schedule_log_change($conn, $currentCompanyId, (int)$leaseId, (int)$installmentInfo['id'], null, 'amount', $installmentInfo['amount'] ?? '', $chequeAmount, $userId, $scheduleMismatchReason, 'lease_add', $accountingMode);
                        re_payment_schedule_log_change($conn, $currentCompanyId, (int)$leaseId, (int)$installmentInfo['id'], null, 'installment_date', $installmentInfo['installment_date'] ?? '', $chequeDate, $userId, $scheduleMismatchReason, 'lease_add', $accountingMode);
                        if ($postedInstallmentType !== '' && in_array($postedInstallmentType, $allowedPostedTypes, true)) {
                            $updateInst = $conn->prepare("
                                UPDATE re_lease_installments 
                                SET amount = ?, installment_date = ?, installment_type = ?, vat_amount = ?, notes = ?
                                WHERE id = ?
                            ");
                            $updateInst->execute([
                                $chequeAmount,
                                $chequeDate,
                                $postedInstallmentType,
                                $postedVatAmount,
                                $scheduleNotes !== '' ? $scheduleNotes : ($installmentInfo['notes'] ?? null),
                                $installmentInfo['id'],
                            ]);
                        } else {
                            $updateInst = $conn->prepare("
                                UPDATE re_lease_installments 
                                SET amount = ?, installment_date = ?, vat_amount = ?, notes = ?
                                WHERE id = ?
                            ");
                            $updateInst->execute([$chequeAmount, $chequeDate, $postedVatAmount, $scheduleNotes !== '' ? $scheduleNotes : ($installmentInfo['notes'] ?? null), $installmentInfo['id']]);
                        }
                        $submittedInstallmentIds[] = (int)$installmentInfo['id'];
                        
                        // Handle file upload
                        $chequePhotoPath = null;
                        if (!empty($_FILES['cheques']['tmp_name'][$index]['cheque_photo']) && 
                            $_FILES['cheques']['error'][$index]['cheque_photo'] === UPLOAD_ERR_OK) {
                            $uploadDir = __DIR__ . '/../../uploads/lease_cheques/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }
                            $extension = pathinfo($_FILES['cheques']['name'][$index]['cheque_photo'], PATHINFO_EXTENSION);
                            $filename = 'cheque_' . $leaseId . '_' . $index . '_' . time() . '.' . $extension;
                            $filepath = $uploadDir . $filename;
                            if (move_uploaded_file($_FILES['cheques']['tmp_name'][$index]['cheque_photo'], $filepath)) {
                                $chequePhotoPath = 'uploads/lease_cheques/' . $filename;
                            }
                        }
                        
                        // Check if cheque already exists for this installment
                        $stmt = $conn->prepare("
                            SELECT id, cheque_number, reference_number, cheque_date, cheque_amount, account_holder_name, bank_name, payment_method, notes
                            FROM re_post_dated_cheques 
                            WHERE lease_id = ? AND installment_id = ?
                        ");
                        $stmt->execute([$leaseId, $installmentInfo['id']]);
                        $existingCheque = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existingCheque) {
                            // Update existing cheque
                            foreach ([
                                'cheque_number' => $chequeNumber ?: ('CHQ-' . $leaseId . '-' . ($index + 1)),
                                'reference_number' => $referenceNumber,
                                'cheque_date' => $chequeDate,
                                'cheque_amount' => $chequeAmount,
                                'account_holder_name' => $chequeHolderName,
                                'bank_name' => $bankName,
                                'payment_method' => $paymentMethodCheque,
                                'notes' => $scheduleNotes,
                            ] as $field => $newValue) {
                                re_payment_schedule_log_change($conn, $currentCompanyId, (int)$leaseId, (int)$installmentInfo['id'], (int)$existingCheque['id'], $field, $existingCheque[$field] ?? '', $newValue, $userId, $scheduleMismatchReason, 'lease_add', $accountingMode);
                            }
                            $stmt = $conn->prepare("
                                UPDATE re_post_dated_cheques 
                                SET cheque_number = ?, reference_number = ?, cheque_date = ?, cheque_amount = ?, 
                                    account_holder_name = ?, bank_name = ?, payment_method = ?, notes = ?, updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([
                                $chequeNumber ?: ('CHQ-' . $leaseId . '-' . ($index + 1)),
                                $referenceNumber, $chequeDate, $chequeAmount, $chequeHolderName, $bankName, $paymentMethodCheque, $scheduleNotes ?: null, $existingCheque['id']
                            ]);
                            
                            // Update re_lease_cheques too
                            $stmt = $conn->prepare("
                                UPDATE re_lease_cheques 
                                SET cheque_number = ?, reference_number = ?, cheque_date = ?, cheque_amount = ?, 
                                    cheque_holder_name = ?, bank_name = ?, payment_method = ?, cheque_photo_path = ?, notes = ?
                                WHERE lease_id = ? AND installment_id = ?
                            ");
                            $stmt->execute([
                                $chequeNumber ?: ('CHQ-' . $leaseId . '-' . ($index + 1)),
                                $referenceNumber, $chequeDate, $chequeAmount, $chequeHolderName, $bankName, $paymentMethodCheque, 
                                $chequePhotoPath, $scheduleNotes ?: null, $leaseId, $installmentInfo['id']
                            ]);
                        } else {
                            // Insert new cheque
                            $stmt = $conn->prepare("
                                INSERT INTO re_post_dated_cheques 
                                (company_id, lease_id, installment_id, cheque_number, reference_number, cheque_date, cheque_amount, 
                                 account_holder_name, bank_name, payment_method, received_date, status, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pending', ?, ?)
                            ");
                            $stmt->execute([
                                $currentCompanyId, $leaseId, $installmentInfo['id'], 
                                $chequeNumber ?: ('CHQ-' . $leaseId . '-' . ($index + 1)), 
                                $referenceNumber, $chequeDate, $chequeAmount, $chequeHolderName, $bankName, $paymentMethodCheque, $scheduleNotes ?: null, $userId
                            ]);
                            $newChequeId = (int)$conn->lastInsertId();
                            re_payment_schedule_log_change($conn, $currentCompanyId, (int)$leaseId, (int)$installmentInfo['id'], $newChequeId, 'row_created', '', 'created', $userId, $scheduleMismatchReason, 'lease_add', $accountingMode);
                            
                            // Also insert into re_lease_cheques
                            $stmt = $conn->prepare("
                                INSERT INTO re_lease_cheques 
                                (company_id, lease_id, installment_id, cheque_number, reference_number, cheque_date, cheque_amount, cheque_holder_name, 
                                 bank_name, payment_method, cheque_photo_path, status, notes)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                            ");
                            $stmt->execute([
                                $currentCompanyId, $leaseId, $installmentInfo['id'], 
                                $chequeNumber ?: ('CHQ-' . $leaseId . '-' . ($index + 1)), 
                                $referenceNumber, $chequeDate, $chequeAmount, $chequeHolderName, $bankName, $paymentMethodCheque, $chequePhotoPath, $scheduleNotes ?: null
                            ]);
                        }
                    }

                    // Remove stale unpaid installments that are no longer represented by the submitted schedule.
                    // This prevents old generated rows from continuing to appear in Lease View after an edit.
                    $submittedInstallmentIds = array_values(array_unique(array_filter($submittedInstallmentIds)));
                    if (!empty($submittedInstallmentIds)) {
                        $keepPlaceholders = implode(',', array_fill(0, count($submittedInstallmentIds), '?'));
                        $hasPaymentAllocationsTable = false;
                        try {
                            $allocTableStmt = $conn->query("
                                SELECT COUNT(*)
                                FROM INFORMATION_SCHEMA.TABLES
                                WHERE TABLE_SCHEMA = DATABASE()
                                  AND TABLE_NAME = 're_payment_allocations'
                            ");
                            $hasPaymentAllocationsTable = ((int)$allocTableStmt->fetchColumn()) > 0;
                        } catch (Throwable $e) {
                            $hasPaymentAllocationsTable = false;
                        }
                        $allocationSafetySql = $hasPaymentAllocationsTable ? "
                              AND NOT EXISTS (
                                  SELECT 1
                                  FROM re_payment_allocations pa
                                  WHERE pa.installment_id = li.id
                                  LIMIT 1
                              )
                        " : "";
                        $staleParams = array_merge([$leaseId, $currentCompanyId], $submittedInstallmentIds);
                        $staleStmt = $conn->prepare("
                            SELECT id
                            FROM re_lease_installments li
                            WHERE li.lease_id = ?
                              AND li.company_id = ?
                              AND li.id NOT IN ($keepPlaceholders)
                              AND li.status NOT IN ('paid', 'partial')
                              AND li.payment_id IS NULL
                              AND li.invoice_id IS NULL
                              AND COALESCE(li.installment_type, '') NOT IN ('chiller','ejari','admin','commission','amc','parking','store','vat','combined_fees')
                              $allocationSafetySql
                        ");
                        $staleStmt->execute($staleParams);
                        $staleInstallmentIds = array_map('intval', $staleStmt->fetchAll(PDO::FETCH_COLUMN));

                        if (!empty($staleInstallmentIds)) {
                            $stalePlaceholders = implode(',', array_fill(0, count($staleInstallmentIds), '?'));
                            $deleteParams = array_merge([$leaseId], $staleInstallmentIds);
                            $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id IN ($stalePlaceholders)")
                                 ->execute($deleteParams);
                            $conn->prepare("DELETE FROM re_lease_cheques WHERE lease_id = ? AND installment_id IN ($stalePlaceholders)")
                                 ->execute($deleteParams);

                            $billingParams = array_merge([$currentCompanyId, $leaseId], $staleInstallmentIds);
                            $conn->prepare("
                                DELETE FROM re_billing_items
                                WHERE company_id = ?
                                  AND lease_id = ?
                                  AND item_type = 'penalty'
                                  AND payment_id IS NULL
                                  AND status IN ('pending', 'overdue', 'waived')
                                  AND installment_id IN ($stalePlaceholders)
                            ")->execute($billingParams);

                            $installmentParams = array_merge([$leaseId, $currentCompanyId], $staleInstallmentIds);
                            $conn->prepare("DELETE FROM re_lease_installments WHERE lease_id = ? AND company_id = ? AND id IN ($stalePlaceholders)")
                                 ->execute($installmentParams);
                        }
                    }
                }

                // ── Remove deactivated separate-fee rows & orphan combined-fees ──
                // When a fee is switched away from "Separate", or fees are merged into
                // the 1st installment, the old standalone installment must be removed.
                $activeSeparateTypes = [];
                if ($sepSecurityDeposit && !$isRenewalLease && $securityDeposit > 0) $activeSeparateTypes['security_deposit'] = true;
                if ($distChiller === 'separate' && $chillerFees > 0)        $activeSeparateTypes['chiller'] = true;
                if ($distEjari   === 'separate' && $ejariFees > 0)          $activeSeparateTypes['ejari'] = true;
                if ($distAdmin   === 'separate' && $adminFees > 0)          $activeSeparateTypes['admin'] = true;
                if ($distComm    === 'separate' && $commissionFees > 0)     $activeSeparateTypes['commission'] = true;

                $typesToCheck = ['security_deposit', 'chiller', 'ejari', 'admin', 'commission'];
                $typesToRemove = [];
                foreach ($typesToCheck as $t) {
                    if (!isset($activeSeparateTypes[$t])) {
                        $typesToRemove[] = $t;
                    }
                }
                if ($addFeesToFirstInstallment) {
                    $typesToRemove[] = 'combined_fees';
                }
                if (!empty($typesToRemove)) {
                    $rmPlaceholders = implode(',', array_fill(0, count($typesToRemove), '?'));
                    $rmStmt = $conn->prepare("
                        SELECT id FROM re_lease_installments
                        WHERE lease_id = ? AND company_id = ?
                          AND installment_type IN ($rmPlaceholders)
                          AND status NOT IN ('paid', 'partial')
                          AND payment_id IS NULL
                          AND invoice_id IS NULL
                    ");
                    $rmStmt->execute(array_merge([$leaseId, $currentCompanyId], $typesToRemove));
                    $rmIds = array_map('intval', $rmStmt->fetchAll(PDO::FETCH_COLUMN));
                    if (!empty($rmIds)) {
                        $rmIdPlaceholders = implode(',', array_fill(0, count($rmIds), '?'));
                        $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id IN ($rmIdPlaceholders)")
                             ->execute(array_merge([$leaseId], $rmIds));
                        $conn->prepare("DELETE FROM re_lease_cheques WHERE lease_id = ? AND installment_id IN ($rmIdPlaceholders)")
                             ->execute(array_merge([$leaseId], $rmIds));
                        $conn->prepare("DELETE FROM re_lease_installments WHERE lease_id = ? AND company_id = ? AND id IN ($rmIdPlaceholders)")
                             ->execute(array_merge([$leaseId, $currentCompanyId], $rmIds));
                    }
                }

                // ── VAT reconciliation: sync vat_amount on all installments ──
                // After all installment manipulation (create/update/remove fees/cheques),
                // set vat_amount consistently with the saved VAT distribution.
                $vatReconcileRows = $conn->prepare("
                    SELECT id, installment_type, amount, status, payment_id
                    FROM re_lease_installments
                    WHERE lease_id = ? AND company_id = ?
                    ORDER BY installment_date ASC, id ASC
                ");
                $vatReconcileRows->execute([$leaseId, $currentCompanyId]);
                $vatAllRows = $vatReconcileRows->fetchAll(PDO::FETCH_ASSOC);

                $vatExistingSepInst = null;
                $vatRegularIds = [];
                $vatFeesInstId = null;
                foreach ($vatAllRows as $vr) {
                    $vType = (string)($vr['installment_type'] ?? '');
                    if ($vType === 'vat') {
                        $vatExistingSepInst = $vr;
                    } elseif ($vType === 'amc' || in_array($vType, ['chiller','ejari','admin','commission','parking','store','combined_fees'], true)) {
                        // non-rent typed installments — not part of rent VAT split
                    } else {
                        $vatRegularIds[] = $vr;
                    }
                }

                if ($vatDistributionType === 'separate_payment') {
                    if ($totalVatStored > 0) {
                        if ($vatExistingSepInst) {
                            $isPaid = ($vatExistingSepInst['status'] === 'paid' || !empty($vatExistingSepInst['payment_id']));
                            if (!$isPaid) {
                                $conn->prepare("UPDATE re_lease_installments SET amount = ?, vat_amount = ? WHERE id = ?")
                                     ->execute([$totalVatStored, $totalVatStored, $vatExistingSepInst['id']]);
                            }
                        } else {
                            $conn->prepare("INSERT INTO re_lease_installments (company_id, lease_id, installment_date, amount, vat_amount, status, installment_type) VALUES (?, ?, ?, ?, ?, 'pending', 'vat')")
                                 ->execute([$currentCompanyId, $leaseId, $startDate, $totalVatStored, $totalVatStored]);
                        }
                    } elseif ($vatExistingSepInst) {
                        $isPaid = ($vatExistingSepInst['status'] === 'paid' || !empty($vatExistingSepInst['payment_id']));
                        if (!$isPaid) {
                            $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id = ?")->execute([$leaseId, $vatExistingSepInst['id']]);
                            $conn->prepare("DELETE FROM re_lease_cheques WHERE lease_id = ? AND installment_id = ?")->execute([$leaseId, $vatExistingSepInst['id']]);
                            $conn->prepare("DELETE FROM re_lease_installments WHERE id = ?")->execute([$vatExistingSepInst['id']]);
                        }
                    }
                    foreach ($vatRegularIds as $vr) {
                        $conn->prepare("UPDATE re_lease_installments SET vat_amount = 0 WHERE id = ?")->execute([$vr['id']]);
                    }
                } else {
                    if ($vatExistingSepInst) {
                        $isPaid = ($vatExistingSepInst['status'] === 'paid' || !empty($vatExistingSepInst['payment_id']));
                        if (!$isPaid) {
                            $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id = ?")->execute([$leaseId, $vatExistingSepInst['id']]);
                            $conn->prepare("DELETE FROM re_lease_cheques WHERE lease_id = ? AND installment_id = ?")->execute([$leaseId, $vatExistingSepInst['id']]);
                            $conn->prepare("DELETE FROM re_lease_installments WHERE id = ?")->execute([$vatExistingSepInst['id']]);
                        }
                    }

                    if ($vatDistributionType === 'split_installments' && $totalVatStored > 0) {
                        $vatN = count($vatRegularIds);
                        if ($vatN > 0) {
                            $vatPerRow = round($totalVatStored / $vatN, 2);
                            $vatAccum = 0.0;
                            foreach ($vatRegularIds as $idx => $vr) {
                                $lineVat = ($idx === $vatN - 1)
                                    ? round($totalVatStored - $vatAccum, 2)
                                    : $vatPerRow;
                                $vatAccum += $lineVat;
                                $conn->prepare("UPDATE re_lease_installments SET vat_amount = ? WHERE id = ?")->execute([$lineVat, $vr['id']]);
                            }
                        }
                    } elseif ($vatDistributionType === 'first_installment' && $totalVatStored > 0) {
                        $combinedFeesVatId = null;
                        foreach ($vatAllRows as $vr) {
                            if (($vr['installment_type'] ?? '') === 'combined_fees') {
                                $combinedFeesVatId = (int)$vr['id'];
                                break;
                            }
                        }
                        if ($combinedFeesVatId > 0 && !$addFeesToFirstInstallment) {
                            $conn->prepare("UPDATE re_lease_installments SET vat_amount = ? WHERE id = ?")
                                 ->execute([$totalVatStored, $combinedFeesVatId]);
                            foreach ($vatRegularIds as $vr) {
                                $conn->prepare("UPDATE re_lease_installments SET vat_amount = 0 WHERE id = ?")->execute([$vr['id']]);
                            }
                        } else {
                            $firstDone = false;
                            foreach ($vatRegularIds as $vr) {
                                if (!$firstDone) {
                                    $conn->prepare("UPDATE re_lease_installments SET vat_amount = ? WHERE id = ?")->execute([$totalVatStored, $vr['id']]);
                                    $firstDone = true;
                                } else {
                                    $conn->prepare("UPDATE re_lease_installments SET vat_amount = 0 WHERE id = ?")->execute([$vr['id']]);
                                }
                            }
                        }
                    } else {
                        foreach ($vatRegularIds as $vr) {
                            $conn->prepare("UPDATE re_lease_installments SET vat_amount = 0 WHERE id = ?")->execute([$vr['id']]);
                        }
                    }
                }

                if (empty($_POST['cheques']) || !is_array($_POST['cheques'])) {
                    lease_reconcile_unpaid_rent_installments(
                        $conn,
                        $leaseId,
                        $currentCompanyId,
                        $effectiveAnnualRent,
                        $numberOfInstallments,
                        $addFeesToFirstInstallment,
                        $totalFeesToFirst
                    );

                    $leaseForSeparate = $conn->prepare('SELECT * FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1');
                    $leaseForSeparate->execute([$leaseId, $currentCompanyId]);
                    $leaseRowForSeparate = $leaseForSeparate->fetch(PDO::FETCH_ASSOC);
                    if ($leaseRowForSeparate) {
                        $leaseRowForSeparate['sep_security_deposit'] = $sepSecurityDeposit ? 1 : 0;
                        lease_reconcile_separate_fee_installments($conn, $leaseId, $currentCompanyId, $leaseRowForSeparate);
                    }
                }

                // Final invariant: cheque mirror tables == installment amount for all unlocked rows.
                lease_sync_cheque_amounts($conn, (int)$leaseId, (int)$currentCompanyId);
                // Keep deferred-revenue recognition aligned (pending+unfunded, unlocked rows only).
                if ($accountingMode === 'legacy') {
                    lease_sync_recognition_schedule($conn, (int)$leaseId, (int)$currentCompanyId);
                }

                // Invalidate any previously generated contract PDF so a stale schedule is never served.
                $conn->prepare("UPDATE re_leases SET generated_contract_path = NULL WHERE id = ? AND company_id = ?")
                     ->execute([$leaseId, $currentCompanyId]);

                $success = "Lease updated successfully";
            } else {
                // Insert
                $stmt = $conn->prepare("
                    INSERT INTO re_leases 
                    (company_id, unit_id, tenant_id, lease_number, start_date, end_date,
                     annual_rent, monthly_rent, number_of_installments, security_deposit, 
                     chiller_fees, ejari_fees, admin_fees, commission_fees,
                     amc_amount, split_amc_fees, sep_amc_fees,
                     add_fees_to_first_installment,
                     deferred_revenue_mode,
                     is_renewal_lease,
                     split_chiller_fees, sep_chiller_fees,
                     split_ejari_fees, sep_ejari_fees,
                     split_admin_fees, sep_admin_fees,
                     split_commission_fees, sep_commission_fees,
                     split_additional_parking, sep_additional_parking,
                     split_additional_store, sep_additional_store,
                     payment_day, payment_method, 
                     grace_period_days, renewal_terms, template_id,
                     has_additional_parking, additional_parking_fee, additional_parking_start_date, additional_parking_end_date,
                     has_additional_store, additional_store_fee, additional_store_start_date, additional_store_end_date,
                     ejari_registration_number, ejari_issue_date, ejari_property_code, ejari_document_path,
                     landlord_signature_path, tenant_signature_path, company_stamp_path,
                     status, move_in_date, notes, created_by, is_multi_unit,
                     vat_applicable_on_rent, vat_applicable_on_extra_charges, lease_vat_rate,
                     rent_vat_amount, extra_services_vat_amount, total_vat_amount,
                     vat_distribution_type, vat_notes, accounting_mode)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $unitId, $tenantId, $leaseNumber, $startDate, $endDate,
                    $annualRent, $monthlyRent, $numberOfInstallments, $securityDeposit,
                    $chillerFees, $ejariFees, $adminFees, $commissionFees,
                    $amcAmount, $splitAmcFees ? 1 : 0, $sepAmcFees ? 1 : 0,
                    $addFeesToFirstInstallment ? 1 : 0,
                    $deferredRevenueMode ? 1 : 0,
                    $isRenewalLease ? 1 : 0,
                    $splitChillerFees ? 1 : 0, $sepChillerFees ? 1 : 0,
                    $splitEjariFees ? 1 : 0, $sepEjariFees ? 1 : 0,
                    $splitAdminFees ? 1 : 0, $sepAdminFees ? 1 : 0,
                    $splitCommissionFees ? 1 : 0, $sepCommissionFees ? 1 : 0,
                    $splitAdditionalParking ? 1 : 0, $sepAdditionalParking ? 1 : 0,
                    $splitAdditionalStore ? 1 : 0, $sepAdditionalStore ? 1 : 0,
                    $paymentDay, $paymentMethod,
                    $gracePeriodDays, $renewalTerms ?: null, $templateId,
                    $hasAdditionalParking ? 1 : 0, $additionalParkingFee, $additionalParkingStartDate, $additionalParkingEndDate,
                    $hasAdditionalStore ? 1 : 0, $additionalStoreFee, $additionalStoreStartDate, $additionalStoreEndDate,
                    $ejariRegistrationNumber ?: null, $ejariIssueDate, $ejariPropertyCode ?: null, $ejariDocumentPath ?: null,
                    $landlordSignaturePath ?: null, $tenantSignaturePath ?: null, $companyStampPath ?: null,
                    $status, $moveInDate, $notes ?: null, $userId,
                    $isMultiUnit ? 1 : 0,
                    $vatApplicableOnRent ? 1 : 0,
                    $vatApplicableOnExtraCharges ? 1 : 0,
                    $leaseVatRate,
                    $rentVatStored,
                    $extraVatStored,
                    $totalVatStored,
                    $vatDistributionType,
                    $vatNotesLease !== '' ? $vatNotesLease : null,
                    $accountingMode,
                ]);
                $leaseId = $conn->lastInsertId();
                re_accounting_log_mode_change(
                    $conn,
                    $currentCompanyId,
                    (int)$leaseId,
                    null,
                    $accountingMode,
                    $userId,
                    $accountingModeOverrideReason ?: ($isRenewalLease ? 'Default accounting mode for renewal' : 'Default accounting mode for new lease'),
                    $isRenewalLease ? 'lease_renewal' : 'lease_create'
                );
                
                // Use same fees-to-first as computed above
                $totalFees = $totalFeesToFirst;
                
                // Generate installments based on number_of_installments
                $start = new DateTime($startDate);
                $end = new DateTime($endDate);
                $totalDays = $end->diff($start)->days;
                $intervalDays = (int)($totalDays / $numberOfInstallments);
                if ($intervalDays < 1) {
                    $intervalDays = 1;
                }

                $current = clone $start;
                $installmentIds = []; // Store installment IDs for cheque creation
                $firstInstallmentDate = null;
                $separateInstDate = $start->format('Y-m-d');

                // ── STEP 1: Separate-installment fees (one installment per fee) ────────
                // Order here MUST match JS generateChequesRows() separateFeeList order.
                $separateFeeList = [];
                if ($sepSecurityDeposit && !$isRenewalLease && $securityDeposit > 0) {
                    $separateFeeList[] = ['dist' => 'separate', 'amount' => $securityDeposit, 'type' => 'security_deposit'];
                }
                $separateFeeList = array_merge($separateFeeList, [
                    ['dist' => $distChiller,  'amount' => $chillerFees,      'type' => 'chiller'],
                    ['dist' => $distEjari,    'amount' => $ejariFees,        'type' => 'ejari'],
                    ['dist' => $distAdmin,    'amount' => $adminFees,        'type' => 'admin'],
                    ['dist' => $distComm,     'amount' => $commissionFees,   'type' => 'commission'],
                ]);
                if ($vatDistributionType === 'separate_payment' && $totalVatStored > 0) {
                    $separateFeeList[] = ['dist' => 'separate', 'amount' => $totalVatStored, 'type' => 'vat'];
                }
                foreach ($separateFeeList as $sf) {
                    if ($sf['dist'] === 'separate' && $sf['amount'] > 0) {
                        $vatAmtIns = ($sf['type'] === 'vat') ? (float)$sf['amount'] : 0.0;
                        $conn->prepare("INSERT INTO re_lease_installments (company_id, lease_id, installment_date, amount, vat_amount, status, installment_type) VALUES (?, ?, ?, ?, ?, 'pending', ?)")
                             ->execute([$currentCompanyId, $leaseId, $separateInstDate, $sf['amount'], $vatAmtIns, $sf['type']]);
                        $installmentIds[] = ['id' => (int)$conn->lastInsertId(), 'date' => $separateInstDate, 'amount' => $sf['amount']];
                        if (!$firstInstallmentDate) $firstInstallmentDate = $separateInstDate;
                    }
                }

                // ── STEP 2: Combined fees installment for 'first'-mode fees ───────────
                if (!$addFeesToFirstInstallment && $totalFees > 0) {
                    $feesDate = $separateInstDate;
                    $feesVatAmount = 0.0;
                    if ($vatDistributionType === 'first_installment' && $totalVatStored > 0) {
                        $feesVatAmount = $totalVatStored;
                    }
                    $conn->prepare("INSERT INTO re_lease_installments (company_id, lease_id, installment_date, amount, vat_amount, status, installment_type) VALUES (?, ?, ?, ?, ?, 'pending', 'combined_fees')")
                         ->execute([$currentCompanyId, $leaseId, $feesDate, $totalFees, $feesVatAmount]);
                    $installmentIds[] = ['id' => (int)$conn->lastInsertId(), 'date' => $feesDate, 'amount' => $totalFees];
                    if (!$firstInstallmentDate) $firstInstallmentDate = $feesDate;
                }

                // ── STEP 3: Rent installments ─────────────────────────────────────────
                $vatPerRentInst = ($vatDistributionType === 'split_installments' && $totalVatStored > 0 && $numberOfInstallments > 0)
                    ? round($totalVatStored / $numberOfInstallments, 2)
                    : 0.0;
                $rentInstallmentAmounts = lease_compute_rent_installment_amounts(
                    $effectiveAnnualRent,
                    $numberOfInstallments,
                    $addFeesToFirstInstallment,
                    $totalFees
                );
                for ($i = 0; $i < $numberOfInstallments; $i++) {
                    $installmentAmount = $rentInstallmentAmounts[$i] ?? $monthlyRent;
                    if ($i === $numberOfInstallments - 1) {
                        $installmentDate = $end->format('Y-m-d');
                    } else {
                        $installmentDate = $current->format('Y-m-d');
                    }
                    if (!$firstInstallmentDate) $firstInstallmentDate = $installmentDate;

                    $lineVat = 0.0;
                    if ($vatDistributionType === 'split_installments' && $totalVatStored > 0) {
                        if ($i === $numberOfInstallments - 1) {
                            $lineVat = round($totalVatStored - $vatPerRentInst * ($numberOfInstallments - 1), 2);
                        } else {
                            $lineVat = $vatPerRentInst;
                        }
                    } elseif ($vatDistributionType === 'first_installment' && $totalVatStored > 0 && $addFeesToFirstInstallment && $i === 0) {
                        $lineVat = $totalVatStored;
                    }

                    $conn->prepare("INSERT INTO re_lease_installments (company_id, lease_id, installment_date, amount, vat_amount, status) VALUES (?, ?, ?, ?, ?, 'pending')")
                         ->execute([$currentCompanyId, $leaseId, $installmentDate, $installmentAmount, $lineVat]);
                    $installmentId = (int)$conn->lastInsertId();

                    // Match JS generateChequesRows(): last cheque date is lease end_date; others advance by intervalDays.
                    if ($i < $numberOfInstallments - 1) {
                        $current->modify("+{$intervalDays} days");
                    }
                    $installmentIds[] = ['id' => $installmentId, 'date' => $installmentDate, 'amount' => $installmentAmount];
                }

                // Handle operational payment schedule mirrors for all payment methods.
                if (true) {
                    // Get tenant name for cheque holder
                    $tenantStmt = $conn->prepare("SELECT first_name, last_name FROM re_tenants WHERE id = ?");
                    $tenantStmt->execute([$tenantId]);
                    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
                    $tenantName = ($tenant ? $tenant['first_name'] . ' ' . $tenant['last_name'] : '');
                    
                    // Check if cheques were provided manually in form
                    if (!empty($_POST['cheques']) && is_array($_POST['cheques'])) {
                        // Use manually provided cheque data
                        foreach ($_POST['cheques'] as $index => $chequeData) {
                            if (empty($chequeData['amount']) || $chequeData['amount'] <= 0) continue;
                            $chequeNumber = trim($chequeData['cheque_number'] ?? '');
                            $fallbackDate = $installmentIds[$index]['date'] ?? $startDate;
                            $chequeDateRaw = !empty($chequeData['cheque_date']) ? $chequeData['cheque_date'] : $fallbackDate;
                            // Ensure date is in YYYY-MM-DD format (convert from MM/DD/YYYY if needed)
                            $chequeDate = $chequeDateRaw;
                            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $chequeDateRaw, $matches)) {
                                // Convert MM/DD/YYYY to YYYY-MM-DD
                                $chequeDate = $matches[3] . '-' . $matches[1] . '-' . $matches[2];
                            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $chequeDateRaw)) {
                                // Already in YYYY-MM-DD format
                                $chequeDate = $chequeDateRaw;
                            } else {
                                // Try to parse with strtotime as fallback
                                $parsed = strtotime($chequeDateRaw);
                                if ($parsed !== false) {
                                    $chequeDate = date('Y-m-d', $parsed);
                                } else {
                                    $chequeDate = $fallbackDate; // Fallback to existing date
                                }
                            }
                            $chequeAmount = (float)$chequeData['amount'];
                            $postedVatAmount = isset($chequeData['vat_amount']) ? (float)$chequeData['vat_amount'] : 0.0;
                            if (isset($installmentIds[$index])) {
                                $installmentInfo = $installmentIds[$index];
                            } else {
                                $createManualInst = $conn->prepare("
                                    INSERT INTO re_lease_installments
                                        (company_id, lease_id, installment_date, amount, status, installment_type)
                                    VALUES (?, ?, ?, ?, 'pending', 'rent')
                                ");
                                $createManualInst->execute([$currentCompanyId, $leaseId, $chequeDate, $chequeAmount]);
                                $installmentInfo = ['id' => (int)$conn->lastInsertId(), 'date' => $chequeDate, 'amount' => $chequeAmount];
                            }
                            $chequeHolderName = trim($chequeData['cheque_holder_name'] ?? $tenantName);
                            $paymentMethodCheque = re_payment_schedule_normalize_method($chequeData['payment_method'] ?? $paymentMethod);
                            $bankName = trim($chequeData['bank_name'] ?? '');
                            $referenceNumber = trim((string)($chequeData['reference_number'] ?? ''));
                            // A stale system placeholder (CHQ-<lease>-<n>) must not be carried into the reference
                            // field, where it would shadow the real cheque number on the lease view.
                            if ($referenceNumber === '' || re_is_auto_cheque_number($referenceNumber)) {
                                $referenceNumber = $chequeNumber;
                            }
                            $scheduleNotes = trim((string)($chequeData['notes'] ?? ''));

                            // Keep installment amount and date in sync with the user-edited cheque amount and date
                            $updateInst = $conn->prepare("
                                UPDATE re_lease_installments 
                                SET amount = ?, installment_date = ?, vat_amount = ?
                                WHERE id = ?
                            ");
                            $updateInst->execute([$chequeAmount, $chequeDate, $postedVatAmount, $installmentInfo['id']]);
                            
                            // Handle file upload
                            $chequePhotoPath = null;
                            if (!empty($_FILES['cheques']['tmp_name'][$index]['cheque_photo']) && 
                                $_FILES['cheques']['error'][$index]['cheque_photo'] === UPLOAD_ERR_OK) {
                                $uploadDir = __DIR__ . '/../../uploads/lease_cheques/';
                                if (!is_dir($uploadDir)) {
                                    mkdir($uploadDir, 0755, true);
                                }
                                $extension = pathinfo($_FILES['cheques']['name'][$index]['cheque_photo'], PATHINFO_EXTENSION);
                                $filename = 'cheque_' . $leaseId . '_' . $index . '_' . time() . '.' . $extension;
                                $filepath = $uploadDir . $filename;
                                if (move_uploaded_file($_FILES['cheques']['tmp_name'][$index]['cheque_photo'], $filepath)) {
                                    $chequePhotoPath = 'uploads/lease_cheques/' . $filename;
                                }
                            }
                            
                            // Insert into re_post_dated_cheques (for billing page)
                            $stmt = $conn->prepare("
                                INSERT INTO re_post_dated_cheques 
                                (company_id, lease_id, installment_id, cheque_number, reference_number, cheque_date, cheque_amount, 
                                 account_holder_name, bank_name, payment_method, received_date, status, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pending', ?, ?)
                            ");
                            $stmt->execute([
                                $currentCompanyId, $leaseId, $installmentInfo['id'], 
                                $chequeNumber ?: ('CHQ-' . $leaseId . '-' . ($index + 1)), 
                                $referenceNumber, $chequeDate, $chequeAmount, $chequeHolderName, $bankName, $paymentMethodCheque, $scheduleNotes ?: null, $userId
                            ]);
                            $newChequeId = (int)$conn->lastInsertId();
                            re_payment_schedule_log_change($conn, $currentCompanyId, (int)$leaseId, (int)$installmentInfo['id'], $newChequeId, 'row_created', '', 'created', $userId, $scheduleMismatchReason, 'lease_add', $accountingMode);
                            
                            // Also insert into re_lease_cheques (for backward compatibility)
                            $stmt = $conn->prepare("
                                INSERT INTO re_lease_cheques 
                                (company_id, lease_id, installment_id, cheque_number, reference_number, cheque_date, cheque_amount, cheque_holder_name, 
                                 bank_name, payment_method, cheque_photo_path, status, notes)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                            ");
                            $stmt->execute([
                                $currentCompanyId, $leaseId, $installmentInfo['id'], 
                                $chequeNumber ?: ('CHQ-' . $leaseId . '-' . ($index + 1)), 
                                $referenceNumber, $chequeDate, $chequeAmount, $chequeHolderName, $bankName, $paymentMethodCheque, $chequePhotoPath, $scheduleNotes ?: null
                            ]);
                        }
                    } else {
                        // Automatically create cheques for all installments (no manual data provided)
                        foreach ($installmentIds as $index => $installmentInfo) {
                            $chequeNumber = 'CHQ-' . $leaseId . '-' . ($index + 1);
                            
                            // Insert into re_post_dated_cheques (for billing page)
                            $stmt = $conn->prepare("
                                INSERT INTO re_post_dated_cheques 
                                (company_id, lease_id, installment_id, cheque_number, reference_number, cheque_date, cheque_amount, 
                                 account_holder_name, payment_method, received_date, status, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pending', ?)
                            ");
                            $stmt->execute([
                                $currentCompanyId, $leaseId, $installmentInfo['id'], 
                                $chequeNumber, $chequeNumber, $installmentInfo['date'], $installmentInfo['amount'], 
                                $tenantName, re_payment_schedule_normalize_method($paymentMethod), $userId
                            ]);
                            $newChequeId = (int)$conn->lastInsertId();
                            re_payment_schedule_log_change($conn, $currentCompanyId, (int)$leaseId, (int)$installmentInfo['id'], $newChequeId, 'row_created', '', 'created', $userId, 'Auto split schedule generated', 'lease_add', $accountingMode);
                            
                            // Also insert into re_lease_cheques (for backward compatibility)
                            $stmt = $conn->prepare("
                                INSERT INTO re_lease_cheques 
                                (company_id, lease_id, installment_id, cheque_number, reference_number, cheque_date, cheque_amount, cheque_holder_name, 
                                 payment_method, status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                            ");
                            $stmt->execute([
                                $currentCompanyId, $leaseId, $installmentInfo['id'], 
                                $chequeNumber, $chequeNumber, $installmentInfo['date'], $installmentInfo['amount'], $tenantName, re_payment_schedule_normalize_method($paymentMethod)
                            ]);
                        }
                    }
                }
                
                // Update unit status to occupied
                $stmt = $conn->prepare("UPDATE re_units SET status = 'occupied' WHERE id = ? AND company_id = ?");
                $stmt->execute([$unitId, $currentCompanyId]);

                // Save per-unit rent breakdown to re_lease_units
                $luStmt = $conn->prepare("
                    INSERT IGNORE INTO re_lease_units (company_id, lease_id, unit_id, annual_rent, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                ");
                if (!empty($multiUnits)) {
                    foreach ($multiUnits as $idx => $mu) {
                        $luStmt->execute([$currentCompanyId, $leaseId, $mu['unit_id'], $mu['annual_rent'], $idx]);
                        if ($idx > 0) {
                            $conn->prepare("UPDATE re_units SET status='occupied' WHERE id=? AND company_id=?")
                                 ->execute([$mu['unit_id'], $currentCompanyId]);
                        }
                    }
                } else {
                    $luStmt->execute([$currentCompanyId, $leaseId, $unitId, $annualRent, 0]);
                }

                $success = "Lease created successfully";
            }

            if (!empty($scheduleValidation['requires_approval']) && abs((float)$scheduleValidation['difference']) > 0.02) {
                re_payment_schedule_log_mismatch_approval(
                    $conn,
                    $currentCompanyId,
                    (int)$leaseId,
                    (float)$scheduleValidation['expected_total'],
                    (float)$scheduleValidation['scheduled_total'],
                    (float)$scheduleValidation['difference'],
                    $userId,
                    $scheduleMismatchReason,
                    'lease_add',
                    $accountingMode
                );
            }

            $invoiceModeSyncNote = '';
            if ($accountingMode === 'invoice') {
                $obRes = re_obligation_engine_generate_for_lease($conn, $currentCompanyId, (int)$leaseId, $userId);
                if (empty($obRes['success'])) {
                    throw new Exception($obRes['error'] ?? 'Could not sync lease obligations.');
                }
                $candRes = re_invoice_engine_prepare_candidates_for_lease($conn, $currentCompanyId, (int)$leaseId, $userId);
                if (empty($candRes['success'])) {
                    throw new Exception($candRes['error'] ?? 'Could not sync invoice candidates.');
                }
                $obStats = $obRes['stats'] ?? [];
                $candStats = $candRes['stats'] ?? [];
                $invoiceModeSyncNote = sprintf(
                    ' Obligations synced (%d created, %d updated, %d unchanged). Invoice candidates prepared (%d new, %d already existed).',
                    (int)($obStats['created'] ?? 0),
                    (int)($obStats['updated'] ?? 0),
                    (int)($obStats['unchanged'] ?? 0),
                    (int)($candStats['created'] ?? 0),
                    (int)($candStats['already_exists'] ?? 0)
                );
            }
            
            $conn->commit();

            // Audit History (fail-safe; does not affect lease save)
            try {
                require_once __DIR__ . '/../../includes/audit_bridge.php';
                $auditNote = trim((string)$invoiceModeSyncNote);
                audit_bridge_re_ops(
                    $conn,
                    (int)$currentCompanyId,
                    $wasNewLease ? 'lease_created' : 'lease_updated',
                    're_leases',
                    (int)$leaseId,
                    (string)$leaseNumber,
                    ($wasNewLease ? 'Created lease ' : 'Updated lease ') . $leaseNumber
                        . ($auditNote !== '' ? ' —' . $auditNote : ''),
                    null,
                    [
                        'lease_number' => $leaseNumber,
                        'tenant_id' => (int)$tenantId,
                        'unit_id' => (int)$unitId,
                        'accounting_mode' => $accountingMode,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    (int)$userId
                );
            } catch (Throwable $e) {
                error_log('lease_add audit: ' . $e->getMessage());
            }
            
            // Post security deposit to accounting if deposit > 0 (only for new leases, not updates)
            if ($securityDeposit > 0 && $wasNewLease && $accountingMode === 'legacy') {
                try {
                    require_once __DIR__ . '/accounting/accounting_integration.php';
                    $accountingResult = post_security_deposit_to_accounting($leaseId, $currentCompanyId, $userId);
                    if (!$accountingResult['success']) {
                        error_log("Accounting posting failed for security deposit (lease {$leaseId}): " . $accountingResult['error']);
                        $_SESSION['accounting_warning'] = "Lease saved, but the security deposit journal could not be posted automatically. Reason: {$accountingResult['error']}. Please post it manually from Journal Entries.";
                    }
                } catch (Exception $e) {
                    error_log("Accounting integration error for security deposit (lease {$leaseId}): " . $e->getMessage());
                    $_SESSION['accounting_warning'] = "Lease saved, but an error occurred posting the security deposit to accounting: {$e->getMessage()}. Please post it manually.";
                }
            }

            // If deferred revenue mode is enabled, pre-populate the recognition schedule
            // for all installments so the Revenue Recognition page shows the full plan
            // even before payments are recorded.
            if ($deferredRevenueMode && $accountingMode === 'legacy') {
                try {
                    require_once __DIR__ . '/accounting/accounting_integration.php';
                    seed_recognition_schedule_for_lease($leaseId, $currentCompanyId);
                } catch (Exception $e) {
                    error_log("Recognition schedule seeding failed for lease {$leaseId}: " . $e->getMessage());
                }
            }
            
            $_SESSION['success'] = ($wasNewLease ? 'Lease created successfully' : 'Lease updated successfully') . $invoiceModeSyncNote;
            header('Location: lease_view.php?id=' . $leaseId);
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Error: " . $e->getMessage();
            // Store POST data to preserve form values on error
            $formData = $_POST;
            $formData['unit_id'] = $unitId;
            $formData['tenant_id'] = $tenantId;
        }
    } else {
        if (!$error) {
            $error = "Please fill in all required fields";
        }
        // Store POST data to preserve form values on error
        $formData = $_POST;
    }
}

// Load lease if editing
$existingCheques = [];
if ($leaseId) {
    $stmt = $conn->prepare("SELECT * FROM re_leases WHERE id = ? AND company_id = ? AND " . re_lease_not_deleted_sql($conn, 're_leases'));
    $stmt->execute([$leaseId, $currentCompanyId]);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lease) {
        header('Location: leases.php');
        exit;
    }
    $unitId = $lease['unit_id'];
    $tenantId = $lease['tenant_id'];

    // Load per-unit rows for multi-unit leases (used to pre-fill the form)
    $existingLeaseUnits = [];
    $stmt = $conn->prepare("
        SELECT lu.unit_id, lu.annual_rent, lu.sort_order,
               u.unit_number, b.name AS building_name, u.unit_type
        FROM re_lease_units lu
        JOIN re_units u ON u.id = lu.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE lu.lease_id = ?
        ORDER BY lu.sort_order ASC, lu.id ASC
    ");
    $stmt->execute([$leaseId]);
    $existingLeaseUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load existing cheques with installment info
    $stmt = $conn->prepare("
        SELECT 
            c.*,
            li.id as installment_id,
            li.installment_date,
            li.amount as installment_amount,
            lc.payment_method,
            lc.cheque_photo_path
        FROM re_post_dated_cheques c
        JOIN re_lease_installments li ON li.id = c.installment_id
        LEFT JOIN re_lease_cheques lc ON lc.installment_id = c.installment_id AND lc.lease_id = c.lease_id
        WHERE c.lease_id = ?
        ORDER BY li.installment_date ASC, li.id ASC, c.id ASC
    ");
    $stmt->execute([$leaseId]);
    $existingCheques = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Derive Security Deposit "separate" mode from the existence of a security_deposit installment (read-only).
    $sdExistsStmt = $conn->prepare("SELECT COUNT(*) FROM re_lease_installments WHERE lease_id = ? AND company_id = ? AND installment_type = 'security_deposit'");
    $sdExistsStmt->execute([$leaseId, $currentCompanyId]);
    $lease['sep_security_deposit'] = ((int)$sdExistsStmt->fetchColumn() > 0) ? 1 : 0;
    // NOTE: the edit form is read-only on GET — it no longer reconciles/tags the schedule
    // on load. Reconciliation happens deterministically on Save, or via the "Repair schedule"
    // button on the lease view page. This keeps GET requests side-effect free.

    // Also load all installments to ensure we have the correct dates and types
    $stmt = $conn->prepare("
        SELECT li.id, li.installment_date, li.amount, li.vat_amount, li.installment_type, li.status, li.payment_id,
               (SELECT COUNT(*) FROM re_payment_allocations pa WHERE pa.installment_id = li.id) AS has_allocation,
               (SELECT c.status FROM re_post_dated_cheques c WHERE c.installment_id = li.id AND c.lease_id = li.lease_id ORDER BY c.id DESC LIMIT 1) AS cheque_status
        FROM re_lease_installments li
        WHERE li.lease_id = ?
        ORDER BY li.installment_date ASC, li.id ASC
    ");
    $stmt->execute([$leaseId]);
    $allInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$displayAccountingMode = $lease
    ? re_accounting_mode_for_existing_lease($conn, $lease)
    : re_accounting_default_mode_for_context($conn, 'new', date('Y-m-d'));
$displayModeIsInvoice = $displayAccountingMode === 'invoice';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Helper function to get form value (prioritize formData on error, then lease data)
function getFormValue($key, $default = '') {
    global $formData, $lease;
    if ($formData && isset($formData[$key])) {
        return $formData[$key];
    }
    if ($lease && isset($lease[$key])) {
        return $lease[$key];
    }
    return $default;
}

/** Checkbox: after POST error, formData may explicitly contain unchecked keys. */
function getCheckboxFormValue(string $name, bool $default = false): bool {
    global $formData, $lease;
    if ($formData !== null && array_key_exists($name, $formData)) {
        return !empty($formData[$name]);
    }
    if ($lease && array_key_exists($name, $lease)) {
        return !empty($lease[$name]);
    }
    return $default;
}

$leaseFormUnitType = '';
if (!empty($lease) && !empty($lease['unit_id'])) {
    try {
        $uqt = $conn->prepare("SELECT unit_type FROM re_units WHERE id = ? AND company_id = ? LIMIT 1");
        $uqt->execute([(int)$lease['unit_id'], $currentCompanyId]);
        $leaseFormUnitType = (string)($uqt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $leaseFormUnitType = '';
    }
}
$leaseCommercialForVat = lease_vat_unit_is_commercial($leaseFormUnitType);

// Set page title and include layout
$pageTitle = ($leaseId ? 'Edit' : 'Add') . ' Lease';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><?= $leaseId ? 'Edit' : 'Add' ?> Lease</div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

                    <form method="POST" id="leaseForm" enctype="multipart/form-data" action="<?= $_SERVER['PHP_SELF'] . ($leaseId ? '?id=' . $leaseId : '') ?>">
            <?php csrf_field(); ?>
            <?php if ($leaseId): ?>
                <input type="hidden" name="lease_id" value="<?= $leaseId ?>">
            <?php endif; ?>
            <?php
                $formAccountingMode = re_accounting_normalize_mode((string)getFormValue('accounting_mode', $displayAccountingMode));
                $featureEnabled = !empty($accountingModeSettings['feature_enabled']);
            ?>
            <div class="alert <?= $formAccountingMode === 'invoice' ? 'alert-info' : 'alert-warning' ?> d-flex flex-column flex-lg-row justify-content-between gap-3">
                <div>
                    <div class="fw-bold">
                        Accounting Mode:
                        <span class="badge bg-<?= $formAccountingMode === 'invoice' ? 'primary' : 'secondary' ?>">
                            <?= $formAccountingMode === 'invoice' ? 'Invoice Mode' : 'Legacy Mode' ?>
                        </span>
                    </div>
                    <div class="small mt-1">
                        <?php if (!$featureEnabled): ?>
                            Invoice Mode feature flag is disabled. This lease will use Legacy accounting until <code>re_accounting_invoice_mode_enabled</code> is enabled.
                        <?php elseif ($formAccountingMode === 'invoice'): ?>
                            This lease will use obligation, invoice, receipt, and allocation accounting. Cheques/installments remain operational only.
                        <?php else: ?>
                            This lease uses the old Legacy accounting flow, preserved for historical safety.
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($canOverrideAccountingMode): ?>
                    <div class="row g-2 align-items-end" style="min-width: 420px;">
                        <div class="col-md-5">
                            <label class="form-label small mb-1">Override Mode</label>
                            <select name="accounting_mode" class="form-select form-select-sm">
                                <option value="invoice" <?= $formAccountingMode === 'invoice' ? 'selected' : '' ?>>Invoice Mode</option>
                                <option value="legacy" <?= $formAccountingMode === 'legacy' ? 'selected' : '' ?> <?= $accountingModeSettings['allow_legacy_new'] !== '1' && !$leaseId ? 'disabled' : '' ?>>Legacy Mode</option>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small mb-1">Override Reason</label>
                            <input type="text" name="accounting_mode_reason" class="form-control form-control-sm" value="<?= h(getFormValue('accounting_mode_reason')) ?>" placeholder="Required if changing existing lease mode">
                        </div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="accounting_mode" value="<?= h($formAccountingMode) ?>">
                <?php endif; ?>
            </div>
            <div id="leaseFormAlerts" class="mb-3"></div>
            
            <!-- Basic Information Card -->
            <div class="card card-round mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-info-circle"></i> Basic Information</h5>
                </div>
                <div class="card-body">
                    <?php
                    // Determine multi-unit state for editing
                    $formIsMultiUnit = false;
                    if ($leaseId && !empty($lease['is_multi_unit'])) {
                        $formIsMultiUnit = true;
                    } elseif ($formData && !empty($formData['is_multi_unit'])) {
                        $formIsMultiUnit = true;
                    }
                    $singleUnitId = $formData ? ($formData['unit_id'] ?? $unitId) : $unitId;
                    ?>
                    <!-- Lease Type Toggle -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Lease Type</label>
                        <div class="d-flex gap-3 align-items-center">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="lease_type_toggle" id="leaseSingle"
                                       value="single" <?= !$formIsMultiUnit ? 'checked' : '' ?>>
                                <label class="form-check-label" for="leaseSingle">
                                    <i class="bi bi-building"></i> Single Unit
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="lease_type_toggle" id="leaseMulti"
                                       value="multi" <?= $formIsMultiUnit ? 'checked' : '' ?>>
                                <label class="form-check-label" for="leaseMulti">
                                    <i class="bi bi-buildings"></i> Multiple Units
                                    <span class="badge bg-info ms-1">New</span>
                                </label>
                            </div>
                        </div>
                        <input type="hidden" name="is_multi_unit" id="isMultiUnitHidden" value="<?= $formIsMultiUnit ? '1' : '0' ?>">
                    </div>

                    <!-- Unit Selector Row: single-unit col | multi-unit table | tenant col -->
                    <div class="row g-3 mb-2">

                        <!-- SINGLE UNIT: simple dropdown -->
                        <div id="singleUnitPanel" class="col-md-6" <?= $formIsMultiUnit ? 'style="display:none"' : '' ?>>
                            <label class="form-label">Unit *</label>
                            <select name="unit_id" class="form-select" id="unitSelect">
                                <option value="">-- Select Unit --</option>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= $u['id'] ?>" data-unit-type="<?= h($u['unit_type'] ?? '') ?>" <?= ($singleUnitId == $u['id']) ? 'selected' : '' ?>>
                                        <?= h($u['building_name'] . ' - ' . $u['unit_number'] . ' (' . $u['unit_type'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- MULTI-UNIT: dynamic rows table -->
                        <div id="multiUnitPanel" class="col-12" <?= !$formIsMultiUnit ? 'style="display:none"' : '' ?>>
                            <label class="form-label fw-semibold">Units &amp; Individual Rents *</label>
                            <div class="card border-info">
                                <div class="card-body p-2">
                                    <table class="table table-sm mb-2" id="multiUnitTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Unit</th>
                                                <th style="width:180px">Annual Rent (AED)</th>
                                                <th style="width:50px"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="multiUnitRows">
                                        <?php
                                        // Pre-fill existing rows when editing a multi-unit lease
                                        $preFillUnits = [];
                                        if (!empty($existingLeaseUnits)) {
                                            $preFillUnits = $existingLeaseUnits;
                                        } elseif ($formData && !empty($formData['multi_unit_ids'])) {
                                            foreach ($formData['multi_unit_ids'] as $k => $muid) {
                                                $preFillUnits[] = [
                                                    'unit_id'    => $muid,
                                                    'annual_rent'=> $formData['multi_unit_rents'][$k] ?? 0
                                                ];
                                            }
                                        }
                                        if (empty($preFillUnits)) {
                                            // Empty first row
                                            echo '<tr class="mu-row" data-idx="0">
                                                <td class="align-middle mu-idx">1</td>
                                                <td><select name="multi_unit_ids[]" class="form-select form-select-sm mu-unit-sel">
                                                    <option value="">-- Select Unit --</option>';
                                            foreach ($units as $u) {
                                                echo '<option value="' . (int)$u['id'] . '" data-unit-type="' . h($u['unit_type'] ?? '') . '">' . h($u['building_name'] . ' - ' . $u['unit_number'] . ' (' . $u['unit_type'] . ')') . '</option>';
                                            }
                                            echo '</select></td>
                                                <td><input type="number" step="0.01" min="0" name="multi_unit_rents[]" class="form-control form-control-sm mu-rent" placeholder="0.00"></td>
                                                <td><button type="button" class="btn btn-sm btn-outline-danger mu-remove-btn" title="Remove"><i class="bi bi-x-lg"></i></button></td>
                                            </tr>';
                                        } else {
                                            foreach ($preFillUnits as $idx => $pu) {
                                                echo '<tr class="mu-row" data-idx="' . $idx . '">
                                                    <td class="align-middle mu-idx">' . ($idx+1) . '</td>
                                                    <td><select name="multi_unit_ids[]" class="form-select form-select-sm mu-unit-sel">
                                                        <option value="">-- Select Unit --</option>';
                                                foreach ($units as $u) {
                                                    $sel = ($u['id'] == $pu['unit_id']) ? 'selected' : '';
                                                    echo '<option value="' . (int)$u['id'] . '" data-unit-type="' . h($u['unit_type'] ?? '') . '" ' . $sel . '>' . h($u['building_name'] . ' - ' . $u['unit_number'] . ' (' . $u['unit_type'] . ')') . '</option>';
                                                }
                                                echo '</select></td>
                                                    <td><input type="number" step="0.01" min="0" name="multi_unit_rents[]" class="form-control form-control-sm mu-rent" value="' . h($pu['annual_rent']) . '" placeholder="0.00"></td>
                                                    <td><button type="button" class="btn btn-sm btn-outline-danger mu-remove-btn" title="Remove"><i class="bi bi-x-lg"></i></button></td>
                                                </tr>';
                                            }
                                        }
                                        ?>
                                        </tbody>
                                    </table>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="addMultiUnitRow">
                                            <i class="bi bi-plus-circle"></i> Add Another Unit
                                        </button>
                                        <div class="text-end">
                                            <small class="text-muted">Combined Annual Rent:</small>
                                            <strong class="text-success ms-1" id="muTotalRentDisplay">0.00 AED</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <small class="form-text text-muted">
                                The <strong>Annual Rent</strong> field below will be auto-filled with the sum of all units.
                            </small>
                        </div>

                        <!-- Tenant — always visible -->
                        <div class="col-md-6 mb-0">
                            <label class="form-label">Tenant *</label>
                            <select name="tenant_id" class="form-select" id="tenantSelect" required>
                                <option value="">-- Select Tenant --</option>
                                <?php
                                $selectedTenantId = $formData ? ($formData['tenant_id'] ?? $tenantId) : $tenantId;
                                foreach ($tenants as $t):
                                    $displayName = ($t['tenant_type'] === 'company' && !empty($t['company_name']))
                                        ? $t['company_name']
                                        : trim($t['first_name'] . ' ' . $t['last_name']);
                                    if (empty($displayName)) {
                                        $displayName = $t['company_name'] ?: $t['phone'] ?: 'Tenant #' . $t['id'];
                                    }
                                ?>
                                    <option value="<?= $t['id'] ?>"
                                        <?= ($selectedTenantId == $t['id']) ? 'selected' : '' ?>
                                        data-search="<?= h(trim($displayName . ($t['phone'] ? ' '.$t['phone'] : '') . ($t['email'] ? ' '.$t['email'] : ''))) ?>">
                                        <?= h($displayName . ($t['phone'] ? ' – ' . $t['phone'] : '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div><!-- /row unit+tenant -->
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Lease Number</label>
                            <?php if ($leaseId): ?>
                                <!-- Editing: Show read-only -->
                                <input type="text" class="form-control" name="lease_number" value="<?= h($lease['lease_number']) ?>" readonly>
                                <small class="form-text text-muted">Lease number cannot be changed after creation</small>
                            <?php else: ?>
                                <!-- New lease: Auto-generated, but can be overridden -->
                                <input type="text" class="form-control" name="lease_number" id="leaseNumber" value="<?= h(getFormValue('lease_number', '')) ?>" placeholder="Auto-generated">
                                <small class="form-text text-muted">Auto-generated as: BuildingShort-Unit-Sequence (e.g. TOWERA-101-0001) to avoid duplicates across buildings</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Start Date *</label>
                            <input type="date" class="form-control" name="start_date" id="startDate" value="<?= h(getFormValue('start_date', $lease ? $lease['start_date'] : '')) ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">End Date *</label>
                            <input type="date" class="form-control" name="end_date" id="endDate" value="<?= h(getFormValue('end_date', $lease ? $lease['end_date'] : '')) ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rent & Payment Details Card -->
            <div class="card card-round mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-cash-coin"></i> Rent & Payment Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Annual Rent (AED) *</label>
                            <input type="number" step="0.01" class="form-control" name="annual_rent" id="annualRent"
                                   value="<?= h(getFormValue('annual_rent', $lease ? ($lease['annual_rent'] ?? ($lease['monthly_rent'] ?? 0) * 12) : '')) ?>"
                                   <?= $formIsMultiUnit ? 'readonly style="background:#f8f9fa"' : '' ?> required>
                            <small class="form-text text-muted" id="annualRentHint">
                                <?= $formIsMultiUnit ? 'Auto-calculated from unit rents above' : 'Total rent per year' ?>
                            </small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Number of Installments *</label>
                            <input type="number" min="1" max="12" class="form-control" name="number_of_installments" id="numberOfInstallments" value="<?= h(getFormValue('number_of_installments', $lease ? ($lease['number_of_installments'] ?? 12) : 12)) ?>" required>
                            <small class="form-text text-muted">How many payments per year (1-12)</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Monthly Rent (AED)</label>
                            <input type="text" class="form-control" id="monthlyRentDisplay" readonly value="0.00">
                            <small class="form-text text-muted">Calculated automatically</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Security Deposit (AED)</label>
                            <input type="number" step="0.01" class="form-control" name="security_deposit" id="securityDeposit" value="<?= h(getFormValue('security_deposit', $lease ? $lease['security_deposit'] : '0')) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Payment Method *</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="cheque" <?= (!$lease || $lease['payment_method'] == 'cheque') ? 'selected' : '' ?>>Cheque</option>
                                <option value="bank_transfer" <?= ($lease && $lease['payment_method'] == 'bank_transfer') ? 'selected' : '' ?>>Bank Transfer</option>
                                <option value="cash" <?= ($lease && $lease['payment_method'] == 'cash') ? 'selected' : '' ?>>Cash</option>
                                <option value="auto_debit" <?= ($lease && $lease['payment_method'] == 'auto_debit') ? 'selected' : '' ?>>Auto Debit</option>
                            </select>
                        </div>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3"><i class="bi bi-receipt-cutoff"></i> Additional Fees</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Chiller Fees (AED)</label>
                            <input type="number" step="0.01" class="form-control" name="chiller_fees" id="chillerFees" value="<?= $lease ? ($lease['chiller_fees'] ?? 0) : '0' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ejari Fees (AED)</label>
                            <input type="number" step="0.01" class="form-control" name="ejari_fees" id="ejariFees" value="<?= $lease ? ($lease['ejari_fees'] ?? 0) : '0' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Admin Fees (AED)</label>
                            <input type="number" step="0.01" class="form-control" name="admin_fees" id="adminFees" value="<?= $lease ? ($lease['admin_fees'] ?? 0) : '0' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Commission Fees (AED)</label>
                            <input type="number" step="0.01" class="form-control" name="commission_fees" id="commissionFees" value="<?= $lease ? ($lease['commission_fees'] ?? 0) : '0' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="alert alert-light border mb-0 h-100">
                                <strong>Extra services moved.</strong><br>
                                <small>AMC, cleaning, pest control, parking, store rooms, access cards, and similar charges must be managed from Billing / Extra Services.</small>
                                <?php if ($lease && (float)($lease['amc_amount'] ?? 0) > 0): ?>
                                    <div class="small text-muted mt-1">Legacy AMC on this lease: <?= number_format((float)$lease['amc_amount'], 2) ?> AED, preserved read-only.</div>
                                <?php endif; ?>
                                <input type="hidden" name="amc_amount" id="amcAmount" value="<?= $lease ? h((string)($lease['amc_amount'] ?? 0)) : '0' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="add_fees_to_first_installment" id="addFeesToFirstInstallment" value="1" <?= (!$lease || ($lease && ($lease['add_fees_to_first_installment'] ?? 1) == 1)) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="addFeesToFirstInstallment">
                                <strong>Merge 1st-installment fees with 1st rent payment</strong>
                            </label>
                            <small class="form-text text-muted d-block">
                                If checked: fees not split into installments are added to the 1st cheque/payment.<br>
                                If unchecked: a separate installment is created (same date as the 1st) containing those fees.
                            </small>
                        </div>
                    </div>

                    <hr>
                    <h6 class="mb-3"><i class="bi bi-percent"></i> VAT (lease)</h6>
                    <p class="small text-muted">VAT on admin / chiller / commission / AMC / parking / store follows the amounts above. <strong>Ejari is excluded from VAT</strong>. Optional VAT on <strong>annual rent</strong> applies to commercial-style units only.</p>
                    <div class="row">
                        <div class="col-md-6 mb-3 <?= $leaseCommercialForVat ? '' : 'd-none' ?>" id="leaseVatRentRow">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="vat_applicable_on_rent" id="vatApplicableOnRent" value="1"
                                    <?= getCheckboxFormValue('vat_applicable_on_rent', $lease && !empty($lease['vat_applicable_on_rent'])) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vatApplicableOnRent">Apply VAT on annual rent (commercial / office / retail / shop / warehouse)</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="vat_applicable_on_extra_charges" id="vatApplicableOnExtraCharges" value="1"
                                    <?= getCheckboxFormValue('vat_applicable_on_extra_charges', !$lease || !isset($lease['vat_applicable_on_extra_charges']) || !empty($lease['vat_applicable_on_extra_charges'])) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vatApplicableOnExtraCharges">Apply VAT on extra charges (fees above)</label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">VAT rate (%)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="lease_vat_rate" id="leaseVatRate"
                                   value="<?= h((string)getFormValue('lease_vat_rate', $lease ? ($lease['lease_vat_rate'] ?? '5') : '5')) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">VAT distribution</label>
                            <?php
                            $vdLease = (string)getFormValue('vat_distribution_type', $lease ? ($lease['vat_distribution_type'] ?? 'first_installment') : 'first_installment');
                            if (!in_array($vdLease, ['first_installment', 'split_installments', 'separate_payment'], true)) {
                                $vdLease = 'first_installment';
                            }
                            ?>
                            <select name="vat_distribution_type" id="vatDistributionType" class="form-select">
                                <option value="first_installment" <?= $vdLease === 'first_installment' ? 'selected' : '' ?>>With first installment / fees cheque</option>
                                <option value="split_installments" <?= $vdLease === 'split_installments' ? 'selected' : '' ?>>Split across all rent cheques</option>
                                <option value="separate_payment" <?= $vdLease === 'separate_payment' ? 'selected' : '' ?>>Separate VAT cheque</option>
                            </select>
                            <div class="form-text">
                                Separate VAT cheque: Invoice Mode creates one VAT invoice for the total VAT.
                                Fee amounts can still be split across cheques without embedding that VAT on fee invoices.
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="small text-muted border rounded p-2 bg-light">
                                <div>Rent VAT: <strong id="leasePreviewRentVat">0.00</strong> AED</div>
                                <div>Extras VAT: <strong id="leasePreviewExtraVat">0.00</strong> AED</div>
                                <div>Total VAT: <strong id="leasePreviewTotalVat">0.00</strong> AED</div>
                            </div>
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label">VAT notes (optional)</label>
                            <textarea name="vat_notes" class="form-control" rows="2" placeholder="Shown on lease record / reports"><?= h((string)getFormValue('vat_notes', $lease ? ($lease['vat_notes'] ?? '') : '')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fee Distribution Card -->
            <div class="card card-round mb-4" style="border: 2px solid #6c757d;">
                <div class="card-header text-white" style="background: #495057;">
                    <h5 class="mb-0"><i class="bi bi-sliders"></i> Fee Distribution &amp; Accounting Settings</h5>
                </div>
                <div class="card-body">

                    <!-- Deferred Revenue / Accrual Mode -->
                    <div class="alert <?= $formAccountingMode === 'invoice' ? 'alert-info' : 'alert-warning' ?> p-2 mb-3">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   name="deferred_revenue_mode" id="deferredRevenueMode" value="1"
                                   <?= (!$lease || ($lease['deferred_revenue_mode'] ?? 1)) && $formAccountingMode !== 'invoice' ? 'checked' : '' ?>
                                   <?= $formAccountingMode === 'invoice' ? 'disabled' : '' ?>>
                            <label class="form-check-label fw-bold" for="deferredRevenueMode">
                                <i class="bi bi-bank2 me-1"></i> Legacy Accrual Mode — Deferred Rent Revenue
                            </label>
                        </div>
                        <small class="text-muted ms-4 d-block mt-1">
                            <?php if ($formAccountingMode === 'invoice'): ?>
                                Disabled for Invoice Mode. Revenue is recognized when eligible invoice candidates are issued from monthly obligations.
                            <?php else: ?>
                                When checked: payments go to <strong>Deferred Rent Revenue [2410]</strong> (a liability)
                                instead of directly crediting income. Run the <strong>Revenue Recognition</strong> report each month
                                to move the correct amount to <strong>Rental Income [4110]</strong>.
                            <?php endif; ?>
                        </small>
                    </div>

                    <!-- Renewal lease -->
                    <div class="alert alert-info p-2 mb-3">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="is_renewal_lease" id="isRenewalLease" value="1"
                                   <?= ($lease && ($lease['is_renewal_lease'] ?? 0)) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="isRenewalLease">
                                Renewal Lease &mdash; Security Deposit already collected
                            </label>
                        </div>
                        <small class="text-muted ms-4 d-block">
                            If checked: Security Deposit is <strong>not collected again</strong> in this lease (it was collected in the original lease).
                        </small>
                    </div>

                    <?php
                    // Determine current 3-way mode for each fee (for radio pre-selection)
                    $dSecurity = (string)getFormValue('dist_security_deposit', (!$lease || empty($lease['sep_security_deposit'])) ? 'first' : 'separate');
                    if (!in_array($dSecurity, ['first', 'separate'], true)) { $dSecurity = 'first'; }
                    $dChiller = !$lease ? 'first'    : (!empty($lease['split_chiller_fees'])     ? 'split' : (!empty($lease['sep_chiller_fees'])     ? 'separate' : 'first'));
                    $dEjari   = !$lease ? 'first'    : (!empty($lease['split_ejari_fees'])       ? 'split' : (!empty($lease['sep_ejari_fees'])       ? 'separate' : 'first'));
                    $dAdmin   = !$lease ? 'first'    : (!empty($lease['split_admin_fees'])       ? 'split' : (!empty($lease['sep_admin_fees'])       ? 'separate' : 'first'));
                    $dComm    = !$lease ? 'first'    : (!empty($lease['split_commission_fees'])  ? 'split' : (!empty($lease['sep_commission_fees'])  ? 'separate' : 'first'));
                    $dAmc     = !$lease ? 'separate' : (!empty($lease['split_amc_fees'])         ? 'split' : (isset($lease['sep_amc_fees']) && !$lease['sep_amc_fees'] ? 'first' : 'separate'));
                    $dParking = !$lease ? 'first'    : (!empty($lease['split_additional_parking'])? 'split' : (!empty($lease['sep_additional_parking'])? 'separate' : 'first'));
                    $dStore   = !$lease ? 'first'    : (!empty($lease['split_additional_store'])  ? 'split' : (!empty($lease['sep_additional_store'])  ? 'separate' : 'first'));
                    function distRadio($name, $val, $current) {
                        return '<input type="radio" class="form-check-input fee-dist-radio" name="' . $name . '" value="' . $val . '"' . ($current === $val ? ' checked' : '') . '>';
                    }
                    ?>
                    <!-- Per-fee distribution table -->
                    <p class="text-muted mb-2">
                        Choose how each fee is collected: <strong>÷ Split</strong> adds it evenly across all installments,
                        <strong>1st Inst.</strong> merges it into the first rent cheque (or a combined fees cheque),
                        <strong>Sep. Inst.</strong> gives it its own dedicated cheque.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0" id="feeDistTable">
                            <thead class="table-dark">
                                <tr>
                                    <th style="min-width:140px;">Fee</th>
                                    <th class="text-center" style="width:100px;"><i class="bi bi-distribute-vertical"></i><br>÷ Split all</th>
                                    <th class="text-center" style="width:100px;"><i class="bi bi-1-circle"></i><br>1st Inst.</th>
                                    <th class="text-center" style="width:110px;"><i class="bi bi-file-earmark-plus"></i><br>Sep. Inst.</th>
                                    <th style="width:130px;">Live Amount (AED)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Security Deposit: 1st installment OR its own separate cheque -->
                                <tr id="secDepRow">
                                    <td>
                                        <strong>Security Deposit</strong>
                                        <small id="secDepRenewalNote" class="text-danger d-none ms-1">(not collected – renewal)</small>
                                    </td>
                                    <td class="text-center text-muted">—</td>
                                    <td class="text-center"><?= distRadio('dist_security_deposit','first',$dSecurity) ?></td>
                                    <td class="text-center"><?= distRadio('dist_security_deposit','separate',$dSecurity) ?></td>
                                    <td><span class="fee-live-amt" id="liveSecDep">0.00</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Chiller Fees</strong></td>
                                    <td class="text-center"><?= distRadio('dist_chiller','split',$dChiller) ?></td>
                                    <td class="text-center"><?= distRadio('dist_chiller','first',$dChiller) ?></td>
                                    <td class="text-center"><?= distRadio('dist_chiller','separate',$dChiller) ?></td>
                                    <td><span class="fee-live-amt" id="liveChiller">0.00</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Ejari Fees</strong></td>
                                    <td class="text-center"><?= distRadio('dist_ejari','split',$dEjari) ?></td>
                                    <td class="text-center"><?= distRadio('dist_ejari','first',$dEjari) ?></td>
                                    <td class="text-center"><?= distRadio('dist_ejari','separate',$dEjari) ?></td>
                                    <td><span class="fee-live-amt" id="liveEjari">0.00</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Admin Fees</strong></td>
                                    <td class="text-center"><?= distRadio('dist_admin','split',$dAdmin) ?></td>
                                    <td class="text-center"><?= distRadio('dist_admin','first',$dAdmin) ?></td>
                                    <td class="text-center"><?= distRadio('dist_admin','separate',$dAdmin) ?></td>
                                    <td><span class="fee-live-amt" id="liveAdmin">0.00</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Commission Fees</strong></td>
                                    <td class="text-center"><?= distRadio('dist_commission','split',$dComm) ?></td>
                                    <td class="text-center"><?= distRadio('dist_commission','first',$dComm) ?></td>
                                    <td class="text-center"><?= distRadio('dist_commission','separate',$dComm) ?></td>
                                    <td><span class="fee-live-amt" id="liveCommission">0.00</span></td>
                                </tr>
                                <tr id="amcDistRow" style="display:none;">
                                    <td>
                                        <strong style="color:#6f42c1;">AMC Fee</strong>
                                        <small class="text-muted d-block">Annual Maintenance Contract</small>
                                    </td>
                                    <td class="text-center"><?= distRadio('dist_amc','split',$dAmc) ?></td>
                                    <td class="text-center"><?= distRadio('dist_amc','first',$dAmc) ?></td>
                                    <td class="text-center"><?= distRadio('dist_amc','separate',$dAmc) ?></td>
                                    <td><span class="fee-live-amt" id="liveAmc">0.00</span></td>
                                </tr>
                                <tr id="parkingDistRow" style="display:none;">
                                    <td><strong>Additional Parking</strong> <small class="text-muted">(monthly)</small></td>
                                    <td class="text-center"><?= distRadio('dist_parking','split',$dParking) ?></td>
                                    <td class="text-center"><?= distRadio('dist_parking','first',$dParking) ?></td>
                                    <td class="text-center"><?= distRadio('dist_parking','separate',$dParking) ?></td>
                                    <td><span class="fee-live-amt" id="liveParking">0.00</span></td>
                                </tr>
                                <tr id="storeDistRow" style="display:none;">
                                    <td><strong>Additional Store</strong> <small class="text-muted">(monthly)</small></td>
                                    <td class="text-center"><?= distRadio('dist_store','split',$dStore) ?></td>
                                    <td class="text-center"><?= distRadio('dist_store','first',$dStore) ?></td>
                                    <td class="text-center"><?= distRadio('dist_store','separate',$dStore) ?></td>
                                    <td><span class="fee-live-amt" id="liveStore">0.00</span></td>
                                </tr>
                            </tbody>
                            <tfoot class="table-secondary fw-bold">
                                <tr>
                                    <td colspan="4">Per-installment amount (rent + split fees ÷ installments)</td>
                                    <td><span id="livePerInstallment">0.00</span> AED</td>
                                </tr>
                                <tr>
                                    <td colspan="4">1st-installment fees total <small class="fw-normal text-muted">(merged or combined cheque)</small></td>
                                    <td><span id="liveToFirst">0.00</span> AED</td>
                                </tr>
                                <tr>
                                    <td colspan="4">Separate installment fees total <small class="fw-normal text-muted">(each gets own cheque)</small></td>
                                    <td><span id="liveToSep">0.00</span> AED</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card card-round mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Extra Services</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        Extra services such as parking, store rooms, cleaning, pest control, AMC, access cards, and similar charges must be managed from the Billing / Extra Services module.
                    </div>
                    <?php if ($lease && (!empty($lease['has_additional_parking']) || !empty($lease['has_additional_store']))): ?>
                        <div class="alert alert-light border">
                            <strong>Legacy extra service values are preserved read-only on this lease.</strong>
                            <?php if (!empty($lease['has_additional_parking'])): ?>
                                <div>Parking: <?= number_format((float)($lease['additional_parking_fee'] ?? 0), 2) ?> AED</div>
                            <?php endif; ?>
                            <?php if (!empty($lease['has_additional_store'])): ?>
                                <div>Store: <?= number_format((float)($lease['additional_store_fee'] ?? 0), 2) ?> AED</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <input type="hidden" name="has_additional_parking" value="<?= $lease && !empty($lease['has_additional_parking']) ? '1' : '0' ?>">
                    <input type="hidden" name="additional_parking_fee" value="<?= $lease ? h((string)($lease['additional_parking_fee'] ?? 0)) : '0' ?>">
                    <input type="hidden" name="additional_parking_start_date" value="<?= $lease ? h((string)($lease['additional_parking_start_date'] ?? '')) : '' ?>">
                    <input type="hidden" name="additional_parking_end_date" value="<?= $lease ? h((string)($lease['additional_parking_end_date'] ?? '')) : '' ?>">
                    <input type="hidden" name="has_additional_store" value="<?= $lease && !empty($lease['has_additional_store']) ? '1' : '0' ?>">
                    <input type="hidden" name="additional_store_fee" value="<?= $lease ? h((string)($lease['additional_store_fee'] ?? 0)) : '0' ?>">
                    <input type="hidden" name="additional_store_start_date" value="<?= $lease ? h((string)($lease['additional_store_start_date'] ?? '')) : '' ?>">
                    <input type="hidden" name="additional_store_end_date" value="<?= $lease ? h((string)($lease['additional_store_end_date'] ?? '')) : '' ?>">
                    <a href="billing_service_charges.php<?= $leaseId ? '?lease_id=' . (int)$leaseId : '' ?>" class="btn btn-outline-primary">
                        <i class="bi bi-box-arrow-up-right"></i> Go to Extra Services Billing
                    </a>
                </div>
            </div>

            <!-- Payment Details (Post-Dated Cheques) Card -->
            <div class="card card-round mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-receipt"></i> Operational Payment / Cheque Schedule</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">This schedule is for expected collections only. It does not control invoice issuance, revenue recognition, VAT accounting, rent income, tenant accounting balance, or GL posting.</p>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Schedule Mode</label>
                            <select id="scheduleMode" class="form-select">
                                <option value="auto">Auto Split Suggestion</option>
                                <option value="manual">Manual Schedule</option>
                            </select>
                            <div class="form-text">Auto Split only suggests rows. Manual edits are preserved until you click Regenerate Schedule.</div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-warning w-100" id="regenerateScheduleBtn">
                                <i class="bi bi-arrow-repeat"></i> Regenerate Schedule
                            </button>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-primary w-100" id="addManualScheduleRowBtn">
                                <i class="bi bi-plus-circle"></i> Add Manual Row
                            </button>
                        </div>
                    </div>
                    <div class="alert alert-light border" id="scheduleTotalsSummary">
                        <div class="row text-center">
                            <div class="col-md-3"><div class="text-muted small">Expected Collection</div><strong id="expectedCollectionTotal">0.00</strong> AED</div>
                            <div class="col-md-3"><div class="text-muted small">Scheduled Payments</div><strong id="scheduledPaymentTotal">0.00</strong> AED</div>
                            <div class="col-md-3"><div class="text-muted small">Difference</div><strong id="scheduleDifferenceTotal">0.00</strong> AED</div>
                            <div class="col-md-3"><div class="text-muted small">Validation</div><strong><?= h(re_payment_schedule_validation_mode($conn)) ?></strong></div>
                        </div>
                        <div class="small mt-2" id="expectedCollectionBreakdown"></div>
                    </div>
                    <div class="alert alert-danger d-none" id="scheduleMismatchWarning">
                        The total scheduled payments do not match the expected lease collection total. Please review before saving. This may cause collection and accounting discrepancies.
                    </div>
                    <?php if ($lease && ((!empty($lease['has_additional_parking']) || (float)($lease['additional_parking_fee'] ?? 0) > 0) || (!empty($lease['has_additional_store']) || (float)($lease['additional_store_fee'] ?? 0) > 0))): ?>
                        <div class="alert alert-warning">
                            <strong>Old extra services detected.</strong>
                            This lease has legacy parking/store values. In the new Invoice Mode flow, recreate these charges from
                            <a href="billing_service_charges.php?lease_id=<?= (int)$leaseId ?>">Service Charges Management</a>.
                            They should not be treated as direct lease financial fields.
                        </div>
                    <?php endif; ?>
                    <?php if (re_payment_schedule_can_override_mismatch($conn)): ?>
                        <div class="border rounded p-3 mb-3" id="scheduleMismatchApprovalBox">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="schedule_mismatch_approved" value="1" id="scheduleMismatchApproved">
                                <label class="form-check-label fw-semibold" for="scheduleMismatchApproved">Approve schedule total mismatch</label>
                            </div>
                            <label class="form-label small">Approval reason</label>
                            <input type="text" name="schedule_mismatch_reason" class="form-control" placeholder="Required if scheduled total does not match expected total">
                        </div>
                    <?php endif; ?>
                    <div id="chequesContainer">
                        <!-- Cheques will be generated dynamically based on number of installments -->
                    </div>
                </div>
            </div>

            <!-- Contract Details Card -->
            <div class="card card-round mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="bi bi-file-text"></i> Contract Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Grace Period (Days)</label>
                            <input type="number" class="form-control" name="grace_period_days" value="<?= $lease ? ($lease['grace_period_days'] ?? 0) : 0 ?>" min="0">
                            <small class="form-text text-muted">Days after due date before penalty applies</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contract Template</label>
                            <?php
                            $templates = $conn->prepare("SELECT id, template_name, template_type FROM re_contract_templates WHERE company_id = ? AND is_active = 1 ORDER BY template_name");
                            $templates->execute([$currentCompanyId]);
                            $templates = $templates->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <div class="d-flex gap-2">
                                <select name="template_id" id="templateSelect" class="form-select">
                                    <option value="">-- No Template --</option>
                                    <?php foreach ($templates as $tpl): ?>
                                        <option value="<?= $tpl['id'] ?>" <?= ($lease && $lease['template_id'] == $tpl['id']) ? 'selected' : '' ?>>
                                            <?= h($tpl['template_name']) ?> (<?= ucfirst($tpl['template_type']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-info" id="previewContractBtn" title="Preview Contract" disabled>
                                    <i class="bi bi-eye"></i> Preview
                                </button>
                            </div>
                            <small class="form-text text-muted">
                                <a href="lease_templates.php" target="_blank">Manage Templates</a>
                            </small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Renewal Terms</label>
                        <textarea class="form-control" name="renewal_terms" rows="3" placeholder="Renewal conditions, terms, and requirements..."><?= $lease ? h($lease['renewal_terms'] ?? '') : '' ?></textarea>
                        <small class="form-text text-muted">Terms and conditions for lease renewal</small>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3"><i class="bi bi-file-earmark-check"></i> Ejari Information</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ejari Contract Number</label>
                            <input type="text" class="form-control" name="ejari_registration_number" 
                                   value="<?= $lease ? h($lease['ejari_registration_number'] ?? '') : '' ?>" 
                                   placeholder="Ejari contract number">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ejari Issue Date</label>
                            <input type="date" class="form-control" name="ejari_issue_date" 
                                   value="<?= $lease ? ($lease['ejari_issue_date'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Owner Number</label>
                            <input type="text" class="form-control" name="ejari_property_code" 
                                   value="<?= $lease ? h($lease['ejari_property_code'] ?? '') : '' ?>" 
                                   placeholder="Owner number">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ejari Document (PDF)</label>
                        <input type="file" class="form-control" name="ejari_document" accept=".pdf">
                        <?php if ($lease && $lease['ejari_document_path']): ?>
                            <small class="form-text text-muted">
                                Current: <a href="<?= h($lease['ejari_document_path']) ?>" target="_blank">View Document</a>
                            </small>
                        <?php endif; ?>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3"><i class="bi bi-pen"></i> Signatures & Stamps</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Landlord Signature</label>
                            <input type="file" class="form-control" name="landlord_signature" accept=".png,.jpg,.jpeg">
                            <?php if ($lease && $lease['landlord_signature_path']): ?>
                                <small class="form-text text-muted">
                                    <img src="<?= h($lease['landlord_signature_path']) ?>" alt="Landlord Signature" style="max-height: 50px; margin-top: 5px;">
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Tenant Signature</label>
                            <input type="file" class="form-control" name="tenant_signature" accept=".png,.jpg,.jpeg">
                            <?php if ($lease && $lease['tenant_signature_path']): ?>
                                <small class="form-text text-muted">
                                    <img src="<?= h($lease['tenant_signature_path']) ?>" alt="Tenant Signature" style="max-height: 50px; margin-top: 5px;">
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Company Stamp/Seal</label>
                            <input type="file" class="form-control" name="company_stamp" accept=".png,.jpg,.jpeg">
                            <?php if ($lease && $lease['company_stamp_path']): ?>
                                <small class="form-text text-muted">
                                    <img src="<?= h($lease['company_stamp_path']) ?>" alt="Company Stamp" style="max-height: 50px; margin-top: 5px;">
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <small class="form-text text-muted">
                        Upload signature images (PNG/JPG) and company stamp. These will be inserted into the generated contract PDF.
                    </small>
                </div>
            </div>

            <!-- Additional Information Card -->
            <div class="card card-round mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="bi bi-info-square"></i> Additional Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <?php
                                $statusOptions = $lease
                                    ? array_values(array_unique(array_merge([$lease['status']], re_lease_allowed_status_transitions($lease))))
                                    : ['draft', 'active'];
                                foreach ($statusOptions as $statusOption):
                                ?>
                                    <option value="<?= h($statusOption) ?>" <?= ($lease ? $lease['status'] : 'draft') === $statusOption ? 'selected' : '' ?>>
                                        <?= ucfirst(h($statusOption)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Direct status changes are limited. Use the renewal or termination workflows for lifecycle-sensitive changes.
                            </div>
                            <?php if ($leaseId && (!$lease || $lease['status'] !== 'terminated') && (has_role('Owner', $conn) || has_role('Admin', $conn))): ?>
                                <div class="form-text">
                                    To terminate, use <a href="lease_terminate.php?lease_id=<?= (int)$leaseId ?>">Terminate Lease</a> so cheques and revenue recognition are handled.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Move-In Date</label>
                            <input type="date" class="form-control" name="move_in_date" value="<?= $lease ? $lease['move_in_date'] : '' ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?= $lease ? h($lease['notes']) : '' ?></textarea>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-4">
                <a href="leases.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><?= $leaseId ? 'Update' : 'Create' ?> Lease</button>
            </div>
        </form>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script>
            // ── Data from PHP – declared FIRST so IIFEs below can reference them ──
            const editingLeaseId = <?= json_encode($leaseId ? (int)$leaseId : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const existingCheques = <?= json_encode($existingCheques, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const allInstallments = <?= json_encode($allInstallments ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            // Engine plan/totals (single source of truth) for the currently-loaded lease.
            // The JS preview math mirrors this; emitted so preview == saved == view can be verified.
            const LEASE_ENGINE_TOTALS = <?= json_encode((isset($lease) && is_array($lease)) ? lease_compute_totals($lease) : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const LEASE_ENGINE_PLAN = <?= json_encode((isset($lease) && is_array($lease)) ? lease_build_plan($lease)['lines'] : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            let preserveExistingScheduleOnInitialEdit = !!editingLeaseId;

            function computeRentInstallmentAmounts(effectiveAnnual, installmentCount, addFeesToFirst, feesToFirst) {
                if (installmentCount < 1) return [];
                const basePer = Math.round((effectiveAnnual / installmentCount) * 100) / 100;
                const lastBase = Math.round((effectiveAnnual - basePer * (installmentCount - 1)) * 100) / 100;
                const amounts = [];
                for (let i = 0; i < installmentCount; i++) {
                    let amt = (i === installmentCount - 1) ? lastBase : basePer;
                    if (i === 0 && addFeesToFirst && feesToFirst > 0) {
                        amt = Math.round((amt + feesToFirst) * 100) / 100;
                    }
                    amounts.push(amt);
                }
                return amounts;
            }

            function isInstallmentLocked(inst) {
                if (!inst) return false;
                return (inst.payment_id && Number(inst.payment_id) > 0)
                    || (inst.has_allocation && Number(inst.has_allocation) > 0)
                    || inst.cheque_status === 'cleared'
                    || inst.cheque_status === 'returned';
            }

            function findSeparateInstallmentRow(type, expectedAmount, firstDate) {
                if (!allInstallments || !allInstallments.length || expectedAmount <= 0) return null;
                const typed = allInstallments.filter(i => (i.installment_type || '') === type);
                for (const row of typed) {
                    if (Math.abs((parseFloat(row.amount) || 0) - expectedAmount) < 0.02 && !isInstallmentLocked(row)) {
                        return row;
                    }
                }
                for (const row of typed) {
                    if (Math.abs((parseFloat(row.amount) || 0) - expectedAmount) < 0.02) {
                        return row;
                    }
                }
                for (const row of typed) {
                    if (!isInstallmentLocked(row)) return row;
                }
                const legacy = allInstallments.filter(i => {
                    const rowType = i.installment_type || '';
                    if (rowType !== '' || legacySeparateInstallmentIds.has(String(i.id))) return false;
                    if (isInstallmentLocked(i)) return false;
                    return i.installment_date === firstDate
                        && Math.abs((parseFloat(i.amount) || 0) - expectedAmount) < 0.02;
                });
                if (legacy.length) {
                    legacy.sort((a, b) => Number(b.id) - Number(a.id));
                    return legacy[0];
                }
                return typed[0] || null;
            }

            // Searchable Unit and Tenant dropdowns (Select2) + unit change handler (must use jQuery so Select2 change is caught)
            (function() {
                if (typeof $ !== 'undefined' && $.fn.select2) {
                    $(function() {
                        $('#unitSelect').select2({ theme: 'bootstrap-5', placeholder: '-- Select Unit --', allowClear: true, width: '100%' });
                        $('#tenantSelect').select2({ theme: 'bootstrap-5', placeholder: '-- Select Tenant --', allowClear: true, width: '100%' });

                        // Apply Select2 to all existing multi-unit unit selects (PHP-rendered rows for editing)
                        function applyMuSelect2(sel) {
                            $(sel).select2({
                                theme: 'bootstrap-5',
                                placeholder: '-- Select Unit --',
                                allowClear: true,
                                width: '100%',
                            });
                        }
                        document.querySelectorAll('.mu-unit-sel').forEach(function(sel) {
                            applyMuSelect2(sel);
                        });
                        // Re-trigger recalcTotal when Select2 fires a change on any mu-unit-sel
                        $(document).on('change', '.mu-unit-sel', function() {
                            if (typeof recalcTotal === 'function') recalcTotal();
                        });
                        // Expose helper globally so addRow() can call it
                        window.applyMuSelect2 = applyMuSelect2;
                        // Handle unit change: active lease warning + auto-generate lease number (jQuery so Select2 change fires)
                        $(document).on('change', '#unitSelect', function() {
                            var unitId = $(this).val();
                            var leaseNumberField = document.getElementById('leaseNumber');
                            var submitButton = document.querySelector('button[type="submit"]');
                            var alertContainer = document.getElementById('leaseFormAlerts');
                            // Editing an existing lease: do not block/clear unit when another ACTIVE lease exists (e.g. draft renewal on same unit).
                            if (editingLeaseId) {
                                if (alertContainer) alertContainer.innerHTML = '';
                                if (submitButton) {
                                    submitButton.disabled = false;
                                    submitButton.classList.remove('btn-secondary');
                                    submitButton.classList.add('btn-primary');
                                    submitButton.title = '';
                                }
                                return;
                            }
                            if (typeof markScheduleChangedAndRecalculate === 'function') markScheduleChangedAndRecalculate();
                            // Only clear the alert when user selected a unit (so we can show fresh warning or leave empty). Do NOT clear when unitId is empty or we would wipe the "active lease" warning after clearing the unit.
                            if (unitId && alertContainer) alertContainer.innerHTML = '';
                            if (submitButton) {
                                submitButton.disabled = false;
                                submitButton.classList.remove('btn-secondary');
                                submitButton.classList.add('btn-primary');
                                submitButton.title = '';
                            }
                            if (!unitId) {
                                if (leaseNumberField) leaseNumberField.value = '';
                                return;
                            }
                            fetch('ajax_get_lease.php?unit_id=' + unitId)
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    if (data && data.lease_id) {
                                        if (alertContainer) {
                                            alertContainer.innerHTML = '<div id="activeLeaseError" class="alert alert-danger alert-dismissible fade show">' +
                                                '<strong>⚠️ Blocked:</strong> This unit already has an ACTIVE lease ' +
                                                '(Lease #' + (data.lease_number || 'N/A') + ', ends: ' + (data.end_date || 'N/A') + '). ' +
                                                'You cannot create another lease until the existing lease is terminated, expired, or marked Has Legal Case.' +
                                                '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                                        }
                                        if (submitButton) {
                                            submitButton.disabled = true;
                                            submitButton.classList.remove('btn-primary');
                                            submitButton.classList.add('btn-secondary');
                                            submitButton.title = 'Cannot create lease: Unit already has an active lease';
                                        }
                                        $('#unitSelect').val(null).trigger('change');
                                        return;
                                    }
                                    if (leaseNumberField) {
                                        fetch('ajax_get_unit_info.php?unit_id=' + unitId)
                                            .then(function(r) { return r.json(); })
                                            .then(function(unitData) {
                                                if (unitData && unitData.unit_number && unitData.building_short) {
                                                    fetch('ajax_get_lease_count.php?unit_id=' + unitId)
                                                        .then(function(r) { return r.json(); })
                                                        .then(function(countData) {
                                                            var n = (countData.next_sequence != null && countData.next_sequence > 0)
                                                                ? countData.next_sequence
                                                                : ((countData.count || 0) + 1);
                                                            var seq = String(n).padStart(4, '0');
                                                            leaseNumberField.value = unitData.building_short + '-' + unitData.unit_number + '-' + seq;
                                                        });
                                                }
                                            });
                                    }
                                })
                                .catch(function(e) { console.error('Error checking active lease:', e); });
                        });
                        // If unit is pre-selected (e.g. from URL), run the same logic to show warning or generate lease number
                        if ($('#unitSelect').val() && !editingLeaseId) {
                            $('#unitSelect').trigger('change');
                        }
                    });
                }
            })();
            // ---------------------------------------------------------------
            // Multi-Unit Lease JS
            // ---------------------------------------------------------------
            (function() {
                const allUnitsData = <?= json_encode(array_map(fn($u) => [
                    'id'   => $u['id'],
                    'label'=> $u['building_name'] . ' - ' . $u['unit_number'] . ' (' . $u['unit_type'] . ')',
                    'unit_type' => $u['unit_type'] ?? '',
                ], $units), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

                const radSingle    = document.getElementById('leaseSingle');
                const radMulti     = document.getElementById('leaseMulti');
                const hiddenFlag   = document.getElementById('isMultiUnitHidden');
                const singlePanel  = document.getElementById('singleUnitPanel');
                const multiPanel   = document.getElementById('multiUnitPanel');
                const singleSelect = document.getElementById('unitSelect');
                const annualInput  = document.getElementById('annualRent');
                const annualHint   = document.getElementById('annualRentHint');
                const muRows       = document.getElementById('multiUnitRows');
                const totalDisplay = document.getElementById('muTotalRentDisplay');
                const addRowBtn    = document.getElementById('addMultiUnitRow');

                function escAttr(s) {
                    return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
                }
                function buildUnitOptions(selectedId) {
                    let html = '<option value="">-- Select Unit --</option>';
                    allUnitsData.forEach(function(u) {
                        html += '<option value="' + u.id + '" data-unit-type="' + escAttr(u.unit_type) + '"' + (u.id == selectedId ? ' selected' : '') + '>' + u.label + '</option>';
                    });
                    return html;
                }

                function recalcTotal() {
                    let total = 0;
                    muRows.querySelectorAll('.mu-rent').forEach(function(inp) {
                        total += parseFloat(inp.value) || 0;
                    });
                    if (totalDisplay) totalDisplay.textContent = total.toLocaleString('en-AE', {minimumFractionDigits:2,maximumFractionDigits:2}) + ' AED';
                    if (annualInput && hiddenFlag.value === '1') {
                        annualInput.value = total > 0 ? total.toFixed(2) : '';
                        if (typeof calculateMonthlyRent === 'function') calculateMonthlyRent();
                    }
                    // Renumber rows
                    muRows.querySelectorAll('.mu-row').forEach(function(tr, i) {
                        const idx = tr.querySelector('.mu-idx');
                        if (idx) idx.textContent = (i + 1);
                    });
                }

                function addRow(unitId, rent) {
                    const idx = muRows.querySelectorAll('.mu-row').length;
                    const tr = document.createElement('tr');
                    tr.className = 'mu-row';
                    tr.dataset.idx = idx;
                    tr.innerHTML = '<td class="align-middle mu-idx">' + (idx+1) + '</td>' +
                        '<td><select name="multi_unit_ids[]" class="form-select form-select-sm mu-unit-sel">' + buildUnitOptions(unitId) + '</select></td>' +
                        '<td><input type="number" step="0.01" min="0" name="multi_unit_rents[]" class="form-control form-control-sm mu-rent" value="' + (rent || '') + '" placeholder="0.00"></td>' +
                        '<td><button type="button" class="btn btn-sm btn-outline-danger mu-remove-btn" title="Remove"><i class="bi bi-x-lg"></i></button></td>';
                    muRows.appendChild(tr);
                    // Apply Select2 to the new unit selector (if available)
                    if (window.applyMuSelect2) {
                        window.applyMuSelect2(tr.querySelector('.mu-unit-sel'));
                    }
                    recalcTotal();
                }

                // Expose recalcTotal globally so the jQuery Select2 change handler can call it
                window.recalcTotal = recalcTotal;

                function switchToMulti() {
                    hiddenFlag.value = '1';
                    singlePanel.style.display  = 'none';
                    multiPanel.style.display   = '';
                    if (singleSelect) singleSelect.removeAttribute('required');
                    if (annualInput) {
                        annualInput.readOnly = true;
                        annualInput.style.background = '#f8f9fa';
                    }
                    if (annualHint) annualHint.textContent = 'Auto-calculated from unit rents above';
                    recalcTotal();
                }

                function switchToSingle() {
                    hiddenFlag.value = '0';
                    singlePanel.style.display  = '';
                    multiPanel.style.display   = 'none';
                    if (singleSelect) singleSelect.setAttribute('required', 'required');
                    if (annualInput) {
                        annualInput.readOnly = false;
                        annualInput.style.background = '';
                    }
                    if (annualHint) annualHint.textContent = 'Total rent per year';
                }

                if (radSingle) radSingle.addEventListener('change', function() { if (this.checked) switchToSingle(); });
                if (radMulti)  radMulti.addEventListener('change',  function() { if (this.checked) switchToMulti(); });

                if (addRowBtn) addRowBtn.addEventListener('click', function() { addRow('', ''); });

                document.addEventListener('click', function(e) {
                    if (e.target.closest('.mu-remove-btn')) {
                        const tr = e.target.closest('.mu-row');
                        if (tr && muRows.querySelectorAll('.mu-row').length > 1) {
                            tr.remove();
                        } else {
                            // Reset instead of removing last row
                            const sel = tr && tr.querySelector('.mu-unit-sel');
                            const inp = tr && tr.querySelector('.mu-rent');
                            if (sel) sel.value = '';
                            if (inp) inp.value = '';
                        }
                        recalcTotal();
                    }
                });

                document.addEventListener('input', function(e) {
                    if (e.target.classList.contains('mu-rent')) {
                        preserveExistingScheduleOnInitialEdit = false;
                        recalcTotal();
                    }
                });
                document.addEventListener('change', function(e) {
                    if (e.target.classList.contains('mu-unit-sel')) {
                        preserveExistingScheduleOnInitialEdit = false;
                        recalcTotal();
                    }
                });

                // Init on load
                if (hiddenFlag.value === '1') {
                    switchToMulti();
                } else {
                    switchToSingle();
                }

                // Expose addRow globally for any external use
                window.muAddRow = addRow;
            })();

            
            // ---------------------------------------------------------------
            // Helpers for fee distribution table + cheque amounts
            // ---------------------------------------------------------------
            function syncLeaseVatCommercialRow() {
                const row = document.getElementById('leaseVatRentRow');
                if (!row) return;
                const hidden = document.getElementById('isMultiUnitHidden');
                let ut = '';
                if (hidden && hidden.value === '1') {
                    const firstSel = document.querySelector('#multiUnitRows tr.mu-row .mu-unit-sel');
                    if (firstSel && firstSel.selectedIndex >= 0) {
                        const opt = firstSel.options[firstSel.selectedIndex];
                        ut = opt ? (opt.getAttribute('data-unit-type') || '') : '';
                    }
                } else {
                    const sel = document.getElementById('unitSelect');
                    if (sel && sel.selectedIndex >= 0) {
                        const opt = sel.options[sel.selectedIndex];
                        ut = opt ? (opt.getAttribute('data-unit-type') || '') : '';
                    }
                }
                const low = String(ut).toLowerCase();
                const commercial = ['commercial','shop','retail','office','warehouse','showroom','business'].some(k => low.includes(k));
                row.classList.toggle('d-none', !commercial);
                if (!commercial) {
                    const cb = document.getElementById('vatApplicableOnRent');
                    if (cb) cb.checked = false;
                }
            }

            function getFeeDistValues() {
                const startDate  = document.getElementById('startDate').value;
                const annualRent = parseFloat(document.getElementById('annualRent').value) || 0;
                const installments = parseInt(document.getElementById('numberOfInstallments').value) || 12;
                const secDep     = parseFloat(document.getElementById('securityDeposit').value) || 0;
                const chiller    = parseFloat(document.getElementById('chillerFees').value) || 0;
                const ejari      = parseFloat(document.getElementById('ejariFees').value) || 0;
                const admin      = parseFloat(document.getElementById('adminFees').value) || 0;
                const commission = parseFloat(document.getElementById('commissionFees').value) || 0;
                const amcFee     = 0; // Phase 3.5: AMC is handled in Extra Services, not Lease Add.
                const isRenewal  = !!document.getElementById('isRenewalLease')?.checked;
                const hasParking = false;
                const hasStore   = false;

                // Read 3-way radio values
                const getD = name => document.querySelector(`input[name="${name}"]:checked`)?.value || 'first';
                const distSecurity = getD('dist_security_deposit');
                const distChiller  = getD('dist_chiller');
                const distEjari    = getD('dist_ejari');
                const distAdmin    = getD('dist_admin');
                const distComm     = getD('dist_commission');
                const distAmc      = getD('dist_amc');
                const distParking  = getD('dist_parking');
                const distStore    = getD('dist_store');

                // Mirror the PHP engine: the monthly value is ungated (used by split/separate);
                // the active-window check (start..end) only gates the 'first' contribution.
                let parkingMonthly = 0, storeMonthly = 0;
                let parkingFirstEligible = false, storeFirstEligible = false;
                if (hasParking) {
                    const f = document.querySelector('input[name="additional_parking_fee"]');
                    const s = document.querySelector('input[name="additional_parking_start_date"]');
                    const e = document.querySelector('input[name="additional_parking_end_date"]');
                    const v = f ? (parseFloat(f.value) || 0) : 0;
                    if (v > 0) {
                        parkingMonthly = v;
                        const sv = s ? s.value : '';
                        const ev = e ? e.value : '';
                        parkingFirstEligible = !!startDate && !!sv && sv <= startDate && (!ev || startDate <= ev);
                    }
                }
                if (hasStore) {
                    const f = document.querySelector('input[name="additional_store_fee"]');
                    const s = document.querySelector('input[name="additional_store_start_date"]');
                    const e = document.querySelector('input[name="additional_store_end_date"]');
                    const v = f ? (parseFloat(f.value) || 0) : 0;
                    if (v > 0) {
                        storeMonthly = v;
                        const sv = s ? s.value : '';
                        const ev = e ? e.value : '';
                        storeFirstEligible = !!startDate && !!sv && sv <= startDate && (!ev || startDate <= ev);
                    }
                }

                // Effective annual rent = base + all 'split' fees
                let effectiveAnnual = annualRent;
                if (distChiller  === 'split') effectiveAnnual += chiller;
                if (distEjari    === 'split') effectiveAnnual += ejari;
                if (distAdmin    === 'split') effectiveAnnual += admin;
                if (distComm     === 'split') effectiveAnnual += commission;

                // VAT (match PHP lease_vat_compute_amounts + distribution)
                let leaseVatRate = parseFloat(document.getElementById('leaseVatRate')?.value) || 5;
                if (leaseVatRate <= 0) leaseVatRate = 5;
                const vatOnExtrasEl = document.getElementById('vatApplicableOnExtraCharges');
                const vatOnExtras = !vatOnExtrasEl || vatOnExtrasEl.checked;
                let primaryUt = '';
                const hiddenMu = document.getElementById('isMultiUnitHidden');
                if (hiddenMu && hiddenMu.value === '1') {
                    const firstSel = document.querySelector('#multiUnitRows tr.mu-row .mu-unit-sel');
                    if (firstSel && firstSel.selectedIndex >= 0) {
                        const opt = firstSel.options[firstSel.selectedIndex];
                        primaryUt = opt ? (opt.getAttribute('data-unit-type') || '') : '';
                    }
                } else {
                    const us = document.getElementById('unitSelect');
                    if (us && us.selectedIndex >= 0) {
                        const opt = us.options[us.selectedIndex];
                        primaryUt = opt ? (opt.getAttribute('data-unit-type') || '') : '';
                    }
                }
                const utLow = String(primaryUt).toLowerCase();
                const isCommercial = ['commercial','shop','retail','office','warehouse','showroom','business'].some(k => utLow.includes(k));
                const vatOnRent = isCommercial && !!document.getElementById('vatApplicableOnRent')?.checked;
                let vatDistributionType = document.getElementById('vatDistributionType')?.value || 'first_installment';
                if (!['first_installment','split_installments','separate_payment'].includes(vatDistributionType)) {
                    vatDistributionType = 'first_installment';
                }
                // Ejari is excluded from VAT extras by business rule.
                let extraBaseVat = chiller + admin + commission;
                let rentVat = (vatOnRent && leaseVatRate > 0) ? Math.round(annualRent * (leaseVatRate / 100) * 100) / 100 : 0;
                let extraVat = (vatOnExtras && leaseVatRate > 0) ? Math.round(extraBaseVat * (leaseVatRate / 100) * 100) / 100 : 0;
                const totalVat = Math.round((rentVat + extraVat) * 100) / 100;
                if (vatDistributionType === 'split_installments' && totalVat > 0) {
                    effectiveAnnual += totalVat;
                }

                const perInstallment = effectiveAnnual / installments;

                // Fees going to 1st installment (or combined fees cheque)
                let toFirst = 0;
                if (!isRenewal && distSecurity !== 'separate') toFirst += secDep;
                if (distChiller  === 'first') toFirst += chiller;
                if (distEjari    === 'first') toFirst += ejari;
                if (distAdmin    === 'first') toFirst += admin;
                if (distComm     === 'first') toFirst += commission;
                if (vatDistributionType === 'first_installment' && totalVat > 0) {
                    toFirst += totalVat;
                }

                // Fees getting their own separate cheque
                let toSep = 0;
                if (!isRenewal && distSecurity === 'separate') toSep += secDep;
                if (distChiller  === 'separate') toSep += chiller;
                if (distEjari    === 'separate') toSep += ejari;
                if (distAdmin    === 'separate') toSep += admin;
                if (distComm     === 'separate') toSep += commission;
                if (vatDistributionType === 'separate_payment' && totalVat > 0) toSep += totalVat;

                return {
                    installments, secDep, chiller, ejari, admin, commission, amcFee,
                    parkingMonthly, storeMonthly,
                    isRenewal, hasParking, hasStore,
                    distSecurity, distChiller, distEjari, distAdmin, distComm, distAmc, distParking, distStore,
                    effectiveAnnual, perInstallment, toFirst, toSep,
                    rentVat, extraVat, totalVat, vatDistributionType, leaseVatRate
                };
            }

            // Update fee distribution table live amounts + tfoot summary
            function updateFeeDistTable() {
                const v = getFeeDistValues();

                // Show/hide rows based on entered amounts
                const amcRow     = document.getElementById('amcDistRow');
                const parkingRow = document.getElementById('parkingDistRow');
                const storeRow   = document.getElementById('storeDistRow');
                if (amcRow)     amcRow.style.display     = v.amcFee > 0    ? '' : 'none';
                if (parkingRow) parkingRow.style.display = v.hasParking    ? '' : 'none';
                if (storeRow)   storeRow.style.display   = v.hasStore      ? '' : 'none';

                // Live amounts
                const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val.toFixed(2); };
                set('liveSecDep',         v.secDep);
                set('liveChiller',        v.chiller);
                set('liveEjari',          v.ejari);
                set('liveAdmin',          v.admin);
                set('liveCommission',     v.commission);
                set('liveAmc',            v.amcFee);
                set('liveParking',        v.parkingMonthly);
                set('liveStore',          v.storeMonthly);
                set('livePerInstallment', v.perInstallment);
                set('liveToFirst',        v.toFirst);
                set('liveToSep',          v.toSep);

                const pr = document.getElementById('leasePreviewRentVat');
                const pe = document.getElementById('leasePreviewExtraVat');
                const pt = document.getElementById('leasePreviewTotalVat');
                if (pr) pr.textContent = (v.rentVat ?? 0).toFixed(2);
                if (pe) pe.textContent = (v.extraVat ?? 0).toFixed(2);
                if (pt) pt.textContent = (v.totalVat ?? 0).toFixed(2);

                // Security deposit row: show renewal note + disable radios when renewal
                const secNote  = document.getElementById('secDepRenewalNote');
                if (secNote)  secNote.classList.toggle('d-none', !v.isRenewal);
                document.querySelectorAll('input[name="dist_security_deposit"]').forEach(r => { r.disabled = v.isRenewal; });

                // Highlight rows based on selected mode
                const rowMap = [
                    ['dist_security_deposit', null],
                    ['dist_chiller',    null],
                    ['dist_ejari',      null],
                    ['dist_admin',      null],
                    ['dist_commission', null],
                    ['dist_amc',        'amcDistRow'],
                    ['dist_parking',    'parkingDistRow'],
                    ['dist_store',      'storeDistRow'],
                ];
                rowMap.forEach(([name]) => {
                    const radios = document.querySelectorAll(`input[name="${name}"]`);
                    radios.forEach(r => {
                        const row = r.closest('tr');
                        if (row) {
                            row.classList.remove('table-success','table-warning','table-info');
                            if (r.checked) {
                                if (r.value === 'split')    row.classList.add('table-success');
                                if (r.value === 'first')    row.classList.add('table-warning');
                                if (r.value === 'separate') row.classList.add('table-info');
                            }
                        }
                    });
                });
            }

            // Recalculate monthly rent display
            function calculateMonthlyRent() {
                syncLeaseVatCommercialRow();
                const v = getFeeDistValues();
                document.getElementById('monthlyRentDisplay').value = v.perInstallment.toFixed(2);
                updateFeeDistTable();
                if ((document.getElementById('scheduleMode')?.value || 'auto') === 'auto') {
                    generateChequesRows();
                } else {
                    updateScheduleTotals();
                }
            }

            function markScheduleChangedAndRecalculate() {
                preserveExistingScheduleOnInitialEdit = false;
                calculateMonthlyRent();
            }

            function markScheduleChangedAndGenerateRows() {
                preserveExistingScheduleOnInitialEdit = false;
                if ((document.getElementById('scheduleMode')?.value || 'auto') === 'auto') {
                    generateChequesRows();
                } else {
                    updateScheduleTotals();
                }
            }

            function expectedCollectionBreakdown() {
                const v = getFeeDistValues();
                const expected = {
                    annualRent: parseFloat(document.getElementById('annualRent')?.value) || 0,
                    rentVat: v.rentVat || 0,
                    securityDeposit: v.isRenewal ? 0 : (v.secDep || 0),
                    chillerFees: v.chiller || 0,
                    chillerVat: (v.leaseVatRate > 0 && (!document.getElementById('vatApplicableOnExtraCharges') || document.getElementById('vatApplicableOnExtraCharges').checked)) ? Math.round((v.chiller || 0) * (v.leaseVatRate / 100) * 100) / 100 : 0,
                    ejariFees: v.ejari || 0,
                    adminFees: v.admin || 0,
                    adminVat: (v.leaseVatRate > 0 && (!document.getElementById('vatApplicableOnExtraCharges') || document.getElementById('vatApplicableOnExtraCharges').checked)) ? Math.round((v.admin || 0) * (v.leaseVatRate / 100) * 100) / 100 : 0,
                    commissionFees: v.commission || 0,
                    commissionVat: (v.leaseVatRate > 0 && (!document.getElementById('vatApplicableOnExtraCharges') || document.getElementById('vatApplicableOnExtraCharges').checked)) ? Math.round((v.commission || 0) * (v.leaseVatRate / 100) * 100) / 100 : 0,
                };
                expected.total = Object.values(expected).reduce((sum, val) => sum + (parseFloat(val) || 0), 0);
                return expected;
            }

            function updateScheduleTotals() {
                const b = expectedCollectionBreakdown();
                let scheduled = 0;
                document.querySelectorAll('#chequesContainer input[name$="[amount]"]').forEach(input => {
                    scheduled += parseFloat(input.value) || 0;
                });
                const difference = Math.round((b.total - scheduled) * 100) / 100;
                const setText = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = (Math.round(value * 100) / 100).toFixed(2); };
                setText('expectedCollectionTotal', b.total);
                setText('scheduledPaymentTotal', scheduled);
                setText('scheduleDifferenceTotal', difference);
                const diffEl = document.getElementById('scheduleDifferenceTotal');
                if (diffEl) {
                    diffEl.classList.toggle('text-danger', Math.abs(difference) > 0.02);
                    diffEl.classList.toggle('text-success', Math.abs(difference) <= 0.02);
                }
                const warning = document.getElementById('scheduleMismatchWarning');
                if (warning) warning.classList.toggle('d-none', Math.abs(difference) <= 0.02);
                const breakdown = document.getElementById('expectedCollectionBreakdown');
                if (breakdown) {
                    breakdown.innerHTML = `Breakdown: Annual Rent ${b.annualRent.toFixed(2)} + Rent VAT ${b.rentVat.toFixed(2)} + Security Deposit ${b.securityDeposit.toFixed(2)} + Chiller ${b.chillerFees.toFixed(2)} + Chiller VAT ${b.chillerVat.toFixed(2)} + Ejari ${b.ejariFees.toFixed(2)} + Admin ${b.adminFees.toFixed(2)} + Admin VAT ${b.adminVat.toFixed(2)} + Commission ${b.commissionFees.toFixed(2)} + Commission VAT ${b.commissionVat.toFixed(2)}`;
                }
            }

            // Wire up all inputs that affect calculations
            document.getElementById('annualRent').addEventListener('input', markScheduleChangedAndRecalculate);
            document.getElementById('numberOfInstallments').addEventListener('input', markScheduleChangedAndRecalculate);

            ['securityDeposit', 'chillerFees', 'ejariFees', 'adminFees', 'commissionFees', 'amcAmount'].forEach(id => {
                const field = document.getElementById(id);
                if (field) field.addEventListener('input', markScheduleChangedAndRecalculate);
            });

            ['additional_parking_fee', 'additional_parking_start_date', 'additional_parking_end_date',
             'additional_store_fee', 'additional_store_start_date', 'additional_store_end_date'].forEach(name => {
                const field = document.querySelector(`input[name="${name}"]`);
                if (field) field.addEventListener('input', markScheduleChangedAndRecalculate);
            });

            const hasAdditionalParkingEl = document.getElementById('hasAdditionalParking');
            if (hasAdditionalParkingEl) {
                hasAdditionalParkingEl.addEventListener('change', function() {
                    const fields = document.getElementById('additionalParkingFields');
                    if (fields) fields.style.display = this.checked ? 'block' : 'none';
                    markScheduleChangedAndRecalculate();
                });
            }
            const hasAdditionalStoreEl = document.getElementById('hasAdditionalStore');
            if (hasAdditionalStoreEl) {
                hasAdditionalStoreEl.addEventListener('change', function() {
                    const fields = document.getElementById('additionalStoreFields');
                    if (fields) fields.style.display = this.checked ? 'block' : 'none';
                    markScheduleChangedAndRecalculate();
                });
            }
            document.getElementById('addFeesToFirstInstallment').addEventListener('change', markScheduleChangedAndGenerateRows);
            document.getElementById('isRenewalLease') && document.getElementById('isRenewalLease').addEventListener('change', markScheduleChangedAndRecalculate);

            // All per-fee 3-way radio buttons
            document.querySelectorAll('.fee-dist-radio').forEach(el => {
                el.addEventListener('change', markScheduleChangedAndRecalculate);
            });

            ['leaseVatRate', 'vatDistributionType'].forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('change', markScheduleChangedAndRecalculate);
                    el.addEventListener('input', markScheduleChangedAndRecalculate);
                }
            });
            ['vatApplicableOnRent', 'vatApplicableOnExtraCharges'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('change', markScheduleChangedAndRecalculate);
            });

            // Generate cheques rows based on number of installments
            function generateChequesRows() {
                const startDate    = document.getElementById('startDate').value;
                const endDate      = document.getElementById('endDate').value;
                if (!startDate || !endDate) {
                    document.getElementById('chequesContainer').innerHTML = '<p class="text-muted">Please select start and end dates first.</p>';
                    return;
                }

                const v             = getFeeDistValues();
                const installments  = v.installments;
                const monthlyRent   = v.perInstallment;
                const totalFees     = v.toFirst;
                const addFeesToFirst = document.getElementById('addFeesToFirstInstallment').checked;

                // Build breakdown string for 'first'-mode fees installment
                const feeBreakdown = [];
                if (!v.isRenewal && v.distSecurity !== 'separate' && v.secDep > 0) feeBreakdown.push(`Security Deposit (${v.secDep.toFixed(2)})`);
                if (v.distChiller === 'first' && v.chiller > 0)      feeBreakdown.push(`Chiller (${v.chiller.toFixed(2)})`);
                if (v.distEjari   === 'first' && v.ejari > 0)        feeBreakdown.push(`Ejari (${v.ejari.toFixed(2)})`);
                if (v.distAdmin   === 'first' && v.admin > 0)        feeBreakdown.push(`Admin (${v.admin.toFixed(2)})`);
                if (v.distComm    === 'first' && v.commission > 0)   feeBreakdown.push(`Commission (${v.commission.toFixed(2)})`);
                if (v.vatDistributionType === 'first_installment' && v.totalVat > 0) feeBreakdown.push(`Total VAT (${v.totalVat.toFixed(2)})`);

                const container = document.getElementById('chequesContainer');
                container.innerHTML = '';

                const start = new Date(startDate);
                const end   = new Date(endDate);
                const totalDays    = Math.floor((end - start) / (1000 * 60 * 60 * 24));
                const intervalDays = Math.max(1, Math.floor(totalDays / installments));
                let currentDate    = new Date(start);

                // ── Build cheque lookup maps ───────────────────────────────────────
                // Prefer lookup by installment_id (reliable); fall back to date.
                const chequesMapById   = {};   // key: installment_id
                const chequesMapByDate = {};   // key: installment_date
                if (existingCheques && existingCheques.length > 0) {
                    existingCheques.forEach(c => {
                        if (c.installment_id) chequesMapById[c.installment_id] = c;
                        const dk = c.installment_date || c.cheque_date;
                        if (dk) chequesMapByDate[dk] = c;
                    });
                }

                // Order matches PHP separateFeeList order exactly (index alignment).
                const separateFeeList = [];
                if (!v.isRenewal && v.distSecurity === 'separate' && v.secDep > 0) {
                    separateFeeList.push({ key: 'security_deposit', dist: 'separate', amount: v.secDep, label: 'Security Deposit', type: 'security_deposit' });
                }
                separateFeeList.push(
                    { key: 'chiller',  dist: v.distChiller,  amount: v.chiller,       label: 'Chiller Fees',     type: 'chiller'    },
                    { key: 'ejari',    dist: v.distEjari,    amount: v.ejari,         label: 'Ejari Fees',       type: 'ejari'      },
                    { key: 'admin',    dist: v.distAdmin,    amount: v.admin,         label: 'Admin Fees',       type: 'admin'      },
                    { key: 'comm',     dist: v.distComm,     amount: v.commission,    label: 'Commission Fees',  type: 'commission' },
                );
                if (v.vatDistributionType === 'separate_payment' && v.totalVat > 0) {
                    separateFeeList.push({ key: 'vat', dist: 'separate', amount: v.totalVat, label: 'VAT (total)', type: 'vat' });
                }

                // ── Determine rent installments from DB (exclude typed fee rows & combined_fees) ─
                const nonRentTypes = new Set(['security_deposit','chiller','ejari','admin','commission','amc','parking','store','vat','combined_fees']);
                const isClearlyMistaggedCombinedFees = (row) => {
                    if (!row || (row.installment_type || '') !== 'combined_fees') return false;
                    if (isInstallmentLocked(row)) return false;
                    const amount = parseFloat(row.amount) || 0;
                    if (totalFees <= 0) return true;
                    return Math.abs(amount - totalFees) > 0.02
                        && amount > Math.max(totalFees + 100, totalFees * 1.5);
                };
                const separateInstallmentByType = {};
                const legacySeparateInstallmentIds = new Set();
                const firstDbInstallmentDate = (allInstallments && allInstallments.length > 0) ? allInstallments[0].installment_date : startDate;
                if (allInstallments && allInstallments.length > 0) {
                    separateFeeList.forEach(sf => {
                        if (sf.dist !== 'separate' || sf.amount <= 0) return;
                        const sepInst = findSeparateInstallmentRow(sf.type, sf.amount, firstDbInstallmentDate);
                        if (sepInst && sepInst.id) {
                            legacySeparateInstallmentIds.add(String(sepInst.id));
                            separateInstallmentByType[sf.type] = sepInst;
                        }
                    });
                }
                let combinedFeesInstallmentId = null;
                if (!addFeesToFirst && totalFees > 0 && allInstallments && allInstallments.length) {
                    let feesInstPreview = allInstallments.find(i => i.installment_type === 'combined_fees' && !isClearlyMistaggedCombinedFees(i)) || null;
                    if (!feesInstPreview) {
                        const sepT = new Set(['security_deposit','chiller','ejari','admin','commission','amc','parking','store','vat']);
                        for (const i of allInstallments) {
                            const t = i.installment_type || '';
                            if (sepT.has(t)) continue;
                            if (Math.abs(parseFloat(i.amount) - totalFees) < 0.02) {
                                feesInstPreview = i;
                                break;
                            }
                        }
                    }
                    if (feesInstPreview && feesInstPreview.id) {
                        combinedFeesInstallmentId = String(feesInstPreview.id);
                    }
                }
                let rentInstallments = [];
                let installmentDates = [];

                if (allInstallments && allInstallments.length > 0) {
                    rentInstallments = allInstallments.filter(i => {
                        if (combinedFeesInstallmentId && String(i.id) === combinedFeesInstallmentId) return false;
                        if (legacySeparateInstallmentIds.has(String(i.id))) return false;
                        if (isClearlyMistaggedCombinedFees(i)) return true;
                        return !nonRentTypes.has(i.installment_type || '');
                    });
                    if (rentInstallments.length > installments) {
                        rentInstallments = rentInstallments.slice(0, installments);
                    }
                    installmentDates = rentInstallments.map(inst => inst.installment_date);
                } else {
                    // No DB data (new lease) — calculate dates from start/end
                    for (let i = 0; i < installments; i++) {
                        let date = currentDate.toISOString().split('T')[0];
                        if (i === installments - 1) date = endDate;
                        installmentDates.push(date);
                        if (i < installments - 1) currentDate.setDate(currentDate.getDate() + intervalDays);
                    }
                }
                const preserveRentDates = preserveExistingScheduleOnInitialEdit && rentInstallments.length >= installments;
                const computedRentAmounts = computeRentInstallmentAmounts(
                    v.effectiveAnnual,
                    installments,
                    addFeesToFirst,
                    totalFees
                );

                let chequeIndex = 0;

                // ── STEP 1: Separate-installment fees (one cheque card per fee) ─────
                for (const sf of separateFeeList) {
                    if (sf.dist !== 'separate' || sf.amount <= 0) continue;

                    // Find existing installment by installment_type from DB data
                    let sepInst = separateInstallmentByType[sf.type] || findSeparateInstallmentRow(sf.type, sf.amount, firstDbInstallmentDate);
                    const sepDate   = sepInst ? sepInst.installment_date : (installmentDates[0] || startDate);
                    const sepCheque = sepInst ? (chequesMapById[sepInst.id] || null) : chequesMapByDate[sepDate] || null;
                    const sepAmount = (sepInst && isInstallmentLocked(sepInst))
                        ? (parseFloat(sepInst.amount) || sf.amount)
                        : sf.amount;
                    const sepPayM   = sepCheque ? (sepCheque.payment_method || 'cheque') : 'cheque';
                    const sepChqNum = sepCheque ? (sepCheque.cheque_number  || '')       : '';
                    const sepHolder = sepCheque ? (sepCheque.account_holder_name || '')  : '';
                    const sepDate2  = sepCheque ? (sepCheque.cheque_date || sepDate)     : sepDate;

                    const row = document.createElement('div');
                    row.className = 'card mb-3 border-0 shadow-sm';
                    row.style.borderLeft = '4px solid #6f42c1 !important';
                    const sepVatAmount = sf.type === 'vat' ? sepAmount : 0;
                    row.innerHTML = `
                        <div class="card-body" data-schedule-row="1" style="border-left:4px solid #6f42c1;">
                            <input type="hidden" name="cheques[${chequeIndex}][installment_id]" value="${sepInst && sepInst.id ? sepInst.id : ''}">
                            <input type="hidden" name="cheques[${chequeIndex}][installment_type]" value="${sf.type}">
                            <input type="hidden" name="cheques[${chequeIndex}][vat_amount]" value="${sepVatAmount.toFixed(2)}">
                            <h6 class="card-title" style="color:#6f42c1;">
                                <i class="bi bi-file-earmark-plus"></i> Separate Installment: <strong>${sf.label}</strong>
                                <small class="text-muted">(${sepDate})</small>
                                <span class="badge ms-2 text-white" style="background:#6f42c1;">Own Cheque</span>
                            </h6>
                            <div class="row">
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Payment Method *</label>
                                    <select name="cheques[${chequeIndex}][payment_method]" class="form-select form-select-sm payment-method-select" required>
                                        <option value="cheque" ${sepPayM==='cheque'?'selected':''}>Cheque</option>
                                        <option value="cash" ${sepPayM==='cash'?'selected':''}>Cash</option>
                                        <option value="bank_transfer" ${sepPayM==='bank_transfer'?'selected':''}>Bank Transfer</option>
                                        <option value="card" ${sepPayM==='card'?'selected':''}>Card</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small cheque-number-label">Cheque Number</label>
                                    <input type="text" name="cheques[${chequeIndex}][cheque_number]" class="form-control form-control-sm" placeholder="Optional" value="${sepChqNum}">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small cheque-date-label">Cheque Date *</label>
                                    <input type="date" name="cheques[${chequeIndex}][cheque_date]" class="form-control form-control-sm" value="${sepDate2}" required>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Amount (AED) *</label>
                                    <input type="number" step="0.01" name="cheques[${chequeIndex}][amount]" class="form-control form-control-sm" value="${sepAmount.toFixed(2)}" required style="background-color:#f3f0ff;">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Holder Name</label>
                                    <input type="text" name="cheques[${chequeIndex}][cheque_holder_name]" class="form-control form-control-sm" placeholder="Optional" value="${sepHolder}">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Cheque Photo</label>
                                    <input type="file" name="cheques[${chequeIndex}][cheque_photo]" class="form-control form-control-sm" accept="image/*">
                                </div>
                            </div>
                        </div>`;
                    container.appendChild(row);
                    const sel = row.querySelector('.payment-method-select');
                    if (sel) updatePaymentLabels(sel);
                    chequeIndex++;
                }

                // ── STEP 2: Combined fees installment (for 'first'-mode fees, if not merging into 1st rent) ─
                if (!addFeesToFirst && totalFees > 0) {
                    let feesInst = null;
                    if (allInstallments && allInstallments.length) {
                        if (combinedFeesInstallmentId) {
                            feesInst = allInstallments.find(i => String(i.id) === combinedFeesInstallmentId) || null;
                        }
                        if (!feesInst) {
                            feesInst = allInstallments.find(i => i.installment_type === 'combined_fees' && !isClearlyMistaggedCombinedFees(i)) || null;
                        }
                        if (!feesInst) {
                            const sepT = new Set(['security_deposit','chiller','ejari','admin','commission','amc','parking','store','vat']);
                            for (const i of allInstallments) {
                                const t = i.installment_type || '';
                                if (sepT.has(t)) continue;
                                if (Math.abs(parseFloat(i.amount) - totalFees) < 0.02) {
                                    feesInst = i;
                                    break;
                                }
                            }
                        }
                    }
                    const feesDate = (feesInst ? feesInst.installment_date : null) || installmentDates[0] || startDate;
                    const feesCheque = feesInst ? (chequesMapById[feesInst.id] || null) : chequesMapByDate[feesDate];
                    const row = document.createElement('div');
                    row.className = 'card mb-3 border-primary';
                    const feesPayMethod  = feesCheque ? (feesCheque.payment_method || 'cheque') : 'cheque';
                    const feesChequeNum = feesCheque ? (feesCheque.cheque_number || '') : '';
                    const feesHolder    = feesCheque ? (feesCheque.account_holder_name || '') : '';
                    const feesChqDate   = feesCheque ? (feesCheque.cheque_date || feesDate) : feesDate;
                    const feesAmount = (preserveExistingScheduleOnInitialEdit && feesInst && Math.abs((parseFloat(feesInst.amount) || 0) - totalFees) < 0.02)
                        ? (parseFloat(feesInst.amount) || totalFees)
                        : totalFees;
                    const feesVatAmount = (v.vatDistributionType === 'first_installment' && v.totalVat > 0) ? v.totalVat : 0;
                    row.innerHTML = `
                        <div class="card-body" data-schedule-row="1">
                            <input type="hidden" name="cheques[${chequeIndex}][installment_id]" value="${feesInst && feesInst.id ? feesInst.id : ''}">
                            <input type="hidden" name="cheques[${chequeIndex}][installment_type]" value="combined_fees">
                            <input type="hidden" name="cheques[${chequeIndex}][vat_amount]" value="${feesVatAmount.toFixed(2)}">
                            <h6 class="card-title text-primary">
                                <i class="bi bi-info-circle"></i> Fees Installment
                                <small class="text-muted">(${feesDate})</small>
                                <span class="badge bg-primary ms-2">Separate fees payment</span>
                            </h6>
                            <div class="row">
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Payment Method *</label>
                                    <select name="cheques[${chequeIndex}][payment_method]" class="form-select form-select-sm payment-method-select" required>
                                        <option value="cheque" ${feesPayMethod==='cheque'?'selected':''}>Cheque</option>
                                        <option value="cash" ${feesPayMethod==='cash'?'selected':''}>Cash</option>
                                        <option value="bank_transfer" ${feesPayMethod==='bank_transfer'?'selected':''}>Bank Transfer</option>
                                        <option value="card" ${feesPayMethod==='card'?'selected':''}>Card</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small cheque-number-label">Cheque Number</label>
                                    <input type="text" name="cheques[${chequeIndex}][cheque_number]" class="form-control form-control-sm" placeholder="Optional" value="${feesChequeNum}">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small cheque-date-label">Cheque Date *</label>
                                    <input type="date" name="cheques[${chequeIndex}][cheque_date]" class="form-control form-control-sm" value="${feesChqDate}" required>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Amount (AED) *</label>
                                    <input type="number" step="0.01" name="cheques[${chequeIndex}][amount]" class="form-control form-control-sm" value="${feesAmount.toFixed(2)}" required readonly style="background-color:#f0f0f0;">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Holder Name</label>
                                    <input type="text" name="cheques[${chequeIndex}][cheque_holder_name]" class="form-control form-control-sm" placeholder="Optional" value="${feesHolder}">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Cheque Photo</label>
                                    <input type="file" name="cheques[${chequeIndex}][cheque_photo]" class="form-control form-control-sm" accept="image/*">
                                </div>
                            </div>
                            <small class="text-muted">Includes: ${feeBreakdown.join(', ') || '–'}</small>
                        </div>`;
                    container.appendChild(row);
                    const feesSelect = row.querySelector('.payment-method-select');
                    if (feesSelect) updatePaymentLabels(feesSelect);
                    chequeIndex++;
                }

                for (let i = 0; i < installments; i++) {
                    const installmentDate = installmentDates[i] || endDate;
                    // Lookup by installment_id first (most reliable), then by date, then by index
                    const rentInst = rentInstallments[i];
                    let existingCheque = (rentInst && chequesMapById[rentInst.id]) || null;
                    if (!existingCheque && !rentInst) existingCheque = chequesMapByDate[installmentDate] || null;
                    if (!existingCheque && !rentInst && existingCheques && existingCheques[i]) existingCheque = existingCheques[i];

                    let installmentAmount = computedRentAmounts[i] ?? monthlyRent;

                    const chequeDate = (preserveRentDates && rentInst && !isInstallmentLocked(rentInst))
                        ? installmentDate
                        : (existingCheque ? (existingCheque.cheque_date || installmentDate) : installmentDate);
                    let chequeAmount = installmentAmount;
                    if (preserveRentDates && rentInst && isInstallmentLocked(rentInst)) {
                        chequeAmount = parseFloat(rentInst.amount) || installmentAmount;
                    } else if (preserveRentDates && existingCheque && isInstallmentLocked(rentInst)) {
                        chequeAmount = parseFloat(existingCheque.cheque_amount) || installmentAmount;
                    }
                    const chequeNumber = existingCheque ? (existingCheque.cheque_number || '') : '';
                    const storedReference = existingCheque ? (existingCheque.reference_number || '') : '';
                    // Never re-post a system placeholder (CHQ-<lease>-<n>) as the reference.
                    const referenceNumber = (!storedReference || /^CHQ-\d+-\d+$/i.test(storedReference))
                        ? chequeNumber
                        : storedReference;
                    const bankName = existingCheque ? (existingCheque.bank_name || '') : '';
                    const rowNotes = existingCheque ? (existingCheque.notes || '') : '';
                    const holderName   = existingCheque ? (existingCheque.account_holder_name || '') : '';
                    const payMethod    = existingCheque ? (existingCheque.payment_method || 'cheque') : 'cheque';
                    const isFirstFees  = (i === 0 && addFeesToFirst && totalFees > 0);

                    const row = document.createElement('div');
                    row.className = 'card mb-3';
                    let rentVatAmount = 0;
                    if (v.vatDistributionType === 'split_installments' && v.totalVat > 0) {
                        rentVatAmount = i === installments - 1
                            ? Math.round((v.totalVat - (Math.round((v.totalVat / installments) * 100) / 100) * (installments - 1)) * 100) / 100
                            : Math.round((v.totalVat / installments) * 100) / 100;
                    } else if (v.vatDistributionType === 'first_installment' && v.totalVat > 0 && addFeesToFirst && i === 0) {
                        rentVatAmount = v.totalVat;
                    } else if (preserveRentDates && rentInst && rentInst.vat_amount) {
                        rentVatAmount = parseFloat(rentInst.vat_amount) || 0;
                    }
                    row.innerHTML = `
                        <div class="card-body" data-schedule-row="1">
                            <input type="hidden" name="cheques[${chequeIndex}][installment_id]" value="${rentInst && rentInst.id ? rentInst.id : ''}">
                            <input type="hidden" name="cheques[${chequeIndex}][vat_amount]" value="${rentVatAmount.toFixed(2)}">
                            <h6 class="card-title">
                                Installment ${i + 1} <small class="text-muted">(${installmentDate})</small>
                                ${isFirstFees ? '<span class="badge bg-success ms-2">Includes Fees</span>' : ''}
                            </h6>
                            <div class="row">
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Payment Method *</label>
                                    <select name="cheques[${chequeIndex}][payment_method]" class="form-select form-select-sm payment-method-select" required>
                                        <option value="cheque" ${payMethod==='cheque'?'selected':''}>Cheque</option>
                                        <option value="cash" ${payMethod==='cash'?'selected':''}>Cash</option>
                                        <option value="bank_transfer" ${payMethod==='bank_transfer'?'selected':''}>Bank Transfer</option>
                                        <option value="card" ${payMethod==='card'?'selected':''}>Card</option>
                                        <option value="other" ${payMethod==='other'?'selected':''}>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small cheque-number-label">Cheque Number</label>
                                    <input type="text" name="cheques[${chequeIndex}][cheque_number]" class="form-control form-control-sm" placeholder="Optional" value="${chequeNumber}">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small cheque-date-label">Cheque Date *</label>
                                    <input type="date" name="cheques[${chequeIndex}][cheque_date]" class="form-control form-control-sm" value="${chequeDate}" required>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Amount (AED) *</label>
                                    <input type="number" step="0.01" name="cheques[${chequeIndex}][amount]" class="form-control form-control-sm" value="${chequeAmount.toFixed(2)}" required ${isFirstFees?'style="background-color:#d4edda;"':''}>
                                    ${isFirstFees ? `<small class="text-muted d-block mt-1">Includes fees: ${totalFees.toFixed(2)}</small>` : ''}
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Holder Name</label>
                                    <input type="text" name="cheques[${chequeIndex}][cheque_holder_name]" class="form-control form-control-sm" placeholder="Optional" value="${holderName}">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label small">Cheque Photo</label>
                                    <input type="file" name="cheques[${chequeIndex}][cheque_photo]" class="form-control form-control-sm" accept="image/*">
                                    ${existingCheque && existingCheque.cheque_photo_path ? `<small class="text-muted d-block mt-1">Current: <a href="${existingCheque.cheque_photo_path}" target="_blank">View</a></small>` : ''}
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-2">
                                    <label class="form-label small">Reference Number</label>
                                    <input type="text" name="cheques[${chequeIndex}][reference_number]" class="form-control form-control-sm" placeholder="Optional" value="${referenceNumber}">
                                </div>
                                <div class="col-md-3 mb-2">
                                    <label class="form-label small">Bank Name</label>
                                    <input type="text" name="cheques[${chequeIndex}][bank_name]" class="form-control form-control-sm" placeholder="Optional" value="${bankName}">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small">Notes</label>
                                    <input type="text" name="cheques[${chequeIndex}][notes]" class="form-control form-control-sm" placeholder="Operational note" value="${rowNotes}">
                                </div>
                            </div>
                        </div>`;
                    container.appendChild(row);
                    const iSelect = row.querySelector('.payment-method-select');
                    if (iSelect) updatePaymentLabels(iSelect);
                    chequeIndex++;
                }
                updateScheduleTotals();
            }
            
            // Function to update field labels based on payment method
            function updatePaymentLabels(selectElement) {
                const paymentMethod = selectElement.value;
                const card = selectElement.closest('.card');
                if (!card) return;
                
                const numberLabel = card.querySelector('.cheque-number-label');
                const dateLabel = card.querySelector('.cheque-date-label');
                
                if (paymentMethod === 'bank_transfer') {
                    if (numberLabel) numberLabel.textContent = 'Reference Number';
                    if (dateLabel) dateLabel.textContent = 'Transfer Date *';
                } else if (paymentMethod === 'cash' || paymentMethod === 'card' || paymentMethod === 'other') {
                    if (numberLabel) numberLabel.textContent = 'Reference Number';
                    if (dateLabel) dateLabel.textContent = 'Expected Collection Date *';
                } else {
                    if (numberLabel) numberLabel.textContent = 'Cheque Number';
                    if (dateLabel) dateLabel.textContent = 'Cheque Date *';
                }
            }
            
            // Use event delegation for dynamically created payment method selects
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('payment-method-select')) {
                    updatePaymentLabels(e.target);
                }
            });
            document.addEventListener('input', function(e) {
                if (e.target.closest('#chequesContainer') && e.target.name && e.target.name.endsWith('[amount]')) {
                    const mode = document.getElementById('scheduleMode');
                    if (mode) mode.value = 'manual';
                    updateScheduleTotals();
                }
            });
            const regenerateScheduleBtn = document.getElementById('regenerateScheduleBtn');
            if (regenerateScheduleBtn) {
                regenerateScheduleBtn.addEventListener('click', function() {
                    if (confirm('Regenerate the operational payment schedule preview? Locked or cleared rows will still be protected on save.')) {
                        const mode = document.getElementById('scheduleMode');
                        if (mode) mode.value = 'auto';
                        preserveExistingScheduleOnInitialEdit = false;
                        generateChequesRows();
                    }
                });
            }
            const addManualScheduleRowBtn = document.getElementById('addManualScheduleRowBtn');
            if (addManualScheduleRowBtn) {
                addManualScheduleRowBtn.addEventListener('click', function() {
                    const mode = document.getElementById('scheduleMode');
                    if (mode) mode.value = 'manual';
                    const container = document.getElementById('chequesContainer');
                    const index = container.querySelectorAll('.card').length;
                    const today = document.getElementById('startDate')?.value || new Date().toISOString().slice(0, 10);
                    const row = document.createElement('div');
                    row.className = 'card mb-3 border-info';
                    row.innerHTML = `<div class="card-body" data-schedule-row="1">
                        <input type="hidden" name="cheques[${index}][vat_amount]" value="0.00">
                        <h6 class="card-title text-info">Manual Schedule Row</h6>
                        <div class="row">
                            <div class="col-md-2 mb-2"><label class="form-label small">Payment Method *</label><select name="cheques[${index}][payment_method]" class="form-select form-select-sm payment-method-select" required><option value="cheque">Cheque</option><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="card">Card</option><option value="other">Other</option></select></div>
                            <div class="col-md-2 mb-2"><label class="form-label small cheque-number-label">Cheque / Reference No.</label><input type="text" name="cheques[${index}][cheque_number]" class="form-control form-control-sm"></div>
                            <div class="col-md-2 mb-2"><label class="form-label small cheque-date-label">Expected Date *</label><input type="date" name="cheques[${index}][cheque_date]" class="form-control form-control-sm" value="${today}" required></div>
                            <div class="col-md-2 mb-2"><label class="form-label small">Amount (AED) *</label><input type="number" step="0.01" name="cheques[${index}][amount]" class="form-control form-control-sm" value="0.00" required></div>
                            <div class="col-md-2 mb-2"><label class="form-label small">Reference Number</label><input type="text" name="cheques[${index}][reference_number]" class="form-control form-control-sm"></div>
                            <div class="col-md-2 mb-2"><label class="form-label small">Bank Name</label><input type="text" name="cheques[${index}][bank_name]" class="form-control form-control-sm"></div>
                            <div class="col-md-12 mb-2"><label class="form-label small">Notes</label><input type="text" name="cheques[${index}][notes]" class="form-control form-control-sm"></div>
                        </div></div>`;
                    container.appendChild(row);
                    updateScheduleTotals();
                });
            }
            
            // Update labels for existing payment methods on page load
            document.querySelectorAll('.payment-method-select').forEach(select => {
                updatePaymentLabels(select);
            });
            
            document.getElementById('startDate').addEventListener('change', markScheduleChangedAndGenerateRows);
            document.getElementById('endDate').addEventListener('change', markScheduleChangedAndGenerateRows);
            
            // Initial calculation
            calculateMonthlyRent();
            
            // Enable/disable preview button based on template selection
            const templateSelect = document.getElementById('templateSelect');
            const previewBtn = document.getElementById('previewContractBtn');
            
            function updatePreviewButton() {
                previewBtn.disabled = !templateSelect.value;
                if (templateSelect.value) {
                    previewBtn.classList.remove('btn-outline-secondary');
                    previewBtn.classList.add('btn-outline-info');
                } else {
                    previewBtn.classList.remove('btn-outline-info');
                    previewBtn.classList.add('btn-outline-secondary');
                }
            }
            
            templateSelect.addEventListener('change', updatePreviewButton);
            updatePreviewButton(); // Initial state - will enable if template is pre-selected
            
            // Preview Contract functionality
            previewBtn.addEventListener('click', function() {
                const templateId = document.getElementById('templateSelect').value;
                if (!templateId) {
                    alert('Please select a contract template first.');
                    return;
                }
                
                // Collect form data for preview
                const formData = new FormData(document.getElementById('leaseForm'));
                formData.append('action', 'preview_contract');
                formData.append('template_id', templateId);
                <?php if ($leaseId): ?>
                formData.append('lease_id', <?= $leaseId ?>);
                <?php endif; ?>
                
                // Show loading
                const btn = this;
                const originalHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading...';
                
                fetch('ajax_preview_contract.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                    
                    if (data.error) {
                        alert('Error: ' + data.error);
                        return;
                    }
                    
                    // Show preview in modal
                    const previewModal = new bootstrap.Modal(document.getElementById('contractPreviewModal'));
                    document.getElementById('contractPreviewContent').innerHTML = data.content;
                    previewModal.show();
                })
                .catch(error => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                    alert('Error loading preview: ' + error.message);
                });
            });
        </script>

        <!-- Contract Preview Modal -->
        <div class="modal fade" id="contractPreviewModal" tabindex="-1">
            <div class="modal-dialog modal-fullscreen">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-file-text"></i> Contract Preview</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="max-height: 80vh; overflow-y: auto; padding: 20px; background: #fff;">
                        <style>
                            .contract-bilingual-wrapper {
                                display: flex;
                                gap: 20px;
                                font-family: 'Times New Roman', serif;
                                font-size: 11pt;
                                line-height: 1.8;
                                max-width: 100%;
                            }
                            .contract-english-column {
                                flex: 1;
                                padding: 15px;
                                text-align: left;
                                direction: ltr;
                                border-right: 2px solid #ddd;
                            }
                            .contract-arabic-column {
                                flex: 1;
                                padding: 15px;
                                text-align: right;
                                direction: rtl;
                                font-family: 'Arial', 'Tahoma', 'DejaVu Sans', sans-serif;
                            }
                            .contract-line {
                                min-height: 1.8em;
                                margin-bottom: 2px;
                            }
                            @media print {
                                .contract-bilingual-wrapper {
                                    font-size: 10pt;
                                    gap: 15px;
                                }
                                .contract-english-column,
                                .contract-arabic-column {
                                    padding: 10px;
                                }
                                .contract-line {
                                    page-break-inside: avoid;
                                }
                            }
                        </style>
                        <div id="contractPreviewContent"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" onclick="window.print()">
                            <i class="bi bi-printer"></i> Print Preview
                        </button>
                    </div>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
