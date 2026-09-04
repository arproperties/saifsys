<?php
/**
 * Shared PDF helpers for construction invoices and receipts.
 */

require_once __DIR__ . '/construction_income_helpers.php';

function co_pdf_company(PDO $conn, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT cs.*, c.name AS company_name
        FROM companies c
        LEFT JOIN company_settings cs ON cs.company_id = c.id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'name' => ($row['legal_name'] ?? '') ?: ($row['company_name'] ?? 'Construction Company'),
        'trade_name' => $row['trade_name'] ?? '',
        'trn' => $row['trn'] ?? '',
        'currency' => $row['currency_code'] ?? 'AED',
        'address' => implode(', ', array_filter([
            $row['address_line1'] ?? '',
            $row['address_line2'] ?? '',
            $row['city'] ?? '',
            $row['state_region'] ?? '',
            $row['country'] ?? '',
        ])),
        'phone' => $row['phone'] ?? '',
        'email' => $row['email'] ?? '',
        'logo_path' => $row['logo_path'] ?? '',
        'bank_name' => $row['bank_name'] ?? '',
        'bank_account_no' => $row['bank_account_no'] ?? '',
        'bank_iban' => $row['bank_iban'] ?? '',
    ];
}

function co_pdf_document_settings(PDO $conn, int $companyId): array {
    $defaults = [
        'show_logo' => '1',
        'show_trn' => '1',
        'show_phone' => '1',
        'show_email' => '1',
        'show_address' => '1',
        'show_bank_details' => '1',
    ];
    $prefix = 'construction_pdf_' . $companyId . '_';
    try {
        $stmt = $conn->prepare("SELECT `key`, `value` FROM settings WHERE `key` LIKE ?");
        $stmt->execute([$prefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = substr($row['key'], strlen($prefix));
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = (string)$row['value'];
            }
        }
    } catch (Throwable $e) {
        error_log('co_pdf_document_settings error: ' . $e->getMessage());
    }
    return $defaults;
}

function co_pdf_save_document_settings(PDO $conn, int $companyId, array $settings): void {
    $prefix = 'construction_pdf_' . $companyId . '_';
    $allowed = ['show_logo', 'show_trn', 'show_phone', 'show_email', 'show_address', 'show_bank_details'];
    $stmt = $conn->prepare("
        INSERT INTO settings (`key`, `value`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    foreach ($allowed as $key) {
        $stmt->execute([$prefix . $key, !empty($settings[$key]) ? '1' : '0']);
    }
}

function co_pdf_logo_data_uri(string $logoPath): string {
    if ($logoPath === '') {
        return '';
    }
    $fullPath = preg_match('~^/~', $logoPath) ? $logoPath : dirname(__DIR__, 3) . '/' . ltrim($logoPath, '/');
    if (!is_file($fullPath)) {
        return '';
    }
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) ?: 'png';
    $data = file_get_contents($fullPath);
    return $data === false ? '' : 'data:image/' . $ext . ';base64,' . base64_encode($data);
}

function co_pdf_number_words(int $num): string {
    if ($num === 0) return 'zero';
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $scales = ['', 'thousand', 'million', 'billion'];
    $parts = [];
    $scale = 0;
    while ($num > 0) {
        $chunk = $num % 1000;
        if ($chunk) {
            $words = '';
            $hundreds = intdiv($chunk, 100);
            $rest = $chunk % 100;
            if ($hundreds) $words .= $ones[$hundreds] . ' hundred';
            if ($rest) {
                if ($words) $words .= ' ';
                $words .= $rest < 20 ? $ones[$rest] : $tens[intdiv($rest, 10)] . (($rest % 10) ? '-' . $ones[$rest % 10] : '');
            }
            if ($scales[$scale]) $words .= ' ' . $scales[$scale];
            array_unshift($parts, $words);
        }
        $num = intdiv($num, 1000);
        $scale++;
    }
    return implode(' ', $parts);
}

function co_pdf_amount_words(float $amount, string $currency = 'AED'): string {
    $whole = (int)floor($amount);
    $fils = (int)round(($amount - $whole) * 100);
    $text = ucfirst(co_pdf_number_words($whole)) . ' ' . $currency;
    if ($fils > 0) {
        $text .= ' and ' . co_pdf_number_words($fils) . ' fils';
    }
    return $text . ' only';
}

function co_pdf_base_styles(): string {
    return '
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #172033; }
        .doc { max-width: 900px; margin: 0 auto; }
        .top { display: table; width: 100%; margin-bottom: 18px; }
        .top-left, .top-right { display: table-cell; vertical-align: top; width: 50%; }
        .top-right { text-align: right; }
        .logo { max-height: 64px; max-width: 170px; margin-bottom: 8px; }
        .title { font-size: 24px; font-weight: 800; color: #0f3d73; letter-spacing: .5px; margin-bottom: 6px; }
        .muted { color: #667085; }
        .panel { border: 1px solid #d8dee8; border-radius: 10px; padding: 12px; margin-bottom: 14px; }
        .grid { display: table; width: 100%; table-layout: fixed; }
        .col { display: table-cell; vertical-align: top; padding-right: 12px; }
        .label { color: #667085; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 3px; }
        .value { font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #eef3fb; color: #172033; font-size: 11px; text-align: left; border: 1px solid #d8dee8; padding: 8px; }
        td { border: 1px solid #d8dee8; padding: 8px; vertical-align: top; }
        .right { text-align: right; }
        .total-row td { font-weight: 800; background: #f8fafc; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 11px; background: #e6f4ea; color: #137333; }
        .footer { margin-top: 26px; color: #667085; font-size: 11px; text-align: center; }
        .no-print { margin: 16px auto; max-width: 900px; text-align: right; }
        .btn { display: inline-block; background: #0d6efd; color: #fff; padding: 8px 12px; border-radius: 6px; text-decoration: none; border: 0; }
        @media print { .no-print { display: none; } body { background: #fff; } }
    ';
}

function co_pdf_output(string $html, string $filename): void {
    if (($_GET['html'] ?? '') === '1') {
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    if (class_exists('\Dompdf\Dompdf')) {
        $projectTmpDir = dirname(__DIR__, 3) . '/uploads/construction_pdf_temp';
        if (!is_dir($projectTmpDir)) {
            @mkdir($projectTmpDir, 0777, true);
        }
        $tmpDir = (is_dir($projectTmpDir) && is_writable($projectTmpDir))
            ? $projectTmpDir
            : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'dejavusans');
        $options->set('tempDir', $tmpDir);
        $options->set('fontDir', $tmpDir);
        $options->set('fontCache', $tmpDir);

        try {
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $dompdf->stream($filename, ['Attachment' => false]);
            exit;
        } catch (Throwable $e) {
            error_log('Construction PDF Dompdf error: ' . $e->getMessage());
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}
