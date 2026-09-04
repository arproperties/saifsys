<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/email_queue_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

try {
    $emailQueueService = new EmailQueueService($conn);
    $stats = $emailQueueService->getQueueStats();
    
    echo json_encode(['success' => true, 'stats' => $stats]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
