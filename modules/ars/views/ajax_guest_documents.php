<?php
/**
 * Guest profile documents — list / upload / re-file / delete.
 * Company-scoped through arsPageAuth(); the guest is re-checked on every call.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_activity.php';
require_once __DIR__ . '/includes/ars_guest_attachments.php';

header('Content-Type: application/json');
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();

$userId = current_user_id();
$action = (string)($_POST['action'] ?? '');
$guestId = (int)($_POST['guest_id'] ?? 0);

if ($guestId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing guest_id']);
    exit;
}

$stmt = $conn->prepare('SELECT id, first_name, last_name FROM ars_guests WHERE id = ? AND company_id = ? LIMIT 1');
$stmt->execute([$guestId, $arsCompanyId]);
$guest = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$guest) {
    echo json_encode(['success' => false, 'error' => 'Guest not found']);
    exit;
}
$guestName = trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''));

$logGuestDoc = static function (string $summary) use ($conn, $arsCompanyId, $guestId, $userId): void {
    try {
        ars_activity_log_guest_change($conn, $arsCompanyId, $guestId, $summary, $userId);
    } catch (Throwable $ignored) {
    }
};

try {
    switch ($action) {

        case 'list_guest_documents': {
            $rows = ars_guest_attachments_list($conn, $arsCompanyId, $guestId);
            foreach ($rows as &$r) {
                $r['download_url'] = 'guest_document_view.php?guest_id=' . $guestId . '&attachment_id=' . (int)$r['id'] . '&disposition=attachment';
                $r['view_url'] = 'guest_document_view.php?guest_id=' . $guestId . '&attachment_id=' . (int)$r['id'];
                $r['category_label'] = ars_guest_doc_category_label($r['doc_category'] ?? 'other');
            }
            unset($r);
            echo json_encode(['success' => true, 'documents' => $rows]);
            break;
        }

        case 'upload_guest_document': {
            if (empty($_FILES['file'])) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
                exit;
            }
            $docCategory = ars_guest_doc_category_normalize((string)($_POST['doc_category'] ?? 'other'));
            $up = ars_guest_attachment_upload(
                $conn,
                $arsCompanyId,
                $guestId,
                $_FILES['file'],
                $userId,
                $docCategory,
                isset($_POST['doc_number']) ? (string)$_POST['doc_number'] : null,
                isset($_POST['expiry_date']) ? (string)$_POST['expiry_date'] : null
            );
            if (!$up['success']) {
                echo json_encode(['success' => false, 'error' => $up['error']]);
                exit;
            }
            $logGuestDoc(ars_guest_doc_category_label($docCategory) . ' uploaded to the guest profile: '
                . (string)($up['attachment']['original_name'] ?? ''));
            echo json_encode(['success' => true, 'attachment' => $up['attachment']]);
            break;
        }

        case 'set_guest_document_category': {
            $attachmentId = (int)($_POST['attachment_id'] ?? 0);
            if ($attachmentId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Missing document.']);
                exit;
            }
            $docCategory = ars_guest_doc_category_normalize((string)($_POST['doc_category'] ?? 'other'));
            $res = ars_guest_attachment_set_category($conn, $arsCompanyId, $guestId, $attachmentId, $docCategory);
            if (!$res['success']) {
                echo json_encode(['success' => false, 'error' => $res['error']]);
                exit;
            }
            $logGuestDoc('Guest document re-filed under ' . ars_guest_doc_category_label($docCategory) . '.');
            echo json_encode(['success' => true, 'doc_category' => $res['doc_category']]);
            break;
        }

        case 'delete_guest_document': {
            $attachmentId = (int)($_POST['attachment_id'] ?? 0);
            if ($attachmentId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Missing document.']);
                exit;
            }
            $res = ars_guest_attachment_delete($conn, $arsCompanyId, $guestId, $attachmentId);
            if (!$res['success']) {
                echo json_encode(['success' => false, 'error' => $res['error']]);
                exit;
            }
            $logGuestDoc('Guest document deleted: ' . ars_guest_doc_category_label($res['doc_category'])
                . ' — ' . (string)$res['original_name']);
            echo json_encode(['success' => true]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    error_log('[ARS guest documents] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
