<?php
/**
 * Real Estate — Accounting & Payment Database Diagnostic
 * -------------------------------------------------------
 * TEMPORARY FILE — DELETE AFTER USE.
 * Upload to: modules/realestate/accounting/db_diagnostic.php
 * Browse to it, copy the full output, and share it.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_login();

// Only super-admin can run this
$currentUserId = current_user_id();
$companyId = current_company_id($conn) ?: 2;

header('Content-Type: text/plain; charset=utf-8');

function section($title) {
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  $title\n";
    echo str_repeat('=', 70) . "\n";
}

function run($conn, $label, $sql, $params = []) {
    echo "\n-- $label\n";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "(no rows)\n";
            return;
        }
        // Print header
        $cols = array_keys($rows[0]);
        $widths = array_map(fn($c) => max(strlen($c), 10), $cols);
        foreach ($rows as $row) {
            foreach ($cols as $i => $c) {
                $widths[$i] = max($widths[$i], strlen((string)($row[$c] ?? 'NULL')));
            }
        }
        $header = '';
        foreach ($cols as $i => $c) $header .= str_pad($c, $widths[$i] + 2);
        echo $header . "\n" . str_repeat('-', strlen($header)) . "\n";
        foreach ($rows as $row) {
            $line = '';
            foreach ($cols as $i => $c) $line .= str_pad((string)($row[$c] ?? 'NULL'), $widths[$i] + 2);
            echo $line . "\n";
        }
        echo "(" . count($rows) . " row" . (count($rows) !== 1 ? 's' : '') . ")\n";
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "REAL ESTATE DATABASE DIAGNOSTIC\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n";
echo "Company ID: $companyId\n";

// ── 1. TABLE STRUCTURE CHECKS ─────────────────────────────────────────────────
section("1. KEY TABLE COLUMNS");

$tables = [
    're_leases'                  => ['id','lease_number','company_id','deferred_revenue_mode'],
    're_lease_installments'      => ['id','lease_id','company_id','installment_date','amount','status'],
    're_payments'                => ['id','lease_id','installment_id','company_id','amount','payment_date'],
    're_payment_allocations'     => ['id','payment_id','installment_id','amount_allocated','company_id'],
    're_rent_recognition_schedule' => ['id','lease_id','installment_id','deferred_payment_id','status','amount'],
    're_journal_headers'         => ['id','journal_type','reference_type','reference_id','is_posted','is_reversed','company_id'],
];
foreach ($tables as $table => $expectedCols) {
    echo "\n-- $table\n";
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table`");
        $actual = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $missing = array_diff($expectedCols, $actual);
        echo "   Exists: YES | Columns checked: " . implode(', ', $expectedCols) . "\n";
        if ($missing) {
            echo "   *** MISSING COLUMNS: " . implode(', ', $missing) . " ***\n";
        } else {
            echo "   All expected columns present.\n";
        }
    } catch (Throwable $e) {
        echo "   *** TABLE MISSING or ERROR: " . $e->getMessage() . " ***\n";
    }
}

// ── 2. LEASES OVERVIEW ────────────────────────────────────────────────────────
section("2. LEASES — deferred_revenue_mode status");
run($conn, "All leases", "
    SELECT l.id, l.lease_number, l.status, l.deferred_revenue_mode,
           COUNT(DISTINCT li.id) as installments,
           COUNT(DISTINCT p.id)  as payments,
           COUNT(DISTINCT jh.id) as posted_journals,
           COUNT(DISTINCT rrs.id) as schedule_rows
    FROM re_leases l
    LEFT JOIN re_lease_installments li ON li.lease_id = l.id
    LEFT JOIN re_payments p ON p.lease_id = l.id AND p.company_id = l.company_id
    LEFT JOIN re_journal_headers jh ON jh.reference_type = 'payment'
           AND jh.reference_id = p.id AND jh.is_posted = 1 AND jh.company_id = l.company_id
    LEFT JOIN re_rent_recognition_schedule rrs ON rrs.lease_id = l.id
    WHERE l.company_id = ?
    GROUP BY l.id ORDER BY l.id
", [$companyId]);

// ── 3. PAYMENT ALLOCATIONS INTEGRITY ─────────────────────────────────────────
section("3. PAYMENT ALLOCATIONS — double-link detection");
run($conn, "Payments linked via BOTH re_payments.installment_id AND re_payment_allocations", "
    SELECT p.id as payment_id, p.amount as payment_amount, p.installment_id as direct_inst_id,
           pa.installment_id as alloc_inst_id, pa.amount_allocated,
           (p.amount - pa.amount_allocated) as diff
    FROM re_payments p
    JOIN re_payment_allocations pa ON pa.payment_id = p.id
    WHERE p.company_id = ?
      AND p.installment_id IS NOT NULL
      AND p.installment_id = pa.installment_id
    ORDER BY p.id
", [$companyId]);

run($conn, "Payments with installment_id set but NO allocation record", "
    SELECT p.id, p.amount, p.installment_id, p.payment_date
    FROM re_payments p
    WHERE p.company_id = ?
      AND p.installment_id IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM re_payment_allocations pa
          WHERE pa.payment_id = p.id AND pa.installment_id = p.installment_id
      )
    ORDER BY p.id
", [$companyId]);

run($conn, "Payments with allocation but NO direct installment_id", "
    SELECT p.id, p.amount, p.installment_id, pa.installment_id as alloc_inst, pa.amount_allocated
    FROM re_payments p
    JOIN re_payment_allocations pa ON pa.payment_id = p.id
    WHERE p.company_id = ?
      AND p.installment_id IS NULL
    ORDER BY p.id
", [$companyId]);

// ── 4. INSTALLMENT COLLECTED AMOUNTS ─────────────────────────────────────────
section("4. INSTALLMENT COLLECTED — comparing direct vs allocation sources");
run($conn, "Per installment: direct sum vs allocation sum", "
    SELECT li.id as inst_id, li.lease_id, li.installment_date, li.amount as due,
           COALESCE((SELECT SUM(p2.amount) FROM re_payments p2 WHERE p2.installment_id = li.id), 0) as direct_sum,
           COALESCE((SELECT SUM(pa2.amount_allocated) FROM re_payment_allocations pa2 WHERE pa2.installment_id = li.id), 0) as alloc_sum,
           li.status
    FROM re_lease_installments li
    WHERE li.company_id = ?
    ORDER BY li.lease_id, li.installment_date, li.id
", [$companyId]);

// ── 5. JOURNAL HEADERS AUDIT ─────────────────────────────────────────────────
section("5. JOURNAL HEADERS — payment journals state");
run($conn, "All payment-related journals", "
    SELECT jh.id, jh.journal_number, jh.journal_date, jh.journal_type,
           jh.reference_type, jh.reference_id,
           jh.is_posted, jh.is_reversed, jh.reversal_journal_id,
           p.amount as payment_amount, p.lease_id
    FROM re_journal_headers jh
    LEFT JOIN re_payments p ON p.id = jh.reference_id AND jh.reference_type = 'payment'
    WHERE jh.company_id = ?
      AND jh.reference_type = 'payment'
    ORDER BY jh.reference_id, jh.id
", [$companyId]);

run($conn, "Orphaned reversal journals (reversal with no non-reversal sibling)", "
    SELECT jh.id, jh.journal_number, jh.journal_type, jh.reference_id, jh.is_posted, jh.is_reversed
    FROM re_journal_headers jh
    WHERE jh.company_id = ?
      AND jh.journal_type = 'reversal'
      AND jh.reference_type = 'payment'
      AND jh.is_posted = 1
      AND jh.is_reversed = 0
    ORDER BY jh.id
", [$companyId]);

run($conn, "Payments with NO journal at all (accounting never posted)", "
    SELECT p.id, p.amount, p.payment_date, p.lease_id, p.installment_id
    FROM re_payments p
    WHERE p.company_id = ?
      AND NOT EXISTS (
          SELECT 1 FROM re_journal_headers jh
          WHERE jh.reference_type = 'payment' AND jh.reference_id = p.id
            AND jh.is_posted = 1 AND jh.company_id = p.company_id
      )
    ORDER BY p.id
", [$companyId]);

// ── 6. REVENUE RECOGNITION SCHEDULE ──────────────────────────────────────────
section("6. REVENUE RECOGNITION SCHEDULE");
run($conn, "All recognition rows", "
    SELECT rrs.id, rrs.lease_id, rrs.installment_id, rrs.recognition_date,
           rrs.amount, rrs.status, rrs.deferred_payment_id,
           rrs.recognition_journal_id
    FROM re_rent_recognition_schedule rrs
    WHERE rrs.company_id = ?
    ORDER BY rrs.lease_id, rrs.recognition_date
", [$companyId]);

run($conn, "Pending rows with NO deferred_payment_id (no payment recorded yet)", "
    SELECT rrs.lease_id, l.lease_number, rrs.installment_id, rrs.recognition_date, rrs.amount
    FROM re_rent_recognition_schedule rrs
    JOIN re_leases l ON l.id = rrs.lease_id
    WHERE rrs.company_id = ? AND rrs.status = 'pending' AND rrs.deferred_payment_id IS NULL
    ORDER BY rrs.lease_id, rrs.recognition_date
", [$companyId]);

run($conn, "Pending rows WITH deferred_payment_id (ready to recognize)", "
    SELECT rrs.lease_id, l.lease_number, rrs.installment_id, rrs.recognition_date,
           rrs.amount, rrs.deferred_payment_id
    FROM re_rent_recognition_schedule rrs
    JOIN re_leases l ON l.id = rrs.lease_id
    WHERE rrs.company_id = ? AND rrs.status = 'pending' AND rrs.deferred_payment_id IS NOT NULL
    ORDER BY rrs.lease_id, rrs.recognition_date
", [$companyId]);

// ── 7. CHART OF ACCOUNTS — key accounts ──────────────────────────────────────
section("7. CHART OF ACCOUNTS — key real estate accounts");
run($conn, "RE accounts (1xxx, 2xxx, 4xxx)", "
    SELECT account_code, account_name, account_type, normal_balance, is_active
    FROM re_chart_of_accounts
    WHERE company_id = ?
      AND (account_code LIKE '1%' OR account_code LIKE '2%' OR account_code LIKE '4%')
    ORDER BY account_code
", [$companyId]);

echo "\n" . str_repeat('=', 70) . "\n";
echo "  DIAGNOSTIC COMPLETE — please copy ALL of the above and share it.\n";
echo str_repeat('=', 70) . "\n";
