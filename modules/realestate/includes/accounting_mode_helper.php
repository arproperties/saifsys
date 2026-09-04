<?php
/**
 * Real Estate accounting mode policy helper.
 *
 * Invoice Mode is enabled only when the global feature flag is on. Existing
 * leases keep their stored mode; new leases and renewals use settings defaults.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
require_once __DIR__ . '/obligation_preview_helper.php';

if (!function_exists('re_accounting_setting')) {
    function re_accounting_setting(PDO $conn, string $key, string $default = ''): string
    {
        try {
            $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false ? $default : (string)$value;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('re_accounting_save_setting')) {
    function re_accounting_save_setting(PDO $conn, string $key, string $value): void
    {
        $stmt = $conn->prepare("
            INSERT INTO settings (`key`, `value`)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
        ");
        $stmt->execute([$key, $value]);
    }
}

if (!function_exists('re_accounting_mode_settings')) {
    /**
     * @return array<string,string|bool>
     */
    function re_accounting_mode_settings(PDO $conn): array
    {
        return [
            'feature_enabled' => re_accounting_phase1_feature_enabled($conn),
            'default_new' => re_accounting_setting($conn, 're_default_accounting_mode_new_leases', 'invoice'),
            'default_renewal' => re_accounting_setting($conn, 're_default_accounting_mode_renewals', 'invoice'),
            'allow_legacy_new' => re_accounting_setting($conn, 're_allow_legacy_mode_new_leases', '0'),
            'override_policy' => re_accounting_setting($conn, 're_allow_accounting_mode_override', 'accountant_only'),
            'activation_date' => re_accounting_setting($conn, 're_invoice_mode_activation_date', date('Y-m-d')),
            'show_badge' => re_accounting_setting($conn, 're_show_accounting_mode_badge', '1'),
        ];
    }
}

if (!function_exists('re_accounting_normalize_mode')) {
    function re_accounting_normalize_mode(?string $mode): string
    {
        return in_array($mode, ['legacy', 'invoice'], true) ? $mode : 'legacy';
    }
}

if (!function_exists('re_accounting_current_user_can_override_mode')) {
    function re_accounting_current_user_can_override_mode(PDO $conn, ?string $policy = null): bool
    {
        $policy = $policy ?: re_accounting_setting($conn, 're_allow_accounting_mode_override', 'admin_only');
        $roles = current_user_roles($conn);

        if ($policy === 'no') {
            return false;
        }
        if ($policy === 'super_admin_only') {
            return in_array('Super Admin', $roles, true) || in_array('Owner', $roles, true);
        }
        if ($policy === 'accountant_only') {
            return in_array('Owner', $roles, true)
                || in_array('Admin', $roles, true)
                || in_array('Accountant', $roles, true)
                || in_array('Account', $roles, true)
                || has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);
        }
        return in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
    }
}

if (!function_exists('re_accounting_activation_applies')) {
    function re_accounting_activation_applies(PDO $conn, ?string $date = null): bool
    {
        $settings = re_accounting_mode_settings($conn);
        if (empty($settings['feature_enabled'])) {
            return false;
        }

        $activation = (string)$settings['activation_date'];
        if ($activation === '') {
            return true;
        }
        $date = $date ?: date('Y-m-d');
        return $date >= $activation;
    }
}

if (!function_exists('re_accounting_default_mode_for_context')) {
    function re_accounting_default_mode_for_context(PDO $conn, string $context = 'new', ?string $effectiveDate = null): string
    {
        $settings = re_accounting_mode_settings($conn);
        if (empty($settings['feature_enabled']) || !re_accounting_activation_applies($conn, $effectiveDate)) {
            return 'legacy';
        }

        $key = $context === 'renewal' ? 'default_renewal' : 'default_new';
        $mode = re_accounting_normalize_mode((string)$settings[$key]);
        if ($mode === 'legacy' && (string)$settings['allow_legacy_new'] !== '1') {
            return 'invoice';
        }
        return $mode;
    }
}

