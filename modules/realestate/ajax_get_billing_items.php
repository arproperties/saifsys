<?php
/**
 * AJAX endpoint to get unpaid billing items for a lease
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

header('Content-Type: application/json');

$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;
$currentCompanyId = current_company_id($conn) ?: 1;

if ($leaseId) {
    $stmt = $conn->prepare("
        SELECT 
            bi.*,
            li.installment_date
        FROM re_billing_items bi
        LEFT JOIN re_lease_installments li ON li.id = bi.installment_id
        WHERE bi.lease_id = ? 
        AND bi.company_id = ? 
        AND bi.is_paid = 0
        ORDER BY bi.due_date ASC, bi.created_at ASC
    ");
    $stmt->execute([$leaseId, $currentCompanyId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($items);
} else {
    echo json_encode([]);
}

