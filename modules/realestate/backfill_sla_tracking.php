<?php
/**
 * Backfill SLA Tracking for Existing Maintenance Requests
 * This script creates SLA tracking records for maintenance requests that don't have them
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

require_once __DIR__ . '/includes/sla_helper.php';

$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

// Get all maintenance requests without SLA tracking
$requests = $conn->prepare("
    SELECT mr.id, mr.company_id, mr.priority, mr.category, mr.created_at
    FROM re_maintenance_requests mr
    LEFT JOIN re_sla_tracking st ON st.maintenance_request_id = mr.id
    WHERE st.id IS NULL
    ORDER BY mr.id
");
$requests->execute();
$requests = $requests->fetchAll(PDO::FETCH_ASSOC);

$created = 0;
$skipped = 0;
$errors = [];

foreach ($requests as $request) {
    try {
        // Get SLA rule for this request
        $slaRule = get_sla_rule($conn, $request['company_id'], $request['priority'], $request['category']);
        
        if (!$slaRule) {
            $skipped++;
            $errors[] = "Request #{$request['id']}: No SLA rule found for priority '{$request['priority']}' and category '{$request['category']}'";
            continue;
        }
        
        // Calculate target times
        $requestDateTime = new DateTime($request['created_at']);
        $targetResponseTime = clone $requestDateTime;
        $targetResponseTime->modify("+{$slaRule['response_time_minutes']} minutes");
        
        $targetResolutionTime = clone $requestDateTime;
        $targetResolutionTime->modify("+{$slaRule['resolution_time_hours']} hours");
        
        // Create SLA tracking record
        $stmt = $conn->prepare("
            INSERT INTO re_sla_tracking 
            (company_id, maintenance_request_id, sla_rule_id, target_response_time, target_resolution_time)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $request['company_id'],
            $request['id'],
            $slaRule['id'],
            $targetResponseTime->format('Y-m-d H:i:s'),
            $targetResolutionTime->format('Y-m-d H:i:s')
        ]);
        
        // Update response time if request was already responded to
        $checkResponse = $conn->prepare("SELECT responded_at FROM re_maintenance_requests WHERE id = ? AND responded_at IS NOT NULL");
        $checkResponse->execute([$request['id']]);
        $responseData = $checkResponse->fetch(PDO::FETCH_ASSOC);
        
        if ($responseData && $responseData['responded_at']) {
            update_sla_response_time($conn, $request['id'], $responseData['responded_at']);
        }
        
        // Update resolution time if request was already completed
        $checkCompletion = $conn->prepare("SELECT completed_at FROM re_maintenance_requests WHERE id = ? AND completed_at IS NOT NULL");
        $checkCompletion->execute([$request['id']]);
        $completionData = $checkCompletion->fetch(PDO::FETCH_ASSOC);
        
        if ($completionData && $completionData['completed_at']) {
            update_sla_resolution_time($conn, $request['id'], $completionData['completed_at']);
        }
        
        $created++;
    } catch (Exception $e) {
        $errors[] = "Request #{$request['id']}: " . $e->getMessage();
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SLA Tracking Backfill - Real Estate</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="reports.php">
                <i class="bi bi-speedometer2"></i> Real Estate - SLA Tracking Backfill
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="reports_sla_performance.php">SLA Report</a>
                <a class="nav-link" href="reports.php">Reports</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h1><i class="bi bi-speedometer2"></i> SLA Tracking Backfill</h1>
        
        <div class="card mt-4">
            <div class="card-body">
                <h5 class="card-title">Results</h5>
                <div class="alert alert-success">
                    <strong>Success!</strong> Created SLA tracking for <strong><?= $created ?></strong> maintenance request(s).
                </div>
                
                <?php if ($skipped > 0): ?>
                    <div class="alert alert-warning">
                        <strong>Warning:</strong> Skipped <strong><?= $skipped ?></strong> request(s) (no matching SLA rules).
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-info">
                        <strong>Details:</strong>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= h($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <div class="mt-3">
                    <a href="reports_sla_performance.php" class="btn btn-primary">
                        <i class="bi bi-speedometer2"></i> View SLA Performance Report
                    </a>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

