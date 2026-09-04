<?php
/**
 * Real Estate Accounting - VAT Report
 * Shows VAT summary for UAE compliance (GL-based: Output credits on 2310, Input net Dr−Cr).
 * UI enrichment only — VAT totals method unchanged.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_fmt($n) { return number_format((float)$n, 2); }
function money_aed($n) { return money_fmt($n) . ' AED'; }

/**
 * Map journal reference to source document URL (relative to accounting/).
 */
function re_vat_document_url(?string $refType, int $refId): ?string {
    if ($refId <= 0 || $refType === null || $refType === '') {
        return null;
    }
    switch ($refType) {
        case 'invoice':
            return '../billing_invoice_view.php?id=' . $refId;
        case 'vendor_invoice':
            return 'vendor_bill_view.php?id=' . $refId;
        case 'vendor_advance_vat_document':
            return 'vendor_advance_vat_document_view.php?id=' . $refId;
        default:
            return null;
    }
}

function re_vat_doc_type_label(?string $refType, ?string $journalType): string {
    $map = [
        'invoice' => 'Invoice',
        'vendor_invoice' => 'Vendor Bill',
        'vendor_advance_vat_document' => 'Advance VAT',
        'credit_note' => 'Credit Note',
        'erp_expense' => 'ERP Expense',
        'recognition_schedule' => 'Recognition',
        'payment' => 'Payment',
        'expense' => 'Expense',
    ];
    if ($refType && isset($map[$refType])) {
        return $map[$refType];
    }
    if ($journalType) {
        return ucwords(str_replace('_', ' ', $journalType));
    }
    return 'Journal';
}

function re_vat_journal_status(array $row): string {
    if (!empty($row['is_reversed'])) {
        return 'Reversed';
    }
    if (!empty($row['is_posted'])) {
        return 'Posted';
    }
    $approval = (string)($row['approval_status'] ?? '');
    if ($approval === 'submitted') {
        return 'Submitted';
    }
    if ($approval === 'approved') {
        return 'Approved';
    }
    if ($approval === 'draft') {
        return 'Draft';
    }
    return 'Unposted';
}

function re_vat_status_badge_class(string $status): string {
    switch ($status) {
        case 'Posted':
            return 'bg-success';
        case 'Reversed':
            return 'bg-warning text-dark';
        case 'Submitted':
        case 'Approved':
            return 'bg-info text-dark';
        case 'Draft':
        case 'Unposted':
            return 'bg-secondary';
        default:
            return 'bg-light text-dark';
    }
}

function re_vat_type_badge_class(string $docType): string {
    $d = strtolower($docType);
    if (str_contains($d, 'reversal')) {
        return 'bg-teal text-dark';
    }
    if (str_contains($d, 'invoice') && !str_contains($d, 'vendor')) {
        return 'bg-primary';
    }
    if (str_contains($d, 'vendor') || str_contains($d, 'bill')) {
        return 'bg-info text-dark';
    }
    if (str_contains($d, 'advance')) {
        return 'bg-success';
    }
    if (str_contains($d, 'credit')) {
        return 'bg-warning text-dark';
    }
    return 'bg-secondary';
}

/**
 * Back-calculate taxable/gross from VAT amount when source doc amounts unavailable.
 */
function re_vat_derive_amounts(float $vatAmount, float $vatRate): array {
    $rate = $vatRate > 0 ? $vatRate : 5.0;
    $taxable = round($vatAmount / ($rate / 100), 2);
    $gross = round($taxable + $vatAmount, 2);
    return [$taxable, $gross, $rate];
}

/**
 * Batch-enrich GL VAT rows with party, document #, taxable/gross from source docs.
 * Does not change the VAT amount used for official totals.
 */
