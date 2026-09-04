<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/db_connect.php';
require_once __DIR__.'/includes/branding.php';
require_once __DIR__.'/includes/url_helper.php';
require_once __DIR__.'/includes/sm_expense_service.php';
require_role(['Owner','Admin'], $conn);

// get_base_path() is provided by url_helper.php (works at server root or in subfolder)

// Get branding settings
$brand = getBrandSettings($conn);

// Handle tab selection via POST (for clean URLs) or GET (backward compatibility)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tab'])) {
    $_SESSION['settings_tab'] = $_POST['tab'];
    $tab = $_POST['tab'];
} else {
    $tab = $_GET['tab'] ?? $_SESSION['settings_tab'] ?? 'dashboard';
    if (isset($_GET['tab'])) {
        $_SESSION['settings_tab'] = $tab;
    }
}
$message = '';
$messageType = '';

// Only Owner can view audit history / security retention
if (in_array($tab, ['history', 'security'], true) && !has_role('Owner', $conn)) {
    http_response_code(403);
    echo '<div style="font-family:system-ui;padding:32px">
            <h3>403 – Forbidden</h3>
            <p>Only users with the Owner role can view audit history and security settings.</p>
          </div>';
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // State-changing settings actions require CSRF
    if ($action !== '' && function_exists('csrf_verify')) {
        csrf_verify(true);
    }
    
    try {
        switch ($action) {
            case 'update_company':
                $settingsCompanyId = (int)($_POST['settings_company_id'] ?? 0);
                if ($settingsCompanyId <= 0) {
                    $settingsCompanyId = (int)(current_company_id($conn) ?: 0);
                }
                if ($settingsCompanyId <= 0) {
                    throw new RuntimeException('No company selected. Choose a company before editing company information.');
                }

                $legal_name = trim($_POST['legal_name'] ?? '');
                $trade_name = trim($_POST['trade_name'] ?? '');
                $trn = trim($_POST['trn'] ?? '');
                $currency_code = trim($_POST['currency_code'] ?? 'AED');
                $address_line1 = trim($_POST['address_line1'] ?? '');
                $address_line2 = trim($_POST['address_line2'] ?? '');
                $city = trim($_POST['city'] ?? '');
                $state_region = trim($_POST['state_region'] ?? '');
                $postcode = trim($_POST['postcode'] ?? '');
                $country = trim($_POST['country'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $whatsapp = trim($_POST['whatsapp'] ?? '');
                $website = trim($_POST['website'] ?? '');
                $bank_name = trim($_POST['bank_name'] ?? '');
                $bank_account_no = trim($_POST['bank_account_no'] ?? '');
                $bank_iban = trim($_POST['bank_iban'] ?? '');
                $bank_swift = trim($_POST['bank_swift'] ?? '');
                
                if ($legal_name) {
                    company_settings_ensure_whatsapp_column($conn);
                    $conn->beginTransaction();

                    $exists = $conn->prepare('SELECT id FROM company_settings WHERE company_id = ? LIMIT 1');
                    $exists->execute([$settingsCompanyId]);
                    $rowId = (int)($exists->fetchColumn() ?: 0);

                    if ($rowId > 0) {
                        $stmt = $conn->prepare("
                            UPDATE company_settings SET 
                                legal_name = ?, trade_name = ?, trn = ?, currency_code = ?,
                                address_line1 = ?, address_line2 = ?, city = ?, state_region = ?,
                                postcode = ?, country = ?, phone = ?, email = ?, whatsapp = ?, website = ?,
                                bank_name = ?, bank_account_no = ?, bank_iban = ?, bank_swift = ?,
                                updated_at = NOW()
                            WHERE company_id = ?
                        ");
                        $stmt->execute([
                            $legal_name, $trade_name, $trn, $currency_code,
                            $address_line1, $address_line2, $city, $state_region,
                            $postcode, $country, $phone, $email, $whatsapp, $website,
                            $bank_name, $bank_account_no, $bank_iban, $bank_swift,
                            $settingsCompanyId
                        ]);
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO company_settings (
                                company_id, legal_name, trade_name, trn, currency_code,
                                address_line1, address_line2, city, state_region,
                                postcode, country, phone, email, whatsapp, website,
                                bank_name, bank_account_no, bank_iban, bank_swift, created_at, updated_at
                            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                        ");
                        $stmt->execute([
                            $settingsCompanyId, $legal_name, $trade_name, $trn, $currency_code,
                            $address_line1, $address_line2, $city, $state_region,
                            $postcode, $country, $phone, $email, $whatsapp, $website,
                            $bank_name, $bank_account_no, $bank_iban, $bank_swift
                        ]);
                        $rowId = (int)$conn->lastInsertId();
                    }
                    
                    $conn->commit();
                    
                    require_once __DIR__ . '/includes/AuditService.php';
                    AuditService::logEvent([
                        'action' => 'update',
                        'module' => 'admin',
                        'company_id' => $settingsCompanyId,
                        'object_type' => 'company_settings',
                        'object_id' => (string)$rowId,
                        'object_ref' => 'Company #' . $settingsCompanyId,
                        'summary' => 'Updated company information for company #' . $settingsCompanyId,
                        'new_data' => [
                            'legal_name' => $legal_name,
                            'trade_name' => $trade_name,
                            'trn' => $trn
                        ],
                        'success' => true
                    ]);
                    
                    $message = 'Company information updated successfully.';
                    $messageType = 'success';
                }
                break;

            case 'update_audit_retention':
                if (!has_role('Owner', $conn)) {
                    throw new RuntimeException('Only Owner can change audit retention.');
                }
                $months = max(6, min(120, (int)($_POST['audit_retention_months'] ?? 24)));
                $stmt = $conn->prepare("
                    INSERT INTO settings (`key`, `value`) VALUES ('audit_retention_months', ?)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                ");
                $stmt->execute([(string)$months]);
                require_once __DIR__ . '/includes/AuditService.php';
                AuditService::logEvent([
                    'action' => 'update',
                    'module' => 'admin',
                    'object_type' => 'settings',
                    'object_ref' => 'audit_retention_months',
                    'summary' => 'Set audit retention to ' . $months . ' months',
                    'new_data' => ['audit_retention_months' => $months],
                    'success' => true,
                ]);
                $message = 'Audit retention updated to ' . $months . ' months.';
                $messageType = 'success';
                $tab = 'security';
                break;

            case 'run_audit_archive':
                if (!has_role('Owner', $conn)) {
                    throw new RuntimeException('Only Owner can run audit archive.');
                }
                require_once __DIR__ . '/includes/audit_retention.php';
                $result = audit_log_run_retention($conn);
                $message = 'Archived ' . (int)$result['archived'] . ' audit row(s) older than '
                    . (int)$result['retention_months'] . ' months.';
                $messageType = 'success';
                $tab = 'security';
                break;
                
            case 'update_system':
                $default_vat_rate = (float)($_POST['default_vat_rate'] ?? 5.0);
                $currency = trim($_POST['currency'] ?? 'AED');
                $timezone = trim($_POST['timezone'] ?? 'Asia/Dubai');
                $date_format = trim($_POST['date_format'] ?? 'Y-m-d');
                $employee_of_month_id = trim($_POST['employee_of_month_id'] ?? '');
                $employee_of_month_message = trim($_POST['employee_of_month_message'] ?? '');
                
                if ($employee_of_month_id === '') {
                    $employee_of_month_id = '0';
                }
                
                $conn->beginTransaction();
                
                $settings = [
                    'default_vat_rate' => $default_vat_rate,
                    'currency' => $currency,
                    'timezone' => $timezone,
                    'date_format' => $date_format,
                    'employee_of_month_id' => $employee_of_month_id,
                    'employee_of_month_message' => $employee_of_month_message
                ];
                
                foreach ($settings as $key => $value) {
                    $stmt = $conn->prepare("
                        INSERT INTO settings (`key`, `value`) 
                        VALUES (?, ?) 
                        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                    ");
                    $stmt->execute([$key, $value]);
                }
                
                $conn->commit();
                
                // Audit Log: Track system settings update
                require_once __DIR__ . '/includes/AuditService.php';
                AuditService::log([
                    'action' => 'update',
                    'object_type' => 'settings',
                    'summary' => 'Updated system configuration',
                    'new_data' => $settings,
                    'success' => true
                ]);
                
                $message = 'System settings updated successfully.';
                $messageType = 'success';
                break;
                
            case 'update_email':
                $smtp_host = trim($_POST['smtp_host'] ?? '');
                $smtp_port = (int)($_POST['smtp_port'] ?? 587);
                $smtp_username = trim($_POST['smtp_username'] ?? '');
                $smtp_password = trim($_POST['smtp_password'] ?? '');
                $from_name = trim($_POST['from_name'] ?? '');
                $from_email = trim($_POST['from_email'] ?? '');
                $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
                $owner_emails = trim($_POST['owner_emails'] ?? '');
                
                $conn->beginTransaction();
                
                $stmt = $conn->prepare("
                    UPDATE app_email_settings 
                    SET smtp_host = ?, smtp_port = ?, smtp_username = ?, 
                        smtp_password = ?, from_name = ?, from_email = ?, is_enabled = ?, owner_emails = ?
                    WHERE id = 1
                ");
                $stmt->execute([$smtp_host, $smtp_port, $smtp_username, $smtp_password, $from_name, $from_email, $is_enabled, $owner_emails]);
                
                $conn->commit();
                
                // Audit Log: Track email settings update
                require_once __DIR__ . '/includes/AuditService.php';
                AuditService::log([
                    'action' => 'update',
                    'object_type' => 'app_email_settings',
                    'object_id' => '1',
                    'summary' => 'Updated email/SMTP settings',
                    'new_data' => [
                        'smtp_host' => $smtp_host,
                        'is_enabled' => $is_enabled
                    ],
                    'success' => true
                ]);
                
                $message = 'Email settings updated successfully.';
                $messageType = 'success';
                break;

            case 'update_mobile_app':
                require_once __DIR__ . '/includes/app_mobile_config_helper.php';
                app_mobile_config_save($conn, [
                    'android_latest_version' => $_POST['android_latest_version'] ?? '',
                    'android_min_version' => $_POST['android_min_version'] ?? '',
                    'ios_latest_version' => $_POST['ios_latest_version'] ?? '',
                    'ios_min_version' => $_POST['ios_min_version'] ?? '',
                    'force_update' => isset($_POST['force_update']) ? 1 : 0,
                    'play_store_url' => $_POST['play_store_url'] ?? '',
                    'app_store_url' => $_POST['app_store_url'] ?? '',
                    'update_title' => $_POST['update_title'] ?? '',
                    'update_message' => $_POST['update_message'] ?? '',
                    'maintenance_mode' => isset($_POST['maintenance_mode']) ? 1 : 0,
                    'maintenance_message' => $_POST['maintenance_message'] ?? '',
                    'feature_flags_json' => $_POST['feature_flags_json'] ?? '{}',
                    'property_share_enabled' => isset($_POST['property_share_enabled']) ? 1 : 0,
                    'share_base_url' => $_POST['share_base_url'] ?? '',
                    'share_message_template' => $_POST['share_message_template'] ?? '',
                    'android_package_name' => $_POST['android_package_name'] ?? '',
                    'android_sha256_fingerprints' => $_POST['android_sha256_fingerprints'] ?? '',
                    'ios_team_id' => $_POST['ios_team_id'] ?? '',
                    'ios_bundle_id' => $_POST['ios_bundle_id'] ?? '',
                ], function_exists('current_user_id') ? (int)current_user_id() : null);

                require_once __DIR__ . '/includes/AuditService.php';
                AuditService::log([
                    'action' => 'update',
                    'object_type' => 'app_mobile_config',
                    'object_id' => '1',
                    'summary' => 'Updated mobile app version / runtime config',
                    'new_data' => [
                        'android_latest' => trim((string)($_POST['android_latest_version'] ?? '')),
                        'ios_latest' => trim((string)($_POST['ios_latest_version'] ?? '')),
                        'force_update' => isset($_POST['force_update']) ? 1 : 0,
                        'maintenance_mode' => isset($_POST['maintenance_mode']) ? 1 : 0,
                    ],
                    'success' => true,
                ]);

                $message = 'Mobile app settings saved successfully.';
                $messageType = 'success';
                break;
                
            case 'update_re_email_notifications':
                $currentCompanyId = (int)(current_company_id($conn) ?: 0);
                if ($currentCompanyId <= 0) {
                    throw new RuntimeException('No company selected. Choose a company before saving RE email notifications.');
                }
                
                // Get notification types and emails from POST
                $notificationTypes = [
                    'maintenance_request' => trim($_POST['maintenance_request_email'] ?? ''),
                    'extra_service_request' => trim($_POST['extra_service_request_email'] ?? ''),
                    'cleaning_request' => trim($_POST['cleaning_request_email'] ?? ''),
                    'pest_control_request' => trim($_POST['pest_control_request_email'] ?? ''),
                    'cash_payment_pending_verification' => trim($_POST['cash_payment_pending_verification_email'] ?? ''),
                    'inventory_material_request' => trim($_POST['inventory_material_request_email'] ?? ''),
                    'lease_expiry' => trim($_POST['lease_expiry_email'] ?? ''),
                    'payment_overdue' => trim($_POST['payment_overdue_email'] ?? ''),
                    'move_in' => trim($_POST['move_in_email'] ?? ''),
                    'move_out' => trim($_POST['move_out_email'] ?? ''),
                    'legal_escalation' => trim($_POST['legal_escalation_email'] ?? ''),
                    'find_home_viewing_request' => trim($_POST['find_home_viewing_request_email'] ?? ''),
                    'find_home_lease_application' => trim($_POST['find_home_lease_application_email'] ?? ''),
                ];
                
                $conn->beginTransaction();
                
                // Delete existing notifications for this company
                $stmt = $conn->prepare("DELETE FROM re_email_notifications WHERE company_id = ?");
                $stmt->execute([$currentCompanyId]);
                
                // Insert new notifications
                $stmt = $conn->prepare("
                    INSERT INTO re_email_notifications (company_id, notification_type, recipient_email, is_enabled)
                    VALUES (?, ?, ?, 1)
                ");
                
                foreach ($notificationTypes as $type => $email) {
                    if (!empty($email)) {
                        // Validate email format and split by comma
                        $emails = array_map('trim', explode(',', $email));
                        foreach ($emails as $e) {
                            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                                try {
                                    $stmt->execute([$currentCompanyId, $type, $e]);
                                } catch (PDOException $e) {
                                    // Ignore duplicate key errors (unique constraint)
                                    if ($e->getCode() != 23000) throw $e;
                                }
                            }
                        }
                    }
                }
                
                $conn->commit();
                $message = 'Real Estate email notification settings saved successfully.';
                $messageType = 'success';
                break;

            case 'update_prepaid_settings':
                $asset = trim($_POST['sm_prepaid_asset_account'] ?? '');
                $validAssets = array_column(sm_prepaid_asset_options($conn), 'account_no');
                if ($asset === '' || !in_array($asset, $validAssets, true)) {
                    // Allow any active non-header asset if custom typed later; for now require from options list
                    $chk = $conn->prepare("SELECT account_no FROM chart_of_accounts WHERE account_no = ? AND type='Asset' AND is_header=0 AND is_active=1");
                    $chk->execute([$asset]);
                    if (!$chk->fetchColumn()) {
                        throw new RuntimeException('Choose a valid prepaid asset account.');
                    }
                }
                sm_set_setting($conn, 'sm_prepaid_asset_account', $asset);
                $message = 'Prepaid expense default asset account saved.';
                $messageType = 'success';
                break;
                
            case 'update_cash_advance_policy':
                $max_advance_amount_global = !empty($_POST['max_advance_amount_global']) ? (float)$_POST['max_advance_amount_global'] : null;
                $max_advance_percentage_salary = !empty($_POST['max_advance_percentage_salary']) ? (float)$_POST['max_advance_percentage_salary'] : null;
                $max_total_advance_amount = !empty($_POST['max_total_advance_amount']) ? (float)$_POST['max_total_advance_amount'] : null;
                $min_service_months = (int)($_POST['min_service_months'] ?? 0);
                $max_pending_advances = (int)($_POST['max_pending_advances'] ?? 1);
                $policy_rules = trim($_POST['policy_rules'] ?? '');
                $eligibility_criteria = trim($_POST['eligibility_criteria'] ?? '');
                $terms_and_conditions = trim($_POST['terms_and_conditions'] ?? '');
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                $conn->beginTransaction();
                
                // Check if policy exists
                $checkStmt = $conn->prepare("SELECT id FROM cash_advance_policy WHERE id = 1");
                $checkStmt->execute();
                $exists = $checkStmt->fetchColumn();
                
                if ($exists) {
                    $stmt = $conn->prepare("
                        UPDATE cash_advance_policy SET
                            max_advance_amount_global = ?,
                            max_advance_percentage_salary = ?,
                            max_total_advance_amount = ?,
                            min_service_months = ?,
                            max_pending_advances = ?,
                            policy_rules = ?,
                            eligibility_criteria = ?,
                            terms_and_conditions = ?,
                            is_active = ?,
                            updated_at = NOW()
                        WHERE id = 1
                    ");
                    $stmt->execute([
                        $max_advance_amount_global,
                        $max_advance_percentage_salary,
                        $max_total_advance_amount,
                        $min_service_months,
                        $max_pending_advances,
                        $policy_rules,
                        $eligibility_criteria,
                        $terms_and_conditions,
                        $is_active
                    ]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO cash_advance_policy (
                            id, max_advance_amount_global, max_advance_percentage_salary, max_total_advance_amount,
                            min_service_months, max_pending_advances,
                            policy_rules, eligibility_criteria, terms_and_conditions, is_active
                        ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $max_advance_amount_global,
                        $max_advance_percentage_salary,
                        $max_total_advance_amount,
                        $min_service_months,
                        $max_pending_advances,
                        $policy_rules,
                        $eligibility_criteria,
                        $terms_and_conditions,
                        $is_active
                    ]);
                }
                
                $conn->commit();
                
                // Audit Log
                require_once __DIR__ . '/includes/AuditService.php';
                AuditService::log([
                    'action' => 'update',
                    'object_type' => 'cash_advance_policy',
                    'object_id' => '1',
                    'summary' => 'Updated cash advance policy settings',
                    'new_data' => [
                        'max_advance_amount_global' => $max_advance_amount_global,
                        'max_advance_percentage_salary' => $max_advance_percentage_salary,
                        'min_service_months' => $min_service_months
                    ],
                    'success' => true
                ]);
                
                $message = 'Cash advance policy updated successfully.';
                $messageType = 'success';
                $tab = 'cash_advance_policy';
                break;
                
            case 'assign_roles':
                $user_id = (int)($_POST['user_id'] ?? 0);
                $roles = $_POST['roles'] ?? [];
                
                if ($user_id > 0) {
                    $conn->beginTransaction();
                    
                    // Clear existing roles
                    $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    // Add new roles
                    if (!empty($roles)) {
                        $stmt = $conn->prepare("
                            INSERT INTO user_roles (user_id, role_id) 
                            SELECT ?, id FROM roles WHERE name = ?
                        ");
                        foreach ($roles as $role) {
                            $stmt->execute([$user_id, $role]);
                        }
                    }
                    
                    $conn->commit();
                    
                    // Audit Log: Track user role assignment
                    require_once __DIR__ . '/includes/AuditService.php';
                    AuditService::log([
                        'action' => 'update',
                        'object_type' => 'user_roles',
                        'object_id' => (string)$user_id,
                        'summary' => "Updated roles for user ID {$user_id}: " . implode(', ', $roles),
                        'new_data' => ['roles' => $roles],
                        'success' => true
                    ]);
                    
                    $message = 'User roles updated successfully.';
                    $messageType = 'success';
                }
                break;

            case 'assign_task_access':
                $user_id = (int)($_POST['user_id'] ?? 0);
                $can_view = isset($_POST['task_can_view']) ? 1 : 0;
                $can_admin_view = isset($_POST['task_can_admin_view']) ? 1 : 0;

                if ($can_admin_view) {
                    $can_view = 1; // admin implies view
                }

                if ($user_id > 0) {
                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO user_task_permissions (user_id, can_view, can_admin_view, updated_by)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                can_view = VALUES(can_view),
                                can_admin_view = VALUES(can_admin_view),
                                updated_by = VALUES(updated_by),
                                updated_at = NOW()
                        ");
                        $stmt->execute([$user_id, $can_view, $can_admin_view, (int)current_user_id()]);

                        require_once __DIR__ . '/includes/AuditService.php';
                        AuditService::log([
                            'action' => 'update',
                            'object_type' => 'user_task_permissions',
                            'object_id' => (string)$user_id,
                            'summary' => "Updated task access for user ID {$user_id}",
                            'new_data' => [
                                'can_view' => $can_view,
                                'can_admin_view' => $can_admin_view
                            ],
                            'success' => true
                        ]);

                        $message = 'Task access updated successfully.';
                        $messageType = 'success';
                    } catch (Exception $e) {
                        $message = 'Error updating task access: ' . $e->getMessage();
                        $messageType = 'danger';
                    }
                }
                break;
                
            case 'create_role':
                $role_name = trim($_POST['role_name'] ?? '');
                $role_description = trim($_POST['role_description'] ?? '');
                $role_module = trim($_POST['role_module'] ?? '');
                $payrollOverride = isset($_POST['payroll_override_validation']) && (string)$_POST['payroll_override_validation'] === '1';
                
                if ($role_name) {
                    try {
                        // Check if role already exists
                        $stmt = $conn->prepare("SELECT id FROM roles WHERE name = ?");
                        $stmt->execute([$role_name]);
                        if ($stmt->fetchColumn()) {
                            $message = 'Role with this name already exists.';
                            $messageType = 'warning';
                        } else {
                            $stmt = $conn->prepare("
                                INSERT INTO roles (name, description, module, is_system) 
                                VALUES (?, ?, ?, 0)
                            ");
                            $stmt->execute([$role_name, $role_description ?: null, $role_module ?: null]);
                            $newRoleId = (int)$conn->lastInsertId();

                            require_once __DIR__ . '/includes/permissions.php';
                            set_role_payroll_override_validation($newRoleId, $payrollOverride, $conn);
                            
                            // Audit Log
                            require_once __DIR__ . '/includes/AuditService.php';
                            AuditService::log([
                                'action' => 'create',
                                'object_type' => 'role',
                                'object_id' => (string)$newRoleId,
                                'summary' => "Created new role: {$role_name}",
                                'new_data' => [
                                    'name' => $role_name,
                                    'module' => $role_module,
                                    'payroll_override_validation' => $payrollOverride,
                                ],
                                'success' => true
                            ]);
                            
                            $message = 'Role created successfully.';
                            $messageType = 'success';
                        }
                    } catch (Exception $e) {
                        $message = 'Error creating role: ' . $e->getMessage();
                        $messageType = 'danger';
                    }
                } else {
                    $message = 'Role name is required.';
                    $messageType = 'warning';
                }
                $tab = 'roles';
                break;
                
            case 'update_role':
                $role_id = (int)($_POST['role_id'] ?? 0);
                $role_name = trim($_POST['role_name'] ?? '');
                $role_description = trim($_POST['role_description'] ?? '');
                $role_module = trim($_POST['role_module'] ?? '');
                $payrollOverride = isset($_POST['payroll_override_validation']) && (string)$_POST['payroll_override_validation'] === '1';
                
                if ($role_id > 0 && $role_name) {
                    try {
                        $roleMeta = $conn->prepare("SELECT id, name, is_system FROM roles WHERE id = ? LIMIT 1");
                        $roleMeta->execute([$role_id]);
                        $existingRole = $roleMeta->fetch(PDO::FETCH_ASSOC);
                        if (!$existingRole) {
                            $message = 'Role not found.';
                            $messageType = 'warning';
                        } else {
                            $isSystemRole = !empty($existingRole['is_system']);

                            // Check if another role with same name exists
                            $stmt = $conn->prepare("SELECT id FROM roles WHERE name = ? AND id != ?");
                            $stmt->execute([$role_name, $role_id]);
                            if ($stmt->fetchColumn()) {
                                $message = 'Another role with this name already exists.';
                                $messageType = 'warning';
                            } else {
                                $metaUpdated = false;
                                if (!$isSystemRole) {
                                    $stmt = $conn->prepare("
                                        UPDATE roles 
                                        SET name = ?, description = ?, module = ? 
                                        WHERE id = ? AND is_system = 0
                                    ");
                                    $stmt->execute([$role_name, $role_description ?: null, $role_module ?: null, $role_id]);
                                    $metaUpdated = true;
                                }

                                require_once __DIR__ . '/includes/permissions.php';
                                $permOk = set_role_payroll_override_validation($role_id, $payrollOverride, $conn);

                                if ($metaUpdated || $permOk) {
                                    require_once __DIR__ . '/includes/AuditService.php';
                                    AuditService::log([
                                        'action' => 'update',
                                        'object_type' => 'role',
                                        'object_id' => (string)$role_id,
                                        'summary' => "Updated role: {$role_name}",
                                        'new_data' => [
                                            'name' => $isSystemRole ? $existingRole['name'] : $role_name,
                                            'module' => $role_module,
                                            'payroll_override_validation' => $payrollOverride,
                                            'metadata_updated' => $metaUpdated,
                                        ],
                                        'success' => true
                                    ]);

                                    $message = $isSystemRole
                                        ? 'Role permissions updated successfully.'
                                        : 'Role updated successfully.';
                                    $messageType = 'success';
                                } else {
                                    $message = 'Could not update role permissions.';
                                    $messageType = 'danger';
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $message = 'Error updating role: ' . $e->getMessage();
                        $messageType = 'danger';
                    }
                }
                $tab = 'roles';
                break;
                
            case 'delete_role':
                $role_id = (int)($_POST['role_id'] ?? 0);
                
                if ($role_id > 0) {
                    try {
                        // Check if it's a system role
                        $stmt = $conn->prepare("SELECT is_system FROM roles WHERE id = ?");
                        $stmt->execute([$role_id]);
                        $is_system = $stmt->fetchColumn();
                        
                        if ($is_system) {
                            $message = 'Cannot delete system roles.';
                            $messageType = 'warning';
                        } else {
                            // Check if role is assigned to any users
                            $stmt = $conn->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = ?");
                            $stmt->execute([$role_id]);
                            $user_count = $stmt->fetchColumn();
                            
                            if ($user_count > 0) {
                                $message = "Cannot delete role: It is assigned to {$user_count} user(s). Remove assignments first.";
                                $messageType = 'warning';
                            } else {
                                $stmt = $conn->prepare("SELECT name FROM roles WHERE id = ?");
                                $stmt->execute([$role_id]);
                                $role_name = $stmt->fetchColumn();
                                
                                $stmt = $conn->prepare("DELETE FROM roles WHERE id = ? AND is_system = 0");
                                $stmt->execute([$role_id]);
                                
                                if ($stmt->rowCount() > 0) {
                                    // Audit Log
                                    require_once __DIR__ . '/includes/AuditService.php';
                                    AuditService::log([
                                        'action' => 'delete',
                                        'object_type' => 'role',
                                        'object_id' => (string)$role_id,
                                        'summary' => "Deleted role: {$role_name}",
                                        'success' => true
                                    ]);
                                    
                                    $message = 'Role deleted successfully.';
                                    $messageType = 'success';
                                } else {
                                    $message = 'Role not found or cannot be deleted.';
                                    $messageType = 'warning';
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $message = 'Error deleting role: ' . $e->getMessage();
                        $messageType = 'danger';
                    }
                }
                $tab = 'roles';
                break;
                
            case 'assign_companies':
                $user_id = (int)($_POST['user_id'] ?? 0);
                $companies = $_POST['companies'] ?? [];
                $primary_company_id = (int)($_POST['primary_company_id'] ?? 0);
                
                if ($user_id > 0) {
                    $conn->beginTransaction();
                    
                    try {
                        // Clear existing company assignments
                        $stmt = $conn->prepare("DELETE FROM user_companies WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        
                        // Add new company assignments
                        if (!empty($companies)) {
                            $stmt = $conn->prepare("
                                INSERT INTO user_companies (user_id, company_id, is_primary) 
                                VALUES (?, ?, ?)
                            ");
                            
                            foreach ($companies as $company_id) {
                                $company_id = (int)$company_id;
                                $is_primary = ($company_id === $primary_company_id) ? 1 : 0;
                                $stmt->execute([$user_id, $company_id, $is_primary]);
                            }
                            
                            // If no primary was set but companies exist, set first one as primary
                            if ($primary_company_id === 0 && !empty($companies)) {
                                $first_company = (int)$companies[0];
                                $stmt = $conn->prepare("
                                    UPDATE user_companies 
                                    SET is_primary = 1 
                                    WHERE user_id = ? AND company_id = ?
                                    LIMIT 1
                                ");
                                $stmt->execute([$user_id, $first_company]);
                            }
                        }
                        
                        $conn->commit();
                        
                        // Audit Log
                        require_once __DIR__ . '/includes/AuditService.php';
                        AuditService::log([
                            'action' => 'update',
                            'object_type' => 'user_companies',
                            'object_id' => (string)$user_id,
                            'summary' => "Updated company assignments for user ID {$user_id}",
                            'new_data' => ['companies' => $companies, 'primary' => $primary_company_id],
                            'success' => true
                        ]);
                        
                        $message = 'User company assignments updated successfully.';
                        $messageType = 'success';
                    } catch (Exception $e) {
                        $conn->rollBack();
                        $message = 'Error updating company assignments: ' . $e->getMessage();
                        $messageType = 'danger';
                    }
                }
                break;
                
            case 'save_departments':
                require_once __DIR__ . '/includes/rbac_department.php';
                
                $role_id = (int)($_POST['role_id'] ?? 0);
                $departments = $_POST['departments'] ?? [];
                
                if ($role_id > 0) {
                    // Build departments structure (all modules: cleaning, RE, construction, ARS, inventory, grocery, barber, HR)
                    $departmentsStructure = rbac_department_selection_to_structure($departments);
                    
                    // Save departments
                    if (set_role_departments($role_id, $departmentsStructure, $conn)) {
                        require_once __DIR__ . '/includes/permissions.php';
                        sync_grocery_barber_role_modules_for_role($role_id, $departmentsStructure, $conn);
                        // Audit Log
                        require_once __DIR__ . '/includes/AuditService.php';
                        AuditService::log([
                            'action' => 'update',
                            'object_type' => 'role_departments',
                            'object_id' => (string)$role_id,
                            'summary' => "Updated departments for role ID {$role_id}",
                            'new_data' => ['departments_count' => count($departments)],
                            'success' => true
                        ]);
                        
                        $message = 'Departments saved successfully.';
                        $messageType = 'success';
                        $tab = 'departments';
                    } else {
                        $message = 'Error saving departments.';
                        $messageType = 'danger';
                    }
                } else {
                    $message = 'Invalid role.';
                    $messageType = 'warning';
                }
                break;
            
            case 'save_emp_profile':
                $featureFlags = [
                    'emp_profile_show_stats' => isset($_POST['feature_show_stats']) ? 1 : 0,
                    'emp_profile_show_kudos' => isset($_POST['feature_show_kudos']) ? 1 : 0,
                    'emp_profile_show_rewards' => isset($_POST['feature_show_rewards']) ? 1 : 0,
                    'emp_profile_show_progress' => isset($_POST['feature_show_progress']) ? 1 : 0,
                    'emp_profile_show_history' => isset($_POST['feature_show_history']) ? 1 : 0,
                ];

                $selectedEmployee = (int)($_POST['employee_of_month_id'] ?? 0);
                $awardMonthInput = trim($_POST['employee_of_month_month'] ?? '');
                $awardMonth = $awardMonthInput ? date('Y-m-01', strtotime($awardMonthInput . '-01')) : date('Y-m-01');
                $awardMessage = trim($_POST['employee_of_month_message'] ?? "🎉 Congratulations {name}! You're our Employee of the Month! 🌟");
                $targetHours = (float)($_POST['employee_of_month_target_hours'] ?? 160);

                $rewardDefaultsPosted = $_POST['reward_defaults'] ?? [];
                $normalizedDefaults = [];
                foreach ($rewardDefaultsPosted as $item) {
                    $item = trim((string)$item);
                    if ($item !== '') {
                        $normalizedDefaults[] = $item;
                    }
                }
                if (empty($normalizedDefaults)) {
                    $normalizedDefaults = ['Certificate sent', 'Bonus processed', 'Celebration announced'];
                }
                $rewardDefaultsJson = json_encode($normalizedDefaults, JSON_UNESCAPED_UNICODE);

                $conn->beginTransaction();

                // Save feature flags
                foreach ($featureFlags as $key => $value) {
                    $stmt = $conn->prepare("
                        INSERT INTO settings (`key`, `value`)
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                    ");
                    $stmt->execute([$key, (string)$value]);
                }

                // Save reward defaults
                $stmt = $conn->prepare("
                    INSERT INTO settings (`key`, `value`)
                    VALUES ('emp_profile_reward_defaults', ?)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                ");
                $stmt->execute([$rewardDefaultsJson]);

                // Save employee of the month configuration (legacy keys reused)
                $stmt = $conn->prepare("
                    INSERT INTO settings (`key`, `value`)
                    VALUES ('employee_of_month_id', ?)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                ");
                $stmt->execute([(string)$selectedEmployee]);

                $stmt = $conn->prepare("
                    INSERT INTO settings (`key`, `value`)
                    VALUES ('employee_of_month_message', ?)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                ");
                $stmt->execute([$awardMessage]);

                $stmt = $conn->prepare("
                    INSERT INTO settings (`key`, `value`)
                    VALUES ('employee_of_month_month', ?)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                ");
                $stmt->execute([$awardMonth]);

                $stmt = $conn->prepare("
                    INSERT INTO settings (`key`, `value`)
                    VALUES ('employee_of_month_target_hours', ?)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                ");
                $stmt->execute([(string)$targetHours]);

                $awardId = null;
                if ($selectedEmployee > 0) {
                    $headline = trim($_POST['employee_of_month_headline'] ?? '');
                    if ($headline === '') {
                        $headline = 'Employee of the Month';
                    }

                    $awardUpsert = $conn->prepare("
                        INSERT INTO employee_awards
                            (award_type, employee_id, award_month, headline, message, target_hours, progress_hours, created_by, updated_by, updated_at)
                        VALUES
                            ('employee_of_month', ?, ?, ?, ?, ?, NULL, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                            employee_id = VALUES(employee_id),
                            headline = VALUES(headline),
                            message = VALUES(message),
                            target_hours = VALUES(target_hours),
                            updated_by = VALUES(updated_by),
                            updated_at = NOW()
                    ");
                    $currentUserId = (int)($_SESSION['user']['id'] ?? 0) ?: null;
                    $awardUpsert->execute([
                        $selectedEmployee,
                        $awardMonth,
                        $headline,
                        $awardMessage,
                        $targetHours,
                        $currentUserId,
                        $currentUserId
                    ]);

                    $idLookup = $conn->prepare("
                        SELECT id FROM employee_awards
                        WHERE award_type = 'employee_of_month' AND award_month = ?
                        LIMIT 1
                    ");
                    $idLookup->execute([$awardMonth]);
                    $awardId = (int)$idLookup->fetchColumn();

                    if ($awardId) {
                        $existingItemsStmt = $conn->prepare("
                            SELECT id, item_label FROM employee_award_checklists
                            WHERE award_id = ?
                        ");
                        $existingItemsStmt->execute([$awardId]);
                        $existingItems = $existingItemsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                        foreach ($normalizedDefaults as $label) {
                            if (!in_array($label, $existingItems, true)) {
                                $insertItem = $conn->prepare("
                                    INSERT INTO employee_award_checklists (award_id, item_label, is_done, updated_by)
                                    VALUES (?, ?, 0, ?)
                                ");
                                $insertItem->execute([$awardId, $label, $currentUserId]);
                            }
                        }

                        if (!empty($_POST['prune_unused_checklist'])) {
                            foreach ($existingItems as $id => $label) {
                                if (!in_array($label, $normalizedDefaults, true)) {
                                    $del = $conn->prepare("DELETE FROM employee_award_checklists WHERE id = ?");
                                    $del->execute([$id]);
                                }
                            }
                        }
                    }
                }

                $conn->commit();

                require_once __DIR__ . '/includes/AuditService.php';
                AuditService::log([
                    'action' => 'update',
                    'object_type' => 'emp_profile_settings',
                    'object_id' => (string)$selectedEmployee,
                    'summary' => 'Updated employee recognition settings',
                    'new_data' => [
                        'employee_of_month_id' => $selectedEmployee,
                        'award_month' => $awardMonth,
                        'target_hours' => $targetHours,
                        'features' => $featureFlags,
                    ],
                    'success' => true
                ]);

                $message = 'Employee profile settings updated successfully.';
                $messageType = 'success';
                $tab = 'emp_profile';
                break;

            case 'update_award_checklist':
                $awardId = (int)($_POST['award_id'] ?? 0);
                $items = $_POST['checklist'] ?? [];
                $currentUserId = (int)($_SESSION['user']['id'] ?? 0) ?: null;
                if ($awardId > 0 && $items) {
                    foreach ($items as $itemId => $value) {
                        $isDone = $value === '1' ? 1 : 0;
                        $stmt = $conn->prepare("
                            UPDATE employee_award_checklists
                            SET is_done = ?, updated_by = ?, updated_at = NOW()
                            WHERE id = ? AND award_id = ?
                        ");
                        $stmt->execute([$isDone, $currentUserId, (int)$itemId, $awardId]);
                    }
                    $message = 'Reward checklist updated.';
                    $messageType = 'success';
                }
                $tab = 'emp_profile';
                break;

            case 'add_kudos':
                $kudosEmployee = (int)($_POST['kudos_employee_id'] ?? 0);
                $kudosMessage = trim($_POST['kudos_message'] ?? '');
                $kudosVisibility = $_POST['kudos_visibility'] === 'private' ? 'private' : 'public';
                if ($kudosEmployee > 0 && $kudosMessage !== '') {
                    $stmt = $conn->prepare("
                        INSERT INTO employee_kudos (employee_id, author_id, message, visibility)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $kudosEmployee,
                        (int)($_SESSION['user']['id'] ?? 0) ?: null,
                        $kudosMessage,
                        $kudosVisibility
                    ]);

                    require_once __DIR__ . '/includes/AuditService.php';
                    AuditService::log([
                        'action' => 'create',
                        'object_type' => 'employee_kudos',
                        'object_id' => (string)$conn->lastInsertId(),
                        'summary' => "New kudos for employee {$kudosEmployee}",
                        'new_data' => [
                            'employee_id' => $kudosEmployee,
                            'visibility' => $kudosVisibility
                        ],
                        'success' => true
                    ]);

                    $message = 'Kudos added successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Please select an employee and enter a message.';
                    $messageType = 'warning';
                }
                $tab = 'emp_profile';
                break;

            case 'delete_kudos':
                $kudosId = (int)($_POST['kudos_id'] ?? 0);
                if ($kudosId > 0) {
                    $stmt = $conn->prepare("DELETE FROM employee_kudos WHERE id = ?");
                    $stmt->execute([$kudosId]);
                    $message = 'Kudos removed.';
                    $messageType = 'success';
                }
                $tab = 'emp_profile';
                break;
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Load current settings
function getSetting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn() ?: $default;
}

// Active company for company-scoped settings (fail closed — no ?: 1)
$settingsCompanyId = (int)(current_company_id($conn) ?: 0);
if ((has_role('Owner', $conn) || has_role('Admin', $conn)) && !empty($_GET['settings_company_id'])) {
    $candidate = (int)$_GET['settings_company_id'];
    if ($candidate > 0) {
        $chk = $conn->prepare('SELECT id FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
        $chk->execute([$candidate]);
        if ($chk->fetchColumn()) {
            $settingsCompanyId = $candidate;
        }
    }
}
$settingsCompanyName = '';
if ($settingsCompanyId > 0) {
    $cn = $conn->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
    $cn->execute([$settingsCompanyId]);
    $settingsCompanyName = (string)($cn->fetchColumn() ?: '');
}

// Load company settings for selected company
$companySettings = [];
if ($settingsCompanyId > 0) {
    company_settings_ensure_whatsapp_column($conn);
    $cs = $conn->prepare('SELECT * FROM company_settings WHERE company_id = ? LIMIT 1');
    $cs->execute([$settingsCompanyId]);
    $companySettings = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Load email settings (global SMTP singleton)
$emailSettings = $conn->query("SELECT * FROM app_email_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];

// Mobile app runtime config (global singleton)
require_once __DIR__ . '/includes/app_mobile_config_helper.php';
app_mobile_config_ensure_schema($conn);
$mobileAppSettings = app_mobile_config_get($conn);
$auditRetentionMonths = (int)getSetting($conn, 'audit_retention_months', '24');
if ($auditRetentionMonths < 6) {
    $auditRetentionMonths = 24;
}

// Load users and roles
$hasUserTaskPermissionsTable = false;
try {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'user_task_permissions'");
    $hasUserTaskPermissionsTable = (bool)$tableCheck->fetchColumn();
} catch (Throwable $e) {
    $hasUserTaskPermissionsTable = false;
}
$taskPermSelect = $hasUserTaskPermissionsTable
    ? "COALESCE(utp.can_view, 0) AS task_can_view, COALESCE(utp.can_admin_view, 0) AS task_can_admin_view"
    : "0 AS task_can_view, 0 AS task_can_admin_view";
$taskPermJoin = $hasUserTaskPermissionsTable ? "LEFT JOIN user_task_permissions utp ON utp.user_id = u.id" : "";

$users = $conn->query("
    SELECT u.id, u.username, u.fullname, u.email, u.contactnumber, u.status,
           GROUP_CONCAT(DISTINCT r.name) as roles,
           GROUP_CONCAT(DISTINCT CONCAT(c.id, ':', c.name, ':', c.business_type, ':', IFNULL(uc.is_primary, 0)) SEPARATOR '||') as companies,
           {$taskPermSelect}
    FROM user u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    LEFT JOIN user_companies uc ON uc.user_id = u.id
    LEFT JOIN companies c ON c.id = uc.company_id
    {$taskPermJoin}
    WHERE u.status = 1
    GROUP BY u.id
    ORDER BY u.fullname
")->fetchAll(PDO::FETCH_ASSOC);

// Get all companies for assignment
$allCompanies = $conn->query("SELECT id, name, business_type FROM companies WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$allRoles = $conn->query("SELECT id, name, description FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$employeesList = $conn->query("
    SELECT id, full_name, employee_code 
    FROM employees 
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

$empProfileFlags = [
    'show_stats'     => (int)getSetting($conn, 'emp_profile_show_stats', '1'),
    'show_kudos'     => (int)getSetting($conn, 'emp_profile_show_kudos', '1'),
    'show_rewards'   => (int)getSetting($conn, 'emp_profile_show_rewards', '1'),
    'show_progress'  => (int)getSetting($conn, 'emp_profile_show_progress', '1'),
    'show_history'   => (int)getSetting($conn, 'emp_profile_show_history', '1'),
];

$rewardDefaultsSetting = getSetting($conn, 'emp_profile_reward_defaults', json_encode([
    'Certificate sent',
    'Bonus processed',
    'Celebration announced'
]));
$rewardDefaultItems = json_decode($rewardDefaultsSetting, true);
if (!is_array($rewardDefaultItems)) {
    $rewardDefaultItems = ['Certificate sent', 'Bonus processed', 'Celebration announced'];
}

$currentAwardMonth = getSetting($conn, 'employee_of_month_month', date('Y-m-01'));
$currentAwardMonthDate = $currentAwardMonth ? date('Y-m-01', strtotime($currentAwardMonth)) : date('Y-m-01');
$currentAward = null;
$currentAwardChecklists = [];

try {
    $awardStmt = $conn->prepare("
        SELECT ea.*, e.full_name, e.employee_code
        FROM employee_awards ea
        JOIN employees e ON e.id = ea.employee_id
        WHERE ea.award_type = 'employee_of_month' AND ea.award_month = ?
        LIMIT 1
    ");
    $awardStmt->execute([$currentAwardMonthDate]);
    $currentAward = $awardStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($currentAward) {
        $checkStmt = $conn->prepare("
            SELECT id, item_label, is_done
            FROM employee_award_checklists
            WHERE award_id = ?
            ORDER BY id
        ");
        $checkStmt->execute([$currentAward['id']]);
        $currentAwardChecklists = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $currentAward = null;
    $currentAwardChecklists = [];
}

$awardHistory = [];
try {
    $histStmt = $conn->query("
        SELECT ea.*, e.full_name, e.employee_code
        FROM employee_awards ea
        JOIN employees e ON e.id = ea.employee_id
        WHERE ea.award_type = 'employee_of_month'
        ORDER BY ea.award_month DESC
        LIMIT 12
    ");
    $awardHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $awardHistory = [];
}

$recentKudos = [];
try {
    $kudoStmt = $conn->query("
        SELECT k.*, e.full_name AS employee_name,
               au.fullname AS author_name, au.username AS author_username
        FROM employee_kudos k
        JOIN employees e ON e.id = k.employee_id
        LEFT JOIN `user` au ON au.id = k.author_id
        ORDER BY k.created_at DESC
        LIMIT 20
    ");
    $recentKudos = $kudoStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentKudos = [];
}

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Helper function to parse HTML list items from existing policy data
function parseListItems($html) {
    if (empty($html)) {
        return [];
    }
    
    // Extract list items from <ul><li>...</li></ul> format
    preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $matches);
    if (!empty($matches[1])) {
        return array_map(function($item) {
            return trim(strip_tags($item));
        }, $matches[1]);
    }
    
    // If no HTML list found, try to split by newlines
    $lines = array_filter(array_map('trim', explode("\n", $html)));
    return array_values($lines);
}

require_once __DIR__ . '/includes/admin/admin_nav.php';
$adminNavGroups = admin_nav_groups($conn);
$tabMeta = admin_nav_tab_meta((string)$tab);
$pageTitle = $tabMeta[0];

// Dashboard KPIs (read-only)
$adminDash = [
    'users' => count($users),
    'companies' => count($allCompanies),
    'audit_24h' => 0,
    'failed_login_24h' => 0,
    'recent' => [],
];
if ($tab === 'dashboard') {
    try {
        $adminDash['audit_24h'] = (int)$conn->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn();
        $adminDash['failed_login_24h'] = (int)$conn->query("SELECT COUNT(*) FROM audit_log WHERE action='login' AND success=0 AND created_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn();
        $adminDash['recent'] = $conn->query("
            SELECT id, created_at, user_name, action, summary, success
            FROM audit_log
            ORDER BY created_at DESC, id DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // fail-soft for dashboard widgets
    }
}

require __DIR__ . '/includes/admin/admin_layout_header.php';
?>

  <?php if ($message): ?>
    <div class="alert alert-<?= h($messageType) ?> alert-dismissible fade show border-0 shadow-sm">
      <?= h($message) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Dashboard -->
  <?php if ($tab === 'dashboard'): ?>
    <?= admin_ui_page_header('Administration', 'Control Center — configure organization, access, communications, and audit', [
      ['label' => 'Administration', 'href' => get_base_path() . '/settings.php?tab=dashboard'],
      ['label' => 'Dashboard'],
    ]) ?>
    <div class="row g-3 mb-4">
      <div class="col-6 col-lg-3"><?= admin_ui_kpi(['label' => 'Active users', 'value' => (string)$adminDash['users'], 'icon' => 'users', 'href' => get_base_path() . '/settings.php?tab=users']) ?></div>
      <div class="col-6 col-lg-3"><?= admin_ui_kpi(['label' => 'Companies', 'value' => (string)$adminDash['companies'], 'icon' => 'building-2', 'href' => get_base_path() . '/settings.php?tab=companies']) ?></div>
      <div class="col-6 col-lg-3"><?= admin_ui_kpi(['label' => 'Events (24h)', 'value' => (string)$adminDash['audit_24h'], 'icon' => 'activity', 'href' => get_base_path() . '/settings.php?tab=history']) ?></div>
      <div class="col-6 col-lg-3"><?= admin_ui_kpi(['label' => 'Failed logins (24h)', 'value' => (string)$adminDash['failed_login_24h'], 'icon' => 'shield-alert', 'sub' => 'Auth failures', 'href' => get_base_path() . '/settings.php?tab=history']) ?></div>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-md-4 col-lg-3"><a class="admin-info-card" href="<?= get_base_path() ?>/settings.php?tab=companies"><div class="d-flex align-items-center gap-2 mb-2"><i data-lucide="building-2" style="width:18px;height:18px;color:var(--admin-primary)"></i><strong>Companies</strong></div><p class="small text-muted mb-0">Modules &amp; company setup</p></a></div>
      <div class="col-md-4 col-lg-3"><a class="admin-info-card" href="<?= get_base_path() ?>/settings.php?tab=users"><div class="d-flex align-items-center gap-2 mb-2"><i data-lucide="users" style="width:18px;height:18px;color:var(--admin-primary)"></i><strong>Users</strong></div><p class="small text-muted mb-0">Access &amp; company assignment</p></a></div>
      <div class="col-md-4 col-lg-3"><a class="admin-info-card" href="<?= get_base_path() ?>/settings.php?tab=email"><div class="d-flex align-items-center gap-2 mb-2"><i data-lucide="mail" style="width:18px;height:18px;color:var(--admin-primary)"></i><strong>SMTP</strong></div><p class="small text-muted mb-0">Canonical email settings</p></a></div>
      <div class="col-md-4 col-lg-3"><a class="admin-info-card" href="<?= get_base_path() ?>/settings.php?tab=history"><div class="d-flex align-items-center gap-2 mb-2"><i data-lucide="history" style="width:18px;height:18px;color:var(--admin-primary)"></i><strong>Audit</strong></div><p class="small text-muted mb-0">Activity center</p></a></div>
      <div class="col-md-4 col-lg-3"><a class="admin-info-card" href="<?= get_base_path() ?>/settings.php?tab=module_hub"><div class="d-flex align-items-center gap-2 mb-2"><i data-lucide="external-link" style="width:18px;height:18px;color:var(--admin-primary)"></i><strong>Module links</strong></div><p class="small text-muted mb-0">HR, RE, Construction, ARS</p></a></div>
      <div class="col-md-4 col-lg-3"><a class="admin-info-card" href="<?= get_base_path() ?>/settings.php?tab=branding"><div class="d-flex align-items-center gap-2 mb-2"><i data-lucide="palette" style="width:18px;height:18px;color:var(--admin-primary)"></i><strong>Branding</strong></div><p class="small text-muted mb-0">Global look &amp; feel</p></a></div>
    </div>
    <div class="admin-settings-card">
      <div class="settings-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0">Recent activity</h5>
          <small class="text-muted">Latest events across the ERP</small>
        </div>
        <?php if (has_role('Owner', $conn)): ?>
          <a href="<?= get_base_path() ?>/settings.php?tab=history" class="btn btn-sm btn-outline-secondary">Open Audit</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($adminDash['recent'])): ?>
          <?= admin_ui_empty('No recent activity yet.') ?>
        <?php else: ?>
          <div class="admin-table-shell border-0 shadow-none rounded-0">
            <table class="table table-hover mb-0">
              <thead><tr><th>When</th><th>Who</th><th>Action</th><th>What happened</th></tr></thead>
              <tbody>
              <?php foreach ($adminDash['recent'] as $ev): ?>
                <tr>
                  <td><small><?= h(date('d/m/Y H:i', strtotime((string)$ev['created_at']))) ?></small></td>
                  <td><?= h($ev['user_name'] ?: 'Not recorded') ?></td>
                  <td><span class="admin-pill admin-pill-gold"><?= h($ev['action']) ?></span></td>
                  <td class="admin-activity-summary"><?= h($ev['summary'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Company Information Tab -->
  <?php if ($tab === 'company'): ?>
    <?= admin_ui_page_header('Company Information', 'Profile for the selected company', [
      ['label' => 'Administration', 'href' => get_base_path() . '/settings.php?tab=dashboard'],
      ['label' => 'Company Info'],
    ], admin_ui_scope_badge('Company')) ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-building"></i> Company Information <span class="badge text-bg-secondary">Company</span></h5>
        <small class="text-muted">Profile for the selected company (not a global singleton)</small>
      </div>
      <div class="card-body">
        <?php if ($settingsCompanyId <= 0): ?>
          <div class="alert alert-warning mb-0">No company selected. Switch company in the header, then return here.</div>
        <?php else: ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <span class="text-muted">Editing:</span>
          <strong><?= h($settingsCompanyName !== '' ? $settingsCompanyName : ('Company #' . $settingsCompanyId)) ?></strong>
          <?php if (!empty($allCompanies) && (has_role('Owner', $conn) || has_role('Admin', $conn))): ?>
          <form method="get" class="d-flex gap-2 ms-md-auto">
            <input type="hidden" name="tab" value="company">
            <select name="settings_company_id" class="form-select form-select-sm" onchange="this.form.submit()">
              <?php foreach ($allCompanies as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $settingsCompanyId ? 'selected' : '' ?>>
                  <?= h($c['name']) ?> (<?= h($c['business_type'] ?? '') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </form>
          <?php endif; ?>
        </div>
        <form method="POST" data-admin-unsaved>
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="update_company">
          <input type="hidden" name="settings_company_id" value="<?= (int)$settingsCompanyId ?>">
          <input type="hidden" name="tab" value="company">
          
          <!-- Company Details Section -->
          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2"><i class="bi bi-building"></i> Company Details</h6>
            </div>
            <div class="col-md-6">
              <label class="form-label">Legal Name *</label>
              <input type="text" name="legal_name" class="form-control" 
                     value="<?= h($companySettings['legal_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Trade Name</label>
              <input type="text" name="trade_name" class="form-control" 
                     value="<?= h($companySettings['trade_name'] ?? '') ?>" 
                     placeholder="Trading name or DBA">
            </div>
            <div class="col-md-6">
              <label class="form-label">Tax Registration Number (TRN)</label>
              <input type="text" name="trn" class="form-control" 
                     value="<?= h($companySettings['trn'] ?? '') ?>" 
                     placeholder="UAE TRN number">
            </div>
            <div class="col-md-6">
              <label class="form-label">Currency</label>
              <select name="currency_code" class="form-select">
                <option value="AED" <?= ($companySettings['currency_code'] ?? 'AED') === 'AED' ? 'selected' : '' ?>>AED - UAE Dirham</option>
                <option value="USD" <?= ($companySettings['currency_code'] ?? '') === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                <option value="EUR" <?= ($companySettings['currency_code'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
              </select>
            </div>
          </div>

          <!-- Address Section -->
          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2"><i class="bi bi-geo-alt"></i> Address Information</h6>
            </div>
            <div class="col-md-8">
              <label class="form-label">Address Line 1</label>
              <input type="text" name="address_line1" class="form-control" 
                     value="<?= h($companySettings['address_line1'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Address Line 2</label>
              <input type="text" name="address_line2" class="form-control" 
                     value="<?= h($companySettings['address_line2'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">City</label>
              <input type="text" name="city" class="form-control" 
                     value="<?= h($companySettings['city'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">State/Region</label>
              <input type="text" name="state_region" class="form-control" 
                     value="<?= h($companySettings['state_region'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Postal Code</label>
              <input type="text" name="postcode" class="form-control" 
                     value="<?= h($companySettings['postcode'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Country</label>
              <input type="text" name="country" class="form-control" 
                     value="<?= h($companySettings['country'] ?? '') ?>">
            </div>
          </div>

          <!-- Contact Information -->
          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2"><i class="bi bi-telephone"></i> Contact Information</h6>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-control" 
                     value="<?= h($companySettings['phone'] ?? '') ?>"
                     placeholder="+971 4 123 4567">
              <small class="text-muted">Shown as Office contact in the Customer App Account screen.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" 
                     value="<?= h($companySettings['email'] ?? '') ?>"
                     placeholder="info@example.com">
              <small class="text-muted">Support email for the Customer App.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">WhatsApp</label>
              <input type="tel" name="whatsapp" class="form-control"
                     value="<?= h($companySettings['whatsapp'] ?? '') ?>"
                     placeholder="+971 50 123 4567">
              <small class="text-muted">WhatsApp number for the Customer App Support section (include country code).</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Website</label>
              <input type="url" name="website" class="form-control" 
                     value="<?= h($companySettings['website'] ?? '') ?>" 
                     placeholder="https://yourcompany.com">
            </div>
          </div>

          <!-- Banking Information -->
          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2"><i class="bi bi-bank"></i> Banking Information</h6>
            </div>
            <div class="col-md-6">
              <label class="form-label">Bank Name</label>
              <input type="text" name="bank_name" class="form-control" 
                     value="<?= h($companySettings['bank_name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Account Number</label>
              <input type="text" name="bank_account_no" class="form-control" 
                     value="<?= h($companySettings['bank_account_no'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">IBAN</label>
              <input type="text" name="bank_iban" class="form-control" 
                     value="<?= h($companySettings['bank_iban'] ?? '') ?>" 
                     placeholder="AE123456789012345678901">
            </div>
            <div class="col-md-6">
              <label class="form-label">SWIFT Code</label>
              <input type="text" name="bank_swift" class="form-control" 
                     value="<?= h($companySettings['bank_swift'] ?? '') ?>" 
                     placeholder="DIBAAEAD">
            </div>
          </div>

          <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary btn-lg">
              <i class="bi bi-check-circle"></i> Save Company Information
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Employee Profile Settings Tab -->
  <?php if ($tab === 'emp_profile'): ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-stars"></i> Employee Recognition & Profile Widgets</h5>
        <small class="text-muted">Control what workers see on their profile page and manage the Employee of the Month showcase.</small>
      </div>
      <div class="card-body">
        <form method="POST" data-admin-unsaved>
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="save_emp_profile">

          <h6 class="text-primary mb-3"><i class="bi bi-toggle-on me-2"></i>Feature Visibility</h6>
          <div class="feature-toggle-grid mb-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="featureStats" name="feature_show_stats" <?= $empProfileFlags['show_stats'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="featureStats">
                <strong>Monthly Snapshot</strong><br>
                <small class="text-muted">Shows this month’s hours, leave balance, and attendance streak cards.</small>
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="featureProgress" name="feature_show_progress" <?= $empProfileFlags['show_progress'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="featureProgress">
                <strong>Progress Bar</strong><br>
                <small class="text-muted">Displays progress toward the next award based on target monthly hours.</small>
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="featureRewards" name="feature_show_rewards" <?= $empProfileFlags['show_rewards'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="featureRewards">
                <strong>Reward Tracker</strong><br>
                <small class="text-muted">Checklist so employees know which perks have been delivered.</small>
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="featureKudos" name="feature_show_kudos" <?= $empProfileFlags['show_kudos'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="featureKudos">
                <strong>Peer Kudos Feed</strong><br>
                <small class="text-muted">Shows encouraging notes shared by supervisors and teammates.</small>
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="featureHistory" name="feature_show_history" <?= $empProfileFlags['show_history'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="featureHistory">
                <strong>Award History</strong><br>
                <small class="text-muted">Carousel of previous awardees to keep everyone inspired.</small>
              </label>
            </div>
          </div>

          <h6 class="text-primary mb-3"><i class="bi bi-trophy me-2"></i>Current Employee of the Month</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label">Employee</label>
              <select name="employee_of_month_id" class="form-select">
                <option value="0">-- Not assigned --</option>
                <?php $selectedEom = (int)getSetting($conn, 'employee_of_month_id', '0'); ?>
                <?php foreach ($employeesList as $empRow): ?>
                  <?php
                    $label = trim($empRow['full_name']) ?: 'Employee #' . (int)$empRow['id'];
                    if (!empty($empRow['employee_code'])) {
                      $label .= ' (' . $empRow['employee_code'] . ')';
                    }
                  ?>
                  <option value="<?= (int)$empRow['id'] ?>" <?= $selectedEom === (int)$empRow['id'] ? 'selected' : '' ?>>
                    <?= h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Month</label>
              <input type="month" name="employee_of_month_month" class="form-control"
                     value="<?= h(date('Y-m', strtotime($currentAwardMonthDate))) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Target Hours</label>
              <input type="number" name="employee_of_month_target_hours" class="form-control"
                     min="0" step="0.5"
                     value="<?= h(getSetting($conn, 'employee_of_month_target_hours', '160')) ?>">
              <div class="form-text">Used for the progress bar.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Headline</label>
              <input type="text" name="employee_of_month_headline" class="form-control"
                     value="<?= h($currentAward['headline'] ?? 'Employee of the Month') ?>"
                     maxlength="180">
            </div>
            <div class="col-md-6">
              <label class="form-label">Celebration Message</label>
              <textarea name="employee_of_month_message" class="form-control" rows="3" maxlength="500"
                        placeholder="🎉 Congratulations {name}! You're our Employee of the Month! 🌟"><?= h(getSetting($conn, 'employee_of_month_message', "🎉 Congratulations {name}! You're our Employee of the Month! 🌟")) ?></textarea>
              <div class="form-text">Use <code>{name}</code> to insert the employee's name automatically.</div>
            </div>
          </div>

          <h6 class="text-primary mb-3"><i class="bi bi-list-check me-2"></i>Reward Checklist Defaults</h6>
          <p class="text-muted small">Set the default checklist items for rewards (e.g. certificate, bonus). These will be created for each new award. Leave blanks to add new lines.</p>
          <div class="input-stack mb-2">
            <?php
              $rewardInputs = $rewardDefaultItems;
              $rewardInputs[] = '';
              $rewardInputs[] = '';
            ?>
            <?php foreach ($rewardInputs as $idx => $label): ?>
              <input type="text" class="form-control" name="reward_defaults[]" value="<?= h($label) ?>" maxlength="120"
                     placeholder="Reward item e.g. Certificate sent">
            <?php endforeach; ?>
          </div>
          <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" value="1" id="pruneChecklist" name="prune_unused_checklist">
            <label class="form-check-label" for="pruneChecklist">
              Remove checklist items from the current award if they are not in the list above.
            </label>
          </div>

          <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save me-2"></i>Save Recognition Settings
            </button>
          </div>
        </form>
      </div>
    </div>

    <?php if ($currentAward): ?>
    <div class="settings-card">
      <div class="settings-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0"><i class="bi bi-box-seam"></i> Reward Tracker for <?= h($currentAward['full_name'] ?? 'Employee') ?></h5>
          <small class="text-muted"><?= h(date('F Y', strtotime($currentAward['award_month']))) ?> • <?= h($currentAward['headline'] ?? 'Employee of the Month') ?></small>
        </div>
        <?php if (!empty($currentAward['target_hours'])): ?>
          <span class="badge bg-primary-subtle text-primary">
            Target: <?= (float)$currentAward['target_hours'] ?> hrs
          </span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($currentAwardChecklists): ?>
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_award_checklist">
            <input type="hidden" name="award_id" value="<?= (int)$currentAward['id'] ?>">
            <div class="checklist-grid mb-3">
              <?php foreach ($currentAwardChecklists as $item): ?>
                <div class="form-check">
                  <input type="hidden" name="checklist[<?= (int)$item['id'] ?>]" value="0">
                  <input class="form-check-input" type="checkbox" value="1"
                         id="checklist<?= (int)$item['id'] ?>"
                         name="checklist[<?= (int)$item['id'] ?>]"
                         <?= $item['is_done'] ? 'checked' : '' ?>>
                  <label class="form-check-label" for="checklist<?= (int)$item['id'] ?>">
                    <?= h($item['item_label']) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-outline-primary">
              <i class="bi bi-check2-square me-2"></i>Update Checklist
            </button>
          </form>
        <?php else: ?>
          <p class="text-muted mb-0">No checklist items yet. Add defaults above and save to generate them for this award.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="settings-card h-100">
          <div class="settings-header">
            <h5 class="mb-0"><i class="bi bi-chat-heart"></i> Peer Kudos</h5>
            <small class="text-muted">Share shout-outs that appear on worker profiles.</small>
          </div>
          <div class="card-body">
            <form method="POST" class="mb-4">
              <?php csrf_field(); ?>
              <input type="hidden" name="action" value="add_kudos">
              <div class="mb-3">
                <label class="form-label">Employee</label>
                <select name="kudos_employee_id" class="form-select" required>
                  <option value="">-- Select --</option>
                  <?php foreach ($employeesList as $empRow): ?>
                    <?php
                      $label = trim($empRow['full_name']) ?: 'Employee #' . (int)$empRow['id'];
                      if (!empty($empRow['employee_code'])) {
                          $label .= ' (' . $empRow['employee_code'] . ')';
                      }
                    ?>
                    <option value="<?= (int)$empRow['id'] ?>">
                      <?= h($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Message</label>
                <textarea name="kudos_message" class="form-control" rows="3" maxlength="300" required placeholder="Thank you for going the extra mile on Project Falcon!"></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label">Visibility</label>
                <select name="kudos_visibility" class="form-select">
                  <option value="public">Visible to worker</option>
                  <option value="private">Internal note</option>
                </select>
              </div>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-send-heart me-2"></i>Post Kudos
              </button>
            </form>

            <h6 class="text-muted mb-3">Recent Kudos</h6>
            <div class="kudos-list">
              <?php if ($recentKudos): ?>
                <?php foreach ($recentKudos as $kudos): ?>
                  <div class="kudos-item">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <strong><?= h($kudos['employee_name']) ?></strong>
                        <div class="small text-muted">
                          <?= h(date('M j, g:i A', strtotime($kudos['created_at']))) ?>
                          <?php if ($kudos['visibility'] === 'private'): ?>
                            • <span class="badge bg-secondary">Private</span>
                          <?php endif; ?>
                        </div>
                        <p class="mb-1 mt-2"><?= nl2br(h($kudos['message'])) ?></p>
                        <div class="small text-muted">
                          From <?= h($kudos['author_name'] ?: $kudos['author_username'] ?: 'System') ?>
                        </div>
                      </div>
                      <form method="POST" class="ms-2">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_kudos">
                        <input type="hidden" name="kudos_id" value="<?= (int)$kudos['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove kudos">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="text-muted">No kudos yet. Start by sharing a thank you above.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="settings-card h-100">
          <div class="settings-header">
            <h5 class="mb-0"><i class="bi bi-journal-richtext"></i> Announcement History</h5>
            <small class="text-muted">Preview of previous Employee of the Month awards.</small>
          </div>
          <div class="card-body">
            <?php if ($awardHistory): ?>
              <div class="award-history-scroll">
                <?php foreach ($awardHistory as $award): ?>
                  <div class="award-card">
                    <div class="d-flex justify-content-between align-items-start">
                      <span class="badge bg-warning text-dark"><i class="bi bi-trophy-fill me-1"></i><?= h(date('M Y', strtotime($award['award_month']))) ?></span>
                      <?php if ((int)$award['employee_id'] === (int)$selectedEom): ?>
                        <span class="badge bg-success-subtle text-success">Active</span>
                      <?php endif; ?>
                    </div>
                    <h6 class="mt-3 mb-1"><?= h($award['full_name']) ?></h6>
                    <p class="mb-2 text-muted small"><?= h($award['headline'] ?? 'Employee of the Month') ?></p>
                    <?php if (!empty($award['message'])): ?>
                      <p class="small"><?= nl2br(h(mb_strimwidth($award['message'], 0, 180, '…'))) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($award['target_hours'])): ?>
                      <p class="small text-muted mb-0"><i class="bi bi-speedometer2 me-1"></i>Target <?= (float)$award['target_hours'] ?> hrs</p>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="text-muted mb-0">Once you start announcing Employees of the Month, their history will appear here.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- User Management Tab -->
  <?php if ($tab === 'users'): ?>
    <?= admin_ui_page_header('Users', 'Accounts, roles, and company access', [
      ['label' => 'Administration', 'href' => get_base_path() . '/settings.php?tab=dashboard'],
      ['label' => 'Users'],
    ], admin_ui_scope_badge('Access')) ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-people"></i> User Management</h5>
        <small class="text-muted">Manage user accounts, roles, and permissions</small>
      </div>
      <div class="card-body">
        <?php if (empty($users)): ?>
          <div class="text-center py-5">
            <i class="bi bi-person-x text-muted" style="font-size: 3rem;"></i>
            <h5 class="text-muted mt-3">No Users Found</h5>
            <p class="text-muted">No active users found in the system.</p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th style="width: 30%;">User Details</th>
                  <th style="width: 20%;">Contact</th>
                  <th style="width: 30%;">Roles & Permissions</th>
                  <th style="width: 20%;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $user): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center">
                        <div class="avatar-circle me-3">
                          <i class="bi bi-person-fill"></i>
                        </div>
                        <div>
                          <div class="fw-bold text-dark"><?= h($user['fullname']) ?></div>
                          <small class="text-muted">@<?= h($user['username']) ?></small>
                          <div class="mt-1">
                            <span class="badge <?= $user['status'] ? 'bg-success' : 'bg-secondary' ?>">
                              <?= $user['status'] ? 'Active' : 'Inactive' ?>
                            </span>
                          </div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="text-truncate">
                        <i class="bi bi-envelope me-1"></i>
                        <?= h($user['email'] ?: 'No email') ?>
                      </div>
                      <?php if ($user['contactnumber']): ?>
                        <div class="text-truncate text-muted small">
                          <i class="bi bi-phone me-1"></i>
                          <?= h($user['contactnumber']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($user['roles']): ?>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                          <?php foreach (explode(',', $user['roles']) as $role): ?>
                            <span class="badge bg-primary"><?= h(trim($role)) ?></span>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <span class="text-muted small mb-2 d-block">
                          <i class="bi bi-exclamation-triangle me-1"></i>
                          No roles assigned
                        </span>
                      <?php endif; ?>
                      
                      <?php 
                      $userCompanies = [];
                      if (!empty($user['companies'])) {
                          foreach (explode('||', $user['companies']) as $comp) {
                              if ($comp) {
                                  $parts = explode(':', $comp);
                                  if (count($parts) >= 3) {
                                      $userCompanies[] = [
                                          'id' => $parts[0],
                                          'name' => $parts[1],
                                          'business_type' => $parts[2],
                                          'is_primary' => isset($parts[3]) ? (int)$parts[3] : 0
                                      ];
                                  }
                              }
                          }
                      }
                      ?>
                      <?php if (!empty($userCompanies)): ?>
                        <div class="d-flex flex-wrap gap-1">
                          <?php foreach ($userCompanies as $uc): ?>
                            <span class="badge <?= $uc['is_primary'] ? 'bg-success' : 'bg-secondary' ?>" title="<?= $uc['is_primary'] ? 'Primary Company' : '' ?>">
                              <i class="bi bi-building me-1"></i><?= h($uc['name']) ?> 
                              <small>(<?= h($uc['business_type']) ?>)</small>
                              <?php if ($uc['is_primary']): ?>
                                <i class="bi bi-star-fill ms-1" style="font-size: 0.7rem;"></i>
                              <?php endif; ?>
                            </span>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <span class="text-muted small">
                          <i class="bi bi-exclamation-triangle me-1"></i>
                          No companies assigned
                        </span>
                      <?php endif; ?>
                      <div class="mt-2">
                        <?php if (!empty($user['task_can_admin_view'])): ?>
                          <span class="badge bg-danger"><i class="bi bi-key-fill"></i> Tasks Admin</span>
                        <?php elseif (!empty($user['task_can_view'])): ?>
                          <span class="badge bg-success"><i class="bi bi-list-check"></i> Tasks Access</span>
                        <?php else: ?>
                          <span class="badge bg-light text-dark border"><i class="bi bi-lock"></i> No Tasks Access</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div class="btn-group" role="group">
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                data-bs-target="#roleModal" data-user-id="<?= $user['id'] ?>" 
                                data-user-name="<?= h($user['fullname']) ?>" 
                                data-user-roles="<?= h($user['roles']) ?>">
                          <i class="bi bi-person-gear"></i> Roles
                        </button>
                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" 
                                data-bs-target="#companyModal" data-user-id="<?= $user['id'] ?>" 
                                data-user-name="<?= h($user['fullname']) ?>"
                                data-user-companies="<?= h(json_encode($userCompanies)) ?>">
                          <i class="bi bi-building"></i> Companies
                        </button>
                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal"
                                data-bs-target="#taskAccessModal" data-user-id="<?= $user['id'] ?>"
                                data-user-name="<?= h($user['fullname']) ?>"
                                data-task-can-view="<?= (int)$user['task_can_view'] ?>"
                                data-task-can-admin="<?= (int)$user['task_can_admin_view'] ?>">
                          <i class="bi bi-list-check"></i> Tasks
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" disabled>
                          <i class="bi bi-pencil"></i> Edit
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Role Assignment Modal -->
    <div class="modal fade" id="roleModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title">
              <i class="bi bi-person-gear"></i> Role Assignment
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="assign_roles">
            <input type="hidden" name="user_id" id="modal-user-id">
            <div class="modal-body">
              <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Assigning roles to: <strong id="modal-user-name"></strong>
              </div>
              
              <h6 class="mb-3">Available Roles:</h6>
              <div class="row">
                <?php foreach ($allRoles as $role): ?>
                  <div class="col-md-6 mb-3">
                    <div class="card h-100">
                      <div class="card-body">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="roles[]" 
                                 value="<?= h($role['name']) ?>" id="role-<?= $role['id'] ?>">
                          <label class="form-check-label w-100" for="role-<?= $role['id'] ?>">
                            <div class="fw-bold"><?= h($role['name']) ?></div>
                            <?php if ($role['description']): ?>
                              <small class="text-muted"><?= h($role['description']) ?></small>
                            <?php endif; ?>
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                <i class="bi bi-x-circle"></i> Cancel
              </button>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle"></i> Save Role Assignment
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Task Access Modal -->
    <div class="modal fade" id="taskAccessModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title"><i class="bi bi-list-check"></i> Task Access</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="assign_task_access">
            <input type="hidden" name="user_id" id="task-modal-user-id">
            <div class="modal-body">
              <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Configure task access for: <strong id="task-modal-user-name"></strong>
              </div>
              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="task_can_view" id="task-can-view">
                <label class="form-check-label" for="task-can-view">
                  <strong>Can access shared Tasks module</strong>
                  <div class="text-muted small">Allows opening <code>/modules/tasks/tasks.php</code> without full Real Estate module access.</div>
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="task_can_admin_view" id="task-can-admin">
                <label class="form-check-label" for="task-can-admin">
                  <strong>Can view all tasks (Tasks Admin)</strong>
                  <div class="text-muted small">Enables all-company task visibility inside task screens.</div>
                </label>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Save Task Access</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Company Assignment Modal -->
    <div class="modal fade" id="companyModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header bg-info text-white">
            <h5 class="modal-title">
              <i class="bi bi-building"></i> Company Assignment
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="assign_companies">
            <input type="hidden" name="user_id" id="company-modal-user-id">
            <div class="modal-body">
              <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Assigning companies to: <strong id="company-modal-user-name"></strong>
              </div>
              
              <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Important:</strong> Users can be assigned to multiple companies (e.g., Cleaning and Real Estate). 
                Select one as the primary company.
              </div>
              
              <h6 class="mb-3">Available Companies:</h6>
              <div class="row">
                <?php foreach ($allCompanies as $company): ?>
                  <div class="col-md-6 mb-3">
                    <div class="card h-100">
                      <div class="card-body">
                        <div class="form-check">
                          <input class="form-check-input company-checkbox" type="checkbox" name="companies[]" 
                                 value="<?= (int)$company['id'] ?>" id="company-<?= $company['id'] ?>"
                                 data-company-id="<?= (int)$company['id'] ?>">
                          <label class="form-check-label w-100" for="company-<?= $company['id'] ?>">
                            <div class="fw-bold"><?= h($company['name']) ?></div>
                            <small class="text-muted"><?= h(ucfirst($company['business_type'])) ?></small>
                          </label>
                        </div>
                        <div class="mt-2">
                          <div class="form-check">
                            <input class="form-check-input primary-company-radio" type="radio" 
                                   name="primary_company_id" value="<?= (int)$company['id'] ?>" 
                                   id="primary-<?= $company['id'] ?>" disabled>
                            <label class="form-check-label small" for="primary-<?= $company['id'] ?>">
                              Set as Primary
                            </label>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                <i class="bi bi-x-circle"></i> Cancel
              </button>
              <button type="submit" class="btn btn-info">
                <i class="bi bi-check-circle"></i> Save Company Assignment
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
      // Role Modal Handler
      document.getElementById('roleModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const userId = button.getAttribute('data-user-id');
        const userName = button.getAttribute('data-user-name');
        const userRoles = button.getAttribute('data-user-roles') || '';
        
        document.getElementById('modal-user-id').value = userId;
        document.getElementById('modal-user-name').textContent = userName;
        
        // Clear all checkboxes
        this.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        
        // Check user's current roles
        if (userRoles) {
          const roles = userRoles.split(',').map(r => r.trim());
          roles.forEach(role => {
            const checkbox = this.querySelector(`input[value="${role}"]`);
            if (checkbox) checkbox.checked = true;
          });
        }
      });

      // Company Modal Handler
      document.getElementById('companyModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const userId = button.getAttribute('data-user-id');
        const userName = button.getAttribute('data-user-name');
        const userCompaniesJson = button.getAttribute('data-user-companies') || '[]';
        
        document.getElementById('company-modal-user-id').value = userId;
        document.getElementById('company-modal-user-name').textContent = userName;
        
        // Clear all checkboxes and radios
        this.querySelectorAll('.company-checkbox').forEach(cb => {
          cb.checked = false;
          cb.closest('.card').querySelector('.primary-company-radio').disabled = true;
        });
        this.querySelectorAll('.primary-company-radio').forEach(rb => rb.checked = false);
        
        // Set user's current companies
        try {
          const userCompanies = JSON.parse(userCompaniesJson);
          userCompanies.forEach(uc => {
            const checkbox = this.querySelector(`#company-${uc.id}`);
            const radio = this.querySelector(`#primary-${uc.id}`);
            if (checkbox) {
              checkbox.checked = true;
              if (radio) {
                radio.disabled = false;
                if (uc.is_primary) {
                  radio.checked = true;
                }
              }
            }
          });
        } catch (e) {
          console.error('Error parsing user companies:', e);
        }
      });

      // Task Access Modal Handler
      document.getElementById('taskAccessModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const userId = button.getAttribute('data-user-id');
        const userName = button.getAttribute('data-user-name');
        const canView = button.getAttribute('data-task-can-view') === '1';
        const canAdmin = button.getAttribute('data-task-can-admin') === '1';

        document.getElementById('task-modal-user-id').value = userId;
        document.getElementById('task-modal-user-name').textContent = userName;
        document.getElementById('task-can-view').checked = canView;
        document.getElementById('task-can-admin').checked = canAdmin;
      });

      document.getElementById('task-can-admin')?.addEventListener('change', function() {
        if (this.checked) {
          const v = document.getElementById('task-can-view');
          if (v) v.checked = true;
        }
      });

      // Enable/disable primary radio when checkbox is toggled
      document.querySelectorAll('.company-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
          const radio = this.closest('.card').querySelector('.primary-company-radio');
          if (radio) {
            radio.disabled = !this.checked;
            if (!this.checked) {
              radio.checked = false;
            }
          }
        });
      });
    </script>
  <?php endif; ?>

  <!-- Roles Management Tab -->
  <?php if ($tab === 'roles'): ?>
    <?php
    require_once __DIR__ . '/includes/permissions.php';

    // Get all roles with user counts
    $roles = $conn->query("
        SELECT r.id, r.name, r.description, r.module, r.is_system,
               COUNT(DISTINCT ur.user_id) as user_count
        FROM roles r
        LEFT JOIN user_roles ur ON ur.role_id = r.id
        GROUP BY r.id
        ORDER BY r.is_system DESC, r.module, r.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $payrollOverrideByRole = [];
    try {
        $ovRows = $conn->query("
            SELECT role_id, permissions
            FROM role_modules
            WHERE module = 'hr'
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ovRows as $ovRow) {
            $decoded = json_decode((string)($ovRow['permissions'] ?? ''), true);
            $payroll = is_array($decoded) ? ($decoded['payroll'] ?? []) : [];
            if (is_array($payroll) && in_array('override_validation', $payroll, true)) {
                $payrollOverrideByRole[(int)$ovRow['role_id']] = true;
            }
        }
    } catch (Throwable $e) {
        $payrollOverrideByRole = [];
    }
    
    $modules = [
        '' => 'No Module',
        'cleaning' => 'Cleaning',
        'realestate' => 'Real Estate',
        'legal' => 'Legal Department',
        'construction' => 'Construction',
        'ars' => 'ARS Home Rentals',
        'hr' => 'HR',
        'finance' => 'Finance',
        'inventory' => 'Inventory',
        'grocery' => 'Grocery',
        'barber' => 'Barber shop',
        'core' => 'Core'
    ];
    ?>
    
    <div class="settings-card">
      <div class="settings-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0"><i class="bi bi-shield-check"></i> Roles Management</h5>
          <small class="text-muted">Create, edit, and manage system roles</small>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRoleModal">
          <i class="bi bi-plus-circle"></i> Create New Role
        </button>
      </div>
      <div class="card-body">
        <?php if (empty($roles)): ?>
          <div class="text-center py-5">
            <i class="bi bi-shield-x text-muted" style="font-size: 3rem;"></i>
            <h5 class="text-muted mt-3">No Roles Found</h5>
            <p class="text-muted">Create your first role to get started.</p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th style="width: 22%;">Role Name</th>
                  <th style="width: 24%;">Description</th>
                  <th style="width: 12%;">Module</th>
                  <th style="width: 10%;">Type</th>
                  <th style="width: 12%;">Payroll</th>
                  <th style="width: 10%;">Users</th>
                  <th style="width: 10%;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($roles as $role): ?>
                  <?php $roleHasPayrollOverride = !empty($payrollOverrideByRole[(int)$role['id']]); ?>
                  <tr>
                    <td>
                      <div class="fw-bold"><?= h($role['name']) ?></div>
                      <?php if ($role['is_system']): ?>
                        <small class="text-muted">System Role</small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="text-muted small">
                        <?= h($role['description'] ?: 'No description') ?>
                      </div>
                    </td>
                    <td>
                      <?php if ($role['module']): ?>
                        <span class="badge bg-info"><?= h($modules[$role['module']] ?? ucfirst($role['module'])) ?></span>
                      <?php else: ?>
                        <span class="badge bg-secondary">No Module</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($role['is_system']): ?>
                        <span class="badge bg-warning text-dark">
                          <i class="bi bi-lock-fill"></i> System
                        </span>
                      <?php else: ?>
                        <span class="badge bg-success">Custom</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($roleHasPayrollOverride): ?>
                        <span class="badge text-bg-danger" title="Can override payroll validation guards">Override Validation</span>
                      <?php else: ?>
                        <span class="text-muted small">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge bg-primary"><?= (int)$role['user_count'] ?> user(s)</span>
                    </td>
                    <td>
                      <div class="btn-group" role="group">
                        <button class="btn btn-sm btn-outline-primary" 
                                data-bs-toggle="modal" 
                                data-bs-target="#editRoleModal"
                                data-role-id="<?= $role['id'] ?>"
                                data-role-name="<?= h($role['name']) ?>"
                                data-role-description="<?= h($role['description'] ?? '') ?>"
                                data-role-module="<?= h($role['module'] ?? '') ?>"
                                data-role-system="<?= $role['is_system'] ?>"
                                data-role-payroll-override="<?= $roleHasPayrollOverride ? '1' : '0' ?>">
                          <i class="bi bi-pencil"></i> Edit
                        </button>
                        <?php if (!$role['is_system']): ?>
                          <button class="btn btn-sm btn-outline-danger" 
                                  data-bs-toggle="modal" 
                                  data-bs-target="#deleteRoleModal"
                                  data-role-id="<?= $role['id'] ?>"
                                  data-role-name="<?= h($role['name']) ?>"
                                  data-role-users="<?= (int)$role['user_count'] ?>">
                            <i class="bi bi-trash"></i> Delete
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Create Role Modal -->
    <div class="modal fade" id="createRoleModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title">
              <i class="bi bi-plus-circle"></i> Create New Role
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create_role">
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label">Role Name <span class="text-danger">*</span></label>
                <input type="text" name="role_name" class="form-control" required 
                       placeholder="e.g., Sales Manager, Operations Lead">
              </div>
              <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="role_description" class="form-control" rows="3" 
                          placeholder="Brief description of this role's responsibilities"></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label">Module</label>
                <select name="role_module" class="form-select">
                  <option value="">-- No Module (General) --</option>
                  <?php foreach ($modules as $key => $label): ?>
                    <?php if ($key !== ''): ?>
                      <option value="<?= h($key) ?>"><?= h($label) ?></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted">Assign this role to a specific module (optional)</small>
              </div>
              <div class="border rounded p-3 bg-light">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="payroll_override_validation" value="1" id="create-payroll-override">
                  <label class="form-check-label" for="create-payroll-override">
                    <strong>Allow Payroll Validation Override</strong>
                  </label>
                </div>
                <div class="small text-muted mt-1">
                  Users with this role can save/post payroll even when take-home / WPS guards fail.
                  Override still requires a reason and is fully audited. Owner/Admin already have this ability.
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                <i class="bi bi-x-circle"></i> Cancel
              </button>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle"></i> Create Role
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Edit Role Modal -->
    <div class="modal fade" id="editRoleModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title">
              <i class="bi bi-pencil"></i> Edit Role
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_role">
            <input type="hidden" name="role_id" id="edit-role-id">
            <div class="modal-body">
              <div class="alert alert-warning" id="edit-system-warning" style="display: none;">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>System Role:</strong> Name, description, and module are locked.
                You can still change the Payroll Validation Override permission below.
              </div>
              <div class="mb-3">
                <label class="form-label">Role Name <span class="text-danger">*</span></label>
                <input type="text" name="role_name" id="edit-role-name" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="role_description" id="edit-role-description" class="form-control" rows="3"></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label">Module</label>
                <select name="role_module" id="edit-role-module" class="form-select">
                  <option value="">-- No Module (General) --</option>
                  <?php foreach ($modules as $key => $label): ?>
                    <?php if ($key !== ''): ?>
                      <option value="<?= h($key) ?>"><?= h($label) ?></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="border rounded p-3 bg-light">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="payroll_override_validation" value="1" id="edit-payroll-override">
                  <label class="form-check-label" for="edit-payroll-override">
                    <strong>Allow Payroll Validation Override</strong>
                  </label>
                </div>
                <div class="small text-muted mt-1">
                  Users with this role can save/post payroll even when take-home / WPS guards fail.
                  Override still requires a reason and is fully audited. Owner/Admin already have this ability.
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                <i class="bi bi-x-circle"></i> Cancel
              </button>
              <button type="submit" class="btn btn-primary" id="edit-role-submit">
                <i class="bi bi-check-circle"></i> Save Changes
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Delete Role Modal -->
    <div class="modal fade" id="deleteRoleModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title">
              <i class="bi bi-exclamation-triangle"></i> Delete Role
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="delete_role">
            <input type="hidden" name="role_id" id="delete-role-id">
            <div class="modal-body">
              <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong>Warning:</strong> This action cannot be undone!
              </div>
              <p>Are you sure you want to delete the role <strong id="delete-role-name"></strong>?</p>
              <div id="delete-role-warning" style="display: none;">
                <div class="alert alert-warning">
                  <i class="bi bi-info-circle"></i>
                  This role is currently assigned to <strong id="delete-role-users"></strong> user(s). 
                  You must remove all user assignments before deleting this role.
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                <i class="bi bi-x-circle"></i> Cancel
              </button>
              <button type="submit" class="btn btn-danger" id="delete-role-submit">
                <i class="bi bi-trash"></i> Delete Role
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
      // Edit Role Modal Handler
      document.getElementById('editRoleModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const roleId = button.getAttribute('data-role-id');
        const roleName = button.getAttribute('data-role-name');
        const roleDescription = button.getAttribute('data-role-description');
        const roleModule = button.getAttribute('data-role-module');
        const isSystem = button.getAttribute('data-role-system') === '1';
        const payrollOverride = button.getAttribute('data-role-payroll-override') === '1';
        
        document.getElementById('edit-role-id').value = roleId;
        document.getElementById('edit-role-name').value = roleName;
        document.getElementById('edit-role-description').value = roleDescription || '';
        document.getElementById('edit-role-module').value = roleModule || '';
        const overrideChk = document.getElementById('edit-payroll-override');
        if (overrideChk) overrideChk.checked = payrollOverride;
        
        const warningDiv = document.getElementById('edit-system-warning');
        const submitBtn = document.getElementById('edit-role-submit');
        const nameInput = document.getElementById('edit-role-name');
        const descInput = document.getElementById('edit-role-description');
        const moduleSelect = document.getElementById('edit-role-module');
        
        if (isSystem) {
          warningDiv.style.display = 'block';
          submitBtn.disabled = false; // permission checkbox can still be saved
          // Keep name enabled (read-only) so it is still posted with the form.
          nameInput.disabled = false;
          nameInput.readOnly = true;
          descInput.disabled = true;
          moduleSelect.disabled = true;
        } else {
          warningDiv.style.display = 'none';
          submitBtn.disabled = false;
          nameInput.disabled = false;
          nameInput.readOnly = false;
          descInput.disabled = false;
          moduleSelect.disabled = false;
        }
      });

      // Delete Role Modal Handler
      document.getElementById('deleteRoleModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const roleId = button.getAttribute('data-role-id');
        const roleName = button.getAttribute('data-role-name');
        const userCount = parseInt(button.getAttribute('data-role-users') || '0');
        
        document.getElementById('delete-role-id').value = roleId;
        document.getElementById('delete-role-name').textContent = roleName;
        
        const warningDiv = document.getElementById('delete-role-warning');
        const submitBtn = document.getElementById('delete-role-submit');
        
        if (userCount > 0) {
          warningDiv.style.display = 'block';
          document.getElementById('delete-role-users').textContent = userCount;
          submitBtn.disabled = true;
        } else {
          warningDiv.style.display = 'none';
          submitBtn.disabled = false;
        }
      });
    </script>
  <?php endif; ?>

  <!-- Department Assignment Tab -->
  <?php if ($tab === 'departments'): ?>
    <?php
    require_once __DIR__ . '/includes/rbac_department.php';
    
    // Get all roles
    $allRoles = $conn->query("SELECT id, name, module FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get selected role
    $selectedRoleId = isset($_GET['role_id']) ? (int)$_GET['role_id'] : 0;
    if (!$selectedRoleId && !empty($allRoles)) {
        $selectedRoleId = (int)$allRoles[0]['id'];
    }
    
    // Get current departments for selected role
    $currentDepartments = [];
    if ($selectedRoleId > 0) {
        $currentDepartments = get_role_departments($selectedRoleId, $conn);
    }
    
    // Flatten current departments for checkbox checking
    $currentDeptsFlat = [];
    foreach ($currentDepartments as $module => $depts) {
        foreach ($depts as $dept) {
            $currentDeptsFlat[] = $dept;
        }
    }
    
    // Define all available departments (must stay in sync with rbac_department_selection_to_structure)
    $allDepartments = [
        'Cleaning Module' => [
            DEPT_CLEANING_OPERATIONS => 'Operations',
            DEPT_CLEANING_ACCOUNTS => 'Accounts'
        ],
        'Real Estate Module' => [
            DEPT_REALESTATE_CORE => 'Core Management',
            DEPT_REALESTATE_FINANCIAL => 'Financial',
            DEPT_REALESTATE_MAINTENANCE => 'Maintenance',
            DEPT_REALESTATE_OPERATIONS => 'Operations',
            DEPT_REALESTATE_COMPLIANCE => 'Compliance & Reports'
        ],
        'Legal Module' => [
            DEPT_LEGAL => 'Legal Department'
        ],
        'Construction Module' => [
            DEPT_CONSTRUCTION_CORE => 'Core',
            DEPT_CONSTRUCTION_PROJECTS => 'Projects',
            DEPT_CONSTRUCTION_FINANCIAL => 'Financial',
            DEPT_CONSTRUCTION_REPORTS => 'Reports'
        ],
        'ARS Module' => [
            DEPT_ARS_CORE => 'Core Management',
            DEPT_ARS_OPERATIONS => 'Operations'
        ],
        'Inventory (shared)' => [
            DEPT_INVENTORY => 'Inventory'
        ],
        'Grocery' => [
            DEPT_GROCERY_POS => 'Grocery — POS (retail)',
            DEPT_GROCERY_BACKOFFICE => 'Grocery — Back office'
        ],
        'Barber shop' => [
            DEPT_BARBER_POS => 'Barber shop — POS',
            DEPT_BARBER_BACKOFFICE => 'Barber shop — Back office'
        ],
        'Shared' => [
            DEPT_HR => 'HR (Shared across modules)'
        ]
    ];
    ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-shield-check"></i> Department Assignment</h5>
        <small class="text-muted">Assign departments to roles - Simple and clear access control</small>
      </div>
      <div class="card-body">
        <form method="GET" class="row g-3 mb-4">
          <div class="col-md-12">
            <label class="form-label">Select Role</label>
            <select name="role_id" class="form-select" onchange="this.form.submit()">
              <option value="">-- Select Role --</option>
              <?php foreach ($allRoles as $role): ?>
                <option value="<?= $role['id'] ?>" <?= $selectedRoleId == $role['id'] ? 'selected' : '' ?>>
                  <?= h($role['name']) ?> <?= $role['module'] ? '(' . h($role['module']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
        
        <?php if ($selectedRoleId > 0): ?>
          <?php
          $roleName = '';
          foreach ($allRoles as $r) {
              if ($r['id'] == $selectedRoleId) {
                  $roleName = $r['name'];
                  break;
              }
          }
          ?>
          <div class="alert alert-info">
            <strong>Assigning departments to:</strong> <?= h($roleName) ?>
            <?php if (!empty($currentDeptsFlat)): ?>
              <br><small>Current departments: <?= h(implode(', ', array_map('get_department_display_name', $currentDeptsFlat))) ?></small>
            <?php endif; ?>
          </div>
          
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save_departments">
            <input type="hidden" name="role_id" value="<?= $selectedRoleId ?>">
            
            <?php foreach ($allDepartments as $sectionName => $departments): ?>
              <div class="mb-4">
                <h6 class="text-primary mb-3">
                  <i class="bi bi-folder"></i> <?= h($sectionName) ?>
                </h6>
                <div class="row">
                  <?php foreach ($departments as $deptCode => $deptName): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                      <div class="card border h-100">
                        <div class="card-body">
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" 
                                   name="departments[]" 
                                   value="<?= h($deptCode) ?>"
                                   id="dept-<?= md5($deptCode) ?>"
                                   <?= in_array($deptCode, $currentDeptsFlat, true) ? 'checked' : '' ?>>
                            <label class="form-check-label w-100" for="dept-<?= md5($deptCode) ?>">
                              <strong><?= h($deptName) ?></strong>
                              <br><small class="text-muted"><?= h($deptCode) ?></small>
                            </label>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
            
            <div class="mt-4 pt-3 border-top">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> Save Departments
              </button>
              <a href="?tab=departments" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        <?php else: ?>
          <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Select a role above to assign departments.
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- System Configuration Tab -->
  <?php if ($tab === 'system'): ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-gear"></i> System Configuration</h5>
        <small class="text-muted">General system settings and preferences</small>
      </div>
      <div class="card-body">
        <form method="POST" data-admin-unsaved>
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="update_system">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Default VAT Rate (%)</label>
              <input type="number" name="default_vat_rate" class="form-control" 
                     value="<?= h(getSetting($conn, 'default_vat_rate', '5.0')) ?>" 
                     step="0.01" min="0" max="100">
              <div class="form-text">UAE standard rate is 5%</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Currency</label>
              <select name="currency" class="form-select">
                <option value="AED" <?= getSetting($conn, 'currency', 'AED') === 'AED' ? 'selected' : '' ?>>AED - UAE Dirham</option>
                <option value="USD" <?= getSetting($conn, 'currency', 'AED') === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                <option value="EUR" <?= getSetting($conn, 'currency', 'AED') === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Timezone</label>
              <select name="timezone" class="form-select">
                <option value="Asia/Dubai" <?= getSetting($conn, 'timezone', 'Asia/Dubai') === 'Asia/Dubai' ? 'selected' : '' ?>>Asia/Dubai</option>
                <option value="UTC" <?= getSetting($conn, 'timezone', 'Asia/Dubai') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                <option value="America/New_York" <?= getSetting($conn, 'timezone', 'Asia/Dubai') === 'America/New_York' ? 'selected' : '' ?>>America/New_York</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Date Format</label>
              <select name="date_format" class="form-select">
                <option value="Y-m-d" <?= getSetting($conn, 'date_format', 'Y-m-d') === 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                <option value="d-m-Y" <?= getSetting($conn, 'date_format', 'Y-m-d') === 'd-m-Y' ? 'selected' : '' ?>>DD-MM-YYYY</option>
                <option value="m/d/Y" <?= getSetting($conn, 'date_format', 'Y-m-d') === 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
              </select>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check"></i> Save System Settings
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- Branding Tab -->
  <?php if ($tab === 'branding'): ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-palette"></i> Brand Identity</h5>
        <small class="text-muted">Customize your system name, logo, and color scheme</small>
      </div>
      <div class="card-body">
        
        <!-- Loading State -->
        <div id="brandingLoading" class="text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="text-muted mt-2">Loading branding settings...</p>
        </div>

        <!-- Branding Form -->
        <form id="brandingForm" style="display: none;">
          
          <!-- System Identity Section -->
          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2">
                <i class="bi bi-tag"></i> System Identity
              </h6>
            </div>
            <div class="col-md-6">
              <label class="form-label">System Name (Full) *</label>
              <input type="text" class="form-control" id="systemName" name="system_name" 
                     placeholder="BMSystem" required>
              <div class="form-text">This appears in the browser title and main header</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">System Name (Short) *</label>
              <input type="text" class="form-control" id="systemNameShort" name="system_name_short" 
                     placeholder="BM" required maxlength="10">
              <div class="form-text">This appears in the logo badge (2-3 characters recommended)</div>
            </div>
            <div class="col-12 mt-3">
              <label class="form-label fw-semibold">Logo Image (Optional)</label>
              <div class="row g-3">
                <div class="col-md-8">
                  <input type="file" class="form-control" id="logoFile" name="logo_file" 
                         accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/webp">
                  <div class="form-text">Upload your company logo. Supported: PNG, JPG, SVG, WebP. Max size: 2MB. Recommended: 200x60px</div>
                </div>
                <div class="col-md-4">
                  <div id="logoPreviewContainer" style="display: none;">
                    <div class="card">
                      <div class="card-body text-center p-3">
                        <img id="logoPreviewImg" src="" alt="Logo Preview" style="max-width: 100%; max-height: 80px; object-fit: contain;">
                        <div class="mt-2">
                          <button type="button" class="btn btn-sm btn-outline-danger" id="removeLogoBtn">
                            <i class="bi bi-trash"></i> Remove
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div id="logoPlaceholder" class="card" style="display: block;">
                    <div class="card-body text-center p-3 text-muted">
                      <i class="bi bi-image" style="font-size: 2rem;"></i>
                      <div class="small mt-2">No logo uploaded</div>
                    </div>
                  </div>
                </div>
              </div>
              <input type="hidden" id="currentLogoPath" name="logo_path" value="">
            </div>
          </div>

          <!-- Color Scheme Section -->
          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2">
                <i class="bi bi-palette-fill"></i> Color Scheme
              </h6>
              <p class="text-muted small">Choose your brand colors. These will be applied across the entire system.</p>
            </div>
            
            <!-- Primary Color -->
            <div class="col-md-6 mb-3">
              <label class="form-label fw-semibold">Primary Color *</label>
              <div class="row g-2">
                <div class="col-3">
                  <input type="color" class="form-control form-control-color w-100" 
                         id="primaryColor" name="primary_color" value="#7a0000">
                </div>
                <div class="col-9">
                  <input type="text" class="form-control" id="primaryColorText" 
                         value="#7a0000" pattern="^#[0-9A-Fa-f]{6}$" maxlength="7">
                </div>
              </div>
              <div class="form-text">Main brand color (buttons, headers, links)</div>
              <div class="mt-2">
                <small class="text-muted">Preview:</small>
                <div class="p-3 rounded text-white text-center fw-semibold" 
                     id="primaryPreview" style="background-color: #7a0000;">
                  Sample Button
                </div>
              </div>
            </div>

            <!-- Primary Light -->
            <div class="col-md-6 mb-3">
              <label class="form-label fw-semibold">Primary Light *</label>
              <div class="row g-2">
                <div class="col-3">
                  <input type="color" class="form-control form-control-color w-100" 
                         id="primaryLight" name="primary_light" value="#910c0c">
                </div>
                <div class="col-9">
                  <input type="text" class="form-control" id="primaryLightText" 
                         value="#910c0c" pattern="^#[0-9A-Fa-f]{6}$" maxlength="7">
                </div>
              </div>
              <div class="form-text">Lighter shade for hover effects and gradients</div>
              <div class="mt-2">
                <small class="text-muted">Preview:</small>
                <div class="p-3 rounded text-white text-center fw-semibold" 
                     id="primaryLightPreview" style="background-color: #910c0c;">
                  Hover State
                </div>
              </div>
            </div>

            <!-- Primary Dark -->
            <div class="col-md-6 mb-3">
              <label class="form-label fw-semibold">Primary Dark *</label>
              <div class="row g-2">
                <div class="col-3">
                  <input type="color" class="form-control form-control-color w-100" 
                         id="primaryDark" name="primary_dark" value="#600000">
                </div>
                <div class="col-9">
                  <input type="text" class="form-control" id="primaryDarkText" 
                         value="#600000" pattern="^#[0-9A-Fa-f]{6}$" maxlength="7">
                </div>
              </div>
              <div class="form-text">Darker shade for borders and shadows</div>
              <div class="mt-2">
                <small class="text-muted">Preview:</small>
                <div class="p-3 rounded text-white text-center fw-semibold" 
                     id="primaryDarkPreview" style="background-color: #600000;">
                  Dark Variant
                </div>
              </div>
            </div>

            <!-- Accent Color -->
            <div class="col-md-6 mb-3">
              <label class="form-label fw-semibold">Accent Color *</label>
              <div class="row g-2">
                <div class="col-3">
                  <input type="color" class="form-control form-control-color w-100" 
                         id="accentColor" name="accent_color" value="#ffd86a">
                </div>
                <div class="col-9">
                  <input type="text" class="form-control" id="accentColorText" 
                         value="#ffd86a" pattern="^#[0-9A-Fa-f]{6}$" maxlength="7">
                </div>
              </div>
              <div class="form-text">Accent color for highlights and special elements</div>
              <div class="mt-2">
                <small class="text-muted">Preview:</small>
                <div class="p-3 rounded text-dark text-center fw-semibold" 
                     id="accentPreview" style="background-color: #ffd86a;">
                  Accent Highlight
                </div>
              </div>
            </div>
          </div>

          <!-- Color Presets -->
          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2">
                <i class="bi bi-stars"></i> Color Presets
              </h6>
              <p class="text-muted small">Quick presets to get you started. Click to apply.</p>
            </div>
            <div class="col-12">
              <div class="row g-3">
                <!-- Saif Holding (Brand) -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('saif')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#1e3a8a;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#2563eb;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#c9a227;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Saif Holding</div>
                      <small class="text-muted">Navy & Gold</small>
                    </div>
                  </div>
                </div>

                <!-- Maroon (Default) -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('maroon')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#7a0000;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#910c0c;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#ffd86a;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Maroon (Default)</div>
                      <small class="text-muted">Professional & Bold</small>
                    </div>
                  </div>
                </div>
                
                <!-- Navy Blue -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('navy')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#1e3a8a;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#2563eb;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fbbf24;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Navy Blue</div>
                      <small class="text-muted">Corporate & Trust</small>
                    </div>
                  </div>
                </div>

                <!-- Forest Green -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('forest')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#065f46;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#10b981;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fcd34d;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Forest Green</div>
                      <small class="text-muted">Natural & Growth</small>
                    </div>
                  </div>
                </div>

                <!-- Purple -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('purple')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#6b21a8;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#9333ea;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fde047;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Purple</div>
                      <small class="text-muted">Creative & Luxury</small>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Row 2 - More Presets -->
              <div class="row g-3 mt-2">
                <!-- Teal -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('teal')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#0d9488;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#14b8a6;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fcd34d;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Teal</div>
                      <small class="text-muted">Modern & Fresh</small>
                    </div>
                  </div>
                </div>

                <!-- Orange -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('orange')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#c2410c;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#ea580c;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fef3c7;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Orange</div>
                      <small class="text-muted">Energetic & Bold</small>
                    </div>
                  </div>
                </div>

                <!-- Indigo -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('indigo')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#4338ca;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#6366f1;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fde68a;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Indigo</div>
                      <small class="text-muted">Tech & Innovation</small>
                    </div>
                  </div>
                </div>

                <!-- Rose -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('rose')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#be123c;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#e11d48;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fef08a;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Rose</div>
                      <small class="text-muted">Elegant & Passionate</small>
                    </div>
                  </div>
                </div>

                <!-- Slate (Dark Professional) -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('slate')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#1e293b;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#334155;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fbbf24;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Slate</div>
                      <small class="text-muted">Dark & Professional</small>
                    </div>
                  </div>
                </div>

                <!-- Emerald -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('emerald')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#047857;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#059669;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fef3c7;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Emerald</div>
                      <small class="text-muted">Fresh & Growth</small>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Row 3 - Premium Presets -->
              <div class="row g-3 mt-2">
                <!-- Amber -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('amber')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#d97706;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#f59e0b;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fef3c7;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Amber</div>
                      <small class="text-muted">Warm & Inviting</small>
                    </div>
                  </div>
                </div>

                <!-- Crimson -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('crimson')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#991b1b;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#dc2626;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fde68a;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Crimson</div>
                      <small class="text-muted">Power & Energy</small>
                    </div>
                  </div>
                </div>

                <!-- Cyan -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('cyan')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#0e7490;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#06b6d4;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fef3c7;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Cyan</div>
                      <small class="text-muted">Cool & Tech</small>
                    </div>
                  </div>
                </div>

                <!-- Fuchsia -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('fuchsia')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#a21caf;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#d946ef;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fef08a;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Fuchsia</div>
                      <small class="text-muted">Vibrant & Modern</small>
                    </div>
                  </div>
                </div>

                <!-- Lime -->
                <div class="col-md-3">
                  <div class="card preset-card" onclick="applyPreset('lime')" style="cursor: pointer;">
                    <div class="card-body text-center">
                      <div class="d-flex gap-2 mb-2 justify-content-center">
                        <div style="width:40px;height:40px;background:#65a30d;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#84cc16;border-radius:8px;"></div>
                        <div style="width:40px;height:40px;background:#fef3c7;border-radius:8px;"></div>
                      </div>
                      <div class="fw-semibold">Lime</div>
                      <small class="text-muted">Fresh & Energetic</small>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Dark Mode Section -->
          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2">
                <i class="bi bi-moon-stars"></i> Dark Mode (Experimental)
              </h6>
              <p class="text-muted small">Enable dark mode for a modern, eye-friendly experience</p>
            </div>
            <div class="col-12">
              <div class="card">
                <div class="card-body">
                  <div class="row align-items-center">
                    <div class="col-md-8">
                      <h6 class="mb-2">
                        <i class="bi bi-moon-fill me-2"></i>
                        Dark Mode Theme
                      </h6>
                      <p class="text-muted small mb-0">
                        Automatically adjusts colors for reduced eye strain in low-light environments.
                        Uses your selected brand colors adapted for dark backgrounds.
                      </p>
                    </div>
                    <div class="col-md-4 text-end">
                      <div class="form-check form-switch d-inline-block">
                        <input class="form-check-input" type="checkbox" role="switch" 
                               id="darkModeToggle" name="dark_mode_enabled" 
                               style="width: 3rem; height: 1.5rem; cursor: pointer;">
                        <label class="form-check-label ms-2 fw-semibold" for="darkModeToggle" id="darkModeLabel">
                          Disabled
                        </label>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-outline-secondary" id="btnResetDefaults">
              <i class="bi bi-arrow-counterclockwise"></i> Reset to Defaults
            </button>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-primary" id="btnPreviewChanges">
                <i class="bi bi-eye"></i> Preview Changes
              </button>
              <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-check-circle"></i> Save Branding
              </button>
            </div>
          </div>
        </form>
        
        <!-- Success Message -->
        <div id="brandingSuccess" class="alert alert-success mt-3" style="display: none;">
          <i class="bi bi-check-circle"></i> <span id="brandingSuccessMessage"></span>
        </div>
        
        <!-- Error Message -->
        <div id="brandingError" class="alert alert-danger mt-3" style="display: none;">
          <i class="bi bi-exclamation-circle"></i> <span id="brandingErrorMessage"></span>
        </div>

      </div>
    </div>

    <style>
    .preset-card {
      transition: all 0.3s;
      border: 2px solid transparent;
    }
    .preset-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 8px 16px rgba(0,0,0,0.1);
      border-color: var(--brand-primary);
    }
    </style>
  <?php endif; ?>

  <!-- Email Settings Tab -->
  <?php if ($tab === 'email'): ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-envelope"></i> Email Configuration <span class="badge text-bg-secondary">Global · Canonical SMTP</span></h5>
        <small class="text-muted">Single source for app SMTP. Operation and HR reminder pages link here instead of editing SMTP separately.</small>
      </div>
      <div class="card-body">
        <form method="POST" data-admin-unsaved>
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="update_email">
          <input type="hidden" name="tab" value="email">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">SMTP Host</label>
              <input type="text" name="smtp_host" class="form-control" 
                     value="<?= h($emailSettings['smtp_host'] ?? '') ?>" 
                     placeholder="smtp.gmail.com">
            </div>
            <div class="col-md-6">
              <label class="form-label">SMTP Port</label>
              <input type="number" name="smtp_port" class="form-control" 
                     value="<?= h($emailSettings['smtp_port'] ?? '587') ?>" 
                     min="1" max="65535">
            </div>
            <div class="col-md-6">
              <label class="form-label">SMTP Username</label>
              <input type="text" name="smtp_username" class="form-control" 
                     value="<?= h($emailSettings['smtp_username'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">SMTP Password</label>
              <input type="password" name="smtp_password" class="form-control" 
                     value="<?= h($emailSettings['smtp_password'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">From Name</label>
              <input type="text" name="from_name" class="form-control" 
                     value="<?= h($emailSettings['from_name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">From Email</label>
              <input type="email" name="from_email" class="form-control" 
                     value="<?= h($emailSettings['from_email'] ?? '') ?>">
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_enabled" 
                       <?= ($emailSettings['is_enabled'] ?? 0) ? 'checked' : '' ?>>
                <label class="form-check-label">Enable email notifications</label>
              </div>
            </div>
            <div class="col-12">
              <hr class="my-3">
              <h6 class="mb-3"><i class="bi bi-bell"></i> Owner Email Notifications</h6>
              <label class="form-label">Owner Email Addresses</label>
              <textarea name="owner_emails" class="form-control" rows="3" 
                        placeholder="Enter email addresses separated by commas (e.g., owner1@example.com, owner2@example.com)"><?= h($emailSettings['owner_emails'] ?? '') ?></textarea>
              <small class="text-muted">These emails will receive automatic notifications when employees submit leave requests or cash advance requests.</small>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check"></i> Save Email Settings
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- Real Estate Email Notifications Tab -->
  <?php if ($tab === 're_email'): ?>
    <?php
    $currentCompanyId = (int)(current_company_id($conn) ?: 0);
    $reNotifications = [];
    if ($currentCompanyId > 0) {
      $reEmailNotifications = $conn->prepare("
          SELECT notification_type, GROUP_CONCAT(recipient_email SEPARATOR ', ') as emails
          FROM re_email_notifications
          WHERE company_id = ? AND is_enabled = 1
          GROUP BY notification_type
      ");
      $reEmailNotifications->execute([$currentCompanyId]);
      while ($row = $reEmailNotifications->fetch(PDO::FETCH_ASSOC)) {
          $reNotifications[$row['notification_type']] = $row['emails'];
      }
    }
    ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-building"></i> Real Estate Email Notifications <span class="badge text-bg-secondary">Company</span></h5>
        <small class="text-muted">Configure email recipients for Real Estate module notifications</small>
      </div>
      <div class="card-body">
        <?php if ($currentCompanyId <= 0): ?>
          <div class="alert alert-warning">No company selected. Switch company in the header, then return here.</div>
        <?php else: ?>
        <div class="alert alert-info">
          <i class="bi bi-info-circle"></i> 
          <strong>Note:</strong> Email sender configuration is managed in the <a href="?tab=email">Email Settings</a> tab. 
          These settings only configure who receives Real Estate notifications for the current company.
        </div>
        
        <form method="POST" data-admin-unsaved>
          <input type="hidden" name="action" value="update_re_email_notifications">
          <?php csrf_field(); ?>
          
          <div class="row">
            <div class="col-md-6 mb-4">
              <h6 class="mb-3"><i class="bi bi-house-heart"></i> Find Your Home – Viewing Requests</h6>
              <label class="form-label">Viewing Request Email(s)</label>
              <input type="text" name="find_home_viewing_request_email" class="form-control"
                     value="<?= h($reNotifications['find_home_viewing_request'] ?? '') ?>"
                     placeholder="leasing@example.com, sales@example.com">
              <small class="text-muted">
                Email address(es) to receive alerts when a prospective tenant schedules a viewing from the Find Your Home mobile journey.
                Multiple emails can be separated by commas.
              </small>
            </div>

            <div class="col-md-6 mb-4">
              <h6 class="mb-3"><i class="bi bi-file-earmark-person"></i> Find Your Home – Lease Applications</h6>
              <label class="form-label">Lease Application Email(s)</label>
              <input type="text" name="find_home_lease_application_email" class="form-control"
                     value="<?= h($reNotifications['find_home_lease_application'] ?? '') ?>"
                     placeholder="leasing@example.com, applications@example.com">
              <small class="text-muted">
                Email address(es) to receive alerts when a prospective tenant submits a lease application lead from the mobile app.
                Multiple emails can be separated by commas.
              </small>
            </div>
          </div>

          <div class="row">
            <div class="col-md-12 mb-4">
              <h6 class="mb-3"><i class="bi bi-tools"></i> Maintenance Request Notifications</h6>
              <label class="form-label">Maintenance Manager Email(s)</label>
              <input type="text" name="maintenance_request_email" class="form-control" 
                     value="<?= h($reNotifications['maintenance_request'] ?? '') ?>"
                     placeholder="maintenance@example.com or multiple emails separated by commas">
              <small class="text-muted">
                Email address(es) to receive notifications when a new maintenance request is created. 
                Multiple emails can be separated by commas.
              </small>
            </div>
            
            <div class="col-md-12 mb-4">
              <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Extra Service Request Notifications</h6>
              <label class="form-label">Extra Service Request Email(s)</label>
              <input type="text" name="extra_service_request_email" class="form-control" 
                     value="<?= h($reNotifications['extra_service_request'] ?? '') ?>"
                     placeholder="management@example.com">
              <small class="text-muted">
                Email address(es) to receive notifications when a tenant submits an extra service request (parking, storage, etc.) from the Tenant Portal.
              </small>
            </div>
            
            <div class="col-md-12 mb-4">
              <h6 class="mb-3"><i class="bi bi-droplet"></i> Cleaning Request Notifications</h6>
              <label class="form-label">Cleaning Request Email(s)</label>
              <input type="text" name="cleaning_request_email" class="form-control" 
                     value="<?= h($reNotifications['cleaning_request'] ?? '') ?>"
                     placeholder="operations@example.com">
              <small class="text-muted">Email address(es) to receive notifications when a tenant submits a cleaning service request.</small>
            </div>
            
            <div class="col-md-12 mb-4">
              <h6 class="mb-3"><i class="bi bi-bug"></i> Pest Control Request Notifications</h6>
              <label class="form-label">Pest Control Request Email(s)</label>
              <input type="text" name="pest_control_request_email" class="form-control" 
                     value="<?= h($reNotifications['pest_control_request'] ?? '') ?>"
                     placeholder="operations@example.com">
              <small class="text-muted">Email address(es) to receive notifications when a tenant submits a pest control request.</small>
            </div>
            
            <div class="col-md-12 mb-4">
              <h6 class="mb-3"><i class="bi bi-cash-stack"></i> Cash Payment – Accountants Team</h6>
              <label class="form-label">Accountants / Finance Email(s)</label>
              <input type="text" name="cash_payment_pending_verification_email" class="form-control" 
                     value="<?= h($reNotifications['cash_payment_pending_verification'] ?? '') ?>"
                     placeholder="accounting@example.com, finance@example.com">
              <small class="text-muted">Email address(es) to receive notifications when reception confirms a cash payment (pending accounting verification). Multiple emails can be separated by commas.</small>
            </div>
            
            <div class="col-md-12 mb-4">
              <h6 class="mb-3"><i class="bi bi-box-seam"></i> Inventory – Material requests (cross-module)</h6>
              <label class="form-label">Inventory manager / admin email(s)</label>
              <input type="text" name="inventory_material_request_email" class="form-control" 
                     value="<?= h($reNotifications['inventory_material_request'] ?? '') ?>"
                     placeholder="inventory@example.com, stock.manager@example.com">
              <small class="text-muted">
                Receives a notification when a user submits a <strong>material request</strong> from Real Estate, Construction, ARS, Cleaning, or another module (not from Inventory’s own create screen). Multiple emails can be separated by commas.
              </small>
            </div>
          </div>
          
          <div class="row">
            <div class="col-md-6 mb-4">
              <h6 class="mb-3"><i class="bi bi-file-text"></i> Lease Notifications</h6>
              <label class="form-label">Lease Expiry Email(s)</label>
              <input type="text" name="lease_expiry_email" class="form-control" 
                     value="<?= h($reNotifications['lease_expiry'] ?? '') ?>"
                     placeholder="leasing@example.com">
              <small class="text-muted">Email address(es) to receive lease expiry reminders.</small>
            </div>
            
            <div class="col-md-6 mb-4">
              <h6 class="mb-3"><i class="bi bi-cash-coin"></i> Payment Notifications</h6>
              <label class="form-label">Payment Overdue Email(s)</label>
              <input type="text" name="payment_overdue_email" class="form-control" 
                     value="<?= h($reNotifications['payment_overdue'] ?? '') ?>"
                     placeholder="finance@example.com">
              <small class="text-muted">Email address(es) to receive overdue payment alerts.</small>
            </div>
          </div>
          
          <div class="row">
            <div class="col-md-6 mb-4">
              <h6 class="mb-3"><i class="bi bi-box-arrow-in-right"></i> Move-In Notifications</h6>
              <label class="form-label">Move-In Email(s)</label>
              <input type="text" name="move_in_email" class="form-control" 
                     value="<?= h($reNotifications['move_in'] ?? '') ?>"
                     placeholder="operations@example.com">
              <small class="text-muted">Email address(es) to receive move-in completion notifications.</small>
            </div>
            
            <div class="col-md-6 mb-4">
              <h6 class="mb-3"><i class="bi bi-box-arrow-right"></i> Move-Out Notifications</h6>
              <label class="form-label">Move-Out Email(s)</label>
              <input type="text" name="move_out_email" class="form-control" 
                     value="<?= h($reNotifications['move_out'] ?? '') ?>"
                     placeholder="operations@example.com">
              <small class="text-muted">Email address(es) to receive move-out completion notifications.</small>
            </div>
          </div>

          <div class="row">
            <div class="col-md-12 mb-4">
              <h6 class="mb-3"><i class="bi bi-bank2"></i> Legal Department – Bounced Cheques &amp; Escalations</h6>
              <label class="form-label">Legal Department Email(s)</label>
              <input type="text" name="legal_escalation_email" class="form-control"
                     value="<?= h($reNotifications['legal_escalation'] ?? '') ?>"
                     placeholder="legal@example.com, collections@example.com">
              <small class="text-muted">
                Email address(es) that receive alerts when an accountant marks a cheque as <strong>bounced</strong> or
                <strong>escalates</strong> it to legal from the Lease View page. Multiple emails can be separated by commas.
                Only these configured addresses are notified (not all Owner/Admin/Legal users).
              </small>
            </div>
          </div>

          <div class="mt-3">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check"></i> Save Real Estate Email Settings
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Accounting Settings Tab -->
  <?php if ($tab === 'accounting'): ?>
    <?php
      $prepaidAssetOptions = sm_prepaid_asset_options($conn);
      $currentPrepaidAsset = sm_prepaid_asset_account($conn);
    ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-calculator"></i> Cleaning Accounting <span class="badge text-bg-info">Cleaning only</span></h5>
        <small class="text-muted">Cleaning company GL (<code>gl_*</code> / <code>chart_of_accounts</code>). Real Estate and Construction use the shared RE engine — open Module links.</small>
      </div>
      <div class="card-body">
        <div class="alert alert-light border small">
          This tab does <strong>not</strong> configure RE Invoice Mode or Construction journals.
          Use <a href="<?= get_base_path() ?>/settings.php?tab=module_hub">HR &amp; module links</a> for those deep-links.
        </div>
        <div class="row">
          <div class="col-md-6">
            <div class="card h-100">
              <div class="card-body text-center">
                <i class="bi bi-graph-up text-primary fs-1"></i>
                <h5 class="card-title">Cleaning Chart of Accounts</h5>
                <p class="card-text">Manage Cleaning account structure and categories</p>
                <a href="accounts/coa.php" class="btn btn-outline-primary">Manage COA</a>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card h-100">
              <div class="card-body text-center">
                <i class="bi bi-file-earmark-text text-success fs-1"></i>
                <h5 class="card-title">Invoice Templates</h5>
                <p class="card-text">Customize invoice layouts and branding</p>
                <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#invoiceTemplatesModal">
                  <i class="bi bi-gear"></i> Manage Templates
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="card mt-4 border-0 shadow-sm">
          <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-calendar2-month me-2"></i>Prepaid Expense Defaults</h5>
            <small class="text-muted">Default balance-sheet asset used when creating a prepaid expense (you can still override per expense).</small>
          </div>
          <div class="card-body">
            <form method="POST" class="row g-3 align-items-end">
              <?php csrf_field(); ?>
              <input type="hidden" name="action" value="update_prepaid_settings">
              <input type="hidden" name="tab" value="accounting">
              <div class="col-md-8">
                <label class="form-label fw-semibold">Default prepaid asset account</label>
                <select class="form-select" name="sm_prepaid_asset_account" required>
                  <?php foreach ($prepaidAssetOptions as $a): ?>
                    <option value="<?= htmlspecialchars($a['account_no'], ENT_QUOTES, 'UTF-8') ?>"
                      <?= $a['account_no'] === $currentPrepaidAsset ? 'selected' : '' ?>>
                      <?= htmlspecialchars($a['account_no'].' — '.$a['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">
                  Examples: <code>1210</code> Prepaid Rent, <code>1220</code> Prepaid Insurance,
                  <code>1240</code> Supplier Deposits. Do <strong>not</strong> use fixed-asset accounts like Furniture &amp; Fixtures.
                </div>
              </div>
              <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">Save prepaid default</button>
              </div>
            </form>
            <div class="alert alert-light border mt-3 mb-0 small">
              <strong>How prepaid works:</strong>
              When you mark an expense as Prepaid, the system debits the <em>prepaid asset</em> you choose
              (balance sheet). Each month, amortization moves a portion to the <em>expense account</em>
              (e.g. Visa fees 5250, Rent expense). Manage schedules under
              <a href="accounts/prepaid_schedules.php">Prepaid Schedules</a>.
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Cash Advance Policy Tab -->
  <?php if ($tab === 'cash_advance_policy'): 
    // Fetch current policy
    $policyStmt = $conn->prepare("SELECT * FROM cash_advance_policy WHERE id = 1");
    $policyStmt->execute();
    $policy = $policyStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'max_advance_amount_global' => null,
        'max_advance_percentage_salary' => 50.00,
        'min_service_months' => 3,
        'max_pending_advances' => 1,
        'policy_rules' => '',
        'eligibility_criteria' => '',
        'terms_and_conditions' => '',
        'is_active' => 1
    ];
  ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-cash-coin"></i> Cash Advance Policy Settings</h5>
        <small class="text-muted">Configure limits, eligibility criteria, and policy rules for cash advances</small>
      </div>
      <div class="card-body">
        <form method="POST" data-admin-unsaved>
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="update_cash_advance_policy">
          <input type="hidden" name="tab" value="cash_advance_policy">
          
          <div class="row g-3 mb-4">
            <div class="col-12">
              <h6 class="border-bottom pb-2 mb-3">Limits & Eligibility</h6>
            </div>
            
            <div class="col-md-6">
              <label class="form-label">Global Maximum Advance Amount (AED)</label>
              <input type="number" name="max_advance_amount_global" class="form-control" 
                     value="<?= $policy['max_advance_amount_global'] ? number_format((float)$policy['max_advance_amount_global'], 2, '.', '') : '' ?>" 
                     step="0.01" min="0" placeholder="Leave empty for no global limit">
              <div class="form-text">Maximum amount any employee can request (overrides percentage if set)</div>
            </div>
            
            <div class="col-md-6">
              <label class="form-label">Maximum Advance as % of Monthly Salary</label>
              <input type="number" name="max_advance_percentage_salary" class="form-control" 
                     value="<?= $policy['max_advance_percentage_salary'] ? number_format((float)$policy['max_advance_percentage_salary'], 2, '.', '') : '' ?>" 
                     step="0.01" min="0" max="100" placeholder="50.00">
              <div class="form-text">Percentage of monthly salary (e.g., 50 = 50% of monthly salary)</div>
            </div>
            
            <div class="col-md-6">
              <label class="form-label">Maximum Total Advance Amount (AED)</label>
              <input type="number" name="max_total_advance_amount" class="form-control" 
                     value="<?= $policy['max_total_advance_amount'] ? number_format((float)$policy['max_total_advance_amount'], 2, '.', '') : '' ?>" 
                     step="0.01" min="0" placeholder="Leave empty for no limit">
              <div class="form-text">Maximum cumulative advance amount an employee can have outstanding (e.g., 1000 AED total limit across all advances)</div>
            </div>
            
            <div class="col-md-6">
              <label class="form-label">Minimum Service Months</label>
              <input type="number" name="min_service_months" class="form-control" 
                     value="<?= (int)$policy['min_service_months'] ?>" 
                     min="0" required>
              <div class="form-text">Minimum months of service required to be eligible</div>
            </div>
            
            <div class="col-md-6">
              <label class="form-label">Maximum Pending Advances</label>
              <input type="number" name="max_pending_advances" class="form-control" 
                     value="<?= (int)$policy['max_pending_advances'] ?>" 
                     min="1" required>
              <div class="form-text">Maximum number of pending advance requests allowed at once</div>
            </div>
            
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" 
                       <?= ($policy['is_active'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label">Policy is active (enforce limits and eligibility)</label>
              </div>
            </div>
          </div>
          
          <div class="row g-3 mb-4">
            <div class="col-12">
              <h6 class="border-bottom pb-2 mb-3">Policy Information</h6>
            </div>
            
            <?php
            // Parse existing list items
            $policyRulesItems = parseListItems($policy['policy_rules'] ?? '');
            $eligibilityItems = parseListItems($policy['eligibility_criteria'] ?? '');
            $termsItems = parseListItems($policy['terms_and_conditions'] ?? '');
            ?>
            
            <!-- Policy Rules -->
            <div class="col-12">
              <label class="form-label">Policy Rules</label>
              <div class="list-builder-container border rounded p-3 bg-light">
                <div id="policyRulesList" class="mb-3">
                  <?php if (empty($policyRulesItems)): ?>
                    <div class="list-item-group mb-2">
                      <div class="input-group">
                        <input type="text" class="form-control list-item-input" placeholder="Enter a policy rule">
                        <button type="button" class="btn btn-outline-danger remove-item-btn" style="display: none;">
                          <i class="bi bi-trash"></i>
                        </button>
                      </div>
                    </div>
                  <?php else: ?>
                    <?php foreach ($policyRulesItems as $item): ?>
                      <div class="list-item-group mb-2">
                        <div class="input-group">
                          <input type="text" class="form-control list-item-input" value="<?= h($item) ?>" placeholder="Enter a policy rule">
                          <button type="button" class="btn btn-outline-danger remove-item-btn">
                            <i class="bi bi-trash"></i>
                          </button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary add-item-btn" data-target="policyRulesList">
                  <i class="bi bi-plus-circle"></i> Add Rule
                </button>
              </div>
              <input type="hidden" name="policy_rules" id="policyRulesHidden">
              <div class="form-text">Rules and guidelines that will be displayed to employees</div>
            </div>
            
            <!-- Eligibility Criteria -->
            <div class="col-12">
              <label class="form-label">Eligibility Criteria</label>
              <div class="list-builder-container border rounded p-3 bg-light">
                <div id="eligibilityCriteriaList" class="mb-3">
                  <?php if (empty($eligibilityItems)): ?>
                    <div class="list-item-group mb-2">
                      <div class="input-group">
                        <input type="text" class="form-control list-item-input" placeholder="Enter eligibility criterion">
                        <button type="button" class="btn btn-outline-danger remove-item-btn" style="display: none;">
                          <i class="bi bi-trash"></i>
                        </button>
                      </div>
                    </div>
                  <?php else: ?>
                    <?php foreach ($eligibilityItems as $item): ?>
                      <div class="list-item-group mb-2">
                        <div class="input-group">
                          <input type="text" class="form-control list-item-input" value="<?= h($item) ?>" placeholder="Enter eligibility criterion">
                          <button type="button" class="btn btn-outline-danger remove-item-btn">
                            <i class="bi bi-trash"></i>
                          </button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary add-item-btn" data-target="eligibilityCriteriaList">
                  <i class="bi bi-plus-circle"></i> Add Criterion
                </button>
              </div>
              <input type="hidden" name="eligibility_criteria" id="eligibilityCriteriaHidden">
              <div class="form-text">Criteria that employees must meet to be eligible for cash advances</div>
            </div>
            
            <!-- Terms and Conditions -->
            <div class="col-12">
              <label class="form-label">Terms and Conditions</label>
              <div class="list-builder-container border rounded p-3 bg-light">
                <div id="termsConditionsList" class="mb-3">
                  <?php if (empty($termsItems)): ?>
                    <div class="list-item-group mb-2">
                      <div class="input-group">
                        <input type="text" class="form-control list-item-input" placeholder="Enter a term or condition">
                        <button type="button" class="btn btn-outline-danger remove-item-btn" style="display: none;">
                          <i class="bi bi-trash"></i>
                        </button>
                      </div>
                    </div>
                  <?php else: ?>
                    <?php foreach ($termsItems as $item): ?>
                      <div class="list-item-group mb-2">
                        <div class="input-group">
                          <input type="text" class="form-control list-item-input" value="<?= h($item) ?>" placeholder="Enter a term or condition">
                          <button type="button" class="btn btn-outline-danger remove-item-btn">
                            <i class="bi bi-trash"></i>
                          </button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary add-item-btn" data-target="termsConditionsList">
                  <i class="bi bi-plus-circle"></i> Add Term
                </button>
              </div>
              <input type="hidden" name="terms_and_conditions" id="termsConditionsHidden">
              <div class="form-text">Terms and conditions for cash advances</div>
            </div>
          </div>
          
          <div class="d-flex justify-content-end gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save"></i> Save Policy
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- Service Categories (Service Management Phase 5) -->
  <?php if ($tab === 'service_categories'): ?>
    <?php include __DIR__ . '/includes/settings/service_categories_panel.php'; ?>
  <?php endif; ?>

  <!-- Companies & Modules Tab — ERP-wide company directory -->
  <?php if ($tab === 'companies'): ?>
    <?php
      $bizTypeLabels = [
        'cleaning' => 'Cleaning',
        'realestate' => 'Real Estate',
        'construction' => 'Construction',
        'short_term_rental' => 'ARS (Short-term rental)',
        'supermarket' => 'Grocery',
        'barbershop' => 'Barber',
        'restaurant' => 'Restaurant',
        '' => 'Unspecified',
      ];
      $bizTypeIcons = [
        'cleaning' => 'droplets',
        'realestate' => 'building',
        'construction' => 'hard-hat',
        'short_term_rental' => 'home',
        'supermarket' => 'shopping-cart',
        'barbershop' => 'scissors',
        'restaurant' => 'utensils',
      ];
      $companyDirectory = [];
      try {
        $companyDirectory = $conn->query("
          SELECT c.id, c.name, c.code, c.business_type, c.is_active, c.created_at,
                 (SELECT COUNT(*) FROM user_companies uc WHERE uc.company_id = c.id) AS user_count
          FROM companies c
          ORDER BY c.is_active DESC, c.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
        $companyDirectory = [];
      }
      $activeCount = 0;
      $typeCounts = [];
      foreach ($companyDirectory as $coRow) {
        if ((int)($coRow['is_active'] ?? 0) === 1) {
          $activeCount++;
        }
        $bt = (string)($coRow['business_type'] ?? '');
        $typeCounts[$bt] = ($typeCounts[$bt] ?? 0) + 1;
      }
      $totalCompanies = count($companyDirectory);
      $typeVariety = count($typeCounts);
    ?>
    <?= admin_ui_page_header(
      'Companies & Modules',
      'See every company in the ERP, which business module it runs, and where to configure access or profile details.',
      [
        ['label' => 'Administration', 'href' => get_base_path() . '/settings.php?tab=dashboard'],
        ['label' => 'Companies & Modules'],
      ]
    ) ?>

    <div class="admin-settings-card mb-3">
      <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-3 align-items-start">
          <div class="kpi-icon" style="width:44px;height:44px;border-radius:12px;background:var(--admin-primary-soft);color:var(--admin-primary);display:grid;place-items:center;flex-shrink:0">
            <i data-lucide="info" style="width:22px;height:22px"></i>
          </div>
          <div class="flex-grow-1">
            <h6 class="mb-1">What this page is for</h6>
            <p class="small text-muted mb-2 mb-md-0">
              HeroSysgro is multi-company. Each company has a <strong>business type</strong> that selects its primary module
              (Cleaning, Real Estate, Construction, ARS, etc.). Use this page to <strong>review the company list</strong>,
              understand which module each company uses, then jump to <strong>Company Info</strong> (profile) or
              <strong>Users</strong> (who can access which company). Deep module settings stay in each module — see
              <a href="<?= get_base_path() ?>/settings.php?tab=module_hub">HR &amp; module links</a>.
            </p>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-6 col-lg-3"><?= admin_ui_kpi(['label' => 'Companies', 'value' => (string)$totalCompanies, 'sub' => $activeCount . ' active', 'icon' => 'building-2']) ?></div>
      <div class="col-6 col-lg-3"><?= admin_ui_kpi(['label' => 'Business types', 'value' => (string)$typeVariety, 'sub' => 'Distinct modules in use', 'icon' => 'layers']) ?></div>
      <div class="col-6 col-lg-3"><?= admin_ui_kpi(['label' => 'User links', 'value' => (string)array_sum(array_map(static fn($r) => (int)($r['user_count'] ?? 0), $companyDirectory)), 'sub' => 'Assignments in user_companies', 'icon' => 'users']) ?></div>
      <div class="col-6 col-lg-3"><?= admin_ui_kpi(['label' => 'Next step', 'value' => 'Access', 'sub' => 'Assign companies on Users', 'icon' => 'arrow-right', 'href' => get_base_path() . '/settings.php?tab=users']) ?></div>
    </div>

    <?php if ($typeCounts): ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <?php foreach ($typeCounts as $btKey => $btN):
        $label = $bizTypeLabels[$btKey] ?? ($btKey !== '' ? ucfirst($btKey) : 'Unspecified');
      ?>
        <span class="admin-pill admin-pill-gold"><?= h($label) ?> · <?= (int)$btN ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="admin-settings-card">
      <div class="settings-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <h5 class="mb-0">Company directory</h5>
          <small class="text-muted">All companies registered in the system (active and inactive)</small>
        </div>
        <a href="<?= get_base_path() ?>/settings.php?tab=users" class="btn btn-sm btn-outline-secondary">
          <i data-lucide="user-cog" style="width:14px;height:14px" class="me-1"></i> Manage user access
        </a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($companyDirectory)): ?>
          <?= admin_ui_empty('No companies found.', 'building') ?>
        <?php else: ?>
          <div class="admin-table-shell border-0 shadow-none rounded-0">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead>
                  <tr>
                    <th>Company</th>
                    <th>Code</th>
                    <th>Business module</th>
                    <th class="text-center">Users</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($companyDirectory as $coRow):
                    $bt = (string)($coRow['business_type'] ?? '');
                    $btLabel = $bizTypeLabels[$bt] ?? ($bt !== '' ? ucfirst($bt) : 'Unspecified');
                    $icon = $bizTypeIcons[$bt] ?? 'building';
                    $isActive = (int)($coRow['is_active'] ?? 0) === 1;
                    $infoHref = get_base_path() . '/settings.php?tab=company&settings_company_id=' . (int)$coRow['id'];
                  ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= h($coRow['name']) ?></div>
                      <div class="admin-activity-meta">ID #<?= (int)$coRow['id'] ?></div>
                    </td>
                    <td><code class="small"><?= h($coRow['code'] ?? '—') ?></code></td>
                    <td>
                      <span class="d-inline-flex align-items-center gap-1">
                        <i data-lucide="<?= h($icon) ?>" style="width:14px;height:14px;color:var(--admin-primary)"></i>
                        <?= h($btLabel) ?>
                      </span>
                    </td>
                    <td class="text-center"><?= (int)($coRow['user_count'] ?? 0) ?></td>
                    <td><?= $isActive ? admin_ui_status_pill('active', 'Active') : admin_ui_status_pill('inactive', 'Inactive') ?></td>
                    <td class="text-end text-nowrap">
                      <a class="btn btn-sm btn-outline-secondary" href="<?= h($infoHref) ?>" title="Edit company profile">
                        Profile
                      </a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-md-4">
        <a class="admin-info-card h-100" href="<?= get_base_path() ?>/settings.php?tab=module_hub">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i data-lucide="external-link" style="width:18px;height:18px;color:var(--admin-primary)"></i>
            <strong>Module settings links</strong>
          </div>
          <p class="small text-muted mb-0">Open HR, RE, Construction, ARS, Inventory, and Cleaning deep-links.</p>
        </a>
      </div>
      <div class="col-md-4">
        <a class="admin-info-card h-100" href="<?= get_base_path() ?>/settings.php?tab=users">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i data-lucide="users" style="width:18px;height:18px;color:var(--admin-primary)"></i>
            <strong>Assign companies to users</strong>
          </div>
          <p class="small text-muted mb-0">Company access is granted on the Users tab (user_companies).</p>
        </a>
      </div>
      <div class="col-md-4">
        <div class="admin-info-card h-100">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i data-lucide="wrench" style="width:18px;height:18px;color:var(--admin-primary)"></i>
            <strong>Specialized tools</strong>
          </div>
          <p class="small text-muted mb-2">Optional wizards for specific modules — not the main company list.</p>
          <div class="d-flex flex-wrap gap-2">
            <a href="<?= get_base_path() ?>/setup_realestate_company.php" class="btn btn-sm btn-outline-secondary">RE setup wizard</a>
            <a href="<?= get_base_path() ?>/debug_user_access.php" target="_blank" class="btn btn-sm btn-outline-secondary">Debug access</a>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Module hub / deep-links -->
  <?php if ($tab === 'module_hub'): ?>
    <?= admin_ui_page_header('HR & module links', 'Deep-links to in-module settings (hub navigation only)', [
      ['label' => 'Administration', 'href' => get_base_path() . '/settings.php?tab=dashboard'],
      ['label' => 'Module links'],
    ], admin_ui_scope_badge('Hub')) ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-box-arrow-up-right"></i> Module configuration links</h5>
        <small class="text-muted">Deep operational settings stay in-module. Hub provides discoverable navigation only.</small>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <h6 class="text-primary"><i class="bi bi-people"></i> People (HR) <span class="badge text-bg-light border">Shared</span></h6>
              <ul class="mb-0 small">
                <li><a href="<?= get_base_path() ?>/hr/holidays.php">Holidays calendar</a></li>
                <li><a href="<?= get_base_path() ?>/hr/leave_types.php">Leave types</a></li>
                <li><a href="<?= get_base_path() ?>/settings.php?tab=cash_advance_policy">Cash advance / loan policy</a></li>
                <li><a href="<?= get_base_path() ?>/hr/org_units.php?tab=reminders">HR document expiry reminders</a> (SMTP via Email tab)</li>
              </ul>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <h6 class="text-primary"><i class="bi bi-building"></i> Real Estate <span class="badge text-bg-light border">Shared ledger</span></h6>
              <ul class="mb-0 small">
                <li><a href="<?= get_base_path() ?>/modules/realestate/accounting/accounting_settings.php">Accounting mode / Invoice Mode</a></li>
                <li><a href="<?= get_base_path() ?>/modules/realestate/amc_alert_settings.php">AMC alert settings</a></li>
                <li><a href="<?= get_base_path() ?>/settings.php?tab=re_email">RE email notification recipients</a></li>
              </ul>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <h6 class="text-primary"><i class="bi bi-hammer"></i> Construction</h6>
              <ul class="mb-0 small">
                <li><a href="<?= get_base_path() ?>/modules/construction/document_settings.php">Document / letterhead</a></li>
                <li><a href="<?= get_base_path() ?>/modules/construction/theme_settings.php">Theme</a></li>
              </ul>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <h6 class="text-primary"><i class="bi bi-house-heart"></i> ARS &amp; Inventory</h6>
              <ul class="mb-0 small">
                <li><a href="<?= get_base_path() ?>/modules/ars/settings.php">ARS commercial &amp; Stripe settings</a></li>
                <li><a href="<?= get_base_path() ?>/modules/inventory/locations.php">Inventory locations / default POS</a></li>
              </ul>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <h6 class="text-primary"><i class="bi bi-droplet"></i> Cleaning</h6>
              <ul class="mb-0 small">
                <li><a href="<?= get_base_path() ?>/settings.php?tab=accounting">Cleaning accounting (this hub)</a></li>
                <li><a href="<?= get_base_path() ?>/settings.php?tab=service_categories">Service categories</a></li>
                <li><a href="<?= get_base_path() ?>/operation/admin_settings.php">Ops gap minutes</a></li>
              </ul>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <h6 class="text-primary"><i class="bi bi-hash"></i> Documents &amp; numbering</h6>
              <p class="small text-muted mb-2">Sequence admin UI is limited today. Overview of known sequence stores:</p>
              <ul class="mb-0 small">
                <li>Cleaning invoice templates — Accounting tab</li>
                <li>Inventory <code>inv_doc_sequences</code> — used when posting inventory docs</li>
                <li>RE lease numbers — managed in Real Estate lease helpers / admin tools</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Mobile App Management -->
  <?php if ($tab === 'mobile_app' && (has_role('Owner', $conn) || has_role('Admin', $conn))): ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-phone"></i> Mobile App Management</h5>
        <small class="text-muted">
          Control Customer App versions, optional/forced updates, store links, and maintenance — without republishing app code.
          Public API: <code>GET /api/customer/v1/app-config</code>
        </small>
      </div>
      <div class="settings-body">
        <form method="post">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="update_mobile_app">
          <input type="hidden" name="tab" value="mobile_app">

          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2"><i class="bi bi-android2"></i> Android</h6>
            </div>
            <div class="col-md-6">
              <label class="form-label">Latest Version</label>
              <input type="text" name="android_latest_version" class="form-control"
                     value="<?= h($mobileAppSettings['android_latest_version'] ?? '1.0.1') ?>"
                     placeholder="1.0.2" required pattern="\d+(\.\d+){0,3}">
              <small class="text-muted">Semantic version shown as “new version available”.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Minimum Supported Version</label>
              <input type="text" name="android_min_version" class="form-control"
                     value="<?= h($mobileAppSettings['android_min_version'] ?? '1.0.0') ?>"
                     placeholder="1.0.0" required pattern="\d+(\.\d+){0,3}">
              <small class="text-muted">Installed below this → forced update.</small>
            </div>
            <div class="col-12 mt-2">
              <label class="form-label">Google Play Store URL</label>
              <input type="url" name="play_store_url" class="form-control"
                     value="<?= h($mobileAppSettings['play_store_url'] ?? '') ?>"
                     placeholder="https://play.google.com/store/apps/details?id=...">
            </div>
          </div>

          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2"><i class="bi bi-apple"></i> iOS</h6>
            </div>
            <div class="col-md-6">
              <label class="form-label">Latest Version</label>
              <input type="text" name="ios_latest_version" class="form-control"
                     value="<?= h($mobileAppSettings['ios_latest_version'] ?? '1.0.1') ?>"
                     placeholder="1.0.2" required pattern="\d+(\.\d+){0,3}">
            </div>
            <div class="col-md-6">
              <label class="form-label">Minimum Supported Version</label>
              <input type="text" name="ios_min_version" class="form-control"
                     value="<?= h($mobileAppSettings['ios_min_version'] ?? '1.0.0') ?>"
                     placeholder="1.0.0" required pattern="\d+(\.\d+){0,3}">
            </div>
            <div class="col-12 mt-2">
              <label class="form-label">Apple App Store URL</label>
              <input type="url" name="app_store_url" class="form-control"
                     value="<?= h($mobileAppSettings['app_store_url'] ?? '') ?>"
                     placeholder="https://apps.apple.com/app/id...">
            </div>
          </div>

          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2"><i class="bi bi-arrow-up-circle"></i> Update Policy</h6>
            </div>
            <div class="col-md-6">
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="force_update" id="forceUpdate"
                       value="1" <?= !empty($mobileAppSettings['force_update']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="forceUpdate">
                  Force Update (Yes / No)
                </label>
              </div>
              <small class="text-muted">When enabled, all users must update before continuing (even if above minimum).</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Update Title</label>
              <input type="text" name="update_title" class="form-control" maxlength="200"
                     value="<?= h($mobileAppSettings['update_title'] ?? 'Update Available') ?>">
            </div>
            <div class="col-12 mt-2">
              <label class="form-label">Update Message</label>
              <textarea name="update_message" class="form-control" rows="3"><?= h($mobileAppSettings['update_message'] ?? '') ?></textarea>
            </div>
          </div>

          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2">
                <i class="bi bi-tools"></i> Maintenance Mode
                <span class="badge text-bg-secondary">Prepared</span>
              </h6>
              <p class="small text-muted mb-2">
                Fields are live for the Customer App gate. Expand with richer notices / holiday banners later via feature flags.
              </p>
            </div>
            <div class="col-md-6">
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenanceMode"
                       value="1" <?= !empty($mobileAppSettings['maintenance_mode']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="maintenanceMode">Maintenance Mode (On / Off)</label>
              </div>
            </div>
            <div class="col-12 mt-2">
              <label class="form-label">Maintenance Message</label>
              <textarea name="maintenance_message" class="form-control" rows="2"><?= h($mobileAppSettings['maintenance_message'] ?? '') ?></textarea>
            </div>
          </div>

          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2"><i class="bi bi-share"></i> Property Sharing</h6>
              <p class="small text-muted mb-2">
                Controls Find Your Home share links, App Links / Universal Links association files, and the store redirect when the app is not installed.
                Shared links never return unpublished or occupied listing details — resolve returns unavailable only.
              </p>
            </div>
            <div class="col-md-6">
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="property_share_enabled" id="propertyShareEnabled"
                       value="1" <?= !empty($mobileAppSettings['property_share_enabled']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="propertyShareEnabled">Enable Property Sharing</label>
              </div>
              <small class="text-muted">When off, Share is hidden in the app and resolve returns unavailable.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Share Base URL</label>
              <input type="url" name="share_base_url" class="form-control"
                     value="<?= h($mobileAppSettings['share_base_url'] ?? '') ?>"
                     placeholder="https://sys.saifholdinggroup.com">
              <small class="text-muted">HTTPS host (or path base) for <code>/p/{share_code}</code>. Leave blank to use current site.</small>
            </div>
            <div class="col-12 mt-2">
              <label class="form-label">Share Message Template</label>
              <textarea name="share_message_template" class="form-control font-monospace" rows="4"
                        placeholder="Check out this property:&#10;{title}&#10;{url}"><?= h($mobileAppSettings['share_message_template'] ?? '') ?></textarea>
              <small class="text-muted">Placeholders: <code>{title}</code> <code>{building}</code> <code>{location}</code> <code>{rent}</code> <code>{url}</code></small>
            </div>
            <div class="col-md-6 mt-3">
              <label class="form-label">Android Package Name</label>
              <input type="text" name="android_package_name" class="form-control"
                     value="<?= h($mobileAppSettings['android_package_name'] ?? 'com.ainalreem.living') ?>">
            </div>
            <div class="col-md-6 mt-3">
              <label class="form-label">Android SHA-256 Fingerprints</label>
              <textarea name="android_sha256_fingerprints" class="form-control font-monospace" rows="2"
                        placeholder="AA:BB:CC:... (one per line or comma-separated)"><?= h($mobileAppSettings['android_sha256_fingerprints'] ?? '') ?></textarea>
              <small class="text-muted">Used by <code>/.well-known/assetlinks.json</code> for App Links.</small>
            </div>
            <div class="col-md-6 mt-3">
              <label class="form-label">iOS Team ID</label>
              <input type="text" name="ios_team_id" class="form-control" maxlength="32"
                     value="<?= h($mobileAppSettings['ios_team_id'] ?? '') ?>"
                     placeholder="ABCDE12345">
            </div>
            <div class="col-md-6 mt-3">
              <label class="form-label">iOS Bundle ID</label>
              <input type="text" name="ios_bundle_id" class="form-control"
                     value="<?= h($mobileAppSettings['ios_bundle_id'] ?? 'com.ainalreem.living') ?>">
              <small class="text-muted">Used by <code>/.well-known/apple-app-site-association</code>.</small>
            </div>
          </div>

          <div class="row mb-4">
            <div class="col-12">
              <h6 class="text-primary border-bottom pb-2"><i class="bi bi-flag"></i> Feature Flags (future-ready)</h6>
              <label class="form-label">JSON object</label>
              <textarea name="feature_flags_json" class="form-control font-monospace" rows="4"
                        placeholder='{"ai_enabled":false,"holiday_banner":""}'><?= h($mobileAppSettings['feature_flags_json'] ?? '{}') ?></textarea>
              <small class="text-muted">Returned on <code>app-config</code> as <code>feature_flags</code>. App can adopt flags later without architecture changes.</small>
            </div>
          </div>

          <?php if (!empty($mobileAppSettings['updated_at'])): ?>
            <p class="small text-muted">Last updated: <?= h((string)$mobileAppSettings['updated_at']) ?></p>
          <?php endif; ?>

          <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary btn-lg">
              <i class="bi bi-check-circle"></i> Save Mobile App Settings
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- Integrations (status only — never print secret values) -->
  <?php if ($tab === 'integrations' && (has_role('Owner', $conn) || has_role('Admin', $conn))): ?>
    <?php
      $jwtConfigured = (defined('JWT_SECRET') && JWT_SECRET !== '')
        || (getenv('JWT_SECRET') !== false && getenv('JWT_SECRET') !== '');
      $stripeStatus = 'Not checked';
      try {
        if ($settingsCompanyId > 0) {
          $st = $conn->prepare('SELECT stripe_enabled, stripe_mode FROM ars_company_settings WHERE company_id = ? LIMIT 1');
          $st->execute([$settingsCompanyId]);
          $row = $st->fetch(PDO::FETCH_ASSOC);
          if ($row) {
            $stripeStatus = ((int)$row['stripe_enabled'] === 1 ? 'Enabled' : 'Disabled')
              . ' · mode ' . ($row['stripe_mode'] ?: 'n/a');
          } else {
            $stripeStatus = 'No ARS settings row for selected company';
          }
        } else {
          $stripeStatus = 'Select a company to view ARS Stripe status';
        }
      } catch (Throwable $e) {
        $stripeStatus = 'ARS Stripe table unavailable';
      }
    ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-plug"></i> Integrations <span class="badge text-bg-secondary">Status only</span></h5>
        <small class="text-muted">Never displays secret key values. Configure Stripe inside ARS settings.</small>
      </div>
      <div class="card-body">
        <table class="table table-sm">
          <tbody>
            <tr>
              <th>Mobile / API JWT</th>
              <td><?= $jwtConfigured ? '<span class="badge text-bg-success">Configured in env</span>' : '<span class="badge text-bg-warning">Not detected in env</span>' ?></td>
            </tr>
            <tr>
              <th>ARS Stripe (selected company)</th>
              <td><?= h($stripeStatus) ?> · <a href="<?= get_base_path() ?>/modules/ars/settings.php">Open ARS settings</a></td>
            </tr>
            <tr>
              <th>SMTP</th>
              <td><?= !empty($emailSettings['is_enabled']) ? 'Enabled' : 'Disabled' ?> · <a href="<?= get_base_path() ?>/settings.php?tab=email">Email settings</a></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- Security & audit retention -->
  <?php if ($tab === 'security'): ?>
    <div class="settings-card">
      <div class="settings-header">
        <h5 class="mb-0"><i class="bi bi-shield-lock"></i> Security &amp; audit retention</h5>
        <small class="text-muted">Owner controls for audit archive. Login/password policy UI is future work.</small>
      </div>
      <div class="card-body">
        <form method="POST" class="row g-3 mb-4">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="update_audit_retention">
          <input type="hidden" name="tab" value="security">
          <div class="col-md-4">
            <label class="form-label">Keep audit rows in hot table (months)</label>
            <input type="number" class="form-control" name="audit_retention_months" min="6" max="120"
                   value="<?= (int)$auditRetentionMonths ?>">
            <div class="form-text">Older rows move to <code>audit_log_archive</code> when you run archive.</div>
          </div>
          <div class="col-md-4 align-self-end">
            <button type="submit" class="btn btn-primary">Save retention</button>
          </div>
        </form>
        <form method="POST" onsubmit="return confirm('Archive audit_log rows older than the retention period?');">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="run_audit_archive">
          <input type="hidden" name="tab" value="security">
          <button type="submit" class="btn btn-outline-danger">
            <i class="bi bi-archive"></i> Run archive now
          </button>
          <span class="text-muted small ms-2">Also available via CLI: <code>php tools/audit_log_retention.php</code></span>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- Audit History Tab — Activity Center -->
  <?php if ($tab === 'history'): ?>
    <?= admin_ui_page_header('Audit History', 'Owner activity center — plain-language events across companies and modules', [
      ['label' => 'Administration', 'href' => get_base_path() . '/settings.php?tab=dashboard'],
      ['label' => 'Audit History'],
    ]) ?>
    <div class="row g-3 mb-3" id="auditKpiRow">
      <div class="col-6 col-lg-3"><div class="admin-kpi"><div class="kpi-label">In range</div><div class="kpi-value" id="kpiTotal">—</div><div class="kpi-sub">Matching filters</div></div></div>
      <div class="col-6 col-lg-3"><div class="admin-kpi"><div class="kpi-label">Success</div><div class="kpi-value text-success" id="kpiSuccess">—</div><div class="kpi-sub">OK events</div></div></div>
      <div class="col-6 col-lg-3"><div class="admin-kpi"><div class="kpi-label">Failures</div><div class="kpi-value" style="color:var(--admin-danger)" id="kpiFail">—</div><div class="kpi-sub">Needs attention</div></div></div>
      <div class="col-6 col-lg-3"><div class="admin-kpi"><div class="kpi-label">API / system</div><div class="kpi-value" id="kpiAuto">—</div><div class="kpi-sub">Non-user sources</div></div></div>
    </div>
    <div class="admin-settings-card">
      <div class="settings-header">
        <h5 class="mb-0">Activity feed</h5>
        <small class="text-muted">Filter, search, and inspect event details in the side drawer</small>
      </div>
      <div class="card-body">
        <div class="alert alert-light border small mb-3" id="auditSchemaHint" style="display:none;"></div>
        
        <!-- Filters -->
        <form id="historyFilters" class="admin-filter-bar mb-3">
          <div class="row g-3">
            <div class="col-md-2">
              <label class="form-label small fw-semibold">Date From</label>
              <input type="date" name="date_from" id="date_from" class="form-control form-control-sm" 
                     value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-semibold">Date To</label>
              <input type="date" name="date_to" id="date_to" class="form-control form-control-sm" 
                     value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-semibold">Company</label>
              <select name="company_id" id="filter_company" class="form-select form-select-sm">
                <option value="">All companies</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-semibold">Module</label>
              <select name="module" id="filter_module" class="form-select form-select-sm">
                <option value="">All modules</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-semibold">User</label>
              <select name="user_id" id="filter_user" class="form-select form-select-sm">
                <option value="">All users</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-semibold">Action</label>
              <select name="action" id="filter_action" class="form-select form-select-sm">
                <option value="">All actions</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-semibold">Status</label>
              <select name="success" id="filter_success" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="1" selected>Success only</option>
                <option value="0">Failures only</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-semibold">Performed by</label>
              <select name="source" id="filter_source" class="form-select form-select-sm">
                <option value="">Anyone / any source</option>
                <option value="user">Users</option>
                <option value="api">API</option>
                <option value="system">System</option>
                <option value="job">Scheduled jobs</option>
                <option value="automated">API + System + Jobs</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Search</label>
              <input type="text" name="search" id="filter_search" class="form-control form-control-sm" 
                     placeholder="Search summary or reference (e.g. Lease #, Order #)…">
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="include_views" id="include_views" value="1">
                <label class="form-check-label small" for="include_views">Include view events</label>
              </div>
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-semibold">&nbsp;</label>
              <div class="d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                  <i class="bi bi-funnel"></i> Filter
                </button>
                <button type="button" id="btnResetFilters" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-arrow-counterclockwise"></i>
                </button>
              </div>
            </div>
          </div>
        </form>

        <!-- Export Button -->
        <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
          <button id="btnExportCSV" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet"></i> Export to CSV
          </button>
          <span class="text-muted small" id="recordCount"></span>
          <span class="text-muted small" id="exportCapHint"></span>
        </div>

        <!-- Loading State -->
        <div id="loadingState" class="text-center py-5" style="display:none;">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="text-muted mt-2">Loading audit history...</p>
        </div>

        <!-- Error State -->
        <div id="errorState" class="alert alert-danger" style="display:none;"></div>

        <!-- Results Table -->
        <div class="admin-table-shell" id="resultsContainer">
          <div class="table-responsive">
          <table class="table table-hover table-sm align-middle mb-0" id="historyTable">
            <thead>
              <tr>
                <th style="width: 130px;">When</th>
                <th style="width: 140px;">Who</th>
                <th style="width: 130px;">Action</th>
                <th style="width: 110px;">Company</th>
                <th style="width: 100px;">Module</th>
                <th style="width: 120px;">Record</th>
                <th>What happened</th>
                <th style="width: 70px;" class="text-center">OK</th>
                <th style="width: 70px;" class="text-center">Details</th>
              </tr>
            </thead>
            <tbody id="historyTableBody">
              <tr>
                <td colspan="9" class="text-center text-muted py-4">
                  <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                  <p class="mb-0 mt-2">Click Filter to load activity history</p>
                </td>
              </tr>
            </tbody>
          </table>
          </div>
        </div>

        <!-- Pagination -->
        <nav aria-label="Audit history pagination" id="paginationContainer" class="mt-3" style="display:none;">
          <ul class="pagination pagination-sm justify-content-center mb-0" id="pagination">
          </ul>
        </nav>

      </div>
    </div>
    <!-- Detail field holders (rendered into Admin drawer by JS) -->
    <div id="auditDetailStore" class="d-none" aria-hidden="true">
      <span id="detail_user"></span>
      <span id="detail_timestamp"></span>
      <span id="detail_action"></span>
      <span id="detail_object"></span>
      <span id="detail_company"></span>
      <span id="detail_module"></span>
      <span id="detail_source"></span>
      <span id="detail_summary"></span>
      <span id="detail_ip"></span>
      <span id="detail_status"></span>
      <span id="detail_error"></span>
      <pre id="detail_old_data"></pre>
      <pre id="detail_new_data"></pre>
      <div id="detail_error_container"></div>
      <div id="oldDataContainer"></div>
      <div id="newDataContainer"></div>
      <div id="dataSection"></div>
    </div>
  <?php endif; ?>

  <!-- Invoice Templates Modal -->
  <div class="modal fade" id="invoiceTemplatesModal" tabindex="-1" aria-labelledby="invoiceTemplatesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="invoiceTemplatesModalLabel">
            <i class="bi bi-file-earmark-text"></i> Invoice Templates
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <!-- Template List -->
          <div class="row mb-4">
            <div class="col-md-8">
              <h6>Available Templates</h6>
              <div id="templatesList" class="list-group">
                <!-- Templates will be loaded here -->
              </div>
            </div>
            <div class="col-md-4">
              <div class="d-grid gap-2">
                <button class="btn btn-success" id="btnNewTemplate">
                  <i class="bi bi-plus-circle"></i> New Template
                </button>
                <button class="btn btn-outline-primary" id="btnPreviewTemplate" disabled>
                  <i class="bi bi-eye"></i> Preview
                </button>
              </div>
            </div>
          </div>

          <!-- Template Editor -->
          <div id="templateEditor" style="display: none;">
            <form id="templateForm" enctype="multipart/form-data">
              <input type="hidden" id="templateId" name="template_id" value="0">
              
              <!-- Basic Settings -->
              <div class="row mb-4">
                <div class="col-12">
                  <h6 class="text-primary border-bottom pb-2">
                    <i class="bi bi-gear"></i> Basic Settings
                  </h6>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Template Name *</label>
                  <input type="text" class="form-control" id="templateName" name="name" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Invoice Title</label>
                  <input type="text" class="form-control" id="invoiceTitle" name="invoice_title" value="TAX INVOICE">
                </div>
              </div>

              <!-- Color Scheme -->
              <div class="row mb-4">
                <div class="col-12">
                  <h6 class="text-primary border-bottom pb-2">
                    <i class="bi bi-palette"></i> Color Scheme
                  </h6>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Primary Color</label>
                  <input type="color" class="form-control form-control-color" id="primaryColor" name="primary_color" value="#0b2a4a">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Accent Color</label>
                  <input type="color" class="form-control form-control-color" id="accentColor" name="accent_color" value="#e53935">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Background</label>
                  <input type="color" class="form-control form-control-color" id="backgroundColor" name="background_color" value="#ffffff">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Text Color</label>
                  <input type="color" class="form-control form-control-color" id="textColor" name="text_color" value="#333333">
                </div>
              </div>

              <!-- Header Settings -->
              <div class="row mb-4">
                <div class="col-12">
                  <h6 class="text-primary border-bottom pb-2">
                    <i class="bi bi-layout-text-window"></i> Header Settings
                  </h6>
                </div>
                <div class="col-md-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showCompanyName" name="show_company_name" checked>
                    <label class="form-check-label" for="showCompanyName">Show Company Name</label>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showLogo" name="show_logo" checked>
                    <label class="form-check-label" for="showLogo">Show Logo</label>
                  </div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Header Layout</label>
                  <select class="form-select" id="headerLayout" name="header_layout">
                    <option value="logo_left">Logo Left</option>
                    <option value="logo_center">Logo Center</option>
                    <option value="logo_right">Logo Right</option>
                  </select>
                </div>
              </div>

              <!-- Invoice Details -->
              <div class="row mb-4">
                <div class="col-12">
                  <h6 class="text-primary border-bottom pb-2">
                    <i class="bi bi-receipt"></i> Invoice Details
                  </h6>
                </div>
                <div class="col-md-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showInvoiceNumber" name="show_invoice_number" checked>
                    <label class="form-check-label" for="showInvoiceNumber">Show Invoice Number</label>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showInvoiceDate" name="show_invoice_date" checked>
                    <label class="form-check-label" for="showInvoiceDate">Show Invoice Date</label>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showDueDate" name="show_due_date" checked>
                    <label class="form-check-label" for="showDueDate">Show Due Date</label>
                  </div>
                </div>
              </div>

              <!-- Layout Options -->
              <div class="row mb-4">
                <div class="col-12">
                  <h6 class="text-primary border-bottom pb-2">
                    <i class="bi bi-layout-three-columns"></i> Layout Options
                  </h6>
                </div>
                <div class="col-md-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showBillTo" name="show_bill_to" checked>
                    <label class="form-check-label" for="showBillTo">Show Bill To</label>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showCompanyInfo" name="show_company_info" checked>
                    <label class="form-check-label" for="showCompanyInfo">Show Company Info</label>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showBankDetails" name="show_bank_details" checked>
                    <label class="form-check-label" for="showBankDetails">Show Bank Details</label>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="showSignature" name="show_signature" checked>
                    <label class="form-check-label" for="showSignature">Show Signature</label>
                  </div>
                </div>
              </div>

              <!-- Table Styling -->
              <div class="row mb-4">
                <div class="col-12">
                  <h6 class="text-primary border-bottom pb-2">
                    <i class="bi bi-table"></i> Table Styling
                  </h6>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Header Background</label>
                  <input type="color" class="form-control form-control-color" id="tableHeaderBg" name="table_header_bg" value="#eef0f3">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Stripe Background</label>
                  <input type="color" class="form-control form-control-color" id="tableStripeBg" name="table_stripe_bg" value="#f5f6f8">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Border Color</label>
                  <input type="color" class="form-control form-control-color" id="tableBorderColor" name="table_border_color" value="#dee2e6">
                </div>
              </div>

              <!-- Footer Settings -->
              <div class="row mb-4">
                <div class="col-12">
                  <h6 class="text-primary border-bottom pb-2">
                    <i class="bi bi-text-paragraph"></i> Footer Settings
                  </h6>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Footer Text</label>
                  <input type="text" class="form-control" id="footerText" name="footer_text" value="Thank you for your business">
                </div>
                <div class="col-md-6">
                  <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="showAmountInWords" name="show_amount_in_words" checked>
                    <label class="form-check-label" for="showAmountInWords">Show Amount in Words</label>
                  </div>
                </div>
                <div class="col-12 mt-3">
                  <label class="form-label">Signature Image</label>
                  <input type="file" class="form-control" id="signatureFile" name="signature_file" accept="image/*">
                  <div class="form-text">Upload a signature image (PNG, JPG, JPEG, GIF). Max size: 2MB</div>
                  <div id="signaturePreview" class="mt-2" style="display: none;">
                    <img id="signaturePreviewImg" src="" alt="Signature Preview" style="max-width: 200px; max-height: 100px; border: 1px solid #ddd; border-radius: 4px;">
                    <div class="mt-1">
                      <button type="button" class="btn btn-sm btn-outline-danger" id="removeSignature">Remove Signature</button>
                    </div>
                  </div>
                  <input type="hidden" id="currentSignaturePath" name="signature_path" value="">
                </div>
              </div>

              <!-- Font Settings -->
              <div class="row mb-4">
                <div class="col-12">
                  <h6 class="text-primary border-bottom pb-2">
                    <i class="bi bi-type"></i> Font Settings
                  </h6>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Font Family</label>
                  <select class="form-select" id="fontFamily" name="font_family">
                    <option value="system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, sans-serif">System Default</option>
                    <option value="Arial, sans-serif">Arial</option>
                    <option value="'Times New Roman', serif">Times New Roman</option>
                    <option value="'Courier New', monospace">Courier New</option>
                    <option value="Georgia, serif">Georgia</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Base Font Size</label>
                  <select class="form-select" id="fontSizeBase" name="font_size_base">
                    <option value="12px">12px</option>
                    <option value="14px" selected>14px</option>
                    <option value="16px">16px</option>
                    <option value="18px">18px</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Title Font Size</label>
                  <select class="form-select" id="fontSizeTitle" name="font_size_title">
                    <option value="20px">20px</option>
                    <option value="22px">22px</option>
                    <option value="24px" selected>24px</option>
                    <option value="28px">28px</option>
                  </select>
                </div>
              </div>

              <!-- Action Buttons -->
              <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" id="btnCancelEdit">Cancel</button>
                <button type="button" class="btn btn-danger" id="btnDeleteTemplate" style="display: none;">Delete</button>
                <button type="submit" class="btn btn-success">Save Template</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Preview Modal -->
  <div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="previewModalLabel">Template Preview</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-0">
          <iframe id="previewFrame" src="about:blank" style="width: 100%; height: 100vh; border: none;"></iframe>
        </div>
      </div>
    </div>
  </div>

<?php require __DIR__ . '/includes/admin/admin_layout_footer.php'; ?>
<script>
// Role assignment modal
document.addEventListener('DOMContentLoaded', function() {
  const roleModal = document.getElementById('roleModal');
  if (roleModal) {
    roleModal.addEventListener('show.bs.modal', function (event) {
      const button = event.relatedTarget;
      const userId = button.getAttribute('data-user-id');
      const userName = button.getAttribute('data-user-name');
      const userRoles = button.getAttribute('data-user-roles');
      
      document.getElementById('modal-user-id').value = userId;
      document.getElementById('modal-user-name').textContent = userName;
      
      // Clear all checkboxes
      document.querySelectorAll('input[name="roles[]"]').forEach(cb => cb.checked = false);
      
      // Check user's current roles
      if (userRoles) {
        const roles = userRoles.split(',');
        roles.forEach(role => {
          const checkbox = document.querySelector(`input[value="${role.trim()}"]`);
          if (checkbox) checkbox.checked = true;
        });
      }
    });
  }
});

// Invoice Templates JavaScript
let currentTemplateId = null;
let templates = [];

// Load templates
async function loadTemplates() {
  try {
    const response = await fetch('settings_ajax_invoice_templates.php?action=get_templates');
    const data = await response.json();
    
    if (data.success) {
      templates = data.templates;
      renderTemplatesList();
      // Enable preview button if there are templates
      if (templates.length > 0) {
        document.getElementById('btnPreviewTemplate').disabled = false;
      }
    } else {
      console.error('Failed to load templates:', data.error);
      alert('Failed to load templates: ' + data.error);
    }
  } catch (error) {
    console.error('Error loading templates:', error);
    alert('Error loading templates: ' + error.message);
  }
}

// Render templates list
function renderTemplatesList() {
  const container = document.getElementById('templatesList');
  container.innerHTML = '';
  
  templates.forEach(template => {
    const item = document.createElement('div');
    item.className = `list-group-item list-group-item-action d-flex justify-content-between align-items-center ${template.is_default ? 'active' : ''}`;
    item.innerHTML = `
      <div>
        <h6 class="mb-1">${template.name} ${template.is_default ? '<span class="badge bg-primary ms-2">Default</span>' : ''}</h6>
        <small class="text-muted">Created: ${new Date(template.created_at).toLocaleDateString()}</small>
      </div>
      <div class="btn-group" role="group">
        <button class="btn btn-sm btn-outline-primary" onclick="editTemplate(${template.id})">
          <i class="bi bi-pencil"></i> Edit
        </button>
        <button class="btn btn-sm btn-outline-info" onclick="previewTemplateById(${template.id})">
          <i class="bi bi-eye"></i> Preview
        </button>
        ${!template.is_default ? `
          <button class="btn btn-sm btn-outline-success" onclick="setDefaultTemplate(${template.id})">
            <i class="bi bi-star"></i> Set Default
          </button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteTemplate(${template.id})">
            <i class="bi bi-trash"></i> Delete
          </button>
        ` : ''}
      </div>
    `;
    container.appendChild(item);
  });
}

// Edit template
async function editTemplate(templateId) {
  try {
    const response = await fetch(`settings_ajax_invoice_templates.php?action=get_template&id=${templateId}`);
    const data = await response.json();
    
    if (data.success) {
      const template = data.template;
      currentTemplateId = templateId;
      
      // Populate form
      document.getElementById('templateId').value = template.id;
      document.getElementById('templateName').value = template.name;
      document.getElementById('invoiceTitle').value = template.invoice_title;
      document.getElementById('primaryColor').value = template.primary_color;
      document.getElementById('accentColor').value = template.accent_color;
      document.getElementById('backgroundColor').value = template.background_color;
      document.getElementById('textColor').value = template.text_color;
      document.getElementById('showCompanyName').checked = template.show_company_name == 1;
      document.getElementById('showLogo').checked = template.show_logo == 1;
      document.getElementById('headerLayout').value = template.header_layout;
      document.getElementById('showInvoiceNumber').checked = template.show_invoice_number == 1;
      document.getElementById('showInvoiceDate').checked = template.show_invoice_date == 1;
      document.getElementById('showDueDate').checked = template.show_due_date == 1;
      document.getElementById('showBillTo').checked = template.show_bill_to == 1;
      document.getElementById('showCompanyInfo').checked = template.show_company_info == 1;
      document.getElementById('showBankDetails').checked = template.show_bank_details == 1;
      document.getElementById('showSignature').checked = template.show_signature == 1;
      document.getElementById('tableHeaderBg').value = template.table_header_bg;
      document.getElementById('tableStripeBg').value = template.table_stripe_bg;
      document.getElementById('tableBorderColor').value = template.table_border_color;
      document.getElementById('footerText').value = template.footer_text;
      document.getElementById('showAmountInWords').checked = template.show_amount_in_words == 1;
      document.getElementById('fontFamily').value = template.font_family;
      document.getElementById('fontSizeBase').value = template.font_size_base;
      document.getElementById('fontSizeTitle').value = template.font_size_title;
      
      // Handle signature
      if (template.signature_path) {
        document.getElementById('currentSignaturePath').value = template.signature_path;
        document.getElementById('signaturePreviewImg').src = template.signature_path;
        document.getElementById('signaturePreview').style.display = 'block';
      } else {
        document.getElementById('currentSignaturePath').value = '';
        document.getElementById('signaturePreview').style.display = 'none';
      }
      
      // Show editor
      document.getElementById('templateEditor').style.display = 'block';
      document.getElementById('btnDeleteTemplate').style.display = template.is_default ? 'none' : 'inline-block';
      document.getElementById('btnPreviewTemplate').disabled = false;
    } else {
      alert('Failed to load template: ' + data.error);
    }
  } catch (error) {
    console.error('Error loading template:', error);
    alert('Error loading template: ' + error.message);
  }
}

// Set default template
async function setDefaultTemplate(templateId) {
  if (!confirm('Are you sure you want to set this template as default?')) return;
  
  try {
    const formData = new FormData();
    formData.append('action', 'set_default');
    formData.append('template_id', templateId);
    
    const response = await fetch('settings_ajax_invoice_templates.php', {
      method: 'POST',
      body: formData
    });
    
    const data = await response.json();
    
    if (data.success) {
      alert('Default template updated successfully');
      loadTemplates();
    } else {
      alert('Failed to update default template: ' + data.error);
    }
  } catch (error) {
    console.error('Error setting default template:', error);
    alert('Error setting default template: ' + error.message);
  }
}

// Delete template
async function deleteTemplate(templateId) {
  if (!confirm('Are you sure you want to delete this template? This action cannot be undone.')) return;
  
  try {
    const formData = new FormData();
    formData.append('action', 'delete_template');
    formData.append('template_id', templateId);
    
    const response = await fetch('settings_ajax_invoice_templates.php', {
      method: 'POST',
      body: formData
    });
    
    const data = await response.json();
    
    if (data.success) {
      alert('Template deleted successfully');
      loadTemplates();
      if (currentTemplateId == templateId) {
        document.getElementById('templateEditor').style.display = 'none';
        currentTemplateId = null;
      }
    } else {
      alert('Failed to delete template: ' + data.error);
    }
  } catch (error) {
    console.error('Error deleting template:', error);
    alert('Error deleting template: ' + error.message);
  }
}

// Preview template by ID
async function previewTemplateById(templateId) {
  currentTemplateId = templateId;
  await previewTemplate();
}

// Preview template
async function previewTemplate() {
  if (!currentTemplateId) return;
  
  try {
    const response = await fetch(`settings_ajax_invoice_templates.php?action=preview_template&template_id=${currentTemplateId}`);
    const data = await response.json();
    
    if (data.success) {
      // Create preview page
      const previewContent = `
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="utf-8">
          <title>Template Preview</title>
          <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
          <style>${data.css}</style>
        </head>
        <body>
          <div class="page">
            <div class="d-flex justify-content-between align-items-center">
              <div class="d-flex align-items-center gap-3">
                <div class="logo" style="width: 100px; height: 60px; background: #f0f0f0; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center; font-size: 12px;">LOGO</div>
                <div class="small">
                  <div class="fw-semibold">Your Company Name</div>
                </div>
              </div>
              <div class="text-end small">
                <div><span class="fw-semibold">Invoice #</span> INV-2025-000001</div>
                <div><span class="fw-semibold">Invoice Date</span> 2025-01-01</div>
              </div>
            </div>
            
            <div class="bar-top">
              <div class="bar-left">Invoice # <span class="fw-semibold">INV-2025-000001</span></div>
              <div class="bar-mid">Date</div>
              <div class="bar-right">2025-01-01</div>
            </div>
            
            <h2 class="title">${data.template.invoice_title}</h2>
            
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <div class="panel">
                  <div class="fw-semibold mb-2">Bill to :</div>
                  <pre>John Doe
123 Main Street
City, State 12345
Phone: (555) 123-4567
john@example.com</pre>
                </div>
              </div>
              <div class="col-md-6">
                <div class="panel">
                  <div class="fw-semibold mb-2">Company</div>
                  <pre>Your Company Name
Your Address
City, State 12345
Tel: (555) 987-6543
info@yourcompany.com
TRN: 123456789012345</pre>
                </div>
              </div>
            </div>
            
            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th style="width:8%">No</th>
                    <th>Description</th>
                    <th class="text-end" style="width:16%">Price</th>
                    <th class="text-end" style="width:12%">Quantity</th>
                    <th class="text-end" style="width:16%">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="fw-semibold">1</td>
                    <td>Sample Service Description</td>
                    <td class="text-end">100.00</td>
                    <td class="text-end">1</td>
                    <td class="text-end">100.00</td>
                  </tr>
                </tbody>
              </table>
            </div>
            
            <div class="row mt-3 align-items-start">
              <div class="col-md-6">
                <div class="mb-3">
                  <div class="fw-semibold" style="color:${data.template.accent_color}">Bank Name: Sample Bank</div>
                  <div>Account No: 1234567890</div>
                  <div>IBAN: AE123456789012345678901</div>
                  <div>Swift Code: SAMPLEXXX</div>
                </div>
              </div>
              
              <div class="col-md-6 d-flex justify-content-end">
                <div class="totals-box">
                  <div class="row">
                    <div class="col-6 cell">Sub Total</div>
                    <div class="col-6 cell text-end">100.00</div>
                  </div>
                  <div class="row">
                    <div class="col-6 cell">Discount</div>
                    <div class="col-6 cell text-end">0.00</div>
                  </div>
                  <div class="row">
                    <div class="col-6 cell label">VAT</div>
                    <div class="col-6 cell label text-end">5.00%</div>
                  </div>
                  <div class="row">
                    <div class="col-6 cell">VAT Amount</div>
                    <div class="col-6 cell text-end">5.00</div>
                  </div>
                  <div class="row">
                    <div class="col-6 cell label">Total</div>
                    <div class="col-6 cell label text-end">105.00</div>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="mt-3"><strong>Amount in words:</strong> One hundred five dirhams only</div>
            
            <div class="d-flex justify-content-between align-items-end mt-5">
              <div class="text-muted">${data.template.footer_text}</div>
              <div class="text-center">
                ${data.template.signature_path ? 
                  `<img src="${data.template.signature_path}" alt="Signature" style="max-width: 200px; max-height: 80px; object-fit: contain;"><br><small>Signature</small>` : 
                  '<div class="signature"></div><br><small>Signature</small>'
                }
              </div>
            </div>
          </div>
        </body>
        </html>
      `;
      
      // Open preview modal
      const previewModal = new bootstrap.Modal(document.getElementById('previewModal'));
      const previewFrame = document.getElementById('previewFrame');
      previewFrame.srcdoc = previewContent;
      previewModal.show();
    } else {
      alert('Failed to generate preview: ' + data.error);
    }
  } catch (error) {
    console.error('Error generating preview:', error);
    alert('Error generating preview: ' + error.message);
  }
}

// Event listeners - with null checks
document.addEventListener('DOMContentLoaded', function() {
  // Check if elements exist before adding event listeners
  const btnNewTemplate = document.getElementById('btnNewTemplate');
  const btnCancelEdit = document.getElementById('btnCancelEdit');
  const btnPreviewTemplate = document.getElementById('btnPreviewTemplate');
  const templateForm = document.getElementById('templateForm');
  const invoiceTemplatesModal = document.getElementById('invoiceTemplatesModal');
  
  if (btnNewTemplate) {
    btnNewTemplate.addEventListener('click', function() {
      currentTemplateId = null;
      document.getElementById('templateForm').reset();
      document.getElementById('templateId').value = '0';
      document.getElementById('templateEditor').style.display = 'block';
      document.getElementById('btnDeleteTemplate').style.display = 'none';
      document.getElementById('btnPreviewTemplate').disabled = true;
    });
  }
  
  if (btnCancelEdit) {
    btnCancelEdit.addEventListener('click', function() {
      document.getElementById('templateEditor').style.display = 'none';
      currentTemplateId = null;
    });
  }
  
  if (btnPreviewTemplate) {
    btnPreviewTemplate.addEventListener('click', function() {
      // If no template is being edited, preview the default template
      if (!currentTemplateId && templates.length > 0) {
        const defaultTemplate = templates.find(t => t.is_default);
        if (defaultTemplate) {
          previewTemplateById(defaultTemplate.id);
        }
      } else {
        previewTemplate();
      }
    });
  }
  
  if (templateForm) {
    templateForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      try {
        const formData = new FormData(this);
        formData.append('action', 'save_template');
        
        // Debug: Log form data
        console.log('Form data being sent:');
        for (let [key, value] of formData.entries()) {
          console.log(key, value);
        }
        
        const response = await fetch('settings_ajax_invoice_templates.php', {
          method: 'POST',
          body: formData
        });
        
        const responseText = await response.text();
        console.log('Raw response:', responseText);
        
        let data;
        try {
          data = JSON.parse(responseText);
        } catch (parseError) {
          console.error('JSON parse error:', parseError);
          console.error('Response was:', responseText);
          throw new Error('Invalid response from server');
        }
        
        if (data.success) {
          alert('Template saved successfully');
          loadTemplates();
          document.getElementById('templateEditor').style.display = 'none';
          currentTemplateId = null;
        } else {
          alert('Failed to save template: ' + data.error);
        }
      } catch (error) {
        console.error('Error saving template:', error);
        alert('Error saving template: ' + error.message);
      }
    });
  }
  
  // Load templates when modal opens
  if (invoiceTemplatesModal) {
    invoiceTemplatesModal.addEventListener('show.bs.modal', function() {
      loadTemplates();
    });
  }
  
  // Signature file handling
  const signatureFile = document.getElementById('signatureFile');
  const signaturePreview = document.getElementById('signaturePreview');
  const signaturePreviewImg = document.getElementById('signaturePreviewImg');
  const removeSignatureBtn = document.getElementById('removeSignature');
  const currentSignaturePath = document.getElementById('currentSignaturePath');
  
  if (signatureFile) {
    signatureFile.addEventListener('change', function(e) {
      const file = e.target.files[0];
      if (file) {
        // Validate file size (2MB max)
        if (file.size > 2 * 1024 * 1024) {
          alert('File size must be less than 2MB');
          this.value = '';
          return;
        }
        
        // Validate file type
        const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
          alert('Please select a valid image file (PNG, JPG, JPEG, GIF)');
          this.value = '';
          return;
        }
        
        // Show preview
        const reader = new FileReader();
        reader.onload = function(e) {
          signaturePreviewImg.src = e.target.result;
          signaturePreview.style.display = 'block';
        };
        reader.readAsDataURL(file);
      }
    });
  }
  
  if (removeSignatureBtn) {
    removeSignatureBtn.addEventListener('click', function() {
      signatureFile.value = '';
      currentSignaturePath.value = '';
      signaturePreview.style.display = 'none';
    });
  }
  
  // ============================================================================
  // Cash Advance Policy - Dynamic List Builder
  // ============================================================================
  
  // Function to convert list items to HTML <ul><li> format
  function convertListToHTML(listContainer) {
    const items = [];
    const inputs = listContainer.querySelectorAll('.list-item-input');
    inputs.forEach(input => {
      const value = input.value.trim();
      if (value) {
        items.push(value);
      }
    });
    
    if (items.length === 0) {
      return '';
    }
    
    // Escape HTML entities properly
    const escapeHtml = (text) => {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    };
    
    const html = '<ul>\n' + items.map(item => '    <li>' + escapeHtml(item) + '</li>').join('\n') + '\n  </ul>';
    return html;
  }
  
  // Function to update hidden field and toggle remove buttons
  function updateListField(listContainer, hiddenFieldId) {
    const hiddenField = document.getElementById(hiddenFieldId);
    if (hiddenField) {
      hiddenField.value = convertListToHTML(listContainer);
    }
    
    // Show/hide remove buttons based on item count
    const items = listContainer.querySelectorAll('.list-item-group');
    items.forEach(item => {
      const removeBtn = item.querySelector('.remove-item-btn');
      if (removeBtn) {
        removeBtn.style.display = items.length > 1 ? '' : 'none';
      }
    });
  }
  
  // Add item button handler
  document.querySelectorAll('.add-item-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const targetId = this.getAttribute('data-target');
      const listContainer = document.getElementById(targetId);
      
      if (!listContainer) return;
      
      // Determine placeholder based on target
      let placeholder = 'Enter item';
      if (targetId === 'policyRulesList') placeholder = 'Enter a policy rule';
      else if (targetId === 'eligibilityCriteriaList') placeholder = 'Enter eligibility criterion';
      else if (targetId === 'termsConditionsList') placeholder = 'Enter a term or condition';
      
      // Create new item
      const newItem = document.createElement('div');
      newItem.className = 'list-item-group mb-2';
      newItem.innerHTML = `
        <div class="input-group">
          <input type="text" class="form-control list-item-input" placeholder="${placeholder}">
          <button type="button" class="btn btn-outline-danger remove-item-btn">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      `;
      
      listContainer.appendChild(newItem);
      
      // Focus on new input
      const newInput = newItem.querySelector('.list-item-input');
      if (newInput) {
        newInput.focus();
      }
      
      // Update hidden field
      let hiddenFieldId = '';
      if (targetId === 'policyRulesList') hiddenFieldId = 'policyRulesHidden';
      else if (targetId === 'eligibilityCriteriaList') hiddenFieldId = 'eligibilityCriteriaHidden';
      else if (targetId === 'termsConditionsList') hiddenFieldId = 'termsConditionsHidden';
      
      updateListField(listContainer, hiddenFieldId);
      
      // Add remove button handler
      const removeBtn = newItem.querySelector('.remove-item-btn');
      if (removeBtn) {
        removeBtn.addEventListener('click', function() {
          newItem.remove();
          updateListField(listContainer, hiddenFieldId);
        });
      }
    });
  });
  
  // Remove item button handlers
  document.querySelectorAll('.remove-item-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const listItemGroup = this.closest('.list-item-group');
      if (listItemGroup) {
        const listContainer = listItemGroup.parentElement;
        listItemGroup.remove();
        
        // Determine hidden field ID
        let hiddenFieldId = '';
        if (listContainer.id === 'policyRulesList') hiddenFieldId = 'policyRulesHidden';
        else if (listContainer.id === 'eligibilityCriteriaList') hiddenFieldId = 'eligibilityCriteriaHidden';
        else if (listContainer.id === 'termsConditionsList') hiddenFieldId = 'termsConditionsHidden';
        
        updateListField(listContainer, hiddenFieldId);
      }
    });
  });
  
  // Input change handlers to update hidden fields
  document.querySelectorAll('.list-item-input').forEach(input => {
    input.addEventListener('input', function() {
      const listContainer = this.closest('[id$="List"]');
      if (listContainer) {
        let hiddenFieldId = '';
        if (listContainer.id === 'policyRulesList') hiddenFieldId = 'policyRulesHidden';
        else if (listContainer.id === 'eligibilityCriteriaList') hiddenFieldId = 'eligibilityCriteriaHidden';
        else if (listContainer.id === 'termsConditionsList') hiddenFieldId = 'termsConditionsHidden';
        
        updateListField(listContainer, hiddenFieldId);
      }
    });
  });
  
  // Initialize hidden fields on page load
  ['policyRulesList', 'eligibilityCriteriaList', 'termsConditionsList'].forEach(listId => {
    const listContainer = document.getElementById(listId);
    if (listContainer) {
      let hiddenFieldId = '';
      if (listId === 'policyRulesList') hiddenFieldId = 'policyRulesHidden';
      else if (listId === 'eligibilityCriteriaList') hiddenFieldId = 'eligibilityCriteriaHidden';
      else if (listId === 'termsConditionsList') hiddenFieldId = 'termsConditionsHidden';
      
      updateListField(listContainer, hiddenFieldId);
    }
  });
  
  // Update hidden fields before form submission
  const cashAdvanceForm = document.querySelector('form[action*="settings"]');
  if (cashAdvanceForm && document.getElementById('policyRulesList')) {
    cashAdvanceForm.addEventListener('submit', function() {
      updateListField(document.getElementById('policyRulesList'), 'policyRulesHidden');
      updateListField(document.getElementById('eligibilityCriteriaList'), 'eligibilityCriteriaHidden');
      updateListField(document.getElementById('termsConditionsList'), 'termsConditionsHidden');
    });
  }
});
</script>

<?php if ($tab === 'history'): ?>
<script>
// ============================================================================
// Audit History Tab JavaScript
// ============================================================================
console.log('History tab JavaScript starting...');
(function() {
  let currentPage = 1;
  let nextCursorId = 0;
  let cursorByPage = { 1: 0 };
  const perPage = 50;
  let currentFilters = {};
  
  // Load filter dropdowns
  async function loadFilterOptions() {
    try {
      const response = await fetch('settings_ajax_audit_log.php?action=filter_options');
      const data = await response.json();
      
      if (data.success) {
        const userSelect = document.getElementById('filter_user');
        if (userSelect && data.users) {
          data.users.forEach(user => {
            const opt = document.createElement('option');
            opt.value = user.user_id || '';
            opt.textContent = user.fullname || user.username || 'Unknown';
            userSelect.appendChild(opt);
          });
        }
        const companySelect = document.getElementById('filter_company');
        if (companySelect && data.companies) {
          data.companies.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            companySelect.appendChild(opt);
          });
        }
        const moduleSelect = document.getElementById('filter_module');
        if (moduleSelect && data.modules) {
          data.modules.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.key;
            opt.textContent = m.label;
            moduleSelect.appendChild(opt);
          });
        }
        const actionSelect = document.getElementById('filter_action');
        if (actionSelect && data.actions) {
          data.actions.forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.value;
            opt.textContent = a.label;
            actionSelect.appendChild(opt);
          });
        }
        const hint = document.getElementById('auditSchemaHint');
        if (hint && data.schema_ready === false) {
          hint.style.display = 'block';
          hint.innerHTML = '<strong>Note:</strong> Run <code>php tools/apply_audit_log_enrichment.php</code> (Owner/Admin) so company, module, and source filters work fully.';
        }
        const cap = document.getElementById('exportCapHint');
        if (cap) cap.textContent = 'CSV export is capped at 10,000 rows for the current filters.';
      } else {
        alert('Failed to load filter options: ' + data.error);
      }
    } catch (error) {
      alert('Error loading filter options: ' + error.message);
    }
  }
  
  // Load audit data (prefers keyset cursor when available for large volumes)
  async function loadAuditData(page = 1) {
    const loadingState = document.getElementById('loadingState');
    const errorState = document.getElementById('errorState');
    const tbody = document.getElementById('historyTableBody');
    const paginationContainer = document.getElementById('paginationContainer');
    
    loadingState.style.display = 'block';
    errorState.style.display = 'none';
    tbody.innerHTML = '';
    currentPage = page;
    
    try {
      const cursorId = cursorByPage[page] || 0;
      const params = new URLSearchParams({
        ...currentFilters,
        page: page,
        per_page: perPage
      });
      if (cursorId > 0) {
        params.set('cursor_id', String(cursorId));
      }
      
      const response = await fetch('settings_ajax_audit_log.php?' + params.toString());
      const data = await response.json();
      
      loadingState.style.display = 'none';
      
      if (!data.success) {
        errorState.textContent = data.error || 'Failed to load audit history';
        errorState.style.display = 'block';
        return;
      }

      nextCursorId = data.next_cursor_id || 0;
      if (nextCursorId > 0) {
        cursorByPage[page + 1] = nextCursorId;
      }
      
      document.getElementById('recordCount').textContent = 
        `Showing ${data.records.length} of ${data.total} records`;
      if (data.stats) {
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = String(v ?? '—'); };
        set('kpiTotal', data.stats.total);
        set('kpiSuccess', data.stats.success);
        set('kpiFail', data.stats.failure);
        set('kpiAuto', data.stats.automated);
      }
      
      if (data.records.length === 0) {
        tbody.innerHTML = `
          <tr>
            <td colspan="9" class="text-center text-muted py-4">
              <i class="bi bi-inbox" style="font-size: 2rem;"></i>
              <p class="mb-0 mt-2">No activity records found</p>
            </td>
          </tr>
        `;
      } else {
        data.records.forEach(record => {
          tbody.appendChild(createTableRow(record));
        });
      }
      
      if (data.total > perPage) {
        buildPagination(data.total, page);
        paginationContainer.style.display = 'block';
      } else {
        paginationContainer.style.display = 'none';
      }
      
    } catch (error) {
      loadingState.style.display = 'none';
      errorState.textContent = 'Error: ' + error.message;
      errorState.style.display = 'block';
    }
  }
  
  // Create table row
  function createTableRow(record) {
    const tr = document.createElement('tr');
    const actionLabel = record.action_label || record.action;
    const ref = record.object_ref || (record.object_id ? ('#' + record.object_id) : '—');
    const company = record.company_name || (record.company_id ? ('#' + record.company_id) : '—');
    const module = record.module_label || record.module || '—';
    const sourceBadge = record.source && record.source !== 'user'
      ? `<br><span class="badge text-bg-light border">${escapeHtml(record.source_label || record.source)}</span>`
      : '';

    tr.innerHTML = `
      <td><small>${formatDateTime(record.created_at)}</small></td>
      <td>${record.user_name ? escapeHtml(record.user_name) : '<em class="text-muted">Not recorded</em>'}${record.user_role ? `<br><small class="text-muted">${escapeHtml(record.user_role)}</small>` : ''}${sourceBadge}</td>
      <td><span class="admin-pill admin-pill-gold">${escapeHtml(actionLabel)}</span></td>
      <td><small>${escapeHtml(String(company))}</small></td>
      <td><small>${escapeHtml(String(module))}</small></td>
      <td><small>${escapeHtml(String(ref))}</small></td>
      <td>${escapeHtml(record.summary || '')}</td>
      <td class="text-center">${record.success == 1 ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>'}</td>
      <td class="text-center"></td>
    `;
    const btn = document.createElement('button');
    btn.className = 'btn btn-sm btn-outline-primary';
    btn.innerHTML = '<i class="bi bi-eye"></i>';
    btn.onclick = () => showDetails(record);
    tr.lastElementChild.appendChild(btn);
    return tr;
  }
  
  // Show details in Admin side drawer
  function showDetails(record) {
    const objectLabel = record.object_ref
      || (record.object_type + (record.object_id ? ' #' + record.object_id : ''))
      || '—';
    const fmtJson = (raw) => {
      if (!raw) return '';
      try { return JSON.stringify(typeof raw === 'string' ? JSON.parse(raw) : raw, null, 2); }
      catch (e) { return String(raw); }
    };
    const oldJ = fmtJson(record.old_data);
    const newJ = fmtJson(record.new_data);
    const titleEl = document.getElementById('admin-drawer-title');
    if (titleEl) titleEl.textContent = record.action_label || record.action || 'Activity';
    const body = document.getElementById('admin-drawer-body');
    if (body) {
      body.innerHTML = `
        <p class="admin-activity-summary mb-3">${escapeHtml(record.summary || '')}</p>
        <div class="row g-3 small">
          <div class="col-6"><div class="text-muted">Who</div><div class="fw-semibold">${escapeHtml(record.user_name || 'Not recorded')}</div></div>
          <div class="col-6"><div class="text-muted">When</div><div class="fw-semibold">${escapeHtml(formatDateTime(record.created_at))}</div></div>
          <div class="col-6"><div class="text-muted">Company</div><div class="fw-semibold">${escapeHtml(record.company_name || (record.company_id ? ('#' + record.company_id) : '—'))}</div></div>
          <div class="col-6"><div class="text-muted">Module</div><div class="fw-semibold">${escapeHtml(record.module_label || record.module || '—')}</div></div>
          <div class="col-6"><div class="text-muted">Record</div><div class="fw-semibold">${escapeHtml(objectLabel)}</div></div>
          <div class="col-6"><div class="text-muted">Source</div><div class="fw-semibold">${escapeHtml(record.source_label || record.source || 'User')}</div></div>
          <div class="col-6"><div class="text-muted">IP</div><div class="fw-semibold">${escapeHtml(record.ip_address || 'N/A')}</div></div>
          <div class="col-6"><div class="text-muted">Status</div><div>${record.success == 1 ? '<span class="admin-pill admin-pill-ok">Success</span>' : '<span class="admin-pill admin-pill-fail">Failure</span>'}</div></div>
          ${record.error_message ? `<div class="col-12"><div class="text-danger fw-semibold">Error</div><div class="text-danger">${escapeHtml(record.error_message)}</div></div>` : ''}
        </div>
        ${(oldJ || newJ) ? `<div class="row g-3 mt-3">
          ${oldJ ? `<div class="col-12"><div class="text-muted small mb-1">Before</div><pre class="bg-light border rounded p-2 small" style="max-height:220px;overflow:auto">${escapeHtml(oldJ)}</pre></div>` : ''}
          ${newJ ? `<div class="col-12"><div class="text-muted small mb-1">After</div><pre class="bg-light border rounded p-2 small" style="max-height:220px;overflow:auto">${escapeHtml(newJ)}</pre></div>` : ''}
        </div>` : ''}
      `;
    }
    if (window.AdminUiV2 && typeof window.AdminUiV2.openDrawer === 'function') {
      window.AdminUiV2.openDrawer();
    }
    if (window.AdminUiV2 && typeof window.AdminUiV2.initLucide === 'function') {
      window.AdminUiV2.initLucide();
    }
  }
  
  // Build pagination
  function buildPagination(total, currentPage) {
    const totalPages = Math.ceil(total / perPage);
    const pagination = document.getElementById('pagination');
    pagination.innerHTML = '';
    
    // Previous
    const prevLi = document.createElement('li');
    prevLi.className = 'page-item' + (currentPage === 1 ? ' disabled' : '');
    prevLi.innerHTML = `<a class="page-link" href="#">Previous</a>`;
    if (currentPage > 1) {
      prevLi.querySelector('a').onclick = (e) => { e.preventDefault(); loadAuditData(currentPage - 1); };
    }
    pagination.appendChild(prevLi);
    
    // Page numbers (show max 5)
    const maxPages = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxPages / 2));
    let endPage = Math.min(totalPages, startPage + maxPages - 1);
    
    if (endPage - startPage < maxPages - 1) {
      startPage = Math.max(1, endPage - maxPages + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
      const li = document.createElement('li');
      li.className = 'page-item' + (i === currentPage ? ' active' : '');
      li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
      li.querySelector('a').onclick = (e) => { e.preventDefault(); loadAuditData(i); };
      pagination.appendChild(li);
    }
    
    // Next
    const nextLi = document.createElement('li');
    nextLi.className = 'page-item' + (currentPage === totalPages ? ' disabled' : '');
    nextLi.innerHTML = `<a class="page-link" href="#">Next</a>`;
    if (currentPage < totalPages) {
      nextLi.querySelector('a').onclick = (e) => { e.preventDefault(); loadAuditData(currentPage + 1); };
    }
    pagination.appendChild(nextLi);
  }
  
  // Filter form submit
  const historyFilters = document.getElementById('historyFilters');
  if (historyFilters) {
    historyFilters.addEventListener('submit', function(e) {
      e.preventDefault();
      currentFilters = {
        date_from: document.getElementById('date_from').value,
        date_to: document.getElementById('date_to').value,
        company_id: document.getElementById('filter_company')?.value || '',
        module: document.getElementById('filter_module')?.value || '',
        user_id: document.getElementById('filter_user').value,
        action: document.getElementById('filter_action').value,
        success: document.getElementById('filter_success').value,
        source: document.getElementById('filter_source')?.value || '',
        search: document.getElementById('filter_search').value,
        include_views: document.getElementById('include_views')?.checked ? '1' : ''
      };
      currentPage = 1;
      cursorByPage = { 1: 0 };
      nextCursorId = 0;
      loadAuditData(1);
    });
  }
  
  // Reset filters
  const btnResetFilters = document.getElementById('btnResetFilters');
  if (btnResetFilters) {
    btnResetFilters.addEventListener('click', function() {
      document.getElementById('historyFilters').reset();
      document.getElementById('date_from').value = '<?= date('Y-m-d', strtotime('-30 days')) ?>';
      document.getElementById('date_to').value = '<?= date('Y-m-d') ?>';
      currentFilters = {};
      cursorByPage = { 1: 0 };
      nextCursorId = 0;
      loadAuditData(1);
    });
  }
  
  // Export CSV
  const btnExportCSV = document.getElementById('btnExportCSV');
  if (btnExportCSV) {
    btnExportCSV.addEventListener('click', function() {
      const params = new URLSearchParams(currentFilters);
      // Export still uses query params (needed for file download)
      window.location.href = 'settings_export_audit_log.php?' + params.toString();
    });
  }
  
  // Helper functions
  function formatDateTime(dateStr) {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    return date.toLocaleString('en-GB', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit'
    });
  }
  
  function getActionBadgeClass(action) {
    const classes = {
      'login': 'bg-success',
      'logout': 'bg-secondary',
      'insert': 'bg-primary',
      'update': 'bg-info',
      'delete': 'bg-danger',
      'upload': 'bg-warning',
      'status_change': 'bg-dark'
    };
    return classes[action] || 'bg-secondary';
  }
  
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  // Initialize
  console.log('Initializing audit history system...');
  loadFilterOptions();
  
  // Auto-load data when page loads (if on history tab)
  // Check if history tab is active via class instead of URL
  if (document.querySelector('.nav-link[data-tab="history"]')?.classList.contains('active')) {
    console.log('Auto-loading audit data on page load');
    loadAuditData(1);
  } else {
    console.log('Not on history tab, skipping auto-load');
  }
})();
</script>
<?php else: ?>
<!-- DEBUG: Not on history tab -->
<?php endif; ?>

<?php if ($tab === 'branding'): ?>
<script type="text/javascript">
// ============================================================================
// Branding Tab JavaScript
// ============================================================================
(function() {
  'use strict';
  
  try {
    // Exit early if we're not on the branding tab
    const currentTab = new URLSearchParams(window.location.search).get('tab');
    if (currentTab !== 'branding') {
      console.log('Not on branding tab, skipping branding script');
      return;
    }
    
    console.log('Branding tab JavaScript starting...');
    
    const form = document.getElementById('brandingForm');
    const loading = document.getElementById('brandingLoading');
    const successDiv = document.getElementById('brandingSuccess');
    const errorDiv = document.getElementById('brandingError');
    
    // Exit early if required elements don't exist
    if (!form || !loading || !successDiv || !errorDiv) {
      console.error('Required branding elements not found:', {
        form: !!form,
        loading: !!loading,
        successDiv: !!successDiv,
        errorDiv: !!errorDiv
      });
      return;
    }
  
  // Color presets
  const presets = {
    saif: {
      primary: '#1e3a8a',
      primaryLight: '#2563eb',
      primaryDark: '#172554',
      accent: '#c9a227'
    },
    maroon: {
      primary: '#7a0000',
      primaryLight: '#910c0c',
      primaryDark: '#600000',
      accent: '#ffd86a'
    },
    navy: {
      primary: '#1e3a8a',
      primaryLight: '#2563eb',
      primaryDark: '#1e40af',
      accent: '#fbbf24'
    },
    forest: {
      primary: '#065f46',
      primaryLight: '#10b981',
      primaryDark: '#064e3b',
      accent: '#fcd34d'
    },
    purple: {
      primary: '#6b21a8',
      primaryLight: '#9333ea',
      primaryDark: '#581c87',
      accent: '#fde047'
    },
    teal: {
      primary: '#0d9488',
      primaryLight: '#14b8a6',
      primaryDark: '#0f766e',
      accent: '#fcd34d'
    },
    orange: {
      primary: '#c2410c',
      primaryLight: '#ea580c',
      primaryDark: '#9a3412',
      accent: '#fef3c7'
    },
    indigo: {
      primary: '#4338ca',
      primaryLight: '#6366f1',
      primaryDark: '#3730a3',
      accent: '#fde68a'
    },
    rose: {
      primary: '#be123c',
      primaryLight: '#e11d48',
      primaryDark: '#9f1239',
      accent: '#fef08a'
    },
    slate: {
      primary: '#1e293b',
      primaryLight: '#334155',
      primaryDark: '#0f172a',
      accent: '#fbbf24'
    },
    emerald: {
      primary: '#047857',
      primaryLight: '#059669',
      primaryDark: '#065f46',
      accent: '#fef3c7'
    },
    amber: {
      primary: '#d97706',
      primaryLight: '#f59e0b',
      primaryDark: '#b45309',
      accent: '#fef3c7'
    },
    crimson: {
      primary: '#991b1b',
      primaryLight: '#dc2626',
      primaryDark: '#7f1d1d',
      accent: '#fde68a'
    },
    cyan: {
      primary: '#0e7490',
      primaryLight: '#06b6d4',
      primaryDark: '#155e75',
      accent: '#fef3c7'
    },
    fuchsia: {
      primary: '#a21caf',
      primaryLight: '#d946ef',
      primaryDark: '#86198f',
      accent: '#fef08a'
    },
    lime: {
      primary: '#65a30d',
      primaryLight: '#84cc16',
      primaryDark: '#4d7c0f',
      accent: '#fef3c7'
    }
  };
  
  // Load branding settings
  async function loadSettings() {
    try {
      loading.style.display = 'block';
      form.style.display = 'none';
      
      console.log('Loading branding settings...');
      
      let response;
      try {
        response = await fetch('settings_ajax_branding.php?action=get_settings', {
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin'
        });
        
        // Check if response URL changed (redirect happened)
        if (response.url && !response.url.includes('settings_ajax_branding.php')) {
          console.error('Request was redirected to:', response.url);
          throw new Error('Request was redirected. Please check authentication.');
        }
      } catch (fetchError) {
        console.error('Fetch error:', fetchError);
        throw new Error('Failed to connect to server: ' + fetchError.message);
      }
      
      // Get response text first - before checking status
      const responseText = await response.text();
      
      // Check response type before reading
      const contentType = response.headers.get('content-type') || '';
      console.log('Response status:', response.status, 'Content-Type:', contentType, 'URL:', response.url);
      console.log('Response text (first 200 chars):', responseText.substring(0, 200));
      
      // CRITICAL: Check if response looks like HTML (starts with <)
      // If it's HTML, it means we got an error page or redirect
      if (responseText.trim().startsWith('<')) {
        console.error('Received HTML instead of JSON! Full response:', responseText.substring(0, 1000));
        showError('Server returned an error page. Please check the console for details.');
        loading.style.display = 'none';
        return;
      }
      
      // Check if response is OK
      if (!response.ok) {
        console.error('HTTP error response:', response.status, responseText.substring(0, 500));
        // Try to parse as JSON even if status is not OK
        if (responseText.trim().startsWith('{')) {
          try {
            const errorData = JSON.parse(responseText);
            throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
          } catch (e) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
        }
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      
      // Parse JSON
      let data;
      try {
        data = JSON.parse(responseText);
      } catch (parseError) {
        console.error('Failed to parse JSON response. Received:', responseText.substring(0, 500));
        console.error('Parse error:', parseError);
        showError('Server returned invalid response. Check console for details.');
        loading.style.display = 'none';
        return;
      }
      
      console.log('Branding settings loaded:', data);
      
      if (data.success) {
        // Populate form
        document.getElementById('systemName').value = data.settings.system_name;
        document.getElementById('systemNameShort').value = data.settings.system_name_short;
        
        setColor('primary', data.settings.primary_color);
        setColor('primaryLight', data.settings.primary_light);
        setColor('primaryDark', data.settings.primary_dark);
        setColor('accent', data.settings.accent_color);
        
        // Load logo if exists
        if (data.settings.logo_path) {
          document.getElementById('currentLogoPath').value = data.settings.logo_path;
          document.getElementById('logoPreviewImg').src = data.settings.logo_path;
          document.getElementById('logoPreviewContainer').style.display = 'block';
          document.getElementById('logoPlaceholder').style.display = 'none';
        } else {
          document.getElementById('logoPreviewContainer').style.display = 'none';
          document.getElementById('logoPlaceholder').style.display = 'block';
        }
        
        // Load dark mode state
        const darkModeToggle = document.getElementById('darkModeToggle');
        const darkModeLabel = document.getElementById('darkModeLabel');
        if (data.settings.dark_mode_enabled == 1 || data.settings.dark_mode_enabled === true) {
          darkModeToggle.checked = true;
          darkModeLabel.textContent = 'Enabled';
          darkModeLabel.classList.add('text-success');
        } else {
          darkModeToggle.checked = false;
          darkModeLabel.textContent = 'Disabled';
          darkModeLabel.classList.remove('text-success');
        }
        
        loading.style.display = 'none';
        form.style.display = 'block';
      } else {
        throw new Error(data.error || 'Failed to load settings');
      }
    } catch (error) {
      console.error('Error loading branding settings:', error);
      loading.style.display = 'none';
      showError('Failed to load branding settings: ' + error.message);
    }
  }
  
  // Explicit element-id map (the markup uses inconsistent ids, so we cannot
  // derive them from the type name reliably).
  const colorFieldMap = {
    primary:      { input: 'primaryColor',  text: 'primaryColorText',  preview: 'primaryPreview' },
    primaryLight: { input: 'primaryLight',  text: 'primaryLightText',  preview: 'primaryLightPreview' },
    primaryDark:  { input: 'primaryDark',   text: 'primaryDarkText',   preview: 'primaryDarkPreview' },
    accent:       { input: 'accentColor',   text: 'accentColorText',   preview: 'accentPreview' }
  };

  // Set color value and preview
  function setColor(type, color) {
    const ids = colorFieldMap[type];
    if (!ids || !color) return;

    const colorInput = document.getElementById(ids.input);
    const colorText  = document.getElementById(ids.text);
    const preview    = document.getElementById(ids.preview);

    if (colorInput) colorInput.value = color;
    if (colorText)  colorText.value  = color;
    if (preview)    preview.style.backgroundColor = color;
  }
  
  // Sync color inputs
  function syncColorInputs() {
    Object.keys(colorFieldMap).forEach(type => {
      const ids = colorFieldMap[type];
      const colorInput = document.getElementById(ids.input);
      const colorText  = document.getElementById(ids.text);
      const preview    = document.getElementById(ids.preview);
      
      if (colorInput && colorText) {
        // Color picker changes text
        colorInput.addEventListener('input', function() {
          colorText.value = this.value;
          if (preview) preview.style.backgroundColor = this.value;
        });
        
        // Text changes color picker
        colorText.addEventListener('input', function() {
          if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
            colorInput.value = this.value;
            if (preview) preview.style.backgroundColor = this.value;
          }
        });
      }
    });
  }
  
  // Apply preset
  window.applyPreset = function(presetName) {
    const preset = presets[presetName];
    if (preset) {
      setColor('primary', preset.primary);
      setColor('primaryLight', preset.primaryLight);
      setColor('primaryDark', preset.primaryDark);
      setColor('accent', preset.accent);
      
      showSuccess('Preset "' + presetName + '" applied! Click "Save Branding" to apply changes.');
    }
  };
  
  // Reset to defaults
  const btnResetDefaults = document.getElementById('btnResetDefaults');
  if (btnResetDefaults) {
    btnResetDefaults.addEventListener('click', async function() {
      if (!confirm('Are you sure you want to reset all branding settings to defaults?')) return;
      
      try {
        const response = await fetch('settings_ajax_branding.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin',
          body: 'action=reset_defaults'
        });
        
        // Get response text first
        const responseText = await response.text();
        
        // Parse JSON safely
        let data;
        try {
          data = JSON.parse(responseText);
        } catch (parseError) {
          console.error('Failed to parse JSON response. Received:', responseText.substring(0, 500));
          throw new Error('Server returned non-JSON response. Check console for details.');
        }
        
        if (data.success) {
          showSuccess(data.message);
          loadSettings();
        } else {
          showError(data.error);
        }
      } catch (error) {
        console.error('Error resetting defaults:', error);
        showError('Error resetting defaults: ' + error.message);
      }
    });
  }
  
  // Preview changes
  const btnPreviewChanges = document.getElementById('btnPreviewChanges');
  if (btnPreviewChanges) {
    btnPreviewChanges.addEventListener('click', function() {
    const systemName = document.getElementById('systemName').value;
    const systemNameShort = document.getElementById('systemNameShort').value;
    const primaryColor = document.getElementById('primaryColor').value;
    const primaryLight = document.getElementById('primaryLight').value;
    
    alert('Preview Mode:\n\n' +
          'System Name: ' + systemName + '\n' +
          'Short Name: ' + systemNameShort + '\n' +
          'Primary Color: ' + primaryColor + '\n' +
          'Primary Light: ' + primaryLight + '\n\n' +
          'Click "Save Branding" to apply these changes across the entire system.');
    });
  }
  
  // Form submission
  if (form) {
  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    try {
      hideMessages();
      
      // Use FormData to handle file uploads
      const formData = new FormData(this);
      formData.append('action', 'update_settings');
      
      // Add logo file if selected
      const logoFile = document.getElementById('logoFile');
      if (logoFile && logoFile.files.length > 0) {
        formData.set('logo_file', logoFile.files[0]);
      }
      
      const response = await fetch('settings_ajax_branding.php', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
          // Don't set Content-Type header - let browser set it with boundary for multipart
        },
        credentials: 'same-origin',
        body: formData
      });
      
      // Get response text first
      const responseText = await response.text();
      
      // Check if response is HTML (error page)
      if (responseText.trim().startsWith('<')) {
        console.error('Received HTML instead of JSON:', responseText.substring(0, 500));
        showError('Server returned an error page. Please check the console or try again.');
        return;
      }
      
      // Parse JSON safely
      let data;
      try {
        data = JSON.parse(responseText);
      } catch (parseError) {
        console.error('Failed to parse JSON response. Received:', responseText.substring(0, 500));
        console.error('Parse error:', parseError);
        showError('Server returned an invalid response. Please check the console or try again.');
        return;
      }
      
      if (data.success) {
        showSuccess(data.message + ' Please refresh the page to see changes throughout the system.');
        
        // Reload after 2 seconds
        setTimeout(() => {
          window.location.reload();
        }, 2000);
      } else {
        // Show the specific error message from the server
        const errorMsg = data.error || 'Unknown error occurred';
        console.error('Server error:', errorMsg);
        showError(errorMsg);
      }
    } catch (error) {
      console.error('Error saving branding:', error);
      showError('Error saving branding: ' + error.message);
    }
  });
  }
  
  // Show success message
  function showSuccess(message) {
    hideMessages();
    document.getElementById('brandingSuccessMessage').textContent = message;
    successDiv.style.display = 'block';
    successDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  
  // Show error message
  function showError(message) {
    hideMessages();
    document.getElementById('brandingErrorMessage').textContent = message;
    errorDiv.style.display = 'block';
    errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  
  // Hide messages
  function hideMessages() {
    successDiv.style.display = 'none';
    errorDiv.style.display = 'none';
  }
  
  // Logo file handling
  const logoFile = document.getElementById('logoFile');
  const logoPreviewImg = document.getElementById('logoPreviewImg');
  const logoPreviewContainer = document.getElementById('logoPreviewContainer');
  const logoPlaceholder = document.getElementById('logoPlaceholder');
  const removeLogoBtn = document.getElementById('removeLogoBtn');
  const currentLogoPath = document.getElementById('currentLogoPath');
  
  if (logoFile) {
    logoFile.addEventListener('change', function(e) {
      const file = e.target.files[0];
      if (file) {
        // Validate file size (2MB max)
        if (file.size > 2 * 1024 * 1024) {
          showError('Logo file size must be less than 2MB');
          this.value = '';
          return;
        }
        
        // Validate file type
        const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
          showError('Please select a valid image file (PNG, JPG, SVG, or WebP)');
          this.value = '';
          return;
        }
        
        // Show preview
        const reader = new FileReader();
        reader.onload = function(e) {
          logoPreviewImg.src = e.target.result;
          logoPreviewContainer.style.display = 'block';
          logoPlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
      }
    });
  }
  
  if (removeLogoBtn) {
    removeLogoBtn.addEventListener('click', function() {
      if (confirm('Are you sure you want to remove the logo?')) {
        logoFile.value = '';
        currentLogoPath.value = '';
        logoPreviewContainer.style.display = 'none';
        logoPlaceholder.style.display = 'block';
        showSuccess('Logo will be removed when you save branding.');
      }
    });
  }
  
  // Dark mode toggle label updater
  const darkModeToggle = document.getElementById('darkModeToggle');
  const darkModeLabel = document.getElementById('darkModeLabel');
  
  if (darkModeToggle && darkModeLabel) {
    darkModeToggle.addEventListener('change', function() {
      if (this.checked) {
        darkModeLabel.textContent = 'Enabled';
        darkModeLabel.classList.add('text-success');
      } else {
        darkModeLabel.textContent = 'Disabled';
        darkModeLabel.classList.remove('text-success');
      }
    });
  }
  
    // Initialize
    try {
      console.log('Initializing branding tab...');
      syncColorInputs();
      loadSettings();
    } catch (initError) {
      console.error('Error initializing branding tab:', initError);
      if (loading) loading.style.display = 'none';
      if (errorDiv) {
        const errorMsg = document.getElementById('brandingErrorMessage');
        if (errorMsg) {
          errorMsg.textContent = 'Failed to initialize: ' + initError.message;
        }
        errorDiv.style.display = 'block';
      }
    }
  } catch (fatalError) {
    console.error('Fatal error in branding script:', fatalError);
  }
})();
</script>
<?php endif; ?>

<!-- Global tab navigation handler - always included -->
<script>
// Tab navigation - use .php URLs
(function() {
  function handleTabClick(e) {
    const link = e.target.closest('a[data-tab]');
    if (!link) return;
    
    const tab = link.getAttribute('data-tab');
    const href = link.getAttribute('href') || '<?= get_base_path() ?>/settings.php';
    
    console.log('[Settings] Tab clicked:', tab, 'href:', href);
    
    // Allow normal navigation with .php extension
    // No need to prevent default since href already has the correct URL
  }
  
  // Use event delegation on document for reliability
  document.addEventListener('click', handleTabClick, true); // Capture phase
  
  console.log('[Settings] Tab navigation handler attached');
})();
</script>

</body>
</html>