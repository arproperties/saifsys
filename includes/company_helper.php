<?php
/**
 * Company Helper Functions
 * Provides utilities for company context management
 */

/**
 * Get current company ID from session or default
 */
function current_company_id(PDO $conn = null): ?int {
    // Check session first
    if (!empty($_SESSION['current_company_id'])) {
        return (int)$_SESSION['current_company_id'];
    }
    
    // Get from user's default company
    $userId = current_user_id();
    if ($userId && $conn instanceof PDO) {
        $stmt = $conn->prepare("SELECT default_company_id FROM user WHERE id = ?");
        $stmt->execute([$userId]);
        $defaultCompanyId = $stmt->fetchColumn();
        
        if ($defaultCompanyId) {
            $_SESSION['current_company_id'] = (int)$defaultCompanyId;
            return (int)$defaultCompanyId;
        }
        
        // Get primary company from user_companies
        $stmt = $conn->prepare("
            SELECT company_id FROM user_companies 
            WHERE user_id = ? AND is_primary = 1 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $primaryCompanyId = $stmt->fetchColumn();
        
        if ($primaryCompanyId) {
            $_SESSION['current_company_id'] = (int)$primaryCompanyId;
            return (int)$primaryCompanyId;
        }
    }
    
    return null;
}

/**
 * Set current company in session
 */
function set_current_company(int $companyId): void {
    $_SESSION['current_company_id'] = $companyId;
}

/**
 * Get user's accessible companies
 */
function get_user_companies(PDO $conn, int $userId): array {
    $stmt = $conn->prepare("
        SELECT c.id, c.name, c.code, c.business_type, c.is_active, uc.is_primary
        FROM user_companies uc
        JOIN companies c ON c.id = uc.company_id
        WHERE uc.user_id = ? AND c.is_active = 1
        ORDER BY uc.is_primary DESC, c.name ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Get company details
 */
function get_company(PDO $conn, int $companyId): ?array {
    $stmt = $conn->prepare("
        SELECT id, name, code, business_type, is_active, created_at, updated_at
        FROM companies
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Ensure company_settings.whatsapp exists (additive; safe to call repeatedly).
 */
function company_settings_ensure_whatsapp_column(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM company_settings LIKE 'whatsapp'");
        if ($stmt && !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("
                ALTER TABLE company_settings
                ADD COLUMN whatsapp VARCHAR(50) NULL DEFAULT NULL
                AFTER website
            ");
        }
        $done = true;
    } catch (Throwable $e) {
        // Fail soft — page/API still works without WhatsApp until migration is applied.
        error_log('company_settings_ensure_whatsapp_column: ' . $e->getMessage());
    }
}

/**
 * Company contact channels for customer/tenant apps (Settings → Company Information).
 * Scoped by company_id. Empty strings when unset — never invent values.
 *
 * @return array{phone:string,email:string,whatsapp:string,website:string,legal_name:string}
 */
function company_settings_contact_for_company(PDO $conn, int $companyId): array {
    $empty = [
        'phone' => '',
        'email' => '',
        'whatsapp' => '',
        'website' => '',
        'legal_name' => '',
    ];
    if ($companyId <= 0) {
        return $empty;
    }
    company_settings_ensure_whatsapp_column($conn);
    try {
        $stmt = $conn->prepare("
            SELECT phone, email, whatsapp, website, legal_name
            FROM company_settings
            WHERE company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $empty;
        }
        return [
            'phone' => trim((string)($row['phone'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'whatsapp' => trim((string)($row['whatsapp'] ?? '')),
            'website' => trim((string)($row['website'] ?? '')),
            'legal_name' => trim((string)($row['legal_name'] ?? '')),
        ];
    } catch (Throwable $e) {
        // Older DBs without whatsapp: retry without that column.
        try {
            $stmt = $conn->prepare("
                SELECT phone, email, website, legal_name
                FROM company_settings
                WHERE company_id = ?
                LIMIT 1
            ");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'phone' => trim((string)($row['phone'] ?? '')),
                'email' => trim((string)($row['email'] ?? '')),
                'whatsapp' => '',
                'website' => trim((string)($row['website'] ?? '')),
                'legal_name' => trim((string)($row['legal_name'] ?? '')),
            ];
        } catch (Throwable $e2) {
            error_log('company_settings_contact_for_company: ' . $e2->getMessage());
            return $empty;
        }
    }
}

/**
 * Check if user has access to company
 */
function user_has_company_access(PDO $conn, int $userId, int $companyId): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM user_companies uc
        JOIN companies c ON c.id = uc.company_id
        WHERE uc.user_id = ? AND uc.company_id = ? AND c.is_active = 1
    ");
    $stmt->execute([$userId, $companyId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Get all companies (for Owner/Admin)
 */
function get_all_companies(PDO $conn, bool $activeOnly = true): array {
    $sql = "SELECT id, name, code, business_type, is_active, created_at, updated_at FROM companies";
    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY name ASC";
    
    $stmt = $conn->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Get company settings
 */
function get_company_settings(PDO $conn, int $companyId): ?array {
    $stmt = $conn->prepare("
        SELECT * FROM company_settings 
        WHERE company_id = ? 
        LIMIT 1
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

