<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';

header('Content-Type: application/json');

require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_activity.php';
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();
$action = $_POST['action'] ?? '';
$userId = function_exists('current_user_id') ? current_user_id() : null;

// Resolve the RE company ID for maintenance requests
$reCompanyId = 0;
try {
    $stmt = $conn->query("SELECT id FROM companies WHERE business_type IN ('realestate','real_estate') AND is_active = 1 LIMIT 1");
    $reCompanyId = (int)($stmt->fetchColumn() ?: 0);
} catch (PDOException $e) {}
if (!$reCompanyId) {
    $reCompanyId = (int)($conn->query("SELECT MIN(id) FROM companies WHERE is_active = 1")->fetchColumn() ?: 1);
}

try {
    switch ($action) {

        case 'create_request':
            $unitId      = (int)($_POST['unit_id'] ?? 0);
            $priority    = $_POST['priority'] ?? 'medium';
            $category    = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $cost        = (float)($_POST['cost'] ?? 0);
            $blockUnit   = !empty($_POST['block_unit']);
            $blockStart  = $_POST['block_start'] ?? '';
            $blockEnd    = $_POST['block_end'] ?? '';

            if (!$unitId || !$description) {
                echo json_encode(['success' => false, 'error' => 'Unit and description are required.']);
                exit;
            }

            try {
                ars_assert_unit_usable_for_ars($conn, $unitId, $arsCompanyId);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }

            if (!in_array($priority, ['low','medium','high','urgent'])) $priority = 'medium';

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO re_maintenance_requests
                    (company_id, unit_id, request_date, priority, category, description, cost, status, created_by, source)
                VALUES (?, ?, CURDATE(), ?, ?, ?, ?, 'pending', ?, 'ars')
            ");
            $stmt->execute([$reCompanyId, $unitId, $priority, $category ?: null, $description, $cost, $userId]);
            $requestId = (int)$conn->lastInsertId();

            // Create SLA tracking if the helper exists
            $slaHelper = dirname(__DIR__) . '/realestate/includes/sla_helper.php';
            if (file_exists($slaHelper)) {
                require_once $slaHelper;
                if (function_exists('create_sla_tracking')) {
                    create_sla_tracking($conn, $reCompanyId, $requestId, $priority, $category ?: null, date('Y-m-d H:i:s'));
                }
            }

            $blockedId = null;
            if ($blockUnit && $blockStart && $blockEnd && $blockEnd >= $blockStart) {
                $stmt = $conn->prepare("
                    INSERT INTO ars_blocked_dates
                        (unit_id, company_id, start_date, end_date, reason, maintenance_request_id, block_type, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'maintenance', ?, NOW())
                ");
                $reason = 'Maintenance #' . $requestId . ': ' . mb_substr($description, 0, 100);
                $stmt->execute([$unitId, $arsCompanyId, $blockStart, $blockEnd, $reason, $requestId, $userId]);
                $blockedId = (int)$conn->lastInsertId();
            }

            $conn->commit();

            $linkedBookingId = (int)($_POST['booking_id'] ?? 0);
            if ($linkedBookingId > 0) {
                $bchk = $conn->prepare('SELECT id FROM ars_bookings WHERE id = ? AND company_id = ? AND unit_id = ? LIMIT 1');
                $bchk->execute([$linkedBookingId, $arsCompanyId, $unitId]);
                if ($bchk->fetchColumn()) {
                    ars_booking_activity_log($conn, [
                        'company_id' => $arsCompanyId,
                        'booking_id' => $linkedBookingId,
                        'event_category' => 'maintenance',
                        'event_type' => 'maintenance_request_created',
                        'title' => 'Maintenance request created',
                        'description' => mb_substr($description, 0, 300),
                        'related_entity_type' => 're_maintenance_request',
                        'related_entity_id' => $requestId,
                        'status' => 'pending',
                        'source' => 'user',
                        'created_by' => $userId,
                        'dedupe_key' => 'maint:' . $requestId,
                    ]);
                }
            }

            echo json_encode([
                'success' => true,
                'request_id' => $requestId,
                'blocked_id' => $blockedId,
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
