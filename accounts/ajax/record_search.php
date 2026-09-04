<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/advanced_search_service.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$page_type = $input['page_type'] ?? '';
$search_query = $input['search_query'] ?? null;
$filter_criteria = $input['filter_criteria'] ?? null;
$user_id = $_SESSION['user']['id'] ?? 1;

if (empty($page_type)) {
    echo json_encode(['success' => false, 'error' => 'Page type is required']);
    exit;
}

try {
    $searchService = new AdvancedSearchService($conn);
    
    // Count results based on page type
    $results_count = 0;
    if ($page_type === 'invoices') {
        $searchQuery = $searchService->buildInvoiceSearchQuery($filter_criteria ?? []);
        $sql = "SELECT COUNT(*) FROM invoices i LEFT JOIN clients c ON i.client_id = c.id {$searchQuery['where_clause']}";
        $stmt = $conn->prepare($sql);
        $stmt->execute($searchQuery['params']);
        $results_count = $stmt->fetchColumn();
    } elseif ($page_type === 'payments') {
        $searchQuery = $searchService->buildPaymentSearchQuery($filter_criteria ?? []);
        $sql = "SELECT COUNT(*) FROM invoice_payments p LEFT JOIN invoices i ON p.invoice_id = i.id {$searchQuery['where_clause']}";
        $stmt = $conn->prepare($sql);
        $stmt->execute($searchQuery['params']);
        $results_count = $stmt->fetchColumn();
    } elseif ($page_type === 'expenses') {
        $searchQuery = $searchService->buildExpenseSearchQuery($filter_criteria ?? []);
        $sql = "SELECT COUNT(*) FROM expenses e {$searchQuery['where_clause']}";
        $stmt = $conn->prepare($sql);
        $stmt->execute($searchQuery['params']);
        $results_count = $stmt->fetchColumn();
    }
    
    $result = $searchService->recordSearch($user_id, $page_type, $search_query, $filter_criteria, $results_count);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
