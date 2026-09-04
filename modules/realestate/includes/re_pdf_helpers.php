<?php
/**
 * Shared PDF helpers for Real Estate invoices, receipts, and vendor statements.
 * Letterhead fields live in company_settings; show/hide toggles use settings keys
 * prefixed with realestate_pdf_{companyId}_.
 */

function re_pdf_company(PDO $conn, int $companyId): array
{
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
        'name' => ($row['legal_name'] ?? '') ?: ($row['company_name'] ?? 'Company'),
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
        'website' => $row['website'] ?? '',
        'logo_path' => $row['logo_path'] ?? '',
        'bank_name' => $row['bank_name'] ?? '',
        'bank_account_no' => $row['bank_account_no'] ?? '',
        'bank_iban' => $row['bank_iban'] ?? '',
        'bank_swift' => $row['bank_swift'] ?? '',
    ];
}

function re_pdf_document_settings(PDO $conn, int $companyId): array
{
    $defaults = [
        'show_logo' => '1',
        'show_trn' => '1',
        'show_phone' => '1',
        'show_email' => '1',
        'show_address' => '1',
        'show_bank_details' => '1',
    ];
    $prefix = 'realestate_pdf_' . $companyId . '_';
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
        error_log('re_pdf_document_settings error: ' . $e->getMessage());
    }
    return $defaults;
}

function re_pdf_save_document_settings(PDO $conn, int $companyId, array $settings): void
{
    $prefix = 'realestate_pdf_' . $companyId . '_';
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

function re_pdf_logo_data_uri(string $logoPath): string
{
    if ($logoPath === '') {
        return '';
    }
    $fullPath = preg_match('~^/~', $logoPath) ? $logoPath : dirname(__DIR__, 3) . '/' . ltrim($logoPath, '/');
    if (!is_file($fullPath)) {
        return '';
    }
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) ?: 'png';
    $mime = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ][$ext] ?? ('image/' . $ext);
    $data = file_get_contents($fullPath);
    return $data === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($data);
}

function re_pdf_number_words(int $num): string
{
    if ($num === 0) {
        return 'zero';
    }
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
            if ($hundreds) {
                $words .= $ones[$hundreds] . ' hundred';
            }
            if ($rest) {
                if ($words) {
                    $words .= ' ';
                }
                $words .= $rest < 20 ? $ones[$rest] : $tens[intdiv($rest, 10)] . (($rest % 10) ? '-' . $ones[$rest % 10] : '');
            }
            if ($scales[$scale]) {
                $words .= ' ' . $scales[$scale];
            }
            array_unshift($parts, $words);
        }
        $num = intdiv($num, 1000);
        $scale++;
    }
    return implode(' ', $parts);
}

function re_pdf_amount_words(float $amount, string $currency = 'AED'): string
{
    $whole = (int)floor($amount);
    $fils = (int)round(($amount - $whole) * 100);
    $text = ucfirst(re_pdf_number_words($whole)) . ' ' . $currency;
    if ($fils > 0) {
        $text .= ' and ' . re_pdf_number_words($fils) . ' fils';
    }
    return $text . ' only';
}

function re_pdf_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Shared letterhead block (left column) for RE PDF documents.
 */
function re_pdf_letterhead_html(array $company, array $docSettings, string $logoDataUri = ''): string
{
    $html = '';
    if ($logoDataUri !== '' && ($docSettings['show_logo'] ?? '1') === '1') {
        $html .= '<img class="logo" src="' . re_pdf_h($logoDataUri) . '" alt="Logo">';
    }
    $html .= '<div class="value">' . re_pdf_h($company['name']) . '</div>';
    if (!empty($company['trade_name']) && $company['trade_name'] !== $company['name']) {
        $html .= '<div class="muted">' . re_pdf_h($company['trade_name']) . '</div>';
    }
    if (!empty($company['address']) && ($docSettings['show_address'] ?? '1') === '1') {
        $html .= '<div class="muted">' . re_pdf_h($company['address']) . '</div>';
    }
    if (($docSettings['show_phone'] ?? '1') === '1' && !empty($company['phone'])) {
        $html .= '<div class="muted">Phone: ' . re_pdf_h($company['phone']) . '</div>';
    }
    if (($docSettings['show_email'] ?? '1') === '1' && !empty($company['email'])) {
        $html .= '<div class="muted">Email: ' . re_pdf_h($company['email']) . '</div>';
    }
    if (!empty($company['website'])) {
        $html .= '<div class="muted">' . re_pdf_h($company['website']) . '</div>';
    }
    if (!empty($company['trn']) && ($docSettings['show_trn'] ?? '1') === '1') {
        $html .= '<div class="muted">TRN: ' . re_pdf_h($company['trn']) . '</div>';
    }
    return $html;
}

