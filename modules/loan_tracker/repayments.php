<?php
/**
 * Loan Tracker — Repayments. Two tabs:
 *   Record a repayment   pick any open loan (staff loan from HR, or a loan made here), record money
 *                        paid back with proof, or close the loan by paying the rest.
 *   Repayment records    every repayment, by month.
 *
 * Staff loans from HR are paid through HR's own hr_loan_settlements (hr_loan_record_settlement), so
 * HR's balance and payroll stay in step. Loans made here use lt_loan_repayments.
 *
 *   repayments.php?loan=hr:12      record on HR loan 12
 *   repayments.php?loan=lt:3       record on Loan Tracker loan 3
 *   repayments.php?tab=records&month=2026-08
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_helper.php';
require_once __DIR__ . '/../../hr/includes/hr_loans.php';

$companyId = lt_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';
$hrBase = get_application_web_root() . '/hr';
$tab = ($_GET['tab'] ?? '') === 'records' ? 'records' : 'record';

/** One open loan of either kind, by key "hr:ID" or "lt:ID", in one shape. */
$getLoan = function (string $key) use ($conn, $companyId): ?array {
    if (!preg_match('/^(hr|lt):(\d+)$/', $key, $m)) { return null; }
    $id = (int)$m[2];
    if ($m[1] === 'hr') {
        foreach (lt_hr_loans($conn) as $r) {
            if ((int)$r['id'] !== $id) { continue; }
            return ['key' => $key, 'kind' => 'hr', 'id' => $id, 'name' => $r['full_name'], 'code' => $r['employee_code'],
                'company' => $r['company_name'], 'label' => ($r['product_type'] === 'loan' ? 'Loan' : 'Salary advance') . ' · HR #' . $id,
                'date' => $r['tx_date'], 'amount' => (float)$r['amount'], 'paid' => (float)$r['recovered'],
                'left' => (float)$r['outstanding'], 'open' => $r['status'] === 'open', 'employee_id' => (int)$r['employee_id'],
                'modes' => ['cash' => 'Cash', 'bank' => 'Bank transfer', 'cheque' => 'Cheque']];
        }
        return null;
    }
    $q = $conn->prepare("SELECT l.*, e.employee_code, c.name AS emp_company FROM lt_loans l
                           LEFT JOIN employees e ON e.id = l.employee_id LEFT JOIN companies c ON c.id = e.company_id
                          WHERE l.id = ? AND l.company_id = ? AND l.status IN ('active','closed')");
    $q->execute([$id, $companyId]);
    $l = $q->fetch(PDO::FETCH_ASSOC);
    if (!$l) { return null; }
    $paid = lt_loan_paid($conn, $id);
    return ['key' => $key, 'kind' => 'lt', 'id' => $id, 'name' => $l['borrower_name'], 'code' => $l['employee_code'],
        'company' => $l['emp_company'], 'label' => lt_loan_no($id) . ' · ' . lt_types()[$l['loan_to']]['label'],
        'date' => $l['issue_date'], 'amount' => (float)$l['total_payable'], 'paid' => $paid,
        'left' => max(0.0, (float)$l['total_payable'] - $paid), 'open' => $l['status'] === 'active',
        'loan_to' => $l['loan_to'], 'who' => $l['loan_to'] === 'employee' ? (string)(int)$l['employee_id'] : $l['borrower_name'],
        'hr_id' => (int)($l['hr_loan_id'] ?? 0),
        'modes' => lt_repayment_modes($l['loan_to'])];
};

$key = (string)($_GET['loan'] ?? '');
if (ctype_digit($key)) { $key = 'hr:' . $key; }
$selfUrl = $ltBase . '/repayments.php' . ($key !== '' ? '?loan=' . rawurlencode($key) : '');
$errors = [];
$v = ['date' => date('Y-m-d'), 'amount' => '', 'paid_by' => 'cash', 'reference' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $key = (string)($_POST['loan'] ?? '');
    $selfUrl = $ltBase . '/repayments.php?loan=' . rawurlencode($key);
    $loan = $getLoan($key);
    $closing = isset($_POST['close']);
    $v = [
        'date' => trim((string)($_POST['date'] ?? '')),
        'amount' => trim((string)($_POST['amount'] ?? '')),
        'paid_by' => (string)($_POST['paid_by'] ?? 'cash'),
        'reference' => mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 100),
        'notes' => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 200),
    ];
    if (!$loan || !$loan['open']) {
        $errors[] = 'This loan is not open, so no repayment can be recorded.';
    } else {
        $balance = round($loan['left'], 2);
        $amount = $closing ? $balance : round((float)str_replace(',', '', $v['amount']), 2);
        if (!isset($loan['modes'][$v['paid_by']])) { $errors[] = 'Please choose how it was paid.'; }
        $d = DateTime::createFromFormat('Y-m-d', $v['date']);
        if (!$d || $d->format('Y-m-d') !== $v['date']) { $errors[] = 'Please enter the date it was paid.'; }
        elseif ($v['date'] > date('Y-m-d')) { $errors[] = 'The date cannot be in the future.'; }
        if ($amount <= 0) { $errors[] = 'Please enter the amount paid.'; }
        elseif ($amount > $balance + 0.005) { $errors[] = 'The amount is more than the AED ' . lt_money($balance) . ' still to pay.'; }
        $file = $_FILES['proof'] ?? null;
        if ($e = lt_upload_error($file, 'Proof')) { $errors[] = $e; }

        if (!$errors) {
            $stored = null;
            try {
                $conn->beginTransaction();
                $fileName = lt_has_upload($file) ? mb_substr(basename((string)$file['name']), 0, 255) : null;
                if ($loan['kind'] === 'hr') {
                    $note = trim($loan['modes'][$v['paid_by']] . ($v['reference'] !== '' ? ' ' . $v['reference'] : '')
                        . ($v['notes'] !== '' ? ' · ' . $v['notes'] : '') . ' · via Loan Tracker');
                    $sid = hr_loan_record_settlement($conn, $loan['id'], $amount, 'cash', $v['date'], current_user_id(), null, null, mb_substr($note, 0, 255));
                    if ($sid > 0) {
                        if (lt_has_upload($file)) { $stored = lt_store_upload($file, 'repayments/' . $sid); }
                        $conn->prepare("INSERT INTO lt_repayment_files (settlement_id, paid_by, reference, file_path, original_name, created_by) VALUES (?,?,?,?,?,?)")
                            ->execute([$sid, $v['paid_by'], $v['reference'] ?: null, $stored, $stored ? $fileName : null, current_user_id()]);
                    }
                    $left = max(0.0, $balance - $amount);
                } else {
                    if (lt_has_upload($file)) { $stored = lt_store_upload($file, $loan['id'] . '/repayments'); }
                    // A loan that is also in HR: record it in HR too, so HR's balance and payroll match
                    $sid = null;
                    if ($loan['hr_id'] > 0) {
                        $note = trim($loan['modes'][$v['paid_by']] . ($v['reference'] !== '' ? ' ' . $v['reference'] : '')
                            . ($v['notes'] !== '' ? ' · ' . $v['notes'] : '') . ' · via Loan Tracker ' . lt_loan_no($loan['id']));
                        $sid = hr_loan_record_settlement($conn, $loan['hr_id'], $amount, $v['paid_by'] === 'salary' ? 'payroll' : 'cash',
                            $v['date'], current_user_id(), null, null, mb_substr($note, 0, 255)) ?: null;
                    }
                    [, $left] = lt_record_lt_repayment($conn, $loan['id'], $amount, $v['date'], $v['paid_by'],
                        $v['reference'], $v['notes'], $stored, $stored ? $fileName : null, current_user_id(), $sid);
                }
                $conn->commit();
                lt_flash('Repayment of AED ' . lt_money($amount) . ' recorded for ' . $loan['name'] . '. '
                    . ($left <= 0.005 ? 'The loan is now closed.' : 'Still to pay: AED ' . lt_money($left) . '.'));
                header('Location: ' . $selfUrl);
                exit;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) { $conn->rollBack(); }
                if ($stored) { @unlink(dirname(__DIR__, 2) . '/' . $stored); }
                error_log('loan_tracker repayment: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException || $e instanceof InvalidArgumentException
                    ? $e->getMessage() : 'The repayment could not be saved. Please try again.';
            }
        }
    }
}

