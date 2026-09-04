<div class="row g-3 mb-3">
    <!-- Health -->
    <div class="col-lg-4">
        <div class="card card-round h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong class="shop-section-title">Contract Health Check</strong>
                <span class="badge bg-<?= $health['overall'] === 'success' ? 'success' : ($health['overall'] === 'warning' ? 'warning text-dark' : 'danger') ?>">
                    <?= (int)$health['score_ok'] ?> ok · <?= (int)$health['score_warn'] ?> warn · <?= (int)$health['score_err'] ?> err
                </span>
            </div>
            <div class="card-body py-2">
                <?php foreach ($health['checks'] as $ch): ?>
                    <div class="shop-health-item">
                        <span class="shop-dot <?= h($ch['status']) ?>"></span>
                        <div>
                            <div class="fw-semibold small"><?= h($ch['label']) ?></div>
                            <?php if ($ch['detail']): ?><div class="text-muted" style="font-size:.78rem"><?= h($ch['detail']) ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$health['checks']): ?><div class="text-muted small py-2">Health checks require Phase 1 schema.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="col-lg-4 no-print">
        <div class="card card-round h-100">
            <div class="card-header bg-white"><strong class="shop-section-title">Quick Actions</strong></div>
            <div class="card-body">
                <div class="d-grid gap-2">
                <?php foreach ($quickActions as $qa):
                    if (!$qa['enabled']) {
                        continue;
                    }
                    if ($qa['type'] === 'form'): ?>
                        <form method="post"><?php csrf_field(); ?>
                            <input type="hidden" name="action" value="<?= h($qa['action']) ?>">
                            <?php if (($qa['action'] ?? '') === 'generate_cheques_quick'): ?>
                                <input type="hidden" name="rent_cheque_count" value="<?= (int)($contract['rent_cheque_count'] ?? 0) ?>">
                                <input type="hidden" name="deposit_cheque_count" value="<?= (int)($contract['deposit_cheque_count'] ?? 0) ?>">
                            <?php endif; ?>
                            <button class="btn btn-outline-primary btn-sm w-100 text-start">
                                <i class="bi bi-lightning-charge me-1"></i><?= h($qa['label']) ?>
                                <?php if (!empty($qa['hint'])): ?><div class="text-muted fw-normal" style="font-size:.72rem"><?= h($qa['hint']) ?></div><?php endif; ?>
                            </button>
                        </form>
                    <?php elseif ($qa['type'] === 'print'): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100 text-start" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i><?= h($qa['label']) ?>
                        </button>
                    <?php else:
                        $href = $qa['href'] ?? '#';
                        $tag = ($qa['type'] === 'link') ? 'a' : 'a';
                    ?>
                        <a href="<?= h($href) ?>" class="btn btn-outline-secondary btn-sm w-100 text-start">
                            <i class="bi bi-box-arrow-up-right me-1"></i><?= h($qa['label']) ?>
                            <?php if (!empty($qa['hint'])): ?><div class="text-muted" style="font-size:.72rem"><?= h($qa['hint']) ?></div><?php endif; ?>
                        </a>
                    <?php endif;
                endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Accounting Navigation -->
    <div class="col-lg-4">
        <div class="card card-round h-100">
            <div class="card-header bg-white"><strong class="shop-section-title">Accounting Navigation</strong></div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($navLinks as $link): ?>
                        <a class="shop-nav-chip" href="<?= h($link['href']) ?>"><i class="bi <?= h($link['icon']) ?>"></i><?= h($link['label']) ?></a>
                    <?php endforeach; ?>
                </div>
                <p class="text-muted small mt-3 mb-0">Links reuse existing Construction invoices, receipts, recognition, and GL reports — Madar Al Wadi only.</p>
            </div>
        </div>
    </div>
</div>
