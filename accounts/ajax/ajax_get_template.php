<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

// Helper function for HTML escaping
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

header('Content-Type: application/json');

$template_id = (int)($_GET['id'] ?? 0);

if ($template_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid template ID']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT * FROM email_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        echo json_encode(['success' => false, 'error' => 'Template not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'template' => $template]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to load template: ' . $e->getMessage()]);
}
