<?php
// hr/perf_settings.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_login();
require_role(['Owner','Admin','HR'], $conn);

/* -----------------------------------------------------------
   Period filters (default: current month)
----------------------------------------------------------- */
$today = new DateTimeImmutable('today');
$first = $today->modify('first day of this month');
$last  = $today->modify('last day of this month');

$from = isset($_GET['from']) && $_GET['from']!=='' ? new DateTimeImmutable($_GET['from']) : $first;
$to   = isset($_GET['to'])   && $_GET['to']  !=='' ? new DateTimeImmutable($_GET['to'])   : $last;

$fromStr = $from->format('Y-m-d');
$toStr   = $to->format('Y-m-d');

/* -----------------------------------------------------------
   Lookups
----------------------------------------------------------- */
$workers = $conn->query("
  SELECT
    id,
    COALESCE(NULLIF(nickname,''), CONCAT('Worker #', id)) AS name,
    weekly_cap_hours, daily_cap_hours
  FROM workers
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

/* Global month target row (one per period) */
$st = $conn->prepare("
  SELECT hours_target, notes
  FROM perf_month_targets
  WHERE period_from=:f AND period_to=:t
  LIMIT 1
");
$st->execute([':f'=>$fromStr, ':t'=>$toStr]);
$monthTargetRow = $st->fetch(PDO::FETCH_ASSOC) ?: ['hours_target'=>null,'notes'=>null];

/* Per-worker targets map for the period */
$st = $conn->prepare("
  SELECT worker_id, hours_target
  FROM perf_worker_targets
  WHERE period_from=:f AND period_to=:t
");
$st->execute([':f'=>$fromStr, ':t'=>$toStr]);
$targetMap = [];
foreach ($st as $r) $targetMap[(int)$r['worker_id']] = (float)$r['hours_target'];

/* Badge thresholds config (id=1) */
$cfg = $conn->query("
  SELECT top_badge_min, warn_badge_max
  FROM hr_perf_config
  WHERE id=1
")->fetch(PDO::FETCH_ASSOC);
$topBadgeMin  = isset($cfg['top_badge_min'])  ? (float)$cfg['top_badge_min']  : 90.0;
$warnBadgeMax = isset($cfg['warn_badge_max']) ? (float)$cfg['warn_badge_max'] : 60.0;

/* -----------------------------------------------------------
   POST handlers
----------------------------------------------------------- */
$flash = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify();
  $action = $_POST['_action'] ?? '';

  if ($action === 'save_month_target') {
    $hours = isset($_POST['month_hours']) ? (float)$_POST['month_hours'] : 0.0;
    $notes = trim($_POST['month_notes'] ?? '');

    $conn->prepare("
      INSERT INTO perf_month_targets (period_from, period_to, hours_target, notes)
      VALUES (:f,:t,:h,:n)
      ON DUPLICATE KEY UPDATE hours_target=VALUES(hours_target), notes=VALUES(notes)
    ")->execute([':f'=>$fromStr, ':t'=>$toStr, ':h'=>$hours, ':n'=>$notes]);

    $monthTargetRow = ['hours_target'=>$hours, 'notes'=>$notes];
    $flash = "Saved global month target.";

  } elseif ($action === 'save_badges') {
    $top  = isset($_POST['top_badge_min'])  ? (float)$_POST['top_badge_min']  : 90.0;
    $warn = isset($_POST['warn_badge_max']) ? (float)$_POST['warn_badge_max'] : 60.0;

    // guardrails
    $top  = max(0, min(100, $top));
    $warn = max(0, min(100, $warn));

    $conn->prepare("
      INSERT INTO hr_perf_config (id, top_badge_min, warn_badge_max)
      VALUES (1, :top, :warn)
      ON DUPLICATE KEY UPDATE
        top_badge_min=VALUES(top_badge_min),
        warn_badge_max=VALUES(warn_badge_max)
    ")->execute([':top'=>$top, ':warn'=>$warn]);

    $topBadgeMin  = $top;
    $warnBadgeMax = $warn;
    $flash = "Saved badge thresholds.";

  } elseif ($action === 'save_worker_targets') {
    // Save all rows provided; wipe existing for the period to keep it clean
    $conn->beginTransaction();
    try {
      $conn->prepare("
        DELETE FROM perf_worker_targets
        WHERE period_from=:f AND period_to=:t
      ")->execute([':f'=>$fromStr, ':t'=>$toStr]);

      $ins = $conn->prepare("
        INSERT INTO perf_worker_targets (period_from, period_to, worker_id, hours_target)
        VALUES (:f,:t,:w,:h)
      ");

      foreach (($_POST['target'] ?? []) as $wid => $val) {
        $wid = (int)$wid;
        if ($wid<=0) continue;
        $h = ($val === '' || $val === null) ? null : (float)$val;
        if ($h !== null) {
          $ins->execute([':f'=>$fromStr, ':t'=>$toStr, ':w'=>$wid, ':h'=>$h]);
          $targetMap[$wid] = $h;
        }
      }

      $conn->commit();
      $flash = "Saved per-worker targets for this period.";
    } catch (Throwable $e) {
      $conn->rollBack();
      $flash = "Error saving per-worker targets: ".$e->getMessage();
    }

  } elseif ($action === 'save_capacity') {
    $upd = $conn->prepare("
      UPDATE workers
      SET weekly_cap_hours = :w, daily_cap_hours = :d
      WHERE id = :id
    ");
    foreach (($_POST['cap'] ?? []) as $wid => $row) {
      $wid = (int)$wid;
      if ($wid<=0) continue;
      $w = isset($row['weekly']) && $row['weekly']!=='' ? (float)$row['weekly'] : null;
      $d = isset($row['daily'])  && $row['daily']  !=='' ? (float)$row['daily']  : null;
      $upd->execute([':w'=>$w, ':d'=>$d, ':id'=>$wid]);
    }
    // reload workers to reflect changes
    $workers = $conn->query("
      SELECT
        id,
        COALESCE(NULLIF(nickname,''), CONCAT('Worker #', id)) AS name,
        weekly_cap_hours, daily_cap_hours
      FROM workers
      ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $flash = "Saved capacity settings.";
  }
}

/* helper for live preview */
function capMonthly($weekly, $daily){
  $weekly = $weekly !== null ? (float)$weekly : 0;
  $daily  = $daily  !== null ? (float)$daily  : 0;
  if ($weekly > 0) return round($weekly * 4.33, 2);
  if ($daily  > 0) return round($daily  * 26,   2);
  return 0.00;
}

$pageTitle = 'Targets & Capacity';
$pageStyles = '.sub{color:#6b7280} .table thead th{white-space:nowrap}';
$pageScripts = <<<'JS'
<script>
  const btn = document.getElementById('btnSetAll');
  const val = document.getElementById('setAllVal');
  if (btn && val) {
    btn.addEventListener('click', () => {
      const v = val.value;
      if (v === '') return;
      document.querySelectorAll('.target-input').forEach(inp => { inp.value = v; });
    });
  }
</script>
JS;
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Targets & Capacity',
    'Configure performance targets, badge thresholds, and worker capacity.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Performance', 'href' => 'performance.php?from=' . urlencode($fromStr) . '&to=' . urlencode($toStr)],
        ['label' => 'Settings'],
    ],
    '<a class="btn btn-outline-secondary" href="performance.php?from=' . h($fromStr) . '&to=' . h($toStr) . '">Back to Performance</a>'
);
?>

  <form class="hr-filter-bar mb-3" method="get" action="perf_settings.php">
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="from" value="<?=h($fromStr)?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="to" value="<?=h($toStr)?>">
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-primary mt-4">Apply</button>
      </div>
    </div>
  </form>

  <?php if ($flash): ?>
    <div class="alert alert-info"><?=h($flash)?></div>
  <?php endif; ?>

  <!-- Global month target -->
  <form class="hr-settings-card mb-3" method="post">
    <?php csrf_field(); ?>
    <input type="hidden" name="_action" value="save_month_target">
    <div class="settings-header">Global month target</div>
    <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Hours target (all cleaners)</label>
        <input type="number" step="0.01" class="form-control" name="month_hours"
               value="<?= $monthTargetRow['hours_target']!==null ? h($monthTargetRow['hours_target']) : '' ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Notes (optional)</label>
        <input type="text" class="form-control" name="month_notes"
               value="<?= h($monthTargetRow['notes'] ?? '') ?>">
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-success mt-4">Save Month Target</button>
      </div>
    </div>
    </div>
  </form>

  <!-- Badge thresholds -->
  <form class="hr-settings-card mb-3" method="post">
    <?php csrf_field(); ?>
    <input type="hidden" name="_action" value="save_badges">
    <div class="settings-header">Badge thresholds</div>
    <div class="card-body">
    <div class="sub mb-3">
      Controls “Achiev. %” colors on Performance:
      <span class="badge text-bg-success">Top performer</span> when ≥ Top %,
      <span class="badge text-bg-danger">Needs attention</span> when &lt; Warn %,
      otherwise gray.
    </div>
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Top performer ≥ (%)</label>
        <input type="number" step="0.1" min="0" max="100" class="form-control" name="top_badge_min"
               value="<?= h($topBadgeMin) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Needs attention &lt; (%)</label>
        <input type="number" step="0.1" min="0" max="100" class="form-control" name="warn_badge_max"
               value="<?= h($warnBadgeMax) ?>">
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-primary mt-4">Save Badge Rules</button>
      </div>
    </div>
    </div>
  </form>

  <!-- Per-worker targets -->
  <form class="hr-settings-card mb-3" method="post">
    <?php csrf_field(); ?>
    <input type="hidden" name="_action" value="save_worker_targets">
    <div class="settings-header d-flex justify-content-between align-items-center">
      <span>Per-worker targets (this period)</span>
      <div class="d-flex gap-2">
        <input type="number" step="0.01" class="form-control form-control-sm" id="setAllVal" placeholder="Set all…">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSetAll">Apply to all</button>
      </div>
    </div>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table align-middle">
        <thead><tr><th>Worker</th><th style="width:160px" class="text-end">Target (h)</th></tr></thead>
        <tbody>
          <?php foreach ($workers as $w): ?>
            <tr>
              <td><?= h($w['name']) ?></td>
              <td class="text-end">
                <input type="number" step="0.01" class="form-control text-end target-input"
                       name="target[<?= (int)$w['id'] ?>]"
                       value="<?= isset($targetMap[$w['id']]) ? h($targetMap[$w['id']]) : '' ?>">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body pt-0">
    <button class="btn btn-success">Save Per-worker Targets</button>
    </div>
  </form>

  <!-- Capacity per worker -->
  <form class="hr-settings-card mb-3" method="post">
    <?php csrf_field(); ?>
    <input type="hidden" name="_action" value="save_capacity">
    <div class="settings-header">Worker capacity (source for “Capacity (mo)”)</div>
    <div class="card-body">
    <div class="sub mb-2">Capacity (mo) = weekly × 4.33, or daily × 26 (if weekly blank).</div>
    <div class="hr-table-shell border-0 shadow-none rounded-0 p-0">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Worker</th>
            <th style="width:160px" class="text-end">Weekly cap (h)</th>
            <th style="width:160px" class="text-end">Daily cap (h)</th>
            <th style="width:160px" class="text-end">Capacity (mo)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($workers as $w): ?>
            <?php
              $weekly = $w['weekly_cap_hours'];
              $daily  = $w['daily_cap_hours'];
              $capMo  = capMonthly($weekly, $daily);
            ?>
            <tr>
              <td><?= h($w['name']) ?></td>
              <td class="text-end">
                <input type="number" step="0.01" class="form-control text-end"
                       name="cap[<?= (int)$w['id'] ?>][weekly]"
                       value="<?= $weekly!==null ? h((string)$weekly) : '' ?>">
              </td>
              <td class="text-end">
                <input type="number" step="0.01" class="form-control text-end"
                       name="cap[<?= (int)$w['id'] ?>][daily]"
                       value="<?= $daily!==null ? h((string)$daily) : '' ?>">
              </td>
              <td class="text-end"><?= number_format($capMo, 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button class="btn btn-success mt-3">Save Capacity</button>
    </div>
  </form>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
