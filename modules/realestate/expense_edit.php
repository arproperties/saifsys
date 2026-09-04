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
require_once __DIR__ . '/../../includes/erp_expense_ui.php';

require_login();

$brand = getBrandSettings($conn);
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

$id = (int)($_GET['id'] ?? $_POST['expense_id'] ?? 0);
if ($id <= 0) {
    header('Location: expenses.php');
    exit;
}

$H = $conn->prepare("SELECT * FROM erp_expense_headers WHERE id = ? AND company_id = ? ");
$H->execute([$id, $currentCompanyId]);
$h = $H->fetch(PDO::FETCH_ASSOC);
if (!$h) {
    header('Location: expenses.php');
    exit;
}
if (($h['status'] ?? '') === 'cancelled') {
    header('Location: ' . (($h['source_module'] ?? '') === 'construction' ? '../construction/expenses.php' : (($h['source_module'] ?? '') === 'ars' ? '../ars/expenses.php' : 'expenses.php')));
    exit;
}
$sourceModule = $h['source_module'] ?? 'realestate';
$expensesListUrl = ($sourceModule === 'construction') ? '../construction/expenses.php' : (($sourceModule === 'ars') ? '../ars/expenses.php' : 'expenses.php');
$historicalReadOnly = erp_expense_is_legacy_archive($h);
if (!$historicalReadOnly && defined('ERP_EXPENSE_HISTORICAL_READONLY') && ERP_EXPENSE_HISTORICAL_READONLY && $sourceModule === 'construction'
    && !erp_expense_headers_has_legacy_archive_column($conn)) {
    // Fallback only when migration not applied yet.
    $historicalReadOnly = true;
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

$vendors = [];
if (($h['source_module'] ?? '') === 'construction') {
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
if (($h['source_module'] ?? '') === 'construction' && $hasExpenseProjectColumn) {
    try {
        $pst = $conn->prepare("SELECT id, project_name, project_code FROM co_projects WHERE company_id = ? ORDER BY project_name");
        $pst->execute([$currentCompanyId]);
        $constructionProjects = $pst->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $constructionProjects = [];
    }
}

$L = $conn->prepare("SELECT * FROM erp_expense_lines WHERE expense_id = ? ORDER BY line_no");
$L->execute([$id]);
$loadedLines = $L->fetchAll(PDO::FETCH_ASSOC);

$err = '';
$msg = '';

if (!$historicalReadOnly && isset($_GET['void']) && $_GET['void'] === '1') {
    try {
        csrf_verify();
        $v = erp_cancel_expense($conn, $id, $userId);
        if (!$v['success']) {
            throw new RuntimeException($v['error'] ?? 'Cancel failed');
        }
        header('Location: ' . $expensesListUrl . '?ok=cancelled');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

if (!$historicalReadOnly && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        csrf_verify();
        $headerStatus = $h['status'] ?? 'draft';
        $expense_date = $_POST['expense_date'] ?: date('Y-m-d');
        $vendor_id = 0;
        $co_supplier_id = 0;
        if (($h['source_module'] ?? '') === 'construction') {
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
        $save_action = $_POST['save_action'] ?? 'draft';
        $project_id = 0;
        if (($h['source_module'] ?? '') === 'construction' && $hasExpenseProjectColumn) {
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

        $conn->beginTransaction();
        $updSql = "
            UPDATE erp_expense_headers SET
            expense_date = ?, vendor_id = ?, co_supplier_id = ?, reference_no = ?, paid_via = ?, pay_account_id = ?,
            subtotal = ?, vat_amount = ?, total = ?, vat_mode = ?, notes = ?
        ";
        if (($h['source_module'] ?? '') === 'construction' && $hasExpenseProjectColumn) {
            $updSql .= ", project_id = ?";
        }
        $updSql .= " WHERE id = ? AND company_id = ? ";
        $upd = $conn->prepare($updSql);
        if (($h['source_module'] ?? '') === 'construction') {
            $venOut = null;
            $coSupOut = $co_supplier_id > 0 ? $co_supplier_id : null;
        } else {
            $venOut = $vendor_id ?: null;
            $coSupOut = null;
        }
        $updArgs = [
            $expense_date,
            $venOut,
            $coSupOut,
            $reference_no ?: null,
            $paid_via,
            $paid_via === 'accounts_payable' ? null : $pay_account_id,
            round($subtotal, 2),
            round($vatSum, 2),
            round($totalSum, 2),
            $vat_mode,
            $notes ?: null,
        ];
        if (($h['source_module'] ?? '') === 'construction' && $hasExpenseProjectColumn) {
            $updArgs[] = $project_id > 0 ? $project_id : null;
        }
        $updArgs[] = $id;
        $updArgs[] = $currentCompanyId;
        $upd->execute($updArgs);

        $conn->prepare("DELETE FROM erp_expense_lines WHERE expense_id = ?")->execute([$id]);
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
                    $id,
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
                    $id,
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

        if ($headerStatus === 'draft') {
            if ($save_action === 'post') {
                $post = erp_post_expense($conn, $id, $userId);
                if (!$post['success']) {
                    throw new RuntimeException($post['error'] ?? 'GL post failed');
                }
                header('Location: ' . $expensesListUrl . '?ok=1');
                exit;
            }
            header('Location: ' . $expensesListUrl . '?saved=draft');
            exit;
        }

        $post = erp_repost_expense($conn, $id, $userId);
        if (!$post['success']) {
            throw new RuntimeException($post['error'] ?? 'GL repost failed');
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

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$pvSel = $h['paid_via'] ?? 'bank';
if ($pvSel === 'ap') {
    $pvSel = 'accounts_payable';
}
if ($sourceModule === 'realestate' && $pvSel === 'accounts_payable') {
    // Quick Paid Expenses no longer use AP; show bank until user picks cash/credit.
    $pvSel = 'bank';
}
if ($sourceModule === 'construction' && $pvSel === 'accounts_payable' && !$historicalReadOnly) {
    $pvSel = 'bank';
}
$expNum = trim((string)($h['expense_number'] ?? ''));
$st = $h['status'] ?? 'draft';

$pageTitle = $historicalReadOnly
    ? 'Historical ERP Expense'
    : (($sourceModule === 'realestate' || $sourceModule === 'construction') ? 'Edit Quick Paid Expense' : 'Edit Expense');
$erpExpenseLayout = erp_expense_resolve_layout($h['source_module'] ?? 'realestate');
erp_expense_require_header($erpExpenseLayout);
?>
        <?php if ($sourceModule === 'realestate'): ?>
        <div class="alert alert-warning mx-3 mt-3">
            <strong>Quick Paid Expenses</strong> — for small expenses paid immediately from bank or cash (Dr expense / Dr Input VAT / Cr bank).
            For unpaid purchases, credit purchases, and formal AP, use <a href="accounting/vendor_bills.php" class="alert-link">Vendor Bills</a>.
        </div>
        <?php elseif ($sourceModule === 'construction' && !$historicalReadOnly): ?>
        <div class="alert alert-warning mx-3 mt-3">
            <strong>Quick Paid Expenses</strong> — paid immediately (Cash / Bank / Credit Card). Dr Expense / Dr Input VAT (2130) / Cr settlement.
            Does <strong>not</strong> create Supplier Invoices or change Outstanding AP. Unpaid bills → <a href="../construction/supplier_invoice_add.php" class="alert-link">Supplier Invoices</a>.
        </div>
        <?php endif; ?>
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="d-flex align-items-center">
                <a href="<?= h($expensesListUrl) ?>" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left"></i> Back</a>
                <div>
                <h4 class="mb-0"><i class="bi <?= $historicalReadOnly ? 'bi-archive' : 'bi-pencil' ?> me-2"></i><?= $historicalReadOnly ? 'Historical ERP Expense ' : (($sourceModule === 'realestate' || $sourceModule === 'construction') ? 'Edit Quick Paid Expense ' : 'Edit ') ?><?= $expNum !== '' ? h($expNum) : ('#' . (int)$id) ?></h4>
                <small class="text-muted">ID <?= (int)$id ?> · <span class="badge bg-<?= $st === 'posted' ? 'success' : ($st === 'draft' ? 'warning text-dark' : 'secondary') ?>"><?= h($st) ?></span><?php if ($historicalReadOnly): ?> · <span class="badge bg-secondary">Legacy archive</span><?php endif; ?></small>
                </div>
            </div>
            <?php if (!$historicalReadOnly): ?>
            <form method="post" action="expense_edit.php?id=<?= (int)$id ?>&void=1" onsubmit="return confirm('Cancel this expense? Posted amounts will be reversed in the ledger.');">
                <?php csrf_field(); ?>
                <button type="submit" class="btn btn-outline-danger">Cancel expense</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if ($historicalReadOnly): ?>
            <div class="alert alert-info">
                This Construction ERP expense is a <strong>legacy historical archive</strong> and is read-only forever. Journals are not rewritten.
                New immediate payments → <a href="../construction/expense_add.php" class="alert-link">Quick Paid Expenses</a>. Unpaid AP → Supplier Invoices.
            </div>
        <?php endif; ?>
        <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

        <form method="post" id="expForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="expense_id" value="<?= (int)$id ?>">

            <div class="card card-round mb-3"><div class="card-body">
                <h6 class="text-primary">Details</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date *</label>
                        <input type="date" name="expense_date" class="form-control" value="<?= h($h['expense_date']) ?>" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label"><?= ($h['source_module'] ?? '') === 'construction' ? 'Supplier (Construction)' : 'Supplier' ?></label>
                        <?php if (($h['source_module'] ?? '') === 'construction'): ?>
                        <select name="co_supplier_id" class="form-select">
                            <option value="0">— none —</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= (int)($h['co_supplier_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <select name="vendor_id" class="form-select">
                            <option value="0">— none —</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= (int)($h['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Reference</label>
                        <input type="text" name="reference_no" class="form-control" value="<?= h($h['reference_no'] ?? '') ?>">
                    </div>
                    <?php if (($h['source_module'] ?? '') === 'construction' && $hasExpenseProjectColumn): ?>
                    <div class="col-md-4">
                        <label class="form-label">Project <span class="text-muted fw-normal">(optional)</span></label>
                        <select name="project_id" class="form-select" <?= $historicalReadOnly ? 'disabled' : '' ?>>
                            <option value="0">— Overhead (no project) —</option>
                            <?php foreach ($constructionProjects as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)($h['project_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= h(trim(($p['project_code'] ? $p['project_code'] . ' — ' : '') . $p['project_name'])) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label">Payment method *</label>
                        <select name="paid_via" id="paid_via" class="form-select" <?= $historicalReadOnly ? 'disabled' : '' ?>>
                            <option value="cash" <?= $pvSel === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="bank" <?= $pvSel === 'bank' ? 'selected' : '' ?>>Bank</option>
                            <option value="credit" <?= $pvSel === 'credit' ? 'selected' : '' ?>>Credit Card</option>
                            <?php if ($historicalReadOnly && $pvSel === 'accounts_payable'): ?>
                            <option value="accounts_payable" selected>Accounts payable (legacy)</option>
                            <?php elseif (!in_array($sourceModule, ['realestate', 'construction'], true)): ?>
                            <option value="accounts_payable" <?= $pvSel === 'accounts_payable' ? 'selected' : '' ?>>Accounts payable</option>
                            <?php endif; ?>
                        </select>
                        <?php if ($sourceModule === 'realestate'): ?>
                        <div class="form-text">Must settle immediately. Unpaid purchases → Vendor Bills.</div>
                        <?php elseif ($sourceModule === 'construction' && !$historicalReadOnly): ?>
                        <div class="form-text">Cash / Bank / Credit Card only. Never creates Supplier AP.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4" id="payWrap">
                        <label class="form-label" id="payLabel">Payment / settlement account *</label>
                        <select name="pay_account_id" class="form-select" id="pay_account_id">
                            <?php
                            $payId = (int)($h['pay_account_id'] ?? 0);
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
                                    SELECT id, account_code, account_name
                                    FROM re_chart_of_accounts
                                    WHERE company_id = ?
                                      AND is_active = 1
                                      AND is_header = 0
                                      AND (
                                        account_code LIKE '11%%'
                                        OR LOWER(account_name) LIKE '%%cash%%'
                                        OR LOWER(account_name) LIKE '%%bank%%'
                                      )
                                    ORDER BY account_code
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
                            <option value="<?= (int)$acct['id'] ?>" <?= $payId === (int)$acct['id'] ? 'selected' : '' ?>><?= h($acct['label']) ?></option>
                            <?php endforeach; ?>
                            <?php if ($cashRow): ?>
                            <option value="<?= (int)$cashRow['id'] ?>" <?= $payId === (int)$cashRow['id'] ? 'selected' : '' ?>><?= h($cashRow['account_code'] . ' — ' . $cashRow['account_name']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?= h($h['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">VAT on lines</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="vat_mode" id="vm_exc" value="exclusive" <?= ($h['vat_mode'] ?? '') === 'exclusive' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="vm_exc">Exclusive</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="vat_mode" id="vm_inc" value="inclusive" <?= ($h['vat_mode'] ?? '') === 'inclusive' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="vm_inc">Inclusive</label>
                        </div>
                    </div>
                </div>
            </div></div>

            <div class="card card-round mb-3"><div class="card-header bg-primary text-white"><i class="bi bi-list-ul"></i> Lines</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table mb-0" id="lineTable"><thead><tr>
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
                <?php if (!$historicalReadOnly): ?><button type="button" class="btn btn-outline-primary btn-sm" id="addLine">+ Add line</button><?php else: ?><span class="text-muted small">Historical lines</span><?php endif; ?>
                <div>Subtotal <strong id="tSub">0.00</strong> &nbsp; VAT <strong id="tVat">0.00</strong> &nbsp; Total <strong id="tTot">0.00</strong> AED</div>
            </div>
            <?php if ($sourceModule === 'realestate'): ?>
            <div class="card-body border-top py-2"><small class="text-muted">Account list shows the full chart; bank/cash/AR/AP/VAT/income/equity accounts are blocked on save. Prefer expense (or prepaid asset) accounts.</small></div>
            <?php endif; ?>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="<?= h($expensesListUrl) ?>" class="btn btn-outline-secondary">Cancel</a>
                <?php if (!$historicalReadOnly && ($h['status'] ?? '') === 'draft'): ?>
                <button type="submit" name="save_action" value="draft" class="btn btn-outline-primary">Save draft</button>
                <button type="submit" name="save_action" value="post" class="btn btn-success">Post to ledger</button>
                <?php elseif (!$historicalReadOnly): ?>
                <button type="submit" name="save_action" value="repost" class="btn btn-success">Save &amp; Repost</button>
                <?php endif; ?>
            </div>
        </form>

        <template id="lineTpl">
            <tr class="line-row">
                <td>
                    <select name="line_account_id[]" class="form-select form-select-sm line-acc" required>
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
                    <select name="line_building_id[]" class="form-select form-select-sm line-bldg">
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
        window.__loadedLines = <?= json_encode($loadedLines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        (function(){
          const readOnly = <?= $historicalReadOnly ? 'true' : 'false' ?>;
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
          function addLine(data){
            const node = tpl.content.cloneNode(true);
            body.appendChild(node);
            const tr = body.lastElementChild;
            if (data) {
              tr.querySelector('.line-acc').value = String(data.account_id);
              tr.querySelector('.line-desc').value = data.description || '';
              tr.querySelector('.line-qty').value = data.qty;
              tr.querySelector('.line-cost').value = data.unit_cost;
              tr.querySelector('.line-vr').value = data.vat_rate;
              const bldg = tr.querySelector('.line-bldg');
              if (bldg) {
                bldg.value = String(data.building_id || 0);
              }
            }
            tr.querySelector('.line-del').addEventListener('click',()=>{ tr.remove(); recalc(); });
            tr.querySelectorAll('input').forEach(i=>i.addEventListener('input',recalc));
            tr.querySelector('.line-acc').addEventListener('change',recalc);
            recalc();
          }
          const addLineButton = document.getElementById('addLine');
          if (addLineButton) addLineButton.addEventListener('click',()=>addLine(null));
          document.querySelectorAll('input[name="vat_mode"]').forEach(r=>r.addEventListener('change',recalc));
          document.getElementById('paid_via').addEventListener('change',e=>{
            const ap = e.target.value==='accounts_payable';
            document.getElementById('payWrap').style.display = ap ? 'none' : '';
            const pl = document.getElementById('payLabel');
            if (pl) pl.textContent = ap ? 'N/A (posted to AP)' : 'Payment / settlement account *';
          });
          const ld = window.__loadedLines || [];
          if (ld.length) { ld.forEach(d=>addLine(d)); }
          else { addLine(null); }
          document.getElementById('paid_via').dispatchEvent(new Event('change'));
          if (readOnly) {
            document.querySelectorAll('#expForm input, #expForm select, #expForm textarea, #expForm button').forEach(el => {
              if (el.closest('.d-flex.justify-content-end')) return;
              el.disabled = true;
            });
          }
        })();
        </script>
<?php erp_expense_require_footer($erpExpenseLayout);
