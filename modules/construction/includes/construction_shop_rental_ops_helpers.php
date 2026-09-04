<?php
/**
 * Construction Shop Rental — Phase 1.5 operational visibility helpers.
 * Read-only health, diagnostics, timeline, portfolio metrics.
 * Does NOT post, reverse, or change accounting behaviour.
 */

require_once __DIR__ . '/construction_shop_rental_helpers.php';

function co_shop_user_label(PDO $conn, ?int $userId): string {
    if (!$userId) {
        return 'System';
    }
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    try {
        $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(fullname), ''), username, CONCAT('User #', id)) AS label FROM user WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $label = (string)($stmt->fetchColumn() ?: ('User #' . $userId));
    } catch (Throwable $e) {
        $label = 'User #' . $userId;
    }
    $cache[$userId] = $label;
    return $label;
}

function co_shop_contract_duration_label(array $contract): string {
    $months = co_shop_contract_month_count((string)($contract['start_date'] ?? ''), (string)($contract['end_date'] ?? ''));
    $start = (string)($contract['start_date'] ?? '');
    $end = (string)($contract['end_date'] ?? '');
    $label = $months . ' month' . ($months === 1 ? '' : 's') . ' · ' . $start . ' → ' . $end;
    if (function_exists('co_shop_concession_active') && co_shop_concession_active($contract)
        && function_exists('co_shop_chargeable_month_count')) {
        $chg = co_shop_chargeable_month_count($contract);
        $label .= ' · ' . $chg . ' chargeable';
    }
    return $label;
}

/**
 * Enriched dashboard payload for Contract View (read-only).
 */
function co_shop_contract_dashboard(PDO $conn, int $companyId, int $contractId): array {
    $summary = co_shop_contract_financial_summary($conn, $companyId, $contractId);
    if (!$summary) {
        return [];
    }
    $cStmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $cStmt->execute([$contractId, $companyId]);
    $contract = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return [];
    }

    $vatOptions = co_shop_vat_collection_options();
    $vatMethod = $contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED;

    return array_merge($summary, [
        'contract_rent_net' => (float)$contract['rent_amount'],
        'monthly_equivalent' => co_shop_monthly_equivalent_net($contract),
        'vat_method_label' => $vatOptions[$vatMethod] ?? (string)$vatMethod,
        'vat_mode_label' => ucfirst((string)($contract['vat_mode'] ?? 'exclusive')),
        'duration_label' => co_shop_contract_duration_label($contract),
        'duration_months' => co_shop_contract_month_count((string)($contract['start_date'] ?? ''), (string)($contract['end_date'] ?? '')),
        'security_deposit' => (float)$contract['security_deposit'],
        'deposit_held' => (float)$contract['deposit_received_amount'],
        'payment_frequency' => (string)($contract['payment_frequency'] ?? ''),
        'status' => (string)($contract['status'] ?? ''),
        'client_id' => (int)$contract['client_id'],
        'end_date' => (string)$contract['end_date'],
        'days_to_expiry' => (int)floor((strtotime((string)$contract['end_date']) - strtotime(date('Y-m-d'))) / 86400),
    ]);
}

/**
 * @return array{checks: list<array>, overall: string, score_ok: int, score_warn: int, score_err: int}
 */
