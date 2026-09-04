<?php
/**
 * Real Estate Module - Lease Renewal Workflow View
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/phpmailer_bootstrap.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

require_once __DIR__ . '/includes/lease_installments_generate.php';
require_once __DIR__ . '/includes/lease_vat_calculator.php';
require_once __DIR__ . '/includes/lease_number_sequence.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';
require_once __DIR__ . '/includes/accounting_mode_helper.php';
require_once __DIR__ . '/includes/obligation_engine.php';
require_once __DIR__ . '/includes/invoice_engine.php';
require_once __DIR__ . '/../../includes/renewal_portal_urls.php';
require_once __DIR__ . '/../../includes/renewal_workflow_transitions.php';
require_once __DIR__ . '/../../includes/renewal_negotiation_thread.php';

/**
 * Renewal workflow FSM status. Required because SELECT rw.*, l.* lets l.status (lease) overwrite rw.status.
 */
function lease_renewal_view_rw_status(array $w): string {
    return (string)($w['renewal_workflow_status'] ?? '');
}

function lease_renewal_view_user_can_admin_override(PDO $conn): bool {
    $roles = current_user_roles($conn);
    return in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
}

function lease_renewal_view_amount_present($value): bool {
    return $value !== null && $value !== '' && (float)$value > 0;
}

function lease_renewal_view_normalize_workflow(array $workflow): array {
    $workflow['created_by'] = array_key_exists('wf_created_by', $workflow) ? $workflow['wf_created_by'] : ($workflow['created_by'] ?? null);
    $workflow['assigned_to'] = array_key_exists('wf_assigned_to', $workflow) ? $workflow['wf_assigned_to'] : ($workflow['assigned_to'] ?? null);

    $workflow['chiller_charges'] = (float)($workflow['wf_chiller_display'] ?? $workflow['chiller_charges'] ?? 0);
    $workflow['admin_fees'] = (float)($workflow['wf_admin_display'] ?? $workflow['admin_fees'] ?? 0);
    $workflow['vat_extra_charges'] = (float)($workflow['wf_vat_display'] ?? $workflow['vat_extra_charges'] ?? 0);
    $workflow['additional_parking_enabled'] = (int)($workflow['wf_additional_parking_enabled'] ?? $workflow['additional_parking_enabled'] ?? 0);
    $workflow['additional_parking_fee'] = (float)($workflow['wf_additional_parking_fee'] ?? $workflow['additional_parking_fee'] ?? 0);
    $workflow['small_store_enabled'] = (int)($workflow['wf_small_store_enabled'] ?? $workflow['small_store_enabled'] ?? 0);
    $workflow['small_store_fee'] = (float)($workflow['wf_small_store_fee'] ?? $workflow['small_store_fee'] ?? 0);
    $workflow['big_store_enabled'] = (int)($workflow['wf_big_store_enabled'] ?? $workflow['big_store_enabled'] ?? 0);
    $workflow['big_store_fee'] = (float)($workflow['wf_big_store_fee'] ?? $workflow['big_store_fee'] ?? 0);

    $missingCoreTerms = !lease_renewal_view_amount_present($workflow['wf_current_rent'] ?? $workflow['current_rent'] ?? null)
        && !lease_renewal_view_amount_present($workflow['wf_proposed_rent'] ?? $workflow['proposed_rent'] ?? null);
    $workflow['_missing_core_terms'] = $missingCoreTerms;

    $leaseAnnualRent = (float)($workflow['annual_rent'] ?? 0);
    $leaseMonthlyRent = (float)($workflow['monthly_rent'] ?? 0);
    $currentRent = (float)($workflow['wf_current_rent'] ?? $workflow['current_rent'] ?? 0);
    if ($currentRent <= 0) {
        $currentRent = $leaseAnnualRent > 0 ? $leaseAnnualRent : round($leaseMonthlyRent * 12, 2);
    }
    $workflow['current_rent'] = $currentRent;

    $increaseType = (string)($workflow['increase_type'] ?? 'percentage');
    if (!in_array($increaseType, ['percentage', 'fixed'], true)) {
        $increaseType = 'percentage';
    }
    $increaseValue = (float)($workflow['increase_value'] ?? 0);
    $calculated = (float)($workflow['calculated_new_rent'] ?? 0);
    if ($calculated <= 0 && $currentRent > 0) {
        $calculated = $increaseType === 'percentage'
            ? round($currentRent + ($currentRent * ($increaseValue / 100)), 2)
            : round($currentRent + $increaseValue, 2);
    }
    $proposedRent = (float)($workflow['wf_proposed_rent'] ?? $workflow['proposed_rent'] ?? 0);
    if ($proposedRent <= 0) {
        $proposedRent = $calculated > 0 ? $calculated : $currentRent;
    }
    $workflow['calculated_new_rent'] = $calculated > 0 ? $calculated : $proposedRent;
    $workflow['proposed_rent'] = $proposedRent;

    $oldInstallments = (int)($workflow['number_of_installments'] ?? 0);
    $workflowCheques = (int)($workflow['number_of_cheques'] ?? 0);
    if ($missingCoreTerms && $oldInstallments > 0) {
        $workflow['number_of_cheques'] = $oldInstallments;
    } elseif ($workflowCheques <= 0) {
        $workflow['number_of_cheques'] = $oldInstallments > 0 ? $oldInstallments : 4;
    }

    $proposedStart = trim((string)($workflow['wf_proposed_start_date'] ?? $workflow['proposed_start_date'] ?? ''));
    if ($proposedStart === '' && !empty($workflow['end_date'])) {
        $proposedStart = date('Y-m-d', strtotime((string)$workflow['end_date'] . ' +1 day'));
    }
    $proposedEnd = trim((string)($workflow['wf_proposed_end_date'] ?? $workflow['proposed_end_date'] ?? ''));
    if ($proposedEnd === '' && $proposedStart !== '') {
        $durationDays = 364;
        if (!empty($workflow['start_date']) && !empty($workflow['end_date'])) {
            try {
                $durationDays = (new DateTime((string)$workflow['end_date']))->diff(new DateTime((string)$workflow['start_date']))->days;
            } catch (Throwable $e) {
                $durationDays = 364;
            }
        }
        $proposedEnd = date('Y-m-d', strtotime($proposedStart . ' +' . max(1, $durationDays) . ' days'));
    }
    $workflow['proposed_start_date'] = $proposedStart !== '' ? $proposedStart : null;
    $workflow['proposed_end_date'] = $proposedEnd !== '' ? $proposedEnd : null;

    $workflow['_created_by_display'] = trim((string)($workflow['created_by_name'] ?? ''));
    if ($workflow['_created_by_display'] === '' && !empty($workflow['created_by'])) {
        $workflow['_created_by_display'] = 'User #' . (int)$workflow['created_by'];
    }
    if ($workflow['_created_by_display'] === '' && !empty($workflow['lease_created_by_name'])) {
        $workflow['_created_by_display'] = $workflow['lease_created_by_name'] . ' (lease creator; workflow creator was not saved)';
    }
    if ($workflow['_created_by_display'] === '') {
        $workflow['_created_by_display'] = '-';
    }

    return $workflow;
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
re_lease_activate_due_renewals($conn, $currentCompanyId, $userId);

$workflowId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$workflowId) {
    header('Location: lease_renewal_workflow.php');
    exit;
}

