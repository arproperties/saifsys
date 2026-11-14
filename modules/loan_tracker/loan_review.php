<?php
/**
 * Loan Tracker — New loan, step 4: review everything, confirm it is correct, submit.
 * Submitting makes the loan Active, records who issued it, and saves the instalment schedule.
 *
 *   loan_review.php?id=12
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_helper.php';

$companyId = lt_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';

$q = $conn->prepare("SELECT l.*, e.employee_code, c.name AS emp_company FROM lt_loans l
                       LEFT JOIN employees e ON e.id = l.employee_id LEFT JOIN companies c ON c.id = e.company_id
                      WHERE l.id = ? AND l.company_id = ?");
$q->execute([(int)($_GET['id'] ?? 0), $companyId]);
$loan = $q->fetch(PDO::FETCH_ASSOC);
if (!$loan) {
    lt_flash('That loan was not found.', 'warning');
    header('Location: ' . $ltBase . '/index.php');
    exit;
}
$id = (int)$loan['id'];
if ($loan['status'] !== 'draft') {
    lt_flash(lt_loan_no($id) . ' is already submitted.', 'info');
    header('Location: ' . $ltBase . '/loan_view.php?id=' . $id);
    exit;
}
if (empty($loan['details_saved_at'])) {
    header('Location: ' . $ltBase . '/loan_details.php?id=' . $id);
    exit;
}

$schedule = lt_schedule((float)$loan['total_payable'], (int)$loan['installments'], $loan['first_due_date']);
$issuer = lt_user_name($conn, current_user_id()) ?: 'You';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (empty($_POST['confirm'])) {
        $error = 'Please tick the box to confirm the details are correct.';
    } else {
        try {
            $conn->beginTransaction();
            $up = $conn->prepare("UPDATE lt_loans SET status = 'active', issued_by = ?, submitted_at = NOW() WHERE id = ? AND status = 'draft'");
            $up->execute([current_user_id(), $id]);
            if ($up->rowCount() !== 1) {
                throw new RuntimeException('This loan was already submitted.');
            }
            $conn->prepare("DELETE FROM lt_loan_installments WHERE loan_id = ?")->execute([$id]);
            $ins = $conn->prepare("INSERT INTO lt_loan_installments (loan_id, seq, due_date, amount) VALUES (?,?,?,?)");
            foreach ($schedule as $s) { $ins->execute([$id, $s['seq'], $s['due'], $s['amount']]); }
            // An employee loan is also an HR loan, so payroll can deduct it and HR sees it
            $hrId = ($loan['loan_to'] === 'employee' && (int)$loan['employee_id'] > 0) ? lt_hr_link_create($conn, $loan, current_user_id()) : 0;
            $conn->commit();
            lt_flash(lt_loan_no($id) . ' is submitted and active. ' . count($schedule) . ' instalment' . (count($schedule) === 1 ? '' : 's') . ', first due ' . lt_date($loan['first_due_date']) . '.'
                . ($hrId ? ' It is also added to HR as loan #' . $hrId . ($loan['repayment_mode'] === 'salary' ? ', to be deducted in payroll.' : '.') : ''));
            header('Location: ' . $ltBase . '/loan_view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            error_log('loan_tracker submit: ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'The loan could not be submitted. Please try again.';
        }
    }
}

$dq = $conn->prepare("SELECT * FROM lt_loan_documents WHERE loan_id = ? ORDER BY FIELD(doc_kind,'emirates_id','passport','visa','trade_licence','other'), id");
$dq->execute([$id]);
$docs = $dq->fetchAll(PDO::FETCH_ASSOC);
$t = lt_types()[$loan['loan_to']];
$modes = lt_repayment_modes($loan['loan_to']);
$rate = (float)$loan['interest_rate'];

$pageTitle = 'Review loan';
$pageStyles = '
  .lt-kv small{ display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--lt-muted); }
  .lt-kv div{ font-weight:600; color:var(--lt-ink); }
  .lt-big{ background:var(--lt-tint); border:1px solid var(--lt-tint-3); border-radius:12px; padding:1rem 1.15rem; }
  .lt-big small{ display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--primary-dark); opacity:.85; }
  .lt-big b{ font-size:1.3rem; color:var(--primary-dark); font-variant-numeric:tabular-nums; }
  .lt-ack{ background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:1rem 1.15rem; }
  .lt-ack .form-check-input{ width:1.3em; height:1.3em; margin-top:.1em; }
  .lt-ack .form-check-input:checked{ background-color:#16a34a; border-color:#16a34a; }
';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4" style="max-width:960px">
  <div>
    <h1 class="lt-title">Review &amp; submit</h1>
    <p class="lt-sub"><?= h(lt_loan_no($id)) ?> · check everything before you submit.</p>
  </div>
  <div class="lt-loan-to">
    <span class="lt-loan-to-label">Loan to</span>
    <span class="lt-loan-to-value"><i class="bi <?= $t['icon'] ?>"></i> <?= h($t['label']) ?></span>
  </div>
</div>

<?php lt_steps(4); ?>

<?php if ($error): ?><div class="alert alert-danger" style="max-width:960px"><?= h($error) ?></div><?php endif; ?>

<div style="max-width:960px">
  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="lt-section mb-3"><?= $loan['loan_to'] === 'company' ? 'Company' : 'Borrower' ?></div>
    <div class="row g-3 lt-kv">
      <div class="col-md-4"><small>Name</small><div><?= h($loan['borrower_name']) ?><?= $loan['employee_code'] ? ' <span class="text-muted fw-normal">(' . h($loan['employee_code']) . ')</span>' : '' ?></div></div>
      <?php if ($loan['emp_company']): ?><div class="col-md-4"><small>Company</small><div><?= h($loan['emp_company']) ?></div></div><?php endif; ?>
      <?php if ($loan['contact_name']): ?><div class="col-md-4"><small>Contact person</small><div><?= h($loan['contact_name']) ?></div></div><?php endif; ?>
      <div class="col-md-2"><small>Phone</small><div><?= h($loan['phone'] ?: '—') ?></div></div>
      <div class="col-md-2"><small>Email</small><div class="text-break"><?= h($loan['email'] ?: '—') ?></div></div>
    </div>
  </div></div>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="lt-section mb-3">Documents</div>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($docs as $d): ?>
        <?php if (!empty($d['skipped'])): ?>
          <span class="lt-badge lt-badge-draft" title="<?= h($d['skip_reason']) ?>"><i class="bi bi-file-earmark-x me-1"></i><?= h($d['title']) ?> skipped</span>
        <?php elseif (trim((string)$d['file_path']) === ''): ?>
          <span class="lt-badge lt-badge-cancelled"><?= h($d['title']) ?><?= $d['doc_number'] ? ' · ' . h($d['doc_number']) : '' ?> · no file</span>
        <?php else: ?>
          <a class="lt-tl-file mt-0" target="_blank" rel="noopener" href="<?= h($ltBase) ?>/file.php?id=<?= (int)$d['id'] ?>"><i class="bi bi-paperclip"></i> <?= h($d['title']) ?><?= $d['doc_number'] ? ' · ' . h($d['doc_number']) : '' ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$docs): ?><span class="text-muted small">No documents.</span><?php endif; ?>
    </div>
  </div></div>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="lt-section">Loan details</div>
      <a class="small" href="<?= h($ltBase) ?>/loan_details.php?id=<?= $id ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
    </div>
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3"><div class="lt-big"><small>Amount</small><b>AED <?= lt_money($loan['amount']) ?></b></div></div>
      <div class="col-6 col-md-3"><div class="lt-big"><small>Interest</small><b><?= $rate > 0 ? h(rtrim(rtrim((string)$loan['interest_rate'], '0'), '.')) . '%' : 'None' ?></b></div></div>
      <div class="col-6 col-md-3"><div class="lt-big"><small>Total to pay back</small><b>AED <?= lt_money($loan['total_payable']) ?></b></div></div>
      <div class="col-6 col-md-3"><div class="lt-big"><small>Instalments</small><b><?= (int)$loan['installments'] ?></b></div></div>
    </div>
    <div class="row g-3 lt-kv">
      <div class="col-md-3"><small>Date of issue</small><div><?= h(lt_date($loan['issue_date'])) ?></div></div>
      <div class="col-md-3"><small>Paid in</small><div><?= $loan['paid_out_by'] === 'bank' ? 'Bank transfer' : 'Cash' ?></div></div>
      <div class="col-md-3"><small>Mode of repayment</small><div><?= h($modes[$loan['repayment_mode']] ?? '—') ?></div></div>
      <div class="col-md-3"><small>First due date</small><div><?= h(lt_date($loan['first_due_date'])) ?></div></div>
      <div class="col-md-6"><small>Proof document</small><div>
        <?php if (!empty($loan['proof_path'])): ?><a class="lt-tl-file mt-0" target="_blank" rel="noopener" href="<?= h($ltBase) ?>/file.php?proof=<?= $id ?>"><i class="bi bi-paperclip"></i> <?= h($loan['proof_name'] ?: 'View') ?></a>
        <?php else: ?><span class="text-muted fw-normal">None</span><?php endif; ?></div></div>
    </div>
  </div></div>

  <div class="card card-round mb-3">
    <div class="card-body px-4 pt-4 pb-2"><div class="lt-section">Repayment schedule</div></div>
    <div class="table-responsive" style="max-height:340px;overflow-y:auto">
      <table class="table lt-table">
        <thead><tr><th>#</th><th>Due date</th><th class="text-end">Amount (AED)</th></tr></thead>
        <tbody>
          <?php foreach ($schedule as $s): ?>
            <tr><td class="text-muted"><?= (int)$s['seq'] ?></td><td><?= h(lt_date($s['due'])) ?></td><td class="text-end fw-semibold" style="font-variant-numeric:tabular-nums"><?= lt_money($s['amount']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <form method="post" action="<?= h($ltBase) ?>/loan_review.php?id=<?= $id ?>" class="card card-round mb-3">
    <?php csrf_field(); ?>
    <div class="card-body p-4">
      <div class="row g-3 align-items-center mb-3 lt-kv">
        <div class="col-md-6"><small>Issued by</small><div><i class="bi bi-person-check me-1" style="color:var(--lt-orange)"></i><?= h($issuer) ?></div></div>
        <div class="col-md-6"><small>Date</small><div><?= h(lt_date(date('Y-m-d'))) ?></div></div>
      </div>
      <div class="lt-ack">
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" id="confirm" name="confirm" value="1" required>
          <label class="form-check-label fw-semibold" for="confirm">
            I confirm that the details above are correct.
          </label>
        </div>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-4">
        <a href="<?= h($ltBase) ?>/loan_details.php?id=<?= $id ?>" class="btn btn-lt-ghost"><i class="bi bi-arrow-left me-1"></i> Back</a>
        <button type="submit" class="btn btn-lt"><i class="bi bi-check2-circle me-1"></i> Submit</button>
      </div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/lt_layout_footer.php';
