<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_availability.php';

header('Content-Type: application/json');

require_once __DIR__ . '/includes/ars_permissions.php';
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();
$action = $_POST['action'] ?? '';
$userId = function_exists('current_user_id') ? current_user_id() : null;
ars_early_checkout_ensure_schema($conn);
$occEnd = ars_booking_occupancy_end_sql();

try {
    switch ($action) {

        case 'add':
            $unitId    = (int)($_POST['unit_id'] ?? 0);
            $startDate = $_POST['start_date'] ?? '';
            $endDate   = $_POST['end_date'] ?? '';
            $reason    = trim($_POST['reason'] ?? '');

            if (!$unitId || !$startDate || !$endDate) {
                echo json_encode(['success' => false, 'error' => 'Unit, start date, and end date are required.']);
                exit;
            }
            if ($endDate < $startDate) {
                echo json_encode(['success' => false, 'error' => 'End date must be on or after start date.']);
                exit;
            }

            // Check for conflicting bookings (company-scoped; early checkout frees from actual departure)
            $stmt = $conn->prepare("
                SELECT booking_number FROM ars_bookings
                WHERE unit_id = ? AND company_id = ? AND status NOT IN ('cancelled','expired')
                  AND check_in < ? AND {$occEnd} > ?
                LIMIT 3
            ");
            $stmt->execute([$unitId, $arsCompanyId, $endDate, $startDate]);
            $conflicts = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($conflicts) {
                echo json_encode(['success' => false, 'error' => 'Cannot block: overlapping bookings exist — ' . implode(', ', $conflicts)]);
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO ars_blocked_dates (unit_id, company_id, start_date, end_date, reason, block_type, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, 'manual', ?, NOW())
            ");
            $stmt->execute([$unitId, $arsCompanyId, $startDate, $endDate, $reason ?: null, $userId]);
            echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
            break;

        case 'edit':
            $id        = (int)($_POST['id'] ?? 0);
            $startDate = $_POST['start_date'] ?? '';
            $endDate   = $_POST['end_date'] ?? '';
            $reason    = trim($_POST['reason'] ?? '');

            if (!$id || !$startDate || !$endDate) {
                echo json_encode(['success' => false, 'error' => 'Missing required fields.']);
                exit;
            }
            if ($endDate < $startDate) {
                echo json_encode(['success' => false, 'error' => 'End date must be on or after start date.']);
                exit;
            }

            // Get unit_id for conflict check
            $stmt = $conn->prepare("SELECT unit_id FROM ars_blocked_dates WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $arsCompanyId]);
            $unitId = (int)$stmt->fetchColumn();
            if (!$unitId) {
                echo json_encode(['success' => false, 'error' => 'Blocked date not found.']);
                exit;
            }

            $stmt = $conn->prepare("
                SELECT booking_number FROM ars_bookings
                WHERE unit_id = ? AND status NOT IN ('cancelled','expired')
                  AND check_in < ? AND {$occEnd} > ?
                LIMIT 3
            ");
            $stmt->execute([$unitId, $endDate, $startDate]);
            $conflicts = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($conflicts) {
                echo json_encode(['success' => false, 'error' => 'Cannot block: overlapping bookings — ' . implode(', ', $conflicts)]);
                exit;
            }

            $conn->prepare("
                UPDATE ars_blocked_dates SET start_date = ?, end_date = ?, reason = ? WHERE id = ? AND company_id = ?
            ")->execute([$startDate, $endDate, $reason ?: null, $id, $arsCompanyId]);
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Invalid ID.']);
                exit;
            }
            $conn->prepare("DELETE FROM ars_blocked_dates WHERE id = ? AND company_id = ?")->execute([$id, $arsCompanyId]);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
