<?php if ($timeline): ?>
<div class="card card-round mb-4" id="contract-timeline"><div class="card-header bg-white"><strong class="shop-section-title">Contract Audit Timeline</strong></div><div class="card-body">
    <div class="shop-timeline">
        <?php foreach ($timeline as $ev):
            $tone = $ev['tone'] ?? 'secondary';
        ?>
            <div class="shop-tl-item tone-<?= h($tone) ?>">
                <div class="d-flex justify-content-between gap-2 flex-wrap">
                    <div>
                        <div class="fw-semibold"><?= h($ev['label']) ?></div>
                        <div class="small text-muted"><?= h($ev['detail']) ?></div>
                        <div class="small mt-1">
                            <span class="text-muted">User:</span> <?= h($ev['user'] ?? '—') ?>
                            <?php if (!empty($ev['document'])): ?>
                                · <span class="text-muted">Doc:</span>
                                <?php if (!empty($ev['document_url'])): ?>
                                    <a href="<?= h($ev['document_url']) ?>" <?= (strpos((string)$ev['document_url'], '#') === 0) ? '' : 'target="_blank"' ?>><?= h($ev['document']) ?></a>
                                <?php else: ?>
                                    <?= h($ev['document']) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="badge bg-light text-dark border"><?= h($ev['date']) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div></div>
<?php endif; ?>