function co_shop_contract_health(PDO $conn, int $companyId, int $contractId, ?array $dashboard = null): array {
    $cStmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $cStmt->execute([$contractId, $companyId]);
    $contract = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return ['checks' => [], 'overall' => 'error', 'score_ok' => 0, 'score_warn' => 0, 'score_err' => 1];
    }

    $dashboard = $dashboard ?: co_shop_contract_dashboard($conn, $companyId, $contractId);
    $phase1 = co_shop_phase1_schema_ready($conn);

    $chqStmt = $conn->prepare("SELECT COUNT(*) FROM co_shop_rent_cheques WHERE company_id = ? AND contract_id = ?");
    $chqStmt->execute([$companyId, $contractId]);
    $chequeCount = (int)$chqStmt->fetchColumn();

    $schStmt = $conn->prepare("SELECT COUNT(*) FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ?");
    $schStmt->execute([$companyId, $contractId]);
    $scheduleCount = (int)$schStmt->fetchColumn();

    $pendingSchedStmt = $conn->prepare("SELECT COUNT(*) FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ? AND status = 'pending' AND invoice_id IS NULL");
    $pendingSchedStmt->execute([$companyId, $contractId]);
    $pendingSchedules = (int)$pendingSchedStmt->fetchColumn();

    $invStmt = $conn->prepare("
        SELECT COUNT(*) FROM co_client_invoices i
        WHERE i.company_id = ? AND i.source_type = 'shop_rental' AND i.status <> 'cancelled'
          AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?)
    ");
    $invStmt->execute([$companyId, $contractId, $companyId]);
    $invCount = (int)$invStmt->fetchColumn();

    $unalloc = 0.0;
    if (co_db_column_exists($conn, 'co_client_payments', 'unallocated_amount')) {
        $uStmt = $conn->prepare("
            SELECT COALESCE(SUM(p.unallocated_amount), 0)
            FROM co_client_payments p
            JOIN co_client_invoices i ON i.id = p.invoice_id AND i.company_id = p.company_id
            WHERE p.company_id = ? AND i.source_type = 'shop_rental'
              AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?)
        ");
        $uStmt->execute([$companyId, $contractId, $companyId]);
        $unalloc = (float)$uStmt->fetchColumn();
    }

    $depositRequired = (float)$contract['security_deposit'];
    $depositHeld = (float)$contract['deposit_received_amount'];
    $deferredOn = !empty($contract['accrual_deferred_rent']);

    $pendingRecog = 0;
    $recogUpToDate = true;
    if ($deferredOn && co_db_table_exists($conn, 'co_shop_rent_recognitions')) {
        $todayMonth = date('Y-m-01');
        $pr = $conn->prepare("
            SELECT COUNT(*) FROM co_shop_rent_schedules s
            WHERE s.company_id = ? AND s.contract_id = ? AND s.status = 'invoiced'
              AND s.period_start <= ?
              AND COALESCE((
                  SELECT SUM(r.amount) FROM co_shop_rent_recognitions r
                  WHERE r.schedule_id = s.id AND r.company_id = s.company_id
              ), 0) + 0.005 < s.amount
        ");
        $pr->execute([$companyId, $contractId, $todayMonth]);
        $pendingRecog = (int)$pr->fetchColumn();
        $recogUpToDate = $pendingRecog === 0;
    }

    // Accounting balance: invoiced journals present when invoices exist
    $missingJournal = 0;
    $mj = $conn->prepare("
        SELECT COUNT(*) FROM co_client_invoices i
        WHERE i.company_id = ? AND i.source_type = 'shop_rental' AND i.status <> 'cancelled'
          AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?)
          AND (i.journal_id IS NULL OR i.journal_id = 0)
    ");
    $mj->execute([$companyId, $contractId, $companyId]);
    $missingJournal = (int)$mj->fetchColumn();

    // Duplicate journals: same invoice journal_id reused across distinct invoices
    $dupJournals = 0;
    $dj = $conn->prepare("
        SELECT COUNT(*) FROM (
            SELECT i.journal_id
            FROM co_client_invoices i
            WHERE i.company_id = ? AND i.source_type = 'shop_rental' AND i.status <> 'cancelled'
              AND i.journal_id IS NOT NULL AND i.journal_id > 0
              AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?)
            GROUP BY i.journal_id
            HAVING COUNT(*) > 1
        ) x
    ");
    $dj->execute([$companyId, $contractId, $companyId]);
    $dupJournals = (int)$dj->fetchColumn();

    $companyOk = $companyId > 0 && (int)$contract['company_id'] === $companyId;

    $checks = [];
    $add = static function (string $key, string $label, string $status, string $detail = '') use (&$checks): void {
        $checks[] = ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    };

    $status = (string)$contract['status'];
    if ($status === 'active') {
        $add('active', 'Contract Active', 'success', 'Status is active');
    } elseif (in_array($status, ['draft', 'pending'], true)) {
        $add('active', 'Contract Active', 'warning', 'Status: ' . $status);
    } else {
        $add('active', 'Contract Active', 'error', 'Status: ' . $status);
    }

    if ($chequeCount > 0) {
        $add('payment_plan', 'Payment Plan Generated', 'success', $chequeCount . ' cheque row(s)');
    } else {
        $add('payment_plan', 'Payment Plan Generated', 'warning', 'No cheque / payment plan rows');
    }

    if ($scheduleCount > 0) {
        $add('rent_schedule', 'Rent Schedule Generated', 'success', $scheduleCount . ' earning period(s)');
    } else {
        $add('rent_schedule', 'Rent Schedule Generated', 'warning', 'No monthly earning schedule');
    }

    if ($invCount > 0 && $pendingSchedules === 0) {
        $add('invoices', 'Invoices Generated', 'success', $invCount . ' invoice(s)');
    } elseif ($invCount > 0) {
        $add('invoices', 'Invoices Generated', 'warning', $invCount . ' invoiced · ' . $pendingSchedules . ' pending');
    } elseif ($scheduleCount > 0) {
        $add('invoices', 'Invoices Generated', 'warning', 'Schedules exist but no invoices yet');
    } else {
        $add('invoices', 'Invoices Generated', 'warning', 'Not started');
    }

    $outstanding = (float)($dashboard['outstanding'] ?? 0);
    if ($unalloc > 0.005) {
        $add('allocation', 'Receipt Allocation Complete', 'warning', 'Unallocated ' . number_format($unalloc, 2));
    } elseif ($invCount === 0) {
        $add('allocation', 'Receipt Allocation Complete', 'success', 'No receipts required yet');
    } elseif ($outstanding <= 0.005) {
        $add('allocation', 'Receipt Allocation Complete', 'success', 'No open invoice balance');
    } else {
        $add('allocation', 'Receipt Allocation Complete', 'warning', 'Open balance ' . number_format($outstanding, 2));
    }

    if ($depositRequired <= 0.005) {
        $add('deposit', 'Security Deposit Recorded', 'success', 'No deposit required');
    } elseif ($depositHeld + 0.005 >= $depositRequired) {
        $add('deposit', 'Security Deposit Recorded', 'success', 'Held ' . number_format($depositHeld, 2));
    } elseif ($depositHeld > 0.005) {
        $add('deposit', 'Security Deposit Recorded', 'warning', 'Partial ' . number_format($depositHeld, 2) . ' / ' . number_format($depositRequired, 2));
    } else {
        $add('deposit', 'Security Deposit Recorded', 'error', 'Deposit required but not received');
    }

    if ($deferredOn) {
        $add('deferred', 'Deferred Revenue Enabled', 'success', 'Accrual / deferred rent on');
    } else {
        $add('deferred', 'Deferred Revenue Enabled', 'warning', 'Direct income mode');
    }

    if (!$deferredOn) {
        $add('recognition', 'Monthly Recognition Up-to-date', 'success', 'N/A (direct income)');
    } elseif ($recogUpToDate) {
        $add('recognition', 'Monthly Recognition Up-to-date', 'success', 'No pending recognition through current month');
    } else {
        $add('recognition', 'Monthly Recognition Up-to-date', 'warning', $pendingRecog . ' period(s) pending recognition');
    }

    if ($missingJournal > 0) {
        $add('balanced', 'Accounting Balanced', 'error', $missingJournal . ' invoice(s) missing journal');
    } elseif ($invCount === 0) {
        $add('balanced', 'Accounting Balanced', 'success', 'No posted invoices yet');
    } else {
        $add('balanced', 'Accounting Balanced', 'success', 'Invoices linked to journals');
    }

    if ($dupJournals > 0) {
        $add('duplicates', 'No Duplicate Journals', 'error', $dupJournals . ' shared journal_id group(s)');
    } else {
        $add('duplicates', 'No Duplicate Journals', 'success', 'No duplicate journal links detected');
    }

    if ($companyOk && $phase1) {
        $add('company', 'Company Validation Passed', 'success', 'Company scoped · Phase 1 schema ready');
    } elseif ($companyOk) {
        $add('company', 'Company Validation Passed', 'warning', 'Company OK · Phase 1 migration recommended');
    } else {
        $add('company', 'Company Validation Passed', 'error', 'Company context mismatch');
    }

    $ok = $warn = $err = 0;
    foreach ($checks as $ch) {
        if ($ch['status'] === 'success') {
            $ok++;
        } elseif ($ch['status'] === 'warning') {
            $warn++;
        } else {
            $err++;
        }
    }
    $overall = $err > 0 ? 'error' : ($warn > 0 ? 'warning' : 'success');

    return [
        'checks' => $checks,
        'overall' => $overall,
        'score_ok' => $ok,
        'score_warn' => $warn,
        'score_err' => $err,
        'meta' => [
            'cheque_count' => $chequeCount,
            'schedule_count' => $scheduleCount,
            'pending_schedules' => $pendingSchedules,
            'invoice_count' => $invCount,
            'pending_recognition' => $pendingRecog,
            'unallocated' => $unalloc,
        ],
    ];
}

/**
 * Operational / accounting warnings for display.
 * @return list<array{code:string,severity:string,title:string,detail:string}>
 */
