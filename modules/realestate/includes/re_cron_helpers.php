<?php
/**
 * Shared helpers for Real Estate CLI / URL-triggered cron scripts.
 */

if (!function_exists('re_cron_guard')) {
    /**
     * Exit unless CLI or ?run_cron=1 (same pattern as amc_alert_cron.php).
     */
    function re_cron_guard(): void {
        if (php_sapi_name() !== 'cli' && !isset($_GET['run_cron'])) {
            die('This script should be run via CLI or with ?run_cron=1');
        }
    }
}

if (!function_exists('re_cron_active_company_ids')) {
    /**
     * @return int[]
     */
    function re_cron_active_company_ids(PDO $conn): array {
        $stmt = $conn->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id");
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }
}

if (!function_exists('re_cron_management_emails')) {
    /**
     * Same audience as lease expiry reminders: company users with Real Estate or Owner/Admin.
     *
     * @return string[] distinct valid emails
     */
    function re_cron_management_emails(PDO $conn, int $companyId): array {
        $stmt = $conn->prepare("
            SELECT DISTINCT u.email
            FROM user u
            JOIN user_companies uc ON uc.user_id = u.id
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            WHERE uc.company_id = ?
              AND (r.module = 'realestate' OR r.name IN ('Owner', 'Admin'))
              AND u.email IS NOT NULL AND TRIM(u.email) != ''
        ");
        $stmt->execute([$companyId]);
        $emails = array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN, 0)));
        return array_values(array_unique(array_filter($emails, function ($e) {
            return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
        })));
    }
}

if (!function_exists('re_cron_owner_emails')) {
    /**
     * Comma-separated owner emails from app_email_settings (global row id=1).
     *
     * @return string[]
     */
    function re_cron_owner_emails(PDO $conn): array {
        $row = $conn->query("SELECT owner_emails FROM app_email_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['owner_emails'])) {
            return [];
        }
        $parts = array_map('trim', explode(',', (string)$row['owner_emails']));
        return array_values(array_unique(array_filter($parts, function ($e) {
            return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
        })));
    }
}

if (!function_exists('re_cron_digest_recipients')) {
    /**
     * Management users for company + global owner emails (deduped).
     *
     * @return string[]
     */
    function re_cron_digest_recipients(PDO $conn, int $companyId): array {
        $a = re_cron_management_emails($conn, $companyId);
        $b = re_cron_owner_emails($conn);
        return array_values(array_unique(array_merge($a, $b)));
    }
}
