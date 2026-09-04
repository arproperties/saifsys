<?php
/**
 * Payroll run access: Owner/Admin see all companies; others limited to current company context.
 */
require_once dirname(__DIR__, 2) . '/includes/company_helper.php';

function hr_payroll_can_access_company(PDO $conn, int $runCompanyId): bool
{
    if (function_exists('has_role') && (has_role('Owner', $conn) || has_role('Admin', $conn))) {
        return true;
    }
    $ctx = (int)(current_company_id($conn) ?: 1);
    return $runCompanyId === $ctx;
}

function hr_payroll_require_run_access(PDO $conn, array $run): void
{
    $cid = (int)($run['company_id'] ?? 1);
    if (!hr_payroll_can_access_company($conn, $cid)) {
        http_response_code(403);
        exit('You do not have access to payroll for this company.');
    }
}
