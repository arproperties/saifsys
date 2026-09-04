<?php
/**
 * Real Estate Module - Lease View
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/payment_allocation_helper.php';
require_once __DIR__ . '/includes/billing_helper.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';
require_once __DIR__ . '/includes/accounting_mode_helper.php';
require_once __DIR__ . '/includes/lease_payment_schedule_helper.php';
require_once __DIR__ . '/includes/cheque_lifecycle_helper.php';
require_once __DIR__ . '/includes/receipt_allocation_engine.php';
require_once __DIR__ . '/includes/security_deposit_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
re_lease_activate_due_renewals($conn, $currentCompanyId, current_user_id());

$leaseId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$leaseId) {
    header('Location: leases.php');
    exit;
}

// Handle waive penalty charge (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['waive_billing_item_id'])) {
    csrf_verify();
    $billingItemId = (int)$_POST['waive_billing_item_id'];
    if ($billingItemId > 0) {
        $chk = $conn->prepare("SELECT id FROM re_billing_items WHERE id = ? AND lease_id = ? AND company_id = ? AND item_type = 'penalty' LIMIT 1");
        $chk->execute([$billingItemId, $leaseId, $currentCompanyId]);
        if ($chk->fetch()) {
            try {
                $conn->prepare("UPDATE re_billing_items SET is_waived = 1, status = 'waived' WHERE id = ?")->execute([$billingItemId]);
                $_SESSION['success'] = 'Penalty charge waived.';
            } catch (Throwable $e) {
                $_SESSION['error'] = 'Could not waive (ensure migration add_billing_items_is_waived.sql has been run).';
            }
        }
    }
    header('Location: lease_view.php?id=' . $leaseId);
    exit;
}

// Handle edit penalty amount (POST) — supports discounting a penalty before collection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['edit_penalty_id'])) {
    csrf_verify();
    $billingItemId  = (int)$_POST['edit_penalty_id'];
    $newAmount      = isset($_POST['edit_penalty_amount']) ? (float)$_POST['edit_penalty_amount'] : -1;
    $editNote       = trim($_POST['edit_penalty_note'] ?? '');
    if ($billingItemId > 0 && $newAmount >= 0) {
        $chk = $conn->prepare("SELECT id FROM re_billing_items WHERE id = ? AND lease_id = ? AND company_id = ? AND item_type = 'penalty' AND is_paid = 0 LIMIT 1");
        $chk->execute([$billingItemId, $leaseId, $currentCompanyId]);
        if ($chk->fetch()) {
            try {
                $conn->prepare("
                    UPDATE re_billing_items
                    SET total_amount = ?, amount = ?,
                        notes = CONCAT(COALESCE(notes,''), IF(? != '', CONCAT(' | Edited: ', ?), ''))
                    WHERE id = ?
                ")->execute([$newAmount, $newAmount, $editNote, $editNote, $billingItemId]);
                $_SESSION['success'] = 'Penalty amount updated to ' . number_format($newAmount, 2) . ' AED.';
            } catch (Throwable $e) {
                $_SESSION['error'] = 'Could not update penalty amount: ' . $e->getMessage();
            }
        }
    }
    header('Location: lease_view.php?id=' . $leaseId);
    exit;
}

// Cancel an applied service charge and stop future unpaid service-fee schedule rows.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cancel_service_charge_id'])) {
    csrf_verify();
    require_once __DIR__ . '/includes/service_charge_payment_plan_helper.php';
    $serviceChargeId = (int)$_POST['cancel_service_charge_id'];
    if ($serviceChargeId > 0) {
        $chk = $conn->prepare("
            SELECT id, charge_name
            FROM re_service_charges
            WHERE id = ? AND lease_id = ? AND company_id = ? AND is_active = 1
            LIMIT 1
        ");
        $chk->execute([$serviceChargeId, $leaseId, $currentCompanyId]);
        $service = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$service) {
            $_SESSION['error'] = 'Service charge not found or already cancelled.';
        } else {
            $stop = re_sc_stop_future_billing(
                $conn,
                (int)$currentCompanyId,
                $serviceChargeId,
                (int)$leaseId,
                'cancelled',
                date('Y-m-d'),
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                'Cancelled from lease view'
            );
            if (!empty($stop['success'])) {
                $_SESSION['success'] = 'Service charge "' . $service['charge_name'] . '" cancelled. Unpaid Extra Service schedule, obligations, and invoices were cleared.';
            } else {
                $_SESSION['error'] = 'Could not cancel service charge: ' . ($stop['error'] ?? 'Unknown error');
            }
        }
    }
    header('Location: lease_view.php?id=' . $leaseId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['clear_cancelled_service_charge_id'])) {
    csrf_verify();
    require_once __DIR__ . '/includes/service_charge_payment_plan_helper.php';
    $serviceChargeId = (int)$_POST['clear_cancelled_service_charge_id'];
    if ($serviceChargeId > 0) {
        $chk = $conn->prepare("
            SELECT id, charge_name, lifecycle_status
            FROM re_service_charges
            WHERE id = ? AND lease_id = ? AND company_id = ?
            LIMIT 1
        ");
        $chk->execute([$serviceChargeId, $leaseId, $currentCompanyId]);
        $service = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$service || (string)($service['lifecycle_status'] ?? '') !== 'cancelled') {
            $_SESSION['error'] = 'Service charge not found or is not cancelled.';
        } else {
            $ownTx = !$conn->inTransaction();
            try {
                if ($ownTx) {
                    $conn->beginTransaction();
                }
                $clear = re_sc_clear_unpaid_accounting(
                    $conn,
                    (int)$currentCompanyId,
                    $serviceChargeId,
                    (int)$leaseId,
                    date('Y-m-d'),
                    isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                    'Clear leftovers after cancel from lease view'
                );
                if (empty($clear['success'])) {
                    throw new RuntimeException($clear['error'] ?? 'Clear failed');
                }
                if (function_exists('re_service_charge_sync_invoice_mode_obligations')) {
                    re_service_charge_sync_invoice_mode_obligations(
                        $conn,
                        (int)$currentCompanyId,
                        (int)$leaseId,
                        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
                    );
                }
                if ($ownTx) {
                    $conn->commit();
                }
                $_SESSION['success'] = 'Cleared remaining open Extra Service items for "' . $service['charge_name'] . '".'
                    . ' Voided invoices: ' . (int)($clear['voided_invoices'] ?? 0) . '.';
            } catch (Throwable $e) {
                if ($ownTx && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                $_SESSION['error'] = 'Could not clear cancelled service leftovers: ' . $e->getMessage();
            }
        }
    }
    header('Location: lease_view.php?id=' . $leaseId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['suspend_service_charge_id'])) {
    csrf_verify();
    require_once __DIR__ . '/includes/service_charge_payment_plan_helper.php';
    $serviceChargeId = (int)$_POST['suspend_service_charge_id'];
    if ($serviceChargeId > 0) {
        $chk = $conn->prepare("
            SELECT id, charge_name, lifecycle_status
            FROM re_service_charges
            WHERE id = ? AND lease_id = ? AND company_id = ? AND is_active = 1
            LIMIT 1
        ");
        $chk->execute([$serviceChargeId, $leaseId, $currentCompanyId]);
        $service = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$service) {
            $_SESSION['error'] = 'Service charge not found or not active.';
        } elseif (($service['lifecycle_status'] ?? 'active') === 'suspended') {
            $_SESSION['error'] = 'Service charge is already suspended.';
        } else {
            $stop = re_sc_stop_future_billing(
                $conn,
                (int)$currentCompanyId,
                $serviceChargeId,
                (int)$leaseId,
                'suspended',
                date('Y-m-d'),
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                'Suspended from lease view'
            );
            if (!empty($stop['success'])) {
                $_SESSION['success'] = 'Service charge "' . $service['charge_name'] . '" suspended. Future unpaid schedule rows were stopped and Invoice Mode obligations synced.';
            } else {
                $_SESSION['error'] = 'Could not suspend service charge: ' . ($stop['error'] ?? 'Unknown error');
            }
        }
    }
    header('Location: lease_view.php?id=' . $leaseId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_service_cheque') {
    csrf_verify();
    require_once __DIR__ . '/includes/service_charge_payment_plan_helper.php';
    $planLineId = (int)($_POST['plan_line_id'] ?? 0);
    $reg = re_sc_register_service_cheque(
        $conn,
        (int)$currentCompanyId,
        $planLineId,
        [
            'cheque_number' => $_POST['cheque_number'] ?? '',
            'cheque_amount' => $_POST['cheque_amount'] ?? null,
            'cheque_date' => $_POST['cheque_date'] ?? null,
            'bank_name' => $_POST['bank_name'] ?? null,
            'account_holder_name' => $_POST['account_holder_name'] ?? null,
            'notes' => $_POST['notes'] ?? null,
        ],
        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
    );
    if (!empty($reg['success'])) {
        $_SESSION['success'] = 'Service cheque registered (#' . (int)$reg['cheque_id'] . '). Rent PDCs were not changed.';
    } else {
        $_SESSION['error'] = 'Could not register service cheque: ' . ($reg['error'] ?? 'Unknown error');
    }
    header('Location: lease_view.php?id=' . $leaseId);
    exit;
}

// Admin-only "Repair schedule": deterministically reconcile a (legacy) lease's
// installments + cheque mirrors to the engine plan, and (re)generate due penalties.
// Replaces the old silent GET-time reconcile. Locked rows are always respected.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repair_schedule'])) {
    csrf_verify();
    if (!(has_role('Owner', $conn) || has_role('Admin', $conn))) {
        $_SESSION['error'] = 'You do not have permission to repair the schedule.';
        header('Location: lease_view.php?id=' . $leaseId);
        exit;
    }
    require_once __DIR__ . '/includes/lease_installment_schedule.php';
    require_once __DIR__ . '/includes/lease_schedule_engine.php';
    try {
        $lRow = $conn->prepare("SELECT * FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
        $lRow->execute([$leaseId, $currentCompanyId]);
        $repairLease = $lRow->fetch(PDO::FETCH_ASSOC);
        if (!$repairLease) {
            throw new Exception('Lease not found.');
        }
        $repairAccountingMode = re_accounting_mode_for_existing_lease($conn, $repairLease);
        if ($repairAccountingMode === 'invoice') {
            $conn->beginTransaction();
            $repairReport = lease_repair_invoice_mode_operational_schedule(
                $conn,
                (int)$leaseId,
                (int)$currentCompanyId,
                $repairLease
            );
            $conn->commit();
            $_SESSION['success'] = sprintf(
                'Operational schedule repaired. Fees row fixed: %d, unpaid duplicates removed: %d, paid duplicates cancelled: %d. Invoices, receipts, and allocations were not changed.',
                (int)($repairReport['fees_amount_fixed'] ?? 0) + (int)($repairReport['fees_retagged'] ?? 0),
                (int)($repairReport['unpaid_deleted'] ?? 0),
                (int)($repairReport['paid_cancelled'] ?? 0)
            );
            header('Location: lease_view.php?id=' . $leaseId);
            exit;
        }
        // Derive the separate-deposit flag from an existing security_deposit row.
        $sdChk = $conn->prepare("SELECT COUNT(*) FROM re_lease_installments WHERE lease_id = ? AND company_id = ? AND installment_type = 'security_deposit'");
        $sdChk->execute([$leaseId, $currentCompanyId]);
        $repairLease['sep_security_deposit'] = ((int)$sdChk->fetchColumn() > 0) ? 1 : 0;

        $totals = lease_compute_totals($repairLease);

        $conn->beginTransaction();
        lease_reconcile_separate_fee_installments($conn, (int)$leaseId, (int)$currentCompanyId, $repairLease);
        lease_reconcile_unpaid_rent_installments(
            $conn,
            (int)$leaseId,
            (int)$currentCompanyId,
            (float)$totals['effective_annual'],
            (int)($repairLease['number_of_installments'] ?? 12),
            !empty($repairLease['add_fees_to_first_installment']),
            (float)$totals['to_first']
        );
        lease_sync_cheque_amounts($conn, (int)$leaseId, (int)$currentCompanyId);
        lease_sync_recognition_schedule($conn, (int)$leaseId, (int)$currentCompanyId);
        $conn->commit();

        $penaltiesCreated = re_generate_lease_penalties($conn, (int)$currentCompanyId, (int)$leaseId);
        $_SESSION['success'] = 'Schedule repaired. Paid/allocated rows were preserved.'
            . ($penaltiesCreated > 0 ? ' Generated ' . $penaltiesCreated . ' penalty charge(s).' : '');
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error'] = 'Could not repair schedule: ' . $e->getMessage();
    }
    header('Location: lease_view.php?id=' . $leaseId);
    exit;
}

// Handle cheque/installment workflow actions: Deposit / Bounce / Hold / Escalate to Legal (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cheque_action'])) {
    csrf_verify();
    require_once __DIR__ . '/includes/cheque_legal_helper.php';

    $chequeAction = (string)$_POST['cheque_action'];
    $chequeId     = (int)($_POST['cheque_id'] ?? 0);
    $note         = trim((string)($_POST['cheque_note'] ?? ''));
    $bouncedDate  = trim((string)($_POST['bounced_date'] ?? ''));
    $bankName     = trim((string)($_POST['bank_name'] ?? ''));
    $depositBankAccountId = (int)($_POST['deposit_bank_account_id'] ?? 0);

    if (!in_array($chequeAction, ['deposit', 'bounce', 'hold', 'escalate'], true) || $chequeId <= 0) {
        $_SESSION['error'] = 'Invalid cheque action.';
        header('Location: lease_view.php?id=' . $leaseId);
        exit;
    }

    try {
        // Load the cheque (scoped to this lease + company).
        $cq = $conn->prepare("SELECT * FROM re_post_dated_cheques WHERE id = ? AND lease_id = ? AND company_id = ? LIMIT 1");
        $cq->execute([$chequeId, $leaseId, $currentCompanyId]);
        $cheque = $cq->fetch(PDO::FETCH_ASSOC);
        if (!$cheque) {
            throw new Exception('Cheque not found for this lease.');
        }
        if (($cheque['status'] ?? '') === 'cleared') {
            throw new Exception('This cheque is already cleared and cannot be changed here.');
        }

        // Lease context for the legal inbox + email.
        $lc = $conn->prepare("
            SELECT l.lease_number, l.tenant_id, l.unit_id,
                   TRIM(CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,''))) AS tenant_name,
                   t.company_name, t.tenant_type,
                   u.unit_number, b.name AS building_name
            FROM re_leases l
            LEFT JOIN re_tenants t ON t.id = l.tenant_id
            LEFT JOIN re_units u ON u.id = l.unit_id
            LEFT JOIN re_buildings b ON b.id = u.building_id
            WHERE l.id = ? AND l.company_id = ? LIMIT 1
        ");
        $lc->execute([$leaseId, $currentCompanyId]);
        $lctx = $lc->fetch(PDO::FETCH_ASSOC) ?: [];
        $tenantName = (($lctx['tenant_type'] ?? '') === 'company' && !empty($lctx['company_name']))
            ? $lctx['company_name']
            : trim((string)($lctx['tenant_name'] ?? ''));
        $unitLabel = trim((string)($lctx['building_name'] ?? '') . ' ' . (string)($lctx['unit_number'] ?? ''));

        $emailCtx = [
            'lease_id'      => $leaseId,
            'lease_number'  => $lctx['lease_number'] ?? null,
            'tenant_name'   => $tenantName,
            'unit_label'    => $unitLabel,
            'cheque_number' => $cheque['cheque_number'] ?? null,
            'cheque_amount' => $cheque['cheque_amount'] ?? null,
            'cheque_date'   => $cheque['cheque_date'] ?? null,
            'bounced_date'  => $bouncedDate ?: null,
            'bank_name'     => $bankName ?: ($cheque['bank_name'] ?? null),
            'reason'        => $note,
        ];
        $escBase = [
            'lease_id'       => $leaseId,
            'installment_id' => $cheque['installment_id'] ?? null,
            'cheque_id'      => $chequeId,
            'tenant_id'      => $lctx['tenant_id'] ?? null,
            'cheque_number'  => $cheque['cheque_number'] ?? null,
            'cheque_amount'  => $cheque['cheque_amount'] ?? null,
            'cheque_date'    => $cheque['cheque_date'] ?? null,
            'reason'         => $note,
            'created_by'     => current_user_id(),
        ];

        if ($chequeAction === 'deposit') {
            $result = re_cheque_update_status($conn, (int)$currentCompanyId, $chequeId, 'deposited', current_user_id(), $note, 'lease_view');
            if (empty($result['success'])) {
                throw new Exception((string)($result['error'] ?? 'Could not mark cheque deposited.'));
            }
            $_SESSION['success'] = 'Cheque marked as deposited. Clearing must be completed through the approved receipt/allocation flow.';
        } elseif ($chequeAction === 'bounce') {
            if ($bouncedDate === '') {
                $bouncedDate = date('Y-m-d');
            }
            if ($depositBankAccountId <= 0) {
                throw new Exception('Select the company bank account where this cheque was deposited.');
            }
            $bankLookup = $conn->prepare("
                SELECT ba.id, ba.bank_name, ba.account_name, coa.account_code
                FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id AND coa.company_id = ba.company_id
                WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
                LIMIT 1
            ");
            $bankLookup->execute([$depositBankAccountId, $currentCompanyId]);
            $depositBank = $bankLookup->fetch(PDO::FETCH_ASSOC);
            if (!$depositBank) {
                throw new Exception('Selected bank account is invalid for this company.');
            }
            $bankLabel = trim(
                (string)($depositBank['account_code'] ?? '') . ' — '
                . (string)($depositBank['account_name'] ?? '')
                . (!empty($depositBank['bank_name']) ? ' (' . $depositBank['bank_name'] . ')' : '')
            );
            $bankName = $bankLabel !== '—' ? $bankLabel : (string)($depositBank['bank_name'] ?? '');
            $emailCtx['bank_name'] = $bankName;
            $emailCtx['bounced_date'] = $bouncedDate;

            $bounceOptions = [
                'bounced_date' => $bouncedDate,
                'bank_name' => $bankName,
                'deposit_bank_account_id' => $depositBankAccountId,
            ];
            $result = re_cheque_update_status(
                $conn,
                (int)$currentCompanyId,
                $chequeId,
                'bounced',
                current_user_id(),
                $note ?: 'Marked bounced from Lease View',
                'lease_view',
                null,
                null,
                false,
                $bounceOptions
            );
            if (empty($result['success'])) {
                throw new Exception((string)($result['error'] ?? 'Could not mark cheque bounced.'));
            }
            re_cheque_create_bounced_penalty($conn, (int)$currentCompanyId, $cheque, current_user_id());

            $escBase['type'] = 'bounced';
            cheque_record_legal_escalation($conn, (int)$currentCompanyId, $escBase);
            $mail = cheque_notify_legal_department($conn, (int)$currentCompanyId, ['type' => 'bounced'] + $emailCtx);

            $_SESSION['success'] = 'Cheque marked as bounced and forwarded to the legal department.'
                . ' No GL bounce journal was posted (match the bank statement bounce via Bank Reconciliation Create/Adjust if needed).'
                . (empty($mail['success']) ? ' (Email could not be sent — check Email Settings / Legal Department Email(s).)' : '');
        } elseif ($chequeAction === 'hold') {
            try {
                $result = re_cheque_update_status($conn, (int)$currentCompanyId, $chequeId, 'held_by_finance', current_user_id(), $note, 'lease_view');
                if (empty($result['success'])) {
                    throw new Exception((string)($result['error'] ?? 'Could not place cheque on hold.'));
                }
                $_SESSION['success'] = 'Cheque placed on hold.';
            } catch (Throwable $e) {
                $_SESSION['error'] = 'Could not place cheque on hold. Run the Phase 6 cheque migration first.';
            }
        } else { // escalate
            $result = re_cheque_update_status($conn, (int)$currentCompanyId, $chequeId, 'legal_escalated', current_user_id(), $note, 'lease_view');
            if (empty($result['success'])) {
                throw new Exception((string)($result['error'] ?? 'Could not escalate cheque.'));
            }
            $escBase['type'] = 'escalated';
            $escId = cheque_record_legal_escalation($conn, (int)$currentCompanyId, $escBase);
            $mail = cheque_notify_legal_department($conn, (int)$currentCompanyId, ['type' => 'escalated'] + $emailCtx);
            if ($escId === null && !cheque_legal_escalations_table_exists($conn)) {
                $_SESSION['error'] = 'Escalation recorded for email only. Run migrations/add_cheque_hold_and_legal_escalations.sql to show it in the Legal inbox.';
            } else {
                $_SESSION['success'] = 'Cheque escalated to the legal department.'
                    . (empty($mail['success']) ? ' (Email could not be sent — check Email Settings / legal recipients.)' : '');
            }
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Could not update cheque: ' . $e->getMessage();
    }
    header('Location: lease_view.php?id=' . $leaseId . '#installments');
    exit;
}

// Handle OWNER-ONLY clean (hard) delete of a lease and all related records (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hard_delete_lease'])) {
    csrf_verify();
    if (!has_role('Owner', $conn)) {
        $_SESSION['error'] = 'Only an Owner can permanently delete a lease.';
        header('Location: lease_view.php?id=' . $leaseId);
        exit;
    }
    $confirmNumber = trim((string)($_POST['confirm_lease_number'] ?? ''));
    try {
        $ln = $conn->prepare("SELECT lease_number FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
        $ln->execute([$leaseId, $currentCompanyId]);
        $realNumber = (string)$ln->fetchColumn();
        if ($realNumber === '') {
            throw new RuntimeException('Lease not found.');
        }
        if ($confirmNumber !== $realNumber) {
            throw new RuntimeException('Confirmation text did not match the lease number. Deletion cancelled.');
        }
        $report = re_lease_hard_delete($conn, (int)$currentCompanyId, (int)$leaseId, (int)current_user_id());
        $_SESSION['success'] = 'Lease ' . $realNumber . ' and all related records were permanently deleted ('
            . array_sum($report) . ' rows removed).';
        header('Location: leases.php');
        exit;
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Could not delete lease: ' . $e->getMessage();
        header('Location: lease_view.php?id=' . $leaseId);
        exit;
    }
}

// Handle manual credit balance override (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_credit_balance'])) {
    csrf_verify();
    // Accept a custom override amount from the form; default to computed value
    $overrideAmount  = isset($_POST['credit_override_amount']) ? (float)$_POST['credit_override_amount'] : -1;
    $overrideReason  = trim($_POST['credit_override_reason'] ?? 'Manual correction');
    if ($overrideAmount < 0) {
        $_SESSION['error'] = 'Please enter a valid credit amount (0 or more).';
        header('Location: lease_view.php?id=' . $leaseId);
        exit;
    }
    $overrideAmount = round($overrideAmount, 2);
    try {
        $tRow = $conn->prepare("SELECT tenant_id FROM re_leases WHERE id = ? AND company_id = ?");
        $tRow->execute([$leaseId, $currentCompanyId]);
        $tRow = $tRow->fetch(PDO::FETCH_ASSOC);
        $tenantId = $tRow ? (int)$tRow['tenant_id'] : 0;
        if (!$tenantId) throw new Exception('Tenant not found.');

        $oldBalance = get_tenant_credit_balance($conn, $tenantId, $currentCompanyId);
        $conn->beginTransaction();

        // Force-set the stored balance
        $conn->prepare("
            INSERT INTO re_tenant_credit_balances (tenant_id, company_id, balance_aed)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE balance_aed = ?
        ")->execute([$tenantId, $currentCompanyId, $overrideAmount, $overrideAmount]);

        // Audit log entry
        $auditRef = 'Manual override: ' . $overrideReason
                  . ' (was ' . number_format($oldBalance, 2) . ' → set to ' . number_format($overrideAmount, 2) . ' AED)';
        try {
            $conn->prepare("
                INSERT INTO re_tenant_credit_transactions
                    (tenant_id, company_id, amount_aed, type, transaction_type, reference)
                VALUES (?, ?, 0.00, 'credit', 'manual_credit', ?)
            ")->execute([$tenantId, $currentCompanyId, $auditRef]);
        } catch (Throwable $e) {
            $conn->prepare("
                INSERT INTO re_tenant_credit_transactions
                    (tenant_id, company_id, amount_aed, type, reference)
                VALUES (?, ?, 0.00, 'credit', ?)
            ")->execute([$tenantId, $currentCompanyId, $auditRef]);
        }
        $conn->commit();
        $_SESSION['success'] = 'Credit balance corrected from ' . number_format($oldBalance, 2)
                             . ' AED to ' . number_format($overrideAmount, 2) . ' AED. Reason: ' . $overrideReason;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['error'] = 'Could not override credit balance: ' . $e->getMessage();
    }
    header('Location: lease_view.php?id=' . $leaseId);
    exit;
}

// Handle issue refund to tenant (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['issue_refund'])) {
    csrf_verify();
    $refundAmount  = isset($_POST['refund_amount']) ? (float)$_POST['refund_amount'] : 0;
    $refundDate    = trim($_POST['refund_date'] ?? date('Y-m-d'));
    $refundMethod  = trim($_POST['refund_method'] ?? 'bank_transfer');
    $refundBank    = !empty($_POST['refund_bank_account_id']) ? (int)$_POST['refund_bank_account_id'] : null;
    $refundRef     = trim($_POST['refund_reference'] ?? '');
    $refundReceipt = trim($_POST['refund_receipt'] ?? '');
    $refundReason  = trim($_POST['refund_reason'] ?? '');
    $refundNote    = trim($_POST['refund_note'] ?? '');

    if ($refundAmount > 0 && $refundDate) {
        try {
            // Load tenant_id for this lease
            $tRow = $conn->prepare("SELECT tenant_id FROM re_leases WHERE id = ? AND company_id = ?");
            $tRow->execute([$leaseId, $currentCompanyId]);
            $tRow = $tRow->fetch(PDO::FETCH_ASSOC);
            $tenantId = $tRow ? (int)$tRow['tenant_id'] : 0;
            if (!$tenantId) throw new Exception('Tenant not found for this lease.');

            // Check credit balance is sufficient
            require_once __DIR__ . '/includes/payment_allocation_helper.php';
            $creditBalance = get_tenant_credit_balance($conn, $tenantId, $currentCompanyId);
            if ($refundAmount > $creditBalance + 0.005) {
                throw new Exception("Refund amount (" . number_format($refundAmount, 2) . " AED) exceeds tenant credit balance (" . number_format($creditBalance, 2) . " AED).");
            }

            $conn->beginTransaction();

            // Debit tenant credit balance and log transaction
            $creditTxnId = update_tenant_credit(
                $conn, $tenantId, $currentCompanyId,
                $refundAmount, 'debit',
                null, null,
                "Refund issued — " . ($refundReason ?: 'Overpayment return'),
                'refund_issued'
            );

            // Insert refund record
            $conn->prepare("
                INSERT INTO re_tenant_refunds
                    (company_id, lease_id, tenant_id, refund_date, amount, payment_method,
                     bank_account_id, reference_number, receipt_number, refund_reason,
                     notes, credit_transaction_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $currentCompanyId, $leaseId, $tenantId, $refundDate, $refundAmount,
                $refundMethod, $refundBank, $refundRef, $refundReceipt,
                $refundReason, $refundNote, $creditTxnId ?: null, $userId
            ]);
            $refundId = (int)$conn->lastInsertId();

            $conn->commit();

            // Post accounting journal (non-blocking)
            try {
                require_once __DIR__ . '/accounting/accounting_integration.php';
                post_refund_to_accounting($refundId, $currentCompanyId, $userId);
            } catch (Throwable $e) {
                error_log("Refund accounting error: " . $e->getMessage());
            }

            $_SESSION['success'] = 'Refund of ' . number_format($refundAmount, 2) . ' AED recorded successfully.';
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $_SESSION['error'] = 'Could not process refund: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'Invalid refund amount or date.';
    }
    header('Location: lease_view.php?id=' . $leaseId);
    exit;
}

$unitParkingSlotSelect = re_obligation_column_exists($conn, 're_units', 'parking_slot') ? 'u.parking_slot' : 'NULL';
$unitBalconySelect = re_obligation_column_exists($conn, 're_units', 'balcony') ? 'u.balcony' : 'NULL';
$unitPremisesNumberSelect = re_obligation_column_exists($conn, 're_units', 'premises_number') ? 'u.premises_number' : 'NULL';

// Get lease details
$stmt = $conn->prepare("
    SELECT l.*, 
           u.unit_number, u.unit_type, u.area_sqm,
           {$unitParkingSlotSelect} AS parking_slot,
           {$unitBalconySelect} AS balcony,
           {$unitPremisesNumberSelect} AS premises_number,
           b.name as building_name, b.address as building_address,
           t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone, t.email, t.id_number,
           u2.username as created_by_name
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN user u2 ON u2.id = l.created_by
    WHERE l.id = ? AND l.company_id = ?
      AND " . re_lease_not_deleted_sql($conn, 'l') . "
");
$stmt->execute([$leaseId, $currentCompanyId]);
$lease = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lease) {
    header('Location: leases.php');
    exit;
}

$accountingMode = re_accounting_mode_for_existing_lease($conn, $lease);
$accountingSettings = re_accounting_mode_settings($conn);
$showAccountingModeBadge = (string)$accountingSettings['show_badge'] === '1';
$invoiceModeSummary = [
    'obligations_total' => 0,
    'obligations_open_amount' => 0.0,
    'candidates_total' => 0,
    'candidates_eligible' => 0,
    'issued_invoices' => 0,
    'open_invoice_amount' => 0.0,
    'receipts_total' => 0,
    'allocated_total' => 0.0,
    'security_deposit_open' => 0.0,
];
if ($accountingMode === 'invoice') {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(
                       CASE
                           WHEN status IN ('draft','open','partially_allocated')
                           THEN GREATEST(total_amount - COALESCE(allocated_amount, 0), 0)
                           ELSE 0
                       END
                   ), 0) AS open_amount,
                   COALESCE(SUM(
                       CASE
                           WHEN obligation_type = 'security_deposit'
                                AND status IN ('draft','open','partially_allocated')
                           THEN GREATEST(total_amount - COALESCE(allocated_amount, 0), 0)
                           ELSE 0
                       END
                   ), 0) AS deposit_open
            FROM re_obligations
            WHERE company_id = ? AND lease_id = ?
        ");
        $stmt->execute([$currentCompanyId, $leaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $invoiceModeSummary['obligations_total'] = (int)($row['cnt'] ?? 0);
        $invoiceModeSummary['obligations_open_amount'] = (float)($row['open_amount'] ?? 0);
        $invoiceModeSummary['security_deposit_open'] = (float)($row['deposit_open'] ?? 0);
    } catch (Throwable $e) {}
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(CASE WHEN status = 'approved' AND eligible_on <= CURDATE() THEN 1 ELSE 0 END), 0) AS eligible_cnt
            FROM re_invoice_candidates
            WHERE company_id = ? AND lease_id = ?
        ");
        $stmt->execute([$currentCompanyId, $leaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $invoiceModeSummary['candidates_total'] = (int)($row['cnt'] ?? 0);
        $invoiceModeSummary['candidates_eligible'] = (int)($row['eligible_cnt'] ?? 0);
    } catch (Throwable $e) {}
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(outstanding_amount), 0) AS open_amount
            FROM re_invoices
            WHERE company_id = ? AND lease_id = ? AND status <> 'cancelled'
        ");
        $stmt->execute([$currentCompanyId, $leaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $invoiceModeSummary['issued_invoices'] = (int)($row['cnt'] ?? 0);
        $invoiceModeSummary['open_invoice_amount'] = (float)($row['open_amount'] ?? 0);
    } catch (Throwable $e) {}
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT p.id) AS cnt,
                   COALESCE(SUM(ra.amount_allocated), 0) AS allocated_total
            FROM re_payments p
            LEFT JOIN re_receipt_allocations ra ON ra.payment_id = p.id
            WHERE p.company_id = ? AND p.lease_id = ? AND p.accounting_mode = 'invoice'
        ");
        $stmt->execute([$currentCompanyId, $leaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $invoiceModeSummary['receipts_total'] = (int)($row['cnt'] ?? 0);
        $invoiceModeSummary['allocated_total'] = (float)($row['allocated_total'] ?? 0);
    } catch (Throwable $e) {}
}

$securityDepositSummary = re_sd_summary($conn, (int)$currentCompanyId, (int)$leaseId);

$allowedStatusTransitions = re_lease_allowed_status_transitions($lease);
$tenantDisplayName = trim((string)($lease['company_name'] ?? ''));
if ($tenantDisplayName === '') {
    $tenantDisplayName = trim((string)($lease['first_name'] ?? '') . ' ' . (string)($lease['last_name'] ?? ''));
}
if ($tenantDisplayName === '') {
    $tenantDisplayName = !empty($lease['tenant_id']) ? 'Tenant #' . (int)$lease['tenant_id'] : '-';
}

// Load per-unit breakdown for multi-unit leases
$leaseUnits = [];
if (!empty($lease['is_multi_unit'])) {
    $stmt = $conn->prepare("
        SELECT lu.unit_id, lu.annual_rent,
               u.unit_number, u.unit_type, u.area_sqm,
               {$unitParkingSlotSelect} AS parking_slot,
               {$unitBalconySelect} AS balcony,
               {$unitPremisesNumberSelect} AS premises_number,
               b.name AS building_name
        FROM re_lease_units lu
        JOIN re_units u ON u.id = lu.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE lu.lease_id = ?
        ORDER BY lu.sort_order ASC, lu.id ASC
    ");
    $stmt->execute([$leaseId]);
    $leaseUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if move-in has been completed (based on re_move_ins table, not just move_in_date field)
$stmt = $conn->prepare("
    SELECT id, status, move_in_date 
    FROM re_move_ins 
    WHERE lease_id = ? AND status = 'completed'
    ORDER BY move_in_date DESC
    LIMIT 1
");
$stmt->execute([$leaseId]);
$moveInRecord = $stmt->fetch(PDO::FETCH_ASSOC);
$hasMoveInCompleted = !empty($moveInRecord);

// Get move-in/out operations (for display in history section)
$stmt = $conn->prepare("
    SELECT * FROM re_move_operations 
    WHERE lease_id = ? 
    ORDER BY operation_type, operation_date DESC
");
$stmt->execute([$leaseId]);
$moveOperations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get installments (including any linked cheque amounts, cheque status, and calculate outstanding)
require_once __DIR__ . '/includes/lease_installment_schedule.php';
require_once __DIR__ . '/includes/lease_schedule_engine.php';
// Derive the separate-deposit flag (read-only) from the presence of a security_deposit row.
$sdExistsStmt = $conn->prepare("SELECT COUNT(*) FROM re_lease_installments WHERE lease_id = ? AND company_id = ? AND installment_type = 'security_deposit'");
$sdExistsStmt->execute([$leaseId, $currentCompanyId]);
$lease['sep_security_deposit'] = ((int)$sdExistsStmt->fetchColumn() > 0) ? 1 : 0;
// NOTE: lease_view is a pure reader — it never reconciles/writes the schedule on GET.
// Legacy leases are fixed via the explicit "Repair schedule" action on this page.

// Payment date / receipt are resolved via re_payment_allocations (new style) first,
// falling back to li.payment_id (legacy style) so both old and new payments are shown.
$stmt = $conn->prepare("
    SELECT 
        li.*, 
        COALESCE(li.installment_type, 'rent') AS installment_type,
        p.id AS linked_payment_id,
        p.payment_date, 
        p.cleared_date,
        p.amount as payment_amount, 
        p.receipt_number,
        p.allocation_status,
        p.receipt_status,
        c.id as cheque_id,
        c.payment_id as cheque_payment_id,
        c.cheque_amount,
        c.cheque_number,
        c.reference_number,
        c.bank_name,
        c.payment_method as schedule_payment_method,
        c.notes as cheque_notes,
        c.cheque_date,
        c.status as cheque_status,
        COALESCE(SUM(p2.amount), 0) as total_paid
    FROM re_lease_installments li
    LEFT JOIN re_post_dated_cheques c 
        ON c.installment_id = li.id 
        AND c.lease_id = li.lease_id
    LEFT JOIN re_payments p ON p.id = COALESCE(
        (SELECT rpa.payment_id FROM re_payment_allocations rpa
         WHERE rpa.installment_id = li.id ORDER BY rpa.id DESC LIMIT 1),
        c.payment_id,
        li.payment_id
    )
    LEFT JOIN re_payments p2 ON p2.installment_id = li.id
    WHERE li.lease_id = ?
    GROUP BY li.id, li.lease_id, li.installment_date, li.amount, li.installment_type, li.status, li.paid_at, li.payment_id, li.notes, li.created_at,
             p.id, p.payment_date, p.cleared_date, p.amount, p.receipt_number, p.allocation_status, p.receipt_status, c.id, c.payment_id, c.cheque_amount, c.cheque_number, c.reference_number, c.bank_name, c.payment_method, c.notes, c.cheque_date, c.status
    ORDER BY li.installment_date ASC,
             CASE COALESCE(li.installment_type, 'rent')
                 WHEN 'combined_fees' THEN 0
                 WHEN 'security_deposit' THEN 1
                 WHEN 'vat' THEN 2
                 WHEN 'chiller' THEN 3
                 WHEN 'ejari' THEN 4
                 WHEN 'admin' THEN 5
                 WHEN 'commission' THEN 6
                 ELSE 10
             END ASC,
             li.id ASC
");
$stmt->execute([$leaseId]);
$installments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$chequeReceiptSummaries = [];
if ($accountingMode === 'invoice') {
    $chequeReceiptSummaries = re_lease_cheque_receipt_summaries($conn, $currentCompanyId, (int)$leaseId, true);
}

// Calculate outstanding and total collected per installment (allocation-aware: includes re_payment_allocations)
// installment.amount is the single source of truth for the due amount (engine-aligned).
$graceDays = (int)($lease['grace_period_days'] ?? 0);
foreach ($installments as &$inst) {
    $installmentAmount = (float)($inst['amount'] ?? 0);
    $totalPaid = 0.0;
    if ($accountingMode === 'invoice' && !empty($inst['cheque_id'])) {
        $chequeSummary = $chequeReceiptSummaries[(int)$inst['cheque_id']] ?? null;
        if ($chequeSummary) {
            $totalPaid = (float)($chequeSummary['collected_total'] ?? 0);
        }
    }
    if ($totalPaid <= 0.005 && $accountingMode === 'invoice' && !empty($inst['linked_payment_id'])) {
        $totalPaid = (float)($inst['payment_amount'] ?? 0);
    }
    if ($totalPaid <= 0.005 && payment_allocation_tables_exist($conn)) {
        $totalPaid = get_installment_total_paid($conn, (int)$inst['id']);
    }
    if ($totalPaid <= 0.005) {
        $totalPaid = (float)$inst['total_paid'];
    }
    $inst['total_paid_display'] = $totalPaid;
    // Canonical status computed ONCE here and reused by both the summary counters
    // and the per-row badges so they can never disagree.
    $inst['computed_status'] = lease_installment_status($inst, $graceDays);
    if (in_array($inst['computed_status'], ['cancelled', 'returned', 'waived'], true)) {
        $inst['outstanding_balance'] = 0;
    } else {
        $inst['outstanding_balance'] = max(0, $installmentAmount - $totalPaid);
    }
}
unset($inst);

$scheduleCompareTotals = lease_compute_totals($lease);
$scheduleCompareFees = (float)($scheduleCompareTotals['to_first'] ?? 0);
$scheduledPaymentTotal = round(array_sum(array_map(static function ($row) {
    if (in_array((string)($row['status'] ?? ''), ['cancelled', 'waived'], true)) {
        return 0.0;
    }
    return (float)($row['amount'] ?? 0);
}, $installments)), 2);
$rentLikeRowCount = count(lease_filter_rent_like_installment_rows(
    $installments,
    (int)($lease['number_of_installments'] ?? 12),
    $scheduleCompareFees,
    !empty($lease['add_fees_to_first_installment'])
));
$expectedRentInstallmentCount = (int)($lease['number_of_installments'] ?? 12);
$operationalScheduleHasExtraRentRow = $rentLikeRowCount > $expectedRentInstallmentCount;
$expectedScheduleBreakdown = re_payment_schedule_expected_breakdown($lease);
$serviceChargeScheduleAdjustment = 0.0;
try {
    require_once __DIR__ . '/includes/service_charge_schedule_helper.php';
    $serviceChargeScheduleAdjustment = re_service_charge_schedule_adjustment_total($conn, $currentCompanyId, (int)$leaseId);
} catch (Throwable $e) {
    $serviceChargeScheduleAdjustment = 0.0;
}
$expectedCollectionTotal = round((float)$expectedScheduleBreakdown['total_expected'] + $serviceChargeScheduleAdjustment, 2);
$operationalSummary = [
    'expected_total' => $expectedCollectionTotal,
    'scheduled_total' => $scheduledPaymentTotal,
    'difference' => round($expectedCollectionTotal - $scheduledPaymentTotal, 2),
    'service_charge_schedule_adjustment' => $serviceChargeScheduleAdjustment,
    'issued_invoices_total' => 0.0,
    'receipts_collected_total' => 0.0,
    'allocated_total' => 0.0,
    'unallocated_receipt_total' => 0.0,
    'security_deposit_expected' => (float)$expectedScheduleBreakdown['security_deposit'],
    'security_deposit_allocated' => 0.0,
];
try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM re_invoices WHERE company_id = ? AND lease_id = ? AND status <> 'cancelled'");
    $stmt->execute([$currentCompanyId, $leaseId]);
    $operationalSummary['issued_invoices_total'] = (float)$stmt->fetchColumn();
} catch (Throwable $e) {}
try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM re_payments WHERE company_id = ? AND lease_id = ?");
    $stmt->execute([$currentCompanyId, $leaseId]);
    $operationalSummary['receipts_collected_total'] = (float)$stmt->fetchColumn();
} catch (Throwable $e) {}
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount_allocated), 0)
        FROM re_receipt_allocations
        WHERE company_id = ? AND lease_id = ?
    ");
    $stmt->execute([$currentCompanyId, $leaseId]);
    $operationalSummary['allocated_total'] = (float)$stmt->fetchColumn();
} catch (Throwable $e) {
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount_allocated), 0)
            FROM re_payment_allocations
            WHERE lease_id = ?
        ");
        $stmt->execute([$leaseId]);
        $operationalSummary['allocated_total'] = (float)$stmt->fetchColumn();
    } catch (Throwable $ignored) {}
}
$operationalSummary['unallocated_receipt_total'] = max(0.0, $operationalSummary['receipts_collected_total'] - $operationalSummary['allocated_total']);
$legacyExtraServiceTotal = 0.0;
$legacyExtraServiceNotes = [];
if (!empty($lease['has_additional_parking']) || (float)($lease['additional_parking_fee'] ?? 0) > 0) {
    $parkingAnnual = round((float)($lease['additional_parking_fee'] ?? 0) * 12, 2);
    if ($parkingAnnual > 0) {
        $legacyExtraServiceTotal += $parkingAnnual;
        $legacyExtraServiceNotes[] = 'Parking ' . number_format($parkingAnnual, 2) . ' AED/year';
    }
}
if (!empty($lease['has_additional_store']) || (float)($lease['additional_store_fee'] ?? 0) > 0) {
    $storeAnnual = round((float)($lease['additional_store_fee'] ?? 0) * 12, 2);
    if ($storeAnnual > 0) {
        $legacyExtraServiceTotal += $storeAnnual;
        $legacyExtraServiceNotes[] = 'Store room ' . number_format($storeAnnual, 2) . ' AED/year';
    }
}
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(ra.amount_allocated), 0)
        FROM re_receipt_allocations ra
        JOIN re_obligations o ON o.id = ra.obligation_id
        WHERE ra.company_id = ? AND ra.lease_id = ? AND o.obligation_type = 'security_deposit'
    ");
    $stmt->execute([$currentCompanyId, $leaseId]);
    $operationalSummary['security_deposit_allocated'] = (float)$stmt->fetchColumn();
} catch (Throwable $e) {}

$leaseDistribution = lease_engine_dist_from_row($lease);
$leaseExtraLabels = [
    'security_deposit' => 'Security Deposit',
    'chiller' => 'Chiller Fees',
    'ejari' => 'Ejari Fees',
    'admin' => 'Admin Fees',
    'commission' => 'Commission Fees',
    'amc' => 'AMC Fee',
    'parking' => 'Parking Fee',
    'store' => 'Store Fee',
    'vat' => 'VAT',
];
$firstPaymentExtras = [];
if (empty($lease['is_renewal_lease']) && $leaseDistribution['security'] !== 'separate' && (float)($lease['security_deposit'] ?? 0) > 0) {
    $firstPaymentExtras[] = ['label' => $leaseExtraLabels['security_deposit'], 'amount' => (float)$lease['security_deposit']];
}
foreach ([
    'chiller' => 'chiller_fees',
    'ejari' => 'ejari_fees',
    'admin' => 'admin_fees',
    'commission' => 'commission_fees',
    'amc' => 'amc_amount',
] as $distKey => $amountKey) {
    if (($leaseDistribution[$distKey] ?? '') === 'first' && (float)($lease[$amountKey] ?? 0) > 0) {
        $firstPaymentExtras[] = ['label' => $leaseExtraLabels[$distKey], 'amount' => (float)$lease[$amountKey]];
    }
}
if (($leaseDistribution['parking'] ?? '') === 'first'
    && !empty($lease['has_additional_parking'])
    && (float)($lease['additional_parking_fee'] ?? 0) > 0
    && lease_engine_date_in_window($lease['start_date'] ?? null, $lease['additional_parking_start_date'] ?? null, $lease['additional_parking_end_date'] ?? null)) {
    $firstPaymentExtras[] = ['label' => $leaseExtraLabels['parking'], 'amount' => (float)$lease['additional_parking_fee']];
}
if (($leaseDistribution['store'] ?? '') === 'first'
    && !empty($lease['has_additional_store'])
    && (float)($lease['additional_store_fee'] ?? 0) > 0
    && lease_engine_date_in_window($lease['start_date'] ?? null, $lease['additional_store_start_date'] ?? null, $lease['additional_store_end_date'] ?? null)) {
    $firstPaymentExtras[] = ['label' => $leaseExtraLabels['store'], 'amount' => (float)$lease['additional_store_fee']];
}
if (($lease['vat_distribution_type'] ?? '') === 'first_installment' && (float)($lease['total_vat_amount'] ?? 0) > 0) {
    $firstPaymentExtras[] = ['label' => $leaseExtraLabels['vat'], 'amount' => (float)$lease['total_vat_amount']];
}
$splitPaymentExtras = [];
$installmentCountForSplit = max(1, (int)($lease['number_of_installments'] ?? 12));
foreach ([
    'chiller' => 'chiller_fees',
    'ejari' => 'ejari_fees',
    'admin' => 'admin_fees',
    'commission' => 'commission_fees',
    'amc' => 'amc_amount',
] as $distKey => $amountKey) {
    if (($leaseDistribution[$distKey] ?? '') === 'split' && (float)($lease[$amountKey] ?? 0) > 0) {
        $splitPaymentExtras[] = ['label' => $leaseExtraLabels[$distKey] . ' split', 'amount' => round((float)$lease[$amountKey] / $installmentCountForSplit, 2)];
    }
}
if (($lease['vat_distribution_type'] ?? '') === 'split_installments' && (float)($lease['total_vat_amount'] ?? 0) > 0) {
    $splitPaymentExtras[] = ['label' => $leaseExtraLabels['vat'] . ' split', 'amount' => round((float)$lease['total_vat_amount'] / $installmentCountForSplit, 2)];
}
if (($leaseDistribution['parking'] ?? '') === 'split' && !empty($lease['has_additional_parking']) && (float)($lease['additional_parking_fee'] ?? 0) > 0) {
    $splitPaymentExtras[] = ['label' => $leaseExtraLabels['parking'] . ' split', 'amount' => round(((float)$lease['additional_parking_fee'] * 12) / $installmentCountForSplit, 2)];
}
if (($leaseDistribution['store'] ?? '') === 'split' && !empty($lease['has_additional_store']) && (float)($lease['additional_store_fee'] ?? 0) > 0) {
    $splitPaymentExtras[] = ['label' => $leaseExtraLabels['store'] . ' split', 'amount' => round(((float)$lease['additional_store_fee'] * 12) / $installmentCountForSplit, 2)];
}
// Total fees routed to the 1st installment / combined-fees cheque — from the ONE engine formula.
$isRenewalLease = !empty($lease['is_renewal_lease']);
$leaseTotals    = lease_compute_totals($lease);
$totalFees      = (float)$leaseTotals['to_first'];

// Identify Fees Installment (if checkbox was unchecked and fees exist)
$feesInstallmentId = null;
if (!empty($installments) && !($lease['add_fees_to_first_installment'] ?? 1) && $totalFees > 0) {
    foreach ($installments as $inst) {
        if (($inst['installment_type'] ?? '') === 'combined_fees') {
            $instAmount = isset($inst['cheque_amount']) && $inst['cheque_amount'] !== null
                ? (float)$inst['cheque_amount']
                : (float)$inst['amount'];
            if ($totalFees <= 0 || abs($instAmount - $totalFees) < 0.02) {
                $feesInstallmentId = $inst['id'];
                break;
            }
        }
    }
    if ($feesInstallmentId === null) {
        // Legacy fallback: amount match on the earliest schedule date.
        $firstInstallmentDate = $installments[0]['installment_date'];
        foreach ($installments as $inst) {
            if ($inst['installment_date'] === $firstInstallmentDate) {
                $instAmount = isset($inst['cheque_amount']) && $inst['cheque_amount'] !== null
                    ? (float)$inst['cheque_amount']
                    : (float)$inst['amount'];
                if (abs($instAmount - $totalFees) < 0.01) {
                    $feesInstallmentId = $inst['id'];
                    break;
                }
            } else {
                break;
            }
        }
    }
}

$firstRentInstallmentId = null;
foreach ($installments as $scheduleRow) {
    if ($feesInstallmentId !== null && (int)$scheduleRow['id'] === (int)$feesInstallmentId) {
        continue;
    }
    $scheduleType = (string)($scheduleRow['installment_type'] ?? '');
    if ($scheduleType === '' || $scheduleType === 'rent') {
        $firstRentInstallmentId = (int)$scheduleRow['id'];
        break;
    }
}

// NOTE: Penalty (Late Fee / Bounced Fee) billing items are NO LONGER created on
// this GET request. Viewing a lease is read-only. They are generated by:
//   - the explicit "Repair schedule" action on this page, and
//   - the cron job cron_lease_penalties.php
// via re_generate_lease_penalties() in billing_helper.php.

// Load penalty billing items (Late Fee / Bounced Fee) for this lease
$penaltyItems = $conn->prepare("
    SELECT bi.*, pr.penalty_type,
           li.installment_date, li.amount AS installment_amount,
           pdc.cheque_number
    FROM re_billing_items bi
    LEFT JOIN re_penalty_rules pr ON pr.id = bi.penalty_rule_id
    LEFT JOIN re_lease_installments li ON li.id = bi.installment_id
    LEFT JOIN re_post_dated_cheques pdc ON pdc.installment_id = bi.installment_id
    WHERE bi.lease_id = ? AND bi.company_id = ? AND bi.item_type = 'penalty'
    ORDER BY bi.due_date ASC, bi.created_at ASC
");
$penaltyItems->execute([$leaseId, $currentCompanyId]);
$penaltyItems = $penaltyItems->fetchAll(PDO::FETCH_ASSOC);

// Load service charge fee schedule for this lease
$serviceChargeSchedule = [];
$serviceChargesById = [];
$servicePaymentPlans = [];
try {
    require_once __DIR__ . '/includes/service_charge_payment_plan_helper.php';
    $serviceStmt = $conn->prepare("
        SELECT bi.*, sc.charge_name AS service_name, sc.is_active AS service_active,
               sc.is_recurring, sc.recurrence_type,
               sc.lifecycle_status, sc.contracted_amount, sc.vat_treatment, sc.vat_rate
        FROM re_billing_items bi
        LEFT JOIN re_service_charges sc ON sc.id = bi.service_charge_id
        WHERE bi.lease_id = ?
          AND bi.company_id = ?
          AND bi.item_type = 'service_charge'
        ORDER BY bi.due_date ASC, bi.id ASC
    ");
    $serviceStmt->execute([$leaseId, $currentCompanyId]);
    $serviceChargeSchedule = $serviceStmt->fetchAll(PDO::FETCH_ASSOC);

    // Invoice Mode: Collected comes from obligation allocated_amount (billing_item source),
    // not Legacy re_billing_item_payment_allocations (IM receipt allocation never writes those).
    $imBillingAllocated = [];
    if ($accountingMode === 'invoice' && !empty($serviceChargeSchedule)) {
        $imBillingAllocated = re_billing_item_obligation_allocated_map(
            $conn,
            $currentCompanyId,
            (int)$leaseId,
            array_map(static fn(array $r): int => (int)$r['id'], $serviceChargeSchedule)
        );
    }

    foreach ($serviceChargeSchedule as &$svcRow) {
        $paid = 0.0;
        if ($accountingMode === 'invoice') {
            $paid = (float)($imBillingAllocated[(int)$svcRow['id']] ?? 0);
        }
        if ($paid <= 0.005) {
            $paid = get_billing_item_total_paid($conn, (int)$svcRow['id']);
        }
        $svcRow['paid_amount_display'] = $paid;
        $svcRow['outstanding_amount'] = (($svcRow['status'] ?? '') === 'waived')
            ? 0.0
            : max(0, (float)$svcRow['total_amount'] - $paid);
        if (!empty($svcRow['service_charge_id'])) {
            $sid = (int)$svcRow['service_charge_id'];
            if (!isset($serviceChargesById[$sid])) {
                $serviceChargesById[$sid] = [
                    'id' => $sid,
                    'name' => $svcRow['service_name'] ?: $svcRow['item_name'],
                    'is_active' => (int)($svcRow['service_active'] ?? 0),
                    'is_recurring' => (int)($svcRow['is_recurring'] ?? 0),
                    'recurrence_type' => $svcRow['recurrence_type'] ?? '',
                    'lifecycle_status' => $svcRow['lifecycle_status'] ?? ((int)($svcRow['service_active'] ?? 0) ? 'active' : 'cancelled'),
                    'contracted_amount' => $svcRow['contracted_amount'] ?? null,
                    'vat_treatment' => $svcRow['vat_treatment'] ?? null,
                    'vat_rate' => $svcRow['vat_rate'] ?? null,
                    'total' => 0.0,
                    'paid' => 0.0,
                    'outstanding' => 0.0,
                ];
            }
            if (($svcRow['status'] ?? '') !== 'waived') {
                $serviceChargesById[$sid]['total'] += (float)$svcRow['total_amount'];
                $serviceChargesById[$sid]['paid'] += $paid;
                $serviceChargesById[$sid]['outstanding'] += (float)$svcRow['outstanding_amount'];
            }
        }
    }
    unset($svcRow);

    if (function_exists('re_sc_payment_plan_table_ready') && re_sc_payment_plan_table_ready($conn)) {
        foreach (array_keys($serviceChargesById) as $sid) {
            $plan = re_sc_load_active_payment_plan($conn, (int)$currentCompanyId, (int)$sid);
            if ($plan) {
                $servicePaymentPlans[(int)$sid] = $plan;
            }
        }
    }
} catch (Throwable $e) {
    $serviceChargeSchedule = [];
    $serviceChargesById = [];
    $servicePaymentPlans = [];
}

// ── Tenant Credit & Refunds ───────────────────────────────────────────────────
$leaseTenantId    = (int)($lease['tenant_id'] ?? 0);
$tenantCreditBal  = 0.0;
$creditHistory    = [];
$refundHistory    = [];

try {
    require_once __DIR__ . '/includes/payment_allocation_helper.php';
    $tenantCreditBal = get_tenant_credit_balance($conn, $leaseTenantId, $currentCompanyId);

    // Credit transactions linked to this lease's payments
    $creditHistory = $conn->prepare("
        SELECT ct.id, ct.amount_aed, ct.type,
               COALESCE(ct.transaction_type,
                   CASE WHEN ct.type='credit' THEN 'overpayment' ELSE 'applied_installment' END
               ) AS transaction_type,
               ct.reference, ct.created_at,
               p.receipt_number, p.payment_date
        FROM re_tenant_credit_transactions ct
        LEFT JOIN re_payments p ON p.id = ct.payment_id
        WHERE ct.tenant_id = ? AND ct.company_id = ?
          AND (p.lease_id = ? OR ct.payment_id IS NULL)
        ORDER BY ct.created_at DESC
        LIMIT 50
    ")->execute([$leaseTenantId, $currentCompanyId, $leaseId])
      ? null : null; // will fix below
    // Use subquery-based filter (avoids LEFT JOIN collision with WHERE on p.lease_id)
    $creditStmt = $conn->prepare("
        SELECT ct.id, ct.amount_aed, ct.type,
               COALESCE(ct.transaction_type,
                   CASE WHEN ct.type='credit' THEN 'overpayment' ELSE 'applied_installment' END
               ) AS transaction_type,
               ct.reference, ct.created_at, ct.payment_id,
               p.receipt_number, p.payment_date,
               p.amount            AS payment_total,
               p.payment_method    AS payment_method,
               COALESCE(
                   (SELECT SUM(ra.amount_allocated)
                    FROM re_receipt_allocations ra
                    WHERE ra.payment_id = ct.payment_id
                      AND ra.target_type IN ('invoice', 'obligation')),
                   (SELECT SUM(pa.amount_allocated)
                    FROM re_payment_allocations pa
                    WHERE pa.payment_id = ct.payment_id),
                   0
               )                   AS allocated_total
        FROM re_tenant_credit_transactions ct
        LEFT JOIN re_payments p ON p.id = ct.payment_id
        WHERE ct.tenant_id = ? AND ct.company_id = ?
          AND (
               ct.payment_id IN (SELECT id FROM re_payments WHERE lease_id = ? AND company_id = ?)
            OR ct.installment_id IN (SELECT id FROM re_lease_installments WHERE lease_id = ?)
            OR (ct.transaction_type = 'refund_issued' AND ct.payment_id IS NULL)
          )
        ORDER BY ct.created_at DESC
        LIMIT 100
    ");
    $creditStmt->execute([$leaseTenantId, $currentCompanyId, $leaseId, $currentCompanyId, $leaseId]);
    $creditHistory = $creditStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* pre-migration fallback */ }

