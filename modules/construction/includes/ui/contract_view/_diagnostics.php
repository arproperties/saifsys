<?php if ($diagnostics): ?>
<div class="alert alert-<?= in_array('error', array_column($diagnostics, 'severity'), true) ? 'danger' : 'warning' ?> no-print">
    <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Diagnostics &amp; Warnings</div>
    <ul class="mb-0 small">
        <?php foreach ($diagnostics as $w): ?>
            <li><strong><?= h($w['title']) ?>:</strong> <?= h($w['detail']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
