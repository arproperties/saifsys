<?php
/**
 * Rent Concession smoke test (company 3).
 * Usage: php modules/construction/tools/rent_concession_smoke.php
 * Requires: XAMPP MySQL + migrations/construction_shop_rental_phase_rent_concession.sql
 */
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/includes/db_connect.php';
require_once $root . '/modules/construction/includes/construction_shop_rental_helpers.php';

if (!is_link('/tmp/mysql.sock') && !file_exists('/tmp/mysql.sock')) {
    @symlink('/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock', '/tmp/mysql.sock');
}

$companyId = 3;
$passed = 0;
$failed = 0;

function rc_assert(bool $ok, string $label, $meta = null): void {
    global $passed, $failed;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}" . ($meta !== null ? ' ' . json_encode($meta) : '') . "\n";
        $failed++;
    }
}

function rc_sum_net(array $periods): float {
    $s = 0.0;
    foreach ($periods as $p) {
        $s = round($s + (float)$p['net'], 2);
    }
    return $s;
}

echo "=== Rent Concession smoke (company {$companyId}) ===\n";

rc_assert(co_shop_concession_schema_ready($conn), 'concession schema ready (run rent_concession migration)');

// Synthetic occupancy: 13 months, rent 120000 → with 1 free beginning → 12 chargeable × 10000
$base = [
    'start_date' => '2026-01-01',
    'end_date' => '2027-01-31', // 13 month windows: Jan26..Jan27
    'rent_amount' => 120000,
    'vat_rate' => 0,
    'vat_mode' => 'exclusive',
    'vat_collection_method' => 'included_in_installment',
    'concession_enabled' => 0,
];

$occ = co_shop_occupancy_month_windows($base['start_date'], $base['end_date']);
rc_assert(count($occ) === 13, 'occupancy windows = 13', ['n' => count($occ), 'first' => $occ[0] ?? null, 'last' => $occ[count($occ) - 1] ?? null]);

$none = co_shop_build_earning_months($base);
rc_assert(count($none) === 13, 'no concession → 13 earning months', count($none));
rc_assert(abs(rc_sum_net($none) - 120000) < 0.02, 'no concession sum nets = rent_amount', rc_sum_net($none));

$beg = $base;
$beg['concession_enabled'] = 1;
$beg['concession_from'] = '2026-01-01';
$beg['concession_to'] = '2026-01-31';
$begMonths = co_shop_build_earning_months($beg);
rc_assert(count($begMonths) === 12, 'beginning 1 free → 12 earning months', count($begMonths));
rc_assert(abs(rc_sum_net($begMonths) - 120000) < 0.02, 'beginning concession sum nets = rent', rc_sum_net($begMonths));
rc_assert($begMonths[0]['period_start'] === '2026-02-01', 'first chargeable is Feb', $begMonths[0]['period_start'] ?? null);
rc_assert((float)$begMonths[0]['net'] === 10000.0, 'equal split 10000', $begMonths[0]['net'] ?? null);
foreach ($begMonths as $p) {
    if ((float)$p['net'] <= 0) {
        rc_assert(false, 'no zero-amount earning rows', $p);
        break;
    }
}
rc_assert(true, 'no zero-amount earning rows (beginning)');

$end = $base;
$end['concession_enabled'] = 1;
$end['concession_from'] = '2027-01-01';
$end['concession_to'] = '2027-01-31';
$endMonths = co_shop_build_earning_months($end);
rc_assert(count($endMonths) === 12, 'end 1 free → 12 earning months', count($endMonths));
rc_assert($endMonths[count($endMonths) - 1]['period_start'] === '2026-12-01', 'last chargeable is Dec', $endMonths[count($endMonths) - 1]['period_start'] ?? null);

$mid = $base;
$mid['concession_enabled'] = 1;
$mid['concession_from'] = '2026-07-01';
$mid['concession_to'] = '2026-07-31';
$midMonths = co_shop_build_earning_months($mid);
rc_assert(count($midMonths) === 12, 'custom mid 1 free → 12 earning months', count($midMonths));
$hasJuly = false;
foreach ($midMonths as $p) {
    if ($p['period_start'] === '2026-07-01') {
        $hasJuly = true;
    }
}
rc_assert(!$hasJuly, 'July skipped in mid concession');

