<?php
require_once __DIR__ . '/lease_vat_calculator.php';

if (!class_exists('RenewalNoticePDFGenerator')) {
    class RenewalNoticePDFGenerator {
        private PDO $conn;
        private int $companyId;
        private string $basePath;

        private const MASTER_TEMPLATE = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tenancy Renewal Notice</title>
  <style>
    /* Top/bottom @page margins = space for fixed header/footer on every page (Dompdf) */
    @page { margin: 96px 34px 90px 34px; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: #111; margin: 12px; padding: 0; line-height: 1.3; font-size: 12px; background: #fff; }
    .pdf-header { position: fixed; top: -88px; left: 0; right: 0; height: 93px; text-align: center; z-index: 1000; overflow: hidden; }
    .pdf-header img { width: 100%; max-height: 93px; height: auto; object-fit: contain; display: block; margin: 0 auto; }
    .pdf-footer { position: fixed; bottom: -74px; left: 0; right: 0; height: 68px; text-align: center; z-index: 1000; overflow: hidden; }
    .pdf-footer img { width: 100%; max-height: 68px; height: auto; object-fit: contain; display: block; margin: 0 auto; }
    .page { width: 100%; page-break-after: always; }
    .page:last-child { page-break-after: avoid; }
    .terms-page ul.bullet-list li { margin-bottom: 5px; line-height: 1.25; }
    .doc-date { font-size: 15px; font-weight: 700; text-decoration: underline; margin: 4px 0 12px; }
    .doc-title { text-align: center; font-size: 17px; font-weight: 700; text-decoration: underline; text-transform: uppercase; margin: 0 0 14px; }
    .tenant-line { font-size: 13px; margin-bottom: 12px; }
    p { margin: 0 0 10px; font-size: 12px; }
    .section-label { font-size: 14px; font-weight: 700; text-decoration: underline; text-transform: uppercase; margin: 16px 0 8px; }
    .expiry-row { font-size: 12px; margin: 10px 0 14px; }
    .expiry-row strong { font-weight: 700; }
    table.notice-table { width: 100%; border-collapse: collapse; margin: 6px 0 14px; table-layout: fixed; }
    table.notice-table td { border: 2px solid #222; padding: 7px 9px; font-size: 11px; vertical-align: middle; }
    table.notice-table td.label { width: 45%; font-weight: 700; }
    table.notice-table td.value { width: 55%; text-align: center; }
    .renewal-confirmation { margin-top: 10px; font-size: 12px; }
    ul.bullet-list { margin: 0; padding-left: 18px; }
    ul.bullet-list li { margin-bottom: 6px; font-size: 11px; }
    .closing { margin-top: 10px; font-size: 11px; }
    .documents-title { font-size: 13px; font-weight: 700; text-decoration: underline; margin: 12px 0 6px; }
    .documents-list { margin: 0 0 20px; padding-left: 0; list-style: none; font-size: 11px; }
    .documents-list li { margin-bottom: 2px; }
    .sign-stamp-table { width: 100%; margin-top: 12px; border-collapse: collapse; }
    .sign-stamp-table td { vertical-align: bottom; padding: 0; font-size: 11px; }
    .sign-stamp-table td.sig { width: 58%; font-weight: 700; }
    .sign-stamp-table td.stamp-cell { width: 55%; text-align: left; }
    .sign-stamp-table td.stamp-cell img { max-width: 190px; max-height: 190px; width: auto; height: auto; object-fit: contain; display: inline-block; }
    .nowrap { white-space: nowrap; }
  </style>
</head>
<body>
  {{#if header_image_data}}<div class="pdf-header"><img src="{{header_image_data}}" alt=""></div>{{/if}}
  {{#if footer_image_data}}<div class="pdf-footer"><img src="{{footer_image_data}}" alt=""></div>{{/if}}

  <div class="page">
    <div class="doc-date">{{notice_date}}</div>
    <div class="doc-title">Tenancy Renewal Notice</div>
    <div class="tenant-line">
      Mr / Mrs. {{tenant_name}}, {{unit_code}}, {{building_name}}, {{community_name}}, {{location_name}}.
    </div>
    <p>We would like to take this opportunity to thank you for being one of our valued tenants.
      We truly appreciate your cooperation and trust in maintaining a positive relationship with us.</p>
    <p>Please be advised that the tenancy agreement for the premises at
      <strong>Unit {{unit_code}}, {{building_name}}, {{community_name}}, {{location_name}}</strong>,
      rented by you, is due to expire shortly. Kindly find below the key details for your review:</p>
    <div class="expiry-row">Date of Expiry: <strong>{{current_lease_expiry_date}}</strong></div>
    <div class="section-label">Annual Lease:</div>

    <table class="notice-table">
      <tr><td class="label">Date of Expiry</td><td class="value">{{current_lease_expiry_date}}</td></tr>
      <tr>
        <td class="label">New Period:</td>
        <td class="value">From: <span class="nowrap">{{new_period_start}}</span>&nbsp;&nbsp;&nbsp;To: <span class="nowrap">{{new_period_end}}</span></td>
      </tr>
      <tr><td class="label">New Annual Rent:</td><td class="value">{{new_annual_rent_display}} AED</td></tr>
      {{#if show_chiller_row}}<tr><td class="label">Chiller Charges</td><td class="value">{{chiller_charges_display}} AED</td></tr>{{/if}}
      <tr><td class="label">{{admin_fee_label}}</td><td class="value">{{admin_fee_display}} AED</td></tr>
      {{#if show_additional_parking_row}}<tr><td class="label">Additional Parking Slot</td><td class="value">{{additional_parking_fee_display}} AED</td></tr>{{/if}}
      {{#if show_small_store_row}}<tr><td class="label">Small Storeroom</td><td class="value">{{small_store_fee_display}} AED</td></tr>{{/if}}
      {{#if show_big_store_row}}<tr><td class="label">Big Storeroom</td><td class="value">{{big_store_fee_display}} AED</td></tr>{{/if}}
      {{#if show_rent_vat_row}}<tr><td class="label">VAT on rent ({{vat_rate_label}})</td><td class="value">{{rent_vat_display}} AED</td></tr>{{/if}}
      <tr><td class="label">VAT on additional charges ({{vat_rate_label}})</td><td class="value">{{vat_extra_charges_display}} AED</td></tr>
      <tr><td class="label">Total VAT</td><td class="value"><strong>{{vat_total_display}} AED</strong></td></tr>
      <tr><td class="label">RERA Charges</td><td class="value">{{rera_charges_display}} AED</td></tr>
      <tr><td class="label">Payment Terms</td><td class="value">{{number_of_cheques}} Cheques</td></tr>
      {{#if show_grand_total_row}}<tr><td class="label">Total Annual Payable</td><td class="value"><strong>{{grand_total_display}} AED</strong></td></tr>{{/if}}
    </table>
    <p class="renewal-confirmation">Kindly oblige by confirming your intention to renew the tenancy within one month of
      the receipt of this notice.</p>
  </div>

  <div class="page terms-page">
    <ul class="bullet-list">
      {{#each terms}}<li>{{this}}</li>{{/each}}
    </ul>
    <p class="closing">We look forward to continuing serving you as a valued customer</p>
    <p class="closing">Kindly respond to this email with your inquiries.</p>
    <div class="documents-title">Documents required for renewal:</div>
    <ul class="documents-list">
      {{#each required_documents}}<li>- {{this}}</li>{{/each}}
    </ul>
    <table class="sign-stamp-table">
      <tr>
        <td class="sig">
          Thanks &amp; Regards,<br><br>{{company_name}}
        </td>
        <td class="stamp-cell">
          {{#if stamp_image_data}}<img src="{{stamp_image_data}}" alt="">{{/if}}
        </td>
      </tr>
    </table>
  </div>
</body>
</html>
HTML;

        public function __construct(PDO $conn, ?int $companyId = null) {
            $this->conn = $conn;
            $this->companyId = $companyId ?: (int)(current_company_id($conn) ?: 1);
            $this->basePath = dirname(dirname(dirname(__DIR__)));
            $primary = $this->basePath . '/uploads/renewal_notices';
            if (!is_dir($primary)) {
                @mkdir($primary, 0777, true);
            }
        }

        /**
         * PDFs must be stored under the project root (DB holds a relative path). Prefer the
         * canonical folder; fall back to uploads/temp when Apache cannot write to renewal_notices.
         */
        private function writableRenewalNoticePdfDir(): string {
            $candidates = [
                $this->basePath . '/uploads/renewal_notices',
                $this->basePath . '/uploads/temp/renewal_notices',
            ];
            foreach ($candidates as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
                if (is_dir($dir) && is_writable($dir)) {
                    $real = realpath($dir);
                    return $real !== false ? $real : $dir;
                }
            }
            throw new Exception(
                'Cannot write renewal notice PDF: allow the web server to write to uploads/renewal_notices ' .
                'or uploads/temp/renewal_notices. From the project root, run: chmod -R 775 uploads/renewal_notices uploads/temp ' .
                'or open .fixperms.php once in the browser (see project root). On XAMPP macOS the Apache user is often `daemon`.'
            );
        }

        private function projectRelativeFromAbsolute(string $absoluteFile): string {
            $root = realpath($this->basePath);
            if ($root === false) {
                throw new Exception('Project root could not be resolved.');
            }
            $normRoot = rtrim(str_replace('\\', '/', $root), '/');
            $normFile = str_replace('\\', '/', $absoluteFile);
            if (stripos($normFile, $normRoot . '/') !== 0) {
                throw new Exception('Renewal PDF path is outside project root.');
            }
            return ltrim(substr($normFile, strlen($normRoot) + 1), '/');
        }

        /**
         * Embed local image as data URI so Dompdf always resolves it (no chroot/file:// issues).
         * Dompdf was showing img "alt" text ("Header"/"Footer") when file paths failed permission or resolution.
         */
        private function imagePathToDataUri(string $projectRelativePath): string {
            $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($projectRelativePath, '/'));
            $abs = $this->basePath . DIRECTORY_SEPARATOR . $rel;
            $resolved = realpath($abs);
            if ($resolved === false || !is_readable($resolved)) {
                return '';
            }
            $mime = @mime_content_type($resolved) ?: 'image/png';
            if (strpos($mime, 'image/') !== 0) {
                $mime = 'image/png';
            }
            $raw = @file_get_contents($resolved);
            if ($raw === false || $raw === '') {
                return '';
            }
            return 'data:' . $mime . ';base64,' . base64_encode($raw);
        }

        private function normalizeLines(?string $text): array {
            $text = trim((string)$text);
            if ($text === '') return [];
            $raw = preg_split('/\R/u', $text) ?: [];
            $out = [];
            foreach ($raw as $line) {
                $line = trim($line);
                if ($line !== '') $out[] = $line;
            }
            return $out;
        }

        private function formatMoney(float $n): string {
            return number_format($n, 2, '.', ',');
        }

        /**
         * Dompdf (CPDF) calls tempnam() under the hood for TrueType subsetting.
         * If this directory is not writable by the web server, tempnam() returns false
         * and php-font-lib throws "Path cannot be empty" on fopen.
         */
        /** Writable temp dir for mPDF / Dompdf (fonts, cache, temp files). */
        private function pdfEngineTempDir(): string {
            $preferred = $this->basePath . '/uploads/renewal_notices_temp';
            if (!is_dir($preferred)) {
                @mkdir($preferred, 0775, true);
            }
            if (is_dir($preferred) && is_writable($preferred)) {
                $real = realpath($preferred);
                return $real !== false ? $real : $preferred;
            }
            $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/\\') . '/herosysgro_pdf_engine';
            if (!is_dir($fallback)) {
                @mkdir($fallback, 0775, true);
            }
            if (is_dir($fallback) && is_writable($fallback)) {
                $real = realpath($fallback);
                return $real !== false ? $real : $fallback;
            }
            return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/\\');
        }

        /**
         * mPDF: unlike Dompdf, it does not repeat `position: fixed` banners, and margin HTML header/footer
         * can overlap body text (small margin_top) or not repeat on page 2. Contract PDFs solve repetition via
         * wkhtmltopdf --header-html/--footer-html (every page) or Dompdf fixed blocks. For mPDF we do the
         * same visually: the same header/footer are inserted in the normal document flow on each page.
         */
        private function prepareRenewalNoticeHtmlForMpdf(string $html): string {
            [$headerInner, $html] = $this->extractFirstDivWithClass($html, 'pdf-header');
            [$footerInner, $html] = $this->extractFirstDivWithClass($html, 'pdf-footer');

            $hBlock = '';
            if ($headerInner !== '') {
                $hBlock = '<div class="renewal-mpdf-banner renewal-mpdf-banner--header">'
                    . $this->inlineFirstImgMaxHeight($headerInner, '28mm') . '</div>';
            }
            $fBlock = '';
            if ($footerInner !== '') {
                $fBlock = '<div class="renewal-mpdf-banner renewal-mpdf-banner--footer">'
                    . $this->inlineFirstImgMaxHeight($footerInner, '22mm') . '</div>';
            }

            if ($hBlock !== '') {
                $html = (string)preg_replace(
                    '#<div class="page">\s*#i',
                    '<div class="page">' . $hBlock,
                    $html,
                    1,
                );
            }

            $openTerms = '<pagebreak page-break-type="cloneall" /><div class="page terms-page">'
                . ($hBlock !== '' ? $hBlock : '');
            $html = (string)preg_replace(
                '#<div class="page terms-page">\s*#i',
                $openTerms,
                $html,
                1,
            );

            if ($fBlock !== '') {
                $html = (string)preg_replace(
                    '#<p class="renewal-confirmation">[\s\S]*?</p>#i',
                    '$0' . $fBlock,
                    $html,
                    1,
                );
                $html = (string)preg_replace(
                    '#<table class="sign-stamp-table"[\s\S]*?</table>#i',
                    '$0' . $fBlock,
                    $html,
                    1,
                );
            }

            $html = (string)preg_replace_callback(
                '#<style>(.*?)</style>#is',
                static function (array $m): string {
                    $css = $m[1];
                    $css = preg_replace('/@page\s*\{[^}]*\}/i', '@page { margin: 8mm 10mm 8mm 10mm; }', $css, 1);
                    $css = preg_replace('/\.pdf-header\s*\{[^}]+\}/is', '', $css);
                    $css = preg_replace('/\.pdf-footer\s*\{[^}]+\}/is', '', $css);
                    $css = preg_replace('/\.pdf-header\s+img\s*\{[^}]+\}/is', '', $css);
                    $css = preg_replace('/\.pdf-footer\s+img\s*\{[^}]+\}/is', '', $css);
                    $css = preg_replace('/\.page\s*\{[^}]+\}/is', '.page { width: 100%; page-break-after: auto; }', $css, 1);
                    $css = preg_replace('/\.page:last-child\s*\{[^}]+\}/is', '', $css);
                    $css .= ' body { margin:0 !important; }';
                    $css .= ' .renewal-mpdf-banner--header { text-align: center; margin: 0 0 2mm; padding: 0 0 1mm; border-bottom: 1px solid #ced4da; }';
                    $css .= ' .renewal-mpdf-banner--footer { text-align: center; margin: 4mm 0 0; padding: 1mm 0 0; border-top: 1px solid #ced4da; }';
                    $css .= ' .renewal-mpdf-banner img { display: block; max-width: 100%; width: 100%; margin: 0 auto; }';
                    return '<style>' . $css . '</style>';
                },
                $html,
                1,
            );

            return $html;
        }

        /**
         * @return array{0: string, 1: string} [inner HTML, HTML with first matching div removed]
         */
        private function extractFirstDivWithClass(string $html, string $classToken): array {
            $tok = preg_quote($classToken, '#');
            $re = '#<div\b[^>]*\bclass\s*=\s*(["\'])((?:(?!\1).)*\b' . $tok . '\b(?:(?!\1).)*)\1[^>]*>(.*?)</div>#is';
            if (!preg_match($re, $html, $m)) {
                return ['', $html];
            }
            $inner = trim((string)$m[3]);
            $stripped = (string)preg_replace($re, '', $html, 1);
            return [$inner, $stripped];
        }

        private function inlineFirstImgMaxHeight(string $html, string $maxHeight): string {
            $out = preg_replace_callback(
                '#<img(\s[^>]*?)>#i',
                static function (array $m) use ($maxHeight): string {
                    $attrs = $m[1];
                    if (preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $attrs, $sm)) {
                        $merged = rtrim(trim((string)$sm[1]), ';') . ';max-height:' . $maxHeight
                            . ';width:auto;object-fit:contain;display:block;margin:0 auto;';
                        $attrs = preg_replace('/\bstyle\s*=\s*"[^"]*"/i', 'style="' . $merged . '"', $attrs, 1);
                    } else {
                        $attrs .= ' style="max-height:' . $maxHeight
                            . ';width:auto;object-fit:contain;display:block;margin:0 auto;"';
                    }
                    return '<img' . $attrs . '>';
                },
                $html,
                1,
            );
            return is_string($out) ? $out : $html;
        }

        /**
         * Generate PDF bytes using mPDF (preferred on servers where Dompdf was removed).
         */
        private function generatePdfBytesWithMpdf(string $html): string {
            $tmpDir = $this->pdfEngineTempDir();
            if (!class_exists(\Mpdf\Mpdf::class)) {
                throw new Exception('mPDF class not found after autoload.');
            }
            $htmlForMpdf = $this->prepareRenewalNoticeHtmlForMpdf($html);
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'tempDir' => $tmpDir,
                'default_font' => 'dejavusans',
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 10,
                'margin_bottom' => 10,
                'setAutoTopMargin' => false,
                'setAutoBottomMargin' => false,
                'defaultheaderline' => 0,
                'defaultfooterline' => 0,
            ]);
            $mpdf->WriteHTML($htmlForMpdf);
            $out = $mpdf->Output('', 'S');
            if (!is_string($out) || $out === '') {
                throw new Exception('mPDF returned empty output.');
            }
            return $out;
        }

        /**
         * Generate PDF bytes using Dompdf (fallback when mPDF is not installed).
         */
        private function generatePdfBytesWithDompdf(string $html): string {
            $tmpDir = $this->pdfEngineTempDir();
            if (!class_exists(\Dompdf\Dompdf::class)) {
                throw new Exception('Dompdf class not found after autoload.');
            }
            $options = new \Dompdf\Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->set('isFontSubsettingEnabled', false);
            $options->set('tempDir', $tmpDir);
            $options->set('fontCache', $tmpDir);
            $rootReal = realpath($this->basePath);
            if ($rootReal !== false) {
                $options->setChroot([$rootReal]);
            }
            $dompdf = new \Dompdf\Dompdf($options);
            if ($rootReal !== false) {
                $dompdf->setBasePath($rootReal . DIRECTORY_SEPARATOR);
            }
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $output = $dompdf->output();
            if (!is_string($output) || $output === '') {
                throw new Exception('Dompdf returned empty output.');
            }
            return $output;
        }

        /**
         * Prefer mPDF, then Dompdf; same HTML as before (data-URI images).
         */
        private function generatePdfBytes(string $html): string {
            $vendorAutoload = $this->basePath . '/vendor/autoload.php';
            if (file_exists($vendorAutoload)) {
                require_once $vendorAutoload;
            }
            if (class_exists(\Mpdf\Mpdf::class)) {
                try {
                    return $this->generatePdfBytesWithMpdf($html);
                } catch (Throwable $e) {
                    error_log('Renewal notice PDF (mPDF): ' . $e->getMessage());
                    if (class_exists(\Dompdf\Dompdf::class)) {
                        return $this->generatePdfBytesWithDompdf($html);
                    }
                    throw new Exception('Renewal notice PDF (mPDF): ' . $e->getMessage());
                }
            }
            if (class_exists(\Dompdf\Dompdf::class)) {
                return $this->generatePdfBytesWithDompdf($html);
            }
            throw new Exception(
                'No PDF engine available for renewal notices. Install mPDF (recommended): ' .
                '`composer require mpdf/mpdf` from the project root, or install Dompdf: `composer require dompdf/dompdf`.'
            );
        }

        private function renderTemplate(string $template, array $data): string {
            $rendered = $template;
            $rendered = preg_replace_callback('/\{\{#if\s+([a-zA-Z0-9_]+)\}\}(.*?)\{\{\/if\}\}/s', function ($m) use ($data) {
                $k = $m[1];
                $v = $data[$k] ?? false;
                return !empty($v) ? $m[2] : '';
            }, $rendered) ?? $rendered;
            $rendered = preg_replace_callback('/\{\{#each\s+([a-zA-Z0-9_]+)\}\}(.*?)\{\{\/each\}\}/s', function ($m) use ($data) {
                $k = $m[1];
                $itemTpl = $m[2];
                $arr = $data[$k] ?? [];
                if (!is_array($arr) || !$arr) return '';
                $buf = '';
                foreach ($arr as $item) {
                    $buf .= str_replace('{{this}}', htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8'), $itemTpl);
                }
                return $buf;
            }, $rendered) ?? $rendered;
            $rendered = preg_replace_callback('/\{\{([a-zA-Z0-9_]+)\}\}/', function ($m) use ($data) {
                return htmlspecialchars((string)($data[$m[1]] ?? ''), ENT_QUOTES, 'UTF-8');
            }, $rendered) ?? $rendered;
            return $rendered;
        }

        public function generateFromWorkflow(int $workflowId): string {
            $stmt = $this->conn->prepare("
                SELECT rw.*,
                       l.end_date AS old_end_date,
                       l.number_of_installments AS old_number_of_installments,
                       l.annual_rent AS old_annual_rent,
                       l.company_stamp_path AS lease_company_stamp_path,
                       u.unit_number,
                       b.name AS building_name,
                       t.first_name, t.last_name,
                       c.name AS company_name,
                       bs.community_name,
                       bs.location_name,
                       bs.template_code AS bs_template_code,
                       bs.show_chiller_row AS bs_show_chiller_row,
                       bs.show_chiller_term AS bs_show_chiller_term,
                       COALESCE(bs.show_chiller_row, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS effective_show_chiller_row,
                       COALESCE(bs.show_chiller_term, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS effective_show_chiller_term,
                       bs.admin_fee_label AS bs_admin_fee_label,
                       bs.vat_enabled AS bs_vat_enabled,
                       bs.vat_rate AS bs_vat_rate,
                       bs.rera_charges AS bs_rera_charges,
                       bs.show_grand_total_row AS bs_show_grand_total_row,
                       bs.terms_text AS bs_terms_text,
                       bs.required_documents_text AS bs_required_documents_text
                FROM re_lease_renewal_workflows rw
                JOIN re_leases l ON l.id = rw.lease_id
                JOIN re_units u ON u.id = l.unit_id
                JOIN re_buildings b ON b.id = u.building_id
                JOIN re_tenants t ON t.id = l.tenant_id
                JOIN companies c ON c.id = l.company_id
                LEFT JOIN re_renewal_notice_building_settings bs ON bs.company_id = l.company_id AND bs.building_id = u.building_id
                WHERE rw.id = ? AND l.company_id = ?
                LIMIT 1
            ");
            $stmt->execute([$workflowId, $this->companyId]);
            $w = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$w) throw new Exception("Renewal workflow not found");

            $tenantName = trim((string)($w['first_name'] ?? '') . ' ' . (string)($w['last_name'] ?? ''));
            $unit = (string)($w['unit_number'] ?? '');
            $building = (string)($w['building_name'] ?? '');
            $community = trim((string)($w['community_name'] ?? 'Jumeirah Village Circle'));
            $location = trim((string)($w['location_name'] ?? 'Al Barsha South Fourth, Dubai'));
            $noticeDate = !empty($w['created_at']) ? date('d/m/Y', strtotime((string)$w['created_at'])) : date('d/m/Y');
            $expiryDate = !empty($w['old_end_date']) ? date('d-F-Y', strtotime((string)$w['old_end_date'])) : '—';
            $newStart = !empty($w['proposed_start_date']) ? date('d/m/Y', strtotime((string)$w['proposed_start_date'])) : '—';
            $newEnd = !empty($w['proposed_end_date']) ? date('d/m/Y', strtotime((string)$w['proposed_end_date'])) : '—';

            // rw.* may have show_chiller_row=0 from an old bug; effective_* matches list/initiate COALESCE logic
            $showChillerRow = ((int)($w['show_chiller_row'] ?? 0) === 1)
                || ((int)($w['effective_show_chiller_row'] ?? 0) === 1);
            $showChillerTerm = ((int)($w['show_chiller_term'] ?? 0) === 1)
                || ((int)($w['effective_show_chiller_term'] ?? 0) === 1);
            $adminFeeLabel = trim((string)($w['admin_fee_label'] ?? $w['bs_admin_fee_label'] ?? 'Lease Renewal Charges'));
            if ($adminFeeLabel === '') $adminFeeLabel = 'Lease Renewal Charges';

            $vatEnabled = (int)($w['vat_enabled'] ?? $w['bs_vat_enabled'] ?? 1) === 1;
            $vatRate = (float)($w['vat_rate'] ?? $w['bs_vat_rate'] ?? 5.0);
            if ($vatRate <= 0) $vatRate = 5.0;

            $newAnnualRent = (float)($w['proposed_rent'] ?? 0);
            $chiller = $showChillerRow ? (float)($w['chiller_charges'] ?? 0) : 0.0;
            $adminFee = (float)($w['admin_fees'] ?? 0);
            $parking = (int)($w['additional_parking_enabled'] ?? 0) === 1 ? (float)($w['additional_parking_fee'] ?? 0) : 0.0;
            $smallStore = (int)($w['small_store_enabled'] ?? 0) === 1 ? (float)($w['small_store_fee'] ?? 0) : 0.0;
            $bigStore = (int)($w['big_store_enabled'] ?? 0) === 1 ? (float)($w['big_store_fee'] ?? 0) : 0.0;
            $vatBase = $adminFee + $chiller + $parking + $smallStore + $bigStore;
            $vatOnRent = !empty($w['vat_applicable_on_rent']);
            $vatCalc = lease_vat_compute_amounts([
                'annual_rent' => $newAnnualRent,
                'vat_applicable_on_rent' => $vatOnRent,
                'vat_applicable_on_extra_charges' => $vatEnabled,
                'lease_vat_rate' => $vatRate,
                'extra_charges_base' => $vatBase,
            ]);
            $rentVat = (float)($w['rent_vat_amount'] ?? -1) >= 0
                ? (float)$w['rent_vat_amount']
                : $vatCalc['rent_vat'];
            $vatExtra = (float)($w['vat_extra_charges'] ?? -1) >= 0
                ? (float)$w['vat_extra_charges']
                : $vatCalc['extra_vat'];
            $vatTotal = round($rentVat + $vatExtra, 2);
            $rera = (float)($w['bs_rera_charges'] ?? 300.0);

            $cheques = (int)($w['number_of_cheques'] ?? $w['old_number_of_installments'] ?? 4);
            if ($cheques <= 0) $cheques = 4;
            $showGrandTotal = (int)($w['show_grand_total_row'] ?? $w['bs_show_grand_total_row'] ?? 0) === 1;
            $grandTotal = (float)($w['grand_total_amount'] ?? ($newAnnualRent + $vatBase + $vatTotal + $rera));

            $defaultTerms = [
                'If the tenant wishes to terminate the contract on expiry and the tenant has not served 60 days prior written notice to the Landlord/Agent; the tenants are liable to pay outstanding rental dues and three months rental penalty from the date of key handover.',
                'If the tenant fails to renew the tenancy contract on or before its expiration, the contract will be automatically terminated, and the tenant will be deemed to have vacated the premises. Upon the expiration of the tenancy contract, the tenant is granted a grace period of 3 days to vacate the premises. During this period, the tenant must ensure that the unit is returned in good condition, as specified in the contract.',
                'In any case where the tenant does not vacate the premises or fails to renew the contract on time, a late renewal fee of 1,000 AED will be applicable. This fee will be due immediately and must be paid in full to proceed with the renewal or allow the tenant to remain in the property.',
                'In the event of non-renewal or failure to vacate, the landlord reserves the right to initiate further actions, including but not limited to, filing with the Notary Public or initiating an RDC case.',
                'Please note that the additional parking slot is not included in the rent amount provided.'
            ];
            if ($showChillerTerm) {
                array_splice($defaultTerms, 4, 0, ['7 AED/Sq.ft will be charged for chiller for the same year.']);
            }
            $defaultDocs = [
                'Valid Occupant passport copy & visa page (Residential)',
                'Valid cheque signatory Passport copy (Residential/Commercial)',
                'Valid Trade license copy (Applicable Tenancy in the name of company or Commercial)',
                'Valid Owner/signatory passport copy (Commercial)',
                'Valid sponsor passport copy (Commercial)',
                'L.L.C agreement (If applicable)',
                'Power of attorney (If applicable)'
            ];
            $terms = $this->normalizeLines((string)($w['terms_text'] ?? $w['bs_terms_text'] ?? ''));
            if (!$terms) $terms = $defaultTerms;
            $docs = $this->normalizeLines((string)($w['required_documents_text'] ?? $w['bs_required_documents_text'] ?? ''));
            if (!$docs) $docs = $defaultDocs;

            $stampRel = trim((string)($w['stamp_image_path'] ?? ''));
            if ($stampRel === '') {
                $stampRel = trim((string)($w['lease_company_stamp_path'] ?? ''));
            }

            $contractsDir = $this->basePath . '/uploads/contracts';
            if (!is_dir($contractsDir)) {
                @mkdir($contractsDir, 0755, true);
            }
            $headerData = $this->imagePathToDataUri('uploads/contracts/header.png');
            $footerData = $this->imagePathToDataUri('uploads/contracts/footer.png');
            $stampData = $stampRel !== '' ? $this->imagePathToDataUri($stampRel) : '';
            if ($headerData === '') {
                error_log('Renewal notice PDF: missing or unreadable uploads/contracts/header.png (resolved from ' . $this->basePath . ')');
            }
            if ($footerData === '') {
                error_log('Renewal notice PDF: missing or unreadable uploads/contracts/footer.png');
            }
            $vatRateLabel = rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') . '%';
            if ($vatRateLabel === '%') {
                $vatRateLabel = '0%';
            }

            $html = $this->renderTemplate(self::MASTER_TEMPLATE, [
                'header_image_data' => $headerData,
                'footer_image_data' => $footerData,
                'stamp_image_data' => $stampData,
                'notice_date' => $noticeDate,
                'tenant_name' => strtoupper($tenantName),
                'unit_code' => $unit,
                'building_name' => $building,
                'community_name' => $community,
                'location_name' => $location,
                'current_lease_expiry_date' => $expiryDate,
                'new_period_start' => $newStart,
                'new_period_end' => $newEnd,
                'new_annual_rent_display' => $this->formatMoney($newAnnualRent),
                'show_chiller_row' => $showChillerRow,
                'chiller_charges_display' => $this->formatMoney($chiller),
                'admin_fee_label' => $adminFeeLabel,
                'admin_fee_display' => $this->formatMoney($adminFee),
                'show_additional_parking_row' => $parking > 0,
                'additional_parking_fee_display' => $this->formatMoney($parking),
                'show_small_store_row' => $smallStore > 0,
                'small_store_fee_display' => $this->formatMoney($smallStore),
                'show_big_store_row' => $bigStore > 0,
                'big_store_fee_display' => $this->formatMoney($bigStore),
                'vat_rate_label' => $vatRateLabel,
                'show_rent_vat_row' => $rentVat > 0.00001,
                'rent_vat_display' => $this->formatMoney($rentVat),
                'vat_extra_charges_display' => $this->formatMoney($vatExtra),
                'vat_total_display' => $this->formatMoney($vatTotal),
                'rera_charges_display' => $this->formatMoney($rera),
                'number_of_cheques' => (string)$cheques,
                'show_grand_total_row' => $showGrandTotal,
                'grand_total_display' => $this->formatMoney($grandTotal),
                'terms' => $terms,
                'required_documents' => $docs,
                'company_name' => (string)($w['company_name'] ?? 'Real Estate'),
            ]);

            $output = $this->generatePdfBytes($html);

            $pdfFilename = 'renewal_notice_' . $workflowId . '_' . date('YmdHis') . '.pdf';
            $pdfDir = $this->writableRenewalNoticePdfDir();
            $pdfPathAbs = $pdfDir . DIRECTORY_SEPARATOR . $pdfFilename;
            if (file_put_contents($pdfPathAbs, $output, LOCK_EX) === false) {
                throw new Exception(
                    'Unable to write renewal notice PDF file. Check permissions on ' . $pdfDir . ' ' .
                    '(or run .fixperms.php from the project root).'
                );
            }

            $relativePath = $this->projectRelativeFromAbsolute($pdfPathAbs);
            $u = $this->conn->prepare("
                UPDATE re_lease_renewal_workflows
                SET renewal_notice_pdf_path = ?, renewal_notice_generated_at = NOW(), vat_extra_charges = ?
                WHERE id = ? AND lease_id IN (SELECT id FROM re_leases WHERE company_id = ?)
            ");
            $u->execute([$relativePath, $vatExtra, $workflowId, $this->companyId]);
            return $relativePath;
        }
    }
}