try {
    $refStmt = $conn->prepare("
        SELECT r.*, ba.account_name AS bank_name
        FROM re_tenant_refunds r
        LEFT JOIN re_bank_accounts ba ON ba.id = r.bank_account_id
        WHERE r.lease_id = ? AND r.company_id = ?
        ORDER BY r.refund_date DESC
    ");
    $refStmt->execute([$leaseId, $currentCompanyId]);
    $refundHistory = $refStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* table may not exist yet */ }

// ── Credit Audit: compute true credit from actual payments vs allocations ───
// Invoice Mode posts to re_receipt_allocations (invoice/obligation/tenant_credit).
// Legacy uses re_payment_allocations. Never treat a missing allocation row as
// "fully allocated" — that falsely zeroes computed credit for IM receipts.
$auditTotalPayments  = 0.0;
$auditTotalAllocated = 0.0;
$auditTotalRefunded  = 0.0;
$auditTrueCredit     = 0.0;
$auditPaymentRows    = [];
try {
    if ($accountingMode === 'invoice') {
        $auditStmt = $conn->prepare("
            SELECT p.id, p.receipt_number, p.payment_date, p.amount,
                   p.payment_method, p.reference_number,
                   COALESCE(
                       (SELECT SUM(ra.amount_allocated)
                        FROM re_receipt_allocations ra
                        WHERE ra.payment_id = p.id
                          AND ra.target_type IN ('invoice', 'obligation')),
                       0
                   ) AS allocated,
                   COALESCE(
                       (SELECT SUM(ra.amount_allocated)
                        FROM re_receipt_allocations ra
                        WHERE ra.payment_id = p.id
                          AND ra.target_type = 'tenant_credit'),
                       GREATEST(
                           p.amount - COALESCE(
                               (SELECT SUM(ra.amount_allocated)
                                FROM re_receipt_allocations ra
                                WHERE ra.payment_id = p.id
                                  AND ra.target_type IN ('invoice', 'obligation')),
                               0
                           ),
                           0
                       )
                   ) AS excess
            FROM re_payments p
            WHERE p.lease_id = ? AND p.company_id = ?
            ORDER BY p.payment_date ASC, p.id ASC
        ");
    } else {
        $auditStmt = $conn->prepare("
            SELECT p.id, p.receipt_number, p.payment_date, p.amount,
                   p.payment_method, p.reference_number,
                   COALESCE(
                       (SELECT SUM(pa.amount_allocated)
                        FROM re_payment_allocations pa WHERE pa.payment_id = p.id),
                       p.amount
                   ) AS allocated,
                   p.amount - COALESCE(
                       (SELECT SUM(pa.amount_allocated)
                        FROM re_payment_allocations pa WHERE pa.payment_id = p.id),
                       p.amount
                   ) AS excess
            FROM re_payments p
            WHERE p.lease_id = ? AND p.company_id = ?
            ORDER BY p.payment_date ASC, p.id ASC
        ");
    }
    $auditStmt->execute([$leaseId, $currentCompanyId]);
    $auditPaymentRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($auditPaymentRows as $ar) {
        $auditTotalPayments  += (float)$ar['amount'];
        $auditTotalAllocated += (float)$ar['allocated'];
    }
    try {
        $rfS = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM re_tenant_refunds WHERE lease_id=? AND company_id=?");
        $rfS->execute([$leaseId, $currentCompanyId]);
        $auditTotalRefunded = (float)$rfS->fetchColumn();
    } catch (Throwable $e) {}
    $auditTrueCredit = max(0, round($auditTotalPayments - $auditTotalAllocated - $auditTotalRefunded, 2));
} catch (Throwable $e) {}

// Load bank accounts for refund modal
$bankAccountsForRefund = [];
try {
    $bStmt = $conn->prepare("
        SELECT ba.id, ba.account_name, ba.bank_name, coa.account_code
        FROM re_bank_accounts ba
        JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
        WHERE ba.company_id = ? AND ba.is_active = 1
        ORDER BY coa.account_code
    ");
    $bStmt->execute([$currentCompanyId]);
    $bankAccountsForRefund = $bStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { }

// Build map: installment_id => [payment_id, ...] for "View Payment" / "View payments (N)" links
$paymentsPerInstallment = [];
if (!empty($installments)) {
    $ids = array_column($installments, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = [];
    $stmt = $conn->prepare("SELECT installment_id, id as payment_id FROM re_payments WHERE installment_id IN ($placeholders) AND company_id = ?");
    $stmt->execute(array_merge($ids, [$currentCompanyId]));
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [(int)$r['installment_id'], (int)$r['payment_id']];
    }
    if (payment_allocation_tables_exist($conn)) {
        $stmt = $conn->prepare("SELECT installment_id, payment_id FROM re_payment_allocations WHERE installment_id IN ($placeholders)");
        $stmt->execute($ids);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [(int)$r['installment_id'], (int)$r['payment_id']];
        }
    }
    foreach ($rows as list($iid, $pid)) {
        $paymentsPerInstallment[$iid][$pid] = true;
    }
    foreach ($paymentsPerInstallment as $iid => $pids) {
        $paymentsPerInstallment[$iid] = array_keys($pids);
    }
}

// Calculate totals — driven by the SAME canonical status used for the row badges.
$collectibleInstallments = array_values(array_filter($installments, fn($i) => !in_array(($i['computed_status'] ?? ''), ['cancelled', 'waived', 'returned'], true)));
$totalInstallments = count($collectibleInstallments);
$paidInstallments = count(array_filter($installments, fn($i) => ($i['computed_status'] ?? '') === 'paid'));
$pendingInstallments = count(array_filter($collectibleInstallments, fn($i) => ($i['computed_status'] ?? '') === 'pending'));
$overdueInstallments = count(array_filter($collectibleInstallments, fn($i) => ($i['computed_status'] ?? '') === 'overdue'));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Lease Details';

$companyBankAccounts = [];
try {
    $bankStmt = $conn->prepare("
        SELECT ba.id, ba.account_name, ba.bank_name, coa.account_code
        FROM re_bank_accounts ba
        JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id AND coa.company_id = ba.company_id
        WHERE ba.company_id = ? AND ba.is_active = 1
        ORDER BY coa.account_code, ba.account_name
    ");
    $bankStmt->execute([(int)$currentCompanyId]);
    $companyBankAccounts = $bankStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $companyBankAccounts = [];
}

require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">Lease <?= h($lease['lease_number'] ?: 'L-' . $lease['id']) ?></div>
            <div class="btn-group">
                <a href="lease_add.php?id=<?= $leaseId ?>" class="btn btn-outline-primary">
                    <i class="bi bi-pencil"></i> Edit Lease
                </a>
                <?php if (has_role('Owner', $conn) || has_role('Admin', $conn)): ?>
                    <a href="billing_service_charges.php?lease_id=<?= $leaseId ?>&apply=1" class="btn btn-outline-info">
                        <i class="bi bi-plus-circle"></i> Add Extra Service
                    </a>
                <?php endif; ?>
                <?php if (($lease['status'] ?? '') !== 'terminated' && (has_role('Owner', $conn) || has_role('Admin', $conn))): ?>
                    <a href="lease_terminate.php?lease_id=<?= $leaseId ?>" class="btn btn-outline-danger">
                        <i class="bi bi-x-octagon"></i> Terminate Lease
                    </a>
                <?php endif; ?>
                <?php if (has_role('Owner', $conn)): ?>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteLeaseModal">
                        <i class="bi bi-trash3"></i> Delete Lease
                    </button>
                <?php endif; ?>
                <?php if (!$hasMoveInCompleted): ?>
                    <a href="move_in_add.php?lease_id=<?= $leaseId ?>" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right"></i> Move-In
                    </a>
                <?php else: ?>
                    <a href="move_out_add.php?lease_id=<?= $leaseId ?>" class="btn btn-warning">
                        <i class="bi bi-box-arrow-right"></i> Move-Out
                    </a>
                <?php endif; ?>
                <?php if ($accountingMode === 'invoice'): ?>
                    <a href="accounting/receipt_multi_cheque.php?lease_id=<?= $leaseId ?>" class="btn btn-success">
                        <i class="bi bi-link-45deg"></i> Multi-Cheque Receipt
                    </a>
                <?php else: ?>
                    <a href="payment_add.php?lease_id=<?= $leaseId ?>" class="btn btn-success">
                        <i class="bi bi-cash-coin"></i> Record Payment
                    </a>
                <?php endif; ?>
                <a href="lease_payments_manage.php?lease_id=<?= $leaseId ?>" class="btn btn-outline-primary">
                    <i class="bi bi-cash-stack"></i> Manage Payments
                </a>
                <a href="payments.php?lease_search=<?= urlencode($lease['lease_number'] ?: '') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul"></i> All Payments
                </a>
                <a href="lease_contract_generate.php?lease_id=<?= $leaseId ?>" class="btn btn-primary">
                    <i class="bi bi-file-pdf"></i> Generate Contract PDF
                </a>
                <?php if ($lease['generated_contract_path'] && file_exists(__DIR__ . '/../../' . $lease['generated_contract_path']) && !empty($lease['email'])): ?>
                    <button type="button" class="btn btn-info" onclick="sendContractEmail(<?= $leaseId ?>)">
                        <i class="bi bi-envelope"></i> Send Contract by Email
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show"><?= h($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?= h($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if ($showAccountingModeBadge): ?>
            <div class="alert <?= $accountingMode === 'invoice' ? 'alert-info' : 'alert-warning' ?> mb-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                    <div>
                        <div class="fw-bold">
                            Accounting Mode:
                            <span class="badge bg-<?= $accountingMode === 'invoice' ? 'primary' : 'secondary' ?>">
                                <?= $accountingMode === 'invoice' ? 'Invoice Mode' : 'Legacy Mode' ?>
                            </span>
                        </div>
                        <div class="small mt-1">
                            <?php if ($accountingMode === 'invoice'): ?>
                                This lease uses the new obligation, invoice, receipt, and allocation accounting engine. Installments and cheques are operational schedules only.
                            <?php else: ?>
                                This lease uses the old legacy accounting flow. It is preserved for historical safety.
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($accountingMode === 'invoice'): ?>
                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            <a class="btn btn-sm btn-outline-primary" href="accounting/obligation_preview.php?lease_id=<?= $leaseId ?>">Obligations</a>
                            <a class="btn btn-sm btn-outline-primary" href="accounting/invoice_preview.php?lease_id=<?= $leaseId ?>">Invoice Candidates</a>
                            <a class="btn btn-sm btn-outline-primary" href="accounting/receipt_allocation.php?lease_id=<?= $leaseId ?>">Receipts & Allocations</a>
                            <a class="btn btn-sm btn-outline-secondary" href="accounting/revenue_recognition.php?lease_id=<?= $leaseId ?>">Recognition Diagnostics</a>
                            <a class="btn btn-sm btn-outline-secondary" href="accounting/security_deposit_diagnostics.php?lease_id=<?= (int)$leaseId ?>">Deposit Diagnostics</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($accountingMode === 'invoice'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card h-100 border-primary">
                        <div class="card-body">
                            <div class="small text-muted">Obligations</div>
                            <div class="h5 mb-0"><?= (int)$invoiceModeSummary['obligations_total'] ?></div>
                            <div class="small">Open: <?= number_format((float)$invoiceModeSummary['obligations_open_amount'], 2) ?> AED</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 border-info">
                        <div class="card-body">
                            <div class="small text-muted">Invoice Candidates</div>
                            <div class="h5 mb-0"><?= (int)$invoiceModeSummary['candidates_total'] ?></div>
                            <div class="small">Eligible today: <?= (int)$invoiceModeSummary['candidates_eligible'] ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 border-success">
                        <div class="card-body">
                            <div class="small text-muted">Issued Invoices</div>
                            <div class="h5 mb-0"><?= (int)$invoiceModeSummary['issued_invoices'] ?></div>
                            <div class="small">Open invoice balance: <?= number_format((float)$invoiceModeSummary['open_invoice_amount'], 2) ?> AED</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 border-secondary">
                        <div class="card-body">
                            <div class="small text-muted">Receipts / Deposit</div>
                            <div class="h5 mb-0"><?= (int)$invoiceModeSummary['receipts_total'] ?></div>
                            <div class="small">Security deposit open: <?= number_format((float)$invoiceModeSummary['security_deposit_open'], 2) ?> AED</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> Operational Collection Comparison</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-light border small">
                    For Invoice Mode, official accounting balance comes from invoices, receipts, allocations, and GL. This operational schedule is not the official accounting balance.
                    <?php if (abs((float)$operationalSummary['difference']) > 0.02): ?>
                        <br><strong>Difference note:</strong> this means the operational cheque/payment schedule total does not match the lease terms. A financial reset does not recreate the schedule; use Edit Pending Row / Repair schedule if the operational schedule should be corrected.
                        <?php if (!empty($operationalScheduleHasExtraRentRow)): ?>
                            <br><strong>Detected issue:</strong> this lease has more rent cheque rows than configured (<?= (int)$rentLikeRowCount ?> vs <?= (int)$expectedRentInstallmentCount ?>). This often happens when the fees installment was counted as rent. Open <a href="lease_add.php?id=<?= (int)$leaseId ?>">Edit Lease</a> and click <strong>Save</strong> to repair the schedule (paid rows are preserved).
                        <?php endif; ?>
                    <?php elseif ((float)($operationalSummary['service_charge_schedule_adjustment'] ?? 0) > 0.02): ?>
                        <br><strong>Extra services note:</strong> <?= number_format((float)$operationalSummary['service_charge_schedule_adjustment'], 2) ?> AED from applied service charges merged into cheques is included in both Expected and Scheduled totals above.
                    <?php endif; ?>
                    <?php if ($legacyExtraServiceTotal > 0): ?>
                        <br><strong>Extra services note:</strong> this lease still has old extra-service values (<?= h(implode(', ', $legacyExtraServiceNotes)) ?>). In the new flow, these should be recreated from <a href="billing_service_charges.php?lease_id=<?= (int)$leaseId ?>">Service Charges Management</a>, not kept inside the lease.
                    <?php endif; ?>
                </div>
                <div class="row g-3 text-center">
                    <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Expected Lease Collection</div><strong><?= number_format($operationalSummary['expected_total'], 2) ?></strong> AED</div></div>
                    <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Scheduled Payment Total</div><strong><?= number_format($operationalSummary['scheduled_total'], 2) ?></strong> AED</div></div>
                    <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Difference</div><strong class="<?= abs($operationalSummary['difference']) > 0.02 ? 'text-danger' : 'text-success' ?>"><?= number_format($operationalSummary['difference'], 2) ?></strong> AED</div></div>
                    <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Issued Invoices Total</div><strong><?= number_format($operationalSummary['issued_invoices_total'], 2) ?></strong> AED</div></div>
                    <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Receipts Collected</div><strong><?= number_format($operationalSummary['receipts_collected_total'], 2) ?></strong> AED</div></div>
                    <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Allocated Total</div><strong><?= number_format($operationalSummary['allocated_total'], 2) ?></strong> AED</div></div>
                    <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Unallocated Receipts</div><strong><?= number_format($operationalSummary['unallocated_receipt_total'], 2) ?></strong> AED</div></div>
                    <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Security Deposit Expected / Allocated</div><strong><?= number_format($operationalSummary['security_deposit_expected'], 2) ?> / <?= number_format($operationalSummary['security_deposit_allocated'], 2) ?></strong> AED</div></div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Lease Information -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Lease Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Lease Number:</th>
                                <td><?= h($lease['lease_number'] ?: 'L-' . $lease['id']) ?></td>
                            </tr>
                            <tr>
                                <th>Ejari Contract Number:</th>
                                <td><?= h($lease['ejari_registration_number'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>Unit<?= !empty($lease['is_multi_unit']) ? 's' : '' ?>:</th>
                                <td>
                                <?php if (!empty($lease['is_multi_unit']) && !empty($leaseUnits)): ?>
                                    <span class="badge bg-info text-dark me-1">Multi-Unit</span>
                                    <table class="table table-sm table-bordered mb-0 mt-1" style="font-size:.85rem">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Unit</th>
                                                <th>Premises #</th>
                                                <th>Type</th>
                                                <th>Parking Slot</th>
                                                <th>Balcony</th>
                                                <th class="text-end">Annual Rent</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($leaseUnits as $lu): ?>
                                            <tr>
                                                <td><?= h($lu['building_name'] . ' - ' . $lu['unit_number']) ?></td>
                                                <td><?= h($lu['premises_number'] ?: '-') ?></td>
                                                <td><?= h($lu['unit_type']) ?></td>
                                                <td><?= h($lu['parking_slot'] ?: '-') ?></td>
                                                <td><?= !empty($lu['balcony']) ? 'Yes' : 'No' ?></td>
                                                <td class="text-end"><?= number_format($lu['annual_rent'], 2) ?> AED</td>
                                            </tr>
                                        <?php endforeach; ?>
                                            <tr class="fw-bold table-warning">
                                                <td colspan="5">Combined Total</td>
                                                <td class="text-end"><?= number_format(array_sum(array_column($leaseUnits,'annual_rent')), 2) ?> AED</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <?= h($lease['building_name'] . ' - ' . $lease['unit_number']) ?>
                                <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (empty($lease['is_multi_unit'])): ?>
                            <tr>
                                <th>Unit Type:</th>
                                <td><?= h($lease['unit_type'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>Premises Number:</th>
                                <td><?= h($lease['premises_number'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>Parking Slot:</th>
                                <td><?= h($lease['parking_slot'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>Balcony:</th>
                                <td><?= !empty($lease['balcony']) ? 'Yes' : 'No' ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Lease Period:</th>
                                <td>
                                    <strong>Start:</strong> <?= date('Y-m-d', strtotime($lease['start_date'])) ?>
                                    <span class="text-muted mx-2">|</span>
                                    <strong>End:</strong> <?= date('Y-m-d', strtotime($lease['end_date'])) ?>
                                </td>
                            </tr>
                            <?php if (!empty($lease['termination_date'])): ?>
                            <tr>
                                <th>Termination Date:</th>
                                <td>
                                    <strong class="text-danger"><?= date('Y-m-d', strtotime($lease['termination_date'])) ?></strong>
                                    <?php if (!empty($lease['terminated_at'])): ?>
                                        <br><small class="text-muted">Recorded in system on <?= h(date('Y-m-d', strtotime($lease['terminated_at']))) ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($lease['termination_reason'])): ?>
                            <tr>
                                <th>Termination Reason:</th>
                                <td><?= nl2br(h($lease['termination_reason'])) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Annual Rent:</th>
                                <td><strong><?= number_format($lease['annual_rent'] ?? ($lease['monthly_rent'] * 12), 2) ?> AED</strong>
                                <?php if (!empty($lease['is_multi_unit']) && !empty($leaseUnits)): ?>
                                    <small class="text-muted">(combined total for <?= count($leaseUnits) ?> units)</small>
                                <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Security Deposit:</th>
                                <td>
                                    <?php if (!empty($lease['is_renewal_lease'])): ?>
                                        <span class="text-muted">Not collected again on renewal</span>
                                        <?php if ((float)($lease['security_deposit'] ?? 0) > 0): ?>
                                            <br><small class="text-muted">Original deposit reference: <?= number_format((float)$lease['security_deposit'], 2) ?> AED</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= number_format($lease['security_deposit'], 2) ?> AED
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($lease['chiller_fees']) && $lease['chiller_fees'] > 0): ?>
                            <tr>
                                <th>Chiller Fees:</th>
                                <td><?= number_format($lease['chiller_fees'], 2) ?> AED</td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($lease['ejari_fees']) && $lease['ejari_fees'] > 0): ?>
                            <tr>
                                <th>Ejari Fees:</th>
                                <td><?= number_format($lease['ejari_fees'], 2) ?> AED</td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($lease['admin_fees']) && $lease['admin_fees'] > 0): ?>
                            <tr>
                                <th>Admin Fees:</th>
                                <td><?= number_format($lease['admin_fees'], 2) ?> AED</td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($lease['commission_fees']) && $lease['commission_fees'] > 0): ?>
                            <tr>
                                <th>Commission Fees:</th>
                                <td><?= number_format($lease['commission_fees'], 2) ?> AED</td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Payment Method:</th>
                                <td><?= ucfirst(str_replace('_', ' ', $lease['payment_method'])) ?></td>
                            </tr>
                            <?php if (!empty($lease['grace_period_days'])): ?>
                            <tr>
                                <th>Grace Period:</th>
                                <td><?= $lease['grace_period_days'] ?> days</td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <?php
                                    $statusClass = [
                                        'draft' => 'secondary',
                                        'active' => 'success',
                                        'expired' => 'warning',
                                        'terminated' => 'danger',
                                        'renewed' => 'info',
                                        'has_legal_case' => 'dark',
                                    ];
                                    $class = $statusClass[$lease['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $class ?> me-2"><?= h(re_lease_status_label((string)$lease['status'])) ?></span>
                                    <div class="btn-group btn-group-sm mt-1">
                                        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                            Change Status
                                        </button>
                                        <ul class="dropdown-menu">
                                            <?php if (empty($allowedStatusTransitions)): ?>
                                                <li><span class="dropdown-item-text text-muted small">No direct status changes available</span></li>
                                            <?php else: ?>
                                                <?php foreach ($allowedStatusTransitions as $allowedStatus): ?>
                                                    <li>
                                                        <a class="dropdown-item<?= $allowedStatus === 'has_legal_case' ? ' text-dark' : '' ?>" href="#"
                                                           onclick="changeLeaseStatus(<?= $leaseId ?>, '<?= h($allowedStatus) ?>'); return false;">
                                                            <?= h(re_lease_status_label($allowedStatus)) ?>
                                                            <?php if ($allowedStatus === 'has_legal_case'): ?>
                                                                <small class="d-block text-muted">Frees unit for a new lease booking</small>
                                                            <?php endif; ?>
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <?php if (has_role('Owner', $conn) || has_role('Admin', $conn)): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-danger" href="lease_terminate.php?lease_id=<?= $leaseId ?>">Terminate with Cheque Return</a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Tenant Information -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Tenant Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Name:</th>
                                <td><?= h($tenantDisplayName) ?></td>
                            </tr>
                            <tr>
                                <th>Phone:</th>
                                <td><?= h($lease['phone'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>Email:</th>
                                <td><?= h($lease['email'] ?: '-') ?></td>
                            </tr>
                            <tr>
                                <th>ID Number:</th>
                                <td><?= h($lease['id_number']) ?></td>
                            </tr>
                        </table>
                        <a href="tenant_view.php?id=<?= $lease['tenant_id'] ?>" class="btn btn-sm btn-outline-primary">
                            View Tenant Details
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Summary -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><?= $accountingMode === 'invoice' ? 'Operational Schedule Summary' : 'Payment Summary' ?></h5>
            </div>
            <div class="card-body">
                <?php if ($accountingMode === 'invoice'): ?>
                    <div class="alert alert-light border small">
                        Installment counts below are operational cheque/payment schedule indicators only. Official tenant balance is managed through issued invoices, receipts, and allocations.
                    </div>
                <?php endif; ?>
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <div class="text-muted">Total Installments</div>
                            <div class="h4"><?= $totalInstallments ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <div class="text-muted">Paid</div>
                            <div class="h4 text-success"><?= $paidInstallments ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <div class="text-muted">Pending</div>
                            <div class="h4 text-warning"><?= $pendingInstallments ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <div class="text-muted">Overdue</div>
                            <div class="h4 text-danger"><?= $overdueInstallments ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Installments -->
        <div class="card mb-4" id="installments">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    Operational Payment / Cheque Schedule
                    <span class="badge bg-info text-dark ms-2">Expected Collections Only</span>
                </h5>
                <?php if (has_role('Owner', $conn) || has_role('Admin', $conn)): ?>
                    <form method="post" action="lease_view.php?id=<?= $leaseId ?>" class="m-0"
                          onsubmit="return confirm('Repair the operational cheque schedule? Paid receipts and invoice accounting are preserved. Duplicate unpaid rows are removed; duplicate paid rows are marked cancelled on the schedule only.');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="repair_schedule" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Fix fees row typing and remove duplicate schedule rows. Does not change invoices, receipts, or allocations.">
                            <i class="bi bi-tools"></i> Repair operational schedule
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="alert alert-light border small">
                    This schedule is for expected collections only. It does not control invoice issuance, revenue recognition, or accounting balances.
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Cheque / Reference No.</th>
                                <th>Bank</th>
                                <th>Expected Date</th>
                                <th>Amount</th>
                                <th>Outstanding</th>
                                <th>Status</th>
                                <th>Linked Receipt</th>
                                <th>Allocation Status</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($installments as $inst): 
                                $isFeesInstallment = ($feesInstallmentId !== null && $inst['id'] == $feesInstallmentId);
                                $isAmcInstallment  = (($inst['installment_type'] ?? 'rent') === 'amc');
                                $instType = (string)($inst['installment_type'] ?? 'rent');
                                $rowLocked = re_payment_schedule_row_locked($conn, [
                                    'id' => $inst['id'] ?? null,
                                    'installment_id' => $inst['id'] ?? null,
                                    'cheque_id' => $inst['cheque_id'] ?? null,
                                    'status' => $inst['status'] ?? null,
                                    'cheque_status' => $inst['cheque_status'] ?? null,
                                    'payment_id' => $inst['payment_id'] ?? null,
                                    'invoice_id' => $inst['invoice_id'] ?? null,
                                ]);
                                $separateTypeLabels = [
                                    'security_deposit' => 'Security Deposit',
                                    'parking' => 'Parking Fee',
                                    'store' => 'Store Fee',
                                    'chiller' => 'Chiller Fee',
                                    'ejari' => 'Ejari Fee',
                                    'admin' => 'Admin Fee',
                                    'commission' => 'Commission Fee',
                                    'vat' => 'VAT',
                                ];
                                $amountDetails = [];
                                if (isset($separateTypeLabels[$instType])) {
                                    $amountDetails[] = ['label' => $separateTypeLabels[$instType], 'amount' => (float)($inst['amount'] ?? 0)];
                                } elseif ($isFeesInstallment || $instType === 'combined_fees') {
                                    $amountDetails = $firstPaymentExtras;
                                } elseif ((int)$inst['id'] === (int)$firstRentInstallmentId && !empty($lease['add_fees_to_first_installment'])) {
                                    $amountDetails = $firstPaymentExtras;
                                    foreach ($splitPaymentExtras as $splitDetail) {
                                        $amountDetails[] = $splitDetail;
                                    }
                                } elseif ($instType === '' || $instType === 'rent') {
                                    $amountDetails = $splitPaymentExtras;
                                }
                            ?>
                                <tr>
                                    <td><?= h(ucwords(str_replace('_', ' ', (string)($inst['schedule_payment_method'] ?? 'cheque')))) ?></td>
                                    <td>
                                        <?= h($inst['reference_number'] ?: ($inst['cheque_number'] ?? '-')) ?>
                                        <?php
                                        $rowChequeDisplayStatus = '';
                                        if ($accountingMode === 'invoice' && !empty($inst['cheque_id'])) {
                                            $refChequeSummary = $chequeReceiptSummaries[(int)$inst['cheque_id']] ?? null;
                                            if ($refChequeSummary) {
                                                $rowChequeDisplayStatus = (string)($refChequeSummary['display_status'] ?? '');
                                            }
                                        }
                                        if ($rowChequeDisplayStatus === '' && !empty($inst['cheque_status']) && $inst['cheque_status'] !== 'pending') {
                                            $rowChequeDisplayStatus = (string)$inst['cheque_status'];
                                        }
                                        if ($rowChequeDisplayStatus !== '' && $rowChequeDisplayStatus !== 'pending'): ?>
                                            <br><span class="badge bg-<?= lease_status_badge_class($rowChequeDisplayStatus) ?>"><?= h(re_cheque_collection_display_label($rowChequeDisplayStatus)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($inst['bank_name'] ?: '-') ?></td>
                                    <td>
                                        <?= date('Y-m-d', strtotime($inst['installment_date'])) ?>
                                        <?php if ($isAmcInstallment): ?>
                                            <br><small class="badge text-white" style="background:#6f42c1;">AMC Fee</small>
                                        <?php elseif ($isFeesInstallment): ?>
                                            <br><small class="badge bg-info">Fees Installment</small>
                                        <?php elseif (isset($separateTypeLabels[$instType])): ?>
                                            <br><small class="badge text-white" style="background:#6f42c1;"><?= h($separateTypeLabels[$instType]) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        // installment.amount is the source of truth (engine-aligned);
                                        // cheque number/status are shown separately.
                                        $displayAmount = (float)($inst['amount'] ?? 0);
                                        ?>
                                        <?= number_format($displayAmount, 2) ?> AED
                                        <?php if (!empty($amountDetails)): ?>
                                            <div class="mt-1 small">
                                                <span class="badge bg-light text-dark border">Amount details</span>
                                                <?php foreach ($amountDetails as $detail): ?>
                                                    <?php if ((float)$detail['amount'] > 0): ?>
                                                        <div class="text-muted">
                                                            <?= h($detail['label']) ?>:
                                                            <strong><?= number_format((float)$detail['amount'], 2) ?> AED</strong>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        // Operational outstanding: schedule face − collected receipts on this cheque.
                                        $rowOutstanding = (float)($inst['outstanding_balance'] ?? max(0, $displayAmount - (float)($inst['total_paid_display'] ?? 0)));
                                        ?>
                                        <?php if ($rowOutstanding <= 0.005): ?>
                                            <span class="text-success fw-semibold">0.00 AED</span>
                                        <?php else: ?>
                                            <span class="text-danger fw-semibold"><?= number_format($rowOutstanding, 2) ?> AED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        // Canonical status computed once above — same value feeds the counters.
                                        $displayStatus = (string)($inst['computed_status'] ?? lease_installment_status($inst, $graceDays));
                                        $class = lease_status_badge_class($displayStatus);
                                        $displayStatusLabel = $displayStatus === 'partial' ? 'Partially Collected' : ucfirst($displayStatus);
                                        ?>
                                        <span class="badge bg-<?= $class ?>"><?= h($displayStatusLabel) ?></span>
                                        <?php if ($rowLocked): ?>
                                            <br><span class="badge bg-dark">Locked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $collected = (float)($inst['total_paid_display'] ?? 0);
                                        $rowChequeSummary = null;
                                        if ($accountingMode === 'invoice' && !empty($inst['cheque_id'])) {
                                            $rowChequeSummary = $chequeReceiptSummaries[(int)$inst['cheque_id']] ?? null;
                                        }
                                        if ($accountingMode === 'invoice' && $rowChequeSummary && $rowChequeSummary['receipt_count'] > 0): ?>
                                            <div class="small">
                                                <?php foreach ($rowChequeSummary['receipts'] as $rcpt):
                                                    $displayAmt = (float)($rcpt['display_amount'] ?? $rcpt['amount'] ?? 0);
                                                    $faceAmt = (float)($rcpt['receipt_face_amount'] ?? $rcpt['amount'] ?? 0);
                                                    $inferred = !empty($rcpt['is_coverage_inferred']);
                                                ?>
                                                    <div>
                                                        <a href="payment_view.php?id=<?= (int)$rcpt['id'] ?>" class="text-decoration-none">
                                                            <?= h($rcpt['receipt_number'] ?: ('#' . $rcpt['id'])) ?>
                                                        </a>
                                                        <span class="text-muted">· <?= number_format($displayAmt, 2) ?> AED</span>
                                                        <?php if ($inferred): ?>
                                                            <span class="badge bg-light text-dark border ms-1" title="Inferred from invoice allocations covering this schedule period (receipt face <?= number_format($faceAmt, 2) ?> AED)">Inferred</span>
                                                        <?php elseif ($faceAmt > $displayAmt + 0.005): ?>
                                                            <span class="text-muted small">(of <?= number_format($faceAmt, 2) ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="text-muted mt-1">
                                                    <?= (int)$rowChequeSummary['receipt_count'] ?> receipt<?= $rowChequeSummary['receipt_count'] === 1 ? '' : 's' ?>
                                                    · <?= number_format($rowChequeSummary['collected_total'], 2) ?> / <?= number_format($rowChequeSummary['cheque_amount'], 2) ?> AED
                                                </div>
                                            </div>
                                        <?php elseif ($collected > 0): ?>
                                            <span class="text-success"><?= number_format($collected, 2) ?> AED</span>
                                            <?php if (!empty($inst['receipt_number'])): ?>
                                                <br><small><?= h($inst['receipt_number']) ?></small>
                                                <?php if (!empty($inst['cleared_date'])): ?>
                                                    <br><small class="text-muted">Cleared <?= h($inst['cleared_date']) ?></small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $rowSummaryAllocStatus = null;
                                        if ($accountingMode === 'invoice' && !empty($inst['cheque_id'])) {
                                            $allocSummary = $chequeReceiptSummaries[(int)$inst['cheque_id']] ?? null;
                                            if ($allocSummary && ($allocSummary['receipt_count'] ?? 0) > 0) {
                                                // Operational status from this cheque's covered share — not the receipt's
                                                // global allocation_status (which may be overpaid due to other invoices).
                                                if (!empty($allocSummary['is_fully_collected'])) {
                                                    $rowSummaryAllocStatus = 'allocated';
                                                } elseif ((float)($allocSummary['collected_total'] ?? 0) > 0.005) {
                                                    $rowSummaryAllocStatus = 'partial';
                                                }
                                            }
                                        }
                                        if (!empty($inst['allocation_status']) && $rowSummaryAllocStatus === null): ?>
                                            <span class="badge bg-<?= $inst['allocation_status'] === 'allocated' ? 'success' : ($inst['allocation_status'] === 'partial' ? 'warning text-dark' : 'secondary') ?>"><?= h(ucwords(str_replace('_', ' ', (string)$inst['allocation_status']))) ?></span>
                                        <?php elseif ($rowSummaryAllocStatus): ?>
                                            <span class="badge bg-<?= $rowSummaryAllocStatus === 'allocated' ? 'success' : 'warning text-dark' ?>"><?= h(ucwords(str_replace('_', ' ', $rowSummaryAllocStatus))) ?></span>
                                        <?php elseif ($collected > 0): ?>
                                            <span class="badge bg-success">Linked</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= h($inst['cheque_notes'] ?: ($inst['notes'] ?? '-')) ?>
                                    </td>
                                    <td>
                                        <?php
                                        $pids = $paymentsPerInstallment[$inst['id']] ?? [];
                                        $viewPaymentId = $inst['linked_payment_id'] ?? ($inst['payment_id'] ?? ($inst['cheque_payment_id'] ?? null));
                                        $rowChequeSummary = ($accountingMode === 'invoice' && !empty($inst['cheque_id']))
                                            ? ($chequeReceiptSummaries[(int)$inst['cheque_id']] ?? null)
                                            : null;
                                        $summaryReceiptIds = $rowChequeSummary
                                            ? array_map(static fn(array $r): int => (int)$r['id'], $rowChequeSummary['receipts'] ?? [])
                                            : [];
                                        if (empty($pids) && !$viewPaymentId && payment_allocation_tables_exist($conn)) {
                                            $stmtLink = $conn->prepare("SELECT payment_id FROM re_payment_allocations WHERE installment_id = ? ORDER BY id DESC LIMIT 1");
                                            $stmtLink->execute([$inst['id']]);
                                            $viewPaymentId = $stmtLink->fetchColumn();
                                        }
                                        $receiptHistoryUrl = 'installment_payment_history.php?installment_id=' . (int)$inst['id']
                                            . (!empty($inst['cheque_id']) ? '&cheque_id=' . (int)$inst['cheque_id'] : '');
                                        if (count($summaryReceiptIds) > 1): ?>
                                            <a href="<?= h($receiptHistoryUrl) ?>" class="btn btn-sm btn-outline-primary" title="View all linked receipts for this schedule row">
                                                View Receipts (<?= count($summaryReceiptIds) ?>)
                                            </a>
                                        <?php elseif (count($summaryReceiptIds) === 1): ?>
                                            <a href="payment_view.php?id=<?= (int)$summaryReceiptIds[0] ?>" class="btn btn-sm btn-outline-primary">
                                                View Receipt
                                            </a>
                                        <?php elseif (count($pids) > 1): ?>
                                            <a href="<?= h($receiptHistoryUrl) ?>" class="btn btn-sm btn-outline-primary" title="View all payments against this installment">
                                                View payments (<?= count($pids) ?>)
                                            </a>
                                        <?php elseif (count($pids) === 1): ?>
                                            <a href="payment_view.php?id=<?= (int)$pids[0] ?>" class="btn btn-sm btn-outline-primary">
                                                View Receipt
                                            </a>
                                        <?php elseif ($viewPaymentId): ?>
                                            <a href="payment_view.php?id=<?= (int)$viewPaymentId ?>" class="btn btn-sm btn-outline-primary">
                                                <?= !empty($inst['receipt_number']) ? 'View Receipt' : 'View Payment' ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($rowLocked): ?>
                                            <div class="small text-muted mb-1">This payment row cannot be edited because it is already linked, cleared, allocated, or legally escalated.</div>
                                        <?php endif; ?>
                                        <?php if ($inst['outstanding_balance'] > 0 && !in_array($displayStatus, ['cancelled', 'returned'], true)): ?>
                                            <?php if ($accountingMode !== 'invoice'): ?>
                                                <a href="payment_add.php?lease_id=<?= $leaseId ?>&installment_id=<?= $inst['id'] ?>&collect_balance=1" class="btn btn-sm btn-warning">
                                                    Collect Balance
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <a href="lease_payment_schedule_audit.php?lease_id=<?= (int)$leaseId ?>&row_id=<?= (int)$inst['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                            Audit
                                        </a>
                                        <?php if ($accountingMode === 'invoice' && !empty($inst['cheque_id'])):
                                            $rowChequeSummary = $chequeReceiptSummaries[(int)$inst['cheque_id']] ?? null;
                                            if ($rowChequeSummary && !$rowChequeSummary['is_fully_collected']): ?>
                                            <a href="<?= h(re_cheque_allocate_payment_url((int)$leaseId, (int)$inst['cheque_id'], $inst, $rowChequeSummary)) ?>" class="btn btn-sm btn-success">
                                                <i class="bi bi-cash-coin"></i> Allocate Payment
                                            </a>
                                        <?php endif; endif; ?>
                                        <?php if (!empty($inst['cheque_id'])): ?>
                                            <a href="billing_cheque_view.php?id=<?= (int)$inst['cheque_id'] ?>" class="btn btn-sm btn-outline-dark">
                                                View Cheque
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($inst['cheque_id'])): ?>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-outline-dark dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="bi bi-bank"></i> Cheque
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><button type="button" class="dropdown-item cheque-action-btn"
                                                            data-action="deposit" data-cheque="<?= (int)$inst['cheque_id'] ?>"
                                                            data-cheque-number="<?= h($inst['cheque_number'] ?? '') ?>">
                                                            <i class="bi bi-bank"></i> Mark Deposited</button></li>
                                                    <li><button type="button" class="dropdown-item text-danger cheque-action-btn"
                                                            data-action="bounce" data-cheque="<?= (int)$inst['cheque_id'] ?>"
                                                            data-cheque-number="<?= h($inst['cheque_number'] ?? '') ?>"
                                                            data-bank-name="<?= h($inst['bank_name'] ?? '') ?>">
                                                            <i class="bi bi-x-octagon"></i> Mark Bounced</button></li>
                                                    <li><button type="button" class="dropdown-item cheque-action-btn"
                                                            data-action="hold" data-cheque="<?= (int)$inst['cheque_id'] ?>"
                                                            data-cheque-number="<?= h($inst['cheque_number'] ?? '') ?>">
                                                            <i class="bi bi-pause-circle"></i> Put on Hold</button></li>
                                                    <li><button type="button" class="dropdown-item text-primary cheque-action-btn"
                                                            data-action="escalate" data-cheque="<?= (int)$inst['cheque_id'] ?>"
                                                            data-cheque-number="<?= h($inst['cheque_number'] ?? '') ?>">
                                                            <i class="bi bi-bank2"></i> Escalate to Legal</button></li>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php if (has_role('Owner', $conn) || has_role('Admin', $conn)): ?>
        <div class="card mb-4 border-warning">
            <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="small">
                    <strong>Admin tool:</strong> Financial Rebook removes payments, accounting, schedule, and service charges while keeping documents and lease terms.
                    Data entry must open Edit Lease and Save to rebuild the operational schedule.
                </div>
                <a href="admin/lease_financial_reset_convert.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-arrow-repeat"></i> Financial Rebook
                </a>
            </div>
        </div>
<?php endif; ?>

<?php if (has_role('Owner', $conn)): ?>
        <!-- Owner-only clean delete -->
        <div class="modal fade" id="deleteLeaseModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="post" action="lease_view.php?id=<?= $leaseId ?>" class="modal-content">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="hard_delete_lease" value="1">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Permanently delete this lease</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <strong>This cannot be undone.</strong> It will permanently remove this lease and
                            <em>all</em> related records — installments, cheques, payments, allocations, invoices,
                            billing/penalties, revenue recognition, and every accounting journal/ledger entry tied
                            to this lease.
                        </div>
                        <p class="mb-2">To confirm, type the lease number
                            <code><?= h($lease['lease_number'] ?: ('L-' . $lease['id'])) ?></code> below:</p>
                        <input type="text" name="confirm_lease_number" class="form-control" autocomplete="off"
                               placeholder="<?= h($lease['lease_number'] ?: ('L-' . $lease['id'])) ?>" required>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Delete permanently</button>
                    </div>
                </form>
            </div>
        </div>
<?php endif; ?>

        <!-- Cheque action modal (Bounce / Hold / Escalate to Legal) -->
        <div class="modal fade" id="chequeActionModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="post" action="lease_view.php?id=<?= $leaseId ?>" class="modal-content">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="cheque_action" id="ca_action">
                    <input type="hidden" name="cheque_id" id="ca_cheque_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="ca_title">Cheque Action</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-2" id="ca_help"></p>
                        <div class="mb-2">
                            <label class="form-label">Cheque</label>
                            <input type="text" class="form-control" id="ca_cheque_number" disabled>
                        </div>
                        <div class="mb-2" id="ca_bounce_fields" style="display:none;">
                            <div class="mb-2">
                                <label class="form-label" for="ca_bounced_date">Bounced date *</label>
                                <input type="date" name="bounced_date" id="ca_bounced_date" class="form-control" value="<?= h(date('Y-m-d')) ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label" for="ca_deposit_bank_account_id">Deposited to (our bank account) *</label>
                                <select name="deposit_bank_account_id" id="ca_deposit_bank_account_id" class="form-select">
                                    <option value="">— Select bank account —</option>
                                    <?php foreach ($companyBankAccounts as $ba): ?>
                                        <option value="<?= (int)$ba['id'] ?>">
                                            <?= h(($ba['account_code'] ?? '') . ' — ' . ($ba['account_name'] ?? '') . (!empty($ba['bank_name']) ? ' (' . $ba['bank_name'] . ')' : '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($companyBankAccounts)): ?>
                                    <div class="form-text text-danger">No active bank accounts. Add one under Accounting → Bank Accounts first.</div>
                                <?php else: ?>
                                    <div class="form-text">Which company bank received this cheque (where it bounced). No GL bounce journal is posted for uncleared cheques — match the statement line in Bank Reconciliation Create/Adjust if needed.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mb-1">
                            <label class="form-label" id="ca_note_label">Reason / Note</label>
                            <textarea name="cheque_note" id="ca_note" class="form-control" rows="3" placeholder="Add details for the record / legal team"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="ca_submit">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        (function () {
            var modalEl = document.getElementById('chequeActionModal');
            if (!modalEl) return;
            var meta = {
                deposit:  { title: 'Mark Cheque as Deposited', help: 'This is operational only. Clearing must be handled through the approved receipt/allocation flow.', btn: 'btn-success', label: 'Deposit note (optional)', submit: 'Mark Deposited' },
                bounce:   { title: 'Mark Cheque as Bounced', help: 'Flags the cheque as bounced, raises any configured bounced-cheque penalty, and emails only the Legal Department Email(s) from Settings. Uncleared cheques do not post a GL bounce journal — match the bank statement bounce via Bank Reconciliation Create/Adjust if needed.', btn: 'btn-danger', label: 'Bounce reason', submit: 'Mark Bounced' },
                hold:     { title: 'Put Cheque on Hold', help: 'Holds the cheque (do not deposit yet). No email is sent.', btn: 'btn-dark', label: 'Hold note (optional)', submit: 'Put on Hold' },
                escalate: { title: 'Escalate Cheque to Legal', help: 'Sends the cheque + lease details to the Legal Department Email(s) from Settings and adds it to the Legal inbox.', btn: 'btn-primary', label: 'Escalation note', submit: 'Escalate' }
            };
            document.querySelectorAll('.cheque-action-btn').forEach(function (b) {
                b.addEventListener('click', function () {
                    var act = b.getAttribute('data-action');
                    var m = meta[act] || meta.escalate;
                    document.getElementById('ca_action').value = act;
                    document.getElementById('ca_cheque_id').value = b.getAttribute('data-cheque');
                    document.getElementById('ca_cheque_number').value = b.getAttribute('data-cheque-number') || ('#' + b.getAttribute('data-cheque'));
                    document.getElementById('ca_title').textContent = m.title;
                    document.getElementById('ca_help').textContent = m.help;
                    document.getElementById('ca_note_label').textContent = m.label;
                    var bounceFields = document.getElementById('ca_bounce_fields');
                    var bouncedDateInput = document.getElementById('ca_bounced_date');
                    var depositBankSelect = document.getElementById('ca_deposit_bank_account_id');
                    if (bounceFields) {
                        bounceFields.style.display = act === 'bounce' ? '' : 'none';
                    }
                    if (bouncedDateInput) {
                        bouncedDateInput.required = act === 'bounce';
                        if (act === 'bounce' && !bouncedDateInput.value) {
                            bouncedDateInput.value = new Date().toISOString().slice(0, 10);
                        }
                    }
                    if (depositBankSelect) {
                        depositBankSelect.required = act === 'bounce';
                        if (act !== 'bounce') {
                            depositBankSelect.value = '';
                        }
                    }
                    var sub = document.getElementById('ca_submit');
                    sub.textContent = m.submit;
                    sub.className = 'btn ' + m.btn;
                    new bootstrap.Modal(modalEl).show();
                });
            });
        })();
        </script>

        <!-- Service Charge Fee Schedule -->
        <?php if (!empty($serviceChargeSchedule)): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0"><i class="bi bi-lightning-charge"></i> Service Charge Fee Schedule</h5>
                    <small class="text-muted">Recurring services such as EV charging are tracked separately from rent installments.</small>
                </div>
                <?php if ($accountingMode === 'invoice'): ?>
                    <a href="accounting/receipt_allocation.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-sm btn-success">
                        <i class="bi bi-cash-coin"></i> Allocate Receipt
                    </a>
                <?php else: ?>
                    <a href="payment_add.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-sm btn-success">
                        <i class="bi bi-cash-coin"></i> Record Payment
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($serviceChargesById)): ?>
                    <div class="row g-3 mb-3">
                        <?php foreach ($serviceChargesById as $svc): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <strong><?= h($svc['name']) ?></strong>
                                            <div class="small text-muted">
                                                <?= !empty($svc['is_recurring']) ? ucfirst(str_replace('_', ' ', $svc['recurrence_type'])) . ' recurring' : 'One-time' ?>
                                                <?php if (!empty($svc['contracted_amount'])): ?>
                                                    · Contracted <?= number_format((float)$svc['contracted_amount'], 2) ?> AED
                                                <?php endif; ?>
                                                <?php if (!empty($svc['vat_treatment'])): ?>
                                                    · VAT <?= h(str_replace('_', ' ', (string)$svc['vat_treatment'])) ?>
                                                    <?php if ((float)($svc['vat_rate'] ?? 0) > 0): ?>
                                                        (<?= number_format((float)$svc['vat_rate'], 2) ?>%)
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php
                                        $life = (string)($svc['lifecycle_status'] ?? (!empty($svc['is_active']) ? 'active' : 'cancelled'));
                                        $lifeBadge = match ($life) {
                                            'active' => 'success',
                                            'suspended' => 'warning',
                                            'draft' => 'secondary',
                                            default => 'secondary',
                                        };
                                        ?>
                                        <span class="badge bg-<?= $lifeBadge ?>">
                                            <?= h(ucfirst($life)) ?>
                                        </span>
                                    </div>
                                    <div class="small mt-2">
                                        <span class="text-muted">Total:</span> <?= number_format($svc['total'], 2) ?> AED ·
                                        <span class="text-success">Paid:</span> <?= number_format($svc['paid'], 2) ?> AED ·
                                        <span class="text-danger">Outstanding:</span> <?= number_format($svc['outstanding'], 2) ?> AED
                                    </div>
                                    <?php if (!empty($svc['is_active']) && in_array($life, ['active', 'suspended'], true)): ?>
                                        <div class="d-flex flex-wrap gap-2 mt-2">
                                            <?php if ($life === 'active'): ?>
                                            <form method="post" onsubmit="return confirm('Suspend this service charge? Future unpaid schedule rows will be stopped, but past unpaid fees remain collectible.');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="suspend_service_charge_id" value="<?= (int)$svc['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                                    <i class="bi bi-pause-circle"></i> Suspend
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="post" onsubmit="return confirm('Cancel this Extra Service? All unpaid schedule rows, obligations, and invoices for this service will be cleared (paid invoices block cancel). Rent cheques are not touched.');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="cancel_service_charge_id" value="<?= (int)$svc['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-x-circle"></i> Cancel Service
                                                </button>
                                            </form>
                                        </div>
                                    <?php elseif ($life === 'cancelled' && (float)($svc['outstanding'] ?? 0) > 0.005): ?>
                                        <div class="d-flex flex-wrap gap-2 mt-2">
                                            <form method="post" onsubmit="return confirm('Clear remaining unpaid Extra Service schedule/invoices for this cancelled service?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="clear_cancelled_service_charge_id" value="<?= (int)$svc['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i> Clear remaining open items
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($servicePaymentPlans)): ?>
                    <div class="mb-4">
                        <h6 class="fw-semibold">Payment Plan (collection only)</h6>
                        <p class="small text-muted mb-2">Independent of monthly revenue rows. Register optional PDCs here — rent cheques are never updated. Use <strong>Allocate Payment</strong> on a registered PDC to auto-suggest Extra Service invoices for that cheque only.</p>
                        <?php foreach ($servicePaymentPlans as $sid => $plan): ?>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                                    <strong><?= h($serviceChargesById[$sid]['name'] ?? ('Service #' . $sid)) ?></strong>
                                    <span class="small text-muted">Plan total <?= number_format((float)$plan['planned_total'], 2) ?> AED · <?= h((string)($plan['expected_method'] ?? '—')) ?></span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Due</th>
                                                <th class="text-end">Amount</th>
                                                <th>Status</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (($plan['lines'] ?? []) as $pline): ?>
                                                <tr>
                                                    <td><?= (int)$pline['line_no'] ?></td>
                                                    <td><?= h($pline['due_date']) ?></td>
                                                    <td class="text-end"><?= number_format((float)$pline['amount'], 2) ?></td>
                                                    <td><?= h(ucfirst((string)$pline['status'])) ?></td>
                                                    <td class="text-end">
                                                        <?php if (($pline['status'] ?? '') === 'planned' && empty($pline['cheque_id'])): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#registerServiceChequeModal"
                                                                    data-line-id="<?= (int)$pline['id'] ?>"
                                                                    data-amount="<?= h((string)$pline['amount']) ?>"
                                                                    data-due="<?= h((string)$pline['due_date']) ?>">
                                                                Register Cheque
                                                            </button>
                                                        <?php elseif (!empty($pline['cheque_id']) && $accountingMode === 'invoice'): ?>
                                                            <?php
                                                            $scPlanChequeId = (int)$pline['cheque_id'];
                                                            $scPlanCheque = re_cheque_load($conn, (int)$currentCompanyId, $scPlanChequeId);
                                                            $scPlanChequeSummary = $scPlanCheque
                                                                ? re_cheque_receipt_summary($conn, (int)$currentCompanyId, $scPlanChequeId)
                                                                : null;
                                                            $scPlanRemaining = (float)($scPlanChequeSummary['remaining'] ?? 0);
                                                            ?>
                                                            <?php if ($scPlanCheque && $scPlanRemaining > 0.005): ?>
                                                                <a href="<?= h(re_cheque_allocate_payment_url((int)$leaseId, $scPlanChequeId, $scPlanCheque, $scPlanChequeSummary)) ?>"
                                                                   class="btn btn-sm btn-success">
                                                                    <i class="bi bi-cash-coin"></i> Allocate Payment
                                                                </a>
                                                            <?php else: ?>
                                                                <span class="small text-muted">PDC #<?= $scPlanChequeId ?></span>
                                                            <?php endif; ?>
                                                        <?php elseif (!empty($pline['cheque_id'])): ?>
                                                            <span class="small text-muted">PDC #<?= (int)$pline['cheque_id'] ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <p class="small text-muted mb-2">Fee Schedule = monthly revenue invoices. Collect via Payment Plan cheques above (Allocate Payment).</p>
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Due Date</th>
                                <th>Service</th>
                                <th>Period</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Collected</th>
                                <th class="text-end">Outstanding</th>
                                <th>Status</th>
                                <?php if ($accountingMode !== 'invoice'): ?>
                                <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($serviceChargeSchedule as $svcItem):
                                $svcStatus = $svcItem['status'] ?? 'pending';
                                if ($svcStatus !== 'waived') {
                                    if ((float)$svcItem['outstanding_amount'] <= 0.005 && (float)$svcItem['total_amount'] > 0) {
                                        $svcStatus = 'paid';
                                    } elseif ((float)$svcItem['paid_amount_display'] > 0) {
                                        $svcStatus = 'partial';
                                    } elseif (!empty($svcItem['due_date']) && $svcItem['due_date'] < date('Y-m-d')) {
                                        $svcStatus = 'overdue';
                                    }
                                }
                                $svcBadge = [
                                    'paid' => 'success',
                                    'partial' => 'info',
                                    'overdue' => 'danger',
                                    'waived' => 'secondary',
                                    'pending' => 'warning',
                                ][$svcStatus] ?? 'secondary';
                            ?>
                                <tr>
                                    <td><?= h(date('Y-m-d', strtotime($svcItem['due_date']))) ?></td>
                                    <td>
                                        <strong><?= h($svcItem['service_name'] ?: $svcItem['item_name']) ?></strong>
                                        <?php if (!empty($svcItem['item_description'])): ?>
                                            <br><small class="text-muted"><?= h($svcItem['item_description']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($svcItem['billing_period_start'])): ?>
                                            <?= h(date('d M Y', strtotime($svcItem['billing_period_start']))) ?>
                                            <?php if (!empty($svcItem['billing_period_end'])): ?>
                                                to <?= h(date('d M Y', strtotime($svcItem['billing_period_end']))) ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= number_format((float)$svcItem['total_amount'], 2) ?> AED</td>
                                    <td class="text-end text-success"><?= number_format((float)$svcItem['paid_amount_display'], 2) ?> AED</td>
                                    <td class="text-end">
                                        <?php if ((float)$svcItem['outstanding_amount'] > 0): ?>
                                            <strong class="text-danger"><?= number_format((float)$svcItem['outstanding_amount'], 2) ?> AED</strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?= $svcBadge ?>"><?= ucfirst($svcStatus) ?></span></td>
                                    <?php if ($accountingMode !== 'invoice'): ?>
                                    <td>
                                        <?php if ((float)$svcItem['outstanding_amount'] > 0 && $svcStatus !== 'waived'): ?>
                                            <a href="payment_add.php?lease_id=<?= (int)$leaseId ?>&billing_item_id=<?= (int)$svcItem['id'] ?>&collect_balance=1" class="btn btn-sm btn-warning">
                                                Collect
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="modal fade" id="registerServiceChequeModal" tabindex="-1">
            <div class="modal-dialog">
                <form method="post" class="modal-content">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="register_service_cheque">
                    <input type="hidden" name="plan_line_id" id="reg_sc_plan_line_id" value="">
                    <div class="modal-header">
                        <h5 class="modal-title">Register Service Cheque</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">Creates a pending PDC linked to the payment-plan line only (<code>installment_id</code> stays empty).</p>
                        <div class="mb-3">
                            <label class="form-label">Cheque number *</label>
                            <input type="text" name="cheque_number" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount *</label>
                                <input type="number" step="0.01" name="cheque_amount" id="reg_sc_cheque_amount" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Cheque date *</label>
                                <input type="date" name="cheque_date" id="reg_sc_cheque_date" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Bank name</label>
                            <input type="text" name="bank_name" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Account holder</label>
                            <input type="text" name="account_holder_name" class="form-control">
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Register</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        (function () {
            const modal = document.getElementById('registerServiceChequeModal');
            if (!modal) return;
            modal.addEventListener('show.bs.modal', function (ev) {
                const btn = ev.relatedTarget;
                if (!btn) return;
                document.getElementById('reg_sc_plan_line_id').value = btn.getAttribute('data-line-id') || '';
                document.getElementById('reg_sc_cheque_amount').value = btn.getAttribute('data-amount') || '';
                document.getElementById('reg_sc_cheque_date').value = btn.getAttribute('data-due') || '';
            });
        })();
        </script>

        <!-- Penalty Charges (Late Fee / Bounced Fee) -->
        <?php if (!empty($penaltyItems)): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Penalty Charges (Late Fee / Bounced Fee)</h5>
                <?php if ($accountingMode === 'invoice'): ?>
                    <a href="accounting/receipt_allocation.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-sm btn-success">
                        <i class="bi bi-cash-coin"></i> Allocate Receipt
                    </a>
                <?php else: ?>
                    <a href="payment_add.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-sm btn-success">
                        <i class="bi bi-cash-coin"></i> Record Payment (collect fees)
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    To collect: use <strong>Record Payment</strong> above for this lease, or include these items in an <a href="billing_invoice_create.php?lease_id=<?= (int)$leaseId ?>">Invoice</a> (Billing → Create Invoice). Penalty charges are typically not subject to VAT; add VAT in the invoice if required by your policy.
                </p>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Charge</th>
                                <th>Related Installment</th>
                                <th>Description</th>
                                <th>Due Date</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($penaltyItems as $pit):
                                $isWaived = !empty($pit['is_waived']);
                                $isPaid = !empty($pit['is_paid']);
                            ?>
                                <tr>
                                    <td><strong><?= h($pit['item_name']) ?></strong></td>
                                    <td>
                                        <?php if (!empty($pit['installment_date'])): ?>
                                            <span class="text-nowrap">
                                                <?= date('d M Y', strtotime($pit['installment_date'])) ?>
                                                <br>
                                                <small class="text-muted">
                                                    <?= number_format((float)$pit['installment_amount'], 2) ?> AED
                                                    <?php if (!empty($pit['cheque_number'])): ?>
                                                        · Cheque <?= h($pit['cheque_number']) ?>
                                                    <?php endif; ?>
                                                </small>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($pit['item_description'] ?: '-') ?></td>
                                    <td><?= date('Y-m-d', strtotime($pit['due_date'])) ?></td>
                                    <td class="text-end">
                                        <strong><?= number_format((float)$pit['total_amount'], 2) ?> AED</strong>
                                        <?php if (!$isPaid && !$isWaived): ?>
                                            <button type="button"
                                                    class="btn btn-link btn-sm p-0 ms-1 text-secondary"
                                                    title="Edit / apply discount"
                                                    onclick="openEditPenalty(<?= (int)$pit['id'] ?>, <?= number_format((float)$pit['total_amount'], 2, '.', '') ?>)">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isWaived): ?>
                                            <span class="badge bg-secondary">Waived</span>
                                        <?php elseif ($isPaid): ?>
                                            <span class="badge bg-success">Paid</span>
                                            <?php if (!empty($pit['paid_date'])): ?>
                                                <br><small class="text-muted"><?= date('Y-m-d', strtotime($pit['paid_date'])) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isWaived): ?>
                                            <span class="text-muted">—</span>
                                        <?php elseif (!$isPaid): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Waive this penalty charge? It will no longer be collectible.');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="waive_billing_item_id" value="<?= (int)$pit['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <i class="bi bi-x-circle"></i> Waive
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

<!-- ── Tenant Credit & Refunds Section ───────────────────────────────── -->
<?php
$creditBalanceDiff   = abs($tenantCreditBal - $auditTrueCredit);
$creditIsOverstated  = ($tenantCreditBal > $auditTrueCredit + 0.02);
$showCreditSection   = ($tenantCreditBal > 0 || !empty($creditHistory) || !empty($refundHistory) || $auditTrueCredit > 0);
?>
<?php if ($showCreditSection): ?>
<?php
$runningCredit = 0.0;
foreach ($creditHistory as $ct) {
    $runningCredit += ($ct['type'] === 'credit') ? (float)$ct['amount_aed'] : -(float)$ct['amount_aed'];
}
?>
<div class="card mb-4 <?= $creditIsOverstated ? 'border-warning' : 'border-info' ?>">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"
         style="background:<?= $creditIsOverstated ? 'rgba(255,193,7,0.12)' : 'rgba(13,202,240,0.08)' ?>;">
        <div>
            <h5 class="mb-0 <?= $creditIsOverstated ? 'text-warning' : 'text-info' ?>">
                <i class="bi bi-wallet2"></i> Tenant Credit &amp; Refunds
            </h5>
            <small class="text-muted">Tracks overpayments received, credit applied to installments, and refunds issued to tenant</small>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <!-- Stored balance -->
            <div class="text-center">
                <div class="small text-muted mb-1">Stored Credit Balance</div>
                <div class="badge <?= $creditIsOverstated ? 'bg-warning text-dark' : 'bg-info text-dark' ?> fs-6 px-3 py-2">
                    <i class="bi bi-piggy-bank me-1"></i>
                    <?= number_format($tenantCreditBal, 2) ?> AED
                </div>
            </div>
            <!-- Computed (true) balance -->
            <div class="text-center">
                <div class="small text-muted mb-1">Computed (Payments − Allocated)</div>
                <div class="badge <?= $creditBalanceDiff > 0.02 ? 'bg-success' : 'bg-info text-dark' ?> fs-6 px-3 py-2">
                    <i class="bi bi-calculator me-1"></i>
                    <?= number_format($auditTrueCredit, 2) ?> AED
                </div>
            </div>
            <?php if ($creditIsOverstated): ?>
            <div class="text-center">
                <div class="badge bg-danger mb-1 d-block">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Stored &gt; Computed by <?= number_format($tenantCreditBal - $auditTrueCredit, 2) ?> AED
                </div>
            </div>
            <?php endif; ?>
            <!-- Always-visible Correct Credit Balance button -->
            <div class="d-flex flex-column gap-1">
                <button type="button" class="btn btn-sm btn-danger"
                        data-bs-toggle="modal" data-bs-target="#fixCreditModal">
                    <i class="bi bi-wrench"></i> Correct Credit Balance
                </button>
                <?php if ($tenantCreditBal > 0): ?>
                    <?php if ($accountingMode === 'invoice'): ?>
                        <a href="accounting/receipt_allocation.php?lease_id=<?= $leaseId ?>" class="btn btn-sm btn-outline-info">
                            <i class="bi bi-arrow-down-circle"></i> Allocate Credit / Receipt
                        </a>
                    <?php else: ?>
                        <a href="payment_add.php?lease_id=<?= $leaseId ?>" class="btn btn-sm btn-outline-info">
                            <i class="bi bi-arrow-down-circle"></i> Apply to Installment
                        </a>
                    <?php endif; ?>
                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#issueRefundModal">
                    <i class="bi bi-arrow-return-left"></i> Issue Refund to Tenant
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment-level audit breakdown -->
    <?php if (!empty($auditPaymentRows)): ?>
    <div class="px-3 pt-3 pb-1">
        <h6 class="small text-uppercase text-muted mb-0">
            <i class="bi bi-search me-1"></i> Payment Audit — Every Payment vs. What Was Allocated
        </h6>
        <small class="text-muted">This table shows where the credit came from. "Excess" = payment amount minus amount actually allocated to installments.</small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th class="small">Receipt #</th>
                    <th class="small">Date</th>
                    <th class="small text-end">Payment Amount</th>
                    <th class="small text-end">Allocated to Installments</th>
                    <th class="small text-end">Excess → Credit</th>
                    <th class="small">Method / Reference</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $runAuditTotal = 0.0;
                foreach ($auditPaymentRows as $ar):
                    $excess = round((float)$ar['amount'] - (float)$ar['allocated'], 2);
                    $runAuditTotal += $excess;
                    $hasExcess = $excess > 0.01;
                ?>
                <tr class="<?= $hasExcess ? 'table-warning' : '' ?>">
                    <td class="small">
                        <a href="payment_view.php?id=<?= (int)$ar['id'] ?>" class="fw-semibold">
                            <?= $ar['receipt_number'] ? '#'.h($ar['receipt_number']) : 'PAY-'.(int)$ar['id'] ?>
                        </a>
                    </td>
                    <td class="small"><?= h($ar['payment_date']) ?></td>
                    <td class="text-end small fw-bold"><?= number_format((float)$ar['amount'], 2) ?></td>
                    <td class="text-end small"><?= number_format((float)$ar['allocated'], 2) ?></td>
                    <td class="text-end small fw-bold <?= $hasExcess ? 'text-danger' : 'text-success' ?>">
                        <?= $hasExcess ? '+'.number_format($excess, 2) : '0.00' ?>
                        <?php if ($hasExcess): ?>
                            <i class="bi bi-arrow-right text-muted" title="Went to tenant credit"></i>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= h(ucfirst(str_replace('_',' ',$ar['payment_method']))) ?>
                        <?php if ($ar['reference_number']): ?>
                            · <?= h($ar['reference_number']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="2" class="text-end small">Totals:</td>
                    <td class="text-end small"><?= number_format($auditTotalPayments, 2) ?></td>
                    <td class="text-end small"><?= number_format($auditTotalAllocated, 2) ?></td>
                    <td class="text-end small <?= $auditTrueCredit > 0.01 ? 'text-danger' : 'text-success' ?>">
                        <?= $auditTrueCredit > 0.01 ? '+'.number_format($auditTrueCredit, 2) : '0.00' ?>
                    </td>
                    <td class="small text-muted">
                        True credit = <?= number_format($auditTotalPayments,2) ?> − <?= number_format($auditTotalAllocated,2) ?>
                        <?php if ($auditTotalRefunded > 0): ?> − <?= number_format($auditTotalRefunded,2) ?> refunded<?php endif; ?>
                        = <strong><?= number_format($auditTrueCredit,2) ?> AED</strong>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
    <div class="card-body p-0">

        <!-- Full credit transaction history (all-in-one table) -->
        <div class="px-3 pt-3 pb-1 d-flex justify-content-between align-items-center">
            <h6 class="text-muted small text-uppercase mb-0">
                <i class="bi bi-clock-history me-1"></i> Full Transaction History
            </h6>
            <?php
            // Show a warning if the running computed balance differs from stored balance
            $balanceDiff = abs($runningCredit - $tenantCreditBal);
            if ($balanceDiff > 0.02 && !empty($creditHistory)):
            ?>
            <span class="badge bg-warning text-dark">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Computed: <?= number_format($runningCredit, 2) ?> AED | Stored: <?= number_format($tenantCreditBal, 2) ?> AED
                — may include transactions from other leases
            </span>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="small">Date</th>
                        <th class="small">Type</th>
                        <th class="small text-end">Payment Total (AED)</th>
                        <th class="small text-end">Allocated to Installments</th>
                        <th class="small text-end fw-bold">Credit Movement (AED)</th>
                        <th class="small">Receipt / Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $badgeMap = [
                        'overpayment'         => ['bg-info text-dark',   'Overpayment',        'The tenant paid more than what was owed. Excess moved to credit.'],
                        'applied_installment' => ['bg-success',          'Applied',             'Credit was used to partially/fully cover an installment.'],
                        'refund_issued'       => ['bg-warning text-dark','Refund Issued',       'Cash/transfer refunded back to the tenant.'],
                        'manual_credit'       => ['bg-primary',          'Manual Credit',       'Manually added credit.'],
                        'manual_debit'        => ['bg-secondary',        'Manual Debit',        'Manually deducted credit.'],
                    ];
                    if (!empty($creditHistory)):
                        foreach ($creditHistory as $ct):
                            $txType = $ct['transaction_type'] ?? ($ct['type'] === 'credit' ? 'overpayment' : 'applied_installment');
                            $txDate = $ct['payment_date'] ?? substr($ct['created_at'], 0, 10);
                            $receiptNum = $ct['receipt_number'] ?? null;
                            // Fallback: extract payment id from reference like "Overpayment/advance payment #259"
                            $refPayId   = $ct['payment_id'] ?? null;
                            if (!$refPayId && preg_match('/#(\d+)/', $ct['reference'] ?? '', $m)) {
                                $refPayId = (int)$m[1];
                            }
                            if (!$receiptNum && $ct['reference']) {
                                $receiptNum = preg_replace('/^(Overpayment\/advance payment|Applied to payment|Refund issued)[^\d]*/', '', $ct['reference']);
                            }
                            [$badgeCls, $badgeLabel, $badgeTitle] = $badgeMap[$txType] ?? ['bg-secondary', ucfirst(str_replace('_', ' ', $txType)), ''];
                            $payTotal    = $ct['payment_total'] !== null ? (float)$ct['payment_total'] : null;
                            $allocTotal  = $ct['allocated_total'] !== null ? (float)$ct['allocated_total'] : null;
                            $isCredit    = $ct['type'] === 'credit';
                            $creditAmt   = (float)$ct['amount_aed'];
                    ?>
                    <tr class="<?= $isCredit ? '' : 'table-light' ?>">
                        <td class="small"><?= h($txDate) ?></td>
                        <td>
                            <span class="badge <?= $badgeCls ?>" title="<?= h($badgeTitle) ?>">
                                <?= $badgeLabel ?>
                            </span>
                        </td>
                        <td class="text-end small">
                            <?php if ($payTotal !== null): ?>
                                <strong><?= number_format($payTotal, 2) ?></strong>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small">
                            <?php if ($payTotal !== null && $allocTotal !== null): ?>
                                <?= number_format($allocTotal, 2) ?>
                                <?php if ($payTotal > 0): ?>
                                    <br><small class="text-muted">(<?= number_format(($allocTotal / $payTotal) * 100, 0) ?>% of payment)</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-bold <?= $isCredit ? 'text-info' : 'text-danger' ?>">
                            <?= $isCredit ? '+' : '−' ?><?= number_format($creditAmt, 2) ?> AED
                            <?php if ($payTotal !== null && $allocTotal !== null && $isCredit): ?>
                                <br><small class="text-muted fst-italic">
                                    <?= number_format($payTotal, 2) ?> paid − <?= number_format($allocTotal, 2) ?> allocated
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($refPayId): ?>
                                <a href="payment_view.php?id=<?= (int)$refPayId ?>" class="text-decoration-none fw-semibold">
                                    <?php if ($receiptNum && !is_numeric($receiptNum)): ?>
                                        #<?= h($receiptNum) ?>
                                    <?php else: ?>
                                        Receipt #<?= h($receiptNum ?: $refPayId) ?>
                                    <?php endif; ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted"><?= h($ct['reference'] ?: '—') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Refund rows inline -->
                    <?php foreach ($refundHistory as $rf): ?>
                    <tr class="table-warning bg-opacity-25">
                        <td class="small"><?= h($rf['refund_date']) ?></td>
                        <td><span class="badge bg-warning text-dark">Refund Issued</span></td>
                        <td class="text-end small text-muted">—</td>
                        <td class="text-end small text-muted">—</td>
                        <td class="text-end fw-bold text-danger">
                            −<?= number_format((float)$rf['amount'], 2) ?> AED
                        </td>
                        <td class="small">
                            <strong><?= h(ucfirst(str_replace('_', ' ', $rf['payment_method']))) ?></strong>
                            <?php if ($rf['reference_number']): ?>
                                · <?= h($rf['reference_number']) ?>
                            <?php endif; ?>
                            <?php if ($rf['refund_reason']): ?>
                                <br><small class="text-muted"><?= h($rf['refund_reason']) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($creditHistory) && empty($refundHistory)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">No credit transactions found for this lease.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php
                $totalCreditIn  = array_sum(array_map(fn($c) => $c['type']==='credit' ? (float)$c['amount_aed'] : 0, $creditHistory));
                $totalCreditOut = array_sum(array_map(fn($c) => $c['type']==='debit'  ? (float)$c['amount_aed'] : 0, $creditHistory));
                $totalRefunded  = array_sum(array_map(fn($r) => (float)$r['amount'], $refundHistory));
                if (!empty($creditHistory) || !empty($refundHistory)):
                ?>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end small">Summary:</td>
                        <td class="text-end small">
                            <span class="text-info">+<?= number_format($totalCreditIn, 2) ?> in</span>
                            &nbsp;/&nbsp;
                            <span class="text-danger">−<?= number_format($totalCreditOut + $totalRefunded, 2) ?> out</span>
                        </td>
                        <td class="small text-muted">Current balance: <strong class="text-info"><?= number_format($tenantCreditBal, 2) ?> AED</strong></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

    </div>
</div>
<?php endif; ?>

<!-- ── Correct Credit Balance Modal ──────────────────────────────────── -->
<div class="modal fade" id="fixCreditModal" tabindex="-1" aria-labelledby="fixCreditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="fix_credit_balance" value="1">
                <div class="modal-header bg-danger bg-opacity-10">
                    <h5 class="modal-title text-danger" id="fixCreditModalLabel">
                        <i class="bi bi-wrench me-1"></i> Correct Credit Balance
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Audit summary -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="card text-center p-2 bg-light">
                                <div class="small text-muted">Current Stored Balance</div>
                                <div class="fw-bold text-warning fs-5"><?= number_format($tenantCreditBal, 2) ?> AED</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="card text-center p-2 bg-light">
                                <div class="small text-muted">Computed (Payments − Allocated)</div>
                                <div class="fw-bold text-success fs-5"><?= number_format($auditTrueCredit, 2) ?> AED</div>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>When to use this:</strong> If the credit balance was created incorrectly (e.g. from re-migration, wrong payment amounts, or split-payment artifacts), set the correct value here.
                        <br>• Set to <strong>0</strong> if the tenant owes no credit (all payments matched their installments)
                        <br>• Set to <strong><?= number_format($auditTrueCredit, 2) ?> AED</strong> to use the mathematically computed value
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Set Credit Balance To (AED) *</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" class="form-control form-control-lg fw-bold"
                                   name="credit_override_amount" id="creditOverrideAmt"
                                   value="0.00" required>
                            <span class="input-group-text">AED</span>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    onclick="document.getElementById('creditOverrideAmt').value='0.00'">
                                Set to 0.00
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success"
                                    onclick="document.getElementById('creditOverrideAmt').value='<?= number_format($auditTrueCredit, 2, '.', '') ?>'">
                                Use Computed (<?= number_format($auditTrueCredit, 2) ?>)
                            </button>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Reason for Correction *</label>
                        <input type="text" class="form-control" name="credit_override_reason"
                               value="Credit balance correction — payment re-migration artifact"
                               placeholder="Explain why this correction is needed" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"
                            onclick="return confirm('This will permanently change the credit balance to ' + document.getElementById('creditOverrideAmt').value + ' AED. Are you sure?')">
                        <i class="bi bi-save me-1"></i> Save Correction
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Issue Refund Modal ─────────────────────────────────────────────── -->
<?php if ($tenantCreditBal > 0): ?>
<div class="modal fade" id="issueRefundModal" tabindex="-1" aria-labelledby="issueRefundModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="issueRefundForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="issue_refund" value="1">
                <div class="modal-header bg-warning bg-opacity-25">
                    <h5 class="modal-title" id="issueRefundModalLabel">
                        <i class="bi bi-arrow-return-left me-1"></i> Issue Refund to Tenant
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Available credit balance: <strong><?= number_format($tenantCreditBal, 2) ?> AED</strong>
                        <br>This will deduct from the tenant's credit and post an accounting journal.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Refund Amount (AED) *</label>
                            <input type="number" step="0.01" min="0.01" max="<?= number_format($tenantCreditBal, 2, '.', '') ?>"
                                   class="form-control" name="refund_amount" id="refundAmountInput"
                                   value="<?= number_format($tenantCreditBal, 2, '.', '') ?>" required>
                            <div class="form-text">Max: <?= number_format($tenantCreditBal, 2) ?> AED</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Refund Date *</label>
                            <input type="date" class="form-control" name="refund_date"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Method *</label>
                            <select class="form-select" name="refund_method" id="refundMethodSel" required>
                                <option value="bank_transfer" selected>Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="cash_deposit">Cash Deposit</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="refundBankRow">
                            <label class="form-label fw-semibold">Bank Account *</label>
                            <select class="form-select" name="refund_bank_account_id" id="refundBankSel">
                                <option value="">-- Select Bank Account --</option>
                                <?php foreach ($bankAccountsForRefund as $ba): ?>
                                <option value="<?= $ba['id'] ?>">
                                    <?= h($ba['account_code'] . ' — ' . $ba['account_name']) ?>
                                    <?php if ($ba['bank_name']): ?>(<?= h($ba['bank_name']) ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reference / Transaction # </label>
                            <input type="text" class="form-control" name="refund_reference" placeholder="e.g. TRF-20260313">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Receipt Number</label>
                            <input type="text" class="form-control" name="refund_receipt" placeholder="Optional">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Reason for Refund *</label>
                            <input type="text" class="form-control" name="refund_reason"
                                   placeholder="e.g. Duplicate payment return, Parking fee refund" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Internal Note <small class="text-muted">(optional)</small></label>
                            <textarea class="form-control form-control-sm" name="refund_note" rows="2"
                                      placeholder="Any additional details for internal records"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-arrow-return-left me-1"></i> Confirm &amp; Record Refund
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function(){
    var methodSel = document.getElementById('refundMethodSel');
    var bankRow   = document.getElementById('refundBankRow');
    var bankSel   = document.getElementById('refundBankSel');
    function toggleBank() {
        var m = methodSel ? methodSel.value : '';
        var show = (m === 'bank_transfer' || m === 'cash_deposit' || m === 'cheque');
        if (bankRow) bankRow.style.display = show ? '' : 'none';
        if (bankSel) bankSel.required = show;
    }
    if (methodSel) { methodSel.addEventListener('change', toggleBank); toggleBank(); }
})();
</script>
<?php endif; ?>

<!-- ── Edit Penalty Amount Modal ──────────────────────────────────────── -->
<div class="modal fade" id="editPenaltyModal" tabindex="-1" aria-labelledby="editPenaltyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="post" id="editPenaltyForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="edit_penalty_id" id="editPenaltyId">
                <div class="modal-header">
                    <h5 class="modal-title" id="editPenaltyModalLabel">
                        <i class="bi bi-pencil-square me-1"></i> Edit Penalty Amount
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Amount (AED)</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="edit_penalty_amount" id="editPenaltyAmount" required>
                        <div class="form-text text-muted">Enter the adjusted amount (e.g., after a discount agreed with the tenant).</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Reason / Note <small class="text-muted">(optional)</small></label>
                        <input type="text" class="form-control form-control-sm" name="edit_penalty_note" placeholder="e.g. Discount agreed on 13-Mar-2026">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="bi bi-save"></i> Save Amount
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function openEditPenalty(id, currentAmount) {
    document.getElementById('editPenaltyId').value     = id;
    document.getElementById('editPenaltyAmount').value = currentAmount;
    var modal = new bootstrap.Modal(document.getElementById('editPenaltyModal'));
    modal.show();
}
</script>

        <!-- Move-In/Out Operations -->
        <?php
        $stmt = $conn->prepare("
            SELECT * FROM re_move_operations 
            WHERE lease_id = ? 
            ORDER BY operation_type, operation_date DESC
        ");
        $stmt->execute([$leaseId]);
        $moveOperations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (!empty($moveOperations)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Move-In/Out Operations</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($moveOperations as $op): ?>
                                <tr>
                                    <td>
                                        <?php if ($op['operation_type'] === 'move_in'): ?>
                                            <span class="badge bg-primary">Move-In</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Move-Out</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('Y-m-d', strtotime($op['operation_date'])) ?></td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'pending' => 'warning',
                                            'in_progress' => 'info',
                                            'completed' => 'success',
                                            'cancelled' => 'secondary'
                                        ];
                                        $class = $statusClass[$op['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?= $class ?>"><?= ucfirst($op['status']) ?></span>
                                    </td>
                                    <td>
                                        <a href="<?= $op['operation_type'] === 'move_in' ? 'move_in' : 'move_out' ?>.php?lease_id=<?= $leaseId ?>" class="btn btn-sm btn-outline-primary">
                                            View/Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Renewal Terms -->
        <?php if (!empty($lease['renewal_terms'])): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Renewal Terms</h5>
            </div>
            <div class="card-body">
                <p><?= nl2br(h($lease['renewal_terms'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notes -->
        <?php if ($lease['notes']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Notes</h5>
            </div>
            <div class="card-body">
                <p><?= nl2br(h($lease['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Renewal Workflow Section -->
        <?php
        $stmt = $conn->prepare("
            SELECT rw.*, u2.username as assigned_to_name
            FROM re_lease_renewal_workflows rw
            LEFT JOIN user u2 ON u2.id = rw.assigned_to
            WHERE rw.lease_id = ?
            ORDER BY rw.initiated_date DESC
            LIMIT 1
        ");
        $stmt->execute([$leaseId]);
        $renewalWorkflow = $stmt->fetch(PDO::FETCH_ASSOC);
        ?>
        <?php if ($renewalWorkflow): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>Renewal Workflow</h5>
                <a href="lease_renewal_workflow_view.php?id=<?= $renewalWorkflow['id'] ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-eye"></i> View Details
                </a>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <strong>Status:</strong><br>
                        <?php
                        $stepLabels = [
                            'initiated' => 'Initiated',
                            'terms_reviewed' => 'Terms Reviewed',
                            'tenant_notified' => 'Tenant Notified',
                            'tenant_response' => 'Tenant Responded',
                            'terms_negotiated' => 'Terms Negotiated',
                            'renewal_approved' => 'Renewal Approved',
                            'new_lease_created' => 'New Lease Created',
                            'completed' => 'Completed',
                            'cancelled' => 'Cancelled'
                        ];
                        $stepColors = [
                            'initiated' => 'info',
                            'terms_reviewed' => 'primary',
                            'tenant_notified' => 'warning',
                            'tenant_response' => 'success',
                            'terms_negotiated' => 'info',
                            'renewal_approved' => 'success',
                            'new_lease_created' => 'success',
                            'completed' => 'success',
                            'cancelled' => 'danger'
                        ];
                        $color = $stepColors[$renewalWorkflow['workflow_step']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $color ?>"><?= $stepLabels[$renewalWorkflow['workflow_step']] ?? ucfirst($renewalWorkflow['workflow_step']) ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Initiated:</strong><br>
                        <?= date('Y-m-d', strtotime($renewalWorkflow['initiated_date'])) ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Assigned To:</strong><br>
                        <?= h($renewalWorkflow['assigned_to_name'] ?? '-') ?>
                    </div>
                    <div class="col-md-3">
                        <?php if ($renewalWorkflow['new_lease_id']): ?>
                            <strong>New Lease:</strong><br>
                            <a href="lease_view.php?id=<?= $renewalWorkflow['new_lease_id'] ?>" class="btn btn-sm btn-outline-primary">
                                View New Lease
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($lease['status'] === 'active' && strtotime($lease['end_date']) <= strtotime('+60 days')): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Renewal Workflow</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">No renewal workflow initiated yet.</p>
                <a href="lease_renewal_workflow.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Initiate Renewal Workflow
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Generate Contract Section -->
        <?php
        // Get templates for this lease
        $templates = $conn->prepare("
            SELECT id, template_name, template_type 
            FROM re_contract_templates 
            WHERE company_id = ? AND is_active = 1 
            ORDER BY is_default DESC, template_name
        ");
        $templates->execute([$currentCompanyId]);
        $templates = $templates->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (!empty($templates)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5>Generate Contract</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">Generate a contract document from a template with all lease information auto-filled.</p>
                <div class="btn-group">
                    <?php foreach ($templates as $tpl): ?>
                        <a href="lease_contract_generate.php?lease_id=<?= $leaseId ?>&template_id=<?= $tpl['id'] ?>" 
                           class="btn btn-outline-primary" target="_blank">
                            <i class="bi bi-file-earmark-text"></i> Generate: <?= h($tpl['template_name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Documents Section -->
        <?php
        require_once __DIR__ . '/includes/document_manager.php';
        render_document_manager('lease', $leaseId, $currentCompanyId);
        ?>

<script>
function changeLeaseStatus(leaseId, newStatus) {
    const labels = {
        draft: 'Draft',
        active: 'Active',
        expired: 'Expired',
        terminated: 'Terminated',
        renewed: 'Renewed',
        has_legal_case: 'Has Legal Case'
    };
    const label = labels[newStatus] || newStatus;
    let msg = 'This will change the lease status to "' + label + '".';
    if (newStatus === 'has_legal_case') {
        msg += '\n\nThe unit will become available for a new lease booking. This lease stays on file for legal/AR follow-up (it is not a full termination).';
    }
    msg += '\n\nType CHANGE to continue.';
    const confirmation = prompt(msg);
    if (confirmation !== 'CHANGE') {
        return;
    }
    
    fetch('ajax_change_lease_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'lease_id=' + leaseId + '&status=' + encodeURIComponent(newStatus) + '&confirm_text=' + encodeURIComponent(confirmation) + '&_csrf=' + encodeURIComponent('<?= csrf_token() ?>')
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Lease status updated successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to update status'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

function sendContractEmail(leaseId) {
    if (!confirm('Send the contract PDF to the tenant\'s email address?')) {
        return;
    }
    
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending...';
    
    fetch('ajax_send_contract_email.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'lease_id=' + leaseId + '&_csrf=' + encodeURIComponent('<?= csrf_token() ?>')
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Contract sent successfully to ' + (data.email || 'tenant') + '!');
        } else {
            alert('Error: ' + (data.error || 'Failed to send email'));
        }
        btn.disabled = false;
        btn.innerHTML = originalText;
    })
    .catch(error => {
        alert('Error: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

