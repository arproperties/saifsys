<?php
/**
 * Operations — a finished job invoices itself.
 *
 * WHAT THIS REPLACES
 * ------------------
 * In the old cleaning module a job became money in three office steps: someone
 * typed a work order, the job was marked complete, and an Admin or Accountant
 * pressed Finalize (wo_finalize_work_order), which produced the invoice and its
 * GL journal. Almost nothing got that far — 2 of 420 orders in the thirty days
 * before this. Here the person on site pressing Finish is the whole trigger.
 *
 * THE SAME INVOICE, NOT A NEW KIND OF ONE
 * ---------------------------------------
 * Nothing about invoicing is re-implemented. A finished job writes the work
 * order the office would have typed, and hands it to ar_ensure_invoice_for_order
 * — the function Finalize itself calls. So the invoice number, its line, the
 * client's terms and due date, the receivable, the VAT and the GL journal are
 * identical to a Finalize, and every accounts page, statement, reconciliation
 * and report that reads make_order and invoices sees these without knowing
 * where they came from.
 *
 * The one thing left out is Finalize's role check. It exists to stop the wrong
 * person in the office freezing an order; here there is no person in the
 * office, and the rules that decide the amount are fixed in this file rather
 * than typed by whoever is logged in.
 *
 * WHAT GETS INVOICED
 * ------------------
 *   - Cleaning jobs a staff member raised in a building that has a client set
 *     (modules/operations/billing.php). Billed to that client, at that client's
 *     hourly rate and VAT, for the time the app measured, up to 10 hours.
 *   - Tenant maintenance requests, the same way — the building's client, its
 *     rate and VAT — but never more than 2 hours. A tenant callout is a visit
 *     to one flat, and the owner is not billed a day because a technician left
 *     the job open.
 *   - Nothing else, deliberately:
 *       maintenance jobs staff raise themselves — the old cleaning module only
 *                           ever billed cleaning;
 *       tenant cleaning   — the tenant paid for the booking when they made it,
 *                           so invoicing it again would charge the work twice.
 *
 * A job that is not invoiced still says why, on the job, in words — so "why is
 * there no invoice for yesterday's lobby" has an answer without anybody
 * reading this file.
 *
 * NEVER BLOCKS FINISH
 * -------------------
 * Billing runs after the job is already marked done. If it fails, the job is
 * still finished, the reason is recorded as `failed`, and the office sees a
 * Retry button on the job. A cleaner is never told their work did not count
 * because an account code was missing.
 */

