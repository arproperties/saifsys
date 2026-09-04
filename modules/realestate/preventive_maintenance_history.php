<?php
/**
 * Real Estate Module - Preventive Maintenance History
 * View maintenance history and completion records
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Today
$filterAsset = !empty($_GET['asset_id']) ? (int)$_GET['asset_id'] : null;
$filterSchedule = !empty($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : null;
$filterBuilding = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : null;

// Build query
$where = ["h.company_id = ?", "h.performed_date >= ?", "h.performed_date <= ?"];
$params = [$currentCompanyId, $dateFrom, $dateTo];

if ($filterAsset) {
    $where[] = "h.asset_id = ?";
    $params[] = $filterAsset;
}

if ($filterSchedule) {
    $where[] = "h.schedule_id = ?";
    $params[] = $filterSchedule;
}

if ($filterBuilding) {
    $where[] = "b.id = ?";
    $params[] = $filterBuilding;
}

// Get history records
$history = $conn->prepare("
    SELECT h.*, 
           t.task_name,
           s.schedule_name,
           a.asset_name, a.asset_type,
           b.name as building_name, u.unit_number,
           e.full_name as performed_by_name
    FROM re_preventive_maintenance_history h
    JOIN re_preventive_maintenance_tasks t ON t.id = h.task_id
    JOIN re_preventive_maintenance_schedules s ON s.id = h.schedule_id
    LEFT JOIN re_maintenance_assets a ON a.id = h.asset_id
    LEFT JOIN re_buildings b ON b.id = a.building_id
    LEFT JOIN re_units u ON u.id = a.unit_id
    LEFT JOIN employees e ON e.id = h.performed_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY h.performed_date DESC, h.created_at DESC
");
$history->execute($params);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total_completed,
        SUM(h.cost) as total_cost,
        AVG(h.duration_minutes) as avg_duration,
        COUNT(DISTINCT h.asset_id) as assets_serviced,
        COUNT(DISTINCT h.schedule_id) as schedules_used
    FROM re_preventive_maintenance_history h
    WHERE h.company_id = ? AND h.performed_date >= ? AND h.performed_date <= ?
");
$stats->execute([$currentCompanyId, $dateFrom, $dateTo]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

// Get assets for filter
$assets = $conn->prepare("
    SELECT id, asset_name, asset_type 
    FROM re_maintenance_assets 
    WHERE company_id = ? AND is_active = 1 
    ORDER BY asset_type, asset_name
");
$assets->execute([$currentCompanyId]);
$assets = $assets->fetchAll(PDO::FETCH_ASSOC);

// Get schedules for filter
$schedules = $conn->prepare("
    SELECT id, schedule_name 
    FROM re_preventive_maintenance_schedules 
    WHERE company_id = ? AND is_active = 1 
    ORDER BY schedule_name
");
$schedules->execute([$currentCompanyId]);
$schedules = $schedules->fetchAll(PDO::FETCH_ASSOC);

// Get buildings for filter
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatAssetType($type) {
    $types = [
        'ac_unit' => 'AC Unit',
        'elevator' => 'Elevator',
        'fire_system' => 'Fire System',
        'plumbing' => 'Plumbing',
        'electrical' => 'Electrical',
        'hvac' => 'HVAC',
        'generator' => 'Generator',
        'pump' => 'Pump',
        'security_system' => 'Security System',
        'other' => 'Other'
    ];
    return $types[$type] ?? ucfirst($type);
}
function formatDuration($minutes) {
    if (!$minutes) return '-';
    if ($minutes < 60) return $minutes . ' min';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours . 'h ' . $mins . 'm';
}

// Set page title and include layout
$pageTitle = 'Maintenance History';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label"><i class="bi bi-clock-history"></i> Maintenance History</div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Completed</h5>
                        <h2 class="mb-0"><?= $stats['total_completed'] ?: 0 ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Cost</h5>
                        <h2 class="mb-0"><?= number_format($stats['total_cost'] ?: 0, 2) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Avg Duration</h5>
                        <h2 class="mb-0"><?= formatDuration($stats['avg_duration']) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Assets Serviced</h5>
                        <h2 class="mb-0"><?= $stats['assets_serviced'] ?: 0 ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Schedules Used</h5>
                        <h2 class="mb-0"><?= $stats['schedules_used'] ?: 0 ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Asset</label>
                        <select name="asset_id" class="form-select">
                            <option value="">All Assets</option>
                            <?php foreach ($assets as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $filterAsset == $a['id'] ? 'selected' : '' ?>>
                                    <?= h($a['asset_name']) ?> (<?= formatAssetType($a['asset_type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Schedule</label>
                        <select name="schedule_id" class="form-select">
                            <option value="">All Schedules</option>
                            <?php foreach ($schedules as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $filterSchedule == $s['id'] ? 'selected' : '' ?>>
                                    <?= h($s['schedule_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Building</label>
                        <select name="building_id" class="form-select">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filterBuilding == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="preventive_maintenance_history.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- History Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Maintenance Records (<?= count($history) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($history)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No maintenance history found for the selected period.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Task</th>
                                    <th>Schedule</th>
                                    <th>Asset</th>
                                    <th>Location</th>
                                    <th>Performed By</th>
                                    <th>Duration</th>
                                    <th>Cost</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $record): ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($record['performed_date'])) ?></td>
                                        <td>
                                            <strong><?= h($record['task_name']) ?></strong>
                                        </td>
                                        <td><?= h($record['schedule_name']) ?></td>
                                        <td>
                                            <?php if ($record['asset_name']): ?>
                                                <?= h($record['asset_name']) ?><br>
                                                <small class="text-muted"><?= formatAssetType($record['asset_type']) ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($record['building_name']): ?>
                                                <?= h($record['building_name']) ?>
                                                <?php if ($record['unit_number']): ?>
                                                    - Unit <?= h($record['unit_number']) ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($record['performed_by_name'] ?: '-') ?></td>
                                        <td><?= formatDuration($record['duration_minutes']) ?></td>
                                        <td><?= $record['cost'] > 0 ? number_format($record['cost'], 2) . ' AED' : '-' ?></td>
                                        <td>
                                            <?php if ($record['status_after']): ?>
                                                <span class="badge bg-success"><?= h($record['status_after']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="viewHistoryDetails(<?= htmlspecialchars(json_encode($record)) ?>)">
                                                <i class="bi bi-eye"></i> Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- History Details Modal -->
    <div class="modal fade" id="historyDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Maintenance History Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="historyDetailsContent">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewHistoryDetails(record) {
            let html = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Task:</strong><br>
                        ${escapeHtml(record.task_name)}
                    </div>
                    <div class="col-md-6">
                        <strong>Schedule:</strong><br>
                        ${escapeHtml(record.schedule_name)}
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Performed Date:</strong><br>
                        ${new Date(record.performed_date).toLocaleDateString()}
                    </div>
                    <div class="col-md-6">
                        <strong>Performed By:</strong><br>
                        ${escapeHtml(record.performed_by_name || '-')}
                    </div>
                </div>
                ${record.asset_name ? `
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Asset:</strong><br>
                            ${escapeHtml(record.asset_name)} (${escapeHtml(record.asset_type || '')})
                        </div>
                        <div class="col-md-6">
                            <strong>Location:</strong><br>
                            ${escapeHtml(record.building_name || '-')}${record.unit_number ? ' - Unit ' + escapeHtml(record.unit_number) : ''}
                        </div>
                    </div>
                ` : ''}
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong>Duration:</strong><br>
                        ${record.duration_minutes ? formatDuration(record.duration_minutes) : '-'}
                    </div>
                    <div class="col-md-4">
                        <strong>Cost:</strong><br>
                        ${record.cost > 0 ? parseFloat(record.cost).toFixed(2) + ' AED' : '-'}
                    </div>
                    <div class="col-md-4">
                        <strong>Next Service Due:</strong><br>
                        ${record.next_service_due ? new Date(record.next_service_due).toLocaleDateString() : '-'}
                    </div>
                </div>
                ${record.status_before ? `
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Status Before:</strong><br>
                            ${escapeHtml(record.status_before)}
                        </div>
                        <div class="col-md-6">
                            <strong>Status After:</strong><br>
                            <span class="badge bg-success">${escapeHtml(record.status_after || '-')}</span>
                        </div>
                    </div>
                ` : ''}
                ${record.issues_found ? `
                    <div class="mb-3">
                        <strong>Issues Found:</strong><br>
                        <div class="alert alert-warning">
                            ${escapeHtml(record.issues_found)}
                        </div>
                    </div>
                ` : ''}
                ${record.parts_replaced ? `
                    <div class="mb-3">
                        <strong>Parts Replaced:</strong><br>
                        <div class="alert alert-info">
                            ${escapeHtml(record.parts_replaced)}
                        </div>
                    </div>
                ` : ''}
                ${record.notes ? `
                    <div class="mb-3">
                        <strong>Notes:</strong><br>
                        ${escapeHtml(record.notes)}
                    </div>
                ` : ''}
            `;
            document.getElementById('historyDetailsContent').innerHTML = html;
            const modal = new bootstrap.Modal(document.getElementById('historyDetailsModal'));
            modal.show();
        }

        function escapeHtml(text) {
            if (!text) return '-';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDuration(minutes) {
            if (!minutes) return '-';
            if (minutes < 60) return minutes + ' min';
            const hours = Math.floor(minutes / 60);
            const mins = minutes % 60;
            return hours + 'h ' + mins + 'm';
        }
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

