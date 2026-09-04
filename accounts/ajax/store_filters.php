<?php
/**
 * Store filter state in session for clean URLs
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['page']) || !isset($input['filters'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

$page = $input['page'];
$filters = $input['filters'];
$tab = $input['tab'] ?? null;

// Store filters in session
if (!isset($_SESSION['filter_state'])) {
    $_SESSION['filter_state'] = [];
}

$key = $page . ($tab ? '_' . $tab : '');
$_SESSION['filter_state'][$key] = $filters;

echo json_encode(['success' => true]);

