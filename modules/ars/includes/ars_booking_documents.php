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
        'document_date' => substr((string)($booking['created_at'] ?? ''), 0, 10),
        'amount' => (float)($booking['total_amount'] ?? 0),
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
            'document_date' => substr((string)($p['payment_date'] ?? ''), 0, 10),
            'amount' => (float)$p['amount'],
        ];
    }

    // VAT and the headline figure come from what was actually billed, not
    // from ars_bookings -- an extension invoice can carry VAT the booking row
    // never sees, and on an extended stay its total_amount is short.
    $invoiceStatement = ars_booking_invoice_statement($conn, $booking);
    $vatAmount = (float)$invoiceStatement['vat'];
    $invoiceTotal = (float)$invoiceStatement['total'];
    $paid = (float)$invoiceStatement['paid'];
    $invoiceAvailable = $vatAmount > 0.009
        && !in_array($status, ['cancelled', 'expired'], true)
        && ($paid > 0.009 || in_array($status, ['confirmed', 'checked_in', 'checked_out', 'completed'], true));

    $catalog[] = [
        'doc_type' => 'tax_invoice',
        'title' => 'Tax invoice (VAT)',
        'subtitle' => $invoiceAvailable ? $currency . ' ' . number_format($invoiceTotal, 2) : null,
        'payment_id' => null,
        'available' => $invoiceAvailable,
        'unavailable_reason' => $invoiceAvailable ? null : 'VAT invoice is available once VAT applies and the booking is confirmed or paid.',
        'currency' => $currency,
        'document_date' => substr((string)($booking['created_at'] ?? ''), 0, 10),
        'amount' => $invoiceTotal,
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

/**
 * One invoice for the whole stay, however many times it was extended.
 *
 * ars_bookings.total_amount only ever holds the original stay plus non-room
 * charges: extension / service / adjustment invoices never write back to it.
 * A booking extended three times therefore printed "12 nights" priced at the
 * six-night figure. The posted financial documents are the only record that
 * knows what was actually billed, so the invoice is assembled from them --
 * one line per period, one total -- while the documents themselves are left
 * exactly as posted. Nothing here writes.
 *
 * Bookings with no financial documents (adapter off, or older records) keep
 * the single accommodation line built from ars_bookings, as before.
 *
 * @param array<string,mixed> $booking
 * @return array{lines: list<array{description: string, amount: float}>, subtotal: float, vat: float, vat_rate: float, total: float, paid: float, balance: float, from_documents: bool, fingerprint: string}
 */
function ars_booking_invoice_statement(PDO $conn, array $booking): array
{
    $bookingId = (int)($booking['id'] ?? 0);
    $companyId = (int)($booking['company_id'] ?? 0);
    $unitTitle = trim((string)($booking['listing_title'] ?? $booking['unit_number'] ?? 'Unit'));

    $fallback = static function () use ($booking, $unitTitle): array {
        $subtotal = (float)($booking['subtotal'] ?? 0);
        $discount = (float)($booking['discount_amount'] ?? 0) + (float)($booking['length_discount_amount'] ?? 0);
        $vat = (float)($booking['vat_amount'] ?? 0);
        $total = (float)($booking['total_amount'] ?? 0);
        $lines = [[
            'description' => 'Accommodation (' . (int)($booking['nights'] ?? 0) . ' nights)',
            'amount' => $subtotal,
        ]];
        if ($discount > 0.009) {
            $lines[] = ['description' => 'Discounts', 'amount' => -$discount];
        }
        return [
            'lines' => $lines,
            'subtotal' => round($subtotal - $discount, 2),
            'vat' => $vat,
            'vat_rate' => (float)($booking['vat_rate'] ?? 0),
            'total' => $total,
            'paid' => (float)($booking['paid_amount'] ?? 0),
            'balance' => (float)($booking['balance_due'] ?? 0),
            'from_documents' => false,
            'fingerprint' => 'booking:' . number_format($total, 2, '.', ''),
        ];
    };

    if ($bookingId <= 0 || $companyId <= 0) {
        return $fallback();
    }

    try {
        // Same exclusions as the booking screen's roll-up, so the printed
        // invoice and the Pricing Summary can never quote different figures.
        $stmt = $conn->prepare("
            SELECT d.id, d.document_type, d.document_number, d.document_date,
                   d.subtotal, d.vat_amount, d.total_amount, d.balance_due, d.vat_rate,
                   e.prior_check_out, e.new_check_out, e.added_nights
            FROM ars_financial_documents d
            LEFT JOIN ars_extension_documents e ON e.document_id = d.id
            WHERE d.booking_id = ? AND d.company_id = ?
              AND d.status NOT IN ('draft', 'voided', 'reversed')
            ORDER BY (d.document_type <> 'original_invoice'),
                     COALESCE(e.new_check_out, d.document_date), d.id
        ");
        $stmt->execute([$bookingId, $companyId]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $fallback();
    }

    if (!$docs) {
        return $fallback();
    }

    // The original invoice covers check-in up to wherever the first extension
    // begins -- not the booking's current check-out, which the extensions moved.
    $firstExtensionFrom = '';
    foreach ($docs as $d) {
        if ((string)$d['document_type'] === 'extension_invoice' && !empty($d['prior_check_out'])) {
            $extFrom = (string)$d['prior_check_out'];
            if ($firstExtensionFrom === '' || strtotime($extFrom) < strtotime($firstExtensionFrom)) {
                $firstExtensionFrom = $extFrom;
            }
        }
    }
    if ($firstExtensionFrom === '') {
        // An extension recorded on the Extend tab but never billed still moved
        // the check-out. Its dates are taken so the original line does not
        // claim the whole stay at the original price; its money is not, because
        // nothing was invoiced for it.
        try {
            $logStmt = $conn->prepare("
                SELECT MIN(extended_from) FROM ars_booking_extension_log
                WHERE booking_id = ? AND company_id = ?
            ");
            $logStmt->execute([$bookingId, $companyId]);
            $firstExtensionFrom = (string)($logStmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $firstExtensionFrom = '';
        }
    }

    $fmt = static function (string $date): string {
        $ts = strtotime($date);
        return $ts ? date('d M Y', $ts) : $date;
    };
    $nightsBetween = static function (string $from, string $to): int {
        $a = strtotime($from);
        $b = strtotime($to);
        return ($a && $b && $b > $a) ? (int)round(($b - $a) / 86400) : 0;
    };

    $lines = [];
    $invoiced = 0.0;
    $credited = 0.0;
    $vatTotal = 0.0;
    $openBalance = 0.0;
    $vatRate = (float)($booking['vat_rate'] ?? 0);
    $fingerprintBits = [];

    foreach ($docs as $d) {
        $type = (string)$d['document_type'];
        $net = (float)$d['subtotal'];
        $docVat = (float)$d['vat_amount'];
        $docTotal = (float)$d['total_amount'];
        $fingerprintBits[] = (int)$d['id'] . ':' . number_format($docTotal, 2, '.', '');

        if ($type === 'credit_note') {
            $credited += $docTotal;
            $openBalance -= (float)$d['balance_due'];
            $lines[] = [
                'description' => 'Credit note ' . (string)$d['document_number'],
                'amount' => -$net,
            ];
            $vatTotal -= $docVat;
            continue;
        }

        $invoiced += $docTotal;
        $openBalance += (float)$d['balance_due'];
        $vatTotal += $docVat;
        if ($docVat > 0.009 && (float)$d['vat_rate'] > 0) {
            $vatRate = (float)$d['vat_rate'];
        }

        if ($type === 'original_invoice') {
            $from = (string)($booking['check_in'] ?? '');
            $to = $firstExtensionFrom !== '' ? $firstExtensionFrom : (string)($booking['check_out'] ?? '');
            $nights = $nightsBetween($from, $to);
            $desc = 'Accommodation';
            if ($nights > 0) {
                $desc .= ' · ' . $fmt($from) . ' to ' . $fmt($to)
                    . ' (' . $nights . ' ' . ($nights === 1 ? 'night' : 'nights') . ')';
            }
            $lines[] = ['description' => $desc, 'amount' => $net];
            continue;
        }

        if ($type === 'extension_invoice') {
            $from = (string)($d['prior_check_out'] ?? '');
            $to = (string)($d['new_check_out'] ?? '');
            $nights = (int)($d['added_nights'] ?? 0) ?: $nightsBetween($from, $to);
            $desc = 'Extension of stay';
            if ($from !== '' && $to !== '') {
                $desc .= ' · ' . $fmt($from) . ' to ' . $fmt($to);
            }
            if ($nights > 0) {
                $desc .= ' (' . $nights . ' ' . ($nights === 1 ? 'night' : 'nights') . ')';
            }
            $lines[] = ['description' => $desc, 'amount' => $net];
            continue;
        }

        // Service / damage / adjustment: the document's own line text is what
        // the guest was told this charge is for.
        $desc = '';
        try {
            $ls = $conn->prepare("
                SELECT description FROM ars_financial_document_lines
                WHERE document_id = ? AND company_id = ? ORDER BY line_no LIMIT 1
            ");
            $ls->execute([(int)$d['id'], $companyId]);
            $desc = trim((string)$ls->fetchColumn());
        } catch (Throwable $e) {
            $desc = '';
        }
        if ($desc === '') {
            $desc = ucwords(str_replace('_', ' ', $type));
        }
        $lines[] = ['description' => $desc, 'amount' => $net];
    }

    $total = round($invoiced - $credited, 2);
    $balance = round($openBalance, 2);

    return [
        'lines' => $lines,
        'subtotal' => round($total - $vatTotal, 2),
        'vat' => round($vatTotal, 2),
        'vat_rate' => $vatRate,
        'total' => $total,
        // Derived from the same two figures the page prints, so the invoice
        // always adds up even when ars_bookings.paid_amount is stale.
        'paid' => round($total - $balance, 2),
        'balance' => $balance,
        'from_documents' => true,
        'fingerprint' => 'docs:' . implode(',', $fingerprintBits),
    ];
}
function ars_booking_document_download_path(int $bookingId, string $docType, ?int $paymentId = null): string {
    // Path only — payment_id is returned separately in the API payload.
    return 'stay/bookings/' . $bookingId . '/documents/' . $docType . '/download';
}

function ars_booking_document_content_hash(array $booking, string $docType, ?int $paymentId, ?string $statementFingerprint = null): string {
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
        // Extension / service invoices never touch ars_bookings, so without
        // this the cached invoice PDF would survive a newly billed extension.
        (string)($statementFingerprint ?? ''),
        'v3',
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
    ?array $payment = null,
    ?array $statement = null
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
        // One invoice for the booking, one row per period actually billed --
        // the original stay and every extension, in date order. Built by
        // ars_booking_invoice_statement() from the posted documents, because
        // ars_bookings.total_amount stops at the original stay.
        $st = $statement ?? ['lines' => [], 'subtotal' => 0.0, 'vat' => 0.0, 'vat_rate' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
        $vat = (float)$st['vat'];
        $itemRows = '';
        foreach ($st['lines'] as $line) {
            $amt = (float)$line['amount'];
            $itemRows .= '<tr><td>' . ars_booking_document_h((string)$line['description']) . '</td>'
                . '<td class="right">' . ($amt < 0 ? '-' : '') . number_format(abs($amt), 2) . '</td></tr>';
        }
        if ($itemRows === '') {
            $itemRows = '<tr><td>Accommodation</td><td class="right">' . number_format((float)$st['subtotal'], 2) . '</td></tr>';
        }
        // A no-VAT booking must not print a VAT row at all.
        $vatRow = $vat > 0.009
            ? '<tr><td>VAT ' . number_format((float)$st['vat_rate'], 1) . '%</td><td class="right">' . number_format($vat, 2) . '</td></tr>'
            : '';
        $totalLabel = $vat > 0.009 ? 'Total inclusive of VAT' : 'Total';
        $stayTo = (string)($booking['check_out'] ?? '');
        $bodyExtra = '
            <div class="panel">
                <table class="grid">
                    <tr><td class="label">Invoice to</td><td>' . ars_booking_document_h($guestName) . '<br><span class="muted">' . ars_booking_document_h((string)($booking['guest_email'] ?? '')) . '</span></td></tr>
                    <tr><td class="label">Booking</td><td>' . $bookingNumber . '</td></tr>
                    <tr><td class="label">Unit</td><td>' . $unitTitle . ($building !== '' ? ' · ' . $building : '') . '</td></tr>
                    <tr><td class="label">Stay</td><td>' . ars_booking_document_h((string)$booking['check_in'] . ' → ' . $stayTo) . '</td></tr>
                    <tr><td class="label">Nights</td><td>' . (int)($booking['nights'] ?? 0) . '</td></tr>
                </table>
            </div>
            <table class="items">
                <thead><tr><th>Description</th><th class="right">Amount (' . $currency . ')</th></tr></thead>
                <tbody>
                    ' . $itemRows . '
                    ' . $vatRow . '
                    <tr class="total"><td>' . $totalLabel . '</td><td class="right">' . number_format((float)$st['total'], 2) . '</td></tr>
                </tbody>
            </table>
            <p class="muted">Paid to date: ' . $currency . ' ' . number_format((float)$st['paid'], 2)
            . ' · Balance: ' . $currency . ' ' . number_format((float)$st['balance'], 2) . '</p>
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

    $statement = ars_booking_invoice_statement($conn, $booking);
    $hash = ars_booking_document_content_hash($booking, $docType, $paymentId, (string)$statement['fingerprint']);
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

    $html = ars_booking_document_build_html($booking, $company, $docType, $payment, $statement);
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