// ---- Record tab: the open loans to choose from, and the chosen loan
$openHr = array_values(array_filter(lt_hr_loans($conn), fn($r) => $r['status'] === 'open' && !$r['hidden']));
usort($openHr, fn($a, $b) => strcmp(($a['company_name'] ?? '') . $a['full_name'], ($b['company_name'] ?? '') . $b['full_name']));
$q = $conn->prepare("SELECT l.id, l.borrower_name, l.loan_to, l.total_payable,
                            l.total_payable - COALESCE((SELECT SUM(r.amount) FROM lt_loan_repayments r WHERE r.loan_id = l.id), 0) AS left_amt
                       FROM lt_loans l WHERE l.company_id = ? AND l.status = 'active' ORDER BY l.borrower_name");
$q->execute([$companyId]);
$openLt = $q->fetchAll(PDO::FETCH_ASSOC);
$loan = $key !== '' ? $getLoan($key) : null;
$history = [];
if ($loan && $loan['kind'] === 'hr') {
    foreach (lt_hr_settlements($conn)[$loan['id']] ?? [] as $s) {
        $history[] = ['date' => $s['settle_date'], 'amount' => $s['amount'],
            'how' => $s['method'] === 'cash' ? lt_paid_by_label($s['paid_by'] ?? null) : 'Deducted from salary' . (!empty($s['period_from']) ? ' · ' . date('M Y', strtotime($s['period_from'])) : ''),
            'ref' => $s['reference'] ?? '', 'proof' => !empty($s['proof_path']) ? $ltBase . '/file.php?rep=' . (int)$s['id'] : null,
            'receipt' => $s['method'] === 'cash' ? $hrBase . '/loan_cash_receipt.php?id=' . (int)$s['id'] : null];
    }
} elseif ($loan) {
    $rq = $conn->prepare("SELECT * FROM lt_loan_repayments WHERE loan_id = ? ORDER BY paid_on, id");
    $rq->execute([$loan['id']]);
    foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $history[] = ['date' => $r['paid_on'], 'amount' => $r['amount'],
            'how' => $r['paid_by'] === 'salary' ? 'Deducted from salary' : lt_paid_by_label($r['paid_by']),
            'ref' => $r['reference'] ?? '', 'proof' => !empty($r['file_path']) ? $ltBase . '/file.php?ltrep=' . (int)$r['id'] : null,
            'receipt' => $ltBase . '/loan_pdf.php?view=1&rep=' . (int)$r['id']];
    }
}
if ($loan && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $v['paid_by'] = array_key_first($loan['modes']);
}

