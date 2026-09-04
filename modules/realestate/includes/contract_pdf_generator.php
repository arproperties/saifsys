<?php
/**
 * Contract PDF Generator using mPDF
 * Safe for Arabic on shared hosting such as Hostinger.
 */

if (!class_exists('ContractPDFGenerator')) {
    class ContractPDFGenerator {
        private $conn;
        private $companyId;
        private $basePath;
        private $uploadsPath;
        private $tempPath;
        private $fontPath;

        public function __construct($conn, $companyId = null) {
            $this->conn = $conn;
            $this->companyId = $companyId ?: current_company_id($conn) ?: 1;
            $this->basePath = dirname(dirname(dirname(__DIR__)));
            $this->uploadsPath = $this->basePath . '/uploads/contracts';
            $this->tempPath = $this->basePath . '/uploads/temp';
            $this->fontPath = $this->basePath . '/storage/fonts';

            foreach ([$this->uploadsPath, $this->tempPath] as $dir) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }
        }

        public function generateFromLease($leaseId) {
            $stmt = $this->conn->prepare("
                SELECT l.*,
                       t.first_name, t.last_name, t.email, t.phone, t.id_number,
                       u.unit_number, u.unit_type, u.area_sqm, u.premises_number,
                       b.name as building_name, b.address as building_address,
                       c.name as company_name
                FROM re_leases l
                JOIN re_tenants t ON t.id = l.tenant_id
                JOIN re_units u ON u.id = l.unit_id
                JOIN re_buildings b ON b.id = u.building_id
                JOIN companies c ON c.id = l.company_id
                WHERE l.id = ? AND l.company_id = ?
            ");
            $stmt->execute([$leaseId, $this->companyId]);
            $lease = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lease) {
                throw new Exception('Lease not found');
            }

            $templateId = $lease['template_id'] ?? null;
            if (!$templateId) {
                $stmt = $this->conn->prepare("
                    SELECT * FROM re_contract_templates
                    WHERE company_id = ? AND is_active = 1 AND is_default = 1
                    LIMIT 1
                ");
                $stmt->execute([$this->companyId]);
                $template = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $stmt = $this->conn->prepare("
                    SELECT * FROM re_contract_templates
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$templateId, $this->companyId]);
                $template = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$template) {
                throw new Exception('Template not found');
            }

            $rawHtml = !empty($template['template_html'])
                ? $template['template_html']
                : $this->convertTextToHTML($template['template_content'] ?? '');

            $htmlContent = (strpos($rawHtml, '&lt;') !== false && strpos($rawHtml, '<') === false)
                ? html_entity_decode($rawHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : $rawHtml;

            $htmlContent = $this->replacePlaceholders($htmlContent, $lease, $template);
            $pdfPath = $this->generatePDF($htmlContent, $leaseId, $lease);

            $stmt = $this->conn->prepare("
                UPDATE re_leases
                SET generated_contract_path = ?, contract_generated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$pdfPath, $leaseId, $this->companyId]);

            return $pdfPath;
        }

        private function replacePlaceholders($html, $lease, $template) {
            $companyName = $lease['company_name'] ?? '';
            $companyNameAr = $this->getCompanyNameArabic($this->companyId);

            $startDate = !empty($lease['start_date']) ? date('d/m/Y', strtotime($lease['start_date'])) : '';
            $endDate = !empty($lease['end_date']) ? date('d/m/Y', strtotime($lease['end_date'])) : '';
            $todayDate = date('d/m/Y');

            $tenantName = trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''));
            $tenantPhone = $lease['phone'] ?? '';
            $tenantEmail = $lease['email'] ?? '';
            $tenantEmiratesId = $lease['id_number'] ?? '';
            $leaseNumber = $lease['lease_number'] ?? '';
            $propertyName = $lease['building_name'] ?? '';
            $unitNo = $lease['unit_number'] ?? '';
            $premisesNumber = $lease['premises_number'] ?? '';

            if (isset($lease['number_of_installments']) && (int)$lease['number_of_installments'] > 0) {
                $numberOfInstallments = (int)$lease['number_of_installments'];
            } else {
                $stmt = $this->conn->prepare('SELECT COUNT(*) as count FROM re_lease_installments WHERE lease_id = ?');
                $stmt->execute([$lease['id']]);
                $installmentCount = $stmt->fetch(PDO::FETCH_ASSOC);
                $numberOfInstallments = (!empty($installmentCount['count'])) ? (int)$installmentCount['count'] : 12;
            }
            $paymentMethod = !empty($lease['payment_method']) ? ucfirst(str_replace('_', ' ', $lease['payment_method'])) : 'Cheque';
            $termsOfPayment = $numberOfInstallments . ' installments via ' . $paymentMethod;

            $annualRentRaw = isset($lease['annual_rent']) ? (float)$lease['annual_rent'] : ((float)($lease['monthly_rent'] ?? 0) * 12);
            $chillerFeesRaw = (float)($lease['chiller_fees'] ?? 0);
            $annualRent = number_format($annualRentRaw, 2);
            $chillerFees = number_format($chillerFeesRaw, 2);
            $annualRentWithChiller = number_format($annualRentRaw + $chillerFeesRaw, 2);
            $securityDeposit = number_format((float)($lease['security_deposit'] ?? 0), 2);

            $leaseVatRatePdf = max(0, (float)($lease['lease_vat_rate'] ?? 5.0));
            $rentVatPdf = (float)($lease['rent_vat_amount'] ?? 0);
            $extraVatPdf = (float)($lease['extra_services_vat_amount'] ?? 0);
            $totalVatPdf = (float)($lease['total_vat_amount'] ?? 0);
            if ($totalVatPdf <= 0 && ($rentVatPdf > 0 || $extraVatPdf > 0)) {
                $totalVatPdf = round($rentVatPdf + $extraVatPdf, 2);
            }
            $vatOnRentLabel = !empty($lease['vat_applicable_on_rent']) ? 'Yes' : 'No';
            $vatOnExtrasLabel = !isset($lease['vat_applicable_on_extra_charges']) || !empty($lease['vat_applicable_on_extra_charges']) ? 'Yes' : 'No';
            $vatDistLabel = ucwords(str_replace('_', ' ', (string)($lease['vat_distribution_type'] ?? 'first_installment')));
            $grossAnnualRentInclRentVat = number_format($annualRentRaw + $rentVatPdf, 2);
            $vatNotesPdf = trim((string)($lease['vat_notes'] ?? ''));
            $vatNotesHtml = $vatNotesPdf !== '' ? nl2br(htmlspecialchars($vatNotesPdf, ENT_QUOTES, 'UTF-8')) : '';

            $ejariNumber = $lease['ejari_registration_number'] ?? '';
            $ejariDate = !empty($lease['ejari_issue_date']) ? date('d/m/Y', strtotime($lease['ejari_issue_date'])) : '';
            $ejariPropertyCode = $lease['ejari_property_code'] ?? '';

            $landlordSignature = $this->getSignatureImage($lease['landlord_signature_path'] ?? '');
            $tenantSignature = $this->getSignatureImage($lease['tenant_signature_path'] ?? '');
            $companyStamp = $this->getSignatureImage($lease['company_stamp_path'] ?? '');

            $replacements = [
                '{{LANDLORD_NAME}}' => $companyName,
                '{{LANDLORD_NAME_AR}}' => $companyNameAr,
                '{{TENANT_NAME}}' => $tenantName,
                '{{TENANT_NAME_AR}}' => $tenantName,
                '{{PROPERTY_NAME}}' => $propertyName,
                '{{PROPERTY_NAME_AR}}' => $propertyName,
                '{{UNIT_NO}}' => $unitNo,
                '{{UNIT_NO_AR}}' => $unitNo,
                '{{LEASE_NUMBER}}' => $leaseNumber,
                '{{START_DATE}}' => $startDate,
                '{{START_DATE_AR}}' => $startDate,
                '{{END_DATE}}' => $endDate,
                '{{END_DATE_AR}}' => $endDate,
                '{{TODAY_DATE}}' => $todayDate,
                '{{ANNUAL_RENT}}' => $annualRent,
                '{{ANNUAL_RENT_AR}}' => $annualRent,
                '{{CHILLER_FEES}}' => $chillerFees,
                '{{CHILLER_FEES_AR}}' => $chillerFees,
                '{{ANNUAL_RENT_WITH_CHILLER}}' => $annualRentWithChiller,
                '{{ANNUAL_RENT_WITH_CHILLER_AR}}' => $annualRentWithChiller,
                '{{SECURITY_DEPOSIT}}' => $securityDeposit,
                '{{SECURITY_DEPOSIT_AR}}' => $securityDeposit,
                '{{LEASE_VAT_RATE}}' => number_format($leaseVatRatePdf, 2),
                '{{LEASE_VAT_RATE_AR}}' => number_format($leaseVatRatePdf, 2),
                '{{VAT_ON_RENT}}' => $vatOnRentLabel,
                '{{VAT_ON_RENT_AR}}' => $vatOnRentLabel,
                '{{VAT_ON_EXTRAS}}' => $vatOnExtrasLabel,
                '{{VAT_ON_EXTRAS_AR}}' => $vatOnExtrasLabel,
                '{{RENT_VAT_AMOUNT}}' => number_format($rentVatPdf, 2),
                '{{RENT_VAT_AMOUNT_AR}}' => number_format($rentVatPdf, 2),
                '{{EXTRA_VAT_AMOUNT}}' => number_format($extraVatPdf, 2),
                '{{EXTRA_VAT_AMOUNT_AR}}' => number_format($extraVatPdf, 2),
                '{{TOTAL_VAT_AMOUNT}}' => number_format($totalVatPdf, 2),
                '{{TOTAL_VAT_AMOUNT_AR}}' => number_format($totalVatPdf, 2),
                '{{VAT_DISTRIBUTION}}' => $vatDistLabel,
                '{{VAT_DISTRIBUTION_AR}}' => $vatDistLabel,
                '{{GROSS_ANNUAL_RENT_INCL_RENT_VAT}}' => $grossAnnualRentInclRentVat,
                '{{GROSS_ANNUAL_RENT_INCL_RENT_VAT_AR}}' => $grossAnnualRentInclRentVat,
                '{{VAT_NOTES}}' => $vatNotesHtml,
                '{{VAT_NOTES_AR}}' => $vatNotesHtml,
                '{{PREMISES_NUMBER}}' => $premisesNumber ?: '_______________',
                '{{TERMS_OF_PAYMENT}}' => $termsOfPayment ?: '_______________',
                '{{TENANT_PHONE}}' => $tenantPhone ?: '_______________',
                '{{TENANT_EMAIL}}' => $tenantEmail ?: '_______________',
                '{{TENANT_EMIRATES_ID}}' => $tenantEmiratesId ?: '_______________',
                '{{TENANT_ID_NUMBER}}' => $tenantEmiratesId ?: '_______________',
                '{{LEASE_NOTES}}' => !empty($lease['notes']) ? nl2br(htmlspecialchars($lease['notes'], ENT_QUOTES, 'UTF-8')) : '',
                '{{LEASE_NOTES_AR}}' => !empty($lease['notes']) ? nl2br(htmlspecialchars($lease['notes'], ENT_QUOTES, 'UTF-8')) : '',
                '{{EJARI_NUMBER}}' => $ejariNumber,
                '{{EJARI_DATE}}' => $ejariDate,
                '{{EJARI_PROPERTY_CODE}}' => $ejariPropertyCode,
                '{{LANDLORD_SIGNATURE}}' => $landlordSignature ? '<img src="' . htmlspecialchars($landlordSignature, ENT_QUOTES, 'UTF-8') . '" class="signature-image" alt="Landlord Signature">' : '',
                '{{TENANT_SIGNATURE}}' => $tenantSignature ? '<img src="' . htmlspecialchars($tenantSignature, ENT_QUOTES, 'UTF-8') . '" class="signature-image" alt="Tenant Signature">' : '',
                '{{COMPANY_STAMP}}' => $companyStamp ? '<img src="' . htmlspecialchars($companyStamp, ENT_QUOTES, 'UTF-8') . '" class="signature-image" alt="Company Stamp">' : '',
            ];

            return str_replace(array_keys($replacements), array_values($replacements), $html);
        }

        private function getSignatureImage($path) {
            if (empty($path) || !file_exists($this->basePath . '/' . ltrim($path, '/'))) {
                return '';
            }
            $fullPath = $this->basePath . '/' . ltrim($path, '/');
            $imageData = file_get_contents($fullPath);
            $imageInfo = @getimagesize($fullPath);
            if (!$imageInfo) {
                return '';
            }
            return 'data:' . $imageInfo['mime'] . ';base64,' . base64_encode($imageData);
        }

        private function getCompanyNameArabic($companyId) {
            $stmt = $this->conn->prepare('SELECT name FROM companies WHERE id = ?');
            $stmt->execute([$companyId]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
            return $company['name'] ?? '';
        }

        private function generateHeaderHTML($lease = []) {
            $headerImagePath = null;
            foreach (['/uploads/contracts/header.png','/uploads/contracts/header.jpg','/uploads/contracts/header.jpeg'] as $rel) {
                $full = $this->basePath . $rel;
                if (file_exists($full) && is_readable($full)) {
                    $headerImagePath = $full;
                    break;
                }
            }
            if ($headerImagePath) {
                $imageData = file_get_contents($headerImagePath);
                $imageInfo = @getimagesize($headerImagePath);
                if ($imageInfo) {
                    return '<div style="text-align:center;"><img src="data:' . $imageInfo['mime'] . ';base64,' . base64_encode($imageData) . '" style="width:100%; height:100px;"></div>';
                }
            }
            $companyName = htmlspecialchars($lease['company_name'] ?? 'AIN AL REEM PROPERTIES L.L.C.', ENT_QUOTES, 'UTF-8');
            return '<div style="text-align:center;border-bottom:1px solid #000;font-family:dejavusans;font-size:8px;padding-bottom:4px;">'
                . '<div style="font-size:11px;font-weight:bold;">' . $companyName . '</div>'
                . '<div>Real Estate and Management Company</div>'
                . '</div>';
        }

        private function generateFooterHTML() {
            $footerImagePath = null;
            foreach (['/uploads/contracts/footer.png','/uploads/contracts/footer.jpg','/uploads/contracts/footer.jpeg'] as $rel) {
                $full = $this->basePath . $rel;
                if (file_exists($full) && is_readable($full)) {
                    $footerImagePath = $full;
                    break;
                }
            }
            if ($footerImagePath) {
                $imageData = file_get_contents($footerImagePath);
                $imageInfo = @getimagesize($footerImagePath);
                if ($imageInfo) {
                    return '<div style="text-align:center;"><img src="data:' . $imageInfo['mime'] . ';base64,' . base64_encode($imageData) . '" style="width:100%; height:80px;"></div>';
                }
            }
            return '<div style="text-align:center;font-family:dejavusans;font-size:7px;border-top:1px solid #ddd;padding-top:3px;">'
                . '<div>Phone: +971 544603667; +971 562436573</div>'
                . '<div>Email: ar.properties15@gmail.com</div>'
                . '<div>Address: OFFICE P01, AYLA RESIDENCE, AL BARSHA SOUTH FOURTH, DUBAI</div>'
                . '</div>';
        }

        private function convertTextToHTML($text) {
            return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</body></html>';
        }

        public function isMpdfAvailable(): bool {
            $vendorAutoload = $this->basePath . '/vendor/autoload.php';
            try {
                if (file_exists($vendorAutoload)) {
                    require_once $vendorAutoload;
                }
                return class_exists('\\Mpdf\\Mpdf');
            } catch (Throwable $e) {
                error_log('mPDF availability check failed: ' . $e->getMessage());
                return false;
            }
        }

        public function isWkhtmltopdfAvailable(): bool {
            // Backward compatibility for older pages. Contract generation now uses mPDF.
            return false;
        }

        public function getPdfEngineLabel(): string {
            return $this->isMpdfAvailable() ? 'mPDF' : 'mPDF not installed';
        }

private function generatePDF($html, $leaseId, $lease = null) {
    $vendorAutoload = $this->basePath . '/vendor/autoload.php';
    if (!file_exists($vendorAutoload)) {
        throw new Exception('Composer autoload not found. Run composer require mpdf/mpdf');
    }
    try {
        require_once $vendorAutoload;
    } catch (Throwable $e) {
        throw new Exception(
            'Composer dependencies cannot load with the current PHP version. ' .
            'Your local PHP is ' . PHP_VERSION . '. Please upgrade PHP to 8.3+ or regenerate Composer dependencies for PHP 8.2. ' .
            'Composer error: ' . $e->getMessage()
        );
    }

    if (!class_exists('\\Mpdf\\Mpdf')) {
        throw new Exception('mPDF is not installed. Run composer require mpdf/mpdf');
    }

    $pdfFilename = 'contract_' . $leaseId . '_' . date('YmdHis') . '.pdf';
    $pdfFullPath = $this->uploadsPath . '/' . $pdfFilename;

    $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $fontDirs = $defaultConfig['fontDir'];

    $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $defaultFontConfig['fontdata'];

    $defaultFont = 'dejavusans';

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_top' => 35,
        'margin_bottom' => 30,
        'margin_left' => 15,
        'margin_right' => 15,
        'tempDir' => $this->tempPath,
        'fontDir' => $fontDirs,
        'fontdata' => $fontData,
        'default_font' => $defaultFont,
        'autoScriptToLang' => true,
        'autoLangToFont' => true,
    ]);

    $mpdf->SetDirectionality('auto');
    $mpdf->SetHTMLHeader($this->generateHeaderHTML($lease ?: []));
    $mpdf->SetHTMLFooter($this->generateFooterHTML());
    $mpdf->WriteHTML($html);
    $mpdf->Output($pdfFullPath, \Mpdf\Output\Destination::FILE);

    return 'uploads/contracts/' . $pdfFilename;
}
    }
}
