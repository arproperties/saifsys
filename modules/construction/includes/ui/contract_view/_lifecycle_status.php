<div class="card card-round mb-3 no-print" id="status-occupancy">
    <div class="card-header bg-white"><strong class="shop-section-title">Lifecycle Actions</strong></div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <?php if ($contract['status'] === 'draft'): ?>
                <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="active"><button class="btn btn-sm btn-success" <?= $phase175Ready ? '' : 'disabled' ?>><?= !empty($contract['parent_contract_id']) ? 'Activate Renewal' : 'Activate Contract' ?></button></form>
            <?php endif; ?>
            <?php if ($contract['status'] === 'active' && co_shop_phase2a_schema_ready($conn)): ?>
                <a class="btn btn-sm btn-outline-primary" href="shop_rental_renew.php?id=<?= $id ?>">Renew</a>
                <a class="btn btn-sm btn-outline-warning" href="shop_rental_terminate.php?id=<?= $id ?>">Early Termination</a>
                <a class="btn btn-sm btn-outline-secondary" href="shop_rental_move_out_inspection.php?id=<?= $id ?>">Move-Out Inspection</a>
                <a class="btn btn-sm btn-outline-secondary" href="shop_rental_deposit_settle.php?id=<?= $id ?>">Settle Deposit</a>
            <?php endif; ?>
            <?php if ($contract['status'] === 'active'): ?>
                <form method="post" onsubmit="return confirm('Mark this contract expired and release shops if unused?');"><?php csrf_field(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="expired"><button class="btn btn-sm btn-warning" <?= $phase175Ready ? '' : 'disabled' ?>>Mark Expired</button></form>
            <?php endif; ?>
            <?php if (in_array($contract['status'], ['expired', 'terminated', 'renewed'], true) && co_shop_phase2a_schema_ready($conn)): ?>
                <form method="post" onsubmit="return confirm('Archive this contract?');"><?php csrf_field(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="archived"><button class="btn btn-sm btn-outline-dark">Archive</button></form>
            <?php endif; ?>
            <?php if (in_array($contract['status'], ['expired'], true)): ?>
                <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="active"><button class="btn btn-sm btn-outline-success" <?= $phase175Ready ? '' : 'disabled' ?>>Re-activate</button></form>
            <?php endif; ?>
        </div>
        <p class="text-muted small mb-0">
            Lifecycle: Draft → Active → Renewed / Expired / Terminated → Archived.
            <?php if (!empty($contract['parent_contract_id'])): ?>
                Renewal of <a href="shop_rental_contract_view.php?id=<?= (int)$contract['parent_contract_id'] ?>">#<?= (int)$contract['parent_contract_id'] ?></a>.
            <?php endif; ?>
            <?php if (!empty($contract['renewed_to_contract_id'])): ?>
                Renewed to <a href="shop_rental_contract_view.php?id=<?= (int)$contract['renewed_to_contract_id'] ?>">#<?= (int)$contract['renewed_to_contract_id'] ?></a>.
            <?php endif; ?>
            Shops occupy only while Active. Activate Renewal marks the parent <strong>Renewed</strong>.
        </p>
    </div>
</div>
