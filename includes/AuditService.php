<?php
/**
 * AuditService - Centralized ERP audit logging
 *
 * Prefer AuditService::logEvent() for new call sites (company, module, source,
 * owner-facing action_label, object_ref, sparse diffs).
 *
 * Legacy helpers (logCreate / logUpdate / logDelete / …) remain supported.
 */

class AuditService
{
    /** @var array<string,bool>|null */
    private static $enrichedColumns = null;

    /** Owner-facing labels for common technical actions */
    private static $actionLabels = [
        'login' => 'Signed in',
        'logout' => 'Signed out',
        'insert' => 'Created',
        'create' => 'Created',
        'update' => 'Updated',
        'delete' => 'Deleted',
        'soft_delete' => 'Archived',
        'hard_delete' => 'Permanently deleted',
        'restore' => 'Restored',
        'upload' => 'Uploaded document',
        'status_change' => 'Changed status',
        'email_sent' => 'Sent email',
        'password_change' => 'Changed password',
        'password_reset_requested' => 'Requested password reset',
        'password_reset_completed' => 'Completed password reset',
        'post' => 'Posted to accounts',
        'reverse' => 'Reversed accounting entry',
        'allocate' => 'Allocated payment',
        'unallocate' => 'Unallocated payment',
        'approve' => 'Approved',
        'reject' => 'Rejected',
        'cancel' => 'Cancelled',
        'export' => 'Exported',
        'view' => 'Viewed',
        'payment_amount_changed' => 'Changed payment amount',
        'payroll_posted' => 'Posted payroll',
        'payroll_draft_saved' => 'Saved payroll draft',
        'payroll_validation_override' => 'Payroll validation override',
        'payroll_created' => 'Created payroll run',
        'payroll_deleted' => 'Deleted payroll run',
        'leave_approved' => 'Approved leave',
        'leave_rejected' => 'Rejected leave',
        'leave_requested' => 'Submitted leave request',
        'leave_cancelled' => 'Cancelled leave',
        'attendance_recorded' => 'Recorded attendance',
        'attendance_updated' => 'Updated attendance',
        'attendance_deleted' => 'Deleted attendance',
        'attendance_status_changed' => 'Changed attendance status',
        'attendance_bulk_applied' => 'Bulk applied attendance',
        'overtime_created' => 'Created overtime',
        'overtime_approved' => 'Approved overtime',
        'overtime_rejected' => 'Rejected overtime',
        'loan_issued' => 'Issued loan / advance',
        'loan_settled' => 'Settled loan / advance',
        'loan_voided' => 'Voided loan / advance',
        'loan_deleted' => 'Deleted loan / advance',
        'inventory_movement' => 'Inventory movement',
        'booking_created' => 'Created booking',
        'booking_cancelled' => 'Cancelled booking',
        'journal_posted' => 'Posted journal',
        'journal_reversed' => 'Reversed journal',
        'receipt_created' => 'Recorded receipt',
        'invoice_issued' => 'Issued invoice',
        'invoice_voided' => 'Voided invoice',
        'lease_created' => 'Created lease',
        'lease_updated' => 'Updated lease',
        'lease_terminated' => 'Terminated lease',
        'unit_created' => 'Added unit',
        'unit_updated' => 'Updated unit',
        'renewal_initiated' => 'Started renewal workflow',
        'renewal_updated' => 'Updated renewal workflow',
        'renewal_converted' => 'Converted renewal',
        'cheque_status_changed' => 'Changed cheque status',
        // SAIF AI Platform (Phase 1B)
        'ai_conversation_started' => 'Started AI conversation',
        'ai_message_submitted' => 'Submitted AI message',
        'ai_tool_authorized' => 'Authorized AI tool',
        'ai_tool_denied' => 'Denied AI tool',
        'ai_tool_executed' => 'Executed AI tool',
        'ai_provider_call' => 'AI provider call',
        'ai_provider_error' => 'AI provider error',
        'ai_rate_limited' => 'AI rate limit triggered',
        'ai_budget_blocked' => 'AI budget/quota blocked',
        'ai_settings_updated' => 'Updated AI settings',
        'ai_conversation_archived' => 'Archived AI conversation',
        'ai_security_refusal' => 'AI security refusal',
        'ai_emergency_shutdown' => 'AI emergency shutdown',
        'document_uploaded' => 'Uploaded document',
        'document_updated' => 'Updated document',
        'document_deleted' => 'Deleted document',
        'employee_created' => 'Created employee',
        'employee_updated' => 'Updated employee',
        'deduction_created' => 'Created deduction / fine',
        'deduction_deleted' => 'Deleted deduction / fine',
        'loan_requested' => 'Requested loan / advance',
        'loan_rejected' => 'Rejected loan / advance',
        'contact_updated' => 'Updated contact info',
        'leave_balance_adjusted' => 'Adjusted leave balance',
        'leave_balances_created' => 'Created leave balances',
        'emergency_contact_added' => 'Added emergency contact',
        'emergency_contact_deleted' => 'Deleted emergency contact',
        'schedule_updated' => 'Updated work schedule',
        'note_added' => 'Added HR note',
        'note_deleted' => 'Deleted HR note',
        'history_added' => 'Added employment history',
        'history_deleted' => 'Deleted employment history',
        'deduction_settled' => 'Settled deduction / fine',
        'employee_login_created' => 'Created employee login',
        'hr_roles_updated' => 'Updated HR user roles',
        'loan_payroll_synced' => 'Synced loan payroll recoveries',
        'holiday_created' => 'Created holiday',
        'holiday_updated' => 'Updated holiday',
        'holiday_deleted' => 'Deleted holiday',
        'leave_type_created' => 'Created leave type',
        'leave_type_updated' => 'Updated leave type',
        'leave_type_deleted' => 'Deleted leave type',
        'department_created' => 'Created department',
        'department_updated' => 'Updated department',
        'department_deleted' => 'Deleted department',
        'location_created' => 'Created location',
        'location_updated' => 'Updated location',
        'location_deleted' => 'Deleted location',
        'document_type_created' => 'Created document type',
        'asset_issued' => 'Issued asset',
        'asset_returned' => 'Returned asset',
        'asset_lost' => 'Marked asset lost',
        'asset_deleted' => 'Deleted asset',
        'training_added' => 'Added training',
        'training_status_updated' => 'Updated training status',
        'training_deleted' => 'Deleted training',
        'avatar_uploaded' => 'Uploaded employee photo',
        'avatar_deleted' => 'Deleted employee photo',
        'leave_balance_recalculated' => 'Recalculated leave balance',
        'payment_recorded' => 'Recorded payment',
        'delete_expense' => 'Deleted Quick Paid Expense',
        'hard_delete_expense' => 'Hard-deleted Quick Paid Expense',
        'delete_vendor' => 'Deleted vendor',
        'delete_supplier' => 'Deleted supplier',
        'supplier_deleted' => 'Deleted supplier',
    ];

