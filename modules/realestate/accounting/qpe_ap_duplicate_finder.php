<?php
/**
 * Find likely Quick Paid Expense duplicates of Vendor Bills / Payments
 * and hard-remove the QPE side (keep formal AP).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../../../includes/erp_expense_posting.php';
require_once __DIR__ . '/../includes/qpe_ap_duplicate_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}
$userId = current_user_id();
$roles = current_user_roles($conn);
$isOwnerAdmin = in_array('Owner', $roles, true) || in_array('Admin', $roles, true);

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2, '.', ',');
}

$success = '';
$error = '';
$dateWindow = 3;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hard_remove_qpe') {
    csrf_verify();
    if (!$isOwnerAdmin) {
        $error = 'Only Owner or Admin can hard-remove duplicate Quick Paid Expenses.';
    } elseif (empty($_POST['confirm_keep_ap'])) {
        $error = 'Confirm that you will keep the Vendor Bill/Payment and remove only the Quick Paid Expense.';
    } else {
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $billId = (int)($_POST['bill_id'] ?? 0);
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $valid = qpe_ap_match_still_valid($conn, $companyId, $expenseId, $billId, $paymentId, $dateWindow);
        if (empty($valid['ok'])) {
            $error = (string)($valid['error'] ?? 'Match is not valid.');
        } else {
            $res = erp_hard_delete_expense_with_journal($conn, $expenseId, $companyId, $userId, 'realestate');
            if (!empty($res['success'])) {
                $success = (string)($res['message'] ?? 'Quick Paid Expense removed.');
            } else {
                $error = (string)($res['error'] ?? 'Hard delete failed.');
            }
        }
    }
}

$matches = [];
$loadError = '';
try {
    $matches = qpe_ap_find_duplicate_matches($conn, $companyId, $dateWindow);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$pageTitle = 'QPE vs AP Duplicate Finder';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-0"><i class="bi bi-intersect me-2"></i>QPE vs Vendor Bill duplicates</h4>
                <small class="text-muted">Find Quick Paid Expenses that look like the same purchase as a Vendor Bill and/or Payment.</small>
            </div>
            <div class="d-flex gap-2">
                <a href="vendor_ap_diagnostics.php" class="btn btn-outline-secondary btn-sm">Vendor/AP Diagnostics</a>
                <a href="../expenses.php" class="btn btn-outline-primary btn-sm">Quick Paid Expenses</a>
            </div>
        </div>

        <div class="alert alert-warning">
            <strong>Suggested action:</strong> Keep the Vendor Bill / Payment (formal AP). Remove the matching Quick Paid Expense
            <em>and</em> its journal from the ledger (hard undo — no reversal clutter).
            Matches are suggestions only; confirm each case. False positives are possible when two real purchases share vendor, amount, and date.
            <?php if (!$isOwnerAdmin): ?>
                <br><span class="text-danger">Hard-remove requires Owner or Admin role. You can review matches below.</span>
            <?php endif; ?>
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show"><?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($loadError !== ''): ?>
            <div class="alert alert-danger">Could not load matches: <?= h($loadError) ?></div>
        <?php endif; ?>

        <div class="card card-round">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Likely duplicates (±<?= (int)$dateWindow ?> days, same vendor &amp; amount)</h6>
                <span class="badge bg-secondary"><?= count($matches) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (!$matches): ?>
                    <div class="text-center text-muted py-4">No likely duplicates found for this company.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Confidence</th>
                                    <th>Vendor</th>
                                    <th>Quick Paid Expense</th>
                                    <th>Vendor Bill</th>
                                    <th>Vendor Payment</th>
                                    <th>Suggested</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($matches as $i => $mrow): ?>
                                <?php
                                $conf = (string)$mrow['confidence'];
                                $badge = $conf === 'high' ? 'bg-danger' : ($conf === 'medium' ? 'bg-warning text-dark' : 'bg-secondary');
                                $modalId = 'removeQpeModal' . (int)$mrow['expense_id'] . '_' . (int)$mrow['bill_id'] . '_' . (int)$mrow['payment_id'] . '_' . $i;
                                ?>
                                <tr>
                                    <td><span class="badge <?= $badge ?>"><?= h(ucfirst($conf)) ?></span></td>
                                    <td><?= h($mrow['vendor_name']) ?></td>
                                    <td>
                                        <a href="../expense_edit.php?id=<?= (int)$mrow['expense_id'] ?>"><?= h($mrow['expense_number'] ?: ('#' . $mrow['expense_id'])) ?></a><br>
                                        <small class="text-muted"><?= h($mrow['expense_date']) ?> · <?= m($mrow['expense_total']) ?> AED · <?= h($mrow['expense_status']) ?></small>
                                        <?php if ((int)$mrow['expense_journal_id'] > 0): ?>
                                            <br><a class="small" href="journal_entry_view.php?id=<?= (int)$mrow['expense_journal_id'] ?>">Journal #<?= (int)$mrow['expense_journal_id'] ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$mrow['bill_id'] > 0): ?>
                                            <a href="vendor_bill_view.php?id=<?= (int)$mrow['bill_id'] ?>"><?= h($mrow['bill_number'] ?: ('Bill #' . $mrow['bill_id'])) ?></a><br>
                                            <small class="text-muted"><?= h($mrow['bill_date']) ?> · <?= m($mrow['bill_total']) ?> AED</small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$mrow['payment_id'] > 0): ?>
                                            <a href="vendor_payments.php?q=<?= urlencode((string)$mrow['payment_id']) ?>">Payment #<?= (int)$mrow['payment_id'] ?></a><br>
                                            <small class="text-muted"><?= h($mrow['payment_date']) ?> · <?= m($mrow['payment_amount']) ?> AED</small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= h($mrow['suggested_action']) ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($isOwnerAdmin && !empty($mrow['can_hard_remove'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#<?= h($modalId) ?>">
                                                <i class="bi bi-trash"></i> Remove QPE
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Owner/Admin required">Remove QPE</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isOwnerAdmin): foreach ($matches as $i => $mrow):
            $modalId = 'removeQpeModal' . (int)$mrow['expense_id'] . '_' . (int)$mrow['bill_id'] . '_' . (int)$mrow['payment_id'] . '_' . $i;
        ?>
        <div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="hard_remove_qpe">
                        <input type="hidden" name="expense_id" value="<?= (int)$mrow['expense_id'] ?>">
                        <input type="hidden" name="bill_id" value="<?= (int)$mrow['bill_id'] ?>">
                        <input type="hidden" name="payment_id" value="<?= (int)$mrow['payment_id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title text-danger">Hard-remove Quick Paid Expense</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">
                                Remove <strong><?= h($mrow['expense_number'] ?: ('#' . $mrow['expense_id'])) ?></strong>
                                (<?= m($mrow['expense_total']) ?> AED) and purge its journal from the books?
                            </p>
                            <ul class="small text-muted mb-3">
                                <li>Vendor Bill / Payment will <strong>not</strong> be changed.</li>
                                <li>Blocked if the QPE journal is bank-reconciled or the period is locked.</li>
                                <li>This cannot be undone.</li>
                            </ul>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="confirm_keep_ap" value="1" id="confirm<?= h($modalId) ?>" required>
                                <label class="form-check-label" for="confirm<?= h($modalId) ?>">
                                    I confirm this Quick Paid Expense is a duplicate and I will keep the Vendor Bill/Payment.
                                </label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Hard-remove QPE</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
