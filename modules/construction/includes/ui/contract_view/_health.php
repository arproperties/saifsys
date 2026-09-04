<div class="card card-round h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong class="shop-section-title">Contract Health Check</strong>
                <span class="badge bg-<?= $health['overall'] === 'success' ? 'success' : ($health['overall'] === 'warning' ? 'warning text-dark' : 'danger') ?>">
                    <?= (int)$health['score_ok'] ?> ok · <?= (int)$health['score_warn'] ?> warn · <?= (int)$health['score_err'] ?> err
                </span>
            </div>
            <div class="card-body py-2">
                <?php foreach ($health['checks'] as $ch):
                    $st = (string)($ch['status'] ?? 'secondary');
                ?>
                    <div class="shop-health-item status-<?= h($st) ?>">
                        <span class="shop-dot <?= h($st) ?>" title="<?= h(ucfirst($st)) ?>"></span>
                        <div>
                            <div class="fw-semibold small"><?= h($ch['label']) ?></div>
                            <?php if ($ch['detail']): ?><div class="text-muted" style="font-size:.78rem"><?= h($ch['detail']) ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$health['checks']): ?><div class="text-muted small py-2">Health checks require Phase 1 schema.</div><?php endif; ?>
            </div>
        </div>
