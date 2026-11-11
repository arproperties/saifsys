<?php
/**
 * Legal - Issues: lookups for the issue form (JSON).
 *   ?action=buildings&company_id=     buildings of a company
 *   ?action=units&company_id=&building_id=
 *   ?action=unit&company_id=&unit_id= contracts on the unit (active first) with tenant details
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/includes/legal_issues_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

header('Content-Type: application/json; charset=utf-8');
$currentCompanyId = current_company_id($conn) ?: 1;
$companyId = (int)($_GET['company_id'] ?? $currentCompanyId);
if (!legal_issue_can_company($conn, $companyId, $currentCompanyId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No access to this company']);
    exit;
}

try {
    switch ($_GET['action'] ?? '') {
        case 'buildings':
            $st = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? AND is_active = 1 ORDER BY name");
            $st->execute([$companyId]);
            echo json_encode(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        case 'units':
            $st = $conn->prepare("SELECT u.id, u.unit_number FROM re_units u
                                  JOIN re_buildings b ON b.id = u.building_id AND b.company_id = ?
                                  WHERE u.building_id = ? ORDER BY u.unit_number + 0, u.unit_number");
            $st->execute([$companyId, (int)($_GET['building_id'] ?? 0)]);
            echo json_encode(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        case 'unit':
            $leases = legal_issue_unit_leases($conn, $companyId, (int)($_GET['unit_id'] ?? 0));
            foreach ($leases as &$l) $l['wa'] = legal_wa_number($l['phone']);
            unset($l);
            echo json_encode(['ok' => true, 'leases' => $leases]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Lookup failed']);
}
