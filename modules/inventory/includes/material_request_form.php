<?php
/**
 * Shared material request form (modern layout, dynamic lines).
 * Expects: $formVal callable, $items, $uoms, $companyId, $brand (optional),
 */
if (!function_exists('inv_material_request_context_field_titles')) {
    require_once dirname(__DIR__, 3) . '/includes/inventory/inv_request_create_helpers.php';
}

$showSourceReference = $showSourceReference ?? true;
$myMaterialRequestsUrl = $myMaterialRequestsUrl ?? null;
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
$titles = inv_material_request_context_field_titles();
$allowManualContext = ($embedMode ?? 'inventory') === 'inventory';
$initialRows = max(1, (int)($initialLineRows ?? 4));
?>
<style>
  .inv-mr-hero {
    background: linear-gradient(135deg, var(--primary, #6b2d5b) 0%, var(--primary-light, #8e4a7a) 100%);
    color: #fff;
    border-radius: 16px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.25rem;
    box-shadow: 0 12px 40px rgba(0,0,0,.12);
  }
  .inv-mr-hero h1 { font-size: 1.35rem; font-weight: 700; margin: 0; letter-spacing: -0.02em; }
  .inv-mr-hero p { margin: .35rem 0 0; opacity: .9; font-size: .875rem; }
  .inv-mr-card {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0,0,0,.06);
    background: #fff;
    overflow: hidden;
  }
  .inv-mr-card .card-h {
    font-weight: 600;
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #64748b;
    padding: 1rem 1.25rem .5rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: .5rem;
  }
  .inv-mr-card .card-b { padding: 1rem 1.25rem 1.25rem; }
  .inv-mr-ctx-pill {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: .75rem 1rem;
    height: 100%;
  }
  .inv-mr-ctx-pill .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; margin-bottom: .25rem; }
  .inv-mr-ctx-pill .val { font-weight: 600; color: #0f172a; font-size: .95rem; }
  .inv-mr-lines-wrap { border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; }
  .inv-mr-lines-wrap thead th {
    font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b;
    background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 600; padding: .65rem .75rem;
  }
  .inv-mr-lines-wrap tbody td { padding: .5rem .75rem; vertical-align: middle; border-color: #f1f5f9; }
  .inv-mr-lines-wrap .btn-icon { width: 2rem; height: 2rem; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
  #invMrLineTemplate { display: none !important; }
</style>

<div class="inv-mr-hero d-flex flex-wrap justify-content-between align-items-start gap-3">
  <div>
    <h1><i class="bi bi-box-seam me-2"></i>New material request</h1>
    <p>Stock is issued only after approval. Add lines below — use <strong>Add line</strong> as needed.</p>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <?php if (!empty($myMaterialRequestsUrl)): ?>
      <a class="btn btn-outline-light btn-sm shadow-sm" href="<?= h($myMaterialRequestsUrl) ?>"><i class="bi bi-list-ul me-1"></i>My material requests</a>
    <?php endif; ?>
    <a class="btn btn-light btn-sm shadow-sm" href="<?= h($backUrl) ?>"><i class="bi bi-arrow-left me-1"></i><?= h($backLabel) ?></a>
  </div>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-<?= ($messageType ?? '') === 'success' ? 'success' : 'warning' ?> border-0 shadow-sm rounded-3"><?= h($message) ?></div>
<?php endif; ?>

<?php if (!$companyId): ?>
  <div class="alert alert-warning rounded-3 border-0">Select a company first.</div>
<?php else: ?>

<?php if (!empty($fromIntegration)): ?>
  <div class="alert alert-info border-0 rounded-3 shadow-sm py-2 mb-3">
    <i class="bi bi-link-45deg me-1"></i> Linked to <strong><?= h($prefillModule ?? '') ?></strong>. Complete location and lines, then submit.
  </div>
<?php endif; ?>

<form method="POST" id="invMrForm" class="inv-mr-form">
  <?php csrf_field(); ?>

  <div class="inv-mr-card mb-3">
    <div class="card-h"><i class="bi bi-calendar3"></i> Request details</div>
    <div class="card-b">
      <p class="small text-muted mb-3 mb-md-2">Stock location is chosen by inventory when the request is approved, not here.</p>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label small text-muted">Request date</label>
          <input class="form-control rounded-3" type="date" name="request_date" value="<?= h($formVal('request_date', date('Y-m-d'))) ?>">
        </div>
        <?php if (!empty($showSourceModulePicker)): ?>
        <div class="col-md-3">
          <label class="form-label small text-muted">Source module</label>
          <select class="form-select rounded-3" name="source_module">
            <?php
            $mods = ['inventory' => 'Inventory (internal)', 'cleaning' => 'Cleaning', 'realestate' => 'Real Estate', 'construction' => 'Construction', 'ars' => 'ARS'];
            $sel = strtolower(trim($formVal('source_module', 'inventory')));
            foreach ($mods as $k => $lab):
            ?>
              <option value="<?= h($k) ?>" <?= $sel === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
          <input type="hidden" name="source_module" value="<?= h($formVal('source_module', $prefillModule ?? 'inventory')) ?>">
        <?php endif; ?>
        <?php if (!empty($showSourceReference)): ?>
        <div class="col-md-5">
          <label class="form-label small text-muted">Source reference (optional)</label>
          <div class="input-group">
            <span class="input-group-text rounded-start-3 bg-light border-end-0 small">Table</span>
            <input class="form-control" name="source_table" placeholder="e.g. re_maintenance_requests" value="<?= h($formVal('source_table')) ?>">
            <span class="input-group-text bg-light small">ID</span>
            <input class="form-control rounded-end-3" name="source_id" placeholder="#" value="<?= h($formVal('source_id')) ?>">
          </div>
        </div>
        <?php else: ?>
          <input type="hidden" name="source_table" value="<?= h($formVal('source_table')) ?>">
          <input type="hidden" name="source_id" value="<?= h($formVal('source_id')) ?>">
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!empty($visibleContextKeys)): ?>
  <div class="inv-mr-card mb-3">
    <div class="card-h"><i class="bi bi-geo-alt"></i> Operational context</div>
    <div class="card-b">
      <?php if (!empty($contextHelp)): ?>
        <p class="small text-muted mb-3"><?= h($contextHelp) ?></p>
      <?php endif; ?>
      <div class="row g-3">
        <?php foreach ($visibleContextKeys as $fname):
          if (!isset($titles[$fname])) {
              continue;
          }
          $tit = $titles[$fname];
          $raw = trim($formVal($fname));
          if ($allowManualContext): ?>
            <div class="col-md-4 col-lg-3">
              <label class="form-label small text-muted"><?= h($tit) ?></label>
              <?php if (!empty($contextLabels[$fname]) && $raw !== ''): ?>
                <div class="small text-primary mb-1"><?= h($contextLabels[$fname]) ?></div>
              <?php endif; ?>
              <input class="form-control form-control-sm rounded-3" type="number" name="<?= h($fname) ?>" value="<?= h($raw) ?>" placeholder="Optional">
            </div>
          <?php
          continue;
          endif;
          if ($raw === '') {
              continue;
          }
          $disp = !empty($contextLabels[$fname]) ? $contextLabels[$fname] : ('#' . $raw);
          ?>
          <div class="col-md-4 col-lg-3">
            <div class="inv-mr-ctx-pill">
              <div class="lbl"><?= h($tit) ?></div>
              <div class="val"><?= h($disp) ?></div>
            </div>
            <input type="hidden" name="<?= h($fname) ?>" value="<?= h($raw) ?>">
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="inv-mr-card mb-3">
    <div class="card-h"><i class="bi bi-chat-left-text"></i> Notes</div>
    <div class="card-b">
      <textarea class="form-control rounded-3" name="notes" rows="2" placeholder="Optional context for approvers…"><?= h($formVal('notes')) ?></textarea>
    </div>
  </div>

  <div class="inv-mr-card mb-4">
    <div class="card-h d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><i class="bi bi-list-ul"></i> Lines</span>
      <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" id="invMrAddLine"><i class="bi bi-plus-lg"></i> Add line</button>
    </div>
    <div class="card-b p-0">
      <div class="inv-mr-lines-wrap">
        <table class="table table-hover align-middle mb-0" id="invMrLinesTable">
          <thead>
            <tr>
              <th>Item</th>
              <th style="width:110px">UoM</th>
              <th style="width:100px">Qty</th>
              <th style="width:110px">Lot</th>
              <th style="width:130px">Expiry</th>
              <th style="width:110px">Serial</th>
              <th style="width:52px"></th>
            </tr>
          </thead>
          <tbody id="invMrLinesBody">
            <?php
            $itemOpts = '';
            foreach ($items as $it) {
                $itemOpts .= '<option value="' . (int)$it['id'] . '">' . h($it['item_code'] . ' — ' . $it['name']) . '</option>';
            }
            $uomOpts = '<option value="">Base</option>';
            foreach ($uoms as $u) {
                $uomOpts .= '<option value="' . (int)$u['id'] . '">' . h($u['code']) . '</option>';
            }
            for ($r = 0; $r < $initialRows; $r++):
            ?>
            <tr class="inv-mr-line">
              <td>
                <select class="form-select form-select-sm rounded-3" name="line_item_id[]">
                  <option value="">— Select item —</option>
                  <?= $itemOpts ?>
                </select>
              </td>
              <td><select class="form-select form-select-sm rounded-3" name="line_uom_id[]"><?= $uomOpts ?></select></td>
              <td><input class="form-control form-control-sm rounded-3" type="number" step="any" min="0" name="line_qty[]" value=""></td>
              <td><input class="form-control form-control-sm rounded-3" name="line_lot[]" value=""></td>
              <td><input class="form-control form-control-sm rounded-3" type="date" name="line_expiry[]" value=""></td>
              <td><input class="form-control form-control-sm rounded-3" name="line_serial[]" value=""></td>
              <td class="text-end">
                <button type="button" class="btn btn-outline-danger btn-sm inv-mr-rm btn-icon" title="Remove line"><i class="bi bi-x-lg"></i></button>
              </td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <template id="invMrLineTemplate">
    <tr class="inv-mr-line">
      <td>
        <select class="form-select form-select-sm rounded-3" name="line_item_id[]">
          <option value="">— Select item —</option>
          <?= $itemOpts ?>
        </select>
      </td>
      <td><select class="form-select form-select-sm rounded-3" name="line_uom_id[]"><?= $uomOpts ?></select></td>
      <td><input class="form-control form-control-sm rounded-3" type="number" step="any" min="0" name="line_qty[]" value=""></td>
      <td><input class="form-control form-control-sm rounded-3" name="line_lot[]" value=""></td>
      <td><input class="form-control form-control-sm rounded-3" type="date" name="line_expiry[]" value=""></td>
      <td><input class="form-control form-control-sm rounded-3" name="line_serial[]" value=""></td>
      <td class="text-end">
        <button type="button" class="btn btn-outline-danger btn-sm inv-mr-rm btn-icon" title="Remove line"><i class="bi bi-x-lg"></i></button>
      </td>
    </tr>
  </template>

  <div class="d-flex gap-2 flex-wrap">
    <button type="submit" class="btn btn-primary btn-lg rounded-pill px-4 shadow-sm"><i class="bi bi-send me-2"></i>Submit request</button>
    <a class="btn btn-outline-secondary btn-lg rounded-pill" href="<?= h($backUrl) ?>">Cancel</a>
  </div>
</form>

<script>
(function () {
  const body = document.getElementById('invMrLinesBody');
  const tpl = document.getElementById('invMrLineTemplate');
  const addBtn = document.getElementById('invMrAddLine');
  if (!body || !tpl || !addBtn) return;

  function bindRow(tr) {
    tr.querySelectorAll('.inv-mr-rm').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (body.querySelectorAll('tr.inv-mr-line').length <= 1) return;
        tr.remove();
      });
    });
  }
  body.querySelectorAll('tr.inv-mr-line').forEach(bindRow);

  addBtn.addEventListener('click', function () {
    const node = tpl.content.cloneNode(true);
    const tr = node.querySelector('tr');
    body.appendChild(tr);
    bindRow(tr);
  });
})();
</script>

<?php endif; ?>
