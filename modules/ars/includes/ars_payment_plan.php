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

/**
 * Corrections the office has made to single months of a plan.
 *
 * The schedule is still rebuilt from the stay every time; a row here only
 * pins one month's date, amount or status (NULL = leave it as worked out).
 * seq is the month's position counted from check-in, so a correction stays on
 * its month when the stay is extended.
 */
function ars_ensure_payment_plan_instalment_table(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS ars_booking_payment_plan_instalments (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id INT NOT NULL,
                company_id INT NOT NULL,
                seq        INT NOT NULL,
                due_date   DATE NULL DEFAULT NULL,
                amount     DECIMAL(12,2) NULL DEFAULT NULL,
                status     VARCHAR(12) NULL DEFAULT NULL,
                updated_by INT NULL DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_plan_instalment (booking_id, company_id, seq)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('ARS payment plan instalment table: ' . $e->getMessage());
    }
}

/** The statuses a month can be pinned to. */
function ars_payment_plan_instalment_statuses(): array {
    return ['paid', 'due', 'overdue', 'upcoming'];
}

/** @return array<int,array{due_date:?string,amount:?float,status:?string}> keyed by seq */
function ars_payment_plan_overrides(PDO $conn, int $companyId, int $bookingId): array {
    ars_ensure_payment_plan_instalment_table($conn);
    $out = [];
    try {
        $st = $conn->prepare("
            SELECT seq, due_date, amount, status
            FROM ars_booking_payment_plan_instalments
            WHERE booking_id = ? AND company_id = ?
        ");
        $st->execute([$bookingId, $companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['seq']] = [
                'due_date' => $row['due_date'] !== null ? (string)$row['due_date'] : null,
                'amount' => $row['amount'] !== null ? (float)$row['amount'] : null,
                'status' => $row['status'] !== null && $row['status'] !== '' ? (string)$row['status'] : null,
            ];
        }
    } catch (Throwable $e) {
        error_log('ARS payment plan overrides: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Pin one month of the plan. A null field goes back to being worked out, and
 * a row with nothing pinned is removed.
 */
function ars_payment_plan_instalment_save(PDO $conn, int $companyId, int $bookingId, int $seq, ?string $dueDate, ?float $amount, ?string $status, int $userId): array {
    if ($seq < 0) {
        return ['success' => false, 'error' => 'Month not found on this plan.'];
    }
    if ($dueDate !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $dueDate);
        if (!$d || $d->format('Y-m-d') !== $dueDate) {
            return ['success' => false, 'error' => 'Choose a valid due date.'];
        }
    }
    if ($amount !== null && $amount < 0) {
        return ['success' => false, 'error' => 'The amount cannot be negative.'];
    }
    if ($status !== null && !in_array($status, ars_payment_plan_instalment_statuses(), true)) {
        return ['success' => false, 'error' => 'Unknown status.'];
    }
    ars_ensure_payment_plan_instalment_table($conn);
    if ($dueDate === null && $amount === null && $status === null) {
        $conn->prepare("DELETE FROM ars_booking_payment_plan_instalments WHERE booking_id = ? AND company_id = ? AND seq = ?")
            ->execute([$bookingId, $companyId, $seq]);
        return ['success' => true, 'reset' => true];
    }
    $conn->prepare("
        INSERT INTO ars_booking_payment_plan_instalments (booking_id, company_id, seq, due_date, amount, status, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE due_date = VALUES(due_date), amount = VALUES(amount), status = VALUES(status),
                                updated_by = VALUES(updated_by), updated_at = NOW()
    ")->execute([$bookingId, $companyId, $seq, $dueDate, $amount !== null ? round($amount, 2) : null, $status, $userId ?: null]);
    return ['success' => true, 'reset' => false];
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
 * $originalStay, when the stay has been extended, is ['end' => date the
 * original stay ran to, 'amount' => what it was invoiced at]. The months inside
 * the original stay then share that amount and the months after it share the
 * rest, so a first month that was booked and paid at its own price is not
 * shown short against the plan amount agreed for the extension.
 *
 * $overrides are the office's corrections to single months (see
 * ars_payment_plan_overrides()). A pinned amount is taken as it is and the
 * other months of the same budget share what is left, so the months still come
 * to the total. A pinned status replaces the worked-out one: "paid" stops the
 * month being asked for, without recording any money.
 *
 * Returns instalments (seq, due_date, amount, paid, unpaid, state: paid | due |
 * overdue | upcoming, edited) plus due_now, overdue, next_due_date, next_due_amount.
 */
function ars_payment_plan_schedule(array $plan, string $checkIn, string $checkOut, float $stayTotal, float $balanceDue, ?string $today = null, ?array $originalStay = null, array $overrides = []): array {
    $today = $today ?: date('Y-m-d');
    $monthly = max(0.0, (float)($plan['monthly_amount'] ?? 0));
    $dates = ars_payment_plan_due_dates($checkIn, $checkOut);
    if (!$dates) {
        $dates = [$today];
    }
    $stayTotal = max(0.0, round($stayTotal, 2));
    $received = max(0.0, round($stayTotal - max(0.0, $balanceDue), 2));

    // Each due date is cut from a budget: the whole stay, or — once extended —
    // the original stay's amount for the months inside it and the rest for
    // the months after. The last date of a budget takes what is left of it.
    $budgets = [];
    $originalEnd = substr((string)($originalStay['end'] ?? ''), 0, 10);
    $originalAmount = round((float)($originalStay['amount'] ?? 0), 2);
    $inOriginal = $originalEnd !== '' ? count(array_filter($dates, static fn($d) => $d < $originalEnd)) : 0;
    if ($inOriginal > 0 && $inOriginal < count($dates) && $originalAmount > 0 && $originalAmount < $stayTotal) {
        $budgets[] = [$inOriginal - 1, $originalAmount];
        $budgets[] = [count($dates) - 1, round($stayTotal - $originalAmount, 2)];
    } else {
        $budgets[] = [count($dates) - 1, $stayTotal];
    }

    // Per budget: what its pinned months already take, and which unpinned
    // month is the last one (it takes whatever the budget has left).
    $budgetOf = [];
    $pinned = [];
    $lastFree = [];
    $b = 0;
    foreach ($dates as $i => $due) {
        if ($i > $budgets[$b][0]) {
            $b++;
        }
        $budgetOf[$i] = $b;
        if (($overrides[$i]['amount'] ?? null) !== null) {
            $pinned[$b] = ($pinned[$b] ?? 0.0) + (float)$overrides[$i]['amount'];
        } else {
            $lastFree[$b] = $i;
        }
    }

    $instalments = [];
    $allocated = [];
    foreach ($dates as $i => $due) {
        $b = $budgetOf[$i];
        $ov = $overrides[$i] ?? null;
        if ($ov !== null && $ov['amount'] !== null) {
            $amount = max(0.0, round((float)$ov['amount'], 2));
        } else {
            $left = round($budgets[$b][1] - ($pinned[$b] ?? 0.0) - ($allocated[$b] ?? 0.0), 2);
            $amount = $i === ($lastFree[$b] ?? -1) ? $left : min($monthly, $left);
            $amount = max(0.0, round($amount, 2));
            $allocated[$b] = ($allocated[$b] ?? 0.0) + $amount;
        }
        if ($amount <= 0.009 && $i !== 0 && $ov === null) {
            continue;
        }
        if ($ov !== null && $ov['due_date'] !== null) {
            $due = $ov['due_date'];
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
        if ($ov !== null && $ov['status'] !== null) {
            $state = $ov['status'];
            if ($state === 'paid') {
                $unpaid = 0.0;
            }
        }
        $instalments[] = [
            'seq' => $i,
            'due_date' => $due,
            'amount' => $amount,
            'paid' => round($paid, 2),
            'unpaid' => max(0.0, $unpaid),
            'state' => $state,
            'edited' => $ov !== null,
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
    // The months' own corrections belong to the plan and go with it.
    ars_ensure_payment_plan_instalment_table($conn);
    $conn->prepare("DELETE FROM ars_booking_payment_plan_instalments WHERE booking_id = ? AND company_id = ?")
        ->execute([$bookingId, $companyId]);
    return $stmt->rowCount() > 0;
}
