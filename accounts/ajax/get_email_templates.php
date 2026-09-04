<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/email_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$type = $_GET['type'] ?? null;

try {
    $emailService = new EmailService($conn);
    $templates = $emailService->getTemplates($type);
    
    echo json_encode(['success' => true, 'templates' => $templates]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to load templates: ' . $e->getMessage()]);
}
