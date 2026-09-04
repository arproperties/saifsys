<?php
// hr/performance_detail.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_login();
require_role(['Owner','Admin','HR'], $conn);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$wid  = (int)($_GET['worker_id'] ?? 0);
$from = $_GET['from'] ?? null;
$to   = $_GET['to']   ?? null;

try {
  $fromDt = new DateTimeImmutable($from ?: 'first day of this month');
  $toDt   = new DateTimeImmutable($to   ?: 'last day of this month');
} catch(Throwable $e) {
  $fromDt = new DateTimeImmutable('first day of this month');
  $toDt   = new DateTimeImmutable('last day of this month');
}

if ($wid <= 0) {
  http_response_code(400);
  echo '<div class="text-danger small">Invalid worker.</div>';
  exit;
}

/* ---------- snapshot (capacity, hours, revenue, salary) ---------- */
$sqlSnap = "
SELECT
  w.id AS worker_id,
  COALESCE(NULLIF(w.nickname,''), CONCAT('Worker #', w.id)) AS worker_name,
  COALESCE(w.weekly_cap_hours,0) AS weekly_cap,
  COALESCE(w.daily_cap_hours,0)  AS daily_cap,
  COALESCE(w.total_salary, COALESCE(w.basic_salary,0)+COALESCE(w.allowance,0)+COALESCE(w.bonus,0)) AS monthly_salary,
  ROUND(SUM(COALESCE(mo.net_hours,0)),2) AS hours_worked,
  ROUND(SUM(CASE WHEN owc.cnt>0 THEN mo.grand_total/owc.cnt ELSE 0 END),2) AS revenue_incl_vat,
  COUNT(DISTINCT mo.id) AS jobs
FROM order_workers ow
JOIN workers w     ON w.id=ow.worker_id
JOIN make_order mo ON mo.id=ow.order_id
JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id=mo.id
WHERE mo.status IN('confirmed','completed')
  AND mo.date BETWEEN :from AND :to
  AND ow.worker_id = :wid
";
$st = $conn->prepare($sqlSnap);
$st->execute([':from'=>$fromDt->format('Y-m-d'), ':to'=>$toDt->format('Y-m-d'), ':wid'=>$wid]);
$snap = $st->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$snap) {
  echo '<div class="text-muted">No data for this worker in the selected period.</div>';
  exit;
}

$workerName = $snap['worker_name'];
$weekly  = (float)$snap['weekly_cap'];
$daily   = (float)$snap['daily_cap'];
$cap     = $weekly>0 ? $weekly*4.33 : ($daily>0 ? $daily*26 : 0.0);
$hours   = (float)$snap['hours_worked'];
$revenue = (float)$snap['revenue_incl_vat'];
$util    = $cap>0 ? (100.0*$hours/$cap) : null;

$days = max(1, (int)$fromDt->diff($toDt)->format('%a') + 1);
$monthsFactor = $days / 30.4375;
$salary = max(0.0,(float)$snap['monthly_salary']);
$baseCost = round($salary*$monthsFactor,2);

