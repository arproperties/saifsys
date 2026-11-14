<?php
/**
 * Loan Tracker — Loan timeline: pick who the loans are to, then the person or company,
 * and see each of their loans as a timeline.
 *
 *   timeline.php?to=employee&who=60        an employee (HR staff loans + loans added here)
 *   timeline.php?to=person&who=John+Smith  an outside person, by name
 *   timeline.php?to=company&who=ABC+LLC    a company, by name
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
$types = lt_types();

$to = isset($types[$_GET['to'] ?? '']) ? $_GET['to'] : 'employee';
$who = trim((string)($_GET['who'] ?? ''));
$focus = (string)($_GET['loan'] ?? '');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : '';

// Who can be picked: anyone with a loan of this type
if ($to === 'employee') {
    $q = $conn->prepare("SELECT e.id AS v, e.full_name, e.employee_code, c.name AS company_name
                           FROM employees e LEFT JOIN companies c ON c.id = e.company_id
                          WHERE e.id IN (SELECT employee_id FROM cash_advances
                                          WHERE status IN ('open','settled') AND (request_status IS NULL OR request_status = 'approved'))
                             OR e.id IN (SELECT employee_id FROM lt_loans WHERE company_id = ? AND employee_id IS NOT NULL AND status <> 'cancelled')
                          ORDER BY e.full_name");
    $q->execute([$companyId]);
    $people = $q->fetchAll(PDO::FETCH_ASSOC);
} else {
    $q = $conn->prepare("SELECT borrower_name AS v, borrower_name AS full_name, COUNT(*) n FROM lt_loans
                          WHERE company_id = ? AND loan_to = ? AND status <> 'cancelled'
                          GROUP BY borrower_name ORDER BY borrower_name");
    $q->execute([$companyId, $to]);
    $people = $q->fetchAll(PDO::FETCH_ASSOC);
}

// The loans to show: of the one picked, of the month picked, or both. Newest first.
$picked = null;
if ($who !== '') {
    foreach ($people as $p) {
        if ((string)$p['v'] === $who) { $picked = $p; break; }
    }
}
$cards = [];
$months = [];
$sum = ['amount' => 0.0, 'recovered' => 0.0, 'outstanding' => 0.0, 'hr' => 0];
$hrRows = [];
if ($to === 'employee') {
    $hrRows = lt_hr_loans($conn, $picked ? (int)$who : null);
    if (!$picked) {
        $hrRows = array_values(array_filter($hrRows, fn($r) => !$r['hidden']));
    }
    $q = $conn->prepare("SELECT lt_loans.*, COALESCE(NULLIF(u.fullname,''), u.username) AS issuer_name FROM lt_loans LEFT JOIN user u ON u.id = lt_loans.issued_by WHERE lt_loans.company_id = ? AND lt_loans.loan_to = 'employee' AND lt_loans.status <> 'cancelled'" . ($picked ? ' AND lt_loans.employee_id = ?' : ''));
    $q->execute($picked ? [$companyId, (int)$who] : [$companyId]);
} else {
    $q = $conn->prepare("SELECT lt_loans.*, COALESCE(NULLIF(u.fullname,''), u.username) AS issuer_name FROM lt_loans LEFT JOIN user u ON u.id = lt_loans.issued_by WHERE lt_loans.company_id = ? AND lt_loans.loan_to = ? AND lt_loans.status <> 'cancelled'" . ($picked ? ' AND lt_loans.borrower_name = ?' : ''));
    $q->execute($picked ? [$companyId, $to, $who] : [$companyId, $to]);
}
$ltRows = $q->fetchAll(PDO::FETCH_ASSOC);

// Months to choose from: those with a loan given (for the one picked, if any)
foreach ($hrRows as $r) { $months[substr((string)$r['tx_date'], 0, 7)] = true; }
foreach ($ltRows as $l) { $months[substr((string)$l['created_at'], 0, 7)] = true; }
krsort($months);
$months = array_keys($months);
if ($month !== '' && !in_array($month, $months, true)) { $month = ''; }

if ($picked || $month !== '') {
    $inMonth = fn($date) => $month === '' || substr((string)$date, 0, 7) === $month;
    $settle = $hrRows ? lt_hr_settlements($conn) : [];
    foreach ($hrRows as $r) {
        if (!$inMonth($r['tx_date'])) { continue; }
        $sum['hr']++;
        foreach (['amount', 'recovered', 'outstanding'] as $k) { $sum[$k] += (float)$r[$k]; }
        $pct = (float)$r['amount'] > 0 ? (int)round($r['recovered'] / (float)$r['amount'] * 100) : 100;
        $kind = $r['product_type'] === 'loan' ? 'Loan' : 'Salary advance';
        $cards[] = [
            'key' => 'hr' . $r['id'], 'sort' => $r['tx_date'],
            'title' => $picked ? $kind : $r['full_name'],
            'sub' => ($picked ? '' : $kind . ' · ') . 'Given ' . lt_date($r['tx_date']) . ' · HR #' . (int)$r['id'],
            'open' => $r['status'] === 'open', 'badge' => $r['status'] === 'open' ? 'Open' : 'Closed',
            'amount' => lt_money($r['amount']), 'balance' => lt_money($r['outstanding']), 'pct' => $pct,
            'events' => lt_hr_loan_events($r, $settle[(int)$r['id']] ?? []), 'link' => null,
            'repay' => $r['status'] === 'open' ? $ltBase . '/repayments.php?loan=hr:' . (int)$r['id'] : null,
        ];
    }
    $dq = $conn->prepare("SELECT * FROM lt_loan_documents WHERE loan_id = ? ORDER BY FIELD(doc_kind,'emirates_id','passport','visa','trade_licence','other'), id");
    $rq = $conn->prepare("SELECT * FROM lt_loan_repayments WHERE loan_id = ? ORDER BY paid_on, id");
    foreach ($ltRows as $l) {
        if (!$inMonth($l['created_at'])) { continue; }
        $dq->execute([(int)$l['id']]);
        $rq->execute([(int)$l['id']]);
        $reps = $rq->fetchAll(PDO::FETCH_ASSOC);
        $hasAmt = !empty($l['details_saved_at']);
        $paidLt = array_sum(array_map(fn($x) => (float)$x['amount'], $reps));
        $tot = (float)$l['total_payable'];
        if ($hasAmt && $l['status'] !== 'draft') {
            $sum['hr']++;
            $sum['amount'] += $tot; $sum['recovered'] += $paidLt; $sum['outstanding'] += max(0.0, $tot - $paidLt);
        }
        $cards[] = [
            'key' => 'lt' . $l['id'], 'sort' => $l['issue_date'] ?: substr($l['created_at'], 0, 10),
            'title' => $picked ? lt_loan_no((int)$l['id']) : $l['borrower_name'],
            'sub' => ($picked ? '' : lt_loan_no((int)$l['id']) . ' · ') . ($l['issue_date'] ? 'Issued ' . lt_date($l['issue_date']) : 'Started ' . lt_date($l['created_at'])) . ' · in Loan Tracker',
            'open' => $l['status'] !== 'closed', 'badge' => $l['status'] === 'closed' ? 'Closed' : lt_statuses()[$l['status']]['label'],
            'amount' => $hasAmt ? lt_money($tot) : null, 'balance' => $hasAmt ? lt_money(max(0.0, $tot - $paidLt)) : null,
            'pct' => $hasAmt && $tot > 0 ? (int)round($paidLt / $tot * 100) : null,
            'events' => lt_loan_events($l, $dq->fetchAll(PDO::FETCH_ASSOC), $reps),
            'link' => $ltBase . '/loan_view.php?id=' . (int)$l['id'],
            'repay' => $l['status'] === 'active' ? $ltBase . '/repayments.php?loan=lt:' . (int)$l['id'] : null,
        ];
    }
    usort($cards, fn($a, $b) => strcmp($b['sort'], $a['sort']));
}

$pageTitle = 'Loan timeline';
$pageStyles = '
  .lt-tl-card{ border:1px solid var(--lt-line); border-radius:14px; background:#fff; overflow:hidden; }
  .lt-tl-card.focus{ box-shadow:0 0 0 3px var(--lt-tint-3); }
  .lt-tl-card-head{ background:var(--lt-tint); border-bottom:1px solid var(--lt-line); padding:.9rem 1.15rem; }
  .lt-tl-card-body{ padding:1.15rem; }
  .lt-pill{ display:inline-block; border-radius:10px; padding:.35rem .7rem; font-weight:700; font-variant-numeric:tabular-nums; font-size:.9rem; }
  .lt-pill small{ display:block; font-size:.68rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; opacity:.8; }
  .lt-pill-amt{ background:var(--lt-tint-2); color:var(--primary-dark); }
  .lt-pill-bal{ background:var(--primary); color:#fff; }
  .lt-pill-paid{ background:#dcfce7; color:#166534; }
  .lt-bar{ height:8px; border-radius:99px; background:#e5e7eb; overflow:hidden; }
  .lt-bar > span{ display:block; height:100%; border-radius:99px; background:#22c55e; }
  .lt-sum{ border-radius:12px; padding:.9rem 1.1rem; height:100%; }
  .lt-sum-label{ font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; opacity:.85; }
  .lt-sum-value{ font-size:1.4rem; font-weight:700; font-variant-numeric:tabular-nums; }
';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="mb-4">
  <h1 class="lt-title">Loan timeline</h1>
  <p class="lt-sub">Every loan of one person or company, step by step.</p>
</div>

<form method="get" class="card card-round mb-4" id="ltTlForm">
  <div class="card-body p-4">
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label fw-semibold" for="ltTo">Loan to</label>
        <select name="to" id="ltTo" class="form-select">
          <?php foreach ($types as $k => $t): ?>
            <option value="<?= h($k) ?>" <?= $to === $k ? 'selected' : '' ?>><?= h($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold" for="ltMonthSel">Month</label>
        <select name="month" id="ltMonthSel" class="form-select" <?= $months ? '' : 'disabled' ?>>
          <option value="">All months</option>
          <?php foreach ($months as $m): ?>
            <option value="<?= h($m) ?>" <?= $month === $m ? 'selected' : '' ?>><?= h(date('F Y', strtotime($m . '-01'))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold" for="ltWho"><?= $to === 'employee' ? 'Employee' : ($to === 'company' ? 'Company' : 'Person') ?></label>
        <select name="who" id="ltWho" class="form-select lt-search" data-placeholder="<?= $people ? 'Everyone — or search by name' : 'No loans yet' ?>" <?= $people ? '' : 'disabled' ?>>
          <option value=""></option>
          <?php foreach ($people as $p): ?>
            <option value="<?= h($p['v']) ?>" <?= $picked && (string)$p['v'] === $who ? 'selected' : '' ?>>
              <?= h($p['full_name']) ?><?= !empty($p['employee_code']) ? ' (' . h($p['employee_code']) . ')' : '' ?><?= !empty($p['company_name']) ? ' · ' . h($p['company_name']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php if ($picked || $month !== ''): ?>
      <div class="mt-3"><a class="small" href="?to=<?= h(urlencode($to)) ?>"><i class="bi bi-x-circle me-1"></i>Clear month and name</a></div>
    <?php endif; ?>
  </div>
</form>

<?php if (!$people): ?>
  <div class="card card-round"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-clock-history fs-2 d-block mb-2" style="color:var(--lt-peach)"></i>
    No loans to <?= $to === 'employee' ? 'employees' : ($to === 'company' ? 'companies' : 'outside people') ?> yet.
  </div></div>
<?php elseif (!$picked && $month === ''): ?>
  <div class="card card-round"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-search fs-2 d-block mb-2" style="color:var(--lt-peach)"></i>
    Choose a month, <?= $to === 'employee' ? 'an employee' : ($to === 'company' ? 'a company' : 'a person') ?>, or both.
  </div></div>
<?php else: ?>
  <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
    <?php if ($picked): ?>
      <div class="avatar" style="width:46px;height:46px;background:var(--lt-tint-2);color:var(--primary);font-size:1.1rem"><?= h(strtoupper(substr($picked['full_name'], 0, 1))) ?></div>
    <?php else: ?>
      <div class="avatar" style="width:46px;height:46px;background:var(--lt-tint-2);color:var(--primary);font-size:1.1rem"><i class="bi bi-calendar3"></i></div>
    <?php endif; ?>
    <div>
      <div class="fw-bold fs-5" style="color:var(--lt-ink)">
        <?= $picked ? h($picked['full_name']) : 'Loans given in ' . h(date('F Y', strtotime($month . '-01'))) ?>
        <?php if ($picked && $month !== ''): ?><span class="lt-badge lt-badge-active ms-1 align-middle"><?= h(date('F Y', strtotime($month . '-01'))) ?></span><?php endif; ?>
      </div>
      <div class="small text-muted"><?= h(implode(' · ', array_filter([$picked['employee_code'] ?? '', $picked['company_name'] ?? '', count($cards) . ' loan' . (count($cards) === 1 ? '' : 's')]))) ?></div>
    </div>
  </div>

  <?php if ($sum['hr'] > 0): ?>
    <div class="row g-3 mb-4">
      <div class="col-md-4"><div class="lt-sum" style="background:var(--lt-tint-2);color:var(--primary-dark)">
        <div class="lt-sum-label">Given</div><div class="lt-sum-value">AED <?= lt_money($sum['amount']) ?></div></div></div>
      <div class="col-md-4"><div class="lt-sum" style="background:#dcfce7;color:#166534">
        <div class="lt-sum-label">Paid back</div><div class="lt-sum-value">AED <?= lt_money($sum['recovered']) ?></div></div></div>
      <div class="col-md-4"><div class="lt-sum" style="background:#fef3c7;color:#92400e">
        <div class="lt-sum-label">Still to pay</div><div class="lt-sum-value">AED <?= lt_money($sum['outstanding']) ?></div></div></div>
    </div>
  <?php endif; ?>

  <?php if (!$cards): ?>
    <div class="card card-round"><div class="card-body text-center text-muted py-5">No loans in this month.</div></div>
  <?php endif; ?>

  <div class="row g-3">
    <?php foreach ($cards as $c): ?>
      <div class="col-lg-6" id="<?= h($c['key']) ?>">
        <div class="lt-tl-card h-100<?= $focus === $c['key'] ? ' focus' : '' ?>">
          <div class="lt-tl-card-head">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <div class="fw-bold" style="color:var(--lt-ink)"><?= h($c['title']) ?></div>
                <div class="small text-muted"><?= h($c['sub']) ?></div>
              </div>
              <span class="lt-badge <?= $c['open'] ? 'lt-badge-active' : 'lt-badge-closed' ?>"><?= h($c['badge']) ?></span>
            </div>
            <?php if ($c['amount'] !== null): ?>
              <div class="d-flex flex-wrap gap-2 mt-3">
                <span class="lt-pill lt-pill-amt"><small>Loan</small>AED <?= h($c['amount']) ?></span>
                <span class="lt-pill <?= $c['open'] ? 'lt-pill-bal' : 'lt-pill-paid' ?>"><small><?= $c['open'] ? 'Still to pay' : 'Paid back' ?></small>AED <?= h($c['open'] ? $c['balance'] : $c['amount']) ?></span>
              </div>
              <div class="lt-bar mt-3"><span style="width:<?= max(0, min(100, (int)$c['pct'])) ?>%"></span></div>
              <div class="small text-muted mt-1"><?= (int)$c['pct'] ?>% paid back</div>
            <?php endif; ?>
          </div>
          <div class="lt-tl-card-body">
            <?php lt_render_timeline($c['events']); ?>
            <?php if ($c['link']): ?>
              <div class="text-end mt-3"><a class="btn btn-lt-ghost btn-sm" href="<?= h($c['link']) ?>">Open loan <i class="bi bi-arrow-right ms-1"></i></a></div>
            <?php endif; ?>
            <?php if (!empty($c['repay'])): ?>
              <div class="text-end mt-3"><a class="btn btn-lt btn-sm" href="<?= h($c['repay']) ?>"><i class="bi bi-cash-stack me-1"></i>Record repayment</a></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  var f = document.getElementById('ltTlForm');
  document.getElementById('ltTo').addEventListener('change', function () {
    var w = document.getElementById('ltWho'); w.value = ''; w.disabled = true;
    var m = document.getElementById('ltMonthSel'); m.value = ''; m.disabled = true;
    f.submit();
  });
  document.getElementById('ltMonthSel').addEventListener('change', function () { f.submit(); });
  document.getElementById('ltWho').addEventListener('change', function () { f.submit(); });
  var hash = location.hash ? document.getElementById(location.hash.slice(1)) : null;
  if (hash) { hash.scrollIntoView({ block: 'center' }); }
})();
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
