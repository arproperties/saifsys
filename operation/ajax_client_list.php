<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/includes/clients_page_helpers.php';

header('Content-Type: application/json');

$companyId = current_company_id($conn) ?: 1;
$q = trim($_GET['q'] ?? '');
$preset = trim($_GET['preset'] ?? '');
$limit = (int)($_GET['limit'] ?? 60);
$offset = (int)($_GET['offset'] ?? 0);
$activeId = (int)($_GET['active_id'] ?? 0);

try {
    $rows = clients_fetch_sidebar_list($conn, (int)$companyId, [
        'q' => $q,
        'preset' => $preset,
        'limit' => $limit,
        'offset' => $offset,
    ]);

    $html = '';
    foreach ($rows as $row) {
        $html .= clients_render_sidebar_item($row, $activeId);
    }

    echo json_encode([
        'success' => true,
        'count' => count($rows),
        'html' => $html,
        'has_more' => count($rows) >= min(100, max(10, $limit)),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load clients.']);
}
