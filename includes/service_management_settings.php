<?php
/**
 * Service Management module settings (feature flags).
 */

if (!function_exists('sm_get_setting')) {
    function sm_get_setting(PDO $conn, string $key, string $default = ''): string {
        static $cache = [];
        if ($key === '__clear__') {
            if ($default === '') {
                $cache = [];
            } else {
                unset($cache[$default]);
            }
            return '';
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $st = $conn->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
            $st->execute([$key]);
            $val = $st->fetchColumn();
            $cache[$key] = ($val !== false && $val !== null) ? (string)$val : $default;
        } catch (Throwable $e) {
            $cache[$key] = $default;
        }
        return $cache[$key];
    }
}

if (!function_exists('sm_set_setting')) {
    function sm_set_setting(PDO $conn, string $key, string $value): void {
        $st = $conn->prepare("
            INSERT INTO settings (`key`, `value`) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
        ");
        $st->execute([$key, $value]);
        sm_get_setting($conn, '__clear__', $key);
    }
}

if (!function_exists('sm_use_accounting_service')) {
    /** When true, dashboards use ServiceAccountingService instead of inline SQL. */
    function sm_use_accounting_service(PDO $conn): bool {
        return sm_get_setting($conn, 'sm_use_accounting_service', '0') === '1';
    }
}

if (!function_exists('sm_accounting_compare_mode')) {
    /** When true, compute legacy + service values and surface mismatches in health check. */
    function sm_accounting_compare_mode(PDO $conn): bool {
        return sm_get_setting($conn, 'sm_accounting_compare_mode', '1') === '1';
    }
}

if (!function_exists('sm_defer_auto_invoice')) {
    /** Phase 2: when true (default), invoices are created only on Finalize — not on WO save. */
    function sm_defer_auto_invoice(PDO $conn): bool {
        return sm_get_setting($conn, 'sm_defer_auto_invoice', '1') === '1';
    }
}

if (!function_exists('sm_hybrid_batch_invoicing')) {
    /** Phase 4: summary batch invoice links to per-WO child invoices (no GL duplicate). */
    function sm_hybrid_batch_invoicing(PDO $conn): bool {
        return sm_get_setting($conn, 'sm_hybrid_batch_invoicing', '1') === '1';
    }
}

if (!function_exists('sm_health_exclude_orphan_invoices')) {
    /** When true, standalone invoices (no order_id) are accepted legacy — not critical health issues. */
    function sm_health_exclude_orphan_invoices(PDO $conn): bool {
        return sm_get_setting($conn, 'sm_health_exclude_orphan_invoices', '1') === '1';
    }
}
