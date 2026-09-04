<?php
/**
 * ARS Phase 1B — Activity Center AJAX (list + internal note).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_activity.php';
require_once __DIR__ . '/includes/ars_financial_lock.php';

header('Content-Type: application/json');
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();

$userId = current_user_id();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');
$bookingId = (int)($_POST['booking_id'] ?? $_GET['booking_id'] ?? 0);

if ($bookingId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing booking_id']);
    exit;
}

$stmt = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? AND company_id = ?');
$stmt->execute([$bookingId, $arsCompanyId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    echo json_encode(['success' => false, 'error' => 'Booking not found']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            $filter = (string)($_POST['filter'] ?? $_GET['filter'] ?? 'all');
            $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
            $perPage = (int)($_POST['per_page'] ?? $_GET['per_page'] ?? 25);
            $result = ars_booking_activities_fetch($conn, $arsCompanyId, $bookingId, $filter, $page, $perPage);
            echo json_encode(['success' => true] + $result);
            break;

        case 'add_internal_note':
            ars_require_booking_action($conn, 'update_operational_notes');
            $note = trim((string)($_POST['note'] ?? ''));
            if ($note === '') {
                echo json_encode(['success' => false, 'error' => 'Note is required.']);
                exit;
            }
            if (mb_strlen($note) > 4000) {
                echo json_encode(['success' => false, 'error' => 'Note is too long.']);
                exit;
            }

            $prev = (string)($booking['internal_notes'] ?? '');
            $combined = $prev === '' ? $note : (rtrim($prev) . "\n\n" . '[' . date('Y-m-d H:i') . '] ' . $note);
            ars_assert_financial_edit_allowed($conn, $booking, ['internal_notes' => $combined]);
            $conn->prepare('UPDATE ars_bookings SET internal_notes = ?, updated_at = NOW() WHERE id = ? AND company_id = ?')
                ->execute([$combined, $bookingId, $arsCompanyId]);

            ars_booking_activity_log($conn, [
                'company_id' => $arsCompanyId,
                'booking_id' => $bookingId,
                'booking_number' => $booking['booking_number'] ?? null,
                'event_category' => 'notes',
                'event_type' => 'internal_note_added',
                'title' => 'Internal note added',
                'description' => mb_substr($note, 0, 500),
                'previous_value' => $prev !== '' ? mb_substr($prev, 0, 200) : null,
                'new_value' => mb_substr($note, 0, 200),
                'related_entity_type' => 'ars_booking',
                'related_entity_id' => $bookingId,
                'source' => 'user',
                'created_by' => $userId,
            ]);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
