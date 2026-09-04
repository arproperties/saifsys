<?php
/**
 * Grocery POS backoffice: shared date/location filters and CSV export.
 */

/**
 * @return array{preset:string, date_from:string, date_to:string, location_ids:int[], ts_from:string, ts_to:string}
 */
function pos_report_parse_filters(): array {
    $preset = strtolower(trim((string)($_GET['preset'] ?? 'today')));
    if (!in_array($preset, ['today', 'yesterday', 'custom'], true)) {
        $preset = 'today';
    }
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    $today = new DateTime('today', $tz);
    if ($preset === 'today') {
        $from = (clone $today)->format('Y-m-d');
        $to = $from;
    } elseif ($preset === 'yesterday') {
        $y = (clone $today)->modify('-1 day');
        $from = $y->format('Y-m-d');
        $to = $from;
    } else {
        $from = trim((string)($_GET['date_from'] ?? ''));
        $to = trim((string)($_GET['date_to'] ?? ''));
        if ($from === '' || $to === '') {
            $from = $today->format('Y-m-d');
            $to = $from;
        }
        if (strtotime($from) > strtotime($to)) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }
    }
    $tsFrom = $from . ' 00:00:00';
    $tsTo = $to . ' 23:59:59';

    $locRaw = $_GET['loc_ids'] ?? $_GET['location_id'] ?? '';
    $locationIds = [];
    if (is_array($locRaw)) {
        foreach ($locRaw as $x) {
            $n = (int)$x;
            if ($n > 0) {
                $locationIds[] = $n;
            }
        }
    } elseif (is_string($locRaw) && $locRaw !== '') {
        foreach (explode(',', $locRaw) as $p) {
            $n = (int)trim($p);
            if ($n > 0) {
                $locationIds[] = $n;
            }
        }
    }
    $locationIds = array_values(array_unique($locationIds));

    return [
        'preset' => $preset,
        'date_from' => $from,
        'date_to' => $to,
        'location_ids' => $locationIds,
        'ts_from' => $tsFrom,
        'ts_to' => $tsTo,
    ];
}

/**
 * @param list<int> $locationIds
 * @return array{sql:string, params:list<mixed>}
 */
function pos_report_location_clause(array $locationIds): array {
    if ($locationIds === []) {
        return ['', []];
    }
    $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
    $sql = " AND ps.location_id IN ($placeholders) ";
    $params = $locationIds;
    return [$sql, $params];
}

/**
 * COALESCE(posted_at, created_at) within range; only posted sales.
 *
 * @param list<mixed> $baseParams [company_id, ...]
 * @return array{sql:string, params:list<mixed>}
 */
function pos_report_sales_where_time_locations(int $companyId, string $tsFrom, string $tsTo, array $locationIds): array {
    $params = [$companyId, $tsFrom, $tsTo];
    $loc = pos_report_location_clause($locationIds);
    $sql = "
        WHERE ps.company_id = ?
          AND ps.status = 'posted'
          AND COALESCE(ps.posted_at, ps.created_at) >= ?
          AND COALESCE(ps.posted_at, ps.created_at) <= ?
    " . $loc[0];
    $params = array_merge($params, $loc[1]);
    return [$sql, $params];
}

/**
 * Send CSV download and exit.
 *
 * @param list<string> $headers
 * @param list<list<string|int|float>> $rows
 */
function pos_report_send_csv(string $filename, array $headers, array $rows): void {
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?: 'export.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, array_map(function ($c) {
            if ($c === null) {
                return '';
            }
            if (is_float($c)) {
                return round($c, 4);
            }
            return (string)$c;
        }, $r));
    }
    fclose($out);
    exit;
}

function pos_report_query_string(array $overrides = []): string {
    $g = array_merge($_GET, $overrides);
    $parts = [];
    foreach ($g as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        if (is_array($v)) {
            foreach ($v as $vv) {
                $parts[] = rawurlencode((string)$k) . '[]=' . rawurlencode((string)$vv);
            }
        } else {
            $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
    }
    return $parts ? ('?' . implode('&', $parts)) : '';
}
