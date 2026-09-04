<?php
/**
 * ARS guest booking documents — PDF generation, cache, secure download.
 */

declare(strict_types=1);

function ars_booking_documents_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_booking_documents (
            id INT(11) NOT NULL AUTO_INCREMENT,
            company_id INT(11) NOT NULL,
            booking_id INT(11) NOT NULL,
            guest_id INT(11) NOT NULL,
            doc_type ENUM('booking_confirmation','payment_receipt','tax_invoice') NOT NULL,
            payment_id INT(11) DEFAULT NULL,
            file_token VARCHAR(64) NOT NULL,
            content_hash VARCHAR(64) NOT NULL,
            file_size INT(11) NOT NULL DEFAULT 0,
            mime_type VARCHAR(80) NOT NULL DEFAULT 'application/pdf',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ars_doc_token (file_token),
            UNIQUE KEY uq_ars_doc_booking_type_payment (booking_id, doc_type, payment_id),
            KEY idx_ars_doc_guest (guest_id, booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $base = ars_booking_documents_storage_root();
    if (!is_dir($base)) {
        @mkdir($base, 0777, true);
    }
    $tmp = ars_booking_pdf_temp_dir();
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0777, true);
    }
}

/**
 * Writable temp dir for Dompdf (font subsetting, cache). Apache on XAMPP often cannot write to sys_get_temp_dir().
 */
function ars_booking_pdf_temp_dir(): string {
    $base = dirname(__DIR__, 3);
    $preferred = $base . '/uploads/ars_documents_temp';
    if (!is_dir($preferred)) {
        @mkdir($preferred, 0777, true);
    }
    if (is_dir($preferred) && is_writable($preferred)) {
        $real = realpath($preferred);
        return $real !== false ? $real : $preferred;
    }
    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/\\') . '/herosysgro_pdf_engine';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0777, true);
    }
    if (is_dir($fallback) && is_writable($fallback)) {
        $real = realpath($fallback);
        return $real !== false ? $real : $fallback;
    }
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/\\');
}

function ars_booking_documents_storage_root(): string {
    return dirname(__DIR__, 3) . '/uploads/ars_booking_documents';
}

/**
 * @return array<string,mixed>|null
 */
