<?php
/**
 * Shared date range helpers for accounting reports (From / To, per-day capable).
 */

if (!function_exists('report_date_range')) {
    /**
     * Parse and validate from/to from request (defaults: first of month → today).
     *
     * @return array{from:string, to:string}
     */
    function report_date_range(?array $get = null): array
    {
        $get = $get ?? $_GET;
        $from = (string)($get['from'] ?? date('Y-m-01'));
        $to = (string)($get['to'] ?? date('Y-m-d'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return ['from' => $from, 'to' => $to];
    }
}

if (!function_exists('report_date_query')) {
    /** Build query string with from/to plus optional extra params. */
    function report_date_query(string $from, string $to, array $extra = []): string
    {
        return http_build_query(array_merge(['from' => $from, 'to' => $to], $extra));
    }
}

if (!function_exists('report_date_period_label')) {
    function report_date_period_label(string $from, string $to): string
    {
        if ($from === $to) {
            return $from;
        }
        return $from . ' to ' . $to;
    }
}

if (!function_exists('report_date_filter_form')) {
    /**
     * Render standard From / To / Run filter row (GET form).
     *
     * @param array<string,mixed> $extraFields e.g. ['account_id' => 5] preserved as hidden inputs
     * @param array<string,mixed> $options button_label, show_period_hint, form_class
     */
    function report_date_filter_form(string $from, string $to, array $extraFields = [], array $options = []): void
    {
        $btn = (string)($options['button_label'] ?? 'Run');
        $hint = (string)($options['hint'] ?? '');
        $formClass = (string)($options['form_class'] ?? 'row g-2 mt-2');
        $colClass = (string)($options['col_class'] ?? 'col-md-3');
        ?>
        <form class="<?= htmlspecialchars($formClass, ENT_QUOTES, 'UTF-8') ?>" method="get">
            <?php foreach ($extraFields as $name => $val): ?>
                <?php if ($val !== null && $val !== ''): ?>
                    <input type="hidden" name="<?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') ?>"
                           value="<?= htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="<?= htmlspecialchars($colClass, ENT_QUOTES, 'UTF-8') ?>">
                <label class="form-label">From</label>
                <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="<?= htmlspecialchars($colClass, ENT_QUOTES, 'UTF-8') ?>">
                <label class="form-label">To</label>
                <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="<?= htmlspecialchars($colClass, ENT_QUOTES, 'UTF-8') ?> align-self-end">
                <button type="submit" class="btn btn-primary w-100"><?= htmlspecialchars($btn, ENT_QUOTES, 'UTF-8') ?></button>
            </div>
            <?php if ($hint !== ''): ?>
                <div class="col-md-6 align-self-end">
                    <small class="text-muted"><?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') ?></small>
                </div>
            <?php endif; ?>
        </form>
        <?php
    }
}