if (!function_exists('re_accounting_mode_for_existing_lease')) {
    function re_accounting_mode_for_existing_lease(PDO $conn, array $lease): string
    {
        if (!re_obligation_column_exists($conn, 're_leases', 'accounting_mode')) {
            return 'legacy';
        }
        return re_accounting_normalize_mode((string)($lease['accounting_mode'] ?? 'legacy'));
    }
}

if (!function_exists('re_accounting_lease_has_history')) {
    function re_accounting_lease_has_history(PDO $conn, int $companyId, int $leaseId): bool
    {
        $checks = [
            "SELECT 1 FROM re_payments WHERE company_id = ? AND lease_id = ? LIMIT 1",
            "SELECT 1 FROM re_invoices WHERE company_id = ? AND lease_id = ? LIMIT 1",
            "SELECT 1 FROM re_journal_headers WHERE company_id = ? AND reference_type IN ('lease','invoice','payment','deposit','recognition_schedule') AND reference_id = ? LIMIT 1",
        ];
        foreach ($checks as $sql) {
            try {
                $stmt = $conn->prepare($sql);
                $stmt->execute([$companyId, $leaseId]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return false;
    }
}

if (!function_exists('re_accounting_log_mode_change')) {
    function re_accounting_log_mode_change(PDO $conn, int $companyId, int $leaseId, ?string $oldMode, string $newMode, ?int $userId, string $reason, string $source = 'lease_edit'): void
    {
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_lease_accounting_mode_audit
                    (company_id, lease_id, old_mode, new_mode, changed_by, reason, source)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId,
                $leaseId,
                $oldMode ? re_accounting_normalize_mode($oldMode) : null,
                re_accounting_normalize_mode($newMode),
                $userId,
                $reason ?: null,
                $source,
            ]);
        } catch (Throwable $e) {
            error_log('Could not log accounting mode change: ' . $e->getMessage());
        }
    }
}

if (!function_exists('re_accounting_validate_requested_mode')) {
    /**
     * @return array{success:bool,mode:string,error:string}
     */
    function re_accounting_validate_requested_mode(PDO $conn, int $companyId, ?int $leaseId, ?string $oldMode, string $requestedMode, string $context, ?string $effectiveDate, string $reason = ''): array
    {
        $requestedMode = re_accounting_normalize_mode($requestedMode);
        $oldMode = $oldMode ? re_accounting_normalize_mode($oldMode) : null;

        if (!re_accounting_phase1_feature_enabled($conn)
            && $requestedMode === 'invoice'
            && !($context === 'edit' && $oldMode === 'invoice')) {
            return ['success' => false, 'mode' => 'legacy', 'error' => 'Invoice Mode feature flag is disabled.'];
        }

        if ($context !== 'edit' && $requestedMode === 'legacy' && re_accounting_phase1_feature_enabled($conn) && re_accounting_activation_applies($conn, $effectiveDate)) {
            $settings = re_accounting_mode_settings($conn);
            if ((string)$settings['allow_legacy_new'] !== '1') {
                return ['success' => false, 'mode' => 'invoice', 'error' => 'Legacy Mode is disabled for new business.'];
            }
        }

        $default = $context === 'edit'
            ? ($oldMode ?: 'legacy')
            : re_accounting_default_mode_for_context($conn, $context, $effectiveDate);

        if ($requestedMode !== $default && !re_accounting_current_user_can_override_mode($conn)) {
            return ['success' => false, 'mode' => $default, 'error' => 'You are not authorized to override accounting mode.'];
        }
        if ($requestedMode !== $default && trim($reason) === '') {
            return ['success' => false, 'mode' => $default, 'error' => 'Accounting mode override reason is required.'];
        }

        if ($context === 'edit' && $leaseId && $oldMode && $oldMode !== $requestedMode) {
            if (re_accounting_lease_has_history($conn, $companyId, $leaseId)) {
                return ['success' => false, 'mode' => $oldMode, 'error' => 'Accounting mode cannot be changed for a lease with historical payments, invoices, or journals.'];
            }
        }

        return ['success' => true, 'mode' => $requestedMode, 'error' => ''];
    }
}

