<?php
/**
 * Unified Documents tab for an ARS booking.
 *
 * Everything a booking accumulates — system-generated PDFs, financial documents
 * from the adapter, deposit paperwork and staff uploads — is filed into four
 * default document types:
 *
 *   1. Short-Term Contract   2. Payment Receipts   3. Security Deposit   4. Other
 *
 * Nothing is written here. Existing bookings keep every document they already
 * had; uploads made before this tab existed simply sit under "Other" until
 * somebody re-files them.
 */

require_once __DIR__ . '/ars_booking_documents.php';
require_once __DIR__ . '/ars_booking_attachments.php';

/**
 * One row in the unified list. Every source is normalised into this shape so the
 * view renders a single table per category.
 *
 * @param array<string,mixed> $over
 * @return array<string,mixed>
 */
function ars_udoc_row(array $over = []): array {
    return array_merge([
        'kind' => 'generated',   // generated | financial | deposit | upload
        'title' => '',
        'subtitle' => '',
        'badge' => '',
        'badge_class' => 'bg-light text-dark border',
        'date' => '',
        'status' => '',
        'status_class' => '',
        'amount' => null,
        'currency' => 'AED',
        'view_url' => '',
        'download_url' => '',
        'available' => true,
        'unavailable_reason' => '',
        'doc_type' => '',
        'payment_id' => null,
        'attachment_id' => null,
        'can_send' => false,
    ], $over);
}

/**
 * Build the four document buckets for a booking.
 *
 * @param array<string,mixed>             $booking
 * @param list<array<string,mixed>>       $financialDocs rows from ars_report_financial_documents()
 * @return array<string,array{label:string,icon:string,hint:string,items:list<array<string,mixed>>}>
 */
