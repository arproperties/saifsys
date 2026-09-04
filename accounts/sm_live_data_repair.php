<?php
/**
 * Owner-only Live Data Repair console — batch-fix legacy health issues (dry-run first).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/cleaning_accounting_context.php';
require_once __DIR__ . '/../includes/sm_data_repair_service.php';

require_role(['Owner'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$companyId = current_company_id($conn) ?: cleaning_accounting_company_id($conn);
$userId = function_exists('current_user_id') ? current_user_id() : null;
$steps = sm_repair_steps();
uasort($steps, static fn($a, $b) => $a['order'] <=> $b['order']);

$runResults = null;
$runStep = null;
$flashError = '';
$flashSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $stepKey = (string)($_POST['step'] ?? '');
    $dryRun = ($action === 'preview' || $action === 'preview_all');

    if ($action !== 'preview' && $action !== 'execute' && $action !== 'preview_all' && $action !== 'execute_all') {
        $flashError = 'Invalid action.';
    } elseif (!$dryRun && empty($_POST['confirm_backup'])) {
        $flashError = 'You must confirm a full database backup before executing repairs.';
    } else {
        try {
            if ($action === 'preview_all' || $action === 'execute_all') {
                $runResults = sm_repair_run_all($conn, $companyId, $dryRun, $userId);
                $runStep = 'all';
                $flashSuccess = $dryRun
                    ? 'Dry-run preview completed for all steps.'
                    : 'All repair steps executed. Re-scan System Health and Accounting Health.';
            } elseif (isset($steps[$stepKey])) {
                $runResults = [$stepKey => sm_repair_run_step($conn, $stepKey, $companyId, $dryRun, $userId)];
                $runStep = $stepKey;
                $flashSuccess = $dryRun
                    ? 'Dry-run preview completed.'
                    : 'Repair step executed.';
            } else {
                $flashError = 'Unknown repair step.';
            }
        } catch (Throwable $e) {
            $flashError = $e->getMessage();
        }
    }
}

$previews = sm_repair_preview_all($conn, $companyId);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Live Data Repair</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{background:#f6f7f9}
  .hero{background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:18px 22px;margin-bottom:18px}
  .repair-card{border:0;border-radius:16px;background:#fff;box-shadow:0 10px 24px rgba(0,0,0,.06)}
  .table-sm td,.table-sm th{font-size:.86rem}
</style>
</head>
<body>
<div class="container-fluid my-4 px-4">
  <div class="hero d-flex align-items-center flex-wrap gap-3">
    <div>
      <div class="text-uppercase small text-muted">Service Management</div>
      <h3 class="mb-0">Live Data Repair</h3>
      <div class="text-muted">Batch-fix legacy accounting and WO issues. Always preview before execute on production.</div>
    </div>
    <div class="ms-auto d-flex flex-wrap gap-2">
      <a href="system_health_check.php" class="btn btn-outline-primary"><i class="bi bi-shield-check"></i> System Health</a>
      <a href="accounting_health.php" class="btn btn-outline-secondary"><i class="bi bi-heart-pulse"></i> GL Health</a>
      <a href="tools.php" class="btn btn-outline-secondary"><i class="bi bi-tools"></i> Tools</a>
    </div>
  </div>

  <div class="alert alert-danger">
    <strong><i class="bi bi-exclamation-triangle"></i> Production safety:</strong>
    Take a full MySQL backup before any Execute action. Repairs write to invoices, GL, work orders and expenses.
    See <a href="../docs/service_management/LIVE_DEPLOYMENT.md" target="_blank">Live Deployment runbook</a>.
  </div>

  <?php if ($flashError): ?>
    <div class="alert alert-danger"><?= h($flashError) ?></div>
  <?php endif; ?>
  <?php if ($flashSuccess): ?>
    <div class="alert alert-success"><?= h($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="card repair-card mb-4 p-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <form method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview_all">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i> Preview all steps</button>
      </form>
      <form method="post" class="d-inline" onsubmit="return confirm('Execute ALL repair steps in order? Ensure you have a DB backup.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="execute_all">
        <label class="form-check-label ms-2 me-2">
          <input type="checkbox" name="confirm_backup" value="1" class="form-check-input"> DB backup taken
        </label>
        <button type="submit" class="btn btn-danger"><i class="bi bi-lightning"></i> Execute all (ordered)</button>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach ($steps as $key => $meta): ?>
      <?php $preview = $previews[$key] ?? []; ?>
      <div class="col-xl-4 col-md-6">
        <div class="repair-card p-3 h-100 border-start border-4 border-primary">
          <div class="text-muted small">Step <?= (int)$meta['order'] ?></div>
          <h5 class="mb-1"><?= h($meta['title']) ?></h5>
          <p class="small text-muted mb-2"><?= h($meta['description']) ?></p>
          <div class="mb-3">
            <span class="badge bg-secondary">Eligible: <?= (int)($preview['eligible'] ?? 0) ?></span>
            <?php if ($key === 'wo_sync' && !empty($preview['unchanged'])): ?>
              <span class="badge bg-light text-dark border">Already matched: <?= (int)$preview['unchanged'] ?></span>
            <?php endif; ?>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="preview">
              <input type="hidden" name="step" value="<?= h($key) ?>">
              <button type="submit" class="btn btn-sm btn-outline-primary">Preview</button>
            </form>
            <form method="post" onsubmit="return confirm('Execute this repair step? Ensure you have a DB backup.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="execute">
              <input type="hidden" name="step" value="<?= h($key) ?>">
              <input type="hidden" name="confirm_backup" value="1">
              <button type="submit" class="btn btn-sm btn-danger">Execute</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (is_array($runResults)): ?>
    <div class="repair-card p-3 mb-4">
      <h5 class="mb-3">Results<?= $runStep === 'all' ? ' — all steps' : ($runStep ? ' — ' . h($steps[$runStep]['title'] ?? $runStep) : '') ?></h5>
      <?php foreach ($runResults as $stepKey => $result): ?>
        <?php if ($runStep === 'all'): ?>
          <h6 class="mt-3"><?= h($steps[$stepKey]['title'] ?? $stepKey) ?></h6>
        <?php endif; ?>
        <div class="small mb-2">
          Eligible: <strong><?= (int)($result['eligible'] ?? 0) ?></strong>
          <?php if (!$result['dry_run']): ?>
            · Fixed: <strong class="text-success"><?= (int)($result['fixed'] ?? 0) ?></strong>
            · Skipped: <?= (int)($result['skipped'] ?? 0) ?>
            · Unchanged: <?= (int)($result['unchanged'] ?? 0) ?>
            · Errors: <strong class="text-danger"><?= count($result['errors'] ?? []) ?></strong>
          <?php else: ?>
            · <span class="text-muted">Dry run — no changes written</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($result['errors'])): ?>
          <div class="alert alert-warning py-2 small">
            <?php foreach ($result['errors'] as $err): ?>
              <div><?= h($err) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($result['items'])): ?>
          <div class="table-responsive mb-3">
            <table class="table table-sm table-striped">
              <thead><tr><th>Record</th><th>Status</th><th>Detail</th></tr></thead>
              <tbody>
                <?php foreach (array_slice($result['items'], 0, 100) as $item): ?>
                  <tr>
                    <td><?= h($item['label'] ?? '') ?></td>
                    <td><span class="badge bg-<?=
                      ($item['status'] ?? '') === 'fixed' ? 'success' :
                      (($item['status'] ?? '') === 'error' ? 'danger' :
                      (($item['status'] ?? '') === 'skipped' ? 'secondary' : 'info'))
                    ?>"><?= h($item['status'] ?? '') ?></span></td>
                    <td class="small"><?= h($item['message'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php if (count($result['items']) > 100): ?>
              <div class="small text-muted">Showing first 100 of <?= count($result['items']) ?> rows.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="repair-card p-3">
    <h6>Manual review (not bulk auto-fixed)</h6>
    <ul class="small text-muted mb-0">
      <li>Unallocated receipts — allocate via receipt screen</li>
      <li>Completed WOs without invoice — finalize or create invoice manually</li>
      <li>Cancelled WO with active invoice — void invoice or credit note</li>
      <li>Legacy standalone invoices (no WO) — accepted baseline; excluded from critical health counts</li>
    </ul>
  </div>
</div>
</body>
</html>
