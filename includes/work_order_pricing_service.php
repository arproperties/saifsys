<?php
/**
 * Work order pricing for create/edit (hybrid cleaning labour + catalog items).
 * Keeps make_order totals aligned with order_services for invoice/GL at finalize.
 */

require_once __DIR__ . '/service_category_helper.php';

if (!function_exists('wo_pricing_compute_booking')) {
    /**
     * @param array{
     *   vat_rate: float,
     *   vat_included: string,
     *   hourly_rate: float,
     *   hours_booking: float,
     *   need_materials: bool,
     *   materials_rate_per_hour: float,
     *   include_cleaning: bool,
     *   catalog_lines: array<int, array{service_id?:int, service_name:string, qty:float, unit:string, unit_price:float}>
     * } $input
     * @return array{subtotal: float, vat_amount: float, grand_total: float, order_service_rows: array<int, array<string, mixed>>}
     */
    function wo_pricing_compute_booking(array $input): array
    {
        $vr = (float)($input['vat_rate'] ?? 5);
        $vatIncluded = (($input['vat_included'] ?? 'yes') === 'no') ? 'no' : 'yes';
        $hourlyRate = (float)($input['hourly_rate'] ?? 0);
        $hoursBooking = max(0, (float)($input['hours_booking'] ?? 0));
        $needMaterials = !empty($input['need_materials']);
        $materialsRate = max(0, (float)($input['materials_rate_per_hour'] ?? 0));
        $includeCleaning = !empty($input['include_cleaning']);
        $catalogLines = is_array($input['catalog_lines'] ?? null) ? $input['catalog_lines'] : [];

        $lineSubs = [];
        $orderServiceRows = [];

        if ($includeCleaning && $hoursBooking > 0 && $hourlyRate > 0) {
            if ($vatIncluded === 'yes') {
                $gross = round($hourlyRate * $hoursBooking, 2);
                $netRate = ($vr > 0) ? round($hourlyRate / (1 + $vr / 100), 4) : $hourlyRate;
                $labourSub = round($netRate * $hoursBooking, 2);
            } else {
                $labourSub = round($hourlyRate * $hoursBooking, 2);
            }
            $lineSubs[] = $labourSub;
            $orderServiceRows[] = [
                'service_id' => null,
                'service_name' => 'Cleaning labour',
                'description' => "{$hoursBooking} h",
                'qty' => $hoursBooking,
                'unit' => 'hour',
                'unit_price' => $hoursBooking > 0 ? round($labourSub / $hoursBooking, 4) : 0,
                'vat_rate' => $vr,
            ];
        }

        if ($includeCleaning && $needMaterials && $materialsRate > 0 && $hoursBooking > 0) {
            $matSub = round($materialsRate * $hoursBooking, 2);
            $lineSubs[] = $matSub;
            $orderServiceRows[] = [
                'service_id' => null,
                'service_name' => 'Cleaning materials',
                'description' => "{$hoursBooking} worker-hours",
                'qty' => $hoursBooking,
                'unit' => 'hour',
                'unit_price' => $materialsRate,
                'vat_rate' => $vr,
            ];
        }

        foreach ($catalogLines as $line) {
            $qty = max(0, (float)($line['qty'] ?? 0));
            $price = max(0, (float)($line['unit_price'] ?? 0));
            if ($qty <= 0 || $price < 0) {
                continue;
            }
            $lineSub = round($qty * $price, 2);
            $lineSubs[] = $lineSub;
            $orderServiceRows[] = [
                'service_id' => isset($line['service_id']) ? (int)$line['service_id'] : null,
                'service_name' => (string)($line['service_name'] ?? 'Service'),
                'description' => (string)($line['description'] ?? ''),
                'qty' => $qty,
                'unit' => (string)($line['unit'] ?? 'job'),
                'unit_price' => $price,
                'vat_rate' => $vr,
            ];
        }

        $sub = round(array_sum($lineSubs), 2);

        if ($sub <= 0 && $includeCleaning && $hoursBooking > 0 && $hourlyRate > 0) {
            // Fallback labour only
            if ($vatIncluded === 'yes') {
                $gross = round($hourlyRate * $hoursBooking, 2);
                $netRate = ($vr > 0) ? round($hourlyRate / (1 + $vr / 100), 4) : $hourlyRate;
                $sub = round($netRate * $hoursBooking, 2);
                $vat = round($gross - $sub, 2);
                $tot = $gross;
            } else {
                $sub = round($hourlyRate * $hoursBooking, 2);
                $vat = round($sub * ($vr / 100), 2);
                $tot = round($sub + $vat, 2);
            }
            return [
                'subtotal' => $sub,
                'vat_amount' => $vat,
                'grand_total' => $tot,
                'order_service_rows' => $orderServiceRows,
            ];
        }

        if ($vatIncluded === 'yes') {
            $tot = round($sub * (1 + $vr / 100), 2);
            $vat = round($tot - $sub, 2);
        } else {
            $vat = round($sub * ($vr / 100), 2);
            $tot = round($sub + $vat, 2);
        }

        return [
            'subtotal' => $sub,
            'vat_amount' => $vat,
            'grand_total' => $tot,
            'order_service_rows' => $orderServiceRows,
        ];
    }
}

