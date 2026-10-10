<?php
/**
 * ARS move-in / move-out inspection actions (checklist ticks, notes, photos, complete).
 * The check-in / check-out itself stays in ajax_booking_actions.php.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_activity.php';
require_once __DIR__ . '/includes/ars_move_inspection.php';

header('Content-Type: application/json');
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();

$userId = current_user_id();
$action = $_POST['action'] ?? '';
$bookingId = (int)($_POST['booking_id'] ?? 0);
$type = ars_move_inspection_type($_POST['type'] ?? 'in');

$stmt = $conn->prepare("SELECT id, booking_number, status FROM ars_bookings WHERE id = ? AND company_id = ?");
$stmt->execute([$bookingId, $arsCompanyId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    echo json_encode(['success' => false, 'error' => 'Booking not found']);
    exit;
}
$inspection = ars_move_inspection_find($conn, $arsCompanyId, $bookingId, $type);
if (!$inspection) {
    echo json_encode(['success' => false, 'error' => 'Open the inspection page first.']);
    exit;
}
$inspectionId = (int)$inspection['id'];
$typeLabel = ars_move_inspection_type_label($type);

try {
    switch ($action) {
        case 'save_item':
            $res = ars_move_inspection_save_item(
                $conn,
                $inspection,
                (int)($_POST['item_id'] ?? 0),
                ($_POST['is_completed'] ?? '0') === '1',
                (string)($_POST['remarks'] ?? ''),
                $userId
            );
            echo json_encode($res + ['progress' => ars_move_inspection_progress($conn, $inspectionId)]);
            break;

        case 'save_notes':
            if ($inspection['status'] === 'completed') {
                echo json_encode(['success' => false, 'error' => 'This inspection is already completed.']);
                break;
            }
            $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 5000);
            $conn->prepare("UPDATE ars_move_inspections SET notes = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$notes !== '' ? $notes : null, $inspectionId]);
            echo json_encode(['success' => true]);
            break;

        case 'upload_photo':
            $files = $_FILES['photos'] ?? null;
            $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
            if ($count === 0) {
                echo json_encode(['success' => false, 'error' => 'Choose at least one photo.']);
                break;
            }
            $saved = 0;
            $errors = [];
            for ($i = 0; $i < $count; $i++) {
                $res = ars_move_inspection_photo_upload($conn, $inspection, [
                    'name' => $files['name'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ], (string)($_POST['caption'] ?? ''), $userId);
                if ($res['success']) {
                    $saved++;
                } else {
                    $errors[] = $files['name'][$i] . ': ' . $res['error'];
                }
            }
            // Success only when a file was really saved.
            echo json_encode([
                'success' => $saved > 0,
                'saved' => $saved,
                'error' => $errors ? implode(' ', $errors) : null,
            ]);
            break;

        case 'delete_photo':
            echo json_encode(ars_move_inspection_photo_delete($conn, $inspection, (int)($_POST['photo_id'] ?? 0)));
            break;

        case 'complete':
            $already = $inspection['status'] === 'completed';
            $res = ars_move_inspection_complete($conn, $inspection, $userId);
            if ($res['success'] && !$already) {
                $progress = ars_move_inspection_progress($conn, $inspectionId);
                ars_booking_activity_log($conn, [
                    'company_id' => $arsCompanyId,
                    'booking_id' => $bookingId,
                    'booking_number' => $booking['booking_number'] ?? null,
                    'event_category' => 'operational',
                    'event_type' => $type === 'out' ? 'move_out_inspection' : 'move_in_inspection',
                    'title' => $typeLabel . ' inspection completed',
                    'description' => $progress['done'] . ' of ' . $progress['total'] . ' checklist items ticked.',
                    'related_entity_type' => 'ars_move_inspection',
                    'related_entity_id' => $inspectionId,
                    'created_by' => $userId,
                ]);
            }
            echo json_encode($res);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('ARS move inspection ' . $action . ': ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not save. Please try again.']);
}