/* ---------- worker -> employee map ---------- */
$eid = null;
$m = $conn->prepare("
  SELECT e.id
  FROM workers w
  LEFT JOIN employees e
    ON (
         (e.employee_code IS NOT NULL AND e.employee_code<>'' AND e.employee_code=w.emp_num)
      OR (e.nickname     IS NOT NULL AND e.nickname    <>'' AND e.nickname    =w.nickname)
      OR (e.full_name    IS NOT NULL AND e.full_name   <>'' AND e.full_name   =w.worker_name)
    )
  WHERE w.id=?
");
$m->execute([$wid]);
$eid = (int)$m->fetchColumn() ?: null;

/* ---------- attendance + OT pay ---------- */
$present=0; $absent=0; $otH=0.0; $otPay=0.0;
if ($eid) {
  $a = $conn->prepare("
    SELECT
      SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS p,
      SUM(CASE WHEN status IN('absent','on_leave','half') THEN 1 ELSE 0 END) AS a,
      ROUND(SUM(CASE WHEN status='approved' AND hours>8 THEN (hours-8) ELSE 0 END),2) AS ot_h
    FROM attendance
    WHERE employee_id=? AND work_date BETWEEN ? AND ?
  ");
  $a->execute([$eid, $fromDt->format('Y-m-d'), $toDt->format('Y-m-d')]);
  $aa = $a->fetch(PDO::FETCH_ASSOC) ?: [];
  $present = (int)($aa['p'] ?? 0);
  $absent  = (int)($aa['a'] ?? 0);
  $otH     = (float)($aa['ot_h'] ?? 0);

  $o = $conn->prepare("
    SELECT ROUND(SUM(pay_amount),2) FROM overtime_entries
    WHERE status='approved' AND employee_id=? AND ot_date BETWEEN ? AND ?
  ");
  $o->execute([$eid, $fromDt->format('Y-m-d'), $toDt->format('Y-m-d')]);
  $otPay = (float)$o->fetchColumn();
}

$totalCost = $baseCost + $otPay;
$net       = $revenue - $totalCost;
$revHr     = $hours>0 ? $revenue/$hours : null;
$netHr     = $hours>0 ? $net/$hours     : null;
$margin    = $revenue>0 ? (100.0*$net/$revenue) : null;

/* ---------- latest jobs (limit 12) ---------- */
$jobs = [];
$j = $conn->prepare("
  SELECT mo.id, mo.date, mo.client_name,
         ROUND(COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE 0 END),2) AS hours,
         ROUND(CASE WHEN owc.cnt>0 THEN mo.grand_total/owc.cnt ELSE 0 END,2) AS revenue
  FROM order_workers ow
  JOIN make_order mo ON mo.id=ow.order_id
  JOIN (SELECT order_id, COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id=mo.id
  WHERE mo.status IN('confirmed','completed')
    AND mo.date BETWEEN :from AND :to
    AND ow.worker_id=:wid
  ORDER BY mo.date DESC, mo.id DESC
  LIMIT 12
");
$j->execute([':from'=>$fromDt->format('Y-m-d'), ':to'=>$toDt->format('Y-m-d'), ':wid'=>$wid]);
$jobs = $j->fetchAll(PDO::FETCH_ASSOC);

/* ---------- feedback summary chip (optional) ---------- */
$avg = ['avg_rating'=>null,'cnt'=>0];
try {
  $avgRow = $conn->prepare("
    SELECT ROUND(AVG(rating),1) avg_rating, COUNT(*) cnt
    FROM perf_feedback
    WHERE worker_id = ?
      AND period_from <= ? AND period_to >= ?
      AND rating IS NOT NULL
  ");
  $avgRow->execute([$wid, $toDt->format('Y-m-d'), $fromDt->format('Y-m-d')]);
  $avg = $avgRow->fetch(PDO::FETCH_ASSOC) ?: $avg;
} catch (Throwable $e) {
  // table might not exist yet; ignore
}
?>
<!-- title hint for offcanvas -->
<h6 class="mb-3 d-flex align-items-center gap-2" data-worker-name="<?= h($workerName) ?>">
  <?= h($workerName) ?>
  <?php if (!empty($avg['cnt'])): ?>
    <span class="badge bg-warning-subtle text-warning-emphasis">
      ★ <?= h($avg['avg_rating']) ?> (<?= (int)$avg['cnt'] ?>)
    </span>
  <?php endif; ?>
</h6>

<div class="row g-2 mb-3">
  <div class="col-6">
    <div class="border rounded p-2 small">
      <div class="text-muted">Hours / Utilization</div>
      <div><strong><?= number_format($hours,2) ?> h</strong>
        <?php if ($util!==null): ?> · <span><?= number_format($util,1) ?>%</span><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-6">
    <div class="border rounded p-2 small">
      <div class="text-muted">Revenue / Net</div>
      <div><strong>AED <?= number_format($revenue,2) ?></strong> ·
        Net <strong class="<?= $net<0?'text-danger':'text-success' ?>">AED <?= number_format($net,2) ?></strong>
      </div>
    </div>
  </div>
  <div class="col-6">
    <div class="border rounded p-2 small">
      <div class="text-muted">Rev/hr · Net/hr · Margin</div>
      <div>
        <?= $revHr!==null ? 'AED '.number_format($revHr,2).'/h' : '—' ?> ·
        <?= $netHr!==null ? 'AED '.number_format($netHr,2).'/h' : '—' ?> ·
        <?= $margin!==null ? number_format($margin,1).'%' : '—' ?>
      </div>
    </div>
  </div>
  <div class="col-6">
    <div class="border rounded p-2 small">
      <div class="text-muted">Cost (base + OT pay)</div>
      <div>AED <?= number_format($baseCost,2) ?> + <?= number_format($otPay,2) ?>
        = <strong>AED <?= number_format($totalCost,2) ?></strong></div>
    </div>
  </div>
  <div class="col-12">
    <div class="border rounded p-2 small">
      <div class="text-muted">Attendance</div>
      <div>Present <strong><?= (int)$present ?></strong> · Absent <strong><?= (int)$absent ?></strong>
          · OT <strong><?= number_format($otH,2) ?> h</strong></div>
    </div>
  </div>
</div>

<h6 class="mb-2">Recent jobs</h6>
<?php if (!$jobs): ?>
  <div class="text-muted small">No jobs found in this period.</div>
<?php else: ?>
  <div class="list-group small">
  <?php foreach ($jobs as $r): ?>
    <div class="list-group-item">
      <div class="d-flex justify-content-between">
        <strong><?= h($r['date']) ?> · #<?= (int)$r['id'] ?></strong>
        <span>AED <?= number_format((float)$r['revenue'],2) ?></span>
      </div>
      <div class="text-muted"><?= h($r['client_name'] ?: '—') ?> · <?= number_format((float)$r['hours'],2) ?> h</div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ===== Feedback UI (list + add form) ===== -->
<hr class="my-3">

<h6 class="mb-2">Feedback</h6>

<!-- List that performance.php JS fills via ?ajax=feedback_list -->
<div id="fbList" class="mb-3 small">
  <div class="text-muted">Loading…</div>
</div>

<!-- Form posted by performance.php JS to ?ajax=feedback_add -->
<form id="fbForm" class="small">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-5">
      <label class="form-label mb-1">Category</label>
      <select class="form-select form-select-sm" name="category">
        <option value="coach">Coaching</option>
        <option value="praise">Praise</option>
        <option value="warn">Needs attention</option>
      </select>
    </div>
    <div class="col-3">
      <label class="form-label mb-1">Rating</label>
      <select class="form-select form-select-sm" name="rating">
        <option value="">—</option>
        <option>1</option><option>2</option><option>3</option><option>4</option><option>5</option>
      </select>
    </div>
    <div class="col-12 mt-2">
      <label class="form-label mb-1">Note</label>
      <textarea class="form-control form-control-sm" name="note" rows="2" placeholder="Short feedback..."></textarea>
    </div>
    <div class="col-12 mt-2 d-flex justify-content-end">
      <button class="btn btn-primary btn-sm">Save feedback</button>
    </div>
  </div>
</form>
