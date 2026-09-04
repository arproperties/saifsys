<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/construction_income_helpers.php';

function co_report_bootstrap(): array {
    global $conn;
    require_login();
    require_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_REPORTS, $conn);
    // ensure_current_company_supports_module runs inside require_department_access
    require_once __DIR__ . '/../../../includes/branding.php';

    $companyId = (int)(current_company_id($conn) ?: 0);
    if ($companyId <= 0) {
        http_response_code(400);
        die('Company context is required for Construction reports.');
    }

    return [
        'brand' => getBrandSettings($conn),
        'company_id' => $companyId,
    ];
}

function co_report_export(array $rows, array $columns, string $filename, string $title): void {
    require_once __DIR__ . '/../../realestate/accounting/export_excel_helper.php';
    $export = $_GET['export'] ?? '';
    if ($export === 'excel') {
        accounting_export_excel_or_csv($rows, $columns, $filename, $title);
    }
    if ($export === 'csv') {
        accounting_export_csv($rows, $columns, $filename);
    }
}

function co_report_export_buttons(array $params = []): string {
    $csv = http_build_query(array_merge($_GET, $params, ['export' => 'csv']));
    $excel = http_build_query(array_merge($_GET, $params, ['export' => 'excel']));
    return '<a class="btn btn-outline-primary btn-sm" href="?' . h($csv) . '"><i class="bi bi-download"></i> CSV</a> '
        . '<a class="btn btn-outline-success btn-sm" href="?' . h($excel) . '"><i class="bi bi-file-earmark-excel"></i> Excel</a> '
        . '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>';
}