function co_shop_contract_diagnostics(PDO $conn, int $companyId, int $contractId, ?array $dashboard = null, ?array $health = null): array {
    $dashboard = $dashboard ?: co_shop_contract_dashboard($conn, $companyId, $contractId);
    $health = $health ?: co_shop_contract_health($conn, $companyId, $contractId, $dashboard);
    $warnings = [];

    $cStmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $cStmt->execute([$contractId, $companyId]);
    $contract = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return [['code' => 'missing_contract', 'severity' => 'error', 'title' => 'Contract Not Found', 'detail' => 'Unable to load contract in current company.']];
    }

    $push = static function (string $code, string $severity, string $title, string $detail) use (&$warnings): void {
        $warnings[] = compact('code', 'severity', 'title', 'detail');
    };

    $depositRequired = (float)$contract['security_deposit'];
    $depositHeld = (float)$contract['deposit_received_amount'];
    if ($depositRequired > 0.005 && $depositHeld + 0.005 < $depositRequired) {
        $push('missing_deposit', $depositHeld > 0 ? 'warning' : 'error', 'Missing Deposit',
            'Required ' . number_format($depositRequired, 2) . '; held ' . number_format($depositHeld, 2) . '.');
    }

    $vatMethod = $contract['vat_collection_method'] ?? null;
    $vatRate = (float)($contract['vat_rate'] ?? 0);
    if ($vatRate > 0 && ($vatMethod === null || $vatMethod === '')) {
        $push('missing_vat', 'error', 'Missing VAT Configuration', 'VAT rate is set but collection method is blank.');
    }

    $meta = $health['meta'] ?? [];
    if ((int)($meta['cheque_count'] ?? 0) === 0) {
        $push('missing_plan', 'warning', 'Missing Payment Plan', 'Generate the cheque / payment plan for operational collections.');
    }
    if ((int)($meta['schedule_count'] ?? 0) === 0) {
        $push('missing_schedule', 'warning', 'Missing Rent Schedule', 'Generate monthly earning schedules before invoicing.');
    }
    if ((int)($meta['pending_recognition'] ?? 0) > 0) {
        $push('pending_recognition', 'warning', 'Pending Recognition',
            (int)$meta['pending_recognition'] . ' invoiced period(s) still need monthly recognition.');
    }
    if ((float)($dashboard['outstanding'] ?? 0) > 0.005) {
        $push('outstanding', 'warning', 'Outstanding Balance',
            'Open AR ' . number_format((float)$dashboard['outstanding'], 2) . '.');
    }
    if ((float)($dashboard['overdue'] ?? 0) > 0.005) {
        $push('overdue', 'error', 'Overdue Invoices',
            'Overdue ' . number_format((float)$dashboard['overdue'], 2) . '.');
    }
    if ((float)($meta['unallocated'] ?? 0) > 0.005) {
        $push('unallocated', 'warning', 'Unallocated Payments',
            'Unallocated ' . number_format((float)$meta['unallocated'], 2) . ' on contract receipts.');
    }

    // Consistency: deposit held vs deposit receipts sum
    if (co_db_table_exists($conn, 'co_shop_deposit_receipts') && $depositRequired > 0) {
        $dr = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM co_shop_deposit_receipts WHERE company_id = ? AND contract_id = ?");
        $dr->execute([$companyId, $contractId]);
        $receiptSum = (float)$dr->fetchColumn();
        if (abs($receiptSum - $depositHeld) > 0.05) {
            $push('consistency_deposit', 'error', 'Data Consistency Issues',
                'Deposit held on contract (' . number_format($depositHeld, 2) . ') differs from deposit receipts (' . number_format($receiptSum, 2) . ').');
        }
    }

    // Cheque rent total vs contract rent (operational check)
    // COMBINED_FIRST face includes sep. VAT + commission — compare rent net portion only
    $hasNetCol = co_db_column_exists($conn, 'co_shop_rent_cheques', 'net_amount');
    $rentExpr = $hasNetCol
        ? "CASE WHEN notes = 'COMBINED_FIRST' THEN COALESCE(net_amount, amount) ELSE amount END"
        : 'amount';
    $rentChq = $conn->prepare("
        SELECT COALESCE(SUM({$rentExpr}),0) FROM co_shop_rent_cheques
        WHERE company_id = ? AND contract_id = ? AND cheque_type = 'rent'
          AND (notes IS NULL OR notes <> 'VAT_SEPARATE') AND status <> 'cancelled'
    ");
    $rentChq->execute([$companyId, $contractId]);
    $rentChequeTotal = (float)$rentChq->fetchColumn();
    $contractRent = (float)$contract['rent_amount'];
    $vatMode = $contract['vat_mode'] ?? 'exclusive';
    $method = $contract['vat_collection_method'] ?? CO_SHOP_VAT_INCLUDED;
    // Compare net rent to cheque net when exclusive+included VAT is on cheques as gross — soft warning only if far apart on net field
    if ($rentChequeTotal > 0.005 && $contractRent > 0.005) {
        // For exclusive included, cheques are often gross; skip hard equality — warn only if zero vs positive mismatch already covered
        $ratio = $rentChequeTotal / max($contractRent, 0.01);
        if ($method === CO_SHOP_VAT_INCLUDED && $vatMode === 'exclusive' && ($ratio < 0.5 || $ratio > 2.5)) {
            $push('consistency_cheques', 'warning', 'Data Consistency Issues',
                'Rent cheque total (' . number_format($rentChequeTotal, 2) . ') looks inconsistent with contract rent net (' . number_format($contractRent, 2) . '). Review payment plan.');
        }
    }

    foreach ($health['checks'] as $ch) {
        if ($ch['key'] === 'duplicates' && $ch['status'] === 'error') {
            $push('duplicate_journals', 'error', 'Data Consistency Issues', $ch['detail']);
        }
        if ($ch['key'] === 'balanced' && $ch['status'] === 'error') {
            $push('missing_journals', 'error', 'Data Consistency Issues', $ch['detail']);
        }
    }

    return $warnings;
}

/**
 * Full audit timeline with user + related document.
 * @return list<array>
 */
