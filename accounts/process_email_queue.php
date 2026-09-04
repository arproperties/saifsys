<?php
/**
 * Email Queue Processor
 * This script processes pending email queues in the background
 * Should be run via cron job every few minutes
 * 
 * Example cron entry:
 * */5 * * * * /usr/bin/php /path/to/accounts/process_email_queue.php
 */

require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/email_queue_service.php';

// Set time limit for long-running process
set_time_limit(300); // 5 minutes

// Log function
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[Email Queue Processor] $timestamp: $message");
}

try {
    $emailQueueService = new EmailQueueService($conn);
    
    // Get pending queues
    $pendingQueues = $emailQueueService->getQueues(10, 0, 'pending');
    
    if (empty($pendingQueues)) {
        logMessage("No pending queues to process");
        exit(0);
    }
    
    logMessage("Found " . count($pendingQueues) . " pending queues");
    
    foreach ($pendingQueues as $queue) {
        logMessage("Processing queue ID: {$queue['id']} - {$queue['queue_name']}");
        
        $result = $emailQueueService->processQueue($queue['id']);
        
        if ($result['success']) {
            logMessage("Queue {$queue['id']} processed successfully: {$result['processed']} sent, {$result['failed']} failed");
        } else {
            logMessage("Queue {$queue['id']} processing failed: " . $result['error']);
        }
        
        // Small delay between queues to prevent overwhelming the email service
        usleep(500000); // 0.5 seconds
    }
    
    // Cleanup old completed queues
    $cleaned = $emailQueueService->cleanupOldQueues();
    if ($cleaned > 0) {
        logMessage("Cleaned up $cleaned old queue records");
    }
    
    logMessage("Email queue processing completed");
    
} catch (Exception $e) {
    logMessage("Error processing email queue: " . $e->getMessage());
    exit(1);
}
?>
