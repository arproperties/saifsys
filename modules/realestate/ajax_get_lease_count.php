<?php
/**
 * AJAX: next lease sequence for a unit (max existing BuildingShort-Unit-NNNN suffix + 1).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/lease_number_sequence.php';

header('Content-Type: application/json');

$unitId = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$currentCompanyId = current_company_id($conn) ?: 1;

if ($unitId) {
    $next = lease_next_sequence_for_unit($conn, $currentCompanyId, $unitId);
    $n = $next !== null ? $next : 1;
    echo json_encode([
        'count' => max(0, $n - 1),
        'next_sequence' => $n,
    ]);
} else {
    echo json_encode(['count' => 0, 'next_sequence' => 1]);
}
