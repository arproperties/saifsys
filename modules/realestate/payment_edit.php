<?php
/**
 * Real Estate Module - Edit Payment
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

// Get bank accounts for payment account selection (same as Record Payment page)
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
    error_log("Error loading bank accounts: " . $e->getMessage());
}

$paymentId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$success = '';
$error = '';

// Get payment details
if ($paymentId) {
    $stmt = $conn->prepare("
        SELECT p.*, 
               l.lease_number,
               li.installment_date, li.amount as installment_amount
        FROM re_payments p
        JOIN re_leases l ON l.id = p.lease_id
        LEFT JOIN re_lease_installments li ON li.id = p.installment_id
        WHERE p.id = ? AND p.company_id = ?
    ");
    $stmt->execute([$paymentId, $currentCompanyId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        header('Location: payments.php');
        exit;
    }
} else {
    header('Location: payments.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $amount = !empty($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $paymentMethod = $_POST['payment_method'] ?? 'bank_transfer';
    $bankAccountId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $referenceNumber = trim($_POST['reference_number'] ?? '');
    $receiptNumber = trim($_POST['receipt_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if ($paymentDate && $amount > 0) {
        try {
            $conn->beginTransaction();
            
            // Update payment (include bank_account_id if column exists)
            $stmt = $conn->prepare("
                UPDATE re_payments 
                SET payment_date = ?, amount = ?, payment_method = ?, bank_account_id = ?,
                    reference_number = ?, receipt_number = ?, notes = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $paymentDate, $amount, $paymentMethod, $bankAccountId,
                $referenceNumber, $receiptNumber, $notes,
                $paymentId, $currentCompanyId
            ]);
            
            // Update installment status if linked
            if ($payment['installment_id']) {
                $installmentAmount = (float)$payment['installment_amount'];
                
                // Calculate total paid for this installment
                $stmt = $conn->prepare("
                    SELECT COALESCE(SUM(amount), 0) as total_paid
                    FROM re_payments
                    WHERE installment_id = ?
                ");
                $stmt->execute([$payment['installment_id']]);
                $totalPaid = (float)($stmt->fetchColumn() ?: 0);
                
                // Determine status based on payment amount
                if ($totalPaid >= $installmentAmount) {
                    $newStatus = 'paid';
                } elseif ($totalPaid > 0) {
                    $newStatus = 'partial';
                } else {
                    $newStatus = 'pending';
                }
                
                // Update installment status
                $stmt = $conn->prepare("
                    UPDATE re_lease_installments 
                    SET status = ?, paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END
                    WHERE id = ?
                ");
                $stmt->execute([$newStatus, $newStatus, $payment['installment_id']]);
            }
            
            $conn->commit();

            // ── Reverse the old accounting journal and repost with updated values ──
            // This keeps the General Ledger accurate when a payment is edited.
            try {
                require_once __DIR__ . '/accounting/accounting_integration.php';
                $accResult = reverse_and_repost_payment($paymentId, $currentCompanyId, $userId, $bankAccountId);
                if (!$accResult['success']) {
                    error_log("Accounting reversal/repost failed for payment {$paymentId}: " . $accResult['error']);
                    $_SESSION['accounting_warning'] = "Payment updated, but the accounting journal could not be corrected automatically. Reason: {$accResult['error']}. Please reverse and re-post it manually from Journal Entries.";
                }
            } catch (Exception $accEx) {
                error_log("Accounting reversal exception for payment {$paymentId}: " . $accEx->getMessage());
                $_SESSION['accounting_warning'] = "Payment updated, but an error occurred updating the accounting journal: {$accEx->getMessage()}. Please correct it manually.";
            }

            header('Location: payment_view.php?id=' . $paymentId);
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
$pageTitle = 'Edit Payment';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <h1>Edit Payment</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" id="paymentForm">
                    <?php csrf_field(); ?>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Lease</label>
                            <input type="text" class="form-control" value="<?= h($payment['lease_number'] ?: 'L-' . $payment['lease_id']) ?>" readonly>
                        </div>
                        <?php if ($payment['installment_date']): ?>
                        <div class="col-md-6">
                            <label class="form-label">Installment</label>
                            <input type="text" class="form-control" value="<?= date('Y-m-d', strtotime($payment['installment_date'])) ?> - <?= number_format($payment['installment_amount'], 2) ?> AED" readonly>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Payment Date *</label>
                            <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d', strtotime($payment['payment_date'])) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount (AED) *</label>
                            <input type="number" step="0.01" class="form-control" name="amount" value="<?= number_format($payment['amount'], 2, '.', '') ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Payment Method *</label>
                            <select name="payment_method" class="form-select" id="paymentMethodSelect" required>
                                <option value="bank_transfer" <?= ($payment['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                <option value="cash" <?= ($payment['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                                <option value="cash_deposit" <?= ($payment['payment_method'] ?? '') === 'cash_deposit' ? 'selected' : '' ?>>Cash Deposit</option>
                                <option value="cheque" <?= ($payment['payment_method'] ?? '') === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                                <option value="auto_debit" <?= ($payment['payment_method'] ?? '') === 'auto_debit' ? 'selected' : '' ?>>Auto Debit</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3" id="bankAccountWrapper" style="display: none;">
                            <label class="form-label">Bank Account</label>
                            <select name="bank_account_id" class="form-select" id="bankAccountSelect">
                                <option value="">-- Select Bank Account --</option>
                                <?php foreach ($bankAccounts as $ba): ?>
                                    <option value="<?= (int)$ba['id'] ?>" <?= (isset($payment['bank_account_id']) && (int)$payment['bank_account_id'] === (int)$ba['id']) ? 'selected' : '' ?>>
                                        <?= h($ba['account_code'] . ' - ' . ($ba['gl_account_name'] ?? $ba['account_name'])) ?>
                                        <?php if (!empty($ba['bank_name'])): ?> (<?= h($ba['bank_name']) ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Bank account this payment was deposited to</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" value="<?= h($payment['reference_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Receipt Number</label>
                            <input type="text" class="form-control" name="receipt_number" value="<?= h($payment['receipt_number'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?= h($payment['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Update Payment</button>
                        <a href="payment_view.php?id=<?= $paymentId ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var paymentMethodSelect = document.getElementById('paymentMethodSelect');
        var bankAccountWrapper = document.getElementById('bankAccountWrapper');
        if (!paymentMethodSelect || !bankAccountWrapper) return;
        function toggleBankAccount() {
            var method = paymentMethodSelect.value;
            var show = (method === 'bank_transfer' || method === 'cash_deposit' || method === 'cheque' || method === 'auto_debit');
            bankAccountWrapper.style.display = show ? 'block' : 'none';
        }
        paymentMethodSelect.addEventListener('change', toggleBankAccount);
        toggleBankAccount();
    })();
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
