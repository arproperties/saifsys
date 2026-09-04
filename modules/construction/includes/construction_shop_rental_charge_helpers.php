<?php
/**
 * Construction Shop Rental — Contract Charges (Payment Maturity Phase 1 + Key Money).
 * Company-scoped catalogue + per-contract lines. Syncs with legacy rent/deposit/commission columns.
 * Key Money is one-time AR revenue (BR-CO-SHOP-KEY-MONEY-001): invoice via shop_charge, income 4170.
 * Does not touch Real Estate allocation or accounting_engine behaviour.
 */

require_once __DIR__ . '/construction_helpers.php';
require_once __DIR__ . '/construction_income_helpers.php';

const CO_SHOP_CHARGE_RENT = 'rent';
const CO_SHOP_CHARGE_DEPOSIT = 'security_deposit';
const CO_SHOP_CHARGE_COMMISSION = 'commission';
const CO_SHOP_CHARGE_KEY_MONEY = 'key_money';
const CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE = 'immediate';
const CO_SHOP_KEY_MONEY_TIMING_ON_START = 'on_start';

function co_shop_charges_schema_ready(PDO $conn): bool {
    return co_db_table_exists($conn, 'co_shop_charge_types')
        && co_db_table_exists($conn, 'co_shop_contract_charges');
}

/**
 * Active (non-cancelled) commission invoice for a shop rental contract, if any.
 * @return array<string,mixed>|null
 */
