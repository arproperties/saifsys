<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/scheduled_reports_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$report_id = (int)($_GET['id'] ?? 0);

if ($report_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid report ID']);
    exit;
}

try {
    $scheduledReportsService = new ScheduledReportsService($conn);
    $report = $scheduledReportsService->getScheduledReport($report_id);
    
    if (!$report) {
        echo json_encode(['success' => false, 'error' => 'Report not found']);
        exit;
    }
    
    // Parse parameters JSON
    $report['parameters'] = json_decode($report['parameters'] ?? '{}', true);
    
    echo json_encode(['success' => true, 'report' => $report]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