// Get workflow details (l.* overwrites rw.* for duplicate keys like admin_fees, status — use renewal_workflow_status / lease_status)
$stmt = $conn->prepare("
    SELECT rw.*, 
           l.*, l.id as lease_id,
           rw.vat_applicable_on_rent AS wf_vat_applicable_on_rent,
           rw.rent_vat_amount AS wf_rent_vat_amount,
           rw.vat_distribution_type AS wf_vat_distribution_type,
           rw.vat_notes AS wf_vat_notes,
           rw.created_by AS wf_created_by,
           rw.assigned_to AS wf_assigned_to,
           rw.current_rent AS wf_current_rent,
           rw.proposed_rent AS wf_proposed_rent,
           rw.proposed_start_date AS wf_proposed_start_date,
           rw.proposed_end_date AS wf_proposed_end_date,
           u.unit_number, u.unit_type,
           b.id AS wf_building_id,
           b.name as building_name,
           t.first_name, t.last_name, t.email, t.phone,
           COALESCE(NULLIF(u2.fullname, ''), u2.username) as assigned_to_name,
           COALESCE(NULLIF(u_creator.fullname, ''), u_creator.username) AS created_by_name,
           COALESCE(NULLIF(u_notice.fullname, ''), u_notice.username) AS notice_sent_by_name,
           l.created_by AS lease_created_by,
           COALESCE(NULLIF(u_lease_creator.fullname, ''), u_lease_creator.username) AS lease_created_by_name,
           c.name AS company_name,
           rw.chiller_charges AS wf_chiller_display,
           rw.admin_fees AS wf_admin_display,
           rw.vat_extra_charges AS wf_vat_display,
           rw.additional_parking_enabled AS wf_additional_parking_enabled,
           rw.additional_parking_fee AS wf_additional_parking_fee,
           rw.small_store_enabled AS wf_small_store_enabled,
           rw.small_store_fee AS wf_small_store_fee,
           rw.big_store_enabled AS wf_big_store_enabled,
           rw.big_store_fee AS wf_big_store_fee,
           COALESCE(rbs.show_chiller_row, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS effective_show_chiller_row,
           COALESCE(rbs.show_chiller_term, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS effective_show_chiller_term,
           rw.status AS renewal_workflow_status,
           l.status AS lease_status
    FROM re_lease_renewal_workflows rw
    JOIN re_leases l ON l.id = rw.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_renewal_notice_building_settings rbs ON rbs.company_id = l.company_id AND rbs.building_id = u.building_id
    LEFT JOIN user u2 ON u2.id = rw.assigned_to
    LEFT JOIN user u_creator ON u_creator.id = rw.created_by
    LEFT JOIN user u_notice ON u_notice.id = rw.notice_sent_by
    LEFT JOIN user u_lease_creator ON u_lease_creator.id = l.created_by
    LEFT JOIN companies c ON c.id = l.company_id
    WHERE rw.id = ? AND l.company_id = ?
");
$stmt->execute([$workflowId, $currentCompanyId]);
$workflow = $stmt->fetch(PDO::FETCH_ASSOC);
if ($workflow) {
    $workflow = lease_renewal_view_normalize_workflow($workflow);
}

if (!$workflow) {
    header('Location: lease_renewal_workflow.php');
    exit;
}

$success = '';
$error = '';
$appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';

function lease_renewal_view_create_draft_lease(PDO $conn, array $workflow, int $companyId, int $userId): int {
    $leaseId = (int)($workflow['lease_id'] ?? 0);
    $unitId = (int)($workflow['unit_id'] ?? 0);
    $tenantId = (int)($workflow['tenant_id'] ?? 0);
    if ($leaseId <= 0 || $unitId <= 0 || $tenantId <= 0) {
        throw new Exception('Cannot recreate draft lease because workflow lease, unit, or tenant context is missing.');
    }

    $newLeaseNumber = lease_generate_number_for_unit($conn, $companyId, $unitId);
    if ($newLeaseNumber === null || $newLeaseNumber === '') {
        throw new Exception('Could not generate a draft lease number for this unit.');
    }

    $proposedRent = round(max(0, (float)($workflow['proposed_rent'] ?? $workflow['calculated_new_rent'] ?? $workflow['current_rent'] ?? 0)), 2);
    $numberOfInstallments = (int)($workflow['number_of_cheques'] ?? 0);
    if ($numberOfInstallments <= 0) {
        $numberOfInstallments = !empty($workflow['number_of_installments']) ? (int)$workflow['number_of_installments'] : 12;
    }
    $monthlyRent = $numberOfInstallments > 0 ? round($proposedRent / $numberOfInstallments, 2) : round($proposedRent / 12.0, 2);
    $proposedStartDate = !empty($workflow['proposed_start_date']) ? date('Y-m-d', strtotime((string)$workflow['proposed_start_date'])) : date('Y-m-d', strtotime((string)($workflow['end_date'] ?? 'today') . ' +1 day'));
    $proposedEndDate = !empty($workflow['proposed_end_date']) ? date('Y-m-d', strtotime((string)$workflow['proposed_end_date'])) : date('Y-m-d', strtotime($proposedStartDate . ' +1 year -1 day'));

    $chiller = round(max(0, (float)($workflow['chiller_charges'] ?? 0)), 2);
    $admin = round(max(0, (float)($workflow['admin_fees'] ?? 0)), 2);
    $parkEnabled = !empty($workflow['additional_parking_enabled']) ? 1 : 0;
    $parkFee = $parkEnabled ? round(max(0, (float)($workflow['additional_parking_fee'] ?? 0)), 2) : 0.0;
    $smallEnabled = !empty($workflow['small_store_enabled']) ? 1 : 0;
    $bigEnabled = !empty($workflow['big_store_enabled']) ? 1 : 0;
    $storeEnabled = ($smallEnabled || $bigEnabled) ? 1 : 0;
    $storeFee = round(max(0, (float)($workflow['small_store_fee'] ?? 0)) + max(0, (float)($workflow['big_store_fee'] ?? 0)), 2);
    $vatRate = (float)($workflow['vat_rate'] ?? 5.0);
    if ($vatRate <= 0) $vatRate = 5.0;
    $rentVat = round(max(0, (float)($workflow['wf_rent_vat_amount'] ?? $workflow['rent_vat_amount'] ?? 0)), 2);
    $extraVat = round(max(0, (float)($workflow['vat_extra_charges'] ?? 0)), 2);
    $totalVat = round($rentVat + $extraVat, 2);
    $vatDistribution = lease_vat_normalize_distribution($workflow['wf_vat_distribution_type'] ?? $workflow['vat_distribution_type'] ?? 'first_installment');

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
        $monthlyRent,
        $numberOfInstallments,
        (float)($workflow['security_deposit'] ?? 0),
        $chiller,
        (float)($workflow['ejari_fees'] ?? 0),
        $admin,
        0.00,
        (float)($workflow['amc_amount'] ?? 0),
        (int)($workflow['split_amc_fees'] ?? 0),
        (int)($workflow['add_fees_to_first_installment'] ?? 1),
        (int)($workflow['deferred_revenue_mode'] ?? 1),
        (int)($workflow['split_chiller_fees'] ?? 0),
        (int)($workflow['split_ejari_fees'] ?? 0),
        (int)($workflow['split_admin_fees'] ?? 0),
        0,
        (int)($workflow['split_additional_parking'] ?? 0),
        (int)($workflow['split_additional_store'] ?? 0),
        $parkEnabled,
        $parkEnabled ? $parkFee : 0.0,
        $parkEnabled ? $proposedStartDate : null,
        $parkEnabled ? $proposedEndDate : null,
        $storeEnabled,
        $storeEnabled ? $storeFee : 0.0,
        $storeEnabled ? $proposedStartDate : null,
        $storeEnabled ? $proposedEndDate : null,
        (int)($workflow['payment_day'] ?? 1),
        $workflow['payment_method'] ?? 'cheque',
        (int)($workflow['grace_period_days'] ?? 0),
        $workflow['renewal_terms'] ?? null,
        $workflow['template_id'] ?? null,
        $workflow['ejari_registration_number'] ?? null,
        $workflow['ejari_issue_date'] ?? null,
        $workflow['ejari_property_code'] ?? null,
        $workflow['notes'] ?? null,
        $userId,
        $leaseId,
        !empty($workflow['wf_vat_applicable_on_rent']) || !empty($workflow['vat_applicable_on_rent']) ? 1 : 0,
        !empty($workflow['vat_enabled']) ? 1 : 0,
        $vatRate,
        $rentVat,
        $extraVat,
        $totalVat,
        $vatDistribution,
        $workflow['wf_vat_notes'] ?? $workflow['vat_notes'] ?? null,
        (int)($workflow['is_multi_unit'] ?? 0)
    ]);

    $newLeaseId = (int)$conn->lastInsertId();
    if (function_exists('re_obligation_column_exists') && re_obligation_column_exists($conn, 're_leases', 'accounting_mode')) {
        $conn->prepare("UPDATE re_leases SET accounting_mode = 'invoice' WHERE id = ? AND company_id = ?")
            ->execute([$newLeaseId, $companyId]);
        if (function_exists('re_accounting_log_mode_change')) {
            re_accounting_log_mode_change($conn, $companyId, $newLeaseId, null, 'invoice', $userId, 'Renewal workflow creates renewal leases in Invoice Mode.', 'renewal_workflow');
        }
    }

    return $newLeaseId;
}

// Rent-change audit history
$rentHistoryStmt = $conn->prepare("
    SELECT rh.*,
           u.username AS changed_by_name
    FROM re_lease_renewal_rent_history rh
    LEFT JOIN user u ON u.id = rh.changed_by
    WHERE rh.workflow_id = ?
    ORDER BY rh.changed_at ASC, rh.id ASC
");
$rentHistoryStmt->execute([$workflowId]);
$rentHistory = $rentHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Email history for this renewal workflow
$emailHistoryStmt = $conn->prepare("
    SELECT el.*
    FROM re_email_logs el
    WHERE el.related_type = 'lease_renewal_workflow'
      AND el.related_id = ?
      AND el.notification_type = 'lease_renewal_notice'
    ORDER BY el.id DESC
    LIMIT 10
");
$emailHistoryStmt->execute([$workflowId]);
$emailHistory = $emailHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions (Send Notice, Save Response/Notes, Approve/Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    try {
        if (!function_exists('handleRenewalStampUploadFromView')) {
            function handleRenewalStampUploadFromView(string $inputName, string $targetBaseDir): ?string {
                if (empty($_FILES[$inputName]['tmp_name'])) return null;
                $f = $_FILES[$inputName];
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
                $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) return null;
                if (!is_dir($targetBaseDir)) mkdir($targetBaseDir, 0755, true);
                $filename = 'renewal_stamp_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $abs = rtrim($targetBaseDir, '/') . '/' . $filename;
                if (!move_uploaded_file($f['tmp_name'], $abs)) return null;
                return str_replace(__DIR__ . '/../../', '', $abs);
            }
        }

        if ($action === 'save_notice_content') {
            if (!renewal_wf_can_save_notice_content(lease_renewal_view_rw_status($workflow))) {
                throw new Exception('Notice content cannot be edited for this workflow state.');
            }
            $termsText = trim((string)($_POST['terms_text'] ?? ''));
            $requiredDocs = trim((string)($_POST['required_documents_text'] ?? ''));
            $adminFeeLabel = trim((string)($_POST['admin_fee_label'] ?? ''));
            if ($adminFeeLabel === '') {
                $adminFeeLabel = 'Lease Renewal Charges';
            }
            $stampPath = handleRenewalStampUploadFromView('stamp_image', __DIR__ . '/../../uploads/renewal_notices/stamps');

            if ($stampPath) {
                $conn->prepare("
                    UPDATE re_lease_renewal_workflows
                    SET terms_text = ?, required_documents_text = ?, admin_fee_label = ?, stamp_image_path = ?
                    WHERE id = ?
                ")->execute([$termsText, $requiredDocs, $adminFeeLabel, $stampPath, $workflowId]);
            } else {
                $conn->prepare("
                    UPDATE re_lease_renewal_workflows
                    SET terms_text = ?, required_documents_text = ?, admin_fee_label = ?
                    WHERE id = ?
                ")->execute([$termsText, $requiredDocs, $adminFeeLabel, $workflowId]);
            }
            $success = "Renewal notice content saved.";
            renewal_wf_log_admin_event($conn, $workflowId, $currentCompanyId, $userId, 'admin_save_notice_content', 'Notice terms / documents text updated.');
        } elseif ($action === 'send_renewal_notice') {
            require_once __DIR__ . '/includes/renewal_notice_pdf_generator.php';

            $userId = current_user_id();
            $wfStatusSend = lease_renewal_view_rw_status($workflow);
            if ($wfStatusSend === '') {
                $wfStatusSend = 'initiated';
            }
            if (!renewal_wf_can_send_notice($wfStatusSend)) {
                throw new Exception('Renewal notice cannot be sent in the current workflow state.');
            }

                    // Resolve recipient email + basic validation first
            // Generate PDF
            $pdfRelativePath = (new RenewalNoticePDFGenerator($conn, $currentCompanyId))->generateFromWorkflow($workflowId);
            $basePath = dirname(dirname(__DIR__)); // project root
            $pdfAbsPath = $basePath . '/' . $pdfRelativePath;
            if (!file_exists($pdfAbsPath)) {
                throw new Exception("Renewal notice PDF file not found after generation.");
            }

            // Load tenant email
            $tenantEmail = trim((string)($workflow['email'] ?? ''));
            if (!$tenantEmail || !filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Tenant email not found/invalid.");
            }

            // Load email template (global table)
            $tplStmt = $conn->prepare("SELECT subject, body_html FROM email_templates WHERE template_type = 'renewal_notice' AND is_active = 1 ORDER BY id DESC LIMIT 1");
            $tplStmt->execute();
            $tpl = $tplStmt->fetch(PDO::FETCH_ASSOC);

            $subject = $tpl['subject'] ?? '[Real Estate] Renewal Notice';
            $tplBodyHtml = trim((string)($tpl['body_html'] ?? ''));
            $bodyHtml = $tplBodyHtml !== '' ? $tplBodyHtml : (
                "<div style='font-family:Arial, sans-serif; line-height:1.6; color:#111; max-width:650px; margin:0 auto;'>"
                . "<p>Dear {TENANT_NAME},</p>"
                . "<p>Please find attached your <strong>lease renewal notice</strong> for <strong>unit {UNIT_NUMBER}</strong>.</p>"
                . "<table style='border-collapse:collapse; width:100%; margin-top:10px;'>"
                . "<tr><td style='border:1px solid #ddd; padding:8px; font-weight:600;'>Current Annual Rent</td>"
                . "<td style='border:1px solid #ddd; padding:8px;'>{CURRENT_RENT} AED / year</td></tr>"
                . "<tr><td style='border:1px solid #ddd; padding:8px; font-weight:600;'>New Annual Rent</td>"
                . "<td style='border:1px solid #ddd; padding:8px;'>{NEW_RENT} AED / year</td></tr>"
                . "<tr><td style='border:1px solid #ddd; padding:8px; font-weight:600;'>Chiller Charges</td>"
                . "<td style='border:1px solid #ddd; padding:8px;'>{CHILLER_CHARGES} AED</td></tr>"
                . "<tr><td style='border:1px solid #ddd; padding:8px; font-weight:600;'>Administrative Charges</td>"
                . "<td style='border:1px solid #ddd; padding:8px;'>{ADMIN_FEES} AED</td></tr>"
                . "<tr><td style='border:1px solid #ddd; padding:8px; font-weight:600;'>VAT (Extra Charges)</td>"
                . "<td style='border:1px solid #ddd; padding:8px;'>{VAT_EXTRA_CHARGES}</td></tr>"
                . "<tr><td style='border:1px solid #ddd; padding:8px; font-weight:600;'>Lease Period</td>"
                . "<td style='border:1px solid #ddd; padding:8px;'>{PROPOSED_START_DATE} to {PROPOSED_END_DATE}</td></tr>"
                . "</table>"
                . "<p style='margin-top:12px;'>You can also <strong>view this renewal, acknowledge receipt, and respond</strong> in the Tenant Portal:</p>"
                . "<p style='margin-top:8px;'>{RENEWAL_PORTAL_LINK}</p>"
                . "<p style='margin-top:12px; font-size:12px; color:#555;'>Direct link: {RENEWAL_PORTAL_URL}</p>"
                . "<p style='margin-top:12px;'>Kindly review the details and respond to this email with your inquiries. Thank you.</p>"
                . "<p>Regards,<br/>{COMPANY_NAME}</p>"
                . "</div>"
            );

            // Placeholder replacements
            $placeholders = [
                '{TENANT_NAME}' => $workflow['first_name'] . ' ' . $workflow['last_name'],
                '{UNIT_NUMBER}' => (string)($workflow['unit_number'] ?? ''),
                '{BUILDING_NAME}' => (string)($workflow['building_name'] ?? ''),
                '{CURRENT_RENT}' => number_format((float)($workflow['current_rent'] ?? $workflow['proposed_rent'] ?? 0), 2),
                '{NEW_RENT}' => number_format((float)($workflow['proposed_rent'] ?? 0), 2),
                '{INCREASE_TYPE}' => (string)($workflow['increase_type'] ?? ''),
                '{INCREASE_VALUE}' => number_format((float)($workflow['increase_value'] ?? 0), 2),
                '{CHILLER_CHARGES}' => number_format((float)($workflow['chiller_charges'] ?? 0), 2),
                '{ADMIN_FEES}' => number_format((float)($workflow['admin_fees'] ?? 0), 2),
                '{VAT_EXTRA_CHARGES}' => number_format((float)($workflow['vat_extra_charges'] ?? 0), 2),
                '{PROPOSED_START_DATE}' => !empty($workflow['proposed_start_date']) ? date('Y-m-d', strtotime($workflow['proposed_start_date'])) : '—',
                '{PROPOSED_END_DATE}' => !empty($workflow['proposed_end_date']) ? date('Y-m-d', strtotime($workflow['proposed_end_date'])) : '—',
                '{OLD_LEASE_NUMBER}' => (string)($workflow['lease_number'] ?? ''),
                '{NEW_LEASE_ID}' => (string)($workflow['new_lease_id'] ?? ''),
                '{COMPANY_NAME}' => (string)($workflow['company_name'] ?? 'Real Estate'),
                '{RENEWAL_PORTAL_URL}' => renewal_portal_renewal_detail_abs_url($workflowId),
                '{RENEWAL_PORTAL_LINK}' => renewal_portal_renewal_detail_link_html($workflowId),
            ];

            $subjectFinal = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
            $bodyFinal = str_replace(array_keys($placeholders), array_values($placeholders), $bodyHtml);

                    // Insert email attempt log (so you always get history)
                    $logStmt = $conn->prepare("
                        INSERT INTO re_email_logs
                            (company_id, notification_type, recipient_email, subject, status, related_id, related_type)
                        VALUES
                            (?, ?, ?, ?, 'pending', ?, ?)
                    ");
                    $notificationType = 'lease_renewal_notice';
                    $logStmt->execute([
                        $currentCompanyId,
                        $notificationType,
                        $tenantEmail,
                        $subjectFinal,
                        $workflowId,
                        'lease_renewal_workflow'
                    ]);
                    $emailLogId = (int)$conn->lastInsertId();

            // SMTP settings
            $emailSettings = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
            $emailSettings->execute();
            $settings = $emailSettings->fetch(PDO::FETCH_ASSOC);
            if (!$settings || empty($settings['smtp_host']) || empty($settings['smtp_username'])) {
                throw new Exception('Email settings not configured/enabled.');
            }

            herosysgro_ensure_phpmailer();

            if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                throw new Exception(
                    'PHPMailer is not loaded. Ensure vendor/autoload.php exists and run `composer install`, ' .
                    'or that vendor/phpmailer/phpmailer/src/ is present on the server.'
                );
            }

            // Send with PHPMailer (with attachment)
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $settings['smtp_host'] ?? '';
            $mail->Port = (int)($settings['smtp_port'] ?? 587);
            $secure = strtolower($settings['smtp_secure'] ?? 'tls');
            if ($secure === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->SMTPAuth = !empty($settings['smtp_username']);
            if ($mail->SMTPAuth) {
                $mail->Username = $settings['smtp_username'];
                $mail->Password = $settings['smtp_password'] ?? '';
            }

            $mail->CharSet = 'UTF-8';
            $fromEmail = $settings['from_email'] ?? $settings['smtp_username'] ?? 'no-reply@example.com';
            $mail->setFrom($fromEmail, $settings['from_name'] ?? $fromEmail);
            $mail->addAddress($tenantEmail, ($workflow['first_name'] ?? '') . ' ' . ($workflow['last_name'] ?? ''));
            $mail->Subject = $subjectFinal;
            $mail->msgHTML($bodyFinal);

            $filename = basename($pdfAbsPath);
            $mail->addAttachment($pdfAbsPath, $filename);

                    try {
                        // If you want debug temporarily, append `?debug_email=1` to this page URL
                        if (!empty($_GET['debug_email'])) {
                            $mail->SMTPDebug = 2;
                            $mail->Debugoutput = function($str, $level) {
                                error_log("Renewal Notice Email Debug: {$str}");
                            };
                        }

                        $mail->send();
                        $conn->prepare("
                            UPDATE re_email_logs
                            SET status = 'sent', sent_at = ?, error_message = NULL
                            WHERE id = ?
                        ")->execute([date('Y-m-d H:i:s'), $emailLogId]);
                    } catch (Throwable $e) {
                        $err = $e->getMessage();
                        $conn->prepare("
                            UPDATE re_email_logs
                            SET status = 'failed', sent_at = NULL, error_message = ?
                            WHERE id = ?
                        ")->execute([$err, $emailLogId]);
                        throw $e; // bubble up to outer catch for UI feedback
                    }

            // Update workflow status (first send → notice_sent; resend → keep status, refresh sent metadata)
            $targetStatus = renewal_wf_send_notice_target_status($wfStatusSend);
            if ($targetStatus !== null) {
                $u = $conn->prepare("
                    UPDATE re_lease_renewal_workflows
                    SET status = ?,
                        notice_sent_at = NOW(),
                        notice_sent_by = ?
                    WHERE id = ? AND status = ?
                ");
                $u->execute([$targetStatus, $userId, $workflowId, $wfStatusSend]);
                if ($u->rowCount() === 0) {
                    throw new Exception('Workflow status changed before send completed. Refresh the page and try again.');
                }
            } else {
                $u = $conn->prepare("
                    UPDATE re_lease_renewal_workflows
                    SET notice_sent_at = NOW(),
                        notice_sent_by = ?
                    WHERE id = ?
                ");
                $u->execute([$userId, $workflowId]);
            }
            renewal_wf_log_admin_event(
                $conn,
                $workflowId,
                $currentCompanyId,
                $userId,
                'admin_send_renewal_notice',
                $targetStatus !== null ? 'Renewal notice email sent (status → notice_sent).' : 'Renewal notice email re-sent (status unchanged).',
                json_encode(['email_log_id' => $emailLogId], JSON_UNESCAPED_SLASHES) ?: null
            );

            // Tenant in-app notification: renewal notice issued
            if (is_file(__DIR__ . '/../../includes/tenant_notifications.php')) require_once __DIR__ . '/../../includes/tenant_notifications.php';
            if (function_exists('tenant_notification_create')) tenant_notification_create($conn, [
                'company_id' => $currentCompanyId,
                'lease_id' => (int)($workflow['lease_id'] ?? 0),
                'type' => 'renewal_notice',
                'entity_type' => 'renewal',
                'entity_id' => (int)$workflowId,
                'title' => 'Renewal notice available',
                'body' => 'A lease renewal notice has been issued. Tap to review the proposed terms.',
            ]);

                    $success = "Renewal notice sent to tenant successfully. (Email log #{$emailLogId})";
            try {
                require_once __DIR__ . '/../../includes/audit_bridge.php';
                audit_bridge_re_ops(
                    $conn,
                    (int)$currentCompanyId,
                    'email_sent',
                    're_lease_renewal_workflows',
                    (int)$workflowId,
                    'Renewal #' . (int)$workflowId,
                    (string)$success,
                    null,
                    ['status' => $targetStatus ?? ($workflow['status'] ?? null)],
                    (int)$userId
                );
            } catch (Throwable $e) {
                error_log('renewal_notice audit: ' . $e->getMessage());
            }
        } elseif ($action === 'save_response') {
            $tenantResponse = trim($_POST['tenant_response'] ?? '');
            $negotiationNotes = trim($_POST['negotiation_notes'] ?? '');
            $userId = current_user_id();
            $curResp = lease_renewal_view_rw_status($workflow);
            if (renewal_wf_is_completed_like($curResp)) {
                throw new Exception('Cannot save response or notes for a closed workflow.');
            }
            if (in_array($curResp, ['signed', 'contract_ready'], true)) {
                throw new Exception('Cannot change workflow status from the portal signing stage using this form.');
            }

            $updateFields = [];
            $params = [];

            if ($tenantResponse !== '') {
                $updateFields[] = "tenant_response = ?";
                $updateFields[] = "tenant_response_date = CURDATE()";
                $params[] = $tenantResponse;
            }
            if ($negotiationNotes !== '') {
                $updateFields[] = "negotiation_notes = ?";
                $params[] = $negotiationNotes;
            }

            $newStatus = renewal_wf_admin_save_response_new_status(
                $curResp,
                $tenantResponse !== '',
                $negotiationNotes !== ''
            );
            if ($newStatus !== null) {
                $updateFields[] = "status = ?";
                $params[] = $newStatus;
                $updateFields[] = "workflow_step = ?";
                $params[] = 'tenant_response';
            }

            if (!empty($updateFields)) {
                $sql = "UPDATE re_lease_renewal_workflows SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $params[] = $workflowId;
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                renewal_wf_log_admin_event(
                    $conn,
                    $workflowId,
                    $currentCompanyId,
                    $userId,
                    'admin_save_response',
                    'Admin updated tenant response / negotiation notes.',
                    json_encode(['new_status' => $newStatus, 'had_tenant_response' => $tenantResponse !== '', 'had_negotiation_notes' => $negotiationNotes !== ''], JSON_UNESCAPED_SLASHES) ?: null
                );
                $success = "Response/notes saved.";
            } else {
                $success = 'No changes to save.';
            }
        } elseif ($action === 'recreate_draft_lease') {
            $curStatus = lease_renewal_view_rw_status($workflow);
            if (!renewal_wf_can_save_renewal_amounts($curStatus)) {
                throw new Exception('Cannot recreate draft lease in the current workflow state.');
            }

            $existingNewLeaseId = (int)($workflow['new_lease_id'] ?? 0);
            $skipRecreate = false;
            if ($existingNewLeaseId > 0) {
                $existingStmt = $conn->prepare("SELECT id, status FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
                $existingStmt->execute([$existingNewLeaseId, $currentCompanyId]);
                $existingLease = $existingStmt->fetch(PDO::FETCH_ASSOC);
                if ($existingLease && ($existingLease['status'] ?? '') === 'draft') {
                    $success = 'A draft lease is already linked to this workflow.';
                    $skipRecreate = true;
                } elseif ($existingLease && ($existingLease['status'] ?? '') !== 'draft') {
                    throw new Exception('The linked renewal lease still exists and is not draft. Cannot recreate another draft lease.');
                }
            }

            if (!$skipRecreate) {
                $conn->beginTransaction();
                try {
                    $newLeaseId = lease_renewal_view_create_draft_lease($conn, $workflow, $currentCompanyId, current_user_id());
                    $conn->prepare("UPDATE re_lease_renewal_workflows SET new_lease_id = ? WHERE id = ? AND lease_id = ?")
                        ->execute([$newLeaseId, $workflowId, (int)$workflow['lease_id']]);
                    renewal_wf_log_admin_event($conn, $workflowId, $currentCompanyId, current_user_id(), 'admin_recreate_draft_lease', 'Draft renewal lease recreated after the previous draft was missing/deleted.', json_encode(['new_lease_id' => $newLeaseId], JSON_UNESCAPED_SLASHES) ?: null);
                    $conn->commit();
                    $workflow['new_lease_id'] = $newLeaseId;
                    $success = "Draft lease recreated successfully. You can now save renewal amounts again.";
                } catch (Throwable $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    throw $e;
                }
            }
        } elseif ($action === 'save_renewal_amounts') {
            $nlid = (int)($workflow['new_lease_id'] ?? 0);
            if (!renewal_wf_can_save_renewal_amounts(lease_renewal_view_rw_status($workflow))) {
                throw new Exception('Cannot edit amounts in the current workflow state.');
            }

            $dl = null;
            $draftMissing = false;
            if ($nlid > 0) {
                $st = $conn->prepare("SELECT id, status FROM re_leases WHERE id = ? AND company_id = ?");
                $st->execute([$nlid, $currentCompanyId]);
                $dl = $st->fetch(PDO::FETCH_ASSOC);
                if (!$dl) {
                    $draftMissing = true;
                } elseif (($dl['status'] ?? '') !== 'draft') {
                    throw new Exception('Draft lease not found or already activated.');
                }
            }

            $showChiller = ((int)($workflow['show_chiller_row'] ?? 0) === 1)
                || ((int)($workflow['effective_show_chiller_row'] ?? 0) === 1);
            $currentRentInput = round(max(0, (float)($_POST['current_rent'] ?? ($workflow['current_rent'] ?? 0))), 2);
            $increaseTypeInput = (string)($_POST['increase_type'] ?? ($workflow['increase_type'] ?? 'percentage'));
            if (!in_array($increaseTypeInput, ['percentage', 'fixed'], true)) {
                $increaseTypeInput = 'percentage';
            }
            $increaseValueInput = round((float)($_POST['increase_value'] ?? ($workflow['increase_value'] ?? 0)), 2);
            $calculatedNewRent = $increaseTypeInput === 'percentage'
                ? round($currentRentInput + ($currentRentInput * ($increaseValueInput / 100)), 2)
                : round($currentRentInput + $increaseValueInput, 2);
            $assignedToInput = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $proposedRent = round(max(0, (float)($_POST['proposed_rent'] ?? 0)), 2);
            $chiller = $showChiller ? round(max(0, (float)($_POST['chiller_charges'] ?? 0)), 2) : 0.0;
            $admin = round(max(0, (float)($_POST['admin_fees'] ?? 0)), 2);
            $nc = max(1, (int)($_POST['number_of_cheques'] ?? 4));
            $pStart = trim((string)($_POST['proposed_start_date'] ?? ''));
            $pEnd = trim((string)($_POST['proposed_end_date'] ?? ''));
            if ($pStart === '' || $pEnd === '') {
                throw new Exception('Proposed start and end dates are required.');
            }
            $pStartDt = date('Y-m-d', strtotime($pStart));
            $pEndDt = date('Y-m-d', strtotime($pEnd));
            if (!$pStartDt || !$pEndDt || $pStartDt === '1970-01-01' || $pEndDt === '1970-01-01') {
                throw new Exception('Invalid proposed date(s).');
            }

            $parkEn = !empty($_POST['additional_parking_enabled']) ? 1 : 0;
            $parkFee = $parkEn ? round(max(0, (float)($_POST['additional_parking_fee'] ?? 0)), 2) : 0.0;
            $smallEn = !empty($_POST['small_store_enabled']) ? 1 : 0;
            $smallFee = $smallEn ? round(max(0, (float)($_POST['small_store_fee'] ?? 0)), 2) : 0.0;
            $bigEn = !empty($_POST['big_store_enabled']) ? 1 : 0;
            $bigFee = $bigEn ? round(max(0, (float)($_POST['big_store_fee'] ?? 0)), 2) : 0.0;

            $vatEnabled = (int)($workflow['vat_enabled'] ?? 1) === 1;
            $vatRate = (float)($workflow['vat_rate'] ?? 5.0);
            if ($vatRate <= 0) {
                $vatRate = 5.0;
            }
            $vatRatePost = isset($_POST['lease_vat_rate']) ? (float)$_POST['lease_vat_rate'] : $vatRate;
            if ($vatRatePost > 0) {
                $vatRate = $vatRatePost;
            }
            $unitTypeWf = (string)($workflow['unit_type'] ?? '');
            $canVatOnRent = lease_vat_unit_is_commercial($unitTypeWf);
            $vatApplicableOnRent = $canVatOnRent && !empty($_POST['vat_applicable_on_rent']);
            $vatDist = lease_vat_normalize_distribution($_POST['vat_distribution_type'] ?? ($workflow['vat_distribution_type'] ?? 'first_installment'));
            $vatNotes = trim((string)($_POST['vat_notes'] ?? ''));

            $vatBase = $admin + $chiller + $parkFee + $smallFee + $bigFee;
            $vatCalc = lease_vat_compute_amounts([
                'annual_rent' => $proposedRent,
                'vat_applicable_on_rent' => $vatApplicableOnRent,
                'vat_applicable_on_extra_charges' => $vatEnabled,
                'lease_vat_rate' => $vatRate,
                'extra_charges_base' => $vatBase,
            ]);
            $vatExtra = $vatCalc['extra_vat'];
            $rentVat = $vatCalc['rent_vat'];
            $totalVatLease = $vatCalc['total_vat'];

            $rera = 300.0;
            $bid = (int)($workflow['building_id'] ?? 0);
            if ($bid <= 0) {
                $bid = (int)($workflow['wf_building_id'] ?? 0);
            }
            if ($bid > 0) {
                $rs = $conn->prepare("SELECT COALESCE(rera_charges, 300) AS rera FROM re_renewal_notice_building_settings WHERE company_id = ? AND building_id = ? LIMIT 1");
                $rs->execute([$currentCompanyId, $bid]);
                $rrow = $rs->fetch(PDO::FETCH_ASSOC);
                if ($rrow && isset($rrow['rera'])) {
                    $rera = (float)$rrow['rera'];
                }
            }
            $grandTotal = round($proposedRent + $vatBase + $rentVat + $vatExtra + $rera, 2);
            $monthly = $nc > 0 ? round($proposedRent / $nc, 2) : $proposedRent;

            $hasStore = ($smallEn || $bigEn) ? 1 : 0;
            $storeFeeTotal = ($smallEn ? $smallFee : 0.0) + ($bigEn ? $bigFee : 0.0);

            $conn->beginTransaction();
            try {
                if ($dl && $nlid > 0) {
                    $conn->prepare("DELETE FROM re_lease_installments WHERE lease_id = ? AND company_id = ?")->execute([$nlid, $currentCompanyId]);
                }

                $persistShowChillerRow = $showChiller ? 1 : 0;
                $persistShowChillerTerm = $showChiller
                    ? ((((int)($workflow['show_chiller_term'] ?? 0) === 1) || ((int)($workflow['effective_show_chiller_term'] ?? 0) === 1)) ? 1 : 0)
                    : (int)($workflow['show_chiller_term'] ?? 0);

                $conn->prepare("
                    UPDATE re_lease_renewal_workflows
                    SET current_rent = ?,
                        increase_type = ?,
                        increase_value = ?,
                        calculated_new_rent = ?,
                        proposed_rent = ?,
                        chiller_charges = ?,
                        admin_fees = ?,
                        vat_extra_charges = ?,
                        vat_rate = ?,
                        rent_vat_amount = ?,
                        vat_applicable_on_rent = ?,
                        vat_distribution_type = ?,
                        vat_notes = ?,
                        number_of_cheques = ?,
                        proposed_start_date = ?,
                        proposed_end_date = ?,
                        additional_parking_enabled = ?,
                        additional_parking_fee = ?,
                        small_store_enabled = ?,
                        small_store_fee = ?,
                        big_store_enabled = ?,
                        big_store_fee = ?,
                        grand_total_amount = ?,
                        show_chiller_row = ?,
                        show_chiller_term = ?,
                        assigned_to = ?
                    WHERE id = ?
                ")->execute([
                    $currentRentInput,
                    $increaseTypeInput,
                    $increaseValueInput,
                    $calculatedNewRent,
                    $proposedRent,
                    $chiller,
                    $admin,
                    $vatExtra,
                    $vatRate,
                    $rentVat,
                    $vatApplicableOnRent ? 1 : 0,
                    $vatDist,
                    $vatNotes !== '' ? $vatNotes : null,
                    $nc,
                    $pStartDt,
                    $pEndDt,
                    $parkEn,
                    $parkFee,
                    $smallEn,
                    $smallFee,
                    $bigEn,
                    $bigFee,
                    $grandTotal,
                    $persistShowChillerRow,
                    $persistShowChillerTerm,
                    $assignedToInput,
                    $workflowId,
                ]);

                if (!$dl || $nlid <= 0 || $draftMissing) {
                    $draftWorkflow = $workflow;
                    $draftWorkflow['current_rent'] = $currentRentInput;
                    $draftWorkflow['increase_type'] = $increaseTypeInput;
                    $draftWorkflow['increase_value'] = $increaseValueInput;
                    $draftWorkflow['calculated_new_rent'] = $calculatedNewRent;
                    $draftWorkflow['proposed_rent'] = $proposedRent;
                    $draftWorkflow['chiller_charges'] = $chiller;
                    $draftWorkflow['admin_fees'] = $admin;
                    $draftWorkflow['vat_extra_charges'] = $vatExtra;
                    $draftWorkflow['vat_rate'] = $vatRate;
                    $draftWorkflow['wf_rent_vat_amount'] = $rentVat;
                    $draftWorkflow['wf_vat_applicable_on_rent'] = $vatApplicableOnRent ? 1 : 0;
                    $draftWorkflow['wf_vat_distribution_type'] = $vatDist;
                    $draftWorkflow['wf_vat_notes'] = $vatNotes !== '' ? $vatNotes : null;
                    $draftWorkflow['number_of_cheques'] = $nc;
                    $draftWorkflow['proposed_start_date'] = $pStartDt;
                    $draftWorkflow['proposed_end_date'] = $pEndDt;
                    $draftWorkflow['additional_parking_enabled'] = $parkEn;
                    $draftWorkflow['additional_parking_fee'] = $parkFee;
                    $draftWorkflow['small_store_enabled'] = $smallEn;
                    $draftWorkflow['small_store_fee'] = $smallFee;
                    $draftWorkflow['big_store_enabled'] = $bigEn;
                    $draftWorkflow['big_store_fee'] = $bigFee;

                    $nlid = lease_renewal_view_create_draft_lease($conn, $draftWorkflow, $currentCompanyId, current_user_id());
                    $conn->prepare("UPDATE re_lease_renewal_workflows SET new_lease_id = ? WHERE id = ? AND lease_id = ?")
                        ->execute([$nlid, $workflowId, (int)$workflow['lease_id']]);
                } else {
                    $updLease = $conn->prepare("
                        UPDATE re_leases
                        SET annual_rent = ?,
                            monthly_rent = ?,
                            number_of_installments = ?,
                            chiller_fees = ?,
                            admin_fees = ?,
                            commission_fees = 0,
                            split_commission_fees = 0,
                            has_additional_parking = ?,
                            additional_parking_fee = ?,
                            additional_parking_start_date = ?,
                            additional_parking_end_date = ?,
                            has_additional_store = ?,
                            additional_store_fee = ?,
                            additional_store_start_date = ?,
                            additional_store_end_date = ?,
                            start_date = ?,
                            end_date = ?,
                            lease_vat_rate = ?,
                            vat_applicable_on_rent = ?,
                            vat_applicable_on_extra_charges = ?,
                            rent_vat_amount = ?,
                            extra_services_vat_amount = ?,
                            total_vat_amount = ?,
                            vat_distribution_type = ?,
                            vat_notes = ?
                        WHERE id = ? AND company_id = ? AND status = 'draft'
                    ");
                    $updLease->execute([
                        $proposedRent,
                        $monthly,
                        $nc,
                        $chiller,
                        $admin,
                        $parkEn,
                        $parkEn ? $parkFee : 0.0,
                        $parkEn ? $pStartDt : null,
                        $parkEn ? $pEndDt : null,
                        $hasStore,
                        $hasStore ? $storeFeeTotal : 0.0,
                        $hasStore ? $pStartDt : null,
                        $hasStore ? $pEndDt : null,
                        $pStartDt,
                        $pEndDt,
                        $vatRate,
                        $vatApplicableOnRent ? 1 : 0,
                        $vatEnabled ? 1 : 0,
                        $rentVat,
                        $vatExtra,
                        $totalVatLease,
                        $vatDist,
                        $vatNotes !== '' ? $vatNotes : null,
                        $nlid,
                        $currentCompanyId,
                    ]);
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            $amtUserId = current_user_id();
            renewal_wf_log_admin_event($conn, $workflowId, $currentCompanyId, $amtUserId, 'admin_save_renewal_amounts', 'Renewal amounts saved and draft lease synced/created.');
            $success = 'Renewal details saved; draft lease synced/created. Approve & Convert will stage the renewal and prepare Invoice Mode obligations; activation happens on the renewal start date.';
        } elseif ($action === 'mark_contract_ready') {
            $uid = current_user_id();
            $nl = (int)($workflow['new_lease_id'] ?? 0);
            if (!$nl) {
                throw new Exception('Link a draft lease (new lease) before marking contract ready.');
            }
            $chk = $conn->prepare("SELECT status FROM re_leases WHERE id = ? AND company_id = ?");
            $chk->execute([$nl, $currentCompanyId]);
            $stLease = $chk->fetchColumn();
            if (($stLease ?: '') !== 'draft') {
                throw new Exception('The linked lease must still be in draft status.');
            }
            $stWf = lease_renewal_view_rw_status($workflow);
            if (!renewal_wf_can_mark_contract_ready($stWf)) {
                throw new Exception('Contract can only be marked ready from accepted, negotiation, approved, or pending response.');
            }
            $markStmt = $conn->prepare("
                UPDATE re_lease_renewal_workflows
                SET status = 'contract_ready',
                    contract_ready_at = NOW(),
                    contract_ready_by = ?
                WHERE id = ? AND status = ?
            ");
            $markStmt->execute([$uid, $workflowId, $stWf]);
            if ($markStmt->rowCount() === 0) {
                throw new Exception('Could not update workflow (status may have changed). Please refresh the page.');
            }
            renewal_wf_log_admin_event($conn, $workflowId, $currentCompanyId, $uid, 'admin_mark_contract_ready', 'Contract marked ready for tenant portal review and e-sign.');

            // Tenant in-app notification: renewal contract ready
            if (is_file(__DIR__ . '/../../includes/tenant_notifications.php')) require_once __DIR__ . '/../../includes/tenant_notifications.php';
            if (function_exists('tenant_notification_create')) tenant_notification_create($conn, [
                'company_id' => $currentCompanyId,
                'lease_id' => (int)($workflow['lease_id'] ?? 0),
                'type' => 'renewal_status',
                'entity_type' => 'renewal',
                'entity_id' => (int)$workflowId,
                'title' => 'Renewal contract ready',
                'body' => 'Your renewal contract is ready to review and sign.',
            ]);

            $success = 'Tenant portal: contract is now ready for tenant review & electronic signature.';
        } elseif ($action === 'review_tenant_upload') {
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $notes = trim((string)($_POST['review_notes'] ?? ''));
            $uid = current_user_id();
            $conn->prepare("
                UPDATE re_renewal_tenant_uploads u
                INNER JOIN re_lease_renewal_workflows rw ON rw.id = u.workflow_id
                INNER JOIN re_leases l ON l.id = rw.lease_id AND l.company_id = ?
                SET u.reviewed_by = ?, u.reviewed_at = NOW(), u.review_notes = ?
                WHERE u.id = ? AND u.workflow_id = ?
            ")->execute([$currentCompanyId, $uid, $notes !== '' ? $notes : null, $uploadId, $workflowId]);
            renewal_wf_log_admin_event($conn, $workflowId, $currentCompanyId, $uid, 'admin_review_tenant_upload', 'Admin marked tenant renewal upload as reviewed.', json_encode(['upload_id' => $uploadId], JSON_UNESCAPED_SLASHES) ?: null);
            $success = 'Tenant upload marked as reviewed.';
        } elseif ($action === 'reject') {
            $userId = current_user_id();
            $rj = lease_renewal_view_rw_status($workflow);
            if (!renewal_wf_can_admin_reject($rj)) {
                throw new Exception('This workflow is already closed.');
            }
            $stmt = $conn->prepare("
                UPDATE re_lease_renewal_workflows
                SET status = 'rejected',
                    rejected_by = ?,
                    workflow_step = 'cancelled'
                WHERE id = ? AND status = ?
            ");
            $stmt->execute([$userId, $workflowId, $rj]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('Could not reject workflow (status may have changed).');
            }
            renewal_wf_log_admin_event($conn, $workflowId, $currentCompanyId, $userId, 'admin_reject', 'Renewal workflow rejected by admin.');

            // Tenant in-app notification: renewal rejected/closed
            if (is_file(__DIR__ . '/../../includes/tenant_notifications.php')) require_once __DIR__ . '/../../includes/tenant_notifications.php';
            if (function_exists('tenant_notification_create')) tenant_notification_create($conn, [
                'company_id' => $currentCompanyId,
                'lease_id' => (int)($workflow['lease_id'] ?? 0),
                'type' => 'renewal_status',
                'entity_type' => 'renewal',
                'entity_id' => (int)$workflowId,
                'title' => 'Renewal closed',
                'body' => 'Your lease renewal has been closed by management.',
            ]);
            $success = "Renewal rejected.";
            try {
                require_once __DIR__ . '/../../includes/audit_bridge.php';
                audit_bridge_re_ops(
                    $conn,
                    (int)$currentCompanyId,
                    'reject',
                    're_lease_renewal_workflows',
                    (int)$workflowId,
                    'Renewal #' . (int)$workflowId,
                    'Rejected renewal workflow #' . (int)$workflowId,
                    null,
                    ['status' => 'rejected'],
                    (int)$userId
                );
            } catch (Throwable $e) {
                error_log('renewal_reject audit: ' . $e->getMessage());
            }
        } elseif ($action === 'post_negotiation_reply') {
            $body = trim((string)($_POST['negotiation_reply'] ?? ''));
            if (strlen($body) < 2) {
                throw new Exception('Please enter a message (at least 2 characters).');
            }
            if (strlen($body) > 8000) {
                throw new Exception('Message is too long (max 8000 characters).');
            }
            $stCur = lease_renewal_view_rw_status($workflow);
            if (!renewal_wf_can_admin_post_negotiation_reply($stCur)) {
                throw new Exception('You cannot post a negotiation reply in the current workflow state.');
            }
            $uid = current_user_id();
            $cntAdm = $conn->prepare("SELECT COUNT(*) FROM re_renewal_negotiation_messages WHERE workflow_id = ? AND author_role = 'admin'");
            $cntAdm->execute([$workflowId]);
            $adminMsgsBefore = (int)$cntAdm->fetchColumn();
            $prev = trim((string)($workflow['negotiation_notes'] ?? ''));
            if ($adminMsgsBefore === 0 && $prev !== '' && $prev !== $body) {
                renewal_negotiation_insert_admin_message(
                    $conn,
                    $workflowId,
                    $currentCompanyId,
                    $prev,
                    $uid,
                    (string)($_SERVER['REMOTE_ADDR'] ?? '')
                );
            }
            renewal_negotiation_insert_admin_message(
                $conn,
                $workflowId,
                $currentCompanyId,
                $body,
                $uid,
                (string)($_SERVER['REMOTE_ADDR'] ?? '')
            );
            $stamp = date('Y-m-d H:i');
            $combined = ($prev !== '' ? $prev . "\n\n---\n" : '') . '[' . $stamp . ', staff] ' . $body;
            $conn->prepare("UPDATE re_lease_renewal_workflows SET negotiation_notes = ? WHERE id = ?")->execute([$combined, $workflowId]);
            if ($stCur === 'pending_response') {
                $conn->prepare("UPDATE re_lease_renewal_workflows SET status = 'negotiation' WHERE id = ? AND status = 'pending_response'")
                    ->execute([$workflowId]);
            }
            $logMsg = function_exists('mb_substr') ? mb_substr($body, 0, 500, 'UTF-8') : substr($body, 0, 500);
            renewal_wf_log_admin_event($conn, $workflowId, $currentCompanyId, $uid, 'admin_negotiation_reply', $logMsg);

            // Tenant in-app notification: management replied in renewal negotiation
            if (is_file(__DIR__ . '/../../includes/tenant_notifications.php')) require_once __DIR__ . '/../../includes/tenant_notifications.php';
            if (function_exists('tenant_notification_create')) tenant_notification_create($conn, [
                'company_id' => $currentCompanyId,
                'lease_id' => (int)($workflow['lease_id'] ?? 0),
                'type' => 'renewal_status',
                'entity_type' => 'renewal',
                'entity_id' => (int)$workflowId,
                'title' => 'New renewal message',
                'body' => 'Management replied to your renewal discussion.',
            ]);

            $success = 'Reply posted. The tenant will see it in Tenant Portal → Renewal → Discussion with management.';
        } elseif ($action === 'approve_convert') {
            $userId = current_user_id();
            $apSt = lease_renewal_view_rw_status($workflow);
            $isAdminOverride = !renewal_wf_can_approve_convert($apSt) && lease_renewal_view_user_can_admin_override($conn);
            if (!renewal_wf_can_approve_convert($apSt) && !$isAdminOverride) {
                throw new Exception('Approve & Convert is only allowed after tenant acceptance/signature, unless the current user is Owner/Admin.');
            }

            // Convert workflow: prepare the renewal lease now, activate it only when its start date arrives.
            $stmt = $conn->prepare("SELECT lease_id, new_lease_id FROM re_lease_renewal_workflows WHERE id = ? AND (new_lease_id IS NOT NULL)");
            $stmt->execute([$workflowId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception("New lease is not linked. Cannot convert.");
            }
            $oldLeaseId = (int)$row['lease_id'];
            $newLeaseId = (int)$row['new_lease_id'];

            $leaseCheck = $conn->prepare("SELECT id, status, start_date, accounting_mode FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
            $leaseCheck->execute([$newLeaseId, $currentCompanyId]);
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
                        ->execute([$newLeaseId, $currentCompanyId]);
                } else {
                    $conn->prepare("UPDATE re_leases SET commission_fees = 0, split_commission_fees = 0 WHERE id = ? AND company_id = ?")
                        ->execute([$newLeaseId, $currentCompanyId]);
                }

                // Operational schedule/cheques can be prepared in advance, but the lease remains draft until start date.
                generateLeaseInstallmentsForConversion($conn, $currentCompanyId, $newLeaseId, $userId);

                // Invoice Mode: prepare obligations and approved invoice candidates from the staged lease.
                if (function_exists('re_obligation_engine_generate_for_lease')) {
                    $obRes = re_obligation_engine_generate_for_lease($conn, $currentCompanyId, $newLeaseId, $userId);
                    if (empty($obRes['success'])) {
                        throw new Exception($obRes['error'] ?? 'Could not prepare renewal lease obligations.');
                    }
                }
                if (function_exists('re_invoice_engine_prepare_candidates_for_lease')) {
                    $candRes = re_invoice_engine_prepare_candidates_for_lease($conn, $currentCompanyId, $newLeaseId, $userId);
                    if (empty($candRes['success'])) {
                        throw new Exception($candRes['error'] ?? 'Could not prepare renewal invoice candidates.');
                    }
                }

                if ($activateNow) {
                    $conn->prepare("UPDATE re_leases SET status = 'active', updated_at = NOW() WHERE id = ? AND company_id = ?")
                        ->execute([$newLeaseId, $currentCompanyId]);
                    $conn->prepare("UPDATE re_leases SET status = 'expired', move_out_date = COALESCE(move_out_date, end_date), updated_at = NOW() WHERE id = ? AND company_id = ? AND status = 'active'")
                        ->execute([$oldLeaseId, $currentCompanyId]);
                } else {
                    $conn->prepare("UPDATE re_leases SET status = 'draft', updated_at = NOW() WHERE id = ? AND company_id = ?")
                        ->execute([$newLeaseId, $currentCompanyId]);
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
                re_accounting_log_mode_change($conn, $currentCompanyId, $newLeaseId, (string)($newLeaseRow['accounting_mode'] ?? 'legacy'), 'invoice', $userId, 'Renewal staged in Invoice Mode until start date.', 'renewal_workflow_convert');
            }
            renewal_wf_log_admin_event(
                $conn,
                $workflowId,
                $currentCompanyId,
                $userId,
                $isAdminOverride ? 'owner_admin_override_convert' : 'admin_approve_convert',
                $isAdminOverride
                    ? ($activateNow ? 'Owner/Admin override: renewal activated immediately.' : 'Owner/Admin override: renewal staged until start date.')
                    : ($activateNow ? 'Renewal approved; start date reached, new lease activated and old lease expired.' : 'Renewal approved; new lease staged until start date and old lease remains active.')
            );

            // Tenant in-app notification: renewal completed/converted
            if (is_file(__DIR__ . '/../../includes/tenant_notifications.php')) require_once __DIR__ . '/../../includes/tenant_notifications.php';
            if (function_exists('tenant_notification_create')) tenant_notification_create($conn, [
                'company_id' => $currentCompanyId,
                'lease_id' => $newLeaseId > 0 ? $newLeaseId : $oldLeaseId,
                'type' => 'renewal_status',
                'entity_type' => 'renewal',
                'entity_id' => (int)$workflowId,
                'title' => $activateNow ? 'Lease renewed' : 'Lease renewal approved',
                'body' => $activateNow ? 'Your lease renewal is complete. Your new lease is now active.' : 'Your lease renewal is approved. The new lease will become active on its start date.',
            ]);

            $success = $isAdminOverride
                ? ($activateNow ? "Renewal converted by Owner/Admin override (new ACTIVE, old EXPIRED)." : "Renewal approved by Owner/Admin override and staged. The old lease remains active until {$newStartDate}.")
                : ($activateNow ? "Renewal approved and leases converted (new ACTIVE, old EXPIRED)." : "Renewal approved and staged. The old lease remains active until the new lease start date ({$newStartDate}).");

            try {
                require_once __DIR__ . '/../../includes/audit_bridge.php';
                audit_bridge_re_ops(
                    $conn,
                    (int)$currentCompanyId,
                    'renewal_converted',
                    're_lease_renewal_workflows',
                    (int)$workflowId,
                    'Renewal #' . (int)$workflowId,
                    (string)$success,
                    null,
                    [
                        'old_lease_id' => (int)$oldLeaseId,
                        'new_lease_id' => (int)$newLeaseId,
                        'activate_now' => (bool)$activateNow,
                        'admin_override' => (bool)$isAdminOverride,
                    ],
                    (int)$userId
                );
            } catch (Throwable $e) {
                error_log('renewal_convert audit: ' . $e->getMessage());
            }
        }

        // Refresh workflow data after any action
        $stmt = $conn->prepare("
            SELECT rw.*, 
                   l.*, l.id as lease_id,
                   rw.vat_applicable_on_rent AS wf_vat_applicable_on_rent,
                   rw.rent_vat_amount AS wf_rent_vat_amount,
                   rw.vat_distribution_type AS wf_vat_distribution_type,
                   rw.vat_notes AS wf_vat_notes,
                   rw.created_by AS wf_created_by,
                   rw.assigned_to AS wf_assigned_to,
                   rw.current_rent AS wf_current_rent,
                   rw.proposed_rent AS wf_proposed_rent,
                   rw.proposed_start_date AS wf_proposed_start_date,
                   rw.proposed_end_date AS wf_proposed_end_date,
                   u.unit_number, u.unit_type,
                   b.id AS wf_building_id,
                   b.name as building_name,
                   t.first_name, t.last_name, t.email, t.phone,
                   COALESCE(NULLIF(u2.fullname, ''), u2.username) as assigned_to_name,
                   COALESCE(NULLIF(u_creator.fullname, ''), u_creator.username) AS created_by_name,
                   COALESCE(NULLIF(u_notice.fullname, ''), u_notice.username) AS notice_sent_by_name,
                   l.created_by AS lease_created_by,
                   COALESCE(NULLIF(u_lease_creator.fullname, ''), u_lease_creator.username) AS lease_created_by_name,
                   c.name AS company_name,
                   rw.chiller_charges AS wf_chiller_display,
                   rw.admin_fees AS wf_admin_display,
                   rw.vat_extra_charges AS wf_vat_display,
                   rw.additional_parking_enabled AS wf_additional_parking_enabled,
                   rw.additional_parking_fee AS wf_additional_parking_fee,
                   rw.small_store_enabled AS wf_small_store_enabled,
                   rw.small_store_fee AS wf_small_store_fee,
                   rw.big_store_enabled AS wf_big_store_enabled,
                   rw.big_store_fee AS wf_big_store_fee,
           COALESCE(rbs.show_chiller_row, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS effective_show_chiller_row,
           COALESCE(rbs.show_chiller_term, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS effective_show_chiller_term,
           rw.status AS renewal_workflow_status,
           l.status AS lease_status
            FROM re_lease_renewal_workflows rw
            JOIN re_leases l ON l.id = rw.lease_id
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN re_tenants t ON t.id = l.tenant_id
            LEFT JOIN re_renewal_notice_building_settings rbs ON rbs.company_id = l.company_id AND rbs.building_id = u.building_id
            LEFT JOIN user u2 ON u2.id = rw.assigned_to
            LEFT JOIN user u_creator ON u_creator.id = rw.created_by
            LEFT JOIN user u_notice ON u_notice.id = rw.notice_sent_by
            LEFT JOIN user u_lease_creator ON u_lease_creator.id = l.created_by
            LEFT JOIN companies c ON c.id = l.company_id
            WHERE rw.id = ? AND l.company_id = ?
        ");
        $stmt->execute([$workflowId, $currentCompanyId]);
        $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($workflow) {
            $workflow = lease_renewal_view_normalize_workflow($workflow);
        }

        // Refresh email history after any action
        $emailHistoryStmt = $conn->prepare("
            SELECT el.*
            FROM re_email_logs el
            WHERE el.related_type = 'lease_renewal_workflow'
              AND el.related_id = ?
              AND el.notification_type = 'lease_renewal_notice'
            ORDER BY el.id DESC
            LIMIT 10
        ");
        $emailHistoryStmt->execute([$workflowId]);
        $emailHistory = $emailHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get available leases for linking new lease
$availableLeases = $conn->prepare("
    SELECT l.id, l.lease_number, u.unit_number, b.name as building_name
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE l.company_id = ? AND l.tenant_id = ? AND l.id != ?
    ORDER BY l.start_date DESC
");
$availableLeases->execute([$currentCompanyId, $workflow['tenant_id'], $workflow['lease_id']]);
$availableLeases = $availableLeases->fetchAll(PDO::FETCH_ASSOC);

$assignableUsersStmt = $conn->prepare("
    SELECT DISTINCT u.id, u.username, u.fullname, u.email
    FROM user u
    JOIN user_companies uc ON uc.user_id = u.id
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE uc.company_id = ?
      AND (r.module = 'realestate' OR r.name IN ('Owner', 'Admin'))
    ORDER BY COALESCE(NULLIF(u.fullname, ''), u.username)
");
$assignableUsersStmt->execute([$currentCompanyId]);
$assignableUsers = $assignableUsersStmt->fetchAll(PDO::FETCH_ASSOC);

$portalUploads = [];
$portalEvents = [];
$portalSignature = null;
$negotiationThreadDisplay = [];
$negotiationAdminNames = [];
try {
    $pu = $conn->prepare("SELECT * FROM re_renewal_tenant_uploads WHERE workflow_id = ? ORDER BY uploaded_at DESC");
    $pu->execute([$workflowId]);
    $portalUploads = $pu->fetchAll(PDO::FETCH_ASSOC);
    $pe = $conn->prepare("SELECT * FROM re_renewal_portal_events WHERE workflow_id = ? ORDER BY created_at DESC LIMIT 150");
    $pe->execute([$workflowId]);
    $portalEvents = $pe->fetchAll(PDO::FETCH_ASSOC);
    $ps = $conn->prepare("SELECT * FROM re_renewal_electronic_signatures WHERE workflow_id = ? LIMIT 1");
    $ps->execute([$workflowId]);
    $portalSignature = $ps->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    // Migration tenant_portal_renewal_phase1 not applied
}
try {
    $negotiationThreadDisplay = renewal_negotiation_thread_for_display($conn, $workflowId, $workflow);
    $adminIds = [];
    foreach ($negotiationThreadDisplay as $it) {
        if (($it['kind'] ?? '') === 'msg' && (($it['row']['author_role'] ?? '') === 'admin') && !empty($it['row']['admin_user_id'])) {
            $adminIds[(int)$it['row']['admin_user_id']] = true;
        }
    }
    if ($adminIds) {
        $ids = array_keys($adminIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $ust = $conn->prepare("SELECT id, username, fullname FROM user WHERE id IN ($ph)");
        $ust->execute($ids);
        while ($ur = $ust->fetch(PDO::FETCH_ASSOC)) {
            $negotiationAdminNames[(int)$ur['id']] = trim((string)($ur['fullname'] ?: $ur['username']));
        }
    }
} catch (Throwable $e) {
    $negotiationThreadDisplay = [];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$wfStatusVal = lease_renewal_view_rw_status($workflow);
$linkedRenewalLease = null;
$linkedRenewalLeaseMissing = false;
if (!empty($workflow['new_lease_id'])) {
    $linkedLeaseStmt = $conn->prepare("SELECT id, lease_number, status, start_date, accounting_mode FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
    $linkedLeaseStmt->execute([(int)$workflow['new_lease_id'], $currentCompanyId]);
    $linkedRenewalLease = $linkedLeaseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $linkedRenewalLeaseMissing = !$linkedRenewalLease;
}
$canChangeRenewalAmounts = renewal_wf_can_save_renewal_amounts($wfStatusVal);
$canEditRenewalAmounts = $canChangeRenewalAmounts
    && $linkedRenewalLease
    && (($linkedRenewalLease['status'] ?? '') === 'draft');
$canRecreateDraftLease = $canChangeRenewalAmounts
    && (!$linkedRenewalLease)
    && (empty($workflow['new_lease_id']) || $linkedRenewalLeaseMissing);
$canShowRenewalAmountsForm = $canChangeRenewalAmounts
    && (!$linkedRenewalLease || (($linkedRenewalLease['status'] ?? '') === 'draft'));
$showChillerRowRenewal = ((int)($workflow['show_chiller_row'] ?? 0) === 1)
    || ((int)($workflow['effective_show_chiller_row'] ?? 0) === 1);
$commercialRenewalUnit = lease_vat_unit_is_commercial((string)($workflow['unit_type'] ?? ''));

$renewalPreviewRera = 300.0;
$renewalPreviewBid = (int)($workflow['building_id'] ?? 0);
if ($renewalPreviewBid <= 0) {
    $renewalPreviewBid = (int)($workflow['wf_building_id'] ?? 0);
}
if ($renewalPreviewBid > 0) {
    try {
        $reraSt = $conn->prepare("SELECT COALESCE(rera_charges, 300) AS rera FROM re_renewal_notice_building_settings WHERE company_id = ? AND building_id = ? LIMIT 1");
        $reraSt->execute([$currentCompanyId, $renewalPreviewBid]);
        $reraRow = $reraSt->fetch(PDO::FETCH_ASSOC);
        if ($reraRow && isset($reraRow['rera'])) {
            $renewalPreviewRera = (float)$reraRow['rera'];
        }
    } catch (Throwable $e) {
        $renewalPreviewRera = 300.0;
    }
}

$canMarkContractReady = !empty($workflow['new_lease_id'])
    && renewal_wf_can_mark_contract_ready($wfStatusVal);

$showNegotiationThreadCard = count($negotiationThreadDisplay) > 0
    || renewal_wf_can_admin_post_negotiation_reply($wfStatusVal)
    || (trim((string)($workflow['tenant_portal_decision'] ?? '')) === 'negotiate');
$canPostNegotiationReply = renewal_wf_can_admin_post_negotiation_reply($wfStatusVal);

// Set page title and include layout
$pageTitle = 'Renewal Workflow Details';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">
                Renewal Workflow: <?= h($workflow['lease_number'] ?: 'L-' . $workflow['lease_id']) ?>
            </div>
            <a href="lease_view.php?id=<?= $workflow['lease_id'] ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Lease
            </a>
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

        <div class="row">
            <!-- Workflow Information -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Workflow Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Current Status:</th>
                                <td>
                                    <?php
                                    $__rwSt = lease_renewal_view_rw_status($workflow);
                                    $wfStatus = $__rwSt !== '' ? $__rwSt : ($workflow['workflow_step'] ?? 'initiated');
                                    $stepLabels = [
                                        'initiated' => 'Initiated',
                                        'notice_sent' => 'Notice Sent',
                                        'viewed_by_tenant' => 'Viewed by tenant',
                                        'acknowledged' => 'Acknowledged',
                                        'pending_response' => 'Pending Response',
                                        'negotiation' => 'Negotiation',
                                        'accepted' => 'Accepted (tenant)',
                                        'approved' => 'Approved',
                                        'rejected' => 'Rejected',
                                        'contract_ready' => 'Contract ready (portal)',
                                        'signed' => 'Signed (e-sign)',
                                        'completed' => 'Completed',
                                        'converted' => 'Converted',

                                        // Backward compatibility (old workflow_step values)
                                        'terms_reviewed' => 'Terms Reviewed',
                                        'tenant_notified' => 'Tenant Notified',
                                        'tenant_response' => 'Tenant Responded',
                                        'terms_negotiated' => 'Terms Negotiated',
                                        'renewal_approved' => 'Renewal Approved',
                                        'new_lease_created' => 'New Lease Created',
                                        'completed' => 'Completed',
                                        'cancelled' => 'Cancelled'
                                    ];
                                    $stepColors = [
                                        'initiated' => 'info',
                                        'notice_sent' => 'warning',
                                        'viewed_by_tenant' => 'secondary',
                                        'acknowledged' => 'info',
                                        'pending_response' => 'primary',
                                        'negotiation' => 'info',
                                        'accepted' => 'success',
                                        'approved' => 'success',
                                        'rejected' => 'danger',
                                        'contract_ready' => 'primary',
                                        'signed' => 'success',
                                        'completed' => 'success',
                                        'converted' => 'success',

                                        // Backward compatibility
                                        'terms_reviewed' => 'primary',
                                        'tenant_notified' => 'warning',
                                        'tenant_response' => 'success',
                                        'terms_negotiated' => 'info',
                                        'renewal_approved' => 'success',
                                        'new_lease_created' => 'success',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                    $color = $stepColors[$wfStatus] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $color ?>"><?= h($stepLabels[$wfStatus] ?? ucfirst((string)$wfStatus)) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th>Lease:</th>
                                <td>
                                    <a href="lease_view.php?id=<?= $workflow['lease_id'] ?>">
                                        <?= h($workflow['lease_number'] ?: 'L-' . $workflow['lease_id']) ?>
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <th>Unit:</th>
                                <td><?= h($workflow['building_name'] . ' - ' . $workflow['unit_number']) ?></td>
                            </tr>
                            <tr>
                                <th>Tenant:</th>
                                <td><?= h($workflow['first_name'] . ' ' . $workflow['last_name']) ?></td>
                            </tr>
                            <tr>
                                <th>Lease End Date:</th>
                                <td><?= date('Y-m-d', strtotime($workflow['end_date'])) ?></td>
                            </tr>
                            <tr>
                                <th>Initiated Date:</th>
                                <td><?= date('Y-m-d', strtotime($workflow['initiated_date'])) ?></td>
                            </tr>
                            <tr>
                                <th>Initiated By:</th>
                                <td><?= h((string)($workflow['_created_by_display'] ?? '-')) ?></td>
                            </tr>
                            <?php if (!empty($workflow['notice_sent_by_name'])): ?>
                            <tr>
                                <th>Notice Sent By:</th>
                                <td><?= h((string)$workflow['notice_sent_by_name']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($workflow['notice_sent_at'])): ?>
                            <tr>
                                <th>Notice Sent At:</th>
                                <td><?= date('Y-m-d', strtotime($workflow['notice_sent_at'])) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Assigned To:</th>
                                <td><?= h($workflow['assigned_to_name'] ?? (!empty($workflow['assigned_to']) ? ('User #' . (int)$workflow['assigned_to']) : '-')) ?></td>
                            </tr>
                            <?php if ($workflow['target_renewal_date']): ?>
                            <tr>
                                <th>Target Renewal Date:</th>
                                <td><?= date('Y-m-d', strtotime($workflow['target_renewal_date'])) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Proposed Terms -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Proposed Renewal Terms</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <?php if (!empty($workflow['current_rent'])): ?>
                            <tr>
                                <th width="40%">Current Rent:</th>
                                <td><?= number_format((float)$workflow['current_rent'], 2) ?> AED / year</td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($workflow['proposed_rent']): ?>
                            <tr>
                                <th width="40%">Proposed Rent:</th>
                                <td><?= number_format($workflow['proposed_rent'], 2) ?> AED</td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($workflow['calculated_new_rent'])): ?>
                            <tr>
                                <th>Calculated New Rent:</th>
                                <td><?= number_format((float)$workflow['calculated_new_rent'], 2) ?> AED</td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($workflow['increase_type'])): ?>
                            <tr>
                                <th>Increase:</th>
                                <td>
                                    <?php
                                        $incType = $workflow['increase_type'];
                                        $incVal = (float)($workflow['increase_value'] ?? 0);
                                    ?>
                                    <?= h($incType) ?> :
                                    <?= number_format($incVal, 2) ?><?= $incType === 'percentage' ? '%' : ' AED' ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($workflow['proposed_start_date']): ?>
                            <tr>
                                <th>Proposed Start Date:</th>
                                <td><?= date('Y-m-d', strtotime($workflow['proposed_start_date'])) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($workflow['proposed_end_date']): ?>
                            <tr>
                                <th>Proposed End Date:</th>
                                <td><?= date('Y-m-d', strtotime($workflow['proposed_end_date'])) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Chiller Charges:</th>
                                <td><?= number_format((float)($workflow['chiller_charges'] ?? 0), 2) ?> AED</td>
                            </tr>
                            <tr>
                                <th>Admin Fees:</th>
                                <td><?= number_format((float)($workflow['admin_fees'] ?? 0), 2) ?> AED</td>
                            </tr>
                            <tr>
                                <th>VAT (Extra Charges):</th>
                                <td><?= number_format((float)($workflow['vat_extra_charges'] ?? 0), 2) ?> AED</td>
                            </tr>
                            <?php if ($commercialRenewalUnit): ?>
                            <tr>
                                <th>VAT on annual rent:</th>
                                <td><?= !empty($workflow['wf_vat_applicable_on_rent']) ? 'Yes' : 'No' ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Rent VAT (AED):</th>
                                <td><?= number_format((float)($workflow['wf_rent_vat_amount'] ?? 0), 2) ?></td>
                            </tr>
                            <tr>
                                <th>Total VAT (rent + extras):</th>
                                <td><?= number_format(round((float)($workflow['wf_rent_vat_amount'] ?? 0) + (float)($workflow['vat_extra_charges'] ?? 0), 2), 2) ?></td>
                            </tr>
                            <tr>
                                <th>VAT distribution:</th>
                                <td><?= h(str_replace('_', ' ', (string)($workflow['wf_vat_distribution_type'] ?? 'first_installment'))) ?></td>
                            </tr>
                            <?php if (trim((string)($workflow['wf_vat_notes'] ?? '')) !== ''): ?>
                            <tr>
                                <th>VAT notes:</th>
                                <td><?= nl2br(h((string)$workflow['wf_vat_notes'])) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($linkedRenewalLease): ?>
                            <tr>
                                <th>New Lease:</th>
                                <td>
                                    <?php
                                        $linkedStatus = (string)($linkedRenewalLease['status'] ?? '');
                                        $linkedPendingStart = $linkedStatus === 'draft' && $wfStatusVal === 'converted';
                                        $linkedStatusLabel = $linkedPendingStart ? 'Pending Start' : ucfirst($linkedStatus);
                                        $linkedStatusClass = $linkedPendingStart ? 'info' : ($linkedStatus === 'draft' ? 'warning text-dark' : ($linkedStatus === 'active' ? 'success' : 'secondary'));
                                    ?>
                                    <a href="lease_view.php?id=<?= (int)$linkedRenewalLease['id'] ?>" class="btn btn-sm btn-primary">
                                        View New Lease
                                    </a>
                                    <span class="badge bg-<?= h($linkedStatusClass) ?> ms-2">
                                        <?= h($linkedStatusLabel) ?>
                                    </span>
                                    <?php if (!empty($linkedRenewalLease['start_date']) && $linkedPendingStart): ?>
                                        <small class="text-muted ms-2">Activates on <?= h($linkedRenewalLease['start_date']) ?></small>
                                    <?php endif; ?>
                                    <?php if (($linkedRenewalLease['accounting_mode'] ?? '') === 'invoice'): ?>
                                        <span class="badge bg-primary ms-1">Invoice Mode</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php elseif (!empty($workflow['new_lease_id'])): ?>
                            <tr>
                                <th>New Lease:</th>
                                <td>
                                    <div class="alert alert-warning py-2 mb-2">
                                        Linked draft lease #<?= (int)$workflow['new_lease_id'] ?> is missing. It may have been deleted.
                                    </div>
                                    <?php if ($canRecreateDraftLease): ?>
                                    <form method="post" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>" class="m-0"
                                          onsubmit="return confirm('Create a new draft lease for this renewal workflow?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="recreate_draft_lease">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <i class="bi bi-arrow-clockwise"></i> Recreate Draft Lease
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php elseif ($canRecreateDraftLease): ?>
                            <tr>
                                <th>New Lease:</th>
                                <td>
                                    <form method="post" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>" class="m-0"
                                          onsubmit="return confirm('Create a new draft lease for this renewal workflow?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="recreate_draft_lease">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <i class="bi bi-plus-circle"></i> Create Draft Lease
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($workflow['renewal_notice_pdf_path'])): ?>
                            <tr>
                                <th>Renewal Notice PDF:</th>
                                <td>
                                    <a href="<?= h($appBase . '/' . ltrim((string)$workflow['renewal_notice_pdf_path'], '/')) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-file-earmark-pdf"></i> View Generated PDF
                                    </a>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($canChangeRenewalAmounts): ?>
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card border-primary">
                    <div class="card-header">
                        <h5 class="mb-0">Edit renewal amounts (draft lease)</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Use this during negotiation to align the workflow and linked <strong>draft</strong> lease.
                            <strong>Approve &amp; Convert</strong> prepares the operational schedule and Invoice Mode obligations, then keeps the renewal staged until its start date.
                            Renewals keep <strong>commission at 0</strong> (admin / renewal charges apply).
                        </p>
                        <?php if (!empty($workflow['_missing_core_terms'])): ?>
                            <div class="alert alert-info">
                                This is an older/incomplete workflow. The page is prefilled from the old lease so you can review, adjust, and save the missing renewal details.
                            </div>
                        <?php endif; ?>
                        <?php if (!$canEditRenewalAmounts): ?>
                            <?php if ($linkedRenewalLease && ($linkedRenewalLease['status'] ?? '') !== 'draft'): ?>
                                <div class="alert alert-warning mb-0">
                                    The linked renewal lease exists but its status is <strong><?= h($linkedRenewalLease['status']) ?></strong>.
                                    Amounts can only be synced while the linked lease is still draft.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    The linked draft lease is missing. Saving the details below will create/sync a new draft lease for this workflow.
                                </div>
                                <?php if ($canRecreateDraftLease): ?>
                                <form method="post" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>" class="m-0"
                                      onsubmit="return confirm('Create a new draft lease for this renewal workflow?')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="recreate_draft_lease">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="bi bi-arrow-clockwise"></i> Recreate Draft Lease
                                    </button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($canShowRenewalAmountsForm): ?>
                        <form id="renewalAmountsForm" method="POST" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="save_renewal_amounts">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Current annual rent (AED)</label>
                                    <input type="number" step="0.01" min="0" name="current_rent" class="form-control" required
                                           value="<?= h((string)($workflow['current_rent'] ?? '0')) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Increase type</label>
                                    <?php $increaseTypeForm = (string)($workflow['increase_type'] ?? 'percentage'); ?>
                                    <select name="increase_type" class="form-select">
                                        <option value="percentage" <?= $increaseTypeForm === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                                        <option value="fixed" <?= $increaseTypeForm === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Increase value</label>
                                    <input type="number" step="0.01" name="increase_value" class="form-control"
                                           value="<?= h((string)($workflow['increase_value'] ?? '0')) ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Proposed annual rent (AED)</label>
                                    <input type="number" step="0.01" min="0" name="proposed_rent" class="form-control" required
                                           value="<?= h((string)($workflow['proposed_rent'] ?? '0')) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Number of cheques</label>
                                    <input type="number" min="1" name="number_of_cheques" class="form-control" required
                                           value="<?= (int)($workflow['number_of_cheques'] ?? 4) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Admin / renewal fees (AED)</label>
                                    <input type="number" step="0.01" min="0" name="admin_fees" class="form-control" required
                                           value="<?= h((string)($workflow['admin_fees'] ?? '0')) ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Assigned To</label>
                                    <select name="assigned_to" class="form-select">
                                        <option value="">-- Unassigned --</option>
                                        <?php foreach ($assignableUsers as $assignee): ?>
                                            <?php $assigneeName = trim((string)($assignee['fullname'] ?: $assignee['username'])); ?>
                                            <option value="<?= (int)$assignee['id'] ?>" <?= ((int)($workflow['assigned_to'] ?? 0) === (int)$assignee['id']) ? 'selected' : '' ?>>
                                                <?= h($assigneeName) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php if ($showChillerRowRenewal): ?>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Chiller charges (AED)</label>
                                    <input type="number" step="0.01" min="0" name="chiller_charges" class="form-control" required
                                           value="<?= h((string)($workflow['chiller_charges'] ?? '0')) ?>">
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Proposed start date</label>
                                    <input type="date" name="proposed_start_date" class="form-control" required
                                           value="<?= h(substr((string)($workflow['proposed_start_date'] ?? ''), 0, 10)) ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Proposed end date</label>
                                    <input type="date" name="proposed_end_date" class="form-control" required
                                           value="<?= h(substr((string)($workflow['proposed_end_date'] ?? ''), 0, 10)) ?>">
                                </div>
                            </div>
                            <div class="row align-items-end">
                                <div class="col-md-4 mb-3">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="additional_parking_enabled" value="1" id="editParkEn"
                                            <?= !empty($workflow['additional_parking_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editParkEn">Additional parking</label>
                                    </div>
                                    <input type="number" step="0.01" min="0" name="additional_parking_fee" class="form-control"
                                           placeholder="Parking fee (AED)"
                                           value="<?= h((string)($workflow['additional_parking_fee'] ?? '0')) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="small_store_enabled" value="1" id="editSmallStore"
                                            <?= !empty($workflow['small_store_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editSmallStore">Small storeroom</label>
                                    </div>
                                    <input type="number" step="0.01" min="0" name="small_store_fee" class="form-control"
                                           placeholder="Small store (AED)"
                                           value="<?= h((string)($workflow['small_store_fee'] ?? '0')) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="big_store_enabled" value="1" id="editBigStore"
                                            <?= !empty($workflow['big_store_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="editBigStore">Big storeroom</label>
                                    </div>
                                    <input type="number" step="0.01" min="0" name="big_store_fee" class="form-control"
                                           placeholder="Big store (AED)"
                                           value="<?= h((string)($workflow['big_store_fee'] ?? '0')) ?>">
                                </div>
                            </div>
                            <p class="small text-muted mb-2">
                                VAT on extras (<?= h((string)($workflow['vat_rate'] ?? '5')) ?>%), optional VAT on rent for commercial units, and grand total for notices match the values calculated on save
                                <?php if (!$showChillerRowRenewal): ?> (chiller fixed at 0 for this building)<?php endif; ?>.
                            </p>
                            <div class="row">
                                <div class="col-md-4 mb-3 <?= $commercialRenewalUnit ? '' : 'd-none' ?>" id="renewalVatOnRentRow">
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox" name="vat_applicable_on_rent" value="1" id="renewalVatOnRent"
                                            <?= !empty($workflow['wf_vat_applicable_on_rent']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="renewalVatOnRent">VAT on annual rent (commercial)</label>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Lease VAT rate (%)</label>
                                    <input type="number" step="0.01" min="0" name="lease_vat_rate" id="renewalLeaseVatRate" class="form-control"
                                           value="<?= h((string)($workflow['vat_rate'] ?? '5')) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">VAT distribution</label>
                                    <?php $vd = (string)($workflow['wf_vat_distribution_type'] ?? 'first_installment'); ?>
                                    <select name="vat_distribution_type" id="renewalVatDistribution" class="form-select">
                                        <option value="first_installment" <?= $vd === 'first_installment' ? 'selected' : '' ?>>First installment / fees cheque</option>
                                        <option value="split_installments" <?= $vd === 'split_installments' ? 'selected' : '' ?>>Split across rent cheques</option>
                                        <option value="separate_payment" <?= $vd === 'separate_payment' ? 'selected' : '' ?>>Separate VAT cheque</option>
                                    </select>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">VAT notes (optional)</label>
                                    <textarea name="vat_notes" class="form-control" rows="2" placeholder="e.g. Tax invoice reference"><?= h((string)($workflow['wf_vat_notes'] ?? '')) ?></textarea>
                                </div>
                            </div>
                            <div class="alert alert-light border small mb-3" id="renewalAmountsLivePreview" role="status">
                                <div class="fw-semibold mb-1">Live preview (same formula as save)</div>
                                <div class="mb-1">VAT base (extras: admin + chiller<?= $showChillerRowRenewal ? '' : ' (0)' ?> + checked parking / storeroom): <strong id="renewalPreviewVatBase">0.00</strong> AED</div>
                                <div class="mb-1">VAT on extras (<?= h((string)($workflow['vat_rate'] ?? '5')) ?>%<?= empty($workflow['vat_enabled']) ? ', <span class="text-warning">off</span>' : '' ?>): <strong id="renewalPreviewVat">0.00</strong> AED</div>
                                <div class="mb-1">Annual rent: <strong id="renewalPreviewRent">0.00</strong> AED</div>
                                <div class="mb-1">VAT on rent: <strong id="renewalPreviewRentVat">0.00</strong> AED</div>
                                <div class="mb-1">Total VAT (rent + extras): <strong id="renewalPreviewTotalVat">0.00</strong> AED</div>
                                <div class="mb-0">Grand total (rent + extras + VAT + RERA <?= number_format($renewalPreviewRera, 2) ?>): <strong id="renewalPreviewGrand">0.00</strong> AED</div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i>
                                <?= $canEditRenewalAmounts ? 'Save amounts & sync draft lease' : 'Save details & create draft lease' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <script>
                        (function () {
                            var form = document.getElementById('renewalAmountsForm');
                            if (!form) return;
                            var vatOn = <?= !empty($workflow['vat_enabled']) ? 'true' : 'false' ?>;
                            var showChiller = <?= $showChillerRowRenewal ? 'true' : 'false' ?>;
                            var commercialUnit = <?= $commercialRenewalUnit ? 'true' : 'false' ?>;
                            var reraFixed = <?= json_encode(round($renewalPreviewRera, 2)) ?>;

                            function parseNum(el) {
                                if (!el) return 0;
                                var v = parseFloat(String(el.value || '0').replace(/,/g, ''));
                                return isFinite(v) ? v : 0;
                            }
                            function isChecked(id) {
                                var el = document.getElementById(id);
                                return el && el.checked;
                            }
                            function recalc() {
                                var rateEl = document.getElementById('renewalLeaseVatRate');
                                var vatRate = rateEl ? parseNum(rateEl) : 0;
                                if (vatRate <= 0) vatRate = 5;
                                var admin = parseNum(form.querySelector('[name="admin_fees"]'));
                                var chiller = 0;
                                if (showChiller) {
                                    chiller = parseNum(form.querySelector('[name="chiller_charges"]'));
                                }
                                var parking = isChecked('editParkEn') ? parseNum(form.querySelector('[name="additional_parking_fee"]')) : 0;
                                var small = isChecked('editSmallStore') ? parseNum(form.querySelector('[name="small_store_fee"]')) : 0;
                                var big = isChecked('editBigStore') ? parseNum(form.querySelector('[name="big_store_fee"]')) : 0;
                                var base = admin + chiller + parking + small + big;
                                var extraVat = (vatOn && vatRate > 0) ? Math.round(base * (vatRate / 100) * 100) / 100 : 0;
                                var rent = parseNum(form.querySelector('[name="proposed_rent"]'));
                                var vatRentOn = commercialUnit && isChecked('renewalVatOnRent');
                                var rentVat = (vatRentOn && vatRate > 0) ? Math.round(rent * (vatRate / 100) * 100) / 100 : 0;
                                var totalVat = Math.round((extraVat + rentVat) * 100) / 100;
                                var grand = Math.round((rent + base + totalVat + reraFixed) * 100) / 100;

                                var bEl = document.getElementById('renewalPreviewVatBase');
                                var vEl = document.getElementById('renewalPreviewVat');
                                var rEl = document.getElementById('renewalPreviewRent');
                                var rvEl = document.getElementById('renewalPreviewRentVat');
                                var tvEl = document.getElementById('renewalPreviewTotalVat');
                                var gEl = document.getElementById('renewalPreviewGrand');
                                if (bEl) bEl.textContent = base.toFixed(2);
                                if (vEl) vEl.textContent = extraVat.toFixed(2);
                                if (rEl) rEl.textContent = rent.toFixed(2);
                                if (rvEl) rvEl.textContent = rentVat.toFixed(2);
                                if (tvEl) tvEl.textContent = totalVat.toFixed(2);
                                if (gEl) gEl.textContent = grand.toFixed(2);
                            }
                            form.addEventListener('input', recalc);
                            form.addEventListener('change', recalc);
                            recalc();
                        })();
                        </script>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Rent Change History -->
        <?php if (!empty($rentHistory)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Rent Change History</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Old Rent</th>
                                <th>Increase</th>
                                <th>Calculated New Rent</th>
                                <th>Override New Rent</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rentHistory as $rh): ?>
                                <tr>
                                    <td><?= !empty($rh['changed_at']) ? date('Y-m-d', strtotime($rh['changed_at'])) : '' ?></td>
                                    <td><?= number_format((float)($rh['old_rent'] ?? 0), 2) ?> AED</td>
                                    <td>
                                        <?= h((string)($rh['increase_type'] ?? 'percentage')) ?> :
                                        <?= number_format((float)($rh['increase_value'] ?? 0), 2) ?>
                                        <?= (($rh['increase_type'] ?? '') === 'percentage') ? '%' : ' AED' ?>
                                    </td>
                                    <td><?= number_format((float)($rh['calculated_new_rent'] ?? 0), 2) ?> AED</td>
                                    <td><?= number_format((float)($rh['override_new_rent'] ?? 0), 2) ?> AED</td>
                                    <td><?= h((string)($rh['changed_by_name'] ?? $rh['changed_by'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Email History -->
        <div class="card mb-4">
            <div class="card-header">
                <h5>Email History (Renewal Notice)</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($emailHistory)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Recipient</th>
                                    <th>Sent At</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($emailHistory as $el): ?>
                                    <tr>
                                        <td><?= h((string)($el['status'] ?? '')) ?></td>
                                        <td><?= h((string)($el['recipient_email'] ?? '')) ?></td>
                                        <td><?= !empty($el['sent_at']) ? date('Y-m-d H:i', strtotime($el['sent_at'])) : '-' ?></td>
                                        <td><?= h((string)($el['error_message'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No Renewal Notice emails sent yet for this workflow.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tenant portal (renewal Phase 1) -->
        <div class="card mb-4 border-info">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="mb-0">Tenant portal</h5>
                <?php if (!empty($canMarkContractReady)): ?>
                    <form method="post" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>" class="m-0"
                          onsubmit="return confirm('Mark this renewal as ready for the tenant to review and sign in the Tenant Portal?');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="mark_contract_ready">
                        <button type="submit" class="btn btn-sm btn-primary">
                            Mark contract ready for tenant signature
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Tenants open <strong>Tenant Portal → Lease renewals</strong> for this lease. Run migration
                    <code>migrations/tenant_portal_renewal_phase1.sql</code> if sections below are empty.
                </p>
                <p class="small mb-3">
                    <strong>Deep link (email / WhatsApp):</strong><br>
                    <a href="<?= h(renewal_portal_renewal_detail_abs_url($workflowId)) ?>" target="_blank" rel="noopener"><?= h(renewal_portal_renewal_detail_abs_url($workflowId)) ?></a>
                    <span class="text-muted">(set <code>APP_BASE_URL</code> in config for correct production URL)</span>
                </p>
                <div class="row small mb-3">
                    <div class="col-md-6">
                        <strong>Portal milestones</strong>
                        <ul class="mb-0">
                            <li>First viewed: <?= !empty($workflow['tenant_first_viewed_at']) ? h(date('Y-m-d H:i', strtotime($workflow['tenant_first_viewed_at']))) : '—' ?></li>
                            <li>Acknowledged: <?= !empty($workflow['tenant_acknowledged_at']) ? h(date('Y-m-d H:i', strtotime($workflow['tenant_acknowledged_at']))) . ' (IP: ' . h((string)($workflow['tenant_acknowledged_ip'] ?? '')) . ')' : '—' ?></li>
                            <li>Portal decision: <?= !empty($workflow['tenant_portal_decision']) ? h((string)$workflow['tenant_portal_decision']) : '—' ?>
                                <?php if (!empty($workflow['tenant_portal_decision_at'])): ?>
                                    @ <?= h(date('Y-m-d H:i', strtotime($workflow['tenant_portal_decision_at']))) ?>
                                <?php endif; ?>
                            </li>
                            <li>Contract ready: <?= !empty($workflow['contract_ready_at']) ? h(date('Y-m-d H:i', strtotime($workflow['contract_ready_at']))) : '—' ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <?php if (!empty($portalSignature)): ?>
                            <strong>Electronic signature</strong>
                            <ul class="mb-0">
                                <li>Name typed: <?= h((string)$portalSignature['typed_full_name']) ?></li>
                                <li>Signed at: <?= h(date('Y-m-d H:i', strtotime((string)$portalSignature['signed_at']))) ?></li>
                                <li>IP: <?= h((string)($portalSignature['ip_address'] ?? '')) ?></li>
                            </ul>
                            <?php
                            $sigRel = trim((string)($portalSignature['signature_image_path'] ?? ''));
                            $sigDataUri = '';
                            if ($sigRel !== '') {
                                $sigAbs = dirname(__DIR__, 2) . '/' . ltrim($sigRel, '/');
                                if (is_file($sigAbs) && is_readable($sigAbs)) {
                                    $sigDataUri = 'data:image/png;base64,' . base64_encode((string)file_get_contents($sigAbs));
                                }
                            }
                            ?>
                            <?php if ($sigDataUri !== ''): ?>
                                <div class="mt-2">
                                    <div class="small text-muted mb-1">Drawn signature (attached to contract):</div>
                                    <img src="<?= $sigDataUri ?>" alt="Tenant signature"
                                         style="max-width: 240px; max-height: 90px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; padding:4px;">
                                    <div class="mt-1">
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="<?= $sigDataUri ?>"
                                           download="tenant_signature_wf<?= (int)$workflowId ?>.png">
                                            <i class="bi bi-download"></i> Download signature
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="small text-muted mt-1">Drawn signature image not available.</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">No electronic signature yet.</span>
                        <?php endif; ?>
                    </div>
                </div>

                <h6 class="mt-3">Documents uploaded by tenant</h6>
                <?php if (empty($portalUploads)): ?>
                    <p class="text-muted small">No uploads yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>File</th>
                                    <th>Uploaded</th>
                                    <th>Review</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($portalUploads as $pu): ?>
                                    <tr>
                                        <td><?= h((string)$pu['document_type']) ?></td>
                                        <td><?= h((string)$pu['original_filename']) ?></td>
                                        <td><?= h(date('Y-m-d H:i', strtotime((string)$pu['uploaded_at']))) ?></td>
                                        <td>
                                            <?php if (!empty($pu['reviewed_at'])): ?>
                                                <span class="badge bg-success">Reviewed</span>
                                                <?php if (!empty($pu['review_notes'])): ?><br><small><?= h((string)$pu['review_notes']) ?></small><?php endif; ?>
                                            <?php else: ?>
                                                <form method="post" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>" class="d-flex flex-wrap gap-1 align-items-center">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="review_tenant_upload">
                                                    <input type="hidden" name="upload_id" value="<?= (int)$pu['id'] ?>">
                                                    <input type="text" name="review_notes" class="form-control form-control-sm" style="min-width:140px;" placeholder="Notes (optional)">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">Mark reviewed</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a class="btn btn-sm btn-outline-primary" href="renewal_admin_upload_download.php?id=<?= (int)$pu['id'] ?>">Download</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <h6 class="mt-4">Portal event log</h6>
                <?php if (empty($portalEvents)): ?>
                    <p class="text-muted small mb-0">No events logged.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height:280px;overflow:auto;">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>When</th><th>Event</th><th>Detail</th><th>IP</th></tr></thead>
                            <tbody>
                                <?php foreach ($portalEvents as $ev): ?>
                                    <tr>
                                        <td><?= h(date('Y-m-d H:i', strtotime((string)$ev['created_at']))) ?></td>
                                        <td><?= h((string)$ev['event_type']) ?></td>
                                        <td><?= h((string)($ev['message'] ?? '')) ?></td>
                                        <td><?= h((string)($ev['ip_address'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($showNegotiationThreadCard): ?>
        <div class="card mb-4 border-primary">
            <div class="card-header">
                <h5 class="mb-0">Negotiation with tenant (portal)</h5>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Messages here are visible to the tenant in <strong>Tenant Portal → Renewal notice → Discussion with management</strong>.
                    Use <strong>Reply to tenant</strong> for each new message (preferred over editing “Negotiation notes” alone).
                </p>
                <?php if (empty($negotiationThreadDisplay)): ?>
                    <p class="text-muted small mb-3">No threaded messages yet. When the tenant chooses <em>Negotiate</em>, their first note appears here (or below after they send).</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3 mb-4">
                        <?php foreach ($negotiationThreadDisplay as $it): ?>
                            <?php if (($it['kind'] ?? '') === 'legacy_tenant'): ?>
                                <div class="border rounded p-3 bg-light">
                                    <div class="small text-muted mb-1">Tenant (initial — before message thread)</div>
                                    <?= nl2br(h((string)$it['body'])) ?>
                                    <?php if (!empty($it['at'])): ?>
                                        <div class="small text-muted mt-2"><?= h(date('Y-m-d H:i', strtotime((string)$it['at']))) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (($it['kind'] ?? '') === 'legacy_admin'): ?>
                                <div class="border rounded p-3 bg-primary bg-opacity-10 border-primary">
                                    <div class="small text-muted mb-1">Staff (saved notes — before thread)</div>
                                    <?= nl2br(h((string)$it['body'])) ?>
                                </div>
                            <?php elseif (($it['kind'] ?? '') === 'msg'): ?>
                                <?php $r = $it['row']; ?>
                                <?php if (($r['author_role'] ?? '') === 'tenant'): ?>
                                    <div class="border rounded p-3 bg-light">
                                        <div class="small text-muted mb-1">Tenant<?= !empty($r['created_at']) ? ' · ' . h(date('Y-m-d H:i', strtotime((string)$r['created_at']))) : '' ?></div>
                                        <?= nl2br(h((string)($r['body'] ?? ''))) ?>
                                    </div>
                                <?php else: ?>
                                    <?php $aid = (int)($r['admin_user_id'] ?? 0); ?>
                                    <div class="border rounded p-3 bg-primary bg-opacity-10 border-primary">
                                        <div class="small text-muted mb-1">Staff<?= $aid && !empty($negotiationAdminNames[$aid]) ? ' · ' . h($negotiationAdminNames[$aid]) : '' ?><?= !empty($r['created_at']) ? ' · ' . h(date('Y-m-d H:i', strtotime((string)$r['created_at']))) : '' ?></div>
                                        <?= nl2br(h((string)($r['body'] ?? ''))) ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canPostNegotiationReply): ?>
                    <form method="post" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="post_negotiation_reply">
                        <label class="form-label fw-semibold">Reply to tenant</label>
                        <textarea name="negotiation_reply" class="form-control mb-2" rows="4" required placeholder="Type your message. The tenant will see this in their portal."></textarea>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-reply"></i> Post reply</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted small mb-0">Replies are closed for this status (e.g. accepted, signed, or completed).</p>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($workflow['tenant_response']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Tenant Response</h5>
            </div>
            <div class="card-body">
                <p><?= nl2br(h($workflow['tenant_response'])) ?></p>
                <?php if ($workflow['tenant_response_date']): ?>
                    <small class="text-muted">Received: <?= date('Y-m-d', strtotime($workflow['tenant_response_date'])) ?></small>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$showNegotiationThreadCard && !empty($workflow['negotiation_notes'])): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Negotiation Notes</h5>
            </div>
            <div class="card-body">
                <p><?= nl2br(h($workflow['negotiation_notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Renewal Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5>Renewal Actions</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>" enctype="multipart/form-data" class="mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save_notice_content">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Admin/Renewal Fee Label</label>
                            <input type="text" name="admin_fee_label" class="form-control"
                                   value="<?= h((string)($workflow['admin_fee_label'] ?? 'Lease Renewal Charges')) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Stamp Image (optional)</label>
                            <input type="file" name="stamp_image" class="form-control" accept=".png,.jpg,.jpeg,.webp">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Terms &amp; Conditions (one line per bullet)</label>
                            <textarea class="form-control" name="terms_text" rows="8"><?= h((string)($workflow['terms_text'] ?? '')) ?></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Required Documents (one line per item)</label>
                            <textarea class="form-control" name="required_documents_text" rows="8"><?= h((string)($workflow['required_documents_text'] ?? '')) ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-save"></i> Save Notice Content
                    </button>
                </form>

                <hr>

                <?php if (!empty($workflow['renewal_notice_pdf_path'])): ?>
                    <div class="mb-3">
                        <a href="<?= h($appBase . '/' . ltrim((string)$workflow['renewal_notice_pdf_path'], '/')) ?>"
                           target="_blank"
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-file-earmark-pdf"></i> View Renewal Notice PDF
                        </a>
                    </div>
                <?php endif; ?>

                <div class="d-flex gap-2 flex-wrap mb-3">
                    <form method="POST" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>" class="m-0 renewal-action-form"
                        data-loading-text="Sending...">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="send_renewal_notice">
                        <button type="submit" class="btn btn-info">
                            <i class="bi bi-send"></i> Send Renewal Notice
                        </button>
                    </form>

                    <form method="POST" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>" class="m-0 renewal-action-form"
                        data-loading-text="Approving...">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="approve_convert">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2-circle"></i> Approve & Convert
                        </button>
                    </form>

                    <form method="POST" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>" class="m-0 renewal-action-form"
                        data-loading-text="Rejecting...">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-x-circle"></i> Reject
                        </button>
                    </form>
                </div>

                <hr>

                <form method="POST" action="lease_renewal_workflow_view.php?id=<?= (int)$workflowId ?>">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save_response">
                    <div class="mb-3">
                        <label class="form-label">Tenant Response</label>
                        <textarea class="form-control" name="tenant_response" rows="3" placeholder="Enter tenant's response..."><?= h($workflow['tenant_response'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Negotiation Notes</label>
                        <textarea class="form-control" name="negotiation_notes" rows="3" placeholder="Internal / legacy notes (optional). For tenant-visible replies use “Negotiation with tenant” above."><?= h($workflow['negotiation_notes'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Response / Notes</button>
                </form>
            </div>
        </div>

        <script>
        (function () {
            const forms = document.querySelectorAll('form.renewal-action-form');
            forms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    const btn = form.querySelector('button[type="submit"]');
                    if (!btn) return;
                    const loadingText = form.getAttribute('data-loading-text') || 'Processing...';
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + loadingText;
                });
            });
        })();
        </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

