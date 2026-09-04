<?php
/**
 * Vendor Statement of Account (Phase 1)
 * Document-based: Bills + Payments + posted Advance Refunds
 * (Credit/Debit Notes deferred). Advance applications remain informational.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';
require_once __DIR__ . '/../includes/re_pdf_helpers.php';
require_once __DIR__ . '/export_excel_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$vendorId = (int)($_GET['vendor_id'] ?? 0);
$dateFrom = !empty($_GET['date_from']) ? (string)$_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? (string)$_GET['date_to'] : date('Y-m-d');
$buildingId = (int)($_GET['building_id'] ?? 0);
$balanceOnly = !empty($_GET['balance_only']);
$export = (string)($_GET['export'] ?? '');

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

$vendorsStmt = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name");
$vendorsStmt->execute([$companyId]);
$vendors = $vendorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$buildingsStmt = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildingsStmt->execute([$companyId]);
$buildings = $buildingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$vendor = null;
$opening = 0.0;
$closing = 0.0;
$rows = [];
$aging = ['current' => 0.0, '30' => 0.0, '60' => 0.0, '90' => 0.0, 'over90' => 0.0];
$periodBills = 0.0;
$periodPayments = 0.0;
$periodRefunds = 0.0;
$outstanding = 0.0;
$advanceAppsPeriod = [];
$advanceRefundsPeriod = [];
$quickPaidPeriod = [];
$soaAdvanceAvailable = 0.0;

if ($vendorId > 0) {
    $vs = $conn->prepare("SELECT * FROM re_vendors WHERE id = ? AND company_id = ? LIMIT 1");
    $vs->execute([$vendorId, $companyId]);
    $vendor = $vs->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($vendor) {
    $buildingFilterSql = '';
    $buildingParams = [];
    if ($buildingId > 0) {
        $buildingFilterSql = " AND EXISTS (
            SELECT 1 FROM re_vendor_invoice_items vii
            WHERE vii.company_id = vi.company_id AND vii.invoice_id = vi.id AND vii.building_id = ?
        )";
        $buildingParams = [$buildingId];
    }

    // Opening: bills before date_from minus payments before date_from
    $obBill = $conn->prepare("
        SELECT COALESCE(SUM(vi.total_amount), 0)
        FROM re_vendor_invoices vi
        WHERE vi.company_id = ? AND vi.vendor_id = ?
          AND vi.posting_status = 'posted'
          AND vi.status NOT IN ('void', 'cancelled', 'draft')
          AND vi.invoice_date < ?
          {$buildingFilterSql}
    ");
    $obBill->execute(array_merge([$companyId, $vendorId, $dateFrom], $buildingParams));
    $openBills = (float)$obBill->fetchColumn();

    // Payments before period: if building filter, only payments allocated to filtered bills
    if ($buildingId > 0) {
        $obPay = $conn->prepare("
            SELECT COALESCE(SUM(a.amount_allocated), 0)
            FROM re_vendor_payment_allocations a
            JOIN re_vendor_payments p ON p.id = a.vendor_payment_id AND p.company_id = a.company_id
            JOIN re_vendor_invoices vi ON vi.id = a.vendor_invoice_id AND vi.company_id = a.company_id
            WHERE a.company_id = ? AND p.vendor_id = ? AND p.status = 'posted' AND p.payment_date < ?
              AND vi.posting_status = 'posted' AND vi.status NOT IN ('void','cancelled','draft')
              AND EXISTS (
                SELECT 1 FROM re_vendor_invoice_items vii
                WHERE vii.company_id = vi.company_id AND vii.invoice_id = vi.id AND vii.building_id = ?
              )
        ");
        $obPay->execute([$companyId, $vendorId, $dateFrom, $buildingId]);
    } else {
        $obPay = $conn->prepare("
            SELECT COALESCE(SUM(p.amount), 0)
            FROM re_vendor_payments p
            WHERE p.company_id = ? AND p.vendor_id = ? AND p.status = 'posted' AND p.payment_date < ?
        ");
        $obPay->execute([$companyId, $vendorId, $dateFrom]);
    }
    $openPays = (float)$obPay->fetchColumn();
    // Posted advance refunds reverse unallocated payments (money returned by vendor).
    // Include only on all-buildings statements (advances are vendor-level, not building-scoped).
    $openRefunds = 0.0;
    if ($buildingId <= 0 && function_exists('re_ap_advance_refund_table_ready') && re_ap_advance_refund_table_ready($conn)) {
        $obRef = $conn->prepare("
            SELECT COALESCE(SUM(r.amount), 0)
            FROM re_vendor_advance_refunds r
            WHERE r.company_id = ? AND r.vendor_id = ?
              AND r.status = 'posted'
              AND r.refund_date < ?
        ");
        $obRef->execute([$companyId, $vendorId, $dateFrom]);
        $openRefunds = (float)$obRef->fetchColumn();
    }
    $opening = re_ap_money($openBills - $openPays + $openRefunds);

    // Period bills
    $billSql = "
        SELECT vi.id, vi.invoice_number, vi.invoice_date, vi.due_date, vi.total_amount,
               vi.paid_amount, vi.balance_due, vi.status, vi.posting_status
        FROM re_vendor_invoices vi
        WHERE vi.company_id = ? AND vi.vendor_id = ?
          AND vi.posting_status = 'posted'
          AND vi.status NOT IN ('void', 'cancelled', 'draft')
          AND vi.invoice_date BETWEEN ? AND ?
          {$buildingFilterSql}
        ORDER BY vi.invoice_date ASC, vi.id ASC
    ";
    $billStmt = $conn->prepare($billSql);
    $billStmt->execute(array_merge([$companyId, $vendorId, $dateFrom, $dateTo], $buildingParams));
    foreach ($billStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b) {
        $amt = (float)$b['total_amount'];
        $periodBills += $amt;
        $rows[] = [
            'date' => $b['invoice_date'],
            'type' => 'Bill',
            'ref' => $b['invoice_number'],
            'debit' => 0.0,
            'credit' => $amt,
            'link' => 'vendor_bill_view.php?id=' . (int)$b['id'],
            'status' => $b['status'],
            'sort' => $b['invoice_date'] . '-B-' . str_pad((string)$b['id'], 10, '0', STR_PAD_LEFT),
        ];
    }

    // Period payments
    if ($buildingId > 0) {
        $payStmt = $conn->prepare("
            SELECT p.id, p.payment_date, p.reference_number, p.amount,
                   COALESCE(SUM(a.amount_allocated), 0) AS filtered_amount
            FROM re_vendor_payments p
            JOIN re_vendor_payment_allocations a ON a.vendor_payment_id = p.id AND a.company_id = p.company_id
            JOIN re_vendor_invoices vi ON vi.id = a.vendor_invoice_id AND vi.company_id = a.company_id
            WHERE p.company_id = ? AND p.vendor_id = ? AND p.status = 'posted'
              AND p.payment_date BETWEEN ? AND ?
              AND EXISTS (
                SELECT 1 FROM re_vendor_invoice_items vii
                WHERE vii.company_id = vi.company_id AND vii.invoice_id = vi.id AND vii.building_id = ?
              )
            GROUP BY p.id, p.payment_date, p.reference_number, p.amount
            ORDER BY p.payment_date ASC, p.id ASC
        ");
        $payStmt->execute([$companyId, $vendorId, $dateFrom, $dateTo, $buildingId]);
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $amt = (float)$p['filtered_amount'];
            if ($amt <= 0.005) {
                continue;
            }
            $periodPayments += $amt;
            $rows[] = [
                'date' => $p['payment_date'],
                'type' => 'Payment',
                'ref' => $p['reference_number'] ?: ('PAY-' . $p['id']),
                'debit' => $amt,
                'credit' => 0.0,
                'link' => 'vendor_payments.php?q=' . urlencode((string)($p['reference_number'] ?: ('PAY-' . $p['id']))),
                'status' => 'posted',
                'sort' => $p['payment_date'] . '-P-' . str_pad((string)$p['id'], 10, '0', STR_PAD_LEFT),
            ];
        }
    } else {
        $payStmt = $conn->prepare("
            SELECT p.id, p.payment_date, p.reference_number, p.amount
            FROM re_vendor_payments p
            WHERE p.company_id = ? AND p.vendor_id = ? AND p.status = 'posted'
              AND p.payment_date BETWEEN ? AND ?
            ORDER BY p.payment_date ASC, p.id ASC
        ");
        $payStmt->execute([$companyId, $vendorId, $dateFrom, $dateTo]);
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $amt = (float)$p['amount'];
            $periodPayments += $amt;
            $rows[] = [
                'date' => $p['payment_date'],
                'type' => 'Payment',
                'ref' => $p['reference_number'] ?: ('PAY-' . $p['id']),
                'debit' => $amt,
                'credit' => 0.0,
                'link' => 'vendor_payments.php?q=' . urlencode((string)($p['reference_number'] ?: ('PAY-' . $p['id']))),
                'status' => 'posted',
                'sort' => $p['payment_date'] . '-P-' . str_pad((string)$p['id'], 10, '0', STR_PAD_LEFT),
            ];
        }
    }

    // Posted advance refunds in period: vendor returned cash → reduces statement credit / overpayment.
    if ($buildingId <= 0 && function_exists('re_ap_advance_refund_table_ready') && re_ap_advance_refund_table_ready($conn)) {
        $refStmt = $conn->prepare("
            SELECT r.id, r.amount, r.refund_date, r.reference_number, r.vendor_payment_id,
                   vp.reference_number AS payment_ref
            FROM re_vendor_advance_refunds r
            JOIN re_vendor_payments vp ON vp.id = r.vendor_payment_id AND vp.company_id = r.company_id
            WHERE r.company_id = ? AND r.vendor_id = ?
              AND r.status = 'posted'
              AND r.refund_date BETWEEN ? AND ?
            ORDER BY r.refund_date ASC, r.id ASC
        ");
        $refStmt->execute([$companyId, $vendorId, $dateFrom, $dateTo]);
        foreach ($refStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rf) {
            $amt = (float)$rf['amount'];
            if ($amt <= 0.005) {
                continue;
            }
            $periodRefunds += $amt;
            $payRef = (string)($rf['payment_ref'] ?: ('PAY-' . $rf['vendor_payment_id']));
            $rows[] = [
                'date' => $rf['refund_date'],
                'type' => 'Advance Refund',
                'ref' => 'REF-' . (int)$rf['id'] . ' (' . $payRef . ')',
                'debit' => 0.0,
                'credit' => $amt,
                'link' => 'vendor_advance_refund_view.php?id=' . (int)$rf['id'],
                'status' => 'posted',
                'sort' => $rf['refund_date'] . '-R-' . str_pad((string)$rf['id'], 10, '0', STR_PAD_LEFT),
            ];
        }
        $periodRefunds = re_ap_money($periodRefunds);
    }

    usort($rows, static fn($a, $b) => strcmp($a['sort'], $b['sort']));

    $running = $opening;
    foreach ($rows as &$r) {
        $running = re_ap_money($running + $r['credit'] - $r['debit']);
        $r['balance'] = $running;
    }
    unset($r);
    $closing = $running;

    // Outstanding / aging as of date_to
    $ageSql = "
        SELECT vi.id, vi.invoice_number, vi.due_date,
               COALESCE(vi.balance_due, vi.total_amount - COALESCE(vi.paid_amount, 0)) AS bal
        FROM re_vendor_invoices vi
        WHERE vi.company_id = ? AND vi.vendor_id = ?
          AND vi.posting_status = 'posted'
          AND vi.status NOT IN ('paid', 'void', 'cancelled', 'draft')
          AND COALESCE(vi.balance_due, vi.total_amount - COALESCE(vi.paid_amount, 0)) > 0.005
          AND vi.invoice_date <= ?
          {$buildingFilterSql}
    ";
    $ageStmt = $conn->prepare($ageSql);
    $ageStmt->execute(array_merge([$companyId, $vendorId, $dateTo], $buildingParams));
    foreach ($ageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ob) {
        $bal = (float)$ob['bal'];
        $outstanding += $bal;
        $days = (int)floor((strtotime($dateTo) - strtotime((string)$ob['due_date'])) / 86400);
        if ($days <= 0) {
            $aging['current'] += $bal;
        } elseif ($days <= 30) {
            $aging['30'] += $bal;
        } elseif ($days <= 60) {
            $aging['60'] += $bal;
        } elseif ($days <= 90) {
            $aging['90'] += $bal;
        } else {
            $aging['over90'] += $bal;
        }
    }
    $outstanding = re_ap_money($outstanding);
    foreach ($aging as $k => $v) {
        $aging[$k] = re_ap_money($v);
    }

    // Advance applications in period (informational; do not alter AP statement running balance)
    $advanceAppsPeriod = [];
    if (re_ap_advance_table_ready($conn)) {
        $aa = $conn->prepare("
            SELECT a.id, a.amount, a.status, a.created_at, a.journal_id,
                   a.vendor_payment_id, a.vendor_invoice_id,
                   vi.invoice_number, vp.payment_date, vp.reference_number
            FROM re_vendor_advance_applications a
            JOIN re_vendor_invoices vi ON vi.id = a.vendor_invoice_id AND vi.company_id = a.company_id
            JOIN re_vendor_payments vp ON vp.id = a.vendor_payment_id AND vp.company_id = a.company_id
            WHERE a.company_id = ? AND a.vendor_id = ?
              AND DATE(a.created_at) BETWEEN ? AND ?
            ORDER BY a.created_at ASC, a.id ASC
        ");
        $aa->execute([$companyId, $vendorId, $dateFrom, $dateTo]);
        $advanceAppsPeriod = $aa->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $advanceRefundsPeriod = [];
    if (function_exists('re_ap_advance_refund_table_ready') && re_ap_advance_refund_table_ready($conn)) {
        $ar = $conn->prepare("
            SELECT r.id, r.amount, r.status, r.refund_date, r.journal_id,
                   r.vendor_payment_id, r.reference_number, vp.reference_number AS payment_ref
            FROM re_vendor_advance_refunds r
            JOIN re_vendor_payments vp ON vp.id = r.vendor_payment_id AND vp.company_id = r.company_id
            WHERE r.company_id = ? AND r.vendor_id = ?
              AND r.refund_date BETWEEN ? AND ?
            ORDER BY r.refund_date ASC, r.id ASC
        ");
        $ar->execute([$companyId, $vendorId, $dateFrom, $dateTo]);
        $advanceRefundsPeriod = $ar->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $soaAdvanceAvailable = re_ap_vendor_advance_balance($conn, $companyId, $vendorId);

    // Quick Paid Expenses in period (informational; do not alter AP statement running balance)
    $quickPaidPeriod = [];
    try {
        $qpe = $conn->prepare("
            SELECT id, expense_number, expense_date, total, paid_via, reference_no, journal_id, status
            FROM erp_expense_headers
            WHERE company_id = ?
              AND source_module = 'realestate'
              AND vendor_id = ?
              AND status = 'posted'
              AND expense_date BETWEEN ? AND ?
            ORDER BY expense_date ASC, id ASC
        ");
        $qpe->execute([$companyId, $vendorId, $dateFrom, $dateTo]);
        $quickPaidPeriod = $qpe->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $quickPaidPeriod = [];
    }

    if ($balanceOnly && $outstanding <= 0.005 && abs($closing) <= 0.005) {
        $rows = [];
    }
}

if ($export === 'excel' && $vendor) {
    $exportRows = [];
    $exportRows[] = [
        'date' => $dateFrom,
        'type' => 'Opening Balance',
        'ref' => '',
        'debit' => '',
        'credit' => '',
        'balance' => $opening,
    ];
    foreach ($rows as $r) {
        $exportRows[] = [
            'date' => $r['date'],
            'type' => $r['type'],
            'ref' => $r['ref'],
            'debit' => $r['debit'] > 0 ? $r['debit'] : '',
            'credit' => $r['credit'] > 0 ? $r['credit'] : '',
            'balance' => $r['balance'],
        ];
    }
    accounting_export_excel_or_csv(
        $exportRows,
        [
            'date' => 'Date',
            'type' => 'Type',
            'ref' => 'Reference',
            'debit' => 'Debit (Payment)',
            'credit' => 'Credit (Bill)',
            'balance' => 'Running Balance',
        ],
        'vendor_soa_' . preg_replace('/[^a-z0-9]+/i', '_', $vendor['vendor_name']) . '_' . $dateTo,
        'Vendor Statement of Account — ' . $vendor['vendor_name']
    );
    exit;
}

if ($export === 'pdf' && $vendor) {
    // Vendor-facing SOA: clean Zoho/Xero-style document (activity ledger only).
    // Internal detail tables stay on the on-screen statement page.
    $company = re_pdf_company($conn, $companyId);
    $docSettings = re_pdf_document_settings($conn, $companyId);
    $logo = re_pdf_logo_data_uri((string)($company['logo_path'] ?? ''));
    $currency = $company['currency'] ?: 'AED';
    $closingBalance = (float)$closing;
    $isCreditBalance = $closingBalance < -0.005;
    $balanceAbs = abs($closingBalance);
    $soaAdvance = (float)$soaAdvanceAvailable;
    $filename = 'vendor-soa-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $vendor['vendor_name']) . '-' . $dateTo . '.pdf';

    $buildingName = '';
    if ($buildingId > 0) {
        foreach ($buildings as $b) {
            if ((int)$b['id'] === $buildingId) {
                $buildingName = (string)$b['name'];
                break;
            }
        }
        if ($buildingName === '') {
            $buildingName = '#' . $buildingId;
        }
    }

    $vendorAddress = implode(', ', array_filter([
        trim((string)($vendor['address'] ?? '')),
        trim((string)($vendor['city'] ?? '')),
        trim((string)($vendor['state'] ?? '')),
        trim((string)($vendor['postal_code'] ?? '')),
        trim((string)($vendor['country'] ?? '')),
    ]));

    $agingTotal = (float)$aging['current'] + (float)$aging['30'] + (float)$aging['60'] + (float)$aging['90'] + (float)$aging['over90'];

    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of Account — <?= h($vendor['vendor_name']) ?></title>
    <style><?= re_pdf_soa_styles() ?></style>
</head>
<body>
<div class="doc">
    <div class="accent"></div>
    <div class="top">
        <div class="top-left">
            <?= re_pdf_letterhead_html($company, $docSettings, $logo) ?>
        </div>
        <div class="top-right">
            <div class="title">Statement of Account</div>
            <table class="soa-meta" style="width:100%;">
                <tr><td class="k">Statement date</td><td class="v"><?= h(re_pdf_fmt_date($dateTo)) ?></td></tr>
                <tr><td class="k">Period</td><td class="v"><?= h(re_pdf_fmt_date($dateFrom)) ?> – <?= h(re_pdf_fmt_date($dateTo)) ?></td></tr>
                <tr><td class="k">Currency</td><td class="v"><?= h($currency) ?></td></tr>
                <?php if ($buildingName !== ''): ?>
                <tr><td class="k">Building</td><td class="v"><?= h($buildingName) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="grid parties">
        <div class="col">
            <div class="panel" style="margin-bottom:0;">
                <div class="label">To</div>
                <div class="value"><?= h($vendor['vendor_name']) ?></div>
                <?php if (!empty($vendor['contact_person'])): ?><div><?= h($vendor['contact_person']) ?></div><?php endif; ?>
                <?php if ($vendorAddress !== ''): ?><div class="muted"><?= h($vendorAddress) ?></div><?php endif; ?>
                <?php if (!empty($vendor['phone'])): ?><div class="muted">Phone: <?= h($vendor['phone']) ?></div><?php endif; ?>
                <?php if (!empty($vendor['email'])): ?><div class="muted">Email: <?= h($vendor['email']) ?></div><?php endif; ?>
                <?php if (!empty($vendor['tax_number'])): ?><div class="muted">TRN: <?= h($vendor['tax_number']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col">
            <div class="panel" style="margin-bottom:0;">
                <div class="label">Account summary</div>
                <div>Opening balance: <strong><?= m($opening) ?> <?= h($currency) ?></strong></div>
                <div>Bills this period: <strong><?= m($periodBills) ?> <?= h($currency) ?></strong></div>
                <div>Payments this period: <strong><?= m($periodPayments) ?> <?= h($currency) ?></strong></div>
                <?php if ($periodRefunds > 0.005): ?>
                <div>Advance refunds this period: <strong><?= m($periodRefunds) ?> <?= h($currency) ?></strong></div>
                <?php endif; ?>
                <?php if ($soaAdvance > 0.005): ?>
                <div class="muted" style="margin-top:6px;">Unapplied advances on file: <?= m($soaAdvance) ?> <?= h($currency) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <table class="summary-box">
        <tr>
            <td>
                <div class="k">Opening balance</div>
                <div class="v"><?= m($opening) ?></div>
            </td>
            <td>
                <div class="k">Invoices / bills</div>
                <div class="v"><?= m($periodBills) ?></div>
            </td>
            <td>
                <div class="k"><?= $periodRefunds > 0.005 ? 'Payments − refunds' : 'Payments' ?></div>
                <div class="v"><?= m(max(0, $periodPayments - $periodRefunds)) ?></div>
            </td>
            <td class="<?= $isCreditBalance ? 'balance-credit' : 'balance-due' ?>">
                <div class="k"><?= $isCreditBalance ? 'Credit balance' : 'Balance due' ?></div>
                <div class="v"><?= m($balanceAbs) ?> <?= h($currency) ?></div>
            </td>
        </tr>
    </table>
    <?php if ($isCreditBalance): ?>
        <div class="note" style="margin-top:-10px;margin-bottom:12px;">
            Credit balance is the net amount still with the vendor after bills, payments, and posted advance refunds
            (<?= m($balanceAbs) ?> <?= h($currency) ?>).
            <?php if ($soaAdvance > 0.005): ?>
            This matches available unapplied advances of <?= m($soaAdvance) ?> <?= h($currency) ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="section-title" style="margin-top:4px;">Account activity</div>
    <table class="txn">
        <thead>
            <tr>
                <th style="width:12%;">Date</th>
                <th style="width:18%;">Activity</th>
                <th>Reference</th>
                <th class="right" style="width:14%;">Bill</th>
                <th class="right" style="width:14%;">Payment</th>
                <th class="right" style="width:14%;">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr class="open">
                <td><?= h(re_pdf_fmt_date($dateFrom)) ?></td>
                <td>Opening Balance</td>
                <td></td>
                <td class="right"></td>
                <td class="right"></td>
                <td class="right"><?= m($opening) ?></td>
            </tr>
            <?php foreach ($rows as $i => $r): ?>
            <?php
                if ((string)$r['type'] === 'Bill') {
                    $desc = 'Vendor bill';
                } elseif ((string)$r['type'] === 'Advance Refund') {
                    $desc = 'Advance refund';
                } else {
                    $desc = 'Payment made';
                }
            ?>
            <tr class="<?= ($i % 2) === 1 ? 'alt' : '' ?>">
                <td><?= h(re_pdf_fmt_date((string)$r['date'])) ?></td>
                <td><?= h($desc) ?></td>
                <td><?= h((string)$r['ref']) ?></td>
                <td class="right"><?= $r['credit'] > 0 ? m($r['credit']) : '—' ?></td>
                <td class="right"><?= $r['debit'] > 0 ? m($r['debit']) : '—' ?></td>
                <td class="right"><?= m($r['balance']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;">No transactions in this period.</td></tr>
            <?php endif; ?>
            <tr class="close">
                <td colspan="3">Closing balance as at <?= h(re_pdf_fmt_date($dateTo)) ?></td>
                <td class="right"><?= m($periodBills + $periodRefunds) ?></td>
                <td class="right"><?= m($periodPayments) ?></td>
                <td class="right"><?= m($closingBalance) ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ($agingTotal > 0.005): ?>
    <div class="section-title">Outstanding aging (open bills)</div>
    <table class="aging">
        <thead>
            <tr>
                <th>Current</th>
                <th>1–30 days</th>
                <th>31–60 days</th>
                <th>61–90 days</th>
                <th>90+ days</th>
                <th>Total outstanding</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= m($aging['current']) ?></td>
                <td><?= m($aging['30']) ?></td>
                <td><?= m($aging['60']) ?></td>
                <td><?= m($aging['90']) ?></td>
                <td><?= m($aging['over90']) ?></td>
                <td><?= m($agingTotal) ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="footer">
        This statement is computer-generated by <?= h($company['name']) ?> and is intended for <?= h($vendor['vendor_name']) ?>.<br>
        Bills and advance refunds increase the balance; payments reduce it.
        <?php if ($soaAdvance > 0.005): ?>
        Remaining unapplied advances: <?= m($soaAdvance) ?> <?= h($currency) ?>.
        <?php endif; ?>
    </div>
</div>
</body>
</html>
    <?php
    $html = ob_get_clean();
    re_pdf_output($html, $filename);
}

$reApUiEnhanced = true;
$pageTitle = 'Vendor Statement of Account';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 re-ap-print-hide">
    <div class="page-header-label">
        <i class="bi bi-file-earmark-ruled me-1"></i>
        Vendor Statement of Account
    </div>
    <div class="d-flex gap-2">
        <?php if ($vendor): ?>
            <a class="btn btn-outline-secondary" target="_blank" href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'pdf']))) ?>">
                <i class="bi bi-printer"></i> Print / PDF
            </a>
            <a class="btn btn-outline-success" href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>">
                <i class="bi bi-file-earmark-excel"></i> Excel
            </a>
        <?php endif; ?>
        <a href="ap_aging.php" class="btn btn-outline-primary">AP Aging</a>
        <a href="../vendors.php" class="btn btn-outline-secondary">Vendors</a>
    </div>
</div>

<div class="alert alert-light border small re-ap-print-hide">
    Statement includes <strong>Bills</strong>, <strong>Payments</strong>, and posted <strong>Advance Refunds</strong>.
    Vendor Credit/Debit Notes are deferred to a later accounting enhancement.
</div>

<div class="card card-round mb-4 re-ap-print-hide">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select" required>
                    <option value="">-- Select Vendor --</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>>
                            <?= h($v['vendor_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Building</label>
                <select name="building_id" class="form-select">
                    <option value="0">All Buildings</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $buildingId === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= h($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="balance_only" value="1" id="balOnly" <?= $balanceOnly ? 'checked' : '' ?>>
                    <label class="form-check-label" for="balOnly">With balance focus</label>
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Load Statement</button>
            </div>
        </form>
    </div>
</div>

<?php if ($vendor): ?>
    <div class="mb-3">
        <h5 class="mb-0"><?= h($vendor['vendor_name']) ?></h5>
        <div class="text-muted small">
            <?= h($vendor['contact_person'] ?? '-') ?> · <?= h($vendor['phone'] ?? '-') ?> · <?= h($vendor['email'] ?? '-') ?>
            · Period <?= h($dateFrom) ?> to <?= h($dateTo) ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card re-ap-card-metric"><div class="card-body"><div class="metric-label">Opening Balance</div><div class="metric-value"><?= m($opening) ?></div></div></div></div>
        <div class="col-md-3"><div class="card re-ap-card-metric"><div class="card-body"><div class="metric-label">Bills (Period)</div><div class="metric-value"><?= m($periodBills) ?></div></div></div></div>
        <div class="col-md-3"><div class="card re-ap-card-metric"><div class="card-body"><div class="metric-label">Payments (Period)</div><div class="metric-value text-success"><?= m($periodPayments) ?></div></div></div></div>
        <div class="col-md-3"><div class="card re-ap-card-metric"><div class="card-body"><div class="metric-label">Advance Refunds (Period)</div><div class="metric-value"><?= m($periodRefunds) ?></div></div></div></div>
        <div class="col-md-3"><div class="card re-ap-card-metric"><div class="card-body"><div class="metric-label">Closing / Outstanding</div><div class="metric-value text-danger"><?= m($outstanding > 0.005 ? $outstanding : $closing) ?></div></div></div></div>
        <?php
        $soaAdvance = $soaAdvanceAvailable;
        $soaGross = $outstanding > 0.005 ? $outstanding : $closing;
        // When open bills exist: net = bills owed minus advances available to apply.
        // When no open bills: statement closing already nets payments and refunds; do not subtract advances again.
        $soaNet = $outstanding > 0.005 ? ($soaGross - $soaAdvance) : $soaGross;
        ?>
        <div class="col-md-3"><div class="card re-ap-card-metric"><div class="card-body"><div class="metric-label">Gross AP / Closing</div><div class="metric-value"><?= m($soaGross) ?></div></div></div></div>
        <div class="col-md-3"><div class="card re-ap-card-metric"><div class="card-body"><div class="metric-label">Available Vendor Advances</div><div class="metric-value text-success"><?= m($soaAdvance) ?></div></div></div></div>
        <div class="col-md-3"><div class="card re-ap-card-metric"><div class="card-body"><div class="metric-label">Net Vendor Position</div><div class="metric-value"><?= m($soaNet) ?></div></div></div></div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header">Aging Summary (as of <?= h($dateTo) ?>)</div>
        <div class="card-body">
            <div class="row text-center g-2">
                <?php foreach (['current' => 'Current', '30' => '1-30', '60' => '31-60', '90' => '61-90', 'over90' => '90+'] as $k => $label): ?>
                    <div class="col">
                        <div class="border rounded p-2">
                            <div class="small text-muted"><?= h($label) ?></div>
                            <strong><?= m($aging[$k]) ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-text mt-2">Aging buckets are gross bill balances only (advances are not netted into buckets).</div>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Vendor Advance Applications (Period)</span>
            <span class="small text-muted">Available advance: <strong><?= m($soaAdvanceAvailable) ?></strong> AED</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover re-ap-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Payment</th>
                        <th>Bill</th>
                        <th>Status</th>
                        <th class="text-end">Amount</th>
                        <th class="re-ap-print-hide"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($advanceAppsPeriod as $aa): ?>
                        <tr>
                            <td><?= h(substr((string)$aa['created_at'], 0, 10)) ?></td>
                            <td><?= h($aa['reference_number'] ?: ('PAY-' . $aa['vendor_payment_id'])) ?></td>
                            <td><?= h($aa['invoice_number']) ?></td>
                            <td><span class="badge bg-<?= ($aa['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>"><?= h($aa['status']) ?></span></td>
                            <td class="text-end"><?= m($aa['amount']) ?></td>
                            <td class="re-ap-print-hide">
                                <a href="vendor_bill_view.php?id=<?= (int)$aa['vendor_invoice_id'] ?>" class="btn btn-sm btn-outline-primary">Bill</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$advanceAppsPeriod): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No advance applications in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Vendor Advance Refunds (Period)</span>
            <a href="vendor_advance_refunds.php?vendor_id=<?= (int)$vendorId ?>" class="small re-ap-print-hide">All refunds</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover re-ap-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Refund</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th class="text-end">Amount</th>
                        <th class="re-ap-print-hide"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($advanceRefundsPeriod as $rr): ?>
                        <tr>
                            <td><?= h($rr['refund_date']) ?></td>
                            <td>REF-<?= (int)$rr['id'] ?></td>
                            <td><?= h($rr['payment_ref'] ?: ('PAY-' . $rr['vendor_payment_id'])) ?></td>
                            <td><span class="badge bg-<?= ($rr['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>"><?= h($rr['status']) ?></span></td>
                            <td class="text-end"><?= m($rr['amount']) ?></td>
                            <td class="re-ap-print-hide">
                                <a href="vendor_advance_refund_view.php?id=<?= (int)$rr['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$advanceRefundsPeriod): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No advance refunds in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Quick Paid Expenses (Period)</span>
            <span class="small text-muted">Informational only — not included in AP running balance</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover re-ap-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Expense #</th>
                        <th>Paid via</th>
                        <th>Reference</th>
                        <th class="text-end">Total</th>
                        <th class="re-ap-print-hide"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quickPaidPeriod as $qe): ?>
                        <tr>
                            <td><?= h($qe['expense_date']) ?></td>
                            <td><?= h($qe['expense_number']) ?></td>
                            <td><?= h(ucfirst((string)($qe['paid_via'] ?? ''))) ?></td>
                            <td><?= h($qe['reference_no'] ?? '') ?></td>
                            <td class="text-end"><?= m($qe['total']) ?></td>
                            <td class="re-ap-print-hide">
                                <a href="../expense_edit.php?id=<?= (int)$qe['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                <?php if (!empty($qe['journal_id'])): ?>
                                    <a href="journal_entry_view.php?id=<?= (int)$qe['journal_id'] ?>" class="btn btn-sm btn-outline-secondary">Journal</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$quickPaidPeriod): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No quick paid expenses in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header d-flex justify-content-between">
            <span>Chronological Statement</span>
            <span class="small text-muted">Credit = Bill / Advance Refund · Debit = Payment · Balance = AP / overpayment</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover re-ap-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Balance</th>
                        <th class="re-ap-print-hide"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="table-secondary">
                        <td><?= h($dateFrom) ?></td>
                        <td colspan="2"><strong>Opening Balance</strong></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end"><strong><?= m($opening) ?></strong></td>
                        <td class="re-ap-print-hide"></td>
                    </tr>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $typeBadge = 'success';
                        if ($r['type'] === 'Bill') {
                            $typeBadge = 'primary';
                        } elseif ($r['type'] === 'Advance Refund') {
                            $typeBadge = 'warning';
                        }
                        ?>
                        <tr>
                            <td><?= h($r['date']) ?></td>
                            <td>
                                <span class="badge bg-<?= $typeBadge ?>">
                                    <?= h($r['type']) ?>
                                </span>
                            </td>
                            <td><?= h($r['ref']) ?></td>
                            <td class="text-end"><?= $r['debit'] > 0 ? m($r['debit']) : '-' ?></td>
                            <td class="text-end"><?= $r['credit'] > 0 ? m($r['credit']) : '-' ?></td>
                            <td class="text-end"><?= m($r['balance']) ?></td>
                            <td class="re-ap-print-hide">
                                <a href="<?= h($r['link']) ?>" class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No transactions in this period.</td></tr>
                    <?php endif; ?>
                    <tr class="table-light fw-semibold">
                        <td colspan="3" class="text-end">Closing Balance</td>
                        <td class="text-end"><?= m($periodPayments) ?></td>
                        <td class="text-end"><?= m($periodBills + $periodRefunds) ?></td>
                        <td class="text-end"><?= m($closing) ?></td>
                        <td class="re-ap-print-hide"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
