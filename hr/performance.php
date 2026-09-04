<?php
/* hr/performance.php — PRO edition (profitability, deltas, drilldown, donut, leaderboard) */

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/includes/hr_company_scope.php';
require_once __DIR__.'/includes/hr_employee_lifecycle.php';
require_login();
require_role(['Owner','Admin','HR'], $conn);

$dateExpr = 'COALESCE(mo.service_date, mo.date)';

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** Fuzzy workers ↔ employees map used by cleaning performance. */
function perf_worker_employee_join_sql(string $wAlias = 'w', string $eAlias = 'e'): string
{
  return "(
    ({$eAlias}.employee_code IS NOT NULL AND {$eAlias}.employee_code<>'' AND {$eAlias}.employee_code={$wAlias}.emp_num)
 OR ({$eAlias}.nickname IS NOT NULL AND {$eAlias}.nickname<>'' AND {$eAlias}.nickname={$wAlias}.nickname)
 OR ({$eAlias}.full_name IS NOT NULL AND {$eAlias}.full_name<>'' AND {$eAlias}.full_name={$wAlias}.worker_name)
  )";
}


/* 0.a) AJAX: list feedback for a worker in the selected period */
if (isset($_GET['ajax']) && $_GET['ajax']==='feedback_list') {
  header('Content-Type: application/json');
  require_once __DIR__.'/../includes/db_connect.php';
  $wid  = (int)($_GET['wid'] ?? 0);
  $from = new DateTimeImmutable($_GET['from'] ?? 'first day of this month');
  $to   = new DateTimeImmutable($_GET['to']   ?? 'last day of this month');

  $st = $conn->prepare("
    SELECT id, category, rating, note, created_at, created_by
    FROM perf_feedback
    WHERE worker_id = ?
      AND period_from <= ? AND period_to >= ?
    ORDER BY created_at DESC, id DESC
  ");
  $st->execute([$wid, $to->format('Y-m-d'), $from->format('Y-m-d')]);
  echo json_encode(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

/* 0.b) AJAX: add feedback (POST) */
if (isset($_GET['ajax']) && $_GET['ajax']==='feedback_add' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json');
  require_once __DIR__.'/../includes/db_connect.php';

  // CSRF (from includes/auth.php)
  csrf_verify();

  $wid   = (int)($_POST['wid'] ?? 0);
  $from  = new DateTimeImmutable($_POST['from'] ?? 'first day of this month');
  $to    = new DateTimeImmutable($_POST['to']   ?? 'last day of this month');
  $cat   = in_array(($_POST['category'] ?? 'coach'), ['praise','coach','warn'], true) ? $_POST['category'] : 'coach';
  $rating= isset($_POST['rating']) && $_POST['rating']!=='' ? max(1,min(5,(int)$_POST['rating'])) : null;
  $note  = trim((string)($_POST['note'] ?? ''));

  if ($wid<=0 || $note==='') { http_response_code(422); echo json_encode(['error'=>'Missing worker or note']); exit; }

  $st = $conn->prepare("
    INSERT INTO perf_feedback (worker_id, period_from, period_to, category, rating, note, created_by)
    VALUES (?,?,?,?,?,?,?)
  ");
  $st->execute([
    $wid, $from->format('Y-m-d'), $to->format('Y-m-d'),
    $cat, $rating, $note, (int)(current_user_id() ?? 0)
  ]);
  echo json_encode(['ok'=>true,'id'=>$conn->lastInsertId()]);
  exit;
}

/* -------------------------------------------------------
   0) AJAX: worker drill-down (JSON) then exit
--------------------------------------------------------*/
if (isset($_GET['ajax']) && $_GET['ajax']==='worker') {
  header('Content-Type: application/json');
  $wid   = (int)($_GET['wid'] ?? 0);
  $from  = new DateTimeImmutable($_GET['from'] ?? 'first day of this month');
  $to    = new DateTimeImmutable($_GET['to']   ?? 'last day of this month');

  // core snapshot for worker
  $sql = "
    SELECT
      w.id AS worker_id,
      COALESCE(NULLIF(w.nickname,''), CONCAT('Worker #', w.id)) AS name,
      COALESCE(w.weekly_cap_hours,0) AS weekly_cap,
      COALESCE(w.daily_cap_hours,0)  AS daily_cap,
      COALESCE(w.total_salary, COALESCE(w.basic_salary,0)+COALESCE(w.allowance,0)+COALESCE(w.bonus,0)) AS monthly_salary,
      ROUND(SUM(COALESCE(mo.net_hours,0)),2) AS hours,
      ROUND(SUM(CASE WHEN owc.cnt>0 THEN mo.grand_total/owc.cnt ELSE 0 END),2) AS revenue
    FROM order_workers ow
    JOIN workers w ON w.id=ow.worker_id
    JOIN make_order mo ON mo.id=ow.order_id
    JOIN (SELECT order_id,COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id=mo.id
    WHERE mo.status IN('confirmed','completed')
      AND {$dateExpr} BETWEEN :from AND :to
      AND ow.worker_id=:wid
  ";
  $st = $conn->prepare($sql);
  $st->execute([':from'=>$from->format('Y-m-d'), ':to'=>$to->format('Y-m-d'), ':wid'=>$wid]);
  $snap = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  // worker->employee map
  $eid = null;
  $m = $conn->prepare("SELECT e.id
                       FROM workers w
                       LEFT JOIN employees e
                         ON (
                              (e.employee_code IS NOT NULL AND e.employee_code<>'' AND e.employee_code=w.emp_num)
                           OR (e.nickname     IS NOT NULL AND e.nickname    <>'' AND e.nickname    =w.nickname)
                           OR (e.full_name    IS NOT NULL AND e.full_name   <>'' AND e.full_name   =w.worker_name)
                         )
                       WHERE w.id=?");
  $m->execute([$wid]);
  $eid = (int)$m->fetchColumn() ?: null;

  $attendance = ['present'=>0,'absent'=>0,'ot_hours'=>0.0,'ot_pay'=>0.0];
  if ($eid) {
    // present/absent and OT hours from attendance
    $a = $conn->prepare("SELECT
                           SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS p,
                           SUM(CASE WHEN status IN('absent','on_leave','half') THEN 1 ELSE 0 END) AS a,
                           ROUND(SUM(CASE WHEN status='approved' AND hours>8 THEN (hours-8) ELSE 0 END),2) AS ot_h
                         FROM attendance
                         WHERE employee_id=? AND work_date BETWEEN ? AND ?");
    $a->execute([$eid,$from->format('Y-m-d'),$to->format('Y-m-d')]);
    $aa = $a->fetch(PDO::FETCH_ASSOC) ?: [];
    $attendance['present']  = (int)($aa['p'] ?? 0);
    $attendance['absent']   = (int)($aa['a'] ?? 0);
    $attendance['ot_hours'] = (float)($aa['ot_h'] ?? 0);

    // OT pay from overtime_entries (approved)
    $ot = $conn->prepare("SELECT
                            ROUND(SUM(pay_hours),2)   AS pay_hours,
                            ROUND(SUM(pay_amount),2) AS pay_amount
                          FROM overtime_entries
                          WHERE status='approved'
                            AND employee_id=? AND ot_date BETWEEN ? AND ?");
    $ot->execute([$eid,$from->format('Y-m-d'),$to->format('Y-m-d')]);
    $oo = $ot->fetch(PDO::FETCH_ASSOC) ?: [];
    $attendance['ot_pay'] = (float)($oo['pay_amount'] ?? 0);
  }

  // latest 8 jobs
  $jobs = [];
  $j = $conn->prepare("
    SELECT mo.id, mo.date, mo.client_name, 
           ROUND(COALESCE(mo.net_hours, CASE WHEN owc.cnt>0 THEN mo.hours/owc.cnt ELSE 0 END),2) AS hours,
           ROUND(CASE WHEN owc.cnt>0 THEN mo.grand_total/owc.cnt ELSE 0 END,2) AS revenue
    FROM order_workers ow
    JOIN make_order mo ON mo.id=ow.order_id
    JOIN (SELECT order_id,COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id=mo.id
    WHERE mo.status IN('confirmed','completed')
      AND {$dateExpr} BETWEEN :from AND :to
      AND ow.worker_id=:wid
    ORDER BY {$dateExpr} DESC, mo.id DESC
    LIMIT 8
  ");
  $j->execute([':from'=>$from->format('Y-m-d'), ':to'=>$to->format('Y-m-d'), ':wid'=>$wid]);
  $jobs = $j->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['snapshot'=>$snap,'attendance'=>$attendance,'jobs'=>$jobs]);
  exit;
}

/* -------------------------------------------------------
   1) Filters
--------------------------------------------------------*/
$today = new DateTimeImmutable('today');
$first = $today->modify('first day of this month');
$last  = $today->modify('last day of this month');

$from = isset($_GET['from']) && $_GET['from']!=='' ? new DateTimeImmutable($_GET['from']) : $first;
$to   = isset($_GET['to'])   && $_GET['to']  !=='' ? new DateTimeImmutable($_GET['to'])   : $last;

$view = ($_GET['view'] ?? 'cleaning') === 'workforce' ? 'workforce' : 'cleaning';
$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);
$departmentId = (int)($_GET['department_id'] ?? 0);

$workerA = (int)($_GET['worker_id']   ?? 0);
$workerB = (int)($_GET['worker_id_b'] ?? 0);
$employeeA = (int)($_GET['employee_id'] ?? 0);

$days = max(1, (int)$from->diff($to)->format('%a') + 1);
$monthsFactor = $days / 30.4375; // avg month

/* previous period for deltas */
$prevFrom = $from->modify('-'.$days.' days');
$prevTo   = $from->modify('-1 day');

/* -------------------------------------------------------
   2) Lookups
--------------------------------------------------------*/
$departments = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$cleanerSql = "
  SELECT w.id, COALESCE(NULLIF(w.nickname,''), CONCAT('Worker #', w.id)) AS name
  FROM workers w
";
$cleanerParams = [];
if ($selectedCompanyId > 0) {
  $cleanerSql .= " WHERE EXISTS (
      SELECT 1 FROM employees e
      WHERE e.company_id = ?
        AND " . perf_worker_employee_join_sql('w', 'e') . "
    )";
  $cleanerParams[] = $selectedCompanyId;
}
$cleanerSql .= " ORDER BY name";
$cleanerStmt = $conn->prepare($cleanerSql);
$cleanerStmt->execute($cleanerParams);
$cleaners = $cleanerStmt->fetchAll(PDO::FETCH_ASSOC);

$employeePicker = [];
if ($view === 'workforce') {
  $empPickSql = "
    SELECT e.id, CONCAT(e.full_name, ' (', e.employee_code, ')') AS label, c.name AS company_name
    FROM employees e
    LEFT JOIN companies c ON c.id = e.company_id
    WHERE e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")
  ";
  $empPickParams = hr_employee_current_statuses();
  if ($selectedCompanyId > 0) {
    $empPickSql .= " AND e.company_id = ?";
    $empPickParams[] = $selectedCompanyId;
  }
  if ($departmentId > 0) {
    $empPickSql .= " AND e.department_id = ?";
    $empPickParams[] = $departmentId;
  }
  $empPickSql .= " ORDER BY e.full_name";
  $stPick = $conn->prepare($empPickSql);
  $stPick->execute($empPickParams);
  $employeePicker = $stPick->fetchAll(PDO::FETCH_ASSOC);
}

/* badge thresholds from settings (fallbacks) */
$badge = ['top'=>90.0,'warn'=>60.0];
try {
  $r = $conn->query("SELECT top_pct, warn_pct FROM perf_badge_rules ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  if ($r) {
    if ($r['top_pct']  !== null) $badge['top']  = (float)$r['top_pct'];
    if ($r['warn_pct'] !== null) $badge['warn'] = (float)$r['warn_pct'];
  }
} catch(Throwable $e) { /* ignore if table missing */ }

/* -------------------------------------------------------
   3–8) Metrics by view (cleaning jobs/revenue vs workforce attendance)
--------------------------------------------------------*/
require_once __DIR__ . '/includes/performance_metrics.php';

// Page settings for shared layout
$pageTitle = 'Performance';
$pageHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>';
$pageStyles = '
    .perf-kpi { border:0; box-shadow:0 12px 28px rgba(16,24,40,.06); border-radius:18px; }
    .kpi { font-size:1.25rem; font-weight:700; }
    .sub { color:#6b7280; }
    .delta-up { color:#16a34a; font-weight:600; }
    .delta-down { color:#dc2626; font-weight:600; }
    .badge-warn { background:#fee2e2; color:#b91c1c; border-radius:10px; padding:.15rem .35rem; font-weight:600; }
    .badge-top { background:#dcfce7; color:#166534; border-radius:10px; padding:.15rem .35rem; font-weight:600; }
    .badge-mid { background:#e5e7eb; color:#374151; border-radius:10px; padding:.15rem .35rem; font-weight:600; }
    .chip { border-radius:999px; background:#fff; border:1px solid #e5e7eb; padding:.25rem .6rem; margin-right:.35rem; display:inline-block; margin-bottom:.35rem; }
    a.worker-link { text-decoration:none; }
    .perf-view-tabs .nav-link { border-radius:999px; padding:.4rem 1rem; }
    .perf-view-tabs .nav-link.active { background: var(--hr-gold, #c9a227); color:#1a1a1a; font-weight:600; }
';
$subtitle = $view === 'workforce'
  ? 'Company-wide attendance, hours, and overtime for current employees.'
  : 'Cleaner productivity, hours, revenue, and target achievement.';
$hrScopeLabel = $companyScopeLabel ?? '';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Performance',
    $subtitle,
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Performance'],
    ],
    '<a class="btn btn-outline-secondary" href="dashboard">Back</a>'
    . ($view === 'cleaning'
        ? '<a class="btn btn-outline-primary" href="perf_settings?from=' . h($from->format('Y-m-d')) . '&to=' . h($to->format('Y-m-d')) . '">Targets & Capacity</a>'
        : '')
);
?>

  <ul class="nav perf-view-tabs gap-2 mb-3">
    <?php
      $baseQs = [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'company_id' => $selectedCompanyId,
      ];
    ?>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'cleaning' ? 'active' : 'bg-white border' ?>"
         href="?<?= h(http_build_query($baseQs + ['view' => 'cleaning'])) ?>">Cleaning productivity</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'workforce' ? 'active' : 'bg-white border' ?>"
         href="?<?= h(http_build_query($baseQs + ['view' => 'workforce'])) ?>">Workforce (all companies)</a>
    </li>
  </ul>

  <div class="hr-filter-bar mb-4">
    <form method="get" action="performance">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <div class="row g-3 align-items-end">
        <div class="col-md-2">
          <label class="form-label">From</label>
          <input type="date" class="form-control" name="from" value="<?= h($from->format('Y-m-d')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">To</label>
          <input type="date" class="form-control" name="to" value="<?= h($to->format('Y-m-d')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Company</label>
          <select class="form-select" name="company_id">
            <option value="0">All companies</option>
            <?php foreach ($companies as $co): ?>
              <option value="<?= (int)$co['id'] ?>" <?= $selectedCompanyId === (int)$co['id'] ? 'selected' : '' ?>><?= h($co['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($view === 'cleaning'): ?>
          <div class="col-md-2">
            <label class="form-label">Cleaner</label>
            <select class="form-select" name="worker_id">
              <option value="0">All cleaners</option>
              <?php foreach ($cleaners as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $workerA === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Compare with</label>
            <select class="form-select" name="worker_id_b">
              <option value="0">—</option>
              <?php foreach ($cleaners as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $workerB === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div class="col-md-2">
            <label class="form-label">Department</label>
            <select class="form-select" name="department_id">
              <option value="0">All departments</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= $departmentId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Employee</label>
            <select class="form-select" name="employee_id">
              <option value="0">All employees</option>
              <?php foreach ($employeePicker as $e): ?>
                <option value="<?= (int)$e['id'] ?>" <?= $employeeA === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="col-md-2">
          <button class="btn btn-primary w-100">Apply</button>
        </div>
      </div>
      <div class="mt-2 small text-muted">Scope: <?= h($companyScopeLabel) ?> · <?= $view === 'cleaning' ? 'Cleaning jobs & revenue' : 'Current employees only (attendance / OT)' ?></div>
    </form>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card perf-kpi p-3">
        <div class="sub">Period</div>
        <div class="kpi"><?= h($from->format('Y-m-d')) ?> → <?= h($to->format('Y-m-d')) ?></div>
        <div class="sub"><?= number_format($days) ?> days (~<?= number_format($monthsFactor, 2) ?> mo)</div>
      </div>
    </div>
    <?php if ($view === 'cleaning'): ?>
      <div class="col-md-3">
        <div class="card perf-kpi p-3">
          <div class="sub">Total jobs</div>
          <div class="kpi"><?= number_format($totals['jobs']) ?></div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card perf-kpi p-3">
          <div class="d-flex justify-content-between">
            <div>
              <div class="sub">Total hours</div>
              <div class="kpi"><?= number_format($totals['hours'], 2) ?></div>
              <?php if ($kpiTarget): ?>
                <div class="sub">
                  Target: <?= number_format($kpiTarget, 1) ?> h
                  <?php if ($kpiAchv !== null): ?>
                    <span class="ms-1 <?= $kpiAchv >= $badge['top'] ? 'badge-top' : ($kpiAchv < $badge['warn'] ? 'badge-warn' : 'badge-mid') ?>"><?= number_format($kpiAchv, 1) ?>%</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <?php if ($kpiTarget): ?>
              <div style="width:95px;height:95px"><canvas id="donutTarget"></canvas></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card perf-kpi p-3">
          <div class="sub">Revenue / Net</div>
          <div class="kpi">AED <?= number_format($totals['revenue'], 2) ?></div>
          <div class="sub">Net AED <?= number_format($totals['net'], 2) ?> (cost <?= number_format($totals['cost'], 2) ?>)</div>
        </div>
      </div>
    <?php else: ?>
      <div class="col-md-3">
        <div class="card perf-kpi p-3">
          <div class="sub">Employees</div>
          <div class="kpi"><?= number_format($totals['employees']) ?></div>
          <div class="sub">Present <?= number_format($totals['present']) ?> · Absent <?= number_format($totals['absent']) ?></div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card perf-kpi p-3">
          <div class="sub">Attendance hours</div>
          <div class="kpi"><?= number_format($totals['hours'], 2) ?></div>
          <div class="sub">OT <?= number_format($totals['ot_hours'], 2) ?> h</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card perf-kpi p-3">
          <div class="sub">Est. payroll cost</div>
          <div class="kpi">AED <?= number_format($totals['cost'], 2) ?></div>
          <div class="sub">Salary prorated + approved OT pay</div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php
    $ranked = $rows;
    if ($view === 'cleaning') {
      usort($ranked, static function ($a, $b) {
        $a1 = $a['achv_pct'] ?? -1;
        $b1 = $b['achv_pct'] ?? -1;
        if ($a1 === $b1) {
          return ($b['rev_per_hr'] ?? -1) <=> ($a['rev_per_hr'] ?? -1);
        }
        return $b1 <=> $a1;
      });
    } else {
      usort($ranked, static function ($a, $b) {
        return ((float)$b['hours_worked']) <=> ((float)$a['hours_worked']);
      });
    }
    $leaders = array_slice($ranked, 0, 3);
  ?>
  <?php if ($leaders): ?>
    <div class="mb-3">
      <?php foreach ($leaders as $i => $L): ?>
        <?php if ($view === 'cleaning'): ?>
          <span class="chip">#<?= $i + 1 ?> <?= h($L['worker_name']) ?> · <?= $L['achv_pct'] !== null ? number_format($L['achv_pct'], 1) . '%' : '—' ?> · <?= $L['rev_per_hr'] !== null ? 'AED ' . number_format($L['rev_per_hr'], 2) . '/h' : '' ?></span>
        <?php else: ?>
          <span class="chip">#<?= $i + 1 ?> <?= h($L['worker_name']) ?> · <?= number_format((float)$L['hours_worked'], 1) ?> h · <?= h($L['company_name'] ?? '') ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card perf-kpi p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center">
      <h6 class="mb-0"><?= $view === 'cleaning' ? 'Hours vs Revenue' : 'Hours vs OT (top 25)' ?></h6>
      <small class="text-muted"><?= h($from->format('Y-m-d')) ?> → <?= h($to->format('Y-m-d')) ?></small>
    </div>
    <canvas id="perfChart" height="80"></canvas>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <h6 class="mb-0"><?= $view === 'cleaning' ? 'Cleaner performance' : 'Workforce performance' ?></h6>
      <a class="btn btn-sm btn-outline-secondary" href="?<?= h($exportQs) ?>">Export CSV</a>
    </div>
    <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <div class="table-responsive">
          <table class="table align-middle table-striped mb-0">
            <?php if ($view === 'cleaning'): ?>
              <thead>
                <tr>
                  <th>Worker</th>
                  <th class="text-end">Jobs</th>
                  <th class="text-end">Hours</th>
                  <th class="text-end">ΔH</th>
                  <th class="text-end">Capacity (mo)</th>
                  <th class="text-end">Util.</th>
                  <th class="text-end">Present</th>
                  <th class="text-end">Absent</th>
                  <th class="text-end">OT (h)</th>
                  <th class="text-end">Target (h)</th>
                  <th class="text-end">Achiev. %</th>
                  <th class="text-end">Rev/hr</th>
                  <th class="text-end">Margin</th>
                  <th class="text-end">Revenue</th>
                  <th class="text-end">ΔRev</th>
                  <th class="text-end">Est. cost</th>
                  <th class="text-end">Net</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="17" class="text-center text-muted py-4">No cleaning data for the selected filters.</td></tr>
              <?php else: foreach ($rows as $r):
                  $p = $prev[(int)$r['worker_id']] ?? ['h' => 0, 'r' => 0];
                  $dH = (float)$r['hours_worked'] - (float)$p['h'];
                  $dR = (float)$r['revenue_incl_vat'] - (float)$p['r'];
                  $achClass = ($r['achv_pct'] === null) ? 'badge-mid' : ($r['achv_pct'] >= $badge['top'] ? 'badge-top' : ($r['achv_pct'] < $badge['warn'] ? 'badge-warn' : 'badge-mid'));
              ?>
                <tr>
                  <td><a href="#" class="worker-link" data-wid="<?= (int)$r['worker_id'] ?>"><?= h($r['worker_name']) ?></a></td>
                  <td class="text-end"><?= number_format($r['jobs']) ?></td>
                  <td class="text-end"><?= number_format($r['hours_worked'], 2) ?></td>
                  <td class="text-end">
                    <?php if ($dH > 0): ?><span class="delta-up">▲ <?= number_format($dH, 2) ?></span>
                    <?php elseif ($dH < 0): ?><span class="delta-down">▼ <?= number_format(abs($dH), 2) ?></span>
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td class="text-end"><?= $r['capacity_month'] ? number_format($r['capacity_month'], 2) : '—' ?></td>
                  <td class="text-end"><?= $r['utilization'] !== null ? number_format($r['utilization'], 1) . '%' : '—' ?></td>
                  <td class="text-end"><?= (int)$r['present_days'] ?></td>
                  <td class="text-end"><?= (int)$r['absent_days'] ?></td>
                  <td class="text-end"><?= number_format((float)$r['ot_hours'], 2) ?></td>
                  <td class="text-end"><?= $r['target_hours'] !== null ? number_format((float)$r['target_hours'], 1) : '—' ?></td>
                  <td class="text-end"><span class="<?= $achClass ?>"><?= $r['achv_pct'] !== null ? number_format((float)$r['achv_pct'], 1) . '%' : '—' ?></span></td>
                  <td class="text-end"><?= $r['rev_per_hr'] !== null ? 'AED ' . number_format($r['rev_per_hr'], 2) : '—' ?></td>
                  <td class="text-end"><?= $r['margin_pct'] !== null ? number_format($r['margin_pct'], 1) . '%' : '—' ?></td>
                  <td class="text-end"><?= number_format((float)$r['revenue_incl_vat'], 2) ?></td>
                  <td class="text-end">
                    <?php if ($dR > 0): ?><span class="delta-up">▲ <?= number_format($dR, 2) ?></span>
                    <?php elseif ($dR < 0): ?><span class="delta-down">▼ <?= number_format(abs($dR), 2) ?></span>
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td class="text-end"><?= number_format($r['cost_prorated'], 2) ?></td>
                  <td class="text-end <?= $r['net_contrib'] < 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($r['net_contrib'], 2) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            <?php else: ?>
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Company</th>
                  <th>Department</th>
                  <th class="text-end">Present</th>
                  <th class="text-end">Absent</th>
                  <th class="text-end">Hours</th>
                  <th class="text-end">ΔH</th>
                  <th class="text-end">OT (h)</th>
                  <th class="text-end">OT pay</th>
                  <th class="text-end">Att. dens.</th>
                  <th class="text-end">Est. cost</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">No employees match the selected filters.</td></tr>
              <?php else: foreach ($rows as $r):
                  $p = $prev[(int)$r['employee_id']] ?? ['h' => 0, 'r' => 0];
                  $dH = (float)$r['hours_worked'] - (float)$p['h'];
              ?>
                <tr>
                  <td>
                    <a href="employee_view?id=<?= (int)$r['employee_id'] ?>"><?= h($r['worker_name']) ?></a>
                    <div class="small text-muted"><?= h($r['employee_code']) ?></div>
                  </td>
                  <td><?= h($r['company_name']) ?></td>
                  <td><?= h($r['department_name']) ?></td>
                  <td class="text-end"><?= (int)$r['present_days'] ?></td>
                  <td class="text-end"><?= (int)$r['absent_days'] ?></td>
                  <td class="text-end"><?= number_format((float)$r['hours_worked'], 2) ?></td>
                  <td class="text-end">
                    <?php if ($dH > 0): ?><span class="delta-up">▲ <?= number_format($dH, 2) ?></span>
                    <?php elseif ($dH < 0): ?><span class="delta-down">▼ <?= number_format(abs($dH), 2) ?></span>
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td class="text-end"><?= number_format((float)$r['ot_hours'], 2) ?></td>
                  <td class="text-end"><?= number_format((float)$r['ot_pay'], 2) ?></td>
                  <td class="text-end"><?= $r['utilization'] !== null ? number_format($r['utilization'], 1) . '%' : '—' ?></td>
                  <td class="text-end"><?= number_format((float)$r['cost_prorated'], 2) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            <?php endif; ?>
          </table>
        </div>
      </div>
    </div>
  </div>

<?php if ($view === 'cleaning'): ?>
<!-- Offcanvas for drill-down -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="ocWorker" style="width:480px">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="ocTitle">Worker</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body" id="ocBody">
    <div class="text-muted">Select a cleaner to view details.</div>
  </div>
</div>
<?php endif; ?>

<script>
  const PERF_VIEW = <?= json_encode($view) ?>;
  const labels = <?= json_encode($chartLabels) ?>;
  const hours  = <?= json_encode($chartHours) ?>;
  const rev    = <?= json_encode($chartRev) ?>;
  const otHrs  = <?= json_encode($chartOt) ?>;

  const chartEl = document.getElementById('perfChart');
  if (chartEl && typeof Chart !== 'undefined') {
    const datasets = PERF_VIEW === 'workforce'
      ? [
          { label: 'Hours', data: hours, yAxisID: 'y1' },
          { label: 'OT (h)', data: otHrs, type: 'line', yAxisID: 'y2' }
        ]
      : [
          { label: 'Hours', data: hours, yAxisID: 'y1' },
          { label: 'Revenue (AED)', data: rev, type: 'line', yAxisID: 'y2' }
        ];
    new Chart(chartEl, {
      type: 'bar',
      data: { labels, datasets },
      options: {
        responsive: true,
        scales: {
          y1: { position: 'left', beginAtZero: true },
          y2: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } }
        },
        plugins: { legend: { position: 'bottom' } }
      }
    });
  }

  const donutEl = document.getElementById('donutTarget');
  <?php
    $done = (float)$totals['hours'];
    $goal = (float)($kpiTarget ?? 0);
    $rem  = max(0, $goal - $done);
  ?>
  if (donutEl && typeof Chart !== 'undefined') {
    new Chart(donutEl, {
      type: 'doughnut',
      data: {
        labels: ['Done', 'Remaining'],
        datasets: [{ data: [<?= json_encode($done) ?>, <?= json_encode($rem) ?>] }]
      },
      options: { cutout: '70%', plugins: { legend: { display: false } } }
    });
  }

  <?php if ($view === 'cleaning'): ?>
  const CSRF = <?= json_encode(csrf_token()) ?>;
  const PERIOD_FROM = <?= json_encode($from->format('Y-m-d')) ?>;
  const PERIOD_TO   = <?= json_encode($to->format('Y-m-d')) ?>;

  function escapeHtml(s) {
    return (s ?? '').toString()
      .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
  }

  function renderFeedbackList(items) {
    const wrap = document.getElementById('fbList');
    if (!wrap) return;
    if (!items || !items.length) {
      wrap.innerHTML = '<div class="text-muted">No feedback yet for this period.</div>';
      return;
    }
    wrap.innerHTML = items.map(row => {
      const tag =
        row.category === 'praise' ? '<span class="badge bg-success-subtle text-success-emphasis me-1">Praise</span>' :
        row.category === 'warn'   ? '<span class="badge bg-danger-subtle text-danger-emphasis me-1">Needs attention</span>' :
                                    '<span class="badge bg-secondary-subtle text-secondary-emphasis me-1">Coaching</span>';
      const star = row.rating ? (' <span class="text-warning">★</span>'.repeat(row.rating)) : '';
      const dt = new Date(String(row.created_at).replace(' ', 'T'));
      return `<div class="border rounded p-2 mb-2">
        <div class="d-flex justify-content-between"><div>${tag}${star}</div>
        <small class="text-muted">${dt.toLocaleString()}</small></div>
        <div>${escapeHtml(row.note)}</div></div>`;
    }).join('');
  }

  async function loadFeedback(wid) {
    const url = new URL(location.href);
    url.searchParams.set('ajax', 'feedback_list');
    url.searchParams.set('wid', wid);
    url.searchParams.set('from', PERIOD_FROM);
    url.searchParams.set('to', PERIOD_TO);
    const res = await fetch(url.toString());
    const data = await res.json();
    renderFeedbackList(data.items || []);
  }

  async function saveFeedback(wid, form) {
    const url = new URL(location.href);
    url.searchParams.set('ajax', 'feedback_add');
    const fd = new FormData(form);
    fd.set('_csrf', CSRF);
    fd.set('wid', wid);
    fd.set('from', PERIOD_FROM);
    fd.set('to', PERIOD_TO);
    const res = await fetch(url.toString(), { method: 'POST', body: fd });
    if (!res.ok) { alert('Could not save feedback'); return; }
    await loadFeedback(wid);
    form.reset();
  }

  const ocEl = document.getElementById('ocWorker');
  if (ocEl) {
    const oc = bootstrap.Offcanvas.getOrCreateInstance(ocEl);
    const ocTitle = document.getElementById('ocTitle');
    const ocBody  = document.getElementById('ocBody');
    document.querySelectorAll('.worker-link').forEach(a => {
      a.addEventListener('click', async (e) => {
        e.preventDefault();
        const wid = a.dataset.wid;
        ocTitle.textContent = 'Loading…';
        ocBody.innerHTML = '<div class="text-muted p-2">Fetching details…</div>';
        oc.show();
        try {
          const url = `performance_detail?worker_id=${encodeURIComponent(wid)}&from=${encodeURIComponent(PERIOD_FROM)}&to=${encodeURIComponent(PERIOD_TO)}`;
          const res = await fetch(url, { credentials: 'same-origin' });
          const html = await res.text();
          ocBody.innerHTML = html;
          const titleEl = ocBody.querySelector('[data-worker-name]');
          ocTitle.textContent = titleEl ? titleEl.getAttribute('data-worker-name') : 'Worker';
          await loadFeedback(wid);
          const fbForm = document.getElementById('fbForm');
          if (fbForm) {
            fbForm.onsubmit = async (ev) => {
              ev.preventDefault();
              await saveFeedback(wid, fbForm);
            };
          }
        } catch (err) {
          console.error(err);
          ocBody.innerHTML = '<div class="text-danger p-2">Error loading details.</div>';
          ocTitle.textContent = 'Worker';
        }
      });
    });
  }
  <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
