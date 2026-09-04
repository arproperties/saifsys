<?php
/**
 * Construction Shop Rental — Payment Workspace (Phase 5A).
 * General Receive Payment + cheque allocate entry. Auto-loads outstanding invoices.
 * Does not call RE receipt allocation engine. Accounting posting unchanged.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_once __DIR__ . '/includes/construction_receipt_allocation_service.php';
require_once __DIR__ . '/includes/construction_receipt_view_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_shop_require_company_id($conn);
$userId = current_user_id() ? (int)current_user_id() : null;

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$contractId = (int)($_GET['contract_id'] ?? $_POST['contract_id'] ?? 0);
$clientIdParam = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$msg = '';
$err = '';
$preview = null;
$schemaReady = co_receipt_workspace_schema_ready($conn);
$fundingMethodsReady = co_receipt_funding_methods_ready($conn);
$fundingMethods = co_receipt_funding_methods();
if (!$fundingMethodsReady) {
    // Until Phase 5A migration is applied, only show methods the ENUM already supports
    $fundingMethods = [
        'bank_transfer' => 'Bank transfer',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
    ];
}
$prefillNotice = '';
$amountHint = '';
$prefillChequeRows = [];

// Deep-link cheque ids
$prefillChequeIds = [];
if (!$isPost) {
    if (!empty($_GET['cheque_id'])) {
        $prefillChequeIds[] = (int)$_GET['cheque_id'];
    }
    if (!empty($_GET['cheque_ids'])) {
        $raw = $_GET['cheque_ids'];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                $prefillChequeIds[] = (int)$v;
            }
        } else {
            foreach (preg_split('/[,\s]+/', (string)$raw) as $v) {
                if ($v !== '') {
                    $prefillChequeIds[] = (int)$v;
                }
            }
        }
    }
    $prefillChequeIds = array_values(array_unique(array_filter($prefillChequeIds)));
}

// Known payment amount from bank transfer / external ref (GET amount=)
$knownAmount = null;
if (!$isPost && isset($_GET['amount']) && is_numeric($_GET['amount'])) {
    $knownAmount = round((float)$_GET['amount'], 2);
    if ($knownAmount <= 0) {
        $knownAmount = null;
    }
}

$contract = null;
$clientContracts = [];
$clientName = '';
if ($contractId > 0) {
    $contract = co_shop_contract_load($conn, $cid, $contractId);
    if (!$contract) {
        $err = 'Contract not found for this company.';
        $contractId = 0;
    } else {
        $clientIdParam = (int)$contract['client_id'];
    }
} elseif ($clientIdParam > 0) {
    $cst = $conn->prepare("SELECT id, client_name FROM co_clients WHERE id = ? AND company_id = ?");
    $cst->execute([$clientIdParam, $cid]);
    $clientRow = $cst->fetch(PDO::FETCH_ASSOC);
    if (!$clientRow) {
        $err = 'Client not found for this company.';
        $clientIdParam = 0;
    } else {
        $clientName = (string)$clientRow['client_name'];
        $lc = $conn->prepare("
            SELECT id, contract_number, status, rent_amount, start_date, end_date
            FROM co_shop_rental_contracts
            WHERE company_id = ? AND client_id = ? AND status IN ('active','draft','expired')
            ORDER BY FIELD(status,'active','draft','expired'), id DESC
        ");
        $lc->execute([$cid, $clientIdParam]);
        $clientContracts = $lc->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($clientContracts) === 1) {
            $contractId = (int)$clientContracts[0]['id'];
            $contract = co_shop_contract_load($conn, $cid, $contractId);
        }
    }
}

if ($contract && $clientName === '') {
    $cn = $conn->prepare("SELECT client_name FROM co_clients WHERE id = ? AND company_id = ?");
    $cn->execute([(int)$contract['client_id'], $cid]);
    $clientName = (string)($cn->fetchColumn() ?: '');
}

$strategyDefault = co_receipt_company_strategy($conn, $cid);
$paymentAccounts = co_fetch_payment_accounts($conn, $cid);

$amountFromPost = $isPost && isset($_POST['amount']);
$amount = $amountFromPost ? (float)$_POST['amount'] : 0.0;
$payAccountId = (int)($_POST['pay_account_id'] ?? 0);
$paymentDate = (string)($_POST['payment_date'] ?? ($_GET['payment_date'] ?? date('Y-m-d')));
$reference = trim((string)($_POST['reference'] ?? ($_GET['reference'] ?? '')));
$strategy = (string)($_POST['strategy'] ?? $strategyDefault);
if (!in_array($strategy, co_receipt_allocation_strategies(), true)) {
    $strategy = $strategyDefault;
}
$manualAlloc = [];
foreach ((array)($_POST['alloc_amount'] ?? []) as $invId => $amt) {
    $manualAlloc[(int)$invId] = (float)$amt;
}

$fundingRows = [];
$methods = (array)($_POST['funding_method'] ?? []);
$famts = (array)($_POST['funding_amount'] ?? []);
$frefs = (array)($_POST['funding_reference'] ?? []);
$fdates = (array)($_POST['funding_date'] ?? []);
$fcheques = (array)($_POST['funding_cheque_id'] ?? []);
if ($methods) {
    foreach ($methods as $i => $m) {
        $fundingRows[] = [
            'method' => (string)$m,
            'amount' => (float)($famts[$i] ?? 0),
            'reference' => (string)($frefs[$i] ?? ''),
            'funding_date' => (string)($fdates[$i] ?? $paymentDate),
            'cheque_id' => (int)($fcheques[$i] ?? 0) ?: null,
            'bank_account_id' => $payAccountId ?: null,
        ];
    }
}

// Prefill from selected cheques (GET only)
$prefillChequeRows = [];
if ($contract && $prefillChequeIds && !$methods) {
    try {
        $chequeRows = co_shop_cheques_for_allocate($conn, $cid, $contractId, $prefillChequeIds);
        $prefillChequeRows = $chequeRows;
        $fundingRows = [];
        $sum = 0.0;
        $refs = [];
        foreach ($chequeRows as $ch) {
            $amt = round((float)$ch['amount'], 2);
            $sum = round($sum + $amt, 2);
            $num = trim((string)($ch['cheque_number'] ?? ''));
            if ($num !== '') {
                $refs[] = $num;
            }
            $fundingRows[] = [
                'method' => 'cheque',
                'amount' => $amt,
                'reference' => $num !== '' ? $num : ('Cheque #' . (int)$ch['id']),
                'funding_date' => $ch['cheque_date'] ?? $paymentDate,
                'cheque_id' => (int)$ch['id'],
                'bank_account_id' => null,
            ];
        }
        if (!$amountFromPost) {
            $amount = $sum;
            $amountHint = count($chequeRows) === 1
                ? 'Prefill from cheque face amount — edit for a partial bank clear if needed.'
                : 'Prefill from sum of selected cheques — editable for partials.';
        }
        if ($reference === '' && $refs) {
            $reference = implode(', ', $refs);
        }
        $prefillNotice = count($chequeRows) === 1
            ? 'Receive Payment prefilled from cheque #' . (int)$chequeRows[0]['id'] . '.'
            : 'Receive Payment prefilled from ' . count($chequeRows) . ' cheques (combined).';
    } catch (Throwable $e) {
        $err = $e->getMessage();
        $prefillChequeIds = [];
        $prefillChequeRows = [];
    }
}

// Known amount from bank transfer / external reference (when not cheque-driven)
if ($contract && !$amountFromPost && $amount <= 0 && $knownAmount !== null) {
    $amount = $knownAmount;
    $amountHint = 'Prefill from payment reference amount — edit for partial payment.';
    if ($prefillNotice === '') {
        $prefillNotice = 'Amount taken from payment reference.';
    }
}

if ($isPost && $contract) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$schemaReady) {
            throw new RuntimeException('Run migrations/construction_shop_rental_phase_payment_workspace.sql before using Payment Workspace.');
        }
        $input = [
            'company_id' => $cid,
            'client_id' => (int)$contract['client_id'],
            'contract_id' => $contractId,
            'amount' => $amount,
            'pay_account_id' => $payAccountId,
            'payment_date' => $paymentDate,
            'reference' => $reference !== '' ? $reference : null,
            'strategy' => $strategy,
            'funding' => $fundingRows,
            'manual_allocations' => $manualAlloc,
            'cheque_ids' => array_values(array_filter(array_map('intval', $fcheques))),
        ];

        if ($action === 'preview') {
            $preview = co_receipt_preview($conn, $input);
            if (!$preview['ok']) {
                $err = implode(' ', $preview['errors']);
            } else {
                $msg = 'Preview ready — review totals, then Confirm Payment.';
            }
        } elseif ($action === 'confirm') {
            $preview = co_receipt_preview($conn, $input);
            if (!$preview['ok']) {
                throw new RuntimeException(implode(' ', $preview['errors']));
            }
            $conn->beginTransaction();
            $result = co_receipt_confirm($conn, $input, $userId);
            $conn->commit();
            header('Location: client_payment_receipt.php?id=' . (int)$result['payment_id']);
            exit;
        } elseif ($action === 'apply_credit') {
            $invoiceId = (int)($_POST['credit_invoice_id'] ?? 0);
            $creditAmt = (float)($_POST['credit_amount'] ?? 0);
            $conn->beginTransaction();
            $result = co_receipt_apply_credit($conn, $cid, (int)$contract['client_id'], $invoiceId, $creditAmt, $userId, $contractId);
            $conn->commit();
            $msg = 'Applied credit AED ' . number_format((float)$result['applied'], 2) . ' to invoice.';
        } elseif ($action === 'reverse_payment') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $reason = trim((string)($_POST['void_reason'] ?? 'Workspace reverse'));
            $conn->beginTransaction();
            co_receipt_reverse($conn, $cid, $paymentId, $reason, $userId);
            $conn->commit();
            $msg = 'Payment #' . $paymentId . ' reversed. Invoices reopened; overpayment credit unwound.';
        } elseif ($action === 'void_payment') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $reason = trim((string)($_POST['void_reason'] ?? 'Workspace void'));
            $conn->beginTransaction();
            co_receipt_void($conn, $cid, $paymentId, $reason, $userId);
            $conn->commit();
            $msg = 'Payment #' . $paymentId . ' voided.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $err = $e->getMessage();
        try {
            $preview = co_receipt_preview($conn, $input ?? []);
        } catch (Throwable $ignored) {
            $preview = null;
        }
    }
}

$openInvoices = [];
$availableCredit = 0.0;
$recentPayments = [];
$allocatableCheques = [];
$outstandingTotal = 0.0;
if ($contract) {
    $openInvoices = co_receipt_enrich_invoice_priorities(
        $conn,
        $cid,
        co_receipt_open_invoices_for_contract($conn, $cid, $contractId)
    );
    $outstandingTotal = round(array_sum(array_map(static fn($r) => (float)$r['balance'], $openInvoices)), 2);
    $availableCredit = co_shop_get_client_credit($conn, $cid, (int)$contract['client_id']);

    // Contract-direct receive: default amount to outstanding (always editable)
    if (!$amountFromPost && $amount <= 0 && $outstandingTotal > 0.005) {
        $amount = $outstandingTotal;
        $amountHint = 'Defaulted to total outstanding — edit the amount for a partial payment.';
    }

    if (!$fundingRows && $amount > 0) {
        $defaultMethod = 'bank_transfer';
        if (!$isPost && !empty($_GET['method']) && isset($fundingMethods[$_GET['method']])) {
            $defaultMethod = (string)$_GET['method'];
        }
        $fundingRows = [[
            'method' => $defaultMethod,
            'amount' => $amount,
            'reference' => $reference,
            'funding_date' => $paymentDate,
            'cheque_id' => null,
        ]];
    }

    // Allocatable cheques for in-workspace picker
    $chq = $conn->prepare("
        SELECT id, cheque_number, cheque_date, amount, status, notes
        FROM co_shop_rent_cheques
        WHERE company_id = ? AND contract_id = ?
          AND cheque_type <> 'security_deposit'
          AND status IN ('received','deposited','cleared')
          AND (payment_id IS NULL OR payment_id = 0)
        ORDER BY cheque_date ASC, id ASC
    ");
    $chq->execute([$cid, $contractId]);
    $allocatableCheques = $chq->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($preview === null && $amount > 0 && $payAccountId > 0) {
        try {
            $preview = co_receipt_preview($conn, [
                'company_id' => $cid,
                'client_id' => (int)$contract['client_id'],
                'contract_id' => $contractId,
                'amount' => $amount,
                'pay_account_id' => $payAccountId,
                'payment_date' => $paymentDate,
                'reference' => $reference !== '' ? $reference : null,
                'strategy' => $strategy,
                'funding' => $fundingRows,
                'manual_allocations' => $manualAlloc,
                'cheque_ids' => array_values(array_filter(array_map(
                    static fn($r) => (int)($r['cheque_id'] ?? 0),
                    $fundingRows
                ))),
            ]);
        } catch (Throwable $e) {
            // wait for receiving account
        }
    }
    if (co_db_column_exists($conn, 'co_client_payments', 'contract_id')) {
        $rpCols = 'id, payment_date, amount, allocation_status, reference, journal_id, credit_amount, invoice_id';
        if (co_db_column_exists($conn, 'co_client_payments', 'receipt_purpose')) {
            $rpCols .= ', receipt_purpose';
        }
        if (co_db_column_exists($conn, 'co_client_payments', 'prepaid_vat_amount')) {
            $rpCols .= ', prepaid_vat_amount';
        }
        $rp = $conn->prepare("
            SELECT {$rpCols}
            FROM co_client_payments
            WHERE company_id = ? AND contract_id = ?
              AND (allocation_status IS NULL OR allocation_status NOT IN ('voided','reversed'))
            ORDER BY id DESC LIMIT 10
        ");
        $rp->execute([$cid, $contractId]);
        $recentPayments = $rp->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$preferredKinds = $preview['preferred_kinds'] ?? null;
if ($preferredKinds === null && $contract) {
    $purposeChequeIds = [];
    foreach ($fundingRows as $fr) {
        if (!empty($fr['cheque_id'])) {
            $purposeChequeIds[] = (int)$fr['cheque_id'];
        }
    }
    if ($purposeChequeIds) {
        $purposeRows = $prefillChequeRows;
        if (!$purposeRows) {
            $purposeRows = co_receipt_load_cheques_for_purpose($conn, $cid, $contractId, $purposeChequeIds);
        }
        $preferredKinds = co_receipt_preferred_kinds_from_cheques($purposeRows);
    }
}

$allocLines = $preview['allocation_lines'] ?? [];
if (!$allocLines && $openInvoices) {
    $allocLines = co_receipt_build_allocation_plan(
        $openInvoices,
        $amount > 0 ? $amount : 0,
        $strategy,
        $manualAlloc,
        $preferredKinds
    );
}
$preferredKindsLabel = co_receipt_preferred_kinds_label($preferredKinds);
$isPrepaidVatCheque = is_array($preferredKinds) && $preferredKinds === [];
$prepaidVatBalance = ($contract && function_exists('co_shop_contract_prepaid_vat_balance'))
    ? co_shop_contract_prepaid_vat_balance($conn, $cid, $contractId)
    : 0.0;

$fundingMethodOptionsHtml = '';
foreach ($fundingMethods as $mk => $ml) {
    $fundingMethodOptionsHtml .= '<option value="' . htmlspecialchars($mk, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($ml, ENT_QUOTES, 'UTF-8') . '</option>';
}

$pageTitle = 'Receive Payment';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <?php if ($contractId): ?>
            <a href="shop_rental_contract_view.php?id=<?= (int)$contractId ?>&tab=finance" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Contract</a>
        <?php else: ?>
            <a href="shop_rental_contracts.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Contracts</a>
        <?php endif; ?>
        <h1 class="h4 mb-0">Receive Payment</h1>
        <p class="text-muted small mb-0">
            Payment Workspace — auto-loads outstanding invoices. Funding may be transfer, cash, card, online, and/or cheque(s).
            One receiving GL debit per payment.
        </p>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if ($prefillNotice !== '' && !$err): ?><div class="alert alert-info"><?= h($prefillNotice) ?></div><?php endif; ?>
<?php if (!$schemaReady): ?>
    <div class="alert alert-warning">Run <code>migrations/construction_shop_rental_phase_payment_workspace.sql</code> before confirming payments.</div>
<?php elseif (!$fundingMethodsReady): ?>
    <div class="alert alert-info py-2 small mb-3">Run <code>migrations/construction_shop_rental_phase_workspace_funding_methods.sql</code> to enable Card/POS and Online funding methods.</div>
<?php endif; ?>

<?php if (!$contract && $clientIdParam > 0 && count($clientContracts) > 1): ?>
    <div class="card card-round mb-3">
        <div class="card-header"><strong>Select contract for <?= h($clientName) ?></strong></div>
        <div class="card-body p-0">
            <div class="list-group list-group-flush">
                <?php foreach ($clientContracts as $cc): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between"
                       href="shop_rental_payment_workspace.php?contract_id=<?= (int)$cc['id'] ?><?= $knownAmount ? '&amount=' . h(number_format($knownAmount, 2, '.', '')) : '' ?><?= $reference !== '' ? '&reference=' . urlencode($reference) : '' ?>">
                        <span><?= h($cc['contract_number'] ?? ('#' . $cc['id'])) ?> · <?= h($cc['status']) ?></span>
                        <span class="text-muted"><?= h($cc['start_date'] ?? '') ?> → <?= h($cc['end_date'] ?? '') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php elseif (!$contract): ?>
    <div class="card card-round">
        <div class="card-body">
            <p class="mb-3">Open <strong>Receive Payment</strong> from a shop rental contract, or look up by contract / client:</p>
            <form method="get" class="row g-2 align-items-end mb-3">
                <div class="col-md-3">
                    <label class="form-label small mb-0">Contract ID</label>
                    <input type="number" name="contract_id" class="form-control form-control-sm" min="1" value="<?= $contractId ?: '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">or Client ID</label>
                    <input type="number" name="client_id" class="form-control form-control-sm" min="1" value="<?= $clientIdParam ?: '' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">Known amount</label>
                    <input type="number" step="0.01" name="amount" class="form-control form-control-sm" value="<?= $knownAmount !== null ? h(number_format($knownAmount, 2, '.', '')) : '' ?>" placeholder="optional">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">Reference</label>
                    <input type="text" name="reference" class="form-control form-control-sm" value="<?= h($reference) ?>" placeholder="optional">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary btn-sm w-100" type="submit">Open</button>
                </div>
            </form>
            <p class="small text-muted mb-0">Cheques remain an optional funding source — use Cheques tab → Allocate Payment to prefill from instruments.</p>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card card-round mb-3 border-primary">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong>Outstanding invoices</strong>
                    <span class="small">Open AR: <strong><?= co_format_money($outstandingTotal) ?></strong> · <?= count($openInvoices) ?> invoice(s)</span>
                </div>
                <div class="card-body p-0">
                    <?php if (!$openInvoices): ?>
                        <p class="p-3 text-muted mb-0">No outstanding invoices on this contract.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Type</th>
                                        <th>Due</th>
                                        <th class="text-end">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($openInvoices as $oi): ?>
                                    <tr>
                                        <td><?= h($oi['invoice_number']) ?></td>
                                        <td><span class="badge bg-light text-dark"><?= h($oi['kind_label'] ?? $oi['kind'] ?? '') ?></span></td>
                                        <td><?= h((string)($oi['due_date'] ?? '—')) ?></td>
                                        <td class="text-end"><?= co_format_money($oi['balance']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" id="pwForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="contract_id" value="<?= (int)$contractId ?>">

                <div class="card card-round mb-3">
                    <div class="card-header"><strong>Payment header</strong></div>
                    <div class="card-body row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Payment date *</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= h($paymentDate) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Amount * <span class="text-muted fw-normal">(editable)</span></label>
                            <input type="number" step="0.01" min="0.01" name="amount" id="pwAmount" class="form-control" value="<?= h(number_format($amount, 2, '.', '')) ?>" required>
                            <?php if ($amountHint !== ''): ?>
                                <div class="form-text"><?= h($amountHint) ?></div>
                            <?php else: ?>
                                <div class="form-text">Supports partial payments — change amount anytime before Confirm.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Receiving GL (cash/bank) *</label>
                            <select name="pay_account_id" class="form-select" required>
                                <option value="">— Select —</option>
                                <?php foreach ($paymentAccounts as $acc): ?>
                                    <option value="<?= (int)$acc['id'] ?>" <?= $payAccountId === (int)$acc['id'] ? 'selected' : '' ?>>
                                        <?= h(($acc['account_code'] ?? '') . ' — ' . ($acc['account_name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reference</label>
                            <input type="text" name="reference" class="form-control" value="<?= h($reference) ?>" maxlength="120" placeholder="Bank ref / transfer ID / receipt no.">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Allocation strategy</label>
                            <select name="strategy" class="form-select" id="pwStrategy">
                                <?php foreach (['fifo' => 'FIFO (invoice date)', 'oldest_due' => 'Oldest due date', 'charge_priority' => 'Priority by charge type', 'manual' => 'Manual'] as $k => $lab): ?>
                                    <option value="<?= h($k) ?>" <?= $strategy === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 small">
                            <span class="text-muted">Contract</span> <strong><?= h($contract['contract_number'] ?? ('#' . $contractId)) ?></strong>
                            · <span class="text-muted">Customer</span> <strong><?= h($clientName !== '' ? $clientName : ('#' . (int)$contract['client_id'])) ?></strong>
                            · <span class="text-muted">Credit</span> <strong><?= co_format_money($availableCredit) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card card-round mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <strong>Funding sources</strong>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="pwAddFunding">Add funding row</button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0" id="pwFundingTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Method</th>
                                        <th class="text-end">Amount</th>
                                        <th>Reference</th>
                                        <th>Date</th>
                                        <th>Cheque</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($fundingRows as $fr): ?>
                                    <tr>
                                        <td>
                                            <select name="funding_method[]" class="form-select form-select-sm pw-fund-method">
                                                <?php foreach ($fundingMethods as $mk => $ml): ?>
                                                    <option value="<?= h($mk) ?>" <?= ($fr['method'] ?? '') === $mk ? 'selected' : '' ?>><?= h($ml) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" step="0.01" name="funding_amount[]" class="form-control form-control-sm text-end pw-fund-amt" value="<?= h(number_format((float)($fr['amount'] ?? 0), 2, '.', '')) ?>"></td>
                                        <td><input type="text" name="funding_reference[]" class="form-control form-control-sm" value="<?= h((string)($fr['reference'] ?? '')) ?>"></td>
                                        <td><input type="date" name="funding_date[]" class="form-control form-control-sm" value="<?= h((string)($fr['funding_date'] ?? $paymentDate)) ?>"></td>
                                        <td>
                                            <select name="funding_cheque_id[]" class="form-select form-select-sm">
                                                <option value="">—</option>
                                                <?php foreach ($allocatableCheques as $ac): ?>
                                                    <option value="<?= (int)$ac['id'] ?>" <?= !empty($fr['cheque_id']) && (int)$fr['cheque_id'] === (int)$ac['id'] ? 'selected' : '' ?>>
                                                        #<?= (int)$ac['id'] ?> · <?= h($ac['cheque_number'] ?: $ac['cheque_date']) ?> · <?= number_format((float)$ac['amount'], 2) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-muted mt-2 mb-0">
                            Methods: bank transfer, cash, card/POS, online, cheque — mix freely.
                            Funding total must equal payment amount. GL debit uses Receiving account only (one Dr).
                        </p>
                    </div>
                </div>

                <div class="card card-round mb-3">
                    <div class="card-header"><strong>Suggested allocations</strong> <span class="small text-muted fw-normal">(override anytime)</span></div>
                    <div class="card-body p-0">
                        <?php if ($isPrepaidVatCheque): ?>
                            <div class="alert alert-info border-bottom rounded-0 mb-0 small py-2 px-3">
                                <strong>Separate VAT payment</strong> — this posts a Payment Receipt only
                                (Dr Bank / Cr VAT Collected in Advance <code>2330</code>).
                                It is <em>not</em> a Tax Invoice and is not allocated to invoices.
                                Monthly rent invoices recognize Output VAT; prepaid VAT is consumed automatically so outstanding = rent only.
                                <?php if ($prepaidVatBalance > 0.005): ?>
                                    <br>Current prepaid VAT balance on contract: <strong><?= co_format_money($prepaidVatBalance) ?></strong>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($preferredKindsLabel !== ''): ?>
                            <div class="alert alert-light border-bottom rounded-0 mb-0 small py-2 px-3">
                                Cheque purpose: suggestions limited to <strong><?= h($preferredKindsLabel) ?></strong> invoices.
                                Use Combined first cheque when rent + prepaid VAT are on one cheque.
                            </div>
                        <?php endif; ?>
                        <?php if ($isPrepaidVatCheque): ?>
                            <p class="p-3 text-muted mb-0">No invoice allocation required for Separate VAT. Confirm to post the prepaid VAT receipt.</p>
                        <?php elseif (!$allocLines): ?>
                            <p class="p-3 text-muted mb-0">No open invoices to allocate.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Invoice</th>
                                            <th>Type</th>
                                            <th>Due</th>
                                            <th class="text-end">Balance</th>
                                            <th class="text-end">Suggested</th>
                                            <th class="text-end">Allocate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($allocLines as $line): ?>
                                        <tr>
                                            <td><?= h($line['invoice_number']) ?></td>
                                            <td><span class="badge bg-light text-dark"><?= h($line['kind_label'] ?? $line['kind'] ?? '') ?></span></td>
                                            <td><?= h((string)($line['due_date'] ?? '—')) ?></td>
                                            <td class="text-end"><?= co_format_money($line['balance']) ?></td>
                                            <td class="text-end"><?= co_format_money($line['suggested'] ?? 0) ?></td>
                                            <td class="text-end" style="min-width:7rem">
                                                <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end"
                                                       name="alloc_amount[<?= (int)$line['invoice_id'] ?>]"
                                                       value="<?= h(number_format((float)$line['amount'], 2, '.', '')) ?>">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($preview): ?>
                        <div class="card-footer small">
                            <?php if (!empty($preview['is_prepaid_vat_receipt'])): ?>
                                Prepaid VAT receipt: <strong><?= co_format_money($preview['amount'] ?? 0) ?></strong>
                                · Funding: <strong><?= co_format_money($preview['funding_total'] ?? 0) ?></strong>
                            <?php else: ?>
                                Allocated: <strong><?= co_format_money($preview['allocated_total'] ?? 0) ?></strong>
                                · Prepaid VAT: <strong><?= co_format_money($preview['prepaid_vat_amount'] ?? 0) ?></strong>
                                · Credit leftover: <strong><?= co_format_money($preview['credit_amount'] ?? 0) ?></strong>
                                · Funding: <strong><?= co_format_money($preview['funding_total'] ?? 0) ?></strong>
                            <?php endif; ?>
                            <?= !empty($preview['funding_balanced']) ? '<span class="text-success">balanced</span>' : '<span class="text-danger">not balanced</span>' ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" name="action" value="preview" class="btn btn-outline-primary" <?= !$schemaReady ? 'disabled' : '' ?>>Preview</button>
                    <button type="submit" name="action" value="confirm" class="btn btn-primary" <?= !$schemaReady ? 'disabled' : '' ?>
                            onclick="return confirm('Confirm payment and post to accounting?');">Confirm Payment</button>
                </div>
            </form>

            <?php if ($availableCredit > 0.005 && $openInvoices): ?>
                <div class="card card-round mb-3">
                    <div class="card-header"><strong>Apply customer credit</strong> (no new cash)</div>
                    <div class="card-body">
                        <form method="post" class="row g-2 align-items-end">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="contract_id" value="<?= (int)$contractId ?>">
                            <input type="hidden" name="action" value="apply_credit">
                            <div class="col-md-5">
                                <label class="form-label small mb-0">Invoice</label>
                                <select name="credit_invoice_id" class="form-select form-select-sm" required>
                                    <?php foreach ($openInvoices as $oi): ?>
                                        <option value="<?= (int)$oi['id'] ?>"><?= h($oi['invoice_number']) ?> (<?= co_format_money($oi['balance']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-0">Amount</label>
                                <input type="number" step="0.01" min="0.01" max="<?= h(number_format($availableCredit, 2, '.', '')) ?>" name="credit_amount" class="form-control form-control-sm" value="<?= h(number_format(min($availableCredit, (float)$openInvoices[0]['balance']), 2, '.', '')) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Apply Credit</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card card-round mb-3">
                <div class="card-header"><strong>Contract summary</strong></div>
                <div class="card-body small">
                    <div>Status: <?= h($contract['status'] ?? '') ?></div>
                    <div>Customer: <?= h($clientName !== '' ? $clientName : ('#' . (int)$contract['client_id'])) ?></div>
                    <div>Rent: <?= co_format_money($contract['rent_amount'] ?? 0) ?></div>
                    <div>Deposit: <?= co_format_money($contract['security_deposit'] ?? 0) ?></div>
                    <div>Outstanding: <?= co_format_money($outstandingTotal) ?></div>
                    <div class="mt-2">
                        <a class="btn btn-outline-secondary btn-sm" href="shop_rental_contract_view.php?id=<?= (int)$contractId ?>&amp;tab=cheques">Cheques tab</a>
                    </div>
                </div>
            </div>

            <?php if ($allocatableCheques): ?>
                <div class="card card-round mb-3">
                    <div class="card-header"><strong>Allocatable cheques</strong></div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush small">
                            <?php foreach ($allocatableCheques as $ac): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>#<?= (int)$ac['id'] ?> · <?= h($ac['status']) ?> · <?= co_format_money($ac['amount']) ?></span>
                                    <a class="btn btn-outline-success btn-sm py-0"
                                       href="shop_rental_payment_workspace.php?contract_id=<?= (int)$contractId ?>&cheque_id=<?= (int)$ac['id'] ?>">Use</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($recentPayments): ?>
                <div class="card card-round mb-3">
                    <div class="card-header"><strong>Recent payments</strong></div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush small">
                            <?php foreach ($recentPayments as $rp): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <span>#<?= (int)$rp['id'] ?> · <?= h($rp['payment_date']) ?></span>
                                        <strong><?= co_format_money($rp['amount']) ?></strong>
                                    </div>
                                    <div class="text-muted"><?= h(co_receipt_status_display_label((string)($rp['allocation_status'] ?? ''), $rp)) ?> <?= $rp['reference'] ? '· ' . h($rp['reference']) : '' ?></div>
                                    <div class="mt-1 d-flex flex-wrap gap-1">
                                        <a href="client_payment_receipt.php?id=<?= (int)$rp['id'] ?>" class="btn btn-outline-primary btn-sm py-0">View Receipt</a>
                                        <a href="client_receipt_pdf.php?id=<?= (int)$rp['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-0">PDF</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Reverse this payment?');">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="contract_id" value="<?= (int)$contractId ?>">
                                            <input type="hidden" name="action" value="reverse_payment">
                                            <input type="hidden" name="payment_id" value="<?= (int)$rp['id'] ?>">
                                            <input type="hidden" name="void_reason" value="Reversed from Payment Workspace">
                                            <button class="btn btn-outline-warning btn-sm py-0" type="submit">Reverse</button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Void this payment?');">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="contract_id" value="<?= (int)$contractId ?>">
                                            <input type="hidden" name="action" value="void_payment">
                                            <input type="hidden" name="payment_id" value="<?= (int)$rp['id'] ?>">
                                            <input type="hidden" name="void_reason" value="Voided from Payment Workspace">
                                            <button class="btn btn-outline-danger btn-sm py-0" type="submit">Void</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function () {
        var tbody = document.querySelector('#pwFundingTable tbody');
        var addBtn = document.getElementById('pwAddFunding');
        var amt = document.getElementById('pwAmount');
        var methodOpts = <?= json_encode($fundingMethods, JSON_UNESCAPED_UNICODE) ?>;
        var payDate = <?= json_encode($paymentDate) ?>;
        function methodSelectHtml(selected) {
            var h = '';
            Object.keys(methodOpts).forEach(function (k) {
                h += '<option value="' + k + '"' + (k === selected ? ' selected' : '') + '>' + methodOpts[k] + '</option>';
            });
            return h;
        }
        if (tbody && addBtn) {
            addBtn.addEventListener('click', function () {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td><select name="funding_method[]" class="form-select form-select-sm pw-fund-method">' + methodSelectHtml('bank_transfer') + '</select></td>'
                    + '<td><input type="number" step="0.01" name="funding_amount[]" class="form-control form-control-sm text-end pw-fund-amt" value="0.00"></td>'
                    + '<td><input type="text" name="funding_reference[]" class="form-control form-control-sm"></td>'
                    + '<td><input type="date" name="funding_date[]" class="form-control form-control-sm" value="' + payDate + '"></td>'
                    + '<td><select name="funding_cheque_id[]" class="form-select form-select-sm"><option value="">—</option>'
                    + <?= json_encode(implode('', array_map(static function ($ac) {
                        return '<option value="' . (int)$ac['id'] . '">#' . (int)$ac['id'] . '</option>';
                    }, $allocatableCheques))) ?>
                    + '</select></td>';
                tbody.appendChild(tr);
            });
        }
        // When a single funding row exists, keep its amount in sync with payment amount for convenience
        if (amt && tbody) {
            amt.addEventListener('change', function () {
                var rows = tbody.querySelectorAll('.pw-fund-amt');
                if (rows.length === 1) {
                    rows[0].value = parseFloat(amt.value || '0').toFixed(2);
                }
            });
        }
    })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
