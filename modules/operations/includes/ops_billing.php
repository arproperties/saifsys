<?php
/**
 * Operations — a finished job makes its work order; the office invoices it.
 *
 * HOW IT WORKS
 * ------------
 * Finish on the phone writes the work order the office would have typed in the
 * old module (make_order), already completed, with the client, the place, the
 * person and the hours the app measured. It is NOT invoiced here. The order
 * waits in the old Work Orders list under "Completed", where an Admin or
 * Accountant checks it — fixes the hours of a job left running, for one — and
 * presses Finalize (wo_finalize_work_order), exactly as before. That is what
 * makes the invoice, its receivable and its GL journal.
 *
 * The job shows "Waiting for Finalize" until then, and the invoice after,
 * read from the order's own invoice_id (ops_bill_summary), so it does not
 * matter which old screen did the finalizing.
 *
 * Until 2026-09-22 Finish finalized the order itself. The user moved it back to
 * the office: cleaners were leaving jobs running for days, and a job finished
 * late would have invoiced the full 10-hour cap with nobody looking.
 *
 * WHAT GETS A WORK ORDER
 * ----------------------
 *   - Cleaning jobs a staff member raised in a building. Billed to the building's
 *     client, at that client's hourly rate and VAT, for the time the app
 *     measured, up to 10 hours. The client is the one set on
 *     modules/operations/billing.php, or, when none is set, the client whose
 *     name is the building's landlord — see ops_bill_client_for_building().
 *   - Maintenance requests, the same way — the building's client, its
 *     rate and VAT — but never more than 2 hours. A tenant callout is a visit
 *     to one flat, and the owner is not billed a day because a technician left
 *     the job open.
 *   - ARS checkouts, from the work order the checkout already made in the old
 *     module. Finish marks that order completed — its own fee, its own client —
 *     so there is one order, never a second (ops_bill_ars_checkout). A checkout
 *     cleaned through the old module is only linked — see
 *     ops_finalize_old_module_checkouts().
 *   - Customer-app bookings, at the price the customer booked
 *     (ops_bill_customer_booking).
 *   - Nothing else, deliberately:
 *       maintenance jobs staff raise themselves — the old cleaning module only
 *                           ever billed cleaning;
 *       tenant cleaning   — the tenant paid for the booking when they made it,
 *                           so invoicing it again would charge the work twice;
 *       move-out cleaning — never billed.
 *
 * A job that is not invoiced still says why, on the job, in words — so "why is
 * there no invoice for yesterday's lobby" has an answer without anybody
 * reading this file.
 *
 * NEVER BLOCKS FINISH
 * -------------------
 * The work order is written after the job is already marked done. If that
 * fails, the job is still finished, the reason is recorded as `failed`, and the
 * office sees a Retry button on the job. A cleaner is never told their work did not count
 * because an account code was missing.
 */

require_once dirname(__DIR__, 3) . '/includes/work_order_financial_guard.php';
require_once dirname(__DIR__, 3) . '/includes/ar_helpers.php';
require_once __DIR__ . '/ops_sources.php';

/** Measured time is billed in half hours, always rounded up. */
const OPS_BILL_STEP_MINUTES = 30;

/**
 * No single job bills more than this.
 *
 * The timer is only as good as the Finish tap. A cleaner who forgets it and
 * finishes from home the next morning would otherwise send a sixteen-hour
 * invoice to a sister company. Ten hours covers a full shift and nothing that
 * is plainly a mistake.
 */
const OPS_BILL_MAX_HOURS = 10.0;

/** A tenant maintenance request bills at most this — see the header. */
const OPS_BILL_MAX_HOURS_TENANT = 2.0;

/**
 * Minutes on the clock to hours on the invoice.
 *
 * Rounded up to the next half hour, never less than half an hour — a job that
 * was done took some time even when Start and Finish were tapped a minute
 * apart — and never more than the cap.
 *
 *     2h 07m → 2.5 h        0h 04m → 0.5 h        14h 30m → 10 h (2 h for a tenant)
 */
