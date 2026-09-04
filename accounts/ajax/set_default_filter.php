<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/advanced_search_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$filter_id = (int)($input['filter_id'] ?? 0);
$page_type = $input['page_type'] ?? '';
$user_id = $_SESSION['user']['id'] ?? 1;

if ($filter_id <= 0 || empty($page_type)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Unset other defaults for this page type
    $stmt = $conn->prepare("
        UPDATE saved_search_filters 
        SET is_default = FALSE 
        WHERE page_type = ? AND (user_id = ? OR is_global = TRUE)
    ");
    $stmt->execute([$page_type, $user_id]);
    
    // Set this filter as default
    $stmt = $conn->prepare("
        UPDATE saved_search_filters 
        SET is_default = TRUE 
        WHERE id = ? AND (user_id = ? OR is_global = TRUE)
    ");
    $stmt->execute([$filter_id, $user_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Filter not found or access denied');
    }
    
    $conn->commit();
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
