<?php
/**
 * Monthly payment plans.
 *
 * A guest on a long stay may pay month by month. The invoice, AR and journals
 * still carry the whole stay — the guest owes all of it — so a plan changes
 * nothing in accounting. It only decides how much of the open balance is due
 * today: a month is due on its date, and only an unpaid month whose date has
 * passed is overdue.
 *
 * The plan stores the monthly amount only. The instalments are rebuilt from the
 * stay dates every time, so an extension or a changed total re-shapes the
 * schedule by itself: the months run from check-in, one per calendar month
 * before check-out, and the last month takes whatever is left.
 */

function ars_ensure_payment_plan_table(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS ars_booking_payment_plans (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id     INT NOT NULL,
                company_id     INT NOT NULL,
                plan_type      VARCHAR(20) NOT NULL DEFAULT 'monthly',
                monthly_amount DECIMAL(12,2) NOT NULL,
                created_by     INT NULL DEFAULT NULL,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by     INT NULL DEFAULT NULL,
                updated_at     DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_payment_plan_booking (booking_id, company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('ARS payment plan table: ' . $e->getMessage());
    }
}

function ars_payment_plan_get(PDO $conn, int $companyId, int $bookingId): ?array {
    $plans = ars_payment_plans_for_bookings($conn, $companyId, [$bookingId]);
    return $plans[$bookingId] ?? null;
}

/** Plans keyed by booking id, for list pages. Bookings without one are absent. */
function ars_payment_plans_for_bookings(PDO $conn, int $companyId, array $bookingIds): array {
    $bookingIds = array_values(array_unique(array_filter(array_map('intval', $bookingIds))));
    if (!$bookingIds) {
        return [];
    }
    ars_ensure_payment_plan_table($conn);
    $out = [];
    try {
        $in = implode(',', array_fill(0, count($bookingIds), '?'));
        $stmt = $conn->prepare("
            SELECT booking_id, plan_type, monthly_amount
            FROM ars_booking_payment_plans
            WHERE company_id = ? AND booking_id IN ($in)
        ");
        $stmt->execute(array_merge([$companyId], $bookingIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['booking_id']] = $row;
        }
    } catch (Throwable $e) {
        error_log('ARS payment plans: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Due dates: check-in, then the same day of each following month while it is
 * still before check-out. A 31st falls back to the month's last day.
 */
function ars_payment_plan_due_dates(string $checkIn, string $checkOut): array {
    $checkIn = substr($checkIn, 0, 10);
    $checkOut = substr($checkOut, 0, 10);
    if ($checkIn === '' || $checkOut === '' || $checkOut <= $checkIn) {
        return $checkIn !== '' ? [$checkIn] : [];
    }
    [$y, $m, $d] = array_map('intval', explode('-', $checkIn));
    $dates = [];
    for ($n = 0; $n < 120; $n++) {
        $mm = $m + $n;
        $yy = $y + intdiv($mm - 1, 12);
        $mm = (($mm - 1) % 12) + 1;
        $day = min($d, (int)date('t', mktime(0, 0, 0, $mm, 1, $yy)));
        $due = sprintf('%04d-%02d-%02d', $yy, $mm, $day);
        if ($due >= $checkOut) {
            break;
        }
        $dates[] = $due;
    }
    return $dates;
}

/** What each month comes to when the plan is first offered: total ÷ months. */
function ars_payment_plan_default_monthly(string $checkIn, string $checkOut, float $stayTotal): float {
    $count = max(1, count(ars_payment_plan_due_dates($checkIn, $checkOut)));
    return round($stayTotal / $count, 2);
}

/**
 * The schedule, with received money applied to the earliest months first.
 *
 * $stayTotal and $balanceDue are whatever the caller already shows as the
 * total and the open balance, so what is received is exactly their difference
 * and the plan can never disagree with the figures beside it.
 *
 * Returns instalments (due_date, amount, paid, unpaid, state: paid | due |
 * overdue | upcoming) plus due_now, overdue, next_due_date, next_due_amount.
 */
function ars_payment_plan_schedule(array $plan, string $checkIn, string $checkOut, float $stayTotal, float $balanceDue, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $monthly = max(0.0, (float)($plan['monthly_amount'] ?? 0));
    $dates = ars_payment_plan_due_dates($checkIn, $checkOut);
    if (!$dates) {
        $dates = [$today];
    }
    $stayTotal = max(0.0, round($stayTotal, 2));
    $received = max(0.0, round($stayTotal - max(0.0, $balanceDue), 2));

    $instalments = [];
    $allocated = 0.0;
    $last = count($dates) - 1;
    foreach ($dates as $i => $due) {
        $left = round($stayTotal - $allocated, 2);
        $amount = $i === $last ? $left : min($monthly, $left);
        $amount = max(0.0, round($amount, 2));
        $allocated += $amount;
        if ($amount <= 0.009 && $i !== 0) {
            continue;
        }
        $paid = min($amount, $received);
        $received = round($received - $paid, 2);
        $unpaid = round($amount - $paid, 2);
        if ($unpaid <= 0.009) {
            $state = 'paid';
        } elseif ($due < $today) {
            $state = 'overdue';
        } elseif ($due === $today) {
            $state = 'due';
        } else {
            $state = 'upcoming';
        }
        $instalments[] = [
            'due_date' => $due,
            'amount' => $amount,
            'paid' => round($paid, 2),
            'unpaid' => max(0.0, $unpaid),
            'state' => $state,
        ];
    }

    $dueNow = 0.0;
    $overdue = 0.0;
    $nextDate = null;
    $nextAmount = 0.0;
    foreach ($instalments as $inst) {
        if ($inst['state'] === 'overdue') {
            $overdue += $inst['unpaid'];
            $dueNow += $inst['unpaid'];
        } elseif ($inst['state'] === 'due') {
            $dueNow += $inst['unpaid'];
        } elseif ($inst['state'] === 'upcoming' && $nextDate === null) {
            $nextDate = $inst['due_date'];
            $nextAmount = $inst['unpaid'];
        }
    }

    return [
        'monthly_amount' => $monthly,
        'instalments' => $instalments,
        'due_now' => round($dueNow, 2),
        'overdue' => round($overdue, 2),
        'next_due_date' => $nextDate,
        'next_due_amount' => round($nextAmount, 2),
    ];
}

/**
 * Due-now figure for a list row. No plan: the balance itself, as before.
 */
function ars_payment_plan_row_due_now(?array $plan, array $bookingRow, ?string $today = null): float {
    $balance = max(0.0, (float)($bookingRow['balance_due'] ?? 0));
    if (!$plan || $balance <= 0.009) {
        return $balance;
    }
    $sched = ars_payment_plan_schedule(
        $plan,
        (string)($bookingRow['check_in'] ?? ''),
        (string)($bookingRow['check_out'] ?? ''),
        (float)($bookingRow['total_amount'] ?? 0),
        $balance,
        $today
    );
    return $sched['due_now'];
}

/**
 * Save (or change) the monthly amount. Refused once a month has been paid in
 * full under the current plan, so a settled month is never re-cut; switching
 * back to Full is always allowed.
 */
function ars_payment_plan_save(PDO $conn, int $companyId, int $bookingId, float $monthlyAmount, int $userId): array {
    $monthlyAmount = round($monthlyAmount, 2);
    if ($monthlyAmount <= 0) {
        return ['success' => false, 'error' => 'Enter the monthly amount.'];
    }
    ars_ensure_payment_plan_table($conn);
    $existing = ars_payment_plan_get($conn, $companyId, $bookingId);
    if ($existing) {
        if (abs((float)$existing['monthly_amount'] - $monthlyAmount) < 0.005) {
            return ['success' => true, 'changed' => false];
        }
        $recv = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM ars_booking_payments WHERE booking_id = ? AND company_id = ?");
        $recv->execute([$bookingId, $companyId]);
        if ((float)$recv->fetchColumn() >= (float)$existing['monthly_amount'] - 0.009) {
            return ['success' => false, 'error' => 'A month is already paid, so the monthly amount can no longer be changed. Switch to Full and set the plan again if it was entered wrong.'];
        }
        $conn->prepare("
            UPDATE ars_booking_payment_plans
            SET monthly_amount = ?, updated_by = ?, updated_at = NOW()
            WHERE booking_id = ? AND company_id = ?
        ")->execute([$monthlyAmount, $userId ?: null, $bookingId, $companyId]);
        return ['success' => true, 'changed' => true, 'previous' => (float)$existing['monthly_amount']];
    }
    $conn->prepare("
        INSERT INTO ars_booking_payment_plans (booking_id, company_id, plan_type, monthly_amount, created_by)
        VALUES (?, ?, 'monthly', ?, ?)
    ")->execute([$bookingId, $companyId, $monthlyAmount, $userId ?: null]);
    return ['success' => true, 'changed' => true, 'previous' => null];
}

function ars_payment_plan_clear(PDO $conn, int $companyId, int $bookingId): bool {
    ars_ensure_payment_plan_table($conn);
    $stmt = $conn->prepare("DELETE FROM ars_booking_payment_plans WHERE booking_id = ? AND company_id = ?");
    $stmt->execute([$bookingId, $companyId]);
    return $stmt->rowCount() > 0;
}
