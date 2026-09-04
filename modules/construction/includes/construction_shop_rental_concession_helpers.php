<?php
/**
 * Construction Shop Rental — Rent Concession / Free Rent (BR-CO-SHOP-RENT-CONCESSION-001).
 * Operational only: filters rent earning months. No accounting / cheque / payment workspace changes.
 */

require_once __DIR__ . '/construction_helpers.php';

const CO_SHOP_CONCESSION_REASONS = [
    'fit_out' => 'Fit-Out',
    'promotion' => 'Promotion',
    'commercial_negotiation' => 'Commercial Negotiation',
    'other' => 'Other',
];

const CO_SHOP_CONCESSION_POSITIONS = [
    'beginning' => 'Beginning',
    'end' => 'End',
    'custom' => 'Custom',
];

function co_shop_concession_schema_ready(PDO $conn): bool {
    return co_db_column_exists($conn, 'co_shop_rental_contracts', 'concession_enabled')
        && co_db_column_exists($conn, 'co_shop_rental_contracts', 'concession_from')
        && co_db_column_exists($conn, 'co_shop_rental_contracts', 'concession_to')
        && co_db_column_exists($conn, 'co_shop_rental_contracts', 'concession_duration_value');
}

function co_shop_concession_active(array $contract): bool {
    if (empty($contract['concession_enabled'])) {
        return false;
    }
    $from = (string)($contract['concession_from'] ?? '');
    $to = (string)($contract['concession_to'] ?? '');
    return $from !== '' && $to !== '' && $from <= $to;
}

/**
 * Build monthly occupancy windows (same algorithm as historic earning months).
 * @return list<array{period_start:string,period_end:string,due_date:string}>
 */
function co_shop_occupancy_month_windows(string $startDate, string $endDate): array {
    if ($startDate === '' || $endDate === '') {
        return [];
    }
    try {
        $cursor = new DateTime($startDate);
        $end = new DateTime($endDate);
    } catch (Throwable $e) {
        return [];
    }
    if ($end < $cursor) {
        return [];
    }
    $windows = [];
    while ($cursor <= $end) {
        $periodStart = $cursor->format('Y-m-d');
        $periodEndDt = clone $cursor;
        $periodEndDt->modify('+1 month -1 day');
        if ($periodEndDt > $end) {
            $periodEndDt = clone $end;
        }
        $windows[] = [
            'period_start' => $periodStart,
            'period_end' => $periodEndDt->format('Y-m-d'),
            'due_date' => $periodStart,
        ];
        $cursor->modify('+1 month');
        if (count($windows) > 120) {
            break;
        }
    }
    return $windows;
}

function co_shop_concession_overlaps_month(string $periodStart, string $periodEnd, string $concFrom, string $concTo): bool {
    return $periodStart <= $concTo && $periodEnd >= $concFrom;
}

/**
 * Compute From/To from occupancy + position + duration.
 * @return array{from:string,to:string}
 */
function co_shop_concession_compute_dates(
    string $occStart,
    string $occEnd,
    string $position,
    float $durationValue,
    string $durationUnit
): array {
    $position = in_array($position, ['beginning', 'end', 'custom'], true) ? $position : 'beginning';
    $unit = ($durationUnit === 'days') ? 'days' : 'months';
    $n = max(0, (float)$durationValue);
    if ($n <= 0 || $occStart === '' || $occEnd === '') {
        return ['from' => '', 'to' => ''];
    }
    $intN = (int)ceil($n - 0.00001);
    if ($intN <= 0) {
        return ['from' => '', 'to' => ''];
    }

    try {
        if ($position === 'end') {
            $to = $occEnd;
            $fromDt = new DateTime($to);
            if ($unit === 'days') {
                $fromDt->modify('-' . $intN . ' days');
                $fromDt->modify('+1 day');
            } else {
                $fromDt->modify('-' . $intN . ' month');
                $fromDt->modify('+1 day');
            }
            $from = $fromDt->format('Y-m-d');
            if ($from < $occStart) {
                $from = $occStart;
            }
            return ['from' => $from, 'to' => $to];
        }

        // beginning (default) and custom-with-duration both anchor at start when computing
        $from = $occStart;
        $toDt = new DateTime($from);
        if ($unit === 'days') {
            $toDt->modify('+' . $intN . ' days');
            $toDt->modify('-1 day');
        } else {
            $toDt->modify('+' . $intN . ' month');
            $toDt->modify('-1 day');
        }
        $to = $toDt->format('Y-m-d');
        if ($to > $occEnd) {
            $to = $occEnd;
        }
        return ['from' => $from, 'to' => $to];
    } catch (Throwable $e) {
        return ['from' => '', 'to' => ''];
    }
}

