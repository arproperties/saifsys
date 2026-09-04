<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/advanced_search_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$filter_id = (int)($input['filter_id'] ?? 0);
$user_id = $_SESSION['user']['id'] ?? 1;

if ($filter_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid filter ID']);
    exit;
}

try {
    $searchService = new AdvancedSearchService($conn);
    $result = $searchService->deleteFilter($filter_id, $user_id);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
