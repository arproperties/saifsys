<?php
/**
 * ARS move forms (PDF): GET booking_id, kind = in (move-in inspection form) |
 * out (move-out inspection report) | settlement (deposit settlement statement),
 * photos=1, remarks=1, download=1 (otherwise shown inline for preview).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_move_process.php';

$arsCompanyId = arsPageAuth($conn);
$booking = amp_booking($conn, $arsCompanyId, (int)($_GET['booking_id'] ?? 0));
$kind = in_array($_GET['kind'] ?? '', ['in', 'out', 'settlement'], true) ? $_GET['kind'] : 'in';
if (!$booking) {
    http_response_code(404);
    exit('Booking not found.');
}
try {
    [$pdf, $name] = amp_pdf($conn, $arsCompanyId, $booking, $kind, [
        'photos' => ($_GET['photos'] ?? '0') === '1',
        'remarks' => ($_GET['remarks'] ?? '0') === '1',
    ]);
} catch (Throwable $e) {
    error_log('ARS move form ' . $kind . ' (booking ' . $booking['id'] . '): ' . $e->getMessage());
    http_response_code(500);
    exit('The form could not be generated: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
