<?php
/**
 * AJAX endpoint for live search - Leases
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';

header('Content-Type: application/json');

require_login();
$currentCompanyId = current_company_id($conn) ?: 1;

// Get filter parameters (treat "all" as no filter)
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
if ($statusFilter === 'all') $statusFilter = '';
$buildingFilter = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$searchQuery = !empty($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE clause
$where = ["l.company_id = ?", re_lease_not_deleted_sql($conn, 'l')];
$params = [$currentCompanyId];

if ($statusFilter !== '') {
    $where[] = "l.status = ?";
    $params[] = $statusFilter;
}

if ($buildingFilter) {
    $where[] = "u.building_id = ?";
    $params[] = $buildingFilter;
}

if ($searchQuery !== '') {
    $searchParam = '%' . $searchQuery . '%';
    $where[] = "(l.lease_number LIKE ? OR CAST(u.unit_number AS CHAR) LIKE ? OR b.name LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR t.company_name LIKE ? OR CONCAT(COALESCE(t.first_name,''), ' ', COALESCE(t.last_name,'')) LIKE ?)";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql = "
    SELECT l.*,
           u.unit_number, u.unit_type, u.building_id,
           b.name as building_name,
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           (SELECT COUNT(*) FROM re_lease_installments li WHERE li.lease_id = l.id AND li.status = 'paid') as paid_installments,
           (SELECT COUNT(*) FROM re_lease_installments li WHERE li.lease_id = l.id AND li.status = 'partial') as partial_installments,
           (SELECT COUNT(*) FROM re_lease_installments li WHERE li.lease_id = l.id AND li.status IN ('pending', 'overdue')) as pending_installments
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY l.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate table HTML
ob_start();
if (empty($leases)):
?>
    <tr>
        <td colspan="9" class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            No leases found
        </td>
    </tr>
<?php
else:
    $currentBuilding = '';
    foreach ($leases as $lease):
        // Group by building if no building filter is applied
        if (!$buildingFilter && $currentBuilding !== $lease['building_name']):
            $currentBuilding = $lease['building_name'];
?>
    <tr class="table-secondary">
        <td colspan="9" class="fw-bold">
            <i class="bi bi-building"></i> <?= htmlspecialchars($currentBuilding, ENT_QUOTES, 'UTF-8') ?>
        </td>
    </tr>
<?php
        endif;
        
        $tenantNameRaw = trim((string)($lease['company_name'] ?? ''));
        if ($tenantNameRaw === '') {
            $tenantNameRaw = trim((string)($lease['first_name'] ?? '') . ' ' . (string)($lease['last_name'] ?? ''));
        }
        if ($tenantNameRaw === '') {
            $tenantNameRaw = !empty($lease['tenant_id']) ? 'Tenant #' . (int)$lease['tenant_id'] : '-';
        }
        $tenantName = htmlspecialchars($tenantNameRaw, ENT_QUOTES, 'UTF-8');
        
        $statusClass = [
            'active' => 'success',
            'expired' => 'danger',
            'draft' => 'secondary',
            'terminated' => 'warning'
        ];
        $statusBadgeClass = $statusClass[$lease['status']] ?? 'secondary';
        
        // Payment status
        $totalInstallments = $lease['paid_installments'] + $lease['partial_installments'] + $lease['pending_installments'];
        $paymentStatus = 'pending';
        $paymentBadgeClass = 'warning';
        if ($lease['paid_installments'] == $totalInstallments && $totalInstallments > 0) {
            $paymentStatus = 'paid';
            $paymentBadgeClass = 'success';
        } elseif ($lease['partial_installments'] > 0) {
            $paymentStatus = 'partial';
            $paymentBadgeClass = 'info';
        }
        
        $annualRent = $lease['annual_rent'] ?? ($lease['monthly_rent'] * 12 ?? 0);
?>
    <tr>
        <td><strong class="text-primary"><?= htmlspecialchars($lease['lease_number'] ?: 'L-' . $lease['id'], ENT_QUOTES, 'UTF-8') ?></strong></td>
        <td>
            <i class="bi bi-building"></i> <?= htmlspecialchars($lease['building_name'], ENT_QUOTES, 'UTF-8') ?><br>
            <small class="text-muted"><?= htmlspecialchars($lease['unit_number'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($lease['unit_type'] ?? '-', ENT_QUOTES, 'UTF-8') ?>)</small>
        </td>
        <td>
            <i class="bi bi-person-circle"></i> <?= $tenantName ?>
        </td>
        <td><?= date('Y-m-d', strtotime($lease['start_date'])) ?></td>
        <td><?= date('Y-m-d', strtotime($lease['end_date'])) ?></td>
        <td>
            <?php if ($annualRent > 0): ?>
                <strong><?= number_format($annualRent, 2) ?> AED</strong>
            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
        </td>
        <td>
            <span class="badge bg-<?= $statusBadgeClass ?>"><?= ucfirst(htmlspecialchars($lease['status'], ENT_QUOTES, 'UTF-8')) ?></span>
        </td>
        <td>
            <span class="badge bg-<?= $paymentBadgeClass ?>"><?= ucfirst($paymentStatus) ?></span>
        </td>
        <td>
            <div class="btn-group btn-group-sm" role="group">
                <a href="lease_view.php?id=<?= $lease['id'] ?>" class="btn btn-outline-primary" title="View Lease">
                    <i class="bi bi-eye"></i>
                </a>
                <?php if ($lease['status'] === 'draft'): ?>
                    <a href="lease_add.php?id=<?= $lease['id'] ?>" class="btn btn-outline-info" title="Edit Lease">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <button type="button" class="btn btn-outline-danger" onclick="deleteLease(<?= $lease['id'] ?>, '<?= htmlspecialchars(addslashes($lease['lease_number'] ?: 'L-' . $lease['id']), ENT_QUOTES, 'UTF-8') ?>', this)" title="Archive Draft Lease">
                        <i class="bi bi-trash"></i>
                    </button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
<?php
    endforeach;
endif;
$tableHtml = ob_get_clean();

echo json_encode([
    'success' => true,
    'count' => count($leases),
    'html' => $tableHtml
]);
