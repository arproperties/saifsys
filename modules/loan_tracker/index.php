<?php
/**
 * Loan Tracker — dashboard, kept simple:
 *   three numbers (lent, paid back, still to collect),
 *   two alert lines (late payments, payments due this month),
 *   one card per open loan: who, how much is left, progress, next payment. Late ones first.
 * Counts Loan Tracker loans (this company) and open staff loans from HR (all companies, not hidden).
 * Staff loans themselves are managed in HR; each HR card opens the loan timeline here.
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
$today = date('Y-m-d');
$monthEnd = date('Y-m-t');

$cards = [];
$tot = ['lent' => 0.0, 'paid' => 0.0, 'left' => 0.0];

// Loan Tracker loans that are running.
$q = $conn->prepare("
    SELECT l.id, l.loan_to, l.borrower_name, l.total_payable, l.installments,
           (SELECT COALESCE(SUM(r.amount), 0) FROM lt_loan_repayments r WHERE r.loan_id = l.id) AS paid,
           (SELECT COUNT(*) FROM lt_loan_installments i WHERE i.loan_id = l.id AND i.paid_amount >= i.amount) AS inst_paid,
           (SELECT i.due_date FROM lt_loan_installments i WHERE i.loan_id = l.id AND i.paid_amount < i.amount ORDER BY i.seq LIMIT 1) AS next_due,
           (SELECT i.amount - i.paid_amount FROM lt_loan_installments i WHERE i.loan_id = l.id AND i.paid_amount < i.amount ORDER BY i.seq LIMIT 1) AS next_amt
      FROM lt_loans l
     WHERE l.company_id = ? AND l.status = 'active'");
$q->execute([$companyId]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $total = (float)$r['total_payable'];
    $paid = min($total, (float)$r['paid']);
    $left = max(0.0, $total - $paid);
    $tot['lent'] += $total;
    $tot['paid'] += $paid;
    $tot['left'] += $left;
    $late = $r['next_due'] !== null && $r['next_due'] < $today;
    $cards[] = [
        'name' => $r['borrower_name'],
        'kind' => (string)$r['loan_to'],
        'type' => '',
        'no' => lt_loan_no((int)$r['id']),
        'total' => $total, 'left' => $left,
        'done' => (int)$r['inst_paid'], 'count' => (int)$r['installments'],
        'pct' => $total > 0 ? (int)round($paid / $total * 100) : 0,
        'late' => $late,
        'next' => $r['next_due'] === null ? '' : ($late
            ? 'AED ' . lt_money($r['next_amt']) . ' was due on ' . lt_date($r['next_due'])
            : 'AED ' . lt_money($r['next_amt']) . ' on ' . lt_date($r['next_due'])),
        'sort' => [$late ? 0 : 1, $r['next_due'] ?? '9999-12-31'],
        'url' => $ltBase . '/loan_view.php?id=' . (int)$r['id'],
    ];
}

// Open staff loans from HR (not hidden). HR keeps no due dates, and a salary advance's plan
// (1 instalment of the full amount) does not match how it is paid back (several salary
// deductions), so the card shows what is certain: % paid back and the last payment.
foreach (lt_hr_loans($conn) as $r) {
    if ($r['status'] !== 'open' || $r['hidden']) {
        continue;
    }
    $total = (float)$r['amount'];
    $tot['lent'] += $total;
    $tot['paid'] += (float)$r['recovered'];
    $tot['left'] += (float)$r['outstanding'];
    $cards[] = [
        'name' => $r['full_name'],
        'kind' => 'employee',
        'type' => 'from HR',
        'no' => $r['product_type'] === 'loan' ? 'Staff loan' : 'Salary advance',
        'total' => $total, 'left' => (float)$r['outstanding'],
        'done' => 0, 'count' => 0,
        'pct' => $total > 0 ? (int)round((float)$r['recovered'] / $total * 100) : 0,
        'late' => false,
        'next' => '',
        'last' => $r['last_paid'] ? 'Last payment: ' . lt_date($r['last_paid']) : 'Nothing paid back yet',
        'sort' => [2, (string)$r['tx_date']],
        'url' => $ltBase . '/timeline.php?to=employee&who=' . (int)$r['employee_id'] . '&loan=hr' . (int)$r['id'] . '#hr' . (int)$r['id'],
    ];
}
$byKind = [];
foreach (array_keys($types) as $k) {
    $byKind[$k] = ['n' => 0, 'left' => 0.0];
}
foreach ($cards as $c) {
    $byKind[$c['kind']]['n']++;
    $byKind[$c['kind']]['left'] += $c['left'];
}
usort($cards, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort'] ?: strcmp($a['name'], $b['name']));

// The two alert lines (Loan Tracker instalments; HR loans have no due dates).
$q = $conn->prepare("
    SELECT SUM(i.due_date < ?) AS late_n,
           COALESCE(SUM(CASE WHEN i.due_date < ? THEN i.amount - i.paid_amount END), 0) AS late_amt,
           SUM(i.due_date BETWEEN ? AND ?) AS month_n,
           COALESCE(SUM(CASE WHEN i.due_date BETWEEN ? AND ? THEN i.amount - i.paid_amount END), 0) AS month_amt
      FROM lt_loan_installments i JOIN lt_loans l ON l.id = i.loan_id
     WHERE l.company_id = ? AND l.status = 'active' AND i.paid_amount < i.amount");
$q->execute([$today, $today, $today, $monthEnd, $today, $monthEnd, $companyId]);
$due = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$lateN = (int)($due['late_n'] ?? 0);
$monthN = (int)($due['month_n'] ?? 0);

// Loans started but not finished.
$q = $conn->prepare("SELECT id, borrower_name FROM lt_loans WHERE company_id = ? AND status = 'draft' ORDER BY id DESC");
$q->execute([$companyId]);
$drafts = $q->fetchAll(PDO::FETCH_ASSOC);

$shown = 12;
$pageStyles = '
  .lt-big{ border-radius:14px; padding:1rem 1.2rem; height:100%; }
  .lt-big-label{ font-size:.9rem; font-weight:600; opacity:.85; }
  .lt-big-value{ font-size:1.7rem; font-weight:800; font-variant-numeric:tabular-nums; }
  .lt-big-lent{ background:var(--lt-tint-2); color:var(--primary-dark); }
  .lt-big-paid{ background:#dcfce7; color:#166534; }
  .lt-big-left{ background:#fef3c7; color:#92400e; }
  .lt-alert-line{ display:flex; align-items:center; gap:.6rem; padding:.7rem 1rem; border-radius:12px; font-weight:600; margin-bottom:.5rem; }
  .lt-alert-late{ background:#fee2e2; color:#991b1b; }
  .lt-alert-month{ background:#fff7ed; color:#9a3412; }
  .lt-alert-ok{ background:#f0fdf4; color:#166534; }
  .lt-alert-draft{ background:#f3f4f6; color:#374151; }
  .lt-loan-card{ display:block; border:1px solid var(--lt-line); border-radius:14px; padding:1rem 1.1rem; background:#fff;
                 color:var(--lt-ink); text-decoration:none; height:100%; transition:box-shadow .12s, transform .12s; }
  .lt-loan-card:hover{ box-shadow:0 8px 20px rgba(0,0,0,.08); transform:translateY(-1px); color:var(--lt-ink); }
  .lt-loan-card.late{ border:2px solid #dc2626; }
  .lt-loan-name{ font-weight:700; font-size:1.05rem; }
  .lt-loan-type{ color:var(--lt-muted); font-size:.85rem; }
  .lt-late-tag{ background:#dc2626; color:#fff; border-radius:99px; padding:.15rem .6rem; font-size:.75rem; font-weight:700; white-space:nowrap; }
  .lt-owes{ font-size:1.05rem; margin-top:.4rem; }
  .lt-owes b{ font-size:1.25rem; }
  .lt-bar{ height:10px; border-radius:99px; background:#e5e7eb; overflow:hidden; margin:.5rem 0 .25rem; }
  .lt-bar > span{ display:block; height:100%; border-radius:99px; background:#22c55e; }
  .lt-next{ margin-top:.35rem; }
  .lt-next.late{ color:#b91c1c; font-weight:700; }
  .lt-more[hidden], .lt-off{ display:none !important; }
  .lt-kinds{ display:flex; flex-wrap:wrap; gap:.6rem; margin-bottom:1rem; }
  .lt-kind{ display:flex; align-items:center; gap:.6rem; border:2px solid var(--lt-line); background:#fff; border-radius:14px;
            padding:.55rem .9rem; text-align:left; color:var(--lt-ink); }
  .lt-kind .lt-kind-ico{ width:34px; height:34px; border-radius:10px; display:grid; place-items:center; color:#fff; background:var(--c, #6b7280); font-size:1.05rem; }
  .lt-kind .lt-kind-name{ font-weight:700; line-height:1.1; }
  .lt-kind .lt-kind-sub{ font-size:.8rem; color:var(--lt-muted); }
  .lt-kind.on{ border-color:var(--c, var(--primary)); box-shadow:0 0 0 3px color-mix(in srgb, var(--c, var(--primary)) 18%, transparent); }
  .lt-loan-card{ border-left:6px solid var(--c, var(--lt-line)) !important; }
  .lt-kind-tag{ display:inline-flex; align-items:center; gap:.3rem; font-size:.78rem; font-weight:700; color:var(--c);
                background:color-mix(in srgb, var(--c) 12%, #fff); border-radius:99px; padding:.1rem .55rem; }
';
$pageTitle = 'Dashboard';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <h1 class="lt-title">Loan Tracker</h1>
  <a href="<?= h($ltBase) ?>/new_loan.php" class="btn btn-lt"><i class="bi bi-plus-lg me-1"></i> New loan</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="lt-big lt-big-lent"><div class="lt-big-label">Lent</div><div class="lt-big-value">AED <?= lt_money($tot['lent']) ?></div></div></div>
  <div class="col-md-4"><div class="lt-big lt-big-paid"><div class="lt-big-label">Paid back</div><div class="lt-big-value">AED <?= lt_money($tot['paid']) ?></div></div></div>
  <div class="col-md-4"><div class="lt-big lt-big-left"><div class="lt-big-label">Still to collect</div><div class="lt-big-value">AED <?= lt_money($tot['left']) ?></div></div></div>
</div>

<div class="mb-4">
  <?php if ($lateN > 0): ?>
    <div class="lt-alert-line lt-alert-late"><i class="bi bi-exclamation-triangle-fill"></i>
      <?= $lateN ?> payment<?= $lateN === 1 ? ' is' : 's are' ?> late · AED <?= lt_money($due['late_amt']) ?></div>
  <?php endif; ?>
  <?php if ($monthN > 0): ?>
    <div class="lt-alert-line lt-alert-month"><i class="bi bi-calendar-event"></i>
      <?= $monthN ?> payment<?= $monthN === 1 ? '' : 's' ?> due this month · AED <?= lt_money($due['month_amt']) ?></div>
  <?php endif; ?>
  <?php if ($lateN === 0 && $monthN === 0): ?>
    <div class="lt-alert-line lt-alert-ok"><i class="bi bi-check-circle-fill"></i> No late payments, and nothing more due this month.</div>
  <?php endif; ?>
  <?php if ($drafts): ?>
    <a class="lt-alert-line lt-alert-draft text-decoration-none" href="<?= h($ltBase) ?>/loan_view.php?id=<?= (int)$drafts[0]['id'] ?>">
      <i class="bi bi-pencil-square"></i> <?= count($drafts) ?> loan<?= count($drafts) === 1 ? ' is' : 's are' ?> not finished yet
      (<?= h(implode(', ', array_slice(array_column($drafts, 'borrower_name'), 0, 3))) ?><?= count($drafts) > 3 ? ', …' : '' ?>) — continue
    </a>
  <?php endif; ?>
</div>

<?php if (!$cards): ?>
  <div class="card card-round"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-wallet2 fs-2 d-block mb-2 opacity-50"></i>No open loans.
    <div class="mt-3"><a href="<?= h($ltBase) ?>/new_loan.php" class="btn btn-lt btn-sm"><i class="bi bi-plus-lg me-1"></i> New loan</a></div>
  </div></div>
<?php else: ?>
  <div class="lt-kinds" role="group" aria-label="Show loans to">
    <button type="button" class="lt-kind on" data-kind="all" style="--c:var(--primary)">
      <span class="lt-kind-ico"><i class="bi bi-wallet2"></i></span>
      <span><span class="lt-kind-name d-block">All</span><span class="lt-kind-sub"><?= count($cards) ?> loan<?= count($cards) === 1 ? '' : 's' ?> · AED <?= lt_money($tot['left']) ?></span></span>
    </button>
    <?php foreach ($types as $k => $t): ?>
      <button type="button" class="lt-kind" data-kind="<?= h($k) ?>" style="--c:<?= h($t['color']) ?>"<?= $byKind[$k]['n'] === 0 ? ' disabled' : '' ?>>
        <span class="lt-kind-ico"><i class="bi <?= h($t['icon']) ?>"></i></span>
        <span><span class="lt-kind-name d-block"><?= h(['employee' => 'Employees', 'person' => 'Outside people', 'company' => 'Companies'][$k] ?? $t['label']) ?></span>
          <span class="lt-kind-sub"><?= $byKind[$k]['n'] ?> loan<?= $byKind[$k]['n'] === 1 ? '' : 's' ?> · AED <?= lt_money($byKind[$k]['left']) ?></span></span>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="row g-3">
    <?php foreach ($cards as $i => $c): $kt = $types[$c['kind']] ?? ['color' => '#6b7280', 'icon' => 'bi-person', 'label' => '']; ?>
      <div class="col-lg-6 lt-card-col<?= $i >= $shown ? ' lt-more' : '' ?>" data-kind="<?= h($c['kind']) ?>"<?= $i >= $shown ? ' hidden' : '' ?>>
        <a class="lt-loan-card<?= $c['late'] ? ' late' : '' ?>" href="<?= h($c['url']) ?>" style="--c:<?= h($kt['color']) ?>">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <div class="lt-loan-name"><?= h($c['name']) ?></div>
              <div class="lt-loan-type mt-1"><span class="lt-kind-tag"><i class="bi <?= h($kt['icon']) ?>"></i><?= h($kt['label']) ?></span>
                <?= h(trim($c['type'] . ' · ' . $c['no'], ' ·')) ?></div>
            </div>
            <?php if ($c['late']): ?><span class="lt-late-tag"><i class="bi bi-exclamation-triangle-fill me-1"></i>LATE</span><?php endif; ?>
          </div>
          <div class="lt-owes">Owes <b>AED <?= lt_money($c['left']) ?></b> of AED <?= lt_money($c['total']) ?></div>
          <div class="lt-bar"><span style="width:<?= max(0, min(100, $c['pct'])) ?>%"></span></div>
          <div class="small text-muted"><?= $c['count'] > 0 ? $c['done'] . ' of ' . $c['count'] . ' payments made' : $c['pct'] . '% paid back' ?></div>
          <?php if ($c['next'] !== ''): ?>
            <div class="lt-next<?= $c['late'] ? ' late' : '' ?>">Next: <?= h($c['next']) ?></div>
          <?php elseif (!empty($c['last'])): ?>
            <div class="lt-next text-muted"><?= h($c['last']) ?></div>
          <?php endif; ?>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($cards) > $shown): ?>
    <div class="text-center mt-3">
      <button type="button" class="btn btn-lt-ghost" id="ltShowAll">Show all <?= count($cards) ?> loans</button>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  var b = document.getElementById('ltShowAll');
  if (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.lt-more').forEach(function (el) { el.hidden = false; });
      b.remove();
    });
  }
  // Employees / Outside people / Companies: show only that kind (all of its cards).
  document.querySelectorAll('.lt-kind[data-kind]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var k = btn.dataset.kind;
      document.querySelectorAll('.lt-kind').forEach(function (x) { x.classList.toggle('on', x === btn); });
      document.querySelectorAll('.lt-card-col').forEach(function (col) {
        col.classList.toggle('lt-off', k !== 'all' && col.dataset.kind !== k);
        if (k !== 'all') { col.hidden = false; }
      });
      var more = document.getElementById('ltShowAll');
      if (more) { more.parentNode.hidden = k !== 'all'; }
    });
  });
})();
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