/**
 * Normalize POST / UI concession payload against occupancy.
 * @return array<string,mixed>
 */
function co_shop_concession_normalize_input(array $input, string $occStart, string $occEnd): array {
    $enabled = !empty($input['concession_enabled']) || !empty($input['concession_enabled_flag']);
    if (!$enabled) {
        return [
            'concession_enabled' => 0,
            'concession_reason' => null,
            'concession_position' => null,
            'concession_duration_value' => null,
            'concession_duration_unit' => null,
            'concession_from' => null,
            'concession_to' => null,
            'concession_notes' => null,
        ];
    }

    $reason = (string)($input['concession_reason'] ?? 'fit_out');
    if (!isset(CO_SHOP_CONCESSION_REASONS[$reason])) {
        $reason = 'other';
    }
    $position = (string)($input['concession_position'] ?? 'beginning');
    if (!isset(CO_SHOP_CONCESSION_POSITIONS[$position])) {
        $position = 'beginning';
    }
    $unit = ((string)($input['concession_duration_unit'] ?? 'months') === 'days') ? 'days' : 'months';
    $durVal = max(0, (float)($input['concession_duration_value'] ?? 0));
    $notes = trim((string)($input['concession_notes'] ?? ''));
    $manualDates = !empty($input['concession_manual_dates']) || $position === 'custom';

    $from = trim((string)($input['concession_from'] ?? ''));
    $to = trim((string)($input['concession_to'] ?? ''));

    if (!$manualDates || $from === '' || $to === '') {
        $calc = co_shop_concession_compute_dates($occStart, $occEnd, $position === 'custom' ? 'beginning' : $position, $durVal, $unit);
        if ($position === 'custom' && $from !== '' && $to !== '') {
            // keep manual
        } else {
            $from = $calc['from'];
            $to = $calc['to'];
        }
    }

    if ($from === '' || $to === '' || $from > $to) {
        throw new RuntimeException('Concession From/To dates are required and From must be on or before To.');
    }
    if ($from < $occStart || $to > $occEnd) {
        throw new RuntimeException('Concession dates must fall within the Occupancy Period (contract start/end).');
    }

    // Ensure at least one chargeable month remains
    $windows = co_shop_occupancy_month_windows($occStart, $occEnd);
    $chargeable = 0;
    foreach ($windows as $w) {
        if (!co_shop_concession_overlaps_month($w['period_start'], $w['period_end'], $from, $to)) {
            $chargeable++;
        }
    }
    if ($chargeable <= 0) {
        throw new RuntimeException('Concession covers the entire Occupancy Period. Leave at least one chargeable month.');
    }

    return [
        'concession_enabled' => 1,
        'concession_reason' => $reason,
        'concession_position' => $position,
        'concession_duration_value' => $durVal > 0 ? round($durVal, 2) : null,
        'concession_duration_unit' => $unit,
        'concession_from' => $from,
        'concession_to' => $to,
        'concession_notes' => $notes !== '' ? $notes : null,
    ];
}

/**
 * Persist concession settings. Does not regenerate schedules.
 * @return array{before:?array,after:array,event:string}
 */
