<?php
/**
 * Legal Notice PDF generator.
 * Mirrors the renewal notice approach: mPDF preferred, Dompdf fallback,
 * header/footer letterhead images embedded as data URIs, writable temp dir.
 */

if (!class_exists('LegalNoticePDFGenerator')) {
    class LegalNoticePDFGenerator {
        private PDO $conn;
        private int $companyId;
        private string $basePath;

        public function __construct(PDO $conn, ?int $companyId = null) {
            $this->conn = $conn;
            $this->companyId = $companyId ?: (int)(current_company_id($conn) ?: 1);
            $this->basePath = dirname(dirname(dirname(__DIR__)));
        }

        private function writablePdfDir(): string {
            $candidates = [
                $this->basePath . '/uploads/legal_notices',
                $this->basePath . '/uploads/temp/legal_notices',
            ];
            foreach ($candidates as $dir) {
                if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                if (is_dir($dir) && is_writable($dir)) {
                    $real = realpath($dir);
                    return $real !== false ? $real : $dir;
                }
            }
            throw new Exception('Cannot write legal notice PDF: make uploads/legal_notices or uploads/temp/legal_notices writable.');
        }

        private function pdfEngineTempDir(): string {
            $preferred = $this->basePath . '/uploads/temp/mpdf';
            if (!is_dir($preferred)) { @mkdir($preferred, 0777, true); }
            if (is_dir($preferred) && is_writable($preferred)) {
                $real = realpath($preferred);
                return $real !== false ? $real : $preferred;
            }
            $fallback = rtrim(sys_get_temp_dir(), '/\\') . '/herosysgro_pdf_engine';
            if (!is_dir($fallback)) { @mkdir($fallback, 0777, true); }
            return is_dir($fallback) ? $fallback : rtrim(sys_get_temp_dir(), '/\\');
        }

        private function projectRelativeFromAbsolute(string $absoluteFile): string {
            $root = realpath($this->basePath);
            if ($root === false) { throw new Exception('Project root could not be resolved.'); }
            $normRoot = rtrim(str_replace('\\', '/', $root), '/');
            $normFile = str_replace('\\', '/', $absoluteFile);
            if (stripos($normFile, $normRoot . '/') !== 0) {
                throw new Exception('Legal PDF path is outside project root.');
            }
            return ltrim(substr($normFile, strlen($normRoot) + 1), '/');
        }

        private function imagePathToDataUri(string $projectRelativePath): string {
            $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($projectRelativePath, '/'));
            $abs = $this->basePath . DIRECTORY_SEPARATOR . $rel;
            $resolved = realpath($abs);
            if ($resolved === false || !is_readable($resolved)) { return ''; }
            $mime = @mime_content_type($resolved) ?: 'image/png';
            if (strpos($mime, 'image/') !== 0) { $mime = 'image/png'; }
            $raw = @file_get_contents($resolved);
            if ($raw === false || $raw === '') { return ''; }
            return 'data:' . $mime . ';base64,' . base64_encode($raw);
        }

        private function buildHtml(array $n, array $company): string {
            $noticeTypes = legal_notice_types();
            $title = $noticeTypes[$n['notice_type']] ?? 'Legal Notice';
            $headerData = $this->imagePathToDataUri('uploads/contracts/header.png');
            $footerData = $this->imagePathToDataUri('uploads/contracts/footer.png');

            $issueDate = !empty($n['issue_date']) ? date('d F Y', strtotime($n['issue_date'])) : date('d F Y');
            $deadline = !empty($n['response_deadline']) ? date('d F Y', strtotime($n['response_deadline'])) : '';
            $ref = htmlspecialchars($n['reference_number'], ENT_QUOTES, 'UTF-8');
            $recipient = htmlspecialchars($n['recipient_name'] ?: '', ENT_QUOTES, 'UTF-8');
            $address = nl2br(htmlspecialchars($n['recipient_address'] ?: '', ENT_QUOTES, 'UTF-8'));
            $subject = htmlspecialchars($n['subject'] ?: $title, ENT_QUOTES, 'UTF-8');
            $body = nl2br(htmlspecialchars($n['body'] ?: '', ENT_QUOTES, 'UTF-8'));
            $companyName = htmlspecialchars($company['name'] ?? 'Real Estate', ENT_QUOTES, 'UTF-8');

            $headerHtml = $headerData ? '<div class="lh"><img src="' . $headerData . '" alt=""></div>' : '';
            $footerHtml = $footerData ? '<div class="lf"><img src="' . $footerData . '" alt=""></div>' : '';
            $deadlineHtml = $deadline ? '<p><strong>Response Deadline:</strong> ' . htmlspecialchars($deadline, ENT_QUOTES, 'UTF-8') . '</p>' : '';

            return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>$ref</title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; color:#111; font-size:12px; line-height:1.5; }
  .lh { text-align:center; margin-bottom:12px; }
  .lh img { width:100%; max-height:90px; object-fit:contain; }
  .lf { text-align:center; margin-top:18px; }
  .lf img { width:100%; max-height:60px; object-fit:contain; }
  .meta { width:100%; margin:8px 0 14px; font-size:11px; }
  .meta td { padding:2px 0; vertical-align:top; }
  .doc-title { text-align:center; font-size:16px; font-weight:bold; text-decoration:underline; text-transform:uppercase; margin:10px 0 16px; }
  .recipient { margin-bottom:14px; }
  .subject { font-weight:bold; text-decoration:underline; margin:10px 0; }
  .body p { margin:0 0 10px; }
  .sign { margin-top:30px; }
</style></head>
<body>
  $headerHtml
  <table class="meta"><tr>
    <td><strong>Ref:</strong> $ref</td>
    <td style="text-align:right"><strong>Date:</strong> $issueDate</td>
  </tr></table>
  <div class="doc-title">$title</div>
  <div class="recipient">
    <strong>To:</strong> $recipient<br>
    $address
  </div>
  <div class="subject">Subject: $subject</div>
  <div class="body">$body</div>
  $deadlineHtml
  <div class="sign">
    Yours faithfully,<br><br>
    <strong>$companyName</strong><br>
    Legal Department
  </div>
  $footerHtml
</body></html>
HTML;
        }

        private function generatePdfBytes(string $html): string {
            $vendorAutoload = $this->basePath . '/vendor/autoload.php';
            if (file_exists($vendorAutoload)) { require_once $vendorAutoload; }
            $tmpDir = $this->pdfEngineTempDir();

            if (class_exists(\Mpdf\Mpdf::class)) {
                try {
                    $mpdf = new \Mpdf\Mpdf([
                        'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmpDir,
                        'default_font' => 'dejavusans',
                        'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 12, 'margin_bottom' => 12,
                    ]);
                    $mpdf->WriteHTML($html);
                    $out = $mpdf->Output('', 'S');
                    if (is_string($out) && $out !== '') { return $out; }
                } catch (Throwable $e) {
                    error_log('Legal notice PDF (mPDF): ' . $e->getMessage());
                }
            }
            if (class_exists(\Dompdf\Dompdf::class)) {
                $options = new \Dompdf\Options();
                $options->set('isHtml5ParserEnabled', true);
                $options->set('isRemoteEnabled', true);
                $options->set('tempDir', $tmpDir);
                $options->set('fontCache', $tmpDir);
                $rootReal = realpath($this->basePath);
                if ($rootReal !== false) { $options->setChroot([$rootReal]); }
                $dompdf = new \Dompdf\Dompdf($options);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                $out = $dompdf->output();
                if (is_string($out) && $out !== '') { return $out; }
            }
            throw new Exception('No PDF engine available. Install mPDF: composer require mpdf/mpdf');
        }

        /**
         * Generate (or regenerate) the PDF for a notice and persist pdf_path.
         * @return string project-relative PDF path
         */
        public function generate(int $noticeId): string {
            $stmt = $this->conn->prepare("SELECT * FROM re_legal_notices WHERE id = ? AND company_id = ?");
            $stmt->execute([$noticeId, $this->companyId]);
            $n = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$n) { throw new Exception('Legal notice not found.'); }

            $cs = $this->conn->prepare("SELECT name FROM companies WHERE id = ?");
            $cs->execute([$this->companyId]);
            $company = $cs->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Real Estate'];

            $html = $this->buildHtml($n, $company);
            $bytes = $this->generatePdfBytes($html);

            $filename = 'legal_notice_' . $noticeId . '_' . date('YmdHis') . '.pdf';
            $dir = $this->writablePdfDir();
            $abs = $dir . DIRECTORY_SEPARATOR . $filename;
            if (file_put_contents($abs, $bytes, LOCK_EX) === false) {
                throw new Exception('Unable to write legal notice PDF to ' . $dir);
            }
            $rel = $this->projectRelativeFromAbsolute($abs);
            $this->conn->prepare("UPDATE re_legal_notices SET pdf_path = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
                       ->execute([$rel, $noticeId, $this->companyId]);
            return $rel;
        }
    }
}
