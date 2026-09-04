<?php
/**
 * Real Estate Module - Preventive Maintenance Templates Management
 * Reusable templates for quick schedule creation
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
$userId = current_user_id();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
            $templateName = trim($_POST['template_name'] ?? '');
            $assetType = $_POST['asset_type'] ?? '';
            $taskDescription = trim($_POST['task_description'] ?? '');
            $estimatedDuration = !empty($_POST['estimated_duration_minutes']) ? (int)$_POST['estimated_duration_minutes'] : null;
            $estimatedCost = !empty($_POST['estimated_cost']) ? (float)$_POST['estimated_cost'] : 0;
            $requiredParts = trim($_POST['required_parts'] ?? '');
            $instructions = trim($_POST['instructions'] ?? '');
            $checklistItems = trim($_POST['checklist_items'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            // Convert checklist items to JSON array
            $checklistArray = [];
            if ($checklistItems) {
                $lines = explode("\n", $checklistItems);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line) {
                        $checklistArray[] = $line;
                    }
                }
            }
            $checklistJson = !empty($checklistArray) ? json_encode($checklistArray) : null;
            
            if (empty($templateName)) {
                $error = "Template name is required";
            } elseif (empty($assetType)) {
                $error = "Asset type is required";
            } elseif (empty($taskDescription)) {
                $error = "Task description is required";
            } else {
                try {
                    if ($_POST['action'] === 'add') {
                        $stmt = $conn->prepare("
                            INSERT INTO re_maintenance_templates 
                            (company_id, template_name, asset_type, task_description,
                             estimated_duration_minutes, estimated_cost, required_parts,
                             instructions, checklist_items, is_active, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $currentCompanyId, $templateName, $assetType, $taskDescription,
                            $estimatedDuration, $estimatedCost, $requiredParts ?: null,
                            $instructions ?: null, $checklistJson, $isActive, $userId
                        ]);
                        $success = "Template created successfully";
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE re_maintenance_templates 
                            SET template_name = ?, asset_type = ?, task_description = ?,
                                estimated_duration_minutes = ?, estimated_cost = ?, required_parts = ?,
                                instructions = ?, checklist_items = ?, is_active = ?
                            WHERE id = ? AND company_id = ?
                        ");
                        $stmt->execute([
                            $templateName, $assetType, $taskDescription,
                            $estimatedDuration, $estimatedCost, $requiredParts ?: null,
                            $instructions ?: null, $checklistJson, $isActive,
                            $id, $currentCompanyId
                        ]);
                        $success = "Template updated successfully";
                    }
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            try {
                $stmt = $conn->prepare("DELETE FROM re_maintenance_templates WHERE id = ? AND company_id = ?");
                $stmt->execute([$id, $currentCompanyId]);
                $success = "Template deleted successfully";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'create_schedule') {
            // Create schedule from template
            $templateId = (int)$_POST['template_id'];
            $scheduleName = trim($_POST['schedule_name'] ?? '');
            $frequencyType = $_POST['frequency_type'] ?? '';
            $frequencyValue = !empty($_POST['frequency_value']) ? (int)$_POST['frequency_value'] : null;
            $frequencyDay = !empty($_POST['frequency_day']) ? (int)$_POST['frequency_day'] : null;
            $frequencyMonth = !empty($_POST['frequency_month']) ? (int)$_POST['frequency_month'] : null;
            $buildingId = !empty($_POST['building_id']) ? (int)$_POST['building_id'] : null;
            $assetId = !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
            $assetType = !empty($_POST['asset_type']) ? $_POST['asset_type'] : null;
            $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $priority = $_POST['priority'] ?? 'medium';
            $startDate = $_POST['start_date'] ?? date('Y-m-d');
            
            if (empty($scheduleName)) {
                $error = "Schedule name is required";
            } elseif (empty($frequencyType)) {
                $error = "Frequency type is required";
            } else {
                try {
                    // Get template
                    $templateStmt = $conn->prepare("SELECT * FROM re_maintenance_templates WHERE id = ? AND company_id = ?");
                    $templateStmt->execute([$templateId, $currentCompanyId]);
                    $template = $templateStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$template) {
                        $error = "Template not found";
                    } else {
                        require_once __DIR__ . '/includes/preventive_maintenance_helper.php';
                        
                        // Calculate next due date
                        $nextDueDate = calculate_next_due_date(
                            $frequencyType,
                            $frequencyValue,
                            $frequencyDay,
                            $frequencyMonth,
                            null,
                            $startDate
                        );
                        
                        // Create schedule from template
                        $scheduleStmt = $conn->prepare("
                            INSERT INTO re_preventive_maintenance_schedules 
                            (company_id, schedule_name, asset_id, asset_type, building_id, task_description,
                             frequency_type, frequency_value, frequency_day, frequency_month,
                             estimated_duration_minutes, estimated_cost, assigned_to, priority,
                             required_parts, instructions, is_active, next_due_date, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                        ");
                        $scheduleStmt->execute([
                            $currentCompanyId, $scheduleName, $assetId, $assetType, $buildingId, $template['task_description'],
                            $frequencyType, $frequencyValue, $frequencyDay, $frequencyMonth,
                            $template['estimated_duration_minutes'], $template['estimated_cost'], $assignedTo, $priority,
                            $template['required_parts'], $template['instructions'], $nextDueDate, $userId
                        ]);
                        
                        $scheduleId = $conn->lastInsertId();
                        $success = "Schedule created from template successfully! <a href='preventive_maintenance_schedules.php'>View Schedule</a>";
                    }
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// Get filter parameters
$filterType = $_GET['asset_type'] ?? 'all';
$filterActive = $_GET['active'] ?? 'all';

// Build query
$where = ["t.company_id = ?"];
$params = [$currentCompanyId];

if ($filterType !== 'all') {
    $where[] = "t.asset_type = ?";
    $params[] = $filterType;
}

if ($filterActive === 'active') {
    $where[] = "t.is_active = 1";
} elseif ($filterActive === 'inactive') {
    $where[] = "t.is_active = 0";
}

// Get templates
$templates = $conn->prepare("
    SELECT t.*, u.username as created_by_name
    FROM re_maintenance_templates t
    LEFT JOIN user u ON u.id = t.created_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.asset_type, t.template_name
");
$templates->execute($params);
$templates = $templates->fetchAll(PDO::FETCH_ASSOC);

// Get assets for schedule creation
$assets = $conn->prepare("
    SELECT id, asset_name, asset_type, building_id 
    FROM re_maintenance_assets 
    WHERE company_id = ? AND is_active = 1 
    ORDER BY asset_type, asset_name
");
$assets->execute([$currentCompanyId]);
$assets = $assets->fetchAll(PDO::FETCH_ASSOC);

// Get buildings
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

// Get employees
$employees = $conn->prepare("SELECT id, full_name FROM employees ORDER BY full_name");
$employees->execute();
$employees = $employees->fetchAll(PDO::FETCH_ASSOC);

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
function formatChecklist($checklistJson) {
    if (!$checklistJson) return [];
    $items = json_decode($checklistJson, true);
    return is_array($items) ? $items : [];
}

// Set page title and include layout
$pageTitle = 'Maintenance Templates';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-file-earmark-text"></i> Maintenance Templates</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTemplateModal">
                <i class="bi bi-plus-circle"></i> New Template
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Asset Type</label>
                        <select name="asset_type" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="ac_unit" <?= $filterType === 'ac_unit' ? 'selected' : '' ?>>AC Unit</option>
                            <option value="elevator" <?= $filterType === 'elevator' ? 'selected' : '' ?>>Elevator</option>
                            <option value="fire_system" <?= $filterType === 'fire_system' ? 'selected' : '' ?>>Fire System</option>
                            <option value="plumbing" <?= $filterType === 'plumbing' ? 'selected' : '' ?>>Plumbing</option>
                            <option value="electrical" <?= $filterType === 'electrical' ? 'selected' : '' ?>>Electrical</option>
                            <option value="hvac" <?= $filterType === 'hvac' ? 'selected' : '' ?>>HVAC</option>
                            <option value="generator" <?= $filterType === 'generator' ? 'selected' : '' ?>>Generator</option>
                            <option value="pump" <?= $filterType === 'pump' ? 'selected' : '' ?>>Pump</option>
                            <option value="security_system" <?= $filterType === 'security_system' ? 'selected' : '' ?>>Security System</option>
                            <option value="other" <?= $filterType === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="active" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?= $filterActive === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="active" <?= $filterActive === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $filterActive === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Templates Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Templates (<?= count($templates) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($templates)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No templates found. 
                        <a href="#" data-bs-toggle="modal" data-bs-target="#addTemplateModal">Create your first template</a>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Template Name</th>
                                    <th>Asset Type</th>
                                    <th>Description</th>
                                    <th>Duration</th>
                                    <th>Cost</th>
                                    <th>Checklist Items</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($templates as $template): 
                                    $checklist = formatChecklist($template['checklist_items']);
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($template['template_name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= formatAssetType($template['asset_type']) ?></span>
                                        </td>
                                        <td>
                                            <?= h(substr($template['task_description'], 0, 50)) ?>
                                            <?= strlen($template['task_description']) > 50 ? '...' : '' ?>
                                        </td>
                                        <td>
                                            <?= $template['estimated_duration_minutes'] ? formatDuration($template['estimated_duration_minutes']) : '-' ?>
                                        </td>
                                        <td>
                                            <?= $template['estimated_cost'] > 0 ? number_format($template['estimated_cost'], 2) . ' AED' : '-' ?>
                                        </td>
                                        <td>
                                            <?php if (count($checklist) > 0): ?>
                                                <span class="badge bg-primary"><?= count($checklist) ?> items</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($template['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-success" onclick="createSchedule(<?= htmlspecialchars(json_encode($template)) ?>)">
                                                    <i class="bi bi-calendar-plus"></i> Create Schedule
                                                </button>
                                                <button class="btn btn-sm btn-primary" onclick="editTemplate(<?= htmlspecialchars(json_encode($template)) ?>)">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this template?');">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $template['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
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

    <!-- Add/Edit Template Modal -->
    <div class="modal fade" id="addTemplateModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="templateForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="templateId">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Template Name *</label>
                                <input type="text" name="template_name" class="form-control" id="templateName" required>
                                <small class="text-muted">e.g., "AC Unit Monthly Service"</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asset Type *</label>
                                <select name="asset_type" class="form-select" id="templateAssetType" required>
                                    <option value="">Select Type</option>
                                    <option value="ac_unit">AC Unit</option>
                                    <option value="elevator">Elevator</option>
                                    <option value="fire_system">Fire System</option>
                                    <option value="plumbing">Plumbing</option>
                                    <option value="electrical">Electrical</option>
                                    <option value="hvac">HVAC</option>
                                    <option value="generator">Generator</option>
                                    <option value="pump">Pump</option>
                                    <option value="security_system">Security System</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Task Description *</label>
                            <textarea name="task_description" class="form-control" rows="3" id="templateDescription" required></textarea>
                            <small class="text-muted">Describe what maintenance work needs to be performed</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Estimated Duration (minutes)</label>
                                <input type="number" name="estimated_duration_minutes" class="form-control" id="templateDuration" min="1">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Estimated Cost (AED)</label>
                                <input type="number" step="0.01" name="estimated_cost" class="form-control" id="templateCost" value="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Required Parts/Materials</label>
                            <textarea name="required_parts" class="form-control" rows="2" id="templateParts" 
                                      placeholder="List required parts, materials, or supplies (one per line)"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Instructions</label>
                            <textarea name="instructions" class="form-control" rows="4" id="templateInstructions" 
                                      placeholder="Step-by-step instructions for performing this maintenance"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Checklist Items</label>
                            <textarea name="checklist_items" class="form-control" rows="4" id="templateChecklist" 
                                      placeholder="Enter checklist items, one per line (e.g., Check filters, Test operation, Clean unit)"></textarea>
                            <small class="text-muted">One item per line. These will be included when creating schedules from this template.</small>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" 
                                       id="templateIsActive" value="1" checked>
                                <label class="form-check-label" for="templateIsActive">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Template</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Schedule from Template Modal -->
    <div class="modal fade" id="createScheduleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Schedule from Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="createScheduleForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_schedule">
                    <input type="hidden" name="template_id" id="scheduleTemplateId">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> This will create a new maintenance schedule using the template details.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Schedule Name *</label>
                            <input type="text" name="schedule_name" class="form-control" id="scheduleNameFromTemplate" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asset Assignment</label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="asset_assignment" id="scheduleAssetSpecific" value="specific" checked onchange="toggleScheduleAssetSelection()">
                                    <label class="form-check-label" for="scheduleAssetSpecific">
                                        Specific Asset
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="asset_assignment" id="scheduleAssetType" value="type" onchange="toggleScheduleAssetSelection()">
                                    <label class="form-check-label" for="scheduleAssetType">
                                        All Assets of Type
                                    </label>
                                </div>
                                <select name="asset_id" class="form-select" id="scheduleAssetIdFromTemplate">
                                    <option value="">Select Asset</option>
                                    <?php foreach ($assets as $asset): ?>
                                        <option value="<?= $asset['id'] ?>" data-type="<?= $asset['asset_type'] ?>">
                                            <?= h($asset['asset_name']) ?> (<?= formatAssetType($asset['asset_type']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="asset_type" class="form-select" id="scheduleAssetTypeFromTemplate" style="display: none;">
                                    <option value="">Select Asset Type</option>
                                    <option value="ac_unit">AC Unit</option>
                                    <option value="elevator">Elevator</option>
                                    <option value="fire_system">Fire System</option>
                                    <option value="plumbing">Plumbing</option>
                                    <option value="electrical">Electrical</option>
                                    <option value="hvac">HVAC</option>
                                    <option value="generator">Generator</option>
                                    <option value="pump">Pump</option>
                                    <option value="security_system">Security System</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Building</label>
                                <select name="building_id" class="form-select" id="scheduleBuildingFromTemplate">
                                    <option value="">All Buildings (Optional)</option>
                                    <?php foreach ($buildings as $b): ?>
                                        <option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Frequency Type *</label>
                                <select name="frequency_type" class="form-select" id="scheduleFrequencyType" required onchange="toggleScheduleFrequencyFields()">
                                    <option value="">Select Frequency</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="semi_annual">Semi-Annual</option>
                                    <option value="annual">Annual</option>
                                    <option value="custom">Custom (Days)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" id="scheduleStartDate" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div id="scheduleFrequencyFields">
                            <div class="row" id="scheduleWeeklyFields" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Day of Week</label>
                                    <select name="frequency_day" class="form-select">
                                        <option value="">Any day</option>
                                        <option value="1">Monday</option>
                                        <option value="2">Tuesday</option>
                                        <option value="3">Wednesday</option>
                                        <option value="4">Thursday</option>
                                        <option value="5">Friday</option>
                                        <option value="6">Saturday</option>
                                        <option value="7">Sunday</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row" id="scheduleMonthlyFields" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Day of Month</label>
                                    <input type="number" name="frequency_day" class="form-control" min="1" max="31">
                                </div>
                            </div>
                            <div class="row" id="scheduleAnnualFields" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Month</label>
                                    <select name="frequency_month" class="form-select">
                                        <option value="">Any month</option>
                                        <option value="1">January</option>
                                        <option value="2">February</option>
                                        <option value="3">March</option>
                                        <option value="4">April</option>
                                        <option value="5">May</option>
                                        <option value="6">June</option>
                                        <option value="7">July</option>
                                        <option value="8">August</option>
                                        <option value="9">September</option>
                                        <option value="10">October</option>
                                        <option value="11">November</option>
                                        <option value="12">December</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Day of Month</label>
                                    <input type="number" name="frequency_day" class="form-control" min="1" max="31">
                                </div>
                            </div>
                            <div class="row" id="scheduleCustomFields" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Every X Days</label>
                                    <input type="number" name="frequency_value" class="form-control" min="1">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select" id="schedulePriorityFromTemplate">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Default Assigned To</label>
                                <select name="assigned_to" class="form-select" id="scheduleAssignedToFromTemplate">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>"><?= h($emp['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editTemplate(template) {
            document.getElementById('modalTitle').textContent = 'Edit Template';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('templateId').value = template.id;
            document.getElementById('templateName').value = template.template_name || '';
            document.getElementById('templateAssetType').value = template.asset_type || '';
            document.getElementById('templateDescription').value = template.task_description || '';
            document.getElementById('templateDuration').value = template.estimated_duration_minutes || '';
            document.getElementById('templateCost').value = template.estimated_cost || '0';
            document.getElementById('templateParts').value = template.required_parts || '';
            document.getElementById('templateInstructions').value = template.instructions || '';
            
            // Parse checklist items
            let checklistItems = '';
            if (template.checklist_items) {
                try {
                    const items = JSON.parse(template.checklist_items);
                    if (Array.isArray(items)) {
                        checklistItems = items.join('\n');
                    }
                } catch (e) {
                    checklistItems = template.checklist_items;
                }
            }
            document.getElementById('templateChecklist').value = checklistItems;
            document.getElementById('templateIsActive').checked = template.is_active == 1;
            
            const modal = new bootstrap.Modal(document.getElementById('addTemplateModal'));
            modal.show();
        }

        function createSchedule(template) {
            document.getElementById('scheduleTemplateId').value = template.id;
            document.getElementById('scheduleNameFromTemplate').value = template.template_name || '';
            document.getElementById('scheduleAssetTypeFromTemplate').value = template.asset_type || '';
            
            // Filter assets by template asset type
            const assetSelect = document.getElementById('scheduleAssetIdFromTemplate');
            Array.from(assetSelect.options).forEach(option => {
                if (option.value && option.dataset.type !== template.asset_type) {
                    option.style.display = 'none';
                } else {
                    option.style.display = 'block';
                }
            });
            
            toggleScheduleAssetSelection();
            const modal = new bootstrap.Modal(document.getElementById('createScheduleModal'));
            modal.show();
        }

        function toggleScheduleAssetSelection() {
            const specific = document.getElementById('scheduleAssetSpecific').checked;
            document.getElementById('scheduleAssetIdFromTemplate').style.display = specific ? 'block' : 'none';
            document.getElementById('scheduleAssetTypeFromTemplate').style.display = specific ? 'none' : 'block';
            if (specific) {
                document.getElementById('scheduleAssetIdFromTemplate').required = true;
                document.getElementById('scheduleAssetTypeFromTemplate').required = false;
            } else {
                document.getElementById('scheduleAssetIdFromTemplate').required = false;
                document.getElementById('scheduleAssetTypeFromTemplate').required = true;
            }
        }

        function toggleScheduleFrequencyFields() {
            const freqType = document.getElementById('scheduleFrequencyType').value;
            
            document.getElementById('scheduleWeeklyFields').style.display = 'none';
            document.getElementById('scheduleMonthlyFields').style.display = 'none';
            document.getElementById('scheduleAnnualFields').style.display = 'none';
            document.getElementById('scheduleCustomFields').style.display = 'none';
            
            if (freqType === 'weekly') {
                document.getElementById('scheduleWeeklyFields').style.display = 'block';
            } else if (freqType === 'monthly') {
                document.getElementById('scheduleMonthlyFields').style.display = 'block';
            } else if (freqType === 'annual') {
                document.getElementById('scheduleAnnualFields').style.display = 'block';
            } else if (freqType === 'custom') {
                document.getElementById('scheduleCustomFields').style.display = 'block';
            }
        }

        function formatDuration(minutes) {
            if (!minutes) return '-';
            if (minutes < 60) return minutes + ' min';
            const hours = Math.floor(minutes / 60);
            const mins = minutes % 60;
            return hours + 'h ' + mins + 'm';
        }

        // Reset form when modal is closed
        document.getElementById('addTemplateModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('templateForm').reset();
            document.getElementById('modalTitle').textContent = 'Add Template';
            document.getElementById('formAction').value = 'add';
            document.getElementById('templateId').value = '';
            document.getElementById('templateIsActive').checked = true;
        });
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