function co_shop_save_concession_settings(
    PDO $conn,
    int $companyId,
    int $contractId,
    array $input,
    ?int $userId = null
): array {
    if ($companyId <= 0 || $contractId <= 0) {
        throw new RuntimeException('Company and contract are required.');
    }
    if (!co_shop_concession_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase_rent_concession.sql');
    }
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('Contract not found.');
    }
    if (co_shop_concession_has_posted_rent_invoices($conn, $companyId, $contractId)) {
        throw new RuntimeException('Cannot change rent concession after rent invoices have been posted. Cancel/void rent invoices first.');
    }

    $before = co_shop_concession_active($contract) ? [
        'from' => $contract['concession_from'] ?? null,
        'to' => $contract['concession_to'] ?? null,
        'reason' => $contract['concession_reason'] ?? null,
    ] : null;

    $norm = co_shop_concession_normalize_input(
        $input,
        (string)$contract['start_date'],
        (string)$contract['end_date']
    );

    $conn->prepare("
        UPDATE co_shop_rental_contracts
        SET concession_enabled = ?,
            concession_reason = ?,
            concession_position = ?,
            concession_duration_value = ?,
            concession_duration_unit = ?,
            concession_from = ?,
            concession_to = ?,
            concession_notes = ?,
            concession_value_total = NULL
        WHERE id = ? AND company_id = ?
    ")->execute([
        (int)$norm['concession_enabled'],
        $norm['concession_reason'],
        $norm['concession_position'],
        $norm['concession_duration_value'],
        $norm['concession_duration_unit'],
        $norm['concession_from'],
        $norm['concession_to'],
        $norm['concession_notes'],
        $contractId,
        $companyId,
    ]);

    $event = 'removed';
    if ((int)$norm['concession_enabled'] === 1) {
        $event = $before ? 'updated' : 'created';
    } elseif (!$before) {
        $event = 'noop';
    }

    if ($event !== 'noop' && function_exists('co_shop_log_event')) {
        co_shop_log_event($conn, $companyId, $contractId, 'rent_concession_' . $event, [
            'reason' => $norm['concession_reason'],
            'position' => $norm['concession_position'],
            'from' => $norm['concession_from'],
            'to' => $norm['concession_to'],
            'duration_value' => $norm['concession_duration_value'],
            'duration_unit' => $norm['concession_duration_unit'],
        ], $userId);
    }

    return ['before' => $before, 'after' => $norm, 'event' => $event];
}

