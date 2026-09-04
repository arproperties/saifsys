<?php
/**
 * AJAX - return selectable records for a given legal link type (company scoped).
 * Returns: { success: bool, items: [ { id, label } ] }
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
header('Content-Type: application/json');

if (!legal_can_manage($conn)) {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$type = $_GET['type'] ?? '';
$companyId = current_company_id($conn) ?: 1;
$q = trim($_GET['q'] ?? '');
$items = [];

try {
    switch ($type) {
        case 'building':
            $s = $conn->prepare("SELECT id, name AS label FROM re_buildings WHERE company_id = ? ORDER BY name");
            $s->execute([$companyId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'unit':
            $s = $conn->prepare("
                SELECT u.id, CONCAT(b.name, ' - ', u.unit_number) AS label
                FROM re_units u JOIN re_buildings b ON b.id = u.building_id
                WHERE u.company_id = ? ORDER BY b.name, u.unit_number LIMIT 1000");
            $s->execute([$companyId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'tenant':
            $s = $conn->prepare("
                SELECT id, CASE WHEN tenant_type = 'company' AND company_name <> '' THEN company_name
                                ELSE TRIM(CONCAT(first_name, ' ', last_name)) END AS label
                FROM re_tenants WHERE company_id = ? AND is_active = 1
                ORDER BY label LIMIT 1000");
            $s->execute([$companyId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'lease':
            $s = $conn->prepare("
                SELECT l.id, CONCAT(l.lease_number, ' (', b.name, ' - ', u.unit_number, ')') AS label
                FROM re_leases l JOIN re_units u ON u.id = l.unit_id JOIN re_buildings b ON b.id = u.building_id
                WHERE l.company_id = ? AND l.deleted_at IS NULL ORDER BY l.created_at DESC LIMIT 1000");
            $s->execute([$companyId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'owner':
            $s = $conn->prepare("SELECT id, name AS label FROM re_property_owners WHERE company_id = ? AND is_active = 1 ORDER BY name");
            $s->execute([$companyId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'cheque':
            $s = $conn->prepare("
                SELECT c.id, CONCAT(c.cheque_number, ' - ', FORMAT(c.cheque_amount, 2), ' (', c.status, ')') AS label
                FROM re_post_dated_cheques c
                WHERE c.company_id = ? ORDER BY c.cheque_date DESC LIMIT 1000");
            $s->execute([$companyId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'document':
            $s = $conn->prepare("
                SELECT id, COALESCE(NULLIF(document_name, ''), file_name) AS label
                FROM re_documents WHERE company_id = ? ORDER BY created_at DESC LIMIT 1000");
            $s->execute([$companyId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'payment':
            $s = $conn->prepare("
                SELECT id, CONCAT(COALESCE(NULLIF(receipt_number, ''), CONCAT('Payment #', id)), ' - ', FORMAT(amount, 2), ' (', payment_date, ')') AS label
                FROM re_payments WHERE company_id = ? ORDER BY payment_date DESC LIMIT 1000");
            $s->execute([$companyId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'installment':
            $s = $conn->prepare("
                SELECT id, CONCAT('Installment ', installment_date, ' - ', FORMAT(amount, 2), ' (', status, ')') AS label
                FROM re_lease_installments WHERE company_id = ? ORDER BY installment_date DESC LIMIT 1000");
            $s->execute([$companyId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid type']);
            exit;
    }

    if ($q !== '') {
        $needle = mb_strtolower($q);
        $items = array_values(array_filter($items, function ($it) use ($needle) {
            return mb_strpos(mb_strtolower($it['label']), $needle) !== false;
        }));
    }

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
