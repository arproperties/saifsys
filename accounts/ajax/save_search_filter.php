<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/advanced_search_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$user_id = $_SESSION['user']['id'] ?? 1;
$filter_name = $_POST['filter_name'] ?? '';
$page_type = $_POST['page_type'] ?? '';
$filter_criteria = $_POST['filter_criteria'] ?? '';
$is_global = isset($_POST['is_global']) ? (bool)$_POST['is_global'] : false;
$is_default = isset($_POST['is_default']) ? (bool)$_POST['is_default'] : false;

if (empty($filter_name) || empty($page_type) || empty($filter_criteria)) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    $searchService = new AdvancedSearchService($conn);
    $criteria = json_decode($filter_criteria, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid filter criteria JSON');
    }
    
    $result = $searchService->saveFilter($user_id, $filter_name, $page_type, $criteria, $is_global, $is_default);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