function co_shop_concession_has_posted_rent_invoices(PDO $conn, int $companyId, int $contractId): bool {
    if (!co_db_table_exists($conn, 'co_client_invoices') || !co_db_table_exists($conn, 'co_shop_rent_schedules')) {
        return false;
    }
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM co_client_invoices i
        WHERE i.company_id = ?
          AND i.source_type = 'shop_rental'
          AND i.status <> 'cancelled'
          AND i.source_id IN (
              SELECT id FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ?
          )
    ");
    $stmt->execute([$companyId, $companyId, $contractId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Chargeable month windows (occupancy minus concession).
 * @return list<array{period_start:string,period_end:string,due_date:string}>
 */
function co_shop_chargeable_month_windows(array $contract): array {
    $windows = co_shop_occupancy_month_windows(
        (string)($contract['start_date'] ?? ''),
        (string)($contract['end_date'] ?? '')
    );
    if (!co_shop_concession_active($contract)) {
        return $windows;
    }
    $from = (string)$contract['concession_from'];
    $to = (string)$contract['concession_to'];
    $out = [];
    foreach ($windows as $w) {
        if (!co_shop_concession_overlaps_month($w['period_start'], $w['period_end'], $from, $to)) {
            $out[] = $w;
        }
    }
    return $out;
}

function co_shop_concession_month_count(array $contract): int {
    if (!co_shop_concession_active($contract)) {
        return 0;
    }
    $occ = co_shop_occupancy_month_windows((string)$contract['start_date'], (string)$contract['end_date']);
    $chg = co_shop_chargeable_month_windows($contract);
    return max(0, count($occ) - count($chg));
}

function co_shop_chargeable_month_count(array $contract): int {
    return max(1, count(co_shop_chargeable_month_windows($contract)));
}

/**
 * Estimate / compute concession commercial value = monthly_equiv × free months.
 * monthly_equiv uses chargeable months (rent_amount ÷ chargeable count).
 */
function co_shop_concession_value_compute(array $contract): float {
    if (!co_shop_concession_active($contract)) {
        return 0.0;
    }
    $rent = (float)($contract['rent_amount'] ?? 0);
    $chargeableN = count(co_shop_chargeable_month_windows($contract));
    $freeN = co_shop_concession_month_count($contract);
    if ($rent <= 0 || $chargeableN <= 0 || $freeN <= 0) {
        return 0.0;
    }
    $monthly = round($rent / $chargeableN, 2);
    return round($monthly * $freeN, 2);
}

/**
 * Persist concession_value_total after schedule generation.
 */
function co_shop_concession_persist_value(PDO $conn, int $companyId, int $contractId): ?float {
    if (!co_shop_concession_schema_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $companyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return null;
    }
    if (!co_shop_concession_active($contract)) {
        $conn->prepare("UPDATE co_shop_rental_contracts SET concession_value_total = NULL WHERE id = ? AND company_id = ?")
            ->execute([$contractId, $companyId]);
        return null;
    }
    $value = co_shop_concession_value_compute($contract);
    $conn->prepare("UPDATE co_shop_rental_contracts SET concession_value_total = ? WHERE id = ? AND company_id = ?")
        ->execute([$value, $contractId, $companyId]);
    return $value;
}

/**
 * Status block for contract view / summary.
 * @return array<string,mixed>
 */
function co_shop_concession_status(array $contract): array {
    $active = co_shop_concession_active($contract);
    $occStart = (string)($contract['start_date'] ?? '');
    $occEnd = (string)($contract['end_date'] ?? '');
    $chg = co_shop_chargeable_month_windows($contract);
    $chargeableFrom = $chg ? $chg[0]['period_start'] : $occStart;
    $chargeableTo = $chg ? $chg[count($chg) - 1]['period_end'] : $occEnd;
    $hasGap = false;
    if ($active && count($chg) >= 2) {
        // Detect non-contiguous chargeable months (mid-term custom)
        for ($i = 1; $i < count($chg); $i++) {
            $prevEnd = new DateTime($chg[$i - 1]['period_end']);
            $prevEnd->modify('+1 day');
            if ($prevEnd->format('Y-m-d') < $chg[$i]['period_start']) {
                $hasGap = true;
                break;
            }
        }
    }
    $reason = (string)($contract['concession_reason'] ?? '');
    return [
        'enabled' => $active,
        'reason' => $reason,
        'reason_label' => CO_SHOP_CONCESSION_REASONS[$reason] ?? ($reason ?: '—'),
        'position' => $contract['concession_position'] ?? null,
        'from' => $active ? ($contract['concession_from'] ?? null) : null,
        'to' => $active ? ($contract['concession_to'] ?? null) : null,
        'duration_value' => $contract['concession_duration_value'] ?? null,
        'duration_unit' => $contract['concession_duration_unit'] ?? null,
        'notes' => $contract['concession_notes'] ?? null,
        'occupancy_from' => $occStart,
        'occupancy_to' => $occEnd,
        'occupancy_months' => count(co_shop_occupancy_month_windows($occStart, $occEnd)),
        'chargeable_from' => $chargeableFrom,
        'chargeable_to' => $chargeableTo,
        'chargeable_months' => count($chg),
        'concession_months' => $active ? co_shop_concession_month_count($contract) : 0,
        'chargeable_has_gap' => $hasGap,
        'value_total' => isset($contract['concession_value_total']) && $contract['concession_value_total'] !== null
            ? (float)$contract['concession_value_total']
            : ($active ? co_shop_concession_value_compute($contract) : 0.0),
        'value_stored' => isset($contract['concession_value_total']) && $contract['concession_value_total'] !== null,
    ];
}
