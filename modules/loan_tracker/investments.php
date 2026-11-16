<?php
/**
 * Loan Tracker — Investments: money invested to persons and companies. Owner only.
 * Three numbers (invested, returns received, returns still to come), Persons / Companies
 * buttons, one card per investment (ending or ended ones first). ?closed=1 shows closed ones.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_invest.php';

$companyId = lt_inv_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';
$to = lt_inv_to();
$showClosed = ($_GET['closed'] ?? '') === '1';
lt_inv_handle_share($conn, $ltBase . '/investments.php');
$canEdit = lt_inv_can_edit($conn);

$q = $conn->prepare("SELECT * FROM lt_investments WHERE company_id = ? AND status = ? ORDER BY end_date, id");
$q->execute([$companyId, $showClosed ? 'closed' : 'active']);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
$q = $conn->prepare("SELECT COUNT(*) FROM lt_investments WHERE company_id = ? AND status = ?");
$q->execute([$companyId, $showClosed ? 'active' : 'closed']);
$otherCount = (int)$q->fetchColumn();

$ids = array_map(fn($r) => (int)$r['id'], $rows);
$received = lt_inv_received($conn, $ids);
$secBy = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $conn->prepare("SELECT investment_id, kind FROM lt_investment_security WHERE investment_id IN ($in)");
    $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $secBy[(int)$s['investment_id']][] = $s;
    }
}

$planBy = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $conn->prepare("SELECT * FROM lt_investment_plan WHERE investment_id IN ($in) ORDER BY investment_id, due_date, seq");
    $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) { $planBy[(int)$p['investment_id']][] = $p; }
}

$tot = ['amount' => 0.0, 'received' => 0.0, 'to_come' => 0.0];
$byKind = ['person' => ['n' => 0, 'amount' => 0.0], 'company' => ['n' => 0, 'amount' => 0.0]];
$cards = [];
foreach ($rows as $r) {
    $rid = (int)$r['id'];
    $got = $received[$rid] ?? 0.0;
    $exp = lt_inv_expected($r);
    $tot['amount'] += (float)$r['amount'];
    $tot['received'] += $got;
    $tot['to_come'] += max(0.0, $exp - $got);
    $byKind[$r['invest_to']]['n']++;
    $byKind[$r['invest_to']]['amount'] += (float)$r['amount'];
    $end = lt_inv_end_state($r);
    $next = null;
    foreach (lt_inv_plan_states($planBy[$rid] ?? [], $got, $r['status'] === 'active') as $p) { if ($p['state'] !== 'received') { $next = $p; break; } }
    $cards[] = $r + ['got' => $got, 'exp' => $exp, 'end' => $end, 'next' => $next, 'sec' => lt_inv_security_text($secBy[$rid] ?? []),
        'sort' => [$end['class'] === 'late' ? 0 : ($end['class'] === 'soon' ? 1 : 2), $r['end_date']]];
}
usort($cards, static fn($a, $b) => $a['sort'] <=> $b['sort']);

$pageTitle = 'Investments';
$pageStyles = '
  .lt-big{ border-radius:14px; padding:1rem 1.2rem; height:100%; }
  .lt-big-label{ font-size:.9rem; font-weight:600; opacity:.85; }
  .lt-big-value{ font-size:1.7rem; font-weight:800; font-variant-numeric:tabular-nums; }
  .lt-kinds{ display:flex; flex-wrap:wrap; gap:.6rem; margin-bottom:1rem; }
  .lt-kind{ display:flex; align-items:center; gap:.6rem; border:2px solid var(--lt-line); background:#fff; border-radius:14px; padding:.55rem .9rem; text-align:left; color:var(--lt-ink); }
  .lt-kind .lt-kind-ico{ width:34px; height:34px; border-radius:10px; display:grid; place-items:center; color:#fff; background:var(--c); font-size:1.05rem; }
  .lt-kind .lt-kind-name{ font-weight:700; line-height:1.1; }
  .lt-kind .lt-kind-sub{ font-size:.8rem; color:var(--lt-muted); }
  .lt-kind.on{ border-color:var(--c); box-shadow:0 0 0 3px color-mix(in srgb, var(--c) 18%, transparent); }
  .lt-off{ display:none !important; }
  .lt-inv-card{ display:block; border:1px solid var(--lt-line); border-left:6px solid var(--c) !important; border-radius:14px; padding:1rem 1.1rem;
                background:#fff; color:var(--lt-ink); text-decoration:none; height:100%; transition:box-shadow .12s, transform .12s; }
  .lt-inv-card:hover{ box-shadow:0 8px 20px rgba(0,0,0,.08); transform:translateY(-1px); color:var(--lt-ink); }
  .lt-inv-card.late{ border:2px solid #dc2626; border-left:6px solid var(--c) !important; }
  .lt-inv-name{ font-weight:700; font-size:1.05rem; }
  .lt-kind-tag{ display:inline-flex; align-items:center; gap:.3rem; font-size:.78rem; font-weight:700; color:var(--c); background:color-mix(in srgb, var(--c) 12%, #fff); border-radius:99px; padding:.1rem .55rem; }
  .lt-end{ border-radius:99px; padding:.15rem .6rem; font-size:.72rem; font-weight:700; white-space:nowrap; }
  .lt-end.late{ background:#dc2626; color:#fff; } .lt-end.soon{ background:#fef3c7; color:#92400e; } .lt-end.done{ background:#dcfce7; color:#166534; }
  .lt-line{ margin-top:.3rem; }
  .lt-inv-table tbody tr.lt-card-col{ cursor:pointer; }
  .lt-inv-table tbody tr.lt-card-col.open td{ background:var(--lt-tint); }
  .lt-more-box{ background:#fafbff; border-top:1px dashed var(--lt-tint-3); padding:1.1rem 1.4rem; }
  .lt-more-box .lt-kv{ display:flex; justify-content:space-between; gap:1rem; padding:.35rem 0; border-top:1px solid var(--lt-tint); font-size:.9rem; }
  .lt-more-box .lt-kv span:first-child{ color:var(--lt-muted); }
  .lt-more-box .lt-tl-dot{ box-shadow:0 0 0 4px #fafbff; }
  .lt-inv-table tbody tr.late td{ background:#fff5f5; }
  .lt-inv-table .lt-bar{ margin:.35rem 0 0 auto; max-width:160px; }
  .lt-bar{ height:8px; border-radius:99px; background:#e5e7eb; overflow:hidden; margin:.45rem 0 .2rem; }
  .lt-bar > span{ display:block; height:100%; border-radius:99px; background:#22c55e; }
';
require __DIR__ . '/includes/lt_layout_header.php';
lt_inv_render_welcome($conn);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="lt-title"><?= $showClosed ? 'Closed investments' : 'Investments' ?></h1>
    <p class="lt-sub">Money invested to persons and companies.
      <a href="?<?= $showClosed ? '' : 'closed=1' ?>"><?= $showClosed ? 'Back to active' : 'Closed (' . $otherCount . ')' ?></a></p>
  </div>
  <?php if ($canEdit): ?><a href="<?= h($ltBase) ?>/investment_form.php" class="btn btn-lt"><i class="bi bi-plus-lg me-1"></i> New investment</a><?php endif; ?>
</div>

<?php if (!$showClosed): ?>
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="lt-big" style="background:var(--lt-tint-2);color:var(--primary-dark)"><div class="lt-big-label">Invested</div><div class="lt-big-value">AED <?= lt_money($tot['amount']) ?></div></div></div>
  <div class="col-md-4"><div class="lt-big" style="background:#dcfce7;color:#166534"><div class="lt-big-label">Payouts received</div><div class="lt-big-value">AED <?= lt_money($tot['received']) ?></div></div></div>
  <div class="col-md-4"><div class="lt-big" style="background:#fef3c7;color:#92400e"><div class="lt-big-label">Payouts still to come</div><div class="lt-big-value">AED <?= lt_money($tot['to_come']) ?></div></div></div>
</div>
<?php endif; ?>

<?php if (!$cards): ?>
  <div class="card card-round"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-graph-up-arrow fs-2 d-block mb-2 opacity-50"></i><?= $showClosed ? 'No closed investments.' : 'No investments yet.' ?>
    <?php if (!$showClosed && $canEdit): ?><div class="mt-3"><a href="<?= h($ltBase) ?>/investment_form.php" class="btn btn-lt btn-sm"><i class="bi bi-plus-lg me-1"></i> New investment</a></div><?php endif; ?>
  </div></div>
<?php else: ?>
  <div class="lt-kinds" role="group" aria-label="Show investments to">
    <button type="button" class="lt-kind on" data-kind="all" style="--c:var(--primary)">
      <span class="lt-kind-ico"><i class="bi bi-graph-up-arrow"></i></span>
      <span><span class="lt-kind-name d-block">All</span><span class="lt-kind-sub"><?= count($cards) ?> · AED <?= lt_money($tot['amount']) ?></span></span>
    </button>
    <?php foreach ($to as $k => $t): ?>
      <button type="button" class="lt-kind" data-kind="<?= h($k) ?>" style="--c:<?= h($t['color']) ?>"<?= $byKind[$k]['n'] === 0 ? ' disabled' : '' ?>>
        <span class="lt-kind-ico"><i class="bi <?= h($t['icon']) ?>"></i></span>
        <span><span class="lt-kind-name d-block"><?= h($t['plural']) ?></span><span class="lt-kind-sub"><?= $byKind[$k]['n'] ?> · AED <?= lt_money($byKind[$k]['amount']) ?></span></span>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="card card-round"><div class="table-responsive">
    <table class="table lt-table lt-inv-table mb-0">
      <thead><tr><th>Investment</th><th class="text-end">Invested (AED)</th><th>Ends</th><th>Next payout</th><th class="text-end">Received / expected (AED)</th><th>Security</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cards as $c): $t = $to[$c['invest_to']]; $pct = $c['exp'] > 0 ? (int)min(100, round($c['got'] / $c['exp'] * 100)) : 0;
            $nx = $c['next']; $url = $ltBase . '/investment_view.php?id=' . (int)$c['id']; ?>
        <tr class="lt-card-col<?= $c['end']['class'] === 'late' ? ' late' : '' ?>" data-kind="<?= h($c['invest_to']) ?>" data-more="more<?= (int)$c['id'] ?>">
          <td style="border-left:5px solid <?= h($t['color']) ?>">
            <a class="lt-inv-name text-decoration-none" href="<?= h($url) ?>"><?= h($c['name']) ?></a>
            <div class="small text-muted"><span class="lt-kind-tag" style="--c:<?= h($t['color']) ?>"><i class="bi <?= h($t['icon']) ?>"></i><?= h($t['label']) ?></span> <?= h(lt_inv_no((int)$c['id'])) ?></div>
          </td>
          <td class="text-end"><b style="font-size:1.05rem;font-variant-numeric:tabular-nums"><?= lt_money($c['amount']) ?></b><div class="small text-muted"><?= h(lt_date($c['invested_on'])) ?></div></td>
          <td><?= h(lt_date($c['end_date'])) ?><?php if ($c['end']['text'] !== ''): ?><div><span class="lt-end <?= h($c['end']['class']) ?>"><?= h($c['end']['text']) ?></span></div><?php endif; ?></td>
          <td>
            <?php if ($nx): ?>
              <b style="font-variant-numeric:tabular-nums">AED <?= lt_money((float)$nx['amount'] - (float)$nx['got']) ?></b>
              <div class="small <?= $nx['state'] === 'overdue' ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $nx['state'] === 'overdue' ? 'Overdue · ' : '' ?><?= h(lt_date($nx['due_date'])) ?></div>
            <?php else: ?><span class="text-muted small"><?= $c['exp'] > 0 ? 'All received' : 'No plan' ?></span><?php endif; ?>
          </td>
          <td class="text-end" style="min-width:170px">
            <span style="font-variant-numeric:tabular-nums"><?= lt_money($c['got']) ?> / <?= $c['exp'] > 0 ? lt_money($c['exp']) : '—' ?></span>
            <?php if ($c['exp'] > 0): ?><div class="lt-bar"><span style="width:<?= $pct ?>%"></span></div><?php endif; ?>
          </td>
          <td class="small"><?= h($c['sec']) ?></td>
          <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-lt-ghost lt-more-btn"><i class="bi bi-chevron-down me-1"></i>View details</button></td>
        </tr>
        <tr class="lt-more lt-off" id="more<?= (int)$c['id'] ?>" data-kind="<?= h($c['invest_to']) ?>">
          <td colspan="7" class="p-0"><div class="lt-more-box">
            <div class="row g-4">
              <div class="col-lg-5">
                <div class="lt-section mb-2">Details</div>
                <div class="lt-kv"><span>Pay back</span><span><?= h(lt_inv_return_text($c)) ?></span></div>
                <div class="lt-kv"><span>Paid by</span><span><?= h(lt_inv_paid_by()[$c['paid_by']] ?? '') ?><?= $c['reference'] ? ' · Ref ' . h($c['reference']) : '' ?></span></div>
                <?php if ($c['purpose']): ?><div class="lt-kv"><span>Purpose</span><span><?= h($c['purpose']) ?></span></div><?php endif; ?>
                <?php if ($c['contact_name']): ?><div class="lt-kv"><span>Contact person</span><span><?= h($c['contact_name']) ?></span></div><?php endif; ?>
                <?php if ($c['phone']): ?><div class="lt-kv"><span>Phone</span><span><?= h($c['phone']) ?></span></div><?php endif; ?>
                <?php if ($c['email']): ?><div class="lt-kv"><span>Email</span><span><?= h($c['email']) ?></span></div><?php endif; ?>
                <div class="lt-kv"><span>Security</span><span><?= h($c['sec']) ?></span></div>
                <?php if ($c['notes']): ?><div class="lt-kv"><span>Notes</span><span><?= nl2br(h($c['notes'])) ?></span></div><?php endif; ?>
                <a class="btn btn-lt btn-sm mt-3" href="<?= h($url) ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Open investment</a>
              </div>
              <div class="col-lg-7">
                <div class="lt-section mb-3">Timeline</div>
                <?php lt_render_timeline(lt_inv_timeline($conn, $c, $ltBase)); ?>
              </div>
            </div>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td class="fw-semibold"><?= count($cards) ?> investment<?= count($cards) === 1 ? '' : 's' ?></td><td class="text-end fw-bold" style="font-variant-numeric:tabular-nums"><?= lt_money($tot['amount']) ?></td><td colspan="2"></td><td class="text-end fw-bold" style="font-variant-numeric:tabular-nums"><?= lt_money($tot['received']) ?></td><td colspan="2"></td></tr></tfoot>
    </table>
  </div></div>
<?php endif; ?>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  document.querySelectorAll('tr[data-more]').forEach(function (tr) {
    var more = document.getElementById(tr.dataset.more), btn = tr.querySelector('.lt-more-btn');
    var toggle = function () {
      var open = more.classList.toggle('lt-off') === false;
      tr.classList.toggle('open', open);
      btn.innerHTML = open ? '<i class="bi bi-chevron-up me-1"></i>Hide details' : '<i class="bi bi-chevron-down me-1"></i>View details';
    };
    tr.addEventListener('click', function (e) { if (!e.target.closest('a')) { toggle(); } });
  });
  document.querySelectorAll('.lt-kind[data-kind]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var k = btn.dataset.kind;
      document.querySelectorAll('.lt-kind').forEach(function (x) { x.classList.toggle('on', x === btn); });
      document.querySelectorAll('.lt-card-col').forEach(function (col) {
        var hide = k !== 'all' && col.dataset.kind !== k;
        col.classList.toggle('lt-off', hide);
        if (hide) { var m = document.getElementById(col.dataset.more); if (m) { m.classList.add('lt-off'); } col.classList.remove('open'); }
      });
    });
  });
})();
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
