<?php
/**
 * Printable receipt for a cash loan repayment (employee paid HR outside payroll / WPS).
 * Proof for both sides that the cash was handed over. Not part of any payslip.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_loans.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0 || !hr_loan_settlements_table_ready($conn)) {
    http_response_code(400);
    exit('Missing receipt id.');
}

$st = $conn->prepare("
    SELECT s.*, ca.amount AS loan_amount, ca.tx_date AS loan_date, ca.description AS loan_desc,
           e.full_name, e.employee_code,
           u.fullname AS received_by_name, u.username AS received_by_username
    FROM hr_loan_settlements s
    JOIN cash_advances ca ON ca.id = s.loan_id
    JOIN employees e ON e.id = s.employee_id
    LEFT JOIN user u ON u.id = s.created_by
    WHERE s.id = ? AND s.method = 'cash'
");
$st->execute([$id]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    http_response_code(404);
    exit('Cash repayment not found.');
}

// Balance right after this payment: loan amount less every settlement up to and including this one.
$stPaid = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM hr_loan_settlements WHERE loan_id = ? AND id <= ?");
$stPaid->execute([(int)$r['loan_id'], $id]);
$balanceAfter = max(0.0, round((float)$r['loan_amount'] - (float)$stPaid->fetchColumn(), 2));
$balanceBefore = round($balanceAfter + (float)$r['amount'], 2);

$receivedBy = trim((string)($r['received_by_name'] ?? '')) ?: (string)($r['received_by_username'] ?? '');
$receiptNo = 'LCR-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);

function lcr_nf($n) { return number_format((float)$n, 2); }

$pageTitle = 'Cash receipt ' . $receiptNo;
$pageStyles = <<<'CSS'
.receipt{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:28px}
.receipt .line{display:flex;justify-content:space-between;padding:8px 0;border-top:1px dashed #e5e7eb}
.receipt .line:first-of-type{border-top:0}
.receipt .amount{font-size:26px;font-weight:700}
.receipt .sign{display:flex;gap:40px;margin-top:56px}
.receipt .sign div{flex:1;border-top:1px solid #111;padding-top:6px;font-size:.85rem;color:#374151}
@media print{
  .hr-sidebar,.hr-topbar,.hr-unsaved-bar,.no-print{display:none!important}
  .hr-shell{display:block}
  .hr-content{padding:0}
  body{background:#fff}
  .receipt{border:0}
}
CSS;
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Cash repayment receipt',
    h($r['full_name']) . ' · ' . h($receiptNo),
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Loans / Advances', 'href' => 'cash_advances.php'],
        ['label' => 'Receipt'],
    ],
    '<button type="button" class="btn btn-sm btn-outline-secondary no-print" onclick="print()">Print</button>'
);
?>

<div class="receipt">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h5 class="mb-1">Loan repayment — cash received</h5>
      <div class="text-muted small">Paid directly by the employee. Not deducted from salary.</div>
    </div>
    <div class="text-end small">
      <div><strong><?= h($receiptNo) ?></strong></div>
      <div class="text-muted">Date <?= h($r['settle_date']) ?></div>
    </div>
  </div>

  <div class="line"><span>Employee</span><span><strong><?= h($r['full_name']) ?></strong> (<?= h($r['employee_code']) ?>)</span></div>
  <div class="line"><span>Loan</span><span>#<?= (int)$r['loan_id'] ?> · issued <?= h($r['loan_date']) ?> · AED <?= lcr_nf($r['loan_amount']) ?></span></div>
  <?php if (!empty($r['loan_desc'])): ?>
    <div class="line"><span>Loan note</span><span><?= h($r['loan_desc']) ?></span></div>
  <?php endif; ?>
  <div class="line"><span>Balance before</span><span>AED <?= lcr_nf($balanceBefore) ?></span></div>
  <div class="line align-items-center"><span>Amount received</span><span class="amount">AED <?= lcr_nf($r['amount']) ?></span></div>
  <div class="line"><span>Balance after</span><span><strong>AED <?= lcr_nf($balanceAfter) ?></strong></span></div>
  <?php if (!empty($r['notes'])): ?>
    <div class="line"><span>Notes</span><span><?= h($r['notes']) ?></span></div>
  <?php endif; ?>
  <div class="line"><span>Recorded by</span><span><?= h($receivedBy !== '' ? $receivedBy : '—') ?> · <?= h($r['created_at']) ?></span></div>

  <div class="sign">
    <div>Paid by (employee signature)</div>
    <div>Received by (HR signature)</div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
