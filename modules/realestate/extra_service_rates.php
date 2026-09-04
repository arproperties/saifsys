<?php
/**
 * Real Estate — Extra service monthly rates (management control)
 * Set or change the monthly amount for each extra service type (parking, storage, cleaning, pest control).
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

$serviceTypes = [
    'extra_parking' => 'Extra parking',
    'storage' => 'Storage',
    'other' => 'Other',
];

// Ensure table exists
try {
    $conn->query("SELECT 1 FROM re_extra_service_rates LIMIT 1");
} catch (Throwable $e) {
    $pageTitle = 'Extra service rates';
    require_once __DIR__ . '/includes/re_layout_header.php';
    echo '<div class="alert alert-warning">Run migration <code>tenant_extra_services_enhance.sql</code> to enable extra service rates.</div>';
    require_once __DIR__ . '/includes/re_layout_footer.php';
    exit;
}

$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($serviceTypes as $key => $label) {
        $val = isset($_POST['rate_' . $key]) ? trim($_POST['rate_' . $key]) : '';
        $amount = $val !== '' ? (float) preg_replace('/[^0-9.]/', '', $val) : 0;
        $upsert = $conn->prepare("
            INSERT INTO re_extra_service_rates (company_id, service_type, monthly_amount_aed, is_active)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE monthly_amount_aed = VALUES(monthly_amount_aed), updated_at = NOW()
        ");
        $upsert->execute([$currentCompanyId, $key, $amount]);
    }
    $message = 'Rates saved successfully.';
    $messageType = 'success';
}

$rates = [];
$stmt = $conn->prepare("SELECT service_type, monthly_amount_aed FROM re_extra_service_rates WHERE company_id = ?");
$stmt->execute([$currentCompanyId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rates[$row['service_type']] = (float) $row['monthly_amount_aed'];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Extra service rates';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Extra service monthly rates</div>
    <a href="maintenance.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Maintenance</a>
</div>
<p class="text-muted">Set the monthly amount (AED) for each extra service. Tenants will see these rates when requesting services with a period (e.g. parking, storage, cleaning, pest control).</p>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= h($message) ?></div>
<?php endif; ?>

<div class="card card-round">
    <div class="card-body">
        <form method="POST">
            <?php csrf_field(); ?>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Service type</th>
                        <th style="width:180px">Monthly amount (AED)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($serviceTypes as $key => $label): ?>
                        <tr>
                            <td><?= h($label) ?></td>
                            <td>
                                <input type="number" name="rate_<?= h($key) ?>" class="form-control" step="0.01" min="0"
                                       value="<?= isset($rates[$key]) && $rates[$key] > 0 ? h($rates[$key]) : '' ?>"
                                       placeholder="0">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary">Save rates</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
