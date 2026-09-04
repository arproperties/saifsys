<?php
/**
 * AJAX endpoint for live search - Tenants
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

header('Content-Type: application/json');

require_login();
$currentCompanyId = current_company_id($conn) ?: 1;

// Get filter parameters
$typeFilter = !empty($_GET['type']) ? $_GET['type'] : '';
$hasLeasesFilter = !empty($_GET['has_leases']) ? $_GET['has_leases'] : '';
$searchQuery = !empty($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE clause
$where = ["t.company_id = ?"];
$params = [$currentCompanyId];

if ($typeFilter) {
    $where[] = "t.tenant_type = ?";
    $params[] = $typeFilter;
}

if ($hasLeasesFilter === 'with') {
    $where[] = "EXISTS (SELECT 1 FROM re_leases l WHERE l.tenant_id = t.id AND l.company_id = ?)";
    $params[] = $currentCompanyId;
} elseif ($hasLeasesFilter === 'without') {
    $where[] = "NOT EXISTS (SELECT 1 FROM re_leases l WHERE l.tenant_id = t.id AND l.company_id = ?)";
    $params[] = $currentCompanyId;
}

if ($searchQuery) {
    $where[] = "(t.first_name LIKE ? OR t.last_name LIKE ? OR t.company_name LIKE ? OR t.email LIKE ? OR t.phone LIKE ? OR t.id_number LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql = "
    SELECT t.*,
           (SELECT COUNT(*) FROM re_leases l WHERE l.tenant_id = t.id AND l.company_id = t.company_id) as lease_count
    FROM re_tenants t
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate table HTML
ob_start();
if (empty($tenants)):
?>
    <tr>
        <td colspan="8" class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            No tenants found
        </td>
    </tr>
<?php
else:
    foreach ($tenants as $tenant):
        $tenantName = $tenant['tenant_type'] === 'company' 
            ? htmlspecialchars($tenant['company_name'], ENT_QUOTES, 'UTF-8')
            : htmlspecialchars(trim($tenant['first_name'] . ' ' . $tenant['last_name']), ENT_QUOTES, 'UTF-8');
?>
    <tr>
        <td>
            <strong>
                <?php if ($tenant['tenant_type'] === 'company'): ?>
                    <i class="bi bi-building"></i> <?= $tenantName ?>
                <?php else: ?>
                    <i class="bi bi-person-circle"></i> <?= $tenantName ?>
                <?php endif; ?>
            </strong>
        </td>
        <td>
            <span class="badge bg-<?= $tenant['tenant_type'] === 'company' ? 'info' : 'primary' ?>">
                <?= ucfirst(htmlspecialchars($tenant['tenant_type'], ENT_QUOTES, 'UTF-8')) ?>
            </span>
        </td>
        <td>
            <?php if ($tenant['email']): ?>
                <a href="mailto:<?= htmlspecialchars($tenant['email'], ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">
                    <i class="bi bi-envelope"></i> <?= htmlspecialchars($tenant['email'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($tenant['phone']): ?>
                <a href="tel:<?= htmlspecialchars($tenant['phone'], ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">
                    <i class="bi bi-telephone"></i> <?= htmlspecialchars($tenant['phone'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($tenant['id_type'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
        <td><code><?= htmlspecialchars($tenant['id_number'] ?: '-', ENT_QUOTES, 'UTF-8') ?></code></td>
        <td>
            <?php if ($tenant['lease_count'] > 0): ?>
                <span class="badge bg-success"><?= $tenant['lease_count'] ?></span>
            <?php else: ?>
                <span class="text-muted">No leases</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="btn-group btn-group-sm" role="group">
                <a href="tenant_view.php?id=<?= $tenant['id'] ?>" class="btn btn-outline-primary" title="View Tenant">
                    <i class="bi bi-eye"></i>
                </a>
            </div>
        </td>
    </tr>
<?php
    endforeach;
endif;
$tableHtml = ob_get_clean();

echo json_encode([
    'success' => true,
    'count' => count($tenants),
    'html' => $tableHtml
]);