function ars_booking_document_load_context(PDO $conn, int $bookingId, int $guestId): ?array {
    $stmt = $conn->prepare("
        SELECT bk.*,
               g.first_name AS guest_first_name, g.last_name AS guest_last_name,
               g.email AS guest_email, g.phone AS guest_phone,
               u.unit_number, u.listing_title, u.unit_type,
               b.name AS building_name, b.address AS building_address
        FROM ars_bookings bk
        INNER JOIN ars_guests g ON g.id = bk.guest_id
        LEFT JOIN re_units u ON u.id = bk.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE bk.id = ? AND bk.guest_id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId, $guestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ars_booking_payment_is_receiptable(array $payment): bool {
    if ((string)($payment['payment_type'] ?? '') === 'security_deposit') {
        return false;
    }
    $amount = (float)($payment['amount'] ?? 0);
    if ($amount <= 0) {
        return false;
    }
    $gateway = strtolower((string)($payment['payment_gateway'] ?? ''));
    if ($gateway === 'stripe') {
        $st = strtolower((string)($payment['gateway_status'] ?? ''));
        return in_array($st, ['succeeded', 'partially_refunded', 'refunded'], true);
    }
    return true;
}

/**
 * @param array<string,mixed> $booking
 * @return list<array<string,mixed>>
 */
function ars_booking_documents_catalog(PDO $conn, array $booking): array {
    ars_booking_documents_ensure_schema($conn);
    $bookingId = (int)$booking['id'];
    $currency = (string)(getArsSettings($conn, (int)$booking['company_id'])['currency'] ?? 'AED');
    $status = (string)($booking['status'] ?? '');

    $confirmationAvailable = !in_array($status, ['cancelled', 'expired'], true);
    $catalog = [];

    $catalog[] = [
        'doc_type' => 'booking_confirmation',
        'title' => 'Booking confirmation',
        'subtitle' => (string)($booking['booking_number'] ?? ''),
        'payment_id' => null,
        'available' => $confirmationAvailable,
        'unavailable_reason' => $confirmationAvailable ? null : 'Not available for cancelled or expired bookings.',
        'currency' => $currency,
    ];

    $payStmt = $conn->prepare("
        SELECT id, amount, payment_method, payment_date, reference_number, payment_gateway,
               gateway_payment_intent_id, gateway_status, payment_type, currency, created_at
        FROM ars_booking_payments
        WHERE booking_id = ?
        ORDER BY payment_date DESC, id DESC
    ");
    $payStmt->execute([$bookingId]);
    foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (!ars_booking_payment_is_receiptable($p)) {
            continue;
        }
        $pid = (int)$p['id'];
        $amt = number_format((float)$p['amount'], 2);
        $ccy = (string)($p['currency'] ?: $currency);
        $catalog[] = [
            'doc_type' => 'payment_receipt',
            'title' => 'Payment receipt',
            'subtitle' => $ccy . ' ' . $amt . ' · ' . (string)($p['payment_date'] ?? ''),
            'payment_id' => $pid,
            'available' => true,
            'unavailable_reason' => null,
            'currency' => $ccy,
        ];
    }

    $vatAmount = (float)($booking['vat_amount'] ?? 0);
    $paid = (float)($booking['paid_amount'] ?? 0);
    $invoiceAvailable = $vatAmount > 0.009
        && !in_array($status, ['cancelled', 'expired'], true)
        && ($paid > 0.009 || in_array($status, ['confirmed', 'checked_in', 'checked_out', 'completed'], true));

    $catalog[] = [
        'doc_type' => 'tax_invoice',
        'title' => 'Tax invoice (VAT)',
        'subtitle' => $invoiceAvailable ? $currency . ' ' . number_format((float)$booking['total_amount'], 2) : null,
        'payment_id' => null,
        'available' => $invoiceAvailable,
        'unavailable_reason' => $invoiceAvailable ? null : 'VAT invoice is available once VAT applies and the booking is confirmed or paid.',
        'currency' => $currency,
    ];

    foreach ($catalog as &$item) {
        $item['download_path'] = ars_booking_document_download_path(
            $bookingId,
            (string)$item['doc_type'],
            $item['payment_id'] !== null ? (int)$item['payment_id'] : null
        );
    }
    unset($item);

    return $catalog;
}

function ars_booking_document_download_path(int $bookingId, string $docType, ?int $paymentId = null): string {
    // Path only — payment_id is returned separately in the API payload.
    return 'stay/bookings/' . $bookingId . '/documents/' . $docType . '/download';
}

function ars_booking_document_content_hash(array $booking, string $docType, ?int $paymentId): string {
    $guestKey = trim((string)($booking['guest_first_name'] ?? '') . '|' . (string)($booking['guest_last_name'] ?? '')
        . '|' . (string)($booking['guest_email'] ?? '') . '|' . (string)($booking['unit_number'] ?? ''));
    $brandKey = (string)($booking['_doc_brand_stamp'] ?? '');
    return hash('sha256', implode('|', [
        (string)($booking['updated_at'] ?? ''),
        (string)($booking['total_amount'] ?? ''),
        (string)($booking['paid_amount'] ?? ''),
        (string)($booking['status'] ?? ''),
        $guestKey,
        $brandKey,
        $docType,
        (string)($paymentId ?? ''),
        'v2',
    ]));
}

/**
 * Staff + guest PDF paths often pass a bare ars_bookings row — join guest/unit for display fields.
 *
 * @param array<string,mixed> $booking
 * @return array<string,mixed>
 */
function ars_booking_document_enrich_booking(PDO $conn, array $booking): array {
    $bookingId = (int)($booking['id'] ?? 0);
    if ($bookingId <= 0) {
        return $booking;
    }
    $stmt = $conn->prepare("
        SELECT bk.*,
               g.first_name AS guest_first_name, g.last_name AS guest_last_name,
               g.email AS guest_email, g.phone AS guest_phone,
               u.unit_number, u.listing_title, u.unit_type,
               b.name AS building_name, b.address AS building_address
        FROM ars_bookings bk
        LEFT JOIN ars_guests g ON g.id = bk.guest_id
        LEFT JOIN re_units u ON u.id = bk.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE bk.id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $booking;
    }
    // Preserve any already-enriched keys from caller, prefer SQL join values for guest/unit.
    return array_merge($booking, $row);
}

function ars_booking_document_company(PDO $conn, int $companyId): array {
    $ars = function_exists('getArsSettings') ? getArsSettings($conn, $companyId) : [];
    $currency = (string)($ars['currency'] ?? 'AED');

    $brandName = trim((string)($ars['doc_brand_name'] ?? ''));
    $brandAddress = trim((string)($ars['doc_brand_address'] ?? ''));
    $brandEmail = trim((string)($ars['doc_brand_email'] ?? ''));
    $brandPhone = trim((string)($ars['doc_brand_phone'] ?? ''));
    $brandTrn = trim((string)($ars['doc_brand_trn'] ?? ''));
    $brandLogo = trim((string)($ars['doc_brand_logo_path'] ?? ''));
    $primary = trim((string)($ars['doc_brand_primary_color'] ?? ''));
    $accent = trim((string)($ars['doc_brand_accent_color'] ?? ''));

    $stmt = $conn->prepare("
        SELECT c.name AS company_name, cs.*
        FROM companies c
        LEFT JOIN company_settings cs ON cs.company_id = c.id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $fallbackAddress = implode(', ', array_filter([
        $row['address_line1'] ?? '',
        $row['address_line2'] ?? '',
        $row['city'] ?? '',
        $row['country'] ?? '',
    ]));

    return [
        'name' => $brandName !== ''
            ? $brandName
            : (string)(($row['legal_name'] ?? '') ?: ($row['company_name'] ?? 'ARS Rentals')),
        'trn' => $brandTrn !== '' ? $brandTrn : (string)($row['trn'] ?? ''),
        'currency' => $currency !== '' ? $currency : (string)($row['currency_code'] ?? 'AED'),
        'address' => $brandAddress !== '' ? $brandAddress : $fallbackAddress,
        'phone' => $brandPhone !== '' ? $brandPhone : (string)($row['phone'] ?? ''),
        'email' => $brandEmail !== '' ? $brandEmail : (string)($row['email'] ?? ''),
        'logo_path' => $brandLogo !== '' ? $brandLogo : (string)($row['logo_path'] ?? ''),
        'primary_color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $primary) ? $primary : '#0f4c75',
        'accent_color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) ? $accent : '#eef3fb',
        'brand_stamp' => md5(implode('|', [
            $brandName, $brandAddress, $brandEmail, $brandPhone, $brandTrn, $brandLogo, $primary, $accent,
        ])),
    ];
}

function ars_booking_document_logo_data_uri(string $logoPath): string {
    if ($logoPath === '') {
        return '';
    }
    $full = preg_match('~^/~', $logoPath)
        ? $logoPath
        : dirname(__DIR__, 3) . '/' . ltrim($logoPath, '/');
    if (!is_file($full)) {
        return '';
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION)) ?: 'png';
    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        default => 'image/png',
    };
    $data = file_get_contents($full);
    return $data === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($data);
}

function ars_booking_document_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string,mixed> $booking
 * @param array<string,mixed> $company
 * @param array<string,mixed>|null $payment
 */
function ars_booking_document_build_html(
    array $booking,
    array $company,
    string $docType,
    ?array $payment = null
): string {
    $guestName = trim((string)($booking['guest_first_name'] ?? '') . ' ' . (string)($booking['guest_last_name'] ?? ''));
    if ($guestName === '') {
        $guestName = trim((string)($booking['first_name'] ?? '') . ' ' . (string)($booking['last_name'] ?? ''));
    }
    $currency = (string)($company['currency'] ?? 'AED');
    $logo = ars_booking_document_logo_data_uri((string)($company['logo_path'] ?? ''));
    $logoHtml = $logo !== '' ? '<img src="' . $logo . '" class="logo" alt="">' : '';
    $primary = (string)($company['primary_color'] ?? '#0f4c75');
    $accent = (string)($company['accent_color'] ?? '#eef3fb');

    $styles = '
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #172033; margin: 0; }
        .doc { padding: 28px; }
        .head { border-bottom: 2px solid ' . $primary . '; padding-bottom: 14px; margin-bottom: 18px; }
        .head table { width: 100%; }
        .title { font-size: 20px; font-weight: 800; color: ' . $primary . '; margin: 0 0 4px; }
        .muted { color: #667085; font-size: 10px; }
        .panel { border: 1px solid #d8dee8; border-radius: 8px; padding: 12px; margin-bottom: 14px; }
        .grid td { padding: 5px 8px 5px 0; vertical-align: top; }
        .label { color: #667085; font-size: 9px; text-transform: uppercase; width: 120px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th { background: ' . $accent . '; text-align: left; padding: 7px; border: 1px solid #d8dee8; font-size: 10px; }
        table.items td { padding: 7px; border: 1px solid #d8dee8; }
        .right { text-align: right; }
        .total td { font-weight: 800; background: #f8fafc; }
        .footer { margin-top: 24px; font-size: 9px; color: #667085; text-align: center; }
        .logo { max-height: 52px; max-width: 140px; }
        .brand-contact { margin-top: 4px; font-size: 10px; color: #667085; }
    ';

    $docTitle = match ($docType) {
        'payment_receipt' => 'PAYMENT RECEIPT',
        'tax_invoice' => 'TAX INVOICE',
        default => 'BOOKING CONFIRMATION',
    };

    $bookingNumber = ars_booking_document_h((string)($booking['booking_number'] ?? ''));
    $unitTitle = ars_booking_document_h((string)($booking['listing_title'] ?? $booking['unit_number'] ?? 'Unit'));
    $building = ars_booking_document_h((string)($booking['building_name'] ?? ''));
        $bodyExtra = '';

    if ($docType === 'booking_confirmation') {
        $bodyExtra = '
            <div class="panel">
                <table class="grid">
                    <tr><td class="label">Booking ref</td><td><strong>' . $bookingNumber . '</strong></td></tr>
                    <tr><td class="label">Guest</td><td>' . ars_booking_document_h($guestName) . '</td></tr>
                    <tr><td class="label">Unit</td><td>' . $unitTitle . ($building !== '' ? ' · ' . $building : '') . '</td></tr>
                    <tr><td class="label">Check-in</td><td>' . ars_booking_document_h((string)$booking['check_in']) . '</td></tr>
                    <tr><td class="label">Check-out</td><td>' . ars_booking_document_h((string)$booking['check_out']) . '</td></tr>
                    <tr><td class="label">Nights</td><td>' . (int)$booking['nights'] . '</td></tr>
                    <tr><td class="label">Guests</td><td>' . (int)$booking['num_guests'] . '</td></tr>
                    <tr><td class="label">Status</td><td>' . ars_booking_document_h(ucfirst((string)$booking['status'])) . '</td></tr>
                    <tr><td class="label">Total stay</td><td><strong>' . $currency . ' ' . number_format((float)$booking['total_amount'], 2) . '</strong></td></tr>
                </table>
            </div>
            <p class="muted">This document confirms your reservation. Security deposit (if any) is shown separately on your stay details.</p>
        ';
    }

    if ($docType === 'payment_receipt' && $payment) {
        $ref = (string)($payment['reference_number'] ?: $payment['gateway_payment_intent_id'] ?: ('PMT-' . $payment['id']));
        $bodyExtra = '
            <div class="panel">
                <table class="grid">
                    <tr><td class="label">Receipt for</td><td>Booking ' . $bookingNumber . '</td></tr>
                    <tr><td class="label">Received from</td><td>' . ars_booking_document_h($guestName) . '</td></tr>
                    <tr><td class="label">Amount</td><td><strong>' . $currency . ' ' . number_format((float)$payment['amount'], 2) . '</strong></td></tr>
                    <tr><td class="label">Payment date</td><td>' . ars_booking_document_h((string)$payment['payment_date']) . '</td></tr>
                    <tr><td class="label">Method</td><td>' . ars_booking_document_h(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))) . '</td></tr>
                    <tr><td class="label">Reference</td><td>' . ars_booking_document_h($ref) . '</td></tr>
                </table>
            </div>
            <p class="muted">Thank you for your payment. This receipt is proof of payment received.</p>
        ';
    }

    if ($docType === 'tax_invoice') {
        $subtotal = (float)($booking['subtotal'] ?? 0);
        $disc = (float)($booking['discount_amount'] ?? 0) + (float)($booking['length_discount_amount'] ?? 0);
        $vat = (float)($booking['vat_amount'] ?? 0);
        $total = (float)($booking['total_amount'] ?? 0);
        $bodyExtra = '
            <div class="panel">
                <table class="grid">
                    <tr><td class="label">Invoice to</td><td>' . ars_booking_document_h($guestName) . '<br><span class="muted">' . ars_booking_document_h((string)($booking['guest_email'] ?? '')) . '</span></td></tr>
                    <tr><td class="label">Booking</td><td>' . $bookingNumber . '</td></tr>
                    <tr><td class="label">Stay</td><td>' . ars_booking_document_h((string)$booking['check_in'] . ' → ' . (string)$booking['check_out']) . '</td></tr>
                </table>
            </div>
            <table class="items">
                <thead><tr><th>Description</th><th class="right">Amount (' . $currency . ')</th></tr></thead>
                <tbody>
                    <tr><td>Accommodation (' . (int)$booking['nights'] . ' nights) — ' . $unitTitle . '</td><td class="right">' . number_format($subtotal, 2) . '</td></tr>
                    ' . ($disc > 0 ? '<tr><td>Discounts</td><td class="right">-' . number_format($disc, 2) . '</td></tr>' : '') . '
                    <tr><td>VAT ' . number_format((float)($booking['vat_rate'] ?? 0), 1) . '%</td><td class="right">' . number_format($vat, 2) . '</td></tr>
                    <tr class="total"><td>Total inclusive of VAT</td><td class="right">' . number_format($total, 2) . '</td></tr>
                </tbody>
            </table>
            <p class="muted">Paid to date: ' . $currency . ' ' . number_format((float)($booking['paid_amount'] ?? 0), 2)
            . ' · Balance: ' . $currency . ' ' . number_format((float)($booking['balance_due'] ?? 0), 2) . '</p>
        ';
    }

$companyBlock = ars_booking_document_h((string)$company['name']);
    if (($company['trn'] ?? '') !== '') {
        $companyBlock .= '<br><span class="muted">TRN: ' . ars_booking_document_h((string)$company['trn']) . '</span>';
    }
    if (($company['address'] ?? '') !== '') {
        $companyBlock .= '<br><span class="muted">' . ars_booking_document_h((string)$company['address']) . '</span>';
    }
    $contactBits = array_filter([
        ($company['phone'] ?? '') !== '' ? 'Tel: ' . (string)$company['phone'] : '',
        ($company['email'] ?? '') !== '' ? (string)$company['email'] : '',
    ]);
    if ($contactBits) {
        $companyBlock .= '<div class="brand-contact">' . ars_booking_document_h(implode(' · ', $contactBits)) . '</div>';
    }

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $styles . '</style></head><body>
        <div class="doc">
            <div class="head">
                <table><tr>
                    <td>' . $logoHtml . '</td>
                    <td class="right">
                        <p class="title">' . $docTitle . '</p>
                        <span class="muted">Generated ' . date('Y-m-d H:i') . ' UTC</span>
                    </td>
                </tr></table>
            </div>
            <div class="panel"><strong>' . $companyBlock . '</strong></div>
            ' . $bodyExtra . '
            <div class="footer">Computer-generated document · ' . ars_booking_document_h((string)$company['name']) . '</div>
        </div>
    </body></html>';
}

function ars_booking_pdf_render(string $html): string {
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        throw new RuntimeException('PDF library not available.');
    }
    require_once $autoload;
    if (!class_exists('\Dompdf\Dompdf')) {
        throw new RuntimeException('Dompdf is not installed.');
    }
    $tmpDir = ars_booking_pdf_temp_dir();
    if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
        throw new RuntimeException(
            'PDF temp directory is not writable. Create uploads/ars_documents_temp with write permission for the web server.'
        );
    }
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('isFontSubsettingEnabled', false);
    $options->set('tempDir', $tmpDir);
    $options->set('fontCache', $tmpDir);
    // Keep bundled DejaVu fonts in vendor/dompdf — do not point fontDir at the empty temp folder.
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $out = $dompdf->output();
    if ($out === false || $out === '') {
        throw new RuntimeException('PDF generation failed.');
    }
    return $out;
}

function ars_booking_document_storage_path(int $companyId, int $bookingId, string $token): string {
    $dir = ars_booking_documents_storage_root() . '/' . $companyId . '/' . $bookingId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/' . $token . '.pdf';
}

/**
 * @param array<string,mixed> $booking
 * @return array{bytes: string, filename: string, mime: string}
 */
function ars_booking_document_get_pdf(
    PDO $conn,
    array $booking,
    string $docType,
    ?int $paymentId = null
): array {
    ars_booking_documents_ensure_schema($conn);
    $booking = ars_booking_document_enrich_booking($conn, $booking);
    $company = ars_booking_document_company($conn, (int)$booking['company_id']);
    $booking['_doc_brand_stamp'] = (string)($company['brand_stamp'] ?? '');
    $bookingId = (int)$booking['id'];
    $guestId = (int)$booking['guest_id'];
    $companyId = (int)$booking['company_id'];

    if ($docType === 'payment_receipt') {
        if ($paymentId === null || $paymentId <= 0) {
            throw new InvalidArgumentException('payment_id is required for payment receipts.');
        }
        $pStmt = $conn->prepare('SELECT * FROM ars_booking_payments WHERE id = ? AND booking_id = ? LIMIT 1');
        $pStmt->execute([$paymentId, $bookingId]);
        $payment = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || !ars_booking_payment_is_receiptable($payment)) {
            throw new RuntimeException('Payment receipt is not available.');
        }
    } else {
        $payment = null;
        $catalog = ars_booking_documents_catalog($conn, $booking);
        $ok = false;
        foreach ($catalog as $item) {
            if ($item['doc_type'] === $docType && $item['available']) {
                if ($docType !== 'payment_receipt' || (int)($item['payment_id'] ?? 0) === (int)$paymentId) {
                    $ok = true;
                    break;
                }
            }
        }
        if (!$ok) {
            throw new RuntimeException('This document is not available for this booking.');
        }
    }

    $hash = ars_booking_document_content_hash($booking, $docType, $paymentId);
    $lookup = $conn->prepare('
        SELECT * FROM ars_booking_documents
        WHERE booking_id = ? AND doc_type = ? AND (payment_id <=> ?)
        LIMIT 1
    ');
    $lookup->execute([$bookingId, $docType, $paymentId]);
    $row = $lookup->fetch(PDO::FETCH_ASSOC);

    if ($row && (string)$row['content_hash'] === $hash) {
        $path = ars_booking_document_storage_path($companyId, $bookingId, (string)$row['file_token']);
        if (is_file($path)) {
            $bytes = file_get_contents($path);
            if ($bytes !== false && $bytes !== '') {
                return [
                    'bytes' => $bytes,
                    'filename' => ars_booking_document_filename($booking, $docType, $paymentId),
                    'mime' => 'application/pdf',
                ];
            }
        }
    }

    $html = ars_booking_document_build_html($booking, $company, $docType, $payment);
    $bytes = ars_booking_pdf_render($html);
    $token = bin2hex(random_bytes(16));
    $path = ars_booking_document_storage_path($companyId, $bookingId, $token);
    if (@file_put_contents($path, $bytes) === false) {
        error_log('ars_booking_document: could not cache PDF at ' . $path);
    }

    if ($row) {
        $conn->prepare('
            UPDATE ars_booking_documents SET file_token = ?, content_hash = ?, file_size = ?, updated_at = NOW()
            WHERE id = ?
        ')->execute([$token, $hash, strlen($bytes), (int)$row['id']]);
        $oldPath = ars_booking_document_storage_path($companyId, $bookingId, (string)$row['file_token']);
        if ($oldPath !== $path && is_file($oldPath)) {
            @unlink($oldPath);
        }
    } else {
        $conn->prepare('
            INSERT INTO ars_booking_documents
                (company_id, booking_id, guest_id, doc_type, payment_id, file_token, content_hash, file_size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $companyId, $bookingId, $guestId, $docType, $paymentId, $token, $hash, strlen($bytes),
        ]);
    }

    return [
        'bytes' => $bytes,
        'filename' => ars_booking_document_filename($booking, $docType, $paymentId),
        'mime' => 'application/pdf',
    ];
}

function ars_booking_document_filename(array $booking, string $docType, ?int $paymentId): string {
    $num = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($booking['booking_number'] ?? 'booking'));
    $suffix = match ($docType) {
        'payment_receipt' => 'receipt-' . ($paymentId ?? 'payment'),
        'tax_invoice' => 'tax-invoice',
        default => 'confirmation',
    };
    return $num . '-' . $suffix . '.pdf';
}

function ars_booking_document_stream_download(
    PDO $conn,
    int $bookingId,
    int $guestId,
    string $docType,
    ?int $paymentId = null
): void {
    $booking = ars_booking_document_load_context($conn, $bookingId, $guestId);
    if (!$booking) {
        customer_api_send_error('not_found', 'Booking not found', 404);
    }
    $allowed = ['booking_confirmation', 'payment_receipt', 'tax_invoice'];
    if (!in_array($docType, $allowed, true)) {
        customer_api_send_error('invalid_request', 'Invalid document type', 400);
    }
    try {
        $file = ars_booking_document_get_pdf($conn, $booking, $docType, $paymentId);
    } catch (Throwable $e) {
        customer_api_send_error('document_error', $e->getMessage(), 422);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['filename']) . '"');
    header('Content-Length: ' . strlen($file['bytes']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $file['bytes'];
    exit;
}
