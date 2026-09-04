<?php
/**
 * Real Estate Module - Cheque View & Status Management
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/billing_helper.php';
require_once __DIR__ . '/includes/cheque_lifecycle_helper.php';
require_once __DIR__ . '/includes/receipt_allocation_engine.php';
require_once __DIR__ . '/includes/lease_schedule_engine.php';
require_once __DIR__ . '/../legal/includes/legal_helper.php';

require_login();
if (!legal_can_manage($conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$chequeId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$chequeId) {
    header('Location: billing_cheques.php');
    exit;
}

// Get cheque details first (needed for POST handler)
$cheque = $conn->prepare("
    SELECT 
        c.*,
        l.lease_number,
        l.accounting_mode,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email
    FROM re_post_dated_cheques c
    JOIN re_leases l ON l.id = c.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE c.id = ? AND c.company_id = ?
");
$cheque->execute([$chequeId, $currentCompanyId]);
$cheque = $cheque->fetch(PDO::FETCH_ASSOC);

if (!$cheque) {
    header('Location: billing_cheques.php');
    exit;
}

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification with better error handling
    if (!isset($_POST['_csrf']) || !hash_equals($_SESSION['_csrf'] ?? '', (string)$_POST['_csrf'])) {
        $_SESSION['error'] = 'CSRF token invalid or missing. Please refresh the page and try again.';
        header('Location: billing_cheque_view.php?id=' . $chequeId);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    if (empty($action)) {
        $_SESSION['error'] = 'Invalid action.';
        header('Location: billing_cheque_view.php?id=' . $chequeId);
        exit;
    }
    
    try {
        switch ($action) {
            case 'replace_cheque':
                $newChequeNumber = trim((string)($_POST['new_cheque_number'] ?? ''));
                $newChequeDate = (string)($_POST['new_cheque_date'] ?? '');
                $newChequeAmount = (float)($_POST['new_cheque_amount'] ?? 0);
                $newBankName = trim((string)($_POST['new_bank_name'] ?? ''));
                $reason = trim((string)($_POST['replacement_reason'] ?? ''));
                if ($newChequeNumber === '' || $newChequeDate === '' || $newChequeAmount <= 0 || $reason === '') {
                    throw new Exception('Replacement cheque number, date, amount, and reason are required.');
                }
                $conn->beginTransaction();
                $stmt = $conn->prepare("
                    INSERT INTO re_post_dated_cheques
                        (company_id, lease_id, installment_id, cheque_number, reference_number, bank_name, account_holder_name,
                         cheque_amount, cheque_date, received_date, status, replacement_for_cheque_id, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'collected', ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId,
                    (int)$cheque['lease_id'],
                    !empty($cheque['installment_id']) ? (int)$cheque['installment_id'] : null,
                    $newChequeNumber,
                    $newChequeNumber,
                    $newBankName ?: ($cheque['bank_name'] ?? null),
                    $cheque['account_holder_name'] ?? null,
                    $newChequeAmount,
                    $newChequeDate,
                    $chequeId,
                    $reason,
                    $currentUserId,
                ]);
                $replacementChequeId = (int)$conn->lastInsertId();
                $conn->prepare("UPDATE re_post_dated_cheques SET replaced_by_cheque_id = ? WHERE id = ? AND company_id = ?")
                    ->execute([$replacementChequeId, $chequeId, $currentCompanyId]);
                $result = re_cheque_update_status($conn, $currentCompanyId, $chequeId, 'replaced', $currentUserId ? (int)$currentUserId : null, $reason, 'billing_cheque_view', null, $replacementChequeId);
                if (empty($result['success'])) {
                    throw new Exception((string)($result['error'] ?? 'Could not mark original cheque replaced.'));
                }
                re_cheque_log_lifecycle($conn, $currentCompanyId, $replacementChequeId, (int)$cheque['lease_id'], null, 'collected', $currentUserId ? (int)$currentUserId : null, 'Replacement for cheque #' . $chequeId, 'billing_cheque_view', null, $chequeId, (string)($cheque['accounting_mode'] ?? 'legacy'));
                $conn->commit();
                $_SESSION['success'] = 'Replacement cheque created and linked. Original cheque remains historical.';
                break;

            case 'settle_alternative':
                $settlementMethod = (string)($_POST['settlement_method'] ?? '');
                $settlementReference = trim((string)($_POST['settlement_reference'] ?? ''));
                $reason = trim((string)($_POST['settlement_reason'] ?? ''));
                if (!in_array($settlementMethod, ['bank_transfer', 'cash', 'card'], true) || $reason === '') {
                    throw new Exception('Settlement method and reason are required.');
                }
                $conn->prepare("
                    UPDATE re_post_dated_cheques
                    SET settlement_method = ?, settlement_reference = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ")->execute([$settlementMethod, $settlementReference ?: null, $chequeId, $currentCompanyId]);
                $result = re_cheque_update_status($conn, $currentCompanyId, $chequeId, 'replaced', $currentUserId ? (int)$currentUserId : null, $reason, 'billing_cheque_view');
                if (empty($result['success'])) {
                    throw new Exception((string)($result['error'] ?? 'Could not mark cheque replaced by settlement.'));
                }
                if (($cheque['accounting_mode'] ?? 'legacy') === 'invoice') {
                    $source = $settlementMethod === 'bank_transfer' ? 'bank_transfer' : $settlementMethod;
                    $query = http_build_query([
                        'lease_id' => (int)$cheque['lease_id'],
                        'amount' => (float)$cheque['cheque_amount'],
                        'receipt_source' => $source,
                        'cleared_date' => date('Y-m-d'),
                        'reference_number' => $settlementReference,
                        'notes' => 'Settlement replacing cheque ' . (string)$cheque['cheque_number'],
                    ]);
                    header('Location: accounting/receipt_allocation.php?' . $query);
                    exit;
                }
                $_SESSION['success'] = 'Cheque marked replaced by ' . str_replace('_', ' ', $settlementMethod) . '.';
                break;

            case 'update_status':
                $status = $_POST['status'] ?? '';
                
                if (empty($status) || !in_array($status, ['pending', 'collected', 'held_by_finance', 'deposited', 'cleared', 'bounced', 'cancelled', 'returned'])) {
                    $_SESSION['error'] = 'Invalid status selected.';
                    header('Location: billing_cheque_view.php?id=' . $chequeId);
                    exit;
                }

                $chequeAccountingMode = re_accounting_normalize_mode((string)($cheque['accounting_mode'] ?? 'legacy'));
                if ($chequeAccountingMode === 'invoice') {
                    if ($status === 'cleared') {
                        $query = http_build_query([
                            'lease_id' => (int)$cheque['lease_id'],
                            'cheque_id' => $chequeId,
                            'amount' => (float)$cheque['cheque_amount'],
                            'receipt_source' => 'cleared_cheque',
                            'cleared_date' => !empty($_POST['cleared_date']) ? $_POST['cleared_date'] : date('Y-m-d'),
                            'reference_number' => (string)($cheque['reference_number'] ?? $cheque['cheque_number'] ?? ''),
                        ]);
                        header('Location: accounting/receipt_allocation.php?' . $query);
                        exit;
                    }

                    $mappedStatus = $status === 'pending' ? 'collected' : $status;
                    $bounceOptions = [];
                    if ($mappedStatus === 'bounced') {
                        $bounceOptions = [
                            'bounced_date' => !empty($_POST['bounced_date']) ? (string)$_POST['bounced_date'] : date('Y-m-d'),
                            'bank_name' => trim((string)($_POST['bank_name'] ?? '')),
                        ];
                    }
                    $result = re_cheque_update_status(
                        $conn,
                        $currentCompanyId,
                        $chequeId,
                        $mappedStatus,
                        $currentUserId ? (int)$currentUserId : null,
                        trim((string)($_POST['bounced_reason'] ?? '')),
                        'billing_cheque_view',
                        null,
                        null,
                        false,
                        $bounceOptions
                    );
                    if (empty($result['success'])) {
                        $_SESSION['error'] = (string)($result['error'] ?? 'Could not update cheque.');
                    } else {
                        if ($mappedStatus === 'bounced') {
                            re_cheque_create_bounced_penalty($conn, $currentCompanyId, $cheque, $currentUserId ? (int)$currentUserId : null);
                        }
                        $_SESSION['success'] = 'Cheque status updated. Invoice Mode clearing must be completed through Receipt Allocation.';
                    }
                    header('Location: billing_cheque_view.php?id=' . $chequeId);
                    exit;
                }
                
                // Get dates from form or keep existing
                $depositedDate = !empty($_POST['deposited_date']) ? $_POST['deposited_date'] : ($cheque['deposited_date'] ?? null);
                $clearedDate = !empty($_POST['cleared_date']) ? $_POST['cleared_date'] : ($cheque['cleared_date'] ?? null);
                $bouncedDate = !empty($_POST['bounced_date']) ? $_POST['bounced_date'] : ($cheque['bounced_date'] ?? null);
                $bouncedReason = !empty($_POST['bounced_reason']) ? trim($_POST['bounced_reason']) : ($cheque['bounced_reason'] ?? null);
                $bankName = $cheque['bank_name'] ?? null;
                if ($status === 'bounced' && array_key_exists('bank_name', $_POST)) {
                    $bankCandidate = trim((string)$_POST['bank_name']);
                    if ($bankCandidate !== '') {
                        $bankName = $bankCandidate;
                    }
                }
                
                // Auto-set dates based on status if not provided
                if ($status === 'deposited' && empty($depositedDate)) {
                    $depositedDate = date('Y-m-d');
                }
                if ($status === 'cleared' && empty($clearedDate)) {
                    $clearedDate = date('Y-m-d');
                }
                if ($status === 'bounced' && empty($bouncedDate)) {
                    $bouncedDate = date('Y-m-d');
                }
                
                // Only set dates for the current status, keep others as is (don't clear them)
                // But if status changes to pending/cancelled/returned, we can clear all dates
                if (in_array($status, ['pending', 'cancelled', 'returned'])) {
                    $depositedDate = null;
                    $clearedDate = null;
                    $bouncedDate = null;
                    $bouncedReason = null;
                } elseif ($status === 'deposited') {
                    // Keep deposited_date, clear cleared/bounced
                    $clearedDate = null;
                    $bouncedDate = null;
                    $bouncedReason = null;
                } elseif ($status === 'cleared') {
                    // Keep deposited_date and cleared_date, clear bounced
                    $bouncedDate = null;
                    $bouncedReason = null;
                } elseif ($status === 'bounced') {
                    // Keep bounced_date and reason, clear cleared (but keep deposited if exists)
                    $clearedDate = null;
                }
                
                $stmt = $conn->prepare("
                    UPDATE re_post_dated_cheques
                    SET status = ?,
                        deposited_date = ?,
                        cleared_date = ?,
                        bounced_date = ?,
                        bounced_reason = ?,
                        bank_name = ?,
                        updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $result = $stmt->execute([
                    $status, 
                    $depositedDate ?: null, 
                    $clearedDate ?: null, 
                    $bouncedDate ?: null, 
                    $bouncedReason ?: null,
                    $bankName ?: null,
                    $chequeId, 
                    $currentCompanyId
                ]);
                
                if (!$result) {
                    throw new Exception('Database update failed. No rows were updated.');
                }

                // Keep legacy/display cheque table aligned when the cheque is tied to an installment.
                if (!empty($cheque['installment_id'])) {
                    $conn->prepare("
                        UPDATE re_lease_cheques
                        SET status = ?,
                            deposited_date = ?,
                            cleared_date = ?,
                            bounced_date = ?,
                            bounced_reason = ?,
                            bank_name = ?,
                            updated_at = NOW()
                        WHERE lease_id = ? AND installment_id = ?
                    ")->execute([
                        $status,
                        $depositedDate ?: null,
                        $clearedDate ?: null,
                        $bouncedDate ?: null,
                        $bouncedReason ?: null,
                        $bankName ?: null,
                        $cheque['lease_id'],
                        $cheque['installment_id'],
                    ]);
                }
                
                // If cleared, create payment record
                if ($status === 'cleared' && !$cheque['payment_id']) {
                        // Create payment record
                        $paymentStmt = $conn->prepare("
                            INSERT INTO re_payments
                            (company_id, lease_id, payment_date, amount, payment_method, reference_number, 
                             cheque_id, created_by)
                            VALUES (?, ?, ?, ?, 'cheque', ?, ?, ?)
                        ");
                        $paymentStmt->execute([
                            $currentCompanyId,
                            $cheque['lease_id'],
                            $clearedDate ?: date('Y-m-d'),
                            $cheque['cheque_amount'],
                            $cheque['cheque_number'],
                            $chequeId,
                            $currentUserId
                        ]);
                        $paymentId = $conn->lastInsertId();

                        // Tenant in-app notification: payment received (cheque cleared)
                        require_once __DIR__ . '/../../includes/tenant_notifications.php';
                        tenant_notification_create($conn, [
                            'company_id' => $currentCompanyId,
                            'lease_id' => (int)$cheque['lease_id'],
                            'type' => 'payment_received',
                            'entity_type' => 'payment',
                            'entity_id' => (int)$paymentId,
                            'title' => 'Payment received',
                            'body' => 'Your cheque payment of AED ' . number_format((float)$cheque['cheque_amount'], 2) . ' has cleared.',
                        ]);
                        
                        // Link payment to cheque
                        $conn->prepare("
                            UPDATE re_post_dated_cheques SET payment_id = ? WHERE id = ?
                        ")->execute([$paymentId, $chequeId]);
                        
                        // Link payment to billing item or installment if applicable
                        if ($cheque['billing_item_id']) {
                            $conn->prepare("
                                UPDATE re_billing_items 
                                SET is_paid = 1, paid_amount = amount, paid_date = ?, payment_id = ?
                                WHERE id = ?
                            ")->execute([$clearedDate ?: date('Y-m-d'), $paymentId, $cheque['billing_item_id']]);
                        }
                        
                        if ($cheque['installment_id']) {
                            $conn->prepare("
                                UPDATE re_lease_installments 
                                SET status = 'paid', paid_at = NOW(), payment_id = ?
                                WHERE id = ?
                            ")->execute([$paymentId, $cheque['installment_id']]);
                        }
                    }
                    
                    // If bounced: auto-create Bounced Fee charge from Penalty Rules (once per cheque)
                    if ($status === 'bounced') {
                        $bouncedRule = $conn->prepare("SELECT * FROM re_penalty_rules WHERE company_id = ? AND penalty_type = 'bounced_cheque' AND is_active = 1 ORDER BY id ASC LIMIT 1");
                        $bouncedRule->execute([$currentCompanyId]);
                        $bouncedRule = $bouncedRule->fetch(PDO::FETCH_ASSOC);
                        $installmentId = !empty($cheque['installment_id']) ? (int)$cheque['installment_id'] : null;
                        if ($bouncedRule && $installmentId) {
                            $exists = $conn->prepare("SELECT 1 FROM re_billing_items WHERE lease_id = ? AND installment_id = ? AND penalty_rule_id = ? AND company_id = ? LIMIT 1");
                            $exists->execute([$cheque['lease_id'], $installmentId, $bouncedRule['id'], $currentCompanyId]);
                            if (!$exists->fetchColumn()) {
                                $dueDate = $bouncedDate ?: date('Y-m-d');
                                create_penalty_billing_item($conn, $currentCompanyId, (int)$cheque['lease_id'], $bouncedRule, (float)$cheque['cheque_amount'], 0, $dueDate, $installmentId);
                            }
                        }
                        require_once __DIR__ . '/includes/collections_helper.php';
                        register_shutdown_function(function() use ($conn, $currentCompanyId, $chequeId) {
                            try {
                                send_bounced_cheque_alert($conn, $currentCompanyId, $chequeId);
                            } catch (Exception $e) {
                                error_log("Bounced cheque alert error: " . $e->getMessage());
                            }
                        });
                    }
                    
                $_SESSION['success'] = 'Cheque status updated successfully.';
                break;
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error'] = 'Error updating cheque: ' . $e->getMessage();
    }
    
    header('Location: billing_cheque_view.php?id=' . $chequeId);
    exit;
}

// Re-fetch cheque details after update
$cheque = $conn->prepare("
    SELECT 
        c.*,
        l.lease_number,
        l.accounting_mode,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email
    FROM re_post_dated_cheques c
    JOIN re_leases l ON l.id = c.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE c.id = ? AND c.company_id = ?
");
$cheque->execute([$chequeId, $currentCompanyId]);
$cheque = $cheque->fetch(PDO::FETCH_ASSOC);

$lifecycleAudit = [];
try {
    $auditStmt = $conn->prepare("
        SELECT a.*, u.username
        FROM re_cheque_lifecycle_audit a
        LEFT JOIN user u ON u.id = a.changed_by
        WHERE a.company_id = ? AND a.cheque_id = ?
        ORDER BY a.changed_at DESC, a.id DESC
    ");
    $auditStmt->execute([$currentCompanyId, $chequeId]);
    $lifecycleAudit = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $lifecycleAudit = [];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Cheque Details';

$chequeAccountingMode = re_accounting_normalize_mode((string)($cheque['accounting_mode'] ?? 'legacy'));
$chequeStatusNormalized = re_cheque_normalize_status((string)($cheque['status'] ?? 'pending'));
$allowedNextStatuses = re_cheque_allowed_next_statuses($chequeStatusNormalized, $chequeAccountingMode);
$chequeReceiptSummary = re_cheque_receipt_summary($conn, $currentCompanyId, $chequeId);
$chequeDisplayStatus = (string)($chequeReceiptSummary['display_status'] ?? re_cheque_normalize_status((string)($cheque['status'] ?? 'pending')));
$chequeDisplayStatusLabel = re_cheque_collection_display_label($chequeDisplayStatus);
$chequeScheduleMethod = (string)($cheque['payment_method'] ?? 'cheque');
$canAllocatePayment = $chequeAccountingMode === 'invoice'
    && $chequeStatusNormalized !== 'replaced'
    && !$chequeReceiptSummary['is_fully_collected'];
$receiptAllocationUrl = re_cheque_allocate_payment_url(
    (int)$cheque['lease_id'],
    $chequeId,
    $cheque,
    $chequeReceiptSummary
);
$statusLabels = [
    'collected' => 'Collected',
    'pending' => 'Pending',
    'held_by_finance' => 'Held by Finance',
    'deposited' => 'Deposited',
    'cleared' => 'Cleared',
    'bounced' => 'Bounced',
    'cancelled' => 'Cancelled',
    'returned' => 'Returned',
    'replaced' => 'Replaced',
];

require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Cheque #<?= h($cheque['cheque_number']) ?></h1>
            <a href="billing_cheques.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= h($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <!-- Cheque Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Cheque Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Cheque Number:</strong> <?= h($cheque['cheque_number']) ?></p>
                                <p><strong>Amount:</strong> <span class="h4 text-primary"><?= number_format($cheque['cheque_amount'], 2) ?> AED</span></p>
                                <p><strong>Cheque Date:</strong> <?= date('M d, Y', strtotime($cheque['cheque_date'])) ?></p>
                                <p><strong>Received Date:</strong> <?= $cheque['received_date'] ? date('M d, Y', strtotime($cheque['received_date'])) : '-' ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Bank Name:</strong> <?= h($cheque['bank_name'] ?: '-') ?></p>
                                <p><strong>Account Holder:</strong> <?= h($cheque['account_holder_name'] ?: '-') ?></p>
                                <p><strong>Status:</strong> 
                                    <span class="badge bg-<?= lease_status_badge_class($chequeDisplayStatus) ?>">
                                        <?= h($chequeDisplayStatusLabel) ?>
                                    </span>
                                </p>
                                <?php if (!empty($cheque['bounced_date']) && ($cheque['status'] ?? '') === 'bounced'): ?>
                                    <p><strong>Bounced Date:</strong> <?= date('M d, Y', strtotime($cheque['bounced_date'])) ?></p>
                                <?php endif; ?>
                                <?php if ($chequeScheduleMethod === 'cheque'
                                    && $chequeDisplayStatus === 'pending'
                                    && strtotime($cheque['cheque_date']) <= time()
                                    && ($chequeReceiptSummary['collected_total'] ?? 0) <= 0.005): ?>
                                    <p class="text-danger"><strong>⚠️ Cheque is due for deposit</strong></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lease Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-text"></i> Lease Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Lease Number:</strong> <?= h($cheque['lease_number']) ?></p>
                                <p><strong>Building:</strong> <?= h($cheque['building_name']) ?></p>
                                <p><strong>Unit:</strong> <?= h($cheque['unit_number']) ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Tenant:</strong> <?= h($cheque['first_name'] . ' ' . $cheque['last_name']) ?></p>
                                <p><strong>Phone:</strong> <?= h($cheque['phone'] ?: '-') ?></p>
                                <p><strong>Email:</strong> <?= h($cheque['email'] ?: '-') ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status History -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Status History</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group">
                            <li class="list-group-item">
                                <strong>Created:</strong> <?= date('M d, Y H:i', strtotime($cheque['created_at'])) ?>
                            </li>
                            <?php if ($cheque['deposited_date']): ?>
                                <li class="list-group-item">
                                    <strong>Deposited:</strong> <?= date('M d, Y', strtotime($cheque['deposited_date'])) ?>
                                </li>
                            <?php endif; ?>
                            <?php if ($cheque['cleared_date']): ?>
                                <li class="list-group-item list-group-item-success">
                                    <strong>Cleared:</strong> <?= date('M d, Y', strtotime($cheque['cleared_date'])) ?>
                                </li>
                            <?php endif; ?>
                            <?php if ($cheque['bounced_date']): ?>
                                <li class="list-group-item list-group-item-danger">
                                    <strong>Bounced:</strong> <?= date('M d, Y', strtotime($cheque['bounced_date'])) ?>
                                    <?php if (!empty($cheque['bank_name'])): ?>
                                        <br><small>Bank: <?= h($cheque['bank_name']) ?></small>
                                    <?php endif; ?>
                                    <?php if ($cheque['bounced_reason']): ?>
                                        <br><small>Reason: <?= h($cheque['bounced_reason']) ?></small>
                                    <?php endif; ?>
                                    <?php if ($cheque['status'] === 'returned'): ?>
                                        <li class="list-group-item list-group-item-secondary">
                                            <strong>Returned:</strong> cheque marked returned to tenant/holder
                                        </li>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list-check"></i> Lifecycle Audit</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($lifecycleAudit)): ?>
                            <div class="text-center text-muted py-3">No lifecycle audit entries yet.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr><th>Date</th><th>Status</th><th>User</th><th>Reason</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($lifecycleAudit as $audit): ?>
                                        <tr>
                                            <td><?= h($audit['changed_at']) ?></td>
                                            <td><?= h(($audit['old_status'] ?: '-') . ' -> ' . $audit['new_status']) ?></td>
                                            <td><?= h($audit['username'] ?: ('User #' . $audit['changed_by'])) ?></td>
                                            <td><?= h($audit['reason'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($cheque['notes']): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5>Notes</h5>
                        <p><?= nl2br(h($cheque['notes'])) ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <?php if ($chequeAccountingMode === 'invoice'): ?>
                <div class="card mb-4 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-cash-coin"></i> Record Payment / Allocate Payment</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 mb-3 small">
                            <div class="col-6">
                                <span class="text-muted d-block">Cheque total</span>
                                <strong><?= number_format($chequeReceiptSummary['cheque_amount'], 2) ?> AED</strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted d-block">Collected</span>
                                <strong class="text-success"><?= number_format($chequeReceiptSummary['collected_total'], 2) ?> AED</strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted d-block">Remaining</span>
                                <strong class="<?= $chequeReceiptSummary['remaining'] > 0.005 ? 'text-danger' : 'text-success' ?>">
                                    <?= number_format($chequeReceiptSummary['remaining'], 2) ?> AED
                                </strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted d-block">Linked receipts</span>
                                <strong><?= (int)$chequeReceiptSummary['receipt_count'] ?></strong>
                            </div>
                        </div>
                        <?php if ($chequeReceiptSummary['receipt_count'] > 0): ?>
                            <ul class="list-group list-group-flush small mb-3">
                                <?php foreach ($chequeReceiptSummary['receipts'] as $rcpt): ?>
                                    <li class="list-group-item px-0 py-1 d-flex justify-content-between">
                                        <a href="payment_view.php?id=<?= (int)$rcpt['id'] ?>" class="text-decoration-none">
                                            <?= h($rcpt['receipt_number'] ?: ('#' . $rcpt['id'])) ?>
                                        </a>
                                        <span><?= number_format((float)$rcpt['amount'], 2) ?> AED</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($canAllocatePayment): ?>
                            <p class="small text-muted mb-3">
                                Invoice Mode cheques are cleared through Receipt Allocation.
                                <?php if ($chequeStatusNormalized === 'cancelled'): ?>
                                    This cheque is currently <strong>cancelled</strong>; allocating a receipt here will reopen it operationally and link the bank receipt.
                                <?php elseif ($chequeReceiptSummary['collected_total'] > 0.005): ?>
                                    Partial payments are supported — enter the remaining balance or another partial amount.
                                <?php endif; ?>
                            </p>
                            <a href="<?= h($receiptAllocationUrl) ?>" class="btn btn-success w-100 mb-2">
                                <i class="bi bi-check2-circle"></i> Allocate Payment
                            </a>
                        <?php else: ?>
                            <div class="alert alert-success mb-0 py-2 small">
                                This cheque is fully collected through linked receipts.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($chequeAccountingMode === 'invoice' && !in_array($chequeStatusNormalized, ['cleared', 'bounced', 'replaced', 'cancelled', 'returned'], true)): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-x-octagon"></i> Mark Cheque as Bounced</h5>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">Records the bounce date and bank, raises any configured penalty, and notifies legal.</p>
                        <form method="POST" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="status" value="bounced">
                            <div class="mb-2">
                                <label class="form-label">Bounced date *</label>
                                <input type="date" name="bounced_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Bank name</label>
                                <input type="text" name="bank_name" class="form-control" value="<?= h($cheque['bank_name'] ?? '') ?>" placeholder="Bank where the cheque bounced">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Bounce reason</label>
                                <textarea name="bounced_reason" class="form-control" rows="3" placeholder="Add details for the record / legal team"></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-x-octagon"></i> Mark Bounced
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($chequeAccountingMode !== 'invoice'): ?>
                <div class="card mb-3 border-0 bg-light">
                    <div class="card-body py-2">
                        <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Cheque lifecycle &amp; exceptions</h5>
                        <div class="small text-muted">Operational status changes, replacement, and alternative settlement.</div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Status</h5>
                        <span class="badge bg-secondary"><?= h($statusLabels[$chequeStatusNormalized] ?? ucfirst($chequeStatusNormalized)) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if ($chequeAccountingMode === 'invoice'): ?>
                            <div class="small text-muted mb-3">Operational status only. Bank clearing always uses Receipt Allocation above.</div>
                        <?php endif; ?>
                        <div class="d-grid gap-2">
                            <?php
                            $quickStatusButtons = [
                                'collected' => ['label' => 'Mark Collected', 'class' => 'btn-outline-primary'],
                                'deposited' => ['label' => 'Mark Deposited', 'class' => 'btn-outline-info'],
                                'held_by_finance' => ['label' => 'Hold by Finance', 'class' => 'btn-outline-warning'],
                                'bounced' => ['label' => 'Mark Bounced', 'class' => 'btn-outline-danger'],
                                'cancelled' => ['label' => 'Cancel Cheque', 'class' => 'btn-outline-secondary'],
                                'returned' => ['label' => 'Mark Returned', 'class' => 'btn-outline-dark'],
                            ];
                            foreach ($quickStatusButtons as $quickStatus => $meta):
                                $isCurrent = $chequeStatusNormalized === $quickStatus
                                    || ($quickStatus === 'held_by_finance' && $chequeStatusNormalized === 'hold');
                                $isAllowed = $isCurrent
                                    || in_array($quickStatus, $allowedNextStatuses, true)
                                    || ($quickStatus === 'collected' && $chequeStatusNormalized === 'cancelled');
                                if (!$isAllowed) {
                                    continue;
                                }
                            ?>
                                <form method="POST" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="status" value="<?= h($quickStatus) ?>">
                                    <button type="submit" class="btn btn-sm w-100 <?= h($meta['class']) ?>" <?= $isCurrent ? 'disabled' : '' ?>>
                                        <?= h($meta['label']) ?><?= $isCurrent ? ' (Current)' : '' ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Status Update -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> Advanced Status</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="statusForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_status">
                            
                            <div class="mb-3">
                                <label class="form-label">New Status *</label>
                                <select name="status" id="status" class="form-select" required>
                                    <?php
                                    $selectableStatuses = array_values(array_unique(array_merge(
                                        [$chequeStatusNormalized === 'hold' ? 'held_by_finance' : $chequeStatusNormalized],
                                        $allowedNextStatuses,
                                        $chequeStatusNormalized === 'cancelled' ? ['collected'] : []
                                    )));
                                    if ($chequeAccountingMode === 'invoice') {
                                        $selectableStatuses[] = 'cleared';
                                    }
                                    $selectableStatuses = array_values(array_unique($selectableStatuses));
                                    foreach ($selectableStatuses as $statusOption):
                                    ?>
                                        <option value="<?= h($statusOption) ?>" <?= ($chequeStatusNormalized === $statusOption || ($statusOption === 'held_by_finance' && $chequeStatusNormalized === 'hold')) ? 'selected' : '' ?>>
                                            <?= h($statusLabels[$statusOption] ?? ucfirst(str_replace('_', ' ', $statusOption))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="depositedDateDiv" style="display: none;">
                                <div class="mb-3">
                                    <label class="form-label">Deposited Date</label>
                                    <input type="date" name="deposited_date" class="form-control" value="<?= $cheque['deposited_date'] ? date('Y-m-d', strtotime($cheque['deposited_date'])) : '' ?>">
                                </div>
                            </div>
                            
                            <div id="clearedDateDiv" style="display: none;">
                                <div class="mb-3">
                                    <label class="form-label">Cleared Date</label>
                                    <input type="date" name="cleared_date" class="form-control" value="<?= $cheque['cleared_date'] ? date('Y-m-d', strtotime($cheque['cleared_date'])) : '' ?>">
                                </div>
                            </div>
                            
                            <div id="bouncedDateDiv" style="display: none;">
                                <div class="mb-3">
                                    <label class="form-label">Bounced Date</label>
                                    <input type="date" name="bounced_date" class="form-control" value="<?= $cheque['bounced_date'] ? date('Y-m-d', strtotime($cheque['bounced_date'])) : '' ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Bank Name</label>
                                    <input type="text" name="bank_name" class="form-control" value="<?= h($cheque['bank_name'] ?? '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Bounced Reason</label>
                                    <textarea name="bounced_reason" class="form-control" rows="3"><?= h($cheque['bounced_reason'] ?? '') ?></textarea>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-circle"></i> Update Status
                            </button>
                        </form>
                    </div>
                </div>

                <?php if (!in_array($cheque['status'], ['cleared', 'cancelled', 'returned', 'replaced'], true)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-arrow-repeat"></i> Replace Cheque</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="replace_cheque">
                            <div class="mb-2">
                                <label class="form-label">New Cheque Number</label>
                                <input type="text" name="new_cheque_number" class="form-control" required>
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2">
                                    <label class="form-label">Date</label>
                                    <input type="date" name="new_cheque_date" class="form-control" required>
                                </div>
                                <div class="col-6 mb-2">
                                    <label class="form-label">Amount</label>
                                    <input type="number" step="0.01" name="new_cheque_amount" class="form-control" value="<?= h($cheque['cheque_amount']) ?>" required>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Bank</label>
                                <input type="text" name="new_bank_name" class="form-control" value="<?= h($cheque['bank_name'] ?? '') ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Reason</label>
                                <input type="text" name="replacement_reason" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-outline-warning w-100">Create Replacement</button>
                        </form>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-credit-card"></i> Settle by Other Method</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="settle_alternative">
                            <div class="mb-2">
                                <label class="form-label">Settlement Method</label>
                                <select name="settlement_method" class="form-select" required>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Reference</label>
                                <input type="text" name="settlement_reference" class="form-control">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Reason</label>
                                <input type="text" name="settlement_reason" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-outline-success w-100">Continue Settlement</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="card mb-4">
                    <div class="card-body py-3 small text-muted">
                        Deposit, bounce, hold, and other operational cheque actions are on the
                        <a href="lease_view.php?id=<?= (int)$cheque['lease_id'] ?>#installments">lease payment schedule</a>.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <a href="lease_view.php?id=<?= $cheque['lease_id'] ?>" class="btn btn-outline-primary w-100 mb-2">
                            <i class="bi bi-file-text"></i> View Lease
                        </a>
                        <a href="billing_cheques.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const statusSelect = document.getElementById('status');
        const statusForm = document.getElementById('statusForm');
        
        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                const status = this.value;
                document.getElementById('depositedDateDiv').style.display = status === 'deposited' ? 'block' : 'none';
                document.getElementById('clearedDateDiv').style.display = status === 'cleared' ? 'block' : 'none';
                document.getElementById('bouncedDateDiv').style.display = status === 'bounced' ? 'block' : 'none';
                
                // Auto-fill dates with today's date if empty and status requires it
                const today = new Date().toISOString().split('T')[0];
                if (status === 'deposited') {
                    const depositedDateInput = document.querySelector('input[name="deposited_date"]');
                    if (depositedDateInput && !depositedDateInput.value) {
                        depositedDateInput.value = today;
                    }
                }
                if (status === 'cleared') {
                    const clearedDateInput = document.querySelector('input[name="cleared_date"]');
                    if (clearedDateInput && !clearedDateInput.value) {
                        clearedDateInput.value = today;
                    }
                }
                if (status === 'bounced') {
                    const bouncedDateInput = document.querySelector('input[name="bounced_date"]');
                    if (bouncedDateInput && !bouncedDateInput.value) {
                        bouncedDateInput.value = today;
                    }
                }
            });
            
            // Trigger on load
            statusSelect.dispatchEvent(new Event('change'));
        }
        
        // Handle form submission with loading state
        if (statusForm) {
            statusForm.addEventListener('submit', function(e) {
                // Don't prevent default - let form submit normally
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Updating...';
                }
            });
        }
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