function re_vat_enrich_rows(PDO $conn, int $companyId, array $rows, string $direction, float $defaultVatRate): array {
    if (!$rows) {
        return [];
    }

    $invoiceIds = [];
    $billIds = [];
    $advIds = [];
    foreach ($rows as $r) {
        $refType = (string)($r['reference_type'] ?? '');
        $refId = (int)($r['reference_id'] ?? 0);
        if ($refId <= 0) {
            continue;
        }
        if ($refType === 'invoice') {
            $invoiceIds[$refId] = true;
        } elseif ($refType === 'vendor_invoice') {
            $billIds[$refId] = true;
        } elseif ($refType === 'vendor_advance_vat_document') {
            $advIds[$refId] = true;
        }
    }

    $invoices = [];
    if ($invoiceIds) {
        $ids = array_keys($invoiceIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("
            SELECT i.id, i.invoice_number, i.subtotal, i.tax_amount, i.total_amount, i.tax_rate, i.status,
                   CASE
                     WHEN t.tenant_type = 'company' AND NULLIF(TRIM(t.company_name), '') IS NOT NULL
                       THEN t.company_name
                     ELSE TRIM(CONCAT(COALESCE(t.first_name, ''), ' ', COALESCE(t.last_name, '')))
                   END AS party_name
            FROM re_invoices i
            LEFT JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
            LEFT JOIN re_tenants t ON t.id = l.tenant_id AND t.company_id = i.company_id
            WHERE i.company_id = ? AND i.id IN ($ph)
        ");
        $st->execute(array_merge([$companyId], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            $invoices[(int)$inv['id']] = $inv;
        }
    }

    $bills = [];
    if ($billIds) {
        $ids = array_keys($billIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("
            SELECT vi.id, vi.invoice_number, vi.subtotal, vi.tax_amount, vi.total_amount, vi.status,
                   vi.posting_status, v.vendor_name AS party_name
            FROM re_vendor_invoices vi
            LEFT JOIN re_vendors v ON v.id = vi.vendor_id AND v.company_id = vi.company_id
            WHERE vi.company_id = ? AND vi.id IN ($ph)
        ");
        $st->execute(array_merge([$companyId], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $bill) {
            $bills[(int)$bill['id']] = $bill;
        }
    }

    $advDocs = [];
    if ($advIds) {
        try {
            $ids = array_keys($advIds);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $conn->prepare("
                SELECT d.id, d.supplier_invoice_number, d.taxable_amount, d.vat_amount, d.gross_amount, d.status,
                       v.vendor_name AS party_name
                FROM re_vendor_advance_vat_documents d
                LEFT JOIN re_vendors v ON v.id = d.vendor_id AND v.company_id = d.company_id
                WHERE d.company_id = ? AND d.id IN ($ph)
            ");
            $st->execute(array_merge([$companyId], $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $doc) {
                $advDocs[(int)$doc['id']] = $doc;
            }
        } catch (Throwable $e) {
            $advDocs = [];
        }
    }

    $enriched = [];
    foreach ($rows as $r) {
        $refType = (string)($r['reference_type'] ?? '');
        $refId = (int)($r['reference_id'] ?? 0);
        $docType = re_vat_doc_type_label($refType ?: null, $r['journal_type'] ?? null);
        $status = re_vat_journal_status($r);
        $partyName = '';
        $docNumber = trim((string)($r['reference'] ?? ''));
        $docUrl = re_vat_document_url($refType ?: null, $refId);
        $taxable = null;
        $gross = null;
        $rowVatRate = $defaultVatRate;

        if ($direction === 'output') {
            $vatAmount = round((float)($r['credit_amount'] ?? 0), 2);
        } else {
            $vatAmount = round((float)($r['report_amount'] ?? ((float)($r['debit_amount'] ?? 0) - (float)($r['credit_amount'] ?? 0))), 2);
        }

        $srcTaxable = null;
        $srcGross = null;
        $srcVat = null;

        if ($refType === 'invoice' && isset($invoices[$refId])) {
            $inv = $invoices[$refId];
            $partyName = trim((string)($inv['party_name'] ?? ''));
            if ($docNumber === '' || $docNumber === '-') {
                $docNumber = (string)$inv['invoice_number'];
            }
            $srcTaxable = round((float)$inv['subtotal'], 2);
            $srcGross = round((float)$inv['total_amount'], 2);
            $srcVat = round((float)$inv['tax_amount'], 2);
            if (isset($inv['tax_rate']) && (float)$inv['tax_rate'] > 0) {
                $rowVatRate = (float)$inv['tax_rate'];
            }
        } elseif ($refType === 'vendor_invoice' && isset($bills[$refId])) {
            $bill = $bills[$refId];
            $partyName = trim((string)($bill['party_name'] ?? ''));
            if ($docNumber === '' || $docNumber === '-') {
                $docNumber = (string)$bill['invoice_number'];
            }
            $srcTaxable = round((float)$bill['subtotal'], 2);
            $srcGross = round((float)$bill['total_amount'], 2);
            $srcVat = round((float)$bill['tax_amount'], 2);
        } elseif ($refType === 'vendor_advance_vat_document' && isset($advDocs[$refId])) {
            $doc = $advDocs[$refId];
            $partyName = trim((string)($doc['party_name'] ?? ''));
            if ($docNumber === '' || $docNumber === '-') {
                $docNumber = (string)$doc['supplier_invoice_number'];
            }
            $srcTaxable = round((float)$doc['taxable_amount'], 2);
            $srcGross = round((float)$doc['gross_amount'], 2);
            $srcVat = round((float)$doc['vat_amount'], 2);
        }

        // Use source taxable/gross only when this GL VAT line aligns with the document VAT
        if ($srcTaxable !== null && abs($vatAmount) >= 0.005 && ($srcVat === null || abs(abs($srcVat) - abs($vatAmount)) <= 0.05)) {
            $sign = $vatAmount < 0 ? -1.0 : 1.0;
            $taxable = round($sign * abs($srcTaxable), 2);
            $gross = round($sign * abs($srcGross), 2);
        } else {
            // Derive from GL VAT amount so row Taxable/Gross reconcile to VAT column
            [$taxable, $gross, $rowVatRate] = re_vat_derive_amounts($vatAmount, $rowVatRate > 0 ? $rowVatRate : $defaultVatRate);
        }

        $description = trim((string)($r['description'] ?? ''));
        // Strip redundant "Invoice INV-… - Party" noise when we have dedicated columns
        $descClean = $description;
        if ($partyName !== '' && $docNumber !== '') {
            $descClean = preg_replace('/^Invoice\s+' . preg_quote($docNumber, '/') . '\s*-\s*' . preg_quote($partyName, '/') . '\s*$/i', '', $descClean) ?? $descClean;
            $descClean = preg_replace('/^Vendor Bill\s+' . preg_quote($docNumber, '/') . '\s*-\s*' . preg_quote($partyName, '/') . '\s*$/i', '', $descClean) ?? $descClean;
            $descClean = trim($descClean);
        }

        $enriched[] = array_merge($r, [
            'direction' => $direction,
            'doc_type' => $docType,
            'doc_number' => $docNumber !== '' ? $docNumber : '-',
            'doc_url' => $docUrl,
            'party_name' => $partyName !== '' ? $partyName : '—',
            'vat_amount' => $vatAmount,
            'taxable_amount' => $taxable,
            'gross_amount' => $gross,
            'vat_rate' => $rowVatRate,
            'status_label' => $status,
            'description_display' => $descClean !== '' ? $descClean : $description,
            'debit_amount' => round((float)($r['debit_amount'] ?? 0), 2),
            'credit_amount' => round((float)($r['credit_amount'] ?? 0), 2),
        ]);
    }

    return $enriched;
}

function re_vat_row_matches_filters(array $row, array $filters): bool {
    if ($filters['party'] !== '' && stripos((string)$row['party_name'], $filters['party']) === false) {
        return false;
    }
    if ($filters['doc_type'] !== '' && strcasecmp((string)$row['doc_type'], $filters['doc_type']) !== 0) {
        return false;
    }
    if ($filters['journal'] !== '' && stripos((string)($row['journal_number'] ?? ''), $filters['journal']) === false) {
        return false;
    }
    if ($filters['doc_number'] !== '' && stripos((string)$row['doc_number'], $filters['doc_number']) === false) {
        return false;
    }
    if ($filters['status'] !== '' && strcasecmp((string)$row['status_label'], $filters['status']) !== 0) {
        return false;
    }
    if ($filters['vat_rate'] !== '') {
        $want = (float)$filters['vat_rate'];
        if (abs((float)$row['vat_rate'] - $want) > 0.001) {
            return false;
        }
    }
    return true;
}

// Get filter parameters
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$filters = [
    'party' => trim((string)($_GET['party'] ?? '')),
    'doc_type' => trim((string)($_GET['doc_type'] ?? '')),
    'journal' => trim((string)($_GET['journal'] ?? '')),
    'doc_number' => trim((string)($_GET['doc_number'] ?? '')),
    'vat_rate' => trim((string)($_GET['vat_rate'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
];

// Get VAT configuration
$vatConfig = $conn->prepare("
    SELECT * FROM re_vat_config
    WHERE company_id = ? AND is_active = 1
    ORDER BY effective_from DESC
    LIMIT 1
");
$vatConfig->execute([$currentCompanyId]);
$vatConfig = $vatConfig->fetch(PDO::FETCH_ASSOC);

$vatRate = $vatConfig ? (float)$vatConfig['vat_rate'] : 5.00;
$vatRegNumber = $vatConfig ? $vatConfig['vat_registration_number'] : null;

// Get Output VAT (VAT collected on sales) — unchanged credit-based total
$outputVatAccount = $conn->prepare("
    SELECT id FROM re_chart_of_accounts
    WHERE company_id = ? AND account_code = '2310' AND is_active = 1
");
$outputVatAccount->execute([$currentCompanyId]);
$outputVatAccount = $outputVatAccount->fetch(PDO::FETCH_ASSOC);

$outputVatTransactionsRaw = [];
if ($outputVatAccount) {
    $stmt = $conn->prepare("
        SELECT 
            gl.*,
            jh.journal_number,
            jh.journal_date,
            jh.journal_type,
            jh.description,
            jh.reference_type,
            jh.reference_id,
            jh.is_posted,
            jh.is_reversed,
            jh.approval_status
        FROM re_general_ledger gl
        JOIN re_journal_headers jh ON jh.id = gl.journal_id AND jh.company_id = gl.company_id
        WHERE gl.account_id = ? AND gl.company_id = ?
        AND gl.entry_date BETWEEN ? AND ?
        ORDER BY gl.entry_date ASC, gl.id ASC
    ");
    $stmt->execute([$outputVatAccount['id'], $currentCompanyId, $dateFrom, $dateTo]);
    $outputVatTransactionsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Input VAT: resolve via AP helper (setting/2320/1260) — net qualifying debits − credits
$inputVatAcc = function_exists('re_ap_input_vat_account') ? re_ap_input_vat_account($conn, $currentCompanyId) : null;
if (!$inputVatAcc) {
    $stmt = $conn->prepare("
        SELECT id, account_code FROM re_chart_of_accounts
        WHERE company_id = ? AND account_code = '2320' AND is_active = 1
    ");
    $stmt->execute([$currentCompanyId]);
    $inputVatAcc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$inputVatTransactionsRaw = [];
if ($inputVatAcc) {
    $stmt = $conn->prepare("
        SELECT 
            gl.*,
            jh.journal_number,
            jh.journal_date,
            jh.journal_type,
            jh.description,
            jh.reference_type,
            jh.reference_id,
            jh.is_posted,
            jh.is_reversed,
            jh.approval_status,
            (COALESCE(gl.debit_amount,0) - COALESCE(gl.credit_amount,0)) AS report_amount
        FROM re_general_ledger gl
        JOIN re_journal_headers jh ON jh.id = gl.journal_id AND jh.company_id = gl.company_id
        WHERE gl.account_id = ? AND gl.company_id = ?
        AND gl.entry_date BETWEEN ? AND ?
        ORDER BY gl.entry_date ASC, gl.id ASC
    ");
    $stmt->execute([(int)$inputVatAcc['id'], $currentCompanyId, $dateFrom, $dateTo]);
    $inputVatTransactionsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$outputAll = re_vat_enrich_rows($conn, $currentCompanyId, $outputVatTransactionsRaw, 'output', $vatRate);
$inputAll = re_vat_enrich_rows($conn, $currentCompanyId, $inputVatTransactionsRaw, 'input', $vatRate);

// Optional filters (display/export only — same VAT amount method on included rows)
$outputVatTransactions = array_values(array_filter($outputAll, fn($r) => re_vat_row_matches_filters($r, $filters)));
$inputVatTransactions = array_values(array_filter($inputAll, fn($r) => re_vat_row_matches_filters($r, $filters)));

// Official totals method (unchanged): Output = sum credits; Input = sum Dr − sum Cr
$outputVatTotal = round(array_sum(array_column($outputVatTransactions, 'credit_amount')), 2);
$inputVatDebits = round(array_sum(array_column($inputVatTransactions, 'debit_amount')), 2);
$inputVatCredits = round(array_sum(array_column($inputVatTransactions, 'credit_amount')), 2);
$inputVatTotal = round($inputVatDebits - $inputVatCredits, 2);
$netVatPayable = round($outputVatTotal - $inputVatTotal, 2);

// Display enrichment totals (taxable / gross) — derived for accountant reconciliation UI
$outputTaxableTotal = round(array_sum(array_column($outputVatTransactions, 'taxable_amount')), 2);
$outputGrossTotal = round(array_sum(array_column($outputVatTransactions, 'gross_amount')), 2);
$inputTaxableTotal = round(array_sum(array_column($inputVatTransactions, 'taxable_amount')), 2);
$inputGrossTotal = round(array_sum(array_column($inputVatTransactions, 'gross_amount')), 2);

// Filter dropdown options from unfiltered period data
$partyOptions = [];
$docTypeOptions = [];
$statusOptions = [];
$vatRateOptions = [];
foreach (array_merge($outputAll, $inputAll) as $r) {
    if (($r['party_name'] ?? '') !== '' && ($r['party_name'] ?? '') !== '—') {
        $partyOptions[$r['party_name']] = true;
    }
    if (!empty($r['doc_type'])) {
        $docTypeOptions[$r['doc_type']] = true;
    }
    if (!empty($r['status_label'])) {
        $statusOptions[$r['status_label']] = true;
    }
    if (isset($r['vat_rate'])) {
        $vatRateOptions[number_format((float)$r['vat_rate'], 2, '.', '')] = true;
    }
}
ksort($partyOptions, SORT_NATURAL | SORT_FLAG_CASE);
ksort($docTypeOptions, SORT_NATURAL | SORT_FLAG_CASE);
ksort($statusOptions, SORT_NATURAL | SORT_FLAG_CASE);
ksort($vatRateOptions, SORT_NUMERIC);

$companyName = '';
try {
    $st = $conn->prepare("SELECT name FROM companies WHERE id = ? LIMIT 1");
    $st->execute([$currentCompanyId]);
    $companyName = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {
    $companyName = '';
}

// CSV export (all new columns + section totals)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'vat_report_' . date('Y-m-d', strtotime($dateFrom)) . '_' . date('Y-m-d', strtotime($dateTo)) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['VAT Report - Real Estate']);
    if ($companyName !== '') {
        fputcsv($out, ['Company', $companyName]);
    }
    fputcsv($out, ['Period', $dateFrom . ' to ' . $dateTo]);
    fputcsv($out, ['VAT Rate %', money_fmt($vatRate)]);
    if ($vatRegNumber) {
        fputcsv($out, ['VAT Registration', $vatRegNumber]);
    }
    fputcsv($out, []);
    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Total Taxable Sales (excl. VAT)', money_fmt($outputTaxableTotal)]);
    fputcsv($out, ['Output VAT', money_fmt($outputVatTotal)]);
    fputcsv($out, ['Total Sales Including VAT', money_fmt($outputGrossTotal)]);
    fputcsv($out, ['Total Taxable Purchases (excl. VAT)', money_fmt($inputTaxableTotal)]);
    fputcsv($out, ['Recoverable Input VAT', money_fmt($inputVatTotal)]);
    fputcsv($out, ['Net VAT ' . ($netVatPayable >= 0 ? 'Payable' : 'Refundable'), money_fmt($netVatPayable)]);
    fputcsv($out, []);
    fputcsv($out, ['Method', 'Output VAT = sum of credits on Output VAT account; Input VAT = qualifying debits − credits. Taxable/Gross enriched from source documents when available.']);
    fputcsv($out, []);

    $headers = ['Section', 'Date', 'Journal Number', 'Document Type', 'Document Number', 'Customer / Vendor', 'Taxable Amount', 'VAT Amount', 'Gross Amount', 'VAT Rate %', 'Status', 'Description'];
    fputcsv($out, ['OUTPUT VAT (Collected on Sales)']);
    fputcsv($out, $headers);
    foreach ($outputVatTransactions as $row) {
        fputcsv($out, [
            'Output VAT',
            date('Y-m-d', strtotime($row['entry_date'])),
            $row['journal_number'],
            $row['doc_type'],
            $row['doc_number'],
            $row['party_name'],
            money_fmt($row['taxable_amount']),
            money_fmt($row['vat_amount']),
            money_fmt($row['gross_amount']),
            money_fmt($row['vat_rate']),
            $row['status_label'],
            $row['description_display'],
        ]);
    }
    fputcsv($out, ['', '', '', '', '', 'TOTAL', money_fmt($outputTaxableTotal), money_fmt($outputVatTotal), money_fmt($outputGrossTotal), '', '', '']);
    fputcsv($out, []);

    fputcsv($out, ['INPUT VAT (Recoverable)']);
    fputcsv($out, $headers);
    foreach ($inputVatTransactions as $row) {
        fputcsv($out, [
            'Input VAT',
            date('Y-m-d', strtotime($row['entry_date'])),
            $row['journal_number'],
            $row['doc_type'],
            $row['doc_number'],
            $row['party_name'],
            money_fmt($row['taxable_amount']),
            money_fmt($row['vat_amount']),
            money_fmt($row['gross_amount']),
            money_fmt($row['vat_rate']),
            $row['status_label'],
            $row['description_display'],
        ]);
    }
    fputcsv($out, ['', '', '', '', '', 'TOTAL', money_fmt($inputTaxableTotal), money_fmt($inputVatTotal), money_fmt($inputGrossTotal), '', '', '']);
    fclose($out);
    exit;
}

$pageTitle = 'VAT Report';
require_once __DIR__ . '/../includes/re_layout_header.php';

$filterQuery = http_build_query(array_filter([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'party' => $filters['party'] ?: null,
    'doc_type' => $filters['doc_type'] ?: null,
    'journal' => $filters['journal'] ?: null,
    'doc_number' => $filters['doc_number'] ?: null,
    'vat_rate' => $filters['vat_rate'] !== '' ? $filters['vat_rate'] : null,
    'status' => $filters['status'] ?: null,
], fn($v) => $v !== null && $v !== ''));
?>

<style>
.vat-summary-card { border-width: 2px; transition: box-shadow .15s ease; }
.vat-summary-card:hover { box-shadow: 0 .35rem .8rem rgba(0,0,0,.08); }
.vat-summary-card .vat-card-value { font-size: 1.35rem; font-weight: 700; letter-spacing: -.01em; }
.vat-summary-card .vat-card-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
.table-vat { font-size: .875rem; }
.table-vat th, .table-vat td { vertical-align: middle; padding: .45rem .6rem; white-space: nowrap; }
.table-vat td.vat-desc { white-space: normal; min-width: 140px; max-width: 220px; }
.table-vat td.vat-party { white-space: normal; min-width: 120px; max-width: 180px; }
.table-sticky thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8f9fa;
    box-shadow: inset 0 -1px 0 #dee2e6;
}
.badge-vat-teal { background-color: #20c997; color: #fff; }
.bg-teal { background-color: #20c997 !important; }
.vat-num { font-variant-numeric: tabular-nums; font-feature-settings: "tnum"; }
.table-vat-wrap { max-height: 520px; overflow: auto; }
@media print {
    .vat-summary-card { break-inside: avoid; }
    .table-vat-wrap { max-height: none !important; overflow: visible !important; }
    .table-sticky thead th { position: static; }
    .badge { border: 1px solid #999; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="page-header-label mb-0">
        <i class="bi bi-receipt"></i> VAT Report
        <?php if ($companyName !== ''): ?>
            <span class="text-muted fs-6 fw-normal ms-2"><?= h($companyName) ?></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 no-print">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
        <a class="btn btn-primary" href="?<?= h($filterQuery) ?>&export=csv">
            <i class="bi bi-download"></i> Export CSV
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card card-round mb-4 no-print">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3 col-lg-2">
                <label class="form-label"><i class="bi bi-calendar"></i> From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>" required>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label"><i class="bi bi-calendar"></i> To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>" required>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Customer / Vendor</label>
                <input type="text" name="party" class="form-control" list="partyList" value="<?= h($filters['party']) ?>" placeholder="Search name…">
                <datalist id="partyList">
                    <?php foreach (array_keys($partyOptions) as $p): ?>
                        <option value="<?= h($p) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Document Type</label>
                <select name="doc_type" class="form-select">
                    <option value="">All types</option>
                    <?php foreach (array_keys($docTypeOptions) as $dt): ?>
                        <option value="<?= h($dt) ?>" <?= strcasecmp($filters['doc_type'], $dt) === 0 ? 'selected' : '' ?>><?= h($dt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Journal Number</label>
                <input type="text" name="journal" class="form-control" value="<?= h($filters['journal']) ?>" placeholder="e.g. JRN-2026">
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Invoice / Bill #</label>
                <input type="text" name="doc_number" class="form-control" value="<?= h($filters['doc_number']) ?>" placeholder="e.g. INV-">
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">VAT Rate</label>
                <select name="vat_rate" class="form-select">
                    <option value="">All rates</option>
                    <?php foreach (array_keys($vatRateOptions) as $vr): ?>
                        <option value="<?= h($vr) ?>" <?= $filters['vat_rate'] !== '' && abs((float)$filters['vat_rate'] - (float)$vr) < 0.001 ? 'selected' : '' ?>><?= h($vr) ?>%</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach (array_keys($statusOptions) as $st): ?>
                        <option value="<?= h($st) ?>" <?= strcasecmp($filters['status'], $st) === 0 ? 'selected' : '' ?>><?= h($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 col-lg-4 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-funnel-fill"></i> Generate Report
                </button>
                <a href="vat_report.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Six summary cards -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card card-round vat-summary-card border-primary h-100">
            <div class="card-body py-3 text-center">
                <div class="vat-card-label text-primary mb-1">Taxable Sales</div>
                <div class="vat-card-value text-primary vat-num"><?= money_aed($outputTaxableTotal) ?></div>
                <small class="text-muted">Excluding VAT</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card card-round vat-summary-card border-primary h-100" style="border-color:#0d6efd!important;background:linear-gradient(180deg,#f0f6ff 0%,#fff 100%);">
            <div class="card-body py-3 text-center">
                <div class="vat-card-label text-primary mb-1">Output VAT</div>
                <div class="vat-card-value text-primary vat-num"><?= money_aed($outputVatTotal) ?></div>
                <small class="text-muted">VAT on sales</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card card-round vat-summary-card border-dark h-100">
            <div class="card-body py-3 text-center">
                <div class="vat-card-label text-dark mb-1">Sales Incl. VAT</div>
                <div class="vat-card-value text-dark vat-num"><?= money_aed($outputGrossTotal) ?></div>
                <small class="text-muted">Taxable + Output VAT</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card card-round vat-summary-card border-info h-100">
            <div class="card-body py-3 text-center">
                <div class="vat-card-label text-info mb-1">Taxable Purchases</div>
                <div class="vat-card-value text-info vat-num"><?= money_aed($inputTaxableTotal) ?></div>
                <small class="text-muted">Excluding VAT</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card card-round vat-summary-card border-info h-100" style="border-color:#0dcaf0!important;background:linear-gradient(180deg,#eefbff 0%,#fff 100%);">
            <div class="card-body py-3 text-center">
                <div class="vat-card-label text-info mb-1">Input VAT</div>
                <div class="vat-card-value text-info vat-num"><?= money_aed($inputVatTotal) ?></div>
                <small class="text-muted">Recoverable<?= $inputVatAcc ? ' · ' . h((string)($inputVatAcc['account_code'] ?? '')) : '' ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card card-round vat-summary-card border-<?= $netVatPayable >= 0 ? 'danger' : 'success' ?> h-100" style="background:linear-gradient(180deg,<?= $netVatPayable >= 0 ? '#fff5f5' : '#f0fff4' ?> 0%,#fff 100%);">
            <div class="card-body py-3 text-center">
                <div class="vat-card-label text-<?= $netVatPayable >= 0 ? 'danger' : 'success' ?> mb-1">
                    Net VAT <?= $netVatPayable >= 0 ? 'Payable' : 'Refundable' ?>
                </div>
                <div class="vat-card-value text-<?= $netVatPayable >= 0 ? 'danger' : 'success' ?> vat-num">
                    <?= money_aed(abs($netVatPayable)) ?>
                </div>
                <small class="text-muted"><?= $netVatPayable >= 0 ? 'Amount to pay to FTA' : 'VAT refundable' ?></small>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-light border small mb-4">
    <strong>Method (unchanged):</strong>
    Output VAT = sum of credits on Output VAT account (2310).
    Input VAT = qualifying debits <?= money_fmt($inputVatDebits) ?> − credits <?= money_fmt($inputVatCredits) ?>
    on Input VAT<?= $inputVatAcc ? ' ' . h((string)($inputVatAcc['account_code'] ?? '')) : '' ?>
    (Vendor Bills + Advance VAT Documents; reversals reduce via credit legs).
    Taxable / Gross columns are enriched from source documents when linked; otherwise derived from VAT ÷ rate.
</div>

<!-- VAT Configuration -->
<?php if ($vatConfig): ?>
<div class="card card-round mb-4">
    <div class="card-body py-3">
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <strong class="small text-muted">VAT Rate</strong><br>
                <?= money_fmt($vatRate) ?>%
            </div>
            <div class="col-6 col-md-3">
                <strong class="small text-muted">VAT Registration Number</strong><br>
                <?= h($vatRegNumber ?: 'Not configured') ?>
            </div>
            <div class="col-6 col-md-3">
                <strong class="small text-muted">Effective From</strong><br>
                <?= $vatConfig['effective_from'] ? date('Y-m-d', strtotime($vatConfig['effective_from'])) : '-' ?>
            </div>
            <div class="col-6 col-md-3">
                <strong class="small text-muted">Report Period</strong><br>
                <?= date('M d, Y', strtotime($dateFrom)) ?> to <?= date('M d, Y', strtotime($dateTo)) ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$renderVatTable = function (string $title, string $headerClass, string $footerClass, array $rows, float $taxableTot, float $vatTot, float $grossTot, string $emptyMsg, string $tableId) {
    ?>
    <div class="card card-round mb-4">
        <div class="card-header <?= h($headerClass) ?> text-white py-2">
            <h5 class="mb-0 fs-6">
                <?= $title ?>
                <span class="badge bg-light text-dark ms-2"><?= count($rows) ?> transaction<?= count($rows) === 1 ? '' : 's' ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive table-vat-wrap">
                <table class="table table-hover table-sm table-vat table-sticky mb-0" id="<?= h($tableId) ?>">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Journal #</th>
                            <th>Document Type</th>
                            <th>Document #</th>
                            <th>Customer / Vendor</th>
                            <th class="text-end">Taxable Amount</th>
                            <th class="text-end">VAT Amount</th>
                            <th class="text-end">Gross Amount</th>
                            <th>Status</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4"><?= h($emptyMsg) ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $trans): ?>
                                <tr>
                                    <td class="vat-num"><?= date('Y-m-d', strtotime($trans['entry_date'])) ?></td>
                                    <td>
                                        <a href="journal_entry_view.php?id=<?= (int)$trans['journal_id'] ?>" class="text-decoration-none fw-semibold">
                                            <?= h($trans['journal_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge <?= re_vat_type_badge_class($trans['doc_type']) ?>"><?= h($trans['doc_type']) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($trans['doc_url']) && $trans['doc_number'] !== '-'): ?>
                                            <a href="<?= h($trans['doc_url']) ?>" class="text-decoration-none text-danger fw-semibold">
                                                <?= h($trans['doc_number']) ?>
                                            </a>
                                        <?php else: ?>
                                            <code class="small"><?= h($trans['doc_number']) ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td class="vat-party"><?= h($trans['party_name']) ?></td>
                                    <td class="text-end vat-num"><?= money_aed($trans['taxable_amount']) ?></td>
                                    <td class="text-end vat-num fw-semibold"><?= money_aed($trans['vat_amount']) ?></td>
                                    <td class="text-end vat-num"><?= money_aed($trans['gross_amount']) ?></td>
                                    <td>
                                        <span class="badge <?= re_vat_status_badge_class($trans['status_label']) ?>"><?= h($trans['status_label']) ?></span>
                                    </td>
                                    <td class="vat-desc text-muted small"><?= h($trans['description_display'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="<?= h($footerClass) ?> fw-bold">
                                <td colspan="5" class="text-end">Totals</td>
                                <td class="text-end vat-num"><?= money_aed($taxableTot) ?></td>
                                <td class="text-end vat-num"><?= money_aed($vatTot) ?></td>
                                <td class="text-end vat-num"><?= money_aed($grossTot) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
};

$renderVatTable(
    '<i class="bi bi-arrow-up-circle"></i> Output VAT (VAT Collected on Sales)',
    'bg-primary',
    'table-primary',
    $outputVatTransactions,
    $outputTaxableTotal,
    $outputVatTotal,
    $outputGrossTotal,
    'No output VAT transactions',
    'outputVatTable'
);

$renderVatTable(
    '<i class="bi bi-arrow-down-circle"></i> Input VAT (Recoverable)',
    'bg-info',
    'table-info',
    $inputVatTransactions,
    $inputTaxableTotal,
    $inputVatTotal,
    $inputGrossTotal,
    'No input VAT transactions',
    'inputVatTable'
);
?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
