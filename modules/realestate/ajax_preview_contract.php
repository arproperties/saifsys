<?php
/**
 * AJAX endpoint to preview contract with current form data
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/contract_generator.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

header('Content-Type: application/json');

$currentCompanyId = current_company_id($conn) ?: 1;
$templateId = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : 0;

if (!$templateId) {
    echo json_encode(['error' => 'Template ID is required']);
    exit;
}

// Get template
$stmt = $conn->prepare("SELECT template_content FROM re_contract_templates WHERE id = ? AND company_id = ? AND is_active = 1");
$stmt->execute([$templateId, $currentCompanyId]);
$template = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$template) {
    echo json_encode(['error' => 'Template not found']);
    exit;
}

// Get form data (for preview, we'll use form values or existing lease data)
$leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : 0;
$unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : 0;
$tenantId = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : 0;

// If lease exists, use it; otherwise build preview data from form
if ($leaseId) {
    // Use existing lease
    $contractContent = generate_contract_from_template($conn, $leaseId, $templateId);
} else {
    // Build preview data from form inputs
    $previewData = [];
    
    // Get unit info
    if ($unitId) {
        $stmt = $conn->prepare("
            SELECT u.unit_number, u.unit_type, u.area_sqm, u.premises_number, u.parking_slot, u.furniture_status,
                   b.name as building_name, b.address as building_address,
                   f.floor_number, f.name as floor_name
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id
            LEFT JOIN re_floors f ON f.id = u.floor_id
            WHERE u.id = ? AND u.company_id = ?
        ");
        $stmt->execute([$unitId, $currentCompanyId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $unit = null;
    }
    
    // Get tenant info
    if ($tenantId) {
        $stmt = $conn->prepare("
            SELECT first_name, last_name, phone, email, id_number, id_type, tenant_type, company_name, address
            FROM re_tenants
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$tenantId, $currentCompanyId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $tenant = null;
    }
    
    // Get company settings
    $stmt = $conn->prepare("SELECT * FROM company_settings WHERE company_id = ? LIMIT 1");
    $stmt->execute([$currentCompanyId]);
    $companySettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Prepare replacement data from form
    $annualRent = !empty($_POST['annual_rent']) ? (float)$_POST['annual_rent'] : 0;
    $numberOfInstallments = !empty($_POST['number_of_installments']) ? (int)$_POST['number_of_installments'] : 12;
    $monthlyRent = $numberOfInstallments > 0 ? $annualRent / $numberOfInstallments : 0;
    $securityDeposit = !empty($_POST['security_deposit']) ? (float)$_POST['security_deposit'] : 0;
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $paymentMethod = $_POST['payment_method'] ?? 'cheque';
    $leaseNumber = $_POST['lease_number'] ?? '';
    
    $replacements = [
        // Tenant Information
        '{TENANT_NAME}' => $tenant ? ($tenant['tenant_type'] === 'company' 
            ? ($tenant['company_name'] ?: trim($tenant['first_name'] . ' ' . $tenant['last_name']))
            : trim($tenant['first_name'] . ' ' . $tenant['last_name'])) : '[Tenant Name]',
        '{TENANT_FIRST_NAME}' => $tenant['first_name'] ?? '[First Name]',
        '{TENANT_LAST_NAME}' => $tenant['last_name'] ?? '[Last Name]',
        '{TENANT_FULL_NAME}' => $tenant ? trim($tenant['first_name'] . ' ' . $tenant['last_name']) : '[Full Name]',
        '{TENANT_PHONE}' => $tenant['phone'] ?? '[Phone]',
        '{TENANT_EMAIL}' => $tenant['email'] ?? '[Email]',
        '{TENANT_ID_NUMBER}' => $tenant['id_number'] ?? '[ID Number]',
        '{TENANT_ID_TYPE}' => $tenant['id_type'] ? ucfirst(str_replace('_', ' ', $tenant['id_type'])) : '[ID Type]',
        '{TENANT_ADDRESS}' => $tenant['address'] ?? '[Address]',
        '{TENANT_TYPE}' => $tenant ? ucfirst($tenant['tenant_type'] ?? 'individual') : 'Individual',
        '{COMPANY_NAME}' => $tenant['company_name'] ?? '',
        
        // Unit Information
        '{UNIT_NUMBER}' => $unit['unit_number'] ?? '[Unit Number]',
        '{UNIT_TYPE}' => $unit ? strtoupper($unit['unit_type'] ?? '') : '[TYPE]',
        '{UNIT_AREA}' => $unit['area_sqm'] ? number_format($unit['area_sqm'], 2) . ' sqm' : '[Area]',
        '{PREMISES_NUMBER}' => $unit['premises_number'] ?? '[Premises No]',
        '{PARKING_SLOT}' => $unit['parking_slot'] ?? '[Parking]',
        '{FURNITURE_STATUS}' => $unit ? ucfirst(str_replace('_', ' ', $unit['furniture_status'] ?? '')) : '[Status]',
        
        // Building Information
        '{BUILDING_NAME}' => $unit['building_name'] ?? '[Building Name]',
        '{BUILDING_ADDRESS}' => $unit['building_address'] ?? '[Building Address]',
        '{FLOOR_NUMBER}' => $unit['floor_number'] ?? '[Floor]',
        '{FLOOR_NAME}' => $unit['floor_name'] ?? ($unit['floor_number'] ? 'Floor ' . $unit['floor_number'] : '[Floor Name]'),
        
        // Lease Information
        '{LEASE_NUMBER}' => $leaseNumber ?: '[Lease Number]',
        '{START_DATE}' => $startDate ? date('d/m/Y', strtotime($startDate)) : '[Start Date]',
        '{END_DATE}' => $endDate ? date('d/m/Y', strtotime($endDate)) : '[End Date]',
        '{START_DATE_LONG}' => $startDate ? date('F d, Y', strtotime($startDate)) : '[Start Date]',
        '{END_DATE_LONG}' => $endDate ? date('F d, Y', strtotime($endDate)) : '[End Date]',
        '{ANNUAL_RENT}' => $annualRent ? number_format($annualRent, 2) . ' AED' : '[Annual Rent]',
        '{MONTHLY_RENT}' => $monthlyRent ? number_format($monthlyRent, 2) . ' AED' : '[Monthly Rent]',
        '{SECURITY_DEPOSIT}' => $securityDeposit ? number_format($securityDeposit, 2) . ' AED' : '[Security Deposit]',
        '{NUMBER_OF_INSTALLMENTS}' => $numberOfInstallments,
        '{PAYMENT_METHOD}' => ucfirst(str_replace('_', ' ', $paymentMethod)),
        '{GRACE_PERIOD_DAYS}' => !empty($_POST['grace_period_days']) ? (int)$_POST['grace_period_days'] : 0,
        '{MOVE_IN_DATE}' => !empty($_POST['move_in_date']) ? date('d/m/Y', strtotime($_POST['move_in_date'])) : '[Move-In Date]',
        
        // Additional Services
        '{ADDITIONAL_PARKING_FEE}' => !empty($_POST['additional_parking_fee']) ? number_format((float)$_POST['additional_parking_fee'], 2) . ' AED' : '',
        '{ADDITIONAL_PARKING_START}' => !empty($_POST['additional_parking_start_date']) ? date('d/m/Y', strtotime($_POST['additional_parking_start_date'])) : '',
        '{ADDITIONAL_PARKING_END}' => !empty($_POST['additional_parking_end_date']) ? date('d/m/Y', strtotime($_POST['additional_parking_end_date'])) : '',
        '{ADDITIONAL_STORE_FEE}' => !empty($_POST['additional_store_fee']) ? number_format((float)$_POST['additional_store_fee'], 2) . ' AED' : '',
        '{ADDITIONAL_STORE_START}' => !empty($_POST['additional_store_start_date']) ? date('d/m/Y', strtotime($_POST['additional_store_start_date'])) : '',
        '{ADDITIONAL_STORE_END}' => !empty($_POST['additional_store_end_date']) ? date('d/m/Y', strtotime($_POST['additional_store_end_date'])) : '',
        
        // Company Information
        '{COMPANY_NAME_SETTING}' => $companySettings['company_name'] ?? '[Company Name]',
        '{COMPANY_ADDRESS}' => $companySettings['address'] ?? '[Company Address]',
        '{COMPANY_PHONE}' => $companySettings['phone'] ?? '[Company Phone]',
        '{COMPANY_EMAIL}' => $companySettings['email'] ?? '[Company Email]',
        
        // Dates
        '{TODAY_DATE}' => date('d/m/Y'),
        '{TODAY_DATE_LONG}' => date('F d, Y'),
        '{YEAR}' => date('Y'),
    ];
    
    // Replace placeholders
    $contractContent = $template['template_content'];
    foreach ($replacements as $placeholder => $value) {
        $contractContent = str_replace($placeholder, $value, $contractContent);
    }
}

// Format for bilingual display
require_once __DIR__ . '/includes/contract_generator.php';
$formattedContent = format_contract_bilingual($contractContent);

echo json_encode(['content' => $formattedContent]);

