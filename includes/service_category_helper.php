<?php
/**
 * Service Management — service category helpers (Phase 5).
 */

require_once __DIR__ . '/cleaning_accounting_context.php';

if (!function_exists('sm_service_category_table_exists')) {
    function sm_service_category_table_exists(PDO $conn): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $st = $conn->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sm_service_categories'
        ");
        $st->execute();
        return $exists = ((int)$st->fetchColumn() > 0);
    }
}

if (!function_exists('sm_make_order_has_service_category')) {
    function sm_make_order_has_service_category(PDO $conn): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $st = $conn->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'service_category_id'
        ");
        $st->execute();
        return $exists = ((int)$st->fetchColumn() > 0);
    }
}

if (!function_exists('sm_ensure_default_cleaning_category')) {
    /** Create or return the default Cleaning category for a company. */
    function sm_ensure_default_cleaning_category(PDO $conn, ?int $companyId = null): int
    {
        if (!sm_service_category_table_exists($conn)) {
            return 0;
        }
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $st = $conn->prepare("
            SELECT id FROM sm_service_categories
            WHERE company_id = ? AND code = 'cleaning'
            LIMIT 1
        ");
        $st->execute([$companyId]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $ins = $conn->prepare("
            INSERT INTO sm_service_categories (company_id, code, name, is_active, icon, sort_order)
            VALUES (?, 'cleaning', 'Cleaning', 1, '🧹', 1)
        ");
        $ins->execute([$companyId]);
        return (int)$conn->lastInsertId();
    }
}

if (!function_exists('sm_resolve_service_category_id')) {
    /**
     * Resolve category for a new work order (explicit pick or company default Cleaning).
     */
    function sm_resolve_service_category_id(PDO $conn, ?int $explicitId = null, ?int $companyId = null): ?int
    {
        if (!sm_make_order_has_service_category($conn) || !sm_service_category_table_exists($conn)) {
            return null;
        }
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        if ($explicitId && $explicitId > 0) {
            $st = $conn->prepare("
                SELECT id FROM sm_service_categories
                WHERE id = ? AND company_id = ? AND is_active = 1
                LIMIT 1
            ");
            $st->execute([$explicitId, $companyId]);
            $id = (int)$st->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }
        $default = sm_ensure_default_cleaning_category($conn, $companyId);
        return $default > 0 ? $default : null;
    }
}

if (!function_exists('sm_list_service_categories')) {
    /** @return array<int, array<string, mixed>> */
    function sm_list_service_categories(PDO $conn, ?int $companyId = null, bool $activeOnly = true): array
    {
        if (!sm_service_category_table_exists($conn)) {
            return [];
        }
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $sql = "
            SELECT id, company_id, code, name, is_active, icon, sort_order
            FROM sm_service_categories
            WHERE company_id = ?
        ";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";
        $st = $conn->prepare($sql);
        $st->execute([$companyId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('sm_save_service_category')) {
    function sm_save_service_category(PDO $conn, array $data): int
    {
        if (!sm_service_category_table_exists($conn)) {
            throw new RuntimeException('Run tools/sm_apply_phase5_schema.php first.');
        }
        $id = (int)($data['id'] ?? 0);
        $companyId = (int)($data['company_id'] ?? cleaning_accounting_company_id($conn));
        $code = strtolower(trim((string)($data['code'] ?? '')));
        $name = trim((string)($data['name'] ?? ''));
        $icon = trim((string)($data['icon'] ?? ''));
        $sort = (int)($data['sort_order'] ?? 0);
        $active = !empty($data['is_active']) ? 1 : 0;

        if ($code === '' || $name === '') {
            throw new InvalidArgumentException('Code and name are required.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $code)) {
            throw new InvalidArgumentException('Code must be lowercase letters, numbers, underscores (2–31 chars).');
        }

        if ($id > 0) {
            $st = $conn->prepare("
                UPDATE sm_service_categories
                SET code = ?, name = ?, icon = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $st->execute([$code, $name, $icon ?: null, $sort, $active, $id, $companyId]);
            return $id;
        }

        $ins = $conn->prepare("
            INSERT INTO sm_service_categories (company_id, code, name, icon, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$companyId, $code, $name, $icon ?: null, $sort, $active]);
        return (int)$conn->lastInsertId();
    }
}

if (!function_exists('sm_category_column_exists')) {
    function sm_category_column_exists(PDO $conn, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        $st = $conn->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sm_service_categories' AND COLUMN_NAME = ?
        ");
        $st->execute([$column]);
        return $cache[$column] = ((int)$st->fetchColumn() > 0);
    }
}

if (!function_exists('sm_make_order_has_booking_categories')) {
    function sm_make_order_has_booking_categories(PDO $conn): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $st = $conn->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'booking_categories'
        ");
        $st->execute();
        return $exists = ((int)$st->fetchColumn() > 0);
    }
}

if (!function_exists('sm_get_category_by_id')) {
    function sm_get_category_by_id(PDO $conn, int $id, ?int $companyId = null): ?array
    {
        if ($id <= 0 || !sm_service_category_table_exists($conn)) {
            return null;
        }
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $cols = "id, company_id, code, name, is_active, icon, sort_order";
        if (sm_category_column_exists($conn, 'materials_rate_per_hour')) {
            $cols .= ", materials_rate_per_hour";
        }
        $st = $conn->prepare("SELECT {$cols} FROM sm_service_categories WHERE id = ? AND company_id = ? LIMIT 1");
        $st->execute([$id, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('sm_infer_category_code_from_service_name')) {
    /** Guess category from catalog line label when service_category_id is missing. */
    function sm_infer_category_code_from_service_name(string $serviceName): ?string
    {
        $n = strtolower(trim($serviceName));
        if ($n === '') {
            return null;
        }
        if (preg_match('/\b(pest|cockroach|rodent|termite|bed[\s-]?bug|fumig|insect|sanitiz)/i', $n)) {
            return 'pest_control';
        }
        return null;
    }
}

if (!function_exists('sm_resolve_order_line_category')) {
    /**
     * Resolve sm_service_categories row for an order_services line.
     * Legacy cleaning catalog rows often have NULL service_category_id — default to Cleaning.
     *
     * @return array{id:int, code:string, name:string}
     */
    function sm_resolve_order_line_category(PDO $conn, array $line, ?int $companyId = null): array
    {
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $fallbackCleaning = sm_get_category_by_code($conn, 'cleaning', $companyId);
        if (!$fallbackCleaning) {
            $fallbackCleaning = [
                'id' => sm_ensure_default_cleaning_category($conn, $companyId),
                'code' => 'cleaning',
                'name' => 'Cleaning',
            ];
        }

        $serviceId = (int)($line['service_id'] ?? 0);
        if ($serviceId > 0 && sm_service_category_table_exists($conn)) {
            $st = $conn->prepare("
                SELECT sc.id, sc.code, sc.name
                FROM services s
                LEFT JOIN sm_service_categories sc ON sc.id = s.service_category_id AND sc.company_id = ?
                WHERE s.id = ?
                LIMIT 1
            ");
            $st->execute([$companyId, $serviceId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['id'])) {
                return [
                    'id' => (int)$row['id'],
                    'code' => (string)$row['code'],
                    'name' => (string)$row['name'],
                ];
            }
        }

        $serviceName = (string)($line['service_name'] ?? $line['description'] ?? '');
        $inferredCode = sm_infer_category_code_from_service_name($serviceName);
        if ($inferredCode) {
            $cat = sm_get_category_by_code($conn, $inferredCode, $companyId);
            if ($cat) {
                return [
                    'id' => (int)$cat['id'],
                    'code' => (string)$cat['code'],
                    'name' => (string)$cat['name'],
                ];
            }
        }

        return [
            'id' => (int)($fallbackCleaning['id'] ?? 0),
            'code' => (string)($fallbackCleaning['code'] ?? 'cleaning'),
            'name' => (string)($fallbackCleaning['name'] ?? 'Cleaning'),
        ];
    }
}

if (!function_exists('sm_get_category_by_code')) {
    function sm_get_category_by_code(PDO $conn, string $code, ?int $companyId = null): ?array
    {
        if (!sm_service_category_table_exists($conn)) {
            return null;
        }
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $cols = "id, company_id, code, name, is_active, icon, sort_order";
        if (sm_category_column_exists($conn, 'materials_rate_per_hour')) {
            $cols .= ", materials_rate_per_hour";
        }
        $st = $conn->prepare("SELECT {$cols} FROM sm_service_categories WHERE code = ? AND company_id = ? LIMIT 1");
        $st->execute([strtolower(trim($code)), $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('sm_list_category_catalog')) {
    function sm_list_category_catalog(PDO $conn, int $categoryId, bool $activeOnly = true): array
    {
        if ($categoryId <= 0 || !sm_make_order_has_service_category($conn)) {
            return [];
        }
        $sql = "
            SELECT id, name, description, price, base_price, price_per_unit,
                   duration_minutes, is_active, sort_order, service_category_id
            FROM services
            WHERE service_category_id = ?
        ";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";
        $st = $conn->prepare($sql);
        $st->execute([$categoryId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['default_price'] = round((float)($r['price_per_unit'] ?? $r['price'] ?? $r['base_price'] ?? 0), 2);
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('sm_save_catalog_item')) {
    function sm_save_catalog_item(PDO $conn, int $categoryId, array $data, ?int $companyId = null): int
    {
        if ($categoryId <= 0) {
            throw new InvalidArgumentException('Category is required.');
        }
        $cat = sm_get_category_by_id($conn, $categoryId, $companyId);
        if (!$cat) {
            throw new InvalidArgumentException('Category not found.');
        }
        if (($cat['code'] ?? '') === 'cleaning') {
            throw new InvalidArgumentException('Cleaning uses hourly labour — set materials rate on the category instead.');
        }

        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $price = round((float)($data['price'] ?? 0), 2);
        $description = trim((string)($data['description'] ?? ''));
        $sort = (int)($data['sort_order'] ?? 0);
        $active = !empty($data['is_active']) ? 1 : 0;
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);

        if ($name === '') {
            throw new InvalidArgumentException('Item name is required.');
        }

        if ($id > 0) {
            $st = $conn->prepare("
                UPDATE services
                SET name = ?, description = ?, price = ?, base_price = ?, price_per_unit = ?,
                    category = ?, is_active = ?, sort_order = ?, service_category_id = ?
                WHERE id = ? AND service_category_id = ?
            ");
            $st->execute([
                $name, $description ?: null, $price, $price, $price,
                $cat['name'], $active, $sort, $categoryId, $id, $categoryId,
            ]);
            return $id;
        }

        $cols = ['service_category_id', 'name', 'description', 'category', 'price', 'base_price', 'price_per_unit', 'duration_minutes', 'is_active', 'sort_order'];
        $vals = [$categoryId, $name, $description ?: null, $cat['name'], $price, $price, $price, 60, $active, $sort];
        $ph = array_fill(0, count($cols), '?');

        $stChk = $conn->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = ?
        ");
        foreach (['company_id', 'updated_at'] as $optCol) {
            $stChk->execute([$optCol]);
            if ((int)$stChk->fetchColumn() > 0) {
                if ($optCol === 'company_id') {
                    array_splice($cols, 1, 0, 'company_id');
                    array_splice($vals, 1, 0, $companyId);
                    array_splice($ph, 1, 0, '?');
                }
            }
        }

        $sql = 'INSERT INTO services (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
        $ins = $conn->prepare($sql);
        $ins->execute($vals);
        return (int)$conn->lastInsertId();
    }
}

if (!function_exists('sm_delete_catalog_item')) {
    function sm_delete_catalog_item(PDO $conn, int $categoryId, int $itemId): void
    {
        if ($itemId <= 0 || $categoryId <= 0) {
            throw new InvalidArgumentException('Invalid catalog item.');
        }
        $st = $conn->prepare("UPDATE services SET is_active = 0 WHERE id = ? AND service_category_id = ?");
        $st->execute([$itemId, $categoryId]);
        if ($st->rowCount() === 0) {
            throw new InvalidArgumentException('Catalog item not found.');
        }
    }
}

if (!function_exists('sm_save_category_materials_rate')) {
    function sm_save_category_materials_rate(PDO $conn, int $categoryId, float $rate): void
    {
        if (!sm_category_column_exists($conn, 'materials_rate_per_hour')) {
            return;
        }
        $cat = sm_get_category_by_id($conn, $categoryId);
        if (!$cat || ($cat['code'] ?? '') !== 'cleaning') {
            return;
        }
        $st = $conn->prepare("
            UPDATE sm_service_categories SET materials_rate_per_hour = ?, updated_at = NOW() WHERE id = ?
        ");
        $st->execute([round(max(0, $rate), 2), $categoryId]);
    }
}

if (!function_exists('sm_categories_for_booking_api')) {
    function sm_categories_for_booking_api(PDO $conn, ?int $companyId = null): array
    {
        $cats = sm_list_service_categories($conn, $companyId, true);
        $out = [];
        foreach ($cats as $cat) {
            $id = (int)$cat['id'];
            $full = sm_get_category_by_id($conn, $id, $companyId) ?: $cat;
            $code = (string)($full['code'] ?? '');
            $out[] = [
                'id' => $id,
                'code' => $code,
                'name' => (string)($full['name'] ?? ''),
                'icon' => (string)($full['icon'] ?? ''),
                'sort_order' => (int)($full['sort_order'] ?? 0),
                'materials_rate_per_hour' => round((float)($full['materials_rate_per_hour'] ?? 0), 2),
                'is_cleaning' => ($code === 'cleaning'),
                'catalog' => ($code === 'cleaning') ? [] : sm_list_category_catalog($conn, $id, true),
            ];
        }
        return $out;
    }
}

if (!function_exists('sm_resolve_booking_category_ids')) {
    function sm_resolve_booking_category_ids(PDO $conn, ?string $bookingCategoriesJson, ?int $primaryCategoryId = null): array
    {
        $ids = [];
        if ($bookingCategoriesJson) {
            try {
                $decoded = json_decode($bookingCategoriesJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $ids = array_values(array_unique(array_filter(array_map('intval', $decoded))));
                }
            } catch (Throwable $e) {
                $decoded = json_decode($bookingCategoriesJson, true);
                if (is_array($decoded)) {
                    $ids = array_values(array_unique(array_filter(array_map('intval', $decoded))));
                }
            }
        }
        foreach ($ids as $id) {
            if (!sm_get_category_by_id($conn, $id)) {
                $ids = array_values(array_diff($ids, [$id]));
            }
        }
        if (!$ids && $primaryCategoryId && $primaryCategoryId > 0) {
            if (sm_get_category_by_id($conn, $primaryCategoryId)) {
                $ids = [$primaryCategoryId];
            }
        }
        return $ids;
    }
}

if (!function_exists('sm_get_categories_for_order')) {
    function sm_get_categories_for_order(PDO $conn, ?string $bookingCategoriesJson, ?int $primaryCategoryId = null): array
    {
        $ids = sm_resolve_booking_category_ids($conn, $bookingCategoriesJson, $primaryCategoryId);
        $out = [];
        foreach ($ids as $id) {
            $cat = sm_get_category_by_id($conn, $id);
            if ($cat) {
                $out[] = $cat;
            }
        }
        return $out;
    }
}

if (!function_exists('sm_format_category_labels')) {
    function sm_format_category_labels(array $categories, string $separator = ' + '): string
    {
        $parts = [];
        foreach ($categories as $cat) {
            $label = trim((string)(($cat['icon'] ?? '') . ' ' . ($cat['name'] ?? '')));
            if ($label !== '') {
                $parts[] = $label;
            }
        }
        return implode($separator, $parts);
    }
}

if (!function_exists('sm_order_category_labels')) {
    function sm_order_category_labels(PDO $conn, ?string $bookingCategoriesJson, ?int $primaryCategoryId = null): string
    {
        return sm_format_category_labels(
            sm_get_categories_for_order($conn, $bookingCategoriesJson, $primaryCategoryId)
        );
    }
}

if (!function_exists('sm_resolve_primary_category_id')) {
    function sm_resolve_primary_category_id(PDO $conn, array $categoryIds): ?int
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        if (!$categoryIds) {
            return sm_resolve_service_category_id($conn);
        }
        $cleaning = sm_get_category_by_code($conn, 'cleaning');
        if ($cleaning && in_array((int)$cleaning['id'], $categoryIds, true)) {
            return (int)$cleaning['id'];
        }
        return $categoryIds[0];
    }
}
