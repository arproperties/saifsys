<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/includes/clients_page_helpers.php';

$companyId = current_company_id($conn) ?: 1;
$clientId = (int)($_GET['client_id'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

if ($clientId <= 0) {
    http_response_code(400);
    echo '<div class="p-5 text-center text-muted"><i class="bi bi-person-circle" style="font-size:64px"></i><div class="mt-2">Select a client to view details.</div></div>';
    exit;
}

$data = clients_load_detail($conn, (int)$companyId, $clientId, $from, $to);
if (!$data) {
    http_response_code(404);
    echo '<div class="alert alert-danger m-3">Client not found.</div>';
    exit;
}

extract($data, EXTR_SKIP);
$client_id = $clientId;
$safe = 'clients_page_safe';
$money2 = 'clients_page_money2';
$money = 'clients_page_money';
$getHealthScoreClass = 'clients_page_health_class';

ob_start();
include __DIR__ . '/partials/client_detail_panel.php';
echo ob_get_clean();