// ---- Records tab: every repayment of both kinds, by month
$records = [];
$recMonths = [];
$recMonth = ($_GET['month'] ?? '') === 'all' ? 'all' : (preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : '');
if ($tab === 'records') {
    $rows = $conn->query("SELECT s.id, s.loan_id, s.settle_date AS d, s.amount, s.method, rf.paid_by, rf.reference, rf.file_path AS proof,
                                 e.full_name AS name, c.name AS company, e.id AS employee_id
                            FROM hr_loan_settlements s JOIN employees e ON e.id = s.employee_id
                            LEFT JOIN companies c ON c.id = e.company_id
                            LEFT JOIN lt_repayment_files rf ON rf.settlement_id = s.id
                           WHERE NOT EXISTS (SELECT 1 FROM lt_loans x WHERE x.hr_loan_id = s.loan_id)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $records[] = ['d' => $r['d'], 'name' => $r['name'], 'sub' => $r['company'] ?: '', 'loan' => 'Staff loan · HR #' . (int)$r['loan_id'],
            'how' => $r['method'] === 'cash' ? lt_paid_by_label($r['paid_by']) : 'Deducted from salary', 'ref' => $r['reference'] ?? '',
            'amount' => (float)$r['amount'], 'proof' => $r['proof'] ? $ltBase . '/file.php?rep=' . (int)$r['id'] : null,
            'receipt' => $r['method'] === 'cash' ? $hrBase . '/loan_cash_receipt.php?id=' . (int)$r['id'] : null,
            'open' => $ltBase . '/repayments.php?loan=hr:' . (int)$r['loan_id']];
    }
    $q = $conn->prepare("SELECT r.*, l.borrower_name, l.loan_to FROM lt_loan_repayments r JOIN lt_loans l ON l.id = r.loan_id WHERE l.company_id = ?");
    $q->execute([$companyId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $records[] = ['d' => $r['paid_on'], 'name' => $r['borrower_name'], 'sub' => lt_types()[$r['loan_to']]['label'], 'loan' => lt_loan_no((int)$r['loan_id']),
            'how' => $r['paid_by'] === 'salary' ? 'Deducted from salary' : lt_paid_by_label($r['paid_by']), 'ref' => $r['reference'] ?? '',
            'amount' => (float)$r['amount'], 'proof' => $r['file_path'] ? $ltBase . '/file.php?ltrep=' . (int)$r['id'] : null,
            'receipt' => $ltBase . '/loan_pdf.php?view=1&rep=' . (int)$r['id'], 'open' => $ltBase . '/repayments.php?loan=lt:' . (int)$r['loan_id']];
    }
    foreach ($records as $r) { $recMonths[substr($r['d'], 0, 7)] = true; }
    krsort($recMonths);
    $recMonths = array_keys($recMonths);
    if ($recMonth === '' && $recMonths) { $recMonth = $recMonths[0]; }
    if ($recMonth !== 'all') {
        $records = array_values(array_filter($records, fn($r) => substr($r['d'], 0, 7) === $recMonth));
    }
    usort($records, fn($a, $b) => strcmp($b['d'], $a['d']));
}

$pageTitle = 'Repayments';
$pageStyles = '
  .lt-tabs{ display:flex; gap:.5rem; border-bottom:2px solid var(--lt-line); margin-bottom:1.5rem; }
  .lt-tabs a{ padding:.65rem 1.1rem; font-weight:600; color:var(--lt-muted); text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-2px; border-radius:8px 8px 0 0; }
  .lt-tabs a:hover{ color:var(--lt-ink); background:var(--lt-tint); }
  .lt-tabs a.active{ color:var(--primary-dark); border-bottom-color:var(--primary); background:#fff; }
  .lt-bal{ border-radius:14px; padding:1rem 1.15rem; }
  .lt-bal small{ display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; opacity:.8; }
  .lt-bal b{ font-size:1.3rem; font-variant-numeric:tabular-nums; }
  .lt-bar{ height:8px; border-radius:99px; background:#e5e7eb; overflow:hidden; }
  .lt-bar > span{ display:block; height:100%; border-radius:99px; background:#22c55e; }
  .btn-lt-close{ background:#dcfce7; color:#166534; border:1px solid #bbf7d0; border-radius:10px; padding:.55rem 1.1rem; font-weight:600; }
  .btn-lt-close:hover{ background:#bbf7d0; color:#14532d; }
  .lt-rec-total{ background:#dcfce7; color:#166534; border-radius:12px; padding:.75rem 1.1rem; font-weight:700; }
';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="mb-3">
  <h1 class="lt-title">Repayments</h1>
  <p class="lt-sub">Record money paid back on any loan, attach the proof, or close the loan.</p>
</div>

<nav class="lt-tabs">
  <a href="<?= h($ltBase) ?>/repayments.php" class="<?= $tab === 'record' ? 'active' : '' ?>"><i class="bi bi-plus-circle me-1"></i> Record a repayment</a>
  <a href="<?= h($ltBase) ?>/repayments.php?tab=records" class="<?= $tab === 'records' ? 'active' : '' ?>"><i class="bi bi-list-check me-1"></i> Repayment records</a>
</nav>

<?php if ($tab === 'record'): ?>

<form method="get" class="card card-round mb-4" id="ltRepPick">
  <div class="card-body p-4">
    <label class="form-label fw-semibold" for="ltRepLoan">Loan</label>
    <select name="loan" id="ltRepLoan" class="form-select lt-search" data-placeholder="<?= $openHr || $openLt ? 'Search by name' : 'No open loans' ?>">
      <option value=""></option>
      <?php if ($openLt): ?>
        <optgroup label="Loan Tracker loans">
          <?php foreach ($openLt as $l): ?>
            <option value="lt:<?= (int)$l['id'] ?>" <?= $loan && $loan['key'] === 'lt:' . (int)$l['id'] ? 'selected' : '' ?>>
              <?= h($l['borrower_name']) ?> · <?= h(lt_loan_no((int)$l['id'])) ?> · <?= h(lt_types()[$l['loan_to']]['label']) ?> · still to pay AED <?= lt_money($l['left_amt']) ?>
            </option>
          <?php endforeach; ?>
        </optgroup>
      <?php endif; ?>
      <?php $grp = null; foreach ($openHr as $r): ?>
        <?php if ($grp !== ($r['company_name'] ?: 'Other')): if ($grp !== null): ?></optgroup><?php endif; $grp = $r['company_name'] ?: 'Other'; ?><optgroup label="Staff loans (HR) · <?= h($grp) ?>"><?php endif; ?>
        <option value="hr:<?= (int)$r['id'] ?>" <?= $loan && $loan['key'] === 'hr:' . (int)$r['id'] ? 'selected' : '' ?>>
          HR #<?= (int)$r['id'] ?> · <?= h($r['full_name']) ?> (<?= h($r['employee_code']) ?>) · given <?= h(lt_date($r['tx_date'])) ?> · still to pay AED <?= lt_money($r['outstanding']) ?>
        </option>
      <?php endforeach; if ($grp !== null): ?></optgroup><?php endif; ?>
    </select>
  </div>
</form>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <div class="fw-semibold mb-1">Please check the following:</div>
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <?php if (!empty($_FILES['proof']['name'])): ?><div class="small mt-2">Please attach the proof again.</div><?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$loan): ?>
  <div class="card card-round"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-cash-stack fs-2 d-block mb-2" style="color:var(--lt-peach)"></i>Choose a loan to record a repayment.
  </div></div>
<?php else:
    $pct = $loan['amount'] > 0 ? (int)round($loan['paid'] / $loan['amount'] * 100) : 100;
    $tlUrl = $loan['kind'] === 'hr'
        ? $ltBase . '/timeline.php?to=employee&who=' . $loan['employee_id'] . '&loan=hr' . $loan['id'] . '#hr' . $loan['id']
        : $ltBase . '/timeline.php?' . http_build_query(['to' => $loan['loan_to'], 'who' => $loan['who'], 'loan' => 'lt' . $loan['id']]) . '#lt' . $loan['id']; ?>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card card-round h-100"><div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
          <div>
            <div class="fw-bold fs-5" style="color:var(--lt-ink)"><?= h($loan['name']) ?></div>
            <div class="small text-muted"><?= h(implode(' · ', array_filter([$loan['code'], $loan['company'], $loan['label'], $loan['date'] ? 'given ' . lt_date($loan['date']) : '']))) ?></div>
          </div>
          <span class="lt-badge <?= $loan['open'] ? 'lt-badge-active' : 'lt-badge-closed' ?>"><?= $loan['open'] ? 'Open' : 'Closed' ?></span>
        </div>
        <div class="row g-2">
          <div class="col-4"><div class="lt-bal" style="background:var(--lt-tint-2);color:var(--primary-dark)"><small>Loan</small><b>AED <?= lt_money($loan['amount']) ?></b></div></div>
          <div class="col-4"><div class="lt-bal" style="background:#dcfce7;color:#166534"><small>Paid back</small><b>AED <?= lt_money($loan['paid']) ?></b></div></div>
          <div class="col-4"><div class="lt-bal" style="background:#fef3c7;color:#92400e"><small>Still to pay</small><b>AED <?= lt_money($loan['left']) ?></b></div></div>
        </div>
        <div class="lt-bar mt-3"><span style="width:<?= max(0, min(100, $pct)) ?>%"></span></div>
        <div class="small text-muted mt-1 mb-4"><?= $pct ?>% paid back</div>

        <?php if (!$loan['open']): ?>
          <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i> This loan is fully paid back and closed.</div>
        <?php else: ?>
          <form method="post" action="<?= h($selfUrl) ?>" enctype="multipart/form-data" id="ltRepForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="loan" value="<?= h($loan['key']) ?>">
            <div class="lt-section mb-3">Record a repayment</div>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small fw-semibold" for="date">Date paid <span class="lt-req">*</span></label>
                <input type="date" class="form-control" id="date" name="date" max="<?= date('Y-m-d') ?>" value="<?= h($v['date']) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold" for="amount">Amount (AED) <span class="lt-req">*</span></label>
                <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" max="<?= h(round($loan['left'], 2)) ?>" value="<?= h($v['amount']) ?>" placeholder="<?= lt_money($loan['left']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold" for="paid_by">Paid by</label>
                <select class="form-select" id="paid_by" name="paid_by">
                  <?php foreach ($loan['modes'] as $k => $l): ?><option value="<?= $k ?>" <?= $v['paid_by'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold" for="reference">Reference</label>
                <input type="text" class="form-control" id="reference" name="reference" maxlength="100" value="<?= h($v['reference']) ?>" placeholder="Receipt / cheque / transfer no.">
              </div>
              <div class="col-md-8">
                <label class="form-label small fw-semibold" for="proof">Proof (receipt, bank slip)</label>
                <input type="file" class="form-control" id="proof" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp">
                <div class="form-text">PDF, JPG, PNG or WEBP, up to 10 MB.</div>
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold" for="notes">Notes</label>
                <input type="text" class="form-control" id="notes" name="notes" maxlength="200" value="<?= h($v['notes']) ?>">
              </div>
            </div>
            <div class="d-flex flex-wrap justify-content-between gap-2 mt-4">
              <button type="submit" name="close" value="1" class="btn btn-lt-close" id="ltCloseBtn"
                      data-msg="Close this loan by recording the full AED <?= h(lt_money($loan['left'])) ?> still to pay?">
                <i class="bi bi-check2-circle me-1"></i> Close loan · pay full AED <?= lt_money($loan['left']) ?>
              </button>
              <button type="submit" class="btn btn-lt"><i class="bi bi-plus-lg me-1"></i> Record repayment</button>
            </div>
          </form>
        <?php endif; ?>
      </div></div>
    </div>

    <div class="col-lg-5">
      <div class="card card-round h-100"><div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="lt-section"><i class="bi bi-clock-history me-1"></i> Repayment history</div>
          <a class="small" href="<?= h($tlUrl) ?>">Full timeline <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if (!$history): ?>
          <div class="text-muted small">Nothing paid back yet.</div>
        <?php else: ?>
          <div class="d-grid gap-2">
            <?php foreach (array_reverse($history) as $s): ?>
              <div class="d-flex justify-content-between align-items-start gap-2 p-2 rounded-3" style="background:#f9fafb;border:1px solid var(--lt-line)">
                <div>
                  <div class="small fw-semibold" style="color:var(--lt-ink)"><?= h($s['how']) ?></div>
                  <div class="small text-muted"><?= h(lt_date($s['date'])) ?><?= $s['ref'] !== '' ? ' · Ref ' . h($s['ref']) : '' ?></div>
                  <?php if ($s['proof'] || $s['receipt']): ?>
                    <div class="d-flex gap-2 mt-1">
                      <?php if ($s['proof']): ?><a class="lt-tl-file mt-0" target="_blank" rel="noopener" href="<?= h($s['proof']) ?>"><i class="bi bi-paperclip"></i> Proof</a><?php endif; ?>
                      <?php if ($s['receipt']): ?><a class="lt-tl-file mt-0" target="_blank" rel="noopener" href="<?= h($s['receipt']) ?>"><i class="bi bi-printer"></i> Receipt</a><?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="fw-bold text-nowrap" style="color:#15803d">− AED <?= lt_money($s['amount']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div></div>
    </div>
  </div>
<?php endif; ?>

<?php else: /* records tab */ $recTotal = array_sum(array_column($records, 'amount')); ?>

<div class="card card-round">
  <div class="card-body px-4 pt-4 pb-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="hidden" name="tab" value="records">
      <label class="small fw-semibold" for="ltRecMonth">Month</label>
      <select name="month" id="ltRecMonth" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <option value="all" <?= $recMonth === 'all' ? 'selected' : '' ?>>All months</option>
        <?php foreach ($recMonths as $m): ?><option value="<?= h($m) ?>" <?= $recMonth === $m ? 'selected' : '' ?>><?= h(date('F Y', strtotime($m . '-01'))) ?></option><?php endforeach; ?>
      </select>
    </form>
    <div class="d-flex flex-wrap gap-2 align-items-center">
    <a class="btn btn-lt-ghost btn-sm" href="<?= h($ltBase) ?>/export.php?what=repayments<?= $recMonth !== 'all' && $recMonth !== '' ? '&month=' . h($recMonth) : '' ?>"><i class="bi bi-download me-1"></i>CSV</a>
    <div class="lt-rec-total"><?= count($records) ?> repayment<?= count($records) === 1 ? '' : 's' ?> · AED <?= lt_money($recTotal) ?></div>
    </div>
  </div>
  <?php if (!$records): ?>
    <div class="text-center text-muted py-5">No repayments<?= $recMonth !== 'all' && $recMonth !== '' ? ' in this month' : ' yet' ?>.</div>
  <?php else: ?>
    <div class="table-responsive" style="max-height:600px;overflow-y:auto">
      <table class="table lt-table">
        <thead><tr><th>Date</th><th>Borrower</th><th>Loan</th><th>How</th><th class="text-end">Amount (AED)</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($records as $r): ?>
            <tr>
              <td class="small text-nowrap"><?= h(lt_date($r['d'])) ?></td>
              <td><a class="fw-semibold text-decoration-none" style="color:var(--lt-ink)" href="<?= h($r['open']) ?>"><?= h($r['name']) ?></a>
                <?php if ($r['sub'] !== ''): ?><div class="small text-muted"><?= h($r['sub']) ?></div><?php endif; ?></td>
              <td class="small"><?= h($r['loan']) ?></td>
              <td class="small"><?= h($r['how']) ?><?= $r['ref'] !== '' ? '<div class="text-muted">Ref ' . h($r['ref']) . '</div>' : '' ?></td>
              <td class="text-end fw-bold text-nowrap" style="color:#15803d;font-variant-numeric:tabular-nums"><?= lt_money($r['amount']) ?></td>
              <td class="text-end text-nowrap">
                <?php if ($r['proof']): ?><a class="lt-tl-file mt-0" target="_blank" rel="noopener" href="<?= h($r['proof']) ?>" title="Proof"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                <?php if ($r['receipt']): ?><a class="lt-tl-file mt-0" target="_blank" rel="noopener" href="<?= h($r['receipt']) ?>" title="Receipt"><i class="bi bi-printer"></i></a><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  var pick = document.getElementById('ltRepLoan');
  if (pick) { pick.addEventListener('change', function () { if (this.value) { document.getElementById('ltRepPick').submit(); } }); }
  var close = document.getElementById('ltCloseBtn');
  if (close) {
    close.addEventListener('click', function (e) {
      if (!confirm(close.dataset.msg)) { e.preventDefault(); }
    });
    document.getElementById('ltRepForm').addEventListener('submit', function (e) {
      var a = document.getElementById('amount');
      if (e.submitter !== close && !a.value) { e.preventDefault(); a.required = true; a.reportValidity(); }
    });
  }
})();
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
