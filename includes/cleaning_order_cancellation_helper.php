<?php
/**
 * Shared helpers for cleaning work-order cancellation tracking.
 */

function cleaning_order_cancel_column_exists(PDO $conn, string $column): bool {
    static $cache = [];
    $key = 'make_order.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $st = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'make_order'
          AND COLUMN_NAME = ?
    ");
    $st->execute([$column]);
    return $cache[$key] = ((int)$st->fetchColumn() > 0);
}

function cleaning_order_cancel_ensure_schema(PDO $conn): void {
    if (!cleaning_order_cancel_column_exists($conn, 'cancellation_category')) {
        $conn->exec("ALTER TABLE make_order ADD COLUMN cancellation_category VARCHAR(50) DEFAULT NULL AFTER cancel_reason");
    }
    if (!cleaning_order_cancel_column_exists($conn, 'cancellation_details')) {
        $conn->exec("ALTER TABLE make_order ADD COLUMN cancellation_details TEXT DEFAULT NULL AFTER cancellation_category");
    }
}

function cleaning_order_cancel_categories(): array {
    return ['Cleaner', 'Driver', 'Management', 'Client'];
}

function cleaning_order_cancel_normalize_category(string $category): string {
    $category = trim($category);
    if (strcasecmp($category, 'Managment') === 0) {
        $category = 'Management';
    }
    foreach (cleaning_order_cancel_categories() as $allowed) {
        if (strcasecmp($category, $allowed) === 0) return $allowed;
    }
    return '';
}

function cleaning_order_cancel_summary(string $category, string $details): string {
    $category = cleaning_order_cancel_normalize_category($category);
    $details = trim($details);
    return trim(($category !== '' ? $category . ': ' : '') . $details);
}