    private static $moduleLabels = [
        'cleaning' => 'Cleaning',
        'realestate' => 'Real Estate',
        'construction' => 'Construction',
        'hr' => 'HR',
        'inventory' => 'Inventory',
        'legal' => 'Legal',
        'ars' => 'ARS',
        'admin' => 'Administration',
        'auth' => 'Sign-in',
        'accounts' => 'Accounts',
        'core' => 'Core',
    ];

    /**
     * Preferred entry point for new audit events.
     *
     * @param array $params Same as log(), plus company_id, module, source, object_ref, action_label
     */
    public static function logEvent(array $params): bool
    {
        if (empty($params['action_label']) && !empty($params['action'])) {
            $params['action_label'] = self::actionLabel((string)$params['action']);
        }
        if (empty($params['source'])) {
            $params['source'] = 'user';
        }
        if (!isset($params['company_id']) && function_exists('current_company_id')) {
            try {
                global $conn;
                if ($conn instanceof PDO) {
                    $cid = current_company_id($conn);
                    if ($cid) {
                        $params['company_id'] = (int)$cid;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        // Prefer sparse diffs when both old/new are arrays
        if (is_array($params['old_data'] ?? null) && is_array($params['new_data'] ?? null)) {
            $diff = self::sparseDiff($params['old_data'], $params['new_data']);
            if ($diff['old'] !== null || $diff['new'] !== null) {
                $params['old_data'] = $diff['old'];
                $params['new_data'] = $diff['new'];
            }
        }
        return self::log($params);
    }

    public static function actionLabel(string $action): string
    {
        return self::$actionLabels[$action] ?? ucwords(str_replace('_', ' ', $action));
    }

    public static function moduleLabel(?string $module): string
    {
        if ($module === null || $module === '') {
            return '';
        }
        return self::$moduleLabels[$module] ?? ucfirst($module);
    }

    /**
     * Best-effort module when callers omit it (legacy AuditService::log sites).
     */
    public static function inferModule(string $action, string $objectType): ?string
    {
        $objectType = strtolower(trim($objectType));
        $action = strtolower(trim($action));

        if ($objectType === 'auth' || in_array($action, ['login', 'logout', 'login_redirect', 'login_redirect_failed', 'password_change'], true)) {
            return 'auth';
        }

        $map = [
            'make_order' => 'cleaning',
            'expenses' => 'accounts',
            'invoices' => 'accounts',
            'receipts' => 'accounts',
            'company_settings' => 'admin',
            'app_email_settings' => 'admin',
            'settings' => 'admin',
            'roles' => 'admin',
            'user' => 'admin',
            'user_roles' => 'admin',
            'payroll_runs' => 'hr',
            'leave_requests' => 'hr',
            'cash_advances' => 'hr',
            'employees' => 'hr',
            'employee_profile' => 'hr',
            'attendance' => 'hr',
            'attendance_bulk' => 'hr',
            'overtime_entries' => 'hr',
            'employee_documents' => 'hr',
            'employee_deductions' => 'hr',
            'leave_balances' => 'hr',
            'emergency_contacts' => 'hr',
            'employee_work_schedules' => 'hr',
            'employee_notes' => 'hr',
            'employment_history' => 'hr',
            'holidays' => 'hr',
            'leave_types' => 'hr',
            'departments' => 'hr',
            'locations' => 'hr',
            'document_types' => 'hr',
            'employee_assets' => 'hr',
            'employee_training' => 'hr',
            'user_roles' => 'hr',
            'inv_doc_headers' => 'inventory',
            'ars_bookings' => 'ars',
            'ars_company_settings' => 'ars',
            're_maintenance_requests' => 'realestate',
            're_leases' => 'realestate',
            'co_shop_rental_contracts' => 'construction',
            'bank_reconciliation' => 'construction',
        ];
        if (isset($map[$objectType])) {
            return $map[$objectType];
        }
        if (str_starts_with($objectType, 're_') || str_starts_with($objectType, 'legal_')) {
            return str_starts_with($objectType, 'legal_') ? 'legal' : 'realestate';
        }
        if (str_starts_with($objectType, 'co_')) {
            return 'construction';
        }
        if (str_starts_with($objectType, 'ars_')) {
            return 'ars';
        }
        if (str_starts_with($objectType, 'inv_')) {
            return 'inventory';
        }
        if (str_starts_with($objectType, 'gl_') || $objectType === 'chart_of_accounts') {
            return 'accounts';
        }
        return null;
    }

    /**
     * @return array{old: ?array, new: ?array}
     */
    public static function sparseDiff(array $old, array $new, int $maxFields = 40): array
    {
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $oldOut = [];
        $newOut = [];
        $n = 0;
        foreach ($keys as $k) {
            if (in_array($k, ['password', 'password_hash', 'token', 'secret', 'api_key', 'stripe_secret'], true)) {
                continue;
            }
            $ov = $old[$k] ?? null;
            $nv = $new[$k] ?? null;
            if ($ov === $nv) {
                continue;
            }
            // Skip huge nested blobs
            if (is_array($ov) || is_array($nv) || is_object($ov) || is_object($nv)) {
                continue;
            }
            $oldOut[$k] = $ov;
            $newOut[$k] = $nv;
            if (++$n >= $maxFields) {
                break;
            }
        }
        return [
            'old' => $oldOut ?: null,
            'new' => $newOut ?: null,
        ];
    }

    public static function log(array $params): bool
    {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }

            global $conn;
            if (!$conn || !($conn instanceof PDO)) {
                require_once __DIR__ . '/db_connect.php';
                if (!$conn || !($conn instanceof PDO)) {
                    error_log('AuditService: Database connection not available');
                    return false;
                }
            }

            $action = $params['action'] ?? '';
            $object_type = $params['object_type'] ?? '';
            $object_id = $params['object_id'] ?? null;
            $summary = $params['summary'] ?? '';
            $old_data = $params['old_data'] ?? null;
            $new_data = $params['new_data'] ?? null;
            $success = $params['success'] ?? true;
            $error_message = $params['error_message'] ?? null;
            $company_id = array_key_exists('company_id', $params) && $params['company_id'] !== null && $params['company_id'] !== ''
                ? (int)$params['company_id']
                : null;
            if ($company_id !== null && $company_id <= 0) {
                $company_id = null;
            }
            // Auto company when caller used legacy AuditService::log() (not only logEvent)
            if ($company_id === null && function_exists('current_company_id')) {
                try {
                    $cid = current_company_id($conn);
                    if ($cid) {
                        $company_id = (int)$cid;
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }
            $module = isset($params['module']) && $params['module'] !== ''
                ? substr((string)$params['module'], 0, 40)
                : null;
            // Infer module for common object_types when not provided (fixes empty Module column)
            if ($module === null || $module === '') {
                $module = self::inferModule((string)$action, (string)$object_type);
            }
            $source = isset($params['source']) ? substr((string)$params['source'], 0, 20) : 'user';
            if (!in_array($source, ['user', 'api', 'system', 'job'], true)) {
                $source = 'user';
            }
            $object_ref = isset($params['object_ref']) ? substr((string)$params['object_ref'], 0, 120) : null;
            $action_label = isset($params['action_label'])
                ? substr((string)$params['action_label'], 0, 120)
                : self::actionLabel((string)$action);

            if (empty($action) || empty($object_type) || empty($summary)) {
                error_log('AuditService: Missing required parameters (action, object_type, or summary)');
                return false;
            }

            $user_id = $params['user_id'] ?? null;
            $user_name = $params['user_name'] ?? null;
            $user_role = $params['user_role'] ?? null;

            if (($user_id === null || (int)$user_id <= 0) && function_exists('current_user_id')) {
                $uid = current_user_id();
                if ($uid) {
                    $user_id = (int)$uid;
                }
            }
            if ($user_id === null || (int)$user_id <= 0) {
                if (isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] > 0) {
                    $user_id = (int)$_SESSION['user']['id'];
                } elseif (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
                    $user_id = (int)$_SESSION['user_id'];
                } else {
                    $user_id = null;
                }
            } else {
                $user_id = (int)$user_id;
            }

            if ($user_name === null && isset($_SESSION['user']['username'])) {
                $user_name = $_SESSION['user']['username'];
            } elseif ($user_name === null && isset($_SESSION['user']['fullname'])) {
                $user_name = $_SESSION['user']['fullname'];
            } elseif ($user_name === null && isset($_SESSION['username'])) {
                $user_name = $_SESSION['username'];
            } elseif ($user_name === null && isset($_SESSION['fullname'])) {
                $user_name = $_SESSION['fullname'];
            }

            if (($user_name === null || $user_name === '') && $user_id !== null) {
                try {
                    $userStmt = $conn->prepare("SELECT username, fullname, email FROM `user` WHERE id = ? LIMIT 1");
                    $userStmt->execute([(int)$user_id]);
                    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $user_name = $userRow['fullname']
                        ?? $userRow['username']
                        ?? $userRow['email']
                        ?? null;
                } catch (Throwable $lookupError) {
                    error_log('AuditService user lookup warning: ' . $lookupError->getMessage());
                }
            }

            if ($user_role === null && isset($_SESSION['role_names'])) {
                $user_role = is_array($_SESSION['role_names'])
                    ? implode(', ', $_SESSION['role_names'])
                    : $_SESSION['role_names'];
            } elseif ($user_role === null && isset($_SESSION['roles'])) {
                $user_role = is_array($_SESSION['roles'])
                    ? implode(', ', $_SESSION['roles'])
                    : $_SESSION['roles'];
            }

            // Login-time / no session company: fall back to user's default company
            if ($company_id === null && $user_id !== null) {
                try {
                    $dc = $conn->prepare('SELECT default_company_id FROM `user` WHERE id = ? LIMIT 1');
                    $dc->execute([(int)$user_id]);
                    $def = (int)($dc->fetchColumn() ?: 0);
                    if ($def > 0) {
                        $company_id = $def;
                    } else {
                        $pc = $conn->prepare('SELECT company_id FROM user_companies WHERE user_id = ? AND is_primary = 1 LIMIT 1');
                        $pc->execute([(int)$user_id]);
                        $prim = (int)($pc->fetchColumn() ?: 0);
                        if ($prim > 0) {
                            $company_id = $prim;
                        }
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }

            $ip_address = self::getClientIp();
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            if ($user_agent && strlen($user_agent) > 500) {
                $user_agent = substr($user_agent, 0, 500);
            }

            $old_data_json = null;
            $new_data_json = null;
            if ($old_data !== null) {
                $old_data_json = is_string($old_data) ? $old_data : json_encode($old_data, JSON_UNESCAPED_UNICODE);
            }
            if ($new_data !== null) {
                $new_data_json = is_string($new_data) ? $new_data : json_encode($new_data, JSON_UNESCAPED_UNICODE);
            }

            $cols = self::detectEnrichedColumns($conn);
            if ($cols['company_id']) {
                $sql = "
                    INSERT INTO audit_log
                    (user_id, user_name, user_role, company_id, module, source, action, action_label,
                     object_type, object_id, object_ref, summary, old_data, new_data,
                     ip_address, user_agent, success, error_message)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ";
                $stmt = $conn->prepare($sql);
                return $stmt->execute([
                    $user_id,
                    $user_name,
                    $user_role,
                    $company_id,
                    $module,
                    $source,
                    $action,
                    $action_label,
                    $object_type,
                    $object_id !== null ? (string)$object_id : null,
                    $object_ref,
                    $summary,
                    $old_data_json,
                    $new_data_json,
                    $ip_address,
                    $user_agent,
                    $success ? 1 : 0,
                    $error_message
                ]);
            }

            // Legacy schema fallback
            $sql = "
                INSERT INTO audit_log
                (user_id, user_name, user_role, action, object_type, object_id,
                 summary, old_data, new_data, ip_address, user_agent, success, error_message)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ";
            $stmt = $conn->prepare($sql);
            return $stmt->execute([
                $user_id,
                $user_name,
                $user_role,
                $action,
                $object_type,
                $object_id !== null ? (string)$object_id : null,
                $summary,
                $old_data_json,
                $new_data_json,
                $ip_address,
                $user_agent,
                $success ? 1 : 0,
                $error_message
            ]);
        } catch (Throwable $e) {
            error_log('AuditService Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @return array{company_id:bool,module:bool,source:bool,object_ref:bool,action_label:bool}
     */
    public static function detectEnrichedColumns(PDO $conn): array
    {
        if (self::$enrichedColumns !== null) {
            return self::$enrichedColumns;
        }
        $defaults = [
            'company_id' => false,
            'module' => false,
            'source' => false,
            'object_ref' => false,
            'action_label' => false,
        ];
        try {
            $rows = $conn->query("SHOW COLUMNS FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
            $set = array_flip($rows ?: []);
            foreach ($defaults as $col => $_) {
                $defaults[$col] = isset($set[$col]);
            }
        } catch (Throwable $e) {
            // keep defaults false
        }
        self::$enrichedColumns = $defaults;
        return self::$enrichedColumns;
    }

    public static function resetSchemaCache(): void
    {
        self::$enrichedColumns = null;
    }

    public static function logCreate(string $table, $id, $data, string $summary = '', ?int $user_id = null, array $extra = []): bool
    {
        if (empty($summary)) {
            $summary = "Created {$table} record #{$id}";
        }
        return self::logEvent(array_merge([
            'action' => 'insert',
            'object_type' => $table,
            'object_id' => (string)$id,
            'summary' => $summary,
            'new_data' => $data,
            'user_id' => $user_id,
            'success' => true,
        ], $extra));
    }

    public static function logUpdate(string $table, $id, $oldData, $newData, string $summary = '', ?int $user_id = null, array $extra = []): bool
    {
        if (empty($summary)) {
            $summary = "Updated {$table} record #{$id}";
        }
        return self::logEvent(array_merge([
            'action' => 'update',
            'object_type' => $table,
            'object_id' => (string)$id,
            'summary' => $summary,
            'old_data' => $oldData,
            'new_data' => $newData,
            'user_id' => $user_id,
            'success' => true,
        ], $extra));
    }

    public static function logDelete(string $table, $id, $oldData, string $summary = '', ?int $user_id = null, array $extra = []): bool
    {
        if (empty($summary)) {
            $summary = "Deleted {$table} record #{$id}";
        }
        return self::logEvent(array_merge([
            'action' => 'delete',
            'object_type' => $table,
            'object_id' => (string)$id,
            'summary' => $summary,
            'old_data' => $oldData,
            'user_id' => $user_id,
            'success' => true,
        ], $extra));
    }

    public static function logUpload(string $filename, string $path, string $relatedTable = '', $relatedId = null, string $summary = '', array $extra = []): bool
    {
        if (empty($summary)) {
            $summary = "Uploaded file: {$filename}";
            if ($relatedTable && $relatedId) {
                $summary .= " for {$relatedTable} #{$relatedId}";
            }
        }
        return self::logEvent(array_merge([
            'action' => 'upload',
            'object_type' => 'file',
            'object_id' => $relatedId ? (string)$relatedId : null,
            'summary' => $summary,
            'new_data' => [
                'filename' => $filename,
                'path' => $path,
                'related_table' => $relatedTable,
                'related_id' => $relatedId
            ],
            'success' => true,
        ], $extra));
    }

    public static function logStatusChange(string $table, $id, string $oldStatus, string $newStatus, string $summary = '', ?int $user_id = null, array $extra = []): bool
    {
        if (empty($summary)) {
            $summary = "Changed {$table} #{$id} status from '{$oldStatus}' to '{$newStatus}'";
        }
        return self::logEvent(array_merge([
            'action' => 'status_change',
            'object_type' => $table,
            'object_id' => (string)$id,
            'summary' => $summary,
            'old_data' => ['status' => $oldStatus],
            'new_data' => ['status' => $newStatus],
            'user_id' => $user_id,
            'success' => true,
        ], $extra));
    }

    public static function logFailure(string $action, string $object_type, $object_id, string $summary, string $error_message, array $extra = []): bool
    {
        return self::logEvent(array_merge([
            'action' => $action,
            'object_type' => $object_type,
            'object_id' => $object_id,
            'summary' => $summary,
            'success' => false,
            'error_message' => $error_message,
        ], $extra));
    }

    private static function getClientIp(): ?string
    {
        $ip = null;
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
