<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/erp_expense_line_calc.php';
require_once __DIR__ . '/../../includes/erp_expense_posting.php';
require_once __DIR__ . '/../../includes/erp_expense_number.php';
require_once __DIR__ . '/../../includes/erp_expense_ui.php';
require_once __DIR__ . '/includes/qpe_ap_duplicate_helper.php';
require_once __DIR__ . '/../construction/includes/qpe_supplier_duplicate_helper.php';

require_login();
if (defined('ERP_EXPENSE_PAGE_LAYOUT') && constant('ERP_EXPENSE_PAGE_LAYOUT') === 'construction') {
    $sourceModule = 'construction';
} elseif (defined('ERP_EXPENSE_PAGE_LAYOUT') && constant('ERP_EXPENSE_PAGE_LAYOUT') === 'ars') {
    $sourceModule = 'ars';
} else {
    $sourceModule = $_GET['source'] ?? 'realestate';
    if (!in_array($sourceModule, ['realestate', 'construction', 'ars'], true)) {
        $sourceModule = 'realestate';
    }
}
if ($sourceModule === 'realestate') {
    if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
        require_module_access($conn, MODULE_REALESTATE);
    }
} elseif ($sourceModule === 'construction') {
    if (!has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn)) {
        require_module_access($conn, MODULE_CONSTRUCTION);
    }
} else {
    if (!has_department_access(MODULE_ARS, DEPT_ARS_CORE, $conn) && !has_department_access(MODULE_ARS, DEPT_ARS_OPERATIONS, $conn)) {
        require_module_access($conn, MODULE_ARS);
    }
}

$brand = getBrandSettings($conn);
// GL accounts and bank mappings come from re_chart_of_accounts / re_bank_accounts for this company_id (same books for RE / Construction / ARS modules).
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}
$userId = current_user_id();
$hasExpenseProjectColumn = false;
try {
    $colStmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_expense_headers' AND COLUMN_NAME = 'project_id'");
    $colStmt->execute();
    $hasExpenseProjectColumn = ((int)$colStmt->fetchColumn() > 0);
} catch (Throwable $e) {
    $hasExpenseProjectColumn = false;
}
$hasBuildingCol = erp_expense_lines_has_building_column($conn);

$expensesListUrl = 'expenses.php';
if ($sourceModule === 'construction') {
    $expensesListUrl = '../construction/expenses.php';
} elseif ($sourceModule === 'ars') {
    $expensesListUrl = '../ars/expenses.php';
}

