<?php
/**
 * Shared HR company scope helpers.
 *
 * HR is a shared module: Owner/Admin/HR may view all companies, but pages can
 * optionally filter to one company to keep mixed-company data clear.
 */

function hr_active_companies(PDO $conn): array
{
    $stmt = $conn->query("SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function hr_selected_company_id(PDO $conn, array $companies, string $param = 'company_id'): int
{
    $selected = isset($_GET[$param]) ? (int)$_GET[$param] : 0;
    if ($selected <= 0) {
        return 0;
    }

    foreach ($companies as $company) {
        if ((int)$company['id'] === $selected) {
            return $selected;
        }
    }

    return 0;
}

function hr_company_scope_label(array $companies, int $companyId): string
{
    if ($companyId <= 0) {
        return 'All companies';
    }

    foreach ($companies as $company) {
        if ((int)$company['id'] === $companyId) {
            return (string)$company['name'];
        }
    }

    return 'Selected company';
}

function hr_add_company_where(array &$where, array &$params, int $companyId, string $column = 'e.company_id'): void
{
    if ($companyId > 0) {
        $where[] = $column . ' = ?';
        $params[] = $companyId;
    }
}