function co_shop_contract_commission_invoice(PDO $conn, int $companyId, int $contractId): ?array {
    if ($companyId <= 0 || $contractId <= 0 || !co_db_table_exists($conn, 'co_client_invoices')) {
        return null;
    }
    $invoiceId = 0;
    if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'commission_invoice_id')) {
        $stmt = $conn->prepare("SELECT commission_invoice_id FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
        $stmt->execute([$contractId, $companyId]);
        $invoiceId = (int)$stmt->fetchColumn();
    }
    if ($invoiceId > 0) {
        $stmt = $conn->prepare("SELECT * FROM co_client_invoices WHERE id = ? AND company_id = ? AND status <> 'cancelled' LIMIT 1");
        $stmt->execute([$invoiceId, $companyId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            return $inv;
        }
    }
    $stmt = $conn->prepare("
        SELECT * FROM co_client_invoices
        WHERE company_id = ? AND source_type = 'shop_commission' AND source_id = ? AND status <> 'cancelled'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$companyId, $contractId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    return $inv ?: null;
}

/**
 * Seed system charge types for a company (idempotent).
 */
function co_shop_seed_charge_types(PDO $conn, int $companyId): void {
    if ($companyId <= 0 || !co_shop_charges_schema_ready($conn)) {
        return;
    }
    $seeds = [
        [
            'code' => CO_SHOP_CHARGE_RENT,
            'name' => 'Rent',
            'nature' => 'income',
            'default_coa_code' => '4120',
            'vat_eligible' => 1,
            'is_system' => 1,
            'is_active' => 1,
            'invoicing_enabled' => 1,
            'allocation_priority' => 10,
            'sort_order' => 10,
        ],
        [
            'code' => CO_SHOP_CHARGE_COMMISSION,
            'name' => 'Tenant Commission',
            'nature' => 'income',
            'default_coa_code' => '4140',
            'vat_eligible' => 1,
            'is_system' => 1,
            'is_active' => 1,
            'invoicing_enabled' => 1,
            'allocation_priority' => 20,
            'sort_order' => 20,
        ],
        [
            'code' => CO_SHOP_CHARGE_KEY_MONEY,
            'name' => 'Key Money',
            'nature' => 'income',
            'default_coa_code' => '4170',
            'vat_eligible' => 1,
            'is_system' => 1,
            'is_active' => 1,
            'invoicing_enabled' => 1,
            'allocation_priority' => 30,
            'sort_order' => 30,
        ],
        [
            'code' => CO_SHOP_CHARGE_DEPOSIT,
            'name' => 'Security Deposit',
            'nature' => 'liability',
            'default_coa_code' => '2200',
            'vat_eligible' => 0,
            'is_system' => 1,
            'is_active' => 1,
            'invoicing_enabled' => 0, // Liability receipt path, not AR invoice
            'allocation_priority' => 90,
            'sort_order' => 90,
        ],
    ];
    $ins = $conn->prepare("
        INSERT INTO co_shop_charge_types
            (company_id, code, name, nature, default_coa_code, vat_eligible, is_system, is_active, invoicing_enabled, allocation_priority, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            nature = VALUES(nature),
            default_coa_code = COALESCE(co_shop_charge_types.default_coa_code, VALUES(default_coa_code)),
            vat_eligible = VALUES(vat_eligible),
            is_system = 1,
            invoicing_enabled = VALUES(invoicing_enabled),
            allocation_priority = VALUES(allocation_priority),
            sort_order = VALUES(sort_order)
    ");
    foreach ($seeds as $s) {
        $ins->execute([
            $companyId,
            $s['code'],
            $s['name'],
            $s['nature'],
            $s['default_coa_code'],
            $s['vat_eligible'],
            $s['is_system'],
            $s['is_active'],
            $s['invoicing_enabled'],
            $s['allocation_priority'],
            $s['sort_order'],
        ]);
    }
}

/** @return list<array<string,mixed>> */
function co_shop_list_charge_types(PDO $conn, int $companyId, bool $activeOnly = true): array {
    if (!co_shop_charges_schema_ready($conn)) {
        return [];
    }
    co_shop_seed_charge_types($conn, $companyId);
    $sql = "SELECT * FROM co_shop_charge_types WHERE company_id = ?";
    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function co_shop_charge_type_by_code(PDO $conn, int $companyId, string $code): ?array {
    if (!co_shop_charges_schema_ready($conn)) {
        return null;
    }
    co_shop_seed_charge_types($conn, $companyId);
    $stmt = $conn->prepare("SELECT * FROM co_shop_charge_types WHERE company_id = ? AND code = ? LIMIT 1");
    $stmt->execute([$companyId, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Recalculate VAT/gross for a charge line.
 * @return array{vat_amount:float,gross_amount:float,vat_mode:string}
 */
function co_shop_charge_compute_vat(float $amount, string $vatMode, float $vatRate): array {
    $amount = max(0, round($amount, 2));
    $vatRate = max(0, (float)$vatRate);
    $vatMode = in_array($vatMode, ['exclusive', 'inclusive', 'none'], true) ? $vatMode : 'exclusive';
    if ($vatMode === 'none' || $vatRate <= 0 || $amount <= 0) {
        return ['vat_amount' => 0.0, 'gross_amount' => $amount, 'vat_mode' => $vatMode === 'none' ? 'none' : $vatMode];
    }
    if ($vatMode === 'inclusive') {
        $gross = $amount;
        $net = round($gross / (1 + $vatRate / 100), 2);
        $vat = round($gross - $net, 2);
        return ['vat_amount' => $vat, 'gross_amount' => $gross, 'vat_mode' => 'inclusive', 'net_amount' => $net];
    }
    $vat = round($amount * $vatRate / 100, 2);
    return ['vat_amount' => $vat, 'gross_amount' => round($amount + $vat, 2), 'vat_mode' => 'exclusive'];
}

/**
 * Upsert a contract charge row by type code.
 * @param string|null $invoiceTiming immediate|on_start (persisted when column exists)
 */
function co_shop_upsert_contract_charge(
    PDO $conn,
    int $companyId,
    int $contractId,
    string $typeCode,
    float $amount,
    string $vatMode,
    float $vatRate,
    string $status = 'active',
    ?string $notes = null,
    ?int $userId = null,
    ?string $glOverride = null,
    ?string $invoiceTiming = null
): int {
    if ($companyId <= 0 || $contractId <= 0) {
        throw new RuntimeException('Company and contract are required.');
    }
    if (!co_shop_charges_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase_charges.sql');
    }
    $type = co_shop_charge_type_by_code($conn, $companyId, $typeCode);
    if (!$type) {
        throw new RuntimeException('Unknown charge type: ' . $typeCode);
    }
    $vat = co_shop_charge_compute_vat($amount, $vatMode, $vatRate);
    if (($type['nature'] ?? '') === 'liability' || empty($type['vat_eligible'])) {
        $vat = ['vat_amount' => 0.0, 'gross_amount' => round($amount, 2), 'vat_mode' => 'none'];
        $vatMode = 'none';
        $vatRate = 0.0;
    }
    $netStore = $amount;
    if (($vat['vat_mode'] ?? '') === 'inclusive' && isset($vat['net_amount'])) {
        $netStore = (float)$vat['net_amount'];
    }
    $hasTiming = co_db_column_exists($conn, 'co_shop_contract_charges', 'invoice_timing');
    $timing = in_array((string)$invoiceTiming, [CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE, CO_SHOP_KEY_MONEY_TIMING_ON_START], true)
        ? (string)$invoiceTiming
        : CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE;

    $existing = $conn->prepare("
        SELECT id FROM co_shop_contract_charges
        WHERE company_id = ? AND contract_id = ? AND charge_type_id = ?
        LIMIT 1
    ");
    $existing->execute([$companyId, $contractId, (int)$type['id']]);
    $id = (int)$existing->fetchColumn();
    if ($id > 0) {
        if ($hasTiming && $invoiceTiming !== null) {
            $upd = $conn->prepare("
                UPDATE co_shop_contract_charges
                SET amount = ?, vat_mode = ?, vat_rate = ?, vat_amount = ?, gross_amount = ?,
                    gl_account_override = ?, status = ?, notes = ?, sort_order = ?, invoice_timing = ?
                WHERE id = ? AND company_id = ?
            ");
            $upd->execute([
                round($netStore, 2),
                $vat['vat_mode'],
                $vatRate,
                $vat['vat_amount'],
                $vat['gross_amount'],
                $glOverride,
                $status,
                $notes,
                (int)$type['sort_order'],
                $timing,
                $id,
                $companyId,
            ]);
        } else {
            $upd = $conn->prepare("
                UPDATE co_shop_contract_charges
                SET amount = ?, vat_mode = ?, vat_rate = ?, vat_amount = ?, gross_amount = ?,
                    gl_account_override = ?, status = ?, notes = ?, sort_order = ?
                WHERE id = ? AND company_id = ?
            ");
            $upd->execute([
                round($netStore, 2),
                $vat['vat_mode'],
                $vatRate,
                $vat['vat_amount'],
                $vat['gross_amount'],
                $glOverride,
                $status,
                $notes,
                (int)$type['sort_order'],
                $id,
                $companyId,
            ]);
        }
        return $id;
    }
    if ($hasTiming) {
        $ins = $conn->prepare("
            INSERT INTO co_shop_contract_charges
                (company_id, contract_id, charge_type_id, amount, vat_mode, vat_rate, vat_amount, gross_amount,
                 gl_account_override, status, notes, invoice_timing, sort_order, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $companyId,
            $contractId,
            (int)$type['id'],
            round($netStore, 2),
            $vat['vat_mode'],
            $vatRate,
            $vat['vat_amount'],
            $vat['gross_amount'],
            $glOverride,
            $status,
            $notes,
            $timing,
            (int)$type['sort_order'],
            $userId,
        ]);
    } else {
        $ins = $conn->prepare("
            INSERT INTO co_shop_contract_charges
                (company_id, contract_id, charge_type_id, amount, vat_mode, vat_rate, vat_amount, gross_amount,
                 gl_account_override, status, notes, sort_order, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $companyId,
            $contractId,
            (int)$type['id'],
            round($netStore, 2),
            $vat['vat_mode'],
            $vatRate,
            $vat['vat_amount'],
            $vat['gross_amount'],
            $glOverride,
            $status,
            $notes,
            (int)$type['sort_order'],
            $userId,
        ]);
    }
    return (int)$conn->lastInsertId();
}

/**
 * Sync system charge rows from legacy contract columns (idempotent).
 */
function co_shop_sync_contract_charges_from_legacy(PDO $conn, int $companyId, int $contractId, ?int $userId = null): void {
    if (!co_shop_charges_schema_ready($conn) || $companyId <= 0 || $contractId <= 0) {
        return;
    }
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return;
    }
    co_shop_seed_charge_types($conn, $companyId);

    $vatMode = (($contract['vat_mode'] ?? 'exclusive') === 'inclusive') ? 'inclusive' : 'exclusive';
    $vatRate = (float)($contract['vat_rate'] ?? 5);
    $rent = (float)($contract['rent_amount'] ?? 0);
    co_shop_upsert_contract_charge(
        $conn, $companyId, $contractId, CO_SHOP_CHARGE_RENT, $rent, $vatMode, $vatRate,
        $rent > 0 ? 'active' : 'disabled', null, $userId
    );

    $deposit = (float)($contract['security_deposit'] ?? 0);
    co_shop_upsert_contract_charge(
        $conn, $companyId, $contractId, CO_SHOP_CHARGE_DEPOSIT, $deposit, 'none', 0,
        $deposit > 0 ? 'active' : 'disabled', null, $userId
    );

    if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'commission_net_amount')) {
        $commInv = co_shop_contract_commission_invoice($conn, $companyId, $contractId);
        $commEnabled = !empty($contract['commission_enabled']);
        $commNet = 0.0;
        $commVatEnabled = !empty($contract['commission_vat_enabled']);
        $commVatRate = $commVatEnabled ? (float)($contract['commission_vat_rate'] ?? 5) : 0.0;
        $commVatMode = $commVatEnabled ? 'exclusive' : 'none';
        $commStatus = ($commEnabled && $commNet > 0) ? 'active' : 'disabled';

        if ($commInv) {
            // Posted invoice is source of truth — realign charge + legacy net (do not leave drift).
            $commNet = round((float)($commInv['subtotal'] ?? 0), 2);
            $invVat = round((float)($commInv['vat_amount'] ?? 0), 2);
            $commVatEnabled = $invVat > 0.005;
            $commVatRate = ($commVatEnabled && $commNet > 0)
                ? round(($invVat / $commNet) * 100, 2)
                : 0.0;
            $commVatMode = $commVatEnabled ? 'exclusive' : 'none';
            $commStatus = 'invoiced';
            $commEnabled = 1;
            $conn->prepare("
                UPDATE co_shop_rental_contracts
                SET commission_net_amount = ?, commission_enabled = 1,
                    commission_vat_enabled = ?, commission_vat_rate = ?
                WHERE id = ? AND company_id = ?
            ")->execute([
                $commNet,
                $commVatEnabled ? 1 : 0,
                $commVatRate,
                $contractId,
                $companyId,
            ]);
        } elseif ($commEnabled) {
            if (function_exists('co_shop_commission_amounts')) {
                $commNet = (float)(co_shop_commission_amounts($contract)['net'] ?? 0);
            } else {
                $commNet = (float)($contract['commission_net_amount'] ?? 0);
            }
            $commStatus = ($commNet > 0) ? 'active' : 'disabled';
        }

        co_shop_upsert_contract_charge(
            $conn, $companyId, $contractId, CO_SHOP_CHARGE_COMMISSION, $commNet, $commVatMode, $commVatRate,
            $commStatus, null, $userId
        );
    }

    // Ensure key_money row exists; never wipe an already-configured Key Money charge
    $kmExisting = co_shop_contract_charge_by_code($conn, $companyId, $contractId, CO_SHOP_CHARGE_KEY_MONEY);
    if (!$kmExisting) {
        co_shop_upsert_contract_charge(
            $conn, $companyId, $contractId, CO_SHOP_CHARGE_KEY_MONEY, 0, 'none', 0,
            'disabled', null, $userId, null, CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE
        );
    }
}

/** @return list<array<string,mixed>> */
function co_shop_list_contract_charges(PDO $conn, int $companyId, int $contractId): array {
    if (!co_shop_charges_schema_ready($conn)) {
        return [];
    }
    $stmt = $conn->prepare("
        SELECT cc.*, ct.code AS charge_code, ct.name AS charge_name, ct.nature, ct.default_coa_code,
               ct.vat_eligible, ct.is_system, ct.invoicing_enabled, ct.allocation_priority
        FROM co_shop_contract_charges cc
        JOIN co_shop_charge_types ct ON ct.id = cc.charge_type_id AND ct.company_id = cc.company_id
        WHERE cc.company_id = ? AND cc.contract_id = ?
        ORDER BY ct.sort_order ASC, cc.id ASC
    ");
    $stmt->execute([$companyId, $contractId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function co_shop_contract_charge_by_code(PDO $conn, int $companyId, int $contractId, string $code): ?array {
    foreach (co_shop_list_contract_charges($conn, $companyId, $contractId) as $row) {
        if (($row['charge_code'] ?? '') === $code) {
            return $row;
        }
    }
    return null;
}

/**
 * Apply charge edits from UI; sync system charges back to legacy contract columns.
 * @param list<array{id:int,amount:float,vat_mode?:string,vat_rate?:float,status?:string,notes?:string,gl_account_override?:string}> $rows
 */
function co_shop_save_contract_charges(PDO $conn, int $companyId, int $contractId, array $rows, ?int $userId = null): void {
    if ($companyId <= 0 || $contractId <= 0) {
        throw new RuntimeException('Company and contract are required.');
    }
    if (!co_shop_charges_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase_charges.sql');
    }
    $byId = [];
    foreach (co_shop_list_contract_charges($conn, $companyId, $contractId) as $c) {
        $byId[(int)$c['id']] = $c;
    }
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0 || empty($byId[$id])) {
            continue;
        }
        $cur = $byId[$id];
        $code = (string)$cur['charge_code'];
        if ($code === CO_SHOP_CHARGE_KEY_MONEY) {
            if (($cur['status'] ?? '') === 'invoiced') {
                continue; // immutable once invoiced
            }
            $amount = max(0, round((float)($row['amount'] ?? 0), 2));
            $vatMode = (string)($row['vat_mode'] ?? $cur['vat_mode'] ?? 'exclusive');
            $vatRate = (float)($row['vat_rate'] ?? $cur['vat_rate'] ?? 5);
            $status = (string)($row['status'] ?? ($amount > 0 ? 'active' : 'disabled'));
            if (!in_array($status, ['active', 'disabled', 'cancelled'], true)) {
                $status = $amount > 0 ? 'active' : 'disabled';
            }
            if ($amount <= 0) {
                $status = 'disabled';
            }
            $notes = isset($row['notes']) ? trim((string)$row['notes']) : ($cur['notes'] ?? null);
            $gl = isset($row['gl_account_override']) ? trim((string)$row['gl_account_override']) : ($cur['gl_account_override'] ?? null);
            if ($gl === '') {
                $gl = null;
            }
            $timing = (string)($row['invoice_timing'] ?? $cur['invoice_timing'] ?? CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE);
            if (!in_array($timing, [CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE, CO_SHOP_KEY_MONEY_TIMING_ON_START], true)) {
                $timing = CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE;
            }
            co_shop_upsert_contract_charge(
                $conn, $companyId, $contractId, CO_SHOP_CHARGE_KEY_MONEY, $amount, $vatMode, $vatRate,
                $status, $notes ?: null, $userId, $gl, $timing
            );
            continue;
        }
        $amount = max(0, round((float)($row['amount'] ?? $cur['amount']), 2));
        $vatMode = (string)($row['vat_mode'] ?? $cur['vat_mode'] ?? 'exclusive');
        $vatRate = (float)($row['vat_rate'] ?? $cur['vat_rate'] ?? 0);
        $status = (string)($row['status'] ?? $cur['status'] ?? 'active');
        if (!in_array($status, ['active', 'disabled', 'invoiced', 'cancelled'], true)) {
            $status = 'active';
        }
        $notes = isset($row['notes']) ? trim((string)$row['notes']) : ($cur['notes'] ?? null);
        $gl = isset($row['gl_account_override']) ? trim((string)$row['gl_account_override']) : ($cur['gl_account_override'] ?? null);
        if ($gl === '') {
            $gl = null;
        }
        if (!empty($cur['is_system']) && $code === CO_SHOP_CHARGE_DEPOSIT) {
            $vatMode = 'none';
            $vatRate = 0;
        }

        // Match Commission tab: posted AR invoices are immutable — do not drift charge/legacy amounts.
        $commissionLocked = ($code === CO_SHOP_CHARGE_COMMISSION)
            && co_shop_contract_commission_invoice($conn, $companyId, $contractId);
        $chargeLocked = (($cur['status'] ?? '') === 'invoiced') || $commissionLocked;
        if ($chargeLocked) {
            $curAmount = round((float)$cur['amount'], 2);
            $curVatMode = (string)($cur['vat_mode'] ?? 'exclusive');
            $curVatRate = round((float)($cur['vat_rate'] ?? 0), 2);
            $amountChanged = abs($amount - $curAmount) > 0.005
                || $vatMode !== $curVatMode
                || abs(round($vatRate, 2) - $curVatRate) > 0.005;
            if ($amountChanged) {
                throw new RuntimeException(
                    'This charge is already invoiced. Posted invoices are not updated from Charges. '
                    . 'Cancel/void the invoice (then re-invoice) before changing amounts — same rule as the Commission tab.'
                );
            }
            // Keep status invoiced; allow only non-amount metadata if needed later
            $status = 'invoiced';
            $amount = $curAmount;
            $vatMode = $curVatMode;
            $vatRate = $curVatRate;
        }

        co_shop_upsert_contract_charge(
            $conn, $companyId, $contractId, $code, $amount, $vatMode, $vatRate, $status, $notes ?: null, $userId, $gl
        );
    }

    // Sync legacy columns from system charges
    $rent = co_shop_contract_charge_by_code($conn, $companyId, $contractId, CO_SHOP_CHARGE_RENT);
    $dep = co_shop_contract_charge_by_code($conn, $companyId, $contractId, CO_SHOP_CHARGE_DEPOSIT);
    $comm = co_shop_contract_charge_by_code($conn, $companyId, $contractId, CO_SHOP_CHARGE_COMMISSION);
    $sets = [];
    $params = [];
    if ($rent) {
        $sets[] = 'rent_amount = ?';
        $params[] = (float)$rent['amount'];
        if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'vat_rate') && ($rent['vat_mode'] ?? '') !== 'none') {
            $sets[] = 'vat_rate = ?';
            $params[] = (float)$rent['vat_rate'];
        }
    }
    if ($dep) {
        $sets[] = 'security_deposit = ?';
        $params[] = (float)$dep['amount'];
    }
    if ($comm && co_db_column_exists($conn, 'co_shop_rental_contracts', 'commission_net_amount')) {
        $sets[] = 'commission_net_amount = ?';
        $params[] = (float)$comm['amount'];
        $sets[] = 'commission_enabled = ?';
        $params[] = (($comm['status'] ?? '') === 'active' && (float)$comm['amount'] > 0) ? 1 : 0;
        $sets[] = 'commission_manual_override = 1';
        if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'commission_vat_enabled')) {
            $sets[] = 'commission_vat_enabled = ?';
            $params[] = (($comm['vat_mode'] ?? 'none') !== 'none' && (float)$comm['vat_rate'] > 0) ? 1 : 0;
            $sets[] = 'commission_vat_rate = ?';
            $params[] = (float)$comm['vat_rate'];
        }
    }
    if ($sets) {
        $params[] = $contractId;
        $params[] = $companyId;
        $conn->prepare('UPDATE co_shop_rental_contracts SET ' . implode(', ', $sets) . ' WHERE id = ? AND company_id = ?')
            ->execute($params);
    }
}

/**
 * Add a custom (non-system) charge type + contract line.
 */
function co_shop_add_custom_contract_charge(
    PDO $conn,
    int $companyId,
    int $contractId,
    string $name,
    float $amount,
    string $coaCode,
    string $vatMode,
    float $vatRate,
    ?int $userId = null
): int {
    if ($companyId <= 0 || $contractId <= 0) {
        throw new RuntimeException('Company and contract are required.');
    }
    if (!co_shop_charges_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase_charges.sql');
    }
    $name = trim($name);
    $coaCode = trim($coaCode);
    if ($name === '' || $amount <= 0) {
        throw new RuntimeException('Custom charge requires a name and amount greater than zero.');
    }
    if ($coaCode === '') {
        throw new RuntimeException('Custom charge requires a GL account code.');
    }
    $acct = find_account_by_code($coaCode, $companyId);
    if (!$acct || !empty($acct['is_header'])) {
        throw new RuntimeException('GL account ' . $coaCode . ' not found for this company.');
    }
    $code = 'custom_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
    $code = trim($code, '_');
    if ($code === '' || strlen($code) > 40) {
        $code = 'custom_' . substr(md5($name . microtime(true)), 0, 8);
    }
    // Ensure unique type code
    $base = $code;
    $n = 1;
    while (co_shop_charge_type_by_code($conn, $companyId, $code)) {
        $code = $base . '_' . $n;
        $n++;
        if ($n > 50) {
            throw new RuntimeException('Could not allocate a unique charge type code.');
        }
    }
    $ins = $conn->prepare("
        INSERT INTO co_shop_charge_types
            (company_id, code, name, nature, default_coa_code, vat_eligible, is_system, is_active, invoicing_enabled, allocation_priority, sort_order)
        VALUES (?, ?, ?, 'income', ?, 1, 0, 1, 1, 50, 50)
    ");
    $ins->execute([$companyId, $code, $name, $coaCode]);
    return co_shop_upsert_contract_charge(
        $conn, $companyId, $contractId, $code, $amount, $vatMode, $vatRate, 'active', null, $userId, null
    );
}

/**
 * Generate AR invoice for an income charge that supports invoicing (not rent schedules, not deposit).
 * Commission uses existing commission helper when code=commission.
 * Key Money uses shop_charge path with income account 4170 (or charge GL override).
 * @return array{invoice_id:int,journal_id:?int}
 */
function co_shop_generate_charge_invoice(PDO $conn, int $companyId, int $contractId, int $chargeId, int $userId, ?string $invoiceDate = null): array {
    if ($companyId <= 0 || $contractId <= 0 || $chargeId <= 0) {
        throw new RuntimeException('Company, contract and charge are required.');
    }
    $charges = co_shop_list_contract_charges($conn, $companyId, $contractId);
    $charge = null;
    foreach ($charges as $c) {
        if ((int)$c['id'] === $chargeId) {
            $charge = $c;
            break;
        }
    }
    if (!$charge) {
        throw new RuntimeException('Charge not found on this contract.');
    }
    $code = (string)$charge['charge_code'];
    if (empty($charge['invoicing_enabled'])) {
        throw new RuntimeException('This charge type is not AR-invoiceable.');
    }
    if (($charge['nature'] ?? '') === 'liability') {
        throw new RuntimeException('Liability charges (e.g. security deposit) use the deposit receipt path, not AR invoices.');
    }
    if ($code === CO_SHOP_CHARGE_RENT) {
        throw new RuntimeException('Rent is invoiced from monthly earning schedules (Invoices / Schedule tab).');
    }
    if (($charge['status'] ?? '') === 'invoiced') {
        throw new RuntimeException('This charge is already marked invoiced.');
    }
    if ((float)$charge['amount'] <= 0) {
        throw new RuntimeException('Charge amount must be greater than zero.');
    }

    if ($code === CO_SHOP_CHARGE_COMMISSION) {
        require_once __DIR__ . '/construction_shop_rental_commission_helpers.php';
        $result = co_shop_generate_commission_invoice($conn, $companyId, $contractId, $userId, $invoiceDate);
        if (co_db_column_exists($conn, 'co_client_invoices', 'contract_charge_id')) {
            $conn->prepare("UPDATE co_client_invoices SET contract_charge_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$chargeId, (int)$result['invoice_id'], $companyId]);
        }
        $conn->prepare("UPDATE co_shop_contract_charges SET status = 'invoiced' WHERE id = ? AND company_id = ?")
            ->execute([$chargeId, $companyId]);
        return ['invoice_id' => (int)$result['invoice_id'], 'journal_id' => (int)($result['journal_id'] ?? 0)];
    }

    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('Contract not found.');
    }

    if ($code === CO_SHOP_CHARGE_KEY_MONEY) {
        $timing = (string)($charge['invoice_timing'] ?? CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE);
        $startDate = (string)($contract['start_date'] ?? '');
        $today = date('Y-m-d');
        if ($timing === CO_SHOP_KEY_MONEY_TIMING_ON_START && $startDate !== '' && $startDate > $today) {
            throw new RuntimeException(
                'Key Money invoice timing is On Contract Start Date (' . $startDate . '). '
                . 'Generation is available on or after that date.'
            );
        }
        if ($invoiceDate === null || $invoiceDate === '') {
            if ($timing === CO_SHOP_KEY_MONEY_TIMING_ON_START && $startDate !== '') {
                $invoiceDate = $startDate;
            } else {
                $invoiceDate = $today;
            }
        }
        co_shop_ensure_key_money_income_account($conn, $companyId);
    }

    $coaCode = trim((string)($charge['gl_account_override'] ?: $charge['default_coa_code'] ?: ''));
    if ($coaCode === '' && $code === CO_SHOP_CHARGE_KEY_MONEY) {
        $coaCode = defined('CO_ACCOUNT_SHOP_KEY_MONEY') ? CO_ACCOUNT_SHOP_KEY_MONEY : '4170';
    }
    if ($coaCode === '') {
        throw new RuntimeException('Charge has no GL account mapped.');
    }
    $acct = find_account_by_code($coaCode, $companyId);
    if (!$acct) {
        throw new RuntimeException('GL account ' . $coaCode . ' not found for this company.');
    }
    $date = $invoiceDate ?: date('Y-m-d');
    $desc = ($charge['charge_name'] ?? 'Charge') . ' · ' . ($contract['contract_number'] ?? ('#' . $contractId));
    $invoiceNumber = co_next_document_number($conn, $companyId, 'SHOP-CHG', 'co_client_invoices', 'invoice_number');
    $invoiceId = co_create_income_invoice(
        $conn,
        $companyId,
        (int)$contract['client_id'],
        0,
        'shop_charge',
        $contractId,
        $invoiceNumber,
        $date,
        $date,
        (float)$charge['amount'],
        (float)$charge['vat_amount'],
        $desc,
        (int)$acct['id'],
        $userId,
        null,
        $chargeId
    );
    $postResult = co_post_client_invoice_to_accounting($invoiceId, $companyId, $userId);
    if (empty($postResult['success'])) {
        throw new RuntimeException($postResult['error'] ?? 'Charge invoice posting failed.');
    }
    $conn->prepare("UPDATE co_client_invoices SET journal_id = ? WHERE id = ? AND company_id = ?")
        ->execute([(int)$postResult['journal_id'], $invoiceId, $companyId]);
    $conn->prepare("UPDATE co_shop_contract_charges SET status = 'invoiced' WHERE id = ? AND company_id = ?")
        ->execute([$chargeId, $companyId]);
    return ['invoice_id' => $invoiceId, 'journal_id' => (int)$postResult['journal_id']];
}

/** Attach contract_charge_id after invoice create when column exists. */
function co_shop_link_invoice_to_charge(PDO $conn, int $companyId, int $invoiceId, ?int $chargeId): void {
    if ($chargeId === null || $chargeId <= 0) {
        return;
    }
    if (!co_db_column_exists($conn, 'co_client_invoices', 'contract_charge_id')) {
        return;
    }
    $conn->prepare("UPDATE co_client_invoices SET contract_charge_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$chargeId, $invoiceId, $companyId]);
}

function co_shop_ensure_key_money_income_account(PDO $conn, int $companyId): ?array {
    $code = defined('CO_ACCOUNT_SHOP_KEY_MONEY') ? CO_ACCOUNT_SHOP_KEY_MONEY : '4170';
    $existing = find_account_by_code($code, $companyId);
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
        VALUES (?, ?, 'Key Money Income', 'Income', 'credit', ?, 0, 1, 'One-time Key Money charged to shop tenants')
    ");
    try {
        $ins->execute([$companyId, $code, $parentId]);
    } catch (Throwable $e) {
        return find_account_by_code($code, $companyId) ?: null;
    }
    return find_account_by_code($code, $companyId) ?: null;
}

/** @return array<string,mixed>|null */
function co_shop_key_money_invoice(PDO $conn, int $companyId, int $contractId): ?array {
    if ($companyId <= 0 || $contractId <= 0 || !co_db_table_exists($conn, 'co_client_invoices')) {
        return null;
    }
    $charge = co_shop_contract_charge_by_code($conn, $companyId, $contractId, CO_SHOP_CHARGE_KEY_MONEY);
    if ($charge && co_db_column_exists($conn, 'co_client_invoices', 'contract_charge_id')) {
        $stmt = $conn->prepare("
            SELECT * FROM co_client_invoices
            WHERE company_id = ? AND contract_charge_id = ? AND status <> 'cancelled'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$companyId, (int)$charge['id']]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            return $inv;
        }
    }
    $stmt = $conn->prepare("
        SELECT i.*
        FROM co_client_invoices i
        LEFT JOIN co_shop_contract_charges cc
          ON cc.id = i.contract_charge_id AND cc.company_id = i.company_id
        LEFT JOIN co_shop_charge_types ct
          ON ct.id = cc.charge_type_id AND ct.company_id = cc.company_id
        WHERE i.company_id = ?
          AND i.source_type = 'shop_charge'
          AND i.source_id = ?
          AND i.status <> 'cancelled'
          AND (ct.code = 'key_money' OR i.description LIKE 'Key Money%')
        ORDER BY i.id DESC
        LIMIT 1
    ");
    $stmt->execute([$companyId, $contractId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    return $inv ?: null;
}

/**
 * Key Money financial status (commission-parity shape).
 * @return array{status:string,invoiced:float,collected:float,outstanding:float,vat:float,net:float,gross:float,invoice:?array,charge:?array,timing:string,enabled:bool}
 */
function co_shop_key_money_status(PDO $conn, int $companyId, int $contractId): array {
    $charge = co_shop_contract_charge_by_code($conn, $companyId, $contractId, CO_SHOP_CHARGE_KEY_MONEY);
    $net = $charge ? round((float)$charge['amount'], 2) : 0.0;
    $vat = $charge ? round((float)$charge['vat_amount'], 2) : 0.0;
    $gross = $charge ? round((float)$charge['gross_amount'], 2) : round($net + $vat, 2);
    $enabled = $charge && ($charge['status'] ?? '') !== 'disabled' && ($charge['status'] ?? '') !== 'cancelled' && $net > 0;
    $timing = (string)($charge['invoice_timing'] ?? CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE);
    $out = [
        'status' => !$enabled ? 'disabled' : 'not_invoiced',
        'invoiced' => 0.0,
        'collected' => 0.0,
        'outstanding' => 0.0,
        'vat' => $vat,
        'net' => $net,
        'gross' => $gross,
        'invoice' => null,
        'charge' => $charge,
        'timing' => $timing,
        'enabled' => (bool)$enabled,
    ];
    if (($charge['status'] ?? '') === 'invoiced' || $enabled) {
        // keep
    }
    $inv = co_shop_key_money_invoice($conn, $companyId, $contractId);
    if (!$inv) {
        if ($enabled && ($charge['status'] ?? '') === 'active') {
            $out['status'] = 'not_invoiced';
        }
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
    $out['enabled'] = true;
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
 * Apply wizard / POST Key Money settings and optionally generate invoice when timing allows.
 * @return array{charge_id:int,invoice_id:?int,journal_id:?int,generated:bool}
 */
function co_shop_apply_key_money_settings(
    PDO $conn,
    int $companyId,
    int $contractId,
    array $input,
    ?int $userId = null,
    bool $autoGenerate = true
): array {
    if (!co_shop_charges_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase_charges.sql');
    }
    co_shop_seed_charge_types($conn, $companyId);
    co_shop_ensure_key_money_income_account($conn, $companyId);

    $enabled = !empty($input['key_money_enabled']);
    $amount = max(0, round((float)($input['key_money_amount'] ?? 0), 2));
    $vatMode = (string)($input['key_money_vat_mode'] ?? 'exclusive');
    if (!in_array($vatMode, ['exclusive', 'inclusive', 'none'], true)) {
        $vatMode = 'exclusive';
    }
    $vatRate = max(0, (float)($input['key_money_vat_rate'] ?? 5));
    if ($vatMode === 'none') {
        $vatRate = 0;
    }
    $gl = trim((string)($input['key_money_coa'] ?? '4170'));
    if ($gl === '') {
        $gl = '4170';
    }
    $notes = trim((string)($input['key_money_notes'] ?? ''));
    $timing = (string)($input['key_money_invoice_timing'] ?? CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE);
    if (!in_array($timing, [CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE, CO_SHOP_KEY_MONEY_TIMING_ON_START], true)) {
        $timing = CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE;
    }

    if (!$enabled || $amount <= 0) {
        $chargeId = co_shop_upsert_contract_charge(
            $conn, $companyId, $contractId, CO_SHOP_CHARGE_KEY_MONEY, 0, 'none', 0,
            'disabled', $notes !== '' ? $notes : null, $userId, $gl, $timing
        );
        return ['charge_id' => $chargeId, 'invoice_id' => null, 'journal_id' => null, 'generated' => false];
    }

    $chargeId = co_shop_upsert_contract_charge(
        $conn, $companyId, $contractId, CO_SHOP_CHARGE_KEY_MONEY, $amount, $vatMode, $vatRate,
        'active', $notes !== '' ? $notes : null, $userId, $gl, $timing
    );

    $generated = false;
    $invoiceId = null;
    $journalId = null;
    if ($autoGenerate && co_shop_key_money_generation_due($conn, $companyId, $contractId)) {
        $result = co_shop_generate_charge_invoice($conn, $companyId, $contractId, $chargeId, (int)$userId);
        $generated = true;
        $invoiceId = (int)$result['invoice_id'];
        $journalId = (int)($result['journal_id'] ?? 0);
    }
    return [
        'charge_id' => $chargeId,
        'invoice_id' => $invoiceId,
        'journal_id' => $journalId,
        'generated' => $generated,
    ];
}

function co_shop_key_money_generation_due(PDO $conn, int $companyId, int $contractId): bool {
    $charge = co_shop_contract_charge_by_code($conn, $companyId, $contractId, CO_SHOP_CHARGE_KEY_MONEY);
    if (!$charge || ($charge['status'] ?? '') !== 'active' || (float)$charge['amount'] <= 0) {
        return false;
    }
    if (co_shop_key_money_invoice($conn, $companyId, $contractId)) {
        return false;
    }
    $timing = (string)($charge['invoice_timing'] ?? CO_SHOP_KEY_MONEY_TIMING_IMMEDIATE);
    if ($timing !== CO_SHOP_KEY_MONEY_TIMING_ON_START) {
        return true;
    }
    $stmt = $conn->prepare("SELECT start_date FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $start = (string)$stmt->fetchColumn();
    return $start !== '' && $start <= date('Y-m-d');
}

/**
 * Auto-generate Key Money invoice when timing is due (safe to call on contract view load).
 * @return array{generated:bool,invoice_id:?int,error:?string}
 */
function co_shop_try_generate_due_key_money(PDO $conn, int $companyId, int $contractId, int $userId): array {
    try {
        if (!co_shop_key_money_generation_due($conn, $companyId, $contractId)) {
            return ['generated' => false, 'invoice_id' => null, 'error' => null];
        }
        $charge = co_shop_contract_charge_by_code($conn, $companyId, $contractId, CO_SHOP_CHARGE_KEY_MONEY);
        if (!$charge) {
            return ['generated' => false, 'invoice_id' => null, 'error' => null];
        }
        $result = co_shop_generate_charge_invoice($conn, $companyId, $contractId, (int)$charge['id'], $userId);
        return ['generated' => true, 'invoice_id' => (int)$result['invoice_id'], 'error' => null];
    } catch (Throwable $e) {
        return ['generated' => false, 'invoice_id' => null, 'error' => $e->getMessage()];
    }
}
