<?php
/**
 * WPS / payroll deduction guards (shared HR — all companies).
 *
 * Confirmed (session correction):
 * - Each employee must keep at least 85% of run earnings (net).
 * - Equivalently: deductions cannot exceed 15% of run earnings.
 * - For WPS runs: at least 90% of employees in the run must have net_pay > 0.
 *
 * Override (controlled): authorized users may bypass these guards with a mandatory
 * reason + audit trail. Percentages and calculation logic are unchanged.
 */

function hr_wps_max_deduction_ratio(): float
{
    return 0.15;
}

function hr_wps_min_net_earnings_ratio(): float
{
    return 0.85;
}

function hr_wps_min_positive_net_ratio(): float
{
    return 0.90;
}

/**
 * Permission key for payroll validation override (HR module role_modules JSON).
 * Owner/Admin always pass via has_permission() bypass.
 */
function hr_payroll_override_permission_key(): string
{
    return 'payroll.override_validation';
}

/**
 * Whether the current user may override WPS / take-home validation.
 * Server-side gate — never trust UI alone.
 */
function hr_payroll_can_override_validation(?PDO $conn = null): bool
{
    if (!function_exists('has_permission')) {
        require_once __DIR__ . '/../../includes/permissions.php';
    }
    $module = defined('MODULE_HR') ? MODULE_HR : 'hr';
    return has_permission(hr_payroll_override_permission_key(), $module, $conn);
}

/**
 * @param array{base?:float,allowance?:float,bonus?:float,adv?:float,ded?:float,abs?:float,name?:string} $row
 * @return string|null Error message or null if OK
 */
function hr_wps_validate_employee_deduction_cap(array $row): ?string
{
    $earnings = (float)($row['base'] ?? 0) + (float)($row['allowance'] ?? 0) + (float)($row['bonus'] ?? 0);
    $deductions = (float)($row['adv'] ?? 0) + (float)($row['ded'] ?? 0) + (float)($row['abs'] ?? 0);
    $net = $earnings - $deductions;
    $name = (string)($row['name'] ?? 'Employee');

    if ($earnings <= 0.005) {
        if ($deductions > 0.005) {
            return $name . ': cannot apply deductions when run earnings are zero.';
        }
        return null;
    }

    $minNet = round($earnings * hr_wps_min_net_earnings_ratio(), 2);
    $maxDed = round($earnings * hr_wps_max_deduction_ratio(), 2);
    if ($deductions > $maxDed + 0.005 || $net + 0.005 < $minNet) {
        $netPct = round(($net / $earnings) * 100, 1);
        $dedPct = round(($deductions / $earnings) * 100, 1);
        return sprintf(
            '%s: employee must receive at least 85%% of earnings (net AED %0.2f / %.1f%%). Deductions AED %0.2f (%.1f%%) exceed the 15%% cap (max AED %0.2f).',
            $name,
            $net,
            $netPct,
            $deductions,
            $dedPct,
            $maxDed
        );
    }
    return null;
}

/**
 * @param list<array{net:float,name?:string}> $rows
 * @return string|null Error message or null if OK
 */
function hr_wps_validate_company_positive_net_ratio(array $rows, string $payrollType): ?string
{
    if (($payrollType ?: 'wps') !== 'wps') {
        return null;
    }
    $total = count($rows);
    if ($total === 0) {
        return null;
    }
    $positive = 0;
    foreach ($rows as $r) {
        if ((float)($r['net'] ?? 0) > 0.005) {
            $positive++;
        }
    }
    $ratio = $positive / $total;
    $min = hr_wps_min_positive_net_ratio();
    if ($ratio + 0.00001 < $min) {
        $pct = round($ratio * 100, 1);
        return sprintf(
            'WPS company rule: only %d of %d employees (%.1f%%) would receive a salary this run. At least 90%% must receive net pay > 0.',
            $positive,
            $total,
            $pct
        );
    }
    return null;
}

/**
 * Collect all validation failures without throwing.
 *
 * @param list<array{name:string,base:float,allowance:float,bonus:float,adv:float,ded:float,abs:float,net:float}> $rows
 * @return list<array{code:string,message:string}>
 */
function hr_wps_collect_payroll_validation_failures(array $rows, string $payrollType): array
{
    $failures = [];
    foreach ($rows as $row) {
        $err = hr_wps_validate_employee_deduction_cap($row);
        if ($err) {
            $failures[] = [
                'code' => 'employee_min_takehome',
                'message' => $err,
            ];
        }
    }
    $err = hr_wps_validate_company_positive_net_ratio($rows, $payrollType);
    if ($err) {
        $failures[] = [
            'code' => 'wps_positive_net_ratio',
            'message' => $err,
        ];
    }
    return $failures;
}

/**
 * Validate a full draft set before save/post.
 *
 * @param list<array{name:string,base:float,allowance:float,bonus:float,adv:float,ded:float,abs:float,net:float}> $rows
 */
function hr_wps_validate_payroll_rows(array $rows, string $payrollType): void
{
    $failures = hr_wps_collect_payroll_validation_failures($rows, $payrollType);
    if ($failures) {
        $messages = array_map(static fn(array $f): string => (string)$f['message'], $failures);
        throw new RuntimeException(implode(' ', $messages));
    }
}

/**
 * Whether payroll_runs override columns exist.
 */
function hr_payroll_validation_override_schema_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $st = $conn->query("SHOW COLUMNS FROM payroll_runs LIKE 'validation_override'");
        $ready = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Persist override marker on the run (no-op if schema not migrated).
 *
 * @param list<array{code:string,message:string}> $failures
 */
function hr_payroll_store_validation_override(
    PDO $conn,
    int $runId,
    string $reason,
    array $failures,
    int $userId
): void {
    if (!hr_payroll_validation_override_schema_ready($conn)) {
        return;
    }
    $codes = [];
    foreach ($failures as $f) {
        $code = (string)($f['code'] ?? '');
        if ($code !== '' && !in_array($code, $codes, true)) {
            $codes[] = $code;
        }
    }
    $conn->prepare("
        UPDATE payroll_runs
        SET validation_override = 1,
            validation_override_reason = ?,
            validation_override_failures = ?,
            validation_override_by = ?,
            validation_override_at = NOW()
        WHERE id = ?
    ")->execute([
        $reason,
        json_encode([
            'codes' => $codes,
            'failures' => $failures,
        ], JSON_UNESCAPED_UNICODE),
        $userId > 0 ? $userId : null,
        $runId,
    ]);
}

/**
 * Clear override marker when the run passes validation without override.
 */
function hr_payroll_clear_validation_override(PDO $conn, int $runId): void
{
    if (!hr_payroll_validation_override_schema_ready($conn)) {
        return;
    }
    $conn->prepare("
        UPDATE payroll_runs
        SET validation_override = 0,
            validation_override_reason = NULL,
            validation_override_failures = NULL,
            validation_override_by = NULL,
            validation_override_at = NULL
        WHERE id = ?
    ")->execute([$runId]);
}
