<?php
/**
 * Lease Payment Manager
 * Professional UI for viewing, editing, deleting, and re-linking payments on a lease.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/accounting/accounting_engine.php';
require_once __DIR__ . '/accounting/accounting_integration.php';
require_once __DIR__ . '/includes/payment_allocation_helper.php';
require_once __DIR__ . '/includes/accounting_mode_helper.php';
require_once __DIR__ . '/includes/security_deposit_helper.php';
require_once __DIR__ . '/includes/lease_payment_manager_im_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand             = getBrandSettings($conn);
$currentCompanyId  = (int)(current_company_id($conn) ?: 0);
$userId            = current_user_id();
if ($currentCompanyId <= 0) {
    $_SESSION['error'] = 'Company context is required for Payment Manager.';
    header('Location: leases.php');
    exit;
}

function h($s)     { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($v) { return number_format((float)$v, 2); }

$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;
$success = '';
$error   = '';

if (!$leaseId) {
    header('Location: leases.php');
    exit;
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function recalcInstallmentStatus(PDO $conn, int $installmentId): void {
    $s = $conn->prepare("SELECT amount, payment_id FROM re_lease_installments WHERE id = ?");
    $s->execute([$installmentId]);
    $inst = $s->fetch(PDO::FETCH_ASSOC);
    if (!$inst) return;

    // Sum payments linked via new-style (payment.installment_id) OR legacy-style (installment.payment_id)
    // DISTINCT on id prevents double-counting when both columns are set on the same payment
    $legacyPayId = (int)($inst['payment_id'] ?? 0);
    $s = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM (
            SELECT DISTINCT p.id, p.amount
            FROM re_payments p
            WHERE p.installment_id = ?
               OR (? > 0 AND p.id = ? AND p.installment_id IS NULL)
        ) t
    ");
    $s->execute([$installmentId, $legacyPayId, $legacyPayId]);
    $paid = (float)$s->fetchColumn();
    $due  = (float)$inst['amount'];

    $status = $paid >= $due ? 'paid' : ($paid > 0 ? 'partial' : 'pending');
    $conn->prepare("
        UPDATE re_lease_installments
        SET status = ?,
            paid_at = CASE WHEN ? = 'paid' THEN COALESCE(paid_at, NOW()) ELSE NULL END
        WHERE id = ?
    ")->execute([$status, $status, $installmentId]);
    sync_pdc_status_for_paid_installment($conn, $installmentId);
}

// ── Load lease ─────────────────────────────────────────────────────────────────

$stmt = $conn->prepare("
    SELECT l.*, t.first_name, t.last_name, t.phone,
           u.unit_number, u.unit_type,
           b.name AS building_name
    FROM re_leases l
    JOIN re_tenants t ON t.id = l.tenant_id
    JOIN re_units   u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE l.id = ? AND l.company_id = ?
");
$stmt->execute([$leaseId, $currentCompanyId]);
$lease = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lease) {
    header('Location: leases.php');
    exit;
}
$accountingMode = re_accounting_mode_for_existing_lease($conn, $lease);
$isInvoiceMode = ($accountingMode === 'invoice');
$companyName = '';
try {
    $cStmt = $conn->prepare("SELECT name FROM companies WHERE id = ? LIMIT 1");
    $cStmt->execute([$currentCompanyId]);
    $companyName = (string)($cStmt->fetchColumn() ?: '');
} catch (Throwable $e) {
    $companyName = '';
}

// ── Handle POST actions ────────────────────────────────────────────────────────
// Payment Manager is read-only for both modes: IM uses Receipt Allocation;
// Legacy is Historical (no mutate).

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['delete_payment', 'fix_links', 'relink_payment', 'split_payment'], true)) {
        $error = $isInvoiceMode
            ? 'Invoice Mode receipts must be managed through Receipt Allocation / reversal workflows, not Payment Manager actions.'
            : 'Legacy Payment Manager is historical/read-only. Mutating payment links is disabled.';
        $action = '';
    }

    // ── Delete payment ──────────────────────────────────────────────────────
    if ($action === 'delete_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if ($paymentId) {
            try {
                $conn->beginTransaction();

                // Confirm payment belongs to this lease
                $s = $conn->prepare("SELECT installment_id FROM re_payments WHERE id = ? AND lease_id = ? AND company_id = ?");
                $s->execute([$paymentId, $leaseId, $currentCompanyId]);
                $pay = $s->fetch(PDO::FETCH_ASSOC);
                if (!$pay) throw new Exception("Payment not found or access denied.");

                $installmentId = $pay['installment_id'];

                // Reverse the accounting journal for this payment before deleting
                $journalStmt = $conn->prepare("
                    SELECT id FROM re_journal_headers
                    WHERE company_id = ? AND reference_type IN ('payment','deferred_payment')
                      AND reference_id = ? AND is_posted = 1 AND (is_reversed = 0 OR is_reversed IS NULL)
                      AND journal_type != 'reversal'
                    ORDER BY id DESC LIMIT 1
                ");
                $journalStmt->execute([$currentCompanyId, $paymentId]);
                $originalJournal = $journalStmt->fetch(PDO::FETCH_ASSOC);

                $accountingNote = '';
                if ($originalJournal) {
                    $reverseResult = reverse_journal(
                        $originalJournal['id'],
                        "Reversal — payment #{$paymentId} deleted",
                        $userId,
                        null
                    );
                    if (!$reverseResult['success']) {
                        $accountingNote = ' Note: accounting journal could not be reversed automatically (' . $reverseResult['error'] . '). Please reverse manually from Journal Entries.';
                    } else {
                        // Also reset any pending recognition schedule rows funded by this payment
                        try {
                            $conn->prepare("
                                DELETE FROM re_rent_recognition_schedule
                                WHERE deferred_payment_id = ? AND status = 'pending' AND company_id = ?
                            ")->execute([$paymentId, $currentCompanyId]);
                        } catch (Throwable $e) { /* ignore */ }
                    }
                }

                // Remove from allocation and credit tables
                $billingItemIdsToRecalc = [];
                try {
                    $sBilling = $conn->prepare("SELECT billing_item_id FROM re_billing_item_payment_allocations WHERE payment_id = ?");
                    $sBilling->execute([$paymentId]);
                    $billingItemIdsToRecalc = array_map('intval', $sBilling->fetchAll(PDO::FETCH_COLUMN));
                } catch (Throwable $e) {
                    $sBilling = $conn->prepare("SELECT id FROM re_billing_items WHERE payment_id = ? AND company_id = ?");
                    $sBilling->execute([$paymentId, $currentCompanyId]);
                    $billingItemIdsToRecalc = array_map('intval', $sBilling->fetchAll(PDO::FETCH_COLUMN));
                }
                foreach (['re_payment_allocations', 're_tenant_credit_transactions'] as $tbl) {
                    try { $conn->prepare("DELETE FROM $tbl WHERE payment_id = ?")->execute([$paymentId]); }
                    catch (Exception $e) { /* table may not exist */ }
                }
                try { $conn->prepare("DELETE FROM re_billing_item_payment_allocations WHERE payment_id = ?")->execute([$paymentId]); }
                catch (Throwable $e) { /* table may not exist */ }
                $conn->prepare("
                    UPDATE re_billing_items
                    SET payment_id = NULL, paid_amount = 0, paid_date = NULL, is_paid = 0,
                        status = CASE WHEN due_date < CURDATE() THEN 'overdue' ELSE 'pending' END
                    WHERE payment_id = ? AND company_id = ?
                ")->execute([$paymentId, $currentCompanyId]);

                // Delete the payment
                $conn->prepare("DELETE FROM re_payments WHERE id = ? AND company_id = ?")
                     ->execute([$paymentId, $currentCompanyId]);

                // Recalculate installment
                if ($installmentId) recalcInstallmentStatus($conn, $installmentId);
                foreach (array_unique($billingItemIdsToRecalc) as $biId) {
                    update_billing_item_status_from_allocations($conn, $biId);
                }

                $conn->commit();
                $success = "Payment #$paymentId deleted and installment status recalculated." . $accountingNote;
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Could not delete: " . $e->getMessage();
            }
        }

    // ── Auto-fix: sync legacy installment.payment_id → payment.installment_id ──
    } elseif ($action === 'fix_links') {
        try {
            $conn->beginTransaction();

            // Find payments where installment_id is NULL but the installment
            // still references them via re_lease_installments.payment_id (old mechanism)
            $rows = $conn->prepare("
                SELECT li.id AS inst_id, li.payment_id AS pay_id
                FROM re_lease_installments li
                JOIN re_payments p ON p.id = li.payment_id
                WHERE li.lease_id = ?
                  AND p.lease_id  = ?
                  AND p.company_id = ?
                  AND p.installment_id IS NULL
            ");
            $rows->execute([$leaseId, $leaseId, $currentCompanyId]);
            $toFix = $rows->fetchAll(PDO::FETCH_ASSOC);

            $fixed = 0;
            foreach ($toFix as $row) {
                $conn->prepare("
                    UPDATE re_payments SET installment_id = ? WHERE id = ? AND company_id = ?
                ")->execute([$row['inst_id'], $row['pay_id'], $currentCompanyId]);
                recalcInstallmentStatus($conn, $row['inst_id']);
                $fixed++;
            }

            // Also handle payments from re_payment_allocations table (allocation-based linking)
            try {
                $allocRows = $conn->prepare("
                    SELECT DISTINCT pa.payment_id, pa.installment_id
                    FROM re_payment_allocations pa
                    JOIN re_payments p ON p.id = pa.payment_id
                    WHERE p.lease_id = ? AND p.company_id = ? AND p.installment_id IS NULL
                      AND pa.installment_id IS NOT NULL
                ");
                $allocRows->execute([$leaseId, $currentCompanyId]);
                foreach ($allocRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $conn->prepare("
                        UPDATE re_payments SET installment_id = ? WHERE id = ? AND company_id = ?
                    ")->execute([$row['installment_id'], $row['payment_id'], $currentCompanyId]);
                    recalcInstallmentStatus($conn, (int)$row['installment_id']);
                    $fixed++;
                }
            } catch (Exception $e) { /* re_payment_allocations may not exist */ }

            $conn->commit();
            $success = $fixed > 0
                ? "Fixed $fixed payment link(s). All installment statuses have been recalculated."
                : "No broken links found — all payments are already correctly linked.";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Could not fix links: " . $e->getMessage();
        }

    // ── Re-link payment to a different installment ──────────────────────────
    } elseif ($action === 'relink_payment') {
        $paymentId        = (int)($_POST['payment_id'] ?? 0);
        $newInstallmentId = !empty($_POST['new_installment_id']) ? (int)$_POST['new_installment_id'] : null;

        if ($paymentId) {
            try {
                $conn->beginTransaction();

                $s = $conn->prepare("SELECT installment_id FROM re_payments WHERE id = ? AND lease_id = ? AND company_id = ?");
                $s->execute([$paymentId, $leaseId, $currentCompanyId]);
                $pay = $s->fetch(PDO::FETCH_ASSOC);
                if (!$pay) throw new Exception("Payment not found.");

                $oldInstallmentId = $pay['installment_id'];

                // Update payment link
                $conn->prepare("UPDATE re_payments SET installment_id = ? WHERE id = ? AND company_id = ?")
                     ->execute([$newInstallmentId, $paymentId, $currentCompanyId]);

                // Update allocations table if it exists
                try {
                    $conn->prepare("DELETE FROM re_payment_allocations WHERE payment_id = ?")->execute([$paymentId]);
                    if ($newInstallmentId) {
                        $s2 = $conn->prepare("SELECT amount FROM re_payments WHERE id = ?");
                        $s2->execute([$paymentId]);
                        $payAmt = (float)($s2->fetchColumn() ?: 0);
                        $conn->prepare("INSERT INTO re_payment_allocations (company_id, payment_id, installment_id, amount_allocated) VALUES (?, ?, ?, ?)")
                             ->execute([$currentCompanyId, $paymentId, $newInstallmentId, $payAmt]);
                    }
                } catch (Exception $e) { /* ignore */ }

                // Recalculate both old and new installments
                if ($oldInstallmentId) update_installment_status_from_allocations($conn, $oldInstallmentId);
                if ($newInstallmentId) update_installment_status_from_allocations($conn, $newInstallmentId);

                $conn->commit();
                $success = "Payment re-linked successfully.";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Could not re-link: " . $e->getMessage();
            }
        }

    } elseif ($action === 'split_payment') {
        // Distribute one payment across multiple installments
        $paymentId   = (int)($_POST['payment_id'] ?? 0);
        $allocations = is_array($_POST['allocation'] ?? null) ? $_POST['allocation'] : [];
        $billingAllocations = is_array($_POST['billing_alloc'] ?? null) ? $_POST['billing_alloc'] : [];

        if ($paymentId) {
            try {
                $conn->beginTransaction();

                $s = $conn->prepare("SELECT * FROM re_payments WHERE id = ? AND lease_id = ? AND company_id = ?");
                $s->execute([$paymentId, $leaseId, $currentCompanyId]);
                $pay = $s->fetch(PDO::FETCH_ASSOC);
                if (!$pay) throw new Exception("Payment not found.");

                $payAmount = (float)$pay['amount'];

                // Build validated allocations
                $validAllocs = [];
                $allocSum    = 0.0;
                foreach ($allocations as $instId => $amt) {
                    $amt = round((float)$amt, 2);
                    if ($amt > 0 && $instId) {
                        // Verify installment belongs to this lease
                        $chk = $conn->prepare("SELECT id FROM re_lease_installments WHERE id = ? AND lease_id = ?");
                        $chk->execute([(int)$instId, $leaseId]);
                        if (!$chk->fetch()) continue;
                        $validAllocs[(int)$instId] = $amt;
                        $allocSum += $amt;
                    }
                }

                $validBillingAllocs = [];
                $billingAllocSum = 0.0;
                foreach ($billingAllocations as $billingItemId => $amt) {
                    $amt = round((float)$amt, 2);
                    if ($amt <= 0 || !$billingItemId) {
                        continue;
                    }
                    $chk = $conn->prepare("
                        SELECT id, total_amount
                        FROM re_billing_items
                        WHERE id = ? AND lease_id = ? AND company_id = ? AND status <> 'waived'
                        LIMIT 1
                    ");
                    $chk->execute([(int)$billingItemId, $leaseId, $currentCompanyId]);
                    $bi = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$bi) {
                        continue;
                    }
                    $open = max(0, (float)$bi['total_amount'] - get_billing_item_total_paid($conn, (int)$billingItemId));
                    if ($amt > $open + 0.005) {
                        throw new Exception("Billing item allocation exceeds outstanding amount.");
                    }
                    $validBillingAllocs[(int)$billingItemId] = $amt;
                    $billingAllocSum += $amt;
                }

                if (empty($validAllocs) && empty($validBillingAllocs)) {
                    throw new Exception("Please enter at least one allocation amount.");
                }
                $totalAllocSum = $allocSum + $billingAllocSum;
                if ($totalAllocSum > $payAmount + 0.01) {
                    throw new Exception("Total allocated (" . money($totalAllocSum) . " AED) exceeds payment amount (" . money($payAmount) . " AED).");
                }

                // Collect old installment IDs for recalc
                $oldInstIds = [];
                $s2 = $conn->prepare("SELECT installment_id FROM re_payment_allocations WHERE payment_id = ?");
                $s2->execute([$paymentId]);
                foreach ($s2->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    if ($id) $oldInstIds[] = (int)$id;
                }
                if ($pay['installment_id']) $oldInstIds[] = (int)$pay['installment_id'];
                $oldBillingItemIds = [];
                try {
                    $s3 = $conn->prepare("SELECT billing_item_id FROM re_billing_item_payment_allocations WHERE payment_id = ?");
                    $s3->execute([$paymentId]);
                    $oldBillingItemIds = array_map('intval', $s3->fetchAll(PDO::FETCH_COLUMN));
                } catch (Throwable $e) {
                    $s3 = $conn->prepare("SELECT id FROM re_billing_items WHERE payment_id = ? AND company_id = ?");
                    $s3->execute([$paymentId, $currentCompanyId]);
                    $oldBillingItemIds = array_map('intval', $s3->fetchAll(PDO::FETCH_COLUMN));
                }

                // Clear existing allocations
                $conn->prepare("DELETE FROM re_payment_allocations WHERE payment_id = ?")->execute([$paymentId]);
                try { $conn->prepare("DELETE FROM re_billing_item_payment_allocations WHERE payment_id = ?")->execute([$paymentId]); }
                catch (Throwable $e) { /* older database, fallback below */ }
                $conn->prepare("
                    UPDATE re_billing_items
                    SET payment_id = NULL, paid_amount = 0, paid_date = NULL, is_paid = 0,
                        status = CASE WHEN due_date < CURDATE() THEN 'overdue' ELSE 'pending' END
                    WHERE payment_id = ? AND company_id = ?
                ")->execute([$paymentId, $currentCompanyId]);

                // Insert new allocations
                $stmt2 = $conn->prepare("INSERT INTO re_payment_allocations (company_id, payment_id, installment_id, amount_allocated) VALUES (?, ?, ?, ?)");
                foreach ($validAllocs as $instId => $amt) {
                    $stmt2->execute([$currentCompanyId, $paymentId, $instId, $amt]);
                }

                foreach ($validBillingAllocs as $billingItemId => $amt) {
                    if (billing_item_allocations_table_exists($conn)) {
                        $conn->prepare("
                            INSERT INTO re_billing_item_payment_allocations
                                (company_id, payment_id, billing_item_id, amount_allocated)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE amount_allocated = VALUES(amount_allocated)
                        ")->execute([$currentCompanyId, $paymentId, $billingItemId, $amt]);
                    } else {
                        $conn->prepare("
                            UPDATE re_billing_items
                            SET paid_amount = COALESCE(paid_amount, 0) + ?,
                                payment_id = COALESCE(payment_id, ?)
                            WHERE id = ? AND company_id = ?
                        ")->execute([$amt, $paymentId, $billingItemId, $currentCompanyId]);
                    }
                    update_billing_item_status_from_allocations($conn, $billingItemId, $paymentId, $pay['payment_date']);
                }

                // Set installment_id on payment: single target = direct link; multi = null (split)
                $primaryInstId = count($validAllocs) === 1 ? array_key_first($validAllocs) : null;
                $conn->prepare("UPDATE re_payments SET installment_id = ? WHERE id = ?")->execute([$primaryInstId, $paymentId]);

                // Credit any remainder to tenant balance
                $overpayment = round($payAmount - $totalAllocSum, 2);
                if ($overpayment > 0.005) {
                    $leaseRow = $conn->prepare("SELECT tenant_id FROM re_leases WHERE id = ? AND company_id = ?");
                    $leaseRow->execute([$leaseId, $currentCompanyId]);
                    $leaseData = $leaseRow->fetch(PDO::FETCH_ASSOC);
                    if ($leaseData) {
                        update_tenant_credit($conn, (int)$leaseData['tenant_id'], $currentCompanyId,
                            $overpayment, 'credit', $paymentId, null,
                            'Remainder from split allocation of payment #' . $paymentId);
                    }
                }

                $conn->commit();

                // Recalculate statuses
                $allInstIds = array_unique(array_merge($oldInstIds, array_keys($validAllocs)));
                foreach ($allInstIds as $iid) {
                    if ($iid) update_installment_status_from_allocations($conn, $iid);
                }
                foreach (array_unique(array_merge($oldBillingItemIds, array_keys($validBillingAllocs))) as $biId) {
                    if ($biId) update_billing_item_status_from_allocations($conn, $biId, $paymentId, $pay['payment_date']);
                }

                $count = count($validAllocs);
                $success = "Payment #" . h($pay['receipt_number'] ?: $paymentId) . " successfully allocated to {$count} installment(s).";

            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Could not allocate payment: " . $e->getMessage();
            }
        }
    }
}

// ── Load data (IM overview vs Legacy historical read) ──────────────────────────

$imOverview = null;
$installments = [];
$serviceItems = [];
$allPayments = [];
$paymentsByInst = [];
$unlinkedPayments = [];
$needsFixCount = 0;
$totalDue = 0.0;
$totalCollected = 0.0;
$totalOutstanding = 0.0;
$totalServiceDue = 0.0;
$totalServiceCollected = 0.0;
$totalServiceOutstanding = 0.0;
$collectibleInstallments = [];
$collectibleServices = [];

if ($isInvoiceMode) {
    $imOverview = re_pm_im_overview(
        $conn,
        $currentCompanyId,
        (int)$leaseId,
        (int)($lease['tenant_id'] ?? 0)
    );
} else {
    $stmt = $conn->prepare("
        SELECT id, installment_date, amount, status, notes
        FROM re_lease_installments
        WHERE lease_id = ?
        ORDER BY installment_date ASC, id ASC
    ");
    $stmt->execute([$leaseId]);
    $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        $stmt = $conn->prepare("
            SELECT bi.*, sc.charge_name AS service_name, sc.is_active AS service_active
            FROM re_billing_items bi
            LEFT JOIN re_service_charges sc ON sc.id = bi.service_charge_id
            WHERE bi.lease_id = ?
              AND bi.company_id = ?
              AND bi.item_type = 'service_charge'
            ORDER BY bi.due_date ASC, bi.id ASC
        ");
        $stmt->execute([$leaseId, $currentCompanyId]);
        $serviceItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($serviceItems as &$svc) {
            $paid = get_billing_item_total_paid($conn, (int)$svc['id']);
            $svc['collected'] = $paid;
            $svc['outstanding'] = (($svc['status'] ?? '') === 'waived')
                ? 0.0
                : max(0, (float)$svc['total_amount'] - $paid);
            if (($svc['status'] ?? '') === 'waived') {
                $svc['status'] = 'waived';
            } elseif ($svc['outstanding'] <= 0.005 && (float)$svc['total_amount'] > 0) {
                $svc['status'] = 'paid';
            } elseif ($paid > 0) {
                $svc['status'] = 'partial';
            } elseif (!empty($svc['due_date']) && $svc['due_date'] < date('Y-m-d')) {
                $svc['status'] = 'overdue';
            }
        }
        unset($svc);
    } catch (Throwable $e) {
        $serviceItems = [];
    }

    $stmt = $conn->prepare("
        SELECT p.id, p.payment_date, p.amount, p.payment_method,
               p.reference_number, p.receipt_number, p.notes,
               p.installment_id AS direct_installment_id,
               p.created_at,
               ba.account_name AS bank_account_name, ba.bank_name,
               u2.username AS recorded_by,
               li_legacy.id AS legacy_installment_id
        FROM re_payments p
        LEFT JOIN re_bank_accounts ba ON ba.id = p.bank_account_id AND ba.company_id = p.company_id
        LEFT JOIN user u2 ON u2.id = p.created_by
        LEFT JOIN re_lease_installments li_legacy
               ON li_legacy.payment_id = p.id AND li_legacy.lease_id = p.lease_id
        WHERE p.lease_id = ? AND p.company_id = ?
        ORDER BY p.payment_date ASC, p.id ASC
    ");
    $stmt->execute([$leaseId, $currentCompanyId]);
    $allPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allPayments as &$p) {
        $p['installment_id'] = $p['direct_installment_id'] ?: ($p['legacy_installment_id'] ?: null);
    }
    unset($p);

    $allocationAmounts = [];
    try {
        $paymentIds = array_column($allPayments, 'id');
        if (!empty($paymentIds)) {
            $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
            $s = $conn->prepare("
                SELECT payment_id, installment_id, amount_allocated
                FROM re_payment_allocations
                WHERE payment_id IN ($placeholders)
            ");
            $s->execute($paymentIds);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $allocationAmounts[(int)$row['payment_id']][(int)$row['installment_id']] = (float)$row['amount_allocated'];
            }
        }
    } catch (Throwable $e) {
    }

    foreach ($allPayments as &$p) {
        $pid = (int)$p['id'];
        $iid = (int)($p['installment_id'] ?? 0);
        if ($iid && isset($allocationAmounts[$pid][$iid])) {
            $p['amount_for_installment'] = $allocationAmounts[$pid][$iid];
        } else {
            $p['amount_for_installment'] = (float)$p['amount'];
        }
    }
    unset($p);

    foreach ($allPayments as $p) {
        $pid = (int)$p['id'];
        $iid = (int)($p['installment_id'] ?? 0);
        $hasAllocations = isset($allocationAmounts[$pid]) && !empty($allocationAmounts[$pid]);

        if ($hasAllocations) {
            $isMultiSplit = count($allocationAmounts[$pid]) > 1;
            foreach ($allocationAmounts[$pid] as $allocInstId => $allocAmt) {
                $pCopy = $p;
                $pCopy['amount_for_installment'] = $allocAmt;
                $pCopy['is_split'] = $isMultiSplit;
                $paymentsByInst[$allocInstId][] = $pCopy;
            }
        } elseif ($iid) {
            $paymentsByInst[$iid][] = $p;
        } else {
            $unlinkedPayments[] = $p;
        }
    }

    foreach ($installments as &$inst) {
        $pmts = $paymentsByInst[(int)$inst['id']] ?? [];
        $inst['collected'] = array_sum(array_column($pmts, 'amount_for_installment'));
        $inst['outstanding'] = (($inst['status'] ?? '') === 'cancelled')
            ? 0
            : max(0, (float)$inst['amount'] - $inst['collected']);
        $inst['payments'] = $pmts;

        $due = (float)$inst['amount'];
        $col = $inst['collected'];
        if (($inst['status'] ?? '') === 'cancelled') {
            $inst['status'] = 'cancelled';
        } elseif ($col <= 0) {
            $inst['status'] = (strtotime($inst['installment_date']) < time()) ? 'overdue' : 'pending';
        } elseif ($col >= $due) {
            $inst['status'] = 'paid';
        } else {
            $inst['status'] = 'partial';
        }
    }
    unset($inst);

    $collectibleInstallments = array_values(array_filter($installments, fn($i) => ($i['status'] ?? '') !== 'cancelled'));
    $collectibleServices = array_values(array_filter($serviceItems, fn($i) => ($i['status'] ?? '') !== 'waived'));
    $totalServiceDue = array_sum(array_column($collectibleServices, 'total_amount'));
    $totalServiceCollected = array_sum(array_column($collectibleServices, 'collected'));
    $totalServiceOutstanding = array_sum(array_column($collectibleServices, 'outstanding'));
    $totalDue = array_sum(array_column($collectibleInstallments, 'amount')) + $totalServiceDue;
    $totalCollected = array_sum(array_column($allPayments, 'amount'));
    $totalOutstanding = max(0, (array_sum(array_column($collectibleInstallments, 'outstanding')) + $totalServiceOutstanding));
}

$csrfToken = csrf_token();

$pageTitle = 'Payment Manager – ' . h($lease['lease_number'] ?: 'Lease #' . $leaseId);
require_once __DIR__ . '/includes/re_layout_header.php';

$imKpis = $imOverview['kpis'] ?? [];
$imInvoices = $imOverview['invoices'] ?? ['rent' => [], 'service' => [], 'lease_service' => [], 'extra_service' => [], 'other' => [], 'deposit' => [], 'totals' => [], 'truncated' => false];
$imReceiptsBundle = $imOverview['receipts'] ?? ['receipts' => [], 'allocations' => []];
$imDeposit = $imOverview['deposit'] ?? [];
$imStatusBadge = static function (string $status): string {
    return match ($status) {
        'paid' => 'success',
        'partial' => 'info',
        'overdue' => 'danger',
        'future' => 'secondary',
        'sent', 'issued', 'open' => 'warning',
        'cancelled' => 'dark',
        default => 'secondary',
    };
};
$imTargetBadge = static function (string $type): string {
    return match ($type) {
        'invoice' => 'primary',
        'obligation' => 'warning',
        'tenant_credit' => 'info',
        default => 'secondary',
    };
};
?>

<style>
.inst-card { border-left: 4px solid #dee2e6; }
.inst-card.status-paid     { border-left-color: #198754; }
.inst-card.status-partial  { border-left-color: #ffc107; }
.inst-card.status-pending  { border-left-color: #6c757d; }
.inst-card.status-overdue  { border-left-color: #dc3545; }
.payment-row:hover { background: #f8f9fa; }
.badge-method { font-size: .75rem; }
.unlinked-section { border: 2px dashed #dc3545; border-radius: .5rem; }
.pm-kpi .card-body { min-height: 5.5rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="mb-0">
            <i class="bi bi-cash-stack text-primary"></i>
            Payment Manager
            <?php if ($isInvoiceMode): ?>
                <span class="badge bg-primary align-middle fs-6">Invoice Mode</span>
            <?php else: ?>
                <span class="badge bg-secondary align-middle fs-6">Legacy — Historical</span>
            <?php endif; ?>
        </h1>
        <p class="text-muted mb-0">
            <?= h($lease['lease_number']) ?> &mdash;
            <?= h($lease['building_name'] . ' – ' . $lease['unit_number']) ?> &mdash;
            <?= h(trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''))) ?>
            <?php if ($companyName !== ''): ?>
                <span class="ms-1">· <?= h($companyName) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap w-100 w-md-auto justify-content-md-end">
        <?php if ($isInvoiceMode): ?>
            <a href="accounting/receipt_allocation.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-primary w-100 w-md-auto">
                <i class="bi bi-diagram-3"></i> Allocate Receipt
            </a>
            <a href="accounting/invoice_preview.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-outline-primary w-100 w-md-auto">
                <i class="bi bi-receipt"></i> Invoice Candidates
            </a>
            <a href="accounting/security_deposit_diagnostics.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-outline-secondary w-100 w-md-auto">
                <i class="bi bi-shield-check"></i> Deposit Diagnostics
            </a>
        <?php endif; ?>
        <a href="lease_view.php?id=<?= (int)$leaseId ?>" class="btn btn-outline-secondary w-100 w-md-auto">
            <i class="bi bi-arrow-left"></i> Back to Lease
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= h($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($isInvoiceMode): ?>
<div class="alert alert-info">
    <strong>Accounting Mode: Invoice Mode.</strong>
    Official balances come from invoices, receipts, allocations, and GL.
    Create and allocate receipts through
    <a href="accounting/receipt_allocation.php?lease_id=<?= (int)$leaseId ?>" class="alert-link">Receipt Allocation</a>.
    Operational cheque/installment schedules on the lease are not the official AR balance.
</div>

<?php $k = $imKpis; ?>
<div class="row g-3 mb-4 pm-kpi">
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card card-round text-center h-100"><div class="card-body py-3">
            <div class="text-muted small">Total Invoiced</div>
            <div class="fw-bold fs-5"><?= money($k['total_invoiced'] ?? 0) ?> AED</div>
            <div class="text-muted small"><?= (int)($k['invoice_count'] ?? 0) ?> invoices</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card card-round text-center h-100"><div class="card-body py-3">
            <div class="text-muted small">Total Due Now</div>
            <div class="fw-bold fs-5 text-warning"><?= money($k['total_due_now'] ?? 0) ?> AED</div>
            <div class="text-muted small"><?= (int)($k['due_now_count'] ?? 0) ?> due ≤ today</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card card-round text-center h-100"><div class="card-body py-3">
            <div class="text-muted small">Total Overdue</div>
            <div class="fw-bold fs-5 text-danger"><?= money($k['total_overdue'] ?? 0) ?> AED</div>
            <div class="text-muted small"><?= (int)($k['overdue_count'] ?? 0) ?> past due</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card card-round text-center h-100"><div class="card-body py-3">
            <div class="text-muted small">Total Outstanding</div>
            <div class="fw-bold fs-5"><?= money($k['total_outstanding'] ?? 0) ?> AED</div>
            <div class="text-muted small">Open invoice AR</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card card-round text-center h-100"><div class="card-body py-3">
            <div class="text-muted small">Total Collected</div>
            <div class="fw-bold fs-5 text-success"><?= money($k['total_collected'] ?? 0) ?> AED</div>
            <div class="text-muted small"><?= (int)($k['receipt_count'] ?? 0) ?> IM receipts</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card card-round text-center h-100"><div class="card-body py-3">
            <div class="text-muted small">Unallocated Receipts</div>
            <div class="fw-bold fs-5"><?= money($k['unallocated_receipts'] ?? 0) ?> AED</div>
            <div class="text-muted small">Collected − allocated</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card card-round text-center h-100"><div class="card-body py-3">
            <div class="text-muted small">Tenant Credit</div>
            <div class="fw-bold fs-5"><?= money($k['tenant_credit'] ?? 0) ?> AED</div>
            <div class="text-muted small">Available balance</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card card-round text-center h-100"><div class="card-body py-3">
            <div class="text-muted small">Allocated Total</div>
            <div class="fw-bold fs-5"><?= money($k['allocated_total'] ?? 0) ?> AED</div>
            <div class="text-muted small">Receipt allocations</div>
        </div></div>
    </div>
</div>

<?php
$renderInvoiceTable = static function (string $title, array $rows, array $totals, string $emptyMsg) use ($imStatusBadge): void {
    ?>
    <div class="card card-round mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong><?= h($title) ?></strong>
            <span class="small text-muted">
                Invoiced <?= money($totals['invoiced'] ?? 0) ?> ·
                Paid <?= money($totals['paid'] ?? 0) ?> ·
                Outstanding <?= money($totals['outstanding'] ?? 0) ?> AED
                (<?= (int)($totals['count'] ?? 0) ?>)
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th class="d-none d-md-table-cell">Due Date</th>
                            <th>Status</th>
                            <th class="d-none d-lg-table-cell">Description</th>
                            <th class="text-end d-none d-md-table-cell">Total</th>
                            <th class="text-end">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3"><?= h($emptyMsg) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $inv):
                            $ds = (string)($inv['display_status'] ?? $inv['status'] ?? '');
                            $badge = $imStatusBadge($ds);
                        ?>
                        <tr>
                            <td>
                                <a href="billing_invoice_view.php?id=<?= (int)$inv['id'] ?>"><?= h($inv['invoice_number']) ?></a>
                                <div class="small text-muted d-md-none"><?= h($inv['due_date'] ?? '') ?></div>
                            </td>
                            <td class="d-none d-md-table-cell"><?= h($inv['due_date'] ?? '') ?></td>
                            <td><span class="badge bg-<?= h($badge) ?>"><?= h(ucfirst(str_replace('_', ' ', $ds))) ?></span></td>
                            <td class="d-none d-lg-table-cell small text-muted"><?= h($inv['display_description'] ?? ($inv['line_description'] ?: ($inv['obligation_type'] ?? '—'))) ?></td>
                            <td class="text-end d-none d-md-table-cell"><?= money($inv['total_amount'] ?? 0) ?></td>
                            <td class="text-end <?= ((float)($inv['outstanding_amount'] ?? 0) > 0.005) ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                <?= money($inv['outstanding_amount'] ?? 0) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
};

$invTotals = $imInvoices['totals'] ?? [];
$renderInvoiceTable('Rent Invoices', $imInvoices['rent'] ?? [], $invTotals['rent'] ?? [], 'No rent invoices for this lease.');
$renderInvoiceTable(
    'Lease Service Invoices (chiller / AMC)',
    $imInvoices['lease_service'] ?? [],
    $invTotals['lease_service'] ?? [],
    'No lease service (chiller / AMC) invoices for this lease.'
);
$renderInvoiceTable(
    'Extra Service Invoices',
    $imInvoices['extra_service'] ?? [],
    $invTotals['extra_service'] ?? [],
    'No Extra Service invoices for this lease.'
);
if (!empty($imInvoices['other']) || ((int)($invTotals['other']['count'] ?? 0) > 0)) {
    $renderInvoiceTable('Other Invoices (fees / admin / penalty)', $imInvoices['other'] ?? [], $invTotals['other'] ?? [], 'No other invoices.');
}
if (!empty($imInvoices['truncated'])) {
    echo '<p class="small text-muted mb-4">Showing up to 50 invoice rows. <a href="accounting/invoice_preview.php?lease_id=' . (int)$leaseId . '">View full invoice ledger</a>.</p>';
}
?>

<div class="card card-round mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong><i class="bi bi-shield-check"></i> Security Deposit</strong>
        <a href="accounting/security_deposit_diagnostics.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-sm btn-outline-secondary">Diagnostics</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="small text-muted">Expected</div>
                <strong><?= money($imDeposit['expected'] ?? 0) ?> AED</strong>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Allocated</div>
                <strong><?= money($imDeposit['allocated'] ?? 0) ?> AED</strong>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Open</div>
                <strong><?= money(max(0, (float)($imDeposit['expected'] ?? 0) - (float)($imDeposit['allocated'] ?? 0))) ?> AED</strong>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Status</div>
                <span class="badge bg-secondary"><?= h(ucfirst(str_replace('_', ' ', (string)($imDeposit['status'] ?? 'not_expected')))) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong><i class="bi bi-cash-coin"></i> Recent Receipts</strong>
        <a href="accounting/receipt_allocation.php?lease_id=<?= (int)$leaseId ?>" class="btn btn-sm btn-primary">Allocate Receipt</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th class="d-none d-md-table-cell">Date</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end d-none d-md-table-cell">Allocated</th>
                        <th class="text-end">Unallocated</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($imReceiptsBundle['receipts'])): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No Invoice Mode receipts yet. Use Allocate Receipt to record one.</td></tr>
                <?php else: ?>
                    <?php foreach ($imReceiptsBundle['receipts'] as $rcpt):
                        $allocBadge = re_payment_allocation_status_badge($conn, $currentCompanyId, $rcpt);
                    ?>
                    <tr>
                        <td><?= h($rcpt['receipt_number'] ?: ('#' . $rcpt['id'])) ?></td>
                        <td class="d-none d-md-table-cell"><?= h($rcpt['cleared_date'] ?: $rcpt['payment_date']) ?></td>
                        <td class="text-end"><?= money($rcpt['amount']) ?></td>
                        <td class="text-end d-none d-md-table-cell"><?= money($rcpt['allocated_amount']) ?></td>
                        <td class="text-end"><?= money($rcpt['unallocated_amount']) ?></td>
                        <td>
                            <span class="badge bg-<?= h($allocBadge['class']) ?>"<?= $allocBadge['title'] !== '' ? ' title="' . h($allocBadge['title']) . '"' : '' ?>>
                                <?= h($allocBadge['label']) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="payment_view.php?id=<?= (int)$rcpt['id'] ?>">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-header"><strong>Recent Allocations</strong></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Target</th>
                        <th class="d-none d-md-table-cell">Detail</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($imReceiptsBundle['allocations'])): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No allocations recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($imReceiptsBundle['allocations'] as $al):
                        $tt = (string)($al['target_type'] ?? '');
                        $detail = $al['invoice_number']
                            ?: trim((string)($al['obligation_type'] ?? '') . ' ' . (string)($al['obligation_description'] ?? ''))
                            ?: ('#' . (int)($al['obligation_id'] ?: $al['invoice_id'] ?: 0));
                    ?>
                    <tr>
                        <td>
                            <a href="payment_view.php?id=<?= (int)$al['payment_id'] ?>"><?= h($al['receipt_number'] ?: ('#' . $al['payment_id'])) ?></a>
                        </td>
                        <td><span class="badge bg-<?= h($imTargetBadge($tt)) ?>"><?= h(ucfirst(str_replace('_', ' ', $tt))) ?></span></td>
                        <td class="d-none d-md-table-cell small">
                            <?php if (!empty($al['invoice_id'])): ?>
                                <a href="billing_invoice_view.php?id=<?= (int)$al['invoice_id'] ?>"><?= h($detail) ?></a>
                            <?php else: ?>
                                <?= h($detail) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= money($al['amount_allocated'] ?? 0) ?> AED</td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<details class="mb-4">
    <summary class="text-muted">Operational schedule note</summary>
    <div class="alert alert-secondary mt-2 mb-0">
        Cheque and installment schedules on
        <a href="lease_view.php?id=<?= (int)$leaseId ?>">Lease View</a>
        are operational only. Official accounting balances are the invoice and receipt figures above.
    </div>
</details>

<?php
require_once __DIR__ . '/includes/re_layout_footer.php';
exit;
endif;
?>

<div class="alert alert-warning">
    <strong>Legacy — Historical.</strong>
    This lease uses Legacy accounting. Payment Manager is read-only for historical installment and payment review.
    Mutating actions (record, delete, relink, split, fix links) are disabled.
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card text-center card-round">
            <div class="card-body py-3">
                <div class="text-muted small">Total Due</div>
                <div class="fw-bold fs-5"><?= money($totalDue) ?> AED</div>
                <div class="text-muted small"><?= count($collectibleInstallments) ?> collectible installments</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card text-center card-round">
            <div class="card-body py-3">
                <div class="text-muted small">Total Collected</div>
                <div class="fw-bold fs-5 text-success"><?= money($totalCollected) ?> AED</div>
                <div class="text-muted small"><?= count($allPayments) ?> payments</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card text-center card-round">
            <div class="card-body py-3">
                <div class="text-muted small">Outstanding</div>
                <div class="fw-bold fs-5 <?= $totalOutstanding > 0 ? 'text-warning' : 'text-success' ?>">
                    <?= money($totalOutstanding) ?> AED
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card text-center card-round">
            <div class="card-body py-3">
                <div class="text-muted small">Unlinked Payments</div>
                <div class="fw-bold fs-5"><?= count($unlinkedPayments) ?></div>
            </div>
        </div>
    </div>
</div>

<h5 class="mb-3"><i class="bi bi-calendar-check"></i> Installments & Payments <span class="badge bg-secondary">Historical</span></h5>

<?php foreach ($installments as $idx => $inst):
    $statusClass = 'status-' . $inst['status'];
    $pct = $inst['amount'] > 0 ? min(100, round($inst['collected'] / $inst['amount'] * 100)) : 0;
    $badgeColor = match($inst['status']) {
        'paid'    => 'success',
        'partial' => 'warning text-dark',
        'overdue' => 'danger',
        'cancelled' => 'secondary',
        default   => 'secondary',
    };
?>
<div class="card card-round mb-3 inst-card <?= $statusClass ?>">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="fw-bold">Installment <?= $idx + 1 ?></span>
            <span class="text-muted small"><i class="bi bi-calendar3"></i> <?= date('d M Y', strtotime($inst['installment_date'])) ?></span>
            <span class="badge bg-<?= $badgeColor ?>"><?= ucfirst($inst['status']) ?></span>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="small"><strong>Due:</strong> <?= money($inst['amount']) ?> AED</span>
            <span class="small text-success"><strong>Collected:</strong> <?= money($inst['collected']) ?> AED</span>
            <?php if ($inst['outstanding'] > 0): ?>
                <span class="small text-danger"><strong>Outstanding:</strong> <?= money($inst['outstanding']) ?> AED</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="progress" style="height:4px; border-radius:0;">
        <div class="progress-bar bg-<?= $pct >= 100 ? 'success' : ($pct > 0 ? 'warning' : 'secondary') ?>"
             style="width:<?= $pct ?>%"></div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($inst['payments'])): ?>
            <div class="text-muted small p-3"><i class="bi bi-info-circle"></i> No payments recorded for this installment.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Receipt #</th>
                            <th>Payment Date</th>
                            <th>Amount</th>
                            <th class="d-none d-md-table-cell">Method</th>
                            <th class="d-none d-lg-table-cell">Reference</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inst['payments'] as $pay): ?>
                            <tr class="payment-row">
                                <td class="ps-3">
                                    <a href="payment_view.php?id=<?= (int)$pay['id'] ?>" class="fw-bold text-decoration-none">
                                        #<?= h($pay['receipt_number'] ?: $pay['id']) ?>
                                    </a>
                                </td>
                                <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                                <td><strong><?= money($pay['amount_for_installment']) ?> AED</strong></td>
                                <td class="d-none d-md-table-cell">
                                    <span class="badge bg-light text-dark border badge-method">
                                        <?= h(ucfirst(str_replace('_', ' ', $pay['payment_method']))) ?>
                                    </span>
                                </td>
                                <td class="d-none d-lg-table-cell text-muted small"><?= h($pay['reference_number'] ?: '–') ?></td>
                                <td class="text-end pe-3">
                                    <a href="payment_view.php?id=<?= (int)$pay['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Receipt">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (!empty($serviceItems)): ?>
<h5 class="mb-3 mt-4"><i class="bi bi-lightning-charge"></i> Service Charges <span class="badge bg-secondary">Historical</span></h5>
<div class="row g-3 mb-4">
    <?php foreach ($serviceItems as $svc):
        $svcBadge = match($svc['status'] ?? '') {
            'paid' => 'success',
            'partial' => 'info',
            'overdue' => 'danger',
            'waived' => 'secondary',
            default => 'warning',
        };
    ?>
    <div class="col-md-6 col-xl-4">
        <div class="card card-round h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between gap-2">
                    <strong><?= h($svc['service_name'] ?: $svc['item_name']) ?></strong>
                    <span class="badge bg-<?= $svcBadge ?>"><?= h(ucfirst($svc['status'] ?? 'pending')) ?></span>
                </div>
                <div class="small text-muted mt-1">Due <?= h($svc['due_date'] ?? '') ?></div>
                <div class="mt-2 small">
                    Amount <?= money($svc['total_amount']) ?> ·
                    Collected <?= money($svc['collected']) ?> ·
                    Outstanding <?= money($svc['outstanding']) ?> AED
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($unlinkedPayments)): ?>
<div class="unlinked-section p-3 mb-4">
    <h5 class="text-danger"><i class="bi bi-exclamation-triangle"></i> Unlinked Payments (Historical)</h5>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Receipt</th><th>Date</th><th class="text-end">Amount</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($unlinkedPayments as $pay): ?>
                <tr>
                    <td>#<?= h($pay['receipt_number'] ?: $pay['id']) ?></td>
                    <td><?= h($pay['payment_date']) ?></td>
                    <td class="text-end"><?= money($pay['amount']) ?> AED</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="payment_view.php?id=<?= (int)$pay['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($allPayments) && empty($installments)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> No historical payments or installments found for this Legacy lease.
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
