<?php
/**
 * Construction Shop Rental Phase 1.6 — tenant commission (charged to tenant).
 * Contract-level. Uses existing co_client_invoices / payments / allocations.
 */

function co_shop_commission_schema_ready(PDO $conn): bool {
    return co_db_column_exists($conn, 'co_shop_rental_contracts', 'commission_net_amount')
        && co_db_column_exists($conn, 'co_shop_rental_contracts', 'commission_invoice_id');
}

function co_shop_ensure_commission_income_account(PDO $conn, int $companyId): ?array {
    $existing = find_account_by_code(CO_ACCOUNT_SHOP_COMMISSION_INCOME, $companyId);
    if ($existing) {
        return $existing;
    }
    $parent = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = '4000' LIMIT 1");
    $parent->execute([$companyId]);
    $parentId = (int)$parent->fetchColumn();
    if ($parentId <= 0) {
        return null;
    }
    $ins = $conn->prepare("
        INSERT INTO re_chart_of_accounts
            (company_id, account_code, account_name, account_type, normal_balance, parent_id, is_header, is_active, description)
        VALUES (?, ?, 'Shop Tenant Commission Income', 'Income', 'credit', ?, 0, 1, 'Commission / letting fee charged to shop tenants')
    ");
    try {
        $ins->execute([$companyId, CO_ACCOUNT_SHOP_COMMISSION_INCOME, $parentId]);
    } catch (Throwable $e) {
        return find_account_by_code(CO_ACCOUNT_SHOP_COMMISSION_INCOME, $companyId) ?: null;
    }
    return find_account_by_code(CO_ACCOUNT_SHOP_COMMISSION_INCOME, $companyId) ?: null;
}

/** Computed commission net from basis (ignores manual override flag). */
function co_shop_commission_compute_net(array $contract): float {
    $rent = (float)($contract['rent_amount'] ?? 0);
    $basis = ($contract['commission_basis'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
    if ($basis === 'fixed') {
        return max(0, round((float)($contract['commission_fixed_amount'] ?? 0), 2));
    }
    $pct = (float)($contract['commission_percent'] ?? 5);
    return max(0, round($rent * $pct / 100, 2));
}

/**
 * Authoritative net + VAT for a contract.
 * @return array{net:float,vat:float,gross:float,basis:string,percent:float,enabled:bool,vat_enabled:bool}
 */
function co_shop_commission_amounts(array $contract): array {
    $enabled = !empty($contract['commission_enabled']);
    $vatEnabled = !empty($contract['commission_vat_enabled']);
    $vatRate = (float)($contract['commission_vat_rate'] ?? 5);
    if (!empty($contract['commission_manual_override'])) {
        $net = max(0, round((float)($contract['commission_net_amount'] ?? 0), 2));
    } else {
        $net = co_shop_commission_compute_net($contract);
    }
    $vat = ($enabled && $vatEnabled && $net > 0) ? round($net * $vatRate / 100, 2) : 0.0;
    return [
        'enabled' => $enabled,
        'basis' => ($contract['commission_basis'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent',
        'percent' => (float)($contract['commission_percent'] ?? 5),
        'net' => $enabled ? $net : 0.0,
        'vat' => $enabled ? $vat : 0.0,
        'gross' => $enabled ? round($net + $vat, 2) : 0.0,
        'vat_enabled' => $vatEnabled,
        'vat_rate' => $vatRate,
        'manual_override' => !empty($contract['commission_manual_override']),
        'invoice_id' => !empty($contract['commission_invoice_id']) ? (int)$contract['commission_invoice_id'] : null,
    ];
}

/**
 * Commission invoice status for UI / control center.
 * @return array{status:string,invoiced:float,collected:float,outstanding:float,vat:float,net:float,invoice:?array}
 */
function co_shop_commission_status(PDO $conn, int $companyId, array $contract): array {
    $amt = co_shop_commission_amounts($contract);
    $out = [
        'status' => !$amt['enabled'] ? 'disabled' : 'not_invoiced',
        'invoiced' => 0.0,
        'collected' => 0.0,
        'outstanding' => 0.0,
        'vat' => $amt['vat'],
        'net' => $amt['net'],
        'gross' => $amt['gross'],
        'invoice' => null,
        'amounts' => $amt,
    ];
    $invoiceId = $amt['invoice_id'];
    if (!$invoiceId) {
        // Fallback: find by source
        $stmt = $conn->prepare("
            SELECT i.*
            FROM co_client_invoices i
            WHERE i.company_id = ? AND i.source_type = 'shop_commission' AND i.source_id = ? AND i.status <> 'cancelled'
            ORDER BY i.id DESC LIMIT 1
        ");
        $stmt->execute([$companyId, (int)$contract['id']]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $invoiceId = (int)$inv['id'];
        }
    } else {
        $stmt = $conn->prepare("SELECT * FROM co_client_invoices WHERE id = ? AND company_id = ? AND status <> 'cancelled'");
        $stmt->execute([$invoiceId, $companyId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (empty($inv)) {
        return $out;
    }
    $paid = 0.0;
    if (co_db_table_exists($conn, 'co_client_payment_allocations')) {
        $p = $conn->prepare("SELECT COALESCE(SUM(allocated_amount),0) FROM co_client_payment_allocations WHERE company_id = ? AND invoice_id = ?");
        $p->execute([$companyId, (int)$inv['id']]);
        $paid = (float)$p->fetchColumn();
    }
    $total = (float)$inv['total_amount'];
    $bal = max(0, round($total - $paid, 2));
    $inv['paid_amount'] = $paid;
    $inv['balance'] = $bal;
    $out['invoice'] = $inv;
    $out['invoiced'] = $total;
    $out['collected'] = $paid;
    $out['outstanding'] = $bal;
    $out['vat'] = (float)$inv['vat_amount'];
    $out['net'] = (float)$inv['subtotal'];
    $out['gross'] = $total;
    if ($bal <= 0.005) {
        $out['status'] = 'collected';
    } elseif ($paid > 0.005) {
        $out['status'] = 'partial';
    } else {
        $out['status'] = 'invoiced';
    }
    return $out;
}

/**
 * Persist commission settings on a contract (company-scoped).
 */
function co_shop_save_commission_settings(
    PDO $conn,
    int $companyId,
    int $contractId,
    array $input
): array {
    if (!co_shop_commission_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase16_commission.sql');
    }
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('Contract not found.');
    }
    if (!empty($contract['commission_invoice_id'])) {
        // Allow editing notes of VAT rate only? Safer: block amount changes after invoice
        throw new RuntimeException('Commission invoice already generated. Cancel/void that invoice before changing commission settings.');
    }

    $enabled = !empty($input['commission_enabled']) ? 1 : 0;
    $basis = (($input['commission_basis'] ?? 'percent') === 'fixed') ? 'fixed' : 'percent';
    $percent = max(0, (float)($input['commission_percent'] ?? 5));
    $fixed = max(0, (float)($input['commission_fixed_amount'] ?? 0));
    $manual = !empty($input['commission_manual_override']) ? 1 : 0;
    $vatEnabled = !empty($input['commission_vat_enabled']) ? 1 : 0;
    $vatRate = max(0, (float)($input['commission_vat_rate'] ?? 5));

    $contract['commission_enabled'] = $enabled;
    $contract['commission_basis'] = $basis;
    $contract['commission_percent'] = $percent;
    $contract['commission_fixed_amount'] = $fixed;
    $contract['commission_manual_override'] = $manual;
    $contract['commission_vat_enabled'] = $vatEnabled;
    $contract['commission_vat_rate'] = $vatRate;

    if ($manual) {
        $net = max(0, round((float)($input['commission_net_amount'] ?? 0), 2));
    } else {
        $net = co_shop_commission_compute_net($contract);
    }

    $conn->prepare("
        UPDATE co_shop_rental_contracts SET
            commission_enabled = ?,
            commission_basis = ?,
            commission_percent = ?,
            commission_fixed_amount = ?,
            commission_net_amount = ?,
            commission_manual_override = ?,
            commission_vat_enabled = ?,
            commission_vat_rate = ?
        WHERE id = ? AND company_id = ?
    ")->execute([
        $enabled, $basis, $percent, $fixed, $net, $manual, $vatEnabled, $vatRate,
        $contractId, $companyId,
    ]);

    $contract['commission_net_amount'] = $net;
    return co_shop_commission_amounts($contract);
}

/**
 * Create + post commission invoice via existing income workflow. No outer txn.
 * @return array{invoice_id:int,journal_id:int,amounts:array}
 */
function co_shop_generate_commission_invoice(
    PDO $conn,
    int $companyId,
    int $contractId,
    int $userId,
    ?string $invoiceDate = null
): array {
    if (!co_shop_commission_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase16_commission.sql');
    }
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('Contract not found.');
    }
    $amt = co_shop_commission_amounts($contract);
    if (!$amt['enabled']) {
        throw new RuntimeException('Commission is disabled on this contract.');
    }
    if ($amt['net'] <= 0) {
        throw new RuntimeException('Commission net amount must be greater than zero.');
    }
    if ($amt['invoice_id']) {
        throw new RuntimeException('Commission invoice already exists for this contract.');
    }
    $dup = $conn->prepare("
        SELECT id FROM co_client_invoices
        WHERE company_id = ? AND source_type = 'shop_commission' AND source_id = ? AND status <> 'cancelled'
        LIMIT 1
    ");
    $dup->execute([$companyId, $contractId]);
    if ($dup->fetchColumn()) {
        throw new RuntimeException('A commission invoice already exists for this contract.');
    }

    $incomeAccount = co_shop_ensure_commission_income_account($conn, $companyId);
    if (!$incomeAccount) {
        throw new RuntimeException('Commission income account 4140 not found. Run Construction → Setup Accounts.');
    }
    $incomeAccountId = (int)$incomeAccount['id'];
    $date = $invoiceDate ?: date('Y-m-d');
    $shops = function_exists('co_shop_contract_shops_label')
        ? co_shop_contract_shops_label($conn, $companyId, $contractId)
        : '';
    $desc = 'Shop tenant commission ' . ($contract['contract_number'] ?? ('#' . $contractId))
        . ($shops ? (' · ' . $shops) : '');

    $invoiceNumber = co_next_document_number($conn, $companyId, 'SHOP-COM', 'co_client_invoices', 'invoice_number');
    $invoiceId = co_create_income_invoice(
        $conn,
        $companyId,
        (int)$contract['client_id'],
        0,
        'shop_commission',
        $contractId,
        $invoiceNumber,
        $date,
        $date,
        $amt['net'],
        $amt['vat'],
        $desc,
        $incomeAccountId,
        $userId
    );
    $postResult = co_post_client_invoice_to_accounting($invoiceId, $companyId, $userId);
    if (empty($postResult['success'])) {
        throw new RuntimeException($postResult['error'] ?? 'Commission invoice posting failed.');
    }
    $conn->prepare("UPDATE co_client_invoices SET journal_id = ? WHERE id = ? AND company_id = ?")
        ->execute([(int)$postResult['journal_id'], $invoiceId, $companyId]);
    $conn->prepare("UPDATE co_shop_rental_contracts SET commission_invoice_id = ?, commission_net_amount = ? WHERE id = ? AND company_id = ?")
        ->execute([$invoiceId, $amt['net'], $contractId, $companyId]);

    if (file_exists(__DIR__ . '/construction_shop_rental_charge_helpers.php')) {
        require_once __DIR__ . '/construction_shop_rental_charge_helpers.php';
        if (function_exists('co_shop_contract_charge_by_code') && function_exists('co_shop_link_invoice_to_charge')) {
            $commCharge = co_shop_contract_charge_by_code($conn, $companyId, $contractId, 'commission');
            if ($commCharge) {
                co_shop_link_invoice_to_charge($conn, $companyId, $invoiceId, (int)$commCharge['id']);
                $conn->prepare("UPDATE co_shop_contract_charges SET status = 'invoiced' WHERE id = ? AND company_id = ?")
                    ->execute([(int)$commCharge['id'], $companyId]);
            }
        }
    }

    return [
        'invoice_id' => $invoiceId,
        'journal_id' => (int)$postResult['journal_id'],
        'amounts' => $amt,
        'invoice_number' => $invoiceNumber,
    ];
}
