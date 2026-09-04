<?php
/**
 * Expects: $rows, $conn, $companyId, $detailPage (e.g. material_request_view.php), $showRequestedBy (bool)
 */
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
require_once dirname(__DIR__) . '/inv_material_requests_for_modules.php';
$showRequestedBy = $showRequestedBy ?? false;
$colspan = 5 + (!empty($showRequestedBy) ? 1 : 0);
?>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead>
      <tr>
        <th>Request</th>
        <th>Date</th>
        <th>Status</th>
        <th>Context</th>
        <?php if (!empty($showRequestedBy)): ?><th>Requested by</th><?php endif; ?>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= (int)$colspan ?>" class="text-muted">No material requests yet.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
          $sum = inv_material_request_list_summary_line($conn, (int)$companyId, $r);
          $st = inv_material_request_status_badge_class((string)($r['status'] ?? ''));
          ?>
          <tr>
            <td class="fw-semibold"><?= h($r['request_no'] ?? '') ?></td>
            <td><?= h($r['request_date'] ?? '') ?></td>
            <td><span class="badge bg-<?= h($st) ?>"><?= h($r['status'] ?? '') ?></span></td>
            <td class="small"><?= h($sum) ?></td>
            <?php if (!empty($showRequestedBy)): ?>
              <td class="small"><?= h($r['requested_username'] ?? '—') ?></td>
            <?php endif; ?>
            <td><a class="btn btn-sm btn-outline-primary" href="<?= h($detailPage) ?>?id=<?= (int)$r['id'] ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