function co_shop_contract_timeline_audit(PDO $conn, int $companyId, int $contractId): array {
    $events = [];

    $c = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $c->execute([$contractId, $companyId]);
    $contract = $c->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        return [];
    }

    $events[] = [
        'datetime' => (string)$contract['created_at'],
        'date' => substr((string)$contract['created_at'], 0, 10),
        'type' => 'contract_created',
        'label' => 'Contract Created',
        'detail' => $contract['contract_number'] . ' · ' . $contract['status'],
        'user' => co_shop_user_label($conn, isset($contract['created_by']) ? (int)$contract['created_by'] : null),
        'document' => $contract['contract_number'],
        'document_url' => 'shop_rental_contract_view.php?id=' . $contractId,
        'tone' => 'primary',
    ];

    $events[] = [
        'datetime' => $contract['start_date'] . ' 00:00:00',
        'date' => $contract['start_date'],
        'type' => 'status',
        'label' => 'Lease Start',
        'detail' => 'Contract period begins',
        'user' => '—',
        'document' => $contract['contract_number'],
        'document_url' => null,
        'tone' => 'success',
    ];

    // Payment plan: first cheque created_at if available, else earliest cheque_date
    $hasChqCreated = co_db_column_exists($conn, 'co_shop_rent_cheques', 'created_at');
    $chqSql = $hasChqCreated
        ? "SELECT MIN(created_at) AS d, COUNT(*) AS n FROM co_shop_rent_cheques WHERE company_id = ? AND contract_id = ?"
        : "SELECT MIN(cheque_date) AS d, COUNT(*) AS n FROM co_shop_rent_cheques WHERE company_id = ? AND contract_id = ?";
    $chq = $conn->prepare($chqSql);
    $chq->execute([$companyId, $contractId]);
    $chqRow = $chq->fetch(PDO::FETCH_ASSOC);
    if ($chqRow && (int)$chqRow['n'] > 0) {
        $d = substr((string)$chqRow['d'], 0, 10);
        $events[] = [
            'datetime' => (string)$chqRow['d'],
            'date' => $d,
            'type' => 'payment_plan',
            'label' => 'Payment Plan Generated',
            'detail' => (int)$chqRow['n'] . ' cheque row(s)',
            'user' => '—',
            'document' => 'Cheque plan',
            'document_url' => '#cheque-plan',
            'tone' => 'info',
        ];
    }

    if (co_db_table_exists($conn, 'co_shop_deposit_receipts')) {
        $dep = $conn->prepare("
            SELECT id, receipt_date, amount, reference, journal_id, created_by, created_at
            FROM co_shop_deposit_receipts
            WHERE company_id = ? AND contract_id = ?
            ORDER BY receipt_date, id
        ");
        $dep->execute([$companyId, $contractId]);
        foreach ($dep->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $events[] = [
                'datetime' => (string)($d['created_at'] ?? $d['receipt_date']),
                'date' => $d['receipt_date'],
                'type' => 'deposit',
                'label' => 'Deposit Recorded',
                'detail' => co_format_money((float)$d['amount']) . ($d['reference'] ? ' · ' . $d['reference'] : ''),
                'user' => co_shop_user_label($conn, isset($d['created_by']) ? (int)$d['created_by'] : null),
                'document' => 'Deposit #' . $d['id'] . ($d['journal_id'] ? ' · J#' . $d['journal_id'] : ''),
                'document_url' => '#deposit-section',
                'tone' => 'success',
            ];
        }
    }

    $hasSchCreated = co_db_column_exists($conn, 'co_shop_rent_schedules', 'created_at');
    $schMeta = $conn->prepare($hasSchCreated
        ? "SELECT MIN(created_at) AS d, COUNT(*) AS n FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ?"
        : "SELECT MIN(period_start) AS d, COUNT(*) AS n FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ?");
    $schMeta->execute([$companyId, $contractId]);
    $sm = $schMeta->fetch(PDO::FETCH_ASSOC);
    if ($sm && (int)$sm['n'] > 0) {
        $events[] = [
            'datetime' => (string)$sm['d'],
            'date' => substr((string)$sm['d'], 0, 10),
            'type' => 'schedule',
            'label' => 'Rent Schedule Generated',
            'detail' => (int)$sm['n'] . ' earning period(s)',
            'user' => '—',
            'document' => 'Earning schedule',
            'document_url' => '#rent-schedule',
            'tone' => 'info',
        ];
    }

    $inv = $conn->prepare("
        SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.journal_id, i.created_by, i.created_at, i.status
        FROM co_client_invoices i
        WHERE i.company_id = ? AND i.source_type = 'shop_rental' AND i.status <> 'cancelled'
          AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?)
        ORDER BY i.invoice_date, i.id
    ");
    $inv->execute([$companyId, $contractId, $companyId]);
    foreach ($inv->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $events[] = [
            'datetime' => (string)($i['created_at'] ?? $i['invoice_date']),
            'date' => $i['invoice_date'],
            'type' => 'invoice',
            'label' => 'Invoice Created',
            'detail' => co_format_money((float)$i['total_amount']) . ' · ' . $i['status'],
            'user' => co_shop_user_label($conn, isset($i['created_by']) ? (int)$i['created_by'] : null),
            'document' => $i['invoice_number'] . ($i['journal_id'] ? ' · J#' . $i['journal_id'] : ''),
            'document_url' => 'client_invoice_pdf.php?id=' . (int)$i['id'],
            'tone' => 'success',
        ];
    }

    // Key Money / shop_charge invoices
    if (co_db_table_exists($conn, 'co_client_invoices')) {
        $kmInv = $conn->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.journal_id, i.created_by, i.created_at, i.status,
                   ct.code AS charge_code, ct.name AS charge_name,
                   COALESCE((
                       SELECT SUM(a.allocated_amount)
                       FROM co_client_payment_allocations a
                       WHERE a.company_id = i.company_id AND a.invoice_id = i.id
                   ), 0) AS paid_amount
            FROM co_client_invoices i
            LEFT JOIN co_shop_contract_charges cc
              ON cc.id = i.contract_charge_id AND cc.company_id = i.company_id
            LEFT JOIN co_shop_charge_types ct
              ON ct.id = cc.charge_type_id AND ct.company_id = cc.company_id
            WHERE i.company_id = ? AND i.source_type = 'shop_charge' AND i.source_id = ? AND i.status <> 'cancelled'
            ORDER BY i.invoice_date, i.id
        ");
        $kmInv->execute([$companyId, $contractId]);
        foreach ($kmInv->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $isKey = (($i['charge_code'] ?? '') === 'key_money') || stripos((string)($i['charge_name'] ?? ''), 'Key Money') !== false;
            $label = $isKey ? 'Key Money Invoice Created' : ('Charge Invoice Created' . (!empty($i['charge_name']) ? ' · ' . $i['charge_name'] : ''));
            $events[] = [
                'datetime' => (string)($i['created_at'] ?? $i['invoice_date']),
                'date' => $i['invoice_date'],
                'type' => $isKey ? 'key_money_invoice' : 'charge_invoice',
                'label' => $label,
                'detail' => co_format_money((float)$i['total_amount']) . ' · ' . $i['status'],
                'user' => co_shop_user_label($conn, isset($i['created_by']) ? (int)$i['created_by'] : null),
                'document' => $i['invoice_number'] . ($i['journal_id'] ? ' · J#' . $i['journal_id'] : ''),
                'document_url' => 'client_invoice_pdf.php?id=' . (int)$i['id'],
                'tone' => 'success',
            ];
            $paid = round((float)$i['paid_amount'], 2);
            $total = round((float)$i['total_amount'], 2);
            if ($isKey && $paid + 0.005 >= $total && $total > 0) {
                $paidAt = (string)($i['created_at'] ?? $i['invoice_date']);
                if (co_db_table_exists($conn, 'co_client_payment_allocations')) {
                    $pa = $conn->prepare("SELECT MAX(created_at) FROM co_client_payment_allocations WHERE company_id = ? AND invoice_id = ?");
                    $pa->execute([$companyId, (int)$i['id']]);
                    $maxPa = (string)$pa->fetchColumn();
                    if ($maxPa !== '') {
                        $paidAt = $maxPa;
                    }
                }
                $events[] = [
                    'datetime' => $paidAt,
                    'date' => substr($paidAt, 0, 10),
                    'type' => 'key_money_paid',
                    'label' => 'Key Money Paid',
                    'detail' => co_format_money($total) . ' · ' . $i['invoice_number'],
                    'user' => '—',
                    'document' => $i['invoice_number'],
                    'document_url' => 'client_invoice_pdf.php?id=' . (int)$i['id'],
                    'tone' => 'success',
                ];
            }
        }

        // Key Money reversed: reversed/voided payments that allocated to Key Money invoices
        if (co_db_column_exists($conn, 'co_client_payments', 'allocation_status')
            && co_db_table_exists($conn, 'co_client_payment_allocations')) {
            $kmRev = $conn->prepare("
                SELECT DISTINCT p.id, p.payment_date, p.amount, p.created_at, p.created_by, p.allocation_status, p.reference
                FROM co_client_payments p
                JOIN co_client_payment_allocations a ON a.payment_id = p.id AND a.company_id = p.company_id
                JOIN co_client_invoices i ON i.id = a.invoice_id AND i.company_id = a.company_id
                LEFT JOIN co_shop_contract_charges cc ON cc.id = i.contract_charge_id AND cc.company_id = i.company_id
                LEFT JOIN co_shop_charge_types ct ON ct.id = cc.charge_type_id AND ct.company_id = cc.company_id
                WHERE p.company_id = ?
                  AND i.source_type = 'shop_charge'
                  AND i.source_id = ?
                  AND ct.code = 'key_money'
                  AND p.allocation_status IN ('reversed', 'voided')
                ORDER BY p.payment_date, p.id
            ");
            // Note: reversed payments may have allocations deleted — also check payment events if needed
            try {
                $kmRev->execute([$companyId, $contractId]);
                foreach ($kmRev->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $events[] = [
                        'datetime' => (string)($p['created_at'] ?? $p['payment_date']),
                        'date' => $p['payment_date'],
                        'type' => 'key_money_reversed',
                        'label' => 'Key Money Reversed',
                        'detail' => co_format_money((float)$p['amount']) . ' · ' . ($p['allocation_status'] ?? ''),
                        'user' => co_shop_user_label($conn, isset($p['created_by']) ? (int)$p['created_by'] : null),
                        'document' => function_exists('co_receipt_display_number') ? co_receipt_display_number((int)$p['id']) : ('Receipt #' . $p['id']),
                        'document_url' => 'client_payment_receipt.php?id=' . (int)$p['id'],
                        'tone' => 'danger',
                    ];
                }
            } catch (Throwable $e) {
                // non-fatal
            }
        }
    }

    $pay = $conn->prepare("
        SELECT p.id, p.payment_date, p.amount, p.reference, p.journal_id, p.created_by, p.created_at, i.invoice_number
        FROM co_client_payments p
        JOIN co_client_invoices i ON i.id = p.invoice_id AND i.company_id = p.company_id
        WHERE p.company_id = ? AND i.source_type = 'shop_rental'
          AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?)
        ORDER BY p.payment_date, p.id
    ");
    $pay->execute([$companyId, $contractId, $companyId]);
    $seenPaymentIds = [];
    foreach ($pay->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $seenPaymentIds[(int)$p['id']] = true;
        $events[] = [
            'datetime' => (string)($p['created_at'] ?? $p['payment_date']),
            'date' => $p['payment_date'],
            'type' => 'receipt',
            'label' => 'Receipt Recorded',
            'detail' => co_format_money((float)$p['amount']) . ($p['reference'] ? ' · ' . $p['reference'] : ''),
            'user' => co_shop_user_label($conn, isset($p['created_by']) ? (int)$p['created_by'] : null),
            'document' => 'Receipt ' . (function_exists('co_receipt_display_number') ? co_receipt_display_number((int)$p['id']) : ('#' . $p['id'])) . ' · ' . $p['invoice_number'],
            'document_url' => 'client_payment_receipt.php?id=' . (int)$p['id'],
            'tone' => 'success',
        ];
    }

    // Workspace payments scoped by contract_id (may have null header invoice_id)
    if (co_db_column_exists($conn, 'co_client_payments', 'contract_id')) {
        $pay2 = $conn->prepare("
            SELECT p.id, p.payment_date, p.amount, p.reference, p.journal_id, p.created_by, p.created_at,
                   p.allocation_status
            FROM co_client_payments p
            WHERE p.company_id = ? AND p.contract_id = ?
            ORDER BY p.payment_date, p.id
        ");
        $pay2->execute([$companyId, $contractId]);
        foreach ($pay2->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $pid = (int)$p['id'];
            if (isset($seenPaymentIds[$pid])) {
                continue;
            }
            $seenPaymentIds[$pid] = true;
            $st = (string)($p['allocation_status'] ?? '');
            $label = 'Payment Receipt';
            $tone = 'success';
            if ($st === 'reversed') {
                $label = 'Payment Reversed';
                $tone = 'danger';
            } elseif ($st === 'voided') {
                $label = 'Payment Voided';
                $tone = 'danger';
            } elseif (($p['reference'] ?? '') === 'CREDIT-APPLY') {
                $label = 'Customer Credit Applied';
                $tone = 'info';
            }
            $events[] = [
                'datetime' => (string)($p['created_at'] ?? $p['payment_date']),
                'date' => $p['payment_date'],
                'type' => 'receipt',
                'label' => $label,
                'detail' => co_format_money((float)$p['amount']) . ($p['reference'] ? ' · ' . $p['reference'] : '') . ($st ? ' · ' . $st : ''),
                'user' => co_shop_user_label($conn, isset($p['created_by']) ? (int)$p['created_by'] : null),
                'document' => function_exists('co_receipt_display_number') ? co_receipt_display_number($pid) : ('Receipt #' . $pid),
                'document_url' => 'client_payment_receipt.php?id=' . $pid,
                'tone' => $tone,
            ];
        }
    }

    if (co_db_table_exists($conn, 'co_client_payment_allocations')) {
        $alloc = $conn->prepare("
            SELECT a.id, a.allocated_amount, a.created_at, i.invoice_number, p.payment_date, p.id AS payment_id
            FROM co_client_payment_allocations a
            JOIN co_client_invoices i ON i.id = a.invoice_id AND i.company_id = a.company_id
            JOIN co_client_payments p ON p.id = a.payment_id AND p.company_id = a.company_id
            WHERE a.company_id = ? AND i.source_type = 'shop_rental'
              AND i.source_id IN (SELECT id FROM co_shop_rent_schedules WHERE contract_id = ? AND company_id = ?)
            ORDER BY a.id
        ");
        $alloc->execute([$companyId, $contractId, $companyId]);
        foreach ($alloc->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $events[] = [
                'datetime' => (string)($a['created_at'] ?? $a['payment_date']),
                'date' => substr((string)($a['created_at'] ?? $a['payment_date']), 0, 10),
                'type' => 'allocation',
                'label' => 'Receipt Allocated',
                'detail' => co_format_money((float)$a['allocated_amount']) . ' → ' . $a['invoice_number'],
                'user' => '—',
                'document' => (function_exists('co_receipt_display_number') ? co_receipt_display_number((int)$a['payment_id']) : ('Pmt #' . $a['payment_id'])) . ' · Alloc #' . $a['id'],
                'document_url' => 'client_payment_receipt.php?id=' . (int)$a['payment_id'],
                'tone' => 'info',
            ];
        }
    }

    if (co_db_table_exists($conn, 'co_shop_rent_recognitions')) {
        $rec = $conn->prepare("
            SELECT r.id, r.amount, r.recognition_month, r.journal_id, r.recognized_by, r.recognized_at, s.period_start
            FROM co_shop_rent_recognitions r
            JOIN co_shop_rent_schedules s ON s.id = r.schedule_id AND s.company_id = r.company_id
            WHERE r.company_id = ? AND s.contract_id = ?
            ORDER BY r.recognition_month, r.id
        ");
        $rec->execute([$companyId, $contractId]);
        foreach ($rec->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $month = substr((string)$r['recognition_month'], 0, 7);
            $events[] = [
                'datetime' => (string)($r['recognized_at'] ?? $r['recognition_month']),
                'date' => substr((string)($r['recognized_at'] ?? $r['recognition_month']), 0, 10),
                'type' => 'recognition',
                'label' => 'Revenue Recognition',
                'detail' => co_format_money((float)$r['amount']) . ' · ' . $month,
                'user' => co_shop_user_label($conn, isset($r['recognized_by']) ? (int)$r['recognized_by'] : null),
                'document' => 'Recog #' . $r['id'] . ($r['journal_id'] ? ' · J#' . $r['journal_id'] : ''),
                'document_url' => 'reports/deferred_rent_recognition.php?month=' . urlencode($month),
                'tone' => 'primary',
            ];
        }
    }

    // Phase 2A lifecycle audit events (renewal, amendment, inspection, settlement, termination, cheques)
    if (co_db_table_exists($conn, 'co_shop_contract_events')) {
        $ev = $conn->prepare("
            SELECT id, event_type, payload_json, created_by, created_at
            FROM co_shop_contract_events
            WHERE company_id = ? AND contract_id = ?
            ORDER BY created_at, id
        ");
        $ev->execute([$companyId, $contractId]);
        $labels = [
            'renewal_draft_created' => 'Renewal Draft Created',
            'created_as_renewal' => 'Created as Renewal',
            'renewal_activated' => 'Renewal Activated',
            'status_renewed' => 'Status → Renewed',
            'status_changed' => 'Status Changed',
            'amendment' => 'Contract Amended',
            'vat_collection_changed' => 'VAT Collection Method Changed',
            'cheque_cleared_allocated' => 'Cheque Cleared & Allocated',
            'cheque_allocated' => 'Cheque Allocated (Workspace)',
            'cheque_unallocated' => 'Cheque Unallocated (Payment Reversed)',
            'cheque_bank_status' => 'Cheque Bank Status',
            'payment_workspace_confirm' => 'Payment Workspace Confirm',
            'payment_reversed' => 'Payment Reversed',
            'inspection_saved' => 'Inspection Saved',
            'inspection_completed' => 'Move-Out Inspection Completed',
            'deposit_settled' => 'Deposit Settled',
            'terminated' => 'Early Termination Finalized',
            'cheque_bounced' => 'Cheque Bounced',
            'cheque_cancelled' => 'Cheque Cancelled',
            'cheque_lost' => 'Cheque Lost',
            'cheque_redeposited' => 'Cheque Re-deposited',
            'cheque_replaced' => 'Cheque Replaced',
            'rent_concession_created' => 'Rent Concession Created',
            'rent_concession_updated' => 'Rent Concession Updated',
            'rent_concession_removed' => 'Rent Concession Removed',
        ];
        foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = (string)$row['event_type'];
            $payload = [];
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $detailParts = [];
            if ($type === 'status_changed' || $type === 'status_renewed') {
                $from = $payload['from'] ?? $payload['old_status'] ?? $payload['old'] ?? $payload['previous_status'] ?? null;
                $to = $payload['to'] ?? $payload['new_status'] ?? $payload['new'] ?? $payload['status'] ?? null;
                if ($from !== null || $to !== null) {
                    $detailParts[] = trim((string)($from ?? '?')) . ' → ' . trim((string)($to ?? '?'));
                }
                if (!empty($payload['reason'])) {
                    $detailParts[] = 'reason: ' . (string)$payload['reason'];
                }
            }
            if ($type === 'vat_collection_changed') {
                $fromL = $payload['from_label'] ?? $payload['from'] ?? '?';
                $toL = $payload['to_label'] ?? $payload['to'] ?? '?';
                $detailParts[] = (string)$fromL . ' → ' . (string)$toL;
                if (!empty($payload['reason'])) {
                    $detailParts[] = 'reason: ' . (string)$payload['reason'];
                }
            }
            $docUrl = null;
            $docLabel = 'Event #' . $row['id'];
            $payIdFromPayload = (int)($payload['payment_id'] ?? 0);
            if ($payIdFromPayload <= 0 && in_array($type, ['payment_workspace_confirm', 'payment_reversed', 'cheque_allocated', 'cheque_unallocated', 'cheque_cleared_allocated'], true)) {
                // try nested keys
                $payIdFromPayload = (int)($payload['payment']['id'] ?? 0);
            }
            if ($payIdFromPayload > 0) {
                $docUrl = 'client_payment_receipt.php?id=' . $payIdFromPayload;
                $docLabel = function_exists('co_receipt_display_number')
                    ? co_receipt_display_number($payIdFromPayload)
                    : ('Receipt #' . $payIdFromPayload);
            }
            if (!$detailParts) {
                foreach ($payload as $k => $v) {
                    if (is_scalar($v) || $v === null) {
                        $detailParts[] = $k . '=' . (string)$v;
                    }
                }
            }
            $events[] = [
                'datetime' => (string)$row['created_at'],
                'date' => substr((string)$row['created_at'], 0, 10),
                'type' => 'lifecycle_' . $type,
                'label' => $labels[$type] ?? ucwords(str_replace('_', ' ', $type)),
                'detail' => $detailParts ? implode(' · ', array_slice($detailParts, 0, 4)) : 'Lifecycle event',
                'user' => co_shop_user_label($conn, isset($row['created_by']) ? (int)$row['created_by'] : null),
                'document' => $docLabel,
                'document_url' => $docUrl,
                'tone' => in_array($type, ['terminated', 'cheque_bounced', 'cheque_lost', 'payment_reversed'], true) ? 'danger'
                    : (in_array($type, ['deposit_settled', 'renewal_activated', 'inspection_completed', 'payment_workspace_confirm', 'cheque_allocated'], true) ? 'success' : 'info'),
            ];
        }
    }

    // Status snapshot only when no lifecycle status events already cover updates
    $hasLifecycleStatus = false;
    foreach ($events as $ev) {
        if (strpos((string)($ev['type'] ?? ''), 'lifecycle_status') === 0) {
            $hasLifecycleStatus = true;
            break;
        }
    }
    if (!$hasLifecycleStatus
        && co_db_column_exists($conn, 'co_shop_rental_contracts', 'updated_at')
        && !empty($contract['updated_at'])
        && (string)$contract['updated_at'] !== (string)$contract['created_at']) {
        $events[] = [
            'datetime' => (string)$contract['updated_at'],
            'date' => substr((string)$contract['updated_at'], 0, 10),
            'type' => 'status',
            'label' => 'Contract Updated',
            'detail' => 'Current status: ' . $contract['status'],
            'user' => '—',
            'document' => $contract['contract_number'],
            'document_url' => null,
            'tone' => 'secondary',
        ];
    }

    $events[] = [
        'datetime' => $contract['end_date'] . ' 23:59:59',
        'date' => $contract['end_date'],
        'type' => 'status',
        'label' => 'Lease End',
        'detail' => 'Contract end date',
        'user' => '—',
        'document' => $contract['contract_number'],
        'document_url' => null,
        'tone' => 'secondary',
    ];

    usort($events, static function ($a, $b) {
        $cmp = strcmp((string)$a['datetime'], (string)$b['datetime']);
        return $cmp !== 0 ? $cmp : strcmp($a['label'], $b['label']);
    });
    return $events;
}

/**
 * Valid quick actions for current contract state.
 * @return list<array{key:string,label:string,type:string,enabled:bool,hint?:string,href?:string,action?:string}>
 */
function co_shop_contract_quick_actions(PDO $conn, int $companyId, int $contractId, array $contract, ?array $health = null, ?array $dashboard = null): array {
    $health = $health ?: co_shop_contract_health($conn, $companyId, $contractId, $dashboard);
    $meta = $health['meta'] ?? [];
    $status = (string)($contract['status'] ?? '');
    $isActive = in_array($status, ['active', 'draft', 'pending'], true);
    $phase1 = co_shop_phase1_schema_ready($conn);
    $depositGap = max(0, (float)$contract['security_deposit'] - (float)$contract['deposit_received_amount']);
    $outstanding = (float)($dashboard['outstanding'] ?? 0);
    $pendingSched = (int)($meta['pending_schedules'] ?? 0);
    $hasCheques = (int)($meta['cheque_count'] ?? 0) > 0;
    $hasSched = (int)($meta['schedule_count'] ?? 0) > 0;

    $actions = [];
    $actions[] = [
        'key' => 'generate_cheques',
        'label' => 'Generate Payment Plan',
        'type' => 'form',
        'action' => 'generate_cheques_quick',
        'enabled' => $isActive,
        'hint' => $hasCheques ? 'Regenerate / adjust cheque counts in Cheque Plan' : 'Create cheque rows for collections',
    ];
    $actions[] = [
        'key' => 'generate_schedules',
        'label' => 'Generate Rent Schedule',
        'type' => 'form',
        'action' => 'generate_schedules',
        'enabled' => $isActive,
        'hint' => 'Monthly earning periods (Option B)',
    ];
    $actions[] = [
        'key' => 'generate_invoices',
        'label' => 'Generate Invoices',
        'type' => 'form',
        'action' => 'generate_invoices_pending',
        'enabled' => $isActive && $hasSched && $pendingSched > 0,
        'hint' => $pendingSched > 0 ? ($pendingSched . ' pending schedule(s)') : 'No pending schedules',
    ];
    if (function_exists('co_shop_phase2a_schema_ready') && co_shop_phase2a_schema_ready($conn)) {
        $actions[] = [
            'key' => 'renew',
            'label' => 'Renew Contract',
            'type' => 'link',
            'href' => 'shop_rental_renew.php?id=' . $contractId,
            'enabled' => ($status === 'active'),
            'hint' => 'Create renewal draft; parent stays Active',
        ];
        $actions[] = [
            'key' => 'inspection',
            'label' => 'Move-Out Inspection',
            'type' => 'link',
            'href' => 'shop_rental_move_out_inspection.php?id=' . $contractId,
            'enabled' => in_array($status, ['active', 'terminated', 'expired'], true),
            'hint' => 'Checklist + suggested deductions (no GL)',
        ];
        $actions[] = [
            'key' => 'deposit_settle',
            'label' => 'Settle Deposit',
            'type' => 'link',
            'href' => 'shop_rental_deposit_settle.php?id=' . $contractId,
            'enabled' => $depositGap < 0.005 && (float)($contract['deposit_received_amount'] ?? 0) > 0.005,
            'hint' => 'Refund / recoveries → posts GL',
        ];
        $actions[] = [
            'key' => 'terminate',
            'label' => 'Early Termination',
            'type' => 'link',
            'href' => 'shop_rental_terminate.php?id=' . $contractId,
            'enabled' => $status === 'active',
            'hint' => 'Penalty + cancel future schedules',
        ];
    }
    $commPending = false;
    $commOutstanding = 0.0;
    if (function_exists('co_shop_commission_schema_ready') && co_shop_commission_schema_ready($conn)) {
        $comm = co_shop_commission_status($conn, $companyId, $contract);
        $commPending = ($comm['status'] ?? '') === 'not_invoiced' && ($comm['net'] ?? 0) > 0.005 && !empty($comm['amounts']['enabled']);
        $commOutstanding = (float)($comm['outstanding'] ?? 0);
    }
    $viewBase = 'shop_rental_contract_view.php?id=' . $contractId;
    $actions[] = [
        'key' => 'generate_commission',
        'label' => 'Generate Commission Invoice',
        'type' => 'link',
        'href' => $viewBase . '&tab=commission#commission-section',
        'enabled' => $isActive && $commPending,
        'hint' => $commPending ? 'Open Tenant Commission section' : 'Commission already invoiced or disabled',
    ];
    $actions[] = [
        'key' => 'record_deposit',
        'label' => 'Record Deposit',
        'type' => 'link',
        'href' => $viewBase . '&tab=deposits#deposit-section',
        'enabled' => $isActive && $phase1 && $depositGap > 0.005,
        'hint' => $depositGap > 0.005 ? ('Open ' . number_format($depositGap, 2)) : 'Deposit complete',
    ];
    $actions[] = [
        'key' => 'record_receipt',
        'label' => 'Receive Payment',
        'type' => 'link',
        'href' => 'shop_rental_payment_workspace.php?contract_id=' . $contractId,
        'enabled' => $isActive && $phase1 && ($outstanding > 0.005 || $commOutstanding > 0.005),
        'hint' => ($outstanding + $commOutstanding) > 0.005
            ? ('Outstanding ' . number_format($outstanding + $commOutstanding, 2))
            : 'No open balance',
    ];
    $actions[] = [
        'key' => 'payment_workspace',
        'label' => 'Payment Workspace',
        'type' => 'link',
        'href' => 'shop_rental_payment_workspace.php?contract_id=' . $contractId,
        'enabled' => true,
        'hint' => 'Receive Payment — auto-loads outstanding invoices',
    ];
    $actions[] = [
        'key' => 'payment_manager',
        'label' => 'Open Payment Manager',
        'type' => 'link',
        'href' => $viewBase . '&tab=finance#payment-manager',
        'enabled' => true,
        'hint' => 'Legacy cheque clear / quick allocate',
    ];
    $actions[] = [
        'key' => 'view_ledger',
        'label' => 'View Ledger',
        'type' => 'link',
        'href' => 'client_invoices.php?client_id=' . (int)$contract['client_id'],
        'enabled' => true,
        'hint' => 'All tenant invoices (rent + commission)',
    ];
    $actions[] = [
        'key' => 'print',
        'label' => 'Print Contract',
        'type' => 'print',
        'enabled' => true,
        'hint' => 'Print-friendly contract summary',
    ];

    return $actions;
}

/**
 * Accounting navigation links for a contract.
 */
function co_shop_contract_nav_links(array $contract, int $contractId): array {
    $clientId = (int)$contract['client_id'];
    $viewBase = 'shop_rental_contract_view.php?id=' . $contractId;
    return [
        ['label' => 'Invoices', 'href' => 'client_invoices.php?client_id=' . $clientId . '&source_type=shop_rental', 'icon' => 'bi-receipt'],
        ['label' => 'Receipts', 'href' => 'client_payments.php?client_id=' . $clientId, 'icon' => 'bi-cash-coin'],
        ['label' => 'Receipt Allocations', 'href' => $viewBase . '&tab=finance#payment-manager', 'icon' => 'bi-diagram-3'],
        ['label' => 'Deposit Records', 'href' => $viewBase . '&tab=deposits#deposit-section', 'icon' => 'bi-safe'],
        ['label' => 'Revenue Recognition', 'href' => 'reports/deferred_rent_recognition.php', 'icon' => 'bi-calendar-check'],
        ['label' => 'Journal Entries', 'href' => 'journal_entry_list.php', 'icon' => 'bi-journal-text'],
        ['label' => 'Customer Ledger', 'href' => 'client_invoices.php?client_id=' . $clientId, 'icon' => 'bi-person-lines-fill'],
    ];
}

/**
 * Portfolio metrics for list page (batched where possible).
 * @param list<array> $contracts
 * @return array<int, array>
 */
function co_shop_portfolio_metrics(PDO $conn, int $companyId, array $contracts): array {
    $out = [];
    if (!$contracts) {
        return $out;
    }
    $today = date('Y-m-d');
    $ids = array_map(static fn($r) => (int)$r['id'], $contracts);
    $idList = implode(',', $ids);

    // Outstanding / overdue by contract via schedules → invoices
    $fin = [];
    if ($idList !== '') {
        $sql = "
            SELECT s.contract_id,
                   i.id AS invoice_id,
                   i.total_amount,
                   i.due_date,
                   COALESCE((
                       SELECT SUM(a.allocated_amount) FROM co_client_payment_allocations a
                       WHERE a.invoice_id = i.id AND a.company_id = i.company_id
                   ), 0) AS paid_amount
            FROM co_shop_rent_schedules s
            JOIN co_client_invoices i ON i.id = s.invoice_id AND i.company_id = s.company_id
            WHERE s.company_id = ? AND s.contract_id IN ($idList)
              AND i.status <> 'cancelled'
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['contract_id'];
            if (!isset($fin[$cid])) {
                $fin[$cid] = ['outstanding' => 0.0, 'overdue' => 0.0, 'invoiced' => 0.0, 'collected' => 0.0];
            }
            $bal = max(0, round((float)$row['total_amount'] - (float)$row['paid_amount'], 2));
            $fin[$cid]['invoiced'] = round($fin[$cid]['invoiced'] + (float)$row['total_amount'], 2);
            $fin[$cid]['collected'] = round($fin[$cid]['collected'] + (float)$row['paid_amount'], 2);
            $fin[$cid]['outstanding'] = round($fin[$cid]['outstanding'] + $bal, 2);
            if ($bal > 0.005 && !empty($row['due_date']) && $row['due_date'] < $today) {
                $fin[$cid]['overdue'] = round($fin[$cid]['overdue'] + $bal, 2);
            }
        }
    }

    $schedCounts = [];
    $chequeCounts = [];
    if ($idList !== '') {
        $s = $conn->query("SELECT contract_id, COUNT(*) AS n FROM co_shop_rent_schedules WHERE company_id = " . (int)$companyId . " AND contract_id IN ($idList) GROUP BY contract_id");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $schedCounts[(int)$r['contract_id']] = (int)$r['n'];
        }
        $c = $conn->query("SELECT contract_id, COUNT(*) AS n FROM co_shop_rent_cheques WHERE company_id = " . (int)$companyId . " AND contract_id IN ($idList) GROUP BY contract_id");
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $chequeCounts[(int)$r['contract_id']] = (int)$r['n'];
        }
    }

    foreach ($contracts as $row) {
        $id = (int)$row['id'];
        $f = $fin[$id] ?? ['outstanding' => 0.0, 'overdue' => 0.0, 'invoiced' => 0.0, 'collected' => 0.0];
        $depReq = (float)$row['security_deposit'];
        $depHeld = (float)$row['deposit_received_amount'];
        if ($depReq <= 0.005) {
            $depositStatus = 'none';
            $depositLabel = 'N/A';
        } elseif ($depHeld + 0.005 >= $depReq) {
            $depositStatus = 'held';
            $depositLabel = 'Held';
        } elseif ($depHeld > 0.005) {
            $depositStatus = 'partial';
            $depositLabel = 'Partial';
        } else {
            $depositStatus = 'missing';
            $depositLabel = 'Missing';
        }

        if ($f['outstanding'] <= 0.005 && $f['invoiced'] > 0.005) {
            $payStatus = 'paid';
            $payLabel = 'Paid';
        } elseif ($f['overdue'] > 0.005) {
            $payStatus = 'overdue';
            $payLabel = 'Overdue';
        } elseif ($f['outstanding'] > 0.005) {
            $payStatus = 'partial';
            $payLabel = 'Open';
        } elseif ($f['invoiced'] <= 0.005) {
            $payStatus = 'uninvoiced';
            $payLabel = 'Not invoiced';
        } else {
            $payStatus = 'ok';
            $payLabel = 'OK';
        }

        $end = (string)$row['end_date'];
        $days = (int)((strtotime($end) - strtotime($today)) / 86400);
        $expiryWarn = $days < 0 ? 'expired' : ($days <= 60 ? 'soon' : 'ok');

        $healthBits = 0;
        $healthTotal = 5;
        if (($row['status'] ?? '') === 'active') {
            $healthBits++;
        }
        if (($chequeCounts[$id] ?? 0) > 0) {
            $healthBits++;
        }
        if (($schedCounts[$id] ?? 0) > 0) {
            $healthBits++;
        }
        if ($depositStatus === 'held' || $depositStatus === 'none') {
            $healthBits++;
        }
        if ($f['overdue'] <= 0.005) {
            $healthBits++;
        }
        if ($healthBits >= 5) {
            $healthStatus = 'good';
            $healthLabel = 'Healthy';
        } elseif ($healthBits >= 3) {
            $healthStatus = 'fair';
            $healthLabel = 'Attention';
        } else {
            $healthStatus = 'poor';
            $healthLabel = 'At risk';
        }

        $quick = [];
        if ($f['overdue'] > 0.005) {
            $quick[] = 'Overdue ' . number_format($f['overdue'], 0);
        } elseif ($f['outstanding'] > 0.005) {
            $quick[] = 'Open ' . number_format($f['outstanding'], 0);
        } elseif ($f['invoiced'] > 0.005) {
            $quick[] = 'Settled';
        } else {
            $quick[] = 'Setup';
        }
        if ($depositStatus === 'missing') {
            $quick[] = 'No deposit';
        }
        if ($expiryWarn === 'soon') {
            $quick[] = $days . 'd left';
        } elseif ($expiryWarn === 'expired') {
            $quick[] = 'Expired';
        }

        $out[$id] = [
            'outstanding' => $f['outstanding'],
            'overdue' => $f['overdue'],
            'invoiced' => $f['invoiced'],
            'collected' => $f['collected'],
            'deposit_status' => $depositStatus,
            'deposit_label' => $depositLabel,
            'payment_status' => $payStatus,
            'payment_label' => $payLabel,
            'health_status' => $healthStatus,
            'health_label' => $healthLabel,
            'health_score' => $healthBits . '/' . $healthTotal,
            'expiry_warn' => $expiryWarn,
            'days_to_expiry' => $days,
            'quick_status' => implode(' · ', $quick),
        ];
    }

    return $out;
}
