<?php if ($phase175Ready): ?>
<div class="card card-round mb-3 no-print">
    <div class="card-header bg-white"><strong class="shop-section-title">Edit Shops &amp; Dates</strong></div>
    <div class="card-body">
        <form method="post" id="editShopsForm"><?php csrf_field(); ?><input type="hidden" name="action" value="update_shops_dates">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Start</label><input type="date" name="start_date" class="form-control" value="<?= h($contract['start_date']) ?>" required></div>
                <div class="col-md-3"><label class="form-label">End</label><input type="date" name="end_date" class="form-control" value="<?= h($contract['end_date']) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Primary Shop</label>
                    <select name="primary_shop_unit_id" id="editPrimaryShop" class="form-select">
                        <?php foreach ($contractShops as $s): ?>
                            <option value="<?= (int)$s['shop_unit_id'] ?>" <?= (int)$s['shop_unit_id'] === $primaryShopId ? 'selected' : '' ?>><?= h($s['shop_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Shops</label>
                    <div class="row g-2">
                        <?php foreach ($allShops as $shop):
                            $sid = (int)$shop['id'];
                        ?>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input edit-shop-check" type="checkbox" name="shop_unit_ids[]" value="<?= $sid ?>" id="eshop<?= $sid ?>" <?= in_array($sid, $linkedShopIds, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="eshop<?= $sid ?>"><?= h($shop['shop_number']) ?> <span class="text-muted small"><?= h($shop['status']) ?></span></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12"><label class="form-label">Amendment reason</label><input type="text" name="amendment_reason" class="form-control form-control-sm" placeholder="<?= ($contract['status'] ?? '') === 'active' ? 'Required for active contract changes' : 'Optional for draft' ?>" <?= ($contract['status'] ?? '') === 'active' ? 'required' : '' ?>></div>
                <div class="col-12"><button class="btn btn-primary btn-sm">Save Shops &amp; Dates</button></div>
            </div>
        </form>
    </div>
</div>
<script>
(function(){
  const checks=document.querySelectorAll('.edit-shop-check');
  const primary=document.getElementById('editPrimaryShop');
  function refresh(){
    const prev=primary.value; primary.innerHTML='';
    checks.forEach(c=>{ if(!c.checked) return; const o=document.createElement('option'); o.value=c.value; o.textContent=c.parentElement.querySelector('label').childNodes[0].textContent.trim(); if(c.value===prev) o.selected=true; primary.appendChild(o); });
    if(primary.options.length && ![...primary.options].some(o=>o.selected)) primary.options[0].selected=true;
  }
  checks.forEach(c=>c.addEventListener('change', refresh));
})();
</script>
<?php endif; ?>
