<?php
/**
 * Real Estate Module - Contract Template Generator
 * Functions to generate contracts from templates with placeholder replacement
 */

if (!function_exists('generate_contract_from_template')) {
    /**
     * Generate contract content from template with placeholders replaced
     * @param PDO $conn Database connection
     * @param int $leaseId Lease ID
     * @param int|null $templateId Template ID (optional, will use default if not provided)
     * @return string Generated contract content
     */
    function generate_contract_from_template(PDO $conn, int $leaseId, ?int $templateId = null): string {
        $currentCompanyId = current_company_id($conn) ?: 1;
        
        // Get template
        if ($templateId) {
            $stmt = $conn->prepare("SELECT template_content FROM re_contract_templates WHERE id = ? AND company_id = ? AND is_active = 1");
            $stmt->execute([$templateId, $currentCompanyId]);
        } else {
            $stmt = $conn->prepare("SELECT template_content FROM re_contract_templates WHERE company_id = ? AND is_default = 1 AND is_active = 1 LIMIT 1");
            $stmt->execute([$currentCompanyId]);
        }
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            return "Template not found.";
        }
        
        // Get lease data with all related information
        $stmt = $conn->prepare("
            SELECT l.*,
                   u.unit_number, u.unit_type, u.area_sqm, u.premises_number, u.parking_slot, u.furniture_status,
                   b.name as building_name, b.address as building_address,
                   t.first_name, t.last_name, t.phone, t.email, t.id_number, t.id_type, 
                   t.tenant_type, t.company_name, t.address as tenant_address,
                   f.floor_number, f.name as floor_name
            FROM re_leases l
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN re_tenants t ON t.id = l.tenant_id
            LEFT JOIN re_floors f ON f.id = u.floor_id
            WHERE l.id = ? AND l.company_id = ?
        ");
        $stmt->execute([$leaseId, $currentCompanyId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lease) {
            return "Lease not found.";
        }
        
        // Get company settings
        $stmt = $conn->prepare("SELECT * FROM company_settings WHERE company_id = ? LIMIT 1");
        $stmt->execute([$currentCompanyId]);
        $companySettings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Prepare replacement data
        $replacements = [
            // Tenant Information
            '{TENANT_NAME}' => $lease['tenant_type'] === 'company' 
                ? ($lease['company_name'] ?: trim($lease['first_name'] . ' ' . $lease['last_name']))
                : trim($lease['first_name'] . ' ' . $lease['last_name']),
            '{TENANT_FIRST_NAME}' => $lease['first_name'] ?? '',
            '{TENANT_LAST_NAME}' => $lease['last_name'] ?? '',
            '{TENANT_FULL_NAME}' => trim($lease['first_name'] . ' ' . $lease['last_name']),
            '{TENANT_PHONE}' => $lease['phone'] ?? '',
            '{TENANT_EMAIL}' => $lease['email'] ?? '',
            '{TENANT_ID_NUMBER}' => $lease['id_number'] ?? '',
            '{TENANT_ID_TYPE}' => ucfirst(str_replace('_', ' ', $lease['id_type'] ?? '')),
            '{TENANT_ADDRESS}' => $lease['tenant_address'] ?? '',
            '{TENANT_TYPE}' => ucfirst($lease['tenant_type'] ?? 'individual'),
            '{COMPANY_NAME}' => $lease['company_name'] ?? '',
            
            // Unit Information
            '{UNIT_NUMBER}' => $lease['unit_number'] ?? '',
            '{UNIT_TYPE}' => strtoupper($lease['unit_type'] ?? ''),
            '{UNIT_AREA}' => $lease['area_sqm'] ? number_format($lease['area_sqm'], 2) . ' sqm' : '',
            '{PREMISES_NUMBER}' => $lease['premises_number'] ?? '',
            '{PARKING_SLOT}' => $lease['parking_slot'] ?? '',
            '{FURNITURE_STATUS}' => ucfirst(str_replace('_', ' ', $lease['furniture_status'] ?? '')),
            
            // Building Information
            '{BUILDING_NAME}' => $lease['building_name'] ?? '',
            '{BUILDING_ADDRESS}' => $lease['building_address'] ?? '',
            '{FLOOR_NUMBER}' => $lease['floor_number'] ?? '',
            '{FLOOR_NAME}' => $lease['floor_name'] ?? ($lease['floor_number'] ? 'Floor ' . $lease['floor_number'] : ''),
            
            // Lease Information
            '{LEASE_NUMBER}' => $lease['lease_number'] ?? 'L-' . $leaseId,
            '{START_DATE}' => $lease['start_date'] ? date('d/m/Y', strtotime($lease['start_date'])) : '',
            '{END_DATE}' => $lease['end_date'] ? date('d/m/Y', strtotime($lease['end_date'])) : '',
            '{START_DATE_LONG}' => $lease['start_date'] ? date('F d, Y', strtotime($lease['start_date'])) : '',
            '{END_DATE_LONG}' => $lease['end_date'] ? date('F d, Y', strtotime($lease['end_date'])) : '',
            '{ANNUAL_RENT}' => $lease['annual_rent'] ? number_format($lease['annual_rent'], 2) . ' AED' : '',
            '{MONTHLY_RENT}' => $lease['monthly_rent'] ? number_format($lease['monthly_rent'], 2) . ' AED' : '',
            '{SECURITY_DEPOSIT}' => $lease['security_deposit'] ? number_format($lease['security_deposit'], 2) . ' AED' : '',
            '{NUMBER_OF_INSTALLMENTS}' => $lease['number_of_installments'] ?? 12,
            '{PAYMENT_METHOD}' => ucfirst(str_replace('_', ' ', $lease['payment_method'] ?? '')),
            '{GRACE_PERIOD_DAYS}' => $lease['grace_period_days'] ?? 0,
            '{MOVE_IN_DATE}' => $lease['move_in_date'] ? date('d/m/Y', strtotime($lease['move_in_date'])) : '',
            
            // Additional Services
            '{ADDITIONAL_PARKING_FEE}' => $lease['additional_parking_fee'] ? number_format($lease['additional_parking_fee'], 2) . ' AED' : '',
            '{ADDITIONAL_PARKING_START}' => $lease['additional_parking_start_date'] ? date('d/m/Y', strtotime($lease['additional_parking_start_date'])) : '',
            '{ADDITIONAL_PARKING_END}' => $lease['additional_parking_end_date'] ? date('d/m/Y', strtotime($lease['additional_parking_end_date'])) : '',
            '{ADDITIONAL_STORE_FEE}' => $lease['additional_store_fee'] ? number_format($lease['additional_store_fee'], 2) . ' AED' : '',
            '{ADDITIONAL_STORE_START}' => $lease['additional_store_start_date'] ? date('d/m/Y', strtotime($lease['additional_store_start_date'])) : '',
            '{ADDITIONAL_STORE_END}' => $lease['additional_store_end_date'] ? date('d/m/Y', strtotime($lease['additional_store_end_date'])) : '',
            
            // Company Information
            '{COMPANY_NAME_SETTING}' => $companySettings['company_name'] ?? '',
            '{COMPANY_ADDRESS}' => $companySettings['address'] ?? '',
            '{COMPANY_PHONE}' => $companySettings['phone'] ?? '',
            '{COMPANY_EMAIL}' => $companySettings['email'] ?? '',
            
            // Dates
            '{TODAY_DATE}' => date('d/m/Y'),
            '{TODAY_DATE_LONG}' => date('F d, Y'),
            '{YEAR}' => date('Y'),
        ];
        
        // Replace placeholders
        $content = $template['template_content'];
        foreach ($replacements as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }
        
        return $content;
    }
}

if (!function_exists('format_contract_bilingual')) {
    /**
     * Format contract content for bilingual display (English left, Arabic right)
     * Pairs English and Arabic lines intelligently to match original document structure
     * @param string $content Contract content with placeholders replaced
     * @return string HTML formatted for two-column bilingual display
     */
    function format_contract_bilingual(string $content): string {
        $lines = explode("\n", $content);
        $rows = [];
        
        // Arabic character range
        $arabicPattern = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';
        
        $i = 0;
        while ($i < count($lines)) {
            $line = trim($lines[$i]);
            
            if (empty($line)) {
                $rows[] = ['english' => '', 'arabic' => ''];
                $i++;
                continue;
            }
            
            // Check if line contains Arabic
            $arabicCount = preg_match_all($arabicPattern, $line);
            $totalChars = mb_strlen($line);
            $isArabic = $arabicCount > ($totalChars * 0.2);
            
            if ($isArabic) {
                // Arabic line - collect consecutive Arabic lines
                $arabicBlock = [$line];
                $i++;
                
                while ($i < count($lines)) {
                    $nextLine = trim($lines[$i]);
                    if (empty($nextLine)) break;
                    
                    $nextArabicCount = preg_match_all($arabicPattern, $nextLine);
                    $nextTotalChars = mb_strlen($nextLine);
                    $nextIsArabic = $nextArabicCount > ($nextTotalChars * 0.2);
                    
                    if ($nextIsArabic) {
                        $arabicBlock[] = $nextLine;
                        $i++;
                    } else {
                        break;
                    }
                }
                
                // Look backwards for corresponding English (up to 10 lines)
                $englishBlock = [];
                $j = $i - count($arabicBlock) - 1;
                $lookBackCount = 0;
                
                while ($j >= 0 && $lookBackCount < 10) {
                    $prevLine = trim($lines[$j]);
                    if (empty($prevLine)) {
                        $j--;
                        $lookBackCount++;
                        continue;
                    }
                    
                    $prevArabicCount = preg_match_all($arabicPattern, $prevLine);
                    $prevTotalChars = mb_strlen($prevLine);
                    $prevIsArabic = $prevArabicCount > ($prevTotalChars * 0.2);
                    
                    if (!$prevIsArabic) {
                        array_unshift($englishBlock, $prevLine);
                        $lookBackCount++;
                    } else {
                        break;
                    }
                    $j--;
                }
                
                // Pair up the blocks
                $maxPairs = max(count($englishBlock), count($arabicBlock));
                for ($k = 0; $k < $maxPairs; $k++) {
                    $rows[] = [
                        'english' => $englishBlock[$k] ?? '',
                        'arabic' => $arabicBlock[$k] ?? ''
                    ];
                }
            } else {
                // English line - check if next lines are also English or if Arabic follows
                $englishBlock = [$line];
                $i++;
                
                // Collect consecutive English lines
                while ($i < count($lines)) {
                    $nextLine = trim($lines[$i]);
                    if (empty($nextLine)) {
                        $i++;
                        continue;
                    }
                    
                    $nextArabicCount = preg_match_all($arabicPattern, $nextLine);
                    $nextTotalChars = mb_strlen($nextLine);
                    $nextIsArabic = $nextArabicCount > ($nextTotalChars * 0.2);
                    
                    if (!$nextIsArabic) {
                        $englishBlock[] = $nextLine;
                        $i++;
                    } else {
                        // Next is Arabic - will be handled in next iteration
                        break;
                    }
                }
                
                // Check if Arabic follows immediately
                if ($i < count($lines)) {
                    $nextLine = trim($lines[$i]);
                    if (!empty($nextLine)) {
                        $nextArabicCount = preg_match_all($arabicPattern, $nextLine);
                        $nextTotalChars = mb_strlen($nextLine);
                        $nextIsArabic = $nextArabicCount > ($nextTotalChars * 0.2);
                        
                        if (!$nextIsArabic) {
                            // No Arabic following - add English-only rows
                            foreach ($englishBlock as $engLine) {
                                $rows[] = ['english' => $engLine, 'arabic' => ''];
                            }
                        }
                        // If Arabic follows, it will be handled in next iteration
                    }
                } else {
                    // End of content - add English-only rows
                    foreach ($englishBlock as $engLine) {
                        $rows[] = ['english' => $engLine, 'arabic' => ''];
                    }
                }
            }
        }
        
        // Build HTML with two-column layout
        $html = '<div class="contract-bilingual-wrapper">';
        $html .= '<div class="contract-english-column">';
        foreach ($rows as $row) {
            $html .= '<div class="contract-line">' . htmlspecialchars($row['english'], ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $html .= '</div>';
        $html .= '<div class="contract-arabic-column">';
        foreach ($rows as $row) {
            $html .= '<div class="contract-line">' . htmlspecialchars($row['arabic'], ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
}

if (!function_exists('get_available_placeholders')) {
    /**
     * Get list of all available placeholders
     * @return array List of placeholders with descriptions
     */
    function get_available_placeholders(): array {
        return [
            'Tenant Information' => [
                '{TENANT_NAME}' => 'Full tenant name (company name if company, or first+last name)',
                '{TENANT_FIRST_NAME}' => 'Tenant first name',
                '{TENANT_LAST_NAME}' => 'Tenant last name',
                '{TENANT_FULL_NAME}' => 'First name + Last name',
                '{TENANT_PHONE}' => 'Tenant phone number',
                '{TENANT_EMAIL}' => 'Tenant email address',
                '{TENANT_ID_NUMBER}' => 'Tenant ID/Passport number',
                '{TENANT_ID_TYPE}' => 'ID type (Emirates ID, Passport, etc.)',
                '{TENANT_ADDRESS}' => 'Tenant address',
                '{TENANT_TYPE}' => 'Individual or Company',
                '{COMPANY_NAME}' => 'Company name (if tenant is company)',
            ],
            'Unit Information' => [
                '{UNIT_NUMBER}' => 'Unit number',
                '{UNIT_TYPE}' => 'Unit type (STUDIO, 1BR, 2BR, etc.)',
                '{UNIT_AREA}' => 'Unit area in sqm',
                '{PREMISES_NUMBER}' => 'Premises number',
                '{PARKING_SLOT}' => 'Parking slot number',
                '{FURNITURE_STATUS}' => 'Furnished/Unfurnished',
            ],
            'Building Information' => [
                '{BUILDING_NAME}' => 'Building name',
                '{BUILDING_ADDRESS}' => 'Building address',
                '{FLOOR_NUMBER}' => 'Floor number',
                '{FLOOR_NAME}' => 'Floor name (e.g., Ground Floor)',
            ],
            'Lease Information' => [
                '{LEASE_NUMBER}' => 'Lease number',
                '{START_DATE}' => 'Lease start date (dd/mm/yyyy)',
                '{END_DATE}' => 'Lease end date (dd/mm/yyyy)',
                '{START_DATE_LONG}' => 'Lease start date (Month Day, Year)',
                '{END_DATE_LONG}' => 'Lease end date (Month Day, Year)',
                '{ANNUAL_RENT}' => 'Annual rent amount in AED',
                '{MONTHLY_RENT}' => 'Monthly rent amount in AED',
                '{SECURITY_DEPOSIT}' => 'Security deposit amount in AED',
                '{NUMBER_OF_INSTALLMENTS}' => 'Number of payment installments',
                '{PAYMENT_METHOD}' => 'Payment method',
                '{GRACE_PERIOD_DAYS}' => 'Grace period in days',
                '{MOVE_IN_DATE}' => 'Move-in date (dd/mm/yyyy)',
            ],
            'Additional Services' => [
                '{ADDITIONAL_PARKING_FEE}' => 'Additional parking monthly fee',
                '{ADDITIONAL_PARKING_START}' => 'Additional parking start date',
                '{ADDITIONAL_PARKING_END}' => 'Additional parking end date',
                '{ADDITIONAL_STORE_FEE}' => 'Additional store monthly fee',
                '{ADDITIONAL_STORE_START}' => 'Additional store start date',
                '{ADDITIONAL_STORE_END}' => 'Additional store end date',
            ],
            'Company Information' => [
                '{COMPANY_NAME_SETTING}' => 'Your company name',
                '{COMPANY_ADDRESS}' => 'Your company address',
                '{COMPANY_PHONE}' => 'Your company phone',
                '{COMPANY_EMAIL}' => 'Your company email',
            ],
            'Dates' => [
                '{TODAY_DATE}' => 'Today\'s date (dd/mm/yyyy)',
                '{TODAY_DATE_LONG}' => 'Today\'s date (Month Day, Year)',
                '{YEAR}' => 'Current year',
            ],
        ];
    }
}

