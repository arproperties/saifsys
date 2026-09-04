<?php
/**
 * Real Estate Accounting Settings.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/accounting_mode_helper.php';
require_once __DIR__ . '/../includes/lease_payment_schedule_helper.php';

require_login();
if (!(has_role('Owner', $conn) || has_role('Admin', $conn) || has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn))) {
    require_module_access($conn, MODULE_REALESTATE);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$success = '';
$error = '';

$allowedModes = ['invoice', 'legacy'];
$allowedOverridePolicies = ['no', 'admin_only', 'accountant_only', 'super_admin_only'];
$allowedScheduleValidationModes = ['strict', 'authorized_override', 'warning_only'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!re_accounting_current_user_can_override_mode($conn, 'accountant_only')) {
        $error = 'Only authorized accounting administrators can change Real Estate accounting settings.';
    } else {
        $invoiceModeEnabled = !empty($_POST['invoice_mode_enabled']) ? '1' : '0';
        $defaultNew = in_array($_POST['default_new'] ?? '', $allowedModes, true) ? $_POST['default_new'] : 'invoice';
        $defaultRenewal = in_array($_POST['default_renewal'] ?? '', $allowedModes, true) ? $_POST['default_renewal'] : 'invoice';
        $allowLegacyNew = !empty($_POST['allow_legacy_new']) ? '1' : '0';
        $overridePolicy = in_array($_POST['override_policy'] ?? '', $allowedOverridePolicies, true) ? $_POST['override_policy'] : 'admin_only';
        $activationDate = trim((string)($_POST['activation_date'] ?? date('Y-m-d')));
        $showBadge = !empty($_POST['show_badge']) ? '1' : '0';
        $scheduleValidationMode = in_array($_POST['schedule_validation_mode'] ?? '', $allowedScheduleValidationModes, true)
            ? $_POST['schedule_validation_mode']
            : 'authorized_override';

        if ($activationDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $activationDate)) {
            $error = 'Invoice Mode activation date must be a valid YYYY-MM-DD date.';
        } else {
            re_accounting_save_setting($conn, 're_accounting_invoice_mode_enabled', $invoiceModeEnabled);
            re_accounting_save_setting($conn, 're_default_accounting_mode_new_leases', $defaultNew);
            re_accounting_save_setting($conn, 're_default_accounting_mode_renewals', $defaultRenewal);
            re_accounting_save_setting($conn, 're_allow_legacy_mode_new_leases', $allowLegacyNew);
            re_accounting_save_setting($conn, 're_allow_accounting_mode_override', $overridePolicy);
            re_accounting_save_setting($conn, 're_invoice_mode_activation_date', $activationDate);
            re_accounting_save_setting($conn, 're_show_accounting_mode_badge', $showBadge);
            re_accounting_save_setting($conn, 're_payment_schedule_validation_mode', $scheduleValidationMode);
            $success = 'Accounting settings saved.';
        }
    }
}

$settings = re_accounting_mode_settings($conn);
$scheduleValidationMode = re_payment_schedule_validation_mode($conn);
$featureEnabled = !empty($settings['feature_enabled']);

$pageTitle = 'Accounting Settings';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-sliders"></i> Real Estate Accounting Settings
    </div>
    <span class="badge bg-<?= $featureEnabled ? 'success' : 'warning text-dark' ?>">
        Invoice Mode Feature: <?= $featureEnabled ? 'Enabled' : 'Disabled' ?>
    </span>
</div>

<?php if (!$featureEnabled): ?>
    <div class="alert alert-warning">
        <strong>Invoice Mode feature flag is disabled.</strong>
        These settings are saved for deployment readiness, but new leases will remain Legacy Mode and no Invoice Mode automation will run until
        <code>re_accounting_invoice_mode_enabled</code> is set to <code>1</code>.
    </div>
<?php endif; ?>

<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="card card-round">
    <div class="card-body">
        <form method="post" class="row g-4">
            <?php csrf_field(); ?>
            <div class="col-md-12">
                <div class="alert <?= $featureEnabled ? 'alert-success' : 'alert-warning' ?> mb-0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="invoiceModeEnabled" name="invoice_mode_enabled" value="1" <?= $featureEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="invoiceModeEnabled">Enable Invoice Mode automation</label>
                    </div>
                    <div class="small mt-1">
                        When enabled, new leases and renewals on or after the activation date can use obligations, invoice candidates, invoices, receipts, and allocations.
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Default accounting mode for new leases</label>
                <select name="default_new" class="form-select">
                    <option value="invoice" <?= $settings['default_new'] === 'invoice' ? 'selected' : '' ?>>Invoice Mode</option>
                    <option value="legacy" <?= $settings['default_new'] === 'legacy' ? 'selected' : '' ?>>Legacy Mode</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Default accounting mode for renewals</label>
                <select name="default_renewal" class="form-select">
                    <option value="invoice" <?= $settings['default_renewal'] === 'invoice' ? 'selected' : '' ?>>Invoice Mode</option>
                    <option value="legacy" <?= $settings['default_renewal'] === 'legacy' ? 'selected' : '' ?>>Legacy Mode</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Invoice Mode activation date</label>
                <input type="date" name="activation_date" class="form-control" value="<?= h($settings['activation_date']) ?>">
                <div class="form-text">New leases and renewals on or after this date default to Invoice Mode when the feature flag is enabled.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Manual accounting mode override</label>
                <select name="override_policy" class="form-select">
                    <?php foreach ([
                        'no' => 'No manual override',
                        'admin_only' => 'Admin / Owner only',
                        'accountant_only' => 'Accountant / Real Estate Financial / Admin',
                        'super_admin_only' => 'Super Admin / Owner only',
                    ] as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $settings['override_policy'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Payment schedule total validation</label>
                <select name="schedule_validation_mode" class="form-select">
                    <option value="strict" <?= $scheduleValidationMode === 'strict' ? 'selected' : '' ?>>Strict match required</option>
                    <option value="authorized_override" <?= $scheduleValidationMode === 'authorized_override' ? 'selected' : '' ?>>Warning with authorized override</option>
                    <option value="warning_only" <?= $scheduleValidationMode === 'warning_only' ? 'selected' : '' ?>>Warning only</option>
                </select>
                <div class="form-text">Controls whether lease payment schedules must match expected core lease collection totals.</div>
            </div>
            <div class="col-md-6">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="allowLegacyNew" name="allow_legacy_new" value="1" <?= $settings['allow_legacy_new'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="allowLegacyNew">Allow Legacy Mode for new leases</label>
                </div>
                <div class="form-text">Keep this off unless management approves a specific exception.</div>
            </div>
            <div class="col-md-6">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="showBadge" name="show_badge" value="1" <?= $settings['show_badge'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="showBadge">Show accounting mode badge on lease pages</label>
                </div>
            </div>
            <div class="col-12 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2-circle"></i> Save Settings
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>

