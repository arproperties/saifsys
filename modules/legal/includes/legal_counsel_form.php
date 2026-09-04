<?php $v = fn($k, $d='') => h($_POST[$k] ?? $counsel[$k] ?? $d); ?>
<div class="d-flex justify-content-between mb-4">
    <h1><i class="bi bi-person-badge"></i> <?= isset($counsel['id']) ? 'Edit' : 'Add' ?> External Counsel</h1>
    <a href="legal_counsel.php" class="btn btn-secondary">Back</a>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<form method="POST" class="card"><div class="card-body">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required value="<?= $v('name') ?>"></div>
        <div class="col-md-4"><label class="form-label">Type</label><select name="counsel_type" class="form-select"><?php foreach (legal_counsel_types() as $k=>$lbl): ?><option value="<?= h($k) ?>" <?= ($counsel['counsel_type']??'firm')===$k?'selected':'' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control" value="<?= $v('contact_person') ?>"></div>
        <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= $v('email') ?>"></div>
        <div class="col-md-4"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= $v('phone') ?>"></div>
        <div class="col-md-4"><label class="form-label">License No.</label><input type="text" name="license_number" class="form-control" value="<?= $v('license_number') ?>"></div>
        <div class="col-md-4"><label class="form-label">Specialization</label><input type="text" name="specialization" class="form-control" value="<?= $v('specialization') ?>"></div>
        <div class="col-md-4"><label class="form-label">Hourly Rate (AED)</label><input type="number" step="0.01" name="hourly_rate" class="form-control" value="<?= $v('hourly_rate') ?>"></div>
        <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= $v('notes') ?></textarea></div>
        <?php if (isset($counsel['id'])): ?><div class="col-12"><div class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" id="isActive" <?= !empty($counsel['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="isActive">Active</label></div></div><?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary mt-3">Save</button>
</div></form>
