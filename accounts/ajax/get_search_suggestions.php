<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/advanced_search_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$page_type = $_GET['page_type'] ?? '';
$query = $_GET['query'] ?? '';
$suggestion_type = $_GET['suggestion_type'] ?? null;

if (empty($page_type) || empty($query)) {
    echo json_encode(['success' => false, 'error' => 'Page type and query are required']);
    exit;
}

try {
    $searchService = new AdvancedSearchService($conn);
    $result = $searchService->getSuggestions($page_type, $query, $suggestion_type, 10);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