function re_pdf_fmt_date(?string $date, string $format = 'd M Y'): string
{
    if ($date === null || $date === '' || $date === '0000-00-00') {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : $date;
}

function re_pdf_base_styles(): string
{
    return '
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #172033; background: #fff; }
        .doc { max-width: 900px; margin: 0 auto; }
        .top { display: table; width: 100%; margin-bottom: 18px; }
        .top-left, .top-right { display: table-cell; vertical-align: top; width: 50%; }
        .top-right { text-align: right; }
        .logo { max-height: 64px; max-width: 170px; margin-bottom: 8px; }
        .title { font-size: 22px; font-weight: 800; color: #0f3d73; letter-spacing: .5px; margin-bottom: 6px; }
        .muted { color: #667085; }
        .panel { border: 1px solid #d8dee8; border-radius: 10px; padding: 12px; margin-bottom: 14px; }
        .grid { display: table; width: 100%; table-layout: fixed; }
        .col { display: table-cell; vertical-align: top; padding-right: 12px; }
        .label { color: #667085; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 3px; }
        .value { font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th { background: #eef3fb; color: #172033; font-size: 11px; text-align: left; border: 1px solid #d8dee8; padding: 8px; }
        td { border: 1px solid #d8dee8; padding: 8px; vertical-align: top; }
        .right { text-align: right; }
        .total-row td { font-weight: 800; background: #f8fafc; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 11px; background: #e6f4ea; color: #137333; }
        .badge-muted { background: #eef2f6; color: #475467; }
        .section-title { font-size: 13px; font-weight: 700; margin: 16px 0 8px; color: #0f3d73; }
        .metrics { display: table; width: 100%; table-layout: fixed; margin-bottom: 12px; }
        .metric { display: table-cell; border: 1px solid #d8dee8; border-radius: 8px; padding: 10px; text-align: center; }
        .metric + .metric { border-left: 0; }
        .metric .label { margin-bottom: 4px; }
        .metric .value { font-size: 14px; }
        .text-success { color: #137333; }
        .text-danger { color: #b42318; }
        .footer { margin-top: 26px; color: #667085; font-size: 11px; text-align: center; }
        .no-print { margin: 16px auto; max-width: 900px; text-align: right; }
        .btn { display: inline-block; background: #0d6efd; color: #fff; padding: 8px 12px; border-radius: 6px; text-decoration: none; border: 0; cursor: pointer; }
        @media print { .no-print { display: none; } body { background: #fff; } }
    ';
}

/** Extra styles for vendor/customer statement PDFs (Zoho/Xero-style). */
function re_pdf_soa_styles(): string
{
    return re_pdf_document_styles();
}

/** Shared Zoho/Xero-style styles for invoices, receipts, and statements. */
function re_pdf_document_styles(): string
{
    return re_pdf_base_styles() . '
        .accent { height: 4px; background: #0f3d73; margin: 0 0 18px; }
        .doc-meta { width: 100%; border-collapse: collapse; }
        .doc-meta td { border: 0; padding: 2px 0 2px 12px; }
        .doc-meta .k { color: #667085; text-align: right; white-space: nowrap; }
        .doc-meta .v { font-weight: 700; text-align: right; }
        .soa-meta td { border: 0; padding: 2px 0 2px 12px; }
        .soa-meta .k { color: #667085; text-align: right; width: 42%; white-space: nowrap; }
        .soa-meta .v { font-weight: 700; text-align: right; }
        .summary-box { width: 100%; border-collapse: collapse; margin: 14px 0 18px; }
        .summary-box td { border: 1px solid #d8dee8; padding: 10px 12px; vertical-align: middle; width: 25%; }
        .summary-box .k { color: #667085; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }
        .summary-box .v { font-size: 15px; font-weight: 800; }
        .balance-due { background: #0f3d73; color: #fff; border-color: #0f3d73 !important; }
        .balance-due .k { color: rgba(255,255,255,.85); }
        .balance-due .v { color: #fff; font-size: 17px; }
        .balance-credit { background: #137333; color: #fff; border-color: #137333 !important; }
        .balance-credit .k { color: rgba(255,255,255,.85); }
        .balance-credit .v { color: #fff; font-size: 17px; }
        .party { border: 1px solid #e4e7ec; padding: 12px 14px; margin-bottom: 14px; }
        .party .label { margin-bottom: 6px; }
        .txn th { background: #0f3d73; color: #fff; border-color: #0f3d73; font-weight: 700; padding: 9px 8px; }
        .txn td { border-color: #e4e7ec; padding: 8px; font-size: 11px; }
        .txn tr.alt td { background: #f8fafc; }
        .txn tr.open td { background: #f0f4fa; font-weight: 700; }
        .txn tr.close td { background: #eef3fb; font-weight: 800; border-top: 2px solid #0f3d73; }
        .totals { width: 100%; border-collapse: collapse; }
        .totals td { border: 1px solid #e4e7ec; padding: 8px 10px; }
        .totals .label-cell { color: #475467; width: 55%; }
        .totals .total-row td { background: #0f3d73; color: #fff; font-weight: 800; border-color: #0f3d73; }
        .totals .due-row td { background: #f0f4fa; font-weight: 800; }
        .amount-box { border: 1px solid #0f3d73; background: #f5f8fc; padding: 14px 16px; margin: 14px 0; }
        .amount-box .label { color: #0f3d73; }
        .amount-box .amount { font-size: 22px; font-weight: 800; color: #0f3d73; margin: 4px 0 6px; }
        .aging { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .aging th { background: #f2f4f7; color: #475467; border: 1px solid #e4e7ec; text-align: center; font-size: 10px; padding: 6px; }
        .aging td { border: 1px solid #e4e7ec; text-align: center; padding: 8px 6px; font-weight: 700; }
        .note { color: #667085; font-size: 10px; margin-top: 6px; }
        .parties { margin-bottom: 8px; }
        .parties .col { width: 50%; }
        .bank { border: 1px solid #e4e7ec; padding: 10px 12px; margin-top: 12px; background: #fafbfc; }
        .badge-paid { background: #e6f4ea; color: #137333; }
        .badge-due { background: #fff4e5; color: #b54708; }
        .badge-sent { background: #eef4ff; color: #3538cd; }
    ';
}

function re_pdf_output(string $html, string $filename): void
{
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
        $projectTmpDir = dirname(__DIR__, 3) . '/uploads/realestate_pdf_temp';
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
            error_log('Real Estate PDF Dompdf error: ' . $e->getMessage());
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}
