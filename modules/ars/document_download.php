<?php
/**
 * Staff PDF download for ARS booking documents (tax invoice / receipt / confirmation).
 * Auth + company scoping. Does not use the guest customer API.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_booking_documents.php';

$arsCompanyId = arsPageAuth($conn);
$bookingId = (int)($_GET['booking_id'] ?? 0);
$docType = (string)($_GET['doc_type'] ?? '');
$paymentId = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : null;
$attachmentId = (int)($_GET['attachment_id'] ?? 0);

if ($bookingId <= 0) {
    http_response_code(400);
    echo 'Missing booking_id';
    exit;
}

$stmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? AND company_id = ? LIMIT 1");
$stmt->execute([$bookingId, $arsCompanyId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    http_response_code(404);
    echo 'Booking not found';
    exit;
}

// Staff attachment download
if ($attachmentId > 0) {
    require_once __DIR__ . '/includes/ars_booking_attachments.php';
    $res = ars_booking_attachment_resolve($conn, $arsCompanyId, $bookingId, $attachmentId);
    if (!$res['success']) {
        http_response_code(404);
        echo h($res['error'] ?? 'Not found');
        exit;
    }
    header('Content-Type: ' . $res['mime']);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string)$res['filename']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string)filesize((string)$res['path']));
    readfile((string)$res['path']);
    exit;
}

$allowed = ['booking_confirmation', 'payment_receipt', 'tax_invoice'];
if (!in_array($docType, $allowed, true)) {
    http_response_code(400);
    echo 'Invalid doc_type';
    exit;
}

try {
    $file = ars_booking_document_get_pdf($conn, $booking, $docType, $paymentId);
} catch (Throwable $e) {
    http_response_code(422);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['filename']) . '"');
header('Content-Length: ' . strlen($file['bytes']));
header('Cache-Control: private, max-age=600');
header('X-Content-Type-Options: nosniff');
echo $file['bytes'];
exit;
