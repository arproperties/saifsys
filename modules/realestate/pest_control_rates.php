<?php
/**
 * Real Estate — Pest control prices by unit type (studio, 1 BHK, 2 BHK, 3 BHK). Management control.
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

$unitTypes = ['studio' => 'Studio', '1_bhk' => '1 BHK', '2_bhk' => '2 BHK', '3_bhk' => '3 BHK'];

try {
    $conn->query("SELECT 1 FROM re_pest_control_rates LIMIT 1");
} catch (Throwable $e) {
    $pageTitle = 'Pest control rates';
    require_once __DIR__ . '/includes/re_layout_header.php';
    echo '<div class="alert alert-warning">Run migration <code>tenant_cleaning_pest_control.sql</code> first.</div>';
    require_once __DIR__ . '/includes/re_layout_footer.php';
    exit;
}

$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($unitTypes as $key => $label) {
        $val = (float) preg_replace('/[^0-9.]/', '', $_POST['price_' . $key] ?? '0');
        $conn->prepare("
            INSERT INTO re_pest_control_rates (company_id, unit_type, price_aed)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE price_aed = VALUES(price_aed)
        ")->execute([$currentCompanyId, $key, $val]);
    }
    $message = 'Pest control rates saved.';
    $messageType = 'success';
}

$rates = [];
$r = $conn->prepare("SELECT unit_type, price_aed FROM re_pest_control_rates WHERE company_id = ?");
$r->execute([$currentCompanyId]);
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $rates[$row['unit_type']] = (float) $row['price_aed'];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Pest control rates';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Pest control prices by unit type</div>
    <a href="pest_control_requests.php" class="btn btn-outline-primary me-2">Pest control requests</a>
    <a href="extra_service_requests.php" class="btn btn-secondary">Tenant extra services</a>
</div>
<p class="text-muted">Set the price (AED) for each unit type. Tenants see the price for their lease unit type when requesting pest control.</p>
<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= h($message) ?></div><?php endif; ?>
<div class="card card-round">
    <div class="card-body">
        <form method="POST">
            <?php csrf_field(); ?>
            <table class="table table-hover">
                <thead><tr><th>Unit type</th><th style="width:180px">Price (AED)</th></tr></thead>
                <tbody>
                    <?php foreach ($unitTypes as $key => $label): ?>
                        <tr>
                            <td><?= h($label) ?></td>
                            <td><input type="number" name="price_<?= h($key) ?>" class="form-control" step="0.01" min="0" value="<?= isset($rates[$key]) ? h($rates[$key]) : '' ?>" placeholder="0"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
