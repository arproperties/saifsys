<?php
/**
 * Real Estate Accounting - Period Management
 * Manage accounting periods and fiscal years
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_fiscal_year') {
        $yearName = trim($_POST['year_name'] ?? '');
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($yearName) || empty($startDate) || empty($endDate)) {
            $error = "All fields are required";
        } elseif (strtotime($startDate) >= strtotime($endDate)) {
            $error = "End date must be after start date";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO re_fiscal_years (company_id, year_name, start_date, end_date, is_active)
                VALUES (?, ?, ?, ?, ?)
            ");
            try {
                $stmt->execute([$currentCompanyId, $yearName, $startDate, $endDate, $isActive]);
                $success = "Fiscal year added successfully";
            } catch (PDOException $e) {
                $error = "Error adding fiscal year: " . $e->getMessage();
            }
        }
    } elseif ($action === 'lock_period') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        $isLocked = isset($_POST['is_locked']) ? 1 : 0;
        
        // Note: Period locking table structure would need to be defined
        // This is a placeholder implementation
        $success = "Period locking feature will be implemented";
    }
}

// Get fiscal years
$fiscalYears = $conn->prepare("
    SELECT * FROM re_fiscal_years
    WHERE company_id = ?
    ORDER BY start_date DESC
");
$fiscalYears->execute([$currentCompanyId]);
$allFiscalYears = $fiscalYears->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Period Management';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= h($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-calendar-range"></i> Period Management
    </div>
</div>

<!-- Add Fiscal Year -->
<div class="card card-round mb-4">
    <div class="card-header bg-light">
        <h6 class="mb-0"><i class="bi bi-plus-circle"></i> Add Fiscal Year</h6>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_fiscal_year">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Year Name *</label>
                    <input type="text" name="year_name" class="form-control" 
                           placeholder="e.g., FY 2024" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Start Date *</label>
                    <input type="date" name="start_date" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date *</label>
                    <input type="date" name="end_date" class="form-control" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" checked>
                        <label class="form-check-label" for="isActive">
                            Active
                        </label>
                    </div>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Fiscal Years List -->
<div class="card card-round">
    <div class="card-header bg-light">
        <h6 class="mb-0"><i class="bi bi-list-ul"></i> Fiscal Years</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th><i class="bi bi-tag"></i> Year Name</th>
                        <th><i class="bi bi-calendar"></i> Start Date</th>
                        <th><i class="bi bi-calendar"></i> End Date</th>
                        <th><i class="bi bi-check-circle"></i> Status</th>
                        <th><i class="bi bi-info-circle"></i> Duration</th>
                        <th><i class="bi bi-gear"></i> Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allFiscalYears)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                No fiscal years defined
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($allFiscalYears as $year): ?>
                            <?php
                            $start = strtotime($year['start_date']);
                            $end = strtotime($year['end_date']);
                            $days = round(($end - $start) / 86400);
                            ?>
                            <tr>
                                <td><strong><?= h($year['year_name']) ?></strong></td>
                                <td><?= date('F d, Y', $start) ?></td>
                                <td><?= date('F d, Y', $end) ?></td>
                                <td>
                                    <?php if ($year['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $days ?> days</td>
                                <td>
                                    <a href="period_close.php?fiscal_year_id=<?= $year['id'] ?>" class="btn btn-sm btn-outline-warning">
                                        <i class="bi bi-lock"></i> Close Period
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
