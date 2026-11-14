<?php
/**
 * Loan Tracker — one loan: borrower and documents. Loan details (step 3) come next.
 *   loan_view.php?id=12
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

$q = $conn->prepare("SELECT l.*, e.employee_code, COALESCE(NULLIF(u.fullname,''), u.username) AS issuer_name
                       FROM lt_loans l LEFT JOIN employees e ON e.id = l.employee_id LEFT JOIN user u ON u.id = l.issued_by
                      WHERE l.id = ? AND l.company_id = ?");
$q->execute([(int)($_GET['id'] ?? 0), $companyId]);
$loan = $q->fetch(PDO::FETCH_ASSOC);
if (!$loan) {
    lt_flash('That loan was not found.', 'warning');
    header('Location: ' . $ltBase . '/index.php');
    exit;
}

// Close or delete the loan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $lid = (int)$loan['id'];
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'close' && in_array($loan['status'], ['draft', 'active'], true)) {
        $reason = mb_substr(trim((string)($_POST['close_note'] ?? '')), 0, 255);
        if ($reason === '') {
            lt_flash('Please give the reason for closing the loan.', 'danger');
        } else {
            $conn->prepare("UPDATE lt_loans SET status = 'closed', closed_at = NOW(), closed_by = ?, close_note = ? WHERE id = ?")
                ->execute([current_user_id(), $reason, $lid]);
            lt_flash(lt_loan_no($lid) . ' is closed.');
        }
        header('Location: ' . $ltBase . '/loan_view.php?id=' . $lid);
        exit;
    }
    if ($do === 'delete') {
        $conn->prepare("DELETE FROM lt_loans WHERE id = ? AND company_id = ?")->execute([$lid, $companyId]);
        // Its files: documents, loan proof and repayment proofs all live in the loan's own folder
        $dir = lt_upload_root() . '/' . $lid;
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($dir);
        }
        lt_flash(lt_loan_no($lid) . ' (' . $loan['borrower_name'] . ') is deleted.');
        header('Location: ' . $ltBase . '/index.php');
        exit;
    }
    header('Location: ' . $ltBase . '/loan_view.php?id=' . $lid);
    exit;
}

$q = $conn->prepare("SELECT * FROM lt_loan_documents WHERE loan_id = ? ORDER BY FIELD(doc_kind,'emirates_id','passport','visa','trade_licence','other'), id");
$q->execute([(int)$loan['id']]);
$docs = $q->fetchAll(PDO::FETCH_ASSOC);

$installments = [];
if (!empty($loan['details_saved_at'])) {
    $q = $conn->prepare("SELECT seq, due_date, amount, paid_amount FROM lt_loan_installments WHERE loan_id = ? ORDER BY seq");
    $q->execute([(int)$loan['id']]);
    $installments = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$installments) {
        foreach (lt_schedule((float)$loan['total_payable'], (int)$loan['installments'], $loan['first_due_date']) as $r) {
            $installments[] = ['seq' => $r['seq'], 'due_date' => $r['due'], 'amount' => $r['amount'], 'paid_amount' => 0];
        }
    }
}
$t = lt_types()[$loan['loan_to']];
$s = lt_statuses()[$loan['status']];
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));

$pageTitle = lt_loan_no((int)$loan['id']);
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
  <div>
    <div class="d-flex align-items-center gap-2">
      <h1 class="lt-title"><?= h(lt_loan_no((int)$loan['id'])) ?></h1>
      <span class="lt-badge <?= $s['class'] ?>"><?= h($s['label']) ?></span>
    </div>
    <p class="lt-sub"><span class="lt-type" style="--c:<?= $t['color'] ?>"><i class="bi <?= $t['icon'] ?>"></i><?= h($t['label']) ?></span> · started <?= h(lt_date($loan['created_at'])) ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= h($ltBase) ?>/index.php" class="btn btn-lt-ghost"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
    <a href="<?= h($ltBase) ?>/loan_pdf.php?view=1&id=<?= (int)$loan['id'] ?>" target="_blank" rel="noopener" class="btn btn-lt-ghost"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</a>
    <?php if (in_array($loan['status'], ['draft', 'active'], true)): ?>
      <button type="button" class="btn btn-lt-ghost" data-bs-toggle="collapse" data-bs-target="#ltClose" aria-expanded="false"><i class="bi bi-lock me-1"></i> Close</button>
    <?php endif; ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Delete <?= h(lt_loan_no((int)$loan['id'])) ?> for <?= h(addslashes($loan['borrower_name'])) ?>?\n\nIts documents, schedule and repayments are deleted too. This cannot be undone.');">
      <?php csrf_field(); ?><input type="hidden" name="do" value="delete">
      <button type="submit" class="btn btn-lt-ghost text-danger"><i class="bi bi-trash3 me-1"></i> Delete</button>
    </form>
  </div>
</div>

<?php if (in_array($loan['status'], ['draft', 'active'], true)): ?>
  <div class="collapse" id="ltClose">
    <form method="post" class="card card-round mb-3" style="max-width:960px;border-left:4px solid var(--lt-green)">
      <?php csrf_field(); ?><input type="hidden" name="do" value="close">
      <div class="card-body p-3">
        <label class="form-label small fw-semibold" for="close_note">Reason for closing <span class="lt-req">*</span></label>
        <div class="d-flex flex-wrap gap-2">
          <input type="text" class="form-control flex-grow-1" style="min-width:240px" id="close_note" name="close_note" maxlength="255" required
                 placeholder="e.g. Settled in full outside the system / Written off / Entered by mistake">
          <button type="submit" class="btn btn-lt"><i class="bi bi-lock me-1"></i> Close loan</button>
        </div>
        <div class="form-text">Closing keeps the loan and its history. To record money paid back, use Repayments instead.</div>
      </div>
    </form>
  </div>
<?php endif; ?>
<?php if ($loan['status'] === 'closed' && !empty($loan['close_note'])): ?>
  <div class="alert alert-secondary" style="max-width:960px"><i class="bi bi-lock me-1"></i> Closed <?= h(lt_date($loan['closed_at'])) ?>: <?= h($loan['close_note']) ?></div>
<?php endif; ?>

<?php if ($loan['status'] === 'draft'): $hasDetails = !empty($loan['details_saved_at']); ?>
  <?php lt_steps($hasDetails ? 4 : 3); ?>
  <div class="card card-round mb-3" style="max-width:960px;border-left:4px solid var(--lt-amber)"><div class="card-body p-3 d-flex flex-wrap align-items-center gap-3">
    <i class="bi bi-hourglass-split fs-4" style="color:var(--lt-amber)"></i>
    <div class="me-auto">
      <div class="fw-semibold"><?= $hasDetails ? 'Next: review and submit' : 'Next: loan details' ?></div>
      <div class="small text-muted"><?= $hasDetails ? 'Check everything and confirm it is correct.' : 'Date of issue, amount, instalments and due date.' ?> This loan stays a draft until it is submitted.</div>
    </div>
    <a class="btn btn-lt" href="<?= h($ltBase) ?>/<?= $hasDetails ? 'loan_review.php' : 'loan_details.php' ?>?id=<?= (int)$loan['id'] ?>"><?= $hasDetails ? 'Review &amp; submit' : 'Add loan details' ?> <i class="bi bi-arrow-right ms-1"></i></a>
  </div></div>
<?php endif; ?>

<?php if (!empty($loan['details_saved_at'])):
    $modes = lt_repayment_modes($loan['loan_to']);
    $paid = array_sum(array_map(fn($r) => (float)$r['paid_amount'], $installments));
    $left = max(0.0, (float)$loan['total_payable'] - $paid); ?>
  <div class="card card-round mb-3" style="max-width:960px"><div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="lt-section">Loan details</div>
      <?php if ($loan['status'] !== 'draft'): ?><a class="small" href="<?= h($ltBase) ?>/export.php?what=schedule&id=<?= (int)$loan['id'] ?>"><i class="bi bi-download me-1"></i>Schedule CSV</a><?php endif; ?>
      <?php if ($loan['status'] === 'draft'): ?><a class="small" href="<?= h($ltBase) ?>/loan_details.php?id=<?= (int)$loan['id'] ?>"><i class="bi bi-pencil me-1"></i>Edit</a><?php endif; ?>
    </div>
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3"><div class="p-3 rounded-3" style="background:var(--lt-tint-2);color:var(--primary-dark)"><div class="small fw-semibold">Amount</div><div class="fw-bold fs-5">AED <?= lt_money($loan['amount']) ?></div></div></div>
      <div class="col-6 col-md-3"><div class="p-3 rounded-3" style="background:var(--lt-tint);color:var(--primary-dark)"><div class="small fw-semibold">Total to pay back</div><div class="fw-bold fs-5">AED <?= lt_money($loan['total_payable']) ?></div></div></div>
      <div class="col-6 col-md-3"><div class="p-3 rounded-3" style="background:#dcfce7;color:#166534"><div class="small fw-semibold">Paid back</div><div class="fw-bold fs-5">AED <?= lt_money($paid) ?></div></div></div>
      <div class="col-6 col-md-3"><div class="p-3 rounded-3" style="background:#fef3c7;color:#92400e"><div class="small fw-semibold">Still to pay</div><div class="fw-bold fs-5">AED <?= lt_money($left) ?></div></div></div>
    </div>
    <div class="row g-3 small">
      <div class="col-md-3"><div class="text-muted">Date of issue</div><div class="fw-semibold"><?= h(lt_date($loan['issue_date'])) ?></div></div>
      <div class="col-md-3"><div class="text-muted">Instalments</div><div class="fw-semibold"><?= (int)$loan['installments'] ?><?= (float)$loan['interest_rate'] > 0 ? ' · interest ' . h(rtrim(rtrim((string)$loan['interest_rate'], '0'), '.')) . '%' : '' ?></div></div>
      <div class="col-md-3"><div class="text-muted">Paid in · Repayment</div><div class="fw-semibold"><?= $loan['paid_out_by'] === 'bank' ? 'Bank transfer' : 'Cash' ?> · <?= h($modes[$loan['repayment_mode']] ?? '—') ?></div></div>
      <div class="col-md-3"><div class="text-muted">Issued by</div><div class="fw-semibold"><?= h($loan['issuer_name'] ?: '—') ?><?= $loan['submitted_at'] ? ' · ' . h(lt_date($loan['submitted_at'])) : '' ?></div></div>
      <?php if (!empty($loan['proof_path'])): ?>
        <div class="col-12"><a class="lt-tl-file mt-0" target="_blank" rel="noopener" href="<?= h($ltBase) ?>/file.php?proof=<?= (int)$loan['id'] ?>"><i class="bi bi-paperclip"></i> <?= h($loan['proof_name'] ?: 'Proof') ?></a></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-responsive" style="max-height:320px;overflow-y:auto">
    <table class="table lt-table">
      <thead><tr><th>#</th><th>Due date</th><th class="text-end">Amount</th><th class="text-end">Paid</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($installments as $r): $done = (float)$r['paid_amount'] >= (float)$r['amount'] - 0.005; $late = !$done && $r['due_date'] < $today; ?>
          <tr>
            <td class="text-muted"><?= (int)$r['seq'] ?></td>
            <td><?= h(lt_date($r['due_date'])) ?></td>
            <td class="text-end fw-semibold" style="font-variant-numeric:tabular-nums"><?= lt_money($r['amount']) ?></td>
            <td class="text-end" style="font-variant-numeric:tabular-nums"><?= lt_money($r['paid_amount']) ?></td>
            <td><span class="lt-badge <?= $done ? 'lt-badge-closed' : ($late ? '' : 'lt-badge-active') ?>" <?= $late ? 'style="background:#fee2e2;color:#991b1b"' : '' ?>><?= $done ? 'Paid' : ($late ? 'Overdue' : ((float)$r['paid_amount'] > 0 ? 'Part paid' : 'Due')) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
<?php endif; ?>

<div class="card card-round mb-3" style="max-width:960px"><div class="card-body p-4">
  <div class="lt-section mb-3"><?= $loan['loan_to'] === 'company' ? 'Company' : 'Borrower' ?></div>
  <div class="row g-3">
    <div class="col-md-4"><div class="small text-muted">Name</div><div class="fw-semibold"><?= h($loan['borrower_name']) ?><?= $loan['employee_code'] ? ' <span class="text-muted fw-normal">(' . h($loan['employee_code']) . ')</span>' : '' ?></div></div>
    <?php if ($loan['contact_name']): ?><div class="col-md-3"><div class="small text-muted">Contact person</div><div><?= h($loan['contact_name']) ?></div></div><?php endif; ?>
    <div class="col-md-2"><div class="small text-muted">Phone</div><div><?= h($loan['phone'] ?: '—') ?></div></div>
    <div class="col-md-3"><div class="small text-muted">Email</div><div class="text-break"><?= h($loan['email'] ?: '—') ?></div></div>
  </div>
</div></div>

<div class="card card-round" style="max-width:960px">
  <div class="card-body pb-2 px-4 pt-4"><div class="lt-section">Documents</div></div>
  <div class="table-responsive">
    <table class="table lt-table">
      <thead><tr><th>Document</th><th>Number</th><th>Expiry</th><th>Source</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($docs as $d): ?>
          <tr>
            <td class="fw-semibold"><?= h($d['title']) ?></td>
            <td><?= h($d['doc_number'] ?: '—') ?></td>
            <td>
              <?= h(lt_date($d['expires_at'])) ?>
              <?php if ($d['expires_at'] && $d['expires_at'] < $today): ?><span class="lt-badge ms-1" style="background:#ffe4e6;color:#9f1239">Expired</span>
              <?php elseif ($d['expires_at'] && $d['expires_at'] <= $soon): ?><span class="lt-badge lt-badge-draft ms-1">Expires soon</span><?php endif; ?>
            </td>
            <?php if (!empty($d['skipped'])): ?>
              <td colspan="2"><span class="lt-badge lt-badge-draft">Skipped</span> <span class="small text-muted"><?= h($d['skip_reason']) ?></span></td>
            <?php elseif (trim((string)$d['file_path']) === ''): ?>
              <td colspan="2" class="small text-muted">No file</td>
            <?php else: ?>
              <td class="small text-muted"><?= $d['source'] === 'hr' ? 'From HR' : 'Attached' ?></td>
              <td class="text-end"><a class="btn btn-lt-ghost btn-sm" target="_blank" rel="noopener" href="<?= h($ltBase) ?>/file.php?id=<?= (int)$d['id'] ?>"><i class="bi bi-eye me-1"></i>View</a></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$docs): ?><tr><td colspan="5" class="text-muted text-center py-4">No documents.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card card-round mt-3" style="max-width:960px"><div class="card-body p-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="lt-section"><i class="bi bi-clock-history me-1"></i> Timeline</div>
    <?php $tlWho = $loan['loan_to'] === 'employee' ? (string)(int)$loan['employee_id'] : $loan['borrower_name']; ?>
    <a class="small" href="<?= h($ltBase) ?>/timeline.php?<?= h(http_build_query(['to' => $loan['loan_to'], 'who' => $tlWho, 'loan' => 'lt' . (int)$loan['id']])) ?>#lt<?= (int)$loan['id'] ?>">
      All loans of <?= h($loan['borrower_name']) ?> <i class="bi bi-arrow-right"></i></a>
  </div>
  <?php
    $rq = $conn->prepare("SELECT * FROM lt_loan_repayments WHERE loan_id = ? ORDER BY paid_on, id");
    $rq->execute([(int)$loan['id']]);
    lt_render_timeline(lt_loan_events($loan, $docs, $rq->fetchAll(PDO::FETCH_ASSOC)));
  ?>
</div></div>

<?php require __DIR__ . '/includes/lt_layout_footer.php';