// Full company COA for dropdown; protected accounts rejected on save/post.
$expAccts = $conn->prepare("
    SELECT id, account_code, account_name, account_type
    FROM re_chart_of_accounts
    WHERE company_id = ? AND is_header = 0 AND is_active = 1
    ORDER BY account_type, account_code
");
$expAccts->execute([$currentCompanyId]);
$expAccts = $expAccts->fetchAll(PDO::FETCH_ASSOC);

$buildings = [];
if ($sourceModule === 'realestate' && $hasBuildingCol) {
    try {
        $bst = $conn->prepare('SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name');
        $bst->execute([$currentCompanyId]);
        $buildings = $bst->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $buildings = [];
    }
}

$bankAccounts = [];
try {
    $stmt = $conn->prepare("
        SELECT ba.id, ba.account_name, coa.id AS gl_account_id, coa.account_code, coa.account_name AS gl_name
        FROM re_bank_accounts ba
        JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
        WHERE ba.company_id = ? AND ba.is_active = 1
        ORDER BY coa.account_code
    ");
    $stmt->execute([$currentCompanyId]);
    $bankAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $bankAccounts = [];
}

$cashRow = null;
$stmt = $conn->prepare("SELECT id, account_code, account_name FROM re_chart_of_accounts WHERE company_id = ? AND account_code = '1110' AND is_active = 1 LIMIT 1");
$stmt->execute([$currentCompanyId]);
$cashRow = $stmt->fetch(PDO::FETCH_ASSOC);

$defaultPayId = null;
if (!empty($bankAccounts[0]['gl_account_id'])) {
    $defaultPayId = (int)$bankAccounts[0]['gl_account_id'];
} elseif ($cashRow) {
    $defaultPayId = (int)$cashRow['id'];
}

$vendors = [];
if ($sourceModule === 'construction') {
    try {
        $vstmt = $conn->prepare("SELECT id, supplier_name AS vendor_name FROM co_suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name");
        $vstmt->execute([$currentCompanyId]);
        $vendors = $vstmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $vendors = [];
    }
} else {
    $vstmt = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? AND status = 'active' ORDER BY vendor_name");
    $vstmt->execute([$currentCompanyId]);
    $vendors = $vstmt->fetchAll(PDO::FETCH_ASSOC);
}

$constructionProjects = [];
if ($sourceModule === 'construction') {
    try {
        $pst = $conn->prepare("SELECT id, project_name, project_code FROM co_projects WHERE company_id = ? ORDER BY project_name");
        $pst->execute([$currentCompanyId]);
        $constructionProjects = $pst->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $constructionProjects = [];
    }
}

$err = '';
$duplicateOverlaps = [];
$showDuplicateConfirm = false;
if (!function_exists('h')) { function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $sm = $_POST['source_module'] ?? 'realestate';
        if (!in_array($sm, ['realestate', 'construction', 'ars'], true)) {
            throw new RuntimeException('Invalid source');
        }
        $sourceModule = $sm;
        $expensesListUrl = ($sourceModule === 'construction') ? '../construction/expenses.php' : (($sourceModule === 'ars') ? '../ars/expenses.php' : 'expenses.php');
        if ($sourceModule === 'realestate') {
            require_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);
        } elseif ($sourceModule === 'construction') {
            require_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn);
        } else {
            if (!has_department_access(MODULE_ARS, DEPT_ARS_CORE, $conn) && !has_department_access(MODULE_ARS, DEPT_ARS_OPERATIONS, $conn)) {
                require_module_access($conn, MODULE_ARS);
            }
        }
        $save_action = $_POST['save_action'] ?? 'post';
        if (!in_array($save_action, ['draft', 'post'], true)) {
            $save_action = 'post';
        }
        $expense_date = $_POST['expense_date'] ?: date('Y-m-d');
        $vendor_id = 0;
        $co_supplier_id = 0;
        if ($sourceModule === 'construction') {
            $co_supplier_id = (int)($_POST['co_supplier_id'] ?? 0);
        } else {
            $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        }
        $reference_no = trim($_POST['reference_no'] ?? '');
        $paid_via = $_POST['paid_via'] ?? 'bank';
        $allowedPaid = in_array($sourceModule, ['realestate', 'construction'], true)
            ? ['cash', 'bank', 'credit']
            : ['cash', 'bank', 'credit', 'accounts_payable'];
        if (!in_array($paid_via, $allowedPaid, true)) {
            $paid_via = 'bank';
        }
        if (in_array($sourceModule, ['realestate', 'construction'], true) && $paid_via === 'accounts_payable') {
            throw new RuntimeException(
                $sourceModule === 'construction'
                    ? 'Quick Paid Expenses must be paid immediately via cash, bank, or credit card. Use Supplier Invoices for unpaid AP.'
                    : 'Quick Paid Expenses must be paid immediately via bank or cash. Use Vendor Bills for unpaid AP.'
            );
        }
        $pay_account_id = ($paid_via === 'accounts_payable') ? 0 : (int)($_POST['pay_account_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $vat_mode = ($_POST['vat_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
        $project_id = 0;
        if ($sourceModule === 'construction' && $hasExpenseProjectColumn) {
            $project_id = (int)($_POST['project_id'] ?? 0);
        }

        $accIds = $_POST['line_account_id'] ?? [];
        $descs = $_POST['line_desc'] ?? [];
        $qtys = $_POST['line_qty'] ?? [];
        $costs = $_POST['line_cost'] ?? [];
        $vats = $_POST['line_vat_rate'] ?? [];
        $bldgIds = $_POST['line_building_id'] ?? [];

        $lines = [];
        $subtotal = 0;
        $vatSum = 0;
        $totalSum = 0;
        $n = count($accIds);
        for ($i = 0; $i < $n; $i++) {
            $aid = (int)($accIds[$i] ?? 0);
            if ($aid <= 0) {
                continue;
            }
            $acctCheck = erp_expense_validate_line_account($conn, $currentCompanyId, $aid);
            if (empty($acctCheck['ok'])) {
                throw new RuntimeException($acctCheck['error'] ?? 'Invalid expense account on a line.');
            }
            $d = trim((string)($descs[$i] ?? ''));
            $q = (float)($qtys[$i] ?? 0);
            $c = (float)($costs[$i] ?? 0);
            $vr = (float)($vats[$i] ?? 0);
            if ($q <= 0 || $c < 0) {
                continue;
            }
            $buildingId = 0;
            if ($sourceModule === 'realestate' && $hasBuildingCol) {
                $buildingId = (int)($bldgIds[$i] ?? 0);
                $bCheck = erp_expense_validate_building($conn, $currentCompanyId, $buildingId);
                if (empty($bCheck['ok'])) {
                    throw new RuntimeException($bCheck['error'] ?? 'Invalid building on a line.');
                }
                $buildingId = (int)($bCheck['building_id'] ?? 0);
            }
            $am = erp_expense_compute_line_amounts($q, $c, $vr, $vat_mode);
            $lines[] = [
                'account_id' => $aid,
                'building_id' => $buildingId > 0 ? $buildingId : null,
                'description' => $d,
                'qty' => $q,
                'unit_cost' => $c,
                'vat_rate' => $vr,
                'line_subtotal' => $am['line_subtotal'],
                'line_vat' => $am['line_vat'],
                'line_total' => $am['line_total'],
            ];
            $subtotal += $am['line_subtotal'];
            $vatSum += $am['line_vat'];
            $totalSum += $am['line_total'];
        }

        if (!$lines) {
            throw new RuntimeException('Add at least one expense line with a valid account.');
        }
        if ($paid_via !== 'accounts_payable' && $pay_account_id <= 0) {
            throw new RuntimeException('Choose a payment / settlement account.');
        }

        // Prevention: same counterparty + amount + date window already on formal AP
        if ($sourceModule === 'realestate' && $vendor_id > 0) {
            $duplicateOverlaps = qpe_ap_find_overlaps_for_new_expense(
                $conn,
                $currentCompanyId,
                $vendor_id,
                round($totalSum, 2),
                $expense_date,
                3
            );
            $confirmedDup = !empty($_POST['confirm_possible_duplicate']);
            if ($duplicateOverlaps && !$confirmedDup) {
                $showDuplicateConfirm = true;
                $parts = [];
                foreach ($duplicateOverlaps as $ov) {
                    $parts[] = ($ov['type'] === 'bill' ? 'Bill' : 'Payment')
                        . ' ' . $ov['label']
                        . ' (' . $ov['date'] . ', ' . number_format((float)$ov['amount'], 2) . ' AED)';
                }
                throw new RuntimeException(
                    'Possible duplicate of an existing Vendor Bill/Payment: '
                    . implode('; ', $parts)
                    . '. If this is a different purchase, tick the confirmation checkbox below and save again. '
                    . 'Otherwise use Vendor Bills / Payments Made, or clean duplicates via Accounting → QPE/AP Duplicates.'
                );
            }
            if ($duplicateOverlaps && $confirmedDup) {
                $showDuplicateConfirm = true;
            }
        } elseif ($sourceModule === 'construction' && $co_supplier_id > 0) {
            $duplicateOverlaps = qpe_co_find_overlaps_for_new_expense(
                $conn,
                $currentCompanyId,
                $co_supplier_id,
                round($totalSum, 2),
                $expense_date,
                3
            );
            $confirmedDup = !empty($_POST['confirm_possible_duplicate']);
            if ($duplicateOverlaps && !$confirmedDup) {
                $showDuplicateConfirm = true;
                $parts = [];
                foreach ($duplicateOverlaps as $ov) {
                    $parts[] = ($ov['type'] === 'bill' ? 'Invoice' : 'Payment')
                        . ' ' . $ov['label']
                        . ' (' . $ov['date'] . ', ' . number_format((float)$ov['amount'], 2) . ' AED)';
                }
                throw new RuntimeException(
                    'Possible duplicate of an existing Supplier Invoice/Payment: '
                    . implode('; ', $parts)
                    . '. If this is a different purchase, tick the confirmation checkbox below and save again. '
                    . 'Otherwise use Supplier Invoices / Payments, or clean duplicates via Financial → QPE/Supplier Duplicates.'
                );
            }
            if ($duplicateOverlaps && $confirmedDup) {
                $showDuplicateConfirm = true;
            }
        }

        $conn->beginTransaction();
        $expense_number = erp_allocate_expense_number($conn, $currentCompanyId);
        if ($sourceModule === 'construction' && $hasExpenseProjectColumn) {
            $insH = $conn->prepare("
                INSERT INTO erp_expense_headers
                (company_id, source_module, expense_number, expense_date, project_id, vendor_id, co_supplier_id, reference_no, paid_via, pay_account_id, currency, subtotal, vat_amount, total, vat_mode, notes, status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)
            ");
            $insH->execute([
                $currentCompanyId,
                $sourceModule,
                $expense_number,
                $expense_date,
                $project_id > 0 ? $project_id : null,
                null,
                $co_supplier_id > 0 ? $co_supplier_id : null,
                $reference_no ?: null,
                $paid_via,
                $paid_via === 'accounts_payable' ? null : $pay_account_id,
                'AED',
                round($subtotal, 2),
                round($vatSum, 2),
                round($totalSum, 2),
                $vat_mode,
                $notes ?: null,
                $userId,
            ]);
        } else {
            $insH = $conn->prepare("
                INSERT INTO erp_expense_headers
                (company_id, source_module, expense_number, expense_date, vendor_id, co_supplier_id, reference_no, paid_via, pay_account_id, currency, subtotal, vat_amount, total, vat_mode, notes, status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)
            ");
            $insH->execute([
                $currentCompanyId,
                $sourceModule,
                $expense_number,
                $expense_date,
                $sourceModule === 'construction' ? null : ($vendor_id ?: null),
                $sourceModule === 'construction' && $co_supplier_id > 0 ? $co_supplier_id : null,
                $reference_no ?: null,
                $paid_via,
                $paid_via === 'accounts_payable' ? null : $pay_account_id,
                'AED',
                round($subtotal, 2),
                round($vatSum, 2),
                round($totalSum, 2),
                $vat_mode,
                $notes ?: null,
                $userId,
            ]);
        }
        $expId = (int)$conn->lastInsertId();

        if ($hasBuildingCol) {
            $insL = $conn->prepare("
                INSERT INTO erp_expense_lines
                (expense_id, line_no, account_id, building_id, description, qty, unit_cost, vat_rate, line_subtotal, line_vat, line_total)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
        } else {
            $insL = $conn->prepare("
                INSERT INTO erp_expense_lines
                (expense_id, line_no, account_id, description, qty, unit_cost, vat_rate, line_subtotal, line_vat, line_total)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
        }
        $ln = 1;
        foreach ($lines as $r) {
            if ($hasBuildingCol) {
                $insL->execute([
                    $expId,
                    $ln++,
                    $r['account_id'],
                    $r['building_id'] ?? null,
                    $r['description'] ?: null,
                    $r['qty'],
                    $r['unit_cost'],
                    $r['vat_rate'],
                    $r['line_subtotal'],
                    $r['line_vat'],
                    $r['line_total'],
                ]);
            } else {
                $insL->execute([
                    $expId,
                    $ln++,
                    $r['account_id'],
                    $r['description'] ?: null,
                    $r['qty'],
                    $r['unit_cost'],
                    $r['vat_rate'],
                    $r['line_subtotal'],
                    $r['line_vat'],
                    $r['line_total'],
                ]);
            }
        }
        $conn->commit();

        if ($save_action === 'draft') {
            header('Location: ' . $expensesListUrl . '?saved=draft');
            exit;
        }

        $post = erp_post_expense($conn, $expId, $userId);
        if (!$post['success']) {
            throw new RuntimeException($post['error'] ?? 'GL post failed. Expense saved as draft — open it from the list to fix and post.');
        }

        header('Location: ' . $expensesListUrl . '?ok=1');
        exit;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $err = $e->getMessage();
    }
}

$pageTitle = in_array($sourceModule, ['realestate', 'construction'], true) ? 'Add Quick Paid Expense' : 'Add Expense';
$erpExpenseLayout = erp_expense_resolve_layout($sourceModule);
$erpChartOfAccountsUrl = erp_expense_chart_of_accounts_href($erpExpenseLayout);
erp_expense_require_header($erpExpenseLayout);
require_once __DIR__ . '/../../includes/searchable_select.php';
searchable_select_assets();
?>
<?php if (($sourceModule ?? 'realestate') === 'realestate'): ?>
<div class="alert alert-warning mx-3 mt-3">
    <strong>Quick Paid Expenses</strong> — for small expenses paid immediately from bank or cash (Dr expense / Dr Input VAT / Cr bank).
    For unpaid purchases, credit purchases, and formal AP, use <a href="accounting/vendor_bills.php" class="alert-link">Vendor Bills</a>.
</div>
<?php elseif ($sourceModule === 'construction'): ?>
<div class="alert alert-warning mx-3 mt-3">
    <strong>Quick Paid Expenses</strong> — paid immediately (Cash / Bank / Credit Card). Dr Expense / Dr Input VAT (2130) / Cr settlement.
    Does <strong>not</strong> create Supplier Invoices or change Outstanding AP. Unpaid bills → <a href="../construction/supplier_invoice_add.php" class="alert-link">Supplier Invoices</a>.
    Project is optional (blank = company overhead).
</div>
<?php endif; ?>

        <div class="d-flex align-items-center mb-4">
            <a href="<?= h($expensesListUrl) ?>" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left"></i> Back</a>
            <h4 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i><?= in_array($sourceModule, ['realestate', 'construction'], true) ? 'Add Quick Paid Expense' : 'Add Expense (ERP)' ?></h4>
        </div>
        <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
        <?php if (empty($expAccts)): ?>
        <div class="alert alert-warning">No accounts in chart of accounts. Add accounts under <a href="<?= h($erpChartOfAccountsUrl) ?>">Chart of Accounts</a>.</div>
        <?php endif; ?>

        <form method="post" id="expForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="source_module" value="<?= h($sourceModule) ?>">
            <div class="card card-round mb-3"><div class="card-body">
                <h6 class="text-primary">Details</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date *</label>
                        <input type="date" name="expense_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label"><?= $sourceModule === 'construction' ? 'Supplier (Construction)' : 'Supplier' ?></label>
                        <?php if ($sourceModule === 'construction'): ?>
                        <select name="co_supplier_id" class="form-select" data-search>
                            <option value="0">— none —</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>"><?= h($v['vendor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <select name="vendor_id" class="form-select" data-search>
                            <option value="0">— none —</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>"><?= h($v['vendor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Reference</label>
                        <input type="text" name="reference_no" class="form-control" placeholder="Bill / receipt #">
                    </div>
                    <?php if ($sourceModule === 'construction' && $hasExpenseProjectColumn): ?>
                    <div class="col-md-4">
                        <label class="form-label">Project <span class="text-muted fw-normal">(optional)</span></label>
                        <select name="project_id" class="form-select" data-search>
                            <option value="0">— Overhead (no project) —</option>
                            <?php foreach ($constructionProjects as $p): ?>
                            <option value="<?= (int)$p['id'] ?>">
                                <?= h(trim(($p['project_code'] ? $p['project_code'] . ' — ' : '') . $p['project_name'])) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label">Payment method *</label>
                        <select name="paid_via" id="paid_via" class="form-select">
                            <option value="cash">Cash</option>
                            <option value="bank" selected>Bank</option>
                            <option value="credit">Credit Card</option>
                            <?php if (!in_array($sourceModule, ['realestate', 'construction'], true)): ?>
                            <option value="accounts_payable">Accounts payable</option>
                            <?php endif; ?>
                        </select>
                        <?php if ($sourceModule === 'realestate'): ?>
                        <div class="form-text">Must settle immediately. Unpaid purchases → Vendor Bills.</div>
                        <?php elseif ($sourceModule === 'construction'): ?>
                        <div class="form-text">Must settle immediately (no Supplier AP). Unpaid bills → Supplier Invoices. Project optional (overhead if blank).</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4" id="payWrap">
                        <label class="form-label" id="payLabel">Payment / settlement account *</label>
                        <select name="pay_account_id" class="form-select" id="pay_account_id" data-search>
                            <?php
                            $payAccounts = [];
                            foreach ($bankAccounts as $b) {
                                $gid = (int)$b['gl_account_id'];
                                if ($gid > 0 && !isset($payAccounts[$gid])) {
                                    $payAccounts[$gid] = [
                                        'id' => $gid,
                                        'label' => trim((string)$b['account_code'] . ' — ' . (string)$b['gl_name']),
                                    ];
                                }
                            }
                            try {
                                $payStmt = $conn->prepare("
                                    SELECT c.id, c.account_code, c.account_name
                                    FROM re_chart_of_accounts c
                                    LEFT JOIN re_chart_of_accounts p ON p.id = c.parent_id
                                    WHERE c.company_id = ?
                                      AND c.is_active = 1
                                      AND c.is_header = 0
                                      AND (
                                        c.account_code LIKE '11%%'
                                        OR LOWER(c.account_name) LIKE '%%cash%%'
                                        OR LOWER(c.account_name) LIKE '%%bank%%'
                                        OR p.account_code IN ('1100', '1200')
                                      )
                                    ORDER BY c.account_code
                                ");
                                $payStmt->execute([$currentCompanyId]);
                                foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                                    $gid = (int)($row['id'] ?? 0);
                                    if ($gid > 0 && !isset($payAccounts[$gid])) {
                                        $payAccounts[$gid] = [
                                            'id' => $gid,
                                            'label' => trim((string)($row['account_code'] ?? '') . ' — ' . (string)($row['account_name'] ?? '')),
                                        ];
                                    }
                                }
                            } catch (Throwable $e) {
                                // Keep bank-mapped accounts only if broad query fails.
                            }
                            foreach ($payAccounts as $acct):
                            ?>
                            <option value="<?= (int)$acct['id'] ?>" <?= $defaultPayId === (int)$acct['id'] ? 'selected' : '' ?>>
                                <?= h($acct['label']) ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if ($cashRow): ?>
                            <option value="<?= (int)$cashRow['id'] ?>" <?= $defaultPayId === (int)$cashRow['id'] ? 'selected' : '' ?>>
                                <?= h($cashRow['account_code'] . ' — ' . $cashRow['account_name']) ?>
                            </option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional"></textarea>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">VAT on lines</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="vat_mode" id="vm_exc" value="exclusive" checked>
                            <label class="form-check-label" for="vm_exc">Exclusive (VAT on top)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="vat_mode" id="vm_inc" value="inclusive">
                            <label class="form-check-label" for="vm_inc">Inclusive (VAT in amount)</label>
                        </div>
                    </div>
                </div>
            </div></div>

            <div class="card card-round mb-3"><div class="card-header bg-primary text-white"><i class="bi bi-list-ul"></i> Lines</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table mb-0" id="lineTable">
                    <thead><tr>
                        <th>Account</th>
                        <th>Description</th>
                        <?php if ($sourceModule === 'realestate' && $hasBuildingCol): ?><th style="min-width:160px">Building</th><?php endif; ?>
                        <th style="width:90px">Qty</th>
                        <th style="width:110px">Unit</th>
                        <th style="width:80px">VAT %</th>
                        <th class="text-end">Line total</th>
                        <th></th>
                    </tr></thead>
                    <tbody id="lineBody"></tbody>
                </table>
            </div>
            <div class="card-body border-top d-flex justify-content-between flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" id="addLine">+ Add line</button>
                <div>Subtotal <strong id="tSub">0.00</strong> &nbsp; VAT <strong id="tVat">0.00</strong> &nbsp; Total <strong id="tTot">0.00</strong> AED</div>
            </div>
            <?php if ($sourceModule === 'realestate'): ?>
            <div class="card-body border-top py-2"><small class="text-muted">Account list shows the full chart; bank/cash/AR/AP/VAT/income/equity accounts are blocked on save. Prefer expense (or prepaid asset) accounts.</small></div>
            <?php endif; ?>
            </div>

            <?php if (in_array($sourceModule, ['realestate', 'construction'], true) && ($showDuplicateConfirm || $duplicateOverlaps)): ?>
            <div class="alert alert-warning">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="confirm_possible_duplicate" value="1" id="confirmPossibleDuplicate" <?= !empty($_POST['confirm_possible_duplicate']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="confirmPossibleDuplicate">
                        I confirm this Quick Paid Expense is <strong>not</strong> the same purchase as the
                        <?= $sourceModule === 'construction' ? 'Supplier Invoice/Payment' : 'Vendor Bill/Payment' ?>
                        listed above (two different purchases can share supplier, amount, and date).
                    </label>
                </div>
                <?php if ($duplicateOverlaps): ?>
                    <ul class="small mb-0 mt-2">
                        <?php foreach ($duplicateOverlaps as $ov): ?>
                            <li>
                                <?php if ($sourceModule === 'construction'): ?>
                                    <?= h($ov['type'] === 'bill' ? 'Supplier Invoice' : 'Supplier Payment') ?>:
                                    <?php if ($ov['type'] === 'bill'): ?>
                                        <a href="supplier_invoice_view.php?id=<?= (int)$ov['id'] ?>"><?= h($ov['label']) ?></a>
                                    <?php else: ?>
                                        <a href="supplier_payment_view.php?id=<?= (int)$ov['id'] ?>"><?= h($ov['label']) ?></a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= h($ov['type'] === 'bill' ? 'Vendor Bill' : 'Vendor Payment') ?>:
                                    <?php if ($ov['type'] === 'bill'): ?>
                                        <a href="accounting/vendor_bill_view.php?id=<?= (int)$ov['id'] ?>"><?= h($ov['label']) ?></a>
                                    <?php else: ?>
                                        <a href="accounting/vendor_payments.php?q=<?= urlencode((string)$ov['id']) ?>"><?= h($ov['label']) ?></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                — <?= h($ov['date']) ?> · <?= number_format((float)$ov['amount'], 2) ?> AED
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end gap-2">
                <a href="<?= h($expensesListUrl) ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" name="save_action" value="draft" class="btn btn-outline-primary">Save draft</button>
                <button type="submit" name="save_action" value="post" class="btn btn-success">Save &amp; Post</button>
            </div>
        </form>

        <template id="lineTpl">
            <tr class="line-row">
                <td>
                    <select name="line_account_id[]" class="form-select form-select-sm line-acc" data-search required>
                        <option value="">— Account —</option>
                        <?php
                        $lastType = null;
                        foreach ($expAccts as $a):
                            $t = (string)($a['account_type'] ?? '');
                            if ($t !== $lastType):
                                if ($lastType !== null) {
                                    echo '</optgroup>';
                                }
                                echo '<optgroup label="' . h($t !== '' ? $t : 'Other') . '">';
                                $lastType = $t;
                            endif;
                        ?>
                        <option value="<?= (int)$a['id'] ?>"><?= h($a['account_code'] . ' — ' . $a['account_name']) ?></option>
                        <?php endforeach; if ($lastType !== null) echo '</optgroup>'; ?>
                    </select>
                </td>
                <td><input type="text" name="line_desc[]" class="form-control form-control-sm line-desc" placeholder="Description"></td>
                <?php if ($sourceModule === 'realestate' && $hasBuildingCol): ?>
                <td>
                    <select name="line_building_id[]" class="form-select form-select-sm line-bldg" data-search>
                        <option value="0">— Unassigned —</option>
                        <?php foreach ($buildings as $b): ?>
                        <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <?php endif; ?>
                <td><input type="number" step="0.001" name="line_qty[]" class="form-control form-control-sm line-qty" value="1"></td>
                <td><input type="number" step="0.01" name="line_cost[]" class="form-control form-control-sm line-cost" value="0"></td>
                <td><input type="number" step="0.01" name="line_vat_rate[]" class="form-control form-control-sm line-vr" value="5"></td>
                <td class="text-end align-middle"><span class="line-lt">0.00</span></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger line-del">&times;</button></td>
            </tr>
        </template>

        <script>
        (function(){
          const body = document.getElementById('lineBody');
          const tpl = document.getElementById('lineTpl');
          function inclusive(){ return document.getElementById('vm_inc').checked; }
          function lineAmounts(q,c,vr,inc){
            const g = Math.round(q*c*100)/100;
            if (inc) {
              if (vr<=0) return {ls:g,lv:0,lt:g};
              const lv = Math.round(g*vr/(100+vr)*100)/100;
              const ls = Math.round((g-lv)*100)/100;
              return {ls,lv,lt:g};
            }
            const ls = g;
            const lv = Math.round(ls*vr/100*100)/100;
            return {ls,lv,lt:Math.round((ls+lv)*100)/100};
          }
          function recalc(){
            let sub=0,vat=0,tot=0;
            body.querySelectorAll('.line-row').forEach(tr=>{
              const q=parseFloat(tr.querySelector('.line-qty').value)||0;
              const c=parseFloat(tr.querySelector('.line-cost').value)||0;
              const vr=parseFloat(tr.querySelector('.line-vr').value)||0;
              const {ls,lv,lt}=lineAmounts(q,c,vr,inclusive());
              sub+=ls; vat+=lv; tot+=lt;
              tr.querySelector('.line-lt').textContent = lt.toFixed(2);
            });
            document.getElementById('tSub').textContent = sub.toFixed(2);
            document.getElementById('tVat').textContent = vat.toFixed(2);
            document.getElementById('tTot').textContent = tot.toFixed(2);
          }
          function addLine(){
            const node = tpl.content.cloneNode(true);
            body.appendChild(node);
            const tr = body.lastElementChild;
            tr.querySelector('.line-del').addEventListener('click',()=>{ tr.remove(); recalc(); });
            tr.querySelectorAll('input').forEach(i=>i.addEventListener('input',recalc));
            tr.querySelector('.line-acc').addEventListener('change',recalc);
            recalc();
          }
          document.getElementById('addLine').addEventListener('click',addLine);
          document.querySelectorAll('input[name="vat_mode"]').forEach(r=>r.addEventListener('change',recalc));
          document.getElementById('paid_via').addEventListener('change',e=>{
            const ap = e.target.value==='accounts_payable';
            document.getElementById('payWrap').style.display = ap ? 'none' : '';
            const pl = document.getElementById('payLabel');
            if (pl) pl.textContent = ap ? 'N/A (posted to AP)' : 'Payment / settlement account *';
          });
          addLine();
          document.getElementById('paid_via').dispatchEvent(new Event('change'));
        })();
        </script>
<?php erp_expense_require_footer($erpExpenseLayout);
