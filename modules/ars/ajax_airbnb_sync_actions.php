<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_airbnb_sync.php';

header('Content-Type: application/json');
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();
// Creating and cancelling bookings from here is the same authority as confirming one.
ars_require_booking_action($conn, 'confirm');

$action = $_POST['action'] ?? '';
$userId = (int)current_user_id();

if (!ars_airbnb_tables_ready($conn)) {
    echo json_encode(['success' => false, 'error' => 'Run migrations/ars_airbnb_email_sync.sql first.']);
    exit;
}

$loadEmail = static function () use ($conn, $arsCompanyId): array {
    $st = $conn->prepare('SELECT * FROM ars_channel_emails WHERE id = ? AND company_id = ?');
    $st->execute([(int)($_POST['email_id'] ?? 0), $arsCompanyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Email not found.');
    return $row;
};

try {
    switch ($action) {
        case 'sync_now':
            $r = ars_airbnb_run_sync($conn, $arsCompanyId);
            if (!empty($r['skipped_locked'])) {
                echo json_encode(['success' => true, 'message' => 'A sync is already running. Refresh in a minute.']);
            } elseif ($r['ok']) {
                echo json_encode(['success' => true, 'message' => "Sync done: {$r['fetched']} new email(s), {$r['created']} booking(s) created, {$r['cancelled']} cancelled, {$r['review']} need attention."]);
            } else {
                echo json_encode(['success' => false, 'error' => $r['error']]);
            }
            break;

        case 'create_on_unit':
            $email = $loadEmail();
            if ($email['email_type'] !== 'confirmed' || !in_array($email['status'], ['needs_review', 'new'], true)) {
                throw new RuntimeException('Only an open booking confirmation can be created.');
            }
            $unitId = (int)($_POST['unit_id'] ?? 0);
            if ($unitId <= 0) throw new RuntimeException('Pick a unit.');
            ars_assert_unit_usable_for_ars($conn, $unitId, $arsCompanyId);
            if (!empty($_POST['remember']) && $email['listing_id']) {
                ars_airbnb_touch_listing($conn, $arsCompanyId, $email['listing_id'], $email['listing_title']);
                $conn->prepare('UPDATE ars_channel_listings SET unit_id = ?, updated_by = ?, updated_at = ? WHERE company_id = ? AND channel = ? AND listing_id = ?')
                     ->execute([$unitId, $userId, ars_airbnb_now(), $arsCompanyId, ARS_AIRBNB_CHANNEL, $email['listing_id']]);
            }
            $r = ars_airbnb_process_email($conn, $arsCompanyId, $email, $userId, ['unit_id' => $unitId]);
            echo json_encode(['success' => $r['status'] === 'processed', 'error' => $r['status'] === 'processed' ? null : $r['note'], 'message' => $r['note']]);
            break;

        case 'link_booking':
            $email = $loadEmail();
            if ($email['email_type'] !== 'confirmed' || $email['status'] !== 'needs_review') {
                throw new RuntimeException('Only an open booking confirmation can be linked.');
            }
            $r = ars_airbnb_link_existing($conn, $arsCompanyId, $email, (string)($_POST['booking_number'] ?? ''), $userId);
            echo json_encode(['success' => true, 'message' => $r['note']]);
            break;

        case 'retry':
            $email = $loadEmail();
            if ($email['status'] !== 'needs_review') throw new RuntimeException('Only items that need attention can be retried.');
            $r = ars_airbnb_process_email($conn, $arsCompanyId, $email, $userId);
            echo json_encode(['success' => true, 'message' => $r['note'], 'status' => $r['status']]);
            break;

        case 'dismiss':
            $email = $loadEmail();
            if ($email['status'] !== 'needs_review') throw new RuntimeException('Only items that need attention can be dismissed.');
            $note = trim((string)($_POST['note'] ?? ''));
            ars_airbnb_mark($conn, (int)$email['id'], 'resolved', 'Handled by staff' . ($note !== '' ? ': ' . mb_substr($note, 0, 500) : '.') . ' (was: ' . $email['result_note'] . ')', null, $userId);
            echo json_encode(['success' => true]);
            break;

        case 'save_listing':
            $listingId = trim((string)($_POST['listing_id'] ?? ''));
            $unitId = (int)($_POST['unit_id'] ?? 0);
            if ($unitId > 0) ars_assert_unit_usable_for_ars($conn, $unitId, $arsCompanyId);
            $st = $conn->prepare('UPDATE ars_channel_listings SET unit_id = ?, updated_by = ?, updated_at = ? WHERE company_id = ? AND channel = ? AND listing_id = ?');
            $st->execute([$unitId ?: null, $userId, ars_airbnb_now(), $arsCompanyId, ARS_AIRBNB_CHANNEL, $listingId]);
            if ($st->rowCount() === 0) throw new RuntimeException('Listing not found.');
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
