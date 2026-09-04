<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/advanced_search_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$page_type = $_GET['page_type'] ?? '';
$user_id = $_SESSION['user']['id'] ?? 1;

if (empty($page_type)) {
    echo json_encode(['success' => false, 'error' => 'Page type is required']);
    exit;
}

try {
    $searchService = new AdvancedSearchService($conn);
    $result = $searchService->getSearchHistory($user_id, $page_type, 20);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
