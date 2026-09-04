<?php
/**
 * Real Estate — Cleaning service rates (rate per hour + materials fee). Management control.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_MAINTENANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

try {
    $conn->query("SELECT 1 FROM re_cleaning_rates LIMIT 1");
} catch (Throwable $e) {
    $pageTitle = 'Cleaning rates';
    require_once __DIR__ . '/includes/re_layout_header.php';
    echo '<div class="alert alert-warning">Run migration <code>tenant_cleaning_pest_control.sql</code> first.</div>';
    require_once __DIR__ . '/includes/re_layout_footer.php';
    exit;
}

$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $rate_per_hour = (float) preg_replace('/[^0-9.]/', '', $_POST['rate_per_hour_aed'] ?? '0');
    $materials_fee = (float) preg_replace('/[^0-9.]/', '', $_POST['materials_fee_aed'] ?? '0');
    $conn->prepare("
        INSERT INTO re_cleaning_rates (company_id, rate_per_hour_aed, materials_fee_aed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE rate_per_hour_aed = VALUES(rate_per_hour_aed), materials_fee_aed = VALUES(materials_fee_aed)
    ")->execute([$currentCompanyId, $rate_per_hour, $materials_fee]);
    $message = 'Cleaning rates saved.';
    $messageType = 'success';
}

$row = $conn->prepare("SELECT rate_per_hour_aed, materials_fee_aed FROM re_cleaning_rates WHERE company_id = ?");
$row->execute([$currentCompanyId]);
$rates = $row->fetch(PDO::FETCH_ASSOC) ?: ['rate_per_hour_aed' => 0, 'materials_fee_aed' => 0];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Cleaning rates';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Cleaning service rates</div>
    <a href="cleaning_requests.php" class="btn btn-outline-primary me-2">Cleaning requests</a>
    <a href="extra_service_requests.php" class="btn btn-secondary">Tenant extra services</a>
</div>
<p class="text-muted">Set the hourly rate per cleaner and the materials fee per hour. Total hours = cleaners × hours. Formula: (rate × total hours) + (materials fee per hour × total hours) when materials are requested. Example: 2 cleaners × 2 hours = 4 hours; at 25 AED/hr + 5 AED/hr materials = (4×25) + (4×5) = 120 AED.</p>
<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= h($message) ?></div><?php endif; ?>
<div class="card card-round">
    <div class="card-body">
        <form method="POST">
            <?php csrf_field(); ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Rate per hour (AED) per cleaner</label>
                    <input type="number" name="rate_per_hour_aed" class="form-control" step="0.01" min="0" value="<?= h($rates['rate_per_hour_aed']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Materials fee (AED) per hour</label>
                    <input type="number" name="materials_fee_aed" class="form-control" step="0.01" min="0" value="<?= h($rates['materials_fee_aed']) ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-3">Save</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
