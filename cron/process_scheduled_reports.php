<?php
/**
 * Cron script to process scheduled reports
 * This script should be run every 5 minutes via cron job
 */

// Set the working directory to the project root
chdir(__DIR__ . '/..');

// Include necessary files
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/scheduled_reports_service.php';

// Set error reporting for cron
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log function for cron output
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
    error_log("[{$timestamp}] {$message}");
}

try {
    logMessage("Starting scheduled reports processing...");
    
    // Initialize the service
    $scheduledReportsService = new ScheduledReportsService($conn);
    
    // Get reports that are due to run
    $dueReports = $scheduledReportsService->getDueReports();
    
    if (empty($dueReports)) {
        logMessage("No reports due for processing.");
        exit(0);
    }
    
    logMessage("Found " . count($dueReports) . " report(s) due for processing.");
    
    $processed = 0;
    $failed = 0;
    
    foreach ($dueReports as $report) {
        try {
            logMessage("Processing report: {$report['report_name']} (ID: {$report['id']})");
            
            $result = $scheduledReportsService->processScheduledReport($report['id']);
            
            if ($result['success']) {
                logMessage("Successfully processed report: {$report['report_name']}");
                $processed++;
            } else {
                logMessage("Failed to process report: {$report['report_name']} - Error: {$result['error']}");
                $failed++;
            }
            
        } catch (Exception $e) {
            logMessage("Exception processing report {$report['report_name']}: " . $e->getMessage());
            $failed++;
        }
    }
    
    logMessage("Processing complete. Processed: {$processed}, Failed: {$failed}");
    
} catch (Exception $e) {
    logMessage("Fatal error in scheduled reports processing: " . $e->getMessage());
    exit(1);
}

exit(0);