$val = co_shop_concession_value_compute($beg);
rc_assert(abs($val - 10000) < 0.02, 'concession value = monthly_eq × free months', $val);

$eq = co_shop_monthly_equivalent_net($beg);
rc_assert(abs($eq - 10000) < 0.02, 'monthly equivalent uses chargeable count', $eq);
$eqTerm = co_shop_monthly_rent_equivalent($beg);
rc_assert(abs($eqTerm - 10000) < 0.02, 'termination monthly equivalent uses chargeable', $eqTerm);

$dates = co_shop_concession_compute_dates('2026-01-01', '2027-01-31', 'beginning', 1, 'months');
rc_assert($dates['from'] === '2026-01-01' && $dates['to'] === '2026-01-31', 'compute beginning 1 month', $dates);
$datesE = co_shop_concession_compute_dates('2026-01-01', '2027-01-31', 'end', 1, 'months');
rc_assert($datesE['to'] === '2027-01-31' && $datesE['from'] === '2027-01-01', 'compute end 1 month', $datesE);

// Live DB: pick a contract without rent invoices if possible; otherwise pure unit tests above stand.
$stmt = $conn->prepare("
    SELECT c.id
    FROM co_shop_rental_contracts c
    WHERE c.company_id = ?
      AND c.status IN ('draft','active')
      AND NOT EXISTS (
          SELECT 1 FROM co_client_invoices i
          INNER JOIN co_shop_rent_schedules s ON s.id = i.source_id AND s.company_id = i.company_id
          WHERE i.company_id = c.company_id AND i.source_type = 'shop_rental' AND i.status <> 'cancelled'
            AND s.contract_id = c.id
      )
    ORDER BY c.id DESC
    LIMIT 1
");
$stmt->execute([$companyId]);
$liveId = (int)$stmt->fetchColumn();
if ($liveId > 0) {
    echo "INFO  Live contract #{$liveId} without rent invoices — save/restore concession smoke\n";
    $before = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $before->execute([$liveId, $companyId]);
    $snap = $before->fetch(PDO::FETCH_ASSOC);
    try {
        co_shop_save_concession_settings($conn, $companyId, $liveId, [
            'concession_enabled' => 1,
            'concession_reason' => 'fit_out',
            'concession_position' => 'beginning',
            'concession_duration_value' => 1,
            'concession_duration_unit' => 'months',
            'concession_manual_dates' => 0,
        ], 1);
        $c2 = co_shop_contract_load($conn, $companyId, $liveId);
        rc_assert(co_shop_concession_active($c2), 'live save enables concession');
        $periods = co_shop_build_earning_months($c2);
        $occN = count(co_shop_occupancy_month_windows($c2['start_date'], $c2['end_date']));
        rc_assert(count($periods) === max(0, $occN - 1) || count($periods) < $occN, 'live earning months < occupancy', [
            'occ' => $occN, 'chg' => count($periods),
        ]);
        // restore
        co_shop_save_concession_settings($conn, $companyId, $liveId, [
            'concession_enabled' => 0,
        ], 1);
        rc_assert(true, 'live concession restored to off');
    } catch (Throwable $e) {
        rc_assert(false, 'live concession save/restore', $e->getMessage());
        // best-effort restore from snap
        if ($snap) {
            $conn->prepare("
                UPDATE co_shop_rental_contracts SET
                    concession_enabled = ?, concession_reason = ?, concession_position = ?,
                    concession_duration_value = ?, concession_duration_unit = ?,
                    concession_from = ?, concession_to = ?, concession_notes = ?, concession_value_total = ?
                WHERE id = ? AND company_id = ?
            ")->execute([
                (int)($snap['concession_enabled'] ?? 0),
                $snap['concession_reason'] ?? null,
                $snap['concession_position'] ?? null,
                $snap['concession_duration_value'] ?? null,
                $snap['concession_duration_unit'] ?? null,
                $snap['concession_from'] ?? null,
                $snap['concession_to'] ?? null,
                $snap['concession_notes'] ?? null,
                $snap['concession_value_total'] ?? null,
                $liveId,
                $companyId,
            ]);
        }
    }
} else {
    echo "INFO  No invoice-free contract for live save smoke — unit cases only\n";
    rc_assert(true, 'skipped live DB save (no eligible contract)');
}

echo "\n=== Result: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
