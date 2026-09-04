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
