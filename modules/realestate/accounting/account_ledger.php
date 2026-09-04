<?php
/**
 * Real Estate Accounting - Account Ledger / Tenant Activity
 * Shows accounting sub-ledger entries plus legacy-compatible tenant activity.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$ledgerId = !empty($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
$tenantId = !empty($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;
$tenantSearch = trim((string)($_GET['tenant_search'] ?? ''));
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

$arAccount = $conn->prepare("SELECT id, account_code, account_name FROM re_chart_of_accounts WHERE company_id = ? AND account_code = '1310' AND is_active = 1 LIMIT 1");
$arAccount->execute([$currentCompanyId]);
$arAccount = $arAccount->fetch(PDO::FETCH_ASSOC);

if (!$tenantId && $ledgerId) {
    $stmt = $conn->prepare("SELECT sub_account_id FROM re_account_ledgers WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$ledgerId, $currentCompanyId]);
    $tenantId = (int)($stmt->fetchColumn() ?: 0) ?: null;
}

$tenantWhere = ['t.company_id = ?', 't.is_active = 1'];
$tenantParams = [$currentCompanyId];
if ($tenantSearch !== '') {
    $tenantWhere[] = "(t.company_name LIKE ? OR CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR t.phone LIKE ? OR t.email LIKE ?)";
    $like = '%' . $tenantSearch . '%';
    array_push($tenantParams, $like, $like, $like, $like);
}
$tenantStmt = $conn->prepare("\n    SELECT t.id, t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone, t.email,\n           al.id AS ledger_id, al.current_balance, al.opening_balance\n    FROM re_tenants t\n    LEFT JOIN re_account_ledgers al ON al.sub_account_id = t.id\n        AND al.company_id = t.company_id\n        AND al.sub_account_type = 'tenant'\n        " . ($arAccount ? "AND al.account_id = " . (int)$arAccount['id'] : "AND 1=0") . "\n    WHERE " . implode(' AND ', $tenantWhere) . "\n    ORDER BY COALESCE(NULLIF(t.company_name,''), t.last_name, t.first_name)\n    LIMIT 250\n");
$tenantStmt->execute($tenantParams);
$tenants = $tenantStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$tenantInfo = null;
$ledgerInfo = null;
$ledgerEntries = [];
$openingBalance = 0.0;
$closingBalance = 0.0;
$activityRows = [];
$activityDebit = 0.0;
$activityCredit = 0.0;

if ($tenantId) {
    $stmt = $conn->prepare("\n        SELECT t.*, al.id AS ledger_id, al.current_balance, al.opening_balance, al.sub_account_name\n        FROM re_tenants t\n        LEFT JOIN re_account_ledgers al ON al.sub_account_id = t.id\n            AND al.company_id = t.company_id\n            AND al.sub_account_type = 'tenant'\n            " . ($arAccount ? "AND al.account_id = " . (int)$arAccount['id'] : "AND 1=0") . "\n        WHERE t.id = ? AND t.company_id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$tenantId, $currentCompanyId]);
    $tenantInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($tenantInfo && !empty($tenantInfo['ledger_id'])) {
        $ledgerId = (int)$tenantInfo['ledger_id'];
        $ledgerInfo = $tenantInfo;
        $stmt = $conn->prepare("SELECT balance FROM re_account_ledger_entries WHERE ledger_id = ? AND company_id = ? AND entry_date < ? ORDER BY entry_date DESC, id DESC LIMIT 1");
        $stmt->execute([$ledgerId, $currentCompanyId, $dateFrom]);
        $openingEntry = $stmt->fetch(PDO::FETCH_ASSOC);
        $openingBalance = $openingEntry ? (float)$openingEntry['balance'] : (float)($tenantInfo['opening_balance'] ?? 0);
        $stmt = $conn->prepare("\n            SELECT ale.*, jh.journal_number, jh.journal_type, jh.journal_date\n            FROM re_account_ledger_entries ale\n            JOIN re_journal_headers jh ON jh.id = ale.journal_id\n            WHERE ale.ledger_id = ? AND ale.company_id = ? AND ale.entry_date BETWEEN ? AND ?\n            ORDER BY ale.entry_date ASC, ale.id ASC\n        ");
        $stmt->execute([$ledgerId, $currentCompanyId, $dateFrom, $dateTo]);
        $ledgerEntries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $closingBalance = $ledgerEntries ? (float)end($ledgerEntries)['balance'] : $openingBalance;
    }

    $inv = $conn->prepare("\n        SELECT i.id, i.invoice_number, i.invoice_date AS txn_date, i.total_amount, i.outstanding_amount,\n               l.lease_number, COALESCE(l.accounting_mode,'legacy') AS mode, b.name AS building_name, u.unit_number\n        FROM re_invoices i\n        JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id\n        JOIN re_units u ON u.id = l.unit_id\n        JOIN re_buildings b ON b.id = u.building_id\n        WHERE l.tenant_id = ? AND i.company_id = ? AND i.invoice_date BETWEEN ? AND ? AND i.status <> 'cancelled'\n    ");
    $inv->execute([$tenantId, $currentCompanyId, $dateFrom, $dateTo]);
    foreach ($inv->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $activityRows[] = [
            'date' => $row['txn_date'], 'mode' => $row['mode'], 'source' => 'Invoice', 'ref' => $row['invoice_number'],
            'lease' => $row['lease_number'], 'unit' => $row['building_name'] . ' - ' . $row['unit_number'],
            'description' => 'Issued invoice', 'debit' => (float)$row['total_amount'], 'credit' => 0.0,
            'link' => 'billing_invoice_view.php?id=' . (int)$row['id'],
        ];
    }

    $legacy = $conn->prepare("\n        SELECT li.id, li.installment_date, li.amount, li.status, l.lease_number, COALESCE(l.accounting_mode,'legacy') AS mode, b.name building_name, u.unit_number, COALESCE(pdc.cheque_number, lc.cheque_number, '') AS cheque_number\n        FROM re_lease_installments li\n        JOIN re_leases l ON l.id = li.lease_id AND l.company_id = li.company_id\n        JOIN re_units u ON u.id = l.unit_id\n        JOIN re_buildings b ON b.id = u.building_id\n        LEFT JOIN re_post_dated_cheques pdc ON pdc.installment_id = li.id AND pdc.lease_id = li.lease_id\n        LEFT JOIN re_lease_cheques lc ON lc.installment_id = li.id AND lc.lease_id = li.lease_id\n        WHERE l.tenant_id = ? AND li.company_id = ? AND COALESCE(l.accounting_mode,'legacy') = 'legacy' AND li.installment_date BETWEEN ? AND ?\n    ");
    $legacy->execute([$tenantId, $currentCompanyId, $dateFrom, $dateTo]);
    foreach ($legacy->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $activityRows[] = [
            'date' => $row['installment_date'], 'mode' => 'legacy', 'source' => 'Legacy Installment', 'ref' => $row['cheque_number'] ?: ('Installment #' . $row['id']),
            'lease' => $row['lease_number'], 'unit' => $row['building_name'] . ' - ' . $row['unit_number'],
            'description' => 'Operational rent/payment schedule row (' . $row['status'] . ')', 'debit' => (float)$row['amount'], 'credit' => 0.0,
            'link' => '../lease_view.php?id=' . (int)$tenantId,
        ];
    }

    $pay = $conn->prepare("\n        SELECT p.id, p.payment_date AS txn_date, p.amount, p.receipt_number, p.reference_number, p.payment_method,\n               l.lease_number, COALESCE(l.accounting_mode,'legacy') AS mode, b.name building_name, u.unit_number\n        FROM re_payments p\n        JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id\n        JOIN re_units u ON u.id = l.unit_id\n        JOIN re_buildings b ON b.id = u.building_id\n        WHERE l.tenant_id = ? AND p.company_id = ? AND p.payment_date BETWEEN ? AND ?\n    ");
    $pay->execute([$tenantId, $currentCompanyId, $dateFrom, $dateTo]);
    foreach ($pay->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $activityRows[] = [
            'date' => $row['txn_date'], 'mode' => $row['mode'], 'source' => ($row['mode'] === 'invoice' ? 'Receipt' : 'Legacy Payment'), 'ref' => $row['receipt_number'] ?: ($row['reference_number'] ?: ('Payment #' . $row['id'])),
            'lease' => $row['lease_number'], 'unit' => $row['building_name'] . ' - ' . $row['unit_number'],
            'description' => 'Payment by ' . str_replace('_', ' ', (string)$row['payment_method']), 'debit' => 0.0, 'credit' => (float)$row['amount'],
            'link' => '../payment_view.php?id=' . (int)$row['id'],
        ];
    }
    usort($activityRows, static fn($a, $b) => strcmp($a['date'], $b['date']));
    foreach ($activityRows as $row) { $activityDebit += $row['debit']; $activityCredit += $row['credit']; }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Account Ledger';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print"><div class="page-header-label"><i class="bi bi-journal-bookmark"></i> Account Ledger</div><?php if ($tenantId): ?><button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button><?php endif; ?></div>
<div class="alert alert-info">Combined tenant view: GL sub-ledger entries are shown where available; Legacy activity is also shown from historical installments and payments.</div>
<div class="card card-round mb-4 no-print"><div class="card-body"><form method="get" class="row g-3"><div class="col-md-3"><label class="form-label">Search Tenant</label><input type="text" name="tenant_search" class="form-control" value="<?= h($tenantSearch) ?>" placeholder="Name, phone, email"></div><div class="col-md-4"><label class="form-label">Tenant</label><select name="tenant_id" class="form-select" onchange="this.form.submit()"><option value="">-- Select Tenant --</option><?php foreach ($tenants as $tenant): ?><?php $name=$tenant['tenant_type']==='company'?$tenant['company_name']:trim($tenant['first_name'].' '.$tenant['last_name']); ?><option value="<?= (int)$tenant['id'] ?>" <?= $tenantId===(int)$tenant['id']?'selected':'' ?>><?= h($name) ?><?= $tenant['ledger_id'] ? ' (Ledger: '.number_format((float)$tenant['current_balance'],2).' AED)' : ' (No GL ledger)' ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div><div class="col-md-2"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div><div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">View</button></div></form></div></div>
<?php if ($tenantInfo): ?><?php $tenantName=$tenantInfo['tenant_type']==='company'?$tenantInfo['company_name']:trim($tenantInfo['first_name'].' '.$tenantInfo['last_name']); ?>
<div class="row g-3 mb-4"><div class="col-md-4"><div class="card"><div class="card-body"><strong><?= h($tenantName) ?></strong><br><span class="text-muted"><?= h($tenantInfo['phone'] ?: '-') ?> · <?= h($tenantInfo['email'] ?: '-') ?></span></div></div></div><div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Opening</div><strong><?= number_format($openingBalance,2) ?></strong></div></div></div><div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Closing GL</div><strong><?= number_format($closingBalance,2) ?></strong></div></div></div><div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Activity Debit</div><strong><?= number_format($activityDebit,2) ?></strong></div></div></div><div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Activity Credit</div><strong><?= number_format($activityCredit,2) ?></strong></div></div></div></div>
<div class="card card-round mb-4"><div class="card-header">GL Sub-Ledger Entries <?= !$ledgerId ? '<span class="badge bg-warning text-dark">No GL sub-ledger found</span>' : '' ?></div><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Journal</th><th>Type</th><th>Description</th><th>Reference</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead><tbody><?php if(!$ledgerEntries): ?><tr><td colspan="8" class="text-center text-muted">No GL sub-ledger entries for this period.</td></tr><?php else: ?><tr class="table-secondary"><td colspan="7" class="text-end">Opening Balance</td><td class="text-end"><?= number_format($openingBalance,2) ?></td></tr><?php foreach($ledgerEntries as $entry): ?><tr><td><?= h($entry['entry_date']) ?></td><td><a href="journal_entry_view.php?id=<?= (int)$entry['journal_id'] ?>"><?= h($entry['journal_number']) ?></a></td><td><?= h($entry['journal_type']) ?></td><td><?= h($entry['description']) ?></td><td><?= h($entry['reference'] ?: '-') ?></td><td class="text-end"><?= $entry['debit_amount']>0?number_format($entry['debit_amount'],2):'-' ?></td><td class="text-end"><?= $entry['credit_amount']>0?number_format($entry['credit_amount'],2):'-' ?></td><td class="text-end"><?= number_format($entry['balance'],2) ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div>
<div class="card card-round"><div class="card-header">Tenant Activity Details (Legacy + Invoice Mode)</div><div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Date</th><th>Mode</th><th>Source</th><th>Ref</th><th>Lease</th><th>Unit</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead><tbody><?php foreach($activityRows as $row): ?><tr><td><?= h($row['date']) ?></td><td><span class="badge bg-<?= $row['mode']==='invoice'?'primary':'secondary' ?>"><?= h($row['mode']) ?></span></td><td><?= h($row['source']) ?></td><td><?= h($row['ref']) ?></td><td><?= h($row['lease']) ?></td><td><?= h($row['unit']) ?></td><td><?= h($row['description']) ?></td><td class="text-end"><?= $row['debit']>0?number_format($row['debit'],2):'-' ?></td><td class="text-end"><?= $row['credit']>0?number_format($row['credit'],2):'-' ?></td></tr><?php endforeach; ?><?php if(!$activityRows): ?><tr><td colspan="9" class="text-center text-muted">No tenant activity found for this period.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
