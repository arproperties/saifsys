<!-- Print / on-screen shop list -->
<div class="card card-round mb-3 print-shops" id="contract-overview">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong class="shop-section-title">Contract Shops</strong>
        <span class="text-muted small">Primary: <?= h($contract['shop_number']) ?></span>
    </div>
    <div class="card-body py-2">
        <ul class="mb-0">
            <?php foreach ($contractShops as $s): ?>
                <li>
                    <strong><?= h($s['shop_number']) ?></strong>
                    <?= !empty($s['shop_name']) ? ' — ' . h($s['shop_name']) : '' ?>
                    <?php if (!empty($s['is_primary'])): ?><span class="badge bg-primary">Primary</span><?php endif; ?>
                    <span class="badge bg-light text-dark border"><?= h($s['unit_status'] ?? '') ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (!$contractShops): ?><li class="text-muted">No shops linked.</li><?php endif; ?>
        </ul>
    </div>
</div>
