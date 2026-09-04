<?php
/**
 * Standalone Cleaning accounting context.
 *
 * The /accounts reports belong only to the Cleaning company accounting system.
 * Shared-engine companies use modules/realestate/accounting reports instead.
 */

function cleaning_accounting_company(PDO $conn): array
{
    static $company = null;
    if ($company !== null) {
        return $company;
    }

    $stmt = $conn->prepare("
        SELECT id, name
        FROM companies
        WHERE business_type = 'cleaning' AND is_active = 1
        ORDER BY id
        LIMIT 1
    ");
    $stmt->execute();
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => 1, 'name' => 'Cleaning Company'];
    $company['id'] = (int)$company['id'];

    return $company;
}

function cleaning_accounting_company_id(PDO $conn): int
{
    return (int)cleaning_accounting_company($conn)['id'];
}

function cleaning_accounting_company_name(PDO $conn): string
{
    return (string)cleaning_accounting_company($conn)['name'];
}
