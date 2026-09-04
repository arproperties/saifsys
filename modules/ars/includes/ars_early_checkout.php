<?php
/**
 * ARS early checkout (operational) — no stay refund.
 * Confirmed 2026-07-19: unused nights are non-refundable; planned check_out preserved;
 * actual_check_out drives cleaning WO date and UI badge.
 */

declare(strict_types=1);

function ars_early_checkout_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ars_early_checkout_add_column($conn, 'actual_check_out', 'DATE NULL');
    ars_early_checkout_add_column($conn, 'is_early_checkout', 'TINYINT(1) NOT NULL DEFAULT 0');
}

function ars_early_checkout_add_column(PDO $conn, string $column, string $definition): void {
    try {
        $stmt = $conn->query('SHOW COLUMNS FROM `ars_bookings` LIKE ' . $conn->quote($column));
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE `ars_bookings` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {
        error_log('ARS early checkout schema failed for ars_bookings.' . $column . ': ' . $e->getMessage());
    }
}

/** Planned stay end date (contractual). */
function ars_booking_planned_check_out(array $booking): string {
    return (string)($booking['check_out'] ?? '');
}

/** Actual departure date if recorded, else planned. */
function ars_booking_effective_check_out(array $booking): string {
    $actual = trim((string)($booking['actual_check_out'] ?? ''));
    if ($actual !== '') {
        return $actual;
    }
    return ars_booking_planned_check_out($booking);
}

function ars_booking_is_early_checkout(array $booking): bool {
    if (!empty($booking['is_early_checkout'])) {
        return true;
    }
    $planned = ars_booking_planned_check_out($booking);
    $actual = trim((string)($booking['actual_check_out'] ?? ''));
    return $planned !== '' && $actual !== '' && $actual < $planned;
}

/**
 * True when checking out today would be early vs planned check_out.
 */
function ars_checkout_would_be_early(array $booking, ?string $actualDate = null): bool {
    $planned = ars_booking_planned_check_out($booking);
    $actual = $actualDate ?: date('Y-m-d');
    return $planned !== '' && $actual < $planned;
}
