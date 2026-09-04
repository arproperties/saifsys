<?php
/**
 * Expects: $req, $lines, $labels, $titles, $locationLabel (string), $inventoryViewUrl (string|null)
 */
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
$locationLabel = $locationLabel ?? '';
$inventoryIssueDocUrl = $inventoryIssueDocUrl ?? null;
?>
<div class="card p-3 mb-3">
  <div class="row">
    <div class="col-md-6">
      <div class="small text-muted">Issue from location</div>
      <div class="fw-semibold">
        <?php if ($locationLabel !== ''): ?>
          <?= h($locationLabel) ?>
        <?php elseif (!empty($req['location_from_id'])): ?>
          <?= h((string)(int)$req['location_from_id']) ?>
        <?php else: ?>
          — <span class="text-muted small fw-normal">(set at approval)</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-md-6">
      <div class="small text-muted">Requested by</div>
      <div><?php
        $rb = trim(($req['requested_fullname'] ?? '') . ' (' . ($req['requested_username'] ?? '') . ')');
        echo h($rb === '()' ? '—' : $rb);
      ?></div>
    </div>
  </div>
  <?php if (!empty($req['notes'])): ?>
    <div class="mt-2"><span class="text-muted small">Notes</span><div><?= nl2br(h($req['notes'])) ?></div></div>
  <?php endif; ?>

  <div class="mt-3">
    <div class="small text-muted mb-1">Operational context</div>
    <div class="row g-2 small">
      <?php
      $anyCtx = false;
      foreach ($titles as $key => $title) {
          if (empty($labels[$key])) {
              continue;
          }
          $anyCtx = true;
          ?>
          <div class="col-md-6"><span class="text-muted"><?= h($title) ?>:</span> <?= h((string)$labels[$key]) ?></div>
          <?php
      }
      if (!$anyCtx) {
          echo '<div class="col-12 text-muted">—</div>';
      }
      ?>
    </div>
  </div>

  <?php if (!empty($req['issue_doc_id'])): ?>
    <div class="mt-2">
      <?php if (!empty($inventoryIssueDocUrl)): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= h($inventoryIssueDocUrl) ?>">Open issue document #<?= (int)$req['issue_doc_id'] ?></a>
      <?php else: ?>
        <span class="small text-muted">Issue document #<?= (int)$req['issue_doc_id'] ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (($req['status'] ?? '') === 'rejected' && !empty($req['rejection_reason'])): ?>
    <div class="alert alert-light border mt-3 mb-0"><strong>Rejection:</strong> <?= nl2br(h($req['rejection_reason'])) ?></div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Lines</div>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>Item</th>
          <th>UoM</th>
          <th>Requested</th>
          <th>Approved</th>
          <th>Lot / serial</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $ln): ?>
          <tr>
            <td><?= h(($ln['item_code'] ?? '') . ' — ' . ($ln['item_name'] ?? '')) ?></td>
            <td><?= h($ln['uom_code'] ?? '—') ?></td>
            <td><?= h((string)$ln['requested_qty']) ?></td>
            <td><?= isset($ln['approved_qty']) && $ln['approved_qty'] !== null && $ln['approved_qty'] !== '' ? h((string)$ln['approved_qty']) : '—' ?></td>
            <td class="small"><?= h(trim(($ln['lot_number'] ?? '') . ' ' . ($ln['serial_number'] ?? ''))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
