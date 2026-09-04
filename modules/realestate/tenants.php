<?php
/**
 * Real Estate Module - Tenants Management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get filter parameters
$typeFilter = !empty($_GET['tenant_type']) ? $_GET['tenant_type'] : '';
$searchQuery = !empty($_GET['search']) ? trim($_GET['search']) : '';
$hasLeasesFilter = isset($_GET['has_leases']) ? $_GET['has_leases'] : '';

// Build WHERE clause
$where = ["t.company_id = ?", "t.is_active = 1"];
$params = [$currentCompanyId];

if ($typeFilter) {
    $where[] = "t.tenant_type = ?";
    $params[] = $typeFilter;
}

if ($searchQuery) {
    $where[] = "(t.first_name LIKE ? OR t.last_name LIKE ? OR t.company_name LIKE ? OR t.email LIKE ? OR t.phone LIKE ? OR t.id_number LIKE ? OR CONCAT(t.first_name, ' ', t.last_name) LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Get tenants
$tenantsQuery = "
    SELECT t.*, 
           COUNT(DISTINCT l.id) as lease_count,
           MAX(l.end_date) as latest_lease_end
    FROM re_tenants t
    LEFT JOIN re_leases l ON l.tenant_id = t.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY t.id
";

// Apply has_leases filter after grouping
if ($hasLeasesFilter === 'yes') {
    $tenantsQuery .= " HAVING lease_count > 0";
} elseif ($hasLeasesFilter === 'no') {
    $tenantsQuery .= " HAVING lease_count = 0";
}

$tenantsQuery .= " ORDER BY t.last_name, t.first_name";

$tenants = $conn->prepare($tenantsQuery);
$tenants->execute($params);
$tenants = $tenants->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total_tenants,
        COUNT(CASE WHEN tenant_type = 'company' THEN 1 END) as company_count,
        COUNT(CASE WHEN tenant_type = 'individual' THEN 1 END) as individual_count,
        COUNT(CASE WHEN EXISTS (SELECT 1 FROM re_leases WHERE tenant_id = t.id) THEN 1 END) as tenants_with_leases
    FROM re_tenants t
    WHERE t.company_id = ? AND t.is_active = 1
");
$stats->execute([$currentCompanyId]);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Tenants';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">Tenants</div>
            <a href="tenant_add.php" class="btn btn-primary" style="background-color: var(--primary); border-color: var(--primary);">
                <i class="bi bi-plus-circle"></i> Add Tenant
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-people text-primary fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Total Tenants</div>
                                <div class="h4 mb-0"><?= number_format($statistics['total_tenants']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-building text-info fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Companies</div>
                                <div class="h4 mb-0"><?= number_format($statistics['company_count']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-person text-success fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">Individuals</div>
                                <div class="h4 mb-0"><?= number_format($statistics['individual_count']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-round border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                    <i class="bi bi-file-earmark-text text-warning fs-4"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">With Leases</div>
                                <div class="h4 mb-0"><?= number_format($statistics['tenants_with_leases']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Advanced Filters -->
        <div class="card card-round mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
            </div>
            <div class="card-body">
                <form method="GET" id="filterForm" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label"><i class="bi bi-tag"></i> Type</label>
                        <select name="tenant_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="individual" <?= $typeFilter === 'individual' ? 'selected' : '' ?>>Individual</option>
                            <option value="company" <?= $typeFilter === 'company' ? 'selected' : '' ?>>Company</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="bi bi-file-earmark-text"></i> Has Leases</label>
                        <select name="has_leases" class="form-select">
                            <option value="">All</option>
                            <option value="yes" <?= $hasLeasesFilter === 'yes' ? 'selected' : '' ?>>With Leases</option>
                            <option value="no" <?= $hasLeasesFilter === 'no' ? 'selected' : '' ?>>No Leases</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-search"></i> Search</label>
                        <input type="text" name="search" id="searchInput" class="form-control" placeholder="Name, Email, Phone, ID Number..." value="<?= h($searchQuery) ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel-fill"></i> Apply
                        </button>
                    </div>
                    <?php if ($typeFilter || $hasLeasesFilter || $searchQuery): ?>
                    <div class="col-12">
                        <a href="tenants.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Tenants Table -->
        <div class="card card-round">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-list-ul"></i> Tenants (<span id="tenantsCount"><?= count($tenants) ?></span>)</h6>
                <?php if (count($tenants) > 0): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportTable()">
                    <i class="bi bi-download"></i> Export
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tenantsTable">
                        <thead class="table-light">
                            <tr>
                                <th><i class="bi bi-person"></i> Name/Company</th>
                                <th><i class="bi bi-tag"></i> Type</th>
                                <th><i class="bi bi-envelope"></i> Email</th>
                                <th><i class="bi bi-telephone"></i> Phone</th>
                                <th><i class="bi bi-card-text"></i> ID Type</th>
                                <th><i class="bi bi-hash"></i> ID Number</th>
                                <th><i class="bi bi-file-earmark-text"></i> Leases</th>
                                <th><i class="bi bi-gear"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tenantsTableBody">
                            <?php if (empty($tenants)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                        No tenants found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tenants as $tenant): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?php 
                                            if ($tenant['tenant_type'] === 'company') {
                                                echo '<i class="bi bi-building"></i> ' . h($tenant['company_name'] ?? 'Company Tenant');
                                            } else {
                                                echo '<i class="bi bi-person-circle"></i> ' . h($tenant['first_name'] . ' ' . $tenant['last_name']);
                                            }
                                            ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $tenant['tenant_type'] === 'company' ? 'info' : 'primary' ?>">
                                            <?= ucfirst($tenant['tenant_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($tenant['email']): ?>
                                            <a href="mailto:<?= h($tenant['email']) ?>" class="text-decoration-none">
                                                <i class="bi bi-envelope"></i> <?= h($tenant['email']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($tenant['phone']): ?>
                                            <a href="tel:<?= h($tenant['phone']) ?>" class="text-decoration-none">
                                                <i class="bi bi-telephone"></i> <?= h($tenant['phone']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($tenant['id_type'] ?: '-') ?></td>
                                    <td><code><?= h($tenant['id_number'] ?: '-') ?></code></td>
                                    <td>
                                        <?php if ($tenant['lease_count'] > 0): ?>
                                            <span class="badge bg-success"><?= $tenant['lease_count'] ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="tenant_view.php?id=<?= $tenant['id'] ?>" class="btn btn-outline-primary" title="View Tenant">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($tenant['lease_count'] == 0): ?>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="deleteTenant(<?= $tenant['id'] ?>, '<?= h(($tenant['tenant_type'] === 'company' ? $tenant['company_name'] : $tenant['first_name'] . ' ' . $tenant['last_name'])) ?>')"
                                                        title="Delete Tenant">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<script>
function exportTable() {
    const table = document.getElementById('tenantsTable');
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        csv.push(row.join(','));
    }
    
    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = 'tenants_<?= date('Y-m-d') ?>.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<script>
// Live search with debouncing (AJAX - no page reload)
(function() {
    const searchInput = document.getElementById('searchInput');
    const filterForm = document.getElementById('filterForm');
    const tenantsTableBody = document.getElementById('tenantsTableBody');
    const tenantsCount = document.getElementById('tenantsCount');
    let searchTimeout;
    
    function updateTable() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams();
        for (const [key, value] of formData.entries()) {
            if (value) params.append(key, value);
        }
        
        // Show loading state
        if (tenantsTableBody) {
            tenantsTableBody.innerHTML = '<tr><td colspan="8" class="text-center py-3"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...</td></tr>';
        }
        
        fetch('ajax_search_tenants.php?' + params.toString())
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (tenantsTableBody) {
                        tenantsTableBody.innerHTML = data.html;
                    }
                    if (tenantsCount) {
                        tenantsCount.textContent = data.count;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (tenantsTableBody) {
                    tenantsTableBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-3">Error loading data. Please refresh the page.</td></tr>';
                }
            });
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(updateTable, 500); // Wait 500ms after user stops typing
        });
    }
})();

function deleteTenant(tenantId, tenantName) {
    if (!confirm('Are you sure you want to delete tenant "' + tenantName + '"?\n\nThis action cannot be undone.')) {
        return;
    }
    
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
    
    const formData = new FormData();
    formData.append('tenant_id', tenantId);
    formData.append('_csrf', '<?= csrf_token() ?>');
    
    fetch('ajax_delete_tenant.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the row from the table
            const row = btn.closest('tr');
            row.style.transition = 'opacity 0.3s';
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
                // Show success message
                const alert = document.createElement('div');
                alert.className = 'alert alert-success alert-dismissible fade show';
                alert.innerHTML = '<strong>Success!</strong> ' + data.message + 
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                document.querySelector('.page-header-label').parentElement.insertAdjacentElement('afterend', alert);
                setTimeout(() => alert.remove(), 5000);
            }, 300);
        } else {
            alert('Error: ' + (data.error || 'Failed to delete tenant'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

