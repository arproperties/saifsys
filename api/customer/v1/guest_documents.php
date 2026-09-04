<?php
/**
 * Guest booking documents API handlers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../modules/ars/includes/ars_booking_documents.php';

function customer_api_guest_handle_booking_documents_list(PDO $conn, int $guestId, int $bookingId): void {
    ars_booking_documents_ensure_schema($conn);
    $booking = ars_booking_document_load_context($conn, $bookingId, $guestId);
    if (!$booking) {
        customer_api_send_error('not_found', 'Booking not found', 404);
    }
    $catalog = ars_booking_documents_catalog($conn, $booking);
    $documents = [];
    foreach ($catalog as $item) {
        $documents[] = [
            'doc_type' => (string)$item['doc_type'],
            'title' => (string)$item['title'],
            'subtitle' => $item['subtitle'] !== null ? (string)$item['subtitle'] : null,
            'payment_id' => $item['payment_id'] !== null ? (int)$item['payment_id'] : null,
            'available' => (bool)$item['available'],
            'unavailable_reason' => $item['unavailable_reason'] !== null ? (string)$item['unavailable_reason'] : null,
            'currency' => (string)$item['currency'],
            'download_path' => (string)$item['download_path'],
        ];
    }
    customer_api_send_ok([
        'booking_id' => $bookingId,
        'booking_number' => (string)($booking['booking_number'] ?? ''),
        'documents' => $documents,
    ]);
}

function customer_api_guest_handle_booking_document_download(
    PDO $conn,
    int $guestId,
    int $bookingId,
    string $docType
): void {
    $paymentId = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : null;
    if ($paymentId !== null && $paymentId <= 0) {
        $paymentId = null;
    }
    ars_booking_document_stream_download($conn, $bookingId, $guestId, $docType, $paymentId);
}
