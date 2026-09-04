<?php
/**
 * One-time / admin: backfill GL journals for receipts where gl_journal_id IS NULL
 * and no gl_journals row exists yet for source='receipt' + source_id.
 *
 * Modes:
 *   - Dry run (default): lists eligible receipts only — no writes.
 *   - Execute: POST with CSRF + confirm — processes each eligible receipt in its own transaction.
 *
 * Cleaning module / shared includes only — not real-estate accounting.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/gl_posting.php';

require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Receipts eligible: gl_journal_id IS NULL and no existing receipt journal for this id.
 *
 * @return list<array<string,mixed>>
 */
function receipt_gl_backfill_eligible(PDO $conn): array {
    $st = $conn->query("
        SELECT r.id, r.receipt_no, r.receipt_date, r.method, r.amount, r.client_id, r.gl_journal_id
        FROM receipts r
        WHERE r.gl_journal_id IS NULL
        ORDER BY r.id ASC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $rid = (int) $r['id'];
        $chk = $conn->prepare("SELECT id FROM gl_journals WHERE source = 'receipt' AND source_id = ? LIMIT 1");
        $chk->execute([$rid]);
        if ($chk->fetch()) {
            continue;
        }
        $m = strtolower(trim((string) ($r['method'] ?? '')));
        $r['_bank_account_no'] = ($m === 'cash') ? '1010' : '1020';
        $out[] = $r;
    }
    return $out;
}

$executeRequested = ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['execute_repair']));
$results = [];
$eligible = receipt_gl_backfill_eligible($conn);

if ($executeRequested) {
    csrf_verify();
    if (empty($_POST['confirm_repair'])) {
        $results['error'] = 'You must confirm to run the live repair.';
    } elseif (!$eligible) {
        $results['error'] = 'No eligible receipts — nothing to execute.';
    } else {
        foreach ($eligible as $row) {
            $rid = (int) $row['id'];
            $bankNo = $row['_bank_account_no'];
            try {
                $conn->beginTransaction();

                $lock = $conn->prepare('SELECT id, receipt_no, method, amount, gl_journal_id FROM receipts WHERE id = ? FOR UPDATE');
                $lock->execute([$rid]);
                $live = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$live) {
                    throw new RuntimeException("Receipt {$rid} not found after lock.");
                }
                if ($live['gl_journal_id'] != null && (string) $live['gl_journal_id'] !== '') {
                    $conn->rollBack();
                    $results['rows'][] = ['id' => $rid, 'status' => 'skipped', 'detail' => 'gl_journal_id already set (race or fixed).'];
                    continue;
                }

                $dup = $conn->prepare("SELECT id FROM gl_journals WHERE source = 'receipt' AND source_id = ? LIMIT 1");
                $dup->execute([$rid]);
                if ($dup->fetch()) {
                    $conn->rollBack();
                    $results['rows'][] = ['id' => $rid, 'status' => 'skipped', 'detail' => 'gl_journals row already exists for this receipt — not posting duplicate.'];
                    continue;
                }

                $jid = gl_post_receipt($conn, $rid, $bankNo);

                $up = $conn->prepare('UPDATE receipts SET gl_journal_id = ?, posted_at = NOW() WHERE id = ? AND gl_journal_id IS NULL');
                $up->execute([$jid, $rid]);
                if ($up->rowCount() !== 1) {
                    throw new RuntimeException("UPDATE receipts did not affect exactly one row for receipt {$rid}.");
                }

                $conn->commit();
                $results['rows'][] = ['id' => $rid, 'status' => 'ok', 'journal_id' => $jid, 'bank' => $bankNo];
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $results['rows'][] = ['id' => $rid, 'status' => 'error', 'detail' => $e->getMessage()];
                error_log('repair_receipt_gl_backfill: ' . $e->getMessage());
            }
        }
        $results['executed'] = true;
    }
}

