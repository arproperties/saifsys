<?php
/**
 * Real Estate Module - Add Payment
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/payment_allocation_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$leaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : null;
$installmentId = !empty($_GET['installment_id']) ? (int)$_GET['installment_id'] : null;
$billingItemId = !empty($_GET['billing_item_id']) ? (int)$_GET['billing_item_id'] : null;
$invoiceId = !empty($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : null;
$collectBalance = !empty($_GET['collect_balance']);
$success = '';
$error = '';
$outstandingBalance = 0;
$invoiceAmount = 0;

function re_payment_add_invoice_mode_lease_id(PDO $conn, int $companyId, ?int $leaseId, ?int $invoiceId): ?int
{
    try {
        if ($invoiceId) {
            $stmt = $conn->prepare("
                SELECT l.id
                FROM re_invoices i
                JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
                WHERE i.id = ? AND i.company_id = ? AND COALESCE(l.accounting_mode, 'legacy') = 'invoice'
                LIMIT 1
            ");
            $stmt->execute([$invoiceId, $companyId]);
            $id = $stmt->fetchColumn();
            if ($id) {
                return (int)$id;
            }
        }
        if ($leaseId) {
            $stmt = $conn->prepare("SELECT id FROM re_leases WHERE id = ? AND company_id = ? AND COALESCE(accounting_mode, 'legacy') = 'invoice' LIMIT 1");
            $stmt->execute([$leaseId, $companyId]);
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $invoiceModeLeaseId = re_payment_add_invoice_mode_lease_id($conn, $currentCompanyId, $leaseId, $invoiceId);
    if ($invoiceModeLeaseId) {
        header('Location: accounting/receipt_allocation.php?lease_id=' . $invoiceModeLeaseId);
        exit;
    }
}

// Get bank accounts for payment account selection
$bankAccounts = [];
try {
    $stmt = $conn->prepare("
        SELECT ba.id, ba.account_name, ba.bank_name, ba.account_number, coa.account_code, coa.account_name as gl_account_name
        FROM re_bank_accounts ba
        JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
        WHERE ba.company_id = ? AND ba.is_active = 1
        ORDER BY coa.account_code
    ");
    $stmt->execute([$currentCompanyId]);
    $bankAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // If bank accounts table doesn't exist or error, continue without bank accounts
    error_log("Error loading bank accounts: " . $e->getMessage());
}

// Also get cash account
$cashAccount = null;
try {
    $stmt = $conn->prepare("
        SELECT id, account_code, account_name
        FROM re_chart_of_accounts
        WHERE company_id = ? AND account_code = '1110' AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$currentCompanyId]);
    $cashAccount = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error loading cash account: " . $e->getMessage());
}

// Get leases — include multi-unit flag and unit count for display
$leases = $conn->prepare("
    SELECT l.id, l.lease_number, l.monthly_rent,
           l.is_multi_unit,
           u.unit_number, b.name as building_name,
           t.first_name, t.last_name, t.company_name, t.tenant_type,
           (SELECT COUNT(*) FROM re_lease_units lu WHERE lu.lease_id = l.id) AS unit_count
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ? AND (l.status = 'active' OR l.id = ?)
    ORDER BY l.start_date DESC
");
$leases->execute([$currentCompanyId, (int)$leaseId]);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

// Get pending installments for selected lease
$installments = [];
if ($leaseId) {
    $stmt = $conn->prepare("
        SELECT id, installment_date, amount, status
        FROM re_lease_installments
        WHERE lease_id = ? AND status IN ('pending', 'overdue', 'partial')
        ORDER BY installment_date ASC
    ");
    $stmt->execute([$leaseId]);
    $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get invoice details if invoice_id is provided
$invoice = null;
if ($invoiceId) {
    $stmt = $conn->prepare("
        SELECT i.*, l.id as lease_id
        FROM re_invoices i
        JOIN re_leases l ON l.id = i.lease_id
        WHERE i.id = ? AND i.company_id = ?
    ");
    $stmt->execute([$invoiceId, $currentCompanyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($invoice) {
        // Set lease_id from invoice if not already set
        if (!$leaseId) {
            $leaseId = (int)$invoice['lease_id'];
        }
        $invoiceAmount = (float)$invoice['outstanding_amount'];
        $outstandingBalance = $invoiceAmount;
    }
}

// Calculate outstanding balance if collecting balance — use the allocation helper for accuracy
if ($collectBalance && $installmentId) {
    $stmt = $conn->prepare("SELECT amount FROM re_lease_installments WHERE id = ?");
    $stmt->execute([$installmentId]);
    $instRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($instRow) {
        $totalPaid = get_installment_total_paid($conn, $installmentId);
        $outstandingBalance = max(0, (float)$instRow['amount'] - $totalPaid);
    }
}
if ($collectBalance && $billingItemId) {
    $stmt = $conn->prepare("
        SELECT bi.id, bi.lease_id, bi.total_amount
        FROM re_billing_items bi
        WHERE bi.id = ? AND bi.company_id = ? AND bi.status <> 'waived'
        LIMIT 1
    ");
    $stmt->execute([$billingItemId, $currentCompanyId]);
    $billingItem = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($billingItem) {
        if (!$leaseId) {
            $leaseId = (int)$billingItem['lease_id'];
        }
        $totalPaid = get_billing_item_total_paid($conn, $billingItemId);
        $outstandingBalance = max(0, (float)$billingItem['total_amount'] - $totalPaid);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $leaseId = (int)$_POST['lease_id'];
    $installmentId = !empty($_POST['installment_id']) ? (int)$_POST['installment_id'] : null;
    $billingItemId = !empty($_POST['billing_item_id']) ? (int)$_POST['billing_item_id'] : null;
    $invoiceId = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $amount = !empty($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $paymentMethodRaw = trim($_POST['payment_method'] ?? '');
    $allowedMethods = ['cash', 'bank_transfer', 'cheque', 'auto_debit', 'cash_deposit'];
    $paymentMethod = in_array($paymentMethodRaw, $allowedMethods, true) ? $paymentMethodRaw : 'bank_transfer';
    $bankAccountId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $referenceNumber = trim($_POST['reference_number'] ?? '');
    $receiptNumber = trim($_POST['receipt_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $applyCredit = !empty($_POST['apply_credit']) ? (float)$_POST['apply_credit'] : 0;
    $allocations = is_array($_POST['allocation'] ?? null) ? $_POST['allocation'] : [];
    $billingAlloc = is_array($_POST['billing_alloc'] ?? null) ? $_POST['billing_alloc'] : [];
    $useAllocationForm = isset($_POST['use_allocation']) && $_POST['use_allocation'] === '1';

    $invoiceModeLeaseId = re_payment_add_invoice_mode_lease_id($conn, $currentCompanyId, $leaseId, $invoiceId);
    if ($invoiceModeLeaseId) {
        header('Location: accounting/receipt_allocation.php?lease_id=' . $invoiceModeLeaseId);
        exit;
    }

    // If any billing item allocations provided, treat as allocation flow too
    $useAllocationFlow = payment_allocation_tables_exist($conn) && ($useAllocationForm || !empty($allocations) || !empty($billingAlloc) || $applyCredit > 0);
    
    if ($leaseId && $paymentDate && $amount > 0) {
        try {
            $conn->beginTransaction();
            
            if ($useAllocationFlow) {
                // Multi-allocation flow: one payment, multiple allocations + optional credit apply/overpayment
                $allocSum = 0.0;
                foreach ($allocations as $instId => $allocAmt) {
                    $allocAmt = (float)$allocAmt;
                    if ($allocAmt > 0 && $instId) {
                        $allocSum += $allocAmt;
                    }
                }
                // Include billing item allocations in total sum check
                $billingAllocSum = 0.0;
                $validBillingAllocs = [];
                foreach ($billingAlloc as $biId => $biAmt) {
                    $biAmt = (float)$biAmt;
                    if ($biAmt > 0 && $biId) {
                        $biId = (int)$biId;
                        $chkBi = $conn->prepare("
                            SELECT id, total_amount
                            FROM re_billing_items
                            WHERE id = ? AND lease_id = ? AND company_id = ? AND status <> 'waived'
                            LIMIT 1
                        ");
                        $chkBi->execute([$biId, $leaseId, $currentCompanyId]);
                        $biRow = $chkBi->fetch(PDO::FETCH_ASSOC);
                        if (!$biRow) {
                            throw new Exception('One selected service/fee item is not valid for this lease.');
                        }
                        $openBi = max(0, (float)$biRow['total_amount'] - get_billing_item_total_paid($conn, $biId));
                        if ($biAmt > $openBi + 0.005) {
                            throw new Exception('Service/fee allocation cannot exceed outstanding amount.');
                        }
                        $billingAllocSum += $biAmt;
                        $validBillingAllocs[$biId] = round($biAmt, 2);
                    }
                }
                $totalAvailable = $amount + $applyCredit;
                if (($allocSum + $billingAllocSum) > $totalAvailable + 0.005) {
                    throw new Exception("Total allocation (" . number_format($allocSum + $billingAllocSum, 2) . " AED) cannot exceed payment (" . number_format($amount, 2) . " AED) + credit applied (" . number_format($applyCredit, 2) . " AED).");
                }
                $stmt = $conn->prepare("SELECT tenant_id FROM re_leases WHERE id = ? AND company_id = ?");
                $stmt->execute([$leaseId, $currentCompanyId]);
                $leaseRow = $stmt->fetch(PDO::FETCH_ASSOC);
                $tenantId = $leaseRow ? (int)$leaseRow['tenant_id'] : null;
                $creditBalance = $tenantId ? get_tenant_credit_balance($conn, $tenantId, $currentCompanyId) : 0;
                if ($applyCredit > 0 && (!$tenantId || $applyCredit > $creditBalance)) {
                    throw new Exception("Apply credit amount exceeds tenant credit balance (" . number_format($creditBalance, 2) . " AED).");
                }
                $stmt = $conn->prepare("
                    INSERT INTO re_payments (company_id, lease_id, installment_id, invoice_id, payment_date, amount, payment_method,
                     bank_account_id, reference_number, receipt_number, notes, created_by)
                    VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$currentCompanyId, $leaseId, $invoiceId, $paymentDate, $amount, $paymentMethod, $bankAccountId, $referenceNumber, $receiptNumber, $notes, $userId]);
                $paymentId = (int)$conn->lastInsertId();
                foreach ($allocations as $instId => $allocAmt) {
                    $allocAmt = (float)$allocAmt;
                    if ($allocAmt <= 0 || !$instId) continue;
                    $stmt = $conn->prepare("INSERT INTO re_payment_allocations (company_id, payment_id, installment_id, amount_allocated) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$currentCompanyId, $paymentId, (int)$instId, $allocAmt]);
                    update_installment_status_from_allocations($conn, (int)$instId);
                }
                // Allocate billing items (service charges, parking fees, penalties)
                $paidBillingItemIds = [];
                foreach ($validBillingAllocs as $biId => $biAmt) {
                    if (billing_item_allocations_table_exists($conn)) {
                        $conn->prepare("
                            INSERT INTO re_billing_item_payment_allocations
                                (company_id, payment_id, billing_item_id, amount_allocated)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE amount_allocated = VALUES(amount_allocated)
                        ")->execute([$currentCompanyId, $paymentId, $biId, $biAmt]);
                    } else {
                        $conn->prepare("
                            UPDATE re_billing_items
                            SET paid_amount = COALESCE(paid_amount, 0) + ?,
                                payment_id = COALESCE(payment_id, ?)
                            WHERE id = ? AND company_id = ?
                        ")->execute([$biAmt, $paymentId, $biId, $currentCompanyId]);
                    }
                    update_billing_item_status_from_allocations($conn, $biId, $paymentId, $paymentDate);
                    $paidBillingItemIds[] = $biId;
                }
                if ($applyCredit > 0 && $tenantId) {
                    update_tenant_credit($conn, $tenantId, $currentCompanyId, $applyCredit, 'debit', $paymentId, null, 'Applied to payment #' . $paymentId);
                }
                $overpayment = $amount - $allocSum - $billingAllocSum;
                if ($overpayment > 0 && $tenantId) {
                    update_tenant_credit($conn, $tenantId, $currentCompanyId, $overpayment, 'credit', $paymentId, null, 'Overpayment/advance payment #' . $paymentId);
                }
            } else {
                // Legacy single-installment flow
                $stmt = $conn->prepare("
                    INSERT INTO re_payments 
                    (company_id, lease_id, installment_id, invoice_id, payment_date, amount, payment_method,
                     bank_account_id, reference_number, receipt_number, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$currentCompanyId, $leaseId, $installmentId, $invoiceId, $paymentDate, $amount,
                              $paymentMethod, $bankAccountId, $referenceNumber, $receiptNumber, $notes, $userId]);
                $paymentId = $conn->lastInsertId();
            }

            // Update invoice if linked (both flows)
            if ($invoiceId) {
                // Get current invoice totals
                $stmt = $conn->prepare("
                    SELECT total_amount, paid_amount, outstanding_amount, status
                    FROM re_invoices
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$invoiceId, $currentCompanyId]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($invoice) {
                    $newPaidAmount = (float)$invoice['paid_amount'] + $amount;
                    $newOutstanding = max(0, (float)$invoice['total_amount'] - $newPaidAmount);
                    
                    // Determine new status
                    if ($newOutstanding <= 0) {
                        $newStatus = 'paid';
                    } elseif ($newPaidAmount > 0) {
                        $newStatus = 'partial';
                    } else {
                        $newStatus = $invoice['status'];
                    }
                    
                    // Update invoice (re_invoices table doesn't have paid_date column)
                    $stmt = $conn->prepare("
                        UPDATE re_invoices 
                        SET paid_amount = ?, outstanding_amount = ?, status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newPaidAmount, $newOutstanding, $newStatus, $invoiceId]);
                    
                    // Mark linked billing items as paid
                    $stmt = $conn->prepare("
                        UPDATE re_billing_items bi
                        JOIN re_invoice_items ii ON ii.billing_item_id = bi.id
                        SET bi.is_paid = 1, bi.paid_amount = bi.total_amount, bi.paid_date = ?
                        WHERE ii.invoice_id = ? AND bi.is_paid = 0
                    ");
                    $stmt->execute([$paymentDate, $invoiceId]);
                }
            }
            
            // Update installment if linked (legacy single-installment flow only)
            if (!$useAllocationFlow && $installmentId) {
                $stmt = $conn->prepare("
                    SELECT amount, status FROM re_lease_installments WHERE id = ?
                ");
                $stmt->execute([$installmentId]);
                $installment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($installment) {
                    $installmentAmount = (float)$installment['amount'];
                    $totalPaid = (float)$amount;
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(amount), 0) as total_paid
                        FROM re_payments
                        WHERE installment_id = ? AND id != ?
                    ");
                    $stmt->execute([$installmentId, $paymentId]);
                    $existingPaid = (float)($stmt->fetchColumn() ?: 0);
                    $totalPaid += $existingPaid;
                    if ($totalPaid >= $installmentAmount) {
                        $newStatus = 'paid';
                    } elseif ($totalPaid > 0) {
                        $newStatus = 'partial';
                    } else {
                        $newStatus = $installment['status'];
                    }
                    $stmt = $conn->prepare("
                        UPDATE re_lease_installments 
                        SET status = ?, paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END, payment_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newStatus, $newStatus, $paymentId, $installmentId]);
                    sync_pdc_status_for_paid_installment($conn, $installmentId, (int)$paymentId);
                }
            }
            
            $conn->commit();

            try {
                require_once __DIR__ . '/../../includes/audit_bridge.php';
                audit_bridge_re_ops(
                    $conn,
                    (int)$currentCompanyId,
                    'payment_recorded',
                    're_payments',
                    (int)$paymentId,
                    'Payment #' . (int)$paymentId,
                    'Recorded payment AED ' . number_format((float)$amount, 2) . ' for lease #' . (int)$leaseId,
                    null,
                    [
                        'lease_id' => (int)$leaseId,
                        'amount' => (float)$amount,
                        'payment_method' => $paymentMethod ?? null,
                        'allocated' => !empty($useAllocationFlow),
                    ],
                    (int)$userId
                );
            } catch (Throwable $e) {
                error_log('payment_add audit: ' . $e->getMessage());
            }

            // Tenant in-app + push notification: payment received
            require_once __DIR__ . '/../../includes/tenant_notifications.php';
            tenant_notification_create($conn, [
                'company_id' => $currentCompanyId,
                'lease_id' => (int)$leaseId,
                'type' => 'payment_received',
                'entity_type' => 'payment',
                'entity_id' => (int)$paymentId,
                'title' => 'Payment received',
                'body' => 'We received your payment of AED ' . number_format((float)$amount, 2) . '. Thank you.',
            ]);
            
            // Post payment to accounting (non-blocking — payment is always saved first)
            $accountingWarning = null;
            try {
                require_once __DIR__ . '/accounting/accounting_integration.php';

                // Check if lease uses deferred revenue / accrual mode
                $deferredStmt = $conn->prepare("
                    SELECT COALESCE(deferred_revenue_mode, 0) FROM re_leases WHERE id = ? AND company_id = ?
                ");
                $deferredStmt->execute([$leaseId, $currentCompanyId]);
                $isDeferredLease = (bool)$deferredStmt->fetchColumn();

                if (!empty($paidBillingItemIds)) {
                    $accountingResult = post_payment_with_billing_allocations_to_accounting(
                        $paymentId, $currentCompanyId, $userId, $bankAccountId
                    );
                } elseif ($isDeferredLease) {
                    $accountingResult = post_deferred_payment_to_accounting(
                        $paymentId, $currentCompanyId, $userId, $bankAccountId
                    );
                } else {
                    $accountingResult = post_payment_to_accounting(
                        $paymentId, $currentCompanyId, $userId, $bankAccountId
                    );
                }

                if (!$accountingResult['success']) {
                    error_log("Accounting posting failed for payment {$paymentId}: " . $accountingResult['error']);
                    $accountingWarning = $accountingResult['error'];
                }

                // Billing item allocations are posted inside the combined payment journal above.
            } catch (Exception $e) {
                error_log("Accounting integration error for payment {$paymentId}: " . $e->getMessage());
                $accountingWarning = $e->getMessage();
            }

            if ($accountingWarning) {
                $_SESSION['accounting_warning'] = "Payment saved successfully, but the accounting journal could not be posted automatically. Reason: {$accountingWarning}. Please post it manually from the Journal Entries page.";
            }

            // Send payment received notification (async)
            require_once __DIR__ . '/includes/collections_helper.php';
            register_shutdown_function(function() use ($conn, $currentCompanyId, $paymentId) {
                try {
                    send_payment_received_notification($conn, $currentCompanyId, $paymentId);
                } catch (Exception $e) {
                    error_log("Payment notification error: " . $e->getMessage());
                }
            });
            
            // Redirect based on source
            if ($invoiceId) {
                header('Location: billing_invoice_view.php?id=' . $invoiceId);
            } else {
                header('Location: payment_view.php?id=' . $paymentId);
            }
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields";
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Record Payment';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
        <h1>Record Payment</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($collectBalance && ($installmentId || $billingItemId) && $outstandingBalance > 0): ?>
        <div class="alert alert-warning d-flex align-items-center gap-3 mb-3">
            <i class="bi bi-exclamation-triangle-fill fs-4"></i>
            <div>
                <strong>Collecting Outstanding Balance</strong> —
                <?= $billingItemId ? 'Service/fee outstanding' : 'Installment outstanding' ?>:
                <strong><?= number_format($outstandingBalance, 2) ?> AED</strong>.
                The allocation table below has been pre-filled. You can also click
                <strong>"Fill All Outstanding"</strong> to collect all overdue amounts at once.
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" id="paymentForm">
                    <?php csrf_field(); ?>
                    
                    <?php if ($invoiceId && $invoice): ?>
                        <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle"></i> <strong>Recording payment for Invoice:</strong> 
                            <?= h($invoice['invoice_number'] ?? 'INV-' . $invoiceId) ?> | 
                            <strong>Outstanding:</strong> <?= number_format($invoice['outstanding_amount'] ?? 0, 2) ?> AED
                        </div>
                    <?php elseif ($invoiceId): ?>
                        <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
                        <div class="alert alert-warning mb-3">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Invoice #<?= $invoiceId ?></strong> - Unable to load invoice details, but payment will still be linked.
                        </div>
                    <?php endif; ?>
                    <?php if ($billingItemId): ?>
                        <input type="hidden" name="billing_item_id" value="<?= (int)$billingItemId ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lease *</label>
                            <select name="lease_id" class="form-select" id="leaseSelect" required <?= $leaseId ? 'readonly' : '' ?>>
                                <option value="">-- Select Lease --</option>
                                <?php foreach ($leases as $l):
                                    $tenantDisplay = ($l['tenant_type'] === 'company' && !empty($l['company_name']))
                                        ? $l['company_name']
                                        : trim($l['first_name'] . ' ' . $l['last_name']);
                                    $unitDisplay   = !empty($l['is_multi_unit'])
                                        ? '[Multi-Unit × ' . (int)$l['unit_count'] . '] ' . $l['building_name']
                                        : $l['building_name'] . ' - ' . $l['unit_number'];
                                    $label = ($l['lease_number'] ?: 'L-' . $l['id']) . ' — ' . $unitDisplay . ' (' . $tenantDisplay . ')';
                                ?>
                                    <option value="<?= $l['id'] ?>"
                                            data-multi="<?= !empty($l['is_multi_unit']) ? '1' : '0' ?>"
                                            data-units="<?= (int)$l['unit_count'] ?>"
                                            <?= ($leaseId == $l['id']) ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Payment Date *</label>
                            <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- Multi-Unit Lease banner (shown when a multi-unit lease is selected) -->
                    <div id="multiUnitBanner" style="display:none;" class="mb-3"></div>

                    <!-- Payment allocation: multi-allocate, partial, credit (shown when allocation supported) -->
                    <div class="card mb-4 border-primary" id="allocationCard" style="display: none;">
                        <div class="card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 text-primary"><i class="bi bi-wallet2"></i> Payment Allocation</h6>
                            <div class="d-flex gap-2 align-items-center">
                                <span class="text-muted small" id="tenantCreditDisplay" style="display:none!important">
                                    Tenant credit: <strong id="tenantCreditAmount">0.00</strong> AED
                                </span>
                                <button type="button" class="btn btn-success btn-sm" id="fillAllBtn" onclick="fillAllOutstanding()" title="Auto-fill all outstanding amounts">
                                    <i class="bi bi-lightning-fill"></i> Fill All Outstanding
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <input type="hidden" name="use_allocation" id="useAllocationHidden" value="" disabled>
                            <div id="tenantCreditDisplay2" class="px-3 pt-3 pb-0" style="display:none">
                                <div class="alert alert-info py-2 mb-2 small">
                                    <i class="bi bi-piggy-bank"></i> Tenant has <strong id="tenantCreditAmount2">0.00</strong> AED credit balance available to apply.
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0" id="allocationTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="width:13%"><i class="bi bi-calendar3"></i> Due Date</th>
                                            <th style="width:12%">Cheque #</th>
                                            <th style="width:12%" class="text-end">Due (AED)</th>
                                            <th style="width:12%" class="text-end">Outstanding</th>
                                            <th style="width:10%">Status</th>
                                            <th style="width:16%">
                                                <i class="bi bi-pencil-square"></i> Allocate (AED)
                                                <button type="button" class="btn btn-outline-light btn-sm py-0 px-1 ms-1 d-none d-md-inline" onclick="fillAllOutstanding()" title="Fill all">
                                                    <i class="bi bi-lightning-fill"></i>
                                                </button>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="allocationTableBody">
                                    </tbody>
                                    <tfoot id="allocationTableFoot" style="display:none">
                                        <tr class="table-light fw-bold">
                                            <td colspan="3" class="text-end">Total Outstanding:</td>
                                            <td class="text-end text-danger" id="totalOutstandingCell">0.00</td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div class="px-3 pb-3 pt-2">
                                <div class="row align-items-end">
                                    <div class="col-md-4 mb-2" id="applyCreditRow" style="display:none">
                                        <label class="form-label small fw-semibold">Apply tenant credit (AED)</label>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="apply_credit" id="applyCreditInput" value="0" placeholder="0">
                                    </div>
                                    <div class="col mb-2">
                                        <div class="d-flex gap-3 flex-wrap">
                                            <span class="badge bg-secondary fs-6" id="allocSumLabel">Allocated: 0.00 AED</span>
                                            <span id="remainderLabel" class="small align-self-center"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3" id="installmentSelectRow">
                            <label class="form-label">Installment (Optional)</label>
                            <select name="installment_id" class="form-select" id="installmentSelect" <?= $installmentId ? 'data-preselected="' . $installmentId . '"' : '' ?>>
                                <option value="">-- Not linked to installment --</option>
                            </select>
                            <small class="text-muted">Select an installment to link this payment</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" id="amountLabel">Amount (AED) *</label>
                            <input type="number" step="0.01" class="form-control" name="amount" id="amountInput" 
                                   value="<?= $outstandingBalance > 0 ? number_format($outstandingBalance, 2, '.', '') : '' ?>" required>
                            <small class="text-muted" id="amountHint">Total payment amount received</small>
                            <?php if ($outstandingBalance > 0): ?>
                                <small class="text-muted <?= $invoiceId ? 'text-info' : 'text-danger' ?> d-block">
                                    <?= $invoiceId ? 'Invoice Outstanding' : 'Outstanding Balance' ?>: <?= number_format($outstandingBalance, 2) ?> AED
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Payment Method *</label>
                            <select name="payment_method" class="form-select" id="paymentMethodSelect" required>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cash_deposit">Cash Deposit</option>
                                <option value="cheque">Cheque</option>
                                <option value="auto_debit">Auto Debit</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3" id="bankAccountWrapper" style="display: none;">
                            <label class="form-label">Bank Account *</label>
                            <select name="bank_account_id" class="form-select" id="bankAccountSelect">
                                <option value="">-- Select Bank Account --</option>
                                <?php foreach ($bankAccounts as $ba): ?>
                                    <option value="<?= $ba['id'] ?>" data-account-code="<?= h($ba['account_code']) ?>">
                                        <?= h($ba['account_code'] . ' - ' . $ba['gl_account_name']) ?>
                                        <?php if ($ba['bank_name']): ?>
                                            (<?= h($ba['bank_name']) ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select which bank account this payment will be deposited to</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Receipt Number</label>
                            <input type="text" class="form-control" name="receipt_number">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Record Payment</button>
                        <a href="payments.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // ── Context passed from PHP ───────────────────────────────────────────────
        const PHP_LEASE_ID         = <?= (int)($leaseId ?? 0) ?>;
        const PHP_INSTALLMENT_ID   = <?= (int)($installmentId ?? 0) ?>;
        const PHP_BILLING_ITEM_ID  = <?= (int)($billingItemId ?? 0) ?>;
        const PHP_COLLECT_BALANCE  = <?= $collectBalance ? 'true' : 'false' ?>;

        // ── State ─────────────────────────────────────────────────────────────────
        var allocationSupported  = false;
        var tenantCreditBalance  = 0;
        var currentInstallments  = [];
        var currentTotalOutstanding = 0;

        // ── Status badge colour ───────────────────────────────────────────────────
        function statusBadge(status) {
            var map = { overdue:'danger', partial:'warning text-dark', pending:'secondary', paid:'success' };
            var cls = map[status] || 'secondary';
            return '<span class="badge bg-' + cls + '">' + (status || 'pending') + '</span>';
        }

        // ── Render allocation table ───────────────────────────────────────────────
        var _leaseInfoCache   = null;
        var _currentPenalties = [];

        function renderAllocationTable(list, penaltyItems) {
            penaltyItems = penaltyItems || [];
            var tbody = document.getElementById('allocationTableBody');
            var tfoot = document.getElementById('allocationTableFoot');
            tbody.innerHTML = '';

            if (!list.length && !penaltyItems.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3"><i class="bi bi-check-circle text-success"></i> All installments are fully paid.</td></tr>';
                if (tfoot) tfoot.style.display = 'none';
                return;
            }

            var totalOut = 0;

            // ── Rent installment rows ──────────────────────────────────────────────
            list.forEach(function(inst) {
                var out = parseFloat(inst.outstanding) || 0;
                totalOut += out;
                var isTarget = !!inst.is_target;
                var displayStatus = inst.display_status || inst.status || 'pending';

                var rowClass = '';
                if (isTarget) rowClass = 'table-warning';
                else if (displayStatus === 'overdue') rowClass = 'table-danger bg-opacity-10';
                else if (displayStatus === 'partial') rowClass = 'table-warning bg-opacity-10';

                var tr = document.createElement('tr');
                if (rowClass) tr.className = rowClass;

                var targetBadge = isTarget ? ' <span class="badge bg-danger ms-1">← Target</span>' : '';
                var multiHint = '';
                if (_leaseInfoCache && _leaseInfoCache.is_multi_unit && _leaseInfoCache.units && _leaseInfoCache.units.length > 1) {
                    var numUnits = _leaseInfoCache.units.length;
                    var instAmt  = parseFloat(inst.amount) || 0;
                    var perUnit  = numUnits > 0 ? (instAmt / numUnits).toFixed(2) : '0.00';
                    multiHint = '<br><small class="text-primary"><i class="bi bi-buildings"></i> ' + numUnits + ' units · ~' + perUnit + ' each</small>';
                }

                tr.innerHTML =
                    '<td class="small">' + (inst.installment_date || '') + targetBadge + multiHint + '</td>' +
                    '<td class="small"><code>' + (inst.cheque_number || '—') + '</code></td>' +
                    '<td class="text-end small">' + parseFloat(inst.amount).toFixed(2) + '</td>' +
                    '<td class="text-end fw-bold ' + (out > 0 ? 'text-danger' : 'text-success') + '">' + out.toFixed(2) + '</td>' +
                    '<td>' + statusBadge(displayStatus) + '</td>' +
                    '<td>' +
                        '<input type="number" step="0.01" min="0" max="' + out.toFixed(2) + '" ' +
                               'class="form-control form-control-sm allocation-input ' + (isTarget ? 'border-danger' : '') + '" ' +
                               'name="allocation[' + inst.id + ']" ' +
                               'data-inst-id="' + inst.id + '" data-max="' + out.toFixed(2) + '" ' +
                               'placeholder="0.00" value="">' +
                    '</td>';
                tbody.appendChild(tr);
            });

            // ── Billing item rows: service charges, parking fees, penalties ───────
            if (penaltyItems.length) {
                // Separator row
                var sep = document.createElement('tr');
                sep.innerHTML = '<td colspan="6" class="py-1 px-2" style="background:#e7f1ff;">' +
                    '<small class="fw-semibold text-primary"><i class="bi bi-lightning-charge-fill me-1"></i>' +
                    'Service Charges / Other Fees</small></td>';
                tbody.appendChild(sep);

                penaltyItems.forEach(function(pi) {
                    var out = parseFloat(pi.outstanding) || 0;
                    totalOut += out;
                    var typeLabel = 'Service Charge';
                    var rowClass = 'table-info bg-opacity-10';
                    if (pi.item_type === 'penalty') {
                        typeLabel = pi.penalty_type === 'bounced_cheque' ? 'Bounced Fee' : 'Late Fee';
                        rowClass = 'table-warning bg-opacity-25';
                    } else if (pi.item_type === 'parking_fee') {
                        typeLabel = 'Parking Fee';
                    } else if (pi.item_type === 'other') {
                        typeLabel = 'Other Fee';
                    }
                    var isTarget = PHP_BILLING_ITEM_ID && parseInt(pi.id) === PHP_BILLING_ITEM_ID;
                    var targetBadge = isTarget ? ' <span class="badge bg-danger ms-1">← Target</span>' : '';
                    var tr = document.createElement('tr');
                    tr.className = isTarget ? 'table-warning' : rowClass;
                    tr.innerHTML =
                        '<td class="small">' + (pi.due_date || '') +
                            targetBadge + '<br><span class="badge bg-primary">' + typeLabel + '</span></td>' +
                        '<td class="small text-muted">' + h2(pi.item_name || '') + '</td>' +
                        '<td class="text-end small">' + parseFloat(pi.total_amount || out).toFixed(2) + '</td>' +
                        '<td class="text-end fw-bold text-danger">' + out.toFixed(2) + '</td>' +
                        '<td>' + statusBadge(pi.status || 'pending') + '</td>' +
                        '<td>' +
                            '<input type="number" step="0.01" min="0" max="' + out.toFixed(2) + '" ' +
                                   'class="form-control form-control-sm allocation-input allocation-billing ' + (isTarget ? 'border-danger' : '') + '" ' +
                                   'name="billing_alloc[' + pi.id + ']" ' +
                                   'data-billing-id="' + pi.id + '" data-max="' + out.toFixed(2) + '" ' +
                                   'placeholder="0.00" value="">' +
                        '</td>';
                    tbody.appendChild(tr);
                });
            }

            // Total outstanding footer
            if (tfoot) {
                tfoot.style.display = '';
                document.getElementById('totalOutstandingCell').textContent = totalOut.toFixed(2);
            }
            currentTotalOutstanding = totalOut;
            attachAllocationInputListeners();
        }

        // ── Fill all outstanding in one click (rent + penalties) ─────────────────
        function fillAllOutstanding() {
            var total = 0;
            document.querySelectorAll('#allocationTableBody .allocation-input').forEach(function(inp) {
                var max = parseFloat(inp.getAttribute('data-max')) || 0;
                inp.value = max > 0 ? max.toFixed(2) : '';
                total += max;
            });
            document.getElementById('amountInput').value = total.toFixed(2);
            updateAllocationSummary();
        }

        // ── Auto-fill only the target installment ────────────────────────────────
        function prefillTargetInstallment() {
            if (!PHP_COLLECT_BALANCE || !PHP_INSTALLMENT_ID) return;
            document.querySelectorAll('#allocationTableBody .allocation-input').forEach(function(inp) {
                if (parseInt(inp.getAttribute('data-inst-id')) === PHP_INSTALLMENT_ID) {
                    var max = parseFloat(inp.getAttribute('data-max')) || 0;
                    if (max > 0) {
                        inp.value = max.toFixed(2);
                        // Set payment amount to this outstanding
                        var amtInput = document.getElementById('amountInput');
                        if (!amtInput.value || parseFloat(amtInput.value) === 0) {
                            amtInput.value = max.toFixed(2);
                        }
                    }
                }
            });
            updateAllocationSummary();
        }

        function prefillTargetBillingItem() {
            if (!PHP_COLLECT_BALANCE || !PHP_BILLING_ITEM_ID) return;
            document.querySelectorAll('#allocationTableBody .allocation-input[data-billing-id]').forEach(function(inp) {
                if (parseInt(inp.getAttribute('data-billing-id')) === PHP_BILLING_ITEM_ID) {
                    var max = parseFloat(inp.getAttribute('data-max')) || 0;
                    if (max > 0) {
                        inp.value = max.toFixed(2);
                        var amtInput = document.getElementById('amountInput');
                        if (!amtInput.value || parseFloat(amtInput.value) === 0) {
                            amtInput.value = max.toFixed(2);
                        }
                    }
                }
            });
            updateAllocationSummary();
        }

        // ── Allocation summary bar ────────────────────────────────────────────────
        function attachAllocationInputListeners() {
            document.querySelectorAll('.allocation-input').forEach(function(inp) {
                inp.removeEventListener('input', updateAllocationSummary);
                inp.addEventListener('input', updateAllocationSummary);
            });
        }

        function updateAllocationSummary() {
            var amount     = parseFloat(document.getElementById('amountInput').value) || 0;
            var credit     = parseFloat(document.getElementById('applyCreditInput').value) || 0;
            var allocSum   = 0;
            document.querySelectorAll('.allocation-input').forEach(function(inp) {
                allocSum += parseFloat(inp.value) || 0;
            });
            var totalUsed  = allocSum + credit;
            var remainder  = amount - totalUsed;

            document.getElementById('allocSumLabel').textContent = 'Allocated: ' + allocSum.toFixed(2) + ' AED';
            var remainderEl = document.getElementById('remainderLabel');
            if (remainder > 0.005) {
                remainderEl.innerHTML = '<span class="text-info"><i class="bi bi-arrow-right-circle"></i> ' + remainder.toFixed(2) + ' AED will go to tenant credit</span>';
            } else if (remainder < -0.005) {
                remainderEl.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Over-allocated by ' + (-remainder).toFixed(2) + ' AED</span>';
            } else {
                remainderEl.innerHTML = amount > 0 ? '<span class="text-success"><i class="bi bi-check-circle"></i> Fully allocated</span>' : '';
            }
        }

        // ── Build AJAX URL including collect-balance context ──────────────────────
        function buildAjaxUrl(leaseId) {
            var url = 'ajax_get_installments.php?lease_id=' + leaseId;
            if (PHP_INSTALLMENT_ID) url += '&installment_id=' + PHP_INSTALLMENT_ID;
            if (PHP_BILLING_ITEM_ID) url += '&billing_item_id=' + PHP_BILLING_ITEM_ID;
            if (PHP_COLLECT_BALANCE) url += '&collect_balance=1';
            return url;
        }

        // ── Multi-Unit banner renderer ────────────────────────────────────────────
        function renderMultiUnitBanner(leaseInfo, installments) {
            var banner = document.getElementById('multiUnitBanner');
            if (!banner) return;
            if (!leaseInfo || !leaseInfo.is_multi_unit || !leaseInfo.units || !leaseInfo.units.length) {
                banner.style.display = 'none';
                banner.innerHTML = '';
                return;
            }
            var units     = leaseInfo.units;
            var numInst   = installments.length > 0 ? (installments.length + '+ installments') : 'installments';
            var totalAR   = leaseInfo.annual_rent || 0;

            var rows = units.map(function(u, i) {
                var pct = totalAR > 0 ? ((u.annual_rent / totalAR) * 100).toFixed(1) : '0.0';
                return '<tr>' +
                    '<td class="fw-semibold">' + (i + 1) + '. ' + h2(u.building_name) + ' — Unit ' + h2(u.unit_number) +
                        ' <small class="text-muted">(' + h2(u.unit_type) + ')</small></td>' +
                    '<td class="text-end">' + Number(u.annual_rent).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' AED/yr</td>' +
                    '<td class="text-end">' + Number(u.monthly_rent).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' AED/mo</td>' +
                    '<td class="text-end"><span class="badge bg-secondary">' + pct + '%</span></td>' +
                    '</tr>';
            }).join('');

            banner.innerHTML =
                '<div class="alert alert-info border-info p-0 overflow-hidden">' +
                    '<div class="px-3 py-2 d-flex align-items-center gap-2" style="background:rgba(13,110,253,0.08)">' +
                        '<i class="bi bi-buildings-fill text-primary fs-5"></i>' +
                        '<strong class="text-primary">Multi-Unit Lease</strong>' +
                        '<span class="badge bg-primary ms-1">' + units.length + ' Units</span>' +
                        '<span class="ms-auto text-muted small">Each installment covers <strong>all ' + units.length + ' units</strong> combined</span>' +
                    '</div>' +
                    '<div class="px-3 pb-2 pt-1">' +
                        '<table class="table table-sm table-borderless mb-0 small">' +
                            '<thead><tr class="text-muted">' +
                                '<th>Unit</th><th class="text-end">Annual Rent</th>' +
                                '<th class="text-end">Monthly</th><th class="text-end">Share</th>' +
                            '</tr></thead>' +
                            '<tbody>' + rows + '</tbody>' +
                            '<tfoot><tr class="fw-bold border-top">' +
                                '<td>Total</td>' +
                                '<td class="text-end">' + Number(totalAR).toLocaleString('en-US', {minimumFractionDigits:2}) + ' AED/yr</td>' +
                                '<td class="text-end">' + Number(totalAR/12).toLocaleString('en-US', {minimumFractionDigits:2}) + ' AED/mo</td>' +
                                '<td class="text-end">100%</td>' +
                            '</tr></tfoot>' +
                        '</table>' +
                        '<small class="text-muted">' +
                            '<i class="bi bi-info-circle me-1"></i>' +
                            'Payments are recorded as a combined amount covering all units. ' +
                            'Each cheque/payment is posted to <strong>Deferred Rent Revenue</strong> and recognised monthly via Revenue Recognition.' +
                        '</small>' +
                    '</div>' +
                '</div>';
            banner.style.display = 'block';
        }

        function h2(s) { // minimal HTML escape for JS use
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── Called when installments data arrives from AJAX ───────────────────────
        function onInstallmentsLoaded(data) {
            if (Array.isArray(data)) {
                data = { installments: data, tenant_credit_balance: 0, allocation_supported: false };
            }
            var list         = data.installments || [];
            var penaltyItems = data.penalty_items || [];
            allocationSupported   = !!data.allocation_supported;
            tenantCreditBalance   = parseFloat(data.tenant_credit_balance) || 0;
            currentInstallments   = list;
            _currentPenalties     = penaltyItems;
            currentTotalOutstanding = parseFloat(data.total_outstanding) || 0;

            // Cache lease info for allocation table rows
            _leaseInfoCache = data.lease_info || null;

            // Render multi-unit banner if applicable
            renderMultiUnitBanner(_leaseInfoCache, list);

            // Populate legacy installment dropdown (hidden in allocation mode)
            var installmentSelect = document.getElementById('installmentSelect');
            installmentSelect.innerHTML = '<option value="">-- Not linked to installment --</option>';
            list.forEach(function(inst) {
                var opt = document.createElement('option');
                opt.value = inst.id;
                opt.textContent = (inst.installment_date || '') + ' — ' + parseFloat(inst.amount).toFixed(2) + ' AED (' + (inst.status || 'pending') + ')' +
                                  (inst.cheque_number ? ' · Chq #' + inst.cheque_number : '');
                installmentSelect.appendChild(opt);
            });

            var allocationCard     = document.getElementById('allocationCard');
            var installmentRow     = document.getElementById('installmentSelectRow');
            var amountLabel        = document.getElementById('amountLabel');

            if (allocationSupported) {
                allocationCard.style.display = 'block';
                installmentRow.style.display = 'none';
                amountLabel.textContent = 'Total Payment (AED) *';

                // Tenant credit
                if (tenantCreditBalance > 0) {
                    var cd1 = document.getElementById('tenantCreditDisplay');
                    var cd2 = document.getElementById('tenantCreditDisplay2');
                    var applyCreditRow = document.getElementById('applyCreditRow');
                    if (cd1) { cd1.style.removeProperty('display'); }
                    if (cd2) { cd2.style.display = 'block'; }
                    if (applyCreditRow) applyCreditRow.style.display = 'block';
                    document.getElementById('tenantCreditAmount').textContent = tenantCreditBalance.toFixed(2);
                    document.getElementById('tenantCreditAmount2').textContent = tenantCreditBalance.toFixed(2);
                    document.getElementById('applyCreditInput').max = tenantCreditBalance;
                }
                var useAllocInp = document.getElementById('useAllocationHidden');
                if (useAllocInp) { useAllocInp.value = '1'; useAllocInp.removeAttribute('disabled'); }

                renderAllocationTable(list, penaltyItems);
                prefillTargetInstallment();
                prefillTargetBillingItem();
                updateAllocationSummary();
            } else {
                allocationCard.style.display = 'none';
                installmentRow.style.display = 'block';
                amountLabel.textContent = 'Amount (AED) *';
                var useAllocInp = document.getElementById('useAllocationHidden');
                if (useAllocInp) { useAllocInp.value = ''; useAllocInp.setAttribute('disabled', 'disabled'); }
            }
        }

        // ── Lease selector wiring ────────────────────────────────────────────────
        function loadInstallmentsForLease(leaseId) {
            if (!leaseId) return;
            document.getElementById('allocationCard').style.display = 'none';
            document.getElementById('installmentSelectRow').style.display = 'block';
            fetch(buildAjaxUrl(leaseId))
                .then(function(r) { return r.json(); })
                .then(onInstallmentsLoaded)
                .catch(function(e) { console.error('Error loading installments:', e); });
        }

        (function() {
            if (typeof $ !== 'undefined' && $.fn.select2) {
                $(function() {
                    $('#leaseSelect').select2({ theme: 'bootstrap-5', placeholder: '-- Select Lease --', allowClear: true, width: '100%' });
                    $(document).on('change', '#leaseSelect', function() {
                        loadInstallmentsForLease($(this).val());
                    });
                    if ($('#leaseSelect').val()) {
                        $('#leaseSelect').trigger('change');
                    }
                });
            } else {
                document.getElementById('leaseSelect').addEventListener('change', function() {
                    loadInstallmentsForLease(this.value);
                });
                var ls = document.getElementById('leaseSelect');
                if (ls.value) ls.dispatchEvent(new Event('change'));
            }
        })();

        // ── Amount & credit inputs update summary ─────────────────────────────────
        document.getElementById('amountInput').addEventListener('input', function() {
            if (allocationSupported) updateAllocationSummary();
        });
        document.getElementById('applyCreditInput').addEventListener('input', function() {
            if (allocationSupported) updateAllocationSummary();
        });

        // ── Before submit: name allocation inputs so they POST correctly ──────────
        document.querySelector('form').addEventListener('submit', function() {
            if (!allocationSupported) return;
            // Only rent/installment inputs (they have data-inst-id) should be remapped to allocation[installment_id].
            // Penalty inputs are billing_alloc[...] and do NOT have data-inst-id.
            document.querySelectorAll('#allocationTableBody .allocation-input[data-inst-id]').forEach(function(inp) {
                inp.removeAttribute('name');
                var instId = inp.getAttribute('data-inst-id');
                var val = parseFloat(inp.value);
                if (val > 0 && instId) {
                    inp.name = 'allocation[' + instId + ']';
                }
            });
        });

        // ── Bank account show/hide ────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            var pmSel   = document.getElementById('paymentMethodSelect');
            var bawWrap = document.getElementById('bankAccountWrapper');
            var baSel   = document.getElementById('bankAccountSelect');
            function toggleBank() {
                var m = pmSel.value;
                var show = (m === 'bank_transfer' || m === 'cheque' || m === 'auto_debit' || m === 'cash_deposit');
                bawWrap.style.display = show ? 'block' : 'none';
                if (show) { baSel.setAttribute('required','required'); }
                else { baSel.removeAttribute('required'); baSel.value = ''; }
            }
            pmSel.addEventListener('change', toggleBank);
            toggleBank();
        });
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