function ars_booking_unified_documents(
    PDO $conn,
    array $booking,
    int $companyId,
    array $financialDocs = []
): array {
    $bookingId = (int)$booking['id'];
    $currency = 'AED';
    $buckets = [];
    foreach (ars_booking_doc_categories() as $key => $meta) {
        $buckets[$key] = $meta + ['items' => []];
    }

    // --- 1. System-generated PDFs (confirmation / receipts / tax invoice) -----
    $catalog = [];
    try {
        $catalog = ars_booking_documents_catalog($conn, $booking);
    } catch (Throwable $ignored) {
    }
    foreach ($catalog as $item) {
        $docType = (string)($item['doc_type'] ?? '');
        $currency = (string)($item['currency'] ?? $currency);
        // The booking confirmation is a different document from the short-term
        // contract, so it files under Other. "Short-Term Contract" holds the real
        // (uploaded) signed contract only.
        $bucket = match ($docType) {
            'payment_receipt', 'tax_invoice' => 'payment_receipt',
            default => 'other',
        };
        $qs = http_build_query(array_filter([
            'booking_id' => $bookingId,
            'doc_type' => $docType,
            'payment_id' => $item['payment_id'] ?? null,
        ], static fn($v) => $v !== null && $v !== ''));
        // Date and amount have their own columns; drop the catalog subtitle when
        // it only repeats them (payment receipts read "AED 100.00 · 2026-09-01").
        $subtitle = (string)($item['subtitle'] ?? '');
        if ($docType !== 'booking_confirmation' && str_contains($subtitle, ' · ')) {
            $subtitle = '';
        }
        $buckets[$bucket]['items'][] = ars_udoc_row([
            'kind' => 'generated',
            'title' => (string)($item['title'] ?? $docType),
            'subtitle' => $subtitle,
            'badge' => 'Generated',
            'badge_class' => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
            'available' => (bool)($item['available'] ?? false),
            'unavailable_reason' => (string)($item['unavailable_reason'] ?? ''),
            'status' => !empty($item['available']) ? 'ready' : 'not available',
            'status_class' => !empty($item['available']) ? 'bg-success' : 'bg-light text-muted border',
            'download_url' => 'document_download.php?' . $qs,
            'date' => (string)($item['document_date'] ?? ''),
            'amount' => isset($item['amount']) ? (float)$item['amount'] : null,
            'doc_type' => $docType,
            'payment_id' => $item['payment_id'] ?? null,
            'currency' => (string)($item['currency'] ?? $currency),
            'can_send' => (bool)($item['available'] ?? false),
        ]);
    }

    // --- 2. Financial documents (adapter invoices / credit notes) -------------
    foreach ($financialDocs as $fd) {
        $fdStatus = (string)($fd['status'] ?? '');
        $buckets['payment_receipt']['items'][] = ars_udoc_row([
            'kind' => 'financial',
            'title' => (string)($fd['document_number'] ?? ('#' . (int)($fd['id'] ?? 0))),
            'subtitle' => 'Balance ' . $currency . ' ' . number_format((float)($fd['balance_due'] ?? 0), 2),
            'badge' => ucwords(str_replace('_', ' ', (string)($fd['document_type'] ?? 'document'))),
            'date' => (string)($fd['document_date'] ?? ''),
            'status' => $fdStatus,
            'status_class' => match ($fdStatus) {
                'posted' => 'bg-success',
                'paid' => 'bg-primary',
                'partially_paid' => 'bg-info',
                'reversed' => 'bg-danger',
                default => 'bg-secondary',
            },
            'amount' => (float)($fd['total_amount'] ?? 0),
            'currency' => $currency,
            'view_url' => 'financial_document_view.php?id=' . (int)($fd['id'] ?? 0),
        ]);
    }

    // --- 3. Security deposit paper trail -------------------------------------
    $depAmount = (float)($booking['deposit_amount'] ?? 0);
    $depStatus = (string)($booking['deposit_status'] ?? 'none');
    $refunded = (float)($booking['deposit_refunded_amount'] ?? 0);
    $forfeited = (float)($booking['deposit_forfeited_amount'] ?? 0);

    if ($depAmount > 0.009) {
        $received = in_array($depStatus, ['received', 'partially_refunded', 'refunded', 'forfeited'], true);
        $buckets['security_deposit']['items'][] = ars_udoc_row([
            'kind' => 'deposit',
            'title' => $received ? 'Deposit received' : 'Deposit due from guest',
            'subtitle' => trim((string)($booking['deposit_payment_method'] ?? '')) !== ''
                ? 'Method: ' . ucfirst(str_replace('_', ' ', (string)$booking['deposit_payment_method']))
                : 'Refundable hold — not stay revenue',
            'badge' => 'Deposit',
            'badge_class' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
            'date' => (string)($booking['deposit_received_date'] ?? ''),
            'status' => $received ? 'received' : 'pending',
            'status_class' => $received ? 'bg-success' : 'bg-warning text-dark',
            'amount' => $depAmount,
            'currency' => $currency,
        ]);
    }
    if ($refunded > 0.009) {
        $buckets['security_deposit']['items'][] = ars_udoc_row([
            'kind' => 'deposit',
            'title' => 'Deposit refunded to guest',
            'subtitle' => 'Refund settlement',
            'badge' => 'Deposit',
            'badge_class' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
            'date' => (string)($booking['deposit_refunded_date'] ?? ''),
            'status' => 'refunded',
            'status_class' => 'bg-secondary',
            'amount' => $refunded,
            'currency' => $currency,
        ]);
    }
    if ($forfeited > 0.009) {
        $buckets['security_deposit']['items'][] = ars_udoc_row([
            'kind' => 'deposit',
            'title' => 'Deposit deductions / forfeited',
            'subtitle' => 'Deducted against damages or unpaid charges',
            'badge' => 'Deposit',
            'badge_class' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
            'date' => (string)($booking['deposit_refunded_date'] ?? ''),
            'status' => 'deducted',
            'status_class' => 'bg-warning text-dark',
            'amount' => $forfeited,
            'currency' => $currency,
        ]);
    }

    // --- 4. Staff uploads, filed by their category ---------------------------
    $attachments = [];
    try {
        $attachments = ars_booking_attachments_list($conn, $companyId, $bookingId);
    } catch (Throwable $ignored) {
    }
    foreach ($attachments as $a) {
        $cat = ars_booking_doc_category_normalize($a['doc_category'] ?? 'other');
        $size = (int)($a['file_size'] ?? 0);
        $buckets[$cat]['items'][] = ars_udoc_row([
            'kind' => 'upload',
            'title' => (string)($a['original_name'] ?? 'file'),
            'subtitle' => trim((!empty($a['payment_id']) ? 'Evidence for payment #' . (int)$a['payment_id'] . ' · ' : '')
                . ($size > 0 ? ars_udoc_filesize($size) : ''), ' ·'),
            'badge' => 'Uploaded',
            'badge_class' => 'bg-secondary-subtle text-secondary-emphasis border',
            'date' => substr((string)($a['created_at'] ?? ''), 0, 10),
            'download_url' => 'document_download.php?booking_id=' . $bookingId . '&attachment_id=' . (int)$a['id'],
            'attachment_id' => (int)$a['id'],
        ]);
    }

    return $buckets;
}

function ars_udoc_filesize(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Flatten the buckets into a single list for the flat Documents table.
 * Each row carries its category so the "Type" column can show it.
 *
 * Ordered by category (contract → receipts → deposit → other), newest first
 * within each; the view splits it into one section per category.
 *
 * @param array<string,array{label:string,icon:string,items:list<array<string,mixed>>}> $buckets
 * @return list<array<string,mixed>>
 */
function ars_booking_unified_documents_flat(array $buckets): array {
    $order = array_keys(ars_booking_doc_categories());
    $rows = [];
    foreach ($buckets as $key => $bucket) {
        foreach ($bucket['items'] as $item) {
            $item['category'] = $key;
            $item['category_label'] = $bucket['label'];
            $item['category_icon'] = $bucket['icon'];
            $rows[] = $item;
        }
    }
    usort($rows, static function (array $a, array $b) use ($order): int {
        $ca = array_search($a['category'], $order, true);
        $cb = array_search($b['category'], $order, true);
        if ($ca !== $cb) {
            return (int)$ca <=> (int)$cb;
        }
        return strcmp((string)$b['date'], (string)$a['date']);
    });
    return $rows;
}

/** Total rows across every bucket. */
function ars_udoc_total(array $buckets): int {
    $n = 0;
    foreach ($buckets as $b) {
        $n += count($b['items']);
    }
    return $n;
}
