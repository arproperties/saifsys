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