if (!function_exists('wo_pricing_parse_booking_categories')) {
    function wo_pricing_parse_booking_categories(PDO $conn, array $post): array
    {
        $ids = [];
        if (!empty($post['booking_category_ids']) && is_array($post['booking_category_ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $post['booking_category_ids']))));
        } elseif (!empty($post['service_category_id'])) {
            $ids = [(int)$post['service_category_id']];
        }
        $valid = [];
        foreach ($ids as $id) {
            if (sm_get_category_by_id($conn, $id)) {
                $valid[] = $id;
            }
        }
        return $valid;
    }
}

if (!function_exists('wo_pricing_parse_catalog_lines')) {
    function wo_pricing_parse_catalog_lines(PDO $conn, array $post): array
    {
        $ids = $post['catalog_item_id'] ?? $post['svc_service_id'] ?? [];
        $qtys = $post['catalog_qty'] ?? $post['svc_qty'] ?? [];
        $prices = $post['catalog_price'] ?? $post['svc_unit_price'] ?? [];
        $units = $post['catalog_unit'] ?? $post['svc_unit'] ?? [];
        $catIds = $post['catalog_category_id'] ?? [];

        if (!is_array($ids)) {
            return [];
        }

        $lines = [];
        $nameById = [];
        $st = $conn->query("SELECT id, name, service_category_id FROM services WHERE is_active = 1");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $nameById[(int)$s['id']] = $s;
        }

        $cleaning = sm_get_category_by_code($conn, 'cleaning');
        $cleaningId = $cleaning ? (int)$cleaning['id'] : 0;

        for ($i = 0; $i < count($ids); $i++) {
            $sid = (int)($ids[$i] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $meta = $nameById[$sid] ?? null;
            if (!$meta) {
                continue;
            }
            $svcCatId = (int)($meta['service_category_id'] ?? 0);
            if ($catIds && isset($catIds[$i])) {
                $svcCatId = (int)$catIds[$i];
            }
            if ($cleaningId > 0 && $svcCatId === $cleaningId) {
                continue;
            }
            $qty = (float)($qtys[$i] ?? 0);
            $price = (float)($prices[$i] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $lines[] = [
                'service_id' => $sid,
                'service_name' => (string)$meta['name'],
                'qty' => $qty,
                'unit' => trim((string)($units[$i] ?? 'job')) ?: 'job',
                'unit_price' => $price,
            ];
        }

        $merged = [];
        foreach ($lines as $line) {
            $sid = (int)$line['service_id'];
            if (isset($merged[$sid])) {
                $merged[$sid]['qty'] = (float)$merged[$sid]['qty'] + (float)$line['qty'];
            } else {
                $merged[$sid] = $line;
            }
        }
        return array_values($merged);
    }
}

if (!function_exists('wo_pricing_resolve_vat_mode')) {
    function wo_pricing_resolve_vat_mode(array $orderRow): string
    {
        if (array_key_exists('vat_included', $orderRow) && $orderRow['vat_included'] !== null && $orderRow['vat_included'] !== '') {
            return (strtolower((string)$orderRow['vat_included']) === 'no') ? 'no' : 'yes';
        }
        $total = (float)($orderRow['total'] ?? 0);
        $vatAmount = (float)($orderRow['vat_amount'] ?? 0);
        $grand = (float)($orderRow['grand_total'] ?? 0);
        if (abs(($total + $vatAmount) - $grand) < 0.01) {
            return 'no';
        }
        return 'yes';
    }
}

if (!function_exists('wo_pricing_totals_from_subtotal')) {
    /**
     * @return array{subtotal: float, vat_amount: float, grand_total: float}
     */
    function wo_pricing_totals_from_subtotal(float $sub, float $vatRate, string $vatIncluded): array
    {
        $sub = round(max(0, $sub), 2);
        $vr = (float)$vatRate;
        $mode = ($vatIncluded === 'no') ? 'no' : 'yes';
        if ($mode === 'no') {
            $vat = round($sub * ($vr / 100), 2);
            $grand = round($sub + $vat, 2);
        } else {
            $grand = round($sub * (1 + $vr / 100), 2);
            $vat = round($grand - $sub, 2);
        }
        return ['subtotal' => $sub, 'vat_amount' => $vat, 'grand_total' => $grand];
    }
}

if (!function_exists('wo_pricing_totals_from_order_services')) {
    /**
     * Derive make_order / invoice header totals from persisted order_services lines.
     *
     * @return array{subtotal: float, vat_amount: float, grand_total: float}|null
     */
    function wo_pricing_totals_from_order_services(PDO $conn, int $orderId, array $orderRow): ?array
    {
        $st = $conn->prepare('SELECT qty, unit_price FROM order_services WHERE order_id = ? ORDER BY id');
        $st->execute([$orderId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }

        $sub = 0.0;
        foreach ($rows as $r) {
            $sub += round((float)$r['qty'] * (float)$r['unit_price'], 2);
        }
        $sub = round($sub, 2);
        if ($sub <= 0) {
            return null;
        }

        $vr = (float)($orderRow['vat_rate'] ?? 5.0);
        $mode = wo_pricing_resolve_vat_mode($orderRow);
        return wo_pricing_totals_from_subtotal($sub, $vr, $mode);
    }
}
