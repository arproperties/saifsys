<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_once __DIR__ . '/includes/construction_shop_rental_charge_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_shop_require_company_id($conn);
$userId = current_user_id();
$err = '';
$warn = [];
$phase1Ready = co_shop_phase1_schema_ready($conn);
$phase175Ready = co_shop_phase175_schema_ready($conn);
$shops = $conn->prepare("SELECT id, shop_number, shop_name, status FROM co_shop_units WHERE company_id = ? ORDER BY shop_number");
$shops->execute([$cid]);
$shops = $shops->fetchAll(PDO::FETCH_ASSOC);
$clients = $conn->prepare("SELECT id, client_name FROM co_clients WHERE company_id = ? ORDER BY client_name");
$clients->execute([$cid]);
$clients = $clients->fetchAll(PDO::FETCH_ASSOC);

$postedShopIds = array_map('intval', (array)($_POST['shop_unit_ids'] ?? []));
$postedPrimary = (int)($_POST['primary_shop_unit_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$phase1Ready) {
        $err = 'Run migrations/construction_shop_rental_phase1.sql before creating shop rental contracts.';
    } elseif (!$phase175Ready) {
        $err = 'Run migrations/construction_shop_rental_phase175.sql for multi-shop contracts.';
    } else {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $contractNumber = trim($_POST['contract_number'] ?? '') ?: co_next_document_number($conn, $cid, 'SHOP', 'co_shop_rental_contracts', 'contract_number');
        $startDate = $_POST['start_date'] ?? date('Y-m-d');
        $endDate = $_POST['end_date'] ?? date('Y-m-d');
        $rentAmount = (float)($_POST['rent_amount'] ?? 0);
        $frequency = $_POST['payment_frequency'] ?? 'monthly';
        $rentChequeCount = max(0, (int)($_POST['rent_cheque_count'] ?? 0));
        $depositChequeCount = max(0, (int)($_POST['deposit_cheque_count'] ?? 0));
        $accrualDeferredRent = !empty($_POST['accrual_deferred_rent']) ? 1 : 0;
        $vatRate = (float)($_POST['vat_rate'] ?? 5);
        $vatMode = co_shop_normalize_vat_mode($_POST['vat_mode'] ?? 'exclusive');
        $vatCollection = co_shop_normalize_vat_collection($_POST['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);
        $securityDeposit = (float)($_POST['security_deposit'] ?? 0);
        $paymentTerms = trim($_POST['payment_terms'] ?? '');
        $status = $_POST['status'] ?? 'active';
        if (!in_array($status, ['draft', 'active', 'expired', 'terminated'], true)) {
            $status = 'draft';
        }
        $notes = trim($_POST['notes'] ?? '');
        $commissionReady = co_shop_commission_schema_ready($conn);
        $commEnabled = !empty($_POST['commission_enabled']) ? 1 : 0;
        $commBasis = (($_POST['commission_basis'] ?? 'percent') === 'fixed') ? 'fixed' : 'percent';
        $commPercent = max(0, (float)($_POST['commission_percent'] ?? 5));
        $commFixed = max(0, (float)($_POST['commission_fixed_amount'] ?? 0));
        $commManual = !empty($_POST['commission_manual_override']) ? 1 : 0;
        $commVatEnabled = !empty($_POST['commission_vat_enabled']) ? 1 : 0;
        $commVatRate = max(0, (float)($_POST['commission_vat_rate'] ?? 5));
        $commNet = 0.0;
        if ($commissionReady && $commEnabled) {
            if ($commManual) {
                $commNet = max(0, round((float)($_POST['commission_net_amount'] ?? 0), 2));
            } elseif ($commBasis === 'fixed') {
                $commNet = round($commFixed, 2);
            } else {
                $commNet = round($rentAmount * $commPercent / 100, 2);
            }
        }
        $combinedFirst = !empty($_POST['combined_first_cheque']) ? 1 : 0;
        $hasCombinedCol = co_db_column_exists($conn, 'co_shop_rental_contracts', 'combined_first_cheque');
        if ($combinedFirst && $rentChequeCount <= 0) {
            $err = 'Combined first collection requires at least 1 rent cheque.';
        }
        try {
            if ($err) {
                throw new RuntimeException($err);
            }
            $sel = co_shop_normalize_shop_selection($postedShopIds, $postedPrimary ?: null);
            $shopIds = $sel['shop_ids'];
            $primaryId = $sel['primary_id'];
            if (!$clientId || $rentAmount <= 0) {
                throw new RuntimeException('Tenant and rent amount are required.');
            }
            if ($status === 'active') {
                co_shop_assert_no_active_overlap($conn, $cid, $shopIds, $startDate, $endDate, null);
            } else {
                $warn = co_shop_draft_overlap_warnings($conn, $cid, $shopIds, $startDate, $endDate, null);
            }
            $conn->beginTransaction();
            if ($commissionReady && $hasCombinedCol) {
                $stmt = $conn->prepare("
                    INSERT INTO co_shop_rental_contracts
                        (company_id, shop_unit_id, client_id, contract_number, start_date, end_date, rent_amount, payment_frequency, rent_cheque_count, deposit_cheque_count, combined_first_cheque, accrual_deferred_rent, vat_rate, vat_mode, vat_collection_method, security_deposit, payment_terms, status, notes,
                         commission_enabled, commission_basis, commission_percent, commission_fixed_amount, commission_net_amount, commission_manual_override, commission_vat_enabled, commission_vat_rate, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $cid, $primaryId, $clientId, $contractNumber, $startDate, $endDate, $rentAmount, $frequency,
                    $rentChequeCount, $depositChequeCount, $combinedFirst, $accrualDeferredRent, $vatRate, $vatMode, $vatCollection,
                    $securityDeposit, $paymentTerms ?: null, $status === 'active' ? 'draft' : $status, $notes ?: null,
                    $commEnabled, $commBasis, $commPercent, $commFixed, $commNet, $commManual, $commVatEnabled, $commVatRate,
                    $userId ?: null,
                ]);
            } elseif ($commissionReady) {
                $stmt = $conn->prepare("
                    INSERT INTO co_shop_rental_contracts
                        (company_id, shop_unit_id, client_id, contract_number, start_date, end_date, rent_amount, payment_frequency, rent_cheque_count, deposit_cheque_count, accrual_deferred_rent, vat_rate, vat_mode, vat_collection_method, security_deposit, payment_terms, status, notes,
                         commission_enabled, commission_basis, commission_percent, commission_fixed_amount, commission_net_amount, commission_manual_override, commission_vat_enabled, commission_vat_rate, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $cid, $primaryId, $clientId, $contractNumber, $startDate, $endDate, $rentAmount, $frequency,
                    $rentChequeCount, $depositChequeCount, $accrualDeferredRent, $vatRate, $vatMode, $vatCollection,
                    $securityDeposit, $paymentTerms ?: null, $status === 'active' ? 'draft' : $status, $notes ?: null,
                    $commEnabled, $commBasis, $commPercent, $commFixed, $commNet, $commManual, $commVatEnabled, $commVatRate,
                    $userId ?: null,
                ]);
            } elseif ($hasCombinedCol) {
                $stmt = $conn->prepare("
                    INSERT INTO co_shop_rental_contracts
                        (company_id, shop_unit_id, client_id, contract_number, start_date, end_date, rent_amount, payment_frequency, rent_cheque_count, deposit_cheque_count, combined_first_cheque, accrual_deferred_rent, vat_rate, vat_mode, vat_collection_method, security_deposit, payment_terms, status, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $cid, $primaryId, $clientId, $contractNumber, $startDate, $endDate, $rentAmount, $frequency,
                    $rentChequeCount, $depositChequeCount, $combinedFirst, $accrualDeferredRent, $vatRate, $vatMode, $vatCollection,
                    $securityDeposit, $paymentTerms ?: null, $status === 'active' ? 'draft' : $status, $notes ?: null, $userId ?: null,
                ]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO co_shop_rental_contracts
                        (company_id, shop_unit_id, client_id, contract_number, start_date, end_date, rent_amount, payment_frequency, rent_cheque_count, deposit_cheque_count, accrual_deferred_rent, vat_rate, vat_mode, vat_collection_method, security_deposit, payment_terms, status, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $cid, $primaryId, $clientId, $contractNumber, $startDate, $endDate, $rentAmount, $frequency,
                    $rentChequeCount, $depositChequeCount, $accrualDeferredRent, $vatRate, $vatMode, $vatCollection,
                    $securityDeposit, $paymentTerms ?: null, $status === 'active' ? 'draft' : $status, $notes ?: null, $userId ?: null,
                ]);
            }
            $contractId = (int)$conn->lastInsertId();
            try {
                require_once __DIR__ . '/../../includes/AuditService.php';
                AuditService::logEvent([
                    'action' => 'create',
                    'module' => 'construction',
                    'company_id' => (int)$cid,
                    'object_type' => 'co_shop_rental_contracts',
                    'object_id' => (string)$contractId,
                    'object_ref' => $contractNumber ?: ('Contract #' . $contractId),
                    'summary' => 'Created shop rental contract ' . ($contractNumber ?: ('#' . $contractId)),
                    'source' => 'user',
                    'success' => true,
                ]);
            } catch (Throwable $ignored) {}
            co_shop_sync_contract_shops($conn, $cid, $contractId, $shopIds, $primaryId);
            if (function_exists('co_shop_sync_contract_charges_from_legacy')) {
                require_once __DIR__ . '/includes/construction_shop_rental_charge_helpers.php';
                co_shop_sync_contract_charges_from_legacy($conn, $cid, $contractId, $userId ?: null);
                if (co_shop_charges_schema_ready($conn)) {
                    // Persist Key Money charge inside the contract txn; generate invoice AFTER commit
                    // (invoice posting / schema helpers must not run inside this transaction).
                    co_shop_apply_key_money_settings($conn, $cid, $contractId, $_POST, $userId ?: null, false);
                }
            }
            if (function_exists('co_shop_concession_schema_ready') && co_shop_concession_schema_ready($conn)
                && (!empty($_POST['concession_enabled']) || !empty($_POST['concession_enabled_flag']))) {
                co_shop_save_concession_settings($conn, $cid, $contractId, $_POST, $userId ?: null);
            }
            if ($status === 'active') {
                co_shop_set_contract_status($conn, $cid, $contractId, 'active');
            }
            if ($rentChequeCount > 0 || $depositChequeCount > 0) {
                co_create_shop_cheque_plan($conn, $cid, $contractId, $rentChequeCount, $depositChequeCount);
            }
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            // Key Money invoice (immediate / due on start) — after commit so GL posting is safe
            if (function_exists('co_shop_try_generate_due_key_money') && co_shop_charges_schema_ready($conn)) {
                try {
                    co_shop_try_generate_due_key_money($conn, $cid, $contractId, (int)($userId ?: 0));
                } catch (Throwable $kmEx) {
                    $_SESSION['co_shop_flash_warn'] = array_merge(
                        is_array($_SESSION['co_shop_flash_warn'] ?? null) ? $_SESSION['co_shop_flash_warn'] : [],
                        ['Contract saved, but Key Money invoice failed: ' . $kmEx->getMessage()]
                    );
                }
            }
            $q = $warn ? ('&warn=1') : '';
            if ($warn) {
                $_SESSION['co_shop_flash_warn'] = array_merge(
                    is_array($_SESSION['co_shop_flash_warn'] ?? null) ? $_SESSION['co_shop_flash_warn'] : [],
                    $warn
                );
            }
            header('Location: shop_rental_contract_view.php?id=' . $contractId . $q);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $err = $e->getMessage();
        }
    }
}
$pageTitle = 'New Shop Rental Contract';
$coUiV2 = true;
require_once __DIR__ . '/includes/construction_layout_header.php';
$vatMethodPost = co_shop_normalize_vat_collection($_POST['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED);
$vatModePost = co_shop_normalize_vat_mode($_POST['vat_mode'] ?? 'exclusive');
$commissionReady = co_shop_commission_schema_ready($conn);
$hasCombinedCol = co_db_column_exists($conn, 'co_shop_rental_contracts', 'combined_first_cheque');
if (!$postedShopIds && $shops) {
    $postedShopIds = [(int)$shops[0]['id']];
}
if (!$postedPrimary && $postedShopIds) {
    $postedPrimary = $postedShopIds[0];
}
$canSave = $phase1Ready && $phase175Ready;
$concessionWizardReady = function_exists('co_shop_concession_schema_ready') && co_shop_concession_schema_ready($conn);
$wizardStart = 1;
if ($err) {
    $wizardStart = 4;
    if (stripos($err, 'cheque') !== false || stripos($err, 'combined') !== false) {
        $wizardStart = 1;
    } elseif (stripos($err, 'rent') !== false || stripos($err, 'tenant') !== false) {
        $wizardStart = stripos($err, 'tenant') !== false ? 1 : 2;
    }
}
$shopJson = [];
foreach ($shops as $s) {
    $shopJson[] = [
        'id' => (int)$s['id'],
        'label' => $s['shop_number'] . ($s['shop_name'] ? ' — ' . $s['shop_name'] : ''),
        'status' => $s['status'] ?? '',
    ];
}
$clientJson = [];
foreach ($clients as $c) {
    $clientJson[] = ['id' => (int)$c['id'], 'name' => $c['client_name']];
}
$vatOpts = co_shop_vat_collection_options();
?>
<div class="co-page-header mb-3">
    <div>
        <nav class="small mb-1" style="color:var(--co-text-dim)">
            <a href="shop_rental_contracts.php" style="color:var(--co-text-muted)">Shop Rental Contracts</a>
            <span class="mx-1">›</span> New Contract
        </nav>
        <h1 class="h4 mb-0">New Shop Rental Contract</h1>
        <p class="text-muted small mb-0">Guided setup — one tenant, one or many shops. Money stays at contract level.</p>
    </div>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!$phase1Ready): ?><div class="alert alert-warning">Run <code>migrations/construction_shop_rental_phase1.sql</code> before using Phase 1 shop rental features.</div><?php endif; ?>
<?php if ($phase1Ready && !$phase175Ready): ?><div class="alert alert-warning">Run <code>migrations/construction_shop_rental_phase175.sql</code> for multi-shop support.</div><?php endif; ?>

<form method="post" id="shopContractForm" class="co-stepper-ready" data-start-step="<?= (int)$wizardStart ?>">
<?php csrf_field(); ?>

<div class="co-cw-top">
    <div class="co-stepper" role="tablist" aria-label="Contract steps">
        <button type="button" class="co-step<?= $wizardStart === 1 ? ' active' : '' ?>" data-cw-goto="1" role="tab">
            <span class="num">1</span><span class="label">Contract Details</span>
        </button>
        <button type="button" class="co-step<?= $wizardStart === 2 ? ' active' : '' ?>" data-cw-goto="2" role="tab">
            <span class="num">2</span><span class="label">Financial Terms</span>
        </button>
        <button type="button" class="co-step<?= $wizardStart === 3 ? ' active' : '' ?>" data-cw-goto="3" role="tab">
            <span class="num">3</span><span class="label">Commission</span>
        </button>
        <button type="button" class="co-step<?= $wizardStart === 4 ? ' active' : '' ?>" data-cw-goto="4" role="tab">
            <span class="num">4</span><span class="label">Review &amp; Save</span>
        </button>
    </div>
    <div class="co-cw-actions">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="cwSaveDraft" <?= $canSave ? '' : 'disabled' ?>>
            <i data-lucide="save" style="width:14px;height:14px" class="me-1"></i> Save Draft
        </button>
        <button type="button" class="btn btn-primary btn-sm" id="cwNextTop" <?= $wizardStart >= 4 ? 'hidden' : '' ?>>
            Next Step <i data-lucide="chevron-right" style="width:14px;height:14px" class="ms-1"></i>
        </button>
        <button type="submit" class="btn btn-primary btn-sm" id="cwCreateTop" <?= $wizardStart < 4 ? 'hidden' : '' ?> <?= $canSave ? '' : 'disabled' ?>>
            <i data-lucide="check" style="width:14px;height:14px" class="me-1"></i> Create Contract
        </button>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">

        <!-- Step 1 -->
        <div class="co-step-panel<?= $wizardStart === 1 ? ' active' : '' ?>" data-cw-panel="1">
            <div class="co-cw-card">
                <div class="card-hd">
                    <span class="ico"><i data-lucide="file-text" style="width:16px;height:16px"></i></span>
                    <h2>Contract Information</h2>
                </div>
                <div class="card-bd">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Contract Number</label>
                            <input type="text" name="contract_number" class="form-control" value="<?= h($_POST['contract_number'] ?? '') ?>" placeholder="Auto-generated">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tenant *</label>
                            <select name="client_id" id="clientId" class="form-select" required>
                                <option value="">Select tenant</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= (int)$client['id'] ?>" <?= (int)($_POST['client_id'] ?? 0) === (int)$client['id'] ? 'selected' : '' ?>><?= h($client['client_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" id="contractStatus">
                                <?php foreach (['draft','active'] as $status): ?>
                                    <option value="<?= h($status) ?>" <?= $status === ($_POST['status'] ?? 'active') ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Draft does not occupy shops. Active hard-blocks date overlap.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Shops * <span class="text-muted fw-normal">(one or more)</span></label>
                            <div class="co-cw-shops" id="shopPicker">
                                <?php foreach ($shops as $shop):
                                    $sid = (int)$shop['id'];
                                    $checked = in_array($sid, $postedShopIds, true);
                                ?>
                                    <div class="co-cw-shop-item">
                                        <input class="form-check-input shop-check" type="checkbox" name="shop_unit_ids[]" value="<?= $sid ?>" id="shop<?= $sid ?>" <?= $checked ? 'checked' : '' ?> data-status="<?= h($shop['status']) ?>" data-label="<?= h($shop['shop_number'] . ($shop['shop_name'] ? ' — ' . $shop['shop_name'] : '')) ?>">
                                        <label class="form-check-label flex-grow-1" for="shop<?= $sid ?>">
                                            <?= h($shop['shop_number'] . ($shop['shop_name'] ? ' — ' . $shop['shop_name'] : '')) ?>
                                            <span class="badge bg-<?= $shop['status'] === 'occupied' ? 'success' : ($shop['status'] === 'available' ? 'primary' : 'secondary') ?>"><?= h($shop['status']) ?></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$shops): ?><div class="text-muted p-2">No shop units — add shops first.</div><?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Primary Shop *</label>
                            <select name="primary_shop_unit_id" id="primaryShop" class="form-select" required></select>
                            <div class="form-text">Defaults to the first selected shop.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" id="startDate" class="form-control" required value="<?= h($_POST['start_date'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End Date *</label>
                            <input type="date" name="end_date" id="endDate" class="form-control" required value="<?= h($_POST['end_date'] ?? date('Y-m-d', strtotime('+1 year -1 day'))) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Payment Plan Frequency</label>
                            <select name="payment_frequency" id="paymentFrequency" class="form-select">
                                <?php foreach (['monthly','quarterly','semi_annual','annual'] as $f): ?>
                                    <option value="<?= h($f) ?>" <?= ($f === ($_POST['payment_frequency'] ?? 'monthly')) ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $f))) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Cheque planning only — not invoicing.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">No. of Rent Cheques</label>
                            <input type="number" min="0" name="rent_cheque_count" id="rentChequeCount" class="form-control" value="<?= h($_POST['rent_cheque_count'] ?? '0') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">No. of Deposit Cheques</label>
                            <input type="number" min="0" name="deposit_cheque_count" id="depositChequeCount" class="form-control" value="<?= h($_POST['deposit_cheque_count'] ?? '0') ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="combined_first_cheque" value="1" id="combinedFirstCheque" <?= !empty($_POST['combined_first_cheque']) ? 'checked' : '' ?> <?= $hasCombinedCol ? '' : 'disabled' ?>>
                                <label class="form-check-label" for="combinedFirstCheque">
                                    Combined first collection cheque — 1st rent + separate VAT (if any) + commission on <strong>one</strong> PDC
                                </label>
                                <div class="form-text">
                                    Opt-in for bank reconciliation. Requires ≥1 rent cheque. Does not merge accounting invoices.
                                    <?php if (!$hasCombinedCol): ?><span class="text-warning">Column missing — run combined_first_cheque migration.</span><?php endif; ?>
                                </div>
                                <div class="small mt-1" id="combinedFirstPreview" style="color:var(--co-gold-soft)"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2 -->
        <div class="co-step-panel<?= $wizardStart === 2 ? ' active' : '' ?>" data-cw-panel="2">
            <div class="co-cw-card">
                <div class="card-hd">
                    <span class="ico"><i data-lucide="coins" style="width:16px;height:16px"></i></span>
                    <h2>Contract Terms</h2>
                </div>
                <div class="card-bd">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Total Contract Rent (net) *</label>
                            <div class="co-cw-money-prefix">
                                <span class="pfx">AED</span>
                                <input type="number" step="0.01" name="rent_amount" id="rentAmount" class="form-control" required value="<?= h($_POST['rent_amount'] ?? '') ?>">
                            </div>
                            <div class="form-text">Full lease-term rent for all shops on this contract.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">VAT Mode</label>
                            <select name="vat_mode" id="vatMode" class="form-select">
                                <option value="exclusive" <?= $vatModePost === 'exclusive' ? 'selected' : '' ?>>Exclusive (add VAT)</option>
                                <option value="inclusive" <?= $vatModePost === 'inclusive' ? 'selected' : '' ?>>Inclusive (VAT in amount)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">VAT %</label>
                            <input type="number" step="0.01" name="vat_rate" id="vatRate" class="form-control" value="<?= h($_POST['vat_rate'] ?? '5') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">VAT Collection Method</label>
                            <select name="vat_collection_method" id="vatCollection" class="form-select">
                                <?php foreach ($vatOpts as $k => $label): ?>
                                    <option value="<?= h($k) ?>" <?= $vatMethodPost === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Security Deposit</label>
                            <div class="co-cw-money-prefix">
                                <span class="pfx">AED</span>
                                <input type="number" step="0.01" name="security_deposit" id="securityDeposit" class="form-control" value="<?= h($_POST['security_deposit'] ?? '0') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="accrual_deferred_rent" value="1" id="accrualDeferred" <?= !isset($_POST['accrual_deferred_rent']) || !empty($_POST['accrual_deferred_rent']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="accrualDeferred">Accrual Mode — Deferred Rent Revenue</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Terms</label>
                            <input type="text" name="payment_terms" id="paymentTerms" class="form-control" value="<?= h($_POST['payment_terms'] ?? '') ?>" placeholder="e.g. Net 30 / PDC schedule">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="contractNotes" class="form-control" rows="3" placeholder="Special conditions, commission remarks, handover notes…"><?= h($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3 -->
        <div class="co-step-panel<?= $wizardStart === 3 ? ' active' : '' ?>" data-cw-panel="3">
            <div class="co-cw-card">
                <div class="card-hd">
                    <span class="ico"><i data-lucide="percent" style="width:16px;height:16px"></i></span>
                    <h2>Tenant Commission</h2>
                </div>
                <div class="card-bd">
                    <?php if ($commissionReady): ?>
                    <p class="text-muted small mb-3">Charged by Madar Al Wadi to the tenant. Default 5% of total net contract rent (not per shop).</p>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="commission_enabled" value="1" id="addCommEnabled" <?= !isset($_POST['commission_enabled']) || !empty($_POST['commission_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="addCommEnabled">Enable Commission</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Basis</label>
                            <select name="commission_basis" id="commissionBasis" class="form-select">
                                <option value="percent" <?= ($_POST['commission_basis'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percent</option>
                                <option value="fixed" <?= ($_POST['commission_basis'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Percent %</label>
                            <input type="number" step="0.0001" name="commission_percent" id="commissionPercent" class="form-control" value="<?= h($_POST['commission_percent'] ?? '5') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fixed Amount</label>
                            <input type="number" step="0.01" name="commission_fixed_amount" id="commissionFixed" class="form-control" value="<?= h($_POST['commission_fixed_amount'] ?? '0') ?>">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="commission_manual_override" value="1" id="addCommManual" <?= !empty($_POST['commission_manual_override']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="addCommManual">Manual override</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Net (manual)</label>
                            <input type="number" step="0.01" name="commission_net_amount" id="commissionNetManual" class="form-control" value="<?= h($_POST['commission_net_amount'] ?? '') ?>">
                            <div class="form-text">Used only with override</div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="commission_vat_enabled" value="1" id="addCommVat" <?= !isset($_POST['commission_vat_enabled']) || !empty($_POST['commission_vat_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="addCommVat">VAT on commission</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Commission VAT %</label>
                            <input type="number" step="0.01" name="commission_vat_rate" id="commissionVatRate" class="form-control" value="<?= h($_POST['commission_vat_rate'] ?? '5') ?>">
                        </div>
                        <div class="col-12">
                            <div class="co-cw-comm-est" id="commLiveEst">Net Amount (Est.) —</div>
                            <div class="form-text mt-2">Commission remarks can be added in Notes on Financial Terms (same contract notes field).</div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning mb-0">Commission schema not ready. Run the Phase 1.6 commission migration to enable this step. You can continue without commission fields.</div>
                    <input type="hidden" name="commission_enabled" value="0">
                    <?php endif; ?>
                </div>
            </div>

            <div class="co-cw-card mt-3">
                <div class="card-hd">
                    <span class="ico"><i data-lucide="key" style="width:16px;height:16px"></i></span>
                    <h2>Key Money (optional)</h2>
                </div>
                <div class="card-bd">
                    <?php $chargesWizardReady = function_exists('co_shop_charges_schema_ready') ? co_shop_charges_schema_ready($conn) : false; ?>
                    <?php if ($chargesWizardReady): ?>
                    <p class="text-muted small mb-3">
                        One-time tenant revenue (not deposit). Default income account <code>4170</code>.
                        VAT uses Contract Charges modes — Exclusive (e.g. 5%), Inclusive, or None (Exempt / Zero Rated / Out of Scope).
                    </p>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="key_money_enabled" value="1" id="addKmEnabled" <?= !empty($_POST['key_money_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="addKmEnabled">Enable Key Money</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Amount (net)</label>
                            <input type="number" step="0.01" min="0" name="key_money_amount" id="keyMoneyAmount" class="form-control" value="<?= h($_POST['key_money_amount'] ?? '0') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">VAT mode</label>
                            <select name="key_money_vat_mode" id="keyMoneyVatMode" class="form-select">
                                <?php foreach (['exclusive' => 'Exclusive (VAT 5%)', 'inclusive' => 'Inclusive', 'none' => 'None (Exempt / Zero / OOS)'] as $vm => $vl): ?>
                                    <option value="<?= h($vm) ?>" <?= ($_POST['key_money_vat_mode'] ?? 'exclusive') === $vm ? 'selected' : '' ?>><?= h($vl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">VAT %</label>
                            <input type="number" step="0.01" min="0" name="key_money_vat_rate" id="keyMoneyVatRate" class="form-control" value="<?= h($_POST['key_money_vat_rate'] ?? '5') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Income account</label>
                            <input type="text" name="key_money_coa" id="keyMoneyCoa" class="form-control" value="<?= h($_POST['key_money_coa'] ?? '4170') ?>" placeholder="4170">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Invoice timing</label>
                            <select name="key_money_invoice_timing" id="keyMoneyTiming" class="form-select">
                                <option value="immediate" <?= ($_POST['key_money_invoice_timing'] ?? 'immediate') === 'immediate' ? 'selected' : '' ?>>Generate Immediately (default)</option>
                                <option value="on_start" <?= ($_POST['key_money_invoice_timing'] ?? '') === 'on_start' ? 'selected' : '' ?>>Generate On Contract Start Date</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Notes (optional)</label>
                            <input type="text" name="key_money_notes" id="keyMoneyNotes" class="form-control" maxlength="500" value="<?= h($_POST['key_money_notes'] ?? '') ?>">
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning mb-0">Charges schema not ready. Run <code>migrations/construction_shop_rental_phase_charges.sql</code> (and Key Money migration) to enable Key Money on create.</div>
                    <input type="hidden" name="key_money_enabled" value="0">
                    <?php endif; ?>
                </div>
            </div>

            <div class="co-cw-card mt-3">
                <div class="card-hd">
                    <span class="ico"><i data-lucide="gift" style="width:16px;height:16px"></i></span>
                    <h2>Rent Concession (optional)</h2>
                </div>
                <div class="card-bd">
                    <?php if ($concessionWizardReady): ?>
                    <p class="text-muted small mb-3">
                        Free-rent window inside Occupancy. Occupancy Start/End stay as the legal lease.
                        Rent invoices skip concession months (no AED&nbsp;0 invoices). Deposit, Key Money, and commission are unchanged.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="concession_enabled" value="1" id="addConcEnabled" <?= !empty($_POST['concession_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="addConcEnabled">Enable Rent Concession</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Reason</label>
                            <select name="concession_reason" id="concessionReason" class="form-select">
                                <?php foreach (CO_SHOP_CONCESSION_REASONS as $rk => $rl): ?>
                                    <option value="<?= h($rk) ?>" <?= ($_POST['concession_reason'] ?? 'fit_out') === $rk ? 'selected' : '' ?>><?= h($rl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Position</label>
                            <select name="concession_position" id="concessionPosition" class="form-select">
                                <?php foreach (CO_SHOP_CONCESSION_POSITIONS as $pk => $pl): ?>
                                    <option value="<?= h($pk) ?>" <?= ($_POST['concession_position'] ?? 'beginning') === $pk ? 'selected' : '' ?>><?= h($pl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Duration</label>
                            <input type="number" step="0.01" min="0" name="concession_duration_value" id="concessionDurationValue" class="form-control" value="<?= h($_POST['concession_duration_value'] ?? '1') ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Unit</label>
                            <select name="concession_duration_unit" id="concessionDurationUnit" class="form-select">
                                <option value="months" <?= ($_POST['concession_duration_unit'] ?? 'months') === 'months' ? 'selected' : '' ?>>Mo</option>
                                <option value="days" <?= ($_POST['concession_duration_unit'] ?? '') === 'days' ? 'selected' : '' ?>>Days</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">From</label>
                            <input type="date" name="concession_from" id="concessionFrom" class="form-control" value="<?= h($_POST['concession_from'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">To</label>
                            <input type="date" name="concession_to" id="concessionTo" class="form-control" value="<?= h($_POST['concession_to'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="concession_manual_dates" value="1" id="concessionManualDates" <?= !empty($_POST['concession_manual_dates']) || ($_POST['concession_position'] ?? '') === 'custom' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="concessionManualDates">Manual From/To</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Notes (optional)</label>
                            <input type="text" name="concession_notes" id="concessionNotes" class="form-control" maxlength="500" value="<?= h($_POST['concession_notes'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-text" id="concLiveHint">Beginning/End auto-fill From/To from Occupancy + Duration. Custom or Manual unlocks dates.</div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning mb-0">Rent Concession schema not ready. Run <code>migrations/construction_shop_rental_phase_rent_concession.sql</code>.</div>
                    <input type="hidden" name="concession_enabled" value="0">
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Step 4 -->
        <div class="co-step-panel<?= $wizardStart === 4 ? ' active' : '' ?>" data-cw-panel="4">
            <div class="co-cw-card">
                <div class="card-hd">
                    <span class="ico"><i data-lucide="clipboard-check" style="width:16px;height:16px"></i></span>
                    <h2>Review before saving</h2>
                </div>
                <div class="card-bd co-cw-review">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="text-muted">Contract</h6>
                            <dl>
                                <dt>Tenant</dt><dd id="rvTenant">—</dd>
                                <dt>Status</dt><dd id="rvStatus">—</dd>
                                <dt>Period</dt><dd id="rvPeriod">—</dd>
                                <dt>Frequency</dt><dd id="rvFrequency">—</dd>
                                <dt>Shops</dt><dd id="rvShops">—</dd>
                                <dt>Primary</dt><dd id="rvPrimary">—</dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Financial</h6>
                            <dl>
                                <dt>Rent (net)</dt><dd id="rvRent">—</dd>
                                <dt>VAT</dt><dd id="rvVat">—</dd>
                                <dt>Contract value</dt><dd id="rvValue">—</dd>
                                <dt>Deposit</dt><dd id="rvDeposit">—</dd>
                                <dt>Deferred rent</dt><dd id="rvDeferred">—</dd>
                                <dt>Payment terms</dt><dd id="rvTerms">—</dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Commission</h6>
                            <dl>
                                <dt>Enabled</dt><dd id="rvCommOn">—</dd>
                                <dt>Net (est.)</dt><dd id="rvCommNet">—</dd>
                                <dt>VAT (est.)</dt><dd id="rvCommVat">—</dd>
                                <dt>Gross (est.)</dt><dd id="rvCommGross">—</dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Key Money</h6>
                            <dl>
                                <dt>Enabled</dt><dd id="rvKmOn">—</dd>
                                <dt>Net (est.)</dt><dd id="rvKmNet">—</dd>
                                <dt>VAT (est.)</dt><dd id="rvKmVat">—</dd>
                                <dt>Gross (est.)</dt><dd id="rvKmGross">—</dd>
                                <dt>Timing</dt><dd id="rvKmTiming">—</dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Rent Concession</h6>
                            <dl>
                                <dt>Enabled</dt><dd id="rvConcOn">—</dd>
                                <dt>Window</dt><dd id="rvConcWindow">—</dd>
                                <dt>Occupancy / Chargeable</dt><dd id="rvConcMonths">—</dd>
                                <dt>Value (est.)</dt><dd id="rvConcValue">—</dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Payment plan</h6>
                            <dl>
                                <dt>Rent cheques</dt><dd id="rvRentChq">—</dd>
                                <dt>Deposit cheques</dt><dd id="rvDepChq">—</dd>
                                <dt>Combined first</dt><dd id="rvCombined">—</dd>
                                <dt>1st cheque (est.)</dt><dd id="rvFirstChq">—</dd>
                            </dl>
                        </div>
                        <div class="col-12" id="rvNotesWrap" hidden>
                            <h6 class="text-muted">Notes</h6>
                            <p class="mb-0 small" style="white-space:pre-wrap" id="rvNotes"></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="co-cw-nav">
                <button type="button" class="btn btn-outline-secondary" data-cw-prev>
                    <i data-lucide="chevron-left" style="width:14px;height:14px"></i> Previous
                </button>
                <button type="submit" class="btn btn-primary" <?= $canSave ? '' : 'disabled' ?>>
                    <i data-lucide="check" style="width:14px;height:14px" class="me-1"></i> Create Contract
                </button>
            </div>
        </div>

        <div class="co-cw-nav" id="cwNavMid" <?= $wizardStart >= 4 ? 'hidden' : '' ?>>
            <button type="button" class="btn btn-outline-secondary" data-cw-prev id="cwPrevMid" disabled>
                <i data-lucide="chevron-left" style="width:14px;height:14px"></i> Previous
            </button>
            <button type="button" class="btn btn-primary" data-cw-next>
                Next Step <i data-lucide="chevron-right" style="width:14px;height:14px"></i>
            </button>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <div class="co-cw-side">
            <div class="co-cw-card">
                <div class="card-hd">
                    <span class="ico"><i data-lucide="calculator" style="width:16px;height:16px"></i></span>
                    <h2>Summary Preview</h2>
                </div>
                <div class="card-bd">
                    <div class="co-cw-summary-row"><span class="lbl">Contract Rent (Net)</span><span class="val" id="smRent">AED 0.00</span></div>
                    <div class="co-cw-summary-row"><span class="lbl">VAT (<span id="smVatPct">5</span>%)</span><span class="val" id="smVat">AED 0.00</span></div>
                    <div class="co-cw-summary-row"><span class="lbl">Total Contract Value</span><span class="val" id="smValue">AED 0.00</span></div>
                    <div class="co-cw-summary-row"><span class="lbl">Security Deposit</span><span class="val" id="smDeposit">AED 0.00</span></div>
                    <div class="co-cw-summary-row"><span class="lbl">Tenant Commission (Est.)</span><span class="val" id="smComm">AED 0.00</span></div>
                    <div class="co-cw-summary-row"><span class="lbl">Key Money (Est.)</span><span class="val" id="smKeyMoney">AED 0.00</span></div>
                    <div class="co-cw-summary-row small text-muted" id="smKeyMoneyMeta" hidden><span class="lbl" id="smKeyMoneyMetaLbl"></span><span class="val"></span></div>
                    <div class="co-cw-summary-row"><span class="lbl">Occupancy Months</span><span class="val" id="smOccMonths">0</span></div>
                    <div class="co-cw-summary-row"><span class="lbl">Chargeable Months</span><span class="val" id="smChgMonths">0</span></div>
                    <div class="co-cw-summary-row"><span class="lbl">Concession Value (Est.)</span><span class="val" id="smConcValue">AED 0.00</span></div>
                    <div class="co-cw-due">
                        <div class="lbl">Est. due at start</div>
                        <div class="amt" id="smDue">AED 0.00</div>
                        <div class="form-text mb-0 mt-1">Deposit + first collection + Key Money (if enabled). Estimate only — not a posting rule.</div>
                    </div>
                    <div class="alert alert-info py-2 px-3 small mt-3 mb-0" style="background:rgba(56,189,248,.08);border-color:rgba(56,189,248,.25);color:#7dd3fc">
                        Estimates only. Invoices and GL post when you generate them on the contract.
                    </div>
                </div>
            </div>

            <div class="co-cw-card">
                <div class="card-hd">
                    <span class="ico"><i data-lucide="zap" style="width:16px;height:16px"></i></span>
                    <h2>Quick Actions</h2>
                </div>
                <div class="card-bd">
                    <div class="co-cw-qa-grid">
                        <button type="button" class="co-qa" id="cwCheckAvail">
                            <i data-lucide="store" style="width:18px;height:18px"></i> Check Availability
                        </button>
                        <button type="button" class="co-qa" id="cwCalcRent">
                            <i data-lucide="calculator" style="width:18px;height:18px"></i> Calculate Rent
                        </button>
                        <button type="button" class="co-qa" id="cwAddShops">
                            <i data-lucide="layout-grid" style="width:18px;height:18px"></i> Add Shops
                        </button>
                        <button type="button" class="co-qa" id="cwResetStep">
                            <i data-lucide="rotate-ccw" style="width:18px;height:18px"></i> Reset Step
                        </button>
                    </div>
                    <div class="form-text mt-2" id="cwQaMsg" hidden></div>
                </div>
            </div>

            <div class="co-cw-card">
                <div class="card-hd">
                    <span class="ico"><i data-lucide="shield-check" style="width:16px;height:16px"></i></span>
                    <h2>Health Check</h2>
                </div>
                <div class="card-bd">
                    <ul class="co-cw-health" id="cwHealth"></ul>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
(function () {
  var form = document.getElementById('shopContractForm');
  if (!form) return;
  var step = parseInt(form.getAttribute('data-start-step') || '1', 10) || 1;
  var primaryDefault = <?= (int)$postedPrimary ?>;
  var commissionReady = <?= $commissionReady ? 'true' : 'false' ?>;
  var concessionReady = <?= !empty($concessionWizardReady) ? 'true' : 'false' ?>;

  function ymd(d) {
    if (!(d instanceof Date) || isNaN(d.getTime())) return '';
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }
  function parseYmd(s) {
    if (!s) return null;
    var p = String(s).split('-');
    if (p.length !== 3) return null;
    var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    return isNaN(d.getTime()) ? null : d;
  }
  function addMonths(d, n) {
    var x = new Date(d.getTime());
    x.setMonth(x.getMonth() + n);
    return x;
  }
  function addDays(d, n) {
    var x = new Date(d.getTime());
    x.setDate(x.getDate() + n);
    return x;
  }
  function occupancyMonthWindows(startStr, endStr) {
    var start = parseYmd(startStr), end = parseYmd(endStr), out = [];
    if (!start || !end || end < start) return out;
    var cursor = new Date(start.getTime());
    while (cursor <= end && out.length < 120) {
      var ps = ymd(cursor);
      var peDt = addDays(addMonths(cursor, 1), -1);
      if (peDt > end) peDt = new Date(end.getTime());
      out.push({ period_start: ps, period_end: ymd(peDt) });
      cursor = addMonths(cursor, 1);
    }
    return out;
  }
  function computeConcessionDates(occStart, occEnd, position, durVal, unit) {
    var start = parseYmd(occStart), end = parseYmd(occEnd);
    var n = Math.ceil(Math.max(0, Number(durVal) || 0) - 0.00001);
    if (!start || !end || n <= 0) return { from: '', to: '' };
    if (position === 'end') {
      var fromDt;
      if (unit === 'days') fromDt = addDays(addDays(end, -n), 1);
      else fromDt = addDays(addMonths(end, -n), 1);
      if (fromDt < start) fromDt = new Date(start.getTime());
      return { from: ymd(fromDt), to: ymd(end) };
    }
    var toDt;
    if (unit === 'days') toDt = addDays(addDays(start, n), -1);
    else toDt = addDays(addMonths(start, n), -1);
    if (toDt > end) toDt = new Date(end.getTime());
    return { from: ymd(start), to: ymd(toDt) };
  }
  function syncConcessionDates(force) {
    if (!concessionReady) return;
    var enabled = !!($('addConcEnabled') || {}).checked;
    var pos = ($('concessionPosition') || {}).value || 'beginning';
    var manual = !!($('concessionManualDates') || {}).checked || pos === 'custom';
    var fromEl = $('concessionFrom'), toEl = $('concessionTo');
    if (!fromEl || !toEl) return;
    fromEl.readOnly = !enabled || (!manual && pos !== 'custom');
    toEl.readOnly = !enabled || (!manual && pos !== 'custom');
    if (!enabled || (manual && !force && pos === 'custom')) return;
    if (manual && pos === 'custom' && !force) return;
    if (manual && !force) return;
    var calc = computeConcessionDates(
      ($('startDate') || {}).value || '',
      ($('endDate') || {}).value || '',
      pos === 'custom' ? 'beginning' : pos,
      num('concessionDurationValue', 1),
      ($('concessionDurationUnit') || {}).value || 'months'
    );
    if (!manual || force || !fromEl.value || !toEl.value) {
      fromEl.value = calc.from;
      toEl.value = calc.to;
    }
  }

  function $(id) { return document.getElementById(id); }
  function fmt(n) {
    var v = Number(n) || 0;
    return 'AED ' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function num(id, def) {
    var el = $(id);
    if (!el) return def;
    var v = parseFloat(el.value);
    return isNaN(v) ? def : v;
  }
  function setText(id, text) {
    var el = $(id);
    if (el) el.textContent = text;
  }
  function persist() {
    try { sessionStorage.setItem('co_shop_add_step', String(step)); } catch (e) {}
  }
  function restore() {
    if (step !== 1) return;
    try {
      var s = parseInt(sessionStorage.getItem('co_shop_add_step') || '0', 10);
      if (s >= 1 && s <= 4) step = s;
    } catch (e) {}
  }

  function refreshPrimary() {
    var primary = $('primaryShop');
    if (!primary) return;
    var checks = Array.prototype.slice.call(document.querySelectorAll('.shop-check')).filter(function (c) { return c.checked; });
    var prev = primary.value || String(primaryDefault || '');
    primary.innerHTML = '';
    if (!checks.length) {
      var empty = document.createElement('option');
      empty.value = '';
      empty.textContent = 'Select shop(s) first';
      primary.appendChild(empty);
      return;
    }
    checks.forEach(function (c, i) {
      var opt = document.createElement('option');
      opt.value = c.value;
      opt.textContent = c.getAttribute('data-label') || c.value;
      primary.appendChild(opt);
    });
    var keep = checks.some(function (c) { return c.value === prev; }) ? prev : checks[0].value;
    primary.value = keep;
  }

  function calc() {
    var rent = num('rentAmount', 0);
    var vatRate = num('vatRate', 5);
    var mode = ($('vatMode') || {}).value || 'exclusive';
    var method = ($('vatCollection') || {}).value || 'included_in_installment';
    var rentNet = rent, rentVat = 0;
    if (mode === 'inclusive' && vatRate > 0) {
      rentVat = Math.round(rent * vatRate / (100 + vatRate) * 100) / 100;
      rentNet = Math.round((rent - rentVat) * 100) / 100;
    } else {
      rentVat = Math.round(rent * vatRate / 100 * 100) / 100;
    }
    var deposit = num('securityDeposit', 0);
    var cheques = Math.max(0, parseInt(($('rentChequeCount') || {}).value || '0', 10) || 0);
    var combined = !!($('combinedFirstCheque') || {}).checked;
    var firstRentNet = cheques > 0 ? Math.round((rentNet / cheques) * 100) / 100 : 0;
    var firstRentVat = (method === 'included_in_installment' || method === 'proportional')
      ? (cheques > 0 ? Math.round((rentVat / cheques) * 100) / 100 : 0) : 0;
    var firstRentGross = Math.round((firstRentNet + firstRentVat) * 100) / 100;
    var vatSep = method === 'separate' ? rentVat : 0;
    var commEnabled = commissionReady && !!($('addCommEnabled') || {}).checked;
    var commNet = 0, commVat = 0;
    if (commEnabled) {
      var basis = ($('commissionBasis') || {}).value || 'percent';
      var manual = !!($('addCommManual') || {}).checked;
      if (manual) commNet = num('commissionNetManual', 0);
      else if (basis === 'fixed') commNet = num('commissionFixed', 0);
      else commNet = Math.round(rent * num('commissionPercent', 5) / 100 * 100) / 100;
      if (($('addCommVat') || {}).checked) {
        commVat = Math.round(commNet * num('commissionVatRate', 5) / 100 * 100) / 100;
      }
    }
    var commGross = Math.round((commNet + commVat) * 100) / 100;
    var kmEnabled = !!($('addKmEnabled') || {}).checked;
    var kmNet = 0, kmVat = 0, kmGross = 0, kmTiming = 'immediate';
    if (kmEnabled) {
      kmNet = num('keyMoneyAmount', 0);
      kmTiming = ($('keyMoneyTiming') || {}).value || 'immediate';
      var kmVatMode = ($('keyMoneyVatMode') || {}).value || 'exclusive';
      var kmVatRate = num('keyMoneyVatRate', 5);
      if (kmVatMode === 'none' || kmVatRate <= 0) {
        kmVat = 0;
        kmGross = Math.round(kmNet * 100) / 100;
      } else if (kmVatMode === 'inclusive') {
        kmGross = Math.round(kmNet * 100) / 100;
        kmNet = Math.round(kmGross / (1 + kmVatRate / 100) * 100) / 100;
        kmVat = Math.round((kmGross - kmNet) * 100) / 100;
      } else {
        kmVat = Math.round(kmNet * kmVatRate / 100 * 100) / 100;
        kmGross = Math.round((kmNet + kmVat) * 100) / 100;
      }
    }
    var occStart = ($('startDate') || {}).value || '';
    var occEnd = ($('endDate') || {}).value || '';
    var occWindows = occupancyMonthWindows(occStart, occEnd);
    var occMonths = occWindows.length;
    var concEnabled = concessionReady && !!($('addConcEnabled') || {}).checked;
    var concFrom = ($('concessionFrom') || {}).value || '';
    var concTo = ($('concessionTo') || {}).value || '';
    var chgMonths = occMonths;
    var freeMonths = 0;
    if (concEnabled && concFrom && concTo) {
      chgMonths = 0;
      occWindows.forEach(function (w) {
        if (!(w.period_start <= concTo && w.period_end >= concFrom)) chgMonths++;
        else freeMonths++;
      });
    }
    chgMonths = Math.max(0, chgMonths);
    var monthlyEq = (rentNet > 0 && chgMonths > 0) ? Math.round((rentNet / chgMonths) * 100) / 100 : 0;
    var concValue = (concEnabled && freeMonths > 0) ? Math.round((monthlyEq * freeMonths) * 100) / 100 : 0;
    var firstCheque = combined
      ? Math.round((firstRentGross + vatSep + commGross) * 100) / 100
      : firstRentGross;
    var dueToday = Math.round((deposit + (combined ? firstCheque : (firstRentGross + (method === 'separate' ? vatSep : 0) + commGross)) + kmGross) * 100) / 100;
    return {
      rentNet: rentNet, rentVat: rentVat, contractValue: Math.round((rentNet + rentVat) * 100) / 100,
      deposit: deposit, vatRate: vatRate, vatMode: mode, vatMethod: method,
      commEnabled: commEnabled, commNet: commNet, commVat: commVat, commGross: commGross,
      kmEnabled: kmEnabled, kmNet: kmNet, kmVat: kmVat, kmGross: kmGross, kmTiming: kmTiming,
      concEnabled: concEnabled, concFrom: concFrom, concTo: concTo,
      occMonths: occMonths, chgMonths: chgMonths, freeMonths: freeMonths, concValue: concValue,
      cheques: cheques, combined: combined, firstCheque: firstCheque, firstRentGross: firstRentGross,
      vatSep: vatSep, dueToday: dueToday
    };
  }

  function refresh() {
    syncConcessionDates(false);
    var f = calc();
    var client = $('clientId');
    var tenant = '';
    if (client && client.value && client.selectedIndex >= 0) {
      tenant = client.options[client.selectedIndex].text || '';
    }
    var statusEl = $('contractStatus');
    var shops = Array.prototype.slice.call(document.querySelectorAll('.shop-check:checked'));
    var primary = $('primaryShop');
    var methodLabels = {
      included_in_installment: 'In installment',
      proportional: 'Proportional',
      separate: 'Separate',
      upfront: 'Upfront'
    };

    setText('smRent', fmt(f.rentNet));
    setText('smVatPct', String(f.vatRate));
    setText('smVat', fmt(f.rentVat));
    setText('smValue', fmt(f.contractValue));
    setText('smDeposit', fmt(f.deposit));
    setText('smComm', fmt(f.commGross));
    setText('smKeyMoney', fmt(f.kmGross));
    setText('smOccMonths', String(f.occMonths || 0));
    setText('smChgMonths', String(f.chgMonths || 0));
    setText('smConcValue', fmt(f.concValue || 0));
    var kmMeta = $('smKeyMoneyMeta');
    var kmMetaLbl = $('smKeyMoneyMetaLbl');
    if (kmMeta && kmMetaLbl) {
      if (f.kmEnabled && f.kmGross > 0) {
        kmMeta.hidden = false;
        kmMetaLbl.textContent = (f.kmTiming === 'on_start' ? 'Invoice on start date' : 'Invoice immediately')
          + (f.kmVat > 0 ? (' · net ' + fmt(f.kmNet) + ' + VAT ' + fmt(f.kmVat)) : ' · no VAT');
      } else {
        kmMeta.hidden = true;
        kmMetaLbl.textContent = '';
      }
    }
    setText('smDue', fmt(f.dueToday));

    setText('rvTenant', tenant || '—');
    setText('rvStatus', statusEl && statusEl.selectedIndex >= 0 ? statusEl.options[statusEl.selectedIndex].text : '—');
    setText('rvPeriod', (($('startDate') || {}).value || '—') + ' → ' + (($('endDate') || {}).value || '—'));
    setText('rvFrequency', ($('paymentFrequency') && $('paymentFrequency').selectedIndex >= 0) ? $('paymentFrequency').options[$('paymentFrequency').selectedIndex].text : '—');
    setText('rvShops', shops.length ? (shops.length + ' shop(s): ' + shops.map(function (s) { return s.getAttribute('data-label'); }).join(', ')) : 'None selected');
    setText('rvPrimary', primary && primary.selectedIndex >= 0 ? primary.options[primary.selectedIndex].text : '—');
    setText('rvRent', fmt(f.rentNet));
    setText('rvVat', fmt(f.rentVat) + ' · ' + f.vatMode + ' · ' + (methodLabels[f.vatMethod] || f.vatMethod));
    setText('rvValue', fmt(f.contractValue));
    setText('rvDeposit', fmt(f.deposit));
    setText('rvDeferred', ($('accrualDeferred') || {}).checked ? 'Yes' : 'No');
    setText('rvTerms', ($('paymentTerms') || {}).value || '—');
    setText('rvCommOn', f.commEnabled ? 'Yes' : 'No');
    setText('rvCommNet', fmt(f.commNet));
    setText('rvCommVat', fmt(f.commVat));
    setText('rvCommGross', fmt(f.commGross));
    setText('rvKmOn', f.kmEnabled ? 'Yes' : 'No');
    setText('rvKmNet', fmt(f.kmNet));
    setText('rvKmVat', fmt(f.kmVat));
    setText('rvKmGross', fmt(f.kmGross));
    setText('rvKmTiming', f.kmEnabled ? (f.kmTiming === 'on_start' ? 'On contract start' : 'Immediate') : '—');
    setText('rvConcOn', f.concEnabled ? 'Yes' : 'No');
    setText('rvConcWindow', f.concEnabled && f.concFrom && f.concTo ? (f.concFrom + ' → ' + f.concTo) : '—');
    setText('rvConcMonths', f.concEnabled ? (f.occMonths + ' occ / ' + f.chgMonths + ' chargeable') : (String(f.occMonths || 0) + ' months'));
    setText('rvConcValue', f.concEnabled ? fmt(f.concValue) : '—');
    setText('rvRentChq', String(f.cheques));
    setText('rvDepChq', String(parseInt(($('depositChequeCount') || {}).value || '0', 10) || 0));
    setText('rvCombined', f.combined ? 'Yes' : 'No');
    setText('rvFirstChq', f.combined ? fmt(f.firstCheque) : '—');
    var notes = ($('contractNotes') || {}).value || '';
    var nw = $('rvNotesWrap');
    if (nw) {
      nw.hidden = !notes;
      setText('rvNotes', notes);
    }

    var est = $('commLiveEst');
    if (est) {
      est.textContent = f.commEnabled
        ? ('Net Amount (Est.) ' + fmt(f.commNet) + (f.commVat ? (' · Gross ' + fmt(f.commGross)) : ''))
        : 'Commission disabled';
    }
    var hint = $('concLiveHint');
    if (hint) {
      if (!concessionReady) hint.textContent = 'Concession schema not ready.';
      else if (!f.concEnabled) hint.textContent = 'Enable to skip rent invoices during a free-rent window inside Occupancy.';
      else if (f.chgMonths <= 0) hint.textContent = 'Concession covers all Occupancy months — leave at least one chargeable month.';
      else hint.textContent = f.freeMonths + ' free month(s) · ' + f.chgMonths + ' chargeable · est. value ' + fmt(f.concValue);
    }
    var box = $('combinedFirstPreview');
    if (box) {
      box.textContent = f.combined
        ? ('Est. 1st cheque face ≈ ' + fmt(f.firstCheque)
          + ' (rent ' + f.firstRentGross.toFixed(2)
          + (f.vatSep ? (' + VAT sep ' + f.vatSep.toFixed(2)) : '')
          + (f.commGross > 0 ? (' + commission ' + f.commGross.toFixed(2)) : '')
          + '). Remaining rent cheques stay rent-only.')
        : '';
    }

    var rentOk = f.rentNet > 0 || num('rentAmount', 0) > 0;
    var datesOk = !!($('startDate') || {}).value && !!($('endDate') || {}).value;
    var occupied = shops.filter(function (s) { return (s.getAttribute('data-status') || '') === 'occupied'; }).length;
    var ready = shops.length > 0 && !!tenant && rentOk && datesOk;
    var items = [
      { cls: tenant ? 'ok' : 'bad', label: tenant ? 'Tenant selected' : 'Select a tenant' },
      { cls: shops.length ? 'ok' : 'bad', label: shops.length ? (shops.length + ' shop(s) selected' + (occupied ? ' · ' + occupied + ' marked occupied' : '')) : 'Select at least one shop' },
      { cls: !shops.length ? 'warn' : (occupied ? 'warn' : 'ok'), label: !shops.length ? 'Shop availability pending' : (occupied ? 'Some selected shops show occupied — overlap checked on Active save' : 'Selected shops look available') },
      { cls: datesOk ? 'ok' : 'bad', label: datesOk ? 'Contract dates set' : 'Set start and end dates' },
      { cls: rentOk ? 'ok' : 'warn', label: rentOk ? 'Rent amount entered' : 'Enter total contract rent (step 2)' },
      { cls: (f.cheques > 0 || num('depositChequeCount', 0) > 0) ? 'ok' : 'warn', label: (f.cheques > 0 || num('depositChequeCount', 0) > 0) ? 'Payment plan cheques configured' : 'Optional: configure cheque counts' },
      { cls: ready ? 'ok' : 'warn', label: ready ? 'Ready to continue / save' : 'Complete required fields before saving' }
    ];
    var health = $('cwHealth');
    if (health) {
      health.innerHTML = items.map(function (h) {
        return '<li class="' + h.cls + '"><span class="dot"></span><span>' + h.label.replace(/</g, '&lt;') + '</span></li>';
      }).join('');
    }
  }

  function go(n) {
    n = parseInt(n, 10);
    if (n < 1 || n > 4) return;
    step = n;
    persist();
    document.querySelectorAll('[data-cw-panel]').forEach(function (p) {
      p.classList.toggle('active', parseInt(p.getAttribute('data-cw-panel'), 10) === step);
    });
    document.querySelectorAll('[data-cw-goto]').forEach(function (b) {
      var sn = parseInt(b.getAttribute('data-cw-goto'), 10);
      b.classList.toggle('active', sn === step);
      b.classList.toggle('done', sn < step);
    });
    var nextTop = $('cwNextTop');
    var createTop = $('cwCreateTop');
    var navMid = $('cwNavMid');
    var prevMid = $('cwPrevMid');
    if (nextTop) nextTop.hidden = step >= 4;
    if (createTop) createTop.hidden = step < 4;
    if (navMid) navMid.hidden = step >= 4;
    if (prevMid) prevMid.disabled = step <= 1;
    if (window.lucide && lucide.createIcons) lucide.createIcons();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    refresh();
  }

  function setQa(msg) {
    var el = $('cwQaMsg');
    if (!el) return;
    el.hidden = !msg;
    el.textContent = msg || '';
  }

  restore();
  refreshPrimary();
  go(step);

  document.querySelectorAll('.shop-check').forEach(function (c) {
    c.addEventListener('change', function () {
      refreshPrimary();
      refresh();
    });
  });
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);

  document.querySelectorAll('[data-cw-goto]').forEach(function (b) {
    b.addEventListener('click', function () { go(b.getAttribute('data-cw-goto')); });
  });
  document.querySelectorAll('[data-cw-next], #cwNextTop').forEach(function (b) {
    b.addEventListener('click', function () { if (step < 4) go(step + 1); });
  });
  document.querySelectorAll('[data-cw-prev]').forEach(function (b) {
    b.addEventListener('click', function () { if (step > 1) go(step - 1); });
  });
  var sd = $('cwSaveDraft');
  if (sd) sd.addEventListener('click', function () {
    var st = $('contractStatus');
    if (st) st.value = 'draft';
    persist();
    form.requestSubmit();
  });
  var ca = $('cwCheckAvail');
  if (ca) ca.addEventListener('click', function () {
    var shops = Array.prototype.slice.call(document.querySelectorAll('.shop-check:checked'));
    if (!shops.length) { setQa('Select shops first.'); go(1); return; }
    var avail = shops.filter(function (s) { return s.getAttribute('data-status') === 'available'; }).length;
    var occ = shops.filter(function (s) { return s.getAttribute('data-status') === 'occupied'; }).length;
    setQa(shops.length + ' selected · ' + avail + ' available · ' + occ + ' occupied (Active save blocks date overlap).');
    go(1);
  });
  var cr = $('cwCalcRent');
  if (cr) cr.addEventListener('click', function () {
    var rent = num('rentAmount', 0);
    if (rent <= 0) { setQa('Enter total contract rent on Financial Terms.'); go(2); return; }
    var start = ($('startDate') || {}).value;
    var end = ($('endDate') || {}).value;
    var months = 12;
    try {
      if (start && end) {
        var a = new Date(start), b = new Date(end), n = 0, c = new Date(a);
        while (c <= b && n < 120) { n++; c.setMonth(c.getMonth() + 1); }
        months = Math.max(1, n);
      }
    } catch (e) {}
    setQa('≈ ' + fmt(Math.round((rent / months) * 100) / 100) + ' / month over ' + months + ' earning month(s) (estimate only).');
    go(2);
  });
  var as = $('cwAddShops');
  if (as) as.addEventListener('click', function () {
    go(1);
    var picker = $('shopPicker');
    if (picker) picker.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
  var rs = $('cwResetStep');
  if (rs) rs.addEventListener('click', function () {
    if (!confirm('Reset fields on this step to defaults?')) return;
    if (step === 1) {
      document.querySelectorAll('.shop-check').forEach(function (c, i) { c.checked = i === 0; });
      if ($('contractStatus')) $('contractStatus').value = 'active';
      if ($('startDate')) $('startDate').value = new Date().toISOString().slice(0, 10);
      if ($('endDate')) {
        var d = new Date(); d.setFullYear(d.getFullYear() + 1); d.setDate(d.getDate() - 1);
        $('endDate').value = d.toISOString().slice(0, 10);
      }
      if ($('paymentFrequency')) $('paymentFrequency').value = 'monthly';
      if ($('rentChequeCount')) $('rentChequeCount').value = '0';
      if ($('depositChequeCount')) $('depositChequeCount').value = '0';
      if ($('combinedFirstCheque')) $('combinedFirstCheque').checked = false;
      refreshPrimary();
    } else if (step === 2) {
      if ($('rentAmount')) $('rentAmount').value = '';
      if ($('vatRate')) $('vatRate').value = '5';
      if ($('vatMode')) $('vatMode').value = 'exclusive';
      if ($('vatCollection')) $('vatCollection').value = 'included_in_installment';
      if ($('securityDeposit')) $('securityDeposit').value = '0';
      if ($('accrualDeferred')) $('accrualDeferred').checked = true;
      if ($('paymentTerms')) $('paymentTerms').value = '';
      if ($('contractNotes')) $('contractNotes').value = '';
    } else if (step === 3) {
      if (commissionReady) {
        if ($('addCommEnabled')) $('addCommEnabled').checked = true;
        if ($('commissionBasis')) $('commissionBasis').value = 'percent';
        if ($('commissionPercent')) $('commissionPercent').value = '5';
        if ($('commissionFixed')) $('commissionFixed').value = '0';
        if ($('addCommManual')) $('addCommManual').checked = false;
        if ($('commissionNetManual')) $('commissionNetManual').value = '';
        if ($('addCommVat')) $('addCommVat').checked = true;
        if ($('commissionVatRate')) $('commissionVatRate').value = '5';
      }
      if ($('addKmEnabled')) $('addKmEnabled').checked = false;
      if ($('keyMoneyAmount')) $('keyMoneyAmount').value = '0';
      if ($('keyMoneyVatMode')) $('keyMoneyVatMode').value = 'exclusive';
      if ($('keyMoneyVatRate')) $('keyMoneyVatRate').value = '5';
      if ($('keyMoneyCoa')) $('keyMoneyCoa').value = '4170';
      if ($('keyMoneyTiming')) $('keyMoneyTiming').value = 'immediate';
      if ($('keyMoneyNotes')) $('keyMoneyNotes').value = '';
      if ($('addConcEnabled')) $('addConcEnabled').checked = false;
      if ($('concessionReason')) $('concessionReason').value = 'fit_out';
      if ($('concessionPosition')) $('concessionPosition').value = 'beginning';
      if ($('concessionDurationValue')) $('concessionDurationValue').value = '1';
      if ($('concessionDurationUnit')) $('concessionDurationUnit').value = 'months';
      if ($('concessionFrom')) $('concessionFrom').value = '';
      if ($('concessionTo')) $('concessionTo').value = '';
      if ($('concessionManualDates')) $('concessionManualDates').checked = false;
      if ($('concessionNotes')) $('concessionNotes').value = '';
    }
    setQa('Step reset to defaults.');
    refresh();
  });
  ['concessionPosition', 'concessionDurationValue', 'concessionDurationUnit', 'startDate', 'endDate', 'addConcEnabled', 'concessionManualDates'].forEach(function (id) {
    var el = $(id);
    if (!el) return;
    el.addEventListener('change', function () {
      var manual = !!($('concessionManualDates') || {}).checked || (($('concessionPosition') || {}).value === 'custom');
      if (!manual || id === 'addConcEnabled' || id === 'concessionManualDates') {
        syncConcessionDates(true);
      }
      refresh();
    });
  });
})();
</script>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