function ops_billable_hours(int $minutes, float $maxHours = OPS_BILL_MAX_HOURS): float
{
    $steps = max(1, (int)ceil(max(0, $minutes) / OPS_BILL_STEP_MINUTES));
    $hours = $steps * OPS_BILL_STEP_MINUTES / 60;
    return (float)min($hours, $maxHours);
}

/**
 * Write the work order for a finished job, once.
 *
 * Safe to call again for the same job: a job that already produced a work
 * order is left exactly as it is. That is what makes the web Retry button and
 * a replayed Finish from the phone harmless.
 *
 * @return array{status:string, note:?string, invoice_id:?int}
 */
function ops_bill_finished_job(PDO $conn, int $jobId): array
{
    $stmt = $conn->prepare("SELECT * FROM ops_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        return ['status' => 'failed', 'note' => 'Job not found.', 'invoice_id' => null];
    }

    // An ARS checkout or customer booking can be linked to its order before
    // that order is invoiced, so for them a link is not the end: their own
    // function decides, and is safe to run again.
    $sourceType = (string)($job['source_type'] ?? 'staff');
    if ((int)($job['order_id'] ?? 0) > 0 && !in_array($sourceType, ['ars_checkout', 'customer_booking'], true)) {
        return ['status' => (string)($job['billing_status'] ?: 'awaiting_finalize'), 'note' => null, 'invoice_id' => (int)$job['invoice_id'] ?: null];
    }
    if ($job['status'] !== 'done') {
        return ['status' => 'failed', 'note' => 'Only a finished job can be invoiced.', 'invoice_id' => null];
    }

    // --- Not billable, by the same rules the old modules followed ----------
    if ($sourceType === 'tenant_cleaning') {
        return ops_bill_record($conn, $jobId, 'not_billable', 'Tenant cleaning — paid by the tenant when booked.');
    }
    // ARS checkout already has its work order in the old module; that one is
    // completed rather than writing a second.
    if ($sourceType === 'ars_checkout') {
        return ops_bill_ars_checkout($conn, $job);
    }
    if ($sourceType === 'customer_booking') {
        return ops_bill_customer_booking($conn, $job);
    }
    // Move-out cleaning has never been billed to anyone.
    if ($sourceType === 'tenant_move_out') {
        return ops_bill_record($conn, $jobId, 'not_billable', 'Move-out cleaning — not invoiced.');
    }
    $isTenant = $sourceType === 'tenant_maintenance';
    if (!$isTenant && $job['job_type'] !== 'cleaning') {
        return ops_bill_record($conn, $jobId, 'not_billable', 'Maintenance jobs are not invoiced.');
    }

    // --- Who pays: the client set for the building ---------------------------
    $places = ops_job_places($conn, [$jobId])[$jobId] ?? [];
    if (!$places) {
        return ops_bill_record($conn, $jobId, 'no_client', 'This job has no place, so there is no building to bill.');
    }

    // The first place decides. A job spanning two buildings with two different
    // clients is not something the old module could express either, and
    // splitting one visit across two invoices by guesswork would be worse than
    // billing it once and saying so.
    $buildingId = (int)$places[0]['building_id'];
    $client = ops_bill_client_for_building($conn, $buildingId);
    if (!$client) {
        $name = ops_bill_building_name($conn, $buildingId);
        return ops_bill_record(
            $conn, $jobId, 'no_client',
            'No client is set for ' . ($name ?: 'this building') . '. Set one under Billing, then retry.'
        );
    }

    $rate = round((float)($client['rate'] ?? 0), 2);
    if ($rate <= 0) {
        return ops_bill_record(
            $conn, $jobId, 'no_client',
            $client['client_name'] . ' has no hourly rate. Add one to the client, then retry.'
        );
    }

    // --- The amount -------------------------------------------------------------
    $minutes = (int)($job['duration_minutes'] ?? 0);
    $hours = ops_billable_hours($minutes, $isTenant ? OPS_BILL_MAX_HOURS_TENANT : OPS_BILL_MAX_HOURS);
    $vatRate = $client['default_vat_rate'] !== null ? (float)$client['default_vat_rate'] : 5.0;
    // VAT on top of the rate, the way every active client's orders were billed:
    // 16 h × 30 = 480.00 + 24.00 VAT = 504.00.
    $subtotal = round($hours * $rate, 2);
    $vat = round($subtotal * $vatRate / 100, 2);
    $grand = round($subtotal + $vat, 2);

    $staffName = ops_bill_person_name($conn, (int)($job['assigned_to'] ?? 0));
    $where = implode(' + ', array_map(static fn(array $p): string => (string)$p['label'], $places));

    $startedAt = (string)($job['started_at'] ?: $job['finished_at']);
    $finishedAt = (string)$job['finished_at'];
    $serviceDate = substr($startedAt, 0, 10) ?: date('Y-m-d');
    $startClock = substr($startedAt, 11, 5);
    $endClock = substr($finishedAt, 11, 5);

    $ownsTxn = !$conn->inTransaction();
    if ($ownsTxn) {
        $conn->beginTransaction();
    }

    try {
        // The work order the office would have typed in operation/order_add.php,
        // already complete. Columns the old table requires but a finished job
        // has no use for — driver, balance — are written empty, as they were
        // for any order without them.
        $ins = $conn->prepare("
            INSERT INTO make_order
                (company_id, client_id, client_name, worker_name, email_o, address_o, mobile_num_o,
                 fee_charged, hourly_rate, payment, date, service_date, time, start_time, end_time,
                 hours, total, balance, need_materials, remark, driver_name,
                 net_hours, net_amount, amount_afc, discount_amount,
                 vat_rate, vat_amount, grand_total, status, payment_status,
                 created_at, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?, ?, ?,
                 ?, ?, 0.00, 0, ?, '',
                 ?, ?, ?, 0.00,
                 ?, ?, ?, 'completed', 'unpaid',
                 ?, ?)
        ");
        $ins->execute([
            (int)$client['company_id'], (int)$client['id'], (string)$client['client_name'],
            mb_substr($staffName, 0, 50), (string)($client['email'] ?? ''), $where,
            mb_substr((string)($client['mobile_num'] ?? ''), 0, 50),
            $rate, $rate, mb_substr((string)($client['terms'] ?: 'cash'), 0, 15),
            $serviceDate, $serviceDate,
            mb_substr($startClock . ' To ' . $endClock, 0, 50),
            $startClock !== '' ? $startClock . ':00' : null,
            $endClock !== '' ? $endClock . ':00' : null,
            $hours, $subtotal,
            mb_substr('From Operations job #' . $jobId . ($isTenant ? ' (tenant request #' . (int)$job['source_id'] . ')' : '')
                . ' — ' . ops_format_duration($minutes) . ' on the app timer', 0, 900),
            $hours, $subtotal, $subtotal,
            $vatRate, $vat, $grand,
            date('Y-m-d H:i:s'), $job['assigned_to'] ?: null,
        ]);
        $orderId = (int)$conn->lastInsertId();

        // Who did it, where the old module can see it — HR performance reads
        // order_workers. Only possible for someone who is also on the old
        // workers list; the name on the order covers everyone else.
        $workerId = ops_bill_worker_id($conn, (int)($job['assigned_to'] ?? 0));
        if ($workerId > 0) {
            $conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?, ?)")
                 ->execute([$orderId, $workerId]);
        }

        // No invoice here. The order waits, completed and unlocked, in the old
        // work order list, where the office checks the hours and presses
        // Finalize — see the header.
        $conn->prepare("
            UPDATE ops_jobs
            SET billing_status = 'awaiting_finalize', billing_note = NULL, billed_hours = ?,
                order_id = ?, invoice_id = NULL
            WHERE id = ? AND order_id IS NULL
        ")->execute([$hours, $orderId, $jobId]);

        require_once dirname(__DIR__, 3) . '/includes/AuditService.php';
        AuditService::logCreate('make_order', $orderId, [
            'ops_job_id' => $jobId,
            'client_id' => (int)$client['id'],
            'hours' => $hours,
            'grand_total' => $grand,
        ], "Operations job #{$jobId} finished — work order #{$orderId} created, waiting for Finalize",
            $job['assigned_to'] ? (int)$job['assigned_to'] : null);

        if ($ownsTxn) {
            $conn->commit();
        }

        return ['status' => 'awaiting_finalize', 'note' => null, 'invoice_id' => null];
    } catch (Throwable $e) {
        if ($ownsTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('ops_bill_finished_job #' . $jobId . ' failed: ' . $e->getMessage());
        return ops_bill_record($conn, $jobId, 'failed', mb_substr('Work order not created: ' . $e->getMessage(), 0, 255));
    }
}

/**
 * The old module's work order list, showing the completed orders that are not
 * finalized yet — where the office checks and presses Finalize.
 */
function ops_bill_finalize_list_url(string $appBase): string
{
    return $appBase . '/operation?tab=workorder&ops_filter=completed&search=&date=&date_from=&date_to=&status_filter=';
}

/** Work orders from finished jobs that nobody has finalized yet. */
function ops_bill_awaiting_finalize_count(PDO $conn, array $companyIds): int
{
    if (!$companyIds) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM ops_jobs j
        JOIN make_order o ON o.id = j.order_id
        WHERE j.billing_status = 'awaiting_finalize'
          AND j.company_id IN ($in)
          AND o.invoice_id IS NULL
          AND o.status <> 'cancelled'
    ");
    $stmt->execute(array_values($companyIds));
    return (int)$stmt->fetchColumn();
}

/** Mark an old-module work order done, only forward. */
function ops_bill_complete_order(PDO $conn, int $orderId): void
{
    $conn->prepare("
        UPDATE make_order SET status = 'completed', updated_at = NOW()
        WHERE id = ? AND status IN ('draft', 'scheduled', 'confirmed', 'in_progress')
    ")->execute([$orderId]);
    wo_sync_ops_status_column($conn, $orderId, 'completed');
}

/**
 * Tie a job to the order it was invoiced from, or failed to be.
 *
 * @return array{status:string, note:?string, invoice_id:?int}
 */
function ops_bill_link(PDO $conn, int $jobId, int $orderId, string $status, ?string $note, ?int $invoiceId): array
{
    $conn->prepare("
        UPDATE ops_jobs
        SET billing_status = ?, billing_note = ?, order_id = ?, invoice_id = ?
        WHERE id = ? AND (order_id IS NULL OR order_id = ?)
    ")->execute([$status, $note, $orderId, $invoiceId, $jobId, $orderId]);
    return ['status' => $status, 'note' => $note, 'invoice_id' => $invoiceId];
}

/**
 * An ARS checkout job uses the ARS work order, never a new one.
 *
 * Checkout still makes its make_order in the old module (ars_cleaning_trigger),
 * and that order carries the fee and the client. Writing a second order from
 * the job would mean two invoices for one clean. So Finish marks that same
 * order completed and links it; the office finalizes it. Safe to run again,
 * which is what makes Retry work.
 */
function ops_bill_ars_checkout(PDO $conn, array $job): array
{
    $jobId = (int)$job['id'];

    // The order this job is already tied to, on a retry; otherwise the
    // checkout's live one.
    if ((int)($job['order_id'] ?? 0) > 0) {
        $stmt = $conn->prepare("SELECT id FROM make_order WHERE id = ?");
        $stmt->execute([(int)$job['order_id']]);
    } else {
        $stmt = $conn->prepare("
            SELECT id FROM make_order
            WHERE ars_booking_id = ? AND status <> 'cancelled'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([(int)$job['source_id']]);
    }
    $orderId = (int)$stmt->fetchColumn();
    if ($orderId <= 0) {
        return ops_bill_record($conn, $jobId, 'not_billable', 'No ARS work order for this checkout, so nothing to invoice.');
    }

    // The clean is done; the office finalizes the order in the old module.
    ops_bill_complete_order($conn, $orderId);
    return ops_bill_link($conn, $jobId, $orderId, 'awaiting_finalize', null, null);
}

/**
 * A customer-app booking's work order carries the price the customer was shown.
 *
 * The booking already carries it: total_price with its VAT, after any coupon
 * or wallet discount. Finish writes the work order the office used to make
 * from the booking (operation/ajax_online_bookings.php), already completed, at
 * those amounts rather than re-pricing it, for the office to finalize. If the office did
 * convert the booking in the old module meanwhile, that order is used instead,
 * so there is still only one.
 */
function ops_bill_customer_booking(PDO $conn, array $job): array
{
    $jobId = (int)$job['id'];
    $bookingId = (int)$job['source_id'];

    $stmt = $conn->prepare("SELECT * FROM online_bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        return ops_bill_record($conn, $jobId, 'failed', 'Customer booking #' . $bookingId . ' no longer exists.');
    }

    $userId = $job['assigned_to'] ? (int)$job['assigned_to'] : null;
    $orderId = (int)($job['order_id'] ?? 0) ?: (int)($booking['work_order_id'] ?? 0);

    try {
        if ($orderId <= 0) {
            $orderId = ops_bill_write_booking_order($conn, $job, $booking);
            $conn->prepare("UPDATE online_bookings SET work_order_id = ? WHERE id = ? AND work_order_id IS NULL")
                 ->execute([$orderId, $bookingId]);
        } else {
            ops_bill_complete_order($conn, $orderId);
        }
        $conn->prepare("UPDATE ops_jobs SET order_id = ? WHERE id = ? AND order_id IS NULL")->execute([$orderId, $jobId]);
    } catch (Throwable $e) {
        error_log('ops_bill_customer_booking #' . $jobId . ' failed: ' . $e->getMessage());
        $note = mb_substr('Work order not created for customer booking #' . $bookingId . ': ' . $e->getMessage(), 0, 255);
        return $orderId > 0
            ? ops_bill_link($conn, $jobId, $orderId, 'failed', $note, null)
            : ops_bill_record($conn, $jobId, 'failed', $note);
    }

    $conn->prepare("UPDATE online_bookings SET status = 'completed', updated_at = NOW() WHERE id = ? AND status NOT IN ('cancelled', 'no_show')")
         ->execute([$bookingId]);
    return ops_bill_link($conn, $jobId, $orderId, 'awaiting_finalize', null, null);
}

/** The completed work order for a finished customer booking. Returns its id. */
function ops_bill_write_booking_order(PDO $conn, array $job, array $booking): int
{
    // The client, the way the office conversion finds or makes one.
    $clientId = (int)($booking['client_id'] ?? 0);
    if ($clientId <= 0) {
        $find = $conn->prepare("SELECT id FROM client WHERE mobile_num = ? LIMIT 1");
        $find->execute([(string)$booking['customer_phone']]);
        $clientId = (int)$find->fetchColumn();
    }
    if ($clientId <= 0) {
        $conn->prepare("
            INSERT INTO client (client_name, mobile_num, cell_num, email, address, rate, payment, terms, default_vat_rate, is_active, currency, client_status)
            VALUES (?, ?, '', ?, ?, 0.00, 'D', 'cash', 5.00, 1, 'AED', 'active')
        ")->execute([
            (string)$booking['customer_name'], (string)$booking['customer_phone'],
            (string)($booking['customer_email'] ?? ''), (string)($booking['address'] ?? ''),
        ]);
        $clientId = (int)$conn->lastInsertId();
    }
    $client = $conn->prepare("SELECT client_name, terms FROM client WHERE id = ?");
    $client->execute([$clientId]);
    $clientRow = $client->fetch(PDO::FETCH_ASSOC) ?: ['client_name' => $booking['customer_name'], 'terms' => 'cash'];

    // The customer's price, as booked.
    $grand = round((float)$booking['total_price'], 2);
    $vat = round((float)($booking['vat'] ?? 0), 2);
    $net = round($grand - $vat, 2);
    $vatRate = $net > 0 ? round($vat / $net * 100, 2) : 0.0;
    $hours = max(0.5, (float)($booking['hours'] ?? 0) * max(1, (int)($booking['professionals'] ?? 1)));
    $rate = round($net / $hours, 2);

    $startedAt = (string)($job['started_at'] ?: $job['finished_at']);
    $finishedAt = (string)$job['finished_at'];
    $serviceDate = substr($startedAt, 0, 10) ?: date('Y-m-d');
    $startClock = substr($startedAt, 11, 5);
    $endClock = substr($finishedAt, 11, 5);
    $userId = $job['assigned_to'] ? (int)$job['assigned_to'] : null;

    $conn->prepare("
        INSERT INTO make_order
            (company_id, client_id, client_name, worker_name, email_o, address_o, mobile_num_o,
             fee_charged, hourly_rate, payment, date, service_date, time, start_time, end_time,
             hours, total, balance, need_materials, remark, notes, driver_name,
             net_hours, net_amount, amount_afc, discount_amount,
             vat_rate, vat_amount, grand_total, status, payment_status,
             created_at, created_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?, ?,
             ?, ?, 0.00, ?, ?, ?, '',
             ?, ?, ?, 0.00,
             ?, ?, ?, 'completed', 'unpaid',
             ?, ?)
    ")->execute([
        OPS_CUSTOMER_BOOKING_COMPANY_ID, $clientId, (string)$clientRow['client_name'],
        mb_substr(ops_bill_person_name($conn, (int)$userId), 0, 50),
        (string)($booking['customer_email'] ?? ''), (string)($booking['address'] ?? ''),
        mb_substr((string)$booking['customer_phone'], 0, 50),
        $rate, $rate, mb_substr((string)($clientRow['terms'] ?: 'cash'), 0, 15),
        $serviceDate, $serviceDate,
        mb_substr($startClock . ' To ' . $endClock, 0, 50),
        $startClock !== '' ? $startClock . ':00' : null,
        $endClock !== '' ? $endClock . ':00' : null,
        $hours, $net, (int)($booking['materials_included'] ?? 0),
        mb_substr('From Operations job #' . (int)$job['id'] . ' — customer booking #' . (int)$booking['id'], 0, 900),
        'Created from online booking #' . (int)$booking['id'],
        $hours, $net, $net,
        $vatRate, $vat, $grand,
        date('Y-m-d H:i:s'), $userId,
    ]);
    $orderId = (int)$conn->lastInsertId();

    $workerId = ops_bill_worker_id($conn, (int)$userId);
    if ($workerId > 0) {
        $conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?, ?)")->execute([$orderId, $workerId]);
    }
    return $orderId;
}

/** Write why a job was, or was not, invoiced. */
function ops_bill_record(PDO $conn, int $jobId, string $status, ?string $note): array
{
    try {
        $conn->prepare("
            UPDATE ops_jobs SET billing_status = ?, billing_note = ?
            WHERE id = ? AND order_id IS NULL
        ")->execute([$status, $note, $jobId]);
    } catch (Throwable $e) {
        error_log('ops_bill_record failed: ' . $e->getMessage());
    }
    return ['status' => $status, 'note' => $note, 'invoice_id' => null];
}

/**
 * Who a building's work is billed to, with the fields billing needs, or null.
 *
 * The client set on the Billing page wins. With none set, the building's
 * landlord: the one active client whose name is the landlord's name, compared
 * without case, spacing, punctuation or a trailing "LLC" — so "Ain Al Reem
 * Properties LLC" on the building finds "AIN AL REEM PROPERTIES L.L.C". That is
 * who the old module billed for these buildings, and it means a building needs
 * no set-up at all unless its landlord is not a client, or is the wrong payer.
 * Two clients with the same name are no answer, and neither is a guess.
 */
function ops_bill_client_for_building(PDO $conn, int $buildingId): ?array
{
    if ($buildingId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT c.*
        FROM ops_building_clients bc
        JOIN client c ON c.id = bc.client_id
        WHERE bc.building_id = ?
        LIMIT 1
    ");
    $stmt->execute([$buildingId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($client) {
        return $client;
    }

    return ops_bill_landlord_client($conn, $buildingId);
}

/** The client matching a building's landlord name, or null — see above. */
function ops_bill_landlord_client(PDO $conn, int $buildingId): ?array
{
    $stmt = $conn->prepare("SELECT landlord_name FROM re_buildings WHERE id = ?");
    $stmt->execute([$buildingId]);
    $landlord = ops_bill_name_key((string)$stmt->fetchColumn());
    if ($landlord === '') {
        return null;
    }

    $matches = [];
    foreach ($conn->query("SELECT * FROM client WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (ops_bill_name_key((string)$row['client_name']) === $landlord) {
            $matches[] = $row;
        }
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/** "Ain Al Reem Properties L.L.C." → "ainalreemproperties". */
function ops_bill_name_key(string $name): string
{
    $key = preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
    return (string)preg_replace('/llc$/', '', $key);
}

function ops_bill_building_name(PDO $conn, int $buildingId): string
{
    $stmt = $conn->prepare("SELECT name FROM re_buildings WHERE id = ?");
    $stmt->execute([$buildingId]);
    return (string)($stmt->fetchColumn() ?: '');
}

function ops_bill_person_name(PDO $conn, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $stmt = $conn->prepare("SELECT COALESCE(NULLIF(fullname, ''), username) FROM user WHERE id = ?");
    $stmt->execute([$userId]);
    return (string)($stmt->fetchColumn() ?: '');
}

/**
 * The person's row on the old workers list, through their employee record —
 * or 0 when they are not on it.
 */
function ops_bill_worker_id(PDO $conn, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    try {
        $stmt = $conn->prepare("
            SELECT m.worker_id
            FROM employees e
            JOIN v_worker_employee_map m ON m.employee_id = e.id
            WHERE e.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * How a job's billing reads on the web, or null before it has finished.
 *
 * @return array{tone:string, text:string, url:?string}|null
 */
function ops_bill_summary(PDO $conn, string $appBase, array $job): ?array
{
    $status = (string)($job['billing_status'] ?? '');
    if ($status === '') {
        return null;
    }

    $invoiceId = (int)($job['invoice_id'] ?? 0);
    // A job tied to an order finalized later in the old module (ARS checkout)
    // shows that order's invoice — the same one, not a copy.
    if ($invoiceId <= 0 && (int)($job['order_id'] ?? 0) > 0) {
        $stmt = $conn->prepare("SELECT invoice_id FROM make_order WHERE id = ?");
        $stmt->execute([(int)$job['order_id']]);
        $invoiceId = (int)$stmt->fetchColumn();
    }

    if ($invoiceId > 0) {
        $stmt = $conn->prepare("SELECT invoice_no, total FROM invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        // The order's hours, not the job's: the office may have corrected
        // them before pressing Finalize.
        $hours = (float)($job['billed_hours'] ?? 0);
        if ((int)($job['order_id'] ?? 0) > 0) {
            $stmt = $conn->prepare("SELECT hours FROM make_order WHERE id = ?");
            $stmt->execute([(int)$job['order_id']]);
            $orderHours = $stmt->fetchColumn();
            if ($orderHours !== false && $orderHours !== null) {
                $hours = (float)$orderHours;
            }
        }
        return [
            'tone' => 'success',
            'text' => 'Invoiced ' . ($inv['invoice_no'] ?? '#' . $invoiceId)
                    . ' — AED ' . number_format((float)($inv['total'] ?? 0), 2)
                    . ($hours > 0 ? ' (' . rtrim(rtrim(number_format($hours, 2), '0'), '.') . ' h)' : ''),
            'url' => $appBase . '/accounts/invoice_view.php?id=' . $invoiceId,
        ];
    }

    if ($status === 'awaiting_finalize' && (int)($job['order_id'] ?? 0) > 0) {
        $hours = (float)($job['billed_hours'] ?? 0);
        return [
            'tone' => 'info',
            'text' => 'Work order #' . (int)$job['order_id']
                    . ($hours > 0 ? ' (' . rtrim(rtrim(number_format($hours, 2), '0'), '.') . ' h)' : '')
                    . ' is waiting for the office to check it and press Finalize.',
            'url' => ops_bill_finalize_list_url($appBase),
        ];
    }

    return [
        'tone' => $status === 'not_billable' ? 'secondary' : ($status === 'failed' ? 'danger' : 'warning'),
        'text' => (string)($job['billing_note'] ?: 'Not invoiced.'),
        'url' => null,
    ];
}