$pageTitle = $executeRequested && empty($results['error']) && !empty($results['executed'])
    ? 'Receipt GL repair — completed'
    : 'Receipt GL repair — dry run';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background: #f6f7f9; }
    .hero { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,.06); padding: 1rem 1.25rem; margin-bottom: 1rem; }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="hero">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div>
        <div class="text-uppercase small text-muted">Admin tool</div>
        <h4 class="mb-0">Receipt GL backfill</h4>
        <div class="text-muted small">Dry run lists candidates; execute posts via <code>gl_post_receipt()</code> only when safe.</div>
      </div>
      <div class="ms-auto d-flex gap-2">
        <a href="gl_exceptions_review.php" class="btn btn-outline-secondary btn-sm">GL exceptions review</a>
        <a href="reports.php" class="btn btn-outline-secondary btn-sm">Reports</a>
      </div>
    </div>
  </div>

  <?php if (!empty($results['error'])): ?>
    <div class="alert alert-warning"><?= h($results['error']) ?></div>
  <?php endif; ?>

  <?php if (!empty($results['executed']) && empty($results['error'])): ?>
    <div class="alert alert-success">
      Repair run finished. Review per-receipt results below. Re-run <a href="gl_exceptions_review.php">GL exceptions review</a> to confirm zero NULL journals.
    </div>
    <?php if (!empty($results['rows'])): ?>
      <div class="table-responsive bg-white rounded shadow-sm">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Receipt ID</th><th>Status</th><th>Detail</th></tr></thead>
          <tbody>
            <?php foreach ($results['rows'] as $rr): ?>
              <tr>
                <td><?= (int) $rr['id'] ?></td>
                <td><?= h($rr['status'] ?? '') ?></td>
                <td><?= h(($rr['status'] ?? '') === 'ok'
                    ? ('journal_id=' . (int) ($rr['journal_id'] ?? 0) . ', bank=' . ($rr['bank'] ?? ''))
                    : (string) ($rr['detail'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <p class="mt-3"><a href="repair_receipt_gl_backfill.php" class="btn btn-primary">Back to dry run</a></p>
  <?php else: ?>

    <div class="alert alert-info small mb-3">
      <strong>Dry run.</strong> No database changes are made on this page load. Eligible = <code>gl_journal_id IS NULL</code>
      and no <code>gl_journals</code> row with <code>source='receipt'</code> and <code>source_id=receipt.id</code>.
      Cash → <code>1010</code>, other methods → <code>1020</code> (same as current payment logic until Phase 2).
    </div>

    <p class="fw-semibold">Eligible receipts: <span class="badge bg-secondary"><?= count($eligible) ?></span></p>

    <?php if (!$eligible): ?>
      <p class="text-muted">None. Nothing to repair.</p>
    <?php else: ?>
      <div class="table-responsive bg-white rounded shadow-sm mb-4">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th>ID</th><th>Receipt #</th><th>Date</th><th>Method</th><th>GL bank a/c</th><th>Amount</th><th>Client ID</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($eligible as $e): ?>
              <tr>
                <td><?= (int) $e['id'] ?></td>
                <td><?= h($e['receipt_no']) ?></td>
                <td><?= h($e['receipt_date']) ?></td>
                <td><?= h($e['method']) ?></td>
                <td><code><?= h($e['_bank_account_no']) ?></code></td>
                <td><?= h(number_format((float) $e['amount'], 2)) ?></td>
                <td><?= (int) $e['client_id'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card border-danger">
        <div class="card-header bg-danger text-white">Live repair (writes database)</div>
        <div class="card-body">
          <p class="small text-muted mb-2">Run only after reviewing the table above on the <strong>live</strong> server. Each receipt is fixed in its own transaction.</p>
          <form method="post" action="">
            <?php csrf_field(); ?>
            <input type="hidden" name="execute_repair" value="1">
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="confirm_repair" value="1" id="confirm_repair" required>
              <label class="form-check-label" for="confirm_repair">I confirm I want to post GL journals and set <code>receipts.gl_journal_id</code> for the listed rows only.</label>
            </div>
            <button type="submit" class="btn btn-danger"><i class="bi bi-lightning-charge"></i> Execute repair</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>
</body>
</html>
