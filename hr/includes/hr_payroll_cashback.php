<?php
/**
 * Payroll cashback — an amount the employee hands back after being paid.
 *
 * DISPLAY ONLY. Net pay stays what WPS pays: payroll_items.net_pay, the WPS
 * file, the 85% / 90% guards and the accounting journal never read cashback.
 * It is an extra line under Net — "Less: Cashback" and "Net after cashback" —
 * on the run and the payslip, and only while the run's "Show cashback" toggle
 * (payroll_runs.show_cashback) is on. With the toggle off, saved amounts are
 * kept but nothing shows them.
 *
 * Off until migrations/hr_payroll_cashback.sql has run.
 */

/** Whether both cashback columns exist. */
function hr_payroll_cashback_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->query("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND ((TABLE_NAME = 'payroll_runs'  AND COLUMN_NAME = 'show_cashback')
                OR (TABLE_NAME = 'payroll_items' AND COLUMN_NAME = 'cashback'))
        ");
        $ready = ((int)$stmt->fetchColumn() === 2);
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** Whether a run (a payroll_runs row) has the toggle on. */
function hr_payroll_cashback_on(PDO $conn, array $run): bool
{
    return hr_payroll_cashback_ready($conn) && !empty($run['show_cashback']);
}

/** The note shown wherever cashback is. */
function hr_payroll_cashback_note(): string
{
    return 'Cashback is shown for information only. Net pay, the WPS file and accounting use Net, before cashback.';
}
