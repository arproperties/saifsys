<?php
/**
 * Construction — Contractor ↔ Supplier Business Partner helpers.
 * Operational contractors link to accounting suppliers (BR-CO-BP-*).
 * Commercial progress KPIs are extensible for future Certification / VO.
 * Does not call RE vendor_* helpers. Does not post journals.
 */

require_once __DIR__ . '/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/construction_supplier_advance_helpers.php';
require_once __DIR__ . '/construction_supplier_reporting_helpers.php';

if (!function_exists('co_contractor_link_schema_ready')) {
    function co_contractor_link_schema_ready(PDO $conn): bool {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $st = $conn->query("SHOW COLUMNS FROM co_contractors LIKE 'supplier_id'");
            $ready = (bool)$st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('co_contractor_money')) {
    function co_contractor_money($amount): float {
        return round((float)$amount, 2);
    }
}

/**
 * @return int|null Linked supplier id, or null if unlinked / schema missing
 */
function co_contractor_linked_supplier_id(PDO $conn, int $companyId, int $contractorId): ?int {
    if ($companyId <= 0 || $contractorId <= 0 || !co_contractor_link_schema_ready($conn)) {
        return null;
    }
    $st = $conn->prepare("SELECT supplier_id FROM co_contractors WHERE id = ? AND company_id = ?");
    $st->execute([$contractorId, $companyId]);
    $sid = $st->fetchColumn();
    if ($sid === false || $sid === null || (int)$sid <= 0) {
        return null;
    }
    return (int)$sid;
}

/**
 * Reverse lookup: supplier → linked contractor row.
 * @return array<string,mixed>|null
 */
function co_supplier_linked_contractor(PDO $conn, int $companyId, int $supplierId): ?array {
    if ($companyId <= 0 || $supplierId <= 0 || !co_contractor_link_schema_ready($conn)) {
        return null;
    }
    $st = $conn->prepare("
        SELECT id, contractor_name, is_active, contact_person, phone, email
        FROM co_contractors
        WHERE company_id = ? AND supplier_id = ?
        LIMIT 1
    ");
    $st->execute([$companyId, $supplierId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Active suppliers available to link (exclude those already linked to another contractor).
 * @return list<array{id:int,supplier_name:string}>
 */
function co_contractor_linkable_suppliers(PDO $conn, int $companyId, ?int $currentContractorId = null): array {
    if ($companyId <= 0) {
        return [];
    }
    if (!co_contractor_link_schema_ready($conn)) {
        $st = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name");
        $st->execute([$companyId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $sql = "
        SELECT s.id, s.supplier_name
        FROM co_suppliers s
        WHERE s.company_id = ? AND s.is_active = 1
          AND NOT EXISTS (
              SELECT 1 FROM co_contractors c
              WHERE c.company_id = s.company_id
                AND c.supplier_id = s.id
                AND (? IS NULL OR c.id <> ?)
          )
        ORDER BY s.supplier_name
    ";
    $st = $conn->prepare($sql);
    $st->execute([$companyId, $currentContractorId, $currentContractorId ?? 0]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Set or clear supplier link. Validates company + uniqueness.
 * @return array{ok:bool,error?:string}
 */
function co_contractor_set_supplier_link(PDO $conn, int $companyId, int $contractorId, ?int $supplierId): array {
    if ($companyId <= 0 || $contractorId <= 0) {
        return ['ok' => false, 'error' => 'Company and contractor are required.'];
    }
    if (!co_contractor_link_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'Run migrations/construction_contractor_supplier_link.sql first.'];
    }
    $st = $conn->prepare("SELECT id FROM co_contractors WHERE id = ? AND company_id = ?");
    $st->execute([$contractorId, $companyId]);
    if (!$st->fetchColumn()) {
        return ['ok' => false, 'error' => 'Contractor not found.'];
    }
    if ($supplierId === null || $supplierId <= 0) {
        $upd = $conn->prepare("UPDATE co_contractors SET supplier_id = NULL WHERE id = ? AND company_id = ?");
        $upd->execute([$contractorId, $companyId]);
        return ['ok' => true];
    }
    $st = $conn->prepare("SELECT id FROM co_suppliers WHERE id = ? AND company_id = ?");
    $st->execute([$supplierId, $companyId]);
    if (!$st->fetchColumn()) {
        return ['ok' => false, 'error' => 'Supplier not found in this company.'];
    }
    $st = $conn->prepare("
        SELECT id FROM co_contractors
        WHERE company_id = ? AND supplier_id = ? AND id <> ?
        LIMIT 1
    ");
    $st->execute([$companyId, $supplierId, $contractorId]);
    if ($st->fetchColumn()) {
        return ['ok' => false, 'error' => 'That supplier is already linked to another contractor.'];
    }
    $upd = $conn->prepare("UPDATE co_contractors SET supplier_id = ? WHERE id = ? AND company_id = ?");
    $upd->execute([$supplierId, $contractorId, $companyId]);
    return ['ok' => true];
}

/**
 * Create a supplier from contractor master fields and link.
 * @return array{ok:bool,supplier_id?:int,error?:string}
 */
function co_contractor_create_supplier_from_contractor(PDO $conn, int $companyId, int $contractorId, ?int $userId = null): array {
    if ($companyId <= 0 || $contractorId <= 0) {
        return ['ok' => false, 'error' => 'Company and contractor are required.'];
    }
    if (!co_contractor_link_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'Run migrations/construction_contractor_supplier_link.sql first.'];
    }
    $st = $conn->prepare("SELECT * FROM co_contractors WHERE id = ? AND company_id = ?");
    $st->execute([$contractorId, $companyId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        return ['ok' => false, 'error' => 'Contractor not found.'];
    }
    if (!empty($c['supplier_id'])) {
        return ['ok' => true, 'supplier_id' => (int)$c['supplier_id']];
    }
    $name = trim((string)$c['contractor_name']);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Contractor name is required.'];
    }
    $dup = $conn->prepare("SELECT id FROM co_suppliers WHERE company_id = ? AND supplier_name = ? LIMIT 1");
    $dup->execute([$companyId, $name]);
    $existingId = (int)$dup->fetchColumn();
    if ($existingId > 0) {
        $link = co_contractor_set_supplier_link($conn, $companyId, $contractorId, $existingId);
        if (!$link['ok']) {
            return ['ok' => false, 'error' => $link['error'] ?? 'Could not link existing supplier.'];
        }
        return ['ok' => true, 'supplier_id' => $existingId];
    }
    try {
        $ins = $conn->prepare("
            INSERT INTO co_suppliers (
                company_id, supplier_name, contact_person, email, phone, address, tax_number,
                bank_name, account_number, iban, swift_code, bank_details, notes, is_active
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
        ");
        $ins->execute([
            $companyId,
            $name,
            $c['contact_person'] ?: null,
            $c['email'] ?: null,
            $c['phone'] ?: null,
            $c['address'] ?: null,
            $c['tax_number'] ?: null,
            $c['bank_name'] ?? null,
            $c['account_number'] ?? null,
            $c['iban'] ?? null,
            $c['swift_code'] ?? null,
            $c['bank_details'] ?? null,
            $c['notes'] ?: null,
        ]);
    } catch (Throwable $e) {
        $ins2 = $conn->prepare("
            INSERT INTO co_suppliers (
                company_id, supplier_name, contact_person, email, phone, address, tax_number, notes, is_active
            ) VALUES (?,?,?,?,?,?,?,?,1)
        ");
        $ins2->execute([
            $companyId, $name,
            $c['contact_person'] ?: null, $c['email'] ?: null, $c['phone'] ?: null,
            $c['address'] ?: null, $c['tax_number'] ?: null, $c['notes'] ?: null,
        ]);
    }
    $supplierId = (int)$conn->lastInsertId();
    $link = co_contractor_set_supplier_link($conn, $companyId, $contractorId, $supplierId);
    if (!$link['ok']) {
        return ['ok' => false, 'error' => $link['error'] ?? 'Supplier created but link failed.'];
    }
    return ['ok' => true, 'supplier_id' => $supplierId];
}

/**
 * Operational contract value for a contractor (optionally one project).
 */
function co_contractor_contract_value(PDO $conn, int $companyId, int $contractorId, ?int $projectId = null): float {
    if ($companyId <= 0 || $contractorId <= 0) {
        return 0.0;
    }
    $sql = "
        SELECT COALESCE(SUM(pc.contract_value), 0)
        FROM co_project_contractors pc
        WHERE pc.company_id = ? AND pc.contractor_id = ?
    ";
    $params = [$companyId, $contractorId];
    if ($projectId !== null && $projectId > 0) {
        $sql .= " AND pc.project_id = ?";
        $params[] = $projectId;
    }
    $st = $conn->prepare($sql);
    $st->execute($params);
    return co_contractor_money($st->fetchColumn());
}

/**
 * Posted invoice subtotals for a supplier (optional project filter).
 * Excludes draft/voided when lifecycle ready.
 */
function co_supplier_total_invoiced_subtotal(PDO $conn, int $companyId, int $supplierId, ?int $projectId = null): float {
    if ($companyId <= 0 || $supplierId <= 0) {
        return 0.0;
    }
    $statusSql = '';
    if (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn)) {
        $statusSql = " AND si.status NOT IN ('draft', 'voided') ";
    } else {
        $statusSql = " AND si.journal_id IS NOT NULL ";
    }
    $sql = "
        SELECT COALESCE(SUM(si.subtotal), 0)
        FROM co_supplier_invoices si
        WHERE si.company_id = ? AND si.supplier_id = ?
          {$statusSql}
    ";
    $params = [$companyId, $supplierId];
    if ($projectId !== null && $projectId > 0) {
        $sql .= " AND si.project_id = ?";
        $params[] = $projectId;
    }
    $st = $conn->prepare($sql);
    $st->execute($params);
    return co_contractor_money($st->fetchColumn());
}

/**
 * Amount paid against supplier invoices (allocations + advances), optional project.
 */
function co_supplier_total_paid_on_invoices(PDO $conn, int $companyId, int $supplierId, ?int $projectId = null): float {
    if ($companyId <= 0 || $supplierId <= 0) {
        return 0.0;
    }
    if ($projectId === null || $projectId <= 0) {
        $st = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM co_supplier_payments
            WHERE company_id = ? AND supplier_id = ? AND journal_id IS NOT NULL
        ");
        $st->execute([$companyId, $supplierId]);
        return co_contractor_money($st->fetchColumn());
    }
    $statusSql = '';
    if (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn)) {
        $statusSql = " AND si.status NOT IN ('draft', 'voided') ";
    }
    $st = $conn->prepare("
        SELECT si.id FROM co_supplier_invoices si
        WHERE si.company_id = ? AND si.supplier_id = ? AND si.project_id = ?
          AND si.journal_id IS NOT NULL
          {$statusSql}
    ");
    $st->execute([$companyId, $supplierId, $projectId]);
    $total = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $invId) {
        $total += co_supplier_invoice_paid_amount($conn, $companyId, (int)$invId);
    }
    return co_contractor_money($total);
}

/**
 * Outstanding AP for supplier, optionally limited to one project.
 */
function co_supplier_outstanding_ap_scoped(PDO $conn, int $companyId, int $supplierId, ?int $projectId = null): float {
    if ($projectId === null || $projectId <= 0) {
        return co_supplier_outstanding_ap($conn, $companyId, $supplierId);
    }
    $statusSql = '';
    if (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn)) {
        $statusSql = " AND si.status NOT IN ('draft', 'voided') ";
    }
    $st = $conn->prepare("
        SELECT si.id, si.total FROM co_supplier_invoices si
        WHERE si.company_id = ? AND si.supplier_id = ? AND si.project_id = ?
          AND si.journal_id IS NOT NULL
          {$statusSql}
    ");
    $st->execute([$companyId, $supplierId, $projectId]);
    $out = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $inv) {
        if (function_exists('co_supplier_invoice_outstanding_amount')) {
            $out += co_supplier_invoice_outstanding_amount($conn, $companyId, (int)$inv['id']);
        } else {
            $paid = co_supplier_invoice_paid_amount($conn, $companyId, (int)$inv['id']);
            $out += max(0, (float)$inv['total'] - $paid);
        }
    }
    return co_contractor_money($out);
}

/**
 * Residual retention held from legacy contractor payments minus releases.
 * @return array{held:float,released:float,balance:float}
 */
function co_contractor_residual_retention(PDO $conn, int $companyId, int $contractorId, ?int $projectId = null): array {
    $sqlHeld = "
        SELECT COALESCE(SUM(cp.retention_held), 0)
        FROM co_contractor_payments cp
        JOIN co_project_contractors pc ON pc.id = cp.project_contractor_id AND pc.company_id = cp.company_id
        WHERE cp.company_id = ? AND pc.contractor_id = ?
    ";
    $sqlRel = "
        SELECT COALESCE(SUM(rr.amount), 0)
        FROM co_retention_releases rr
        JOIN co_project_contractors pc ON pc.id = rr.project_contractor_id AND pc.company_id = rr.company_id
        WHERE rr.company_id = ? AND pc.contractor_id = ?
    ";
    $params = [$companyId, $contractorId];
    if ($projectId !== null && $projectId > 0) {
        $sqlHeld .= " AND pc.project_id = ?";
        $sqlRel .= " AND pc.project_id = ?";
        $params[] = $projectId;
    }
    $held = 0.0;
    $released = 0.0;
    try {
        $st = $conn->prepare($sqlHeld);
        $st->execute($params);
        $held = co_contractor_money($st->fetchColumn());
        $st = $conn->prepare($sqlRel);
        $st->execute($params);
        $released = co_contractor_money($st->fetchColumn());
    } catch (Throwable $e) {
        // tables may be absent in partial installs
    }
    return [
        'held' => $held,
        'released' => $released,
        'balance' => co_contractor_money(max(0, $held - $released)),
    ];
}

/**
 * Core commercial progress pack (reusable; extend for Certification / VO later).
 *
 * @return array<string,mixed>
 */
function co_contractor_commercial_progress(
    PDO $conn,
    int $companyId,
    int $contractorId,
    ?int $projectId = null
): array {
    $empty = [
        'linked' => false,
        'supplier_id' => null,
        'supplier_name' => null,
        'contract_value' => 0.0,
        'total_invoiced' => 0.0,
        'total_paid' => 0.0,
        'outstanding_ap' => 0.0,
        'advance_balance' => 0.0,
        'net_payable' => 0.0,
        'remaining_contract_value' => 0.0,
        'billing_progress_pct' => 0.0,
        'payment_progress_pct' => 0.0,
        'retention_held' => 0.0,
        'retention_released' => 0.0,
        'retention_balance' => 0.0,
        'open_invoices' => 0,
        'last_invoice' => null,
        'last_payment' => null,
        // Future Certification / VO extension points (null until implemented)
        'total_certified' => null,
        'variation_orders_approved' => null,
        'project_id' => $projectId,
    ];
    if ($companyId <= 0 || $contractorId <= 0) {
        return $empty;
    }

    $contractValue = co_contractor_contract_value($conn, $companyId, $contractorId, $projectId);
    $retention = co_contractor_residual_retention($conn, $companyId, $contractorId, $projectId);
    $empty['contract_value'] = $contractValue;
    $empty['remaining_contract_value'] = $contractValue;
    $empty['retention_held'] = $retention['held'];
    $empty['retention_released'] = $retention['released'];
    $empty['retention_balance'] = $retention['balance'];

    $supplierId = co_contractor_linked_supplier_id($conn, $companyId, $contractorId);
    if ($supplierId === null) {
        return $empty;
    }

    $st = $conn->prepare("SELECT supplier_name FROM co_suppliers WHERE id = ? AND company_id = ?");
    $st->execute([$supplierId, $companyId]);
    $supplierName = $st->fetchColumn();

    $invoiced = co_supplier_total_invoiced_subtotal($conn, $companyId, $supplierId, $projectId);
    $paid = co_supplier_total_paid_on_invoices($conn, $companyId, $supplierId, $projectId);
    $outstanding = co_supplier_outstanding_ap_scoped($conn, $companyId, $supplierId, $projectId);

    $advance = 0.0;
    $netPayable = $outstanding;
    if ($projectId === null || $projectId <= 0) {
        $pos = co_supplier_financial_position($conn, $companyId, $supplierId);
        $advance = $pos['advance_balance'];
        $netPayable = $pos['net_payable'];
    }

    $billingPct = $contractValue > 0.005
        ? co_contractor_money(min(100, ($invoiced / $contractValue) * 100))
        : 0.0;
    $paymentPct = $contractValue > 0.005
        ? co_contractor_money(min(100, ($paid / $contractValue) * 100))
        : 0.0;

    $kpis = ($projectId === null || $projectId <= 0)
        ? co_supplier_dashboard_kpis($conn, $companyId, $supplierId)
        : null;

    return [
        'linked' => true,
        'supplier_id' => $supplierId,
        'supplier_name' => $supplierName !== false && $supplierName !== null ? (string)$supplierName : null,
        'contract_value' => $contractValue,
        'total_invoiced' => $invoiced,
        'total_paid' => $paid,
        'outstanding_ap' => $outstanding,
        'advance_balance' => $advance,
        'net_payable' => $netPayable,
        'remaining_contract_value' => co_contractor_money(max(0, $contractValue - $invoiced)),
        'billing_progress_pct' => $billingPct,
        'payment_progress_pct' => $paymentPct,
        'retention_held' => $retention['held'],
        'retention_released' => $retention['released'],
        'retention_balance' => $retention['balance'],
        'open_invoices' => $kpis ? ((int)$kpis['open_invoices'] + (int)$kpis['partially_paid_invoices']) : 0,
        'last_invoice' => $kpis['last_invoice'] ?? null,
        'last_payment' => $kpis['last_payment'] ?? null,
        'total_certified' => null,
        'variation_orders_approved' => null,
        'project_id' => $projectId,
    ];
}

/**
 * Project–contractor commercial strip (one assignment row).
 * @return array<string,mixed>
 */
function co_project_contractor_commercial_progress(
    PDO $conn,
    int $companyId,
    int $projectContractorId
): array {
    $st = $conn->prepare("
        SELECT pc.id, pc.project_id, pc.contractor_id, pc.contract_value, c.contractor_name
        FROM co_project_contractors pc
        JOIN co_contractors c ON c.id = pc.contractor_id AND c.company_id = pc.company_id
        WHERE pc.id = ? AND pc.company_id = ?
    ");
    $st->execute([$projectContractorId, $companyId]);
    $pc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pc) {
        return co_contractor_commercial_progress($conn, 0, 0);
    }
    $progress = co_contractor_commercial_progress(
        $conn,
        $companyId,
        (int)$pc['contractor_id'],
        (int)$pc['project_id']
    );
    $cv = co_contractor_money($pc['contract_value']);
    $progress['contract_value'] = $cv;
    $progress['remaining_contract_value'] = co_contractor_money(max(0, $cv - $progress['total_invoiced']));
    $progress['billing_progress_pct'] = $cv > 0.005
        ? co_contractor_money(min(100, ($progress['total_invoiced'] / $cv) * 100))
        : 0.0;
    $progress['payment_progress_pct'] = $cv > 0.005
        ? co_contractor_money(min(100, ($progress['total_paid'] / $cv) * 100))
        : 0.0;
    $progress['project_contractor_id'] = (int)$pc['id'];
    $progress['contractor_id'] = (int)$pc['contractor_id'];
    $progress['contractor_name'] = $pc['contractor_name'];
    return $progress;
}

/**
 * Projects + contract values for a linked contractor (supplier profile panel).
 * @return list<array<string,mixed>>
 */
function co_contractor_project_assignments(PDO $conn, int $companyId, int $contractorId): array {
    $st = $conn->prepare("
        SELECT pc.id AS project_contractor_id, pc.contract_value, pc.retention_pct, pc.status,
               pc.parent_project_contractor_id,
               p.id AS project_id, p.project_code, p.project_name
        FROM co_project_contractors pc
        JOIN co_projects p ON p.id = pc.project_id AND p.company_id = pc.company_id
        WHERE pc.company_id = ? AND pc.contractor_id = ?
        ORDER BY p.project_name
    ");
    $st->execute([$companyId, $contractorId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Build Supplier Payment Workspace URL, or null if contractor not linked.
 */
function co_contractor_payment_workspace_url(
    PDO $conn,
    int $companyId,
    int $contractorId,
    ?int $projectId = null
): ?string {
    $sid = co_contractor_linked_supplier_id($conn, $companyId, $contractorId);
    if ($sid === null) {
        return null;
    }
    $url = 'supplier_payment_add.php?supplier_id=' . $sid;
    if ($projectId !== null && $projectId > 0) {
        $url .= '&project_id=' . (int)$projectId;
    }
    return $url;
}

/**
 * Resolve payment redirect from a project_contractor_id.
 * @return array{url:?string,contractor_id:int,project_id:int,supplier_id:?int,error?:string}
 */
function co_project_contractor_payment_redirect(PDO $conn, int $companyId, int $projectContractorId): array {
    $st = $conn->prepare("
        SELECT pc.contractor_id, pc.project_id
        FROM co_project_contractors pc
        WHERE pc.id = ? AND pc.company_id = ?
    ");
    $st->execute([$projectContractorId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['url' => null, 'contractor_id' => 0, 'project_id' => 0, 'supplier_id' => null, 'error' => 'Assignment not found.'];
    }
    $contractorId = (int)$row['contractor_id'];
    $projectId = (int)$row['project_id'];
    $supplierId = co_contractor_linked_supplier_id($conn, $companyId, $contractorId);
    if (!$supplierId) {
        return [
            'url' => null,
            'contractor_id' => $contractorId,
            'project_id' => $projectId,
            'supplier_id' => null,
            'error' => 'Link a Supplier on the Contractor profile before recording payments.',
        ];
    }
    return [
        'url' => 'supplier_payment_add.php?supplier_id=' . $supplierId . '&project_id=' . $projectId,
        'contractor_id' => $contractorId,
        'project_id' => $projectId,
        'supplier_id' => $supplierId,
    ];
}
