<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_once __DIR__ . '/includes/construction_shop_rental_ops_helpers.php';
require_once __DIR__ . '/includes/construction_shop_rental_charge_helpers.php';
require_once __DIR__ . '/includes/construction_receipt_view_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_shop_require_company_id($conn);
$userId = (int)(current_user_id() ?: 0);
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: shop_rental_contracts.php'); exit; }
$msg = '';
$err = '';
$warn = [];
if (!empty($_SESSION['co_shop_flash_warn']) && is_array($_SESSION['co_shop_flash_warn'])) {
    $warn = $_SESSION['co_shop_flash_warn'];
    unset($_SESSION['co_shop_flash_warn']);
}
$phase1Ready = co_shop_phase1_schema_ready($conn);
$phase175Ready = co_shop_phase175_schema_ready($conn);

$contract = co_shop_contract_load($conn, $cid, $id);
if (!$contract) { header('Location: shop_rental_contracts.php'); exit; }

$chargesReady = co_shop_charges_schema_ready($conn);
if ($chargesReady) {
    try {
        co_shop_sync_contract_charges_from_legacy($conn, $cid, $id, $userId ?: null);
        $dueKm = co_shop_try_generate_due_key_money($conn, $cid, $id, $userId);
        if (!empty($dueKm['generated'])) {
            $msg = 'Key Money invoice #' . (int)$dueKm['invoice_id'] . ' generated (contract start date reached).';
        }
    } catch (Throwable $e) {
        // Non-fatal: UI will show migration notice
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    try {
        if (!$phase1Ready && !in_array($action, ['generate_schedules', 'generate_cheques', 'generate_cheques_quick', 'save_cheques', 'invoice_schedule', 'generate_invoices_pending', 'update_shops_dates', 'set_status', 'save_commission', 'generate_commission_invoice', 'save_concession', 'delete_contract', 'save_charges', 'add_custom_charge', 'generate_charge_invoice'], true)) {
            throw new RuntimeException('Run migrations/construction_shop_rental_phase1.sql for deposit/receipt/credit features.');
        }
        if (in_array($action, ['save_charges', 'add_custom_charge', 'generate_charge_invoice'], true) && !$chargesReady) {
            throw new RuntimeException('Run migrations/construction_shop_rental_phase_charges.sql for contract charges.');
        }
        if ($action === 'save_charges') {
            $rows = [];
            $ids = (array)($_POST['charge_id'] ?? []);
            foreach ($ids as $i => $cidRow) {
                $rows[] = [
                    'id' => (int)$cidRow,
                    'amount' => (float)($_POST['charge_amount'][$i] ?? 0),
                    'vat_mode' => (string)($_POST['charge_vat_mode'][$i] ?? 'exclusive'),
                    'vat_rate' => (float)($_POST['charge_vat_rate'][$i] ?? 0),
                    'status' => (string)($_POST['charge_status'][$i] ?? 'active'),
                    'notes' => (string)($_POST['charge_notes'][$i] ?? ''),
                    'gl_account_override' => (string)($_POST['charge_gl'][$i] ?? ''),
                    'invoice_timing' => (string)($_POST['charge_invoice_timing'][$i] ?? 'immediate'),
                ];
            }
            co_shop_save_contract_charges($conn, $cid, $id, $rows, $userId ?: null);
            $contract = co_shop_contract_load($conn, $cid, $id);
            $msg = 'Contract charges saved (legacy rent/deposit/commission synced).';
        } elseif ($action === 'add_custom_charge') {
            $newId = co_shop_add_custom_contract_charge(
                $conn,
                $cid,
                $id,
                (string)($_POST['custom_name'] ?? ''),
                (float)($_POST['custom_amount'] ?? 0),
                (string)($_POST['custom_coa'] ?? ''),
                (string)($_POST['custom_vat_mode'] ?? 'exclusive'),
                (float)($_POST['custom_vat_rate'] ?? 5),
                $userId ?: null
            );
            $msg = 'Custom charge added (#' . $newId . ').';
        } elseif ($action === 'generate_charge_invoice') {
            $chargeId = (int)($_POST['charge_id'] ?? 0);
            $invDate = $_POST['key_money_invoice_date'] ?? ($_POST['charge_invoice_date'] ?? null);
            $result = co_shop_generate_charge_invoice($conn, $cid, $id, $chargeId, $userId, $invDate ?: null);
            $msg = 'Charge invoice #' . (int)$result['invoice_id'] . ' created and posted.';
        } elseif ($action === 'set_status') {
            if (!$phase175Ready) {
                throw new RuntimeException('Run migrations/construction_shop_rental_phase175.sql for occupancy controls.');
            }
            $newStatus = (string)($_POST['status'] ?? '');
            $conn->beginTransaction();
            if ($newStatus === 'active' && !empty($contract['parent_contract_id']) && ($contract['status'] ?? '') === 'draft' && co_shop_phase2a_schema_ready($conn)) {
                co_shop_activate_renewal($conn, $cid, $id, $userId);
                $msg = 'Renewal activated. Previous contract marked Renewed.';
            } else {
                co_shop_set_contract_status($conn, $cid, $id, $newStatus);
                $msg = 'Contract status updated to ' . $newStatus . '.';
            }
            $conn->commit();
        } elseif ($action === 'update_shops_dates') {
            if (!$phase175Ready) {
                throw new RuntimeException('Run migrations/construction_shop_rental_phase175.sql for multi-shop support.');
            }
            $startDate = $_POST['start_date'] ?? $contract['start_date'];
            $endDate = $_POST['end_date'] ?? $contract['end_date'];
            $sel = co_shop_normalize_shop_selection(
                (array)($_POST['shop_unit_ids'] ?? []),
                (int)($_POST['primary_shop_unit_id'] ?? 0) ?: null
            );
            $shopIds = $sel['shop_ids'];
            $primaryId = $sel['primary_id'];
            if (($contract['status'] ?? '') === 'active') {
                co_shop_assert_no_active_overlap($conn, $cid, $shopIds, $startDate, $endDate, $id);
            } else {
                $warn = array_merge($warn, co_shop_draft_overlap_warnings($conn, $cid, $shopIds, $startDate, $endDate, $id));
            }
            $before = co_shop_phase2a_schema_ready($conn)
                ? co_shop_contract_amendment_snapshot($conn, $cid, $contract)
                : null;
            $conn->beginTransaction();
            $conn->prepare("UPDATE co_shop_rental_contracts SET start_date = ?, end_date = ? WHERE id = ? AND company_id = ?")
                ->execute([$startDate, $endDate, $id, $cid]);
            co_shop_sync_contract_shops($conn, $cid, $id, $shopIds, $primaryId);
            if (($contract['status'] ?? '') === 'active') {
                co_shop_occupy_contract_shops($conn, $cid, $id);
            }
            if ($before) {
                $fresh = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
                $fresh->execute([$id, $cid]);
                $afterContract = $fresh->fetch(PDO::FETCH_ASSOC);
                $after = co_shop_contract_amendment_snapshot($conn, $cid, $afterContract);
                $reason = trim((string)($_POST['amendment_reason'] ?? ''));
                if ($reason === '') {
                    $reason = ($contract['status'] ?? '') === 'active'
                        ? 'Active contract shops/dates update'
                        : 'Draft shops/dates update';
                }
                co_shop_record_amendment($conn, $cid, $id, $reason, $before, $after, $userId);
            }
            $conn->commit();
            $msg = 'Shops and lease dates updated.';
        } elseif ($action === 'update_vat_collection') {
            if (!$phase1Ready) {
                throw new RuntimeException('Run migrations/construction_shop_rental_phase1.sql first.');
            }
            $newMethod = co_shop_normalize_vat_collection($_POST['vat_collection_method'] ?? '');
            $reason = trim((string)($_POST['amendment_reason'] ?? ''));
            $result = co_shop_change_vat_collection_method($conn, $cid, $id, $newMethod, $reason, $userId);
            $labels = co_shop_vat_collection_options();
            $msg = 'VAT collection changed from "'
                . ($labels[$result['from']] ?? $result['from'])
                . '" to "'
                . ($labels[$result['to']] ?? $result['to'])
                . '". Rebuilt '
                . (int)$result['cheques'] . ' cheque row(s) and '
                . (int)$result['schedules'] . ' schedule row(s).';
        } elseif ($action === 'cheque_lifecycle') {
            $chequeId = (int)($_POST['cheque_id'] ?? 0);
            $op = (string)($_POST['lifecycle_op'] ?? '');
            $note = trim((string)($_POST['lifecycle_note'] ?? ''));
            if ($op === 'bounce') {
                co_shop_cheque_bounce($conn, $cid, $chequeId, $note, $userId);
                $msg = 'Cheque marked bounced.';
            } elseif ($op === 'cancel') {
                co_shop_cheque_cancel($conn, $cid, $chequeId, $note, $userId);
                $msg = 'Cheque cancelled.';
            } elseif ($op === 'lost') {
                co_shop_cheque_mark_lost($conn, $cid, $chequeId, $note, $userId);
                $msg = 'Cheque marked lost.';
            } elseif ($op === 'redeposit') {
                co_shop_cheque_redeposit($conn, $cid, $chequeId, $note, $userId);
                $msg = 'Cheque re-deposited.';
            } elseif ($op === 'replace') {
                $newId = co_shop_cheque_replace($conn, $cid, $chequeId, [
                    'cheque_number' => $_POST['new_cheque_number'] ?? null,
                    'bank_name' => $_POST['new_bank_name'] ?? null,
                    'cheque_date' => $_POST['new_cheque_date'] ?? date('Y-m-d'),
                    'amount' => isset($_POST['new_amount']) ? (float)$_POST['new_amount'] : null,
                    'lifecycle_note' => $note,
                ], $userId);
                $msg = 'Replacement cheque #' . $newId . ' created.';
            } else {
                throw new RuntimeException('Unknown cheque lifecycle operation.');
            }
        } elseif ($action === 'cheque_bank_status') {
            $chequeId = (int)($_POST['cheque_id'] ?? 0);
            $bankStatus = (string)($_POST['bank_status'] ?? '');
            $note = trim((string)($_POST['lifecycle_note'] ?? ''));
            co_shop_cheque_mark_bank_status($conn, $cid, $chequeId, $bankStatus, $note !== '' ? $note : null, $userId);
            $msg = 'Cheque bank status updated to ' . $bankStatus . ' (no invoice settlement).';
        } elseif ($action === 'generate_cheques_quick') {
            $rentChequeCount = max(0, (int)($contract['rent_cheque_count'] ?? 0));
            $depositChequeCount = max(0, (int)($contract['deposit_cheque_count'] ?? 0));
            if ($rentChequeCount <= 0 && $depositChequeCount <= 0) {
                throw new RuntimeException('Set rent/deposit cheque counts in the Cheque Plan section first.');
            }
            $conn->beginTransaction();
            $created = co_create_shop_cheque_plan($conn, $cid, $id, $rentChequeCount, $depositChequeCount);
            $conn->commit();
            $msg = $created . ' cheque row(s) generated.';
        } elseif ($action === 'generate_invoices_pending') {
            $pending = $conn->prepare("
                SELECT * FROM co_shop_rent_schedules
                WHERE company_id = ? AND contract_id = ? AND status = 'pending' AND invoice_id IS NULL
                ORDER BY period_start, id
            ");
            $pending->execute([$cid, $id]);
            $rows = $pending->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                throw new RuntimeException('No pending schedules to invoice.');
            }
            $incomeAccountId = co_default_income_account_id($conn, $cid, 'shop_rental');
            $created = 0;
            // Do not wrap the loop in one outer transaction: create_and_post_journal
            // owns its own transaction per invoice. An outer begin/commit causes
            // "There is no active transaction" after journals already committed.
            foreach ($rows as $schedule) {
                if (($schedule['schedule_type'] ?? 'rent') === 'vat') {
                    continue; // Retired: Separate VAT uses Payment Receipt → prepaid liability
                }
                $invoiceNumber = co_next_document_number($conn, $cid, 'SHOP-INV', 'co_client_invoices', 'invoice_number');
                $shopLabel = co_shop_contract_shops_label($conn, $cid, $id) ?: ($contract['shop_number'] ?? '');
                $desc = 'Shop rent ' . $shopLabel . ' for ' . $schedule['period_start'] . ' to ' . $schedule['period_end'];
                $net = (float)($schedule['net_amount'] ?? $schedule['amount']);
                $vat = (float)$schedule['vat_amount'];
                $invoiceId = co_create_income_invoice($conn, $cid, (int)$contract['client_id'], 0, 'shop_rental', (int)$schedule['id'], $invoiceNumber, $schedule['due_date'], $schedule['due_date'], $net, $vat, $desc, $incomeAccountId, $userId);
                $rentCharge = $chargesReady ? co_shop_contract_charge_by_code($conn, $cid, $id, CO_SHOP_CHARGE_RENT) : null;
                if ($rentCharge) {
                    co_shop_link_invoice_to_charge($conn, $cid, $invoiceId, (int)$rentCharge['id']);
                }
                $postResult = co_post_client_invoice_to_accounting($invoiceId, $cid, $userId);
                if (!$postResult['success']) {
                    throw new RuntimeException(($postResult['error'] ?? 'Invoice posting failed.') . ' (' . $created . ' invoice(s) already posted.)');
                }
                $conn->prepare("UPDATE co_client_invoices SET journal_id = ? WHERE id = ? AND company_id = ?")->execute([$postResult['journal_id'], $invoiceId, $cid]);
                $conn->prepare("UPDATE co_shop_rent_schedules SET invoice_id = ?, status = 'invoiced' WHERE id = ? AND company_id = ?")->execute([$invoiceId, (int)$schedule['id'], $cid]);
                if (!empty($schedule['cheque_id'])) {
                    $conn->prepare("UPDATE co_shop_rent_cheques SET invoice_id = ?, schedule_id = ? WHERE id = ? AND company_id = ?")
                        ->execute([$invoiceId, (int)$schedule['id'], (int)$schedule['cheque_id'], $cid]);
                }
                $created++;
            }
            $msg = $created . ' invoice(s) generated and posted.';
        } elseif ($action === 'generate_schedules') {
            // Replace pending (uninvoiced) earning rows so Option B monthly schedules refresh safely
            $conn->prepare("
                DELETE FROM co_shop_rent_schedules
                WHERE company_id = ? AND contract_id = ? AND status = 'pending' AND invoice_id IS NULL
            ")->execute([$cid, $id]);
            $created = co_generate_shop_schedules_from_cheques($conn, $cid, $id);
            $msg = $created . ' monthly earning schedule row(s) created.';
        } elseif ($action === 'generate_cheques') {
            $rentChequeCount = max(0, (int)($_POST['rent_cheque_count'] ?? 0));
            $depositChequeCount = max(0, (int)($_POST['deposit_cheque_count'] ?? 0));
            $combinedFirst = !empty($_POST['combined_first_cheque']) ? 1 : 0;
            if ($combinedFirst && $rentChequeCount <= 0) {
                throw new RuntimeException('Combined first collection requires at least 1 rent cheque.');
            }
            $conn->beginTransaction();
            if (co_db_column_exists($conn, 'co_shop_rental_contracts', 'combined_first_cheque')) {
                $conn->prepare("UPDATE co_shop_rental_contracts SET rent_cheque_count = ?, deposit_cheque_count = ?, combined_first_cheque = ? WHERE id = ? AND company_id = ?")
                    ->execute([$rentChequeCount, $depositChequeCount, $combinedFirst, $id, $cid]);
            } else {
                $conn->prepare("UPDATE co_shop_rental_contracts SET rent_cheque_count = ?, deposit_cheque_count = ? WHERE id = ? AND company_id = ?")
                    ->execute([$rentChequeCount, $depositChequeCount, $id, $cid]);
            }
            $created = co_create_shop_cheque_plan($conn, $cid, $id, $rentChequeCount, $depositChequeCount);
            $conn->commit();
            $msg = $created . ' cheque row(s) generated.'
                . ($combinedFirst ? ' First cheque is combined (1st rent + separate VAT if any + commission).' : '');
        } elseif ($action === 'save_cheques') {
            $cheques = $_POST['cheques'] ?? [];
            $hasVatCols = co_db_column_exists($conn, 'co_shop_rent_cheques', 'vat_amount');
            if ($hasVatCols) {
                $stmt = $conn->prepare("
                    UPDATE co_shop_rent_cheques
                    SET cheque_number = ?, bank_name = ?, cheque_date = ?, amount = ?, vat_amount = ?, net_amount = ?, status = ?, notes = ?
                    WHERE id = ? AND company_id = ? AND contract_id = ? AND status <> 'cleared'
                ");
            } else {
                $stmt = $conn->prepare("
                    UPDATE co_shop_rent_cheques
                    SET cheque_number = ?, bank_name = ?, cheque_date = ?, amount = ?, status = ?, notes = ?
                    WHERE id = ? AND company_id = ? AND contract_id = ? AND status <> 'cleared'
                ");
            }
            foreach ($cheques as $chequeId => $row) {
                $chequeDate = $row['cheque_date'] ?? date('Y-m-d');
                $amount = (float)($row['amount'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $status = in_array(($row['status'] ?? 'received'), ['received','deposited','bounced','returned','replaced','cancelled'], true) ? $row['status'] : 'received';
                if ($hasVatCols) {
                    $vat = (float)($row['vat_amount'] ?? 0);
                    $net = (float)($row['net_amount'] ?? max(0, $amount - $vat));
                    $stmt->execute([
                        trim($row['cheque_number'] ?? '') ?: null,
                        trim($row['bank_name'] ?? '') ?: null,
                        $chequeDate,
                        $amount,
                        $vat,
                        $net,
                        $status,
                        trim($row['notes'] ?? '') ?: null,
                        (int)$chequeId,
                        $cid,
                        $id,
                    ]);
                } else {
                    $stmt->execute([
                        trim($row['cheque_number'] ?? '') ?: null,
                        trim($row['bank_name'] ?? '') ?: null,
                        $chequeDate,
                        $amount,
                        $status,
                        trim($row['notes'] ?? '') ?: null,
                        (int)$chequeId,
                        $cid,
                        $id,
                    ]);
                }
            }
            $msg = 'Cheque details saved.';
        } elseif ($action === 'invoice_schedule') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM co_shop_rent_schedules WHERE id = ? AND company_id = ? AND contract_id = ? AND status = 'pending'");
            $stmt->execute([$scheduleId, $cid, $id]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$schedule) throw new RuntimeException('Schedule not found or already invoiced.');
            if (($schedule['schedule_type'] ?? 'rent') === 'vat') {
                throw new RuntimeException(
                    'VAT-only tax invoices are retired. Collect Separate VAT via Payment Workspace — '
                    . 'it posts a Payment Receipt to VAT Collected in Advance (2330). Monthly rent invoices are the Tax Invoices.'
                );
            }
            $incomeAccountId = co_default_income_account_id($conn, $cid, 'shop_rental');
            $invoiceNumber = co_next_document_number($conn, $cid, 'SHOP-INV', 'co_client_invoices', 'invoice_number');
            $shopLabel = co_shop_contract_shops_label($conn, $cid, $id) ?: ($contract['shop_number'] ?? '');
            $desc = 'Shop rent ' . $shopLabel . ' for ' . $schedule['period_start'] . ' to ' . $schedule['period_end'];
            $net = (float)($schedule['net_amount'] ?? $schedule['amount']);
            $vat = (float)$schedule['vat_amount'];
            // Posting owns its transaction — do not wrap with begin/commit here.
            $invoiceId = co_create_income_invoice($conn, $cid, (int)$contract['client_id'], 0, 'shop_rental', $scheduleId, $invoiceNumber, $schedule['due_date'], $schedule['due_date'], $net, $vat, $desc, $incomeAccountId, $userId);
            $rentCharge = $chargesReady ? co_shop_contract_charge_by_code($conn, $cid, $id, CO_SHOP_CHARGE_RENT) : null;
            if ($rentCharge) {
                co_shop_link_invoice_to_charge($conn, $cid, $invoiceId, (int)$rentCharge['id']);
            }
            $postResult = co_post_client_invoice_to_accounting($invoiceId, $cid, $userId);
            if (!$postResult['success']) throw new RuntimeException($postResult['error'] ?? 'Invoice posting failed.');
            $conn->prepare("UPDATE co_client_invoices SET journal_id = ? WHERE id = ? AND company_id = ?")->execute([$postResult['journal_id'], $invoiceId, $cid]);
            $conn->prepare("UPDATE co_shop_rent_schedules SET invoice_id = ?, status = 'invoiced' WHERE id = ? AND company_id = ?")->execute([$invoiceId, $scheduleId, $cid]);
            if (!empty($schedule['cheque_id'])) {
                $conn->prepare("UPDATE co_shop_rent_cheques SET invoice_id = ?, schedule_id = ? WHERE id = ? AND company_id = ?")
                    ->execute([$invoiceId, $scheduleId, (int)$schedule['cheque_id'], $cid]);
            }
            $msg = 'Invoice generated and posted.';
        } elseif ($action === 'clear_cheque') {
            $chequeId = (int)($_POST['cheque_id'] ?? 0);
            $payAccountId = (int)($_POST['pay_account_id'] ?? 0);
            $clearDate = $_POST['clear_date'] ?? date('Y-m-d');
            if ($payAccountId <= 0) {
                throw new RuntimeException('Choose the bank/cash account where this cheque cleared.');
            }
            $stmt = $conn->prepare("SELECT * FROM co_shop_rent_cheques WHERE id = ? AND company_id = ? AND contract_id = ?");
            $stmt->execute([$chequeId, $cid, $id]);
            $cheque = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cheque) {
                throw new RuntimeException('Cheque not found.');
            }
            // Phase 4: rent/VAT/commission instruments settle only via Payment Workspace (no dual post path).
            if (($cheque['cheque_type'] ?? '') !== 'security_deposit') {
                throw new RuntimeException(
                    'Use Payment Workspace to allocate this cheque (Cheques tab → Allocate Payment). '
                    . 'Inline clear/allocate is disabled to protect accounting integrity.'
                );
            }
            if (in_array($cheque['status'] ?? '', ['cleared', 'allocated', 'cancelled'], true)) {
                throw new RuntimeException('Cheque not found or already cleared.');
            }
            $conn->beginTransaction();
            $result = co_shop_record_deposit_receipt(
                $conn, $cid, $id, (float)$cheque['amount'], $payAccountId, $clearDate, $chequeId,
                trim((string)$cheque['cheque_number']) ?: null, $userId
            );
            $conn->commit();
            $msg = 'Deposit cheque cleared via unified deposit path (journal #' . $result['journal_id'] . ').';
        } elseif ($action === 'record_deposit') {
            $amount = (float)($_POST['deposit_amount'] ?? 0);
            $payAccountId = (int)($_POST['pay_account_id'] ?? 0);
            $date = $_POST['deposit_date'] ?? date('Y-m-d');
            $reference = trim($_POST['reference'] ?? '');
            $chequeId = (int)($_POST['cheque_id'] ?? 0) ?: null;
            $conn->beginTransaction();
            $result = co_shop_record_deposit_receipt($conn, $cid, $id, $amount, $payAccountId, $date, $chequeId, $reference ?: null, $userId);
            $conn->commit();
            $msg = 'Security deposit recorded (receipt #' . $result['receipt_id'] . ').';
        } elseif ($action === 'record_receipt') {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $payAccountId = (int)($_POST['pay_account_id'] ?? 0);
            $date = $_POST['payment_date'] ?? date('Y-m-d');
            $reference = trim($_POST['reference'] ?? '');
            $conn->beginTransaction();
            $payResult = co_shop_record_invoice_payment($conn, $cid, $invoiceId, $amount, $payAccountId, $date, $reference ?: null, $userId);
            $conn->commit();
            $msg = 'Receipt recorded. Allocated ' . co_format_money($payResult['allocated'])
                . ($payResult['credit'] > 0 ? '; credit ' . co_format_money($payResult['credit']) : '') . '.';
        } elseif ($action === 'apply_credit') {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $conn->beginTransaction();
            $result = co_shop_apply_client_credit_to_invoice($conn, $cid, (int)$contract['client_id'], $invoiceId, $amount, $userId);
            $conn->commit();
            $msg = 'Applied credit ' . co_format_money($result['applied']) . ' to invoice.';
        } elseif ($action === 'save_commission') {
            if (!co_shop_commission_schema_ready($conn)) {
                throw new RuntimeException('Run migrations/construction_shop_rental_phase16_commission.sql');
            }
            $amt = co_shop_save_commission_settings($conn, $cid, $id, $_POST);
            $msg = 'Commission settings saved. Net ' . co_format_money($amt['net'])
                . ($amt['vat'] > 0 ? (' + VAT ' . co_format_money($amt['vat'])) : '') . '.';
        } elseif ($action === 'save_concession') {
            if (!co_shop_concession_schema_ready($conn)) {
                throw new RuntimeException('Run migrations/construction_shop_rental_phase_rent_concession.sql');
            }
            $saved = co_shop_save_concession_settings($conn, $cid, $id, $_POST, $userId ?: null);
            // Refresh pending earning months so concession filter applies immediately
            $conn->prepare("
                DELETE FROM co_shop_rent_schedules
                WHERE company_id = ? AND contract_id = ? AND status = 'pending' AND invoice_id IS NULL
            ")->execute([$cid, $id]);
            $created = co_generate_shop_schedules_from_cheques($conn, $cid, $id);
            $contract = co_shop_contract_load($conn, $cid, $id);
            $ev = $saved['event'] ?? 'updated';
            $msg = 'Rent concession ' . $ev . '. Pending schedule refreshed (' . (int)$created . ' row(s)).';
        } elseif ($action === 'generate_commission_invoice') {
            $result = co_shop_generate_commission_invoice($conn, $cid, $id, $userId, $_POST['commission_invoice_date'] ?? null);
            $msg = 'Commission invoice ' . $result['invoice_number'] . ' generated and posted (journal #' . $result['journal_id'] . ').';
        } elseif ($action === 'delete_contract') {
            if (strtoupper(trim((string)($_POST['confirm_purge'] ?? ''))) !== 'DELETE') {
                throw new RuntimeException('Type DELETE to confirm permanent purge of this contract and its financial postings.');
            }
            $result = co_shop_delete_contract($conn, $cid, $id, $userId);
            $p = $result['purged'] ?? [];
            $_SESSION['co_shop_contracts_flash'] = [
                'msg' => 'Purged contract ' . ($result['contract_number'] ?: ('#' . $id))
                    . ' — invoices: ' . (int)($p['invoices'] ?? 0)
                    . ', payments: ' . (int)($p['payments'] ?? 0)
                    . ', journals: ' . (int)($p['journals'] ?? 0) . '.',
            ];
            header('Location: shop_rental_contracts.php');
            exit;
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $err = $e->getMessage();
    }
    $contract = co_shop_contract_load($conn, $cid, $id);
}

$schedulesStmt = $conn->prepare("
    SELECT s.*, i.invoice_number, i.status AS invoice_status
    FROM co_shop_rent_schedules s
    LEFT JOIN co_client_invoices i ON i.id = s.invoice_id
    WHERE s.company_id = ? AND s.contract_id = ?
    ORDER BY s.period_start, s.id
");
$schedulesStmt->execute([$cid, $id]);
$schedules = $schedulesStmt->fetchAll(PDO::FETCH_ASSOC);
$chequesStmt = $conn->prepare("
    SELECT *
    FROM co_shop_rent_cheques
    WHERE company_id = ? AND contract_id = ?
    ORDER BY cheque_type DESC, cheque_date, id
");
$chequesStmt->execute([$cid, $id]);
$cheques = $chequesStmt->fetchAll(PDO::FETCH_ASSOC);
$paymentAccounts = co_fetch_payment_accounts($conn, $cid);
$dashboard = $phase1Ready ? co_shop_contract_dashboard($conn, $cid, $id) : [];
$summary = $dashboard;
$health = $phase1Ready ? co_shop_contract_health($conn, $cid, $id, $dashboard ?: null) : ['checks' => [], 'overall' => 'warning', 'score_ok' => 0, 'score_warn' => 0, 'score_err' => 0];
$diagnostics = $phase1Ready ? co_shop_contract_diagnostics($conn, $cid, $id, $dashboard ?: null, $health) : [];
$timeline = $phase1Ready ? co_shop_contract_timeline_audit($conn, $cid, $id) : [];
$quickActions = co_shop_contract_quick_actions($conn, $cid, $id, $contract, $health, $dashboard ?: null);
$navLinks = co_shop_contract_nav_links($contract, $id);
$openDepositCheques = array_values(array_filter($cheques, static function ($c) {
    return $c['cheque_type'] === 'security_deposit' && !in_array($c['status'], ['cleared', 'cancelled'], true);
}));
$vatMethodLabel = co_shop_vat_collection_options()[$contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED] ?? 'VAT included';
$vatMethodChange = $phase1Ready
    ? co_shop_vat_collection_change_eligibility($conn, $cid, $id)
    : ['allowed' => false, 'blockers' => ['Phase 1 schema required.'], 'current' => null];
$hasVatCols = co_db_column_exists($conn, 'co_shop_rent_cheques', 'vat_amount');
$contractShops = co_shop_contract_shops($conn, $cid, $id);
$shopsLabel = co_shop_contract_shops_label($conn, $cid, $id);
$allShops = $conn->prepare("SELECT id, shop_number, shop_name, status FROM co_shop_units WHERE company_id = ? ORDER BY shop_number");
$allShops->execute([$cid]);
$allShops = $allShops->fetchAll(PDO::FETCH_ASSOC);
$linkedShopIds = array_map(static fn($s) => (int)$s['shop_unit_id'], $contractShops);
$primaryShopId = 0;
foreach ($contractShops as $s) {
    if (!empty($s['is_primary'])) {
        $primaryShopId = (int)$s['shop_unit_id'];
        break;
    }
}
if (!$primaryShopId && $linkedShopIds) {
    $primaryShopId = $linkedShopIds[0];
}
$pastEndDate = ($contract['status'] === 'active' && (string)$contract['end_date'] < date('Y-m-d'));
$deleteEligibility = co_shop_contract_delete_eligibility($conn, $cid, $id);
$commissionStatus = null;
$cInv = null;
if (co_shop_commission_schema_ready($conn)) {
    $commissionStatus = $summary['commission'] ?? co_shop_commission_status($conn, $cid, $contract);
    $cInv = $commissionStatus['invoice'] ?? null;
}
$commissionReady = co_shop_commission_schema_ready($conn);
$contractCharges = $chargesReady ? co_shop_list_contract_charges($conn, $cid, $id) : [];
$keyMoneyStatus = $chargesReady
    ? ($summary['key_money'] ?? co_shop_key_money_status($conn, $cid, $id))
    : null;
$kmInv = $keyMoneyStatus['invoice'] ?? null;
$concessionReady = function_exists('co_shop_concession_schema_ready') && co_shop_concession_schema_ready($conn);
$concessionStatus = $concessionReady ? co_shop_concession_status($contract) : null;
$concessionLocked = $concessionReady
    ? co_shop_concession_has_posted_rent_invoices($conn, $cid, $id)
    : true;

$coUiV2 = true;
$pageTitle = 'Shop Rental Contract';
require_once __DIR__ . '/includes/construction_layout_header.php';
require_once __DIR__ . '/includes/ui/shop_rental_contract_view_shell.php';
require_once __DIR__ . '/includes/construction_layout_footer.php';