require_once dirname(__DIR__, 3) . '/includes/work_order_financial_guard.php';
require_once dirname(__DIR__, 3) . '/includes/ar_helpers.php';

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
 * Invoice a finished job, once.
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

    if ((int)($job['order_id'] ?? 0) > 0) {
        return ['status' => 'billed', 'note' => null, 'invoice_id' => (int)$job['invoice_id'] ?: null];
    }
    if ($job['status'] !== 'done') {
        return ['status' => 'failed', 'note' => 'Only a finished job can be invoiced.', 'invoice_id' => null];
    }

    // --- Not billable, by the same rules the old modules followed ----------
    $sourceType = (string)($job['source_type'] ?? 'staff');
    if ($sourceType === 'tenant_cleaning') {
        return ops_bill_record($conn, $jobId, 'not_billable', 'Tenant cleaning — paid by the tenant when booked.');
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

        // The same preconditions Finalize enforces, on the order just written.
        $order = $conn->prepare("SELECT * FROM make_order WHERE id = ?");
        $order->execute([$orderId]);
        [$ok, $reason] = wo_can_finalize($conn, $order->fetch(PDO::FETCH_ASSOC) ?: []);
        if (!$ok) {
            throw new RuntimeException($reason);
        }

        // Invoice, items, receivable and GL journal — Finalize's own call.
        $invoiceId = (int)ar_ensure_invoice_for_order($conn, $orderId, $job['assigned_to'] ? (int)$job['assigned_to'] : null);
        if ($invoiceId <= 0) {
            throw new RuntimeException('The invoice could not be created.');
        }

        // What Finalize writes after the invoice, so these orders read as
        // finalized and locked everywhere the old module checks.
        $sets = ['invoice_id = ?', "status = 'invoiced'"];
        $args = [$invoiceId];
        if (wo_column_exists($conn, 'frozen_subtotal')) {
            $sets[] = 'frozen_subtotal = ?';
            $sets[] = 'frozen_vat_amount = ?';
            $sets[] = 'frozen_grand_total = ?';
            array_push($args, $subtotal, $vat, $grand);
        }
        if (wo_column_exists($conn, 'is_finalized')) {
            $sets[] = 'is_finalized = 1';
            $sets[] = 'finalized_at = ?';
            $sets[] = 'finalized_by = ?';
            array_push($args, date('Y-m-d H:i:s'), $job['assigned_to'] ?: null);
        }
        if (wo_column_exists($conn, 'ops_status')) {
            $sets[] = "ops_status = 'completed'";
        }
        $args[] = $orderId;
        $conn->prepare('UPDATE make_order SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);

        $conn->prepare("
            UPDATE ops_jobs
            SET billing_status = 'billed', billing_note = NULL, billed_hours = ?,
                order_id = ?, invoice_id = ?
            WHERE id = ? AND order_id IS NULL
        ")->execute([$hours, $orderId, $invoiceId, $jobId]);

        require_once dirname(__DIR__, 3) . '/includes/AuditService.php';
        AuditService::logCreate('make_order', $orderId, [
            'ops_job_id' => $jobId,
            'client_id' => (int)$client['id'],
            'hours' => $hours,
            'grand_total' => $grand,
            'invoice_id' => $invoiceId,
        ], "Operations job #{$jobId} finished — work order #{$orderId} and invoice created automatically",
            $job['assigned_to'] ? (int)$job['assigned_to'] : null);

        if ($ownsTxn) {
            $conn->commit();
        }

        // Finalize refreshes the cached financial dashboards afterwards; so does
        // this. Outside the transaction, and never allowed to undo a good invoice.
        try {
            require_once dirname(__DIR__, 3) . '/includes/service_accounting_service.php';
            (new ServiceAccountingService($conn))->invalidateFinancialCache();
        } catch (Throwable $e) {
            error_log('ops billing cache refresh failed: ' . $e->getMessage());
        }

        return ['status' => 'billed', 'note' => null, 'invoice_id' => $invoiceId];
    } catch (Throwable $e) {
        if ($ownsTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('ops_bill_finished_job #' . $jobId . ' failed: ' . $e->getMessage());
        return ops_bill_record($conn, $jobId, 'failed', mb_substr('Invoice not created: ' . $e->getMessage(), 0, 255));
    }
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

/** The client set for a building, with the fields billing needs, or null. */
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
    return $client ?: null;
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

    if ($status === 'billed' && (int)($job['invoice_id'] ?? 0) > 0) {
        $stmt = $conn->prepare("SELECT invoice_no, total FROM invoices WHERE id = ?");
        $stmt->execute([(int)$job['invoice_id']]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'tone' => 'success',
            'text' => 'Invoiced ' . ($inv['invoice_no'] ?? '#' . (int)$job['invoice_id'])
                    . ' — AED ' . number_format((float)($inv['total'] ?? 0), 2)
                    . ' (' . rtrim(rtrim(number_format((float)$job['billed_hours'], 2), '0'), '.') . ' h)',
            'url' => $appBase . '/accounts/invoice_view.php?id=' . (int)$job['invoice_id'],
        ];
    }

    return [
        'tone' => $status === 'not_billable' ? 'secondary' : ($status === 'failed' ? 'danger' : 'warning'),
        'text' => (string)($job['billing_note'] ?: 'Not invoiced.'),
        'url' => null,
    ];
}
